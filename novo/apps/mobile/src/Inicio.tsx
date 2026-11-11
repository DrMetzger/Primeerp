import { corStatus, rotuloStatus, type ChamadoResumo, type Dashboard, type ListaChamados, type UsuarioLogado } from '@primehub/shared';
import { useCallback, useEffect, useState } from 'react';
import { FlatList, Pressable, RefreshControl, ScrollView, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { api } from './api';
import { Esqueleto, EsqueletoPainel } from './Esqueleto';
import { useTema } from './tema';
import { TelaWeb } from './TelaWeb';
import { Botao, Cartao, T } from './ui';

type Aba = 'painel' | 'chamados' | 'conta';

function CartaoChamado({ c, aoAbrir, equipe }: { c: ChamadoResumo; aoAbrir: () => void; equipe: boolean }) {
  const { tema } = useTema();
  const cor = tema[corStatus[c.status]];
  return (
    <Pressable onPress={aoAbrir} accessibilityRole="button" accessibilityLabel={`Chamado ${c.id}, ${c.titulo}, ${rotuloStatus[c.status]}`}
      style={({ pressed }) => ({ opacity: pressed ? 0.85 : 1, marginBottom: 10 })}>
      <Cartao style={{ gap: 6 }}>
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
          <T style={{ color: tema.textoSuave, fontWeight: '700', fontSize: 14 }}>#{c.id}</T>
          <View style={{ flexDirection: 'row', alignItems: 'center', gap: 6, borderRadius: 99, paddingHorizontal: 10, paddingVertical: 4, backgroundColor: tema.superficieAlta }}>
            <View style={{ width: 8, height: 8, borderRadius: 4, backgroundColor: cor }} />
            <T style={{ color: cor, fontWeight: '700', fontSize: 13 }}>{rotuloStatus[c.status]}</T>
          </View>
          {(c.prioridade === 'urgente' || c.prioridade === 'alta') && <T style={{ color: tema.perigo, fontWeight: '700', fontSize: 13 }}>🔥 {c.prioridade === 'urgente' ? 'Urgente' : 'Alta'}</T>}
        </View>
        <T style={{ fontWeight: '700', fontSize: 17 }} numberOfLines={3}>{c.titulo}</T>
        <T style={{ color: tema.textoSuave, fontSize: 14 }} numberOfLines={1}>
          {equipe ? `${c.cliente ?? 'Sem cliente'}${c.responsavel ? ` · ${c.responsavel}` : ''}` : `Aberto em ${c.criadoEm.slice(8, 10)}/${c.criadoEm.slice(5, 7)}/${c.criadoEm.slice(0, 4)}`}
        </T>
        {equipe && c.horasEsperando !== null && <T style={{ color: tema.textoSuave, fontSize: 14 }}>⏱ esperando há {c.horasEsperando < 24 ? `${c.horasEsperando}h` : `${Math.floor(c.horasEsperando / 24)}d`}</T>}
      </Cartao>
    </Pressable>
  );
}

function Painel({ aoAbrirChamado }: { aoAbrirChamado: (c: ChamadoResumo) => void }) {
  const { tema } = useTema();
  const [dados, setDados] = useState<Dashboard | null>(null);
  const [atualizando, setAtualizando] = useState(false);
  const carregar = useCallback(() => api<Dashboard>('/api/dashboard').then(setDados).finally(() => setAtualizando(false)), []);
  useEffect(() => { void carregar(); }, [carregar]);

  if (!dados) return <View style={{ padding: 16 }}><EsqueletoPainel /></View>;
  const k = dados.kpis;
  const cartoes = [k.pendentesNossos, k.urgentesPendentes, k.abertos30d, k.fechados30d, k.primeiraRespostaHoras, k.resolucaoHoras];
  const maximo = Math.max(1, ...dados.semanas.flatMap((s) => [s.abertos, s.fechados]));

  return (
    <ScrollView contentContainerStyle={{ padding: 16, gap: 14 }} refreshControl={<RefreshControl refreshing={atualizando} onRefresh={() => { setAtualizando(true); void carregar(); }} />}>
      <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 12 }}>
        {cartoes.map((kpi, i) => (
          <Cartao key={kpi.rotulo} style={{ flexBasis: '47%', flexGrow: 1, backgroundColor: i === 0 ? tema.primaria : tema.superficie }}>
            <T style={{ fontSize: 14, color: i === 0 ? tema.primariaTexto : tema.textoSuave }}>{kpi.rotulo}</T>
            <T style={{ fontSize: 30, fontWeight: '800', marginTop: 4, color: i === 0 ? tema.primariaTexto : tema.texto }}>{kpi.valor}{kpi.sufixo ?? ''}</T>
          </Cartao>
        ))}
      </View>
      <Cartao>
        <T style={{ fontWeight: '700', marginBottom: 4 }}>Abertos x fechados por semana</T>
        <View style={{ flexDirection: 'row', gap: 14, marginBottom: 10 }}>
          <T style={{ fontSize: 14, color: tema.textoSuave }}><T style={{ color: tema.grafico1 }}>■</T> Abertos</T>
          <T style={{ fontSize: 14, color: tema.textoSuave }}><T style={{ color: tema.grafico2 }}>■</T> Fechados</T>
        </View>
        <View accessible accessibilityLabel={`Últimas 12 semanas: ${dados.semanas.map((s) => `${s.abertos} abertos e ${s.fechados} fechados`).join('; ')}`}
          style={{ height: 140, flexDirection: 'row', alignItems: 'flex-end', gap: 4, borderBottomWidth: 1, borderColor: tema.borda }}>
          {dados.semanas.map((s) => (
            <View key={s.semana} style={{ flex: 1, flexDirection: 'row', alignItems: 'flex-end', gap: 2, height: '100%' }}>
              <View style={{ flex: 1, height: `${(s.abertos / maximo) * 100}%`, backgroundColor: tema.grafico1, borderTopLeftRadius: 4, borderTopRightRadius: 4 }} />
              <View style={{ flex: 1, height: `${(s.fechados / maximo) * 100}%`, backgroundColor: tema.grafico2, borderTopLeftRadius: 4, borderTopRightRadius: 4 }} />
            </View>
          ))}
        </View>
      </Cartao>
      <T style={{ fontWeight: '700', fontSize: 18 }}>Esperando resposta nossa</T>
      {dados.maisAntigos.length === 0 ? <T style={{ color: tema.textoSuave }}>Nada pendente. 🎉</T>
        : dados.maisAntigos.map((c) => <CartaoChamado key={c.id} c={c} equipe aoAbrir={() => aoAbrirChamado(c)} />)}
    </ScrollView>
  );
}

function Chamados({ equipe, aoAbrirChamado, aoNovo }: { equipe: boolean; aoAbrirChamado: (c: ChamadoResumo) => void; aoNovo: () => void }) {
  const { tema } = useTema();
  const [status, setStatus] = useState('pendentes');
  const [dados, setDados] = useState<ListaChamados | null>(null);
  const [atualizando, setAtualizando] = useState(false);
  const carregar = useCallback(() => api<ListaChamados>(`/api/chamados?status=${status}`).then(setDados).finally(() => setAtualizando(false)), [status]);
  useEffect(() => { setDados(null); void carregar(); }, [carregar]);

  const filtros = [['pendentes', 'Pendentes'], ['aguardando_equipe', 'Aguardando nós'], ['aguardando_cliente', 'Aguardando cliente'], ['fechado', 'Fechados']] as const;
  return (
    <View style={{ flex: 1 }}>
      <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 8, padding: 16, paddingBottom: 8 }} style={{ flexGrow: 0 }}>
        {filtros.map(([v, r]) => (
          <Pressable key={v} onPress={() => setStatus(v)} accessibilityRole="tab" accessibilityState={{ selected: status === v }}
            style={{ minHeight: 44, borderRadius: 99, paddingHorizontal: 16, justifyContent: 'center', backgroundColor: status === v ? tema.primaria : tema.superficie, borderWidth: 1, borderColor: tema.borda }}>
            <T style={{ fontWeight: '700', fontSize: 14, color: status === v ? tema.primariaTexto : tema.textoSuave }}>{r}</T>
          </Pressable>
        ))}
      </ScrollView>
      {!equipe && <View style={{ paddingHorizontal: 16, paddingBottom: 8 }}><Botao titulo="+ Abrir chamado" primario onPress={aoNovo} /></View>}
      {!dados ? (
        <View style={{ padding: 16, gap: 10 }}>{[0, 1, 2, 3, 4].map((i) => <Esqueleto key={i} altura={96} />)}</View>
      ) : (
        <FlatList data={dados.itens} keyExtractor={(c) => String(c.id)} contentContainerStyle={{ padding: 16, paddingTop: 4 }}
          refreshControl={<RefreshControl refreshing={atualizando} onRefresh={() => { setAtualizando(true); void carregar(); }} />}
          ListEmptyComponent={<T style={{ color: tema.textoSuave, textAlign: 'center', marginTop: 40 }}>Nenhum chamado por aqui.</T>}
          renderItem={({ item }) => <CartaoChamado c={item} equipe={equipe} aoAbrir={() => aoAbrirChamado(item)} />} />
      )}
    </View>
  );
}

export function Inicio({ usuario, aoSair }: { usuario: UsuarioLogado; aoSair: () => void }) {
  const { tema, alternar, nome } = useTema();
  const insets = useSafeAreaInsets();
  const equipe = usuario.tipo === 'equipe';
  const [aba, setAba] = useState<Aba>(equipe ? 'painel' : 'chamados');
  const [web, setWeb] = useState<{ url: string; titulo: string } | null>(null);

  async function abrirClassico(destino: string, titulo: string) {
    const { url } = await api<{ url: string }>('/api/classico', { method: 'POST', body: JSON.stringify({ destino }) });
    setWeb({ url, titulo });
  }
  const abrirChamado = (c: ChamadoResumo) => void abrirClassico(equipe ? `/cliente.php?id=${c.clienteId ?? 0}#chamados` : `/portal/chamado.php?id=${c.id}`, `Chamado #${c.id}`);

  if (web) return <TelaWeb url={web.url} titulo={web.titulo} aoFechar={() => setWeb(null)} />;

  const abas: [Aba, string, string][] = equipe ? [['painel', '📊', 'Painel'], ['chamados', '🎧', 'Chamados'], ['conta', '👤', 'Conta']] : [['chamados', '🎧', 'Chamados'], ['conta', '👤', 'Conta']];

  return (
    <View style={{ flex: 1, backgroundColor: tema.fundo }}>
      <View style={{ paddingTop: insets.top + 8, paddingHorizontal: 16, paddingBottom: 10, borderBottomWidth: 1, borderColor: tema.borda, backgroundColor: tema.superficie }}>
        <T style={{ fontSize: 22, fontWeight: '800' }} accessibilityRole="header">{aba === 'painel' ? 'Painel' : aba === 'chamados' ? (equipe ? 'Chamados' : 'Meus chamados') : 'Minha conta'}</T>
        <T style={{ color: tema.textoSuave, fontSize: 14 }} numberOfLines={1}>{usuario.nome}{usuario.empresa ? ` · ${usuario.empresa}` : ''}</T>
      </View>
      <View style={{ flex: 1 }}>
        {aba === 'painel' && <Painel aoAbrirChamado={abrirChamado} />}
        {aba === 'chamados' && <Chamados equipe={equipe} aoAbrirChamado={abrirChamado} aoNovo={() => void abrirClassico('/portal/chamados.php', 'Abrir chamado')} />}
        {aba === 'conta' && (
          <ScrollView contentContainerStyle={{ padding: 16, gap: 12 }}>
            <Botao titulo={nome === 'escuro' ? '☀️  Usar tema claro' : '🌙  Usar tema escuro'} onPress={alternar} />
            <Botao titulo="Meu perfil" onPress={() => void abrirClassico(equipe ? '/perfil.php' : '/portal/perfil.php', 'Meu perfil')} />
            {equipe && <Botao titulo="Clientes" onPress={() => void abrirClassico('/clientes.php', 'Clientes')} />}
            {equipe && <Botao titulo="Agenda" onPress={() => void abrirClassico('/agenda.php', 'Agenda')} />}
            <Botao titulo="Sair" onPress={aoSair} style={{ backgroundColor: 'transparent', borderWidth: 1, borderColor: tema.perigo }} />
          </ScrollView>
        )}
      </View>
      <View accessibilityRole="tablist" style={{ flexDirection: 'row', borderTopWidth: 1, borderColor: tema.borda, backgroundColor: tema.superficie, paddingBottom: insets.bottom }}>
        {abas.map(([id, icone, rotulo]) => (
          <Pressable key={id} onPress={() => setAba(id)} accessibilityRole="tab" accessibilityState={{ selected: aba === id }} accessibilityLabel={rotulo}
            style={{ flex: 1, minHeight: 60, alignItems: 'center', justifyContent: 'center', gap: 2 }}>
            <T style={{ fontSize: 22, opacity: aba === id ? 1 : 0.55 }}>{icone}</T>
            <T style={{ fontSize: 13, fontWeight: '700', color: aba === id ? tema.primaria : tema.textoSuave }}>{rotulo}</T>
          </Pressable>
        ))}
      </View>
    </View>
  );
}
