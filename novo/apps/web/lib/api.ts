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
  const corpo = (await resposta.json().catch(() => ({}))) as T & { erro?: string; foraDoHorario?: boolean };
  if (corpo.foraDoHorario) { location.href = '/fora-do-horario'; throw new ErroApi(corpo.erro ?? 'Fora do horário', 503); }
  if (!resposta.ok) throw new ErroApi(corpo.erro ?? 'Não deu certo agora. Tente de novo em instantes.', resposta.status);
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

/**
 * Manda uma ação pra qualquer tela de sempre (agenda.php, usuarios.php…):
 * o PHP responde {ok, mensagens, erro} em vez de redirecionar.
 */
export async function enviarAcao(arquivo: string, campos: Record<string, string>, arquivos: Record<string, File | File[] | null | undefined> = {}): Promise<{ ok: boolean; mensagens: Record<string, string>; erro: string | null }> {
  const corpo = new FormData();
  for (const [k, v] of Object.entries(campos)) corpo.append(k, v);
  for (const [k, v] of Object.entries(arquivos)) {
    if (!v) continue;
    if (Array.isArray(v)) v.forEach((f) => corpo.append(k, f));
    else corpo.append(k, v);
  }
  const r = await fetch(`/${arquivo}`, { method: 'POST', body: corpo, credentials: 'same-origin', headers: { 'X-PrimeHub': '1' } });
  const d = (await r.json().catch(() => null)) as { ok: boolean; mensagens?: Record<string, string>; erro?: string | null } | null;
  if (!d) throw new ErroApi('Não deu certo agora. Tente de novo em instantes.', r.status);
  if (!r.ok || d.erro) throw new ErroApi(d.erro ?? 'Não deu certo agora.', r.status);
  return { ok: true, mensagens: d.mensagens ?? {}, erro: null };
}

export async function lerJsonPhp<T>(caminho: string): Promise<T> {
  const r = await fetch(caminho, { credentials: 'same-origin' });
  if (!r.ok || !(r.headers.get('content-type') ?? '').includes('json')) throw new ErroApi('Essa área é só para admin, diretoria e CEO.', r.status);
  return (await r.json()) as T;
}
