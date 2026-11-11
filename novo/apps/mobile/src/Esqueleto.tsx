import { useEffect, useRef } from 'react';
import { Animated, type DimensionValue, View } from 'react-native';
import { useTema } from './tema';

/** Bloco de carregamento pulsando, no formato do conteúdo. */
export function Esqueleto({ altura, largura = '100%', raio = 16 }: { altura: number; largura?: DimensionValue; raio?: number }) {
  const { tema } = useTema();
  const pulso = useRef(new Animated.Value(0.45)).current;
  useEffect(() => {
    const anim = Animated.loop(Animated.sequence([
      Animated.timing(pulso, { toValue: 1, duration: 650, useNativeDriver: true }),
      Animated.timing(pulso, { toValue: 0.45, duration: 650, useNativeDriver: true }),
    ]));
    anim.start();
    return () => anim.stop();
  }, [pulso]);
  return <Animated.View accessibilityElementsHidden importantForAccessibility="no-hide-descendants"
    style={{ height: altura, width: largura, borderRadius: raio, backgroundColor: tema.borda, opacity: pulso }} />;
}

export function EsqueletoPainel() {
  return (
    <View accessible accessibilityLabel="Carregando" style={{ gap: 12 }}>
      <View style={{ flexDirection: 'row', gap: 12 }}><View style={{ flex: 1 }}><Esqueleto altura={96} /></View><View style={{ flex: 1 }}><Esqueleto altura={96} /></View></View>
      <View style={{ flexDirection: 'row', gap: 12 }}><View style={{ flex: 1 }}><Esqueleto altura={96} /></View><View style={{ flex: 1 }}><Esqueleto altura={96} /></View></View>
      <Esqueleto altura={200} />
      <Esqueleto altura={84} /><Esqueleto altura={84} /><Esqueleto altura={84} />
    </View>
  );
}
