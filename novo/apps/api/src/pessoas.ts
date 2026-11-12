import type { UsuarioLogado } from '@primehub/shared';
import { consulta } from './db';

/**
 * Quem escreveu cada mensagem de chamado: a PESSOA (membro da equipe,
 * responsável do setor ou contato master do cliente), com foto -- nunca só
 * o nome da empresa.
 */
export const SQL_AUTOR_RESPOSTA = `
  COALESCE(CASE WHEN r.autor_tipo = 'equipe' THEN u.nome WHEN r.autor_cliente_tipo = 'setor' THEN sr.nome WHEN r.autor_cliente_tipo = 'master' THEN NULLIF(cc.contato_nome, '') END, r.autor_nome) AS autorNome,
  CASE WHEN r.autor_tipo = 'equipe' THEN u.foto_perfil WHEN r.autor_cliente_tipo = 'setor' THEN sr.foto_perfil ELSE cc.foto_perfil END AS autorFoto,
  CASE WHEN r.autor_tipo = 'equipe' THEN 'Equipe PrimeERP' ELSE cc.nome END AS autorPapel`;

export const JOINS_AUTOR_RESPOSTA = `
  JOIN chamados rch ON rch.id = r.chamado_id
  LEFT JOIN usuarios u ON u.id = r.autor_usuario_id
  LEFT JOIN setor_responsaveis sr ON sr.id = r.autor_setor_responsavel_id
  LEFT JOIN clientes cc ON cc.id = rch.cliente_id`;

/** Última pessoa que escreveu em cada chamado da lista (uma consulta só). */
export async function ultimosAutores(ids: number[]): Promise<Map<number, { nome: string; foto: string | null; tipo: 'equipe' | 'cliente'; em: string }>> {
  const mapa = new Map<number, { nome: string; foto: string | null; tipo: 'equipe' | 'cliente'; em: string }>();
  if (!ids.length) return mapa;
  const linhas = await consulta<{ chamadoId: number; autorNome: string; autorFoto: string | null; tipo: 'equipe' | 'cliente'; em: string }>(
    `SELECT r.chamado_id chamadoId, r.autor_tipo tipo, r.criado_em em, ${SQL_AUTOR_RESPOSTA}
       FROM chamado_respostas r ${JOINS_AUTOR_RESPOSTA}
      WHERE r.id IN (SELECT MAX(id) FROM chamado_respostas WHERE chamado_id IN (${ids.map(() => '?').join(',')}) GROUP BY chamado_id)`, ids);
  for (const l of linhas) mapa.set(l.chamadoId, { nome: l.autorNome, foto: l.autorFoto, tipo: l.tipo, em: l.em });
  return mapa;
}

/** Tipo de quem lê, pra confirmação de leitura (mesma convenção do portal antigo). */
export function tipoLeitor(u: UsuarioLogado): 'equipe' | 'master' | 'setor' {
  return u.tipo === 'equipe' ? 'equipe' : u.perfil === 'master' ? 'master' : 'setor';
}

export async function registrarLeitura(chamadoId: number, u: UsuarioLogado): Promise<void> {
  await consulta(
    `INSERT INTO chamado_leituras (chamado_id, leitor_tipo, leitor_id, leitor_nome, lido_em) VALUES (?, ?, ?, ?, NOW())
     ON DUPLICATE KEY UPDATE lido_em = NOW(), leitor_nome = VALUES(leitor_nome)`, [chamadoId, tipoLeitor(u), u.id, u.nome]).catch(() => undefined);
}

export function tipoAnexo(nome: string): 'imagem' | 'video' | 'pdf' | 'arquivo' {
  const ext = nome.split('.').pop()?.toLowerCase() ?? '';
  if (['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'heic'].includes(ext)) return 'imagem';
  if (['mp4', 'webm', 'mov', 'm4v', 'avi', 'mkv'].includes(ext)) return 'video';
  if (ext === 'pdf') return 'pdf';
  return 'arquivo';
}

export async function garantirEstrutura(): Promise<void> {
  await consulta(`CREATE TABLE IF NOT EXISTS chamado_leituras (
      chamado_id INT NOT NULL, leitor_tipo VARCHAR(10) NOT NULL, leitor_id INT NOT NULL, leitor_nome VARCHAR(150) NULL, lido_em DATETIME NOT NULL,
      PRIMARY KEY (chamado_id, leitor_tipo, leitor_id), KEY idx_leitura_chamado (chamado_id, lido_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`).catch((e: unknown) => console.error('estrutura:', e instanceof Error ? e.message : e));
}
