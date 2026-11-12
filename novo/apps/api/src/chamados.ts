import type { ChamadoResumo, ListaChamados, StatusChamado, UsuarioLogado } from '@primehub/shared';
import { filtroEscopoCliente, filtroEscopoEquipe } from './auth';
import { SQL_CHAMADO_RESUMO } from './dashboard';
import { consulta, primeira } from './db';
import { ultimosAutores } from './pessoas';

const STATUS: StatusChamado[] = ['aberto', 'aguardando_equipe', 'aguardando_cliente', 'fechado'];

export async function listarChamados(usuario: UsuarioLogado, filtros: { status?: string; busca?: string; pagina?: number; clienteId?: number; setorId?: number }): Promise<ListaChamados> {
  const escopo = usuario.tipo === 'equipe' ? await filtroEscopoEquipe(usuario) : filtroEscopoCliente(usuario);
  const condicoes = [escopo.sql];
  const params: unknown[] = [...escopo.params];

  if (filtros.status === 'pendentes') {
    condicoes.push("ch.status <> 'fechado'");
  } else if (filtros.status && (STATUS as string[]).includes(filtros.status)) {
    condicoes.push('ch.status = ?');
    params.push(filtros.status);
  }
  if (filtros.clienteId && usuario.tipo === 'equipe') {
    condicoes.push('ch.cliente_id = ?');
    params.push(filtros.clienteId);
  }
  if (filtros.setorId && usuario.tipo === 'equipe') {
    condicoes.push('ch.setor_id = ?');
    params.push(filtros.setorId);
  }
  if (filtros.busca?.trim()) {
    const termo = `%${filtros.busca.trim()}%`;
    condicoes.push('(ch.titulo LIKE ? OR ch.descricao LIKE ? OR c.nome LIKE ? OR ch.id = ?)');
    params.push(termo, termo, termo, Number(filtros.busca) || 0);
  }

  const onde = `WHERE ${condicoes.join(' AND ')}`;
  const porPagina = 30;
  const pagina = Math.max(1, filtros.pagina ?? 1);
  const total = Number((await primeira<{ n: number }>(`SELECT COUNT(*) n FROM chamados ch LEFT JOIN clientes c ON c.id = ch.cliente_id ${onde}`, params))?.n ?? 0);
  const itens = (await consulta<ChamadoResumo>(
    `${SQL_CHAMADO_RESUMO} ${onde} ORDER BY FIELD(ch.status, 'aguardando_equipe', 'aberto', 'aguardando_cliente', 'fechado'), ch.atualizado_em DESC LIMIT ${porPagina} OFFSET ${(pagina - 1) * porPagina}`,
    params,
  )).map((r) => ({ ...r, horasEsperando: r.horasEsperando === null ? null : Number(r.horasEsperando) }));
  const autores = await ultimosAutores(itens.map((c) => c.id));
  for (const c of itens) c.ultimo = autores.get(c.id) ?? null;

  return { itens, total };
}
