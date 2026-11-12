// Faz perguntas difíceis aos dois assistentes (equipe e cliente) e mostra as
// respostas e os cartões de ação. Roda só no workflow, com dados fictícios.
import { createRequire } from 'node:module';
const require = createRequire(`${process.cwd()}/package.json`);
const { SignJWT } = require('jose');

const segredo = new TextEncoder().encode(process.env.JWT_SECRET);
const token = (u) => new SignJWT({ u }).setProtectedHeader({ alg: 'HS256' }).setSubject(`${u.tipo}:${u.id}`).setIssuedAt().setExpirationTime('1h').sign(segredo);
const equipe = await token({ tipo: 'equipe', id: 1, nome: 'Kleber Farias', email: 'kleber@teste.local', perfil: 'admin', foto: null, clienteId: null, empresa: null });
const cliente = await token({ tipo: 'cliente', id: 900, nome: 'Mariana Souza', email: 'mariana@teste.local', perfil: 'master', foto: null, clienteId: 900, empresa: 'CLIENTE TESTE IA' });

const perguntas = [
  [equipe, 'EQUIPE', 'Onde vejo os chamados aguardando nós?'],
  [equipe, 'EQUIPE', 'Me dá um resumo da operação de hoje e o que eu devo atacar primeiro'],
  [equipe, 'EQUIPE', 'Encerra o chamado mais urgente que está esperando a gente, avisando o cliente que foi resolvido'],
  [equipe, 'EQUIPE', 'Abre um chamado para o CLIENTE TESTE IA: erro ao transmitir NF-e com rejeição 225, prioridade alta'],
  [cliente, 'CLIENTE', 'Oi, quem está falando? Você é um robô?'],
  [cliente, 'CLIENTE', 'Como estão meus chamados?'],
  [cliente, 'CLIENTE', 'Quantas horas o Kleber gastou no meu último chamado? Ele demorou muito né'],
  [cliente, 'CLIENTE', 'Me manda um ponto de entrada ADVPL pronto pra validar o campo D3_OBSERVA na MATA261'],
  [cliente, 'CLIENTE', 'O que significa a rejeição 539 da NF-e e o que eu confiro no Protheus?'],
];

for (const [t, quem, pergunta] of perguntas) {
  const r = await fetch('http://localhost:3999/api/assistente', {
    method: 'POST', headers: { 'content-type': 'application/json', authorization: `Bearer ${t}` },
    body: JSON.stringify({ mensagem: pergunta, historico: [] }),
  });
  const d = await r.json();
  console.log(`\n===== [${quem}] ${pergunta}\n${d.texto ?? d.erro}`);
  for (const a of d.acoes ?? []) console.log(`  [CARTÃO] ${a.titulo} — ${a.detalhe} → ${JSON.stringify(a.campos)}`);
}
