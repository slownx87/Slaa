"""Fila de eventos entre a captura (tempo real) e o reconhecimento (lento).

Quando a memória aperta, a fila rareia quadros dos eventos em espera em vez de
descartar eventos: cada passagem continua representada. Só se nem isso bastar
um evento é perdido, e a perda é contada e registrada em log, nunca silenciosa.
"""

from __future__ import annotations

import logging
import threading
from collections import deque

log = logging.getLogger("fila")


class FilaEventos:
    def __init__(self, max_bytes: int, min_quadros: int):
        self.max_bytes = max_bytes
        self.min_quadros = max(1, min_quadros)
        self.raleados = 0
        self.perdidos = 0
        self._itens: deque = deque()
        self._cond = threading.Condition()

    @property
    def tamanho(self) -> int:
        return len(self._itens)

    def por(self, evento) -> None:
        with self._cond:
            self._itens.append(evento)
            while sum(e.bytes for e in self._itens) > self.max_bytes:
                maior = max(self._itens, key=lambda e: len(e.quadros))
                if maior.ralear(self.min_quadros):
                    self.raleados += 1
                elif len(self._itens) > 1:
                    perdido = self._itens.popleft()
                    self.perdidos += 1
                    log.error("PERDA: fila cheia, evento %d descartado sem leitura", perdido.id)
                else:
                    break
            self._cond.notify()

    def tirar(self, espera: float):
        with self._cond:
            if not self._itens:
                self._cond.wait(espera)
            return self._itens.popleft() if self._itens else None
