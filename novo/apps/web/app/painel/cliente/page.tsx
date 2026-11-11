'use client';

import type { ClienteDetalhe, ListaChamados as Lista } from '@primehub/shared';
import { ArrowLeft, CalendarDays, Download, ExternalLink, FileText, LifeBuoy, Mail, Pencil, Phone, Plus, Users } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Casca } from '@/components/casca';
import { ListaChamados } from '@/components/chamados';
import { Esqueleto, EsqueletoLista } from '@/components/esqueleto';
import { FormCliente } from '@/components/form-cliente';
import { NovoChamado } from '@/components/novo-chamado';
import { BotaoPrimario, BotaoSecundario, Painel, Vazio, dataBR, tamanhoArquivo } from '@/components/ui/basico';
import { LogoEmpresa } from '@/components/ui/logo-empresa';
import { api, arquivoPublico } from '@/lib/api';
import { usuarioSalvo } from '@/lib/sessao';

const ABAS = [
  { id: 'geral', rotulo: 'Visão geral' },
  { id: 'chamados', rotulo: 'Chamados' },
  { id: 'setores', rotulo: 'Setores' },
  { id: 'pessoas', rotulo: 'Usuários' },
  { id: 'documentos', rotulo: 'Documentos' },
  { id: 'agenda', rotulo: 'Agenda' },
] as const;
type Aba = (typeof ABAS)[number]['id'];

const ROTULO_TIPO: Record<string, string> = { treinamento: 'Treinamento', reuniao: 'Reunião', conversa: 'Conversa', auxilio: 'Auxílio' };

export default function PaginaCliente() {
  const [id, setId] = useState(0);
  const [dados, setDados] = useState<ClienteDetalhe | null>(null);
  const [chamados, setChamados] = useState<Lista | null>(null);
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
    if (a && ABAS.some((x) => x.id === a)) setAba(a);
    setId(cid);
    if (!cid) { setErro('Cliente não informado.'); return; }
    api<ClienteDetalhe>(`/api/clientes/${cid}`).then(setDados).catch((e: unknown) => setErro(e instanceof Error ? e.message : 'Não consegui abrir esse cliente.'));
    api<Lista>(`/api/chamados?${new URLSearchParams({ cliente: String(cid), status: '' })}`).then(setChamados).catch(() => setChamados({ itens: [], total: 0 }));
  }, []);

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

          <div className="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1" role="tablist" aria-label="Seções do cliente">
            {ABAS.map((a) => (
              <button key={a.id} type="button" role="tab" aria-selected={aba === a.id} onClick={() => trocarAba(a.id)}
                className={`toque shrink-0 rounded-full px-4 text-sm font-semibold ${aba === a.id ? 'gradiente-marca text-white shadow' : 'border border-borda bg-superficie text-suave'}`}>
                {a.rotulo}
              </button>
            ))}
          </div>

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

          {aba === 'chamados' && (!chamados ? <EsqueletoLista /> : (
            <>
              <p className="text-sm text-suave">{chamados.total} chamado(s){chamados.total > chamados.itens.length ? ` · mostrando ${chamados.itens.length}` : ''}</p>
              <ListaChamados itens={chamados.itens} visao="equipe" />
            </>
          ))}

          {aba === 'setores' && (dados.setores.length ? (
            <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              {dados.setores.map((s) => (
                <li key={s.id} className="flex items-center gap-3 rounded-3xl border border-borda bg-superficie p-4 shadow-sm">
                  <span className="gradiente-marca flex h-11 w-11 items-center justify-center rounded-2xl text-white"><LifeBuoy size={20} aria-hidden="true" /></span>
                  <div className="min-w-0 flex-1">
                    <p className="truncate font-semibold text-texto">{s.nome}</p>
                    <p className="text-sm text-suave">{s.ativo ? 'Ativo' : 'Desativado'} · {s.pendentes} pendente(s)</p>
                  </div>
                </li>
              ))}
            </ul>
          ) : <Vazio>Esse cliente ainda não tem setores.</Vazio>)}

          {aba === 'pessoas' && (dados.pessoas.length ? (
            <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              {dados.pessoas.map((p) => (
                <li key={p.id} className="flex items-center gap-3 rounded-3xl border border-borda bg-superficie p-4 shadow-sm">
                  {p.foto ? <img src={arquivoPublico(p.foto) ?? ''} alt="" className="h-11 w-11 rounded-full object-cover" /> : <span aria-hidden="true" className="gradiente-marca flex h-11 w-11 items-center justify-center rounded-full text-sm font-bold text-white">{p.nome.slice(0, 1).toUpperCase()}</span>}
                  <div className="min-w-0 flex-1">
                    <p className="truncate font-semibold text-texto">{p.nome}</p>
                    <p className="truncate text-sm text-suave">{p.setor ?? 'Sem setor'}{p.email ? ` · ${p.email}` : ''}</p>
                  </div>
                  <span className={`shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold ${p.portalAtivo ? 'bg-sucesso/15 text-sucesso' : 'bg-fundo text-suave'}`}>{p.portalAtivo ? 'Portal ativo' : 'Sem acesso'}</span>
                </li>
              ))}
            </ul>
          ) : <Vazio>Nenhum usuário cadastrado.</Vazio>)}

          {aba === 'documentos' && (dados.documentos.length ? (
            <ul className="space-y-2">
              {dados.documentos.map((d) => (
                <li key={d.id}>
                  <a href={`/download.php?id=${d.id}`} className="toque flex items-center gap-3 rounded-2xl border border-borda bg-superficie p-4 shadow-sm hover:border-primaria/50">
                    <FileText size={22} className="shrink-0 text-primaria" aria-hidden="true" />
                    <div className="min-w-0 flex-1">
                      <p className="truncate font-semibold text-texto">{d.titulo}</p>
                      <p className="truncate text-sm text-suave">{[d.categoria, d.setor, dataBR(d.criadoEm), tamanhoArquivo(d.tamanho)].filter(Boolean).join(' · ')}</p>
                    </div>
                    <Download size={20} className="shrink-0 text-suave" aria-hidden="true" />
                  </a>
                </li>
              ))}
            </ul>
          ) : <Vazio>Nenhum documento enviado.</Vazio>)}

          {aba === 'agenda' && (dados.treinamentos.length ? (
            <ul className="space-y-2">
              {dados.treinamentos.map((t) => (
                <li key={t.id} className="flex items-center gap-3 rounded-2xl border border-borda bg-superficie p-4 shadow-sm">
                  <CalendarDays size={22} className="shrink-0 text-primaria" aria-hidden="true" />
                  <div className="min-w-0 flex-1">
                    <p className="truncate font-semibold text-texto">{t.titulo}</p>
                    <p className="truncate text-sm text-suave">{[ROTULO_TIPO[t.tipo ?? ''] ?? t.tipo, t.setor, dataBR(t.inicio, true), t.status].filter(Boolean).join(' · ')}</p>
                  </div>
                  {t.link && <a href={t.link} target="_blank" rel="noopener noreferrer" className="toque inline-flex items-center gap-1 rounded-xl border border-borda px-3 text-sm font-semibold text-primaria"><ExternalLink size={16} aria-hidden="true" />Entrar</a>}
                </li>
              ))}
            </ul>
          ) : <Vazio>Nada agendado para esse cliente.</Vazio>)}
        </div>
      )}
    </Casca>
  );
}
