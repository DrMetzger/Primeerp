# Prime Hub — nova versão (fase 2): requisitos

Vem depois da migração do sistema atual pra VM (`suporte.primeerp.com.br`).
Substitui o PHP aos poucos, sem parar o atendimento.

## Plataformas
- **Navegador** (desktop e celular) e **app Android (APK)**. Sem app desktop.
- O APK cria o atalho na tela inicial ao instalar.
- Um login só: **cliente** vê o portal (abrir/acompanhar chamados);
  **equipe** vê o painel de atendimento.

## Tecnologia
- **TypeScript** na versão estável mais recente disponível quando o
  desenvolvimento começar, com o modo mais estrito (`strict`, sem `any`).
- Backend: Node.js (NestJS). Web: React (Next.js). Android: React Native (Expo).
- Código e tipos compartilhados entre web, app e backend.

## Visual
- Cores bonitas, a partir da identidade da PrimeERP (azul/turquesa dos logos).
- **Tema claro e escuro**, seguindo o sistema do aparelho, com troca manual.
- Logos da PrimeERP (versão completa e só o "P") e avatar do agente de IA.

## Celular / acessibilidade (obrigatório)
- Nunca cortar cabeçalho nem rodapé: respeitar as áreas seguras (notch,
  barra de status, barra de navegação/gestos) em qualquer Android.
- Tabelas e grids legíveis no celular: viram cartões/lista em tela
  estreita, sem rolagem lateral e sem texto minúsculo.
- Tudo tocável com o dedo: alvos de toque de no mínimo 48dp, espaço entre
  botões, nada que dependa de passar o mouse por cima.
- Texto que respeita o tamanho de fonte escolhido no Android, contraste
  adequado nos dois temas, leitura por leitor de tela (TalkBack).
- Funciona da tela mais estreita (360dp) até tablet, retrato e paisagem.

## Agente de IA
- Agente dentro do site, com avatar (sugestão: D-ID Agents ou HeyGen
  Interactive Avatar; imagem estática pode sair do Leonardo.ai/Midjourney).
- Responde sempre como atendimento humano da PrimeERP (nunca se apresenta como IA).
