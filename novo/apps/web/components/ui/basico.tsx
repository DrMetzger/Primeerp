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
