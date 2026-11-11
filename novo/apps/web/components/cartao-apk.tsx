'use client';

import type { VersaoApp } from '@primehub/shared';
import { Download, Smartphone } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Esqueleto } from './esqueleto';

/** "Baixe o app" com a versão atual -- aparece nas duas telas de login. */
export function CartaoApk() {
  const [versao, setVersao] = useState<VersaoApp | null | undefined>(undefined);
  useEffect(() => {
    fetch('/api/app/versao', { cache: 'no-store' })
      .then((r) => (r.ok ? (r.json() as Promise<VersaoApp>) : null))
      .then(setVersao)
      .catch(() => setVersao(null));
  }, []);

  if (versao === undefined) return <Esqueleto className="h-24" />;

  return (
    <div className="flex items-center gap-4 rounded-2xl border border-borda bg-superficie/80 p-4 backdrop-blur">
      <div className="gradiente-marca flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl text-white shadow-lg">
        <Smartphone size={24} aria-hidden="true" />
      </div>
      <div className="min-w-0 flex-1">
        <p className="font-semibold text-texto">App Prime Hub para Android</p>
        <p className="text-sm text-suave">
          {versao ? `Versão ${versao.versao}${versao.tamanhoBytes ? ` · ${(versao.tamanhoBytes / 1048576).toFixed(0)} MB` : ''}` : 'Disponível em breve'}
        </p>
      </div>
      {versao && (
        <a href={versao.urlApk} className="toque inline-flex items-center gap-2 rounded-xl bg-primaria px-4 font-semibold text-primaria-texto shadow hover:opacity-90">
          <Download size={18} aria-hidden="true" /> Baixar
        </a>
      )}
    </div>
  );
}
