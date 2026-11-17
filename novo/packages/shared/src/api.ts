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
  /** Caminho do logo da empresa (ex.: assets/avatars/logo-3.png). */
  clienteLogo: string | null;
  responsavel: string | null;
  criadoEm: string;
  atualizadoEm: string;
  /** Horas desde a última mensagem do cliente sem resposta nossa. */
  horasEsperando: number | null;
  responsavelFoto?: string | null;
  /** Quem escreveu por último no chamado (pessoa, não a empresa). */
  ultimo?: { nome: string; foto: string | null; tipo: 'equipe' | 'cliente'; em: string } | null;
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

export interface ClienteResumo {
  id: number;
  nome: string;
  status: 'ativo' | 'pausado' | 'inativo' | string;
  logo: string | null;
  contatoNome: string | null;
  contatoEmail: string | null;
  pendentes: number;
  urgentes: number;
  totalChamados: number;
  documentos: number;
  kpis: number;
  treinamentos: number;
}

export interface Pessoa {
  id: number;
  nome: string;
  email: string | null;
  telefone: string | null;
  foto: string | null;
  setor: string | null;
  portalAtivo: boolean;
  documento?: string | null;
  master?: boolean;
  setorIds?: number[];
}

export interface Documento {
  id: number;
  titulo: string;
  descricao?: string | null;
  categoria: string | null;
  setor: string | null;
  arquivo: string;
  tamanho: number;
  criadoEm: string;
}

export interface Treinamento {
  id: number;
  titulo: string;
  tipo: string | null;
  status: string;
  setor: string | null;
  inicio: string | null;
  link: string | null;
}

export interface ClienteDetalhe {
  cliente: ClienteResumo & { cnpj: string | null; contatoTelefone: string | null; observacoes: string | null; responsavelInterno: string | null; responsavelInternoId: number | null; contatoDocumento: string | null; criadoEm: string };
  setores: { id: number; nome: string; ativo: boolean; pendentes: number }[];
  pessoas: Pessoa[];
  documentos: Documento[];
  treinamentos: Treinamento[];
  equipe: { id: number; nome: string; foto: string | null }[];
  /** Pessoas da Prime vinculadas a este cliente (acesso restrito por cliente/setor). */
  vinculos: { id: number; usuarioId: number; nome: string; foto: string | null; setor: string | null; restrito: boolean }[];
  analises: { id: number; tipo: 'risco' | 'melhorias'; setor: string | null; texto: string; autor: string | null; criadoEm: string; atualizadoEm: string | null }[];
}

export interface Anexo { id: number; nome: string; tamanho: number; tipo: 'imagem' | 'video' | 'pdf' | 'arquivo' }

export interface MensagemChamado {
  id: number;
  autorTipo: 'cliente' | 'equipe';
  autorNome: string;
  autorFoto: string | null;
  /** Cargo/papel curto: "Equipe PrimeERP", "Financeiro", "Master"… */
  autorPapel: string | null;
  mensagem: string;
  criadoEm: string;
  anexos: Anexo[];
  /** Quem do OUTRO lado já viu essa mensagem (e quando). */
  vistoPor: { nome: string; em: string }[];
}

export interface ChamadoDetalhe {
  chamado: ChamadoResumo & { descricao: string; categoria: string | null; setor: string | null; responsavelId: number | null; origem: string | null };
  mensagens: MensagemChamado[];
  ia: { status: string; resposta: string | null; fonte: string | null; statusSugerido: string | null } | null;
  agente?: PropostaAgente | null;
}

export interface TarefaAgenda {
  id: number; titulo: string; data: string; dataFim: string | null; horaInicio: string | null; duracaoMinutos: number | null;
  status: 'pendente' | 'em_andamento' | 'concluida'; notas: string | null;
  clienteId: number | null; cliente: string | null; clienteLogo: string | null;
  chamadoId: number | null; chamado: string | null; responsavelId: number | null; responsavel: string | null;
}
export interface EventoCliente { id: number; titulo: string; tipo: string | null; status: string; inicio: string | null; fim: string | null; link: string | null; cliente: string | null; clienteLogo: string | null; setor: string | null }
export interface HorarioTrabalho { expedienteInicio: string; expedienteFim: string; almocoInicio: string; almocoFim: string }
export interface Agenda { tarefas: TarefaAgenda[]; eventos: EventoCliente[]; clientes: { id: number; nome: string }[]; equipe: { id: number; nome: string; foto?: string | null }[]; horario: HorarioTrabalho }

export interface FonteArquivo { id: number; titulo: string; categoria: string | null; descricao: string | null; arquivo: string; tamanho: number; setor: string | null; setorId: number | null; criadoEm: string; autor: string | null }

export interface UsuarioEquipe { id: number; nome: string; email: string; telefone: string | null; documento: string | null; perfil: string; foto: string | null; ativo: boolean; restrito: boolean; sensiveis: boolean; ultimoLogin: string | null }

export interface Perfil { nome: string; email: string; telefone: string | null; documento: string | null; foto: string | null; tipo: TipoLogin }

export interface PessoaPortal { id: number; nome: string; email: string | null; telefone: string | null; foto: string | null; master: boolean; portalAtivo: boolean; setores: string[] }
export interface EquipePortal { setores: { id: number; nome: string; ativo: boolean }[]; pessoas: PessoaPortal[]; podeGerenciar: boolean }

export interface SetorDetalhe {
  cliente: { id: number; nome: string; logo: string | null };
  setor: { id: number; nome: string; icone: string | null; ativo: boolean };
  pessoas: Pessoa[];
  documentos: Documento[];
  videoaulas: Documento[];
  treinamentos: (Treinamento & { fim: string | null; descricao: string | null; responsavelId: number | null; chamadoId: number | null })[];
  fontes: { id: number; titulo: string; pontoEntrada: string | null; descricao: string | null; arquivo: string; tamanho: number; criadoEm: string }[];
  propostas: { id: number; titulo: string; descricao: string | null; status: string; documentoId: number | null; criadoEm: string }[];
  kpis: { id: number; nome: string; valor: number; meta: number | null; direcao: string | null; unidade: string | null; periodo: string | null; observacoes: string | null }[];
  sugestoesIa: { id: number; texto: string; criadoEm: string }[];
  equipe: { id: number; nome: string; foto: string | null }[];
}

/** Cofre de acessos do cliente (senhas nunca vêm aqui: só sob pedido, com registro). */
export interface CofreCliente {
  podeVer: boolean;
  veTudo: boolean;
  tipos: Record<string, string>;
  ambientesTcloud: Record<string, string>;
  credenciais: { id: number; tipo: string; titulo: string; host: string | null; porta: string | null; usuario: string | null; temSenha: boolean; observacoes: string | null; autor: string | null; atualizadoEm: string; meu: boolean }[];
  vpn: { id: number; titulo: string; arquivo: string; tamanho: number; autor: string | null; criadoEm: string; meu: boolean }[];
  tcloud: { tipo: string; linkProtheus: string | null; linkRest: string | null; linkSchedule: string | null; linkExclusivo: string | null; atualizadoEm: string | null }[];
  vscode: { id: number; titulo: string; link: string | null; porta: string | null; ambiente: string | null; meu: boolean }[];
}

/** Proposta do agente Claude para um chamado (sempre revisada pela equipe antes de ir ao cliente). */
export interface PropostaAgente {
  id: number; chamadoId: number; chamadoTitulo: string; cliente: string; prioridade: string;
  status: string; etapa: string; tipo: string; plano: string | null; instrucoes: string | null;
  resposta: string; statusSugerido: string | null; confianca: string; fontes: { titulo: string; url: string }[];
  resumoInterno: string | null; motivo: string | null; atendente: string | null; arquivos: { id: number; nome: string }[]; criadoEm: string; sessao: string | null;
}
export const TIPOS_CASO_AGENTE: Record<string, string> = {
  resposta: 'Resposta / orientação', duvida: 'Dúvida (falta informação)', correcao: 'Correção', melhoria: 'Melhoria',
  proposta: 'Proposta / orçamento', desenvolvimento: 'Desenvolvimento', outro: 'Outro',
};

/** Painel de serviços da VM (Protheus e companhia). */
export interface ServicoVm {
  id: string; nome: string; descricao: string; unidade: string; critico: boolean;
  estado: string; subestado: string; desde: string | null; memoriaMB: number | null; cpuSegundos: number | null;
  pid: number | null; reinicios: number; iniciaComVM: boolean; portas: { porta: number; aberta: boolean }[];
}
export interface VersoesProtheus {
  protheus?: string; rpoData?: string; appserver?: string; appserverBuild?: string; webapp?: string; dbaccess?: string;
  licenseServer?: string; postgresql?: string; ambiente?: string; webappUrl?: string;
}
export interface PainelVm {
  servicos: ServicoVm[];
  vm: { memoriaTotalMB: number; memoriaUsadaMB: number; memoriaDisponivelMB: number; discoTotalGB: number; discoUsadoGB: number; carga: number[]; nucleos: number };
  versoes: VersoesProtheus;
  agora: string;
}
