'use client';

import { Bot, FileText, Mail, MessageCircle, Save } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import { Casca } from '@/components/casca';
import { Esqueleto } from '@/components/esqueleto';
import { CampoSenha } from '@/components/ui/campo-senha';
import { Aviso, BotaoPrimario, CLASSE_AREA, CLASSE_ENTRADA, Campo, EscolherArquivo, Painel } from '@/components/ui/basico';
import { api, arquivoPublico, enviarAcao, lerJsonPhp } from '@/lib/api';

interface Config {
  valores: Record<string, string>;
  autorIa: number;
  usuarios: { id: number; nome: string }[];
  timbrado: { cabecalho: string | null; rodape: string | null; cabecalhoAltura: number; rodapeAltura: number };
}

function Secao({ icone, titulo, texto, children }: { icone: ReactNode; titulo: string; texto: string; children: ReactNode }) {
  return (
    <Painel>
      <div className="mb-4 flex items-start gap-3">
        <span className="gradiente-marca flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-white">{icone}</span>
        <div><h2 className="font-semibold text-texto">{titulo}</h2><p className="text-sm text-suave">{texto}</p></div>
      </div>
      <div className="grid gap-4 sm:grid-cols-2">{children}</div>
    </Painel>
  );
}

export default function PaginaConfiguracoes() {
  const [cfg, setCfg] = useState<Config | null>(null);
  const [v, setV] = useState<Record<string, string>>({});
  const [alturas, setAlturas] = useState({ cabecalho_altura_mm: '28', rodape_altura_mm: '16' });
  const [timbrado, setTimbrado] = useState<{ cabecalho: File | null; rodape: File | null }>({ cabecalho: null, rodape: null });
  const [erro, setErro] = useState('');
  const [aviso, setAviso] = useState<{ texto: string; erro: boolean }>({ texto: '', erro: false });
  const [salvando, setSalvando] = useState(false);

  useEffect(() => {
    lerJsonPhp<Config>('/configuracoes.php?formato=json').then((c) => {
      setCfg(c);
      setV({ ...c.valores, ia_chamados_usuario_id: c.valores.ia_chamados_usuario_id || String(c.autorIa) });
      setAlturas({ cabecalho_altura_mm: String(c.timbrado.cabecalhoAltura), rodape_altura_mm: String(c.timbrado.rodapeAltura) });
    }).catch((e: unknown) => setErro(e instanceof Error ? e.message : 'Não consegui abrir as configurações.'));
  }, []);

  const campo = (k: string) => ({ value: v[k] ?? '', onChange: (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) => setV((x) => ({ ...x, [k]: e.target.value })) });
  const senha = (k: string, rotulo: string) => <CampoSenha rotulo={rotulo} valor={v[k] ?? ''} aoMudar={(n) => setV((x) => ({ ...x, [k]: n }))} autoComplete="off" />;

  async function salvar(e: React.FormEvent) {
    e.preventDefault();
    setSalvando(true); setAviso({ texto: '', erro: false });
    try {
      // Manda TODOS os campos: a tela de sempre grava todos de uma vez.
      const r = await enviarAcao('configuracoes.php', { ...v, ...alturas }, { timbrado_cabecalho: timbrado.cabecalho, timbrado_rodape: timbrado.rodape });
      setAviso({ texto: r.mensagens.sucesso ?? 'Configurações salvas.', erro: false });
    } catch (ex) { setAviso({ texto: ex instanceof Error ? ex.message : 'Não deu pra salvar.', erro: true }); }
    setSalvando(false);
  }

  return (
    <Casca tipo="equipe" titulo="Configurações">
      {erro && <Aviso texto={erro} erro />}
      {!cfg && !erro && <div role="status" aria-label="Carregando" className="space-y-4"><Esqueleto className="h-56" /><Esqueleto className="h-72" /><Esqueleto className="h-56" /></div>}
      {cfg && (
        <form onSubmit={(e) => void salvar(e)} className="space-y-4">
          <Secao icone={<MessageCircle size={20} />} titulo="WhatsApp (Meta)" texto="Credenciais da API oficial do WhatsApp usada no bot de chamados e nos avisos.">
            {senha('whatsapp_access_token', 'Access Token')}
            <Campo rotulo="Phone Number ID"><input {...campo('whatsapp_phone_number_id')} className={CLASSE_ENTRADA} autoComplete="off" /></Campo>
            {senha('whatsapp_app_secret', 'App Secret')}
            <Campo rotulo="Telefone que recebe as notificações"><input {...campo('whatsapp_notificar_telefone')} className={CLASSE_ENTRADA} inputMode="tel" /></Campo>
          </Secao>

          <Secao icone={<Mail size={20} />} titulo="E-mail (SMTP)" texto="Caixa usada pra enviar avisos de chamado/treinamento, convites e o código de verificação do login.">
            <Campo rotulo="Servidor SMTP (host)"><input {...campo('smtp_host')} className={CLASSE_ENTRADA} placeholder="smtp.hostinger.com" /></Campo>
            <div className="grid grid-cols-2 gap-3">
              <Campo rotulo="Porta"><input type="number" {...campo('smtp_port')} className={CLASSE_ENTRADA} /></Campo>
              <Campo rotulo="Segurança"><select {...campo('smtp_secure')} className={CLASSE_ENTRADA}><option value="ssl">SSL</option><option value="tls">TLS</option></select></Campo>
            </div>
            <Campo rotulo="Usuário (também é o remetente)"><input type="email" {...campo('smtp_user')} className={CLASSE_ENTRADA} autoComplete="off" /></Campo>
            {senha('smtp_pass', 'Senha')}
            <Campo rotulo="Nome do remetente"><input {...campo('smtp_nome_remetente')} className={CLASSE_ENTRADA} /></Campo>
            <div className="sm:col-span-2"><Campo rotulo="E-mails da equipe que recebem aviso de todo chamado/treinamento novo" dica="Um e-mail por linha."><textarea rows={3} {...campo('notificacao_emails_equipe')} className={CLASSE_AREA} /></Campo></div>
          </Secao>

          <Secao icone={<Bot size={20} />} titulo="Agente Claude (Mesa do agente)" texto="Sessões do Claude passam pelos chamados em horário comercial, preparam análise, plano, fontes e resposta, e deixam tudo na Mesa do agente. Nada vai ao cliente sem alguém da equipe aprovar.">
            <TokenAgente />
          </Secao>
          <Secao icone={<Bot size={20} />} titulo="IA nos chamados (Gemini)" texto="Só o botão Sugerir resposta dentro do chamado (nunca envia). O atendimento automático agora é o agente Claude, revisado pela equipe.">
            {senha('gemini_api_key', 'Chave de análise')}
            {senha('gemini_api_key_busca', 'Chave de busca')}
            <Campo rotulo="Modo">
              <select {...campo('ia_chamados_modo')} className={CLASSE_ENTRADA}>
                <option value="sugestao">Sugestão: só a equipe vê e decide se envia</option>
                <option value="automatico">Automático: já responde o cliente sozinha</option>
                <option value="desligado">Desligado</option>
              </select>
            </Campo>
            <Campo rotulo="Responder em nome de" dica="As respostas saem com o nome e a foto dessa pessoa. O cliente não vê nenhuma menção a IA.">
              <select {...campo('ia_chamados_usuario_id')} className={CLASSE_ENTRADA}>{cfg.usuarios.map((u) => <option key={u.id} value={u.id}>{u.nome}</option>)}</select>
            </Campo>
            <Campo rotulo="Máximo de análises por dia"><input type="number" min={0} {...campo('ia_chamados_limite_dia')} className={CLASSE_ENTRADA} /></Campo>
            <Campo rotulo="Máximo de buscas no TDN/User Function por dia"><input type="number" min={0} {...campo('ia_chamados_limite_busca_dia')} className={CLASSE_ENTRADA} /></Campo>
          </Secao>

          <Secao icone={<FileText size={20} />} titulo="Timbrado dos PDFs" texto="Cabeçalho e rodapé de todo PDF gerado pelo sistema. Sem imagem, o sistema desenha um timbrado padrão.">
            {(['cabecalho', 'rodape'] as const).map((p) => (
              <div key={p} className="space-y-3">
                <p className="text-sm font-semibold text-texto">{p === 'cabecalho' ? 'Cabeçalho' : 'Rodapé'}</p>
                {cfg.timbrado[p] && <img src={arquivoPublico(cfg.timbrado[p]) ?? ''} alt={`${p === 'cabecalho' ? 'Cabeçalho' : 'Rodapé'} atual`} className="max-h-20 rounded-lg border border-borda bg-white" />}
                <EscolherArquivo rotulo="Trocar imagem" aceita="image/png,image/jpeg" aoEscolher={(f) => setTimbrado((t) => ({ ...t, [p]: f[0] ?? null }))} nomes={timbrado[p] ? [timbrado[p].name] : []} />
                <Campo rotulo="Altura (mm)">
                  <input type="number" step="0.5" min={5} max={60} value={alturas[`${p}_altura_mm`]} onChange={(e) => setAlturas((a) => ({ ...a, [`${p}_altura_mm`]: e.target.value }))} className={CLASSE_ENTRADA} />
                </Campo>
              </div>
            ))}
          </Secao>

          <div className="sticky bottom-[max(1rem,env(safe-area-inset-bottom))] z-10 flex flex-col gap-3 rounded-3xl border border-borda bg-superficie/95 p-3 shadow-lg backdrop-blur sm:flex-row sm:items-center">
            <div className="flex-1"><Aviso texto={aviso.texto} erro={aviso.erro} /></div>
            <BotaoPrimario type="submit" disabled={salvando}><Save size={18} aria-hidden="true" /> {salvando ? 'Salvando…' : 'Salvar configurações'}</BotaoPrimario>
          </div>
        </form>
      )}
    </Casca>
  );
}

/** Gera o token que as sessões do agente usam (mostrado uma vez só). */
function TokenAgente() {
  const [token, setToken] = useState('');
  const [erro, setErro] = useState('');
  const [copiado, setCopiado] = useState(false);
  async function gerar() {
    if (!confirm('Gerar um token novo? O anterior para de funcionar na hora.')) return;
    setErro('');
    try { setToken((await api<{ token: string }>('/api/agente/token', { method: 'POST', body: '{}' })).token); } catch (e) { setErro(e instanceof Error ? e.message : 'Não deu.'); }
  }
  return (
    <div className="space-y-3">
      {token ? (
        <div className="space-y-2 rounded-2xl border border-alerta/50 bg-alerta/10 p-3">
          <p className="text-sm font-semibold text-texto">Copie agora e cole no formulário seguro do Claude (ele não aparece de novo):</p>
          <div className="flex gap-2">
            <input readOnly value={token} className={`${CLASSE_ENTRADA} font-mono text-xs`} onFocus={(e) => e.currentTarget.select()} aria-label="Token do agente" />
            <BotaoPrimario onClick={() => { void navigator.clipboard.writeText(token).then(() => setCopiado(true)); }}>{copiado ? 'Copiado' : 'Copiar'}</BotaoPrimario>
          </div>
        </div>
      ) : <BotaoPrimario onClick={() => void gerar()}>Gerar token do agente</BotaoPrimario>}
      <Aviso texto={erro} erro />
    </div>
  );
}
