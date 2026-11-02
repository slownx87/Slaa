<?php
/*
 * Casca das telas do painel administrativo mobile.
 *
 * Usa o mesmo tema do app do funcionário (_app_shell.php): só muda a barra
 * inferior, que aqui tem Painel / Funcionários / Calendário / Relatórios.
 */

declare(strict_types=1);

require_once __DIR__ . '/_app_shell.php';

/** Barra superior do admin: logo + engrenagem (configurações). */
function admin_topbar(bool $mostrarConfig = true): void
{
    ?>
<header class="fixed top-0 w-full z-50 backdrop-blur-xl border-b border-white/10 bg-surface/70 transition-all duration-200">
<div class="relative flex items-center justify-center px-container-margin h-14 w-full max-w-md mx-auto">
<a class="flex items-center justify-center active:scale-95 transition-transform" href="admin.php" aria-label="<?= h(APP_NAME) ?>">
<img alt="<?= h(APP_NAME) ?>" class="logo-app h-9 w-auto object-contain" src="<?= h(LOGO_PATH) ?>"/>
</a>
<?php if ($mostrarConfig): ?>
<a class="absolute right-container-margin w-9 h-9 flex items-center justify-center rounded-full text-primary hover:bg-primary/10 active:scale-95 transition-all" href="admin-config.php" aria-label="Configurações">
<span class="material-symbols-outlined text-[20px]">settings</span>
</a>
<?php endif; ?>
</div>
</header>
    <?php
}

/** Barra inferior do admin. $ativo: painel | funcionarios | calendario | relatorios */
function admin_nav(string $ativo): void
{
    $itens = [
        'painel'      => ['admin.php',             'dashboard',  'Painel'],
        'funcionarios'=> ['admin-funcionarios.php','group',      'Equipe'],
        'calendario'  => ['admin-calendario.php',  'event_note', 'Ajustes'],
        'relatorios'  => ['admin-relatorios.php',  'assessment', 'Relatórios'],
    ];
    ?>
<nav class="fixed bottom-0 w-full z-50 rounded-t-2xl backdrop-blur-2xl border-t border-white/10 shadow-lg bg-surface/80" aria-label="Navegação do painel">
<div class="flex justify-around items-center px-2 pb-safe h-16 max-w-md mx-auto">
<?php foreach ($itens as $chave => [$href, $icone, $rotulo]):
    $on = ($chave === $ativo); ?>
<a class="flex flex-col items-center justify-center <?= $on ? 'text-primary bg-primary/10 rounded-xl px-3 py-1' : 'text-secondary hover:text-primary' ?> transition-transform duration-300 active:scale-90 w-[72px]" href="<?= h($href) ?>"<?= $on ? ' aria-current="page"' : '' ?>>
<span class="material-symbols-outlined mb-0.5 text-[20px]" style="font-variation-settings: 'FILL' <?= $on ? '1' : '0' ?>;"><?= h($icone) ?></span>
<span class="font-label-bold text-label-bold"><?= h($rotulo) ?></span>
</a>
<?php endforeach; ?>
</div>
</nav>
    <?php
}

/**
 * Filtro de condomínio do admin logado, aplicado às consultas do painel.
 * Super admin enxerga tudo; os demais só o próprio condomínio.
 */
function admin_filtro_condominio(string $alias = ''): array
{
    $prefix = $alias !== '' ? $alias . '.' : '';
    if (admin_nivel() === 'super_admin' || admin_condominio_id() === null) {
        return ['', []];
    }
    return [' AND ' . $prefix . 'condominio_id = ? ', [admin_condominio_id()]];
}

/** Resposta JSON padrão das telas do admin. */
function admin_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Confere se o admin logado pode mexer nesse funcionário. */
function admin_funcionario_autorizado(int $funcionarioId): array
{
    $func = get_funcionario($funcionarioId);
    if (!$func) {
        throw new RuntimeException('Funcionário não encontrado.');
    }
    if (admin_nivel() !== 'super_admin' && admin_condominio_id() !== null
        && admin_condominio_id() !== (int)$func['condominio_id']) {
        throw new RuntimeException('Sem permissão para esse funcionário.');
    }
    return $func;
}

/** CSS comum das telas do admin (campos escuros e folha inferior). */
function admin_css(): string
{
    return <<<'CSS'
<style>
    /* O plugin "forms" do Tailwind entra depois deste CSS e pinta os campos de
       branco; aqui o tema escuro precisa vencer. */
    .campo {
        background-color: rgba(5,8,16,.6) !important;
        border: 1px solid rgba(148,163,184,.35) !important;
        color: #fff !important;
        border-radius: .75rem;
        padding: .5rem .75rem;
        width: 100%;
    }
    .campo:focus { outline:none; border-color:#3b82f6 !important; box-shadow:0 0 0 1px #3b82f6 !important; }
    .campo::placeholder { color:#94a3b8; }
    select.campo option { background:#0a1322; color:#fff; }
    input[type="date"], input[type="time"], input[type="datetime-local"] { color-scheme: dark; }
    input[type="date"]::-webkit-calendar-picker-indicator,
    input[type="time"]::-webkit-calendar-picker-indicator { opacity:.7; filter: invert(1); cursor:pointer; }

    .sheet-fundo { transition: opacity .3s ease; opacity:0; pointer-events:none; }
    .sheet-fundo.aberto { opacity:1; pointer-events:auto; }
    .sheet-folha { transition: transform .35s cubic-bezier(0.16, 1, 0.3, 1); transform: translateY(100%); }
    .sheet-folha.aberto { transform: translateY(0); }

    .cartao { background: rgba(17,24,39,.86); border:1px solid rgba(148,163,184,.18); }
    .linha-toque { transition: background-color .2s ease, transform .12s ease; }
    .linha-toque:active { background-color: rgba(59,130,246,.12); transform: scale(.99); }
</style>
CSS;
}
