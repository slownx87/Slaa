<?php
/*
    VN System - Bootstrap compartilhado do painel mobile v6.
    Páginas novas: inicial, dashboard OS, nova OS, metas, orçamento dashboard e orçamento em etapas.
*/

/* Sessão, cookie e tempo de login centralizados em _vn_security.php. */
require_once __DIR__ . '/_vn_security.php';
vn_session_start();

date_default_timezone_set('America/Sao_Paulo');

if (!function_exists('vn_now_sp')) {
    function vn_now_sp(): string { return date('Y-m-d H:i:s'); }
}
if (!function_exists('vn_today_sp')) {
    function vn_today_sp(): string { return date('Y-m-d'); }
}
if (!function_exists('vn_time_sp')) {
    function vn_time_sp(): string { return date('H:i:s'); }
}
if (!function_exists('vn_set_db_timezone_sp')) {
    function vn_set_db_timezone_sp($db): void {
        try {
            if ($db instanceof PDO) { $db->exec("SET time_zone = '-03:00'"); }
            elseif ($db instanceof mysqli) { @$db->query("SET time_zone = '-03:00'"); }
        } catch (Throwable $e) { /* não interrompe a página por causa do fuso */ }
    }
}


/* Preços e conta da serralheria por medida (m², metro linear, degrau, hora, kg). */
if (is_file(__DIR__ . '/_vn_serralheria_lib.php')) require_once __DIR__ . '/_vn_serralheria_lib.php';

/* Exibição de erros: somente via VN_DEBUG no arquivo de segredos (config.php). */

$vnBaseDirs = [__DIR__, dirname(__DIR__), dirname(__DIR__, 2), dirname(__DIR__, 3)];
$vnConfigLoaded = false;
foreach ($vnBaseDirs as $dir) {
    if (is_file($dir . '/config.php')) {
        require_once $dir . '/config.php';
        $vnConfigLoaded = true;
        break;
    }
}
/* cron/*.php roda pela linha de comando (PHP_SAPI=cli) e define VN_CLI_CRON; pela web isso nunca vale. */
$VN_CLI_CRON = (PHP_SAPI === 'cli' && defined('VN_CLI_CRON'));
if ((!defined('VN_PUBLIC_ACCESS') || !VN_PUBLIC_ACCESS) && !$VN_CLI_CRON) {
    foreach ($vnBaseDirs as $dir) {
        if (is_file($dir . '/auth.php')) {
            require_once $dir . '/auth.php';
            break;
        }
    }
}
if (!$vnConfigLoaded) die('Erro: config.php não encontrado. Coloque a pasta mobile dentro da pasta site do sistema.');

$VN_CARGO = strtolower(trim((string)($_SESSION['cargo'] ?? '')));
$VN_PUBLIC_ACCESS = (defined('VN_PUBLIC_ACCESS') && VN_PUBLIC_ACCESS) || $VN_CLI_CRON;
if (!$VN_PUBLIC_ACCESS && (!isset($_SESSION['cargo']) || !in_array($VN_CARGO, ['admin', 'funcionario'], true))) {
    $login = is_file(__DIR__ . '/login.php') ? 'login.php' : (is_file(dirname(__DIR__) . '/login.php') ? '../login.php' : '../../login.php');
    header('Location: ' . $login);
    exit;
}

$VN_USUARIO_ID = (int)($_SESSION['id'] ?? $_SESSION['admin_id'] ?? $_SESSION['usuario_id'] ?? $_SESSION['user_id'] ?? 0);
$VN_USUARIO_NOME = $_SESSION['usuario'] ?? $_SESSION['admin'] ?? $_SESSION['nome'] ?? 'VN System';
$VN_ID_TEC = (int)($_SESSION['id_tec'] ?? $_SESSION['tecnico_id'] ?? $_SESSION['vendedor_pedido_id'] ?? 0);
if ($VN_ID_TEC <= 0 && $VN_USUARIO_ID > 0) $VN_ID_TEC = $VN_USUARIO_ID;

function vn_db() {
    static $database = null;
    if ($database) return $database;

    global $conn, $mysqli, $pdo, $conexao, $con, $link;

    // Preferência: usa a função db() do config.php, que normalmente é PDO.
    // Isso evita erro 500 quando a extensão mysqli não está disponível ou quando $conn não foi criado.
    if (function_exists('db')) {
        try {
            $cfgDb = db();
            if ($cfgDb instanceof PDO) {
                vn_set_db_timezone_sp($cfgDb);
                return $database = $cfgDb;
            }
            if (class_exists('mysqli') && $cfgDb instanceof mysqli) {
                @$cfgDb->set_charset('utf8mb4');
                vn_set_db_timezone_sp($cfgDb);
                return $database = $cfgDb;
            }
        } catch (Throwable $e) {
            // Continua tentando as conexões globais abaixo.
        }
    }

    if (isset($pdo) && $pdo instanceof PDO) { vn_set_db_timezone_sp($pdo); return $database = $pdo; }
    if (class_exists('mysqli')) {
        foreach (['conn','mysqli','conexao','con','link'] as $g) {
            if (isset($GLOBALS[$g]) && $GLOBALS[$g] instanceof mysqli) {
                @$GLOBALS[$g]->set_charset('utf8mb4');
                vn_set_db_timezone_sp($GLOBALS[$g]);
                return $database = $GLOBALS[$g];
            }
        }
        if (defined('DB_HOST') && defined('DB_USER') && defined('DB_NAME')) {
            $pass = defined('DB_PASS') ? DB_PASS : '';
            $db = new mysqli(DB_HOST, DB_USER, $pass, DB_NAME);
            if ($db->connect_errno) throw new Exception('Erro ao conectar no banco: ' . $db->connect_error);
            $db->set_charset('utf8mb4');
            vn_set_db_timezone_sp($db);
            return $database = $db;
        }
    }
    throw new Exception('Não encontrei conexão com banco no config.php.');
}

function vn_is_pdo(): bool { return vn_db() instanceof PDO; }

function vn_bind_mysqli_params($stmt, array $params): void {
    if (!$params) return;
    $types = '';
    $values = [];
    foreach ($params as $p) {
        if (is_int($p) || $p === null) $types .= 'i';
        elseif (is_float($p)) $types .= 'd';
        else $types .= 's';
        $values[] = $p;
    }
    $bind = [$types];
    foreach ($values as $k => $v) $bind[] = &$values[$k];
    call_user_func_array([$stmt, 'bind_param'], $bind);
}

function vn_query(string $sql, array $params = []) {
    $db = vn_db();
    if ($db instanceof PDO) {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
    $stmt = $db->prepare($sql);
    if (!$stmt) throw new Exception(vn_sql_error_msg('prepare', (string)$db->error, (string)$sql));
    vn_bind_mysqli_params($stmt, $params);
    if (!$stmt->execute()) throw new Exception(vn_sql_error_msg('execute', (string)$stmt->error, (string)$sql));
    return $stmt;
}

function vn_exec(string $sql, array $params = []): int {
    $db = vn_db();
    if ($db instanceof PDO) {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->rowCount();
    }
    $stmt = vn_query($sql, $params);
    $n = (int)$stmt->affected_rows;
    $stmt->close();
    return $n;
}

function vn_last_id(): int {
    $db = vn_db();
    return $db instanceof PDO ? (int)$db->lastInsertId() : (int)$db->insert_id;
}

function vn_fetch_one(string $sql, array $params = []): ?array {
    $stmt = vn_query($sql, $params);
    if (vn_is_pdo()) {
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }
    $res = $stmt->get_result();
    $r = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $r ?: null;
}

function vn_fetch_all(string $sql, array $params = []): array {
    $stmt = vn_query($sql, $params);
    if (vn_is_pdo()) return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $res = $stmt->get_result();
    $rows = [];
    if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    return $rows;
}

function vn_database_name(): string {
    $r = vn_fetch_one('SELECT DATABASE() AS dbname');
    return (string)($r['dbname'] ?? '');
}

function vn_table_exists(string $table): bool {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    $dbName = vn_database_name();
    if ($dbName === '') return $cache[$table] = false;
    $r = vn_fetch_one('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1', [$dbName, $table]);
    return $cache[$table] = !empty($r);
}

function vn_column_exists(string $table, string $column): bool {
    global $VN_COL_CACHE;
    if (!is_array($VN_COL_CACHE ?? null)) $VN_COL_CACHE = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $VN_COL_CACHE)) return $VN_COL_CACHE[$key];
    $dbName = vn_database_name();
    if ($dbName === '') return $VN_COL_CACHE[$key] = false;
    $r = vn_fetch_one('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1', [$dbName, $table, $column]);
    return $VN_COL_CACHE[$key] = !empty($r);
}

function vn_add_col_if_missing(string $table, string $column, string $definition): void {
    global $VN_COL_CACHE;
    if (vn_table_exists($table) && !vn_column_exists($table, $column)) {
        try {
            vn_exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);
        } catch (Throwable $e) {
            // "Duplicate column": a coluna já existia (cache desatualizado na mesma requisição) — segue o jogo.
            $msg = $e->getMessage();
            if (stripos($msg, 'Duplicate column') === false && strpos($msg, '1060') === false) throw $e;
        }
        // Atualiza o cache para as próximas chamadas da MESMA requisição.
        $VN_COL_CACHE[$table . '.' . $column] = true;
    }
}

function vn_first_col(string $table, array $columns): ?string {
    foreach ($columns as $c) if (vn_column_exists($table, $c)) return $c;
    return null;
}

function vn_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function vn_clean($v): string { $v = trim((string)$v); return preg_replace('/\s+/', ' ', $v); }
function vn_money($v): string { return 'R$ ' . number_format((float)$v, 2, ',', '.'); }

function vn_money_db($v): float {
    $v = trim((string)$v);
    if ($v === '') return 0.0;
    $v = str_replace(['R$', ' '], '', $v);
    if (strpos($v, ',') !== false) $v = str_replace('.', '', $v);
    $v = str_replace(',', '.', $v);
    return round((float)$v, 2);
}

function vn_lower($v): string {
    $v = vn_clean($v);
    $v = function_exists('mb_strtolower') ? mb_strtolower($v, 'UTF-8') : strtolower($v);
    return strtr($v, [
        'á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c'
    ]);
}

function vn_date_br($v): string {
    if (!$v || $v === '0000-00-00' || $v === '0000-00-00 00:00:00') return 'Não informado';
    try { $dt = new DateTime((string)$v); return strlen((string)$v) <= 10 ? $dt->format('d/m/Y') : $dt->format('d/m/Y H:i'); }
    catch (Throwable $e) { return (string)$v; }
}

function vn_date_input_value($v): string {
    $v = trim((string)$v);
    if ($v === '' || $v === '0000-00-00' || $v === '0000-00-00 00:00:00') return '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) return substr($v, 0, 10);
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $v, $m)) return $m[3] . '-' . $m[2] . '-' . $m[1];
    try { return (new DateTime($v))->format('Y-m-d'); }
    catch (Throwable $e) { return ''; }
}

function vn_date_db_or_null($v): ?string {
    $date = vn_date_input_value($v);
    if ($date === '') return null;
    $dt = DateTime::createFromFormat('!Y-m-d', $date);
    return ($dt && $dt->format('Y-m-d') === $date) ? $date : null;
}

function vn_mes_atual(): string {
    $mes = preg_replace('/[^0-9\-]/', '', (string)($_GET['mes'] ?? date('Y-m')));
    return vn_mes_valido($mes) ? $mes : date('Y-m');
}

/* 'AAAA-MM' de verdade? (mês de 01 a 12; ano de 2000 a 2100) */
function vn_mes_valido(string $mes): bool {
    if (!preg_match('/^(\d{4})-(\d{2})$/', $mes, $m)) return false;
    return (int)$m[1] >= 2000 && (int)$m[1] <= 2100 && (int)$m[2] >= 1 && (int)$m[2] <= 12;
}

function vn_mes_nome(int $m): string {
    static $n = [1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 5 => 'Maio', 6 => 'Junho',
                 7 => 'Julho', 8 => 'Agosto', 9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'];
    return $n[$m] ?? '';
}

function vn_mes_range(?string $mes = null): array {
    $mes = $mes ?: vn_mes_atual();
    if (!vn_mes_valido($mes)) $mes = date('Y-m');
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $mes . '-01 00:00:00');
    if (!$dt) $dt = new DateTime('first day of this month 00:00:00');
    $inicio = $dt->format('Y-m-01 00:00:00');
    $fim = $dt->format('Y-m-t 23:59:59');
    return [$inicio, $fim, $dt->format('Y-m'), $dt->format('m/Y')];
}

/* Lista simples de meses (uma <option> por mês, com o ano): de 6 meses à frente até 5 anos atrás, sempre com o mês escolhido.
   Os filtros novos usam vn_mes_picker_html(); esta função fica por compatibilidade. */
function vn_mes_options_html(string $selected): string {
    $out = '';
    $base = new DateTime('first day of this month 00:00:00');
    $base->modify('+6 months');
    $vistos = [];
    for ($i = 0; $i < 6 + 60; $i++) {
        $d = clone $base;
        $d->modify('-' . $i . ' months');
        $vistos[$d->format('Y-m')] = true;
    }
    if (vn_mes_valido($selected)) $vistos[$selected] = true;
    krsort($vistos);
    foreach (array_keys($vistos) as $val) {
        $out .= '<option value="' . vn_h($val) . '" ' . ($val === $selected ? 'selected' : '') . '>' . vn_h(substr($val, 5, 2) . '/' . substr($val, 0, 4)) . '</option>';
    }
    return $out;
}

/*
   Seletor de MÊS e ANO:  ‹  [Novembro ▾] [2026 ▾]  ›   (as setas andam um mês; os dois menus pulam para qualquer mês/ano)
   Cria o campo name="mes" (AAAA-MM). Vai dentro de um <form method="get">.
   $opc: rotulo       texto pequeno em cima ('Mês'; '' = sem texto)
         todos        true = tem a opção "todos os meses" (o campo vai vazio)
         todos_rotulo texto dessa opção (padrão 'Todos os meses')
         auto         true (padrão) = envia o formulário ao mudar; false = só preenche o campo (o botão do formulário envia)
         nome         name do campo (padrão 'mes')
   Não depende de nenhum arquivo de script: o pequeno código vem junto (uma vez por página).
*/
function vn_mes_picker_html(string $selecionado, array $opc = []): string {
    static $assets = false;
    $rotulo = (string)($opc['rotulo'] ?? 'Mês');
    $todos = !empty($opc['todos']);
    $rotTodos = (string)($opc['todos_rotulo'] ?? 'Todos os meses');
    $auto = !array_key_exists('auto', $opc) || !empty($opc['auto']);
    $nome = (string)($opc['nome'] ?? 'mes');
    if (!vn_mes_valido($selecionado)) $selecionado = $todos ? '' : date('Y-m');
    $hoje = (int)date('Y');
    $ano = $selecionado !== '' ? (int)substr($selecionado, 0, 4) : $hoje;
    $mesSel = $selecionado !== '' ? substr($selecionado, 5, 2) : '';

    $h = '';
    if (!$assets) {
        $assets = true;
        $h .= '<style>'
            . '.vn-mes-wrap{display:flex;flex-direction:column;gap:6px;min-width:0;max-width:100%}'
            . '.vn-mes-rot{font-size:.78rem;font-weight:700;color:var(--text-soft,#4b5563)}'
            . '.vn-mes{display:inline-flex;align-items:center;gap:6px;max-width:100%;min-width:0}'
            . '.vn-mes select{min-height:44px;padding:8px 10px;border:1px solid var(--line-strong,#d1d5db);border-radius:var(--radius,10px);background:var(--surface,#fff);color:var(--text,#191c1d);font:inherit;font-weight:700;min-width:0}'
            . '.vn-mes .vn-mes-m{flex:1 1 140px}.vn-mes .vn-mes-a{flex:0 0 auto;width:96px}'
            . '.vn-mes .vn-mes-nav{flex:0 0 44px;width:44px;min-height:44px;padding:0;border:1px solid var(--line-strong,#d1d5db);border-radius:var(--radius,10px);background:var(--surface,#fff);color:var(--text,#191c1d);font:inherit;font-size:1.35rem;font-weight:800;line-height:1;cursor:pointer}'
            . '.vn-mes .vn-mes-nav:hover:not(:disabled){border-color:var(--primary,#2563eb);color:var(--primary-fg,#2563eb)}'
            . '.vn-mes .vn-mes-nav:disabled{opacity:.4;cursor:not-allowed}'
            . '.vn-mes select:focus-visible,.vn-mes .vn-mes-nav:focus-visible{outline:none;box-shadow:0 0 0 3px rgba(37,99,235,.28)}'
            . '@media(max-width:480px){.vn-mes select{font-size:16px;min-height:48px}.vn-mes .vn-mes-nav{min-height:48px}}'
            . '.vn-mes select{min-width:0}'
            . '@media(max-width:380px){.vn-mes{gap:4px}.vn-mes select{padding:8px 4px}.vn-mes .vn-mes-a{width:80px}.vn-mes .vn-mes-nav{flex-basis:40px;width:40px}}'
            . '</style>'
            . '<script>window.vnMes=window.vnMes||function(el,passo){'
            . 'var w=el.closest("[data-vn-mes]");if(!w)return;'
            . 'var sm=w.querySelector(".vn-mes-m"),sa=w.querySelector(".vn-mes-a"),h=w.querySelector("input[type=hidden]");'
            . 'if(passo){if(sm.value==="")return;'
            . 'var n=parseInt(sa.value,10)*12+parseInt(sm.value,10)-1+passo,a=Math.floor(n/12),m=n%12+1;if(a<2000||a>2100)return;'
            . 'var tem=false,i;for(i=0;i<sa.options.length;i++){if(+sa.options[i].value===a)tem=true;}'
            . 'if(!tem){var o=document.createElement("option");o.value=o.textContent=a;var pos=null;for(i=0;i<sa.options.length;i++){if(+sa.options[i].value>a){pos=sa.options[i];break;}}sa.insertBefore(o,pos);}'
            . 'sa.value=String(a);sm.value=(m<10?"0":"")+m;}'
            . 'h.value=sm.value===""?"":sa.value+"-"+sm.value;'
            . 'var t=w.querySelectorAll(".vn-mes-nav");for(var k=0;k<t.length;k++){t[k].disabled=sm.value==="";}'
            . 'sa.disabled=sm.value==="";'
            . 'var f=w.closest("form");if(w.getAttribute("data-auto")==="1"&&f){if(f.requestSubmit)f.requestSubmit();else f.submit();}'
            . '};</script>';
    }

    $anos = [];
    for ($a = $hoje - 10; $a <= $hoje + 5; $a++) $anos[$a] = true;
    $anos[$ano] = true;
    ksort($anos);

    $h .= '<div class="vn-mes-wrap">' . ($rotulo !== '' ? '<span class="vn-mes-rot">' . vn_h($rotulo) . '</span>' : '')
        . '<span class="vn-mes" data-vn-mes data-auto="' . ($auto ? '1' : '0') . '" role="group" aria-label="Escolher mês e ano">'
        . '<button type="button" class="vn-mes-nav" aria-label="Mês anterior" onclick="vnMes(this,-1)"' . ($selecionado === '' ? ' disabled' : '') . '>&#8249;</button>'
        . '<select class="vn-mes-m" aria-label="Mês" onchange="vnMes(this,0)">';
    if ($todos) $h .= '<option value=""' . ($selecionado === '' ? ' selected' : '') . '>' . vn_h($rotTodos) . '</option>';
    for ($m = 1; $m <= 12; $m++) {
        $v = sprintf('%02d', $m);
        $h .= '<option value="' . $v . '"' . ($v === $mesSel ? ' selected' : '') . '>' . vn_h(vn_mes_nome($m)) . '</option>';
    }
    $h .= '</select><select class="vn-mes-a" aria-label="Ano" onchange="vnMes(this,0)"' . ($selecionado === '' ? ' disabled' : '') . '>';
    foreach (array_keys($anos) as $a) $h .= '<option value="' . $a . '"' . ($a === $ano ? ' selected' : '') . '>' . $a . '</option>';
    $h .= '</select>'
        . '<button type="button" class="vn-mes-nav" aria-label="Próximo mês" onclick="vnMes(this,1)"' . ($selecionado === '' ? ' disabled' : '') . '>&#8250;</button>'
        . '<input type="hidden" name="' . vn_h($nome) . '" value="' . vn_h($selecionado) . '">'
        . '</span></div>';
    return $h;
}

function vn_month_filter(string $pagina, string $mes): void { ?>
    <form class="month-filter" method="get" action="<?php echo vn_h($pagina); ?>">
        <?php echo vn_mes_picker_html($mes); ?>
    </form>
<?php }

function vn_link($file): string {
    $files = is_array($file) ? $file : [$file];
    foreach ($files as $f) {
        $f = ltrim((string)$f, '/');
        if (is_file(__DIR__ . '/' . $f)) return $f;
        if (is_file(dirname(__DIR__) . '/' . $f)) return '../' . $f;
        if (is_file(dirname(__DIR__, 2) . '/' . $f)) return '../../' . $f;
    }
    return ltrim((string)$files[0], '/');
}

function vn_logo_src(): string {
    foreach (['vn-orcamento.png','vn.png','../vn.png','../../vn.png','../../../vn.png','assets/img/logo-vn-system.svg','logo.png','../logo.png','../../logo.png'] as $p) {
        if (is_file(__DIR__ . '/' . $p)) return $p;
    }
    return 'vn-orcamento.png';
}

function vn_abs_file_from_mobile(array $paths): string {
    foreach ($paths as $p) {
        $p = (string)$p;
        $try = preg_match('~^(/|[A-Za-z]:[\\\\/])~', $p) ? $p : (__DIR__ . '/' . ltrim($p, '/'));
        if (is_file($try)) return $try;
    }
    foreach ([__DIR__, dirname(__DIR__), dirname(__DIR__, 2), dirname(__DIR__, 3)] as $dir) {
        foreach ($paths as $p) {
            $try = $dir . '/' . basename((string)$p);
            if (is_file($try)) return $try;
        }
    }
    return '';
}

function vn_norm_key($v): string {
    $v = vn_lower($v);
    $v = preg_replace('/[^a-z0-9]+/', ' ', $v);
    return trim(preg_replace('/\s+/', ' ', $v));
}

function vn_tecnico_key(int $id, string $nome): string {
    $nomeKey = vn_norm_key($nome);
    if ($nomeKey !== '' && !preg_match('/^(funcionario|tecnico) [0-9]+$/', $nomeKey)) return 'nome:' . $nomeKey;
    return 'id:' . $id;
}

function vn_status_kind(string $status): string {
    $s = vn_lower($status);
    if ($s === '') return 'pendente';
    if (strpos($s, 'final') !== false || strpos($s, 'conclu') !== false || strpos($s, 'atendido') !== false || strpos($s, 'aprovado') !== false || strpos($s, 'encerr') !== false || $s === 'concluida') return 'concluida';
    if (strpos($s, 'andamento') !== false || strpos($s, 'execu') !== false || strpos($s, 'iniciada') !== false || strpos($s, 'rota') !== false) return 'em_andamento';
    if (strpos($s, 'cancel') !== false || strpos($s, 'recus') !== false || strpos($s, 'nao realizada') !== false || strpos($s, 'não realizada') !== false) return 'cancelada';
    return 'pendente';
}

function vn_orc_status_kind(string $status): string {
    $s = vn_lower($status);
    if (strpos($s, 'recus') !== false || strpos($s, 'cancel') !== false || strpos($s, 'perdido') !== false) return 'recusado';
    if (strpos($s, 'aguardando aprov') !== false) return 'pendente';   // "aguardando aprovação" ainda NÃO está aprovado
    if (strpos($s, 'aprov') !== false || strpos($s, 'aceit') !== false || strpos($s, 'fechado') !== false || strpos($s, 'ganho') !== false
        || strpos($s, 'andamento') !== false || strpos($s, 'aguardando pag') !== false || strpos($s, 'conclu') !== false || strpos($s, 'garantia') !== false) return 'aprovado';
    return 'pendente';
}

function vn_text_expr(string $table, array $cols, string $fallback = "''"): string {
    $parts = [];
    foreach ($cols as $c) if (vn_column_exists($table, $c)) $parts[] = "NULLIF(TRIM(`$c`), '')";
    if (!$parts) return $fallback;
    return count($parts) === 1 ? "COALESCE({$parts[0]}, $fallback)" : 'COALESCE(' . implode(', ', $parts) . ', ' . $fallback . ')';
}

function vn_date_expr(string $table, array $cols): ?string {
    $parts = [];
    foreach ($cols as $c) {
        if (vn_column_exists($table, $c)) $parts[] = "NULLIF(NULLIF(`$c`, '0000-00-00 00:00:00'), '0000-00-00')";
    }
    if (!$parts) return null;
    return count($parts) === 1 ? $parts[0] : 'COALESCE(' . implode(', ', $parts) . ')';
}

function vn_date_where_any(string $table, array $cols, string $inicio, string $fim, array &$params): string {
    $ors = [];
    foreach ($cols as $c) {
        if (vn_column_exists($table, $c)) {
            $ors[] = "(NULLIF(NULLIF(`$c`, '0000-00-00 00:00:00'), '0000-00-00') BETWEEN ? AND ?)";
            $params[] = $inicio;
            $params[] = $fim;
        }
    }
    return $ors ? '(' . implode(' OR ', $ors) . ')' : '1=1';
}

function vn_insert_dynamic(string $table, array $map): int {
    if (!vn_table_exists($table)) return 0;
    $cols = [];
    $params = [];
    foreach ($map as $col => $val) {
        if (vn_column_exists($table, $col)) { $cols[] = $col; $params[] = $val; }
    }
    if (!$cols) return 0;
    $sql = 'INSERT INTO `' . $table . '` (`' . implode('`, `', $cols) . '`) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
    vn_exec($sql, $params);
    return vn_last_id();
}

/* Tipos (linhas de trabalho) de orçamentos, peças e OS. */
function vn_categorias_orc(): array { return ['cftv' => 'CFTV', 'serralheria' => 'Serralheria']; }
function vn_categoria_valida($c, string $padrao = 'cftv'): string {
    $c = strtolower(trim((string)$c));
    return isset(vn_categorias_orc()[$c]) ? $c : $padrao;
}
function vn_categoria_rotulo($c): string { return vn_categorias_orc()[vn_categoria_valida($c)]; }
/* Linha de um item do catálogo: cftv | serralheria | geral. Serviço sem escolha vale para os dois ('geral'); peça sem escolha é de CFTV. */
function vn_linha_catalogo($linha, string $tipo): string {
    $l = strtolower(trim((string)$linha));
    if (in_array($l, ['cftv', 'serralheria', 'geral'], true)) {
        if ($l === 'geral' && !in_array($tipo, ['servico', 'mao_obra'], true)) return 'cftv';   // peça física tem de ser de uma linha só
        return $l;
    }
    return in_array($tipo, ['servico', 'mao_obra'], true) ? 'geral' : 'cftv';
}

function vn_ensure_mobile_tables(): void {
    static $feito = false;   // as tabelas só precisam ser conferidas uma vez por requisição
    if ($feito) return;
    vn_exec("CREATE TABLE IF NOT EXISTS vn_mobile_orcamentos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        numero VARCHAR(50) NULL,
        cliente_id INT NULL,
        cliente_nome VARCHAR(190) NULL,
        cliente_telefone VARCHAR(80) NULL,
        endereco TEXT NULL,
        status VARCHAR(50) NOT NULL DEFAULT 'Pendente',
        valor_total DECIMAL(12,2) NOT NULL DEFAULT 0,
        valor_custo DECIMAL(12,2) NOT NULL DEFAULT 0,
        desconto DECIMAL(12,2) NOT NULL DEFAULT 0,
        lucro DECIMAL(12,2) NOT NULL DEFAULT 0,
        observacoes TEXT NULL,
        criado_por INT NULL,
        criado_por_nome VARCHAR(160) NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        atualizado_em DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_vn_mobile_orc_mes (criado_em),
        INDEX idx_vn_mobile_orc_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    foreach ([
        'cliente_email' => "VARCHAR(160) NULL",
        'validade' => "DATE NULL",
        'visita_descricao' => "VARCHAR(180) NULL",
        'visita_valor' => "DECIMAL(12,2) NOT NULL DEFAULT 0",
        'mao_obra_descricao' => "VARCHAR(180) NULL",
        'mao_obra_valor' => "DECIMAL(12,2) NOT NULL DEFAULT 0",
        'mostrar_valor_itens' => "TINYINT(1) NOT NULL DEFAULT 1",
        'mostrar_itens' => "TINYINT(1) NOT NULL DEFAULT 1",
        'mostrar_valor_unitario' => "TINYINT(1) NOT NULL DEFAULT 1",
        'mostrar_subtotal_itens' => "TINYINT(1) NOT NULL DEFAULT 1",
        'mostrar_visita' => "TINYINT(1) NOT NULL DEFAULT 1",
        'mostrar_mao_obra' => "TINYINT(1) NOT NULL DEFAULT 1",
        'mostrar_desconto' => "TINYINT(1) NOT NULL DEFAULT 1",
        'mostrar_total_geral' => "TINYINT(1) NOT NULL DEFAULT 1"
        ,'assinatura_tecnico' => "TEXT NULL"
        // Tela de pedido nova (prazo, equipamento, pagamento, garantia, cláusulas, relatório, compromisso...)
        ,'prazo_execucao' => "VARCHAR(160) NULL"
        ,'equipamento' => "VARCHAR(190) NULL"
        ,'defeito' => "TEXT NULL"
        ,'condicoes_pagamento' => "TEXT NULL"
        ,'meios_pagamento' => "VARCHAR(255) NULL"
        ,'garantia' => "TEXT NULL"
        ,'clausulas' => "MEDIUMTEXT NULL"
        ,'anotacoes' => "TEXT NULL"
        ,'relatorio' => "TEXT NULL"
        ,'compromisso_data' => "DATE NULL"
        ,'compromisso_hora' => "VARCHAR(8) NULL"
        ,'compromisso_desc' => "VARCHAR(180) NULL"
        ,'visita_data' => "DATE NULL"
        ,'desconto_cfg' => "TEXT NULL"
        ,'mostrar_equipamento' => "TINYINT(1) NOT NULL DEFAULT 1"
        ,'mostrar_pagamento' => "TINYINT(1) NOT NULL DEFAULT 1"
        ,'mostrar_garantia' => "TINYINT(1) NOT NULL DEFAULT 1"
        ,'mostrar_relatorio' => "TINYINT(1) NOT NULL DEFAULT 1"
        ,'mostrar_fotos' => "TINYINT(1) NOT NULL DEFAULT 1"
        ,'mostrar_clausulas' => "TINYINT(1) NOT NULL DEFAULT 1"
        ,'categoria' => "VARCHAR(20) NOT NULL DEFAULT 'cftv'"      // tipo do orçamento: cftv | serralheria
    ] as $col => $def) {
        vn_add_col_if_missing('vn_mobile_orcamentos', $col, $def);
    }

    vn_exec("CREATE TABLE IF NOT EXISTS vn_mobile_orcamento_itens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        orcamento_id INT NOT NULL,
        descricao VARCHAR(255) NOT NULL,
        quantidade DECIMAL(12,2) NOT NULL DEFAULT 1,
        valor_unitario DECIMAL(12,2) NOT NULL DEFAULT 0,
        custo_unitario DECIMAL(12,2) NOT NULL DEFAULT 0,
        total DECIMAL(12,2) NOT NULL DEFAULT 0,
        custo_total DECIMAL(12,2) NOT NULL DEFAULT 0,
        INDEX idx_vn_mobile_orc_item (orcamento_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    vn_add_col_if_missing('vn_mobile_orcamento_itens', 'tipo', "VARCHAR(40) NOT NULL DEFAULT 'material'");
    vn_add_col_if_missing('vn_mobile_orcamento_itens', 'origem_peca', "VARCHAR(20) NOT NULL DEFAULT 'estoque'");
    vn_add_col_if_missing('vn_mobile_orcamento_itens', 'comprado', "TINYINT(1) NOT NULL DEFAULT 0");
    vn_add_col_if_missing('vn_mobile_orcamento_itens', 'linha', "VARCHAR(20) NOT NULL DEFAULT 'cftv'");   // peça de CFTV ou de serralheria
    vn_add_col_if_missing('vn_mobile_orcamento_itens', 'unidade', "VARCHAR(12) NULL DEFAULT NULL");        // medida em que o item é cobrado: m2, m, un, h, degrau, kg (serralheria por medida)

    vn_exec("CREATE TABLE IF NOT EXISTS vn_mobile_orcamento_fotos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        orcamento_id INT NOT NULL,
        caminho VARCHAR(255) NOT NULL,
        nome_original VARCHAR(190) NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_vn_orc_fotos (orcamento_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    vn_exec("CREATE TABLE IF NOT EXISTS vn_pedido_pagamentos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        orcamento_id INT NOT NULL,
        valor DECIMAL(12,2) NOT NULL DEFAULT 0,
        data DATE NULL,
        forma VARCHAR(60) NULL,
        obs VARCHAR(255) NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_vn_ped_pag (orcamento_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    vn_exec("CREATE TABLE IF NOT EXISTS vn_pedido_custos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        orcamento_id INT NOT NULL,
        descricao VARCHAR(190) NOT NULL,
        valor DECIMAL(12,2) NOT NULL DEFAULT 0,
        data DATE NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_vn_ped_cus (orcamento_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    vn_exec("CREATE TABLE IF NOT EXISTS vn_pedido_compromissos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        orcamento_id INT NOT NULL,
        titulo VARCHAR(190) NOT NULL,
        data DATE NOT NULL,
        hora VARCHAR(8) NULL,
        obs VARCHAR(255) NULL,
        feito TINYINT(1) NOT NULL DEFAULT 0,
        origem VARCHAR(10) NOT NULL DEFAULT 'agenda',
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_vn_ped_comp (orcamento_id),
        INDEX idx_vn_ped_comp_data (data)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    vn_exec("CREATE TABLE IF NOT EXISTS vn_pedido_padroes (
        chave VARCHAR(40) NOT NULL PRIMARY KEY,
        valor MEDIUMTEXT NULL,
        atualizado_em DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");


    vn_exec("CREATE TABLE IF NOT EXISTS vn_mobile_orcamento_catalogo (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tipo VARCHAR(40) NOT NULL DEFAULT 'material',
        nome VARCHAR(190) NOT NULL,
        descricao TEXT NULL,
        foto_url VARCHAR(255) NULL,
        unidade VARCHAR(30) NULL DEFAULT 'un',
        valor_unitario DECIMAL(12,2) NOT NULL DEFAULT 0,
        custo_unitario DECIMAL(12,2) NOT NULL DEFAULT 0,
        quantidade_estoque DECIMAL(12,2) NOT NULL DEFAULT 0,
        status VARCHAR(30) NOT NULL DEFAULT 'ativo',
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        atualizado_em DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_vn_orc_cat_nome (nome),
        INDEX idx_vn_orc_cat_status (status),
        INDEX idx_vn_orc_cat_tipo (tipo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    vn_add_col_if_missing('vn_mobile_orcamento_catalogo', 'foto_url', "VARCHAR(255) NULL");
    vn_add_col_if_missing('vn_mobile_orcamento_catalogo', 'quantidade_estoque', "DECIMAL(12,2) NOT NULL DEFAULT 0");
    vn_add_col_if_missing('vn_mobile_orcamento_catalogo', 'estoque_minimo', "DECIMAL(12,2) NULL DEFAULT NULL");
    // Linha do item: 'cftv', 'serralheria' ou 'geral' (serviço que vale para os dois). Os itens que já existiam eram todos de CFTV.
    $linhaNova = !vn_column_exists('vn_mobile_orcamento_catalogo', 'linha');
    vn_add_col_if_missing('vn_mobile_orcamento_catalogo', 'linha', "VARCHAR(20) NOT NULL DEFAULT 'geral'");
    if ($linhaNova) vn_exec("UPDATE vn_mobile_orcamento_catalogo SET linha = 'cftv' WHERE tipo NOT IN ('servico', 'mao_obra')");
    // Como o produto é cobrado: vazio = por unidade (quantidade simples) ou a medida (m2, m, degrau, h, kg): no orçamento a tela pede a medida e faz a conta.
    vn_add_col_if_missing('vn_mobile_orcamento_catalogo', 'calculo', "VARCHAR(12) NOT NULL DEFAULT ''");

    vn_exec("CREATE TABLE IF NOT EXISTS vn_mobile_os (
        id INT AUTO_INCREMENT PRIMARY KEY,
        codigo_os VARCHAR(60) NULL,
        cliente_id INT NULL,
        cliente_nome VARCHAR(190) NULL,
        endereco_id INT NULL,
        endereco TEXT NULL,
        tecnico_id INT NULL,
        tecnico_nome VARCHAR(190) NULL,
        status VARCHAR(50) NOT NULL DEFAULT 'Aberta',
        prioridade VARCHAR(50) NULL,
        problema TEXT NULL,
        observacoes TEXT NULL,
        data_agendada DATETIME NULL,
        criado_por INT NULL,
        criado_por_nome VARCHAR(160) NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        atualizado_em DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_vn_mobile_os_mes (criado_em),
        INDEX idx_vn_mobile_os_status (status),
        INDEX idx_vn_mobile_os_tec (tecnico_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    vn_add_col_if_missing('vn_mobile_os', 'linha_servico', "VARCHAR(20) NOT NULL DEFAULT 'cftv'");   // OS de CFTV (padrão) ou de serralheria
    $feito = true;
}

function vn_clientes_form(): array {
    $tables = ['clientes', 'cliente', 'clientes_admin', 'vn_clientes'];
    $table = '';
    foreach ($tables as $t) {
        if (vn_table_exists($t)) { $table = $t; break; }
    }
    if ($table === '') return [];

    $idCol = vn_first_col($table, ['id','cliente_id','id_cliente','codigo','cod_cliente']);
    if (!$idCol) return [];

    $nomeCols = ['nome','nome_cliente','razao_cliente','razao_social','fantasia','fantasia_cliente','cliente','apelido','empresa'];
    $nomeExpr = vn_text_expr($table, $nomeCols, "CONCAT('Cliente #', `$idCol`)");
    $telExpr = vn_text_expr($table, ['telefone','telefone_cliente','celular','celular_cliente','fone','contato','cliente_contato','whatsapp'], "''");
    $emailExpr = vn_text_expr($table, ['email','email_cliente','cliente_email'], "''");

    $endCols = [];
    foreach (['endereco','endereco_cliente','logradouro','logradouro_cliente','rua','rua_cliente','numero','numero_cliente','complemento','complemento_cliente','bairro','bairro_cliente','cidade','cidade_cliente','uf','uf_cliente','estado','cep','cep_cliente','referencia','referencia_cliente'] as $c) {
        if (vn_column_exists($table, $c)) $endCols[] = "NULLIF(TRIM(`$c`), '')";
    }
    $endExpr = $endCols ? 'CONCAT_WS(\' - \', ' . implode(', ', $endCols) . ')' : "''";

    $where = '';
    if (vn_column_exists($table, 'status')) {
        $where = "WHERE (status IS NULL OR status = '' OR LOWER(TRIM(status)) IN ('ativo','1','sim','s'))";
    }

    $rows = vn_fetch_all("SELECT `$idCol` AS id, $nomeExpr AS nome, $telExpr AS telefone, $emailExpr AS email, $endExpr AS endereco FROM `$table` $where ORDER BY nome ASC, `$idCol` ASC LIMIT 2000");
    $out = [];
    foreach ($rows as $r) {
        $id = (int)($r['id'] ?? 0);
        if ($id <= 0) continue;
        $nome = vn_clean($r['nome'] ?? '');
        if ($nome === '') $nome = 'Cliente #' . $id;
        $endereco = vn_clean($r['endereco'] ?? '');
        if ($endereco === '') $endereco = vn_buscar_endereco_cliente_por_id(0, $id);
        $out[] = [
            'id' => $id,
            'nome' => $nome,
            'telefone' => vn_clean($r['telefone'] ?? ''),
            'email' => vn_clean($r['email'] ?? ''),
            'endereco' => $endereco,
        ];
    }
    return $out;
}

function vn_enderecos_form(): array {
    $tables = ['enderecos_clientes', 'cliente_enderecos', 'enderecos'];
    $table = '';
    foreach ($tables as $t) {
        if (vn_table_exists($t)) { $table = $t; break; }
    }
    if ($table === '') return [];

    $idCol = vn_first_col($table, ['id','endereco_id','id_endereco']);
    $clienteCol = vn_first_col($table, ['cliente_id','id_cliente','cliente']);
    if (!$idCol || !$clienteCol) return [];

    $labelExpr = vn_text_expr($table, ['nome_endereco','titulo','apelido','referencia','referencia_cliente'], "'Endereço de atendimento'");
    $partes = [];
    foreach (['endereco','endereco_cliente','logradouro','logradouro_cliente','rua','rua_cliente','numero','numero_cliente','complemento','complemento_cliente','bairro','bairro_cliente','cidade','cidade_cliente','uf','uf_cliente','estado','cep','cep_cliente','referencia','referencia_cliente'] as $c) {
        if (vn_column_exists($table, $c)) $partes[] = "NULLIF(TRIM(`$c`), '')";
    }
    $valorExpr = $partes ? 'CONCAT_WS(\' - \', ' . implode(', ', $partes) . ')' : "''";
    $where = '';
    if (vn_column_exists($table, 'status')) {
        $where = "WHERE (status IS NULL OR status = '' OR LOWER(TRIM(status)) IN ('ativo','1','sim','s'))";
    }
    $order = [];
    if (vn_column_exists($table, 'principal')) $order[] = 'principal DESC';
    $order[] = "`$idCol` ASC";
    return vn_fetch_all("SELECT `$idCol` AS id, `$clienteCol` AS cliente_id, $labelExpr AS label, $valorExpr AS endereco FROM `$table` $where ORDER BY `$clienteCol` ASC, " . implode(', ', $order) . " LIMIT 3000");
}

function vn_tecnicos_form(): array { return vn_tecnicos_base(); }

function vn_tecnicos_base(): array {
    $out = [];
    $seenByName = [];

    $addTec = function(int $idTec, int $adminId, string $nome) use (&$out, &$seenByName) {
        $idTec = (int)$idTec;
        $adminId = (int)$adminId;
        $nome = vn_clean($nome);
        if ($idTec <= 0 && $adminId > 0) $idTec = $adminId;
        if ($idTec <= 0) return;
        if ($nome === '') $nome = 'Técnico #' . $idTec;

        $nameKey = vn_norm_key($nome);
        $idKey = 'tec_' . $idTec;

        if ($nameKey !== '' && isset($seenByName[$nameKey])) {
            $oldKey = $seenByName[$nameKey];
            if (isset($out[$oldKey])) {
                if (($out[$oldKey]['admin_id'] ?? 0) <= 0 && $adminId > 0) $out[$oldKey]['admin_id'] = $adminId;
                if (($out[$oldKey]['id'] ?? 0) <= 0 && $idTec > 0) $out[$oldKey]['id'] = $idTec;
            }
            return;
        }

        if (isset($out[$idKey])) {
            if ($nameKey !== '') $seenByName[$nameKey] = $idKey;
            return;
        }

        $out[$idKey] = [
            'id'=>$idTec,
            'admin_id'=>$adminId,
            'nome'=>$nome,
            'meta'=>20,
            'feitas'=>0,
            'pendentes'=>0,
            'andamento'=>0,
            'canceladas'=>0
        ];
        if ($nameKey !== '') $seenByName[$nameKey] = $idKey;
    };

    if (vn_table_exists('admins') && vn_column_exists('admins', 'id')) {
        $cols = ['id'];
        foreach (['id_tec','nome','usuario','cargo','status'] as $c) if (vn_column_exists('admins', $c)) $cols[] = $c;
        $where = [];
        if (vn_column_exists('admins', 'cargo')) $where[] = "(cargo IS NULL OR cargo = '' OR LOWER(TRIM(cargo)) IN ('admin','funcionario','colaborador','tecnico','técnico'))";
        if (vn_column_exists('admins', 'status')) $where[] = "(status IS NULL OR status = '' OR LOWER(status) IN ('ativo','1','sim','s'))";
        $order = vn_column_exists('admins', 'nome') ? 'nome' : (vn_column_exists('admins', 'usuario') ? 'usuario' : 'id');
        $sql = 'SELECT `' . implode('`, `', array_unique($cols)) . '` FROM admins ' . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . " ORDER BY `$order` ASC, id ASC";
        foreach (vn_fetch_all($sql) as $r) {
            $adminId = (int)($r['id'] ?? 0);
            $idTec = (int)($r['id_tec'] ?? 0);
            if ($idTec <= 0) $idTec = $adminId;
            $nome = vn_clean($r['nome'] ?? ($r['usuario'] ?? ('Técnico #' . $idTec)));
            $addTec($idTec, $adminId, $nome);
        }
    }

    if (vn_table_exists('tecnicos') && vn_column_exists('tecnicos','id')) {
        $cols = ['id'];
        foreach (['nome','usuario','nome_tecnico','status'] as $c) if (vn_column_exists('tecnicos', $c)) $cols[] = $c;
        $where = vn_column_exists('tecnicos','status') ? "WHERE (status IS NULL OR status = '' OR LOWER(status) IN ('ativo','1','sim','s'))" : '';
        $order = vn_column_exists('tecnicos','nome') ? 'nome' : 'id';
        $sql = 'SELECT `' . implode('`, `', array_unique($cols)) . "` FROM tecnicos $where ORDER BY `$order` ASC, id ASC";
        foreach (vn_fetch_all($sql) as $r) {
            $idTec = (int)($r['id'] ?? 0);
            $nome = vn_clean($r['nome'] ?? ($r['nome_tecnico'] ?? ($r['usuario'] ?? ('Técnico #' . $idTec))));
            $addTec($idTec, 0, $nome);
        }
    }

    return array_values($out);
}

function vn_os_month_stats(bool $somenteUsuario = false, ?string $mes = null): array {
    global $VN_CARGO, $VN_ID_TEC, $VN_USUARIO_ID;
    [$inicio, $fim, $mesVal, $mesLabel] = vn_mes_range($mes);
    $stats = [
        'mes'=>$mesVal,'mes_label'=>$mesLabel,'total_mes'=>0,'concluidas_mes'=>0,'pendentes_mes'=>0,'andamento_mes'=>0,'canceladas_mes'=>0,
        'funcionarios'=>[],'recentes'=>[],'fonte'=>'Nenhuma tabela encontrada'
    ];
    foreach (vn_tecnicos_base() as $t) $stats['funcionarios'][$t['id']] = $t;

    $sources = [];
    if (vn_table_exists('os_visitas')) $sources[] = 'os_visitas';
    if (vn_table_exists('ordens_servico')) $sources[] = 'ordens_servico';
    if (vn_table_exists('vn_mobile_os')) $sources[] = 'vn_mobile_os';
    if (!$sources) return $stats;
    $stats['fonte'] = implode(' + ', $sources);

    foreach ($sources as $table) {
        if ($table === 'os_visitas') {
            $idCol = vn_first_col($table, ['id']);
            $dateCandidates = ['criado_em','data_abertura','agendado_para','atualizado_em','checkin_em','checkout_em','finalizado_em'];
            $dataExpr = vn_date_expr($table, ['checkout_em','finalizado_em','atualizado_em','checkin_em','criado_em','data_abertura','agendado_para']);
            $statusExpr = vn_text_expr($table, ['status_visita','status'], "''");
            $tecCol = vn_first_col($table, ['funcionario_id','id_tec','tecnico_id','tecnico_admin_id']);
            $nomeExpr = vn_text_expr($table, ['funcionario_nome','tecnico_nome','vendedor_pedido'], "''");
            $tituloExpr = vn_text_expr($table, ['titulo','codigo_os','ordem_codigo','ordem_id_local'], "CONCAT('OS #', `$idCol`)");
            $clienteExpr = vn_text_expr($table, ['cliente_nome','nome_cliente'], "''");
        } elseif ($table === 'vn_mobile_os') {
            $idCol = 'id';
            $dateCandidates = ['criado_em','data_agendada','atualizado_em'];
            $dataExpr = vn_date_expr($table, ['atualizado_em','data_agendada','criado_em']);
            $statusExpr = vn_text_expr($table, ['status'], "'Aberta'");
            $tecCol = vn_first_col($table, ['tecnico_id']);
            $nomeExpr = vn_text_expr($table, ['tecnico_nome'], "''");
            $tituloExpr = vn_text_expr($table, ['codigo_os','problema'], "CONCAT('OS #', `id`)");
            $clienteExpr = vn_text_expr($table, ['cliente_nome'], "''");
        } else {
            $idCol = vn_first_col($table, ['id']);
            $dateCandidates = ['data_abertura','criado_em','data_pedido','data_agendada','atualizado_em','updated_at','data_finalizacao','finalizado_em','data_conclusao','data_encerramento','data_realizacao'];
            $dataExpr = vn_date_expr($table, ['data_finalizacao','finalizado_em','data_conclusao','data_encerramento','data_realizacao','atualizado_em','updated_at','data_abertura','data_pedido','criado_em','data_agendada']);
            $statusExpr = vn_text_expr($table, ['status_visita','status','status_pedido','situacao'], "''");
            $tecCol = vn_first_col($table, ['id_tec','tecnico_id','tecnico_admin_id','vendedor_pedido_id','funcionario_id','criado_por']);
            $nomeExpr = vn_text_expr($table, ['funcionario_nome','tecnico_nome','vendedor_pedido','criado_por_nome'], "''");
            $tituloExpr = vn_text_expr($table, ['codigo_os','numero','referencia_ordem','problema_ordem','problema_relatado_cliente'], "CONCAT('OS #', `$idCol`)");
            $clienteExpr = vn_text_expr($table, ['cliente_nome','nome_cliente','razao_cliente'], "''");
        }
        if (!$idCol || !$dataExpr) continue;
        $tecExpr = $tecCol ? "`$tecCol`" : '0';
        $whereParams = [];
        $where = [vn_date_where_any($table, $dateCandidates, $inicio, $fim, $whereParams)];
        $params = $whereParams;
        if (($somenteUsuario || $VN_CARGO !== 'admin') && $tecCol) {
            $where[] = "`$tecCol` IN (?, ?)";
            $params[] = (int)$VN_ID_TEC;
            $params[] = (int)$VN_USUARIO_ID;
        }
        $sql = "SELECT `$idCol` AS id, $dataExpr AS data_ref, $statusExpr AS status_ref, $tecExpr AS tecnico_id, $nomeExpr AS tecnico_nome, $tituloExpr AS titulo, $clienteExpr AS cliente FROM `$table` WHERE " . implode(' AND ', $where) . " ORDER BY data_ref DESC LIMIT 1200";
        foreach (vn_fetch_all($sql, $params) as $r) {
            $stats['total_mes']++;
            $kind = vn_status_kind((string)($r['status_ref'] ?? ''));
            if ($kind === 'concluida') $stats['concluidas_mes']++;
            elseif ($kind === 'em_andamento') $stats['andamento_mes']++;
            elseif ($kind === 'cancelada') $stats['canceladas_mes']++;
            else $stats['pendentes_mes']++;

            $tecId = (int)($r['tecnico_id'] ?? 0);
            $tecNome = vn_clean($r['tecnico_nome'] ?? '');
            if ($tecNome === '') $tecNome = $tecId > 0 ? ('Funcionário #' . $tecId) : 'Sem técnico';
            if (!isset($stats['funcionarios'][$tecId])) $stats['funcionarios'][$tecId] = ['id'=>$tecId,'admin_id'=>0,'nome'=>$tecNome,'meta'=>20,'feitas'=>0,'pendentes'=>0,'andamento'=>0,'canceladas'=>0];
            if ($kind === 'concluida') $stats['funcionarios'][$tecId]['feitas']++;
            elseif ($kind === 'em_andamento') $stats['funcionarios'][$tecId]['andamento']++;
            elseif ($kind === 'cancelada') $stats['funcionarios'][$tecId]['canceladas']++;
            else $stats['funcionarios'][$tecId]['pendentes']++;

            if (count($stats['recentes']) < 12) {
                $stats['recentes'][] = [
                    'id'=>(int)($r['id'] ?? 0), 'titulo'=>vn_clean($r['titulo'] ?? ('OS #' . ($r['id'] ?? ''))),
                    'cliente'=>vn_clean($r['cliente'] ?? ''), 'tecnico'=>$tecNome, 'status'=>(string)($r['status_ref'] ?? ''),
                    'kind'=>$kind, 'data'=>(string)($r['data_ref'] ?? ''), 'fonte'=>$table
                ];
            }
        }
    }
    $agrupadosFuncionarios = [];
    foreach ($stats['funcionarios'] as $f) {
        $key = vn_tecnico_key((int)($f['id'] ?? 0), (string)($f['nome'] ?? ''));
        if (!isset($agrupadosFuncionarios[$key])) {
            $agrupadosFuncionarios[$key] = $f;
            continue;
        }
        foreach (['feitas','pendentes','andamento','canceladas'] as $campo) {
            $agrupadosFuncionarios[$key][$campo] = (int)($agrupadosFuncionarios[$key][$campo] ?? 0) + (int)($f[$campo] ?? 0);
        }
        if (!empty($f['nome']) && preg_match('/^(funcionário|funcionario|técnico|tecnico) #?[0-9]+$/i', (string)($agrupadosFuncionarios[$key]['nome'] ?? ''))) {
            $agrupadosFuncionarios[$key]['nome'] = $f['nome'];
        }
    }
    $stats['funcionarios'] = array_values($agrupadosFuncionarios);

    usort($stats['funcionarios'], function($a,$b){
        if ((int)$b['feitas'] === (int)$a['feitas']) return strcmp((string)$a['nome'], (string)$b['nome']);
        return (int)$b['feitas'] <=> (int)$a['feitas'];
    });
    usort($stats['recentes'], function($a,$b){ return strcmp((string)$b['data'], (string)$a['data']); });
    $stats['recentes'] = array_slice($stats['recentes'], 0, 10);
    return $stats;
}

function vn_orcamento_stats(?string $mes = null): array {
    [$inicio, $fim, $mesVal, $mesLabel] = vn_mes_range($mes);
    $stats = [
        'mes'=>$mesVal,'mes_label'=>$mesLabel,'total_mes'=>0,'valor_total'=>0,'custo_total'=>0,'lucro_total'=>0,
        'aprovados'=>0,'valor_aprovado'=>0,'custo_aprovado'=>0,'lucro_aprovado'=>0,
        'pendentes'=>0,'valor_pendente'=>0,'custo_pendente'=>0,'lucro_pendente'=>0,
        'recusados'=>0,'valor_recusado'=>0,'custo_recusado'=>0,'lucro_recusado'=>0,
        'recentes'=>[],'fonte'=>'Nenhuma tabela encontrada'
    ];
    $sources = [];
    if (vn_table_exists('orcamentos')) $sources[] = 'orcamentos';
    if (vn_table_exists('vn_mobile_orcamentos')) $sources[] = 'vn_mobile_orcamentos';
    if (!$sources) return $stats;
    $stats['fonte'] = implode(' + ', $sources);

    foreach ($sources as $table) {
        $idCol = vn_first_col($table, ['id']);
        $dataCol = vn_first_col($table, ['criado_em','data_cadastro','data','atualizado_em','updated_at']);
        if (!$idCol || !$dataCol) continue;
        $statusExpr = vn_text_expr($table, ['status','situacao'], "'Pendente'");
        $totalCol = vn_first_col($table, ['valor_total','total_geral','total','valor']);
        $custoCol = vn_first_col($table, ['valor_custo','total_custo','custo_total','custo']);
        $lucroCol = vn_first_col($table, ['lucro','valor_lucro','lucro_total']);
        $numeroExpr = vn_text_expr($table, ['numero','codigo'], "CONCAT('ORC #', `$idCol`)");
        $clienteExpr = vn_text_expr($table, ['cliente_nome','nome_cliente','cliente'], "''");
        $totalExpr = $totalCol ? "`$totalCol`" : '0';
        $custoExpr = $custoCol ? "`$custoCol`" : '0';
        $lucroExpr = $lucroCol ? "`$lucroCol`" : "($totalExpr - $custoExpr)";
        $rows = vn_fetch_all("SELECT `$idCol` AS id, `$dataCol` AS data_ref, $statusExpr AS status_ref, $totalExpr AS total_ref, $custoExpr AS custo_ref, $lucroExpr AS lucro_ref, $numeroExpr AS numero, $clienteExpr AS cliente FROM `$table` WHERE `$dataCol` BETWEEN ? AND ? ORDER BY `$dataCol` DESC LIMIT 1200", [$inicio, $fim]);
        foreach ($rows as $r) {
            $valor = (float)($r['total_ref'] ?? 0);
            $custo = (float)($r['custo_ref'] ?? 0);
            $lucro = (float)($r['lucro_ref'] ?? ($valor - $custo));
            $kind = vn_orc_status_kind((string)($r['status_ref'] ?? ''));
            $stats['total_mes']++; $stats['valor_total'] += $valor; $stats['custo_total'] += $custo; $stats['lucro_total'] += $lucro;
            if ($kind === 'aprovado') { $stats['aprovados']++; $stats['valor_aprovado'] += $valor; $stats['custo_aprovado'] += $custo; $stats['lucro_aprovado'] += $lucro; }
            elseif ($kind === 'recusado') { $stats['recusados']++; $stats['valor_recusado'] += $valor; $stats['custo_recusado'] += $custo; $stats['lucro_recusado'] += $lucro; }
            else { $stats['pendentes']++; $stats['valor_pendente'] += $valor; $stats['custo_pendente'] += $custo; $stats['lucro_pendente'] += $lucro; }
            if (count($stats['recentes']) < 12) {
                $stats['recentes'][] = ['id'=>(int)$r['id'],'numero'=>vn_clean($r['numero'] ?? ('ORC #' . $r['id'])),'cliente'=>vn_clean($r['cliente'] ?? ''),'status'=>(string)$r['status_ref'],'kind'=>$kind,'valor'=>$valor,'custo'=>$custo,'lucro'=>$lucro,'data'=>(string)$r['data_ref'],'fonte'=>$table];
            }
        }
    }
    usort($stats['recentes'], function($a,$b){ return strcmp((string)$b['data'], (string)$a['data']); });
    $stats['recentes'] = array_slice($stats['recentes'], 0, 10);
    return $stats;
}

function vn_buscar_endereco_cliente_por_id(int $enderecoId = 0, int $clienteId = 0): string {
    if (!vn_table_exists('enderecos_clientes')) return '';
    $idCol = vn_first_col('enderecos_clientes', ['id']);
    $clienteCol = vn_first_col('enderecos_clientes', ['cliente_id','id_cliente']);
    if (!$idCol || !$clienteCol) return '';
    $partes = [];
    foreach (['endereco','endereco_cliente','logradouro','logradouro_cliente','rua','rua_cliente','numero','numero_cliente','complemento','complemento_cliente','bairro','bairro_cliente','cidade','cidade_cliente','uf','uf_cliente','cep','cep_cliente','referencia','referencia_cliente'] as $c) {
        if (vn_column_exists('enderecos_clientes', $c)) $partes[] = "NULLIF(TRIM(`$c`), '')";
    }
    $expr = $partes ? 'CONCAT_WS(\' - \', ' . implode(', ', $partes) . ')' : "''";
    if ($enderecoId > 0) {
        $r = vn_fetch_one("SELECT $expr AS endereco FROM enderecos_clientes WHERE `$idCol` = ? LIMIT 1", [$enderecoId]);
        if (!empty($r['endereco'])) return vn_clean($r['endereco']);
    }
    if ($clienteId > 0) {
        $r = vn_fetch_one("SELECT $expr AS endereco FROM enderecos_clientes WHERE `$clienteCol` = ? ORDER BY `$idCol` ASC LIMIT 1", [$clienteId]);
        if (!empty($r['endereco'])) return vn_clean($r['endereco']);
    }
    return '';
}

function vn_salvar_orcamento_mobile(array $post): int {
    global $VN_USUARIO_ID, $VN_USUARIO_NOME;
    vn_ensure_mobile_tables();
    $id = (int)($post['id_orcamento'] ?? 0);
    $clienteId = (int)($post['cliente_id'] ?? 0);
    $enderecoId = (int)($post['endereco_id'] ?? 0);
    $clienteNome = vn_clean($post['cliente_nome'] ?? '');
    $telefone = vn_clean($post['cliente_telefone'] ?? '');
    $email = vn_clean($post['cliente_email'] ?? '');
    $endereco = vn_clean($post['endereco'] ?? '');
    if ($endereco === '') $endereco = vn_buscar_endereco_cliente_por_id($enderecoId, $clienteId);
    $status = vn_clean($post['status'] ?? 'Pendente') ?: 'Pendente';
    $desconto = vn_money_db($post['desconto'] ?? 0);
    $validade = trim((string)($post['validade'] ?? ''));
    $validadeDb = vn_date_db_or_null($validade);
    $visitaDesc = vn_clean($post['visita_descricao'] ?? 'Visita técnica');
    $visitaValor = vn_money_db($post['visita_valor'] ?? 0);
    $maoDesc = vn_clean($post['mao_obra_descricao'] ?? 'Mão de obra');
    $maoValor = vn_money_db($post['mao_obra_valor'] ?? 0);
    $obs = trim((string)($post['observacoes'] ?? ''));
    $assinaturaTecnico = trim((string)($post['assinatura_tecnico'] ?? ''));
    $desc = $post['item_desc'] ?? [];
    $tipos = $post['item_tipo'] ?? [];
    $qtds = $post['item_qtd'] ?? [];
    $precos = $post['item_preco'] ?? [];
    $custos = $post['item_custo'] ?? [];
    $itens = [];
    $subtotal = 0; $custoTotal = 0;
    foreach ($desc as $i => $d) {
        $d = vn_clean($d);
        if ($d === '') continue;
        $tipo = vn_clean($tipos[$i] ?? 'material');
        if (!in_array($tipo, ['material','equipamento','servico','outro'], true)) $tipo = 'material';
        $q = max(0.01, (float)str_replace(',', '.', (string)($qtds[$i] ?? 1)));
        $p = vn_money_db($precos[$i] ?? 0);
        $c = vn_money_db($custos[$i] ?? 0);
        $t = round($q * $p, 2);
        $ct = round($q * $c, 2);
        $subtotal += $t; $custoTotal += $ct;
        $itens[] = [$tipo,$d,$q,$p,$c,$t,$ct];
    }
    $total = max(0, $subtotal + $visitaValor + $maoValor - $desconto);
    $lucro = $total - $custoTotal;

    $mostrarValorItens = isset($post['mostrar_valor_itens']) ? 1 : 0;
    $mostrarValorUnitario = isset($post['mostrar_valor_unitario']) ? 1 : 0;
    $mostrarSubtotalItens = isset($post['mostrar_subtotal_itens']) ? 1 : 0;
    $mostrarVisita = isset($post['mostrar_visita']) ? 1 : 0;
    $mostrarMaoObra = isset($post['mostrar_mao_obra']) ? 1 : 0;
    $mostrarDesconto = isset($post['mostrar_desconto']) ? 1 : 0;
    $mostrarTotalGeral = isset($post['mostrar_total_geral']) ? 1 : 0;

    if ($id > 0) {
        vn_exec('UPDATE vn_mobile_orcamentos SET cliente_id=?, cliente_nome=?, cliente_telefone=?, cliente_email=?, endereco=?, status=?, valor_total=?, valor_custo=?, desconto=?, lucro=?, observacoes=?, validade=?, visita_descricao=?, visita_valor=?, mao_obra_descricao=?, mao_obra_valor=?, mostrar_valor_itens=?, mostrar_valor_unitario=?, mostrar_subtotal_itens=?, mostrar_visita=?, mostrar_mao_obra=?, mostrar_desconto=?, mostrar_total_geral=?, atualizado_em=NOW() WHERE id=? LIMIT 1', [$clienteId ?: null, $clienteNome, $telefone, $email, $endereco, $status, $total, $custoTotal, $desconto, $lucro, $obs, $validadeDb, $visitaDesc, $visitaValor, $maoDesc, $maoValor, $mostrarValorItens, $mostrarValorUnitario, $mostrarSubtotalItens, $mostrarVisita, $mostrarMaoObra, $mostrarDesconto, $mostrarTotalGeral, $id]);
        vn_exec('DELETE FROM vn_mobile_orcamento_itens WHERE orcamento_id = ?', [$id]);
        $orcId = $id;
    } else {
        $numero = 'ORC-MOB-' . date('Ymd-His');
        vn_exec('INSERT INTO vn_mobile_orcamentos (numero, cliente_id, cliente_nome, cliente_telefone, cliente_email, endereco, status, valor_total, valor_custo, desconto, lucro, observacoes, validade, visita_descricao, visita_valor, mao_obra_descricao, mao_obra_valor, mostrar_valor_itens, mostrar_valor_unitario, mostrar_subtotal_itens, mostrar_visita, mostrar_mao_obra, mostrar_desconto, mostrar_total_geral, criado_por, criado_por_nome, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())', [$numero, $clienteId ?: null, $clienteNome, $telefone, $email, $endereco, $status, $total, $custoTotal, $desconto, $lucro, $obs, $validadeDb, $visitaDesc, $visitaValor, $maoDesc, $maoValor, $mostrarValorItens, $mostrarValorUnitario, $mostrarSubtotalItens, $mostrarVisita, $mostrarMaoObra, $mostrarDesconto, $mostrarTotalGeral, $VN_USUARIO_ID, $VN_USUARIO_NOME]);
        $orcId = vn_last_id();
    }
    foreach ($itens as $it) {
        vn_exec('INSERT INTO vn_mobile_orcamento_itens (orcamento_id, tipo, descricao, quantidade, valor_unitario, custo_unitario, total, custo_total) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$orcId, $it[0], $it[1], $it[2], $it[3], $it[4], $it[5], $it[6]]);
    }
    return $orcId;
}

function vn_salvar_os_mobile(array $post): array {
    global $VN_USUARIO_ID, $VN_USUARIO_NOME;
    vn_ensure_mobile_tables();
    $clienteId = (int)($post['cliente_id'] ?? 0);
    $enderecoId = (int)($post['endereco_id'] ?? 0);
    $clienteNome = vn_clean($post['cliente_nome'] ?? '');
    $enderecoId = (int)($post['endereco_id'] ?? 0);
    $endereco = vn_clean($post['endereco'] ?? '');
    $tecId = (int)($post['tecnico_id'] ?? 0);
    $tecNome = vn_clean($post['tecnico_nome'] ?? '');
    $prioridade = vn_clean($post['prioridade'] ?? 'Normal');
    $problema = trim((string)($post['problema'] ?? ''));
    $obs = trim((string)($post['observacoes'] ?? ''));
    $dataAgendada = trim((string)($post['data_agendada'] ?? ''));
    $dataAgendadaDb = $dataAgendada ? date('Y-m-d H:i:s', strtotime($dataAgendada)) : null;
    $codigo = 'OS-MOB-' . date('Ymd-His');
    $linhaServico = (($post['linha_servico'] ?? '') === 'serralheria') ? 'serralheria' : 'cftv';   // OS de CFTV (padrão) ou de serralheria

    vn_exec('INSERT INTO vn_mobile_os (codigo_os, cliente_id, cliente_nome, endereco_id, endereco, tecnico_id, tecnico_nome, status, prioridade, problema, observacoes, data_agendada, criado_por, criado_por_nome, criado_em, linha_servico) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$codigo, $clienteId ?: null, $clienteNome, $enderecoId ?: null, $endereco, $tecId ?: null, $tecNome, 'Aberta', $prioridade, $problema, $obs, $dataAgendadaDb, $VN_USUARIO_ID, $VN_USUARIO_NOME, vn_now_sp(), $linhaServico]);
    $mobileId = vn_last_id();

    $ordemId = 0;
    $avisos = [];
    if (vn_table_exists('ordens_servico')) {
        vn_add_col_if_missing('ordens_servico', 'linha_servico', "VARCHAR(20) NOT NULL DEFAULT 'cftv'");
        $map = [
            'linha_servico'=>$linhaServico,
            'codigo_os'=>$codigo, 'numero'=>$codigo, 'cliente_id'=>$clienteId ?: null, 'endereco_id'=>$enderecoId ?: null,
            'status'=>'aberta', 'status_pedido'=>'Em Aberto', 'situacao'=>'Aberta', 'status_visita'=>'pendente',
            'problema_relatado_cliente'=>$problema, 'problema_ordem'=>$problema, 'referencia_ordem'=>$endereco, 'local_atendimento'=>$endereco,
            'observacao_interna'=>$obs, 'obs_interno_pedido'=>$obs, 'prioridade'=>$prioridade, 'urgente'=>vn_lower($prioridade) === 'urgente' ? 1 : 0,
            'id_tec'=>$tecId ?: null, 'vendedor_pedido_id'=>$tecId ?: null, 'funcionario_id'=>$tecId ?: null,
            'vendedor_pedido'=>$tecNome, 'tecnico_nome'=>$tecNome, 'funcionario_nome'=>$tecNome,
            'criado_por'=>$VN_USUARIO_ID ?: null, 'criado_por_nome'=>$VN_USUARIO_NOME,
            'data_abertura'=>vn_now_sp(), 'data_pedido'=>vn_today_sp(), 'criado_em'=>vn_now_sp(), 'data_agendada'=>$dataAgendadaDb
        ];
        if (vn_column_exists('ordens_servico', 'tecnico_id')) {
            $podeTecId = true;
            if (vn_table_exists('tecnicos') && vn_column_exists('tecnicos', 'id') && $tecId > 0) {
                $podeTecId = (bool)vn_fetch_one('SELECT id FROM tecnicos WHERE id = ? LIMIT 1', [$tecId]);
            }
            if ($podeTecId) $map['tecnico_id'] = $tecId ?: null;
        }
        try { $ordemId = vn_insert_dynamic('ordens_servico', $map); }
        catch (Throwable $e) { $avisos[] = 'Foi salva na tabela mobile, mas não consegui espelhar em ordens_servico: ' . $e->getMessage(); }
    }
    if (vn_table_exists('os_visitas')) {
        vn_add_col_if_missing('os_visitas', 'linha_servico', "VARCHAR(20) NOT NULL DEFAULT 'cftv'");
        $mapVis = [
            'linha_servico'=>$linhaServico,
            'ordem_id_local'=>$ordemId ?: null, 'codigo_os'=>$codigo, 'status_visita'=>'pendente', 'status'=>'Aberta',
            'funcionario_id'=>$tecId ?: null, 'funcionario_nome'=>$tecNome, 'tecnico_id'=>$tecId ?: null, 'tecnico_nome'=>$tecNome,
            'cliente_nome'=>$clienteNome, 'endereco'=>$endereco, 'problema'=>$problema, 'comentario'=>'', 'criado_em'=>vn_now_sp(), 'atualizado_em'=>vn_now_sp()
        ];
        try { vn_insert_dynamic('os_visitas', $mapVis); }
        catch (Throwable $e) { $avisos[] = 'OS criada, mas não consegui criar visita automaticamente: ' . $e->getMessage(); }
    }
    return ['mobile_id'=>$mobileId, 'ordem_id'=>$ordemId, 'codigo'=>$codigo, 'avisos'=>$avisos];
}

/* Cabeçalho/rodapé das telas "mobile v6": o HTML vem do design system (_vn_ui.php). */
function vn_top_header(string $titulo, string $pagina = 'inicio'): void {
    require_once __DIR__ . '/_vn_ui.php';
    vn_ui_shell_start($titulo, $pagina, ['legacy' => 'mobile', 'charts' => true]);
}

function vn_bottom_nav(string $active = 'inicio'): void {
    vn_ui_shell_end($active);
}

function vn_card_link(string $href, string $icon, string $title, string $desc, string $class = ''): void { ?>
    <a class="shortcut-card <?php echo vn_h($class); ?>" href="<?php echo vn_h($href); ?>"><span class="shortcut-icon"><?php echo $icon; ?></span><strong><?php echo vn_h($title); ?></strong><small><?php echo vn_h($desc); ?></small></a>
<?php }
function vn_badge(string $kind, string $text): string { return '<span class="badge badge-' . vn_h($kind) . '">' . vn_h($text) . '</span>'; }
?><?php
/* ==========================
   VN Mobile V5 - Orçamentos: editar, excluir, PDF melhorado e técnicos sem duplicar
========================== */
function vn_orcamento_mobile_get_v4(int $id): ?array {
    vn_ensure_mobile_tables();
    if ($id <= 0) return null;
    $orc = vn_fetch_one('SELECT * FROM vn_mobile_orcamentos WHERE id = ? LIMIT 1', [$id]);
    if (!$orc) return null;
    $orc['itens'] = vn_fetch_all('SELECT * FROM vn_mobile_orcamento_itens WHERE orcamento_id = ? ORDER BY id ASC', [$id]);
    $orc['fotos'] = vn_fetch_all('SELECT * FROM vn_mobile_orcamento_fotos WHERE orcamento_id = ? ORDER BY id ASC', [$id]);
    return $orc;
}

function vn_excluir_orcamento_mobile_v4(int $id): void {
    vn_ensure_mobile_tables();
    if ($id <= 0) throw new Exception('ID do orçamento inválido.');
    foreach (vn_fetch_all('SELECT caminho FROM vn_mobile_orcamento_fotos WHERE orcamento_id = ?', [$id]) as $f) {
        $arq = __DIR__ . '/' . ltrim((string)$f['caminho'], '/');
        if (strpos(realpath(dirname($arq)) ?: '', realpath(__DIR__ . '/uploads/orcamentos') ?: "\0") === 0 && is_file($arq)) @unlink($arq);
    }
    vn_exec('DELETE FROM vn_mobile_orcamento_fotos WHERE orcamento_id = ?', [$id]);
    foreach (['vn_pedido_pagamentos', 'vn_pedido_custos', 'vn_pedido_compromissos'] as $t) vn_exec('DELETE FROM ' . $t . ' WHERE orcamento_id = ?', [$id]);
    vn_exec('DELETE FROM vn_mobile_orcamento_itens WHERE orcamento_id = ?', [$id]);
    vn_exec('DELETE FROM vn_mobile_orcamentos WHERE id = ? LIMIT 1', [$id]);
}

/* ==========================
   Saída de estoque (baixa direta + registro em relatórios)
========================== */
/* Executa $fn dentro de uma transação (tudo ou nada). Se já houver uma aberta, apenas roda. */
function vn_tx(callable $fn) {
    $db = vn_db();
    if ($db instanceof PDO) {
        if ($db->inTransaction()) return $fn();
        $db->beginTransaction();
        try { $r = $fn(); $db->commit(); return $r; }
        catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    }
    $db->begin_transaction();
    try { $r = $fn(); $db->commit(); return $r; }
    catch (Throwable $e) { $db->rollback(); throw $e; }
}

/*
   Ajusta o estoque do catálogo de forma ATÔMICA: o próprio banco soma/subtrai, sem ler o saldo antes.
   Assim duas saídas ao mesmo tempo não se sobrescrevem. Saída (delta < 0) só é aplicada se houver
   saldo, a menos que $permitirNegativo. Retorna false quando faltou saldo (nada foi alterado).
*/
function vn_estoque_ajustar(int $catId, float $delta, bool $permitirNegativo = false): bool {
    if ($catId <= 0 || abs($delta) < 0.00001) return true;
    if ($delta < 0 && !$permitirNegativo) {
        return vn_exec('UPDATE vn_mobile_orcamento_catalogo SET quantidade_estoque = quantidade_estoque + ?, atualizado_em = NOW() WHERE id = ? AND quantidade_estoque + ? >= 0 LIMIT 1', [$delta, $catId, $delta]) > 0;
    }
    return vn_exec('UPDATE vn_mobile_orcamento_catalogo SET quantidade_estoque = quantidade_estoque + ?, atualizado_em = NOW() WHERE id = ? LIMIT 1', [$delta, $catId]) > 0;
}

function vn_estoque_saidas_tabela_v1(): void {
    vn_exec("CREATE TABLE IF NOT EXISTS vn_mobile_estoque_saidas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        catalogo_id INT NOT NULL DEFAULT 0,
        origem VARCHAR(40) NOT NULL DEFAULT 'mobile',
        item_nome VARCHAR(190) NOT NULL,
        item_tipo VARCHAR(40) NULL,
        quantidade DECIMAL(12,2) NOT NULL DEFAULT 0,
        motivo VARCHAR(255) NULL,
        responsavel VARCHAR(120) NULL,
        criado_em DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    vn_add_col_if_missing('vn_mobile_estoque_saidas', 'item_tipo', "VARCHAR(40) NULL");
    vn_add_col_if_missing('vn_mobile_estoque_saidas', 'cliente_id', "INT NULL");
    vn_add_col_if_missing('vn_mobile_estoque_saidas', 'cliente_nome', "VARCHAR(190) NULL");
}

/** Busca um cliente (id, nome, telefone) na lista do cadastro. */
function vn_cliente_por_id(int $id): ?array {
    if ($id <= 0) return null;
    foreach (vn_clientes_form() as $c) {
        if ((int)($c['id'] ?? 0) === $id) return $c;
    }
    return null;
}

/** Nome do cliente de uma saída: usa o cliente vinculado ou, nas saídas antigas, o texto do motivo. */
function vn_saida_cliente_texto(array $s): string {
    $nome = vn_clean((string)($s['cliente_nome'] ?? ''));
    if ($nome !== '') return $nome;
    return vn_clean((string)($s['motivo'] ?? ''));
}

/**
 * Condição SQL para separar saídas por categoria (material x ferramenta).
 * Retorna [fragmento, params]. Sem a coluna item_tipo, registros antigos contam como material.
 */
function vn_saida_cat_cond(?string $categoria): array {
    if (!$categoria || $categoria === 'todos') return ['', []];
    $ferr = vn_tipos_ferramenta_sql();
    if (!vn_column_exists('vn_mobile_estoque_saidas', 'item_tipo')) {
        return $categoria === 'ferramenta' ? ['1=0', []] : ['', []];
    }
    if ($categoria === 'ferramenta') return ["item_tipo IN ($ferr)", []];
    return ["(item_tipo IS NULL OR item_tipo NOT IN ($ferr))", []];
}

/**
 * Registra a saída de um ou vários itens do estoque.
 * Aceita item único (saida_catalogo_id) ou múltiplos (saida_catalogo_id[] + saida_quantidade[]).
 * Valida tudo antes de aplicar para não dar baixa parcial.
 */
function vn_registrar_saida_estoque_v1(array $post): string {
    $origem = (string)($post['saida_origem'] ?? 'mobile');
    if ($origem !== 'mobile') throw new Exception('Saída disponível apenas para itens do estoque mobile.');

    $ids = $post['saida_catalogo_id'] ?? [];
    $qts = $post['saida_quantidade'] ?? [];
    if (!is_array($ids)) { $ids = [$ids]; }
    if (!is_array($qts)) { $qts = [$qts]; }

    $motivo = vn_clean((string)($post['saida_motivo'] ?? ''));
    $responsavel = vn_clean((string)($post['saida_responsavel'] ?? ''));

    // Data da saída (padrão: agora). Em outro dia, mantém a hora de agora só para a ordem do dia.
    $dataSaida = vn_date_db_or_null($post['saida_data'] ?? '');
    $quando = ($dataSaida !== null && $dataSaida !== date('Y-m-d')) ? $dataSaida . ' ' . date('H:i:s') : '';

    // Cliente vinculado (opcional): grava id + nome, e usa o nome como motivo se o motivo ficou vazio.
    $clienteId = (int)($post['saida_cliente_id'] ?? 0);
    $clienteNome = '';
    if ($clienteId > 0) {
        $cli = vn_cliente_por_id($clienteId);
        if ($cli) $clienteNome = vn_clean((string)($cli['nome'] ?? ''));
        if ($clienteNome === '') $clienteId = 0;
    }
    if ($motivo === '' && $clienteNome !== '') $motivo = $clienteNome;

    // Agrupa por item (se o mesmo item aparecer mais de uma vez, soma)
    $linhas = [];
    foreach ($ids as $i => $idRaw) {
        $id = (int)$idRaw;
        if ($id <= 0) continue;
        $qtd = vn_money_db($qts[$i] ?? 0);
        if ($qtd <= 0) throw new Exception('Informe uma quantidade maior que zero para todos os itens selecionados.');
        $linhas[$id] = ($linhas[$id] ?? 0) + $qtd;
    }
    if (empty($linhas)) throw new Exception('Selecione ao menos um item para dar saída.');

    // Valida estoque de todos antes de aplicar
    $rows = [];
    foreach ($linhas as $id => $qtd) {
        $row = vn_fetch_one('SELECT id, nome, tipo, quantidade_estoque FROM vn_mobile_orcamento_catalogo WHERE id = ? LIMIT 1', [$id]);
        if (!$row) throw new Exception('Item não encontrado no estoque (ID ' . $id . ').');
        $atual = (float)($row['quantidade_estoque'] ?? 0);
        $nome = (string)($row['nome'] ?? 'Item');
        if ($qtd > $atual) {
            throw new Exception('Saída de "' . $nome . '" (' . number_format($qtd, 2, ',', '.') . ') maior que o estoque disponível (' . number_format($atual, 2, ',', '.') . ').');
        }
        $rows[$id] = $row;
    }

    // Aplica baixa + registra cada movimento
    vn_estoque_saidas_tabela_v1();
    $resumo = [];
    vn_tx(function () use ($linhas, $rows, $origem, $motivo, $responsavel, $clienteId, $clienteNome, $quando, &$resumo) {
    foreach ($linhas as $id => $qtd) {
        $row = $rows[$id];
        $nome = (string)($row['nome'] ?? 'Item');
        $tipo = (string)($row['tipo'] ?? 'material');
        if (!vn_estoque_ajustar((int)$id, -$qtd)) {
            throw new Exception('O estoque de "' . $nome . '" mudou enquanto a saída era registrada e já não é suficiente. Atualize a página e tente de novo.');
        }
        vn_exec('INSERT INTO vn_mobile_estoque_saidas (catalogo_id, origem, item_nome, item_tipo, quantidade, motivo, responsavel, cliente_id, cliente_nome, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ' . ($quando !== '' ? '?' : 'NOW()') . ')',
            array_merge([$id, $origem, $nome, $tipo, $qtd, $motivo ?: null, $responsavel ?: null, $clienteId ?: null, $clienteNome ?: null], $quando !== '' ? [$quando] : []));
        $resumo[] = $nome . ' (' . number_format($qtd, 2, ',', '.') . ')';
    }
    });

    return 'Saída registrada' . ($quando !== '' ? ' em ' . date('d/m/Y', strtotime($quando)) : '') . ' para ' . count($resumo) . ' item(ns): ' . implode(', ', $resumo) . '. Movimentos disponíveis em Relatórios.';
}

function vn_estoque_saidas_recentes_v1(int $limite = 20, ?string $categoria = null): array {
    if (!vn_table_exists('vn_mobile_estoque_saidas')) return [];
    $limite = max(1, min(200, $limite));
    [$cond, $cp] = vn_saida_cat_cond($categoria);
    $sql = 'SELECT * FROM vn_mobile_estoque_saidas' . ($cond ? ' WHERE ' . $cond : '') . ' ORDER BY criado_em DESC, id DESC LIMIT ' . $limite;
    return vn_fetch_all($sql, $cp);
}

function vn_estoque_saidas_mes_v1(string $mes, ?string $categoria = null): array {
    if (!vn_table_exists('vn_mobile_estoque_saidas')) return [];
    // Usa o mesmo intervalo dos orçamentos (BETWEEN) para garantir consistência de mês/fuso.
    [$inicio, $fim] = vn_mes_range($mes);
    [$cond, $cp] = vn_saida_cat_cond($categoria);
    $sql = 'SELECT * FROM vn_mobile_estoque_saidas WHERE criado_em BETWEEN ? AND ?' . ($cond ? ' AND ' . $cond : '') . ' ORDER BY criado_em DESC, id DESC LIMIT 500';
    return vn_fetch_all($sql, array_merge([$inicio, $fim], $cp));
}

/**
 * Exclui uma saída e devolve a quantidade ao estoque do item (se ainda existir).
 */
function vn_excluir_saida_estoque_v1(int $id): string {
    if ($id <= 0) throw new Exception('Saída inválida.');
    if (!vn_table_exists('vn_mobile_estoque_saidas')) throw new Exception('Nenhuma saída registrada.');

    $s = vn_fetch_one('SELECT * FROM vn_mobile_estoque_saidas WHERE id = ? LIMIT 1', [$id]);
    if (!$s) throw new Exception('Saída não encontrada.');

    $catId = (int)($s['catalogo_id'] ?? 0);
    $qtd = (float)($s['quantidade'] ?? 0);
    $nome = (string)($s['item_nome'] ?? 'Item');
    $origem = (string)($s['origem'] ?? 'mobile');

    $devolvido = false;
    if ($catId > 0 && $origem === 'mobile') {
        $item = vn_fetch_one('SELECT id, quantidade_estoque FROM vn_mobile_orcamento_catalogo WHERE id = ? LIMIT 1', [$catId]);
        if ($item) {
            vn_estoque_ajustar($catId, $qtd);
            $devolvido = true;
        }
    }

    vn_exec('DELETE FROM vn_mobile_estoque_saidas WHERE id = ? LIMIT 1', [$id]);

    return $devolvido
        ? 'Saída excluída e ' . number_format($qtd, 2, ',', '.') . ' de "' . $nome . '" devolvido(s) ao estoque.'
        : 'Saída excluída. O item "' . $nome . '" não está mais no catálogo, então a quantidade não foi devolvida.';
}

function vn_estoque_saidas_resumo_mes_v1(string $mes, ?string $categoria = null): array {
    $lista = vn_estoque_saidas_mes_v1($mes, $categoria);
    $totalQtd = 0.0;
    $porItem = [];
    foreach ($lista as $s) {
        $q = (float)($s['quantidade'] ?? 0);
        $totalQtd += $q;
        $nome = (string)($s['item_nome'] ?? 'Item');
        $porItem[$nome] = ($porItem[$nome] ?? 0) + $q;
    }
    arsort($porItem);
    return ['lista' => $lista, 'registros' => count($lista), 'total_qtd' => $totalQtd, 'por_item' => $porItem];
}

/* ==========================
   Retirada de materiais (comprovante por cliente/dia com assinatura)
========================== */
function vn_retiradas_tabelas_v1(): void {
    vn_exec("CREATE TABLE IF NOT EXISTS vn_mobile_retiradas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cliente_id INT NULL,
        cliente_nome VARCHAR(190) NOT NULL,
        data_retirada DATE NOT NULL,
        retirante_nome VARCHAR(160) NOT NULL,
        retirante_documento VARCHAR(80) NULL,
        assinatura_retirante VARCHAR(255) NULL,
        criado_por_nome VARCHAR(160) NULL,
        criado_em DATETIME NOT NULL,
        INDEX idx_vn_retirada_data (data_retirada),
        INDEX idx_vn_retirada_cliente (cliente_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    vn_exec("CREATE TABLE IF NOT EXISTS vn_mobile_retirada_itens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        retirada_id INT NOT NULL,
        saida_id INT NULL,
        item_nome VARCHAR(190) NOT NULL,
        unidade VARCHAR(30) NOT NULL DEFAULT 'un',
        quantidade DECIMAL(12,2) NOT NULL DEFAULT 0,
        INDEX idx_vn_retirada_item (retirada_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * Saídas de material de um dia específico (Y-m-d), com unidade do catálogo e,
 * quando a saída veio da baixa de um pedido, o cliente daquele pedido.
 */
function vn_estoque_saidas_do_dia_v1(string $dia): array {
    if (!vn_table_exists('vn_mobile_estoque_saidas')) return [];
    $d = DateTime::createFromFormat('Y-m-d', $dia);
    if (!$d || $d->format('Y-m-d') !== $dia) return [];
    [$cond] = vn_saida_cat_cond('material');
    $sql = 'SELECT s.*, c.unidade AS unidade FROM vn_mobile_estoque_saidas s
            LEFT JOIN vn_mobile_orcamento_catalogo c ON c.id = s.catalogo_id
            WHERE s.criado_em BETWEEN ? AND ?' . ($cond ? ' AND ' . $cond : '') . '
            ORDER BY s.criado_em ASC, s.id ASC LIMIT 500';
    $rows = vn_fetch_all($sql, [$dia . ' 00:00:00', $dia . ' 23:59:59']);

    // Liga cada saída de baixa de pedido ("Saída do pedido X") ao cliente do pedido.
    $prefixo = 'Saída do pedido ';
    $numeros = [];
    foreach ($rows as $s) {
        $m = (string)($s['motivo'] ?? '');
        if (strpos($m, $prefixo) === 0) $numeros[substr($m, strlen($prefixo))] = true;
    }
    $porNumero = [];
    if ($numeros) {
        $lista = array_keys($numeros);
        $ph = implode(',', array_fill(0, count($lista), '?'));
        foreach (vn_fetch_all("SELECT numero, cliente_id, cliente_nome FROM vn_mobile_orcamentos WHERE numero IN ($ph)", $lista) as $o) {
            $porNumero[(string)$o['numero']] = $o;
        }
    }
    foreach ($rows as &$s) {
        $s['unidade'] = vn_clean((string)($s['unidade'] ?? '')) ?: 'un';
        $s['pedido_numero'] = '';
        $s['pedido_cliente_id'] = 0;
        $s['pedido_cliente_nome'] = '';
        $m = (string)($s['motivo'] ?? '');
        if (strpos($m, $prefixo) === 0) {
            $num = substr($m, strlen($prefixo));
            $s['pedido_numero'] = $num;
            if (isset($porNumero[$num])) {
                $s['pedido_cliente_id'] = (int)($porNumero[$num]['cliente_id'] ?? 0);
                $s['pedido_cliente_nome'] = vn_clean((string)($porNumero[$num]['cliente_nome'] ?? ''));
            }
        }
    }
    unset($s);
    return $rows;
}

/** Salva a assinatura desenhada (dataURL PNG do canvas) em uploads/assinaturas. */
function vn_retirada_salvar_assinatura_v1(string $dataUrl): string {
    $prefixo = 'data:image/png;base64,';
    if (strpos($dataUrl, $prefixo) !== 0) throw new Exception('Assinatura desenhada inválida.');
    $b64 = substr($dataUrl, strlen($prefixo));
    if (strlen($b64) > 1500000) throw new Exception('Assinatura desenhada muito grande.');
    $bin = base64_decode($b64, true);
    if (!$bin) throw new Exception('Assinatura desenhada inválida.');
    $info = @getimagesizefromstring($bin);
    if (!$info || (string)($info['mime'] ?? '') !== 'image/png') throw new Exception('Assinatura desenhada inválida.');
    $dir = __DIR__ . '/uploads/assinaturas';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) throw new Exception('Não foi possível criar a pasta de assinaturas.');
    $nome = 'retirada-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.png';
    if (@file_put_contents($dir . '/' . $nome, $bin) === false) throw new Exception('Não foi possível salvar a assinatura.');
    return 'uploads/assinaturas/' . $nome;
}

/**
 * Cria o comprovante de retirada a partir das saídas selecionadas.
 * A assinatura de quem retira é opcional: pode ser desenhada na hora
 * ou o PDF pode ser enviado com a linha em branco para assinar depois.
 */
function vn_criar_retirada_v1(array $post): int {
    global $VN_USUARIO_NOME;
    vn_retiradas_tabelas_v1();

    $dia = trim((string)($post['ret_data'] ?? ''));
    $d = DateTime::createFromFormat('Y-m-d', $dia);
    if (!$d || $d->format('Y-m-d') !== $dia) throw new Exception('Informe uma data de retirada válida.');

    $clienteId = (int)($post['ret_cliente_id'] ?? 0);
    $clienteNome = vn_clean((string)($post['ret_cliente_nome'] ?? ''));
    if ($clienteId > 0 && $clienteNome === '') {
        foreach (vn_clientes_form() as $c) {
            if ((int)($c['id'] ?? 0) === $clienteId) { $clienteNome = (string)$c['nome']; break; }
        }
    }
    if ($clienteNome === '') throw new Exception('Informe o cliente da retirada.');

    $retirante = vn_clean((string)($post['ret_retirante'] ?? ''));
    if ($retirante === '') throw new Exception('Informe o nome de quem está retirando os materiais.');
    $documento = vn_clean((string)($post['ret_documento'] ?? ''));

    $ids = $post['ret_saida_id'] ?? [];
    if (!is_array($ids)) $ids = [$ids];
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($v) { return $v > 0; })));
    if (!$ids) throw new Exception('Selecione ao menos um material para o comprovante.');

    $itens = [];
    foreach ($ids as $sid) {
        $s = vn_fetch_one('SELECT s.*, c.unidade AS unidade FROM vn_mobile_estoque_saidas s LEFT JOIN vn_mobile_orcamento_catalogo c ON c.id = s.catalogo_id WHERE s.id = ? LIMIT 1', [$sid]);
        if (!$s) throw new Exception('Saída de estoque não encontrada (ID ' . $sid . ').');
        $itens[] = [
            'saida_id' => $sid,
            'nome' => (string)($s['item_nome'] ?? 'Item'),
            'unidade' => vn_clean((string)($s['unidade'] ?? '')) ?: 'un',
            'qtd' => (float)($s['quantidade'] ?? 0),
        ];
    }

    $assinatura = '';
    $dataUrl = trim((string)($post['ret_assinatura'] ?? ''));
    if ($dataUrl !== '') $assinatura = vn_retirada_salvar_assinatura_v1($dataUrl);

    vn_exec('INSERT INTO vn_mobile_retiradas (cliente_id, cliente_nome, data_retirada, retirante_nome, retirante_documento, assinatura_retirante, criado_por_nome, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
        [$clienteId ?: null, $clienteNome, $dia, $retirante, $documento ?: null, $assinatura ?: null, vn_clean((string)($VN_USUARIO_NOME ?? '')) ?: null]);
    $id = vn_last_id();
    foreach ($itens as $it) {
        vn_exec('INSERT INTO vn_mobile_retirada_itens (retirada_id, saida_id, item_nome, unidade, quantidade) VALUES (?, ?, ?, ?, ?)',
            [$id, $it['saida_id'], $it['nome'], $it['unidade'], $it['qtd']]);
    }
    return $id;
}

function vn_retirada_get_v1(int $id): ?array {
    if ($id <= 0 || !vn_table_exists('vn_mobile_retiradas')) return null;
    $r = vn_fetch_one('SELECT * FROM vn_mobile_retiradas WHERE id = ? LIMIT 1', [$id]);
    if (!$r) return null;
    $r['itens'] = vn_fetch_all('SELECT * FROM vn_mobile_retirada_itens WHERE retirada_id = ? ORDER BY id ASC', [$id]);
    return $r;
}

function vn_retiradas_listar_v1(int $limite = 30, int $clienteId = 0, string $clienteNome = ''): array {
    if (!vn_table_exists('vn_mobile_retiradas')) return [];
    $limite = max(1, min(300, $limite));
    [$cond, $params] = vn_retirada_cliente_cond_v1($clienteId, $clienteNome);
    return vn_fetch_all('SELECT r.*, (SELECT COUNT(*) FROM vn_mobile_retirada_itens i WHERE i.retirada_id = r.id) AS total_itens
        FROM vn_mobile_retiradas r' . ($cond ? ' WHERE ' . $cond : '') . ' ORDER BY r.criado_em DESC, r.id DESC LIMIT ' . $limite, $params);
}

/** Condomínios/clientes distintos que já têm comprovante de retirada. */
function vn_retiradas_clientes_v1(): array {
    if (!vn_table_exists('vn_mobile_retiradas')) return [];
    $rows = vn_fetch_all('SELECT DISTINCT cliente_nome FROM vn_mobile_retiradas ORDER BY cliente_nome ASC LIMIT 500');
    $out = [];
    foreach ($rows as $r) {
        $nome = vn_clean((string)($r['cliente_nome'] ?? ''));
        if ($nome !== '') $out[] = $nome;
    }
    return $out;
}

function vn_excluir_retirada_v1(int $id): string {
    $r = vn_retirada_get_v1($id);
    if (!$r) throw new Exception('Comprovante de retirada não encontrado.');
    vn_exec('DELETE FROM vn_mobile_retirada_itens WHERE retirada_id = ?', [$id]);
    vn_exec('DELETE FROM vn_mobile_retiradas WHERE id = ? LIMIT 1', [$id]);
    $assin = (string)($r['assinatura_retirante'] ?? '');
    if ($assin !== '' && strpos($assin, 'uploads/assinaturas/') === 0) {
        $f = __DIR__ . '/' . $assin;
        if (is_file($f)) @unlink($f);
    }
    return 'Comprovante de retirada excluído.';
}

function vn_retirada_public_token(int $id): string {
    return vn_hmac_token('retirada', $id);
}

/** Condição SQL (fragmento + params) para filtrar retiradas por condomínio/cliente. */
function vn_retirada_cliente_cond_v1(int $clienteId, string $clienteNome): array {
    if ($clienteId > 0 && $clienteNome !== '') return ['(cliente_id = ? OR cliente_nome = ?)', [$clienteId, $clienteNome]];
    if ($clienteId > 0) return ['cliente_id = ?', [$clienteId]];
    if ($clienteNome !== '') return ['cliente_nome = ?', [$clienteNome]];
    return ['', []];
}

/** Motivos "Saída do pedido X" dos pedidos de um cliente — liga saídas ao condomínio. */
function vn_motivos_pedidos_cliente_v1(int $clienteId, string $clienteNome): array {
    if (!vn_table_exists('vn_mobile_orcamentos')) return [];
    $w = []; $p = [];
    if ($clienteId > 0) { $w[] = 'cliente_id = ?'; $p[] = $clienteId; }
    if ($clienteNome !== '') { $w[] = 'cliente_nome = ?'; $p[] = $clienteNome; }
    if (!$w) return [];
    $rows = vn_fetch_all("SELECT DISTINCT numero FROM vn_mobile_orcamentos WHERE (" . implode(' OR ', $w) . ") AND numero IS NOT NULL AND numero <> '' LIMIT 900", $p);
    $out = [];
    foreach ($rows as $r) $out[] = 'Saída do pedido ' . (string)$r['numero'];
    return $out;
}

/**
 * Contadores por dia do mês (Y-m) para o calendário: saídas de material e comprovantes.
 * Com condomínio informado, conta só as saídas ligadas aos pedidos dele e os comprovantes dele.
 */
function vn_retirada_calendario_v1(string $mes, int $clienteId = 0, string $clienteNome = ''): array {
    [$inicio, $fim, $mesNorm] = vn_mes_range($mes);
    $temCliente = ($clienteId > 0 || $clienteNome !== '');
    $saidas = [];
    $retiradas = [];
    if (vn_table_exists('vn_mobile_estoque_saidas')) {
        [$cond] = vn_saida_cat_cond('material');
        $extra = '';
        $params = [$inicio, $fim];
        if ($temCliente) {
            $motivos = vn_motivos_pedidos_cliente_v1($clienteId, $clienteNome);
            if ($motivos) {
                $extra = ' AND motivo IN (' . implode(',', array_fill(0, count($motivos), '?')) . ')';
                $params = array_merge($params, $motivos);
            } else {
                $extra = ' AND 1=0';
            }
        }
        $sql = 'SELECT DATE(criado_em) AS d, COUNT(*) AS n FROM vn_mobile_estoque_saidas WHERE criado_em BETWEEN ? AND ?' . ($cond ? ' AND ' . $cond : '') . $extra . ' GROUP BY DATE(criado_em)';
        foreach (vn_fetch_all($sql, $params) as $r) $saidas[(string)$r['d']] = (int)$r['n'];
    }
    if (vn_table_exists('vn_mobile_retiradas')) {
        [$condR, $pr] = vn_retirada_cliente_cond_v1($clienteId, $clienteNome);
        $sql = 'SELECT data_retirada AS d, COUNT(*) AS n FROM vn_mobile_retiradas WHERE data_retirada BETWEEN ? AND ?' . ($condR ? ' AND ' . $condR : '') . ' GROUP BY data_retirada';
        foreach (vn_fetch_all($sql, array_merge([substr($inicio, 0, 10), substr($fim, 0, 10)], $pr)) as $r) {
            $retiradas[(string)$r['d']] = (int)$r['n'];
        }
    }
    return ['mes' => $mesNorm, 'saidas' => $saidas, 'retiradas' => $retiradas];
}

/** Comprovantes de um dia específico (opcionalmente de um condomínio). */
function vn_retiradas_do_dia_v1(string $dia, int $clienteId = 0, string $clienteNome = ''): array {
    if (!vn_table_exists('vn_mobile_retiradas')) return [];
    $d = DateTime::createFromFormat('Y-m-d', $dia);
    if (!$d || $d->format('Y-m-d') !== $dia) return [];
    [$cond, $params] = vn_retirada_cliente_cond_v1($clienteId, $clienteNome);
    $sql = 'SELECT r.*, (SELECT COUNT(*) FROM vn_mobile_retirada_itens i WHERE i.retirada_id = r.id) AS total_itens
        FROM vn_mobile_retiradas r WHERE r.data_retirada = ?' . ($cond ? ' AND ' . $cond : '') . ' ORDER BY r.id DESC LIMIT 100';
    return vn_fetch_all($sql, array_merge([$dia], $params));
}

/**
 * Edita um comprovante de retirada: dados gerais e itens (alterar, remover, incluir).
 * Itens chegam em arrays paralelos; ret_item_id 0 = item novo. Itens ausentes são removidos.
 */
function vn_editar_retirada_v1(array $post): string {
    $id = (int)($post['retirada_id'] ?? 0);
    $r = vn_retirada_get_v1($id);
    if (!$r) throw new Exception('Comprovante de retirada não encontrado.');

    $dia = trim((string)($post['ret_data'] ?? ''));
    $d = DateTime::createFromFormat('Y-m-d', $dia);
    if (!$d || $d->format('Y-m-d') !== $dia) throw new Exception('Informe uma data de retirada válida.');
    $clienteNome = vn_clean((string)($post['ret_cliente_nome'] ?? ''));
    if ($clienteNome === '') throw new Exception('Informe o cliente da retirada.');
    $retirante = vn_clean((string)($post['ret_retirante'] ?? ''));
    if ($retirante === '') throw new Exception('Informe o nome de quem retirou os materiais.');
    $documento = vn_clean((string)($post['ret_documento'] ?? ''));

    $itemIds = $post['ret_item_id'] ?? [];
    $nomes = $post['ret_item_nome'] ?? [];
    $unidades = $post['ret_item_unidade'] ?? [];
    $qtds = $post['ret_item_qtd'] ?? [];
    if (!is_array($itemIds)) $itemIds = [$itemIds];
    if (!is_array($nomes)) $nomes = [$nomes];
    if (!is_array($unidades)) $unidades = [$unidades];
    if (!is_array($qtds)) $qtds = [$qtds];

    $linhas = [];
    foreach ($nomes as $i => $nomeRaw) {
        $nome = vn_clean((string)$nomeRaw);
        if ($nome === '') continue;
        $qtd = vn_money_db($qtds[$i] ?? 0);
        if ($qtd <= 0) throw new Exception('Informe uma quantidade maior que zero para "' . $nome . '".');
        $linhas[] = [
            'id' => (int)($itemIds[$i] ?? 0),
            'nome' => $nome,
            'unidade' => vn_clean((string)($unidades[$i] ?? '')) ?: 'un',
            'qtd' => $qtd,
        ];
    }
    if (!$linhas) throw new Exception('O comprovante precisa de ao menos um material.');

    vn_exec('UPDATE vn_mobile_retiradas SET data_retirada = ?, cliente_nome = ?, retirante_nome = ?, retirante_documento = ? WHERE id = ? LIMIT 1',
        [$dia, $clienteNome, $retirante, $documento ?: null, $id]);

    // Remove os itens que saíram do formulário, atualiza os mantidos e insere os novos.
    $manter = array_values(array_filter(array_map(function ($l) { return $l['id']; }, $linhas)));
    if ($manter) {
        $ph = implode(',', array_fill(0, count($manter), '?'));
        vn_exec("DELETE FROM vn_mobile_retirada_itens WHERE retirada_id = ? AND id NOT IN ($ph)", array_merge([$id], $manter));
    } else {
        vn_exec('DELETE FROM vn_mobile_retirada_itens WHERE retirada_id = ?', [$id]);
    }
    foreach ($linhas as $l) {
        if ($l['id'] > 0) {
            vn_exec('UPDATE vn_mobile_retirada_itens SET item_nome = ?, unidade = ?, quantidade = ? WHERE id = ? AND retirada_id = ? LIMIT 1',
                [$l['nome'], $l['unidade'], $l['qtd'], $l['id'], $id]);
        } else {
            vn_exec('INSERT INTO vn_mobile_retirada_itens (retirada_id, saida_id, item_nome, unidade, quantidade) VALUES (?, NULL, ?, ?, ?)',
                [$id, $l['nome'], $l['unidade'], $l['qtd']]);
        }
    }

    if (!empty($post['ret_remover_assinatura'])) {
        $assin = (string)($r['assinatura_retirante'] ?? '');
        if ($assin !== '' && strpos($assin, 'uploads/assinaturas/') === 0) {
            $f = __DIR__ . '/' . $assin;
            if (is_file($f)) @unlink($f);
        }
        vn_exec('UPDATE vn_mobile_retiradas SET assinatura_retirante = NULL WHERE id = ? LIMIT 1', [$id]);
    }

    return 'Comprovante #' . $id . ' atualizado. O PDF e o link público já mostram a versão corrigida.';
}

/* ==========================
   Ferramentas (sem valor/lucro: só nome, quantidade e foto)
========================== */
function vn_ferramentas_listar_v1(bool $somenteAtivos = true): array {
    vn_ensure_mobile_tables();
    $ferr = vn_tipos_ferramenta_sql();
    $where = "WHERE tipo IN ($ferr)";
    if ($somenteAtivos) $where .= " AND (status IS NULL OR status = '' OR status = 'ativo')";
    $rows = vn_fetch_all("SELECT id, tipo, nome, descricao, foto_url, unidade, quantidade_estoque FROM vn_mobile_orcamento_catalogo $where ORDER BY tipo ASC, nome ASC LIMIT 1000");
    foreach ($rows as &$r) { $r['origem'] = 'mobile'; $r['quantidade_estoque'] = (float)($r['quantidade_estoque'] ?? 0); }
    unset($r);
    return $rows;
}

function vn_ferramenta_tipo_post(array $post): string {
    $t = vn_lower((string)($post['ferramenta_tipo'] ?? 'ferramenta'));
    return vn_is_tipo_ferramenta($t) ? $t : 'ferramenta';
}

function vn_salvar_ferramenta_v1(array $post): int {
    vn_ensure_mobile_tables();
    $tipo = vn_ferramenta_tipo_post($post);
    $nome = vn_clean($post['ferramenta_nome'] ?? '');
    if ($nome === '') throw new Exception('Informe o nome da ferramenta.');
    $descricao = trim((string)($post['ferramenta_descricao'] ?? ''));
    if ($descricao === '') $descricao = $nome;
    $unidade = vn_clean($post['ferramenta_unidade'] ?? 'un') ?: 'un';
    $qtd = vn_money_db($post['ferramenta_quantidade'] ?? 0);
    $foto = trim((string)($post['ferramenta_foto_url'] ?? ''));
    vn_exec("INSERT INTO vn_mobile_orcamento_catalogo (tipo, nome, descricao, foto_url, unidade, valor_unitario, custo_unitario, quantidade_estoque, status, criado_em) VALUES (?, ?, ?, ?, ?, 0, 0, ?, 'ativo', NOW())",
        [$tipo, $nome, $descricao, $foto ?: null, $unidade, $qtd]);
    return vn_last_id();
}

function vn_atualizar_ferramenta_v1(array $post): int {
    vn_ensure_mobile_tables();
    $id = (int)($post['ferramenta_id'] ?? 0);
    if ($id <= 0) throw new Exception('Ferramenta inválida para editar.');
    $tipo = vn_ferramenta_tipo_post($post);
    $nome = vn_clean($post['ferramenta_nome'] ?? '');
    if ($nome === '') throw new Exception('Informe o nome da ferramenta.');
    $descricao = trim((string)($post['ferramenta_descricao'] ?? ''));
    if ($descricao === '') $descricao = $nome;
    $unidade = vn_clean($post['ferramenta_unidade'] ?? 'un') ?: 'un';
    $qtd = vn_money_db($post['ferramenta_quantidade'] ?? 0);
    $foto = trim((string)($post['ferramenta_foto_url'] ?? ''));
    if ($foto !== '') {
        vn_exec("UPDATE vn_mobile_orcamento_catalogo SET tipo=?, nome=?, descricao=?, foto_url=?, unidade=?, valor_unitario=0, custo_unitario=0, quantidade_estoque=?, atualizado_em=NOW() WHERE id=? LIMIT 1",
            [$tipo, $nome, $descricao, $foto, $unidade, $qtd, $id]);
    } else {
        vn_exec("UPDATE vn_mobile_orcamento_catalogo SET tipo=?, nome=?, descricao=?, unidade=?, valor_unitario=0, custo_unitario=0, quantidade_estoque=?, atualizado_em=NOW() WHERE id=? LIMIT 1",
            [$tipo, $nome, $descricao, $unidade, $qtd, $id]);
    }
    return $id;
}

function vn_excluir_ferramenta_v1(int $id): void {
    if ($id <= 0) throw new Exception('Ferramenta inválida.');
    $ferr = vn_tipos_ferramenta_sql();
    vn_exec("DELETE FROM vn_mobile_orcamento_catalogo WHERE id = ? AND tipo IN ($ferr) LIMIT 1", [$id]);
}

/* ==========================
   Entrada de estoque (adicionar mais quantidade a um item)
========================== */
function vn_entrada_estoque_v1(int $id, $qtd): string {
    $id = (int)$id;
    $qtd = vn_money_db($qtd);
    if ($id <= 0) throw new Exception('Item inválido.');
    if ($qtd <= 0) throw new Exception('Informe uma quantidade maior que zero para adicionar.');
    $item = vn_fetch_one('SELECT id, nome, quantidade_estoque FROM vn_mobile_orcamento_catalogo WHERE id = ? LIMIT 1', [$id]);
    if (!$item) throw new Exception('Item não encontrado no estoque.');
    vn_estoque_ajustar($id, $qtd);
    $novo = (float)(vn_fetch_one('SELECT quantidade_estoque FROM vn_mobile_orcamento_catalogo WHERE id = ? LIMIT 1', [$id])['quantidade_estoque'] ?? 0);
    return 'Adicionado ' . number_format($qtd, 2, ',', '.') . ' a "' . (string)($item['nome'] ?? 'item') . '". Novo estoque: ' . number_format($novo, 2, ',', '.') . '.';
}

/* ==========================
   Editar uma saída já registrada (ajusta o estoque pela diferença)
========================== */
function vn_editar_saida_estoque_v1(array $post): string {
    $id = (int)($post['saida_id'] ?? 0);
    if ($id <= 0) throw new Exception('Saída inválida.');
    if (!vn_table_exists('vn_mobile_estoque_saidas')) throw new Exception('Nenhuma saída registrada.');

    $s = vn_fetch_one('SELECT * FROM vn_mobile_estoque_saidas WHERE id = ? LIMIT 1', [$id]);
    if (!$s) throw new Exception('Saída não encontrada.');

    $novaQtd = vn_money_db($post['saida_quantidade'] ?? 0);
    if ($novaQtd <= 0) throw new Exception('Informe uma quantidade maior que zero.');
    $resp = vn_clean((string)($post['saida_responsavel'] ?? ''));
    $motivo = vn_clean((string)($post['saida_motivo'] ?? ''));

    $qtdAntiga = (float)($s['quantidade'] ?? 0);
    $delta = $novaQtd - $qtdAntiga; // >0 = sai mais do estoque; <0 = volta pro estoque
    $catId = (int)($s['catalogo_id'] ?? 0);
    $origem = (string)($s['origem'] ?? 'mobile');

    if ($catId > 0 && $origem === 'mobile' && abs($delta) > 0.0001) {
        $item = vn_fetch_one('SELECT id, nome, quantidade_estoque FROM vn_mobile_orcamento_catalogo WHERE id = ? LIMIT 1', [$catId]);
        if ($item) {
            $atual = (float)($item['quantidade_estoque'] ?? 0);
            if ($delta > 0 && $delta > $atual) {
                throw new Exception('Aumento da saída (' . number_format($delta, 2, ',', '.') . ') maior que o estoque disponível (' . number_format($atual, 2, ',', '.') . ').');
            }
            // delta > 0: sai mais (exige saldo, checado no próprio UPDATE); delta < 0: devolve ao estoque.
            if (!vn_estoque_ajustar($catId, -$delta)) {
                throw new Exception('O estoque de "' . (string)($item['nome'] ?? 'item') . '" mudou e já não comporta o aumento da saída. Atualize a página e tente de novo.');
            }
        }
    }

    vn_estoque_saidas_tabela_v1();
    vn_exec('UPDATE vn_mobile_estoque_saidas SET quantidade = ?, responsavel = ?, motivo = ? WHERE id = ? LIMIT 1',
        [$novaQtd, $resp ?: null, $motivo ?: null, $id]);

    // Cliente vinculado (só mexe se o formulário enviou o campo).
    if (array_key_exists('saida_cliente_id', $post)) {
        $clienteId = (int)$post['saida_cliente_id'];
        $clienteNome = '';
        if ($clienteId > 0) {
            $cli = vn_cliente_por_id($clienteId);
            if ($cli) $clienteNome = vn_clean((string)($cli['nome'] ?? ''));
            if ($clienteNome === '') $clienteId = 0;
        }
        vn_exec('UPDATE vn_mobile_estoque_saidas SET cliente_id = ?, cliente_nome = ? WHERE id = ? LIMIT 1',
            [$clienteId ?: null, $clienteNome ?: null, $id]);
    }

    // Data da saída (só mexe se o formulário enviou o campo): a peça da venda em Cobranças acompanha.
    $msgData = '';
    if (isset($post['saida_data']) && trim((string)$post['saida_data']) !== '') {
        $dataNova = vn_date_db_or_null($post['saida_data']);
        if ($dataNova === null) throw new Exception('A data da saída não é válida.');
        if ($dataNova !== substr((string)($s['criado_em'] ?? ''), 0, 10)) {
            $hora = substr((string)($s['criado_em'] ?? ''), 11, 8);
            if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $hora)) $hora = date('H:i:s');
            vn_exec('UPDATE vn_mobile_estoque_saidas SET criado_em = ? WHERE id = ? LIMIT 1', [$dataNova . ' ' . $hora, $id]);
            if (vn_table_exists('vn_saida_cobrancas')) vn_exec('UPDATE vn_saida_cobrancas SET criado_em = ? WHERE saida_id = ?', [$dataNova . ' ' . $hora, $id]);
            $msgData = ' Data da saída: ' . date('d/m/Y', strtotime($dataNova)) . '.';
        }
    }

    return 'Saída atualizada. Estoque ajustado conforme a nova quantidade.' . $msgData;
}

/** Ações (editar + excluir) de uma linha de saída, usadas em todas as tabelas de saída. */
function vn_render_saida_acoes_v1(array $s, array $responsaveis = [], array $clientes = []): void {
    $id = (int)($s['id'] ?? 0);
    $qtd = (float)($s['quantidade'] ?? 0);
    $resp = (string)($s['responsavel'] ?? '');
    $motivo = (string)($s['motivo'] ?? '');
    $clienteId = (int)($s['cliente_id'] ?? 0);
    ?>
    <div class="vn-actions vn-saida-acoes">
        <details class="vn-edit-box">
            <summary class="vn-mini-btn">Editar</summary>
            <form method="post" class="vn-form-grid" style="margin-top:10px">
                <input type="hidden" name="acao" value="editar_saida">
                <input type="hidden" name="saida_id" value="<?php echo $id; ?>">
                <div class="vn-field"><label>Quantidade</label><input name="saida_quantidade" inputmode="decimal" value="<?php echo number_format($qtd, 2, ',', '.'); ?>" required></div>
                <div class="vn-field"><label>Data da saída</label><input type="date" name="saida_data" value="<?php echo vn_h(substr((string)($s['criado_em'] ?? ''), 0, 10)); ?>" required></div>
                <div class="vn-field"><label>Responsável</label>
                    <?php if (!empty($responsaveis)): ?>
                        <select name="saida_responsavel">
                            <option value="">—</option>
                            <?php $ops = $responsaveis; if ($resp !== '' && !in_array($resp, $ops, true)) array_unshift($ops, $resp); foreach ($ops as $n): ?>
                                <option value="<?php echo vn_h($n); ?>" <?php echo ($n === $resp ? 'selected' : ''); ?>><?php echo vn_h($n); ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input name="saida_responsavel" value="<?php echo vn_h($resp); ?>">
                    <?php endif; ?>
                </div>
                <?php if (!empty($clientes)): ?>
                    <div class="vn-field full"><label>Cliente</label>
                        <select name="saida_cliente_id">
                            <option value="0">— Sem cliente —</option>
                            <?php foreach ($clientes as $c): ?>
                                <option value="<?php echo (int)$c['id']; ?>" data-telefone="<?php echo vn_h((string)($c['telefone'] ?? '')); ?>" <?php echo ((int)$c['id'] === $clienteId ? 'selected' : ''); ?>><?php echo vn_h($c['nome']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <div class="vn-field full"><label>Motivo / observação</label><input name="saida_motivo" value="<?php echo vn_h($motivo); ?>"></div>
                <div class="vn-field full"><button class="vn-btn vn-btn-primary" type="submit">Salvar saída</button></div>
            </form>
        </details>
        <form method="post" data-confirm="Excluir esta saída e devolver a quantidade ao estoque?" data-danger>
            <input type="hidden" name="acao" value="excluir_saida">
            <input type="hidden" name="saida_id" value="<?php echo $id; ?>">
            <button class="vn-mini-btn vn-btn-danger" type="submit">Excluir</button>
        </form>
    </div>
<?php }

/** Botão (em cada card) que abre o modal de adicionar quantidade. */
function vn_entrada_btn_v1(int $id, string $nome, string $unidade = 'un'): void {
    ?><button type="button" class="vn-mini-btn vn-btn-primary" data-entrada-open data-id="<?php echo $id; ?>" data-nome="<?php echo vn_h($nome); ?>" data-unidade="<?php echo vn_h($unidade); ?>">＋ Quantidade</button><?php
}

/** Modal único (uma vez por página) para adicionar quantidade a um item. */
function vn_render_entrada_modal_v1(): void {
    ?>
    <dialog class="vn-orc-modal vn-entrada-modal" id="entradaModal">
        <form method="post" class="vn-entrada-form">
            <div class="vn-orc-modal-head">
                <div><h4>Adicionar quantidade</h4><small id="entradaItemNome">—</small></div>
                <button type="button" class="vn-mini-btn" data-entrada-close>Fechar</button>
            </div>
            <div class="vn-orc-modal-body">
                <input type="hidden" name="acao" value="entrada_estoque">
                <input type="hidden" name="entrada_id" id="entradaId" value="">
                <div class="vn-field"><label>Quantidade a adicionar <span id="entradaUnidade"></span></label><input name="entrada_quantidade" id="entradaQtd" inputmode="decimal" value="1" required></div>
                <div class="vn-field full" style="margin-top:12px"><button class="vn-btn vn-btn-primary" type="submit">Adicionar ao estoque</button></div>
            </div>
        </form>
    </dialog>
    <script>
    (function(){
        var modal = document.getElementById('entradaModal');
        if (!modal) return;
        document.addEventListener('click', function(ev){
            var open = ev.target.closest('[data-entrada-open]');
            if (open){
                document.getElementById('entradaId').value = open.getAttribute('data-id') || '';
                document.getElementById('entradaItemNome').textContent = open.getAttribute('data-nome') || '';
                document.getElementById('entradaUnidade').textContent = '(' + (open.getAttribute('data-unidade') || 'un') + ')';
                document.getElementById('entradaQtd').value = '1';
                if (modal.showModal) modal.showModal(); else modal.setAttribute('open','open');
                setTimeout(function(){ document.getElementById('entradaQtd').focus(); }, 50);
                return;
            }
            if (ev.target.closest('[data-entrada-close]')){ modal.close(); return; }
            if (ev.target === modal){ modal.close(); }
        });
    })();
    </script>
<?php }

/* ==========================
   Estoque baixo (materiais faltando)
========================== */
function vn_estoque_baixo_v1(float $limite = 3): array {
    vn_ensure_mobile_tables();
    // Itens físicos (que têm estoque) abaixo do limite. Serviços/mão de obra não entram.
    $rows = vn_fetch_all(
        "SELECT id, tipo, nome, descricao, foto_url, unidade, quantidade_estoque, estoque_minimo
         FROM vn_mobile_orcamento_catalogo
         WHERE tipo NOT IN ('servico','mao_obra')
           AND quantidade_estoque < COALESCE(estoque_minimo, ?)
           AND (status IS NULL OR status = '' OR status = 'ativo')
         ORDER BY quantidade_estoque ASC, nome ASC LIMIT 500",
        [$limite]
    );
    // Item com estoque mínimo próprio usa o dele; os demais usam o limite geral da tela.
    foreach ($rows as &$r) {
        $r['quantidade_estoque'] = (float)($r['quantidade_estoque'] ?? 0);
        $r['estoque_minimo'] = ($r['estoque_minimo'] === null || $r['estoque_minimo'] === '') ? null : (float)$r['estoque_minimo'];
        $r['minimo_usado'] = $r['estoque_minimo'] !== null ? $r['estoque_minimo'] : (float)$limite;
        $r['origem'] = 'mobile';
    }
    unset($r);
    return $rows;
}

/* ==========================
   Financeiro — valor total parado no estoque
========================== */
/**
 * Soma o valor de tudo que está no estoque (peças, equipamentos, usados e outros físicos).
 * Retorna o total a preço de custo, a preço de venda, lucro potencial e a lista por item.
 */
function vn_estoque_valor_total_v1(): array {
    vn_ensure_mobile_tables();
    $ferr = vn_tipos_ferramenta_sql();
    $rows = vn_fetch_all(
        "SELECT tipo, nome, unidade, quantidade_estoque, custo_unitario, valor_unitario
           FROM vn_mobile_orcamento_catalogo
          WHERE tipo NOT IN ('servico','mao_obra',$ferr)
            AND (status IS NULL OR status = '' OR LOWER(status) IN ('ativo','1','sim','s'))
          ORDER BY (quantidade_estoque * custo_unitario) DESC, nome ASC
          LIMIT 1000"
    );
    $totalCusto = 0.0; $totalVenda = 0.0; $totalQtd = 0.0; $comEstoque = 0; $itens = [];
    foreach ($rows as $r) {
        $q = (float)($r['quantidade_estoque'] ?? 0);
        $c = (float)($r['custo_unitario'] ?? 0);
        $v = (float)($r['valor_unitario'] ?? 0);
        $subCusto = $q * $c; $subVenda = $q * $v;
        $totalCusto += $subCusto; $totalVenda += $subVenda; $totalQtd += $q;
        if ($q > 0) $comEstoque++;
        $itens[] = [
            'nome' => (string)($r['nome'] ?? ''),
            'tipo' => (string)($r['tipo'] ?? 'material'),
            'unidade' => (string)($r['unidade'] ?? 'un'),
            'quantidade' => $q,
            'custo' => $c,
            'venda' => $v,
            'sub_custo' => $subCusto,
            'sub_venda' => $subVenda,
        ];
    }
    return [
        'total_custo' => $totalCusto,
        'total_venda' => $totalVenda,
        'lucro_potencial' => $totalVenda - $totalCusto,
        'total_qtd' => $totalQtd,
        'itens_distintos' => count($itens),
        'itens_com_estoque' => $comEstoque,
        'itens' => $itens,
    ];
}

/* ==========================
   Financeiro — cobranças a receber (resumo das parcelas)
========================== */
/**
 * Resumo financeiro das cobranças: total a receber, já recebido e parcelas vencidas.
 * Usa as tabelas da página de Cobranças (criadas lá). Se não existirem, retorna zeros.
 */
function vn_cobrancas_resumo_v1(): array {
    $base = [
        'a_receber' => 0.0, 'recebido' => 0.0, 'vencidas' => 0.0,
        'qtd_vencidas' => 0, 'qtd_abertas' => 0, 'qtd_cobrancas' => 0,
    ];
    if (!vn_table_exists('vn_cobranca_parcelas')) return $base;
    $hoje = function_exists('vn_today_sp') ? vn_today_sp() : date('Y-m-d');
    foreach (vn_fetch_all('SELECT valor, vencimento, pago FROM vn_cobranca_parcelas') as $p) {
        $valor = (float)($p['valor'] ?? 0);
        if ((int)($p['pago'] ?? 0) === 1) {
            $base['recebido'] += $valor;
        } else {
            $base['a_receber'] += $valor;
            $base['qtd_abertas']++;
            if (!empty($p['vencimento']) && $p['vencimento'] < $hoje) {
                $base['vencidas'] += $valor;
                $base['qtd_vencidas']++;
            }
        }
    }
    if (vn_table_exists('vn_cobrancas')) {
        $r = vn_fetch_one('SELECT COUNT(*) AS n FROM vn_cobrancas');
        $base['qtd_cobrancas'] = (int)($r['n'] ?? 0);
    }
    return $base;
}

/**
 * Lista de responsáveis (usuários/técnicos) para os formulários de saída.
 */
function vn_responsaveis_lista_v1(): array {
    try {
        $nomes = [];
        foreach (vn_tecnicos_form() as $t) {
            $n = vn_clean($t['nome'] ?? '');
            if ($n !== '') $nomes[$n] = $n;
        }
        ksort($nomes);
        return array_values($nomes);
    } catch (Throwable $e) { return []; }
}

/**
 * Formulário de saída compartilhado (estoque e ferramentas).
 * $itensMobile: itens com id, nome, quantidade_estoque, unidade.
 * $responsaveis: lista de nomes (dropdown). $saidasRecentes: histórico com botão excluir.
 */
function vn_render_saida_form_v2(array $itensMobile, array $responsaveis, array $saidasRecentes, array $opts = []): void {
    $titulo = (string)($opts['titulo'] ?? 'Saída de estoque');
    $subtitulo = (string)($opts['subtitulo'] ?? 'Selecione vários itens, dê baixa e registre nos relatórios.');
    $vazio = (string)($opts['vazio'] ?? 'Cadastre itens para registrar saídas.');
    $respAtual = (string)($opts['responsavel_atual'] ?? '');
    $labelItem = (string)($opts['label_item'] ?? 'Item');

    $jsItens = [];
    foreach ($itensMobile as $it) {
        if (isset($it['origem']) && (string)$it['origem'] !== 'mobile') continue;
        $jsItens[] = [
            'id' => (int)$it['id'],
            'nome' => (string)($it['nome'] ?? ''),
            'estoque' => (float)($it['quantidade_estoque'] ?? 0),
            'unidade' => (string)($it['unidade'] ?? 'un'),
        ];
    }
    ?>
    <section class="vn-panel vn-stock-saida">
        <div class="vn-panel-head"><div><h3><?php echo vn_h($titulo); ?></h3><small><?php echo vn_h($subtitulo); ?></small></div></div>
        <?php if (empty($jsItens)): ?>
            <div class="vn-empty"><?php echo vn_h($vazio); ?></div>
        <?php else: ?>
        <form method="post" class="vn-saida-form">
            <input type="hidden" name="acao" value="saida_estoque">
            <input type="hidden" name="saida_origem" value="mobile">
            <div class="vn-items-title" style="margin-top:0">Itens da saída</div>
            <div id="saidaLista" class="vn-saida-lista"></div>
            <button type="button" class="vn-mini-btn" id="saidaAddItem">＋ Adicionar <?php echo vn_h(vn_lower($labelItem)); ?></button>
            <div class="vn-form-grid" style="margin-top:14px">
                <div class="vn-field"><label>Responsável</label>
                    <?php if (!empty($responsaveis)): ?>
                        <select name="saida_responsavel">
                            <option value="">Selecionar responsável...</option>
                            <?php
                            $opcoes = $responsaveis;
                            if ($respAtual !== '' && !in_array($respAtual, $opcoes, true)) array_unshift($opcoes, $respAtual);
                            foreach ($opcoes as $nome): ?>
                                <option value="<?php echo vn_h($nome); ?>" <?php echo ($nome === $respAtual ? 'selected' : ''); ?>><?php echo vn_h($nome); ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input name="saida_responsavel" value="<?php echo vn_h($respAtual); ?>" placeholder="Quem retirou">
                    <?php endif; ?>
                </div>
                <div class="vn-field"><label>Motivo</label><input name="saida_motivo" placeholder="Ex: instalação, uso interno, perda, troca..."></div>
                <div class="vn-field full"><button class="vn-btn vn-btn-primary" type="submit">Registrar saída</button></div>
            </div>
        </form>
        <script>
        (function(){
            var ITENS = <?php echo json_encode($jsItens, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            var lista = document.getElementById('saidaLista');
            var addBtn = document.getElementById('saidaAddItem');
            if (!lista) return;
            function esc(s){ return String(s).replace(/[&<>"]/g, function(c){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'})[c]; }); }
            function fmt(n){ return Number(n||0).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2}); }
            function optionsHtml(){
                var h = '<option value="">Selecionar...</option>';
                ITENS.forEach(function(it){
                    h += '<option value="'+it.id+'" data-estoque="'+it.estoque+'" data-unidade="'+esc(it.unidade)+'">'+esc(it.nome)+' — '+fmt(it.estoque)+' '+esc(it.unidade)+'</option>';
                });
                return h;
            }
            function addRow(){
                var row = document.createElement('div');
                row.className = 'vn-saida-row';
                row.innerHTML =
                    '<div class="vn-field"><label>Item</label><select name="saida_catalogo_id[]" class="saidaSel" required>'+optionsHtml()+'</select><small class="vn-help saidaInfo">Estoque atual: —</small></div>'+
                    '<div class="vn-field"><label>Quantidade</label><input name="saida_quantidade[]" class="saidaQtd" inputmode="decimal" value="1" required></div>'+
                    '<button type="button" class="vn-mini-btn vn-btn-danger saidaDel" title="Remover item">×</button>';
                lista.appendChild(row);
                var sel = row.querySelector('.saidaSel');
                var info = row.querySelector('.saidaInfo');
                var qtd = row.querySelector('.saidaQtd');
                sel.addEventListener('change', function(){
                    var opt = sel.options[sel.selectedIndex];
                    if (!opt || !opt.value){ info.textContent = 'Estoque atual: —'; return; }
                    var est = opt.getAttribute('data-estoque') || '0';
                    var un = opt.getAttribute('data-unidade') || 'un';
                    info.textContent = 'Estoque atual: ' + fmt(est) + ' ' + un;
                    qtd.setAttribute('max', est);
                });
                row.querySelector('.saidaDel').addEventListener('click', function(){
                    if (lista.querySelectorAll('.vn-saida-row').length > 1) row.remove();
                    else { sel.value = ''; qtd.value = '1'; info.textContent = 'Estoque atual: —'; }
                });
            }
            addBtn.addEventListener('click', addRow);
            addRow();
        })();
        </script>
        <?php endif; ?>
        <?php if (!empty($saidasRecentes)): ?>
            <div class="vn-items-title" style="margin-top:18px">Últimas saídas</div>
            <table class="vn-table">
                <thead><tr><th>Data</th><th>Item</th><th>Qtd</th><th>Responsável</th><th>Motivo</th><th>Ações</th></tr></thead>
                <tbody>
                <?php foreach ($saidasRecentes as $s): ?>
                    <tr>
                        <td data-label="Data"><?php echo vn_h(date('d/m/Y H:i', strtotime((string)($s['criado_em'] ?? 'now')))); ?></td>
                        <td data-label="Item"><?php echo vn_h($s['item_nome'] ?? ''); ?></td>
                        <td data-label="Qtd"><?php echo number_format((float)($s['quantidade'] ?? 0), 2, ',', '.'); ?></td>
                        <td data-label="Responsável"><?php echo vn_h(($s['responsavel'] ?? '') ?: '—'); ?></td>
                        <td data-label="Motivo"><?php echo vn_h(($s['motivo'] ?? '') ?: '—'); ?></td>
                        <td data-label="Ações"><?php vn_render_saida_acoes_v1($s, $responsaveis); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
<?php }

function vn_orcamentos_listagem_v4(?string $mes = null): array {
    [$inicio, $fim] = vn_mes_range($mes);
    $lista = [];
    if (vn_table_exists('vn_mobile_orcamentos')) {
        foreach (vn_fetch_all("SELECT *, 'vn_mobile_orcamentos' AS fonte FROM vn_mobile_orcamentos WHERE criado_em BETWEEN ? AND ? ORDER BY criado_em DESC LIMIT 300", [$inicio, $fim]) as $r) {
            $r['kind'] = vn_orc_status_kind((string)($r['status'] ?? ''));
            $r['valor'] = (float)($r['valor_total'] ?? 0);
            $r['custo'] = (float)($r['valor_custo'] ?? 0);
            $r['lucro_calc'] = (float)($r['lucro'] ?? (($r['valor'] ?? 0) - ($r['custo'] ?? 0)));
            $r['data_ref'] = (string)($r['criado_em'] ?? '');
            $lista[] = $r;
        }
    }
    if (vn_table_exists('orcamentos')) {
        $idCol = vn_first_col('orcamentos', ['id']);
        $dataCol = vn_first_col('orcamentos', ['criado_em','data_cadastro','data','atualizado_em','updated_at']);
        if ($idCol && $dataCol) {
            $statusExpr = vn_text_expr('orcamentos', ['status','situacao'], "'Pendente'");
            $totalCol = vn_first_col('orcamentos', ['valor_total','total_geral','total','valor']);
            $custoCol = vn_first_col('orcamentos', ['valor_custo','total_custo','custo_total','custo']);
            $lucroCol = vn_first_col('orcamentos', ['lucro','valor_lucro','lucro_total']);
            $numeroExpr = vn_text_expr('orcamentos', ['numero','codigo'], "CONCAT('ORC #', `$idCol`)");
            $clienteExpr = vn_text_expr('orcamentos', ['cliente_nome','nome_cliente','cliente'], "''");
            $totalExpr = $totalCol ? "`$totalCol`" : '0';
            $custoExpr = $custoCol ? "`$custoCol`" : '0';
            $lucroExpr = $lucroCol ? "`$lucroCol`" : "($totalExpr - $custoExpr)";
            $sql = "SELECT `$idCol` AS id, `$dataCol` AS criado_em, $statusExpr AS status, $totalExpr AS valor_total, $custoExpr AS valor_custo, $lucroExpr AS lucro, $numeroExpr AS numero, $clienteExpr AS cliente_nome, 'orcamentos' AS fonte FROM orcamentos WHERE `$dataCol` BETWEEN ? AND ? ORDER BY `$dataCol` DESC LIMIT 300";
            foreach (vn_fetch_all($sql, [$inicio, $fim]) as $r) {
                $r['kind'] = vn_orc_status_kind((string)($r['status'] ?? ''));
                $r['valor'] = (float)($r['valor_total'] ?? 0);
                $r['custo'] = (float)($r['valor_custo'] ?? 0);
                $r['lucro_calc'] = (float)($r['lucro'] ?? (($r['valor'] ?? 0) - ($r['custo'] ?? 0)));
                $r['data_ref'] = (string)($r['criado_em'] ?? '');
                $lista[] = $r;
            }
        }
    }
    usort($lista, function($a, $b){ return strcmp((string)($b['data_ref'] ?? ''), (string)($a['data_ref'] ?? '')); });
    return $lista;
}

function vn_bool_post_v4(array $post, string $key, int $default = 0): int {
    return isset($post[$key]) ? 1 : 0;
}


function vn_normalizar_tipo_orcamento_v6(string $tipo): string {
    $tipo = vn_lower($tipo);
    if (in_array($tipo, ['equipamento','material','produto_usado','usado','servico','mao_obra','ferramenta','ferramenta_outro','outro'], true)) return $tipo === 'usado' ? 'produto_usado' : $tipo;
    if (strpos($tipo, 'ferram') !== false) return 'ferramenta';
    if (strpos($tipo, 'obra') !== false) return 'mao_obra';
    if (strpos($tipo, 'serv') !== false) return 'servico';
    if (strpos($tipo, 'usado') !== false || strpos($tipo, 'seminovo') !== false) return 'produto_usado';
    if (strpos($tipo, 'equip') !== false) return 'equipamento';
    return 'material';
}

function vn_catalogo_markup_fixo_v9(): float {
    // Mantido apenas para compatibilidade com versões antigas.
    return 42.857142857;
}

function vn_catalogo_margem_para_markup_v10(float $margem): float {
    // Margem é a porcentagem de lucro sobre o preço final.
    // Exemplo: margem 30% => markup 42,857142857%.
    if ($margem <= 0 || $margem >= 100) return 0.0;
    return ($margem / (100 - $margem)) * 100;
}

function vn_catalogo_preco_auto_v8(float $custo, array $post): float {
    $valorInformado = vn_money_db($post['catalogo_valor'] ?? 0);
    $tipo = vn_normalizar_tipo_orcamento_v6((string)($post['catalogo_tipo'] ?? 'material'));
    $auto = isset($post['catalogo_auto_calculo']) ? (string)$post['catalogo_auto_calculo'] : '';

    // Serviço e mão de obra continuam manuais para você alterar livremente.
    if (in_array($tipo, ['servico', 'mao_obra'], true)) return $valorInformado;
    if ($auto !== '1' || $custo <= 0) return $valorInformado;

    // Produtos, peças, equipamentos e usados: você altera a margem de lucro,
    // o sistema calcula markup e valor de venda automaticamente.
    $margem = vn_money_db($post['catalogo_margem'] ?? 30);
    if ($margem <= 0 || $margem >= 100) return $valorInformado;
    return round($custo / (1 - ($margem / 100)), 2);
}

function vn_salvar_catalogo_orcamento_mobile_v6(array $post): int {
    vn_ensure_mobile_tables();
    $tipo = vn_normalizar_tipo_orcamento_v6((string)($post['catalogo_tipo'] ?? 'material'));
    $nome = vn_clean($post['catalogo_nome'] ?? '');
    $descricao = trim((string)($post['catalogo_descricao'] ?? ''));
    $unidade = vn_clean($post['catalogo_unidade'] ?? 'un');
    $custo = vn_money_db($post['catalogo_custo'] ?? 0);
    $quantidadeEstoque = vn_money_db($post['catalogo_quantidade_estoque'] ?? 0);
    $estoqueMinimo = trim((string)($post['catalogo_estoque_minimo'] ?? '')) === '' ? null : max(0, vn_money_db($post['catalogo_estoque_minimo']));
    $valor = vn_catalogo_preco_auto_v8($custo, $post);
    if ($nome === '') throw new Exception('Informe o nome do item para cadastrar.');
    if ($descricao === '') $descricao = $nome;
    $fotoUrl = trim((string)($post['catalogo_foto_url'] ?? ''));
    $linha = vn_linha_catalogo($post['catalogo_linha'] ?? '', $tipo);
    // Produto cobrado por medida (serralheria): a unidade é a da medida ("Preço por m²") e não há estoque em m².
    $calculo = function_exists('vn_serr_calculo_valido') ? vn_serr_calculo_valido($post['catalogo_calculo'] ?? '') : '';
    if ($calculo !== '') { $unidade = vn_serr_calculo_unidade($calculo); $quantidadeEstoque = 0; }
    vn_exec('INSERT INTO vn_mobile_orcamento_catalogo (tipo, nome, descricao, foto_url, unidade, valor_unitario, custo_unitario, quantidade_estoque, estoque_minimo, status, linha, calculo, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())', [$tipo, $nome, $descricao, $fotoUrl ?: null, $unidade ?: 'un', $valor, $custo, $quantidadeEstoque, $estoqueMinimo, 'ativo', $linha, $calculo]);
    return vn_last_id();
}

function vn_atualizar_catalogo_orcamento_mobile_v6(array $post): int {
    vn_ensure_mobile_tables();
    $id = (int)($post['catalogo_id'] ?? 0);
    if ($id <= 0) throw new Exception('Item do catalogo invalido para editar.');
    $tipo = vn_normalizar_tipo_orcamento_v6((string)($post['catalogo_tipo'] ?? 'material'));
    $nome = vn_clean($post['catalogo_nome'] ?? '');
    $descricao = trim((string)($post['catalogo_descricao'] ?? ''));
    $unidade = vn_clean($post['catalogo_unidade'] ?? 'un');
    $custo = vn_money_db($post['catalogo_custo'] ?? 0);
    $quantidadeEstoque = vn_money_db($post['catalogo_quantidade_estoque'] ?? 0);
    $estoqueMinimo = trim((string)($post['catalogo_estoque_minimo'] ?? '')) === '' ? null : max(0, vn_money_db($post['catalogo_estoque_minimo']));
    $valor = vn_catalogo_preco_auto_v8($custo, $post);
    $fotoUrl = trim((string)($post['catalogo_foto_url'] ?? ''));
    if ($nome === '') throw new Exception('Informe o nome do item para editar.');
    if ($descricao === '') $descricao = $nome;
    if ($fotoUrl !== '') {
        // Só troca a foto quando uma nova foi enviada; caso contrário, mantém a atual.
        vn_exec('UPDATE vn_mobile_orcamento_catalogo SET tipo=?, nome=?, descricao=?, foto_url=?, unidade=?, valor_unitario=?, custo_unitario=?, quantidade_estoque=?, estoque_minimo=?, atualizado_em=NOW() WHERE id=? LIMIT 1', [$tipo, $nome, $descricao, $fotoUrl, $unidade ?: 'un', $valor, $custo, $quantidadeEstoque, $estoqueMinimo, $id]);
    } else {
        vn_exec('UPDATE vn_mobile_orcamento_catalogo SET tipo=?, nome=?, descricao=?, unidade=?, valor_unitario=?, custo_unitario=?, quantidade_estoque=?, estoque_minimo=?, atualizado_em=NOW() WHERE id=? LIMIT 1', [$tipo, $nome, $descricao, $unidade ?: 'un', $valor, $custo, $quantidadeEstoque, $estoqueMinimo, $id]);
    }
    // Só mexe na linha (CFTV / serralheria) quando o formulário mandou o campo.
    if (isset($post['catalogo_linha'])) {
        vn_exec('UPDATE vn_mobile_orcamento_catalogo SET linha = ? WHERE id = ? LIMIT 1', [vn_linha_catalogo($post['catalogo_linha'], $tipo), $id]);
    }
    // Como cobrar (por unidade ou por medida): só mexe quando o formulário mandou o campo; por medida, a unidade passa a ser a da medida.
    if (array_key_exists('catalogo_calculo', $post) && function_exists('vn_serr_calculo_valido')) {
        $calculo = vn_serr_calculo_valido($post['catalogo_calculo']);
        vn_exec('UPDATE vn_mobile_orcamento_catalogo SET calculo = ? WHERE id = ? LIMIT 1', [$calculo, $id]);
        if ($calculo !== '') vn_exec('UPDATE vn_mobile_orcamento_catalogo SET unidade = ? WHERE id = ? LIMIT 1', [vn_serr_calculo_unidade($calculo), $id]);
    }
    return $id;
}

function vn_atualizar_catalogo_item_v6(array $post): int {
    vn_ensure_mobile_tables();
    $origem = (string)($post['catalogo_origem'] ?? 'mobile');
    if ($origem === 'mobile') {
        return vn_atualizar_catalogo_orcamento_mobile_v6($post);
    }
    $id = (int)($post['catalogo_id'] ?? 0);
    if ($id <= 0) throw new Exception('Item invalido para editar.');
    if ($origem === 'materiais_orcamento' && vn_table_exists('materiais_orcamento')) {
        $tipo = vn_normalizar_tipo_orcamento_v6((string)($post['catalogo_tipo'] ?? 'material'));
        $nome = vn_clean($post['catalogo_nome'] ?? '');
        $descricao = trim((string)($post['catalogo_descricao'] ?? ''));
        $unidade = vn_clean($post['catalogo_unidade'] ?? 'un');
        $valor = vn_money_db($post['catalogo_valor'] ?? 0);
        $custo = vn_money_db($post['catalogo_custo'] ?? 0);
        $quantidadeEstoque = vn_money_db($post['catalogo_quantidade_estoque'] ?? 0);
        $foto = trim((string)($post['catalogo_foto_url'] ?? ''));
        if ($nome === '') throw new Exception('Informe o nome do item para editar.');
        $sets = [];
        $params = [];
        $map = ['tipo'=>$tipo,'nome'=>$nome,'descricao'=>$descricao ?: $nome,'unidade'=>$unidade ?: 'un','valor_unitario'=>$valor,'custo_unitario'=>$custo,'quantidade_estoque'=>$quantidadeEstoque,'quantidade'=>$quantidadeEstoque,'qtd'=>$quantidadeEstoque,'estoque'=>$quantidadeEstoque];
        // Só atualiza a foto quando uma nova foi enviada; senão mantém a atual.
        if ($foto !== '') $map['foto_url'] = $foto;
        foreach ($map as $col => $val) {
            if (vn_column_exists('materiais_orcamento', $col)) { $sets[] = "`$col`=?"; $params[] = $val; }
        }
        if ($sets) {
            $params[] = $id;
            vn_exec('UPDATE materiais_orcamento SET ' . implode(', ', $sets) . ' WHERE id=? LIMIT 1', $params);
        }
        return $id;
    }
    return vn_atualizar_catalogo_orcamento_mobile_v6($post);
}

function vn_catalogo_orcamento_mobile_v6(bool $somenteAtivos = true): array {
    vn_ensure_mobile_tables();
    $lista = [];
    $vistos = [];
    $add = function(array $r, string $origem) use (&$lista, &$vistos) {
        $tipo = vn_normalizar_tipo_orcamento_v6((string)($r['tipo'] ?? 'material'));
        $nome = vn_clean($r['nome'] ?? '');
        if ($nome === '') return;
        $desc = trim((string)($r['descricao'] ?? ''));
        if ($desc === '') $desc = $nome;
        $valor = (float)($r['valor_unitario'] ?? 0);
        $custo = (float)($r['custo_unitario'] ?? 0);
        $key = vn_norm_key($tipo . ' ' . $nome . ' ' . $valor);
        if (isset($vistos[$key])) return;
        $vistos[$key] = true;
        $lista[] = [
            'origem' => $origem,
            'id' => (int)($r['id'] ?? 0),
            'tipo' => $tipo,
            'nome' => $nome,
            'descricao' => $desc,
            'foto_url' => trim((string)($r['foto_url'] ?? '')),
            'unidade' => vn_clean($r['unidade'] ?? 'un'),
            'valor_unitario' => $valor,
            'custo_unitario' => $custo,
            'quantidade_estoque' => (float)($r['quantidade_estoque'] ?? $r['quantidade'] ?? $r['qtd'] ?? $r['estoque'] ?? 0),
            'estoque_minimo' => (isset($r['estoque_minimo']) && $r['estoque_minimo'] !== '') ? (float)$r['estoque_minimo'] : null,
            'linha' => vn_linha_catalogo($r['linha'] ?? '', $tipo),
            'calculo' => function_exists('vn_serr_calculo_valido') ? vn_serr_calculo_valido($r['calculo'] ?? '') : '',
        ];
    };

    $where = $somenteAtivos ? "WHERE status IS NULL OR status = '' OR LOWER(status) IN ('ativo','1','sim','s')" : '';
    foreach (vn_fetch_all("SELECT id, tipo, nome, descricao, foto_url, unidade, valor_unitario, custo_unitario, quantidade_estoque, estoque_minimo, linha, calculo FROM vn_mobile_orcamento_catalogo $where ORDER BY tipo ASC, nome ASC LIMIT 1000") as $r) {
        $add($r, 'mobile');
    }

    if (vn_table_exists('materiais_orcamento')) {
        $cols = [];
        foreach (['id','tipo','nome','descricao','unidade','valor_unitario','custo_unitario','quantidade_estoque','quantidade','qtd','estoque','status'] as $c) {
            if (vn_column_exists('materiais_orcamento', $c)) $cols[] = $c;
        }
        if (in_array('nome', $cols, true)) {
            $select = [];
            foreach (['id','tipo','nome','descricao','foto_url','unidade','valor_unitario','custo_unitario'] as $c) {
                $select[] = in_array($c, $cols, true) ? "`$c`" : "NULL AS `$c`";
            }
            $qCol = '';
            foreach (['quantidade_estoque','quantidade','qtd','estoque'] as $qc) { if (in_array($qc, $cols, true)) { $qCol = $qc; break; } }
            $select[] = $qCol !== '' ? "`$qCol` AS `quantidade_estoque`" : "0 AS `quantidade_estoque`";
            $where2 = ($somenteAtivos && in_array('status', $cols, true)) ? "WHERE status IS NULL OR status = '' OR LOWER(status) IN ('ativo','1','sim','s')" : '';
            foreach (vn_fetch_all('SELECT ' . implode(', ', $select) . " FROM materiais_orcamento $where2 ORDER BY nome ASC LIMIT 1000") as $r) {
                $add($r, 'materiais_orcamento');
            }
        }
    }

    usort($lista, function($a, $b){
        $c = strcmp((string)$a['tipo'], (string)$b['tipo']);
        return $c !== 0 ? $c : strcmp((string)$a['nome'], (string)$b['nome']);
    });
    return $lista;
}

function vn_salvar_orcamento_mobile_v4(array $post): int {
    global $VN_USUARIO_ID, $VN_USUARIO_NOME;
    vn_ensure_mobile_tables();
    $id = (int)($post['id_orcamento'] ?? 0);
    $clienteId = (int)($post['cliente_id'] ?? 0);
    $enderecoId = (int)($post['endereco_id'] ?? 0);
    $clienteNome = vn_clean($post['cliente_nome'] ?? '');
    $telefone = vn_clean($post['cliente_telefone'] ?? '');
    $email = vn_clean($post['cliente_email'] ?? '');
    $endereco = trim((string)($post['endereco'] ?? ''));
    if ($endereco === '') $endereco = vn_buscar_endereco_cliente_por_id($enderecoId, $clienteId);
    $status = vn_clean($post['status'] ?? 'Pendente') ?: 'Pendente';
    $validade = vn_date_db_or_null($post['validade'] ?? '');
    $desconto = vn_money_db($post['desconto'] ?? 0);
    $visitaDesc = vn_clean($post['visita_descricao'] ?? 'Visita técnica');
    $visitaValor = vn_money_db($post['visita_valor'] ?? 0);
    $maoDesc = vn_clean($post['mao_obra_descricao'] ?? 'Mão de obra');
    $maoValor = vn_money_db($post['mao_obra_valor'] ?? 0);
    $obs = trim((string)($post['observacoes'] ?? ''));
    $assinaturaTecnico = trim((string)($post['assinatura_tecnico'] ?? ''));
    if ($clienteNome === '') throw new Exception('Informe o cliente do orçamento.');
    $flags = [
        'mostrar_valor_itens' => vn_bool_post_v4($post, 'mostrar_valor_itens'),
        'mostrar_itens' => vn_bool_post_v4($post, 'mostrar_itens', 1),
        'mostrar_valor_unitario' => vn_bool_post_v4($post, 'mostrar_valor_unitario'),
        'mostrar_subtotal_itens' => vn_bool_post_v4($post, 'mostrar_subtotal_itens'),
        'mostrar_visita' => vn_bool_post_v4($post, 'mostrar_visita'),
        'mostrar_mao_obra' => vn_bool_post_v4($post, 'mostrar_mao_obra'),
        'mostrar_desconto' => vn_bool_post_v4($post, 'mostrar_desconto'),
        'mostrar_total_geral' => vn_bool_post_v4($post, 'mostrar_total_geral'),
    ];
    $tipos = $post['item_tipo'] ?? [];
    $desc = $post['item_desc'] ?? [];
    $qtds = $post['item_qtd'] ?? [];
    $precos = $post['item_preco'] ?? [];
    $custos = $post['item_custo'] ?? [];
    $origens = $post['item_origem'] ?? [];
    $linhasItem = $post['item_linha'] ?? [];
    $unidadesItem = $post['item_un'] ?? [];
    $itens = [];
    $subtotalItens = 0;
    $custoTotal = 0;
    foreach ($desc as $i => $d) {
        $d = vn_clean($d);
        if ($d === '') continue;
        $tipo = vn_clean($tipos[$i] ?? 'material');
        if (!in_array($tipo, ['equipamento','material','produto_usado','servico','mao_obra','outro'], true)) $tipo = 'material';
        $origem = vn_clean($origens[$i] ?? 'estoque');
        if (!in_array($origem, ['estoque','comprar'], true)) $origem = 'estoque';
        // Serviço/mão de obra não é peça física; sempre conta como "da base".
        if (in_array($tipo, ['servico','mao_obra'], true)) $origem = 'estoque';
        $q = max(0.01, (float)str_replace(',', '.', (string)($qtds[$i] ?? 1)));
        $p = vn_money_db($precos[$i] ?? 0);
        $c = vn_money_db($custos[$i] ?? 0);
        $t = round($q * $p, 2);
        $ct = round($q * $c, 2);
        $subtotalItens += $t;
        $custoTotal += $ct;
        $itens[] = ['tipo'=>$tipo, 'descricao'=>$d, 'quantidade'=>$q, 'valor_unitario'=>$p, 'custo_unitario'=>$c, 'total'=>$t, 'custo_total'=>$ct, 'origem_peca'=>$origem,
                    'linha'=>vn_categoria_valida($linhasItem[$i] ?? 'cftv'),
                    'unidade'=>function_exists('vn_serr_unidade_valida') ? vn_serr_unidade_valida($unidadesItem[$i] ?? '') : ''];
    }
    $total = max(0, round($subtotalItens + $visitaValor + $maoValor - $desconto, 2));
    $lucro = round($total - $custoTotal, 2);

    if ($id > 0 && vn_fetch_one('SELECT id FROM vn_mobile_orcamentos WHERE id = ? LIMIT 1', [$id])) {
        vn_exec('UPDATE vn_mobile_orcamentos SET cliente_id=?, cliente_nome=?, cliente_telefone=?, cliente_email=?, endereco=?, status=?, validade=?, valor_total=?, valor_custo=?, desconto=?, lucro=?, visita_descricao=?, visita_valor=?, mao_obra_descricao=?, mao_obra_valor=?, observacoes=?, assinatura_tecnico=?, mostrar_itens=?, mostrar_valor_itens=?, mostrar_valor_unitario=?, mostrar_subtotal_itens=?, mostrar_visita=?, mostrar_mao_obra=?, mostrar_desconto=?, mostrar_total_geral=?, atualizado_em=NOW() WHERE id=? LIMIT 1', [
            $clienteId ?: null, $clienteNome, $telefone, $email, $endereco, $status, $validade, $total, $custoTotal, $desconto, $lucro, $visitaDesc, $visitaValor, $maoDesc, $maoValor, $obs, $assinaturaTecnico,
            $flags['mostrar_itens'], $flags['mostrar_valor_itens'], $flags['mostrar_valor_unitario'], $flags['mostrar_subtotal_itens'], $flags['mostrar_visita'], $flags['mostrar_mao_obra'], $flags['mostrar_desconto'], $flags['mostrar_total_geral'], $id
        ]);
        // Guarda quais peças já foram marcadas como compradas para não perder na edição.
        $compradoMap = [];
        foreach (vn_fetch_all('SELECT descricao FROM vn_mobile_orcamento_itens WHERE orcamento_id = ? AND comprado = 1', [$id]) as $old) {
            $compradoMap[vn_lower((string)$old['descricao'])] = 1;
        }
        vn_exec('DELETE FROM vn_mobile_orcamento_itens WHERE orcamento_id = ?', [$id]);
        $orcId = $id;
    } else {
        $compradoMap = [];
        $numero = 'ORC-MOB-' . date('Ymd-His');
        vn_exec('INSERT INTO vn_mobile_orcamentos (numero, cliente_id, cliente_nome, cliente_telefone, cliente_email, endereco, status, validade, valor_total, valor_custo, desconto, lucro, visita_descricao, visita_valor, mao_obra_descricao, mao_obra_valor, observacoes, assinatura_tecnico, mostrar_itens, mostrar_valor_itens, mostrar_valor_unitario, mostrar_subtotal_itens, mostrar_visita, mostrar_mao_obra, mostrar_desconto, mostrar_total_geral, criado_por, criado_por_nome, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())', [
            $numero, $clienteId ?: null, $clienteNome, $telefone, $email, $endereco, $status, $validade, $total, $custoTotal, $desconto, $lucro, $visitaDesc, $visitaValor, $maoDesc, $maoValor, $obs, $assinaturaTecnico,
            $flags['mostrar_itens'], $flags['mostrar_valor_itens'], $flags['mostrar_valor_unitario'], $flags['mostrar_subtotal_itens'], $flags['mostrar_visita'], $flags['mostrar_mao_obra'], $flags['mostrar_desconto'], $flags['mostrar_total_geral'], $VN_USUARIO_ID, $VN_USUARIO_NOME
        ]);
        $orcId = vn_last_id();
    }
    foreach ($itens as $it) {
        $comprado = ($it['origem_peca'] === 'comprar' && isset($compradoMap[vn_lower($it['descricao'])])) ? 1 : 0;
        vn_exec('INSERT INTO vn_mobile_orcamento_itens (orcamento_id, tipo, descricao, quantidade, valor_unitario, custo_unitario, total, custo_total, origem_peca, comprado, linha, unidade) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$orcId, $it['tipo'], $it['descricao'], $it['quantidade'], $it['valor_unitario'], $it['custo_unitario'], $it['total'], $it['custo_total'], $it['origem_peca'], $comprado, $it['linha'], $it['unidade'] !== '' ? $it['unidade'] : null]);
    }
    // Tipo do orçamento (CFTV ou Serralheria). Formulário antigo, sem o campo, não mexe no que já estava salvo.
    if (isset($post['categoria'])) {
        vn_exec('UPDATE vn_mobile_orcamentos SET categoria = ? WHERE id = ? LIMIT 1', [vn_categoria_valida($post['categoria']), $orcId]);
    }
    vn_orcamento_salvar_extras((int)$orcId, $post);
    return $orcId;
}

/* ==========================
   Situações do pedido (as mesmas do aplicativo de referência) e a cor de cada uma
========================== */
function vn_pedido_status_lista(): array {
    return ['Pendente' => 'warn', 'Aguardando aprovação' => 'warn', 'Aprovado' => 'info', 'Em andamento' => 'info',
            'Aguardando pagamento' => 'info', 'Concluído' => 'ok', 'Garantia' => 'ok', 'Cancelado' => 'bad'];
}
/* Cor (tom) de uma situação: as conhecidas têm cor própria; as antigas seguem o grupo (pendente/aprovado/recusado). */
function vn_pedido_status_tom(string $status): string {
    $l = vn_pedido_status_lista();
    if (isset($l[$status])) return $l[$status];
    $k = vn_orc_status_kind($status);
    return $k === 'aprovado' ? 'info' : ($k === 'recusado' ? 'bad' : 'warn');
}
/* Texto da situação como aparece nas telas: "pendente" e "Pendente" viram "Pendente"; as desconhecidas ficam como vieram. */
function vn_pedido_status_label(string $status): string {
    $status = trim($status);
    if ($status === '') return 'Pendente';
    foreach (array_keys(vn_pedido_status_lista()) as $lbl) { if (vn_norm_key($lbl) === vn_norm_key($status)) return $lbl; }
    return $status;
}
/* Número do pedido como aparece na tela: 174-2026 (id + ano da criação). */
function vn_pedido_numero(array $o): string {
    $ano = substr((string)($o['criado_em'] ?? ''), 0, 4);
    return str_pad((string)(int)($o['id'] ?? 0), 3, '0', STR_PAD_LEFT) . '-' . ($ano !== '' && $ano !== '0000' ? $ano : date('Y'));
}

/* ==========================
   Pedido: campos da tela nova (prazo, equipamento, pagamento, garantia, cláusulas, relatório, fotos)
========================== */
function vn_pedido_meios_lista(): array {
    return ['Dinheiro', 'PIX', 'Boleto', 'Cartão de crédito', 'Cartão de débito', 'Transferência bancária', 'Cheque'];
}

/* Textos que se repetem em todo pedido e podem virar "padrão" (aparecem já preenchidos nos próximos). */
function vn_pedido_padroes(): array {
    vn_ensure_mobile_tables();
    $out = [];
    foreach (vn_fetch_all('SELECT chave, valor FROM vn_pedido_padroes') as $r) $out[(string)$r['chave']] = (string)($r['valor'] ?? '');
    return $out;
}

function vn_pedido_padrao_salvar(string $chave, string $valor): void {
    if (!in_array($chave, ['clausulas', 'condicoes_pagamento', 'garantia', 'meios_pagamento'], true)) return;
    vn_exec('REPLACE INTO vn_pedido_padroes (chave, valor, atualizado_em) VALUES (?, ?, ?)', [$chave, $valor, vn_now_sp()]);
}

/* Grava os campos novos do pedido (a função principal de salvar continua igual). */
function vn_orcamento_salvar_extras(int $orcId, array $post): void {
    $linha = function (string $k, int $max) use ($post): string { return mb_substr(vn_clean($post[$k] ?? ''), 0, $max); };
    $texto = function (string $k, int $max) use ($post): string { return mb_substr(trim(str_replace("\r", '', (string)($post[$k] ?? ''))), 0, $max); };

    $meios = [];
    $permitidos = vn_pedido_meios_lista();
    foreach ((array)($post['meios_pagamento'] ?? []) as $m) {
        $m = trim((string)$m);
        if (in_array($m, $permitidos, true) && !in_array($m, $meios, true)) $meios[] = $m;
    }
    $hora = (string)($post['compromisso_hora'] ?? '');
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) $hora = null;

    // Como o desconto foi montado na tela (só para reabrir igual): {"servico":{"tipo":"%","valor":10},"peca":{...}}
    $cfg = json_decode((string)($post['desconto_cfg'] ?? ''), true);
    $cfgOk = [];
    foreach (['servico', 'peca'] as $k) {
        $t = (string)($cfg[$k]['tipo'] ?? '');
        $v = (float)($cfg[$k]['valor'] ?? 0);
        if (in_array($t, ['%', 'R$'], true) && $v > 0) $cfgOk[$k] = ['tipo' => $t, 'valor' => round($v, 2)];
    }

    $textos = [
        'clausulas' => $texto('clausulas', 30000), 'condicoes_pagamento' => $texto('condicoes_pagamento', 4000), 'garantia' => $texto('garantia', 4000),
    ];
    vn_exec('UPDATE vn_mobile_orcamentos SET prazo_execucao=?, equipamento=?, defeito=?, condicoes_pagamento=?, meios_pagamento=?, garantia=?, clausulas=?, anotacoes=?, relatorio=?,
             compromisso_data=?, compromisso_hora=?, compromisso_desc=?, visita_data=?, desconto_cfg=?,
             mostrar_equipamento=?, mostrar_pagamento=?, mostrar_garantia=?, mostrar_relatorio=?, mostrar_fotos=?, mostrar_clausulas=? WHERE id=?', [
        $linha('prazo_execucao', 160), $linha('equipamento', 190), $texto('defeito', 4000), $textos['condicoes_pagamento'], implode(', ', $meios), $textos['garantia'], $textos['clausulas'],
        $texto('anotacoes', 4000), $texto('relatorio', 8000),
        vn_date_db_or_null($post['compromisso_data'] ?? ''), $hora, $linha('compromisso_desc', 180), vn_date_db_or_null($post['visita_data'] ?? ''), $cfgOk ? json_encode($cfgOk) : null,
        vn_bool_post_v4($post, 'mostrar_equipamento'), vn_bool_post_v4($post, 'mostrar_pagamento'), vn_bool_post_v4($post, 'mostrar_garantia'),
        vn_bool_post_v4($post, 'mostrar_relatorio'), vn_bool_post_v4($post, 'mostrar_fotos'), vn_bool_post_v4($post, 'mostrar_clausulas'), $orcId,
    ]);

    foreach (['clausulas', 'condicoes_pagamento', 'garantia'] as $k) {
        if (!empty($post['padrao_' . $k])) vn_pedido_padrao_salvar($k, $textos[$k]);
    }
    if (!empty($post['padrao_meios_pagamento'])) vn_pedido_padrao_salvar('meios_pagamento', implode(', ', $meios));

    // O compromisso marcado no formulário vira (ou atualiza) a linha de origem "pedido" na agenda do pedido.
    $cData = vn_date_db_or_null($post['compromisso_data'] ?? '');
    $cLinha = vn_fetch_one("SELECT id FROM vn_pedido_compromissos WHERE orcamento_id = ? AND origem = 'pedido' LIMIT 1", [$orcId]);
    if ($cData) {
        $cTitulo = $linha('compromisso_desc', 190) !== '' ? $linha('compromisso_desc', 190) : 'Atendimento';
        if ($cLinha) vn_exec('UPDATE vn_pedido_compromissos SET titulo = ?, data = ?, hora = ? WHERE id = ?', [$cTitulo, $cData, $hora, (int)$cLinha['id']]);
        else vn_exec("INSERT INTO vn_pedido_compromissos (orcamento_id, titulo, data, hora, origem) VALUES (?, ?, ?, ?, 'pedido')", [$orcId, $cTitulo, $cData, $hora]);
    } elseif ($cLinha) {
        vn_exec('DELETE FROM vn_pedido_compromissos WHERE id = ?', [(int)$cLinha['id']]);
    }

    $remover = array_map('intval', (array)($post['foto_remover'] ?? []));
    vn_orcamento_salvar_fotos($orcId, is_array($_FILES['fotos'] ?? null) ? $_FILES['fotos'] : [], $remover);
}

/* Resumo financeiro do pedido: o que foi recebido, o que falta, custos lançados e o resultado (recebido - custos). */
function vn_pedido_financeiro(int $id, float $total): array {
    vn_ensure_mobile_tables();
    $pagos = vn_fetch_all('SELECT * FROM vn_pedido_pagamentos WHERE orcamento_id = ? ORDER BY data DESC, id DESC', [$id]);
    $custos = vn_fetch_all('SELECT * FROM vn_pedido_custos WHERE orcamento_id = ? ORDER BY data DESC, id DESC', [$id]);
    $recebido = 0.0; foreach ($pagos as $p) $recebido += (float)$p['valor'];
    $custo = 0.0; foreach ($custos as $c) $custo += (float)$c['valor'];
    return ['pagamentos' => $pagos, 'custos' => $custos, 'recebido' => round($recebido, 2), 'a_receber' => round(max(0, $total - $recebido), 2),
            'custo' => round($custo, 2), 'resultado' => round($recebido - $custo, 2), 'pct' => $total > 0 ? min(100, round($recebido / $total * 100, 1)) : 0];
}

/* Títulos dos documentos que dá para gerar a partir do pedido (o conteúdo é o mesmo; muda o título). */
function vn_pedido_documentos(): array {
    return ['proposta-comercial' => 'Proposta comercial', 'proposta-de-investimento' => 'Proposta de investimento', 'contrato-de-servico' => 'Contrato de serviço',
            'orcamento' => 'Orçamento', 'fatura' => 'Fatura', 'lista-de-pecas' => 'Lista de peças', 'pedido' => 'Pedido',
            'resumo-financeiro' => 'Resumo financeiro do pedido', 'ordem-de-servico' => 'Ordem de serviço', 'projeto' => 'Projeto',
            'laudo-tecnico' => 'Laudo técnico', 'relatorio-tecnico' => 'Relatório técnico'];
}

/* Copia o pedido (dados e itens) para um novo, como "Pendente". Não copia fotos, recebimentos, custos nem agenda. */
function vn_duplicar_orcamento_mobile(int $id): int {
    global $VN_USUARIO_ID, $VN_USUARIO_NOME;
    $o = vn_fetch_one('SELECT * FROM vn_mobile_orcamentos WHERE id = ? LIMIT 1', [$id]);
    if (!$o) throw new Exception('Pedido não encontrado.');
    foreach (['id', 'criado_em', 'atualizado_em', 'materiais_baixados', 'materiais_baixados_em', 'compromisso_data', 'compromisso_hora', 'compromisso_desc', 'visita_data'] as $k) unset($o[$k]);
    $o['numero'] = 'ORC-MOB-' . date('Ymd-His');
    $o['status'] = 'Pendente';
    $o['criado_em'] = vn_now_sp();
    $o['criado_por'] = $VN_USUARIO_ID ?? null;
    $o['criado_por_nome'] = $VN_USUARIO_NOME ?? null;
    $novo = (int)vn_insert_dynamic('vn_mobile_orcamentos', $o);
    if ($novo <= 0) throw new Exception('Não foi possível duplicar o pedido.');
    foreach (vn_fetch_all('SELECT * FROM vn_mobile_orcamento_itens WHERE orcamento_id = ? ORDER BY id', [$id]) as $it) {
        foreach (['id', 'comprado'] as $k) unset($it[$k]);
        $it['orcamento_id'] = $novo;
        vn_insert_dynamic('vn_mobile_orcamento_itens', $it);
    }
    return $novo;
}

/* Fotos do pedido: valida, reduz (1920 px) e guarda em uploads/orcamentos/ID. Devolve quantas entraram. */
function vn_orcamento_salvar_fotos(int $orcId, array $files, array $remover = []): int {
    require_once __DIR__ . '/_vn_media.php';
    $base = __DIR__ . '/uploads/orcamentos';
    $dir = $base . '/' . $orcId;

    foreach ($remover as $fid) {
        $f = vn_fetch_one('SELECT * FROM vn_mobile_orcamento_fotos WHERE id = ? AND orcamento_id = ?', [(int)$fid, $orcId]);
        if (!$f) continue;
        $arq = __DIR__ . '/' . ltrim((string)$f['caminho'], '/');
        if (is_file($arq) && strpos(realpath($arq) ?: '', realpath($base) ?: "\0") === 0) @unlink($arq);
        vn_exec('DELETE FROM vn_mobile_orcamento_fotos WHERE id = ?', [(int)$f['id']]);
    }

    if (empty($files['name']) || !is_array($files['name'])) return 0;
    $existentes = (int)(vn_fetch_one('SELECT COUNT(*) AS q FROM vn_mobile_orcamento_fotos WHERE orcamento_id = ?', [$orcId])['q'] ?? 0);
    $mimes = ['jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'webp' => ['image/webp']];
    $salvas = 0;
    foreach ($files['name'] as $i => $nome) {
        if ($existentes + $salvas >= 12) break;   // limite por pedido
        if ((int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
        $tmp = (string)($files['tmp_name'][$i] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) continue;
        $tam = (int)($files['size'][$i] ?? 0);
        if ($tam <= 0 || $tam > 15 * 1024 * 1024) continue;
        $original = preg_replace('/[^a-zA-Z0-9._ -]/', '_', basename((string)$nome));
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!isset($mimes[$ext])) continue;
        $mime = '';
        if (function_exists('finfo_open') && ($fi = finfo_open(FILEINFO_MIME_TYPE))) { $mime = (string)finfo_file($fi, $tmp); finfo_close($fi); }
        if ($mime !== '' && !in_array($mime, $mimes[$ext], true)) continue;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) continue;
        $ht = $dir . '/.htaccess';
        if (!is_file($ht)) @file_put_contents($ht, "Options -Indexes\n<FilesMatch \"\\.(php|phtml|php[0-9]|phar|cgi|pl|asp|aspx|jsp)$\">\nRequire all denied\n</FilesMatch>\n", LOCK_EX);
        $novo = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (!@move_uploaded_file($tmp, $dir . '/' . $novo)) continue;
        vn_image_optimize($dir . '/' . $novo);
        vn_exec('INSERT INTO vn_mobile_orcamento_fotos (orcamento_id, caminho, nome_original) VALUES (?, ?, ?)', [$orcId, 'uploads/orcamentos/' . $orcId . '/' . $novo, mb_substr($original, 0, 190)]);
        $salvas++;
    }
    return $salvas;
}

/* ==========================
   Compras: peças de pedidos marcadas como "para comprar"
========================== */
function vn_pecas_para_comprar_v1(bool $somentePendentes = true): array {
    vn_ensure_mobile_tables();
    if (!vn_column_exists('vn_mobile_orcamento_itens', 'origem_peca')) return [];
    $where = "i.origem_peca = 'comprar'";
    if ($somentePendentes) $where .= ' AND i.comprado = 0';
    return vn_fetch_all(
        "SELECT i.id, i.orcamento_id, i.tipo, i.descricao, i.quantidade, i.valor_unitario, i.custo_unitario, i.total, i.custo_total, i.comprado,
                o.numero, o.cliente_nome, o.status, o.criado_em
         FROM vn_mobile_orcamento_itens i
         INNER JOIN vn_mobile_orcamentos o ON o.id = i.orcamento_id
         WHERE $where
         ORDER BY i.comprado ASC, o.criado_em DESC, i.id ASC
         LIMIT 500"
    );
}

function vn_marcar_peca_comprada_v1(int $itemId, int $comprado): string {
    if ($itemId <= 0) throw new Exception('Item inválido.');
    $comprado = $comprado ? 1 : 0;
    $n = vn_exec('UPDATE vn_mobile_orcamento_itens SET comprado = ? WHERE id = ? LIMIT 1', [$comprado, $itemId]);
    if ($n <= 0) {
        $existe = vn_fetch_one('SELECT id FROM vn_mobile_orcamento_itens WHERE id = ? LIMIT 1', [$itemId]);
        if (!$existe) throw new Exception('Peça não encontrada.');
    }
    return $comprado ? 'Peça marcada como comprada.' : 'Peça voltou para a lista de compras.';
}

/** Garante as colunas de controle da baixa de materiais no pedido. */
function vn_orc_baixa_setup(): void {
    if (!vn_table_exists('vn_mobile_orcamentos')) return;
    vn_add_col_if_missing('vn_mobile_orcamentos', 'materiais_baixados', "TINYINT(1) NOT NULL DEFAULT 0");
    vn_add_col_if_missing('vn_mobile_orcamentos', 'materiais_baixados_em', "DATETIME NULL");
}

/** IDs dos pedidos que já tiveram a baixa de materiais aplicada. */
function vn_orc_baixados_ids(): array {
    if (!vn_table_exists('vn_mobile_orcamentos') || !vn_column_exists('vn_mobile_orcamentos', 'materiais_baixados')) return [];
    $ids = [];
    foreach (vn_fetch_all('SELECT id FROM vn_mobile_orcamentos WHERE materiais_baixados = 1') as $r) {
        $ids[(int)$r['id']] = true;
    }
    return $ids;
}

/**
 * Dá baixa no estoque dos materiais de um pedido.
 * Só baixa itens do tipo material que estejam cadastrados no estoque (catálogo) e cuja origem não seja "comprar".
 * Registra cada movimento em vn_mobile_estoque_saidas e marca o pedido para não baixar duas vezes.
 */
function vn_baixar_materiais_orcamento_v1(int $orcId, string $responsavel = ''): string {
    if ($orcId <= 0) throw new Exception('Pedido inválido.');
    vn_ensure_mobile_tables();
    vn_orc_baixa_setup();

    $orc = vn_fetch_one('SELECT * FROM vn_mobile_orcamentos WHERE id = ? LIMIT 1', [$orcId]);
    if (!$orc) throw new Exception('Pedido não encontrado.');
    if ((int)($orc['materiais_baixados'] ?? 0) === 1) {
        throw new Exception('Os materiais deste pedido já tiveram baixa no estoque. Use "Desfazer baixa" se precisar refazer.');
    }
    $numero = (string)($orc['numero'] ?? ('ORC #' . $orcId));

    $itens = vn_fetch_all('SELECT * FROM vn_mobile_orcamento_itens WHERE orcamento_id = ? ORDER BY id ASC', [$orcId]);
    if (empty($itens)) throw new Exception('Este pedido não tem itens.');

    // Catálogo do estoque indexado pelo nome normalizado.
    $catByKey = [];
    foreach (vn_fetch_all("SELECT id, nome, tipo, quantidade_estoque FROM vn_mobile_orcamento_catalogo") as $c) {
        $catByKey[vn_norm_key((string)$c['nome'])] = $c;
    }

    vn_estoque_saidas_tabela_v1();
    return vn_tx(function () use ($itens, $catByKey, $numero, $responsavel, $orcId) {
    $baixados = []; $semCadastro = []; $pulados = 0;

    foreach ($itens as $it) {
        $tipo = vn_lower((string)($it['tipo'] ?? 'material'));
        $origem = vn_lower((string)($it['origem_peca'] ?? 'estoque'));
        // Serviços / mão de obra não saem do estoque.
        if (strpos($tipo, 'servic') !== false || strpos($tipo, 'mao') !== false) continue;
        // Itens "para comprar" não estão no estoque (ficam na tela de Compras).
        if ($origem === 'comprar') { $pulados++; continue; }

        $qtd = (float)($it['quantidade'] ?? 0);
        if ($qtd <= 0) continue;

        $nome = (string)($it['descricao'] ?? '');
        $key = vn_norm_key($nome);
        $cat = $catByKey[$key] ?? null;
        if (!$cat) { $semCadastro[] = $nome; continue; }

        $catId = (int)$cat['id'];
        vn_estoque_ajustar($catId, -$qtd, true); // a baixa do pedido sempre acontece (o estoque pode ficar negativo para acerto)
        $novo = (float)$cat['quantidade_estoque'] - $qtd;
        vn_exec('INSERT INTO vn_mobile_estoque_saidas (catalogo_id, origem, item_nome, item_tipo, quantidade, motivo, responsavel, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
            [$catId, 'mobile', (string)$cat['nome'], (string)$cat['tipo'], $qtd, 'Saída do pedido ' . $numero, ($responsavel !== '' ? $responsavel : null)]);
        $catByKey[$key]['quantidade_estoque'] = $novo; // caso o mesmo item apareça de novo
        $baixados[] = (string)$cat['nome'] . ' (' . number_format($qtd, 2, ',', '.') . ')';
    }

    if (empty($baixados)) {
        $msg = 'Nenhum material do estoque foi baixado neste pedido.';
        if ($semCadastro) $msg .= ' Itens sem cadastro no estoque: ' . implode(', ', array_unique($semCadastro)) . '.';
        if ($pulados) $msg .= ' ' . $pulados . ' item(ns) marcado(s) como "comprar" foram ignorados.';
        throw new Exception($msg);
    }

    vn_exec('UPDATE vn_mobile_orcamentos SET materiais_baixados = 1, materiais_baixados_em = NOW() WHERE id = ? LIMIT 1', [$orcId]);

    $msg = 'Baixa de estoque registrada para ' . count($baixados) . ' material(is) do pedido ' . $numero . ': ' . implode(', ', $baixados) . '.';
    if ($semCadastro) $msg .= ' Não baixados (sem cadastro no estoque): ' . implode(', ', array_unique($semCadastro)) . '.';
    if ($pulados) $msg .= ' ' . $pulados . ' item(ns) "para comprar" foram ignorados.';
    return $msg;
    });
}

/** Desfaz a baixa: devolve ao estoque e remove os movimentos de saída deste pedido. */
function vn_estornar_materiais_orcamento_v1(int $orcId): string {
    if ($orcId <= 0) throw new Exception('Pedido inválido.');
    vn_orc_baixa_setup();
    $orc = vn_fetch_one('SELECT * FROM vn_mobile_orcamentos WHERE id = ? LIMIT 1', [$orcId]);
    if (!$orc) throw new Exception('Pedido não encontrado.');
    if ((int)($orc['materiais_baixados'] ?? 0) !== 1) throw new Exception('Este pedido não tem baixa de materiais para desfazer.');

    $numero = (string)($orc['numero'] ?? ('ORC #' . $orcId));
    $motivo = 'Saída do pedido ' . $numero;
    return vn_tx(function () use ($motivo, $orcId) {
    $devolvidos = 0;
    if (vn_table_exists('vn_mobile_estoque_saidas')) {
        $saidas = vn_fetch_all('SELECT * FROM vn_mobile_estoque_saidas WHERE motivo = ?', [$motivo]);
        foreach ($saidas as $s) {
            $catId = (int)($s['catalogo_id'] ?? 0);
            $qtd = (float)($s['quantidade'] ?? 0);
            if ($catId > 0) {
                vn_estoque_ajustar($catId, $qtd); // devolve ao estoque (se o item ainda existir; senão não altera nada)
            }
            vn_exec('DELETE FROM vn_mobile_estoque_saidas WHERE id = ? LIMIT 1', [(int)$s['id']]);
            $devolvidos++;
        }
    }
    vn_exec('UPDATE vn_mobile_orcamentos SET materiais_baixados = 0, materiais_baixados_em = NULL WHERE id = ? LIMIT 1', [$orcId]);
    return 'Baixa desfeita: ' . $devolvidos . ' material(is) devolvido(s) ao estoque.';
    });
}

function vn_orc_tipo_label_v4(string $tipo): string {
    return ['equipamento'=>'Equipamento','material'=>'Material','produto_usado'=>'Produto usado','servico'=>'Serviço','mao_obra'=>'Mão de obra','ferramenta'=>'Ferramenta','ferramenta_outro'=>'Outros','outro'=>'Outro'][$tipo] ?? 'Material';
}

/** Tipos que pertencem à área de Ferramentas (sem valor/lucro). */
function vn_tipos_ferramenta(): array { return ['ferramenta', 'ferramenta_outro']; }
function vn_tipos_ferramenta_sql(): string { return "'ferramenta','ferramenta_outro'"; }
function vn_is_tipo_ferramenta(string $tipo): bool { return in_array($tipo, vn_tipos_ferramenta(), true); }

function vn_pdf_logo_to_jpeg_bin(): array {
    $file = vn_abs_file_from_mobile(['vn.png', 'assets/img/logo-vn-system.svg', '../vn.png', '../../vn.png', '../../../vn.png']);
    if ($file === '') return [];
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $bin = @file_get_contents($file);
    if (!$bin) return [];
    if (in_array($ext, ['jpg','jpeg'], true)) {
        $info = @getimagesizefromstring($bin);
        if ($info) return ['data'=>$bin, 'w'=>(int)$info[0], 'h'=>(int)$info[1]];
    }
    if (!function_exists('imagecreatetruecolor')) return [];
    $img = null;
    if ($ext === 'png' && function_exists('imagecreatefrompng')) $img = @imagecreatefrompng($file);
    elseif ($ext === 'webp' && function_exists('imagecreatefromwebp')) $img = @imagecreatefromwebp($file);
    elseif ($ext === 'gif' && function_exists('imagecreatefromgif')) $img = @imagecreatefromgif($file);
    elseif (in_array($ext, ['jpg','jpeg'], true) && function_exists('imagecreatefromjpeg')) $img = @imagecreatefromjpeg($file);
    if (!$img) return [];
    $w = imagesx($img); $h = imagesy($img);
    $canvas = imagecreatetruecolor($w, $h);
    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefilledrectangle($canvas, 0, 0, $w, $h, $white);
    imagecopy($canvas, $img, 0, 0, 0, 0, $w, $h);
    ob_start(); imagejpeg($canvas, null, 92); $jpg = ob_get_clean();
    imagedestroy($canvas); imagedestroy($img);
    return $jpg ? ['data'=>$jpg, 'w'=>$w, 'h'=>$h] : [];
}

function vn_pdf_local_image_to_jpeg_bin(string $relPath): array {
    $relPath = trim($relPath);
    if ($relPath === '') return [];
    $file = __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($relPath, '/\\'));
    if (!is_file($file)) return [];
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $bin = @file_get_contents($file);
    if (!$bin) return [];
    if (in_array($ext, ['jpg','jpeg'], true)) {
        $info = @getimagesizefromstring($bin);
        if ($info) return ['data'=>$bin, 'w'=>(int)$info[0], 'h'=>(int)$info[1]];
    }
    if (!function_exists('imagecreatetruecolor')) return [];
    $img = null;
    if ($ext === 'png' && function_exists('imagecreatefrompng')) $img = @imagecreatefrompng($file);
    elseif ($ext === 'webp' && function_exists('imagecreatefromwebp')) $img = @imagecreatefromwebp($file);
    elseif ($ext === 'gif' && function_exists('imagecreatefromgif')) $img = @imagecreatefromgif($file);
    elseif (in_array($ext, ['jpg','jpeg'], true) && function_exists('imagecreatefromjpeg')) $img = @imagecreatefromjpeg($file);
    if (!$img) return [];
    $w = imagesx($img); $h = imagesy($img);
    $canvas = imagecreatetruecolor($w, $h);
    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefilledrectangle($canvas, 0, 0, $w, $h, $white);
    imagecopy($canvas, $img, 0, 0, 0, 0, $w, $h);
    ob_start(); imagejpeg($canvas, null, 90); $jpg = ob_get_clean();
    imagedestroy($canvas); imagedestroy($img);
    return $jpg ? ['data'=>$jpg, 'w'=>$w, 'h'=>$h] : [];
}

class VNMobPdfV5 {
    private array $pages = [];
    private array $pageImages = [];
    private array $images = [];
    private string $content = '';
    private float $w = 595.28;
    private float $h = 841.89;
    private float $y = 36;
    private function enc($s): string { $s = preg_replace('/\s+/', ' ', trim((string)$s)); $s = strtr($s, ['—' => '-', '–' => '-', '…' => '...', '•' => '-']); if (function_exists('iconv')) { $c = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $s); if ($c !== false) $s = $c; } return str_replace(['\\','(',')'], ['\\\\','\\(','\\)'], $s); }
    private function cmd($s): void { $this->content .= $s . "\n"; }
    private function yp($top): float { return $this->h - $top; }
    private function color($r,$g,$b): void { $this->cmd(sprintf('%.3f %.3f %.3f rg', $r/255, $g/255, $b/255)); }
    private function stroke($r,$g,$b): void { $this->cmd(sprintf('%.3f %.3f %.3f RG', $r/255, $g/255, $b/255)); }
    public function line($x1,$y1,$x2,$y2,$rgb=[191,219,254]): void { $this->stroke($rgb[0],$rgb[1],$rgb[2]); $this->cmd(sprintf('%.2f %.2f m %.2f %.2f l S',$x1,$this->yp($y1),$x2,$this->yp($y2))); }
    public function rect($x,$y,$w,$h,$fill=null,$stroke=[191,219,254]): void { if($fill)$this->color($fill[0],$fill[1],$fill[2]); $this->stroke($stroke[0],$stroke[1],$stroke[2]); $this->cmd(sprintf('%.2f %.2f %.2f %.2f re %s',$x,$this->yp($y+$h),$w,$h,$fill?'B':'S')); }
    public function text($txt,$x,$y,$size=9,$bold=false,$rgb=[30,58,138]): void { $this->color($rgb[0],$rgb[1],$rgb[2]); $font=$bold?'/F2':'/F1'; $this->cmd(sprintf('BT %s %d Tf 1 0 0 1 %.2f %.2f Tm (%s) Tj ET',$font,$size,$x,$this->yp($y),$this->enc($txt))); }
    private function wrap($txt,$chars): array { $txt=trim((string)$txt); if($txt==='')return ['Não informado']; $out=[]; foreach(explode("\n", $txt) as $p){ foreach(explode("\n", wordwrap($p, max(8,(int)$chars), "\n", true)) as $line) $out[]=$line; } return $out ?: ['Não informado']; }
    private function need($height): void { if($this->y + $height > 790) $this->newPage(); }
    private function footer(): void { $this->line(36,820,559,820,[191,219,254]); $this->text('VN System - (11) 96919-0724',36,832,7,false,[37,99,235]); $this->text(date('d/m/Y H:i'),500,832,7,false,[37,99,235]); }
    private function newPage(): void { $this->footer(); $this->pages[]=['content'=>$this->content, 'images'=>$this->pageImages]; $this->content=''; $this->pageImages=[]; $this->y=36; $this->headerMini(); }
    private function imageJpeg(string $name, array $img, float $x, float $yTop, float $boxW, float $boxH): void { if (empty($img['data']) || empty($img['w']) || empty($img['h'])) return; $this->images[$name]=$img; $this->pageImages[$name]=true; $iw=(float)$img['w']; $ih=(float)$img['h']; $scale=min($boxW/$iw,$boxH/$ih); $dw=$iw*$scale; $dh=$ih*$scale; $dx=$x+(($boxW-$dw)/2); $dy=$yTop+(($boxH-$dh)/2); $this->cmd('q'); $this->cmd(sprintf('%.2f 0 0 %.2f %.2f %.2f cm /%s Do',$dw,$dh,$dx,$this->yp($dy+$dh),$name)); $this->cmd('Q'); }
    public function header($numero = ''): void { $this->rect(36,28,523,8,[29,78,216],[29,78,216]); if ((string)$numero !== '') { $tit = mb_strtoupper((string)$numero); $this->text($tit, max(300, 548 - strlen($tit) * 7.4), 74, 13, true, [29,78,216]); } $this->rect(36,44,523,98,null,[37,99,235]); $this->rect(48,60,64,56,[255,255,255],[191,219,254]); $logo=vn_pdf_logo_to_jpeg_bin(); if($logo)$this->imageJpeg('LogoVN',$logo,52,64,56,46); else $this->text('VN',64,94,22,true,[29,78,216]); $this->text('VN SYSTEM',124,66,19,true,[29,78,216]); $this->text('CNPJ: 58.181.863/0001-92',124,92,9); $this->text('Telefone: (11) 96919-0724',124,108,9); $this->text('Rua Ana Maria, 46 - São Bernardo do Campo/SP - CEP 09669-060',124,124,8); $this->y=160; }
    private function headerMini(): void { $this->rect(36,28,523,7,[29,78,216],[29,78,216]); $this->text('VN System',36,50,10,true,[29,78,216]); $this->y=70; }
    public function section($title): void { $this->need(32); $this->rect(36,$this->y,523,19,[37,99,235],[37,99,235]); $this->text(mb_strtoupper($title),44,$this->y+13,9,true,[255,255,255]); $this->y+=30; }
    public function box($label,$value,$x,$w,$h=44): void { $this->rect($x,$this->y,$w,$h,[239,246,255],[191,219,254]); $this->text(mb_strtoupper($label),$x+7,$this->y+12,7,true,[37,99,235]); $yy=$this->y+27; foreach(array_slice($this->wrap($value,(int)($w/5.3)),0,3) as $l){$this->text($l,$x+7,$yy,8.5);$yy+=10;} }
    public function two($l1,$v1,$l2,$v2,$h=46): void { $this->need($h+10); $this->box($l1,$v1,36,255,$h); $this->box($l2,$v2,304,255,$h); $this->y += $h+10; }
    public function block($label,$value): void { $lines=$this->wrap($value,86); $h=max(44,24+count($lines)*11); $this->need($h+8); $this->rect(36,$this->y,523,$h,null,[191,219,254]); $this->text(mb_strtoupper($label),44,$this->y+12,7.5,true,[37,99,235]); $yy=$this->y+28; foreach($lines as $l){$this->text($l,44,$yy,9);$yy+=11;} $this->y += $h+8; }
    public function row(array $cols, array $widths, bool $head=false): void { $descIndex=2; $lines=$this->wrap((string)($cols[$descIndex]??''), $head ? 30 : (int)(($widths[$descIndex]??230)/4.5)); $h=$head?28:max(30,16+count($lines)*9); $this->need($h+5); $x=36; foreach($cols as $i=>$c){$w=$widths[$i]??60; $this->rect($x,$this->y,$w,$h,$head?[239,246,255]:null,[191,219,254]); $cellLines=$i===$descIndex?$lines:$this->wrap((string)$c,(int)($w/4.6)); $yy=$this->y+17; foreach(array_slice($cellLines,0,5) as $line){$this->text($line,$x+4,$yy,$head?7.5:8,$head,[30,58,138]);$yy+=9;} $x+=$w;} $this->y+=$h; }
    public function total($label,$value): void { $this->need(45); $this->rect(350,$this->y,209,34,[236,253,245],[134,239,172]); $this->text(mb_strtoupper($label),358,$this->y+14,8,true,[22,101,52]); $this->text($value,455,$this->y+24,14,true,[22,101,52]); $this->y += 44; }
    public function sign(): void { $this->need(108); $this->y += 58; $assinVN = vn_pdf_local_image_to_jpeg_bin('assinatura-vnsystem.jpg'); if (!$assinVN) $assinVN = vn_pdf_local_image_to_jpeg_bin('assinatura-vnsystem.png'); if ($assinVN) $this->imageJpeg('AssinVN', $assinVN, 340, $this->y - 52, 190, 48); $this->line(58,$this->y,260,$this->y,[29,78,216]); $this->line(335,$this->y,535,$this->y,[29,78,216]); $this->text('Cliente / responsável',100,$this->y+14,9); $this->text('VN System',410,$this->y+14,9); }
    public function signRetirada(string $retirante, string $assinaturaPath = ''): void { $this->need(112); $this->y += 58; if ($assinaturaPath !== '') { $ret = vn_pdf_local_image_to_jpeg_bin($assinaturaPath); if ($ret) $this->imageJpeg('AssinRet', $ret, 68, $this->y - 52, 180, 48); } $assinVN = vn_pdf_local_image_to_jpeg_bin('assinatura-vnsystem.jpg'); if (!$assinVN) $assinVN = vn_pdf_local_image_to_jpeg_bin('assinatura-vnsystem.png'); if ($assinVN) $this->imageJpeg('AssinVN', $assinVN, 340, $this->y - 52, 190, 48); $this->line(58,$this->y,260,$this->y,[29,78,216]); $this->line(335,$this->y,535,$this->y,[29,78,216]); $this->text($retirante !== '' ? $retirante : 'Assinatura de quem retira', 70, $this->y+14, 9, true); $this->text('Assinatura de quem retira os materiais', 70, $this->y+26, 7.5, false, [37,99,235]); $this->text('VN System', 410, $this->y+14, 9, true); $this->text('Assinatura da empresa', 400, $this->y+26, 7.5, false, [37,99,235]); $this->y += 36; }
    public function signFuncionario(string $nome, string $assinaturaPath = ''): void { $this->need(112); $this->y += 58; if ($assinaturaPath !== '') { $ass = vn_pdf_local_image_to_jpeg_bin($assinaturaPath); if ($ass) $this->imageJpeg('AssinFunc', $ass, 68, $this->y - 52, 180, 48); } $assinVN = vn_pdf_local_image_to_jpeg_bin('assinatura-vnsystem.jpg'); if (!$assinVN) $assinVN = vn_pdf_local_image_to_jpeg_bin('assinatura-vnsystem.png'); if ($assinVN) $this->imageJpeg('AssinVN', $assinVN, 340, $this->y - 52, 190, 48); $this->line(58,$this->y,260,$this->y,[29,78,216]); $this->line(335,$this->y,535,$this->y,[29,78,216]); $this->text($nome !== '' ? $nome : 'Funcionário responsável', 70, $this->y+14, 9, true); $this->text('Assinatura do funcionário responsável pelas saídas', 70, $this->y+26, 7.5, false, [37,99,235]); $this->text('VN System', 410, $this->y+14, 9, true); $this->text('Assinatura da empresa', 400, $this->y+26, 7.5, false, [37,99,235]); $this->y += 36; }
    /* Texto longo (cláusulas, relatório): continua na página seguinte quando não cabe. */
    public function longText($label,$value): void { $lines=$this->wrap($value,92); $n=count($lines); $i=0; $first=true; while($i<$n){ $this->need(64); $per=max(3,(int)floor((790-$this->y-26)/11)); $chunk=array_slice($lines,$i,$per); $h=24+count($chunk)*11; $this->rect(36,$this->y,523,$h,null,[191,219,254]); $this->text(mb_strtoupper($label).($first?'':' (continuação)'),44,$this->y+12,7.5,true,[37,99,235]); $yy=$this->y+28; foreach($chunk as $l){$this->text($l,44,$yy,9);$yy+=11;} $this->y+=$h+8; $i+=count($chunk); $first=false; if($i<$n) $this->newPage(); } }
    /* Até duas fotos lado a lado. */
    public function photoRow(array $imgs): void { $imgs=array_values(array_filter($imgs, function($x){return !empty($x['data']);})); if(!$imgs) return; $this->need(196); $this->rect(36,$this->y,523,186,[249,250,251],[203,213,225]); foreach($imgs as $k=>$img){ $this->imageJpeg('FT'.(count($this->images)+1),$img,44+$k*258,$this->y+10,247,166); } $this->y+=194; }
    public function equipmentPhoto(string $title, array $img): void { if (empty($img['data'])) return; $this->need(118); $this->rect(36,$this->y,523,108,[249,250,251],[203,213,225]); $this->text($title,44,$this->y+14,8.5,true,[37,99,235]); $name='EQ'.(count($this->images)+1); $this->imageJpeg($name,$img,44,$this->y+22,160,80); $this->y += 116; }
    public function output(): string { $this->footer(); $this->pages[]=['content'=>$this->content,'images'=>$this->pageImages]; $objects=[]; $add=function($body)use(&$objects){$objects[]=$body;return count($objects);}; $font=$add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>'); $bold=$add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>'); $pagesId=count($objects)+1; $objects[]=''; $imgIds=[]; foreach($this->images as $name=>$img){$imgIds[$name]=$add('<< /Type /XObject /Subtype /Image /Width '.(int)$img['w'].' /Height '.(int)$img['h'].' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($img['data'])." >>\nstream\n".$img['data']."\nendstream");} $pageRefs=[]; foreach($this->pages as $page){$content=$page['content']; $cid=$add('<< /Length '.strlen($content)." >>\nstream\n$content\nendstream"); $parts=[]; foreach(array_keys($page['images']??[]) as $name){if(isset($imgIds[$name]))$parts[]="/$name ".$imgIds[$name].' 0 R';} $xRes=$parts?('/XObject << '.implode(' ',$parts).' >>'):''; $res="<< /Font << /F1 {$font} 0 R /F2 {$bold} 0 R >> {$xRes} >>"; $pid=$add("<< /Type /Page /Parent {$pagesId} 0 R /MediaBox [0 0 {$this->w} {$this->h}] /Resources {$res} /Contents {$cid} 0 R >>"); $pageRefs[]="$pid 0 R";} $objects[$pagesId-1]='<< /Type /Pages /Kids ['.implode(' ',$pageRefs).'] /Count '.count($pageRefs).' >>'; $catalog=$add("<< /Type /Catalog /Pages {$pagesId} 0 R >>"); $pdf="%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $offs=[0]; foreach($objects as $i=>$body){$id=$i+1;$offs[$id]=strlen($pdf);$pdf.="$id 0 obj\n$body\nendobj\n";} $xref=strlen($pdf); $pdf.='xref'."\n0 ".(count($objects)+1)."\n0000000000 65535 f \n"; for($i=1;$i<=count($objects);$i++)$pdf.=sprintf("%010d 00000 n \n",$offs[$i]); $pdf.="trailer\n<< /Size ".(count($objects)+1)." /Root {$catalog} 0 R >>\nstartxref\n{$xref}\n%%EOF"; return $pdf; }
}

function vn_pdf_orcamento_mobile_v4(int $id, string $titulo = ''): string {
    $orc = vn_orcamento_mobile_get_v4($id);
    if (!$orc) throw new Exception('Orçamento não encontrado.');
    if (vn_clean($orc['endereco'] ?? '') === '') $orc['endereco'] = vn_buscar_endereco_cliente_por_id(0, (int)($orc['cliente_id'] ?? 0));
    // Layout novo, com a marca da categoria do orçamento (CFTV ou Serralheria). Se algo falhar, o layout clássico abaixo continua valendo.
    if (is_file(__DIR__ . '/_vn_pdf_docs.php')) {
        try {
            require_once __DIR__ . '/_vn_pdf_docs.php';
            return vn_pdf_orcamento_v6($orc, $titulo);
        } catch (Throwable $e) {
            error_log('PDF do orçamento (layout novo) falhou, usando o clássico: ' . $e->getMessage());
        }
    }
    $pdf = new VNMobPdfV5();
    // Cabeçalho com os dados da empresa; o título do documento (Proposta, Fatura...) só aparece quando escolhido em "gerar documento".
    $pdf->header($titulo);
    $pdf->section('1. Dados do cliente');
    $pdf->two('Cliente', $orc['cliente_nome'] ?? '', 'Contato', $orc['cliente_telefone'] ?? '');
    $pdf->two('E-mail', $orc['cliente_email'] ?? '', 'Validade', vn_date_br($orc['validade'] ?? ''));
    $pdf->block('Endereço do atendimento', $orc['endereco'] ?? 'Não informado');
    $prazoExec = trim((string)($orc['prazo_execucao'] ?? ''));
    $equipDoc = (int)($orc['mostrar_equipamento'] ?? 1) === 1 ? trim((string)($orc['equipamento'] ?? '')) : '';
    $defeitoDoc = (int)($orc['mostrar_equipamento'] ?? 1) === 1 ? trim((string)($orc['defeito'] ?? '')) : '';
    if ($equipDoc !== '' || $prazoExec !== '') $pdf->two('Equipamento', $equipDoc !== '' ? $equipDoc : '-', 'Prazo de execução', $prazoExec !== '' ? $prazoExec : '-');
    if ($defeitoDoc !== '') $pdf->block('Defeito relatado', $defeitoDoc);
    $showValues = (int)($orc['mostrar_valor_itens'] ?? 1) === 1;
    $showUnit = (int)($orc['mostrar_valor_unitario'] ?? 1) === 1;
    $showSub = (int)($orc['mostrar_subtotal_itens'] ?? 1) === 1;
    if ((int)($orc['mostrar_itens'] ?? 1) === 1) {
    $pdf->section('2. Itens do orçamento');
    if ($showValues) { $headers = ['#','Tipo','Descrição','Qtd']; $widths=[24,70,235,45]; if ($showUnit) {$headers[]='Valor un.'; $widths[]=74;} if ($showSub) {$headers[]='Subtotal'; $widths[]=75;} }
    else { $headers = ['#','Tipo','Descrição','Qtd']; $widths=[24,80,365,54]; }
    $pdf->row($headers,$widths,true);
    $itens = $orc['itens'] ?? [];
    if (!$itens) $itens = [['tipo'=>'material','descricao'=>'Nenhum item informado','quantidade'=>0,'valor_unitario'=>0,'total'=>0]];
    $catalogFotos = [];
    foreach (vn_fetch_all("SELECT tipo, nome, foto_url FROM vn_mobile_orcamento_catalogo WHERE foto_url IS NOT NULL AND foto_url <> ''") as $r) {
        $k = vn_norm_key((string)($r['nome'] ?? ''));
        if ($k !== '') $catalogFotos[$k] = ['tipo'=>(string)($r['tipo'] ?? ''), 'foto_url'=>(string)($r['foto_url'] ?? '')];
    }
    foreach ($itens as $idx=>$it) { $row=[(string)($idx+1), vn_orc_tipo_label_v4((string)($it['tipo'] ?? 'material')), (string)($it['descricao'] ?? ''), number_format((float)($it['quantidade'] ?? 1),2,',','.')]; if ($showValues && $showUnit) $row[] = vn_money($it['valor_unitario'] ?? 0); if ($showValues && $showSub) $row[] = vn_money($it['total'] ?? 0); $pdf->row($row,$widths,false); }
    foreach ($itens as $it) {
        $tipo = (string)($it['tipo'] ?? '');
        if ($tipo !== 'equipamento') continue;
        $descKey = vn_norm_key((string)($it['descricao'] ?? ''));
        $foto = '';
        foreach ($catalogFotos as $nomeKey => $meta) {
            if ($nomeKey !== '' && strpos($descKey, $nomeKey) !== false) { $foto = (string)($meta['foto_url'] ?? ''); break; }
        }
        if ($foto === '') continue;
        $img = vn_pdf_local_image_to_jpeg_bin($foto);
        if ($img) $pdf->equipmentPhoto('Foto do equipamento: ' . (string)($it['descricao'] ?? 'Equipamento'), $img);
    }
    }
    $pdf->section('3. Valores do orçamento');
    $valorItens = array_sum(array_map(fn($x)=>(float)($x['total'] ?? 0), $orc['itens'] ?? []));
    $financeiros = [];
    if ($showValues) $financeiros[] = ['Subtotal dos itens', vn_money($valorItens)];
    if ((int)($orc['mostrar_visita'] ?? 1) === 1) $financeiros[] = [$orc['visita_descricao'] ?: 'Visita técnica', vn_money($orc['visita_valor'] ?? 0)];
    if ((int)($orc['mostrar_mao_obra'] ?? 1) === 1) $financeiros[] = [$orc['mao_obra_descricao'] ?: 'Mão de obra', vn_money($orc['mao_obra_valor'] ?? 0)];
    if ((int)($orc['mostrar_desconto'] ?? 1) === 1) $financeiros[] = ['Desconto', vn_money($orc['desconto'] ?? 0)];
    if (!$financeiros) $pdf->block('Valores', 'Valores ocultos neste orçamento.');
    for ($i=0; $i<count($financeiros); $i+=2) { $a=$financeiros[$i]; $b=$financeiros[$i+1] ?? ['','']; $pdf->two($a[0],$a[1],$b[0],$b[1]); }
    $pdf->block('4. Observações e condições comerciais', $orc['observacoes'] ?? 'Sem observações.');
    if ((int)($orc['mostrar_total_geral'] ?? 1) === 1) $pdf->total('Total do orçamento', vn_money($orc['valor_total'] ?? 0));

    // Seções da tela de pedido nova (só aparecem quando preenchidas e liberadas em "O que mostrar no documento").
    $flagDoc = function (string $k) use ($orc): bool { return (int)($orc[$k] ?? 1) === 1; };
    $secN = 5;
    $meiosDoc = trim((string)($orc['meios_pagamento'] ?? ''));
    $condDoc = trim((string)($orc['condicoes_pagamento'] ?? ''));
    if ($flagDoc('mostrar_pagamento') && ($meiosDoc !== '' || $condDoc !== '')) {
        $pdf->section(($secN++) . '. Pagamento');
        if ($meiosDoc !== '') $pdf->block('Meios de pagamento', $meiosDoc);
        if ($condDoc !== '') $pdf->block('Condições de pagamento', $condDoc);
    }
    $garDoc = trim((string)($orc['garantia'] ?? ''));
    if ($flagDoc('mostrar_garantia') && $garDoc !== '') { $pdf->section(($secN++) . '. Garantia'); $pdf->block('Garantia', $garDoc); }
    $relDoc = trim((string)($orc['relatorio'] ?? ''));
    if ($flagDoc('mostrar_relatorio') && $relDoc !== '') { $pdf->section(($secN++) . '. Relatório técnico'); $pdf->longText('Relatório', $relDoc); }
    $fotosDoc = $flagDoc('mostrar_fotos') ? ($orc['fotos'] ?? []) : [];
    if ($fotosDoc) {
        $pdf->section(($secN++) . '. Fotos');
        $par = [];
        foreach ($fotosDoc as $ft) {
            $img = vn_pdf_local_image_to_jpeg_bin((string)($ft['caminho'] ?? ''));
            if (!$img) continue;
            $par[] = $img;
            if (count($par) === 2) { $pdf->photoRow($par); $par = []; }
        }
        if ($par) $pdf->photoRow($par);
    }
    $claDoc = trim((string)($orc['clausulas'] ?? ''));
    if ($flagDoc('mostrar_clausulas') && $claDoc !== '') { $pdf->section(($secN++) . '. Cláusulas contratuais'); $pdf->longText('Cláusulas', $claDoc); }
    $pdf->sign();
    return $pdf->output();
}

/**
 * PDF do comprovante de retirada de materiais — mesmo visual do PDF de orçamento,
 * mas mostrando apenas materiais, unidades e quantidades (sem valores).
 */
function vn_pdf_retirada_v1(int $id): string {
    $r = vn_retirada_get_v1($id);
    if (!$r) throw new Exception('Comprovante de retirada não encontrado.');
    $pdf = new VNMobPdfV5();
    $pdf->header('');
    $pdf->section('1. Dados da retirada');
    $pdf->two('Cliente', $r['cliente_nome'] ?? '', 'Data da retirada', vn_date_br($r['data_retirada'] ?? ''));
    $pdf->two('Retirado por', $r['retirante_nome'] ?? '', 'Documento (RG/CPF)', ($r['retirante_documento'] ?? '') ?: 'Não informado');
    $pdf->section('2. Materiais retirados');
    $widths = [24, 64, 340, 95];
    $pdf->row(['#', 'Qtd', 'Material', 'Unidade'], $widths, true);
    $itens = $r['itens'] ?? [];
    if (!$itens) $itens = [['item_nome' => 'Nenhum material informado', 'unidade' => '-', 'quantidade' => 0]];
    foreach ($itens as $i => $it) {
        $pdf->row([
            (string)($i + 1),
            number_format((float)($it['quantidade'] ?? 0), 2, ',', '.'),
            (string)($it['item_nome'] ?? ''),
            (string)($it['unidade'] ?? 'un'),
        ], $widths, false);
    }
    $pdf->block('3. DECLARAÇÃO DE RETIRADA', 'Declaro que retirei da VN System os materiais listados acima, nas quantidades indicadas, na data de ' . vn_date_br($r['data_retirada'] ?? '') . ', e que conferi os itens no ato da retirada.');
    $pdf->signRetirada((string)($r['retirante_nome'] ?? ''), (string)($r['assinatura_retirante'] ?? ''));
    return $pdf->output();
}

/**
 * PDF das saídas de estoque de um dia, para o funcionário responsável assinar.
 * $responsavel vazio = todas as saídas do dia (linha de assinatura em branco).
 * $assinaturaPath: assinatura desenhada na hora (opcional).
 */
function vn_pdf_saidas_dia_v1(string $dia, string $responsavel = '', string $assinaturaPath = ''): string {
    $d = DateTime::createFromFormat('Y-m-d', $dia);
    if (!$d || $d->format('Y-m-d') !== $dia) throw new Exception('Data inválida.');

    $saidas = vn_estoque_saidas_do_dia_v1($dia);
    if ($responsavel !== '') {
        $alvo = vn_norm_key($responsavel);
        $saidas = array_values(array_filter($saidas, function ($s) use ($alvo) {
            return vn_norm_key((string)($s['responsavel'] ?? '')) === $alvo;
        }));
    }
    if (!$saidas) throw new Exception('Nenhuma saída de material em ' . vn_date_br($dia) . ($responsavel !== '' ? ' para "' . $responsavel . '"' : '') . '.');

    $pdf = new VNMobPdfV5();
    $pdf->header('');
    $pdf->section('1. Dados do relatório');
    $pdf->two('Data das saídas', vn_date_br($dia), 'Funcionário responsável', $responsavel !== '' ? $responsavel : 'Todas as saídas do dia');

    $pdf->section('2. Materiais que saíram do estoque');
    $widths = [24, 40, 235, 60, 164];
    $pdf->row(['#', 'Hora', 'Material', 'Qtd', 'Cliente / Motivo'], $widths, true);
    $totalQtd = 0.0;
    foreach ($saidas as $i => $s) {
        $qtd = (float)($s['quantidade'] ?? 0);
        $totalQtd += $qtd;
        $destino = vn_saida_cliente_texto($s);
        if ($destino === '' && !empty($s['pedido_cliente_nome'])) $destino = (string)$s['pedido_cliente_nome'];
        $pdf->row([
            (string)($i + 1),
            date('H:i', strtotime((string)($s['criado_em'] ?? 'now'))),
            (string)($s['item_nome'] ?? ''),
            number_format($qtd, 2, ',', '.') . ' ' . (string)($s['unidade'] ?? 'un'),
            $destino !== '' ? $destino : '—',
        ], $widths, false);
    }
    $pdf->two('Total de saídas', count($saidas) . ' item(ns)', 'Quantidade total', number_format($totalQtd, 2, ',', '.'));

    $pdf->block('3. DECLARAÇÃO DO FUNCIONÁRIO', 'Declaro que as saídas de materiais listadas acima, na data de ' . vn_date_br($dia) . ', foram realizadas por mim ou sob minha responsabilidade, nas quantidades indicadas, e que conferi os itens relacionados.');
    $pdf->signFuncionario($responsavel, $assinaturaPath);
    return $pdf->output();
}
?>
<?php
if (!function_exists('vn_orc_public_token')) {
    function vn_orc_public_token(int $id): string {
        return vn_hmac_token('orcamento', $id);
    }
}
