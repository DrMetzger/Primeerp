import * as DocumentPicker from 'expo-document-picker';
import * as ImagePicker from 'expo-image-picker';

/** Arquivo escolhido no celular, pronto para ir num FormData (padrão do React Native). */
export interface ArquivoEscolhido { uri: string; name: string; type: string; tamanho?: number }

/** Qualquer arquivo: PDF, Word, Excel, ZIP, fontes .prw... (vários de uma vez). */
export async function escolherDocumentos(tipos: string[] = ['*/*'], varios = true): Promise<ArquivoEscolhido[]> {
  const r = await DocumentPicker.getDocumentAsync({ type: tipos, multiple: varios, copyToCacheDirectory: true });
  if (r.canceled) return [];
  return r.assets.map((a) => ({ uri: a.uri, name: a.name, type: a.mimeType ?? 'application/octet-stream', ...(a.size ? { tamanho: a.size } : {}) }));
}

/** Fotos e vídeos da galeria. */
export async function escolherDaGaleria(somenteImagem = false, varios = true): Promise<ArquivoEscolhido[]> {
  const r = await ImagePicker.launchImageLibraryAsync({ mediaTypes: somenteImagem ? ['images'] : ['images', 'videos'], allowsMultipleSelection: varios, quality: 0.85 });
  if (r.canceled) return [];
  return r.assets.map((a, i) => ({
    uri: a.uri,
    name: a.fileName ?? `${a.type === 'video' ? 'video' : 'foto'}-${Date.now()}-${i}.${a.type === 'video' ? 'mp4' : 'jpg'}`,
    type: a.mimeType ?? (a.type === 'video' ? 'video/mp4' : 'image/jpeg'),
    ...(a.fileSize ? { tamanho: a.fileSize } : {}),
  }));
}

/** Foto na hora, pela câmera. */
export async function tirarFoto(): Promise<ArquivoEscolhido[]> {
  const p = await ImagePicker.requestCameraPermissionsAsync();
  if (!p.granted) return [];
  const r = await ImagePicker.launchCameraAsync({ quality: 0.85 });
  if (r.canceled) return [];
  return r.assets.map((a) => ({ uri: a.uri, name: a.fileName ?? `foto-${Date.now()}.jpg`, type: a.mimeType ?? 'image/jpeg' }));
}

export const tamanhoLegivel = (b?: number) => (!b ? '' : b > 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`);
