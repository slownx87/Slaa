"""Detecção da placa (YOLOv9-t) e leitura dos caracteres (fast-plate-ocr), via ONNX Runtime."""

from __future__ import annotations

import cv2
import numpy as np


class Reconhecedor:
    def __init__(self, cfg):
        import onnxruntime as ort
        from fast_plate_ocr import LicensePlateRecognizer
        from open_image_models import create_detector

        opcoes = ort.SessionOptions()
        opcoes.intra_op_num_threads = cfg.threads
        opcoes.inter_op_num_threads = 1
        cpu = ["CPUExecutionProvider"]
        # Na primeira execução os modelos são baixados para ~/.cache.
        self.detector = create_detector(cfg.detector, conf_thresh=cfg.conf_detector,
                                        providers=cpu, sess_options=opcoes)
        self.ocr = LicensePlateRecognizer(hub_ocr_model=cfg.ocr, providers=cpu, sess_options=opcoes)
        cinza = self.ocr.config.image_color_mode == "grayscale"
        self._cor = cv2.COLOR_BGR2GRAY if cinza else cv2.COLOR_BGR2RGB

    def detectar(self, bgr: np.ndarray) -> list[tuple[int, int, int, int, float]]:
        """Caixas (x1, y1, x2, y2, confiança) das placas na imagem."""
        altura, largura = bgr.shape[:2]
        caixas = []
        for d in self.detector.predict(bgr):
            b = d.bounding_box
            x1, y1 = max(0, int(b.x1)), max(0, int(b.y1))
            x2, y2 = min(largura, int(b.x2)), min(altura, int(b.y2))
            if x2 - x1 >= 8 and y2 - y1 >= 8:
                caixas.append((x1, y1, x2, y2, float(d.confidence)))
        return caixas

    def ler(self, recortes: list[np.ndarray]) -> list[tuple[str, list[float]]]:
        """Para cada recorte de placa (BGR): texto só com A-Z/0-9 e a confiança de cada caractere."""
        if not recortes:
            return []
        imagens = [cv2.cvtColor(r, self._cor) for r in recortes]
        saida = []
        for p in self.ocr.run(imagens, return_confidence=True):
            probs = [] if p.char_probs is None else [float(v) for v in np.ravel(p.char_probs)]
            pares = [(c.upper(), probs[i] if i < len(probs) else 0.0)
                     for i, c in enumerate(p.plate) if c.isascii() and c.isalnum()]
            saida.append(("".join(c for c, _ in pares), [v for _, v in pares]))
        return saida
