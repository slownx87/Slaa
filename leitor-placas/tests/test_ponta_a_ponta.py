"""Ponta a ponta com o ffmpeg de verdade e um vídeo sintético no lugar da câmera.

O reconhecedor é falso (acha um retângulo branco e "lê" a placa pela largura
dele): o que se testa aqui é o caminho captura -> movimento -> eventos -> fila
-> cobertura/refino -> votação -> banco, que é o que garante que nada se perde.
"""

import shutil
import sqlite3
import subprocess
import time

import cv2
import numpy as np
import pytest

from leitor_placas import config
from leitor_placas.cli import rodar

pytestmark = pytest.mark.skipif(shutil.which("ffmpeg") is None, reason="ffmpeg não instalado")

LARGURA, ALTURA, FPS = 640, 360, 15
# (segundo em que entra, largura do retângulo-placa, texto)
PASSAGENS = [(2.0, 80, "ABC1D23"), (6.0, 100, "DEF4567"), (10.0, 120, "GHI8J90")]
DURACAO_PASSAGEM = 1.5


@pytest.fixture(scope="module")
def video(tmp_path_factory):
    caminho = str(tmp_path_factory.mktemp("video") / "rua.mp4")
    codificador = subprocess.Popen(
        ["ffmpeg", "-loglevel", "error", "-y", "-f", "rawvideo", "-pix_fmt", "bgr24",
         "-s", f"{LARGURA}x{ALTURA}", "-r", str(FPS), "-i", "pipe:0",
         "-c:v", "libx264", "-pix_fmt", "yuv420p", "-g", str(FPS), caminho],
        stdin=subprocess.PIPE)
    fundo = np.tile(np.linspace(60, 140, LARGURA, dtype=np.uint8), (ALTURA, 1))
    fundo = cv2.merge([fundo, fundo, fundo])
    for n in range(int(13.5 * FPS)):
        t = n / FPS
        quadro = fundo.copy()
        for inicio, largura_placa, _ in PASSAGENS:
            avanco = (t - inicio) / DURACAO_PASSAGEM
            if 0 <= avanco <= 1:
                x = int(-220 + avanco * (LARGURA + 220))  # "carro" atravessa da esquerda para a direita
                cv2.rectangle(quadro, (x, 120), (x + 220, 260), (30, 30, 30), -1)
                cv2.rectangle(quadro, (x + 50, 200), (x + 50 + largura_placa, 230), (255, 255, 255), -1)
        codificador.stdin.write(quadro.tobytes())
    codificador.stdin.close()
    assert codificador.wait() == 0
    return caminho


class ReconhecedorFalso:
    def __init__(self, atraso=0.0):
        self.atraso = atraso
        self.chamadas = 0

    def detectar(self, bgr):
        self.chamadas += 1
        time.sleep(self.atraso)
        claro = (cv2.cvtColor(bgr, cv2.COLOR_BGR2GRAY) > 215).astype(np.uint8)
        contornos, _ = cv2.findContours(claro, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
        caixas = [cv2.boundingRect(c) for c in contornos]
        return [(x, y, x + w, y + h, 0.9) for x, y, w, h in caixas if w >= 20 and h >= 15]

    def ler(self, recortes):
        saida = []
        for recorte in recortes:
            _, _, texto = min(PASSAGENS, key=lambda p: abs(p[1] - recorte.shape[1]))
            saida.append((texto, [0.95] * 7))
        return saida


def executar(video, pasta, falso, **fila):
    cfg = config.de_dict({"camera": {"url": video}, "saida": {"pasta": str(pasta)}, "fila": fila})
    assert rodar(cfg, criar_reconhecedor=lambda: falso, intervalo_relatorio=5) == 0
    banco = sqlite3.connect(str(pasta / "placas.db"))
    return banco.execute("SELECT placa, status, leituras, imagem, recorte FROM passagens ORDER BY ts").fetchall()


def test_tres_passagens_tres_placas(video, tmp_path):
    falso = ReconhecedorFalso()
    linhas = executar(video, tmp_path, falso)
    assert [(l[0], l[1]) for l in linhas] == [(texto, "lida") for _, _, texto in PASSAGENS]
    for _, _, leituras, imagem, recorte in linhas:
        assert leituras >= 2
        assert (tmp_path / imagem).stat().st_size > 0 and (tmp_path / recorte).stat().st_size > 0
    # Consenso atingido na cobertura: não precisou examinar todos os quadros.
    assert falso.chamadas < 3 * 24


def test_reconhecimento_lento_e_pouca_memoria_nao_perdem_placa(video, tmp_path):
    # 0,3 s por quadro (ordem de grandeza de um Pi 3B) e fila de 3 MB: cada
    # evento (~8 MB) precisa ser raleado para caber.
    linhas = executar(video, tmp_path, ReconhecedorFalso(atraso=0.3), max_mb=3)
    assert [(l[0], l[1]) for l in linhas] == [(texto, "lida") for _, _, texto in PASSAGENS]
