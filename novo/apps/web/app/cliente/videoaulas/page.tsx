'use client';

import type { FonteArquivo } from '@primehub/shared';
import { Download, PlayCircle } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Casca } from '@/components/casca';
import { EsqueletoLista } from '@/components/esqueleto';
import { Vazio, dataBR } from '@/components/ui/basico';
import { api } from '@/lib/api';

export default function PaginaVideoaulas() {
  const [itens, setItens] = useState<FonteArquivo[] | null>(null);
  const [tocando, setTocando] = useState<number | null>(null);
  useEffect(() => { api<FonteArquivo[]>('/api/portal/videoaulas').then(setItens).catch(() => setItens([])); }, []);
  return (
    <Casca tipo="cliente" titulo="Videoaulas">
      {!itens ? <EsqueletoLista /> : !itens.length ? <Vazio>Ainda não temos videoaulas para você.</Vazio> : (
        <ul className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
          {itens.map((v) => (
            <li key={v.id} className="overflow-hidden rounded-3xl border border-borda bg-superficie shadow-sm">
              {tocando === v.id ? (
                <video controls autoPlay playsInline src={`/video_aula.php?id=${v.id}`} className="aspect-video w-full bg-black" />
              ) : (
                <button type="button" onClick={() => setTocando(v.id)} aria-label={`Assistir ${v.titulo}`} className="group relative block aspect-video w-full bg-black">
                  <video preload="metadata" muted playsInline src={`/video_aula.php?id=${v.id}#t=1`} className="h-full w-full object-cover opacity-80" />
                  <PlayCircle size={64} className="absolute inset-0 m-auto text-white drop-shadow-lg transition group-hover:scale-110" aria-hidden="true" />
                </button>
              )}
              <div className="flex items-start gap-3 p-4">
                <div className="min-w-0 flex-1">
                  <p className="font-semibold text-texto">{v.titulo}</p>
                  <p className="text-sm text-suave">{[v.setor, dataBR(v.criadoEm)].filter(Boolean).join(' · ')}</p>
                  {v.descricao && <p className="mt-1 line-clamp-3 text-sm text-suave">{v.descricao}</p>}
                </div>
                <a href={`/video_aula.php?id=${v.id}&baixar=1`} aria-label={`Baixar ${v.titulo}`} className="toque flex items-center justify-center rounded-xl text-primaria"><Download size={20} /></a>
              </div>
            </li>
          ))}
        </ul>
      )}
    </Casca>
  );
}
