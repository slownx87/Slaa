<?php
/*
 * Testes da apuração do dia (tolerância por cargo). Rode no terminal:
 *
 *     php tests/apuracao_teste.php
 *
 * Não usa banco de dados: monta o cargo e as batidas na mão. Sai com código 1
 * se algum caso falhar.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/../relogio_ponto/_config.php';

$falhas = 0;
$total = 0;
function confere(string $nome, $obtido, $esperado): void
{
    global $falhas, $total;
    $total++;
    if ($obtido === $esperado) {
        echo "  ok   $nome\n";
        return;
    }
    $falhas++;
    echo "  FALHOU  $nome\n          esperado: " . var_export($esperado, true) . "\n          obtido:   " . var_export($obtido, true) . "\n";
}

/** Monta a jornada do funcionário sem banco (o que ponto_jornada_do_funcionario() faz com o cargo). */
function jornada_de(array $cargo): array
{
    $j = ponto_jornada_do_cargo($cargo);
    $t = $j;
    $t['id'] = 0;
    $t['principal'] = true;
    $t['horario'] = $j['entrada'] . '-' . $j['saida'];
    $t['descricao'] = 'Principal';
    $j['turnos'] = $j['definido'] ? [$t] : [];
    $j['multi'] = false;
    $j['turnos_texto'] = $t['horario'];
    return $j;
}

/** Dia com almoço de 1h: [entrada, saída almoço, volta, saída] em "HH:MM". Saída < entrada = dia seguinte. */
function dia(string $data, string $e, ?string $sa, ?string $va, string $s): array
{
    $reg = function (string $tipo, string $hm, string $dia) use ($data) {
        return ['id' => 0, 'funcionario_id' => 1, 'tipo' => $tipo, 'data_ponto' => $data, 'data_hora' => $dia . ' ' . $hm . ':00'];
    };
    $prox = date('Y-m-d', strtotime($data . ' +1 day'));
    $out = [$reg('entrada', $e, $data)];
    if ($sa !== null) { $out[] = $reg('saida_almoco', $sa, $data); $out[] = $reg('volta_almoco', $va, $data); }
    $out[] = $reg('saida', $s, $s < $e ? $prox : $data);
    $n = 1;
    foreach ($out as &$r) { $r['id'] = $n++; }
    return $out;
}

$porteiro = ['id' => 1, 'hora_entrada' => '08:00:00', 'hora_saida' => '17:00:00', 'minutos_intervalo' => 60, 'tolerancia_minutos' => 15, 'antecipacao_minutos' => null];
$D = '2026-08-03';

echo "Atraso tolerado\n";
$j = jornada_de($porteiro);
$a = ponto_apurar_dia(dia($D, '08:10', '12:00', '13:00', '17:10'), $j);
confere('jornada completa, 10 min depois: falta 0', $a['falta_min'], 0);
$a = ponto_apurar_dia(dia($D, '08:10', '12:00', '13:00', '17:00'), $j);
confere('chegou 08:10 e saiu 17:00 (tolerância 15): falta 0', $a['falta_min'], 0);
confere('  ...o atraso tolerado foi 10 min', $a['atraso_tolerado_min'], 10);
confere('  ...horas trabalhadas continuam reais (7h50)', $a['trabalho_min'], 470);
$a = ponto_apurar_dia(dia($D, '08:20', '12:00', '13:00', '17:00'), $j);
confere('chegou 08:20 (passou de 15): conta os 20 min inteiros', $a['falta_min'], 20);
$a = ponto_apurar_dia(dia($D, '08:10', '12:00', '13:00', '16:40'), $j);
confere('chegou 08:10 (tolerado) mas saiu 20 min antes: falta só os 20 da saída', $a['falta_min'], 20);

$semTol = $porteiro; $semTol['tolerancia_minutos'] = 0;
$a = ponto_apurar_dia(dia($D, '08:01', '12:00', '13:00', '17:00'), jornada_de($semTol));
confere('sem tolerância: 1 min de atraso conta', $a['falta_min'], 1);

echo "Chegada antecipada\n";
$comLimite = $porteiro; $comLimite['antecipacao_minutos'] = 10;
$a = ponto_apurar_dia(dia($D, '07:30', '12:00', '13:00', '16:30'), jornada_de($comLimite));
confere('07:30 com limite de 10 min: 20 min antes do limite não contam', $a['antecipacao_excedida_min'], 20);
confere('  ...trabalhado = 8h - 20 min = 7h40', $a['trabalho_min'], 460);
confere('  ...e faltam 20 min para fechar a jornada', $a['falta_min'], 20);
$a = ponto_apurar_dia(dia($D, '07:30', '12:00', '13:00', '16:30'), jornada_de($porteiro));
confere('mesmo dia, cargo SEM limite: nada é descontado', $a['falta_min'], 0);
confere('  ...trabalhado = 8h cheias', $a['trabalho_min'], 480);
$a = ponto_apurar_dia(dia($D, '07:55', '12:00', '13:00', '17:00'), jornada_de($comLimite));
confere('07:55 com limite de 10: dentro do limite, nada descontado', $a['antecipacao_excedida_min'], 0);
$zero = $porteiro; $zero['antecipacao_minutos'] = 0;
$a = ponto_apurar_dia(dia($D, '07:50', '12:00', '13:00', '17:00'), jornada_de($zero));
confere('limite 0: não pode chegar antes (10 min não contam)', $a['antecipacao_excedida_min'], 10);
confere('ponto_antecipacao_entrada_minutos avisa o excesso', ponto_antecipacao_entrada_minutos($D . ' 07:30:00', jornada_de($comLimite)), 30);
confere('  ...e fica 0 quando o cargo não tem limite', ponto_antecipacao_entrada_minutos($D . ' 07:30:00', jornada_de($porteiro)), 0);

echo "Turno da noite (vira o dia)\n";
$vigia = ['id' => 2, 'hora_entrada' => '18:00:00', 'hora_saida' => '06:00:00', 'minutos_intervalo' => 60, 'tolerancia_minutos' => 15, 'antecipacao_minutos' => 10];
$jv = jornada_de($vigia);
confere('jornada do vigia = 11h', $jv['jornada_min'], 660);
$a = ponto_apurar_dia(dia($D, '18:10', null, null, '06:00'), $jv);
confere('18:10 até 06:00: sem falta', $a['falta_min'], 0);
$a = ponto_apurar_dia(dia($D, '18:20', null, null, '05:00'), $jv);
confere('18:20 (passou da tolerância) até 05:00: bruto 10h40, falta 20', $a['falta_min'], 20);
confere('diferença na virada: esperado 23:00, bateu 00:05 = +65', ponto_diferenca_entrada_min('2026-08-04 00:05:00', 23 * 60), 65);
confere('diferença na virada: esperado 00:30, bateu 23:00 = -90', ponto_diferenca_entrada_min('2026-08-03 23:00:00', 30), -90);

echo "Cargo sem horário\n";
$sem = jornada_de(['id' => 3]);
$a = ponto_apurar_dia(dia($D, '08:10', '12:00', '13:00', '17:10'), $sem);
confere('sem horário cadastrado: regra automática de 8h, 08:10 é tolerado', $a['falta_min'], 0);
confere('textos', ponto_tolerancias_texto(jornada_de($comLimite)), 'atraso até 15 min · chegada até 10 min antes');
confere('textos (sem tolerância, sem limite)', ponto_tolerancias_texto(jornada_de($semTol)), 'sem tolerância de atraso · chegada antecipada sem limite');

echo "Troca de horário de uma batida\n";
confere('23:50 -> 00:10 passa para o dia seguinte', ponto_data_hora_mais_proxima('00:10', '2026-08-03 23:50:00'), '2026-08-04 00:10:00');
confere('saída 06:00 -> 05:50 continua no mesmo dia', ponto_data_hora_mais_proxima('05:50', '2026-08-04 06:00:00'), '2026-08-04 05:50:00');
confere('08:10 -> 08:00 continua no mesmo dia', ponto_data_hora_mais_proxima('08:00', '2026-08-03 08:10:00'), '2026-08-03 08:00:00');
confere('00:10 -> 23:55 volta para o dia anterior', ponto_data_hora_mais_proxima('23:55', '2026-08-04 00:10:00'), '2026-08-03 23:55:00');
confere('coordenada válida', ponto_coordenada('-23.5505199', 90), '-23.5505199');
confere('coordenada com lixo vira nulo', ponto_coordenada('abc', 90), null);
confere('coordenada fora da faixa vira nulo', ponto_coordenada('123', 90), null);

echo "Páscoa e feriados\n";
confere('Páscoa 2024', ponto_pascoa(2024), '2024-03-31');
confere('Páscoa 2025', ponto_pascoa(2025), '2025-04-20');
confere('Páscoa 2026', ponto_pascoa(2026), '2026-04-05');
confere('Páscoa 2027', ponto_pascoa(2027), '2027-03-28');
confere('Sexta-feira Santa 2026 é feriado', ponto_feriados_nacionais(2026)['2026-04-03'] ?? null, 'Sexta-feira Santa');
confere('Consciência Negra só a partir de 2024', [isset(ponto_feriados_nacionais(2023)['2023-11-20']), isset(ponto_feriados_nacionais(2024)['2024-11-20'])], [false, true]);
confere('2026 tem 10 feriados nacionais', count(ponto_feriados_nacionais(2026)), 10);
confere('domingo é folga', ponto_folga_do_dia('2026-10-04'), 'Domingo');
confere('sábado comum NÃO é folga', ponto_folga_do_dia('2026-10-03'), null);
confere('terça comum NÃO é folga', ponto_folga_do_dia('2026-10-06'), null);
confere('feriado em dia de semana', ponto_folga_do_dia('2026-09-07'), 'Independência do Brasil');
confere('feriado que cai no domingo mostra o nome do feriado', ponto_folga_do_dia('2026-11-15'), 'Proclamação da República');

echo "Falta (dia sem batida)\n";
$jan = ['primeiro' => '2026-10-01', 'ultimo' => '2026-10-31', 'ativo' => true];
$hoje = '2026-11-02';
confere('terça sem batida = falta', ponto_dia_eh_falta('2026-10-06', $jan, true, $hoje), true);
confere('sábado sem batida = falta', ponto_dia_eh_falta('2026-10-03', $jan, true, $hoje), true);
confere('domingo sem batida = folga', ponto_dia_eh_falta('2026-10-04', $jan, true, $hoje), false);
confere('feriado (12/10) sem batida = folga', ponto_dia_eh_falta('2026-10-12', $jan, true, $hoje), false);
confere('hoje ainda não é falta', ponto_dia_eh_falta('2026-11-02', $jan, true, $hoje), false);
confere('antes da primeira batida não é falta', ponto_dia_eh_falta('2026-09-30', $jan, true, $hoje), false);
confere('sem nenhuma batida na vida: nunca é falta', ponto_dia_eh_falta('2026-10-06', ['primeiro' => null, 'ultimo' => null, 'ativo' => true], true, $hoje), false);
$sai = ['primeiro' => '2026-10-01', 'ultimo' => '2026-10-20', 'ativo' => false];
confere('desativado: depois da última batida não é falta', ponto_dia_eh_falta('2026-10-27', $sai, true, $hoje), false);
confere('desativado: antes da última batida é falta', ponto_dia_eh_falta('2026-10-14', $sai, true, $hoje), true);
confere('cargo que não controla falta (12x36): nunca', ponto_dia_eh_falta('2026-10-06', $jan, false, $hoje), false);
confere('jornada perdida na falta = a do turno principal', ponto_jornada_padrao_min(jornada_de($porteiro)), 480);
confere('  ...sem horário: 8 h', ponto_jornada_padrao_min(jornada_de(['id' => 9])), 480);

echo "Turno da noite pelo cargo\n";
confere('padrão (sem cargo): 17:00 e 08:00', ponto_janela_noite_da_jornada(null), [1020, 480]);
confere('18:00-06:00 mantém o padrão', ponto_janela_noite_da_jornada(jornada_de($vigia)), [1020, 480]);
$cedo = ['id' => 7, 'hora_entrada' => '16:00:00', 'hora_saida' => '02:00:00', 'minutos_intervalo' => 60];
confere('16:00-02:00 passa a valer desde 14:00', ponto_janela_noite_da_jornada(jornada_de($cedo))[0], 840);
$longo = ['id' => 8, 'hora_entrada' => '22:00:00', 'hora_saida' => '10:00:00', 'minutos_intervalo' => 60];
confere('22:00-10:00: a saída vale até 12:00', ponto_janela_noite_da_jornada(jornada_de($longo))[1], 720);
$diurno = ['id' => 6, 'hora_entrada' => '08:00:00', 'hora_saida' => '17:00:00', 'minutos_intervalo' => 60];
confere('turno diurno não muda nada', ponto_janela_noite_da_jornada(jornada_de($diurno)), [1020, 480]);

echo "Meta da semana pelo cargo\n";
confere('8 h/dia = 44 h (como antes)', ponto_meta_semana_min(480, true), 2640);
confere('6 h/dia = 33 h', ponto_meta_semana_min(360, true), 1980);
confere('12x36 (11 h líquidas) = 38h30', ponto_meta_semana_min(660, true), 2310);
confere('cargo sem horário = 44 h', ponto_meta_semana_min(480, false), 2640);
confere('9 h/dia não passa de 44 h', ponto_meta_semana_min(540, true), 2640);

echo "Entrada antes da hora (aviso e bloqueio)\n";
$jl = jornada_de($comLimite); // 08:00-17:00, limite de 10 min antes
$b = ponto_entrada_antecipada_da_jornada($jl, '2026-08-03 07:49:00');
confere('07:49 com limite de 10: bloqueia', $b !== null, true);
confere('  ...libera a partir das 07:50', $b['liberado_em'] ?? null, '07:50');
confere('  ...falta 1 min', $b['faltam_min'] ?? null, 1);
confere('07:50 já pode', ponto_entrada_antecipada_da_jornada($jl, '2026-08-03 07:50:00'), null);
confere('08:30 (atrasado) nunca é bloqueado', ponto_entrada_antecipada_da_jornada($jl, '2026-08-03 08:30:00'), null);
confere('06:00 bloqueia (falta 1h50)', (ponto_entrada_antecipada_da_jornada($jl, '2026-08-03 06:00:00') ?? [])['faltam_min'] ?? null, 110);
confere('cargo SEM limite: não bloqueia nunca', ponto_entrada_antecipada_da_jornada(jornada_de($porteiro), '2026-08-03 05:00:00'), null);
$zero2 = $porteiro; $zero2['antecipacao_minutos'] = 0;
confere('limite 0: 07:59 já bloqueia', ponto_entrada_antecipada_da_jornada(jornada_de($zero2), '2026-08-03 07:59:00') !== null, true);
confere('mensagem diz o horário', strpos($b['mensagem'], '08:00-17:00') !== false && strpos($b['mensagem'], '07:50') !== false, true);
confere('turno da noite 18:00 (limite 10): 17:45 bloqueia', (ponto_entrada_antecipada_da_jornada($jv, '2026-08-03 17:45:00') ?? [])['liberado_em'] ?? null, '17:50');
confere('turno da noite: 00:30 (6h30 depois) não bloqueia', ponto_entrada_antecipada_da_jornada($jv, '2026-08-04 00:30:00'), null);
$escala = ['id' => 5, 'hora_entrada' => '06:00:00', 'hora_saida' => '14:00:00', 'minutos_intervalo' => 60, 'tolerancia_minutos' => 15, 'antecipacao_minutos' => 10];
$je = jornada_de($escala);
$t2 = $je['turnos'][0]; $t2['id'] = 9; $t2['principal'] = false; $t2['entrada_min'] = 840; $t2['saida_min'] = 1320; $t2['entrada'] = '14:00'; $t2['saida'] = '22:00'; $t2['horario'] = '14:00-22:00'; $t2['descricao'] = '14:00-22:00';
$je['turnos'][] = $t2; $je['multi'] = true;
confere('escala 06:00/14:00: 13:30 bloqueia o turno das 14:00', (ponto_entrada_antecipada_da_jornada($je, '2026-08-03 13:30:00') ?? [])['liberado_em'] ?? null, '13:50');
confere('escala 06:00/14:00: 05:55 pode (turno das 06:00)', ponto_entrada_antecipada_da_jornada($je, '2026-08-03 05:55:00'), null);

echo "\n$total verificações, $falhas falha(s).\n";
exit($falhas ? 1 : 0);
