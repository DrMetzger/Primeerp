'use client';

import type { FonteArquivo } from '@primehub/shared';
import { Code2, Download, Plus } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Casca } from '@/components/casca';
import { EsqueletoLista } from '@/components/esqueleto';
import { Aviso, BotaoPrimario, BotaoSecundario, CLASSE_AREA, CLASSE_ENTRADA, Campo, EscolherArquivo, Modal, Vazio, dataBR, tamanhoArquivo } from '@/components/ui/basico';
import { api, enviarAcao } from '@/lib/api';

interface Dados { fontes: FonteArquivo[]; setores: { id: number; nome: string }[] }

export default function PaginaFontesCliente() {
  const [dados, setDados] = useState<Dados | null>(null);
  const [form, setForm] = useState<{ setor_id: string; titulo: string; ponto_entrada: string; descricao: string; arquivo: File | null } | null>(null);
  const [salvando, setSalvando] = useState(false);
  const [aviso, setAviso] = useState('');
  const carregar = () => api<Dados>('/api/portal/fontes').then(setDados).catch(() => setDados({ fontes: [], setores: [] }));
  useEffect(() => { void carregar(); }, []);

  async function enviar(e: React.FormEvent) {
    e.preventDefault();
    if (!form) return;
    setSalvando(true); setAviso('');
    try {
      const { arquivo, ...campos } = form;
      await enviarAcao('portal/fontes_advpl.php', { acao: 'upload_fonte_advpl', ...campos }, { arquivo });
      setForm(null); void carregar();
    } catch (ex) { setAviso(ex instanceof Error ? ex.message : 'Não deu pra enviar.'); }
    setSalvando(false);
  }

  const acoes = dados?.setores.length ? (
    <button type="button" onClick={() => setForm({ setor_id: String(dados.setores[0]?.id ?? ''), titulo: '', ponto_entrada: '', descricao: '', arquivo: null })} className="gradiente-marca toque inline-flex items-center gap-2 rounded-xl px-4 font-semibold text-white shadow">
      <Plus size={20} aria-hidden="true" /> <span className="hidden sm:inline">Enviar fonte</span>
    </button>
  ) : undefined;

  return (
    <Casca tipo="cliente" titulo="Fontes ADVPL" acoes={acoes}>
      {!dados ? <EsqueletoLista /> : !dados.fontes.length ? <Vazio>Nenhuma fonte cadastrada ainda.</Vazio> : (
        <div className="space-y-5">
          {dados.setores.map((s) => {
            const l = dados.fontes.filter((f) => f.setorId === s.id);
            if (!l.length) return null;
            return (
              <section key={s.id}>
                <h2 className="mb-2 text-sm font-bold uppercase tracking-wide text-suave">{s.nome}</h2>
                <ul className="grid gap-2 md:grid-cols-2">
                  {l.map((f) => (
                    <li key={f.id} className="flex items-center gap-3 rounded-2xl border border-borda bg-superficie p-3 shadow-sm">
                      <span className="gradiente-marca flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-white"><Code2 size={18} aria-hidden="true" /></span>
                      <div className="min-w-0 flex-1">
                        <p className="truncate font-semibold text-texto">{f.titulo}</p>
                        <p className="truncate text-xs text-suave">{[f.categoria, f.arquivo, tamanhoArquivo(f.tamanho), dataBR(f.criadoEm)].filter(Boolean).join(' · ')}</p>
                        {f.descricao && <p className="line-clamp-2 text-sm text-suave">{f.descricao}</p>}
                      </div>
                      <a href={`/download_fonte_advpl.php?id=${f.id}`} aria-label={`Baixar ${f.titulo}`} className="toque flex items-center justify-center rounded-xl text-primaria"><Download size={20} /></a>
                    </li>
                  ))}
                </ul>
              </section>
            );
          })}
        </div>
      )}
      {form && dados && (
        <Modal titulo="Enviar fonte ADVPL" aoFechar={() => setForm(null)}>
          <form onSubmit={(e) => void enviar(e)} className="space-y-4">
            <Campo rotulo="Setor *">
              <select value={form.setor_id} onChange={(e) => setForm({ ...form, setor_id: e.target.value })} className={CLASSE_ENTRADA}>{dados.setores.map((s) => <option key={s.id} value={s.id}>{s.nome}</option>)}</select>
            </Campo>
            <Campo rotulo="Título *"><input required value={form.titulo} onChange={(e) => setForm({ ...form, titulo: e.target.value })} className={CLASSE_ENTRADA} /></Campo>
            <Campo rotulo="Ponto de entrada"><input value={form.ponto_entrada} onChange={(e) => setForm({ ...form, ponto_entrada: e.target.value })} className={CLASSE_ENTRADA} placeholder="Ex.: MT100TOK" /></Campo>
            <Campo rotulo="Descrição"><textarea rows={3} value={form.descricao} onChange={(e) => setForm({ ...form, descricao: e.target.value })} className={CLASSE_AREA} /></Campo>
            <EscolherArquivo rotulo="Escolher arquivo *" aceita=".prw,.tlpp,.prx,.ptm" aoEscolher={(f) => setForm({ ...form, arquivo: f[0] ?? null })} nomes={form.arquivo ? [form.arquivo.name] : []} />
            <Aviso texto={aviso} erro />
            <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
              <BotaoSecundario onClick={() => setForm(null)}>Cancelar</BotaoSecundario>
              <BotaoPrimario type="submit" disabled={salvando || !form.arquivo}>{salvando ? 'Enviando…' : 'Enviar'}</BotaoPrimario>
            </div>
          </form>
        </Modal>
      )}
    </Casca>
  );
}
