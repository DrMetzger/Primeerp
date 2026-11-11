import bcrypt from 'bcryptjs';
import { SignJWT, jwtVerify } from 'jose';
import type { MiddlewareHandler } from 'hono';
import type { PedidoLogin, Sessao, TipoLogin, UsuarioLogado } from '@primehub/shared';
import { config } from './config';
import { consulta, primeira } from './db';

/** O PHP grava senha com password_hash() ($2y$); bcryptjs entende como $2b$. */
async function senhaConfere(senha: string, hash: string | null): Promise<boolean> {
  if (!hash) return false;
  return bcrypt.compare(senha, hash.replace(/^\$2y\$/, '$2b$'));
}

interface LinhaUsuario { id: number; nome: string; email: string; senha_hash: string; perfil: string; foto_perfil: string | null }
interface LinhaCliente { id: number; nome: string; contato_nome: string | null; contato_email: string; senha_hash: string; foto_perfil: string | null }
interface LinhaSetorResp { id: number; nome: string; email: string; senha_hash: string; cliente_id: number; empresa_nome: string; foto_perfil: string | null }

/** Confere e-mail/senha (mesmas senhas do PHP). Não abre sessão ainda (falta o código). */
export async function verificarCredenciais(pedido: PedidoLogin): Promise<UsuarioLogado | null> {
  const email = pedido.email.trim().toLowerCase();
  let usuario: UsuarioLogado | null = null;

  if (pedido.tipo === 'equipe') {
    const u = await primeira<LinhaUsuario>('SELECT id, nome, email, senha_hash, perfil, foto_perfil FROM usuarios WHERE email = ? AND ativo = 1 LIMIT 1', [email]);
    if (u && (await senhaConfere(pedido.senha, u.senha_hash))) {
      usuario = { tipo: 'equipe', id: u.id, nome: u.nome, email: u.email, foto: u.foto_perfil, perfil: u.perfil, clienteId: null, empresa: null };
      await consulta('UPDATE usuarios SET ultimo_login_em = NOW() WHERE id = ?', [u.id]).catch(() => undefined);
    }
  } else {
    const c = await primeira<LinhaCliente>('SELECT id, nome, contato_nome, contato_email, senha_hash, foto_perfil FROM clientes WHERE contato_email = ? AND portal_ativo = 1 AND senha_hash IS NOT NULL LIMIT 1', [email]);
    if (c && (await senhaConfere(pedido.senha, c.senha_hash))) {
      usuario = { tipo: 'cliente', id: c.id, nome: c.contato_nome || c.nome, email: c.contato_email, foto: c.foto_perfil, perfil: 'master', clienteId: c.id, empresa: c.nome };
    } else {
      const s = await primeira<LinhaSetorResp>(
        `SELECT sr.id, sr.nome, sr.email, sr.senha_hash, s.cliente_id, c.nome AS empresa_nome, sr.foto_perfil
           FROM setor_responsaveis sr JOIN setores s ON s.id = sr.setor_id JOIN clientes c ON c.id = s.cliente_id
          WHERE sr.email = ? AND sr.portal_ativo = 1 AND sr.senha_hash IS NOT NULL AND s.ativo = 1 LIMIT 1`, [email]);
      if (s && (await senhaConfere(pedido.senha, s.senha_hash))) {
        usuario = { tipo: 'cliente', id: s.id, nome: s.nome, email: s.email, foto: s.foto_perfil, perfil: 'setor', clienteId: s.cliente_id, empresa: s.empresa_nome };
      }
    }
  }

  return usuario;
}

export async function emitirSessao(usuario: UsuarioLogado): Promise<Sessao> {
  const token = await new SignJWT({ u: usuario })
    .setProtectedHeader({ alg: 'HS256' })
    .setSubject(`${usuario.tipo}:${usuario.id}`)
    .setIssuedAt()
    .setExpirationTime('30d')
    .sign(config.jwtSegredo);
  return { token, usuario };
}

export type Variaveis = { usuario: UsuarioLogado };

export function exigirLogin(tipo?: TipoLogin): MiddlewareHandler<{ Variables: Variaveis }> {
  return async (c, next) => {
    const cabecalho = c.req.header('authorization') ?? '';
    const token = cabecalho.startsWith('Bearer ') ? cabecalho.slice(7) : (c.req.header('cookie')?.match(/(?:^|;\s*)ph_token=([^;]+)/)?.[1] ?? '');
    try {
      const { payload } = await jwtVerify(token, config.jwtSegredo);
      const usuario = (payload as { u?: UsuarioLogado }).u;
      if (!usuario || (tipo && usuario.tipo !== tipo)) {
        return c.json({ erro: 'Acesso negado.' }, 403);
      }
      c.set('usuario', usuario);
      await next();
    } catch {
      return c.json({ erro: 'Sessão expirada. Entre de novo.' }, 401);
    }
  };
}

const PERFIS_ADMIN = ['admin', 'diretor', 'ceo'];

/**
 * Filtro SQL de quais chamados a pessoa da equipe enxerga (mesma regra do
 * Auth::podeAcessarSetor do PHP): admin vê tudo; colaborador com "acesso
 * restrito por cliente" só vê os clientes/setores vinculados a ele.
 */
export async function filtroEscopoEquipe(usuario: UsuarioLogado, alias = 'ch'): Promise<{ sql: string; params: unknown[] }> {
  if (PERFIS_ADMIN.includes(usuario.perfil ?? '')) return { sql: '1=1', params: [] };
  const linha = await primeira<{ r: number }>('SELECT acesso_restrito_por_cliente AS r FROM usuarios WHERE id = ?', [usuario.id]);
  if (!linha?.r) return { sql: '1=1', params: [] };
  return {
    sql: `EXISTS (SELECT 1 FROM usuario_cliente_setores ucs WHERE ucs.usuario_id = ? AND ucs.cliente_id = ${alias}.cliente_id
            AND (ucs.setor_id IS NULL OR ${alias}.setor_id IS NULL OR ucs.setor_id = ${alias}.setor_id))`,
    params: [usuario.id],
  };
}

/** Chamados que o cliente enxerga: master = empresa toda; setor = setores dele. */
export function filtroEscopoCliente(usuario: UsuarioLogado, alias = 'ch'): { sql: string; params: unknown[] } {
  if (usuario.perfil === 'master') return { sql: `${alias}.cliente_id = ?`, params: [usuario.clienteId] };
  return {
    sql: `${alias}.cliente_id = ? AND ${alias}.setor_id IN (SELECT setor_id FROM setor_responsavel_setores WHERE setor_responsavel_id = ?)`,
    params: [usuario.clienteId, usuario.id],
  };
}
