"""Registro das passagens: SQLite + imagens de evidência, com retenção limitada."""

from __future__ import annotations

import os
import sqlite3
import threading
import time

ESQUEMA = """
CREATE TABLE IF NOT EXISTS passagens (
    id INTEGER PRIMARY KEY,
    ts REAL NOT NULL,            -- quando a placa foi vista (epoch)
    quando TEXT NOT NULL,        -- o mesmo, legível (hora local)
    placa TEXT NOT NULL,         -- vazio quando não houve leitura
    status TEXT NOT NULL,        -- lida | incerta | sem_placa
    confianca REAL NOT NULL,
    leituras INTEGER NOT NULL,   -- quantos quadros sustentam o resultado
    evento INTEGER NOT NULL,
    vezes INTEGER NOT NULL DEFAULT 1,  -- reaparições dentro da janela de duplicata
    imagem TEXT,
    recorte TEXT
);
CREATE INDEX IF NOT EXISTS passagens_ts ON passagens (ts);
CREATE INDEX IF NOT EXISTS passagens_placa ON passagens (placa);
"""


class Armazenamento:
    def __init__(self, pasta: str, retencao_dias: int = 30, janela_duplicata_s: float = 20.0):
        self.pasta = pasta
        self.retencao_s = retencao_dias * 86400
        self.janela = janela_duplicata_s
        os.makedirs(os.path.join(pasta, "imagens"), exist_ok=True)
        self._db = sqlite3.connect(os.path.join(pasta, "placas.db"), check_same_thread=False)
        self._db.executescript(ESQUEMA)
        self._trava = threading.Lock()
        self._recentes: dict[str, tuple[float, int]] = {}  # placa -> (ts, id)

    def registrar(self, *, ts: float, placa: str, status: str, confianca: float, leituras: int,
                  evento: int, imagem: bytes | None = None, recorte: bytes | None = None) -> int | None:
        """Grava uma passagem. Devolve None se for repetição recente da mesma placa."""
        with self._trava:
            anterior = self._recentes.get(placa) if placa else None
            if anterior and ts - anterior[0] <= self.janela:
                self._recentes[placa] = (ts, anterior[1])
                # Repetição: conta a reaparição e fica com a melhor das duas leituras.
                self._db.execute(
                    "UPDATE passagens SET vezes = vezes + 1, confianca = MAX(confianca, ?),"
                    " status = CASE WHEN ? = 'lida' THEN 'lida' ELSE status END WHERE id = ?",
                    (confianca, status, anterior[1]))
                self._db.commit()
                return None
            local = time.localtime(ts)
            base = os.path.join("imagens", time.strftime("%Y-%m-%d", local),
                                f"{time.strftime('%H%M%S', local)}_{int(ts % 1 * 1000):03d}_{placa or status}")
            caminhos = []
            for sufixo, conteudo in ((".jpg", imagem), ("_recorte.jpg", recorte)):
                caminhos.append(self._salvar(base + sufixo, conteudo) if conteudo else None)
            cursor = self._db.execute(
                "INSERT INTO passagens (ts, quando, placa, status, confianca, leituras, evento, imagem, recorte)"
                " VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                (ts, time.strftime("%Y-%m-%d %H:%M:%S", local), placa, status, confianca, leituras,
                 evento, *caminhos))
            self._db.commit()
            if placa:
                self._recentes[placa] = (ts, cursor.lastrowid)
            return cursor.lastrowid

    def _salvar(self, relativo: str, conteudo: bytes) -> str:
        caminho = os.path.join(self.pasta, relativo)
        os.makedirs(os.path.dirname(caminho), exist_ok=True)
        with open(caminho, "wb") as arquivo:
            arquivo.write(conteudo)
        return relativo

    def ultimas(self, n: int = 20) -> list[tuple]:
        with self._trava:
            return self._db.execute(
                "SELECT quando, placa, status, confianca, leituras, vezes, imagem FROM passagens"
                " ORDER BY ts DESC LIMIT ?", (n,)).fetchall()

    def contagem(self) -> dict[str, int]:
        with self._trava:
            return dict(self._db.execute("SELECT status, COUNT(*) FROM passagens GROUP BY status"))

    def limpar_antigos(self, agora: float | None = None) -> int:
        """Apaga registros e imagens além da retenção (poupa o cartão SD e limita dados pessoais)."""
        corte = (agora or time.time()) - self.retencao_s
        with self._trava:
            antigos = self._db.execute(
                "SELECT id, imagem, recorte FROM passagens WHERE ts < ?", (corte,)).fetchall()
            for _, *arquivos in antigos:
                for relativo in filter(None, arquivos):
                    try:
                        os.remove(os.path.join(self.pasta, relativo))
                    except FileNotFoundError:
                        pass
            self._db.execute("DELETE FROM passagens WHERE ts < ?", (corte,))
            self._db.commit()
            self._recentes = {p: v for p, v in self._recentes.items() if v[0] >= corte}
        return len(antigos)
