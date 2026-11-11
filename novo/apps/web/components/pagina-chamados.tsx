'use client';

import type { ListaChamados as Lista } from '@primehub/shared';
import { Plus, Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Casca } from './casca';
import { ListaChamados } from './chamados';
import { EsqueletoLista } from './esqueleto';
import { abrirClassico, api } from '@/lib/api';

const FILTROS = [
  { valor: 'pendentes', rotulo: 'Pendentes' },
  { valor: 'aguardando_equipe', rotulo: 'Aguardando nós' },
  { valor: 'aguardando_cliente', rotulo: 'Aguardando cliente' },
  { valor: 'fechado', rotulo: 'Fechados' },
  { valor: '', rotulo: 'Todos' },
];

export function PaginaChamados({ visao }: { visao: 'equipe' | 'cliente' }) {
  const [status, setStatus] = useState('pendentes');
  const [busca, setBusca] = useState('');
  const [dados, setDados] = useState<Lista | null>(null);

  useEffect(() => {
    setDados(null);
    const t = setTimeout(() => {
      const q = new URLSearchParams({ status, busca });
      api<Lista>(`/api/chamados?${q}`).then(setDados).catch(() => setDados({ itens: [], total: 0 }));
    }, busca ? 300 : 0);
    return () => clearTimeout(t);
  }, [status, busca]);

  const acoes = visao === 'cliente' ? (
    <button type="button" onClick={() => void abrirClassico('/portal/chamados.php')} className="gradiente-marca toque inline-flex items-center gap-2 rounded-xl px-4 font-semibold text-white shadow">
      <Plus size={20} aria-hidden="true" /> <span className="hidden sm:inline">Abrir chamado</span>
    </button>
  ) : undefined;

  return (
    <Casca tipo={visao} titulo={visao === 'equipe' ? 'Chamados' : 'Meus chamados'} acoes={acoes}>
      <div className="mb-4 space-y-3">
        <label className="relative block">
          <span className="sr-only">Buscar chamado</span>
          <Search size={20} className="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-suave" aria-hidden="true" />
          <input type="search" value={busca} onChange={(e) => setBusca(e.target.value)} placeholder="Buscar por número, título ou cliente"
            className="toque w-full rounded-2xl border border-borda bg-superficie pl-12 pr-4 text-base text-texto outline-none focus:border-primaria" />
        </label>
        <div className="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1" role="tablist" aria-label="Filtrar por situação">
          {FILTROS.map((f) => (
            <button key={f.rotulo} type="button" role="tab" aria-selected={status === f.valor} onClick={() => setStatus(f.valor)}
              className={`toque shrink-0 rounded-full px-4 text-sm font-semibold ${status === f.valor ? 'gradiente-marca text-white shadow' : 'border border-borda bg-superficie text-suave'}`}>
              {f.rotulo}
            </button>
          ))}
        </div>
      </div>
      {!dados ? <EsqueletoLista /> : (
        <>
          <p className="mb-2 text-sm text-suave">{dados.total} chamado(s){dados.total > dados.itens.length ? ` · mostrando ${dados.itens.length}` : ''}</p>
          <ListaChamados itens={dados.itens} visao={visao} />
        </>
      )}
    </Casca>
  );
}
