import { Clock } from 'lucide-react';

export const metadata = { title: 'Fora do horário' };

/** Portal do cliente funciona em horário comercial (mesma regra de sempre). */
export default function Pagina() {
  return (
    <main className="fundo-marca flex min-h-dvh items-center justify-center p-4">
      <div className="max-w-md rounded-3xl border border-borda bg-superficie p-8 text-center shadow-xl">
        <span className="gradiente-marca mx-auto flex h-16 w-16 items-center justify-center rounded-2xl text-white"><Clock size={32} aria-hidden="true" /></span>
        <h1 className="mt-5 text-2xl font-bold text-texto">Voltamos já, já!</h1>
        <p className="mt-2 text-suave">O portal funciona em horário comercial, das <b className="text-texto">09:00 às 18:00</b>. Volte nesse horário para abrir ou acompanhar seus chamados.</p>
        <a href="/portal" className="gradiente-marca toque mt-6 inline-flex items-center justify-center rounded-xl px-6 font-semibold text-white">Voltar ao início</a>
      </div>
    </main>
  );
}
