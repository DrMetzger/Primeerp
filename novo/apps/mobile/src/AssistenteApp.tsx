import type { UsuarioLogado } from '@primehub/shared';
import { useEffect, useRef, useState } from 'react';
import { FlatList, KeyboardAvoidingView, Platform, Pressable, TextInput, View } from 'react-native';
import { api } from './api';
import { type AcaoRobo, RoboNativo } from './RoboNativo';
import { useTema } from './tema';
import { T } from './ui';

interface Mensagem { autor: 'pessoa' | 'assistente'; texto: string }

const SUGESTOES = {
  equipe: ['Como respondo um chamado com a IA?', 'Onde vejo os chamados aguardando nós?', 'Como cadastro um cliente novo?'],
  cliente: ['Como abro um chamado?', 'Onde acompanho meus chamados?', 'Como anexo um print?'],
};

/** Conversa com o assistente (o mesmo robô e a mesma IA do site). */
export function AssistenteApp({ usuario }: { usuario: UsuarioLogado }) {
  const { tema } = useTema();
  const [acao, setAcao] = useState<AcaoRobo>('acenar');
  const [mensagens, setMensagens] = useState<Mensagem[]>([]);
  const [digitando, setDigitando] = useState('');
  const [texto, setTexto] = useState('');
  const [pensando, setPensando] = useState(false);
  const lista = useRef<FlatList<Mensagem>>(null);

  function responder(resposta: string) {
    setAcao('falar');
    let i = 0;
    const passo = () => {
      i = Math.min(resposta.length, i + 3);
      setDigitando(resposta.slice(0, i));
      if (i < resposta.length) setTimeout(passo, 22);
      else {
        setDigitando('');
        setMensagens((m) => [...m, { autor: 'assistente', texto: resposta }]);
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
    if (!p || pensando || digitando) return;
    setTexto('');
    const historico = mensagens;
    setMensagens((m) => [...m, { autor: 'pessoa', texto: p }]);
    setPensando(true);
    setAcao('anotar');
    try {
      const r = await api<{ texto?: string; erro?: string }>('/api/assistente', { method: 'POST', body: JSON.stringify({ mensagem: p, historico }) });
      setPensando(false);
      responder(r.texto ?? r.erro ?? 'Não consegui responder agora.');
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
          <RoboNativo altura={210} acao={acao} />
        </Pressable>
      </View>
      <FlatList ref={lista} data={itens} keyExtractor={(_, i) => String(i)} contentContainerStyle={{ padding: 16, gap: 8 }}
        onContentSizeChange={() => lista.current?.scrollToEnd({ animated: true })}
        renderItem={({ item }) => (
          <View style={{ alignSelf: item.autor === 'pessoa' ? 'flex-end' : 'flex-start', maxWidth: '85%', borderRadius: 18, paddingHorizontal: 14, paddingVertical: 10,
            backgroundColor: item.autor === 'pessoa' ? tema.primaria : tema.superficie, borderWidth: item.autor === 'pessoa' ? 0 : 1, borderColor: tema.borda }}>
            <T style={{ color: item.autor === 'pessoa' ? tema.primariaTexto : tema.texto, fontSize: 16, lineHeight: 22 }}>{item.texto}</T>
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
      <View style={{ flexDirection: 'row', gap: 8, padding: 12, borderTopWidth: 1, borderColor: tema.borda, backgroundColor: tema.superficie }}>
        <TextInput value={texto} onChangeText={setTexto} placeholder="Pergunte ao assistente…" placeholderTextColor={tema.textoSuave} onFocus={() => setAcao('sorrir')}
          onSubmitEditing={() => void enviar(texto)} returnKeyType="send" maxFontSizeMultiplier={1.6} accessibilityLabel="Sua pergunta"
          style={{ flex: 1, minHeight: 50, borderRadius: 14, borderWidth: 1, borderColor: tema.borda, backgroundColor: tema.fundo, color: tema.texto, paddingHorizontal: 14, fontSize: 16 }} />
        <Pressable onPress={() => void enviar(texto)} disabled={!texto.trim() || pensando || !!digitando} accessibilityRole="button" accessibilityLabel="Enviar"
          style={{ minWidth: 54, minHeight: 50, borderRadius: 14, backgroundColor: tema.primaria, alignItems: 'center', justifyContent: 'center', opacity: !texto.trim() ? 0.5 : 1 }}>
          <T style={{ color: tema.primariaTexto, fontSize: 20, fontWeight: '800' }}>➤</T>
        </Pressable>
      </View>
    </KeyboardAvoidingView>
  );
}
