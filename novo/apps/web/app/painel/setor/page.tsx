'use client';

import type { ListaChamados as Lista, SetorDetalhe } from '@primehub/shared';
import { ArrowLeft, BarChart3, CalendarDays, Code2, Download, ExternalLink, FileText, Lightbulb, LifeBuoy, Mail, Pencil, Plus, Sparkles, Trash2, Users, Video } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { CalendarioMes } from '@/components/calendario-mes';
import { Casca } from '@/components/casca';
import { ListaChamados } from '@/components/chamados';
import { Esqueleto, EsqueletoLista } from '@/components/esqueleto';
import { NovoChamado } from '@/components/novo-chamado';
import { AbasIcones, type AbaIcone } from '@/components/ui/abas-icones';
import { Avatar } from '@/components/ui/avatar';
import { Aviso, BotaoPrimario, BotaoSecundario, CLASSE_AREA, CLASSE_ENTRADA, Campo, EscolherArquivo, Modal, Painel, Vazio, dataBR, tamanhoArquivo } from '@/components/ui/basico';
import { LogoEmpresa } from '@/components/ui/logo-empresa';
import { api, enviarAcao } from '@/lib/api';

type Aba = 'chamados' | 'pessoas' | 'documentos' | 'treinamentos' | 'videos' | 'fontes' | 'propostas' | 'metricas' | 'ia';
const TIPOS = { treinamento: 'Treinamento', reuniao: 'Reunião', conversa: 'Conversa', auxilio: 'Auxílio' } as const;
const STATUS_T = { planejado: 'Planejado', em_andamento: 'Em andamento', concluido: 'Concluído', cancelado: 'Cancelado' } as const;
const STATUS_P = { proposta: 'Proposta', aprovada: 'Aprovada', em_andamento: 'Em andamento', concluida: 'Concluída', recusada: 'Recusada' } as const;
const COR_TIPO: Record<string, string> = { treinamento: '#10b981', reuniao: '#6366f1', conversa: '#0ea5e9', auxilio: '#f59e0b' };

type Formulario =
  | { tipo: 'documento'; titulo: string; descricao: string; categoria: string; arquivo: File | null }
  | { tipo: 'video'; nome_rotina: string; arquivo: File | null }
  | { tipo: 'fonte'; fonte_id: string; titulo: string; ponto_entrada: string; descricao: string; arquivo: File | null }
  | { tipo: 'proposta'; titulo: string; descricao: string; arquivo: File | null }
  | { tipo: 'kpi'; kpi_id: string; nome_kpi: string; valor: string; meta: string; unidade: string; periodo: string; direcao_melhor: string; observacoes: string }
  | { tipo: 'treinamento'; treinamento_id: string; titulo: string; tipoT: string; status: string; data_inicio: string; hora_inicio: string; data_fim: string; hora_fim: string; link_reuniao: string; descricao: string; responsavel_id: string };

export default function PaginaSetor() {
  const [ids, setIds] = useState({ cliente: 0, setor: 0 });
  const [d, setD] = useState<SetorDetalhe | null>(null);
  const [chamados, setChamados] = useState<Lista | null>(null);
  const [erro, setErro] = useState('');
  const [aba, setAba] = useState<Aba>('chamados');
  const [form, setForm] = useState<Formulario | null>(null);
  const [novoChamado, setNovoChamado] = useState(false);
  const [aviso, setAviso] = useState<{ t: string; e: boolean }>({ t: '', e: false });
  const [ocupado, setOcupado] = useState(false);
  const [ia, setIa] = useState('');

  const carregar = useCallback(async (cliente: number, setor: number) => {
    try {
      const [det, ch] = await Promise.all([api<SetorDetalhe>(`/api/clientes/${cliente}/setores/${setor}`), api<Lista>(`/api/chamados?cliente=${cliente}&setor=${setor}&status=`)]);
      setD(det); setChamados(ch);
    } catch (e) { setErro(e instanceof Error ? e.message : 'Não consegui abrir esse setor.'); }
  }, []);

  useEffect(() => {
    const q = new URLSearchParams(location.search);
    const v = { cliente: Number(q.get('cliente') ?? 0), setor: Number(q.get('id') ?? 0) };
    const a = location.hash.slice(1) as Aba;
    if (a) setAba(a);
    setIds(v);
    if (!v.cliente || !v.setor) { setErro('Setor não informado.'); return; }
    void carregar(v.cliente, v.setor);
  }, [carregar]);

  /** Mesmas ações da tela do cliente de sempre (cliente.php), com as mesmas regras. */
  async function agir(campos: Record<string, string>, arquivos: Record<string, File | null> = {}, okMsg?: string) {
    setOcupado(true); setAviso({ t: '', e: false });
    try {
      const r = await enviarAcao(`cliente.php?id=${ids.cliente}`, { setor_id: String(ids.setor), ...campos }, arquivos);
      setAviso({ t: okMsg ?? r.mensagens.sucesso ?? 'Pronto!', e: false });
      setForm(null);
      await carregar(ids.cliente, ids.setor);
    } catch (e) { setAviso({ t: e instanceof Error ? e.message : 'Não deu certo.', e: true }); }
    setOcupado(false);
  }

  async function gerarIa() {
    setOcupado(true); setIa('Analisando chamados e métricas do setor…');
    try {
      const corpo = new FormData();
      corpo.append('acao', 'gerar_sugestao_ia'); corpo.append('ajax', '1'); corpo.append('setor_id', String(ids.setor));
      const r = await fetch(`/cliente.php?id=${ids.cliente}`, { method: 'POST', body: corpo, credentials: 'same-origin', headers: { 'X-PrimeHub': '1' } });
      const j = (await r.json()) as { sucesso?: boolean; mensagem?: string; sugestao?: string };
      setIa(j.sucesso ? '' : (j.mensagem ?? 'Não deu para gerar agora.'));
      await carregar(ids.cliente, ids.setor);
    } catch { setIa('Não deu para gerar agora. Tente de novo em instantes.'); }
    setOcupado(false);
  }

  const abas: AbaIcone<Aba>[] = d ? [
    { id: 'chamados', rotulo: 'Chamados', icone: LifeBuoy, cor: '#0ea5e9', contagem: chamados?.itens.filter((c) => c.status !== 'fechado').length ?? 0 },
    { id: 'pessoas', rotulo: 'Pessoas', icone: Users, cor: '#8b5cf6', contagem: d.pessoas.length },
    { id: 'documentos', rotulo: 'Documentos', icone: FileText, cor: '#f59e0b', contagem: d.documentos.length },
    { id: 'treinamentos', rotulo: 'Calendário', icone: CalendarDays, cor: '#10b981', contagem: d.treinamentos.length },
    { id: 'videos', rotulo: 'Vídeo aulas', icone: Video, cor: '#f43f5e', contagem: d.videoaulas.length },
    { id: 'fontes', rotulo: 'Fontes ADVPL', icone: Code2, cor: '#06b6d4', contagem: d.fontes.length },
    { id: 'propostas', rotulo: 'Propostas', icone: Lightbulb, cor: '#f97316', contagem: d.propostas.length },
    { id: 'metricas', rotulo: 'Métricas', icone: BarChart3, cor: '#84cc16', contagem: d.kpis.length },
    { id: 'ia', rotulo: 'IA do setor', icone: Sparkles, cor: '#d946ef' },
  ] : [];

  const voltar = (
    <a href={`/painel/cliente?id=${ids.cliente}#setores`} className="toque inline-flex items-center gap-2 rounded-xl border border-borda bg-superficie px-3 text-sm font-semibold text-texto">
      <ArrowLeft size={18} aria-hidden="true" /> <span className="hidden sm:inline">Cliente</span>
    </a>
  );
  const BotaoNovo = ({ rotulo, aoTocar }: { rotulo: string; aoTocar: () => void }) => <BotaoPrimario onClick={aoTocar}><Plus size={18} aria-hidden="true" /> {rotulo}</BotaoPrimario>;
  const linhaArquivo = (id: number, titulo: string, sub: string, href: string, cor: string, Icone: typeof FileText, extra?: React.ReactNode) => (
    <li key={id} className="flex items-center gap-3 rounded-2xl border border-borda bg-superficie p-3 shadow-sm">
      <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl" style={{ background: `color-mix(in oklab, ${cor} 18%, transparent)`, color: cor }}><Icone size={20} aria-hidden="true" /></span>
      <div className="min-w-0 flex-1"><p className="truncate font-semibold text-texto">{titulo}</p><p className="truncate text-xs text-suave">{sub}</p></div>
      <a href={href} aria-label={`Baixar ${titulo}`} className="toque flex items-center justify-center rounded-xl text-primaria"><Download size={20} /></a>
      {extra}
    </li>
  );

  return (
    <Casca tipo="equipe" titulo={d ? `${d.setor.nome} · ${d.cliente.nome}` : 'Setor'} acoes={voltar}>
      {novoChamado && <NovoChamado visao="equipe" clienteInicial={ids.cliente} aoFechar={() => setNovoChamado(false)} />}
      {erro && <Painel><p className="text-center text-suave">{erro}</p></Painel>}
      {!erro && !d && <div role="status" aria-label="Carregando o setor" className="space-y-4"><Esqueleto className="h-28" /><Esqueleto className="h-12" /><EsqueletoLista /></div>}
      {d && (
        <div className="space-y-4">
          <Painel>
            <div className="flex items-center gap-4">
              <LogoEmpresa nome={d.cliente.nome} logo={d.cliente.logo} tamanho="m" />
              <div className="min-w-0 flex-1">
                <p className="text-sm font-semibold text-suave">{d.cliente.nome}</p>
                <h2 className="text-2xl font-bold text-texto">{d.setor.nome}</h2>
                <p className="text-sm text-suave">{d.setor.ativo ? 'Setor ativo' : 'Setor desativado'} · {d.pessoas.length} pessoa(s)</p>
              </div>
              <div className="flex -space-x-2">{d.pessoas.slice(0, 5).map((p) => <Avatar key={p.id} nome={p.nome} foto={p.foto} tamanho={36} />)}</div>
            </div>
          </Painel>
          <AbasIcones abas={abas} ativa={aba} aoTrocar={(a) => { setAba(a); history.replaceState(null, '', `#${a}`); setAviso({ t: '', e: false }); }} />
          {aviso.t && <Aviso texto={aviso.t} erro={aviso.e} />}

          {aba === 'chamados' && (
            <div className="space-y-3">
              <div className="flex justify-end"><BotaoNovo rotulo="Chamado neste cliente" aoTocar={() => setNovoChamado(true)} /></div>
              {!chamados ? <EsqueletoLista /> : <ListaChamados itens={chamados.itens} visao="equipe" />}
            </div>
          )}

          {aba === 'pessoas' && (d.pessoas.length ? (
            <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
              {d.pessoas.map((p) => (
                <li key={p.id} className="flex flex-col gap-3 rounded-3xl border border-borda bg-superficie p-4 shadow-sm">
                  <div className="flex items-center gap-3">
                    <Avatar nome={p.nome} foto={p.foto} tamanho={48} />
                    <div className="min-w-0 flex-1"><p className="truncate font-semibold text-texto">{p.nome}</p><p className="truncate text-sm text-suave">{p.email ?? 'sem e-mail'}</p></div>
                    <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${p.portalAtivo ? 'bg-sucesso/15 text-sucesso' : 'bg-fundo text-suave'}`}>{p.portalAtivo ? 'Acesso ativo' : 'Sem acesso'}</span>
                  </div>
                  <div className="grid grid-cols-2 gap-2">
                    <BotaoSecundario disabled={ocupado || !p.email} onClick={() => void agir({ acao: 'enviar_convite_setor', responsavel_id: String(p.id) })}><Mail size={16} aria-hidden="true" /> Convite</BotaoSecundario>
                    {p.portalAtivo && <BotaoSecundario disabled={ocupado} className="text-perigo" onClick={() => { if (confirm(`Tirar o acesso de ${p.nome}?`)) void agir({ acao: 'desativar_portal_setor', responsavel_id: String(p.id) }); }}>Tirar acesso</BotaoSecundario>}
                  </div>
                </li>
              ))}
            </ul>
          ) : <Vazio>Ninguém neste setor ainda. Cadastre pessoas na aba Usuários da ficha do cliente.</Vazio>)}

          {aba === 'documentos' && (
            <div className="space-y-3">
              <div className="flex justify-end"><BotaoNovo rotulo="Enviar documento" aoTocar={() => setForm({ tipo: 'documento', titulo: '', descricao: '', categoria: 'Geral', arquivo: null })} /></div>
              {d.documentos.length ? <ul className="grid gap-2 md:grid-cols-2">{d.documentos.map((x) => linhaArquivo(x.id, x.titulo, [x.categoria, x.arquivo, tamanhoArquivo(x.tamanho), dataBR(x.criadoEm)].filter(Boolean).join(' · '), `/download.php?id=${x.id}`, '#f59e0b', FileText))}</ul> : <Vazio>Nenhum documento neste setor.</Vazio>}
            </div>
          )}

          {aba === 'treinamentos' && (
            <div className="space-y-3">
              <div className="flex justify-end"><BotaoNovo rotulo="Agendar" aoTocar={() => setForm({ tipo: 'treinamento', treinamento_id: '', titulo: '', tipoT: 'treinamento', status: 'planejado', data_inicio: new Date().toISOString().slice(0, 10), hora_inicio: '09:00', data_fim: '', hora_fim: '', link_reuniao: '', descricao: '', responsavel_id: '' })} /></div>
              <CalendarioMes eventos={d.treinamentos.filter((t) => t.inicio).map((t) => ({
                id: String(t.id), data: (t.inicio ?? '').slice(0, 10), hora: (t.inicio ?? '').slice(11, 16), titulo: t.titulo, cor: COR_TIPO[t.tipo ?? ''] ?? '#10b981',
                aoClicar: () => setForm({ tipo: 'treinamento', treinamento_id: String(t.id), titulo: t.titulo, tipoT: t.tipo ?? 'treinamento', status: t.status, data_inicio: (t.inicio ?? '').slice(0, 10), hora_inicio: (t.inicio ?? '').slice(11, 16), data_fim: (t.fim ?? '').slice(0, 10), hora_fim: (t.fim ?? '').slice(11, 16), link_reuniao: t.link ?? '', descricao: t.descricao ?? '', responsavel_id: t.responsavelId ? String(t.responsavelId) : '' }),
              }))} />
            </div>
          )}

          {aba === 'videos' && (
            <div className="space-y-3">
              <div className="flex justify-end"><BotaoNovo rotulo="Enviar vídeo aula" aoTocar={() => setForm({ tipo: 'video', nome_rotina: '', arquivo: null })} /></div>
              {d.videoaulas.length ? (
                <ul className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                  {d.videoaulas.map((v) => (
                    <li key={v.id} className="overflow-hidden rounded-3xl border border-borda bg-superficie shadow-sm">
                      <video controls preload="metadata" playsInline src={`/video_aula.php?id=${v.id}`} className="aspect-video w-full bg-black" />
                      <div className="flex items-center gap-2 p-3">
                        <p className="min-w-0 flex-1 truncate font-semibold text-texto">{v.titulo}</p>
                        <button type="button" aria-label={`Excluir ${v.titulo}`} onClick={() => { if (confirm(`Excluir a vídeo aula "${v.titulo}"?`)) void agir({ acao: 'excluir_video_aula', documento_id: String(v.id) }); }} className="toque flex items-center justify-center rounded-xl text-suave hover:text-perigo"><Trash2 size={18} /></button>
                      </div>
                    </li>
                  ))}
                </ul>
              ) : <Vazio>Nenhuma vídeo aula neste setor.</Vazio>}
            </div>
          )}

          {aba === 'fontes' && (
            <div className="space-y-3">
              <div className="flex justify-end"><BotaoNovo rotulo="Enviar fonte" aoTocar={() => setForm({ tipo: 'fonte', fonte_id: '', titulo: '', ponto_entrada: '', descricao: '', arquivo: null })} /></div>
              {d.fontes.length ? <ul className="grid gap-2 md:grid-cols-2">{d.fontes.map((f) => linhaArquivo(f.id, f.titulo, [f.pontoEntrada, f.arquivo, tamanhoArquivo(f.tamanho), dataBR(f.criadoEm)].filter(Boolean).join(' · '), `/download_fonte_advpl.php?id=${f.id}`, '#06b6d4', Code2, (
                <>
                  <button type="button" aria-label={`Editar ${f.titulo}`} onClick={() => setForm({ tipo: 'fonte', fonte_id: String(f.id), titulo: f.titulo, ponto_entrada: f.pontoEntrada ?? '', descricao: f.descricao ?? '', arquivo: null })} className="toque flex items-center justify-center rounded-xl text-suave"><Pencil size={18} /></button>
                  <button type="button" aria-label={`Excluir ${f.titulo}`} onClick={() => { if (confirm(`Excluir a fonte "${f.titulo}"?`)) void agir({ acao: 'excluir_fonte_advpl', fonte_id: String(f.id) }); }} className="toque flex items-center justify-center rounded-xl text-suave hover:text-perigo"><Trash2 size={18} /></button>
                </>
              )))}</ul> : <Vazio>Nenhuma fonte ADVPL neste setor.</Vazio>}
            </div>
          )}

          {aba === 'propostas' && (
            <div className="space-y-3">
              <div className="flex justify-end"><BotaoNovo rotulo="Nova proposta" aoTocar={() => setForm({ tipo: 'proposta', titulo: '', descricao: '', arquivo: null })} /></div>
              {d.propostas.length ? (
                <ul className="grid gap-3 md:grid-cols-2">
                  {d.propostas.map((p) => (
                    <li key={p.id} className="space-y-2 rounded-3xl border border-borda bg-superficie p-4 shadow-sm">
                      <div className="flex items-start gap-2">
                        <Lightbulb size={20} className="mt-0.5 shrink-0 text-orange-500" aria-hidden="true" />
                        <p className="min-w-0 flex-1 font-semibold text-texto">{p.titulo}</p>
                        <button type="button" aria-label="Excluir proposta" onClick={() => { if (confirm('Excluir esta proposta?')) void agir({ acao: 'excluir_proposta', proposta_id: String(p.id) }); }} className="text-suave hover:text-perigo"><Trash2 size={16} /></button>
                      </div>
                      {p.descricao && <p className="whitespace-pre-wrap text-sm text-suave">{p.descricao}</p>}
                      <div className="flex flex-wrap items-center gap-2">
                        <select value={p.status} onChange={(e) => void agir({ acao: 'atualizar_status_proposta', proposta_id: String(p.id), status: e.target.value })} className={`${CLASSE_ENTRADA} w-auto`} aria-label="Situação da proposta">
                          {Object.entries(STATUS_P).map(([k, r]) => <option key={k} value={k}>{r}</option>)}
                        </select>
                        {p.documentoId && <a href={`/download.php?id=${p.documentoId}`} className="toque inline-flex items-center gap-1 rounded-xl border border-borda px-3 text-sm text-primaria"><Download size={16} aria-hidden="true" />Arquivo</a>}
                        <span className="text-xs text-suave">{dataBR(p.criadoEm)}</span>
                      </div>
                    </li>
                  ))}
                </ul>
              ) : <Vazio>Nenhuma proposta de desenvolvimento neste setor.</Vazio>}
            </div>
          )}

          {aba === 'metricas' && (
            <div className="space-y-3">
              <div className="flex justify-end"><BotaoNovo rotulo="Novo indicador" aoTocar={() => setForm({ tipo: 'kpi', kpi_id: '', nome_kpi: '', valor: '', meta: '', unidade: '', periodo: new Date().toISOString().slice(0, 7), direcao_melhor: 'maior', observacoes: '' })} /></div>
              {d.kpis.length ? (
                <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                  {d.kpis.map((k) => {
                    const pct = k.meta ? Math.min(150, Math.round((k.valor / k.meta) * 100)) : null;
                    const bom = pct === null ? null : k.direcao === 'menor' ? k.valor <= (k.meta ?? 0) : k.valor >= (k.meta ?? 0);
                    return (
                      <li key={k.id} className="space-y-2 rounded-3xl border border-borda bg-superficie p-4 shadow-sm">
                        <div className="flex items-start gap-2">
                          <p className="min-w-0 flex-1 font-semibold text-texto">{k.nome}</p>
                          <button type="button" aria-label="Editar" onClick={() => setForm({ tipo: 'kpi', kpi_id: String(k.id), nome_kpi: k.nome, valor: String(k.valor), meta: k.meta === null ? '' : String(k.meta), unidade: k.unidade ?? '', periodo: String(k.periodo ?? '').slice(0, 7), direcao_melhor: k.direcao ?? 'maior', observacoes: k.observacoes ?? '' })} className="text-suave"><Pencil size={16} /></button>
                          <button type="button" aria-label="Excluir" onClick={() => { if (confirm('Excluir este indicador?')) void agir({ acao: 'excluir_kpi', kpi_id: String(k.id) }); }} className="text-suave hover:text-perigo"><Trash2 size={16} /></button>
                        </div>
                        <p className="text-3xl font-bold tabular-nums text-texto">{k.valor.toLocaleString('pt-BR')}<span className="ml-1 text-base text-suave">{k.unidade}</span></p>
                        {pct !== null && (
                          <div>
                            <div className="h-2 overflow-hidden rounded-full bg-superficie-alta"><div className={`h-full rounded-full ${bom ? 'bg-sucesso' : 'bg-alerta'}`} style={{ width: `${Math.min(100, pct)}%` }} /></div>
                            <p className="mt-1 text-xs text-suave">Meta {k.meta?.toLocaleString('pt-BR')} {k.unidade} · {k.periodo ? new Date(`${String(k.periodo).slice(0, 10)}T12:00`).toLocaleDateString('pt-BR', { month: 'short', year: 'numeric' }) : ''}</p>
                          </div>
                        )}
                      </li>
                    );
                  })}
                </ul>
              ) : <Vazio>Nenhum indicador registrado.</Vazio>}
            </div>
          )}

          {aba === 'ia' && (
            <div className="space-y-3">
              <Painel>
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                  <p className="flex-1 text-sm text-suave">A IA analisa os últimos chamados e indicadores deste setor e sugere melhorias de processo.</p>
                  <BotaoPrimario onClick={() => void gerarIa()} disabled={ocupado}><Sparkles size={18} aria-hidden="true" /> {ocupado ? 'Analisando…' : 'Gerar análise'}</BotaoPrimario>
                </div>
                {ia && <p className="mt-3 text-sm text-suave">{ia}</p>}
              </Painel>
              {d.sugestoesIa.map((s) => (
                <Painel key={s.id} titulo={`Análise de ${dataBR(s.criadoEm, true)}`}><p className="whitespace-pre-wrap text-sm leading-relaxed text-texto">{s.texto.replace(/\*\*/g, '')}</p></Painel>
              ))}
            </div>
          )}
        </div>
      )}

      {form && d && (
        <Modal titulo={{ documento: 'Enviar documento', video: 'Enviar vídeo aula', fonte: form.tipo === 'fonte' && form.fonte_id ? 'Editar fonte' : 'Enviar fonte ADVPL', proposta: 'Nova proposta', kpi: 'Indicador do setor', treinamento: form.tipo === 'treinamento' && form.treinamento_id ? 'Editar agendamento' : 'Agendar' }[form.tipo]} aoFechar={() => setForm(null)}>
          <form className="space-y-4" onSubmit={(e) => {
            e.preventDefault();
            if (form.tipo === 'documento') void agir({ acao: 'upload_documento', titulo: form.titulo, descricao: form.descricao, categoria: form.categoria }, { arquivo: form.arquivo });
            if (form.tipo === 'video') void agir({ acao: 'upload_video_aula', nome_rotina: form.nome_rotina }, { arquivo: form.arquivo });
            if (form.tipo === 'fonte') void agir({ acao: form.fonte_id ? 'atualizar_fonte_advpl' : 'upload_fonte_advpl', fonte_id: form.fonte_id, titulo: form.titulo, ponto_entrada: form.ponto_entrada, descricao: form.descricao }, { arquivo: form.arquivo });
            if (form.tipo === 'proposta') void agir({ acao: 'criar_proposta', titulo: form.titulo, descricao: form.descricao }, { arquivo: form.arquivo });
            if (form.tipo === 'kpi') { const { tipo: _t, kpi_id, ...k } = form; void _t; void agir({ acao: kpi_id ? 'editar_kpi' : 'criar_kpi', kpi_id, ...k }); }
            if (form.tipo === 'treinamento') { const { tipo: _t, tipoT, treinamento_id, ...t } = form; void _t; void agir({ acao: treinamento_id ? 'editar_treinamento' : 'criar_treinamento', treinamento_id, tipo: tipoT, chamado_id: '', ...t }); }
          }}>
            {form.tipo === 'documento' && (
              <>
                <Campo rotulo="Título *"><input required value={form.titulo} onChange={(e) => setForm({ ...form, titulo: e.target.value })} className={CLASSE_ENTRADA} /></Campo>
                <Campo rotulo="Categoria"><input value={form.categoria} onChange={(e) => setForm({ ...form, categoria: e.target.value })} className={CLASSE_ENTRADA} /></Campo>
                <Campo rotulo="Descrição"><textarea rows={3} value={form.descricao} onChange={(e) => setForm({ ...form, descricao: e.target.value })} className={CLASSE_AREA} /></Campo>
                <EscolherArquivo rotulo="Escolher arquivo *" aoEscolher={(f) => setForm({ ...form, arquivo: f[0] ?? null })} nomes={form.arquivo ? [form.arquivo.name] : []} />
              </>
            )}
            {form.tipo === 'video' && (
              <>
                <Campo rotulo="Nome da rotina / assunto *"><input required value={form.nome_rotina} onChange={(e) => setForm({ ...form, nome_rotina: e.target.value })} className={CLASSE_ENTRADA} placeholder="Ex.: MATA410 - Pedido de venda" /></Campo>
                <EscolherArquivo rotulo="Escolher vídeo *" aceita="video/*" aoEscolher={(f) => setForm({ ...form, arquivo: f[0] ?? null })} nomes={form.arquivo ? [form.arquivo.name] : []} />
              </>
            )}
            {form.tipo === 'fonte' && (
              <>
                <Campo rotulo="Título *"><input required value={form.titulo} onChange={(e) => setForm({ ...form, titulo: e.target.value })} className={CLASSE_ENTRADA} /></Campo>
                <Campo rotulo="Ponto de entrada"><input value={form.ponto_entrada} onChange={(e) => setForm({ ...form, ponto_entrada: e.target.value })} className={CLASSE_ENTRADA} /></Campo>
                <Campo rotulo="Descrição"><textarea rows={3} value={form.descricao} onChange={(e) => setForm({ ...form, descricao: e.target.value })} className={CLASSE_AREA} /></Campo>
                <EscolherArquivo rotulo={form.fonte_id ? 'Trocar arquivo (opcional)' : 'Escolher arquivo *'} aceita=".prw,.tlpp,.prx,.ptm" aoEscolher={(f) => setForm({ ...form, arquivo: f[0] ?? null })} nomes={form.arquivo ? [form.arquivo.name] : []} />
              </>
            )}
            {form.tipo === 'proposta' && (
              <>
                <Campo rotulo="Título *"><input required value={form.titulo} onChange={(e) => setForm({ ...form, titulo: e.target.value })} className={CLASSE_ENTRADA} /></Campo>
                <Campo rotulo="Descrição"><textarea rows={4} value={form.descricao} onChange={(e) => setForm({ ...form, descricao: e.target.value })} className={CLASSE_AREA} /></Campo>
                <EscolherArquivo rotulo="Anexar documento (opcional)" aoEscolher={(f) => setForm({ ...form, arquivo: f[0] ?? null })} nomes={form.arquivo ? [form.arquivo.name] : []} />
              </>
            )}
            {form.tipo === 'kpi' && (
              <div className="grid gap-4 sm:grid-cols-2">
                <div className="sm:col-span-2"><Campo rotulo="Nome do indicador *"><input required value={form.nome_kpi} onChange={(e) => setForm({ ...form, nome_kpi: e.target.value })} className={CLASSE_ENTRADA} /></Campo></div>
                <Campo rotulo="Valor *"><input required inputMode="decimal" value={form.valor} onChange={(e) => setForm({ ...form, valor: e.target.value })} className={CLASSE_ENTRADA} /></Campo>
                <Campo rotulo="Meta"><input inputMode="decimal" value={form.meta} onChange={(e) => setForm({ ...form, meta: e.target.value })} className={CLASSE_ENTRADA} /></Campo>
                <Campo rotulo="Unidade"><input value={form.unidade} onChange={(e) => setForm({ ...form, unidade: e.target.value })} className={CLASSE_ENTRADA} placeholder="%, R$, dias…" /></Campo>
                <Campo rotulo="Mês de referência"><input type="month" required value={form.periodo} onChange={(e) => setForm({ ...form, periodo: e.target.value })} className={CLASSE_ENTRADA} /></Campo>
                <Campo rotulo="Melhor quando"><select value={form.direcao_melhor} onChange={(e) => setForm({ ...form, direcao_melhor: e.target.value })} className={CLASSE_ENTRADA}><option value="maior">Maior</option><option value="menor">Menor</option></select></Campo>
                <div className="sm:col-span-2"><Campo rotulo="Observações"><textarea rows={2} value={form.observacoes} onChange={(e) => setForm({ ...form, observacoes: e.target.value })} className={CLASSE_AREA} /></Campo></div>
              </div>
            )}
            {form.tipo === 'treinamento' && (
              <div className="grid gap-4 sm:grid-cols-2">
                <div className="sm:col-span-2"><Campo rotulo="Título *"><input required value={form.titulo} onChange={(e) => setForm({ ...form, titulo: e.target.value })} className={CLASSE_ENTRADA} /></Campo></div>
                <Campo rotulo="Tipo"><select value={form.tipoT} onChange={(e) => setForm({ ...form, tipoT: e.target.value })} className={CLASSE_ENTRADA}>{Object.entries(TIPOS).map(([k, r]) => <option key={k} value={k}>{r}</option>)}</select></Campo>
                <Campo rotulo="Situação"><select value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })} className={CLASSE_ENTRADA}>{Object.entries(STATUS_T).map(([k, r]) => <option key={k} value={k}>{r}</option>)}</select></Campo>
                <Campo rotulo="Início *"><div className="flex gap-2"><input type="date" required value={form.data_inicio} onChange={(e) => setForm({ ...form, data_inicio: e.target.value })} className={CLASSE_ENTRADA} /><input type="time" value={form.hora_inicio} onChange={(e) => setForm({ ...form, hora_inicio: e.target.value })} className={`${CLASSE_ENTRADA} w-32`} /></div></Campo>
                <Campo rotulo="Fim"><div className="flex gap-2"><input type="date" value={form.data_fim} onChange={(e) => setForm({ ...form, data_fim: e.target.value })} className={CLASSE_ENTRADA} /><input type="time" value={form.hora_fim} onChange={(e) => setForm({ ...form, hora_fim: e.target.value })} className={`${CLASSE_ENTRADA} w-32`} /></div></Campo>
                <Campo rotulo="Link da reunião"><input type="url" value={form.link_reuniao} onChange={(e) => setForm({ ...form, link_reuniao: e.target.value })} className={CLASSE_ENTRADA} placeholder="https://meet.google.com/…" /></Campo>
                <Campo rotulo="Responsável na Prime"><select value={form.responsavel_id} onChange={(e) => setForm({ ...form, responsavel_id: e.target.value })} className={CLASSE_ENTRADA}><option value="">Ninguém</option>{d.equipe.map((u) => <option key={u.id} value={u.id}>{u.nome}</option>)}</select></Campo>
                <div className="sm:col-span-2"><Campo rotulo="Descrição"><textarea rows={3} value={form.descricao} onChange={(e) => setForm({ ...form, descricao: e.target.value })} className={CLASSE_AREA} /></Campo></div>
                {form.link_reuniao && <a href={form.link_reuniao} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 text-sm font-semibold text-primaria"><ExternalLink size={16} aria-hidden="true" />Abrir a reunião</a>}
              </div>
            )}
            {aviso.e && <Aviso texto={aviso.t} erro />}
            <div className="flex flex-col-reverse gap-3 sm:flex-row">
              {form.tipo === 'treinamento' && form.treinamento_id && <BotaoSecundario className="text-perigo" onClick={() => { if (confirm('Excluir este agendamento?')) void agir({ acao: 'excluir_treinamento', treinamento_id: form.treinamento_id }); }}><Trash2 size={16} aria-hidden="true" /> Excluir</BotaoSecundario>}
              <BotaoSecundario onClick={() => setForm(null)} className="sm:ml-auto">Cancelar</BotaoSecundario>
              <BotaoPrimario type="submit" disabled={ocupado}>{ocupado ? 'Salvando…' : 'Salvar'}</BotaoPrimario>
            </div>
          </form>
        </Modal>
      )}
    </Casca>
  );
}
