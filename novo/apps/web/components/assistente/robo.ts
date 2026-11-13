/**
 * Motor de animação do robô assistente da PrimeERP.
 *
 * O robô é montado em camadas (peças recortadas em public/assistente) e
 * animado a cada quadro por requestAnimationFrame, sem biblioteca: flutua,
 * respira, pisca, acompanha o mouse/dedo com a cabeça e os olhos, e executa
 * ações (acenar, falar, sorrir, bocejar, escrever, anotar, pensar, apontar,
 * surpreso). Coordenadas em "unidades de cena" (pixels das peças originais);
 * a cena inteira é escalada pelo CSS pra caber no tamanho desejado.
 */

export type Acao = 'acenar' | 'falar' | 'sorrir' | 'bocejar' | 'escrever' | 'anotar' | 'pensar' | 'apontar' | 'surpreso' | 'comemorar';

type Olhos = 'aberto' | 'fechado' | 'sono' | 'feliz' | 'surpreso' | 'pensando';
type Boca = 'sorriso' | 'neutra' | 'a' | 'o' | 'e' | 'bocejo';
type Braco = 'relaxado' | 'aberto' | 'caneta' | 'apontando' | 'curvado';
type Objeto = 'nenhum' | 'prancheta' | 'tablet';

export const CENA = { largura: 700, altura: 1130 } as const;

const PIVO: Record<Braco, { x: number; y: number }> = {
  relaxado: { x: 61, y: 41 },
  aberto: { x: 65, y: 42 },
  caneta: { x: 71, y: 41 },
  apontando: { x: 52, y: 41 },
  curvado: { x: 56, y: 41 },
};

// Posições na cena (medidas a partir das peças).
const TRONCO = { x: 117, y: 380 };
const CAPACETE = { x: 123, y: 75, pivoX: 227, pivoY: 345 };
const TELA = { x: 352, y: 279 }; // centro da tela do rosto
const OMBRO_E = { x: TRONCO.x + 35, y: TRONCO.y + 145 };
const OMBRO_D = { x: TRONCO.x + 430, y: TRONCO.y + 145 };

interface Pose {
  olhos: Olhos;
  boca: Boca;
  bracoE: Braco;
  bracoD: Braco;
  anguloE: number; // graus; 0 = pendurado pra baixo, positivo = mão pra fora
  anguloD: number;
  objeto: Objeto;
  inclinacao: number; // cabeça
  salto: number; // deslocamento vertical extra do corpo
}

interface Pecas {
  raiz: HTMLDivElement;
  corpo: HTMLDivElement;
  cabeca: HTMLDivElement;
  olhoE: HTMLImageElement;
  olhoD: HTMLImageElement;
  boca: HTMLImageElement;
  olhos: HTMLDivElement;
  bracoE: HTMLDivElement;
  bracoD: HTMLDivElement;
  imgBracoE: HTMLImageElement;
  imgBracoD: HTMLImageElement;
  jato: HTMLImageElement;
  objeto: HTMLImageElement;
  brilho: HTMLImageElement;
}

const src = (nome: string) => `/assistente/${nome}.webp`;

function img(nome: string, x: number, y: number, extra = ''): HTMLImageElement {
  const el = document.createElement('img');
  el.src = src(nome);
  el.alt = '';
  el.draggable = false;
  el.decoding = 'async';
  // max-width/height: o CSS do site (Tailwind) limita img a 100% do pai,
  // o que zeraria os braços (o encaixe do ombro tem largura 0).
  el.style.cssText = `position:absolute;left:${x}px;top:${y}px;max-width:none;max-height:none;pointer-events:none;user-select:none;${extra}`;
  return el;
}

/** Pré-carrega todas as peças (troca de expressão sem piscar em branco). */
export function preCarregar(): void {
  const nomes = [
    'tronco', 'capacete', 'base', 'jato', 'brilho', 'prancheta', 'tablet',
    ...(['relaxado', 'aberto', 'caneta', 'apontando', 'curvado'] as const).map((b) => `braco-${b}`),
    ...(['aberto', 'fechado', 'sono', 'feliz', 'surpreso', 'pensando'] as const).flatMap((o) => [`olho-${o}-e`, `olho-${o}-d`]),
    ...(['sorriso', 'neutra', 'a', 'o', 'e', 'bocejo'] as const).map((b) => `boca-${b}`),
  ];
  for (const n of nomes) {
    const i = new Image();
    i.src = src(n);
  }
}

export class Robo {
  private p: Pecas;
  private quadro = 0;
  private inicio = performance.now();
  private alvo = { x: 0, y: 0 }; // -1..1, pra onde olhar
  private olhar = { x: 0, y: 0 }; // suavizado
  private acao: { nome: Acao; ate: number; desde: number } | null = null;
  private proximaPiscada = performance.now() + 2500;
  private piscandoAte = 0;
  private ultimaInteracao = performance.now();
  private bocaFala: Boca = 'neutra';
  private trocaBoca = 0;
  private reduzirMovimento: boolean;
  private atual: Partial<Record<string, string>> = {};

  constructor(container: HTMLElement) {
    this.reduzirMovimento = matchMedia('(prefers-reduced-motion: reduce)').matches;
    this.p = this.montar(container);
    this.loop = this.loop.bind(this);
    this.quadro = requestAnimationFrame(this.loop);
  }

  destruir(): void {
    cancelAnimationFrame(this.quadro);
    this.p.raiz.remove();
  }

  /** Ponto da tela (px) pra onde o robô deve olhar. */
  olharPara(clienteX: number, clienteY: number): void {
    const r = this.p.raiz.getBoundingClientRect();
    const cx = r.left + r.width * (TELA.x / CENA.largura);
    const cy = r.top + r.height * (TELA.y / CENA.altura);
    const dx = (clienteX - cx) / Math.max(innerWidth * 0.5, 1);
    const dy = (clienteY - cy) / Math.max(innerHeight * 0.5, 1);
    this.alvo = { x: Math.max(-1, Math.min(1, dx)), y: Math.max(-1, Math.min(1, dy)) };
    this.ultimaInteracao = performance.now();
  }

  /** Executa uma ação por um tempo (ms). Falar/escrever/anotar/pensar duram até parar(). */
  agir(nome: Acao, duracaoMs = 2600): void {
    const agora = performance.now();
    this.acao = { nome, desde: agora, ate: agora + duracaoMs };
    this.ultimaInteracao = agora;
  }

  parar(): void {
    this.acao = null;
    this.ultimaInteracao = performance.now();
  }

  get acaoAtual(): Acao | null {
    return this.acao?.nome ?? null;
  }

  private montar(container: HTMLElement): Pecas {
    const raiz = document.createElement('div');
    raiz.style.cssText = `position:relative;width:${CENA.largura}px;height:${CENA.altura}px;transform-origin:0 0;`;

    const corpo = document.createElement('div');
    corpo.style.cssText = 'position:absolute;inset:0;will-change:transform;';

    const jato = img('jato', 299, 975, 'transform-origin:50% 0;mix-blend-mode:screen;opacity:.9;');
    const base = img('base', 163, 728);
    const tronco = img('tronco', TRONCO.x, TRONCO.y);
    const objeto = img('prancheta', 0, 0, 'display:none;');

    const bracoE = document.createElement('div');
    bracoE.style.cssText = `position:absolute;left:${OMBRO_E.x}px;top:${OMBRO_E.y}px;width:0;height:0;will-change:transform;`;
    const imgBracoE = img('braco-relaxado', -PIVO.relaxado.x, -PIVO.relaxado.y);
    bracoE.append(imgBracoE);

    const bracoD = document.createElement('div');
    bracoD.style.cssText = `position:absolute;left:${OMBRO_D.x}px;top:${OMBRO_D.y}px;width:0;height:0;will-change:transform;`;
    const imgBracoD = img('braco-relaxado', -PIVO.relaxado.x, -PIVO.relaxado.y);
    bracoD.append(imgBracoD);

    const cabeca = document.createElement('div');
    cabeca.style.cssText = `position:absolute;left:${CAPACETE.x}px;top:${CAPACETE.y}px;width:454px;height:355px;transform-origin:${CAPACETE.pivoX}px ${CAPACETE.pivoY}px;will-change:transform;`;
    cabeca.append(img('capacete', 0, 0));

    // Rosto: luzes sobre a tela, com brilho somado (fundo preto some).
    const olhos = document.createElement('div');
    olhos.style.cssText = 'position:absolute;inset:0;will-change:transform;mix-blend-mode:screen;filter:drop-shadow(0 0 10px rgba(47,211,229,.55));';
    const olhoE = img('olho-aberto-e', TELA.x - CAPACETE.x - 105, TELA.y - CAPACETE.y - 62);
    const olhoD = img('olho-aberto-d', TELA.x - CAPACETE.x + 21, TELA.y - CAPACETE.y - 62);
    const boca = img('boca-sorriso', 0, 0);
    olhos.append(olhoE, olhoD, boca);
    cabeca.append(olhos);

    const brilho = img('brilho', 520, 40, 'mix-blend-mode:screen;opacity:0;');

    // Ordem: braço que segura, objeto por cima dele, braço da caneta por cima de tudo.
    corpo.append(jato, base, tronco, bracoD, objeto, bracoE, cabeca, brilho);
    raiz.append(corpo);
    container.append(raiz);

    return { raiz, corpo, cabeca, olhoE, olhoD, boca, olhos, bracoE, bracoD, imgBracoE, imgBracoD, jato, objeto, brilho };
  }

  /** Troca a imagem só quando muda (evita recarregar a cada quadro). */
  private trocar(el: HTMLImageElement, chave: string, nome: string): void {
    if (this.atual[chave] === nome) return;
    this.atual[chave] = nome;
    el.src = src(nome);
  }

  private pose(t: number, agora: number): Pose {
    const pose: Pose = { olhos: 'aberto', boca: 'sorriso', bracoE: 'relaxado', bracoD: 'relaxado', anguloE: 10, anguloD: 10, objeto: 'nenhum', inclinacao: 0, salto: 0 };
    const a = this.acao;
    if (a && agora > a.ate) this.acao = null;
    const p = a ? (agora - a.desde) / Math.max(1, a.ate - a.desde) : 0; // progresso 0..1
    const vai = (de: number, para: number, entrada = 0.15) => de + (para - de) * Math.min(1, p / entrada); // entra suave

    switch (this.acao?.nome) {
      case 'acenar':
        pose.olhos = 'feliz';
        pose.bracoE = 'aberto';
        pose.anguloE = vai(10, 150) + Math.sin(t * 9) * 18;
        pose.inclinacao = Math.sin(t * 4.5) * 4;
        break;
      case 'falar':
        if (agora > this.trocaBoca) {
          const bocas: Boca[] = ['a', 'o', 'e', 'neutra', 'sorriso', 'a', 'e'];
          this.bocaFala = bocas[Math.floor(Math.random() * bocas.length)] ?? 'a';
          this.trocaBoca = agora + 80 + Math.random() * 90;
        }
        pose.boca = this.bocaFala;
        pose.bracoD = 'curvado';
        pose.anguloD = 22 + Math.sin(t * 3) * 10;
        pose.inclinacao = Math.sin(t * 2.2) * 3;
        break;
      case 'sorrir':
        pose.olhos = 'feliz';
        pose.inclinacao = 5;
        break;
      case 'comemorar':
        pose.olhos = 'feliz';
        pose.boca = 'e';
        pose.bracoE = 'aberto';
        pose.bracoD = 'aberto';
        pose.anguloE = 160 + Math.sin(t * 12) * 10;
        pose.anguloD = 160 + Math.sin(t * 12 + 1) * 10;
        pose.salto = -Math.abs(Math.sin(t * 7)) * 40;
        break;
      case 'bocejar': {
        const fase = Math.sin(Math.min(1, p) * Math.PI); // abre e fecha
        pose.olhos = fase > 0.35 ? 'fechado' : 'sono';
        pose.boca = fase > 0.25 ? 'bocejo' : 'neutra';
        pose.bracoE = 'aberto';
        pose.bracoD = 'aberto';
        pose.anguloE = 10 + fase * 140;
        pose.anguloD = 10 + fase * 140;
        pose.inclinacao = -fase * 6;
        pose.salto = -fase * 14;
        break;
      }
      case 'escrever':
      case 'anotar':
        // Segura a prancheta/tablet na frente da barriga com uma mão e
        // escreve com a caneta na outra (mão da caneta "rabiscando").
        pose.olhos = 'pensando';
        pose.boca = 'neutra';
        pose.objeto = this.acao?.nome === 'escrever' ? 'tablet' : 'prancheta';
        // Mão direita apoia a prancheta inclinada; a ponta da caneta fica no
        // papel e só rabisca curto (sem escrever no ar).
        pose.bracoD = 'curvado';
        pose.anguloD = -24;
        pose.bracoE = 'caneta';
        pose.anguloE = -50 + Math.sin(t * 19) * 1.1 + Math.sin(t * 3.1) * 0.9;
        pose.inclinacao = 8;
        break;
      case 'pensar':
        pose.olhos = 'pensando';
        pose.boca = 'neutra';
        pose.bracoE = 'curvado';
        pose.anguloE = vai(10, -150) + Math.sin(t * 2) * 3;
        pose.inclinacao = -5 + Math.sin(t * 1.5) * 2;
        break;
      case 'apontar':
        pose.olhos = 'aberto';
        pose.bracoD = 'apontando';
        pose.anguloD = vai(10, 70);
        pose.boca = 'sorriso';
        break;
      case 'surpreso':
        pose.olhos = 'surpreso';
        pose.boca = 'o';
        pose.salto = -Math.sin(Math.min(1, p * 3) * Math.PI) * 30;
        pose.anguloE = 30;
        pose.anguloD = 30;
        break;
      default:
        break;
    }
    return pose;
  }

  private loop(agora: number): void {
    this.quadro = requestAnimationFrame(this.loop);
    const t = (agora - this.inicio) / 1000;
    const m = this.reduzirMovimento ? 0.15 : 1;

    // Sem ninguém interagir por 25s: boceja sozinho de vez em quando.
    if (!this.acao && agora - this.ultimaInteracao > 25_000) {
      this.agir('bocejar', 3200);
      this.ultimaInteracao = agora;
    }

    const pose = this.pose(t, agora);

    // Piscar natural (a cada 2,5 a 6 s), menos quando os olhos já estão fechados/felizes.
    if (agora > this.proximaPiscada) {
      this.piscandoAte = agora + 130;
      this.proximaPiscada = agora + 2500 + Math.random() * 3500;
    }
    if (agora < this.piscandoAte && (pose.olhos === 'aberto' || pose.olhos === 'surpreso' || pose.olhos === 'pensando')) {
      pose.olhos = 'fechado';
    }

    // Olhar segue o alvo com suavização.
    this.olhar.x += (this.alvo.x - this.olhar.x) * 0.12;
    this.olhar.y += (this.alvo.y - this.olhar.y) * 0.12;
    const olhandoX = this.acao?.nome === 'escrever' || this.acao?.nome === 'anotar' ? -0.4 : this.olhar.x;
    const olhandoY = this.acao?.nome === 'escrever' || this.acao?.nome === 'anotar' ? 0.7 : this.olhar.y;

    const p = this.p;
    // Corpo: flutuação + respiração + salto da ação.
    const flutua = Math.sin(t * 1.6) * 14 * m + pose.salto * m;
    const respira = 1 + Math.sin(t * 2.4) * 0.008 * m;
    p.corpo.style.transform = `translateY(${flutua}px) rotate(${olhandoX * 2.5 * m}deg) scale(${respira})`;

    // Cabeça acompanha o olhar.
    p.cabeca.style.transform = `rotate(${(olhandoX * 9 + pose.inclinacao) * m}deg) translate(${olhandoX * 8 * m}px, ${olhandoY * 6 * m}px)`;
    p.olhos.style.transform = `translate(${olhandoX * 26 * m}px, ${olhandoY * 18 * m}px)`;

    this.trocar(p.olhoE, 'olhoE', `olho-${pose.olhos}-e`);
    this.trocar(p.olhoD, 'olhoD', `olho-${pose.olhos}-d`);
    this.trocar(p.boca, 'boca', `boca-${pose.boca}`);
    // Boca centralizada embaixo dos olhos (cada boca tem um tamanho).
    const tamBoca: Record<Boca, [number, number]> = { sorriso: [174, 58], neutra: [138, 20], a: [137, 109], o: [97, 111], e: [172, 106], bocejo: [133, 206] };
    const [lb, ab] = tamBoca[pose.boca];
    const escalaBoca = pose.boca === 'bocejo' ? 0.62 : 0.72;
    p.boca.style.width = `${lb * escalaBoca}px`;
    p.boca.style.left = `${TELA.x - CAPACETE.x - (lb * escalaBoca) / 2}px`;
    p.boca.style.top = `${TELA.y - CAPACETE.y + 48 - (pose.boca === 'bocejo' ? 30 : 0) - (ab * escalaBoca) / 4}px`;

    // Braços.
    this.trocar(p.imgBracoE, 'bracoE', `braco-${pose.bracoE}`);
    this.trocar(p.imgBracoD, 'bracoD', `braco-${pose.bracoD}`);
    p.imgBracoE.style.left = `${-PIVO[pose.bracoE].x}px`;
    p.imgBracoE.style.top = `${-PIVO[pose.bracoE].y}px`;
    p.imgBracoD.style.left = `${-PIVO[pose.bracoD].x}px`;
    p.imgBracoD.style.top = `${-PIVO[pose.bracoD].y}px`;
    // Escrevendo: braços firmes na prancheta (o balanço tiraria a caneta do papel).
    const firme = pose.objeto !== 'nenhum' ? 0 : 1;
    const balancoE = Math.sin(t * 1.6 + 0.6) * 3 * m * firme;
    const balancoD = Math.sin(t * 1.6 + 1.4) * 3 * m * firme;
    p.bracoE.style.transform = `rotate(${pose.anguloE + balancoE}deg) scale(0.86)`;
    // Braço do outro lado: a mesma peça espelhada.
    p.bracoD.style.transform = `scaleX(-1) rotate(${pose.anguloD + balancoD}deg) scale(0.86)`;

    // Objeto na frente do peito (prancheta/tablet).
    if (pose.objeto === 'nenhum') {
      p.objeto.style.display = 'none';
    } else {
      this.trocar(p.objeto, 'objeto', pose.objeto);
      p.objeto.style.display = 'block';
      // Apoiado na mão direita e deitado na direção do robô (achatado e
      // inclinado), com a ponta da caneta encostando no papel.
      p.objeto.style.left = '315px';
      p.objeto.style.top = '655px';
      p.objeto.style.width = '185px';
      p.objeto.style.height = '128px';
      p.objeto.style.transformOrigin = '50% 50%';
      p.objeto.style.transform = 'rotate(-16deg) skewX(-16deg)';
    }

    // Jato pulsando + brilho quando está pensando/comemorando.
    p.jato.style.transform = `scaleY(${0.85 + Math.sin(t * 22) * 0.12 + Math.random() * 0.06}) scaleX(${0.95 + Math.sin(t * 17) * 0.05})`;
    const brilha = this.acao?.nome === 'pensar' || this.acao?.nome === 'comemorar' || this.acao?.nome === 'sorrir';
    p.brilho.style.opacity = brilha ? String(0.5 + Math.sin(t * 6) * 0.5) : '0';
    p.brilho.style.transform = `rotate(${t * 90}deg) scale(${0.8 + Math.sin(t * 6) * 0.2})`;
  }
}
