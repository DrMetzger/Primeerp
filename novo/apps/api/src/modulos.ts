import type { Agenda, EquipePortal, EventoCliente, FonteArquivo, Perfil, TarefaAgenda, UsuarioEquipe, UsuarioLogado } from '@primehub/shared';
import { consulta, primeira } from './db';

const DATA = /^\d{4}-\d{2}-\d{2}$/;

/** Agenda de trabalho da gestão + reuniões/treinamentos com clientes. */
export async function montarAgenda(de: string, ate: string): Promise<Agenda> {
  const hoje = new Date();
  const inicio = DATA.test(de) ? de : new Date(hoje.getTime() - 7 * 864e5).toISOString().slice(0, 10);
  const fim = DATA.test(ate) ? ate : new Date(hoje.getTime() + 30 * 864e5).toISOString().slice(0, 10);
  const [tarefas, eventos, clientes, equipe] = await Promise.all([
    consulta<TarefaAgenda>(
      `SELECT a.id, a.titulo, DATE_FORMAT(a.data, '%Y-%m-%d') data, DATE_FORMAT(a.data_fim, '%Y-%m-%d') dataFim, TIME_FORMAT(a.hora_inicio, '%H:%i') horaInicio,
              a.duracao_minutos duracaoMinutos, a.status, a.notas, a.cliente_id clienteId, c.nome cliente, c.logo clienteLogo,
              a.chamado_id chamadoId, ch.titulo chamado, a.responsavel_id responsavelId, u.nome responsavel
         FROM agenda_tarefas a LEFT JOIN clientes c ON c.id = a.cliente_id LEFT JOIN chamados ch ON ch.id = a.chamado_id LEFT JOIN usuarios u ON u.id = a.responsavel_id
        WHERE (a.data BETWEEN ? AND ?) OR (a.data_fim IS NOT NULL AND a.data <= ? AND a.data_fim >= ?)
        ORDER BY a.data, a.hora_inicio IS NULL, a.hora_inicio, a.ordem`, [inicio, fim, fim, inicio]),
    consulta<EventoCliente>(
      `SELECT t.id, t.titulo, t.tipo, t.status, CONCAT(t.data_inicio, ' ', COALESCE(t.hora_inicio, '00:00:00')) inicio,
              CASE WHEN t.data_fim IS NULL THEN NULL ELSE CONCAT(t.data_fim, ' ', COALESCE(t.hora_fim, '00:00:00')) END fim,
              t.link_reuniao link, c.nome cliente, c.logo clienteLogo, s.nome setor
         FROM treinamentos t JOIN clientes c ON c.id = t.cliente_id LEFT JOIN setores s ON s.id = t.setor_id
        WHERE t.data_inicio BETWEEN ? AND ? ORDER BY t.data_inicio, t.hora_inicio`, [inicio, fim]),
    consulta<{ id: number; nome: string }>("SELECT id, nome FROM clientes WHERE status <> 'inativo' ORDER BY nome"),
    consulta<{ id: number; nome: string }>('SELECT id, nome FROM usuarios WHERE ativo = 1 ORDER BY nome'),
  ]);
  return { tarefas: tarefas.map((t) => ({ ...t, duracaoMinutos: t.duracaoMinutos === null ? null : Number(t.duracaoMinutos) })), eventos, clientes, equipe };
}

/** Biblioteca de fontes da Prime (fontes_globais). */
export async function listarFontesGlobais(): Promise<FonteArquivo[]> {
  const l = await consulta<FonteArquivo>(
    `SELECT f.id, f.nome titulo, f.categoria, f.descricao, f.arquivo_nome_original arquivo, f.arquivo_tamanho tamanho, NULL setor, NULL setorId, f.criado_em criadoEm, u.nome autor
       FROM fontes_globais f LEFT JOIN usuarios u ON u.id = f.criado_por ORDER BY f.categoria, f.nome`);
  return l.map((f) => ({ ...f, tamanho: Number(f.tamanho ?? 0) }));
}

export async function listarUsuarios(): Promise<UsuarioEquipe[]> {
  const l = await consulta<UsuarioEquipe>(
    `SELECT id, nome, email, telefone, documento, perfil, foto_perfil foto, ativo, acesso_restrito_por_cliente restrito, acesso_dados_sensiveis sensiveis, ultimo_login_em ultimoLogin FROM usuarios ORDER BY ativo DESC, nome`);
  return l.map((u) => ({ ...u, ativo: Boolean(u.ativo), restrito: Boolean(u.restrito), sensiveis: Boolean(u.sensiveis) }));
}

export async function lerPerfil(u: UsuarioLogado): Promise<Perfil | null> {
  if (u.tipo === 'equipe') {
    return primeira<Perfil>("SELECT nome, email, telefone, documento, foto_perfil foto, 'equipe' tipo FROM usuarios WHERE id = ?", [u.id]);
  }
  if (u.perfil === 'master') {
    return primeira<Perfil>("SELECT COALESCE(contato_nome, nome) nome, contato_email email, contato_telefone telefone, contato_documento documento, foto_perfil foto, 'cliente' tipo FROM clientes WHERE id = ?", [u.clienteId]);
  }
  return primeira<Perfil>("SELECT nome, email, telefone, documento, foto_perfil foto, 'cliente' tipo FROM setor_responsaveis WHERE id = ?", [u.id]);
}

// ---------------------------------------------------------------- Portal do cliente

/** Master (ou setor com permissão de master) vê a empresa toda; senão, só os setores dele. */
async function escopoPortal(u: UsuarioLogado): Promise<{ master: boolean; setores: number[] }> {
  if (u.perfil === 'master') return { master: true, setores: [] };
  const eh = await primeira<{ m: number }>('SELECT eh_master m FROM setor_responsaveis WHERE id = ?', [u.id]);
  if (eh?.m) return { master: true, setores: [] };
  const s = await consulta<{ id: number }>('SELECT setor_id id FROM setor_responsavel_setores WHERE setor_responsavel_id = ?', [u.id]);
  return { master: false, setores: s.map((x) => x.id) };
}

const emSetores = (coluna: string, setores: number[], nulos = false) =>
  setores.length ? `(${coluna} IN (${setores.map(() => '?').join(',')})${nulos ? ` OR ${coluna} IS NULL` : ''})` : (nulos ? `${coluna} IS NULL` : '1=0');

export async function portalCalendario(u: UsuarioLogado): Promise<EventoCliente[]> {
  const e = await escopoPortal(u);
  const filtro = e.master ? '1=1' : emSetores('t.setor_id', e.setores, true);
  return consulta<EventoCliente>(
    `SELECT t.id, t.titulo, t.tipo, t.status, CONCAT(t.data_inicio, ' ', COALESCE(t.hora_inicio, '00:00:00')) inicio,
            CASE WHEN t.data_fim IS NULL THEN NULL ELSE CONCAT(t.data_fim, ' ', COALESCE(t.hora_fim, '00:00:00')) END fim,
            t.link_reuniao link, NULL cliente, NULL clienteLogo, s.nome setor
       FROM treinamentos t LEFT JOIN setores s ON s.id = t.setor_id
      WHERE t.cliente_id = ? AND ${filtro} ORDER BY t.data_inicio DESC, t.hora_inicio`, [u.clienteId, ...(e.master ? [] : e.setores)]);
}

export async function portalVideoaulas(u: UsuarioLogado): Promise<FonteArquivo[]> {
  const e = await escopoPortal(u);
  const filtro = e.master ? '1=1' : emSetores('d.setor_id', e.setores);
  const l = await consulta<FonteArquivo>(
    `SELECT d.id, d.titulo, d.categoria, d.descricao, d.arquivo_nome_original arquivo, d.arquivo_tamanho tamanho, s.nome setor, d.setor_id setorId, d.criado_em criadoEm, NULL autor
       FROM documentos d LEFT JOIN setores s ON s.id = d.setor_id
      WHERE d.cliente_id = ? AND d.categoria = 'Vídeo aula' AND ${filtro} ORDER BY d.criado_em DESC`, [u.clienteId, ...(e.master ? [] : e.setores)]);
  return l.map((f) => ({ ...f, tamanho: Number(f.tamanho ?? 0) }));
}

export async function portalFontes(u: UsuarioLogado): Promise<{ fontes: FonteArquivo[]; setores: { id: number; nome: string }[] }> {
  const [fontes, setores] = await Promise.all([
    consulta<FonteArquivo & { pontoEntrada?: string }>(
      `SELECT f.id, f.titulo, f.ponto_entrada categoria, f.descricao, f.arquivo_nome_original arquivo, f.arquivo_tamanho tamanho, s.nome setor, f.setor_id setorId, f.criado_em criadoEm, NULL autor
         FROM setor_fontes_advpl f JOIN setores s ON s.id = f.setor_id WHERE s.cliente_id = ? ORDER BY s.nome, f.titulo`, [u.clienteId]),
    consulta<{ id: number; nome: string }>('SELECT id, nome FROM setores WHERE cliente_id = ? ORDER BY nome', [u.clienteId]),
  ]);
  return { fontes: fontes.map((f) => ({ ...f, tamanho: Number(f.tamanho ?? 0) })), setores };
}

export async function portalEquipe(u: UsuarioLogado): Promise<EquipePortal> {
  const [setores, pessoas, vinculos, cli] = await Promise.all([
    consulta<{ id: number; nome: string; ativo: number }>('SELECT id, nome, ativo FROM setores WHERE cliente_id = ? ORDER BY nome', [u.clienteId]),
    consulta<{ id: number; nome: string; email: string | null; telefone: string | null; foto: string | null; master: number; portalAtivo: number }>(
      'SELECT id, nome, email, telefone, foto_perfil foto, eh_master master, portal_ativo portalAtivo FROM setor_responsaveis WHERE cliente_id = ? ORDER BY nome', [u.clienteId]),
    consulta<{ p: number; nome: string }>(
      'SELECT v.setor_responsavel_id p, s.nome FROM setor_responsavel_setores v JOIN setores s ON s.id = v.setor_id WHERE s.cliente_id = ?', [u.clienteId]),
    primeira<{ g: number }>('SELECT portal_permite_gerenciar_equipe g FROM clientes WHERE id = ?', [u.clienteId]),
  ]);
  const e = await escopoPortal(u);
  return {
    setores: setores.map((s) => ({ ...s, ativo: Boolean(s.ativo) })),
    pessoas: pessoas.map((p) => ({ ...p, master: Boolean(p.master), portalAtivo: Boolean(p.portalAtivo), setores: vinculos.filter((v) => v.p === p.id).map((v) => v.nome) })),
    podeGerenciar: e.master && (u.perfil === 'master' || Boolean(cli?.g)),
  };
}
