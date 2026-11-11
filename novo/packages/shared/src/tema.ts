/**
 * Identidade visual da PrimeERP: azul profundo + turquesa (dos logos).
 * Mesmos tokens no site (CSS variables) e no app Android (React Native).
 */
export const marca = {
  azul: '#1E3FD8',
  azulProfundo: '#0B1B6F',
  turquesa: '#0FB5C9',
  turquesaClaro: '#5EE3F0',
} as const;

export type NomeTema = 'claro' | 'escuro';

export interface Tema {
  fundo: string;
  superficie: string;
  superficieAlta: string;
  borda: string;
  texto: string;
  textoSuave: string;
  primaria: string;
  primariaTexto: string;
  destaque: string;
  sucesso: string;
  alerta: string;
  perigo: string;
  info: string;
}

export const temas: Record<NomeTema, Tema> = {
  claro: {
    fundo: '#F4F7FC',
    superficie: '#FFFFFF',
    superficieAlta: '#EEF3FB',
    borda: '#DDE5F2',
    texto: '#0E1630',
    textoSuave: '#5A6684',
    primaria: '#1E3FD8',
    primariaTexto: '#FFFFFF',
    destaque: '#0A9FB2',
    sucesso: '#15925A',
    alerta: '#B86E00',
    perigo: '#D23A4A',
    info: '#2563EB',
  },
  escuro: {
    fundo: '#070B1C',
    superficie: '#0F1631',
    superficieAlta: '#17204A',
    borda: '#24305E',
    texto: '#EAF0FF',
    textoSuave: '#9AA7CC',
    primaria: '#5B7BFF',
    primariaTexto: '#05081A',
    destaque: '#2FD3E5',
    sucesso: '#3DD68C',
    alerta: '#FFB547',
    perigo: '#FF6B7A',
    info: '#7AA2FF',
  },
};

/** Cores por status de chamado (mesmo significado em todo lugar). */
export const corStatus = {
  aberto: 'info',
  aguardando_equipe: 'perigo',
  aguardando_cliente: 'alerta',
  fechado: 'sucesso',
} as const satisfies Record<StatusChamado, keyof Tema>;

export type StatusChamado = 'aberto' | 'aguardando_equipe' | 'aguardando_cliente' | 'fechado';

export const rotuloStatus: Record<StatusChamado, string> = {
  aberto: 'Aberto',
  aguardando_equipe: 'Aguardando nós',
  aguardando_cliente: 'Aguardando cliente',
  fechado: 'Fechado',
};
