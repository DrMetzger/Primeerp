import { createHmac } from 'node:crypto';
import type { UsuarioLogado } from '@primehub/shared';
import { config } from './config';

/**
 * Link pra abrir uma tela CLÁSSICA (PHP) já logado: token assinado com
 * HMAC-SHA256 (mesmo segredo do sso.php), válido por 60 segundos.
 */
export function linkClassico(usuario: UsuarioLogado, destino: string): string {
  const seguro = /^\/(?!\/)[\w\-./?=&%#]*$/.test(destino) ? destino : '/';
  const corpo = Buffer.from(JSON.stringify({ t: usuario.tipo, id: usuario.id, p: usuario.perfil, d: seguro, exp: Math.floor(Date.now() / 1000) + 60 })).toString('base64url');
  const assinatura = createHmac('sha256', config.ssoSegredo).update(corpo).digest('base64url');
  return `/sso.php?t=${corpo}.${assinatura}`;
}
