'use client';

import { MANUAL_PRIME_HUB } from '@primehub/shared';
import { BookOpen, Search } from 'lucide-react';
import { useState } from 'react';
import { Casca } from '@/components/casca';
import { chamarAssistente } from '@/components/assistente/assistente';

/** Manual do Prime Hub para novos colaboradores (o mesmo que a IA da equipe estuda). */
export default function PaginaManual() {
  const [busca, setBusca] = useState('');
  const termo = busca.trim().toLowerCase();
  const secoes = MANUAL_PRIME_HUB.filter((s) => !termo || `${s.titulo} ${s.texto}`.toLowerCase().includes(termo));
  return (
    <Casca tipo="equipe" titulo="Manual do Prime Hub">
      <div className="grid gap-6 lg:grid-cols-[18rem_1fr]">
        <aside className="lg:sticky lg:top-24 lg:self-start">
          <div className="rounded-3xl border border-borda bg-superficie p-4 shadow-sm">
            <p className="mb-3 flex items-center gap-2 font-bold text-texto"><BookOpen size={18} className="text-primaria" aria-hidden="true" />Índice</p>
            <nav aria-label="Índice do manual">
              <ol className="space-y-1 text-sm">
                {MANUAL_PRIME_HUB.map((s) => <li key={s.id}><a href={`#${s.id}`} className="block rounded-lg px-2 py-1.5 text-suave hover:bg-superficie-alta hover:text-texto">{s.titulo}</a></li>)}
              </ol>
            </nav>
            <button type="button" onClick={chamarAssistente} className="gradiente-marca toque mt-4 w-full rounded-xl px-3 text-sm font-semibold text-white">Perguntar ao assistente</button>
          </div>
        </aside>
        <div className="min-w-0 space-y-4">
          <label className="relative block">
            <span className="sr-only">Buscar no manual</span>
            <Search size={20} className="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-suave" aria-hidden="true" />
            <input type="search" value={busca} onChange={(e) => setBusca(e.target.value)} placeholder="Buscar no manual (ex.: Gantt, setor, senha)" className="toque w-full rounded-2xl border border-borda bg-superficie pl-12 pr-4 text-base text-texto outline-none focus:border-primaria" />
          </label>
          {secoes.map((s) => (
            <article key={s.id} id={s.id} className="scroll-mt-24 rounded-3xl border border-borda bg-superficie p-6 shadow-sm">
              <h2 className="mb-3 text-xl font-bold text-texto">{s.titulo}</h2>
              {s.texto.split('\n\n').map((p, i) => <p key={i} className="mb-3 leading-relaxed text-texto/90 last:mb-0">{p}</p>)}
            </article>
          ))}
          {!secoes.length && <p className="text-center text-suave">Nada encontrado. Tente outra palavra ou pergunte ao assistente.</p>}
        </div>
      </div>
    </Casca>
  );
}
