import type { EquipePortal, EventoCliente, FonteArquivo, Perfil, UsuarioEquipe } from '@primehub/shared';
import { type ReactNode, useCallback, useEffect, useState } from 'react';
import { Image, Linking, Pressable, RefreshControl, ScrollView, TextInput, View } from 'react-native';
import { acaoPhp, api, arquivoPublico, ErroApi, lerJsonPhp } from './api';
import { Esqueleto } from './Esqueleto';
import { useTema } from './tema';
import { abrirArquivo, BotaoContorno, BotaoGradiente, dataBR, Entrada, Escolha, Vazio } from './Telas';
import { FotoPessoa } from './FotoPessoa';
import { Cartao, SeletorAnexos, T } from './ui';
import { escolherDaGaleria, tirarFoto, type ArquivoEscolhido } from './arquivos';
import { Acoes, Aviso, confirmar, Folha } from './formularios';
export { FotoPessoa };


type Resp = { ok: boolean; mensagens?: Record<string, string>; erro?: string | null };
const acao = (arquivo: string, campos: Record<string, string>) => acaoPhp<Resp>(arquivo, campos);
const msgErro = (e: unknown) => (e instanceof ErroApi || e instanceof Error ? e.message : 'Não deu certo agora.');

function Carregando({ n = 4, altura = 90 }: { n?: number; altura?: number }) {
  return <View style={{ padding: 16, gap: 12 }}>{Array.from({ length: n }, (_, i) => <Esqueleto key={i} altura={altura} />)}</View>;
}

function Mensagem({ texto, erro }: { texto: string; erro?: boolean }) {
  const { tema } = useTema();
  if (!texto) return null;
  return <T style={{ color: erro ? tema.perigo : tema.sucesso, fontWeight: '700', fontSize: 14 }}>{texto}</T>;
}

/** Senha com botão de olho (obrigatório em todo campo de senha). */
export function EntradaSenha({ rotulo, valor, aoMudar }: { rotulo: string; valor: string; aoMudar: (v: string) => void }) {
  const { tema } = useTema();
  const [ver, setVer] = useState(false);
  return (
    <View style={{ gap: 6 }}>
      <T style={{ fontWeight: '700', fontSize: 14 }}>{rotulo}</T>
      <View style={{ flexDirection: 'row', alignItems: 'center', borderRadius: 14, borderWidth: 1, borderColor: tema.borda, backgroundColor: tema.superficie }}>
        <TextInput value={valor} onChangeText={aoMudar} secureTextEntry={!ver} autoCapitalize="none" autoCorrect={false} accessibilityLabel={rotulo} maxFontSizeMultiplier={1.5}
          style={{ flex: 1, minHeight: 50, color: tema.texto, paddingHorizontal: 14, fontSize: 16 }} />
        <Pressable onPress={() => setVer((v) => !v)} accessibilityRole="button" accessibilityLabel={ver ? 'Esconder senha' : 'Mostrar senha'} style={{ width: 52, height: 50, alignItems: 'center', justifyContent: 'center' }}>
          <T style={{ fontSize: 20 }}>{ver ? '🙈' : '👁️'}</T>
        </Pressable>
      </View>
    </View>
  );
}

// ---------------------------------------------------------------- Agenda (equipe)

const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;


// ---------------------------------------------------------------- Arquivos (fontes / videoaulas)

function ListaArquivos({ itens, rotaDownload, agrupar, icone, acoes, topo }: { itens: FonteArquivo[]; rotaDownload: (f: FonteArquivo) => string; agrupar: (f: FonteArquivo) => string; icone: string; acoes?: (f: FonteArquivo) => { rotulo: string; aoTocar: () => void; perigo?: boolean }[]; topo?: ReactNode }) {
  const { tema } = useTema();
  const [busca, setBusca] = useState('');
  const filtrados = itens.filter((f) => !busca || `${f.titulo} ${f.arquivo} ${f.descricao ?? ''}`.toLowerCase().includes(busca.toLowerCase()));
  const grupos = [...new Set(filtrados.map(agrupar))];
  return (
    <ScrollView contentContainerStyle={{ padding: 16, gap: 10 }} keyboardShouldPersistTaps="handled">
      <TextInput value={busca} onChangeText={setBusca} placeholder="🔍  Buscar" placeholderTextColor={tema.textoSuave} accessibilityLabel="Buscar" maxFontSizeMultiplier={1.5}
        style={{ minHeight: 50, borderRadius: 16, borderWidth: 1, borderColor: tema.borda, backgroundColor: tema.superficie, color: tema.texto, paddingHorizontal: 16, fontSize: 16 }} />
      {topo}
      {!filtrados.length && <Vazio>Nada por aqui ainda.</Vazio>}
      {grupos.map((g) => (
        <View key={g} style={{ gap: 8 }}>
          <T style={{ color: tema.textoSuave, fontWeight: '800', fontSize: 13, textTransform: 'uppercase', marginTop: 6 }}>{g}</T>
          {filtrados.filter((f) => agrupar(f) === g).map((f) => (
            <Pressable key={f.id} onPress={() => void abrirArquivo(rotaDownload(f))} accessibilityRole="button" accessibilityLabel={`Abrir ${f.titulo}`}>
              <Cartao style={{ gap: 10 }}>
              <View style={{ flexDirection: 'row', gap: 12, alignItems: 'center' }}>
                <T style={{ fontSize: 26 }}>{icone}</T>
                <View style={{ flex: 1 }}>
                  <T style={{ fontWeight: '700' }}>{f.titulo}</T>
                  <T style={{ color: tema.textoSuave, fontSize: 13 }} numberOfLines={1}>{[f.categoria, f.arquivo, dataBR(f.criadoEm)].filter(Boolean).join(' · ')}</T>
                  {f.descricao && <T style={{ color: tema.textoSuave, fontSize: 13 }} numberOfLines={2}>{f.descricao}</T>}
                </View>
                <T style={{ color: tema.primaria, fontSize: 22 }}>⬇</T>
              </View>
              {acoes && <Acoes itens={acoes(f)} />}
              </Cartao>
            </Pressable>
          ))}
        </View>
      ))}
    </ScrollView>
  );
}

const CATEGORIAS: Record<string, string> = { integracoes: 'Integrações', compras: 'Compras', estoque: 'Estoque', faturamento: 'Faturamento', pcp: 'PCP', qualidade: 'Qualidade', financeiro: 'Financeiro', fiscal: 'Fiscal', contabilidade: 'Contabilidade', importacoes: 'Importações', exportacoes: 'Exportações', miscelanea: 'Miscelânea' };

export function TelaFontes() {
  const [itens, setItens] = useState<FonteArquivo[] | null>(null);
  const [form, setForm] = useState<{ fonte_id: string; nome: string; categoria: string; descricao: string; arquivos: ArquivoEscolhido[] } | null>(null);
  const [aviso, setAviso] = useState<{ t: string; e: boolean }>({ t: '', e: false });
  const [salvando, setSalvando] = useState(false);
  const carregar = useCallback(() => { api<FonteArquivo[]>('/api/fontes').then(setItens).catch(() => setItens([])); }, []);
  useEffect(carregar, [carregar]);

  async function salvar() {
    if (!form) return;
    setSalvando(true); setAviso({ t: '', e: false });
    try {
      await acaoPhp('fontes.php', { acao: form.fonte_id ? 'atualizar_fonte_global' : 'criar_fonte_global', fonte_id: form.fonte_id, nome: form.nome, categoria: form.categoria, descricao: form.descricao },
        form.fonte_id ? (form.arquivos.length ? { arquivo: form.arquivos.slice(0, 1) } : {}) : { 'arquivo[]': form.arquivos });
      setForm(null); setAviso({ t: 'Fonte salva.', e: false }); carregar();
    } catch (e) { setAviso({ t: e instanceof Error ? e.message : 'Não deu pra salvar.', e: true }); }
    setSalvando(false);
  }
  async function excluir(f: FonteArquivo) {
    if (!(await confirmar('Excluir fonte', `Excluir "${f.titulo}"?`, 'Excluir'))) return;
    try { await acaoPhp('fontes.php', { acao: 'excluir_fonte_global', fonte_id: String(f.id) }); setAviso({ t: 'Fonte excluída.', e: false }); carregar(); }
    catch (e) { setAviso({ t: e instanceof Error ? e.message : 'Não deu pra excluir.', e: true }); }
  }

  if (!itens) return <Carregando />;
  return (
    <>
      <ListaArquivos itens={itens} icone="💻" agrupar={(f) => CATEGORIAS[f.categoria ?? ''] ?? 'Outros'} rotaDownload={(f) => `/download_fonte_global.php?id=${f.id}`}
        topo={<View style={{ gap: 10 }}>
          <BotaoGradiente titulo="＋ Enviar fontes" aoTocar={() => setForm({ fonte_id: '', nome: '', categoria: 'integracoes', descricao: '', arquivos: [] })} />
          {!form && <Aviso texto={aviso.t} erro={aviso.e} />}
        </View>}
        acoes={(f) => [
          { rotulo: '✏️ Editar', aoTocar: () => setForm({ fonte_id: String(f.id), nome: f.titulo, categoria: f.categoria ?? 'miscelanea', descricao: f.descricao ?? '', arquivos: [] }) },
          { rotulo: '🗑️ Excluir', perigo: true, aoTocar: () => void excluir(f) },
        ]} />
      <Folha aberta={!!form} aoFechar={() => setForm(null)} titulo={form?.fonte_id ? 'Editar fonte' : 'Enviar fontes'}
        rodape={form && <><Aviso texto={aviso.e ? aviso.t : ''} erro /><BotaoGradiente titulo={salvando ? 'Salvando…' : 'Salvar'} aoTocar={() => void salvar()} desativado={salvando || (!form.fonte_id && !form.arquivos.length)} /></>}>
        {form && (<>
          <Entrada rotulo={form.fonte_id ? 'Nome' : 'Nome (opcional; vazio usa o nome do arquivo)'} valor={form.nome} aoMudar={(v) => setForm({ ...form, nome: v })} />
          <Escolha rotulo="Categoria" opcoes={Object.entries(CATEGORIAS).map(([valor, rotulo]) => ({ valor, rotulo }))} valor={form.categoria} aoMudar={(v) => setForm({ ...form, categoria: v })} />
          <Entrada rotulo="Descrição" valor={form.descricao} aoMudar={(v) => setForm({ ...form, descricao: v })} linhas={3} />
          <SeletorAnexos rotulo={form.fonte_id ? 'Trocar arquivo (opcional)' : 'Arquivos .prw / .tlpp *'} tipos={['*/*']} varios={!form.fonte_id} arquivos={form.arquivos} aoMudar={(a) => setForm({ ...form, arquivos: a })} />
        </>)}
      </Folha>
    </>
  );
}

export function TelaFontesCliente() {
  const [itens, setItens] = useState<FonteArquivo[] | null>(null);
  useEffect(() => { api<{ fontes: FonteArquivo[] }>('/api/portal/fontes').then((d) => setItens(d.fontes)).catch(() => setItens([])); }, []);
  if (!itens) return <Carregando />;
  return <ListaArquivos itens={itens} icone="💻" agrupar={(f) => f.setor ?? 'Geral'} rotaDownload={(f) => `/download_fonte_advpl.php?id=${f.id}`} />;
}

export function TelaVideoaulas() {
  const [itens, setItens] = useState<FonteArquivo[] | null>(null);
  useEffect(() => { api<FonteArquivo[]>('/api/portal/videoaulas').then(setItens).catch(() => setItens([])); }, []);
  if (!itens) return <Carregando />;
  return <ListaArquivos itens={itens} icone="🎬" agrupar={(f) => f.setor ?? 'Geral'} rotaDownload={(f) => `/video_aula.php?id=${f.id}`} />;
}

// ---------------------------------------------------------------- Calendário (cliente)

export function TelaCalendario() {
  const { tema } = useTema();
  const [itens, setItens] = useState<EventoCliente[] | null>(null);
  useEffect(() => { api<EventoCliente[]>('/api/portal/calendario').then(setItens).catch(() => setItens([])); }, []);
  if (!itens) return <Carregando />;
  const hoje = iso(new Date());
  const proximos = itens.filter((e) => (e.inicio ?? '') >= hoje).reverse();
  const passados = itens.filter((e) => (e.inicio ?? '') < hoje);
  const cartao = (e: EventoCliente) => (
    <Cartao key={e.id} style={{ gap: 4 }}>
      <T style={{ fontWeight: '700' }}>📅 {e.titulo}</T>
      <T style={{ color: tema.textoSuave, fontSize: 14 }}>{[dataBR(e.inicio, true), e.setor, e.tipo].filter(Boolean).join(' · ')}</T>
      {e.link && <BotaoContorno titulo="Entrar na reunião" aoTocar={() => void Linking.openURL(e.link ?? '')} />}
    </Cartao>
  );
  return (
    <ScrollView contentContainerStyle={{ padding: 16, gap: 10 }}>
      <T style={{ fontWeight: '800', fontSize: 17 }}>Próximos</T>
      {proximos.length ? proximos.map(cartao) : <Vazio>Nada marcado por enquanto.</Vazio>}
      {passados.length > 0 && <T style={{ fontWeight: '800', fontSize: 17, marginTop: 8 }}>Já aconteceram</T>}
      {passados.map(cartao)}
    </ScrollView>
  );
}

// ---------------------------------------------------------------- Equipe do cliente

export function TelaEquipeCliente() {
  const { tema } = useTema();
  const [dados, setDados] = useState<EquipePortal | null>(null);
  const [aviso, setAviso] = useState<{ t: string; e: boolean }>({ t: '', e: false });
  const [nova, setNova] = useState<{ nome: string; email: string; telefone: string; documento: string; eh_master: boolean; setores: number[] } | null>(null);
  const [novoSetor, setNovoSetor] = useState<string | null>(null);
  const [ocupado, setOcupado] = useState(false);
  const carregar = () => api<EquipePortal>('/api/portal/equipe').then(setDados).catch(() => setDados({ setores: [], pessoas: [], podeGerenciar: false }));
  useEffect(() => { void carregar(); }, []);
  async function agir(campos: Record<string, string>): Promise<boolean> {
    setOcupado(true);
    try { const r = await acao('portal/equipe.php', campos); setAviso({ t: r.mensagens?.sucesso ?? 'Pronto!', e: false }); setNova(null); setNovoSetor(null); void carregar(); return true; }
    catch (e) { setAviso({ t: msgErro(e), e: true }); return false; }
    finally { setOcupado(false); }
  }
  function criarPessoa() {
    if (!nova) return;
    const c: Record<string, string> = { acao: 'criar_usuario_cliente', nome: nova.nome, email: nova.email, telefone: nova.telefone, documento: nova.documento };
    if (nova.eh_master) c.eh_master = '1';
    nova.setores.forEach((x, i) => { c[`setor_ids[${i}]`] = String(x); });
    void agir(c);
  }
  if (!dados) return <Carregando />;
  return (
    <ScrollView contentContainerStyle={{ padding: 16, gap: 10 }}>
      {dados.podeGerenciar && <BotaoGradiente titulo="＋ Novo usuário" aoTocar={() => setNova({ nome: '', email: '', telefone: '', documento: '', eh_master: false, setores: [] })} />}
      {!nova && !novoSetor && <Mensagem texto={aviso.t} erro={aviso.e} />}
      <Cartao style={{ gap: 6 }}>
        <View style={{ flexDirection: 'row', alignItems: 'center' }}>
          <T style={{ fontWeight: '800', flex: 1 }}>Setores</T>
          {dados.podeGerenciar && <Pressable onPress={() => setNovoSetor('')} accessibilityRole="button" hitSlop={8}><T style={{ color: tema.primaria, fontWeight: '800' }}>＋ Setor</T></Pressable>}
        </View>
        <T style={{ color: tema.textoSuave }}>{dados.setores.map((s) => s.nome).join(' · ') || 'Nenhum setor ainda.'}</T>
      </Cartao>
      {dados.pessoas.map((p) => (
        <Cartao key={p.id} style={{ gap: 8 }}>
          <View style={{ flexDirection: 'row', gap: 12, alignItems: 'center' }}>
            <FotoPessoa caminho={p.foto} nome={p.nome} />
            <View style={{ flex: 1 }}>
              <T style={{ fontWeight: '700' }}>{p.nome}{p.master ? ' · Master' : ''}</T>
              <T style={{ color: tema.textoSuave, fontSize: 13 }}>{p.email ?? 'sem e-mail'}</T>
              <T style={{ color: tema.textoSuave, fontSize: 13 }}>{p.setores.join(', ') || 'Sem setor'} · {p.portalAtivo ? 'acesso ativo' : 'sem acesso'}</T>
            </View>
          </View>
          {dados.podeGerenciar && <Acoes itens={[
            ...(p.email ? [{ rotulo: '✉️ Convite', aoTocar: () => void agir({ acao: 'enviar_convite_setor', responsavel_id: String(p.id) }) },
              { rotulo: '🔑 Nova senha', aoTocar: () => void confirmar('Nova senha', `Mandar para ${p.nome} um link de nova senha?`, 'Mandar', false).then((ok) => ok && agir({ acao: 'resetar_senha_setor', responsavel_id: String(p.id) })) }] : []),
            ...(p.portalAtivo ? [{ rotulo: '⛔ Tirar acesso', perigo: true, aoTocar: () => void confirmar('Tirar acesso', `Tirar o acesso de ${p.nome}?`, 'Tirar').then((ok) => ok && agir({ acao: 'desativar_portal_setor', responsavel_id: String(p.id) })) }] : []),
          ]} />}
        </Cartao>
      ))}
      {!dados.pessoas.length && <Vazio>Nenhuma pessoa cadastrada.</Vazio>}
      {!dados.podeGerenciar && <T style={{ color: tema.textoSuave, fontSize: 13 }}>Para incluir ou alterar pessoas, peça à Prime para liberar esse recurso.</T>}
      <Folha aberta={!!nova} aoFechar={() => setNova(null)} titulo="Novo usuário"
        rodape={nova && <><Aviso texto={aviso.e ? aviso.t : ''} erro /><BotaoGradiente titulo={ocupado ? 'Salvando…' : 'Cadastrar'} aoTocar={criarPessoa} desativado={ocupado || !nova.nome.trim()} /></>}>
        {nova && (<>
          <Entrada rotulo="Nome *" valor={nova.nome} aoMudar={(v) => setNova({ ...nova, nome: v })} />
          <Entrada rotulo="E-mail (para acessar o portal)" valor={nova.email} aoMudar={(v) => setNova({ ...nova, email: v })} />
          <Entrada rotulo="Telefone / WhatsApp" valor={nova.telefone} aoMudar={(v) => setNova({ ...nova, telefone: v })} dica="(17) 99999-9999" />
          <Entrada rotulo="CPF" valor={nova.documento} aoMudar={(v) => setNova({ ...nova, documento: v })} />
          <T style={{ fontWeight: '700', fontSize: 14 }}>Setores</T>
          <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8 }}>
            {dados.setores.filter((x) => x.ativo).map((x) => {
              const marcado = nova.setores.includes(x.id);
              return (
                <Pressable key={x.id} onPress={() => setNova({ ...nova, setores: marcado ? nova.setores.filter((y) => y !== x.id) : [...nova.setores, x.id] })} accessibilityRole="checkbox" accessibilityState={{ checked: marcado }}
                  style={{ minHeight: 44, borderRadius: 99, paddingHorizontal: 14, justifyContent: 'center', backgroundColor: marcado ? tema.primaria : tema.superficieAlta, borderWidth: 1, borderColor: marcado ? tema.primaria : tema.borda }}>
                  <T style={{ fontSize: 14, fontWeight: '700', color: marcado ? tema.primariaTexto : tema.texto }}>{marcado ? '✓ ' : ''}{x.nome}</T>
                </Pressable>
              );
            })}
          </View>
          <Escolha rotulo="Pode gerenciar a equipe (master)?" opcoes={[{ valor: 'nao', rotulo: 'Não' }, { valor: 'sim', rotulo: 'Sim' }]} valor={nova.eh_master ? 'sim' : 'nao'} aoMudar={(v) => setNova({ ...nova, eh_master: v === 'sim' })} />
        </>)}
      </Folha>
      <Folha aberta={novoSetor !== null} aoFechar={() => setNovoSetor(null)} titulo="Novo setor"
        rodape={<><Aviso texto={aviso.e ? aviso.t : ''} erro /><BotaoGradiente titulo={ocupado ? 'Salvando…' : 'Criar setor'} aoTocar={() => void agir({ acao: 'criar_setor', nome: novoSetor ?? '', icone: '' })} desativado={ocupado || !(novoSetor ?? '').trim()} /></>}>
        <Entrada rotulo="Nome do setor *" valor={novoSetor ?? ''} aoMudar={setNovoSetor} dica="Ex.: Financeiro" />
      </Folha>
    </ScrollView>
  );
}

// ---------------------------------------------------------------- Usuários (admin)

const PERFIS: Record<string, string> = { colaborador: 'Colaborador', admin: 'Administrador', diretor: 'Diretor', ceo: 'CEO' };
const COR_PERFIL: Record<string, string> = { colaborador: '#0ea5e9', admin: '#8b5cf6', diretor: '#f59e0b', ceo: '#f43f5e' };

export function TelaUsuarios() {
  const { tema } = useTema();
  const [itens, setItens] = useState<UsuarioEquipe[] | null>(null);
  const [senha, setSenha] = useState<{ id: number; nome: string; nova: string; confirmar: string } | null>(null);
  const [aviso, setAviso] = useState<{ t: string; e: boolean }>({ t: '', e: false });
  const [atualizando, setAtualizando] = useState(false);
  const [form, setForm] = useState<{ usuario_id: string; nome: string; email: string; telefone: string; documento: string; perfil: string; senha: string; restrito: boolean; sensiveis: boolean } | null>(null);
  const [avatar, setAvatar] = useState<UsuarioEquipe | null>(null);
  const [ocupado, setOcupado] = useState(false);
  const carregar = () => api<UsuarioEquipe[]>('/api/usuarios').then(setItens).catch(() => setItens([])).finally(() => setAtualizando(false));
  useEffect(() => { void carregar(); }, []);
  async function agir(campos: Record<string, string>, foto: ArquivoEscolhido[] = []): Promise<boolean> {
    setOcupado(true);
    try { const r = await acaoPhp<Resp>('usuarios.php', campos, foto.length ? { foto } : {}); setAviso({ t: r.mensagens?.sucesso ?? 'Pronto!', e: false }); setSenha(null); setForm(null); setAvatar(null); void carregar(); return true; }
    catch (e) { setAviso({ t: msgErro(e), e: true }); return false; }
    finally { setOcupado(false); }
  }
  function salvar() {
    if (!form) return;
    void agir({ acao: form.usuario_id ? 'atualizar_usuario' : 'criar_usuario', usuario_id: form.usuario_id, nome: form.nome, email: form.email, telefone: form.telefone, documento: form.documento, perfil: form.perfil, senha: form.senha, acesso_restrito_por_cliente: form.restrito ? '1' : '0', acesso_dados_sensiveis: form.sensiveis ? '1' : '0' });
  }
  if (!itens) return <Carregando />;
  if (senha) {
    return (
      <ScrollView contentContainerStyle={{ padding: 16, gap: 14 }} keyboardShouldPersistTaps="handled">
        <T style={{ fontWeight: '800', fontSize: 18 }}>Nova senha de {senha.nome}</T>
        <EntradaSenha rotulo="Nova senha" valor={senha.nova} aoMudar={(v) => setSenha({ ...senha, nova: v })} />
        <EntradaSenha rotulo="Confirmar" valor={senha.confirmar} aoMudar={(v) => setSenha({ ...senha, confirmar: v })} />
        <T style={{ color: tema.textoSuave, fontSize: 13 }}>Mínimo 8 caracteres, com maiúscula, minúscula, número e símbolo.</T>
        <Mensagem texto={aviso.t} erro={aviso.e} />
        <BotaoGradiente titulo="Definir senha" aoTocar={() => void agir({ acao: 'definir_senha_usuario', usuario_id: String(senha.id), nova_senha: senha.nova, confirmar_senha: senha.confirmar })} />
        <BotaoContorno titulo="Cancelar" aoTocar={() => setSenha(null)} />
      </ScrollView>
    );
  }
  return (
    <ScrollView contentContainerStyle={{ padding: 16, gap: 10 }} refreshControl={<RefreshControl refreshing={atualizando} onRefresh={() => { setAtualizando(true); void carregar(); }} colors={[tema.primaria]} />}>
      <BotaoGradiente titulo="＋ Novo usuário" aoTocar={() => { setAviso({ t: '', e: false }); setForm({ usuario_id: '', nome: '', email: '', telefone: '', documento: '', perfil: 'colaborador', senha: '', restrito: false, sensiveis: false }); }} />
      {!form && !avatar && <Mensagem texto={aviso.t} erro={aviso.e} />}
      {itens.map((u) => {
        const cor = COR_PERFIL[u.perfil] ?? '#0ea5e9';
        return (
          <Cartao key={u.id} style={{ gap: 10, opacity: u.ativo ? 1 : 0.6, borderLeftWidth: 5, borderLeftColor: cor }}>
            <View style={{ flexDirection: 'row', gap: 12, alignItems: 'center' }}>
              <Pressable onPress={() => { setAviso({ t: '', e: false }); setAvatar(u); }} accessibilityRole="button" accessibilityLabel={`Trocar foto de ${u.nome}`}>
                <View style={{ borderRadius: 30, borderWidth: 3, borderColor: cor, padding: 2 }}><FotoPessoa caminho={u.foto} nome={u.nome} tamanho={48} /></View>
                <View style={{ position: 'absolute', right: -2, bottom: -2, width: 22, height: 22, borderRadius: 11, backgroundColor: cor, alignItems: 'center', justifyContent: 'center' }}><T style={{ fontSize: 11, color: '#fff' }}>✎</T></View>
              </Pressable>
              <View style={{ flex: 1 }}>
                <T style={{ fontWeight: '700' }}>{u.nome}</T>
                <T style={{ color: tema.textoSuave, fontSize: 13 }} numberOfLines={1}>{u.email}</T>
                <View style={{ flexDirection: 'row', gap: 6, marginTop: 4, flexWrap: 'wrap' }}>
                  <View style={{ backgroundColor: cor, borderRadius: 99, paddingHorizontal: 9, paddingVertical: 2 }}><T style={{ color: '#fff', fontSize: 12, fontWeight: '800' }}>{PERFIS[u.perfil] ?? u.perfil}</T></View>
                  {!u.ativo && <View style={{ backgroundColor: tema.superficieAlta, borderRadius: 99, paddingHorizontal: 9, paddingVertical: 2 }}><T style={{ fontSize: 12, fontWeight: '700' }}>Desativado</T></View>}
                </View>
              </View>
            </View>
            <Acoes itens={[
              { rotulo: '✏️ Editar', aoTocar: () => { setAviso({ t: '', e: false }); setForm({ usuario_id: String(u.id), nome: u.nome, email: u.email, telefone: u.telefone ?? '', documento: u.documento ?? '', perfil: u.perfil, senha: '', restrito: u.restrito, sensiveis: u.sensiveis }); } },
              { rotulo: '🔑 Senha', aoTocar: () => { setAviso({ t: '', e: false }); setSenha({ id: u.id, nome: u.nome, nova: '', confirmar: '' }); } },
              { rotulo: u.ativo ? '⏻ Desativar' : '⏻ Reativar', perigo: u.ativo, aoTocar: () => void confirmar(u.ativo ? 'Desativar' : 'Reativar', `${u.ativo ? 'Desativar' : 'Reativar'} ${u.nome}?`, u.ativo ? 'Desativar' : 'Reativar', u.ativo).then((ok) => ok && agir({ acao: 'alternar_status', usuario_id: String(u.id) })) },
            ]} />
          </Cartao>
        );
      })}
      <Folha aberta={!!form} aoFechar={() => setForm(null)} titulo={form?.usuario_id ? 'Editar usuário' : 'Novo usuário'}
        rodape={form && <><Aviso texto={aviso.e ? aviso.t : ''} erro /><BotaoGradiente titulo={ocupado ? 'Salvando…' : 'Salvar'} aoTocar={salvar} desativado={ocupado || !form.nome.trim() || !form.email.trim() || (!form.usuario_id && form.senha.length < 8)} /></>}>
        {form && (<>
          <Entrada rotulo="Nome *" valor={form.nome} aoMudar={(v) => setForm({ ...form, nome: v })} />
          <Entrada rotulo="E-mail *" valor={form.email} aoMudar={(v) => setForm({ ...form, email: v })} />
          <Entrada rotulo="Telefone / WhatsApp" valor={form.telefone} aoMudar={(v) => setForm({ ...form, telefone: v })} />
          <Entrada rotulo="CPF" valor={form.documento} aoMudar={(v) => setForm({ ...form, documento: v })} />
          <Escolha rotulo="Perfil" opcoes={Object.entries(PERFIS).map(([valor, rotulo]) => ({ valor, rotulo }))} valor={form.perfil} aoMudar={(v) => setForm({ ...form, perfil: v })} />
          {!form.usuario_id && <EntradaSenha rotulo="Senha inicial * (mín. 8, com maiúscula, número e símbolo)" valor={form.senha} aoMudar={(v) => setForm({ ...form, senha: v })} />}
          <Escolha rotulo="Vê só os clientes vinculados a ele?" opcoes={[{ valor: 'nao', rotulo: 'Não, vê todos' }, { valor: 'sim', rotulo: 'Sim, só os dele' }]} valor={form.restrito ? 'sim' : 'nao'} aoMudar={(v) => setForm({ ...form, restrito: v === 'sim' })} />
          <Escolha rotulo="Acesso a dados sensíveis (senhas, VPN)?" opcoes={[{ valor: 'nao', rotulo: 'Não' }, { valor: 'sim', rotulo: 'Sim' }]} valor={form.sensiveis ? 'sim' : 'nao'} aoMudar={(v) => setForm({ ...form, sensiveis: v === 'sim' })} />
        </>)}
      </Folha>
      <Folha aberta={!!avatar} aoFechar={() => setAvatar(null)} titulo={avatar ? `Foto de ${avatar.nome}` : ''}>
        {avatar && (<>
          <View style={{ flexDirection: 'row', gap: 8 }}>
            <View style={{ flex: 1 }}><BotaoContorno titulo="🖼️ Da galeria" desativado={ocupado} aoTocar={() => void escolherDaGaleria(true, false).then((f) => f.length > 0 && agir({ acao: 'atualizar_avatar_usuario', usuario_id: String(avatar.id) }, f.slice(0, 1)))} /></View>
            <View style={{ flex: 1 }}><BotaoContorno titulo="📷 Câmera" desativado={ocupado} aoTocar={() => void tirarFoto().then((f) => f.length > 0 && agir({ acao: 'atualizar_avatar_usuario', usuario_id: String(avatar.id) }, f.slice(0, 1)))} /></View>
          </View>
          <T style={{ fontWeight: '700' }}>Ou escolha um avatar:</T>
          <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 10, justifyContent: 'center' }}>
            {Array.from({ length: 24 }, (_, i) => i + 1).map((n) => {
              const caminho = `assets/img/avatares/avatar-${n}.svg`;
              return (
                <Pressable key={n} disabled={ocupado} onPress={() => void agir({ acao: 'escolher_avatar_predefinido_usuario', usuario_id: String(avatar.id), avatar: String(n) })} accessibilityRole="button" accessibilityLabel={`Avatar ${n}`}
                  style={{ width: 58, height: 58, borderRadius: 29, borderWidth: 3, borderColor: avatar.foto === caminho ? tema.primaria : 'transparent', alignItems: 'center', justifyContent: 'center', backgroundColor: tema.superficieAlta }}>
                  <FotoPessoa caminho={caminho} tamanho={48} />
                </Pressable>
              );
            })}
          </View>
          <Aviso texto={aviso.e ? aviso.t : ''} erro />
        </>)}
      </Folha>
    </ScrollView>
  );
}

// ---------------------------------------------------------------- Perfil

export function TelaPerfil({ equipe, aoMudarFoto }: { equipe: boolean; aoMudarFoto: (foto: string | null) => void }) {
  const { tema } = useTema();
  const arquivo = equipe ? 'perfil.php' : 'portal/perfil.php';
  const [p, setP] = useState<Perfil | null>(null);
  const [dados, setDados] = useState({ nome: '', email: '', telefone: '', documento: '' });
  const [senha, setSenha] = useState({ senha_atual: '', nova_senha: '', confirmar_senha: '' });
  const [aviso, setAviso] = useState<{ t: string; e: boolean }>({ t: '', e: false });
  const carregar = useCallback(() => api<Perfil>('/api/perfil').then((x) => {
    setP(x); aoMudarFoto(x.foto); setDados({ nome: x.nome ?? '', email: x.email ?? '', telefone: x.telefone ?? '', documento: x.documento ?? '' });
  }), [aoMudarFoto]);
  useEffect(() => { void carregar(); }, [carregar]);
  async function agir(campos: Record<string, string>, foto: ArquivoEscolhido[] = []): Promise<boolean> {
    try { const r = await acaoPhp<Resp>(arquivo, campos, foto.length ? { foto } : {}); setAviso({ t: r.mensagens?.sucesso ?? 'Pronto!', e: false }); void carregar(); return true; } catch (e) { setAviso({ t: msgErro(e), e: true }); return false; }
  }
  const enviarFoto = (origem: 'galeria' | 'camera') => void (origem === 'galeria' ? escolherDaGaleria(true, false) : tirarFoto()).then((f) => f.length > 0 && agir({ acao: 'atualizar_foto' }, f.slice(0, 1)));
  if (!p) return <Carregando n={3} />;
  return (
    <ScrollView contentContainerStyle={{ padding: 16, gap: 14 }} keyboardShouldPersistTaps="handled">
      <Cartao style={{ gap: 12, alignItems: 'center' }}>
        <FotoPessoa caminho={p.foto} nome={p.nome} tamanho={96} />
        <T style={{ fontWeight: '800', fontSize: 18 }}>{p.nome}</T>
        <T style={{ color: tema.textoSuave, fontSize: 14 }}>Envie uma foto ou escolha um avatar:</T>
        <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8, justifyContent: 'center' }}>
          {Array.from({ length: 24 }, (_, i) => i + 1).map((n) => {
            const caminho = `assets/img/avatares/avatar-${n}.svg`;
            return (
              <Pressable key={n} onPress={() => void agir({ acao: 'escolher_avatar_predefinido', avatar: String(n) })} accessibilityRole="button" accessibilityLabel={`Avatar ${n}`}
                style={{ width: 52, height: 52, borderRadius: 26, borderWidth: 3, borderColor: p.foto === caminho ? tema.primaria : 'transparent', alignItems: 'center', justifyContent: 'center', backgroundColor: tema.superficieAlta }}>
                <FotoPessoa caminho={caminho} tamanho={44} />
              </Pressable>
            );
          })}
        </View>
        <View style={{ flexDirection: 'row', gap: 8, alignSelf: 'stretch' }}>
          <View style={{ flex: 1 }}><BotaoContorno titulo="🖼️ Foto da galeria" aoTocar={() => enviarFoto('galeria')} /></View>
          <View style={{ flex: 1 }}><BotaoContorno titulo="📷 Tirar foto" aoTocar={() => enviarFoto('camera')} /></View>
        </View>
      </Cartao>
      <Mensagem texto={aviso.t} erro={aviso.e} />
      {equipe && (
        <>
          <Cartao style={{ gap: 12 }}>
            <T style={{ fontWeight: '800' }}>Meus dados</T>
            <Entrada rotulo="Nome" valor={dados.nome} aoMudar={(v) => setDados({ ...dados, nome: v })} />
            <Entrada rotulo="E-mail" valor={dados.email} aoMudar={(v) => setDados({ ...dados, email: v })} />
            <Entrada rotulo="Telefone" valor={dados.telefone} aoMudar={(v) => setDados({ ...dados, telefone: v })} />
            <BotaoGradiente titulo="Salvar dados" aoTocar={() => void agir({ acao: 'atualizar_dados', ...dados })} />
          </Cartao>
          <Cartao style={{ gap: 12 }}>
            <T style={{ fontWeight: '800' }}>Trocar senha</T>
            <EntradaSenha rotulo="Senha atual" valor={senha.senha_atual} aoMudar={(v) => setSenha({ ...senha, senha_atual: v })} />
            <EntradaSenha rotulo="Nova senha" valor={senha.nova_senha} aoMudar={(v) => setSenha({ ...senha, nova_senha: v })} />
            <EntradaSenha rotulo="Confirmar" valor={senha.confirmar_senha} aoMudar={(v) => setSenha({ ...senha, confirmar_senha: v })} />
            <BotaoGradiente titulo="Trocar senha" aoTocar={() => void agir({ acao: 'trocar_senha', ...senha })} />
          </Cartao>
        </>
      )}
    </ScrollView>
  );
}

// ---------------------------------------------------------------- Configurações (admin)

interface Config { valores: Record<string, string>; autorIa: number; usuarios: { id: number; nome: string }[]; timbrado: { cabecalho: string | null; rodape: string | null; cabecalhoAltura: number; rodapeAltura: number } }

export function TelaConfiguracoes() {
  const { tema } = useTema();
  const [cfg, setCfg] = useState<Config | null>(null);
  const [v, setV] = useState<Record<string, string>>({});
  const [aviso, setAviso] = useState<{ t: string; e: boolean }>({ t: '', e: false });
  const [erro, setErro] = useState('');
  const [timbrado, setTimbrado] = useState<{ cabecalho: ArquivoEscolhido[]; rodape: ArquivoEscolhido[] }>({ cabecalho: [], rodape: [] });
  const [alturas, setAlturas] = useState({ cabecalho: '', rodape: '' });
  useEffect(() => {
    lerJsonPhp<Config>('/configuracoes.php?formato=json').then((c) => { setAlturas({ cabecalho: String(c.timbrado.cabecalhoAltura), rodape: String(c.timbrado.rodapeAltura) }); return c; }).then((c) => { setCfg(c); setV({ ...c.valores, ia_chamados_usuario_id: c.valores.ia_chamados_usuario_id || String(c.autorIa) }); }).catch((e: unknown) => setErro(msgErro(e)));
  }, []);
  if (erro) return <Vazio>{erro}</Vazio>;
  if (!cfg) return <Carregando />;
  const campo = (k: string, r: string) => <Entrada rotulo={r} valor={v[k] ?? ''} aoMudar={(x) => setV({ ...v, [k]: x })} />;
  const segredo = (k: string, r: string) => <EntradaSenha rotulo={r} valor={v[k] ?? ''} aoMudar={(x) => setV({ ...v, [k]: x })} />;
  async function salvar() {
    if (!cfg) return;
    try {
      const r = await acaoPhp<Resp>('configuracoes.php', { ...v, cabecalho_altura_mm: alturas.cabecalho, rodape_altura_mm: alturas.rodape },
        { ...(timbrado.cabecalho.length ? { timbrado_cabecalho: timbrado.cabecalho } : {}), ...(timbrado.rodape.length ? { timbrado_rodape: timbrado.rodape } : {}) });
      setAviso({ t: r.mensagens?.sucesso ?? 'Configurações salvas.', e: false });
      setTimbrado({ cabecalho: [], rodape: [] });
    } catch (e) { setAviso({ t: msgErro(e), e: true }); }
  }
  return (
    <ScrollView contentContainerStyle={{ padding: 16, gap: 14 }} keyboardShouldPersistTaps="handled">
      <Cartao style={{ gap: 12 }}>
        <T style={{ fontWeight: '800' }}>💬 WhatsApp (Meta)</T>
        {segredo('whatsapp_access_token', 'Access Token')}
        {campo('whatsapp_phone_number_id', 'Phone Number ID')}
        {segredo('whatsapp_app_secret', 'App Secret')}
        {campo('whatsapp_notificar_telefone', 'Telefone que recebe as notificações')}
      </Cartao>
      <Cartao style={{ gap: 12 }}>
        <T style={{ fontWeight: '800' }}>✉️ E-mail (SMTP)</T>
        {campo('smtp_host', 'Servidor SMTP')}
        {campo('smtp_port', 'Porta')}
        <Escolha rotulo="Segurança" opcoes={[{ valor: 'ssl', rotulo: 'SSL' }, { valor: 'tls', rotulo: 'TLS' }]} valor={v.smtp_secure ?? 'tls'} aoMudar={(x) => setV({ ...v, smtp_secure: x })} />
        {campo('smtp_user', 'Usuário (remetente)')}
        {segredo('smtp_pass', 'Senha')}
        {campo('smtp_nome_remetente', 'Nome do remetente')}
        <Entrada rotulo="E-mails da equipe (um por linha)" valor={v.notificacao_emails_equipe ?? ''} aoMudar={(x) => setV({ ...v, notificacao_emails_equipe: x })} linhas={3} />
      </Cartao>
      <Cartao style={{ gap: 12 }}>
        <T style={{ fontWeight: '800' }}>🤖 IA nos chamados</T>
        {segredo('gemini_api_key', 'Chave de análise')}
        {segredo('gemini_api_key_busca', 'Chave de busca')}
        <Escolha rotulo="Modo" opcoes={[{ valor: 'sugestao', rotulo: 'Sugestão' }, { valor: 'automatico', rotulo: 'Automático' }, { valor: 'desligado', rotulo: 'Desligado' }]} valor={v.ia_chamados_modo ?? 'sugestao'} aoMudar={(x) => setV({ ...v, ia_chamados_modo: x })} />
        <Escolha rotulo="Responder em nome de" opcoes={cfg.usuarios.map((u) => ({ valor: String(u.id), rotulo: u.nome }))} valor={v.ia_chamados_usuario_id ?? ''} aoMudar={(x) => setV({ ...v, ia_chamados_usuario_id: x })} />
        {campo('ia_chamados_limite_dia', 'Máximo de análises por dia')}
        {campo('ia_chamados_limite_busca_dia', 'Máximo de buscas no TDN por dia')}
      </Cartao>
      <Cartao style={{ gap: 12 }}>
        <T style={{ fontWeight: '800' }}>📄 Timbrado dos PDFs</T>
        <T style={{ color: tema.textoSuave, fontSize: 13 }}>Cabeçalho e rodapé de todo PDF gerado pelo sistema. Sem imagem, o sistema desenha um timbrado padrão.</T>
        {(['cabecalho', 'rodape'] as const).map((parte) => (
          <View key={parte} style={{ gap: 8 }}>
            <T style={{ fontWeight: '700' }}>{parte === 'cabecalho' ? 'Cabeçalho' : 'Rodapé'}</T>
            {cfg.timbrado[parte] && !timbrado[parte].length && <Image source={{ uri: arquivoPublico(cfg.timbrado[parte]) ?? '' }} resizeMode="contain" style={{ height: 70, borderRadius: 10, backgroundColor: '#fff' }} accessibilityLabel={`${parte} atual`} />}
            <SeletorAnexos rotulo="" somenteImagem varios={false} arquivos={timbrado[parte]} aoMudar={(a) => setTimbrado({ ...timbrado, [parte]: a })} />
            <Entrada rotulo="Altura (mm)" valor={alturas[parte]} aoMudar={(x) => setAlturas({ ...alturas, [parte]: x })} />
          </View>
        ))}
      </Cartao>
      <Mensagem texto={aviso.t} erro={aviso.e} />
      <BotaoGradiente titulo="Salvar configurações" aoTocar={() => void salvar()} />
    </ScrollView>
  );
}
