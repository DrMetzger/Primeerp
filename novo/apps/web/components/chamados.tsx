'use client';

import { corStatus, rotuloStatus, type ChamadoResumo } from '@primehub/shared';
import { Clock, Flame } from 'lucide-react';
import { abrirClassico } from '@/lib/api';

function esperando(horas: number | null): string | null {
  if (horas === null) return null;
  if (horas < 1) return 'agora';
  if (horas < 24) return `${horas}h`;
  return `${Math.floor(horas / 24)}d ${horas % 24}h`;
}

export function SeloStatus({ status }: { status: ChamadoResumo['status'] }) {
  return (
    <span className="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold"
      style={{ color: `var(--${corStatus[status]})`, background: `color-mix(in oklab, var(--${corStatus[status]}) 14%, transparent)` }}>
      <span aria-hidden="true" className="h-2 w-2 rounded-full" style={{ background: `var(--${corStatus[status]})` }} />
      {rotuloStatus[status]}
    </span>
  );
}

/**
 * Lista de chamados que funciona no dedo: no celular cada chamado é um
 * cartão (nada de tabela espremida/rolagem lateral); no computador vira linha.
 */
export function ListaChamados({ itens, visao }: { itens: ChamadoResumo[]; visao: 'equipe' | 'cliente' }) {
  function abrir(c: ChamadoResumo) {
    void abrirClassico(visao === 'equipe' ? `/cliente.php?id=${c.clienteId ?? 0}#chamados` : `/portal/chamado.php?id=${c.id}`);
  }
  if (!itens.length) return <p className="rounded-2xl border border-dashed border-borda p-10 text-center text-suave">Nenhum chamado por aqui.</p>;

  return (
    <ul className="space-y-2">
      {itens.map((c) => {
        const tempo = esperando(c.horasEsperando);
        const urgente = c.prioridade === 'urgente' || c.prioridade === 'alta';
        return (
          <li key={c.id}>
            <button type="button" onClick={() => abrir(c)}
              className="toque flex w-full flex-col gap-2 rounded-2xl border border-borda bg-superficie p-4 text-left shadow-sm transition hover:border-primaria/50 hover:shadow-md md:flex-row md:items-center md:gap-4">
              <div className="flex items-center gap-2 md:w-48 md:shrink-0">
                <span className="text-sm font-semibold tabular-nums text-suave">#{c.id}</span>
                <SeloStatus status={c.status} />
              </div>
              <div className="min-w-0 flex-1">
                <p className="line-clamp-2 font-semibold text-texto md:line-clamp-1">{c.titulo}</p>
                <p className="mt-0.5 truncate text-sm text-suave">
                  {visao === 'equipe' ? (c.cliente ?? 'Sem cliente') : 'Aberto em ' + new Date(c.criadoEm.replace(' ', 'T')).toLocaleDateString('pt-BR')}
                  {visao === 'equipe' && c.responsavel ? ` · ${c.responsavel}` : ''}
                </p>
              </div>
              <div className="flex items-center gap-3 text-sm md:justify-end">
                {urgente && <span className="inline-flex items-center gap-1 font-semibold text-perigo"><Flame size={16} aria-hidden="true" />{c.prioridade === 'urgente' ? 'Urgente' : 'Alta'}</span>}
                {tempo && visao === 'equipe' && <span className="inline-flex items-center gap-1 text-suave" title="Tempo esperando resposta nossa"><Clock size={16} aria-hidden="true" />{tempo}</span>}
              </div>
            </button>
          </li>
        );
      })}
    </ul>
  );
}
