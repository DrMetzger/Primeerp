import type { ExpoConfig } from 'expo/config';

// versionCode vem do workflow que gera o APK (número de commits): toda
// publicação nova tem um código maior, e o app velho é obrigado a atualizar.
const versaoCodigo = Number(process.env.APP_VERSION_CODE ?? 1);
const versao = process.env.APP_VERSION ?? `1.0.${versaoCodigo}`;

const config: ExpoConfig = {
  name: 'Prime Hub',
  slug: 'primehub',
  scheme: 'primehub',
  version: versao,
  orientation: 'default',
  icon: './assets/icone-app.png',
  userInterfaceStyle: 'automatic',
  backgroundColor: '#070B1C',
  android: {
    package: 'br.com.primeerp.primehub',
    versionCode: versaoCodigo,
    adaptiveIcon: { foregroundImage: './assets/icone-frente.png', backgroundImage: './assets/icone-fundo.png', backgroundColor: '#0B1B6F' },
    permissions: ['INTERNET', 'REQUEST_INSTALL_PACKAGES'],
  },
  plugins: [
    'expo-secure-store',
    ['expo-splash-screen', { image: './assets/icone-p.png', imageWidth: 120, backgroundColor: '#070B1C' }],
    'expo-font',
  ],
  extra: { urlServidor: process.env.APP_URL_SERVIDOR ?? 'https://suporte.primeerp.com.br' },
};

export default config;
