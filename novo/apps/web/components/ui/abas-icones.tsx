'use client';

import type { LucideIcon } from 'lucide-react';

export interface AbaIcone<T extends string> { id: T; rotulo: string; icone: LucideIcon; cor: string; contagem?: number }

/** Abas internas com ícone e cor própria de cada seção (quebram linha: nada fica cortado). */
export function AbasIcones<T extends string>({ abas, ativa, aoTrocar }: { abas: AbaIcone<T>[]; ativa: T; aoTrocar: (a: T) => void }) {
  return (
    <div className="flex flex-wrap gap-2" role="tablist">
      {abas.map((a) => {
        const sel = a.id === ativa;
        const Icone = a.icone;
        return (
          <button key={a.id} type="button" role="tab" aria-selected={sel} onClick={() => aoTrocar(a.id)}
            className={`toque group inline-flex shrink-0 items-center gap-2 rounded-2xl border px-3 text-sm font-semibold transition ${sel ? 'text-white shadow-lg' : 'border-borda bg-superficie text-texto hover:bg-superficie-alta'}`}
            style={sel ? { background: `linear-gradient(135deg, ${a.cor}, color-mix(in oklab, ${a.cor} 60%, #0b1020))`, borderColor: 'transparent', boxShadow: `0 8px 20px -10px ${a.cor}` } : undefined}>
            <span className="flex h-7 w-7 items-center justify-center rounded-lg" style={{ background: sel ? 'rgb(255 255 255 / .18)' : `color-mix(in oklab, ${a.cor} 18%, transparent)`, color: sel ? '#fff' : a.cor }}>
              <Icone size={16} aria-hidden="true" />
            </span>
            {a.rotulo}
            {a.contagem !== undefined && a.contagem > 0 && <span className={`rounded-full px-1.5 text-xs ${sel ? 'bg-white/25' : 'bg-superficie-alta text-suave'}`}>{a.contagem}</span>}
          </button>
        );
      })}
    </div>
  );
}
