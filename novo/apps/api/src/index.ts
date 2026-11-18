import { serve } from '@hono/node-server';
import { type Context, Hono } from 'hono';
import { z } from 'zod';
import { emitirSessao, exigirLogin, verificarCredenciais, type Variaveis, clienteForaDoHorario, MSG_FORA_HORARIO } from './auth';
import { conferirCodigo, dispositivoConfiavel, iniciarDesafio, lembrarDispositivo } from './dois-fatores';
import { primeira } from './db';
import type { UsuarioLogado } from '@primehub/shared';
import { converterRelatorio } from '@primehub/shared';
import { respostaApk, versaoAtual } from './app-versao';
import { perguntar } from './assistente';
import { listarChamados } from './chamados';
import { detalharChamado, detalharCliente, detalharSetor, listarClientes } from './clientes';
import { garantirEstrutura } from './pessoas';
import { lerPerfil, listarFontesGlobais, listarUsuarios, montarAgenda, portalCalendario, portalEquipe, portalFontes, portalVideoaulas } from './modulos';
import { config } from './config';
import { montarDashboard } from './dashboard';
import { linkClassico } from './sso';
import { assinaturaValida, processarWebhook } from './whatsapp-bot';
import { agente, ErroVm, podePainelVm } from './vm-servicos';
import { anexoAgente, aprovarExecucao, arquivoDaProposta, conferirTokenAgente, descartarProposta, detalheAgente, enviarProposta, ErroAgente, filaAgente, garantirEstruturaAgente, gerarTokenAgente, mesaDoAgente, registrarProposta, type Proposta } from './agente';
import { SignJWT, jwtVerify } from 'jose';
import { baixarVpn, conferirCofre, ErroCofre, recifrarCofre, excluirCredencial, excluirVpn, excluirVscode, lerCofre, revelarSenha, salvarAmbienteTcloud, salvarCredencial, salvarVpn, salvarVscode } from './acessos';

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

// ---------------------------------------------------------------- Cofre de acessos do cliente
// (senhas, VPN, links T-Cloud) -- só no sistema novo, sem PHP.
const cofre = async (c: Context, f: () => Promise<unknown>) => {
  try { return c.json((await f()) as object); }
  catch (e) {
    if (e instanceof ErroCofre) return c.json({ erro: e.message }, e.status);
    console.error('cofre:', e instanceof Error ? e.message : e);
    return c.json({ erro: 'Não deu certo agora. Tente de novo em instantes.' }, 500);
  }
};
const idDe = (v: string | undefined) => { const n = Number(v); return Number.isInteger(n) && n > 0 ? n : 0; };
const corpoJson = async (c: { req: { json: () => Promise<unknown> } }) => ((await c.req.json().catch(() => ({}))) ?? {}) as Record<string, unknown>;

app.get('/api/clientes/:id/acessos', exigirLogin('equipe'), (c) => cofre(c, () => lerCofre(c.get('usuario'), idDe(c.req.param('id')))));
app.post('/api/clientes/:id/acessos/credenciais/:cid/senha', exigirLogin('equipe'), (c) => cofre(c, async () => ({ senha: await revelarSenha(c.get('usuario'), idDe(c.req.param('id')), idDe(c.req.param('cid'))) })));
app.post('/api/clientes/:id/acessos/credenciais', exigirLogin('equipe'), (c) => cofre(c, async () => ({ mensagem: await salvarCredencial(c.get('usuario'), idDe(c.req.param('id')), null, await corpoJson(c)) })));
app.put('/api/clientes/:id/acessos/credenciais/:cid', exigirLogin('equipe'), (c) => cofre(c, async () => ({ mensagem: await salvarCredencial(c.get('usuario'), idDe(c.req.param('id')), idDe(c.req.param('cid')), await corpoJson(c)) })));
app.delete('/api/clientes/:id/acessos/credenciais/:cid', exigirLogin('equipe'), (c) => cofre(c, async () => ({ mensagem: await excluirCredencial(c.get('usuario'), idDe(c.req.param('id')), idDe(c.req.param('cid'))) })));
app.post('/api/clientes/:id/acessos/vpn', exigirLogin('equipe'), (c) => cofre(c, async () => {
  const f = await c.req.parseBody();
  const id = idDe(typeof f.id === 'string' ? f.id : undefined) || null;
  const arquivo = f.arquivo instanceof File && f.arquivo.size > 0 ? f.arquivo : null;
  return { mensagem: await salvarVpn(c.get('usuario'), idDe(c.req.param('id')), id, typeof f.titulo === 'string' ? f.titulo : '', arquivo) };
}));
app.delete('/api/clientes/:id/acessos/vpn/:vid', exigirLogin('equipe'), (c) => cofre(c, async () => ({ mensagem: await excluirVpn(c.get('usuario'), idDe(c.req.param('id')), idDe(c.req.param('vid'))) })));
// Download: link de 2 minutos (funciona no navegador e no celular), permissão conferida de novo na hora de baixar.
app.post('/api/clientes/:id/acessos/vpn/:vid/link', exigirLogin('equipe'), (c) => cofre(c, async () => {
  const token = await new SignJWT({ u: c.get('usuario'), cl: idDe(c.req.param('id')), v: idDe(c.req.param('vid')) }).setProtectedHeader({ alg: 'HS256' }).setSubject('vpn').setExpirationTime('2m').sign(config.jwtSegredo);
  return { url: `/api/arquivo-vpn?t=${encodeURIComponent(token)}` };
}));
app.get('/api/arquivo-vpn', async (c) => {
  try {
    const { payload } = await jwtVerify(c.req.query('t') ?? '', config.jwtSegredo, { subject: 'vpn' });
    const a = await baixarVpn(payload.u as UsuarioLogado, Number(payload.cl), Number(payload.v));
    return new Response(new Uint8Array(a.bytes), { headers: { 'content-type': 'application/octet-stream', 'content-disposition': `attachment; filename="${a.nome.replace(/["\r\n]/g, '')}"`, 'cache-control': 'private, no-store' } });
  } catch (e) {
    return c.text(e instanceof ErroCofre ? e.message : 'Link expirado. Volte e toque em baixar de novo.', e instanceof ErroCofre ? e.status : 403);
  }
});
app.put('/api/clientes/:id/acessos/tcloud/:tipo', exigirLogin('equipe'), (c) => cofre(c, async () => ({ mensagem: await salvarAmbienteTcloud(c.get('usuario'), idDe(c.req.param('id')), c.req.param('tipo'), await corpoJson(c)) })));
app.post('/api/clientes/:id/acessos/vscode', exigirLogin('equipe'), (c) => cofre(c, async () => ({ mensagem: await salvarVscode(c.get('usuario'), idDe(c.req.param('id')), null, await corpoJson(c)) })));
app.put('/api/clientes/:id/acessos/vscode/:sid', exigirLogin('equipe'), (c) => cofre(c, async () => ({ mensagem: await salvarVscode(c.get('usuario'), idDe(c.req.param('id')), idDe(c.req.param('sid')), await corpoJson(c)) })));
app.delete('/api/clientes/:id/acessos/vscode/:sid', exigirLogin('equipe'), (c) => cofre(c, async () => ({ mensagem: await excluirVscode(c.get('usuario'), idDe(c.req.param('id')), idDe(c.req.param('sid'))) })));

// ---------------------------------------------------------------- Agente Claude dos chamados
// O agente (sessões do Claude que a equipe acompanha) usa o cabeçalho
// X-Agente-Token; a equipe revisa e aprova pela Mesa do agente. Nada vai
// ao cliente sem uma pessoa aprovar.
const agenteResp = async (c: Context, f: () => Promise<unknown>) => {
  try { return c.json((await f()) as object); }
  catch (e) {
    if (e instanceof ErroAgente) return c.json({ erro: e.message }, e.status);
    console.error('agente:', e instanceof Error ? e.message : e);
    return c.json({ erro: 'Não deu certo agora. Tente de novo em instantes.' }, 500);
  }
};
const soAgente = async (c: Context, f: () => Promise<unknown>) => agenteResp(c, async () => { await conferirTokenAgente(c.req.header('x-agente-token')); return f(); });
const arquivoResp = (a: { nome: string; bytes: Buffer }) => new Response(new Uint8Array(a.bytes), { headers: { 'content-type': 'application/octet-stream', 'content-disposition': `attachment; filename="${a.nome.replace(/["\r\n]/g, '')}"`, 'cache-control': 'private, no-store' } });

app.get('/api/agente/fila', (c) => soAgente(c, () => filaAgente(Number(c.req.query('limite') ?? 3))));
app.get('/api/agente/chamados/:id', (c) => soAgente(c, () => detalheAgente(idDe(c.req.param('id')))));
app.get('/api/agente/anexos/:id', async (c) => {
  try { await conferirTokenAgente(c.req.header('x-agente-token')); return arquivoResp(await anexoAgente(idDe(c.req.param('id')))); }
  catch (e) { return c.json({ erro: e instanceof Error ? e.message : 'erro' }, e instanceof ErroAgente ? e.status : 500); }
});
app.post('/api/agente/chamados/:id/proposta', (c) => soAgente(c, async () => registrarProposta(idDe(c.req.param('id')), (await corpoJson(c)) as unknown as Proposta)));

app.get('/api/agente/mesa', exigirLogin('equipe'), (c) => agenteResp(c, () => mesaDoAgente()));
app.post('/api/agente/propostas/:pid/enviar', exigirLogin('equipe'), (c) => agenteResp(c, async () => {
  const b = await corpoJson(c);
  return enviarProposta(idDe(c.req.param('pid')), c.get('usuario'), typeof b.mensagem === 'string' ? b.mensagem : null, typeof b.status === 'string' ? b.status : null, b.comoAtendente !== false);
}));
app.post('/api/agente/propostas/:pid/executar', exigirLogin('equipe'), (c) => agenteResp(c, async () => {
  const b = await corpoJson(c);
  await aprovarExecucao(idDe(c.req.param('pid')), c.get('usuario'), String(b.tipo ?? ''), String(b.instrucoes ?? ''));
  return { mensagem: 'Aprovado. O agente vai executar na próxima rodada e devolver para você revisar.' };
}));
app.post('/api/agente/propostas/:pid/descartar', exigirLogin('equipe'), (c) => agenteResp(c, async () => { await descartarProposta(idDe(c.req.param('pid'))); return { mensagem: 'Proposta descartada.' }; }));
app.post('/api/agente/arquivos/:aid/link', exigirLogin('equipe'), (c) => agenteResp(c, async () => {
  const t = await new SignJWT({ a: idDe(c.req.param('aid')) }).setProtectedHeader({ alg: 'HS256' }).setSubject('agente-arquivo').setExpirationTime('2m').sign(config.jwtSegredo);
  return { url: `/api/agente/arquivo?t=${encodeURIComponent(t)}` };
}));
app.get('/api/agente/arquivo', async (c) => {
  try { const { payload } = await jwtVerify(c.req.query('t') ?? '', config.jwtSegredo, { subject: 'agente-arquivo' }); return arquivoResp(await arquivoDaProposta(Number(payload.a))); }
  catch { return c.text('Link expirado. Volte e toque em baixar de novo.', 403); }
});
app.post('/api/agente/token', exigirLogin('equipe'), (c) => agenteResp(c, async () => {
  if (!['admin', 'diretor', 'ceo'].includes(c.get('usuario').perfil ?? '')) throw new ErroAgente('Só administrador gera o token do agente.', 403);
  return { token: await gerarTokenAgente() };
}));

// Converte um fonte de relatório ADVPL para User Function (padrão PrimeERP).
// Só prepara o texto; compilar/gerar patch é outro passo, revisado por pessoa.
app.post('/api/compilacao/converter', exigirLogin('equipe'), async (c) => {
  const b = await corpoJson(c);
  const fonte = typeof b.fonte === 'string' ? b.fonte : '';
  const nome = (typeof b.nome === 'string' ? b.nome : '').trim();
  if (!fonte.trim() || !nome) return c.json({ erro: 'Informe o fonte e o nome do programa (ex.: MATR435).' }, 400);
  if (!/^[A-Za-z_]\w{0,19}(\.(prw|tlpp|prx))?$/i.test(nome)) return c.json({ erro: 'Nome de programa inválido.' }, 400);
  return c.json(converterRelatorio(fonte, nome.replace(/\.(prw|tlpp|prx)$/i, '')));
});

// ---------------------------------------------------------------- Painel da VM (Protheus e serviços)
const vmResp = async (c: Context, f: () => Promise<unknown>) => {
  if (!podePainelVm(c.get('usuario'))) return c.json({ erro: 'Só administrador, diretor ou CEO.' }, 403);
  try { return c.json((await f()) as object); }
  catch (e) { return c.json({ erro: e instanceof Error ? e.message : 'Falhou.' }, e instanceof ErroVm ? e.status : 500); }
};
const SERVICO_VM = /^[a-z]{3,20}$/;
app.get('/api/vm/servicos', exigirLogin('equipe'), (c) => vmResp(c, () => agente('GET', '/servicos')));
app.get('/api/vm/servicos/:id/log', exigirLogin('equipe'), (c) => vmResp(c, () => {
  const id = c.req.param('id');
  if (!SERVICO_VM.test(id)) throw new ErroVm('Serviço inválido.', 400);
  return agente('GET', `/servicos/${id}/log`);
}));
app.post('/api/vm/servicos/:id/:acao', exigirLogin('equipe'), (c) => vmResp(c, () => {
  const { id, acao } = c.req.param();
  if (!SERVICO_VM.test(id) || !['iniciar', 'parar', 'reiniciar'].includes(acao)) throw new ErroVm('Ação inválida.', 400);
  const u = c.get('usuario');
  console.log(`painel VM: ${u.nome} (#${u.id}) -> ${acao} ${id}`);
  return agente('POST', `/servicos/${id}/${acao}`, `${u.nome} #${u.id}`);
}));
app.get('/api/vm/tarefas', exigirLogin('equipe'), (c) => vmResp(c, () => agente('GET', '/tarefas')));
app.get('/api/vm/tarefas/:id', exigirLogin('equipe'), (c) => vmResp(c, () => {
  const id = c.req.param('id');
  if (!/^[a-f0-9]{12}$/.test(id)) throw new ErroVm('Tarefa inválida.', 400);
  return agente('GET', `/tarefas/${id}`);
}));
/** Versão do Protheus para o menu (qualquer pessoa da equipe vê). */
app.get('/api/vm/protheus/versao', exigirLogin('equipe'), async (c) => {
  try { return c.json(await agente('GET', '/versoes')); } catch { return c.json({}); }
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

// WhatsApp (Meta Cloud API): verificação do endereço e mensagens recebidas.
app.get('/api/whatsapp/webhook', (c) => {
  const token = process.env.WHATSAPP_VERIFY_TOKEN || 'primehub-verify-token';
  if (c.req.query('hub.mode') === 'subscribe' && c.req.query('hub.verify_token') === token) return c.text(c.req.query('hub.challenge') ?? '');
  return c.text('Forbidden', 403);
});
app.post('/api/whatsapp/webhook', async (c) => {
  const corpo = await c.req.text();
  if (!(await assinaturaValida(corpo, c.req.header('x-hub-signature-256')))) return c.text('Assinatura inválida', 403);
  // Responde já (a Meta reenvia se demorar) e processa em seguida.
  setImmediate(() => { processarWebhook(corpo).catch((e) => console.error('WhatsApp webhook:', e instanceof Error ? e.message : e)); });
  return c.text('OK');
});

app.get('/api/app/versao', async (c) => {
  const versao = await versaoAtual();
  c.header('cache-control', 'no-store');
  return versao ? c.json(versao) : c.json({ erro: 'Nenhuma versão publicada ainda.' }, 404);
});

app.get('/downloads/primehub.apk', () => respostaApk());

// Comandos de manutenção (rodados com `node index.mjs --...` dentro do contêiner):
// não sobem o servidor, só fazem a tarefa e saem. Nunca imprimem senha nem chave.
const comando = process.argv.find((a) => a.startsWith('--') && ['--recifrar-cofre', '--conferir-cofre'].includes(a));
if (comando === '--recifrar-cofre') {
  recifrarCofre(process.env.CHAVE_ANTIGA ?? '', process.env.CHAVE_NOVA ?? '')
    .then((r) => { console.log(`cofre: ${r.total} senha(s); recifradas ${r.recifradas}; já na chave nova ${r.jaNaNova}; ilegíveis ${r.ilegiveis}`); process.exit(0); })
    .catch((e: unknown) => { console.error('cofre: ERRO, nada foi alterado:', e instanceof Error ? e.message : e); process.exit(1); });
} else if (comando === '--conferir-cofre') {
  conferirCofre()
    .then((r) => { console.log(`cofre: ${r.abrem} de ${r.total} senha(s) abrem com a chave atual`); process.exit(r.abrem === r.total ? 0 : 2); })
    .catch((e: unknown) => { console.error('cofre: ERRO', e instanceof Error ? e.message : e); process.exit(1); });
}
if (!comando) { void garantirEstrutura(); void garantirEstruturaAgente().catch((e: unknown) => console.error('agente: estrutura', e instanceof Error ? e.message : e)); }
if (!comando) {
  serve({ fetch: app.fetch, port: config.porta }, (info) => {
    console.log(`API do Prime Hub ouvindo na porta ${info.port}`);
  });
}
