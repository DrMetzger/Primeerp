import { createCipheriv, createDecipheriv, randomBytes } from 'node:crypto';
import { mkdir, readFile, unlink, writeFile } from 'node:fs/promises';
import { extname, join, resolve } from 'node:path';
import type { CofreCliente, UsuarioLogado } from '@primehub/shared';
import { filtroClientes } from './clientes';
import { consulta, primeira } from './db';
import { executar } from './regras-chamado';

/**
 * Cofre de acessos do cliente (credenciais de servidor/RDP/AnyDesk/VPN/
 * Protheus/SQL/T-Cloud, arquivos de VPN, links T-Cloud por ambiente e
 * acessos pelo VSCode), com as MESMAS regras do sistema antigo:
 * - vê a aba quem tem "dados sensíveis" (admin/diretor ou liberado) ou é
 *   colaborador com acesso restrito aos clientes dele;
 * - só admin/diretor/CEO veem TUDO; os demais só o que eles cadastraram;
 * - a senha fica cifrada (AES-256-GCM, mesma chave e formato de sempre) e só
 *   é revelada sob pedido, com registro em acesso_dados_sensiveis_log.
 */

export const TIPOS_CREDENCIAL: Record<string, string> = {
  servidor: 'Servidor', rdp: 'RDP', anydesk: 'AnyDesk', vpn: 'VPN', protheus: 'Protheus', sql_server: 'SQL Server', t_cloud: 'T-Cloud', outro: 'Outro',
};
export const AMBIENTES_TCLOUD: Record<string, string> = { dev: 'Desenvolvimento', teste: 'Teste', homologacao: 'Homologação', producao: 'Produção' };
const LINKS_TCLOUD = ['link_protheus', 'link_rest', 'link_schedule', 'link_exclusivo'] as const;
const MAX_ARQUIVO = 500 * 1024 * 1024;

// ---------------------------------------------------------------- Permissões

const ehAdmin = (u: UsuarioLogado) => ['admin', 'diretor', 'ceo'].includes(u.perfil ?? '');

export interface PermissaoCofre { ver: boolean; tudo: boolean }

/** Sempre consulta o banco: revogar a liberação vale na hora. */
export async function permissaoCofre(u: UsuarioLogado): Promise<PermissaoCofre> {
  if (u.tipo !== 'equipe') return { ver: false, tudo: false };
  if (ehAdmin(u)) return { ver: true, tudo: true };
  const l = await primeira<{ s: number; r: number }>('SELECT acesso_dados_sensiveis s, acesso_restrito_por_cliente r FROM usuarios WHERE id = ?', [u.id]);
  return { ver: Boolean(l?.s) || Boolean(l?.r), tudo: false };
}

async function podeVerCliente(u: UsuarioLogado, clienteId: number): Promise<boolean> {
  const f = await filtroClientes(u);
  return Boolean(await primeira(`SELECT 1 x FROM clientes c WHERE ${f.sql} AND c.id = ?`, [...f.params, clienteId]));
}

export class ErroCofre extends Error {
  constructor(message: string, readonly status: 400 | 403 | 404 = 400) { super(message); }
}

/** Confere aba + cliente; devolve a permissão. */
async function exigir(u: UsuarioLogado, clienteId: number): Promise<PermissaoCofre> {
  const p = await permissaoCofre(u);
  if (!p.ver) throw new ErroCofre('Acesso restrito: essa área guarda dados sensíveis do cliente (senhas, VPN).', 403);
  if (!(await podeVerCliente(u, clienteId))) throw new ErroCofre('Cliente não encontrado.', 404);
  return p;
}

/** Não-admin só mexe no que ele mesmo cadastrou. */
async function exigirDono(u: UsuarioLogado, p: PermissaoCofre, tabela: string, id: number, clienteId: number): Promise<Record<string, unknown>> {
  const linha = await primeira<Record<string, unknown>>(`SELECT * FROM ${tabela} WHERE id = ? AND cliente_id = ?`, [id, clienteId]);
  if (!linha) throw new ErroCofre('Registro não encontrado.', 404);
  if (!p.tudo && Number(linha.criado_por) !== u.id) throw new ErroCofre('Você só pode mexer no que você mesmo cadastrou.', 403);
  return linha;
}

async function registrar(u: UsuarioLogado, tipo: string, acao: string, referencia: number, clienteId: number): Promise<void> {
  await executar('INSERT INTO acesso_dados_sensiveis_log (usuario_id, tipo, acao, referencia_id, cliente_id) VALUES (?, ?, ?, ?, ?)', [u.id, tipo, acao, referencia, clienteId])
    .catch((e: unknown) => console.error('cofre: falha ao registrar acesso:', e instanceof Error ? e.message : e));
}

// ---------------------------------------------------------------- Cifra (compatível com o PHP)

function chave(): Buffer {
  const k = Buffer.from(process.env.CREDENCIAIS_CIFRA_CHAVE ?? '', 'base64');
  if (k.length !== 32) throw new ErroCofre('Cofre sem chave de cifra configurada no servidor.', 400);
  return k;
}

export function cifrar(texto: string | null | undefined): string | null {
  if (!texto) return null;
  const iv = randomBytes(12);
  const c = createCipheriv('aes-256-gcm', chave(), iv);
  const cifrado = Buffer.concat([c.update(texto, 'utf8'), c.final()]);
  return Buffer.concat([iv, c.getAuthTag(), cifrado]).toString('base64');
}

export function decifrar(valor: string | null | undefined): string | null {
  if (!valor) return null;
  try {
    const bruto = Buffer.from(valor, 'base64');
    if (bruto.length <= 28) return null;
    const d = createDecipheriv('aes-256-gcm', chave(), bruto.subarray(0, 12));
    d.setAuthTag(bruto.subarray(12, 28));
    return Buffer.concat([d.update(bruto.subarray(28)), d.final()]).toString('utf8');
  } catch (e) {
    if (e instanceof ErroCofre) throw e;
    return null;
  }
}

// ---------------------------------------------------------------- Leitura

export type Cofre = CofreCliente;

export async function lerCofre(u: UsuarioLogado, clienteId: number): Promise<Cofre> {
  const vazio: Cofre = { podeVer: false, veTudo: false, tipos: TIPOS_CREDENCIAL, ambientesTcloud: AMBIENTES_TCLOUD, credenciais: [], vpn: [], tcloud: [], vscode: [] };
  const p = await permissaoCofre(u);
  if (!p.ver) return vazio;
  if (!(await podeVerCliente(u, clienteId))) throw new ErroCofre('Cliente não encontrado.', 404);
  const dono = p.tudo ? '' : ' AND x.criado_por = ?';
  const pd = p.tudo ? [] : [u.id];
  const [cred, vpn, amb, vs] = await Promise.all([
    consulta<{ id: number; tipo: string; titulo: string; host: string | null; porta: string | null; usuario: string | null; tem: number; observacoes: string | null; autor: string | null; atualizadoEm: string; criado_por: number | null }>(
      `SELECT x.id, x.tipo, x.titulo, x.host, x.porta, x.usuario, (x.senha_cifrada IS NOT NULL AND x.senha_cifrada <> '') tem, x.observacoes, us.nome autor, x.atualizado_em atualizadoEm, x.criado_por
         FROM cliente_credenciais x LEFT JOIN usuarios us ON us.id = x.criado_por WHERE x.cliente_id = ?${dono} ORDER BY x.tipo, x.titulo`, [clienteId, ...pd]),
    consulta<{ id: number; titulo: string; arquivo: string; tamanho: number; autor: string | null; criadoEm: string; criado_por: number | null }>(
      `SELECT x.id, x.titulo, x.arquivo_nome_original arquivo, x.arquivo_tamanho tamanho, us.nome autor, x.criado_em criadoEm, x.criado_por
         FROM cliente_vpn_arquivos x LEFT JOIN usuarios us ON us.id = x.criado_por WHERE x.cliente_id = ?${dono} ORDER BY x.titulo`, [clienteId, ...pd]),
    consulta<Cofre['tcloud'][number]>(`SELECT tipo, link_protheus linkProtheus, link_rest linkRest, link_schedule linkSchedule, link_exclusivo linkExclusivo, atualizado_em atualizadoEm FROM cliente_tcloud_ambientes WHERE cliente_id = ?`, [clienteId]),
    consulta<{ id: number; titulo: string; link: string | null; porta: string | null; ambiente: string | null; criado_por: number | null }>(
      `SELECT x.id, x.titulo, x.link, x.porta, x.ambiente, x.criado_por FROM cliente_tcloud_vscode x WHERE x.cliente_id = ?${dono} ORDER BY x.titulo`, [clienteId, ...pd]),
  ]);
  const meu = (c: number | null) => Number(c) === u.id;
  return {
    podeVer: true,
    veTudo: p.tudo,
    tipos: TIPOS_CREDENCIAL,
    ambientesTcloud: AMBIENTES_TCLOUD,
    credenciais: cred.map(({ tem, criado_por, ...c }) => ({ ...c, temSenha: Boolean(Number(tem)), meu: meu(criado_por) })),
    vpn: vpn.map(({ criado_por, ...v }) => ({ ...v, tamanho: Number(v.tamanho), meu: meu(criado_por) })),
    tcloud: Object.keys(AMBIENTES_TCLOUD).map((t) => amb.find((a) => a.tipo === t) ?? { tipo: t, linkProtheus: null, linkRest: null, linkSchedule: null, linkExclusivo: null, atualizadoEm: null }),
    vscode: vs.map(({ criado_por, ...v }) => ({ ...v, meu: meu(criado_por) })),
  };
}

export async function revelarSenha(u: UsuarioLogado, clienteId: number, id: number): Promise<string> {
  const p = await exigir(u, clienteId);
  const l = await exigirDono(u, p, 'cliente_credenciais', id, clienteId);
  const senha = decifrar(l.senha_cifrada as string | null);
  await registrar(u, 'credencial', 'visualizar_senha', id, clienteId);
  return senha ?? '';
}

// ---------------------------------------------------------------- Credenciais

const txt = (v: unknown, max = 500) => { const s = typeof v === 'string' ? v.trim() : ''; return s ? s.slice(0, max) : null; };

export async function salvarCredencial(u: UsuarioLogado, clienteId: number, id: number | null, d: Record<string, unknown>): Promise<string> {
  const p = await exigir(u, clienteId);
  const titulo = txt(d.titulo, 200);
  if (!titulo) throw new ErroCofre('Informe um título para essa credencial.');
  const tipo = typeof d.tipo === 'string' && d.tipo in TIPOS_CREDENCIAL ? d.tipo : 'outro';
  const campos = [tipo, titulo, txt(d.host, 150), txt(d.porta, 10), txt(d.usuario, 150), txt(d.observacoes, 5000)];
  const senha = typeof d.senha === 'string' ? d.senha : '';
  if (!id) {
    await executar('INSERT INTO cliente_credenciais (cliente_id, tipo, titulo, host, porta, usuario, observacoes, senha_cifrada, criado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [clienteId, ...campos, cifrar(senha), u.id]);
    return 'Credencial cadastrada.';
  }
  await exigirDono(u, p, 'cliente_credenciais', id, clienteId);
  // Senha em branco mantém a atual (não precisa redigitar para corrigir o título).
  if (senha.trim()) await executar('UPDATE cliente_credenciais SET tipo=?, titulo=?, host=?, porta=?, usuario=?, observacoes=?, senha_cifrada=? WHERE id=? AND cliente_id=?', [...campos, cifrar(senha), id, clienteId]);
  else await executar('UPDATE cliente_credenciais SET tipo=?, titulo=?, host=?, porta=?, usuario=?, observacoes=? WHERE id=? AND cliente_id=?', [...campos, id, clienteId]);
  return 'Credencial atualizada.';
}

export async function excluirCredencial(u: UsuarioLogado, clienteId: number, id: number): Promise<string> {
  const p = await exigir(u, clienteId);
  await exigirDono(u, p, 'cliente_credenciais', id, clienteId);
  await executar('DELETE FROM cliente_credenciais WHERE id = ? AND cliente_id = ?', [id, clienteId]);
  return 'Credencial removida.';
}

// ---------------------------------------------------------------- Arquivos de VPN

const pastaUploads = () => resolve(process.env.UPLOADS_DIR ?? '/uploads');

async function gravarArquivo(clienteId: number, arquivo: File): Promise<{ caminho: string; bytes: Buffer }> {
  if (arquivo.size > MAX_ARQUIVO) throw new ErroCofre('Arquivo maior que o limite de 500MB.');
  const bytes = Buffer.from(await arquivo.arrayBuffer());
  const ext = extname(arquivo.name).toLowerCase().replace(/[^.a-z0-9]/g, '').slice(0, 10);
  const nome = `${randomBytes(16).toString('hex')}${ext}`;
  const pasta = join(pastaUploads(), 'clientes', String(clienteId));
  await mkdir(pasta, { recursive: true, mode: 0o755 });
  await writeFile(join(pasta, nome), bytes, { mode: 0o644 });
  return { caminho: `clientes/${clienteId}/${nome}`, bytes };
}

export async function salvarVpn(u: UsuarioLogado, clienteId: number, id: number | null, titulo: string, arquivo: File | null): Promise<string> {
  const p = await exigir(u, clienteId);
  const t = txt(titulo, 200);
  if (!t) throw new ErroCofre('Informe um título para esse arquivo de VPN.');
  if (!id) {
    if (!arquivo) throw new ErroCofre('Escolha o arquivo de VPN.');
    const g = await gravarArquivo(clienteId, arquivo);
    await executar('INSERT INTO cliente_vpn_arquivos (cliente_id, titulo, arquivo_nome_original, arquivo_caminho, arquivo_tamanho, dados, criado_por) VALUES (?, ?, ?, ?, ?, ?, ?)', [clienteId, t, arquivo.name.slice(0, 255), g.caminho, arquivo.size, g.bytes, u.id]);
    return 'Arquivo de VPN cadastrado.';
  }
  const antigo = await exigirDono(u, p, 'cliente_vpn_arquivos', id, clienteId);
  if (!arquivo) {
    await executar('UPDATE cliente_vpn_arquivos SET titulo = ? WHERE id = ? AND cliente_id = ?', [t, id, clienteId]);
    return 'Arquivo de VPN atualizado.';
  }
  // Troca o arquivo: grava o novo e só então apaga o antigo.
  const g = await gravarArquivo(clienteId, arquivo);
  await executar('UPDATE cliente_vpn_arquivos SET titulo = ?, arquivo_nome_original = ?, arquivo_caminho = ?, arquivo_tamanho = ?, dados = ? WHERE id = ? AND cliente_id = ?', [t, arquivo.name.slice(0, 255), g.caminho, arquivo.size, g.bytes, id, clienteId]);
  if (antigo.arquivo_caminho) await unlink(join(pastaUploads(), String(antigo.arquivo_caminho))).catch(() => undefined);
  return 'Arquivo de VPN atualizado.';
}

export async function excluirVpn(u: UsuarioLogado, clienteId: number, id: number): Promise<string> {
  const p = await exigir(u, clienteId);
  const l = await exigirDono(u, p, 'cliente_vpn_arquivos', id, clienteId);
  await executar('DELETE FROM cliente_vpn_arquivos WHERE id = ? AND cliente_id = ?', [id, clienteId]);
  if (l.arquivo_caminho) await unlink(join(pastaUploads(), String(l.arquivo_caminho))).catch(() => undefined);
  return 'Arquivo de VPN removido.';
}

/** Conteúdo do arquivo (da pasta de uploads ou, se não estiver lá, da cópia no banco). */
export async function baixarVpn(u: UsuarioLogado, clienteId: number, id: number): Promise<{ nome: string; bytes: Buffer }> {
  const p = await exigir(u, clienteId);
  const l = await exigirDono(u, p, 'cliente_vpn_arquivos', id, clienteId);
  let bytes: Buffer | null = null;
  const base = pastaUploads();
  const caminho = l.arquivo_caminho ? resolve(base, String(l.arquivo_caminho)) : '';
  if (caminho.startsWith(`${base}/`)) bytes = await readFile(caminho).catch(() => null);
  if (!bytes && l.dados) bytes = Buffer.from(l.dados as Buffer);
  if (!bytes) throw new ErroCofre('Arquivo não encontrado.', 404);
  await registrar(u, 'vpn_arquivo', 'baixar_arquivo', id, clienteId);
  return { nome: String(l.arquivo_nome_original ?? 'vpn'), bytes };
}

// ---------------------------------------------------------------- T-Cloud

export async function salvarAmbienteTcloud(u: UsuarioLogado, clienteId: number, tipo: string, d: Record<string, unknown>): Promise<string> {
  await exigir(u, clienteId);
  if (!(tipo in AMBIENTES_TCLOUD)) throw new ErroCofre('Ambiente inválido.');
  const links = LINKS_TCLOUD.map((k) => txt(d[k]));
  await executar(`INSERT INTO cliente_tcloud_ambientes (cliente_id, tipo, link_protheus, link_rest, link_schedule, link_exclusivo, atualizado_por) VALUES (?, ?, ?, ?, ?, ?, ?)
      ON DUPLICATE KEY UPDATE link_protheus = VALUES(link_protheus), link_rest = VALUES(link_rest), link_schedule = VALUES(link_schedule), link_exclusivo = VALUES(link_exclusivo), atualizado_por = VALUES(atualizado_por)`,
  [clienteId, tipo, ...links, u.id]);
  return `Links de ${AMBIENTES_TCLOUD[tipo]} salvos.`;
}

export async function salvarVscode(u: UsuarioLogado, clienteId: number, id: number | null, d: Record<string, unknown>): Promise<string> {
  const p = await exigir(u, clienteId);
  const titulo = txt(d.titulo, 150);
  if (!titulo) throw new ErroCofre('Informe um título para esse acesso pelo VSCode.');
  const campos = [titulo, txt(d.link), txt(d.porta, 10), txt(d.ambiente, 100)];
  if (!id) {
    await executar('INSERT INTO cliente_tcloud_vscode (cliente_id, titulo, link, porta, ambiente, criado_por) VALUES (?, ?, ?, ?, ?, ?)', [clienteId, ...campos, u.id]);
    return 'Acesso pelo VSCode cadastrado.';
  }
  await exigirDono(u, p, 'cliente_tcloud_vscode', id, clienteId);
  await executar('UPDATE cliente_tcloud_vscode SET titulo=?, link=?, porta=?, ambiente=? WHERE id=? AND cliente_id=?', [...campos, id, clienteId]);
  return 'Acesso pelo VSCode atualizado.';
}

export async function excluirVscode(u: UsuarioLogado, clienteId: number, id: number): Promise<string> {
  const p = await exigir(u, clienteId);
  await exigirDono(u, p, 'cliente_tcloud_vscode', id, clienteId);
  await executar('DELETE FROM cliente_tcloud_vscode WHERE id = ? AND cliente_id = ?', [id, clienteId]);
  return 'Acesso pelo VSCode removido.';
}
