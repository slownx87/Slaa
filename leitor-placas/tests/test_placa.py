from leitor_placas.placa import Agregador, Leitura, corrigir, distancia


def leitura(quadro, texto, prob=0.95, caixa=(10, 10, 110, 40)):
    return Leitura(quadro, float(quadro), caixa, 0.9, texto, [prob] * len(texto))


def test_corrigir_aceita_os_dois_padroes():
    assert corrigir("ABC1234") == "ABC1234"  # antiga
    assert corrigir("ABC1D23") == "ABC1D23"  # Mercosul


def test_corrigir_troca_confusoes_pela_posicao():
    assert corrigir("A8C1Z34") == "ABC1Z34"  # 8 em posição de letra vira B; Z na 5ª fica
    assert corrigir("ABCI2S4") == "ABC1254"  # I e S em posição de dígito


def test_corrigir_rejeita_o_que_nao_e_placa():
    assert corrigir("ABC123") is None
    assert corrigir("1234567") is None
    assert corrigir("ABCDEFG") is None


def test_distancia():
    assert distancia("ABC1D23", "ABC1D23") == 0
    assert distancia("ABC1D23", "ABC1D28") == 1
    assert distancia("ABC1", "ABC1D23") == 3


def test_voto_vence_erro_isolado_e_da_consenso():
    ag = Agregador(2, 0.8)
    for q, texto in enumerate(["ABC1D23", "ABC1D23", "ABC1D28"]):
        ag.adicionar(leitura(q, texto))
    (r,) = ag.resultados()
    assert (r.placa, r.status, r.leituras) == ("ABC1D23", "lida", 3)
    assert ag.quadros_pendentes() == []


def test_uma_leitura_so_fica_incerta_e_pendente():
    ag = Agregador(2, 0.8)
    ag.adicionar(leitura(4, "ABC1D23"))
    (r,) = ag.resultados()
    assert (r.placa, r.status) == ("ABC1D23", "incerta")
    assert ag.quadros_pendentes() == [4]


def test_baixa_confianca_nao_vira_lida():
    ag = Agregador(2, 0.8)
    ag.adicionar(leitura(0, "ABC1D23", 0.5))
    ag.adicionar(leitura(1, "ABC1D23", 0.5))
    assert ag.resultados()[0].status == "incerta"


def test_dois_veiculos_no_mesmo_evento_ficam_separados():
    ag = Agregador(2, 0.8)
    for q in range(2):
        ag.adicionar(leitura(q, "ABC1D23"))
        ag.adicionar(leitura(q, "ABC1D24", caixa=(300, 10, 400, 40)))  # 1 caractere de diferença
    assert sorted(r.placa for r in ag.resultados()) == ["ABC1D23", "ABC1D24"]
    assert all(r.status == "lida" for r in ag.resultados())


def test_pedaco_de_placa_entra_no_mesmo_grupo():
    ag = Agregador(2, 0.8)
    for q, texto in enumerate(["ABC1", "ABC1D23", "ABC1D23"]):
        ag.adicionar(leitura(q, texto))
    (r,) = ag.resultados()
    assert (r.placa, r.status) == ("ABC1D23", "lida")


def test_placa_vista_mas_pequena_demais_e_registrada_como_incerta():
    ag = Agregador(2, 0.8)
    ag.adicionar(Leitura(3, 3.0, (0, 0, 30, 10), 0.6))
    assert not ag.vazio and ag.quadros_pendentes() == [3]
    (r,) = ag.resultados()
    assert (r.placa, r.status) == ("", "incerta")
