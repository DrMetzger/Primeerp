// Alvos de compilação do Protheus (a nossa base PRIMEERP e, depois, os
// clientes liberados a compilar via VSCode). A senha é guardada CIFRADA com a
// mesma chave do cofre de acessos (AES-256-GCM). O compilador na VM lê a senha
// decifrada por um comando local (nunca vai para log nem para o repositório).
import type { RowDataPacket } from 'mysql2/promise';
import type { UsuarioLogado, AlvoCompilacao } from '@primehub/shared';
import { consulta, primeira } from './db';
import { executar } from './regras-chamado';
import { cifrar, decifrar } from './acessos';

const ADMIN = ['admin', 'diretor', 'ceo'];
export const podeCompilacao = (u: UsuarioLogado) => u.tipo === 'equipe' && ADMIN.includes(u.perfil ?? '');

export class ErroCompilacao extends Error {
  constructor(message: string, readonly status: 400 | 403 | 404 = 400) { super(message); }
}

export async function garantirEstruturaCompilacao(): Promise<void> {
  await executar(`CREATE TABLE IF NOT EXISTS compilacao_alvos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(60) NOT NULL UNIQUE,
    nome VARCHAR(160) NOT NULL,
    host VARCHAR(160) NOT NULL DEFAULT '127.0.0.1',
    porta INT NOT NULL DEFAULT 1234,
    ambiente VARCHAR(60) NOT NULL,
    usuario VARCHAR(80) NOT NULL DEFAULT 'admin',
    build VARCHAR(40) NOT NULL DEFAULT '',
    secure TINYINT NOT NULL DEFAULT 0,
    senha_cifrada TEXT,
    includes TEXT,
    liberado TINYINT NOT NULL DEFAULT 1,
    observacoes TEXT,
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  )`);
  // Garante a linha da nossa base de testes (sem senha ainda).
  await executar(
    `INSERT INTO compilacao_alvos (slug, nome, host, porta, ambiente, usuario, build)
     VALUES ('base', 'Base PrimeERP (VM)', '127.0.0.1', 1234, 'PRIMEERP', 'admin', '7.00.240223P')
     ON DUPLICATE KEY UPDATE slug = slug`,
  );
}

function paraAlvo(l: RowDataPacket): AlvoCompilacao {
  return {
    id: Number(l.id), slug: String(l.slug), nome: String(l.nome), host: String(l.host),
    porta: Number(l.porta), ambiente: String(l.ambiente), usuario: String(l.usuario),
    build: String(l.build ?? ''), secure: Boolean(l.secure), liberado: Boolean(l.liberado),
    includes: (l.includes as string | null) ?? '', observacoes: (l.observacoes as string | null) ?? '',
    temSenha: Boolean(l.senha_cifrada),
  };
}

export async function listarAlvos(u: UsuarioLogado): Promise<AlvoCompilacao[]> {
  if (!podeCompilacao(u)) throw new ErroCompilacao('Sem permissão.', 403);
  const linhas = await consulta<RowDataPacket>('SELECT * FROM compilacao_alvos ORDER BY slug = "base" DESC, nome', []);
  return linhas.map(paraAlvo);
}

/** Cria/atualiza um alvo. A senha só é trocada quando vem preenchida. */
export async function salvarAlvo(u: UsuarioLogado, slug: string, d: Record<string, unknown>): Promise<AlvoCompilacao> {
  if (!podeCompilacao(u)) throw new ErroCompilacao('Sem permissão.', 403);
  slug = String(slug || '').trim().toLowerCase().replace(/[^a-z0-9_-]/g, '');
  if (!slug) throw new ErroCompilacao('Identificador inválido.');
  const nome = String(d.nome ?? '').trim() || slug;
  const host = String(d.host ?? '127.0.0.1').trim() || '127.0.0.1';
  const porta = Number(d.porta) || 1234;
  const ambiente = String(d.ambiente ?? '').trim();
  const usuario = String(d.usuario ?? 'admin').trim() || 'admin';
  const build = String(d.build ?? '').trim();
  const secure = d.secure ? 1 : 0;
  const includes = String(d.includes ?? '').trim();
  const liberado = d.liberado === false ? 0 : 1;
  const observacoes = String(d.observacoes ?? '').trim();
  if (!ambiente) throw new ErroCompilacao('Informe o ambiente (ex.: PRIMEERP).');
  const senha = typeof d.senha === 'string' ? d.senha : '';
  const existe = await primeira<RowDataPacket>('SELECT id FROM compilacao_alvos WHERE slug = ?', [slug]);
  if (existe) {
    await executar(
      `UPDATE compilacao_alvos SET nome=?, host=?, porta=?, ambiente=?, usuario=?, build=?, secure=?, includes=?, liberado=?, observacoes=?${senha.trim() ? ', senha_cifrada=?' : ''} WHERE slug=?`,
      senha.trim()
        ? [nome, host, porta, ambiente, usuario, build, secure, includes, liberado, observacoes, cifrar(senha), slug]
        : [nome, host, porta, ambiente, usuario, build, secure, includes, liberado, observacoes, slug],
    );
  } else {
    await executar(
      'INSERT INTO compilacao_alvos (slug, nome, host, porta, ambiente, usuario, build, secure, includes, liberado, observacoes, senha_cifrada) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
      [slug, nome, host, porta, ambiente, usuario, build, secure, includes, liberado, observacoes, senha.trim() ? cifrar(senha) : null],
    );
  }
  const l = await primeira<RowDataPacket>('SELECT * FROM compilacao_alvos WHERE slug = ?', [slug]);
  return paraAlvo(l as RowDataPacket);
}

export async function excluirAlvo(u: UsuarioLogado, slug: string): Promise<void> {
  if (!podeCompilacao(u)) throw new ErroCompilacao('Sem permissão.', 403);
  if (slug === 'base') throw new ErroCompilacao('A base não pode ser removida.');
  await executar('DELETE FROM compilacao_alvos WHERE slug = ?', [slug]);
}

/** Dados do alvo + senha decifrada. Uso interno (compilador na VM). */
export async function alvoComSenha(slug: string): Promise<(AlvoCompilacao & { senha: string }) | null> {
  const l = await primeira<RowDataPacket>('SELECT * FROM compilacao_alvos WHERE slug = ?', [slug]);
  if (!l) return null;
  return { ...paraAlvo(l), senha: decifrar(l.senha_cifrada as string | null) ?? '' };
}
