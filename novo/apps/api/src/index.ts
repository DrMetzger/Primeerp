import { serve } from '@hono/node-server';
import { Hono } from 'hono';
import { z } from 'zod';
import { emitirSessao, exigirLogin, verificarCredenciais, type Variaveis, clienteForaDoHorario, MSG_FORA_HORARIO } from './auth';
import { conferirCodigo, dispositivoConfiavel, iniciarDesafio, lembrarDispositivo } from './dois-fatores';
import { primeira } from './db';
import type { UsuarioLogado } from '@primehub/shared';
import { respostaApk, versaoAtual } from './app-versao';
import { perguntar } from './assistente';
import { listarChamados } from './chamados';
import { detalharChamado, detalharCliente, detalharSetor, listarClientes } from './clientes';
import { garantirEstrutura } from './pessoas';
import { lerPerfil, listarFontesGlobais, listarUsuarios, montarAgenda, portalCalendario, portalEquipe, portalFontes, portalVideoaulas } from './modulos';
import { config } from './config';
import { montarDashboard } from './dashboard';
import { linkClassico } from './sso';

const app = new Hono<{ Variables: Variaveis }>();

app.onError((erro, c) => {
  console.error(erro);
  return c.json({ erro: 'Erro interno. Tente de novo.' }, 500);
});

app.get('/api/saude', (c) => c.json({ ok: true }));

const esquemaLogin = z.object({
  tipo: z.enum(['equipe', 'cliente']),
  email: z.string().email().max(200),
  senha: z.string().min(1).max(200),
  dispositivo: z.string().max(100).optional(),
});

app.post('/api/login', async (c) => {
  const dados = esquemaLogin.safeParse(await c.req.json().catch(() => null));
  if (!dados.success) return c.json({ erro: 'Preencha e-mail e senha.' }, 400);
  const { dispositivo, ...credenciais } = dados.data;
  const usuario = await verificarCredenciais(credenciais);
  if (!usuario) return c.json({ erro: 'E-mail ou senha incorretos.' }, 401);
  if (usuario.tipo === 'cliente' && await clienteForaDoHorario(usuario.clienteId)) return c.json({ erro: MSG_FORA_HORARIO, foraDoHorario: true }, 503);
  // Aparelho já lembrado: entra direto. Senão: código no e-mail.
  if (await dispositivoConfiavel(usuario, dispositivo)) return c.json(await emitirSessao(usuario));
  try {
    return c.json({ etapa: 'codigo', ...(await iniciarDesafio(usuario)) });
  } catch (e) {
    return c.json({ erro: e instanceof Error ? e.message : 'Não foi possível enviar o código.' }, 503);
  }
});

const esquemaCodigo = z.object({
  desafio: z.string().regex(/^[0-9a-f]{32}$/),
  codigo: z.string().min(6).max(12),
  lembrar: z.boolean().default(false),
  aparelho: z.string().max(200).default(''),
});

app.post('/api/login/codigo', async (c) => {
  const dados = esquemaCodigo.safeParse(await c.req.json().catch(() => null));
  if (!dados.success) return c.json({ erro: 'Digite os 6 números do código.' }, 400);
  const r = await conferirCodigo(dados.data.desafio, dados.data.codigo);
  if ('erro' in r) return c.json(r, 401);
  const sessao = await emitirSessao(r.usuario);
  if (dados.data.lembrar) sessao.dispositivo = await lembrarDispositivo(r.usuario, dados.data.aparelho || (c.req.header('user-agent') ?? ''));
  return c.json(sessao);
});

app.post('/api/login/reenviar', async (c) => {
  const corpo = z.object({ desafio: z.string().regex(/^[0-9a-f]{32}$/), canal: z.enum(['email', 'whatsapp']).default('email') }).safeParse(await c.req.json().catch(() => null));
  if (!corpo.success) return c.json({ erro: 'Entre de novo.' }, 400);
  const linha = await primeira<{ usuario_json: string }>('SELECT usuario_json FROM login_2fa WHERE id = ? AND usado_em IS NULL AND criado_em > NOW() - INTERVAL 30 MINUTE', [corpo.data.desafio]);
  if (!linha) return c.json({ erro: 'Entre de novo.' }, 400);
  try {
    return c.json({ etapa: 'codigo', ...(await iniciarDesafio(JSON.parse(linha.usuario_json) as UsuarioLogado, corpo.data.canal)) });
  } catch (e) {
    return c.json({ erro: e instanceof Error ? e.message : 'Não foi possível reenviar.' }, 503);
  }
});

app.get('/api/eu', exigirLogin(), (c) => c.json(c.get('usuario')));

app.get('/api/dashboard', exigirLogin('equipe'), async (c) => c.json(await montarDashboard(c.get('usuario'))));

app.get('/api/chamados', exigirLogin(), async (c) => {
  const pagina = Number(c.req.query('pagina') ?? 1);
  const status = c.req.query('status');
  const busca = c.req.query('busca');
  return c.json(await listarChamados(c.get('usuario'), {
    pagina: Number.isFinite(pagina) ? pagina : 1,
    ...(status ? { status } : {}),
    ...(busca ? { busca } : {}),
    ...(Number(c.req.query('cliente')) > 0 ? { clienteId: Number(c.req.query('cliente')) } : {}),
    ...(Number(c.req.query('setor')) > 0 ? { setorId: Number(c.req.query('setor')) } : {}),
  }));
});

app.get('/api/clientes', exigirLogin('equipe'), async (c) => c.json(await listarClientes(c.get('usuario'), c.req.query('busca'))));

app.get('/api/clientes/:id', exigirLogin('equipe'), async (c) => {
  const d = await detalharCliente(c.get('usuario'), Number(c.req.param('id')));
  return d ? c.json(d) : c.json({ erro: 'Cliente não encontrado.' }, 404);
});

app.get('/api/clientes/:id/setores/:setor', exigirLogin('equipe'), async (c) => {
  const d = await detalharSetor(c.get('usuario'), Number(c.req.param('id')), Number(c.req.param('setor')));
  return d ? c.json(d) : c.json({ erro: 'Setor não encontrado.' }, 404);
});

app.get('/api/chamados/:id', exigirLogin(), async (c) => {
  const d = await detalharChamado(c.get('usuario'), Number(c.req.param('id')));
  return d ? c.json(d) : c.json({ erro: 'Chamado não encontrado.' }, 404);
});

const ADMIN = ['admin', 'diretor', 'ceo'];
app.get('/api/agenda', exigirLogin('equipe'), async (c) => c.json(await montarAgenda(c.req.query('de') ?? '', c.req.query('ate') ?? '', c.get('usuario').id)));
app.get('/api/fontes', exigirLogin('equipe'), async (c) => c.json(await listarFontesGlobais()));
app.get('/api/usuarios', exigirLogin('equipe'), async (c) => {
  if (!ADMIN.includes(c.get('usuario').perfil ?? '')) return c.json({ erro: 'Só admin, diretoria e CEO.' }, 403);
  return c.json(await listarUsuarios());
});
app.get('/api/perfil', exigirLogin(), async (c) => c.json(await lerPerfil(c.get('usuario'))));
app.get('/api/portal/calendario', exigirLogin('cliente'), async (c) => c.json(await portalCalendario(c.get('usuario'))));
app.get('/api/portal/videoaulas', exigirLogin('cliente'), async (c) => c.json(await portalVideoaulas(c.get('usuario'))));
app.get('/api/portal/fontes', exigirLogin('cliente'), async (c) => c.json(await portalFontes(c.get('usuario'))));
app.get('/api/portal/equipe', exigirLogin('cliente'), async (c) => c.json(await portalEquipe(c.get('usuario'))));

app.post('/api/classico', exigirLogin(), async (c) => {
  const corpo = z.object({ destino: z.string().max(300) }).safeParse(await c.req.json().catch(() => null));
  if (!corpo.success) return c.json({ erro: 'Destino inválido.' }, 400);
  return c.json({ url: linkClassico(c.get('usuario'), corpo.data.destino) });
});

const esquemaAssistente = z.object({
  mensagem: z.string().trim().min(1).max(1000),
  historico: z.array(z.object({ autor: z.enum(['pessoa', 'assistente']), texto: z.string().max(4000) })).max(20).default([]),
  tela: z.object({ rota: z.string().max(300), titulo: z.string().max(200), texto: z.string().max(8000) }).optional(),
});

app.post('/api/assistente', exigirLogin(), async (c) => {
  const dados = esquemaAssistente.safeParse(await c.req.json().catch(() => null));
  if (!dados.success) return c.json({ erro: 'Escreva sua pergunta.' }, 400);
  const r = await perguntar(c.get('usuario'), dados.data.historico, dados.data.mensagem, dados.data.tela);
  return 'erro' in r ? c.json(r, 200) : c.json(r);
});

app.get('/api/app/versao', async (c) => {
  const versao = await versaoAtual();
  c.header('cache-control', 'no-store');
  return versao ? c.json(versao) : c.json({ erro: 'Nenhuma versão publicada ainda.' }, 404);
});

app.get('/downloads/primehub.apk', () => respostaApk());

void garantirEstrutura();
serve({ fetch: app.fetch, port: config.porta }, (info) => {
  console.log(`API do Prime Hub ouvindo na porta ${info.port}`);
});
