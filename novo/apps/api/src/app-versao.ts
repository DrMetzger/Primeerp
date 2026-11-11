import { createReadStream } from 'node:fs';
import { readFile, stat } from 'node:fs/promises';
import { join } from 'node:path';
import { Readable } from 'node:stream';
import type { VersaoApp } from '@primehub/shared';
import { config } from './config';

interface ArquivoVersao { versao: string; versaoCodigo: number; versaoMinima?: number; publicadoEm?: string; novidades?: string[] }

/**
 * versao.json é gravado pelo workflow que gera o APK. Por padrão a versão
 * mínima É a última publicada: o app nunca abre numa versão velha.
 */
export async function versaoAtual(): Promise<VersaoApp | null> {
  try {
    const dados = JSON.parse(await readFile(join(config.pastaDownloads, 'versao.json'), 'utf8')) as ArquivoVersao;
    const apk = await stat(join(config.pastaDownloads, 'primehub.apk')).catch(() => null);
    return {
      versao: dados.versao,
      versaoCodigo: dados.versaoCodigo,
      versaoMinima: dados.versaoMinima ?? dados.versaoCodigo,
      urlApk: `${config.urlBase}/downloads/primehub.apk?v=${dados.versaoCodigo}`,
      tamanhoBytes: apk?.size ?? null,
      publicadoEm: dados.publicadoEm ?? null,
      novidades: dados.novidades ?? [],
    };
  } catch {
    return null;
  }
}

export async function respostaApk(): Promise<Response> {
  const caminho = join(config.pastaDownloads, 'primehub.apk');
  const info = await stat(caminho).catch(() => null);
  if (!info) return new Response('APK ainda não publicado.', { status: 404 });
  return new Response(Readable.toWeb(createReadStream(caminho)) as ReadableStream, {
    headers: {
      'content-type': 'application/vnd.android.package-archive',
      'content-length': String(info.size),
      'content-disposition': 'attachment; filename="PrimeHub.apk"',
      'cache-control': 'no-cache',
    },
  });
}
