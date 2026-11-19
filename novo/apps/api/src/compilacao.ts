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
    grupo VARCHAR(120) NOT NULL DEFAULT '',
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
  await executar("ALTER TABLE compilacao_alvos ADD COLUMN IF NOT EXISTS grupo VARCHAR(120) NOT NULL DEFAULT ''").catch(() => undefined);
  // Nossa base de testes (sem senha ainda).
  await executar(
    `INSERT INTO compilacao_alvos (slug, grupo, nome, host, porta, ambiente, usuario, build)
     VALUES ('base', 'PrimeERP', 'Base PrimeERP (VM)', '127.0.0.1', 1234, 'PRIMEERP', 'admin', '7.00.240223P')
     ON DUPLICATE KEY UPDATE slug = slug`,
  );
  // Semeia uma vez os servidores do VSCode (não recria se a pessoa apagar).
  const jaSemeou = await primeira<RowDataPacket>("SELECT valor FROM configuracoes WHERE chave = 'compilacao_seed_v1'");
  if (!jaSemeou) {
    for (const s of SERVIDORES_VSCODE) {
      await executar(
        `INSERT INTO compilacao_alvos (slug, grupo, nome, host, porta, ambiente, usuario, includes, secure)
         VALUES (?,?,?,?,?, '', 'admin', '', ?) ON DUPLICATE KEY UPDATE slug = slug`,
        [s.slug, s.grupo, s.nome, s.host, s.porta, s.secure ? 1 : 0],
      );
    }
    await executar("INSERT INTO configuracoes (chave, valor) VALUES ('compilacao_seed_v1', '1') ON DUPLICATE KEY UPDATE valor = valor");
  }
}

// Servidores que a equipe já usa no VSCode (empresa, nome, host, porta).
// O ambiente, o usuário e a senha são preenchidos no painel (cofre).
const SERVIDORES_VSCODE: { slug: string; grupo: string; nome: string; host: string; porta: number; secure?: boolean }[] = [
  { slug: '3life-exc-dev', grupo: '3LIFE', nome: 'EXCLUSIVO - DESENVOLVIMENTO', host: 'threelife187384.protheus.cloudtotvs.com.br', porta: 10376 },
  { slug: 'ds-teste-debug', grupo: 'DS TECNOLOGIA', nome: 'DS - TESTE - DEBUG', host: 'ds122513.protheus.cloudtotvs.com.br', porta: 7600 },
  { slug: 'ds-teste-exclusivo', grupo: 'DS TECNOLOGIA', nome: 'DS - TESTE - EXCLUSIVO', host: 'ds122513.protheus.cloudtotvs.com.br', porta: 6701 },
  { slug: 'intecom-dev-dbg', grupo: 'INTECOM', nome: 'DESENVOLVIMENTO - DBG', host: 'intecomservicos212496.protheus.cloudtotvs.com.br', porta: 7600 },
  { slug: 'intecom-dev-exc', grupo: 'INTECOM', nome: 'DESENVOLVIMENTO - EXC', host: 'intecomservicos212496.protheus.cloudtotvs.com.br', porta: 6701 },
  { slug: 'prever-prd-rest', grupo: 'PREVER', nome: 'PRD - REST', host: 'sociedademutuaria207824.protheus.cloudtotvs.com.br', porta: 6400 },
  { slug: 'werk-dev-exc', grupo: 'WERK SCHOTT', nome: 'DESENVOLVIMENTO - EXC', host: '189.126.155.148', porta: 1228 },
];

function paraAlvo(l: RowDataPacket): AlvoCompilacao {
  return {
    id: Number(l.id), slug: String(l.slug), grupo: String(l.grupo ?? ''), nome: String(l.nome), host: String(l.host),
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
  const grupo = String(d.grupo ?? '').trim();
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
      `UPDATE compilacao_alvos SET grupo=?, nome=?, host=?, porta=?, ambiente=?, usuario=?, build=?, secure=?, includes=?, liberado=?, observacoes=?${senha.trim() ? ', senha_cifrada=?' : ''} WHERE slug=?`,
      senha.trim()
        ? [grupo, nome, host, porta, ambiente, usuario, build, secure, includes, liberado, observacoes, cifrar(senha), slug]
        : [grupo, nome, host, porta, ambiente, usuario, build, secure, includes, liberado, observacoes, slug],
    );
  } else {
    await executar(
      'INSERT INTO compilacao_alvos (slug, grupo, nome, host, porta, ambiente, usuario, build, secure, includes, liberado, observacoes, senha_cifrada) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
      [slug, grupo, nome, host, porta, ambiente, usuario, build, secure, includes, liberado, observacoes, senha.trim() ? cifrar(senha) : null],
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

// ---- Compilar um fonte e gerar o patch (.ptm) -----------------------------
import { agente } from './vm-servicos';
import { mkdir, writeFile, readFile } from 'node:fs/promises';
import { randomBytes } from 'node:crypto';
import { join } from 'node:path';

const PATCHES = join(process.env.UPLOADS_DIR ?? '/uploads', 'patches');

export interface ArquivoFonte { nome: string; conteudo_base64: string }
export interface ResultadoCompilacao { ok: boolean; nome?: string; erro?: string; token?: string }

/** Compila os fontes no servidor do alvo e guarda o .ptm para download. Só admin. */
export async function compilarFonte(u: UsuarioLogado, slug: string, arquivos: ArquivoFonte[]): Promise<ResultadoCompilacao> {
  if (!podeCompilacao(u)) throw new ErroCompilacao('Sem permissão.', 403);
  slug = String(slug || '').trim().toLowerCase().replace(/[^a-z0-9_-]/g, '');
  const alvo = await primeira<RowDataPacket>('SELECT liberado FROM compilacao_alvos WHERE slug = ?', [slug]);
  if (!alvo) throw new ErroCompilacao('Servidor não encontrado.', 404);
  if (!alvo.liberado) throw new ErroCompilacao('Servidor não está liberado para compilar.');
  if (!arquivos?.length) throw new ErroCompilacao('Envie ao menos um fonte (.prw/.tlpp).');
  const r = await agente<{ ok: boolean; nome?: string; patch_base64?: string; erro?: string }>('POST', '/compilacao/compilar', u.nome, { slug, arquivos }, 280_000);
  if (!r.ok || !r.patch_base64) return { ok: false, erro: r.erro || 'não gerou o patch (ver log)' };
  await mkdir(PATCHES, { recursive: true });
  const token = randomBytes(16).toString('hex');
  await writeFile(join(PATCHES, `${token}.ptm`), Buffer.from(r.patch_base64, 'base64'));
  await writeFile(join(PATCHES, `${token}.nome`), r.nome ?? 'patch.ptm');
  return { ok: true, nome: r.nome ?? '', token };
}

export async function lerPatch(token: string): Promise<{ nome: string; bytes: Buffer } | null> {
  const t = String(token || '').replace(/[^a-f0-9]/g, '');
  if (t.length !== 32) return null;
  try {
    const bytes = await readFile(join(PATCHES, `${t}.ptm`));
    const nome = (await readFile(join(PATCHES, `${t}.nome`), 'utf8').catch(() => 'patch.ptm')).trim() || 'patch.ptm';
    return { nome, bytes };
  } catch { return null; }
}
