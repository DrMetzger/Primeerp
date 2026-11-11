'use client';

import { Paperclip, Send, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { acaoPhp } from '@/lib/api';
import { BotaoPrimario, BotaoSecundario, CLASSE_ENTRADA, Campo } from './ui/basico';

interface Opcoes { categorias: string[]; setores: { id: number; nome: string }[]; clientes: { id: number; nome: string }[]; responsaveis: { id: number; nome: string }[] }

/** Abrir chamado (cliente pelo portal ou equipe em nome do cliente). */
export function NovoChamado({ visao, clienteInicial, aoFechar }: { visao: 'equipe' | 'cliente'; clienteInicial?: number; aoFechar: () => void }) {
  const [opcoes, setOpcoes] = useState<Opcoes | null>(null);
  const [clienteId, setClienteId] = useState(clienteInicial ? String(clienteInicial) : '');
  const [form, setForm] = useState({ titulo: '', categoria: '', prioridade: 'media', setor_id: '', responsavel_id: '', descricao: '' });
  const [anexos, setAnexos] = useState<File[]>([]);
  const [enviando, setEnviando] = useState(false);
  const [erro, setErro] = useState('');

  useEffect(() => {
    acaoPhp<Opcoes>('api_acoes.php', { acao: 'opcoes_chamado', cliente_id: clienteId }).then(setOpcoes).catch(() => setOpcoes({ categorias: ['Geral'], setores: [], clientes: [], responsaveis: [] }));
  }, [clienteId]);

  useEffect(() => {
    const fechar = (e: KeyboardEvent) => { if (e.key === 'Escape') aoFechar(); };
    document.addEventListener('keydown', fechar);
    return () => document.removeEventListener('keydown', fechar);
  }, [aoFechar]);

  const muda = (k: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) => setForm((f) => ({ ...f, [k]: e.target.value }));

  async function enviar(e: React.FormEvent) {
    e.preventDefault();
    setEnviando(true); setErro('');
    try {
      const r = await acaoPhp('api_acoes.php', { acao: 'abrir_chamado', cliente_id: clienteId, ...form }, anexos);
      location.href = r.id ? `${visao === 'equipe' ? '/painel' : '/cliente'}/chamado?id=${r.id}` : (visao === 'equipe' ? '/painel/chamados' : '/cliente');
    } catch (ex) {
      setErro(ex instanceof Error ? ex.message : 'Não deu pra abrir agora.');
      setEnviando(false);
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center sm:items-center" role="dialog" aria-modal="true" aria-label="Abrir chamado">
      <button type="button" aria-label="Fechar" className="absolute inset-0 bg-black/55" onClick={aoFechar} />
      <form onSubmit={(e) => void enviar(e)} className="relative max-h-[92dvh] w-full max-w-2xl overflow-y-auto rounded-t-3xl border border-borda bg-superficie p-5 pb-[max(1.25rem,env(safe-area-inset-bottom))] shadow-2xl sm:rounded-3xl">
        <div className="mb-4 flex items-center gap-3">
          <h2 className="flex-1 text-lg font-bold text-texto">Abrir chamado</h2>
          <button type="button" onClick={aoFechar} aria-label="Fechar" className="toque flex items-center justify-center rounded-xl text-suave"><X size={22} /></button>
        </div>
        <div className="grid gap-4 sm:grid-cols-2">
          {visao === 'equipe' && (
            <div className="sm:col-span-2">
              <Campo rotulo="Cliente *">
                <select required value={clienteId} onChange={(e) => { setClienteId(e.target.value); setForm((f) => ({ ...f, setor_id: '' })); }} className={CLASSE_ENTRADA}>
                  <option value="">Escolha o cliente</option>
                  {opcoes?.clientes.map((c) => <option key={c.id} value={c.id}>{c.nome}</option>)}
                </select>
              </Campo>
            </div>
          )}
          <div className="sm:col-span-2">
            <Campo rotulo="Título *"><input required value={form.titulo} onChange={muda('titulo')} maxLength={200} className={CLASSE_ENTRADA} placeholder="Resumo do problema" /></Campo>
          </div>
          <Campo rotulo="Categoria *">
            <select required value={form.categoria} onChange={muda('categoria')} className={CLASSE_ENTRADA}>
              <option value="">Selecione</option>
              {opcoes?.categorias.map((c) => <option key={c}>{c}</option>)}
            </select>
          </Campo>
          <Campo rotulo="Prioridade *">
            <select value={form.prioridade} onChange={muda('prioridade')} className={CLASSE_ENTRADA}>
              <option value="baixa">Baixa</option><option value="media">Média</option><option value="alta">Alta</option><option value="urgente">Urgente</option>
            </select>
          </Campo>
          {!!opcoes?.setores.length && (
            <Campo rotulo="Setor">
              <select value={form.setor_id} onChange={muda('setor_id')} className={CLASSE_ENTRADA}>
                <option value="">{visao === 'equipe' ? 'Sem setor' : 'Padrão'}</option>
                {opcoes.setores.map((s) => <option key={s.id} value={s.id}>{s.nome}</option>)}
              </select>
            </Campo>
          )}
          {visao === 'equipe' && (
            <Campo rotulo="Responsável">
              <select value={form.responsavel_id} onChange={muda('responsavel_id')} className={CLASSE_ENTRADA}>
                <option value="">Ninguém ainda</option>
                {opcoes?.responsaveis.map((u) => <option key={u.id} value={u.id}>{u.nome}</option>)}
              </select>
            </Campo>
          )}
          <div className="sm:col-span-2">
            <Campo rotulo="Descrição *">
              <textarea required value={form.descricao} onChange={muda('descricao')} rows={6} className="w-full rounded-xl border border-borda bg-fundo p-3 text-base text-texto outline-none focus:border-primaria" placeholder="Conte o que aconteceu, a rotina e a mensagem de erro, se tiver." />
            </Campo>
          </div>
        </div>
        {anexos.length > 0 && <p className="mt-3 text-sm text-suave">{anexos.length} anexo(s): {anexos.map((a) => a.name).join(', ')}</p>}
        {erro && <p className="mt-3 text-sm text-perigo" role="alert">{erro}</p>}
        <div className="mt-5 flex flex-col-reverse gap-3 sm:flex-row sm:items-center">
          <label className="toque inline-flex cursor-pointer items-center justify-center gap-2 rounded-xl border border-borda px-4 font-semibold text-texto hover:bg-superficie-alta">
            <Paperclip size={18} aria-hidden="true" /> Anexar arquivos
            <input type="file" multiple className="sr-only" onChange={(e) => { setAnexos((l) => [...l, ...Array.from(e.target.files ?? [])]); e.target.value = ''; }} />
          </label>
          <BotaoSecundario onClick={aoFechar} className="sm:ml-auto">Cancelar</BotaoSecundario>
          <BotaoPrimario type="submit" disabled={enviando}><Send size={18} aria-hidden="true" /> {enviando ? 'Abrindo…' : 'Abrir chamado'}</BotaoPrimario>
        </div>
      </form>
    </div>
  );
}
