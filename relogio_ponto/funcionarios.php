<?php
require_once __DIR__ . '/_config.php';
require_admin();

function funcionarios_com_mesmo_pin(string $pin, int $ignorarId = 0): array
{
    if (!pin_fingerprint_disponivel()) {
        return [];
    }
    $stmt = db()->prepare("SELECT f.id, f.nome, f.ativo, co.nome AS condominio_nome
        FROM ponto_funcionarios f
        INNER JOIN ponto_condominios co ON co.id = f.condominio_id
        WHERE f.pin_fingerprint = ? AND f.id <> ?
        ORDER BY f.nome");
    $stmt->execute([pin_fingerprint($pin), $ignorarId]);
    return $stmt->fetchAll();
}

$erro = '';
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $acao = $_POST['acao'] ?? 'salvar';
        if ($acao === 'toggle') {
            $id = (int)$_POST['id'];
            $f = get_funcionario($id);
            if (!$f) throw new RuntimeException('Funcionário não encontrado.');
            if (admin_nivel() !== 'super_admin' && admin_condominio_id() !== (int)$f['condominio_id']) {
                throw new RuntimeException('Sem permissão para alterar este funcionário.');
            }
            db()->prepare("UPDATE ponto_funcionarios SET ativo = IF(ativo=1,0,1) WHERE id = ?")->execute([$id]);
            log_action('alterar_status_funcionario', 'Alterou status do funcionário ID ' . $id, $id, (int)$f['condominio_id']);
            flash_set('success', 'Status alterado.');
            redirect('funcionarios.php');
        }
        if ($acao === 'trocar_pin') {
            $id = (int)$_POST['id'];
            $pin = trim($_POST['novo_pin'] ?? '');
            $f = get_funcionario($id);
            if (!$f) throw new RuntimeException('Funcionário não encontrado.');
            if (strlen($pin) < 4) throw new RuntimeException('PIN deve ter no mínimo 4 números.');
            if (admin_nivel() !== 'super_admin' && admin_condominio_id() !== (int)$f['condominio_id']) {
                throw new RuntimeException('Sem permissão para alterar este funcionário.');
            }
            $repetidos = funcionarios_com_mesmo_pin($pin, $id);
            if ($repetidos) {
                $nomes = array_map(function ($o) { return $o['nome'] . ' (' . $o['condominio_nome'] . ')'; }, $repetidos);
                throw new RuntimeException('Esse PIN já está em uso por: ' . implode(', ', $nomes) . '. Escolha outro PIN.');
            }
            if (pin_fingerprint_disponivel()) {
                db()->prepare("UPDATE ponto_funcionarios SET pin_hash = ?, pin_fingerprint = ? WHERE id = ?")->execute([password_hash($pin, PASSWORD_DEFAULT), pin_fingerprint($pin), $id]);
            } else {
                db()->prepare("UPDATE ponto_funcionarios SET pin_hash = ? WHERE id = ?")->execute([password_hash($pin, PASSWORD_DEFAULT), $id]);
            }
            log_action('trocar_pin_funcionario', 'Trocou PIN do funcionário ID ' . $id, $id, (int)$f['condominio_id']);
            flash_set('success', 'PIN alterado.');
            redirect('funcionarios.php');
        }
        if ($acao === 'mudar_condominio') {
            $id = (int)$_POST['id'];
            $novoCondominioId = (int)($_POST['novo_condominio_id'] ?? 0);
            $f = get_funcionario($id);
            if (!$f) throw new RuntimeException('Funcionário não encontrado.');
            if (admin_nivel() !== 'super_admin' && admin_condominio_id() !== null) {
                throw new RuntimeException('Apenas administradores gerais podem mudar funcionário de condomínio.');
            }
            if ($novoCondominioId <= 0) throw new RuntimeException('Escolha o condomínio de destino.');
            if ($novoCondominioId === (int)$f['condominio_id']) throw new RuntimeException('O funcionário já está nesse condomínio.');
            $stmtCo = db()->prepare("SELECT id, nome FROM ponto_condominios WHERE id = ?");
            $stmtCo->execute([$novoCondominioId]);
            $co = $stmtCo->fetch();
            if (!$co) throw new RuntimeException('Condomínio de destino não encontrado.');
            db()->prepare("UPDATE ponto_funcionarios SET condominio_id = ? WHERE id = ?")->execute([$novoCondominioId, $id]);
            log_action('mudar_condominio_funcionario', 'Mudou funcionário ' . $f['nome'] . ' (ID ' . $id . ') de "' . $f['condominio_nome'] . '" para "' . $co['nome'] . '"', $id, $novoCondominioId);
            flash_set('success', 'Funcionário ' . $f['nome'] . ' movido para ' . $co['nome'] . '. As batidas antigas continuam registradas no condomínio anterior.');
            redirect('funcionarios.php');
        }

        $condominioId = (int)($_POST['condominio_id'] ?? 0);
        if (admin_nivel() !== 'super_admin' && admin_condominio_id() !== null) {
            $condominioId = admin_condominio_id();
        }
        $cargoId = (int)($_POST['cargo_id'] ?? 0) ?: null;
        $nome = trim($_POST['nome'] ?? '');
        $pin = trim($_POST['pin'] ?? '');
        if ($condominioId <= 0) throw new RuntimeException('Escolha o condomínio.');
        if ($nome === '') throw new RuntimeException('Informe o nome do funcionário.');
        if (strlen($pin) < 4) throw new RuntimeException('PIN deve ter no mínimo 4 números.');

        $repetidos = funcionarios_com_mesmo_pin($pin);
        if ($repetidos) {
            $nomes = array_map(function ($o) { return $o['nome'] . ' (' . $o['condominio_nome'] . ')'; }, $repetidos);
            throw new RuntimeException('Esse PIN já está em uso por: ' . implode(', ', $nomes) . '. Escolha outro PIN ou gere um aleatório.');
        }

        if (pin_fingerprint_disponivel()) {
            $stmt = db()->prepare("INSERT INTO ponto_funcionarios (condominio_id, cargo_id, nome, cpf, telefone, email, pin_hash, pin_fingerprint, ativo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)");
            $stmt->execute([$condominioId, $cargoId, $nome, trim($_POST['cpf'] ?? ''), trim($_POST['telefone'] ?? ''), trim($_POST['email'] ?? ''), password_hash($pin, PASSWORD_DEFAULT), pin_fingerprint($pin)]);
        } else {
            $stmt = db()->prepare("INSERT INTO ponto_funcionarios (condominio_id, cargo_id, nome, cpf, telefone, email, pin_hash, ativo) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
            $stmt->execute([$condominioId, $cargoId, $nome, trim($_POST['cpf'] ?? ''), trim($_POST['telefone'] ?? ''), trim($_POST['email'] ?? ''), password_hash($pin, PASSWORD_DEFAULT)]);
        }
        log_action('criar_funcionario', 'Cadastrou funcionário: ' . $nome, (int)db()->lastInsertId(), $condominioId);
        flash_set('success', 'Funcionário cadastrado.');
        redirect('funcionarios.php');
    }
} catch (Throwable $e) { $erro = $e->getMessage(); }

$condominios = get_condominios(true);
$cargos = get_cargos(true);

if (admin_nivel() === 'super_admin' || admin_condominio_id() === null) {
    $stmt = db()->query("SELECT f.*, c.nome AS cargo_nome, co.nome AS condominio_nome
        FROM ponto_funcionarios f
        LEFT JOIN ponto_cargos c ON c.id = f.cargo_id
        INNER JOIN ponto_condominios co ON co.id = f.condominio_id
        ORDER BY f.ativo DESC, co.nome, f.nome");
} else {
    $stmt = db()->prepare("SELECT f.*, c.nome AS cargo_nome, co.nome AS condominio_nome
        FROM ponto_funcionarios f
        LEFT JOIN ponto_cargos c ON c.id = f.cargo_id
        INNER JOIN ponto_condominios co ON co.id = f.condominio_id
        WHERE f.condominio_id = ?
        ORDER BY f.ativo DESC, f.nome");
    $stmt->execute([admin_condominio_id()]);
}
$rows = $stmt->fetchAll();

$adminGeral = (admin_nivel() === 'super_admin' || admin_condominio_id() === null);

// PINs repetidos: agrupa pela impressão digital do PIN (não expõe o PIN em si).
$pinsRepetidos = [];
$gruposPinRepetido = [];
$pinsNaoConferidos = 0;
if (pin_fingerprint_disponivel()) {
    $dups = db()->query("SELECT pin_fingerprint FROM ponto_funcionarios WHERE pin_fingerprint IS NOT NULL AND pin_fingerprint <> '' GROUP BY pin_fingerprint HAVING COUNT(*) > 1")->fetchAll();
    foreach ($dups as $d) {
        $pinsRepetidos[(string)$d['pin_fingerprint']] = true;
    }
    if ($pinsRepetidos) {
        $ph = implode(',', array_fill(0, count($pinsRepetidos), '?'));
        $stmtG = db()->prepare("SELECT f.id, f.nome, f.ativo, f.pin_fingerprint, co.nome AS condominio_nome
            FROM ponto_funcionarios f
            INNER JOIN ponto_condominios co ON co.id = f.condominio_id
            WHERE f.pin_fingerprint IN ($ph)
            ORDER BY f.pin_fingerprint, f.nome");
        $stmtG->execute(array_keys($pinsRepetidos));
        foreach ($stmtG->fetchAll() as $g) {
            $gruposPinRepetido[(string)$g['pin_fingerprint']][] = $g;
        }
    }
    $pinsNaoConferidos = (int)db()->query("SELECT COUNT(*) FROM ponto_funcionarios WHERE pin_fingerprint IS NULL OR pin_fingerprint = ''")->fetchColumn();
}

app_header('Funcionários', '');
flash_show();
if ($erro) echo '<div class="alert error">' . h($erro) . '</div>';
?>
<section class="panel">
    <h1>Funcionários</h1>
    <p>Cadastre o funcionário com um PIN individual. O PIN não aparece no banco em texto aberto.</p>
    <form method="post" class="form">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <div class="form-row">
            <label>Condomínio
                <select name="condominio_id" <?= admin_nivel() !== 'super_admin' && admin_condominio_id() !== null ? 'disabled' : '' ?> required>
                    <option value="">Selecione</option>
                    <?php foreach ($condominios as $co): ?>
                        <option value="<?= (int)$co['id'] ?>" <?= admin_condominio_id() === (int)$co['id'] ? 'selected' : '' ?>><?= h($co['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Cargo
                <select name="cargo_id">
                    <option value="">Sem cargo</option>
                    <?php foreach ($cargos as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h($c['nome']) ?></option><?php endforeach; ?>
                </select>
            </label>
        </div>
        <div class="form-row">
            <label>Nome <input name="nome" required></label>
            <label>CPF <input name="cpf"></label>
        </div>
        <div class="form-row">
            <label>Telefone <input name="telefone"></label>
            <label>Email <input name="email" type="email"></label>
        </div>
        <label>PIN do funcionário
            <div style="display:flex;gap:8px;align-items:center">
                <input name="pin" id="pinNovoFunc" inputmode="numeric" minlength="4" required placeholder="Ex: 1234" style="flex:1">
                <button type="button" class="btn secondary" id="btnPinAleatorio">Gerar aleatório</button>
            </div>
            <small class="muted" id="pinAleatorioAviso" style="display:none">PIN gerado: <b id="pinAleatorioValor"></b> — anote e informe ao funcionário antes de cadastrar.</small>
        </label>
        <button type="submit">Cadastrar funcionário</button>
    </form>
</section>

<?php if ($adminGeral && pin_fingerprint_disponivel()): ?>
<section class="card" style="margin-top:18px">
    <h2>PINs repetidos</h2>
    <?php if ($gruposPinRepetido): ?>
        <p class="muted">Os funcionários abaixo estão usando o <b>mesmo PIN</b> (agrupados sem mostrar o PIN). PIN repetido atrapalha a batida: o sistema precisa pedir o condomínio para saber quem é. Troque o PIN de um deles na lista abaixo.</p>
        <div class="table-wrap"><table>
            <thead><tr><th>Grupo</th><th>Funcionários com o mesmo PIN</th></tr></thead>
            <tbody>
            <?php $numGrupo = 0; foreach ($gruposPinRepetido as $grupo): $numGrupo++; ?>
                <tr>
                    <td><span class="badge red">Grupo <?= (int)$numGrupo ?></span></td>
                    <td>
                        <?php foreach ($grupo as $g): ?>
                            <div><?= h($g['nome']) ?> — <?= h($g['condominio_nome']) ?> <span class="badge <?= $g['ativo'] ? 'green' : 'red' ?>"><?= $g['ativo'] ? 'Ativo' : 'Inativo' ?></span></div>
                        <?php endforeach; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php else: ?>
        <p class="muted">Nenhum PIN repetido encontrado entre os PINs já conferidos. 👍</p>
    <?php endif; ?>
    <?php if ($pinsNaoConferidos > 0): ?>
        <p class="muted"><b><?= (int)$pinsNaoConferidos ?> funcionário(s)</b> ainda têm PIN antigo não conferido (cadastrado antes dessa função existir). O PIN deles entra na verificação automaticamente na próxima vez que baterem ponto, ou na hora que você trocar o PIN deles.</p>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="card" style="margin-top:18px">
    <h2>Lista</h2>
    <div class="table-wrap"><table><thead><tr><th>ID</th><th>Nome</th><th>Condomínio</th><th>Cargo</th><th>Status</th><th>Trocar PIN</th><th>Ação</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><?= (int)$r['id'] ?></td>
            <td>
                <?= h($r['nome']) ?>
                <?php if (!empty($r['pin_fingerprint']) && isset($pinsRepetidos[(string)$r['pin_fingerprint']])): ?>
                    <span class="badge red" title="Outro funcionário está usando o mesmo PIN">PIN repetido</span>
                <?php endif; ?>
                <br><span class="muted"><?= h($r['cpf']) ?></span>
            </td>
            <td>
                <?= h($r['condominio_nome']) ?>
                <?php if ($adminGeral): ?>
                    <form method="post" class="actions" style="margin-top:6px">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="acao" value="mudar_condominio">
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <select name="novo_condominio_id" style="width:150px">
                            <option value="0">Mudar para...</option>
                            <?php foreach ($condominios as $co): ?>
                                <?php if ((int)$co['id'] === (int)$r['condominio_id']) continue; ?>
                                <option value="<?= (int)$co['id'] ?>"><?= h($co['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn secondary" data-confirm="Mudar <?= h($r['nome']) ?> de condomínio? As batidas antigas continuam no condomínio atual.">Mudar</button>
                    </form>
                <?php endif; ?>
            </td>
            <td><?= h($r['cargo_nome'] ?? 'Sem cargo') ?></td>
            <td><span class="badge <?= $r['ativo'] ? 'green' : 'red' ?>"><?= $r['ativo'] ? 'Ativo' : 'Inativo' ?></span></td>
            <td>
                <form method="post" class="actions">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="acao" value="trocar_pin">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <input style="width:110px" name="novo_pin" placeholder="Novo PIN" minlength="4" inputmode="numeric" required>
                    <button class="btn secondary">Salvar</button>
                </form>
            </td>
            <td>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="acao" value="toggle">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button class="btn secondary" data-confirm="Alterar status?">Alterar</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7">Nenhum funcionário cadastrado.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>
<script>
(function(){
    var btn = document.getElementById('btnPinAleatorio');
    var input = document.getElementById('pinNovoFunc');
    var aviso = document.getElementById('pinAleatorioAviso');
    var valor = document.getElementById('pinAleatorioValor');
    if (!btn || !input) return;
    btn.addEventListener('click', function(){
        var pin = '';
        if (window.crypto && window.crypto.getRandomValues) {
            var buf = new Uint32Array(1);
            window.crypto.getRandomValues(buf);
            pin = String(1000 + (buf[0] % 9000));
        } else {
            pin = String(1000 + Math.floor(Math.random() * 9000));
        }
        input.value = pin;
        if (aviso && valor) {
            valor.textContent = pin;
            aviso.style.display = '';
        }
    });
})();
</script>
<?php app_footer(); ?>
