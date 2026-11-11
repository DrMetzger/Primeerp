'use client';

import { useState } from 'react';
import { arquivoPublico } from '@/lib/api';

const TAMANHOS = { p: 'h-9 w-9 text-xs', m: 'h-12 w-12 text-sm', g: 'h-20 w-20 text-xl' } as const;

/** Logo da empresa; sem logo (ou imagem quebrada) mostra as iniciais. */
export function LogoEmpresa({ nome, logo, tamanho = 'p' }: { nome: string | null; logo: string | null; tamanho?: keyof typeof TAMANHOS }) {
  const [falhou, setFalhou] = useState(false);
  const src = arquivoPublico(logo);
  const iniciais = (nome ?? '?').split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0] ?? '').join('').toUpperCase();
  if (src && !falhou) {
    return (
      <span className={`${TAMANHOS[tamanho]} flex shrink-0 items-center justify-center overflow-hidden rounded-xl border border-borda bg-white p-1`}>
        <img src={src} alt="" loading="lazy" onError={() => setFalhou(true)} className="h-full w-full object-contain" />
      </span>
    );
  }
  return <span aria-hidden="true" className={`${TAMANHOS[tamanho]} gradiente-marca flex shrink-0 items-center justify-center rounded-xl font-bold text-white`}>{iniciais}</span>;
}
