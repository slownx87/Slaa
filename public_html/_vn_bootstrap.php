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
if (!defined('VN_PUBLIC_ACCESS') || !VN_PUBLIC_ACCESS) {
    foreach ($vnBaseDirs as $dir) {
        if (is_file($dir . '/auth.php')) {
            require_once $dir . '/auth.php';
            break;
        }
    }
}
if (!$vnConfigLoaded) die('Erro: config.php não encontrado. Coloque a pasta mobile dentro da pasta site do sistema.');

$VN_CARGO = strtolower(trim((string)($_SESSION['cargo'] ?? '')));
$VN_PUBLIC_ACCESS = defined('VN_PUBLIC_ACCESS') && VN_PUBLIC_ACCESS;
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
    foreach (['assets/img/vn.png','vn.png','../vn.png','../../vn.png','../../../vn.png','logo.png','../logo.png','../../logo.png'] as $p) {
        if (is_file(__DIR__ . '/' . $p)) return $p;
    }
    return 'vn.png';
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

function vn_ensure_mobile_tables(): void {
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
}

function vn_clientes_form(): array {
    if (!vn_table_exists('clientes')) return [];
    $idCol = vn_first_col('clientes', ['id','cliente_id','id_cliente']);
    $nomeExpr = vn_text_expr('clientes', ['nome','nome_cliente','razao_cliente','razao_social','fantasia','fantasia_cliente','cliente'], "CONCAT('Cliente #', `$idCol`)");
    if (!$idCol) return [];
    $telExpr = vn_text_expr('clientes', ['telefone','telefone_cliente','celular','celular_cliente','fone','contato'], "''");
    $emailExpr = vn_text_expr('clientes', ['email','email_cliente','cliente_email'], "''");
    $endCols = [];
    foreach (['endereco','endereco_cliente','logradouro','rua','numero','numero_cliente','bairro','bairro_cliente','cidade','cidade_cliente','uf','uf_cliente','cep','cep_cliente'] as $c) {
        if (vn_column_exists('clientes', $c)) $endCols[] = "`$c`";
    }
    $endExpr = $endCols ? 'CONCAT_WS(\' - \', ' . implode(', ', $endCols) . ')' : "''";
    $sql = "SELECT `$idCol` AS id, $nomeExpr AS nome, $telExpr AS telefone, $emailExpr AS email, $endExpr AS endereco FROM clientes ORDER BY nome ASC LIMIT 1000";
    return vn_fetch_all($sql);
}

function vn_enderecos_form(): array {
    if (!vn_table_exists('enderecos_clientes')) return [];
    $idCol = vn_first_col('enderecos_clientes', ['id']);
    $clienteCol = vn_first_col('enderecos_clientes', ['cliente_id','id_cliente']);
    if (!$idCol || !$clienteCol) return [];
    $labelExpr = vn_text_expr('enderecos_clientes', ['nome_endereco','titulo','apelido'], "'Endereço de atendimento'");
    $partes = [];
    foreach (['endereco','endereco_cliente','logradouro','logradouro_cliente','rua','rua_cliente','numero','numero_cliente','complemento','complemento_cliente','bairro','bairro_cliente','cidade','cidade_cliente','uf','uf_cliente','cep','cep_cliente','referencia','referencia_cliente'] as $c) {
        if (vn_column_exists('enderecos_clientes', $c)) $partes[] = "`$c`";
    }
    $valorExpr = $partes ? 'CONCAT_WS(\' - \', ' . implode(', ', $partes) . ')' : "''";
    return vn_fetch_all("SELECT `$idCol` AS id, `$clienteCol` AS cliente_id, $labelExpr AS label, $valorExpr AS endereco FROM enderecos_clientes ORDER BY cliente_id ASC, id ASC LIMIT 2000");
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
    $validadeDb = $validade !== '' ? date('Y-m-d', strtotime($validade)) : null;
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
    return $orc;
}

function vn_excluir_orcamento_mobile_v4(int $id): void {
    vn_ensure_mobile_tables();
    if ($id <= 0) throw new Exception('ID do orçamento inválido.');
    vn_exec('DELETE FROM vn_mobile_orcamento_itens WHERE orcamento_id = ?', [$id]);
    vn_exec('DELETE FROM vn_mobile_orcamentos WHERE id = ? LIMIT 1', [$id]);
}

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
    $valor = vn_catalogo_preco_auto_v8($custo, $post);
    if ($nome === '') throw new Exception('Informe o nome do item para cadastrar.');
    if ($descricao === '') $descricao = $nome;
    $fotoUrl = trim((string)($post['catalogo_foto_url'] ?? ''));
    vn_exec('INSERT INTO vn_mobile_orcamento_catalogo (tipo, nome, descricao, foto_url, unidade, valor_unitario, custo_unitario, quantidade_estoque, status, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())', [$tipo, $nome, $descricao, $fotoUrl ?: null, $unidade ?: 'un', $valor, $custo, $quantidadeEstoque, 'ativo']);
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
    $valor = vn_catalogo_preco_auto_v8($custo, $post);
    $fotoUrl = trim((string)($post['catalogo_foto_url'] ?? ''));
    if ($nome === '') throw new Exception('Informe o nome do item para editar.');
    if ($descricao === '') $descricao = $nome;
    vn_exec('UPDATE vn_mobile_orcamento_catalogo SET tipo=?, nome=?, descricao=?, foto_url=?, unidade=?, valor_unitario=?, custo_unitario=?, quantidade_estoque=?, atualizado_em=NOW() WHERE id=? LIMIT 1', [$tipo, $nome, $descricao, $fotoUrl ?: null, $unidade ?: 'un', $valor, $custo, $quantidadeEstoque, $id]);
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
        $map = ['tipo'=>$tipo,'nome'=>$nome,'descricao'=>$descricao ?: $nome,'unidade'=>$unidade ?: 'un','valor_unitario'=>$valor,'custo_unitario'=>$custo,'quantidade_estoque'=>$quantidadeEstoque,'quantidade'=>$quantidadeEstoque,'qtd'=>$quantidadeEstoque,'estoque'=>$quantidadeEstoque,'foto_url'=>$foto ?: null];
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
        ];
    };

    $where = $somenteAtivos ? "WHERE status IS NULL OR status = '' OR LOWER(status) IN ('ativo','1','sim','s')" : '';
    foreach (vn_fetch_all("SELECT id, tipo, nome, descricao, foto_url, unidade, valor_unitario, custo_unitario, quantidade_estoque FROM vn_mobile_orcamento_catalogo $where ORDER BY tipo ASC, nome ASC LIMIT 1000") as $r) {
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
    $validade = vn_clean($post['validade'] ?? '');
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
    $itens = [];
    $subtotalItens = 0;
    $custoTotal = 0;
    foreach ($desc as $i => $d) {
        $d = vn_clean($d);
        if ($d === '') continue;
        $tipo = vn_clean($tipos[$i] ?? 'material');
        if (!in_array($tipo, ['equipamento','material','servico','mao_obra','outro'], true)) $tipo = 'material';
        $q = max(0.01, (float)str_replace(',', '.', (string)($qtds[$i] ?? 1)));
        $p = vn_money_db($precos[$i] ?? 0);
        $c = vn_money_db($custos[$i] ?? 0);
        $t = round($q * $p, 2);
        $ct = round($q * $c, 2);
        $subtotalItens += $t;
        $custoTotal += $ct;
        $itens[] = ['tipo'=>$tipo, 'descricao'=>$d, 'quantidade'=>$q, 'valor_unitario'=>$p, 'custo_unitario'=>$c, 'total'=>$t, 'custo_total'=>$ct];
    }
    $total = max(0, round($subtotalItens + $visitaValor + $maoValor - $desconto, 2));
    $lucro = round($total - $custoTotal, 2);

    if ($id > 0 && vn_fetch_one('SELECT id FROM vn_mobile_orcamentos WHERE id = ? LIMIT 1', [$id])) {
        vn_exec('UPDATE vn_mobile_orcamentos SET cliente_id=?, cliente_nome=?, cliente_telefone=?, cliente_email=?, endereco=?, status=?, validade=?, valor_total=?, valor_custo=?, desconto=?, lucro=?, visita_descricao=?, visita_valor=?, mao_obra_descricao=?, mao_obra_valor=?, observacoes=?, assinatura_tecnico=?, mostrar_itens=?, mostrar_valor_itens=?, mostrar_valor_unitario=?, mostrar_subtotal_itens=?, mostrar_visita=?, mostrar_mao_obra=?, mostrar_desconto=?, mostrar_total_geral=?, atualizado_em=NOW() WHERE id=? LIMIT 1', [
            $clienteId ?: null, $clienteNome, $telefone, $email, $endereco, $status, $validade ?: null, $total, $custoTotal, $desconto, $lucro, $visitaDesc, $visitaValor, $maoDesc, $maoValor, $obs, $assinaturaTecnico,
            $flags['mostrar_itens'], $flags['mostrar_valor_itens'], $flags['mostrar_valor_unitario'], $flags['mostrar_subtotal_itens'], $flags['mostrar_visita'], $flags['mostrar_mao_obra'], $flags['mostrar_desconto'], $flags['mostrar_total_geral'], $id
        ]);
        vn_exec('DELETE FROM vn_mobile_orcamento_itens WHERE orcamento_id = ?', [$id]);
        $orcId = $id;
    } else {
        $numero = 'ORC-MOB-' . date('Ymd-His');
        vn_exec('INSERT INTO vn_mobile_orcamentos (numero, cliente_id, cliente_nome, cliente_telefone, cliente_email, endereco, status, validade, valor_total, valor_custo, desconto, lucro, visita_descricao, visita_valor, mao_obra_descricao, mao_obra_valor, observacoes, assinatura_tecnico, mostrar_itens, mostrar_valor_itens, mostrar_valor_unitario, mostrar_subtotal_itens, mostrar_visita, mostrar_mao_obra, mostrar_desconto, mostrar_total_geral, criado_por, criado_por_nome, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())', [
            $numero, $clienteId ?: null, $clienteNome, $telefone, $email, $endereco, $status, $validade ?: null, $total, $custoTotal, $desconto, $lucro, $visitaDesc, $visitaValor, $maoDesc, $maoValor, $obs, $assinaturaTecnico,
            $flags['mostrar_itens'], $flags['mostrar_valor_itens'], $flags['mostrar_valor_unitario'], $flags['mostrar_subtotal_itens'], $flags['mostrar_visita'], $flags['mostrar_mao_obra'], $flags['mostrar_desconto'], $flags['mostrar_total_geral'], $VN_USUARIO_ID, $VN_USUARIO_NOME
        ]);
        $orcId = vn_last_id();
    }
    foreach ($itens as $it) {
        vn_exec('INSERT INTO vn_mobile_orcamento_itens (orcamento_id, tipo, descricao, quantidade, valor_unitario, custo_unitario, total, custo_total) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$orcId, $it['tipo'], $it['descricao'], $it['quantidade'], $it['valor_unitario'], $it['custo_unitario'], $it['total'], $it['custo_total']]);
    }
    return $orcId;
}

function vn_orc_tipo_label_v4(string $tipo): string {
    return ['equipamento'=>'Equipamento','material'=>'Material','produto_usado'=>'Produto usado','servico'=>'Serviço','mao_obra'=>'Mão de obra','ferramenta'=>'Ferramenta','ferramenta_outro'=>'Outros','outro'=>'Outro'][$tipo] ?? 'Material';
}

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
    private function enc($s): string { $s = preg_replace('/\s+/', ' ', trim((string)$s)); if (function_exists('iconv')) { $c = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $s); if ($c !== false) $s = $c; } return str_replace(['\\','(',')'], ['\\\\','\\(','\\)'], $s); }
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
    public function header($numero = ''): void { $this->rect(36,28,523,8,[29,78,216],[29,78,216]); $this->rect(36,44,523,98,null,[37,99,235]); $this->rect(48,60,64,56,[255,255,255],[191,219,254]); $logo=vn_pdf_logo_to_jpeg_bin(); if($logo)$this->imageJpeg('LogoVN',$logo,52,64,56,46); else $this->text('VN',64,94,22,true,[29,78,216]); $this->text('VN SYSTEM',124,66,19,true,[29,78,216]); $this->text('CNPJ: 58.181.863/0001-92',124,92,9); $this->text('Telefone: (11) 96919-0724',124,108,9); $this->text('Rua Ana Maria, 46 - São Bernardo do Campo/SP - CEP 09669-060',124,124,8); $this->y=160; }
    private function headerMini(): void { $this->rect(36,28,523,7,[29,78,216],[29,78,216]); $this->text('VN System',36,50,10,true,[29,78,216]); $this->y=70; }
    public function section($title): void { $this->need(32); $this->rect(36,$this->y,523,19,[37,99,235],[37,99,235]); $this->text(strtoupper($title),44,$this->y+13,9,true,[255,255,255]); $this->y+=30; }
    public function box($label,$value,$x,$w,$h=44): void { $this->rect($x,$this->y,$w,$h,[239,246,255],[191,219,254]); $this->text(strtoupper($label),$x+7,$this->y+12,7,true,[37,99,235]); $yy=$this->y+27; foreach(array_slice($this->wrap($value,(int)($w/5.3)),0,3) as $l){$this->text($l,$x+7,$yy,8.5);$yy+=10;} }
    public function two($l1,$v1,$l2,$v2,$h=46): void { $this->need($h+10); $this->box($l1,$v1,36,255,$h); $this->box($l2,$v2,304,255,$h); $this->y += $h+10; }
    public function block($label,$value): void { $lines=$this->wrap($value,86); $h=max(44,24+count($lines)*11); $this->need($h+8); $this->rect(36,$this->y,523,$h,null,[191,219,254]); $this->text(strtoupper($label),44,$this->y+12,7.5,true,[37,99,235]); $yy=$this->y+28; foreach($lines as $l){$this->text($l,44,$yy,9);$yy+=11;} $this->y += $h+8; }
    public function row(array $cols, array $widths, bool $head=false): void { $descIndex=2; $lines=$this->wrap((string)($cols[$descIndex]??''), $head ? 30 : (int)(($widths[$descIndex]??230)/4.5)); $h=$head?28:max(30,16+count($lines)*9); $this->need($h+5); $x=36; foreach($cols as $i=>$c){$w=$widths[$i]??60; $this->rect($x,$this->y,$w,$h,$head?[239,246,255]:null,[191,219,254]); $cellLines=$i===$descIndex?$lines:$this->wrap((string)$c,(int)($w/4.6)); $yy=$this->y+17; foreach(array_slice($cellLines,0,5) as $line){$this->text($line,$x+4,$yy,$head?7.5:8,$head,[30,58,138]);$yy+=9;} $x+=$w;} $this->y+=$h; }
    public function total($label,$value): void { $this->need(45); $this->rect(350,$this->y,209,34,[236,253,245],[134,239,172]); $this->text(strtoupper($label),358,$this->y+14,8,true,[22,101,52]); $this->text($value,455,$this->y+24,14,true,[22,101,52]); $this->y += 44; }
    public function sign(): void { $this->need(80); $this->y += 30; $this->line(58,$this->y,260,$this->y,[29,78,216]); $this->line(335,$this->y,535,$this->y,[29,78,216]); $this->text('Cliente / responsável',100,$this->y+14,9); $this->text('VN System',410,$this->y+14,9); }
    public function equipmentPhoto(string $title, array $img): void { if (empty($img['data'])) return; $this->need(118); $this->rect(36,$this->y,523,108,[249,250,251],[203,213,225]); $this->text($title,44,$this->y+14,8.5,true,[37,99,235]); $name='EQ'.(count($this->images)+1); $this->imageJpeg($name,$img,44,$this->y+22,160,80); $this->y += 116; }
    public function output(): string { $this->footer(); $this->pages[]=['content'=>$this->content,'images'=>$this->pageImages]; $objects=[]; $add=function($body)use(&$objects){$objects[]=$body;return count($objects);}; $font=$add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>'); $bold=$add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>'); $pagesId=count($objects)+1; $objects[]=''; $imgIds=[]; foreach($this->images as $name=>$img){$imgIds[$name]=$add('<< /Type /XObject /Subtype /Image /Width '.(int)$img['w'].' /Height '.(int)$img['h'].' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($img['data'])." >>\nstream\n".$img['data']."\nendstream");} $pageRefs=[]; foreach($this->pages as $page){$content=$page['content']; $cid=$add('<< /Length '.strlen($content)." >>\nstream\n$content\nendstream"); $parts=[]; foreach(array_keys($page['images']??[]) as $name){if(isset($imgIds[$name]))$parts[]="/$name ".$imgIds[$name].' 0 R';} $xRes=$parts?('/XObject << '.implode(' ',$parts).' >>'):''; $res="<< /Font << /F1 {$font} 0 R /F2 {$bold} 0 R >> {$xRes} >>"; $pid=$add("<< /Type /Page /Parent {$pagesId} 0 R /MediaBox [0 0 {$this->w} {$this->h}] /Resources {$res} /Contents {$cid} 0 R >>"); $pageRefs[]="$pid 0 R";} $objects[$pagesId-1]='<< /Type /Pages /Kids ['.implode(' ',$pageRefs).'] /Count '.count($pageRefs).' >>'; $catalog=$add("<< /Type /Catalog /Pages {$pagesId} 0 R >>"); $pdf="%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $offs=[0]; foreach($objects as $i=>$body){$id=$i+1;$offs[$id]=strlen($pdf);$pdf.="$id 0 obj\n$body\nendobj\n";} $xref=strlen($pdf); $pdf.='xref'."\n0 ".(count($objects)+1)."\n0000000000 65535 f \n"; for($i=1;$i<=count($objects);$i++)$pdf.=sprintf("%010d 00000 n \n",$offs[$i]); $pdf.="trailer\n<< /Size ".(count($objects)+1)." /Root {$catalog} 0 R >>\nstartxref\n{$xref}\n%%EOF"; return $pdf; }
}

function vn_pdf_orcamento_mobile_v4(int $id): string {
    $orc = vn_orcamento_mobile_get_v4($id);
    if (!$orc) throw new Exception('Orçamento não encontrado.');
    if (vn_clean($orc['endereco'] ?? '') === '') $orc['endereco'] = vn_buscar_endereco_cliente_por_id(0, (int)($orc['cliente_id'] ?? 0));
    $pdf = new VNMobPdfV5();
    // Cabeçalho somente com dados da empresa, sem identificação/código/número do orçamento.
    $pdf->header('');
    $pdf->section('1. Dados do cliente');
    $pdf->two('Cliente', $orc['cliente_nome'] ?? '', 'Contato', $orc['cliente_telefone'] ?? '');
    $pdf->two('E-mail', $orc['cliente_email'] ?? '', 'Validade', vn_date_br($orc['validade'] ?? ''));
    $pdf->block('Endereço do atendimento', $orc['endereco'] ?? 'Não informado');
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
    $pdf->sign();
    return $pdf->output();
}
?>
<?php
if (!function_exists('vn_orc_public_token')) {
    function vn_orc_public_token(int $id): string {
        return vn_hmac_token('orcamento', $id);
    }
}
