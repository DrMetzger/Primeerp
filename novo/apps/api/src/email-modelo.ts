import { LOGO_EMAIL_PNG_BASE64 } from './logo-email';

/**
 * Modelo visual de todos os e-mails do Prime Hub: cabeçalho azul-marinho com
 * o logo (embutido no próprio e-mail, aparece mesmo com imagens externas
 * bloqueadas), cartão branco, rodapé. Feito com tabelas e estilos inline
 * (o que Gmail, Outlook e celular entendem).
 */

export const ANEXO_LOGO = { filename: 'primeerp.png', content: Buffer.from(LOGO_EMAIL_PNG_BASE64, 'base64'), cid: 'logo-primeerp', contentType: 'image/png' };

const FONTE = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

export interface ModeloEmail {
  /** Linha cinza de prévia que aparece na caixa de entrada. */
  previa?: string;
  titulo: string;
  /** HTML do corpo (parágrafos simples). */
  corpo: string;
  botao?: { texto: string; url: string };
  /** Texto pequeno abaixo do cartão (ex.: aviso de segurança). */
  nota?: string;
}

export function montarEmail(m: ModeloEmail): string {
  const botao = m.botao
    ? `<table role="presentation" cellpadding="0" cellspacing="0" style="margin:28px 0 4px"><tr><td style="border-radius:12px;background:#1E3FD8">
         <a href="${m.botao.url}" style="display:inline-block;padding:14px 28px;font:600 15px ${FONTE};color:#ffffff;text-decoration:none;border-radius:12px">${m.botao.texto}</a>
       </td></tr></table>`
    : '';
  return `<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light only"><title>${m.titulo}</title></head>
<body style="margin:0;padding:0;background:#EEF2F8">
<span style="display:none!important;opacity:0;color:transparent;height:0;width:0;overflow:hidden">${m.previa ?? ''}</span>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#EEF2F8"><tr><td align="center" style="padding:32px 12px">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px">
    <tr><td style="background:#0E1630;background-image:linear-gradient(135deg,#0E1630 0%,#13265C 60%,#0B5C73 100%);border-radius:20px 20px 0 0;padding:30px 32px" align="left">
      <img src="cid:logo-primeerp" width="200" height="46" alt="PrimeERP" style="display:block;border:0;width:200px;height:auto">
    </td></tr>
    <tr><td style="background:#ffffff;border-radius:0 0 20px 20px;padding:36px 32px 32px;box-shadow:0 8px 30px rgba(14,22,48,.08)">
      <h1 style="margin:0 0 18px;font:700 22px/1.3 ${FONTE};color:#0E1630">${m.titulo}</h1>
      <div style="font:400 15px/1.6 ${FONTE};color:#33405F">${m.corpo}</div>
      ${botao}
    </td></tr>
    ${m.nota ? `<tr><td style="padding:20px 32px 0;font:400 12.5px/1.6 ${FONTE};color:#6B7690">${m.nota}</td></tr>` : ''}
    <tr><td style="padding:22px 32px 0;font:400 12px/1.6 ${FONTE};color:#8A93A8">
      <b style="color:#56627F">PrimeERP Soluções em Tecnologia</b> · Prime Hub<br>
      <a href="https://suporte.primeerp.com.br" style="color:#1E3FD8;text-decoration:none">suporte.primeerp.com.br</a>
    </td></tr>
  </table>
</td></tr></table>
</body></html>`;
}

/** Bloco com o código de acesso (dígitos grandes, espaçados, fácil de copiar). */
export function blocoCodigo(codigo: string): string {
  return `<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:8px 0 6px"><tr>
    <td align="center" style="background:#F2F6FF;border:1px solid #D9E3FB;border-radius:16px;padding:22px 12px">
      <div style="font:600 11px ${FONTE};letter-spacing:2px;text-transform:uppercase;color:#6B7690;margin-bottom:8px">Seu código</div>
      <div style="font:700 38px/1 'SF Mono',Menlo,Consolas,monospace;letter-spacing:12px;color:#1E3FD8;padding-left:12px">${codigo}</div>
    </td></tr></table>`;
}
