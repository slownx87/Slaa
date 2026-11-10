from types import SimpleNamespace

from leitor_placas.captura import Evento, Quadro, RelogioQuadros, Segmentador
from leitor_placas.fila import FilaEventos

KB = 1024


def evento(ident, quadros, tamanho=KB):
    return Evento(ident, 0.0, 2, 2, quadros=[Quadro(float(i), bytearray(tamanho)) for i in range(quadros)])


def cfg_mov(**extra):
    base = dict(pre_s=0.5, pos_s=0.7, max_evento_s=6.0, max_quadros_evento=24)
    return SimpleNamespace(**{**base, **extra})


def passar(seg, movimentos, fps=10):
    """Alimenta um quadro por item de `movimentos`; devolve os eventos fechados."""
    fechados = []
    for i, mov in enumerate(movimentos):
        ev = seg.alimentar(Quadro(i / fps, bytearray(6)), mov)
        if ev:
            fechados.append(ev)
    return fechados


def test_fila_ralea_antes_de_perder():
    fila = FilaEventos(max_bytes=40 * KB, min_quadros=4)
    fila.por(evento(1, 24))
    fila.por(evento(2, 24))
    assert fila.tamanho == 2 and fila.perdidos == 0 and fila.raleados > 0
    assert all(len(e.quadros) >= 4 for e in fila._itens)
    assert sum(e.bytes for e in fila._itens) <= 40 * KB


def test_fila_raleamento_mantem_inicio_e_fim_do_evento():
    ev = evento(1, 24)
    while ev.ralear(4):
        pass
    tempos = [q.t for q in ev.quadros]
    assert len(tempos) == 4 and tempos[0] == 0.0 and tempos[-1] >= 18.0


def test_fila_so_perde_quando_tudo_ja_esta_no_minimo_e_conta_a_perda():
    fila = FilaEventos(max_bytes=10 * KB, min_quadros=4)
    for i in range(4):
        fila.por(evento(i, 8))
    assert fila.perdidos == 2 and fila.tamanho == 2  # cabem 2 eventos de 4 quadros
    assert [e.id for e in fila._itens] == [2, 3]  # os mais antigos saem primeiro


def test_fila_tirar_e_fifo_e_nao_trava_vazia():
    fila = FilaEventos(max_bytes=1 << 20, min_quadros=4)
    assert fila.tirar(0.01) is None
    fila.por(evento(1, 2))
    fila.por(evento(2, 2))
    assert fila.tirar(0.01).id == 1 and fila.tirar(0.01).id == 2


def test_segmentador_inclui_pre_gatilho_e_corta_a_cauda_parada():
    seg = Segmentador(cfg_mov(), 2, 2)
    #          0-9 parado | 10-19 movimento | 20-39 parado
    (ev,) = passar(seg, [False] * 10 + [True] * 10 + [False] * 20)
    tempos = [q.t for q in ev.quadros]
    assert tempos[0] == 0.5  # 0,5 s antes do primeiro movimento (t=1,0)
    assert abs(tempos[-1] - 2.1) < 1e-9  # último movimento (1,9) + cauda de 0,2 s
    assert ev.inicio == 0.5 and ev.fim == tempos[-1]


def test_segmentador_separa_duas_passagens_e_junta_pausa_curta():
    seg = Segmentador(cfg_mov(), 2, 2)
    passagem = [True] * 5
    eventos = passar(seg, passagem + [False] * 3 + passagem + [False] * 15 + passagem + [False] * 15)
    assert len(eventos) == 2  # pausa de 0,3 s não separa; a de 1,5 s separa
    assert [e.id for e in eventos] == [1, 2]


def test_segmentador_fecha_evento_longo_e_abre_outro_sem_buraco():
    seg = Segmentador(cfg_mov(max_evento_s=2.0), 2, 2)
    eventos = passar(seg, [True] * 65)  # 6,5 s de movimento contínuo
    assert len(eventos) == 3
    for anterior, seguinte in zip(eventos, eventos[1:]):
        assert seguinte.quadros[0].t - anterior.quadros[-1].t <= 0.1 + 1e-9  # nenhum quadro pulado


def test_segmentador_limita_quadros_com_amostragem_uniforme():
    seg = Segmentador(cfg_mov(max_quadros_evento=8, max_evento_s=60.0), 2, 2)
    (ev,) = passar(seg, [True] * 100 + [False] * 10)
    assert len(ev.quadros) <= 8
    passos = {round(b.t - a.t, 6) for a, b in zip(ev.quadros, ev.quadros[1:])}
    assert len(passos) == 1  # espaçamento constante: o evento inteiro continua coberto
    assert ev.quadros[-1].t >= 8.0


def test_segmentador_fechar_no_meio_entrega_o_evento_aberto():
    seg = Segmentador(cfg_mov(), 2, 2)
    assert passar(seg, [True] * 5) == []
    ev = seg.fechar()
    assert ev is not None and len(ev.quadros) == 5
    assert seg.fechar() is None


def relogio_com(chegadas, fps_nominal=15.0, folga_max=15.0):
    """Horas atribuídas a quadros que chegam nos instantes `chegadas`."""
    instantes = iter(chegadas)
    relogio = RelogioQuadros(fps_nominal, folga_max, agora=lambda: next(instantes))
    return [relogio.marcar() for _ in chegadas]


def test_relogio_segue_a_chegada_quando_o_fluxo_esta_regular():
    chegadas = [100 + i / 15 for i in range(30)]
    assert relogio_com(chegadas) == chegadas


def test_relogio_espaca_os_quadros_de_uma_rajada():
    # 1 s de engasgo e depois 15 quadros de uma vez: continuam valendo ~1 s de vídeo.
    horas = relogio_com([100.0] + [101.0] * 15)
    assert 0.8 <= horas[-1] - horas[1] <= 1.0
    assert all(b > a for a, b in zip(horas, horas[1:]))


def test_relogio_corrige_fps_nominal_errado_e_limita_o_adiantamento():
    # A câmera diz 5 fps mas manda 15: sem correção a hora se adiantaria sem parar.
    chegadas = [100 + i / 15 for i in range(15 * 60)]
    horas = relogio_com(chegadas, fps_nominal=5.0, folga_max=3.0)
    assert max(h - c for h, c in zip(horas, chegadas)) <= 3.0 + 1e-9
    assert horas[-1] - chegadas[-1] < 0.01  # com o intervalo medido, volta a acompanhar a hora real
