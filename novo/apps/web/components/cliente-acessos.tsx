'use client';

import type { CofreCliente } from '@primehub/shared';
import { Check, Code2, Copy, Download, Eye, EyeOff, ExternalLink, KeyRound, Lock, Pencil, Plus, Server, ShieldAlert, Trash2, Wifi } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { Aviso, BotaoPrimario, BotaoSecundario, CLASSE_AREA, CLASSE_ENTRADA, Campo, EscolherArquivo, Modal, Vazio, dataBR, tamanhoArquivo } from './ui/basico';
import { CampoSenha } from './ui/campo-senha';
import { Esqueleto } from './esqueleto';
import { api, ErroApi } from '@/lib/api';

/**
 * Aba "Acessos" do cliente: cofre de senhas (servidor, RDP, AnyDesk, VPN,
 * Protheus, SQL, T-Cloud), arquivos de VPN, links do Protheus/T-Cloud por
 * ambiente e acessos pelo VSCode. Tudo no sistema novo; a senha só aparece
 * quando a pessoa clica em "Mostrar" (e isso fica registrado).
 */

type Cred = CofreCliente['credenciais'][number];
type Vpn = CofreCliente['vpn'][number];

const COR_TIPO: Record<string, string> = { servidor: '#0ea5e9', rdp: '#6366f1', anydesk: '#ef4444', vpn: '#10b981', protheus: '#f59e0b', sql_server: '#8b5cf6', t_cloud: '#06b6d4', outro: '#64748b' };
const COR_AMBIENTE: Record<string, string> = { dev: '#8b5cf6', teste: '#f59e0b', homologacao: '#0ea5e9', producao: '#10b981' };
/** Garante http:// quando o link foi salvo sem esquema (ex.: 192.168.1.4:8000),
 *  senão o navegador trata como caminho do próprio site. */
const comEsquema = (u: string) => { const v = (u ?? '').trim(); return !v || /^[a-z][a-z0-9+.-]*:\/\//i.test(v) ? v : `http://${v}`; };
const LINKS: [keyof CofreCliente['tcloud'][number], string, string][] = [
  ['linkProtheus', 'link_protheus', 'Protheus'], ['linkRest', 'link_rest', 'REST'], ['linkSchedule', 'link_schedule', 'Schedule'], ['linkExclusivo', 'link_exclusivo', 'Modo exclusivo'],
];

function Copiar({ texto, rotulo }: { texto: string; rotulo: string }) {
  const [ok, setOk] = useState(false);
  return (
    <button type="button" aria-label={`Copiar ${rotulo}`} title={`Copiar ${rotulo}`}
      onClick={() => { void navigator.clipboard.writeText(texto).then(() => { setOk(true); setTimeout(() => setOk(false), 1500); }); }}
      className="toque inline-flex items-center justify-center rounded-lg text-suave hover:bg-superficie-alta hover:text-primaria">
      {ok ? <Check size={16} className="text-sucesso" /> : <Copy size={16} />}
    </button>
  );
}

function Linha({ rotulo, valor, segredo }: { rotulo: string; valor: string | null; segredo?: React.ReactNode }) {
  if (!valor && !segredo) return null;
  return (
    <div className="flex min-w-0 items-center gap-2 rounded-xl bg-superficie-alta/60 px-3 py-1.5 text-sm">
      <span className="w-16 shrink-0 text-xs font-semibold uppercase text-suave">{rotulo}</span>
      {segredo ?? <><span className="min-w-0 flex-1 truncate font-mono text-texto" title={valor ?? ''}>{valor}</span><Copiar texto={valor ?? ''} rotulo={rotulo} /></>}
    </div>
  );
}

function Senha({ clienteId, c }: { clienteId: number; c: Cred }) {
  const [senha, setSenha] = useState<string | null>(null);
  const [erro, setErro] = useState('');
  const [carregando, setCarregando] = useState(false);
  async function mostrar() {
    if (senha !== null) { setSenha(null); return; }
    setCarregando(true); setErro('');
    try { setSenha((await api<{ senha: string }>(`/api/clientes/${clienteId}/acessos/credenciais/${c.id}/senha`, { method: 'POST', body: '{}' })).senha); }
    catch (e) { setErro(e instanceof ErroApi ? e.message : 'Não deu para mostrar.'); }
    setCarregando(false);
  }
  // Some sozinha depois de 30 s (não fica aberta na tela).
  useEffect(() => { if (senha === null) return; const t = setTimeout(() => setSenha(null), 30_000); return () => clearTimeout(t); }, [senha]);
  if (!c.temSenha) return <span className="flex-1 text-suave">sem senha cadastrada</span>;
  return (
    <>
      <span className="min-w-0 flex-1 truncate font-mono text-texto">{senha ?? '••••••••••'}</span>
      {erro && <span className="text-xs text-perigo">{erro}</span>}
      {senha !== null && <Copiar texto={senha} rotulo="senha" />}
      <button type="button" onClick={() => void mostrar()} disabled={carregando} aria-label={senha === null ? 'Mostrar senha' : 'Esconder senha'}
        className="toque inline-flex items-center gap-1 rounded-lg px-2 text-xs font-semibold text-primaria hover:bg-primaria/10">
        {senha === null ? <><Eye size={15} /> Mostrar</> : <><EyeOff size={15} /> Esconder</>}
      </button>
    </>
  );
}

type FormCred = { id: number | null; tipo: string; titulo: string; host: string; porta: string; usuario: string; senha: string; observacoes: string; temSenha: boolean };
type FormVpn = { id: number | null; titulo: string; arquivo: File | null; nomeAtual: string };
type FormTc = { tipo: string; link_protheus: string; link_rest: string; link_schedule: string; link_exclusivo: string };
type FormVs = { id: number | null; titulo: string; link: string; porta: string; ambiente: string };

export function SecaoAcessos({ clienteId }: { clienteId: number }) {
  const [d, setD] = useState<CofreCliente | null>(null);
  const [erro, setErro] = useState('');
  const [aviso, setAviso] = useState<{ t: string; e: boolean }>({ t: '', e: false });
  const [ocupado, setOcupado] = useState(false);
  const [cred, setCred] = useState<FormCred | null>(null);
  const [vpn, setVpn] = useState<FormVpn | null>(null);
  const [tc, setTc] = useState<FormTc | null>(null);
  const [vs, setVs] = useState<FormVs | null>(null);
  const base = `/api/clientes/${clienteId}/acessos`;

  const carregar = useCallback(() => api<CofreCliente>(base).then(setD).catch((e: unknown) => setErro(e instanceof Error ? e.message : 'Não consegui abrir os acessos.')), [base]);
  useEffect(() => { void carregar(); }, [carregar]);

  async function agir(f: () => Promise<{ mensagem?: string }>, fechar: () => void): Promise<void> {
    setOcupado(true); setAviso({ t: '', e: false });
    try { const r = await f(); setAviso({ t: r.mensagem ?? 'Pronto!', e: false }); fechar(); await carregar(); }
    catch (e) { setAviso({ t: e instanceof Error ? e.message : 'Não deu certo.', e: true }); }
    setOcupado(false);
  }
  const json = (metodo: string, url: string, corpo?: object) => api<{ mensagem?: string }>(url, { method: metodo, body: JSON.stringify(corpo ?? {}) });
  const excluir = (url: string, nome: string) => { if (confirm(`Excluir "${nome}"?`)) void agir(() => json('DELETE', url), () => undefined); };

  async function enviarVpn() {
    if (!vpn) return;
    const corpo = new FormData();
    if (vpn.id) corpo.append('id', String(vpn.id));
    corpo.append('titulo', vpn.titulo);
    if (vpn.arquivo) corpo.append('arquivo', vpn.arquivo);
    await agir(async () => {
      const r = await fetch(`${base}/vpn`, { method: 'POST', body: corpo, credentials: 'same-origin' });
      const j = (await r.json().catch(() => ({}))) as { mensagem?: string; erro?: string };
      if (!r.ok) throw new Error(j.erro ?? 'Não deu para enviar.');
      return j;
    }, () => setVpn(null));
  }
  async function baixarVpn(v: Vpn) {
    try { const { url } = await api<{ url: string }>(`${base}/vpn/${v.id}/link`, { method: 'POST', body: '{}' }); location.href = url; }
    catch (e) { setAviso({ t: e instanceof Error ? e.message : 'Não deu para baixar.', e: true }); }
  }

  if (erro) return <Vazio>{erro}</Vazio>;
  if (!d) return <div className="space-y-3"><Esqueleto className="h-24" /><Esqueleto className="h-40" /></div>;
  if (!d.podeVer) {
    return (
      <div className="flex items-start gap-3 rounded-3xl border border-alerta/40 bg-alerta/10 p-5">
        <ShieldAlert className="mt-0.5 shrink-0 text-alerta" size={22} aria-hidden="true" />
        <div><p className="font-semibold text-texto">Área restrita</p><p className="text-sm text-suave">Aqui ficam senhas e VPN do cliente. Peça a um administrador para liberar o acesso a dados sensíveis no seu usuário.</p></div>
      </div>
    );
  }
  const pode = (meu: boolean) => d.veTudo || meu;

  return (
    <div className="space-y-6">
      {!d.veTudo && <p className="flex items-center gap-2 rounded-2xl bg-superficie-alta px-4 py-2 text-sm text-suave"><Lock size={15} aria-hidden="true" /> Você vê e edita só os acessos que você mesmo cadastrou.</p>}
      {aviso.t && !cred && !vpn && !tc && !vs && <Aviso texto={aviso.t} erro={aviso.e} />}

      {/* Links do Protheus / T-Cloud */}
      <section className="space-y-3">
        <h2 className="flex items-center gap-2 text-lg font-bold text-texto"><ExternalLink size={20} className="text-cyan-500" aria-hidden="true" /> Links do Protheus (T-Cloud)</h2>
        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
          {d.tcloud.map((a) => {
            const cor = COR_AMBIENTE[a.tipo] ?? '#0ea5e9';
            const algum = LINKS.some(([k]) => a[k]);
            return (
              <div key={a.tipo} className="flex flex-col gap-3 rounded-3xl border border-borda bg-superficie p-4 shadow-sm" style={{ borderTop: `4px solid ${cor}` }}>
                <div className="flex items-center gap-2">
                  <span className="h-2.5 w-2.5 rounded-full" style={{ background: cor }} aria-hidden="true" />
                  <p className="flex-1 font-semibold text-texto">{d.ambientesTcloud[a.tipo]}</p>
                  <button type="button" aria-label={`Editar links de ${d.ambientesTcloud[a.tipo]}`} onClick={() => setTc({ tipo: a.tipo, link_protheus: a.linkProtheus ?? '', link_rest: a.linkRest ?? '', link_schedule: a.linkSchedule ?? '', link_exclusivo: a.linkExclusivo ?? '' })}
                    className="toque inline-flex items-center justify-center rounded-xl text-suave hover:bg-superficie-alta hover:text-primaria"><Pencil size={16} /></button>
                </div>
                {algum ? (
                  <div className="space-y-1.5">
                    {LINKS.filter(([k]) => a[k]).map(([k, , r]) => (
                      <div key={k} className="flex items-center gap-1">
                        <a href={comEsquema(String(a[k]))} target="_blank" rel="noopener noreferrer" className="toque inline-flex min-w-0 flex-1 items-center gap-2 rounded-xl px-3 text-sm font-semibold text-white" style={{ background: cor }}>
                          <ExternalLink size={15} aria-hidden="true" /><span className="truncate">{r}</span>
                        </a>
                        <Copiar texto={String(a[k])} rotulo={`link ${r}`} />
                      </div>
                    ))}
                  </div>
                ) : <p className="text-sm text-suave">Nenhum link ainda.</p>}
              </div>
            );
          })}
        </div>
      </section>

      {/* Credenciais */}
      <section className="space-y-3">
        <div className="flex items-center gap-3">
          <h2 className="flex flex-1 items-center gap-2 text-lg font-bold text-texto"><KeyRound size={20} className="text-amber-500" aria-hidden="true" /> Senhas e acessos</h2>
          <BotaoPrimario onClick={() => setCred({ id: null, tipo: 'servidor', titulo: '', host: '', porta: '', usuario: '', senha: '', observacoes: '', temSenha: false })}><Plus size={18} aria-hidden="true" /> Acesso</BotaoPrimario>
        </div>
        {!d.credenciais.length ? <Vazio>Nenhum acesso cadastrado.</Vazio> : (
          <ul className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            {d.credenciais.map((c) => {
              const cor = COR_TIPO[c.tipo] ?? '#64748b';
              return (
                <li key={c.id} className="space-y-2 rounded-3xl border border-borda bg-superficie p-4 shadow-sm">
                  <div className="flex items-start gap-3">
                    <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl text-white" style={{ background: cor }}><Server size={18} aria-hidden="true" /></span>
                    <div className="min-w-0 flex-1">
                      <p className="truncate font-semibold text-texto">{c.titulo}</p>
                      <p className="text-xs text-suave"><span className="font-bold" style={{ color: cor }}>{d.tipos[c.tipo] ?? c.tipo}</span>{c.autor ? ` · por ${c.autor}` : ''} · {dataBR(c.atualizadoEm)}</p>
                    </div>
                    {pode(c.meu) && (
                      <div className="flex">
                        <button type="button" aria-label={`Editar ${c.titulo}`} onClick={() => setCred({ id: c.id, tipo: c.tipo, titulo: c.titulo, host: c.host ?? '', porta: c.porta ?? '', usuario: c.usuario ?? '', senha: '', observacoes: c.observacoes ?? '', temSenha: c.temSenha })}
                          className="toque inline-flex items-center justify-center rounded-xl text-suave hover:bg-superficie-alta hover:text-primaria"><Pencil size={16} /></button>
                        <button type="button" aria-label={`Excluir ${c.titulo}`} onClick={() => excluir(`${base}/credenciais/${c.id}`, c.titulo)}
                          className="toque inline-flex items-center justify-center rounded-xl text-suave hover:bg-perigo/10 hover:text-perigo"><Trash2 size={16} /></button>
                      </div>
                    )}
                  </div>
                  <Linha rotulo="Host" valor={c.host && c.porta ? `${c.host}:${c.porta}` : c.host ?? c.porta} />
                  <Linha rotulo="Usuário" valor={c.usuario} />
                  <Linha rotulo="Senha" valor={null} segredo={<Senha clienteId={clienteId} c={c} />} />
                  {c.observacoes && <p className="whitespace-pre-wrap rounded-xl bg-superficie-alta/40 px-3 py-2 text-sm text-suave">{c.observacoes}</p>}
                </li>
              );
            })}
          </ul>
        )}
      </section>

      {/* VPN */}
      <section className="space-y-3">
        <div className="flex items-center gap-3">
          <h2 className="flex flex-1 items-center gap-2 text-lg font-bold text-texto"><Wifi size={20} className="text-emerald-500" aria-hidden="true" /> Arquivos de VPN</h2>
          <BotaoPrimario onClick={() => setVpn({ id: null, titulo: '', arquivo: null, nomeAtual: '' })}><Plus size={18} aria-hidden="true" /> Arquivo</BotaoPrimario>
        </div>
        {!d.vpn.length ? <Vazio>Nenhum arquivo de VPN.</Vazio> : (
          <ul className="grid gap-3 md:grid-cols-2">
            {d.vpn.map((v) => (
              <li key={v.id} className="flex items-center gap-3 rounded-3xl border border-borda bg-superficie p-4 shadow-sm">
                <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-emerald-500 text-white"><Wifi size={18} aria-hidden="true" /></span>
                <div className="min-w-0 flex-1">
                  <p className="truncate font-semibold text-texto">{v.titulo}</p>
                  <p className="truncate text-xs text-suave">{v.arquivo} · {tamanhoArquivo(v.tamanho)}{v.autor ? ` · por ${v.autor}` : ''}</p>
                </div>
                <BotaoSecundario onClick={() => void baixarVpn(v)} aria-label={`Baixar ${v.titulo}`}><Download size={16} aria-hidden="true" /> Baixar</BotaoSecundario>
                {pode(v.meu) && <>
                  <button type="button" aria-label={`Editar ${v.titulo}`} onClick={() => setVpn({ id: v.id, titulo: v.titulo, arquivo: null, nomeAtual: v.arquivo })} className="toque inline-flex items-center justify-center rounded-xl text-suave hover:bg-superficie-alta hover:text-primaria"><Pencil size={16} /></button>
                  <button type="button" aria-label={`Excluir ${v.titulo}`} onClick={() => excluir(`${base}/vpn/${v.id}`, v.titulo)} className="toque inline-flex items-center justify-center rounded-xl text-suave hover:bg-perigo/10 hover:text-perigo"><Trash2 size={16} /></button>
                </>}
              </li>
            ))}
          </ul>
        )}
      </section>

      {/* VSCode */}
      <section className="space-y-3">
        <div className="flex items-center gap-3">
          <h2 className="flex flex-1 items-center gap-2 text-lg font-bold text-texto"><Code2 size={20} className="text-sky-500" aria-hidden="true" /> Acesso pelo VSCode (T-Cloud)</h2>
          <BotaoPrimario onClick={() => setVs({ id: null, titulo: '', link: '', porta: '', ambiente: '' })}><Plus size={18} aria-hidden="true" /> VSCode</BotaoPrimario>
        </div>
        {!d.vscode.length ? <Vazio>Nenhum acesso pelo VSCode.</Vazio> : (
          <ul className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            {d.vscode.map((v) => (
              <li key={v.id} className="space-y-2 rounded-3xl border border-borda bg-superficie p-4 shadow-sm">
                <div className="flex items-center gap-3">
                  <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-sky-500 text-white"><Code2 size={18} aria-hidden="true" /></span>
                  <p className="min-w-0 flex-1 truncate font-semibold text-texto">{v.titulo}</p>
                  {pode(v.meu) && <>
                    <button type="button" aria-label={`Editar ${v.titulo}`} onClick={() => setVs({ id: v.id, titulo: v.titulo, link: v.link ?? '', porta: v.porta ?? '', ambiente: v.ambiente ?? '' })} className="toque inline-flex items-center justify-center rounded-xl text-suave hover:bg-superficie-alta hover:text-primaria"><Pencil size={16} /></button>
                    <button type="button" aria-label={`Excluir ${v.titulo}`} onClick={() => excluir(`${base}/vscode/${v.id}`, v.titulo)} className="toque inline-flex items-center justify-center rounded-xl text-suave hover:bg-perigo/10 hover:text-perigo"><Trash2 size={16} /></button>
                  </>}
                </div>
                <Linha rotulo="Link" valor={v.link} />
                <Linha rotulo="Porta" valor={v.porta} />
                <Linha rotulo="Ambiente" valor={v.ambiente} />
              </li>
            ))}
          </ul>
        )}
      </section>

      {cred && (
        <Modal titulo={cred.id ? 'Editar acesso' : 'Novo acesso'} aoFechar={() => setCred(null)} largura="max-w-lg">
          <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); const { id, temSenha: _t, ...corpo } = cred; void _t; void agir(() => json(id ? 'PUT' : 'POST', id ? `${base}/credenciais/${id}` : `${base}/credenciais`, corpo), () => setCred(null)); }}>
            <Campo rotulo="Tipo">
              <div className="flex flex-wrap gap-2">
                {Object.entries(d.tipos).map(([k, r]) => (
                  <button key={k} type="button" aria-pressed={cred.tipo === k} onClick={() => setCred({ ...cred, tipo: k })} className="toque rounded-full border px-3 text-sm font-semibold"
                    style={cred.tipo === k ? { background: COR_TIPO[k], borderColor: COR_TIPO[k], color: '#fff' } : { borderColor: 'var(--borda)', color: 'var(--textoSuave)' }}>{r}</button>
                ))}
              </div>
            </Campo>
            <Campo rotulo="Título *"><input required value={cred.titulo} onChange={(e) => setCred({ ...cred, titulo: e.target.value })} className={CLASSE_ENTRADA} placeholder="Ex.: Servidor de aplicação" /></Campo>
            <div className="grid grid-cols-[1fr_110px] gap-3">
              <Campo rotulo="Host / IP / ID"><input value={cred.host} onChange={(e) => setCred({ ...cred, host: e.target.value })} className={CLASSE_ENTRADA} autoComplete="off" /></Campo>
              <Campo rotulo="Porta"><input value={cred.porta} onChange={(e) => setCred({ ...cred, porta: e.target.value })} className={CLASSE_ENTRADA} inputMode="numeric" /></Campo>
            </div>
            <Campo rotulo="Usuário"><input value={cred.usuario} onChange={(e) => setCred({ ...cred, usuario: e.target.value })} className={CLASSE_ENTRADA} autoComplete="off" /></Campo>
            <CampoSenha rotulo={cred.id && cred.temSenha ? 'Senha (deixe em branco para manter a atual)' : 'Senha'} valor={cred.senha} aoMudar={(v) => setCred({ ...cred, senha: v })} autoComplete="new-password" />
            <Campo rotulo="Observações"><textarea rows={3} value={cred.observacoes} onChange={(e) => setCred({ ...cred, observacoes: e.target.value })} className={CLASSE_AREA} /></Campo>
            <p className="flex items-center gap-2 text-xs text-suave"><Lock size={13} aria-hidden="true" /> A senha é guardada cifrada. Cada vez que alguém clica em "Mostrar", fica registrado.</p>
            {aviso.e && <Aviso texto={aviso.t} erro />}
            <div className="flex justify-end gap-3"><BotaoSecundario onClick={() => setCred(null)}>Cancelar</BotaoSecundario><BotaoPrimario type="submit" disabled={ocupado}>{ocupado ? 'Salvando…' : 'Salvar'}</BotaoPrimario></div>
          </form>
        </Modal>
      )}
      {vpn && (
        <Modal titulo={vpn.id ? 'Editar arquivo de VPN' : 'Novo arquivo de VPN'} aoFechar={() => setVpn(null)} largura="max-w-lg">
          <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); void enviarVpn(); }}>
            <Campo rotulo="Título *"><input required value={vpn.titulo} onChange={(e) => setVpn({ ...vpn, titulo: e.target.value })} className={CLASSE_ENTRADA} placeholder="Ex.: VPN FortiClient" /></Campo>
            <EscolherArquivo rotulo={vpn.id ? `Trocar o arquivo (atual: ${vpn.nomeAtual})` : 'Arquivo *'} aoEscolher={(f) => setVpn({ ...vpn, arquivo: f[0] ?? null })} nomes={vpn.arquivo ? [vpn.arquivo.name] : []} />
            {aviso.e && <Aviso texto={aviso.t} erro />}
            <div className="flex justify-end gap-3"><BotaoSecundario onClick={() => setVpn(null)}>Cancelar</BotaoSecundario><BotaoPrimario type="submit" disabled={ocupado || (!vpn.id && !vpn.arquivo)}>{ocupado ? 'Enviando…' : 'Salvar'}</BotaoPrimario></div>
          </form>
        </Modal>
      )}
      {tc && (
        <Modal titulo={`Links de ${d.ambientesTcloud[tc.tipo]}`} aoFechar={() => setTc(null)} largura="max-w-lg">
          <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); const { tipo, ...corpo } = tc; void agir(() => json('PUT', `${base}/tcloud/${tipo}`, corpo), () => setTc(null)); }}>
            {LINKS.map(([, campo, r]) => (
              <Campo key={campo} rotulo={`Link ${r}`}><input type="url" value={tc[campo as keyof FormTc]} onChange={(e) => setTc({ ...tc, [campo]: e.target.value })} className={CLASSE_ENTRADA} placeholder="https://" /></Campo>
            ))}
            {aviso.e && <Aviso texto={aviso.t} erro />}
            <div className="flex justify-end gap-3"><BotaoSecundario onClick={() => setTc(null)}>Cancelar</BotaoSecundario><BotaoPrimario type="submit" disabled={ocupado}>{ocupado ? 'Salvando…' : 'Salvar'}</BotaoPrimario></div>
          </form>
        </Modal>
      )}
      {vs && (
        <Modal titulo={vs.id ? 'Editar acesso pelo VSCode' : 'Novo acesso pelo VSCode'} aoFechar={() => setVs(null)} largura="max-w-lg">
          <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); const { id, ...corpo } = vs; void agir(() => json(id ? 'PUT' : 'POST', id ? `${base}/vscode/${id}` : `${base}/vscode`, corpo), () => setVs(null)); }}>
            <Campo rotulo="Título *"><input required value={vs.titulo} onChange={(e) => setVs({ ...vs, titulo: e.target.value })} className={CLASSE_ENTRADA} /></Campo>
            <Campo rotulo="Link"><input value={vs.link} onChange={(e) => setVs({ ...vs, link: e.target.value })} className={CLASSE_ENTRADA} /></Campo>
            <div className="grid grid-cols-2 gap-3">
              <Campo rotulo="Porta"><input value={vs.porta} onChange={(e) => setVs({ ...vs, porta: e.target.value })} className={CLASSE_ENTRADA} inputMode="numeric" /></Campo>
              <Campo rotulo="Ambiente"><input value={vs.ambiente} onChange={(e) => setVs({ ...vs, ambiente: e.target.value })} className={CLASSE_ENTRADA} /></Campo>
            </div>
            {aviso.e && <Aviso texto={aviso.t} erro />}
            <div className="flex justify-end gap-3"><BotaoSecundario onClick={() => setVs(null)}>Cancelar</BotaoSecundario><BotaoPrimario type="submit" disabled={ocupado}>{ocupado ? 'Salvando…' : 'Salvar'}</BotaoPrimario></div>
          </form>
        </Modal>
      )}
    </div>
  );
}

/** Botões rápidos "Abrir Protheus" na visão geral (só para quem pode ver o cofre). */
export function AtalhosProtheus({ clienteId, aoVerTudo }: { clienteId: number; aoVerTudo: () => void }) {
  const [d, setD] = useState<CofreCliente | null>(null);
  useEffect(() => { api<CofreCliente>(`/api/clientes/${clienteId}/acessos`).then(setD).catch(() => setD(null)); }, [clienteId]);
  if (!d?.podeVer) return null;
  const links = d.tcloud.flatMap((a) => (a.linkProtheus ? [{ tipo: a.tipo, url: a.linkProtheus }] : []));
  return (
    <div className="flex flex-wrap items-center gap-2 rounded-3xl border border-borda bg-superficie p-3 shadow-sm">
      <span className="px-2 text-sm font-semibold text-suave">Protheus:</span>
      {links.length ? links.map((l) => (
        <a key={l.tipo} href={comEsquema(l.url)} target="_blank" rel="noopener noreferrer" className="toque inline-flex items-center gap-2 rounded-xl px-4 text-sm font-semibold text-white shadow" style={{ background: COR_AMBIENTE[l.tipo] ?? '#0ea5e9' }}>
          <ExternalLink size={15} aria-hidden="true" /> {d.ambientesTcloud[l.tipo]}
        </a>
      )) : <span className="text-sm text-suave">nenhum link cadastrado</span>}
      <button type="button" onClick={aoVerTudo} className="toque ml-auto inline-flex items-center gap-2 rounded-xl px-3 text-sm font-semibold text-primaria hover:bg-primaria/10">
        <KeyRound size={15} aria-hidden="true" /> Senhas e VPN ({d.credenciais.length + d.vpn.length})
      </button>
    </div>
  );
}
