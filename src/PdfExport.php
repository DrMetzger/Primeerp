<?php

namespace AdminPrime;

/**
 * PDF timbrado padrão do Prime Hub -- pedido do Kleber pra que TODO export em
 * PDF do sistema (não só as sugestões de IA da Implantação) siga o mesmo
 * timbrado oficial. Como não há acesso, neste ambiente, aos dois arquivos de
 * imagem do timbrado oficial (cabeçalho e rodapé) que ele anexou na
 * conversa, o cabeçalho/rodapé aqui são desenhados em código reaproveitando
 * a identidade visual JÁ usada no app (gradiente índigo/roxo do navbar, ver
 * ".navbar-dark.bg-dark" em assets/css/style.css, e a logo branca
 * assets/img/logo.png sobre fundo colorido, mesma combinação de lá) -- se um
 * dia os arquivos originais do timbrado forem salvos em
 * assets/img/timbrado-cabecalho.png / timbrado-rodape.png, esta classe pode
 * trocar pra usar Image() com eles no lugar da faixa desenhada.
 *
 * Baseado em FPDF (vendorizado manualmente em vendor/fpdf, sem Composer --
 * mesmo esquema do PHPMailer, ver bootstrap.php).
 */
class PdfExport extends \FPDF
{
    private const FAIXA_STOPS = [
        [30, 27, 75],   // #1e1b4b
        [55, 48, 163],  // #3730a3
        [109, 40, 217], // #6d28d9
    ];
    private const ALTURA_CABECALHO = 28;
    private const ALTURA_RODAPE = 16;
    private const RODAPE_URL = 'www.primeerp.com.br';

    private string $tituloDocumento;

    public function __construct(string $tituloDocumento = '')
    {
        parent::__construct('P', 'mm', 'A4');
        $this->tituloDocumento = $tituloDocumento;
        $this->SetMargins(15, self::ALTURA_CABECALHO + 6, 15);
        $this->SetAutoPageBreak(true, self::ALTURA_RODAPE + 10);
        $this->AliasNbPages();
        $this->AddPage();
    }

    public function Header(): void
    {
        $this->desenharFaixaGradiente(0, self::ALTURA_CABECALHO, false);

        $logo = __DIR__ . '/../assets/img/logo.png';
        if (is_file($logo)) {
            $this->Image($logo, 15, 8, 40);
        }

        if ($this->tituloDocumento !== '') {
            $this->SetFont('Helvetica', 'B', 11);
            $this->SetTextColor(255, 255, 255);
            $this->SetXY(15, self::ALTURA_CABECALHO - 10);
            $this->Cell(180, 6, $this->texto($this->tituloDocumento), 0, 0, 'R');
        }

        $this->SetY(self::ALTURA_CABECALHO + 6);
        $this->SetTextColor(20, 20, 20);
    }

    public function Footer(): void
    {
        $yFaixa = $this->GetPageHeight() - self::ALTURA_RODAPE;
        $this->desenharFaixaGradiente($yFaixa, self::ALTURA_RODAPE, true);

        // Pedido explícito: NO rodapé só o site da Prime, nada mais (sem
        // numeração de página, sem outro texto).
        $this->SetY(-self::ALTURA_RODAPE / 2 - 3);
        $this->SetFont('Helvetica', 'B', 10.5);
        $this->SetTextColor(255, 255, 255);
        $this->Cell(0, 6, self::RODAPE_URL, 0, 0, 'C');
    }

    /**
     * Escreve um bloco de texto livre (já editado pelo admin) respeitando
     * quebras de linha/paragrafo -- usado pra texto simples, sem nenhuma
     * marcação. Pra texto gerado por IA (que vem em Markdown -- ##, **,
     * listas), usar escreverMarkdown() abaixo, que de fato interpreta essas
     * marcações em vez de jogar o símbolo cru no PDF.
     */
    public function escreverTexto(string $texto): void
    {
        $this->SetFont('Helvetica', '', 10.5);
        $this->SetTextColor(20, 20, 20);
        $this->MultiCell(0, 5.5, $this->texto($texto));
    }

    public function subtitulo(string $texto): void
    {
        $this->SetFont('Helvetica', 'B', 9);
        $this->SetTextColor(110, 110, 110);
        $this->Cell(0, 6, $this->texto($texto), 0, 1);
        $this->Ln(1);
    }

    /**
     * Renderiza um texto em MARKDOWN simples (o formato que a IA já é
     * instruída a usar, ver cliente.php/AiSugestoes) com hierarquia visual
     * de verdade -- antes o texto ia cru pro PDF (##, ** e "-" apareciam
     * como símbolo literal, tudo no mesmo tamanho/peso de fonte, "plano"),
     * o que o Kleber não podia entregar pro cliente como documento oficial.
     * Suporta: títulos (#/##/###), negrito inline (**texto**), listas
     * ("- item" ou "1. item") e separador de seção ("---").
     */
    public function escreverMarkdown(string $texto): void
    {
        $linhas = explode("\n", str_replace("\r\n", "\n", $texto));
        $paragrafoAtual = [];

        $flushParagrafo = function () use (&$paragrafoAtual): void {
            if (!$paragrafoAtual) {
                return;
            }
            $this->paragrafoMarkdown(implode(' ', $paragrafoAtual));
            $paragrafoAtual = [];
        };

        foreach ($linhas as $linha) {
            $linhaAparada = trim($linha);

            if ($linhaAparada === '') {
                $flushParagrafo();
                continue;
            }

            if (preg_match('/^(#{1,3})\s+(.+)$/', $linhaAparada, $m)) {
                $flushParagrafo();
                $this->tituloMarkdown($m[2], strlen($m[1]));
                continue;
            }

            if (preg_match('/^-{3,}$/', $linhaAparada) || preg_match('/^\*{3,}$/', $linhaAparada)) {
                $flushParagrafo();
                $this->separadorMarkdown();
                continue;
            }

            if (preg_match('/^[-*]\s+(.+)$/', $linhaAparada, $m)) {
                $flushParagrafo();
                $this->itemListaMarkdown($m[1]);
                continue;
            }

            if (preg_match('/^(\d+)[.)]\s+(.+)$/', $linhaAparada, $m)) {
                $flushParagrafo();
                $this->itemListaMarkdown($m[2], $m[1] . '.');
                continue;
            }

            $paragrafoAtual[] = $linhaAparada;
        }

        $flushParagrafo();
    }

    private const TAMANHOS_TITULO_MARKDOWN = [1 => 14.5, 2 => 12.5, 3 => 11];
    private const COR_TITULO_MARKDOWN = [55, 48, 163]; // mesmo tom do meio do gradiente do timbrado

    private function tituloMarkdown(string $texto, int $nivel): void
    {
        // Espaço antes do título (menos pro primeiro bloco da página, pra
        // não abrir com um vão enorme embaixo do cabeçalho).
        if ($this->GetY() > $this->tMargin + 2) {
            $this->Ln($nivel === 1 ? 4 : 2.5);
        }

        $this->SetFont('Helvetica', 'B', self::TAMANHOS_TITULO_MARKDOWN[$nivel] ?? 10.5);
        $this->SetTextColor(...self::COR_TITULO_MARKDOWN);
        $this->escreverInlineMarkdown($texto, $nivel === 1 ? 7 : 6);
        $this->Ln($nivel === 1 ? 2 : 1.5);
        $this->SetTextColor(20, 20, 20);
    }

    private function paragrafoMarkdown(string $texto): void
    {
        $this->SetFont('Helvetica', '', 10.5);
        $this->SetTextColor(20, 20, 20);
        $this->escreverInlineMarkdown($texto, 5.5);
        $this->Ln(3);
    }

    private function itemListaMarkdown(string $texto, ?string $marcador = null): void
    {
        $this->SetFont('Helvetica', '', 10.5);
        $this->SetTextColor(20, 20, 20);

        $xInicial = $this->GetX();
        $indentacao = 6;
        $this->Cell($indentacao, 5.5, $this->texto($marcador ?? '-'));
        $this->SetX($xInicial + $indentacao);
        $this->escreverInlineMarkdown($texto, 5.5, $indentacao);
        $this->Ln(1.5);
    }

    private function separadorMarkdown(): void
    {
        $this->Ln(1.5);
        $this->SetDrawColor(224, 224, 231);
        $this->Line($this->GetX(), $this->GetY(), $this->GetX() + $this->GetPageWidth() - $this->lMargin - $this->rMargin, $this->GetY());
        $this->Ln(3.5);
    }

    /**
     * Escreve uma linha/parágrafo respeitando **negrito** inline -- FPDF
     * não tem rich text nativo, então alterna a fonte a cada trecho e usa
     * Write() (que flui e quebra linha automaticamente) em vez de Cell/
     * MultiCell, que travariam tudo num único estilo. $indentacaoContinuacao
     * é o quanto recuar as linhas SEGUINTES quando o texto quebra (0 = sem
     * recuo, usado nos itens de lista pra alinhar com o texto após o
     * marcador, não embaixo dele).
     */
    private function escreverInlineMarkdown(string $texto, float $alturaLinha, float $indentacaoContinuacao = 0): void
    {
        if ($indentacaoContinuacao > 0) {
            $this->SetLeftMargin($this->lMargin + $indentacaoContinuacao);
        }

        $fonteAtual = $this->FontFamily;
        $tamanhoAtual = $this->FontSizePt;

        // Primeiro separa **negrito**, depois -- só dentro dos trechos que
        // NÃO são negrito -- separa *itálico* (asterisco duplo já foi
        // consumido antes, então um asterisco sozinho aqui é sempre itálico
        // de verdade, sem ambiguidade).
        $partesNegrito = preg_split('/\*\*(.+?)\*\*/s', $texto, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($partesNegrito as $indiceNegrito => $parteNegrito) {
            if ($parteNegrito === '') {
                continue;
            }
            $negrito = $indiceNegrito % 2 === 1;
            if ($negrito) {
                $this->SetFont($fonteAtual, 'B', $tamanhoAtual);
                $this->Write($alturaLinha, $this->texto($parteNegrito));
                continue;
            }

            $partesItalico = preg_split('/\*(.+?)\*/s', $parteNegrito, -1, PREG_SPLIT_DELIM_CAPTURE);
            foreach ($partesItalico as $indiceItalico => $parteItalico) {
                if ($parteItalico === '') {
                    continue;
                }
                $italico = $indiceItalico % 2 === 1;
                $this->SetFont($fonteAtual, $italico ? 'I' : '', $tamanhoAtual);
                $this->Write($alturaLinha, $this->texto($parteItalico));
            }
        }
        $this->Ln($alturaLinha);

        if ($indentacaoContinuacao > 0) {
            $this->SetLeftMargin($this->lMargin - $indentacaoContinuacao);
        }
    }

    /**
     * Gera o PDF e devolve os bytes prontos pra download -- quem chamou
     * decide o nome do arquivo e os cabeçalhos HTTP.
     */
    public function bytes(): string
    {
        return $this->Output('S');
    }

    /**
     * FPDF só aceita ISO-8859-1 (Latin-1) internamente -- todo texto vindo
     * do banco/usuário está em UTF-8, então precisa converter aqui, senão
     * acento sai como símbolo quebrado no PDF.
     */
    private function texto(string $s): string
    {
        return iconv('UTF-8', 'ISO-8859-1//IGNORE', $s) ?: $s;
    }

    /**
     * Faixa colorida horizontal com gradiente de 3 tons (mesmas cores do
     * gradiente do navbar) e uma leve curva ondulada na borda -- aproximação
     * em código da "onda" do timbrado oficial. $borboletaNaBase decide se a
     * onda fica na borda de baixo (cabeçalho) ou de cima (rodapé).
     */
    private function desenharFaixaGradiente(float $y, float $altura, bool $ondaNoTopo): void
    {
        $largura = $this->GetPageWidth();
        $passos = 60;
        $largPasso = $largura / $passos;
        [$r1, $g1, $b1] = self::FAIXA_STOPS[0];
        [$r2, $g2, $b2] = self::FAIXA_STOPS[1];
        [$r3, $g3, $b3] = self::FAIXA_STOPS[2];

        for ($i = 0; $i < $passos; $i++) {
            $t = $i / ($passos - 1);
            if ($t <= 0.5) {
                $t2 = $t / 0.5;
                $r = $r1 + ($r2 - $r1) * $t2;
                $g = $g1 + ($g2 - $g1) * $t2;
                $b = $b1 + ($b2 - $b1) * $t2;
            } else {
                $t2 = ($t - 0.5) / 0.5;
                $r = $r2 + ($r3 - $r2) * $t2;
                $g = $g2 + ($g3 - $g2) * $t2;
                $b = $b2 + ($b3 - $b2) * $t2;
            }
            $this->SetFillColor((int) round($r), (int) round($g), (int) round($b));
            $this->Rect($i * $largPasso, $y, $largPasso + 0.5, $altura, 'F');
        }

        $this->desenharOnda($y, $altura, $ondaNoTopo, $largura);
    }

    /**
     * Uma curva Bezier simples e clara sobreposta na borda da faixa --
     * FPDF "puro" (sem extensões) não tem método de curva pronto, então
     * escreve o operador PDF de curva ("c") direto, igual o próprio FPDF
     * faz internamente em Line()/Rect().
     */
    private function desenharOnda(float $yFaixa, float $altura, bool $ondaNoTopo, float $largura): void
    {
        $yBase = $ondaNoTopo ? $yFaixa : $yFaixa + $altura;
        $amplitude = 2.6;
        // Tom mais claro que a faixa de base, só pra dar profundidade --
        // roxo claro pro rodapé (base índigo escuro), índigo claro pro
        // cabeçalho (base violeta).
        $corClara = $ondaNoTopo ? [124, 58, 237] : [79, 70, 229];

        $this->SetFillColor($corClara[0], $corClara[1], $corClara[2]);

        $segmentos = 3;
        $largSeg = $largura / $segmentos;
        $k = $this->k;
        $h = $this->h;

        $pontoPdf = static fn (float $x, float $y): string => sprintf('%.2F %.2F', $x * $k, ($h - $y) * $k);

        $sinalBase = $ondaNoTopo ? -1 : 1;
        $out = sprintf('%s m', $pontoPdf(0, $yBase));
        for ($i = 0; $i < $segmentos; $i++) {
            $xIni = $i * $largSeg;
            $xFim = $xIni + $largSeg;
            $sinal = $sinalBase * ($i % 2 === 0 ? 1 : -1);
            $yControle = $yBase + $sinal * $amplitude;
            $out .= sprintf(
                ' %s %s %s c',
                $pontoPdf($xIni + $largSeg * 0.25, $yControle),
                $pontoPdf($xIni + $largSeg * 0.75, $yControle),
                $pontoPdf($xFim, $yBase)
            );
        }
        // Fecha o polígono com uma linha reta um pouco ANTES do início da
        // onda (só a amplitude, não a altura da faixa inteira) -- é isso
        // que dá aquele "lábio" fino e ondulado, em vez de um bloco enorme
        // cobrindo o resto da página (bug já visto e corrigido aqui).
        $yFechamento = $ondaNoTopo ? $yBase - $amplitude : $yBase + $amplitude;
        $out .= sprintf(' %s l %s l h f', $pontoPdf($largura, $yFechamento), $pontoPdf(0, $yFechamento));

        $this->_out($out);
    }
}
