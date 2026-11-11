#!/usr/bin/env python3
"""
Recorta as peças do robô assistente a partir de uma "folha de personagem"
gerada por IA e remove o fundo:

  --fundo verde : peças sólidas sobre verde chapado (#00FF00) -> PNG transparente
  --fundo preto : peças de luz (olhos, boca, propulsor) sobre preto (#000000)
                  -> PNG com alfa pela luminosidade (fica perfeito com brilho)

Cada peça vira um arquivo peca-01.png, peca-02.png... na ordem de leitura
(linhas de cima pra baixo, da esquerda pra direita) + pecas.json com o
tamanho de cada uma. Sem dependências além de Pillow e numpy.

Uso: python3 recortar-pecas.py folha.png pasta-saida --fundo verde|preto
"""
import argparse
import json
import sys
from collections import deque
from pathlib import Path

import numpy as np
from PIL import Image


def mascara_verde(rgb: np.ndarray) -> tuple[np.ndarray, np.ndarray]:
    r, g, b = (rgb[..., i].astype(np.float32) for i in range(3))
    # Quanto o verde domina o pixel (0 = nada de verde, 1 = verde chapado).
    dominancia = (g - np.maximum(r, b)) / np.maximum(g, 1)
    alfa = np.clip(1.0 - (dominancia - 0.18) / 0.32, 0.0, 1.0)
    # Tira o "vazamento" de verde nas bordas (despill).
    g_limpo = np.minimum(g, np.maximum(r, b) * 1.05)
    rgb_limpo = np.stack([r, g_limpo, b], axis=-1)
    return alfa, rgb_limpo


def mascara_preta(rgb: np.ndarray) -> tuple[np.ndarray, np.ndarray]:
    rgbf = rgb.astype(np.float32)
    luz = rgbf.max(axis=-1)
    alfa = np.clip((luz - 18.0) / 200.0, 0.0, 1.0)
    # Desfaz a mistura com o preto: cor "pura" = cor / alfa.
    rgb_puro = np.clip(rgbf / np.maximum(alfa[..., None], 1e-3), 0, 255)
    return alfa, rgb_puro


def componentes(ocupado: np.ndarray, area_minima: int) -> list[tuple[int, int, int, int]]:
    """Caixas (x0, y0, x1, y1) de cada grupo de pixels ligados."""
    h, w = ocupado.shape
    visto = np.zeros_like(ocupado, dtype=bool)
    caixas = []
    for y in range(h):
        for x in range(w):
            if not ocupado[y, x] or visto[y, x]:
                continue
            fila = deque([(y, x)])
            visto[y, x] = True
            x0 = x1 = x
            y0 = y1 = y
            area = 0
            while fila:
                cy, cx = fila.popleft()
                area += 1
                x0, x1, y0, y1 = min(x0, cx), max(x1, cx), min(y0, cy), max(y1, cy)
                for ny, nx in ((cy + 1, cx), (cy - 1, cx), (cy, cx + 1), (cy, cx - 1)):
                    if 0 <= ny < h and 0 <= nx < w and ocupado[ny, nx] and not visto[ny, nx]:
                        visto[ny, nx] = True
                        fila.append((ny, nx))
            if area >= area_minima:
                caixas.append((x0, y0, x1 + 1, y1 + 1))
    return caixas


def juntar_proximas(caixas, distancia):
    """Une caixas muito próximas (ex.: os dois olhos de um mesmo par)."""
    mudou = True
    while mudou:
        mudou = False
        for i in range(len(caixas)):
            for j in range(i + 1, len(caixas)):
                a, b = caixas[i], caixas[j]
                if a[0] - distancia < b[2] and b[0] - distancia < a[2] and a[1] - distancia < b[3] and b[1] - distancia < a[3]:
                    caixas[i] = (min(a[0], b[0]), min(a[1], b[1]), max(a[2], b[2]), max(a[3], b[3]))
                    del caixas[j]
                    mudou = True
                    break
            if mudou:
                break
    return caixas


def main() -> int:
    p = argparse.ArgumentParser()
    p.add_argument('folha')
    p.add_argument('saida')
    p.add_argument('--fundo', choices=['verde', 'preto'], required=True)
    p.add_argument('--juntar', type=int, default=6, help='distância (px) pra unir partes de uma mesma peça')
    a = p.parse_args()

    rgb = np.array(Image.open(a.folha).convert('RGB'))
    alfa, cor = (mascara_verde if a.fundo == 'verde' else mascara_preta)(rgb)
    ocupado = alfa > 0.5
    area_minima = max(40, int(ocupado.size * 0.0004))
    caixas = juntar_proximas(componentes(ocupado, area_minima), a.juntar)
    # Ordem de leitura: agrupa por linha (centro vertical) e depois por x.
    altura_media = np.median([c[3] - c[1] for c in caixas]) if caixas else 1
    caixas.sort(key=lambda c: (round(((c[1] + c[3]) / 2) / (altura_media * 1.2)), c[0]))

    saida = Path(a.saida)
    saida.mkdir(parents=True, exist_ok=True)
    rgba = np.dstack([cor, alfa * 255]).astype(np.uint8)
    info = []
    for n, (x0, y0, x1, y1) in enumerate(caixas, start=1):
        m = 2
        x0, y0, x1, y1 = max(0, x0 - m), max(0, y0 - m), min(rgb.shape[1], x1 + m), min(rgb.shape[0], y1 + m)
        nome = f'peca-{n:02d}.png'
        Image.fromarray(rgba[y0:y1, x0:x1]).save(saida / nome, optimize=True)
        info.append({'arquivo': nome, 'largura': x1 - x0, 'altura': y1 - y0, 'x': x0, 'y': y0})
    (saida / 'pecas.json').write_text(json.dumps(info, indent=2, ensure_ascii=False))
    print(f'{len(info)} peças salvas em {saida}')
    for i in info:
        print(f"  {i['arquivo']}: {i['largura']}x{i['altura']} em ({i['x']},{i['y']})")
    return 0


if __name__ == '__main__':
    sys.exit(main())
