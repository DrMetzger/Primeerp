import { createHash, randomBytes, timingSafeEqual } from 'node:crypto';
import { SignJWT } from 'jose';
import { converterRelatorio, type PropostaAgente, type UsuarioLogado } from '@primehub/shared';
export type { PropostaAgente };
import { compilarFonte, listarAlvos } from './compilacao';
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
  // Arquivos que a EQUIPE anexa ao mandar o agente desenvolver (fontes, PDFs,
  // prints de referência). Não vão ao cliente: são insumo para o agente.
  await executar(`CREATE TABLE IF NOT EXISTS chamado_agente_entrada (
      id INT AUTO_INCREMENT PRIMARY KEY,
      proposta_id INT NOT NULL,
      nome VARCHAR(255) NOT NULL,
      dados LONGBLOB NOT NULL,
      criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      CONSTRAINT fk_agente_entrada FOREIGN KEY (proposta_id) REFERENCES chamado_agente(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`);
  // Linha do tempo (pipeline) das ações do agente num chamado, para a equipe
  // acompanhar: recebido, rascunho, aprovado, desenvolvendo, compilando, pronto…
  await executar(`CREATE TABLE IF NOT EXISTS chamado_agente_eventos (
      id INT AUTO_INCREMENT PRIMARY KEY,
      chamado_id INT NOT NULL,
      proposta_id INT NULL,
      fase VARCHAR(20) NOT NULL,
      texto VARCHAR(500) NOT NULL DEFAULT '',
      criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY ix_evt_chamado (chamado_id, id)
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
    execucaoAprovada: await (async () => {
      const ex = await primeira<{ propostaId: number } & Record<string, unknown>>(
        `SELECT a.id propostaId, a.tipo, a.plano, a.instrucoes_equipe instrucoesDaEquipe, a.resposta rascunhoAnterior, u.nome aprovadoPor
           FROM chamado_agente a LEFT JOIN usuarios u ON u.id = a.aprovado_por WHERE a.chamado_id = ? AND a.status = 'aprovado_execucao' ORDER BY a.id DESC LIMIT 1`, [id]);
      if (!ex) return null;
      const refs = await consulta<{ id: number; nome: string; tamanho: number }>('SELECT id, nome, LENGTH(dados) tamanho FROM chamado_agente_entrada WHERE proposta_id = ?', [ex.propostaId]).catch(() => []);
      return { ...ex, arquivosReferencia: refs.map((a) => ({ id: Number(a.id), nome: a.nome, tamanho: Number(a.tamanho), baixar: `/api/agente/entrada/${a.id}` })) };
    })(),
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

const FASES_VALIDAS = new Set(['recebido', 'rascunho', 'aprovado', 'analisando', 'desenvolvendo', 'compilando', 'erro', 'pronto', 'enviado', 'descartado']);

/** Registra um passo do pipeline do agente (para a equipe acompanhar). */
export async function registrarEvento(chamadoId: number, fase: string, texto = '', propostaId: number | null = null): Promise<void> {
  if (!FASES_VALIDAS.has(fase)) fase = 'analisando';
  await executar('INSERT INTO chamado_agente_eventos (chamado_id, proposta_id, fase, texto) VALUES (?,?,?,?)', [chamadoId, propostaId, fase, texto.slice(0, 500)]).catch(() => undefined);
}

/** Últimos eventos do pipeline de um chamado (mais novo por último). */
export async function eventosDoChamado(chamadoId: number, limite = 12): Promise<{ fase: string; texto: string; em: string }[]> {
  const l = await consulta<{ fase: string; texto: string; em: string }>(
    'SELECT fase, texto, criado_em em FROM chamado_agente_eventos WHERE chamado_id = ? ORDER BY id DESC LIMIT ?', [chamadoId, Math.max(1, Math.min(50, limite))]).catch(() => []);
  return l.reverse();
}

export interface EtapaPipeline { chamadoId: number; chamadoTitulo: string; cliente: string; prioridade: string; fase: string; texto: string; desde: string; propostaId: number | null }
/** Pipeline (kanban): o ESTÁGIO ATUAL de cada chamado que o agente tocou nos últimos dias. */
export async function pipelineAgente(): Promise<EtapaPipeline[]> {
  return consulta<EtapaPipeline>(
    `SELECT e.chamado_id chamadoId, ch.titulo chamadoTitulo, c.nome cliente, ch.prioridade, e.fase, e.texto, e.criado_em desde,
            (SELECT a.id FROM chamado_agente a WHERE a.chamado_id = e.chamado_id AND a.status IN ('rascunho','aprovado_execucao') ORDER BY a.id DESC LIMIT 1) propostaId
       FROM chamado_agente_eventos e
       JOIN chamados ch ON ch.id = e.chamado_id
       JOIN clientes c ON c.id = ch.cliente_id
      WHERE e.id IN (SELECT MAX(id) FROM chamado_agente_eventos GROUP BY chamado_id)
        AND e.criado_em > NOW() - INTERVAL 10 DAY
      ORDER BY FIELD(ch.prioridade,'urgente','alta','media','baixa'), e.criado_em DESC`)
    .then((l) => l.map((x) => ({ ...x, propostaId: x.propostaId === null ? null : Number(x.propostaId) }))).catch(() => []);
}

export interface AtividadeAgente { chamadoId: number; chamadoTitulo: string; cliente: string; fase: string; texto: string; em: string }
/** Feed de TODAS as ações do agente (todos os chamados), mais novo primeiro. */
export async function atividadesAgente(limite = 60): Promise<AtividadeAgente[]> {
  return consulta<AtividadeAgente>(
    `SELECT e.chamado_id chamadoId, ch.titulo chamadoTitulo, c.nome cliente, e.fase, e.texto, e.criado_em em
       FROM chamado_agente_eventos e JOIN chamados ch ON ch.id = e.chamado_id JOIN clientes c ON c.id = ch.cliente_id
      ORDER BY e.id DESC LIMIT ?`, [Math.max(1, Math.min(200, limite))]).catch(() => []);
}

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
  await registrarEvento(chamadoId, execucao ? 'pronto' : 'rascunho', execucao ? 'Agente terminou — pronto para a equipe revisar.' : 'Rascunho pronto para revisão.', r.insertId);
  return { id: r.insertId, avisos };
}

/** Equipe aprova o plano: a próxima rodada do agente executa (e devolve para revisão). */
export async function aprovarExecucao(propostaId: number, quem: { id: number }, tipo: string, instrucoes: string, arquivos: { nome?: string; conteudo_base64?: string }[] = []): Promise<void> {
  if (!(tipo in TIPOS_CASO)) throw new ErroAgente('Tipo inválido.');
  const ok = await executar("UPDATE chamado_agente SET status = 'aprovado_execucao', tipo = ?, instrucoes_equipe = ?, aprovado_por = ? WHERE id = ? AND status = 'rascunho'", [tipo, instrucoes.trim().slice(0, 4000) || null, quem.id, propostaId]);
  if (!ok.affectedRows) throw new ErroAgente('Essa proposta não está mais aguardando revisão.', 409);
  const chamadoId = Number((await primeira<{ chamado_id: number }>('SELECT chamado_id FROM chamado_agente WHERE id = ?', [propostaId]))?.chamado_id ?? 0);
  // Arquivos de referência que a equipe mandou junto (fontes, PDFs): insumo do agente.
  const limpos = (arquivos ?? []).slice(0, 10)
    .map((a) => ({ nome: String(a.nome ?? 'arquivo').replace(/[^\w.\-]/g, '_').slice(0, 160), bytes: Buffer.from(String(a.conteudo_base64 ?? ''), 'base64') }))
    .filter((a) => a.bytes.length > 0 && a.bytes.length <= 10 * 1024 * 1024);
  for (const a of limpos) await executar('INSERT INTO chamado_agente_entrada (proposta_id, nome, dados) VALUES (?,?,?)', [propostaId, a.nome, a.bytes]);
  if (chamadoId) await registrarEvento(chamadoId, 'aprovado', `Execução aprovada (${TIPOS_CASO[tipo] ?? tipo})${limpos.length ? ` · ${limpos.length} arquivo(s) de referência` : ''}.`, propostaId);
  // Faz AGORA pela IA (Gemini) — sem travar a resposta ao usuário e sem gastar Claude.
  void executarComGemini(propostaId).catch((e: unknown) => console.error('agente exec disparo', e instanceof Error ? e.message : e));
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
  await registrarEvento(p.chamado_id, 'enviado', 'Resposta enviada ao cliente.', propostaId);
  return { ok: true };
}

export async function descartarProposta(propostaId: number): Promise<void> {
  const p = await primeira<{ chamado_id: number }>('SELECT chamado_id FROM chamado_agente WHERE id = ?', [propostaId]);
  await executar("UPDATE chamado_agente SET status = 'descartada' WHERE id = ? AND status = 'rascunho'", [propostaId]);
  if (p) await registrarEvento(p.chamado_id, 'descartado', 'Proposta descartada pela equipe.', propostaId);
}

/** Destrava uma execução que ficou parada: volta ao rascunho para a equipe redecidir. */
export async function cancelarExecucao(propostaId: number): Promise<void> {
  const p = await primeira<{ chamado_id: number }>('SELECT chamado_id FROM chamado_agente WHERE id = ?', [propostaId]);
  const ok = await executar("UPDATE chamado_agente SET status = 'rascunho', etapa = 'triagem', aprovado_por = NULL WHERE id = ? AND status = 'aprovado_execucao'", [propostaId]);
  if (!ok.affectedRows) throw new ErroAgente('Essa execução não está mais em andamento.', 409);
  await executar('DELETE FROM chamado_agente_reservas WHERE chamado_id = ?', [p?.chamado_id ?? 0]).catch(() => undefined);
  if (p) await registrarEvento(p.chamado_id, 'rascunho', 'Execução cancelada pela equipe — voltou para revisão.', propostaId);
}

/** Arquivo de referência que a equipe anexou para o agente (download pelo agente). */
export async function arquivoEntrada(arquivoId: number): Promise<{ nome: string; bytes: Buffer }> {
  const a = await primeira<{ nome: string; dados: Buffer }>('SELECT nome, dados FROM chamado_agente_entrada WHERE id = ?', [arquivoId]);
  if (!a) throw new ErroAgente('Arquivo não encontrado.', 404);
  return { nome: a.nome, bytes: Buffer.from(a.dados) };
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
  const [arquivos, entrada, eventos] = await Promise.all([
    consulta<{ id: number; nome: string }>('SELECT id, nome FROM chamado_agente_arquivos WHERE proposta_id = ?', [p.id]).then((l) => l.map((a) => ({ id: Number(a.id), nome: a.nome }))),
    consulta<{ id: number; nome: string; tamanho: number }>('SELECT id, nome, LENGTH(dados) tamanho FROM chamado_agente_entrada WHERE proposta_id = ?', [p.id]).then((l) => l.map((a) => ({ id: Number(a.id), nome: a.nome, tamanho: Number(a.tamanho) }))).catch(() => []),
    eventosDoChamado(p.chamadoId, 12),
  ]);
  let fontes: { titulo: string; url: string }[] = [];
  try { fontes = JSON.parse(p.fontes ?? '[]') as typeof fontes; } catch { /* sem fontes */ }
  return { ...p, fontes, arquivos, entrada, eventos };
}

export async function arquivoDaProposta(arquivoId: number): Promise<{ nome: string; bytes: Buffer }> {
  const a = await primeira<{ nome: string; dados: Buffer }>('SELECT nome, dados FROM chamado_agente_arquivos WHERE id = ?', [arquivoId]);
  if (!a) throw new ErroAgente('Arquivo não encontrado.', 404);
  return { nome: a.nome, bytes: Buffer.from(a.dados) };
}

// ---- Execução pela IA (Gemini): a mesa "faz" sozinha, sem gastar Claude -----
// Quando a equipe aprova a execução, o servidor desenvolve (e compila, quando é
// código) usando o GEMINI — nunca Claude — e devolve o resultado como proposta
// "pronta para revisar". Assim nada fica preso em "Trabalhando".

const EXECUTANDO = new Set<number>();
function normaliza(s: string): string { return (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); }

function usuarioAdminSintetico(nome: string): UsuarioLogado {
  return { tipo: 'equipe', id: 0, nome: nome || 'PrimeERP', email: '', perfil: 'admin', foto: null, clienteId: null, empresa: null } as UsuarioLogado;
}

/** Escolhe o servidor de compilação do cliente (pelo grupo) ou cai na base. */
async function alvoDoCliente(cliente: string): Promise<{ slug: string; nome: string } | null> {
  const alvos = await listarAlvos(usuarioAdminSintetico('PrimeERP'));
  const c = normaliza(cliente);
  const primeira1 = (s: string) => s.split(' ')[0] ?? s;
  // NUNCA auto-compilar em produção: só dev/teste/debug/exclusivo.
  const ehProducao = (a: { slug: string; nome: string }) => /prd|prod|produ/.test(normaliza(`${a.slug} ${a.nome}`));
  const doCliente = alvos.find((a) => a.liberado && !ehProducao(a) && a.grupo && (c.includes(primeira1(normaliza(a.grupo))) || normaliza(a.grupo).includes(primeira1(c))));
  if (doCliente) return { slug: doCliente.slug, nome: `${doCliente.grupo} — ${doCliente.nome}` };
  const base = alvos.find((a) => a.slug === 'base' && a.liberado);
  return base ? { slug: base.slug, nome: `${base.nome} (checagem — sem servidor de teste liberado do cliente ${cliente})` } : null;
}

interface SaidaGemini { precisa_codigo: boolean; nome_programa: string; fonte: string; resposta: string; resumo_interno: string }

async function desenvolverComGemini(det: Record<string, any>, tipo: string, instrucoes: string, referencias: string, exigeCodigo: boolean): Promise<SaidaGemini | null> {
  const chave = await configuracao('gemini_api_key', '');
  if (!chave) return null;
  const conversa: any[] = det.conversa ?? [];
  const parecidos: any[] = det.chamadosParecidosResolvidos ?? [];
  const autor = String(det.atendente ?? 'Kleber Metzger');
  const hoje = new Date().toLocaleDateString('pt-BR');
  const regraCodigo = exigeCodigo
    ? `Este é um trabalho de CÓDIGO (tipo: ${tipo}). Você DEVE entregar precisa_codigo=true e o campo "fonte" com o código ADVPL/TLPP COMPLETO e compilável — NÃO responda só com texto. Se faltar informação para um código seguro, ainda assim entregue o MELHOR fonte possível (um esqueleto funcional, comentando onde precisa ajustar) e explique no resumo_interno exatamente o que falta confirmar.`
    : `Decida se precisa de código ADVPL/TLPP ou se é só texto.`;
  const sistema = `Você é um desenvolvedor TOTVS Protheus sênior da PrimeERP. Recebe um chamado já triado e as instruções da equipe e deve ENTREGAR o trabalho (tipo: ${tipo}). ${regraCodigo} Quando houver código, escreva o fonte COMPLETO e compilável, no padrão PrimeERP: ponto de entrada ou User Function com cabeçalho Protheus.doc (@author ${autor}, @since ${hoje}, @version P12, @obs PrimeERP - Solucoes em Tecnologia) e cada alteração comentada "// [${autor} - ${hoje} - PrimeERP] o que mudou". Em "nome_programa" devolva um nome curto do fonte (ex.: MATA461A). NUNCA invente rotina, parâmetro MV_, tabela ou campo que você não tenha certeza que existe. A "resposta" é a mensagem para o cliente: português do Brasil, texto puro sem markdown, humana e acolhedora como um consultor sênior (nada de robô), em primeira pessoa do plural, SEM mencionar IA/robô/Gemini. O "resumo_interno" é só para a equipe: o que você fez, onde mexeu e como testar. Responda SOMENTE o JSON pedido.`;
  const contexto = `Chamado: ${det.chamado?.titulo}\nCliente: ${det.cliente}\nDescrição: ${String(det.chamado?.descricao ?? '').slice(0, 2500)}\n\nInstruções da equipe: ${instrucoes || '(sem instruções extras)'}\n\nÚltimas mensagens:\n${conversa.slice(-6).map((m) => `${m.de}: ${m.mensagem}`).join('\n').slice(0, 2500)}\n\nCasos parecidos já resolvidos:\n${parecidos.map((s) => `- ${s.titulo}: ${(s.respostasEquipe ?? []).join(' ').slice(0, 300)}`).join('\n').slice(0, 1500)}\n\nArquivos de referência da equipe:\n${referencias.slice(0, 4000) || '(nenhum)'}`;
  const esquema = { type: 'OBJECT', properties: {
    precisa_codigo: { type: 'BOOLEAN' }, nome_programa: { type: 'STRING' }, fonte: { type: 'STRING' }, resposta: { type: 'STRING' }, resumo_interno: { type: 'STRING' },
  }, required: ['precisa_codigo', 'resposta', 'resumo_interno'] };
  try {
    const r = await fetch('https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent', {
      method: 'POST', headers: { 'content-type': 'application/json', 'x-goog-api-key': chave },
      body: JSON.stringify({ systemInstruction: { parts: [{ text: sistema }] }, contents: [{ role: 'user', parts: [{ text: contexto }] }],
        generationConfig: { temperature: 0.3, maxOutputTokens: 8192, responseMimeType: 'application/json', responseSchema: esquema } }),
      signal: AbortSignal.timeout(90_000),
    });
    const j = (await r.json()) as any;
    const txt = String((j?.candidates?.[0]?.content?.parts ?? []).map((p: any) => p.text).join('')).trim();
    const o = JSON.parse(txt) as Partial<SaidaGemini>;
    return { precisa_codigo: Boolean(o.precisa_codigo), nome_programa: String(o.nome_programa ?? 'FONTE'), fonte: String(o.fonte ?? ''), resposta: String(o.resposta ?? ''), resumo_interno: String(o.resumo_interno ?? '') };
  } catch (e) { console.error('agente gemini exec', e instanceof Error ? e.message : e); return null; }
}

/** Executa uma proposta aprovada usando o Gemini e devolve o resultado para revisão. */
export async function executarComGemini(propostaId: number): Promise<void> {
  if (EXECUTANDO.has(propostaId)) return;
  const p = await primeira<{ id: number; chamado_id: number; tipo: string; plano: string | null; instrucoes_equipe: string | null; status: string }>(
    'SELECT id, chamado_id, tipo, plano, instrucoes_equipe, status FROM chamado_agente WHERE id = ?', [propostaId]);
  if (!p || p.status !== 'aprovado_execucao') return;
  EXECUTANDO.add(propostaId);
  try {
    await registrarEvento(p.chamado_id, 'analisando', 'IA (Gemini) analisando o chamado e as instruções.', propostaId);
    const det = (await detalheAgente(p.chamado_id)) as Record<string, any>;
    // Referências textuais que a equipe anexou (fontes/txt pequenos entram no prompt).
    const refs = await consulta<{ nome: string; dados: Buffer }>('SELECT nome, dados FROM chamado_agente_entrada WHERE proposta_id = ?', [propostaId]).catch(() => []);
    const referencias = refs.map((a) => /\.(prw|tlpp|prx|txt|csv|json|md|xml)$/i.test(a.nome) ? `--- ${a.nome} ---\n${Buffer.from(a.dados).toString('utf8').slice(0, 3000)}` : `--- ${a.nome} (anexo binário, não lido) ---`).join('\n\n');

    // Tipo de código escolhido pela equipe: a IA É OBRIGADA a entregar fonte e compilar.
    const exigeCodigo = ['desenvolvimento', 'correcao', 'melhoria'].includes(p.tipo);
    await registrarEvento(p.chamado_id, 'desenvolvendo', exigeCodigo ? 'IA escrevendo o fonte ADVPL.' : 'IA desenvolvendo a solução.', propostaId);
    const saida = await desenvolverComGemini(det, p.tipo, p.instrucoes_equipe ?? '', referencias, exigeCodigo);
    if (!saida) throw new Error('Gemini indisponível ou sem chave.');

    const temFonte = saida.fonte.trim().length > 30;
    // Se a equipe pediu desenvolvimento e a IA não conseguiu gerar fonte, AVISA (não finge que fez).
    if (exigeCodigo && !temFonte) await registrarEvento(p.chamado_id, 'erro', 'A IA não conseguiu gerar um fonte seguro para este chamado — provavelmente faltam detalhes (layout, campos, exemplo). Veja o resumo e detalhe o pedido.', propostaId);

    const arquivos: { nome: string; conteudo_base64: string }[] = [];
    let notaCompilacao = '';
    if ((saida.precisa_codigo || exigeCodigo) && temFonte) {
      const base = saida.nome_programa.replace(/\.(prw|tlpp|prx)$/i, '').replace(/[^\w]/g, '') || 'FONTE';
      const nomeArq = `${base}.prw`;
      arquivos.push({ nome: nomeArq, conteudo_base64: Buffer.from(saida.fonte, 'utf8').toString('base64') });
      const alvo = await alvoDoCliente(String(det.cliente ?? ''));
      if (alvo) {
        await registrarEvento(p.chamado_id, 'compilando', `Compilando ${nomeArq} em ${alvo.nome}.`, propostaId);
        try {
          const rc = await compilarFonte(usuarioAdminSintetico(String(det.atendente ?? 'PrimeERP')), alvo.slug, arquivos);
          if (rc.ok) {
            notaCompilacao = `Compilou OK em ${alvo.nome}. Patch: ${rc.nome} — baixar em /api/compilacao/arquivo/${rc.token}`;
            await registrarEvento(p.chamado_id, 'pronto', `Compilou OK em ${alvo.nome} (patch ${rc.nome}).`, propostaId);
          } else { notaCompilacao = `Compilou COM ERRO em ${alvo.nome}: ${rc.erro}`; await registrarEvento(p.chamado_id, 'erro', notaCompilacao, propostaId); }
        } catch (e) { notaCompilacao = `Não deu para compilar: ${e instanceof Error ? e.message : e}`; await registrarEvento(p.chamado_id, 'erro', notaCompilacao, propostaId); }
      } else {
        notaCompilacao = 'Fonte gerado, mas nenhum servidor de teste liberado do cliente — compile pelo assistente após revisar.';
      }
    }

    const resposta = saida.resposta.trim().length >= 20 ? saida.resposta.trim() : 'Olá! Preparamos o que foi solicitado e estamos finalizando a revisão. Em seguida retornamos.';
    const resumo = `${saida.resumo_interno}${notaCompilacao ? `\n\n[Compilação] ${notaCompilacao}` : ''}\n\nFeito automaticamente pela IA (Gemini). Revise antes de enviar.`.slice(0, 8000);
    await registrarProposta(p.chamado_id, {
      resposta, tipo: p.tipo, plano: `Desenvolvido pela IA (Gemini).${notaCompilacao ? ' ' + notaCompilacao : ''}`.slice(0, 8000),
      confianca: 'media', status_sugerido: 'aguardando_cliente', resumo_interno: resumo, arquivos, sessao: 'gemini-exec',
    });
  } catch (e) {
    console.error('agente executarComGemini', propostaId, e instanceof Error ? e.stack ?? e.message : e);
    await registrarEvento(p.chamado_id, 'erro', `Falha na execução automática: ${e instanceof Error ? e.message : e}`, propostaId).catch(() => undefined);
  } finally { EXECUTANDO.delete(propostaId); }
}

/** Varre execuções aprovadas que ainda não foram feitas e dispara o Gemini. */
export async function processarExecucoesAprovadas(): Promise<void> {
  if ((await configuracao('ia_chamados_motor', 'claude')) === 'desligado') return;
  const pend = await consulta<{ id: number }>("SELECT id FROM chamado_agente WHERE status = 'aprovado_execucao' ORDER BY atualizado_em LIMIT 3").catch(() => []);
  for (const e of pend) if (!EXECUTANDO.has(e.id)) { await executarComGemini(e.id).catch(() => undefined); }
}

// ---- Processador automático: nenhum chamado fica sem cair na Mesa ----------
// Roda no próprio servidor (sem rotina externa, sem token): pega chamados do
// cliente sem resposta da equipe e cria um RASCUNHO na Mesa do agente. Se o
// chamado pede conversão de fonte e tem anexo .prw, já converte (determinístico)
// e anexa o fonte convertido. O texto é um rascunho (revisado antes de enviar).
let processandoAgente = false;

export async function processarChamadosPendentes(): Promise<void> {
  if (processandoAgente) return;
  if ((await configuracao('ia_chamados_motor', 'claude')) === 'desligado') return;
  processandoAgente = true;
  try {
    const pend = await consulta<{ id: number }>(
      `SELECT ch.id FROM chamados ch JOIN clientes c ON c.id = ch.cliente_id
        WHERE ch.status IN ('aberto','aguardando_equipe') AND c.status = 'ativo'
          AND COALESCE(${ULTIMO_AUTOR}, 'cliente') <> 'equipe'
          AND NOT EXISTS (SELECT 1 FROM chamado_agente a WHERE a.chamado_id = ch.id AND a.status IN ('rascunho','aprovado_execucao','enviada','descartada') AND a.ate_resposta_id = COALESCE(${ULTIMA}, 0))
        ORDER BY FIELD(ch.prioridade,'urgente','alta','media','baixa'), ch.criado_em ASC LIMIT 3`);
    console.log(`agente: ${pend.length} chamado(s) pendente(s) para rascunhar:`, pend.map((t) => t.id).join(', '));
    for (const t of pend) {
      try { await rascunharChamado(t.id); console.log(`agente: rascunho criado para o chamado ${t.id}`); }
      catch (e) { console.error('agente auto chamado', t.id, e instanceof Error ? e.stack ?? e.message : e); }
    }
  } finally { processandoAgente = false; }
}

async function rascunharChamado(id: number): Promise<void> {
  await registrarEvento(id, 'recebido', 'Chamado recebido pelo agente.');
  const det = (await detalheAgente(id)) as Record<string, any>;
  const conversa: any[] = det.conversa ?? [];
  const anexos: any[] = det.anexos ?? [];
  const texto = `${det.chamado?.titulo ?? ''} ${det.chamado?.descricao ?? ''} ${conversa.map((m) => m.mensagem).join(' ')}`.toLowerCase();
  const pedeConversao = /user ?function|converter|convers[aã]o|ponto de entrada|\bpe\b|relat[oó]rio/.test(texto);
  const anexoFonte = anexos.find((a) => /\.(prw|tlpp|prx)$/i.test(String(a.nome)));
  const arquivos: { nome: string; conteudo_base64: string }[] = [];
  let planoConv = '';
  if (pedeConversao && anexoFonte) {
    try {
      const f = await anexoAgente(Number(anexoFonte.id));
      const base = String(anexoFonte.nome).replace(/\.(prw|tlpp|prx)$/i, '');
      const conv = converterRelatorio(f.bytes.toString('utf8'), base, String(det.atendente ?? 'Kleber Metzger'));
      arquivos.push({ nome: conv.nomeArquivo, conteudo_base64: Buffer.from(conv.fonte, 'utf8').toString('base64') });
      planoConv = `Converti ${base} para User Function ${conv.principal} (chamada ${conv.funcoes[0]?.chamada ?? ''}). Segue o fonte convertido em anexo. Para gerar o patch, use o assistente da equipe (gerar_patch) depois de revisar.`;
    } catch { planoConv = 'Pediram conversão com anexo, mas não consegui ler/converter o fonte — confira o anexo manualmente.'; }
  }
  const resposta = await rascunhoIA(det, Boolean(arquivos.length));
  await registrarProposta(id, {
    resposta,
    tipo: arquivos.length ? 'desenvolvimento' : 'resposta',
    plano: (planoConv ? planoConv + '\n\n' : '') + 'Rascunho automático do servidor — a equipe revisa e aprova antes de enviar.',
    confianca: 'media',
    status_sugerido: 'aguardando_cliente',
    resumo_interno: 'Gerado automaticamente pelo servidor (motor=claude). Revise o texto; se for desenvolvimento, compile/gere o patch pelo assistente.',
    arquivos,
    sessao: 'auto-servidor',
  });
}

async function rascunhoIA(det: Record<string, any>, temFonte: boolean): Promise<string> {
  const nome = String(det.contatoPrimeiroNome ?? '').trim();
  const padrao = `Oi, ${nome || 'tudo bem'}! Recebemos seu chamado e já estamos verificando. Em breve retornamos com a solução.`;
  const chave = await configuracao('gemini_api_key', '');
  if (!chave) return padrao;
  const conversa: any[] = det.conversa ?? [];
  const parecidos: any[] = det.chamadosParecidosResolvidos ?? [];
  const sistema = `Você é um consultor sênior da PrimeERP (consultoria TOTVS Protheus) respondendo um cliente por escrito, em nome da equipe. Escreva como uma PESSOA de verdade que LEU o chamado: natural, gentil e seguro, nada de robô nem de texto-padrão. Português do Brasil, texto puro (sem markdown, sem asteriscos).

Como escrever:
- Cumprimente a pessoa pelo nome ("${nome || 'Olá'}") de um jeito caloroso e varie o início (não comece sempre igual).
- Mostre que entendeu o problema DELA, com as palavras dela, citando a rotina/assunto específico do chamado — nunca frases vagas como "verificamos sua solicitação a respeito do...".
- Fale em primeira pessoa do plural (vimos, preparamos, vamos). Seja específico e útil: dê a orientação técnica que você tem certeza, ou peça de forma objetiva só o que falta (print do erro, código da rotina, exemplo), explicando por que ajuda.
- Feche com um próximo passo claro e um tom acolhedor. Não prometa prazo.
- Escreva a mensagem COMPLETA, de 2 a 4 parágrafos curtos. Nunca pare no meio de uma frase.

Proibido: mencionar IA, robô, assistente, modelo ou Gemini; afirmar ser humano; inventar rotina, parâmetro MV_, tabela ou prazo; assinar no fim.
${temFonte ? 'Contexto: já convertemos o fonte para User Function (vai em anexo). Diga que enviamos o fonte convertido, que podemos gerar a atualização (patch) para aplicar, e peça para testar em ambiente de teste primeiro.' : ''}`;
  const contexto = `Chamado: ${det.chamado?.titulo}\nDescrição do cliente: ${String(det.chamado?.descricao ?? '').slice(0, 1800)}\n\nÚltimas mensagens:\n${conversa.slice(-5).map((m) => `${m.de}: ${m.mensagem}`).join('\n').slice(0, 2000)}\n\nComo a equipe já respondeu casos parecidos (use como referência de tom e solução):\n${parecidos.map((s) => `- ${s.titulo}: ${(s.respostasEquipe ?? []).join(' ').slice(0, 300)}`).join('\n').slice(0, 1500)}`;
  try {
    const r = await fetch('https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent', {
      method: 'POST', headers: { 'content-type': 'application/json', 'x-goog-api-key': chave },
      body: JSON.stringify({ systemInstruction: { parts: [{ text: sistema }] }, contents: [{ role: 'user', parts: [{ text: contexto }] }],
        generationConfig: { temperature: 0.65, maxOutputTokens: 1200, thinkingConfig: { thinkingBudget: 0 } } }),
      signal: AbortSignal.timeout(30_000),
    });
    const j = (await r.json()) as any;
    const cand = j?.candidates?.[0];
    const t = String((cand?.content?.parts ?? []).map((p: any) => p.text).join('')).replace(/\*\*/g, '').trim();
    // Se veio vazio ou cortado no meio (sem pontuação final), usa o padrão em vez de mandar texto truncado.
    if (t.length < 20) return padrao;
    if (cand?.finishReason === 'MAX_TOKENS' && !/[.!?…)]\s*$/.test(t)) return padrao;
    return t;
  } catch { return padrao; }
}
