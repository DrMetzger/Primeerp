import { createHash, randomBytes, randomInt } from 'node:crypto';
import nodemailer from 'nodemailer';
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
  tabelasProntas = true;
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
  await transporte.sendMail({
    from: `"${remetente}" <${usuario}>`,
    to: para,
    subject: `${codigo} é o seu código de acesso ao Prime Hub`,
    text: `Olá, ${primeiroNome}!\n\nSeu código de acesso ao Prime Hub é: ${codigo}\n\nEle vale por ${VALIDADE_MIN} minutos. Se não foi você que tentou entrar, ignore este e-mail e troque sua senha.\n\nPrimeERP Soluções em Tecnologia`,
    html: `<div style="font-family:Arial,sans-serif;max-width:480px;margin:auto;padding:24px;color:#0E1630">
      <p style="font-size:16px">Olá, ${primeiroNome}!</p>
      <p style="font-size:16px">Seu código de acesso ao <b>Prime Hub</b> é:</p>
      <p style="font-size:34px;font-weight:bold;letter-spacing:10px;background:#EEF4FD;border-radius:12px;padding:16px;text-align:center;color:#1E3FD8">${codigo}</p>
      <p style="font-size:14px;color:#56627F">Ele vale por ${VALIDADE_MIN} minutos. Se não foi você que tentou entrar, ignore este e-mail e troque sua senha.</p>
      <p style="font-size:13px;color:#56627F">PrimeERP Soluções em Tecnologia</p></div>`,
  });
}

/** Cria o desafio e manda o código. Retorna o id do desafio e o e-mail mascarado. */
export async function iniciarDesafio(usuario: UsuarioLogado): Promise<{ desafio: string; destino: string }> {
  await garantirTabelas();
  if (!usuario.email) throw new Error('Seu cadastro está sem e-mail. Fale com a PrimeERP.');
  const recentes = await primeira<{ n: number }>(
    'SELECT COUNT(*) n FROM login_2fa WHERE tipo = ? AND usuario_id = ? AND criado_em > NOW() - INTERVAL 15 MINUTE', [usuario.tipo, usuario.id]);
  if (Number(recentes?.n ?? 0) >= MAX_CODIGOS_15MIN) throw new Error('Muitos códigos pedidos. Aguarde 15 minutos e tente de novo.');

  const codigo = String(randomInt(0, 1_000_000)).padStart(6, '0');
  const desafio = randomBytes(16).toString('hex');
  await consulta(`INSERT INTO login_2fa (id, tipo, usuario_id, usuario_json, codigo_hash, expira_em) VALUES (?, ?, ?, ?, ?, NOW() + INTERVAL ${VALIDADE_MIN} MINUTE)`,
    [desafio, usuario.tipo, usuario.id, JSON.stringify(usuario), hash(`${desafio}:${codigo}`)]);
  await enviarEmail(usuario.email, usuario.nome, codigo);
  return { desafio, destino: mascarar(usuario.email) };
}

/** Confere o código. Sucesso devolve a pessoa; erro devolve a mensagem. */
export async function conferirCodigo(desafio: string, codigo: string): Promise<{ usuario: UsuarioLogado } | { erro: string }> {
  await garantirTabelas();
  const linha = await primeira<{ usuario_json: string; codigo_hash: string; tentativas: number; vencido: number; usado_em: string | null }>(
    'SELECT usuario_json, codigo_hash, tentativas, expira_em < NOW() vencido, usado_em FROM login_2fa WHERE id = ?', [desafio]);
  if (!linha || linha.usado_em) return { erro: 'Código inválido. Entre de novo.' };
  if (Number(linha.vencido)) return { erro: 'O código venceu. Toque em "Reenviar código".' };
  if (linha.tentativas >= TENTATIVAS) return { erro: 'Muitas tentativas erradas. Entre de novo.' };
  if (hash(`${desafio}:${codigo.replace(/\D/g, '')}`) !== linha.codigo_hash) {
    await consulta('UPDATE login_2fa SET tentativas = tentativas + 1 WHERE id = ?', [desafio]);
    const restam = TENTATIVAS - linha.tentativas - 1;
    return { erro: restam > 0 ? `Código incorreto. Restam ${restam} tentativa(s).` : 'Muitas tentativas erradas. Entre de novo.' };
  }
  await consulta('UPDATE login_2fa SET usado_em = NOW() WHERE id = ?', [desafio]);
  return { usuario: JSON.parse(linha.usuario_json) as UsuarioLogado };
}
