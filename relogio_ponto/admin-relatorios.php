<?php
/*
 * Painel administrativo - relatórios e cálculos.
 *
 * Saldo de horas por funcionário no período escolhido, com exportação em CSV.
 * O espelho em PDF é gerado aqui mesmo, sem sair da página.
 */

require_once __DIR__ . '/_config.php';
require_once __DIR__ . '/_admin_shell.php';
require_once __DIR__ . '/_relatorio_espelho.php';
require_admin();

/** "06:00-14:00 (12d) · 14:00-22:00 (8d)" — em que horários o mês caiu. */
function rel_turnos_usados_texto(array $turnosUsados): string
{
    if (count($turnosUsados) < 2) {
        return ''; // um horário só não precisa de explicação
    }
    arsort($turnosUsados);
    $partes = [];
    foreach ($turnosUsados as $horario => $dias) {
        $partes[] = $horario . ' (' . $dias . 'd)';
    }
    return implode(' · ', $partes);
}

/** Soma horas trabalhadas e atrasos de cada funcionário no período. */
function rel_calcular(string $ini, string $fim, int $condominioId = 0, int $funcionarioId = 0): array
{
    [$filtro, $params] = admin_filtro_condominio('f');
    $extra = '';
    if ($condominioId > 0) {
        $extra .= ' AND f.condominio_id = ? ';
        $params[] = $condominioId;
    }
    if ($funcionarioId > 0) {
        $extra .= ' AND f.id = ? ';
        $params[] = $funcionarioId;
    }

    /* Funcionário desativado que bateu ponto no período CONTINUA no relatório:
     * quem saiu no meio do mês tem horas a fechar (antes ele sumia do total). */
    $stmt = db()->prepare("SELECT f.id, f.nome, f.ativo, c.nome AS cargo_nome, co.nome AS condominio_nome
        FROM ponto_funcionarios f
        LEFT JOIN ponto_cargos c ON c.id = f.cargo_id
        INNER JOIN ponto_condominios co ON co.id = f.condominio_id
        WHERE (f.ativo = 1 OR EXISTS (SELECT 1 FROM ponto_registros rr
                                       WHERE rr.funcionario_id = f.id AND rr.data_ponto BETWEEN ? AND ?))
              {$filtro} {$extra}
        ORDER BY f.nome");
    $stmt->execute(array_merge([$ini, $fim], $params));

    $linhas = [];
    foreach ($stmt->fetchAll() as $f) {
        $id = (int)$f['id'];
        $trabalho = 0;
        $atrasos = 0;
        $diasComBatida = 0;
        $diasAtrasados = 0;
        $jornadaCargo = ponto_jornada_do_funcionario($id);
        $esperadoTotal = 0;
        $turnosUsados = []; // horário do cargo => quantos dias caíram nele
        $faltas = 0;
        $faltasDias = [];
        $janela = ponto_funcionario_janela($id);
        $controlaFalta = !isset($jornadaCargo['controla_falta']) || (int)$jornadaCargo['controla_falta'] === 1;

        /* Uma consulta por funcionário, não uma por dia: com 50 pessoas num mês
         * o laço antigo disparava mais de mil consultas só para abrir a tela.
         * A janela vai um dia antes e dois depois para pegar a saída da
         * madrugada de quem faz turno noturno. */
        $stmtReg = db()->prepare("SELECT * FROM ponto_registros
            WHERE funcionario_id = ? AND data_hora >= ? AND data_hora < ?
            ORDER BY data_hora ASC, id ASC");
        $stmtReg->execute([
            $id,
            date('Y-m-d 00:00:00', strtotime($ini . ' -1 day')),
            date('Y-m-d 00:00:00', strtotime($fim . ' +2 days')),
        ]);
        $registrosPeriodo = $stmtReg->fetchAll();

        $ts = strtotime($ini);
        $fimTs = strtotime($fim);
        while ($ts !== false && $ts <= $fimTs) {
            $dia = date('Y-m-d', $ts);
            $ts = strtotime('+1 day', $ts);

            $lista = ponto_registros_do_dia_em_memoria($registrosPeriodo, $dia, $id);
            if (!$lista) {
                /* Dia sem nenhuma batida: falta, a não ser domingo/feriado (folga)
                 * ou fora do período em que a pessoa trabalhou aqui. */
                if (ponto_dia_eh_falta($dia, $janela, $controlaFalta)) {
                    $faltas++;
                    $faltasDias[] = $dia;
                    $esperadoTotal += ponto_jornada_padrao_min($jornadaCargo);
                }
                continue;
            }
            if (!jornada_fechada($lista) || open_interval($lista)) continue;

            /* Atraso = horas que faltaram para fechar a jornada do turno, já com
             * as tolerâncias do cargo (atraso tolerado e chegada antecipada).
             * Cargo de escala: o turno é escolhido dia a dia pela batida, e o
             * esperado do período soma o de cada dia — usar o horário
             * principal para o mês inteiro é o que quebrava a conta. */
            $falta = ponto_falta_horas_do_dia($lista, $jornadaCargo);
            $minutos = (int)$falta['trabalhado'];

            $diasComBatida++;
            $trabalho += $minutos;
            $esperadoTotal += (int)$falta['jornada_min'];
            if ($falta['turno_horario'] !== '') {
                $turnosUsados[$falta['turno_horario']] = ($turnosUsados[$falta['turno_horario']] ?? 0) + 1;
            }

            if ($falta['minutos'] > 0) {
                $atrasos += $falta['minutos'];
                $diasAtrasados++;
            }
        }

        $esperado = $esperadoTotal;
        $linhas[] = [
            'id' => $id,
            'ativo' => (int)$f['ativo'] === 1,
            'faltas' => $faltas,
            'faltas_dias' => $faltasDias,
            'nome' => (string)$f['nome'],
            'cargo' => (string)($f['cargo_nome'] ?: 'Sem cargo'),
            'condominio' => (string)$f['condominio_nome'],
            'dias' => $diasComBatida,
            'trabalho_min' => $trabalho,
            'atrasos_min' => $atrasos,
            'dias_atrasados' => $diasAtrasados,
            'esperado_min' => $esperado,
            'percentual' => $esperado > 0 ? min(150, (int)round($trabalho / $esperado * 100)) : 0,
            /* Cargo de escala mostra todos os horários aceitos, não só o
             * principal — era esse rótulo que dava a impressão de que o
             * sistema só conhecia um horário. */
            'jornada' => !empty($jornadaCargo['definido'])
                ? ((string)($jornadaCargo['turnos_texto'] ?? '') ?: $jornadaCargo['entrada'] . '-' . $jornadaCargo['saida'])
                : 'sem horário no cargo',
            /* E quais deles a pessoa realmente cumpriu no período. */
            'turnos_usados' => rel_turnos_usados_texto($turnosUsados),
            /* Tolerâncias do cargo, para quem lê o relatório entender o número. */
            'regras' => !empty($jornadaCargo['definido']) ? ponto_tolerancias_texto($jornadaCargo) : '',
        ];
    }
    return $linhas;
}

function rel_periodo(): array
{
    $periodo = (string)($_REQUEST['periodo'] ?? 'mes');
    if ($periodo === 'anterior') {
        $ini = date('Y-m-01', strtotime('-1 month'));
        $fim = date('Y-m-t', strtotime('-1 month'));
        $rotulo = 'Mês anterior';
    } elseif ($periodo === 'custom') {
        $ini = (string)($_REQUEST['ini'] ?? date('Y-m-01'));
        $fim = (string)($_REQUEST['fim'] ?? today_sp());
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ini)) $ini = date('Y-m-01');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fim)) $fim = today_sp();
        if ($fim < $ini) [$ini, $fim] = [$fim, $ini];
        $rotulo = date('d/m/Y', strtotime($ini)) . ' a ' . date('d/m/Y', strtotime($fim));
    } else {
        $periodo = 'mes';
        $ini = date('Y-m-01');
        $fim = today_sp();
        $rotulo = 'Este mês';
    }
    return [$periodo, $ini, $fim, $rotulo];
}

[$periodo, $ini, $fim, $rotuloPeriodo] = rel_periodo();
$condominioFiltro = (int)($_REQUEST['condominio'] ?? 0);
$funcionarioFiltro = (int)($_REQUEST['funcionario'] ?? 0); // 0 = todos

/* ------------------------------ exportar CSV ------------------------------ */
if (isset($_GET['exportar'])) {
    $linhas = rel_calcular($ini, $fim, $condominioFiltro, $funcionarioFiltro);
    $nome = 'relatorio_ponto_' . $ini . '_a_' . $fim . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nome . '"');

    /* Excel/LibreOffice executam célula que começa com = + - @ : um nome de
     * funcionário assim viraria fórmula. O apóstrofo na frente a torna texto. */
    $seguro = function ($v) {
        $v = (string)$v;
        return ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) ? "'" . $v : $v;
    };

    $saida = fopen('php://output', 'w');
    fwrite($saida, "\xEF\xBB\xBF"); // BOM para o Excel abrir com acento certo
    fputcsv($saida, ['Funcionario', 'Cargo', 'Condominio', 'Horarios do cargo', 'Horarios cumpridos', 'Dias com batida', 'Horas trabalhadas', 'Atrasos', 'Dias com atraso', 'Faltas (dias)', 'Esperado'], ';');
    foreach ($linhas as $l) {
        fputcsv($saida, [
            $seguro($l['nome'] . ($l['ativo'] ? '' : ' (desativado)')), $seguro($l['cargo']), $seguro($l['condominio']),
            $l['jornada'], $l['turnos_usados'], $l['dias'],
            fmt_minutes($l['trabalho_min']),
            fmt_minutes($l['atrasos_min']), $l['dias_atrasados'], $l['faltas'], fmt_minutes($l['esperado_min']),
        ], ';');
    }
    fclose($saida);
    log_action('exportar_relatorio', 'Exportou CSV de ' . $ini . ' a ' . $fim);
    exit;
}

$linhas = rel_calcular($ini, $fim, $condominioFiltro, $funcionarioFiltro);
$condominios = get_condominios(true);

/* Lista do seletor de funcionário. */
[$filtroF, $paramsF] = admin_filtro_condominio('f');
$stmt = db()->prepare("SELECT f.id, f.nome, f.ativo FROM ponto_funcionarios f WHERE 1=1 {$filtroF} ORDER BY f.ativo DESC, f.nome");
$stmt->execute($paramsF);
$listaFuncionarios = $stmt->fetchAll();

/* Espelho já pronto para o PDF: sai junto com a página, então o botão gera na
 * hora, sem abrir outra tela. */
$espelho = espelho_pdf_dados($ini, $fim, $condominioFiltro, $funcionarioFiltro > 0 ? [$funcionarioFiltro] : []);

$totalTrabalho = array_sum(array_column($linhas, 'trabalho_min'));
$totalAtrasos = array_sum(array_column($linhas, 'atrasos_min'));
$totalFaltas = array_sum(array_column($linhas, 'faltas'));

$csrf = csrf_token();
app_mobile_head('Relatórios - ' . APP_NAME, 'bg-background text-on-background antialiased min-h-screen', admin_css(), 'manifest-admin.webmanifest');
admin_topbar();
?>
<main class="pt-[70px] pb-24 px-container-margin max-w-md mx-auto w-full flex flex-col gap-stack-gap-md">

<div class="flex flex-col gap-1 fade-in-up">
<h1 class="font-headline-md text-headline-md text-on-surface">Relatórios</h1>
<p class="font-body-md text-body-md text-secondary"><?= h($rotuloPeriodo) ?> · <?= count($linhas) ?> funcionário(s)</p>
</div>

<!-- -------------------------------- filtros -------------------------------- -->
<form class="cartao rounded-xl p-3 flex flex-col gap-3 fade-in-up" method="get">
<div class="grid grid-cols-2 gap-2">
<div class="flex flex-col gap-1">
<label class="font-label-bold text-label-bold text-secondary uppercase tracking-wider" for="periodo">Período</label>
<select class="campo font-body-md text-body-md" id="periodo" name="periodo">
<option value="mes" <?= $periodo === 'mes' ? 'selected' : '' ?>>Este mês</option>
<option value="anterior" <?= $periodo === 'anterior' ? 'selected' : '' ?>>Mês anterior</option>
<option value="custom" <?= $periodo === 'custom' ? 'selected' : '' ?>>Escolher datas</option>
</select>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-bold text-label-bold text-secondary uppercase tracking-wider" for="condominio">Condomínio</label>
<select class="campo font-body-md text-body-md" id="condominio" name="condominio">
<option value="0">Todos</option>
<?php foreach ($condominios as $c): ?>
<option value="<?= (int)$c['id'] ?>" <?= $condominioFiltro === (int)$c['id'] ? 'selected' : '' ?>><?= h((string)$c['nome']) ?></option>
<?php endforeach; ?>
</select>
</div>
</div>

<div class="flex flex-col gap-1">
<label class="font-label-bold text-label-bold text-secondary uppercase tracking-wider" for="funcionario">Funcionário</label>
<select class="campo font-body-md text-body-md" id="funcionario" name="funcionario">
<option value="0">Todos os funcionários</option>
<?php foreach ($listaFuncionarios as $f): ?>
<option value="<?= (int)$f['id'] ?>" <?= $funcionarioFiltro === (int)$f['id'] ? 'selected' : '' ?>><?= h((string)$f['nome']) ?><?= (int)$f['ativo'] === 1 ? '' : ' (desativado)' ?></option>
<?php endforeach; ?>
</select>
</div>

<div class="grid grid-cols-2 gap-2 <?= $periodo === 'custom' ? '' : 'hidden' ?>" id="blocoDatas">
<div class="flex flex-col gap-1">
<label class="font-label-bold text-label-bold text-secondary uppercase tracking-wider" for="ini">De</label>
<input class="campo font-body-md text-body-md" id="ini" name="ini" type="date" value="<?= h($ini) ?>"/>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-bold text-label-bold text-secondary uppercase tracking-wider" for="fim">Até</label>
<input class="campo font-body-md text-body-md" id="fim" name="fim" type="date" value="<?= h($fim) ?>"/>
</div>
</div>

<button class="h-[42px] rounded-full bg-primary text-on-primary font-label-bold text-label-bold active:scale-95 transition-transform" type="submit">Aplicar filtros</button>
</form>

<!-- --------------------------------- totais --------------------------------- -->
<section class="grid grid-cols-3 gap-2 fade-in-up">
<div class="cartao rounded-xl p-2.5 flex flex-col gap-0.5">
<span class="font-label-sm text-label-sm text-secondary uppercase tracking-wider">Trabalhadas</span>
<span class="font-headline-md text-headline-md text-on-surface"><?= h(fmt_minutes($totalTrabalho)) ?></span>
</div>
<div class="cartao rounded-xl p-2.5 flex flex-col gap-0.5 border-error/25">
<span class="font-label-sm text-label-sm text-secondary uppercase tracking-wider">Atrasos</span>
<span class="font-headline-md text-headline-md text-error"><?= h(fmt_minutes($totalAtrasos)) ?></span>
</div>
<div class="cartao rounded-xl p-2.5 flex flex-col gap-0.5 border-error/25">
<span class="font-label-sm text-label-sm text-secondary uppercase tracking-wider">Faltas</span>
<span class="font-headline-md text-headline-md text-error"><?= (int)$totalFaltas ?> dia(s)</span>
</div>
</section>

<?php $qtdEspelhos = count($espelho['espelhos']); ?>
<div class="flex flex-col gap-2">
<button class="h-[46px] rounded-full bg-primary text-on-primary font-label-bold text-label-bold flex items-center justify-center gap-2 shadow-lg active:scale-95 transition-transform disabled:opacity-50" id="btnPdf" type="button">
<span class="material-symbols-outlined text-[18px]">picture_as_pdf</span>
<span>Exportar espelho em PDF<?= $qtdEspelhos > 1 ? ' (' . $qtdEspelhos . ' num arquivo só)' : '' ?></span>
</button>
<?php if ($qtdEspelhos > 1): ?>
<!-- ZIP continua para quem precisa arquivar o espelho de cada um separado. -->
<button class="h-[42px] rounded-full border border-primary text-primary font-label-bold text-label-bold flex items-center justify-center gap-2 active:scale-95 transition-transform disabled:opacity-50" id="btnPdfZip" type="button">
<span class="material-symbols-outlined text-[18px]">folder_zip</span>
<span>Um PDF por funcionário (ZIP)</span>
</button>
<?php endif; ?>
<a class="h-[46px] rounded-full border border-primary text-primary font-label-bold text-label-bold flex items-center justify-center gap-2 active:scale-95 transition-transform"
   href="?exportar=1&amp;periodo=<?= h($periodo) ?>&amp;ini=<?= h($ini) ?>&amp;fim=<?= h($fim) ?>&amp;condominio=<?= $condominioFiltro ?>&amp;funcionario=<?= $funcionarioFiltro ?>">
<span class="material-symbols-outlined text-[18px]">table_view</span> Exportar resumo em CSV
</a>
</div>

<!-- ------------------------------ funcionários ------------------------------ -->
<section class="flex flex-col gap-2">
<h2 class="font-label-bold text-label-bold text-secondary uppercase tracking-wider">Por funcionário</h2>

<?php if (!$linhas): ?>
<div class="cartao rounded-xl p-6 text-center">
<span class="material-symbols-outlined text-outline text-[28px] mb-1">query_stats</span>
<p class="font-body-md text-body-md text-secondary">Nenhum funcionário no filtro escolhido.</p>
</div>
<?php endif; ?>

<?php foreach ($linhas as $i => $l):
    $corBarra = $l['atrasos_min'] > 0 ? 'bg-error' : 'bg-primary'; ?>
<article class="surgir cartao rounded-xl p-3 flex flex-col gap-2 relative overflow-hidden" style="animation-delay:<?= min($i, 10) * 45 ?>ms">
<div class="absolute left-0 top-0 bottom-0 w-1 <?= $corBarra ?>"></div>
<div class="flex items-center gap-2 pl-1">
<div class="w-8 h-8 rounded-full bg-surface-variant flex items-center justify-center text-primary font-label-bold text-label-bold flex-shrink-0">
<?= h(mb_strtoupper(mb_substr($l['nome'], 0, 2))) ?>
</div>
<div class="min-w-0">
<p class="font-body-lg text-body-lg text-on-surface font-medium truncate"><?= h($l['nome']) ?><?= $l['ativo'] ? '' : ' (desativado)' ?></p>
<p class="font-label-sm text-label-sm text-secondary truncate"><?= h($l['cargo']) ?> · <?= h($l['condominio']) ?></p>
<p class="font-label-sm text-label-sm <?= $l['jornada'] === 'sem horário no cargo' ? 'text-error' : 'text-primary' ?>"><?= h($l['jornada']) ?></p>
<?php if ($l['regras'] !== ''): ?>
<p class="font-label-sm text-label-sm text-secondary truncate"><?= h($l['regras']) ?></p>
<?php endif; ?>
<?php if ($l['turnos_usados'] !== ''): ?>
<p class="font-label-sm text-label-sm text-secondary truncate">cumpriu: <?= h($l['turnos_usados']) ?></p>
<?php endif; ?>
</div>
</div>

<div class="grid grid-cols-3 gap-1 py-2 border-y border-outline-variant/25">
<div class="flex flex-col items-center">
<span class="font-label-sm text-label-sm text-secondary">Trabalhadas</span>
<span class="font-headline-md text-headline-md text-on-surface"><?= h(fmt_minutes($l['trabalho_min'])) ?></span>
</div>
<div class="flex flex-col items-center border-l border-outline-variant/25">
<span class="font-label-sm text-label-sm text-secondary">Atrasos</span>
<span class="font-body-lg text-body-lg font-semibold text-error"><?= h(fmt_minutes($l['atrasos_min'])) ?></span>
<?php if ($l['dias_atrasados']): ?><span class="font-label-sm text-label-sm text-error"><?= (int)$l['dias_atrasados'] ?> dia(s)</span><?php endif; ?>
</div>
<div class="flex flex-col items-center border-l border-outline-variant/25">
<span class="font-label-sm text-label-sm text-secondary">Faltas</span>
<span class="font-body-lg text-body-lg font-semibold <?= $l['faltas'] ? 'text-error' : 'text-on-surface' ?>"><?= (int)$l['faltas'] ?></span>
<?php if ($l['faltas']): ?><span class="font-label-sm text-label-sm text-error truncate" style="max-width:100%"><?= h(implode(', ', array_map(function ($d) { return date('d/m', strtotime($d)); }, array_slice($l['faltas_dias'], 0, 6)))) ?><?= count($l['faltas_dias']) > 6 ? '…' : '' ?></span><?php endif; ?>
</div>
</div>

<div>
<div class="flex justify-between font-label-sm text-label-sm mb-1 text-secondary">
<span><?= (int)$l['dias'] ?> dia(s) · esperado <?= h(fmt_minutes($l['esperado_min'])) ?></span>
<span><?= (int)$l['percentual'] ?>%</span>
</div>
<div class="w-full h-1.5 bg-surface-container rounded-full overflow-hidden">
<div class="h-full <?= $corBarra ?> rounded-full" style="width: <?= min(100, (int)$l['percentual']) ?>%"></div>
</div>
</div>
</article>
<?php endforeach; ?>
</section>

<p class="font-label-sm text-label-sm text-secondary text-center px-2">
Atraso é <b>o que faltou para fechar a jornada</b> do cargo (pega chegar tarde e sair mais cedo).
Trabalhar além da jornada não gera crédito.
Cargo sem horário cai no padrão de <?= (int)(PONTO_JORNADA_PADRAO_MINUTOS / 60) ?>h por dia.
Cada cargo tem as suas tolerâncias (Configurações &rarr; Cargos): chegar atrasado dentro da tolerância não conta;
o app não deixa bater a entrada antes do limite do cargo (batida lançada à mão antes do limite não soma horas).
<b>Falta</b> é dia sem nenhuma batida; domingo e feriado são folga (se trabalhar, conta como dia normal).
Cargo com "não conta falta" (escala 12x36) fica fora dessa conta.
Dias em aberto não entram na conta.
</p>
</main>

<?php admin_nav('relatorios'); ?>
<?php app_mobile_footer($csrf); ?>
<script src="assets/espelho-pdf.js?v=1.3.0"></script>
<script>
(() => {
    'use strict';
    const { toast } = window.PontoApp;

    /* O espelho já vem montado com a página, então o PDF sai na hora. */
    const ESPELHO = <?= json_encode($espelho, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    const EMISSAO = <?= json_encode(date('d/m/Y H:i')) ?>;
    const EMISSAO_COMPLETA = <?= json_encode(date('d/m/Y H:i:s')) ?>;

    document.getElementById('periodo').addEventListener('change', (evento) => {
        document.getElementById('blocoDatas').classList.toggle('hidden', evento.target.value !== 'custom');
    });

    /* 'unico' = um arquivo com todos, uma página por pessoa (era isso que
     * faltava: o ZIP obrigava a abrir pasta por pasta para achar cada PDF).
     * 'zip' = um PDF por pessoa, todos na raiz do ZIP. */
    async function exportar(botao, modo) {
        try {
            const quantos = await window.EspelhoPDF.gerar({
                espelhos: ESPELHO.espelhos,
                periodo: ESPELHO.periodo,
                periodoArquivo: ESPELHO.periodo_arquivo,
                emissao: EMISSAO,
                emissaoCompleta: EMISSAO_COMPLETA,
                modo,
                botao
            });
            if (quantos === 1) toast('PDF gerado.');
            else if (modo === 'zip') toast(quantos + ' PDFs baixados em ZIP.');
            else toast('PDF com ' + quantos + ' funcionários gerado.');
        } catch (erro) {
            toast(erro.message || 'Não foi possível gerar o PDF.', 'error');
        }
    }

    document.getElementById('btnPdf')
        .addEventListener('click', (evento) => exportar(evento.currentTarget, 'unico'));
    /* O botão do ZIP só existe quando há mais de um funcionário no filtro. */
    const btnZip = document.getElementById('btnPdfZip');
    if (btnZip) {
        btnZip.addEventListener('click', (evento) => exportar(evento.currentTarget, 'zip'));
    }
})();
</script>
<?php app_mobile_end(); ?>
