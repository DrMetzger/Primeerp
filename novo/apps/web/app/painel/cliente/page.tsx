'use client';

import type { ClienteDetalhe, ListaChamados as Lista } from '@primehub/shared';
import { ArrowLeft, Building2, CalendarDays, FileText, KeyRound, LayoutDashboard, LifeBuoy, Mail, Pencil, Phone, Plus, ShieldCheck, Siren, Users } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Casca } from '@/components/casca';
import { SecaoAgendaCliente, SecaoDocumentos, SecaoEquipePrime, SecaoPessoas, SecaoRisco, SecaoSetores } from '@/components/cliente-secoes';
import { AbasIcones } from '@/components/ui/abas-icones';
import { AtalhosProtheus, SecaoAcessos } from '@/components/cliente-acessos';
import { ListaChamados } from '@/components/chamados';
import { Esqueleto, EsqueletoLista } from '@/components/esqueleto';
import { FormCliente } from '@/components/form-cliente';
import { NovoChamado } from '@/components/novo-chamado';
import { BotaoPrimario, BotaoSecundario, Painel, dataBR } from '@/components/ui/basico';
import { LogoEmpresa } from '@/components/ui/logo-empresa';
import { api } from '@/lib/api';
import { usuarioSalvo } from '@/lib/sessao';

type Aba = 'geral' | 'chamados' | 'acessos' | 'setores' | 'pessoas' | 'equipe' | 'documentos' | 'agenda' | 'risco';
const IDS_ABAS: Aba[] = ['geral', 'chamados', 'acessos', 'setores', 'pessoas', 'equipe', 'documentos', 'agenda', 'risco'];


export default function PaginaCliente() {
  const [id, setId] = useState(0);
  const [dados, setDados] = useState<ClienteDetalhe | null>(null);
  const [chamados, setChamados] = useState<Lista | null>(null);
  const [filtroCh, setFiltroCh] = useState<string>('nossos');
  const [erro, setErro] = useState('');
  const [aba, setAba] = useState<Aba>('geral');
  const [editar, setEditar] = useState(false);
  const [novoChamado, setNovoChamado] = useState(false);
  const [admin, setAdmin] = useState(false);

  useEffect(() => {
    setAdmin(['admin', 'diretor', 'ceo'].includes(usuarioSalvo()?.perfil ?? ''));
    const q = new URLSearchParams(location.search);
    const cid = Number(q.get('id') ?? 0);
    const a = (location.hash.slice(1) || q.get('aba')) as Aba | null;
    if (a && IDS_ABAS.includes(a)) setAba(a);
    setId(cid);
    if (!cid) { setErro('Cliente não informado.'); return; }
    recarregarCliente(cid);
  }, []);

  function carregarChamados(cid: number, st: string) {
    setChamados(null);
    api<Lista>(`/api/chamados?${new URLSearchParams({ cliente: String(cid), status: st })}`).then(setChamados).catch(() => setChamados({ itens: [], total: 0 }));
  }
  useEffect(() => { if (id) carregarChamados(id, filtroCh); }, [id, filtroCh]);

  function recarregarCliente(cid = id) {
    api<ClienteDetalhe>(`/api/clientes/${cid}`).then(setDados).catch((e: unknown) => setErro(e instanceof Error ? e.message : 'Não consegui abrir esse cliente.'));
  }

  function trocarAba(a: Aba) { setAba(a); history.replaceState(null, '', `#${a}`); }

  const acoes = (
    <a href="/painel/clientes" className="toque inline-flex items-center gap-2 rounded-xl border border-borda bg-superficie px-3 text-sm font-semibold text-texto">
      <ArrowLeft size={18} aria-hidden="true" /> <span className="hidden sm:inline">Clientes</span>
    </a>
  );

  const c = dados?.cliente;
  return (
    <Casca tipo="equipe" titulo={c?.nome ?? 'Cliente'} acoes={acoes}>
      {editar && c && <FormCliente cliente={c} equipe={dados.equipe} aoFechar={() => setEditar(false)} />}
      {novoChamado && <NovoChamado visao="equipe" clienteInicial={id} aoFechar={() => setNovoChamado(false)} />}
      {erro && <Painel><p className="text-center text-suave">{erro}</p></Painel>}
      {!erro && !dados && <div role="status" aria-label="Carregando o cliente" className="space-y-4"><Esqueleto className="h-36" /><Esqueleto className="h-12" /><EsqueletoLista linhas={4} /></div>}
      {c && dados && (
        <div className="space-y-4">
          <Painel>
            <div className="flex flex-col gap-4 sm:flex-row sm:items-center">
              <LogoEmpresa nome={c.nome} logo={c.logo} tamanho="g" />
              <div className="min-w-0 flex-1">
                <h2 className="text-xl font-bold text-texto">{c.nome}</h2>
                <p className="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm text-suave">
                  {c.contatoNome && <span className="inline-flex items-center gap-1"><Users size={14} aria-hidden="true" />{c.contatoNome}</span>}
                  {c.contatoEmail && <a href={`mailto:${c.contatoEmail}`} className="inline-flex items-center gap-1 hover:text-primaria"><Mail size={14} aria-hidden="true" />{c.contatoEmail}</a>}
                  {c.contatoTelefone && <a href={`https://wa.me/55${c.contatoTelefone}`} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 hover:text-primaria"><Phone size={14} aria-hidden="true" />{c.contatoTelefone}</a>}
                </p>
              </div>
              <div className="flex gap-2">
                <BotaoPrimario onClick={() => setNovoChamado(true)}><Plus size={18} aria-hidden="true" /> Chamado</BotaoPrimario>
                {admin && <BotaoSecundario onClick={() => setEditar(true)} aria-label="Editar cliente"><Pencil size={18} aria-hidden="true" /> <span className="hidden sm:inline">Editar</span></BotaoSecundario>}
              </div>
            </div>
          </Painel>

          <AbasIcones ativa={aba} aoTrocar={trocarAba} abas={[
            { id: 'geral', rotulo: 'Visão geral', icone: LayoutDashboard, cor: '#6366f1' },
            { id: 'chamados', rotulo: 'Chamados', icone: LifeBuoy, cor: '#0ea5e9', contagem: c.pendentes },
            { id: 'acessos', rotulo: 'Acessos', icone: KeyRound, cor: '#eab308' },
            { id: 'setores', rotulo: 'Setores', icone: Building2, cor: '#10b981', contagem: dados.setores.length },
            { id: 'pessoas', rotulo: 'Usuários do cliente', icone: Users, cor: '#8b5cf6', contagem: dados.pessoas.length },
            { id: 'equipe', rotulo: 'Equipe Prime', icone: ShieldCheck, cor: '#06b6d4', contagem: new Set(dados.vinculos.map((v) => v.usuarioId)).size },
            { id: 'documentos', rotulo: 'Documentos', icone: FileText, cor: '#f59e0b', contagem: dados.documentos.length },
            { id: 'agenda', rotulo: 'Calendário', icone: CalendarDays, cor: '#ec4899', contagem: dados.treinamentos.length },
            { id: 'risco', rotulo: 'Risco & melhorias (IA)', icone: Siren, cor: '#f43f5e', contagem: dados.analises.length },
          ]} />

          {aba === 'geral' && <AtalhosProtheus clienteId={id} aoVerTudo={() => trocarAba('acessos')} />}
          {aba === 'geral' && (
            <div className="grid gap-4 lg:grid-cols-3">
              <div className="grid grid-cols-2 gap-3 lg:col-span-2 sm:grid-cols-4">
                {[
                  { r: 'Pendentes', v: c.pendentes, cor: c.pendentes ? 'text-perigo' : 'text-texto' },
                  { r: 'Urgentes', v: c.urgentes, cor: c.urgentes ? 'text-perigo' : 'text-texto' },
                  { r: 'Chamados', v: c.totalChamados, cor: 'text-texto' },
                  { r: 'Documentos', v: c.documentos, cor: 'text-texto' },
                  { r: 'Treinamentos', v: c.treinamentos, cor: 'text-texto' },
                  { r: 'KPIs', v: c.kpis, cor: 'text-texto' },
                  { r: 'Setores', v: dados.setores.length, cor: 'text-texto' },
                  { r: 'Usuários', v: dados.pessoas.length, cor: 'text-texto' },
                ].map((k) => (
                  <div key={k.r} className="rounded-3xl border border-borda bg-superficie p-4 shadow-sm">
                    <p className="text-sm text-suave">{k.r}</p>
                    <p className={`mt-1 text-3xl font-bold tabular-nums ${k.cor}`}>{k.v}</p>
                  </div>
                ))}
              </div>
              <Painel titulo="Cadastro">
                <dl className="space-y-3 text-sm">
                  <div><dt className="text-suave">Situação</dt><dd className="font-semibold capitalize text-texto">{c.status}</dd></div>
                  <div><dt className="text-suave">Responsável na Prime</dt><dd className="text-texto">{c.responsavelInterno ?? '—'}</dd></div>
                  <div><dt className="text-suave">CNPJ</dt><dd className="text-texto">{c.cnpj ?? '—'}</dd></div>
                  <div><dt className="text-suave">Cliente desde</dt><dd className="text-texto">{dataBR(c.criadoEm)}</dd></div>
                  {c.observacoes && <div><dt className="text-suave">Observações</dt><dd className="whitespace-pre-wrap text-texto">{c.observacoes}</dd></div>}
                </dl>
              </Painel>
              <Painel titulo="Chamados recentes" className="lg:col-span-3" acao={<button type="button" onClick={() => trocarAba('chamados')} className="text-sm font-semibold text-primaria">Ver todos</button>}>
                {!chamados ? <EsqueletoLista linhas={3} /> : <ListaChamados itens={chamados.itens.slice(0, 5)} visao="equipe" />}
              </Painel>
            </div>
          )}

          {aba === 'chamados' && (
            <div className="space-y-3">
              <div className="flex flex-wrap gap-2">
                {([['nossos', 'Pendentes com a gente'], ['aguardando_cliente', 'Aguardando cliente'], ['fechado', 'Fechados'], ['', 'Todos']] as const).map(([v, r]) => (
                  <button key={v || 'todos'} type="button" onClick={() => setFiltroCh(v)}
                    className={`toque rounded-full px-3 py-1.5 text-sm font-medium transition-colors ${filtroCh === v ? 'bg-primaria text-white' : 'border border-borda text-suave hover:text-texto'}`}>
                    {r}
                  </button>
                ))}
              </div>
              {!chamados ? <EsqueletoLista /> : chamados.itens.length === 0 ? (
                <p className="rounded-2xl border border-dashed border-borda p-10 text-center text-suave">Nenhum chamado {filtroCh === 'nossos' ? 'pendente com a gente' : filtroCh === 'aguardando_cliente' ? 'aguardando o cliente' : filtroCh === 'fechado' ? 'fechado' : ''} por aqui.</p>
              ) : (
                <>
                  <p className="text-sm text-suave">{chamados.total} chamado(s){chamados.total > chamados.itens.length ? ` · mostrando ${chamados.itens.length}` : ''}</p>
                  <ListaChamados itens={chamados.itens} visao="equipe" />
                </>
              )}
            </div>
          )}

          {aba === 'acessos' && <SecaoAcessos clienteId={id} />}
          {aba === 'setores' && <SecaoSetores clienteId={id} dados={dados} recarregar={() => recarregarCliente()} />}

          {aba === 'pessoas' && <SecaoPessoas clienteId={id} dados={dados} recarregar={() => recarregarCliente()} />}

          {aba === 'documentos' && <SecaoDocumentos clienteId={id} dados={dados} recarregar={() => recarregarCliente()} />}

          {aba === 'equipe' && <SecaoEquipePrime clienteId={id} dados={dados} recarregar={() => recarregarCliente()} />}
          {aba === 'risco' && <SecaoRisco clienteId={id} dados={dados} recarregar={() => recarregarCliente()} />}
          {aba === 'agenda' && <SecaoAgendaCliente clienteId={id} dados={dados} />}
        </div>
      )}
    </Casca>
  );
}
