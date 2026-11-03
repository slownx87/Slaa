<?php
/*
 * Painel administrativo - configurações e cadastros.
 *
 * Condomínios, cargos, administradores e advertências.
 */

require_once __DIR__ . '/_config.php';
require_once __DIR__ . '/_admin_shell.php';
require_admin();

try { ensure_advertencias_table(); } catch (Throwable $e) { /* aparece no ajax */ }

/* A tabela de cargos original não tem salário; o painel novo pede. A coluna é
 * criada uma vez e depois só usada. */
function ensure_cargo_salario(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        if (!column_exists('ponto_cargos', 'salario_base')) {
            db()->exec("ALTER TABLE ponto_cargos ADD COLUMN salario_base DECIMAL(10,2) NULL AFTER nome");
        }
    } catch (Throwable $e) {
        // segue sem salário: o campo simplesmente não aparece
    }
}
try { ensure_cargo_salario(); } catch (Throwable $e) { /* idem */ }
try { ensure_cargo_horarios(); } catch (Throwable $e) { /* idem */ }
try { ensure_cargo_turnos_table(); } catch (Throwable $e) { /* idem */ }
try { ensure_feriados_table(); } catch (Throwable $e) { /* idem */ }

/** Lê e valida os campos de horário do formulário de cargo. */
function admin_horario_do_post(): array
{
    $entrada = trim((string)($_POST['hora_entrada'] ?? ''));
    $saida = trim((string)($_POST['hora_saida'] ?? ''));
    $hhmm = '/^([01]\d|2[0-3]):[0-5]\d$/';

    /* Os dois vazios = cargo sem horário fixo (volta para a regra automática). */
    if ($entrada === '' && $saida === '') {
        $entrada = $saida = null;
    } else {
        if (!preg_match($hhmm, $entrada) || !preg_match($hhmm, $saida)) {
            throw new RuntimeException('Preencha entrada e saída no formato 00:00, ou deixe os dois em branco.');
        }
        if ($entrada === $saida) {
            throw new RuntimeException('A entrada e a saída não podem ser no mesmo horário.');
        }
    }

    $intervalo = max(0, min(480, (int)($_POST['minutos_intervalo'] ?? 60)));

    /* Atraso tolerado: campo vazio = SEM tolerância (0). Só cai no padrão de 15
     * quando o campo nem veio no envio. */
    if (!array_key_exists('tolerancia_minutos', $_POST)) {
        $tolerancia = 15;
    } else {
        $bruto = trim((string)$_POST['tolerancia_minutos']);
        if ($bruto !== '' && !ctype_digit($bruto)) {
            throw new RuntimeException('Atraso tolerado: use só números (minutos), ou deixe em branco para não ter tolerância.');
        }
        $tolerancia = $bruto === '' ? 0 : min(180, (int)$bruto);
    }

    /* Chegada antecipada: campo que nem veio = padrão (10 min); campo vazio =
     * SEM limite (NULL). */
    if (!array_key_exists('antecipacao_minutos', $_POST)) {
        $antecipacao = PONTO_ANTECIPACAO_PADRAO_MIN;
    } else {
        $brutoAnt = trim((string)$_POST['antecipacao_minutos']);
        if ($brutoAnt !== '' && !ctype_digit($brutoAnt)) {
            throw new RuntimeException('Chegada antecipada: use só números (minutos), ou deixe em branco para não ter limite.');
        }
        $antecipacao = $brutoAnt === '' ? null : min(240, (int)$brutoAnt);
    }

    return [$entrada, $saida, $intervalo, $tolerancia, $antecipacao];
}

/** "Dia sem batida conta como falta" do formulário: 1/0, ou null se o campo nem veio. */
function admin_controla_falta_do_post(): ?int
{
    if (!array_key_exists('controla_falta', $_POST)) {
        return null;
    }
    return ((string)$_POST['controla_falta'] === '0') ? 0 : 1;
}

$soSuperAdmin = (admin_nivel() === 'super_admin');
/* Cargo vale para TODOS os condomínios (a tabela não tem condomínio). Por isso só
 * quem enxerga tudo mexe em cargo/horário/tolerância; antes qualquer admin de um
 * condomínio podia mudar o horário de todos os outros. */
$podeCargos = admin_eh_global();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    try {
        check_csrf();
        $acao = (string)($_POST['acao'] ?? '');

        /* ---------------------------- condomínios ---------------------------- */
        if ($acao === 'criar_condominio') {
            if (!$soSuperAdmin) throw new RuntimeException('Só o super admin cadastra condomínio.');
            $nome = trim((string)($_POST['nome'] ?? ''));
            if (mb_strlen($nome) < 3) throw new RuntimeException('Informe o nome do condomínio.');
            db()->prepare("INSERT INTO ponto_condominios (nome, endereco, ativo) VALUES (?, ?, 1)")
                ->execute([$nome, trim((string)($_POST['endereco'] ?? ''))]);
            log_action('criar_condominio', 'Cadastrou condomínio: ' . $nome, null, (int)db()->lastInsertId());
            admin_json(['ok' => true, 'mensagem' => $nome . ' cadastrado.']);
        }

        if ($acao === 'status_condominio') {
            if (!$soSuperAdmin) throw new RuntimeException('Só o super admin altera condomínio.');
            $id = (int)($_POST['id'] ?? 0);
            db()->prepare("UPDATE ponto_condominios SET ativo = IF(ativo = 1, 0, 1) WHERE id = ?")->execute([$id]);
            log_action('alterar_status_condominio', 'Alterou status do condomínio ID ' . $id, null, $id);
            admin_json(['ok' => true, 'mensagem' => 'Status alterado.']);
        }

        /* ------------------------------- cargos ------------------------------- */
        if ($acao === 'criar_cargo') {
            if (!$podeCargos) throw new RuntimeException('Só o administrador geral cadastra cargo.');
            ensure_cargo_horarios();
            $nome = trim((string)($_POST['nome'] ?? ''));
            if (mb_strlen($nome) < 2) throw new RuntimeException('Informe o nome do cargo.');

            [$entrada, $saida, $intervalo, $tolerancia, $antecipacao] = admin_horario_do_post();
            $controlaFalta = admin_controla_falta_do_post() ?? 1;
            $salario = (string)($_POST['salario'] ?? '');
            $salario = $salario !== '' ? (float)str_replace(',', '.', $salario) : null;

            $temSalario = column_exists('ponto_cargos', 'salario_base');
            if ($temSalario) {
                db()->prepare("INSERT INTO ponto_cargos (nome, salario_base, hora_entrada, hora_saida, minutos_intervalo, tolerancia_minutos, antecipacao_minutos, controla_falta, ativo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)")
                    ->execute([$nome, $salario, $entrada, $saida, $intervalo, $tolerancia, $antecipacao, $controlaFalta]);
            } else {
                db()->prepare("INSERT INTO ponto_cargos (nome, hora_entrada, hora_saida, minutos_intervalo, tolerancia_minutos, antecipacao_minutos, controla_falta, ativo) VALUES (?, ?, ?, ?, ?, ?, ?, 1)")
                    ->execute([$nome, $entrada, $saida, $intervalo, $tolerancia, $antecipacao, $controlaFalta]);
            }
            log_action('criar_cargo', 'Cadastrou cargo: ' . $nome . ($entrada ? ' (' . $entrada . '-' . $saida . ')' : ''));
            admin_json(['ok' => true, 'mensagem' => $nome . ' cadastrado.']);
        }

        /* Salva o horário de um cargo que já existe. */
        if ($acao === 'horario_cargo') {
            if (!$podeCargos) throw new RuntimeException('Só o administrador geral altera o horário de um cargo.');
            ensure_cargo_horarios();
            $id = (int)($_POST['id'] ?? 0);
            $existe = db()->prepare("SELECT COUNT(*) FROM ponto_cargos WHERE id = ?");
            $existe->execute([$id]);
            if ((int)$existe->fetchColumn() < 1) throw new RuntimeException('Cargo não encontrado.');
            [$entrada, $saida, $intervalo, $tolerancia, $antecipacao] = admin_horario_do_post();

            db()->prepare("UPDATE ponto_cargos SET hora_entrada = ?, hora_saida = ?, minutos_intervalo = ?, tolerancia_minutos = ?, antecipacao_minutos = ? WHERE id = ?")
                ->execute([$entrada, $saida, $intervalo, $tolerancia, $antecipacao, $id]);
            $controlaFalta = admin_controla_falta_do_post();
            if ($controlaFalta !== null) {
                db()->prepare("UPDATE ponto_cargos SET controla_falta = ? WHERE id = ?")->execute([$controlaFalta, $id]);
            }

            $jornada = ponto_jornada_do_cargo([
                'hora_entrada' => $entrada, 'hora_saida' => $saida,
                'minutos_intervalo' => $intervalo, 'tolerancia_minutos' => $tolerancia,
                'antecipacao_minutos' => $antecipacao,
                'controla_falta' => $controlaFalta ?? 1,
            ]);
            log_action('horario_cargo', 'Definiu horário do cargo ID ' . $id . ': ' . ($entrada ?: 'sem horário')
                . ' · ' . ponto_tolerancias_texto($jornada));
            admin_json([
                'ok' => true,
                'mensagem' => $jornada['definido']
                    ? 'Horário salvo. Jornada de ' . ponto_fmt_hm($jornada['jornada_min']) . ' por dia (' . ponto_tolerancias_texto($jornada) . ').'
                    : 'Horário removido: esse cargo volta para a regra automática.',
            ]);
        }

        /* Horário adicional: o cargo aceita entrar nesse horário também, sem
         * contar atraso. Serve para escala e revezamento. */
        if ($acao === 'criar_turno') {
            if (!$podeCargos) throw new RuntimeException('Só o administrador geral altera o horário de um cargo.');
            ensure_cargo_horarios();
            ensure_cargo_turnos_table();
            $cargoId = (int)($_POST['cargo_id'] ?? 0);
            if ($cargoId <= 0) throw new RuntimeException('Cargo inválido.');

            $stmtCargo = db()->prepare("SELECT * FROM ponto_cargos WHERE id = ?");
            $stmtCargo->execute([$cargoId]);
            $cargoRow = $stmtCargo->fetch();
            if (!$cargoRow) throw new RuntimeException('Cargo não encontrado.');

            /* O horário adicional segue as mesmas tolerâncias do cargo, a menos
             * que o formulário mande outras. Antes era sempre 15 fixo. */
            if (!array_key_exists('tolerancia_minutos', $_POST)) {
                $_POST['tolerancia_minutos'] = (string)(int)($cargoRow['tolerancia_minutos'] ?? 15);
            }
            if (!array_key_exists('antecipacao_minutos', $_POST)) {
                $_POST['antecipacao_minutos'] = isset($cargoRow['antecipacao_minutos'])
                    ? (string)(int)$cargoRow['antecipacao_minutos']
                    : ''; // cargo sem limite => o horário extra também fica sem limite
            }
            [$entrada, $saida, $intervalo, $tolerancia, $antecipacao] = admin_horario_do_post();
            if ($entrada === null) {
                throw new RuntimeException('Preencha a entrada e a saída do horário adicional.');
            }

            /* Não vale repetir a entrada de outro horário — inclusive a do principal. */
            $stmtDup = db()->prepare("SELECT COUNT(*) FROM ponto_cargo_turnos WHERE cargo_id = ? AND hora_entrada = ?");
            $stmtDup->execute([$cargoId, $entrada]);
            $principal = ponto_hhmm_para_min((string)($cargoRow['hora_entrada'] ?? ''));
            if ((int)$stmtDup->fetchColumn() > 0 || $principal === ponto_hhmm_para_min($entrada)) {
                throw new RuntimeException('Esse cargo já tem um horário começando às ' . $entrada . '.');
            }

            db()->prepare("INSERT INTO ponto_cargo_turnos (cargo_id, hora_entrada, hora_saida, minutos_intervalo, tolerancia_minutos, antecipacao_minutos, descricao, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$cargoId, $entrada, $saida, $intervalo, $tolerancia, $antecipacao,
                    mb_substr(trim((string)($_POST['descricao'] ?? '')), 0, 60) ?: null, now_sp()]);

            $j = ponto_jornada_do_cargo(['hora_entrada' => $entrada, 'hora_saida' => $saida, 'minutos_intervalo' => $intervalo]);
            log_action('criar_turno_cargo', 'Horário adicional ' . $entrada . '-' . $saida . ' no cargo ID ' . $cargoId);
            admin_json(['ok' => true, 'mensagem' => 'Horário ' . $entrada . '-' . $saida . ' adicionado (jornada de ' . ponto_fmt_hm($j['jornada_min']) . ').']);
        }

        if ($acao === 'excluir_turno') {
            if (!$podeCargos) throw new RuntimeException('Só o administrador geral altera o horário de um cargo.');
            ensure_cargo_turnos_table();
            $id = (int)($_POST['id'] ?? 0);
            db()->prepare("DELETE FROM ponto_cargo_turnos WHERE id = ?")->execute([$id]);
            log_action('excluir_turno_cargo', 'Removeu horário adicional ID ' . $id);
            admin_json(['ok' => true, 'mensagem' => 'Horário removido.']);
        }

        /* ------------------------------ feriados ------------------------------ */
        if ($acao === 'criar_feriado') {
            if (!$podeCargos) throw new RuntimeException('Só o administrador geral cadastra feriado.');
            $data = trim((string)($_POST['data'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) || !strtotime($data)) {
                throw new RuntimeException('Informe a data do feriado.');
            }
            $nome = trim((string)($_POST['nome'] ?? ''));
            if (mb_strlen($nome) < 3) throw new RuntimeException('Dê um nome ao feriado (ex.: Aniversário de São Paulo).');
            $anual = ((string)($_POST['anual'] ?? '') === '1') ? 1 : 0;

            $dup = db()->prepare("SELECT COUNT(*) FROM ponto_feriados WHERE data = ?");
            $dup->execute([$data]);
            if ((int)$dup->fetchColumn() > 0) throw new RuntimeException('Já existe um feriado cadastrado nessa data.');
            if (isset(ponto_feriados_nacionais((int)substr($data, 0, 4))[$data])) {
                throw new RuntimeException('Esse já é feriado nacional e entra sozinho.');
            }

            db()->prepare("INSERT INTO ponto_feriados (data, nome, anual, criado_em) VALUES (?, ?, ?, ?)")
                ->execute([$data, mb_substr($nome, 0, 80), $anual, now_sp()]);
            log_action('criar_feriado', 'Cadastrou feriado ' . $data . ' - ' . $nome . ($anual ? ' (todo ano)' : ''));
            admin_json(['ok' => true, 'mensagem' => $nome . ' cadastrado. Dia sem batida nessa data deixa de ser falta.']);
        }

        if ($acao === 'excluir_feriado') {
            if (!$podeCargos) throw new RuntimeException('Só o administrador geral remove feriado.');
            $id = (int)($_POST['id'] ?? 0);
            db()->prepare("DELETE FROM ponto_feriados WHERE id = ?")->execute([$id]);
            log_action('excluir_feriado', 'Removeu feriado ID ' . $id);
            admin_json(['ok' => true, 'mensagem' => 'Feriado removido.']);
        }

        if ($acao === 'status_cargo') {
            if (!$podeCargos) throw new RuntimeException('Só o administrador geral ativa ou desativa um cargo.');
            $id = (int)($_POST['id'] ?? 0);
            db()->prepare("UPDATE ponto_cargos SET ativo = IF(ativo = 1, 0, 1) WHERE id = ?")->execute([$id]);
            log_action('alterar_status_cargo', 'Alterou status do cargo ID ' . $id);
            admin_json(['ok' => true, 'mensagem' => 'Status alterado.']);
        }

        /* ---------------------------- advertências ---------------------------- */
        if ($acao === 'criar_advertencia') {
            ensure_advertencias_table();
            $funcionarioId = (int)($_POST['funcionario_id'] ?? 0);
            $func = admin_funcionario_autorizado($funcionarioId);

            $data = (string)($_POST['data'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) throw new RuntimeException('Informe a data da advertência.');
            if (strtotime($data) > time() + 86400) throw new RuntimeException('A data não pode ser no futuro.');

            $tipo = (string)($_POST['tipo'] ?? 'escrita');
            if (!in_array($tipo, ['verbal', 'escrita', 'suspensao'], true)) $tipo = 'escrita';

            $motivo = trim((string)($_POST['motivo'] ?? ''));
            if (mb_strlen($motivo) < 10) throw new RuntimeException('Descreva o motivo com pelo menos 10 letras.');

            db()->prepare("INSERT INTO ponto_advertencias (funcionario_id, condominio_id, data_advertencia, tipo, motivo, admin_id, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?)")
                ->execute([$funcionarioId, (int)$func['condominio_id'], $data, $tipo, mb_substr($motivo, 0, 1000), admin_id(), now_sp()]);
            log_action('criar_advertencia', ponto_advertencia_tipo_label($tipo) . ' para ' . $func['nome'], $funcionarioId, (int)$func['condominio_id']);
            admin_json(['ok' => true, 'mensagem' => ponto_advertencia_tipo_label($tipo) . ' registrada para ' . $func['nome'] . '.']);
        }

        if ($acao === 'excluir_advertencia') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = db()->prepare("SELECT * FROM ponto_advertencias WHERE id = ?");
            $stmt->execute([$id]);
            $adv = $stmt->fetch();
            if (!$adv) throw new RuntimeException('Advertência não encontrada.');
            admin_funcionario_autorizado((int)$adv['funcionario_id']);

            db()->prepare("DELETE FROM ponto_advertencias WHERE id = ?")->execute([$id]);
            log_action('excluir_advertencia', 'Excluiu advertência ID ' . $id, (int)$adv['funcionario_id'], (int)$adv['condominio_id']);
            admin_json(['ok' => true, 'mensagem' => 'Advertência removida.']);
        }

        /* ------------------------------- admins ------------------------------- */
        if ($acao === 'criar_admin') {
            if (!$soSuperAdmin) throw new RuntimeException('Só o super admin cadastra administradores.');
            $nome = trim((string)($_POST['nome'] ?? ''));
            $usuario = trim((string)($_POST['usuario'] ?? ''));
            $senha = (string)($_POST['senha'] ?? '');
            $nivel = (string)($_POST['nivel'] ?? 'admin');
            $condominioId = (int)($_POST['condominio_id'] ?? 0);

            if (mb_strlen($nome) < 3) throw new RuntimeException('Informe o nome do administrador.');
            if (mb_strlen($usuario) < 3) throw new RuntimeException('Informe o usuário de acesso.');
            if (mb_strlen($senha) < 6) throw new RuntimeException('A senha precisa de pelo menos 6 caracteres.');
            if (!in_array($nivel, ['admin', 'super_admin'], true)) $nivel = 'admin';
            /* Admin de condomínio SEM condomínio seria tratado como acesso a tudo
             * (o sistema entende "sem condomínio" como administrador geral). */
            if ($nivel !== 'super_admin') {
                $stCond = db()->prepare("SELECT COUNT(*) FROM ponto_condominios WHERE id = ? AND ativo = 1");
                $stCond->execute([$condominioId]);
                if ($condominioId <= 0 || (int)$stCond->fetchColumn() < 1) {
                    throw new RuntimeException('Escolha o condomínio desse administrador (ele só enxerga o condomínio escolhido).');
                }
            }

            $stmt = db()->prepare("SELECT COUNT(*) FROM ponto_admins WHERE usuario = ?");
            $stmt->execute([$usuario]);
            if ((int)$stmt->fetchColumn() > 0) throw new RuntimeException('Esse usuário já existe.');

            db()->prepare("INSERT INTO ponto_admins (nome, usuario, senha_hash, nivel, condominio_id, ativo) VALUES (?, ?, ?, ?, ?, 1)")
                ->execute([$nome, $usuario, password_hash($senha, PASSWORD_DEFAULT), $nivel, $nivel === 'super_admin' ? null : ($condominioId ?: null)]);
            log_action('criar_admin', 'Cadastrou administrador: ' . $nome);
            admin_json(['ok' => true, 'mensagem' => $nome . ' cadastrado como administrador.']);
        }

        throw new RuntimeException('Ação desconhecida.');
    } catch (Throwable $e) {
        admin_json(['ok' => false, 'error' => ponto_msg_erro($e)], 422);
    }
}

$feriadosExtras = ponto_feriados_extras();
$feriadosNacionaisAno = ponto_feriados_nacionais((int)date('Y'));
$condominios = db()->query("SELECT * FROM ponto_condominios ORDER BY ativo DESC, nome")->fetchAll();
$cargos = db()->query("SELECT * FROM ponto_cargos ORDER BY ativo DESC, nome")->fetchAll();
$temSalario = column_exists('ponto_cargos', 'salario_base');

[$filtro, $params] = admin_filtro_condominio('f');
$stmt = db()->prepare("SELECT f.id, f.nome FROM ponto_funcionarios f WHERE f.ativo = 1 {$filtro} ORDER BY f.nome");
$stmt->execute($params);
$funcionarios = $stmt->fetchAll();

[$filtroA, $paramsA] = admin_filtro_condominio('a');
$stmt = db()->prepare("SELECT a.*, f.nome AS funcionario_nome FROM ponto_advertencias a
    INNER JOIN ponto_funcionarios f ON f.id = a.funcionario_id
    WHERE 1=1 {$filtroA} ORDER BY a.data_advertencia DESC, a.id DESC LIMIT 40");
$stmt->execute($paramsA);
$advertencias = $stmt->fetchAll();

$csrf = csrf_token();
app_mobile_head('Configurações - ' . APP_NAME, 'bg-background text-on-background antialiased min-h-screen', admin_css(), 'manifest-admin.webmanifest');
admin_topbar(false);
?>
<main class="pt-[70px] pb-24 px-container-margin max-w-md mx-auto w-full flex flex-col gap-stack-gap-lg">

<div class="flex flex-col gap-1 fade-in-up">
<h1 class="font-headline-md text-headline-md text-on-surface">Configurações</h1>
<p class="font-body-md text-body-md text-secondary">Condomínios, cargos, advertências e acessos.</p>
</div>

<!-- ------------------------------ condomínios ------------------------------ -->
<section class="flex flex-col gap-2" id="condominios">
<h2 class="font-headline-md text-headline-md text-primary">Condomínios</h2>

<?php if ($soSuperAdmin): ?>
<div class="cartao rounded-xl p-3 flex flex-col gap-2">
<span class="font-label-bold text-label-bold text-secondary uppercase tracking-wider">Novo condomínio</span>
<input class="campo font-body-lg text-body-lg" id="cdNome" maxlength="120" placeholder="Nome" type="text"/>
<input class="campo font-body-lg text-body-lg" id="cdEndereco" maxlength="200" placeholder="Endereço (opcional)" type="text"/>
<button class="h-[46px] rounded-full bg-primary text-on-primary font-label-bold text-label-bold active:scale-95 transition-transform" data-criar="condominio" type="button">Adicionar</button>
</div>
<?php endif; ?>

<div class="flex flex-col gap-2">
<?php foreach ($condominios as $c): $on = (int)$c['ativo'] === 1; ?>
<div class="cartao rounded-lg p-2.5 flex items-center justify-between gap-2 <?= $on ? '' : 'opacity-60' ?>">
<div class="flex items-center gap-2 min-w-0">
<div class="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center text-primary flex-shrink-0">
<span class="material-symbols-outlined text-[18px]">apartment</span>
</div>
<div class="min-w-0">
<p class="font-body-lg text-body-lg text-on-surface truncate"><?= h((string)$c['nome']) ?></p>
<p class="font-label-sm text-label-sm text-secondary truncate"><?= h((string)($c['endereco'] ?? '')) ?: '&nbsp;' ?></p>
</div>
</div>
<?php if ($soSuperAdmin): ?>
<button class="w-8 h-8 rounded-full flex items-center justify-center <?= $on ? 'text-amber-500' : 'text-green-500' ?>" data-status="condominio" data-id="<?= (int)$c['id'] ?>" type="button" aria-label="Ativar ou desativar">
<span class="material-symbols-outlined text-[18px]"><?= $on ? 'toggle_on' : 'toggle_off' ?></span>
</button>
<?php endif; ?>
</div>
<?php endforeach; ?>
</div>
</section>

<!-- --------------------------------- cargos --------------------------------- -->
<section class="flex flex-col gap-2" id="cargos">
<h2 class="font-headline-md text-headline-md text-primary">Cargos</h2>

<?php if ($podeCargos): ?>
<div class="cartao rounded-xl p-3 flex flex-col gap-2">
<span class="font-label-bold text-label-bold text-secondary uppercase tracking-wider">Novo cargo</span>
<input class="campo font-body-lg text-body-lg" id="cgNome" maxlength="80" placeholder="Ex: Porteiro" type="text"/>
<?php if ($temSalario): ?>
<input class="campo font-body-lg text-body-lg" id="cgSalario" placeholder="Salário base (opcional)" step="0.01" type="number"/>
<?php endif; ?>

<span class="font-label-bold text-label-bold text-primary uppercase tracking-wider mt-1">Horário de trabalho</span>
<p class="font-label-sm text-label-sm text-secondary">É daqui que saem a hora extra e o atraso. Deixe em branco para o sistema deduzir sozinho.</p>
<div class="grid grid-cols-2 gap-2">
<div class="flex flex-col gap-1">
<label class="font-label-sm text-label-sm text-secondary" for="cgEntrada">Entrada</label>
<input class="campo font-body-lg text-body-lg text-center tabular-nums" id="cgEntrada" type="time"/>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-sm text-label-sm text-secondary" for="cgSaida">Saída</label>
<input class="campo font-body-lg text-body-lg text-center tabular-nums" id="cgSaida" type="time"/>
</div>
<div class="flex flex-col gap-1 col-span-2">
<label class="font-label-sm text-label-sm text-secondary" for="cgIntervalo">Intervalo (min)</label>
<input class="campo font-body-md text-body-md text-center" id="cgIntervalo" max="480" min="0" type="number" value="60"/>
</div>
</div>

<span class="font-label-bold text-label-bold text-primary uppercase tracking-wider mt-1">Tolerâncias de chegada</span>
<p class="font-label-sm text-label-sm text-secondary">Opcional. <b>Pode chegar atrasado até</b>: depois da entrada, esses minutos não contam como atraso (passou disso, conta tudo). <b>Pode chegar antes até</b>: quem tentar bater a entrada mais cedo que isso recebe um aviso e o app <b>não deixa</b> bater. Em branco = sem tolerância / sem limite. O padrão de "antes" é <b><?= (int)PONTO_ANTECIPACAO_PADRAO_MIN ?> min</b>.</p>
<div class="grid grid-cols-2 gap-2">
<div class="flex flex-col gap-1">
<label class="font-label-sm text-label-sm text-secondary" for="cgTolerancia">Atrasado até (min)</label>
<input class="campo font-body-md text-body-md text-center" id="cgTolerancia" inputmode="numeric" max="180" min="0" placeholder="sem tolerância" type="number" value="15"/>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-sm text-label-sm text-secondary" for="cgAntecipacao">Antes até (min)</label>
<input class="campo font-body-md text-body-md text-center" id="cgAntecipacao" inputmode="numeric" max="240" min="0" placeholder="sem limite" type="number" value="<?= (int)PONTO_ANTECIPACAO_PADRAO_MIN ?>"/>
</div>
</div>
<p class="font-label-sm text-label-sm text-primary" id="cgPreview"></p>

<label class="flex items-start gap-2 mt-1">
<input checked class="w-5 h-5 flex-shrink-0" id="cgFalta" type="checkbox"/>
<span class="font-label-sm text-label-sm text-secondary"><b>Dia sem nenhuma batida conta como falta</b> (domingo e feriado são folga). Desligue para escala 12x36 / revezamento, em que a folga varia.</span>
</label>

<button class="h-[46px] rounded-full border border-primary text-primary font-label-bold text-label-bold active:scale-95 transition-transform" data-criar="cargo" type="button">Criar cargo</button>
</div>
<?php else: ?>
<p class="font-label-sm text-label-sm text-secondary">Os cargos, horários e tolerâncias valem para todos os condomínios, por isso só o administrador geral altera. Aqui você só consulta.</p>
<?php endif; ?>

<div class="flex flex-col gap-2">
<?php foreach ($cargos as $c):
    $on = (int)$c['ativo'] === 1;
    $j = ponto_jornada_do_cargo($c); ?>
<div class="cartao rounded-lg p-2.5 flex flex-col gap-2 <?= $on ? '' : 'opacity-60' ?>">
<div class="flex items-center justify-between gap-2">
<div class="flex items-center gap-2 min-w-0">
<span class="material-symbols-outlined text-primary text-[18px]">badge</span>
<div class="min-w-0">
<p class="font-body-lg text-body-lg text-on-surface truncate"><?= h((string)$c['nome']) ?></p>
<p class="font-label-sm text-label-sm <?= $j['definido'] ? 'text-primary' : 'text-error' ?>">
<?php if ($j['definido']): ?>
<?= h($j['entrada']) ?> às <?= h($j['saida']) ?> · jornada <?= h(ponto_fmt_hm($j['jornada_min'])) ?>
<?php else: ?>
sem horário — hora extra pelo padrão de 8h
<?php endif; ?>
</p>
<p class="font-label-sm text-label-sm text-secondary"><?= h(ponto_tolerancias_texto($j)) ?></p>
<?php if ($temSalario && !empty($c['salario_base'])): ?>
<p class="font-label-sm text-label-sm text-secondary">R$ <?= h(number_format((float)$c['salario_base'], 2, ',', '.')) ?></p>
<?php endif; ?>
</div>
</div>
<?php if ($podeCargos): ?>
<div class="flex gap-1 flex-shrink-0">
<button class="w-8 h-8 rounded-full flex items-center justify-center text-primary" data-horario="<?= (int)$c['id'] ?>" type="button" aria-label="Definir horário e tolerâncias">
<span class="material-symbols-outlined text-[18px]">schedule</span>
</button>
<button class="w-8 h-8 rounded-full flex items-center justify-center <?= $on ? 'text-amber-500' : 'text-green-500' ?>" data-status="cargo" data-id="<?= (int)$c['id'] ?>" type="button" aria-label="Ativar ou desativar">
<span class="material-symbols-outlined text-[18px]"><?= $on ? 'toggle_on' : 'toggle_off' ?></span>
</button>
</div>
<?php endif; ?>
</div>

<?php if ($podeCargos): ?>
<div class="hidden flex-col gap-2 border-t border-outline-variant/30 pt-2" data-painel-horario="<?= (int)$c['id'] ?>">
<div class="grid grid-cols-2 gap-2">
<div class="flex flex-col gap-1">
<label class="font-label-sm text-label-sm text-secondary">Entrada</label>
<input class="campo font-body-md text-body-md text-center tabular-nums" data-campo="entrada" type="time" value="<?= h($j['entrada']) ?>"/>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-sm text-label-sm text-secondary">Saída</label>
<input class="campo font-body-md text-body-md text-center tabular-nums" data-campo="saida" type="time" value="<?= h($j['saida']) ?>"/>
</div>
<div class="flex flex-col gap-1 col-span-2">
<label class="font-label-sm text-label-sm text-secondary">Intervalo (min)</label>
<input class="campo font-body-md text-body-md text-center" data-campo="intervalo" max="480" min="0" type="number" value="<?= (int)$j['intervalo_min'] ?>"/>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-sm text-label-sm text-secondary">Atrasado até (min)</label>
<input class="campo font-body-md text-body-md text-center" data-campo="tolerancia" inputmode="numeric" max="180" min="0" placeholder="sem tolerância" type="number" value="<?= (int)($c['tolerancia_minutos'] ?? 15) > 0 ? (int)$c['tolerancia_minutos'] : '' ?>"/>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-sm text-label-sm text-secondary">Antes até (min)</label>
<input class="campo font-body-md text-body-md text-center" data-campo="antecipacao" inputmode="numeric" max="240" min="0" placeholder="sem limite" type="number" value="<?= isset($c['antecipacao_minutos']) && $c['antecipacao_minutos'] !== null ? (int)$c['antecipacao_minutos'] : '' ?>"/>
</div>
</div>
<p class="font-label-sm text-label-sm text-secondary"><b>Atrasado até</b>: minutos depois da entrada que não contam como atraso (passou disso, conta tudo). <b>Antes até</b>: quem tentar bater a entrada mais cedo que isso recebe um aviso e o app <b>não deixa</b> bater. Em branco = sem tolerância / sem limite. O limite de "antes" só funciona com entrada e saída preenchidas.</p>
<p class="font-label-sm text-label-sm text-primary" data-preview="<?= (int)$c['id'] ?>"></p>
<label class="flex items-start gap-2">
<input <?= (int)($c['controla_falta'] ?? 1) === 1 ? 'checked' : '' ?> class="w-5 h-5 flex-shrink-0" data-campo="falta" type="checkbox"/>
<span class="font-label-sm text-label-sm text-secondary"><b>Dia sem nenhuma batida conta como falta</b> (domingo e feriado são folga). Desligue para escala 12x36 / revezamento.</span>
</label>
<button class="h-[40px] rounded-full bg-primary text-on-primary font-label-bold text-label-bold" data-salvar-horario="<?= (int)$c['id'] ?>" type="button">Salvar horário e tolerâncias</button>

<div class="border-t border-outline-variant/30 pt-2 flex flex-col gap-2">
<span class="font-label-bold text-label-bold text-secondary uppercase tracking-wider">Outros horários aceitos</span>
<p class="font-label-sm text-label-sm text-secondary">Quem faz escala pode entrar em qualquer um destes sem contar atraso. Cada horário novo segue as tolerâncias do cargo.</p>

<?php $extras = ponto_turnos_extras_do_cargo((int)$c['id']); ?>
<?php foreach ($extras as $t): $je = ponto_jornada_do_cargo($t); ?>
<div class="flex items-center justify-between gap-2 rounded-lg border border-outline-variant/40 p-2">
<div class="min-w-0">
<p class="font-body-md text-body-md text-on-surface"><?= h($je['entrada']) ?> às <?= h($je['saida']) ?> · <?= h(ponto_fmt_hm($je['jornada_min'])) ?></p>
<p class="font-label-sm text-label-sm text-secondary"><?= h(ponto_tolerancias_texto($je)) ?></p>
<?php if (!empty($t['descricao'])): ?><p class="font-label-sm text-label-sm text-secondary"><?= h((string)$t['descricao']) ?></p><?php endif; ?>
</div>
<button class="w-7 h-7 rounded-full flex items-center justify-center text-error flex-shrink-0" data-excluir-turno="<?= (int)$t['id'] ?>" type="button" aria-label="Remover">
<span class="material-symbols-outlined text-[16px]">close</span>
</button>
</div>
<?php endforeach; ?>

<div class="grid grid-cols-2 gap-2" data-novo-turno="<?= (int)$c['id'] ?>">
<input class="campo font-body-md text-body-md text-center tabular-nums" data-campo="entrada" placeholder="Entrada" type="time"/>
<input class="campo font-body-md text-body-md text-center tabular-nums" data-campo="saida" placeholder="Saída" type="time"/>
<input class="campo font-body-md text-body-md text-center" data-campo="intervalo" max="480" min="0" placeholder="Intervalo" type="number" value="60"/>
<input class="campo font-body-md text-body-md" data-campo="descricao" maxlength="60" placeholder="Apelido (opcional)" type="text"/>
</div>
<button class="h-[38px] rounded-full border border-primary text-primary font-label-bold text-label-bold" data-add-turno="<?= (int)$c['id'] ?>" type="button">+ Adicionar horário</button>
</div>
</div>
<?php endif; ?>
</div>
<?php endforeach; ?>
</div>
</section>

<!-- -------------------------------- feriados -------------------------------- -->
<section class="flex flex-col gap-2" id="feriados">
<h2 class="font-headline-md text-headline-md text-primary">Feriados</h2>
<p class="font-body-md text-body-md text-secondary">Dia sem nenhuma batida é <b>falta</b>, menos domingo e feriado (folga). Se a pessoa trabalhar no feriado, conta como dia normal.</p>
<p class="font-label-sm text-label-sm text-secondary">Os feriados nacionais já entram sozinhos em <?= (int)date('Y') ?>: <?= h(implode(' · ', array_map(function ($d, $n) { return date('d/m', strtotime($d)) . ' ' . $n; }, array_keys($feriadosNacionaisAno), $feriadosNacionaisAno))) ?>. Cadastre abaixo os municipais/estaduais (ex.: 25/01 aniversário de São Paulo, 09/07) e os pontos facultativos em que a empresa não trabalha (Carnaval, Corpus Christi).</p>

<?php if ($podeCargos): ?>
<div class="cartao rounded-xl p-3 flex flex-col gap-2">
<span class="font-label-bold text-label-bold text-secondary uppercase tracking-wider">Novo feriado</span>
<input class="campo font-body-lg text-body-lg" id="fdData" type="date"/>
<input class="campo font-body-lg text-body-lg" id="fdNome" maxlength="80" placeholder="Nome (ex.: Corpus Christi)" type="text"/>
<label class="flex items-center gap-2">
<input class="w-5 h-5 flex-shrink-0" id="fdAnual" type="checkbox"/>
<span class="font-label-sm text-label-sm text-secondary">Repete todo ano na mesma data</span>
</label>
<button class="h-[46px] rounded-full bg-primary text-on-primary font-label-bold text-label-bold active:scale-95 transition-transform" data-criar="feriado" type="button">Adicionar feriado</button>
</div>
<?php endif; ?>

<div class="flex flex-col gap-2">
<?php foreach ($feriadosExtras as $fe): ?>
<div class="cartao rounded-lg p-2.5 flex items-center justify-between gap-2">
<div class="min-w-0">
<p class="font-body-lg text-body-lg text-on-surface truncate"><?= h((string)$fe['nome']) ?></p>
<p class="font-label-sm text-label-sm text-secondary"><?= h(date('d/m/Y', strtotime((string)$fe['data']))) ?><?= (int)$fe['anual'] === 1 ? ' · todo ano' : '' ?></p>
</div>
<?php if ($podeCargos): ?>
<button class="w-8 h-8 rounded-full flex items-center justify-center text-error flex-shrink-0" data-excluir-feriado="<?= (int)$fe['id'] ?>" type="button" aria-label="Remover feriado">
<span class="material-symbols-outlined text-[18px]">delete</span>
</button>
<?php endif; ?>
</div>
<?php endforeach; ?>
<?php if (!$feriadosExtras): ?>
<p class="font-body-md text-body-md text-secondary text-center py-2">Nenhum feriado extra cadastrado.</p>
<?php endif; ?>
</div>
</section>

<!-- ------------------------------ advertências ------------------------------ -->
<section class="flex flex-col gap-2" id="advertencias">
<h2 class="font-headline-md text-headline-md text-primary">Advertências</h2>
<p class="font-body-md text-body-md text-secondary">O funcionário vê a contagem e o motivo no histórico dele.</p>

<div class="cartao rounded-xl p-3 flex flex-col gap-2">
<span class="font-label-bold text-label-bold text-secondary uppercase tracking-wider">Registrar advertência</span>
<select class="campo font-body-lg text-body-lg" id="advFuncionario">
<option value="">Escolha o funcionário...</option>
<?php foreach ($funcionarios as $f): ?>
<option value="<?= (int)$f['id'] ?>"><?= h((string)$f['nome']) ?></option>
<?php endforeach; ?>
</select>
<div class="grid grid-cols-2 gap-2">
<select class="campo font-body-md text-body-md" id="advTipo">
<option value="verbal">Verbal</option>
<option selected value="escrita">Escrita</option>
<option value="suspensao">Suspensão</option>
</select>
<input class="campo font-body-md text-body-md" id="advData" max="<?= h(today_sp()) ?>" type="date" value="<?= h(today_sp()) ?>"/>
</div>
<textarea class="campo font-body-md text-body-md resize-none" id="advMotivo" maxlength="1000" placeholder="Motivo da advertência" rows="3"></textarea>
<button class="h-[46px] rounded-full bg-amber-500 text-on-background font-label-bold text-label-bold active:scale-95 transition-transform" data-criar="advertencia" type="button">Registrar</button>
</div>

<div class="flex flex-col gap-2" id="listaAdvertencias">
<?php foreach ($advertencias as $a): ?>
<div class="cartao rounded-lg p-2.5 flex items-start justify-between gap-2 border-amber-500/25" data-adv="<?= (int)$a['id'] ?>">
<div class="flex items-start gap-2 min-w-0">
<span class="material-symbols-outlined text-amber-500 text-[18px] flex-shrink-0 mt-0.5">gavel</span>
<div class="min-w-0">
<p class="font-body-lg text-body-lg text-on-surface truncate"><?= h((string)$a['funcionario_nome']) ?></p>
<p class="font-label-sm text-label-sm text-amber-500 uppercase"><?= h(ponto_advertencia_tipo_label((string)$a['tipo'])) ?> · <?= h(date('d/m/Y', strtotime((string)$a['data_advertencia']))) ?></p>
<p class="font-body-md text-body-md text-on-surface-variant"><?= h((string)$a['motivo']) ?></p>
</div>
</div>
<button class="w-8 h-8 rounded-full flex items-center justify-center text-error flex-shrink-0" data-excluir-adv="<?= (int)$a['id'] ?>" type="button" aria-label="Remover">
<span class="material-symbols-outlined text-[18px]">delete</span>
</button>
</div>
<?php endforeach; ?>
<?php if (!$advertencias): ?>
<p class="font-body-md text-body-md text-secondary text-center py-3">Nenhuma advertência registrada.</p>
<?php endif; ?>
</div>
</section>

<!-- ------------------------------ administradores ------------------------------ -->
<?php if ($soSuperAdmin): ?>
<section class="flex flex-col gap-2" id="admins">
<h2 class="font-headline-md text-headline-md text-primary">Administradores</h2>
<div class="cartao rounded-xl p-3 flex flex-col gap-2">
<span class="font-label-bold text-label-bold text-secondary uppercase tracking-wider">Novo administrador</span>
<input class="campo font-body-lg text-body-lg" id="adNome" maxlength="120" placeholder="Nome" type="text"/>
<input class="campo font-body-lg text-body-lg" autocomplete="off" id="adUsuario" maxlength="60" placeholder="Usuário de acesso" type="text"/>
<input class="campo font-body-lg text-body-lg" autocomplete="new-password" id="adSenha" placeholder="Senha (mínimo 6)" type="password"/>
<div class="grid grid-cols-2 gap-2">
<select class="campo font-body-md text-body-md" id="adNivel">
<option value="admin">Admin do condomínio</option>
<option value="super_admin">Super admin</option>
</select>
<select class="campo font-body-md text-body-md" id="adCondominio">
<?php foreach ($condominios as $c): if ((int)$c['ativo'] !== 1) continue; ?>
<option value="<?= (int)$c['id'] ?>"><?= h((string)$c['nome']) ?></option>
<?php endforeach; ?>
</select>
</div>
<button class="h-[46px] rounded-full bg-primary text-on-primary font-label-bold text-label-bold active:scale-95 transition-transform" data-criar="admin" type="button">Cadastrar</button>
</div>
</section>
<?php endif; ?>

<div class="flex flex-col gap-1 pt-2">
<a class="font-label-bold text-label-bold text-secondary text-center py-2" href="diagnostico.php">Diagnóstico do sistema</a>
<a class="font-label-bold text-label-bold text-error text-center py-2" href="logout.php">Sair</a>
</div>
</main>

<?php admin_nav('painel'); ?>
<?php app_mobile_footer($csrf); ?>
<script>
(() => {
    'use strict';
    const $ = (sel) => document.querySelector(sel);
    const { toast, post, vibrar } = window.PontoApp;
    let ocupado = false;

    async function enviar(dados, recarregar) {
        if (ocupado) return;
        ocupado = true;
        try {
            const resposta = await post(dados);
            toast(resposta.mensagem);
            vibrar(40);
            if (recarregar) setTimeout(() => window.location.reload(), 700);
        } catch (erro) {
            toast(erro.message, 'error');
        } finally {
            ocupado = false;
        }
    }

    document.addEventListener('click', (evento) => {
        const criar = evento.target.closest('[data-criar]');
        if (criar) {
            const tipo = criar.dataset.criar;
            if (tipo === 'condominio') {
                enviar({ acao: 'criar_condominio', nome: $('#cdNome').value.trim(), endereco: $('#cdEndereco').value.trim() }, true);
            } else if (tipo === 'cargo') {
                enviar({
                    acao: 'criar_cargo',
                    nome: $('#cgNome').value.trim(),
                    salario: $('#cgSalario') ? $('#cgSalario').value : '',
                    hora_entrada: $('#cgEntrada').value,
                    hora_saida: $('#cgSaida').value,
                    minutos_intervalo: $('#cgIntervalo').value,
                    tolerancia_minutos: $('#cgTolerancia').value,
                    antecipacao_minutos: $('#cgAntecipacao').value,
                    controla_falta: $('#cgFalta').checked ? 1 : 0
                }, true);
            } else if (tipo === 'feriado') {
                enviar({
                    acao: 'criar_feriado',
                    data: $('#fdData').value,
                    nome: $('#fdNome').value.trim(),
                    anual: $('#fdAnual').checked ? 1 : 0
                }, true);
            } else if (tipo === 'advertencia') {
                enviar({
                    acao: 'criar_advertencia',
                    funcionario_id: $('#advFuncionario').value,
                    tipo: $('#advTipo').value,
                    data: $('#advData').value,
                    motivo: $('#advMotivo').value.trim()
                }, true);
            } else if (tipo === 'admin') {
                enviar({
                    acao: 'criar_admin',
                    nome: $('#adNome').value.trim(),
                    usuario: $('#adUsuario').value.trim(),
                    senha: $('#adSenha').value,
                    nivel: $('#adNivel').value,
                    condominio_id: $('#adCondominio') ? $('#adCondominio').value : 0
                }, true);
            }
            return;
        }

        const status = evento.target.closest('[data-status]');
        if (status) {
            enviar({ acao: 'status_' + status.dataset.status, id: status.dataset.id }, true);
            return;
        }

        const excluirFeriado = evento.target.closest('[data-excluir-feriado]');
        if (excluirFeriado) {
            enviar({ acao: 'excluir_feriado', id: excluirFeriado.dataset.excluirFeriado }, true);
            return;
        }

        const excluirAdv = evento.target.closest('[data-excluir-adv]');
        if (excluirAdv) {
            enviar({ acao: 'excluir_advertencia', id: excluirAdv.dataset.excluirAdv }, true);
        }
    });

    /* ------------------------ horário do cargo ------------------------ */
    /* Mostra a jornada resultante enquanto a pessoa digita, para ela conferir
       antes de salvar. */
    function calcularJornada(entrada, saida, intervalo) {
        if (!entrada || !saida || entrada === saida) return null;
        const min = (h) => Number(h.slice(0, 2)) * 60 + Number(h.slice(3, 5));
        const e = min(entrada), s = min(saida);
        const bruto = s > e ? (s - e) : (1440 - e + s);
        const liquido = Math.max(0, bruto - (Number(intervalo) || 0));
        return String(Math.floor(liquido / 60)).padStart(2, '0') + 'h '
             + String(liquido % 60).padStart(2, '0') + 'm';
    }

    /* "HH:MM" mais/menos N minutos, dando a volta na meia-noite. */
    function somarMin(hhmm, delta) {
        const t = ((Number(hhmm.slice(0, 2)) * 60 + Number(hhmm.slice(3, 5)) + delta) % 1440 + 1440) % 1440;
        return String(Math.floor(t / 60)).padStart(2, '0') + ':' + String(t % 60).padStart(2, '0');
    }

    /* Frase que mostra o que as tolerâncias significam na prática. */
    function descreverJanela(entrada, atraso, antes) {
        if (!entrada) return '';
        const a = atraso === '' ? 0 : Number(atraso);
        const fim = somarMin(entrada, a);
        if (antes === '') {
            return a > 0
                ? 'Chegando até ' + fim + ' não conta atraso. Chegar antes não tem limite.'
                : 'Sem tolerância: qualquer minuto depois de ' + entrada + ' conta atraso. Chegar antes não tem limite.';
        }
        const ini = somarMin(entrada, -Number(antes));
        return 'Chegando entre ' + ini + ' e ' + fim + ': sem atraso. Antes das ' + ini + ' o app avisa e não deixa bater a entrada.';
    }

    function atualizarPreview() {
        const j = calcularJornada($('#cgEntrada').value, $('#cgSaida').value, $('#cgIntervalo').value);
        const janela = descreverJanela($('#cgEntrada').value, $('#cgTolerancia').value, $('#cgAntecipacao').value);
        $('#cgPreview').textContent = (j ? 'Jornada de ' + j + ' por dia. O que passar disso não gera crédito. ' : '') + janela;
    }
    ['#cgEntrada', '#cgSaida', '#cgIntervalo', '#cgTolerancia', '#cgAntecipacao'].forEach((sel) => {
        const el = $(sel);
        if (el) el.addEventListener('input', atualizarPreview);
    });
    atualizarPreview();

    /* Prévia de cada cargo já cadastrado, dentro do painel dele. */
    document.querySelectorAll('[data-painel-horario]').forEach((painel) => {
        const id = painel.dataset.painelHorario;
        const alvo = painel.querySelector('[data-preview="' + id + '"]');
        const campo = (nome) => painel.querySelector('[data-campo="' + nome + '"]');
        const atualizar = () => {
            if (alvo) alvo.textContent = descreverJanela(campo('entrada').value, campo('tolerancia').value, campo('antecipacao').value);
        };
        ['entrada', 'tolerancia', 'antecipacao'].forEach((n) => campo(n).addEventListener('input', atualizar));
        atualizar();
    });

    document.addEventListener('click', (evento) => {
        const abrir = evento.target.closest('[data-horario]');
        if (abrir) {
            const painel = document.querySelector(`[data-painel-horario="${abrir.dataset.horario}"]`);
            painel.classList.toggle('hidden');
            painel.classList.toggle('flex');
            return;
        }

        const salvar = evento.target.closest('[data-salvar-horario]');
        if (salvar) {
            const id = salvar.dataset.salvarHorario;
            const painel = document.querySelector(`[data-painel-horario="${id}"]`);
            const campo = (nome) => painel.querySelector(`[data-campo="${nome}"]`).value;
            enviar({
                acao: 'horario_cargo',
                id,
                hora_entrada: campo('entrada'),
                hora_saida: campo('saida'),
                minutos_intervalo: campo('intervalo'),
                tolerancia_minutos: campo('tolerancia'),
                antecipacao_minutos: campo('antecipacao'),
                controla_falta: painel.querySelector('[data-campo="falta"]').checked ? 1 : 0
            }, true);
        }
    });

    document.addEventListener('click', (evento) => {
        const add = evento.target.closest('[data-add-turno]');
        if (add) {
            const id = add.dataset.addTurno;
            const bloco = document.querySelector(`[data-novo-turno="${id}"]`);
            const campo = (n) => bloco.querySelector(`[data-campo="${n}"]`).value;
            enviar({
                acao: 'criar_turno',
                cargo_id: id,
                hora_entrada: campo('entrada'),
                hora_saida: campo('saida'),
                minutos_intervalo: campo('intervalo'),
                descricao: campo('descricao')
            }, true);
            return;
        }

        const remover = evento.target.closest('[data-excluir-turno]');
        if (remover) {
            enviar({ acao: 'excluir_turno', id: remover.dataset.excluirTurno }, true);
        }
    });

    /* Super admin não fica preso a um condomínio. */
    const nivel = $('#adNivel');
    if (nivel) {
        nivel.addEventListener('change', () => {
            $('#adCondominio').disabled = nivel.value === 'super_admin';
        });
    }
})();
</script>
<?php app_mobile_end(); ?>
