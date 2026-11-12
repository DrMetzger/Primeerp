'use client';

import { corStatus, rotuloStatus, type ChamadoResumo } from '@primehub/shared';
import { Clock, Flame } from 'lucide-react';
import { Avatar } from './ui/avatar';
import { tempoRelativo } from './ui/basico';
import { LogoEmpresa } from './ui/logo-empresa';

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
  const rota = (c: ChamadoResumo) => (visao === 'equipe' ? `/painel/chamado?id=${c.id}` : `/cliente/chamado?id=${c.id}`);
  if (!itens.length) return <p className="rounded-2xl border border-dashed border-borda p-10 text-center text-suave">Nenhum chamado por aqui.</p>;

  return (
    <ul className="space-y-2">
      {itens.map((c) => {
        const tempo = esperando(c.horasEsperando);
        const urgente = c.prioridade === 'urgente' || c.prioridade === 'alta';
        return (
          <li key={c.id}>
            <a href={rota(c)}
              className="group flex w-full items-center gap-3 rounded-2xl border border-borda bg-superficie p-3 text-left shadow-sm transition hover:-translate-y-0.5 hover:border-primaria/50 hover:shadow-lg sm:gap-4 sm:p-4">
              {visao === 'equipe' && <LogoEmpresa nome={c.cliente} logo={c.clienteLogo} />}
              <div className="min-w-0 flex-1 space-y-1">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="text-xs font-bold tabular-nums text-suave">#{c.id}</span>
                  <SeloStatus status={c.status} />
                  {urgente && <span className="inline-flex items-center gap-1 rounded-full bg-perigo/10 px-2 py-0.5 text-xs font-bold text-perigo"><Flame size={13} aria-hidden="true" />{c.prioridade === 'urgente' ? 'Urgente' : 'Alta'}</span>}
                  {visao === 'equipe' && <span className="truncate text-xs font-semibold text-suave">{c.cliente}</span>}
                </div>
                <p className="line-clamp-2 font-semibold text-texto md:line-clamp-1">{c.titulo}</p>
                {c.ultimo ? (
                  <p className="flex min-w-0 items-center gap-2 text-sm text-suave">
                    <Avatar nome={c.ultimo.nome} foto={c.ultimo.foto} tamanho={22} anel={c.ultimo.tipo === 'equipe' ? 'ring-primaria/60' : 'ring-destaque/60'} />
                    <span className="truncate"><b className="font-semibold text-texto">{c.ultimo.nome}</b> respondeu {tempoRelativo(c.ultimo.em)}</span>
                  </p>
                ) : (
                  <p className="text-sm text-suave">Aberto {tempoRelativo(c.criadoEm)}, ainda sem respostas</p>
                )}
              </div>
              <div className="hidden shrink-0 flex-col items-end gap-2 sm:flex">
                {visao === 'equipe' && c.responsavel && (
                  <span className="flex items-center gap-1.5 text-xs text-suave" title="Responsável"><Avatar nome={c.responsavel} foto={c.responsavelFoto ?? null} tamanho={26} /><span className="hidden lg:inline">{c.responsavel.split(' ')[0]}</span></span>
                )}
                {tempo && visao === 'equipe' && <span className="inline-flex items-center gap-1 rounded-full bg-alerta/10 px-2 py-0.5 text-xs font-semibold text-alerta" title="Esperando resposta nossa"><Clock size={13} aria-hidden="true" />{tempo}</span>}
              </div>
            </a>
          </li>
        );
      })}
    </ul>
  );
}
