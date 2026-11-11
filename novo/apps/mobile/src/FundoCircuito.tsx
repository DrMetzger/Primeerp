import { svgTextura, TAMANHO_TEXTURA } from '@primehub/shared';
import { LinearGradient } from 'expo-linear-gradient';
import { memo, type ReactNode, useMemo } from 'react';
import { StyleSheet, useWindowDimensions, View } from 'react-native';
import { SvgXml } from 'react-native-svg';
import { useTema } from './tema';

/** Fundo da marca: degradê + placa de circuito (a mesma textura do site). */
export const FundoCircuito = memo(function FundoCircuito({ children }: { children: ReactNode }) {
  const { tema, nome } = useTema();
  const { width, height } = useWindowDimensions();
  const xml = useMemo(() => svgTextura(nome === 'escuro' ? '#2FD3E5' : '#1E3FD8'), [nome]);
  const lado = TAMANHO_TEXTURA * 0.8;
  const colunas = Math.ceil(width / lado);
  const linhas = Math.ceil(height / lado);

  return (
    <View style={{ flex: 1, backgroundColor: tema.fundo }}>
      <LinearGradient
        colors={nome === 'escuro' ? ['#070B1C', '#0B1640', '#06222C'] : ['#F4F7FC', '#E8EFFC', '#E3F6F8']}
        start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={StyleSheet.absoluteFill} />
      <View pointerEvents="none" style={[StyleSheet.absoluteFill, { opacity: nome === 'escuro' ? 0.16 : 0.12 }]}
        accessibilityElementsHidden importantForAccessibility="no-hide-descendants">
        {Array.from({ length: linhas }, (_, l) => (
          <View key={l} style={{ flexDirection: 'row' }}>
            {Array.from({ length: colunas }, (_, c) => <SvgXml key={c} xml={xml} width={lado} height={lado} />)}
          </View>
        ))}
      </View>
      {children}
    </View>
  );
});
