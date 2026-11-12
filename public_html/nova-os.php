<?php
require_once __DIR__ . '/_vn_bootstrap.php';

if (!function_exists('vn_mobile_os_get')) {
    function vn_mobile_os_get($arr, $keys, $default = '') {
        foreach ((array)$keys as $k) {
            if (isset($arr[$k]) && $arr[$k] !== '' && $arr[$k] !== null) {
                return $arr[$k];
            }
        }
        return $default;
    }
}

if (!function_exists('vn_mobile_os_endereco_full')) {
    function vn_mobile_os_endereco_full($e) {
        $partes = [];

        $logradouro = vn_mobile_os_get($e, ['endereco', 'endereco_cliente', 'logradouro', 'rua']);
        $numero     = vn_mobile_os_get($e, ['numero', 'numero_cliente', 'num']);
        $bairro     = vn_mobile_os_get($e, ['bairro', 'bairro_cliente']);
        $cidade     = vn_mobile_os_get($e, ['cidade', 'cidade_cliente']);
        $uf         = vn_mobile_os_get($e, ['uf', 'uf_cliente', 'estado']);
        $cep        = vn_mobile_os_get($e, ['cep', 'cep_cliente']);
        $referencia = vn_mobile_os_get($e, ['referencia', 'referencia_cliente', 'complemento', 'complemento_cliente']);

        if ($logradouro) {
            $partes[] = trim($logradouro . ($numero ? ', ' . $numero : ''));
        }

        if ($bairro) {
            $partes[] = $bairro;
        }

        $cidadeUf = trim($cidade . ($uf ? ' - ' . $uf : ''));
        if ($cidadeUf) {
            $partes[] = $cidadeUf;
        }

        if ($cep) {
            $partes[] = 'CEP: ' . $cep;
        }

        if ($referencia) {
            $partes[] = 'Referência: ' . $referencia;
        }

        $montado = trim(implode(' | ', array_filter($partes)));

        if (!$montado) {
            $montado = vn_mobile_os_get($e, ['label', 'nome', 'descricao'], '');
        }

        return $montado;
    }
}

if (!function_exists('vn_mobile_os_cliente_nome')) {
    function vn_mobile_os_cliente_nome($c) {
        return vn_mobile_os_get($c, [
            'nome',
            'nome_cliente',
            'razao_social',
            'razao_cliente',
            'fantasia_cliente',
            'cliente'
        ], '');
    }
}

$ok = null;
$erro = '';
$avisos = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $res = vn_salvar_os_mobile($_POST);
        $ok = 'OS criada: ' . $res['codigo'];
        $avisos = $res['avisos'];
    } catch (Throwable $e) {
        $erro = $e->getMessage();
    }
}

$clientes  = vn_clientes_form();
$enderecos = vn_enderecos_form();
$tecnicos  = vn_tecnicos_form();

/* Vindo de um pedido aprovado (?orc=ID): já preenche cliente, endereço e o serviço combinado. */
$pre = ['cliente_id' => 0, 'cliente_nome' => '', 'endereco' => '', 'problema' => '', 'observacoes' => '', 'numero' => ''];
$orcId = (int)($_GET['orc'] ?? 0);
if ($orcId > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    try {
        $orcOrigem = vn_orcamento_mobile_get_v4($orcId);
        if ($orcOrigem) {
            $linhas = [];
            foreach (($orcOrigem['itens'] ?? []) as $it) {
                $q = (float)($it['quantidade'] ?? 1);
                $linhas[] = '• ' . ($q > 0 && $q != 1 ? rtrim(rtrim(number_format($q, 2, ',', ''), '0'), ',') . 'x ' : '') . trim((string)($it['descricao'] ?? ''));
            }
            $pre = [
                'cliente_id'   => (int)($orcOrigem['cliente_id'] ?? 0),
                'cliente_nome' => (string)($orcOrigem['cliente_nome'] ?? ''),
                'endereco'     => (string)($orcOrigem['endereco'] ?? ''),
                'problema'     => 'Serviço do pedido ' . ($orcOrigem['numero'] ?? ('#' . $orcId)) . ":\n" . implode("\n", $linhas),
                'observacoes'  => (string)($orcOrigem['observacoes'] ?? ''),
                'numero'       => (string)($orcOrigem['numero'] ?? ('#' . $orcId)),
            ];
        }
    } catch (Throwable $e) {
        error_log('VN System [nova-os] pedido de origem: ' . $e->getMessage());
    }
}

/* Tipo da OS: CFTV ou serralheria (?linha=serralheria; vindo de um pedido de serralheria já vem marcado) */
$linhaSel = (($_POST['linha_servico'] ?? ($_GET['linha'] ?? (isset($orcOrigem) && is_array($orcOrigem) ? ($orcOrigem['categoria'] ?? '') : ''))) === 'serralheria') ? 'serralheria' : 'cftv';

vn_top_header($linhaSel === 'serralheria' ? 'Nova OS de Serralheria' : 'Nova OS', 'os');
?>

<?php if ($ok): ?>
    <div class="success">
        <?php echo vn_h($ok); ?> · <a href="os-dashboard.php">ver dashboard</a>
    </div>
<?php endif; ?>

<?php foreach ($avisos as $a): ?>
    <div class="warn"><?php echo vn_h($a); ?></div>
<?php endforeach; ?>

<?php if ($erro): ?>
    <div class="warn">Erro ao salvar OS: <?php echo vn_h($erro); ?></div>
<?php endif; ?>

<section class="hero">
    <span class="hero-kicker">Nova OS</span>
    <h1><?php echo $linhaSel === 'serralheria' ? 'Criar OS de serralheria em etapas.' : 'Criar OS em etapas.'; ?></h1>
    <p>Informe o cliente, o técnico e o problema. A OS entra como aberta e aparece no dashboard<?php echo $linhaSel === 'serralheria' ? ', separada das OS de CFTV' : ''; ?>.</p>
</section>

<?php if ($pre['numero'] !== ''): ?>
    <div class="alert ok">Dados preenchidos a partir do pedido <?php echo vn_h($pre['numero']); ?>. Confira antes de salvar.</div>
<?php endif; ?>

<form method="post" class="wizard" data-wizard>
    <div class="steps">
        <div class="step-pill">1 Cliente</div>
        <div class="step-pill">2 Técnico</div>
        <div class="step-pill">3 Problema</div>
        <div class="step-pill">4 Salvar</div>
    </div>

    <section class="panel wizard-step">
        <div class="panel-head">
            <div>
                <h3>Cliente e local</h3>
                <small>dados principais da OS</small>
            </div>
        </div>

<style>
.linha-os-op{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:6px}
.linha-os-op label{position:relative;display:flex;align-items:center;justify-content:center;gap:8px;min-height:50px;margin:0;padding:8px 10px;border:1px solid var(--line-strong,rgba(148,163,184,.4));border-radius:12px;cursor:pointer;font-weight:800;text-align:center}
.linha-os-op input{position:absolute!important;left:0;top:0;width:1px!important;height:1px!important;min-width:0!important;min-height:0!important;margin:0!important;padding:0!important;border:0!important;opacity:0;pointer-events:none}   /* o CSS antigo dá width:100% a todo input: sem isto o campo invisível alargava a página */
.linha-os-op label:has(input:checked){border-color:#3b82f6;background:rgba(59,130,246,.16);box-shadow:0 0 0 2px rgba(59,130,246,.25)}
.linha-os-op label.serr:has(input:checked){border-color:#f59e0b;background:rgba(245,158,11,.18);box-shadow:0 0 0 2px rgba(245,158,11,.28)}
</style>
        <div class="form-grid">
            <div class="field" style="grid-column:1/-1">
                <label>Tipo da OS</label>
                <div class="linha-os-op" role="radiogroup" aria-label="Tipo da OS">
                    <label><input type="radio" name="linha_servico" value="cftv" <?php echo $linhaSel !== 'serralheria' ? 'checked' : ''; ?>>CFTV / segurança</label>
                    <label class="serr"><input type="radio" name="linha_servico" value="serralheria" <?php echo $linhaSel === 'serralheria' ? 'checked' : ''; ?>>Serralheria</label>
                </div>
            </div>

            <div class="field">
                <label>Cliente cadastrado</label>
                <select name="cliente_id" data-cliente-select>
                    <option value="0">Selecionar cliente</option>

                    <?php foreach ($clientes as $c): ?>
                        <?php
                        $clienteId   = (int) vn_mobile_os_get($c, ['id', 'cliente_id', 'id_cliente', 'codigo_cliente'], 0);
                        $clienteNome = vn_mobile_os_cliente_nome($c);
                        $clienteTel  = vn_mobile_os_get($c, ['telefone', 'telefone_cliente', 'celular', 'celular_cliente'], '');
                        $clienteEnd  = vn_mobile_os_endereco_full($c);
                        ?>

                        <?php if ($clienteId > 0): ?>
                            <option
                                value="<?php echo $clienteId; ?>"<?php echo ($pre['cliente_id'] > 0 && $pre['cliente_id'] === $clienteId) ? ' selected' : ''; ?>
                                data-nome="<?php echo vn_h($clienteNome); ?>"
                                data-tel="<?php echo vn_h($clienteTel); ?>"
                                data-end="<?php echo vn_h($clienteEnd); ?>"
                            >
                                <?php echo vn_h($clienteNome ?: ('Cliente #' . $clienteId)); ?>
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label>Nome do cliente</label>
                <input name="cliente_nome" required placeholder="Nome/condomínio" data-cliente-nome value="<?php echo vn_h($pre['cliente_nome']); ?>">
            </div>

            <div class="field">
                <label>Endereço salvo</label>
                <select name="endereco_id" data-endereco-select>
                    <option value="0">Selecione um cliente primeiro</option>

                    <?php foreach ($enderecos as $e): ?>
                        <?php
                        $enderecoId = (int) vn_mobile_os_get($e, ['id', 'endereco_id', 'id_endereco', 'address_id'], 0);
                        $clienteIdEndereco = (int) vn_mobile_os_get($e, ['cliente_id', 'id_cliente', 'codigo_cliente'], 0);
                        $enderecoFull = vn_mobile_os_endereco_full($e);
                        $label = vn_mobile_os_get($e, ['label', 'nome', 'descricao'], '');

                        if (!$label) {
                            $label = $enderecoFull;
                        }
                        ?>

                        <?php if ($enderecoId > 0): ?>
                            <option
                                value="<?php echo $enderecoId; ?>"
                                data-cliente="<?php echo $clienteIdEndereco; ?>"
                                data-end="<?php echo vn_h($enderecoFull); ?>"
                                data-label="<?php echo vn_h($label); ?>"
                            >
                                <?php echo vn_h('Cliente ' . $clienteIdEndereco . ' · ' . $label); ?>
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>

                <small data-endereco-status style="display:block;margin-top:6px;opacity:.75;"></small>
            </div>

            <div class="field">
                <label>Endereço da OS</label>
                <textarea name="endereco" placeholder="Endereço completo" data-endereco-texto><?php echo vn_h($pre['endereco']); ?></textarea>
            </div>
        </div>

        <div class="wizard-actions">
            <button class="btn btn-primary full" type="button" data-next>Próxima etapa</button>
        </div>
    </section>

    <section class="panel wizard-step">
        <div class="panel-head">
            <div>
                <h3>Técnico e data</h3>
                <small>responsável pelo atendimento</small>
            </div>
        </div>

        <div class="form-grid">
            <div class="field">
                <label>Técnico</label>
                <select name="tecnico_id" data-tecnico-select>
                    <option value="0">Selecionar técnico</option>

                    <?php foreach ($tecnicos as $t): ?>
                        <?php
                        $tecId = (int) vn_mobile_os_get($t, ['id', 'id_tec', 'tecnico_id'], 0);
                        $tecNome = vn_mobile_os_get($t, ['nome', 'usuario', 'nome_tecnico'], '');
                        ?>

                        <?php if ($tecId > 0): ?>
                            <option
                                value="<?php echo $tecId; ?>"
                                data-nome="<?php echo vn_h($tecNome); ?>"
                            >
                                <?php echo vn_h($tecNome ?: ('Técnico #' . $tecId)); ?>
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>

                <input type="hidden" name="tecnico_nome" data-tecnico-nome>
            </div>

            <div class="field">
                <label>Data agendada</label>
                <input type="datetime-local" name="data_agendada">
            </div>

            <div class="field">
                <label>Prioridade</label>
                <select name="prioridade">
                    <option>Normal</option>
                    <option>Urgente</option>
                    <option>Baixa</option>
                </select>
            </div>
        </div>

        <div class="wizard-actions">
            <button class="btn btn-soft" type="button" data-prev>Voltar</button>
            <button class="btn btn-primary" type="button" data-next>Próximo</button>
        </div>
    </section>

    <section class="panel wizard-step">
        <div class="panel-head">
            <div>
                <h3>Problema relatado</h3>
                <small>o que precisa ser feito</small>
            </div>
        </div>

        <div class="form-grid">
            <div class="field">
                <label>Problema/serviço</label>
                <textarea name="problema" required placeholder="Ex: interfone não chama, câmera sem imagem..."><?php echo vn_h($pre['problema']); ?></textarea>
            </div>

            <div class="field">
                <label>Observações internas</label>
                <textarea name="observacoes" placeholder="Informações para o técnico"><?php echo vn_h($pre['observacoes']); ?></textarea>
            </div>
        </div>

        <div class="wizard-actions">
            <button class="btn btn-soft" type="button" data-prev>Voltar</button>
            <button class="btn btn-primary" type="button" data-next>Revisar</button>
        </div>
    </section>

    <section class="panel wizard-step">
        <div class="panel-head">
            <div>
                <h3>Salvar OS</h3>
                <small>criar ordem de serviço</small>
            </div>
        </div>

        <p class="note">Ao salvar, a OS entra como aberta/pendente e aparece no gráfico de pendentes do dashboard.</p>

        <div class="wizard-actions">
            <button class="btn btn-soft" type="button" data-prev>Voltar</button>
            <button class="btn btn-green" type="submit">Salvar OS</button>
        </div>
    </section>
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const clienteSelect = document.querySelector('[data-cliente-select]');
    const enderecoSelect = document.querySelector('[data-endereco-select]');
    const enderecoTexto = document.querySelector('[data-endereco-texto]');
    const clienteNomeInput = document.querySelector('[data-cliente-nome]');
    const tecnicoSelect = document.querySelector('[data-tecnico-select]');
    const tecnicoNomeInput = document.querySelector('[data-tecnico-nome]');
    const enderecoStatus = document.querySelector('[data-endereco-status]');

    const enderecoOptionsOriginais = Array.from(enderecoSelect.querySelectorAll('option[data-cliente]')).map(opt => ({
        value: opt.value,
        cliente: opt.dataset.cliente || '0',
        endereco: opt.dataset.end || '',
        label: opt.dataset.label || opt.textContent.trim(),
        text: opt.textContent.trim()
    }));

    function limparEnderecos(msg) {
        enderecoSelect.innerHTML = '';
        const opt = document.createElement('option');
        opt.value = '0';
        opt.textContent = msg || 'Selecionar endereço';
        enderecoSelect.appendChild(opt);
    }

    function addEnderecoOption(item) {
        const opt = document.createElement('option');
        opt.value = item.value;
        opt.dataset.cliente = item.cliente;
        opt.dataset.end = item.endereco;
        opt.dataset.label = item.label;
        opt.textContent = item.text;
        enderecoSelect.appendChild(opt);
    }

    function filtrarEnderecosPorCliente() {
        const clienteId = clienteSelect.value || '0';
        const clienteOpt = clienteSelect.options[clienteSelect.selectedIndex];

        const nome = clienteOpt ? (clienteOpt.dataset.nome || '') : '';
        const endCliente = clienteOpt ? (clienteOpt.dataset.end || '') : '';

        if (clienteNomeInput && nome) {
            clienteNomeInput.value = nome;
        }

        limparEnderecos('Selecionar endereço');

        if (!clienteId || clienteId === '0') {
            enderecoTexto.value = '';
            enderecoStatus.textContent = 'Selecione um cliente para carregar os endereços dele.';
            return;
        }

        const encontrados = enderecoOptionsOriginais.filter(item => String(item.cliente) === String(clienteId));

        if (encontrados.length === 0) {
            limparEnderecos('Nenhum endereço salvo para este cliente');
            enderecoTexto.value = endCliente || '';
            enderecoStatus.textContent = endCliente
                ? 'Nenhum endereço extra encontrado. Usando endereço principal do cliente.'
                : 'Nenhum endereço encontrado para este cliente.';
            return;
        }

        encontrados.forEach(addEnderecoOption);

        enderecoStatus.textContent = encontrados.length === 1
            ? '1 endereço encontrado para este cliente.'
            : encontrados.length + ' endereços encontrados para este cliente.';

        if (encontrados.length === 1) {
            enderecoSelect.value = encontrados[0].value;
            enderecoTexto.value = encontrados[0].endereco;
        } else {
            enderecoTexto.value = '';
        }
    }

    function preencherEnderecoSelecionado() {
        const opt = enderecoSelect.options[enderecoSelect.selectedIndex];

        if (!opt || !opt.dataset.end) {
            return;
        }

        enderecoTexto.value = opt.dataset.end;
    }

    function preencherTecnicoNome() {
        if (!tecnicoSelect || !tecnicoNomeInput) return;

        const opt = tecnicoSelect.options[tecnicoSelect.selectedIndex];
        tecnicoNomeInput.value = opt ? (opt.dataset.nome || opt.textContent.trim()) : '';
    }

    if (clienteSelect && enderecoSelect) {
        clienteSelect.addEventListener('change', filtrarEnderecosPorCliente);
    }

    if (enderecoSelect) {
        enderecoSelect.addEventListener('change', preencherEnderecoSelecionado);
    }

    if (tecnicoSelect) {
        tecnicoSelect.addEventListener('change', preencherTecnicoNome);
    }

    const wizard = document.querySelector('[data-wizard]');
    if (wizard) {
        const steps = Array.from(wizard.querySelectorAll('.wizard-step'));
        const pills = Array.from(wizard.querySelectorAll('.step-pill'));
        let atual = 0;

        function mostrarStep(index) {
            atual = Math.max(0, Math.min(index, steps.length - 1));

            steps.forEach((step, i) => {
                step.style.display = i === atual ? '' : 'none';
            });

            pills.forEach((pill, i) => {
                pill.classList.toggle('active', i === atual);
            });
        }

        wizard.querySelectorAll('[data-next]').forEach(btn => {
            btn.addEventListener('click', function () {
                mostrarStep(atual + 1);
            });
        });

        wizard.querySelectorAll('[data-prev]').forEach(btn => {
            btn.addEventListener('click', function () {
                mostrarStep(atual - 1);
            });
        });

        mostrarStep(0);
    }

    filtrarEnderecosPorCliente();
    preencherTecnicoNome();

    // pedido de origem: o filtro acima pode limpar o endereço quando o cliente tem vários; reaplica o do pedido
    const prePedido = <?php echo json_encode(['endereco' => $pre['endereco']], VN_JSON_SCRIPT); ?>;
    if (prePedido.endereco && enderecoTexto && !enderecoTexto.value) {
        enderecoTexto.value = prePedido.endereco;
    }
});
</script>

<?php vn_bottom_nav('os'); ?>