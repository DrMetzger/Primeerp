# Prime Hub — nova versão (fase 2): requisitos

Vem depois da migração do sistema atual pra VM (`suporte.primeerp.com.br`).
Substitui o PHP aos poucos, sem parar o atendimento.

## Escopo: TUDO que existe hoje, refeito no sistema novo (site + APK)
Nenhuma tela pode mandar pra versão clássica (PHP) quando estiver pronta.
Ordem de entrega (cada item sai completo no site E no APK):

- [ ] **Login** (equipe e cliente, verificação em duas etapas, olho na senha) — base pronta
- [ ] **Dashboard** com KPIs reais — base pronta
- [ ] **Clientes**: lista, **novo cliente** (cadastro completo, logo/avatar), página do
      cliente com todas as abas (visão geral, demandas, KPIs, documentos, treinamentos,
      chamados, setores, acessos, fontes, implantação)
- [ ] **Chamados**: lista, **novo chamado**, responder com anexos, status, responsável,
      **análise com IA** (botão e automática), passo a passo
- [ ] **Agendas**: agenda da equipe e **agenda do cliente** (treinamentos/reuniões)
- [ ] **Fontes ADVPL**: lista e **upload de fontes**
- [ ] **Relatórios** e exportações (PDF/Excel)
- [ ] **Formulários e cadastros** (usuários, setores, responsáveis, acessos)
- [ ] **Configurações** (IA, e-mail, WhatsApp, timbrado) e **avatares**
- [ ] **Portal do cliente**: abertura de chamado, acompanhar, calendário, videoaulas,
      fontes, minha equipe, perfil
- [ ] **Chat do cliente com a IA** e **robô da PrimeERP** em todas as telas
- [ ] **Cron** (respostas automáticas da IA, lembretes) rodando no sistema novo

## Regra de trabalho (pedido do Kleber)
- **Toda mudança, melhoria ou novidade vale SEMPRE para os dois: site e APK.**
  Nada é entregue só em um deles.

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

## Acesso
- Login com **verificação em duas etapas**: depois da senha, código de 6
  números no e-mail (10 min, 5 tentativas); "lembrar este aparelho" por 30 dias.
- Seletor **"Sou cliente / Sou da equipe"** no topo do login (cliente e equipe
  são acessos diferentes, cada um com o próprio cadastro).
- **Botão de olho (mostrar/esconder) obrigatório em todo campo de senha.**

## Textura
- Fundo com **placa de circuito** (trilhas, vias e chips) nos dois temas, com
  uma luz percorrendo as trilhas no site; nada de fundo branco ou azul liso.
- Logo com texto claro no tema escuro.

## Carregamento e abertura
- **Skeleton** em todo carregamento (site e APK): blocos animados no formato
  do conteúdo (cards, gráficos, listas), nunca tela branca ou só "carregando".
- **Animação de abertura do APK com cara de IA**: o "P" da PrimeERP se monta
  com partículas/rede neural em azul e turquesa, enquanto o app verifica a
  versão e o login.

## Atualização obrigatória do APK
- Ao abrir, o app consulta `/api/app/versao`. Se a versão instalada for
  menor que a mínima, **bloqueia tudo** e só mostra "Atualizar agora"
  (baixa e abre o instalador). Nunca abre numa versão velha.
- O APK fica disponível pra baixar na tela de login da equipe e na do cliente.

## Agente de IA
- Agente dentro do site, com avatar (sugestão: D-ID Agents ou HeyGen
  Interactive Avatar; imagem estática pode sair do Leonardo.ai/Midjourney).
- Responde sempre como atendimento humano da PrimeERP (nunca se apresenta como IA).
