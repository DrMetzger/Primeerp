'use client';

import type { Sessao, UsuarioLogado } from '@primehub/shared';

const CHAVE = 'ph_usuario';

export function salvarSessao(sessao: Sessao): void {
  // Cookie lido pela API (mesmo domínio); 30 dias, só HTTPS em produção.
  const seguro = location.protocol === 'https:' ? '; Secure' : '';
  document.cookie = `ph_token=${sessao.token}; Path=/; Max-Age=${60 * 60 * 24 * 30}; SameSite=Lax${seguro}`;
  localStorage.setItem(CHAVE, JSON.stringify(sessao.usuario));
}

export function usuarioSalvo(): UsuarioLogado | null {
  try {
    const bruto = localStorage.getItem(CHAVE);
    return bruto ? (JSON.parse(bruto) as UsuarioLogado) : null;
  } catch {
    return null;
  }
}

export function sair(): void {
  const tipo = usuarioSalvo()?.tipo;
  document.cookie = 'ph_token=; Path=/; Max-Age=0';
  localStorage.removeItem(CHAVE);
  location.href = tipo === 'cliente' ? '/portal' : '/login';
}
