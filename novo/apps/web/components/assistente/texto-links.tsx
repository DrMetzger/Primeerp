import type { ReactNode } from 'react';

// Captura URLs http/https (sem engolir pontuação final).
const RE = /(https?:\/\/[^\s<>()]+[^\s<>().,!?:])/g;

/** Renderiza um texto transformando URLs em links clicáveis (ex.: link de download do .prw). */
export function TextoComLinks({ texto }: { texto: string }): ReactNode {
  const partes = texto.split(RE);
  return (
    <>
      {partes.map((seg, i) =>
        /^https?:\/\//.test(seg) ? (
          <a key={i} href={seg} target="_blank" rel="noopener noreferrer"
            className="font-semibold text-primaria underline decoration-primaria/50 underline-offset-2 hover:opacity-80 break-all">
            {seg}
          </a>
        ) : (
          <span key={i}>{seg}</span>
        ),
      )}
    </>
  );
}
