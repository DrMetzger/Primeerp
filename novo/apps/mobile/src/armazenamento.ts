import * as SecureStore from 'expo-secure-store';
import { Platform } from 'react-native';

// Só existe na prévia web; no Android é undefined.
const local = (globalThis as { localStorage?: { getItem(k: string): string | null; setItem(k: string, v: string): void; removeItem(k: string): void } }).localStorage;

/**
 * Guarda token/preferências: no celular, no cofre seguro do Android
 * (SecureStore); na prévia pelo navegador (só pra testes), no localStorage.
 */
export const armazenamento = {
  async ler(chave: string): Promise<string | null> {
    if (Platform.OS === 'web') return local?.getItem(chave) ?? null;
    return SecureStore.getItemAsync(chave);
  },
  async gravar(chave: string, valor: string): Promise<void> {
    if (Platform.OS === 'web') { local?.setItem(chave, valor); return; }
    await SecureStore.setItemAsync(chave, valor);
  },
  async apagar(chave: string): Promise<void> {
    if (Platform.OS === 'web') { local?.removeItem(chave); return; }
    await SecureStore.deleteItemAsync(chave);
  },
};
