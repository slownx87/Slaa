<?php
/*
    VN System - Layout e <head> únicos (design system).

    - vn_ui_head_tags()        <meta>, fontes, tema sem "piscada", CSS e JS — usado por TODAS as telas
    - vn_ui_shell_start/end()  barra lateral (PC) + topo + menu lateral e barra inferior (celular)

    Não depende de bootstrap: pode ser carregado por qualquer página. O menu vem de
    _vn_admin_menu.php (já filtrado pelo cargo do usuário).
*/
if (defined('VN_UI_LOADED')) {
    return;
}
define('VN_UI_LOADED', true);

require_once __DIR__ . '/_vn_admin_menu.php';

/*
    Ícones Material Symbols usados no sistema. O Google Fonts só entrega estes (a fonte inteira
    pesa centenas de KB). Ao usar um ícone novo, ADICIONE o nome aqui e rode:
        php tools/scan-icons.php --check
    (um ícone que falte aparece na tela como texto, ex.: "receipt_long").
*/
const VN_UI_ICONS = [
    'account_circle', 'add', 'add_circle', 'add_shopping_cart', 'apartment', 'arrow_back', 'arrow_forward',
    'assignment', 'attach_money', 'bar_chart', 'bolt', 'build', 'calendar_month', 'call', 'camera_alt', 'campaign', 'chat',
    'check', 'check_circle', 'chevron_right', 'close', 'computer', 'construction', 'content_copy', 'dark_mode',
    'dashboard', 'delete', 'deployed_code', 'download', 'edit', 'edit_calendar', 'engineering', 'error', 'event',
    'event_repeat', 'expand_more', 'fact_check', 'filter_list', 'fingerprint', 'flag', 'garage', 'group',
    'groups', 'handyman', 'history', 'home', 'hourglass_top', 'info', 'inventory_2', 'keyboard_arrow_up',
    'label', 'light_mode', 'link', 'location_on', 'lock', 'logout', 'mail', 'menu', 'monitoring', 'more_horiz',
    'more_vert', 'move_up', 'note_add', 'notifications_active', 'output', 'paid', 'payments', 'person',
    'photo_camera', 'picture_as_pdf', 'pix', 'production_quantity_limits', 'qr_code_2', 'receipt_long', 'refresh',
    'request_quote', 'schedule', 'search', 'security', 'sell', 'send', 'share', 'shield', 'shopping_cart',
    'signature', 'smartphone', 'space_dashboard', 'speed', 'support_agent', 'target', 'task', 'task_alt', 'tune',
    'upload', 'verified_user', 'videocam', 'visibility', 'visibility_off', 'warehouse', 'warning',
];

function vn_ui_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/* Endereço base do site (esquema + domínio + pasta), usado em links que o admin envia a clientes. */
function vn_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($dir === '.' ? '' : $dir);
}

/* Número de WhatsApp só com dígitos e DDI 55 (vazio se não parecer um telefone). */
function vn_wa_phone($telefone): string
{
    $d = preg_replace('/\D+/', '', (string)$telefone);
    if (strlen($d) < 10) {
        return '';
    }
    return strlen($d) <= 11 ? '55' . $d : $d;
}

/* Link wa.me com mensagem pronta. Sem telefone válido, abre o WhatsApp para escolher o contato. */
function vn_wa_link($telefone, string $texto): string
{
    return 'https://wa.me/' . vn_wa_phone($telefone) . '?text=' . rawurlencode($texto);
}

/* Arquivo estático com ?v=<data de modificação>: o navegador baixa de novo só quando o arquivo muda. */
function vn_ui_asset(string $rel): string
{
    $f = __DIR__ . '/' . ltrim($rel, '/');
    return $rel . (is_file($f) ? '?v=' . filemtime($f) : '');
}

function vn_ui_fonts_html(bool $icons = true): string
{
    $lista = VN_UI_ICONS;
    sort($lista);
    $h = '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n"
        . '    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n"
        . '    <link href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">';
    if ($icons) {
        $h .= "\n" . '    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@400..700,0..1&icon_names=' . implode(',', $lista) . '&display=block" rel="stylesheet">';
    }
    return $h;
}

/*
    Opções: title (string), desc, noindex (bool),
            legacy ('admin' | 'mobile' | ''), css (array), js (array de scripts defer),
            charts (bool: carrega vn-charts.js)
*/
function vn_ui_head_tags(array $o = []): string
{
    $title = trim((string)($o['title'] ?? ''));
    $out = '<meta charset="utf-8">' . "\n";
    $out .= '    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">' . "\n";
    $out .= '    <meta name="theme-color" content="#2563eb">' . "\n";
    if ($title !== '') {
        $out .= '    <title>' . vn_ui_h($title) . (!empty($o['title_raw']) ? '' : ' - VN System') . '</title>' . "\n";
    }
    if (!empty($o['desc'])) {
        $out .= '    <meta name="description" content="' . vn_ui_h($o['desc']) . '">' . "\n";
    }
    if (!empty($o['noindex'])) {
        $out .= '    <meta name="robots" content="noindex, nofollow">' . "\n";
    }
    $out .= '    ' . vn_ui_fonts_html(!array_key_exists('icons', $o) || $o['icons']) . "\n";
    $out .= '    <link rel="icon" href="vn.png" type="image/png">' . "\n";
    $out .= '    <link rel="apple-touch-icon" href="assets/icons/icon-192.png">' . "\n";
    $out .= '    <link rel="manifest" href="manifest.webmanifest">' . "\n";
    $out .= '    <meta name="mobile-web-app-capable" content="yes">' . "\n";
    $out .= '    <meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
    $out .= '    <meta name="apple-mobile-web-app-title" content="VN System">' . "\n";
    // Tema aplicado antes de pintar a página (evita a "piscada" claro→escuro).
    $padrao = (($o['theme_default'] ?? 'light') === 'dark') ? 'dark' : 'light';
    $out .= '    <script>(function(){var d="' . $padrao . '";try{var t=localStorage.getItem("vn-theme");document.documentElement.setAttribute("data-theme",(t==="dark"||t==="light")?t:d)}catch(e){document.documentElement.setAttribute("data-theme",d)}})();</script>' . "\n";
    $out .= '    <link rel="stylesheet" href="' . vn_ui_h(vn_ui_asset('assets/css/vn-ui.css')) . '">' . "\n";
    // CSS/JS próprio da página (telas antigas) entra antes do CSS legado, que é quem o sobrescreve.
    if (!empty($o['head_extra'])) {
        $out .= (string)$o['head_extra'] . "\n";
    }
    $legacy = (string)($o['legacy'] ?? '');
    if ($legacy === 'admin') {
        $out .= '    <link rel="stylesheet" href="' . vn_ui_h(vn_ui_asset('assets/css/vn-legacy-admin.css')) . '">' . "\n";
    } elseif ($legacy === 'mobile') {
        $out .= '    <link rel="stylesheet" href="' . vn_ui_h(vn_ui_asset('assets/css/vn-legacy-mobile.css')) . '">' . "\n";
    }
    foreach ((array)($o['css'] ?? []) as $css) {
        $out .= '    <link rel="stylesheet" href="' . vn_ui_h(vn_ui_asset((string)$css)) . '">' . "\n";
    }
    $out .= '    <script defer src="' . vn_ui_h(vn_ui_asset('assets/js/vn-ui.js')) . '"></script>' . "\n";
    // Pesquisa nos seletores de cliente (todas as telas): abre uma janela com barra de pesquisa ao tocar no campo.
    $out .= '    <script defer src="' . vn_ui_h(vn_ui_asset('assets/js/vn-select-busca.js')) . '"></script>' . "\n";
    if (!empty($o['charts'])) {
        $out .= '    <script defer src="' . vn_ui_h(vn_ui_asset('assets/js/vn-charts.js')) . '"></script>' . "\n";
    }
    foreach ((array)($o['js'] ?? []) as $js) {
        $out .= '    <script defer src="' . vn_ui_h(vn_ui_asset((string)$js)) . '"></script>' . "\n";
    }
    return $out;
}

/* Cargo, nome e logo do usuário logado (funciona com ou sem bootstrap). */
function vn_ui_user(): array
{
    $cargo = strtolower(trim((string)($GLOBALS['VN_CARGO'] ?? ($_SESSION['cargo'] ?? ''))));
    $nome = (string)($GLOBALS['VN_USUARIO_NOME'] ?? ($_SESSION['usuario'] ?? $_SESSION['admin'] ?? $_SESSION['nome'] ?? 'Usuário'));
    $logo = function_exists('vn_logo_src') ? (string)vn_logo_src() : 'vn.png';
    return ['cargo' => $cargo, 'nome' => $nome !== '' ? $nome : 'Usuário', 'logo' => $logo !== '' ? $logo : 'vn.png'];
}

/* Itens da barra inferior do celular, conforme o cargo. [chave, link, ícone, rótulo] */
function vn_ui_tabs(string $cargo): array
{
    if ($cargo === 'admin') {
        return [
            ['inicio', 'inicial-vn.php', 'home', 'Início'],
            ['pedidos', 'pedidos-dashboard.php', 'receipt_long', 'Pedidos'],
            ['fab', 'orcamento-novo.php', 'add', 'Novo pedido'],
            ['agenda', 'agenda.php', 'calendar_month', 'Agenda'],
            ['mais', '', 'menu', 'Menu'],
        ];
    }
    return [
        ['inicio', 'iniciar-funcionario.php', 'construction', 'OS'],
        ['agenda', 'agenda.php', 'calendar_month', 'Agenda'],
        ['retiradas', 'retirada-materiais.php', 'signature', 'Retiradas'],
        ['mais', '', 'menu', 'Menu'],
    ];
}

/* Qual aba da barra inferior fica marcada para cada chave do menu. */
function vn_ui_tab_for(string $active): string
{
    $map = [
        'visao' => 'inicio', 'inicio' => 'inicio',
        'pedidos' => 'pedidos', 'novo' => 'pedidos',
        'agenda' => 'agenda',
        'retiradas' => 'retiradas', 'area-os' => 'inicio',
    ];
    return $map[$active] ?? 'mais';
}

function vn_ui_menu_html(string $active): string
{
    $h = '';
    foreach (vn_admin_menu_grupos() as $titulo => $itens) {
        $h .= '<div class="vn-menu-group">' . vn_ui_h($titulo) . '</div>';
        foreach ($itens as [$chave, $href, $fa, $mat, $rotulo]) {
            $h .= '<a class="' . ($active === $chave ? 'active' : '') . '"' . ($active === $chave ? ' aria-current="page"' : '')
                . ' href="' . vn_ui_h($href) . '"><span class="material-symbols-outlined" aria-hidden="true">' . vn_ui_h($mat) . '</span>' . vn_ui_h($rotulo) . '</a>';
        }
    }
    return $h;
}

/*
    Abre a página: <html>, <head>, barra lateral, topo, menu lateral do celular e <main>.
    $o: sub (texto abaixo do título), + as opções de vn_ui_head_tags().
*/
function vn_ui_shell_start(string $titulo, string $active = '', array $o = []): void
{
    if (($o['nav'] ?? '') === 'client') {
        vn_ui_client_start($titulo, $active, $o);
        return;
    }
    $u = vn_ui_user();
    $isAdmin = $u['cargo'] === 'admin';
    $home = $isAdmin ? 'inicial-vn.php' : vn_home_por_cargo($u['cargo']);
    $o['title'] = $titulo;
    $o['noindex'] = true;
    $sub = (string)($o['sub'] ?? date('d/m/Y'));
    ?>
<!doctype html>
<html lang="pt-BR" data-theme="light">
<head>
    <?php echo vn_ui_head_tags($o); ?>
</head>
<body class="vn-admin-page vn-orcamento-module<?php echo (($o['legacy'] ?? '') !== '') ? ' vn-legacy-' . vn_ui_h($o['legacy']) : ''; ?>">
<a class="vn-skip" href="#vnMain">Pular para o conteúdo</a>
<div class="vn-shell">
    <aside class="vn-sidebar" aria-label="Menu principal">
        <a class="vn-brand" href="<?php echo vn_ui_h($isAdmin ? 'visao-geral.php' : $home); ?>">
            <img src="<?php echo vn_ui_h($u['logo']); ?>" alt="VN System">
            <span><strong>VNSYSTEM</strong><small>Gestão de Serviços</small></span>
        </a>
        <?php if ($isAdmin): ?><a class="vn-new" href="orcamento-novo.php"><span class="material-symbols-outlined" aria-hidden="true">add</span>Novo Pedido</a><?php endif; ?>
        <nav class="vn-menu"><?php echo vn_ui_menu_html($active); ?></nav>
        <div class="vn-sidebar-foot">
            <a class="vn-back-panel" href="<?php echo vn_ui_h($home); ?>"><span class="material-symbols-outlined" aria-hidden="true">home</span>Início</a>
            <button type="button" class="vn-back-panel" data-theme-toggle><span class="material-symbols-outlined vn-theme-icon" aria-hidden="true"></span>Alternar tema</button>
            <a class="vn-back-panel" href="logout.php"><span class="material-symbols-outlined" aria-hidden="true">logout</span>Sair</a>
        </div>
    </aside>

    <div class="vn-main">
        <header class="vn-top">
            <button type="button" class="vn-icon-btn vn-only-mobile" data-vn-drawer aria-label="Abrir menu"><span class="material-symbols-outlined" aria-hidden="true">menu</span></button>
            <div class="vn-top-title"><h1><?php echo vn_ui_h($titulo); ?></h1><?php if ($sub !== ''): ?><p><?php echo vn_ui_h($sub); ?></p><?php endif; ?></div>
            <div class="vn-user">
                <button type="button" class="vn-icon-btn" data-theme-toggle aria-label="Alternar tema"><span class="material-symbols-outlined vn-theme-icon" aria-hidden="true"></span></button>
                <span class="vn-avatar material-symbols-outlined vn-only-pc" aria-hidden="true">account_circle</span>
                <span class="vn-user-name vn-only-pc"><?php echo vn_ui_h($u['nome']); ?><br><small><?php echo vn_ui_h(ucfirst($u['cargo'])); ?></small></span>
            </div>
        </header>
        <main class="vn-content-wrap" id="vnMain" tabindex="-1">
    <?php
}

function vn_ui_shell_end(string $active = ''): void
{
    if (!empty($GLOBALS['vn_ui_client_mode'])) {
        vn_ui_client_end();
        return;
    }
    $u = vn_ui_user();
    $isAdmin = $u['cargo'] === 'admin';
    $home = $isAdmin ? 'inicial-vn.php' : vn_home_por_cargo($u['cargo']);
    $tabs = vn_ui_tabs($u['cargo']);
    $tabAtiva = vn_ui_tab_for($active);
    ?>
        </main>
    </div>
</div>

<div class="vn-drawer-backdrop" id="vnDrawerBackdrop"></div>
<aside class="vn-drawer" id="vnDrawer" aria-label="Menu">
    <a class="vn-brand" href="<?php echo vn_ui_h($home); ?>">
        <img src="<?php echo vn_ui_h($u['logo']); ?>" alt="VN System">
        <span><strong>VNSYSTEM</strong><small>Gestão de Serviços</small></span>
    </a>
    <div class="vn-drawer-user"><span class="material-symbols-outlined" aria-hidden="true">account_circle</span><span><?php echo vn_ui_h($u['nome']); ?><small><?php echo vn_ui_h(ucfirst($u['cargo'])); ?></small></span></div>
    <nav class="vn-menu"><?php echo vn_ui_menu_html($active); ?></nav>
    <div class="vn-sidebar-foot">
        <button type="button" class="vn-back-panel" data-theme-toggle><span class="material-symbols-outlined vn-theme-icon" aria-hidden="true"></span>Alternar tema</button>
        <a class="vn-back-panel" href="logout.php"><span class="material-symbols-outlined" aria-hidden="true">logout</span>Sair</a>
    </div>
</aside>

<nav class="vn-tabbar vn-tabbar-<?php echo count($tabs); ?>" aria-label="Atalhos">
    <?php foreach ($tabs as [$k, $href, $ico, $rot]): ?>
        <?php if ($k === 'fab'): ?>
            <a class="vn-tab-fab" href="<?php echo vn_ui_h($href); ?>" aria-label="<?php echo vn_ui_h($rot); ?>"><span class="material-symbols-outlined" aria-hidden="true"><?php echo vn_ui_h($ico); ?></span></a>
        <?php elseif ($k === 'mais'): ?>
            <button type="button" class="vn-tab<?php echo $tabAtiva === 'mais' ? ' active' : ''; ?>" data-vn-drawer><span class="material-symbols-outlined" aria-hidden="true"><?php echo vn_ui_h($ico); ?></span><?php echo vn_ui_h($rot); ?></button>
        <?php else: ?>
            <a class="vn-tab<?php echo $tabAtiva === $k ? ' active' : ''; ?>"<?php echo $tabAtiva === $k ? ' aria-current="page"' : ''; ?> href="<?php echo vn_ui_h($href); ?>"><span class="material-symbols-outlined" aria-hidden="true"><?php echo vn_ui_h($ico); ?></span><?php echo vn_ui_h($rot); ?></a>
        <?php endif; ?>
    <?php endforeach; ?>
</nav>
</body>
</html>
    <?php
}

/* ------------------------------------------------------------------ área do cliente
   Sem menu administrativo: topo com a marca, 2 links, tema e sair. Usado por painel-cliente e os.php. */
function vn_ui_client_start(string $titulo, string $active, array $o): void
{
    $GLOBALS['vn_ui_client_mode'] = true;
    $u = vn_ui_user();
    $o['title'] = $titulo;
    $o['noindex'] = true;
    ?>
<!doctype html>
<html lang="pt-BR" data-theme="light">
<head>
    <?php echo vn_ui_head_tags($o); ?>
</head>
<body class="vn-admin-page vn-client<?php echo (($o['legacy'] ?? '') !== '') ? ' vn-legacy-' . vn_ui_h($o['legacy']) : ''; ?>">
<a class="vn-skip" href="#vnMain">Pular para o conteúdo</a>
<header class="vn-top vn-top-client">
    <a class="vn-brand" href="painel-cliente.php">
        <img src="<?php echo vn_ui_h($u['logo']); ?>" alt="VN System">
        <span><strong>VNSYSTEM</strong><small>Área do cliente</small></span>
    </a>
    <nav class="vn-client-nav" aria-label="Menu do cliente">
        <a href="painel-cliente.php"<?php echo $active === 'painel-cliente' ? ' class="active" aria-current="page"' : ''; ?>><span class="material-symbols-outlined" aria-hidden="true">receipt_long</span><span>Minhas OS</span></a>
        <a href="os.php"<?php echo $active === 'os' ? ' class="active" aria-current="page"' : ''; ?>><span class="material-symbols-outlined" aria-hidden="true">note_add</span><span>Solicitar serviço</span></a>
    </nav>
    <div class="vn-user">
        <button type="button" class="vn-icon-btn" data-theme-toggle aria-label="Alternar tema"><span class="material-symbols-outlined vn-theme-icon" aria-hidden="true"></span></button>
        <a class="vn-icon-btn" href="logout.php" aria-label="Sair" title="Sair"><span class="material-symbols-outlined" aria-hidden="true">logout</span></a>
    </div>
</header>
<main class="vn-content-wrap vn-client-wrap" id="vnMain" tabindex="-1">
    <?php
}

function vn_ui_client_end(): void
{
    $GLOBALS['vn_ui_client_mode'] = false;
    ?>
</main>
</body>
</html>
    <?php
}
