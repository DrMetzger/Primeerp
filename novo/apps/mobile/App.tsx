import type { UsuarioLogado, VersaoApp } from '@primehub/shared';
import * as Application from 'expo-application';
import * as SplashScreen from 'expo-splash-screen';
import { StatusBar } from 'expo-status-bar';
import { useEffect, useState } from 'react';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { AberturaIA } from './src/AberturaIA';
import { api, carregarSessao, encerrarSessao } from './src/api';
import { AtualizacaoObrigatoria } from './src/AtualizacaoObrigatoria';
import { Inicio } from './src/Inicio';
import { Login } from './src/Login';
import { ProvedorTema, useTema } from './src/tema';
import { Botao, T } from './src/ui';
import { View } from 'react-native';

void SplashScreen.preventAutoHideAsync();

type Estado =
  | { tela: 'abrindo'; mensagem: string }
  | { tela: 'atualizar'; versao: VersaoApp }
  | { tela: 'semConexao' }
  | { tela: 'login' }
  | { tela: 'inicio'; usuario: UsuarioLogado };

const TEMPO_MINIMO_ABERTURA = 2200;

function Fluxo() {
  const { nome, tema } = useTema();
  const [estado, setEstado] = useState<Estado>({ tela: 'abrindo', mensagem: 'Conectando à inteligência PrimeERP…' });

  async function iniciar() {
    setEstado({ tela: 'abrindo', mensagem: 'Conectando à inteligência PrimeERP…' });
    const inicio = Date.now();
    const esperarAbertura = () => new Promise<void>((r) => setTimeout(r, Math.max(0, TEMPO_MINIMO_ABERTURA - (Date.now() - inicio))));
    try {
      // 1) Versão: SEMPRE antes de qualquer coisa. Versão velha não abre.
      const versao = await api<VersaoApp>('/api/app/versao');
      const instalada = Number(Application.nativeBuildVersion ?? 0);
      if (instalada < versao.versaoMinima) {
        await esperarAbertura();
        setEstado({ tela: 'atualizar', versao });
        return;
      }
      setEstado({ tela: 'abrindo', mensagem: 'Verificando seu acesso…' });
      const usuario = await carregarSessao();
      if (usuario) await api('/api/eu'); // token ainda vale?
      await esperarAbertura();
      setEstado(usuario ? { tela: 'inicio', usuario } : { tela: 'login' });
    } catch (e) {
      await esperarAbertura();
      // 401 = sessão vencida (vai pro login); qualquer outra falha = sem
      // servidor: não dá pra conferir a versão, então não libera o app.
      if (e instanceof Error && 'status' in e && (e as { status: number }).status === 401) {
        await encerrarSessao();
        setEstado({ tela: 'login' });
      } else {
        setEstado({ tela: 'semConexao' });
      }
    }
  }

  useEffect(() => {
    void SplashScreen.hideAsync();
    void iniciar();
  }, []);

  const barra = estado.tela === 'abrindo' || estado.tela === 'atualizar' ? 'light' : nome === 'escuro' ? 'light' : 'dark';

  return (
    <>
      <StatusBar style={barra} />
      {estado.tela === 'abrindo' && <AberturaIA mensagem={estado.mensagem} />}
      {estado.tela === 'atualizar' && <AtualizacaoObrigatoria versao={estado.versao} instalada={Application.nativeApplicationVersion ?? '?'} />}
      {estado.tela === 'semConexao' && (
        <View style={{ flex: 1, backgroundColor: tema.fundo, alignItems: 'center', justifyContent: 'center', padding: 24, gap: 16 }}>
          <T style={{ fontSize: 22, fontWeight: '800', textAlign: 'center' }}>Sem conexão com a PrimeERP</T>
          <T style={{ color: tema.textoSuave, textAlign: 'center' }}>Confira a internet do celular e tente de novo.</T>
          <Botao titulo="Tentar de novo" primario onPress={() => void iniciar()} style={{ alignSelf: 'stretch' }} />
        </View>
      )}
      {estado.tela === 'login' && <Login aoEntrar={(s) => setEstado({ tela: 'inicio', usuario: s.usuario })} />}
      {estado.tela === 'inicio' && <Inicio usuario={estado.usuario} aoSair={() => { void encerrarSessao(); setEstado({ tela: 'login' }); }} />}
    </>
  );
}

export default function App() {
  return (
    <SafeAreaProvider>
      <ProvedorTema>
        <Fluxo />
      </ProvedorTema>
    </SafeAreaProvider>
  );
}
