import { corStatus, rotuloStatus, type ChamadoResumo, type Dashboard, type ItemRanking, type Kpi, type ListaChamados, type StatusChamado, type UsuarioLogado, type VersoesProtheus } from '@primehub/shared';
import { LinearGradient } from 'expo-linear-gradient';
import { useCallback, useEffect, useState } from 'react';
import { BackHandler, FlatList, Image, Pressable, RefreshControl, ScrollView, TextInput, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { aoFicarForaDoHorario, api } from './api';
import { AssistenteApp } from './AssistenteApp';
import { Esqueleto, EsqueletoPainel } from './Esqueleto';
import { FundoCircuito } from './FundoCircuito';
import { useTema } from './tema';
import { TelaAgenda } from './TelaAgenda';
import { TelaMesaAgente } from './MesaAgente';
import { TelaProtheus } from './TelaProtheus';
import { FotoPessoa, TelaCalendario, TelaConfiguracoes, TelaEquipeCliente, TelaFontes, TelaFontesCliente, TelaPerfil, TelaUsuarios, TelaVideoaulas } from './Telas2';
import { TelaSetor } from './TelaSetor';
import { LogoEmpresa, TelaChamado, TelaCliente, TelaClientes, TelaNovoChamado, TelaRelatorios } from './Telas';
import { Cartao, T } from './ui';

type Aba = 'painel' | 'chamados' | 'assistente' | 'menu';
type Tela = { tipo: 'chamado'; id: number } | { tipo: 'cliente'; id: number } | { tipo: 'setor'; clienteId: number; id: number } | { tipo: 'novoChamado'; clienteId?: number }
  | { tipo: 'clientes' | 'agente' | 'protheus' | 'relatorios' | 'agenda' | 'fontes' | 'usuarios' | 'configuracoes' | 'perfil' | 'calendario' | 'videoaulas' | 'fontesCliente' | 'equipeCliente' };
const TITULOS_TELA: Record<string, string> = {
  clientes: 'Clientes', agente: 'Mesa do agente', protheus: 'Protheus 2510', relatorios: 'Relatórios', agenda: 'Agenda', fontes: 'Fontes ADVPL', usuarios: 'Usuários', configuracoes: 'Configurações',
  perfil: 'Meu perfil', calendario: 'Calendário', videoaulas: 'Videoaulas', fontesCliente: 'Fontes ADVPL', equipeCliente: 'Minha equipe',
};
const ADMIN = ['admin', 'diretor', 'ceo'];

// ---------------------------------------------------------------- Painel

function CartaoKpi({ kpi, destaque }: { kpi: Kpi; destaque?: boolean }) {
  const { tema } = useTema();
  const v = kpi.variacao;
  const bom = v === null || v === 0 ? null : (v > 0) === kpi.subirEhBom;
  const conteudo = (
    <>
      <T style={{ fontSize: 13, color: destaque ? 'rgba(255,255,255,.88)' : tema.textoSuave }} numberOfLines={2}>{kpi.rotulo}</T>
      <T style={{ fontSize: 30, fontWeight: '800', marginTop: 6, color: destaque ? '#FFFFFF' : tema.texto }}>
        {kpi.valor.toLocaleString('pt-BR')}{kpi.sufixo ? <T style={{ fontSize: 17, fontWeight: '700', color: destaque ? '#FFFFFF' : tema.textoSuave }}>{kpi.sufixo}</T> : null}
      </T>
      {v !== null && (
        <T style={{ fontSize: 12, fontWeight: '700', marginTop: 2, color: destaque ? '#FFFFFF' : bom === null ? tema.textoSuave : bom ? tema.sucesso : tema.perigo }}>
          {v > 0 ? '↗ +' : v < 0 ? '↘ ' : ''}{v}% vs. 30 dias antes
        </T>
      )}
    </>
  );
  const estilo = { flexBasis: '47%' as const, flexGrow: 1, borderRadius: 20, padding: 14, minHeight: 112 };
  return destaque ? (
    <LinearGradient colors={['#1E3FD8', '#0FB5C9']} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={estilo}>{conteudo}</LinearGradient>
  ) : (
    <View style={[estilo, { backgroundColor: tema.superficie, borderWidth: 1, borderColor: tema.borda }]}>{conteudo}</View>
  );
}

function Grafico({ dados }: { dados: Dashboard }) {
  const { tema } = useTema();
  const [foco, setFoco] = useState<number | null>(null);
  const maximo = Math.max(1, ...dados.semanas.flatMap((s) => [s.abertos, s.fechados]));
  const atual = foco === null ? null : dados.semanas[foco];
  const rotulo = (iso: string) => `${iso.slice(8, 10)}/${iso.slice(5, 7)}`;
  return (
    <Cartao>
      <T style={{ fontWeight: '700', fontSize: 17 }}>Abertos x fechados por semana</T>
      <View style={{ flexDirection: 'row', gap: 16, marginVertical: 10 }}>
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 6 }}><View style={{ width: 12, height: 12, borderRadius: 3, backgroundColor: tema.grafico1 }} /><T style={{ fontSize: 14 }}>Abertos</T></View>
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 6 }}><View style={{ width: 12, height: 12, borderRadius: 3, backgroundColor: tema.grafico2 }} /><T style={{ fontSize: 14 }}>Fechados</T></View>
      </View>
      <T style={{ fontSize: 14, color: tema.textoSuave, minHeight: 20 }}>
        {atual ? `Semana de ${rotulo(atual.semana)}: ${atual.abertos} abertos · ${atual.fechados} fechados` : 'Toque numa semana para ver os números'}
      </T>
      <View style={{ height: 160, flexDirection: 'row', alignItems: 'flex-end', gap: 3, borderBottomWidth: 1, borderColor: tema.borda, marginTop: 6 }}>
        {dados.semanas.map((s, i) => (
          <Pressable key={s.semana} onPress={() => setFoco(i)} accessibilityLabel={`Semana de ${rotulo(s.semana)}: ${s.abertos} abertos, ${s.fechados} fechados`}
            style={{ flex: 1, height: '100%', flexDirection: 'row', alignItems: 'flex-end', gap: 2, borderRadius: 6, backgroundColor: foco === i ? tema.superficieAlta : 'transparent' }}>
            <View style={{ flex: 1, height: `${(s.abertos / maximo) * 100}%`, minHeight: s.abertos ? 3 : 0, backgroundColor: tema.grafico1, borderTopLeftRadius: 4, borderTopRightRadius: 4 }} />
            <View style={{ flex: 1, height: `${(s.fechados / maximo) * 100}%`, minHeight: s.fechados ? 3 : 0, backgroundColor: tema.grafico2, borderTopLeftRadius: 4, borderTopRightRadius: 4 }} />
          </Pressable>
        ))}
      </View>
      <View style={{ flexDirection: 'row', justifyContent: 'space-between', marginTop: 6 }}>
        <T style={{ fontSize: 12, color: tema.textoSuave }}>{rotulo(dados.semanas[0]?.semana ?? '')}</T>
        <T style={{ fontSize: 12, color: tema.textoSuave }}>{rotulo(dados.semanas[dados.semanas.length - 1]?.semana ?? '')}</T>
      </View>
    </Cartao>
  );
}

function Situacao({ porStatus }: { porStatus: Record<StatusChamado, number> }) {
  const { tema } = useTema();
  const ordem: StatusChamado[] = ['aguardando_equipe', 'aberto', 'aguardando_cliente', 'fechado'];
  const total = Math.max(1, ordem.reduce((t, s) => t + porStatus[s], 0));
  return (
    <Cartao style={{ gap: 10 }}>
      <T style={{ fontWeight: '700', fontSize: 17 }}>Situação agora</T>
      <View style={{ flexDirection: 'row', height: 14, borderRadius: 99, overflow: 'hidden', gap: 2 }}>
        {ordem.filter((s) => porStatus[s] > 0).map((s) => <View key={s} style={{ flex: porStatus[s] / total, backgroundColor: tema[corStatus[s]] }} />)}
      </View>
      {ordem.map((s) => (
        <View key={s} style={{ flexDirection: 'row', alignItems: 'center', gap: 10, backgroundColor: tema.superficieAlta, borderRadius: 12, paddingHorizontal: 12, minHeight: 44 }}>
          <View style={{ width: 10, height: 10, borderRadius: 5, backgroundColor: tema[corStatus[s]] }} />
          <T style={{ flex: 1, color: tema.textoSuave, fontSize: 15 }}>{rotuloStatus[s]}{s === 'fechado' ? ' (30d)' : ''}</T>
          <T style={{ fontWeight: '800', fontSize: 17 }}>{porStatus[s]}</T>
        </View>
      ))}
    </Cartao>
  );
}

function Ranking({ titulo, itens, vazio }: { titulo: string; itens: ItemRanking[]; vazio: string }) {
  const { tema } = useTema();
  const maximo = Math.max(1, ...itens.map((i) => i.valor));
  return (
    <Cartao style={{ gap: 10 }}>
      <T style={{ fontWeight: '700', fontSize: 17 }}>{titulo}</T>
      {!itens.length ? <T style={{ color: tema.textoSuave }}>{vazio}</T> : itens.map((i) => (
        <View key={`${i.id}-${i.nome}`} style={{ gap: 4 }}>
          <View style={{ flexDirection: 'row', justifyContent: 'space-between', gap: 8 }}>
            <T style={{ flex: 1, fontSize: 15, fontWeight: '600' }} numberOfLines={1}>{i.nome}</T>
            <T style={{ fontWeight: '800', fontSize: 15 }}>{i.valor}</T>
          </View>
          <View style={{ height: 8, borderRadius: 99, backgroundColor: tema.superficieAlta }}>
            <View style={{ height: 8, borderRadius: 99, width: `${(i.valor / maximo) * 100}%`, backgroundColor: tema.grafico1 }} />
          </View>
        </View>
      ))}
    </Cartao>
  );
}

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
        <View style={{ flexDirection: 'row', gap: 12, alignItems: 'center' }}>
          {equipe && <LogoEmpresa nome={c.cliente} logo={c.clienteLogo} tamanho={42} />}
          <View style={{ flex: 1, gap: 2 }}>
            <T style={{ fontWeight: '700', fontSize: 17 }} numberOfLines={3}>{c.titulo}</T>
            {equipe && <T style={{ color: tema.textoSuave, fontSize: 13, fontWeight: '700' }} numberOfLines={1}>{c.cliente ?? 'Sem cliente'}</T>}
            {c.ultimo ? (
              <View style={{ flexDirection: 'row', alignItems: 'center', gap: 6 }}>
                <FotoPessoa caminho={c.ultimo.foto} nome={c.ultimo.nome} tamanho={22} />
                <T style={{ color: tema.textoSuave, fontSize: 14, flex: 1 }} numberOfLines={1}><T style={{ fontWeight: '800', fontSize: 14 }}>{c.ultimo.nome}</T> respondeu</T>
              </View>
            ) : <T style={{ color: tema.textoSuave, fontSize: 14 }}>Aberto em {c.criadoEm.slice(8, 10)}/{c.criadoEm.slice(5, 7)}, sem respostas ainda</T>}
          </View>
        </View>
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
  useEffect(() => { void carregar(); const id = setInterval(() => void carregar(), 60_000); return () => clearInterval(id); }, [carregar]);

  if (!dados) return <View style={{ padding: 16 }}><EsqueletoPainel /></View>;
  const k = dados.kpis;
  return (
    <ScrollView contentContainerStyle={{ padding: 16, gap: 14 }} refreshControl={<RefreshControl refreshing={atualizando} onRefresh={() => { setAtualizando(true); void carregar(); }} colors={[tema.primaria]} />}>
      <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 12 }}>
        <CartaoKpi kpi={k.pendentesNossos} destaque />
        <CartaoKpi kpi={k.urgentesPendentes} />
        <CartaoKpi kpi={k.abertos30d} />
        <CartaoKpi kpi={k.fechados30d} />
        <CartaoKpi kpi={k.primeiraRespostaHoras} />
        <CartaoKpi kpi={k.resolucaoHoras} />
      </View>
      <Grafico dados={dados} />
      <Situacao porStatus={dados.porStatus} />
      <T style={{ fontWeight: '800', fontSize: 18, marginTop: 4 }}>Esperando resposta nossa há mais tempo</T>
      {dados.maisAntigos.length === 0 ? <T style={{ color: tema.textoSuave }}>Nada pendente. 🎉</T>
        : dados.maisAntigos.map((c) => <CartaoChamado key={c.id} c={c} equipe aoAbrir={() => aoAbrirChamado(c)} />)}
      <Ranking titulo="Clientes que mais abriram (30 dias)" itens={dados.porCliente} vazio="Nenhum chamado nos últimos 30 dias." />
      <Ranking titulo="Pendências por responsável" itens={dados.porResponsavel} vazio="Nenhuma pendência. 🎉" />
      <Cartao style={{ gap: 10 }}>
        <T style={{ fontWeight: '700', fontSize: 17 }}>Assistente (30 dias)</T>
        <View style={{ flexDirection: 'row', gap: 8 }}>
          {([['Análises', dados.ia.analises30d], ['Respondidas', dados.ia.respondidasSozinha30d], ['Sugestões', dados.ia.sugestoes30d]] as const).map(([r, v]) => (
            <View key={r} style={{ flex: 1, alignItems: 'center', backgroundColor: tema.superficieAlta, borderRadius: 14, paddingVertical: 10 }}>
              <T style={{ fontSize: 12, color: tema.textoSuave }}>{r}</T>
              <T style={{ fontSize: 22, fontWeight: '800' }}>{v}</T>
            </View>
          ))}
        </View>
      </Cartao>
      <T style={{ textAlign: 'center', color: tema.textoSuave, fontSize: 12 }}>Atualizado às {new Date(dados.geradoEm).toLocaleTimeString('pt-BR')} · puxe para atualizar</T>
    </ScrollView>
  );
}

// ---------------------------------------------------------------- Chamados

function Chamados({ equipe, aoAbrirChamado, aoNovo }: { equipe: boolean; aoAbrirChamado: (c: ChamadoResumo) => void; aoNovo: () => void }) {
  const { tema } = useTema();
  const [status, setStatus] = useState('pendentes');
  const [busca, setBusca] = useState('');
  const [dados, setDados] = useState<ListaChamados | null>(null);
  const [atualizando, setAtualizando] = useState(false);
  const carregar = useCallback(() => api<ListaChamados>(`/api/chamados?status=${status}&busca=${encodeURIComponent(busca)}`).then(setDados).finally(() => setAtualizando(false)), [status, busca]);
  useEffect(() => { setDados(null); const t = setTimeout(() => void carregar(), busca ? 350 : 0); return () => clearTimeout(t); }, [carregar, busca]);

  const filtros = [['pendentes', 'Pendentes'], ['aguardando_equipe', 'Aguardando nós'], ['aguardando_cliente', 'Aguardando cliente'], ['fechado', 'Fechados'], ['', 'Todos']] as const;
  return (
    <View style={{ flex: 1 }}>
      <View style={{ paddingHorizontal: 16, paddingTop: 12, gap: 10 }}>
        <TextInput value={busca} onChangeText={setBusca} placeholder="🔍  Buscar por número, título ou cliente" placeholderTextColor={tema.textoSuave} maxFontSizeMultiplier={1.5}
          accessibilityLabel="Buscar chamado" style={{ minHeight: 50, borderRadius: 16, borderWidth: 1, borderColor: tema.borda, backgroundColor: tema.superficie, color: tema.texto, paddingHorizontal: 16, fontSize: 16 }} />
        {(
          <Pressable onPress={aoNovo} accessibilityRole="button" style={{ borderRadius: 16, overflow: 'hidden' }}>
            <LinearGradient colors={['#1E3FD8', '#0FB5C9']} start={{ x: 0, y: 0 }} end={{ x: 1, y: 0 }} style={{ minHeight: 52, alignItems: 'center', justifyContent: 'center' }}>
              <T style={{ color: '#FFFFFF', fontWeight: '800', fontSize: 17 }}>{equipe ? '＋ Novo chamado' : '＋ Abrir chamado'}</T>
            </LinearGradient>
          </Pressable>
        )}
      </View>
      <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8, paddingHorizontal: 16, paddingVertical: 10 }}>
        {filtros.map(([v, r]) => (
          <Pressable key={r} onPress={() => setStatus(v)} accessibilityRole="tab" accessibilityState={{ selected: status === v }}
            style={{ minHeight: 40, borderRadius: 99, paddingHorizontal: 13, justifyContent: 'center', backgroundColor: status === v ? tema.primaria : tema.superficie, borderWidth: 1, borderColor: status === v ? tema.primaria : tema.borda }}>
            <T style={{ fontWeight: '700', fontSize: 14, color: status === v ? tema.primariaTexto : tema.textoSuave }}>{r}</T>
          </Pressable>
        ))}
      </View>
      {!dados ? (
        <View style={{ padding: 16, gap: 10 }}>{[0, 1, 2, 3, 4].map((i) => <Esqueleto key={i} altura={110} />)}</View>
      ) : (
        <FlatList data={dados.itens} keyExtractor={(c) => String(c.id)} contentContainerStyle={{ paddingHorizontal: 16, paddingBottom: 16 }}
          ListHeaderComponent={<T style={{ color: tema.textoSuave, fontSize: 14, marginBottom: 8 }}>{dados.total} chamado(s)</T>}
          refreshControl={<RefreshControl refreshing={atualizando} onRefresh={() => { setAtualizando(true); void carregar(); }} colors={[tema.primaria]} />}
          ListEmptyComponent={<T style={{ color: tema.textoSuave, textAlign: 'center', marginTop: 40 }}>Nenhum chamado por aqui.</T>}
          renderItem={({ item }) => <CartaoChamado c={item} equipe={equipe} aoAbrir={() => aoAbrirChamado(item)} />} />
      )}
    </View>
  );
}

// ---------------------------------------------------------------- Menu

interface ItemMenu { icone: string; rotulo: string; sub?: string; destino?: string; acao?: () => void; perigo?: boolean }

function Menu({ itens }: { itens: ItemMenu[] }) {
  const { tema } = useTema();
  return (
    <ScrollView contentContainerStyle={{ padding: 16, flexDirection: 'row', flexWrap: 'wrap', gap: 12 }}>
      {itens.map((i) => (
        <Pressable key={i.rotulo} onPress={i.acao} accessibilityRole="button" accessibilityLabel={i.rotulo}
          style={({ pressed }) => ({ flexBasis: '47%', flexGrow: 1, minHeight: 104, borderRadius: 20, padding: 14, gap: 8, justifyContent: 'center',
            backgroundColor: tema.superficie, borderWidth: 1, borderColor: i.perigo ? tema.perigo : tema.borda, opacity: pressed ? 0.8 : 1 })}>
          <View style={{ width: 46, height: 46, borderRadius: 14, alignItems: 'center', justifyContent: 'center', backgroundColor: i.perigo ? 'transparent' : tema.superficieAlta }}>
            <T style={{ fontSize: 24 }}>{i.icone}</T>
          </View>
          <T style={{ fontWeight: '700', fontSize: 16, color: i.perigo ? tema.perigo : tema.texto }}>{i.rotulo}</T>
          {!!i.sub && <T style={{ fontSize: 11, color: tema.textoSuave }} numberOfLines={1}>{i.sub}</T>}
        </Pressable>
      ))}
    </ScrollView>
  );
}

// ---------------------------------------------------------------- Tela principal

export function Inicio({ usuario, aoSair }: { usuario: UsuarioLogado; aoSair: () => void }) {
  const { tema, alternar, nome } = useTema();
  const insets = useSafeAreaInsets();
  const equipe = usuario.tipo === 'equipe';
  const [aba, setAba] = useState<Aba>(equipe ? 'painel' : 'chamados');
  const [foto, setFoto] = useState<string | null>(usuario.foto);
  const [foraDoHorario, setForaDoHorario] = useState(false);
  useEffect(() => { aoFicarForaDoHorario(() => setForaDoHorario(true)); return () => aoFicarForaDoHorario(null); }, []);
  const [pilha, setPilha] = useState<Tela[]>([]);
  const ir = (t: Tela) => setPilha((p) => [...p, t]);
  // Última tela vista antes de abrir o Assistente: a IA ajuda "nessa tela".
  const [telaVista, setTelaVista] = useState<{ rota: string; titulo: string; texto: string } | undefined>(undefined);
  const voltar = useCallback(() => setPilha((p) => p.slice(0, -1)), []);

  // Botão "voltar" do Android volta uma tela em vez de fechar o app.
  useEffect(() => {
    const sub = BackHandler.addEventListener('hardwareBackPress', () => {
      if (pilha.length) { voltar(); return true; }
      return false;
    });
    return () => sub.remove();
  }, [pilha.length, voltar]);

  const abrirChamado = (c: ChamadoResumo) => ir({ tipo: 'chamado', id: c.id });

  const modulo = (icone: string, rotulo: string, tipo: Exclude<Tela, { id: number }>['tipo']): ItemMenu => ({ icone, rotulo, acao: () => ir({ tipo } as Tela) });
  const admin = ADMIN.includes(usuario.perfil ?? '');
  const [versaoProtheus, setVersaoProtheus] = useState('');
  useEffect(() => {
    if (!admin) return;
    api<VersoesProtheus>('/api/vm/protheus/versao').then((v) => { if (v.protheus) setVersaoProtheus(`${v.protheus}${v.appserver ? ` · AppServer ${v.appserver}` : ''}`); }).catch(() => undefined);
  }, [admin]);
  const itensMenu: ItemMenu[] = equipe ? [
    modulo('👥', 'Clientes', 'clientes'),
    modulo('🤖', 'Mesa do agente', 'agente'),
    ...(admin ? [modulo('📅', 'Agenda', 'agenda'), modulo('📈', 'Relatórios', 'relatorios'), modulo('💻', 'Fontes ADVPL', 'fontes'), modulo('🧑‍💼', 'Usuários', 'usuarios'), modulo('⚙️', 'Configurações', 'configuracoes')] : []),
    modulo('👤', 'Meu perfil', 'perfil'),
    ...(admin ? [{ ...modulo('🖥️', 'Protheus', 'protheus'), sub: versaoProtheus }] : []),
    { icone: nome === 'escuro' ? '☀️' : '🌙', rotulo: nome === 'escuro' ? 'Tema claro' : 'Tema escuro', acao: alternar },
    { icone: '⎋', rotulo: 'Sair', acao: aoSair, perigo: true },
  ] : [
    modulo('📅', 'Calendário', 'calendario'),
    modulo('🎬', 'Videoaulas', 'videoaulas'),
    modulo('💻', 'Fontes ADVPL', 'fontesCliente'),
    modulo('👥', 'Minha equipe', 'equipeCliente'),
    modulo('👤', 'Meu perfil', 'perfil'),
    { icone: nome === 'escuro' ? '☀️' : '🌙', rotulo: nome === 'escuro' ? 'Tema claro' : 'Tema escuro', acao: alternar },
    { icone: '⎋', rotulo: 'Sair', acao: aoSair, perigo: true },
  ];

  const abas: [Aba, string, string][] = [
    ...(equipe ? [['painel', '📊', 'Painel'] as [Aba, string, string]] : []),
    ['chamados', '🎧', 'Chamados'],
    ['assistente', '🤖', 'Assistente'],
    ['menu', '☰', 'Menu'],
  ];
  const titulos: Record<Aba, string> = { painel: 'Dashboard', chamados: equipe ? 'Chamados' : 'Meus chamados', assistente: 'Assistente PrimeERP', menu: 'Menu' };
  const atual = pilha[pilha.length - 1];
  useEffect(() => {
    if (aba === 'assistente') return;
    const t = pilha[pilha.length - 1];
    const descricao = !t ? `Aba ${aba} do app` : t.tipo === 'chamado' ? `Conversa do chamado número ${t.id}` : t.tipo === 'cliente' ? `Ficha do cliente id ${t.id}`
      : t.tipo === 'setor' ? `Página do setor id ${t.id} do cliente id ${t.clienteId}` : t.tipo === 'novoChamado' ? 'Formulário de abrir chamado' : `Tela ${t.tipo}`;
    setTelaVista({ rota: `app:${t ? t.tipo : aba}`, titulo: t ? (TITULOS_TELA[t.tipo] ?? t.tipo) : aba, texto: `${descricao} (app Android). Use as ferramentas para consultar os dados dessa tela.` });
  }, [aba, pilha]);
  const tituloAtual = !atual ? titulos[aba] : atual.tipo === 'chamado' ? `Chamado #${atual.id}` : atual.tipo === 'cliente' ? 'Cliente' : atual.tipo === 'setor' ? 'Setor'
    : atual.tipo === 'novoChamado' ? (equipe ? 'Novo chamado' : 'Abrir chamado') : TITULOS_TELA[atual.tipo] ?? '';
  const iniciais = usuario.nome.split(' ').slice(0, 2).map((p) => p[0] ?? '').join('').toUpperCase();

  if (foraDoHorario && !equipe) {
    return (
      <FundoCircuito>
        <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', padding: 24, gap: 16, paddingTop: insets.top + 24 }}>
          <T style={{ fontSize: 56 }}>⏰</T>
          <T style={{ fontSize: 24, fontWeight: '800', textAlign: 'center' }}>Voltamos já, já!</T>
          <T style={{ color: tema.textoSuave, fontSize: 16, textAlign: 'center', lineHeight: 23 }}>O portal funciona em horário comercial, das 09:00 às 18:00. Volte nesse horário para abrir ou acompanhar seus chamados.</T>
          <Pressable onPress={() => { setForaDoHorario(false); setPilha([]); }} accessibilityRole="button" style={{ minHeight: 52, borderRadius: 16, paddingHorizontal: 24, justifyContent: 'center', backgroundColor: tema.primaria }}>
            <T style={{ color: tema.primariaTexto, fontWeight: '800' }}>Tentar de novo</T>
          </Pressable>
          <Pressable onPress={aoSair} accessibilityRole="button" style={{ minHeight: 48, justifyContent: 'center' }}><T style={{ color: tema.textoSuave, textDecorationLine: 'underline' }}>Sair</T></Pressable>
        </View>
      </FundoCircuito>
    );
  }

  return (
    <FundoCircuito>
      <View style={{ paddingTop: insets.top + 8, paddingHorizontal: 16, paddingBottom: 12, borderBottomWidth: 1, borderColor: tema.borda,
        backgroundColor: nome === 'escuro' ? 'rgba(15,22,49,0.92)' : 'rgba(255,255,255,0.94)', flexDirection: 'row', alignItems: 'center', gap: 12 }}>
        {atual ? (
          <Pressable onPress={voltar} accessibilityRole="button" accessibilityLabel="Voltar" hitSlop={8}
            style={{ width: 44, height: 44, borderRadius: 14, alignItems: 'center', justifyContent: 'center', backgroundColor: tema.superficieAlta }}>
            <T style={{ fontSize: 22, fontWeight: '800' }}>←</T>
          </Pressable>
        ) : <Image source={require('../assets/icone-p.png')} style={{ width: 30, height: 36 }} resizeMode="contain" />}
        <View style={{ flex: 1 }}>
          <T style={{ fontSize: 21, fontWeight: '800' }} accessibilityRole="header" numberOfLines={1}>{tituloAtual}</T>
          <T style={{ color: tema.textoSuave, fontSize: 13 }} numberOfLines={1}>{usuario.nome}{usuario.empresa ? ` · ${usuario.empresa}` : ' · Central da equipe'}</T>
        </View>
        <Pressable onPress={() => ir({ tipo: 'perfil' })} accessibilityRole="button" accessibilityLabel="Meu perfil">
          {foto ? <FotoPessoa caminho={foto} tamanho={40} nome={usuario.nome} /> : (
            <LinearGradient colors={['#1E3FD8', '#0FB5C9']} style={{ width: 40, height: 40, borderRadius: 20, alignItems: 'center', justifyContent: 'center' }}>
              <T style={{ color: '#FFFFFF', fontWeight: '800', fontSize: 14 }}>{iniciais}</T>
            </LinearGradient>
          )}
        </Pressable>
      </View>
      {atual && (
        <View style={{ flex: 1 }}>
          {atual.tipo === 'chamado' && <TelaChamado key={atual.id} id={atual.id} equipe={equipe} aoAbrirCliente={(id) => ir({ tipo: 'cliente', id })} />}
          {atual.tipo === 'cliente' && <TelaCliente key={atual.id} id={atual.id} CartaoChamado={CartaoChamado} aoAbrirChamado={(id) => ir({ tipo: 'chamado', id })} aoNovoChamado={() => ir({ tipo: 'novoChamado', clienteId: atual.id })} aoAbrirSetor={(sid) => ir({ tipo: 'setor', clienteId: atual.id, id: sid })} />}
          {atual.tipo === 'setor' && <TelaSetor key={atual.id} clienteId={atual.clienteId} setorId={atual.id} CartaoChamado={CartaoChamado} aoAbrirChamado={(id) => ir({ tipo: 'chamado', id })} />}
          {atual.tipo === 'clientes' && <TelaClientes aoAbrir={(id) => ir({ tipo: 'cliente', id })} />}
          {atual.tipo === 'relatorios' && <TelaRelatorios />}
          {atual.tipo === 'protheus' && <TelaProtheus />}
          {atual.tipo === 'agente' && <TelaMesaAgente aoAbrirChamado={(id) => ir({ tipo: 'chamado', id })} />}
          {atual.tipo === 'agenda' && <TelaAgenda aoAbrirChamado={(id) => ir({ tipo: 'chamado', id })} />}
          {atual.tipo === 'fontes' && <TelaFontes />}
          {atual.tipo === 'usuarios' && <TelaUsuarios />}
          {atual.tipo === 'configuracoes' && <TelaConfiguracoes />}
          {atual.tipo === 'perfil' && <TelaPerfil equipe={equipe} aoMudarFoto={setFoto} />}
          {atual.tipo === 'calendario' && <TelaCalendario />}
          {atual.tipo === 'videoaulas' && <TelaVideoaulas />}
          {atual.tipo === 'fontesCliente' && <TelaFontesCliente />}
          {atual.tipo === 'equipeCliente' && <TelaEquipeCliente />}
          {atual.tipo === 'novoChamado' && <TelaNovoChamado equipe={equipe} {...(atual.clienteId ? { clienteInicial: atual.clienteId } : {})}
            aoCriar={(id) => setPilha((p) => [...p.slice(0, -1), ...(id ? [{ tipo: 'chamado' as const, id }] : [])])} />}
        </View>
      )}
      <View style={{ flex: 1, display: atual ? 'none' : 'flex' }}>
        {aba === 'painel' && <Painel aoAbrirChamado={abrirChamado} />}
        {aba === 'chamados' && <Chamados equipe={equipe} aoAbrirChamado={abrirChamado} aoNovo={() => ir({ tipo: 'novoChamado' })} />}
        {aba === 'assistente' && <AssistenteApp usuario={usuario} {...(telaVista ? { tela: telaVista } : {})} />}
        {aba === 'menu' && <Menu itens={itensMenu} />}
      </View>
      <View accessibilityRole="tablist" style={{ flexDirection: 'row', borderTopWidth: 1, borderColor: tema.borda,
        backgroundColor: nome === 'escuro' ? 'rgba(15,22,49,0.96)' : 'rgba(255,255,255,0.97)', paddingBottom: insets.bottom }}>
        {abas.map(([id, icone, rotulo]) => (
          <Pressable key={id} onPress={() => { setPilha([]); setAba(id); }} accessibilityRole="tab" accessibilityState={{ selected: aba === id }} accessibilityLabel={rotulo}
            style={{ flex: 1, minHeight: 62, alignItems: 'center', justifyContent: 'center', gap: 2 }}>
            <View style={{ paddingHorizontal: 16, paddingVertical: 3, borderRadius: 99, backgroundColor: aba === id ? tema.superficieAlta : 'transparent' }}>
              <T style={{ fontSize: 21, opacity: aba === id ? 1 : 0.6 }}>{icone}</T>
            </View>
            <T style={{ fontSize: 12, fontWeight: '700', color: aba === id ? tema.primaria : tema.textoSuave }}>{rotulo}</T>
          </Pressable>
        ))}
      </View>
    </FundoCircuito>
  );
}
