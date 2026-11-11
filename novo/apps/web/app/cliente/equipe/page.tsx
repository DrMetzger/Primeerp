'use client';

import type { EquipePortal } from '@primehub/shared';
import { Mail, Plus, UserX } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Casca } from '@/components/casca';
import { EsqueletoLista } from '@/components/esqueleto';
import { Aviso, BotaoPrimario, BotaoSecundario, CLASSE_ENTRADA, Campo, Modal, Painel, Vazio } from '@/components/ui/basico';
import { api, arquivoPublico, enviarAcao } from '@/lib/api';

export default function PaginaEquipeCliente() {
  const [dados, setDados] = useState<EquipePortal | null>(null);
  const [nova, setNova] = useState<{ nome: string; email: string; telefone: string; documento: string; eh_master: boolean; setores: number[] } | null>(null);
  const [novoSetor, setNovoSetor] = useState<string | null>(null);
  const [aviso, setAviso] = useState<{ texto: string; erro: boolean }>({ texto: '', erro: false });
  const [ocupado, setOcupado] = useState(false);

  const carregar = () => api<EquipePortal>('/api/portal/equipe').then(setDados).catch(() => setDados({ setores: [], pessoas: [], podeGerenciar: false }));
  useEffect(() => { void carregar(); }, []);

  async function agir(campos: Record<string, string>, extra: Record<string, string[]> = {}) {
    setOcupado(true); setAviso({ texto: '', erro: false });
    try {
      const corpo: Record<string, string> = { ...campos };
      Object.entries(extra).forEach(([k, l]) => l.forEach((v, i) => { corpo[`${k}[${i}]`] = v; }));
      const r = await enviarAcao('portal/equipe.php', corpo);
      setAviso({ texto: r.mensagens.sucesso ?? 'Pronto!', erro: false });
      setNova(null); setNovoSetor(null); void carregar();
    } catch (e) { setAviso({ texto: e instanceof Error ? e.message : 'Não deu certo.', erro: true }); }
    setOcupado(false);
  }

  const acoes = dados?.podeGerenciar ? (
    <button type="button" onClick={() => setNova({ nome: '', email: '', telefone: '', documento: '', eh_master: false, setores: [] })} className="gradiente-marca toque inline-flex items-center gap-2 rounded-xl px-4 font-semibold text-white shadow">
      <Plus size={20} aria-hidden="true" /> <span className="hidden sm:inline">Nova pessoa</span>
    </button>
  ) : undefined;

  return (
    <Casca tipo="cliente" titulo="Minha equipe" acoes={acoes}>
      {!dados ? <EsqueletoLista /> : (
        <div className="space-y-4">
          {!dados.podeGerenciar && <p className="text-sm text-suave">Para incluir ou alterar pessoas da sua equipe, peça para a Prime liberar esse recurso ou abra um chamado.</p>}
          {aviso.texto && <Aviso texto={aviso.texto} erro={aviso.erro} />}
          <Painel titulo="Setores" acao={dados.podeGerenciar ? <BotaoSecundario onClick={() => setNovoSetor('')}><Plus size={16} aria-hidden="true" /> Setor</BotaoSecundario> : undefined}>
            {!dados.setores.length ? <Vazio>Nenhum setor ainda.</Vazio> : (
              <ul className="flex flex-wrap gap-2">{dados.setores.map((s) => <li key={s.id} className={`rounded-full border border-borda px-3 py-1.5 text-sm font-semibold ${s.ativo ? 'text-texto' : 'text-suave line-through'}`}>{s.nome}</li>)}</ul>
            )}
          </Painel>
          {!dados.pessoas.length ? <Vazio>Nenhuma pessoa cadastrada.</Vazio> : (
            <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
              {dados.pessoas.map((p) => (
                <li key={p.id} className="flex flex-col gap-3 rounded-3xl border border-borda bg-superficie p-4 shadow-sm">
                  <div className="flex items-center gap-3">
                    {p.foto ? <img src={arquivoPublico(p.foto) ?? ''} alt="" className="h-12 w-12 rounded-full object-cover" />
                      : <span aria-hidden="true" className="gradiente-marca flex h-12 w-12 items-center justify-center rounded-full font-bold text-white">{p.nome.slice(0, 1)}</span>}
                    <div className="min-w-0 flex-1">
                      <p className="truncate font-semibold text-texto">{p.nome}</p>
                      <p className="truncate text-sm text-suave">{p.email ?? 'sem e-mail'}</p>
                    </div>
                    {p.master && <span className="rounded-full bg-primaria/10 px-2 py-0.5 text-xs font-semibold text-primaria">Master</span>}
                  </div>
                  <p className="text-xs text-suave">{p.setores.join(', ') || 'Sem setor'} · {p.portalAtivo ? 'acesso ativo' : 'sem acesso ao portal'}</p>
                  {dados.podeGerenciar && (
                    <div className="mt-auto grid grid-cols-2 gap-2">
                      <BotaoSecundario disabled={ocupado || !p.email} onClick={() => void agir({ acao: 'enviar_convite_setor', responsavel_id: String(p.id) })}><Mail size={16} aria-hidden="true" /> Convite</BotaoSecundario>
                      {p.portalAtivo && <BotaoSecundario disabled={ocupado} className="text-perigo" onClick={() => { if (confirm(`Tirar o acesso de ${p.nome}?`)) void agir({ acao: 'desativar_portal_setor', responsavel_id: String(p.id) }); }}><UserX size={16} aria-hidden="true" /> Tirar acesso</BotaoSecundario>}
                    </div>
                  )}
                </li>
              ))}
            </ul>
          )}
        </div>
      )}

      {nova && dados && (
        <Modal titulo="Nova pessoa da equipe" aoFechar={() => setNova(null)}>
          <form onSubmit={(e) => { e.preventDefault(); void agir({ acao: 'criar_usuario_cliente', nome: nova.nome, email: nova.email, telefone: nova.telefone, documento: nova.documento, ...(nova.eh_master ? { eh_master: '1' } : {}) }, { setor_ids: nova.setores.map(String) }); }} className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-2">
              <Campo rotulo="Nome *"><input required value={nova.nome} onChange={(e) => setNova({ ...nova, nome: e.target.value })} className={CLASSE_ENTRADA} /></Campo>
              <Campo rotulo="E-mail *"><input type="email" required value={nova.email} onChange={(e) => setNova({ ...nova, email: e.target.value })} className={CLASSE_ENTRADA} /></Campo>
              <Campo rotulo="Telefone / WhatsApp"><input type="tel" value={nova.telefone} onChange={(e) => setNova({ ...nova, telefone: e.target.value })} className={CLASSE_ENTRADA} /></Campo>
              <Campo rotulo="CPF/RG"><input inputMode="numeric" value={nova.documento} onChange={(e) => setNova({ ...nova, documento: e.target.value })} className={CLASSE_ENTRADA} /></Campo>
            </div>
            <fieldset className="space-y-2">
              <legend className="text-sm font-semibold text-texto">Setores</legend>
              <div className="flex flex-wrap gap-2">
                {dados.setores.map((s) => {
                  const marcado = nova.setores.includes(s.id);
                  return <button key={s.id} type="button" aria-pressed={marcado} onClick={() => setNova({ ...nova, setores: marcado ? nova.setores.filter((x) => x !== s.id) : [...nova.setores, s.id] })}
                    className={`toque rounded-full px-4 text-sm font-semibold ${marcado ? 'gradiente-marca text-white' : 'border border-borda text-suave'}`}>{s.nome}</button>;
                })}
              </div>
            </fieldset>
            <label className="toque flex items-center gap-3 text-sm text-texto"><input type="checkbox" checked={nova.eh_master} onChange={(e) => setNova({ ...nova, eh_master: e.target.checked })} className="h-5 w-5" />Vê os chamados de todos os setores (master)</label>
            {aviso.erro && <Aviso texto={aviso.texto} erro />}
            <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
              <BotaoSecundario onClick={() => setNova(null)}>Cancelar</BotaoSecundario>
              <BotaoPrimario type="submit" disabled={ocupado}>Cadastrar</BotaoPrimario>
            </div>
          </form>
        </Modal>
      )}
      {novoSetor !== null && (
        <Modal titulo="Novo setor" aoFechar={() => setNovoSetor(null)} largura="max-w-md">
          <form onSubmit={(e) => { e.preventDefault(); void agir({ acao: 'criar_setor', nome: novoSetor, icone: '' }); }} className="space-y-4">
            <Campo rotulo="Nome do setor *"><input required value={novoSetor} onChange={(e) => setNovoSetor(e.target.value)} className={CLASSE_ENTRADA} placeholder="Ex.: Financeiro" /></Campo>
            <BotaoPrimario type="submit" disabled={ocupado} className="w-full">Criar setor</BotaoPrimario>
          </form>
        </Modal>
      )}
    </Casca>
  );
}
