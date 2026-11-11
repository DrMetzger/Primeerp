'use client';

import type { FonteArquivo } from '@primehub/shared';
import { Code2, Download, Pencil, Plus, Search, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Casca } from '@/components/casca';
import { EsqueletoLista } from '@/components/esqueleto';
import { Aviso, BotaoPrimario, BotaoSecundario, CLASSE_AREA, CLASSE_ENTRADA, Campo, EscolherArquivo, Modal, Vazio, dataBR, tamanhoArquivo } from '@/components/ui/basico';
import { api, enviarAcao } from '@/lib/api';

const CATEGORIAS_FONTES: Record<string, string> = {
  integracoes: 'Integrações', compras: 'Compras', estoque: 'Estoque', faturamento: 'Faturamento', pcp: 'PCP', qualidade: 'Qualidade',
  financeiro: 'Financeiro', fiscal: 'Fiscal', contabilidade: 'Contabilidade', importacoes: 'Importações', exportacoes: 'Exportações', miscelanea: 'Miscelânea',
};

interface Form { fonte_id: string; nome: string; categoria: string; descricao: string; arquivos: File[] }

export default function PaginaFontes() {
  const [itens, setItens] = useState<FonteArquivo[] | null>(null);
  const [erro, setErro] = useState('');
  const [busca, setBusca] = useState('');
  const [form, setForm] = useState<Form | null>(null);
  const [salvando, setSalvando] = useState(false);
  const [aviso, setAviso] = useState('');

  const carregar = () => api<FonteArquivo[]>('/api/fontes').then(setItens).catch((e: unknown) => setErro(e instanceof Error ? e.message : 'Não consegui abrir as fontes.'));
  useEffect(() => { void carregar(); }, []);

  async function salvar(e: React.FormEvent) {
    e.preventDefault();
    if (!form) return;
    setSalvando(true); setAviso('');
    try {
      await enviarAcao('fontes.php', { acao: form.fonte_id ? 'atualizar_fonte_global' : 'criar_fonte_global', fonte_id: form.fonte_id, nome: form.nome, categoria: form.categoria, descricao: form.descricao },
        { [form.fonte_id ? 'arquivo' : 'arquivo[]']: form.fonte_id ? (form.arquivos[0] ?? null) : form.arquivos });
      setForm(null); void carregar();
    } catch (ex) { setAviso(ex instanceof Error ? ex.message : 'Não deu pra salvar.'); }
    setSalvando(false);
  }

  async function excluir(f: FonteArquivo) {
    if (!confirm(`Excluir "${f.titulo}"?`)) return;
    try { await enviarAcao('fontes.php', { acao: 'excluir_fonte_global', fonte_id: String(f.id) }); void carregar(); } catch (ex) { alert(ex instanceof Error ? ex.message : 'Não deu pra excluir.'); }
  }

  const filtrados = (itens ?? []).filter((f) => !busca || `${f.titulo} ${f.descricao ?? ''} ${f.arquivo}`.toLowerCase().includes(busca.toLowerCase()));
  const grupos = Object.entries(CATEGORIAS_FONTES).map(([k, r]) => [r, filtrados.filter((f) => f.categoria === k)] as const).filter(([, l]) => l.length);

  const acoes = (
    <button type="button" onClick={() => setForm({ fonte_id: '', nome: '', categoria: 'miscelanea', descricao: '', arquivos: [] })} className="gradiente-marca toque inline-flex items-center gap-2 rounded-xl px-4 font-semibold text-white shadow">
      <Plus size={20} aria-hidden="true" /> <span className="hidden sm:inline">Enviar fonte</span>
    </button>
  );

  return (
    <Casca tipo="equipe" titulo="Fontes ADVPL" acoes={acoes}>
      <p className="mb-3 text-suave">Biblioteca geral de fontes e patches da Prime (.prw, .tlpp, .prx, .ptm). As fontes de cada cliente ficam na ficha do cliente.</p>
      <label className="relative mb-4 block">
        <span className="sr-only">Buscar fonte</span>
        <Search size={20} className="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-suave" aria-hidden="true" />
        <input type="search" value={busca} onChange={(e) => setBusca(e.target.value)} placeholder="Buscar por nome, arquivo ou descrição" className="toque w-full rounded-2xl border border-borda bg-superficie pl-12 pr-4 text-base text-texto outline-none focus:border-primaria" />
      </label>
      {erro && <Aviso texto={erro} erro />}
      {!itens && !erro ? <EsqueletoLista /> : !grupos.length ? <Vazio>Nenhuma fonte por aqui ainda.</Vazio> : (
        <div className="space-y-5">
          {grupos.map(([rotulo, lista]) => (
            <section key={rotulo}>
              <h2 className="mb-2 text-sm font-bold uppercase tracking-wide text-suave">{rotulo}</h2>
              <ul className="grid gap-2 md:grid-cols-2">
                {lista.map((f) => (
                  <li key={f.id} className="flex items-center gap-3 rounded-2xl border border-borda bg-superficie p-3 shadow-sm">
                    <span className="gradiente-marca flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-white"><Code2 size={18} aria-hidden="true" /></span>
                    <div className="min-w-0 flex-1">
                      <p className="truncate font-semibold text-texto">{f.titulo}</p>
                      <p className="truncate text-xs text-suave">{f.arquivo} · {tamanhoArquivo(f.tamanho)} · {dataBR(f.criadoEm)}{f.autor ? ` · ${f.autor}` : ''}</p>
                      {f.descricao && <p className="line-clamp-2 text-sm text-suave">{f.descricao}</p>}
                    </div>
                    <a href={`/download_fonte_global.php?id=${f.id}`} aria-label={`Baixar ${f.titulo}`} className="toque flex items-center justify-center rounded-xl text-primaria"><Download size={20} /></a>
                    <button type="button" aria-label={`Editar ${f.titulo}`} onClick={() => setForm({ fonte_id: String(f.id), nome: f.titulo, categoria: f.categoria ?? 'miscelanea', descricao: f.descricao ?? '', arquivos: [] })} className="toque flex items-center justify-center rounded-xl text-suave"><Pencil size={18} /></button>
                    <button type="button" aria-label={`Excluir ${f.titulo}`} onClick={() => void excluir(f)} className="toque flex items-center justify-center rounded-xl text-suave hover:text-perigo"><Trash2 size={18} /></button>
                  </li>
                ))}
              </ul>
            </section>
          ))}
        </div>
      )}
      {form && (
        <Modal titulo={form.fonte_id ? 'Editar fonte' : 'Enviar fonte'} aoFechar={() => setForm(null)}>
          <form onSubmit={(e) => void salvar(e)} className="space-y-4">
            <Campo rotulo={form.fonte_id ? 'Nome *' : 'Nome (opcional, usa o do arquivo)'}><input value={form.nome} required={!!form.fonte_id} onChange={(e) => setForm({ ...form, nome: e.target.value })} className={CLASSE_ENTRADA} /></Campo>
            <Campo rotulo="Categoria *">
              <select value={form.categoria} onChange={(e) => setForm({ ...form, categoria: e.target.value })} className={CLASSE_ENTRADA}>
                {Object.entries(CATEGORIAS_FONTES).map(([k, r]) => <option key={k} value={k}>{r}</option>)}
              </select>
            </Campo>
            <Campo rotulo="Descrição"><textarea value={form.descricao} onChange={(e) => setForm({ ...form, descricao: e.target.value })} rows={3} className={CLASSE_AREA} /></Campo>
            <EscolherArquivo rotulo={form.fonte_id ? 'Trocar arquivo (opcional)' : 'Escolher arquivo(s) *'} aceita=".prw,.tlpp,.prx,.ptm" multiplo={!form.fonte_id}
              aoEscolher={(f) => setForm({ ...form, arquivos: form.fonte_id ? f.slice(0, 1) : [...form.arquivos, ...f] })} nomes={form.arquivos.map((a) => a.name)} />
            <Aviso texto={aviso} erro />
            <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
              <BotaoSecundario onClick={() => setForm(null)}>Cancelar</BotaoSecundario>
              <BotaoPrimario type="submit" disabled={salvando || (!form.fonte_id && !form.arquivos.length)}>{salvando ? 'Enviando…' : 'Salvar'}</BotaoPrimario>
            </div>
          </form>
        </Modal>
      )}
    </Casca>
  );
}
