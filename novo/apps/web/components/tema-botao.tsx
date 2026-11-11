'use client';

import { Moon, Sun } from 'lucide-react';
import { useEffect, useState } from 'react';

export function TemaBotao({ className = '' }: { className?: string }) {
  const [tema, setTema] = useState<'claro' | 'escuro'>('claro');
  useEffect(() => {
    setTema(document.documentElement.dataset.tema === 'escuro' ? 'escuro' : 'claro');
  }, []);

  function alternar() {
    const novo = tema === 'escuro' ? 'claro' : 'escuro';
    document.documentElement.dataset.tema = novo;
    try { localStorage.setItem('ph_tema', novo); } catch { /* aba privada */ }
    setTema(novo);
  }

  return (
    <button type="button" onClick={alternar} className={`toque inline-flex items-center justify-center rounded-xl border border-borda bg-superficie text-texto hover:bg-superficie-alta ${className}`}
      aria-label={tema === 'escuro' ? 'Usar tema claro' : 'Usar tema escuro'} title={tema === 'escuro' ? 'Tema claro' : 'Tema escuro'}>
      {tema === 'escuro' ? <Sun size={20} /> : <Moon size={20} />}
    </button>
  );
}
