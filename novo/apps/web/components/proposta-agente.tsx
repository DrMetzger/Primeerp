'use client';

import { FASES_AGENTE, TIPOS_CASO_AGENTE, type EventoAgente, type PropostaAgente } from '@primehub/shared';
import { Bot, CheckCircle2, ClipboardList, Download, ExternalLink, FileCode2, Hammer, Paperclip, Send, Trash2, TriangleAlert, X } from 'lucide-react';
import Link from 'next/link';
import { useState } from 'react';
import { Aviso, BotaoPrimario, BotaoSecundario, CLASSE_AREA, CLASSE_ENTRADA, Campo, tempoRelativo } from './ui/basico';
import { api } from '@/lib/api';

const COR_TIPO: Record<string, string> = { resposta: '#0ea5e9', duvida: '#64748b', correcao: '#ef4444', melhoria: '#10b981', proposta: '#f59e0b', desenvolvimento: '#8b5cf6', outro: '#94a3b8' };
const COR_CONF: Record<string, string> = { alta: '#10b981', media: '#f59e0b', baixa: '#ef4444' };
const STATUS_APOS: Record<string, string> = { aguardando_cliente: 'Aguardando cliente', aguardando_equipe: 'Aguardando nós', fechado: 'Encerrar o chamado' };
const FASE_INFO = Object.fromEntries(FASES_AGENTE.map((f) => [f.fase, f]));

function lerBase64(f: File): Promise<string> {
  return new Promise((ok, falha) => {
    const r = new FileReader();
    r.onload = () => ok(String(r.result).split(',')[1] ?? '');
    r.onerror = () => falha(new Error('erro ao ler'));
    r.readAsDataURL(f);
  });
}
function tamanhoHumano(n: number): string { return n >= 1048576 ? `${(n / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(n / 1024))} KB`; }

/** Linha do tempo das ações do agente no chamado (pipeline). */
function Pipeline({ eventos }: { eventos: EventoAgente[] }) {
  if (!eventos.length) return null;
  const hora = (s: string) => { try { return new Date(s.replace(' ', 'T')).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }); } catch { return ''; } };
  return (
    <details className="rounded-2xl bg-superficie-alta/40 p-3 text-sm" open>
      <summary className="cursor-pointer font-semibold text-texto">Andamento do agente ({eventos.length})</summary>
      <ol className="mt-3 space-y-2">
        {eventos.map((e, i) => {
          const info = FASE_INFO[e.fase] ?? { rotulo: e.fase, cor: '#94a3b8' };
          const ultimo = i === eventos.length - 1;
          return (
            <li key={i} className="flex items-start gap-2.5">
              <span className="mt-1 h-2.5 w-2.5 shrink-0 rounded-full" style={{ background: info.cor, boxShadow: ultimo ? `0 0 0 3px ${info.cor}33` : 'none' }} aria-hidden="true" />
              <div className="min-w-0 flex-1">
                <p className="flex flex-wrap items-center gap-x-2 leading-tight">
                  <span className="font-semibold" style={{ color: info.cor }}>{info.rotulo}</span>
                  <span className="text-xs text-suave">{hora(e.em)}</span>
                </p>
                {e.texto && <p className="text-suave">{e.texto}</p>}
              </div>
            </li>
          );
        })}
      </ol>
    </details>
  );
}

/**
 * Uma proposta do agente para a equipe decidir: enviar a resposta (pode
 * editar), aprovar a execução (correção, melhoria, proposta...) com
 * instruções, ou descartar. Nada vai ao cliente sem esse clique.
 */
export function CartaoProposta({ p, aoMudar, mostrarChamado = true }: { p: PropostaAgente; aoMudar: () => void; mostrarChamado?: boolean }) {
  const [texto, setTexto] = useState(p.resposta);
  const [status, setStatus] = useState(p.statusSugerido ?? 'aguardando_cliente');
  const [executar, setExecutar] = useState<{ tipo: string; instrucoes: string } | null>(null);
  const [refs, setRefs] = useState<File[]>([]);
  const [ocupado, setOcupado] = useState(false);
  const [aviso, setAviso] = useState<{ t: string; e: boolean }>({ t: '', e: false });
  const emExecucao = p.status === 'aprovado_execucao';
  const cor = COR_TIPO[p.tipo] ?? '#94a3b8';

  async function agir(caminho: string, corpo: object, ok: string) {
    setOcupado(true); setAviso({ t: '', e: false });
    try { const r = await api<{ mensagem?: string }>(`/api/agente/propostas/${p.id}/${caminho}`, { method: 'POST', body: JSON.stringify(corpo) }); setAviso({ t: r.mensagem ?? ok, e: false }); setTimeout(aoMudar, 900); }
    catch (e) { setAviso({ t: e instanceof Error ? e.message : 'Não deu certo.', e: true }); }
    setOcupado(false);
  }
  /** Aprova a execução levando os arquivos de referência que a equipe anexou. */
  async function mandarFazer() {
    if (!executar) return;
    setOcupado(true); setAviso({ t: '', e: false });
    try {
      const arquivos = await Promise.all(refs.slice(0, 10).map(async (f) => ({ nome: f.name, conteudo_base64: await lerBase64(f) })));
      const r = await api<{ mensagem?: string }>(`/api/agente/propostas/${p.id}/executar`, { method: 'POST', body: JSON.stringify({ ...executar, arquivos }) });
      setAviso({ t: r.mensagem ?? 'Aprovado.', e: false }); setTimeout(aoMudar, 900);
    } catch (e) { setAviso({ t: e instanceof Error ? e.message : 'Não deu certo.', e: true }); setOcupado(false); }
  }
  async function baixar(id: number) {
    try { const { url } = await api<{ url: string }>(`/api/agente/arquivos/${id}/link`, { method: 'POST', body: '{}' }); location.href = url; }
    catch (e) { setAviso({ t: e instanceof Error ? e.message : 'Não deu para baixar.', e: true }); }
  }

  return (
    <article className="space-y-4 rounded-3xl border border-borda bg-superficie p-5 shadow-sm" style={{ borderTop: `4px solid ${cor}` }}>
      <header className="flex flex-wrap items-center gap-2">
        <span className="flex h-9 w-9 items-center justify-center rounded-2xl text-white" style={{ background: 'linear-gradient(135deg,#d946ef,#7c3aed)' }}><Bot size={18} aria-hidden="true" /></span>
        <span className="rounded-full px-3 py-1 text-xs font-bold text-white" style={{ background: cor }}>{TIPOS_CASO_AGENTE[p.tipo] ?? p.tipo}</span>
        <span className="rounded-full px-3 py-1 text-xs font-bold" style={{ background: `${COR_CONF[p.confianca] ?? '#94a3b8'}22`, color: COR_CONF[p.confianca] }}>confiança {p.confianca}</span>
        <span className="rounded-full bg-superficie-alta px-3 py-1 text-xs font-semibold text-suave">{p.etapa === 'execucao' ? 'execução pronta para revisar' : 'triagem'}</span>
        {mostrarChamado && <Link href={`/painel/chamado?id=${p.chamadoId}`} className="ml-auto text-sm font-semibold text-primaria hover:underline">#{p.chamadoId} · {p.cliente}</Link>}
        <span className="text-xs text-suave">{tempoRelativo(p.criadoEm)}</span>
      </header>
      {mostrarChamado && <p className="font-semibold text-texto">{p.chamadoTitulo}</p>}

      {emExecucao ? (
        <div className="space-y-2 rounded-2xl border border-violet-500/40 bg-violet-500/10 p-4 text-sm">
          <div className="flex items-start gap-3">
            <Hammer className="mt-0.5 shrink-0 text-violet-500" size={18} aria-hidden="true" />
            <div><p className="font-semibold text-texto">Execução aprovada: o agente está fazendo.</p><p className="text-suave">Quando terminar, volta aqui para você revisar antes de qualquer envio.{p.instrucoes ? ` Suas instruções: "${p.instrucoes}"` : ''}</p></div>
          </div>
          {p.entrada && p.entrada.length > 0 && (
            <div className="flex flex-wrap gap-2 pl-7">
              <span className="text-xs font-semibold text-suave">Referências enviadas:</span>
              {p.entrada.map((a) => (
                <span key={a.id} className="inline-flex items-center gap-1.5 rounded-lg border border-violet-500/40 bg-violet-500/10 px-2 py-0.5 text-xs text-violet-500">
                  <FileCode2 size={12} aria-hidden="true" />{a.nome}<span className="text-suave">{tamanhoHumano(a.tamanho)}</span>
                </span>
              ))}
            </div>
          )}
        </div>
      ) : null}

      {p.eventos && p.eventos.length > 0 && <Pipeline eventos={p.eventos} />}

      {p.plano && (
        <section className="space-y-1">
          <h3 className="flex items-center gap-2 text-sm font-bold text-texto"><ClipboardList size={16} className="text-amber-500" aria-hidden="true" /> Plano sugerido</h3>
          <p className="whitespace-pre-wrap rounded-2xl bg-superficie-alta/60 p-3 text-sm text-texto">{p.plano}</p>
        </section>
      )}
      {p.resumoInterno && (
        <details className="rounded-2xl bg-superficie-alta/40 p-3 text-sm">
          <summary className="cursor-pointer font-semibold text-texto">O que foi analisado (só para a equipe)</summary>
          <p className="mt-2 whitespace-pre-wrap text-suave">{p.resumoInterno}</p>
        </details>
      )}
      {(p.fontes.length > 0 || p.arquivos.length > 0) && (
        <div className="flex flex-wrap gap-2">
          {p.fontes.map((f) => (
            <a key={f.url} href={f.url} target="_blank" rel="noopener noreferrer" className="inline-flex max-w-full items-center gap-1.5 rounded-xl border border-borda px-3 py-1.5 text-xs font-semibold text-primaria hover:bg-primaria/10">
              <ExternalLink size={13} aria-hidden="true" /><span className="truncate">{f.titulo}</span>
            </a>
          ))}
          {p.arquivos.map((a) => (
            <button key={a.id} type="button" onClick={() => void baixar(a.id)} className="inline-flex items-center gap-1.5 rounded-xl border border-violet-500/50 bg-violet-500/10 px-3 py-1.5 text-xs font-semibold text-violet-500 hover:bg-violet-500/20">
              <FileCode2 size={13} aria-hidden="true" />{a.nome}<Download size={12} aria-hidden="true" />
            </button>
          ))}
        </div>
      )}
      {p.motivo && <p className="flex items-center gap-2 text-xs font-semibold text-alerta"><TriangleAlert size={14} aria-hidden="true" /> Atenção: {p.motivo}</p>}

      {!emExecucao && (
        <>
          <Campo rotulo={`Resposta para o cliente${p.atendente ? ` (sai em nome de ${p.atendente})` : ''}`}>
            <textarea rows={Math.min(16, Math.max(6, texto.split('\n').length + 2))} value={texto} onChange={(e) => setTexto(e.target.value)} className={CLASSE_AREA} />
          </Campo>
          <div className="flex flex-wrap items-end gap-3">
            <Campo rotulo="Depois de enviar">
              <select value={status} onChange={(e) => setStatus(e.target.value)} className={`${CLASSE_ENTRADA} w-auto`}>{Object.entries(STATUS_APOS).map(([k, r]) => <option key={k} value={k}>{r}</option>)}</select>
            </Campo>
            <div className="ml-auto flex flex-wrap gap-2">
              <BotaoSecundario disabled={ocupado} className="text-perigo" onClick={() => { if (confirm('Descartar esta proposta? O chamado volta a esperar a equipe.')) void agir('descartar', {}, 'Descartada.'); }}><Trash2 size={16} aria-hidden="true" /> Descartar</BotaoSecundario>
              <BotaoSecundario disabled={ocupado} onClick={() => setExecutar({ tipo: p.tipo === 'resposta' || p.tipo === 'duvida' ? 'correcao' : p.tipo, instrucoes: '' })}><Hammer size={16} aria-hidden="true" /> Aprovar execução…</BotaoSecundario>
              <BotaoPrimario disabled={ocupado || texto.trim().length < 10} onClick={() => { if (confirm('Enviar esta resposta para o cliente agora?')) void agir('enviar', { mensagem: texto, status }, 'Enviada ao cliente.'); }}>
                <Send size={16} aria-hidden="true" /> {p.arquivos.length ? `Enviar com ${p.arquivos.length} arquivo(s)` : 'Enviar ao cliente'}
              </BotaoPrimario>
            </div>
          </div>
          {executar && (
            <div className="space-y-3 rounded-2xl border border-violet-500/40 bg-violet-500/5 p-4">
              <p className="text-sm font-semibold text-texto">O agente vai fazer o trabalho e devolver para você revisar (nada é enviado ao cliente).</p>
              <div className="flex flex-wrap gap-2">
                {['correcao', 'melhoria', 'proposta', 'desenvolvimento', 'resposta'].map((t) => (
                  <button key={t} type="button" aria-pressed={executar.tipo === t} onClick={() => setExecutar({ ...executar, tipo: t })} className="toque rounded-full border px-4 text-sm font-semibold"
                    style={executar.tipo === t ? { background: COR_TIPO[t], borderColor: COR_TIPO[t], color: '#fff' } : { borderColor: 'var(--borda)', color: 'var(--textoSuave)' }}>{TIPOS_CASO_AGENTE[t]}</button>
                ))}
              </div>
              <Campo rotulo="Instruções para o agente (opcional)">
                <textarea rows={3} value={executar.instrucoes} onChange={(e) => setExecutar({ ...executar, instrucoes: e.target.value })} className={CLASSE_AREA} placeholder="Ex.: corrigir no PE M410STTS, não mexer na rotina padrão; proposta com 2 opções de escopo." />
              </Campo>
              <div>
                <p className="mb-1.5 text-sm font-semibold text-texto">Arquivos de referência para o agente (fontes, PDFs, prints)</p>
                <label
                  onDragOver={(ev) => ev.preventDefault()}
                  onDrop={(ev) => { ev.preventDefault(); setRefs((l) => [...l, ...Array.from(ev.dataTransfer.files)].slice(0, 10)); }}
                  className="flex cursor-pointer items-center justify-center gap-2 rounded-xl border border-dashed border-violet-500/50 bg-violet-500/5 px-3 py-3 text-sm text-violet-500 hover:bg-violet-500/10">
                  <Paperclip size={16} aria-hidden="true" /> Arraste aqui ou escolha arquivos (.prw, .tlpp, PDF, imagens…)
                  <input type="file" multiple className="sr-only" onChange={(ev) => { setRefs((l) => [...l, ...Array.from(ev.target.files ?? [])].slice(0, 10)); ev.target.value = ''; }} />
                </label>
                {refs.length > 0 && (
                  <ul className="mt-2 flex flex-wrap gap-2">
                    {refs.map((f, i) => (
                      <li key={`${f.name}-${i}`} className="inline-flex items-center gap-2 rounded-lg bg-superficie-alta px-2 py-1 text-xs text-texto">
                        <FileCode2 size={13} className="text-violet-500" aria-hidden="true" />
                        <span className="max-w-[12rem] truncate" title={f.name}>{f.name}</span>
                        <span className="text-suave">{tamanhoHumano(f.size)}</span>
                        <button type="button" aria-label={`Remover ${f.name}`} onClick={() => setRefs((l) => l.filter((_, n) => n !== i))} className="toque text-suave hover:text-perigo"><X size={13} /></button>
                      </li>
                    ))}
                  </ul>
                )}
              </div>
              <div className="flex justify-end gap-2">
                <BotaoSecundario onClick={() => { setExecutar(null); setRefs([]); }}>Cancelar</BotaoSecundario>
                <BotaoPrimario disabled={ocupado} onClick={() => void mandarFazer()}><CheckCircle2 size={16} aria-hidden="true" /> Aprovar e mandar fazer{refs.length ? ` (${refs.length})` : ''}</BotaoPrimario>
              </div>
            </div>
          )}
        </>
      )}
      <Aviso texto={aviso.t} erro={aviso.e} />
    </article>
  );
}
