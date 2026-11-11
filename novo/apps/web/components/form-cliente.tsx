'use client';

import type { ClienteDetalhe } from '@primehub/shared';
import { ImagePlus, Save, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { BotaoPrimario, BotaoSecundario, CLASSE_ENTRADA, Campo } from './ui/basico';
import { LogoEmpresa } from './ui/logo-empresa';

/** Cadastro/edição do cliente (só admin, como no sistema de sempre). */
export function FormCliente({ cliente, equipe = [], aoFechar }: { cliente?: ClienteDetalhe['cliente']; equipe?: ClienteDetalhe['equipe']; aoFechar: () => void }) {
  const [form, setForm] = useState({
    nome: cliente?.nome ?? '', status: cliente?.status ?? 'ativo',
    contato_nome: cliente?.contatoNome ?? '', contato_email: cliente?.contatoEmail ?? '',
    contato_telefone: cliente?.contatoTelefone ?? '', contato_documento: cliente?.contatoDocumento ?? '',
    responsavel_interno_id: cliente?.responsavelInternoId ? String(cliente.responsavelInternoId) : '', observacoes: cliente?.observacoes ?? '',
  });
  const [logo, setLogo] = useState<File | null>(null);
  const [previa, setPrevia] = useState<string | null>(null);
  const [salvando, setSalvando] = useState(false);
  const [erro, setErro] = useState('');

  useEffect(() => {
    if (!logo) { setPrevia(null); return; }
    const u = URL.createObjectURL(logo);
    setPrevia(u);
    return () => URL.revokeObjectURL(u);
  }, [logo]);

  const muda = (k: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) => setForm((f) => ({ ...f, [k]: e.target.value }));

  async function salvar(e: React.FormEvent) {
    e.preventDefault();
    setSalvando(true); setErro('');
    try {
      const corpo = new FormData();
      corpo.append('acao', 'salvar_cliente');
      if (cliente) corpo.append('cliente_id', String(cliente.id));
      for (const [k, v] of Object.entries(form)) corpo.append(k, v);
      if (logo) corpo.append('logo', logo);
      const r = await fetch('/api_acoes.php', { method: 'POST', body: corpo, credentials: 'same-origin', headers: { 'X-PrimeHub': '1' } });
      const d = (await r.json().catch(() => ({}))) as { id?: number; erro?: string };
      if (!r.ok || d.erro) throw new Error(d.erro ?? 'Não deu pra salvar agora.');
      location.href = `/painel/cliente?id=${d.id ?? cliente?.id ?? ''}`;
    } catch (ex) {
      setErro(ex instanceof Error ? ex.message : 'Não deu pra salvar agora.');
      setSalvando(false);
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center sm:items-center" role="dialog" aria-modal="true" aria-label={cliente ? 'Editar cliente' : 'Novo cliente'}>
      <button type="button" aria-label="Fechar" className="absolute inset-0 bg-black/55" onClick={aoFechar} />
      <form onSubmit={(e) => void salvar(e)} className="relative max-h-[92dvh] w-full max-w-2xl overflow-y-auto rounded-t-3xl border border-borda bg-superficie p-5 pb-[max(1.25rem,env(safe-area-inset-bottom))] shadow-2xl sm:rounded-3xl">
        <div className="mb-4 flex items-center gap-3">
          <h2 className="flex-1 text-lg font-bold text-texto">{cliente ? 'Editar cliente' : 'Novo cliente'}</h2>
          <button type="button" onClick={aoFechar} aria-label="Fechar" className="toque flex items-center justify-center rounded-xl text-suave"><X size={22} /></button>
        </div>
        <div className="mb-4 flex items-center gap-4">
          {previa ? <img src={previa} alt="" className="h-20 w-20 rounded-xl border border-borda bg-white object-contain p-1" /> : <LogoEmpresa nome={form.nome || 'Cliente'} logo={cliente?.logo ?? null} tamanho="g" />}
          <label className="toque inline-flex cursor-pointer items-center gap-2 rounded-xl border border-borda px-4 font-semibold text-texto hover:bg-superficie-alta">
            <ImagePlus size={18} aria-hidden="true" /> {cliente?.logo || logo ? 'Trocar logo' : 'Enviar logo'}
            <input type="file" accept="image/png,image/jpeg,image/webp,image/svg+xml" className="sr-only" onChange={(e) => setLogo(e.target.files?.[0] ?? null)} />
          </label>
        </div>
        <div className="grid gap-4 sm:grid-cols-2">
          <div className="sm:col-span-2"><Campo rotulo="Nome da empresa *"><input required value={form.nome} onChange={muda('nome')} className={CLASSE_ENTRADA} /></Campo></div>
          <Campo rotulo="Contato"><input value={form.contato_nome} onChange={muda('contato_nome')} className={CLASSE_ENTRADA} /></Campo>
          <Campo rotulo="E-mail do contato"><input type="email" value={form.contato_email} onChange={muda('contato_email')} className={CLASSE_ENTRADA} /></Campo>
          <Campo rotulo="Telefone / WhatsApp"><input type="tel" inputMode="tel" value={form.contato_telefone} onChange={muda('contato_telefone')} className={CLASSE_ENTRADA} /></Campo>
          <Campo rotulo="CPF/RG do contato"><input inputMode="numeric" value={form.contato_documento} onChange={muda('contato_documento')} className={CLASSE_ENTRADA} /></Campo>
          {cliente && (
            <>
              <Campo rotulo="Situação">
                <select value={form.status} onChange={muda('status')} className={CLASSE_ENTRADA}>
                  <option value="ativo">Ativo</option><option value="pausado">Pausado</option><option value="inativo">Inativo</option>
                </select>
              </Campo>
              <Campo rotulo="Responsável na Prime">
                <select value={form.responsavel_interno_id} onChange={muda('responsavel_interno_id')} className={CLASSE_ENTRADA}>
                  <option value="">Ninguém</option>
                  {equipe.map((u) => <option key={u.id} value={u.id}>{u.nome}</option>)}
                </select>
              </Campo>
              <div className="sm:col-span-2">
                <Campo rotulo="Observações"><textarea value={form.observacoes} onChange={muda('observacoes')} rows={3} className="w-full rounded-xl border border-borda bg-fundo p-3 text-base text-texto outline-none focus:border-primaria" /></Campo>
              </div>
            </>
          )}
        </div>
        {erro && <p className="mt-3 text-sm text-perigo" role="alert">{erro}</p>}
        <div className="mt-5 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
          <BotaoSecundario onClick={aoFechar}>Cancelar</BotaoSecundario>
          <BotaoPrimario type="submit" disabled={salvando}><Save size={18} aria-hidden="true" /> {salvando ? 'Salvando…' : 'Salvar'}</BotaoPrimario>
        </div>
      </form>
    </div>
  );
}
