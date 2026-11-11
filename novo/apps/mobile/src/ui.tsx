import type { ReactNode } from 'react';
import { Pressable, type PressableProps, StyleSheet, Text, type TextProps, View, type ViewStyle } from 'react-native';
import { useTema } from './tema';

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
