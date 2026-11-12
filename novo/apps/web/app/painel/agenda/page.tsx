'use client';

import type { Agenda, EventoCliente, TarefaAgenda } from '@primehub/shared';
import { CalendarDays, CalendarRange, ChevronLeft, ChevronRight, Clock3, GanttChart, Plus, Sparkles, Sun, Trash2 } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { CalendarioMes } from '@/components/calendario-mes';
import { Casca } from '@/components/casca';
import { EsqueletoLista } from '@/components/esqueleto';
import { GanttSemana } from '@/components/gantt-semana';
import { Aviso, BotaoPrimario, BotaoSecundario, CLASSE_AREA, CLASSE_ENTRADA, Campo, Modal } from '@/components/ui/basico';
import { LogoEmpresa } from '@/components/ui/logo-empresa';
import { acaoPhp, api, enviarAcao } from '@/lib/api';

type Visao = 'dia' | 'semana' | 'mes';
const STATUS = { pendente: 'Pendente', em_andamento: 'Em andamento', concluida: 'Concluída' } as const;
const DURACOES = [[30, '30 min'], [45, '45 min'], [60, '1h'], [90, '1h30'], [120, '2h'], [180, '3h'], [240, '4h']] as const;
const COR_TIPO: Record<string, string> = { treinamento: '#10b981', reuniao: '#6366f1', conversa: '#0ea5e9', auxilio: '#f59e0b' };
const CORES = ['#0ea5e9', '#8b5cf6', '#10b981', '#f59e0b', '#f43f5e', '#06b6d4', '#84cc16', '#d946ef', '#f97316', '#6366f1'];
const corDe = (nome: string | null) => CORES[[...(nome ?? '?')].reduce((s, c) => s + c.charCodeAt(0), 0) % CORES.length] ?? '#0ea5e9';

const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const meioDia = (d: Date) => { const x = new Date(d); x.setHours(12, 0, 0, 0); return x; };
function inicioSemana(d: Date): Date { const x = meioDia(d); x.setDate(x.getDate() - ((x.getDay() + 6) % 7)); return x; }

interface FormTarefa { tarefa_id: string; titulo: string; data: string; hora_inicio: string; duracao_minutos: string; cliente_id: string; responsavel_id: string; notas: string; status: string; chamado_id: string }
const formVazio = (data: string): FormTarefa => ({ tarefa_id: '', titulo: '', data, hora_inicio: '', duracao_minutos: '60', cliente_id: '', responsavel_id: '', notas: '', status: 'pendente', chamado_id: '' });

export default function PaginaAgenda() {
  const [visao, setVisao] = useState<Visao>('semana');
  const [ancora, setAncora] = useState(() => meioDia(new Date()));
  const [dados, setDados] = useState<Agenda | null>(null);
  const [erro, setErro] = useState('');
  const [form, setForm] = useState<FormTarefa | null>(null);
  const [evento, setEvento] = useState<EventoCliente | null>(null);
  const [ia, setIa] = useState<{ periodo: Visao; clientes: number[]; rodando: '' | 'texto' | 'tarefas'; resposta: string; erro: boolean } | null>(null);
  const [horario, setHorario] = useState<Agenda['horario'] | null>(null);
  const [aviso, setAviso] = useState('');
  const [salvando, setSalvando] = useState(false);

  useEffect(() => { try { const v = localStorage.getItem('ph_agenda_visao') as Visao | null; if (v) setVisao(v); } catch { /* sem armazenamento */ } }, []);
  const trocarVisao = (v: Visao) => { setVisao(v); try { localStorage.setItem('ph_agenda_visao', v); } catch { /* ignore */ } };

  const dias = useMemo(() => {
    if (visao === 'dia') return [ancora];
    const ini = inicioSemana(ancora);
    return Array.from({ length: 7 }, (_, i) => { const d = new Date(ini); d.setDate(d.getDate() + i); return d; });
  }, [visao, ancora]);
  const intervalo = useMemo(() => {
    if (visao === 'mes') {
      const a = new Date(ancora); a.setDate(1); a.setDate(a.getDate() - 7);
      const b = new Date(ancora); b.setMonth(b.getMonth() + 1, 7);
      return [iso(a), iso(b)] as const;
    }
    return [iso(dias[0] ?? ancora), iso(dias[dias.length - 1] ?? ancora)] as const;
  }, [visao, ancora, dias]);

  const carregar = useCallback(() => {
    api<Agenda>(`/api/agenda?de=${intervalo[0]}&ate=${intervalo[1]}`).then(setDados).catch((e: unknown) => setErro(e instanceof Error ? e.message : 'Não consegui abrir a agenda.'));
  }, [intervalo]);
  useEffect(() => { carregar(); }, [carregar]);

  function navegar(n: number) {
    setAncora((a) => { const x = new Date(a); if (visao === 'dia') x.setDate(x.getDate() + n); else if (visao === 'semana') x.setDate(x.getDate() + 7 * n); else x.setMonth(x.getMonth() + n, 1); return meioDia(x); });
  }
  const titulo = visao === 'dia' ? ancora.toLocaleDateString('pt-BR', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' })
    : visao === 'semana' ? `${(dias[0] ?? ancora).toLocaleDateString('pt-BR', { day: '2-digit', month: 'short' })} – ${(dias[6] ?? ancora).toLocaleDateString('pt-BR', { day: '2-digit', month: 'short', year: 'numeric' })}`
      : ancora.toLocaleDateString('pt-BR', { month: 'long', year: 'numeric' });

  function editar(t: TarefaAgenda) {
    setAviso('');
    setForm({ tarefa_id: String(t.id), titulo: t.titulo, data: t.data, hora_inicio: t.horaInicio ?? '', duracao_minutos: String(t.duracaoMinutos ?? 60), cliente_id: t.clienteId ? String(t.clienteId) : '',
      responsavel_id: t.responsavelId ? String(t.responsavelId) : '', notas: t.notas ?? '', status: t.status, chamado_id: t.chamadoId ? String(t.chamadoId) : '' });
  }

  async function salvar(e: React.FormEvent) {
    e.preventDefault();
    if (!form) return;
    setSalvando(true); setAviso('');
    try { await enviarAcao('agenda.php', { ...form, acao: form.tarefa_id ? 'atualizar_tarefa' : 'criar_tarefa' }); setForm(null); carregar(); } catch (ex) { setAviso(ex instanceof Error ? ex.message : 'Não deu pra salvar.'); }
    setSalvando(false);
  }
  async function excluir() {
    if (!form?.tarefa_id || !confirm('Excluir essa tarefa da agenda?')) return;
    try { await enviarAcao('agenda.php', { acao: 'excluir_tarefa', tarefa_id: form.tarefa_id }); setForm(null); carregar(); } catch (ex) { setAviso(ex instanceof Error ? ex.message : 'Não deu pra excluir.'); }
  }
  async function moverTarefa(t: TarefaAgenda, data: string, hora: string | null) {
    setDados((d) => d && { ...d, tarefas: d.tarefas.map((x) => (x.id === t.id ? { ...x, data, horaInicio: hora } : x)) });
    await enviarAcao('agenda.php', { acao: 'mover_tarefa', ajax: '1', tarefa_id: String(t.id), data, hora_inicio: hora ?? '', duracao_minutos: String(t.duracaoMinutos ?? 60) }).catch(() => undefined);
    carregar();
  }
  async function moverEvento(e: EventoCliente, data: string, hora: string) {
    setDados((d) => d && { ...d, eventos: d.eventos.map((x) => (x.id === e.id ? { ...x, inicio: `${data} ${hora}:00` } : x)) });
    await acaoPhp('api_acoes.php', { acao: 'mover_treinamento', treinamento_id: String(e.id), data, hora_inicio: hora }).catch(() => undefined);
    carregar();
  }
  async function alternarFeita(t: TarefaAgenda) {
    const status = t.status === 'concluida' ? 'pendente' : 'concluida';
    setDados((d) => d && { ...d, tarefas: d.tarefas.map((x) => (x.id === t.id ? { ...x, status } : x)) });
    await enviarAcao('agenda.php', { acao: 'mudar_status_tarefa', ajax: '1', tarefa_id: String(t.id), status }).catch(() => carregar());
  }

  /** IA da agenda (mesma do sistema de sempre): prioriza chamados abertos e encaixa no expediente, sem almoço. */
  async function rodarIa(criar: boolean) {
    if (!ia) return;
    setIa({ ...ia, rodando: criar ? 'tarefas' : 'texto', resposta: '', erro: false });
    try {
      const corpo = new FormData();
      corpo.append('acao', 'gerar_sugestao_ia'); corpo.append('ajax', '1'); corpo.append('periodo', ia.periodo); corpo.append('data', iso(ancora));
      corpo.append('cliente_ids', ia.clientes.join(',')); corpo.append('criar_tarefas', criar ? '1' : '0');
      const r = await fetch('/agenda.php', { method: 'POST', body: corpo, credentials: 'same-origin', headers: { 'X-PrimeHub': '1' } });
      const j = (await r.json()) as { sucesso?: boolean; mensagem?: string; tipo?: string; sugestao?: string; resumo?: string; totalCriadas?: number; dataFoco?: string };
      if (!j.sucesso) { setIa((x) => x && { ...x, rodando: '', resposta: j.mensagem ?? 'A IA não conseguiu montar agora. Tente de novo.', erro: true }); return; }
      const resposta = j.tipo === 'tarefas' ? `${j.resumo ?? ''}\n\n✅ ${j.totalCriadas ?? 0} tarefa(s) criada(s) na agenda.` : (j.sugestao ?? '');
      setIa((x) => x && { ...x, rodando: '', resposta, erro: false });
      if (j.tipo === 'tarefas') { if (j.dataFoco) setAncora(meioDia(new Date(`${j.dataFoco}T12:00`))); carregar(); }
    } catch { setIa((x) => x && { ...x, rodando: '', resposta: 'Não deu para falar com a IA agora. Tente de novo em instantes.', erro: true }); }
  }

  async function salvarHorario() {
    if (!horario) return;
    setSalvando(true); setAviso('');
    try {
      const corpo = new FormData();
      corpo.append('acao', 'salvar_horario_trabalho'); corpo.append('ajax', '1');
      corpo.append('expediente_inicio', horario.expedienteInicio); corpo.append('expediente_fim', horario.expedienteFim);
      corpo.append('almoco_inicio', horario.almocoInicio); corpo.append('almoco_fim', horario.almocoFim);
      const r = await fetch('/agenda.php', { method: 'POST', body: corpo, credentials: 'same-origin', headers: { 'X-PrimeHub': '1' } });
      const j = (await r.json()) as { sucesso?: boolean; mensagem?: string };
      if (!j.sucesso) setAviso(j.mensagem ?? 'Horário inválido.'); else { setHorario(null); carregar(); }
    } catch { setAviso('Não deu para salvar agora.'); }
    setSalvando(false);
  }

  const mudar = (k: keyof FormTarefa) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) => setForm((f) => f && { ...f, [k]: e.target.value });
  const pendentes = dados?.tarefas.filter((t) => t.status !== 'concluida').length ?? 0;

  const acoes = (
    <div className="flex gap-2">
      <button type="button" onClick={() => setIa({ periodo: visao, clientes: [], rodando: '', resposta: '', erro: false })}
        className="toque inline-flex items-center gap-2 rounded-xl px-3 font-semibold text-white shadow-lg" style={{ background: 'linear-gradient(135deg,#d946ef,#7c3aed)' }}>
        <Sparkles size={18} aria-hidden="true" /> <span className="hidden md:inline">Montar agenda com IA</span>
      </button>
      <button type="button" onClick={() => setForm(formVazio(iso(ancora)))} className="gradiente-marca toque inline-flex items-center gap-2 rounded-xl px-3 font-semibold text-white shadow">
        <Plus size={20} aria-hidden="true" /> <span className="hidden md:inline">Nova tarefa</span>
      </button>
    </div>
  );

  return (
    <Casca tipo="equipe" titulo="Agenda" acoes={acoes}>
      <div className="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center">
        <div className="flex gap-1 rounded-2xl border border-borda bg-superficie p-1" role="tablist" aria-label="Visão da agenda">
          {([['dia', 'Dia', Sun, '#f59e0b'], ['semana', 'Semana', GanttChart, '#8b5cf6'], ['mes', 'Mês', CalendarDays, '#10b981']] as const).map(([v, r, Icone, cor]) => (
            <button key={v} type="button" role="tab" aria-selected={visao === v} onClick={() => trocarVisao(v)}
              className={`toque inline-flex flex-1 items-center justify-center gap-2 rounded-xl px-4 text-sm font-bold transition lg:flex-none ${visao === v ? 'text-white shadow-lg' : 'text-suave hover:text-texto'}`}
              style={visao === v ? { background: `linear-gradient(135deg, ${cor}, color-mix(in oklab, ${cor} 60%, #0b1020))` } : undefined}>
              <Icone size={18} aria-hidden="true" style={visao === v ? undefined : { color: cor }} /> {r}
            </button>
          ))}
        </div>
        <div className="flex flex-1 items-center gap-2">
          <BotaoSecundario onClick={() => navegar(-1)} aria-label="Anterior"><ChevronLeft size={20} /></BotaoSecundario>
          <p className="flex-1 text-center text-lg font-bold capitalize text-texto">{titulo}</p>
          <BotaoSecundario onClick={() => setAncora(meioDia(new Date()))}>Hoje</BotaoSecundario>
          <BotaoSecundario onClick={() => navegar(1)} aria-label="Próximo"><ChevronRight size={20} /></BotaoSecundario>
        </div>
        {dados && (
          <button type="button" onClick={() => { setAviso(''); setHorario(dados.horario); }} className="toque inline-flex items-center gap-2 rounded-xl border border-borda bg-superficie px-3 text-sm font-semibold text-texto hover:bg-superficie-alta" title="Meu horário de trabalho">
            <Clock3 size={18} className="text-amber-500" aria-hidden="true" /> {dados.horario.expedienteInicio}–{dados.horario.expedienteFim}
          </button>
        )}
      </div>

      {dados && (
        <div className="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
          {[
            { r: 'Tarefas no período', v: dados.tarefas.length, c: '#0ea5e9' },
            { r: 'Pendentes', v: pendentes, c: '#f59e0b' },
            { r: 'Concluídas', v: dados.tarefas.length - pendentes, c: '#10b981' },
            { r: 'Reuniões e treinamentos', v: dados.eventos.length, c: '#6366f1' },
          ].map((k) => (
            <div key={k.r} className="rounded-2xl border border-borda bg-superficie p-3 shadow-sm" style={{ borderLeft: `4px solid ${k.c}` }}>
              <p className="text-xs text-suave">{k.r}</p><p className="text-2xl font-bold tabular-nums text-texto">{k.v}</p>
            </div>
          ))}
        </div>
      )}

      {erro && <Aviso texto={erro} erro />}
      {!dados && !erro && <EsqueletoLista linhas={6} />}
      {dados && visao !== 'mes' && (
        <GanttSemana dias={dias} tarefas={dados.tarefas} eventos={dados.eventos} horario={dados.horario}
          aoMover={(t, d, h) => void moverTarefa(t, d, h)} aoAbrir={editar} aoMoverEvento={(e, d, h) => void moverEvento(e, d, h)} aoAbrirEvento={setEvento} />
      )}
      {dados && visao === 'mes' && (
        <CalendarioMes semCabecalho key={iso(ancora).slice(0, 7)} inicial={ancora} aoMudarMes={(m) => setAncora(meioDia(m))} aoEscolherDia={() => undefined} eventos={[
          ...dados.tarefas.map((t) => ({ id: `t${t.id}`, data: t.data, hora: t.horaInicio, titulo: t.titulo, cor: t.status === 'concluida' ? '#64748b' : corDe(t.responsavel ?? t.cliente),
            aoClicar: () => editar(t), aoMover: (dia: string) => void moverTarefa(t, dia, t.horaInicio) })),
          ...dados.eventos.filter((e) => e.inicio).map((e) => ({ id: `e${e.id}`, data: (e.inicio ?? '').slice(0, 10), hora: (e.inicio ?? '').slice(11, 16), titulo: `${e.titulo} · ${e.cliente ?? ''}`, cor: COR_TIPO[e.tipo ?? ''] ?? '#6366f1',
            aoClicar: () => setEvento(e), aoMover: (dia: string) => void moverEvento(e, dia, (e.inicio ?? '').slice(11, 16) || '09:00') })),
        ]} />
      )}
      {dados && (
        <div className="mt-3 flex flex-wrap gap-3 text-xs text-suave">
          {Object.entries(COR_TIPO).map(([k, c]) => <span key={k} className="flex items-center gap-1.5"><span className="h-3 w-3 rounded-full" style={{ background: c }} />{{ treinamento: 'Treinamento', reuniao: 'Reunião', conversa: 'Conversa', auxilio: 'Auxílio' }[k]}</span>)}
          {dados.equipe.filter((u) => dados.tarefas.some((t) => t.responsavelId === u.id)).map((u) => <span key={u.id} className="flex items-center gap-1.5"><span className="h-3 w-3 rounded" style={{ background: corDe(u.nome) }} />{u.nome}</span>)}
        </div>
      )}

      {evento && (
        <Modal titulo={evento.titulo} aoFechar={() => setEvento(null)} largura="max-w-md">
          <div className="space-y-3">
            <div className="flex items-center gap-3"><LogoEmpresa nome={evento.cliente} logo={evento.clienteLogo} /><div><p className="font-semibold text-texto">{evento.cliente}</p><p className="text-sm text-suave">{evento.setor ?? 'Todos os setores'}</p></div></div>
            <p className="text-sm text-texto"><CalendarRange size={16} className="mr-1 inline text-primaria" aria-hidden="true" />{evento.inicio ? new Date(evento.inicio.replace(' ', 'T')).toLocaleString('pt-BR', { dateStyle: 'full', timeStyle: 'short' }) : ''}</p>
            <p className="text-sm text-suave">Arraste no Gantt ou no calendário para mudar o horário.</p>
            {evento.link && <a href={evento.link} target="_blank" rel="noopener noreferrer" className="gradiente-marca toque flex items-center justify-center rounded-xl font-semibold text-white">Entrar na reunião</a>}
          </div>
        </Modal>
      )}

      {ia && dados && (
        <Modal titulo="✨ Montar agenda com IA" aoFechar={() => setIa(null)} largura="max-w-2xl">
          <div className="space-y-4">
            <p className="text-sm text-suave">A IA olha os chamados abertos (urgentes e mais antigos primeiro) e as tarefas já marcadas, e encaixa tudo no seu expediente ({dados.horario.expedienteInicio}–{dados.horario.expedienteFim}), sem invadir o almoço ({dados.horario.almocoInicio}–{dados.horario.almocoFim}).</p>
            <fieldset><legend className="mb-2 text-sm font-semibold text-texto">Período</legend>
              <div className="flex gap-2">{(['dia', 'semana', 'mes'] as const).map((p) => <button key={p} type="button" aria-pressed={ia.periodo === p} onClick={() => setIa({ ...ia, periodo: p })} className={`toque flex-1 rounded-xl border px-3 text-sm font-semibold ${ia.periodo === p ? 'border-transparent text-white' : 'border-borda text-texto'}`} style={ia.periodo === p ? { background: 'linear-gradient(135deg,#d946ef,#7c3aed)' } : undefined}>{{ dia: 'Este dia', semana: 'Esta semana', mes: 'Este mês' }[p]}</button>)}</div>
            </fieldset>
            <fieldset><legend className="mb-2 text-sm font-semibold text-texto">Clientes (nenhum = todos)</legend>
              <div className="flex max-h-40 flex-wrap gap-2 overflow-y-auto">{dados.clientes.map((c) => { const m = ia.clientes.includes(c.id); return <button key={c.id} type="button" aria-pressed={m} onClick={() => setIa({ ...ia, clientes: m ? ia.clientes.filter((x) => x !== c.id) : [...ia.clientes, c.id] })} className={`rounded-full border px-3 py-1.5 text-sm font-semibold ${m ? 'border-transparent bg-violet-600 text-white' : 'border-borda text-suave'}`}>{c.nome}</button>; })}</div>
            </fieldset>
            <div className="grid gap-3 sm:grid-cols-2">
              <BotaoSecundario onClick={() => void rodarIa(false)} disabled={!!ia.rodando}>🧭 {ia.rodando === 'texto' ? 'Pensando…' : 'Só me diga as prioridades'}</BotaoSecundario>
              <BotaoPrimario onClick={() => void rodarIa(true)} disabled={!!ia.rodando} style={{ background: 'linear-gradient(135deg,#d946ef,#7c3aed)' }}><Sparkles size={18} aria-hidden="true" /> {ia.rodando === 'tarefas' ? 'Montando a agenda…' : 'Montar e criar as tarefas'}</BotaoPrimario>
            </div>
            {ia.rodando && <div className="esqueleto h-24" aria-hidden="true" />}
            {ia.resposta && <div className={`whitespace-pre-wrap rounded-2xl border p-4 text-sm leading-relaxed ${ia.erro ? 'border-perigo/40 text-perigo' : 'border-violet-500/40 bg-violet-500/5 text-texto'}`}>{ia.resposta}</div>}
          </div>
        </Modal>
      )}

      {horario && (
        <Modal titulo="Meu horário de trabalho" aoFechar={() => setHorario(null)} largura="max-w-md">
          <div className="space-y-4">
            <p className="text-sm text-suave">A agenda e a IA usam este horário para encaixar suas tarefas.</p>
            <div className="grid grid-cols-2 gap-3">
              <Campo rotulo="Começo do expediente"><input type="time" value={horario.expedienteInicio} onChange={(e) => setHorario({ ...horario, expedienteInicio: e.target.value })} className={CLASSE_ENTRADA} /></Campo>
              <Campo rotulo="Fim do expediente"><input type="time" value={horario.expedienteFim} onChange={(e) => setHorario({ ...horario, expedienteFim: e.target.value })} className={CLASSE_ENTRADA} /></Campo>
              <Campo rotulo="Almoço começa"><input type="time" value={horario.almocoInicio} onChange={(e) => setHorario({ ...horario, almocoInicio: e.target.value })} className={CLASSE_ENTRADA} /></Campo>
              <Campo rotulo="Almoço termina"><input type="time" value={horario.almocoFim} onChange={(e) => setHorario({ ...horario, almocoFim: e.target.value })} className={CLASSE_ENTRADA} /></Campo>
            </div>
            <Aviso texto={aviso} erro />
            <BotaoPrimario onClick={() => void salvarHorario()} disabled={salvando} className="w-full">Salvar meu horário</BotaoPrimario>
          </div>
        </Modal>
      )}

      {form && dados && (
        <Modal titulo={form.tarefa_id ? 'Editar tarefa' : 'Nova tarefa'} aoFechar={() => setForm(null)}>
          <form onSubmit={(e) => void salvar(e)} className="space-y-4">
            <Campo rotulo="Título *"><input required value={form.titulo} onChange={mudar('titulo')} className={CLASSE_ENTRADA} /></Campo>
            <div className="grid gap-4 sm:grid-cols-3">
              <Campo rotulo="Dia *"><input type="date" required value={form.data} onChange={mudar('data')} className={CLASSE_ENTRADA} /></Campo>
              <Campo rotulo="Horário"><input type="time" value={form.hora_inicio} onChange={mudar('hora_inicio')} className={CLASSE_ENTRADA} /></Campo>
              <Campo rotulo="Duração"><select value={form.duracao_minutos} onChange={mudar('duracao_minutos')} className={CLASSE_ENTRADA}>{DURACOES.map(([v, r]) => <option key={v} value={v}>{r}</option>)}</select></Campo>
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
              <Campo rotulo="Cliente"><select value={form.cliente_id} onChange={mudar('cliente_id')} className={CLASSE_ENTRADA}><option value="">Nenhum</option>{dados.clientes.map((c) => <option key={c.id} value={c.id}>{c.nome}</option>)}</select></Campo>
              <Campo rotulo="Responsável"><select value={form.responsavel_id} onChange={mudar('responsavel_id')} className={CLASSE_ENTRADA}><option value="">Eu mesmo</option>{dados.equipe.map((u) => <option key={u.id} value={u.id}>{u.nome}</option>)}</select></Campo>
            </div>
            {form.tarefa_id && (
              <div className="flex flex-wrap items-center gap-3">
                <Campo rotulo="Situação"><select value={form.status} onChange={mudar('status')} className={CLASSE_ENTRADA}>{Object.entries(STATUS).map(([k, r]) => <option key={k} value={k}>{r}</option>)}</select></Campo>
                {(() => { const t = dados.tarefas.find((x) => String(x.id) === form.tarefa_id); return t ? <BotaoSecundario className="mt-6" onClick={() => { void alternarFeita(t); setForm(null); }}>{t.status === 'concluida' ? '↺ Reabrir' : '✓ Marcar como feita'}</BotaoSecundario> : null; })()}
                {form.chamado_id && <a href={`/painel/chamado?id=${form.chamado_id}`} className="mt-6 text-sm font-semibold text-primaria">Abrir chamado #{form.chamado_id} →</a>}
              </div>
            )}
            <Campo rotulo="Anotações"><textarea value={form.notas} onChange={mudar('notas')} rows={3} className={CLASSE_AREA} /></Campo>
            <Aviso texto={aviso} erro />
            <div className="flex flex-col-reverse gap-3 sm:flex-row">
              {form.tarefa_id && <BotaoSecundario onClick={() => void excluir()} className="text-perigo"><Trash2 size={18} aria-hidden="true" /> Excluir</BotaoSecundario>}
              <BotaoSecundario onClick={() => setForm(null)} className="sm:ml-auto">Cancelar</BotaoSecundario>
              <BotaoPrimario type="submit" disabled={salvando}>{salvando ? 'Salvando…' : 'Salvar'}</BotaoPrimario>
            </div>
          </form>
        </Modal>
      )}
    </Casca>
  );
}
