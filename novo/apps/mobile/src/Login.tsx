import type { Sessao, TipoLogin } from '@primehub/shared';
import { useState } from 'react';
import { Image, KeyboardAvoidingView, Platform, Pressable, ScrollView, TextInput, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { api, guardarSessao } from './api';
import { useTema } from './tema';
import { Botao, Cartao, T } from './ui';

export function Login({ aoEntrar }: { aoEntrar: (s: Sessao) => void }) {
  const { tema, alternar, nome } = useTema();
  const insets = useSafeAreaInsets();
  const [tipo, setTipo] = useState<TipoLogin>('cliente');
  const [email, setEmail] = useState('');
  const [senha, setSenha] = useState('');
  const [erro, setErro] = useState('');
  const [enviando, setEnviando] = useState(false);

  async function entrar() {
    setErro('');
    setEnviando(true);
    try {
      const sessao = await api<Sessao>('/api/login', { method: 'POST', body: JSON.stringify({ tipo, email, senha }) });
      await guardarSessao(sessao);
      aoEntrar(sessao);
    } catch (e) {
      setErro(e instanceof Error ? e.message : 'Não foi possível entrar.');
      setEnviando(false);
    }
  }

  const campo = { minHeight: 52, borderRadius: 14, borderWidth: 1, borderColor: tema.borda, backgroundColor: tema.fundo, color: tema.texto, paddingHorizontal: 16, fontSize: 17 } as const;

  return (
    <KeyboardAvoidingView style={{ flex: 1, backgroundColor: tema.fundo }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={{ flexGrow: 1, justifyContent: 'center', padding: 20, paddingTop: insets.top + 20, paddingBottom: insets.bottom + 20, gap: 20 }}>
        <View style={{ alignItems: 'flex-end' }}>
          <Pressable onPress={alternar} accessibilityRole="button" accessibilityLabel={nome === 'escuro' ? 'Usar tema claro' : 'Usar tema escuro'} style={{ minHeight: 48, minWidth: 48, alignItems: 'center', justifyContent: 'center' }}>
            <T style={{ fontSize: 22 }}>{nome === 'escuro' ? '☀️' : '🌙'}</T>
          </Pressable>
        </View>
        <Image source={require('../assets/logo.png')} style={{ width: '80%', height: 64, alignSelf: 'center' }} resizeMode="contain" accessibilityLabel="PrimeERP Soluções em Tecnologia" />
        <Cartao style={{ gap: 14 }}>
          <View accessibilityRole="tablist" style={{ flexDirection: 'row', backgroundColor: tema.superficieAlta, borderRadius: 14, padding: 4 }}>
            {(['cliente', 'equipe'] as const).map((t) => (
              <Pressable key={t} accessibilityRole="tab" accessibilityState={{ selected: tipo === t }} onPress={() => setTipo(t)}
                style={{ flex: 1, minHeight: 48, borderRadius: 11, alignItems: 'center', justifyContent: 'center', backgroundColor: tipo === t ? tema.primaria : 'transparent' }}>
                <T style={{ fontWeight: '700', color: tipo === t ? tema.primariaTexto : tema.textoSuave }}>{t === 'cliente' ? 'Sou cliente' : 'Sou da equipe'}</T>
              </Pressable>
            ))}
          </View>
          <T style={{ fontWeight: '600' }}>E-mail</T>
          <TextInput value={email} onChangeText={setEmail} autoCapitalize="none" autoComplete="email" keyboardType="email-address" style={campo}
            accessibilityLabel="E-mail" placeholderTextColor={tema.textoSuave} maxFontSizeMultiplier={1.6} />
          <T style={{ fontWeight: '600' }}>Senha</T>
          <TextInput value={senha} onChangeText={setSenha} secureTextEntry autoComplete="password" style={campo}
            accessibilityLabel="Senha" onSubmitEditing={entrar} maxFontSizeMultiplier={1.6} />
          {!!erro && <T accessibilityRole="alert" style={{ color: tema.perigo, fontWeight: '600' }}>{erro}</T>}
          <Botao titulo={enviando ? 'Entrando…' : 'Entrar'} primario onPress={entrar} disabled={enviando || !email || !senha} />
        </Cartao>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}
