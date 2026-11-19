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
