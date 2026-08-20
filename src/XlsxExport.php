<?php

namespace AdminPrime;

/**
 * Gerador mínimo de planilha .xlsx REAL (OOXML) -- até essa classe existir,
 * todo "export pra Excel" do sistema (exportar_chamados.php, exportar_
 * usuarios_cliente.php etc.) era, na prática, um .csv com BOM UTF-8 que
 * abre direto no Excel, mas sem nenhuma formatação (sem negrito, sem cor,
 * sem largura de coluna -- o Excel só desenha uma grade cinza padrão).
 * Pedido explícito do Kleber pra Relatórios: "formatação digna para
 * apresentação para diretoria" -- isso exige um .xlsx de verdade, não CSV.
 *
 * Não usamos nenhuma lib (esse projeto não tem Composer, ver bootstrap.php)
 * -- um .xlsx é só um .zip com alguns XMLs dentro (OOXML), e ZipArchive já
 * é usado em outro lugar do app (download_fontes_advpl_zip.php), então dá
 * pra montar um sozinho. Suporta só o essencial: UMA aba, título de seção
 * (pra relatórios com mais de uma tabela, ex.: Estatísticas), cabeçalho em
 * negrito com fundo colorido, largura de coluna configurável, zebra nas
 * linhas de dado, congelar a primeira linha ao rolar. Sem fórmulas, sem
 * múltiplas abas, sem gráfico -- se um dia precisar de mais que isso,
 * trocar por uma lib de verdade (PhpSpreadsheet) é o caminho certo, não
 * inflar essa classe.
 *
 * Uso: iniciarSecao() é OPCIONAL (só quando o relatório tem mais de uma
 * tabela) -&gt; cabecalho() define as colunas dali pra frente -&gt; linha() uma
 * vez por linha de dado -&gt; linhaVazia() antes da seção seguinte, se houver.
 */
class XlsxExport
{
    private array $linhasXml = [];
    private int $proximoNumeroLinha = 1;
    private array $largurasPorColuna = []; // índice da coluna (0-based) => largura MÁXIMA já vista
    private int $colunasNaSecaoAtual = 0;
    private string $tituloPlanilha;

    public function __construct(string $tituloPlanilha = 'Relatório')
    {
        // Nome da aba no Excel tem limite de 31 caracteres e não aceita
        // : \ / ? * [ ] -- corta e limpa pra nunca gerar um arquivo corrompido.
        $this->tituloPlanilha = substr(preg_replace('/[:\\\\\/?*\[\]]/', ' ', $tituloPlanilha), 0, 31) ?: 'Relatório';
    }

    /** Linha de título de seção -- só o essencial pra separar visualmente duas tabelas na mesma aba (relatórios com mais de um bloco, ex.: Estatísticas). */
    public function tituloSecao(string $titulo): void
    {
        $this->linhasXml[] = '<row r="' . $this->proximoNumeroLinha . '" ht="22" customHeight="1">'
            . $this->celula($this->proximoNumeroLinha, 1, $titulo, 3)
            . '</row>';
        $this->proximoNumeroLinha++;
    }

    /** @param array<string,int> $colunasComLargura Rótulo => largura aproximada em caracteres. Define as colunas válidas pra linha() até a próxima chamada. */
    public function cabecalho(array $colunasComLargura): void
    {
        $this->colunasNaSecaoAtual = count($colunasComLargura);
        $celulas = '';
        foreach (array_values($colunasComLargura) as $indice => $largura) {
            $this->largurasPorColuna[$indice] = max($this->largurasPorColuna[$indice] ?? 0, $largura);
        }
        foreach (array_keys($colunasComLargura) as $indice => $rotulo) {
            $celulas .= $this->celula($this->proximoNumeroLinha, $indice + 1, $rotulo, 1);
        }
        $this->linhasXml[] = '<row r="' . $this->proximoNumeroLinha . '" ht="20" customHeight="1">' . $celulas . '</row>';
        $this->proximoNumeroLinha++;
    }

    /** @param array<int,string|int|float|null> $valores Na MESMA ordem do cabecalho() dessa seção. */
    public function linha(array $valores): void
    {
        $numeroLinha = $this->proximoNumeroLinha;
        $celulas = '';
        foreach (array_values($valores) as $indice => $valor) {
            $celulas .= $this->celula($numeroLinha, $indice + 1, $valor, 2);
        }
        $this->linhasXml[] = '<row r="' . $numeroLinha . '">' . $celulas . '</row>';
        $this->proximoNumeroLinha++;
    }

    /** Espaço entre uma tabela e a próxima, em relatórios de vários blocos. */
    public function linhaVazia(): void
    {
        $this->proximoNumeroLinha++;
    }

    public function bytes(): string
    {
        $arquivoTemp = tempnam(sys_get_temp_dir(), 'xlsx_');
        $zip = new \ZipArchive();
        $zip->open($arquivoTemp, \ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml', $this->contentTypesXml());
        $zip->addFromString('_rels/.rels', $this->relsRaizXml());
        $zip->addFromString('xl/workbook.xml', $this->workbookXml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelsXml());
        $zip->addFromString('xl/styles.xml', $this->stylesXml());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheetXml());
        $zip->close();

        $bytes = file_get_contents($arquivoTemp);
        unlink($arquivoTemp);

        return $bytes;
    }

    private function contentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private function relsRaizXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function workbookXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . $this->escapar($this->tituloPlanilha) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private function workbookRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    /**
     * 4 estilos (cellXfs): 0 = padrão, 1 = cabeçalho de tabela (negrito,
     * branco, fundo índigo -- mesmo tom do timbrado/navbar), 2 = célula
     * normal com borda fina cinza clara, 3 = título de seção (negrito,
     * índigo, sem fundo).
     */
    private function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="3">'
            . '<font><sz val="10"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="12"/><color rgb="FF3730A3"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="3">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF3730A3"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="2">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left style="thin"><color rgb="FFE0E0E7"/></left><right style="thin"><color rgb="FFE0E0E7"/></right><top style="thin"><color rgb="FFE0E0E7"/></top><bottom style="thin"><color rgb="FFE0E0E7"/></bottom><diagonal/></border>'
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="4">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"><alignment vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"><alignment vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"><alignment vertical="center"/></xf>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private function sheetXml(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        // Ordem dos filhos de &lt;worksheet&gt; é FIXA pelo schema OOXML
        // (sheetViews antes de cols, antes de sheetData) -- inverter isso
        // (como numa versão anterior desse arquivo) faz o Excel/LibreOffice
        // recusar o arquivo inteiro ("não foi possível carregar"), mesmo
        // com todo o XML dentro sendo perfeitamente bem formado.
        $xml .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';

        if ($this->largurasPorColuna) {
            $xml .= '<cols>';
            foreach ($this->largurasPorColuna as $indice => $largura) {
                $col = $indice + 1;
                $xml .= '<col min="' . $col . '" max="' . $col . '" width="' . max(8, (int) $largura) . '" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }

        $xml .= '<sheetData>' . implode('', $this->linhasXml) . '</sheetData></worksheet>';

        return $xml;
    }

    /**
     * Número/decimal vira célula numérica de verdade (t omitido = number,
     * fica alinhado à direita e dá pra somar/filtrar no Excel); qualquer
     * outra coisa vira texto inline (t="inlineStr") -- sem tabela de
     * strings compartilhadas de propósito, é complexidade que essa
     * exportação simples não precisa.
     */
    private function celula(int $linha, int $coluna, mixed $valor, int $estilo): string
    {
        $referencia = $this->referenciaCelula($coluna) . $linha;

        if (is_int($valor) || is_float($valor)) {
            return '<c r="' . $referencia . '" s="' . $estilo . '"><v>' . $valor . '</v></c>';
        }

        $texto = (string) ($valor ?? '');

        return '<c r="' . $referencia . '" s="' . $estilo . '" t="inlineStr"><is><t xml:space="preserve">' . $this->escapar($texto) . '</t></is></c>';
    }

    private function referenciaCelula(int $coluna): string
    {
        $letra = '';
        while ($coluna > 0) {
            $resto = ($coluna - 1) % 26;
            $letra = chr(65 + $resto) . $letra;
            $coluna = intdiv($coluna - 1, 26);
        }

        return $letra;
    }

    private function escapar(string $texto): string
    {
        // XML 1.0 não aceita boa parte dos caracteres de controle (mesmo
        // escapados) -- remove-los é mais seguro do que deixar o Excel
        // recusar o arquivo inteiro por um caractere invisível perdido
        // (ex.: copiar/colar de um PDF de origem).
        $texto = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $texto) ?? $texto;

        return htmlspecialchars($texto, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
