import { Image, View } from 'react-native';
import { SvgUri } from 'react-native-svg';
import { arquivoPublico } from './api';
import { useTema } from './tema';
import { T } from './ui';

/** Foto ou avatar (os avatares prontos são SVG, que o Image do Android não desenha). */
/** Foto com anel colorido opcional (azul = equipe, turquesa = cliente). */
export function FotoPessoa({ anel, ...p }: { caminho: string | null | undefined; tamanho?: number; nome?: string; anel?: string }) {
  if (!anel) return <Foto {...p} />;
  const t = p.tamanho ?? 44;
  return <View style={{ width: t + 6, height: t + 6, borderRadius: (t + 6) / 2, borderWidth: 2, borderColor: anel, alignItems: 'center', justifyContent: 'center' }}><Foto {...p} /></View>;
}

function Foto({ caminho, tamanho = 44, nome = '' }: { caminho: string | null | undefined; tamanho?: number; nome?: string }) {
  const { tema } = useTema();
  const uri = arquivoPublico(caminho);
  if (!uri) {
    return <View style={{ width: tamanho, height: tamanho, borderRadius: tamanho / 2, backgroundColor: tema.superficieAlta, alignItems: 'center', justifyContent: 'center' }}><T style={{ fontWeight: '800', fontSize: tamanho / 2.6 }}>{nome.slice(0, 1).toUpperCase() || '👤'}</T></View>;
  }
  if (/\.svg($|\?)/i.test(uri)) return <View style={{ width: tamanho, height: tamanho, borderRadius: tamanho / 2, overflow: 'hidden' }}><SvgUri uri={uri} width={tamanho} height={tamanho} /></View>;
  return <Image source={{ uri }} style={{ width: tamanho, height: tamanho, borderRadius: tamanho / 2 }} accessibilityIgnoresInvertColors />;
}

