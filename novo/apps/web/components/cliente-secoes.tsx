'use client';

import type { ClienteDetalhe } from '@primehub/shared';
import { ChevronRight, Download, FileText, KeyRound, Mail, Pencil, Plus, Trash2, UserX } from 'lucide-react';
import { useState } from 'react';
import { CalendarioMes } from './calendario-mes';
import { Avatar } from './ui/avatar';
import { Aviso, BotaoPrimario, BotaoSecundario, CLASSE_AREA, CLASSE_ENTRADA, Campo, EscolherArquivo, Modal, Vazio, dataBR, tamanhoArquivo } from './ui/basico';
import { enviarAcao } from '@/lib/api';

type Dados = ClienteDetalhe;
const CORES_SETOR = ['#0ea5e9', '#8b5cf6', '#10b981', '#f59e0b', '#f43f5e', '#06b6d4', '#84cc16', '#d946ef', '#f97316', '#6366f1'];

/** Ações na tela do cliente de sempre (cliente.php), com as mesmas regras e avisos. */
function useAcaoCliente(clienteId: number, recarregar: () => void) {
  const [ocupado, setOcupado] = useState(false);
  const [aviso, setAviso] = useState<{ t: string; e: boolean }>({ t: '', e: false });
  async function agir(campos: Record<string, string>, arquivos: Record<string, File | null> = {}): Promise<boolean> {
    setOcupado(true); setAviso({ t: '', e: false });
    try {
      const r = await enviarAcao(`cliente.php?id=${clienteId}`, campos, arquivos);
      setAviso({ t: r.mensagens.sucesso ?? 'Pronto!', e: false });
      recarregar();
      return true;
    } catch (e) { setAviso({ t: e instanceof Error ? e.message : 'Não deu certo.', e: true }); return false; } finally { setOcupado(false); }
  }
  return { agir, ocupado, aviso };
}

// ---------------------------------------------------------------- Setores

export function SecaoSetores({ clienteId, dados, recarregar }: { clienteId: number; dados: Dados; recarregar: () => void }) {
  const { agir, ocupado, aviso } = useAcaoCliente(clienteId, recarregar);
  const [form, setForm] = useState<{ setor_id: string; nome: string; ativo: boolean } | null>(null);
  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between gap-3">
        <p className="text-sm text-suave">Toque em um setor para ver os chamados, documentos, calendário, vídeo aulas, fontes, propostas, métricas e IA só dele.</p>
        <BotaoPrimario onClick={() => setForm({ setor_id: '', nome: '', ativo: true })}><Plus size={18} aria-hidden="true" /> Setor</BotaoPrimario>
      </div>
      {aviso.t && !form && <Aviso texto={aviso.t} erro={aviso.e} />}
      {dados.setores.length ? (
        <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          {dados.setores.map((s, i) => {
            const cor = CORES_SETOR[i % CORES_SETOR.length] ?? '#0ea5e9';
            const pessoas = dados.pessoas.filter((p) => p.setorIds?.includes(s.id));
            return (
              <li key={s.id} className="group relative overflow-hidden rounded-3xl border border-borda bg-superficie shadow-sm transition hover:-translate-y-0.5 hover:shadow-lg">
                <span aria-hidden="true" className="absolute inset-x-0 top-0 h-1" style={{ background: cor }} />
                <a href={`/painel/setor?cliente=${clienteId}&id=${s.id}`} className="flex items-center gap-3 p-4 pr-14">
                  <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl text-lg font-bold text-white" style={{ background: `linear-gradient(135deg, ${cor}, color-mix(in oklab, ${cor} 55%, #0b1020))` }}>{s.nome.slice(0, 2).toUpperCase()}</span>
                  <div className="min-w-0 flex-1">
                    <p className="truncate font-semibold text-texto">{s.nome}</p>
                    <p className="text-sm text-suave">{s.ativo ? `${s.pendentes} pendente(s)` : 'Desativado'}</p>
                    <div className="mt-1 flex -space-x-1.5">{pessoas.slice(0, 5).map((p) => <Avatar key={p.id} nome={p.nome} foto={p.foto} tamanho={22} />)}</div>
                  </div>
                  <ChevronRight size={20} className="shrink-0 text-suave transition group-hover:translate-x-1" aria-hidden="true" />
                </a>
                <button type="button" onClick={() => setForm({ setor_id: String(s.id), nome: s.nome, ativo: s.ativo })} aria-label={`Editar ${s.nome}`} className="toque absolute right-2 top-2 flex items-center justify-center rounded-xl text-suave hover:text-texto"><Pencil size={16} /></button>
              </li>
            );
          })}
        </ul>
      ) : <Vazio>Nenhum setor ainda. Crie o primeiro (Financeiro, Fiscal, Compras…).</Vazio>}
      {form && (
        <Modal titulo={form.setor_id ? 'Editar setor' : 'Novo setor'} aoFechar={() => setForm(null)} largura="max-w-md">
          <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); void agir({ acao: form.setor_id ? 'atualizar_setor' : 'criar_setor', setor_id: form.setor_id, nome: form.nome, icone: '', ...(form.ativo ? { ativo: '1' } : {}) }).then((ok) => ok && setForm(null)); }}>
            <Campo rotulo="Nome do setor *"><input required value={form.nome} onChange={(e) => setForm({ ...form, nome: e.target.value })} className={CLASSE_ENTRADA} placeholder="Ex.: Financeiro" /></Campo>
            {form.setor_id && <label className="toque flex items-center gap-3 text-sm text-texto"><input type="checkbox" checked={form.ativo} onChange={(e) => setForm({ ...form, ativo: e.target.checked })} className="h-5 w-5" />Setor ativo</label>}
            {aviso.e && <Aviso texto={aviso.t} erro />}
            <div className="flex flex-col-reverse gap-3 sm:flex-row">
              {form.setor_id && <BotaoSecundario className="text-perigo" onClick={() => { if (confirm(`Excluir o setor "${form.nome}"? Os chamados ficam sem setor e as pessoas continuam cadastradas.`)) void agir({ acao: 'excluir_setor', setor_id: form.setor_id }).then((ok) => ok && setForm(null)); }}><Trash2 size={16} aria-hidden="true" /> Excluir</BotaoSecundario>}
              <BotaoPrimario type="submit" disabled={ocupado} className="sm:ml-auto">Salvar</BotaoPrimario>
            </div>
          </form>
        </Modal>
      )}
    </div>
  );
}

// ---------------------------------------------------------------- Usuários do cliente

interface FormPessoa { responsavel_id: string; nome: string; email: string; telefone: string; documento: string; eh_master: boolean; setores: number[] }

export function SecaoPessoas({ clienteId, dados, recarregar }: { clienteId: number; dados: Dados; recarregar: () => void }) {
  const { agir, ocupado, aviso } = useAcaoCliente(clienteId, recarregar);
  const [form, setForm] = useState<FormPessoa | null>(null);
  const salvar = (f: FormPessoa) => {
    const campos: Record<string, string> = { acao: f.responsavel_id ? 'editar_usuario_cliente' : 'criar_usuario_cliente', responsavel_id: f.responsavel_id, nome: f.nome, email: f.email, telefone: f.telefone, documento: f.documento };
    if (f.eh_master) campos.eh_master = '1';
    f.setores.forEach((s, i) => { campos[`setor_ids[${i}]`] = String(s); });
    void agir(campos).then((ok) => ok && setForm(null));
  };
  return (
    <div className="space-y-3">
      <div className="flex justify-end"><BotaoPrimario onClick={() => setForm({ responsavel_id: '', nome: '', email: '', telefone: '', documento: '', eh_master: false, setores: [] })}><Plus size={18} aria-hidden="true" /> Usuário</BotaoPrimario></div>
      {aviso.t && !form && <Aviso texto={aviso.t} erro={aviso.e} />}
      {dados.pessoas.length ? (
        <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          {dados.pessoas.map((p) => (
            <li key={p.id} className="flex flex-col gap-3 rounded-3xl border border-borda bg-superficie p-4 shadow-sm">
              <div className="flex items-center gap-3">
                <Avatar nome={p.nome} foto={p.foto} tamanho={48} />
                <div className="min-w-0 flex-1">
                  <p className="truncate font-semibold text-texto">{p.nome}{p.master ? <span className="ml-2 rounded-full bg-primaria/15 px-2 py-0.5 text-xs text-primaria">Master</span> : null}</p>
                  <p className="truncate text-sm text-suave">{p.email ?? 'sem e-mail'}</p>
                  <p className="truncate text-xs text-suave">{p.setor ?? 'Sem setor'}</p>
                </div>
              </div>
              <div className="mt-auto grid grid-cols-4 gap-2">
                <BotaoSecundario aria-label={`Editar ${p.nome}`} onClick={() => setForm({ responsavel_id: String(p.id), nome: p.nome, email: p.email ?? '', telefone: p.telefone ?? '', documento: p.documento ?? '', eh_master: Boolean(p.master), setores: p.setorIds ?? [] })}><Pencil size={16} /></BotaoSecundario>
                <BotaoSecundario aria-label={`Enviar convite para ${p.nome}`} disabled={ocupado || !p.email} onClick={() => void agir({ acao: 'enviar_convite_setor', responsavel_id: String(p.id) })}><Mail size={16} className="text-sky-500" /></BotaoSecundario>
                <BotaoSecundario aria-label={`Nova senha para ${p.nome}`} disabled={ocupado || !p.email} onClick={() => { if (confirm(`Mandar para ${p.nome} um link de nova senha?`)) void agir({ acao: 'resetar_senha_setor', responsavel_id: String(p.id) }); }}><KeyRound size={16} className="text-amber-500" /></BotaoSecundario>
                <BotaoSecundario aria-label={p.portalAtivo ? `Tirar acesso de ${p.nome}` : `Excluir ${p.nome}`} disabled={ocupado} onClick={() => {
                  if (p.portalAtivo) { if (confirm(`Tirar o acesso de ${p.nome} ao portal?`)) void agir({ acao: 'desativar_portal_setor', responsavel_id: String(p.id) }); }
                  else if (confirm(`Excluir ${p.nome}?`)) void agir({ acao: 'excluir_usuario_cliente', responsavel_id: String(p.id) });
                }}>{p.portalAtivo ? <UserX size={16} className="text-perigo" /> : <Trash2 size={16} className="text-perigo" />}</BotaoSecundario>
              </div>
            </li>
          ))}
        </ul>
      ) : <Vazio>Nenhum usuário cadastrado.</Vazio>}
      {form && (
        <Modal titulo={form.responsavel_id ? 'Editar usuário' : 'Novo usuário do cliente'} aoFechar={() => setForm(null)}>
          <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); salvar(form); }}>
            <div className="grid gap-4 sm:grid-cols-2">
              <Campo rotulo="Nome *"><input required value={form.nome} onChange={(e) => setForm({ ...form, nome: e.target.value })} className={CLASSE_ENTRADA} /></Campo>
              <Campo rotulo="E-mail *"><input type="email" required value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} className={CLASSE_ENTRADA} /></Campo>
              <Campo rotulo="Telefone / WhatsApp"><input type="tel" value={form.telefone} onChange={(e) => setForm({ ...form, telefone: e.target.value })} className={CLASSE_ENTRADA} /></Campo>
              <Campo rotulo="CPF/RG"><input inputMode="numeric" value={form.documento} onChange={(e) => setForm({ ...form, documento: e.target.value })} className={CLASSE_ENTRADA} /></Campo>
            </div>
            <fieldset className="space-y-2">
              <legend className="text-sm font-semibold text-texto">Setores</legend>
              <div className="flex flex-wrap gap-2">
                {dados.setores.map((s, i) => {
                  const marcado = form.setores.includes(s.id);
                  const cor = CORES_SETOR[i % CORES_SETOR.length] ?? '#0ea5e9';
                  return <button key={s.id} type="button" aria-pressed={marcado} onClick={() => setForm({ ...form, setores: marcado ? form.setores.filter((x) => x !== s.id) : [...form.setores, s.id] })}
                    className="toque rounded-full border px-4 text-sm font-semibold" style={marcado ? { background: cor, borderColor: cor, color: '#fff' } : { borderColor: 'var(--borda)', color: 'var(--textoSuave)' }}>{s.nome}</button>;
                })}
              </div>
            </fieldset>
            <label className="toque flex items-center gap-3 text-sm text-texto"><input type="checkbox" checked={form.eh_master} onChange={(e) => setForm({ ...form, eh_master: e.target.checked })} className="h-5 w-5" />Master: vê os chamados de todos os setores</label>
            {aviso.e && <Aviso texto={aviso.t} erro />}
            <div className="flex justify-end gap-3"><BotaoSecundario onClick={() => setForm(null)}>Cancelar</BotaoSecundario><BotaoPrimario type="submit" disabled={ocupado}>Salvar</BotaoPrimario></div>
          </form>
        </Modal>
      )}
    </div>
  );
}

// ---------------------------------------------------------------- Agenda do cliente (calendário)

const COR_TIPO: Record<string, string> = { treinamento: '#10b981', reuniao: '#6366f1', conversa: '#0ea5e9', auxilio: '#f59e0b' };

export function SecaoAgendaCliente({ clienteId, dados }: { clienteId: number; dados: Dados }) {
  return (
    <div className="space-y-3">
      <p className="text-sm text-suave">Treinamentos, reuniões, conversas e auxílios de todos os setores. Para agendar, entre no setor e use Calendário.</p>
      <CalendarioMes eventos={dados.treinamentos.filter((t) => t.inicio).map((t) => ({
        id: String(t.id), data: (t.inicio ?? '').slice(0, 10), hora: (t.inicio ?? '').slice(11, 16), titulo: `${t.titulo}${t.setor ? ` · ${t.setor}` : ''}`, cor: COR_TIPO[t.tipo ?? ''] ?? '#10b981',
        aoClicar: () => { const s = dados.setores.find((x) => x.nome === t.setor); if (s) location.href = `/painel/setor?cliente=${clienteId}&id=${s.id}#treinamentos`; else if (t.link) window.open(t.link, '_blank', 'noopener'); },
      }))} />
      <div className="flex flex-wrap gap-3 text-xs text-suave">{Object.entries(COR_TIPO).map(([k, c]) => <span key={k} className="flex items-center gap-1.5"><span className="h-3 w-3 rounded-full" style={{ background: c }} />{{ treinamento: 'Treinamento', reuniao: 'Reunião', conversa: 'Conversa', auxilio: 'Auxílio' }[k]}</span>)}</div>
    </div>
  );
}

// ---------------------------------------------------------------- Documentos

export function SecaoDocumentos({ clienteId, dados, recarregar }: { clienteId: number; dados: Dados; recarregar: () => void }) {
  const { agir, ocupado, aviso } = useAcaoCliente(clienteId, recarregar);
  const [form, setForm] = useState<{ titulo: string; categoria: string; descricao: string; setor_id: string; arquivo: File | null } | null>(null);
  const [arrastando, setArrastando] = useState(false);
  const soltar = (f: File | undefined) => { if (f) setForm({ titulo: f.name.replace(/\.[^.]+$/, ''), categoria: 'Geral', descricao: '', setor_id: '', arquivo: f }); };
  return (
    <div className="space-y-3"
      onDragOver={(e) => { if (e.dataTransfer.types.includes('Files')) { e.preventDefault(); setArrastando(true); } }}
      onDragLeave={(e) => { if (!e.currentTarget.contains(e.relatedTarget as Node | null)) setArrastando(false); }}
      onDrop={(e) => { e.preventDefault(); setArrastando(false); soltar(e.dataTransfer.files[0]); }}>
      <div className={`flex flex-col items-center gap-2 rounded-3xl border-2 border-dashed p-6 text-center transition ${arrastando ? 'border-primaria bg-primaria/10' : 'border-borda'}`}>
        <FileText size={28} className="text-amber-500" aria-hidden="true" />
        <p className="font-semibold text-texto">Arraste um documento para cá</p>
        <BotaoPrimario onClick={() => setForm({ titulo: '', categoria: 'Geral', descricao: '', setor_id: '', arquivo: null })}><Plus size={18} aria-hidden="true" /> Enviar documento</BotaoPrimario>
      </div>
      {aviso.t && !form && <Aviso texto={aviso.t} erro={aviso.e} />}
      {dados.documentos.length ? (
        <ul className="grid gap-2 md:grid-cols-2">
          {dados.documentos.map((d) => (
            <li key={d.id}>
              <a href={`/download.php?id=${d.id}`} className="toque flex items-center gap-3 rounded-2xl border border-borda bg-superficie p-3 shadow-sm hover:border-primaria/50">
                <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-amber-500/15 text-amber-500"><FileText size={20} aria-hidden="true" /></span>
                <div className="min-w-0 flex-1"><p className="truncate font-semibold text-texto">{d.titulo}</p><p className="truncate text-xs text-suave">{[d.categoria, d.setor, dataBR(d.criadoEm), tamanhoArquivo(d.tamanho)].filter(Boolean).join(' · ')}</p></div>
                <Download size={20} className="shrink-0 text-primaria" aria-hidden="true" />
              </a>
            </li>
          ))}
        </ul>
      ) : <Vazio>Nenhum documento enviado.</Vazio>}
      {form && (
        <Modal titulo="Enviar documento" aoFechar={() => setForm(null)}>
          <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); void agir({ acao: 'upload_documento', titulo: form.titulo, categoria: form.categoria, descricao: form.descricao, setor_id: form.setor_id }, { arquivo: form.arquivo }).then((ok) => ok && setForm(null)); }}>
            <Campo rotulo="Título *"><input required value={form.titulo} onChange={(e) => setForm({ ...form, titulo: e.target.value })} className={CLASSE_ENTRADA} /></Campo>
            <div className="grid gap-4 sm:grid-cols-2">
              <Campo rotulo="Categoria"><input value={form.categoria} onChange={(e) => setForm({ ...form, categoria: e.target.value })} className={CLASSE_ENTRADA} /></Campo>
              <Campo rotulo="Setor"><select value={form.setor_id} onChange={(e) => setForm({ ...form, setor_id: e.target.value })} className={CLASSE_ENTRADA}><option value="">Geral (sem setor)</option>{dados.setores.map((s) => <option key={s.id} value={s.id}>{s.nome}</option>)}</select></Campo>
            </div>
            <Campo rotulo="Descrição"><textarea rows={3} value={form.descricao} onChange={(e) => setForm({ ...form, descricao: e.target.value })} className={CLASSE_AREA} /></Campo>
            <EscolherArquivo rotulo="Escolher arquivo *" aoEscolher={(f) => setForm({ ...form, arquivo: f[0] ?? null })} nomes={form.arquivo ? [form.arquivo.name] : []} />
            {aviso.e && <Aviso texto={aviso.t} erro />}
            <div className="flex justify-end gap-3"><BotaoSecundario onClick={() => setForm(null)}>Cancelar</BotaoSecundario><BotaoPrimario type="submit" disabled={ocupado || !form.arquivo}>Enviar</BotaoPrimario></div>
          </form>
        </Modal>
      )}
    </div>
  );
}

// ---------------------------------------------------------------- Equipe da Prime vinculada ao cliente

export function SecaoEquipePrime({ clienteId, dados, recarregar }: { clienteId: number; dados: Dados; recarregar: () => void }) {
  const [form, setForm] = useState<{ usuario_id: string; setores: number[] } | null>(null);
  const [ocupado, setOcupado] = useState(false);
  const [aviso, setAviso] = useState<{ t: string; e: boolean }>({ t: '', e: false });
  async function agir(campos: Record<string, string>) {
    setOcupado(true); setAviso({ t: '', e: false });
    try {
      const r = await enviarAcao('usuarios.php', campos);
      setAviso({ t: r.mensagens.sucesso ?? 'Pronto!', e: false }); setForm(null); recarregar();
    } catch (e) { setAviso({ t: e instanceof Error ? e.message : 'Não deu certo.', e: true }); }
    setOcupado(false);
  }
  const porPessoa = new Map<number, Dados['vinculos']>();
  for (const v of dados.vinculos) porPessoa.set(v.usuarioId, [...(porPessoa.get(v.usuarioId) ?? []), v]);
  return (
    <div className="space-y-3">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
        <p className="flex-1 text-sm text-suave">Quem da Prime atende este cliente. Colaboradores com "só enxerga os clientes vinculados" passam a ver este cliente (ou só os setores escolhidos).</p>
        <BotaoPrimario onClick={() => setForm({ usuario_id: '', setores: [] })}><Plus size={18} aria-hidden="true" /> Vincular usuário</BotaoPrimario>
      </div>
      {aviso.t && !form && <Aviso texto={aviso.t} erro={aviso.e} />}
      {porPessoa.size ? (
        <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          {[...porPessoa.values()].map((l) => {
            const p = l[0];
            if (!p) return null;
            return (
              <li key={p.usuarioId} className="space-y-3 rounded-3xl border border-borda bg-superficie p-4 shadow-sm">
                <div className="flex items-center gap-3">
                  <Avatar nome={p.nome} foto={p.foto} tamanho={48} anel="ring-primaria/60" />
                  <div className="min-w-0 flex-1"><p className="truncate font-semibold text-texto">{p.nome}</p><p className="text-xs text-suave">{p.restrito ? 'Acesso restrito aos vínculos' : 'Acesso a todos os clientes'}</p></div>
                </div>
                <ul className="flex flex-wrap gap-2">
                  {l.map((v) => (
                    <li key={v.id} className="inline-flex items-center gap-1 rounded-full bg-primaria/10 py-1 pl-3 pr-1 text-sm font-semibold text-primaria">
                      {v.setor ?? 'Cliente inteiro'}
                      <button type="button" aria-label={`Remover vínculo ${v.setor ?? 'cliente inteiro'} de ${v.nome}`} disabled={ocupado} onClick={() => { if (confirm(`Remover ${v.nome} de ${v.setor ?? 'todo o cliente'}?`)) void agir({ acao: 'remover_vinculo_colaborador', vinculo_id: String(v.id), usuario_id: String(v.usuarioId) }); }} className="flex h-6 w-6 items-center justify-center rounded-full hover:bg-perigo/20 hover:text-perigo">×</button>
                    </li>
                  ))}
                </ul>
              </li>
            );
          })}
        </ul>
      ) : <Vazio>Ninguém da equipe vinculado ainda.</Vazio>}
      {form && (
        <Modal titulo="Vincular usuário da Prime" aoFechar={() => setForm(null)} largura="max-w-lg">
          <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); const c: Record<string, string> = { acao: 'adicionar_vinculo_colaborador', usuario_id: form.usuario_id, cliente_id: String(clienteId) }; form.setores.forEach((s, i) => { c[`setor_ids[${i}]`] = String(s); }); void agir(c); }}>
            <Campo rotulo="Usuário *">
              <select required value={form.usuario_id} onChange={(e) => setForm({ ...form, usuario_id: e.target.value })} className={CLASSE_ENTRADA}>
                <option value="">Escolha</option>{dados.equipe.map((u) => <option key={u.id} value={u.id}>{u.nome}</option>)}
              </select>
            </Campo>
            <fieldset className="space-y-2">
              <legend className="text-sm font-semibold text-texto">Setores (nenhum = cliente inteiro)</legend>
              <div className="flex flex-wrap gap-2">
                {dados.setores.map((s, i) => {
                  const marcado = form.setores.includes(s.id);
                  const cor = CORES_SETOR[i % CORES_SETOR.length] ?? '#0ea5e9';
                  return <button key={s.id} type="button" aria-pressed={marcado} onClick={() => setForm({ ...form, setores: marcado ? form.setores.filter((x) => x !== s.id) : [...form.setores, s.id] })}
                    className="toque rounded-full border px-4 text-sm font-semibold" style={marcado ? { background: cor, borderColor: cor, color: '#fff' } : { borderColor: 'var(--borda)', color: 'var(--textoSuave)' }}>{s.nome}</button>;
                })}
              </div>
            </fieldset>
            {aviso.e && <Aviso texto={aviso.t} erro />}
            <div className="flex justify-end gap-3"><BotaoSecundario onClick={() => setForm(null)}>Cancelar</BotaoSecundario><BotaoPrimario type="submit" disabled={ocupado}>Vincular</BotaoPrimario></div>
          </form>
        </Modal>
      )}
    </div>
  );
}

// ---------------------------------------------------------------- Análise de risco de perda e melhorias (IA)

export function SecaoRisco({ clienteId, dados, recarregar }: { clienteId: number; dados: Dados; recarregar: () => void }) {
  const [setor, setSetor] = useState('');
  const [gerando, setGerando] = useState<'' | 'risco' | 'melhorias'>('');
  const [aviso, setAviso] = useState<{ t: string; e: boolean }>({ t: '', e: false });
  const [editando, setEditando] = useState<{ id: number; texto: string } | null>(null);

  async function gerar(tipo: 'risco' | 'melhorias') {
    setGerando(tipo); setAviso({ t: tipo === 'risco' ? 'A IA está cruzando chamados, prazos, indicadores e humor das conversas…' : 'A IA está procurando oportunidades de melhoria…', e: false });
    try {
      const corpo = new FormData();
      corpo.append('acao', 'gerar_analise_risco'); corpo.append('ajax', '1'); corpo.append('tipo', tipo); corpo.append('setor_id', setor);
      const r = await fetch(`/cliente.php?id=${clienteId}`, { method: 'POST', body: corpo, credentials: 'same-origin', headers: { 'X-PrimeHub': '1' } });
      const j = (await r.json()) as { sucesso?: boolean; mensagem?: string };
      setAviso({ t: j.sucesso ? 'Análise pronta!' : (j.mensagem ?? 'Não deu para gerar agora.'), e: !j.sucesso });
      recarregar();
    } catch { setAviso({ t: 'Não deu para gerar agora. Tente de novo em instantes.', e: true }); }
    setGerando('');
  }
  async function salvarEdicao() {
    if (!editando) return;
    try { await enviarAcao(`cliente.php?id=${clienteId}`, { acao: 'atualizar_analise_risco', id: String(editando.id), texto: editando.texto }); setEditando(null); recarregar(); } catch (e) { setAviso({ t: e instanceof Error ? e.message : 'Não deu certo.', e: true }); }
  }
  async function exportar(a: Dados['analises'][number]) {
    const corpo = new FormData();
    corpo.append('acao', 'exportar_pdf_analise_risco'); corpo.append('texto', a.texto); corpo.append('label', a.tipo === 'risco' ? 'Análise de Risco' : 'Sugestões de Melhoria');
    const r = await fetch(`/cliente.php?id=${clienteId}`, { method: 'POST', body: corpo, credentials: 'same-origin', headers: { 'X-PrimeHub': '1' } });
    const blob = await r.blob();
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url; link.download = `${a.tipo === 'risco' ? 'analise-de-risco' : 'melhorias'}-${dados.cliente.nome}.pdf`; link.click();
    setTimeout(() => URL.revokeObjectURL(url), 2000);
  }
  return (
    <div className="space-y-4">
      <div className="grid gap-3 rounded-3xl border border-borda bg-superficie p-4 shadow-sm md:grid-cols-[1fr_auto_auto] md:items-end">
        <Campo rotulo="Escopo da análise">
          <select value={setor} onChange={(e) => setSetor(e.target.value)} className={CLASSE_ENTRADA}>
            <option value="">Cliente inteiro (todos os setores)</option>{dados.setores.map((s) => <option key={s.id} value={s.id}>Setor {s.nome}</option>)}
          </select>
        </Campo>
        <BotaoPrimario onClick={() => void gerar('risco')} disabled={!!gerando} className="!bg-none" style={{ background: 'linear-gradient(135deg,#f43f5e,#be123c)' }}>🛡️ {gerando === 'risco' ? 'Analisando…' : 'Risco de perda'}</BotaoPrimario>
        <BotaoPrimario onClick={() => void gerar('melhorias')} disabled={!!gerando} className="!bg-none" style={{ background: 'linear-gradient(135deg,#10b981,#047857)' }}>💡 {gerando === 'melhorias' ? 'Analisando…' : 'Sugerir melhorias'}</BotaoPrimario>
      </div>
      {aviso.t && <Aviso texto={aviso.t} erro={aviso.e} />}
      {dados.analises.length ? dados.analises.map((a) => (
        <article key={a.id} className={`rounded-3xl border p-5 shadow-sm ${a.tipo === 'risco' ? 'border-rose-500/40 bg-rose-500/5' : 'border-emerald-500/40 bg-emerald-500/5'}`}>
          <div className="mb-3 flex flex-wrap items-center gap-2">
            <span className={`rounded-full px-3 py-1 text-xs font-bold text-white ${a.tipo === 'risco' ? 'bg-rose-500' : 'bg-emerald-500'}`}>{a.tipo === 'risco' ? '🛡️ Risco de perda' : '💡 Melhorias'}</span>
            <span className="text-sm text-suave">{a.setor ? `Setor ${a.setor}` : 'Cliente inteiro'} · {dataBR(a.criadoEm, true)}{a.autor ? ` · ${a.autor}` : ''}</span>
            <div className="ml-auto flex gap-1">
              <button type="button" onClick={() => void exportar(a)} className="toque inline-flex items-center gap-1 rounded-xl px-3 text-sm font-semibold text-primaria hover:bg-primaria/10"><Download size={16} aria-hidden="true" />PDF</button>
              <button type="button" onClick={() => setEditando({ id: a.id, texto: a.texto })} aria-label="Editar análise" className="toque flex items-center justify-center rounded-xl text-sky-500 hover:bg-sky-500/10"><Pencil size={16} /></button>
              <button type="button" aria-label="Excluir análise" onClick={() => { if (confirm('Excluir esta análise?')) void enviarAcao(`cliente.php?id=${clienteId}`, { acao: 'excluir_analise_risco', id: String(a.id) }).then(recarregar); }} className="toque flex items-center justify-center rounded-xl text-suave hover:bg-perigo/10 hover:text-perigo"><Trash2 size={16} /></button>
            </div>
          </div>
          <div className="space-y-2 text-[15px] leading-relaxed text-texto">
            {a.texto.split('\n').filter((l) => l.trim()).map((l, i) => {
              const t = l.replace(/\*\*/g, '').replace(/^#+\s*/, '');
              return /^#/.test(l) || /^\*\*.*\*\*$/.test(l.trim()) ? <p key={i} className="pt-2 font-bold">{t}</p> : <p key={i} className={/^\s*[-*•]/.test(l) ? 'pl-4' : ''}>{t.replace(/^\s*[-*]\s*/, '• ')}</p>;
            })}
          </div>
        </article>
      )) : <Vazio>Nenhuma análise ainda. Gere a primeira acima.</Vazio>}
      {editando && (
        <Modal titulo="Editar análise" aoFechar={() => setEditando(null)} largura="max-w-3xl">
          <textarea rows={16} value={editando.texto} onChange={(e) => setEditando({ ...editando, texto: e.target.value })} className={CLASSE_AREA} />
          <div className="mt-3 flex justify-end gap-3"><BotaoSecundario onClick={() => setEditando(null)}>Cancelar</BotaoSecundario><BotaoPrimario onClick={() => void salvarEdicao()}>Salvar</BotaoPrimario></div>
        </Modal>
      )}
    </div>
  );
}
