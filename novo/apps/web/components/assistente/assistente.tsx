'use client';

import { Check, Paperclip, Send, Sparkles, X, Zap } from 'lucide-react';
import { acaoPhp } from '@/lib/api';
import { type FormEvent, useCallback, useEffect, useRef, useState } from 'react';
import { usuarioSalvo } from '@/lib/sessao';
import { CENA, preCarregar, Robo } from './robo';

interface Mensagem { autor: 'pessoa' | 'assistente'; texto: string }
interface Acao { id: string; titulo: string; detalhe: string; arquivo: string; campos: Record<string, string>; perigo?: boolean; estado?: 'pendente' | 'rodando' | 'feita' | 'cancelada' | 'erro'; aviso?: string }

const CHAVE_OCULTO = 'ph_assistente_oculto';
const CHAVE_CONVERSA = 'ph_assistente_conversa';
export const EVENTO_CHAMAR = 'primehub:assistente';

/** Guarda a conversa (texto só, sem anexos) pra não sumir quando fechar/recarregar. */
function salvarConversa(mensagens: Mensagem[], acoes: Acao[]): void {
  try {
    if (!mensagens.length && !acoes.length) { localStorage.removeItem(CHAVE_CONVERSA); return; }
    localStorage.setItem(CHAVE_CONVERSA, JSON.stringify({ v: 1, em: Date.now(), mensagens: mensagens.slice(-40), acoes: acoes.slice(-20) }));
  } catch { /* aba privada / sem espaço */ }
}
function lerConversa(): { mensagens: Mensagem[]; acoes: Acao[] } | null {
  try {
    const d = JSON.parse(localStorage.getItem(CHAVE_CONVERSA) ?? 'null') as { em?: number; mensagens?: Mensagem[]; acoes?: Acao[] } | null;
    if (!d?.mensagens?.length) return null;
    // Descarta conversa muito antiga (12 h) pra não reabrir algo esquecido.
    if (d.em && Date.now() - d.em > 12 * 3600_000) { localStorage.removeItem(CHAVE_CONVERSA); return null; }
    return { mensagens: d.mensagens, acoes: Array.isArray(d.acoes) ? d.acoes.map((x) => ({ ...x, estado: x.estado && x.estado !== 'rodando' ? x.estado : 'pendente' })) : [] };
  } catch { return null; }
}

/**
 * O que a pessoa está vendo agora (endereço, título e o texto visível da
 * área principal), pra IA ajudar exatamente naquela tela. Campos de senha
 * não entram (innerText não lê valor de input).
 */
function contextoDaTela(): { rota: string; titulo: string; texto: string } {
  const principal = document.querySelector('main') ?? document.body;
  const dialogo = document.querySelector('[role="dialog"]:not([aria-label="Conversa com o assistente"])');
  const titulo = document.querySelector('header h1')?.textContent ?? document.title;
  const texto = `${dialogo ? `[JANELA ABERTA] ${(dialogo as HTMLElement).innerText}\n\n` : ''}${(principal as HTMLElement).innerText}`;
  return { rota: location.pathname + location.search + location.hash, titulo: titulo.trim(), texto: texto.replace(/\n{3,}/g, '\n\n').slice(0, 6000) };
}

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
  const [anexos, setAnexos] = useState<{ nome: string; conteudo: string }[]>([]);
  const [pensando, setPensando] = useState(false);
  const [fase, setFase] = useState('');
  const [digitando, setDigitando] = useState('');
  const fim = useRef<HTMLDivElement>(null);
  const convId = useRef<number | null>(null);
  const [tamanho, setTamanho] = useState(150);
  // Posição (distância da direita e de baixo): arrastável e lembrada.
  const [pos, setPos] = useState<{ d: number; b: number }>({ d: 12, b: 12 });
  const arrasto = useRef<{ x: number; y: number; d: number; b: number; moveu: boolean } | null>(null);
  useEffect(() => {
    try { const p = JSON.parse(localStorage.getItem('ph_assistente_pos') ?? 'null') as { d: number; b: number } | null; if (p) setPos(p); } catch { /* sem armazenamento */ }
    if (modo !== 'login') { const c = lerConversa(); if (c) { setMensagens(c.mensagens); setAcoes(c.acoes); } }
  }, [modo]);

  // Sempre que a conversa muda, guarda — assim fechar a janela ou recarregar não perde nada.
  useEffect(() => { if (modo !== 'login') salvarConversa(mensagens, acoes); }, [mensagens, acoes, modo]);

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
        : tipo === 'equipe'
          ? `Fala${primeiroNome ? `, ${primeiroNome}` : ''}! Posso resumir a operação, achar chamados, abrir, encerrar ou passar chamados pra alguém (você confirma antes). Bora?`
          : `Olá${primeiroNome ? `, ${primeiroNome}` : ''}! Aqui é da equipe da PrimeERP. Pode me perguntar qualquer coisa do Protheus ou sobre os seus chamados.`;
      robo.current?.agir('acenar', 1500);
      setTimeout(() => responder(oi), 600);
    }
  }

  function fechar() {
    setAberto(false);
    setVisivel(false);
    try { localStorage.setItem(CHAVE_OCULTO, '1'); } catch { /* ignore */ }
  }

  /** Traz o robô de volta depois de fechado (pelo lançador flutuante). */
  function reabrir() {
    try { localStorage.removeItem(CHAVE_OCULTO); } catch { /* ignore */ }
    setVisivel(true);
    setAberto(true);
    setTimeout(() => robo.current?.agir('acenar', 1800), 300);
  }

  /** Zera a conversa guardada e recomeça com a saudação. */
  function novaConversa() {
    setMensagens([]); setAcoes([]); setDigitando(''); setTexto(''); setAnexos([]);
    convId.current = null;
    try { localStorage.removeItem(CHAVE_CONVERSA); } catch { /* ignore */ }
    setTimeout(() => abrir(), 50);
  }

  async function enviar(pergunta: string) {
    const p = pergunta.trim();
    if ((!p && !anexos.length) || pensando || digitando) return;
    setTexto('');
    const historico = mensagens;
    const enviados = anexos;
    setAnexos([]);
    const rotulo = enviados.length ? `${p}${p ? '\n' : ''}📎 ${enviados.map((a) => a.nome).join(', ')}` : p;
    setMensagens((m) => [...m, { autor: 'pessoa', texto: rotulo }]);

    if (modo === 'login') {
      robo.current?.agir('pensar', 900);
      setTimeout(() => responder(RESPOSTAS_LOGIN[p] ?? 'Assim que você entrar eu consigo te ajudar com tudo do sistema. Se não lembrar a senha, fala com a equipe da PrimeERP.'), 900);
      return;
    }

    setPensando(true);
    setFase('');
    robo.current?.agir(Math.random() > 0.5 ? 'anotar' : 'escrever', 60_000);
    // Acompanha ao vivo o que a IA está fazendo (gerando / compilando / erro).
    const acompanhar = setInterval(() => {
      void fetch('/api/assistente/progresso', { credentials: 'same-origin' })
        .then((x) => x.json())
        .then((pj: { fase?: string | null; rotulo?: string; detalhe?: string }) => {
          if (pj?.fase && pj.rotulo) setFase(pj.detalhe ? `${pj.rotulo} — ${pj.detalhe.slice(0, 140)}` : pj.rotulo);
        })
        .catch(() => undefined);
    }, 1500);
    try {
      const r = await fetch('/api/assistente', {
        method: 'POST', credentials: 'same-origin', headers: { 'content-type': 'application/json' }, signal: AbortSignal.timeout(95_000),
        body: JSON.stringify({ mensagem: p || 'Segue o arquivo em anexo.', historico, tela: contextoDaTela(), ...(convId.current ? { conversaId: convId.current } : {}), ...(enviados.length ? { anexos: enviados } : {}) }),
      });
      const corpo = (await r.json()) as { texto?: string; erro?: string; acoes?: Acao[]; conversaId?: number | null };
      if (corpo.conversaId) convId.current = corpo.conversaId;
      clearInterval(acompanhar);
      setPensando(false); setFase('');
      responder(corpo.texto ?? corpo.erro ?? 'Não consegui responder agora.');
      if (corpo.acoes?.length) setAcoes((a) => [...a, ...(corpo.acoes ?? []).map((x) => ({ ...x, estado: 'pendente' as const }))]);
    } catch {
      clearInterval(acompanhar);
      setPensando(false); setFase('');
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

  // Fechado: mostra um lançador flutuante pra sempre dar pra reabrir o robô.
  if (!visivel) {
    return (
      <button type="button" onClick={reabrir} aria-label="Abrir o assistente PrimeERP" title="Abrir o assistente"
        className="pointer-events-auto fixed z-50 flex h-14 w-14 items-center justify-center rounded-full gradiente-marca text-white shadow-2xl shadow-primaria/40 transition hover:scale-105"
        style={{ right: `max(${pos.d}px, env(safe-area-inset-right))`, bottom: `calc(max(${pos.b}px, env(safe-area-inset-bottom)) + 28px)` }}>
        <Sparkles size={26} aria-hidden="true" />
      </button>
    );
  }
  const escala = tamanho / CENA.largura;
  const alturaRobo = CENA.altura * escala;
  const espacoAcima = typeof window !== 'undefined' ? innerHeight - pos.b - alturaRobo : 600;
  const espacoAbaixo = pos.b;
  // Abre a conversa pro lado com mais espaço e cabe na tela.
  const noTopo = espacoAbaixo > espacoAcima;
  const alturaPainel = Math.max(260, Math.min(680, (noTopo ? espacoAbaixo : espacoAcima) - 16));
  const naEsquerda = typeof window !== 'undefined' && pos.d > innerWidth / 2;

  /** Arrastar o robô pela tela (mouse ou dedo). Toque sem arrastar = clique. */
  function comecarArrasto(e: React.PointerEvent) {
    (e.currentTarget as HTMLElement).setPointerCapture(e.pointerId);
    arrasto.current = { x: e.clientX, y: e.clientY, d: pos.d, b: pos.b, moveu: false };
  }
  function moverArrasto(e: React.PointerEvent) {
    const a = arrasto.current;
    if (!a) return;
    const dx = e.clientX - a.x, dy = e.clientY - a.y;
    if (!a.moveu && Math.abs(dx) + Math.abs(dy) < 6) return;
    a.moveu = true;
    const altura = CENA.altura * escala;
    setPos({ d: Math.max(4, Math.min(innerWidth - tamanho - 4, a.d - dx)), b: Math.max(4, Math.min(innerHeight - altura - 4, a.b - dy)) });
  }
  function soltarArrasto() {
    const a = arrasto.current;
    arrasto.current = null;
    if (!a) return;
    if (a.moveu) {
      try { localStorage.setItem('ph_assistente_pos', JSON.stringify(pos)); } catch { /* ignore */ }
      robo.current?.agir('surpreso', 700);
      return;
    }
    if (aberto) robo.current?.agir('surpreso', 900); else abrir();
  }

  return (
    <div className="pointer-events-none fixed z-50" style={{ right: `max(${pos.d}px, env(safe-area-inset-right))`, bottom: `max(${pos.b}px, env(safe-area-inset-bottom))` }}>
      {aberto && (
        <section aria-label="Conversa com o assistente"
          className={`pointer-events-auto absolute ${noTopo ? 'top-full mt-2' : 'bottom-full mb-2'} ${naEsquerda ? 'left-0' : 'right-0'} flex w-[min(94vw,440px)] flex-col overflow-hidden rounded-3xl border border-borda bg-superficie shadow-2xl`} style={{ maxHeight: alturaPainel }}>
          <header className="gradiente-marca flex items-center gap-2 px-4 py-3 text-white">
            <Sparkles size={18} aria-hidden="true" />
            <div className="flex-1">
              <p className="font-semibold leading-tight">{tipo === 'equipe' ? 'Assistente da equipe' : 'Atendimento PrimeERP'}</p>
              <p className="text-xs opacity-85">{tipo === 'equipe' ? 'Conhece o manual, consulta e age por você' : 'Especialista em TOTVS Protheus'}</p>
            </div>
            {mensagens.length > 1 && (
              <button type="button" onClick={novaConversa} className="toque flex items-center justify-center rounded-xl px-2 text-xs font-semibold opacity-90 hover:opacity-100" aria-label="Começar uma nova conversa">Nova</button>
            )}
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
            {pensando && (
              <div className="inline-flex max-w-[85%] items-center gap-2 rounded-2xl bg-superficie-alta px-3 py-3" aria-label={fase || 'Pensando'}>
                <span className="inline-flex gap-1">
                  <span className="h-2 w-2 animate-bounce rounded-full bg-suave" />
                  <span className="h-2 w-2 animate-bounce rounded-full bg-suave [animation-delay:.15s]" />
                  <span className="h-2 w-2 animate-bounce rounded-full bg-suave [animation-delay:.3s]" />
                </span>
                {fase && <span className="text-[13px] leading-snug text-suave">{fase}</span>}
              </div>
            )}
            {mensagens.length <= 1 && !digitando && (
              <div className="flex flex-wrap gap-2 pt-1">
                {SUGESTOES[tipo].map((s) => (
                  <button key={s} type="button" onClick={() => void enviar(s)} className="toque rounded-full border border-borda px-3 text-sm text-texto hover:bg-superficie-alta">{s}</button>
                ))}
              </div>
            )}
            <div ref={fim} />
          </div>
          <form onSubmit={(e: FormEvent) => { e.preventDefault(); void enviar(texto); }} className="flex flex-col gap-2 border-t border-borda p-3">
            {anexos.length > 0 && (
              <div className="flex flex-wrap gap-2">
                {anexos.map((a, i) => (
                  <span key={`${a.nome}-${i}`} className="inline-flex max-w-full items-center gap-1 rounded-lg bg-superficie-alta px-2 py-1 text-xs text-texto">
                    <Paperclip size={12} aria-hidden="true" /><span className="truncate">{a.nome}</span>
                    <button type="button" aria-label={`Remover ${a.nome}`} onClick={() => setAnexos((l) => l.filter((_, j) => j !== i))} className="text-suave hover:text-perigo"><X size={12} /></button>
                  </span>
                ))}
              </div>
            )}
            <div className="flex gap-2">
              <label className="sr-only" htmlFor="pergunta-assistente">Sua pergunta</label>
              <label className="toque flex cursor-pointer items-center justify-center rounded-xl border border-borda px-2 text-suave hover:text-texto" title="Anexar fonte (.prw, .tlpp, .ch)">
                <Paperclip size={18} aria-hidden="true" />
                <input type="file" accept=".prw,.tlpp,.prx,.ch,.txt,.tlp,text/plain" multiple className="hidden"
                  onChange={async (e) => {
                    const fs = Array.from(e.target.files ?? []);
                    e.target.value = '';
                    for (const f of fs) {
                      if (anexos.length >= 5) break;
                      if (f.size > 1024 * 1024) { setMensagens((m) => [...m, { autor: 'assistente', texto: `O arquivo ${f.name} é grande demais (máx. 1 MB).` }]); continue; }
                      try { const t = await f.text(); setAnexos((l) => (l.length >= 5 ? l : [...l, { nome: f.name.slice(0, 120), conteudo: t }])); } catch { /* ignore */ }
                    }
                  }} />
              </label>
              <input id="pergunta-assistente" value={texto} onChange={(e) => setTexto(e.target.value)} placeholder={anexos.length ? 'O que mudar no fonte?' : 'Pergunte ao assistente…'} maxLength={1000}
                onFocus={() => robo.current?.agir('sorrir', 1200)}
                className="toque min-w-0 flex-1 rounded-xl border border-borda bg-fundo px-3 text-base text-texto outline-none focus:border-primaria" />
              <button type="submit" disabled={(!texto.trim() && !anexos.length) || pensando || !!digitando} aria-label="Enviar"
                className="gradiente-marca toque flex items-center justify-center rounded-xl px-3 text-white disabled:opacity-50"><Send size={20} /></button>
            </div>
          </form>
        </section>
      )}

      <div className="pointer-events-auto relative" style={{ width: tamanho, height: CENA.altura * escala }}>
        <button type="button" onClick={fechar} aria-label="Fechar assistente" title="Fechar assistente"
          className="absolute -top-1 right-0 z-10 flex h-9 w-9 items-center justify-center rounded-full border border-borda bg-superficie text-suave shadow hover:text-perigo">
          <X size={18} />
        </button>
        <button type="button" onPointerDown={comecarArrasto} onPointerMove={moverArrasto} onPointerUp={soltarArrasto} onPointerCancel={() => { arrasto.current = null; }}
          onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); if (!aberto) abrir(); } }}
          title="Arraste para mudar de lugar"
          onMouseEnter={() => { if (!robo.current?.acaoAtual) robo.current?.agir('sorrir', 1400); }}
          aria-label={aberto ? 'Assistente PrimeERP' : 'Abrir o assistente PrimeERP'} className="block h-full w-full cursor-grab touch-none rounded-3xl active:cursor-grabbing">
          <div ref={palco} aria-hidden="true" style={{ width: CENA.largura, height: CENA.altura, transform: `scale(${escala})`, transformOrigin: '0 0' }} />
        </button>
      </div>
    </div>
  );
}
