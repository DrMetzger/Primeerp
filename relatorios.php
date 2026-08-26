<?php

/**
 * Relatórios pra diretoria -- pedido explícito do Kleber: "apenas admin,
 * diretor e ceo tem acesso". Auth::isAdmin() já cobre exatamente esses três
 * perfis (ver src/Auth.php) -- por isso Auth::requireAdmin() aqui, mesma
 * trava de configuracoes.php/usuarios.php, sem precisar de nada novo.
 *
 * Cada relatório é montado uma vez (gerarRelatorio()) em blocos de tabela
 * (['titulo' => ?string, 'colunas' => [...], 'linhas' => [...]]) -- a MESMA
 * estrutura alimenta a pré-visualização em tela, o PDF (com o timbrado
 * oficial, ver src/PdfExport.php::tabela()) e o Excel de verdade (.xlsx
 * real, ver src/XlsxExport.php -- não é CSV disfarçado como o resto do
 * sistema, isso foi pedido explícito pra ficar "digno de apresentação pra
 * diretoria").
 */

require_once __DIR__ . '/bootstrap.php';

use AdminPrime\Auth;
use AdminPrime\Database;
use AdminPrime\PdfExport;
use AdminPrime\XlsxExport;

Auth::requireAdmin();

$pdo = Database::connection();

const MAPA_STATUS_RELATORIO = ['aberto' => 'Aberto', 'aguardando_equipe' => 'Aguardando nós', 'aguardando_cliente' => 'Aguardando cliente', 'fechado' => 'Fechado'];
const MAPA_PRIORIDADE_RELATORIO = ['baixa' => 'Baixa', 'media' => 'Média', 'alta' => 'Alta', 'urgente' => 'Urgente'];
const MESES_ABREV_RELATORIO = ['01' => 'Jan', '02' => 'Fev', '03' => 'Mar', '04' => 'Abr', '05' => 'Mai', '06' => 'Jun', '07' => 'Jul', '08' => 'Ago', '09' => 'Set', '10' => 'Out', '11' => 'Nov', '12' => 'Dez'];

function truncarTexto(?string $texto, int $max): string
{
    $texto = trim((string) $texto);

    return mb_strlen($texto, 'UTF-8') > $max ? mb_substr($texto, 0, $max - 1, 'UTF-8') . '…' : $texto;
}

function mesLabelRelatorio(string $chaveAnoMes): string
{
    [$ano, $mes] = explode('-', $chaveAnoMes);

    return (MESES_ABREV_RELATORIO[$mes] ?? $mes) . '/' . $ano;
}

function dataValidaRelatorio(?string $valor): ?string
{
    if (!$valor) {
        return null;
    }
    $d = DateTime::createFromFormat('Y-m-d', $valor);

    return ($d && $d->format('Y-m-d') === $valor) ? $valor : null;
}

/**
 * Catálogo dos relatórios disponíveis -- alimenta os cards da tela inicial
 * E a validação de qual "relatorio" é aceito no GET.
 */
const CATALOGO_RELATORIOS = [
    'chamados_cliente' => ['titulo' => 'Chamados por Cliente', 'descricao' => 'Histórico completo de chamados de um cliente específico, com resumo de volume e tempo de atendimento.', 'icone' => 'bi-headset', 'precisaCliente' => true],
    'chamados_geral' => ['titulo' => 'Chamados Geral', 'descricao' => 'Visão consolidada de todos os clientes: volume, tempo médio de atendimento e pendências por cliente.', 'icone' => 'bi-bar-chart-line', 'precisaCliente' => false],
    'fontes_cliente' => ['titulo' => 'Fontes ADVPL por Cliente', 'descricao' => 'Relação de todo o código-fonte customizado cadastrado pra um cliente, organizado por setor.', 'icone' => 'bi-file-earmark-code', 'precisaCliente' => true],
    'estatisticas' => ['titulo' => 'Estatísticas Gerais', 'descricao' => 'Panorama do negócio: volume de chamados, tendência mensal e desempenho da equipe de atendimento.', 'icone' => 'bi-graph-up-arrow', 'precisaCliente' => false],
    'clientes_risco' => ['titulo' => 'Clientes em Risco', 'descricao' => 'Consolidado das análises de risco (IA) já geradas por cliente -- prioriza quem tem sinal de risco mais alto.', 'icone' => 'bi-shield-exclamation', 'precisaCliente' => false],
    'atividade_colaborador' => ['titulo' => 'Atividade do Colaborador', 'descricao' => 'Detalhe do que um colaborador específico fez: logins no sistema, chamados atendidos/fechados e tempo médio de resposta no período.', 'icone' => 'bi-person-check', 'precisaCliente' => false, 'precisaUsuario' => true],
];

function gerarRelatorio(string $tipo, array $filtros, PDO $pdo): array
{
    $clienteId = (int) ($filtros['cliente_id'] ?? 0);
    $dataDe = dataValidaRelatorio($filtros['data_de'] ?? null);
    $dataAte = dataValidaRelatorio($filtros['data_ate'] ?? null);

    if ($tipo === 'chamados_cliente') {
        $cliente = $pdo->prepare('SELECT nome FROM clientes WHERE id = ?');
        $cliente->execute([$clienteId]);
        $nomeCliente = $cliente->fetchColumn();
        if (!$nomeCliente) {
            return ['erro' => 'Selecione um cliente.'];
        }

        $sql = 'SELECT c.*, u.nome AS responsavel_nome FROM chamados c LEFT JOIN usuarios u ON u.id = c.responsavel_id WHERE c.cliente_id = ?';
        $params = [$clienteId];
        if ($dataDe) {
            $sql .= ' AND c.criado_em >= ?';
            $params[] = $dataDe . ' 00:00:00';
        }
        if ($dataAte) {
            $sql .= ' AND c.criado_em <= ?';
            $params[] = $dataAte . ' 23:59:59';
        }
        $sql .= ' ORDER BY c.criado_em DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $chamados = $stmt->fetchAll();

        $total = count($chamados);
        $fechados = count(array_filter($chamados, fn ($c) => $c['status'] === 'fechado'));
        $temposFechados = array_filter(array_map(fn ($c) => $c['fechado_em'] ? (strtotime($c['fechado_em']) - strtotime($c['criado_em'])) / 60 : null, $chamados));
        $tempoMedio = $temposFechados ? array_sum($temposFechados) / count($temposFechados) : null;

        $linhasChamados = [];
        foreach ($chamados as $c) {
            $linhasChamados[] = [
                truncarTexto($c['titulo'], 50),
                $c['categoria'] ?? '-',
                MAPA_STATUS_RELATORIO[$c['status']] ?? $c['status'],
                MAPA_PRIORIDADE_RELATORIO[$c['prioridade']] ?? $c['prioridade'],
                $c['responsavel_nome'] ?? '-',
                formatar_data($c['criado_em']),
                $c['fechado_em'] ? formatar_data($c['fechado_em']) : '-',
            ];
        }

        return [
            'titulo' => 'Chamados por Cliente',
            'descricaoFiltros' => 'Cliente: ' . $nomeCliente . ($dataDe || $dataAte ? ' · Período: ' . ($dataDe ?: 'início') . ' a ' . ($dataAte ?: 'hoje') : ' · Todo o período'),
            'nomeArquivo' => 'chamados-' . $nomeCliente,
            'blocos' => [
                [
                    'titulo' => 'Resumo',
                    'colunas' => ['Métrica' => ['pdf' => 90, 'xlsx' => 40, 'align' => 'L'], 'Valor' => ['pdf' => 90, 'xlsx' => 25, 'align' => 'L']],
                    'linhas' => [
                        ['Total de chamados', $total],
                        ['Chamados fechados', $fechados],
                        ['Chamados em aberto/aguardando', $total - $fechados],
                        ['Tempo médio de atendimento', $tempoMedio !== null ? formatar_duracao_minutos($tempoMedio) : 'sem chamados fechados no período'],
                    ],
                ],
                [
                    'titulo' => 'Chamados',
                    'colunas' => [
                        'Título' => ['pdf' => 45, 'xlsx' => 40, 'align' => 'L'],
                        'Categoria' => ['pdf' => 20, 'xlsx' => 18, 'align' => 'L'],
                        'Status' => ['pdf' => 20, 'xlsx' => 16, 'align' => 'L'],
                        'Prioridade' => ['pdf' => 18, 'xlsx' => 12, 'align' => 'L'],
                        'Responsável' => ['pdf' => 30, 'xlsx' => 20, 'align' => 'L'],
                        'Aberto em' => ['pdf' => 24, 'xlsx' => 16, 'align' => 'L'],
                        'Fechado em' => ['pdf' => 23, 'xlsx' => 16, 'align' => 'L'],
                    ],
                    'linhas' => $linhasChamados,
                ],
            ],
        ];
    }

    if ($tipo === 'chamados_geral') {
        $filtroData = '';
        $paramsData = [];
        if ($dataDe) {
            $filtroData .= ' AND ch.criado_em >= ?';
            $paramsData[] = $dataDe . ' 00:00:00';
        }
        if ($dataAte) {
            $filtroData .= ' AND ch.criado_em <= ?';
            $paramsData[] = $dataAte . ' 23:59:59';
        }

        $sqlPorCliente = "
            SELECT
                c.nome,
                (SELECT COUNT(*) FROM chamados ch WHERE ch.cliente_id = c.id {$filtroData}) AS total,
                (SELECT COUNT(*) FROM chamados ch WHERE ch.cliente_id = c.id AND ch.status = 'fechado' {$filtroData}) AS fechados,
                (SELECT COUNT(*) FROM chamados ch WHERE ch.cliente_id = c.id AND ch.status != 'fechado' {$filtroData}) AS pendentes,
                (SELECT AVG(TIMESTAMPDIFF(MINUTE, ch.criado_em, ch.fechado_em)) FROM chamados ch WHERE ch.cliente_id = c.id AND ch.fechado_em IS NOT NULL {$filtroData}) AS tempo_medio_min,
                (SELECT AVG(DATEDIFF(NOW(), ch.criado_em)) FROM chamados ch WHERE ch.cliente_id = c.id AND ch.status != 'fechado' {$filtroData}) AS idade_media_pendentes_dias
            FROM clientes c
            WHERE c.status = 'ativo'
            ORDER BY total DESC, c.nome ASC
        ";
        // Cada subconsulta usa os MESMOS parâmetros de data (4 vezes) --
        // a ordem importa: PDO precisa dos parâmetros na ordem em que os
        // placeholders aparecem no SQL final.
        $stmt = $pdo->prepare($sqlPorCliente);
        $stmt->execute(array_merge($paramsData, $paramsData, $paramsData, $paramsData));
        $porCliente = $stmt->fetchAll();

        $totalGeral = array_sum(array_column($porCliente, 'total'));
        $fechadosGeral = array_sum(array_column($porCliente, 'fechados'));

        $linhasCliente = [];
        foreach ($porCliente as $c) {
            if ((int) $c['total'] === 0) {
                continue;
            }
            $linhasCliente[] = [
                $c['nome'],
                (int) $c['total'],
                (int) $c['fechados'],
                (int) $c['pendentes'],
                $c['tempo_medio_min'] !== null ? formatar_duracao_minutos((float) $c['tempo_medio_min']) : '-',
                $c['idade_media_pendentes_dias'] !== null ? round($c['idade_media_pendentes_dias']) . ' dias' : '-',
            ];
        }

        return [
            'titulo' => 'Chamados Geral',
            'descricaoFiltros' => ($dataDe || $dataAte) ? 'Período: ' . ($dataDe ?: 'início') . ' a ' . ($dataAte ?: 'hoje') : 'Todo o período',
            'nomeArquivo' => 'chamados-geral',
            'blocos' => [
                [
                    'titulo' => 'Resumo',
                    'colunas' => ['Métrica' => ['pdf' => 90, 'xlsx' => 40, 'align' => 'L'], 'Valor' => ['pdf' => 90, 'xlsx' => 25, 'align' => 'L']],
                    'linhas' => [
                        ['Clientes com movimento no período', count($linhasCliente)],
                        ['Total de chamados', $totalGeral],
                        ['Chamados fechados', $fechadosGeral],
                        ['Chamados pendentes', $totalGeral - $fechadosGeral],
                    ],
                ],
                [
                    'titulo' => 'Por Cliente',
                    'colunas' => [
                        'Cliente' => ['pdf' => 55, 'xlsx' => 30, 'align' => 'L'],
                        'Total' => ['pdf' => 20, 'xlsx' => 10, 'align' => 'R'],
                        'Fechados' => ['pdf' => 22, 'xlsx' => 10, 'align' => 'R'],
                        'Pendentes' => ['pdf' => 22, 'xlsx' => 10, 'align' => 'R'],
                        'Tempo médio' => ['pdf' => 30, 'xlsx' => 16, 'align' => 'L'],
                        'Idade média pendentes' => ['pdf' => 31, 'xlsx' => 18, 'align' => 'L'],
                    ],
                    'linhas' => $linhasCliente,
                ],
            ],
        ];
    }

    if ($tipo === 'fontes_cliente') {
        $cliente = $pdo->prepare('SELECT nome FROM clientes WHERE id = ?');
        $cliente->execute([$clienteId]);
        $nomeCliente = $cliente->fetchColumn();
        if (!$nomeCliente) {
            return ['erro' => 'Selecione um cliente.'];
        }

        $stmt = $pdo->prepare("
            SELECT sfa.*, s.nome AS setor_nome
            FROM setor_fontes_advpl sfa
            INNER JOIN setores s ON s.id = sfa.setor_id
            WHERE s.cliente_id = ?
            ORDER BY s.nome ASC, sfa.titulo ASC
        ");
        $stmt->execute([$clienteId]);
        $fontes = $stmt->fetchAll();

        $linhasFontes = [];
        $setoresComFonte = [];
        $tamanhoTotal = 0;
        foreach ($fontes as $f) {
            $setoresComFonte[$f['setor_nome']] = true;
            $tamanhoTotal += (int) $f['arquivo_tamanho'];
            $linhasFontes[] = [
                $f['setor_nome'],
                truncarTexto($f['titulo'], 40),
                $f['ponto_entrada'] ?: '-',
                truncarTexto($f['arquivo_nome_original'], 30),
                round($f['arquivo_tamanho'] / 1024) . ' KB',
                formatar_data($f['criado_em']),
            ];
        }

        return [
            'titulo' => 'Fontes ADVPL por Cliente',
            'descricaoFiltros' => 'Cliente: ' . $nomeCliente,
            'nomeArquivo' => 'fontes-advpl-' . $nomeCliente,
            'blocos' => [
                [
                    'titulo' => 'Resumo',
                    'colunas' => ['Métrica' => ['pdf' => 90, 'xlsx' => 40, 'align' => 'L'], 'Valor' => ['pdf' => 90, 'xlsx' => 25, 'align' => 'L']],
                    'linhas' => [
                        ['Total de fontes cadastradas', count($fontes)],
                        ['Setores com fonte cadastrada', count($setoresComFonte)],
                        ['Tamanho total dos arquivos', round($tamanhoTotal / 1024 / 1024, 2) . ' MB'],
                    ],
                ],
                [
                    'titulo' => 'Fontes',
                    'colunas' => [
                        'Setor' => ['pdf' => 28, 'xlsx' => 18, 'align' => 'L'],
                        'Rotina' => ['pdf' => 45, 'xlsx' => 32, 'align' => 'L'],
                        'Ponto de entrada' => ['pdf' => 32, 'xlsx' => 20, 'align' => 'L'],
                        'Arquivo' => ['pdf' => 35, 'xlsx' => 24, 'align' => 'L'],
                        'Tamanho' => ['pdf' => 18, 'xlsx' => 10, 'align' => 'R'],
                        'Cadastrado em' => ['pdf' => 22, 'xlsx' => 16, 'align' => 'L'],
                    ],
                    'linhas' => $linhasFontes,
                ],
            ],
        ];
    }

    if ($tipo === 'estatisticas') {
        $clientesAtivos = (int) $pdo->query("SELECT COUNT(*) FROM clientes WHERE status = 'ativo'")->fetchColumn();
        $totalChamados = (int) $pdo->query('SELECT COUNT(*) FROM chamados')->fetchColumn();
        $chamados30dias = (int) $pdo->query('SELECT COUNT(*) FROM chamados WHERE criado_em >= NOW() - INTERVAL 30 DAY')->fetchColumn();
        $fechados30dias = (int) $pdo->query("SELECT COUNT(*) FROM chamados WHERE status = 'fechado' AND fechado_em >= NOW() - INTERVAL 30 DAY")->fetchColumn();
        $tempoMedio30dias = $pdo->query("SELECT AVG(TIMESTAMPDIFF(MINUTE, criado_em, fechado_em)) FROM chamados WHERE fechado_em IS NOT NULL AND fechado_em >= NOW() - INTERVAL 30 DAY")->fetchColumn();
        $idadeMediaPendentes = $pdo->query("SELECT AVG(DATEDIFF(NOW(), criado_em)) FROM chamados WHERE status IN ('aberto','aguardando_equipe')")->fetchColumn();

        $mesesChave = [];
        $ref = new DateTime('first day of this month');
        for ($i = 11; $i >= 0; $i--) {
            $mesRef = clone $ref;
            $mesRef->modify("-{$i} months");
            $mesesChave[] = $mesRef->format('Y-m');
        }
        $primeiroMes = reset($mesesChave) . '-01';

        $abertosPorMes = array_fill_keys($mesesChave, 0);
        foreach ($pdo->query("SELECT DATE_FORMAT(criado_em, '%Y-%m') AS mes, COUNT(*) AS total FROM chamados WHERE criado_em >= '{$primeiroMes}' GROUP BY mes")->fetchAll() as $r) {
            $abertosPorMes[$r['mes']] = (int) $r['total'];
        }
        $fechadosPorMes = array_fill_keys($mesesChave, 0);
        foreach ($pdo->query("SELECT DATE_FORMAT(fechado_em, '%Y-%m') AS mes, COUNT(*) AS total FROM chamados WHERE fechado_em IS NOT NULL AND fechado_em >= '{$primeiroMes}' GROUP BY mes")->fetchAll() as $r) {
            $fechadosPorMes[$r['mes']] = (int) $r['total'];
        }
        $tempoMedioPorMes = array_fill_keys($mesesChave, null);
        foreach ($pdo->query("SELECT DATE_FORMAT(fechado_em, '%Y-%m') AS mes, AVG(TIMESTAMPDIFF(MINUTE, criado_em, fechado_em)) AS media FROM chamados WHERE fechado_em IS NOT NULL AND fechado_em >= '{$primeiroMes}' GROUP BY mes")->fetchAll() as $r) {
            $tempoMedioPorMes[$r['mes']] = $r['media'] !== null ? (float) $r['media'] : null;
        }

        $linhasTendencia = [];
        foreach ($mesesChave as $mes) {
            $linhasTendencia[] = [
                mesLabelRelatorio($mes),
                $abertosPorMes[$mes],
                $fechadosPorMes[$mes],
                $tempoMedioPorMes[$mes] !== null ? formatar_duracao_minutos($tempoMedioPorMes[$mes]) : '-',
            ];
        }

        $atendentes = $pdo->query("
            SELECT
                u.nome,
                (SELECT COUNT(*) FROM chamados c WHERE c.responsavel_id = u.id) AS total_atendidos,
                (SELECT COUNT(*) FROM chamados c WHERE c.responsavel_id = u.id AND c.status = 'fechado') AS total_fechados,
                (SELECT COUNT(DISTINCT c.cliente_id) FROM chamados c WHERE c.responsavel_id = u.id AND c.cliente_id IS NOT NULL) AS clientes_atendidos,
                (SELECT AVG(TIMESTAMPDIFF(MINUTE, c.criado_em, c.fechado_em)) FROM chamados c WHERE c.responsavel_id = u.id AND c.fechado_em IS NOT NULL) AS media_minutos_atendimento
            FROM usuarios u
            ORDER BY total_atendidos DESC, u.nome ASC
        ")->fetchAll();

        $linhasAtendentes = [];
        foreach ($atendentes as $a) {
            if ((int) $a['total_atendidos'] === 0) {
                continue;
            }
            $linhasAtendentes[] = [
                $a['nome'],
                (int) $a['total_atendidos'],
                (int) $a['total_fechados'],
                (int) $a['clientes_atendidos'],
                $a['media_minutos_atendimento'] !== null ? formatar_duracao_minutos((float) $a['media_minutos_atendimento']) : '-',
            ];
        }

        return [
            'titulo' => 'Estatísticas Gerais',
            'descricaoFiltros' => 'Panorama consolidado -- gerado em ' . formatar_data(date('Y-m-d H:i:s')),
            'nomeArquivo' => 'estatisticas-gerais',
            'blocos' => [
                [
                    'titulo' => 'Visão Geral',
                    'colunas' => ['Métrica' => ['pdf' => 90, 'xlsx' => 40, 'align' => 'L'], 'Valor' => ['pdf' => 90, 'xlsx' => 25, 'align' => 'L']],
                    'linhas' => [
                        ['Clientes ativos', $clientesAtivos],
                        ['Total de chamados (todos os tempos)', $totalChamados],
                        ['Chamados abertos nos últimos 30 dias', $chamados30dias],
                        ['Chamados fechados nos últimos 30 dias', $fechados30dias],
                        ['Tempo médio de atendimento (30 dias)', $tempoMedio30dias !== null ? formatar_duracao_minutos((float) $tempoMedio30dias) : 'sem dados'],
                        ['Idade média dos chamados pendentes', $idadeMediaPendentes !== null ? round((float) $idadeMediaPendentes) . ' dias' : 'sem pendências'],
                    ],
                ],
                [
                    'titulo' => 'Tendência mensal (últimos 12 meses)',
                    'colunas' => [
                        'Mês' => ['pdf' => 30, 'xlsx' => 12, 'align' => 'L'],
                        'Chamados abertos' => ['pdf' => 45, 'xlsx' => 18, 'align' => 'R'],
                        'Chamados fechados' => ['pdf' => 45, 'xlsx' => 18, 'align' => 'R'],
                        'Tempo médio de atendimento' => ['pdf' => 60, 'xlsx' => 22, 'align' => 'L'],
                    ],
                    'linhas' => $linhasTendencia,
                ],
                [
                    'titulo' => 'Desempenho por Atendente',
                    'colunas' => [
                        'Atendente' => ['pdf' => 55, 'xlsx' => 28, 'align' => 'L'],
                        'Total atendidos' => ['pdf' => 30, 'xlsx' => 14, 'align' => 'R'],
                        'Fechados' => ['pdf' => 25, 'xlsx' => 12, 'align' => 'R'],
                        'Clientes atendidos' => ['pdf' => 30, 'xlsx' => 14, 'align' => 'R'],
                        'Tempo médio' => ['pdf' => 40, 'xlsx' => 18, 'align' => 'L'],
                    ],
                    'linhas' => $linhasAtendentes,
                ],
            ],
        ];
    }

    if ($tipo === 'clientes_risco') {
        $analises = $pdo->query("
            SELECT r.*, c.nome AS cliente_nome
            FROM cliente_analise_risco r
            INNER JOIN clientes c ON c.id = r.cliente_id
            WHERE r.tipo = 'risco'
            ORDER BY r.criado_em DESC
        ")->fetchAll();

        // Agrupa só a análise MAIS RECENTE de cada cliente (histórico
        // completo não cabe num relatório consolidado) e tenta extrair o
        // nível de risco -- é texto livre gerado por IA (ver
        // cliente.php::gerar_analise_risco), então a extração é só uma
        // dica visual (melhor esforço via regex), não um dado estruturado.
        $maisRecentePorCliente = [];
        $totalPorCliente = [];
        foreach ($analises as $a) {
            $totalPorCliente[$a['cliente_id']] = ($totalPorCliente[$a['cliente_id']] ?? 0) + 1;
            if (!isset($maisRecentePorCliente[$a['cliente_id']])) {
                $maisRecentePorCliente[$a['cliente_id']] = $a;
            }
        }

        $pesoNivel = ['crítico' => 4, 'critico' => 4, 'alto' => 3, 'médio' => 2, 'medio' => 2, 'baixo' => 1];
        $linhasRisco = [];
        foreach ($maisRecentePorCliente as $clienteIdRisco => $a) {
            $nivel = '-';
            if (preg_match('/n[íi]vel de risco\s*\n+\s*([a-zçãéô]+)/ui', $a['analise'], $m)) {
                $nivel = mb_convert_case($m[1], MB_CASE_TITLE, 'UTF-8');
            }
            $linhasRisco[] = [
                '_peso' => $pesoNivel[mb_strtolower($nivel, 'UTF-8')] ?? 0,
                'linha' => [
                    $a['cliente_nome'],
                    $nivel,
                    formatar_data($a['criado_em']),
                    $totalPorCliente[$clienteIdRisco],
                ],
            ];
        }
        usort($linhasRisco, fn ($x, $y) => $y['_peso'] <=> $x['_peso']);
        $linhasRisco = array_column($linhasRisco, 'linha');

        return [
            'titulo' => 'Clientes em Risco',
            'descricaoFiltros' => 'Última análise de risco (IA) de cada cliente -- ' . count($linhasRisco) . ' cliente(s) com análise gerada',
            'nomeArquivo' => 'clientes-em-risco',
            'blocos' => [
                [
                    'titulo' => null,
                    'colunas' => [
                        'Cliente' => ['pdf' => 60, 'xlsx' => 30, 'align' => 'L'],
                        'Nível de risco (última análise)' => ['pdf' => 50, 'xlsx' => 24, 'align' => 'L'],
                        'Gerada em' => ['pdf' => 35, 'xlsx' => 18, 'align' => 'L'],
                        'Análises já geradas' => ['pdf' => 35, 'xlsx' => 16, 'align' => 'R'],
                    ],
                    'linhas' => $linhasRisco,
                ],
            ],
        ];
    }

    if ($tipo === 'atividade_colaborador') {
        $usuarioIdRelatorio = (int) ($filtros['usuario_id'] ?? 0);
        $usuarioRelatorio = $pdo->prepare('SELECT * FROM usuarios WHERE id = ?');
        $usuarioRelatorio->execute([$usuarioIdRelatorio]);
        $usuarioRelatorio = $usuarioRelatorio->fetch();
        if (!$usuarioRelatorio) {
            return ['erro' => 'Selecione um colaborador.'];
        }

        $filtroDataLogin = '';
        $filtroDataChamado = '';
        $paramsLogin = [$usuarioIdRelatorio];
        $paramsChamado = [$usuarioIdRelatorio];
        if ($dataDe) {
            $filtroDataLogin .= ' AND criado_em >= ?';
            $filtroDataChamado .= ' AND c.criado_em >= ?';
            $paramsLogin[] = $dataDe . ' 00:00:00';
            $paramsChamado[] = $dataDe . ' 00:00:00';
        }
        if ($dataAte) {
            $filtroDataLogin .= ' AND criado_em <= ?';
            $filtroDataChamado .= ' AND c.criado_em <= ?';
            $paramsLogin[] = $dataAte . ' 23:59:59';
            $paramsChamado[] = $dataAte . ' 23:59:59';
        }

        $stmtLogins = $pdo->prepare("SELECT * FROM usuario_login_log WHERE usuario_id = ? {$filtroDataLogin} ORDER BY criado_em DESC");
        $stmtLogins->execute($paramsLogin);
        $logins = $stmtLogins->fetchAll();

        $stmtChamadosColab = $pdo->prepare("
            SELECT c.*, cl.nome AS cliente_nome
            FROM chamados c
            LEFT JOIN clientes cl ON cl.id = c.cliente_id
            WHERE c.responsavel_id = ? {$filtroDataChamado}
            ORDER BY c.criado_em DESC
        ");
        $stmtChamadosColab->execute($paramsChamado);
        $chamadosColab = $stmtChamadosColab->fetchAll();

        $totalChamados = count($chamadosColab);
        $fechadosChamados = count(array_filter($chamadosColab, fn ($c) => $c['status'] === 'fechado'));
        $temposRespostaChamados = array_filter(array_map(
            fn ($c) => $c['fechado_em'] ? (strtotime($c['fechado_em']) - strtotime($c['criado_em'])) / 60 : null,
            $chamadosColab
        ));
        $tempoMedioChamados = $temposRespostaChamados ? array_sum($temposRespostaChamados) / count($temposRespostaChamados) : null;

        // IP e "SO · Navegador" são identificadores REAIS, só que limitados
        // por design de qualquer navegador -- nenhum site, em lugar nenhum,
        // consegue ler nome da máquina, processador ou memória RAM de quem
        // acessa (bloqueado por privacidade, não é uma limitação daqui).
        // O IP pode vir em formato IPv6 (mais comprido, com ":") dependendo
        // da operadora de internet de quem acessou -- é o endereço de
        // verdade mesmo, só um formato menos familiar que o IPv4.
        $linhasLogins = [];
        foreach ($logins as $l) {
            $linhasLogins[] = [formatar_data($l['criado_em']), $l['ip'] ?: '-', identificar_dispositivo($l['user_agent'])];
        }

        // Chamados atendidos separados por status -- um bloco (tabela) por
        // status, na mesma ordem usada no resto do sistema, em vez de uma
        // lista só misturando tudo (pedido do Kleber: "separar por aberto,
        // aguardando cliente e encerrados").
        $chamadosPorStatus = ['aberto' => [], 'aguardando_equipe' => [], 'aguardando_cliente' => [], 'fechado' => []];
        foreach ($chamadosColab as $c) {
            $chamadosPorStatus[$c['status']][] = [
                truncarTexto($c['titulo'], 45),
                $c['cliente_nome'] ?? '-',
                formatar_data($c['criado_em']),
                $c['fechado_em'] ? formatar_data($c['fechado_em']) : '-',
                $c['fechado_em'] ? formatar_duracao_minutos((strtotime($c['fechado_em']) - strtotime($c['criado_em'])) / 60) : '-',
            ];
        }
        $colunasChamados = [
            'Título' => ['pdf' => 48, 'xlsx' => 35, 'align' => 'L'],
            'Cliente' => ['pdf' => 35, 'xlsx' => 22, 'align' => 'L'],
            'Aberto em' => ['pdf' => 27, 'xlsx' => 16, 'align' => 'L'],
            'Fechado em' => ['pdf' => 27, 'xlsx' => 16, 'align' => 'L'],
            'Tempo de atendimento' => ['pdf' => 37, 'xlsx' => 18, 'align' => 'L'],
        ];
        $blocosChamadosPorStatus = [];
        foreach ($chamadosPorStatus as $statusChave => $linhasStatus) {
            $blocosChamadosPorStatus[] = [
                'titulo' => 'Chamados · ' . (MAPA_STATUS_RELATORIO[$statusChave] ?? $statusChave),
                'colunas' => $colunasChamados,
                'linhas' => $linhasStatus,
            ];
        }

        return [
            'titulo' => 'Atividade do Colaborador',
            'descricaoFiltros' => $usuarioRelatorio['nome'] . ' (' . (PERFIS_USUARIO[$usuarioRelatorio['perfil']] ?? $usuarioRelatorio['perfil']) . ')'
                . ($dataDe || $dataAte ? ' · Período: ' . ($dataDe ?: 'início') . ' a ' . ($dataAte ?: 'hoje') : ' · Todo o período'),
            'nomeArquivo' => 'atividade-' . $usuarioRelatorio['nome'],
            'blocos' => array_merge(
                [
                    [
                        'titulo' => 'Resumo',
                        'colunas' => ['Métrica' => ['pdf' => 90, 'xlsx' => 40, 'align' => 'L'], 'Valor' => ['pdf' => 90, 'xlsx' => 25, 'align' => 'L']],
                        'linhas' => [
                            ['Logins no período', count($logins)],
                            ['Último login (histórico completo)', $usuarioRelatorio['ultimo_login_em'] ? formatar_data($usuarioRelatorio['ultimo_login_em']) : 'nunca acessou'],
                            ['Chamados atendidos no período', $totalChamados],
                            ['Chamados fechados no período', $fechadosChamados],
                            ['Tempo médio de atendimento (fechados no período)', $tempoMedioChamados !== null ? formatar_duracao_minutos($tempoMedioChamados) : 'sem chamados fechados no período'],
                        ],
                    ],
                ],
                $blocosChamadosPorStatus,
                [
                    [
                        'titulo' => 'Logins no período',
                        'colunas' => [
                            'Data/hora' => ['pdf' => 55, 'xlsx' => 30, 'align' => 'L'],
                            'IP (endereço real de internet)' => ['pdf' => 65, 'xlsx' => 30, 'align' => 'L'],
                            'Sistema · Navegador' => ['pdf' => 60, 'xlsx' => 25, 'align' => 'L'],
                        ],
                        'linhas' => $linhasLogins,
                    ],
                ]
            ),
        ];
    }

    return ['erro' => 'Relatório desconhecido.'];
}

$tipoRelatorio = $_GET['relatorio'] ?? '';
$formato = $_GET['formato'] ?? '';

if ($tipoRelatorio !== '' && isset(CATALOGO_RELATORIOS[$tipoRelatorio])) {
    $relatorio = gerarRelatorio($tipoRelatorio, $_GET, $pdo);

    if (empty($relatorio['erro']) && $formato === 'pdf') {
        $pdf = new PdfExport($relatorio['titulo']);
        $pdf->subtitulo($relatorio['descricaoFiltros']);
        foreach ($relatorio['blocos'] as $bloco) {
            if (!$bloco['linhas']) {
                continue;
            }
            if ($bloco['titulo']) {
                $pdf->tituloBloco($bloco['titulo']);
            }
            $colunasPdf = [];
            $alinhamentos = [];
            foreach ($bloco['colunas'] as $rotulo => $def) {
                $colunasPdf[$rotulo] = $def['pdf'];
                $alinhamentos[] = $def['align'];
            }
            $pdf->tabela($colunasPdf, $bloco['linhas'], $alinhamentos);
            $pdf->Ln(4);
        }
        $nomeArquivoPdf = preg_replace('/[^a-z0-9]+/i', '-', $relatorio['nomeArquivo'] ?? $relatorio['titulo']) . '-' . date('Ymd') . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $nomeArquivoPdf . '"');
        echo $pdf->bytes();
        exit;
    }

    if (empty($relatorio['erro']) && $formato === 'xlsx') {
        $xlsx = new XlsxExport($relatorio['titulo']);
        foreach ($relatorio['blocos'] as $indiceBloco => $bloco) {
            if (!$bloco['linhas']) {
                continue;
            }
            if ($bloco['titulo']) {
                $xlsx->tituloSecao($bloco['titulo']);
            }
            $colunasXlsx = [];
            foreach ($bloco['colunas'] as $rotulo => $def) {
                $colunasXlsx[$rotulo] = $def['xlsx'];
            }
            $xlsx->cabecalho($colunasXlsx);
            foreach ($bloco['linhas'] as $linha) {
                $xlsx->linha($linha);
            }
            $xlsx->linhaVazia();
        }
        $nomeArquivoXlsx = preg_replace('/[^a-z0-9]+/i', '-', $relatorio['nomeArquivo'] ?? $relatorio['titulo']) . '-' . date('Ymd') . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $nomeArquivoXlsx . '"');
        echo $xlsx->bytes();
        exit;
    }
}

$clientesParaFiltro = $pdo->query("SELECT id, nome FROM clientes WHERE status = 'ativo' ORDER BY nome ASC")->fetchAll();
$usuariosParaFiltro = $pdo->query("SELECT id, nome FROM usuarios WHERE ativo = 1 ORDER BY nome ASC")->fetchAll();

$tituloPagina = 'Relatórios';
require __DIR__ . '/templates/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-clipboard-data"></i> Relatórios</h4>
</div>

<?php if ($tipoRelatorio === '' || !isset(CATALOGO_RELATORIOS[$tipoRelatorio])): ?>
    <p class="text-muted mb-4">Relatórios prontos pra apresentação, exportáveis em PDF (com o timbrado oficial) ou Excel.</p>
    <div class="row g-3">
        <?php foreach (CATALOGO_RELATORIOS as $chave => $info): ?>
        <div class="col-md-6 col-lg-4">
            <a href="<?= url('relatorios.php?relatorio=' . $chave) ?>" class="text-decoration-none">
                <div class="card card-stat h-100 relatorio-card">
                    <div class="card-body">
                        <div class="relatorio-card-icone mb-3"><i class="bi <?= $info['icone'] ?>"></i></div>
                        <h6 class="fw-bold mb-2 text-dark"><?= e($info['titulo']) ?></h6>
                        <p class="text-muted small mb-0"><?= e($info['descricao']) ?></p>
                    </div>
                </div>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
<?php else:
    $infoRelatorio = CATALOGO_RELATORIOS[$tipoRelatorio];
    ?>
    <p class="text-muted small mb-3"><a href="<?= url('relatorios.php') ?>"><i class="bi bi-arrow-left"></i> Voltar pra Relatórios</a></p>

    <div class="card card-stat mb-4">
        <div class="card-body">
            <h5 class="mb-3"><i class="bi <?= $infoRelatorio['icone'] ?>"></i> <?= e($infoRelatorio['titulo']) ?></h5>
            <form method="get" class="row g-2 align-items-end">
                <input type="hidden" name="relatorio" value="<?= e($tipoRelatorio) ?>">
                <?php if ($infoRelatorio['precisaCliente']): ?>
                <div class="col-md-4">
                    <label class="form-label small mb-1">Cliente *</label>
                    <select name="cliente_id" class="form-select form-select-sm" required>
                        <option value="">Selecione...</option>
                        <?php foreach ($clientesParaFiltro as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= (int) ($_GET['cliente_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <?php if (!empty($infoRelatorio['precisaUsuario'])): ?>
                <div class="col-md-4">
                    <label class="form-label small mb-1">Colaborador *</label>
                    <select name="usuario_id" class="form-select form-select-sm" required>
                        <option value="">Selecione...</option>
                        <?php foreach ($usuariosParaFiltro as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= (int) ($_GET['usuario_id'] ?? 0) === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <?php if (in_array($tipoRelatorio, ['chamados_cliente', 'chamados_geral', 'atividade_colaborador'], true)): ?>
                <div class="col-md-3">
                    <label class="form-label small mb-1">De</label>
                    <input type="date" name="data_de" class="form-control form-control-sm" value="<?= e($_GET['data_de'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1">Até</label>
                    <input type="date" name="data_ate" class="form-control form-control-sm" value="<?= e($_GET['data_ate'] ?? '') ?>">
                </div>
                <?php endif; ?>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-sm btn-dark w-100"><i class="bi bi-search"></i> Gerar</button>
                </div>
            </form>
        </div>
    </div>

    <?php
    $relatorio = gerarRelatorio($tipoRelatorio, $_GET, $pdo);
    if (!empty($relatorio['erro'])): ?>
        <div class="alert alert-warning"><?= e($relatorio['erro']) ?></div>
    <?php else: ?>
        <div class="d-flex justify-content-between align-items-center mb-3">
            <p class="text-muted small mb-0"><?= e($relatorio['descricaoFiltros']) ?></p>
            <div class="d-flex gap-2">
                <a href="<?= url('relatorios.php?' . http_build_query(array_merge($_GET, ['formato' => 'pdf']))) ?>" class="btn btn-sm btn-outline-dark" target="_blank"><i class="bi bi-file-earmark-pdf"></i> Exportar PDF</a>
                <a href="<?= url('relatorios.php?' . http_build_query(array_merge($_GET, ['formato' => 'xlsx']))) ?>" class="btn btn-sm btn-outline-dark"><i class="bi bi-file-earmark-excel"></i> Exportar Excel</a>
            </div>
        </div>

        <?php foreach ($relatorio['blocos'] as $bloco): ?>
            <?php if ($bloco['titulo']): ?><h6 class="text-muted text-uppercase small fw-bold mt-3 mb-2"><?= e($bloco['titulo']) ?></h6><?php endif; ?>
            <?php if (!$bloco['linhas']): ?>
                <p class="text-muted text-center py-3">Nenhum dado encontrado.</p>
            <?php else: ?>
            <div class="card card-stat mb-3">
                <div class="card-body p-0">
                    <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr><?php foreach (array_keys($bloco['colunas']) as $rotulo): ?><th><?= e($rotulo) ?></th><?php endforeach; ?></tr></thead>
                        <tbody>
                        <?php foreach ($bloco['linhas'] as $linha): ?>
                            <tr><?php foreach ($linha as $valor): ?><td><?= e((string) $valor) ?></td><?php endforeach; ?></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        <?php endforeach; ?>

        <div class="d-flex gap-2 mb-4">
            <a href="<?= url('relatorios.php?' . http_build_query(array_merge($_GET, ['formato' => 'pdf']))) ?>" class="btn btn-sm btn-outline-dark" target="_blank"><i class="bi bi-file-earmark-pdf"></i> Exportar PDF</a>
            <a href="<?= url('relatorios.php?' . http_build_query(array_merge($_GET, ['formato' => 'xlsx']))) ?>" class="btn btn-sm btn-outline-dark"><i class="bi bi-file-earmark-excel"></i> Exportar Excel</a>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/templates/footer.php'; ?>
