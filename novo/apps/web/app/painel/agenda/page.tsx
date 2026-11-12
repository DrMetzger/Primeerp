'use client';

import type { Agenda, TarefaAgenda } from '@primehub/shared';
import { CalendarDays, Check, ChevronLeft, ChevronRight, Clock, Columns3, ExternalLink, GanttChart, Plus, Trash2, Video } from 'lucide-react';
import { CalendarioMes } from '@/components/calendario-mes';
import { GanttSemana } from '@/components/gantt-semana';
import { useCallback, useEffect, useState } from 'react';
import { Casca } from '@/components/casca';
import { EsqueletoLista } from '@/components/esqueleto';
import { Aviso, BotaoPrimario, BotaoSecundario, CLASSE_AREA, CLASSE_ENTRADA, Campo, Modal } from '@/components/ui/basico';
import { LogoEmpresa } from '@/components/ui/logo-empresa';
import { api, enviarAcao } from '@/lib/api';

const DIAS = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
const STATUS = { pendente: 'Pendente', em_andamento: 'Em andamento', concluida: 'Concluída' } as const;
const DURACOES = [[30, '30 min'], [45, '45 min'], [60, '1h'], [90, '1h30'], [120, '2h'], [180, '3h'], [240, '4h']] as const;

const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
function inicioSemana(d: Date): Date { const x = new Date(d); x.setHours(12, 0, 0, 0); x.setDate(x.getDate() - ((x.getDay() + 6) % 7)); return x; }

interface FormTarefa { tarefa_id: string; titulo: string; data: string; hora_inicio: string; duracao_minutos: string; cliente_id: string; responsavel_id: string; notas: string; status: string }
const formVazio = (data: string): FormTarefa => ({ tarefa_id: '', titulo: '', data, hora_inicio: '', duracao_minutos: '60', cliente_id: '', responsavel_id: '', notas: '', status: 'pendente' });

export default function PaginaAgenda() {
  const [semana, setSemana] = useState(() => inicioSemana(new Date()));
  const [dados, setDados] = useState<Agenda | null>(null);
  const [erro, setErro] = useState('');
  const [form, setForm] = useState<FormTarefa | null>(null);
  const [salvando, setSalvando] = useState(false);
  const [aviso, setAviso] = useState('');
  const [visao, setVisao] = useState<'semana' | 'gantt' | 'mes'>('gantt');

  const dias = Array.from({ length: 7 }, (_, i) => { const d = new Date(semana); d.setDate(d.getDate() + i); return d; });
  // No mês carrega uma janela larga (o calendário navega sozinho entre meses).
  const largo = (n: number) => { const d = new Date(); d.setDate(1); d.setMonth(d.getMonth() + n); return iso(d); };
  const de = visao === 'mes' ? largo(-3) : iso(dias[0] ?? semana), ate = visao === 'mes' ? largo(6) : iso(dias[6] ?? semana);

  const carregar = useCallback(() => {
    api<Agenda>(`/api/agenda?de=${de}&ate=${ate}`).then(setDados).catch((e: unknown) => setErro(e instanceof Error ? e.message : 'Não consegui abrir a agenda.'));
  }, [de, ate]);
  useEffect(() => { setDados(null); carregar(); }, [carregar]);

  function editar(t: TarefaAgenda) {
    setForm({ tarefa_id: String(t.id), titulo: t.titulo, data: t.data, hora_inicio: t.horaInicio ?? '', duracao_minutos: String(t.duracaoMinutos ?? 60),
      cliente_id: t.clienteId ? String(t.clienteId) : '', responsavel_id: t.responsavelId ? String(t.responsavelId) : '', notas: t.notas ?? '', status: t.status });
  }

  async function salvar(e: React.FormEvent) {
    e.preventDefault();
    if (!form) return;
    setSalvando(true); setAviso('');
    try {
      await enviarAcao('agenda.php', { ...form, acao: form.tarefa_id ? 'atualizar_tarefa' : 'criar_tarefa' });
      setForm(null); carregar();
    } catch (ex) { setAviso(ex instanceof Error ? ex.message : 'Não deu pra salvar.'); }
    setSalvando(false);
  }

  async function excluir() {
    if (!form?.tarefa_id || !confirm('Excluir essa tarefa da agenda?')) return;
    try { await enviarAcao('agenda.php', { acao: 'excluir_tarefa', tarefa_id: form.tarefa_id }); setForm(null); carregar(); } catch (ex) { setAviso(ex instanceof Error ? ex.message : 'Não deu pra excluir.'); }
  }

  async function alternarFeita(t: TarefaAgenda) {
    const status = t.status === 'concluida' ? 'pendente' : 'concluida';
    setDados((d) => d && { ...d, tarefas: d.tarefas.map((x) => (x.id === t.id ? { ...x, status } : x)) });
    await enviarAcao('agenda.php', { acao: 'mudar_status_tarefa', ajax: '1', tarefa_id: String(t.id), status }).catch(() => carregar());
  }

  async function mover(t: TarefaAgenda, data: string, hora: string) {
    setDados((d) => d && { ...d, tarefas: d.tarefas.map((x) => (x.id === t.id ? { ...x, data, horaInicio: hora } : x)) });
    try {
      await enviarAcao('agenda.php', { acao: 'mover_tarefa', ajax: '1', tarefa_id: String(t.id), data, hora_inicio: hora, duracao_minutos: String(t.duracaoMinutos ?? 60) });
    } catch { /* volta ao que está salvo */ }
    carregar();
  }

  const mudar = (k: keyof FormTarefa) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) => setForm((f) => f && { ...f, [k]: e.target.value });
  const hoje = iso(new Date());
  const nav = (n: number) => setSemana((s) => { const x = new Date(s); x.setDate(x.getDate() + n * 7); return x; });

  const acoes = (
    <button type="button" onClick={() => setForm(formVazio(hoje))} className="gradiente-marca toque inline-flex items-center gap-2 rounded-xl px-4 font-semibold text-white shadow">
      <Plus size={20} aria-hidden="true" /> <span className="hidden sm:inline">Nova tarefa</span>
    </button>
  );

  return (
    <Casca tipo="equipe" titulo="Agenda" acoes={acoes}>
      <div className="mb-3 flex flex-wrap gap-2" role="tablist" aria-label="Forma de ver a agenda">
        {([['gantt', 'Gantt', GanttChart, '#8b5cf6'], ['semana', 'Semana', Columns3, '#0ea5e9'], ['mes', 'Mês', CalendarDays, '#10b981']] as const).map(([v, r, Icone, cor]) => (
          <button key={v} type="button" role="tab" aria-selected={visao === v} onClick={() => setVisao(v)}
            className={`toque inline-flex items-center gap-2 rounded-2xl border px-4 text-sm font-semibold ${visao === v ? 'border-transparent text-white shadow-lg' : 'border-borda bg-superficie text-texto'}`}
            style={visao === v ? { background: `linear-gradient(135deg, ${cor}, color-mix(in oklab, ${cor} 60%, #0b1020))` } : undefined}>
            <Icone size={18} aria-hidden="true" style={visao === v ? undefined : { color: cor }} /> {r}
          </button>
        ))}
      </div>
      {visao !== 'mes' && <div className="mb-4 flex items-center gap-2">
        <BotaoSecundario onClick={() => nav(-1)} aria-label="Semana anterior"><ChevronLeft size={20} /></BotaoSecundario>
        <p className="flex-1 text-center font-semibold text-texto">{(dias[0] ?? semana).toLocaleDateString('pt-BR', { day: '2-digit', month: 'short' })} – {(dias[6] ?? semana).toLocaleDateString('pt-BR', { day: '2-digit', month: 'short', year: 'numeric' })}</p>
        <BotaoSecundario onClick={() => setSemana(inicioSemana(new Date()))}>Hoje</BotaoSecundario>
        <BotaoSecundario onClick={() => nav(1)} aria-label="Próxima semana"><ChevronRight size={20} /></BotaoSecundario>
      </div>}
      {erro && <Aviso texto={erro} erro />}
      {dados && visao === 'gantt' && <GanttSemana dias={dias} tarefas={dados.tarefas} eventos={dados.eventos} aoMover={(t, d, h) => void mover(t, d, h)} aoAbrir={editar} />}
      {dados && visao === 'mes' && (
        <CalendarioMes eventos={[
          ...dados.tarefas.map((t) => ({ id: `t${t.id}`, data: t.data, hora: t.horaInicio, titulo: t.titulo, cor: t.status === 'concluida' ? '#64748b' : '#0ea5e9', aoClicar: () => editar(t) })),
          ...dados.eventos.filter((e) => e.inicio).map((e) => ({ id: `e${e.id}`, data: (e.inicio ?? '').slice(0, 10), hora: (e.inicio ?? '').slice(11, 16), titulo: `${e.titulo} · ${e.cliente ?? ''}`, cor: '#8b5cf6', aoClicar: () => { if (e.link) window.open(e.link, '_blank', 'noopener'); } })),
        ]} aoEscolherDia={() => undefined} />
      )}
      {!dados && !erro ? <EsqueletoLista linhas={7} /> : dados && visao === 'semana' && (
        <ol className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
          {dias.map((d) => {
            const dia = iso(d);
            const tarefas = dados.tarefas.filter((t) => t.data === dia || (t.dataFim && t.data <= dia && t.dataFim >= dia));
            const eventos = dados.eventos.filter((ev) => ev.inicio?.slice(0, 10) === dia);
            return (
              <li key={dia} className={`flex flex-col rounded-3xl border bg-superficie p-4 shadow-sm ${dia === hoje ? 'border-primaria' : 'border-borda'}`}>
                <div className="mb-3 flex items-baseline gap-2">
                  <p className={`text-lg font-bold ${dia === hoje ? 'text-primaria' : 'text-texto'}`}>{DIAS[d.getDay()]}</p>
                  <p className="text-sm text-suave">{d.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' })}</p>
                  <button type="button" onClick={() => setForm(formVazio(dia))} aria-label={`Nova tarefa em ${d.toLocaleDateString('pt-BR')}`} className="toque ml-auto flex items-center justify-center rounded-xl text-suave hover:text-primaria"><Plus size={18} /></button>
                </div>
                <ul className="flex-1 space-y-2">
                  {eventos.map((ev) => (
                    <li key={`e${ev.id}`} className="rounded-2xl border border-destaque/40 bg-destaque/10 p-3">
                      <div className="flex items-center gap-2">
                        <LogoEmpresa nome={ev.cliente} logo={ev.clienteLogo} />
                        <div className="min-w-0 flex-1">
                          <p className="truncate text-sm font-semibold text-texto"><Video size={14} className="mr-1 inline" aria-hidden="true" />{ev.titulo}</p>
                          <p className="truncate text-xs text-suave">{ev.inicio?.slice(11, 16)} · {ev.cliente}</p>
                        </div>
                        {ev.link && <a href={ev.link} target="_blank" rel="noopener noreferrer" aria-label="Entrar na reunião" className="toque flex items-center justify-center rounded-xl text-primaria"><ExternalLink size={18} /></a>}
                      </div>
                    </li>
                  ))}
                  {tarefas.map((t) => (
                    <li key={t.id} className={`flex items-start gap-2 rounded-2xl border border-borda bg-fundo p-3 ${t.status === 'concluida' ? 'opacity-60' : ''}`}>
                      <button type="button" onClick={() => void alternarFeita(t)} aria-label={t.status === 'concluida' ? 'Marcar como pendente' : 'Marcar como feita'}
                        className={`mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-lg border-2 ${t.status === 'concluida' ? 'border-sucesso bg-sucesso text-white' : 'border-borda'}`}>
                        {t.status === 'concluida' && <Check size={16} />}
                      </button>
                      <button type="button" onClick={() => editar(t)} className="min-w-0 flex-1 text-left">
                        <p className={`text-sm font-semibold text-texto ${t.status === 'concluida' ? 'line-through' : ''}`}>{t.titulo}</p>
                        <p className="text-xs text-suave">
                          {t.horaInicio ? <><Clock size={12} className="mr-0.5 inline" aria-hidden="true" />{t.horaInicio} · </> : ''}
                          {[t.cliente, t.responsavel, t.status === 'em_andamento' ? 'em andamento' : null].filter(Boolean).join(' · ')}
                        </p>
                        {t.chamadoId && <a href={`/painel/chamado?id=${t.chamadoId}`} onClick={(e) => e.stopPropagation()} className="text-xs font-semibold text-primaria">Chamado #{t.chamadoId}</a>}
                      </button>
                    </li>
                  ))}
                  {!tarefas.length && !eventos.length && <li className="py-4 text-center text-sm text-suave"><CalendarDays size={18} className="mx-auto mb-1 opacity-50" aria-hidden="true" />Livre</li>}
                </ul>
              </li>
            );
          })}
        </ol>
      )}

      {form && dados && (
        <Modal titulo={form.tarefa_id ? 'Editar tarefa' : 'Nova tarefa'} aoFechar={() => setForm(null)}>
          <form onSubmit={(e) => void salvar(e)} className="space-y-4">
            <Campo rotulo="Título *"><input required value={form.titulo} onChange={mudar('titulo')} className={CLASSE_ENTRADA} /></Campo>
            <div className="grid gap-4 sm:grid-cols-3">
              <Campo rotulo="Dia *"><input type="date" required value={form.data} onChange={mudar('data')} className={CLASSE_ENTRADA} /></Campo>
              <Campo rotulo="Horário"><input type="time" value={form.hora_inicio} onChange={mudar('hora_inicio')} className={CLASSE_ENTRADA} /></Campo>
              <Campo rotulo="Duração">
                <select value={form.duracao_minutos} onChange={mudar('duracao_minutos')} className={CLASSE_ENTRADA}>{DURACOES.map(([v, r]) => <option key={v} value={v}>{r}</option>)}</select>
              </Campo>
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
              <Campo rotulo="Cliente">
                <select value={form.cliente_id} onChange={mudar('cliente_id')} className={CLASSE_ENTRADA}>
                  <option value="">Nenhum</option>{dados.clientes.map((c) => <option key={c.id} value={c.id}>{c.nome}</option>)}
                </select>
              </Campo>
              <Campo rotulo="Responsável">
                <select value={form.responsavel_id} onChange={mudar('responsavel_id')} className={CLASSE_ENTRADA}>
                  <option value="">Eu mesmo</option>{dados.equipe.map((u) => <option key={u.id} value={u.id}>{u.nome}</option>)}
                </select>
              </Campo>
            </div>
            {form.tarefa_id && (
              <Campo rotulo="Situação">
                <select value={form.status} onChange={mudar('status')} className={CLASSE_ENTRADA}>{Object.entries(STATUS).map(([v, r]) => <option key={v} value={v}>{r}</option>)}</select>
              </Campo>
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
