import type { UsuarioLogado } from '@primehub/shared';
import { primeira } from './db';

/**
 * Assistente do Prime Hub (o robô do site/app): responde dúvidas de uso do
 * sistema com o Gemini, usando a mesma chave cadastrada em Configurações
 * (tabela configuracoes, chave gemini_api_key). Modelo mais barato, resposta
 * curta e limite diário por pessoa, pra não gastar à toa.
 */

const MODELOS = ['gemini-flash-lite-latest', 'gemini-flash-latest'];
const LIMITE_DIA_POR_PESSOA = 60;
const usoHoje = new Map<string, { dia: string; n: number }>();

export interface MensagemChat { autor: 'pessoa' | 'assistente'; texto: string }

async function chaveGemini(): Promise<string> {
  const linha = await primeira<{ valor: string | null }>("SELECT valor FROM configuracoes WHERE chave = 'gemini_api_key'").catch(() => null);
  return linha?.valor?.trim() || process.env.GEMINI_API_KEY || '';
}

function instrucoes(usuario: UsuarioLogado): string {
  const quem = usuario.tipo === 'equipe'
    ? `Você conversa com ${usuario.nome}, da EQUIPE da PrimeERP (perfil ${usuario.perfil ?? 'colaborador'}). No Prime Hub a equipe tem: Dashboard (KPIs de atendimento), Chamados (lista com filtros; abrir um chamado leva à tela do cliente, onde se responde, anexa arquivos e usa o botão "Sugerir resposta com IA"), Clientes, Agenda, Relatórios, Fontes ADVPL, Usuários e Configurações (só administradores; inclui a IA dos chamados).`
    : `Você conversa com ${usuario.nome}, CLIENTE da PrimeERP (empresa ${usuario.empresa ?? ''}). No Prime Hub o cliente tem: Meus chamados (acompanhar e abrir chamado pelo botão "Abrir chamado", com anexos), Calendário de treinamentos, Videoaulas, Fontes ADVPL e Minha equipe.`;
  return `Você é o assistente virtual do Prime Hub, a central de atendimento da PrimeERP Soluções em Tecnologia (consultoria TOTVS Protheus, Tanabi-SP). ${quem}

SEU JEITO: simpático, educado e descolado na medida certa, como um colega de trabalho gente boa. Pode usar um toque de humor leve e um emoji de vez em quando, mas sempre com respeito. Nunca seja seco, nunca diga que "não pode responder" de forma ríspida e nunca deixe a pessoa sem resposta.

O QUE VOCÊ FAZ: ajuda a usar o Prime Hub e tira dúvidas rápidas de Protheus/ERP.
- Pergunta fora do assunto ou brincadeira (ex.: "como faço pra não trabalhar hoje?"): responda com bom humor e gentileza, em uma ou duas frases, e traga a conversa de volta pro que você pode ajudar. Ex.: "Haha, essa eu não consigo resolver 😄 mas posso deixar seu dia mais leve: quer que eu te mostre os chamados que estão esperando você?"
- Problema técnico que precisa de análise (erro no Protheus, ajuste de fonte, nota rejeitada etc.): explique o que der e oriente a abrir um chamado com prints, porque a equipe analisa.
- Nunca invente telas, botões, rotinas ou parâmetros que você não tem certeza que existem. Não peça senha nem dados sensíveis.

FORMATO: português do Brasil, texto puro (sem markdown, sem asteriscos), curto: no máximo 4 frases ou 5 passos curtos.`;
}

export async function perguntar(usuario: UsuarioLogado, historico: MensagemChat[], mensagem: string): Promise<{ texto: string } | { erro: string }> {
  const dia = new Date().toISOString().slice(0, 10);
  const chaveUso = `${usuario.tipo}:${usuario.id}`;
  const uso = usoHoje.get(chaveUso);
  const n = uso?.dia === dia ? uso.n : 0;
  if (n >= LIMITE_DIA_POR_PESSOA) return { erro: 'Hoje já conversamos bastante! Amanhã eu volto a responder. Se for urgente, abra um chamado.' };
  usoHoje.set(chaveUso, { dia, n: n + 1 });

  const chave = await chaveGemini();
  if (!chave) return { erro: 'O assistente ainda não foi configurado.' };

  const contents = [...historico.slice(-8), { autor: 'pessoa' as const, texto: mensagem }].map((m) => ({
    role: m.autor === 'pessoa' ? 'user' : 'model',
    parts: [{ text: m.texto.slice(0, 2000) }],
  }));

  // Gemini sobrecarregado (503/429) acontece: tenta de novo com espera curta
  // e troca de modelo. A pessoa nunca vê mensagem de erro técnica.
  for (const [tentativa, modelo] of [MODELOS[0], MODELOS[0], MODELOS[1], MODELOS[1]].entries()) {
    if (tentativa > 0) await new Promise((r) => setTimeout(r, 900 * tentativa));
    try {
      const r = await fetch(`https://generativelanguage.googleapis.com/v1beta/models/${modelo}:generateContent`, {
        method: 'POST',
        headers: { 'content-type': 'application/json', 'x-goog-api-key': chave },
        body: JSON.stringify({
          systemInstruction: { parts: [{ text: instrucoes(usuario) }] },
          contents,
          // Folga de tokens: modelos que "pensam" gastam parte disso antes de responder.
          generationConfig: { maxOutputTokens: 2048, temperature: 0.7 },
        }),
        signal: AbortSignal.timeout(25_000),
      });
      if (!r.ok) {
        console.error(`assistente: Gemini ${modelo} respondeu HTTP ${r.status}`);
        continue;
      }
      const dados = (await r.json()) as { candidates?: { finishReason?: string; content?: { parts?: { text?: string; thought?: boolean }[] } }[] };
      const partes = dados.candidates?.[0]?.content?.parts ?? [];
      const texto = partes.filter((p) => !p.thought).map((p) => p.text ?? '').join('').replace(/\*\*/g, '').replace(/^#+\s*/gm, '').trim();
      if (texto) return { texto };
      console.error(`assistente: Gemini ${modelo} sem texto (motivo: ${dados.candidates?.[0]?.finishReason ?? '?'})`);
    } catch (e) {
      console.error(`assistente: falha chamando ${modelo}:`, e instanceof Error ? e.message : e);
    }
  }
  // Último caso: resposta educada, nunca "erro".
  return { texto: `Opa, ${usuario.nome.split(' ')[0]}! Me enrolei aqui por um instante 😅 Pode me mandar a pergunta de novo? Se for algo urgente, é só abrir um chamado que a equipe te atende.` };
}
