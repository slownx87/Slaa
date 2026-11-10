"""Configuração do leitor (lida de um arquivo YAML; ver config.exemplo.yaml)."""

from __future__ import annotations

from dataclasses import dataclass, field, fields

import yaml


@dataclass
class Camera:
    url: str = ""
    decodificador: str = "auto"  # auto | hardware | software
    tempo_limite_s: float = 10.0  # sem quadros por esse tempo -> reconecta


@dataclass
class Zona:
    """Região de leitura em pixels do quadro inteiro. Zeros = quadro inteiro."""

    x: int = 0
    y: int = 0
    largura: int = 0
    altura: int = 0


@dataclass
class Movimento:
    limiar: int = 14  # diferença de brilho (0-255) para um ponto contar como "mudou"
    area_min: float = 0.01  # fração da zona que precisa mudar para haver movimento
    pre_s: float = 0.5  # quadros guardados de antes do gatilho
    pos_s: float = 0.7  # sem movimento por esse tempo -> fecha o evento
    max_evento_s: float = 6.0
    max_quadros_evento: int = 24


@dataclass
class Fila:
    max_mb: int = 200
    min_quadros_evento: int = 4


@dataclass
class Reconhecimento:
    detector: str = "yolo-v9-t-384-license-plate-end2end"
    ocr: str = "cct-xs-v2-global-model"
    conf_detector: float = 0.4
    largura_min_placa: int = 60  # placas menores que isso (px) não vão para o OCR
    intervalo_cobertura_s: float = 0.25
    leituras_consenso: int = 2
    conf_consenso: float = 0.8
    threads: int = 3  # o 4º núcleo fica para a captura


@dataclass
class Saida:
    pasta: str = "dados"
    salvar_sem_placa: bool = True
    retencao_dias: int = 30
    janela_duplicata_s: float = 20.0


@dataclass
class Config:
    camera: Camera = field(default_factory=Camera)
    zona: Zona = field(default_factory=Zona)
    movimento: Movimento = field(default_factory=Movimento)
    fila: Fila = field(default_factory=Fila)
    reconhecimento: Reconhecimento = field(default_factory=Reconhecimento)
    saida: Saida = field(default_factory=Saida)


def de_dict(dados: dict) -> Config:
    cfg = Config()
    secoes = {f.name for f in fields(Config)}
    for secao, valores in (dados or {}).items():
        if secao not in secoes:
            raise ValueError(f"seção desconhecida na configuração: {secao!r}")
        alvo = getattr(cfg, secao)
        chaves = {f.name for f in fields(alvo)}
        for chave, valor in (valores or {}).items():
            if chave not in chaves:
                raise ValueError(f"opção desconhecida em {secao}: {chave!r}")
            setattr(alvo, chave, type(getattr(alvo, chave))(valor))
    if not cfg.camera.url:
        raise ValueError("camera.url é obrigatório")
    if cfg.camera.decodificador not in ("auto", "hardware", "software"):
        raise ValueError("camera.decodificador deve ser auto, hardware ou software")
    return cfg


def carregar(caminho: str) -> Config:
    with open(caminho, encoding="utf-8") as arquivo:
        return de_dict(yaml.safe_load(arquivo))
