import { temas, type NomeTema, type Tema } from '@primehub/shared';
import { armazenamento } from './armazenamento';
import { createContext, type ReactNode, useContext, useEffect, useState } from 'react';
import { useColorScheme } from 'react-native';

interface ContextoTema { tema: Tema; nome: NomeTema; alternar: () => void }

const Contexto = createContext<ContextoTema>({ tema: temas.escuro, nome: 'escuro', alternar: () => undefined });

/** Segue o tema do Android; a pessoa pode trocar e a escolha fica salva. */
export function ProvedorTema({ children }: { children: ReactNode }) {
  const sistema = useColorScheme();
  const [escolha, setEscolha] = useState<NomeTema | null>(null);
  useEffect(() => {
    void armazenamento.ler('ph_tema').then((t) => { if (t === 'claro' || t === 'escuro') setEscolha(t); });
  }, []);
  const nome: NomeTema = escolha ?? (sistema === 'light' ? 'claro' : 'escuro');
  const alternar = () => {
    const novo: NomeTema = nome === 'escuro' ? 'claro' : 'escuro';
    setEscolha(novo);
    void armazenamento.gravar('ph_tema', novo);
  };
  return <Contexto.Provider value={{ tema: temas[nome], nome, alternar }}>{children}</Contexto.Provider>;
}

export const useTema = () => useContext(Contexto);
