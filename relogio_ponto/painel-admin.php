<?php
require_once __DIR__ . '/_config.php';
require_admin();
try { ensure_photo_columns(); } catch (Throwable $e) { flash_set('error', $e->getMessage()); }

[$condWhere, $condParams] = admin_where_condominio('');
$condClause = $condWhere ? ' WHERE 1=1 ' . $condWhere : '';

function scalar_q(string $sql, array $params = []): int
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

if (admin_nivel() === 'super_admin' || admin_condominio_id() === null) {
    $totalConds = scalar_q("SELECT COUNT(*) FROM ponto_condominios WHERE ativo = 1");
    $totalFuncs = scalar_q("SELECT COUNT(*) FROM ponto_funcionarios WHERE ativo = 1");
    $batidasHoje = scalar_q("SELECT COUNT(*) FROM ponto_registros WHERE data_ponto = ?", [today_sp()]);
    $abertosHoje = scalar_q("SELECT COUNT(*) FROM ponto_resumo_diario WHERE data_ponto = ? AND status <> 'fechado'", [today_sp()]);

    $stmt = db()->prepare("SELECT r.*, f.nome AS funcionario_nome, c.nome AS condominio_nome
        FROM ponto_registros r
        INNER JOIN ponto_funcionarios f ON f.id = r.funcionario_id
        INNER JOIN ponto_condominios c ON c.id = r.condominio_id
        ORDER BY r.data_hora DESC LIMIT 12");
    $stmt->execute();
} else {
    $cid = admin_condominio_id();
    $totalConds = 1;
    $totalFuncs = scalar_q("SELECT COUNT(*) FROM ponto_funcionarios WHERE ativo = 1 AND condominio_id = ?", [$cid]);
    $batidasHoje = scalar_q("SELECT COUNT(*) FROM ponto_registros WHERE data_ponto = ? AND condominio_id = ?", [today_sp(), $cid]);
    $abertosHoje = scalar_q("SELECT COUNT(*) FROM ponto_resumo_diario WHERE data_ponto = ? AND status <> 'fechado' AND condominio_id = ?", [today_sp(), $cid]);

    $stmt = db()->prepare("SELECT r.*, f.nome AS funcionario_nome, c.nome AS condominio_nome
        FROM ponto_registros r
        INNER JOIN ponto_funcionarios f ON f.id = r.funcionario_id
        INNER JOIN ponto_condominios c ON c.id = r.condominio_id
        WHERE r.condominio_id = ?
        ORDER BY r.data_hora DESC LIMIT 12");
    $stmt->execute([$cid]);
}
$ultimos = $stmt->fetchAll();

app_header('Painel Admin', '');
flash_show();
?>
<section class="panel">
    <h1>Olá, <?= h(admin_nome()) ?></h1>
    <p>Painel do relógio de ponto com condomínios separados, funcionários por PIN e foto obrigatória na batida.</p>
    <div class="grid grid-4">
        <div class="stat"><strong><?= $totalConds ?></strong><span>Condomínios ativos</span></div>
        <div class="stat"><strong><?= $totalFuncs ?></strong><span>Funcionários ativos</span></div>
        <div class="stat"><strong><?= $batidasHoje ?></strong><span>Batidas hoje</span></div>
        <div class="stat"><strong><?= $abertosHoje ?></strong><span>Dias em aberto</span></div>
    </div>
    <div class="actions" style="margin-top:18px">
        <a class="btn" href="bater-ponto.php">Abrir tela de ponto</a>
        <a class="btn secondary" href="funcionarios.php">Cadastrar funcionário</a>
        <a class="btn secondary" href="relatorios.php">Ver relatórios</a>
    </div>
</section>

<section class="card" style="margin-top:18px">
    <h2>Últimas batidas</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Data/Hora</th><th>Funcionário</th><th>Condomínio</th><th>Tipo</th><th>Foto</th></tr></thead>
            <tbody>
            <?php foreach ($ultimos as $r): ?>
                <tr>
                    <td><?= h(date('d/m/Y H:i:s', strtotime($r['data_hora']))) ?></td>
                    <td><?= h($r['funcionario_nome']) ?></td>
                    <td><?= h($r['condominio_nome']) ?></td>
                    <td><span class="badge"><?= h(tipo_ponto_label($r['tipo'])) ?></span></td>
                    <td><?php if ($r['foto_path']): ?><a class="photo-link" target="_blank" href="<?= h($r['foto_path']) ?>">Ver foto</a><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$ultimos): ?><tr><td colspan="5">Nenhuma batida registrada ainda.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php app_footer(); ?>
