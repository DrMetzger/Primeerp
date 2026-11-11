import { marca } from '@primehub/shared';
import { LinearGradient } from 'expo-linear-gradient';
import { useEffect, useMemo, useRef } from 'react';
import { AccessibilityInfo, Animated, Easing, Image, StyleSheet, Text, useWindowDimensions, View } from 'react-native';
import Svg, { Circle, Defs, Line, RadialGradient, Stop } from 'react-native-svg';

const PARTICULAS = 18;
const NOS = 9;

/**
 * Abertura com cara de IA: rede neural pulsando, partículas sendo puxadas
 * pro "P" da PrimeERP e um brilho respirando. Fica na tela enquanto o app
 * confere a versão e o login (mensagem embaixo muda conforme o passo).
 */
export function AberturaIA({ mensagem }: { mensagem: string }) {
  const { width, height } = useWindowDimensions();
  const t = useRef(new Animated.Value(0)).current;
  const giro = useRef(new Animated.Value(0)).current;
  const respiro = useRef(new Animated.Value(0)).current;
  const entrada = useRef(new Animated.Value(0)).current;

  useEffect(() => {
    let reduzir = false;
    void AccessibilityInfo.isReduceMotionEnabled().then((r) => { reduzir = r; });
    const animacoes = [
      Animated.timing(entrada, { toValue: 1, duration: 900, easing: Easing.out(Easing.back(1.6)), useNativeDriver: true }),
      Animated.loop(Animated.timing(t, { toValue: 1, duration: 2200, easing: Easing.in(Easing.cubic), useNativeDriver: true })),
      Animated.loop(Animated.timing(giro, { toValue: 1, duration: 14000, easing: Easing.linear, useNativeDriver: true })),
      Animated.loop(Animated.sequence([
        Animated.timing(respiro, { toValue: 1, duration: 1100, easing: Easing.inOut(Easing.sin), useNativeDriver: true }),
        Animated.timing(respiro, { toValue: 0, duration: 1100, easing: Easing.inOut(Easing.sin), useNativeDriver: true }),
      ])),
    ];
    setTimeout(() => { if (!reduzir) animacoes.forEach((a) => a.start()); else entrada.setValue(1); }, 0);
    return () => animacoes.forEach((a) => a.stop());
  }, [t, giro, respiro, entrada]);

  const raio = Math.min(width, height) * 0.42;

  // Rede neural: nós num círculo + ligações entre vizinhos e com o centro.
  const nos = useMemo(() => Array.from({ length: NOS }, (_, i) => {
    const a = (i / NOS) * Math.PI * 2;
    const r = raio * (0.62 + ((i * 37) % 10) / 40);
    return { x: raio + Math.cos(a) * r, y: raio + Math.sin(a) * r };
  }), [raio]);

  // Partículas em espiral sendo sugadas pro centro (cada uma com fase própria).
  const particulas = useMemo(() => Array.from({ length: PARTICULAS }, (_, i) => {
    const fase = i / PARTICULAS;
    const angulo = fase * Math.PI * 2;
    const p = Animated.modulo(Animated.add(t, fase), 1);
    const distancia = p.interpolate({ inputRange: [0, 1], outputRange: [raio * 1.05, 0] });
    return {
      chave: i,
      cor: i % 3 === 0 ? marca.turquesaClaro : i % 3 === 1 ? marca.turquesa : '#7C9BFF',
      tamanho: 3 + (i % 4),
      x: Animated.multiply(distancia, Math.cos(angulo)),
      y: Animated.multiply(distancia, Math.sin(angulo)),
      opacidade: p.interpolate({ inputRange: [0, 0.15, 0.85, 1], outputRange: [0, 1, 1, 0] }),
    };
  }), [t, raio]);

  const rotacao = giro.interpolate({ inputRange: [0, 1], outputRange: ['0deg', '360deg'] });
  const brilho = respiro.interpolate({ inputRange: [0, 1], outputRange: [0.35, 0.9] });
  const escala = respiro.interpolate({ inputRange: [0, 1], outputRange: [1, 1.08] });

  return (
    <LinearGradient colors={['#050817', '#0B1B6F', '#05303A']} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={estilos.tela}
      accessible accessibilityRole="progressbar" accessibilityLabel={`Prime Hub. ${mensagem}`}>
      <View style={{ width: raio * 2, height: raio * 2, alignItems: 'center', justifyContent: 'center' }}>
        <Animated.View style={[StyleSheet.absoluteFill, { transform: [{ rotate: rotacao }], opacity: brilho }]}>
          <Svg width={raio * 2} height={raio * 2}>
            <Defs>
              <RadialGradient id="no" cx="50%" cy="50%" r="50%">
                <Stop offset="0" stopColor={marca.turquesaClaro} stopOpacity="1" />
                <Stop offset="1" stopColor={marca.turquesa} stopOpacity="0" />
              </RadialGradient>
            </Defs>
            {nos.map((n, i) => {
              const prox = nos[(i + 2) % NOS] ?? n;
              return [
                <Line key={`c${i}`} x1={raio} y1={raio} x2={n.x} y2={n.y} stroke="#3C5BFF" strokeOpacity={0.35} strokeWidth={1} />,
                <Line key={`v${i}`} x1={n.x} y1={n.y} x2={prox.x} y2={prox.y} stroke={marca.turquesa} strokeOpacity={0.45} strokeWidth={1} />,
                <Circle key={`n${i}`} cx={n.x} cy={n.y} r={9} fill="url(#no)" />,
                <Circle key={`p${i}`} cx={n.x} cy={n.y} r={2.5} fill="#E6FBFF" />,
              ];
            })}
          </Svg>
        </Animated.View>

        {particulas.map((p) => (
          <Animated.View key={p.chave} style={{
            position: 'absolute', width: p.tamanho, height: p.tamanho, borderRadius: p.tamanho, backgroundColor: p.cor,
            opacity: p.opacidade, transform: [{ translateX: p.x }, { translateY: p.y }],
            shadowColor: p.cor, shadowOpacity: 1, shadowRadius: 6, elevation: 4,
          }} />
        ))}

        <Animated.View style={[estilos.halo, { opacity: brilho, transform: [{ scale: escala }] }]} />
        <Animated.View style={{ transform: [{ scale: Animated.multiply(entrada, escala) }], opacity: entrada }}>
          <Image source={require('../assets/icone-p.png')} style={{ width: 92, height: 110 }} resizeMode="contain" accessibilityIgnoresInvertColors />
        </Animated.View>
      </View>
      <Animated.View style={{ opacity: entrada, alignItems: 'center', marginTop: 28 }}>
        <Text style={estilos.titulo} maxFontSizeMultiplier={1.6}>Prime Hub</Text>
        <Text style={estilos.mensagem} maxFontSizeMultiplier={1.8}>{mensagem}</Text>
      </Animated.View>
    </LinearGradient>
  );
}

const estilos = StyleSheet.create({
  tela: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 24 },
  halo: { position: 'absolute', width: 170, height: 170, borderRadius: 85, backgroundColor: marca.turquesa, opacity: 0.4, shadowColor: marca.turquesaClaro, shadowRadius: 40, shadowOpacity: 1 },
  titulo: { color: '#FFFFFF', fontSize: 28, fontWeight: '800', letterSpacing: 0.5 },
  mensagem: { color: '#A9F3FA', fontSize: 15, marginTop: 6, textAlign: 'center' },
});
