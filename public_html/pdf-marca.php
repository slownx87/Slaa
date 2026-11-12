<?php
/*
    VN System - Marca dos PDFs
    Logo, cores e dados da empresa que aparecem nos orçamentos e nas ordens de serviço em PDF.
    Cada linha de negócio tem a sua marca: VN System (CFTV) e Expresso Serralheria. O orçamento e a OS usam
    a marca da própria categoria (CFTV ou Serralheria).
    - Ao enviar a logo, as cores do PDF saem dela sozinhas (dá para mudar à mão);
    - "PDF de exemplo" mostra o resultado na hora, com dados inventados.
    Só administrador abre. Dados: tabela vn_pdf_marca; imagens: uploads/marca. Motor do PDF: _vn_pdf.php / _vn_pdf_docs.php.
*/
require_once __DIR__ . '/_vn_orcamento_layout.php';
require_once __DIR__ . '/_vn_pdf_docs.php';

if (strtolower(trim((string)($_SESSION['cargo'] ?? ''))) !== 'admin') {
    http_response_code(403);
    echo 'Acesso restrito ao administrador.';
    exit;
}

$linhas = vn_marca_linhas();
$linha = vn_marca_linha_valida($_GET['linha'] ?? ($_POST['linha'] ?? 'cftv'));

/* ------------------------------------------------------------ PDF de exemplo (baixa como arquivo) */
if (isset($_GET['exemplo'])) {
    try {
        $tipo = (string)$_GET['exemplo'] === 'os' ? 'os' : 'orcamento';
        $pdf = vn_pdf_exemplo($tipo, $linha);
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="exemplo-' . $tipo . '-' . $linha . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('X-Content-Type-Options: nosniff');
        echo $pdf;
    } catch (Throwable $e) {
        while (ob_get_level() > 0) ob_end_clean();
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Erro ao gerar o PDF de exemplo: ' . vn_public_error($e, 'pdf-marca');
    }
    exit;
}

/* ------------------------------------------------------------ salvar */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipoAviso = 'ok';
    try {
        // imagem maior que o limite do servidor chega como POST vazio
        if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            throw new InvalidArgumentException('As imagens passaram do limite de envio do servidor (' . ini_get('post_max_size') . '). Use imagens menores.');
        }
        if ((string)($_POST['acao'] ?? '') !== 'salvar') throw new InvalidArgumentException('Ação inválida. Recarregue a página e tente de novo.');
        $msg = vn_marca_salvar($linha, $_POST, $_FILES);
    } catch (Throwable $e) {
        $tipoAviso = 'err';
        $msg = $e instanceof InvalidArgumentException ? $e->getMessage() : vn_public_error($e, 'pdf-marca');
    }
    $_SESSION['pm_flash'] = ['tipo' => $tipoAviso, 'msg' => $msg];
    header('Location: pdf-marca.php?linha=' . $linha);
    exit;
}

$flash = $_SESSION['pm_flash'] ?? null;
unset($_SESSION['pm_flash']);

$b = vn_marca_get($linha);
$tema = vn_pdf_tema((string)$b['cor1'], (string)$b['cor2']);
$logoDireto = $b['logo_file'] !== '' && vn_marca_logo_sobre_escuro((string)$b['logo_file'], (string)$b['cor1']);   // logo clara: vai direto na faixa
$logoUrl = $b['logo_file'] !== '' ? ltrim(str_replace('\\', '/', (string)($b['logo'] !== '' ? $b['logo'] : 'vn.png')), '/') : '';
$assUrl = $b['assinatura_file'] !== '' ? ltrim(str_replace('\\', '/', (string)$b['assinatura']), '/') : '';
$faltaDados = $linha === 'serralheria' && !$b['salvo'];

vn_ui_shell_start('Marca dos PDFs', 'marca', [
    'css' => ['assets/css/vn-marca.css'],
    'js'  => ['assets/js/vn-download.js', 'assets/js/vn-marca.js'],
    'sub' => 'Logo, cores e dados dos PDFs',
]);
?>
<div class="pm" id="pmRoot">

<?php if ($flash && !empty($flash['msg'])): $ft = in_array($flash['tipo'] ?? '', ['ok', 'warn', 'err'], true) ? $flash['tipo'] : 'ok'; ?>
    <div class="vn-alert <?php echo $ft; ?>" role="status"><?php echo vn_h($flash['msg']); ?></div>
<?php endif; ?>

<section class="vn-page-head">
    <div>
        <span class="vn-kicker">PDFs</span>
        <h2>Marca dos PDFs.</h2>
        <p>Envie a logo e preencha os dados: orçamentos e ordens de serviço saem em PDF com o nome, o contato e as cores da marca. Cada orçamento e cada OS usa a marca da sua categoria (CFTV ou Serralheria).</p>
    </div>
</section>

<nav class="vn-segment pm-abas" aria-label="Marca">
    <?php foreach ($linhas as $k => $rot): ?>
        <a href="pdf-marca.php?linha=<?php echo vn_h($k); ?>"<?php echo $k === $linha ? ' class="active" aria-current="page"' : ''; ?>><?php echo vn_h($rot); ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($faltaDados): ?>
    <div class="vn-alert warn" role="note">Ainda não há CNPJ, telefone nem endereço da Expresso Serralheria: os PDFs saem só com a logo e a frase. Preencha os dados abaixo.</div>
<?php endif; ?>

<section class="pm-prev<?php echo $logoDireto ? ' pm-direto' : ''; echo ($logoDireto && !empty($b['logo_nome'])) ? ' pm-logonome' : ''; ?>" id="pmPrev" aria-label="Prévia do cabeçalho do PDF"
         style="--pm-c1:<?php echo vn_h($tema['c1']); ?>;--pm-c2:<?php echo vn_h($tema['c2']); ?>;--pm-on2:<?php echo vn_h($tema['on2']); ?>">
    <div class="pm-prev-band">
        <div class="pm-prev-logo">
            <img id="pmPrevImg" src="<?php echo vn_h($logoUrl); ?>" alt=""<?php echo $logoUrl === '' ? ' hidden' : ''; ?>>
            <span id="pmPrevIni"<?php echo $logoUrl !== '' ? ' hidden' : ''; ?>><?php echo vn_h(vn_pdf_iniciais((string)$b['nome'])); ?></span>
        </div>
        <div class="pm-prev-name">
            <strong id="pmPrevNome"><?php echo vn_h(vn_pdf_upper((string)$b['nome'])); ?></strong>
            <small id="pmPrevSlogan"><?php echo vn_h((string)$b['slogan']); ?></small>
            <small id="pmPrevContato"><?php echo vn_h(implode('  ·  ', array_filter([trim((string)$b['cnpj']) !== '' ? 'CNPJ ' . trim((string)$b['cnpj']) : '', trim((string)$b['telefone'])]))); ?></small>
        </div>
        <div class="pm-prev-doc">
            <strong>ORÇAMENTO</strong>
            <span>Nº 0001</span>
            <em>Válido até 31/12/<?php echo date('Y'); ?></em>
        </div>
    </div>
    <div class="pm-prev-stripe"></div>
    <div class="pm-prev-body">
        <div class="pm-prev-th"><span>Descrição</span><span>Qtd</span><span>Subtotal</span></div>
        <div class="pm-prev-tr"><span>Item de exemplo</span><span>1</span><span>R$ 1.000,00</span></div>
        <div class="pm-prev-total"><i></i><span>TOTAL</span><strong>R$ 1.000,00</strong></div>
    </div>
</section>

<form method="post" enctype="multipart/form-data" class="vn-panel pm-form" id="pmForm" autocomplete="off">
    <input type="hidden" name="acao" value="salvar">
    <input type="hidden" name="linha" value="<?php echo vn_h($linha); ?>">

    <div class="pm-bloco">
        <h3>Logo e cores</h3>
        <div class="pm-logo-linha">
            <div class="pm-logo-box" id="pmLogoBox">
                <img id="pmLogoImg" src="<?php echo vn_h($logoUrl); ?>" alt="Logo atual"<?php echo $logoUrl === '' ? ' hidden' : ''; ?>>
                <span id="pmLogoVazio"<?php echo $logoUrl !== '' ? ' hidden' : ''; ?>>Sem logo</span>
            </div>
            <div class="pm-logo-campos">
                <div class="vn-field">
                    <label for="pmLogo">Logo (PNG com fundo transparente fica melhor)</label>
                    <input type="file" name="logo" id="pmLogo" accept="image/png,image/jpeg,image/webp">
                </div>
                <label class="vn-check"><input type="checkbox" name="logo_nome" value="1" id="pmLogoNome"<?php echo !empty($b['logo_nome']) ? ' checked' : ''; ?>> A logo já traz o nome da empresa (não repetir o nome ao lado dela)</label>
                <?php if ($logoUrl !== ''): ?>
                    <label class="vn-check"><input type="checkbox" name="remover_logo" value="1" id="pmRemoverLogo"> Tirar a logo</label>
                <?php endif; ?>
            </div>
        </div>

        <label class="vn-check pm-cores-logo"><input type="checkbox" name="cores_da_logo" value="1" id="pmCoresLogo"> Pegar as cores do PDF da logo ao salvar</label>
        <div class="pm-cores">
            <div class="vn-field">
                <label for="pmCor1">Cor principal (faixa e títulos)</label>
                <input type="color" name="cor1" id="pmCor1" value="<?php echo vn_h((string)$b['cor1']); ?>">
            </div>
            <div class="vn-field">
                <label for="pmCor2">Cor de destaque (detalhes)</label>
                <input type="color" name="cor2" id="pmCor2" value="<?php echo vn_h((string)$b['cor2']); ?>">
            </div>
        </div>
        <p class="vn-help">Se a cor principal for clara demais, o PDF a escurece sozinho para o texto branco continuar legível.</p>
    </div>

    <div class="pm-bloco">
        <h3>Dados da empresa</h3>
        <div class="vn-form-grid">
            <div class="vn-field full"><label for="pmNome">Nome da empresa</label><input type="text" name="nome" id="pmNome" maxlength="120" required value="<?php echo vn_h((string)$b['nome']); ?>"></div>
            <div class="vn-field full"><label for="pmSlogan">Frase abaixo do nome (opcional)</label><input type="text" name="slogan" id="pmSlogan" maxlength="160" value="<?php echo vn_h((string)$b['slogan']); ?>"></div>
            <div class="vn-field"><label for="pmCnpj">CNPJ</label><input type="text" name="cnpj" id="pmCnpj" maxlength="40" value="<?php echo vn_h((string)$b['cnpj']); ?>"></div>
            <div class="vn-field"><label for="pmTel">Telefone / WhatsApp</label><input type="text" name="telefone" id="pmTel" maxlength="60" inputmode="tel" value="<?php echo vn_h((string)$b['telefone']); ?>"></div>
            <div class="vn-field"><label for="pmEmail">E-mail</label><input type="email" name="email" id="pmEmail" maxlength="120" value="<?php echo vn_h((string)$b['email']); ?>"></div>
            <div class="vn-field"><label for="pmSite">Site ou Instagram</label><input type="text" name="site" id="pmSite" maxlength="120" value="<?php echo vn_h((string)$b['site']); ?>"></div>
            <div class="vn-field full"><label for="pmEnd">Endereço</label><input type="text" name="endereco" id="pmEnd" maxlength="255" value="<?php echo vn_h((string)$b['endereco']); ?>"></div>
            <div class="vn-field full"><label for="pmRodape">Mensagem no rodapé (opcional)</label><input type="text" name="rodape" id="pmRodape" maxlength="255" placeholder="Ex.: Obrigado pela preferência!" value="<?php echo vn_h((string)$b['rodape']); ?>"></div>
        </div>
    </div>

    <div class="pm-bloco">
        <h3>Assinatura da empresa (opcional)</h3>
        <p class="vn-help">Aparece sobre a linha "Assinatura da empresa" no orçamento. Sem imagem, a linha fica em branco para assinar à mão.</p>
        <div class="pm-logo-linha">
            <div class="pm-logo-box pm-ass-box">
                <?php if ($assUrl !== ''): ?><img src="<?php echo vn_h($assUrl); ?>" alt="Assinatura atual"><?php else: ?><span>Sem assinatura</span><?php endif; ?>
            </div>
            <div class="pm-logo-campos">
                <div class="vn-field"><label for="pmAss">Imagem da assinatura</label><input type="file" name="assinatura" id="pmAss" accept="image/png,image/jpeg,image/webp"></div>
                <?php if ($assUrl !== ''): ?><label class="vn-check"><input type="checkbox" name="remover_assinatura" value="1"> Tirar a assinatura</label><?php endif; ?>
            </div>
        </div>
    </div>

    <div class="vn-actions pm-acoes">
        <button type="submit" class="vn-btn vn-btn-primary"><span class="material-symbols-outlined" aria-hidden="true">check</span>Salvar marca</button>
        <a class="vn-btn" data-baixar data-nome="exemplo-orcamento-<?php echo vn_h($linha); ?>.pdf" href="pdf-marca.php?linha=<?php echo vn_h($linha); ?>&amp;exemplo=orcamento"><span class="material-symbols-outlined" aria-hidden="true">picture_as_pdf</span>Orçamento de exemplo</a>
        <a class="vn-btn" data-baixar data-nome="exemplo-os-<?php echo vn_h($linha); ?>.pdf" href="pdf-marca.php?linha=<?php echo vn_h($linha); ?>&amp;exemplo=os"><span class="material-symbols-outlined" aria-hidden="true">picture_as_pdf</span>OS de exemplo</a>
    </div>
    <p class="vn-help">Os PDFs de exemplo usam a marca já salva. Salve primeiro para ver as mudanças.</p>
</form>
</div>
<?php vn_ui_shell_end('marca'); ?>
