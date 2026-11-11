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
