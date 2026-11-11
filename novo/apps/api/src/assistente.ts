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
Ajude a usar o sistema e tire dúvidas rápidas de Protheus. Responda em português do Brasil, de forma simpática, natural e CURTA (no máximo 4 frases ou 5 passos curtos). Texto puro, sem markdown, sem asteriscos.
Se a dúvida for um problema técnico que precisa de análise (erro no Protheus, ajuste de fonte, nota rejeitada etc.), oriente a abrir um chamado com prints, porque a equipe analisa. Nunca invente telas, botões, rotinas ou parâmetros que você não tem certeza que existem. Não peça senha nem dados sensíveis.`;
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

  for (const modelo of MODELOS) {
    try {
      const r = await fetch(`https://generativelanguage.googleapis.com/v1beta/models/${modelo}:generateContent`, {
        method: 'POST',
        headers: { 'content-type': 'application/json', 'x-goog-api-key': chave },
        body: JSON.stringify({
          systemInstruction: { parts: [{ text: instrucoes(usuario) }] },
          contents,
          generationConfig: { maxOutputTokens: 400, temperature: 0.5 },
        }),
        signal: AbortSignal.timeout(25_000),
      });
      if (!r.ok) continue; // 503/429: tenta o modelo reserva
      const dados = (await r.json()) as { candidates?: { content?: { parts?: { text?: string }[] } }[] };
      const texto = (dados.candidates?.[0]?.content?.parts ?? []).map((p) => p.text ?? '').join('').replace(/\*\*/g, '').trim();
      if (texto) return { texto };
    } catch {
      // rede/tempo: tenta o próximo modelo
    }
  }
  return { erro: 'Não consegui pensar direito agora. Tenta de novo em alguns segundos?' };
}
