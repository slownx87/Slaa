<?php
require_once __DIR__ . '/_vn_security.php'; // sessão/cookie/permissões antes do session_start()
/*
   OS Concluídas — lista as ordens de serviço finalizadas e gera o PDF de cada
   uma (normal ou preventiva). O único dado novo pedido é o valor: descrição,
   equipamento e garantia já existem na OS desde a abertura, então o PDF puxa
   direto de ordens_servico em vez de perguntar de novo.
*/
if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set('America/Sao_Paulo');

if (!isset($_SESSION['cargo']) || strtolower(trim((string)$_SESSION['cargo'])) !== 'admin') {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/config.php';
if (is_file(__DIR__ . '/auth.php')) require_once __DIR__ . '/auth.php';

$pagina_atual = basename($_SERVER['PHP_SELF']);

function osc_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function osc_money($v): string { return 'R$ ' . number_format((float)$v, 2, ',', '.'); }

/* Converte "2.500,00" ou "2500.00" em float. */
function osc_money_parse($s): float {
    $s = preg_replace('/[^0-9,.-]/', '', (string)$s);
    if ($s === '' || $s === '-') return 0.0;
    if (strpos($s, ',') !== false) { $s = str_replace('.', '', $s); $s = str_replace(',', '.', $s); }
    return (float)$s;
}

/* Token idêntico ao os-pdf-publico.php: ambos usam vn_os_token() de _vn_security.php. */
function osc_token(int $id): string {
    return vn_os_token($id);
}

$pdo = db();

function osc_col_exists(PDO $pdo, string $t, string $c): bool {
    $st = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
    $st->execute([$t, $c]);
    return (bool)$st->fetchColumn();
}
function osc_ensure_col(PDO $pdo, string $t, string $c, string $def): void {
    try { if (!osc_col_exists($pdo, $t, $c)) $pdo->exec("ALTER TABLE `$t` ADD COLUMN `$c` $def"); }
    catch (Throwable $e) { /* sem permissão de ALTER: a tela ainda lista, só não salva */ }
}

/*
   Campos novos: valor, texto do serviço, modelo do PDF e as opções de
   exibição (JSON com preco/total/detalhes/fotos). Nada de quantidade,
   unidade, pagamento ou garantia — isso já existe na OS ou é sempre igual.
*/
$pdfCols = [
    'pdf_valor'          => 'DECIMAL(12,2) NULL',
    'pdf_texto_servico'  => 'VARCHAR(255) NULL',
    'pdf_modelo'         => 'VARCHAR(48) NULL',
    'pdf_opcoes'         => 'VARCHAR(255) NULL',
];
$temOsVisitas = false;
try {
    $temOsVisitas = (bool)$pdo->query("SHOW TABLES LIKE 'os_visitas'")->fetchColumn();
    if ($temOsVisitas) foreach ($pdfCols as $c => $def) osc_ensure_col($pdo, 'os_visitas', $c, $def);
    if ($temOsVisitas) osc_ensure_col($pdo, 'os_visitas', 'linha_servico', "VARCHAR(20) NOT NULL DEFAULT 'cftv'");   // OS de CFTV ou de serralheria
} catch (Throwable $e) { $temOsVisitas = false; }

/*
   Memória entre OS diferentes: o texto do serviço digitado numa OS vira a
   sugestão padrão da próxima, pra não digitar do zero toda vez. Tabela
   própria (chave/valor) porque é algo do sistema, não de uma OS específica.
*/
function osc_ensure_prefs_table(PDO $pdo): bool {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS os_pdf_prefs (chave VARCHAR(64) NOT NULL PRIMARY KEY, valor TEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return true;
    } catch (Throwable $e) { return false; }
}
function osc_pref_get(PDO $pdo, string $chave, string $default = ''): string {
    try {
        $st = $pdo->prepare('SELECT valor FROM os_pdf_prefs WHERE chave = ? LIMIT 1');
        $st->execute([$chave]);
        $v = $st->fetchColumn();
        return ($v !== false && $v !== null) ? (string)$v : $default;
    } catch (Throwable $e) { return $default; }
}
function osc_pref_set(PDO $pdo, string $chave, string $valor): void {
    try {
        $st = $pdo->prepare('INSERT INTO os_pdf_prefs (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)');
        $st->execute([$chave, $valor]);
    } catch (Throwable $e) { /* sem permissão de CREATE/INSERT: só perde a memória global, cada OS ainda guarda a própria */ }
}
$temPrefs = osc_ensure_prefs_table($pdo);
$textoServicoPadrao = $temPrefs ? osc_pref_get($pdo, 'texto_servico_ultimo', '') : '';

/* Modelos de PDF (pdf_templates/*.json). */
function osc_modelos(): array {
    $out = [];
    foreach ((array)glob(__DIR__ . '/pdf_templates/*.json') as $f) {
        $id = basename((string)$f, '.json');
        if (!preg_match('/^[a-z0-9_-]{1,48}$/i', $id)) continue;
        $j = json_decode((string)@file_get_contents($f), true);
        $nome = (is_array($j) && trim((string)($j['nome'] ?? '')) !== '') ? (string)$j['nome'] : $id;
        $out[] = ['id' => $id, 'nome' => $nome];
    }
    usort($out, fn($a, $b) => strcasecmp($a['nome'], $b['nome']));
    return $out;
}
$modelos = osc_modelos();

/* POST: salva valor/modelo/opções e redireciona pro PDF já gerado. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') !== '' && $temOsVisitas) {
    $acao = (string)$_POST['acao'];
    $vid  = (int)($_POST['visita_id'] ?? 0);

    if ($vid > 0 && in_array($acao, ['gerar_normal', 'gerar_preventiva'], true)) {
        $valor         = osc_money_parse($_POST['pdf_valor'] ?? '');
        $textoServico  = trim((string)($_POST['pdf_texto_servico'] ?? ''));
        $modelo = preg_replace('/[^a-z0-9_-]/i', '', (string)($_POST['pdf_modelo'] ?? ''));
        $opcoes = json_encode([
            'preco'    => isset($_POST['op_preco'])    ? 1 : 0,
            'total'    => isset($_POST['op_total'])    ? 1 : 0,
            'detalhes' => isset($_POST['op_detalhes']) ? 1 : 0,
            'fotos'    => isset($_POST['op_fotos'])    ? 1 : 0,
        ], JSON_UNESCAPED_UNICODE);

        $sets = []; $vals = [];
        if (osc_col_exists($pdo, 'os_visitas', 'pdf_valor'))         { $sets[] = 'pdf_valor = ?';         $vals[] = $valor; }
        if (osc_col_exists($pdo, 'os_visitas', 'pdf_texto_servico')) { $sets[] = 'pdf_texto_servico = ?';  $vals[] = $textoServico; }
        if (osc_col_exists($pdo, 'os_visitas', 'pdf_modelo'))        { $sets[] = 'pdf_modelo = ?';         $vals[] = $modelo; }
        if (osc_col_exists($pdo, 'os_visitas', 'pdf_opcoes'))        { $sets[] = 'pdf_opcoes = ?';         $vals[] = $opcoes; }
        if ($sets) {
            $vals[] = $vid;
            try { $pdo->prepare('UPDATE os_visitas SET ' . implode(', ', $sets) . ' WHERE id = ? LIMIT 1')->execute($vals); }
            catch (Throwable $e) { /* ignora e segue pro PDF mesmo assim */ }
        }

        // Vira a sugestão padrão da próxima OS, pra não digitar do zero de novo.
        if ($textoServico !== '' && $temPrefs) osc_pref_set($pdo, 'texto_servico_ultimo', $textoServico);

        $url = 'os-pdf-publico.php?t=' . rawurlencode(osc_token($vid));
        if ($modelo !== '') $url .= '&modelo=' . rawurlencode($modelo);
        if ($acao === 'gerar_preventiva') $url .= '&tipo=preventiva';
        header('Location: ' . $url);
        exit;
    }
}

/* Filtros. */
$busca  = trim((string)($_GET['q'] ?? ''));
$linha  = in_array((string)($_GET['linha'] ?? ''), ['cftv', 'serralheria'], true) ? (string)$_GET['linha'] : '';   // '' = todas as áreas
$status = (string)($_GET['status'] ?? 'concluida');
if (!in_array($status, ['concluida', 'nao_realizada', 'todas'], true)) $status = 'concluida';

$ordens = []; $erroLista = '';
try {
    if (!$temOsVisitas) throw new Exception('A tabela os_visitas não foi encontrada.');

    $where = []; $params = [];
    if ($status === 'concluida') {
        $where[] = "(status_visita LIKE 'conclu%' OR status_visita = 'finalizada')";
    } elseif ($status === 'nao_realizada') {
        $where[] = "(status_visita LIKE 'nao%realiz%' OR status_visita = 'cancelada')";
    } else {
        $where[] = "(status_visita LIKE 'conclu%' OR status_visita = 'finalizada' OR status_visita LIKE 'nao%realiz%' OR status_visita = 'cancelada' OR checkout_em IS NOT NULL)";
    }
    if ($linha !== '' && osc_col_exists($pdo, 'os_visitas', 'linha_servico')) {
        $where[] = "COALESCE(linha_servico, 'cftv') = ?";
        $params[] = $linha;
    }
    if ($busca !== '') {
        $where[] = "(cliente_nome LIKE ? OR numero_os LIKE ? OR endereco LIKE ? OR funcionario_nome LIKE ?)";
        $c = '%' . $busca . '%';
        array_push($params, $c, $c, $c, $c);
    }
    $sql = "SELECT * FROM os_visitas WHERE " . implode(' AND ', $where) .
           " ORDER BY COALESCE(checkout_em, data_agendada, criado_em) DESC, id DESC LIMIT 500";
    $st = $pdo->prepare($sql); $st->execute($params); $ordens = $st->fetchAll();
} catch (Throwable $e) { $erroLista = $e->getMessage(); }

function osc_eh_preventiva(array $os): bool {
    $txt = mb_strtolower((string)($os['tipo_servico'] ?? '') . ' ' . (string)($os['descricao'] ?? ''));
    return strpos($txt, 'prevent') !== false;
}
function osc_data_br($v): string {
    $v = trim((string)$v);
    if ($v === '' || strpos($v, '0000') === 0) return '—';
    try { $d = new DateTime($v); return $d->format('d/m/Y'); } catch (Throwable $e) { return $v; }
}
?>
<?php
require_once __DIR__ . '/_vn_ui.php';
ob_start();
?>
<style>
        :root {
            --bg: #050810;
            --card: #0f172a;
            --card2: #111c31;
            --line: rgba(59, 130, 246, .25);
            --line2: rgba(255,255,255,.08);
            --text: #ffffff;
            --muted: #94a3b8;
            --blue: #3b82f6;
            --blue2: #2563eb;
            --green: #4ade80;
            --yellow: #facc15;
            --red: #f87171;
            --sidebar: #07111f;
            --sidebar-text: #cbd5e1;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        /*
           overflow-x:hidden no body (com o html sem overflow) faz o body virar
           um segundo container de rolagem: o overflow-y computa para auto e o
           celular fica com dois scrollers disputando o toque — dava para descer
           mas não para voltar. No html clipa a horizontal com um scroller só.
        */
        html { overflow-x: hidden; }

        body { font-family: "Inter", Arial, sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; }
        a { text-decoration: none; }

        .topbar {
            height: 68px; background: rgba(10, 19, 34, .98); border-bottom: 1px solid var(--line);
            display: flex; align-items: center; justify-content: space-between; padding: 0 22px;
            position: sticky; top: 0; z-index: 20;
        }
        .brand { display: flex; align-items: center; gap: 12px; font-weight: 800; }
        .brand img { height: 40px; max-width: 140px; object-fit: contain; }
        .brand span { color: var(--primary-fg); letter-spacing: .2px; }
        .menu-btn { display: none; border: 1px solid var(--line); background: rgba(59,130,246,.14); color: var(--text); border-radius: 10px; padding: 10px 13px; font-weight: 800; }

        .layout { display: flex; min-height: calc(100vh - 68px); }

        .sidebar {
            width: 265px; background: linear-gradient(180deg, #07111f 0%, #050810 100%); color: var(--sidebar-text);
            padding: 16px 14px; flex-shrink: 0; border-right: 1px solid var(--line);
        }
        .side-btn {
            min-height: 48px; display: flex; align-items: center; gap: 11px; color: var(--sidebar-text);
            padding: 12px 13px; border-radius: 12px; margin-bottom: 8px; font-size: .95rem; font-weight: 700;
            border: 1px solid rgba(255,255,255,.06); background: rgba(255,255,255,.025); transition: .18s ease;
        }
        .side-btn i { width: 22px; text-align: center; color: var(--primary-fg); font-size: 1rem; flex-shrink: 0; }
        .side-btn:hover { background: rgba(59,130,246,.14); border-color: rgba(59,130,246,.45); color: #fff; transform: translateX(2px); }
        .side-btn.active { background: var(--blue); border-color: var(--blue); color: #020617; box-shadow: 0 8px 22px rgba(59,130,246,.22); }
        .side-btn.active i { color: #020617; }
        .side-btn.logout { margin-top: 18px; color: #fecaca !important; border-top: 1px solid rgba(255,255,255,.10); padding-top: 14px !important; background: rgba(248,113,113,.07); border-color: rgba(248,113,113,.20); }
        .side-btn.logout i { color: var(--red); }

        .content { flex: 1; padding: 24px; max-width: 1200px; margin: 0 auto; width: 100%; }

        .page-title, .card { background: var(--card); border: 1px solid var(--line); border-radius: 16px; margin-bottom: 18px; }
        .page-title { padding: 20px; }
        .page-title h1 { color: var(--primary-fg); font-size: 1.55rem; margin-bottom: 8px; }
        .page-title p { color: var(--muted); line-height: 1.5; }
        .card { padding: 24px; }

        label { display: block; margin-bottom: 8px; color: var(--muted); font-size: .82rem; font-weight: 900; text-transform: uppercase; }
        input, textarea, select { width: 100%; padding: 14px; background: #050810; border: 1px solid var(--line); border-radius: 12px; color: white; outline: none; font-family: inherit; font-size: .95rem; }

        .btn {
            min-height: 50px; padding: 14px 18px; background: var(--blue); border: none; border-radius: 12px;
            font-weight: 900; color: #020617; cursor: pointer; width: 100%; font-size: 1rem; text-transform: uppercase;
        }
        .btn:hover { background: #60a5fa; }
        .btn-soft { background: rgba(59,130,246,.16); color: #bfdbfe; }
        .btn-soft:hover { background: rgba(59,130,246,.26); }

        .alert { padding: 15px; border-radius: 12px; margin-bottom: 18px; font-weight: 700; text-align: center; }
        .alert-success { background: rgba(74, 222, 128, 0.10); color: var(--green); border: 1px solid rgba(74, 222, 128, .35); }
        .alert-error { background: rgba(248, 113, 113, 0.10); color: var(--red); border: 1px solid rgba(248, 113, 113, .35); }

        @media (max-width: 780px) {
            .topbar { height: 64px; padding: 0 14px; }
            .brand img { height: 34px; max-width: 110px; }
            .brand span { font-size: .95rem; }
            .menu-btn { display: block; }
            .layout { display: block; }
            .sidebar { display: none; width: 100%; padding: 12px; border-right: 0; border-bottom: 1px solid var(--line); background: #07111f; }
            .sidebar.open { display: block; }
            .side-btn { min-height: 50px; margin-bottom: 7px; font-size: 1rem; padding: 13px 12px; }
            .side-btn:hover { transform: none; }
            .content { padding: 14px; }
            .page-title, .card { padding: 14px; }
            .page-title h1 { font-size: 1.22rem; }
            .btn { min-height: 52px; }
        }
        @media (max-width: 430px) { .brand span { display: none; } }

        /* ===== Componentes desta tela ===== */
        .osc-toolbar { display:flex; flex-wrap:wrap; gap:12px; align-items:center; margin-bottom:16px; }
        .osc-search { display:flex; gap:8px; flex:1 1 280px; }
        .osc-search input { flex:1; min-height:48px; }
        .osc-tabs { display:flex; gap:8px; flex-wrap:wrap; }
        .osc-tab {
            display:inline-flex; align-items:center; min-height:44px; padding:0 16px; border-radius:999px;
            font-weight:800; font-size:.86rem; color:var(--text); border:1px solid var(--line2); background:var(--card2);
        }
        .osc-tab.on { background:var(--primary); border-color:transparent; color:#fff; }

        .osc-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(320px,1fr)); gap:14px; }
        .osc-card { background:var(--card2); border:1px solid var(--line2); border-radius:16px; padding:16px; display:flex; flex-direction:column; gap:12px; }
        .osc-card.prev { border-left:4px solid #10b981; }
        .osc-card-top { display:flex; justify-content:space-between; gap:10px; align-items:flex-start; }
        .osc-num { font-weight:900; font-size:1.02rem; }
        .osc-cli { font-weight:800; margin-top:3px; }
        .osc-sub { color:var(--muted); font-size:.85rem; line-height:1.4; margin-top:4px; overflow-wrap:anywhere; }
        .osc-chips { display:flex; flex-wrap:wrap; gap:6px; }
        .osc-chip { display:inline-flex; align-items:center; gap:5px; padding:4px 10px; border-radius:999px; font-size:.72rem; font-weight:800; background:rgba(148,163,184,.16); color:var(--muted); }
        .osc-chip.val { background:var(--ok-bg); color:var(--ok-text); }
        .osc-chip.prev { background:var(--ok-bg); color:var(--ok-text); }
        .osc-chip.serr { background:rgba(245,158,11,.2); color:#fbbf24; }
        .osc-st { display:inline-flex; padding:5px 10px; border-radius:999px; font-size:.72rem; font-weight:900; white-space:nowrap; background:var(--primary-softer); color:var(--primary-ink); }
        .osc-st.done { background:var(--ok-bg); color:var(--ok-text); }
        .osc-st.cancel { background:var(--danger-bg); color:var(--danger-text); }
        .osc-card .btn { width:100%; margin-top:auto; }

        .osc-empty { text-align:center; color:var(--muted); padding:30px 12px; line-height:1.5; }
        .osc-count { color:var(--muted); font-size:.85rem; font-weight:700; margin-bottom:12px; }

        /*
           Modal: centralizado por top/left/transform (não por flex do pai), e
           largura sempre calculada contra a viewport (calc(100vw - ...)) — assim
           ele nunca fica maior que a tela nem depende do layout ao redor.
           Coluna única nos campos, sem grid: elimina o caso clássico de <select>
           forçando a coluna a ficar mais larga que o card e "vazando" pra fora.
        */
        .osc-modal { position:fixed; inset:0; z-index:2000; display:none; background:rgba(2,6,16,.72); }
        .osc-modal.open { display:block; }
        .osc-modal-card {
            position:fixed; top:50%; left:50%; transform:translate(-50%,-50%);
            width:min(520px, calc(100vw - 32px));
            max-height:calc(100vh - 32px);
            overflow-y:auto; -webkit-overflow-scrolling:touch;
            background:var(--card); color:var(--text);
            border:1px solid var(--line); border-radius:20px; padding:22px;
        }
        .osc-modal-head { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:6px; }
        .osc-modal-head h2 { color:var(--primary-fg); font-size:1.25rem; overflow-wrap:anywhere; }
        .osc-modal-head .x { flex-shrink:0; width:40px; height:40px; border-radius:50%; border:0; background:var(--card2); color:var(--text); font-size:19px; cursor:pointer; }
        .osc-modal p.hint { color:var(--muted); font-size:.85rem; line-height:1.45; margin-bottom:16px; }

        .osc-field { margin-bottom:14px; }
        .osc-field label { margin-bottom:6px; }
        .osc-modelo-vazio {
            padding:13px 14px; border-radius:12px; background:rgba(59,130,246,.08); border:1px dashed var(--line);
            color:var(--muted); font-size:.85rem; line-height:1.5;
        }
        .osc-modelo-vazio a { color:#93c5fd; font-weight:800; text-decoration:underline; }

        .osc-toggles { display:grid; gap:2px; margin-bottom:6px; }
        .osc-toggle {
            display:flex; align-items:center; justify-content:space-between; gap:12px;
            min-height:50px; padding:6px 2px; border-bottom:1px solid var(--line2); font-size:.92rem; font-weight:650;
        }
        .osc-toggle:last-child { border-bottom:0; }
        .osc-switch { position:relative; width:44px; height:26px; flex-shrink:0; }
        .osc-switch input { position:absolute; inset:0; opacity:0; margin:0; cursor:pointer; width:100%; height:100%; }
        .osc-switch i { position:absolute; inset:0; background:var(--line2); border-radius:999px; transition:.15s; pointer-events:none; }
        .osc-switch i::before { content:""; position:absolute; width:20px; height:20px; left:3px; top:3px; background:#fff; border-radius:50%; transition:.15s; }
        .osc-switch input:checked + i { background:var(--blue); }
        .osc-switch input:checked + i::before { transform:translateX(18px); }

        .osc-modal-actions { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-top:18px; }
        .btn.btn-prev { background:rgba(16,185,129,.16); border:1px solid rgba(16,185,129,.5); color:#6ee7b7; }

        @media (max-width:480px) {
            .osc-modal-actions { grid-template-columns:1fr; }
        }
    </style>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
<?php
$vnHeadExtra = ob_get_clean();
vn_ui_shell_start('OS Concluídas', 'os-concluidas', ['head_extra' => $vnHeadExtra, 'legacy' => 'admin']);
?>
<div class="content">
            <div class="page-title">
                <h1>OS Concluídas</h1>
                <p>Gere o PDF de qualquer OS finalizada — normal ou preventiva. Descrição, equipamento e garantia já vêm da própria OS; só o valor e o que aparece no PDF são perguntados na hora de gerar.</p>
            </div>

            <div class="card">
                <div class="osc-toolbar">
                    <form class="osc-search" method="get" action="os-concluidas.php">
                        <input type="hidden" name="status" value="<?php echo osc_h($status); ?>">
                        <?php if ($linha !== ''): ?><input type="hidden" name="linha" value="<?php echo osc_h($linha); ?>"><?php endif; ?>
                        <input type="search" name="q" value="<?php echo osc_h($busca); ?>" placeholder="Buscar por cliente, número, endereço ou técnico...">
                        <button class="btn" type="submit" style="width:auto;min-height:48px;padding:0 20px">Buscar</button>
                    </form>
                    <div class="osc-tabs">
                        <?php
                            $abas = ['concluida' => 'Concluídas', 'nao_realizada' => 'Não realizadas', 'todas' => 'Todas'];
                            foreach ($abas as $chave => $rotulo):
                                $qs = http_build_query(array_filter(['status' => $chave, 'q' => $busca, 'linha' => $linha]));
                        ?>
                            <a class="osc-tab <?php echo $status === $chave ? 'on' : ''; ?>" href="os-concluidas.php?<?php echo osc_h($qs); ?>"><?php echo osc_h($rotulo); ?></a>
                        <?php endforeach; ?>
                    </div>
                    <div class="osc-tabs" style="margin-top:8px" aria-label="Tipo da OS">
                        <?php foreach (['' => 'Todas as áreas', 'cftv' => 'CFTV', 'serralheria' => 'Serralheria'] as $kl => $rl):
                            $qsl = http_build_query(array_filter(['status' => $status, 'q' => $busca, 'linha' => $kl])); ?>
                            <a class="osc-tab <?php echo $linha === $kl ? 'on' : ''; ?>" href="os-concluidas.php?<?php echo osc_h($qsl); ?>"><?php echo osc_h($rl); ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if ($erroLista !== ''): ?>
                    <div class="osc-empty"><?php echo osc_h($erroLista); ?></div>
                <?php elseif (!$ordens): ?>
                    <div class="osc-empty">Nenhuma OS encontrada com esse filtro.</div>
                <?php else: ?>
                    <div class="osc-count"><?php echo count($ordens); ?> OS encontrada<?php echo count($ordens) === 1 ? '' : 's'; ?></div>
                    <div class="osc-grid">
                        <?php foreach ($ordens as $os):
                            $id = (int)($os['id'] ?? 0);
                            if ($id <= 0) continue;
                            $prev = osc_eh_preventiva($os);
                            $statusTxt = (string)($os['status_visita'] ?? '');
                            $stKind = (strpos(mb_strtolower($statusTxt), 'nao') !== false || $statusTxt === 'cancelada') ? 'cancel'
                                    : ((strpos(mb_strtolower($statusTxt), 'conclu') !== false || $statusTxt === 'finalizada') ? 'done' : '');
                            $valorAtual = (float)($os['pdf_valor'] ?? 0);
                            $dataRef = $os['checkout_em'] ?? ($os['data_agendada'] ?? ($os['criado_em'] ?? ''));

                            $opcoesSalvas = json_decode((string)($os['pdf_opcoes'] ?? ''), true);
                            if (!is_array($opcoesSalvas)) $opcoesSalvas = [];

                            $textoServicoOS = trim((string)($os['pdf_texto_servico'] ?? '')) ?: $textoServicoPadrao;

                            $osData = [
                                'id' => $id,
                                'numero' => (string)($os['numero_os'] ?? ('OS #' . $id)),
                                'valor' => $valorAtual > 0 ? number_format($valorAtual, 2, ',', '.') : '',
                                'texto_servico' => $textoServicoOS,
                                'modelo' => (string)($os['pdf_modelo'] ?? ''),
                                'preco'    => !array_key_exists('preco', $opcoesSalvas)    || !empty($opcoesSalvas['preco']),
                                'total'    => !array_key_exists('total', $opcoesSalvas)    || !empty($opcoesSalvas['total']),
                                'detalhes' => !array_key_exists('detalhes', $opcoesSalvas) || !empty($opcoesSalvas['detalhes']),
                                'fotos'    => !array_key_exists('fotos', $opcoesSalvas)    || !empty($opcoesSalvas['fotos']),
                            ];
                        ?>
                            <article class="osc-card <?php echo $prev ? 'prev' : ''; ?>">
                                <div class="osc-card-top">
                                    <div>
                                        <div class="osc-num"><?php echo osc_h($os['numero_os'] ?: ('OS #' . $id)); ?></div>
                                        <div class="osc-cli"><?php echo osc_h($os['cliente_nome'] ?: 'Cliente não informado'); ?></div>
                                        <?php if (trim((string)($os['endereco'] ?? '')) !== ''): ?>
                                            <div class="osc-sub"><?php echo osc_h($os['endereco']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <span class="osc-st <?php echo $stKind; ?>"><?php echo osc_h($statusTxt ?: 'OS'); ?></span>
                                </div>

                                <div class="osc-chips">
                                    <span class="osc-chip"><?php echo osc_h($os['funcionario_nome'] ?: 'Sem técnico'); ?></span>
                                    <span class="osc-chip"><?php echo osc_h(osc_data_br($dataRef)); ?></span>
                                    <?php if ($prev): ?><span class="osc-chip prev">Preventiva</span><?php endif; ?>
                                    <?php if ((string)($os['linha_servico'] ?? '') === 'serralheria'): ?><span class="osc-chip serr">Serralheria</span><?php endif; ?>
                                    <?php if ($valorAtual > 0): ?><span class="osc-chip val"><?php echo osc_h(osc_money($valorAtual)); ?></span><?php endif; ?>
                                </div>

                                <button type="button" class="btn" data-os='<?php echo osc_h(json_encode($osData, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT)); ?>' onclick="oscAbrir(this)">
                                    Gerar PDF
                                </button>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    <!-- Modal: valor + o que aparece no PDF -->
    <div class="osc-modal" id="pdfModal">
        <div class="osc-modal-card">
            <div class="osc-modal-head">
                <h2 id="pdfModalTitulo">Gerar PDF</h2>
                <button type="button" class="x" onclick="oscFechar()">✕</button>
            </div>
            <p class="hint">Equipamento e garantia já vêm da OS. O valor só aparece na versão <strong>preventiva</strong> — a OS normal continua o relatório técnico puro.</p>

            <form method="post" action="os-concluidas.php">
                <input type="hidden" name="visita_id" id="pdfVisitaId" value="">
                <input type="hidden" name="acao" id="pdfAcao" value="gerar_normal">

                <div class="osc-field">
                    <label>Texto do serviço</label>
                    <input type="text" name="pdf_texto_servico" id="pdfTextoServico" placeholder="Ex: Manutenção preventiva e corretiva 24 horas">
                </div>

                <div class="osc-field">
                    <label>Valor do serviço (R$)</label>
                    <input type="text" name="pdf_valor" id="pdfValor" inputmode="decimal" placeholder="0,00">
                </div>

                <div class="osc-field">
                    <label>Modelo do PDF</label>
                    <?php if (!$modelos): ?>
                        <div class="osc-modelo-vazio">
                            Nenhum modelo cadastrado ainda — o PDF sai no padrão do sistema.
                            <a href="os-modelos-pdf.php" target="_blank" rel="noopener">Criar um modelo</a>
                        </div>
                    <?php else: ?>
                        <select name="pdf_modelo" id="pdfModelo">
                            <option value="">Padrão do sistema</option>
                            <?php foreach ($modelos as $m): ?>
                                <option value="<?php echo osc_h($m['id']); ?>"><?php echo osc_h($m['nome']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>

                <div class="osc-toggles">
                    <label class="osc-toggle">Mostrar preço do serviço
                        <span class="osc-switch"><input type="checkbox" name="op_preco" id="opPreco" checked><i></i></span>
                    </label>
                    <label class="osc-toggle">Mostrar total
                        <span class="osc-switch"><input type="checkbox" name="op_total" id="opTotal" checked><i></i></span>
                    </label>
                    <label class="osc-toggle">Mostrar detalhes do serviço
                        <span class="osc-switch"><input type="checkbox" name="op_detalhes" id="opDetalhes" checked><i></i></span>
                    </label>
                    <label class="osc-toggle">Mostrar fotos do atendimento
                        <span class="osc-switch"><input type="checkbox" name="op_fotos" id="opFotos" checked><i></i></span>
                    </label>
                </div>

                <div class="osc-modal-actions">
                    <button type="submit" class="btn" onclick="document.getElementById('pdfAcao').value='gerar_normal'">Gerar OS normal</button>
                    <button type="submit" class="btn btn-prev" onclick="document.getElementById('pdfAcao').value='gerar_preventiva'">Gerar preventiva</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function oscAbrir(btn) {
            var d = {};
            try { d = JSON.parse(btn.getAttribute('data-os') || '{}'); } catch (e) { d = {}; }
            document.getElementById('pdfVisitaId').value = d.id || '';
            document.getElementById('pdfModalTitulo').textContent = d.numero || 'Gerar PDF';
            document.getElementById('pdfTextoServico').value = d.texto_servico || '';
            document.getElementById('pdfValor').value = d.valor || '';
            var selModelo = document.getElementById('pdfModelo');
            if (selModelo) selModelo.value = d.modelo || '';
            document.getElementById('opPreco').checked = d.preco !== false;
            document.getElementById('opTotal').checked = d.total !== false;
            document.getElementById('opDetalhes').checked = d.detalhes !== false;
            document.getElementById('opFotos').checked = d.fotos !== false;
            document.getElementById('pdfModal').classList.add('open');
        }
        function oscFechar() { document.getElementById('pdfModal').classList.remove('open'); }
        document.getElementById('pdfModal').addEventListener('click', function (e) {
            if (e.target === this) oscFechar();
        });
    </script>
<?php vn_ui_shell_end('os-concluidas'); ?>
