import { useEffect, useRef, useState } from 'react';
import { AccessibilityInfo, Animated, Easing, Image, type ImageSourcePropType, View } from 'react-native';

/**
 * O mesmo robô assistente do site, montado com as mesmas peças e posições
 * (unidades de cena = pixels das peças originais), animado com Animated:
 * flutua, pisca, mexe a cabeça, acena, fala, sorri, pensa e anota.
 */

export type AcaoRobo = 'parado' | 'acenar' | 'falar' | 'sorrir' | 'pensar' | 'anotar' | 'comemorar';

const CENA = { largura: 700, altura: 1130 };
const P = {
  tronco: require('../assets/robo/tronco.webp'),
  capacete: require('../assets/robo/capacete.webp'),
  base: require('../assets/robo/base.webp'),
  jato: require('../assets/robo/jato.webp'),
  prancheta: require('../assets/robo/prancheta.webp'),
  bracoRelaxado: require('../assets/robo/braco-relaxado.webp'),
  bracoAberto: require('../assets/robo/braco-aberto.webp'),
  bracoCaneta: require('../assets/robo/braco-caneta.webp'),
  bracoCurvado: require('../assets/robo/braco-curvado.webp'),
  olhos: {
    aberto: [require('../assets/robo/olho-aberto-e.webp'), require('../assets/robo/olho-aberto-d.webp')],
    fechado: [require('../assets/robo/olho-fechado-e.webp'), require('../assets/robo/olho-fechado-d.webp')],
    feliz: [require('../assets/robo/olho-feliz-e.webp'), require('../assets/robo/olho-feliz-d.webp')],
    pensando: [require('../assets/robo/olho-pensando-e.webp'), require('../assets/robo/olho-pensando-d.webp')],
  },
  bocas: {
    sorriso: require('../assets/robo/boca-sorriso.webp'),
    neutra: require('../assets/robo/boca-neutra.webp'),
    a: require('../assets/robo/boca-a.webp'),
    o: require('../assets/robo/boca-o.webp'),
    e: require('../assets/robo/boca-e.webp'),
  },
} as const;

type Olhos = keyof typeof P.olhos;
type Boca = keyof typeof P.bocas;

const TAM_BOCA: Record<Boca, [number, number]> = { sorriso: [174, 58], neutra: [138, 20], a: [137, 109], o: [97, 111], e: [172, 106] };
const BRACO = { relaxado: { l: 104, a: 441, px: 61, py: 41 }, aberto: { l: 138, a: 441, px: 65, py: 42 }, caneta: { l: 146, a: 440, px: 71, py: 41 }, curvado: { l: 117, a: 442, px: 56, py: 41 } };
type Braco = keyof typeof BRACO;
const FONTE_BRACO: Record<Braco, ImageSourcePropType> = { relaxado: P.bracoRelaxado, aberto: P.bracoAberto, caneta: P.bracoCaneta, curvado: P.bracoCurvado };

const TRONCO = { x: 117, y: 380 };
const CAPACETE = { x: 123, y: 75 };
const TELA = { x: 352, y: 279 };
const OMBRO_E = { x: TRONCO.x + 35, y: TRONCO.y + 145 };
const OMBRO_D = { x: TRONCO.x + 430, y: TRONCO.y + 145 };

function BracoAnimado({ lado, tipo, angulo }: { lado: 'e' | 'd'; tipo: Braco; angulo: Animated.AnimatedInterpolation<string> }) {
  const b = BRACO[tipo];
  const espelho = lado === 'd';
  const pivoX = espelho ? b.l - b.px : b.px;
  const ombro = espelho ? OMBRO_D : OMBRO_E;
  return (
    <Animated.View style={{
      position: 'absolute', left: ombro.x - pivoX, top: ombro.y - b.py, width: b.l, height: b.a,
      transformOrigin: [pivoX, b.py, 0], transform: [{ rotate: angulo }, { scale: 0.86 }],
    }}>
      <Image source={FONTE_BRACO[tipo]} style={{ width: b.l, height: b.a, transform: espelho ? [{ scaleX: -1 }] : [] }} />
    </Animated.View>
  );
}

export function RoboNativo({ altura, acao }: { altura: number; acao: AcaoRobo }) {
  const escala = altura / CENA.altura;
  const flutua = useRef(new Animated.Value(0)).current;
  const balanco = useRef(new Animated.Value(0)).current;
  const aceno = useRef(new Animated.Value(0)).current;
  const jato = useRef(new Animated.Value(0)).current;
  const rabisco = useRef(new Animated.Value(0)).current;
  const [olhos, setOlhos] = useState<Olhos>('aberto');
  const [boca, setBoca] = useState<Boca>('sorriso');

  // Movimentos contínuos (flutuar, balançar, jato).
  useEffect(() => {
    let animacoes: Animated.CompositeAnimation[] = [];
    void AccessibilityInfo.isReduceMotionEnabled().then((reduzir) => {
      if (reduzir) return;
      const vaiVem = (v: Animated.Value, ms: number) => Animated.loop(Animated.sequence([
        Animated.timing(v, { toValue: 1, duration: ms, easing: Easing.inOut(Easing.sin), useNativeDriver: true }),
        Animated.timing(v, { toValue: 0, duration: ms, easing: Easing.inOut(Easing.sin), useNativeDriver: true }),
      ]));
      animacoes = [vaiVem(flutua, 1900), vaiVem(balanco, 2600), vaiVem(jato, 90), vaiVem(rabisco, 160)];
      animacoes.forEach((a) => a.start());
    });
    return () => animacoes.forEach((a) => a.stop());
  }, [flutua, balanco, jato, rabisco]);

  // Aceno (e comemoração) balança o braço rápido.
  useEffect(() => {
    if (acao !== 'acenar' && acao !== 'comemorar') { aceno.setValue(0); return; }
    const a = Animated.loop(Animated.sequence([
      Animated.timing(aceno, { toValue: 1, duration: 260, useNativeDriver: true }),
      Animated.timing(aceno, { toValue: 0, duration: 260, useNativeDriver: true }),
    ]));
    a.start();
    return () => a.stop();
  }, [acao, aceno]);

  // Expressões: olhos por ação + piscar natural; boca mexendo quando fala.
  useEffect(() => {
    const base: Olhos = acao === 'acenar' || acao === 'sorrir' || acao === 'comemorar' ? 'feliz' : acao === 'pensar' || acao === 'anotar' ? 'pensando' : 'aberto';
    setOlhos(base);
    setBoca(acao === 'pensar' || acao === 'anotar' ? 'neutra' : acao === 'comemorar' ? 'e' : 'sorriso');
    let ativo = true;
    const piscar = () => {
      if (!ativo) return;
      if (base === 'aberto' || base === 'pensando') {
        setOlhos('fechado');
        setTimeout(() => ativo && setOlhos(base), 130);
      }
      setTimeout(piscar, 2500 + Math.random() * 3500);
    };
    const tPiscar = setTimeout(piscar, 1800);
    let tFala: ReturnType<typeof setInterval> | undefined;
    if (acao === 'falar') {
      const bocas: Boca[] = ['a', 'o', 'e', 'neutra', 'sorriso', 'a', 'e'];
      tFala = setInterval(() => setBoca(bocas[Math.floor(Math.random() * bocas.length)] ?? 'a'), 120);
    }
    return () => { ativo = false; clearTimeout(tPiscar); if (tFala) clearInterval(tFala); };
  }, [acao]);

  const sobeDesce = flutua.interpolate({ inputRange: [0, 1], outputRange: [8, -8] });
  const inclina = balanco.interpolate({ inputRange: [0, 1], outputRange: acao === 'pensar' ? ['-6deg', '-3deg'] : ['-3deg', '3deg'] });
  const jatoEscala = jato.interpolate({ inputRange: [0, 1], outputRange: [0.82, 1] });

  const anguloRepouso = (d: number) => balanco.interpolate({ inputRange: [0, 1], outputRange: [`${d - 3}deg`, `${d + 3}deg`] });
  const anguloAceno = aceno.interpolate({ inputRange: [0, 1], outputRange: ['135deg', '165deg'] });

  let bracoE: Braco = 'relaxado';
  let bracoD: Braco = 'relaxado';
  let anguloE = anguloRepouso(10);
  let anguloD = anguloRepouso(-10);
  if (acao === 'acenar') { bracoE = 'aberto'; anguloE = anguloAceno; }
  if (acao === 'comemorar') { bracoE = 'aberto'; bracoD = 'aberto'; anguloE = anguloAceno; anguloD = aceno.interpolate({ inputRange: [0, 1], outputRange: ['-165deg', '-135deg'] }); }
  if (acao === 'falar') { bracoD = 'curvado'; anguloD = anguloRepouso(-24); }
  if (acao === 'pensar') { bracoE = 'curvado'; anguloE = anguloRepouso(-150); }
  // Anotar: a mão direita apoia a prancheta inclinada (deitada na direção do
  // robô) e a outra escreve com a ponta da caneta NO papel, rabiscando curto.
  if (acao === 'anotar') {
    bracoE = 'caneta'; bracoD = 'curvado';
    anguloE = rabisco.interpolate({ inputRange: [0, 1], outputRange: ['-51.2deg', '-48.8deg'] });
    anguloD = balanco.interpolate({ inputRange: [0, 1], outputRange: ['24deg', '24deg'] });
  }

  const [lb, ab] = TAM_BOCA[boca];
  const eb = 0.72;

  return (
    <View style={{ width: CENA.largura * escala, height: altura }} accessible accessibilityLabel="Robô assistente da PrimeERP">
      <Animated.View style={{ width: CENA.largura, height: CENA.altura, transformOrigin: [0, 0, 0], transform: [{ scale: escala }, { translateY: sobeDesce }] }}>
        <Animated.Image source={P.jato} style={{ position: 'absolute', left: 299, top: 975, width: 103, height: 138, opacity: 0.9, transformOrigin: [51, 0, 0], transform: [{ scaleY: jatoEscala }] }} />
        <Image source={P.base} style={{ position: 'absolute', left: 163, top: 728, width: 374, height: 264 }} />
        <Image source={P.tronco} style={{ position: 'absolute', left: TRONCO.x, top: TRONCO.y, width: 466, height: 413 }} />
        <BracoAnimado lado="d" tipo={bracoD} angulo={anguloD} />
        {acao === 'anotar' && <Image source={P.prancheta} resizeMode="stretch" style={{ position: 'absolute', left: 315, top: 655, width: 185, height: 128, transform: [{ rotate: '-16deg' }, { skewX: '-16deg' }] }} />}
        <BracoAnimado lado="e" tipo={bracoE} angulo={anguloE} />
        <Animated.View style={{ position: 'absolute', left: CAPACETE.x, top: CAPACETE.y, width: 454, height: 355, transformOrigin: [227, 345, 0], transform: [{ rotate: inclina }] }}>
          <Image source={P.capacete} style={{ width: 454, height: 355 }} />
          <Image source={P.olhos[olhos][0]} style={{ position: 'absolute', left: TELA.x - CAPACETE.x - 105, top: TELA.y - CAPACETE.y - 62, width: 84, height: olhos === 'fechado' ? 20 : 87, marginTop: olhos === 'fechado' ? 34 : 0 }} resizeMode="contain" />
          <Image source={P.olhos[olhos][1]} style={{ position: 'absolute', left: TELA.x - CAPACETE.x + 21, top: TELA.y - CAPACETE.y - 62, width: 84, height: olhos === 'fechado' ? 20 : 87, marginTop: olhos === 'fechado' ? 34 : 0 }} resizeMode="contain" />
          <Image source={P.bocas[boca]} style={{ position: 'absolute', width: lb * eb, height: ab * eb, left: TELA.x - CAPACETE.x - (lb * eb) / 2, top: TELA.y - CAPACETE.y + 48 - (ab * eb) / 4 }} resizeMode="contain" />
        </Animated.View>
      </Animated.View>
    </View>
  );
}
