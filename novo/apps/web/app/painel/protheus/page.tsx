'use client';

import { alertasVm, type AlvoCompilacao, type PainelVm, type ServicoVm, type TarefaVm } from '@primehub/shared';
import { Activity, CheckCircle2, CircleDashed, Cpu, Loader2, MinusCircle, Power, PowerOff, XCircle, Database, ExternalLink, HardDrive, KeyRound, MemoryStick, Play, RefreshCw, ScrollText, Server, Square, RotateCw, FolderSync, Plus, Trash2, Eye, EyeOff } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { Casca } from '@/components/casca';
import { Esqueleto } from '@/components/esqueleto';
import { Aviso, BotaoPrimario, BotaoSecundario, Campo, CLASSE_ENTRADA, Modal, Vazio } from '@/components/ui/basico';
import { api } from '@/lib/api';

const ICONE: Record<string, typeof Server> = { appserver: Server, dbaccess: Database, license: KeyRound, postgresql: Database, syncthing: FolderSync };
const COR: Record<string, string> = { appserver: '#0ea5e9', dbaccess: '#8b5cf6', license: '#f59e0b', postgresql: '#336791', syncthing: '#10b981' };

function desde(v: string | null): string {
  if (!v) return '—';
  const d = new Date(v.replace(/^\w{3} /, '').replace(/ -?\d{2}$/, ''));
  if (Number.isNaN(d.getTime())) return v;
  const min = Math.round((Date.now() - d.getTime()) / 60000);
  return min < 60 ? `há ${min} min` : min < 1440 ? `há ${Math.round(min / 60)} h` : `há ${Math.round(min / 1440)} dias`;
}

/** Painel do Protheus 2510 na VM: status, iniciar/parar/reiniciar e log de cada serviço. */
export default function PaginaProtheus() {
  const [d, setD] = useState<PainelVm | null>(null);
  const [erro, setErro] = useState('');
  const [ocupado, setOcupado] = useState('');
  const [aviso, setAviso] = useState<{ t: string; e: boolean }>({ t: '', e: false });
  const [log, setLog] = useState<{ nome: string; linhas: string[] | null } | null>(null);
  const [tarefas, setTarefas] = useState<TarefaVm[]>([]);
  const rodando = tarefas.some((t) => t.estado === 'rodando');

  const carregarTarefas = useCallback(() => api<{ tarefas: TarefaVm[] }>('/api/vm/tarefas').then((r) => setTarefas(r.tarefas)).catch(() => undefined), []);
  useEffect(() => { void carregarTarefas(); }, [carregarTarefas]);
  // Enquanto tem tarefa rodando, acompanha de perto (pipeline ao vivo).
  useEffect(() => {
    if (!rodando) return;
    const t = setInterval(() => { void carregarTarefas(); void carregar(); }, 1500);
    return () => clearInterval(t);
  }, [rodando, carregarTarefas]); // eslint-disable-line react-hooks/exhaustive-deps

  const carregar = useCallback(() => api<PainelVm>('/api/vm/servicos').then((x) => { setD(x); setErro(''); }).catch((e: unknown) => setErro(e instanceof Error ? e.message : 'Não consegui falar com a VM.')), []);
  useEffect(() => { void carregar(); const t = setInterval(() => void carregar(), 15_000); return () => clearInterval(t); }, [carregar]);

  async function agir(s: { id: string; nome: string; critico: boolean }, acao: 'iniciar' | 'parar' | 'reiniciar') {
    const todos = s.id === 'todos';
    if (acao !== 'iniciar' && !confirm(`${acao === 'parar' ? 'Parar' : 'Reiniciar'} ${todos ? 'TODOS os serviços do Protheus' : `o ${s.nome}`}?${s.critico ? ' O Protheus fica fora do ar enquanto isso.' : ''}`)) return;
    setOcupado(`${s.id}:${acao}`); setAviso({ t: '', e: false });
    try { const t = await api<TarefaVm>(`/api/vm/servicos/${s.id}/${acao}`, { method: 'POST', body: '{}' }); setTarefas((l) => [t, ...l.filter((x) => x.id !== t.id)]); }
    catch (e) { setAviso({ t: e instanceof Error ? e.message : 'Não deu certo.', e: true }); }
    setOcupado('');
  }
  async function verLog(s: ServicoVm) {
    setLog({ nome: s.nome, linhas: null });
    try { const r = await api<{ linhas: string[] }>(`/api/vm/servicos/${s.id}/log`); setLog({ nome: s.nome, linhas: r.linhas }); }
    catch (e) { setLog({ nome: s.nome, linhas: [e instanceof Error ? e.message : 'Não deu para ler o log.'] }); }
  }

  const v = d?.versoes ?? {};
  const todosNoAr = d?.servicos.filter((s) => s.critico).every((s) => s.estado === 'active');
  const alertas = d ? alertasVm(d) : [];
  const temErro = alertas.some((x) => x.nivel === 'erro');
  return (
    <Casca tipo="equipe" titulo="Protheus 2510" acoes={<BotaoSecundario onClick={() => void carregar()} aria-label="Atualizar"><RefreshCw size={18} aria-hidden="true" /></BotaoSecundario>}>
      <div className="mx-auto max-w-6xl space-y-6">
        {erro && <Aviso texto={erro} erro />}
        {!d ? (erro ? null : <div className="space-y-3"><Esqueleto className="h-40" /><Esqueleto className="h-64" /></div>) : (
          <>
            <section className="relative overflow-hidden rounded-3xl border border-borda bg-superficie p-6 shadow-sm">
              <div className="flex items-start gap-4">
                <span className="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl text-white shadow-lg" style={{ background: 'linear-gradient(135deg,#0ea5e9,#1e3fd8)' }}><Server size={28} aria-hidden="true" /></span>
                <div className="min-w-0 flex-1">
                  <h2 className="text-2xl font-bold text-texto">Protheus {v.protheus ?? '12.1.2510'}</h2>
                  <p className="text-sm text-suave">Ambiente <b className="text-texto">{v.ambiente ?? 'PRIMEERP'}</b> · base de desenvolvimento na VM</p>
                  <a href="#atencao" className={`mt-2 inline-flex max-w-full items-center gap-2 rounded-full px-3 py-1 text-sm font-bold ${!alertas.length ? 'bg-sucesso/15 text-sucesso' : temErro ? 'bg-perigo/15 text-perigo' : 'bg-alerta/15 text-alerta'}`}>
                    <span className={`h-2.5 w-2.5 shrink-0 rounded-full ${!alertas.length ? 'bg-sucesso' : temErro ? 'bg-perigo' : 'bg-alerta'} animate-pulse`} aria-hidden="true" />
                    <span className="truncate">{!alertas.length ? 'Tudo no ar' : `${alertas[0]?.titulo}${alertas.length > 1 ? ` (+${alertas.length - 1})` : ''}`}</span>
                  </a>
                </div>
              </div>
              <div className="mt-4 flex flex-wrap items-center justify-end gap-2">
                {!todosNoAr && <BotaoSecundario disabled={!!ocupado || rodando} onClick={() => void agir({ id: 'todos', nome: 'todos', critico: true }, 'iniciar')} className="text-sucesso"><Power size={16} aria-hidden="true" /> Iniciar todos</BotaoSecundario>}
                <BotaoSecundario disabled={!!ocupado || rodando} onClick={() => void agir({ id: 'todos', nome: 'todos', critico: true }, 'reiniciar')}><RotateCw size={16} aria-hidden="true" /> Reiniciar todos</BotaoSecundario>
                <BotaoSecundario disabled={!!ocupado || rodando} onClick={() => void agir({ id: 'todos', nome: 'todos', critico: true }, 'parar')} className="text-perigo"><PowerOff size={16} aria-hidden="true" /> Parar todos</BotaoSecundario>
                <a href={v.webappUrl ?? 'https://protheus.primeerp.com.br'} target="_blank" rel="noopener noreferrer" className="gradiente-marca toque inline-flex items-center gap-2 rounded-xl px-4 font-semibold text-white shadow"><ExternalLink size={18} aria-hidden="true" /> Abrir o Protheus</a>
              </div>
              <dl className="mt-5 grid grid-cols-2 gap-2 sm:grid-cols-3 2xl:grid-cols-6">
                {([['RPO', `${v.protheus ?? '—'}${v.rpoData ? ` · ${v.rpoData}` : ''}`], ['AppServer', `${v.appserver ?? '—'}${v.appserverBuild ? ` (${v.appserverBuild})` : ''}`], ['Webapp', v.webapp || '—'], ['DBAccess', v.dbaccess || '—'], ['License Server', v.licenseServer || '—'], ['PostgreSQL', v.postgresql || '—']] as const).map(([r, x]) => (
                  <div key={r} className="rounded-2xl bg-superficie-alta/60 px-3 py-2"><dt className="text-xs font-semibold uppercase text-suave">{r}</dt><dd className="truncate font-mono text-sm text-texto" title={x}>{x}</dd></div>
                ))}
              </dl>
            </section>

            <section className="grid gap-3 sm:grid-cols-3">
              {([[MemoryStick, 'Memória', `${(d.vm.memoriaUsadaMB / 1024).toFixed(1)} de ${(d.vm.memoriaTotalMB / 1024).toFixed(1)} GB`, d.vm.memoriaUsadaMB / d.vm.memoriaTotalMB], [HardDrive, 'Disco', `${d.vm.discoUsadoGB} de ${d.vm.discoTotalGB} GB`, d.vm.discoUsadoGB / d.vm.discoTotalGB], [Cpu, 'Processador', `carga ${d.vm.carga[0]} · ${d.vm.nucleos} núcleos`, Math.min(1, (d.vm.carga[0] ?? 0) / d.vm.nucleos)]] as const).map(([I, r, t, p]) => (
                <div key={r} className="rounded-3xl border border-borda bg-superficie p-4 shadow-sm">
                  <p className="flex items-center gap-2 text-sm font-semibold text-suave"><I size={16} aria-hidden="true" />{r}</p>
                  <p className="mt-1 font-bold text-texto">{t}</p>
                  <div className="mt-2 h-2 overflow-hidden rounded-full bg-superficie-alta"><div className="h-2 rounded-full" style={{ width: `${Math.round(p * 100)}%`, background: p > 0.85 ? 'var(--perigo)' : p > 0.65 ? 'var(--alerta)' : 'var(--sucesso)' }} /></div>
                </div>
              ))}
            </section>

            {alertas.length > 0 && (
              <section id="atencao" className="space-y-2 rounded-3xl border border-borda bg-superficie p-4 shadow-sm">
                <h2 className="font-bold text-texto">O que precisa de atenção ({alertas.length})</h2>
                <ul className="space-y-2">
                  {alertas.map((x) => (
                    <li key={x.titulo} className={`flex items-start gap-3 rounded-2xl border p-3 ${x.nivel === 'erro' ? 'border-perigo/40 bg-perigo/10' : 'border-alerta/40 bg-alerta/10'}`}>
                      {x.nivel === 'erro' ? <XCircle size={20} className="mt-0.5 shrink-0 text-perigo" aria-hidden="true" /> : <Activity size={20} className="mt-0.5 shrink-0 text-alerta" aria-hidden="true" />}
                      <div className="min-w-0 flex-1"><p className="font-semibold text-texto">{x.titulo}</p><p className="text-sm text-suave">{x.detalhe}</p></div>
                    </li>
                  ))}
                </ul>
              </section>
            )}
            <Aviso texto={aviso.t} erro={aviso.e} />
            <Pipeline tarefas={tarefas} />
            <section className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
              {d.servicos.map((s) => {
                const I = ICONE[s.id] ?? Activity;
                const ativo = s.estado === 'active';
                return (
                  <article key={s.id} className="flex flex-col gap-3 rounded-3xl border border-borda bg-superficie p-4 shadow-sm" style={{ borderTop: `4px solid ${COR[s.id] ?? '#0ea5e9'}` }}>
                    <div className="flex items-center gap-3">
                      <span className="flex h-11 w-11 items-center justify-center rounded-2xl text-white" style={{ background: COR[s.id] ?? '#0ea5e9' }}><I size={20} aria-hidden="true" /></span>
                      <div className="min-w-0 flex-1"><p className="font-bold text-texto">{s.nome}</p><p className="truncate text-xs text-suave">{s.descricao}</p></div>
                      <span className={`rounded-full px-3 py-1 text-xs font-bold ${ativo ? 'bg-sucesso/15 text-sucesso' : s.estado === 'failed' ? 'bg-perigo/15 text-perigo' : 'bg-superficie-alta text-suave'}`}>
                        {ativo ? 'No ar' : s.estado === 'failed' ? 'Com erro' : s.estado === 'activating' ? 'Subindo' : s.estado === 'deactivating' ? 'Parando' : 'Parado'}
                      </span>
                    </div>
                    <dl className="grid grid-cols-3 gap-2 text-xs">
                      <div><dt className="text-suave">Ligado</dt><dd className="font-semibold text-texto">{ativo ? desde(s.desde) : '—'}</dd></div>
                      <div><dt className="text-suave">Memória</dt><dd className="font-semibold text-texto">{s.memoriaMB !== null ? `${s.memoriaMB} MB` : '—'}</dd></div>
                      <div><dt className="text-suave">Portas</dt><dd className="flex flex-wrap gap-1">{s.portas.map((p) => <span key={p.porta} className={`rounded px-1.5 font-mono font-semibold ${p.aberta ? 'bg-sucesso/15 text-sucesso' : 'bg-perigo/15 text-perigo'}`}>{p.porta}</span>)}</dd></div>
                    </dl>
                    <div className="mt-auto flex flex-wrap gap-2">
                      {!ativo && <BotaoSecundario disabled={!!ocupado || rodando} onClick={() => void agir(s, 'iniciar')} className="text-sucesso"><Play size={16} aria-hidden="true" /> {ocupado === `${s.id}:iniciar` ? 'Iniciando…' : 'Iniciar'}</BotaoSecundario>}
                      {ativo && <BotaoSecundario disabled={!!ocupado || rodando} onClick={() => void agir(s, 'reiniciar')}><RotateCw size={16} aria-hidden="true" /> {ocupado === `${s.id}:reiniciar` ? 'Reiniciando…' : 'Reiniciar'}</BotaoSecundario>}
                      {ativo && <BotaoSecundario disabled={!!ocupado || rodando} onClick={() => void agir(s, 'parar')} className="text-perigo"><Square size={16} aria-hidden="true" /> {ocupado === `${s.id}:parar` ? 'Parando…' : 'Parar'}</BotaoSecundario>}
                      <BotaoSecundario onClick={() => void verLog(s)} className="ml-auto"><ScrollText size={16} aria-hidden="true" /> Log</BotaoSecundario>
                    </div>
                  </article>
                );
              })}
            </section>
            <CofreCompilacao />
            <p className="text-center text-xs text-suave">Atualiza sozinho a cada 15 segundos · ações ficam registradas na VM</p>
          </>
        )}
        {!d && !erro && <Vazio>Conectando com a VM…</Vazio>}
      </div>
      {log && (
        <Modal titulo={`Log: ${log.nome}`} aoFechar={() => setLog(null)} largura="max-w-4xl">
          {!log.linhas ? <Esqueleto className="h-64" /> : <pre className="max-h-[60vh] overflow-auto whitespace-pre-wrap rounded-2xl bg-black/80 p-4 font-mono text-xs leading-relaxed text-emerald-200">{log.linhas.join('\n') || '(vazio)'}</pre>}
        </Modal>
      )}
    </Casca>
  );
}

const ICONE_ETAPA = { pendente: CircleDashed, rodando: Loader2, ok: CheckCircle2, erro: XCircle, pulada: MinusCircle } as const;
const COR_ETAPA = { pendente: 'text-suave', rodando: 'text-primaria', ok: 'text-sucesso', erro: 'text-perigo', pulada: 'text-suave' } as const;
const seg = (n: number | null) => (n === null ? '' : n < 60 ? `${n.toFixed(1)} s` : `${Math.floor(n / 60)} min ${Math.round(n % 60)} s`);

/** Pipeline da VM: cada ação vira etapas acompanhadas ao vivo (como uma esteira de CI). */
function Pipeline({ tarefas }: { tarefas: TarefaVm[] }) {
  const [abertas, setAbertas] = useState<Record<string, boolean>>({});
  if (!tarefas.length) return null;
  return (
    <section className="space-y-2 rounded-3xl border border-borda bg-superficie p-4 shadow-sm">
      <h2 className="flex items-center gap-2 font-bold text-texto"><Activity size={18} className="text-primaria" aria-hidden="true" /> Pipeline da VM</h2>
      <ul className="space-y-2">
        {tarefas.slice(0, 8).map((t, i) => {
          const aberta = abertas[t.id] ?? (i === 0 || t.estado !== 'ok');
          const feitas = t.etapas.filter((e) => e.estado === 'ok').length;
          const I = t.estado === 'rodando' ? Loader2 : t.estado === 'ok' ? CheckCircle2 : XCircle;
          return (
            <li key={t.id} className="rounded-2xl border border-borda bg-superficie-alta/40">
              <button type="button" onClick={() => setAbertas({ ...abertas, [t.id]: !aberta })} className="flex w-full items-center gap-3 px-3 py-2 text-left" aria-expanded={aberta}>
                <I size={18} className={`${t.estado === 'rodando' ? 'animate-spin text-primaria' : t.estado === 'ok' ? 'text-sucesso' : 'text-perigo'}`} aria-hidden="true" />
                <span className="min-w-0 flex-1"><span className="font-semibold text-texto">{t.titulo}</span><span className="ml-2 text-xs text-suave">por {t.quem} · {new Date(t.inicio * 1000).toLocaleTimeString('pt-BR')}</span></span>
                <span className="text-xs font-semibold text-suave">{feitas}/{t.etapas.length} · {seg(t.duracao)}</span>
              </button>
              <div className="mx-3 h-1.5 overflow-hidden rounded-full bg-superficie-alta"><div className={`h-1.5 rounded-full transition-all ${t.estado === 'erro' ? 'bg-perigo' : 'bg-sucesso'}`} style={{ width: `${Math.round((feitas / Math.max(1, t.etapas.length)) * 100)}%` }} /></div>
              {aberta && (
                <ol className="space-y-1 px-3 py-2">
                  {t.etapas.map((e, n) => {
                    const IE = ICONE_ETAPA[e.estado];
                    return (
                      <li key={n} className="flex items-center gap-2 text-sm">
                        <IE size={16} className={`${COR_ETAPA[e.estado]} ${e.estado === 'rodando' ? 'animate-spin' : ''}`} aria-hidden="true" />
                        <span className={`flex-1 ${e.estado === 'pendente' || e.estado === 'pulada' ? 'text-suave' : 'text-texto'}`}>{n + 1}. {e.nome}</span>
                        {e.detalhe && <span className={`truncate text-xs ${e.estado === 'erro' ? 'text-perigo' : 'text-suave'}`} title={e.detalhe}>{e.detalhe}</span>}
                        <span className="w-16 text-right font-mono text-xs text-suave">{seg(e.duracao)}</span>
                      </li>
                    );
                  })}
                </ol>
              )}
            </li>
          );
        })}
      </ul>
    </section>
  );
}

/** Servidores de compilação (como a lista do VSCode), agrupados por empresa.
 *  A senha fica cifrada no cofre; o compilador usa ela para conectar. */
function CofreCompilacao() {
  const [alvos, setAlvos] = useState<AlvoCompilacao[] | null>(null);
  const [edit, setEdit] = useState<Record<string, Partial<AlvoCompilacao> & { senha?: string }>>({});
  const [aberto, setAberto] = useState<string>('');
  const [ocupado, setOcupado] = useState('');
  const [teste, setTeste] = useState<Record<string, { testando?: boolean; conectado?: boolean; detalhe?: string; build?: string }>>({});
  const [novo, setNovo] = useState<{ grupo: string; nome: string; host: string; porta: string; ambiente: string; usuario: string; senha: string } | null>(null);
  const [verSenha, setVerSenha] = useState<Record<string, boolean>>({});
  const [aviso, setAviso] = useState<{ t: string; e: boolean }>({ t: '', e: false });

  const carregar = useCallback(() => { api<AlvoCompilacao[]>('/api/compilacao/alvos').then(setAlvos).catch(() => setAlvos([])); }, []);
  useEffect(() => { carregar(); }, [carregar]);

  async function salvar(a: AlvoCompilacao) {
    const d = edit[a.slug] ?? {};
    setOcupado(a.slug); setAviso({ t: '', e: false });
    try {
      await api(`/api/compilacao/alvos/${a.slug}`, { method: 'POST', body: JSON.stringify({ grupo: d.grupo ?? a.grupo, nome: d.nome ?? a.nome, host: d.host ?? a.host, porta: d.porta ?? a.porta, ambiente: d.ambiente ?? a.ambiente, usuario: d.usuario ?? a.usuario, build: d.build ?? a.build, includes: d.includes ?? a.includes, senha: d.senha ?? '', liberado: d.liberado ?? a.liberado }) });
      setAviso({ t: 'Salvo. A senha fica guardada cifrada.', e: false });
      setEdit((e) => ({ ...e, [a.slug]: {} })); carregar();
    } catch (e) { setAviso({ t: e instanceof Error ? e.message : 'Não deu.', e: true }); }
    setOcupado('');
  }

  async function criar() {
    if (!novo) return;
    const base = `${novo.grupo}-${novo.nome}`.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 48);
    const slug = `${base || 'servidor'}-${Math.random().toString(36).slice(2, 6)}`;
    if (!novo.nome.trim() || !novo.host.trim()) { setAviso({ t: 'Informe pelo menos o nome e o host.', e: true }); return; }
    setOcupado('novo'); setAviso({ t: '', e: false });
    try {
      await api(`/api/compilacao/alvos/${slug}`, { method: 'POST', body: JSON.stringify({ grupo: novo.grupo, nome: novo.nome, host: novo.host, porta: Number(novo.porta) || 1234, ambiente: novo.ambiente, usuario: novo.usuario || 'admin', senha: novo.senha, liberado: true }) });
      setNovo(null); setAviso({ t: 'Servidor adicionado.', e: false }); carregar();
    } catch (e) { setAviso({ t: e instanceof Error ? e.message : 'Não deu.', e: true }); }
    setOcupado('');
  }
  async function remover(slug: string, nome: string) {
    if (!confirm(`Remover o servidor "${nome}"? A senha guardada some junto.`)) return;
    setOcupado(slug);
    try { await api(`/api/compilacao/alvos/${slug}`, { method: 'DELETE' }); setAberto(''); carregar(); }
    catch (e) { setAviso({ t: e instanceof Error ? e.message : 'Não deu.', e: true }); }
    setOcupado('');
  }
  async function testar(slug: string) {
    setTeste((t) => ({ ...t, [slug]: { testando: true } }));
    try {
      const r = await api<{ conectado?: boolean; detalhe?: string; build?: string }>(`/api/compilacao/alvos/${slug}/testar`, { method: 'POST', body: '{}' });
      setTeste((t) => ({ ...t, [slug]: { conectado: !!r.conectado, detalhe: r.detalhe ?? '', build: r.build ?? '' } }));
    } catch (e) { setTeste((t) => ({ ...t, [slug]: { conectado: false, detalhe: e instanceof Error ? e.message : 'falhou' } })); }
  }

  if (!alvos) return <Esqueleto className="h-40" />;
  const set = (slug: string, k: string, v: unknown) => setEdit((e) => ({ ...e, [slug]: { ...e[slug], [k]: v } }));
  const pronto = (a: AlvoCompilacao) => a.liberado && a.temSenha && !!a.ambiente;
  const grupos = [...new Set(alvos.map((a) => a.grupo || 'Outros'))];

  return (
    <section className="space-y-3 rounded-3xl border border-borda bg-superficie p-5 shadow-sm">
      <header className="flex items-center gap-2">
        <span className="flex h-9 w-9 items-center justify-center rounded-2xl bg-sky-500 text-white"><Server size={18} aria-hidden="true" /></span>
        <div className="flex-1"><h2 className="font-bold text-texto">VS Code — servidores</h2><p className="text-xs text-suave">Os mesmos servidores do VS Code, por empresa. Verde = conectado. A senha fica cifrada no cofre.</p></div>
        <BotaoPrimario onClick={() => setNovo({ grupo: '', nome: '', host: '', porta: '1234', ambiente: '', usuario: 'admin', senha: '' })}><Plus size={16} aria-hidden="true" /> Adicionar</BotaoPrimario>
      </header>
      {novo && (
        <div className="grid gap-3 rounded-2xl border border-sky-500/40 bg-sky-500/5 p-4 sm:grid-cols-2">
          <p className="sm:col-span-2 font-semibold text-texto">Novo servidor</p>
          <Campo rotulo="Empresa"><input className={CLASSE_ENTRADA} value={novo.grupo} onChange={(e) => setNovo({ ...novo, grupo: e.target.value })} placeholder="ex.: DS TECNOLOGIA" /></Campo>
          <Campo rotulo="Nome"><input className={CLASSE_ENTRADA} value={novo.nome} onChange={(e) => setNovo({ ...novo, nome: e.target.value })} placeholder="ex.: DS - TESTE - EXCLUSIVO" /></Campo>
          <Campo rotulo="Host (endereço)"><input className={CLASSE_ENTRADA} value={novo.host} onChange={(e) => setNovo({ ...novo, host: e.target.value })} placeholder="ex.: ds122513.protheus.cloudtotvs.com.br" /></Campo>
          <div className="grid grid-cols-2 gap-3">
            <Campo rotulo="Porta"><input type="number" className={CLASSE_ENTRADA} value={novo.porta} onChange={(e) => setNovo({ ...novo, porta: e.target.value })} /></Campo>
            <Campo rotulo="Ambiente"><input className={CLASSE_ENTRADA} value={novo.ambiente} onChange={(e) => setNovo({ ...novo, ambiente: e.target.value })} /></Campo>
          </div>
          <Campo rotulo="Usuário"><input className={CLASSE_ENTRADA} value={novo.usuario} onChange={(e) => setNovo({ ...novo, usuario: e.target.value })} autoComplete="off" /></Campo>
          <Campo rotulo="Senha">
            <div className="relative">
              <input type={verSenha.novo ? 'text' : 'password'} className={`${CLASSE_ENTRADA} pr-10`} value={novo.senha} onChange={(e) => setNovo({ ...novo, senha: e.target.value })} autoComplete="new-password" placeholder="••••••" />
              <button type="button" onClick={() => setVerSenha((v) => ({ ...v, novo: !v.novo }))} className="absolute right-2 top-1/2 -translate-y-1/2 text-suave hover:text-texto" aria-label={verSenha.novo ? 'Ocultar senha' : 'Mostrar senha'} tabIndex={-1}>{verSenha.novo ? <EyeOff size={18} aria-hidden="true" /> : <Eye size={18} aria-hidden="true" />}</button>
            </div>
          </Campo>
          <div className="sm:col-span-2 flex justify-end gap-2">
            <BotaoSecundario onClick={() => setNovo(null)}>Cancelar</BotaoSecundario>
            <BotaoPrimario disabled={ocupado === 'novo'} onClick={() => void criar()}>{ocupado === 'novo' ? 'Adicionando…' : 'Adicionar servidor'}</BotaoPrimario>
          </div>
        </div>
      )}
      {grupos.map((g) => (
        <div key={g} className="space-y-2">
          <p className="text-xs font-bold uppercase tracking-wide text-suave">{g}</p>
          {alvos.filter((a) => (a.grupo || 'Outros') === g).map((a) => {
            const d = edit[a.slug] ?? {};
            const ok = pronto(a);
            const tt = teste[a.slug];
            const cor = tt?.testando ? 'bg-amber-400 animate-pulse' : tt?.conectado ? 'bg-sucesso shadow-[0_0_8px] shadow-sucesso' : tt && tt.conectado === false ? 'bg-perigo' : ok ? 'bg-sucesso/70' : a.temSenha ? 'bg-alerta' : 'bg-suave/40';
            const abertoAqui = aberto === a.slug;
            return (
              <div key={a.slug} className="rounded-2xl border border-borda bg-superficie-alta/40">
                <button type="button" onClick={() => setAberto(abertoAqui ? '' : a.slug)} className="flex w-full items-center gap-3 p-3 text-left">
                  <span className={`h-2.5 w-2.5 shrink-0 rounded-full ${cor}`} title={tt?.detalhe ?? (ok ? 'configurado' : a.temSenha ? 'falta ambiente ou liberar' : 'falta senha')} />
                  <span className="min-w-0 flex-1"><span className="block truncate font-semibold text-texto">{a.nome}</span><span className="block truncate font-mono text-xs text-suave">{a.host}:{a.porta}</span></span>
                  {!a.liberado && <span className="rounded-full bg-superficie px-2 py-0.5 text-[11px] text-suave">pausado</span>}
                  <span className="text-suave">{abertoAqui ? '▴' : '▾'}</span>
                </button>
                {abertoAqui && (
                  <div className="grid gap-3 border-t border-borda p-4 sm:grid-cols-2">
                    <Campo rotulo="Ambiente (Environment)"><input className={CLASSE_ENTRADA} value={d.ambiente ?? a.ambiente} onChange={(e) => set(a.slug, 'ambiente', e.target.value)} placeholder="ex.: PRIMEERP" /></Campo>
                    <Campo rotulo="Usuário"><input className={CLASSE_ENTRADA} value={d.usuario ?? a.usuario} onChange={(e) => set(a.slug, 'usuario', e.target.value)} autoComplete="off" /></Campo>
                    <Campo rotulo={a.temSenha ? 'Nova senha (vazio = manter)' : 'Senha'}>
                      <div className="relative">
                        <input type={verSenha[a.slug] ? 'text' : 'password'} className={`${CLASSE_ENTRADA} pr-10`} value={d.senha ?? ''} onChange={(e) => set(a.slug, 'senha', e.target.value)} autoComplete="new-password" placeholder={a.temSenha ? '•••••• (guardada)' : '••••••'} />
                        <button type="button" onClick={() => setVerSenha((v) => ({ ...v, [a.slug]: !v[a.slug] }))} className="absolute right-2 top-1/2 -translate-y-1/2 text-suave hover:text-texto" aria-label={verSenha[a.slug] ? 'Ocultar senha' : 'Mostrar senha'} tabIndex={-1}>{verSenha[a.slug] ? <EyeOff size={18} aria-hidden="true" /> : <Eye size={18} aria-hidden="true" />}</button>
                      </div>
                    </Campo>
                    <Campo rotulo="Includes (pasta na VM)"><input className={CLASSE_ENTRADA} value={d.includes ?? a.includes} onChange={(e) => set(a.slug, 'includes', e.target.value)} placeholder="/opt/totvs/fontes/includes" /></Campo>
                    <div className="grid grid-cols-2 gap-3">
                      <Campo rotulo="Host"><input className={CLASSE_ENTRADA} value={d.host ?? a.host} onChange={(e) => set(a.slug, 'host', e.target.value)} /></Campo>
                      <Campo rotulo="Porta"><input type="number" className={CLASSE_ENTRADA} value={d.porta ?? a.porta} onChange={(e) => set(a.slug, 'porta', Number(e.target.value))} /></Campo>
                    </div>
                    <Campo rotulo="Build (vazio = detectar)"><input className={CLASSE_ENTRADA} value={d.build ?? a.build} onChange={(e) => set(a.slug, 'build', e.target.value)} placeholder="AUTO" /></Campo>
                    {tt && !tt.testando && (
                      <p className={`sm:col-span-2 text-sm font-semibold ${tt.conectado ? 'text-sucesso' : 'text-perigo'}`}>
                        {tt.conectado ? `Conectado${tt.build ? ` · build ${tt.build}` : ''} ✓` : `Não conectou: ${tt.detalhe ?? 'erro'}`}
                      </p>
                    )}
                    <div className="sm:col-span-2 flex flex-wrap items-center justify-between gap-2">
                      <label className="flex items-center gap-2 text-sm text-texto"><input type="checkbox" checked={d.liberado ?? a.liberado} onChange={(e) => set(a.slug, 'liberado', e.target.checked)} /> Liberado para compilar</label>
                      <div className="ml-auto flex flex-wrap gap-2">
                        {a.slug !== 'base' && <BotaoSecundario disabled={ocupado === a.slug} className="text-perigo" onClick={() => void remover(a.slug, a.nome)}><Trash2 size={16} aria-hidden="true" /> Remover</BotaoSecundario>}
                        <BotaoSecundario disabled={tt?.testando || !a.temSenha} onClick={() => void testar(a.slug)}>{tt?.testando ? 'Testando…' : 'Testar conexão'}</BotaoSecundario>
                        <BotaoPrimario disabled={ocupado === a.slug} onClick={() => void salvar(a)}>{ocupado === a.slug ? 'Salvando…' : 'Salvar'}</BotaoPrimario>
                      </div>
                    </div>
                  </div>
                )}
              </div>
            );
          })}
        </div>
      ))}
      <Aviso texto={aviso.t} erro={aviso.e} />
    </section>
  );
}
