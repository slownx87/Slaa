<?php
require_once __DIR__ . '/_config.php';
require_admin();

$erro = '';
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $acao = $_POST['acao'] ?? 'salvar';
        if ($acao === 'toggle') {
            $id = (int)$_POST['id'];
            db()->prepare("UPDATE ponto_cargos SET ativo = IF(ativo=1,0,1) WHERE id = ?")->execute([$id]);
            log_action('alterar_status_cargo', 'Alterou status do cargo ID ' . $id);
            flash_set('success', 'Status alterado.');
            redirect('cargos.php');
        }
        $nome = trim($_POST['nome'] ?? '');
        if ($nome === '') throw new RuntimeException('Informe o nome do cargo.');
        $stmt = db()->prepare("INSERT INTO ponto_cargos (nome, descricao, ativo) VALUES (?, ?, 1)");
        $stmt->execute([$nome, trim($_POST['descricao'] ?? '')]);
        log_action('criar_cargo', 'Cadastrou cargo: ' . $nome);
        flash_set('success', 'Cargo cadastrado.');
        redirect('cargos.php');
    }
} catch (Throwable $e) { $erro = $e->getMessage(); }

$rows = db()->query("SELECT * FROM ponto_cargos ORDER BY ativo DESC, nome")->fetchAll();
app_header('Cargos', '');
flash_show();
if ($erro) echo '<div class="alert error">' . h($erro) . '</div>';
?>
<section class="panel">
    <h1>Cargos</h1>
    <form method="post" class="form">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <label>Nome do cargo <input name="nome" required placeholder="Ex: Controlador de Acesso"></label>
        <label>Descrição <textarea name="descricao"></textarea></label>
        <button type="submit">Cadastrar cargo</button>
    </form>
</section>
<section class="card" style="margin-top:18px">
    <h2>Lista</h2>
    <div class="table-wrap"><table><thead><tr><th>ID</th><th>Cargo</th><th>Descrição</th><th>Status</th><th>Ação</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
        <tr><td><?= (int)$r['id'] ?></td><td><?= h($r['nome']) ?></td><td><?= h($r['descricao']) ?></td><td><span class="badge <?= $r['ativo'] ? 'green' : 'red' ?>"><?= $r['ativo'] ? 'Ativo' : 'Inativo' ?></span></td><td><form method="post"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="acao" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn secondary" data-confirm="Alterar status?">Alterar</button></form></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</section>
<?php app_footer(); ?>
