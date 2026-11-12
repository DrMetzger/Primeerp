import DateTimePicker from '@react-native-community/datetimepicker';
import { type ReactNode, useState } from 'react';
import { Alert, KeyboardAvoidingView, Modal, Platform, Pressable, ScrollView, View } from 'react-native';
import { useTema } from './tema';
import { T } from './ui';

/** Folha que sobe de baixo com um formulário (no lugar dos modais do site). */
export function Folha({ aberta, titulo, aoFechar, children, rodape }: { aberta: boolean; titulo: string; aoFechar: () => void; children: ReactNode; rodape?: ReactNode }) {
  const { tema } = useTema();
  return (
    <Modal visible={aberta} animationType="slide" transparent onRequestClose={aoFechar} statusBarTranslucent>
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : 'height'} style={{ flex: 1, justifyContent: 'flex-end', backgroundColor: 'rgba(7,11,28,0.55)' }}>
        <Pressable style={{ flex: 1 }} onPress={aoFechar} accessibilityLabel="Fechar" />
        <View style={{ maxHeight: '92%', backgroundColor: tema.fundo, borderTopLeftRadius: 26, borderTopRightRadius: 26, paddingTop: 10 }}>
          <View style={{ alignSelf: 'center', width: 44, height: 5, borderRadius: 3, backgroundColor: tema.borda, marginBottom: 8 }} />
          <View style={{ flexDirection: 'row', alignItems: 'center', paddingHorizontal: 18, paddingBottom: 8 }}>
            <T style={{ flex: 1, fontSize: 20, fontWeight: '800' }}>{titulo}</T>
            <Pressable onPress={aoFechar} hitSlop={10} accessibilityRole="button" accessibilityLabel="Fechar"
              style={{ width: 40, height: 40, borderRadius: 20, alignItems: 'center', justifyContent: 'center', backgroundColor: tema.superficieAlta }}>
              <T style={{ fontSize: 18, fontWeight: '800' }}>✕</T>
            </Pressable>
          </View>
          <ScrollView contentContainerStyle={{ padding: 18, paddingTop: 6, gap: 14 }} keyboardShouldPersistTaps="handled">
            {children}
          </ScrollView>
          {rodape && <View style={{ padding: 16, paddingBottom: 28, gap: 10, borderTopWidth: 1, borderTopColor: tema.borda }}>{rodape}</View>}
        </View>
      </KeyboardAvoidingView>
    </Modal>
  );
}

/** Pergunta antes de apagar/tirar acesso. */
export function confirmar(titulo: string, mensagem: string, rotuloSim = 'Sim', perigo = true): Promise<boolean> {
  return new Promise((ok) => {
    Alert.alert(titulo, mensagem, [
      { text: 'Cancelar', style: 'cancel', onPress: () => ok(false) },
      { text: rotuloSim, style: perigo ? 'destructive' : 'default', onPress: () => ok(true) },
    ], { cancelable: true, onDismiss: () => ok(false) });
  });
}

const doisDig = (n: number) => String(n).padStart(2, '0');
/** "AAAA-MM-DD" e "HH:MM" (o que o servidor espera). */
export const paraData = (d: Date) => `${d.getFullYear()}-${doisDig(d.getMonth() + 1)}-${doisDig(d.getDate())}`;
export const paraHora = (d: Date) => `${doisDig(d.getHours())}:${doisDig(d.getMinutes())}`;

/** Data e hora com o calendário/relógio do próprio Android. */
export function CampoDataHora({ rotulo, data, hora, aoMudar, semHora = false, opcional = false }: {
  rotulo: string; data: string; hora: string; aoMudar: (data: string, hora: string) => void; semHora?: boolean; opcional?: boolean;
}) {
  const { tema } = useTema();
  const [abrir, setAbrir] = useState<'date' | 'time' | null>(null);
  const atual = data ? new Date(`${data}T${hora || '09:00'}:00`) : new Date();
  const caixa = (texto: string, modo: 'date' | 'time', vazio: string) => (
    <Pressable onPress={() => setAbrir(modo)} accessibilityRole="button" accessibilityLabel={`${rotulo}: ${texto || vazio}`}
      style={{ flex: modo === 'date' ? 1.4 : 1, minHeight: 50, borderRadius: 14, borderWidth: 1, borderColor: tema.borda, backgroundColor: tema.superficie, paddingHorizontal: 14, flexDirection: 'row', alignItems: 'center', gap: 8 }}>
      <T style={{ fontSize: 18 }}>{modo === 'date' ? '📅' : '🕘'}</T>
      <T style={{ color: texto ? tema.texto : tema.textoSuave }}>{texto || vazio}</T>
    </Pressable>
  );
  const dataBr = data ? data.split('-').reverse().join('/') : '';
  return (
    <View style={{ gap: 6 }}>
      <T style={{ fontWeight: '700', fontSize: 14 }}>{rotulo}</T>
      <View style={{ flexDirection: 'row', gap: 8 }}>
        {caixa(dataBr, 'date', 'Escolher dia')}
        {!semHora && caixa(hora, 'time', 'Hora')}
        {opcional && !!data && (
          <Pressable onPress={() => aoMudar('', '')} accessibilityRole="button" accessibilityLabel="Limpar" style={{ width: 44, alignItems: 'center', justifyContent: 'center' }}>
            <T style={{ color: tema.perigo, fontWeight: '800' }}>✕</T>
          </Pressable>
        )}
      </View>
      {abrir && (
        <DateTimePicker value={atual} mode={abrir} is24Hour display="default"
          onChange={(ev, d) => {
            setAbrir(null);
            if (ev.type !== 'set' || !d) return;
            if (abrir === 'date') aoMudar(paraData(d), hora || (semHora ? '' : '09:00'));
            else aoMudar(data || paraData(new Date()), paraHora(d));
          }} />
      )}
    </View>
  );
}

/** Linha com ações pequenas (editar, excluir...) embaixo de um item. */
export function Acoes({ itens }: { itens: { rotulo: string; aoTocar: () => void; perigo?: boolean }[] }) {
  const { tema } = useTema();
  return (
    <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8 }}>
      {itens.map((a) => (
        <Pressable key={a.rotulo} onPress={a.aoTocar} accessibilityRole="button" hitSlop={4}
          style={({ pressed }) => ({ minHeight: 40, paddingHorizontal: 12, borderRadius: 12, justifyContent: 'center', backgroundColor: a.perigo ? `${tema.perigo}1A` : tema.superficieAlta, opacity: pressed ? 0.7 : 1 })}>
          <T style={{ fontSize: 14, fontWeight: '700', color: a.perigo ? tema.perigo : tema.texto }}>{a.rotulo}</T>
        </Pressable>
      ))}
    </View>
  );
}

/** Aviso de sucesso/erro dentro das telas. */
export function Aviso({ texto, erro = false }: { texto: string; erro?: boolean }) {
  const { tema } = useTema();
  if (!texto) return null;
  return (
    <View style={{ borderRadius: 14, padding: 12, backgroundColor: erro ? `${tema.perigo}1A` : `${tema.sucesso}1A`, borderWidth: 1, borderColor: erro ? tema.perigo : tema.sucesso }}>
      <T style={{ fontSize: 14, color: erro ? tema.perigo : tema.texto }}>{erro ? '⚠️ ' : '✅ '}{texto}</T>
    </View>
  );
}
