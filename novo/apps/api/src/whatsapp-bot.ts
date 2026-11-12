import { consulta, primeira } from './db';
import { assinaturaValida, avisarEquipeWhatsapp, baixarMidia, enviarBotoes, enviarLista, enviarTexto, type Linha } from './integracoes';
import {
  agendarIa, ANEXO_TIPOS, avisarMensagemWhatsapp, avisarNovoChamado, CATEGORIAS_CHAMADO, duplicadoRecente, executar,
  recalcularStatus, salvarAnexo, setoresDoResponsavel, telefonesCorrespondem,
} from './regras-chamado';

/**
 * Robô do WhatsApp da PrimeERP (menu guiado para abrir e acompanhar chamado),
 * em TypeScript -- mesmo comportamento do robô antigo, etapa por etapa.
 * Cada telefone tem uma conversa em andamento (whatsapp_conversas).
 */

const PRIORIDADES: Record<string, string> = { baixa: 'Baixa', media: 'Média', alta: 'Alta', urgente: 'Urgente' };
const MAX_TENTATIVAS_IDENTIFICACAO = 3;
const MINUTOS_BLOQUEIO_SPAM = 30;
const HORAS_ENTRE_AVISOS_HORARIO = 3;
const HORAS_ABANDONO_CONVERSA = 3;
const HORA_INICIO = 8;
const HORA_FIM = 18;
const MAX_CHAMADOS_NA_LISTA = 9;
const EMOJIS_CATEGORIA = ['📋', '🛒', '📦', '🧾', '💰', '👥', '⏰', '🧮', '📑', '💻'];
const BOTOES_POS_RESPOSTA = [{ id: 'encerrar_chamado', titulo: '✅ Encerrar' }, { id: 'responder_chamado', titulo: '💬 Responder' }, { id: 'nenhuma_acao', titulo: '🕓 Nenhuma agora' }];
const PERGUNTA_TITULO = '📝 Resuma em poucas palavras o que está acontecendo (isso vai virar o título do chamado):';

interface Conversa {
  id: number; telefone: string; nome_perfil: string | null; nome_pessoa: string | null; tentativas_identificacao: number;
  bloqueado_ate: string | null; aviso_horario_enviado_em: string | null; cliente_id: number | null; setor_id: number | null;
  chamado_continuar_id: number | null; setor_responsavel_id: number | null; etapa: string | null;
  titulo: string | null; categoria: string | null; prioridade: string | null; descricao: string | null;
  bloqueado_agora?: number; avisou_recente?: number;
}
interface Identificacao { cliente_id: number; setor_id: number | null; setor_responsavel_id: number | null; nome_cliente: string; nome_setor: string | null; nome_pessoa: string | null; bloqueado: boolean }

const primeiroNome = (n: string) => n.trim().split(' ')[0] || n;
const minus = (t: string) => t.trim().toLocaleLowerCase('pt-BR');
const ehResposta = (t: string, opcoes: string[]) => opcoes.includes(minus(t));
const ehCancelar = (t: string) => ['cancelar', 'cancela', 'reiniciar'].includes(minus(t));
const SAUDACOES = ['oi', 'ola', 'olá', 'opa', 'eae', 'e ai', 'e aí', 'oii', 'oie', 'bom dia', 'boa tarde', 'boa noite', 'tudo bem', 'tudo bom', 'blz', 'beleza', 'hello', 'hi', 'alo', 'alô'];
const saudacaoOuCurto = (t: string) => { const n = minus(t); return [...n].length < 4 || SAUDACOES.includes(n); };

function foraDoHorario(): boolean {
  const partes = new Intl.DateTimeFormat('en-US', { timeZone: 'America/Sao_Paulo', weekday: 'short', hour: 'numeric', hourCycle: 'h23' }).formatToParts(new Date());
  const dia = partes.find((p) => p.type === 'weekday')?.value ?? '';
  const hora = Number(partes.find((p) => p.type === 'hour')?.value ?? 0);
  return dia === 'Sat' || dia === 'Sun' || hora < HORA_INICIO || hora >= HORA_FIM;
}

// ---------------------------------------------------------------- Conversa

async function obterConversa(telefone: string, nomePerfil: string): Promise<Conversa> {
  const atual = await primeira<Conversa & { abandonada: number }>(
    `SELECT *, (atualizado_em < NOW() - INTERVAL ? HOUR) abandonada, (bloqueado_ate IS NOT NULL AND bloqueado_ate > NOW()) bloqueado_agora,
            (aviso_horario_enviado_em IS NOT NULL AND aviso_horario_enviado_em > NOW() - INTERVAL ? HOUR) avisou_recente
       FROM whatsapp_conversas WHERE telefone = ?`, [HORAS_ABANDONO_CONVERSA, HORAS_ENTRE_AVISOS_HORARIO, telefone]);
  if (atual && Number(atual.abandonada)) await excluir(atual.id);
  else if (atual) return atual;
  await executar('INSERT INTO whatsapp_conversas (telefone, nome_perfil) VALUES (?, ?)', [telefone, nomePerfil]);
  return (await primeira<Conversa>('SELECT *, 0 bloqueado_agora, 0 avisou_recente FROM whatsapp_conversas WHERE telefone = ?', [telefone])) as Conversa;
}

const COLUNAS = new Set(['etapa', 'cliente_id', 'setor_responsavel_id', 'setor_id', 'nome_pessoa', 'titulo', 'categoria', 'prioridade', 'descricao', 'chamado_continuar_id', 'tentativas_identificacao', 'bloqueado_ate', 'aviso_horario_enviado_em']);
async function atualizar(id: number, campos: Record<string, unknown>): Promise<void> {
  const chaves = Object.keys(campos).filter((c) => COLUNAS.has(c));
  if (!chaves.length) return;
  const sets = chaves.map((c) => (campos[c] === 'AGORA' ? `${c} = NOW()` : campos[c] === 'BLOQUEIO' ? `${c} = NOW() + INTERVAL ${MINUTOS_BLOQUEIO_SPAM} MINUTE` : `${c} = ?`));
  const valores = chaves.filter((c) => campos[c] !== 'AGORA' && campos[c] !== 'BLOQUEIO').map((c) => campos[c]);
  await executar(`UPDATE whatsapp_conversas SET ${sets.join(', ')} WHERE id = ?`, [...valores, id]);
}
const excluir = (id: number) => executar('DELETE FROM whatsapp_conversas WHERE id = ?', [id]);
const novoAssunto = (extra: Record<string, unknown>) => ({ titulo: null, categoria: null, prioridade: null, descricao: null, ...extra });

// ---------------------------------------------------------------- Identificação

async function identificar(telefone: string): Promise<Identificacao | null> {
  const resp = await consulta<{ id: number; nome: string; telefone: string; setor_id: number | null; bloqueado_whatsapp: number; nome_setor: string | null; cliente_id: number; nome_cliente: string }>(
    `SELECT sr.id, sr.nome, sr.telefone, sr.setor_id, sr.bloqueado_whatsapp, s.nome nome_setor, sr.cliente_id, c.nome nome_cliente
       FROM setor_responsaveis sr JOIN clientes c ON c.id = sr.cliente_id LEFT JOIN setores s ON s.id = sr.setor_id
      WHERE c.status = 'ativo' AND sr.telefone IS NOT NULL
        AND EXISTS (SELECT 1 FROM setor_responsavel_setores srs JOIN setores s2 ON s2.id = srs.setor_id WHERE srs.setor_responsavel_id = sr.id AND s2.ativo = 1)`);
  for (const l of resp) {
    if (telefonesCorrespondem(telefone, l.telefone)) {
      return { cliente_id: Number(l.cliente_id), setor_id: l.setor_id === null ? null : Number(l.setor_id), setor_responsavel_id: Number(l.id), nome_cliente: l.nome_cliente, nome_setor: l.nome_setor, nome_pessoa: l.nome, bloqueado: Boolean(Number(l.bloqueado_whatsapp)) };
    }
  }
  const clientes = await consulta<{ id: number; nome: string; contato_nome: string | null; contato_telefone: string; bloqueado_whatsapp: number }>(
    "SELECT id, nome, contato_nome, contato_telefone, bloqueado_whatsapp FROM clientes WHERE status = 'ativo' AND contato_telefone IS NOT NULL");
  for (const l of clientes) {
    if (telefonesCorrespondem(telefone, l.contato_telefone)) {
      return { cliente_id: Number(l.id), setor_id: null, setor_responsavel_id: null, nome_cliente: l.nome, nome_setor: null, nome_pessoa: l.contato_nome || null, bloqueado: Boolean(Number(l.bloqueado_whatsapp)) };
    }
  }
  return null;
}

async function chamadosAbertos(clienteId: number, setorResponsavelId: number | null): Promise<{ id: number; titulo: string }[]> {
  return setorResponsavelId
    ? consulta(`SELECT id, titulo FROM chamados WHERE aberto_por_setor_responsavel_id = ? AND status != 'fechado' ORDER BY criado_em DESC LIMIT ${MAX_CHAMADOS_NA_LISTA}`, [setorResponsavelId])
    : consulta(`SELECT id, titulo FROM chamados WHERE cliente_id = ? AND aberto_por_tipo = 'master' AND status != 'fechado' ORDER BY criado_em DESC LIMIT ${MAX_CHAMADOS_NA_LISTA}`, [clienteId]);
}
const linhasChamados = (l: { id: number; titulo: string }[]): Linha[] => [
  ...l.map((c) => ({ id: `chamado_${c.id}`, titulo: `#${c.id}`, descricao: c.titulo })),
  { id: 'novo', titulo: '🆕 Novo chamado', descricao: 'Assunto diferente dos de cima' },
];

async function setoresDisponiveis(clienteId: number): Promise<{ id: number; nome: string }[]> {
  return consulta(`SELECT s.id, s.nome FROM setores s WHERE s.cliente_id = ? AND s.ativo = 1
      AND (EXISTS (SELECT 1 FROM cliente_setores cs WHERE cs.cliente_id = s.cliente_id AND cs.setor_id = s.id) OR EXISTS (SELECT 1 FROM setor_responsavel_setores srs WHERE srs.setor_id = s.id))
    ORDER BY s.nome`, [clienteId]);
}
async function setoresParaEscolha(clienteId: number, setorResponsavelId: number | null): Promise<{ id: number; nome: string }[]> {
  if (!setorResponsavelId) return setoresDisponiveis(clienteId);
  const ids = await setoresDoResponsavel(setorResponsavelId);
  if (!ids.length) return [];
  return consulta(`SELECT id, nome FROM setores WHERE cliente_id = ? AND ativo = 1 AND id IN (${ids.map(() => '?').join(',')}) ORDER BY nome`, [clienteId, ...ids]);
}
const linhasSetores = (s: { nome: string }[], destaqueId?: number) => s.map((x, i) => ({ id: String(i + 1), titulo: `${'id' in x && Number((x as { id: number }).id) === destaqueId ? '⭐ ' : ''}${x.nome}` }));
const linhasCategoria = (): Linha[] => CATEGORIAS_CHAMADO.map((n, i) => ({ id: String(i + 1), titulo: `${EMOJIS_CATEGORIA[i] ?? '▫️'} ${n}` }));
const linhasPrioridade = (): Linha[] => [{ id: '1', titulo: '🟢 Baixa' }, { id: '2', titulo: '🟡 Média' }, { id: '3', titulo: '🟠 Alta' }, { id: '4', titulo: '🔴 Urgente' }];

// ---------------------------------------------------------------- Etapas

async function etapaIdentificar(c: Conversa): Promise<void> {
  const id = await identificar(c.telefone);
  if (!id) {
    await atualizar(c.id, { etapa: 'aguardando_identificacao_manual' });
    await enviarTexto(c.telefone, '👋 Oi! Aqui é o atendimento da PrimeERP. Não encontrei seu número no nosso cadastro -- pode me dizer o nome da sua empresa?');
    return;
  }
  if (id.bloqueado) {
    const quem = id.nome_pessoa ?? (c.nome_perfil || c.telefone);
    await avisarEquipeWhatsapp(`🚫 WhatsApp: ${quem} (${c.telefone}), de ${id.nome_cliente}, tentou abrir chamado mas está com o acesso bloqueado.`);
    await enviarTexto(c.telefone, '🚫 Seu acesso a abrir chamados por aqui está temporariamente bloqueado. Fale com sua empresa ou com a PrimeERP por outro canal.');
    await excluir(c.id);
    return;
  }
  await avancarAposIdentificar(c, id.cliente_id, id.nome_cliente, id.nome_pessoa, id.setor_responsavel_id, id.setor_id, id.nome_setor);
}

async function avancarAposIdentificar(c: Conversa, clienteId: number, nomeCliente: string, nomePessoa: string | null, setorResponsavelId: number | null, setorId: number | null, nomeSetor: string | null): Promise<void> {
  const dados: Record<string, unknown> = { cliente_id: clienteId, setor_responsavel_id: setorResponsavelId, nome_pessoa: nomePessoa, titulo: null, categoria: null, prioridade: null, descricao: null };
  const oi = nomePessoa ? `Oi, ${primeiroNome(nomePessoa)}! 👋 ` : '👋 ';

  const abertos = await chamadosAbertos(clienteId, setorResponsavelId);
  if (abertos.length) {
    await atualizar(c.id, { ...dados, setor_id: setorId, chamado_continuar_id: null, etapa: 'escolher_chamado' });
    const resumo = abertos.length === 1 ? 'Vi que você já tem 1 chamado em aberto.' : `Vi que você já tem ${abertos.length} chamados em aberto.`;
    await enviarLista(c.telefone, `${oi}Encontrei seu cadastro: *${nomeCliente}*. ${resumo} É sobre algum deles ou é um assunto novo?`, 'Escolher', linhasChamados(abertos));
    return;
  }
  if (setorResponsavelId) {
    if ((await setoresDoResponsavel(setorResponsavelId)).length <= 1) {
      await atualizar(c.id, { ...dados, setor_id: setorId, etapa: 'titulo' });
      await enviarTexto(c.telefone, `${oi}Encontrei seu cadastro: *${nomeCliente}* (${nomeSetor ?? ''}). ✅ Vamos abrir seu chamado.\n\n${PERGUNTA_TITULO}`);
      return;
    }
    await atualizar(c.id, { ...dados, etapa: 'escolher_setor' });
    await enviarLista(c.telefone, `${oi}Encontrei seu cadastro: *${nomeCliente}*. Vi que você está em mais de um setor. 🗂️ Qual setor é o assunto?`, 'Escolher setor', linhasSetores(await setoresParaEscolha(clienteId, setorResponsavelId)));
    return;
  }
  const setores = await setoresDisponiveis(clienteId);
  if (setores.length) {
    await atualizar(c.id, { ...dados, etapa: 'escolher_setor' });
    const padrao = await primeira<{ s: number | null }>('SELECT setor_id s FROM clientes WHERE id = ?', [clienteId]);
    await enviarLista(c.telefone, `${oi}Encontrei seu cadastro: *${nomeCliente}*. 🗂️ Qual setor é o assunto?`, 'Escolher setor', linhasSetores(setores, Number(padrao?.s ?? 0)));
    return;
  }
  await atualizar(c.id, { ...dados, etapa: 'titulo' });
  await enviarTexto(c.telefone, `${oi}Encontrei seu cadastro: *${nomeCliente}*. ✅ Vamos abrir seu chamado.\n\n${PERGUNTA_TITULO}`);
}

async function etapaIdentificacaoManual(c: Conversa, texto: string): Promise<void> {
  const achados = await consulta<{ id: number; nome: string }>("SELECT id, nome FROM clientes WHERE status = 'ativo' AND nome LIKE ?", [`%${texto}%`]);
  const unico = achados.length === 1 ? achados[0] : undefined;
  if (unico) { await avancarAposIdentificar(c, Number(unico.id), unico.nome, null, null, null, null); return; }
  const tentativas = Number(c.tentativas_identificacao) + 1;
  if (tentativas >= MAX_TENTATIVAS_IDENTIFICACAO) {
    await atualizar(c.id, { tentativas_identificacao: 0, bloqueado_ate: 'BLOQUEIO' });
    await avisarEquipeWhatsapp(`🚫 WhatsApp: ${c.nome_perfil ?? ''} (${c.telefone}) excedeu tentativas de identificação (última tentativa: "${texto}") e ficou bloqueado temporariamente.`);
    await enviarTexto(c.telefone, '🚫 Muitas tentativas sem conseguir confirmar seu cadastro. Aguarde um pouco e tente de novo, ou fale com a PrimeERP por outro canal.');
    return;
  }
  await atualizar(c.id, { tentativas_identificacao: tentativas });
  await enviarTexto(c.telefone, '😕 Não encontrei nenhuma empresa com esse nome. Pode digitar de novo, com o nome completo da empresa?');
}

async function iniciarNovoAssunto(c: Conversa): Promise<void> {
  const clienteId = Number(c.cliente_id);
  const sr = c.setor_responsavel_id ? Number(c.setor_responsavel_id) : null;
  if (sr && (await setoresDoResponsavel(sr)).length <= 1) {
    await atualizar(c.id, novoAssunto({ etapa: 'titulo', chamado_continuar_id: null }));
    await enviarTexto(c.telefone, `📝 Beleza, novo assunto. ${PERGUNTA_TITULO.slice(3)}`);
    return;
  }
  const setores = await setoresParaEscolha(clienteId, sr);
  if (setores.length) {
    await atualizar(c.id, novoAssunto({ etapa: 'escolher_setor', chamado_continuar_id: null }));
    await enviarLista(c.telefone, '🗂️ Beleza, novo assunto. Qual setor é o assunto?', 'Escolher setor', linhasSetores(setores));
    return;
  }
  await atualizar(c.id, novoAssunto({ etapa: 'titulo', chamado_continuar_id: null }));
  await enviarTexto(c.telefone, `📝 Beleza, novo assunto. ${PERGUNTA_TITULO.slice(3)}`);
}

async function etapaEscolherChamado(c: Conversa, texto: string): Promise<void> {
  if (ehResposta(texto, ['novo', 'assunto novo', 'outro', 'novo chamado'])) { await iniciarNovoAssunto(c); return; }
  const abertos = await chamadosAbertos(Number(c.cliente_id), c.setor_responsavel_id ? Number(c.setor_responsavel_id) : null);
  const idEscolhido = texto.startsWith('chamado_') ? Number(texto.slice(8)) : NaN;
  const escolhido = abertos.find((x) => Number(x.id) === idEscolhido);
  if (!escolhido) {
    if (!abertos.length) { await iniciarNovoAssunto(c); return; }
    await enviarLista(c.telefone, '🤔 Não entendi. Toca numa das opções: é sobre algum chamado já aberto, ou é um assunto novo?', 'Escolher', linhasChamados(abertos));
    return;
  }
  await atualizar(c.id, { chamado_continuar_id: escolhido.id, etapa: 'mensagem_continuar' });
  await enviarTexto(c.telefone, `✍️ Certo, chamado #${escolhido.id}. Manda sua mensagem que eu registro por lá.`);
}

async function etapaMensagemContinuar(c: Conversa, texto: string): Promise<void> {
  if (!texto || saudacaoOuCurto(texto)) { await enviarTexto(c.telefone, '🤔 Pode escrever a mensagem que quer registrar no chamado?'); return; }
  const chamadoId = Number(c.chamado_continuar_id);
  const ch = await primeira<{ id: number; titulo: string; cliente_id: number }>('SELECT id, titulo, cliente_id FROM chamados WHERE id = ?', [chamadoId]);
  if (!ch) {
    await excluir(c.id);
    await enviarTexto(c.telefone, '😕 Não encontrei mais esse chamado. Manda "oi" de novo pra começar um atendimento novo.');
    return;
  }
  const r = await executar("INSERT INTO chamado_respostas (chamado_id, autor_tipo, autor_nome, autor_cliente_tipo, autor_setor_responsavel_id, mensagem) VALUES (?, 'cliente', ?, ?, ?, ?)",
    [chamadoId, c.nome_pessoa || c.nome_perfil, c.setor_responsavel_id ? 'setor' : 'master', c.setor_responsavel_id, texto]);
  for (const m of await consulta<{ arquivo_nome_original: string | null; mime_type: string | null; dados: Buffer }>('SELECT arquivo_nome_original, mime_type, dados FROM whatsapp_midias_temp WHERE conversa_id = ?', [c.id])) {
    await salvarAnexo(chamadoId, r.insertId, 'cliente', m.arquivo_nome_original || 'anexo', m.mime_type || 'application/octet-stream', m.dados);
  }
  await recalcularStatus(chamadoId);
  const cli = await primeira<{ nome: string }>('SELECT nome FROM clientes WHERE id = ?', [ch.cliente_id]);
  await avisarMensagemWhatsapp(ch, cli?.nome ?? '', texto);
  await excluir(c.id);
  await enviarTexto(c.telefone, `✅ Registrado no chamado #${chamadoId}. Nossa equipe vai ver sua mensagem e responder por aqui.`);
}

async function etapaAcaoPosResposta(c: Conversa, texto: string): Promise<void> {
  const chamadoId = Number(c.chamado_continuar_id);
  if (texto === 'encerrar_chamado') {
    await executar("UPDATE chamados SET status = 'fechado', fechado_em = NOW() WHERE id = ?", [chamadoId]);
    await excluir(c.id);
    await enviarTexto(c.telefone, `✅ Chamado #${chamadoId} encerrado. Se precisar de mais alguma coisa, é só mandar mensagem por aqui. 🙂`);
    return;
  }
  if (texto === 'responder_chamado') {
    await atualizar(c.id, { etapa: 'mensagem_continuar' });
    await enviarTexto(c.telefone, `✍️ Pode mandar sua mensagem que eu registro no chamado #${chamadoId}.`);
    return;
  }
  if (texto === 'nenhuma_acao') {
    await excluir(c.id);
    await enviarTexto(c.telefone, '👍 Tudo bem, fico por aqui. Quando quiser encerrar ou responder esse chamado, é só me chamar de novo.');
    return;
  }
  await enviarBotoes(c.telefone, '🤔 Não entendi. Toca numa das opções:', BOTOES_POS_RESPOSTA);
}

async function etapaEscolherSetor(c: Conversa, texto: string): Promise<void> {
  const setores = await setoresParaEscolha(Number(c.cliente_id), c.setor_responsavel_id ? Number(c.setor_responsavel_id) : null);
  const escolha = /^\d+$/.test(texto.trim()) ? setores[Number(texto.trim()) - 1] : undefined;
  if (!escolha) { await enviarLista(c.telefone, '🤔 Não entendi. Qual setor é o assunto?', 'Escolher setor', linhasSetores(setores)); return; }
  await atualizar(c.id, { setor_id: escolha.id, etapa: 'titulo' });
  await enviarTexto(c.telefone, `✅ Certo, ${escolha.nome}.\n\n${PERGUNTA_TITULO}`);
}

async function etapaTitulo(c: Conversa, texto: string): Promise<void> {
  if (!texto || saudacaoOuCurto(texto)) { await enviarTexto(c.telefone, '🤔 Isso parece só uma saudação. Me conta rapidinho qual é o problema -- isso vai virar o título do chamado:'); return; }
  await atualizar(c.id, { titulo: [...texto].slice(0, 200).join(''), etapa: 'categoria' });
  await enviarLista(c.telefone, '🏷️ Qual a categoria do problema?', 'Escolher', linhasCategoria());
}

async function etapaCategoria(c: Conversa, texto: string): Promise<void> {
  const cat = /^\d+$/.test(texto.trim()) ? CATEGORIAS_CHAMADO[Number(texto.trim()) - 1] : undefined;
  if (!cat) { await enviarLista(c.telefone, '🤔 Não entendi. Qual a categoria do problema?', 'Escolher', linhasCategoria()); return; }
  await atualizar(c.id, { categoria: cat, etapa: 'prioridade' });
  await enviarLista(c.telefone, '📶 E a prioridade?', 'Escolher', linhasPrioridade());
}

async function etapaPrioridade(c: Conversa, texto: string): Promise<void> {
  const chave = /^\d+$/.test(texto.trim()) ? Object.keys(PRIORIDADES)[Number(texto.trim()) - 1] : undefined;
  if (!chave) { await enviarLista(c.telefone, '🤔 Não entendi. E a prioridade?', 'Escolher', linhasPrioridade()); return; }
  await atualizar(c.id, { prioridade: chave, etapa: 'descricao' });
  await enviarTexto(c.telefone, '📄 Agora descreve o problema com o máximo de detalhe que puder.');
}

async function etapaDescricao(c: Conversa, texto: string): Promise<void> {
  if (!texto || saudacaoOuCurto(texto)) { await enviarTexto(c.telefone, '🤔 Preciso de uma descrição um pouco melhor do problema, pode escrever com suas palavras mesmo.'); return; }
  await atualizar(c.id, { descricao: texto, etapa: 'anexos' });
  await enviarBotoes(c.telefone, '📎 Quer mandar algum print ou arquivo? Pode enviar agora (foto ou PDF), ou tocar no botão pra continuar sem anexo.', [{ id: 'nao', titulo: '🚫 Sem anexo' }]);
}

async function etapaAnexos(c: Conversa, texto: string): Promise<void> {
  if (!ehResposta(texto, ['nao', 'não', 'pronto', 'pular', 'continuar'])) {
    await enviarBotoes(c.telefone, '📎 Pode mandar o print/arquivo, ou tocar no botão pra continuar sem anexo.', [{ id: 'nao', titulo: '✅ Continuar' }]);
    return;
  }
  await atualizar(c.id, { etapa: 'confirmacao' });
  const qtd = Number((await primeira<{ n: number }>('SELECT COUNT(*) n FROM whatsapp_midias_temp WHERE conversa_id = ?', [c.id]))?.n ?? 0);
  const desc = c.descricao ?? '';
  const previa = [...desc].length > 300 ? `${[...desc].slice(0, 300).join('')}(...)` : desc;
  const resumo = `🔎 Confere se está tudo certo:\n\n📝 *Título:* ${c.titulo ?? ''}\n🏷️ *Categoria:* ${c.categoria ?? ''}\n📶 *Prioridade:* ${PRIORIDADES[c.prioridade ?? ''] ?? ''}\n📄 *Descrição:* ${previa}\n${qtd > 0 ? `📎 *Anexos:* ${qtd} arquivo(s)\n` : ''}\nPosso abrir o chamado?`;
  await enviarBotoes(c.telefone, resumo, [{ id: 'sim', titulo: '✅ Sim, abrir' }, { id: 'nao', titulo: '✏️ Corrigir' }]);
}

async function etapaConfirmacao(c: Conversa, texto: string): Promise<void> {
  if (ehResposta(texto, ['sim', 's', 'confirmar', 'confirmo', 'pode'])) { await criarChamado(c); return; }
  if (ehResposta(texto, ['nao', 'não', 'n'])) {
    await atualizar(c.id, { etapa: 'titulo', titulo: null, categoria: null, prioridade: null, descricao: null });
    await executar('DELETE FROM whatsapp_midias_temp WHERE conversa_id = ?', [c.id]);
    await enviarTexto(c.telefone, '🔄 Sem problema, vamos refazer. Resuma em poucas palavras o que está acontecendo:');
    return;
  }
  await enviarTexto(c.telefone, 'Toca em um dos botões acima 👆, ou responda "sim" pra abrir o chamado, ou "não" pra corrigir.');
}

async function criarChamado(c: Conversa): Promise<void> {
  const titulo = (c.titulo ?? '').toLocaleUpperCase('pt-BR');
  const descricao = c.descricao ?? '';
  const clienteId = Number(c.cliente_id);
  const pronto = c.nome_pessoa ? `Prontinho, ${primeiroNome(c.nome_pessoa)}!` : 'Prontinho!';
  if (await duplicadoRecente(clienteId, titulo, descricao)) {
    await excluir(c.id);
    await enviarTexto(c.telefone, `✅ ${pronto} Seu chamado já tinha sido aberto. 🙌 Nossa equipe já foi avisada e vai te responder por aqui.`);
    return;
  }
  const tipo = c.setor_responsavel_id ? 'setor' : 'master';
  const r = await executar(`INSERT INTO chamados (cliente_id, titulo, descricao, categoria, prioridade, aberto_por, aberto_por_tipo, aberto_por_setor_responsavel_id, origem_canal, setor_id, telefone_whatsapp)
      VALUES (?, ?, ?, ?, ?, 'cliente', ?, ?, 'whatsapp', ?, ?)`, [clienteId, titulo, descricao, c.categoria, c.prioridade, tipo, c.setor_responsavel_id, c.setor_id, c.telefone]);
  const chamadoId = r.insertId;
  for (const m of await consulta<{ arquivo_nome_original: string | null; mime_type: string | null; dados: Buffer }>('SELECT arquivo_nome_original, mime_type, dados FROM whatsapp_midias_temp WHERE conversa_id = ?', [c.id])) {
    await salvarAnexo(chamadoId, null, 'cliente', m.arquivo_nome_original || 'anexo', m.mime_type || 'application/octet-stream', m.dados);
  }
  const cli = await primeira<{ id: number; nome: string; contato_nome: string | null; contato_email: string | null; contato_telefone: string | null }>('SELECT id, nome, contato_nome, contato_email, contato_telefone FROM clientes WHERE id = ?', [clienteId]);
  await excluir(c.id);
  await enviarTexto(c.telefone, `✅ ${pronto} Chamado #${chamadoId} aberto com sucesso. 🙌 Nossa equipe já foi avisada e vai te responder por aqui.`);
  if (cli) {
    await avisarNovoChamado({ id: chamadoId, titulo, prioridade: c.prioridade ?? 'media', descricao, setor_id: c.setor_id, aberto_por_tipo: tipo, aberto_por_setor_responsavel_id: c.setor_responsavel_id }, cli);
  }
  await agendarIa(chamadoId);
}

// ---------------------------------------------------------------- Entrada

async function bloqueadoOuForaDoHorario(c: Conversa): Promise<boolean> {
  if (Number(c.bloqueado_agora)) return true;
  if (foraDoHorario()) {
    let clienteId = c.cliente_id ? Number(c.cliente_id) : null;
    if (!clienteId) clienteId = (await identificar(c.telefone))?.cliente_id ?? null;
    const liberado = clienteId ? Number((await primeira<{ l: number }>('SELECT whatsapp_liberacao_horario l FROM clientes WHERE id = ?', [clienteId]))?.l ?? 0) : 0;
    if (!liberado) {
      if (!Number(c.avisou_recente)) {
        await atualizar(c.id, { aviso_horario_enviado_em: 'AGORA' });
        await enviarTexto(c.telefone, `🕗 Nosso atendimento por aqui funciona de segunda a sexta, das ${HORA_INICIO}h às ${HORA_FIM}h. Recebemos sua mensagem e alguém vai te responder no próximo horário comercial. 🙏`);
      }
      return true;
    }
  }
  return false;
}

export async function processarTexto(telefone: string, nomePerfil: string, bruto: string): Promise<void> {
  const texto = bruto.trim();
  const c = await obterConversa(telefone, nomePerfil);
  if (await bloqueadoOuForaDoHorario(c)) return;
  if (ehCancelar(texto)) {
    await excluir(c.id);
    await enviarTexto(telefone, '🚫 Cancelado! Quando quiser abrir um chamado, é só mandar mensagem de novo. 🙂');
    return;
  }
  const etapas: Record<string, (c: Conversa, t: string) => Promise<void>> = {
    aguardando_identificacao_manual: etapaIdentificacaoManual, escolher_chamado: etapaEscolherChamado, mensagem_continuar: etapaMensagemContinuar,
    aguardando_acao_resposta: etapaAcaoPosResposta, escolher_setor: etapaEscolherSetor, titulo: etapaTitulo, categoria: etapaCategoria,
    prioridade: etapaPrioridade, descricao: etapaDescricao, anexos: etapaAnexos, confirmacao: etapaConfirmacao,
  };
  const etapa = etapas[c.etapa ?? ''];
  await (etapa ? etapa(c, texto) : etapaIdentificar(c));
}

export async function processarMidia(telefone: string, nomePerfil: string, mediaId: string, nomeArquivo: string | null): Promise<void> {
  const c = await obterConversa(telefone, nomePerfil);
  if (await bloqueadoOuForaDoHorario(c)) return;
  const midia = await baixarMidia(mediaId);
  if (!midia) { await enviarTexto(telefone, '😕 Não consegui baixar esse arquivo agora, pode tentar mandar de novo?'); return; }
  const ext = ANEXO_TIPOS[midia.mime];
  if (!ext) {
    await enviarBotoes(telefone, '🚫 Esse tipo de arquivo eu não consigo anexar (imagem, PDF, Word/Excel/PowerPoint, texto ou ZIP). Pode mandar outro, ou tocar no botão pra continuar sem anexo.', [{ id: 'nao', titulo: '✅ Continuar' }]);
    return;
  }
  // Guarda já (mesmo fora da etapa de anexos): entra no chamado no final.
  await executar('INSERT INTO whatsapp_midias_temp (conversa_id, arquivo_nome_original, mime_type, dados) VALUES (?, ?, ?, ?)', [c.id, nomeArquivo || `anexo.${ext}`, midia.mime, midia.bytes]);
  if (c.etapa !== 'anexos') {
    await enviarTexto(telefone, '📌 Arquivo recebido e guardado -- vou anexar ele no chamado automaticamente. Só preciso de mais algumas informações antes. Vou te perguntar de novo o que falta.');
    await enviarTexto(telefone, 'Pode responder minha última pergunta quando puder. 🙂');
    return;
  }
  await enviarBotoes(telefone, '✅ Recebido! Pode mandar mais algum print/arquivo, ou tocar no botão quando terminar.', [{ id: 'nao', titulo: '✅ Continuar' }]);
}

/** Depois que a equipe responde um chamado: pergunta no mesmo fio o que a pessoa quer fazer. */
export async function prepararConversaPosResposta(chamadoId: number, telefone: string): Promise<void> {
  if (!telefone) return;
  const c = await obterConversa(telefone, '');
  await atualizar(c.id, { etapa: 'aguardando_acao_resposta', chamado_continuar_id: chamadoId });
  await enviarBotoes(telefone, '👉 O que você deseja fazer agora com esse chamado?', BOTOES_POS_RESPOSTA);
}

// ---------------------------------------------------------------- Webhook

interface Payload { entry?: { changes?: { value?: { messages?: Record<string, unknown>[]; contacts?: { wa_id: string; profile?: { name?: string } }[] } }[] }[] }

/** Processa o webhook já validado (roda depois de responder 200 pra Meta). */
export async function processarWebhook(corpo: string): Promise<void> {
  let p: Payload;
  try { p = JSON.parse(corpo) as Payload; } catch { return; }
  for (const entry of p.entry ?? []) {
    for (const mudanca of entry.changes ?? []) {
      const v = mudanca.value ?? {};
      if (!v.messages?.length) continue; // "statuses" (entregue/lido) não é mensagem nova
      const nomes = new Map((v.contacts ?? []).map((c) => [c.wa_id, c.profile?.name ?? '']));
      for (const m of v.messages) {
        const tel = typeof m.from === 'string' ? m.from : '';
        if (!tel) continue;
        const nome = nomes.get(tel) ?? '';
        try {
          if (m.type === 'text') await processarTexto(tel, nome, String((m.text as { body?: string } | undefined)?.body ?? ''));
          else if (m.type === 'interactive') {
            const i = (m.interactive ?? {}) as { button_reply?: { id?: string }; list_reply?: { id?: string } };
            const id = i.button_reply?.id ?? i.list_reply?.id;
            if (id) await processarTexto(tel, nome, id);
          } else if (m.type === 'image' || m.type === 'document') {
            const md = (m[m.type] ?? {}) as { id?: string; filename?: string };
            if (md.id) await processarMidia(tel, nome, md.id, md.filename ?? null);
          }
        } catch (e) {
          console.error(`WhatsApp: erro processando mensagem de ${tel.slice(0, 4)}****:`, e instanceof Error ? e.message : e);
        }
      }
    }
  }
}

export { assinaturaValida };
