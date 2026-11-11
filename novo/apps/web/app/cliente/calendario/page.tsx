'use client';

import type { EventoCliente } from '@primehub/shared';
import { CalendarDays, ExternalLink } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Casca } from '@/components/casca';
import { EsqueletoLista } from '@/components/esqueleto';
import { Vazio, dataBR } from '@/components/ui/basico';
import { api } from '@/lib/api';

const TIPOS: Record<string, string> = { treinamento: 'Treinamento', reuniao: 'Reunião', conversa: 'Conversa', auxilio: 'Auxílio' };

export default function PaginaCalendario() {
  const [itens, setItens] = useState<EventoCliente[] | null>(null);
  useEffect(() => { api<EventoCliente[]>('/api/portal/calendario').then(setItens).catch(() => setItens([])); }, []);
  const agora = new Date().toISOString().slice(0, 16).replace('T', ' ');
  const proximos = (itens ?? []).filter((e) => (e.inicio ?? '') >= agora.slice(0, 10)).reverse();
  const passados = (itens ?? []).filter((e) => (e.inicio ?? '') < agora.slice(0, 10));

  const lista = (l: EventoCliente[]) => (
    <ul className="space-y-2">
      {l.map((e) => (
        <li key={e.id} className="flex items-center gap-3 rounded-2xl border border-borda bg-superficie p-4 shadow-sm">
          <span className="gradiente-marca flex h-12 w-12 shrink-0 flex-col items-center justify-center rounded-2xl text-white">
            <span className="text-lg font-bold leading-none">{e.inicio?.slice(8, 10)}</span>
            <span className="text-[10px] uppercase">{e.inicio ? new Date(e.inicio.replace(' ', 'T')).toLocaleDateString('pt-BR', { month: 'short' }).replace('.', '') : ''}</span>
          </span>
          <div className="min-w-0 flex-1">
            <p className="font-semibold text-texto">{e.titulo}</p>
            <p className="text-sm text-suave">{[TIPOS[e.tipo ?? ''] ?? e.tipo, dataBR(e.inicio, true), e.setor].filter(Boolean).join(' · ')}</p>
          </div>
          {e.link && <a href={e.link} target="_blank" rel="noopener noreferrer" className="toque inline-flex items-center gap-1 rounded-xl border border-borda px-3 text-sm font-semibold text-primaria"><ExternalLink size={16} aria-hidden="true" />Entrar</a>}
        </li>
      ))}
    </ul>
  );

  return (
    <Casca tipo="cliente" titulo="Calendário">
      {!itens ? <EsqueletoLista /> : !itens.length ? <Vazio><CalendarDays className="mx-auto mb-2" aria-hidden="true" />Nenhum treinamento ou reunião agendado.</Vazio> : (
        <div className="space-y-6">
          <section><h2 className="mb-2 font-bold text-texto">Próximos</h2>{proximos.length ? lista(proximos) : <Vazio>Nada marcado por enquanto.</Vazio>}</section>
          {passados.length > 0 && <section><h2 className="mb-2 font-bold text-texto">Já aconteceram</h2>{lista(passados)}</section>}
        </div>
      )}
    </Casca>
  );
}
