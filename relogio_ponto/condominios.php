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
            db()->prepare("UPDATE ponto_condominios SET ativo = IF(ativo=1,0,1) WHERE id = ?")->execute([$id]);
            log_action('alterar_status_condominio', 'Alterou status do condomínio ID ' . $id, null, $id);
            flash_set('success', 'Status alterado.');
            redirect('condominios.php');
        }
        $nome = trim($_POST['nome'] ?? '');
        if ($nome === '') throw new RuntimeException('Informe o nome do condomínio.');
        $stmt = db()->prepare("INSERT INTO ponto_condominios (nome, cnpj, endereco, numero, bairro, cidade, uf, cep, ativo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)");
        $stmt->execute([$nome, trim($_POST['cnpj'] ?? ''), trim($_POST['endereco'] ?? ''), trim($_POST['numero'] ?? ''), trim($_POST['bairro'] ?? ''), trim($_POST['cidade'] ?? 'São Paulo'), strtoupper(trim($_POST['uf'] ?? 'SP')), trim($_POST['cep'] ?? '')]);
        log_action('criar_condominio', 'Cadastrou condomínio: ' . $nome, null, (int)db()->lastInsertId());
        flash_set('success', 'Condomínio cadastrado.');
        redirect('condominios.php');
    }
} catch (Throwable $e) { $erro = $e->getMessage(); }

$rows = db()->query("SELECT * FROM ponto_condominios ORDER BY ativo DESC, nome")->fetchAll();
app_header('Condomínios', '');
flash_show();
if ($erro) echo '<div class="alert error">' . h($erro) . '</div>';
?>
<section class="panel">
    <h1>Condomínios</h1>
    <form method="post" class="form">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <div class="form-row">
            <label>Nome <input name="nome" required></label>
            <label>CNPJ <input name="cnpj"></label>
        </div>
        <div class="form-row">
            <label>Endereço <input name="endereco"></label>
            <label>Número <input name="numero"></label>
        </div>
        <div class="form-row">
            <label>Bairro <input name="bairro"></label>
            <label>CEP <input name="cep"></label>
        </div>
        <div class="form-row">
            <label>Cidade <input name="cidade" value="São Paulo"></label>
            <label>UF <input name="uf" value="SP" maxlength="2"></label>
        </div>
        <button type="submit">Cadastrar condomínio</button>
    </form>
</section>
<section class="card" style="margin-top:18px">
    <h2>Lista</h2>
    <div class="table-wrap"><table><thead><tr><th>ID</th><th>Nome</th><th>Endereço</th><th>Status</th><th>Ação</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
        <tr><td><?= (int)$r['id'] ?></td><td><?= h($r['nome']) ?></td><td><?= h(trim(($r['endereco'] ?? '') . ', ' . ($r['numero'] ?? '') . ' - ' . ($r['bairro'] ?? ''))) ?></td><td><span class="badge <?= $r['ativo'] ? 'green' : 'red' ?>"><?= $r['ativo'] ? 'Ativo' : 'Inativo' ?></span></td><td><form method="post"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="acao" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn secondary" data-confirm="Alterar status?">Alterar</button></form></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</section>
<?php app_footer(); ?>
