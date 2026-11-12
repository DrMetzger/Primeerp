import type { ClienteDetalhe } from '@primehub/shared';
import { useState } from 'react';
import { Pressable, Share, View } from 'react-native';
import { acaoPhp } from './api';
import type { ArquivoEscolhido } from './arquivos';
import { Esqueleto } from './Esqueleto';
import { FotoPessoa } from './FotoPessoa';
import { Acoes, Aviso, confirmar, Folha } from './formularios';
import { useTema } from './tema';
import { abrirArquivo, BotaoContorno, BotaoGradiente, dataBR, Entrada, Escolha, Vazio } from './Telas';
import { Cartao, SeletorAnexos, T } from './ui';

/** Seções do cliente no app com as mesmas ações do site (cliente.php / usuarios.php). */

const CORES = ['#0ea5e9', '#8b5cf6', '#10b981', '#f59e0b', '#f43f5e', '#06b6d4', '#84cc16', '#d946ef'];
type Agir = (arquivo: string, campos: Record<string, string>, arquivos?: Record<string, ArquivoEscolhido[]>, ok?: string) => Promise<boolean>;

function useAcao(recarregar: () => void) {
  const [ocupado, setOcupado] = useState(false);
  const [aviso, setAviso] = useState<{ t: string; e: boolean }>({ t: '', e: false });
  const agir: Agir = async (arquivo, campos, arquivos = {}, ok = 'Pronto!') => {
    setOcupado(true); setAviso({ t: '', e: false });
    try {
      const r = await acaoPhp<{ ok?: boolean; mensagens?: string[] | Record<string, string> }>(arquivo, campos, arquivos);
      const m = Array.isArray(r.mensagens) ? r.mensagens.join(' ') : r.mensagens?.sucesso;
      setAviso({ t: m || ok, e: false }); recarregar(); return true;
    } catch (e) { setAviso({ t: e instanceof Error ? e.message : 'Não deu certo agora.', e: true }); return false; }
    finally { setOcupado(false); }
  };
  return { agir, ocupado, aviso, setAviso };
}

// ---------------------------------------------------------------- Setores

export function SecaoSetoresApp({ id, dados, recarregar, aoAbrirSetor }: { id: number; dados: ClienteDetalhe; recarregar: () => void; aoAbrirSetor: (setorId: number) => void }) {
  const { tema } = useTema();
  const { agir, ocupado, aviso } = useAcao(recarregar);
  const [form, setForm] = useState<{ setor_id: string; nome: string; ativo: boolean } | null>(null);
  const salvar = () => form && void agir(`cliente.php?id=${id}`, { acao: form.setor_id ? 'atualizar_setor' : 'criar_setor', setor_id: form.setor_id, nome: form.nome, icone: '', ...(form.ativo ? { ativo: '1' } : {}) }, {}, 'Setor salvo.').then((ok) => ok && setForm(null));
  return (
    <>
      <BotaoGradiente titulo="＋ Novo setor" aoTocar={() => setForm({ setor_id: '', nome: '', ativo: true })} />
      {!form && <Aviso texto={aviso.t} erro={aviso.e} />}
      {!dados.setores.length ? <Vazio>Sem setores cadastrados.</Vazio> : dados.setores.map((s, i) => {
        const cor = CORES[i % CORES.length] ?? '#0ea5e9';
        return (
          <Cartao key={s.id} style={{ gap: 10, borderLeftWidth: 5, borderLeftColor: cor, opacity: s.ativo ? 1 : 0.6 }}>
            <Pressable onPress={() => aoAbrirSetor(s.id)} accessibilityRole="button" accessibilityLabel={`Setor ${s.nome}`} style={{ flexDirection: 'row', gap: 12, alignItems: 'center' }}>
              <View style={{ width: 44, height: 44, borderRadius: 14, backgroundColor: cor, alignItems: 'center', justifyContent: 'center' }}><T style={{ color: '#FFFFFF', fontWeight: '800' }}>{s.nome.slice(0, 2).toUpperCase()}</T></View>
              <View style={{ flex: 1 }}><T style={{ fontWeight: '700' }}>{s.nome}</T><T style={{ color: tema.textoSuave, fontSize: 14 }}>{s.ativo ? `${s.pendentes} pendente(s)` : 'Desativado'}</T></View>
              <T style={{ color: tema.primaria, fontSize: 22 }}>›</T>
            </Pressable>
            <Acoes itens={[
              { rotulo: '✏️ Renomear', aoTocar: () => setForm({ setor_id: String(s.id), nome: s.nome, ativo: s.ativo }) },
              { rotulo: '🗑️ Excluir', perigo: true, aoTocar: () => void confirmar('Excluir setor', `Excluir o setor "${s.nome}"? Os chamados ficam sem setor e as pessoas continuam cadastradas.`, 'Excluir').then((ok) => { if (ok) void agir(`cliente.php?id=${id}`, { acao: 'excluir_setor', setor_id: String(s.id) }, {}, 'Setor excluído.'); }) },
            ]} />
          </Cartao>
        );
      })}
      <Folha aberta={!!form} aoFechar={() => setForm(null)} titulo={form?.setor_id ? 'Editar setor' : 'Novo setor'}
        rodape={form && <><Aviso texto={aviso.e ? aviso.t : ''} erro /><BotaoGradiente titulo={ocupado ? 'Salvando…' : 'Salvar'} aoTocar={salvar} desativado={ocupado || !form.nome.trim()} /></>}>
        {form && (<>
          <Entrada rotulo="Nome do setor *" valor={form.nome} aoMudar={(v) => setForm({ ...form, nome: v })} dica="Ex.: Financeiro" />
          {!!form.setor_id && <Escolha rotulo="Situação" opcoes={[{ valor: 'sim', rotulo: 'Ativo' }, { valor: 'nao', rotulo: 'Desativado' }]} valor={form.ativo ? 'sim' : 'nao'} aoMudar={(v) => setForm({ ...form, ativo: v === 'sim' })} />}
        </>)}
      </Folha>
    </>
  );
}

// ---------------------------------------------------------------- Usuários do cliente

export function SecaoPessoasApp({ id, dados, recarregar }: { id: number; dados: ClienteDetalhe; recarregar: () => void }) {
  const { tema } = useTema();
  const { agir, ocupado, aviso } = useAcao(recarregar);
  const [form, setForm] = useState<{ responsavel_id: string; nome: string; email: string; telefone: string; documento: string; eh_master: boolean; setores: number[] } | null>(null);
  const url = `cliente.php?id=${id}`;
  function salvar() {
    if (!form) return;
    const c: Record<string, string> = { acao: form.responsavel_id ? 'editar_usuario_cliente' : 'criar_usuario_cliente', responsavel_id: form.responsavel_id, nome: form.nome, email: form.email, telefone: form.telefone, documento: form.documento };
    if (form.eh_master) c.eh_master = '1';
    form.setores.forEach((s, i) => { c[`setor_ids[${i}]`] = String(s); });
    void agir(url, c, {}, 'Usuário salvo.').then((ok) => ok && setForm(null));
  }
  return (
    <>
      <BotaoGradiente titulo="＋ Novo usuário" aoTocar={() => setForm({ responsavel_id: '', nome: '', email: '', telefone: '', documento: '', eh_master: false, setores: [] })} />
      {!form && <Aviso texto={aviso.t} erro={aviso.e} />}
      {!dados.pessoas.length ? <Vazio>Nenhum usuário cadastrado.</Vazio> : dados.pessoas.map((p) => (
        <Cartao key={p.id} style={{ gap: 10 }}>
          <View style={{ flexDirection: 'row', gap: 12, alignItems: 'center' }}>
            <FotoPessoa caminho={p.foto} nome={p.nome} tamanho={44} />
            <View style={{ flex: 1 }}>
              <T style={{ fontWeight: '700' }}>{p.nome}{p.master ? ' · Master' : ''}</T>
              <T style={{ color: tema.textoSuave, fontSize: 13 }}>{p.setor ?? 'Sem setor'}{p.email ? ` · ${p.email}` : ''}</T>
              <T style={{ color: p.portalAtivo ? tema.sucesso : tema.textoSuave, fontSize: 12, fontWeight: '700' }}>{p.portalAtivo ? '● portal ativo' : '○ sem acesso ao portal'}</T>
            </View>
          </View>
          <Acoes itens={[
            { rotulo: '✏️ Editar', aoTocar: () => setForm({ responsavel_id: String(p.id), nome: p.nome, email: p.email ?? '', telefone: p.telefone ?? '', documento: p.documento ?? '', eh_master: !!p.master, setores: p.setorIds ?? [] }) },
            ...(p.email ? [{ rotulo: '✉️ Convite', aoTocar: () => void agir(url, { acao: 'enviar_convite_setor', responsavel_id: String(p.id) }, {}, 'Convite enviado.') },
              { rotulo: '🔑 Nova senha', aoTocar: () => void confirmar('Nova senha', `Mandar para ${p.nome} um link de nova senha?`, 'Mandar', false).then((ok) => { if (ok) void agir(url, { acao: 'resetar_senha_setor', responsavel_id: String(p.id) }, {}, 'Link enviado.'); }) }] : []),
            p.portalAtivo
              ? { rotulo: '⛔ Tirar acesso', perigo: true, aoTocar: () => void confirmar('Tirar acesso', `Tirar o acesso de ${p.nome} ao portal?`, 'Tirar').then((ok) => { if (ok) void agir(url, { acao: 'desativar_portal_setor', responsavel_id: String(p.id) }, {}, 'Acesso retirado.'); }) }
              : { rotulo: '🗑️ Excluir', perigo: true, aoTocar: () => void confirmar('Excluir usuário', `Excluir ${p.nome}?`, 'Excluir').then((ok) => { if (ok) void agir(url, { acao: 'excluir_usuario_cliente', responsavel_id: String(p.id) }, {}, 'Usuário excluído.'); }) },
          ]} />
        </Cartao>
      ))}
      <Folha aberta={!!form} aoFechar={() => setForm(null)} titulo={form?.responsavel_id ? 'Editar usuário' : 'Novo usuário'}
        rodape={form && <><Aviso texto={aviso.e ? aviso.t : ''} erro /><BotaoGradiente titulo={ocupado ? 'Salvando…' : 'Salvar'} aoTocar={salvar} desativado={ocupado || !form.nome.trim()} /></>}>
        {form && (<>
          <Entrada rotulo="Nome *" valor={form.nome} aoMudar={(v) => setForm({ ...form, nome: v })} />
          <Entrada rotulo="E-mail (para acessar o portal)" valor={form.email} aoMudar={(v) => setForm({ ...form, email: v })} />
          <Entrada rotulo="Telefone / WhatsApp" valor={form.telefone} aoMudar={(v) => setForm({ ...form, telefone: v })} />
          <Entrada rotulo="CPF" valor={form.documento} aoMudar={(v) => setForm({ ...form, documento: v })} />
          <SetoresMarcaveis setores={dados.setores} marcados={form.setores} aoMudar={(x) => setForm({ ...form, setores: x })} rotulo="Setores" />
          <Escolha rotulo="Master (gerencia a equipe do cliente)?" opcoes={[{ valor: 'nao', rotulo: 'Não' }, { valor: 'sim', rotulo: 'Sim' }]} valor={form.eh_master ? 'sim' : 'nao'} aoMudar={(v) => setForm({ ...form, eh_master: v === 'sim' })} />
        </>)}
      </Folha>
    </>
  );
}

function SetoresMarcaveis({ setores, marcados, aoMudar, rotulo }: { setores: ClienteDetalhe['setores']; marcados: number[]; aoMudar: (x: number[]) => void; rotulo: string }) {
  const { tema } = useTema();
  return (
    <View style={{ gap: 6 }}>
      <T style={{ fontWeight: '700', fontSize: 14 }}>{rotulo}</T>
      <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8 }}>
        {setores.map((s, i) => {
          const m = marcados.includes(s.id);
          const cor = CORES[i % CORES.length] ?? '#0ea5e9';
          return (
            <Pressable key={s.id} onPress={() => aoMudar(m ? marcados.filter((x) => x !== s.id) : [...marcados, s.id])} accessibilityRole="checkbox" accessibilityState={{ checked: m }}
              style={{ minHeight: 42, borderRadius: 99, paddingHorizontal: 14, justifyContent: 'center', backgroundColor: m ? cor : tema.superficieAlta, borderWidth: 1, borderColor: m ? cor : tema.borda }}>
              <T style={{ fontSize: 14, fontWeight: '700', color: m ? '#fff' : tema.texto }}>{m ? '✓ ' : ''}{s.nome}</T>
            </Pressable>
          );
        })}
      </View>
    </View>
  );
}

// ---------------------------------------------------------------- Documentos

export function SecaoDocumentosApp({ id, dados, recarregar }: { id: number; dados: ClienteDetalhe; recarregar: () => void }) {
  const { tema } = useTema();
  const { agir, ocupado, aviso } = useAcao(recarregar);
  const [form, setForm] = useState<{ titulo: string; categoria: string; descricao: string; setor_id: string; arquivos: ArquivoEscolhido[] } | null>(null);
  return (
    <>
      <BotaoGradiente titulo="＋ Enviar documento" aoTocar={() => setForm({ titulo: '', categoria: 'Geral', descricao: '', setor_id: '', arquivos: [] })} />
      {!form && <Aviso texto={aviso.t} erro={aviso.e} />}
      {!dados.documentos.length ? <Vazio>Nenhum documento enviado.</Vazio> : dados.documentos.map((d) => (
        <Pressable key={d.id} onPress={() => void abrirArquivo(`/download.php?id=${d.id}`)} accessibilityRole="button">
          <Cartao style={{ flexDirection: 'row', gap: 12, alignItems: 'center' }}>
            <T style={{ fontSize: 24 }}>📄</T>
            <View style={{ flex: 1 }}><T style={{ fontWeight: '700' }}>{d.titulo}</T><T style={{ color: tema.textoSuave, fontSize: 13 }}>{[d.categoria, d.setor, dataBR(d.criadoEm)].filter(Boolean).join(' · ')}</T></View>
            <T style={{ color: tema.primaria, fontSize: 18 }}>⬇</T>
          </Cartao>
        </Pressable>
      ))}
      <Folha aberta={!!form} aoFechar={() => setForm(null)} titulo="Enviar documento"
        rodape={form && <><Aviso texto={aviso.e ? aviso.t : ''} erro /><BotaoGradiente titulo={ocupado ? 'Enviando…' : 'Enviar'} desativado={ocupado || !form.titulo.trim() || !form.arquivos.length}
          aoTocar={() => void agir(`cliente.php?id=${id}`, { acao: 'upload_documento', titulo: form.titulo, categoria: form.categoria, descricao: form.descricao, setor_id: form.setor_id }, { arquivo: form.arquivos.slice(0, 1) }, 'Documento enviado.').then((ok) => ok && setForm(null))} /></>}>
        {form && (<>
          <Entrada rotulo="Título *" valor={form.titulo} aoMudar={(v) => setForm({ ...form, titulo: v })} />
          <Entrada rotulo="Categoria" valor={form.categoria} aoMudar={(v) => setForm({ ...form, categoria: v })} />
          <Escolha rotulo="Setor" opcoes={[{ valor: '', rotulo: 'Cliente inteiro' }, ...dados.setores.map((s) => ({ valor: String(s.id), rotulo: s.nome }))]} valor={form.setor_id} aoMudar={(v) => setForm({ ...form, setor_id: v })} />
          <Entrada rotulo="Descrição" valor={form.descricao} aoMudar={(v) => setForm({ ...form, descricao: v })} linhas={3} />
          <SeletorAnexos rotulo="Arquivo *" varios={false} arquivos={form.arquivos} aoMudar={(a) => setForm({ ...form, arquivos: a })} />
        </>)}
      </Folha>
    </>
  );
}

// ---------------------------------------------------------------- Equipe Prime (vínculos)

export function SecaoEquipePrimeApp({ id, dados, recarregar }: { id: number; dados: ClienteDetalhe; recarregar: () => void }) {
  const { tema } = useTema();
  const { agir, ocupado, aviso } = useAcao(recarregar);
  const [form, setForm] = useState<{ usuario_id: string; setores: number[] } | null>(null);
  const porPessoa = new Map<number, ClienteDetalhe['vinculos']>();
  for (const v of dados.vinculos) porPessoa.set(v.usuarioId, [...(porPessoa.get(v.usuarioId) ?? []), v]);
  function vincular() {
    if (!form) return;
    const c: Record<string, string> = { acao: 'adicionar_vinculo_colaborador', usuario_id: form.usuario_id, cliente_id: String(id) };
    form.setores.forEach((s, i) => { c[`setor_ids[${i}]`] = String(s); });
    void agir('usuarios.php', c, {}, 'Usuário vinculado.').then((ok) => ok && setForm(null));
  }
  return (
    <>
      <T style={{ color: tema.textoSuave, fontSize: 14 }}>Quem da Prime atende este cliente. Quem tem acesso restrito passa a ver este cliente (ou só os setores escolhidos).</T>
      <BotaoGradiente titulo="＋ Vincular usuário da Prime" aoTocar={() => setForm({ usuario_id: '', setores: [] })} />
      {!form && <Aviso texto={aviso.t} erro={aviso.e} />}
      {!porPessoa.size ? <Vazio>Ninguém da equipe vinculado ainda.</Vazio> : [...porPessoa.values()].map((l) => {
        const p = l[0];
        if (!p) return null;
        return (
          <Cartao key={p.usuarioId} style={{ gap: 10 }}>
            <View style={{ flexDirection: 'row', gap: 12, alignItems: 'center' }}>
              <FotoPessoa caminho={p.foto} nome={p.nome} tamanho={46} />
              <View style={{ flex: 1 }}><T style={{ fontWeight: '700' }}>{p.nome}</T><T style={{ color: tema.textoSuave, fontSize: 12 }}>{p.restrito ? 'Acesso restrito aos vínculos' : 'Acesso a todos os clientes'}</T></View>
            </View>
            <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8 }}>
              {l.map((v) => (
                <Pressable key={v.id} disabled={ocupado} accessibilityRole="button" accessibilityLabel={`Remover ${v.setor ?? 'cliente inteiro'} de ${v.nome}`}
                  onPress={() => void confirmar('Remover vínculo', `Remover ${v.nome} de ${v.setor ?? 'todo o cliente'}?`, 'Remover').then((ok) => { if (ok) void agir('usuarios.php', { acao: 'remover_vinculo_colaborador', vinculo_id: String(v.id), usuario_id: String(v.usuarioId) }, {}, 'Vínculo removido.'); })}
                  style={{ flexDirection: 'row', alignItems: 'center', gap: 6, minHeight: 36, paddingLeft: 12, paddingRight: 8, borderRadius: 99, backgroundColor: `${tema.primaria}1F` }}>
                  <T style={{ color: tema.primaria, fontWeight: '700', fontSize: 13 }}>{v.setor ?? 'Cliente inteiro'}</T>
                  <T style={{ color: tema.perigo, fontWeight: '800' }}>✕</T>
                </Pressable>
              ))}
            </View>
          </Cartao>
        );
      })}
      <Folha aberta={!!form} aoFechar={() => setForm(null)} titulo="Vincular usuário da Prime"
        rodape={form && <><Aviso texto={aviso.e ? aviso.t : ''} erro /><BotaoGradiente titulo={ocupado ? 'Vinculando…' : 'Vincular'} aoTocar={vincular} desativado={ocupado || !form.usuario_id} /></>}>
        {form && (<>
          <Escolha rotulo="Usuário *" opcoes={dados.equipe.map((u) => ({ valor: String(u.id), rotulo: u.nome }))} valor={form.usuario_id} aoMudar={(v) => setForm({ ...form, usuario_id: v })} />
          <SetoresMarcaveis setores={dados.setores} marcados={form.setores} aoMudar={(x) => setForm({ ...form, setores: x })} rotulo="Setores (nenhum = cliente inteiro)" />
        </>)}
      </Folha>
    </>
  );
}

// ---------------------------------------------------------------- Risco de perda e melhorias (IA)

export function SecaoRiscoApp({ id, dados, recarregar }: { id: number; dados: ClienteDetalhe; recarregar: () => void }) {
  const { tema } = useTema();
  const { agir, aviso, setAviso } = useAcao(recarregar);
  const [setor, setSetor] = useState('');
  const [gerando, setGerando] = useState<'' | 'risco' | 'melhorias'>('');
  const [editando, setEditando] = useState<{ id: number; texto: string } | null>(null);
  const url = `cliente.php?id=${id}`;

  async function gerar(tipo: 'risco' | 'melhorias') {
    setGerando(tipo);
    setAviso({ t: tipo === 'risco' ? 'A IA está cruzando chamados, prazos, indicadores e o humor das conversas…' : 'A IA está procurando oportunidades de melhoria…', e: false });
    try {
      const j = await acaoPhp<{ ok: boolean; sucesso?: boolean; mensagem?: string }>(url, { acao: 'gerar_analise_risco', ajax: '1', tipo, setor_id: setor });
      setAviso({ t: j.sucesso ? 'Análise pronta!' : (j.mensagem ?? 'Não deu para gerar agora.'), e: !j.sucesso });
      recarregar();
    } catch { setAviso({ t: 'Não deu para gerar agora. Tente de novo em instantes.', e: true }); }
    setGerando('');
  }

  return (
    <>
      <Cartao style={{ gap: 12 }}>
        <Escolha rotulo="Escopo da análise" opcoes={[{ valor: '', rotulo: 'Cliente inteiro' }, ...dados.setores.map((s) => ({ valor: String(s.id), rotulo: s.nome }))]} valor={setor} aoMudar={setSetor} />
        <View style={{ flexDirection: 'row', gap: 8 }}>
          <View style={{ flex: 1 }}><BotaoGradiente titulo={gerando === 'risco' ? 'Analisando…' : '⚠️ Risco de perda'} desativado={!!gerando} aoTocar={() => void gerar('risco')} /></View>
          <View style={{ flex: 1 }}><BotaoContorno titulo={gerando === 'melhorias' ? 'Pensando…' : '💡 Melhorias'} desativado={!!gerando} aoTocar={() => void gerar('melhorias')} /></View>
        </View>
        {!!gerando && <Esqueleto altura={90} />}
        <Aviso texto={aviso.t} erro={aviso.e} />
      </Cartao>
      {!dados.analises.length ? <Vazio>Nenhuma análise ainda. Gere a primeira acima.</Vazio> : dados.analises.map((a) => {
        const cor = a.tipo === 'risco' ? '#f43f5e' : '#10b981';
        return (
          <Cartao key={a.id} style={{ gap: 10, borderLeftWidth: 5, borderLeftColor: cor }}>
            <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }}>
              <View style={{ backgroundColor: cor, borderRadius: 99, paddingHorizontal: 10, paddingVertical: 3 }}><T style={{ color: '#fff', fontSize: 12, fontWeight: '800' }}>{a.tipo === 'risco' ? '⚠️ Risco de perda' : '💡 Melhorias'}</T></View>
              <T style={{ flex: 1, color: tema.textoSuave, fontSize: 12 }} numberOfLines={1}>{[a.setor ?? 'Cliente inteiro', dataBR(a.atualizadoEm ?? a.criadoEm, true), a.autor].filter(Boolean).join(' · ')}</T>
            </View>
            <T selectable style={{ fontSize: 15, lineHeight: 22 }}>{a.texto.replace(/\*\*/g, '').replace(/^#+\s*/gm, '')}</T>
            <Acoes itens={[
              { rotulo: '✏️ Editar', aoTocar: () => setEditando({ id: a.id, texto: a.texto }) },
              { rotulo: '📤 Compartilhar', aoTocar: () => void Share.share({ title: `${a.tipo === 'risco' ? 'Análise de risco' : 'Sugestões de melhoria'} - ${dados.cliente.nome}`, message: a.texto.replace(/\*\*/g, '') }) },
              { rotulo: '🗑️ Excluir', perigo: true, aoTocar: () => void confirmar('Excluir análise', 'Excluir esta análise?', 'Excluir').then((ok) => { if (ok) void agir(url, { acao: 'excluir_analise_risco', id: String(a.id) }, {}, 'Análise excluída.'); }) },
            ]} />
          </Cartao>
        );
      })}
      <Folha aberta={!!editando} aoFechar={() => setEditando(null)} titulo="Editar análise"
        rodape={editando && <BotaoGradiente titulo="Salvar" aoTocar={() => void agir(url, { acao: 'atualizar_analise_risco', id: String(editando.id), texto: editando.texto }, {}, 'Análise atualizada.').then((ok) => ok && setEditando(null))} />}>
        {editando && <Entrada rotulo="Texto" valor={editando.texto} aoMudar={(v) => setEditando({ ...editando, texto: v })} linhas={14} />}
      </Folha>
    </>
  );
}
