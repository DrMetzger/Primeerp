import { request } from 'node:http';
import type { UsuarioLogado } from '@primehub/shared';

/**
 * Painel de serviços da VM (Protheus etc.). Fala com o agente que roda na
 * própria VM por um socket local montado no container; o agente só conhece
 * uma lista fechada de serviços. Só admin/diretor/CEO.
 */

const SOCKET = process.env.VM_AGENTE_SOCKET ?? '/run/primehub/servicos.sock';
export const podePainelVm = (u: UsuarioLogado) => u.tipo === 'equipe' && ['admin', 'diretor', 'ceo'].includes(u.perfil ?? '');

export class ErroVm extends Error {
  constructor(message: string, readonly status: 400 | 403 | 404 | 409 | 500 | 503 = 500) { super(message); }
}

export function agente<T>(metodo: 'GET' | 'POST', caminho: string, quem?: string, corpo?: unknown, timeoutMs = 150_000): Promise<T> {
  return new Promise((ok, falha) => {
    const cabecalhos: Record<string, string> = {};
    if (quem) cabecalhos['x-quem'] = quem.replace(/[^\p{L}\p{N} @._-]/gu, '').slice(0, 120);
    const dadosEnvio = corpo !== undefined ? JSON.stringify(corpo) : undefined;
    if (dadosEnvio !== undefined) { cabecalhos['content-type'] = 'application/json'; cabecalhos['content-length'] = String(Buffer.byteLength(dadosEnvio)); }
    const req = request({ socketPath: SOCKET, path: caminho, method: metodo, headers: cabecalhos, timeout: timeoutMs }, (res) => {
      let dados = '';
      res.setEncoding('utf8');
      res.on('data', (c: string) => { dados += c; });
      res.on('end', () => {
        try {
          const j = JSON.parse(dados) as T & { erro?: string };
          if ((res.statusCode ?? 500) >= 400) falha(new ErroVm(j.erro ?? 'O agente da VM recusou.', (res.statusCode === 404 ? 404 : res.statusCode === 400 ? 400 : res.statusCode === 409 ? 409 : 500)));
          else ok(j);
        } catch { falha(new ErroVm('Resposta inválida do agente da VM.')); }
      });
    });
    req.on('timeout', () => { req.destroy(); falha(new ErroVm('O agente da VM demorou para responder.', 503)); });
    req.on('error', () => falha(new ErroVm('O painel da VM está fora do ar (agente de serviços parado).', 503)));
    if (dadosEnvio !== undefined) req.write(dadosEnvio);
    req.end();
  });
}

/** Resposta do reset de senha vindo do agente/REST do Protheus. */
export interface RespostaReset {
  ok: boolean;
  http?: number;
  resposta?: string;
  erro?: string;
}

/**
 * Reseta a senha de um usuário do Protheus pela API REST do ambiente, sem
 * precisar de tela nem de login (o admin pode estar travado). `restBase` é a
 * URL base do REST do ambiente alvo (ex.: http://127.0.0.1:8095 na base).
 * Só admin/diretor/CEO. Observação: a gravação pode ocorrer mesmo quando o
 * Protheus devolve 500 na finalização da transação — por isso conferimos.
 */
export async function resetarSenhaProtheus(quem: string, login: string, senha: string, restBase?: string): Promise<RespostaReset> {
  const r = await agente<RespostaReset>('POST', '/reset/senha', quem, { login, senha, rest_base: restBase }, 60_000);
  // Considera sucesso quando o JSON do PRMRSS traz ok:true OU quando gravou
  // apesar do 500 (resposta sem "chave invalida"/"nao encontrado").
  const corpo = String(r.resposta ?? '');
  const okJson = /"ok"\s*:\s*true/.test(corpo);
  const falhaConhecida = /Chave de autorizacao invalida|Usuario nao encontrado|Login nao informado|Valor inv.lido/i.test(corpo);
  return { ...r, ok: okJson || (r.http === 500 && !falhaConhecida) };
}

export interface FonteAchado { nome: string; caminho: string }
/** Busca fontes .prw/.tlpp pela pasta de fontes do Protheus (por nome parcial). */
export async function buscarFontesProtheus(quem: string, termo: string): Promise<{ ok: boolean; total?: number; fontes?: FonteAchado[]; erro?: string }> {
  return agente('POST', '/fontes/buscar', quem, { termo }, 60_000);
}
/** Lê o conteúdo de um fonte da pasta de fontes do Protheus, por nome ou caminho. */
export async function lerFonteProtheus(quem: string, nome: string, caminho?: string): Promise<{ ok: boolean; nome?: string; caminho?: string; conteudo?: string; erro?: string }> {
  return agente('POST', '/fontes/ler', quem, { nome, caminho }, 60_000);
}

export interface NovoUsuario { login: string; nome: string; senha?: string; email?: string }
/**
 * Cria (ou atualiza) um usuário do Protheus pela API REST PRMADM do ambiente,
 * sem tela e sem acesso ao banco do cliente. `restBase` é a URL do REST do alvo.
 * Só admin/diretor/CEO.
 */
export async function criarUsuarioProtheus(quem: string, u: NovoUsuario, restBase?: string): Promise<RespostaReset> {
  const r = await agente<RespostaReset>('POST', '/admin/criar-usuario', quem, { ...u, rest_base: restBase }, 60_000);
  const corpo = String(r.resposta ?? '');
  const okJson = /"ok"\s*:\s*true/.test(corpo);
  const falhaConhecida = /Chave de autorizacao invalida|Login nao informado|Nome nao informado|Falha ao criar|Falha ao atualizar|Acao desconhecida/i.test(corpo);
  return { ...r, ok: okJson || (r.http === 500 && !falhaConhecida) };
}
