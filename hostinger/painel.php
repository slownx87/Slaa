<?php
/**
 * VN System - portal Wi-Fi | Painel de cadastros
 * URL: https://seudominio.com.br/wifi/painel.php
 */

require __DIR__ . '/config.php';

session_start();

/* --- Sair ---------------------------------------------------------------- */
if (isset($_GET['sair'])) {
    session_destroy();
    header('Location: painel.php');
    exit;
}

/* --- Login --------------------------------------------------------------- */
$erroLogin = '';
if (!($_SESSION['ok'] ?? false)) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['senha'])) {
        if (password_verify((string)$_POST['senha'], PAINEL_HASH)) {
            session_regenerate_id(true);
            $_SESSION['ok'] = true;
            header('Location: painel.php');
            exit;
        }
        $erroLogin = 'Senha incorreta.';
        usleep(700000);
    }
    ?><!DOCTYPE html>
    <html lang="pt-BR"><head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>VN System &mdash; Painel</title><link rel="stylesheet" href="vn.css">
    </head><body class="login-page">
      <div class="login-box">
        <div class="vn-brand">
          <img class="brand-logo" src="logo.png" alt="VN System"
               onerror="this.style.display='none';this.nextElementSibling.style.display='block';">
          <div class="vn-wordmark"><b>VN</b><span>SYSTEM</span></div>
        </div>
        <h1>Painel de cadastros</h1>
        <p class="sub">Acesso restrito.</p>
        <?php if ($erroLogin): ?><div class="alert alert-error"><?= h($erroLogin) ?></div><?php endif; ?>
        <form method="post" class="form-grid">
          <div class="field full">
            <label for="senha">Senha</label>
            <input type="password" id="senha" name="senha" autocomplete="current-password" autofocus>
          </div>
          <div class="field full">
            <button class="btn btn-primary btn-block">Entrar</button>
          </div>
        </form>
      </div>
    </body></html><?php
    exit;
}

/* --- Exclusao (direito de exclusao da LGPD) ------------------------------ */
$aviso = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['excluir'])) {
    if (hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
        db()->prepare('DELETE FROM cadastros WHERE id = ?')->execute([(int)$_POST['excluir']]);
        $aviso = 'Cadastro excluido.';
    }
}
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

/* --- Filtros ------------------------------------------------------------- */
$busca = trim((string)($_GET['q']     ?? ''));
$bloco = trim((string)($_GET['bloco'] ?? ''));
$de    = trim((string)($_GET['de']    ?? ''));
$ate   = trim((string)($_GET['ate']   ?? ''));
$pag   = max(1, (int)($_GET['pag'] ?? 1));

$onde = [];
$par  = [];

if ($busca !== '') {
    $onde[] = '(nome LIKE :q OR cpf LIKE :qd OR ap LIKE :q2 OR mac LIKE :q3)';
    $par[':q']  = '%' . $busca . '%';
    $par[':qd'] = '%' . preg_replace('/\D+/', '', $busca) . '%';
    $par[':q2'] = '%' . $busca . '%';
    $par[':q3'] = '%' . $busca . '%';
}
if ($bloco !== '') { $onde[] = 'bloco = :bloco'; $par[':bloco'] = $bloco; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $de))  { $onde[] = 'criado_em >= :de';  $par[':de']  = $de . ' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate)) { $onde[] = 'criado_em <= :ate'; $par[':ate'] = $ate . ' 23:59:59'; }

$where = $onde ? ('WHERE ' . implode(' AND ', $onde)) : '';

/* --- Exportar CSV -------------------------------------------------------- */
if (isset($_GET['csv'])) {
    $st = db()->prepare("SELECT * FROM cadastros $where ORDER BY criado_em DESC");
    $st->execute($par);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="cadastros-wifi-' . date('Y-m-d') . '.csv"');

    /* Neutraliza formula: celula comecando com = + - @ vira texto no Excel. */
    $seguro = static function ($v): string {
        $v = (string)$v;
        return preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v;
    };

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // BOM: acentos certos no Excel
    fputcsv($out, ['Data/hora','Nome completo','CPF','Bloco','Apartamento','Celular',
                   'AP / Local','Confere?','MAC','IP','Servidor','Roteador'], ';');
    while ($r = $st->fetch()) {
        fputcsv($out, array_map($seguro, [
            $r['criado_em'], $r['nome'], cpfFmt($r['cpf']), $r['bloco'], $r['ap'],
            foneFmt($r['fone']), $r['local_ap'], $r['confere'] ?: 'ok',
            $r['mac'], $r['ip'], $r['servidor'], $r['roteador'],
        ]), ';');
    }
    fclose($out);
    exit;
}

/* --- Consulta ------------------------------------------------------------ */
$st = db()->prepare("SELECT COUNT(*) FROM cadastros $where");
$st->execute($par);
$total = (int)$st->fetchColumn();

$paginas = max(1, (int)ceil($total / POR_PAGINA));
$pag     = min($pag, $paginas);
$offset  = ($pag - 1) * POR_PAGINA;

$st = db()->prepare("SELECT * FROM cadastros $where ORDER BY criado_em DESC LIMIT " . (int)POR_PAGINA . " OFFSET $offset");
$st->execute($par);
$linhas = $st->fetchAll();

$hoje = (int)db()->query("SELECT COUNT(*) FROM cadastros WHERE DATE(criado_em) = CURDATE()")->fetchColumn();
$geral = (int)db()->query("SELECT COUNT(*) FROM cadastros")->fetchColumn();
$pess  = (int)db()->query("SELECT COUNT(DISTINCT COALESCE(cpf, mac)) FROM cadastros")->fetchColumn();
$div   = (int)db()->query("SELECT COUNT(*) FROM cadastros WHERE confere IS NOT NULL AND confere <> ''")->fetchColumn();

$blocos = db()->query("SELECT DISTINCT bloco FROM cadastros ORDER BY bloco")->fetchAll(PDO::FETCH_COLUMN);

function link_com(array $novo): string {
    return '?' . http_build_query(array_merge($_GET, $novo));
}
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>VN System &mdash; Cadastros do Wi-Fi</title>
<link rel="stylesheet" href="vn.css">
</head>
<body>
<div class="admin-main" style="min-height:100vh">

  <div class="topline">
    <div style="display:flex;align-items:center;gap:12px">
      <img class="brand-logo" src="logo.png" alt="VN System"
           onerror="this.style.display='none';this.nextElementSibling.style.display='block';">
      <div class="vn-wordmark" style="text-align:left"><b style="font-size:30px">VN</b></div>
      <div>
        <h2 style="margin:0;color:#fff;font-size:20px;letter-spacing:-.03em">Cadastros do Wi-Fi</h2>
        <span style="color:var(--muted);font-size:13px">Portal de acesso &agrave; rede</span>
      </div>
    </div>
    <div class="actions">
      <a class="btn btn-ghost btn-small" href="<?= h(link_com(['csv' => 1])) ?>">Exportar CSV</a>
      <a class="btn btn-danger btn-small" href="?sair=1">Sair</a>
    </div>
  </div>

  <?php if ($aviso): ?><div class="alert alert-ok"><?= h($aviso) ?></div><?php endif; ?>

  <div class="cards" style="grid-template-columns:repeat(4,1fr)">
    <div class="dash-card"><span>Cadastros hoje</span><strong><?= $hoje ?></strong></div>
    <div class="dash-card"><span>Total</span><strong><?= $geral ?></strong></div>
    <div class="dash-card"><span>Pessoas distintas</span><strong><?= $pess ?></strong></div>
    <div class="dash-card"><span>Bloco divergente</span><strong><?= $div ?></strong></div>
  </div>

  <form class="filters" method="get">
    <input type="text" name="q" value="<?= h($busca) ?>" placeholder="Nome, CPF, apartamento ou MAC">
    <select name="bloco">
      <option value="">Todos os blocos</option>
      <?php foreach ($blocos as $b): ?>
        <option value="<?= h($b) ?>" <?= $b === $bloco ? 'selected' : '' ?>>Bloco <?= h($b) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="date" name="de"  value="<?= h($de) ?>"  title="De">
    <input type="date" name="ate" value="<?= h($ate) ?>" title="Ate">
    <button class="btn btn-primary btn-small">Filtrar</button>
    <a class="btn btn-ghost btn-small" href="painel.php">Limpar</a>
  </form>

  <div class="table-card">
    <div class="table-responsive">
      <table>
        <thead>
          <tr>
            <th>Data/hora</th><th>Nome</th><th>CPF</th><th>Bloco / Ap</th>
            <th>Celular</th><th>AP / Local</th><th>Aparelho</th><th></th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$linhas): ?>
          <tr><td colspan="8" style="color:var(--muted)">Nenhum cadastro encontrado.</td></tr>
        <?php endif; ?>
        <?php foreach ($linhas as $r): ?>
          <tr>
            <td data-label="Data/hora"><?= h(date('d/m/Y H:i', strtotime($r['criado_em']))) ?></td>
            <td data-label="Nome"><?= h($r['nome']) ?></td>
            <td data-label="CPF"><?= h(cpfFmt($r['cpf'])) ?></td>
            <td data-label="Bloco / Ap"><b><?= h($r['bloco']) ?></b> &middot; <?= h($r['ap']) ?></td>
            <td data-label="Celular"><?= h(foneFmt($r['fone'])) ?></td>
            <td data-label="AP / Local">
              <?= h($r['local_ap']) ?>
              <?php if (!empty($r['confere'])): ?>
                <br><span class="status status-perdido" title="<?= h($r['confere']) ?>">divergente</span>
              <?php endif; ?>
            </td>
            <td data-label="Aparelho">
              <span style="font-size:12px;color:var(--muted)"><?= h($r['mac']) ?><br><?= h($r['ip']) ?></span>
            </td>
            <td data-label="">
              <form method="post" onsubmit="return confirm('Excluir este cadastro? Nao da para desfazer.')">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                <input type="hidden" name="excluir" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-danger btn-small">Excluir</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($paginas > 1): ?>
    <div class="actions" style="margin-top:16px;justify-content:center">
      <?php if ($pag > 1): ?>
        <a class="btn btn-ghost btn-small" href="<?= h(link_com(['pag' => $pag - 1])) ?>">Anterior</a>
      <?php endif; ?>
      <span style="color:var(--muted);padding:9px 11px">P&aacute;gina <?= $pag ?> de <?= $paginas ?> &middot; <?= $total ?> registros</span>
      <?php if ($pag < $paginas): ?>
        <a class="btn btn-ghost btn-small" href="<?= h(link_com(['pag' => $pag + 1])) ?>">Pr&oacute;xima</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>

</div>
</body>
</html>
