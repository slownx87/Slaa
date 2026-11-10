"""Linha de comando: rodar | foto | diagnostico | listar."""

from __future__ import annotations

import argparse
import logging
import os
import signal
import subprocess
import sys
import threading
import time

import cv2
import numpy as np

from . import config
from .armazenamento import Armazenamento
from .captura import DISPOSITIVO_HW, Captura, comando_ffmpeg, resolver_zona, sondar
from .fila import FilaEventos
from .processador import Processador

log = logging.getLogger("leitor")


def rodar(cfg, criar_reconhecedor=None, intervalo_relatorio: float = 60.0) -> int:
    if criar_reconhecedor is None:
        from .reconhecimento import Reconhecedor

        def criar_reconhecedor():
            return Reconhecedor(cfg.reconhecimento)

    parar = threading.Event()
    fila = FilaEventos(cfg.fila.max_mb << 20, cfg.fila.min_quadros_evento)
    armazenamento = Armazenamento(cfg.saida.pasta, cfg.saida.retencao_dias, cfg.saida.janela_duplicata_s)
    processador = Processador(cfg, fila, criar_reconhecedor, armazenamento, parar)
    processador.start()
    processador.pronto.wait()  # a captura só começa com os modelos carregados
    if processador.erro:
        log.error("não consegui carregar os modelos: %r", processador.erro)
        return 1
    captura = Captura(cfg, fila, parar)
    captura.start()
    try:
        for sinal in (signal.SIGINT, signal.SIGTERM):
            signal.signal(sinal, lambda *_: parar.set())
    except ValueError:
        pass  # fora da thread principal (testes)

    def relatorio() -> None:
        ms = 1000 * processador.tempo_quadros / max(1, processador.quadros)
        log.info("quadros=%d eventos=%d processados=%d fila=%d raleados=%d perdidos=%d falhas=%d "
                 "reconexoes=%d ms/quadro=%.0f", captura.quadros, captura.eventos, processador.eventos,
                 fila.tamanho, fila.raleados, fila.perdidos, processador.falhas, captura.reconexoes, ms)

    codigo = 0
    proximo_relatorio = time.monotonic() + intervalo_relatorio
    proxima_limpeza = 0.0
    while not parar.wait(0.2):
        agora = time.monotonic()
        if captura.fim_da_entrada.is_set() and processador.eventos + fila.perdidos >= captura.eventos:
            break  # arquivo gravado terminou e tudo foi processado
        if not processador.is_alive() or not (captura.is_alive() or captura.fim_da_entrada.is_set()):
            log.error("uma das threads morreu; saindo para o systemd reiniciar")
            codigo = 1
            break
        if agora >= proximo_relatorio:
            relatorio()
            proximo_relatorio = agora + intervalo_relatorio
        if agora >= proxima_limpeza:
            apagados = armazenamento.limpar_antigos()
            if apagados:
                log.info("retenção: %d registros antigos apagados", apagados)
            proxima_limpeza = agora + 3600
    parar.set()
    captura.join(5)
    processador.join(60)
    relatorio()
    log.info("registros no banco: %s", armazenamento.contagem())
    return codigo


def foto(cfg, saida: str) -> int:
    """Salva um quadro inteiro com a zona e uma grade de coordenadas desenhadas."""
    url = cfg.camera.url
    info = sondar(url)
    cmd = ["ffmpeg", "-hide_banner", "-loglevel", "error", "-nostdin", "-y"]
    if url.startswith("rtsp"):
        cmd += ["-rtsp_transport", "tcp"]
    if cfg.camera.decodificador == "hardware":
        cmd += ["-c:v", "h264_v4l2m2m"]  # para conferir a imagem que esse decodificador produz
    cmd += ["-i", url, "-frames:v", "1", "-q:v", "2", saida]
    subprocess.run(cmd, check=True, timeout=60)
    imagem = cv2.imread(saida)
    x, y, w, h = resolver_zona(cfg.zona, info["largura"], info["altura"])
    for gx in range(0, info["largura"], 200):
        cv2.line(imagem, (gx, 0), (gx, info["altura"]), (255, 255, 255), 1)
        cv2.putText(imagem, str(gx), (gx + 4, 18), cv2.FONT_HERSHEY_SIMPLEX, 0.5, (0, 255, 255), 1)
    for gy in range(200, info["altura"], 200):
        cv2.line(imagem, (0, gy), (info["largura"], gy), (255, 255, 255), 1)
        cv2.putText(imagem, str(gy), (4, gy - 4), cv2.FONT_HERSHEY_SIMPLEX, 0.5, (0, 255, 255), 1)
    cv2.rectangle(imagem, (x, y), (x + w, y + h), (0, 0, 255), 3)
    cv2.imwrite(saida, imagem)
    print(f"{saida}: quadro {info['largura']}x{info['altura']}, zona {w}x{h} em ({x},{y}) em vermelho")
    return 0


def _medir_decodificacao(url: str, zona, hw: bool, segundos: float = 8.0) -> tuple[float, float]:
    """(quadros por segundo entregues, núcleos de CPU usados pelo ffmpeg)."""
    tamanho = zona[2] * zona[3] * 3 // 2
    antes = os.times()
    processo = subprocess.Popen(comando_ffmpeg(url, zona, hw), stdout=subprocess.PIPE,
                                stderr=subprocess.DEVNULL)
    recebidos, primeiro = 0, None
    fim = time.monotonic() + segundos + 10  # folga para conectar
    try:
        while time.monotonic() < fim:
            bloco = processo.stdout.read(tamanho)
            if len(bloco) < tamanho:
                break
            primeiro = primeiro or time.monotonic()
            recebidos += 1
            if time.monotonic() - primeiro >= segundos:
                break
    finally:
        processo.kill()
        processo.wait()
    depois = os.times()
    duracao = time.monotonic() - primeiro if primeiro else 0.0
    if recebidos < 2 or duracao <= 0:
        return 0.0, 0.0
    cpu = (depois.children_user + depois.children_system) - (antes.children_user + antes.children_system)
    return (recebidos - 1) / duracao, cpu / duracao


def diagnostico(cfg) -> int:
    """Mede, neste hardware, o que o projeto promete: decodificação e reconhecimento."""
    url = cfg.camera.url
    info = sondar(url)
    zona = resolver_zona(cfg.zona, info["largura"], info["altura"])
    print(f"Fluxo: {info['codec']} {info['largura']}x{info['altura']} a {info['fps']:.0f} fps")
    print(f"Zona:  {zona[2]}x{zona[3]} em ({zona[0]},{zona[1]})")
    if info["codec"] != "h264":
        print("  AVISO: o Pi 3B só decodifica H.264 por hardware. Mude o codec da câmera para H.264.")
    if info["largura"] * info["altura"] > 1920 * 1080:
        print("  AVISO: acima de 1080p o decodificador do Pi 3B não acompanha. Reduza a resolução.")
    if info["fps"] > 16:
        print("  AVISO: mais de 15 fps só gasta CPU. Reduza a taxa de quadros na câmera.")

    modos = [("software", False)]
    if info["codec"] == "h264" and os.path.exists(DISPOSITIVO_HW):
        modos.insert(0, ("hardware", True))
    else:
        print(f"Decodificador por hardware indisponível ({DISPOSITIVO_HW} ausente ou codec diferente de H.264).")
    for nome, hw in modos:
        fps, cpu = _medir_decodificacao(url, zona, hw)
        print(f"Decodificação por {nome}: {fps:.1f} fps entregues, ffmpeg usando {cpu * 100:.0f}% de um núcleo")

    from .reconhecimento import Reconhecedor

    reconhecedor = Reconhecedor(cfg.reconhecimento)
    ruido = np.random.default_rng(0).integers(0, 255, (zona[3], zona[2], 3), dtype=np.uint8)

    def medir(funcao, vezes: int) -> float:
        funcao()  # aquecimento
        inicio = time.perf_counter()
        for _ in range(vezes):
            funcao()
        return 1000 * (time.perf_counter() - inicio) / vezes

    deteccao = medir(lambda: reconhecedor.detectar(ruido), 5)
    leitura = medir(lambda: reconhecedor.ler([ruido[:48, :144]]), 10)
    por_passagem = 2.0 / cfg.reconhecimento.intervalo_cobertura_s * (deteccao + leitura) / 1000
    print(f"Detecção: {deteccao:.0f} ms por quadro. OCR: {leitura:.0f} ms por placa.")
    print(f"Uma passagem de 2 s custa cerca de {por_passagem:.1f} s -> até ~{60 / por_passagem:.0f} "
          f"veículos por minuto sem a fila crescer.")
    try:
        for argumentos in (["measure_temp"], ["get_throttled"]):
            print("vcgencmd:", subprocess.run(["vcgencmd", *argumentos], capture_output=True,
                                              text=True, timeout=5).stdout.strip())
        print("  (throttled diferente de 0x0 = fonte fraca ou superaquecimento: corrija antes de confiar no sistema)")
    except (OSError, subprocess.SubprocessError):
        pass
    return 0


def listar(cfg, n: int) -> int:
    armazenamento = Armazenamento(cfg.saida.pasta, cfg.saida.retencao_dias, cfg.saida.janela_duplicata_s)
    print(f"{'quando':19}  {'placa':8} {'status':9} {'conf':>5} {'leit':>4} {'vezes':>5}  imagem")
    for quando, placa, status, confianca, leituras, vezes, imagem in armazenamento.ultimas(n):
        print(f"{quando:19}  {placa or '-':8} {status:9} {confianca:5.2f} {leituras:4d} {vezes:5d}  {imagem or ''}")
    print("totais:", armazenamento.contagem())
    return 0


def main(argumentos: list[str] | None = None) -> int:
    analisador = argparse.ArgumentParser(prog="leitor_placas", description=__doc__)
    analisador.add_argument("comando", choices=["rodar", "foto", "diagnostico", "listar"])
    analisador.add_argument("-c", "--config", default="config.yaml")
    analisador.add_argument("-o", "--saida", default="zona.jpg", help="arquivo do comando foto")
    analisador.add_argument("-n", type=int, default=20, help="quantos registros o comando listar mostra")
    opcoes = analisador.parse_args(argumentos)
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s: %(message)s")
    cfg = config.carregar(opcoes.config)
    if opcoes.comando == "rodar":
        return rodar(cfg)
    if opcoes.comando == "foto":
        return foto(cfg, opcoes.saida)
    if opcoes.comando == "diagnostico":
        return diagnostico(cfg)
    return listar(cfg, opcoes.n)


if __name__ == "__main__":
    sys.exit(main())
