import type { PainelVm, ServicoVm, TarefaVm } from '@primehub/shared';
import { useCallback, useEffect, useState } from 'react';
import { Linking, Pressable, RefreshControl, ScrollView, View } from 'react-native';
import { api } from './api';
import { Esqueleto } from './Esqueleto';
import { Acoes, Aviso, confirmar, Folha } from './formularios';
import { useTema } from './tema';
import { BotaoGradiente, Vazio } from './Telas';
import { Cartao, T } from './ui';

/** Protheus 2510 na VM: status dos serviços, iniciar/parar/reiniciar e log (só admin). */

const ICONE: Record<string, string> = { appserver: '🖥️', dbaccess: '🔌', license: '🔑', postgresql: '🐘', syncthing: '🔄' };
const COR: Record<string, string> = { appserver: '#0ea5e9', dbaccess: '#8b5cf6', license: '#f59e0b', postgresql: '#336791', syncthing: '#10b981' };

export function TelaProtheus() {
  const { tema } = useTema();
  const [d, setD] = useState<PainelVm | null>(null);
  const [erro, setErro] = useState('');
  const [ocupado, setOcupado] = useState('');
  const [aviso, setAviso] = useState<{ t: string; e: boolean }>({ t: '', e: false });
  const [log, setLog] = useState<{ nome: string; linhas: string[] | null } | null>(null);
  const [atualizando, setAtualizando] = useState(false);
  const [tarefas, setTarefas] = useState<TarefaVm[]>([]);
  const rodando = tarefas.some((t) => t.estado === 'rodando');
  const carregarTarefas = useCallback(() => api<{ tarefas: TarefaVm[] }>('/api/vm/tarefas').then((r) => setTarefas(r.tarefas)).catch(() => undefined), []);
  useEffect(() => { void carregarTarefas(); }, [carregarTarefas]);
  const carregar = useCallback(() => api<PainelVm>('/api/vm/servicos').then((x) => { setD(x); setErro(''); }).catch((e: unknown) => setErro(e instanceof Error ? e.message : 'Não consegui falar com a VM.')).finally(() => setAtualizando(false)), []);
  useEffect(() => { void carregar(); const t = setInterval(() => void carregar(), 15_000); return () => clearInterval(t); }, [carregar]);
  useEffect(() => {
    if (!rodando) return;
    const t = setInterval(() => { void carregarTarefas(); void carregar(); }, 1500);
    return () => clearInterval(t);
  }, [rodando, carregarTarefas, carregar]);

  async function agir(s: { id: string; nome: string; critico: boolean }, acao: 'iniciar' | 'parar' | 'reiniciar') {
    const todos = s.id === 'todos';
    if (acao !== 'iniciar' && !(await confirmar(acao === 'parar' ? 'Parar' : 'Reiniciar', `${acao === 'parar' ? 'Parar' : 'Reiniciar'} ${todos ? 'TODOS os serviços do Protheus' : `o ${s.nome}`}?${s.critico ? ' O Protheus fica fora do ar enquanto isso.' : ''}`, acao === 'parar' ? 'Parar' : 'Reiniciar'))) return;
    setOcupado(`${s.id}:${acao}`); setAviso({ t: '', e: false });
    try { const t = await api<TarefaVm>(`/api/vm/servicos/${s.id}/${acao}`, { method: 'POST', body: '{}' }); setTarefas((l) => [t, ...l.filter((x) => x.id !== t.id)]); }
    catch (e) { setAviso({ t: e instanceof Error ? e.message : 'Não deu certo.', e: true }); }
    setOcupado('');
  }
  async function verLog(s: ServicoVm) {
    setLog({ nome: s.nome, linhas: null });
    try { setLog({ nome: s.nome, linhas: (await api<{ linhas: string[] }>(`/api/vm/servicos/${s.id}/log`)).linhas }); }
    catch (e) { setLog({ nome: s.nome, linhas: [e instanceof Error ? e.message : 'Não deu para ler o log.'] }); }
  }

  const v = d?.versoes ?? {};
  const noAr = d?.servicos.filter((s) => s.critico).every((s) => s.estado === 'active');
  return (
    <>
      <ScrollView contentContainerStyle={{ padding: 16, gap: 12 }} refreshControl={<RefreshControl refreshing={atualizando} onRefresh={() => { setAtualizando(true); void carregar(); }} colors={[tema.primaria]} />}>
        {!!erro && <Aviso texto={erro} erro />}
        {!d ? (erro ? null : <><Esqueleto altura={150} /><Esqueleto altura={220} /></>) : (<>
          <Cartao style={{ gap: 10 }}>
            <View style={{ flexDirection: 'row', alignItems: 'center', gap: 12 }}>
              <View style={{ width: 52, height: 52, borderRadius: 16, backgroundColor: '#0ea5e9', alignItems: 'center', justifyContent: 'center' }}><T style={{ fontSize: 26 }}>🖥️</T></View>
              <View style={{ flex: 1 }}>
                <T style={{ fontSize: 20, fontWeight: '800' }}>Protheus {v.protheus ?? '12.1.2510'}</T>
                <T style={{ fontSize: 13, color: tema.textoSuave }}>Ambiente {v.ambiente ?? 'PRIMEERP'}</T>
              </View>
              <View style={{ borderRadius: 99, paddingHorizontal: 10, paddingVertical: 4, backgroundColor: noAr ? `${tema.sucesso}26` : `${tema.perigo}26` }}>
                <T style={{ fontSize: 12, fontWeight: '800', color: noAr ? tema.sucesso : tema.perigo }}>{noAr ? '● Tudo no ar' : '● Atenção'}</T>
              </View>
            </View>
            {([['RPO', `${v.protheus ?? '—'}${v.rpoData ? ` · ${v.rpoData}` : ''}`], ['AppServer', `${v.appserver ?? '—'}${v.appserverBuild ? ` (${v.appserverBuild})` : ''}`], ['Webapp', v.webapp || '—'], ['DBAccess', v.dbaccess || '—'], ['License', v.licenseServer || '—'], ['PostgreSQL', v.postgresql || '—']] as const).map(([r, x]) => (
              <View key={r} style={{ flexDirection: 'row', justifyContent: 'space-between' }}><T style={{ color: tema.textoSuave, fontSize: 13 }}>{r}</T><T style={{ fontSize: 13, fontFamily: 'monospace' }}>{x}</T></View>
            ))}
            <BotaoGradiente titulo="↗ Abrir o Protheus" aoTocar={() => void Linking.openURL(v.webappUrl ?? 'https://protheus.primeerp.com.br')} />
            <Acoes itens={[
              ...(!noAr ? [{ rotulo: '⏻ Iniciar todos', aoTocar: () => { if (!rodando) void agir({ id: 'todos', nome: 'todos', critico: true }, 'iniciar'); } }] : []),
              { rotulo: '↻ Reiniciar todos', aoTocar: () => { if (!rodando) void agir({ id: 'todos', nome: 'todos', critico: true }, 'reiniciar'); } },
              { rotulo: '■ Parar todos', perigo: true, aoTocar: () => { if (!rodando) void agir({ id: 'todos', nome: 'todos', critico: true }, 'parar'); } },
            ]} />
          </Cartao>
          <Cartao style={{ gap: 4 }}>
            <T style={{ fontSize: 13 }}>🧠 Memória: {(d.vm.memoriaUsadaMB / 1024).toFixed(1)} de {(d.vm.memoriaTotalMB / 1024).toFixed(1)} GB</T>
            <T style={{ fontSize: 13 }}>💽 Disco: {d.vm.discoUsadoGB} de {d.vm.discoTotalGB} GB</T>
            <T style={{ fontSize: 13 }}>⚙️ Carga: {d.vm.carga[0]} ({d.vm.nucleos} núcleos)</T>
          </Cartao>
          <Aviso texto={aviso.t} erro={aviso.e} />
          {tarefas.length > 0 && (
            <Cartao style={{ gap: 8 }}>
              <T style={{ fontWeight: '800' }}>🛠️ Pipeline da VM</T>
              {tarefas.slice(0, 5).map((t, i) => (
                <View key={t.id} style={{ gap: 4, paddingTop: i ? 8 : 0, borderTopWidth: i ? 1 : 0, borderTopColor: tema.borda }}>
                  <T style={{ fontWeight: '700', fontSize: 14 }}>{t.estado === 'rodando' ? '⏳' : t.estado === 'ok' ? '✅' : '❌'} {t.titulo} <T style={{ fontSize: 12, color: tema.textoSuave }}>· {t.duracao.toFixed(0)} s · por {t.quem}</T></T>
                  {(i === 0 || t.estado !== 'ok') && t.etapas.map((e, n) => (
                    <T key={n} style={{ fontSize: 13, color: e.estado === 'erro' ? tema.perigo : e.estado === 'pendente' || e.estado === 'pulada' ? tema.textoSuave : tema.texto }}>
                      {{ pendente: '○', rodando: '⟳', ok: '✓', erro: '✗', pulada: '–' }[e.estado]} {n + 1}. {e.nome}{e.duracao !== null ? ` · ${e.duracao.toFixed(1)} s` : ''}{e.detalhe ? `\n     ${e.detalhe}` : ''}
                    </T>
                  ))}
                </View>
              ))}
            </Cartao>
          )}
          {d.servicos.map((s) => {
            const ativo = s.estado === 'active';
            return (
              <Cartao key={s.id} style={{ gap: 8, borderTopWidth: 4, borderTopColor: COR[s.id] ?? '#0ea5e9' }}>
                <View style={{ flexDirection: 'row', alignItems: 'center', gap: 10 }}>
                  <T style={{ fontSize: 24 }}>{ICONE[s.id] ?? '⚙️'}</T>
                  <View style={{ flex: 1 }}><T style={{ fontWeight: '800' }}>{s.nome}</T><T style={{ fontSize: 12, color: tema.textoSuave }}>{s.descricao}</T></View>
                  <T style={{ fontSize: 12, fontWeight: '800', color: ativo ? tema.sucesso : s.estado === 'failed' ? tema.perigo : tema.textoSuave }}>{ativo ? '● No ar' : s.estado === 'failed' ? '● Com erro' : '○ Parado'}</T>
                </View>
                <T style={{ fontSize: 12, color: tema.textoSuave }}>{s.memoriaMB !== null ? `${s.memoriaMB} MB` : '—'} · portas {s.portas.map((p) => `${p.porta}${p.aberta ? '✓' : '✗'}`).join(' ')}</T>
                <Acoes itens={[
                  ...(!ativo ? [{ rotulo: ocupado === `${s.id}:iniciar` ? 'Iniciando…' : '▶ Iniciar', aoTocar: () => void agir(s, 'iniciar') }] : []),
                  ...(ativo ? [{ rotulo: ocupado === `${s.id}:reiniciar` ? 'Reiniciando…' : '↻ Reiniciar', aoTocar: () => void agir(s, 'reiniciar') },
                    { rotulo: ocupado === `${s.id}:parar` ? 'Parando…' : '■ Parar', perigo: true, aoTocar: () => void agir(s, 'parar') }] : []),
                  { rotulo: '📜 Log', aoTocar: () => void verLog(s) },
                ]} />
              </Cartao>
            );
          })}
          <T style={{ textAlign: 'center', fontSize: 12, color: tema.textoSuave }}>Atualiza sozinho a cada 15 segundos</T>
        </>)}
        {!d && !erro && <Vazio>Conectando com a VM…</Vazio>}
      </ScrollView>
      <Folha aberta={!!log} aoFechar={() => setLog(null)} titulo={log ? `Log: ${log.nome}` : ''}>
        {log && (!log.linhas ? <Esqueleto altura={200} /> : (
          <Pressable style={{ backgroundColor: '#0b1020', borderRadius: 14, padding: 12 }}>
            <T selectable style={{ fontFamily: 'monospace', fontSize: 11, color: '#a7f3d0', lineHeight: 16 }}>{log.linhas.join('\n') || '(vazio)'}</T>
          </Pressable>
        ))}
      </Folha>
    </>
  );
}
