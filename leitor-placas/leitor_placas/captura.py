"""Captura: ffmpeg -> quadros da zona (I420) -> movimento -> eventos na fila.

Este é o caminho de tempo real. Ele nunca espera pelo reconhecimento: só lê o
fluxo, decide se há movimento e empacota os quadros de cada passagem.
"""

from __future__ import annotations

import fcntl
import itertools
import json
import logging
import os
import select
import subprocess
import threading
import time
from collections import deque
from dataclasses import dataclass, field

import cv2
import numpy as np

log = logging.getLogger("captura")

DISPOSITIVO_HW = "/dev/video10"  # decodificador H.264 do Raspberry Pi (bcm2835-codec)
F_SETPIPE_SZ = 1031


def e_rede(url: str) -> bool:
    return "://" in url


def sondar(url: str) -> dict:
    """Codec, resolução e fps do fluxo, via ffprobe."""
    cmd = ["ffprobe", "-v", "error"]
    if url.startswith("rtsp"):
        cmd += ["-rtsp_transport", "tcp"]
    cmd += ["-select_streams", "v:0", "-show_entries",
            "stream=codec_name,width,height,avg_frame_rate", "-of", "json", url]
    saida = subprocess.run(cmd, capture_output=True, text=True, timeout=30)
    fluxos = json.loads(saida.stdout or "{}").get("streams") if saida.returncode == 0 else None
    if not fluxos:
        raise RuntimeError(f"não consegui abrir o fluxo: {saida.stderr.strip() or 'sem vídeo'}")
    num, _, den = fluxos[0].get("avg_frame_rate", "0/1").partition("/")
    fps = float(num) / float(den) if float(den or 0) else 0.0
    return {"codec": fluxos[0]["codec_name"], "largura": int(fluxos[0]["width"]),
            "altura": int(fluxos[0]["height"]), "fps": fps}


def resolver_zona(zona, largura: int, altura: int) -> tuple[int, int, int, int]:
    """Ajusta a zona ao quadro; tudo par, como o formato I420 exige."""
    x = max(0, min(zona.x, largura - 2)) & ~1
    y = max(0, min(zona.y, altura - 2)) & ~1
    w = min(zona.largura or largura, largura - x) & ~1
    h = min(zona.altura or altura, altura - y) & ~1
    return x, y, w, h


def comando_ffmpeg(url: str, zona: tuple[int, int, int, int], hw: bool) -> list[str]:
    x, y, w, h = zona
    cmd = ["ffmpeg", "-hide_banner", "-loglevel", "error", "-nostdin"]
    if url.startswith("rtsp"):
        # TCP: sem perda de pacotes (UDP perdido = quadro corrompido = placa ilegível).
        cmd += ["-rtsp_transport", "tcp", "-fflags", "nobuffer", "-flags", "low_delay"]
    elif not e_rede(url):
        cmd += ["-re"]  # arquivo gravado: reproduz no ritmo de uma câmera
    if hw:
        cmd += ["-c:v", "h264_v4l2m2m"]
    # O recorte vem antes de qualquer conversão: o custo acompanha a zona, não o
    # quadro. Sem filtro de fps: sai exatamente o que a câmera manda.
    cmd += ["-i", url, "-an", "-sn", "-vf", f"crop={w}:{h}:{x}:{y},format=yuv420p",
            "-fps_mode", "passthrough", "-f", "rawvideo", "pipe:1"]
    return cmd


class RelogioQuadros:
    """Hora de cada quadro, em tempo monotônico (imune a acertos do relógio pelo NTP).

    Depois de um engasgo da rede os quadros chegam em rajada. Para os tempos do
    evento (cauda, cobertura) continuarem valendo, a hora avança pelo menos
    ~1 intervalo de quadro por quadro; o intervalo é medido no próprio fluxo.
    """

    JANELA_S = 10.0

    def __init__(self, fps_nominal: float, folga_max: float, agora=time.monotonic):
        self._agora = agora
        self.passo = 0.9 / fps_nominal if 1 <= fps_nominal <= 60 else 0.0
        self.folga_max = folga_max  # o quanto a hora do quadro pode se adiantar à real
        self.t = 0.0
        self._inicio_janela: float | None = None
        self._na_janela = 0

    def marcar(self) -> float:
        agora = self._agora()
        if self._inicio_janela is None:
            self._inicio_janela = agora
        self._na_janela += 1
        if agora - self._inicio_janela >= self.JANELA_S:
            self.passo = 0.9 * (agora - self._inicio_janela) / self._na_janela
            self._inicio_janela, self._na_janela = agora, 0
        self.t = min(max(agora, self.t + self.passo), agora + self.folga_max)
        return self.t


@dataclass
class Quadro:
    t: float  # segundos em tempo monotônico
    dados: bytearray  # zona em I420 (o plano Y, no início, já é a imagem em cinza)


@dataclass
class Evento:
    """Os quadros de uma passagem (um trecho contínuo de movimento)."""

    id: int
    inicio: float
    largura: int
    altura: int
    fim: float = 0.0
    quadros: list[Quadro] = field(default_factory=list)

    @property
    def bytes(self) -> int:
        return sum(len(q.dados) for q in self.quadros)

    def ralear(self, minimo: int) -> bool:
        """Descarta quadros de forma uniforme no tempo, sem ir abaixo de `minimo`."""
        n = len(self.quadros)
        if n <= minimo:
            return False
        if (n + 1) // 2 >= minimo:
            self.quadros = self.quadros[::2]
        else:
            indices = sorted({round(i * (n - 1) / (minimo - 1)) for i in range(minimo)})
            self.quadros = [self.quadros[i] for i in indices]
        return True

    def bgr(self, i: int) -> np.ndarray:
        yuv = np.frombuffer(self.quadros[i].dados, np.uint8).reshape(self.altura * 3 // 2, self.largura)
        return cv2.cvtColor(yuv, cv2.COLOR_YUV2BGR_I420)


class DetectorMovimento:
    """Diferença contra um fundo de média móvel, numa miniatura do plano Y."""

    def __init__(self, largura: int, altura: int, limiar: int, area_min: float, alfa: float = 0.03):
        self.largura, self.altura = largura, altura
        self.limiar, self.area_min, self.alfa = limiar, area_min, alfa
        fator = max(1, round(largura / 160))
        self.salto = max(1, fator // 2)
        self.tamanho = (max(8, largura // fator), max(8, altura // fator))
        self.fundo: np.ndarray | None = None

    def __call__(self, dados) -> bool:
        y = np.frombuffer(dados, np.uint8, self.largura * self.altura).reshape(self.altura, self.largura)
        mini = cv2.resize(np.ascontiguousarray(y[::self.salto, ::self.salto]), self.tamanho,
                          interpolation=cv2.INTER_AREA)
        if self.fundo is None:
            self.fundo = mini.astype(np.float32)
            return False
        diferenca = cv2.absdiff(mini, cv2.convertScaleAbs(self.fundo))
        mudou = np.count_nonzero(diferenca > self.limiar) / diferenca.size
        cv2.accumulateWeighted(mini, self.fundo, self.alfa)
        return mudou >= self.area_min


class Segmentador:
    """Agrupa quadros em eventos: pré-gatilho + movimento + pequena cauda."""

    CAUDA_S = 0.2  # quadros parados depois do último movimento não trazem nada novo

    def __init__(self, cfg, largura: int, altura: int):
        self.cfg, self.largura, self.altura = cfg, largura, altura
        self.pre: deque[Quadro] = deque()
        self.atual: Evento | None = None
        self._ids = itertools.count(1)
        self._salto = 1
        self._n = 0
        self._inicio_mov = 0.0
        self._ultimo_mov = 0.0

    def alimentar(self, quadro: Quadro, movimento: bool) -> Evento | None:
        t = quadro.t
        if self.atual is None:
            self.pre.append(quadro)
            while t - self.pre[0].t > self.cfg.pre_s:
                self.pre.popleft()
            if not movimento:
                return None
            self.atual = Evento(next(self._ids), self.pre[0].t, self.largura, self.altura)
            self._salto, self._n = 1, 0
            self._inicio_mov = self._ultimo_mov = t
            for anterior in self.pre:
                self._guardar(anterior)
            self.pre.clear()
            return None
        self._guardar(quadro)
        if movimento:
            self._ultimo_mov = t
        if t - self._ultimo_mov >= self.cfg.pos_s or t - self._inicio_mov >= self.cfg.max_evento_s:
            return self.fechar()
        return None

    def _guardar(self, quadro: Quadro) -> None:
        # Evento longo = veículo lento: basta uma amostragem mais espaçada. Ao
        # estourar o limite, fica um quadro sim, um não, e o passo dobra.
        if self._n % self._salto == 0:
            self.atual.quadros.append(quadro)
            if len(self.atual.quadros) > self.cfg.max_quadros_evento:
                self.atual.quadros = self.atual.quadros[::2]
                self._salto *= 2
        self._n += 1

    def fechar(self) -> Evento | None:
        evento, self.atual = self.atual, None
        if evento is None:
            return None
        corte = self._ultimo_mov + self.CAUDA_S
        evento.quadros = [q for q in evento.quadros if q.t <= corte] or evento.quadros[:1]
        evento.fim = evento.quadros[-1].t
        return evento


class Captura(threading.Thread):
    def __init__(self, cfg, fila, parar: threading.Event):
        super().__init__(name="captura", daemon=True)
        self.cfg, self.fila, self.parar = cfg, fila, parar
        self.quadros = 0
        self.eventos = 0
        self.reconexoes = 0
        self.fim_da_entrada = threading.Event()  # só para arquivo gravado
        self._hw_falhou = False

    def usar_hw(self, codec: str) -> bool:
        modo = self.cfg.camera.decodificador
        if modo == "software":
            return False
        if modo == "hardware":
            return True
        return codec == "h264" and os.path.exists(DISPOSITIVO_HW) and not self._hw_falhou

    def run(self) -> None:
        url = self.cfg.camera.url
        espera = 1.0
        while not self.parar.is_set():
            try:
                lidos = self._sessao(url)
            except Exception as erro:  # câmera fora do ar, ffmpeg ausente etc.
                log.error("falha na captura: %s", erro)
                lidos = 0
            if not e_rede(url):
                self.fim_da_entrada.set()
                return
            if self.parar.is_set():
                return
            self.reconexoes += 1
            espera = 1.0 if lidos else min(espera * 2, 30.0)
            log.warning("fluxo interrompido após %d quadros; reconectando em %.0f s", lidos, espera)
            self.parar.wait(espera)

    def _sessao(self, url: str) -> int:
        info = sondar(url)
        zona = resolver_zona(self.cfg.zona, info["largura"], info["altura"])
        _, _, w, h = zona
        hw = self.usar_hw(info["codec"])
        log.info("fluxo %s %dx%d %.0f fps; zona %dx%d em (%d,%d); decodificação por %s",
                 info["codec"], info["largura"], info["altura"], info["fps"], w, h, zona[0], zona[1],
                 "hardware" if hw else "software")
        mov = self.cfg.movimento
        detector = DetectorMovimento(w, h, mov.limiar, mov.area_min)
        segmentador = Segmentador(mov, w, h)
        processo = subprocess.Popen(comando_ffmpeg(url, zona, hw), stdout=subprocess.PIPE, bufsize=0)
        fd = processo.stdout.fileno()
        try:
            fcntl.fcntl(fd, F_SETPIPE_SZ, 1 << 20)
        except OSError:
            pass
        relogio = RelogioQuadros(info["fps"], self.cfg.camera.tempo_limite_s + 5)
        lidos = 0
        try:
            while not self.parar.is_set():
                dados = self._ler(processo, fd, w * h * 3 // 2)
                if dados is None:
                    break
                lidos += 1
                self.quadros += 1
                evento = segmentador.alimentar(Quadro(relogio.marcar(), dados), detector(dados))
                if evento:
                    self._entregar(evento)
        finally:
            evento = segmentador.fechar()
            if evento:
                self._entregar(evento)
            processo.kill()
            processo.wait()
        if lidos == 0 and hw and self.cfg.camera.decodificador == "auto":
            self._hw_falhou = True
            log.warning("o decodificador por hardware não entregou quadros; passando para software")
        return lidos

    def _ler(self, processo, fd: int, tamanho: int) -> bytearray | None:
        """Lê um quadro inteiro; None em fim de fluxo, parada ou fluxo travado."""
        dados = bytearray(tamanho)
        visao = memoryview(dados)
        lidos = 0
        limite = time.monotonic() + self.cfg.camera.tempo_limite_s
        while lidos < tamanho:
            if self.parar.is_set():
                return None
            if time.monotonic() > limite:
                log.warning("sem dados da câmera por %.0f s", self.cfg.camera.tempo_limite_s)
                return None
            if not select.select([fd], [], [], 0.5)[0]:
                continue
            n = processo.stdout.readinto(visao[lidos:])
            if not n:
                return None
            lidos += n
        return dados

    def _entregar(self, evento: Evento) -> None:
        self.eventos += 1
        self.fila.por(evento)
