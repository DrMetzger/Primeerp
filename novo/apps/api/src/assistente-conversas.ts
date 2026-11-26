/**
 * Histórico de conversas com o assistente (equipe e cliente), guardado no
 * servidor para a pessoa recuperar depois — estilo Gemini/ChatGPT: uma lista
 * de conversas, cada uma com início, meio e fim. Cada troca (pergunta +
 * resposta) é salva aqui. Nada some ao fechar a janela.
 */

import { consulta, primeira } from './db';
import { executar } from './regras-chamado';

export async function garantirEstruturaAssistente(): Promise<void> {
  await executar(`CREATE TABLE IF NOT EXISTS assistente_conversas (
      id INT AUTO_INCREMENT PRIMARY KEY,
      usuario_id INT NOT NULL,
      titulo VARCHAR(160) NOT NULL DEFAULT 'Conversa',
      criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY ix_conv_usuario (usuario_id, atualizado_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`);
  await executar(`CREATE TABLE IF NOT EXISTS assistente_mensagens (
      id INT AUTO_INCREMENT PRIMARY KEY,
      conversa_id INT NOT NULL,
      autor ENUM('pessoa','assistente') NOT NULL,
      texto MEDIUMTEXT NOT NULL,
      criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY ix_msg_conversa (conversa_id, id),
      CONSTRAINT fk_msg_conversa FOREIGN KEY (conversa_id) REFERENCES assistente_conversas(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`);
}

const tituloDe = (pergunta: string) => (pergunta.replace(/\s+/g, ' ').trim().slice(0, 80) || 'Conversa');

/** Salva a PERGUNTA na hora (cria a conversa se não vier id) e devolve o id.
 * Assim a conversa nunca se perde mesmo se a resposta demorar ou falhar. */
export async function salvarPergunta(usuarioId: number, conversaId: number | null, pergunta: string): Promise<number> {
  let id = conversaId ?? 0;
  if (id) {
    const dona = await primeira<{ id: number }>('SELECT id FROM assistente_conversas WHERE id = ? AND usuario_id = ?', [id, usuarioId]);
    if (!dona) id = 0;
  }
  if (!id) {
    const r = await executar('INSERT INTO assistente_conversas (usuario_id, titulo) VALUES (?, ?)', [usuarioId, tituloDe(pergunta)]);
    id = r.insertId;
  } else {
    await executar('UPDATE assistente_conversas SET atualizado_em = CURRENT_TIMESTAMP WHERE id = ?', [id]);
  }
  if (pergunta.trim()) await executar('INSERT INTO assistente_mensagens (conversa_id, autor, texto) VALUES (?, ?, ?)', [id, 'pessoa', pergunta.slice(0, 20000)]);
  return id;
}

/** Salva a RESPOSTA do assistente numa conversa já criada. */
export async function salvarResposta(conversaId: number, resposta: string): Promise<void> {
  if (!conversaId || !resposta.trim()) return;
  await executar('INSERT INTO assistente_mensagens (conversa_id, autor, texto) VALUES (?, ?, ?)', [conversaId, 'assistente', resposta.slice(0, 20000)]);
  await executar('UPDATE assistente_conversas SET atualizado_em = CURRENT_TIMESTAMP WHERE id = ?', [conversaId]);
}

export interface ConversaResumo { id: number; titulo: string; atualizadoEm: string; previa: string }
export async function listarConversas(usuarioId: number): Promise<ConversaResumo[]> {
  return consulta<ConversaResumo>(
    `SELECT c.id, c.titulo, c.atualizado_em atualizadoEm,
            COALESCE((SELECT LEFT(m.texto, 120) FROM assistente_mensagens m WHERE m.conversa_id = c.id ORDER BY m.id DESC LIMIT 1), '') previa
       FROM assistente_conversas c WHERE c.usuario_id = ? ORDER BY c.atualizado_em DESC LIMIT 100`, [usuarioId]).catch(() => []);
}

export interface MensagemConversa { autor: 'pessoa' | 'assistente'; texto: string; em: string }
export async function lerConversa(usuarioId: number, conversaId: number): Promise<{ id: number; titulo: string; mensagens: MensagemConversa[] } | null> {
  const c = await primeira<{ id: number; titulo: string }>('SELECT id, titulo FROM assistente_conversas WHERE id = ? AND usuario_id = ?', [conversaId, usuarioId]);
  if (!c) return null;
  const mensagens = await consulta<MensagemConversa>('SELECT autor, texto, criado_em em FROM assistente_mensagens WHERE conversa_id = ? ORDER BY id', [conversaId]).catch(() => []);
  return { id: c.id, titulo: c.titulo, mensagens };
}

export async function apagarConversa(usuarioId: number, conversaId: number): Promise<void> {
  await executar('DELETE FROM assistente_conversas WHERE id = ? AND usuario_id = ?', [conversaId, usuarioId]);
}
