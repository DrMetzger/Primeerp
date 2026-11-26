'use client';

import { MessageSquarePlus, Paperclip, Send, Sparkles, Trash2, X, Zap } from 'lucide-react';
import { type FormEvent, useCallback, useEffect, useRef, useState } from 'react';
import { Casca } from '@/components/casca';
import { TextoComLinks } from '@/components/assistente/texto-links';
import { acaoPhp, api } from '@/lib/api';

interface Msg { autor: 'pessoa' | 'assistente'; texto: string }
interface ConversaResumo { id: number; titulo: string; atualizadoEm: string; previa: string }
interface Acao { id: string; titulo: string; detalhe: string; arquivo: string; campos: Record<string, string>; perigo?: boolean; estado?: 'pendente' | 'feita' | 'erro'; aviso?: string }

function quando(s: string): string { try { return new Date(s.replace(' ', 'T')).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }); } catch { return ''; } }

/** Página de conversas com o assistente (estilo Gemini): histórico à esquerda, conversa à direita. */
export function PaginaAssistente({ visao }: { visao: 'equipe' | 'cliente' }) {
  const [conversas, setConversas] = useState<ConversaResumo[]>([]);
  const [conversaId, setConversaId] = useState<number | null>(null);
  const [mensagens, setMensagens] = useState<Msg[]>([]);
  const [acoes, setAcoes] = useState<Acao[]>([]);
  const [texto, setTexto] = useState('');
  const [anexos, setAnexos] = useState<{ nome: string; conteudo: string }[]>([]);
  const [pensando, setPensando] = useState(false);
  const [listaAberta, setListaAberta] = useState(false);
  const fim = useRef<HTMLDivElement>(null);

  const carregarLista = useCallback(() => { void api<ConversaResumo[]>('/api/assistente/conversas').then(setConversas).catch(() => undefined); }, []);
  const montou = useRef(false);
  const pollRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  // No primeiro carregamento, reabre a conversa mais recente (pra não voltar e "não ter nada").
  useEffect(() => {
    void api<ConversaResumo[]>('/api/assistente/conversas').then((l) => {
      setConversas(l);
      if (!montou.current) { montou.current = true; if (l.length && l[0]) abrir(l[0].id); }
    }).catch(() => undefined);
  }, []);
  useEffect(() => { fim.current?.scrollIntoView({ behavior: 'smooth', block: 'end' }); }, [mensagens, pensando]);

  function abrir(id: number) {
    setConversaId(id); setListaAberta(false); setAcoes([]);
    if (pollRef.current) { clearTimeout(pollRef.current); pollRef.current = null; }
    let tentativas = 0;
    const carregar = () => {
      void api<{ mensagens: Msg[] }>(`/api/assistente/conversas/${id}`).then((c) => {
        setMensagens(c.mensagens);
        const ultima = c.mensagens[c.mensagens.length - 1];
        // Se a última é uma pergunta SEM resposta, a resposta pode estar sendo gerada no servidor: busca de novo.
        if (ultima?.autor === 'pessoa' && tentativas < 12) { tentativas++; pollRef.current = setTimeout(carregar, 4000); }
      }).catch(() => setMensagens([]));
    };
    carregar();
  }
  function nova() { if (pollRef.current) clearTimeout(pollRef.current); setConversaId(null); setMensagens([]); setAcoes([]); setTexto(''); setListaAberta(false); }
  async function apagar(id: number) {
    if (!confirm('Apagar esta conversa? Não dá para desfazer.')) return;
    await api(`/api/assistente/conversas/${id}`, { method: 'DELETE' }).catch(() => undefined);
    if (conversaId === id) nova();
    carregarLista();
  }

  async function enviar(e: FormEvent) {
    e.preventDefault();
    const p = texto.trim();
    if ((!p && !anexos.length) || pensando) return;
    setTexto('');
    const enviados = anexos;
    setAnexos([]);
    const historico = mensagens.slice(-10).map((m) => ({ autor: m.autor, texto: m.texto.slice(0, 4000) }));
    const rotulo = enviados.length ? `${p}${p ? '\n' : ''}📎 ${enviados.map((a) => a.nome).join(', ')}` : p;
    setMensagens((m) => [...m, { autor: 'pessoa', texto: rotulo }]);
    setPensando(true);
    try {
      const r = await api<{ texto?: string; erro?: string; acoes?: Acao[]; conversaId?: number | null }>('/api/assistente', {
        method: 'POST', signal: AbortSignal.timeout(95_000), body: JSON.stringify({ mensagem: p || 'Segue o arquivo em anexo.', historico, conversaId: conversaId ?? undefined, ...(enviados.length ? { anexos: enviados } : {}), tela: { rota: location.pathname, titulo: 'Conversas com o assistente', texto: '' } }),
      });
      setMensagens((m) => [...m, { autor: 'assistente', texto: r.texto ?? r.erro ?? 'Não consegui responder agora.' }]);
      if (r.acoes?.length) setAcoes((a) => [...a, ...(r.acoes ?? []).map((x) => ({ ...x, estado: 'pendente' as const }))]);
      if (r.conversaId && r.conversaId !== conversaId) setConversaId(r.conversaId);
      carregarLista();
    } catch {
      setMensagens((m) => [...m, { autor: 'assistente', texto: 'Fiquei sem conexão. Tenta de novo?' }]);
    }
    setPensando(false);
  }

  async function confirmar(a: Acao) {
    try {
      const r = await acaoPhp<{ ok: boolean; id?: number | null }>(a.arquivo, a.campos);
      setAcoes((l) => l.map((x) => (x.id === a.id ? { ...x, estado: 'feita', aviso: `Feito!${r.id ? ` Chamado #${r.id}.` : ''}` } : x)));
      window.dispatchEvent(new Event('primehub:atualizar'));
    } catch (e) {
      setAcoes((l) => l.map((x) => (x.id === a.id ? { ...x, estado: 'erro', aviso: e instanceof Error ? e.message : 'Não deu certo.' } : x)));
    }
  }

  const Lista = (
    <div className="flex h-full flex-col">
      <button type="button" onClick={nova} className="toque mb-3 flex items-center justify-center gap-2 rounded-2xl gradiente-marca px-4 py-2.5 font-semibold text-white">
        <MessageSquarePlus size={18} aria-hidden="true" /> Nova conversa
      </button>
      <div className="min-h-0 flex-1 space-y-1.5 overflow-y-auto">
        {conversas.length === 0 ? <p className="px-2 py-4 text-sm text-suave">Suas conversas aparecem aqui.</p> : conversas.map((c) => (
          <div key={c.id} className={`group flex items-start gap-1 rounded-2xl border px-3 py-2 ${conversaId === c.id ? 'border-primaria/50 bg-primaria/10' : 'border-transparent hover:bg-superficie-alta'}`}>
            <button type="button" onClick={() => abrir(c.id)} className="min-w-0 flex-1 text-left">
              <p className="truncate text-sm font-semibold text-texto">{c.titulo}</p>
              <p className="truncate text-xs text-suave">{c.previa || '—'}</p>
              <p className="mt-0.5 text-[11px] text-suave">{quando(c.atualizadoEm)}</p>
            </button>
            <button type="button" onClick={() => void apagar(c.id)} aria-label="Apagar conversa" className="toque rounded-lg p-1 text-suave opacity-0 hover:text-perigo group-hover:opacity-100"><Trash2 size={15} /></button>
          </div>
        ))}
      </div>
    </div>
  );

  return (
    <Casca tipo={visao} titulo="Assistente">
      <div className="grid gap-4 lg:grid-cols-[300px_minmax(0,1fr)]" style={{ height: 'calc(100dvh - 8.5rem)' }}>
        <aside className="hidden rounded-3xl border border-borda bg-superficie p-3 lg:block">{Lista}</aside>

        <section className="flex min-h-0 flex-col rounded-3xl border border-borda bg-superficie">
          <header className="flex items-center gap-2 border-b border-borda px-4 py-3">
            <span className="gradiente-marca flex h-9 w-9 items-center justify-center rounded-2xl text-white"><Sparkles size={18} aria-hidden="true" /></span>
            <div className="min-w-0 flex-1">
              <p className="font-semibold leading-tight text-texto">{visao === 'equipe' ? 'Assistente da equipe' : 'Atendimento PrimeERP'}</p>
              <p className="truncate text-xs text-suave">Conversa salva — dá para recuperar quando quiser.</p>
            </div>
            <button type="button" onClick={() => setListaAberta((v) => !v)} className="toque rounded-xl border border-borda px-3 py-1.5 text-sm font-semibold text-texto lg:hidden">Conversas</button>
          </header>

          {listaAberta && <div className="border-b border-borda p-3 lg:hidden" style={{ maxHeight: '40dvh', overflowY: 'auto' }}>{Lista}</div>}

          <div className="min-h-0 flex-1 space-y-3 overflow-y-auto p-4">
            {mensagens.length === 0 && !pensando && (
              <div className="flex h-full flex-col items-center justify-center text-center text-suave">
                <span className="gradiente-marca mb-3 flex h-14 w-14 items-center justify-center rounded-3xl text-white"><Sparkles size={26} aria-hidden="true" /></span>
                <p className="font-semibold text-texto">Como posso ajudar?</p>
                <p className="mt-1 max-w-sm text-sm">Pergunte sobre o Protheus, seus chamados, ou peça para o assistente agir. Tudo fica salvo aqui.</p>
              </div>
            )}
            {mensagens.map((m, i) => (
              <p key={i} className={`max-w-[85%] whitespace-pre-wrap rounded-2xl px-3.5 py-2.5 text-[15px] leading-relaxed ${m.autor === 'pessoa' ? 'ml-auto bg-primaria text-primaria-texto' : 'bg-superficie-alta text-texto'}`}><TextoComLinks texto={m.texto} /></p>
            ))}
            {acoes.map((a) => (
              <div key={a.id} className={`rounded-2xl border p-3 text-sm ${a.perigo ? 'border-perigo/40 bg-perigo/5' : 'border-primaria/40 bg-primaria/5'}`}>
                <p className="flex items-center gap-2 font-semibold text-texto"><Zap size={16} className={a.perigo ? 'text-perigo' : 'text-primaria'} aria-hidden="true" />{a.titulo}</p>
                <p className="mt-1 text-suave">{a.detalhe}</p>
                {a.estado === 'pendente' ? (
                  <div className="mt-3 flex gap-2">
                    <button type="button" onClick={() => void confirmar(a)} className={`toque flex-1 rounded-xl px-3 py-1.5 font-semibold text-white ${a.perigo ? 'bg-perigo' : 'gradiente-marca'}`}>Confirmar</button>
                    <button type="button" onClick={() => setAcoes((l) => l.map((x) => (x.id === a.id ? { ...x, estado: 'feita', aviso: 'Cancelado.' } : x)))} className="toque rounded-xl border border-borda px-3 text-texto">Cancelar</button>
                  </div>
                ) : a.aviso ? <p className="mt-2 text-sm font-semibold text-suave">{a.aviso}</p> : null}
              </div>
            ))}
            {pensando && (
              <div className="flex w-fit items-center gap-1.5 rounded-2xl bg-superficie-alta px-3.5 py-3" aria-label="digitando">
                <span className="h-2 w-2 animate-bounce rounded-full bg-suave [animation-delay:-0.3s]" />
                <span className="h-2 w-2 animate-bounce rounded-full bg-suave [animation-delay:-0.15s]" />
                <span className="h-2 w-2 animate-bounce rounded-full bg-suave" />
              </div>
            )}
            <div ref={fim} />
          </div>

          <div className="border-t border-borda p-3">
            {anexos.length > 0 && (
              <ul className="mb-2 flex flex-wrap gap-2">
                {anexos.map((a, i) => (
                  <li key={`${a.nome}-${i}`} className="inline-flex items-center gap-2 rounded-lg bg-superficie-alta px-2 py-1 text-xs text-texto">
                    <Paperclip size={12} className="text-primaria" aria-hidden="true" />
                    <span className="max-w-[12rem] truncate" title={a.nome}>{a.nome}</span>
                    <button type="button" aria-label={`Remover ${a.nome}`} onClick={() => setAnexos((l) => l.filter((_, j) => j !== i))} className="toque text-suave hover:text-perigo"><X size={13} /></button>
                  </li>
                ))}
              </ul>
            )}
            <form onSubmit={(e) => void enviar(e)} className="flex items-end gap-2">
              <label className="toque flex h-11 w-11 shrink-0 cursor-pointer items-center justify-center rounded-2xl border border-borda text-suave hover:text-texto" title="Anexar fonte (.prw, .tlpp, .ch, .txt)" aria-label="Anexar arquivo">
                <Paperclip size={18} aria-hidden="true" />
                <input type="file" accept=".prw,.tlpp,.prx,.ch,.txt,.tlp,text/plain" multiple className="hidden"
                  onChange={async (e) => {
                    for (const f of Array.from(e.target.files ?? [])) {
                      if (anexos.length >= 5) break;
                      if (f.size > 1024 * 1024) { setMensagens((m) => [...m, { autor: 'assistente', texto: `O arquivo ${f.name} é grande demais (máx. 1 MB).` }]); continue; }
                      try { const t = await f.text(); setAnexos((l) => (l.length >= 5 ? l : [...l, { nome: f.name.slice(0, 120), conteudo: t }])); } catch { /* ignore */ }
                    }
                    e.target.value = '';
                  }} />
              </label>
              <textarea value={texto} onChange={(e) => setTexto(e.target.value)} rows={1}
                onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); void enviar(e as unknown as FormEvent); } }}
                placeholder="Escreva sua mensagem…" className="max-h-40 min-h-[2.75rem] flex-1 resize-none rounded-2xl border border-borda bg-fundo p-3 text-base text-texto outline-none focus:border-primaria" />
              <button type="submit" disabled={pensando || (!texto.trim() && !anexos.length)} className="toque flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl gradiente-marca text-white disabled:opacity-50" aria-label="Enviar">
                <Send size={18} aria-hidden="true" />
              </button>
            </form>
          </div>
        </section>
      </div>
    </Casca>
  );
}
