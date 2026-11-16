'use client';

import type { VersaoApp } from '@primehub/shared';
import { Download, QrCode, Smartphone, X } from 'lucide-react';
import QRCode from 'qrcode';
import { useEffect, useState } from 'react';
import { Esqueleto } from './esqueleto';

/** Versão publicada do app (a mesma que o próprio app confere para se atualizar). */
function useVersaoApp(): VersaoApp | null | undefined {
  const [versao, setVersao] = useState<VersaoApp | null | undefined>(undefined);
  useEffect(() => {
    fetch('/api/app/versao', { cache: 'no-store' })
      .then((r) => (r.ok ? (r.json() as Promise<VersaoApp>) : null))
      .then(setVersao)
      .catch(() => setVersao(null));
  }, []);
  return versao;
}

/** Só computador (mouse e tela grande, sem ser celular/tablet): é onde o QR code faz sentido. */
function useComputador(): boolean {
  const [sim, setSim] = useState(false);
  useEffect(() => {
    const celular = /Android|iPhone|iPad|iPod|Mobile|Tablet/i.test(navigator.userAgent);
    const mq = window.matchMedia('(pointer: fine) and (min-width: 1024px)');
    const atualizar = () => setSim(!celular && mq.matches);
    atualizar();
    mq.addEventListener('change', atualizar);
    return () => mq.removeEventListener('change', atualizar);
  }, []);
  return sim;
}

const urlCompleta = (url: string) => (/^https?:/.test(url) ? url : `${location.origin}${url}`);

/** QR code do link de download (aponta a câmera do celular e baixa). */
function QrDownload({ url, tamanho }: { url: string; tamanho: number }) {
  const [svg, setSvg] = useState('');
  useEffect(() => { void QRCode.toString(urlCompleta(url), { type: 'svg', margin: 1, errorCorrectionLevel: 'M', color: { dark: '#0E1630', light: '#FFFFFF' } }).then(setSvg); }, [url]);
  if (!svg) return <Esqueleto className="rounded-xl" />;
  return <div role="img" aria-label="QR code para baixar o app" className="overflow-hidden rounded-xl bg-white p-1.5" style={{ width: tamanho, height: tamanho }} dangerouslySetInnerHTML={{ __html: svg }} />;
}

const tamanhoMB = (v: VersaoApp) => (v.tamanhoBytes ? ` · ${(v.tamanhoBytes / 1048576).toFixed(0)} MB` : '');

/** "Baixe o app" nas telas de login: botão + QR code (no computador). */
export function CartaoApk() {
  const versao = useVersaoApp();
  const computador = useComputador();
  if (versao === undefined) return <Esqueleto className="h-28" />;
  return (
    <div className="flex items-center gap-4 rounded-3xl border border-primaria/40 bg-superficie/85 p-4 shadow-lg backdrop-blur">
      <div className="gradiente-marca flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl text-white shadow-lg">
        <Smartphone size={24} aria-hidden="true" />
      </div>
      <div className="min-w-0 flex-1">
        <p className="font-bold text-texto">Baixe o app Prime Hub</p>
        <p className="text-sm text-suave">{versao ? `Android · versão ${versao.versao}${tamanhoMB(versao)}` : 'Disponível em breve'}</p>
        {versao && (
          <a href={versao.urlApk} className="toque mt-2 inline-flex items-center gap-2 rounded-xl bg-primaria px-4 font-semibold text-primaria-texto shadow hover:opacity-90">
            <Download size={18} aria-hidden="true" /> Baixar o app
          </a>
        )}
      </div>
      {versao && computador && <div className="shrink-0"><QrDownload url={versao.urlApk} tamanho={96} /></div>}
    </div>
  );
}

/** Janela "Baixar o app" de dentro do sistema (menu lateral). */
export function JanelaApk({ aoFechar }: { aoFechar: () => void }) {
  const versao = useVersaoApp();
  const computador = useComputador();
  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center sm:items-center" role="dialog" aria-modal="true" aria-label="Baixar o app" onKeyDown={(e) => { if (e.key === 'Escape') aoFechar(); }}>
      <button type="button" aria-label="Fechar" className="absolute inset-0 bg-black/60" onClick={aoFechar} />
      <div className="relative w-full max-w-md space-y-5 rounded-t-3xl border border-borda bg-superficie p-6 shadow-2xl sm:rounded-3xl">
        <div className="flex items-center gap-3">
          <div className="gradiente-marca flex h-11 w-11 items-center justify-center rounded-2xl text-white"><Smartphone size={22} aria-hidden="true" /></div>
          <div className="flex-1"><p className="text-lg font-bold text-texto">App Prime Hub</p><p className="text-sm text-suave">{versao ? `Android · versão ${versao.versao}${tamanhoMB(versao)}` : 'Carregando…'}</p></div>
          <button type="button" onClick={aoFechar} aria-label="Fechar" className="toque flex items-center justify-center rounded-xl text-suave hover:bg-superficie-alta"><X size={20} /></button>
        </div>
        {versao === undefined ? <Esqueleto className="h-48" /> : !versao ? <p className="text-suave">O app ainda não está disponível.</p> : (
          <>
            {computador && (
              <div className="flex flex-col items-center gap-2">
                <QrDownload url={versao.urlApk} tamanho={208} />
                <p className="flex items-center gap-1.5 text-sm text-suave"><QrCode size={15} aria-hidden="true" /> Aponte a câmera do celular para baixar</p>
              </div>
            )}
            <a href={versao.urlApk} className="gradiente-marca toque flex w-full items-center justify-center gap-2 rounded-xl font-semibold text-white shadow">
              <Download size={18} aria-hidden="true" /> Baixar no aparelho
            </a>
            <ol className="space-y-1 rounded-2xl bg-superficie-alta/60 p-4 text-sm text-suave">
              <li>1) Abra o arquivo baixado (primehub.apk).</li>
              <li>2) Se o Android pedir, permita "instalar apps desta fonte".</li>
              <li>3) Pronto: o app se atualiza sozinho quando sair versão nova.</li>
            </ol>
          </>
        )}
      </div>
    </div>
  );
}
