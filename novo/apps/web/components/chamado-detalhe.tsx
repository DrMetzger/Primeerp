'use client';

import { CartaoProposta } from './proposta-agente';
import { rotuloStatus, type ChamadoDetalhe, type ClienteDetalhe } from '@primehub/shared';
import { ArrowLeft, Bot, Check, CheckCheck, CheckCircle2, Download, FileText, Lock, Paperclip, RotateCcw, Send, Sparkles, UploadCloud, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { acaoPhp, api } from '@/lib/api';
import { Casca } from './casca';
import { SeloStatus } from './chamados';
import { Esqueleto } from './esqueleto';
import { Avatar } from './ui/avatar';
import { BotaoPrimario, BotaoSecundario, CLASSE_ENTRADA, Painel, dataBR, tamanhoArquivo, tempoRelativo } from './ui/basico';
import { LogoEmpresa } from './ui/logo-empresa';

type Visao = 'equipe' | 'cliente';
interface PassoIa { texto?: string; rotulo?: string; mensagem?: string }
interface EstadoIa { ok: boolean; terminou?: boolean; sucesso?: boolean; passos?: (PassoIa | string)[]; resposta?: string; fonte?: string; status_sugerido?: string; erro?: string }

const STATUS_APOS: { valor: string; rotulo: string }[] = [
  { valor: 'aguardando_cliente', rotulo: 'Aguardando cliente' },
  { valor: 'aguardando_equipe', rotulo: 'Aguardando nós' },
  { valor: 'fechado', rotulo: 'Encerrar chamado' },
];

function textoPasso(p: PassoIa | string): string {
  return typeof p === 'string' ? p : p.texto ?? p.rotulo ?? p.mensagem ?? '';
}

/** Conversa do chamado + resposta (com anexos), igual pra equipe e cliente. */
export function ChamadoDetalheTela({ visao }: { visao: Visao }) {
  const [id, setId] = useState(0);
  const [dados, setDados] = useState<ChamadoDetalhe | null>(null);
  const [equipe, setEquipe] = useState<ClienteDetalhe['equipe']>([]);
  const [erro, setErro] = useState('');
  const [mensagem, setMensagem] = useState('');
  const [anexos, setAnexos] = useState<File[]>([]);
  const [statusApos, setStatusApos] = useState('aguardando_cliente');
  const [notificar, setNotificar] = useState(true);
  const [enviando, setEnviando] = useState(false);
  const [aviso, setAviso] = useState('');
  const [ia, setIa] = useState<{ rodando: boolean; passos: string[]; erro: string }>({ rodando: false, passos: [], erro: '' });
  const fimConversa = useRef<HTMLDivElement>(null);
  const [arrastando, setArrastando] = useState(false);
  const [respManual, setRespManual] = useState(false);

  const carregar = useCallback(async (cid: number) => {
    try {
      const d = await api<ChamadoDetalhe>(`/api/chamados/${cid}`);
      setDados(d);
      if (d.ia?.statusSugerido) setStatusApos(d.ia.statusSugerido);
    } catch (e) {
      setErro(e instanceof Error ? e.message : 'Não consegui abrir esse chamado.');
    }
  }, []);

  useEffect(() => {
    const cid = Number(new URLSearchParams(location.search).get('id') ?? 0);
    setId(cid);
    if (!cid) { setErro('Chamado não informado.'); return; }
    void carregar(cid);
  }, [carregar]);

  // Lista da equipe (pra trocar responsável) vem do detalhe do cliente.
  useEffect(() => {
    if (visao !== 'equipe' || !dados?.chamado.clienteId) return;
    api<ClienteDetalhe>(`/api/clientes/${dados.chamado.clienteId}`).then((c) => setEquipe(c.equipe)).catch(() => undefined);
  }, [visao, dados?.chamado.clienteId]);

  useEffect(() => { fimConversa.current?.scrollIntoView({ block: 'end' }); }, [dados?.mensagens.length]);

  async function responder() {
    if (!mensagem.trim() && !anexos.length) return;
    setEnviando(true); setAviso('');
    try {
      const campos: Record<string, string> = { acao: 'responder_chamado', chamado_id: String(id), mensagem };
      if (visao === 'equipe') { campos.status_apos_resposta = statusApos; campos.notificar_cliente = notificar ? '1' : '0'; }
      const r = await acaoPhp('api_acoes.php', campos, anexos);
      setMensagem(''); setAnexos([]);
      setAviso(r.avisos?.length ? `Resposta enviada, mas: ${r.avisos.join(' ')}` : 'Resposta enviada.');
      await carregar(id);
    } catch (e) {
      setAviso(e instanceof Error ? e.message : 'Não deu pra enviar agora.');
    } finally {
      setEnviando(false);
    }
  }

  async function trocarResponsavel(valor: string) {
    try {
      await acaoPhp('api_acoes.php', { acao: 'atribuir_responsavel', chamado_id: String(id), responsavel_id: valor });
      await carregar(id);
    } catch (e) { setAviso(e instanceof Error ? e.message : 'Não deu pra trocar agora.'); }
  }

  async function encerrarOuReabrir() {
    if (!dados) return;
    const fechado = dados.chamado.status === 'fechado';
    try {
      if (visao === 'cliente') await acaoPhp('api_acoes.php', { acao: fechado ? 'reabrir_chamado' : 'encerrar_chamado', chamado_id: String(id) });
      else await acaoPhp('api_acoes.php', { acao: 'responder_chamado', chamado_id: String(id), mensagem: '', status_apos_resposta: fechado ? 'aguardando_equipe' : 'fechado', notificar_cliente: '0' });
      await carregar(id);
    } catch (e) { setAviso(e instanceof Error ? e.message : 'Não deu certo agora.'); }
  }

  /** Pede a sugestão da IA: só preenche o campo, NUNCA envia pro cliente. */
  async function sugerirComIa() {
    setIa({ rodando: true, passos: ['Começando a análise…'], erro: '' });
    try {
      await acaoPhp<EstadoIa>('ia_sugerir_resposta.php', { acao: 'iniciar', chamado_id: String(id) });
      let anteriorFalhou = false;
      for (let i = 0; i < 40; i++) {
        let estado: EstadoIa;
        try {
          estado = await acaoPhp<EstadoIa>('ia_sugerir_resposta.php', { acao: 'passo', chamado_id: String(id), anterior_falhou: anteriorFalhou ? '1' : '0' });
          anteriorFalhou = false;
        } catch { anteriorFalhou = true; continue; }
        setIa((v) => ({ ...v, passos: (estado.passos ?? []).map(textoPasso).filter(Boolean) }));
        if (estado.terminou) {
          if (estado.sucesso && estado.resposta) {
            setMensagem(estado.resposta);
            if (estado.status_sugerido) setStatusApos(estado.status_sugerido);
            setIa({ rodando: false, passos: [`Pronto — ${estado.fonte ?? 'confira antes de enviar'}.`], erro: '' });
          } else {
            setIa({ rodando: false, passos: [], erro: estado.erro ?? 'Dessa vez não achei uma boa resposta. Vale responder do seu jeito.' });
          }
          return;
        }
      }
      setIa({ rodando: false, passos: [], erro: 'A análise demorou demais. Tente de novo em instantes.' });
    } catch (e) {
      setIa({ rodando: false, passos: [], erro: e instanceof Error ? e.message : 'Não consegui analisar agora.' });
    }
  }

  const voltar = visao === 'equipe' ? '/painel/chamados' : '/cliente';
  const acoes = (
    <a href={voltar} className="toque inline-flex items-center gap-2 rounded-xl border border-borda bg-superficie px-3 text-sm font-semibold text-texto">
      <ArrowLeft size={18} aria-hidden="true" /> <span className="hidden sm:inline">Voltar</span>
    </a>
  );

  return (
    <Casca tipo={visao} titulo={dados ? `Chamado #${dados.chamado.id}` : 'Chamado'} acoes={acoes}>
      {erro && <Painel><p className="text-center text-suave">{erro}</p></Painel>}
      {!erro && !dados && (
        <div role="status" aria-label="Carregando o chamado" className="space-y-4">
          <Esqueleto className="h-32" /><Esqueleto className="h-24" /><Esqueleto className="h-24" /><Esqueleto className="h-48" />
        </div>
      )}
      {dados && (
        <div className="grid gap-4 lg:grid-cols-[1fr_20rem]">
          <div className="min-w-0 space-y-4">
            <Painel>
              <div className="flex items-start gap-3">
                {visao === 'equipe' && <LogoEmpresa nome={dados.chamado.cliente} logo={dados.chamado.clienteLogo} tamanho="m" />}
                <div className="min-w-0 flex-1">
                  <h2 className="text-lg font-bold leading-snug text-texto">{dados.chamado.titulo}</h2>
                  <p className="mt-1 text-sm text-suave">
                    {visao === 'equipe' && dados.chamado.clienteId ? <a className="font-semibold text-primaria underline-offset-2 hover:underline" href={`/painel/cliente?id=${dados.chamado.clienteId}`}>{dados.chamado.cliente}</a> : null}
                    {visao === 'equipe' ? ' · ' : ''}Aberto em {dataBR(dados.chamado.criadoEm, true)}
                    {dados.chamado.setor ? ` · ${dados.chamado.setor}` : ''}{dados.chamado.categoria ? ` · ${dados.chamado.categoria}` : ''}
                  </p>
                </div>
                <SeloStatus status={dados.chamado.status} />
              </div>
            </Painel>

            {dados.mensagens.some((m) => m.anexos.length) && (
              <button type="button" onClick={() => document.getElementById('anexos-do-chamado')?.scrollIntoView({ behavior: 'smooth' })}
                className="toque flex w-full items-center justify-center gap-2 rounded-2xl border border-primaria/40 bg-primaria/10 px-4 font-semibold text-primaria lg:hidden">
                <Paperclip size={18} aria-hidden="true" /> Ver os {dados.mensagens.reduce((n, m) => n + m.anexos.length, 0)} anexos do chamado
              </button>
            )}
            <ol className="space-y-4" aria-label="Conversa do chamado">
              {dados.mensagens.map((m) => {
                const nosso = m.autorTipo === 'equipe';
                const minha = (visao === 'equipe') === nosso;
                return (
                  <li key={m.id} id={`msg-${m.id}`} className={`flex scroll-mt-24 items-end gap-2 rounded-3xl transition-colors ${minha ? 'flex-row-reverse' : ''}`}>
                    <Avatar nome={m.autorNome} foto={m.autorFoto} tamanho={40} anel={nosso ? 'ring-primaria/60' : 'ring-destaque/60'} />
                    <div className={`max-w-[85%] sm:max-w-[75%] ${minha ? 'items-end' : 'items-start'} flex flex-col gap-1`}>
                      <p className={`flex flex-wrap items-baseline gap-x-2 px-1 text-xs ${minha ? 'justify-end text-right' : ''}`}>
                        <b className="text-sm font-semibold text-texto">{m.autorNome}</b>
                        {m.autorPapel && <span className={`rounded-full px-2 py-0.5 font-semibold ${nosso ? 'bg-primaria/15 text-primaria' : 'bg-destaque/15 text-destaque'}`}>{m.autorPapel}</span>}
                        <span className="text-suave" title={dataBR(m.criadoEm, true)}>{tempoRelativo(m.criadoEm)}</span>
                      </p>
                      <div className={`rounded-3xl border p-4 shadow-sm ${minha ? 'rounded-br-md border-primaria/30 bg-primaria/10' : 'rounded-bl-md border-borda bg-superficie'}`}>
                        <div className="whitespace-pre-wrap break-words text-[15px] leading-relaxed text-texto">{m.mensagem}</div>
                        {m.anexos.length > 0 && (
                          <ul className="mt-3 grid gap-2 sm:grid-cols-2">
                            {m.anexos.map((a) => (
                              <li key={a.id}>
                                {a.tipo === 'imagem' ? (
                                  <a href={`/download_anexo_chamado.php?id=${a.id}`} target="_blank" rel="noopener noreferrer" className="block overflow-hidden rounded-2xl border border-borda bg-black/20">
                                    <img src={`/download_anexo_chamado.php?id=${a.id}&ver=1`} alt={a.nome} loading="lazy" className="max-h-64 w-full object-contain" />
                                  </a>
                                ) : a.tipo === 'video' ? (
                                  <video controls preload="metadata" src={`/download_anexo_chamado.php?id=${a.id}&ver=1`} className="max-h-64 w-full rounded-2xl bg-black" />
                                ) : (
                                  <a href={`/download_anexo_chamado.php?id=${a.id}`} className="toque flex items-center gap-2 rounded-xl border border-borda bg-fundo px-3 text-sm text-texto hover:border-primaria">
                                    {a.tipo === 'pdf' ? <FileText size={16} className="shrink-0 text-perigo" aria-hidden="true" /> : <Download size={16} className="shrink-0 text-primaria" aria-hidden="true" />}
                                    <span className="min-w-0 flex-1 truncate">{a.nome}</span> <span className="text-xs text-suave">{tamanhoArquivo(a.tamanho)}</span>
                                  </a>
                                )}
                              </li>
                            ))}
                          </ul>
                        )}
                      </div>
                      {minha && (
                        <p className={`flex items-center gap-1 px-1 text-xs ${m.vistoPor.length ? 'text-sucesso' : 'text-suave'}`} title={m.vistoPor.map((v) => `${v.nome} · ${dataBR(v.em, true)}`).join('\n')}>
                          {m.vistoPor.length ? <CheckCheck size={14} aria-hidden="true" /> : <Check size={14} aria-hidden="true" />}
                          {m.vistoPor.length ? `Visto por ${m.vistoPor.map((v) => v.nome.split(' ')[0]).join(', ')} ${tempoRelativo(m.vistoPor.reduce((a, v) => (v.em > a ? v.em : a), m.vistoPor[0]?.em ?? ''))}` : 'Enviado, ainda não visto'}
                        </p>
                      )}
                    </div>
                  </li>
                );
              })}
            </ol>
            <div ref={fimConversa} />

            <div className="relative"
              onDragOver={(e) => { if (e.dataTransfer.types.includes('Files')) { e.preventDefault(); setArrastando(true); } }}
              onDragLeave={(e) => { if (!e.currentTarget.contains(e.relatedTarget as Node | null)) setArrastando(false); }}
              onDrop={(e) => { e.preventDefault(); setArrastando(false); const f = Array.from(e.dataTransfer.files); if (f.length) { setRespManual(true); setAnexos((l) => [...l, ...f]); } }}
              onPaste={(e) => { const f = Array.from(e.clipboardData.files); if (f.length) { e.preventDefault(); setRespManual(true); setAnexos((l) => [...l, ...f]); } }}>
            {arrastando && (
              <div className="pointer-events-none absolute inset-0 z-10 flex flex-col items-center justify-center gap-2 rounded-3xl border-2 border-dashed border-primaria bg-primaria/15 text-primaria backdrop-blur-sm">
                <UploadCloud size={40} aria-hidden="true" /><p className="font-bold">Solte aqui para anexar</p><p className="text-sm">Imagens, vídeos, PDFs, planilhas, fontes…</p>
              </div>
            )}
            <Painel titulo={dados.chamado.status === 'fechado' ? 'Chamado encerrado — responder reabre' : 'Responder'}>
              {visao === 'equipe' && (
                <div className="mb-3 flex flex-wrap items-center gap-2">
                  <BotaoSecundario onClick={() => void sugerirComIa()} disabled={ia.rodando}>
                    <Sparkles size={18} aria-hidden="true" /> {ia.rodando ? 'Analisando…' : 'Sugerir resposta com IA'}
                  </BotaoSecundario>
                  <span className="text-xs text-suave">A sugestão só preenche o campo. Você confere antes de enviar.</span>
                </div>
              )}
              {(ia.passos.length > 0 || ia.erro) && (
                <div className="mb-3 rounded-2xl border border-borda bg-fundo p-3 text-sm" aria-live="polite">
                  {ia.passos.slice(-4).map((p, i) => <p key={i} className="flex items-start gap-2 text-suave"><Bot size={16} className="mt-0.5 shrink-0 text-destaque" aria-hidden="true" />{p}</p>)}
                  {ia.erro && <p className="text-suave">{ia.erro}</p>}
                </div>
              )}
              {visao === 'equipe' && dados.agente && <div className="mb-4"><CartaoProposta p={dados.agente} mostrarChamado={false} aoMudar={() => void carregar(id)} /></div>}
              {visao === 'equipe' && dados.agente && !respManual && (
                <button type="button" onClick={() => setRespManual(true)} className="toque mb-2 text-sm font-semibold text-suave hover:text-primaria">
                  Prefere responder você mesmo? Responder manualmente
                </button>
              )}
              {visao === 'equipe' && !dados.agente && dados.ia?.resposta && !mensagem && !ia.rodando && (
                <button type="button" onClick={() => setMensagem(dados.ia?.resposta ?? '')} className="toque mb-3 flex w-full items-start gap-2 rounded-2xl border border-destaque/40 bg-destaque/10 p-3 text-left text-sm text-texto">
                  <Sparkles size={18} className="mt-0.5 shrink-0 text-destaque" aria-hidden="true" />
                  <span><b>A IA já deixou uma sugestão pronta</b> ({dados.ia.status}). Toque para usar no campo e revisar.</span>
                </button>
              )}
              {(!(visao === 'equipe' && dados.agente) || respManual) && (<>
              {visao === 'equipe' && dados.agente && respManual && (
                <p className="mb-2 text-sm font-semibold text-suave">Resposta manual (ignora a proposta do agente):</p>
              )}
              <label className="block">
                <span className="sr-only">Mensagem</span>
                <textarea value={mensagem} onChange={(e) => setMensagem(e.target.value)} rows={6} placeholder="Escreva a resposta… (arraste ou cole imagens, vídeos e arquivos aqui)"
                  className="w-full rounded-2xl border border-borda bg-fundo p-3 text-base leading-relaxed text-texto outline-none focus:border-primaria" />
              </label>
              {anexos.length > 0 && (
                <ul className="mt-2 flex flex-wrap gap-2">
                  {anexos.map((a, i) => (
                    <li key={`${a.name}-${i}`} className="inline-flex items-center gap-2 rounded-xl border border-borda bg-fundo px-3 py-2 text-sm text-texto">
                      <Paperclip size={14} aria-hidden="true" /> <span className="max-w-40 truncate">{a.name}</span>
                      <button type="button" aria-label={`Tirar ${a.name}`} onClick={() => setAnexos((l) => l.filter((_, j) => j !== i))} className="text-suave hover:text-perigo"><X size={16} /></button>
                    </li>
                  ))}
                </ul>
              )}
              <div className="mt-3 flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
                <label className="toque inline-flex cursor-pointer items-center justify-center gap-2 rounded-xl border border-borda bg-superficie px-4 font-semibold text-texto hover:bg-superficie-alta">
                  <Paperclip size={18} aria-hidden="true" /> Anexar
                  <input type="file" multiple className="sr-only" onChange={(e) => { const f = Array.from(e.target.files ?? []); setAnexos((l) => [...l, ...f]); e.target.value = ''; }} />
                </label>
                {visao === 'equipe' && (
                  <>
                    <label className="flex items-center gap-2 text-sm text-texto">
                      <span className="shrink-0">Depois:</span>
                      <select value={statusApos} onChange={(e) => setStatusApos(e.target.value)} className={`${CLASSE_ENTRADA} sm:w-52`}>
                        {STATUS_APOS.map((s) => <option key={s.valor} value={s.valor}>{s.rotulo}</option>)}
                      </select>
                    </label>
                    <label className="toque flex items-center gap-2 text-sm text-texto">
                      <input type="checkbox" checked={notificar} onChange={(e) => setNotificar(e.target.checked)} className="h-5 w-5 accent-[var(--primaria)]" />
                      Avisar o cliente
                    </label>
                  </>
                )}
                <BotaoPrimario onClick={() => void responder()} disabled={enviando || (!mensagem.trim() && !anexos.length)} className="sm:ml-auto">
                  <Send size={18} aria-hidden="true" /> {enviando ? 'Enviando…' : 'Enviar resposta'}
                </BotaoPrimario>
              </div>
              </>)}
              {aviso && <p className="mt-3 text-sm text-suave" role="status">{aviso}</p>}
            </Painel>
            </div>
          </div>

          <aside className="space-y-4 lg:sticky lg:top-24 lg:max-h-[calc(100dvh-7rem)] lg:self-start lg:overflow-y-auto lg:pb-4">
            <PainelAnexos mensagens={dados.mensagens} />
            <Painel titulo="Detalhes">
              <dl className="space-y-3 text-sm">
                <div><dt className="text-suave">Situação</dt><dd className="font-semibold text-texto">{rotuloStatus[dados.chamado.status]}</dd></div>
                <div><dt className="text-suave">Prioridade</dt><dd className="font-semibold capitalize text-texto">{dados.chamado.prioridade === 'media' ? 'Média' : dados.chamado.prioridade}</dd></div>
                <div><dt className="text-suave">Atualizado</dt><dd className="text-texto">{dataBR(dados.chamado.atualizadoEm, true)}</dd></div>
                <div>
                  <dt className="text-suave">Responsável</dt>
                  <dd>
                    {visao === 'equipe' ? (
                      <select value={dados.chamado.responsavelId ?? ''} onChange={(e) => void trocarResponsavel(e.target.value)} className={`${CLASSE_ENTRADA} mt-1`} aria-label="Responsável pelo chamado">
                        <option value="">Ninguém ainda</option>
                        {equipe.map((u) => <option key={u.id} value={u.id}>{u.nome}</option>)}
                      </select>
                    ) : <span className="text-texto">{dados.chamado.responsavel ?? 'Nossa equipe'}</span>}
                  </dd>
                </div>
              </dl>
              <BotaoSecundario onClick={() => void encerrarOuReabrir()} className="mt-4 w-full">
                {dados.chamado.status === 'fechado' ? <><RotateCcw size={18} aria-hidden="true" /> Reabrir chamado</> : <><CheckCircle2 size={18} aria-hidden="true" /> Encerrar chamado</>}
              </BotaoSecundario>
            </Painel>
            {visao === 'equipe' && (
              <p className="flex items-start gap-2 px-2 text-xs text-suave"><Lock size={14} className="mt-0.5 shrink-0" aria-hidden="true" />As respostas saem no seu nome, com aviso por e-mail e WhatsApp quando marcado.</p>
            )}
          </aside>
        </div>
      )}
    </Casca>
  );
}


/** Todos os anexos do chamado num lugar só (do mais novo pro mais antigo), com quem mandou e atalho pra mensagem. */
function PainelAnexos({ mensagens }: { mensagens: ChamadoDetalhe['mensagens'] }) {
  const [filtro, setFiltro] = useState<'todos' | 'imagem' | 'documento'>('todos');
  const todos = mensagens.flatMap((m) => m.anexos.map((a) => ({ ...a, msgId: m.id, autor: m.autorNome, foto: m.autorFoto, em: m.criadoEm, equipe: m.autorTipo === 'equipe' }))).reverse();
  if (!todos.length) return null;
  const lista = todos.filter((a) => filtro === 'todos' || (filtro === 'imagem' ? a.tipo === 'imagem' || a.tipo === 'video' : a.tipo === 'pdf' || a.tipo === 'arquivo'));
  const irPara = (id: number) => {
    const el = document.getElementById(`msg-${id}`);
    if (!el) return;
    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    el.classList.add('bg-primaria/15');
    setTimeout(() => el.classList.remove('bg-primaria/15'), 1800);
  };
  return (
    <div id="anexos-do-chamado" className="scroll-mt-24">
    <Painel titulo={`Anexos do chamado (${todos.length})`}>
      <div className="mb-3 flex gap-1 rounded-xl bg-fundo p-1 text-xs font-semibold" role="tablist" aria-label="Filtrar anexos">
        {([['todos', 'Todos'], ['imagem', 'Imagens e vídeos'], ['documento', 'Documentos']] as const).map(([v, r]) => (
          <button key={v} type="button" role="tab" aria-selected={filtro === v} onClick={() => setFiltro(v)} className={`flex-1 rounded-lg px-2 py-1.5 ${filtro === v ? 'bg-primaria text-white' : 'text-suave hover:text-texto'}`}>{r}</button>
        ))}
      </div>
      <ul className="space-y-2">
        {lista.map((a) => (
          <li key={a.id} className="flex items-center gap-2 rounded-2xl border border-borda bg-fundo p-2">
            {a.tipo === 'imagem' ? (
              <a href={`/download_anexo_chamado.php?id=${a.id}&ver=1`} target="_blank" rel="noopener noreferrer" className="h-12 w-12 shrink-0 overflow-hidden rounded-xl bg-black/20" aria-label={`Ver ${a.nome}`}>
                <img src={`/download_anexo_chamado.php?id=${a.id}&ver=1`} alt="" loading="lazy" className="h-full w-full object-cover" />
              </a>
            ) : (
              <span className={`flex h-12 w-12 shrink-0 items-center justify-center rounded-xl text-[10px] font-bold uppercase ${a.tipo === 'pdf' ? 'bg-perigo/15 text-perigo' : a.tipo === 'video' ? 'bg-fuchsia-500/15 text-fuchsia-500' : 'bg-primaria/15 text-primaria'}`}>
                {a.nome.split('.').pop()?.slice(0, 4) ?? 'arq'}
              </span>
            )}
            <div className="min-w-0 flex-1">
              <p className="line-clamp-2 break-all text-sm font-semibold leading-tight text-texto" title={a.nome}>{a.nome}</p>
              <p className="truncate text-xs text-suave">{a.autor.split(' ')[0]} · {tempoRelativo(a.em)} · {tamanhoArquivo(a.tamanho)}</p>
            </div>
            <button type="button" onClick={() => irPara(a.msgId)} aria-label={`Ir para a mensagem de ${a.nome}`} title="Ver na conversa" className="toque flex h-9 w-9 items-center justify-center rounded-xl text-suave hover:bg-superficie-alta hover:text-texto">↥</button>
            <a href={`/download_anexo_chamado.php?id=${a.id}`} aria-label={`Baixar ${a.nome}`} title="Baixar" className="toque flex h-9 w-9 items-center justify-center rounded-xl text-primaria hover:bg-primaria/10"><Download size={18} /></a>
          </li>
        ))}
        {!lista.length && <li className="py-2 text-center text-sm text-suave">Nenhum anexo desse tipo.</li>}
      </ul>
    </Painel>
    </div>
  );
}
