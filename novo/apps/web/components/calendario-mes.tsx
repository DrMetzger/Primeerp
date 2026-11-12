'use client';

import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useMemo, useState } from 'react';

export interface EventoCalendario { id: string; data: string; titulo: string; hora?: string | null; cor: string; aoClicar?: () => void; aoMover?: (novoDia: string) => void }

const SEMANA = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];
const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

/**
 * Calendário mensal de verdade (grade de 6 semanas). No celular cada dia
 * vira bolinha com contagem e a lista do dia escolhido aparece embaixo.
 */
export function CalendarioMes({ eventos, aoEscolherDia, inicial, aoMudarMes, semCabecalho = false }: { eventos: EventoCalendario[]; aoEscolherDia?: (dia: string) => void; inicial?: Date; aoMudarMes?: (mes: Date) => void; semCabecalho?: boolean }) {
  const [arrastando, setArrastando] = useState<string | null>(null);
  const [alvo, setAlvo] = useState<string | null>(null);
  const [mes, setMes] = useState(() => { const d = inicial ? new Date(inicial) : new Date(); d.setDate(1); d.setHours(12); return d; });
  const [diaSel, setDiaSel] = useState(iso(new Date()));
  const hoje = iso(new Date());

  const dias = useMemo(() => {
    const primeiro = new Date(mes);
    const desloc = (primeiro.getDay() + 6) % 7;
    return Array.from({ length: 42 }, (_, i) => { const d = new Date(primeiro); d.setDate(1 - desloc + i); return d; });
  }, [mes]);
  const porDia = useMemo(() => {
    const m = new Map<string, EventoCalendario[]>();
    for (const e of eventos) m.set(e.data, [...(m.get(e.data) ?? []), e]);
    for (const l of m.values()) l.sort((a, b) => (a.hora ?? '').localeCompare(b.hora ?? ''));
    return m;
  }, [eventos]);
  const navegar = (n: number) => setMes((m) => { const x = new Date(m); x.setMonth(x.getMonth() + n); aoMudarMes?.(x); return x; });
  const doDia = porDia.get(diaSel) ?? [];

  return (
    <div className="rounded-3xl border border-borda bg-superficie p-3 shadow-sm sm:p-4">
      {!semCabecalho && <div className="mb-3 flex items-center gap-2">
        <button type="button" onClick={() => navegar(-1)} aria-label="Mês anterior" className="toque flex items-center justify-center rounded-xl border border-borda text-texto hover:bg-superficie-alta"><ChevronLeft size={20} /></button>
        <p className="flex-1 text-center text-lg font-bold capitalize text-texto">{mes.toLocaleDateString('pt-BR', { month: 'long', year: 'numeric' })}</p>
        <button type="button" onClick={() => { const d = new Date(); d.setDate(1); setMes(d); setDiaSel(hoje); }} className="toque rounded-xl border border-borda px-3 text-sm font-semibold text-texto hover:bg-superficie-alta">Hoje</button>
        <button type="button" onClick={() => navegar(1)} aria-label="Próximo mês" className="toque flex items-center justify-center rounded-xl border border-borda text-texto hover:bg-superficie-alta"><ChevronRight size={20} /></button>
      </div>}
      <div className="grid grid-cols-7 gap-1 text-center text-xs font-semibold uppercase text-suave">{SEMANA.map((d) => <span key={d} className="py-1">{d}</span>)}</div>
      <div className="grid grid-cols-7 gap-1">
        {dias.map((d) => {
          const k = iso(d);
          const lista = porDia.get(k) ?? [];
          const fora = d.getMonth() !== mes.getMonth();
          return (
            <div key={k} role="button" tabIndex={0} onClick={() => { setDiaSel(k); aoEscolherDia?.(k); }} onKeyDown={(e) => { if (e.key === 'Enter') { setDiaSel(k); aoEscolherDia?.(k); } }}
              aria-label={`${d.toLocaleDateString('pt-BR')}, ${lista.length} evento(s)`} aria-pressed={diaSel === k}
              onDragOver={(e) => { if (arrastando) { e.preventDefault(); setAlvo(k); } }} onDragLeave={() => setAlvo((a) => (a === k ? null : a))}
              onDrop={(e) => { e.preventDefault(); const ev = eventos.find((x) => x.id === arrastando); setArrastando(null); setAlvo(null); if (ev && ev.data !== k) ev.aoMover?.(k); }}
              className={`flex min-h-12 cursor-pointer flex-col items-stretch gap-0.5 rounded-xl border p-1 text-left transition sm:min-h-28 ${alvo === k ? 'border-primaria bg-primaria/20 ring-2 ring-primaria' : diaSel === k ? 'border-primaria bg-primaria/10' : 'border-borda/40 hover:bg-superficie-alta'} ${fora ? 'opacity-40' : ''}`}>
              <span className={`mx-auto flex h-6 w-6 items-center justify-center rounded-full text-xs font-bold sm:mx-0 ${k === hoje ? 'gradiente-marca text-white' : 'text-texto'}`}>{d.getDate()}</span>
              <span className="hidden flex-col gap-0.5 sm:flex">
                {lista.slice(0, 4).map((e) => (
                  <span key={e.id} draggable={!!e.aoMover} onDragStart={(ev) => { ev.stopPropagation(); ev.dataTransfer.effectAllowed = 'move'; setArrastando(e.id); }} onDragEnd={() => { setArrastando(null); setAlvo(null); }}
                    onClick={(ev) => { ev.stopPropagation(); e.aoClicar?.(); }}
                    className={`truncate rounded-md px-1.5 py-0.5 text-[11px] font-semibold text-white shadow-sm ${e.aoMover ? 'cursor-grab active:cursor-grabbing' : ''} ${arrastando === e.id ? 'opacity-40' : ''}`} style={{ background: e.cor }}>{e.hora ? `${e.hora} ` : ''}{e.titulo}</span>
                ))}
                {lista.length > 4 && <span className="px-1 text-[11px] text-suave">+{lista.length - 4}</span>}
              </span>
              {lista.length > 0 && <span className="mx-auto flex gap-0.5 sm:hidden">{lista.slice(0, 3).map((e) => <span key={e.id} className="h-1.5 w-1.5 rounded-full" style={{ background: e.cor }} />)}</span>}
            </div>
          );
        })}
      </div>
      <div className="mt-3 border-t border-borda pt-3">
        <p className="mb-2 text-sm font-semibold text-texto">{new Date(`${diaSel}T12:00`).toLocaleDateString('pt-BR', { weekday: 'long', day: '2-digit', month: 'long' })}</p>
        {doDia.length ? (
          <ul className="space-y-1.5">
            {doDia.map((e) => (
              <li key={e.id}>
                <button type="button" onClick={e.aoClicar} className="toque flex w-full items-center gap-2 rounded-xl border border-borda bg-fundo px-3 text-left text-sm text-texto hover:border-primaria">
                  <span className="h-3 w-3 shrink-0 rounded-full" style={{ background: e.cor }} />
                  <span className="w-12 shrink-0 text-xs font-semibold text-suave">{e.hora ?? 'Dia todo'}</span>
                  <span className="truncate font-medium">{e.titulo}</span>
                </button>
              </li>
            ))}
          </ul>
        ) : <p className="text-sm text-suave">Nada marcado nesse dia.</p>}
      </div>
    </div>
  );
}
