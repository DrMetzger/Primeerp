import { temas, type NomeTema } from '@primehub/shared';

/** Variáveis CSS dos dois temas (fonte única: packages/shared). */
export function cssDosTemas(): string {
  return (Object.keys(temas) as NomeTema[])
    .map((nome) => {
      const vars = Object.entries(temas[nome]).map(([k, v]) => `--${k}:${v};`).join('');
      return `html[data-tema="${nome}"]{${vars}color-scheme:${nome === 'escuro' ? 'dark' : 'light'};}`;
    })
    .join('\n');
}

/**
 * Roda antes do React (sem "piscar" no tema errado): usa o tema salvo pela
 * pessoa ou, se nunca escolheu, o do sistema do aparelho.
 */
export const scriptTema = `(function(){try{var t=localStorage.getItem('ph_tema');if(t!=='claro'&&t!=='escuro'){t=matchMedia('(prefers-color-scheme: dark)').matches?'escuro':'claro'}document.documentElement.dataset.tema=t}catch(e){document.documentElement.dataset.tema='claro'}})();`;
