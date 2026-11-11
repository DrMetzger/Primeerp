#!/usr/bin/env python3
"""
Gera a textura de placa de circuito do site (SVG em ladrilho que se repete
sem emenda): trilhas em 90/45 graus, vias (bolinhas) e "chips" retangulares.
Sai como CSS pronto (variáveis --textura-claro / --textura-escuro).

Uso: python3 gerar-textura.py > textura.css
"""
import random
from urllib.parse import quote

TAM = 480
PASSO = 24
rnd = random.Random(2026)


def trilhas() -> list[str]:
    partes = []
    feitas = 0
    while feitas < 30:
        x = rnd.randrange(1, TAM // PASSO) * PASSO
        y = rnd.randrange(1, TAM // PASSO) * PASSO
        x0, y0 = x, y
        d = f'M{x} {y}'
        dentro = True
        for _ in range(rnd.randint(2, 4)):
            dx, dy = rnd.choice([(1, 0), (0, 1), (-1, 0), (0, -1), (1, 1), (1, -1)])
            n = rnd.randint(1, 4)
            x, y = x + dx * PASSO * n, y + dy * PASSO * n
            dentro = dentro and PASSO <= x <= TAM - PASSO and PASSO <= y <= TAM - PASSO
            d += f' L{x} {y}'
        if not dentro:
            continue  # só trilhas inteiras dentro do ladrilho: repete sem emenda
        feitas += 1
        partes.append(f'<path d="{d}"/>')
        partes.append(f'<circle cx="{x0}" cy="{y0}" r="3.2"/><circle cx="{x}" cy="{y}" r="3.2"/>')
    return partes


def chips() -> list[str]:
    partes = []
    for _ in range(4):
        x = rnd.randrange(2, TAM // PASSO - 4) * PASSO
        y = rnd.randrange(2, TAM // PASSO - 4) * PASSO
        l, a = PASSO * rnd.randint(2, 3), PASSO * 2
        partes.append(f'<rect x="{x}" y="{y}" width="{l}" height="{a}" rx="3"/>')
        for i in range(1, l // 8):
            partes.append(f'<path d="M{x + i * 8} {y} v-6 M{x + i * 8} {y + a} v6"/>')
    return partes


desenho = trilhas() + chips()


def svg(cor: str) -> str:
    copias = ''.join(desenho)
    return (f'<svg xmlns="http://www.w3.org/2000/svg" width="{TAM}" height="{TAM}" viewBox="0 0 {TAM} {TAM}">'
            f'<g fill="none" stroke="{cor}" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round">{copias}</g></svg>')


def uri(cor: str) -> str:
    return 'url("data:image/svg+xml,' + quote(svg(cor), safe=' /=:(),.-') + '")'


import sys
if len(sys.argv) > 1 and sys.argv[1] == '--ts':
    # Mesma placa de circuito pro app (React Native, via SvgXml).
    print('// Gerado por novo/ferramentas/gerar-textura.py -- não editar à mão.')
    print(f'export const TAMANHO_TEXTURA = {TAM};')
    print('export function svgTextura(cor: string): string {')
    print('  return ' + repr(svg('__COR__')).replace("'__COR__'", "' + cor + '").replace('__COR__', "' + cor + '") + ';')
    print('}')
else:
    print('/* Gerado por novo/ferramentas/gerar-textura.py -- não editar à mão. */')
    print(':root {')
    print(f'  --textura-claro: {uri("#1E3FD8")};')
    print(f'  --textura-escuro: {uri("#2FD3E5")};')
    print('}')
