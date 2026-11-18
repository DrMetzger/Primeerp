// Conversão de fonte de relatório ADVPL/TLPP para User Function, no padrão
// PrimeERP combinado com a equipe:
//  - o arquivo vira U + nome original (MATR435 -> UMATR435.prw);
//  - cada Function NÃO estática vira "User Function" com a 1ª letra trocada
//    por X e no máximo 7 letras (MATR435 -> XATR435), chamada como U_XATR435;
//  - Static Function não muda (é local do fonte, não entra no RPO pelo nome);
//  - chamadas internas às funções convertidas viram U_<novo>.
// Nada aqui toca em servidor: é só texto. A compilação é um passo à parte,
// sempre revisado por uma pessoa antes de ir a um cliente.

export interface FuncaoConvertida {
  original: string;
  novo: string;
  /** Como passa a ser chamada no ADVPL (U_ + nome novo). */
  chamada: string;
}

export interface ConversaoRelatorio {
  nomeOriginal: string;
  /** Nome de arquivo sugerido para o fonte convertido (ex.: UMATR435.prw). */
  nomeArquivo: string;
  /** Função principal convertida (a que o cliente vai chamar). */
  principal: string;
  fonte: string;
  funcoes: FuncaoConvertida[];
  /** Pontos que uma pessoa precisa conferir (colisões, nomes cortados...). */
  avisos: string[];
}

/** Nome customizado: troca a 1ª letra por X e limita a 7 (MATR435 -> XATR435). */
export function nomeCustomizado(original: string): string {
  return ('X' + original.slice(1)).slice(0, 7).toUpperCase();
}

const DECLARACAO = /^([ \t]*)(user[ \t]+function|static[ \t]+function|function)([ \t]+)([A-Za-z_]\w*)/gim;
const ALTERNATIVAS = '23456789BCDEFGHJKLMNPQRSTUVWXYZ';

/**
 * Converte o fonte de um relatório para User Function. `nomeBase` é o nome do
 * programa (ex.: "MATR435"); se vier vazio, usa a 1ª Function não estática.
 */
export function converterRelatorio(fonteEntrada: string, nomeBase: string): ConversaoRelatorio {
  const avisos: string[] = [];
  const fonte = fonteEntrada.replace(/\r\n/g, '\n');

  // 1) Todas as declarações, para saber o que é estático e o que é público.
  const declaracoes: { estatica: boolean; nome: string }[] = [];
  for (const m of fonte.matchAll(DECLARACAO)) {
    const tipo = (m[2] ?? '').toLowerCase();
    const nome = m[4];
    if (!nome) continue;
    declaracoes.push({ estatica: tipo.startsWith('static'), nome });
  }

  // 2) Mapa de renomeação: só Function/User Function NÃO estáticas.
  const mapa = new Map<string, string>(); // NOME_MAIUSCULO -> novo
  const usados = new Map<string, string>(); // novo -> nome original
  for (const d of declaracoes) {
    const chave = d.nome.toUpperCase();
    if (d.estatica || mapa.has(chave)) continue;
    let novo = nomeCustomizado(d.nome);
    if (usados.has(novo)) {
      const base = novo;
      for (const c of ALTERNATIVAS) {
        const cand = (base.slice(0, 6) + c);
        if (!usados.has(cand)) { novo = cand; break; }
      }
      avisos.push(`"${d.nome}" colidiria com "${usados.get(base)}" em "${base}"; usei "${novo}". Confira se é o nome que você quer.`);
    } else if (d.nome.length > 7) {
      avisos.push(`"${d.nome}" tem mais de 7 letras; virou "${novo}" (cortado no limite do U_).`);
    }
    usados.set(novo, d.nome);
    mapa.set(chave, novo);
  }
  if (mapa.size === 0) avisos.push('Nenhuma Function não estática encontrada — confira se este fonte é mesmo um relatório padrão.');

  // 3) Troca as declarações não estáticas por "User Function <novo>".
  let saida = fonte.replace(DECLARACAO, (todo, ind, _kw, _sp, nome) => {
    const novo = mapa.get(nome.toUpperCase());
    return novo ? `${ind}User Function ${novo}` : todo;
  });

  // 4) Troca as CHAMADAS NOME( -> U_<novo>(  (palavra inteira, antes de parêntese).
  for (const [chave, novo] of mapa) {
    saida = saida.replace(new RegExp(`\\b${chave}\\b(?=[ \\t]*\\()`, 'gi'), `U_${novo}`);
  }

  // 5) Cabeçalho Protheus.doc na função principal.
  const principal = mapa.get(nomeBase.toUpperCase()) ?? [...mapa.values()][0] ?? nomeCustomizado(nomeBase);
  const hoje = new Date().toISOString().slice(0, 10).split('-').reverse().join('/');
  const doc = `/*/{Protheus.doc} ${principal}\nCustomizacao do relatorio ${nomeBase} convertido para User Function.\n@author PrimeERP\n@since ${hoje}\n/*/`;
  if (/#INCLUDE[ \t]+"PROTHEUS/i.test(saida)) {
    saida = saida.replace(/(#INCLUDE[ \t]+"PROTHEUS[^\n]*\n)/i, `$1${doc}\n`);
  } else {
    saida = `#INCLUDE "PROTHEUS.CH"\n${doc}\n${saida}`;
  }

  return {
    nomeOriginal: nomeBase,
    nomeArquivo: ('U' + nomeBase).toUpperCase().replace(/\.(PRW|TLPP|PRX)$/i, '') + '.prw',
    principal,
    fonte: saida,
    funcoes: [...mapa].map(([original, novo]) => ({ original, novo, chamada: 'U_' + novo })),
    avisos,
  };
}
