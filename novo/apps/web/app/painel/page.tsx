'use client';

import type { Dashboard } from '@primehub/shared';
import { Bot, RefreshCw } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Casca } from '@/components/casca';
import { ListaChamados } from '@/components/chamados';
import { EsqueletoDashboard } from '@/components/esqueleto';
import { BarraStatus, CartaoKpi, GraficoSemanas, Ranking } from '@/components/graficos';
import { api } from '@/lib/api';

function Painel({ titulo, children, className = '' }: { titulo: string; children: React.ReactNode; className?: string }) {
  return (
    <section className={`rounded-3xl border border-borda bg-superficie p-5 shadow-sm ${className}`}>
      <h2 className="mb-4 text-base font-semibold text-texto">{titulo}</h2>
      {children}
    </section>
  );
}

export default function PaginaDashboard() {
  const [dados, setDados] = useState<Dashboard | null>(null);
  const [erro, setErro] = useState('');

  function carregar() {
    setErro('');
    api<Dashboard>('/api/dashboard').then(setDados).catch((e: unknown) => setErro(e instanceof Error ? e.message : 'Erro'));
  }
  useEffect(() => {
    carregar();
    const id = setInterval(carregar, 60_000);
    return () => clearInterval(id);
  }, []);

  const acoes = (
    <button type="button" onClick={carregar} className="toque hidden items-center gap-2 rounded-xl border border-borda bg-superficie px-3 text-sm text-texto sm:inline-flex" aria-label="Atualizar">
      <RefreshCw size={18} aria-hidden="true" /> Atualizar
    </button>
  );

  return (
    <Casca tipo="equipe" titulo="Dashboard" acoes={acoes}>
      {erro && <p role="alert" className="mb-4 rounded-xl bg-perigo/10 p-4 text-perigo">{erro}</p>}
      {!dados ? <EsqueletoDashboard /> : (
        <div className="space-y-5">
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-3 xl:grid-cols-6">
            <CartaoKpi kpi={dados.kpis.pendentesNossos} destaque />
            <CartaoKpi kpi={dados.kpis.urgentesPendentes} />
            <CartaoKpi kpi={dados.kpis.abertos30d} />
            <CartaoKpi kpi={dados.kpis.fechados30d} />
            <CartaoKpi kpi={dados.kpis.primeiraRespostaHoras} />
            <CartaoKpi kpi={dados.kpis.resolucaoHoras} />
          </div>

          <div className="grid gap-5 lg:grid-cols-3">
            <Painel titulo="Abertos x fechados por semana" className="lg:col-span-2"><GraficoSemanas semanas={dados.semanas} /></Painel>
            <Painel titulo="Situação agora"><BarraStatus porStatus={dados.porStatus} /></Painel>
          </div>

          <Painel titulo="Esperando resposta nossa há mais tempo">
            <ListaChamados itens={dados.maisAntigos} visao="equipe" />
          </Painel>

          <div className="grid gap-5 lg:grid-cols-3">
            <Painel titulo="Clientes que mais abriram (30 dias)"><Ranking itens={dados.porCliente} vazio="Nenhum chamado nos últimos 30 dias." /></Painel>
            <Painel titulo="Pendências por responsável"><Ranking itens={dados.porResponsavel} vazio="Nenhuma pendência. 🎉" /></Painel>
            <Painel titulo="Assistente (30 dias)">
              <div className="flex items-center gap-3">
                <span className="gradiente-marca flex h-12 w-12 items-center justify-center rounded-2xl text-white"><Bot size={24} aria-hidden="true" /></span>
                <p className="text-sm text-suave">Respostas preparadas automaticamente pra agilizar o atendimento.</p>
              </div>
              <dl className="mt-4 grid grid-cols-3 gap-2 text-center">
                {([['Análises', dados.ia.analises30d], ['Respondidas', dados.ia.respondidasSozinha30d], ['Sugestões', dados.ia.sugestoes30d]] as const).map(([r, v]) => (
                  <div key={r} className="rounded-xl bg-superficie-alta p-3"><dt className="text-xs text-suave">{r}</dt><dd className="text-xl font-bold tabular-nums text-texto">{v}</dd></div>
                ))}
              </dl>
            </Painel>
          </div>
          <p className="text-center text-xs text-suave">Atualizado às {new Date(dados.geradoEm).toLocaleTimeString('pt-BR')} · atualiza sozinho a cada minuto</p>
        </div>
      )}
    </Casca>
  );
}
