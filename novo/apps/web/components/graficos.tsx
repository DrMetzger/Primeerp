'use client';

import { corStatus, rotuloStatus, type ItemRanking, type Kpi, type PontoSemana, type StatusChamado } from '@primehub/shared';
import { ArrowDownRight, ArrowUpRight, Minus } from 'lucide-react';
import { useState } from 'react';

const numero = new Intl.NumberFormat('pt-BR');

export function CartaoKpi({ kpi, destaque = false }: { kpi: Kpi; destaque?: boolean }) {
  const v = kpi.variacao;
  const bom = v === null || v === 0 ? null : (v > 0) === kpi.subirEhBom;
  const Seta = v === null || v === 0 ? Minus : v > 0 ? ArrowUpRight : ArrowDownRight;
  return (
    <div className={`rounded-2xl border p-4 shadow-sm ${destaque ? 'gradiente-marca border-transparent text-white' : 'border-borda bg-superficie'}`}>
      <p className={`text-sm font-medium ${destaque ? 'text-white/85' : 'text-suave'}`}>{kpi.rotulo}</p>
      <p className="mt-2 text-3xl font-bold tabular-nums tracking-tight">
        {numero.format(kpi.valor)}{kpi.sufixo && <span className="ml-1 text-lg font-semibold opacity-70">{kpi.sufixo}</span>}
      </p>
      {v !== null && (
        <p className={`mt-1 inline-flex items-center gap-1 text-xs font-semibold ${destaque ? 'text-white/90' : bom === null ? 'text-suave' : bom ? 'text-sucesso' : 'text-perigo'}`}>
          <Seta size={14} aria-hidden="true" /> {v > 0 ? '+' : ''}{v}% vs. 30 dias antes
        </p>
      )}
    </div>
  );
}

/** Abertos x fechados por semana: barras agrupadas, legenda, tooltip e tabela. */
export function GraficoSemanas({ semanas }: { semanas: PontoSemana[] }) {
  const [foco, setFoco] = useState<number | null>(null);
  const [verTabela, setVerTabela] = useState(false);
  const maximo = Math.max(1, ...semanas.flatMap((s) => [s.abertos, s.fechados]));
  const rotuloSemana = (iso: string) => { const [, m, d] = iso.split('-'); return `${d}/${m}`; };
  const atual = foco === null ? null : semanas[foco];

  return (
    <div>
      <div className="mb-3 flex flex-wrap items-center gap-4 text-sm">
        <span className="inline-flex items-center gap-2 text-texto"><span className="h-3 w-3 rounded-sm bg-grafico1" />Abertos</span>
        <span className="inline-flex items-center gap-2 text-texto"><span className="h-3 w-3 rounded-sm bg-grafico2" />Fechados</span>
        <button type="button" onClick={() => setVerTabela(!verTabela)} className="toque ml-auto text-sm font-medium text-primaria">{verTabela ? 'Ver gráfico' : 'Ver tabela'}</button>
      </div>
      {verTabela ? (
        <div className="max-h-64 overflow-y-auto">
          <table className="w-full text-sm">
            <thead><tr className="text-left text-suave"><th className="py-2 font-medium">Semana</th><th className="py-2 text-right font-medium">Abertos</th><th className="py-2 text-right font-medium">Fechados</th></tr></thead>
            <tbody>{semanas.map((s) => (
              <tr key={s.semana} className="border-t border-borda text-texto"><td className="py-2">{rotuloSemana(s.semana)}</td><td className="py-2 text-right tabular-nums">{s.abertos}</td><td className="py-2 text-right tabular-nums">{s.fechados}</td></tr>
            ))}</tbody>
          </table>
        </div>
      ) : (
        <div className="relative">
          <div className="flex h-56 items-end gap-1 border-b border-borda sm:gap-2" onMouseLeave={() => setFoco(null)}>
            {semanas.map((s, i) => (
              <button key={s.semana} type="button" onMouseEnter={() => setFoco(i)} onFocus={() => setFoco(i)} onClick={() => setFoco(i)}
                aria-label={`Semana de ${rotuloSemana(s.semana)}: ${s.abertos} abertos, ${s.fechados} fechados`}
                className={`flex h-full flex-1 items-end justify-center gap-[2px] rounded-t-md ${foco === i ? 'bg-superficie-alta' : ''}`}>
                <span className="w-full max-w-4 rounded-t-[4px] bg-grafico1" style={{ height: `${(s.abertos / maximo) * 100}%`, minHeight: s.abertos ? 3 : 0 }} />
                <span className="w-full max-w-4 rounded-t-[4px] bg-grafico2" style={{ height: `${(s.fechados / maximo) * 100}%`, minHeight: s.fechados ? 3 : 0 }} />
              </button>
            ))}
          </div>
          <div className="mt-2 flex gap-1 text-[11px] text-suave sm:gap-2">
            {semanas.map((s, i) => <span key={s.semana} className="flex-1 text-center">{i % 2 === 1 || semanas.length <= 6 ? rotuloSemana(s.semana) : ''}</span>)}
          </div>
          {atual && (
            <div role="status" className="pointer-events-none absolute left-1/2 top-0 -translate-x-1/2 rounded-xl border border-borda bg-superficie px-3 py-2 text-sm shadow-lg">
              <p className="font-semibold text-texto">Semana de {rotuloSemana(atual.semana)}</p>
              <p className="text-suave"><span className="font-semibold text-texto tabular-nums">{atual.abertos}</span> abertos · <span className="font-semibold text-texto tabular-nums">{atual.fechados}</span> fechados</p>
            </div>
          )}
        </div>
      )}
    </div>
  );
}

/** Situação atual: barra empilhada com cada parte rotulada (cor + texto). */
export function BarraStatus({ porStatus }: { porStatus: Record<StatusChamado, number> }) {
  const ordem: StatusChamado[] = ['aguardando_equipe', 'aberto', 'aguardando_cliente', 'fechado'];
  const total = Math.max(1, ordem.reduce((t, s) => t + porStatus[s], 0));
  return (
    <div>
      <div className="flex h-4 gap-[2px] overflow-hidden rounded-full">
        {ordem.filter((s) => porStatus[s] > 0).map((s) => (
          <span key={s} title={`${rotuloStatus[s]}: ${porStatus[s]}`} style={{ width: `${(porStatus[s] / total) * 100}%`, background: `var(--${corStatus[s]})` }} />
        ))}
      </div>
      <ul className="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-1">
        {ordem.map((s) => (
          <li key={s} className="flex items-center gap-2 rounded-xl bg-superficie-alta px-3 py-2">
            <span aria-hidden="true" className="h-3 w-3 shrink-0 rounded-full" style={{ background: `var(--${corStatus[s]})` }} />
            <span className="min-w-0 flex-1 truncate text-sm text-suave">{rotuloStatus[s]}{s === 'fechado' ? ' (30d)' : ''}</span>
            <span className="text-base font-bold tabular-nums text-texto">{porStatus[s]}</span>
          </li>
        ))}
      </ul>
    </div>
  );
}

/** Ranking simples (uma série): barras horizontais com o número escrito. */
export function Ranking({ itens, vazio }: { itens: ItemRanking[]; vazio: string }) {
  if (!itens.length) return <p className="py-8 text-center text-sm text-suave">{vazio}</p>;
  const maximo = Math.max(1, ...itens.map((i) => i.valor));
  return (
    <ul className="space-y-3">
      {itens.map((item) => (
        <li key={`${item.id}-${item.nome}`}>
          <div className="mb-1 flex items-center justify-between gap-3 text-sm">
            <span className="truncate font-medium text-texto">{item.nome}</span>
            <span className="font-bold tabular-nums text-texto">{item.valor}</span>
          </div>
          <div className="h-2 rounded-full bg-superficie-alta">
            <div className="h-2 rounded-full bg-grafico1" style={{ width: `${(item.valor / maximo) * 100}%` }} />
          </div>
        </li>
      ))}
    </ul>
  );
}
