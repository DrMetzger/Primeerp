'use client';

import { BarChart3, FileCode2, FileSpreadsheet, FileText, LifeBuoy, ShieldAlert, TrendingUp, UserCheck, type LucideIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Casca } from '@/components/casca';
import { Esqueleto, EsqueletoLista } from '@/components/esqueleto';
import { BotaoPrimario, CLASSE_ENTRADA, Campo, Painel, Vazio } from '@/components/ui/basico';

interface ItemCatalogo { titulo: string; descricao: string; icone: string; precisaCliente: boolean; precisaUsuario?: boolean }
interface Bloco { titulo: string | null; colunas: Record<string, { align: 'L' | 'C' | 'R' }>; linhas: (string | number | null)[][] }
interface Relatorio { titulo: string; descricaoFiltros?: string; blocos?: Bloco[]; erro?: string }
interface Resposta { catalogo: Record<string, ItemCatalogo>; clientes: { id: number; nome: string }[]; usuarios: { id: number; nome: string }[]; relatorio: Relatorio | null }

const ICONES: Record<string, LucideIcon> = {
  'bi-headset': LifeBuoy, 'bi-bar-chart-line': BarChart3, 'bi-file-earmark-code': FileCode2,
  'bi-graph-up-arrow': TrendingUp, 'bi-shield-exclamation': ShieldAlert, 'bi-person-check': UserCheck,
};
const ALINHA = { L: 'text-left', C: 'text-center', R: 'text-right' } as const;

async function buscar(params: Record<string, string>): Promise<Resposta> {
  const r = await fetch(`/relatorios.php?${new URLSearchParams({ ...params, formato: 'json' })}`, { credentials: 'same-origin' });
  if (!r.ok || !(r.headers.get('content-type') ?? '').includes('json')) throw new Error('Relatórios são só para admin, diretoria e CEO.');
  return (await r.json()) as Resposta;
}

export default function PaginaRelatorios() {
  const [base, setBase] = useState<Resposta | null>(null);
  const [erro, setErro] = useState('');
  const [tipo, setTipo] = useState('');
  const [filtros, setFiltros] = useState({ cliente_id: '', usuario_id: '', data_de: '', data_ate: '' });
  const [relatorio, setRelatorio] = useState<Relatorio | null>(null);
  const [gerando, setGerando] = useState(false);

  useEffect(() => { buscar({}).then(setBase).catch((e: unknown) => setErro(e instanceof Error ? e.message : 'Não consegui abrir os relatórios.')); }, []);

  const info = tipo ? base?.catalogo[tipo] : undefined;
  const params = () => {
    const p: Record<string, string> = { relatorio: tipo };
    for (const [k, v] of Object.entries(filtros)) if (v) p[k] = v;
    return p;
  };

  async function gerar() {
    setGerando(true); setRelatorio(null);
    try { setRelatorio((await buscar(params())).relatorio); } catch (e) { setRelatorio({ titulo: '', erro: e instanceof Error ? e.message : 'Não deu pra gerar agora.' }); }
    setGerando(false);
  }

  const exportar = (formato: 'pdf' | 'xlsx') => `/relatorios.php?${new URLSearchParams({ ...params(), formato })}`;
  const faltaFiltro = (info?.precisaCliente && !filtros.cliente_id) || (info?.precisaUsuario && !filtros.usuario_id);

  return (
    <Casca tipo="equipe" titulo="Relatórios">
      {erro && <Painel><p className="text-center text-suave">{erro}</p></Painel>}
      {!erro && !base && <div role="status" aria-label="Carregando" className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">{Array.from({ length: 6 }, (_, i) => <Esqueleto key={i} className="h-36" />)}</div>}
      {base && (
        <div className="space-y-5">
          <p className="text-suave">Relatórios prontos para apresentação, com exportação em PDF (timbrado oficial) ou Excel.</p>
          <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {Object.entries(base.catalogo).map(([chave, r]) => {
              const Icone = ICONES[r.icone] ?? FileText;
              const ativo = tipo === chave;
              return (
                <li key={chave}>
                  <button type="button" aria-pressed={ativo} onClick={() => { setTipo(chave); setRelatorio(null); }}
                    className={`flex h-full w-full flex-col gap-2 rounded-3xl border p-4 text-left shadow-sm transition ${ativo ? 'border-primaria bg-primaria/10' : 'border-borda bg-superficie hover:border-primaria/50'}`}>
                    <span className="gradiente-marca flex h-11 w-11 items-center justify-center rounded-2xl text-white"><Icone size={22} aria-hidden="true" /></span>
                    <span className="font-semibold text-texto">{r.titulo}</span>
                    <span className="text-sm text-suave">{r.descricao}</span>
                  </button>
                </li>
              );
            })}
          </ul>

          {info && (
            <Painel titulo={`Filtros — ${info.titulo}`}>
              <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {info.precisaCliente && (
                  <Campo rotulo="Cliente *">
                    <select value={filtros.cliente_id} onChange={(e) => setFiltros((f) => ({ ...f, cliente_id: e.target.value }))} className={CLASSE_ENTRADA}>
                      <option value="">Escolha</option>
                      {base.clientes.map((c) => <option key={c.id} value={c.id}>{c.nome}</option>)}
                    </select>
                  </Campo>
                )}
                {info.precisaUsuario && (
                  <Campo rotulo="Colaborador *">
                    <select value={filtros.usuario_id} onChange={(e) => setFiltros((f) => ({ ...f, usuario_id: e.target.value }))} className={CLASSE_ENTRADA}>
                      <option value="">Escolha</option>
                      {base.usuarios.map((u) => <option key={u.id} value={u.id}>{u.nome}</option>)}
                    </select>
                  </Campo>
                )}
                <Campo rotulo="De"><input type="date" value={filtros.data_de} onChange={(e) => setFiltros((f) => ({ ...f, data_de: e.target.value }))} className={CLASSE_ENTRADA} /></Campo>
                <Campo rotulo="Até"><input type="date" value={filtros.data_ate} onChange={(e) => setFiltros((f) => ({ ...f, data_ate: e.target.value }))} className={CLASSE_ENTRADA} /></Campo>
              </div>
              <div className="mt-4 flex flex-col gap-3 sm:flex-row">
                <BotaoPrimario onClick={() => void gerar()} disabled={gerando || !!faltaFiltro}><BarChart3 size={18} aria-hidden="true" /> {gerando ? 'Gerando…' : 'Ver relatório'}</BotaoPrimario>
                <a href={faltaFiltro ? undefined : exportar('pdf')} aria-disabled={!!faltaFiltro} className={`toque inline-flex items-center justify-center gap-2 rounded-xl border border-borda bg-superficie px-4 font-semibold text-texto hover:bg-superficie-alta ${faltaFiltro ? 'pointer-events-none opacity-50' : ''}`}><FileText size={18} aria-hidden="true" /> Baixar PDF</a>
                <a href={faltaFiltro ? undefined : exportar('xlsx')} aria-disabled={!!faltaFiltro} className={`toque inline-flex items-center justify-center gap-2 rounded-xl border border-borda bg-superficie px-4 font-semibold text-texto hover:bg-superficie-alta ${faltaFiltro ? 'pointer-events-none opacity-50' : ''}`}><FileSpreadsheet size={18} aria-hidden="true" /> Baixar Excel</a>
              </div>
            </Painel>
          )}

          {gerando && <EsqueletoLista linhas={4} />}
          {relatorio && (relatorio.erro ? <Vazio>{relatorio.erro}</Vazio> : (
            <div className="space-y-4">
              <div>
                <h2 className="text-lg font-bold text-texto">{relatorio.titulo}</h2>
                {relatorio.descricaoFiltros && <p className="text-sm text-suave">{relatorio.descricaoFiltros}</p>}
              </div>
              {(relatorio.blocos ?? []).filter((b) => b.linhas.length).map((b, i) => {
                const colunas = Object.entries(b.colunas);
                return (
                  <Painel key={i} titulo={b.titulo ?? undefined}>
                    {/* Celular: cada linha vira um cartão com rótulo; computador: tabela. */}
                    <ul className="space-y-2 md:hidden">
                      {b.linhas.map((l, j) => (
                        <li key={j} className="rounded-2xl border border-borda bg-fundo p-3">
                          <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
                            {colunas.map(([rot], k) => <div key={rot} className="contents"><dt className="text-suave">{rot}</dt><dd className="text-right font-medium text-texto">{l[k] ?? '—'}</dd></div>)}
                          </dl>
                        </li>
                      ))}
                    </ul>
                    <div className="hidden overflow-x-auto md:block">
                      <table className="w-full text-sm">
                        <thead>
                          <tr className="border-b border-borda">{colunas.map(([rot, d]) => <th key={rot} scope="col" className={`px-3 py-2 font-semibold text-suave ${ALINHA[d.align] ?? 'text-left'}`}>{rot}</th>)}</tr>
                        </thead>
                        <tbody>
                          {b.linhas.map((l, j) => (
                            <tr key={j} className="border-b border-borda/60 last:border-0">
                              {colunas.map(([rot, d], k) => <td key={rot} className={`px-3 py-2 text-texto ${ALINHA[d.align] ?? 'text-left'}`}>{l[k] ?? '—'}</td>)}
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  </Painel>
                );
              })}
              {!(relatorio.blocos ?? []).some((b) => b.linhas.length) && <Vazio>Nada encontrado com esses filtros.</Vazio>}
            </div>
          ))}
        </div>
      )}
    </Casca>
  );
}
