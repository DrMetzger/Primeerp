'use client';

import type { UsuarioLogado, VersoesProtheus } from '@primehub/shared';
import { api } from '@/lib/api';
import {
  BarChart3, BookOpen, Bot, CalendarDays, Code2, LayoutDashboard, LifeBuoy, LogOut, Menu,
  Settings, UserCircle, Users, UsersRound, Video, X, Smartphone, type LucideIcon, Server } from 'lucide-react';
import { usePathname } from 'next/navigation';
import { JanelaApk } from './cartao-apk';
import { type ReactNode, useEffect, useState } from 'react';
import { Assistente, chamarAssistente } from './assistente/assistente';
import { sair, usuarioSalvo } from '@/lib/sessao';
import { TemaBotao } from './tema-botao';

/** Item do menu: tudo é tela do sistema novo. */
interface ItemMenu { rotulo: string; icone: LucideIcon; href: string; cor?: string; somenteAdmin?: boolean; sub?: 'protheus' }

const MENU_EQUIPE: ItemMenu[] = [
  { rotulo: 'Dashboard', icone: LayoutDashboard, cor: '#6366f1', href: '/painel' },
  { rotulo: 'Chamados', icone: LifeBuoy, cor: '#0ea5e9', href: '/painel/chamados' },
  { rotulo: 'Mesa do agente', icone: Bot, cor: '#a855f7', href: '/painel/agente' },
  { rotulo: 'Clientes', icone: Users, cor: '#10b981', href: '/painel/clientes' },
  { rotulo: 'Agenda', icone: CalendarDays, cor: '#f43f5e', href: '/painel/agenda', somenteAdmin: true },
  { rotulo: 'Relatórios', icone: BarChart3, cor: '#84cc16', href: '/painel/relatorios', somenteAdmin: true },
  { rotulo: 'Fontes ADVPL', icone: Code2, cor: '#06b6d4', href: '/painel/fontes', somenteAdmin: true },
  { rotulo: 'Usuários', icone: UsersRound, cor: '#8b5cf6', href: '/painel/usuarios', somenteAdmin: true },
  { rotulo: 'Configurações', icone: Settings, cor: '#94a3b8', href: '/painel/configuracoes', somenteAdmin: true },
  { rotulo: 'Manual', icone: BookOpen, cor: '#f59e0b', href: '/painel/manual' },
  { rotulo: 'Protheus', icone: Server, cor: '#0284c7', href: '/painel/protheus', somenteAdmin: true, sub: 'protheus' },
];

const MENU_CLIENTE: ItemMenu[] = [
  { rotulo: 'Meus chamados', icone: LifeBuoy, cor: '#0ea5e9', href: '/cliente' },
  { rotulo: 'Calendário', icone: CalendarDays, cor: '#f43f5e', href: '/cliente/calendario' },
  { rotulo: 'Videoaulas', icone: Video, cor: '#d946ef', href: '/cliente/videoaulas' },
  { rotulo: 'Fontes ADVPL', icone: Code2, cor: '#06b6d4', href: '/cliente/fontes' },
  { rotulo: 'Minha equipe', icone: UsersRound, cor: '#8b5cf6', href: '/cliente/equipe' },
];

const ADMIN = ['admin', 'diretor', 'ceo'];

function Avatar({ usuario }: { usuario: UsuarioLogado }) {
  const iniciais = usuario.nome.split(' ').slice(0, 2).map((p) => p[0] ?? '').join('').toUpperCase();
  return usuario.foto
    ? <img src={`/${usuario.foto.replace(/^\//, '')}`} alt="" className="h-10 w-10 rounded-full object-cover ring-2 ring-borda" />
    : <span aria-hidden="true" className="gradiente-marca flex h-10 w-10 items-center justify-center rounded-full text-sm font-bold text-white">{iniciais}</span>;
}

export function Casca({ tipo, titulo, children, acoes }: { tipo: 'equipe' | 'cliente'; titulo: string; children: ReactNode; acoes?: ReactNode }) {
  const caminho = usePathname();
  const [usuario, setUsuario] = useState<UsuarioLogado | null>(null);
  const [aberto, setAberto] = useState(false);
  const [janelaApp, setJanelaApp] = useState(false);
  const [versaoProtheus, setVersaoProtheus] = useState('');
  useEffect(() => {
    if (tipo !== 'equipe' || !ADMIN.includes(usuario?.perfil ?? '')) return;
    api<VersoesProtheus>('/api/vm/protheus/versao').then((v) => { if (v.protheus) setVersaoProtheus(`${v.protheus}${v.appserver ? ` · AppServer ${v.appserver}` : ''}`); }).catch(() => undefined);
  }, [tipo, usuario?.perfil]);
  const [abrindo, setAbrindo] = useState<string | null>(null);

  useEffect(() => {
    const u = usuarioSalvo();
    if (!u || u.tipo !== tipo) {
      location.replace(tipo === 'equipe' ? '/login' : '/portal');
      return;
    }
    setUsuario(u);
  }, [tipo]);

  // Gaveta aberta no celular: Esc fecha e a página atrás não rola.
  useEffect(() => {
    if (!aberto) return;
    const fechar = (e: KeyboardEvent) => { if (e.key === 'Escape') setAberto(false); };
    document.addEventListener('keydown', fechar);
    document.body.style.overflow = 'hidden';
    return () => { document.removeEventListener('keydown', fechar); document.body.style.overflow = ''; };
  }, [aberto]);

  const itens = (tipo === 'equipe' ? MENU_EQUIPE : MENU_CLIENTE).filter((i) => !i.somenteAdmin || ADMIN.includes(usuario?.perfil ?? ''));

  function irPara(item: ItemMenu) {
    setAbrindo(item.rotulo);
    location.href = item.href;
  }

  const menu = (
    <nav aria-label="Menu principal" className="flex h-full flex-col">
      <div className="flex items-center gap-3 px-5 pb-6 pt-[max(1.25rem,env(safe-area-inset-top))]">
        <img src="/marca/primeerp-p.png" alt="" className="h-9 w-auto drop-shadow-[0_0_10px_rgba(47,211,229,.45)]" />
        <div>
          <p className="text-lg font-bold leading-tight text-texto">Prime Hub</p>
          <p className="text-xs text-suave">{tipo === 'equipe' ? 'Central da equipe' : usuario?.empresa ?? 'Central do cliente'}</p>
        </div>
      </div>
      <ul className="flex-1 space-y-1 overflow-y-auto px-3">
        {itens.map((item) => {
          const ativo = (item.href === caminho || (item.href !== '/painel' && item.href !== '/cliente' && caminho.startsWith(item.href))
            || (item.href === '/painel/clientes' && caminho === '/painel/cliente') || (item.href === '/painel/chamados' && caminho === '/painel/chamado') || (item.href === '/cliente' && caminho === '/cliente/chamado'));
          const Icone = item.icone;
          return (
            <li key={item.rotulo}>
              <button type="button" onClick={() => irPara(item)} aria-current={ativo ? 'page' : undefined}
                className={`toque group flex w-full items-center gap-3 rounded-xl px-3 text-left font-medium transition ${ativo ? 'gradiente-marca text-white shadow-md' : 'text-suave hover:bg-superficie-alta hover:text-texto'}`}>
                <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg transition" style={ativo ? { background: 'rgb(255 255 255 / .2)', color: '#fff' } : { background: `color-mix(in oklab, ${item.cor ?? '#0ea5e9'} 16%, transparent)`, color: item.cor ?? '#0ea5e9' }}>
                  <Icone size={18} aria-hidden="true" />
                </span>
                <span className="min-w-0 flex-1 leading-tight">
                  {item.rotulo}
                  {item.sub === 'protheus' && versaoProtheus && <span className={`block truncate text-[11px] font-normal ${ativo ? 'text-white/75' : 'text-suave'}`}>{versaoProtheus}</span>}
                </span>
                {abrindo === item.rotulo && !ativo ? <span className="text-xs">abrindo…</span> : null}
              </button>
            </li>
          );
        })}
        <li>
          <button type="button" onClick={() => { setAberto(false); setJanelaApp(true); }}
            className="toque flex w-full items-center gap-3 rounded-xl px-3 text-left font-medium text-suave transition hover:bg-superficie-alta hover:text-texto">
            <span className="flex h-8 w-8 items-center justify-center rounded-lg" style={{ background: '#10b98122', color: '#10b981' }}><Smartphone size={18} aria-hidden="true" /></span>
            <span className="flex-1">Baixar o app</span>
            <span className="rounded-full bg-emerald-500/15 px-2 py-0.5 text-xs font-semibold text-emerald-500">Android</span>
          </button>
        </li>
        <li>
          <button type="button" onClick={() => { setAberto(false); chamarAssistente(); }}
            className="toque flex w-full items-center gap-3 rounded-xl px-3 text-left font-medium text-suave transition hover:bg-superficie-alta hover:text-texto">
            <Bot size={20} aria-hidden="true" />
            <span className="flex-1">Assistente</span>
            <span className="rounded-full bg-destaque/15 px-2 py-0.5 text-xs font-semibold text-destaque">IA</span>
          </button>
        </li>
      </ul>
      {usuario && (
        <div className="border-t border-borda p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
          <div className="flex items-center gap-3 rounded-xl p-2">
            <Avatar usuario={usuario} />
            <div className="min-w-0 flex-1">
              <p className="truncate text-sm font-semibold text-texto">{usuario.nome}</p>
              <p className="truncate text-xs text-suave">{usuario.email}</p>
            </div>
          </div>
          <div className="mt-2 grid grid-cols-2 gap-2">
            <button type="button" onClick={() => irPara({ rotulo: 'Perfil', icone: UserCircle, href: tipo === 'equipe' ? '/painel/perfil' : '/cliente/perfil' })}
              className="toque flex items-center justify-center gap-2 rounded-xl border border-borda text-sm text-texto hover:bg-superficie-alta">
              <UserCircle size={18} aria-hidden="true" /> Perfil
            </button>
            <button type="button" onClick={sair} className="toque flex items-center justify-center gap-2 rounded-xl border border-borda text-sm text-perigo hover:bg-perigo/10">
              <LogOut size={18} aria-hidden="true" /> Sair
            </button>
          </div>
        </div>
      )}
    </nav>
  );

  return (
    <div className="fundo-marca min-h-dvh">
      {/* Menu lateral fixo no computador */}
      <aside className="fixed inset-y-0 left-0 z-30 hidden w-72 border-r border-borda bg-superficie/85 backdrop-blur-xl lg:block">{menu}</aside>

      {/* Gaveta no celular */}
      {aberto && (
        <div className="fixed inset-0 z-40 lg:hidden" role="dialog" aria-modal="true" aria-label="Menu">
          <button type="button" aria-label="Fechar menu" className="absolute inset-0 bg-black/50" onClick={() => setAberto(false)} />
          <aside className="absolute inset-y-0 left-0 w-[85vw] max-w-80 bg-superficie shadow-2xl">
            <button type="button" onClick={() => setAberto(false)} aria-label="Fechar menu" className="toque absolute right-2 top-[max(0.5rem,env(safe-area-inset-top))] flex items-center justify-center text-suave">
              <X size={22} />
            </button>
            {menu}
          </aside>
        </div>
      )}

      <div className="lg:pl-72">
        <header className="sticky top-0 z-20 border-b border-borda bg-fundo/80 pt-[env(safe-area-inset-top)] backdrop-blur-xl">
          <div className="flex items-center gap-3 px-4 py-3 sm:px-6">
            <button type="button" onClick={() => setAberto(true)} className="toque flex items-center justify-center rounded-xl text-texto lg:hidden" aria-label="Abrir menu">
              <Menu size={24} />
            </button>
            <h1 className="min-w-0 flex-1 truncate text-xl font-bold tracking-tight text-texto">{titulo}</h1>
            {acoes}
            {tipo === 'equipe' && (
              <a href="/painel/agente" className="toque inline-flex items-center gap-2 rounded-xl border border-violet-500/40 bg-violet-500/10 px-3 text-sm font-semibold text-violet-500 hover:bg-violet-500/20" aria-label="Ações do Agente">
                <Bot size={18} aria-hidden="true" /> <span className="hidden sm:inline">Ações do Agente</span>
              </a>
            )}
            <TemaBotao />
          </div>
        </header>
        <main className="mx-auto max-w-7xl px-4 pb-[max(2rem,env(safe-area-inset-bottom))] pt-6 sm:px-6">{children}</main>
      </div>
      {usuario && <Assistente />}
      {janelaApp && <JanelaApk aoFechar={() => setJanelaApp(false)} />}
    </div>
  );
}
