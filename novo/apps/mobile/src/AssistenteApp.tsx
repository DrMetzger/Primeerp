import type { UsuarioLogado } from '@primehub/shared';
import * as DocumentPicker from 'expo-document-picker';
import * as FileSystem from 'expo-file-system';
import { useEffect, useRef, useState } from 'react';
import { FlatList, KeyboardAvoidingView, Linking, Platform, Pressable, TextInput, View } from 'react-native';
import { acaoPhp, api, URL_SERVIDOR } from './api';

/** Acha links de download de fonte/patch no texto da resposta. */
function linksDownload(texto: string): { url: string; nome: string }[] {
  const out: { url: string; nome: string }[] = [];
  const re = /\/api\/compilacao\/arquivo\/[a-f0-9]{32}/g;
  let m: RegExpExecArray | null;
  while ((m = re.exec(texto))) out.push({ url: `${URL_SERVIDOR}${m[0]}`, nome: /patch|\.ptm/i.test(texto.slice(Math.max(0, m.index - 40), m.index)) ? 'Baixar patch (.ptm)' : 'Baixar fonte (.prw)' });
  return out;
}
import { type AcaoRobo, RoboNativo } from './RoboNativo';
import { useTema } from './tema';
import { T } from './ui';

interface Acao { id: string; titulo: string; detalhe: string; arquivo: string; campos: Record<string, string>; perigo?: boolean; estado: 'pendente' | 'rodando' | 'feita' | 'cancelada' | 'erro'; aviso?: string }
interface Mensagem { autor: 'pessoa' | 'assistente'; texto: string; acao?: Acao }

const SUGESTOES = {
  equipe: ['Resumo da operação de hoje', 'Quais urgentes estão esperando a gente?', 'Como respondo um chamado com a IA?'],
  cliente: ['Como estão meus chamados?', 'O que é a rejeição 539 da NF-e?', 'Quero abrir um chamado'],
};

/** Conversa com o assistente (o mesmo robô e a mesma IA do site). */
export function AssistenteApp({ usuario, tela }: { usuario: UsuarioLogado; tela?: { rota: string; titulo: string; texto: string } }) {
  const { tema } = useTema();
  const [acao, setAcao] = useState<AcaoRobo>('acenar');
  const [mensagens, setMensagens] = useState<Mensagem[]>([]);
  const [digitando, setDigitando] = useState('');
  const [texto, setTexto] = useState('');
  const [anexos, setAnexos] = useState<{ nome: string; conteudo: string }[]>([]);
  const [pensando, setPensando] = useState(false);
  const lista = useRef<FlatList<Mensagem>>(null);

  /** Anexa um fonte (.prw/.tlpp/.ch/.txt) lido como texto. */
  async function anexar() {
    try {
      const r = await DocumentPicker.getDocumentAsync({ type: '*/*', copyToCacheDirectory: true, multiple: false });
      const f = r.canceled ? undefined : r.assets?.[0];
      if (!f) return;
      if ((f.size ?? 0) > 1024 * 1024) { responder(`O arquivo ${f.name} é grande demais (máx. 1 MB).`); return; }
      const conteudo = await FileSystem.readAsStringAsync(f.uri);
      setAnexos((l) => (l.length >= 5 ? l : [...l, { nome: (f.name || 'fonte.prw').slice(0, 120), conteudo }]));
    } catch { responder('Não consegui ler esse arquivo.'); }
  }

  function mudarAcao(id: string, mudanca: Partial<Acao>) {
    setMensagens((m) => m.map((x) => (x.acao?.id === id ? { ...x, acao: { ...x.acao, ...mudanca } } : x)));
  }

  /** Ação proposta pela IA: só roda com o toque em Confirmar. */
  async function confirmar(a: Acao) {
    mudarAcao(a.id, { estado: 'rodando' });
    setAcao('anotar');
    try {
      const r = await acaoPhp<{ ok: boolean; id?: number | null }>(a.arquivo, a.campos);
      mudarAcao(a.id, { estado: 'feita', aviso: `Feito!${r.id ? ` Chamado #${r.id} criado.` : ''}` });
      setAcao('comemorar');
    } catch (e) {
      mudarAcao(a.id, { estado: 'erro', aviso: e instanceof Error ? e.message : 'Não deu certo.' });
      setAcao('parado');
    }
  }

  function responder(resposta: string, acoes: Acao[] = []) {
    setAcao('falar');
    let i = 0;
    const passo = () => {
      i = Math.min(resposta.length, i + 3);
      setDigitando(resposta.slice(0, i));
      if (i < resposta.length) setTimeout(passo, 22);
      else {
        setDigitando('');
        setMensagens((m) => [...m, { autor: 'assistente', texto: resposta }, ...acoes.map((a) => ({ autor: 'assistente' as const, texto: '', acao: a }))]);
        setAcao('sorrir');
        setTimeout(() => setAcao('parado'), 1800);
      }
    };
    passo();
  }

  useEffect(() => {
    const t = setTimeout(() => responder(`Oi, ${usuario.nome.split(' ')[0]}! Sou o assistente do Prime Hub. Em que posso te ajudar?`), 900);
    return () => clearTimeout(t);
  }, [usuario.nome]);

  async function enviar(pergunta: string) {
    const p = pergunta.trim();
    if ((!p && !anexos.length) || pensando || digitando) return;
    setTexto('');
    const enviados = anexos;
    setAnexos([]);
    const historico = mensagens;
    const rotulo = enviados.length ? `${p}${p ? '\n' : ''}📎 ${enviados.map((a) => a.nome).join(', ')}` : p;
    setMensagens((m) => [...m, { autor: 'pessoa', texto: rotulo }]);
    setPensando(true);
    setAcao('anotar');
    try {
      const r = await api<{ texto?: string; erro?: string; acoes?: Omit<Acao, 'estado'>[] }>('/api/assistente', { method: 'POST', body: JSON.stringify({ mensagem: p || 'Segue o arquivo em anexo.', historico: historico.filter((m) => !m.acao), ...(enviados.length ? { anexos: enviados } : {}), ...(tela ? { tela } : {}) }) });
      setPensando(false);
      responder(r.texto ?? r.erro ?? 'Não consegui responder agora.', (r.acoes ?? []).map((a) => ({ ...a, estado: 'pendente' as const })));
    } catch {
      setPensando(false);
      responder('Fiquei sem conexão. Tenta de novo?');
    }
  }

  const itens = [...mensagens, ...(digitando ? [{ autor: 'assistente' as const, texto: `${digitando}▍` }] : []), ...(pensando ? [{ autor: 'assistente' as const, texto: '…' }] : [])];

  return (
    <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <View style={{ alignItems: 'center', paddingTop: 6 }}>
        <Pressable onPress={() => setAcao('comemorar')} onPressOut={() => setTimeout(() => setAcao('parado'), 1500)} accessibilityRole="button" accessibilityLabel="Robô assistente">
          <RoboNativo altura={172} acao={acao} />
        </Pressable>
      </View>
      <FlatList ref={lista} data={itens} keyExtractor={(_, i) => String(i)} contentContainerStyle={{ padding: 16, gap: 8 }}
        onContentSizeChange={() => lista.current?.scrollToEnd({ animated: true })}
        renderItem={({ item }) => item.acao ? (
          <View style={{ alignSelf: 'stretch', borderRadius: 18, padding: 14, gap: 8, borderWidth: 1, borderColor: item.acao.perigo ? tema.perigo : tema.primaria, backgroundColor: tema.superficie }}>
            <T style={{ fontWeight: '800' }}>⚡ {item.acao.titulo}</T>
            <T style={{ color: tema.textoSuave, fontSize: 14 }}>{item.acao.detalhe}</T>
            {item.acao.estado === 'pendente' && (
              <View style={{ flexDirection: 'row', gap: 8 }}>
                <Pressable onPress={() => item.acao && void confirmar(item.acao)} accessibilityRole="button" style={{ flex: 1, minHeight: 48, borderRadius: 14, alignItems: 'center', justifyContent: 'center', backgroundColor: item.acao.perigo ? tema.perigo : tema.primaria }}>
                  <T style={{ color: '#FFFFFF', fontWeight: '800' }}>Confirmar</T>
                </Pressable>
                <Pressable onPress={() => item.acao && mudarAcao(item.acao.id, { estado: 'cancelada', aviso: 'Cancelado.' })} accessibilityRole="button" style={{ minHeight: 48, paddingHorizontal: 16, borderRadius: 14, alignItems: 'center', justifyContent: 'center', borderWidth: 1, borderColor: tema.borda }}>
                  <T>Cancelar</T>
                </Pressable>
              </View>
            )}
            {item.acao.estado === 'rodando' && <T style={{ color: tema.textoSuave }}>Fazendo…</T>}
            {!!item.acao.aviso && <T style={{ fontWeight: '700', color: item.acao.estado === 'feita' ? tema.sucesso : item.acao.estado === 'erro' ? tema.perigo : tema.textoSuave }}>{item.acao.aviso}</T>}
          </View>
        ) : (
          <View style={{ alignSelf: item.autor === 'pessoa' ? 'flex-end' : 'flex-start', maxWidth: '85%', borderRadius: 18, paddingHorizontal: 14, paddingVertical: 10, gap: 8,
            backgroundColor: item.autor === 'pessoa' ? tema.primaria : tema.superficie, borderWidth: item.autor === 'pessoa' ? 0 : 1, borderColor: tema.borda }}>
            <T style={{ color: item.autor === 'pessoa' ? tema.primariaTexto : tema.texto, fontSize: 16, lineHeight: 22 }}>{item.texto}</T>
            {item.autor === 'assistente' && linksDownload(item.texto).map((l) => (
              <Pressable key={l.url} onPress={() => void Linking.openURL(l.url)} accessibilityRole="button"
                style={{ minHeight: 44, borderRadius: 12, backgroundColor: tema.primaria, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 14 }}>
                <T style={{ color: tema.primariaTexto, fontWeight: '800' }}>⬇ {l.nome}</T>
              </Pressable>
            ))}
          </View>
        )}
        ListFooterComponent={mensagens.length <= 1 && !digitando ? (
          <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginTop: 8 }}>
            {SUGESTOES[usuario.tipo].map((s) => (
              <Pressable key={s} onPress={() => void enviar(s)} accessibilityRole="button"
                style={{ minHeight: 44, borderRadius: 99, borderWidth: 1, borderColor: tema.borda, backgroundColor: tema.superficie, paddingHorizontal: 14, justifyContent: 'center' }}>
                <T style={{ fontSize: 14 }}>{s}</T>
              </Pressable>
            ))}
          </View>
        ) : undefined} />
      <View style={{ borderTopWidth: 1, borderColor: tema.borda, backgroundColor: tema.superficie }}>
        {anexos.length > 0 && (
          <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 6, paddingHorizontal: 12, paddingTop: 10 }}>
            {anexos.map((a, i) => (
              <View key={`${a.nome}-${i}`} style={{ flexDirection: 'row', alignItems: 'center', gap: 6, borderRadius: 10, backgroundColor: tema.fundo, borderWidth: 1, borderColor: tema.borda, paddingHorizontal: 8, paddingVertical: 5 }}>
                <T style={{ fontSize: 13, maxWidth: 160 }} numberOfLines={1}>📎 {a.nome}</T>
                <Pressable onPress={() => setAnexos((l) => l.filter((_, j) => j !== i))} accessibilityRole="button" accessibilityLabel={`Remover ${a.nome}`}><T style={{ color: tema.perigo, fontWeight: '800' }}>✕</T></Pressable>
              </View>
            ))}
          </View>
        )}
        <View style={{ flexDirection: 'row', gap: 8, padding: 12 }}>
          <Pressable onPress={() => void anexar()} accessibilityRole="button" accessibilityLabel="Anexar fonte"
            style={{ minWidth: 50, minHeight: 50, borderRadius: 14, borderWidth: 1, borderColor: tema.borda, backgroundColor: tema.fundo, alignItems: 'center', justifyContent: 'center' }}>
            <T style={{ fontSize: 20 }}>📎</T>
          </Pressable>
          <TextInput value={texto} onChangeText={setTexto} placeholder="Pergunte ou anexe um fonte…" placeholderTextColor={tema.textoSuave} onFocus={() => setAcao('sorrir')}
            onSubmitEditing={() => void enviar(texto)} returnKeyType="send" maxFontSizeMultiplier={1.6} accessibilityLabel="Sua pergunta"
            style={{ flex: 1, minHeight: 50, borderRadius: 14, borderWidth: 1, borderColor: tema.borda, backgroundColor: tema.fundo, color: tema.texto, paddingHorizontal: 14, fontSize: 16 }} />
          <Pressable onPress={() => void enviar(texto)} disabled={(!texto.trim() && !anexos.length) || pensando || !!digitando} accessibilityRole="button" accessibilityLabel="Enviar"
            style={{ minWidth: 54, minHeight: 50, borderRadius: 14, backgroundColor: tema.primaria, alignItems: 'center', justifyContent: 'center', opacity: (!texto.trim() && !anexos.length) ? 0.5 : 1 }}>
            <T style={{ color: tema.primariaTexto, fontSize: 20, fontWeight: '800' }}>➤</T>
          </Pressable>
        </View>
      </View>
    </KeyboardAvoidingView>
  );
}
