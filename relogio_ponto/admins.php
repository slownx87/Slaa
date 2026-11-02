<?php
require_once __DIR__ . '/_config.php';
require_admin();

if (admin_nivel() !== 'super_admin') {
    app_header('Admins', '');
    echo '<div class="alert error">Somente super_admin pode gerenciar admins.</div>';
    app_footer();
    exit;
}

$erro = '';
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $acao = $_POST['acao'] ?? 'salvar';
        if ($acao === 'toggle') {
            $id = (int)$_POST['id'];
            if ($id === admin_id()) throw new RuntimeException('Você não pode desativar seu próprio usuário.');
            db()->prepare("UPDATE ponto_admins SET ativo = IF(ativo=1,0,1) WHERE id = ?")->execute([$id]);
            log_action('alterar_status_admin', 'Alterou status do admin ID ' . $id);
            flash_set('success', 'Status alterado.');
            redirect('admins.php');
        }
        $nome = trim($_POST['nome'] ?? '');
        $usuario = trim($_POST['usuario'] ?? '');
        $senha = (string)($_POST['senha'] ?? '');
        $nivel = $_POST['nivel'] ?? 'admin_condominio';
        $condominioId = (int)($_POST['condominio_id'] ?? 0) ?: null;
        if ($nome === '' || $usuario === '' || strlen($senha) < 6) {
            throw new RuntimeException('Informe nome, usuário e senha com no mínimo 6 caracteres.');
        }
        if (!in_array($nivel, ['super_admin','admin_condominio','supervisor'], true)) $nivel = 'admin_condominio';
        if ($nivel !== 'super_admin' && !$condominioId) {
            throw new RuntimeException('Admin de condomínio ou supervisor precisa estar vinculado a um condomínio.');
        }
        if ($nivel === 'super_admin') $condominioId = null;

        $stmt = db()->prepare("INSERT INTO ponto_admins (condominio_id, nome, usuario, senha_hash, nivel, ativo) VALUES (?, ?, ?, ?, ?, 1)");
        $stmt->execute([$condominioId, $nome, $usuario, password_hash($senha, PASSWORD_DEFAULT), $nivel]);
        log_action('criar_admin', 'Cadastrou admin: ' . $usuario);
        flash_set('success', 'Admin cadastrado.');
        redirect('admins.php');
    }
} catch (Throwable $e) { $erro = $e->getMessage(); }

$condominios = get_condominios(true);
$admins = db()->query("SELECT a.*, co.nome AS condominio_nome FROM ponto_admins a LEFT JOIN ponto_condominios co ON co.id = a.condominio_id ORDER BY a.ativo DESC, a.nome")->fetchAll();
app_header('Admins', '');
flash_show();
if ($erro) echo '<div class="alert error">' . h($erro) . '</div>';
?>
<section class="panel">
    <h1>Admins</h1>
    <form method="post" class="form">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <div class="form-row">
            <label>Nome <input name="nome" required></label>
            <label>Usuário <input name="usuario" required></label>
        </div>
        <div class="form-row">
            <label>Senha <input name="senha" type="password" minlength="6" required></label>
            <label>Nível
                <select name="nivel">
                    <option value="admin_condominio">Admin do condomínio</option>
                    <option value="supervisor">Supervisor</option>
                    <option value="super_admin">Super admin</option>
                </select>
            </label>
        </div>
        <label>Condomínio
            <select name="condominio_id">
                <option value="0">Nenhum / Super admin</option>
                <?php foreach ($condominios as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h($c['nome']) ?></option><?php endforeach; ?>
            </select>
        </label>
        <button type="submit">Cadastrar admin</button>
    </form>
</section>
<section class="card" style="margin-top:18px">
    <h2>Lista</h2>
    <div class="table-wrap"><table><thead><tr><th>ID</th><th>Nome</th><th>Usuário</th><th>Nível</th><th>Condomínio</th><th>Status</th><th>Ação</th></tr></thead><tbody>
    <?php foreach ($admins as $a): ?>
        <tr>
            <td><?= (int)$a['id'] ?></td>
            <td><?= h($a['nome']) ?></td>
            <td><?= h($a['usuario']) ?></td>
            <td><span class="badge"><?= h($a['nivel']) ?></span></td>
            <td><?= h($a['condominio_nome'] ?? 'Todos') ?></td>
            <td><span class="badge <?= $a['ativo'] ? 'green' : 'red' ?>"><?= $a['ativo'] ? 'Ativo' : 'Inativo' ?></span></td>
            <td>
                <form method="post"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="acao" value="toggle"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="btn secondary" data-confirm="Alterar status?">Alterar</button></form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</section>
<?php app_footer(); ?>
