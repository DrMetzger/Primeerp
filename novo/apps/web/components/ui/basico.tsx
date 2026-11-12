import type { ReactNode } from 'react';

export function Painel({ titulo, children, className = '', acao }: { titulo?: string | undefined; children: ReactNode; className?: string; acao?: ReactNode }) {
  return (
    <section className={`rounded-3xl border border-borda bg-superficie p-5 shadow-sm ${className}`}>
      {(titulo || acao) && (
        <div className="mb-4 flex items-center gap-3">
          {titulo && <h2 className="flex-1 text-base font-semibold text-texto">{titulo}</h2>}
          {acao}
        </div>
      )}
      {children}
    </section>
  );
}

export function Vazio({ children }: { children: ReactNode }) {
  return <p className="rounded-2xl border border-dashed border-borda p-8 text-center text-suave">{children}</p>;
}

export function Campo({ rotulo, children, dica }: { rotulo: string; children: ReactNode; dica?: string }) {
  return (
    <label className="block space-y-1.5">
      <span className="text-sm font-semibold text-texto">{rotulo}</span>
      {children}
      {dica && <span className="block text-xs text-suave">{dica}</span>}
    </label>
  );
}

export const CLASSE_ENTRADA = 'toque w-full rounded-xl border border-borda bg-fundo px-3 text-base text-texto outline-none focus:border-primaria';

export function BotaoPrimario({ children, ...props }: React.ButtonHTMLAttributes<HTMLButtonElement>) {
  return (
    <button type="button" {...props} className={`gradiente-marca toque inline-flex items-center justify-center gap-2 rounded-xl px-5 font-semibold text-white shadow disabled:opacity-60 ${props.className ?? ''}`}>
      {children}
    </button>
  );
}

export function BotaoSecundario({ children, ...props }: React.ButtonHTMLAttributes<HTMLButtonElement>) {
  return (
    <button type="button" {...props} className={`toque inline-flex items-center justify-center gap-2 rounded-xl border border-borda bg-superficie px-4 font-semibold text-texto hover:bg-superficie-alta disabled:opacity-60 ${props.className ?? ''}`}>
      {children}
    </button>
  );
}

export function dataBR(valor: string | null | undefined, comHora = false): string {
  if (!valor) return '—';
  const d = new Date(valor.replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return valor;
  return comHora ? d.toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' }) : d.toLocaleDateString('pt-BR');
}

export function tamanhoArquivo(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

/** Janela por cima da tela: no celular sobe de baixo, no PC fica no meio. Esc fecha. */
export function Modal({ titulo, aoFechar, children, largura = 'max-w-2xl' }: { titulo: string; aoFechar: () => void; children: ReactNode; largura?: string }) {
  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center sm:items-center" role="dialog" aria-modal="true" aria-label={titulo}
      onKeyDown={(e) => { if (e.key === 'Escape') aoFechar(); }}>
      <button type="button" aria-label="Fechar" className="absolute inset-0 bg-black/55" onClick={aoFechar} />
      <div className={`relative max-h-[92dvh] w-full ${largura} overflow-y-auto rounded-t-3xl border border-borda bg-superficie p-5 pb-[max(1.25rem,env(safe-area-inset-bottom))] shadow-2xl sm:rounded-3xl`}>
        <div className="mb-4 flex items-center gap-3">
          <h2 className="flex-1 text-lg font-bold text-texto">{titulo}</h2>
          <button type="button" onClick={aoFechar} aria-label="Fechar" className="toque flex items-center justify-center rounded-xl text-2xl leading-none text-suave">×</button>
        </div>
        {children}
      </div>
    </div>
  );
}

export const CLASSE_AREA = 'w-full rounded-xl border border-borda bg-fundo p-3 text-base text-texto outline-none focus:border-primaria';

/** Botão de escolher arquivo (grande, bom no dedo). */
export function EscolherArquivo({ rotulo, aceita, multiplo = false, aoEscolher, nomes = [] }: { rotulo: string; aceita?: string; multiplo?: boolean; aoEscolher: (f: File[]) => void; nomes?: string[] }) {
  return (
    <div className="space-y-1.5">
      <label className="toque inline-flex cursor-pointer items-center justify-center gap-2 rounded-xl border border-dashed border-primaria/60 bg-primaria/5 px-4 font-semibold text-texto hover:bg-primaria/10">
        📎 {rotulo}
        <input type="file" className="sr-only" {...(aceita ? { accept: aceita } : {})} multiple={multiplo} onChange={(e) => { aoEscolher(Array.from(e.target.files ?? [])); e.target.value = ''; }} />
      </label>
      {nomes.length > 0 && <p className="text-sm text-suave">{nomes.join(', ')}</p>}
    </div>
  );
}

export function Aviso({ texto, erro = false }: { texto: string; erro?: boolean }) {
  if (!texto) return null;
  return <p role={erro ? 'alert' : 'status'} className={`rounded-xl px-3 py-2 text-sm ${erro ? 'bg-perigo/10 text-perigo' : 'bg-sucesso/10 text-sucesso'}`}>{texto}</p>;
}

/** "agora", "há 5 min", "há 3 h", "ontem", "há 4 dias", depois a data. */
export function tempoRelativo(valor: string | null | undefined): string {
  if (!valor) return '';
  const d = new Date(valor.replace(' ', 'T'));
  const min = Math.round((Date.now() - d.getTime()) / 60000);
  if (Number.isNaN(min)) return '';
  if (min < 1) return 'agora';
  if (min < 60) return `há ${min} min`;
  const h = Math.round(min / 60);
  if (h < 24) return `há ${h} h`;
  const dias = Math.round(h / 24);
  if (dias === 1) return 'ontem';
  if (dias < 30) return `há ${dias} dias`;
  return d.toLocaleDateString('pt-BR');
}
