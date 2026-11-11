import type { VersaoApp } from '@primehub/shared';
import { File, Paths } from 'expo-file-system';
import { getContentUriAsync } from 'expo-file-system/legacy';
import * as IntentLauncher from 'expo-intent-launcher';
import { LinearGradient } from 'expo-linear-gradient';
import { useState } from 'react';
import { ActivityIndicator, Image, Linking, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

/**
 * Bloqueio total: a versão instalada é mais velha que a mínima. Não existe
 * "depois" nem botão de voltar -- só atualizar. Baixa o APK novo e abre o
 * instalador do Android direto.
 */
export function AtualizacaoObrigatoria({ versao, instalada }: { versao: VersaoApp; instalada: string }) {
  const insets = useSafeAreaInsets();
  const [estado, setEstado] = useState<'parado' | 'baixando' | 'erro'>('parado');

  async function atualizar() {
    setEstado('baixando');
    try {
      const destino = new File(Paths.cache, `PrimeHub-${versao.versaoCodigo}.apk`);
      if (destino.exists) destino.delete();
      const apk = await File.downloadFileAsync(versao.urlApk, destino);
      const uri = await getContentUriAsync(apk.uri);
      await IntentLauncher.startActivityAsync('android.intent.action.VIEW', {
        data: uri,
        flags: 1, // FLAG_GRANT_READ_URI_PERMISSION
        type: 'application/vnd.android.package-archive',
      });
      setEstado('parado');
    } catch {
      setEstado('erro');
    }
  }

  return (
    <LinearGradient colors={['#050817', '#0B1B6F']} style={{ flex: 1 }}>
      <ScrollView contentContainerStyle={[estilos.conteudo, { paddingTop: insets.top + 32, paddingBottom: insets.bottom + 32 }]}>
        <Image source={require('../assets/icone-p.png')} style={{ width: 72, height: 86 }} resizeMode="contain" accessibilityIgnoresInvertColors />
        <Text accessibilityRole="header" style={estilos.titulo}>Atualização obrigatória</Text>
        <Text style={estilos.texto}>
          Sua versão ({instalada}) não pode mais ser usada. Instale a versão {versao.versao} para continuar.
        </Text>
        {versao.novidades.length > 0 && (
          <View style={estilos.novidades}>
            <Text style={estilos.subtitulo}>Novidades</Text>
            {versao.novidades.map((n) => <Text key={n} style={estilos.item}>• {n}</Text>)}
          </View>
        )}
        <Pressable onPress={atualizar} disabled={estado === 'baixando'} accessibilityRole="button" accessibilityLabel="Atualizar agora"
          style={({ pressed }) => [estilos.botao, { opacity: pressed || estado === 'baixando' ? 0.75 : 1 }]}>
          {estado === 'baixando' ? <ActivityIndicator color="#05081A" /> : <Text style={estilos.botaoTexto}>Atualizar agora</Text>}
        </Pressable>
        {estado === 'baixando' && <Text style={estilos.texto}>Baixando a nova versão…</Text>}
        {estado === 'erro' && (
          <>
            <Text style={estilos.erro}>Não deu pra baixar aqui. Toque abaixo pra baixar pelo navegador.</Text>
            <Pressable onPress={() => void Linking.openURL(versao.urlApk)} accessibilityRole="link" style={estilos.link}>
              <Text style={estilos.linkTexto}>Baixar pelo navegador</Text>
            </Pressable>
          </>
        )}
        <Text style={estilos.dica}>Se o Android pedir, permita "instalar apps desta fonte" para o Prime Hub.</Text>
      </ScrollView>
    </LinearGradient>
  );
}

const estilos = StyleSheet.create({
  conteudo: { flexGrow: 1, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 24, gap: 16 },
  titulo: { color: '#FFFFFF', fontSize: 26, fontWeight: '800', textAlign: 'center' },
  subtitulo: { color: '#FFFFFF', fontSize: 16, fontWeight: '700', marginBottom: 6 },
  texto: { color: '#C8D3FF', fontSize: 16, textAlign: 'center', lineHeight: 23 },
  novidades: { alignSelf: 'stretch', backgroundColor: 'rgba(255,255,255,0.08)', borderRadius: 16, padding: 16 },
  item: { color: '#E6ECFF', fontSize: 15, lineHeight: 22 },
  botao: { alignSelf: 'stretch', minHeight: 56, borderRadius: 16, backgroundColor: '#2FD3E5', alignItems: 'center', justifyContent: 'center' },
  botaoTexto: { color: '#05081A', fontSize: 18, fontWeight: '800' },
  erro: { color: '#FF9AA5', fontSize: 15, textAlign: 'center' },
  link: { minHeight: 48, justifyContent: 'center', paddingHorizontal: 12 },
  linkTexto: { color: '#5EE3F0', fontSize: 16, fontWeight: '700', textDecorationLine: 'underline' },
  dica: { color: '#8C99C9', fontSize: 13, textAlign: 'center', marginTop: 8 },
});
