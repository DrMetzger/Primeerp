'use client';

import { Check, Send, Sparkles, X, Zap } from 'lucide-react';
import { acaoPhp } from '@/lib/api';
import { type FormEvent, useCallback, useEffect, useRef, useState } from 'react';
import { usuarioSalvo } from '@/lib/sessao';
import { CENA, preCarregar, Robo } from './robo';

interface Mensagem { autor: 'pessoa' | 'assistente'; texto: string }
interface Acao { id: string; titulo: string; detalhe: string; arquivo: string; campos: Record<string, string>; perigo?: boolean; estado?: 'pendente' | 'rodando' | 'feita' | 'cancelada' | 'erro'; aviso?: string }

const CHAVE_OCULTO = 'ph_assistente_oculto';
export const EVENTO_CHAMAR = 'primehub:assistente';

/** Chama o assistente de volta (botão do menu). */
export function chamarAssistente(): void {
  window.dispatchEvent(new Event(EVENTO_CHAMAR));
}

const SUGESTOES = {
  equipe: ['Resumo da operação de hoje', 'Quais urgentes estão esperando a gente?', 'Como respondo um chamado com a IA?'],
  cliente: ['Como estão meus chamados?', 'O que é a rejeição 539 da NF-e?', 'Quero abrir um chamado'],
  login: ['Esqueci minha senha', 'Como baixo o app?', 'Não tenho acesso ainda'],
} as const;

const RESPOSTAS_LOGIN: Record<string, string> = {
  'Esqueci minha senha': 'Sem problema! Fala com a equipe da PrimeERP pelo WhatsApp ou e-mail de sempre que a gente te manda um link novo pra criar a senha.',
  'Como baixo o app?': 'É só tocar em "Baixar" no cartão do app aqui na tela de login. No Android, permita instalar apps do navegador quando ele pedir.',
  'Não tenho acesso ainda': 'O acesso é liberado pela equipe da PrimeERP. Pede pro responsável da sua empresa ou fala com a gente que liberamos rapidinho.',
};

export function Assistente({ modo = 'logado' }: { modo?: 'logado' | 'login' }) {
  const palco = useRef<HTMLDivElement>(null);
  const robo = useRef<Robo | null>(null);
  const [visivel, setVisivel] = useState(false);
  const [aberto, setAberto] = useState(false);
  const [mensagens, setMensagens] = useState<Mensagem[]>([]);
  const [acoes, setAcoes] = useState<Acao[]>([]);
  const [texto, setTexto] = useState('');
  const [pensando, setPensando] = useState(false);
  const [digitando, setDigitando] = useState('');
  const fim = useRef<HTMLDivElement>(null);
  const [tamanho, setTamanho] = useState(150);

  const tipo = modo === 'login' ? 'login' : (usuarioSalvo()?.tipo ?? 'cliente');
  const primeiroNome = modo === 'login' ? '' : (usuarioSalvo()?.nome.split(' ')[0] ?? '');

  // Começa visível, a não ser que a pessoa tenha fechado antes.
  useEffect(() => {
    let oculto = false;
    try { oculto = localStorage.getItem(CHAVE_OCULTO) === '1'; } catch { /* aba privada */ }
    setVisivel(!oculto);
    preCarregar();
    const chamar = () => {
      try { localStorage.removeItem(CHAVE_OCULTO); } catch { /* ignore */ }
      setVisivel(true);
      setAberto(true);
      setTimeout(() => robo.current?.agir('acenar', 2400), 300);
    };
    window.addEventListener(EVENTO_CHAMAR, chamar);
    const medir = () => setTamanho(innerWidth < 640 ? 104 : 150);
    medir();
    window.addEventListener('resize', medir);
    return () => { window.removeEventListener(EVENTO_CHAMAR, chamar); window.removeEventListener('resize', medir); };
  }, []);

  // Monta o robô quando ele aparece; acena ao chegar.
  useEffect(() => {
    if (!visivel || !palco.current) return;
    const r = new Robo(palco.current);
    robo.current = r;
    // Só em desenvolvimento: permite disparar ações pelo console/testes.
    if (process.env.NODE_ENV !== 'production') (window as unknown as { __robo?: Robo }).__robo = r;
    const oi = setTimeout(() => r.agir('acenar', 2600), 500);
    const seguir = (e: PointerEvent) => r.olharPara(e.clientX, e.clientY);
    window.addEventListener('pointermove', seguir, { passive: true });
    window.addEventListener('pointerdown', seguir, { passive: true });
    return () => { clearTimeout(oi); window.removeEventListener('pointermove', seguir); window.removeEventListener('pointerdown', seguir); r.destruir(); robo.current = null; };
  }, [visivel]);

  useEffect(() => { fim.current?.scrollIntoView({ behavior: 'smooth', block: 'end' }); }, [mensagens, digitando, pensando]);

  /** Mostra a resposta "digitando" enquanto o robô mexe a boca. */
  const responder = useCallback((resposta: string) => {
    const r = robo.current;
    r?.agir('falar', 60_000);
    let i = 0;
    const passo = () => {
      i = Math.min(resposta.length, i + 2 + Math.floor(Math.random() * 3));
      setDigitando(resposta.slice(0, i));
      if (i < resposta.length) {
        setTimeout(passo, 22);
      } else {
        setDigitando('');
        setMensagens((m) => [...m, { autor: 'assistente', texto: resposta }]);
        r?.parar();
        r?.agir('sorrir', 1800);
      }
    };
    passo();
  }, []);

  function abrir() {
    setAberto(true);
    if (mensagens.length === 0) {
      const oi = modo === 'login'
        ? 'Oi! Eu sou o assistente da PrimeERP. Posso te ajudar a entrar ou a baixar o app.'
        : `Oi${primeiroNome ? `, ${primeiroNome}` : ''}! Sou o assistente do Prime Hub. Em que posso te ajudar?`;
      robo.current?.agir('acenar', 1500);
      setTimeout(() => responder(oi), 600);
    }
  }

  function fechar() {
    setAberto(false);
    setVisivel(false);
    try { localStorage.setItem(CHAVE_OCULTO, '1'); } catch { /* ignore */ }
  }

  async function enviar(pergunta: string) {
    const p = pergunta.trim();
    if (!p || pensando || digitando) return;
    setTexto('');
    const historico = mensagens;
    setMensagens((m) => [...m, { autor: 'pessoa', texto: p }]);

    if (modo === 'login') {
      robo.current?.agir('pensar', 900);
      setTimeout(() => responder(RESPOSTAS_LOGIN[p] ?? 'Assim que você entrar eu consigo te ajudar com tudo do sistema. Se não lembrar a senha, fala com a equipe da PrimeERP.'), 900);
      return;
    }

    setPensando(true);
    robo.current?.agir(Math.random() > 0.5 ? 'anotar' : 'escrever', 60_000);
    try {
      const r = await fetch('/api/assistente', {
        method: 'POST', credentials: 'same-origin', headers: { 'content-type': 'application/json' },
        body: JSON.stringify({ mensagem: p, historico }),
      });
      const corpo = (await r.json()) as { texto?: string; erro?: string; acoes?: Acao[] };
      setPensando(false);
      responder(corpo.texto ?? corpo.erro ?? 'Não consegui responder agora.');
      if (corpo.acoes?.length) setAcoes((a) => [...a, ...(corpo.acoes ?? []).map((x) => ({ ...x, estado: 'pendente' as const }))]);
    } catch {
      setPensando(false);
      responder('Fiquei sem conexão. Tenta de novo?');
    }
  }

  /** Ação proposta pela IA: só roda com o clique em Confirmar (mesmas regras do sistema). */
  async function confirmar(a: Acao) {
    setAcoes((l) => l.map((x) => (x.id === a.id ? { ...x, estado: 'rodando' } : x)));
    robo.current?.agir('escrever', 3000);
    try {
      const r = await acaoPhp<{ ok: boolean; id?: number | null }>(a.arquivo, a.campos);
      const link = r.id ? ` Chamado #${r.id} criado.` : '';
      setAcoes((l) => l.map((x) => (x.id === a.id ? { ...x, estado: 'feita', aviso: `Feito!${link}` } : x)));
      robo.current?.agir('comemorar', 1800);
      window.dispatchEvent(new Event('primehub:atualizar'));
    } catch (e) {
      setAcoes((l) => l.map((x) => (x.id === a.id ? { ...x, estado: 'erro', aviso: e instanceof Error ? e.message : 'Não deu certo.' } : x)));
    }
  }

  if (!visivel) return null;
  const escala = tamanho / CENA.largura;

  return (
    <div className="pointer-events-none fixed z-50" style={{ right: 'max(12px, env(safe-area-inset-right))', bottom: 'max(12px, env(safe-area-inset-bottom))' }}>
      {aberto && (
        <section aria-label="Conversa com o assistente"
          className="pointer-events-auto absolute bottom-full right-0 mb-2 flex max-h-[min(78dvh,680px)] w-[min(94vw,440px)] flex-col overflow-hidden rounded-3xl border border-borda bg-superficie shadow-2xl">
          <header className="gradiente-marca flex items-center gap-2 px-4 py-3 text-white">
            <Sparkles size={18} aria-hidden="true" />
            <p className="flex-1 font-semibold">Assistente PrimeERP</p>
            <button type="button" onClick={() => setAberto(false)} className="toque -mr-2 flex items-center justify-center rounded-xl" aria-label="Minimizar conversa"><X size={20} /></button>
          </header>
          <div className="flex-1 space-y-2 overflow-y-auto p-4" aria-live="polite">
            {mensagens.map((m, i) => (
              <p key={i} className={`max-w-[85%] whitespace-pre-wrap rounded-2xl px-3 py-2 text-[15px] leading-snug ${m.autor === 'pessoa' ? 'ml-auto bg-primaria text-primaria-texto' : 'bg-superficie-alta text-texto'}`}>{m.texto}</p>
            ))}
            {acoes.map((a) => (
              <div key={a.id} className={`rounded-2xl border p-3 text-sm ${a.perigo ? 'border-perigo/40 bg-perigo/5' : 'border-primaria/40 bg-primaria/5'}`}>
                <p className="flex items-center gap-2 font-semibold text-texto"><Zap size={16} className={a.perigo ? 'text-perigo' : 'text-primaria'} aria-hidden="true" />{a.titulo}</p>
                <p className="mt-1 text-suave">{a.detalhe}</p>
                {a.estado === 'pendente' && (
                  <div className="mt-3 flex gap-2">
                    <button type="button" onClick={() => void confirmar(a)} className={`toque flex-1 rounded-xl px-3 font-semibold text-white ${a.perigo ? 'bg-perigo' : 'gradiente-marca'}`}>Confirmar</button>
                    <button type="button" onClick={() => setAcoes((l) => l.map((x) => (x.id === a.id ? { ...x, estado: 'cancelada', aviso: 'Cancelado.' } : x)))} className="toque rounded-xl border border-borda px-3 text-texto">Cancelar</button>
                  </div>
                )}
                {a.estado === 'rodando' && <p className="mt-2 text-suave">Fazendo…</p>}
                {a.aviso && <p className={`mt-2 flex items-center gap-1 font-semibold ${a.estado === 'feita' ? 'text-sucesso' : a.estado === 'erro' ? 'text-perigo' : 'text-suave'}`}>{a.estado === 'feita' && <Check size={16} aria-hidden="true" />}{a.aviso}</p>}
              </div>
            ))}
            {digitando && <p className="max-w-[85%] whitespace-pre-wrap rounded-2xl bg-superficie-alta px-3 py-2 text-[15px] leading-snug text-texto">{digitando}<span className="animate-pulse">▍</span></p>}
            {pensando && <p className="inline-flex gap-1 rounded-2xl bg-superficie-alta px-3 py-3" aria-label="Pensando"><span className="h-2 w-2 animate-bounce rounded-full bg-suave" /><span className="h-2 w-2 animate-bounce rounded-full bg-suave [animation-delay:.15s]" /><span className="h-2 w-2 animate-bounce rounded-full bg-suave [animation-delay:.3s]" /></p>}
            {mensagens.length <= 1 && !digitando && (
              <div className="flex flex-wrap gap-2 pt-1">
                {SUGESTOES[tipo].map((s) => (
                  <button key={s} type="button" onClick={() => void enviar(s)} className="toque rounded-full border border-borda px-3 text-sm text-texto hover:bg-superficie-alta">{s}</button>
                ))}
              </div>
            )}
            <div ref={fim} />
          </div>
          <form onSubmit={(e: FormEvent) => { e.preventDefault(); void enviar(texto); }} className="flex gap-2 border-t border-borda p-3">
            <label className="sr-only" htmlFor="pergunta-assistente">Sua pergunta</label>
            <input id="pergunta-assistente" value={texto} onChange={(e) => setTexto(e.target.value)} placeholder="Pergunte ao assistente…" maxLength={1000}
              onFocus={() => robo.current?.agir('sorrir', 1200)}
              className="toque min-w-0 flex-1 rounded-xl border border-borda bg-fundo px-3 text-base text-texto outline-none focus:border-primaria" />
            <button type="submit" disabled={!texto.trim() || pensando || !!digitando} aria-label="Enviar"
              className="gradiente-marca toque flex items-center justify-center rounded-xl px-3 text-white disabled:opacity-50"><Send size={20} /></button>
          </form>
        </section>
      )}

      <div className="pointer-events-auto relative" style={{ width: tamanho, height: CENA.altura * escala }}>
        <button type="button" onClick={fechar} aria-label="Fechar assistente" title="Fechar assistente"
          className="absolute -top-1 right-0 z-10 flex h-9 w-9 items-center justify-center rounded-full border border-borda bg-superficie text-suave shadow hover:text-perigo">
          <X size={18} />
        </button>
        <button type="button" onClick={() => (aberto ? robo.current?.agir('surpreso', 900) : abrir())}
          onMouseEnter={() => { if (!robo.current?.acaoAtual) robo.current?.agir('sorrir', 1400); }}
          aria-label={aberto ? 'Assistente PrimeERP' : 'Abrir o assistente PrimeERP'} className="block h-full w-full cursor-pointer rounded-3xl">
          <div ref={palco} aria-hidden="true" style={{ width: CENA.largura, height: CENA.altura, transform: `scale(${escala})`, transformOrigin: '0 0' }} />
        </button>
      </div>
    </div>
  );
}
