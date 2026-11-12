<?php
/*
    VN System - Saídas de estoque
    Página dedicada para dar baixa em peças/equipamentos/usados do estoque.
    A seleção de materiais usa o mesmo modal de catálogo da tela de Pedido (orçamento).
*/
require_once __DIR__ . '/_vn_orcamento_layout.php';
require_once __DIR__ . '/_vn_devolucoes_lib.php';
require_once __DIR__ . '/_vn_cobranca_equip.php';
require_once __DIR__ . '/_vn_cobrancas_lib.php';
$ok = null; $erro = '';

try {
    vn_ensure_estoque_devolucoes();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $acao = (string)($_POST['acao'] ?? '');
        if ($acao === 'saida_estoque') {
            $ok = vn_registrar_saida_estoque_v1($_POST);
            // Toda saída vai direto para a central de Cobranças.
            if (vn_saida_cobrancas_auto_gerar() > 0) {
                $ok .= ' A cobrança do material já foi criada na página Cobranças.';
            }
        } elseif ($acao === 'excluir_saida') {
            $saidaIdExc = (int)($_POST['saida_id'] ?? 0);
            $ok = vn_excluir_saida_estoque_v1($saidaIdExc);
            // Remove também a cobrança gerada desta saída (senão fica órfã em Cobranças).
            if ($saidaIdExc > 0 && vn_table_exists('vn_saida_cobrancas')) {
                vn_exec('DELETE FROM vn_saida_cobrancas WHERE saida_id = ?', [$saidaIdExc]);
            }
        } elseif ($acao === 'editar_saida') {
            $ok = vn_editar_saida_estoque_v1($_POST);
        } elseif ($acao === 'devolver_saida') {
            $ok = vn_registrar_devolucao_estoque_v1($_POST);
        } elseif ($acao === 'excluir_devolucao') {
            $ok = vn_excluir_devolucao_estoque_v1((int)($_POST['devolucao_id'] ?? 0));
        }
    }
    $catalogo = vn_catalogo_orcamento_mobile_v6(true);
} catch (Throwable $e) { $erro = $e->getMessage(); $catalogo = $catalogo ?? []; }

$devolucoes = [];
try { $devolucoes = vn_fetch_all('SELECT * FROM vn_estoque_devolucoes ORDER BY criado_em DESC, id DESC LIMIT 40'); } catch (Throwable $e) { $devolucoes = []; }

/* Filtros da lista de saídas: período (mês ou últimas 40), busca e página. */
$fMes = (string)($_GET['mes'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $fMes)) { $fMes = ''; }
$fQ = trim((string)($_GET['q'] ?? ''));
$fPag = max(1, (int)($_GET['pagina'] ?? 1));
$porPagS = 15;
$saidasRecentes = [];
try { $saidasRecentes = $fMes !== '' ? vn_estoque_saidas_mes_v1($fMes, 'material') : vn_estoque_saidas_recentes_v1(40, 'material'); } catch (Throwable $e) { $saidasRecentes = []; }
if ($fQ !== '') {
    $alvoQ = function_exists('mb_strtolower') ? mb_strtolower($fQ, 'UTF-8') : strtolower($fQ);
    $saidasRecentes = array_values(array_filter($saidasRecentes, static function ($s) use ($alvoQ) {
        $txt = ($s['item_nome'] ?? '') . ' ' . ($s['responsavel'] ?? '') . ' ' . ($s['cliente_nome'] ?? '') . ' ' . ($s['motivo'] ?? '');
        $txt = function_exists('mb_strtolower') ? mb_strtolower($txt, 'UTF-8') : strtolower($txt);
        return strpos($txt, $alvoQ) !== false;
    }));
}
$totalSaidas = count($saidasRecentes);
$paginasS = max(1, (int)ceil($totalSaidas / $porPagS));
$fPag = min($fPag, $paginasS);
$saidasRecentes = array_slice($saidasRecentes, ($fPag - 1) * $porPagS, $porPagS);
function saidas_url(array $extra = []): string {
    $m = array_merge(['mes' => $_GET['mes'] ?? '', 'q' => $_GET['q'] ?? '', 'pagina' => $_GET['pagina'] ?? ''], $extra);
    foreach ($m as $k => $v) { if ($v === '' || $v === null || ($k === 'pagina' && (int)$v <= 1)) unset($m[$k]); }
    return 'saidas-estoque.php' . ($m ? '?' . http_build_query($m) : '') . '#ultimas-saidas';
}
$responsaveis = [];
try { $responsaveis = vn_responsaveis_lista_v1(); } catch (Throwable $e) { $responsaveis = []; }
$clientesLista = [];
try { $clientesLista = vn_clientes_form(); } catch (Throwable $e) { $clientesLista = []; }

/* Separação por categoria (igual à tela de Pedido) — só itens físicos com estoque */
$grupos = [
    'material'      => ['titulo' => 'Materiais', 'itens' => []],
    'equipamento'   => ['titulo' => 'Equipamentos', 'itens' => []],
    'outro'         => ['titulo' => 'Outros itens', 'itens' => []],
    'produto_usado' => ['titulo' => 'Produtos usados', 'itens' => []],
];
$itensSaida = [];
foreach ($catalogo as $it) {
    $tipo = (string)($it['tipo'] ?? 'material');
    if (vn_is_tipo_ferramenta($tipo)) continue;                 // ferramentas têm página própria
    if (in_array($tipo, ['servico', 'mao_obra'], true)) continue; // serviços não dão baixa de estoque
    if ((string)($it['origem'] ?? 'mobile') !== 'mobile') continue;
    if (!isset($grupos[$tipo])) $tipo = 'material';
    $grupos[$tipo]['itens'][] = $it;
    $itensSaida[] = $it;
}
$totalItens = count($itensSaida);
$catalogoJson = json_encode($itensSaida, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$respAtual = (string)($VN_USUARIO_NOME ?? '');

vn_admin_start('Saídas de estoque', 'saidas');
vn_alerts($ok, $erro);
?>
<style>
/* ---- Modal de seleção (igual ao "Adicionar peça" do Pedido) ---- */
.ab-add-modal{
    width:100vw;max-width:100vw;min-width:100vw;
    height:100vh;height:100dvh;max-height:none;min-height:100vh;min-height:100dvh;
    border:0;border-radius:0;outline:none;background:var(--surface);color:var(--text);
    padding:0;margin:0;position:fixed;left:0;top:0;right:0;bottom:0;
    display:none;flex-direction:column;box-sizing:border-box;
}
.ab-add-modal[open]{display:flex}
.ab-add-modal::backdrop{background:rgba(25,28,29,.55)}
@media(min-width:1000px){
    .ab-add-modal{width:600px;max-width:92vw;min-width:0;height:auto;min-height:0;max-height:90vh;
        border-radius:14px;left:50%;top:50%;right:auto;bottom:auto;transform:translate(-50%,-50%);box-shadow:var(--shadow)}
}
.ab-add-head{display:flex;align-items:center;gap:8px;padding:14px 14px 12px;border-bottom:1px solid var(--line);flex-shrink:0}
.ab-add-head button{border:0;background:transparent;color:var(--primary-fg);font-size:1.6rem;line-height:1;cursor:pointer;width:40px;height:40px;border-radius:10px}
.ab-add-head button:hover{background:var(--primary-softer)}
.ab-add-head h4{flex:1;margin:0;text-align:center;font-size:1.05rem;font-weight:800;color:var(--primary-fg)}
.ab-add-head .sp{width:40px}
.ab-add-body{flex:1 1 auto;min-height:0;overflow-y:auto;-webkit-overflow-scrolling:touch;padding:14px 16px}
.ab-add-search{display:flex;align-items:center;gap:10px;background:var(--surface-2);border:1px solid var(--line);border-radius:10px;padding:0 14px;margin-bottom:12px}
.ab-add-search .material-symbols-outlined{color:var(--muted);font-size:1.2rem}
.ab-add-search input{flex:1;border:0;background:transparent;min-height:48px;font:inherit;font-size:16px;color:var(--text);outline:none}
.ab-grp-title{display:flex;align-items:center;gap:10px;margin:14px 0 10px;color:var(--primary-fg);font-weight:800;text-transform:uppercase;letter-spacing:.07em;font-size:.72rem}
.ab-grp-title::after{content:"";flex:1;height:1px;background:var(--line)}
.ab-sel-item{display:grid;grid-template-columns:auto 1fr auto;gap:4px 12px;align-items:start;
    background:var(--surface-2);border:1px solid var(--line);border-radius:12px;padding:12px;margin-bottom:10px;cursor:pointer;transition:.12s}
.ab-sel-item.marcado{border-color:var(--primary);background:var(--primary-softer)}
.ab-sel-item img{grid-row:span 2;width:62px;height:62px;border-radius:10px;object-fit:cover;background:var(--surface)}
.ab-sel-item .sem-img{grid-row:span 2;width:0}
.ab-sel-info strong{display:block;font-size:.95rem;color:var(--text);font-weight:800;line-height:1.3}
.ab-sel-info small{display:block;color:var(--muted);font-size:.8rem;font-weight:500;line-height:1.35;margin-top:2px}
.ab-sel-chk{width:26px;height:26px;border-radius:7px;border:2px solid var(--line-strong);background:var(--surface);display:inline-flex;align-items:center;justify-content:center;color:transparent;flex-shrink:0;font-size:1rem;font-weight:900}
.ab-sel-item.marcado .ab-sel-chk{background:var(--primary);border-color:var(--primary);color:#fff}
.ab-sel-preco{grid-column:2/4;display:flex;align-items:center;justify-content:space-between;gap:10px;border-top:1px dashed var(--line);padding-top:8px;margin-top:6px}
.ab-sel-preco small{color:var(--primary-fg);font-weight:700;font-size:.8rem}
.ab-sel-preco strong{color:var(--primary-ink);font-weight:800;font-size:.95rem}
.ab-add-foot{padding:12px 16px calc(12px + env(safe-area-inset-bottom,0px));border-top:1px solid var(--line);flex-shrink:0}
.ab-add-foot button{width:100%;min-height:52px;border:0;border-radius:12px;background:var(--primary);color:#fff;font:inherit;font-size:1rem;font-weight:800;cursor:pointer;transition:.15s}
.ab-add-foot button:disabled{background:var(--line-strong);color:var(--surface);cursor:not-allowed}
/* ---- Botão grande "Selecionar materiais" ---- */
.vn-saida-pick{display:flex;align-items:center;gap:12px;width:100%;min-height:64px;padding:14px 18px;border:1.5px dashed var(--primary);border-radius:var(--radius-lg);
    background:var(--primary-softer);color:var(--primary-fg);font:inherit;font-weight:800;font-size:1rem;cursor:pointer;transition:.15s;text-align:left}
.vn-saida-pick:hover{background:var(--primary-soft)}
.vn-saida-pick .material-symbols-outlined{font-size:1.6rem}
.vn-saida-pick small{display:block;font-weight:600;color:var(--text-soft);font-size:.82rem;margin-top:2px}
/* ---- Linhas dos itens selecionados ---- */
.vn-saida-lista{display:flex;flex-direction:column;gap:10px;margin:14px 0 4px}
.vn-saida-row{display:grid;grid-template-columns:1fr 130px auto;gap:10px;align-items:center;border:1px solid var(--line);border-radius:12px;background:var(--surface-2);padding:12px}
.vn-saida-row .nome{font-weight:800;color:var(--text);font-size:.96rem}
.vn-saida-row .est{display:block;color:var(--muted);font-size:.8rem;font-weight:600;margin-top:2px}
.vn-saida-row .qtd-field label{display:block;font-size:.7rem;color:var(--muted);font-weight:700;margin-bottom:3px}
.vn-saida-row .qtd-field input{width:100%;min-height:42px;padding:8px 10px;border:1px solid var(--line-strong);border-radius:10px;background:var(--surface);color:var(--text);font:inherit;font-size:.95rem;box-sizing:border-box}
.vn-saida-row .qtd-field input.over{border-color:#e0507a;color:#e0507a}
.vn-saida-del{width:42px;height:42px;border-radius:10px;border:1px solid #fca5a5;background:var(--danger-bg);color:var(--danger-text);font-weight:800;cursor:pointer;font-size:1.1rem}
.vn-saida-empty-rows{padding:18px;text-align:center;color:var(--muted);border:1px dashed var(--line);border-radius:12px;font-size:.92rem}
@media(max-width:560px){.vn-saida-row{grid-template-columns:1fr 100px auto}}
</style>

<section class="vn-page-head">
    <div>
        <span class="vn-kicker">Estoque</span>
        <h2>Saídas de estoque.</h2>
        <p>Selecione os materiais que saíram (igual ao Pedido), dê baixa no estoque e registre nos relatórios — sem precisar gerar orçamento. Se algum item voltar, use o botão "↩ Devolver" na saída: o estoque e a cobrança são atualizados juntos.</p>
    </div>
    <div class="vn-actions">
        <a class="vn-btn" href="estoque.php">Voltar ao inventário</a>
        <a class="vn-btn" href="saidas-dia.php">Saídas do dia (PDF)</a>
        <a class="vn-btn" href="cobrancas.php#material">Cobranças</a>
        <a class="vn-btn vn-btn-primary" href="orcamentos-dashboard.php">Ver no Financeiro</a>
    </div>
</section>

<section class="vn-panel vn-stock-saida">
    <div class="vn-panel-head"><div><h3>Registrar nova saída</h3><small>Escolha um ou vários itens do catálogo e informe a quantidade que saiu.</small></div></div>

    <?php if ($totalItens === 0): ?>
        <div class="vn-empty">Cadastre peças, equipamentos ou produtos usados no <a href="estoque.php">inventário</a> para registrar saídas.</div>
    <?php else: ?>
    <form method="post" class="vn-saida-form" id="saidaForm">
        <input type="hidden" name="acao" value="saida_estoque">
        <input type="hidden" name="saida_origem" value="mobile">

        <button type="button" class="vn-saida-pick" id="abrirSaidaModal">
            <span class="material-symbols-outlined">add_shopping_cart</span>
            <span>Selecionar materiais<small><?php echo (int)$totalItens; ?> itens disponíveis no catálogo</small></span>
        </button>

        <div class="vn-saida-lista" id="saidaLista"></div>
        <div class="vn-saida-empty-rows" id="saidaVazio">Nenhum item selecionado ainda. Toque em “Selecionar materiais”.</div>

        <div class="vn-form-grid" style="margin-top:16px">
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
            <div class="vn-field"><label>Cliente (condomínio)</label>
                <?php if (!empty($clientesLista)): ?>
                    <select name="saida_cliente_id">
                        <option value="0">— Sem cliente / uso interno —</option>
                        <?php foreach ($clientesLista as $c): ?>
                            <option value="<?php echo (int)$c['id']; ?>"><?php echo vn_h($c['nome']); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input name="saida_cliente_id" type="hidden" value="0">
                    <input disabled value="" placeholder="Cadastre clientes em Clientes para vincular">
                <?php endif; ?>
            </div>
            <div class="vn-field"><label>Data da saída</label><input type="date" name="saida_data" value="<?php echo vn_h(date('Y-m-d')); ?>" required><small class="vn-help">Hoje, ou o dia em que o material realmente saiu.</small></div>
            <div class="vn-field"><label>Motivo / observação</label><input name="saida_motivo" placeholder="Ex: instalação, uso interno, perda, troca..."></div>
            <div class="vn-field full"><button class="vn-btn vn-btn-primary" type="submit" id="saidaSubmit" disabled>Registrar saída</button></div>
        </div>
    </form>

    <!-- Modal de seleção (mesmo padrão do Pedido) -->
    <dialog class="ab-add-modal" id="saidaModal">
        <div class="ab-add-head">
            <button type="button" data-saida-close>‹</button>
            <h4>Selecionar materiais</h4>
            <span class="sp"></span>
        </div>
        <div class="ab-add-body">
            <div class="ab-add-search"><span class="material-symbols-outlined">search</span><input type="search" id="saidaBusca" placeholder="Buscar"></div>
            <?php $temAlgum = false; foreach ($grupos as $g): if (empty($g['itens'])) continue; $temAlgum = true; ?>
                <div data-cat-group>
                    <div class="ab-grp-title"><?php echo vn_h($g['titulo']); ?> · <?php echo count($g['itens']); ?></div>
                    <?php foreach ($g['itens'] as $c): ?>
                        <div class="ab-sel-item" data-sel-item data-id="<?php echo (int)$c['id']; ?>" data-origem="<?php echo vn_h($c['origem']); ?>" data-search="<?php echo vn_h(vn_lower(($c['nome'] ?? '') . ' ' . ($c['descricao'] ?? '') . ' ' . $g['titulo'])); ?>">
                            <?php if (!empty($c['foto_url'])): ?><img src="<?php echo vn_h($c['foto_url']); ?>" alt=""><?php else: ?><span class="sem-img"></span><?php endif; ?>
                            <div class="ab-sel-info">
                                <strong><?php echo vn_h($c['nome']); ?></strong>
                                <?php if (!empty($c['descricao']) && $c['descricao'] !== $c['nome']): ?><small><?php echo vn_h($c['descricao']); ?></small><?php endif; ?>
                                <small>Estoque: <?php echo number_format((float)($c['quantidade_estoque'] ?? 0), 2, ',', '.'); ?> <?php echo vn_h($c['unidade'] ?? 'un'); ?></small>
                            </div>
                            <span class="ab-sel-chk">✓</span>
                            <div class="ab-sel-preco"><small>Custo por <?php echo vn_h($c['unidade'] ?? 'unidade'); ?>:</small><strong><?php echo vn_money($c['custo_unitario'] ?? 0); ?></strong></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; if (!$temAlgum): ?>
                <div class="vn-empty">Nenhum item no catálogo.</div>
            <?php endif; ?>
        </div>
        <div class="ab-add-foot">
            <button type="button" id="saidaConfirmar" disabled>adicionar</button>
        </div>
    </dialog>
    <?php endif; ?>

    <div class="vn-items-title" style="margin-top:22px" id="ultimas-saidas"><?php echo $fMes !== '' ? 'Saídas de ' . vn_h(substr($fMes, 5, 2) . '/' . substr($fMes, 0, 4)) : 'Últimas saídas'; ?></div>
    <form class="vn-filter" method="get" action="saidas-estoque.php#ultimas-saidas" role="search" style="margin-bottom:6px">
        <?php echo vn_mes_picker_html($fMes, ['rotulo' => 'Período', 'todos' => true, 'todos_rotulo' => 'Últimas 40']); ?>
        <label style="flex:1;min-width:200px">Buscar
            <input type="search" name="q" value="<?php echo vn_h($fQ); ?>" placeholder="Item, responsável, cliente ou motivo">
        </label>
        <button class="vn-btn vn-btn-primary" type="submit"><span class="material-symbols-outlined" aria-hidden="true">search</span>Filtrar</button>
        <?php if ($fMes !== '' || $fQ !== ''): ?><a class="vn-btn" href="saidas-estoque.php#ultimas-saidas">Limpar</a><?php endif; ?>
        <a class="vn-btn" href="saidas-export.php?mes=<?php echo vn_h(rawurlencode($fMes)); ?>&amp;q=<?php echo vn_h(rawurlencode($fQ)); ?>"><span class="material-symbols-outlined" aria-hidden="true">download</span>Exportar CSV</a>
    </form>
    <?php if (!empty($saidasRecentes)): ?>
        <table class="vn-table">
            <thead><tr><th>Data</th><th>Item</th><th>Qtd</th><th>Responsável</th><th>Cliente / Motivo</th><th>Ações</th></tr></thead>
            <tbody>
            <?php foreach ($saidasRecentes as $s): ?>
                <tr>
                    <td data-label="Data"><?php echo vn_h(date('d/m/Y H:i', strtotime((string)($s['criado_em'] ?? 'now')))); ?></td>
                    <td data-label="Item"><?php echo vn_h($s['item_nome'] ?? ''); ?></td>
                    <td data-label="Qtd"><?php echo number_format((float)($s['quantidade'] ?? 0), 2, ',', '.'); ?></td>
                    <td data-label="Responsável"><?php echo vn_h(($s['responsavel'] ?? '') ?: '—'); ?></td>
                    <td data-label="Cliente / Motivo"><?php echo vn_h(vn_saida_cliente_texto($s) ?: '—'); ?></td>
                    <td data-label="Ações"><div class="vn-actions">
                        <?php if ((float)($s['quantidade'] ?? 0) > 0): ?>
                        <details class="vn-edit-box">
                            <summary class="vn-mini-btn">↩ Devolver</summary>
                            <form method="post" class="vn-form-grid" style="margin-top:10px">
                                <input type="hidden" name="acao" value="devolver_saida">
                                <input type="hidden" name="saida_id" value="<?php echo (int)$s['id']; ?>">
                                <div class="vn-field"><label>Qtd devolvida</label><input name="devolucao_quantidade" inputmode="decimal" value="<?php echo number_format((float)($s['quantidade'] ?? 0), 2, ',', '.'); ?>" required></div>
                                <div class="vn-field"><label>Motivo</label><input name="devolucao_motivo" placeholder="Ex: sobrou da instalação"></div>
                                <input type="hidden" name="devolucao_responsavel" value="<?php echo vn_h($respAtual); ?>">
                                <div class="vn-field full"><button class="vn-btn vn-btn-primary" type="submit">Registrar devolução</button></div>
                            </form>
                        </details>
                        <?php endif; ?>
                        <?php vn_render_saida_acoes_v1($s, $responsaveis, $clientesLista); ?>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($paginasS > 1): ?>
        <nav class="vn-pager" aria-label="Paginação das saídas">
            <span><?php echo $totalSaidas; ?> saída(s) · página <?php echo $fPag; ?> de <?php echo $paginasS; ?></span>
            <div class="vn-actions">
                <?php if ($fPag > 1): ?><a class="vn-mini-btn" href="<?php echo vn_h(saidas_url(['pagina' => $fPag - 1])); ?>">← Anterior</a><?php endif; ?>
                <?php if ($fPag < $paginasS): ?><a class="vn-mini-btn" href="<?php echo vn_h(saidas_url(['pagina' => $fPag + 1])); ?>">Próxima →</a><?php endif; ?>
            </div>
        </nav>
        <?php endif; ?>
    <?php else: ?>
        <div class="vn-empty"><?php echo ($fMes !== '' || $fQ !== '') ? 'Nenhuma saída encontrada com esses filtros.' : 'Nenhuma saída registrada ainda.'; ?></div>
    <?php endif; ?>
</section>

<section class="vn-panel">
    <div class="vn-panel-head"><div><h3>Devoluções (itens que voltaram)</h3><small>Use o botão "↩ Devolver" em uma saída para registrar o que voltou. A quantidade retorna ao estoque e, se a saída já tinha cobrança gerada, a cobrança é atualizada junto.</small></div></div>
    <?php if (empty($devolucoes)): ?>
        <div class="vn-empty">Nenhuma devolução registrada ainda.</div>
    <?php else: ?>
        <table class="vn-table">
            <thead><tr><th>Data</th><th>Item</th><th>Qtd devolvida</th><th>Responsável</th><th>Motivo</th><th>Ações</th></tr></thead>
            <tbody>
            <?php foreach ($devolucoes as $d): ?>
                <tr>
                    <td data-label="Data"><?php echo vn_h(date('d/m/Y H:i', strtotime((string)($d['criado_em'] ?? 'now')))); ?></td>
                    <td data-label="Item"><strong><?php echo vn_h($d['item_nome']); ?></strong></td>
                    <td data-label="Qtd devolvida"><?php echo number_format((float)($d['quantidade'] ?? 0), 2, ',', '.'); ?></td>
                    <td data-label="Responsável"><?php echo vn_h(($d['responsavel'] ?? '') ?: '—'); ?></td>
                    <td data-label="Motivo"><?php echo vn_h(($d['motivo'] ?? '') ?: '—'); ?></td>
                    <td data-label="Ações">
                        <form method="post" data-confirm="Desfazer esta devolução? O item sai do estoque de novo e a saída/cobrança voltam ao que eram." data-danger>
                            <input type="hidden" name="acao" value="excluir_devolucao">
                            <input type="hidden" name="devolucao_id" value="<?php echo (int)$d['id']; ?>">
                            <button class="vn-mini-btn vn-btn-danger" type="submit">Desfazer</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<script>
(function(){
    var ITENS = <?php echo $catalogoJson ?: '[]'; ?>;
    var modal = document.getElementById('saidaModal');
    var lista = document.getElementById('saidaLista');
    if (!modal || !lista) return;

    var abrir = document.getElementById('abrirSaidaModal');
    var fechar = modal.querySelector('[data-saida-close]');
    var confirmar = document.getElementById('saidaConfirmar');
    var busca = document.getElementById('saidaBusca');
    var vazio = document.getElementById('saidaVazio');
    var submit = document.getElementById('saidaSubmit');

    function fmt(n){ return Number(n||0).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2}); }
    function selCount(){ return modal.querySelectorAll('[data-sel-item].marcado').length; }
    function syncFoot(){
        var n = selCount();
        confirmar.textContent = n>0 ? ('adicionar ('+n+')') : 'adicionar';
        confirmar.disabled = n===0;
    }
    function syncForm(){
        var rows = lista.querySelectorAll('.vn-saida-row').length;
        vazio.style.display = rows ? 'none' : '';
        if (submit) submit.disabled = rows===0;
    }

    function abreModal(){ if(modal.showModal) modal.showModal(); else modal.setAttribute('open','open'); }
    abrir && abrir.addEventListener('click', abreModal);
    fechar && fechar.addEventListener('click', function(){ modal.close(); });
    modal.addEventListener('click', function(ev){ if(ev.target===modal) modal.close(); });

    /* Marca itens já adicionados ao abrir, para não duplicar */
    function jaAdicionado(id){ return !!lista.querySelector('.vn-saida-row[data-id="'+id+'"]'); }

    modal.querySelectorAll('[data-sel-item]').forEach(function(card){
        card.addEventListener('click', function(){ card.classList.toggle('marcado'); syncFoot(); });
    });

    busca && busca.addEventListener('input', function(ev){
        var q = (ev.target.value||'').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,'').trim();
        modal.querySelectorAll('[data-sel-item]').forEach(function(card){
            var hay = (card.dataset.search||'').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,'');
            card.style.display = (q!=='' && !hay.includes(q)) ? 'none' : '';
        });
        modal.querySelectorAll('[data-cat-group]').forEach(function(g){
            var vis = [].slice.call(g.querySelectorAll('[data-sel-item]')).some(function(c){ return c.style.display!=='none'; });
            g.style.display = vis ? '' : 'none';
        });
    });

    function addRow(it){
        if (jaAdicionado(it.id)) return;
        var row = document.createElement('div');
        row.className = 'vn-saida-row';
        row.setAttribute('data-id', it.id);
        var est = Number(it.quantidade_estoque||0);
        row.innerHTML =
            '<div><span class="nome"></span><span class="est"></span>'+
            '<input type="hidden" name="saida_catalogo_id[]" value="'+it.id+'"></div>'+
            '<div class="qtd-field"><label>Quantidade</label>'+
            '<input class="qtd" name="saida_quantidade[]" inputmode="decimal" value="1" max="'+est+'" required></div>'+
            '<button type="button" class="vn-saida-del" title="Remover">×</button>';
        row.querySelector('.nome').textContent = it.nome || 'Item';
        row.querySelector('.est').textContent = 'Estoque: ' + fmt(est) + ' ' + (it.unidade||'un');
        lista.appendChild(row);
        var qtd = row.querySelector('.qtd');
        qtd.addEventListener('input', function(){
            var v = Number(String(qtd.value).replace(',','.'))||0;
            qtd.classList.toggle('over', v>est);
        });
        row.querySelector('.vn-saida-del').addEventListener('click', function(){ row.remove(); syncForm(); });
    }

    confirmar && confirmar.addEventListener('click', function(){
        modal.querySelectorAll('[data-sel-item].marcado').forEach(function(card){
            var it = ITENS.find(function(x){ return String(x.id)===String(card.dataset.id) && String(x.origem)===String(card.dataset.origem); });
            if (it) addRow(it);
            card.classList.remove('marcado');
        });
        syncFoot(); syncForm(); modal.close();
    });

    var form = document.getElementById('saidaForm');
    form && form.addEventListener('submit', function(e){
        if (!lista.querySelectorAll('.vn-saida-row').length){ e.preventDefault(); alert('Selecione ao menos um item para dar saída.'); }
    });

    syncForm();
})();
</script>
<?php vn_admin_end('saidas'); ?>
