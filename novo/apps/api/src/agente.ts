import { createHash, randomBytes, timingSafeEqual } from 'node:crypto';
import { SignJWT } from 'jose';
import type { PropostaAgente, UsuarioLogado } from '@primehub/shared';
export type { PropostaAgente };
import { config } from './config';
import { consulta, primeira } from './db';
import { configuracao } from './integracoes';
import { executar } from './regras-chamado';

/**
 * Agente Claude dos chamados. Cada rodada é uma sessão do Claude que a
 * equipe acompanha: ela pega a fila por aqui, lê o chamado, pesquisa,
 * escreve o fonte ADVPL quando precisa e devolve uma PROPOSTA (resposta
 * pro cliente + fontes + resumo interno + arquivos).
 *
 * NADA vai para o cliente sem a equipe ver e aprovar. Duas etapas:
 *  1) triagem: o agente lê, classifica (resposta, correção, melhoria,
 *     proposta, desenvolvimento...), explica o plano e deixa um rascunho;
 *  2) execução: só quando a equipe aprova o plano (com instruções), o
 *     agente escreve o fonte/proposta e devolve DE NOVO para revisão.
 * Quem aprova o envio é sempre uma pessoa; a resposta sai em nome do
 * atendente do chamado, pelo mesmo caminho da resposta manual.
 */

export class ErroAgente extends Error {
  constructor(message: string, readonly status: 400 | 401 | 403 | 404 | 409 = 400) { super(message); }
}

export async function garantirEstruturaAgente(): Promise<void> {
  await executar(`CREATE TABLE IF NOT EXISTS chamado_agente (
      id INT AUTO_INCREMENT PRIMARY KEY,
      chamado_id INT NOT NULL,
      ate_resposta_id INT NOT NULL DEFAULT 0 COMMENT 'Última mensagem do chamado quando a proposta foi feita',
      status ENUM('rascunho','aprovado_execucao','enviada','descartada','substituida') NOT NULL DEFAULT 'rascunho',
      etapa ENUM('triagem','execucao') NOT NULL DEFAULT 'triagem',
      tipo VARCHAR(20) NOT NULL DEFAULT 'resposta',
      plano TEXT NULL COMMENT 'O que o agente sugere fazer (para a equipe decidir)',
      instrucoes_equipe TEXT NULL COMMENT 'O que a equipe pediu ao aprovar a execução',
      aprovado_por INT NULL,
      resposta TEXT NOT NULL,
      status_sugerido VARCHAR(20) NULL,
      confianca ENUM('alta','media','baixa') NOT NULL DEFAULT 'media',
      fontes TEXT NULL COMMENT 'JSON [{titulo,url}]',
      resumo_interno TEXT NULL COMMENT 'O que foi analisado/feito (só para a equipe)',
      motivo_nao_enviou VARCHAR(300) NULL,
      atendente_id INT NULL,
      resposta_id INT NULL,
      sessao VARCHAR(300) NULL COMMENT 'Link da sessão do Claude que fez a proposta',
      criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY ix_agente_chamado (chamado_id, status),
      CONSTRAINT fk_agente_chamado FOREIGN KEY (chamado_id) REFERENCES chamados(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`);
  await executar(`CREATE TABLE IF NOT EXISTS chamado_agente_arquivos (
      id INT AUTO_INCREMENT PRIMARY KEY,
      proposta_id INT NOT NULL,
      nome VARCHAR(255) NOT NULL,
      dados LONGBLOB NOT NULL,
      criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      CONSTRAINT fk_agente_arquivo FOREIGN KEY (proposta_id) REFERENCES chamado_agente(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`);
  await executar(`CREATE TABLE IF NOT EXISTS chamado_agente_reservas (
      chamado_id INT PRIMARY KEY,
      reservado_ate DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`);
  // O motor automático dos chamados passa a ser o Claude (o Gemini para de responder sozinho).
  await executar("INSERT INTO configuracoes (chave, valor) VALUES ('ia_chamados_motor', 'claude') ON DUPLICATE KEY UPDATE valor = 'claude'").catch(() => undefined);
}

// ---------------------------------------------------------------- Token do agente

const hash = (t: string) => createHash('sha256').update(t).digest('hex');

/** Gera um token novo (mostrado uma vez só); invalida o anterior. */
export async function gerarTokenAgente(): Promise<string> {
  const token = `phag_${randomBytes(32).toString('base64url')}`;
  await executar("INSERT INTO configuracoes (chave, valor) VALUES ('agente_token_hash', ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)", [hash(token)]);
  return token;
}

export async function conferirTokenAgente(recebido: string | undefined): Promise<void> {
  const guardado = await configuracao('agente_token_hash', '');
  if (!guardado || !recebido) throw new ErroAgente('Agente sem token.', 401);
  const a = Buffer.from(hash(recebido)); const b = Buffer.from(guardado);
  if (a.length !== b.length || !timingSafeEqual(a, b)) throw new ErroAgente('Token do agente inválido.', 401);
}

// ---------------------------------------------------------------- Fila

const ULTIMA = `(SELECT r.id FROM chamado_respostas r WHERE r.chamado_id = ch.id ORDER BY r.criado_em DESC, r.id DESC LIMIT 1)`;
const ULTIMO_AUTOR = `(SELECT r.autor_tipo FROM chamado_respostas r WHERE r.chamado_id = ch.id ORDER BY r.criado_em DESC, r.id DESC LIMIT 1)`;

export interface ItemFila { id: number; titulo: string; cliente: string; prioridade: string; categoria: string | null; esperandoDesde: string; etapa: 'triagem' | 'execucao'; propostaId: number | null; tipo: string | null; instrucoes: string | null }

/**
 * Trabalho do agente: primeiro as execuções que a equipe aprovou; depois
 * chamados esperando a equipe que ainda não têm triagem para a mensagem
 * atual. Reserva por 40 minutos (duas sessões não pegam o mesmo).
 */
export async function filaAgente(limite: number): Promise<ItemFila[]> {
  await executar('DELETE FROM chamado_agente_reservas WHERE reservado_ate < NOW()');
  const n = Math.max(1, Math.min(10, limite));
  const execucoes = await consulta<ItemFila>(
    `SELECT ch.id, ch.titulo, c.nome cliente, ch.prioridade, ch.categoria, a.atualizado_em esperandoDesde, 'execucao' etapa, a.id propostaId, a.tipo, a.instrucoes_equipe instrucoes
       FROM chamado_agente a JOIN chamados ch ON ch.id = a.chamado_id JOIN clientes c ON c.id = ch.cliente_id
      WHERE a.status = 'aprovado_execucao' AND NOT EXISTS (SELECT 1 FROM chamado_agente_reservas rv WHERE rv.chamado_id = ch.id)
      ORDER BY a.atualizado_em LIMIT ${n}`);
  const triagens = execucoes.length >= n ? [] : await consulta<ItemFila>(
    `SELECT ch.id, ch.titulo, c.nome cliente, ch.prioridade, ch.categoria,
            COALESCE((SELECT MAX(r.criado_em) FROM chamado_respostas r WHERE r.chamado_id = ch.id), ch.criado_em) esperandoDesde,
            'triagem' etapa, NULL propostaId, NULL tipo, NULL instrucoes
       FROM chamados ch JOIN clientes c ON c.id = ch.cliente_id
      WHERE ch.status IN ('aberto', 'aguardando_equipe') AND c.status = 'ativo'
        AND COALESCE(${ULTIMO_AUTOR}, 'cliente') <> 'equipe'
        AND NOT EXISTS (SELECT 1 FROM chamado_agente a WHERE a.chamado_id = ch.id AND a.status IN ('rascunho','aprovado_execucao','enviada','descartada') AND a.ate_resposta_id = COALESCE(${ULTIMA}, 0))
        AND NOT EXISTS (SELECT 1 FROM chamado_agente_reservas rv WHERE rv.chamado_id = ch.id)
      ORDER BY FIELD(ch.prioridade, 'urgente', 'alta', 'media', 'baixa'), esperandoDesde ASC
      LIMIT ${n - execucoes.length}`);
  const itens = [...execucoes, ...triagens];
  for (const i of itens) await executar('INSERT INTO chamado_agente_reservas (chamado_id, reservado_ate) VALUES (?, NOW() + INTERVAL 40 MINUTE) ON DUPLICATE KEY UPDATE reservado_ate = VALUES(reservado_ate)', [i.id]);
  return itens.map((i) => ({ ...i, propostaId: i.propostaId === null ? null : Number(i.propostaId) }));
}

// ---------------------------------------------------------------- Detalhe

async function atendenteDo(ch: { responsavel_id: number | null; cliente_id: number }): Promise<{ id: number; nome: string } | null> {
  const porId = (id: number | null | undefined) => (id ? primeira<{ id: number; nome: string }>("SELECT id, nome FROM usuarios WHERE id = ? AND ativo = 1", [id]) : Promise.resolve(null));
  const cli = await primeira<{ r: number | null }>('SELECT responsavel_interno_id r FROM clientes WHERE id = ?', [ch.cliente_id]);
  return (await porId(ch.responsavel_id)) ?? (await porId(cli?.r)) ?? (await porId(Number(await configuracao('ia_chamados_usuario_id', '0')) || null));
}

const PALAVRAS_FORA = new Set(['para', 'com', 'que', 'nao', 'não', 'uma', 'por', 'dos', 'das', 'mais', 'esta', 'está', 'ao', 'de', 'do', 'da', 'no', 'na', 'em', 'os', 'as', 'um', 'se', 'quando', 'erro', 'problema', 'protheus', 'favor', 'bom', 'dia', 'tarde']);

export async function detalheAgente(id: number): Promise<Record<string, unknown>> {
  const ch = await primeira<{ id: number; titulo: string; descricao: string; categoria: string | null; prioridade: string; status: string; cliente_id: number; setor_id: number | null; responsavel_id: number | null; criado_em: string; aberto_por_nome: string | null }>(
    'SELECT id, titulo, descricao, categoria, prioridade, status, cliente_id, setor_id, responsavel_id, criado_em, aberto_por AS aberto_por_nome FROM chamados WHERE id = ?', [id]);
  if (!ch) throw new ErroAgente('Chamado não encontrado.', 404);
  const [cliente, setor, conversa, anexos] = await Promise.all([
    primeira<{ nome: string; contato_nome: string | null }>('SELECT nome, contato_nome FROM clientes WHERE id = ?', [ch.cliente_id]),
    ch.setor_id ? primeira<{ nome: string }>('SELECT nome FROM setores WHERE id = ?', [ch.setor_id]) : Promise.resolve(null),
    consulta<{ id: number; autor_tipo: string; autor_nome: string | null; mensagem: string; criado_em: string }>('SELECT id, autor_tipo, autor_nome, mensagem, criado_em FROM chamado_respostas WHERE chamado_id = ? ORDER BY criado_em, id', [id]),
    consulta<{ id: number; nome: string; tamanho: number; resposta_id: number | null }>('SELECT id, arquivo_nome_original nome, arquivo_tamanho tamanho, resposta_id FROM chamado_anexos WHERE chamado_id = ? ORDER BY id', [id]),
  ]);
  const ultimaCliente = [...conversa].reverse().find((m) => m.autor_tipo === 'cliente');
  const contato = (ultimaCliente?.autor_nome || cliente?.contato_nome || '').trim().split(/\s+/)[0] ?? '';
  const atendente = await atendenteDo(ch);

  // Chamados parecidos já resolvidos por nós (referência técnica e de estilo).
  const palavras = [...new Set(`${ch.titulo} ${ch.descricao}`.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').match(/[a-z0-9_]{4,}/g) ?? [])]
    .filter((p) => !PALAVRAS_FORA.has(p)).slice(0, 6);
  const similares = palavras.length
    ? await consulta<{ id: number; titulo: string; descricao: string }>(
      `SELECT c2.id, c2.titulo, LEFT(c2.descricao, 700) descricao FROM chamados c2
        WHERE c2.id <> ? AND c2.status IN ('fechado','aguardando_cliente') AND (${palavras.map(() => '(c2.titulo LIKE ? OR c2.descricao LIKE ?)').join(' OR ')})
        ORDER BY c2.criado_em DESC LIMIT 5`, [id, ...palavras.flatMap((p) => [`%${p}%`, `%${p}%`])])
    : [];
  const resolvidos = await Promise.all(similares.map(async (s) => ({
    ...s,
    respostasEquipe: (await consulta<{ m: string }>("SELECT LEFT(mensagem, 1200) m FROM chamado_respostas WHERE chamado_id = ? AND autor_tipo = 'equipe' ORDER BY criado_em LIMIT 3", [s.id])).map((r) => r.m),
  })));

  return {
    chamado: { id: ch.id, titulo: ch.titulo, descricao: ch.descricao, categoria: ch.categoria, prioridade: ch.prioridade, status: ch.status, abertoEm: ch.criado_em, abertoPor: ch.aberto_por_nome },
    cliente: cliente?.nome ?? null,
    setor: setor?.nome ?? null,
    contatoPrimeiroNome: contato,
    atendente: atendente?.nome ?? null,
    conversa: conversa.map((m) => ({ id: m.id, de: m.autor_tipo === 'equipe' ? `Equipe (${m.autor_nome ?? ''})` : `Cliente (${m.autor_nome ?? ''})`, quando: m.criado_em, mensagem: m.mensagem })),
    anexos: anexos.map((a) => ({ id: a.id, nome: a.nome, tamanho: Number(a.tamanho), baixar: `/api/agente/anexos/${a.id}` })),
    chamadosParecidosResolvidos: resolvidos,
    execucaoAprovada: await primeira(
      `SELECT a.id propostaId, a.tipo, a.plano, a.instrucoes_equipe instrucoesDaEquipe, a.resposta rascunhoAnterior, u.nome aprovadoPor
         FROM chamado_agente a LEFT JOIN usuarios u ON u.id = a.aprovado_por WHERE a.chamado_id = ? AND a.status = 'aprovado_execucao' ORDER BY a.id DESC LIMIT 1`, [id]),
  };
}

export async function anexoAgente(id: number): Promise<{ nome: string; bytes: Buffer }> {
  const a = await primeira<{ nome: string; caminho: string | null; dados: Buffer | null }>('SELECT arquivo_nome_original nome, arquivo_caminho caminho, dados FROM chamado_anexos WHERE id = ?', [id]);
  if (!a) throw new ErroAgente('Anexo não encontrado.', 404);
  if (a.dados) return { nome: a.nome, bytes: Buffer.from(a.dados) };
  const { readFile } = await import('node:fs/promises');
  const { join, resolve } = await import('node:path');
  const base = resolve(process.env.UPLOADS_DIR ?? '/uploads');
  const caminho = a.caminho ? resolve(join(base, a.caminho)) : '';
  if (!caminho.startsWith(`${base}/`)) throw new ErroAgente('Arquivo não encontrado.', 404);
  return { nome: a.nome, bytes: await readFile(caminho) };
}

// ---------------------------------------------------------------- Proposta (sempre rascunho)

export const TIPOS_CASO: Record<string, string> = {
  resposta: 'Resposta / orientação', duvida: 'Dúvida (precisa de informação do cliente)', correcao: 'Correção',
  melhoria: 'Melhoria', proposta: 'Proposta comercial / orçamento', desenvolvimento: 'Desenvolvimento', outro: 'Outro',
};

/** Jeito de escrever que entrega robô: avisa a equipe na revisão. */
const SINAIS_DE_IA = /(intelig[eê]ncia artificial|\bIA\b|chatgpt|\bclaude\b|gemini|openai|anthropic|modelo de linguagem|assistente virtual|sou um rob[oô]|como (um )?(modelo|assistente))/i;
const MARKDOWN = /(^|\n)\s{0,3}(#{1,6}\s|\*\*[^*]+\*\*)|```/;

export interface Proposta {
  resposta: string;
  tipo?: string;
  plano?: string;
  status_sugerido?: string;
  confianca?: string;
  fontes?: { titulo?: string; url?: string }[];
  resumo_interno?: string;
  arquivos?: { nome?: string; conteudo_base64?: string }[];
  sessao?: string;
}

const STATUS_OK = ['aguardando_cliente', 'aguardando_equipe', 'fechado'];

/** Guarda a proposta para a equipe revisar. Nunca envia nada ao cliente. */
export async function registrarProposta(chamadoId: number, p: Proposta): Promise<{ id: number; avisos: string[] }> {
  const ch = await primeira<{ id: number; cliente_id: number; responsavel_id: number | null }>('SELECT id, cliente_id, responsavel_id FROM chamados WHERE id = ?', [chamadoId]);
  if (!ch) throw new ErroAgente('Chamado não encontrado.', 404);
  const resposta = (p.resposta ?? '').trim();
  if (resposta.length < 20) throw new ErroAgente('Resposta vazia ou curta demais.');
  if (resposta.length > 8000) throw new ErroAgente('Resposta longa demais (máx. 8000 caracteres).');
  const tipo = p.tipo && p.tipo in TIPOS_CASO ? p.tipo : 'resposta';
  const confianca = ['alta', 'media', 'baixa'].includes(p.confianca ?? '') ? (p.confianca as string) : 'media';
  const statusSugerido = STATUS_OK.includes(p.status_sugerido ?? '') ? (p.status_sugerido as string) : 'aguardando_cliente';
  const fontes = (p.fontes ?? []).filter((f) => f.url && /^https?:\/\//.test(f.url)).slice(0, 10).map((f) => ({ titulo: String(f.titulo ?? f.url).slice(0, 200), url: String(f.url).slice(0, 500) }));
  const arquivos = (p.arquivos ?? []).slice(0, 5).map((a) => ({ nome: String(a.nome ?? 'arquivo.txt').replace(/[^\w.\-]/g, '_').slice(0, 120), bytes: Buffer.from(String(a.conteudo_base64 ?? ''), 'base64') }))
    .filter((a) => a.bytes.length > 0 && a.bytes.length <= 2 * 1024 * 1024);
  const ultima = await primeira<{ id: number }>('SELECT id FROM chamado_respostas WHERE chamado_id = ? ORDER BY criado_em DESC, id DESC LIMIT 1', [chamadoId]);
  const atendente = await atendenteDo(ch);
  const execucao = await primeira<{ id: number }>("SELECT id FROM chamado_agente WHERE chamado_id = ? AND status = 'aprovado_execucao' ORDER BY id DESC LIMIT 1", [chamadoId]);

  const avisos: string[] = [];
  if (SINAIS_DE_IA.test(resposta)) avisos.push('o texto menciona IA');
  if (MARKDOWN.test(resposta)) avisos.push('o texto tem formatação de markdown');
  if (!atendente) avisos.push('chamado sem atendente definido');

  // Proposta anterior ainda não usada (ou a execução aprovada que esta conclui) sai da mesa.
  await executar("UPDATE chamado_agente SET status = 'substituida' WHERE chamado_id = ? AND status IN ('rascunho','aprovado_execucao')", [chamadoId]);
  const r = await executar(`INSERT INTO chamado_agente (chamado_id, ate_resposta_id, etapa, tipo, plano, resposta, status_sugerido, confianca, fontes, resumo_interno, motivo_nao_enviou, atendente_id, sessao)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`, [chamadoId, ultima?.id ?? 0, execucao ? 'execucao' : 'triagem', tipo, (p.plano ?? '').slice(0, 8000) || null, resposta, statusSugerido, confianca,
    JSON.stringify(fontes), (p.resumo_interno ?? '').slice(0, 8000) || null, avisos.join('; ').slice(0, 300) || null, atendente?.id ?? null, (p.sessao ?? '').slice(0, 300) || null]);
  for (const a of arquivos) await executar('INSERT INTO chamado_agente_arquivos (proposta_id, nome, dados) VALUES (?, ?, ?)', [r.insertId, a.nome, a.bytes]);
  await executar('DELETE FROM chamado_agente_reservas WHERE chamado_id = ?', [chamadoId]);
  return { id: r.insertId, avisos };
}

/** Equipe aprova o plano: a próxima rodada do agente executa (e devolve para revisão). */
export async function aprovarExecucao(propostaId: number, quem: { id: number }, tipo: string, instrucoes: string): Promise<void> {
  if (!(tipo in TIPOS_CASO)) throw new ErroAgente('Tipo inválido.');
  const ok = await executar("UPDATE chamado_agente SET status = 'aprovado_execucao', tipo = ?, instrucoes_equipe = ?, aprovado_por = ? WHERE id = ? AND status = 'rascunho'", [tipo, instrucoes.trim().slice(0, 4000) || null, quem.id, propostaId]);
  if (!ok.affectedRows) throw new ErroAgente('Essa proposta não está mais aguardando revisão.', 409);
}

// ---------------------------------------------------------------- Envio (pelo mesmo caminho da resposta manual)

async function tokenDe(usuarioId: number): Promise<string> {
  const u = await primeira<{ id: number; nome: string; email: string; perfil: string; foto: string | null }>('SELECT id, nome, email, perfil, foto_perfil foto FROM usuarios WHERE id = ? AND ativo = 1', [usuarioId]);
  if (!u) throw new ErroAgente('Atendente inativo ou inexistente.', 409);
  const usuario: UsuarioLogado = { tipo: 'equipe', id: u.id, nome: u.nome, email: u.email, perfil: u.perfil, foto: u.foto, clienteId: null, empresa: null } as UsuarioLogado;
  return new SignJWT({ u: usuario }).setProtectedHeader({ alg: 'HS256' }).setSubject(`equipe:${u.id}`).setIssuedAt().setExpirationTime('5m').sign(config.jwtSegredo);
}

/**
 * A pessoa da equipe aprovou: posta a resposta (editada ou não) no chamado
 * em nome do atendente, com os arquivos da proposta, e avisa o cliente.
 */
export async function enviarProposta(propostaId: number, quem: { id: number }, mensagem: string | null, status: string | null, comoAtendente: boolean): Promise<{ ok: true }> {
  const p = await primeira<{ id: number; chamado_id: number; resposta: string; status_sugerido: string | null; status: string; atendente_id: number | null }>('SELECT id, chamado_id, resposta, status_sugerido, status, atendente_id FROM chamado_agente WHERE id = ?', [propostaId]);
  if (!p) throw new ErroAgente('Proposta não encontrada.', 404);
  if (p.status !== 'rascunho') throw new ErroAgente('Essa proposta não está mais aguardando revisão.', 409);
  const corpo = new FormData();
  corpo.append('acao', 'responder_chamado');
  corpo.append('chamado_id', String(p.chamado_id));
  corpo.append('mensagem', (mensagem ?? p.resposta).trim());
  corpo.append('status_apos_resposta', STATUS_OK.includes(status ?? '') ? String(status) : (p.status_sugerido ?? 'aguardando_cliente'));
  corpo.append('notificar_cliente', '1');
  for (const a of await consulta<{ nome: string; dados: Buffer }>('SELECT nome, dados FROM chamado_agente_arquivos WHERE proposta_id = ?', [propostaId])) {
    corpo.append('anexos[]', new Blob([new Uint8Array(a.dados)], { type: 'text/plain' }), a.nome);
  }
  const r = await fetch(`${(process.env.PHP_INTERNO ?? 'http://primehub-web').replace(/\/$/, '')}/api_acoes.php`, {
    method: 'POST', body: corpo, headers: { 'X-PrimeHub': '1', authorization: `Bearer ${await tokenDe(comoAtendente && p.atendente_id ? p.atendente_id : quem.id)}` }, signal: AbortSignal.timeout(60_000),
  });
  const j = (await r.json().catch(() => null)) as { ok?: boolean; erro?: string } | null;
  if (!r.ok || !j?.ok) throw new ErroAgente(j?.erro ?? `Não deu para enviar (HTTP ${r.status}).`, 409);
  await executar("UPDATE chamado_agente SET status = 'enviada', aprovado_por = ? WHERE id = ?", [quem.id, propostaId]);
  return { ok: true };
}

export async function descartarProposta(propostaId: number): Promise<void> {
  await executar("UPDATE chamado_agente SET status = 'descartada' WHERE id = ? AND status = 'rascunho'", [propostaId]);
}

/** Proposta atual do chamado, para a tela da equipe. */
export async function propostaDoChamado(chamadoId: number): Promise<PropostaAgente | null> {
  const p = await primeira<Omit<PropostaAgente, 'fontes' | 'arquivos'> & { fontes: string | null }>(
    `${SQL_PROPOSTA} WHERE a.chamado_id = ? AND a.status IN ('rascunho','aprovado_execucao') ORDER BY a.id DESC LIMIT 1`, [chamadoId]).catch(() => null);
  if (!p) return null;
  return completar(p);
}

/** Mesa do agente: tudo esperando a equipe (e o que está em execução). */
export async function mesaDoAgente(): Promise<PropostaAgente[]> {
  const l = await consulta<Omit<PropostaAgente, 'fontes' | 'arquivos'> & { fontes: string | null }>(
    `${SQL_PROPOSTA} WHERE a.status IN ('rascunho','aprovado_execucao') ORDER BY a.status = 'aprovado_execucao', FIELD(ch.prioridade, 'urgente','alta','media','baixa'), a.criado_em`).catch(() => []);
  return Promise.all(l.map(completar));
}


const SQL_PROPOSTA = `SELECT a.id, a.chamado_id chamadoId, ch.titulo chamadoTitulo, c.nome cliente, ch.prioridade, a.status, a.etapa, a.tipo, a.plano, a.instrucoes_equipe instrucoes,
    a.resposta, a.status_sugerido statusSugerido, a.confianca, a.fontes, a.resumo_interno resumoInterno, a.motivo_nao_enviou motivo, u.nome atendente, a.criado_em criadoEm, a.sessao
  FROM chamado_agente a JOIN chamados ch ON ch.id = a.chamado_id JOIN clientes c ON c.id = ch.cliente_id LEFT JOIN usuarios u ON u.id = a.atendente_id`;

async function completar(p: Omit<PropostaAgente, 'fontes' | 'arquivos'> & { fontes: string | null }): Promise<PropostaAgente> {
  const arquivos = (await consulta<{ id: number; nome: string }>('SELECT id, nome FROM chamado_agente_arquivos WHERE proposta_id = ?', [p.id])).map((a) => ({ id: Number(a.id), nome: a.nome }));
  let fontes: { titulo: string; url: string }[] = [];
  try { fontes = JSON.parse(p.fontes ?? '[]') as typeof fontes; } catch { /* sem fontes */ }
  return { ...p, fontes, arquivos };
}

export async function arquivoDaProposta(arquivoId: number): Promise<{ nome: string; bytes: Buffer }> {
  const a = await primeira<{ nome: string; dados: Buffer }>('SELECT nome, dados FROM chamado_agente_arquivos WHERE id = ?', [arquivoId]);
  if (!a) throw new ErroAgente('Arquivo não encontrado.', 404);
  return { nome: a.nome, bytes: Buffer.from(a.dados) };
}
