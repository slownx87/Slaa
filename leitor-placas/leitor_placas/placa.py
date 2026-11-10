"""Regras de placa brasileira e votação entre as leituras de uma passagem."""

from __future__ import annotations

import re
from dataclasses import dataclass, field

# Antiga: AAA9999. Mercosul: AAA9A99. Só a 5ª posição muda de tipo.
PADRAO = re.compile(r"^[A-Z]{3}[0-9][A-Z0-9][0-9]{2}$")
POSICOES_LETRA = (0, 1, 2)
POSICOES_DIGITO = (3, 5, 6)
PARA_LETRA = {"0": "O", "1": "I", "2": "Z", "5": "S", "6": "G", "8": "B"}
PARA_DIGITO = {"O": "0", "Q": "0", "D": "0", "I": "1", "L": "1", "Z": "2", "S": "5", "G": "6", "B": "8"}


def corrigir(texto: str) -> str | None:
    """Troca confusões típicas de OCR conforme a posição; None se não virar placa válida."""
    if len(texto) != 7:
        return None
    letras = list(texto)
    for i in POSICOES_LETRA:
        letras[i] = PARA_LETRA.get(letras[i], letras[i])
    for i in POSICOES_DIGITO:
        letras[i] = PARA_DIGITO.get(letras[i], letras[i])
    corrigido = "".join(letras)
    return corrigido if PADRAO.match(corrigido) else None


def distancia(a: str, b: str) -> int:
    """Distância de edição (Levenshtein)."""
    anterior = list(range(len(b) + 1))
    for i, ca in enumerate(a, 1):
        atual = [i]
        for j, cb in enumerate(b, 1):
            atual.append(min(anterior[j] + 1, atual[j - 1] + 1, anterior[j - 1] + (ca != cb)))
        anterior = atual
    return anterior[-1]


def _parecidas(a: str, b: str) -> bool:
    curta, longa = sorted((a, b), key=len)
    # Mesma placa com até 2 erros, ou um pedaço dela (placa lida pela metade).
    return distancia(a, b) <= 2 or (len(curta) >= 3 and curta in longa)


@dataclass
class Leitura:
    quadro: int  # índice do quadro dentro do evento
    t: float
    caixa: tuple[int, int, int, int]
    conf_det: float
    texto: str = ""  # vazio: placa detectada mas pequena demais para o OCR
    probs: list[float] = field(default_factory=list)

    @property
    def valida(self) -> str | None:
        return corrigir(self.texto)

    @property
    def chave(self) -> str:
        return self.valida or self.texto

    @property
    def conf(self) -> float:
        return sum(self.probs) / len(self.probs) if self.probs else 0.0


@dataclass
class Resultado:
    placa: str
    status: str  # "lida" (consenso em formato válido) ou "incerta" (guardar para revisão)
    confianca: float
    leituras: int
    melhor: Leitura


class Grupo:
    """Leituras que, ao que tudo indica, são da mesma placa."""

    def __init__(self, primeira: Leitura):
        self.leituras = [primeira]

    def aceita(self, leitura: Leitura) -> bool:
        # Duas placas no mesmo quadro são, por definição, veículos diferentes.
        if any(l.quadro == leitura.quadro for l in self.leituras):
            return False
        return any(_parecidas(l.chave, leitura.chave) for l in self.leituras)

    def resultado(self, min_leituras: int, min_conf: float) -> Resultado:
        validas = [l for l in self.leituras if l.valida]
        if not validas:
            melhor = max(self.leituras, key=lambda l: l.conf)
            return Resultado(melhor.texto, "incerta", melhor.conf, len(self.leituras), melhor)
        # Voto por posição, ponderado pela confiança de cada caractere.
        placa, confs = "", []
        for i in range(7):
            pesos: dict[str, float] = {}
            for l in validas:
                pesos[l.valida[i]] = pesos.get(l.valida[i], 0.0) + l.probs[i]
            vencedor = max(pesos, key=pesos.get)
            placa += vencedor
            confs.append(pesos[vencedor] / len(validas))
        confianca = sum(confs) / 7
        apoio = [l for l in validas if l.valida == placa]
        melhor = max(apoio or validas, key=lambda l: l.conf)
        lida = len(apoio) >= min_leituras and confianca >= min_conf
        return Resultado(placa, "lida" if lida else "incerta", confianca, len(self.leituras), melhor)


class Agregador:
    def __init__(self, min_leituras: int, min_conf: float):
        self.min_leituras, self.min_conf = min_leituras, min_conf
        self.grupos: list[Grupo] = []
        self.sem_texto: list[Leitura] = []

    def adicionar(self, leitura: Leitura) -> None:
        if not leitura.texto:
            self.sem_texto.append(leitura)
            return
        for grupo in self.grupos:
            if grupo.aceita(leitura):
                grupo.leituras.append(leitura)
                return
        self.grupos.append(Grupo(leitura))

    @property
    def vazio(self) -> bool:
        return not self.grupos and not self.sem_texto

    def quadros_pendentes(self) -> list[int]:
        """Quadros onde apareceu uma placa que ainda não tem leitura de consenso."""
        pendentes = [l.quadro for g in self.grupos
                     if g.resultado(self.min_leituras, self.min_conf).status != "lida"
                     for l in g.leituras]
        if not self.grupos:
            pendentes += [l.quadro for l in self.sem_texto]
        return pendentes

    def resultados(self) -> list[Resultado]:
        saida = [g.resultado(self.min_leituras, self.min_conf) for g in self.grupos]
        if not saida and self.sem_texto:
            maior = max(self.sem_texto, key=lambda l: l.caixa[2] - l.caixa[0])
            saida.append(Resultado("", "incerta", 0.0, len(self.sem_texto), maior))
        return saida
