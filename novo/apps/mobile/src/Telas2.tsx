import type { Agenda, EquipePortal, EventoCliente, FonteArquivo, Perfil, TarefaAgenda, UsuarioEquipe } from '@primehub/shared';
import { useCallback, useEffect, useState } from 'react';
import { Linking, Pressable, RefreshControl, ScrollView, TextInput, View } from 'react-native';
import { acaoPhp, api, ErroApi, lerJsonPhp } from './api';
import { Esqueleto } from './Esqueleto';
import { useTema } from './tema';
import { abrirArquivo, BotaoContorno, BotaoGradiente, dataBR, Entrada, Escolha, LogoEmpresa, Vazio } from './Telas';
import { FotoPessoa } from './FotoPessoa';
import { Cartao, T } from './ui';
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
const DIAS = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];

export function TelaAgenda({ aoAbrirChamado }: { aoAbrirChamado: (id: number) => void }) {
  const { tema } = useTema();
  const [inicio, setInicio] = useState(() => { const d = new Date(); d.setHours(12); d.setDate(d.getDate() - ((d.getDay() + 6) % 7)); return d; });
  const [dados, setDados] = useState<Agenda | null>(null);
  const [form, setForm] = useState<{ tarefa_id: string; titulo: string; data: string; hora_inicio: string; cliente_id: string; notas: string; status: string } | null>(null);
  const [aviso, setAviso] = useState('');
  const dias = Array.from({ length: 7 }, (_, i) => { const d = new Date(inicio); d.setDate(d.getDate() + i); return d; });
  const de = iso(dias[0] ?? inicio), ate = iso(dias[6] ?? inicio);
  const carregar = useCallback(() => api<Agenda>(`/api/agenda?de=${de}&ate=${ate}`).then(setDados).catch(() => setDados({ tarefas: [], eventos: [], clientes: [], equipe: [] })), [de, ate]);
  useEffect(() => { setDados(null); void carregar(); }, [carregar]);

  async function feita(t: TarefaAgenda) {
    const status = t.status === 'concluida' ? 'pendente' : 'concluida';
    setDados((d) => d && { ...d, tarefas: d.tarefas.map((x) => (x.id === t.id ? { ...x, status } : x)) });
    await acao('agenda.php', { acao: 'mudar_status_tarefa', ajax: '1', tarefa_id: String(t.id), status }).catch(() => void carregar());
  }
  async function salvar() {
    if (!form) return;
    setAviso('');
    try {
      await acao('agenda.php', { ...form, duracao_minutos: '60', responsavel_id: '', acao: form.tarefa_id ? 'atualizar_tarefa' : 'criar_tarefa' });
      setForm(null); void carregar();
    } catch (e) { setAviso(msgErro(e)); }
  }
  async function excluir() {
    if (!form?.tarefa_id) return;
    try { await acao('agenda.php', { acao: 'excluir_tarefa', tarefa_id: form.tarefa_id }); setForm(null); void carregar(); } catch (e) { setAviso(msgErro(e)); }
  }

  if (form && dados) {
    return (
      <ScrollView contentContainerStyle={{ padding: 16, gap: 14 }} keyboardShouldPersistTaps="handled">
        <T style={{ fontWeight: '800', fontSize: 18 }}>{form.tarefa_id ? 'Editar tarefa' : 'Nova tarefa'}</T>
        <Entrada rotulo="Título *" valor={form.titulo} aoMudar={(v) => setForm({ ...form, titulo: v })} />
        <Entrada rotulo="Dia (AAAA-MM-DD) *" valor={form.data} aoMudar={(v) => setForm({ ...form, data: v })} />
        <Entrada rotulo="Horário (HH:MM)" valor={form.hora_inicio} aoMudar={(v) => setForm({ ...form, hora_inicio: v })} dica="Ex.: 09:30" />
        <Escolha rotulo="Cliente" opcoes={[{ valor: '', rotulo: 'Nenhum' }, ...dados.clientes.map((c) => ({ valor: String(c.id), rotulo: c.nome }))]} valor={form.cliente_id} aoMudar={(v) => setForm({ ...form, cliente_id: v })} />
        {!!form.tarefa_id && <Escolha rotulo="Situação" opcoes={[{ valor: 'pendente', rotulo: 'Pendente' }, { valor: 'em_andamento', rotulo: 'Em andamento' }, { valor: 'concluida', rotulo: 'Concluída' }]} valor={form.status} aoMudar={(v) => setForm({ ...form, status: v })} />}
        <Entrada rotulo="Anotações" valor={form.notas} aoMudar={(v) => setForm({ ...form, notas: v })} linhas={4} />
        <Mensagem texto={aviso} erro />
        <BotaoGradiente titulo="Salvar" aoTocar={() => void salvar()} desativado={!form.titulo.trim()} />
        {!!form.tarefa_id && <BotaoContorno titulo="🗑 Excluir tarefa" aoTocar={() => void excluir()} />}
        <BotaoContorno titulo="Cancelar" aoTocar={() => setForm(null)} />
      </ScrollView>
    );
  }

  const nav = (n: number) => setInicio((s) => { const x = new Date(s); x.setDate(x.getDate() + n * 7); return x; });
  return (
    <ScrollView contentContainerStyle={{ padding: 16, gap: 12 }}>
      <View style={{ flexDirection: 'row', gap: 8, alignItems: 'center' }}>
        <BotaoContorno titulo="‹" aoTocar={() => nav(-1)} />
        <T style={{ flex: 1, textAlign: 'center', fontWeight: '700' }}>{(dias[0] ?? inicio).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' })} a {(dias[6] ?? inicio).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' })}</T>
        <BotaoContorno titulo="›" aoTocar={() => nav(1)} />
      </View>
      <BotaoGradiente titulo="＋ Nova tarefa" aoTocar={() => setForm({ tarefa_id: '', titulo: '', data: iso(new Date()), hora_inicio: '', cliente_id: '', notas: '', status: 'pendente' })} />
      {!dados ? [0, 1, 2].map((i) => <Esqueleto key={i} altura={110} />) : dias.map((d) => {
        const dia = iso(d);
        const tarefas = dados.tarefas.filter((t) => t.data === dia);
        const eventos = dados.eventos.filter((e) => e.inicio?.slice(0, 10) === dia);
        const hoje = dia === iso(new Date());
        return (
          <Cartao key={dia} style={{ gap: 8, borderColor: hoje ? tema.primaria : tema.borda, borderWidth: hoje ? 2 : 1 }}>
            <T style={{ fontWeight: '800', color: hoje ? tema.primaria : tema.texto }}>{DIAS[d.getDay()]} · {d.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' })}</T>
            {eventos.map((e) => (
              <Pressable key={`e${e.id}`} onPress={() => e.link && void Linking.openURL(e.link)} style={{ flexDirection: 'row', gap: 10, alignItems: 'center', padding: 8, borderRadius: 12, backgroundColor: tema.superficieAlta }}>
                <LogoEmpresa nome={e.cliente} logo={e.clienteLogo} tamanho={34} />
                <View style={{ flex: 1 }}><T style={{ fontWeight: '700', fontSize: 14 }}>🎥 {e.titulo}</T><T style={{ color: tema.textoSuave, fontSize: 13 }}>{e.inicio?.slice(11, 16)} · {e.cliente}</T></View>
              </Pressable>
            ))}
            {tarefas.map((t) => (
              <View key={t.id} style={{ flexDirection: 'row', gap: 10, alignItems: 'center' }}>
                <Pressable onPress={() => void feita(t)} accessibilityRole="checkbox" accessibilityState={{ checked: t.status === 'concluida' }} accessibilityLabel={`Feita: ${t.titulo}`}
                  style={{ width: 34, height: 34, borderRadius: 10, borderWidth: 2, borderColor: t.status === 'concluida' ? tema.sucesso : tema.borda, backgroundColor: t.status === 'concluida' ? tema.sucesso : 'transparent', alignItems: 'center', justifyContent: 'center' }}>
                  {t.status === 'concluida' && <T style={{ color: '#FFFFFF', fontWeight: '800' }}>✓</T>}
                </Pressable>
                <Pressable style={{ flex: 1, minHeight: 44, justifyContent: 'center' }} onPress={() => setForm({ tarefa_id: String(t.id), titulo: t.titulo, data: t.data, hora_inicio: t.horaInicio ?? '', cliente_id: t.clienteId ? String(t.clienteId) : '', notas: t.notas ?? '', status: t.status })}>
                  <T style={{ fontWeight: '700', fontSize: 15, textDecorationLine: t.status === 'concluida' ? 'line-through' : 'none' }}>{t.titulo}</T>
                  <T style={{ color: tema.textoSuave, fontSize: 13 }}>{[t.horaInicio, t.cliente, t.responsavel].filter(Boolean).join(' · ')}</T>
                </Pressable>
                {t.chamadoId && <Pressable onPress={() => aoAbrirChamado(t.chamadoId ?? 0)} hitSlop={8}><T style={{ color: tema.primaria, fontWeight: '700', fontSize: 13 }}>#{t.chamadoId}</T></Pressable>}
              </View>
            ))}
            {!tarefas.length && !eventos.length && <T style={{ color: tema.textoSuave, fontSize: 14 }}>Livre</T>}
          </Cartao>
        );
      })}
    </ScrollView>
  );
}

// ---------------------------------------------------------------- Arquivos (fontes / videoaulas)

function ListaArquivos({ itens, rotaDownload, agrupar, icone }: { itens: FonteArquivo[]; rotaDownload: (f: FonteArquivo) => string; agrupar: (f: FonteArquivo) => string; icone: string }) {
  const { tema } = useTema();
  const [busca, setBusca] = useState('');
  const filtrados = itens.filter((f) => !busca || `${f.titulo} ${f.arquivo} ${f.descricao ?? ''}`.toLowerCase().includes(busca.toLowerCase()));
  const grupos = [...new Set(filtrados.map(agrupar))];
  return (
    <ScrollView contentContainerStyle={{ padding: 16, gap: 10 }} keyboardShouldPersistTaps="handled">
      <TextInput value={busca} onChangeText={setBusca} placeholder="🔍  Buscar" placeholderTextColor={tema.textoSuave} accessibilityLabel="Buscar" maxFontSizeMultiplier={1.5}
        style={{ minHeight: 50, borderRadius: 16, borderWidth: 1, borderColor: tema.borda, backgroundColor: tema.superficie, color: tema.texto, paddingHorizontal: 16, fontSize: 16 }} />
      {!filtrados.length && <Vazio>Nada por aqui ainda.</Vazio>}
      {grupos.map((g) => (
        <View key={g} style={{ gap: 8 }}>
          <T style={{ color: tema.textoSuave, fontWeight: '800', fontSize: 13, textTransform: 'uppercase', marginTop: 6 }}>{g}</T>
          {filtrados.filter((f) => agrupar(f) === g).map((f) => (
            <Pressable key={f.id} onPress={() => void abrirArquivo(rotaDownload(f))} accessibilityRole="button" accessibilityLabel={`Abrir ${f.titulo}`}>
              <Cartao style={{ flexDirection: 'row', gap: 12, alignItems: 'center' }}>
                <T style={{ fontSize: 26 }}>{icone}</T>
                <View style={{ flex: 1 }}>
                  <T style={{ fontWeight: '700' }}>{f.titulo}</T>
                  <T style={{ color: tema.textoSuave, fontSize: 13 }} numberOfLines={1}>{[f.categoria, f.arquivo, dataBR(f.criadoEm)].filter(Boolean).join(' · ')}</T>
                  {f.descricao && <T style={{ color: tema.textoSuave, fontSize: 13 }} numberOfLines={2}>{f.descricao}</T>}
                </View>
                <T style={{ color: tema.primaria, fontSize: 22 }}>⬇</T>
              </Cartao>
            </Pressable>
          ))}
        </View>
      ))}
      <T style={{ color: tema.textoSuave, fontSize: 12, textAlign: 'center' }}>Para enviar arquivos novos, use o site.</T>
    </ScrollView>
  );
}

const CATEGORIAS: Record<string, string> = { integracoes: 'Integrações', compras: 'Compras', estoque: 'Estoque', faturamento: 'Faturamento', pcp: 'PCP', qualidade: 'Qualidade', financeiro: 'Financeiro', fiscal: 'Fiscal', contabilidade: 'Contabilidade', importacoes: 'Importações', exportacoes: 'Exportações', miscelanea: 'Miscelânea' };

export function TelaFontes() {
  const [itens, setItens] = useState<FonteArquivo[] | null>(null);
  useEffect(() => { api<FonteArquivo[]>('/api/fontes').then(setItens).catch(() => setItens([])); }, []);
  if (!itens) return <Carregando />;
  return <ListaArquivos itens={itens} icone="💻" agrupar={(f) => CATEGORIAS[f.categoria ?? ''] ?? 'Outros'} rotaDownload={(f) => `/download_fonte_global.php?id=${f.id}`} />;
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
  const carregar = () => api<EquipePortal>('/api/portal/equipe').then(setDados).catch(() => setDados({ setores: [], pessoas: [], podeGerenciar: false }));
  useEffect(() => { void carregar(); }, []);
  async function agir(campos: Record<string, string>) {
    try { const r = await acao('portal/equipe.php', campos); setAviso({ t: r.mensagens?.sucesso ?? 'Pronto!', e: false }); void carregar(); } catch (e) { setAviso({ t: msgErro(e), e: true }); }
  }
  if (!dados) return <Carregando />;
  return (
    <ScrollView contentContainerStyle={{ padding: 16, gap: 10 }}>
      <Mensagem texto={aviso.t} erro={aviso.e} />
      <Cartao style={{ gap: 6 }}>
        <T style={{ fontWeight: '800' }}>Setores</T>
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
          {dados.podeGerenciar && !!p.email && <BotaoContorno titulo="✉️ Enviar convite de acesso" aoTocar={() => void agir({ acao: 'enviar_convite_setor', responsavel_id: String(p.id) })} />}
        </Cartao>
      ))}
      {!dados.pessoas.length && <Vazio>Nenhuma pessoa cadastrada.</Vazio>}
      {!dados.podeGerenciar && <T style={{ color: tema.textoSuave, fontSize: 13 }}>Para incluir ou alterar pessoas, peça à Prime para liberar esse recurso.</T>}
      {dados.podeGerenciar && <T style={{ color: tema.textoSuave, fontSize: 13 }}>Para cadastrar uma pessoa nova, use o site em Minha equipe.</T>}
    </ScrollView>
  );
}

// ---------------------------------------------------------------- Usuários (admin)

export function TelaUsuarios() {
  const { tema } = useTema();
  const [itens, setItens] = useState<UsuarioEquipe[] | null>(null);
  const [senha, setSenha] = useState<{ id: number; nome: string; nova: string; confirmar: string } | null>(null);
  const [aviso, setAviso] = useState<{ t: string; e: boolean }>({ t: '', e: false });
  const [atualizando, setAtualizando] = useState(false);
  const carregar = () => api<UsuarioEquipe[]>('/api/usuarios').then(setItens).catch(() => setItens([])).finally(() => setAtualizando(false));
  useEffect(() => { void carregar(); }, []);
  async function agir(campos: Record<string, string>) {
    try { const r = await acao('usuarios.php', campos); setAviso({ t: r.mensagens?.sucesso ?? 'Pronto!', e: false }); setSenha(null); void carregar(); } catch (e) { setAviso({ t: msgErro(e), e: true }); }
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
      <Mensagem texto={aviso.t} erro={aviso.e} />
      {itens.map((u) => (
        <Cartao key={u.id} style={{ gap: 10, opacity: u.ativo ? 1 : 0.6 }}>
          <View style={{ flexDirection: 'row', gap: 12, alignItems: 'center' }}>
            <FotoPessoa caminho={u.foto} nome={u.nome} tamanho={46} />
            <View style={{ flex: 1 }}>
              <T style={{ fontWeight: '700' }}>{u.nome}</T>
              <T style={{ color: tema.textoSuave, fontSize: 13 }} numberOfLines={1}>{u.email}</T>
              <T style={{ color: tema.textoSuave, fontSize: 13 }}>{u.perfil} · {u.ativo ? 'ativo' : 'desativado'}</T>
            </View>
          </View>
          <View style={{ flexDirection: 'row', gap: 8 }}>
            <View style={{ flex: 1 }}><BotaoContorno titulo="🔑 Senha" aoTocar={() => { setAviso({ t: '', e: false }); setSenha({ id: u.id, nome: u.nome, nova: '', confirmar: '' }); }} /></View>
            <View style={{ flex: 1 }}><BotaoContorno titulo={u.ativo ? '⏻ Desativar' : '⏻ Reativar'} aoTocar={() => void agir({ acao: 'alternar_status', usuario_id: String(u.id) })} /></View>
          </View>
        </Cartao>
      ))}
      <T style={{ color: tema.textoSuave, fontSize: 12, textAlign: 'center' }}>Cadastro de usuário novo e edição completa: pelo site, em Usuários.</T>
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
  async function agir(campos: Record<string, string>) {
    try { const r = await acao(arquivo, campos); setAviso({ t: r.mensagens?.sucesso ?? 'Pronto!', e: false }); void carregar(); } catch (e) { setAviso({ t: msgErro(e), e: true }); }
  }
  if (!p) return <Carregando n={3} />;
  return (
    <ScrollView contentContainerStyle={{ padding: 16, gap: 14 }} keyboardShouldPersistTaps="handled">
      <Cartao style={{ gap: 12, alignItems: 'center' }}>
        <FotoPessoa caminho={p.foto} nome={p.nome} tamanho={96} />
        <T style={{ fontWeight: '800', fontSize: 18 }}>{p.nome}</T>
        <T style={{ color: tema.textoSuave, fontSize: 14 }}>Escolha um avatar:</T>
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
        <T style={{ color: tema.textoSuave, fontSize: 12, textAlign: 'center' }}>Para enviar uma foto sua, use o site em Meu perfil.</T>
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

interface Config { valores: Record<string, string>; autorIa: number; usuarios: { id: number; nome: string }[]; timbrado: { cabecalhoAltura: number; rodapeAltura: number } }

export function TelaConfiguracoes() {
  const { tema } = useTema();
  const [cfg, setCfg] = useState<Config | null>(null);
  const [v, setV] = useState<Record<string, string>>({});
  const [aviso, setAviso] = useState<{ t: string; e: boolean }>({ t: '', e: false });
  const [erro, setErro] = useState('');
  useEffect(() => {
    lerJsonPhp<Config>('/configuracoes.php?formato=json').then((c) => { setCfg(c); setV({ ...c.valores, ia_chamados_usuario_id: c.valores.ia_chamados_usuario_id || String(c.autorIa) }); }).catch((e: unknown) => setErro(msgErro(e)));
  }, []);
  if (erro) return <Vazio>{erro}</Vazio>;
  if (!cfg) return <Carregando />;
  const campo = (k: string, r: string) => <Entrada rotulo={r} valor={v[k] ?? ''} aoMudar={(x) => setV({ ...v, [k]: x })} />;
  const segredo = (k: string, r: string) => <EntradaSenha rotulo={r} valor={v[k] ?? ''} aoMudar={(x) => setV({ ...v, [k]: x })} />;
  async function salvar() {
    if (!cfg) return;
    try {
      const r = await acao('configuracoes.php', { ...v, cabecalho_altura_mm: String(cfg.timbrado.cabecalhoAltura), rodape_altura_mm: String(cfg.timbrado.rodapeAltura) });
      setAviso({ t: r.mensagens?.sucesso ?? 'Configurações salvas.', e: false });
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
      <Mensagem texto={aviso.t} erro={aviso.e} />
      <BotaoGradiente titulo="Salvar configurações" aoTocar={() => void salvar()} />
      <T style={{ color: tema.textoSuave, fontSize: 12, textAlign: 'center' }}>Timbrado dos PDFs (imagens): pelo site.</T>
    </ScrollView>
  );
}
