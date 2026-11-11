import type { DesafioCodigo, RespostaLogin, Sessao, TipoLogin } from '@primehub/shared';
import { type ComponentRef, useRef, useState } from 'react';
import { Image, KeyboardAvoidingView, Platform, Pressable, ScrollView, Switch, TextInput, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { api, dispositivoSalvo, guardarSessao } from './api';
import { FundoCircuito } from './FundoCircuito';
import { useTema } from './tema';
import { Botao, Cartao, T } from './ui';

export function Login({ aoEntrar }: { aoEntrar: (s: Sessao) => void }) {
  const { tema, alternar, nome } = useTema();
  const insets = useSafeAreaInsets();
  const [tipo, setTipo] = useState<TipoLogin>('cliente');
  const [email, setEmail] = useState('');
  const [senha, setSenha] = useState('');
  const [verSenha, setVerSenha] = useState(false);
  const [erro, setErro] = useState('');
  const [aviso, setAviso] = useState('');
  const [enviando, setEnviando] = useState(false);
  const [desafio, setDesafio] = useState<DesafioCodigo | null>(null);
  const [codigo, setCodigo] = useState('');
  const [lembrar, setLembrar] = useState(true);
  const campoCodigo = useRef<ComponentRef<typeof TextInput>>(null);

  async function concluir(sessao: Sessao) {
    await guardarSessao(sessao);
    aoEntrar(sessao);
  }

  async function entrar() {
    setErro('');
    setEnviando(true);
    try {
      const dispositivo = await dispositivoSalvo();
      const r = await api<RespostaLogin>('/api/login', { method: 'POST', body: JSON.stringify({ tipo, email, senha, ...(dispositivo ? { dispositivo } : {}) }) });
      if ('etapa' in r) {
        setDesafio(r);
        setCodigo('');
        setEnviando(false);
        setTimeout(() => campoCodigo.current?.focus(), 300);
      } else {
        await concluir(r);
      }
    } catch (e) {
      setErro(e instanceof Error ? e.message : 'Não foi possível entrar.');
      setEnviando(false);
    }
  }

  async function confirmar(valor = codigo) {
    if (!desafio || valor.length !== 6) return;
    setErro('');
    setEnviando(true);
    try {
      await concluir(await api<Sessao>('/api/login/codigo', { method: 'POST', body: JSON.stringify({ desafio: desafio.desafio, codigo: valor, lembrar, aparelho: `App Android (${Platform.Version})` }) }));
    } catch (e) {
      setErro(e instanceof Error ? e.message : 'Código inválido.');
      setCodigo('');
      setEnviando(false);
    }
  }

  async function reenviar() {
    if (!desafio) return;
    setErro('');
    try {
      const novo = await api<DesafioCodigo>('/api/login/reenviar', { method: 'POST', body: JSON.stringify({ desafio: desafio.desafio }) });
      setDesafio(novo);
      setCodigo('');
      setAviso(`Enviamos um código novo para ${novo.destino}.`);
    } catch (e) {
      setErro(e instanceof Error ? e.message : 'Não foi possível reenviar.');
    }
  }

  const campo = { minHeight: 52, borderRadius: 14, borderWidth: 1, borderColor: tema.borda, backgroundColor: tema.fundo, color: tema.texto, paddingHorizontal: 16, fontSize: 17 } as const;

  return (
    <FundoCircuito>
      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={{ flexGrow: 1, justifyContent: 'center', padding: 20, paddingTop: insets.top + 12, paddingBottom: insets.bottom + 20, gap: 20 }}>
          <View style={{ alignItems: 'flex-end' }}>
            <Pressable onPress={alternar} accessibilityRole="button" accessibilityLabel={nome === 'escuro' ? 'Usar tema claro' : 'Usar tema escuro'}
              style={{ minHeight: 48, minWidth: 48, borderRadius: 14, alignItems: 'center', justifyContent: 'center', backgroundColor: tema.superficie, borderWidth: 1, borderColor: tema.borda }}>
              <T style={{ fontSize: 22 }}>{nome === 'escuro' ? '☀️' : '🌙'}</T>
            </Pressable>
          </View>
          <Image source={nome === 'escuro' ? require('../assets/logo-escuro.png') : require('../assets/logo.png')}
            style={{ width: '80%', height: 64, alignSelf: 'center' }} resizeMode="contain" accessibilityLabel="PrimeERP Soluções em Tecnologia" />
          <View style={{ alignItems: 'center', gap: 4 }}>
            <T style={{ fontSize: 24, fontWeight: '800', textAlign: 'center' }} accessibilityRole="header">
              {desafio ? 'Confirme que é você' : tipo === 'cliente' ? 'Bem-vindo à sua central' : 'Central da equipe'}
            </T>
            <T style={{ color: tema.textoSuave, textAlign: 'center' }}>
              {desafio ? 'Verificação em duas etapas' : tipo === 'cliente' ? 'Abra e acompanhe seus chamados com a PrimeERP' : 'Atendimento, chamados e clientes da PrimeERP'}
            </T>
          </View>

          <Cartao style={{ gap: 14, backgroundColor: nome === 'escuro' ? 'rgba(15,22,49,0.92)' : 'rgba(255,255,255,0.94)' }}>
            {!desafio ? (
              <>
                <View accessibilityRole="tablist" style={{ flexDirection: 'row', backgroundColor: tema.superficieAlta, borderRadius: 14, padding: 4 }}>
                  {(['cliente', 'equipe'] as const).map((t) => (
                    <Pressable key={t} accessibilityRole="tab" accessibilityState={{ selected: tipo === t }} onPress={() => { setTipo(t); setErro(''); }}
                      style={{ flex: 1, minHeight: 48, borderRadius: 11, alignItems: 'center', justifyContent: 'center', backgroundColor: tipo === t ? tema.primaria : 'transparent' }}>
                      <T style={{ fontWeight: '700', color: tipo === t ? tema.primariaTexto : tema.textoSuave }}>{t === 'cliente' ? 'Sou cliente' : 'Sou da equipe'}</T>
                    </Pressable>
                  ))}
                </View>
                <T style={{ fontWeight: '600' }}>E-mail</T>
                <TextInput value={email} onChangeText={setEmail} autoCapitalize="none" autoComplete="email" keyboardType="email-address" style={campo}
                  accessibilityLabel="E-mail" maxFontSizeMultiplier={1.6} />
                <T style={{ fontWeight: '600' }}>Senha</T>
                <View style={{ justifyContent: 'center' }}>
                  <TextInput value={senha} onChangeText={setSenha} secureTextEntry={!verSenha} autoComplete="password" style={[campo, { paddingRight: 56 }]}
                    accessibilityLabel="Senha" onSubmitEditing={() => void entrar()} maxFontSizeMultiplier={1.6} />
                  <Pressable onPress={() => setVerSenha(!verSenha)} accessibilityRole="button" accessibilityLabel={verSenha ? 'Esconder senha' : 'Mostrar senha'}
                    accessibilityState={{ selected: verSenha }} hitSlop={8}
                    style={{ position: 'absolute', right: 2, minWidth: 52, minHeight: 52, alignItems: 'center', justifyContent: 'center' }}>
                    <T style={{ fontSize: 20 }}>{verSenha ? '🙈' : '👁️'}</T>
                  </Pressable>
                </View>
                {!!erro && <T accessibilityRole="alert" style={{ color: tema.perigo, fontWeight: '600' }}>{erro}</T>}
                <Botao titulo={enviando ? 'Entrando…' : 'Entrar'} primario onPress={() => void entrar()} disabled={enviando || !email || !senha} />
                <T style={{ color: tema.textoSuave, fontSize: 13, textAlign: 'center' }}>🛡️ Acesso protegido com verificação em duas etapas</T>
              </>
            ) : (
              <>
                <View style={{ backgroundColor: tema.superficieAlta, borderRadius: 14, padding: 14 }}>
                  <T style={{ fontSize: 15 }}>Enviamos um código de 6 números para <T style={{ fontWeight: '800', fontSize: 15 }}>{desafio.destino}</T>. Ele vale por 10 minutos.</T>
                </View>
                {/* Um campo só (o Android preenche sozinho o código do SMS/e-mail), mostrado como 6 caixinhas. */}
                <Pressable onPress={() => campoCodigo.current?.focus()} accessibilityLabel="Código de 6 números" style={{ flexDirection: 'row', justifyContent: 'space-between', gap: 8 }}>
                  {Array.from({ length: 6 }, (_, i) => (
                    <View key={i} style={{ flex: 1, height: 58, borderRadius: 12, borderWidth: 2, borderColor: i === codigo.length ? tema.primaria : tema.borda, backgroundColor: tema.fundo, alignItems: 'center', justifyContent: 'center' }}>
                      <T style={{ fontSize: 26, fontWeight: '800' }}>{codigo[i] ?? ''}</T>
                    </View>
                  ))}
                </Pressable>
                <TextInput ref={campoCodigo} value={codigo} keyboardType="number-pad" textContentType="oneTimeCode" autoComplete="sms-otp" maxLength={6}
                  onChangeText={(v) => { const d = v.replace(/\D/g, '').slice(0, 6); setCodigo(d); if (d.length === 6) void confirmar(d); }}
                  style={{ position: 'absolute', opacity: 0, height: 1, width: 1 }} accessibilityLabel="Código de verificação" />
                <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', minHeight: 48 }}>
                  <T style={{ flex: 1 }}>Lembrar este aparelho por 30 dias</T>
                  <Switch value={lembrar} onValueChange={setLembrar} trackColor={{ true: tema.primaria, false: tema.borda }} accessibilityLabel="Lembrar este aparelho por 30 dias" />
                </View>
                {!!erro && <T accessibilityRole="alert" style={{ color: tema.perigo, fontWeight: '600' }}>{erro}</T>}
                {!!aviso && !erro && <T style={{ color: tema.sucesso, fontWeight: '600' }}>{aviso}</T>}
                <Botao titulo={enviando ? 'Conferindo…' : 'Confirmar'} primario onPress={() => void confirmar()} disabled={enviando || codigo.length !== 6} />
                <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
                  <Pressable onPress={() => { setDesafio(null); setErro(''); setAviso(''); }} style={{ minHeight: 48, justifyContent: 'center' }} accessibilityRole="button">
                    <T style={{ color: tema.textoSuave }}>‹ Voltar</T>
                  </Pressable>
                  <Pressable onPress={() => void reenviar()} style={{ minHeight: 48, justifyContent: 'center' }} accessibilityRole="button">
                    <T style={{ color: tema.primaria, fontWeight: '700' }}>Reenviar código</T>
                  </Pressable>
                </View>
              </>
            )}
          </Cartao>
        </ScrollView>
      </KeyboardAvoidingView>
    </FundoCircuito>
  );
}
