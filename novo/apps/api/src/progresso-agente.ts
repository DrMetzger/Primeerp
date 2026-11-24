/**
 * Progresso ao vivo das operações longas do agente (gerar fonte, compilar,
 * resetar senha). A ferramenta vai escrevendo a fase aqui enquanto roda; o
 * site busca por polling para o usuário ver "gerando / compilando / erro"
 * sem ficar olhando pra uma tela parada. É memória do processo (efêmero) —
 * some num restart, o que é ok para feedback de UI.
 */

export type FaseAgente = 'gerando' | 'convertendo' | 'compilando' | 'resetando' | 'concluido' | 'erro';

export interface ProgressoAgente {
  fase: FaseAgente;
  rotulo: string;      // texto pronto para a tela ("Compilando na Werk…")
  detalhe?: string;    // mensagem de erro ou info extra
  atualizadoEm: number;
}

const POR_USUARIO = new Map<number, ProgressoAgente>();
const VALIDADE_MS = 3 * 60_000; // progresso velho é descartado

export function marcarProgresso(usuarioId: number, fase: FaseAgente, rotulo: string, detalhe?: string): void {
  const p: ProgressoAgente = { fase, rotulo, atualizadoEm: Date.now() };
  if (detalhe !== undefined) p.detalhe = detalhe;
  POR_USUARIO.set(usuarioId, p);
}

export function limparProgresso(usuarioId: number): void {
  POR_USUARIO.delete(usuarioId);
}

export function lerProgresso(usuarioId: number): ProgressoAgente | null {
  const p = POR_USUARIO.get(usuarioId);
  if (!p) return null;
  if (Date.now() - p.atualizadoEm > VALIDADE_MS) { POR_USUARIO.delete(usuarioId); return null; }
  return p;
}
