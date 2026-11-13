import { TIPOS_CASO_AGENTE, type PropostaAgente } from '@primehub/shared';
import { useCallback, useEffect, useState } from 'react';
import { Linking, Pressable, RefreshControl, ScrollView, View } from 'react-native';
import { api, URL_SERVIDOR } from './api';
import { Esqueleto } from './Esqueleto';
import { Acoes, Aviso, confirmar, Folha } from './formularios';
import { useTema } from './tema';
import { BotaoContorno, BotaoGradiente, Entrada, Escolha, Vazio } from './Telas';
import { Cartao, T } from './ui';

/** Mesa do agente no app: o agente prepara, a equipe decide (nada vai ao cliente sem aprovação). */

const COR_TIPO: Record<string, string> = { resposta: '#0ea5e9', duvida: '#64748b', correcao: '#ef4444', melhoria: '#10b981', proposta: '#f59e0b', desenvolvimento: '#8b5cf6', outro: '#94a3b8' };
const STATUS_APOS = [{ valor: 'aguardando_cliente', rotulo: 'Aguardando cliente' }, { valor: 'aguardando_equipe', rotulo: 'Aguardando nós' }, { valor: 'fechado', rotulo: 'Encerrar' }];

export function CartaoPropostaApp({ p, aoMudar, aoAbrirChamado }: { p: PropostaAgente; aoMudar: () => void; aoAbrirChamado?: (id: number) => void }) {
  const { tema } = useTema();
  const [texto, setTexto] = useState(p.resposta);
  const [status, setStatus] = useState(p.statusSugerido ?? 'aguardando_cliente');
  const [executar, setExecutar] = useState<{ tipo: string; instrucoes: string } | null>(null);
  const [aviso, setAviso] = useState<{ t: string; e: boolean }>({ t: '', e: false });
  const [ocupado, setOcupado] = useState(false);
  const emExecucao = p.status === 'aprovado_execucao';
  const cor = COR_TIPO[p.tipo] ?? '#94a3b8';
  async function agir(caminho: string, corpo: object) {
    setOcupado(true); setAviso({ t: '', e: false });
    try { const r = await api<{ mensagem?: string }>(`/api/agente/propostas/${p.id}/${caminho}`, { method: 'POST', body: JSON.stringify(corpo) }); setAviso({ t: r.mensagem ?? 'Pronto!', e: false }); setExecutar(null); setTimeout(aoMudar, 900); }
    catch (e) { setAviso({ t: e instanceof Error ? e.message : 'Não deu certo.', e: true }); }
    setOcupado(false);
  }
  async function baixar(id: number) {
    try { const { url } = await api<{ url: string }>(`/api/agente/arquivos/${id}/link`, { method: 'POST', body: '{}' }); await Linking.openURL(`${URL_SERVIDOR}${url}`); }
    catch (e) { setAviso({ t: e instanceof Error ? e.message : 'Não deu para baixar.', e: true }); }
  }
  return (
    <Cartao style={{ gap: 10, borderTopWidth: 4, borderTopColor: cor }}>
      <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 6, alignItems: 'center' }}>
        <View style={{ backgroundColor: cor, borderRadius: 99, paddingHorizontal: 10, paddingVertical: 3 }}><T style={{ color: '#fff', fontSize: 12, fontWeight: '800' }}>🤖 {TIPOS_CASO_AGENTE[p.tipo] ?? p.tipo}</T></View>
        <T style={{ fontSize: 12, color: tema.textoSuave }}>confiança {p.confianca} · {p.etapa === 'execucao' ? 'execução pronta' : 'triagem'}</T>
      </View>
      {aoAbrirChamado && <Pressable onPress={() => aoAbrirChamado(p.chamadoId)}><T style={{ fontWeight: '800' }}>#{p.chamadoId} · {p.chamadoTitulo}</T><T style={{ color: tema.primaria, fontSize: 13 }}>{p.cliente} ›</T></Pressable>}
      {emExecucao && <T style={{ color: '#8b5cf6', fontWeight: '700', fontSize: 14 }}>🔨 Execução aprovada: o agente está fazendo e devolve para você revisar.{p.instrucoes ? ` Instruções: "${p.instrucoes}"` : ''}</T>}
      {!!p.plano && <View style={{ gap: 4 }}><T style={{ fontWeight: '800', fontSize: 14 }}>📋 Plano sugerido</T><T style={{ fontSize: 14, lineHeight: 20 }}>{p.plano}</T></View>}
      {!!p.resumoInterno && <View style={{ gap: 4 }}><T style={{ fontWeight: '800', fontSize: 14 }}>🔎 O que foi analisado (só equipe)</T><T style={{ fontSize: 13, color: tema.textoSuave, lineHeight: 19 }}>{p.resumoInterno}</T></View>}
      {(p.fontes.length > 0 || p.arquivos.length > 0) && <Acoes itens={[
        ...p.fontes.map((f) => ({ rotulo: `↗ ${f.titulo.slice(0, 34)}`, aoTocar: () => void Linking.openURL(f.url) })),
        ...p.arquivos.map((a) => ({ rotulo: `📄 ${a.nome}`, aoTocar: () => void baixar(a.id) })),
      ]} />}
      {!!p.motivo && <T style={{ color: tema.alerta, fontSize: 13, fontWeight: '700' }}>⚠️ {p.motivo}</T>}
      {!emExecucao && (<>
        <Entrada rotulo={`Resposta para o cliente${p.atendente ? ` (em nome de ${p.atendente})` : ''}`} valor={texto} aoMudar={setTexto} linhas={8} />
        <Escolha rotulo="Depois de enviar" opcoes={STATUS_APOS} valor={status} aoMudar={setStatus} />
        <BotaoGradiente titulo={ocupado ? 'Enviando…' : p.arquivos.length ? `📨 Enviar com ${p.arquivos.length} arquivo(s)` : '📨 Enviar ao cliente'} desativado={ocupado || texto.trim().length < 10}
          aoTocar={() => void confirmar('Enviar ao cliente', 'Enviar esta resposta para o cliente agora?', 'Enviar', false).then((ok) => { if (ok) void agir('enviar', { mensagem: texto, status }); })} />
        <View style={{ flexDirection: 'row', gap: 8 }}>
          <View style={{ flex: 1 }}><BotaoContorno titulo="🔨 Aprovar execução" desativado={ocupado} aoTocar={() => setExecutar({ tipo: p.tipo === 'resposta' || p.tipo === 'duvida' ? 'correcao' : p.tipo, instrucoes: '' })} /></View>
          <View style={{ flex: 1 }}><BotaoContorno titulo="🗑 Descartar" desativado={ocupado} aoTocar={() => void confirmar('Descartar', 'Descartar esta proposta?', 'Descartar').then((ok) => { if (ok) void agir('descartar', {}); })} /></View>
        </View>
      </>)}
      <Aviso texto={aviso.t} erro={aviso.e} />
      <Folha aberta={!!executar} aoFechar={() => setExecutar(null)} titulo="Aprovar execução"
        rodape={executar && <BotaoGradiente titulo={ocupado ? 'Enviando…' : '✅ Aprovar e mandar fazer'} desativado={ocupado} aoTocar={() => void agir('executar', executar)} />}>
        {executar && (<>
          <T style={{ color: tema.textoSuave, fontSize: 14 }}>O agente faz o trabalho e devolve para você revisar. Nada é enviado ao cliente.</T>
          <Escolha rotulo="O que fazer" opcoes={['correcao', 'melhoria', 'proposta', 'desenvolvimento', 'resposta'].map((t) => ({ valor: t, rotulo: TIPOS_CASO_AGENTE[t] ?? t }))} valor={executar.tipo} aoMudar={(v) => setExecutar({ ...executar, tipo: v })} />
          <Entrada rotulo="Instruções para o agente (opcional)" valor={executar.instrucoes} aoMudar={(v) => setExecutar({ ...executar, instrucoes: v })} linhas={4} dica="Ex.: corrigir no PE, proposta com 2 opções…" />
        </>)}
      </Folha>
    </Cartao>
  );
}

export function TelaMesaAgente({ aoAbrirChamado }: { aoAbrirChamado: (id: number) => void }) {
  const { tema } = useTema();
  const [itens, setItens] = useState<PropostaAgente[] | null>(null);
  const [atualizando, setAtualizando] = useState(false);
  const carregar = useCallback(() => api<PropostaAgente[]>('/api/agente/mesa').then(setItens).catch(() => setItens([])).finally(() => setAtualizando(false)), []);
  useEffect(() => { void carregar(); }, [carregar]);
  const ordenados = [...(itens ?? [])].sort((a, b) => Number(a.status === 'aprovado_execucao') - Number(b.status === 'aprovado_execucao'));
  return (
    <ScrollView contentContainerStyle={{ padding: 16, gap: 12 }} refreshControl={<RefreshControl refreshing={atualizando} onRefresh={() => { setAtualizando(true); void carregar(); }} colors={[tema.primaria]} />}>
      <Cartao style={{ gap: 4 }}>
        <T style={{ fontWeight: '800' }}>🤖 O agente prepara, você decide.</T>
        <T style={{ color: tema.textoSuave, fontSize: 14 }}>🛡️ Nada vai para o cliente sem você tocar em Enviar.</T>
      </Cartao>
      {!itens ? [0, 1].map((i) => <Esqueleto key={i} altura={200} />) : !ordenados.length ? <Vazio>Nada esperando você agora.</Vazio>
        : ordenados.map((p) => <CartaoPropostaApp key={p.id} p={p} aoMudar={() => void carregar()} aoAbrirChamado={aoAbrirChamado} />)}
    </ScrollView>
  );
}
