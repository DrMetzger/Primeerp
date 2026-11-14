import { createHash, randomBytes, randomInt } from 'node:crypto';
import nodemailer from 'nodemailer';
import { ANEXO_LOGO, blocoCodigo, montarEmail } from './email-modelo';
import type { UsuarioLogado } from '@primehub/shared';
import { consulta, pool, primeira } from './db';

/**
 * Verificação em duas etapas do login: senha certa -> código de 6 dígitos
 * no e-mail da pessoa (válido 10 min, 5 tentativas). "Lembrar este
 * aparelho" guarda um token de dispositivo (30 dias) pra não pedir o código
 * toda vez. No banco só ficam hashes (sha256), nunca o código ou o token.
 */

const VALIDADE_MIN = 10;
const TENTATIVAS = 5;
const DIAS_DISPOSITIVO = 30;
const MAX_CODIGOS_15MIN = 5;

const hash = (v: string) => createHash('sha256').update(v).digest('hex');

let tabelasProntas = false;
async function garantirTabelas(): Promise<void> {
  if (tabelasProntas) return;
  await pool.query(`CREATE TABLE IF NOT EXISTS login_2fa (
    id CHAR(32) PRIMARY KEY,
    tipo VARCHAR(10) NOT NULL,
    usuario_id INT NOT NULL,
    usuario_json TEXT NOT NULL,
    codigo_hash CHAR(64) NOT NULL,
    tentativas TINYINT NOT NULL DEFAULT 0,
    expira_em DATETIME NOT NULL,
    usado_em DATETIME NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_usuario (tipo, usuario_id, criado_em)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`);
  await pool.query(`CREATE TABLE IF NOT EXISTS login_dispositivos (
    token_hash CHAR(64) PRIMARY KEY,
    tipo VARCHAR(10) NOT NULL,
    usuario_id INT NOT NULL,
    descricao VARCHAR(200) NULL,
    expira_em DATETIME NOT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_usuario (tipo, usuario_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`);
  await pool.query(`CREATE TABLE IF NOT EXISTS whatsapp_janela (
    telefone VARCHAR(20) PRIMARY KEY,
    ultima_msg_em DATETIME NOT NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`);
  tabelasProntas = true;
}

/** Guarda quando cada número escreveu para nós (janela de 24h da Meta). */
export async function registrarMensagemRecebida(telefone: string): Promise<void> {
  await garantirTabelas();
  await consulta('INSERT INTO whatsapp_janela (telefone, ultima_msg_em) VALUES (?, NOW()) ON DUPLICATE KEY UPDATE ultima_msg_em = NOW()', [telefone.replace(/\D/g, '').slice(0, 20)]);
}

export async function dispositivoConfiavel(usuario: UsuarioLogado, token: string | undefined): Promise<boolean> {
  if (!token) return false;
  await garantirTabelas();
  const linha = await primeira<{ n: number }>(
    'SELECT COUNT(*) n FROM login_dispositivos WHERE token_hash = ? AND tipo = ? AND usuario_id = ? AND expira_em > NOW()',
    [hash(token), usuario.tipo, usuario.id]);
  return Number(linha?.n ?? 0) > 0;
}

export async function lembrarDispositivo(usuario: UsuarioLogado, descricao: string): Promise<string> {
  await garantirTabelas();
  const token = randomBytes(32).toString('hex');
  await consulta(`INSERT INTO login_dispositivos (token_hash, tipo, usuario_id, descricao, expira_em) VALUES (?, ?, ?, ?, NOW() + INTERVAL ${DIAS_DISPOSITIVO} DAY)`,
    [hash(token), usuario.tipo, usuario.id, descricao.slice(0, 200)]);
  return token;
}

/** "kleber.farias@primeerp.com.br" -> "k***s@primeerp.com.br" */
function mascarar(email: string): string {
  const [nome = '', dominio = ''] = email.split('@');
  return `${nome.slice(0, 1)}***${nome.length > 2 ? nome.slice(-1) : ''}@${dominio}`;
}

async function config(chave: string, env: string, padrao = ''): Promise<string> {
  const linha = await primeira<{ valor: string | null }>('SELECT valor FROM configuracoes WHERE chave = ?', [chave]).catch(() => null);
  return linha?.valor?.trim() || process.env[env] || padrao;
}

async function enviarEmail(para: string, nome: string, codigo: string): Promise<void> {
  const host = await config('smtp_host', 'SMTP_HOST');
  const usuario = await config('smtp_user', 'SMTP_USER');
  if (process.env.SMTP_TESTE_LOG === '1') {
    console.log(`[2FA teste] código para ${para}: ${codigo}`);
    return;
  }
  if (!host || !usuario) throw new Error('E-mail (SMTP) não configurado em Configurações.');
  const porta = Number(await config('smtp_port', 'SMTP_PORT', '587'));
  const seguro = await config('smtp_secure', 'SMTP_SECURE', 'tls');
  const transporte = nodemailer.createTransport({
    host,
    port: porta,
    secure: seguro === 'ssl' || porta === 465,
    auth: { user: usuario, pass: await config('smtp_pass', 'SMTP_PASS') },
    connectionTimeout: 15_000,
  });
  const remetente = await config('smtp_nome_remetente', 'SMTP_NOME', 'Prime Hub');
  const primeiroNome = nome.split(' ')[0] ?? '';
  const envio = await transporte.sendMail({
    from: `"${remetente}" <${usuario}>`,
    to: para,
    subject: 'Seu código de acesso ao Prime Hub',
    text: `Olá, ${primeiroNome}!\n\nSeu código de acesso ao Prime Hub é: ${codigo}\n\nEle vale por ${VALIDADE_MIN} minutos. Se não foi você que tentou entrar, ignore este e-mail e troque sua senha.\n\nPrimeERP Soluções em Tecnologia`,
    html: montarEmail({
      previa: `${codigo} é o seu código de acesso ao Prime Hub`,
      titulo: `Olá, ${primeiroNome}! 👋`,
      corpo: `<p style="margin:0 0 18px">Use o código abaixo para entrar no <b>Prime Hub</b>:</p>${blocoCodigo(codigo)}
        <p style="margin:16px 0 0;color:#6B7690;font-size:14px">⏱️ Vale por <b>${VALIDADE_MIN} minutos</b> e só funciona uma vez.</p>`,
      nota: '🔒 Se não foi você que tentou entrar, ignore este e-mail e troque sua senha. A PrimeERP nunca pede esse código por telefone.',
    }),
    attachments: [ANEXO_LOGO],
  });
  // Sem endereço no log: só se o servidor aceitou.
  console.log(`2FA: e-mail aceito pelo servidor (${String(envio.response ?? '').slice(0, 80)})`);
}

/** Telefone cadastrado da pessoa (equipe, master do cliente ou responsável de setor). */
async function telefoneDe(u: UsuarioLogado): Promise<string> {
  const l = u.tipo === 'equipe'
    ? await primeira<{ t: string | null }>('SELECT telefone t FROM usuarios WHERE id = ?', [u.id])
    : u.perfil === 'master'
      ? await primeira<{ t: string | null }>('SELECT contato_telefone t FROM clientes WHERE id = ?', [u.id])
      : await primeira<{ t: string | null }>('SELECT telefone t FROM setor_responsaveis WHERE id = ?', [u.id]);
  const d = (l?.t ?? '').replace(/\D/g, '');
  return d.length === 10 || d.length === 11 ? `55${d}` : d;
}

/**
 * Código pelo WhatsApp oficial da PrimeERP. A Meta só ENTREGA texto livre
 * para quem falou com o número nas últimas 24h; fora disso aceita o pedido
 * e descarta calada. Por isso: com o modelo de autenticação aprovado
 * (Configurações: whatsapp_template_codigo) usa o modelo, que chega sempre;
 * sem modelo, só manda se a pessoa escreveu nas últimas 24h -- e se não
 * der, avisa a verdade em vez de dizer que enviou.
 */
async function enviarWhatsApp(telefone: string, nome: string, codigo: string): Promise<void> {
  const token = await config('whatsapp_access_token', 'WHATSAPP_ACCESS_TOKEN');
  const numeroId = await config('whatsapp_phone_number_id', 'WHATSAPP_PHONE_NUMBER_ID');
  if (!token || !numeroId) throw new Error('O WhatsApp da PrimeERP não está configurado. Use o e-mail.');
  const modelo = await config('whatsapp_template_codigo', 'WHATSAPP_TEMPLATE_CODIGO');
  const corpo = modelo
    ? { messaging_product: 'whatsapp', to: telefone, type: 'template', template: { name: modelo, language: { code: 'pt_BR' }, components: [
        { type: 'body', parameters: [{ type: 'text', text: codigo }] },
        { type: 'button', sub_type: 'url', index: '0', parameters: [{ type: 'text', text: codigo }] },
      ] } }
    : { messaging_product: 'whatsapp', to: telefone, type: 'text', text: { body: `Olá, ${nome.split(' ')[0] ?? ''}! Seu código de acesso ao Prime Hub é: *${codigo}*\n\nVale por ${VALIDADE_MIN} minutos. Não compartilhe com ninguém.` } };
  if (!modelo && !(await falouNasUltimas24h(telefone))) {
    throw new Error('Pelo WhatsApp o código só chega se você mandou uma mensagem para o WhatsApp da PrimeERP nas últimas 24 horas. Mande um "oi" para o nosso WhatsApp e toque de novo em "Receber o código pelo WhatsApp", ou use o código do e-mail.');
  }
  const r = await fetch(`https://graph.facebook.com/v21.0/${numeroId}/messages`, {
    method: 'POST', headers: { authorization: `Bearer ${token}`, 'content-type': 'application/json' }, body: JSON.stringify(corpo), signal: AbortSignal.timeout(15_000),
  });
  if (!r.ok) {
    console.error(`2FA: WhatsApp recusou (HTTP ${r.status}) ${(await r.text().catch(() => '')).slice(0, 200)}`);
    throw new Error('O WhatsApp não aceitou enviar agora. Use o código do e-mail (o mais recente ou um anterior, ambos valem por 10 minutos).');
  }
}

/** A pessoa escreveu para o WhatsApp da PrimeERP nas últimas 24h? (janela em que a Meta entrega texto livre) */
async function falouNasUltimas24h(telefone: string): Promise<boolean> {
  const fim = telefone.slice(-8);
  const l = await primeira<{ n: number }>('SELECT COUNT(*) n FROM whatsapp_janela WHERE telefone LIKE ? AND ultima_msg_em > NOW() - INTERVAL 23 HOUR', [`%${fim}`]).catch(() => null);
  return Number(l?.n ?? 0) > 0;
}

/** Cria o desafio e manda o código. Retorna o id do desafio e o e-mail mascarado. */
export async function iniciarDesafio(usuario: UsuarioLogado, canal: 'email' | 'whatsapp' = 'email'): Promise<{ desafio: string; destino: string }> {
  await garantirTabelas();
  if (!usuario.email) throw new Error('Seu cadastro está sem e-mail. Fale com a PrimeERP.');
  const recentes = await primeira<{ n: number }>(
    'SELECT COUNT(*) n FROM login_2fa WHERE tipo = ? AND usuario_id = ? AND criado_em > NOW() - INTERVAL 15 MINUTE', [usuario.tipo, usuario.id]);
  if (Number(recentes?.n ?? 0) >= MAX_CODIGOS_15MIN) throw new Error('Muitos códigos pedidos. Aguarde 15 minutos e tente de novo.');

  const codigo = String(randomInt(0, 1_000_000)).padStart(6, '0');
  const desafio = randomBytes(16).toString('hex');
  await consulta(`INSERT INTO login_2fa (id, tipo, usuario_id, usuario_json, codigo_hash, expira_em) VALUES (?, ?, ?, ?, ?, NOW() + INTERVAL ${VALIDADE_MIN} MINUTE)`,
    [desafio, usuario.tipo, usuario.id, JSON.stringify(usuario), hash(`${desafio}:${codigo}`)]);
  // Se não deu para enviar, o pedido não conta no limite nem fica valendo.
  const desfazer = async (e: unknown) => { await consulta('DELETE FROM login_2fa WHERE id = ?', [desafio]).catch(() => undefined); throw e; };
  if (canal === 'whatsapp') {
    const tel = await telefoneDe(usuario);
    if (tel.length < 12) await desfazer(new Error('Seu cadastro está sem telefone/WhatsApp. Use o e-mail ou peça para a PrimeERP cadastrar.'));
    await enviarWhatsApp(tel, usuario.nome, codigo).catch(desfazer);
    return { desafio, destino: `WhatsApp final ${tel.slice(-4)}` };
  }
  await enviarEmail(usuario.email, usuario.nome, codigo).catch(desfazer);
  return { desafio, destino: mascarar(usuario.email) };
}

/**
 * Confere o código. Vale QUALQUER código ainda válido que a pessoa pediu
 * para o mesmo login (o e-mail que chegou atrasado depois de um "Reenviar"
 * ou o do WhatsApp): e-mail lento não pode trancar ninguém do lado de fora.
 */
export async function conferirCodigo(desafio: string, codigo: string): Promise<{ usuario: UsuarioLogado } | { erro: string }> {
  await garantirTabelas();
  const linha = await primeira<{ tipo: string; usuario_id: number; usuario_json: string; codigo_hash: string; tentativas: number; vencido: number; usado_em: string | null }>(
    'SELECT tipo, usuario_id, usuario_json, codigo_hash, tentativas, expira_em < NOW() vencido, usado_em FROM login_2fa WHERE id = ?', [desafio]);
  if (!linha || linha.usado_em) return { erro: 'Código inválido. Entre de novo.' };
  if (linha.tentativas >= TENTATIVAS) return { erro: 'Muitas tentativas erradas. Entre de novo.' };
  const digitos = codigo.replace(/\D/g, '');
  const validos = await consulta<{ id: string; codigo_hash: string }>(
    'SELECT id, codigo_hash FROM login_2fa WHERE tipo = ? AND usuario_id = ? AND usado_em IS NULL AND expira_em > NOW() ORDER BY criado_em DESC LIMIT 10', [linha.tipo, linha.usuario_id]);
  const certo = validos.find((v) => hash(`${v.id}:${digitos}`) === v.codigo_hash);
  if (!certo) {
    await consulta('UPDATE login_2fa SET tentativas = tentativas + 1 WHERE id = ?', [desafio]);
    if (Number(linha.vencido) && !validos.length) return { erro: 'O código venceu. Toque em "Reenviar código".' };
    const restam = TENTATIVAS - linha.tentativas - 1;
    return { erro: restam > 0 ? `Código incorreto. Restam ${restam} tentativa(s).` : 'Muitas tentativas erradas. Entre de novo.' };
  }
  // Login feito: todos os códigos pendentes desse login deixam de valer.
  await consulta('UPDATE login_2fa SET usado_em = NOW() WHERE tipo = ? AND usuario_id = ? AND usado_em IS NULL', [linha.tipo, linha.usuario_id]);
  return { usuario: JSON.parse(linha.usuario_json) as UsuarioLogado };
}
