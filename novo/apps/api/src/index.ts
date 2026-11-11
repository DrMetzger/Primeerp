import { serve } from '@hono/node-server';
import { Hono } from 'hono';
import { z } from 'zod';
import { exigirLogin, login, type Variaveis } from './auth';
import { respostaApk, versaoAtual } from './app-versao';
import { perguntar } from './assistente';
import { listarChamados } from './chamados';
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
});

app.post('/api/login', async (c) => {
  const dados = esquemaLogin.safeParse(await c.req.json().catch(() => null));
  if (!dados.success) return c.json({ erro: 'Preencha e-mail e senha.' }, 400);
  const sessao = await login(dados.data);
  if (!sessao) return c.json({ erro: 'E-mail ou senha incorretos.' }, 401);
  return c.json(sessao);
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
  }));
});

app.post('/api/classico', exigirLogin(), async (c) => {
  const corpo = z.object({ destino: z.string().max(300) }).safeParse(await c.req.json().catch(() => null));
  if (!corpo.success) return c.json({ erro: 'Destino inválido.' }, 400);
  return c.json({ url: linkClassico(c.get('usuario'), corpo.data.destino) });
});

const esquemaAssistente = z.object({
  mensagem: z.string().trim().min(1).max(1000),
  historico: z.array(z.object({ autor: z.enum(['pessoa', 'assistente']), texto: z.string().max(4000) })).max(20).default([]),
});

app.post('/api/assistente', exigirLogin(), async (c) => {
  const dados = esquemaAssistente.safeParse(await c.req.json().catch(() => null));
  if (!dados.success) return c.json({ erro: 'Escreva sua pergunta.' }, 400);
  const r = await perguntar(c.get('usuario'), dados.data.historico, dados.data.mensagem);
  return 'erro' in r ? c.json(r, 200) : c.json(r);
});

app.get('/api/app/versao', async (c) => {
  const versao = await versaoAtual();
  c.header('cache-control', 'no-store');
  return versao ? c.json(versao) : c.json({ erro: 'Nenhuma versão publicada ainda.' }, 404);
});

app.get('/downloads/primehub.apk', () => respostaApk());

serve({ fetch: app.fetch, port: config.porta }, (info) => {
  console.log(`API do Prime Hub ouvindo na porta ${info.port}`);
});
