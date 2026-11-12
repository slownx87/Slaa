<?php
/*
    VN System - Preços da serralheria
    Serralheiro cobra pela medida: portão e grade por m², corrimão e guarda-corpo por metro, escada por degrau,
    serviço por hora ou por unidade. Esta tela guarda a tabela de preços que a calculadora do orçamento usa
    ("Calcular por medida" no pedido de serralheria):
      - estruturas e serviços, cada um com o preço da sua medida (e, se quiser, a sua própria mão de obra);
      - mão de obra por medida (m², metro, hora, degrau, unidade, kg);
      - níveis da estrutura (Leve, Médio, Reforçado) e perfis de mão de obra (Padrão, Urgente, Obra grande, Cliente VIP),
        em porcentagem (negativa = desconto);
      - reajuste de todos os preços de uma vez (o preço do aço muda).
    Tudo se edita em janelas na própria tela. Só administrador abre. Dados e conta: _vn_serralheria_lib.php.
*/
require_once __DIR__ . '/_vn_orcamento_layout.php';

if (strtolower(trim((string)($_SESSION['cargo'] ?? ''))) !== 'admin') {
    http_response_code(403);
    echo 'Acesso restrito ao administrador.';
    exit;
}
if (!function_exists('vn_serr_config')) {
    http_response_code(500);
    echo 'Faltou o arquivo _vn_serralheria_lib.php. Envie a atualização completa de novo.';
    exit;
}

/* ------------------------------------------------------------ salvar (sempre volta para esta tela, no mesmo trecho) */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipoAviso = 'ok'; $ancora = 'estruturas'; $msg = '';
    try {
        $acao = (string)($_POST['acao'] ?? '');
        if ($acao === 'estrutura_salvar') {
            $idEst = (int)($_POST['id'] ?? 0);
            vn_serr_estrutura_salvar($idEst, $_POST);
            $msg = $idEst > 0 ? 'Estrutura atualizada.' : 'Estrutura adicionada à tabela.';
        } elseif ($acao === 'estrutura_excluir') {
            $idEst = (int)($_POST['id'] ?? 0);
            $antiga = vn_fetch_one('SELECT nome FROM vn_serr_estruturas WHERE id = ? LIMIT 1', [$idEst]);
            if (!$antiga) throw new InvalidArgumentException('Estrutura não encontrada.');
            vn_serr_estrutura_excluir($idEst);
            $msg = '"' . $antiga['nome'] . '" saiu da tabela. Os pedidos que já usam essa estrutura não mudam.';
        } elseif ($acao === 'config_salvar') {
            $chave = (string)($_POST['chave'] ?? '');
            vn_serr_config_salvar($chave, $_POST['valor'] ?? '');
            $ancora = strpos($chave, 'mo_') === 0 ? 'mao-de-obra' : (strpos($chave, 'nivel_') === 0 ? 'niveis' : 'perfis');
            $msg = 'Valor salvo.';
        } elseif ($acao === 'reajustar') {
            $pct = vn_serr_num($_POST['pct'] ?? 0);
            $n = vn_serr_reajustar($pct, !empty($_POST['incluir_mao']));
            $msg = 'Preços reajustados em ' . ($pct > 0 ? '+' : '') . str_replace('.', ',', (string)round($pct, 2)) . '% (' . $n . ' estruturas' . (!empty($_POST['incluir_mao']) ? ' e a mão de obra' : '') . ').';
        } elseif ($acao === 'conferido') {
            vn_serr_marcar_conferido();
            $msg = 'Tabela marcada como conferida.';
        } else {
            throw new InvalidArgumentException('Ação inválida. Recarregue a página e tente de novo.');
        }
    } catch (Throwable $e) {
        $tipoAviso = 'err';
        $msg = $e instanceof InvalidArgumentException ? $e->getMessage() : vn_public_error($e, 'serralheria-precos');
    }
    $_SESSION['sp_flash'] = ['tipo' => $tipoAviso, 'msg' => $msg];
    header('Location: serralheria-precos.php#' . $ancora);
    exit;
}

$flash = $_SESSION['sp_flash'] ?? null;
unset($_SESSION['sp_flash']);

$cfg = vn_serr_config();
$grupos = vn_serr_agrupadas(true);
$unidades = vn_serr_unidades();
$categorias = array_keys($grupos);
$dias = null;
if (!empty($cfg['atualizado_em'])) { $t = strtotime((string)$cfg['atualizado_em']); if ($t) $dias = (int)floor((time() - $t) / 86400); }
$totalEst = 0; foreach ($grupos as $g) $totalEst += count($g);

/* "+20%", "−10%", "sem ajuste" */
function sp_pct(float $v): string {
    if (abs($v) < 0.0001) return 'sem ajuste';
    return ($v > 0 ? '+' : '−') . rtrim(rtrim(number_format(abs($v), 2, ',', ''), '0'), ',') . '%';
}
function sp_num_campo(float $v): string { return number_format($v, 2, ',', '.'); }
function sp_json($d): string { return vn_h(json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); }
function sp_ico(string $n): string { return '<span class="material-symbols-outlined" aria-hidden="true">' . $n . '</span>'; }

/* linhas de configuração: chave, título, explicação */
$linhasMao = [
    ['mo_m2', 'Por m²', 'Portões, grades de janela e tudo que se cobra pela área.'],
    ['mo_m', 'Por metro linear', 'Corrimão, guarda-corpo, gradil, cerca.'],
    ['mo_h', 'Por hora', 'Serviço avulso, manutenção, ajuste.'],
    ['mo_degrau', 'Por degrau', 'Escadas.'],
    ['mo_un', 'Por unidade', 'Serviço cobrado por peça.'],
    ['mo_kg', 'Por kg', 'Estrutura metálica pesada.'],
];
$linhasNivel = [['nivel_leve', 'Leve', 'Estrutura mais simples, material mais fino.'], ['nivel_medio', 'Médio', 'O padrão: o preço da tabela.'], ['nivel_reforcado', 'Reforçado', 'Material mais grosso, reforços extras.']];
$linhasPerfil = [['perfil_urgente', 'Urgente', 'Cliente com pressa: costuma ter acréscimo.'], ['perfil_obra_grande', 'Obra grande', 'Muito serviço de uma vez: costuma ter desconto.'], ['perfil_vip', 'Cliente VIP', 'Cliente especial: costuma ter desconto.']];

vn_ui_shell_start('Preços da serralheria', 'serr-precos', [
    'css' => ['assets/css/vn-serr-precos.css'],
    'js'  => ['assets/js/vn-serr-precos.js'],
    'sub' => 'Quanto você cobra por medida',
]);
?>
<div class="sp" id="spRoot">

<?php if ($flash && !empty($flash['msg'])): $ft = in_array($flash['tipo'] ?? '', ['ok', 'warn', 'err'], true) ? $flash['tipo'] : 'ok'; ?>
    <div class="vn-alert <?php echo $ft; ?>" role="status"><?php echo vn_h($flash['msg']); ?></div>
<?php endif; ?>

<section class="vn-page-head">
    <div>
        <span class="vn-kicker">Serralheria</span>
        <h2>Preços da serralheria.</h2>
        <p>Serralheiro cobra pela medida: portão e grade por m², corrimão e guarda-corpo por metro, escada por degrau, serviço por hora. É esta tabela que a calculadora do orçamento ("Calcular por medida") usa.</p>
    </div>
</section>

<?php if (!$cfg['revisado']): ?>
    <div class="vn-alert warn sp-aviso" role="note">
        <span>Estes preços são valores iniciais de exemplo. Ajuste cada um para o seu custo (o preço do aço muda) e toque em "Conferi os preços" para tirar este aviso.</span>
        <form method="post"><input type="hidden" name="acao" value="conferido"><button type="submit" class="sp-btn">Conferi os preços</button></form>
    </div>
<?php elseif ($dias !== null && $dias >= 30): ?>
    <div class="vn-alert warn sp-aviso" role="note">
        <span>A tabela foi conferida há <?php echo (int)$dias; ?> dias. O preço do aço varia: vale revisar ou reajustar tudo de uma vez.</span>
        <form method="post"><input type="hidden" name="acao" value="conferido"><button type="submit" class="sp-btn">Está tudo certo</button></form>
    </div>
<?php endif; ?>

<div class="sp-acoes">
    <button type="button" class="sp-btn pri" data-sp-nova><?php echo sp_ico('add'); ?>Nova estrutura</button>
    <button type="button" class="sp-btn" data-sp-reajuste><?php echo sp_ico('tune'); ?>Reajustar todos os preços (%)</button>
    <?php if (!empty($cfg['atualizado_em'])): ?><span class="sp-quando">Atualizada em <?php echo vn_h(vn_date_br(substr((string)$cfg['atualizado_em'], 0, 10))); ?></span><?php endif; ?>
</div>

<!-- ============ estruturas e serviços ============ -->
<section class="vn-panel sp-sec" id="estruturas" aria-labelledby="spT1">
    <h3 id="spT1">Estruturas e serviços</h3>
    <p class="vn-help">O preço de cada uma por medida. Toque em Editar para mudar o preço, a medida ou o nome; "Nova estrutura" cadastra outra.</p>
    <?php if (!$grupos): ?>
        <div class="sp-vazio">Nenhuma estrutura cadastrada. Toque em "Nova estrutura".</div>
    <?php endif; ?>
    <?php foreach ($grupos as $cat => $lista): ?>
        <h4 class="sp-cat"><?php echo vn_h($cat); ?> <small><?php echo count($lista); ?></small></h4>
        <div class="sp-lista">
        <?php foreach ($lista as $e): $un = (string)$e['unidade']; $sem = (float)$e['preco'] <= 0; ?>
            <div class="sp-row">
                <div class="sp-info">
                    <strong><?php echo vn_h($e['nome']); ?></strong>
                    <small><?php echo vn_h(vn_serr_unidade_por($un)); ?><?php if ($e['mao_obra'] !== null): ?> · mão de obra própria <?php echo vn_h(vn_money($e['mao_obra'])); ?><?php endif; ?></small>
                </div>
                <div class="sp-preco<?php echo $sem ? ' sem' : ''; ?>"><b><?php echo $sem ? 'sem preço' : vn_h(vn_money($e['preco'])); ?></b><?php if (!$sem): ?><span>/ <?php echo vn_h($unidades[$un][0] ?? $un); ?></span><?php endif; ?></div>
                <button type="button" class="sp-btn sm" data-sp-estrutura="<?php echo sp_json(['id' => (int)$e['id'], 'nome' => (string)$e['nome'], 'categoria' => (string)$e['categoria'], 'unidade' => $un, 'preco' => sp_num_campo((float)$e['preco']), 'mao_obra' => $e['mao_obra'] === null ? '' : sp_num_campo((float)$e['mao_obra'])]); ?>"><?php echo sp_ico('edit'); ?>Editar</button>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
</section>

<!-- ============ mão de obra por medida ============ -->
<section class="vn-panel sp-sec" id="mao-de-obra" aria-labelledby="spT2">
    <h3 id="spT2">Custo de mão de obra por medida</h3>
    <p class="vn-help">Quanto você cobra de mão de obra, somado ao preço da estrutura. Deixe em R$ 0,00 se o preço da estrutura já inclui a instalação. Cada estrutura pode ter a sua própria mão de obra (em Editar).</p>
    <div class="sp-lista">
    <?php foreach ($linhasMao as $l): ?>
        <div class="sp-row">
            <div class="sp-info"><strong><?php echo vn_h($l[1]); ?></strong><small><?php echo vn_h($l[2]); ?></small></div>
            <div class="sp-preco"><b><?php echo vn_h(vn_money($cfg[$l[0]])); ?></b></div>
            <button type="button" class="sp-btn sm" data-sp-valor="<?php echo sp_json(['chave' => $l[0], 'rotulo' => 'Mão de obra ' . mb_strtolower($l[1]), 'tipo' => 'reais', 'valor' => sp_num_campo((float)$cfg[$l[0]]), 'ajuda' => $l[2]]); ?>"><?php echo sp_ico('edit'); ?>Editar</button>
        </div>
    <?php endforeach; ?>
    </div>
</section>

<!-- ============ níveis da estrutura ============ -->
<section class="vn-panel sp-sec" id="niveis" aria-labelledby="spT3">
    <h3 id="spT3">Níveis de estrutura</h3>
    <p class="vn-help">Aplicado sobre o preço da estrutura conforme o nível escolhido no orçamento. Porcentagem negativa é desconto.</p>
    <div class="sp-lista">
    <?php foreach ($linhasNivel as $l): ?>
        <div class="sp-row">
            <div class="sp-info"><strong><?php echo vn_h($l[1]); ?></strong><small><?php echo vn_h($l[2]); ?></small></div>
            <div class="sp-preco"><b><?php echo vn_h(sp_pct((float)$cfg[$l[0]])); ?></b></div>
            <button type="button" class="sp-btn sm" data-sp-valor="<?php echo sp_json(['chave' => $l[0], 'rotulo' => 'Nível ' . mb_strtolower($l[1]), 'tipo' => 'pct', 'valor' => sp_num_campo((float)$cfg[$l[0]]), 'ajuda' => 'Quanto o preço da estrutura sobe (positivo) ou desce (negativo) neste nível.']); ?>"><?php echo sp_ico('edit'); ?>Editar</button>
        </div>
    <?php endforeach; ?>
    </div>
</section>

<!-- ============ perfis de mão de obra ============ -->
<section class="vn-panel sp-sec" id="perfis" aria-labelledby="spT4">
    <h3 id="spT4">Perfis de mão de obra</h3>
    <p class="vn-help">Aplicado sobre a mão de obra conforme o perfil escolhido no orçamento. Porcentagem negativa é desconto.</p>
    <div class="sp-lista">
        <div class="sp-row">
            <div class="sp-info"><strong>Padrão</strong><small>Sem ajuste: a mão de obra da tabela.</small></div>
            <div class="sp-preco"><b>sem ajuste</b></div>
            <span class="sp-fixo">fixo</span>
        </div>
    <?php foreach ($linhasPerfil as $l): ?>
        <div class="sp-row">
            <div class="sp-info"><strong><?php echo vn_h($l[1]); ?></strong><small><?php echo vn_h($l[2]); ?></small></div>
            <div class="sp-preco"><b><?php echo vn_h(sp_pct((float)$cfg[$l[0]])); ?></b></div>
            <button type="button" class="sp-btn sm" data-sp-valor="<?php echo sp_json(['chave' => $l[0], 'rotulo' => 'Perfil ' . mb_strtolower($l[1]), 'tipo' => 'pct', 'valor' => sp_num_campo((float)$cfg[$l[0]]), 'ajuda' => 'Quanto a mão de obra sobe (positivo) ou desce (negativo) neste perfil.']); ?>"><?php echo sp_ico('edit'); ?>Editar</button>
        </div>
    <?php endforeach; ?>
    </div>
</section>

<p class="vn-help sp-rodape">O orçamento só calcula o que estiver aqui. Para usar: abra um pedido do tipo Serralheria, toque em "Calcular por medida", escolha a estrutura, digite as medidas e adicione ao pedido.</p>

<!-- ============ janela: estrutura (nova / editar) ============ -->
<datalist id="spCats"><?php foreach ($categorias as $c): ?><option value="<?php echo vn_h($c); ?>"><?php endforeach; ?></datalist>
<dialog class="sp-sheet" id="spEstrutura" aria-labelledby="spEstT"><div class="sp-sheet-in">
    <div class="sp-sheet-head"><h3 id="spEstT">Nova estrutura</h3><button type="button" class="sp-x" data-sp-fechar aria-label="Fechar"><?php echo sp_ico('close'); ?></button></div>
    <form method="post" id="spEstForm" autocomplete="off" class="sp-sheet-body">
        <input type="hidden" name="acao" value="estrutura_salvar">
        <input type="hidden" name="id" value="0">
        <div class="sp-f"><label for="spNome">Nome *</label><input id="spNome" name="nome" maxlength="160" required placeholder="Ex.: Portão Deslizante"></div>
        <div class="sp-f"><label for="spCat">Categoria</label><input id="spCat" name="categoria" maxlength="80" list="spCats" placeholder="Ex.: Estruturas essenciais"></div>
        <div class="sp-2">
            <div class="sp-f"><label for="spUn">Cobrar por *</label><select id="spUn" name="unidade" required>
                <?php foreach ($unidades as $k => $u): ?><option value="<?php echo vn_h($k); ?>"><?php echo vn_h($u[1]); ?></option><?php endforeach; ?>
            </select></div>
            <div class="sp-f"><label for="spPreco">Preço (R$) *</label><input id="spPreco" name="preco" inputmode="decimal" required placeholder="0,00"></div>
        </div>
        <div class="sp-f"><label for="spMao">Mão de obra própria (R$, opcional)</label><input id="spMao" name="mao_obra" inputmode="decimal" placeholder="Vazio = usa a mão de obra geral da tabela"><small>Preencha só se esta estrutura tem uma mão de obra diferente da geral. Vale por medida, como o preço.</small></div>
    </form>
    <div class="sp-sheet-foot">
        <form method="post" id="spEstDel" data-danger data-confirm="Tirar esta estrutura da tabela? Os pedidos que já usam ela não mudam." hidden>
            <input type="hidden" name="acao" value="estrutura_excluir"><input type="hidden" name="id" value="0">
            <button type="submit" class="sp-btn bad" data-confirm-ok="Excluir"><?php echo sp_ico('delete'); ?>Excluir</button>
        </form>
        <button type="button" class="sp-btn push" data-sp-fechar>Cancelar</button>
        <button type="submit" form="spEstForm" class="sp-btn pri">Salvar</button>
    </div>
</div></dialog>

<!-- ============ janela: um valor (mão de obra em R$, nível ou perfil em %) ============ -->
<dialog class="sp-sheet sp-peq" id="spValor" aria-labelledby="spValT"><div class="sp-sheet-in">
    <div class="sp-sheet-head"><h3 id="spValT">Editar valor</h3><button type="button" class="sp-x" data-sp-fechar aria-label="Fechar"><?php echo sp_ico('close'); ?></button></div>
    <form method="post" id="spValForm" autocomplete="off" class="sp-sheet-body">
        <input type="hidden" name="acao" value="config_salvar">
        <input type="hidden" name="chave" value="">
        <div class="sp-f"><label for="spVal" data-sp-val-rot>Valor</label>
            <div class="sp-adorn"><span data-sp-pre>R$</span><input id="spVal" name="valor" inputmode="decimal" required placeholder="0,00"><span data-sp-pos hidden>%</span></div>
            <small data-sp-val-ajuda></small></div>
    </form>
    <div class="sp-sheet-foot"><button type="button" class="sp-btn push" data-sp-fechar>Cancelar</button><button type="submit" form="spValForm" class="sp-btn pri">Salvar</button></div>
</div></dialog>

<!-- ============ janela: reajustar todos os preços ============ -->
<dialog class="sp-sheet sp-peq" id="spReajuste" aria-labelledby="spReaT"><div class="sp-sheet-in">
    <div class="sp-sheet-head"><h3 id="spReaT">Reajustar todos os preços</h3><button type="button" class="sp-x" data-sp-fechar aria-label="Fechar"><?php echo sp_ico('close'); ?></button></div>
    <form method="post" id="spReaForm" autocomplete="off" class="sp-sheet-body">
        <input type="hidden" name="acao" value="reajustar">
        <p class="sp-nota">O aço subiu (ou desceu)? Aumente ou diminua o preço de todas as <?php echo (int)$totalEst; ?> estruturas de uma vez. Use um número negativo para baixar.</p>
        <div class="sp-f"><label for="spPct">Reajuste (%)</label><div class="sp-adorn"><input id="spPct" name="pct" inputmode="decimal" required placeholder="Ex.: 8 ou -5"><span>%</span></div></div>
        <label class="sp-chk"><input type="checkbox" name="incluir_mao" value="1"> Reajustar também a mão de obra (a geral e as próprias de cada estrutura)</label>
        <p class="sp-nota">Níveis e perfis (as porcentagens) não mudam. Pedidos já salvos não mudam.</p>
    </form>
    <div class="sp-sheet-foot"><button type="button" class="sp-btn push" data-sp-fechar>Cancelar</button><button type="submit" form="spReaForm" class="sp-btn pri">Reajustar</button></div>
</div></dialog>

</div>
<?php vn_ui_shell_end('serr-precos'); ?>
