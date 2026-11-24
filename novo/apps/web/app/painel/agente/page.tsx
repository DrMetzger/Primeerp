'use client';

import { FASES_AGENTE, type AtividadeAgente, type PropostaAgente } from '@primehub/shared';
import { Activity, Bot, Eye, Hammer, ShieldCheck } from 'lucide-react';
import Link from 'next/link';
import { useCallback, useEffect, useState } from 'react';
import { Casca } from '@/components/casca';
import { EsqueletoLista } from '@/components/esqueleto';
import { CartaoProposta } from '@/components/proposta-agente';
import { Vazio } from '@/components/ui/basico';
import { api } from '@/lib/api';

const FASE_INFO = Object.fromEntries(FASES_AGENTE.map((f) => [f.fase, f]));
function horaCurta(s: string): string { try { return new Date(s.replace(' ', 'T')).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }); } catch { return ''; } }

/** Mesa do agente: tudo o que o agente Claude preparou, esperando a equipe decidir. */
export default function PaginaAgente() {
  const [itens, setItens] = useState<PropostaAgente[] | null>(null);
  const [atividades, setAtividades] = useState<AtividadeAgente[]>([]);
  const [erro, setErro] = useState('');
  const carregar = useCallback(() => {
    void api<AtividadeAgente[]>('/api/agente/atividades').then(setAtividades).catch(() => undefined);
    return api<PropostaAgente[]>('/api/agente/mesa').then(setItens).catch((e: unknown) => setErro(e instanceof Error ? e.message : 'Não consegui abrir a mesa.'));
  }, []);
  useEffect(() => { void carregar(); const t = setInterval(() => void carregar(), 30_000); return () => clearInterval(t); }, [carregar]);
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
