'use client';

import type { Sessao, TipoLogin } from '@primehub/shared';
import { Eye, EyeOff, LogIn } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { salvarSessao } from '@/lib/sessao';
import { Assistente } from './assistente/assistente';
import { CartaoApk } from './cartao-apk';
import { TemaBotao } from './tema-botao';

const textos: Record<TipoLogin, { titulo: string; subtitulo: string; outro: { href: string; texto: string } }> = {
  equipe: { titulo: 'Central da equipe', subtitulo: 'Atendimento, chamados e clientes da PrimeERP', outro: { href: '/portal', texto: 'Sou cliente' } },
  cliente: { titulo: 'Central do cliente', subtitulo: 'Abra e acompanhe seus chamados com a PrimeERP', outro: { href: '/login', texto: 'Sou da equipe PrimeERP' } },
};

export function TelaLogin({ tipo }: { tipo: TipoLogin }) {
  const [email, setEmail] = useState('');
  const [senha, setSenha] = useState('');
  const [verSenha, setVerSenha] = useState(false);
  const [erro, setErro] = useState('');
  const [enviando, setEnviando] = useState(false);
  const t = textos[tipo];

  async function entrar(e: FormEvent) {
    e.preventDefault();
    setErro('');
    setEnviando(true);
    try {
      const r = await fetch('/api/login', { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ tipo, email, senha }) });
      const corpo = (await r.json()) as Sessao & { erro?: string };
      if (!r.ok) throw new Error(corpo.erro ?? 'Não foi possível entrar.');
      salvarSessao(corpo);
      location.href = tipo === 'equipe' ? '/painel' : '/cliente';
    } catch (falha) {
      setErro(falha instanceof Error ? falha.message : 'Não foi possível entrar.');
      setEnviando(false);
    }
  }

  return (
    <main className="fundo-marca flex min-h-dvh flex-col px-4 pb-[max(1rem,env(safe-area-inset-bottom))] pt-[max(1rem,env(safe-area-inset-top))]">
      <div className="flex justify-end"><TemaBotao /></div>
      <div className="mx-auto flex w-full max-w-md flex-1 flex-col justify-center gap-6 py-6">
        <div className="text-center">
          <img src="/marca/primeerp-logo.png" alt="PrimeERP Soluções em Tecnologia" className="mx-auto h-14 w-auto" />
          <h1 className="mt-6 text-2xl font-bold tracking-tight text-texto">{t.titulo}</h1>
          <p className="mt-1 text-suave">{t.subtitulo}</p>
        </div>

        <form onSubmit={entrar} className="space-y-4 rounded-3xl border border-borda bg-superficie/90 p-6 shadow-xl backdrop-blur">
          <label className="block">
            <span className="mb-1 block text-sm font-medium text-texto">E-mail</span>
            <input type="email" required autoComplete="email" inputMode="email" value={email} onChange={(e) => setEmail(e.target.value)}
              className="toque w-full rounded-xl border border-borda bg-fundo px-4 text-base text-texto outline-none focus:border-primaria" />
          </label>
          <label className="block">
            <span className="mb-1 block text-sm font-medium text-texto">Senha</span>
            <div className="relative">
              <input type={verSenha ? 'text' : 'password'} required autoComplete="current-password" value={senha} onChange={(e) => setSenha(e.target.value)}
                className="toque w-full rounded-xl border border-borda bg-fundo px-4 pr-12 text-base text-texto outline-none focus:border-primaria" />
              <button type="button" onClick={() => setVerSenha(!verSenha)} className="toque absolute right-0 top-0 flex items-center justify-center text-suave"
                aria-label={verSenha ? 'Esconder senha' : 'Mostrar senha'}>
                {verSenha ? <EyeOff size={20} /> : <Eye size={20} />}
              </button>
            </div>
          </label>
          {erro && <p role="alert" className="rounded-xl bg-perigo/10 px-4 py-3 text-sm font-medium text-perigo">{erro}</p>}
          <button type="submit" disabled={enviando}
            className="gradiente-marca toque flex w-full items-center justify-center gap-2 rounded-xl text-base font-semibold text-white shadow-lg disabled:opacity-60">
            <LogIn size={20} aria-hidden="true" /> {enviando ? 'Entrando…' : 'Entrar'}
          </button>
          <p className="text-center text-sm">
            <a href={tipo === 'cliente' ? '/portal/login.php' : '/login.php'} className="text-suave underline-offset-4 hover:underline">
              Entrar na versão clássica
            </a>
          </p>
        </form>

        <CartaoApk />
        <p className="text-center text-sm"><a href={t.outro.href} className="toque inline-flex items-center font-medium text-primaria">{t.outro.texto}</a></p>
      </div>
      <Assistente modo="login" />
    </main>
  );
}
