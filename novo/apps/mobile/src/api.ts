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
  const corpo = (await r.json().catch(() => ({}))) as T & { erro?: string };
  if (!r.ok) throw new ErroApi(corpo.erro ?? 'Não foi possível falar com o servidor.', r.status);
  return corpo;
}
