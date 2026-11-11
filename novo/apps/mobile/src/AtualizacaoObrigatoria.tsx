import type { VersaoApp } from '@primehub/shared';
import { cacheDirectory, createDownloadResumable, deleteAsync, getContentUriAsync } from 'expo-file-system/legacy';
import * as IntentLauncher from 'expo-intent-launcher';
import { LinearGradient } from 'expo-linear-gradient';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Image, Linking, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

const PACOTE = 'br.com.primeerp.primehub';

type Estado = 'baixando' | 'instalando' | 'erro' | 'permissao';

/**
 * Bloqueio total: a versão instalada é mais velha que a mínima. Não existe
 * "depois" nem voltar. O download começa SOZINHO assim que a tela aparece e o
 * instalador do Android abre sozinho no fim -- a pessoa só confirma o
 * "Instalar" do Android (obrigatório pra qualquer app fora da Play Store).
 */
export function AtualizacaoObrigatoria({ versao, instalada }: { versao: VersaoApp; instalada: string }) {
  const insets = useSafeAreaInsets();
  const [estado, setEstado] = useState<Estado>('baixando');
  const [progresso, setProgresso] = useState(0);
  const arquivo = useRef<string | null>(null);

  const abrirInstalador = useCallback(async () => {
    if (!arquivo.current) return;
    setEstado('instalando');
    try {
      const uri = await getContentUriAsync(arquivo.current);
      await IntentLauncher.startActivityAsync('android.intent.action.VIEW', {
        data: uri,
        flags: 1, // FLAG_GRANT_READ_URI_PERMISSION
        type: 'application/vnd.android.package-archive',
      });
      // Voltou do instalador sem instalar (cancelou ou falta permissão).
      setEstado('permissao');
    } catch {
      setEstado('permissao');
    }
  }, []);

  const baixar = useCallback(async () => {
    setEstado('baixando');
    setProgresso(0);
    try {
      const destino = `${cacheDirectory ?? ''}PrimeHub-${versao.versaoCodigo}.apk`;
      await deleteAsync(destino, { idempotent: true });
      const download = createDownloadResumable(versao.urlApk, destino, {}, (p) => {
        if (p.totalBytesExpectedToWrite > 0) setProgresso(p.totalBytesWritten / p.totalBytesExpectedToWrite);
      });
      const r = await download.downloadAsync();
      if (!r || r.status !== 200) throw new Error('download');
      arquivo.current = r.uri;
      await abrirInstalador();
    } catch {
      setEstado('erro');
    }
  }, [versao, abrirInstalador]);

  // Começa sozinho, sem precisar tocar em nada.
  useEffect(() => { void baixar(); }, [baixar]);

  const permitirFonte = () => void IntentLauncher.startActivityAsync('android.settings.MANAGE_UNKNOWN_APP_SOURCES', { data: `package:${PACOTE}` }).catch(() => undefined);

  return (
    <LinearGradient colors={['#050817', '#0B1B6F']} style={{ flex: 1 }}>
      <ScrollView contentContainerStyle={[estilos.conteudo, { paddingTop: insets.top + 32, paddingBottom: insets.bottom + 32 }]}>
        <Image source={require('../assets/icone-p.png')} style={{ width: 72, height: 86 }} resizeMode="contain" accessibilityIgnoresInvertColors />
        <Text accessibilityRole="header" style={estilos.titulo}>Atualizando o Prime Hub</Text>
        <Text style={estilos.texto}>Sua versão ({instalada}) foi substituída pela {versao.versao}. A atualização é obrigatória e já começou.</Text>

        {(estado === 'baixando' || estado === 'instalando') && (
          <View style={{ alignSelf: 'stretch', gap: 8 }} accessible accessibilityRole="progressbar" accessibilityValue={{ min: 0, max: 100, now: Math.round(progresso * 100) }}>
            <View style={estilos.trilho}><View style={[estilos.barra, { width: `${Math.max(4, progresso * 100)}%` }]} /></View>
            <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8 }}>
              <ActivityIndicator color="#5EE3F0" />
              <Text style={estilos.texto}>{estado === 'baixando' ? `Baixando… ${Math.round(progresso * 100)}%` : 'Abrindo o instalador…'}</Text>
            </View>
          </View>
        )}

        {estado === 'permissao' && (
          <View style={{ alignSelf: 'stretch', gap: 12 }}>
            <Text style={estilos.texto}>Toque em <Text style={{ fontWeight: '800', color: '#FFFFFF' }}>Instalar</Text> na tela do Android. Se ele pedir, permita "instalar apps desta fonte" para o Prime Hub.</Text>
            <Pressable onPress={() => void abrirInstalador()} accessibilityRole="button" style={({ pressed }) => [estilos.botao, { opacity: pressed ? 0.8 : 1 }]}>
              <Text style={estilos.botaoTexto}>Instalar agora</Text>
            </Pressable>
            <Pressable onPress={permitirFonte} accessibilityRole="button" style={estilos.link}>
              <Text style={estilos.linkTexto}>Permitir instalar apps do Prime Hub</Text>
            </Pressable>
          </View>
        )}

        {estado === 'erro' && (
          <View style={{ alignSelf: 'stretch', gap: 12 }}>
            <Text style={estilos.erro}>Não deu pra baixar agora. Confira a internet e tente de novo.</Text>
            <Pressable onPress={() => void baixar()} accessibilityRole="button" style={({ pressed }) => [estilos.botao, { opacity: pressed ? 0.8 : 1 }]}>
              <Text style={estilos.botaoTexto}>Tentar de novo</Text>
            </Pressable>
            <Pressable onPress={() => void Linking.openURL(versao.urlApk)} accessibilityRole="link" style={estilos.link}>
              <Text style={estilos.linkTexto}>Baixar pelo navegador</Text>
            </Pressable>
          </View>
        )}

        {versao.novidades.length > 0 && (
          <View style={estilos.novidades}>
            <Text style={estilos.subtitulo}>Novidades</Text>
            {versao.novidades.map((n) => <Text key={n} style={estilos.item}>• {n}</Text>)}
          </View>
        )}
      </ScrollView>
    </LinearGradient>
  );
}

const estilos = StyleSheet.create({
  conteudo: { flexGrow: 1, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 24, gap: 18 },
  titulo: { color: '#FFFFFF', fontSize: 26, fontWeight: '800', textAlign: 'center' },
  subtitulo: { color: '#FFFFFF', fontSize: 16, fontWeight: '700', marginBottom: 6 },
  texto: { color: '#C8D3FF', fontSize: 16, textAlign: 'center', lineHeight: 23 },
  trilho: { height: 12, borderRadius: 99, backgroundColor: 'rgba(255,255,255,0.12)', overflow: 'hidden' },
  barra: { height: 12, borderRadius: 99, backgroundColor: '#2FD3E5' },
  novidades: { alignSelf: 'stretch', backgroundColor: 'rgba(255,255,255,0.08)', borderRadius: 16, padding: 16 },
  item: { color: '#E6ECFF', fontSize: 15, lineHeight: 22 },
  botao: { alignSelf: 'stretch', minHeight: 56, borderRadius: 16, backgroundColor: '#2FD3E5', alignItems: 'center', justifyContent: 'center' },
  botaoTexto: { color: '#05081A', fontSize: 18, fontWeight: '800' },
  erro: { color: '#FF9AA5', fontSize: 15, textAlign: 'center' },
  link: { minHeight: 48, justifyContent: 'center', alignItems: 'center', paddingHorizontal: 12 },
  linkTexto: { color: '#5EE3F0', fontSize: 16, fontWeight: '700', textDecorationLine: 'underline' },
});
