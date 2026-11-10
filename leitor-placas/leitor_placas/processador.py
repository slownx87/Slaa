"""Reconhecimento: consome eventos da fila no ritmo que o Pi aguentar.

Para cada evento:
  1. cobertura - examina um quadro a cada `intervalo_cobertura_s`, do começo ao
     fim. Toda placa que fica esse tempo na zona é vista ao menos uma vez.
  2. refino - enquanto houver placa detectada sem leitura de consenso, examina
     os quadros vizinhos de onde ela apareceu.
Com a fila vazia, o tempo ocioso é gasto examinando o resto do evento.
"""

from __future__ import annotations

import logging
import os
import threading
import time

import cv2

from .placa import Agregador, Leitura

log = logging.getLogger("processador")


class Processador(threading.Thread):
    def __init__(self, cfg, fila, criar_reconhecedor, armazenamento, parar: threading.Event):
        super().__init__(name="processador", daemon=True)
        self.cfg, self.fila, self.armazenamento, self.parar = cfg, fila, armazenamento, parar
        self._criar = criar_reconhecedor
        self.rec = None
        self.pronto = threading.Event()
        self.erro: BaseException | None = None
        self.eventos = 0  # eventos concluídos (com ou sem falha)
        self.falhas = 0
        self.quadros = 0
        self.tempo_quadros = 0.0

    def run(self) -> None:
        try:
            # No Linux a prioridade é por thread, e as threads do ONNX Runtime
            # criadas daqui herdam este valor: a captura sempre passa na frente.
            os.nice(5)
            self.rec = self._criar()
        except BaseException as erro:
            self.erro = erro
            return
        finally:
            self.pronto.set()
        while not self.parar.is_set():
            evento = self.fila.tirar(0.5)
            if evento is None:
                continue
            try:
                self.processar(evento)
            except Exception:
                self.falhas += 1
                log.exception("PERDA: falha ao processar o evento %d", evento.id)
            finally:
                self.eventos += 1

    def processar(self, evento) -> list:
        rcfg = self.cfg.reconhecimento
        agregador = Agregador(rcfg.leituras_consenso, rcfg.conf_consenso)
        total = len(evento.quadros)
        cobertura, ultimo = [], None
        for i, quadro in enumerate(evento.quadros):
            if ultimo is None or quadro.t - ultimo >= rcfg.intervalo_cobertura_s:
                cobertura.append(i)
                ultimo = quadro.t
        restantes = [i for i in range(total) if i not in cobertura]
        # Com fila acumulada, cada evento ganha menos quadros de refino.
        orcamento = max(len(cobertura), total // (1 + self.fila.tamanho))
        for i in cobertura:
            self._examinar(evento, i, agregador)
        feitos = len(cobertura)
        while restantes and feitos < orcamento:
            pendentes = agregador.quadros_pendentes()
            if pendentes:
                i = min(restantes, key=lambda j: min(abs(j - p) for p in pendentes))
            elif agregador.vazio and self.fila.tamanho == 0:
                i = restantes[0]  # nada visto e nada esperando: varre o que sobrou
            else:
                break
            restantes.remove(i)
            self._examinar(evento, i, agregador)
            feitos += 1
        resultados = agregador.resultados()
        self._registrar(evento, resultados)
        log.info("evento %d: %d/%d quadros examinados -> %s", evento.id, feitos, total,
                 ", ".join(f"{r.placa or '?'} ({r.status}, {r.confianca:.2f})" for r in resultados)
                 or "sem placa")
        return resultados

    def _examinar(self, evento, i: int, agregador: Agregador) -> None:
        inicio = time.perf_counter()
        bgr = evento.bgr(i)
        caixas = self.rec.detectar(bgr)
        altura, largura = bgr.shape[:2]
        minimo = self.cfg.reconhecimento.largura_min_placa
        # Só vai para o OCR a placa grande o bastante e inteira (longe da borda da
        # zona). As outras contam como "vista, não lida" e puxam o refino.
        legiveis = [c for c in caixas if c[2] - c[0] >= minimo
                    and c[0] > 1 and c[1] > 1 and c[2] < largura - 1 and c[3] < altura - 1]
        textos = self.rec.ler([bgr[y1:y2, x1:x2] for x1, y1, x2, y2, _ in legiveis])
        t = evento.quadros[i].t
        for (x1, y1, x2, y2, conf), (texto, probs) in zip(legiveis, textos):
            agregador.adicionar(Leitura(i, t, (x1, y1, x2, y2), conf, texto, probs))
        for x1, y1, x2, y2, conf in caixas:
            if (x1, y1, x2, y2, conf) not in legiveis:
                agregador.adicionar(Leitura(i, t, (x1, y1, x2, y2), conf))
        self.quadros += 1
        self.tempo_quadros += time.perf_counter() - inicio

    def _registrar(self, evento, resultados) -> None:
        # Os quadros carregam tempo monotônico; a hora de parede é calculada só
        # aqui, com o relógio de agora (o Pi não tem RTC: no boot a hora pode estar errada).
        relogio = time.time() - time.monotonic()
        if not resultados:
            imagem = None
            if self.cfg.saida.salvar_sem_placa:
                # Miniatura do meio do evento: permite auditar o que passou sem leitura.
                bgr = evento.bgr(len(evento.quadros) // 2)
                escala = min(1.0, 640 / bgr.shape[1])
                imagem = _jpeg(cv2.resize(bgr, None, fx=escala, fy=escala, interpolation=cv2.INTER_AREA), 70)
            self.armazenamento.registrar(ts=relogio + evento.inicio, placa="", status="sem_placa", confianca=0.0,
                                         leituras=0, evento=evento.id, imagem=imagem)
            return
        for r in resultados:
            bgr = evento.bgr(r.melhor.quadro)
            x1, y1, x2, y2 = r.melhor.caixa
            recorte = _jpeg(bgr[y1:y2, x1:x2], 95)
            cv2.rectangle(bgr, (x1, y1), (x2, y2), (0, 255, 0), 2)
            self.armazenamento.registrar(ts=relogio + r.melhor.t, placa=r.placa, status=r.status,
                                         confianca=r.confianca, leituras=r.leituras, evento=evento.id,
                                         imagem=_jpeg(bgr, 85), recorte=recorte)


def _jpeg(bgr, qualidade: int) -> bytes:
    return cv2.imencode(".jpg", bgr, [cv2.IMWRITE_JPEG_QUALITY, qualidade])[1].tobytes()
