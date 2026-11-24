'use client';

import { COLUNAS_PIPELINE, FASES_AGENTE, type AtividadeAgente, type EtapaPipeline, type PropostaAgente } from '@primehub/shared';
import { Activity, Bot, Eye, Hammer, ShieldCheck } from 'lucide-react';
import Link from 'next/link';
import { useCallback, useEffect, useState } from 'react';
import { Casca } from '@/components/casca';
import { EsqueletoLista } from '@/components/esqueleto';
import { CartaoProposta } from '@/components/proposta-agente';
import { Vazio } from '@/components/ui/basico';
import { api } from '@/lib/api';

const FASE_INFO = Object.fromEntries(FASES_AGENTE.map((f) => [f.fase, f]));
const COR_PRIO: Record<string, string> = { urgente: '#ef4444', alta: '#f59e0b', media: '#64748b', baixa: '#94a3b8' };
function horaCurta(s: string): string { try { return new Date(s.replace(' ', 'T')).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }); } catch { return ''; } }
/** Minutos desde o carimbo (para mostrar "há X" e marcar o que travou). */
function minutosDesde(s: string): number { try { return Math.max(0, Math.round((Date.now() - new Date(s.replace(' ', 'T')).getTime()) / 60000)); } catch { return 0; } }
function haQuanto(min: number): string { return min < 1 ? 'agora' : min < 60 ? `há ${min} min` : min < 1440 ? `há ${Math.round(min / 60)}h` : `há ${Math.round(min / 1440)}d`; }

/** Quadro (kanban) do agente: cada chamado aparece na coluna do estágio atual. */
function Quadro({ etapas }: { etapas: EtapaPipeline[] }) {
  const porColuna = (fases: string[]) => etapas.filter((e) => fases.includes(e.fase));
  return (
    <section className="rounded-3xl border border-borda bg-superficie p-4 shadow-sm">
      <h2 className="mb-3 flex items-center gap-2 text-base font-semibold text-texto"><Activity size={18} className="text-primaria" aria-hidden="true" /> Pipeline do agente — em que pé está cada coisa</h2>
      <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        {COLUNAS_PIPELINE.map((col) => {
          const cards = porColuna(col.fases);
          return (
            <div key={col.id} className="flex min-h-[7rem] flex-col rounded-2xl bg-superficie-alta/40 p-2.5">
              <p className="mb-2 flex items-center justify-between gap-1 px-1 text-xs font-bold uppercase tracking-wide" style={{ color: col.cor }}>
                <span className="flex items-center gap-1.5"><span className="h-2.5 w-2.5 rounded-full" style={{ background: col.cor }} aria-hidden="true" />{col.rotulo}</span>
                <span className="rounded-full bg-superficie px-1.5 text-suave">{cards.length}</span>
              </p>
              <div className="space-y-2">
                {cards.map((e) => {
                  const min = minutosDesde(e.desde);
                  // Trabalhando/Compilando devia ser rápido: passou de 15 min, provavelmente travou.
                  const travou = ['trabalhando', 'compilando'].includes(col.id) && min > 15;
                  return (
                    <Link key={e.chamadoId} href={`/painel/chamado?id=${e.chamadoId}`}
                      className="toque block rounded-xl border border-borda bg-superficie p-2.5 text-left transition hover:border-primaria/60"
                      style={{ borderLeft: `3px solid ${travou ? '#ef4444' : (COR_PRIO[e.prioridade] ?? '#64748b')}` }}>
                      <p className="flex items-center gap-1.5 text-xs font-semibold text-primaria">#{e.chamadoId} · <span className="truncate text-suave">{e.cliente}</span></p>
                      <p className="mt-0.5 line-clamp-2 text-xs font-medium leading-tight text-texto">{e.chamadoTitulo}</p>
                      <p className={`mt-1 text-[11px] ${travou ? 'font-semibold text-perigo' : 'text-suave'}`}>{travou ? `⚠ parado ${haQuanto(min)}` : haQuanto(min)}</p>
                    </Link>
                  );
                })}
                {cards.length === 0 && <p className="px-1 text-[11px] text-suave/60">—</p>}
              </div>
            </div>
          );
        })}
      </div>
    </section>
  );
}

/** Mesa do agente: tudo o que o agente Claude preparou, esperando a equipe decidir. */
export default function PaginaAgente() {
  const [itens, setItens] = useState<PropostaAgente[] | null>(null);
  const [atividades, setAtividades] = useState<AtividadeAgente[]>([]);
  const [pipeline, setPipeline] = useState<EtapaPipeline[]>([]);
  const [erro, setErro] = useState('');
  const carregar = useCallback(() => {
    void api<AtividadeAgente[]>('/api/agente/atividades').then(setAtividades).catch(() => undefined);
    void api<EtapaPipeline[]>('/api/agente/pipeline').then(setPipeline).catch(() => undefined);
    return api<PropostaAgente[]>('/api/agente/mesa').then(setItens).catch((e: unknown) => setErro(e instanceof Error ? e.message : 'Não consegui abrir a mesa.'));
  }, []);
  useEffect(() => { void carregar(); const t = setInterval(() => void carregar(), 20_000); return () => clearInterval(t); }, [carregar]);
  const revisar = itens?.filter((p) => p.status === 'rascunho') ?? [];
  const fazendo = itens?.filter((p) => p.status === 'aprovado_execucao') ?? [];
  return (
    <Casca tipo="equipe" titulo="Mesa do agente">
      <div className="mx-auto max-w-5xl space-y-6">
        <div className="flex flex-wrap items-center gap-3 rounded-3xl border border-borda bg-superficie p-4 shadow-sm">
          <span className="flex h-11 w-11 items-center justify-center rounded-2xl text-white" style={{ background: 'linear-gradient(135deg,#d946ef,#7c3aed)' }}><Bot size={22} aria-hidden="true" /></span>
          <div className="min-w-0 flex-1">
            <p className="font-bold text-texto">O agente prepara, você decide.</p>
            <p className="flex items-center gap-1.5 text-sm text-suave"><ShieldCheck size={15} className="text-sucesso" aria-hidden="true" /> Nada vai para o cliente sem você clicar em Enviar.</p>
          </div>
          <span className="inline-flex items-center gap-1.5 rounded-full bg-sky-500/15 px-3 py-1 text-sm font-semibold text-sky-500"><Eye size={15} aria-hidden="true" /> {revisar.length} para revisar</span>
          <span className="inline-flex items-center gap-1.5 rounded-full bg-violet-500/15 px-3 py-1 text-sm font-semibold text-violet-500"><Hammer size={15} aria-hidden="true" /> {fazendo.length} em execução</span>
        </div>

        {pipeline.length > 0 && <Quadro etapas={pipeline} />}

        {atividades.length > 0 && (
          <section className="rounded-3xl border border-borda bg-superficie p-5 shadow-sm">
            <h2 className="mb-3 flex items-center gap-2 text-base font-semibold text-texto"><Activity size={18} className="text-primaria" aria-hidden="true" /> Ações do agente (ao vivo)</h2>
            <ol className="space-y-2.5">
              {atividades.map((a, i) => {
                const info = FASE_INFO[a.fase] ?? { rotulo: a.fase, cor: '#94a3b8' };
                return (
                  <li key={i} className="flex items-start gap-3 text-sm">
                    <span className="mt-1 h-2.5 w-2.5 shrink-0 rounded-full" style={{ background: info.cor }} aria-hidden="true" />
                    <div className="min-w-0 flex-1">
                      <p className="flex flex-wrap items-center gap-x-2 leading-tight">
                        <span className="font-semibold" style={{ color: info.cor }}>{info.rotulo}</span>
                        <Link href={`/painel/chamado?id=${a.chamadoId}`} className="font-semibold text-primaria hover:underline">#{a.chamadoId} · {a.cliente}</Link>
                        <span className="text-xs text-suave">{horaCurta(a.em)}</span>
                      </p>
                      {a.texto && <p className="truncate text-suave" title={a.texto}>{a.texto}</p>}
                    </div>
                  </li>
                );
              })}
            </ol>
          </section>
        )}
        {erro ? <Vazio>{erro}</Vazio> : !itens ? <EsqueletoLista /> : !itens.length ? <Vazio>Nada esperando você agora. O agente passa pelos chamados em horário comercial.</Vazio> : (
          <div className="space-y-4">{[...revisar, ...fazendo].map((p) => <CartaoProposta key={p.id} p={p} aoMudar={() => void carregar()} />)}</div>
        )}
      </div>
    </Casca>
  );
}
