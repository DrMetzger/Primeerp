import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import type { NextConfig } from 'next';

// Em produção o Caddy manda /api e /downloads pra API e *.php pras telas
// clássicas; em desenvolvimento local o próprio Next repassa.
const apiInterna = process.env.API_INTERNA;
const phpInterno = process.env.PHP_INTERNO;

const config: NextConfig = {
  output: 'standalone',
  // Monorepo: rastreia dependências a partir da raiz (packages/shared).
  outputFileTracingRoot: join(dirname(fileURLToPath(import.meta.url)), '../..'),
  transpilePackages: ['@primehub/shared'],
  poweredByHeader: false,
  // Checagem de tipos roda separada com o TypeScript 7 (pnpm typecheck).
  typescript: { ignoreBuildErrors: true },
  // As páginas (HTML) não podem ficar presas em cache de 1 ano: assim todo
  // deploy chega na hora para o usuário. Os estáticos versionados (/_next/static)
  // continuam com cache imutável.
  async headers() {
    return [
      { source: '/((?!_next/static|_next/image).*)', headers: [{ key: 'Cache-Control', value: 'no-cache, must-revalidate' }] },
    ];
  },
  async rewrites() {
    if (!apiInterna) return [];
    return [
      { source: '/api/:caminho*', destination: `${apiInterna}/api/:caminho*` },
      { source: '/downloads/:caminho*', destination: `${apiInterna}/downloads/:caminho*` },
      ...(phpInterno ? [
        { source: '/:arquivo*.php', destination: `${phpInterno}/:arquivo*.php` },
        { source: '/assets/:caminho*', destination: `${phpInterno}/assets/:caminho*` },
      ] : []),
    ];
  },
};

export default config;
