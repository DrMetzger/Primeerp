import { createHmac, timingSafeEqual } from 'node:crypto';
import nodemailer from 'nodemailer';
import { primeira } from './db';
import { ANEXO_LOGO, montarEmail } from './email-modelo';

/**
 * Integrações externas do Prime Hub (WhatsApp Cloud API da Meta e e-mail
 * SMTP), sem PHP. Configuração lida da tabela `configuracoes` (tela
 * Configurações), igual ao sistema antigo.
 *
 * SEM_NOTIFICACOES=1 (cópia de teste): nada é enviado para ninguém, só log.
 */

const VERSAO_API = 'v21.0';
const semNotificacoes = () => process.env.SEM_NOTIFICACOES === '1';

export async function configuracao(chave: string, padrao = ''): Promise<string> {
  const l = await primeira<{ valor: string | null }>('SELECT valor FROM configuracoes WHERE chave = ?', [chave]).catch(() => null);
  return l?.valor?.trim() || padrao;
}

// ---------------------------------------------------------------- WhatsApp

async function token(): Promise<string> { return configuracao('whatsapp_access_token', process.env.WHATSAPP_ACCESS_TOKEN ?? ''); }
async function numeroId(): Promise<string> { return configuracao('whatsapp_phone_number_id', process.env.WHATSAPP_PHONE_NUMBER_ID ?? ''); }

export async function whatsappConfigurado(): Promise<boolean> {
  if (semNotificacoes()) return false;
  return Boolean((await token()) && (await numeroId()));
}

let ultimoAlertaToken = 0;

async function graph(caminho: string, corpo: unknown | null, metodo: 'POST' | 'GET' = 'POST'): Promise<Record<string, unknown> | null> {
  try {
    const r = await fetch(`https://graph.facebook.com/${VERSAO_API}/${caminho}`, {
      method: metodo,
      headers: { authorization: `Bearer ${await token()}`, 'content-type': 'application/json' },
      ...(metodo === 'POST' ? { body: JSON.stringify(corpo) } : {}),
      signal: AbortSignal.timeout(15_000),
    });
    const texto = await r.text();
    if (!r.ok) {
      console.error(`WhatsApp: Graph API HTTP ${r.status} ${texto.slice(0, 300)}`);
      // Token vencido derruba todo envio: avisa a equipe por e-mail (no máximo 1x/hora).
      if ((r.status === 401 || r.status === 403) && Date.now() - ultimoAlertaToken > 3_600_000) {
        ultimoAlertaToken = Date.now();
        for (const email of await destinatariosEquipe()) {
          void enviarEmail(email, '⚠️ WhatsApp da Prime parou de enviar mensagens',
            `<p>A API do WhatsApp está rejeitando as mensagens (HTTP ${r.status} -- token da Meta provavelmente expirado ou inválido).</p><p>Gere um token novo (de preferência permanente, via System User) na Meta Business Manager e atualize em Configurações &gt; WhatsApp.</p>`);
        }
      }
      return null;
    }
    return texto ? (JSON.parse(texto) as Record<string, unknown>) : {};
  } catch (e) {
    console.error('WhatsApp: falha chamando a Graph API:', e instanceof Error ? e.message : e);
    return null;
  }
}

/** Corpo de mensagem interativa tem limite de 1024 caracteres na Meta (passou disso, ela recusa calada). */
const limitar = (t: string) => ([...t].length > 1024 ? `${[...t].slice(0, 1021).join('')}...` : t);
const cortar = (t: string, n: number) => [...t].slice(0, n).join('');

export async function enviarTexto(para: string, texto: string): Promise<boolean> {
  if (!(await whatsappConfigurado())) { console.log(`WhatsApp (não enviado: desligado/sem configuração): ${texto.slice(0, 80)}`); return false; }
  return (await graph(`${await numeroId()}/messages`, { messaging_product: 'whatsapp', to: para, type: 'text', text: { body: texto } })) !== null;
}

export interface Botao { id: string; titulo: string }
export async function enviarBotoes(para: string, corpo: string, botoes: Botao[]): Promise<boolean> {
  if (!(await whatsappConfigurado())) return false;
  return (await graph(`${await numeroId()}/messages`, {
    messaging_product: 'whatsapp', to: para, type: 'interactive',
    interactive: { type: 'button', body: { text: limitar(corpo) }, action: { buttons: botoes.map((b) => ({ type: 'reply', reply: { id: b.id, title: cortar(b.titulo, 20) } })) } },
  })) !== null;
}

export interface Linha { id: string; titulo: string; descricao?: string }
export async function enviarLista(para: string, corpo: string, tituloBotao: string, linhas: Linha[]): Promise<boolean> {
  if (!(await whatsappConfigurado())) return false;
  return (await graph(`${await numeroId()}/messages`, {
    messaging_product: 'whatsapp', to: para, type: 'interactive',
    interactive: {
      type: 'list', body: { text: limitar(corpo) },
      action: { button: cortar(tituloBotao, 20), sections: [{ rows: linhas.map((l) => ({ id: l.id, title: cortar(l.titulo, 24), ...(l.descricao ? { description: cortar(l.descricao, 72) } : {}) })) }] },
    },
  })) !== null;
}

/** Baixa um print/arquivo recebido (a Meta manda só o id; a URL expira rápido). */
export async function baixarMidia(mediaId: string): Promise<{ bytes: Buffer; mime: string } | null> {
  if (!(await whatsappConfigurado())) return null;
  const info = await graph(mediaId, null, 'GET');
  const url = typeof info?.url === 'string' ? info.url : '';
  if (!url) return null;
  try {
    const r = await fetch(url, { headers: { authorization: `Bearer ${await token()}` }, signal: AbortSignal.timeout(30_000) });
    if (!r.ok) return null;
    return { bytes: Buffer.from(await r.arrayBuffer()), mime: typeof info?.mime_type === 'string' ? info.mime_type : 'application/octet-stream' };
  } catch { return null; }
}

/** Assinatura HMAC (X-Hub-Signature-256) que a Meta manda: garante que veio mesmo dela. */
export async function assinaturaValida(corpoBruto: string, recebida: string | undefined): Promise<boolean> {
  const segredo = await configuracao('whatsapp_app_secret', process.env.WHATSAPP_APP_SECRET ?? '');
  if (!segredo) return true;
  if (!recebida) return false;
  const esperada = Buffer.from(`sha256=${createHmac('sha256', segredo).update(corpoBruto).digest('hex')}`);
  const veio = Buffer.from(recebida);
  return esperada.length === veio.length && timingSafeEqual(esperada, veio);
}

// ---------------------------------------------------------------- E-mail

export async function enviarEmail(para: string, assunto: string, html: string, opcoes: { sempre?: boolean } = {}): Promise<boolean> {
  if (semNotificacoes() && !opcoes.sempre) { console.log(`E-mail não enviado (modo teste): ${assunto.slice(0, 80)}`); return false; }
  const host = await configuracao('smtp_host', process.env.SMTP_HOST ?? '');
  const usuario = await configuracao('smtp_user', process.env.SMTP_USER ?? '');
  if (!host || !usuario) { console.error('E-mail: SMTP não configurado em Configurações.'); return false; }
  const porta = Number(await configuracao('smtp_port', '587'));
  const seguro = await configuracao('smtp_secure', 'tls');
  try {
    const t = nodemailer.createTransport({ host, port: porta, secure: seguro === 'ssl' || porta === 465, auth: { user: usuario, pass: await configuracao('smtp_pass', process.env.SMTP_PASS ?? '') }, connectionTimeout: 15_000 });
    const remetente = await configuracao('smtp_nome_remetente', 'Prime Hub');
    // Todo aviso sai no modelo da PrimeERP (logo, cartão); o link "Ver..." vira botão.
    const link = /<p><a href="([^"]+)">([^<]+)<\/a><\/p>\s*$/.exec(html);
    const corpo = link ? html.slice(0, link.index) : html;
    const bonito = montarEmail({ previa: assunto, titulo: assunto, corpo, ...(link ? { botao: { texto: `${link[2]} →`, url: link[1] ?? '' } } : {}) });
    const r = await t.sendMail({ from: `"${remetente}" <${usuario}>`, to: para, subject: assunto, html: bonito, attachments: [ANEXO_LOGO], text: html.replace(/<br\s*\/?>/gi, '\n').replace(/<[^>]+>/g, '').replace(/\n{3,}/g, '\n\n').trim() });
    console.log(`E-mail aceito pelo servidor: ${assunto.slice(0, 60)} (${String(r.response ?? '').slice(0, 60)})`);
    return true;
  } catch (e) {
    console.error(`E-mail: falha ao enviar "${assunto.slice(0, 60)}":`, e instanceof Error ? e.message : e);
    return false;
  }
}

export async function destinatariosEquipe(): Promise<string[]> {
  const lista = await configuracao('notificacao_emails_equipe', 'kleber.farias@primeerp.com.br\npcorreajr@primeerp.com.br');
  return lista.split(/[\r\n,]+/).map((s) => s.trim()).filter(Boolean);
}

/** WhatsApp para o número da equipe que recebe os avisos (Configurações > WhatsApp). */
export async function avisarEquipeWhatsapp(mensagem: string): Promise<void> {
  const destino = await configuracao('whatsapp_notificar_telefone', process.env.WHATSAPP_NOTIFICAR_TELEFONE ?? '5517981368201');
  if (destino) await enviarTexto(destino, mensagem);
}

export const escaparHtml = (t: string) => t.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c] ?? c);
export const urlBase = () => (process.env.URL_BASE ?? 'https://suporte.primeerp.com.br').replace(/\/$/, '');
