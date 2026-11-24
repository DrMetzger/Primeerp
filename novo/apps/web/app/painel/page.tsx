'use client';

import type { ClienteResumo, Dashboard } from '@primehub/shared';
import { Bot, RefreshCw, Siren } from 'lucide-react';
import Link from 'next/link';
import { useEffect, useState } from 'react';
import { Casca } from '@/components/casca';
import { ListaChamados } from '@/components/chamados';
import { EsqueletoDashboard } from '@/components/esqueleto';
import { BarraStatus, CartaoKpi, GraficoSemanas, Ranking } from '@/components/graficos';
import { LogoEmpresa } from '@/components/ui/logo-empresa';
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
  const [clientes, setClientes] = useState<ClienteResumo[]>([]);
  const [erro, setErro] = useState('');

  function carregar() {
    setErro('');
    api<Dashboard>('/api/dashboard').then(setDados).catch((e: unknown) => setErro(e instanceof Error ? e.message : 'Erro'));
    api<ClienteResumo[]>('/api/clientes').then(setClientes).catch(() => setClientes([]));
  }
  // Clientes com pendência, dos mais urgentes para os menos (urgentes, depois pendentes).
  const urgentes = clientes
    .filter((c) => c.pendentes > 0)
    .sort((a, b) => b.urgentes - a.urgentes || b.pendentes - a.pendentes || a.nome.localeCompare(b.nome));
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

          {urgentes.length > 0 && (
            <Painel titulo="Clientes com pendências (toque para ver os chamados)">
              <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
                {urgentes.map((c) => (
                  <Link key={c.id} href={`/painel/cliente?id=${c.id}#chamados`}
                    className="toque group flex flex-col items-center gap-3 rounded-3xl border border-borda bg-superficie-alta/40 p-4 text-center transition hover:border-primaria/60 hover:bg-superficie-alta"
                    style={c.urgentes > 0 ? { borderColor: 'color-mix(in srgb, var(--perigo) 45%, transparent)' } : undefined}>
                    <LogoEmpresa nome={c.nome} logo={c.logo} tamanho="g" />
                    <p className="line-clamp-2 text-sm font-bold leading-tight text-texto">{c.nome}</p>
                    <div className="mt-auto flex flex-wrap items-center justify-center gap-1.5">
                      {c.urgentes > 0 && (
                        <span className="inline-flex items-center gap-1 rounded-full bg-perigo/15 px-2.5 py-1 text-xs font-bold text-perigo">
                          <Siren size={12} aria-hidden="true" /> {c.urgentes} urgente{c.urgentes > 1 ? 's' : ''}
                        </span>
                      )}
                      <span className="rounded-full bg-superficie px-2.5 py-1 text-xs font-semibold text-suave">{c.pendentes} pendente{c.pendentes > 1 ? 's' : ''}</span>
                    </div>
                  </Link>
                ))}
              </div>
            </Painel>
          )}

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
