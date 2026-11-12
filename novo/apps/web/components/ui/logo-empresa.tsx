'use client';

import { useEffect, useState } from 'react';
import { arquivoPublico } from '@/lib/api';

const TAMANHOS = { p: 'h-12 w-12 rounded-[14px] p-1.5 text-sm', m: 'h-16 w-16 rounded-[20px] p-2 text-lg', g: 'h-24 w-24 rounded-[28px] p-3 text-2xl' } as const;
const cache = new Map<string, string>();

/** Cor principal do logo (ignora branco/transparente), pra dar o brilho da marca. */
function useCorDaMarca(src: string | null): string | null {
  const [cor, setCor] = useState<string | null>(src ? cache.get(src) ?? null : null);
  useEffect(() => {
    if (!src || cache.has(src)) return;
    const img = new Image();
    img.crossOrigin = 'anonymous';
    img.onload = () => {
      try {
        const c = document.createElement('canvas');
        c.width = c.height = 24;
        const ctx = c.getContext('2d', { willReadFrequently: true });
        if (!ctx) return;
        ctx.drawImage(img, 0, 0, 24, 24);
        const d = ctx.getImageData(0, 0, 24, 24).data;
        let r = 0, g = 0, b = 0, n = 0;
        for (let i = 0; i < d.length; i += 4) {
          const [pr = 0, pg = 0, pb = 0, pa = 0] = [d[i], d[i + 1], d[i + 2], d[i + 3]];
          const max = Math.max(pr, pg, pb), min = Math.min(pr, pg, pb);
          if (pa < 128 || max > 235 || max - min < 28) continue; // fundo, branco, cinza
          r += pr; g += pg; b += pb; n++;
        }
        const final = n ? `rgb(${Math.round(r / n)} ${Math.round(g / n)} ${Math.round(b / n)})` : 'rgb(47 211 229)';
        cache.set(src, final);
        setCor(final);
      } catch { /* imagem de outro domínio: fica sem brilho */ }
    };
    img.src = src;
  }, [src]);
  return cor;
}

/**
 * Logo da empresa como "ícone de app": quadrado arredondado, logo grande e
 * centralizado, borda de vidro e um brilho na cor da própria marca.
 * Sem logo (ou imagem quebrada): monograma em gradiente.
 */
export function LogoEmpresa({ nome, logo, tamanho = 'p' }: { nome: string | null; logo: string | null; tamanho?: keyof typeof TAMANHOS }) {
  const [falhou, setFalhou] = useState(false);
  const src = arquivoPublico(logo);
  const cor = useCorDaMarca(src && !falhou ? src : null);
  const iniciais = (nome ?? '?').split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0] ?? '').join('').toUpperCase();
  if (src && !falhou) {
    return (
      <span className={`${TAMANHOS[tamanho]} relative flex shrink-0 items-center justify-center overflow-hidden bg-gradient-to-br from-white to-slate-100 ring-1 ring-white/40 transition`}
        style={{ boxShadow: cor ? `0 0 0 1px color-mix(in oklab, ${cor} 35%, transparent), 0 6px 18px -6px color-mix(in oklab, ${cor} 70%, transparent)` : undefined }}>
        <img src={src} alt="" loading="lazy" onError={() => setFalhou(true)} className="h-full w-full object-contain [image-rendering:auto]" />
        <span aria-hidden="true" className="pointer-events-none absolute inset-x-0 top-0 h-1/2 bg-gradient-to-b from-white/50 to-transparent" />
      </span>
    );
  }
  return <span aria-hidden="true" className={`${TAMANHOS[tamanho]} gradiente-marca flex shrink-0 items-center justify-center font-bold text-white shadow-lg shadow-primaria/30`}>{iniciais}</span>;
}
