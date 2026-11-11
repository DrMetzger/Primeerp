'use client';

import { usuarioSalvo } from './sessao';

export class ErroApi extends Error {
  constructor(message: string, readonly status: number) {
    super(message);
  }
}

export async function api<T>(caminho: string, opcoes: RequestInit = {}): Promise<T> {
  const resposta = await fetch(caminho, {
    ...opcoes,
    credentials: 'same-origin',
    headers: { 'content-type': 'application/json', ...(opcoes.headers ?? {}) },
  });
  if (resposta.status === 401) {
    const tipo = usuarioSalvo()?.tipo;
    location.href = tipo === 'cliente' ? '/portal' : '/login';
    throw new ErroApi('Sessão expirada', 401);
  }
  const corpo = (await resposta.json().catch(() => ({}))) as T & { erro?: string };
  if (!resposta.ok) throw new ErroApi(corpo.erro ?? 'Erro inesperado', resposta.status);
  return corpo;
}

/** Abre uma tela clássica (PHP) já logado, via sso.php. */
export async function abrirClassico(destino: string, novaAba = false): Promise<void> {
  const { url } = await api<{ url: string }>('/api/classico', { method: 'POST', body: JSON.stringify({ destino }) });
  if (novaAba) window.open(url, '_blank', 'noopener');
  else location.href = url;
}

/**
 * Ações que reaproveitam as regras do PHP (avisos por e-mail/WhatsApp,
 * anexos, fila da IA). O login vai pelo mesmo cookie do sistema novo.
 */
export async function acaoPhp<T = { ok: boolean; id?: number | null; avisos?: string[] }>(
  arquivo: string, campos: Record<string, string>, anexos: File[] = [],
): Promise<T> {
  const corpo = new FormData();
  for (const [k, v] of Object.entries(campos)) corpo.append(k, v);
  for (const a of anexos) corpo.append('anexos[]', a);
  const r = await fetch(`/${arquivo}`, { method: 'POST', body: corpo, credentials: 'same-origin', headers: { 'X-PrimeHub': '1' } });
  const dados = (await r.json().catch(() => ({}))) as T & { erro?: string };
  if (!r.ok || dados.erro) throw new ErroApi(dados.erro ?? 'Não deu certo agora. Tente de novo em instantes.', r.status);
  return dados;
}

/** Endereço público de um arquivo do sistema (logo, foto, avatar). */
export function arquivoPublico(caminho: string | null | undefined): string | null {
  if (!caminho) return null;
  return /^https?:/.test(caminho) ? caminho : `/${caminho.replace(/^\//, '')}`;
}
