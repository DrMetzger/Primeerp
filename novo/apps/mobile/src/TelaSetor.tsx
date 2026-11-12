import type { ChamadoResumo, ListaChamados, SetorDetalhe } from '@primehub/shared';
import { type ComponentType, useCallback, useEffect, useState } from 'react';
import { Linking, Pressable, RefreshControl, ScrollView, View } from 'react-native';
import { acaoPhp, api } from './api';
import type { ArquivoEscolhido } from './arquivos';
import { Acoes, Aviso, CampoDataHora, confirmar, Folha } from './formularios';
import { Esqueleto } from './Esqueleto';
import { FotoPessoa } from './FotoPessoa';
import { useTema } from './tema';
import { abrirArquivo, BotaoContorno, BotaoGradiente, dataBR, Entrada, Escolha, LogoEmpresa, Vazio } from './Telas';
import { Cartao, SeletorAnexos, T } from './ui';

type Secao = 'chamados' | 'pessoas' | 'documentos' | 'calendario' | 'videos' | 'fontes' | 'propostas' | 'metricas' | 'ia';
const SECOES: [Secao, string, string, string][] = [
  ['chamados', '🎧', 'Chamados', '#0ea5e9'], ['pessoas', '👥', 'Usuários', '#8b5cf6'], ['documentos', '📄', 'Documentos', '#f59e0b'],
  ['calendario', '📅', 'Calendário', '#10b981'], ['videos', '🎬', 'Vídeo aulas', '#f43f5e'], ['fontes', '💻', 'Fontes', '#06b6d4'],
  ['propostas', '💡', 'Propostas', '#f97316'], ['metricas', '📊', 'Métricas', '#84cc16'], ['ia', '✨', 'IA do setor', '#d946ef'],
];
const STATUS_P: Record<string, string> = { proposta: 'Proposta', aprovada: 'Aprovada', em_andamento: 'Em andamento', concluida: 'Concluída', recusada: 'Recusada' };
const TIPOS_T: Record<string, string> = { treinamento: 'Treinamento', reuniao: 'Reunião', conversa: 'Conversa', auxilio: 'Auxílio' };
const STATUS_T: Record<string, string> = { planejado: 'Planejado', em_andamento: 'Em andamento', concluido: 'Concluído', cancelado: 'Cancelado' };
const opc = (r: Record<string, string>) => Object.entries(r).map(([valor, rotulo]) => ({ valor, rotulo }));
const mesAtual = () => new Date().toISOString().slice(0, 7);
const separar = (v: string | null) => (v ? [v.slice(0, 10), v.length > 10 ? v.slice(11, 16) : ''] : ['', '']);

/** Formulário aberto (um por tipo de cadastro). */
type Form =
  | { tipo: 'documento'; titulo: string; categoria: string; descricao: string; arquivos: ArquivoEscolhido[] }
  | { tipo: 'video'; documento_id: string; nome_rotina: string; descricao: string; arquivos: ArquivoEscolhido[] }
  | { tipo: 'fonte'; fonte_id: string; titulo: string; ponto_entrada: string; descricao: string; arquivos: ArquivoEscolhido[] }
  | { tipo: 'proposta'; titulo: string; descricao: string; arquivos: ArquivoEscolhido[] }
  | { tipo: 'kpi'; kpi_id: string; nome_kpi: string; valor: string; meta: string; unidade: string; periodo: string; direcao_melhor: string; observacoes: string }
  | { tipo: 'treinamento'; treinamento_id: string; titulo: string; tipoT: string; status: string; data_inicio: string; hora_inicio: string; data_fim: string; hora_fim: string; link_reuniao: string; responsavel_id: string; descricao: string }
  | { tipo: 'pessoa'; responsavel_id: string; nome: string; email: string; telefone: string; documento: string; setores: number[] };

/** "Pasta" do setor do cliente, igual ao site: cada seção com ícone e cor. */
export function TelaSetor({ clienteId, setorId, aoAbrirChamado, CartaoChamado }: {
  clienteId: number; setorId: number; aoAbrirChamado: (id: number) => void;
  CartaoChamado: ComponentType<{ c: ChamadoResumo; aoAbrir: () => void; equipe: boolean }>;
}) {
  const { tema } = useTema();
  const [d, setD] = useState<SetorDetalhe | null>(null);
  const [chamados, setChamados] = useState<ListaChamados | null>(null);
  const [secao, setSecao] = useState<Secao>('chamados');
  const [atualizando, setAtualizando] = useState(false);
  const [ia, setIa] = useState('');
  const [erro, setErro] = useState('');
  const [form, setForm] = useState<Form | null>(null);
  const [ocupado, setOcupado] = useState(false);
  const [aviso, setAviso] = useState<{ t: string; e: boolean }>({ t: '', e: false });

  const carregar = useCallback(async () => {
    try {
      const [a, b] = await Promise.all([api<SetorDetalhe>(`/api/clientes/${clienteId}/setores/${setorId}`), api<ListaChamados>(`/api/chamados?cliente=${clienteId}&setor=${setorId}&status=`)]);
      setD(a); setChamados(b);
    } catch (e) { setErro(e instanceof Error ? e.message : 'Não consegui abrir o setor.'); }
    setAtualizando(false);
  }, [clienteId, setorId]);
  useEffect(() => { void carregar(); }, [carregar]);

  async function gerarIa() {
    setIa('Analisando chamados e métricas do setor…');
    try {
      const r = await acaoPhp<{ sucesso?: boolean; mensagem?: string }>(`cliente.php?id=${clienteId}`, { acao: 'gerar_sugestao_ia', ajax: '1', setor_id: String(setorId) });
      setIa(r.sucesso === false ? (r.mensagem ?? 'Não deu agora.') : '');
      void carregar();
    } catch { setIa('Não deu para gerar agora. Tente de novo em instantes.'); }
  }

  /** Mesmas ações do site (cliente.php), com as mesmas regras e avisos. */
  async function agir(campos: Record<string, string>, arquivos: ArquivoEscolhido[] = [], okMsg = 'Salvo.'): Promise<boolean> {
    setOcupado(true); setAviso({ t: '', e: false });
    try {
      const r = await acaoPhp<{ ok?: boolean; mensagens?: string[] }>(`cliente.php?id=${clienteId}`, { setor_id: String(setorId), ...campos }, arquivos.length ? { arquivo: arquivos.slice(0, 1) } : {});
      setAviso({ t: r.mensagens?.join(' ') || okMsg, e: false }); setForm(null);
      await carregar();
      return true;
    } catch (e) { setAviso({ t: e instanceof Error ? e.message : 'Não deu certo agora.', e: true }); return false; }
    finally { setOcupado(false); }
  }

  async function salvar() {
    if (!form) return;
    const f = form;
    if (f.tipo === 'documento') await agir({ acao: 'upload_documento', titulo: f.titulo, descricao: f.descricao, categoria: f.categoria }, f.arquivos, 'Documento enviado.');
    if (f.tipo === 'video') {
      if (f.documento_id && !f.arquivos.length) await agir({ acao: 'editar_video_aula', documento_id: f.documento_id, nome_rotina: f.nome_rotina, descricao: f.descricao }, [], 'Vídeo aula atualizada.');
      else if (await agir({ acao: 'upload_video_aula', nome_rotina: f.nome_rotina, descricao: f.descricao }, f.arquivos, 'Vídeo aula enviada.') && f.documento_id) {
        await agir({ acao: 'excluir_video_aula', documento_id: f.documento_id }, [], 'Vídeo aula atualizada.');
      }
    }
    if (f.tipo === 'fonte') await agir({ acao: f.fonte_id ? 'atualizar_fonte_advpl' : 'upload_fonte_advpl', fonte_id: f.fonte_id, titulo: f.titulo, ponto_entrada: f.ponto_entrada, descricao: f.descricao }, f.arquivos, 'Fonte salva.');
    if (f.tipo === 'proposta') await agir({ acao: 'criar_proposta', titulo: f.titulo, descricao: f.descricao }, f.arquivos, 'Proposta criada.');
    if (f.tipo === 'kpi') await agir({ acao: f.kpi_id ? 'editar_kpi' : 'criar_kpi', kpi_id: f.kpi_id, nome_kpi: f.nome_kpi, valor: f.valor.replace(',', '.'), meta: f.meta.replace(',', '.'), unidade: f.unidade, periodo: `${f.periodo}-01`.slice(0, 10), direcao_melhor: f.direcao_melhor, observacoes: f.observacoes }, [], 'Indicador salvo.');
    if (f.tipo === 'treinamento') await agir({ acao: f.treinamento_id ? 'editar_treinamento' : 'criar_treinamento', treinamento_id: f.treinamento_id, tipo: f.tipoT, chamado_id: '', titulo: f.titulo, status: f.status, data_inicio: f.data_inicio, hora_inicio: f.hora_inicio, data_fim: f.data_fim, hora_fim: f.hora_fim, link_reuniao: f.link_reuniao, responsavel_id: f.responsavel_id, descricao: f.descricao }, [], 'Agendamento salvo.');
    if (f.tipo === 'pessoa') {
      const c: Record<string, string> = { acao: f.responsavel_id ? 'editar_usuario_cliente' : 'criar_usuario_cliente', responsavel_id: f.responsavel_id, nome: f.nome, email: f.email, telefone: f.telefone, documento: f.documento };
      (f.setores.length ? f.setores : [setorId]).forEach((x, i) => { c[`setor_ids[${i}]`] = String(x); });
      await agir(c, [], 'Usuário salvo.');
    }
  }

  const prontoParaSalvar = (f: Form): boolean => {
    switch (f.tipo) {
      case 'documento': return !!f.titulo.trim() && f.arquivos.length > 0;
      case 'video': return !!f.nome_rotina.trim() && (!!f.documento_id || f.arquivos.length > 0);
      case 'fonte': return !!f.titulo.trim() && (!!f.fonte_id || f.arquivos.length > 0);
      case 'proposta': return !!f.titulo.trim();
      case 'kpi': return !!f.nome_kpi.trim() && !!f.valor.trim() && /^\d{4}-\d{2}$/.test(f.periodo);
      case 'treinamento': return !!f.titulo.trim() && !!f.data_inicio;
      case 'pessoa': return !!f.nome.trim();
    }
  };

  if (erro) return <Vazio>{erro}</Vazio>;
  if (!d) return <View style={{ padding: 16, gap: 12 }}>{[110, 50, 110, 110].map((a, i) => <Esqueleto key={i} altura={a} />)}</View>;
  const contagem: Record<Secao, number> = {
    chamados: chamados?.itens.filter((c) => c.status !== 'fechado').length ?? 0, pessoas: d.pessoas.length, documentos: d.documentos.length, calendario: d.treinamentos.length,
    videos: d.videoaulas.length, fontes: d.fontes.length, propostas: d.propostas.length, metricas: d.kpis.length, ia: d.sugestoesIa.length,
  };
  const linha = (chave: string, icone: string, titulo: string, sub: string, aoTocar?: () => void) => (
    <Pressable key={chave} onPress={aoTocar} disabled={!aoTocar} accessibilityRole={aoTocar ? 'button' : undefined}>
      <Cartao style={{ flexDirection: 'row', gap: 12, alignItems: 'center' }}>
        <T style={{ fontSize: 24 }}>{icone}</T>
        <View style={{ flex: 1 }}><T style={{ fontWeight: '700' }}>{titulo}</T><T style={{ color: tema.textoSuave, fontSize: 13 }}>{sub}</T></View>
        {aoTocar && <T style={{ color: tema.primaria, fontSize: 20 }}>›</T>}
      </Cartao>
    </Pressable>
  );

  return (
    <>
    <ScrollView contentContainerStyle={{ padding: 16, gap: 12 }} refreshControl={<RefreshControl refreshing={atualizando} onRefresh={() => { setAtualizando(true); void carregar(); }} colors={[tema.primaria]} />}>
      <Cartao style={{ flexDirection: 'row', gap: 14, alignItems: 'center' }}>
        <LogoEmpresa nome={d.cliente.nome} logo={d.cliente.logo} tamanho={56} />
        <View style={{ flex: 1 }}>
          <T style={{ color: tema.textoSuave, fontSize: 13, fontWeight: '700' }}>{d.cliente.nome}</T>
          <T style={{ fontSize: 22, fontWeight: '800' }}>{d.setor.nome}</T>
        </View>
      </Cartao>
      <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8 }}>
        {SECOES.map(([id, icone, rotulo, cor]) => {
          const sel = secao === id;
          return (
            <Pressable key={id} onPress={() => setSecao(id)} accessibilityRole="tab" accessibilityState={{ selected: sel }}
              style={{ minHeight: 42, borderRadius: 14, paddingHorizontal: 11, flexDirection: 'row', alignItems: 'center', gap: 6, backgroundColor: sel ? cor : tema.superficie, borderWidth: 1, borderColor: sel ? cor : tema.borda }}>
              <T style={{ fontSize: 16 }}>{icone}</T>
              <T style={{ fontWeight: '700', fontSize: 14, color: sel ? '#FFFFFF' : tema.texto }}>{rotulo}{contagem[id] ? ` ${contagem[id]}` : ''}</T>
            </Pressable>
          );
        })}
      </View>

      {aviso.t && !form && <Aviso texto={aviso.t} erro={aviso.e} />}
      {secao === 'pessoas' && <BotaoGradiente titulo="＋ Novo usuário" aoTocar={() => setForm({ tipo: 'pessoa', responsavel_id: '', nome: '', email: '', telefone: '', documento: '', setores: [setorId] })} />}
      {secao === 'documentos' && <BotaoGradiente titulo="＋ Enviar documento" aoTocar={() => setForm({ tipo: 'documento', titulo: '', categoria: 'Geral', descricao: '', arquivos: [] })} />}
      {secao === 'calendario' && <BotaoGradiente titulo="＋ Agendar treinamento ou reunião" aoTocar={() => setForm({ tipo: 'treinamento', treinamento_id: '', titulo: '', tipoT: 'treinamento', status: 'planejado', data_inicio: '', hora_inicio: '', data_fim: '', hora_fim: '', link_reuniao: '', responsavel_id: '', descricao: '' })} />}
      {secao === 'videos' && <BotaoGradiente titulo="＋ Enviar vídeo aula" aoTocar={() => setForm({ tipo: 'video', documento_id: '', nome_rotina: '', descricao: '', arquivos: [] })} />}
      {secao === 'fontes' && <BotaoGradiente titulo="＋ Enviar fonte ADVPL" aoTocar={() => setForm({ tipo: 'fonte', fonte_id: '', titulo: '', ponto_entrada: '', descricao: '', arquivos: [] })} />}
      {secao === 'propostas' && <BotaoGradiente titulo="＋ Nova proposta" aoTocar={() => setForm({ tipo: 'proposta', titulo: '', descricao: '', arquivos: [] })} />}
      {secao === 'metricas' && <BotaoGradiente titulo="＋ Novo indicador" aoTocar={() => setForm({ tipo: 'kpi', kpi_id: '', nome_kpi: '', valor: '', meta: '', unidade: '', periodo: mesAtual(), direcao_melhor: 'maior', observacoes: '' })} />}

      {secao === 'chamados' && (chamados?.itens.length ? chamados.itens.map((c) => <CartaoChamado key={c.id} c={c} equipe aoAbrir={() => aoAbrirChamado(c.id)} />) : <Vazio>Nenhum chamado neste setor.</Vazio>)}
      {secao === 'pessoas' && (d.pessoas.length ? d.pessoas.map((p) => (
        <Cartao key={p.id} style={{ gap: 10 }}>
          <View style={{ flexDirection: 'row', gap: 12, alignItems: 'center' }}>
            <FotoPessoa caminho={p.foto} nome={p.nome} tamanho={44} />
            <View style={{ flex: 1 }}><T style={{ fontWeight: '700' }}>{p.nome}</T><T style={{ color: tema.textoSuave, fontSize: 13 }}>{p.email ?? 'sem e-mail'}{p.telefone ? ` · ${p.telefone}` : ''}</T><T style={{ color: p.portalAtivo ? tema.sucesso : tema.textoSuave, fontSize: 12, fontWeight: '700' }}>{p.portalAtivo ? '● acesso ativo' : '○ sem acesso ao portal'}</T></View>
          </View>
          <Acoes itens={[
            { rotulo: '✏️ Editar', aoTocar: () => setForm({ tipo: 'pessoa', responsavel_id: String(p.id), nome: p.nome, email: p.email ?? '', telefone: p.telefone ?? '', documento: p.documento ?? '', setores: p.setorIds?.length ? p.setorIds : [setorId] }) },
            ...(p.email ? [{ rotulo: '✉️ Convite', aoTocar: () => void agir({ acao: 'enviar_convite_setor', responsavel_id: String(p.id) }, [], 'Convite enviado.') },
              { rotulo: '🔑 Nova senha', aoTocar: () => void confirmar('Nova senha', `Mandar para ${p.nome} um link de nova senha?`, 'Mandar', false).then((ok) => ok && agir({ acao: 'resetar_senha_setor', responsavel_id: String(p.id) }, [], 'Link de nova senha enviado.')) }] : []),
            p.portalAtivo
              ? { rotulo: '⛔ Tirar acesso', perigo: true, aoTocar: () => void confirmar('Tirar acesso', `Tirar o acesso de ${p.nome} ao portal?`, 'Tirar').then((ok) => ok && agir({ acao: 'desativar_portal_setor', responsavel_id: String(p.id) }, [], 'Acesso retirado.')) }
              : { rotulo: '🗑️ Excluir', perigo: true, aoTocar: () => void confirmar('Excluir usuário', `Excluir ${p.nome}?`, 'Excluir').then((ok) => ok && agir({ acao: 'excluir_usuario_cliente', responsavel_id: String(p.id) }, [], 'Usuário excluído.')) },
          ]} />
        </Cartao>
      )) : <Vazio>Nenhum usuário neste setor ainda.</Vazio>)}
      {secao === 'documentos' && (d.documentos.length ? d.documentos.map((x) => linha(`d${x.id}`, '📄', x.titulo, [x.categoria, dataBR(x.criadoEm)].filter(Boolean).join(' · '), () => void abrirArquivo(`/download.php?id=${x.id}`))) : <Vazio>Nenhum documento.</Vazio>)}
      {secao === 'calendario' && (d.treinamentos.length ? d.treinamentos.map((t) => (
        <Cartao key={`t${t.id}`} style={{ gap: 10 }}>
          <View style={{ flexDirection: 'row', gap: 12, alignItems: 'center' }}>
            <T style={{ fontSize: 24 }}>{{ reuniao: '🤝', conversa: '💬', auxilio: '🛟' }[t.tipo ?? ''] ?? '🎓'}</T>
            <View style={{ flex: 1 }}><T style={{ fontWeight: '700' }}>{t.titulo}</T><T style={{ color: tema.textoSuave, fontSize: 13 }}>{[dataBR(t.inicio, true), STATUS_T[t.status] ?? t.status].filter(Boolean).join(' · ')}</T></View>
          </View>
          <Acoes itens={[
            ...(t.link ? [{ rotulo: '🎥 Entrar na reunião', aoTocar: () => void Linking.openURL(t.link ?? '') }] : []),
            { rotulo: '✏️ Editar', aoTocar: () => { const [di, hi] = separar(t.inicio); const [df, hf] = separar(t.fim); setForm({ tipo: 'treinamento', treinamento_id: String(t.id), titulo: t.titulo, tipoT: t.tipo ?? 'treinamento', status: t.status, data_inicio: di ?? '', hora_inicio: hi ?? '', data_fim: df ?? '', hora_fim: hf ?? '', link_reuniao: t.link ?? '', responsavel_id: t.responsavelId ? String(t.responsavelId) : '', descricao: t.descricao ?? '' }); } },
            { rotulo: '🗑️ Excluir', perigo: true, aoTocar: () => void confirmar('Excluir agendamento', `Excluir "${t.titulo}"?`, 'Excluir').then((ok) => ok && agir({ acao: 'excluir_treinamento', treinamento_id: String(t.id) }, [], 'Agendamento excluído.')) },
          ]} />
        </Cartao>
      )) : <Vazio>Nada agendado.</Vazio>)}
      {secao === 'videos' && (d.videoaulas.length ? d.videoaulas.map((v) => (
        <Cartao key={`v${v.id}`} style={{ gap: 10 }}>
          <Pressable onPress={() => void abrirArquivo(`/video_aula.php?id=${v.id}`)} accessibilityRole="button" style={{ flexDirection: 'row', gap: 12, alignItems: 'center' }}>
            <View style={{ width: 52, height: 52, borderRadius: 14, backgroundColor: '#f43f5e', alignItems: 'center', justifyContent: 'center' }}><T style={{ fontSize: 22, color: '#fff' }}>▶</T></View>
            <View style={{ flex: 1 }}><T style={{ fontWeight: '700' }}>{v.titulo}</T>{!!v.descricao && <T style={{ color: tema.textoSuave, fontSize: 13 }} numberOfLines={2}>{v.descricao}</T>}<T style={{ color: tema.textoSuave, fontSize: 12 }}>{dataBR(v.criadoEm)}</T></View>
          </Pressable>
          <Acoes itens={[
            { rotulo: '✏️ Editar', aoTocar: () => setForm({ tipo: 'video', documento_id: String(v.id), nome_rotina: v.titulo, descricao: v.descricao ?? '', arquivos: [] }) },
            { rotulo: '🗑️ Excluir', perigo: true, aoTocar: () => void confirmar('Excluir vídeo aula', `Excluir "${v.titulo}"?`, 'Excluir').then((ok) => ok && agir({ acao: 'excluir_video_aula', documento_id: String(v.id) }, [], 'Vídeo aula excluída.')) },
          ]} />
        </Cartao>
      )) : <Vazio>Nenhuma vídeo aula.</Vazio>)}
      {secao === 'fontes' && (d.fontes.length ? d.fontes.map((f) => (
        <Cartao key={`f${f.id}`} style={{ gap: 10 }}>
          <Pressable onPress={() => void abrirArquivo(`/download_fonte_advpl.php?id=${f.id}`)} accessibilityRole="button" style={{ flexDirection: 'row', gap: 12, alignItems: 'center' }}>
            <T style={{ fontSize: 24 }}>💻</T>
            <View style={{ flex: 1 }}><T style={{ fontWeight: '700' }}>{f.titulo}</T><T style={{ color: tema.textoSuave, fontSize: 13 }}>{[f.pontoEntrada, f.arquivo].filter(Boolean).join(' · ')}</T></View>
            <T style={{ color: tema.primaria, fontSize: 18 }}>⬇</T>
          </Pressable>
          <Acoes itens={[
            { rotulo: '✏️ Editar', aoTocar: () => setForm({ tipo: 'fonte', fonte_id: String(f.id), titulo: f.titulo, ponto_entrada: f.pontoEntrada ?? '', descricao: f.descricao ?? '', arquivos: [] }) },
            { rotulo: '🗑️ Excluir', perigo: true, aoTocar: () => void confirmar('Excluir fonte', `Excluir "${f.titulo}"?`, 'Excluir').then((ok) => ok && agir({ acao: 'excluir_fonte_advpl', fonte_id: String(f.id) }, [], 'Fonte excluída.')) },
          ]} />
        </Cartao>
      )) : <Vazio>Nenhuma fonte ADVPL.</Vazio>)}
      {secao === 'propostas' && (d.propostas.length ? d.propostas.map((p) => (
        <Cartao key={`p${p.id}`} style={{ gap: 10 }}>
          <View style={{ flexDirection: 'row', gap: 12, alignItems: 'center' }}>
            <T style={{ fontSize: 24 }}>💡</T>
            <View style={{ flex: 1 }}><T style={{ fontWeight: '700' }}>{p.titulo}</T>{!!p.descricao && <T style={{ color: tema.textoSuave, fontSize: 13 }} numberOfLines={3}>{p.descricao}</T>}<T style={{ color: tema.textoSuave, fontSize: 12 }}>{dataBR(p.criadoEm)}</T></View>
          </View>
          <Escolha rotulo="Situação" opcoes={opc(STATUS_P)} valor={p.status} aoMudar={(v) => void agir({ acao: 'atualizar_status_proposta', proposta_id: String(p.id), status: v }, [], 'Situação atualizada.')} />
          <Acoes itens={[
            ...(p.documentoId ? [{ rotulo: '📎 Documento', aoTocar: () => void abrirArquivo(`/download.php?id=${p.documentoId}`) }] : []),
            { rotulo: '🗑️ Excluir', perigo: true, aoTocar: () => void confirmar('Excluir proposta', `Excluir "${p.titulo}"?`, 'Excluir').then((ok) => ok && agir({ acao: 'excluir_proposta', proposta_id: String(p.id) }, [], 'Proposta excluída.')) },
          ]} />
        </Cartao>
      )) : <Vazio>Nenhuma proposta.</Vazio>)}
      {secao === 'metricas' && (d.kpis.length ? d.kpis.map((k) => {
        const pct = k.meta ? Math.min(100, Math.round((k.valor / k.meta) * 100)) : null;
        const bom = k.meta === null ? true : k.direcao === 'menor' ? k.valor <= k.meta : k.valor >= k.meta;
        return (
          <Cartao key={k.id} style={{ gap: 6 }}>
            <T style={{ fontWeight: '700' }}>{k.nome}</T>
            <T style={{ fontSize: 26, fontWeight: '800' }}>{k.valor.toLocaleString('pt-BR')} <T style={{ fontSize: 14, color: tema.textoSuave }}>{k.unidade}</T></T>
            {pct !== null && <View style={{ height: 8, borderRadius: 4, backgroundColor: tema.superficieAlta, overflow: 'hidden' }}><View style={{ height: 8, width: `${pct}%`, backgroundColor: bom ? tema.sucesso : tema.alerta }} /></View>}
            {k.meta !== null && <T style={{ color: tema.textoSuave, fontSize: 12 }}>Meta {k.meta.toLocaleString('pt-BR')} {k.unidade}{k.periodo ? ` · ${k.periodo.slice(5, 7)}/${k.periodo.slice(0, 4)}` : ''}</T>}
            <Acoes itens={[
              { rotulo: '✏️ Editar', aoTocar: () => setForm({ tipo: 'kpi', kpi_id: String(k.id), nome_kpi: k.nome, valor: String(k.valor), meta: k.meta === null ? '' : String(k.meta), unidade: k.unidade ?? '', periodo: (k.periodo ?? mesAtual()).slice(0, 7), direcao_melhor: k.direcao ?? 'maior', observacoes: k.observacoes ?? '' }) },
              { rotulo: '🗑️ Excluir', perigo: true, aoTocar: () => void confirmar('Excluir indicador', `Excluir "${k.nome}"?`, 'Excluir').then((ok) => ok && agir({ acao: 'excluir_kpi', kpi_id: String(k.id) }, [], 'Indicador excluído.')) },
            ]} />
          </Cartao>
        );
      }) : <Vazio>Nenhum indicador.</Vazio>)}
      {secao === 'ia' && (
        <>
          <BotaoContorno titulo="✨ Gerar análise do setor" aoTocar={() => void gerarIa()} />
          {!!ia && <T style={{ color: tema.textoSuave }}>{ia}</T>}
          {d.sugestoesIa.map((s) => <Cartao key={s.id} style={{ gap: 6 }}><T style={{ color: tema.textoSuave, fontSize: 12 }}>{dataBR(s.criadoEm, true)}</T><T style={{ fontSize: 15, lineHeight: 22 }}>{s.texto.replace(/\*\*/g, '')}</T></Cartao>)}
        </>
      )}
    </ScrollView>
      <Folha aberta={!!form} aoFechar={() => setForm(null)}
        titulo={!form ? '' : { documento: 'Enviar documento', video: form.tipo === 'video' && form.documento_id ? 'Editar vídeo aula' : 'Enviar vídeo aula', fonte: form.tipo === 'fonte' && form.fonte_id ? 'Editar fonte' : 'Enviar fonte ADVPL', proposta: 'Nova proposta', kpi: 'Indicador do setor', treinamento: form.tipo === 'treinamento' && form.treinamento_id ? 'Editar agendamento' : 'Agendar', pessoa: form.tipo === 'pessoa' && form.responsavel_id ? 'Editar usuário' : 'Novo usuário' }[form.tipo]}
        rodape={form && <><Aviso texto={aviso.e ? aviso.t : ''} erro /><BotaoGradiente titulo={ocupado ? 'Salvando…' : 'Salvar'} aoTocar={() => void salvar()} desativado={ocupado || !prontoParaSalvar(form)} /></>}>
        {form?.tipo === 'documento' && (<>
          <Entrada rotulo="Título *" valor={form.titulo} aoMudar={(v) => setForm({ ...form, titulo: v })} />
          <Entrada rotulo="Categoria" valor={form.categoria} aoMudar={(v) => setForm({ ...form, categoria: v })} />
          <Entrada rotulo="Descrição" valor={form.descricao} aoMudar={(v) => setForm({ ...form, descricao: v })} linhas={3} />
          <SeletorAnexos rotulo="Arquivo *" varios={false} arquivos={form.arquivos} aoMudar={(a) => setForm({ ...form, arquivos: a })} />
        </>)}
        {form?.tipo === 'video' && (<>
          <Entrada rotulo="Título (rotina / assunto) *" valor={form.nome_rotina} aoMudar={(v) => setForm({ ...form, nome_rotina: v })} dica="Ex.: MATA410 - Pedido de venda" />
          <Entrada rotulo="Descrição" valor={form.descricao} aoMudar={(v) => setForm({ ...form, descricao: v })} linhas={3} dica="O que a pessoa aprende nesse vídeo" />
          <SeletorAnexos rotulo={form.documento_id ? 'Trocar o vídeo (opcional)' : 'Vídeo *'} varios={false} arquivos={form.arquivos} aoMudar={(a) => setForm({ ...form, arquivos: a })} />
        </>)}
        {form?.tipo === 'fonte' && (<>
          <Entrada rotulo="Título *" valor={form.titulo} aoMudar={(v) => setForm({ ...form, titulo: v })} />
          <Entrada rotulo="Ponto de entrada" valor={form.ponto_entrada} aoMudar={(v) => setForm({ ...form, ponto_entrada: v })} />
          <Entrada rotulo="Descrição" valor={form.descricao} aoMudar={(v) => setForm({ ...form, descricao: v })} linhas={3} />
          <SeletorAnexos rotulo={form.fonte_id ? 'Trocar arquivo (opcional)' : 'Arquivo .prw / .tlpp *'} tipos={['*/*']} varios={false} arquivos={form.arquivos} aoMudar={(a) => setForm({ ...form, arquivos: a })} />
        </>)}
        {form?.tipo === 'proposta' && (<>
          <Entrada rotulo="Título *" valor={form.titulo} aoMudar={(v) => setForm({ ...form, titulo: v })} />
          <Entrada rotulo="Descrição" valor={form.descricao} aoMudar={(v) => setForm({ ...form, descricao: v })} linhas={4} />
          <SeletorAnexos rotulo="Documento (opcional)" varios={false} arquivos={form.arquivos} aoMudar={(a) => setForm({ ...form, arquivos: a })} />
        </>)}
        {form?.tipo === 'kpi' && (<>
          <Entrada rotulo="Nome do indicador *" valor={form.nome_kpi} aoMudar={(v) => setForm({ ...form, nome_kpi: v })} />
          <View style={{ flexDirection: 'row', gap: 10 }}>
            <View style={{ flex: 1 }}><Entrada rotulo="Valor *" valor={form.valor} aoMudar={(v) => setForm({ ...form, valor: v })} /></View>
            <View style={{ flex: 1 }}><Entrada rotulo="Meta" valor={form.meta} aoMudar={(v) => setForm({ ...form, meta: v })} /></View>
          </View>
          <View style={{ flexDirection: 'row', gap: 10 }}>
            <View style={{ flex: 1 }}><Entrada rotulo="Unidade" valor={form.unidade} aoMudar={(v) => setForm({ ...form, unidade: v })} dica="%, R$, dias…" /></View>
            <View style={{ flex: 1 }}><Entrada rotulo="Mês (AAAA-MM) *" valor={form.periodo} aoMudar={(v) => setForm({ ...form, periodo: v })} dica={mesAtual()} /></View>
          </View>
          <Escolha rotulo="Melhor quando" opcoes={[{ valor: 'maior', rotulo: '📈 Maior' }, { valor: 'menor', rotulo: '📉 Menor' }]} valor={form.direcao_melhor} aoMudar={(v) => setForm({ ...form, direcao_melhor: v })} />
          <Entrada rotulo="Observações" valor={form.observacoes} aoMudar={(v) => setForm({ ...form, observacoes: v })} linhas={2} />
        </>)}
        {form?.tipo === 'treinamento' && (<>
          <Entrada rotulo="Título *" valor={form.titulo} aoMudar={(v) => setForm({ ...form, titulo: v })} />
          <Escolha rotulo="Tipo" opcoes={opc(TIPOS_T)} valor={form.tipoT} aoMudar={(v) => setForm({ ...form, tipoT: v })} />
          <Escolha rotulo="Situação" opcoes={opc(STATUS_T)} valor={form.status} aoMudar={(v) => setForm({ ...form, status: v })} />
          <CampoDataHora rotulo="Início *" data={form.data_inicio} hora={form.hora_inicio} aoMudar={(dt, h) => setForm({ ...form, data_inicio: dt, hora_inicio: h })} />
          <CampoDataHora rotulo="Fim" opcional data={form.data_fim} hora={form.hora_fim} aoMudar={(dt, h) => setForm({ ...form, data_fim: dt, hora_fim: h })} />
          <Entrada rotulo="Link da reunião" valor={form.link_reuniao} aoMudar={(v) => setForm({ ...form, link_reuniao: v })} dica="https://meet.google.com/…" />
          <Escolha rotulo="Responsável na Prime" opcoes={[{ valor: '', rotulo: 'Ninguém' }, ...d.equipe.map((u) => ({ valor: String(u.id), rotulo: u.nome }))]} valor={form.responsavel_id} aoMudar={(v) => setForm({ ...form, responsavel_id: v })} />
          <Entrada rotulo="Descrição" valor={form.descricao} aoMudar={(v) => setForm({ ...form, descricao: v })} linhas={3} />
        </>)}
        {form?.tipo === 'pessoa' && (<>
          <Entrada rotulo="Nome *" valor={form.nome} aoMudar={(v) => setForm({ ...form, nome: v })} />
          <Entrada rotulo="E-mail (para acessar o portal)" valor={form.email} aoMudar={(v) => setForm({ ...form, email: v })} />
          <Entrada rotulo="Telefone / WhatsApp" valor={form.telefone} aoMudar={(v) => setForm({ ...form, telefone: v })} dica="(17) 99999-9999" />
          <Entrada rotulo="CPF" valor={form.documento} aoMudar={(v) => setForm({ ...form, documento: v })} />
        </>)}
      </Folha>
    </>
  );
}
