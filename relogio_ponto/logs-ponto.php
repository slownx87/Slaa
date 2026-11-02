<?php
require_once __DIR__ . '/_config.php';
require_admin();
ensure_photo_columns();

$dataIni = $_GET['data_ini'] ?? today_sp();
$dataFim = $_GET['data_fim'] ?? today_sp();
$funcionarioIdsRaw = $_GET['funcionario_ids'] ?? [];
if (!is_array($funcionarioIdsRaw)) $funcionarioIdsRaw = [$funcionarioIdsRaw];
$funcionarioIds = array_values(array_unique(array_filter(array_map('intval', $funcionarioIdsRaw), fn($v) => $v > 0)));

$where = ["r.data_ponto BETWEEN ? AND ?"];
$params = [$dataIni, $dataFim];
if (admin_nivel() !== 'super_admin' && admin_condominio_id() !== null) {
    $where[] = "r.condominio_id = ?";
    $params[] = admin_condominio_id();
}
if ($funcionarioIds) {
    $ph = implode(',', array_fill(0, count($funcionarioIds), '?'));
    $where[] = "r.funcionario_id IN ($ph)";
    $params = array_merge($params, $funcionarioIds);
}

$sql = "SELECT r.*, f.nome AS funcionario_nome, ca.nome AS cargo_nome, co.nome AS condominio_nome
    FROM ponto_registros r
    INNER JOIN ponto_funcionarios f ON f.id = r.funcionario_id
    LEFT JOIN ponto_cargos ca ON ca.id = f.cargo_id
    INNER JOIN ponto_condominios co ON co.id = r.condominio_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY r.data_hora DESC, r.id DESC";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$registros = $stmt->fetchAll();

if (admin_nivel() === 'super_admin' || admin_condominio_id() === null) {
    $funcs = db()->query("SELECT id, nome FROM ponto_funcionarios WHERE ativo = 1 ORDER BY nome")->fetchAll();
} else {
    $stmtF = db()->prepare("SELECT id, nome FROM ponto_funcionarios WHERE ativo = 1 AND condominio_id = ? ORDER BY nome");
    $stmtF->execute([admin_condominio_id()]);
    $funcs = $stmtF->fetchAll();
}

app_header('Logs de ponto', '');
?>
<section class="panel">
    <h1>Logs de ponto</h1>
    <form method="get" class="searchbar no-print">
        <label>Data inicial <input type="date" name="data_ini" value="<?= h($dataIni) ?>"></label>
        <label>Data final <input type="date" name="data_fim" value="<?= h($dataFim) ?>"></label>
        <label>Funcionários
            <select name="funcionario_ids[]" multiple size="6">
                <?php foreach ($funcs as $f): ?>
                    <option value="<?= (int)$f['id'] ?>" <?= in_array((int)$f['id'], $funcionarioIds, true) ? 'selected' : '' ?>><?= h($f['nome']) ?></option>
                <?php endforeach; ?>
            </select>
            <small>Sem selecionar = todos. Pode selecionar vários funcionários.</small>
        </label>
        <button type="submit">Filtrar</button>
        <button type="button" onclick="window.print()" class="secondary">Imprimir/PDF</button>
    </form>
    <div class="grid grid-3" style="margin-top:18px">
        <div class="stat"><strong><?= count($registros) ?></strong><span>batidas encontradas</span></div>
        <div class="stat"><strong><?= h(date('d/m/Y', strtotime($dataIni))) ?></strong><span>início</span></div>
        <div class="stat"><strong><?= h(date('d/m/Y', strtotime($dataFim))) ?></strong><span>fim</span></div>
    </div>
</section>
<section class="card" style="margin-top:18px">
    <h2>Registros</h2>
    <div class="table-wrap"><table><thead><tr><th>Data/Hora</th><th>Funcionário</th><th>Cargo</th><th>Condomínio</th><th>Tipo</th><th>Foto</th><th>Observação</th></tr></thead><tbody>
    <?php foreach ($registros as $r): ?>
        <tr>
            <td><?= h(date('d/m/Y H:i:s', strtotime($r['data_hora']))) ?></td>
            <td><?= h($r['funcionario_nome']) ?></td>
            <td><?= h($r['cargo_nome']) ?></td>
            <td><?= h($r['condominio_nome']) ?></td>
            <td><span class="badge"><?= h(tipo_ponto_label($r['tipo'])) ?></span></td>
            <td><?php if ($r['foto_path']): ?><a target="_blank" class="photo-link" href="<?= h($r['foto_path']) ?>">Ver foto</a><?php else: ?>Sem foto<?php endif; ?></td>
            <td><?= h($r['observacao']) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$registros): ?><tr><td colspan="7">Nenhuma batida encontrada.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>
<?php app_footer(); ?>
