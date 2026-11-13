import type { CofreCliente } from '@primehub/shared';
import * as Clipboard from 'expo-clipboard';
import { useCallback, useEffect, useState } from 'react';
import { Linking, Pressable, View } from 'react-native';
import { api, apiForm, ErroApi, URL_SERVIDOR } from './api';
import type { ArquivoEscolhido } from './arquivos';
import { Esqueleto } from './Esqueleto';
import { Acoes, Aviso, confirmar, Folha } from './formularios';
import { useTema } from './tema';
import { BotaoGradiente, dataBR, Entrada, Escolha, Vazio } from './Telas';
import { EntradaSenha } from './Telas2';
import { Cartao, SeletorAnexos, T } from './ui';

/**
 * Aba "Acessos" do cliente no app (cofre de senhas, VPN, links do Protheus e
 * VSCode), igual ao site. A senha só aparece ao tocar em "Mostrar" (fica
 * registrado) e some sozinha depois de 30 segundos.
 */

type Cred = CofreCliente['credenciais'][number];
const COR_TIPO: Record<string, string> = { servidor: '#0ea5e9', rdp: '#6366f1', anydesk: '#ef4444', vpn: '#10b981', protheus: '#f59e0b', sql_server: '#8b5cf6', t_cloud: '#06b6d4', outro: '#64748b' };
const COR_AMB: Record<string, string> = { dev: '#8b5cf6', teste: '#f59e0b', homologacao: '#0ea5e9', producao: '#10b981' };
const LINKS: [keyof CofreCliente['tcloud'][number], string, string][] = [['linkProtheus', 'link_protheus', 'Protheus'], ['linkRest', 'link_rest', 'REST'], ['linkSchedule', 'link_schedule', 'Schedule'], ['linkExclusivo', 'link_exclusivo', 'Modo exclusivo']];

function Linha({ rotulo, valor, children }: { rotulo: string; valor?: string | null; children?: React.ReactNode }) {
  const { tema } = useTema();
  const [copiou, setCopiou] = useState(false);
  if (!valor && !children) return null;
  return (
    <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8, borderRadius: 12, backgroundColor: tema.superficieAlta, paddingHorizontal: 10, minHeight: 42 }}>
      <T style={{ width: 62, fontSize: 11, fontWeight: '800', color: tema.textoSuave }}>{rotulo.toUpperCase()}</T>
      {children ?? (<>
        <T selectable style={{ flex: 1, fontSize: 14, fontFamily: 'monospace' }} numberOfLines={1}>{valor}</T>
        <Pressable hitSlop={8} accessibilityRole="button" accessibilityLabel={`Copiar ${rotulo}`} onPress={() => { void Clipboard.setStringAsync(valor ?? '').then(() => { setCopiou(true); setTimeout(() => setCopiou(false), 1500); }); }}>
          <T style={{ fontSize: 16, color: copiou ? tema.sucesso : tema.primaria }}>{copiou ? '✓' : '⧉'}</T>
        </Pressable>
      </>)}
    </View>
  );
}

function Senha({ clienteId, c }: { clienteId: number; c: Cred }) {
  const { tema } = useTema();
  const [senha, setSenha] = useState<string | null>(null);
  const [erro, setErro] = useState('');
  useEffect(() => { if (senha === null) return; const t = setTimeout(() => setSenha(null), 30_000); return () => clearTimeout(t); }, [senha]);
  if (!c.temSenha) return <T style={{ flex: 1, color: tema.textoSuave, fontSize: 14 }}>sem senha cadastrada</T>;
  async function alternar() {
    if (senha !== null) { setSenha(null); return; }
    setErro('');
    try { setSenha((await api<{ senha: string }>(`/api/clientes/${clienteId}/acessos/credenciais/${c.id}/senha`, { method: 'POST', body: '{}' })).senha); }
    catch (e) { setErro(e instanceof ErroApi ? e.message : 'Não deu.'); }
  }
  return (
    <>
      <T selectable style={{ flex: 1, fontSize: 14, fontFamily: 'monospace' }} numberOfLines={1}>{erro || (senha ?? '••••••••••')}</T>
      {senha !== null && <Pressable hitSlop={8} accessibilityLabel="Copiar senha" onPress={() => void Clipboard.setStringAsync(senha)}><T style={{ fontSize: 16, color: tema.primaria }}>⧉</T></Pressable>}
      <Pressable hitSlop={8} accessibilityRole="button" onPress={() => void alternar()} style={{ paddingHorizontal: 6 }}><T style={{ color: tema.primaria, fontWeight: '800', fontSize: 13 }}>{senha === null ? '👁 Mostrar' : 'Esconder'}</T></Pressable>
    </>
  );
}

type FCred = { id: number | null; tipo: string; titulo: string; host: string; porta: string; usuario: string; senha: string; observacoes: string; temSenha: boolean };

export function SecaoAcessosApp({ clienteId }: { clienteId: number }) {
  const { tema } = useTema();
  const [d, setD] = useState<CofreCliente | null>(null);
  const [erro, setErro] = useState('');
  const [aviso, setAviso] = useState<{ t: string; e: boolean }>({ t: '', e: false });
  const [ocupado, setOcupado] = useState(false);
  const [cred, setCred] = useState<FCred | null>(null);
  const [vpn, setVpn] = useState<{ id: number | null; titulo: string; arquivos: ArquivoEscolhido[]; atual: string } | null>(null);
  const [tc, setTc] = useState<{ tipo: string; link_protheus: string; link_rest: string; link_schedule: string; link_exclusivo: string } | null>(null);
  const [vs, setVs] = useState<{ id: number | null; titulo: string; link: string; porta: string; ambiente: string } | null>(null);
  const base = `/api/clientes/${clienteId}/acessos`;
  const carregar = useCallback(() => api<CofreCliente>(base).then(setD).catch((e: unknown) => setErro(e instanceof Error ? e.message : 'Não consegui abrir os acessos.')), [base]);
  useEffect(() => { void carregar(); }, [carregar]);

  async function agir(f: () => Promise<{ mensagem?: string }>, fechar: () => void): Promise<boolean> {
    setOcupado(true); setAviso({ t: '', e: false });
    try { const r = await f(); setAviso({ t: r.mensagem ?? 'Pronto!', e: false }); fechar(); await carregar(); return true; }
    catch (e) { setAviso({ t: e instanceof Error ? e.message : 'Não deu certo.', e: true }); return false; }
    finally { setOcupado(false); }
  }
  const json = (metodo: string, url: string, corpo?: object) => api<{ mensagem?: string }>(url, { method: metodo, body: JSON.stringify(corpo ?? {}) });
  const excluir = (url: string, nome: string) => void confirmar('Excluir', `Excluir "${nome}"?`, 'Excluir').then((ok) => { if (ok) void agir(() => json('DELETE', url), () => undefined); });
  async function baixar(id: number) {
    try { const { url } = await api<{ url: string }>(`${base}/vpn/${id}/link`, { method: 'POST', body: '{}' }); await Linking.openURL(`${URL_SERVIDOR}${url}`); }
    catch (e) { setAviso({ t: e instanceof Error ? e.message : 'Não deu para baixar.', e: true }); }
  }
  function enviarVpn() {
    if (!vpn) return;
    const corpo = new FormData();
    if (vpn.id) corpo.append('id', String(vpn.id));
    corpo.append('titulo', vpn.titulo);
    const a = vpn.arquivos[0];
    if (a) corpo.append('arquivo', { uri: a.uri, name: a.name, type: a.type } as unknown as Blob);
    void agir(() => apiForm<{ mensagem?: string }>(`${base}/vpn`, corpo), () => setVpn(null));
  }

  if (erro) return <Vazio>{erro}</Vazio>;
  if (!d) return <Esqueleto altura={160} />;
  if (!d.podeVer) {
    return <Cartao style={{ borderColor: tema.alerta, borderWidth: 1, gap: 4 }}><T style={{ fontWeight: '800' }}>🔒 Área restrita</T><T style={{ color: tema.textoSuave, fontSize: 14 }}>Aqui ficam senhas e VPN do cliente. Peça a um administrador para liberar o acesso a dados sensíveis no seu usuário.</T></Cartao>;
  }
  const pode = (meu: boolean) => d.veTudo || meu;
  const titulo = (t: string) => <T style={{ fontWeight: '800', fontSize: 17, marginTop: 6 }}>{t}</T>;

  return (
    <>
      {!d.veTudo && <T style={{ color: tema.textoSuave, fontSize: 13 }}>🔒 Você vê e edita só os acessos que você mesmo cadastrou.</T>}
      {!cred && !vpn && !tc && !vs && <Aviso texto={aviso.t} erro={aviso.e} />}

      {titulo('🌐 Links do Protheus (T-Cloud)')}
      {d.tcloud.map((a) => {
        const cor = COR_AMB[a.tipo] ?? '#0ea5e9';
        const lista = LINKS.filter(([k]) => a[k]);
        return (
          <Cartao key={a.tipo} style={{ gap: 8, borderLeftWidth: 5, borderLeftColor: cor }}>
            <View style={{ flexDirection: 'row', alignItems: 'center' }}>
              <T style={{ flex: 1, fontWeight: '800' }}>{d.ambientesTcloud[a.tipo]}</T>
              <Pressable hitSlop={8} onPress={() => setTc({ tipo: a.tipo, link_protheus: a.linkProtheus ?? '', link_rest: a.linkRest ?? '', link_schedule: a.linkSchedule ?? '', link_exclusivo: a.linkExclusivo ?? '' })}><T style={{ color: tema.primaria, fontWeight: '700' }}>✏️ Editar</T></Pressable>
            </View>
            {lista.length ? (
              <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8 }}>
                {lista.map(([k, , r]) => (
                  <Pressable key={k} onPress={() => void Linking.openURL(String(a[k]))} onLongPress={() => void Clipboard.setStringAsync(String(a[k]))} accessibilityRole="link" accessibilityHint="Segure para copiar o link"
                    style={{ minHeight: 42, paddingHorizontal: 14, borderRadius: 12, backgroundColor: cor, justifyContent: 'center' }}>
                    <T style={{ color: '#fff', fontWeight: '800', fontSize: 14 }}>↗ {r}</T>
                  </Pressable>
                ))}
              </View>
            ) : <T style={{ color: tema.textoSuave, fontSize: 14 }}>Nenhum link ainda.</T>}
          </Cartao>
        );
      })}

      {titulo('🔑 Senhas e acessos')}
      <BotaoGradiente titulo="＋ Novo acesso" aoTocar={() => setCred({ id: null, tipo: 'servidor', titulo: '', host: '', porta: '', usuario: '', senha: '', observacoes: '', temSenha: false })} />
      {!d.credenciais.length ? <Vazio>Nenhum acesso cadastrado.</Vazio> : d.credenciais.map((c) => {
        const cor = COR_TIPO[c.tipo] ?? '#64748b';
        return (
          <Cartao key={c.id} style={{ gap: 8, borderLeftWidth: 5, borderLeftColor: cor }}>
            <T style={{ fontWeight: '800' }}>{c.titulo}</T>
            <T style={{ fontSize: 12, color: tema.textoSuave }}><T style={{ fontSize: 12, fontWeight: '800', color: cor }}>{d.tipos[c.tipo] ?? c.tipo}</T>{c.autor ? ` · por ${c.autor}` : ''} · {dataBR(c.atualizadoEm)}</T>
            <Linha rotulo="Host" valor={c.host && c.porta ? `${c.host}:${c.porta}` : c.host ?? c.porta} />
            <Linha rotulo="Usuário" valor={c.usuario} />
            <Linha rotulo="Senha"><Senha clienteId={clienteId} c={c} /></Linha>
            {!!c.observacoes && <T style={{ color: tema.textoSuave, fontSize: 14 }}>{c.observacoes}</T>}
            {pode(c.meu) && <Acoes itens={[
              { rotulo: '✏️ Editar', aoTocar: () => setCred({ id: c.id, tipo: c.tipo, titulo: c.titulo, host: c.host ?? '', porta: c.porta ?? '', usuario: c.usuario ?? '', senha: '', observacoes: c.observacoes ?? '', temSenha: c.temSenha }) },
              { rotulo: '🗑️ Excluir', perigo: true, aoTocar: () => excluir(`${base}/credenciais/${c.id}`, c.titulo) },
            ]} />}
          </Cartao>
        );
      })}

      {titulo('📶 Arquivos de VPN')}
      <BotaoGradiente titulo="＋ Arquivo de VPN" aoTocar={() => setVpn({ id: null, titulo: '', arquivos: [], atual: '' })} />
      {!d.vpn.length ? <Vazio>Nenhum arquivo de VPN.</Vazio> : d.vpn.map((v) => (
        <Cartao key={v.id} style={{ gap: 8 }}>
          <T style={{ fontWeight: '800' }}>📶 {v.titulo}</T>
          <T style={{ color: tema.textoSuave, fontSize: 13 }}>{v.arquivo}{v.autor ? ` · por ${v.autor}` : ''}</T>
          <Acoes itens={[
            { rotulo: '⬇ Baixar', aoTocar: () => void baixar(v.id) },
            ...(pode(v.meu) ? [{ rotulo: '✏️ Editar', aoTocar: () => setVpn({ id: v.id, titulo: v.titulo, arquivos: [], atual: v.arquivo }) }, { rotulo: '🗑️ Excluir', perigo: true, aoTocar: () => excluir(`${base}/vpn/${v.id}`, v.titulo) }] : []),
          ]} />
        </Cartao>
      ))}

      {titulo('💻 Acesso pelo VSCode')}
      <BotaoGradiente titulo="＋ Acesso pelo VSCode" aoTocar={() => setVs({ id: null, titulo: '', link: '', porta: '', ambiente: '' })} />
      {!d.vscode.length ? <Vazio>Nenhum acesso pelo VSCode.</Vazio> : d.vscode.map((v) => (
        <Cartao key={v.id} style={{ gap: 8 }}>
          <T style={{ fontWeight: '800' }}>💻 {v.titulo}</T>
          <Linha rotulo="Link" valor={v.link} />
          <Linha rotulo="Porta" valor={v.porta} />
          <Linha rotulo="Ambiente" valor={v.ambiente} />
          {pode(v.meu) && <Acoes itens={[
            { rotulo: '✏️ Editar', aoTocar: () => setVs({ id: v.id, titulo: v.titulo, link: v.link ?? '', porta: v.porta ?? '', ambiente: v.ambiente ?? '' }) },
            { rotulo: '🗑️ Excluir', perigo: true, aoTocar: () => excluir(`${base}/vscode/${v.id}`, v.titulo) },
          ]} />}
        </Cartao>
      ))}

      <Folha aberta={!!cred} aoFechar={() => setCred(null)} titulo={cred?.id ? 'Editar acesso' : 'Novo acesso'}
        rodape={cred && <><Aviso texto={aviso.e ? aviso.t : ''} erro /><BotaoGradiente titulo={ocupado ? 'Salvando…' : 'Salvar'} desativado={ocupado || !cred.titulo.trim()}
          aoTocar={() => { const { id, temSenha: _t, ...corpo } = cred; void _t; void agir(() => json(id ? 'PUT' : 'POST', id ? `${base}/credenciais/${id}` : `${base}/credenciais`, corpo), () => setCred(null)); }} /></>}>
        {cred && (<>
          <Escolha rotulo="Tipo" opcoes={Object.entries(d.tipos).map(([valor, rotulo]) => ({ valor, rotulo }))} valor={cred.tipo} aoMudar={(v) => setCred({ ...cred, tipo: v })} />
          <Entrada rotulo="Título *" valor={cred.titulo} aoMudar={(v) => setCred({ ...cred, titulo: v })} dica="Ex.: Servidor de aplicação" />
          <View style={{ flexDirection: 'row', gap: 10 }}>
            <View style={{ flex: 2 }}><Entrada rotulo="Host / IP / ID" valor={cred.host} aoMudar={(v) => setCred({ ...cred, host: v })} /></View>
            <View style={{ flex: 1 }}><Entrada rotulo="Porta" valor={cred.porta} aoMudar={(v) => setCred({ ...cred, porta: v })} /></View>
          </View>
          <Entrada rotulo="Usuário" valor={cred.usuario} aoMudar={(v) => setCred({ ...cred, usuario: v })} />
          <EntradaSenha rotulo={cred.id && cred.temSenha ? 'Senha (em branco mantém a atual)' : 'Senha'} valor={cred.senha} aoMudar={(v) => setCred({ ...cred, senha: v })} />
          <Entrada rotulo="Observações" valor={cred.observacoes} aoMudar={(v) => setCred({ ...cred, observacoes: v })} linhas={3} />
          <T style={{ color: tema.textoSuave, fontSize: 12 }}>🔒 A senha é guardada cifrada. Cada vez que alguém toca em "Mostrar", fica registrado.</T>
        </>)}
      </Folha>
      <Folha aberta={!!vpn} aoFechar={() => setVpn(null)} titulo={vpn?.id ? 'Editar arquivo de VPN' : 'Novo arquivo de VPN'}
        rodape={vpn && <><Aviso texto={aviso.e ? aviso.t : ''} erro /><BotaoGradiente titulo={ocupado ? 'Enviando…' : 'Salvar'} aoTocar={enviarVpn} desativado={ocupado || !vpn.titulo.trim() || (!vpn.id && !vpn.arquivos.length)} /></>}>
        {vpn && (<>
          <Entrada rotulo="Título *" valor={vpn.titulo} aoMudar={(v) => setVpn({ ...vpn, titulo: v })} dica="Ex.: VPN FortiClient" />
          <SeletorAnexos rotulo={vpn.id ? `Trocar o arquivo (atual: ${vpn.atual})` : 'Arquivo *'} tipos={['*/*']} varios={false} arquivos={vpn.arquivos} aoMudar={(a) => setVpn({ ...vpn, arquivos: a })} />
        </>)}
      </Folha>
      <Folha aberta={!!tc} aoFechar={() => setTc(null)} titulo={tc ? `Links de ${d.ambientesTcloud[tc.tipo]}` : ''}
        rodape={tc && <><Aviso texto={aviso.e ? aviso.t : ''} erro /><BotaoGradiente titulo={ocupado ? 'Salvando…' : 'Salvar'} desativado={ocupado} aoTocar={() => { const { tipo, ...corpo } = tc; void agir(() => json('PUT', `${base}/tcloud/${tipo}`, corpo), () => setTc(null)); }} /></>}>
        {tc && LINKS.map(([, campo, r]) => <Entrada key={campo} rotulo={`Link ${r}`} valor={tc[campo as keyof typeof tc]} aoMudar={(v) => setTc({ ...tc, [campo]: v })} dica="https://" />)}
      </Folha>
      <Folha aberta={!!vs} aoFechar={() => setVs(null)} titulo={vs?.id ? 'Editar acesso pelo VSCode' : 'Novo acesso pelo VSCode'}
        rodape={vs && <><Aviso texto={aviso.e ? aviso.t : ''} erro /><BotaoGradiente titulo={ocupado ? 'Salvando…' : 'Salvar'} desativado={ocupado || !vs.titulo.trim()} aoTocar={() => { const { id, ...corpo } = vs; void agir(() => json(id ? 'PUT' : 'POST', id ? `${base}/vscode/${id}` : `${base}/vscode`, corpo), () => setVs(null)); }} /></>}>
        {vs && (<>
          <Entrada rotulo="Título *" valor={vs.titulo} aoMudar={(v) => setVs({ ...vs, titulo: v })} />
          <Entrada rotulo="Link" valor={vs.link} aoMudar={(v) => setVs({ ...vs, link: v })} />
          <View style={{ flexDirection: 'row', gap: 10 }}>
            <View style={{ flex: 1 }}><Entrada rotulo="Porta" valor={vs.porta} aoMudar={(v) => setVs({ ...vs, porta: v })} /></View>
            <View style={{ flex: 1 }}><Entrada rotulo="Ambiente" valor={vs.ambiente} aoMudar={(v) => setVs({ ...vs, ambiente: v })} /></View>
          </View>
        </>)}
      </Folha>
    </>
  );
}
