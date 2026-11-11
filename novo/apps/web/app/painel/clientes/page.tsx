'use client';

import type { ClienteResumo } from '@primehub/shared';
import { AlertTriangle, FileText, LifeBuoy, Plus, Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Casca } from '@/components/casca';
import { EsqueletoLista } from '@/components/esqueleto';
import { FormCliente } from '@/components/form-cliente';
import { Vazio } from '@/components/ui/basico';
import { LogoEmpresa } from '@/components/ui/logo-empresa';
import { api } from '@/lib/api';
import { usuarioSalvo } from '@/lib/sessao';

const ROTULO_STATUS: Record<string, string> = { ativo: 'Ativo', pausado: 'Pausado', inativo: 'Inativo' };

export default function PaginaClientes() {
  const [busca, setBusca] = useState('');
  const [itens, setItens] = useState<ClienteResumo[] | null>(null);
  const [novo, setNovo] = useState(false);
  const [admin, setAdmin] = useState(false);

  useEffect(() => { setAdmin(['admin', 'diretor', 'ceo'].includes(usuarioSalvo()?.perfil ?? '')); }, []);

  useEffect(() => {
    setItens(null);
    const t = setTimeout(() => {
      api<ClienteResumo[]>(`/api/clientes?${new URLSearchParams({ busca })}`).then(setItens).catch(() => setItens([]));
    }, busca ? 300 : 0);
    return () => clearTimeout(t);
  }, [busca]);

  const acoes = admin ? (
    <button type="button" onClick={() => setNovo(true)} className="gradiente-marca toque inline-flex items-center gap-2 rounded-xl px-4 font-semibold text-white shadow">
      <Plus size={20} aria-hidden="true" /> <span className="hidden sm:inline">Novo cliente</span>
    </button>
  ) : undefined;

  return (
    <Casca tipo="equipe" titulo="Clientes" acoes={acoes}>
      {novo && <FormCliente aoFechar={() => setNovo(false)} />}
      <label className="relative mb-4 block">
        <span className="sr-only">Buscar cliente</span>
        <Search size={20} className="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-suave" aria-hidden="true" />
        <input type="search" value={busca} onChange={(e) => setBusca(e.target.value)} placeholder="Buscar por empresa, contato ou e-mail"
          className="toque w-full rounded-2xl border border-borda bg-superficie pl-12 pr-4 text-base text-texto outline-none focus:border-primaria" />
      </label>
      {!itens ? <EsqueletoLista /> : !itens.length ? <Vazio>Nenhum cliente encontrado.</Vazio> : (
        <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          {itens.map((c) => (
            <li key={c.id}>
              <a href={`/painel/cliente?id=${c.id}`} className="flex h-full flex-col gap-4 rounded-3xl border border-borda bg-superficie p-4 shadow-sm transition hover:border-primaria/50 hover:shadow-md">
                <div className="flex items-center gap-3">
                  <LogoEmpresa nome={c.nome} logo={c.logo} tamanho="m" />
                  <div className="min-w-0 flex-1">
                    <p className="truncate font-semibold text-texto">{c.nome}</p>
                    <p className="truncate text-sm text-suave">{c.contatoNome ?? c.contatoEmail ?? 'Sem contato cadastrado'}</p>
                  </div>
                  <span className={`shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold ${c.status === 'ativo' ? 'bg-sucesso/15 text-sucesso' : 'bg-alerta/15 text-alerta'}`}>{ROTULO_STATUS[c.status] ?? c.status}</span>
                </div>
                <div className="mt-auto grid grid-cols-3 gap-2 text-center">
                  <div className={`rounded-2xl p-2 ${c.pendentes ? 'bg-perigo/10' : 'bg-fundo'}`}>
                    <p className={`text-lg font-bold tabular-nums ${c.pendentes ? 'text-perigo' : 'text-texto'}`}>{c.pendentes}</p>
                    <p className="flex items-center justify-center gap-1 text-[11px] text-suave"><LifeBuoy size={12} aria-hidden="true" />pendentes</p>
                  </div>
                  <div className="rounded-2xl bg-fundo p-2">
                    <p className="text-lg font-bold tabular-nums text-texto">{c.totalChamados}</p>
                    <p className="text-[11px] text-suave">chamados</p>
                  </div>
                  <div className="rounded-2xl bg-fundo p-2">
                    <p className="text-lg font-bold tabular-nums text-texto">{c.documentos}</p>
                    <p className="flex items-center justify-center gap-1 text-[11px] text-suave"><FileText size={12} aria-hidden="true" />docs</p>
                  </div>
                </div>
                {c.urgentes > 0 && <p className="flex items-center gap-1.5 text-sm font-semibold text-perigo"><AlertTriangle size={16} aria-hidden="true" />{c.urgentes} urgente(s) esperando</p>}
              </a>
            </li>
          ))}
        </ul>
      )}
    </Casca>
  );
}
