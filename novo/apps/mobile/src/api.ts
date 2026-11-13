import type { Sessao, UsuarioLogado } from '@primehub/shared';
import Constants from 'expo-constants';
import { armazenamento } from './armazenamento';

export const URL_SERVIDOR: string = (Constants.expoConfig?.extra as { urlServidor?: string } | undefined)?.urlServidor ?? 'https://suporte.primeerp.com.br';

let token: string | null = null;

export class ErroApi extends Error {
  constructor(message: string, readonly status: number) { super(message); }
}

export async function carregarSessao(): Promise<UsuarioLogado | null> {
  token = await armazenamento.ler('ph_token');
  const usuario = await armazenamento.ler('ph_usuario');
  return token && usuario ? (JSON.parse(usuario) as UsuarioLogado) : null;
}

/** Token de "lembrar este aparelho" (verificação em duas etapas). */
export async function dispositivoSalvo(): Promise<string | null> {
  return armazenamento.ler('ph_dispositivo');
}

export async function guardarSessao(sessao: Sessao): Promise<void> {
  token = sessao.token;
  if (sessao.dispositivo) await armazenamento.gravar('ph_dispositivo', sessao.dispositivo);
  await armazenamento.gravar('ph_token', sessao.token);
  await armazenamento.gravar('ph_usuario', JSON.stringify(sessao.usuario));
}

export async function encerrarSessao(): Promise<void> {
  token = null;
  await armazenamento.apagar('ph_token');
  await armazenamento.apagar('ph_usuario');
}

export async function api<T>(caminho: string, opcoes: RequestInit = {}): Promise<T> {
  const r = await fetch(`${URL_SERVIDOR}${caminho}`, {
    ...opcoes,
    headers: { 'content-type': 'application/json', ...(token ? { authorization: `Bearer ${token}` } : {}), ...(opcoes.headers ?? {}) },
  });
  const corpo = (await r.json().catch(() => ({}))) as T & { erro?: string; foraDoHorario?: boolean };
  if (corpo.foraDoHorario) ouvinteHorario?.();
  if (!r.ok) throw new ErroApi(corpo.erro ?? 'Não foi possível falar com o servidor.', r.status);
  return corpo;
}

/** Portal do cliente fora do horário comercial: a tela principal mostra o aviso. */
let ouvinteHorario: (() => void) | null = null;
export function aoFicarForaDoHorario(f: (() => void) | null): void { ouvinteHorario = f; }

/** Abre um arquivo (anexo, fonte, vídeo) no navegador do celular, já com acesso. */
export async function linkArquivo(destino: string): Promise<string> {
  const { url } = await api<{ url: string }>('/api/classico', { method: 'POST', body: JSON.stringify({ destino }) });
  return `${URL_SERVIDOR}${url}`;
}

/** Ações que reaproveitam as regras do PHP (avisos, anexos, fila da IA). */
export async function acaoPhp<T = { ok: boolean; id?: number | null; avisos?: string[] }>(arquivo: string, campos: Record<string, string>, arquivos: Record<string, { uri: string; name: string; type: string }[]> = {}): Promise<T> {
  const corpo = new FormData();
  for (const [k, v] of Object.entries(campos)) corpo.append(k, v);
  // Arquivos do celular (o React Native manda o conteúdo a partir do uri).
  for (const [k, lista] of Object.entries(arquivos)) for (const a of lista) corpo.append(k, { uri: a.uri, name: a.name, type: a.type } as unknown as Blob);
  const r = await fetch(`${URL_SERVIDOR}/${arquivo}`, {
    method: 'POST', body: corpo,
    headers: { 'X-PrimeHub': '1', ...(token ? { authorization: `Bearer ${token}` } : {}) },
  });
  const dados = (await r.json().catch(() => ({}))) as T & { erro?: string; foraDoHorario?: boolean };
  if (dados.foraDoHorario) ouvinteHorario?.();
  if (!r.ok || dados.erro) throw new ErroApi(dados.erro ?? 'Não deu certo agora. Tente de novo em instantes.', r.status);
  return dados;
}

/** Relatórios (JSON do PHP), com o mesmo login. */
export async function relatorioJson<T>(params: Record<string, string>): Promise<T> {
  const r = await fetch(`${URL_SERVIDOR}/relatorios.php?${new URLSearchParams({ ...params, formato: 'json' })}`, {
    headers: token ? { authorization: `Bearer ${token}` } : {},
  });
  if (!r.ok || !(r.headers.get('content-type') ?? '').includes('json')) throw new ErroApi('Relatórios são só para admin, diretoria e CEO.', r.status);
  return (await r.json()) as T;
}

export function arquivoPublico(caminho: string | null | undefined): string | null {
  if (!caminho) return null;
  return /^https?:/.test(caminho) ? caminho : `${URL_SERVIDOR}/${caminho.replace(/^\//, '')}`;
}

/** JSON de uma tela do PHP (ex.: configuracoes.php?formato=json), com o mesmo login. */
export async function lerJsonPhp<T>(caminho: string): Promise<T> {
  const r = await fetch(`${URL_SERVIDOR}${caminho}`, { headers: token ? { authorization: `Bearer ${token}` } : {} });
  if (!r.ok || !(r.headers.get('content-type') ?? '').includes('json')) throw new ErroApi('Essa área é só para admin, diretoria e CEO.', r.status);
  return (await r.json()) as T;
}

/** Envio com arquivo (multipart) para a API do sistema novo. */
export async function apiForm<T>(caminho: string, corpo: FormData): Promise<T> {
  const r = await fetch(`${URL_SERVIDOR}${caminho}`, { method: 'POST', body: corpo, headers: token ? { authorization: `Bearer ${token}` } : {} });
  const dados = (await r.json().catch(() => ({}))) as T & { erro?: string };
  if (!r.ok) throw new ErroApi(dados.erro ?? 'Não deu certo agora.', r.status);
  return dados;
}
