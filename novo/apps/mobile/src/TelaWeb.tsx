import { ActivityIndicator, Pressable, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { WebView } from 'react-native-webview';
import { URL_SERVIDOR } from './api';
import { useTema } from './tema';
import { T } from './ui';

/** Tela que ainda não foi refeita no app: abre dentro dele, já logado. */
export function TelaWeb({ url, titulo, aoFechar }: { url: string; titulo: string; aoFechar: () => void }) {
  const { tema } = useTema();
  const insets = useSafeAreaInsets();
  return (
    <View style={{ flex: 1, backgroundColor: tema.fundo, paddingTop: insets.top }}>
      <View style={{ flexDirection: 'row', alignItems: 'center', borderBottomWidth: 1, borderColor: tema.borda, paddingHorizontal: 4 }}>
        <Pressable onPress={aoFechar} accessibilityRole="button" accessibilityLabel="Voltar" style={{ minWidth: 52, minHeight: 52, alignItems: 'center', justifyContent: 'center' }}>
          <T style={{ fontSize: 26 }}>‹</T>
        </Pressable>
        <T style={{ fontWeight: '700', fontSize: 18 }} numberOfLines={1}>{titulo}</T>
      </View>
      <WebView source={{ uri: `${URL_SERVIDOR}${url}` }} style={{ flex: 1, marginBottom: insets.bottom }} startInLoadingState
        renderLoading={() => <ActivityIndicator style={{ marginTop: 40 }} color={tema.primaria} />} setSupportMultipleWindows={false} allowsBackForwardNavigationGestures />
    </View>
  );
}
