<?php
require_once __DIR__ . '/_vn_orcamento_layout.php';
$ok = null; $erro = '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $acao = (string)($_POST['acao'] ?? '');
        if ($acao === 'salvar_catalogo') {
            $foto = vn_catalog_upload('catalogo_foto');
            if ($foto !== '') $_POST['catalogo_foto_url'] = $foto;
            vn_salvar_catalogo_orcamento_mobile_v6($_POST);
            $ok = 'Item cadastrado com sucesso.';
        } elseif ($acao === 'editar_catalogo') {
            $foto = vn_catalog_upload('catalogo_foto');
            if ($foto !== '') $_POST['catalogo_foto_url'] = $foto;
            vn_atualizar_catalogo_item_v6($_POST);
            $ok = 'Item atualizado com sucesso.';
        } elseif ($acao === 'excluir_catalogo') {
            $id = (int)($_POST['catalogo_id'] ?? 0);
            $origem = (string)($_POST['catalogo_origem'] ?? 'mobile');
            if ($id <= 0) throw new Exception('Item inválido.');

            if ($origem === 'mobile') {
                vn_exec('DELETE FROM vn_mobile_orcamento_catalogo WHERE id = ? LIMIT 1', [$id]);
            } elseif ($origem === 'materiais_orcamento' && vn_table_exists('materiais_orcamento') && vn_column_exists('materiais_orcamento', 'id')) {
                vn_exec('DELETE FROM materiais_orcamento WHERE id = ? LIMIT 1', [$id]);
            } else {
                throw new Exception('Não foi possível excluir este item pela origem informada.');
            }
            $ok = 'Item excluído com sucesso.';
        } elseif ($acao === 'entrada_estoque') {
            $ok = vn_entrada_estoque_v1((int)($_POST['entrada_id'] ?? 0), $_POST['entrada_quantidade'] ?? 0);
        }
    }
    $catalogo = vn_catalogo_orcamento_mobile_v6(true);
} catch (Throwable $e) { $erro = $e->getMessage(); $catalogo = []; }

$servicos = [];
$pecas = [];
$serralheria = [];   // peças e equipamentos da linha de serralheria (o orçamento de serralheria usa só estes)
$usados = [];
foreach ($catalogo as $it) {
    $tipo = (string)($it['tipo'] ?? 'material');
    if (vn_is_tipo_ferramenta($tipo)) continue; // ferramentas/outros têm página própria (ferramentas.php)
    if ($tipo === 'produto_usado') $usados[] = $it;
    elseif (in_array($tipo, ['servico', 'mao_obra'], true)) $servicos[] = $it;
    elseif ((string)($it['linha'] ?? 'cftv') === 'serralheria') $serralheria[] = $it;
    else $pecas[] = $it;
}

/* Escolha da linha do item: peça = CFTV ou Serralheria; serviço também pode valer para as duas ("geral"). */
function vn_linha_select_v1(bool $servico, string $atual, string $name = 'catalogo_linha'): string {
    $ops = $servico ? ['geral' => 'CFTV e serralheria', 'cftv' => 'Só CFTV', 'serralheria' => 'Só serralheria'] : ['cftv' => 'CFTV', 'serralheria' => 'Serralheria'];
    if (!isset($ops[$atual])) $atual = $servico ? 'geral' : 'cftv';
    $h = '<select name="' . vn_h($name) . '">';
    foreach ($ops as $k => $rot) $h .= '<option value="' . vn_h($k) . '"' . ($k === $atual ? ' selected' : '') . '>' . vn_h($rot) . '</option>';
    return $h . '</select>';
}

function vn_render_estoque_grupo_v9(string $titulo, string $subtitulo, array $itens): void { ?>
    <section class="vn-panel vn-stock-section">
        <div class="vn-panel-head">
            <div>
                <h3><?php echo vn_h($titulo); ?></h3>
                <small><?php echo vn_h($subtitulo); ?> · <?php echo count($itens); ?> cadastrado(s)</small>
            </div>
        </div>
        <?php if (empty($itens)): ?>
            <div class="vn-empty">Nenhum item nesta categoria.</div>
        <?php else: ?>
            <div class="vn-catalog-grid">
                <?php foreach ($itens as $it):
                    $tipoItem = (string)($it['tipo'] ?? 'material');
                    $isServico = in_array($tipoItem, ['servico', 'mao_obra'], true);
                    $custo = (float)($it['custo_unitario'] ?? 0);
                    $venda = (float)($it['valor_unitario'] ?? 0);
                    $lucro = $venda - $custo;
                    $qtdEstoque = (float)($it['quantidade_estoque'] ?? 0);
                    $markup = (!$isServico && $custo > 0) ? (($venda / $custo) - 1) * 100 : 0;
                    $margemLucro = (!$isServico && $venda > 0) ? (($venda - $custo) / $venda) * 100 : 0;
                ?>
                    <article class="vn-catalog-card">
                        <?php if (!empty($it['foto_url'])): ?>
                            <img src="<?php echo vn_h($it['foto_url']); ?>" alt="<?php echo vn_h($it['nome']); ?>">
                        <?php endif; ?>
                        <h4><?php echo vn_h($it['nome']); ?></h4>
                        <?php $linhaIt = (string)($it['linha'] ?? 'cftv'); if ($linhaIt === 'serralheria' || ($linhaIt === 'cftv' && $isServico)): ?><span class="vn-linha-chip <?php echo vn_h($linhaIt); ?>"><?php echo $linhaIt === 'serralheria' ? 'Serralheria' : 'CFTV'; ?></span><?php elseif ($isServico && $linhaIt === 'geral'): ?><span class="vn-linha-chip geral">CFTV e serralheria</span><?php endif; ?>
                        <p><?php echo vn_h($it['descricao']); ?></p>
                        <div class="vn-catalog-meta">
                            <span><?php echo vn_h(vn_orc_tipo_label_v4($tipoItem)); ?></span>
                            <span><?php echo vn_money($venda); ?></span>
                        </div>
                        <div class="vn-catalog-mini">
                            <span>Custo: <?php echo vn_money($custo); ?></span>
                            <span>Markup: <?php echo number_format($markup, 2, ',', '.'); ?>%</span>
                            <span>Margem lucro: <?php echo number_format($margemLucro, 2, ',', '.'); ?>%</span>
                            <span>Lucro: <?php echo vn_money($lucro); ?></span>
                            <span>Estoque: <?php echo number_format($qtdEstoque, 2, ',', '.'); ?> <?php echo vn_h($it['unidade'] ?? 'un'); ?></span>
                        </div>

                        <?php if (!$isServico && (string)($it['origem'] ?? 'mobile') === 'mobile'): ?>
                        <div class="vn-actions" style="margin:10px 0 0"><?php vn_entrada_btn_v1((int)$it['id'], (string)$it['nome'], (string)($it['unidade'] ?? 'un')); ?></div>
                        <?php endif; ?>

                        <details class="vn-edit-box">
                            <summary class="vn-mini-btn">Alterar</summary>
                            <form method="post" enctype="multipart/form-data" class="vn-form-grid js-calc-catalogo" data-auto-markup="<?php echo $isServico ? '0' : '1'; ?>">
                                <input type="hidden" name="acao" value="editar_catalogo">
                                <input type="hidden" name="catalogo_id" value="<?php echo (int)$it['id']; ?>">
                                <input type="hidden" name="catalogo_origem" value="<?php echo vn_h($it['origem']); ?>">
                                <input type="hidden" name="catalogo_auto_calculo" value="<?php echo $isServico ? '0' : '1'; ?>">
                                <input type="hidden" name="catalogo_tipo" value="<?php echo vn_h($tipoItem); ?>">
                                <div class="vn-field"><label>Nome</label><input name="catalogo_nome" value="<?php echo vn_h($it['nome']); ?>" required></div>
                                <div class="vn-field"><label>Unidade</label><input name="catalogo_unidade" value="<?php echo vn_h($it['unidade'] ?? 'un'); ?>"></div>
                                <?php if (function_exists('vn_serr_calculo_select')): ?><div class="vn-field"><label>Como cobrar</label><?php echo vn_serr_calculo_select((string)($it['calculo'] ?? '')); ?><small class="vn-help">Por medida (m², metro, degrau, hora, kg): no orçamento a tela pede a medida e calcula.</small></div><?php endif; ?>
                                <div class="vn-field"><label>Qtd no estoque</label><input name="catalogo_quantidade_estoque" inputmode="decimal" value="<?php echo number_format($qtdEstoque, 2, ',', '.'); ?>"></div>
                                <?php if (!$isServico): ?><div class="vn-field"><label>Estoque mínimo</label><input name="catalogo_estoque_minimo" inputmode="decimal" placeholder="padrão" value="<?php echo isset($it['estoque_minimo']) && $it['estoque_minimo'] !== null && $it['estoque_minimo'] !== '' ? number_format((float)$it['estoque_minimo'], 2, ',', '.') : ''; ?>"><small class="vn-help">Avisa em "Faltando" quando ficar abaixo. Vazio = limite geral.</small></div><?php endif; ?>
                                <div class="vn-field"><label>Custo</label><input class="js-custo" name="catalogo_custo" inputmode="decimal" value="<?php echo number_format($custo, 2, ',', '.'); ?>"></div>
                                <div class="vn-field"><label><?php echo $isServico ? 'Lucro %' : 'Lucro %' ; ?></label><input class="js-margem" name="catalogo_margem" inputmode="decimal" value="<?php echo number_format($isServico ? 0 : $margemLucro, 2, ',', '.'); ?>" <?php echo $isServico ? 'readonly' : ''; ?>></div>
                                <div class="vn-field"><label>Markup automático</label><input class="js-markup" readonly value="<?php echo number_format($markup, 2, ',', '.'); ?>%"></div>
                                <div class="vn-field"><label><?php echo $isServico ? 'Valor venda' : 'Valor venda automático'; ?></label><input class="js-venda" name="catalogo_valor" inputmode="decimal" value="<?php echo number_format($venda, 2, ',', '.'); ?>" <?php echo $isServico ? '' : 'readonly'; ?>></div>
                                <div class="vn-field"><label>Linha</label><?php echo vn_linha_select_v1($isServico, (string)($it['linha'] ?? ($isServico ? 'geral' : 'cftv'))); ?></div>
                                <div class="vn-field"><label>Trocar foto</label><input type="file" name="catalogo_foto" accept="image/*"></div>
                                <div class="vn-field full"><label>Descrição</label><textarea name="catalogo_descricao"><?php echo vn_h($it['descricao']); ?></textarea></div>
                                <div class="vn-field full"><button class="vn-btn vn-btn-primary" type="submit">Salvar alteração</button></div>
                            </form>
                        </details>

                        <form method="post" class="vn-actions" data-confirm="Excluir este item definitivamente?" data-danger style="margin-top:12px">
                            <input type="hidden" name="acao" value="excluir_catalogo">
                            <input type="hidden" name="catalogo_id" value="<?php echo (int)$it['id']; ?>">
                            <input type="hidden" name="catalogo_origem" value="<?php echo vn_h($it['origem']); ?>">
                            <button class="vn-mini-btn vn-btn-danger" type="submit">Excluir</button>
                        </form>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
<?php }

function vn_render_form_catalogo_v9(string $titulo, string $subtitulo, string $tipo, string $nomeLabel, string $nomePlaceholder, string $unidade, string $descLabel, string $linha = ''): void {
    $isServico = in_array($tipo, ['servico', 'mao_obra'], true);
    ?>
    <section class="vn-panel vn-stock-create-panel">
        <div class="vn-panel-head"><div><h3><?php echo vn_h($titulo); ?></h3><small><?php echo vn_h($subtitulo); ?></small></div></div>
        <form method="post" enctype="multipart/form-data" class="vn-form-grid js-calc-catalogo" data-auto-markup="<?php echo $isServico ? '0' : '1'; ?>">
            <input type="hidden" name="acao" value="salvar_catalogo">
            <input type="hidden" name="catalogo_auto_calculo" value="<?php echo $isServico ? '0' : '1'; ?>">
            <?php if ($linha !== ''): ?><input type="hidden" name="catalogo_linha" value="<?php echo vn_h($linha); ?>"><?php endif; ?>
            <div class="vn-field"><label>Tipo</label><select name="catalogo_tipo">
                <?php if ($tipo === 'fisico'): ?>
                    <option value="material">Peça / material</option><option value="equipamento">Equipamento</option><option value="outro">Outro item físico</option>
                <?php else: ?>
                    <option value="<?php echo vn_h($tipo); ?>"><?php echo vn_h(vn_orc_tipo_label_v4($tipo)); ?></option>
                <?php endif; ?>
            </select></div>
            <div class="vn-field"><label><?php echo vn_h($nomeLabel); ?></label><input name="catalogo_nome" required placeholder="<?php echo vn_h($nomePlaceholder); ?>"></div>
            <div class="vn-field"><label>Unidade</label><input name="catalogo_unidade" value="<?php echo vn_h($unidade); ?>"></div>
            <?php if (function_exists('vn_serr_calculo_select')): ?><div class="vn-field"><label>Como cobrar</label><?php echo vn_serr_calculo_select(''); ?><small class="vn-help">Por medida (m², metro, degrau, hora, kg): no orçamento a tela pede a medida e calcula.</small></div><?php endif; ?>
            <div class="vn-field"><label>Qtd no estoque</label><input name="catalogo_quantidade_estoque" inputmode="decimal" value="0,00" placeholder="0,00"></div>
            <?php if (!$isServico): ?><div class="vn-field"><label>Estoque mínimo</label><input name="catalogo_estoque_minimo" inputmode="decimal" placeholder="padrão"><small class="vn-help">Avisa em "Faltando" quando ficar abaixo. Vazio = limite geral.</small></div><?php endif; ?>
            <div class="vn-field"><label>Custo</label><input class="js-custo" name="catalogo_custo" inputmode="decimal" value="0,00" placeholder="0,00"></div>
            <div class="vn-field"><label>Lucro %</label><input class="js-margem" name="catalogo_margem" inputmode="decimal" value="<?php echo $isServico ? '0,00' : '30,00'; ?>" <?php echo $isServico ? 'readonly' : ''; ?>></div>
            <div class="vn-field"><label>Markup automático</label><input class="js-markup" readonly value="<?php echo $isServico ? '0,00%' : '42,86%'; ?>"></div>
            <div class="vn-field"><label><?php echo $isServico ? 'Valor venda' : 'Valor venda automático'; ?></label><input class="js-venda" name="catalogo_valor" inputmode="decimal" value="0,00" placeholder="0,00" <?php echo $isServico ? '' : 'readonly'; ?>></div>
            <?php if ($isServico && $linha === ''): ?><div class="vn-field"><label>Vale para</label><?php echo vn_linha_select_v1(true, 'geral'); ?></div><?php endif; ?>
            <div class="vn-field"><label>Foto</label><input type="file" name="catalogo_foto" accept="image/*"></div>
            <div class="vn-field full"><label><?php echo vn_h($descLabel); ?></label><textarea name="catalogo_descricao" placeholder="Descrição para o orçamento"></textarea></div>
            <div class="vn-field full"><button class="vn-btn vn-btn-primary" type="submit">Cadastrar</button></div>
        </form>
    </section>
<?php }

vn_admin_start('Estoque', 'estoque');
vn_alerts($ok, $erro);
?>
<style>
.vn-stock-tabs{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:18px}
.vn-stock-tab{padding:16px;border:1px solid var(--line);border-radius:var(--radius-lg);background:var(--surface);display:flex;align-items:center;justify-content:space-between;gap:12px;color:var(--text-soft);font-weight:700;box-shadow:var(--shadow-sm);transition:.15s}
.vn-stock-tab:hover{border-color:var(--primary);transform:translateY(-1px)}
.vn-stock-tab strong{display:block;color:var(--text);font-size:.98rem;font-weight:800}.vn-stock-tab span{color:var(--primary-fg);font-size:1.4rem;font-weight:800}
.vn-stock-section{scroll-margin-top:96px}
.vn-stock-create-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin-bottom:18px}
.vn-stock-create-panel .vn-panel-head{align-items:flex-start}.vn-stock-create-panel .vn-panel-head small{line-height:1.35}.vn-stock-create-panel .vn-btn{width:100%}
.vn-edit-box .vn-field.full{grid-column:1/-1}
@media(max-width:1100px){.vn-stock-create-grid{grid-template-columns:1fr}}
@media(max-width:1100px){.vn-stock-tabs{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:720px){.vn-stock-tabs{grid-template-columns:1fr}.vn-stock-tab{padding:14px}.vn-catalog-card p{min-height:auto}}
.vn-stock-search-wrap{position:relative;margin-bottom:20px}
.vn-stock-search{width:100%;padding:11px 44px 11px 16px;border:1px solid var(--line);border-radius:var(--radius-lg);background:var(--surface);color:var(--text);font-size:.97rem;box-shadow:var(--shadow-sm);transition:.15s;box-sizing:border-box}
.vn-stock-search:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px color-mix(in srgb,var(--primary) 20%,transparent)}
.vn-stock-search-icon{position:absolute;right:14px;top:50%;transform:translateY(-50%);pointer-events:none;font-size:1.1rem;color:var(--text-soft)}
.vn-stock-search-count{display:block;font-size:.83rem;color:var(--text-soft);margin-top:6px;padding-left:4px}
.vn-catalog-card[hidden]{display:none!important}
.vn-linha-chip{display:inline-block;align-self:flex-start;margin:0 0 6px;padding:2px 9px;border-radius:999px;font-size:.7rem;font-weight:800;letter-spacing:.03em;text-transform:uppercase;border:1px solid var(--line-strong);background:var(--surface-2);color:var(--text-soft)}
.vn-linha-chip.serralheria{background:var(--warn-bg);border-color:var(--warn-line);color:var(--warn-text)}
.vn-linha-chip.cftv{background:var(--primary-softer);border-color:var(--primary-soft);color:var(--primary-ink)}
.vn-stock-no-results{display:none;padding:24px;text-align:center;color:var(--text-soft);font-size:.95rem}
</style>
<section class="vn-page-head">
    <div>
        <span class="vn-kicker">Catálogo / estoque</span>
        <h2>Peças, usados e serviços separados.</h2>
        <p>Informe custo e quantidade no estoque. Você altera a porcentagem de lucro em qualquer produto, peça, equipamento ou usado; o sistema calcula o markup e o valor de venda automaticamente. Serviço continua manual.</p>
    </div>
    <a class="vn-btn vn-btn-primary" href="orcamento-novo.php">Usar no pedido</a>
</section>

<div class="vn-stock-tabs">
    <a class="vn-stock-tab" href="#pecas"><strong>Peças e equipamentos</strong><span><?php echo count($pecas); ?></span></a>
    <a class="vn-stock-tab" href="#serralheria"><strong>Peças de serralheria</strong><span><?php echo count($serralheria); ?></span></a>
    <a class="vn-stock-tab" href="#usados"><strong>Produtos usados</strong><span><?php echo count($usados); ?></span></a>
    <a class="vn-stock-tab" href="#servicos"><strong>Serviços e mão de obra</strong><span><?php echo count($servicos); ?></span></a>
</div>

<div class="vn-stock-search-wrap">
    <input type="search" id="vn-stock-search" class="vn-stock-search" placeholder="Pesquisar item por nome ou descrição…" autocomplete="off">
    <span class="vn-stock-search-icon">&#128269;</span>
    <span id="vn-stock-search-count" class="vn-stock-search-count" hidden></span>
</div>

<div class="vn-stock-create-grid">
    <?php vn_render_form_catalogo_v9('Cadastrar peça/equipamento', 'Materiais, produtos, fontes, câmeras, centrais e equipamentos novos (CFTV).', 'fisico', 'Nome da peça', 'Ex: Câmera, fonte, fechadura...', 'un', 'Descrição da peça', 'cftv'); ?>
    <?php vn_render_form_catalogo_v9('Cadastrar peça de serralheria', 'Tubos, chapas, perfis, ferragens, solda, tinta... Aparecem no orçamento de serralheria.', 'fisico', 'Nome da peça de serralheria', 'Ex: Metalon 30x30, chapa 2mm, dobradiça...', 'un', 'Descrição da peça', 'serralheria'); ?>
    <?php vn_render_form_catalogo_v9('Cadastrar produto usado', 'Aba separada para equipamentos usados, retirados, seminovos ou reaproveitados.', 'produto_usado', 'Nome do produto usado', 'Ex: Motor usado, central usada...', 'un', 'Estado/observação do produto usado'); ?>
    <?php vn_render_form_catalogo_v9('Cadastrar serviço', 'Instalação, configuração, visita técnica e mão de obra.', 'servico', 'Nome do serviço', 'Ex: Instalação, configuração...', 'serviço', 'Descrição do serviço'); ?>
</div>

<section class="vn-panel vn-stock-saida">
    <div class="vn-panel-head">
        <div><h3>Saída de estoque</h3><small>O registro de saídas agora tem página própria, com seleção de materiais igual à do Pedido.</small></div>
        <a class="vn-btn vn-btn-primary" href="saidas-estoque.php"><span class="material-symbols-outlined" style="font-size:1.1rem;vertical-align:-3px">output</span> Registrar saída</a>
    </div>
    <p style="color:var(--text-soft);line-height:1.6;margin:6px 0 0">Dê baixa em peças, equipamentos e produtos usados na página <a href="saidas-estoque.php">Saídas de estoque</a>. O resumo do mês continua disponível no <a href="orcamentos-dashboard.php">Financeiro</a>.</p>
</section>

<div id="pecas"><?php vn_render_estoque_grupo_v9('Peças e equipamentos', 'Materiais, produtos, equipamentos e outros itens físicos', $pecas); ?></div>
<div id="serralheria"><?php vn_render_estoque_grupo_v9('Peças de serralheria', 'Tubos, chapas, perfis, ferragens e o que mais o orçamento de serralheria usa', $serralheria); ?></div>
<div id="usados"><?php vn_render_estoque_grupo_v9('Produtos usados', 'Equipamentos usados, seminovos, retirados ou reaproveitados', $usados); ?></div>
<div id="servicos"><?php vn_render_estoque_grupo_v9('Serviços e mão de obra', 'Instalação, configuração, visita, mão de obra e serviços', $servicos); ?></div>
<script>
(function(){
  function parseBR(v){
    v = String(v || '').replace(/[^0-9,.-]/g,'').replace(/\./g,'').replace(',', '.');
    var n = parseFloat(v);
    return isNaN(n) ? 0 : n;
  }
  function moneyBR(n){
    return (Number(n || 0)).toLocaleString('pt-BR', {minimumFractionDigits:2, maximumFractionDigits:2});
  }
  function pctBR(n){
    return (Number(n || 0)).toLocaleString('pt-BR', {minimumFractionDigits:2, maximumFractionDigits:2}) + '%';
  }
  function calc(form){
    var auto = form.getAttribute('data-auto-markup') !== '0';
    var vendaEl = form.querySelector('.js-venda');
    var markupEl = form.querySelector('.js-markup');
    if (!auto) {
      if (markupEl && !markupEl.value) markupEl.value = '0,00%';
      if (vendaEl && !vendaEl.value) vendaEl.value = '0,00';
      return;
    }
    var custo = parseBR(form.querySelector('.js-custo')?.value);
    var margemEl = form.querySelector('.js-margem');
    var margem = parseBR(margemEl ? margemEl.value : 30);
    if (margem <= 0 || margem >= 100) {
      if (markupEl) markupEl.value = '0,00%';
      if (vendaEl) vendaEl.value = '0,00';
      return;
    }
    var markup = (margem / (100 - margem)) * 100;
    var venda = custo > 0 ? custo / (1 - (margem / 100)) : 0;
    if (vendaEl) vendaEl.value = moneyBR(venda);
    if (markupEl) markupEl.value = pctBR(markup);
  }
  // Pesquisa de estoque
  (function(){
    var input = document.getElementById('vn-stock-search');
    var countEl = document.getElementById('vn-stock-search-count');
    if (!input) return;
    input.addEventListener('input', function(){
      var q = input.value.trim().toLowerCase();
      var cards = document.querySelectorAll('.vn-catalog-card');
      var visible = 0;
      cards.forEach(function(card){
        var text = (card.querySelector('h4')?.textContent || '') + ' ' + (card.querySelector('p')?.textContent || '');
        if (!q || text.toLowerCase().includes(q)) {
          card.removeAttribute('hidden');
          visible++;
        } else {
          card.setAttribute('hidden', '');
        }
      });
      // Mostra/oculta seções vazias após filtragem
      document.querySelectorAll('.vn-stock-section').forEach(function(sec){
        var anyVisible = sec.querySelectorAll('.vn-catalog-card:not([hidden])').length > 0;
        var grid = sec.querySelector('.vn-catalog-grid');
        var empty = sec.querySelector('.vn-empty');
        var noRes = sec.querySelector('.vn-stock-no-results');
        if (q) {
          if (!noRes) {
            noRes = document.createElement('div');
            noRes.className = 'vn-stock-no-results';
            noRes.textContent = 'Nenhum resultado para "' + input.value.trim() + '" nesta categoria.';
            sec.appendChild(noRes);
          } else {
            noRes.textContent = 'Nenhum resultado para "' + input.value.trim() + '" nesta categoria.';
          }
          if (grid) grid.style.display = anyVisible ? '' : 'none';
          if (empty) empty.style.display = 'none';
          noRes.style.display = anyVisible ? 'none' : 'block';
        } else {
          if (grid) grid.style.display = '';
          if (empty) empty.style.display = '';
          if (noRes) noRes.style.display = 'none';
        }
      });
      if (q) {
        countEl.textContent = visible + (visible === 1 ? ' resultado encontrado' : ' resultados encontrados');
        countEl.removeAttribute('hidden');
      } else {
        countEl.setAttribute('hidden', '');
      }
    });
  })();

  document.querySelectorAll('.js-calc-catalogo').forEach(function(form){
    ['input','change','blur'].forEach(function(ev){
      form.addEventListener(ev, function(e){
        if (e.target.matches('.js-custo, .js-margem')) calc(form);
      }, true);
    });
    calc(form);
  });
})();
</script>
<?php vn_render_entrada_modal_v1(); ?>
<?php vn_admin_end('estoque'); ?>
