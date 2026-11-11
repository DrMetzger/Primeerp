import { corStatus, rotuloStatus, type ChamadoDetalhe, type ClienteDetalhe, type ClienteResumo, type ListaChamados } from '@primehub/shared';
import { LinearGradient } from 'expo-linear-gradient';
import { type ComponentType, type ReactNode, useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Image, KeyboardAvoidingView, Linking, Platform, Pressable, RefreshControl, ScrollView, TextInput, View } from 'react-native';
import { acaoPhp, api, arquivoPublico, linkArquivo, relatorioJson } from './api';
import { Esqueleto } from './Esqueleto';
import { useTema } from './tema';
import { Cartao, T } from './ui';

// ---------------------------------------------------------------- Peças

/** Baixa/abre um arquivo no navegador do celular, já com acesso. */
export async function abrirArquivo(destino: string): Promise<void> {
  try { await Linking.openURL(await linkArquivo(destino)); } catch { /* sem internet: nada a fazer */ }
}

export function LogoEmpresa({ nome, logo, tamanho = 40 }: { nome: string | null; logo: string | null; tamanho?: number }) {
  const [falhou, setFalhou] = useState(false);
  const uri = arquivoPublico(logo);
  const iniciais = (nome ?? '?').split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0] ?? '').join('').toUpperCase();
  if (uri && !falhou) {
    return (
      <View style={{ width: tamanho, height: tamanho, borderRadius: 12, backgroundColor: '#FFFFFF', padding: 3, overflow: 'hidden' }}>
        <Image source={{ uri }} onError={() => setFalhou(true)} style={{ width: '100%', height: '100%' }} resizeMode="contain" accessibilityIgnoresInvertColors />
      </View>
    );
  }
  return (
    <LinearGradient colors={['#1E3FD8', '#0FB5C9']} style={{ width: tamanho, height: tamanho, borderRadius: 12, alignItems: 'center', justifyContent: 'center' }}>
      <T style={{ color: '#FFFFFF', fontWeight: '800', fontSize: tamanho / 3 }}>{iniciais}</T>
    </LinearGradient>
  );
}

export function BotaoGradiente({ titulo, aoTocar, desativado }: { titulo: string; aoTocar: () => void; desativado?: boolean }) {
  return (
    <Pressable onPress={aoTocar} disabled={desativado} accessibilityRole="button" style={{ borderRadius: 16, overflow: 'hidden', opacity: desativado ? 0.6 : 1 }}>
      <LinearGradient colors={['#1E3FD8', '#0FB5C9']} start={{ x: 0, y: 0 }} end={{ x: 1, y: 0 }} style={{ minHeight: 52, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 18 }}>
        <T style={{ color: '#FFFFFF', fontWeight: '800', fontSize: 17 }}>{titulo}</T>
      </LinearGradient>
    </Pressable>
  );
}

export function BotaoContorno({ titulo, aoTocar, desativado }: { titulo: string; aoTocar: () => void; desativado?: boolean }) {
  const { tema } = useTema();
  return (
    <Pressable onPress={aoTocar} disabled={desativado} accessibilityRole="button"
      style={({ pressed }) => ({ minHeight: 50, borderRadius: 16, borderWidth: 1, borderColor: tema.borda, backgroundColor: tema.superficie, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 16, opacity: pressed || desativado ? 0.7 : 1 })}>
      <T style={{ fontWeight: '700' }}>{titulo}</T>
    </Pressable>
  );
}

/** Escolha em "pílulas" (no dedo é melhor que lista suspensa). */
export function Escolha<V extends string>({ rotulo, opcoes, valor, aoMudar }: { rotulo: string; opcoes: { valor: V; rotulo: string }[]; valor: V; aoMudar: (v: V) => void }) {
  const { tema } = useTema();
  return (
    <View style={{ gap: 6 }}>
      <T style={{ fontWeight: '700', fontSize: 14 }}>{rotulo}</T>
      <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8 }}>
        {opcoes.map((o) => {
          const ativo = o.valor === valor;
          return (
            <Pressable key={o.valor} onPress={() => aoMudar(o.valor)} accessibilityRole="radio" accessibilityState={{ selected: ativo }}
              style={{ minHeight: 44, borderRadius: 99, paddingHorizontal: 14, justifyContent: 'center', backgroundColor: ativo ? tema.primaria : tema.superficieAlta, borderWidth: 1, borderColor: ativo ? tema.primaria : tema.borda }}>
              <T style={{ fontSize: 14, fontWeight: '700', color: ativo ? tema.primariaTexto : tema.texto }}>{o.rotulo}</T>
            </Pressable>
          );
        })}
      </View>
    </View>
  );
}

export function Entrada({ rotulo, valor, aoMudar, linhas = 1, dica }: { rotulo: string; valor: string; aoMudar: (v: string) => void; linhas?: number; dica?: string }) {
  const { tema } = useTema();
  return (
    <View style={{ gap: 6 }}>
      <T style={{ fontWeight: '700', fontSize: 14 }}>{rotulo}</T>
      <TextInput value={valor} onChangeText={aoMudar} multiline={linhas > 1} numberOfLines={linhas} placeholder={dica} placeholderTextColor={tema.textoSuave} accessibilityLabel={rotulo}
        maxFontSizeMultiplier={1.5} textAlignVertical={linhas > 1 ? 'top' : 'center'}
        style={{ minHeight: linhas > 1 ? 24 * linhas : 50, borderRadius: 14, borderWidth: 1, borderColor: tema.borda, backgroundColor: tema.superficie, color: tema.texto, paddingHorizontal: 14, paddingVertical: 10, fontSize: 16 }} />
    </View>
  );
}

export const dataBR = (v: string | null | undefined, hora = false) => {
  if (!v) return '—';
  const d = new Date(v.replace(' ', 'T'));
  return Number.isNaN(d.getTime()) ? v : hora ? d.toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' }) : d.toLocaleDateString('pt-BR');
};

export function Vazio({ children }: { children: ReactNode }) {
  const { tema } = useTema();
  return <T style={{ color: tema.textoSuave, textAlign: 'center', marginVertical: 30 }}>{children}</T>;
}

// ---------------------------------------------------------------- Chamado

const STATUS_APOS = [
  { valor: 'aguardando_cliente', rotulo: 'Aguardando cliente' },
  { valor: 'aguardando_equipe', rotulo: 'Aguardando nós' },
  { valor: 'fechado', rotulo: 'Encerrar' },
] as const;
type StatusApos = (typeof STATUS_APOS)[number]['valor'];

export function TelaChamado({ id, equipe, aoAbrirCliente }: { id: number; equipe: boolean; aoAbrirCliente?: (id: number) => void }) {
  const { tema } = useTema();
  const [dados, setDados] = useState<ChamadoDetalhe | null>(null);
  const [erro, setErro] = useState('');
  const [mensagem, setMensagem] = useState('');
  const [statusApos, setStatusApos] = useState<StatusApos>('aguardando_cliente');
  const [avisar, setAvisar] = useState(true);
  const [enviando, setEnviando] = useState(false);
  const [aviso, setAviso] = useState('');
  const [ia, setIa] = useState<{ rodando: boolean; texto: string }>({ rodando: false, texto: '' });

  const carregar = useCallback(async () => {
    try { setDados(await api<ChamadoDetalhe>(`/api/chamados/${id}`)); } catch (e) { setErro(e instanceof Error ? e.message : 'Não consegui abrir esse chamado.'); }
  }, [id]);
  useEffect(() => { void carregar(); }, [carregar]);

  async function responder() {
    if (!mensagem.trim()) return;
    setEnviando(true); setAviso('');
    try {
      const campos: Record<string, string> = { acao: 'responder_chamado', chamado_id: String(id), mensagem };
      if (equipe) { campos.status_apos_resposta = statusApos; campos.notificar_cliente = avisar ? '1' : '0'; }
      await acaoPhp('api_acoes.php', campos);
      setMensagem(''); setAviso('Resposta enviada. ✅');
      await carregar();
    } catch (e) { setAviso(e instanceof Error ? e.message : 'Não deu pra enviar agora.'); }
    setEnviando(false);
  }

  async function encerrarOuReabrir() {
    if (!dados) return;
    const fechado = dados.chamado.status === 'fechado';
    try {
      if (equipe) await acaoPhp('api_acoes.php', { acao: 'responder_chamado', chamado_id: String(id), mensagem: '', status_apos_resposta: fechado ? 'aguardando_equipe' : 'fechado', notificar_cliente: '0' });
      else await acaoPhp('api_acoes.php', { acao: fechado ? 'reabrir_chamado' : 'encerrar_chamado', chamado_id: String(id) });
      await carregar();
    } catch (e) { setAviso(e instanceof Error ? e.message : 'Não deu certo agora.'); }
  }

  /** Sugestão da IA: só preenche o campo, nunca envia sozinha. */
  async function sugerir() {
    setIa({ rodando: true, texto: 'Analisando o chamado…' });
    try {
      await acaoPhp('ia_sugerir_resposta.php', { acao: 'iniciar', chamado_id: String(id) });
      for (let i = 0; i < 40; i++) {
        const e = await acaoPhp<{ terminou?: boolean; sucesso?: boolean; resposta?: string; status_sugerido?: string; erro?: string; passos?: { texto?: string }[] }>(
          'ia_sugerir_resposta.php', { acao: 'passo', chamado_id: String(id) }).catch(() => null);
        if (!e) continue;
        const ultimo = e.passos?.[e.passos.length - 1]?.texto;
        if (ultimo) setIa({ rodando: true, texto: ultimo });
        if (e.terminou) {
          if (e.sucesso && e.resposta) {
            setMensagem(e.resposta);
            if (e.status_sugerido && STATUS_APOS.some((s) => s.valor === e.status_sugerido)) setStatusApos(e.status_sugerido as StatusApos);
            setIa({ rodando: false, texto: 'Pronto! Confira e ajuste antes de enviar.' });
          } else setIa({ rodando: false, texto: e.erro ?? 'Dessa vez não achei uma boa resposta. Vale responder do seu jeito.' });
          return;
        }
      }
      setIa({ rodando: false, texto: 'A análise demorou demais. Tente de novo em instantes.' });
    } catch (e) { setIa({ rodando: false, texto: e instanceof Error ? e.message : 'Não consegui analisar agora.' }); }
  }

  if (erro) return <Vazio>{erro}</Vazio>;
  if (!dados) return <View style={{ padding: 16, gap: 12 }}>{[120, 90, 90, 200].map((a, i) => <Esqueleto key={i} altura={a} />)}</View>;
  const c = dados.chamado;
  const cor = tema[corStatus[c.status]];

  return (
    <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <ScrollView contentContainerStyle={{ padding: 16, gap: 12 }} keyboardShouldPersistTaps="handled">
        <Cartao style={{ gap: 8 }}>
          <View style={{ flexDirection: 'row', gap: 12, alignItems: 'center' }}>
            {equipe && <LogoEmpresa nome={c.cliente} logo={c.clienteLogo} tamanho={46} />}
            <View style={{ flex: 1 }}>
              <T style={{ fontWeight: '800', fontSize: 18 }}>{c.titulo}</T>
              {equipe && c.clienteId ? (
                <Pressable onPress={() => aoAbrirCliente?.(c.clienteId ?? 0)} accessibilityRole="link"><T style={{ color: tema.primaria, fontWeight: '700', fontSize: 14 }}>{c.cliente}</T></Pressable>
              ) : null}
            </View>
          </View>
          <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8, alignItems: 'center' }}>
            <View style={{ flexDirection: 'row', alignItems: 'center', gap: 6, borderRadius: 99, paddingHorizontal: 10, paddingVertical: 4, backgroundColor: tema.superficieAlta }}>
              <View style={{ width: 8, height: 8, borderRadius: 4, backgroundColor: cor }} />
              <T style={{ color: cor, fontWeight: '700', fontSize: 13 }}>{rotuloStatus[c.status]}</T>
            </View>
            <T style={{ color: tema.textoSuave, fontSize: 13 }}>#{c.id} · {dataBR(c.criadoEm, true)}{c.setor ? ` · ${c.setor}` : ''}</T>
          </View>
          <T style={{ color: tema.textoSuave, fontSize: 14 }}>Responsável: {c.responsavel ?? (equipe ? 'ninguém ainda' : 'nossa equipe')}</T>
        </Cartao>

        {dados.mensagens.map((m) => {
          const minha = (m.autorTipo === 'equipe') === equipe;
          return (
            <View key={m.id} style={{ alignSelf: minha ? 'flex-end' : 'flex-start', maxWidth: '92%', borderRadius: 20, padding: 14, gap: 6,
              backgroundColor: minha ? tema.superficieAlta : tema.superficie, borderWidth: 1, borderColor: minha ? tema.primaria : tema.borda }}>
              <T style={{ color: tema.textoSuave, fontSize: 12, fontWeight: '700' }}>{m.autorNome} · {dataBR(m.criadoEm, true)}</T>
              <T selectable style={{ fontSize: 15, lineHeight: 22 }}>{m.mensagem}</T>
              {m.anexos.map((a) => (
                <Pressable key={a.id} onPress={() => void abrirArquivo(`/download_anexo_chamado.php?id=${a.id}`)} accessibilityRole="link" style={{ minHeight: 40, justifyContent: 'center' }}>
                  <T style={{ color: tema.primaria, fontSize: 14, fontWeight: '700' }}>📎 {a.nome}</T>
                </Pressable>
              ))}
            </View>
          );
        })}

        <Cartao style={{ gap: 12 }}>
          <T style={{ fontWeight: '800', fontSize: 17 }}>{c.status === 'fechado' ? 'Encerrado — responder reabre' : 'Responder'}</T>
          {equipe && <BotaoContorno titulo={ia.rodando ? '✨ Analisando…' : '✨ Sugerir resposta com IA'} aoTocar={() => void sugerir()} desativado={ia.rodando} />}
          {!!ia.texto && <View style={{ flexDirection: 'row', gap: 8, alignItems: 'center' }}>{ia.rodando && <ActivityIndicator color={tema.primaria} />}<T style={{ flex: 1, color: tema.textoSuave, fontSize: 14 }}>{ia.texto}</T></View>}
          {equipe && dados.ia?.resposta && !mensagem && !ia.rodando && (
            <Pressable onPress={() => setMensagem(dados.ia?.resposta ?? '')} style={{ borderRadius: 14, padding: 12, borderWidth: 1, borderColor: tema.destaque }}>
              <T style={{ fontSize: 14 }}>✨ A IA já deixou uma sugestão pronta. Toque para usar e revisar.</T>
            </Pressable>
          )}
          <Entrada rotulo="Mensagem" valor={mensagem} aoMudar={setMensagem} linhas={6} dica="Escreva a resposta…" />
          {equipe && (
            <>
              <Escolha rotulo="Depois de responder" opcoes={[...STATUS_APOS]} valor={statusApos} aoMudar={setStatusApos} />
              <Escolha rotulo="Avisar o cliente (e-mail e WhatsApp)" opcoes={[{ valor: 'sim', rotulo: 'Sim' }, { valor: 'nao', rotulo: 'Não' }]} valor={avisar ? 'sim' : 'nao'} aoMudar={(v) => setAvisar(v === 'sim')} />
            </>
          )}
          <BotaoGradiente titulo={enviando ? 'Enviando…' : 'Enviar resposta'} aoTocar={() => void responder()} desativado={enviando || !mensagem.trim()} />
          {!!aviso && <T style={{ color: tema.textoSuave, fontSize: 14 }}>{aviso}</T>}
        </Cartao>
        <BotaoContorno titulo={c.status === 'fechado' ? '↺ Reabrir chamado' : '✓ Encerrar chamado'} aoTocar={() => void encerrarOuReabrir()} />
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

// ---------------------------------------------------------------- Novo chamado

interface Opcoes { categorias: string[]; setores: { id: number; nome: string }[]; clientes: { id: number; nome: string }[]; responsaveis: { id: number; nome: string }[] }

export function TelaNovoChamado({ equipe, clienteInicial, aoCriar }: { equipe: boolean; clienteInicial?: number; aoCriar: (id: number | null) => void }) {
  const { tema } = useTema();
  const [opcoes, setOpcoes] = useState<Opcoes | null>(null);
  const [clienteId, setClienteId] = useState(clienteInicial ? String(clienteInicial) : '');
  const [busca, setBusca] = useState('');
  const [titulo, setTitulo] = useState('');
  const [categoria, setCategoria] = useState('');
  const [prioridade, setPrioridade] = useState<'baixa' | 'media' | 'alta' | 'urgente'>('media');
  const [setor, setSetor] = useState('');
  const [descricao, setDescricao] = useState('');
  const [enviando, setEnviando] = useState(false);
  const [erro, setErro] = useState('');

  useEffect(() => {
    acaoPhp<Opcoes>('api_acoes.php', { acao: 'opcoes_chamado', cliente_id: clienteId }).then(setOpcoes).catch(() => setOpcoes({ categorias: ['Geral'], setores: [], clientes: [], responsaveis: [] }));
  }, [clienteId]);

  async function abrir() {
    setEnviando(true); setErro('');
    try {
      const r = await acaoPhp('api_acoes.php', { acao: 'abrir_chamado', cliente_id: clienteId, titulo, categoria, prioridade, setor_id: setor, descricao });
      aoCriar(r.id ?? null);
    } catch (e) { setErro(e instanceof Error ? e.message : 'Não deu pra abrir agora.'); setEnviando(false); }
  }

  if (!opcoes) return <View style={{ padding: 16, gap: 12 }}>{[60, 60, 120, 200].map((a, i) => <Esqueleto key={i} altura={a} />)}</View>;
  const clientesFiltrados = opcoes.clientes.filter((c) => !busca || c.nome.toLowerCase().includes(busca.toLowerCase())).slice(0, 12);
  const pronto = titulo.trim() && categoria && descricao.trim() && (!equipe || clienteId);

  return (
    <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <ScrollView contentContainerStyle={{ padding: 16, gap: 16 }} keyboardShouldPersistTaps="handled">
        {equipe && (
          <Cartao style={{ gap: 10 }}>
            <Entrada rotulo="Cliente *" valor={busca} aoMudar={setBusca} dica="Digite para filtrar" />
            <Escolha rotulo="" opcoes={clientesFiltrados.map((c) => ({ valor: String(c.id), rotulo: c.nome }))} valor={clienteId} aoMudar={(v) => { setClienteId(v); setSetor(''); }} />
          </Cartao>
        )}
        <Entrada rotulo="Título *" valor={titulo} aoMudar={setTitulo} dica="Resumo do problema" />
        <Escolha rotulo="Categoria *" opcoes={opcoes.categorias.map((c) => ({ valor: c, rotulo: c }))} valor={categoria} aoMudar={setCategoria} />
        <Escolha rotulo="Prioridade" opcoes={[{ valor: 'baixa', rotulo: 'Baixa' }, { valor: 'media', rotulo: 'Média' }, { valor: 'alta', rotulo: 'Alta' }, { valor: 'urgente', rotulo: 'Urgente' }]} valor={prioridade} aoMudar={setPrioridade} />
        {opcoes.setores.length > 0 && <Escolha rotulo="Setor" opcoes={[{ valor: '', rotulo: equipe ? 'Sem setor' : 'Padrão' }, ...opcoes.setores.map((s) => ({ valor: String(s.id), rotulo: s.nome }))]} valor={setor} aoMudar={setSetor} />}
        <Entrada rotulo="Descrição *" valor={descricao} aoMudar={setDescricao} linhas={7} dica="Conte o que aconteceu, a rotina e a mensagem de erro." />
        {!!erro && <T style={{ color: tema.perigo }}>{erro}</T>}
        <BotaoGradiente titulo={enviando ? 'Abrindo…' : 'Abrir chamado'} aoTocar={() => void abrir()} desativado={enviando || !pronto} />
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

// ---------------------------------------------------------------- Clientes

export function TelaClientes({ aoAbrir }: { aoAbrir: (id: number) => void }) {
  const { tema } = useTema();
  const [busca, setBusca] = useState('');
  const [itens, setItens] = useState<ClienteResumo[] | null>(null);
  useEffect(() => {
    setItens(null);
    const t = setTimeout(() => { api<ClienteResumo[]>(`/api/clientes?busca=${encodeURIComponent(busca)}`).then(setItens).catch(() => setItens([])); }, busca ? 350 : 0);
    return () => clearTimeout(t);
  }, [busca]);

  return (
    <ScrollView contentContainerStyle={{ padding: 16, gap: 10 }} keyboardShouldPersistTaps="handled">
      <TextInput value={busca} onChangeText={setBusca} placeholder="🔍  Buscar empresa, contato ou e-mail" placeholderTextColor={tema.textoSuave} maxFontSizeMultiplier={1.5} accessibilityLabel="Buscar cliente"
        style={{ minHeight: 50, borderRadius: 16, borderWidth: 1, borderColor: tema.borda, backgroundColor: tema.superficie, color: tema.texto, paddingHorizontal: 16, fontSize: 16 }} />
      {!itens ? [0, 1, 2, 3].map((i) => <Esqueleto key={i} altura={120} />) : !itens.length ? <Vazio>Nenhum cliente encontrado.</Vazio> : itens.map((c) => (
        <Pressable key={c.id} onPress={() => aoAbrir(c.id)} accessibilityRole="button" accessibilityLabel={`${c.nome}, ${c.pendentes} pendentes`} style={({ pressed }) => ({ opacity: pressed ? 0.85 : 1 })}>
          <Cartao style={{ gap: 12 }}>
            <View style={{ flexDirection: 'row', alignItems: 'center', gap: 12 }}>
              <LogoEmpresa nome={c.nome} logo={c.logo} tamanho={48} />
              <View style={{ flex: 1 }}>
                <T style={{ fontWeight: '800', fontSize: 17 }} numberOfLines={2}>{c.nome}</T>
                <T style={{ color: tema.textoSuave, fontSize: 14 }} numberOfLines={1}>{c.contatoNome ?? c.contatoEmail ?? 'Sem contato cadastrado'}</T>
              </View>
            </View>
            <View style={{ flexDirection: 'row', gap: 8 }}>
              {([['pendentes', c.pendentes, c.pendentes > 0], ['chamados', c.totalChamados, false], ['docs', c.documentos, false]] as const).map(([r, v, alerta]) => (
                <View key={r} style={{ flex: 1, alignItems: 'center', borderRadius: 14, paddingVertical: 8, backgroundColor: tema.superficieAlta }}>
                  <T style={{ fontSize: 20, fontWeight: '800', color: alerta ? tema.perigo : tema.texto }}>{v}</T>
                  <T style={{ fontSize: 12, color: tema.textoSuave }}>{r}</T>
                </View>
              ))}
            </View>
            {c.urgentes > 0 && <T style={{ color: tema.perigo, fontWeight: '700', fontSize: 14 }}>🔥 {c.urgentes} urgente(s) esperando</T>}
          </Cartao>
        </Pressable>
      ))}
    </ScrollView>
  );
}

type AbaCliente = 'geral' | 'chamados' | 'setores' | 'pessoas' | 'documentos' | 'agenda';

export function TelaCliente({ id, aoAbrirChamado, aoNovoChamado, CartaoChamado }: {
  id: number; aoAbrirChamado: (id: number) => void; aoNovoChamado: () => void;
  CartaoChamado: ComponentType<{ c: ListaChamados['itens'][number]; aoAbrir: () => void; equipe: boolean }>;
}) {
  const { tema } = useTema();
  const [dados, setDados] = useState<ClienteDetalhe | null>(null);
  const [chamados, setChamados] = useState<ListaChamados | null>(null);
  const [aba, setAba] = useState<AbaCliente>('geral');
  const [erro, setErro] = useState('');
  const [atualizando, setAtualizando] = useState(false);

  const carregar = useCallback(async () => {
    try {
      const [d, l] = await Promise.all([api<ClienteDetalhe>(`/api/clientes/${id}`), api<ListaChamados>(`/api/chamados?cliente=${id}&status=`)]);
      setDados(d); setChamados(l);
    } catch (e) { setErro(e instanceof Error ? e.message : 'Não consegui abrir esse cliente.'); }
    setAtualizando(false);
  }, [id]);
  useEffect(() => { void carregar(); }, [carregar]);

  if (erro) return <Vazio>{erro}</Vazio>;
  if (!dados) return <View style={{ padding: 16, gap: 12 }}>{[130, 50, 110, 110].map((a, i) => <Esqueleto key={i} altura={a} />)}</View>;
  const c = dados.cliente;
  const abas: [AbaCliente, string][] = [['geral', 'Visão geral'], ['chamados', 'Chamados'], ['setores', 'Setores'], ['pessoas', 'Usuários'], ['documentos', 'Documentos'], ['agenda', 'Agenda']];

  return (
    <ScrollView contentContainerStyle={{ padding: 16, gap: 12 }} refreshControl={<RefreshControl refreshing={atualizando} onRefresh={() => { setAtualizando(true); void carregar(); }} colors={[tema.primaria]} />}>
      <Cartao style={{ gap: 12 }}>
        <View style={{ flexDirection: 'row', gap: 14, alignItems: 'center' }}>
          <LogoEmpresa nome={c.nome} logo={c.logo} tamanho={64} />
          <View style={{ flex: 1 }}>
            <T style={{ fontWeight: '800', fontSize: 19 }}>{c.nome}</T>
            {c.contatoNome && <T style={{ color: tema.textoSuave, fontSize: 14 }}>👤 {c.contatoNome}</T>}
            {c.contatoEmail && <T style={{ color: tema.textoSuave, fontSize: 14 }} numberOfLines={1}>✉️ {c.contatoEmail}</T>}
            {c.contatoTelefone && <T style={{ color: tema.textoSuave, fontSize: 14 }}>📞 {c.contatoTelefone}</T>}
          </View>
        </View>
        <BotaoGradiente titulo="＋ Abrir chamado para este cliente" aoTocar={aoNovoChamado} />
      </Cartao>
      <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 8 }}>
        {abas.map(([v, r]) => (
          <Pressable key={v} onPress={() => setAba(v)} accessibilityRole="tab" accessibilityState={{ selected: aba === v }}
            style={{ minHeight: 44, borderRadius: 99, paddingHorizontal: 16, justifyContent: 'center', backgroundColor: aba === v ? tema.primaria : tema.superficie, borderWidth: 1, borderColor: aba === v ? tema.primaria : tema.borda }}>
            <T style={{ fontWeight: '700', fontSize: 14, color: aba === v ? tema.primariaTexto : tema.textoSuave }}>{r}</T>
          </Pressable>
        ))}
      </ScrollView>

      {aba === 'geral' && (
        <>
          <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 10 }}>
            {([['Pendentes', c.pendentes, c.pendentes > 0], ['Urgentes', c.urgentes, c.urgentes > 0], ['Chamados', c.totalChamados, false], ['Documentos', c.documentos, false], ['Treinamentos', c.treinamentos, false], ['KPIs', c.kpis, false]] as const).map(([r, v, alerta]) => (
              <Cartao key={r} style={{ flexBasis: '47%', flexGrow: 1, padding: 14 }}>
                <T style={{ color: tema.textoSuave, fontSize: 13 }}>{r}</T>
                <T style={{ fontSize: 26, fontWeight: '800', color: alerta ? tema.perigo : tema.texto }}>{v}</T>
              </Cartao>
            ))}
          </View>
          <Cartao style={{ gap: 6 }}>
            <T style={{ fontWeight: '800' }}>Cadastro</T>
            <T style={{ fontSize: 14 }}>Situação: {c.status}</T>
            <T style={{ fontSize: 14 }}>Responsável na Prime: {c.responsavelInterno ?? '—'}</T>
            <T style={{ fontSize: 14 }}>CNPJ: {c.cnpj ?? '—'}</T>
            <T style={{ fontSize: 14 }}>Cliente desde: {dataBR(c.criadoEm)}</T>
            {c.observacoes && <T style={{ fontSize: 14, color: tema.textoSuave }}>{c.observacoes}</T>}
          </Cartao>
        </>
      )}
      {aba === 'chamados' && (!chamados?.itens.length ? <Vazio>Nenhum chamado.</Vazio> : chamados.itens.map((ch) => <CartaoChamado key={ch.id} c={ch} equipe aoAbrir={() => aoAbrirChamado(ch.id)} />))}
      {aba === 'setores' && (!dados.setores.length ? <Vazio>Sem setores cadastrados.</Vazio> : dados.setores.map((s) => (
        <Cartao key={s.id}><T style={{ fontWeight: '700' }}>{s.nome}</T><T style={{ color: tema.textoSuave, fontSize: 14 }}>{s.ativo ? 'Ativo' : 'Desativado'} · {s.pendentes} pendente(s)</T></Cartao>
      )))}
      {aba === 'pessoas' && (!dados.pessoas.length ? <Vazio>Nenhum usuário cadastrado.</Vazio> : dados.pessoas.map((p) => (
        <Cartao key={p.id}>
          <T style={{ fontWeight: '700' }}>{p.nome}</T>
          <T style={{ color: tema.textoSuave, fontSize: 14 }}>{p.setor ?? 'Sem setor'}{p.email ? ` · ${p.email}` : ''}</T>
          <T style={{ color: p.portalAtivo ? tema.sucesso : tema.textoSuave, fontSize: 13, fontWeight: '700' }}>{p.portalAtivo ? 'Portal ativo' : 'Sem acesso ao portal'}</T>
        </Cartao>
      )))}
      {aba === 'documentos' && (!dados.documentos.length ? <Vazio>Nenhum documento enviado.</Vazio> : dados.documentos.map((d) => (
        <Cartao key={d.id}><T style={{ fontWeight: '700' }}>📄 {d.titulo}</T><T style={{ color: tema.textoSuave, fontSize: 14 }}>{[d.categoria, d.setor, dataBR(d.criadoEm)].filter(Boolean).join(' · ')}</T></Cartao>
      )))}
      {aba === 'agenda' && (!dados.treinamentos.length ? <Vazio>Nada agendado.</Vazio> : dados.treinamentos.map((t) => (
        <Cartao key={t.id}><T style={{ fontWeight: '700' }}>📅 {t.titulo}</T><T style={{ color: tema.textoSuave, fontSize: 14 }}>{[t.tipo, t.setor, dataBR(t.inicio, true), t.status].filter(Boolean).join(' · ')}</T></Cartao>
      )))}
    </ScrollView>
  );
}

// ---------------------------------------------------------------- Relatórios

interface Bloco { titulo: string | null; colunas: Record<string, unknown>; linhas: (string | number | null)[][] }
interface RespRelatorio {
  catalogo: Record<string, { titulo: string; descricao: string; precisaCliente: boolean; precisaUsuario?: boolean }>;
  clientes: { id: number; nome: string }[]; usuarios: { id: number; nome: string }[];
  relatorio: { titulo: string; descricaoFiltros?: string; blocos?: Bloco[]; erro?: string } | null;
}

export function TelaRelatorios() {
  const { tema } = useTema();
  const [base, setBase] = useState<RespRelatorio | null>(null);
  const [erro, setErro] = useState('');
  const [tipo, setTipo] = useState('');
  const [cliente, setCliente] = useState('');
  const [usuario, setUsuario] = useState('');
  const [rel, setRel] = useState<RespRelatorio['relatorio']>(null);
  const [gerando, setGerando] = useState(false);

  useEffect(() => { relatorioJson<RespRelatorio>({}).then(setBase).catch((e: unknown) => setErro(e instanceof Error ? e.message : 'Não consegui abrir os relatórios.')); }, []);

  async function gerar() {
    setGerando(true); setRel(null);
    const p: Record<string, string> = { relatorio: tipo };
    if (cliente) p.cliente_id = cliente;
    if (usuario) p.usuario_id = usuario;
    try { setRel((await relatorioJson<RespRelatorio>(p)).relatorio); } catch (e) { setRel({ titulo: '', erro: e instanceof Error ? e.message : 'Não deu pra gerar agora.' }); }
    setGerando(false);
  }

  if (erro) return <Vazio>{erro}</Vazio>;
  if (!base) return <View style={{ padding: 16, gap: 12 }}>{[90, 90, 90, 90].map((a, i) => <Esqueleto key={i} altura={a} />)}</View>;
  const info = tipo ? base.catalogo[tipo] : undefined;
  const falta = (info?.precisaCliente && !cliente) || (info?.precisaUsuario && !usuario);

  return (
    <ScrollView contentContainerStyle={{ padding: 16, gap: 12 }}>
      {Object.entries(base.catalogo).map(([k, r]) => (
        <Pressable key={k} onPress={() => { setTipo(k); setRel(null); }} accessibilityRole="radio" accessibilityState={{ selected: tipo === k }}>
          <Cartao style={{ gap: 4, borderColor: tipo === k ? tema.primaria : tema.borda, borderWidth: tipo === k ? 2 : 1 }}>
            <T style={{ fontWeight: '800', fontSize: 16 }}>📊 {r.titulo}</T>
            <T style={{ color: tema.textoSuave, fontSize: 14 }}>{r.descricao}</T>
          </Cartao>
        </Pressable>
      ))}
      {info && (
        <Cartao style={{ gap: 12 }}>
          {info.precisaCliente && <Escolha rotulo="Cliente *" opcoes={base.clientes.map((c) => ({ valor: String(c.id), rotulo: c.nome }))} valor={cliente} aoMudar={setCliente} />}
          {info.precisaUsuario && <Escolha rotulo="Colaborador *" opcoes={base.usuarios.map((u) => ({ valor: String(u.id), rotulo: u.nome }))} valor={usuario} aoMudar={setUsuario} />}
          <BotaoGradiente titulo={gerando ? 'Gerando…' : 'Ver relatório'} aoTocar={() => void gerar()} desativado={gerando || !!falta} />
          <T style={{ color: tema.textoSuave, fontSize: 13 }}>PDF e Excel com o timbrado: pelo site, em Relatórios.</T>
        </Cartao>
      )}
      {gerando && <Esqueleto altura={160} />}
      {rel && (rel.erro ? <Vazio>{rel.erro}</Vazio> : (
        <>
          <T style={{ fontWeight: '800', fontSize: 18 }}>{rel.titulo}</T>
          {rel.descricaoFiltros && <T style={{ color: tema.textoSuave, fontSize: 14 }}>{rel.descricaoFiltros}</T>}
          {(rel.blocos ?? []).filter((b) => b.linhas.length).map((b, i) => {
            const colunas = Object.keys(b.colunas);
            return (
              <Cartao key={i} style={{ gap: 8 }}>
                {b.titulo && <T style={{ fontWeight: '800' }}>{b.titulo}</T>}
                {b.linhas.map((l, j) => (
                  <View key={j} style={{ borderRadius: 12, backgroundColor: tema.superficieAlta, padding: 10, gap: 2 }}>
                    {colunas.map((col, k) => (
                      <View key={col} style={{ flexDirection: 'row', justifyContent: 'space-between', gap: 10 }}>
                        <T style={{ color: tema.textoSuave, fontSize: 13, flexShrink: 0 }}>{col}</T>
                        <T style={{ fontSize: 14, fontWeight: '600', flex: 1, textAlign: 'right' }}>{String(l[k] ?? '—')}</T>
                      </View>
                    ))}
                  </View>
                ))}
              </Cartao>
            );
          })}
        </>
      ))}
    </ScrollView>
  );
}
