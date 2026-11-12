import { randomBytes } from 'node:crypto';
import { mkdir, writeFile } from 'node:fs/promises';
import { join } from 'node:path';
import type { ResultSetHeader } from 'mysql2/promise';
import { consulta, pool, primeira } from './db';
import { avisarEquipeWhatsapp, configuracao, destinatariosEquipe, enviarEmail, enviarTexto, escaparHtml, urlBase } from './integracoes';

/** Regras de chamado do Prime Hub (as mesmas do sistema antigo), em TypeScript. */

export const CATEGORIAS_CHAMADO = ['Geral', 'Compras', 'Estoque/Custos/PCP', 'Faturamento', 'Financeiro', 'Gestão de Pessoal', 'Ponto Eletrônico', 'Fiscal', 'Contabilidade', 'Desenvolvimento'];

export const ANEXO_TIPOS: Record<string, string> = {
  'image/jpeg': 'jpg', 'image/png': 'png', 'image/webp': 'webp', 'image/gif': 'gif', 'application/pdf': 'pdf',
  'application/msword': 'doc', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document': 'docx',
  'application/vnd.ms-excel': 'xls', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': 'xlsx',
  'application/vnd.ms-powerpoint': 'ppt', 'application/vnd.openxmlformats-officedocument.presentationml.presentation': 'pptx',
  'application/vnd.oasis.opendocument.text': 'odt', 'application/vnd.oasis.opendocument.spreadsheet': 'ods', 'application/vnd.oasis.opendocument.presentation': 'odp',
  'text/plain': 'txt', 'text/csv': 'csv', 'application/rtf': 'rtf', 'text/rtf': 'rtf', 'application/zip': 'zip',
  'application/x-rar': 'rar', 'application/x-rar-compressed': 'rar', 'application/vnd.rar': 'rar', 'application/x-7z-compressed': '7z',
};
const ANEXO_MAX = 500 * 1024 * 1024;

export async function executar(sql: string, params: unknown[] = []): Promise<ResultSetHeader> {
  const [r] = await pool.query<ResultSetHeader>(sql, params);
  return r;
}

const digitos = (t: string | null | undefined) => (t ?? '').replace(/\D/g, '');

/** Mesmo número com/sem 55 e com/sem o 9º dígito (como o WhatsApp manda vs. como foi cadastrado). */
export function telefonesCorrespondem(waId: string, cadastrado: string | null | undefined): boolean {
  if (!cadastrado) return false;
  const variacoes = (d: string) => {
    const v = [d];
    if (d.startsWith('55') && d.length > 10) v.push(d.slice(2));
    for (const x of [...v]) {
      if (x.length === 11 && x[2] === '9') v.push(x.slice(0, 2) + x.slice(3));
      else if (x.length === 10) v.push(`${x.slice(0, 2)}9${x.slice(2)}`);
    }
    return v;
  };
  const a = variacoes(digitos(waId));
  const b = new Set(variacoes(digitos(cadastrado)));
  return a.some((x) => x && b.has(x));
}

export async function setoresDoResponsavel(id: number): Promise<number[]> {
  return (await consulta<{ s: number }>('SELECT setor_id s FROM setor_responsavel_setores WHERE setor_responsavel_id = ? ORDER BY setor_id', [id])).map((l) => Number(l.s));
}

/** Grava um anexo de chamado (arquivo na pasta de uploads + cópia no banco, como sempre foi). */
export async function salvarAnexo(chamadoId: number, respostaId: number | null, enviadoPor: 'cliente' | 'equipe', nome: string, mime: string, bytes: Buffer): Promise<string | null> {
  const ext = ANEXO_TIPOS[mime];
  if (!ext) return `"${nome}" não é um tipo de arquivo permitido.`;
  if (bytes.length > ANEXO_MAX) return `"${nome}" é maior que o limite de 500MB.`;
  const pasta = join(process.env.UPLOADS_DIR ?? '/uploads', 'chamados', String(chamadoId));
  const arquivo = `${randomBytes(16).toString('hex')}.${ext}`;
  try {
    await mkdir(pasta, { recursive: true, mode: 0o755 });
    await writeFile(join(pasta, arquivo), bytes, { mode: 0o644 });
  } catch (e) {
    console.error('anexo: não deu para gravar o arquivo:', e instanceof Error ? e.message : e);
    return `Não foi possível salvar "${nome}" no servidor.`;
  }
  const caminho = `chamados/${chamadoId}/${arquivo}`;
  await executar('INSERT INTO chamado_anexos (chamado_id, resposta_id, enviado_por, arquivo_nome_original, arquivo_caminho, arquivo_tamanho, dados) VALUES (?, ?, ?, ?, ?, ?, ?)',
    [chamadoId, respostaId, enviadoPor, nome, caminho, bytes.length, bytes])
    .catch(() => executar('INSERT INTO chamado_anexos (chamado_id, resposta_id, enviado_por, arquivo_nome_original, arquivo_caminho, arquivo_tamanho) VALUES (?, ?, ?, ?, ?, ?)',
      [chamadoId, respostaId, enviadoPor, nome, caminho, bytes.length]));
  return null;
}

/** Situação a partir de quem falou por último (nunca fecha sozinho; reabre se o cliente responder). */
export async function recalcularStatus(chamadoId: number): Promise<void> {
  const ultimo = await primeira<{ t: string }>('SELECT autor_tipo t FROM chamado_respostas WHERE chamado_id = ? ORDER BY criado_em DESC, id DESC LIMIT 1', [chamadoId]);
  let status = 'aberto';
  if (ultimo) {
    const equipe = await primeira<{ x: number }>("SELECT 1 x FROM chamado_respostas WHERE chamado_id = ? AND autor_tipo = 'equipe' LIMIT 1", [chamadoId]);
    if (equipe) status = ultimo.t === 'equipe' ? 'aguardando_cliente' : 'aguardando_equipe';
  }
  await executar('UPDATE chamados SET status = ?, fechado_em = NULL WHERE id = ?', [status, chamadoId]);
}

/** Trava contra clique duplo / webhook reenviado. */
export async function duplicadoRecente(clienteId: number, titulo: string, descricao: string, segundos = 20): Promise<boolean> {
  return Boolean(await primeira('SELECT id FROM chamados WHERE cliente_id = ? AND titulo = ? AND descricao = ? AND criado_em >= NOW() - INTERVAL ? SECOND LIMIT 1', [clienteId, titulo, descricao, segundos]));
}

/** Põe o chamado na fila da IA (o cron processa). */
export async function agendarIa(chamadoId: number): Promise<void> {
  if ((await configuracao('ia_chamados_modo', 'sugestao')) === 'desligado' || !(await configuracao('gemini_api_key', process.env.GEMINI_API_KEY ?? ''))) return;
  await executar("INSERT IGNORE INTO chamado_ia_analises (chamado_id, status) VALUES (?, 'pendente')", [chamadoId]).catch(() => undefined);
}

// ---------------------------------------------------------------- Avisos

interface ChamadoAviso { id: number; titulo: string; prioridade: string; descricao: string; setor_id: number | null; aberto_por_tipo: string | null; aberto_por_setor_responsavel_id: number | null }
interface ClienteAviso { id: number; nome: string; contato_nome: string | null; contato_email: string | null; contato_telefone: string | null }

export async function avisarNovoChamado(ch: ChamadoAviso, cli: ClienteAviso): Promise<void> {
  const link = `${urlBase()}/painel/chamado?id=${ch.id}`;
  const corpo = `<p>Novo chamado aberto por <strong>${escaparHtml(cli.nome)}</strong>.</p><p><strong>Título:</strong> ${escaparHtml(ch.titulo)}</p>
    <p><strong>Prioridade:</strong> ${escaparHtml(ch.prioridade)}</p><p><strong>Descrição:</strong><br>${escaparHtml(ch.descricao).replace(/\n/g, '<br>')}</p><p><a href="${link}">Ver no Prime Hub</a></p>`;
  for (const email of await destinatariosEquipe()) await enviarEmail(email, `Novo chamado: ${ch.titulo} (${cli.nome})`, corpo);
  await avisarEquipeWhatsapp(`Novo chamado de ${cli.nome}: ${ch.titulo}`);
  if (!ch.setor_id) return;

  const setor = await primeira<{ nome: string }>('SELECT nome FROM setores WHERE id = ?', [ch.setor_id]);
  if (!setor) return;
  const avisar = async (p: { nome: string; email: string | null; telefone: string | null }) => {
    const linkPortal = `${urlBase()}/cliente/chamado?id=${ch.id}`;
    if (p.email) await enviarEmail(p.email, `Novo chamado atribuído ao setor ${setor.nome}: ${ch.titulo}`,
      `<p>Olá, ${escaparHtml(p.nome)}.</p><p>O chamado <strong>${escaparHtml(ch.titulo)}</strong> foi atribuído ao setor <strong>${escaparHtml(setor.nome)}</strong>, do qual você é responsável.</p><p><a href="${linkPortal}">Ver chamado no portal</a></p>`);
    if (p.telefone) await enviarTexto(p.telefone, `📌 O chamado *#${ch.id} - ${ch.titulo}* foi atribuído ao setor *${setor.nome}*, do qual você é responsável.`);
  };
  const membros = await consulta<{ id: number; nome: string; email: string | null; telefone: string | null }>(
    'SELECT sr.id, sr.nome, sr.email, sr.telefone FROM setor_responsaveis sr JOIN setor_responsavel_setores srs ON srs.setor_responsavel_id = sr.id WHERE srs.setor_id = ?', [ch.setor_id]);
  for (const m of membros) if (Number(m.id) !== Number(ch.aberto_por_setor_responsavel_id ?? 0)) await avisar(m);
  const padraoMaster = await consulta<{ s: number }>('SELECT setor_id s FROM cliente_setores WHERE cliente_id = ?', [cli.id]);
  if (padraoMaster.some((x) => Number(x.s) === Number(ch.setor_id)) && ch.aberto_por_tipo !== 'master') {
    await avisar({ nome: cli.contato_nome || cli.nome, email: cli.contato_email, telefone: cli.contato_telefone });
  }
}

export async function avisarMensagemWhatsapp(ch: { id: number; titulo: string }, nomeCliente: string, mensagem: string): Promise<void> {
  const link = `${urlBase()}/painel/chamado?id=${ch.id}`;
  for (const email of await destinatariosEquipe()) {
    await enviarEmail(email, `Nova mensagem (WhatsApp) no chamado #${ch.id}: ${ch.titulo}`,
      `<p>Nova mensagem via WhatsApp no chamado <strong>${escaparHtml(ch.titulo)}</strong>, de <strong>${escaparHtml(nomeCliente)}</strong>.</p><p><strong>Mensagem:</strong><br>${escaparHtml(mensagem).replace(/\n/g, '<br>')}</p><p><a href="${link}">Ver no Prime Hub</a></p>`);
  }
  await avisarEquipeWhatsapp(`💬 Nova mensagem via WhatsApp no chamado #${ch.id} (${ch.titulo}), de ${nomeCliente}: "${mensagem}"`);
}
