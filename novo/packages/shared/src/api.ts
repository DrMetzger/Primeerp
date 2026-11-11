import type { StatusChamado } from './tema';

/** Contrato da API nova (apps/api) -- usado pelo site e pelo app Android. */

export type TipoLogin = 'equipe' | 'cliente';

export interface PedidoLogin {
  tipo: TipoLogin;
  email: string;
  senha: string;
  /** Token de "lembrar este aparelho" (pula o código por 30 dias). */
  dispositivo?: string;
}

export interface Sessao {
  token: string;
  usuario: UsuarioLogado;
  /** Quando a pessoa marcou "lembrar este aparelho". */
  dispositivo?: string;
}

/** Verificação em duas etapas: senha certa -> código enviado por e-mail. */
export interface DesafioCodigo {
  etapa: 'codigo';
  desafio: string;
  /** E-mail mascarado pra onde o código foi (ex.: k***s@primeerp.com.br). */
  destino: string;
}

export type RespostaLogin = Sessao | DesafioCodigo;

export interface PedidoCodigo {
  desafio: string;
  codigo: string;
  lembrar: boolean;
  aparelho: string;
}

export interface UsuarioLogado {
  tipo: TipoLogin;
  id: number;
  nome: string;
  email: string | null;
  foto: string | null;
  perfil: string | null;
  /** Cliente: empresa a que pertence. */
  clienteId: number | null;
  empresa: string | null;
}

/**
 * Versão obrigatória do app Android: ao abrir, o app compara o próprio
 * versionCode com `versaoMinima`; se for menor, bloqueia tudo e só deixa
 * instalar a nova. Nunca abre numa versão velha.
 */
export interface VersaoApp {
  versao: string;
  versaoCodigo: number;
  versaoMinima: number;
  urlApk: string;
  tamanhoBytes: number | null;
  publicadoEm: string | null;
  novidades: string[];
}

export interface Kpi {
  rotulo: string;
  valor: number;
  /** Variação percentual contra o período anterior (null = sem base). */
  variacao: number | null;
  /** true = subir é bom (ex.: fechados); false = subir é ruim (ex.: pendentes). */
  subirEhBom: boolean;
  sufixo?: string;
}

export interface PontoSemana {
  semana: string;
  abertos: number;
  fechados: number;
}

export interface ItemRanking {
  id: number;
  nome: string;
  valor: number;
  foto?: string | null;
}

export interface ChamadoResumo {
  id: number;
  titulo: string;
  status: StatusChamado;
  prioridade: 'baixa' | 'media' | 'alta' | 'urgente';
  cliente: string | null;
  clienteId: number | null;
  responsavel: string | null;
  criadoEm: string;
  atualizadoEm: string;
  /** Horas desde a última mensagem do cliente sem resposta nossa. */
  horasEsperando: number | null;
}

export interface Dashboard {
  geradoEm: string;
  kpis: {
    pendentesNossos: Kpi;
    abertos30d: Kpi;
    fechados30d: Kpi;
    primeiraRespostaHoras: Kpi;
    resolucaoHoras: Kpi;
    urgentesPendentes: Kpi;
  };
  porStatus: Record<StatusChamado, number>;
  semanas: PontoSemana[];
  porCliente: ItemRanking[];
  porResponsavel: ItemRanking[];
  maisAntigos: ChamadoResumo[];
  ia: {
    analises30d: number;
    respondidasSozinha30d: number;
    sugestoes30d: number;
    tokens30d: number;
  };
}

export interface ListaChamados {
  itens: ChamadoResumo[];
  total: number;
}

export interface LinkClassico {
  url: string;
}
