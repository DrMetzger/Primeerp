'use client';

import { TIPOS_CASO_AGENTE, type PropostaAgente } from '@primehub/shared';
import { Bot, CheckCircle2, ClipboardList, Download, ExternalLink, FileCode2, Hammer, Send, Trash2, TriangleAlert } from 'lucide-react';
import Link from 'next/link';
import { useState } from 'react';
import { Aviso, BotaoPrimario, BotaoSecundario, CLASSE_AREA, CLASSE_ENTRADA, Campo, tempoRelativo } from './ui/basico';
import { api } from '@/lib/api';

const COR_TIPO: Record<string, string> = { resposta: '#0ea5e9', duvida: '#64748b', correcao: '#ef4444', melhoria: '#10b981', proposta: '#f59e0b', desenvolvimento: '#8b5cf6', outro: '#94a3b8' };
const COR_CONF: Record<string, string> = { alta: '#10b981', media: '#f59e0b', baixa: '#ef4444' };
const STATUS_APOS: Record<string, string> = { aguardando_cliente: 'Aguardando cliente', aguardando_equipe: 'Aguardando nós', fechado: 'Encerrar o chamado' };

/**
 * Uma proposta do agente para a equipe decidir: enviar a resposta (pode
 * editar), aprovar a execução (correção, melhoria, proposta...) com
 * instruções, ou descartar. Nada vai ao cliente sem esse clique.
 */
export function CartaoProposta({ p, aoMudar, mostrarChamado = true }: { p: PropostaAgente; aoMudar: () => void; mostrarChamado?: boolean }) {
  const [texto, setTexto] = useState(p.resposta);
  const [status, setStatus] = useState(p.statusSugerido ?? 'aguardando_cliente');
  const [executar, setExecutar] = useState<{ tipo: string; instrucoes: string } | null>(null);
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
        <div className="flex items-start gap-3 rounded-2xl border border-violet-500/40 bg-violet-500/10 p-4 text-sm">
          <Hammer className="mt-0.5 shrink-0 text-violet-500" size={18} aria-hidden="true" />
          <div><p className="font-semibold text-texto">Execução aprovada: o agente está fazendo.</p><p className="text-suave">Quando terminar, volta aqui para você revisar antes de qualquer envio.{p.instrucoes ? ` Suas instruções: "${p.instrucoes}"` : ''}</p></div>
        </div>
      ) : null}

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
              <div className="flex justify-end gap-2">
                <BotaoSecundario onClick={() => setExecutar(null)}>Cancelar</BotaoSecundario>
                <BotaoPrimario disabled={ocupado} onClick={() => void agir('executar', executar, 'Aprovado.')}><CheckCircle2 size={16} aria-hidden="true" /> Aprovar e mandar fazer</BotaoPrimario>
              </div>
            </div>
          )}
        </>
      )}
      <Aviso texto={aviso.t} erro={aviso.e} />
    </article>
  );
}
