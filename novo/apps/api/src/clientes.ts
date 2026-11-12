import type { ChamadoDetalhe, ClienteDetalhe, ClienteResumo, UsuarioLogado } from '@primehub/shared';
import { filtroEscopoCliente, filtroEscopoEquipe } from './auth';
import { SQL_CHAMADO_RESUMO } from './dashboard';
import { consulta, primeira } from './db';
import { JOINS_AUTOR_RESPOSTA, registrarLeitura, SQL_AUTOR_RESPOSTA, tipoAnexo } from './pessoas';

const PERFIS_ADMIN = ['admin', 'diretor', 'ceo'];

/** Clientes que a pessoa da equipe pode ver (mesma regra do PHP). */
async function filtroClientes(usuario: UsuarioLogado): Promise<{ sql: string; params: unknown[] }> {
  if (PERFIS_ADMIN.includes(usuario.perfil ?? '')) return { sql: '1=1', params: [] };
  const restrito = await primeira<{ r: number }>('SELECT acesso_restrito_por_cliente r FROM usuarios WHERE id = ?', [usuario.id]);
  if (!restrito?.r) return { sql: '1=1', params: [] };
  return { sql: 'c.id IN (SELECT cliente_id FROM usuario_cliente_setores WHERE usuario_id = ?)', params: [usuario.id] };
}

const SQL_RESUMO = `
  SELECT c.id, c.nome, c.status, c.logo, c.contato_nome contatoNome, c.contato_email contatoEmail,
    (SELECT COUNT(*) FROM chamados ch WHERE ch.cliente_id = c.id AND ch.status IN ('aberto','aguardando_equipe')) pendentes,
    (SELECT COUNT(*) FROM chamados ch WHERE ch.cliente_id = c.id AND ch.status IN ('aberto','aguardando_equipe') AND ch.prioridade IN ('alta','urgente')) urgentes,
    (SELECT COUNT(*) FROM chamados ch WHERE ch.cliente_id = c.id) totalChamados,
    (SELECT COUNT(*) FROM documentos d WHERE d.cliente_id = c.id) documentos,
    (SELECT COUNT(*) FROM kpis k WHERE k.cliente_id = c.id) kpis,
    (SELECT COUNT(*) FROM treinamentos t WHERE t.cliente_id = c.id) treinamentos
  FROM clientes c`;

const numeros = <T extends object>(l: T, campos: readonly string[]): T => {
  const copia = { ...l } as Record<string, unknown>;
  for (const c of campos) copia[c] = Number(copia[c] ?? 0);
  return copia as T;
};
const CAMPOS_NUM: readonly (keyof ClienteResumo)[] = ['pendentes', 'urgentes', 'totalChamados', 'documentos', 'kpis', 'treinamentos'];

export async function listarClientes(usuario: UsuarioLogado, busca?: string): Promise<ClienteResumo[]> {
  const f = await filtroClientes(usuario);
  const params: unknown[] = [...f.params];
  let extra = '';
  if (busca?.trim()) {
    extra = ' AND (c.nome LIKE ? OR c.contato_nome LIKE ? OR c.contato_email LIKE ?)';
    const t = `%${busca.trim()}%`;
    params.push(t, t, t);
  }
  const linhas = await consulta<ClienteResumo>(`${SQL_RESUMO} WHERE ${f.sql}${extra} ORDER BY urgentes DESC, pendentes DESC, c.nome ASC`, params);
  return linhas.map((l) => numeros(l, CAMPOS_NUM));
}

export async function detalharCliente(usuario: UsuarioLogado, id: number): Promise<ClienteDetalhe | null> {
  const f = await filtroClientes(usuario);
  const base = await primeira<ClienteDetalhe['cliente']>(
    `SELECT r.*, c2.cnpj, c2.contato_telefone contatoTelefone, c2.observacoes, u.nome responsavelInterno, c2.responsavel_interno_id responsavelInternoId, c2.contato_documento contatoDocumento, c2.criado_em criadoEm
       FROM (${SQL_RESUMO} WHERE ${f.sql} AND c.id = ?) r
       JOIN clientes c2 ON c2.id = r.id LEFT JOIN usuarios u ON u.id = c2.responsavel_interno_id`, [...f.params, id]);
  if (!base) return null;

  const [setores, pessoas, documentos, treinamentos, equipe] = await Promise.all([
    consulta<ClienteDetalhe['setores'][number]>(
      `SELECT s.id, s.nome, s.ativo, (SELECT COUNT(*) FROM chamados ch WHERE ch.setor_id = s.id AND ch.status IN ('aberto','aguardando_equipe')) pendentes
         FROM setores s WHERE s.cliente_id = ? ORDER BY s.nome`, [id]),
    consulta<ClienteDetalhe['pessoas'][number]>(
      `SELECT sr.id, sr.nome, sr.email, sr.telefone, sr.foto_perfil foto, s.nome setor, sr.portal_ativo portalAtivo
         FROM setor_responsaveis sr LEFT JOIN setores s ON s.id = sr.setor_id WHERE sr.cliente_id = ? ORDER BY sr.nome`, [id]),
    consulta<ClienteDetalhe['documentos'][number]>(
      `SELECT d.id, d.titulo, d.categoria, s.nome setor, d.arquivo_nome_original arquivo, d.arquivo_tamanho tamanho, d.criado_em criadoEm
         FROM documentos d LEFT JOIN setores s ON s.id = d.setor_id WHERE d.cliente_id = ? ORDER BY d.criado_em DESC LIMIT 200`, [id]),
    consulta<ClienteDetalhe['treinamentos'][number]>(
      `SELECT t.id, t.titulo, t.tipo, t.status, s.nome setor,
              CASE WHEN t.data_inicio IS NULL THEN NULL ELSE CONCAT(t.data_inicio, ' ', COALESCE(t.hora_inicio, '00:00:00')) END inicio, t.link_reuniao link
         FROM treinamentos t LEFT JOIN setores s ON s.id = t.setor_id WHERE t.cliente_id = ? ORDER BY t.data_inicio DESC LIMIT 100`, [id]),
    consulta<ClienteDetalhe['equipe'][number]>("SELECT id, nome, foto_perfil foto FROM usuarios WHERE ativo = 1 ORDER BY nome"),
  ]);

  return {
    cliente: numeros(base, CAMPOS_NUM),
    setores: setores.map((s) => ({ ...s, ativo: Boolean(s.ativo), pendentes: Number(s.pendentes) })),
    pessoas: pessoas.map((p) => ({ ...p, portalAtivo: Boolean(p.portalAtivo) })),
    documentos: documentos.map((d) => ({ ...d, tamanho: Number(d.tamanho) })),
    treinamentos,
    equipe,
  };
}

export async function detalharChamado(usuario: UsuarioLogado, id: number): Promise<ChamadoDetalhe | null> {
  const escopo = usuario.tipo === 'equipe' ? await filtroEscopoEquipe(usuario) : filtroEscopoCliente(usuario);
  const chamado = await primeira<ChamadoDetalhe['chamado'] & { aberturaNome: string | null; aberturaFoto: string | null }>(
    `${SQL_CHAMADO_RESUMO.replace('SELECT ch.id,', `SELECT ch.descricao, ch.categoria, ch.responsavel_id responsavelId, ch.origem_canal origem,
        (SELECT nome FROM setores WHERE id = ch.setor_id) setor,
        CASE WHEN ch.aberto_por = 'equipe' THEN 'Equipe PrimeERP' WHEN ch.aberto_por_tipo = 'setor' THEN (SELECT nome FROM setor_responsaveis WHERE id = ch.aberto_por_setor_responsavel_id) ELSE NULLIF(c.contato_nome, '') END aberturaNome,
        CASE WHEN ch.aberto_por = 'equipe' THEN NULL WHEN ch.aberto_por_tipo = 'setor' THEN (SELECT foto_perfil FROM setor_responsaveis WHERE id = ch.aberto_por_setor_responsavel_id) ELSE c.foto_perfil END aberturaFoto,
        ch.id,`)}
     WHERE ${escopo.sql} AND ch.id = ?`, [...escopo.params, id]);
  if (!chamado) return null;

  // Antes de ler as leituras: quem abriu agora também "viu".
  await registrarLeitura(id, usuario);

  const [respostas, anexos, leituras] = await Promise.all([
    consulta<{ id: number; autorTipo: 'cliente' | 'equipe'; autorNome: string; autorFoto: string | null; autorPapel: string | null; mensagem: string; criadoEm: string }>(
      `SELECT r.id, r.autor_tipo autorTipo, r.mensagem, r.criado_em criadoEm, ${SQL_AUTOR_RESPOSTA}
         FROM chamado_respostas r ${JOINS_AUTOR_RESPOSTA} WHERE r.chamado_id = ? ORDER BY r.criado_em, r.id`, [id]),
    consulta<{ id: number; respostaId: number | null; nome: string; tamanho: number }>(
      'SELECT id, resposta_id respostaId, arquivo_nome_original nome, arquivo_tamanho tamanho FROM chamado_anexos WHERE chamado_id = ? ORDER BY id', [id]),
    consulta<{ tipo: string; nome: string | null; em: string }>(
      'SELECT leitor_tipo tipo, leitor_nome nome, lido_em em FROM chamado_leituras WHERE chamado_id = ?', [id]).catch(() => []),
  ]);
  const anexosDe = (respostaId: number | null) => anexos.filter((a) => a.respostaId === respostaId)
    .map(({ id: aid, nome, tamanho }) => ({ id: aid, nome, tamanho: Number(tamanho), tipo: tipoAnexo(nome) }));
  const em = (v: string) => new Date(String(v).replace(' ', 'T')).getTime();
  const vistoPor = (autorTipo: 'cliente' | 'equipe', criadoEm: string) => leituras
    .filter((l) => (autorTipo === 'equipe' ? l.tipo !== 'equipe' : l.tipo === 'equipe') && em(l.em) >= em(criadoEm))
    .map((l) => ({ nome: l.nome ?? (l.tipo === 'equipe' ? 'Equipe' : 'Cliente'), em: l.em }));

  const abertaPelaEquipe = chamado.aberturaNome === 'Equipe PrimeERP';
  const mensagens = [
    {
      id: 0, autorTipo: (abertaPelaEquipe ? 'equipe' : 'cliente') as 'equipe' | 'cliente', autorNome: chamado.aberturaNome ?? chamado.cliente ?? 'Cliente',
      autorFoto: chamado.aberturaFoto, autorPapel: abertaPelaEquipe ? 'Equipe PrimeERP' : chamado.cliente, mensagem: chamado.descricao,
      criadoEm: chamado.criadoEm, anexos: anexosDe(null), vistoPor: vistoPor(abertaPelaEquipe ? 'equipe' : 'cliente', chamado.criadoEm),
    },
    ...respostas.map((r) => ({ ...r, anexos: anexosDe(r.id), vistoPor: vistoPor(r.autorTipo, r.criadoEm) })),
  ];
  const ia = usuario.tipo === 'equipe'
    ? await primeira<NonNullable<ChamadoDetalhe['ia']>>('SELECT status, resposta, fonte, status_sugerido statusSugerido FROM chamado_ia_analises WHERE chamado_id = ?', [id]).catch(() => null)
    : null;
  const { aberturaNome: _n, aberturaFoto: _f, ...resto } = chamado;
  void _n; void _f;
  return { chamado: { ...resto, horasEsperando: resto.horasEsperando === null ? null : Number(resto.horasEsperando) }, mensagens, ia };
}
