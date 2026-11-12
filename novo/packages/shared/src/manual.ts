/**
 * Manual do Prime Hub -- fonte única: vira a página "Manual" do sistema e é
 * a base de conhecimento do assistente interno (IA da equipe). Ao mudar uma
 * tela, atualize a seção correspondente aqui.
 */

export interface SecaoManual { id: string; titulo: string; texto: string }

export const MANUAL_PRIME_HUB: SecaoManual[] = [
  {
    id: 'visao-geral',
    titulo: '1. O que é o Prime Hub',
    texto: `O Prime Hub é a central de atendimento da PrimeERP Soluções em Tecnologia, consultoria TOTVS Protheus de Tanabi-SP. Ele reúne em um só lugar os chamados dos clientes, a carteira de clientes com seus setores, a agenda da equipe, relatórios, a biblioteca de fontes ADVPL, o bot de WhatsApp e a inteligência artificial que analisa e responde chamados.

Existem duas portas de entrada: o site (suporte.primeerp.com.br), que funciona em qualquer navegador de computador ou celular, e o aplicativo Android (APK), que se baixa pelo botão "Baixar o app" nas telas de login. Tudo o que muda em um muda no outro: as duas versões leem os mesmos dados ao mesmo tempo.

Há dois públicos. A EQUIPE da Prime entra por /login (botão "Sou da equipe") e vê a central completa. O CLIENTE entra por /portal (botão "Sou cliente") e vê só o que é da empresa dele: os chamados, o calendário, as videoaulas, as fontes e a equipe da empresa.`,
  },
  {
    id: 'acesso',
    titulo: '2. Entrar, verificação em duas etapas e segurança',
    texto: `Para entrar, escolha "Sou da equipe" ou "Sou cliente", digite e-mail e senha (o botão de olho mostra a senha digitada). Em seguida o sistema manda um código de 6 números para o e-mail cadastrado: é a verificação em duas etapas. O código vale 10 minutos e aceita 5 tentativas; se não chegar, use "Reenviar código" e confira a caixa de spam.

Marcando "Lembrar este aparelho", aquele navegador ou celular não pede o código de novo por 30 dias. Em computador compartilhado, não marque. Para sair, use o botão "Sair" no rodapé do menu lateral (no app, Menu > Sair).

O portal do cliente funciona em horário comercial, das 09:00 às 18:00 (horário de Brasília). Fora desse horário o cliente vê o aviso "Voltamos já, já!". Clientes com liberação especial (marcada no cadastro pela Prime) podem usar a qualquer hora. A equipe não tem restrição de horário.

Ninguém da equipe deve pedir a senha de um cliente. Esqueceu a senha: para a equipe, um administrador define uma nova em Usuários; para o cliente, a Prime reenvia o convite de acesso.`,
  },
  {
    id: 'menu',
    titulo: '3. Como a tela é organizada (menu lateral)',
    texto: `No computador, o MENU LATERAL ESQUERDO fica sempre visível. No celular, ele abre pelo botão de três linhas no canto superior esquerdo. No app Android, os atalhos principais ficam na barra de baixo (Painel, Chamados, Assistente, Menu) e o restante dentro de "Menu".

Itens do menu da equipe: Dashboard, Chamados, Clientes, Agenda, Relatórios, Fontes ADVPL, Usuários, Configurações e Assistente. Agenda, Relatórios, Fontes ADVPL, Usuários e Configurações aparecem só para administradores (perfis admin, diretor e CEO). No rodapé do menu ficam a foto da pessoa, o botão Perfil e o botão Sair.

Itens do menu do cliente: Meus chamados, Calendário, Videoaulas, Fontes ADVPL e Minha equipe, além de Perfil e Sair.

No topo de cada tela há o título, os botões de ação daquela tela (por exemplo "Novo chamado") e o botão de sol ou lua, que troca entre tema claro e escuro. IMPORTANTE: não existem mais "abas" para navegar entre módulos; cada módulo é um item do menu lateral. Abas só existem DENTRO de uma tela, como na ficha do cliente.`,
  },
  {
    id: 'dashboard',
    titulo: '4. Dashboard',
    texto: `O Dashboard é a primeira tela da equipe. Mostra os indicadores do atendimento: chamados aguardando a equipe, urgentes pendentes, chamados novos e resolvidos nos últimos 30 dias (com a comparação ao período anterior), tempo médio da primeira resposta e tempo médio de resolução.

Abaixo vêm o gráfico de chamados por semana, a distribuição por situação, a lista "Esperando resposta nossa há mais tempo" (comece o dia por ela), o ranking dos clientes que mais abriram chamados, as pendências por responsável e o resumo da IA nos últimos 30 dias (análises feitas, respostas enviadas sozinha, sugestões). O painel se atualiza sozinho a cada minuto; no app, puxe a tela para baixo para atualizar.`,
  },
  {
    id: 'chamados',
    titulo: '5. Chamados: lista, situação e prioridade',
    texto: `Menu lateral > Chamados abre a lista geral de chamados de todos os clientes que a pessoa pode ver. Os filtros ficam logo abaixo da busca: Pendentes (tudo que não está fechado), Aguardando nós, Aguardando cliente, Fechados e Todos. A busca aceita número do chamado, parte do título, da descrição ou o nome do cliente.

Cada linha mostra o logo da empresa, o número, a situação, a prioridade quando é alta ou urgente, o título, QUEM respondeu por último (com a foto) e há quanto tempo, a foto do responsável e há quanto tempo o cliente espera a nossa resposta.

As situações são: Aberto (acabou de chegar, ninguém respondeu), Aguardando nós (o cliente falou por último e espera a equipe), Aguardando cliente (a equipe respondeu e espera retorno) e Fechado. Quando o cliente responde um chamado fechado, ele reabre sozinho como "Aguardando nós".

Prioridades: Baixa, Média, Alta e Urgente. Alta e urgente aparecem com o selo vermelho de fogo e devem ser atendidas primeiro.

Para ver só os chamados de um cliente, entre em Clientes, abra a ficha e use a aba Chamados. Para ver os de um setor, abra a ficha do cliente, aba Setores, e toque no setor.`,
  },
  {
    id: 'responder',
    titulo: '6. Atender um chamado (conversa, anexos, status, leitura)',
    texto: `Tocar em um chamado abre a conversa completa. Cada mensagem mostra a foto e o nome de QUEM escreveu (a pessoa, não só a empresa), o papel dela (Equipe PrimeERP ou o nome da empresa do cliente) e há quanto tempo. Embaixo das mensagens da equipe aparece a confirmação de leitura: "Visto por Fulano há 5 min" quando alguém do cliente já abriu o chamado depois da mensagem, ou "Enviado, ainda não visto".

Para responder: escreva no campo, anexe arquivos pelo botão Anexar ou simplesmente ARRASTE e solte imagens, vídeos, PDFs, planilhas e fontes em cima da área de resposta (também dá para colar um print com Ctrl+V). Escolha em "Depois" a situação que o chamado terá após a resposta (Aguardando cliente, Aguardando nós ou Encerrar) e deixe marcado "Avisar o cliente" para mandar o aviso por e-mail e WhatsApp. A resposta sai sempre no nome de quem está logado.

Imagens, vídeos e PDFs anexados aparecem dentro da própria conversa; os demais arquivos ficam como botão de download.

No painel Detalhes, à direita, dá para trocar o responsável (a pessoa escolhida recebe aviso) e encerrar ou reabrir o chamado. O nome da empresa no topo leva à ficha do cliente.`,
  },
  {
    id: 'ia-chamados',
    titulo: '7. IA nos chamados (sugestão e resposta automática)',
    texto: `Quando um cliente abre um chamado, a IA da Prime analisa em segundo plano. Primeiro procura chamados parecidos que a equipe já resolveu; só se não encontrar, pesquisa no TDN da TOTVS e no User Function, que é a etapa mais cara. O modo de funcionamento fica em Configurações > IA nos chamados: Sugestão (só a equipe vê e decide se envia), Automático (responde o cliente sozinha, no nome da pessoa escolhida em "Responder em nome de") ou Desligado. Os limites diários de análises e de buscas controlam o gasto com a API do Gemini.

Dentro de qualquer chamado, o botão "Sugerir resposta com IA" faz a análise na hora, mostrando o passo a passo. A sugestão SÓ preenche o campo de resposta: nada é enviado ao cliente até a pessoa revisar e clicar em Enviar resposta. Quando a IA já deixou uma sugestão pronta, aparece o cartão "A IA já deixou uma sugestão pronta"; toque para usar.

Regra da casa: o cliente nunca é informado de que uma resposta foi escrita por IA.`,
  },
  {
    id: 'novo-chamado',
    titulo: '8. Abrir chamado (equipe e cliente)',
    texto: `A equipe abre um chamado em nome do cliente pelo botão "Novo chamado" na tela Chamados, ou por "+ Chamado" dentro da ficha do cliente (que já vem com o cliente escolhido). Preencha cliente, título, categoria, prioridade, setor (opcional), responsável (opcional) e a descrição, e anexe arquivos se quiser.

O cliente abre pelo botão "Abrir chamado" em Meus chamados, pelo WhatsApp (o bot conduz por categoria e prioridade) ou pedindo ao assistente do portal. Quem é de um setor abre já vinculado ao próprio setor. O título é padronizado em letras maiúsculas e o sistema ignora cliques duplos para não criar chamados repetidos. Ao abrir, a equipe é avisada e a IA entra na fila de análise.

Categorias: Geral, Compras, Estoque/Custos/PCP, Faturamento, Financeiro, Gestão de Pessoal, Ponto Eletrônico, Fiscal, Contabilidade e Desenvolvimento.`,
  },
  {
    id: 'clientes',
    titulo: '9. Clientes e a ficha do cliente',
    texto: `Menu lateral > Clientes mostra a carteira em cartões: logo, contato principal, situação (Ativo, Pausado, Inativo), chamados pendentes, total de chamados, documentos e o alerta de urgentes. Os que precisam de atenção aparecem primeiro. Administradores cadastram com "Novo cliente" e editam pelo botão Editar da ficha, inclusive trocando o logo.

A ficha do cliente tem abas internas: Visão geral (indicadores e chamados recentes), Chamados (todos os do cliente), Setores (cada setor com os próprios chamados, documentos, treinamentos, fontes e pessoas), Usuários (as pessoas do cliente que acessam o portal), Documentos e Agenda (treinamentos e reuniões). O botão "+ Chamado" abre um chamado já para esse cliente.`,
  },
  {
    id: 'setores',
    titulo: '10. Setores do cliente',
    texto: `Cada cliente pode ser dividido em setores (Financeiro, Fiscal, Compras, etc.). Setores são independentes: a pessoa de um setor só vê os chamados do próprio setor, enquanto o master (contato principal, ou alguém marcado como master) vê a empresa toda.

Na ficha do cliente, aba Setores, cada setor é um cartão colorido; o lápis edita ou exclui e o "+ Setor" cria. Tocando no cartão abre a PÁGINA DO SETOR, com abas próprias: Chamados (só os do setor), Usuários (convite e retirada de acesso), Documentos, Calendário (treinamentos, reuniões, conversas e auxílios em calendário mensal, com agendamento), Vídeo aulas, Fontes ADVPL (enviar, editar, excluir), Propostas de desenvolvimento (com situação), Métricas (indicadores com meta e barra de progresso) e IA do setor (análise com diagnóstico e sugestões de melhoria).

Os usuários do cliente são cadastrados na aba Usuários da ficha: nome, e-mail, telefone, setores e se é master; ali também se envia convite, link de nova senha e se tira o acesso. Clientes liberados pela Prime também podem criar setores e cadastrar usuários pelo item "Minha equipe" do portal; o cliente nunca consegue excluir nada.`,
  },
  {
    id: 'agenda',
    titulo: '11. Agenda da Prime (calendário e Gantt)',
    texto: `Menu lateral > Agenda é o quadro de trabalho compartilhado da gestão (admin, diretor e CEO), para decidir qual cliente atender primeiro e quando. No topo há três formas de ver: Gantt, Semana e Mês.

GANTT: uma linha por dia da semana e o horário na horizontal (07h às 20h), com a faixa do almoço e uma linha vermelha marcando a hora atual. Cada tarefa é uma barra colorida pela pessoa responsável. Para reagendar, ARRASTE a barra para outro horário ou outro dia (encaixa de 15 em 15 minutos); para editar, toque nela. Reuniões e treinamentos com clientes aparecem tracejados e abrem o link da reunião.

SEMANA: um cartão por dia, com caixinha para marcar a tarefa como feita. MÊS: calendário mensal completo com tudo marcado; toque no dia para ver a lista.

"Nova tarefa" (ou o + de cada dia) cria uma tarefa com título, dia, horário, duração, cliente, responsável e anotações. Tarefas ligadas a um chamado mostram o número, que leva direto à conversa.`,
  },
  {
    id: 'relatorios',
    titulo: '12. Relatórios',
    texto: `Menu lateral > Relatórios (só administradores) tem seis relatórios prontos: Chamados por Cliente, Chamados Geral, Fontes ADVPL por Cliente, Estatísticas Gerais, Clientes em Risco (consolidado das análises de risco da IA) e Atividade do Colaborador. Escolha o relatório, preencha os filtros (cliente, colaborador, período) e clique em "Ver relatório" para ver na tela, ou baixe em PDF (com o timbrado oficial) ou Excel.`,
  },
  {
    id: 'fontes',
    titulo: '13. Fontes ADVPL',
    texto: `Menu lateral > Fontes ADVPL (só administradores) é a biblioteca geral de fontes e patches da Prime, organizada por categoria (Integrações, Compras, Estoque, Faturamento, PCP, Qualidade, Financeiro, Fiscal, Contabilidade, Importações, Exportações e Miscelânea). Aceita .prw, .tlpp, .prx e .ptm. Dá para enviar vários arquivos de uma vez, editar nome, categoria e descrição, trocar o arquivo e baixar.

As fontes customizadas de cada cliente ficam nos setores do cliente. O cliente vê e envia as dele em Fontes ADVPL do portal.`,
  },
  {
    id: 'usuarios',
    titulo: '14. Usuários da equipe e perfis',
    texto: `Menu lateral > Usuários (só administradores) lista a equipe com foto, perfil e último acesso. "Novo usuário" cria um acesso com senha inicial forte (mínimo 8 caracteres com maiúscula, minúscula, número e símbolo). Em cada cartão: editar dados e perfil, definir nova senha e desativar ou reativar (ninguém consegue desativar o próprio usuário).

Perfis: Colaborador, Administrador, Diretor e CEO; os três últimos têm acesso total. "Só enxerga os clientes vinculados" restringe um colaborador aos clientes e setores dele. "Pode ver dados sensíveis" libera senhas, VPN e AnyDesk dos clientes, com registro de cada acesso.`,
  },
  {
    id: 'configuracoes',
    titulo: '15. Configurações e integrações (WhatsApp, e-mail, IA, timbrado)',
    texto: `Menu lateral > Configurações (só administradores) reúne as integrações. WhatsApp (Meta): Access Token, Phone Number ID, App Secret e o telefone que recebe as notificações da equipe; é isso que faz o bot do WhatsApp abrir chamados e avisar clientes. E-mail (SMTP): servidor, porta, segurança, usuário e senha da caixa que envia avisos, convites e o código da verificação em duas etapas, além da lista de e-mails da equipe que recebem aviso de todo chamado novo. IA nos chamados (Gemini): chaves, modo, em nome de quem a IA responde e limites diários. Timbrado dos PDFs: imagens de cabeçalho e rodapé e suas alturas. Todo campo de senha ou chave tem o botão de olho para conferir o que foi digitado.`,
  },
  {
    id: 'whatsapp',
    titulo: '16. Bot do WhatsApp',
    texto: `O número oficial da Prime no WhatsApp atende o cliente sozinho: identifica a pessoa pelo telefone cadastrado, pergunta categoria e prioridade por lista de botões e abre o chamado. Quando a equipe responde um chamado com "Avisar o cliente" marcado, o cliente recebe o aviso no WhatsApp e pode responder por lá, e a resposta entra na conversa do chamado. Para funcionar, o telefone da pessoa precisa estar cadastrado no cliente ou no setor.`,
  },
  {
    id: 'portal',
    titulo: '17. O que o cliente vê (portal)',
    texto: `Meus chamados: lista e conversa de cada chamado, com resposta, anexos, encerrar e reabrir. Calendário: treinamentos e reuniões marcados, com botão para entrar na reunião. Videoaulas: vídeos gravados para a empresa, que tocam na própria tela. Fontes ADVPL: as fontes da empresa por setor, com download e envio. Minha equipe: setores e pessoas da empresa (incluir e convidar só para clientes liberados). Meu perfil: foto ou avatar. Assistente: o robô do portal tira dúvidas de Protheus e ajuda a abrir chamados.`,
  },
  {
    id: 'app',
    titulo: '18. Aplicativo Android',
    texto: `O app tem tudo do site, pensado para o dedo: barra inferior com Painel, Chamados, Assistente e Menu; o botão voltar do Android volta uma tela. Na abertura, o app confere a versão; se houver uma nova, a atualização é obrigatória e AUTOMÁTICA: o download começa sozinho e o instalador do Android abre no final, bastando tocar em Instalar (na primeira vez o Android pode pedir para permitir instalar apps do Prime Hub). Arquivos e anexos abrem direto no navegador do celular. Envio de foto de perfil e de fontes é feito pelo site.`,
  },
  {
    id: 'assistente',
    titulo: '19. Assistente da equipe (o robô)',
    texto: `O robô no canto da tela é o assistente da equipe. Além de tirar dúvidas de uso do sistema e de Protheus, ele faz ações: resume a operação (pendências, urgentes, quem está com o quê), busca chamados, mostra o resumo de um chamado, abre chamado para um cliente, encerra chamado, muda situação e troca responsável. Toda ação que altera dados aparece como um cartão de confirmação; nada muda sem o seu clique em Confirmar. O robô some pelo X acima dele e volta pelo item Assistente do menu.`,
  },
  {
    id: 'boas-praticas',
    titulo: '20. Boas práticas de atendimento da Prime',
    texto: `Comece o dia pelo Dashboard, lista "Esperando resposta nossa há mais tempo", e depois pelo filtro Aguardando nós. Responda primeiro urgentes e altas. Toda resposta deve deixar claro o próximo passo e escolher a situação certa em "Depois". Use a confirmação de leitura antes de cobrar o cliente. Anexe prints ou vídeos curtos sempre que ajudar. Registre na Agenda as visitas e os atendimentos longos. Nunca prometa prazo que não foi combinado com a gestão, nunca exponha informação interna (horas gastas, valores, problemas de outros clientes) e nunca diga ao cliente que uma resposta foi feita por IA.`,
  },
];

/** Manual em texto corrido (base de conhecimento da IA). */
export function manualEmTexto(): string {
  return MANUAL_PRIME_HUB.map((s) => `## ${s.titulo}\n${s.texto}`).join('\n\n');
}
