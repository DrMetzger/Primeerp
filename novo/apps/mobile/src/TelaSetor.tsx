import type { ChamadoResumo, ListaChamados, SetorDetalhe } from '@primehub/shared';
import { type ComponentType, useCallback, useEffect, useState } from 'react';
import { Linking, Pressable, RefreshControl, ScrollView, View } from 'react-native';
import { acaoPhp, api } from './api';
import { Esqueleto } from './Esqueleto';
import { FotoPessoa } from './FotoPessoa';
import { useTema } from './tema';
import { abrirArquivo, BotaoContorno, dataBR, LogoEmpresa, Vazio } from './Telas';
import { Cartao, T } from './ui';

type Secao = 'chamados' | 'pessoas' | 'documentos' | 'calendario' | 'videos' | 'fontes' | 'propostas' | 'metricas' | 'ia';
const SECOES: [Secao, string, string, string][] = [
  ['chamados', '🎧', 'Chamados', '#0ea5e9'], ['pessoas', '👥', 'Pessoas', '#8b5cf6'], ['documentos', '📄', 'Documentos', '#f59e0b'],
  ['calendario', '📅', 'Calendário', '#10b981'], ['videos', '🎬', 'Vídeo aulas', '#f43f5e'], ['fontes', '💻', 'Fontes', '#06b6d4'],
  ['propostas', '💡', 'Propostas', '#f97316'], ['metricas', '📊', 'Métricas', '#84cc16'], ['ia', '✨', 'IA do setor', '#d946ef'],
];
const STATUS_P: Record<string, string> = { proposta: 'Proposta', aprovada: 'Aprovada', em_andamento: 'Em andamento', concluida: 'Concluída', recusada: 'Recusada' };

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
    <ScrollView contentContainerStyle={{ padding: 16, gap: 12 }} refreshControl={<RefreshControl refreshing={atualizando} onRefresh={() => { setAtualizando(true); void carregar(); }} colors={[tema.primaria]} />}>
      <Cartao style={{ flexDirection: 'row', gap: 14, alignItems: 'center' }}>
        <LogoEmpresa nome={d.cliente.nome} logo={d.cliente.logo} tamanho={56} />
        <View style={{ flex: 1 }}>
          <T style={{ color: tema.textoSuave, fontSize: 13, fontWeight: '700' }}>{d.cliente.nome}</T>
          <T style={{ fontSize: 22, fontWeight: '800' }}>{d.setor.nome}</T>
        </View>
      </Cartao>
      <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 8 }}>
        {SECOES.map(([id, icone, rotulo, cor]) => {
          const sel = secao === id;
          return (
            <Pressable key={id} onPress={() => setSecao(id)} accessibilityRole="tab" accessibilityState={{ selected: sel }}
              style={{ minHeight: 46, borderRadius: 16, paddingHorizontal: 12, flexDirection: 'row', alignItems: 'center', gap: 6, backgroundColor: sel ? cor : tema.superficie, borderWidth: 1, borderColor: sel ? cor : tema.borda }}>
              <T style={{ fontSize: 16 }}>{icone}</T>
              <T style={{ fontWeight: '700', fontSize: 14, color: sel ? '#FFFFFF' : tema.texto }}>{rotulo}{contagem[id] ? ` ${contagem[id]}` : ''}</T>
            </Pressable>
          );
        })}
      </ScrollView>

      {secao === 'chamados' && (chamados?.itens.length ? chamados.itens.map((c) => <CartaoChamado key={c.id} c={c} equipe aoAbrir={() => aoAbrirChamado(c.id)} />) : <Vazio>Nenhum chamado neste setor.</Vazio>)}
      {secao === 'pessoas' && (d.pessoas.length ? d.pessoas.map((p) => (
        <Cartao key={p.id} style={{ flexDirection: 'row', gap: 12, alignItems: 'center' }}>
          <FotoPessoa caminho={p.foto} nome={p.nome} tamanho={44} />
          <View style={{ flex: 1 }}><T style={{ fontWeight: '700' }}>{p.nome}</T><T style={{ color: tema.textoSuave, fontSize: 13 }}>{p.email ?? 'sem e-mail'} · {p.portalAtivo ? 'acesso ativo' : 'sem acesso'}</T></View>
        </Cartao>
      )) : <Vazio>Ninguém neste setor ainda.</Vazio>)}
      {secao === 'documentos' && (d.documentos.length ? d.documentos.map((x) => linha(`d${x.id}`, '📄', x.titulo, [x.categoria, dataBR(x.criadoEm)].filter(Boolean).join(' · '), () => void abrirArquivo(`/download.php?id=${x.id}`))) : <Vazio>Nenhum documento.</Vazio>)}
      {secao === 'calendario' && (d.treinamentos.length ? d.treinamentos.map((t) => linha(`t${t.id}`, { reuniao: '🤝', conversa: '💬', auxilio: '🛟' }[t.tipo ?? ''] ?? '🎓', t.titulo, [dataBR(t.inicio, true), t.status].filter(Boolean).join(' · '), t.link ? () => void Linking.openURL(t.link ?? '') : undefined)) : <Vazio>Nada agendado.</Vazio>)}
      {secao === 'videos' && (d.videoaulas.length ? d.videoaulas.map((v) => linha(`v${v.id}`, '🎬', v.titulo, dataBR(v.criadoEm), () => void abrirArquivo(`/video_aula.php?id=${v.id}`))) : <Vazio>Nenhuma vídeo aula.</Vazio>)}
      {secao === 'fontes' && (d.fontes.length ? d.fontes.map((f) => linha(`f${f.id}`, '💻', f.titulo, [f.pontoEntrada, f.arquivo].filter(Boolean).join(' · '), () => void abrirArquivo(`/download_fonte_advpl.php?id=${f.id}`))) : <Vazio>Nenhuma fonte ADVPL.</Vazio>)}
      {secao === 'propostas' && (d.propostas.length ? d.propostas.map((p) => linha(`p${p.id}`, '💡', p.titulo, `${STATUS_P[p.status] ?? p.status} · ${dataBR(p.criadoEm)}`)) : <Vazio>Nenhuma proposta.</Vazio>)}
      {secao === 'metricas' && (d.kpis.length ? d.kpis.map((k) => {
        const pct = k.meta ? Math.min(100, Math.round((k.valor / k.meta) * 100)) : null;
        const bom = k.meta === null ? true : k.direcao === 'menor' ? k.valor <= k.meta : k.valor >= k.meta;
        return (
          <Cartao key={k.id} style={{ gap: 6 }}>
            <T style={{ fontWeight: '700' }}>{k.nome}</T>
            <T style={{ fontSize: 26, fontWeight: '800' }}>{k.valor.toLocaleString('pt-BR')} <T style={{ fontSize: 14, color: tema.textoSuave }}>{k.unidade}</T></T>
            {pct !== null && <View style={{ height: 8, borderRadius: 4, backgroundColor: tema.superficieAlta, overflow: 'hidden' }}><View style={{ height: 8, width: `${pct}%`, backgroundColor: bom ? tema.sucesso : tema.alerta }} /></View>}
            {k.meta !== null && <T style={{ color: tema.textoSuave, fontSize: 12 }}>Meta {k.meta.toLocaleString('pt-BR')} {k.unidade}</T>}
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
      <T style={{ color: tema.textoSuave, fontSize: 12, textAlign: 'center' }}>Para enviar arquivos e cadastrar, use o site.</T>
    </ScrollView>
  );
}
