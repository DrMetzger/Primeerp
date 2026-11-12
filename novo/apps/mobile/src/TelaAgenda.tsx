import DateTimePicker from '@react-native-community/datetimepicker';
import type { Agenda, EventoCliente, HorarioTrabalho, TarefaAgenda } from '@primehub/shared';
import { LinearGradient } from 'expo-linear-gradient';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Linking, PanResponder, Pressable, RefreshControl, ScrollView, TextInput, View } from 'react-native';
import { acaoPhp, api } from './api';
import { Esqueleto } from './Esqueleto';
import { Acoes, Aviso, CampoDataHora, confirmar, Folha, paraHora } from './formularios';
import { useTema } from './tema';
import { BotaoContorno, BotaoGradiente, Entrada, Escolha, LogoEmpresa, Vazio } from './Telas';
import { Cartao, T } from './ui';

/**
 * Agenda da equipe no app, igual ao site: Dia (linha do tempo do expediente,
 * segurar e arrastar muda o horário), Semana e Mês; IA que monta a agenda
 * pelos chamados abertos e pelo horário de trabalho; meu horário.
 */

type Visao = 'dia' | 'semana' | 'mes';
const DIAS_LONGOS = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
const MESES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
const STATUS = { pendente: 'Pendente', em_andamento: 'Em andamento', concluida: 'Concluída' } as const;
const DURACOES = [['30', '30 min'], ['45', '45 min'], ['60', '1h'], ['90', '1h30'], ['120', '2h'], ['180', '3h'], ['240', '4h']] as const;
const PX_MIN = 1.1; // 66px por hora
const PASSO = 15; // arrastar encaixa de 15 em 15 minutos
const CORES = ['#0ea5e9', '#8b5cf6', '#f59e0b', '#10b981', '#f43f5e', '#06b6d4', '#f97316', '#84cc16', '#d946ef', '#6366f1'];

const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const meioDia = (d: Date) => { const x = new Date(d); x.setHours(12, 0, 0, 0); return x; };
const somarDias = (d: Date, n: number) => { const x = new Date(d); x.setDate(x.getDate() + n); return x; };
const paraMin = (h: string | null | undefined) => { if (!h) return null; const [a, b] = h.split(':').map(Number); return (a ?? 0) * 60 + (b ?? 0); };
const deMin = (m: number) => `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`;
const corCliente = (id: number | null) => (id ? CORES[id % CORES.length] ?? '#0ea5e9' : '#64748b');

interface FormTarefa { tarefa_id: string; titulo: string; data: string; hora_inicio: string; duracao_minutos: string; cliente_id: string; responsavel_id: string; notas: string; status: string; chamado_id: string }
const formVazio = (data: string, hora = ''): FormTarefa => ({ tarefa_id: '', titulo: '', data, hora_inicio: hora, duracao_minutos: '60', cliente_id: '', responsavel_id: '', notas: '', status: 'pendente', chamado_id: '' });

function intervalo(visao: Visao, ancora: Date): [Date, Date] {
  if (visao === 'dia') return [ancora, ancora];
  if (visao === 'semana') { const ini = somarDias(ancora, -((ancora.getDay() + 6) % 7)); return [ini, somarDias(ini, 6)]; }
  const ini = new Date(ancora.getFullYear(), ancora.getMonth(), 1, 12);
  const fim = new Date(ancora.getFullYear(), ancora.getMonth() + 1, 0, 12);
  return [somarDias(ini, -((ini.getDay() + 6) % 7)), somarDias(fim, (7 - fim.getDay()) % 7)];
}

export function TelaAgenda({ aoAbrirChamado }: { aoAbrirChamado: (id: number) => void }) {
  const { tema } = useTema();
  const [visao, setVisao] = useState<Visao>('dia');
  const [ancora, setAncora] = useState(() => meioDia(new Date()));
  const [dados, setDados] = useState<Agenda | null>(null);
  const [atualizando, setAtualizando] = useState(false);
  const [form, setForm] = useState<FormTarefa | null>(null);
  const [mover, setMover] = useState<{ tipo: 'tarefa'; t: TarefaAgenda; data: string; hora: string } | { tipo: 'evento'; e: EventoCliente; data: string; hora: string } | null>(null);
  const [ia, setIa] = useState<{ periodo: Visao; clientes: number[]; busca: string; rodando: '' | 'texto' | 'tarefas'; resposta: string; erro: boolean } | null>(null);
  const [horario, setHorario] = useState<HorarioTrabalho | null>(null);
  const [aviso, setAviso] = useState<{ t: string; e: boolean }>({ t: '', e: false });
  const [ocupado, setOcupado] = useState(false);
  const [rolar, setRolar] = useState(true);

  const [ini, fim] = intervalo(visao, ancora);
  const de = iso(ini), ate = iso(fim);
  const carregar = useCallback(() => api<Agenda>(`/api/agenda?de=${de}&ate=${ate}`).then(setDados).catch(() => setDados((d) => d ?? { tarefas: [], eventos: [], clientes: [], equipe: [], horario: { expedienteInicio: '08:00', expedienteFim: '18:00', almocoInicio: '12:00', almocoFim: '13:00' } })).finally(() => setAtualizando(false)), [de, ate]);
  useEffect(() => { void carregar(); }, [carregar]);

  async function php<R = { sucesso?: boolean; mensagem?: string }>(arquivo: string, campos: Record<string, string>): Promise<R> {
    return acaoPhp<R & { ok: boolean }>(arquivo, campos);
  }

  async function moverTarefa(t: TarefaAgenda, data: string, hora: string) {
    setDados((d) => d && { ...d, tarefas: d.tarefas.map((x) => (x.id === t.id ? { ...x, data, horaInicio: hora || null } : x)) });
    await php('agenda.php', { acao: 'mover_tarefa', ajax: '1', tarefa_id: String(t.id), data, hora_inicio: hora, duracao_minutos: String(t.duracaoMinutos ?? 60) }).catch(() => undefined);
    void carregar();
  }
  async function moverEvento(e: EventoCliente, data: string, hora: string) {
    setDados((d) => d && { ...d, eventos: d.eventos.map((x) => (x.id === e.id ? { ...x, inicio: `${data} ${hora || '09:00'}:00` } : x)) });
    await php('api_acoes.php', { acao: 'mover_treinamento', treinamento_id: String(e.id), data, hora_inicio: hora || '09:00' }).catch(() => undefined);
    void carregar();
  }
  async function feita(t: TarefaAgenda) {
    const status = t.status === 'concluida' ? 'pendente' : 'concluida';
    setDados((d) => d && { ...d, tarefas: d.tarefas.map((x) => (x.id === t.id ? { ...x, status } : x)) });
    await php('agenda.php', { acao: 'mudar_status_tarefa', ajax: '1', tarefa_id: String(t.id), status }).catch(() => void carregar());
  }
  async function salvar() {
    if (!form) return;
    setOcupado(true); setAviso({ t: '', e: false });
    try { await php('agenda.php', { ...form, acao: form.tarefa_id ? 'atualizar_tarefa' : 'criar_tarefa' }); setForm(null); setAviso({ t: 'Tarefa salva.', e: false }); void carregar(); }
    catch (e) { setAviso({ t: e instanceof Error ? e.message : 'Não deu pra salvar.', e: true }); }
    setOcupado(false);
  }
  async function excluir() {
    if (!form?.tarefa_id || !(await confirmar('Excluir tarefa', `Excluir "${form.titulo}"?`, 'Excluir'))) return;
    try { await php('agenda.php', { acao: 'excluir_tarefa', tarefa_id: form.tarefa_id }); setForm(null); void carregar(); }
    catch (e) { setAviso({ t: e instanceof Error ? e.message : 'Não deu pra excluir.', e: true }); }
  }
  async function rodarIa(criar: boolean) {
    if (!ia) return;
    setIa({ ...ia, rodando: criar ? 'tarefas' : 'texto', resposta: '', erro: false });
    try {
      const j = await php<{ sucesso?: boolean; mensagem?: string; tipo?: string; sugestao?: string; resumo?: string; totalCriadas?: number; dataFoco?: string }>('agenda.php',
        { acao: 'gerar_sugestao_ia', ajax: '1', periodo: ia.periodo, data: iso(ancora), cliente_ids: ia.clientes.join(','), criar_tarefas: criar ? '1' : '0' });
      if (!j.sucesso) { setIa((x) => x && { ...x, rodando: '', resposta: j.mensagem ?? 'A IA não conseguiu montar agora. Tente de novo.', erro: true }); return; }
      const resposta = j.tipo === 'tarefas' ? `${j.resumo ?? ''}\n\n✅ ${j.totalCriadas ?? 0} tarefa(s) criada(s) na agenda.` : (j.sugestao ?? '');
      setIa((x) => x && { ...x, rodando: '', resposta: resposta.replace(/\*\*/g, ''), erro: false });
      if (j.tipo === 'tarefas') { if (j.dataFoco) setAncora(meioDia(new Date(`${j.dataFoco}T12:00`))); void carregar(); }
    } catch { setIa((x) => x && { ...x, rodando: '', resposta: 'Não deu para falar com a IA agora. Tente de novo em instantes.', erro: true }); }
  }
  async function salvarHorario() {
    if (!horario) return;
    setOcupado(true); setAviso({ t: '', e: false });
    try {
      const j = await php('agenda.php', { acao: 'salvar_horario_trabalho', ajax: '1', expediente_inicio: horario.expedienteInicio, expediente_fim: horario.expedienteFim, almoco_inicio: horario.almocoInicio, almoco_fim: horario.almocoFim });
      if (j.sucesso === false) setAviso({ t: j.mensagem ?? 'Horário inválido.', e: true }); else { setHorario(null); setAviso({ t: 'Horário salvo.', e: false }); void carregar(); }
    } catch { setAviso({ t: 'Não deu para salvar agora.', e: true }); }
    setOcupado(false);
  }

  const editar = (t: TarefaAgenda) => setForm({ tarefa_id: String(t.id), titulo: t.titulo, data: t.data, hora_inicio: t.horaInicio?.slice(0, 5) ?? '', duracao_minutos: String(t.duracaoMinutos ?? 60), cliente_id: t.clienteId ? String(t.clienteId) : '', responsavel_id: t.responsavelId ? String(t.responsavelId) : '', notas: t.notas ?? '', status: t.status, chamado_id: t.chamadoId ? String(t.chamadoId) : '' });
  const nav = (n: number) => setAncora((a) => (visao === 'dia' ? somarDias(a, n) : visao === 'semana' ? somarDias(a, 7 * n) : meioDia(new Date(a.getFullYear(), a.getMonth() + n, 1))));
  const titulo = visao === 'dia' ? `${DIAS_LONGOS[ancora.getDay()]}, ${ancora.getDate()} de ${MESES[ancora.getMonth()]}`
    : visao === 'semana' ? `${ini.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' })} a ${fim.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' })}`
      : `${MESES[ancora.getMonth()]} de ${ancora.getFullYear()}`;

  const tarefasDoDia = (dia: string) => (dados?.tarefas ?? []).filter((t) => t.data === dia).sort((a, b) => (a.horaInicio ?? '99').localeCompare(b.horaInicio ?? '99'));
  const eventosDoDia = (dia: string) => (dados?.eventos ?? []).filter((e) => e.inicio?.slice(0, 10) === dia);
  const pendentes = dados?.tarefas.filter((t) => t.status !== 'concluida').length ?? 0;
  const concluidas = dados?.tarefas.filter((t) => t.status === 'concluida').length ?? 0;

  return (
    <>
      <ScrollView scrollEnabled={rolar} contentContainerStyle={{ padding: 16, gap: 12 }} refreshControl={<RefreshControl refreshing={atualizando} onRefresh={() => { setAtualizando(true); void carregar(); }} colors={[tema.primaria]} />}>
        <View style={{ flexDirection: 'row', backgroundColor: tema.superficie, borderRadius: 16, padding: 4, borderWidth: 1, borderColor: tema.borda }}>
          {(['dia', 'semana', 'mes'] as const).map((v) => (
            <Pressable key={v} onPress={() => setVisao(v)} accessibilityRole="tab" accessibilityState={{ selected: visao === v }}
              style={{ flex: 1, minHeight: 42, borderRadius: 12, alignItems: 'center', justifyContent: 'center', backgroundColor: visao === v ? tema.primaria : 'transparent' }}>
              <T style={{ fontWeight: '800', fontSize: 15, color: visao === v ? tema.primariaTexto : tema.textoSuave }}>{{ dia: 'Dia', semana: 'Semana', mes: 'Mês' }[v]}</T>
            </Pressable>
          ))}
        </View>
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }}>
          <Pressable onPress={() => nav(-1)} accessibilityRole="button" accessibilityLabel="Anterior" style={{ width: 46, height: 46, borderRadius: 14, backgroundColor: tema.superficie, borderWidth: 1, borderColor: tema.borda, alignItems: 'center', justifyContent: 'center' }}><T style={{ fontSize: 22, fontWeight: '800' }}>‹</T></Pressable>
          <Pressable onPress={() => setAncora(meioDia(new Date()))} style={{ flex: 1, alignItems: 'center' }} accessibilityRole="button" accessibilityLabel="Voltar para hoje">
            <T style={{ fontWeight: '800', fontSize: 16, textAlign: 'center' }}>{titulo}</T>
            <T style={{ color: tema.primaria, fontSize: 12, fontWeight: '700' }}>Hoje</T>
          </Pressable>
          <Pressable onPress={() => nav(1)} accessibilityRole="button" accessibilityLabel="Próximo" style={{ width: 46, height: 46, borderRadius: 14, backgroundColor: tema.superficie, borderWidth: 1, borderColor: tema.borda, alignItems: 'center', justifyContent: 'center' }}><T style={{ fontSize: 22, fontWeight: '800' }}>›</T></Pressable>
        </View>
        <View style={{ flexDirection: 'row', gap: 8 }}>
          <Pressable onPress={() => setIa({ periodo: visao, clientes: [], busca: '', rodando: '', resposta: '', erro: false })} accessibilityRole="button" style={{ flex: 1.4, borderRadius: 16, overflow: 'hidden' }}>
            <LinearGradient colors={['#d946ef', '#7c3aed']} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={{ minHeight: 50, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 10 }}>
              <T style={{ color: '#fff', fontWeight: '800', fontSize: 15 }}>✨ Montar com IA</T>
            </LinearGradient>
          </Pressable>
          <Pressable onPress={() => setForm(formVazio(iso(ancora)))} accessibilityRole="button" style={{ flex: 1, borderRadius: 16, overflow: 'hidden' }}>
            <LinearGradient colors={['#1E3FD8', '#0FB5C9']} start={{ x: 0, y: 0 }} end={{ x: 1, y: 0 }} style={{ minHeight: 50, alignItems: 'center', justifyContent: 'center' }}>
              <T style={{ color: '#fff', fontWeight: '800', fontSize: 15 }}>＋ Tarefa</T>
            </LinearGradient>
          </Pressable>
          <Pressable onPress={() => dados && setHorario({ ...dados.horario })} accessibilityRole="button" accessibilityLabel="Meu horário de trabalho"
            style={{ width: 50, minHeight: 50, borderRadius: 16, backgroundColor: tema.superficie, borderWidth: 1, borderColor: tema.borda, alignItems: 'center', justifyContent: 'center' }}><T style={{ fontSize: 20 }}>🕘</T></Pressable>
        </View>
        <View style={{ flexDirection: 'row', gap: 8 }}>
          {([['⏳', pendentes, 'pendentes', '#f59e0b'], ['✅', concluidas, 'concluídas', '#10b981'], ['🎥', dados?.eventos.length ?? 0, 'reuniões', '#8b5cf6']] as const).map(([i, n, r, cor]) => (
            <View key={r} style={{ flex: 1, borderRadius: 16, padding: 10, backgroundColor: `${cor}1F`, borderWidth: 1, borderColor: `${cor}55` }}>
              <T style={{ fontSize: 20, fontWeight: '800' }}>{i} {n}</T>
              <T style={{ fontSize: 12, color: tema.textoSuave, fontWeight: '700' }}>{r}</T>
            </View>
          ))}
        </View>
        {!form && !horario && <Aviso texto={aviso.t} erro={aviso.e} />}

        {!dados ? [0, 1, 2].map((i) => <Esqueleto key={i} altura={120} />) : visao === 'dia' ? (
          <LinhaDoTempo dia={iso(ancora)} horario={dados.horario} tarefas={tarefasDoDia(iso(ancora))} eventos={eventosDoDia(iso(ancora))}
            aoArrastar={setRolar} aoMoverTarefa={(t, h) => void moverTarefa(t, t.data, h)} aoMoverEvento={(e, h) => void moverEvento(e, e.inicio?.slice(0, 10) ?? iso(ancora), h)}
            aoTocarTarefa={editar} aoTocarEvento={(e) => setMover({ tipo: 'evento', e, data: e.inicio?.slice(0, 10) ?? '', hora: e.inicio?.slice(11, 16) ?? '' })}
            aoFeita={(t) => void feita(t)} aoNovaNaHora={(h) => setForm(formVazio(iso(ancora), h))} />
        ) : visao === 'semana' ? (
          Array.from({ length: 7 }, (_, i) => somarDias(ini, i)).map((d) => {
            const dia = iso(d);
            const ts = tarefasDoDia(dia), es = eventosDoDia(dia);
            const hoje = dia === iso(new Date());
            return (
              <Cartao key={dia} style={{ gap: 8, borderColor: hoje ? tema.primaria : tema.borda, borderWidth: hoje ? 2 : 1 }}>
                <Pressable onPress={() => { setAncora(meioDia(d)); setVisao('dia'); }} style={{ flexDirection: 'row', alignItems: 'center' }} accessibilityRole="button">
                  <T style={{ flex: 1, fontWeight: '800', color: hoje ? tema.primaria : tema.texto }}>{DIAS_LONGOS[d.getDay()]} · {d.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' })}</T>
                  <T style={{ color: tema.primaria, fontSize: 13, fontWeight: '700' }}>ver dia ›</T>
                </Pressable>
                {es.map((e) => (
                  <Pressable key={`e${e.id}`} onPress={() => setMover({ tipo: 'evento', e, data: dia, hora: e.inicio?.slice(11, 16) ?? '' })} style={{ flexDirection: 'row', gap: 10, alignItems: 'center', padding: 8, borderRadius: 12, backgroundColor: '#8b5cf622', borderLeftWidth: 4, borderLeftColor: '#8b5cf6' }}>
                    <LogoEmpresa nome={e.cliente} logo={e.clienteLogo} tamanho={32} />
                    <View style={{ flex: 1 }}><T style={{ fontWeight: '700', fontSize: 14 }}>🎥 {e.titulo}</T><T style={{ color: tema.textoSuave, fontSize: 13 }}>{e.inicio?.slice(11, 16)} · {e.cliente}</T></View>
                  </Pressable>
                ))}
                {ts.map((t) => (
                  <ItemTarefa key={t.id} t={t} aoFeita={() => void feita(t)} aoTocar={() => editar(t)} aoSegurar={() => setMover({ tipo: 'tarefa', t, data: t.data, hora: t.horaInicio?.slice(0, 5) ?? '' })} aoChamado={aoAbrirChamado} />
                ))}
                {!ts.length && !es.length && <Pressable onPress={() => setForm(formVazio(dia))}><T style={{ color: tema.textoSuave, fontSize: 14 }}>Livre · toque para adicionar</T></Pressable>}
              </Cartao>
            );
          })
        ) : (
          <Mes ini={ini} fim={fim} mes={ancora.getMonth()} tarefas={dados.tarefas} eventos={dados.eventos} aoAbrirDia={(d) => { setAncora(meioDia(d)); setVisao('dia'); }} />
        )}
        {visao !== 'mes' && <T style={{ color: tema.textoSuave, fontSize: 12, textAlign: 'center' }}>{visao === 'dia' ? 'Segure uma tarefa ou reunião e arraste para mudar o horário.' : 'Segure uma tarefa para mudar o dia ou a hora.'}</T>}
      </ScrollView>

      {/* Tarefa */}
      <Folha aberta={!!form} aoFechar={() => setForm(null)} titulo={form?.tarefa_id ? 'Editar tarefa' : 'Nova tarefa'}
        rodape={form && <><Aviso texto={aviso.e ? aviso.t : ''} erro /><BotaoGradiente titulo={ocupado ? 'Salvando…' : 'Salvar'} aoTocar={() => void salvar()} desativado={ocupado || !form.titulo.trim() || !form.data} />{!!form.tarefa_id && <BotaoContorno titulo="🗑 Excluir tarefa" aoTocar={() => void excluir()} />}</>}>
        {form && dados && (<>
          <Entrada rotulo="Título *" valor={form.titulo} aoMudar={(v) => setForm({ ...form, titulo: v })} />
          <CampoDataHora rotulo="Quando *" data={form.data} hora={form.hora_inicio} aoMudar={(d, h) => setForm({ ...form, data: d, hora_inicio: h })} />
          <Escolha rotulo="Duração" opcoes={DURACOES.map(([valor, rotulo]) => ({ valor, rotulo }))} valor={form.duracao_minutos} aoMudar={(v) => setForm({ ...form, duracao_minutos: v })} />
          <EscolhaCliente clientes={dados.clientes} valor={form.cliente_id} aoMudar={(v) => setForm({ ...form, cliente_id: v })} />
          {dados.equipe.length > 0 && <Escolha rotulo="Responsável" opcoes={[{ valor: '', rotulo: 'Eu' }, ...dados.equipe.map((u) => ({ valor: String(u.id), rotulo: u.nome }))]} valor={form.responsavel_id} aoMudar={(v) => setForm({ ...form, responsavel_id: v })} />}
          {!!form.tarefa_id && <Escolha rotulo="Situação" opcoes={Object.entries(STATUS).map(([valor, rotulo]) => ({ valor, rotulo }))} valor={form.status} aoMudar={(v) => setForm({ ...form, status: v })} />}
          <Entrada rotulo="Anotações" valor={form.notas} aoMudar={(v) => setForm({ ...form, notas: v })} linhas={4} />
          {!!form.chamado_id && <BotaoContorno titulo={`🎧 Abrir chamado #${form.chamado_id}`} aoTocar={() => { const id = Number(form.chamado_id); setForm(null); aoAbrirChamado(id); }} />}
        </>)}
      </Folha>

      {/* Mover para outro dia/hora */}
      <Folha aberta={!!mover} aoFechar={() => setMover(null)} titulo={mover?.tipo === 'evento' ? mover.e.titulo : 'Mover tarefa'}
        rodape={mover && <BotaoGradiente titulo="Mover" desativado={!mover.data} aoTocar={() => { if (mover.tipo === 'tarefa') void moverTarefa(mover.t, mover.data, mover.hora); else void moverEvento(mover.e, mover.data, mover.hora); setMover(null); }} />}>
        {mover && (<>
          {mover.tipo === 'tarefa' && <T style={{ fontWeight: '700' }}>{mover.t.titulo}</T>}
          {mover.tipo === 'evento' && <T style={{ color: tema.textoSuave }}>{[mover.e.cliente, mover.e.setor, mover.e.tipo].filter(Boolean).join(' · ')}</T>}
          <CampoDataHora rotulo="Para quando" data={mover.data} hora={mover.hora} aoMudar={(d, h) => setMover({ ...mover, data: d, hora: h })} />
          <Acoes itens={[
            { rotulo: '‹ Dia anterior', aoTocar: () => setMover({ ...mover, data: iso(somarDias(new Date(`${mover.data}T12:00`), -1)) }) },
            { rotulo: 'Próximo dia ›', aoTocar: () => setMover({ ...mover, data: iso(somarDias(new Date(`${mover.data}T12:00`), 1)) }) },
            ...(mover.tipo === 'tarefa' ? [{ rotulo: '✏️ Editar tarefa', aoTocar: () => { const t = mover.t; setMover(null); editar(t); } }] : []),
            ...(mover.tipo === 'evento' && mover.e.link ? [{ rotulo: '🎥 Entrar na reunião', aoTocar: () => void Linking.openURL(mover.e.link ?? '') }] : []),
          ]} />
        </>)}
      </Folha>

      {/* IA */}
      <Folha aberta={!!ia} aoFechar={() => setIa(null)} titulo="✨ Montar agenda com IA"
        rodape={ia && <>
          <BotaoGradiente titulo={ia.rodando === 'tarefas' ? 'Montando e criando…' : '📅 Montar e criar as tarefas'} desativado={!!ia.rodando} aoTocar={() => void rodarIa(true)} />
          <BotaoContorno titulo={ia.rodando === 'texto' ? 'Pensando…' : '💬 Só sugerir (não cria nada)'} desativado={!!ia.rodando} aoTocar={() => void rodarIa(false)} />
        </>}>
        {ia && dados && (<>
          <T style={{ color: tema.textoSuave, fontSize: 14 }}>A IA olha os chamados abertos e as pendências dos clientes, prioriza e encaixa tudo no seu expediente ({dados.horario.expedienteInicio}–{dados.horario.expedienteFim}), respeitando o almoço.</T>
          <Escolha rotulo="Período" opcoes={[{ valor: 'dia', rotulo: 'Dia' }, { valor: 'semana', rotulo: 'Semana' }, { valor: 'mes', rotulo: 'Mês' }]} valor={ia.periodo} aoMudar={(v) => setIa({ ...ia, periodo: v as Visao })} />
          <T style={{ fontWeight: '700', fontSize: 14 }}>Clientes (nenhum marcado = todos)</T>
          <TextInput value={ia.busca} onChangeText={(v) => setIa({ ...ia, busca: v })} placeholder="🔍 Filtrar clientes" placeholderTextColor={tema.textoSuave}
            style={{ minHeight: 46, borderRadius: 14, borderWidth: 1, borderColor: tema.borda, backgroundColor: tema.superficie, color: tema.texto, paddingHorizontal: 14, fontSize: 15 }} />
          <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8 }}>
            {dados.clientes.filter((c) => !ia.busca || c.nome.toLowerCase().includes(ia.busca.toLowerCase())).slice(0, 40).map((c) => {
              const marcado = ia.clientes.includes(c.id);
              return (
                <Pressable key={c.id} onPress={() => setIa({ ...ia, clientes: marcado ? ia.clientes.filter((x) => x !== c.id) : [...ia.clientes, c.id] })} accessibilityRole="checkbox" accessibilityState={{ checked: marcado }}
                  style={{ minHeight: 40, borderRadius: 99, paddingHorizontal: 12, justifyContent: 'center', backgroundColor: marcado ? '#7c3aed' : tema.superficieAlta, borderWidth: 1, borderColor: marcado ? '#7c3aed' : tema.borda }}>
                  <T style={{ fontSize: 13, fontWeight: '700', color: marcado ? '#fff' : tema.texto }}>{marcado ? '✓ ' : ''}{c.nome}</T>
                </Pressable>
              );
            })}
          </View>
          {!!ia.rodando && <Esqueleto altura={120} />}
          {!!ia.resposta && <Cartao style={{ borderColor: ia.erro ? tema.perigo : '#7c3aed', borderWidth: 1 }}><T selectable style={{ fontSize: 15, lineHeight: 22, color: ia.erro ? tema.perigo : tema.texto }}>{ia.resposta}</T></Cartao>}
        </>)}
      </Folha>

      {/* Meu horário */}
      <Folha aberta={!!horario} aoFechar={() => setHorario(null)} titulo="🕘 Meu horário de trabalho"
        rodape={horario && <><Aviso texto={aviso.e ? aviso.t : ''} erro /><BotaoGradiente titulo={ocupado ? 'Salvando…' : 'Salvar horário'} aoTocar={() => void salvarHorario()} desativado={ocupado} /></>}>
        {horario && (<>
          <T style={{ color: tema.textoSuave, fontSize: 14 }}>A linha do tempo e a IA usam esse horário para montar a sua agenda.</T>
          <View style={{ flexDirection: 'row', gap: 10 }}>
            <CampoHora rotulo="Começo do expediente" valor={horario.expedienteInicio} aoMudar={(v) => setHorario({ ...horario, expedienteInicio: v })} />
            <CampoHora rotulo="Fim do expediente" valor={horario.expedienteFim} aoMudar={(v) => setHorario({ ...horario, expedienteFim: v })} />
          </View>
          <View style={{ flexDirection: 'row', gap: 10 }}>
            <CampoHora rotulo="Saída do almoço" valor={horario.almocoInicio} aoMudar={(v) => setHorario({ ...horario, almocoInicio: v })} />
            <CampoHora rotulo="Volta do almoço" valor={horario.almocoFim} aoMudar={(v) => setHorario({ ...horario, almocoFim: v })} />
          </View>
        </>)}
      </Folha>
    </>
  );
}

// ---------------------------------------------------------------- Peças

function ItemTarefa({ t, aoFeita, aoTocar, aoSegurar, aoChamado }: { t: TarefaAgenda; aoFeita: () => void; aoTocar: () => void; aoSegurar: () => void; aoChamado: (id: number) => void }) {
  const { tema } = useTema();
  const ok = t.status === 'concluida';
  return (
    <View style={{ flexDirection: 'row', gap: 10, alignItems: 'center', paddingLeft: 8, borderLeftWidth: 4, borderLeftColor: corCliente(t.clienteId), borderRadius: 4 }}>
      <Pressable onPress={aoFeita} accessibilityRole="checkbox" accessibilityState={{ checked: ok }} accessibilityLabel={`Feita: ${t.titulo}`} hitSlop={6}
        style={{ width: 32, height: 32, borderRadius: 10, borderWidth: 2, borderColor: ok ? tema.sucesso : tema.borda, backgroundColor: ok ? tema.sucesso : 'transparent', alignItems: 'center', justifyContent: 'center' }}>
        {ok && <T style={{ color: '#FFFFFF', fontWeight: '800' }}>✓</T>}
      </Pressable>
      <Pressable style={{ flex: 1, minHeight: 44, justifyContent: 'center' }} onPress={aoTocar} onLongPress={aoSegurar} delayLongPress={350}>
        <T style={{ fontWeight: '700', fontSize: 15, textDecorationLine: ok ? 'line-through' : 'none' }}>{t.titulo}</T>
        <T style={{ color: tema.textoSuave, fontSize: 13 }}>{[t.horaInicio?.slice(0, 5), t.cliente, t.responsavel].filter(Boolean).join(' · ')}</T>
      </Pressable>
      {t.chamadoId && <Pressable onPress={() => aoChamado(t.chamadoId ?? 0)} hitSlop={8}><T style={{ color: tema.primaria, fontWeight: '700', fontSize: 13 }}>#{t.chamadoId}</T></Pressable>}
    </View>
  );
}

function EscolhaCliente({ clientes, valor, aoMudar }: { clientes: { id: number; nome: string }[]; valor: string; aoMudar: (v: string) => void }) {
  const { tema } = useTema();
  const [busca, setBusca] = useState('');
  const atual = clientes.find((c) => String(c.id) === valor);
  const lista = clientes.filter((c) => !busca || c.nome.toLowerCase().includes(busca.toLowerCase())).slice(0, busca ? 30 : 12);
  return (
    <View style={{ gap: 6 }}>
      <T style={{ fontWeight: '700', fontSize: 14 }}>Cliente{atual ? `: ${atual.nome}` : ''}</T>
      <TextInput value={busca} onChangeText={setBusca} placeholder="🔍 Buscar cliente" placeholderTextColor={tema.textoSuave}
        style={{ minHeight: 46, borderRadius: 14, borderWidth: 1, borderColor: tema.borda, backgroundColor: tema.superficie, color: tema.texto, paddingHorizontal: 14, fontSize: 15 }} />
      <Escolha rotulo="" opcoes={[{ valor: '', rotulo: 'Nenhum' }, ...lista.map((c) => ({ valor: String(c.id), rotulo: c.nome }))]} valor={valor} aoMudar={aoMudar} />
    </View>
  );
}

function CampoHora({ rotulo, valor, aoMudar }: { rotulo: string; valor: string; aoMudar: (v: string) => void }) {
  const { tema } = useTema();
  const [abrir, setAbrir] = useState(false);
  return (
    <View style={{ flex: 1, gap: 6 }}>
      <T style={{ fontWeight: '700', fontSize: 13 }}>{rotulo}</T>
      <Pressable onPress={() => setAbrir(true)} accessibilityRole="button" accessibilityLabel={`${rotulo}: ${valor}`}
        style={{ minHeight: 50, borderRadius: 14, borderWidth: 1, borderColor: tema.borda, backgroundColor: tema.superficie, alignItems: 'center', justifyContent: 'center', flexDirection: 'row', gap: 8 }}>
        <T style={{ fontSize: 18 }}>🕘</T><T style={{ fontSize: 18, fontWeight: '800' }}>{valor.slice(0, 5)}</T>
      </Pressable>
      {abrir && <DateTimePicker value={new Date(`2000-01-01T${valor.slice(0, 5) || '08:00'}:00`)} mode="time" is24Hour onChange={(ev, d) => { setAbrir(false); if (ev.type === 'set' && d) aoMudar(paraHora(d)); }} />}
    </View>
  );
}

function Mes({ ini, fim, mes, tarefas, eventos, aoAbrirDia }: { ini: Date; fim: Date; mes: number; tarefas: TarefaAgenda[]; eventos: EventoCliente[]; aoAbrirDia: (d: Date) => void }) {
  const { tema } = useTema();
  const dias: Date[] = [];
  for (let d = new Date(ini); d <= fim; d = somarDias(d, 1)) dias.push(d);
  const hoje = iso(new Date());
  return (
    <Cartao style={{ padding: 8 }}>
      <View style={{ flexDirection: 'row' }}>{['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'].map((d) => <T key={d} style={{ flex: 1, textAlign: 'center', fontSize: 12, fontWeight: '800', color: tema.textoSuave, paddingVertical: 4 }}>{d}</T>)}</View>
      <View style={{ flexDirection: 'row', flexWrap: 'wrap' }}>
        {dias.map((d) => {
          const dia = iso(d);
          const ts = tarefas.filter((t) => t.data === dia);
          const es = eventos.filter((e) => e.inicio?.slice(0, 10) === dia);
          const pend = ts.filter((t) => t.status !== 'concluida').length;
          const ehHoje = dia === hoje;
          return (
            <Pressable key={dia} onPress={() => aoAbrirDia(d)} accessibilityRole="button" accessibilityLabel={`${d.getDate()}: ${ts.length} tarefas, ${es.length} reuniões`}
              style={{ width: `${100 / 7}%`, aspectRatio: 0.78, padding: 3, opacity: d.getMonth() === mes ? 1 : 0.4 }}>
              <View style={{ flex: 1, borderRadius: 12, padding: 4, backgroundColor: ehHoje ? `${tema.primaria}22` : tema.superficieAlta, borderWidth: ehHoje ? 2 : 0, borderColor: tema.primaria, gap: 3 }}>
                <T style={{ fontSize: 13, fontWeight: '800', color: ehHoje ? tema.primaria : tema.texto }}>{d.getDate()}</T>
                {pend > 0 && <View style={{ borderRadius: 6, backgroundColor: '#f59e0b', paddingHorizontal: 3 }}><T style={{ fontSize: 10, color: '#fff', fontWeight: '800', textAlign: 'center' }}>{pend} ⏳</T></View>}
                {es.length > 0 && <View style={{ borderRadius: 6, backgroundColor: '#8b5cf6', paddingHorizontal: 3 }}><T style={{ fontSize: 10, color: '#fff', fontWeight: '800', textAlign: 'center' }}>{es.length} 🎥</T></View>}
                <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 2 }}>{ts.filter((t) => t.status === 'concluida').slice(0, 4).map((t) => <View key={t.id} style={{ width: 6, height: 6, borderRadius: 3, backgroundColor: '#10b981' }} />)}</View>
              </View>
            </Pressable>
          );
        })}
      </View>
    </Cartao>
  );
}

/** Linha do tempo do dia: expediente, almoço listrado, blocos arrastáveis (segurar e arrastar). */
function LinhaDoTempo({ dia, horario, tarefas, eventos, aoArrastar, aoMoverTarefa, aoMoverEvento, aoTocarTarefa, aoTocarEvento, aoFeita, aoNovaNaHora }: {
  dia: string; horario: HorarioTrabalho; tarefas: TarefaAgenda[]; eventos: EventoCliente[];
  aoArrastar: (rolar: boolean) => void; aoMoverTarefa: (t: TarefaAgenda, hora: string) => void; aoMoverEvento: (e: EventoCliente, hora: string) => void;
  aoTocarTarefa: (t: TarefaAgenda) => void; aoTocarEvento: (e: EventoCliente) => void; aoFeita: (t: TarefaAgenda) => void; aoNovaNaHora: (hora: string) => void;
}) {
  const { tema } = useTema();
  const comHora = tarefas.filter((t) => t.horaInicio);
  const semHora = tarefas.filter((t) => !t.horaInicio);
  const minutos = [...comHora.map((t) => paraMin(t.horaInicio) ?? 0), ...eventos.map((e) => paraMin(e.inicio?.slice(11, 16)) ?? 0)];
  const fins = [...comHora.map((t) => (paraMin(t.horaInicio) ?? 0) + (t.duracaoMinutos ?? 60)), ...eventos.map((e) => paraMin(e.fim?.slice(11, 16)) ?? (paraMin(e.inicio?.slice(11, 16)) ?? 0) + 60)];
  const inicio = Math.floor(Math.min(paraMin(horario.expedienteInicio) ?? 480, ...minutos) / 60) * 60;
  const termino = Math.ceil(Math.max(paraMin(horario.expedienteFim) ?? 1080, ...fins) / 60) * 60;
  const altura = (termino - inicio) * PX_MIN;
  const almocoIni = paraMin(horario.almocoInicio), almocoFim = paraMin(horario.almocoFim);
  const agora = new Date();
  const minAgora = agora.getHours() * 60 + agora.getMinutes();
  const ehHoje = dia === iso(agora);

  // Blocos lado a lado quando se sobrepõem.
  type Bloco = { chave: string; ini: number; dur: number; tarefa?: TarefaAgenda; evento?: EventoCliente; col: number; cols: number };
  const blocos = useMemo(() => {
    const lista: Bloco[] = [
      ...comHora.map((t) => ({ chave: `t${t.id}`, ini: paraMin(t.horaInicio) ?? 0, dur: t.duracaoMinutos ?? 60, tarefa: t, col: 0, cols: 1 })),
      ...eventos.map((e) => { const i = paraMin(e.inicio?.slice(11, 16)) ?? 540; const f = paraMin(e.fim?.slice(11, 16)); return { chave: `e${e.id}`, ini: i, dur: f && f > i ? f - i : 60, evento: e, col: 0, cols: 1 }; }),
    ].sort((a, b) => a.ini - b.ini);
    const grupos: Bloco[][] = [];
    for (const b of lista) {
      const g = grupos.find((gr) => gr.some((x) => b.ini < x.ini + x.dur && x.ini < b.ini + b.dur));
      if (g) g.push(b); else grupos.push([b]);
    }
    for (const g of grupos) g.forEach((b, i) => { b.col = i; b.cols = g.length; });
    return lista;
  }, [comHora, eventos]);

  return (
    <View style={{ gap: 10 }}>
      {semHora.length > 0 && (
        <Cartao style={{ gap: 8 }}>
          <T style={{ fontWeight: '800', fontSize: 14, color: tema.textoSuave }}>SEM HORÁRIO</T>
          {semHora.map((t) => <ItemTarefa key={t.id} t={t} aoFeita={() => aoFeita(t)} aoTocar={() => aoTocarTarefa(t)} aoSegurar={() => aoTocarTarefa(t)} aoChamado={() => undefined} />)}
        </Cartao>
      )}
      <Cartao style={{ padding: 0, overflow: 'hidden' }}>
        <View style={{ height: altura + 16, marginTop: 8 }}>
          {Array.from({ length: (termino - inicio) / 60 + 1 }, (_, i) => inicio + i * 60).map((m) => (
            <Pressable key={m} onPress={() => m < termino && aoNovaNaHora(deMin(m))} style={{ position: 'absolute', top: (m - inicio) * PX_MIN, left: 0, right: 0, height: 60 * PX_MIN, flexDirection: 'row' }} accessibilityLabel={`Nova tarefa às ${deMin(m)}`}>
              <T style={{ width: 52, fontSize: 12, color: tema.textoSuave, textAlign: 'right', paddingRight: 8, marginTop: -8 }}>{deMin(m)}</T>
              <View style={{ flex: 1, borderTopWidth: 1, borderTopColor: tema.borda }} />
            </Pressable>
          ))}
          {almocoIni !== null && almocoFim !== null && almocoFim > almocoIni && (
            <View pointerEvents="none" style={{ position: 'absolute', left: 52, right: 0, top: (almocoIni - inicio) * PX_MIN, height: (almocoFim - almocoIni) * PX_MIN, backgroundColor: `${tema.alerta}18`, borderLeftWidth: 3, borderLeftColor: tema.alerta, justifyContent: 'center', paddingLeft: 10 }}>
              <T style={{ fontSize: 12, fontWeight: '800', color: tema.alerta }}>🍽️ Almoço</T>
            </View>
          )}
          {ehHoje && minAgora >= inicio && minAgora <= termino && (
            <View pointerEvents="none" style={{ position: 'absolute', left: 46, right: 0, top: (minAgora - inicio) * PX_MIN, height: 2, backgroundColor: tema.perigo }}>
              <View style={{ position: 'absolute', left: -5, top: -4, width: 10, height: 10, borderRadius: 5, backgroundColor: tema.perigo }} />
            </View>
          )}
          {blocos.map((b) => (
            <BlocoArrastavel key={b.chave} topo={(b.ini - inicio) * PX_MIN} altura={Math.max(34, b.dur * PX_MIN - 3)} col={b.col} cols={b.cols}
              limites={[0, (termino - inicio - b.dur) * PX_MIN]} cor={b.evento ? '#8b5cf6' : corCliente(b.tarefa?.clienteId ?? null)} feita={b.tarefa?.status === 'concluida'}
              titulo={b.evento ? `🎥 ${b.evento.titulo}` : b.tarefa?.titulo ?? ''} sub={[deMin(b.ini), b.evento?.cliente ?? b.tarefa?.cliente].filter(Boolean).join(' · ')}
              aoArrastar={aoArrastar} rotuloHora={(dy) => deMin(Math.round((b.ini + dy / PX_MIN) / PASSO) * PASSO)}
              aoSoltar={(dy) => { const novo = Math.round((b.ini + dy / PX_MIN) / PASSO) * PASSO; if (novo === b.ini) return; if (b.tarefa) aoMoverTarefa(b.tarefa, deMin(novo)); else if (b.evento) aoMoverEvento(b.evento, deMin(novo)); }}
              aoTocar={() => { if (b.tarefa) aoTocarTarefa(b.tarefa); else if (b.evento) aoTocarEvento(b.evento); }} />
          ))}
        </View>
      </Cartao>
      {!tarefas.length && !eventos.length && <Vazio>Dia livre. Toque num horário para criar uma tarefa.</Vazio>}
    </View>
  );
}

function BlocoArrastavel({ topo, altura, col, cols, limites, cor, feita, titulo, sub, aoArrastar, aoSoltar, aoTocar, rotuloHora }: {
  topo: number; altura: number; col: number; cols: number; limites: [number, number]; cor: string; feita: boolean; titulo: string; sub: string;
  aoArrastar: (rolar: boolean) => void; aoSoltar: (dy: number) => void; aoTocar: () => void; rotuloHora: (dy: number) => string;
}) {
  const [dy, setDy] = useState(0);
  const [ativo, setAtivo] = useState(false);
  const estado = useRef({ ativo: false, timer: null as ReturnType<typeof setTimeout> | null, moveu: false, dy: 0 });
  const props = useRef({ aoArrastar, aoSoltar, aoTocar, topo, limites });
  props.current = { aoArrastar, aoSoltar, aoTocar, topo, limites };

  const pan = useMemo(() => PanResponder.create({
    onStartShouldSetPanResponder: () => true,
    onPanResponderGrant: () => {
      const s = estado.current;
      s.moveu = false; s.dy = 0;
      s.timer = setTimeout(() => { s.ativo = true; setAtivo(true); props.current.aoArrastar(false); }, 320);
    },
    onPanResponderMove: (_, g) => {
      const s = estado.current;
      if (!s.ativo) { if (Math.abs(g.dy) > 8 || Math.abs(g.dx) > 8) { s.moveu = true; if (s.timer) clearTimeout(s.timer); } return; }
      const [min, max] = props.current.limites;
      const novo = Math.max(min - props.current.topo, Math.min(max - props.current.topo, g.dy));
      s.dy = novo; setDy(novo);
    },
    // Enquanto não "segurou", deixa a tela rolar normalmente.
    onPanResponderTerminationRequest: () => !estado.current.ativo,
    onPanResponderRelease: () => {
      const s = estado.current;
      if (s.timer) clearTimeout(s.timer);
      if (s.ativo) { props.current.aoSoltar(s.dy); props.current.aoArrastar(true); }
      else if (!s.moveu) props.current.aoTocar();
      s.ativo = false; setAtivo(false); setDy(0);
    },
    onPanResponderTerminate: () => {
      const s = estado.current;
      if (s.timer) clearTimeout(s.timer);
      if (s.ativo) props.current.aoArrastar(true);
      s.ativo = false; setAtivo(false); setDy(0);
    },
  }), []);

  const largura = `${(100 - 0) / cols}%` as const;
  return (
    <View {...pan.panHandlers} accessibilityRole="button" accessibilityLabel={`${titulo}, ${sub}. Segure e arraste para mudar o horário.`}
      style={{ position: 'absolute', top: topo + dy, height: altura, left: 56, right: 6, zIndex: ativo ? 10 : 1 }}>
      <View style={{ position: 'absolute', left: `${(col * 100) / cols}%`, width: largura, top: 0, bottom: 0, paddingRight: 3 }}>
        <View style={{ flex: 1, borderRadius: 10, backgroundColor: ativo ? cor : `${cor}26`, borderLeftWidth: 4, borderLeftColor: cor, paddingHorizontal: 8, paddingVertical: 4, opacity: feita ? 0.55 : 1, elevation: ativo ? 8 : 0, transform: [{ scale: ativo ? 1.03 : 1 }] }}>
          <T numberOfLines={altura > 50 ? 2 : 1} style={{ fontSize: 13, fontWeight: '800', color: ativo ? '#fff' : undefined, textDecorationLine: feita ? 'line-through' : 'none' }}>{titulo}</T>
          {altura > 40 && <T numberOfLines={1} style={{ fontSize: 11, opacity: 0.8, color: ativo ? '#fff' : undefined }}>{ativo ? `→ ${rotuloHora(dy)}` : sub}</T>}
        </View>
      </View>
    </View>
  );
}
