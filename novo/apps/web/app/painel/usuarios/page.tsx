'use client';

import type { UsuarioEquipe } from '@primehub/shared';
import { Camera, KeyRound, Pencil, Plus, Power } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Casca } from '@/components/casca';
import { EsqueletoLista } from '@/components/esqueleto';
import { CampoSenha } from '@/components/ui/campo-senha';
import { Aviso, BotaoPrimario, BotaoSecundario, CLASSE_ENTRADA, Campo, EscolherArquivo, Modal, dataBR } from '@/components/ui/basico';
import { api, arquivoPublico, enviarAcao } from '@/lib/api';

const PERFIS: Record<string, string> = { colaborador: 'Colaborador', admin: 'Administrador', diretor: 'Diretor', ceo: 'CEO' };
const COR_PERFIL: Record<string, string> = { colaborador: '#0ea5e9', admin: '#8b5cf6', diretor: '#f59e0b', ceo: '#f43f5e' };

interface Form { usuario_id: string; nome: string; email: string; telefone: string; documento: string; perfil: string; senha: string; acesso_restrito_por_cliente: boolean; acesso_dados_sensiveis: boolean }

export default function PaginaUsuarios() {
  const [itens, setItens] = useState<UsuarioEquipe[] | null>(null);
  const [erro, setErro] = useState('');
  const [form, setForm] = useState<Form | null>(null);
  const [senha, setSenha] = useState<{ id: number; nome: string; nova: string; confirmar: string } | null>(null);
  const [salvando, setSalvando] = useState(false);
  const [avatar, setAvatar] = useState<UsuarioEquipe | null>(null);
  const [aviso, setAviso] = useState('');

  const carregar = () => api<UsuarioEquipe[]>('/api/usuarios').then(setItens).catch((e: unknown) => setErro(e instanceof Error ? e.message : 'Não consegui abrir os usuários.'));
  useEffect(() => { void carregar(); }, []);

  async function salvar(e: React.FormEvent) {
    e.preventDefault();
    if (!form) return;
    setSalvando(true); setAviso('');
    try {
      await enviarAcao('usuarios.php', {
        acao: form.usuario_id ? 'atualizar_usuario' : 'criar_usuario', usuario_id: form.usuario_id, nome: form.nome, email: form.email,
        telefone: form.telefone, documento: form.documento, perfil: form.perfil, senha: form.senha, acesso_restrito_por_cliente: form.acesso_restrito_por_cliente ? '1' : '0', acesso_dados_sensiveis: form.acesso_dados_sensiveis ? '1' : '0',
      });
      setForm(null); void carregar();
    } catch (ex) { setAviso(ex instanceof Error ? ex.message : 'Não deu pra salvar.'); }
    setSalvando(false);
  }

  async function definirSenha(e: React.FormEvent) {
    e.preventDefault();
    if (!senha) return;
    setSalvando(true); setAviso('');
    try {
      await enviarAcao('usuarios.php', { acao: 'definir_senha_usuario', usuario_id: String(senha.id), nova_senha: senha.nova, confirmar_senha: senha.confirmar });
      setSenha(null);
    } catch (ex) { setAviso(ex instanceof Error ? ex.message : 'Não deu pra trocar a senha.'); }
    setSalvando(false);
  }

  async function trocarAvatar(campos: Record<string, string>, foto?: File) {
    if (!avatar) return;
    setSalvando(true); setAviso('');
    try {
      await enviarAcao('usuarios.php', { usuario_id: String(avatar.id), ...campos }, foto ? { foto } : {});
      setAvatar(null); void carregar();
    } catch (ex) { setAviso(ex instanceof Error ? ex.message : 'Não deu para trocar a foto.'); }
    setSalvando(false);
  }

  async function alternar(u: UsuarioEquipe) {
    if (!confirm(u.ativo ? `Desativar ${u.nome}? Ele(a) não consegue mais entrar.` : `Reativar ${u.nome}?`)) return;
    try { await enviarAcao('usuarios.php', { acao: 'alternar_status', usuario_id: String(u.id) }); void carregar(); } catch (ex) { alert(ex instanceof Error ? ex.message : 'Não deu certo.'); }
  }

  const acoes = (
    <button type="button" onClick={() => { setAviso(''); setForm({ usuario_id: '', nome: '', email: '', telefone: '', documento: '', perfil: 'colaborador', senha: '', acesso_restrito_por_cliente: false, acesso_dados_sensiveis: false }); }}
      className="gradiente-marca toque inline-flex items-center gap-2 rounded-xl px-4 font-semibold text-white shadow">
      <Plus size={20} aria-hidden="true" /> <span className="hidden sm:inline">Novo usuário</span>
    </button>
  );

  return (
    <Casca tipo="equipe" titulo="Usuários da equipe" acoes={acoes}>
      {erro && <Aviso texto={erro} erro />}
      {!itens && !erro ? <EsqueletoLista /> : (
        <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          {(itens ?? []).map((u) => (
            <li key={u.id} className={`relative flex flex-col gap-3 overflow-hidden rounded-3xl border border-borda bg-superficie p-4 pt-6 shadow-sm transition hover:-translate-y-0.5 hover:shadow-lg ${u.ativo ? '' : 'opacity-60'}`}>
              <span aria-hidden="true" className="absolute inset-x-0 top-0 h-16 opacity-25" style={{ background: `linear-gradient(135deg, ${COR_PERFIL[u.perfil] ?? '#0ea5e9'}, transparent)` }} />
              <div className="relative flex items-center gap-3">
                <button type="button" onClick={() => { setAviso(''); setAvatar(u); }} aria-label={`Trocar foto de ${u.nome}`} title="Trocar foto ou avatar" className="group relative shrink-0">
                  {u.foto ? <img src={arquivoPublico(u.foto) ?? ''} alt="" className="h-14 w-14 rounded-full object-cover ring-2" style={{ ['--tw-ring-color' as string]: COR_PERFIL[u.perfil] ?? '#0ea5e9' }} />
                    : <span aria-hidden="true" className="flex h-14 w-14 items-center justify-center rounded-full text-lg font-bold text-white" style={{ background: COR_PERFIL[u.perfil] ?? '#0ea5e9' }}>{u.nome.slice(0, 1)}</span>}
                  <span className="absolute -bottom-0.5 -right-0.5 flex h-6 w-6 items-center justify-center rounded-full border-2 border-superficie bg-primaria text-white transition group-hover:scale-110"><Camera size={12} aria-hidden="true" /></span>
                </button>
                <div className="min-w-0 flex-1">
                  <p className="truncate font-semibold text-texto">{u.nome}</p>
                  <p className="truncate text-sm text-suave">{u.email}</p>
                </div>
                <span className="shrink-0 rounded-full px-2.5 py-1 text-xs font-bold text-white" style={{ background: COR_PERFIL[u.perfil] ?? '#0ea5e9' }}>{PERFIS[u.perfil] ?? u.perfil}</span>
              </div>
              <p className="text-xs text-suave">{u.ativo ? 'Ativo' : 'Desativado'}{u.restrito ? ' · acesso só aos clientes vinculados' : ''} · último acesso {dataBR(u.ultimoLogin, true)}</p>
              <div className="mt-auto grid grid-cols-3 gap-2">
                <BotaoSecundario className="text-sky-500" onClick={() => { setAviso(''); setForm({ usuario_id: String(u.id), nome: u.nome, email: u.email, telefone: u.telefone ?? '', documento: u.documento ?? '', perfil: u.perfil, senha: '', acesso_restrito_por_cliente: u.restrito, acesso_dados_sensiveis: u.sensiveis }); }} aria-label={`Editar ${u.nome}`}><Pencil size={16} aria-hidden="true" /></BotaoSecundario>
                <BotaoSecundario className="text-amber-500" onClick={() => { setAviso(''); setSenha({ id: u.id, nome: u.nome, nova: '', confirmar: '' }); }} aria-label={`Trocar senha de ${u.nome}`}><KeyRound size={16} aria-hidden="true" /></BotaoSecundario>
                <BotaoSecundario onClick={() => void alternar(u)} aria-label={u.ativo ? `Desativar ${u.nome}` : `Reativar ${u.nome}`} className={u.ativo ? 'text-perigo' : 'text-sucesso'}><Power size={16} aria-hidden="true" /></BotaoSecundario>
              </div>
            </li>
          ))}
        </ul>
      )}

      {form && (
        <Modal titulo={form.usuario_id ? 'Editar usuário' : 'Novo usuário'} aoFechar={() => setForm(null)}>
          <form onSubmit={(e) => void salvar(e)} className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-2">
              <Campo rotulo="Nome *"><input required value={form.nome} onChange={(e) => setForm({ ...form, nome: e.target.value })} className={CLASSE_ENTRADA} /></Campo>
              <Campo rotulo="E-mail *"><input type="email" required value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} className={CLASSE_ENTRADA} /></Campo>
              <Campo rotulo="Telefone / WhatsApp"><input type="tel" value={form.telefone} onChange={(e) => setForm({ ...form, telefone: e.target.value })} className={CLASSE_ENTRADA} /></Campo>
              <Campo rotulo="CPF/RG"><input inputMode="numeric" value={form.documento} onChange={(e) => setForm({ ...form, documento: e.target.value })} className={CLASSE_ENTRADA} /></Campo>
              <Campo rotulo="Perfil">
                <select value={form.perfil} onChange={(e) => setForm({ ...form, perfil: e.target.value })} className={CLASSE_ENTRADA}>{Object.entries(PERFIS).map(([k, r]) => <option key={k} value={k}>{r}</option>)}</select>
              </Campo>
            </div>
            {!form.usuario_id && <CampoSenha rotulo="Senha inicial *" valor={form.senha} aoMudar={(v) => setForm({ ...form, senha: v })} dica="Mínimo 8 caracteres, com letra maiúscula, minúscula, número e símbolo." />}
            {form.usuario_id && (
              <label className="toque flex items-center gap-3 text-sm text-texto">
                <input type="checkbox" checked={form.acesso_restrito_por_cliente} onChange={(e) => setForm({ ...form, acesso_restrito_por_cliente: e.target.checked })} className="h-5 w-5" />
                Só enxerga os clientes vinculados a ele(a)
              </label>
            )}
            {form.usuario_id && (
              <label className="toque flex items-center gap-3 text-sm text-texto">
                <input type="checkbox" checked={form.acesso_dados_sensiveis} onChange={(e) => setForm({ ...form, acesso_dados_sensiveis: e.target.checked })} className="h-5 w-5" />
                Pode ver dados sensíveis (senhas, VPN, AnyDesk dos clientes)
              </label>
            )}
            <Aviso texto={aviso} erro />
            <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
              <BotaoSecundario onClick={() => setForm(null)}>Cancelar</BotaoSecundario>
              <BotaoPrimario type="submit" disabled={salvando}>{salvando ? 'Salvando…' : 'Salvar'}</BotaoPrimario>
            </div>
          </form>
        </Modal>
      )}

      {avatar && (
        <Modal titulo={`Foto de ${avatar.nome}`} aoFechar={() => setAvatar(null)}>
          <div className="space-y-4">
            <EscolherArquivo rotulo="Enviar uma foto" aceita="image/jpeg,image/png,image/webp" aoEscolher={(f) => { if (f[0]) void trocarAvatar({ acao: 'atualizar_avatar_usuario' }, f[0]); }} />
            <p className="text-sm font-semibold text-texto">Ou escolha um avatar:</p>
            <ul className="grid grid-cols-6 gap-2 sm:grid-cols-8">
              {Array.from({ length: 24 }, (_, i) => i + 1).map((n) => {
                const caminho = `assets/img/avatares/avatar-${n}.svg`;
                return (
                  <li key={n}>
                    <button type="button" disabled={salvando} onClick={() => void trocarAvatar({ acao: 'escolher_avatar_predefinido_usuario', avatar: String(n) })} aria-label={`Avatar ${n}`} aria-pressed={avatar.foto === caminho}
                      className={`aspect-square w-full overflow-hidden rounded-full border-2 transition hover:scale-110 ${avatar.foto === caminho ? 'border-primaria' : 'border-transparent'}`}>
                      <img src={`/${caminho}`} alt="" className="h-full w-full" loading="lazy" />
                    </button>
                  </li>
                );
              })}
            </ul>
            <Aviso texto={aviso} erro />
          </div>
        </Modal>
      )}

      {senha && (
        <Modal titulo={`Nova senha de ${senha.nome}`} aoFechar={() => setSenha(null)} largura="max-w-md">
          <form onSubmit={(e) => void definirSenha(e)} className="space-y-4">
            <CampoSenha rotulo="Nova senha *" valor={senha.nova} aoMudar={(v) => setSenha({ ...senha, nova: v })} dica="Mínimo 8 caracteres, com letra maiúscula, minúscula, número e símbolo." />
            <CampoSenha rotulo="Confirmar *" valor={senha.confirmar} aoMudar={(v) => setSenha({ ...senha, confirmar: v })} />
            <Aviso texto={aviso} erro />
            <BotaoPrimario type="submit" disabled={salvando} className="w-full">{salvando ? 'Salvando…' : 'Definir senha'}</BotaoPrimario>
          </form>
        </Modal>
      )}
    </Casca>
  );
}
