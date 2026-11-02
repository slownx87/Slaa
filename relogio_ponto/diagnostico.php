<?php
/*
 * Diagnóstico do sistema: confere tabelas, colunas e permissões.
 * Para erro 500 (quando nem o _config.php carrega), use checar.php.
 */

require_once __DIR__ . '/_config.php';
require_once __DIR__ . '/_admin_shell.php';
require_admin_or_first_install();

$itens = [];
function diag_item(string $nome, bool $ok, string $detalhe = ''): void
{
    global $itens;
    $itens[] = ['nome' => $nome, 'ok' => $ok, 'detalhe' => $detalhe];
}

diag_item('Versão do PHP', version_compare(PHP_VERSION, '7.1.0', '>='), PHP_VERSION . ' — mínimo 7.1, recomendado 8.x');
diag_item('Extensão PDO', extension_loaded('pdo'), extension_loaded('pdo') ? 'OK' : 'PDO não carregado');
diag_item('Extensão pdo_mysql', extension_loaded('pdo_mysql'), extension_loaded('pdo_mysql') ? 'OK' : 'pdo_mysql não carregado');
diag_item('Extensão mbstring', extension_loaded('mbstring'), extension_loaded('mbstring') ? 'OK' : 'mbstring não carregado');

try {
    db();
    diag_item('Conexão com banco', true, 'Conectado com sucesso');
} catch (Throwable $e) {
    diag_item('Conexão com banco', false, $e->getMessage());
}

/* As três últimas são criadas sozinhas pelo app. */
try { ensure_solicitacoes_table(); } catch (Throwable $e) { /* aparece na lista */ }
try { ensure_pin_tentativas_table(); } catch (Throwable $e) { /* aparece na lista */ }
try { ensure_advertencias_table(); } catch (Throwable $e) { /* aparece na lista */ }

$tabelas = ['ponto_condominios', 'ponto_cargos', 'ponto_funcionarios', 'ponto_admins',
            'ponto_registros', 'ponto_resumo_diario', 'ponto_ajustes',
            'ponto_solicitacoes', 'ponto_advertencias', 'ponto_pin_tentativas'];
foreach ($tabelas as $t) {
    try { diag_item('Tabela ' . $t, table_exists($t), table_exists($t) ? 'Existe' : 'Não existe'); }
    catch (Throwable $e) { diag_item('Tabela ' . $t, false, $e->getMessage()); }
}

foreach (['foto_path', 'foto_mime', 'foto_tamanho'] as $c) {
    try { diag_item('Coluna ponto_registros.' . $c, column_exists('ponto_registros', $c), column_exists('ponto_registros', $c) ? 'Existe' : 'Não existe'); }
    catch (Throwable $e) { diag_item('Coluna ponto_registros.' . $c, false, $e->getMessage()); }
}

try {
    $ok = column_exists('ponto_funcionarios', 'pin_fingerprint');
    diag_item('Coluna pin_fingerprint', $ok, $ok ? 'Existe — conferência de PIN rápida' : 'Não existe — o login por PIN fica lento');
} catch (Throwable $e) {
    diag_item('Coluna pin_fingerprint', false, $e->getMessage());
}

/* Sem estas o admin não consegue autorizar pedido de ajuste. */
foreach (['registro_id', 'tipo_solicitacao', 'data_hora_atual'] as $c) {
    try {
        $ok = column_exists('ponto_solicitacoes', $c);
        diag_item('Coluna ponto_solicitacoes.' . $c, $ok,
            $ok ? 'Existe' : 'Não existe — rode: ALTER TABLE ponto_solicitacoes ADD COLUMN ' . $c . ' ...(ver sql/ponto_solicitacoes.sql)');
    } catch (Throwable $e) {
        diag_item('Coluna ponto_solicitacoes.' . $c, false, $e->getMessage());
    }
}

/* Tolerâncias por cargo (criadas sozinhas na primeira vez que a tela de cargos abre). */
try { ensure_cargo_horarios(); ensure_cargo_turnos_table(); } catch (Throwable $e) { /* aparece na lista */ }
foreach (['tolerancia_minutos', 'antecipacao_minutos'] as $c) {
    try {
        $ok = column_exists('ponto_cargos', $c);
        diag_item('Coluna ponto_cargos.' . $c, $ok, $ok ? 'Existe' : 'Não existe — abra Configurações > Cargos uma vez, ou rode o ALTER TABLE do LEIA-ME');
    } catch (Throwable $e) {
        diag_item('Coluna ponto_cargos.' . $c, false, $e->getMessage());
    }
}

/* As fotos (rostos) só podem sair por foto.php. Confere se a pasta está fechada. */
$htFotos = __DIR__ . '/uploads/ponto_fotos/.htaccess';
$fechada = is_file($htFotos) && strpos((string)file_get_contents($htFotos), 'Require all denied') !== false;
diag_item('Pasta de fotos fechada', $fechada,
    $fechada ? 'uploads/ponto_fotos/.htaccess bloqueia o acesso direto (as fotos abrem só por foto.php, com login)'
             : 'Falta o .htaccess em uploads/ponto_fotos — sem ele as fotos abrem para quem tiver o link');

/* Pedidos antigos que não guardam qual batida é. */
try {
    if (table_exists('ponto_solicitacoes') && column_exists('ponto_solicitacoes', 'tipo_registro')) {
        $antigos = (int)db()->query("SELECT COUNT(*) FROM ponto_solicitacoes WHERE status = 'pendente' AND (tipo_registro IS NULL OR tipo_registro = '')")->fetchColumn();
        diag_item('Pedidos sem tipo de batida', $antigos === 0,
            $antigos === 0 ? 'Nenhum' : $antigos . ' pedido(s) antigos — o painel pergunta o tipo na hora de autorizar');
    }
} catch (Throwable $e) {
    diag_item('Pedidos sem tipo de batida', false, $e->getMessage());
}

$uploadDir = __DIR__ . '/uploads/ponto_fotos';
if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0755, true); }
diag_item('Pasta uploads/ponto_fotos', is_dir($uploadDir), $uploadDir);
diag_item('Permissão de escrita das fotos', is_writable($uploadDir), is_writable($uploadDir) ? 'Gravável' : 'Sem permissão de escrita');

$falhas = count(array_filter($itens, function ($i) { return !$i['ok']; }));

app_mobile_head('Diagnóstico - ' . APP_NAME, 'bg-background text-on-background antialiased min-h-screen', admin_css(), 'manifest-admin.webmanifest');
admin_topbar(false);
?>
<main class="pt-[70px] pb-24 px-container-margin max-w-md mx-auto w-full flex flex-col gap-stack-gap-md">

<div class="flex flex-col gap-1 fade-in-up">
<h1 class="font-headline-md text-headline-md text-on-surface">Diagnóstico</h1>
<p class="font-body-md text-body-md text-secondary">
<?= $falhas === 0 ? 'Tudo certo com o sistema.' : $falhas . ' item(ns) precisam de atenção.' ?>
</p>
</div>

<div class="flex flex-col gap-2">
<?php foreach ($itens as $i => $item): ?>
<div class="surgir cartao rounded-lg p-2.5 flex items-start gap-2 <?= $item['ok'] ? '' : 'border-error/40' ?>" style="animation-delay:<?= min($i, 12) * 30 ?>ms">
<span class="material-symbols-outlined text-[18px] flex-shrink-0 mt-0.5 <?= $item['ok'] ? 'text-green-500' : 'text-error' ?>">
<?= $item['ok'] ? 'check_circle' : 'error' ?>
</span>
<div class="min-w-0">
<p class="font-body-lg text-body-lg text-on-surface"><?= h($item['nome']) ?></p>
<p class="font-label-sm text-label-sm text-secondary break-words"><?= h($item['detalhe']) ?></p>
</div>
</div>
<?php endforeach; ?>
</div>

<a class="font-label-bold text-label-bold text-secondary text-center py-2" href="admin-config.php">Voltar às configurações</a>
</main>
<?php app_mobile_end(); ?>
