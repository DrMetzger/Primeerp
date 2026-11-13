'use client';

import type { PropostaAgente } from '@primehub/shared';
import { Bot, Eye, Hammer, ShieldCheck } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { Casca } from '@/components/casca';
import { EsqueletoLista } from '@/components/esqueleto';
import { CartaoProposta } from '@/components/proposta-agente';
import { Vazio } from '@/components/ui/basico';
import { api } from '@/lib/api';

/** Mesa do agente: tudo o que o agente Claude preparou, esperando a equipe decidir. */
export default function PaginaAgente() {
  const [itens, setItens] = useState<PropostaAgente[] | null>(null);
  const [erro, setErro] = useState('');
  const carregar = useCallback(() => api<PropostaAgente[]>('/api/agente/mesa').then(setItens).catch((e: unknown) => setErro(e instanceof Error ? e.message : 'Não consegui abrir a mesa.')), []);
  useEffect(() => { void carregar(); const t = setInterval(() => void carregar(), 60_000); return () => clearInterval(t); }, [carregar]);
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
        {erro ? <Vazio>{erro}</Vazio> : !itens ? <EsqueletoLista /> : !itens.length ? <Vazio>Nada esperando você agora. O agente passa pelos chamados em horário comercial.</Vazio> : (
          <div className="space-y-4">{[...revisar, ...fazendo].map((p) => <CartaoProposta key={p.id} p={p} aoMudar={() => void carregar()} />)}</div>
        )}
      </div>
    </Casca>
  );
}
