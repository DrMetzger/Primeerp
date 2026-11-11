/** Bloco de carregamento no formato do conteúdo (nunca tela branca). */
export function Esqueleto({ className = '' }: { className?: string }) {
  return <div aria-hidden="true" className={`esqueleto ${className}`} />;
}

export function EsqueletoDashboard() {
  return (
    <div role="status" aria-label="Carregando o painel" className="space-y-6">
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-3 xl:grid-cols-6">
        {Array.from({ length: 6 }, (_, i) => <Esqueleto key={i} className="h-28" />)}
      </div>
      <div className="grid gap-4 lg:grid-cols-3">
        <Esqueleto className="h-72 lg:col-span-2" />
        <Esqueleto className="h-72" />
      </div>
      <div className="grid gap-4 lg:grid-cols-2">
        <Esqueleto className="h-64" />
        <Esqueleto className="h-64" />
      </div>
    </div>
  );
}

export function EsqueletoLista({ linhas = 6 }: { linhas?: number }) {
  return (
    <div role="status" aria-label="Carregando" className="space-y-3">
      {Array.from({ length: linhas }, (_, i) => <Esqueleto key={i} className="h-20" />)}
    </div>
  );
}
