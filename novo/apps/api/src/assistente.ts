import { converterRelatorio, manualEmTexto, type UsuarioLogado } from '@primehub/shared';
import { filtroEscopoCliente, filtroEscopoEquipe } from './auth';
import { consulta, primeira } from './db';
import { compilarFonte, guardarArtefato, listarAlvos, podeCompilacao } from './compilacao';
import { podePainelVm, resetarSenhaProtheus } from './vm-servicos';

/**
 * DOIS assistentes separados, ambos no Gemini pago da Prime:
 *
 *  - EQUIPE (painel administrativo): conhece o manual do Prime Hub, consulta
 *    a operação e PROPÕE ações (abrir/encerrar chamado, mudar situação,
 *    trocar responsável). Ação que muda dado nunca roda sozinha: volta como
 *    cartão de confirmação e a tela executa pelo api_acoes.php (mesmas regras,
 *    avisos e permissões de sempre).
 *  - CLIENTE (portal): especialista em Protheus/ADVPL que fala em nome da
 *    equipe, vê só os chamados da empresa (e do setor) da pessoa, nunca gera
 *    código, nunca contorna o sistema de chamados e sempre defende a Prime.
 */

const MODELOS = ['gemini-flash-latest', 'gemini-flash-lite-latest', 'gemini-flash-lite-latest', 'gemini-flash-latest'];
const LIMITE_DIA = { equipe: 200, cliente: 40 } as const;
const usoHoje = new Map<string, { dia: string; n: number }>();

export interface MensagemChat { autor: 'pessoa' | 'assistente'; texto: string }
export interface AcaoProposta { id: string; titulo: string; detalhe: string; arquivo: 'api_acoes.php'; campos: Record<string, string>; perigo?: boolean }
export interface RespostaAssistente { texto: string; acoes?: AcaoProposta[] }

type Parte = { text?: string; thought?: boolean; functionCall?: { name: string; args?: Record<string, unknown> }; functionResponse?: unknown; thoughtSignature?: string };
type Conteudo = { role: 'user' | 'model'; parts: Parte[] };

async function config(chave: string): Promise<string> {
  const l = await primeira<{ valor: string | null }>('SELECT valor FROM configuracoes WHERE chave = ?', [chave]).catch(() => null);
  return l?.valor?.trim() ?? '';
}

/** Nome de quem "assina" pelo portal: o mesmo de "Responder em nome de" da IA dos chamados. */
async function nomeAtendente(): Promise<string> {
  const id = Number(await config('ia_chamados_usuario_id')) || 0;
  const u = id ? await primeira<{ nome: string }>('SELECT nome FROM usuarios WHERE id = ? AND ativo = 1', [id]) : null;
  const k = u ?? await primeira<{ nome: string }>("SELECT nome FROM usuarios WHERE ativo = 1 AND nome LIKE 'Kleber%' ORDER BY id LIMIT 1");
  return (k?.nome ?? 'Kleber').split(' ')[0] ?? 'Kleber';
}

const curto = (t: string | null | undefined, n = 400) => (t ?? '').replace(/\s+/g, ' ').trim().slice(0, n);
const ROTULO: Record<string, string> = { aberto: 'Aberto', aguardando_equipe: 'Aguardando nós', aguardando_cliente: 'Aguardando cliente', fechado: 'Fechado' };

// ---------------------------------------------------------------- Ferramentas

interface Ferramenta {
  declaracao: { name: string; description: string; parameters?: { type: 'OBJECT'; properties: Record<string, { type: string; description: string; enum?: string[] }>; required?: string[] } };
  executar: (u: UsuarioLogado, a: Record<string, unknown>, acoes: AcaoProposta[]) => Promise<unknown>;
}

const txt = (a: Record<string, unknown>, k: string) => (typeof a[k] === 'string' ? (a[k] as string).trim() : typeof a[k] === 'number' ? String(a[k]) : '');
const num = (a: Record<string, unknown>, k: string) => Number(a[k]) || 0;

async function chamadoVisivel(u: UsuarioLogado, id: number) {
  const e = u.tipo === 'equipe' ? await filtroEscopoEquipe(u) : filtroEscopoCliente(u);
  return primeira<{ id: number; titulo: string; status: string; prioridade: string; cliente: string; clienteId: number; responsavel: string | null; descricao: string; criadoEm: string; setor: string | null }>(
    `SELECT ch.id, ch.titulo, ch.status, ch.prioridade, c.nome cliente, ch.cliente_id clienteId, u.nome responsavel, ch.descricao, ch.criado_em criadoEm, s.nome setor
       FROM chamados ch JOIN clientes c ON c.id = ch.cliente_id LEFT JOIN usuarios u ON u.id = ch.responsavel_id LEFT JOIN setores s ON s.id = ch.setor_id
      WHERE ${e.sql} AND ch.id = ?`, [...e.params, id]);
}

async function buscarChamados(u: UsuarioLogado, a: Record<string, unknown>) {
  const e = u.tipo === 'equipe' ? await filtroEscopoEquipe(u) : filtroEscopoCliente(u);
  const cond = [e.sql];
  const p: unknown[] = [...e.params];
  const status = txt(a, 'status');
  if (status === 'pendentes') cond.push("ch.status <> 'fechado'");
  else if (ROTULO[status]) { cond.push('ch.status = ?'); p.push(status); }
  const termo = txt(a, 'texto');
  if (termo) { cond.push('(ch.titulo LIKE ? OR ch.descricao LIKE ? OR c.nome LIKE ? OR ch.id = ?)'); p.push(`%${termo}%`, `%${termo}%`, `%${termo}%`, Number(termo) || 0); }
  const l = await consulta<{ id: number; titulo: string; status: string; prioridade: string; cliente: string; responsavel: string | null; atualizado: string }>(
    `SELECT ch.id, ch.titulo, ch.status, ch.prioridade, c.nome cliente, u.nome responsavel, ch.atualizado_em atualizado
       FROM chamados ch JOIN clientes c ON c.id = ch.cliente_id LEFT JOIN usuarios u ON u.id = ch.responsavel_id
      WHERE ${cond.join(' AND ')} ORDER BY FIELD(ch.status, 'aguardando_equipe', 'aberto', 'aguardando_cliente', 'fechado'), ch.atualizado_em DESC LIMIT 12`, p);
  return l.map((c) => ({ ...c, status: ROTULO[c.status] ?? c.status, ...(u.tipo === 'cliente' ? { responsavel: undefined } : {}) }));
}

async function conversa(id: number, u: UsuarioLogado) {
  const r = await consulta<{ autor: string; tipo: string; mensagem: string; em: string }>(
    `SELECT r.autor_nome autor, r.autor_tipo tipo, r.mensagem, r.criado_em em FROM chamado_respostas r WHERE r.chamado_id = ? ORDER BY r.id DESC LIMIT 6`, [id]);
  return r.reverse().map((m) => ({ de: m.tipo === 'equipe' ? (u.tipo === 'cliente' ? 'Equipe PrimeERP' : m.autor) : m.autor, quando: m.em, texto: curto(m.mensagem, 600) }));
}

const FERRAMENTAS_EQUIPE: Ferramenta[] = [
  {
    declaracao: { name: 'resumo_operacao', description: 'Resumo da operação de atendimento agora: pendências por situação, urgentes, chamados esperando há mais tempo, carga por responsável e movimento de hoje.' },
    executar: async (u) => {
      const e = await filtroEscopoEquipe(u);
      const [porStatus, antigos, porResp, hoje] = await Promise.all([
        consulta<{ status: string; n: number }>(`SELECT ch.status, COUNT(*) n FROM chamados ch WHERE ${e.sql} GROUP BY ch.status`, e.params),
        consulta<{ id: number; titulo: string; cliente: string; prioridade: string; desde: string }>(
          `SELECT ch.id, ch.titulo, c.nome cliente, ch.prioridade, ch.atualizado_em desde FROM chamados ch JOIN clientes c ON c.id = ch.cliente_id
            WHERE ${e.sql} AND ch.status IN ('aberto','aguardando_equipe') ORDER BY FIELD(ch.prioridade,'urgente','alta','media','baixa'), ch.atualizado_em LIMIT 8`, e.params),
        consulta<{ responsavel: string | null; n: number }>(
          `SELECT u.nome responsavel, COUNT(*) n FROM chamados ch LEFT JOIN usuarios u ON u.id = ch.responsavel_id WHERE ${e.sql} AND ch.status IN ('aberto','aguardando_equipe') GROUP BY u.nome ORDER BY n DESC`, e.params),
        primeira<{ abertos: number; fechados: number; respostas: number }>(
          `SELECT (SELECT COUNT(*) FROM chamados ch WHERE ${e.sql} AND DATE(ch.criado_em) = CURDATE()) abertos,
                  (SELECT COUNT(*) FROM chamados ch WHERE ${e.sql} AND DATE(ch.fechado_em) = CURDATE()) fechados,
                  (SELECT COUNT(*) FROM chamado_respostas r WHERE r.autor_tipo = 'equipe' AND DATE(r.criado_em) = CURDATE()) respostas`, [...e.params, ...e.params]),
      ]);
      return {
        porSituacao: Object.fromEntries(porStatus.map((s) => [ROTULO[s.status] ?? s.status, Number(s.n)])),
        maisUrgentesEsperandoNos: antigos, cargaPorResponsavel: porResp.map((r) => ({ responsavel: r.responsavel ?? 'Sem responsável', pendentes: Number(r.n) })), hoje,
      };
    },
  },
  {
    declaracao: {
      name: 'buscar_chamados', description: 'Busca chamados por texto (número, título, descrição ou cliente) e/ou situação.',
      parameters: { type: 'OBJECT', properties: { texto: { type: 'STRING', description: 'Termo de busca, opcional' }, status: { type: 'STRING', description: 'Situação', enum: ['pendentes', 'aberto', 'aguardando_equipe', 'aguardando_cliente', 'fechado'] } } },
    },
    executar: (u, a) => buscarChamados(u, a),
  },
  {
    declaracao: { name: 'detalhes_chamado', description: 'Dados e as últimas mensagens de um chamado, pelo número.', parameters: { type: 'OBJECT', properties: { chamado_id: { type: 'INTEGER', description: 'Número do chamado' } }, required: ['chamado_id'] } },
    executar: async (u, a) => {
      const c = await chamadoVisivel(u, num(a, 'chamado_id'));
      if (!c) return { erro: 'Chamado não encontrado ou fora do acesso.' };
      return { ...c, status: ROTULO[c.status] ?? c.status, descricao: curto(c.descricao, 800), ultimasMensagens: await conversa(c.id, u) };
    },
  },
  {
    declaracao: { name: 'listar_clientes', description: 'Lista clientes (id e nome) para achar o cliente certo antes de abrir chamado.', parameters: { type: 'OBJECT', properties: { texto: { type: 'STRING', description: 'Parte do nome' } } } },
    executar: async (_u, a) => consulta<{ id: number; nome: string; status: string }>("SELECT id, nome, status FROM clientes WHERE nome LIKE ? ORDER BY nome LIMIT 15", [`%${txt(a, 'texto')}%`]),
  },
  {
    declaracao: { name: 'listar_equipe', description: 'Lista as pessoas ativas da equipe (id e nome), para atribuir responsável.' },
    executar: async () => consulta<{ id: number; nome: string }>('SELECT id, nome FROM usuarios WHERE ativo = 1 ORDER BY nome'),
  },
  {
    declaracao: {
      name: 'propor_abrir_chamado', description: 'Prepara a abertura de um chamado para um cliente (a pessoa confirma num cartão antes de criar).',
      parameters: { type: 'OBJECT', properties: {
        cliente_id: { type: 'INTEGER', description: 'id do cliente (use listar_clientes)' }, titulo: { type: 'STRING', description: 'Título curto' },
        descricao: { type: 'STRING', description: 'Descrição completa' }, categoria: { type: 'STRING', description: 'Categoria', enum: ['Geral', 'Compras', 'Estoque/Custos/PCP', 'Faturamento', 'Financeiro', 'Gestão de Pessoal', 'Ponto Eletrônico', 'Fiscal', 'Contabilidade', 'Desenvolvimento'] },
        prioridade: { type: 'STRING', description: 'Prioridade', enum: ['baixa', 'media', 'alta', 'urgente'] },
      }, required: ['cliente_id', 'titulo', 'descricao', 'categoria'] },
    },
    executar: async (_u, a, acoes) => {
      const c = await primeira<{ nome: string }>('SELECT nome FROM clientes WHERE id = ?', [num(a, 'cliente_id')]);
      if (!c) return { erro: 'Cliente não encontrado. Use listar_clientes.' };
      acoes.push({ id: `abrir-${Date.now()}`, titulo: `Abrir chamado para ${c.nome}`, detalhe: `${txt(a, 'titulo').toUpperCase()} · ${txt(a, 'categoria')} · prioridade ${txt(a, 'prioridade') || 'media'}`, arquivo: 'api_acoes.php',
        campos: { acao: 'abrir_chamado', cliente_id: String(num(a, 'cliente_id')), titulo: txt(a, 'titulo'), descricao: txt(a, 'descricao'), categoria: txt(a, 'categoria') || 'Geral', prioridade: txt(a, 'prioridade') || 'media', setor_id: '', responsavel_id: '' } });
      return { ok: 'Cartão de confirmação mostrado à pessoa. Avise que é só confirmar.' };
    },
  },
  {
    declaracao: {
      name: 'propor_mudar_situacao', description: 'Prepara encerrar, reabrir ou mudar a situação de um chamado, com mensagem opcional ao cliente (a pessoa confirma antes).',
      parameters: { type: 'OBJECT', properties: {
        chamado_id: { type: 'INTEGER', description: 'Número do chamado' }, situacao: { type: 'STRING', description: 'Nova situação', enum: ['fechado', 'aguardando_cliente', 'aguardando_equipe'] },
        mensagem_ao_cliente: { type: 'STRING', description: 'Mensagem enviada ao cliente junto (opcional; vazio = muda só a situação, sem avisar)' },
      }, required: ['chamado_id', 'situacao'] },
    },
    executar: async (u, a, acoes) => {
      const c = await chamadoVisivel(u, num(a, 'chamado_id'));
      if (!c) return { erro: 'Chamado não encontrado ou fora do acesso.' };
      const sit = txt(a, 'situacao');
      const msg = txt(a, 'mensagem_ao_cliente');
      acoes.push({ id: `sit-${c.id}-${Date.now()}`, titulo: `${sit === 'fechado' ? 'Encerrar' : 'Mudar para ' + (ROTULO[sit] ?? sit)} o chamado #${c.id}`, detalhe: `${c.titulo} · ${c.cliente}${msg ? ` · com mensagem: "${curto(msg, 120)}"` : ''}`,
        arquivo: 'api_acoes.php', perigo: sit === 'fechado', campos: { acao: 'responder_chamado', chamado_id: String(c.id), mensagem: msg, status_apos_resposta: sit, notificar_cliente: msg ? '1' : '0' } });
      return { ok: 'Cartão de confirmação mostrado à pessoa.' };
    },
  },
  {
    declaracao: {
      name: 'propor_trocar_responsavel', description: 'Prepara a troca do responsável de um chamado (a pessoa confirma antes).',
      parameters: { type: 'OBJECT', properties: { chamado_id: { type: 'INTEGER', description: 'Número do chamado' }, usuario_id: { type: 'INTEGER', description: 'id da pessoa da equipe (use listar_equipe)' } }, required: ['chamado_id', 'usuario_id'] },
    },
    executar: async (u, a, acoes) => {
      const c = await chamadoVisivel(u, num(a, 'chamado_id'));
      const p = await primeira<{ nome: string }>('SELECT nome FROM usuarios WHERE id = ? AND ativo = 1', [num(a, 'usuario_id')]);
      if (!c || !p) return { erro: 'Chamado ou pessoa não encontrados.' };
      acoes.push({ id: `resp-${c.id}-${Date.now()}`, titulo: `Passar o chamado #${c.id} para ${p.nome}`, detalhe: `${c.titulo} · ${c.cliente}`, arquivo: 'api_acoes.php',
        campos: { acao: 'atribuir_responsavel', chamado_id: String(c.id), responsavel_id: String(num(a, 'usuario_id')) } });
      return { ok: 'Cartão de confirmação mostrado à pessoa.' };
    },
  },
  {
    declaracao: {
      name: 'gerar_patch',
      description: 'SÓ PARA ADMINISTRADORES. Compila um fonte ADVPL/TLPP em um servidor cadastrado (os mesmos do VS Code, no painel Protheus) e gera um patch .ptm pronto para aplicar. Pode antes converter um relatório padrão em User Function no padrão PrimeERP. Devolve um link para baixar o .ptm. Use quando pedirem para compilar, gerar patch, ou converter um relatório em user function.',
      parameters: { type: 'OBJECT', properties: {
        servidor: { type: 'STRING', description: 'nome ou identificador do servidor cadastrado (ex.: "base", "DS - TESTE - EXCLUSIVO", "Werk Schott")' },
        nome_programa: { type: 'STRING', description: 'nome do programa/fonte, ex.: MATR435' },
        fonte: { type: 'STRING', description: 'conteúdo completo do fonte ADVPL/TLPP a compilar' },
        converter_relatorio: { type: 'BOOLEAN', description: 'true para converter o relatório em User Function (1ª letra vira X, chamada U_) antes de compilar' },
      }, required: ['servidor', 'fonte'] },
    },
    executar: async (u, a) => {
      if (!podeCompilacao(u)) return { erro: 'Gerar patch é só para administradores (admin/diretor/CEO).' };
      const alvos = await listarAlvos(u);
      const termo = txt(a, 'servidor').toLowerCase();
      const alvo = alvos.find((x) => x.slug === termo || x.nome.toLowerCase().includes(termo) || `${x.grupo} ${x.nome}`.toLowerCase().includes(termo));
      if (!alvo) return { erro: `Servidor "${txt(a, 'servidor')}" não encontrado. Disponíveis: ${alvos.map((x) => x.nome).join(', ')}` };
      let fonte = typeof a.fonte === 'string' ? a.fonte : '';
      if (!fonte.trim()) return { erro: 'Preciso do conteúdo do fonte para compilar.' };
      const base = (txt(a, 'nome_programa') || 'FONTE').replace(/\.(prw|tlpp|prx)$/i, '');
      let nomeArquivo = `${base || 'FONTE'}.prw`;
      let resumoConversao = '';
      if (a.converter_relatorio) { const c = converterRelatorio(fonte, base || 'FONTE', u.nome); fonte = c.fonte; nomeArquivo = c.nomeArquivo; resumoConversao = `Convertido para ${c.principal} (chamada ${c.funcoes[0]?.chamada ?? ''}).`; }
      const r = await compilarFonte(u, alvo.slug, [{ nome: nomeArquivo, conteudo_base64: Buffer.from(fonte, 'utf8').toString('base64') }]);
      if (!r.ok) return { ok: false, servidor: alvo.nome, erro: r.erro, dica: 'confira ambiente/senha do servidor e se o fonte compila' };
      const tokenFonte = await guardarArtefato(nomeArquivo, Buffer.from(fonte, 'utf8'));
      return { ok: true, servidor: alvo.nome, conversao: resumoConversao || undefined, nome_patch: r.nome, nome_fonte: nomeArquivo, baixar_patch: `/api/compilacao/arquivo/${r.token}`, baixar_fonte: `/api/compilacao/arquivo/${tokenFonte}`, instrucao_resposta: 'Diga o que mudou e entregue OS DOIS links: o do fonte (.prw) e o do patch (.ptm), dizendo em qual servidor compilou.' };
    },
  },
  {
    declaracao: {
      name: 'resetar_senha',
      description: 'SÓ PARA ADMINISTRADORES. Reseta a senha de um usuário do Protheus num servidor cadastrado, sem tela e sem precisar de login (útil quando o admin perdeu a senha). A senha nova precisa de caractere especial (política do Protheus). Use quando pedirem para resetar/reiniciar a senha de um usuário do Protheus.',
      parameters: { type: 'OBJECT', properties: {
        servidor: { type: 'STRING', description: 'nome ou identificador do servidor cadastrado (ex.: "base", "3LIFE")' },
        login: { type: 'STRING', description: 'login do usuário Protheus a resetar, ex.: admin ou Administrador' },
        senha: { type: 'STRING', description: 'nova senha (precisa de caractere especial, ex.: Prime@2026)' },
      }, required: ['servidor', 'login'] },
    },
    executar: async (u, a) => {
      if (!podePainelVm(u)) return { erro: 'Resetar senha é só para administradores (admin/diretor/CEO).' };
      const alvos = await listarAlvos(u);
      const termo = txt(a, 'servidor').toLowerCase();
      const alvo = alvos.find((x) => x.slug === termo || x.nome.toLowerCase().includes(termo) || `${x.grupo} ${x.nome}`.toLowerCase().includes(termo));
      if (!alvo) return { erro: `Servidor "${txt(a, 'servidor')}" não encontrado. Disponíveis: ${alvos.map((x) => x.nome).join(', ')}` };
      const login = txt(a, 'login');
      if (!login) return { erro: 'Informe o login do usuário a resetar.' };
      const senha = txt(a, 'senha') || 'Prime@2026';
      if (!/[^A-Za-z0-9]/.test(senha)) return { erro: 'A nova senha precisa de pelo menos um caractere especial (política do Protheus). Ex.: Prime@2026.' };
      const restBase = `http://${alvo.host}:8095`;
      const r = await resetarSenhaProtheus(u.nome, login, senha, restBase);
      if (!r.ok) return { ok: false, servidor: alvo.nome, erro: r.resposta || r.erro || 'não deu para resetar', dica: 'confira se o REST (porta 8095) do ambiente está ligado e se o U_PRMRST está compilado lá' };
      return { ok: true, servidor: alvo.nome, login, senha, instrucao_resposta: `Confirme que a senha do usuário ${login} foi resetada em ${alvo.nome} e diga a nova senha (${senha}).` };
    },
  },
];

const FERRAMENTAS_CLIENTE: Ferramenta[] = [
  {
    declaracao: {
      name: 'meus_chamados', description: 'Chamados da empresa da pessoa (só os que ela pode ver), com situação.',
      parameters: { type: 'OBJECT', properties: { status: { type: 'STRING', description: 'Filtro', enum: ['pendentes', 'aberto', 'aguardando_equipe', 'aguardando_cliente', 'fechado'] }, texto: { type: 'STRING', description: 'Busca opcional' } } },
    },
    executar: (u, a) => buscarChamados(u, a),
  },
  {
    declaracao: { name: 'detalhes_chamado', description: 'Situação e últimas mensagens de um chamado da empresa da pessoa.', parameters: { type: 'OBJECT', properties: { chamado_id: { type: 'INTEGER', description: 'Número do chamado' } }, required: ['chamado_id'] } },
    executar: async (u, a) => {
      const c = await chamadoVisivel(u, num(a, 'chamado_id'));
      if (!c) return { erro: 'Esse chamado não aparece para esta pessoa.' };
      return { id: c.id, titulo: c.titulo, situacao: ROTULO[c.status] ?? c.status, prioridade: c.prioridade, aberto: c.criadoEm, setor: c.setor, ultimasMensagens: await conversa(c.id, u) };
    },
  },
  {
    declaracao: {
      name: 'propor_abrir_chamado', description: 'Prepara a abertura de um chamado para a pessoa (ela confirma num cartão). Use quando o problema precisar de análise da equipe.',
      parameters: { type: 'OBJECT', properties: {
        titulo: { type: 'STRING', description: 'Título curto' }, descricao: { type: 'STRING', description: 'Descrição completa com rotina, mensagem de erro e passos' },
        categoria: { type: 'STRING', description: 'Categoria', enum: ['Geral', 'Compras', 'Estoque/Custos/PCP', 'Faturamento', 'Financeiro', 'Gestão de Pessoal', 'Ponto Eletrônico', 'Fiscal', 'Contabilidade', 'Desenvolvimento'] },
        prioridade: { type: 'STRING', description: 'Prioridade', enum: ['baixa', 'media', 'alta', 'urgente'] },
      }, required: ['titulo', 'descricao', 'categoria'] },
    },
    executar: async (_u, a, acoes) => {
      acoes.push({ id: `abrir-${Date.now()}`, titulo: 'Abrir este chamado', detalhe: `${txt(a, 'titulo').toUpperCase()} · ${txt(a, 'categoria')}`, arquivo: 'api_acoes.php',
        campos: { acao: 'abrir_chamado', titulo: txt(a, 'titulo'), descricao: txt(a, 'descricao'), categoria: txt(a, 'categoria') || 'Geral', prioridade: txt(a, 'prioridade') || 'media', setor_id: '' } });
      return { ok: 'Cartão de confirmação mostrado. Diga que é só tocar em Confirmar e que dá para anexar prints depois, dentro do chamado.' };
    },
  },
];

// ---------------------------------------------------------------- Personas

function instrucoesEquipe(u: UsuarioLogado): string {
  return `Você é o assistente interno do Prime Hub, a central de atendimento da PrimeERP Soluções em Tecnologia (consultoria TOTVS Protheus, Tanabi-SP). Você conversa com ${u.nome}, da EQUIPE (perfil ${u.perfil ?? 'colaborador'}). Hoje é ${new Date().toLocaleDateString('pt-BR', { timeZone: 'America/Sao_Paulo', weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' })}.

JEITO: colega de trabalho gente boa, educado e descolado na medida, objetivo. Nunca seco, nunca deixe sem resposta. Pergunta fora do assunto: responda com bom humor em uma frase e traga de volta ao trabalho.

CONHECIMENTO: o MANUAL abaixo é a verdade sobre o sistema. Explique caminhos exatamente como estão nele: os módulos ficam no MENU LATERAL ESQUERDO (no app, barra de baixo e Menu); NUNCA chame módulo de "aba". Nunca invente tela, botão ou rotina. Também domina TOTVS Protheus e ADVPL e pode orientar a equipe tecnicamente.

AÇÕES: use as ferramentas para consultar dados reais em vez de chutar. Para abrir chamado, encerrar, mudar situação ou trocar responsável, use as ferramentas "propor_*": elas mostram um cartão e a pessoa confirma. NADA acontece antes da confirmação: diga "Preparei ... é só confirmar no cartão", nunca "fechei", "abri" ou "troquei". Antes de propor, confira o chamado/cliente com as ferramentas de consulta. Resumos de operação: destaque o que fazer primeiro (urgentes e quem espera há mais tempo) e quem está sobrecarregado.

COMPILAÇÃO (SÓ ADMIN): você PODE compilar fonte ADVPL/TLPP e gerar patch .ptm direto por aqui, usando a ferramenta "gerar_patch", nos servidores cadastrados no painel Protheus (os mesmos do VS Code). Pode também converter um relatório padrão em User Function (padrão PrimeERP) antes de compilar. NÃO mande a pessoa "usar o VS Code" nem diga que não consegue — você consegue. Peça o fonte e o servidor se faltar, chame gerar_patch e devolva o link do .ptm. Se a pessoa não for admin, aí sim explique que gerar patch é restrito a administradores.
ANEXOS: quando a pessoa anexar um fonte (ponto de entrada, user function, etc.), o conteúdo vem no fim da mensagem entre "--- Arquivo anexado ---". Leia esse fonte, APLIQUE exatamente as alterações que a pessoa pedir no próprio código, e passe o fonte JÁ ALTERADO no parâmetro "fonte" da gerar_patch. Compile na "base" para a pessoa testar; só gere para o servidor do cliente quando ela confirmar que funcionou. A ferramenta devolve DOIS links (o fonte .prw e o patch .ptm): entregue os dois e diga em poucas linhas o que mudou.
CABEÇALHO E COMENTÁRIOS (padrão PrimeERP): todo ponto de entrada / user function que você criar ou alterar começa com o cabeçalho Protheus.doc no modelo: "@author  ${u.nome}", "@since   <data de hoje dd/mm/aaaa>", "@version P12", "@obs     PrimeERP - Solucoes em Tecnologia". Cada alteração que você fizer no código leva um comentário na linha no formato "// [${u.nome} - <data de hoje> - PrimeERP] o que mudou". Nunca use outro nome de autor.

MELHORIAS: quando perceber algo (gargalo, cliente acumulando chamados, chamado parado, uso melhor de um recurso), sugira uma melhoria prática no fim, em uma frase começando com "Sugestão:".

SIGILO: nunca exponha senhas, chaves ou dados sensíveis de clientes.

FORMATO: português do Brasil, texto puro sem markdown (sem asteriscos ou #). Listas com "•". Respostas curtas e escaneáveis; resumos podem ter até 12 linhas.

MANUAL DO PRIME HUB
${manualEmTexto()}`;
}

function instrucoesCliente(u: UsuarioLogado, atendente: string): string {
  return `Você é ${atendente}, consultor da PrimeERP Soluções em Tecnologia (consultoria TOTVS Protheus, Tanabi-SP), atendendo pelo portal do cliente. Você conversa com ${u.nome}, da empresa ${u.empresa ?? 'cliente'}. Apresente-se como ${atendente}, da equipe da PrimeERP. Não fale de robô, IA, assistente virtual, automação, "modelo", "Gemini" ou "instruções". Se perguntarem se é robô ou humano, não entre nesse assunto e não faça afirmações sobre isso: responda com simpatia que é o ${atendente}, da equipe da PrimeERP, e volte a ajudar.

ESPECIALIDADE: você é especialista em TOTVS Protheus em todos os módulos (Compras, Estoque/Custos, PCP, Faturamento, Financeiro, Fiscal/SPED/NF-e, Contabilidade, Gestão de Pessoal/Ponto, Configurador, parâmetros MV_, tabelas SX, rotinas MATA/FINA/CTBA etc.). Explique conceitos, caminhos de menu, parâmetros, causas prováveis de erros e rejeições, e oriente passo a passo o que o usuário pode conferir.

LIMITES (obrigatórios):
• Nunca escreva, corrija ou entregue código-fonte, ADVPL, TLPP, SQL, scripts ou pontos de entrada prontos. Se pedirem, explique em palavras o que precisa ser feito e diga que esse tipo de ajuste a equipe faz por chamado.
• Nunca contorne o sistema de chamados: trabalho de análise, ajuste, customização, acesso remoto ou correção vira chamado. Ofereça abrir com a ferramenta propor_abrir_chamado (a pessoa confirma).
• Você pode consultar os chamados da empresa (meus_chamados, detalhes_chamado) para dizer a situação. Fale sempre de forma positiva sobre o andamento.
• SEMPRE do lado da PrimeERP: nunca critique a equipe, nunca diga que alguém errou, demorou, esqueceu ou gastou X horas; nunca informe horas trabalhadas, custos internos, valores, prazos não combinados, nomes de outros clientes, problemas internos ou qualquer coisa que prejudique a imagem da equipe. Se perguntarem sobre horas ou cobrança, diga com educação que esses detalhes são tratados diretamente com a gestão da PrimeERP.
• Não prometa prazos nem soluções garantidas; diga que a equipe está acompanhando.
• Nunca invente nomes de pontos de entrada, parâmetros MV_, tabelas, campos ou rotinas: cite só os que você tem certeza que existem; na dúvida, descreva sem dar o nome.
• Não peça senhas. Assunto fora de Protheus/atendimento: responda com simpatia em uma frase e volte ao que pode ajudar.

JEITO: educado, próximo e confiante, como um consultor sênior experiente; pode ser descontraído na medida. Português do Brasil, texto puro sem markdown, curto (até 8 linhas; passos com "•").`;
}

// ---------------------------------------------------------------- Conversa

async function chamarGemini(chave: string, modelo: string, sistema: string, contents: Conteudo[], ferramentas: Ferramenta[]) {
  const r = await fetch(`https://generativelanguage.googleapis.com/v1beta/models/${modelo}:generateContent`, {
    method: 'POST',
    headers: { 'content-type': 'application/json', 'x-goog-api-key': chave },
    body: JSON.stringify({
      systemInstruction: { parts: [{ text: sistema }] },
      contents,
      tools: [{ functionDeclarations: ferramentas.map((f) => f.declaracao) }],
      generationConfig: { maxOutputTokens: 4096, temperature: 0.6, ...(modelo.includes('lite') ? {} : { thinkingConfig: { thinkingBudget: 1024 } }) },
    }),
    signal: AbortSignal.timeout(modelo.includes('lite') ? 25_000 : 20_000),
  });
  if (!r.ok) throw new Error(`HTTP ${r.status} ${(await r.text().catch(() => '')).replace(/\s+/g, ' ').slice(0, 200)}`);
  const d = (await r.json()) as { candidates?: { content?: { parts?: Parte[] } }[] };
  return d.candidates?.[0]?.content?.parts ?? [];
}

export interface TelaAtual { rota: string; titulo: string; texto: string }

/** O que a pessoa está vendo agora, pra IA ajudar naquela tela específica. */
function blocoTela(tela: TelaAtual | undefined): string {
  if (!tela) return '';
  return `

TELA QUE A PESSOA ESTÁ VENDO AGORA (use para entender "isso", "aqui", "esse chamado", "essa tela"; é só leitura, nunca invente o que não está nela):
Endereço: ${tela.rota}
Título: ${tela.titulo}
Conteúdo visível:
${tela.texto.slice(0, 6000)}`;
}

export interface AnexoChat { nome: string; conteudo: string }
export async function perguntar(usuario: UsuarioLogado, historico: MensagemChat[], mensagem: string, tela?: TelaAtual, anexos?: AnexoChat[]): Promise<RespostaAssistente | { erro: string }> {
  const dia = new Date().toISOString().slice(0, 10);
  const chaveUso = `${usuario.tipo}:${usuario.id}`;
  const uso = usoHoje.get(chaveUso);
  const n = uso?.dia === dia ? uso.n : 0;
  if (n >= LIMITE_DIA[usuario.tipo]) return { erro: 'Hoje já conversamos bastante! Amanhã eu volto. Se for urgente, abra um chamado.' };
  usoHoje.set(chaveUso, { dia, n: n + 1 });

  const chave = (await config('gemini_api_key')) || process.env.GEMINI_API_KEY || '';
  const primeiro = usuario.nome.split(' ')[0];
  if (!chave) return { texto: `Oi, ${primeiro}! Agora não consigo consultar isso, mas a equipe está à disposição pelos chamados.` };

  const equipe = usuario.tipo === 'equipe';
  const ferramentas = equipe ? FERRAMENTAS_EQUIPE : FERRAMENTAS_CLIENTE;
  const sistema = (equipe ? instrucoesEquipe(usuario) : instrucoesCliente(usuario, await nomeAtendente())) + blocoTela(tela);

  for (const [tentativa, modelo] of MODELOS.entries()) {
    if (tentativa > 0) await new Promise((r) => setTimeout(r, 400 * tentativa));
    const acoes: AcaoProposta[] = [];
    const contents: Conteudo[] = historico.slice(-10).map((m) => ({ role: m.autor === 'pessoa' ? 'user' as const : 'model' as const, parts: [{ text: m.texto.slice(0, 3000) }] }));
    const anexosTexto = (anexos ?? []).map((a) => `\n\n--- Arquivo anexado: ${a.nome} ---\n${a.conteudo.slice(0, 80000)}\n--- fim do arquivo ---`).join('');
    contents.push({ role: 'user' as const, parts: [{ text: (mensagem.slice(0, 3000) + anexosTexto).slice(0, 170000) }] });
    try {
      // Até 5 rodadas de ferramenta (consultar, conferir, propor) antes de responder.
      for (let rodada = 0; rodada < 6; rodada++) {
        const partes = await chamarGemini(chave, modelo, sistema, contents, ferramentas);
        const chamadas = partes.filter((p) => p.functionCall);
        if (!chamadas.length) {
          const texto = partes.filter((p) => !p.thought && p.text).map((p) => p.text).join('').replace(/\*\*/g, '').replace(/^#+\s*/gm, '').trim();
          if (texto || acoes.length) return { texto: texto || 'Preparei aqui, é só confirmar no cartão.', ...(acoes.length ? { acoes } : {}) };
          throw new Error('sem texto');
        }
        contents.push({ role: 'model', parts: partes });
        const respostas: Parte[] = [];
        for (const p of chamadas) {
          const f = ferramentas.find((x) => x.declaracao.name === p.functionCall?.name);
          let resultado: unknown;
          try { resultado = f ? await f.executar(usuario, p.functionCall?.args ?? {}, acoes) : { erro: 'ferramenta desconhecida' }; } catch (e) { resultado = { erro: e instanceof Error ? e.message : 'falhou' }; }
          respostas.push({ functionResponse: { name: p.functionCall?.name, response: { resultado } } });
        }
        contents.push({ role: 'user', parts: respostas });
      }
      throw new Error('rodadas demais');
    } catch (e) {
      console.error(`assistente: ${modelo} falhou:`, e instanceof Error ? e.message : e);
    }
  }
  return { texto: `Opa, ${primeiro}! Me enrolei aqui por um instante. Pode mandar de novo? Se for urgente, é só abrir um chamado.` };
}
