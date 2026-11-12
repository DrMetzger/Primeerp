'use client';

import type { EventoCliente, TarefaAgenda } from '@primehub/shared';
import { useRef, useState } from 'react';

const INICIO_H = 7;
const FIM_H = 20;
const PX_MIN = 1.6; // largura de 1 minuto
const ALTURA_FAIXA = 46;
const DIAS = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
const CORES = ['#0ea5e9', '#8b5cf6', '#10b981', '#f59e0b', '#f43f5e', '#06b6d4', '#84cc16', '#d946ef', '#f97316', '#6366f1'];

const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const paraMin = (h: string) => { const [a = '0', b = '0'] = h.split(':'); return Number(a) * 60 + Number(b); };
const paraHora = (m: number) => `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`;
const corDe = (nome: string | null) => CORES[[...(nome ?? '?')].reduce((s, c) => s + c.charCodeAt(0), 0) % CORES.length] ?? '#0ea5e9';

interface Barra { id: number; dia: string; ini: number; dur: number; titulo: string; sub: string; cor: string; tarefa?: TarefaAgenda; evento?: EventoCliente; faixa: number }

/**
 * Gantt da semana: uma linha por dia, horário na horizontal. Arraste a
 * barra para outro horário ou outro dia (encaixa de 15 em 15 min).
 * Reuniões com clientes aparecem tracejadas e não se arrastam.
 */
export function GanttSemana({ dias, tarefas, eventos, aoMover, aoAbrir }: {
  dias: Date[]; tarefas: TarefaAgenda[]; eventos: EventoCliente[];
  aoMover: (t: TarefaAgenda, dia: string, hora: string) => void; aoAbrir: (t: TarefaAgenda) => void;
}) {
  const [arrasto, setArrasto] = useState<{ id: number; dx: number; dy: number } | null>(null);
  const origem = useRef<{ x: number; y: number; moveu: boolean } | null>(null);
  const largura = (FIM_H - INICIO_H) * 60 * PX_MIN;
  const hoje = iso(new Date());

  // Monta as barras e empilha as que se sobrepõem no mesmo dia.
  const porDia = new Map<string, Barra[]>();
  const adicionar = (b: Omit<Barra, 'faixa'>) => {
    const lista = porDia.get(b.dia) ?? [];
    let faixa = 0;
    while (lista.some((x) => x.faixa === faixa && x.ini < b.ini + b.dur && b.ini < x.ini + x.dur)) faixa++;
    lista.push({ ...b, faixa });
    porDia.set(b.dia, lista);
  };
  for (const t of tarefas) {
    if (!t.horaInicio) continue;
    adicionar({ id: t.id, dia: t.data, ini: paraMin(t.horaInicio), dur: t.duracaoMinutos ?? 60, titulo: t.titulo, sub: [t.cliente, t.responsavel].filter(Boolean).join(' · '), cor: corDe(t.responsavel ?? t.cliente), tarefa: t });
  }
  for (const e of eventos) {
    if (!e.inicio) continue;
    const ini = paraMin(e.inicio.slice(11, 16));
    const fim = e.fim && e.fim.slice(0, 10) === e.inicio.slice(0, 10) ? paraMin(e.fim.slice(11, 16)) : ini + 60;
    adicionar({ id: -e.id, dia: e.inicio.slice(0, 10), ini, dur: Math.max(30, fim - ini), titulo: e.titulo, sub: e.cliente ?? '', cor: '#94a3b8', evento: e });
  }
  const semHorario = tarefas.filter((t) => !t.horaInicio);

  function soltar(b: Barra) {
    const a = arrasto;
    setArrasto(null);
    if (!a || !b.tarefa || !origem.current?.moveu) { if (b.tarefa && !origem.current?.moveu) aoAbrir(b.tarefa); return; }
    const novoIni = Math.max(INICIO_H * 60, Math.min(FIM_H * 60 - 15, Math.round((b.ini + a.dx / PX_MIN) / 15) * 15));
    const linhaAtual = dias.findIndex((d) => iso(d) === b.dia);
    const alturaLinha = ALTURA_FAIXA * Math.max(1, ...[...(porDia.get(b.dia) ?? [])].map((x) => x.faixa + 1)) + 8;
    const novaLinha = Math.max(0, Math.min(dias.length - 1, linhaAtual + Math.round(a.dy / alturaLinha)));
    const dia = iso(dias[novaLinha] ?? dias[0] ?? new Date());
    if (dia !== b.dia || novoIni !== b.ini) aoMover(b.tarefa, dia, paraHora(novoIni));
  }

  return (
    <div className="space-y-3">
      {semHorario.length > 0 && (
        <div className="flex flex-wrap items-center gap-2 rounded-2xl border border-dashed border-borda p-3">
          <span className="text-xs font-semibold uppercase text-suave">Sem horário:</span>
          {semHorario.map((t) => <button key={t.id} type="button" onClick={() => aoAbrir(t)} className="rounded-full px-3 py-1 text-xs font-semibold text-white" style={{ background: corDe(t.responsavel ?? t.cliente) }}>{t.data.slice(8, 10)}/{t.data.slice(5, 7)} · {t.titulo}</button>)}
        </div>
      )}
      <div className="overflow-x-auto rounded-3xl border border-borda bg-superficie shadow-sm">
        <div style={{ width: largura + 96 }} className="select-none">
          <div className="sticky top-0 flex border-b border-borda bg-superficie/95 text-xs font-semibold text-suave">
            <div className="w-24 shrink-0 p-2">Dia</div>
            <div className="relative" style={{ width: largura, height: 28 }}>
              {Array.from({ length: FIM_H - INICIO_H + 1 }, (_, i) => (
                <span key={i} className="absolute top-1.5 -translate-x-1/2" style={{ left: i * 60 * PX_MIN }}>{String(INICIO_H + i).padStart(2, '0')}h</span>
              ))}
            </div>
          </div>
          {dias.map((d) => {
            const dia = iso(d);
            const barras = porDia.get(dia) ?? [];
            const faixas = Math.max(1, ...barras.map((b) => b.faixa + 1));
            const altura = ALTURA_FAIXA * faixas + 8;
            const agora = new Date();
            const minAgora = agora.getHours() * 60 + agora.getMinutes();
            return (
              <div key={dia} className={`flex border-b border-borda last:border-0 ${dia === hoje ? 'bg-primaria/5' : ''}`}>
                <div className="w-24 shrink-0 border-r border-borda p-2">
                  <p className={`text-sm font-bold ${dia === hoje ? 'text-primaria' : 'text-texto'}`}>{DIAS[d.getDay()]}</p>
                  <p className="text-xs text-suave">{d.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' })}</p>
                </div>
                <div className="relative" style={{ width: largura, height: altura }}>
                  {Array.from({ length: FIM_H - INICIO_H }, (_, i) => <span key={i} aria-hidden="true" className="absolute inset-y-0 border-l border-borda/50" style={{ left: i * 60 * PX_MIN }} />)}
                  <span aria-hidden="true" className="absolute inset-y-0 bg-black/5" style={{ left: (12 - INICIO_H) * 60 * PX_MIN, width: 60 * PX_MIN }} title="Almoço" />
                  {dia === hoje && minAgora > INICIO_H * 60 && minAgora < FIM_H * 60 && <span aria-hidden="true" className="absolute inset-y-0 z-10 w-0.5 bg-perigo" style={{ left: (minAgora - INICIO_H * 60) * PX_MIN }} />}
                  {barras.map((b) => {
                    const a = arrasto?.id === b.id ? arrasto : null;
                    const feita = b.tarefa?.status === 'concluida';
                    return (
                      <div key={b.id} role={b.tarefa ? 'button' : undefined} tabIndex={b.tarefa ? 0 : undefined}
                        aria-label={`${b.titulo}, ${paraHora(b.ini)}, ${b.dur} minutos${b.tarefa ? ', arraste para mudar' : ''}`}
                        onKeyDown={(e) => { if (e.key === 'Enter' && b.tarefa) aoAbrir(b.tarefa); }}
                        onPointerDown={(e) => { if (!b.tarefa) { if (b.evento?.link) window.open(b.evento.link, '_blank', 'noopener'); return; } (e.target as HTMLElement).setPointerCapture(e.pointerId); origem.current = { x: e.clientX, y: e.clientY, moveu: false }; setArrasto({ id: b.id, dx: 0, dy: 0 }); }}
                        onPointerMove={(e) => { if (!origem.current || arrasto?.id !== b.id) return; const dx = e.clientX - origem.current.x, dy = e.clientY - origem.current.y; if (Math.abs(dx) + Math.abs(dy) > 4) origem.current.moveu = true; setArrasto({ id: b.id, dx, dy }); }}
                        onPointerUp={() => soltar(b)}
                        className={`absolute flex flex-col justify-center overflow-hidden rounded-xl px-2 text-white shadow-md transition-shadow ${b.tarefa ? 'cursor-grab touch-none active:cursor-grabbing' : 'cursor-pointer border-2 border-dashed border-white/60'} ${a ? 'z-20 shadow-2xl ring-2 ring-white' : ''} ${feita ? 'opacity-50' : ''}`}
                        style={{ left: (b.ini - INICIO_H * 60) * PX_MIN, width: Math.max(28, b.dur * PX_MIN - 2), top: 4 + b.faixa * ALTURA_FAIXA, height: ALTURA_FAIXA - 6,
                          background: `linear-gradient(135deg, ${b.cor}, color-mix(in oklab, ${b.cor} 70%, #0b1020))`, transform: a ? `translate(${a.dx}px, ${a.dy}px)` : undefined }}>
                        <span className={`truncate text-xs font-bold ${feita ? 'line-through' : ''}`}>{paraHora(b.ini)} {b.titulo}</span>
                        <span className="truncate text-[11px] opacity-85">{b.sub}</span>
                      </div>
                    );
                  })}
                </div>
              </div>
            );
          })}
        </div>
      </div>
      <p className="text-xs text-suave">Arraste uma tarefa para outro horário ou outro dia. Toque para editar. Reuniões com clientes (tracejadas) abrem o link da reunião.</p>
    </div>
  );
}
