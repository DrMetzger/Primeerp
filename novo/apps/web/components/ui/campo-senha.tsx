'use client';

import { Eye, EyeOff } from 'lucide-react';
import { useId, useState } from 'react';

/** Toda senha tem o botão de olho (mostrar/esconder). */
export function CampoSenha({ rotulo, valor, aoMudar, dica, autoComplete = 'new-password' }: { rotulo: string; valor: string; aoMudar: (v: string) => void; dica?: string; autoComplete?: string }) {
  const [ver, setVer] = useState(false);
  const id = useId();
  return (
    <div className="space-y-1.5">
      <label htmlFor={id} className="text-sm font-semibold text-texto">{rotulo}</label>
      <div className="relative">
        <input id={id} type={ver ? 'text' : 'password'} value={valor} onChange={(e) => aoMudar(e.target.value)} autoComplete={autoComplete}
          className="toque w-full rounded-xl border border-borda bg-fundo pl-3 pr-12 text-base text-texto outline-none focus:border-primaria" />
        <button type="button" onClick={() => setVer((v) => !v)} aria-label={ver ? 'Esconder senha' : 'Mostrar senha'} aria-pressed={ver}
          className="toque absolute right-1 top-1/2 flex -translate-y-1/2 items-center justify-center rounded-lg text-suave hover:text-texto">
          {ver ? <EyeOff size={20} /> : <Eye size={20} />}
        </button>
      </div>
      {dica && <p className="text-xs text-suave">{dica}</p>}
    </div>
  );
}
