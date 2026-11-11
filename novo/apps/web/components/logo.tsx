/** Logo PrimeERP na versão certa pro tema (texto claro no tema escuro). */
export function Logo({ className = 'h-14 w-auto' }: { className?: string }) {
  return (
    <>
      <img src="/marca/primeerp-logo.png" alt="PrimeERP Soluções em Tecnologia" className={`so-claro ${className}`} />
      <img src="/marca/primeerp-logo-escuro.png" alt="PrimeERP Soluções em Tecnologia" className={`so-escuro ${className}`} />
    </>
  );
}
