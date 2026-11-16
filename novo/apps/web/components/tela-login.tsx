'use client';

import type { DesafioCodigo, RespostaLogin, Sessao, TipoLogin } from '@primehub/shared';
import { ArrowLeft, Building2, Eye, EyeOff, Headset, LogIn, MailCheck, ShieldCheck } from 'lucide-react';
import { type ClipboardEvent, type FormEvent, type KeyboardEvent, useEffect, useRef, useState } from 'react';
import { salvarSessao } from '@/lib/sessao';
import { CartaoApk } from './cartao-apk';
import { Logo } from './logo';
import { TemaBotao } from './tema-botao';

const textos: Record<TipoLogin, { titulo: string; subtitulo: string }> = {
  cliente: { titulo: 'Bem-vindo à sua central', subtitulo: 'Abra e acompanhe seus chamados com a PrimeERP' },
  equipe: { titulo: 'Central da equipe', subtitulo: 'Atendimento, chamados e clientes da PrimeERP' },
};

const CHAVE_DISPOSITIVO = 'ph_dispositivo';

function lerDispositivo(): string | undefined {
  try { return localStorage.getItem(CHAVE_DISPOSITIVO) ?? undefined; } catch { return undefined; }
}

/** 6 caixinhas para o código (aceita colar o código inteiro). */
function CaixasCodigo({ valor, aoMudar, aoCompletar }: { valor: string; aoMudar: (v: string) => void; aoCompletar: (v: string) => void }) {
  const refs = useRef<(HTMLInputElement | null)[]>([]);
  useEffect(() => { refs.current[0]?.focus(); }, []);
  function definir(i: number, digito: string) {
    const lista = valor.padEnd(6, ' ').split('');
    lista[i] = digito || ' ';
    const novo = lista.join('').replace(/\s+$/, '');
    aoMudar(novo);
    if (digito && i < 5) refs.current[i + 1]?.focus();
    if (novo.replace(/\D/g, '').length === 6) aoCompletar(novo.replace(/\D/g, ''));
  }
  function colar(e: ClipboardEvent<HTMLInputElement>) {
    const digitos = e.clipboardData.getData('text').replace(/\D/g, '').slice(0, 6);
    if (!digitos) return;
    e.preventDefault();
    aoMudar(digitos);
    refs.current[Math.min(5, digitos.length)]?.focus();
    if (digitos.length === 6) aoCompletar(digitos);
  }
  function tecla(i: number, e: KeyboardEvent<HTMLInputElement>) {
    if (e.key === 'Backspace' && !(valor[i] ?? '').trim() && i > 0) refs.current[i - 1]?.focus();
  }
  return (
    <div className="flex justify-between gap-2" role="group" aria-label="Código de 6 números">
      {Array.from({ length: 6 }, (_, i) => (
        <input key={i} ref={(el) => { refs.current[i] = el; }} inputMode="numeric" autoComplete={i === 0 ? 'one-time-code' : 'off'} maxLength={1}
          aria-label={`Número ${i + 1} do código`} value={(valor[i] ?? '').trim()}
          onChange={(e) => definir(i, e.target.value.replace(/\D/g, '').slice(-1))} onKeyDown={(e) => tecla(i, e)} onPaste={colar}
          className="h-14 w-full min-w-0 rounded-xl border border-borda bg-fundo text-center text-2xl font-bold tabular-nums text-texto outline-none focus:border-primaria focus:ring-2 focus:ring-primaria/30" />
      ))}
    </div>
  );
}

export function TelaLogin({ tipo: tipoInicial }: { tipo: TipoLogin }) {
  const [tipo, setTipo] = useState<TipoLogin>(tipoInicial);
  const [email, setEmail] = useState('');
  const [senha, setSenha] = useState('');
  const [verSenha, setVerSenha] = useState(false);
  const [erro, setErro] = useState('');
  const [aviso, setAviso] = useState('');
  const [enviando, setEnviando] = useState(false);
  const [desafio, setDesafio] = useState<DesafioCodigo | null>(null);
  const [codigo, setCodigo] = useState('');
  const [lembrar, setLembrar] = useState(true);
  const t = textos[tipo];

  function trocarTipo(novo: TipoLogin) {
    setTipo(novo);
    setErro('');
    history.replaceState(null, '', novo === 'cliente' ? '/portal' : '/login');
  }

  function concluir(sessao: Sessao) {
    salvarSessao(sessao);
    if (sessao.dispositivo) { try { localStorage.setItem(CHAVE_DISPOSITIVO, sessao.dispositivo); } catch { /* aba privada */ } }
    location.href = sessao.usuario.tipo === 'equipe' ? '/painel' : '/cliente';
  }

  async function postar<T>(caminho: string, corpo: unknown): Promise<T> {
    const r = await fetch(caminho, { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify(corpo) });
    const dados = (await r.json().catch(() => ({}))) as T & { erro?: string };
    if (!r.ok) throw new Error(dados.erro ?? 'Não foi possível continuar.');
    return dados;
  }

  async function entrar(e: FormEvent) {
    e.preventDefault();
    setErro('');
    setEnviando(true);
    try {
      const dispositivo = lerDispositivo();
      const r = await postar<RespostaLogin>('/api/login', { tipo, email, senha, ...(dispositivo ? { dispositivo } : {}) });
      if ('etapa' in r) { setDesafio(r); setCodigo(''); setEnviando(false); } else concluir(r);
    } catch (falha) {
      setErro(falha instanceof Error ? falha.message : 'Não foi possível entrar.');
      setEnviando(false);
    }
  }

  async function confirmar(valor = codigo) {
    if (!desafio || valor.replace(/\D/g, '').length !== 6) return;
    setErro('');
    setEnviando(true);
    try {
      concluir(await postar<Sessao>('/api/login/codigo', { desafio: desafio.desafio, codigo: valor.replace(/\D/g, ''), lembrar, aparelho: navigator.userAgent.slice(0, 180) }));
    } catch (falha) {
      setErro(falha instanceof Error ? falha.message : 'Código inválido.');
      setCodigo('');
      setEnviando(false);
    }
  }

  async function reenviar(canal: 'email' | 'whatsapp' = 'email') {
    if (!desafio) return;
    setErro('');
    try {
      const novo = await postar<DesafioCodigo>('/api/login/reenviar', { desafio: desafio.desafio, canal });
      setDesafio(novo);
      setCodigo('');
      setAviso(`Enviamos um código novo para ${novo.destino}.`);
    } catch (falha) {
      setErro(falha instanceof Error ? falha.message : 'Não foi possível reenviar.');
    }
  }

  const campo = 'toque w-full rounded-xl border border-borda bg-fundo/80 px-4 text-base text-texto outline-none focus:border-primaria focus:ring-2 focus:ring-primaria/30';

  return (
    <main className="fundo-marca flex min-h-dvh flex-col px-4 pb-[max(1rem,env(safe-area-inset-bottom))] pt-[max(1rem,env(safe-area-inset-top))]">
      <div className="flex justify-end"><TemaBotao /></div>
      <div className="mx-auto flex w-full max-w-md flex-1 flex-col justify-center gap-6 py-6">
        <div className="text-center">
          <Logo className="mx-auto h-14 w-auto" />
          <h1 className="mt-6 text-2xl font-bold tracking-tight text-texto">{desafio ? 'Confirme que é você' : t.titulo}</h1>
          <p className="mt-1 text-suave">{desafio ? 'Verificação em duas etapas' : t.subtitulo}</p>
        </div>

        <div className="rounded-3xl border border-borda bg-superficie/85 p-6 shadow-xl backdrop-blur-xl">
          {!desafio ? (
            <form onSubmit={entrar} className="space-y-4">
              <div role="tablist" aria-label="Tipo de acesso" className="grid grid-cols-2 gap-1 rounded-2xl bg-superficie-alta p-1">
                {([['cliente', 'Sou cliente', Building2], ['equipe', 'Sou da equipe', Headset]] as const).map(([valor, rotulo, Icone]) => (
                  <button key={valor} type="button" role="tab" aria-selected={tipo === valor} onClick={() => trocarTipo(valor)}
                    className={`toque flex items-center justify-center gap-2 rounded-xl px-3 text-sm font-semibold transition ${tipo === valor ? 'gradiente-marca text-white shadow-md' : 'text-suave hover:text-texto'}`}>
                    <Icone size={18} aria-hidden="true" /> {rotulo}
                  </button>
                ))}
              </div>
              <label className="block">
                <span className="mb-1 block text-sm font-medium text-texto">E-mail</span>
                <input type="email" required autoComplete="email" inputMode="email" value={email} onChange={(e) => setEmail(e.target.value)} className={campo} />
              </label>
              <label className="block">
                <span className="mb-1 block text-sm font-medium text-texto">Senha</span>
                <div className="relative">
                  <input type={verSenha ? 'text' : 'password'} required autoComplete="current-password" value={senha} onChange={(e) => setSenha(e.target.value)} className={`${campo} pr-12`} />
                  <button type="button" onClick={() => setVerSenha(!verSenha)} className="toque absolute right-0 top-0 flex items-center justify-center text-suave hover:text-texto"
                    aria-label={verSenha ? 'Esconder senha' : 'Mostrar senha'} aria-pressed={verSenha}>
                    {verSenha ? <EyeOff size={20} /> : <Eye size={20} />}
                  </button>
                </div>
              </label>
              {erro && <p role="alert" className="rounded-xl bg-perigo/10 px-4 py-3 text-sm font-medium text-perigo">{erro}</p>}
              <button type="submit" disabled={enviando} className="gradiente-marca toque flex w-full items-center justify-center gap-2 rounded-xl text-base font-semibold text-white shadow-lg disabled:opacity-60">
                <LogIn size={20} aria-hidden="true" /> {enviando ? 'Entrando…' : 'Entrar'}
              </button>
              <p className="flex items-center justify-center gap-1.5 text-xs text-suave"><ShieldCheck size={14} aria-hidden="true" /> Acesso protegido com verificação em duas etapas</p>
              <p className="text-center text-sm">
                <a href={tipo === 'cliente' ? '/portal/login.php' : '/login.php'} className="text-suave underline-offset-4 hover:underline">Entrar na versão clássica</a>
              </p>
            </form>
          ) : (
            <form onSubmit={(e) => { e.preventDefault(); void confirmar(); }} className="space-y-5">
              <div className="flex items-start gap-3 rounded-2xl bg-superficie-alta p-4">
                <MailCheck size={24} className="mt-0.5 shrink-0 text-destaque" aria-hidden="true" />
                <p className="text-sm text-texto">Enviamos um código de 6 números para <strong>{desafio.destino}</strong>. Ele vale por 10 minutos.</p>
              </div>
              <CaixasCodigo valor={codigo} aoMudar={setCodigo} aoCompletar={(v) => void confirmar(v)} />
              <label className="flex min-h-11 cursor-pointer items-center gap-3 text-sm text-texto">
                <input type="checkbox" checked={lembrar} onChange={(e) => setLembrar(e.target.checked)} className="h-5 w-5 accent-[var(--primaria)]" />
                Lembrar este aparelho por 30 dias
              </label>
              {erro && <p role="alert" className="rounded-xl bg-perigo/10 px-4 py-3 text-sm font-medium text-perigo">{erro}</p>}
              {aviso && !erro && <p role="status" className="rounded-xl bg-sucesso/10 px-4 py-3 text-sm font-medium text-sucesso">{aviso}</p>}
              <button type="submit" disabled={enviando || codigo.replace(/\D/g, '').length !== 6} className="gradiente-marca toque flex w-full items-center justify-center gap-2 rounded-xl text-base font-semibold text-white shadow-lg disabled:opacity-60">
                <ShieldCheck size={20} aria-hidden="true" /> {enviando ? 'Conferindo…' : 'Confirmar'}
              </button>
              <div className="flex items-center justify-between gap-2 text-sm">
                <button type="button" onClick={() => { setDesafio(null); setErro(''); setAviso(''); }} className="toque inline-flex items-center gap-1 text-suave hover:text-texto"><ArrowLeft size={16} aria-hidden="true" /> Voltar</button>
                <button type="button" onClick={() => void reenviar()} className="toque font-medium text-primaria">Reenviar código</button>
              </div>
              <button type="button" onClick={() => void reenviar('whatsapp')} className="toque flex w-full items-center justify-center gap-2 rounded-xl border border-emerald-500/50 bg-emerald-500/10 text-sm font-semibold text-emerald-600 dark:text-emerald-400">
                💬 Receber o código pelo WhatsApp
              </button>
              <p className="text-center text-xs text-suave">Não chegou? Olhe a caixa de Spam / Lixo eletrônico (assunto "Seu código de acesso ao Prime Hub").</p>
            </form>
          )}
        </div>

        <CartaoApk />
      </div>
    </main>
  );
}
