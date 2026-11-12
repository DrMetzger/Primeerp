import type { ReactNode } from 'react';
import { Pressable, type PressableProps, StyleSheet, Text, type TextProps, View, type ViewStyle } from 'react-native';
import { useTema } from './tema';
import { escolherDaGaleria, escolherDocumentos, tamanhoLegivel, tirarFoto, type ArquivoEscolhido } from './arquivos';

/** Texto que respeita o tamanho de fonte do Android (até 1,8x sem quebrar layout). */
export function T({ style, ...props }: TextProps) {
  const { tema } = useTema();
  return <Text maxFontSizeMultiplier={1.8} {...props} style={[{ color: tema.texto, fontSize: 16 }, style]} />;
}

export function Cartao({ children, style }: { children: ReactNode; style?: ViewStyle }) {
  const { tema } = useTema();
  return <View style={[{ backgroundColor: tema.superficie, borderColor: tema.borda, borderWidth: StyleSheet.hairlineWidth, borderRadius: 20, padding: 16 }, style]}>{children}</View>;
}

/** Botão com alvo de toque de no mínimo 48dp. */
export function Botao({ titulo, primario = false, style, ...props }: PressableProps & { titulo: string; primario?: boolean; style?: ViewStyle }) {
  const { tema } = useTema();
  return (
    <Pressable accessibilityRole="button" hitSlop={6} {...props}
      style={({ pressed }) => [{
        minHeight: 52, borderRadius: 16, paddingHorizontal: 18, alignItems: 'center', justifyContent: 'center',
        backgroundColor: primario ? tema.primaria : tema.superficieAlta, opacity: pressed ? 0.8 : props.disabled ? 0.6 : 1,
      }, style]}>
      <T style={{ color: primario ? tema.primariaTexto : tema.texto, fontWeight: '700', fontSize: 17 }}>{titulo}</T>
    </Pressable>
  );
}

// ---------------------------------------------------------------- Anexar arquivos


/** Botões 📎 Arquivo / 🖼️ Galeria / 📷 Câmera + lista do que foi escolhido (com remover). */
export function SeletorAnexos({ arquivos, aoMudar, somenteImagem = false, varios = true, tipos, rotulo = 'Anexos' }: {
  arquivos: ArquivoEscolhido[]; aoMudar: (a: ArquivoEscolhido[]) => void; somenteImagem?: boolean; varios?: boolean; tipos?: string[]; rotulo?: string;
}) {
  const { tema } = useTema();
  const somar = (novos: ArquivoEscolhido[]) => { if (novos.length) aoMudar(varios ? [...arquivos, ...novos] : novos.slice(0, 1)); };
  const botoes: [string, () => Promise<ArquivoEscolhido[]>][] = [
    ...(somenteImagem ? [] : [['📎 Arquivo', () => escolherDocumentos(tipos, varios)] as [string, () => Promise<ArquivoEscolhido[]>]]),
    ...(tipos ? [] : [['🖼️ Galeria', () => escolherDaGaleria(somenteImagem, varios)] as [string, () => Promise<ArquivoEscolhido[]>], ['📷 Câmera', tirarFoto] as [string, () => Promise<ArquivoEscolhido[]>]]),
  ];
  return (
    <View style={{ gap: 8 }}>
      {!!rotulo && <T style={{ fontWeight: '700', fontSize: 15 }}>{rotulo}</T>}
      <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8 }}>
        {botoes.map(([r, f]) => (
          <Pressable key={r} onPress={() => { void f().then(somar).catch(() => undefined); }} accessibilityRole="button"
            style={({ pressed }) => ({ minHeight: 44, paddingHorizontal: 14, borderRadius: 12, borderWidth: 1, borderStyle: 'dashed', borderColor: tema.primaria, justifyContent: 'center', opacity: pressed ? 0.7 : 1 })}>
            <T style={{ color: tema.primaria, fontWeight: '700', fontSize: 14 }}>{r}</T>
          </Pressable>
        ))}
      </View>
      {arquivos.map((a, i) => (
        <View key={`${a.uri}${i}`} style={{ flexDirection: 'row', alignItems: 'center', gap: 8, padding: 10, borderRadius: 12, backgroundColor: tema.superficieAlta }}>
          <T style={{ fontSize: 18 }}>{a.type.startsWith('image/') ? '🖼️' : a.type.startsWith('video/') ? '🎬' : a.type.includes('pdf') ? '📕' : '📄'}</T>
          <T style={{ flex: 1, fontSize: 14 }} numberOfLines={1}>{a.name}</T>
          <T style={{ color: tema.textoSuave, fontSize: 12 }}>{tamanhoLegivel(a.tamanho)}</T>
          <Pressable onPress={() => aoMudar(arquivos.filter((_, j) => j !== i))} hitSlop={8} accessibilityRole="button" accessibilityLabel={`Tirar ${a.name}`}>
            <T style={{ color: tema.perigo, fontWeight: '800', fontSize: 18 }}>✕</T>
          </Pressable>
        </View>
      ))}
    </View>
  );
}
