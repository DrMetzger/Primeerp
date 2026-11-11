#!/usr/bin/env python3
"""
Gera as variações da marca PrimeERP usadas no site e no app:

  - primeerp-logo-escuro.png : logo com o texto escuro clareado (pro tema escuro)
  - icone-app.png            : ícone 1024x1024 (P no centro, fundo azul com circuitos)
  - icone-frente.png         : camada da frente do ícone adaptativo do Android
                               (só o P, com margem da "zona segura")
  - icone-fundo.png          : camada de fundo do ícone adaptativo (azul + circuitos)

Uso: python3 gerar-marca.py <logo.png> <p.png> <pasta-web-marca> <pasta-app-assets>
"""
import random
import sys
from pathlib import Path

import numpy as np
from PIL import Image, ImageDraw, ImageFilter

logo_arq, p_arq, pasta_web, pasta_app = map(Path, sys.argv[1:5])

# 1) Logo pro tema escuro: o que é cinza-escuro/preto (texto "rime") vira
#    branco-azulado; o turquesa e o degradê do P continuam iguais.
logo = np.array(Image.open(logo_arq).convert('RGBA')).astype(np.float32)
rgb, alfa = logo[..., :3], logo[..., 3:]
lum = rgb.mean(axis=-1, keepdims=True)
sat = rgb.max(axis=-1, keepdims=True) - rgb.min(axis=-1, keepdims=True)
peso = np.clip((110 - lum) / 70, 0, 1) * np.clip((70 - sat) / 40, 0, 1)  # 1 = escuro e sem cor
claro = np.array([234, 240, 255], np.float32)
# Mantém o relevo: quanto mais escuro era, mais claro fica (inverte a luminosidade).
relevo = 0.78 + 0.22 * (1 - lum / 110)
novo = rgb * (1 - peso) + claro * relevo * peso
Image.fromarray(np.dstack([np.clip(novo, 0, 255), alfa]).astype(np.uint8)).save(pasta_web / 'primeerp-logo-escuro.png', optimize=True)


def circuitos(tamanho: int, cor: tuple[int, int, int, int], semente: int = 7) -> Image.Image:
    """Trilhas de placa de circuito (linhas em 90/45 graus + nós)."""
    rnd = random.Random(semente)
    camada = Image.new('RGBA', (tamanho, tamanho), (0, 0, 0, 0))
    d = ImageDraw.Draw(camada)
    passo = tamanho // 16
    largura = max(2, tamanho // 300)
    for _ in range(46):
        x = rnd.randrange(0, 16) * passo
        y = rnd.randrange(0, 16) * passo
        pontos = [(x, y)]
        for _ in range(rnd.randint(2, 4)):
            dx, dy = rnd.choice([(1, 0), (0, 1), (-1, 0), (0, -1), (1, 1), (-1, 1)])
            n = rnd.randint(1, 3)
            x, y = x + dx * passo * n, y + dy * passo * n
            pontos.append((x, y))
        d.line(pontos, fill=cor, width=largura, joint='curve')
        for px, py in (pontos[0], pontos[-1]):
            r = largura * 2.2
            d.ellipse((px - r, py - r, px + r, py + r), outline=cor, width=largura)
    return camada


TAM = 1024
fundo = Image.new('RGBA', (TAM, TAM))
# Degradê azul profundo -> azul da marca -> petróleo.
y, x = np.mgrid[0:TAM, 0:TAM] / TAM
t = np.clip(0.55 * x + 0.45 * y, 0, 1)[..., None]
c1, c2, c3 = np.array([11, 27, 111]), np.array([20, 48, 170]), np.array([5, 70, 92])
cores = np.where(t < 0.5, c1 + (c2 - c1) * (t / 0.5), c2 + (c3 - c2) * ((t - 0.5) / 0.5))
fundo = Image.fromarray(np.dstack([cores, np.full((TAM, TAM), 255)]).astype(np.uint8), 'RGBA')
fundo.alpha_composite(circuitos(TAM, (94, 227, 240, 46)))
# Brilho turquesa atrás do P.
brilho = Image.new('RGBA', (TAM, TAM), (0, 0, 0, 0))
ImageDraw.Draw(brilho).ellipse((TAM * 0.22, TAM * 0.2, TAM * 0.78, TAM * 0.8), fill=(47, 211, 229, 90))
fundo.alpha_composite(brilho.filter(ImageFilter.GaussianBlur(TAM // 10)))

p = Image.open(p_arq).convert('RGBA')


def com_p(base: Image.Image, altura_p: float) -> Image.Image:
    alvo_a = int(TAM * altura_p)
    alvo_l = int(p.width * alvo_a / p.height)
    pg = p.resize((alvo_l, alvo_a), Image.LANCZOS).filter(ImageFilter.UnsharpMask(radius=2, percent=60, threshold=2))
    sombra = Image.new('RGBA', base.size, (0, 0, 0, 0))
    sombra.alpha_composite(Image.fromarray(np.dstack([np.zeros((alvo_a, alvo_l, 3)), np.array(pg)[..., 3] * 0.55]).astype(np.uint8), 'RGBA'),
                           ((TAM - alvo_l) // 2 + 10, (TAM - alvo_a) // 2 + 18))
    saida = base.copy()
    saida.alpha_composite(sombra.filter(ImageFilter.GaussianBlur(14)))
    saida.alpha_composite(pg, ((TAM - alvo_l) // 2, (TAM - alvo_a) // 2))
    return saida


# Ícone comum (lojas/web): P ocupando ~58% da altura.
com_p(fundo, 0.58).save(pasta_app / 'icone-app.png')
# Ícone adaptativo: o Android corta em círculo/gota -- P dentro da zona segura (~46%).
fundo.save(pasta_app / 'icone-fundo.png')
com_p(Image.new('RGBA', (TAM, TAM), (0, 0, 0, 0)), 0.46).save(pasta_app / 'icone-frente.png')
# Favicon do site.
com_p(fundo, 0.62).resize((256, 256), Image.LANCZOS).save(pasta_web / 'icone-256.png')
print('ok')
