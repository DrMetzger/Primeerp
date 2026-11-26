import type { ReactNode } from 'react';

// Pega: link markdown [rótulo](url) | URL http(s) | caminho de download relativo (/api/compilacao/arquivo/<hex>).
const RE = /\[([^\]]+)\]\((https?:\/\/[^\s)]+|\/[^\s)]+)\)|(https?:\/\/[^\s<>()]+[^\s<>().,!?:])|(\/api\/compilacao\/arquivo\/[a-f0-9]{32})/g;

function Link({ href, children }: { href: string; children: ReactNode }): ReactNode {
  return (
    <a href={href} target="_blank" rel="noopener noreferrer"
      className="font-semibold text-primaria underline decoration-primaria/50 underline-offset-2 hover:opacity-80 break-all">
      {children}
    </a>
  );
}

/** Renderiza um texto transformando links markdown e URLs (inclusive relativas) em links clicáveis. */
export function TextoComLinks({ texto }: { texto: string }): ReactNode {
  const out: ReactNode[] = [];
  let ultimo = 0;
  let m: RegExpExecArray | null;
  RE.lastIndex = 0;
  while ((m = RE.exec(texto))) {
    if (m.index > ultimo) out.push(texto.slice(ultimo, m.index));
    if (m[1] && m[2]) {
      out.push(<Link key={m.index} href={m[2]}>{m[1]}</Link>); // [rótulo](url)
    } else if (m[3]) {
      out.push(<Link key={m.index} href={m[3]}>{m[3]}</Link>); // URL crua
    } else if (m[4]) {
      out.push(<Link key={m.index} href={m[4]}>Baixar arquivo</Link>); // caminho de download solto
    }
    ultimo = m.index + m[0].length;
  }
  if (ultimo < texto.length) out.push(texto.slice(ultimo));
  return <>{out}</>;
}
