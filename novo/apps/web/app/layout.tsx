import type { Metadata, Viewport } from 'next';
import { Plus_Jakarta_Sans } from 'next/font/google';
import type { ReactNode } from 'react';
import { cssDosTemas, scriptTema } from '@/lib/tema-css';
import './globals.css';

const fonte = Plus_Jakarta_Sans({ subsets: ['latin'], variable: '--fonte', display: 'swap' });

export const metadata: Metadata = {
  title: { default: 'Prime Hub · PrimeERP', template: '%s · Prime Hub' },
  description: 'Central de atendimento PrimeERP Soluções em Tecnologia',
  icons: { icon: '/marca/icone-256.png', apple: '/marca/icone-256.png' },
};

export const viewport: Viewport = {
  width: 'device-width',
  initialScale: 1,
  viewportFit: 'cover',
  themeColor: [
    { media: '(prefers-color-scheme: light)', color: '#F4F7FC' },
    { media: '(prefers-color-scheme: dark)', color: '#070B1C' },
  ],
};

export default function Raiz({ children }: { children: ReactNode }) {
  return (
    <html lang="pt-BR" data-tema="claro" className={fonte.variable} suppressHydrationWarning>
      <head>
        <style dangerouslySetInnerHTML={{ __html: cssDosTemas() }} />
        <script dangerouslySetInnerHTML={{ __html: scriptTema }} />
      </head>
      <body className="font-sans antialiased">{children}</body>
    </html>
  );
}
