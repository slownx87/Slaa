<?php
/* =========================================================================
   VN System — documentos em PDF (orçamento e ordem de serviço) com a cara de cada marca.

   - Marca: nome, contatos, logo e cores de cada linha de negócio (CFTV = VN System,
     Serralheria = Expresso Serralheria). Fica na tabela vn_pdf_marca e é editada em
     "Marca dos PDFs" (pdf-marca.php). Ao enviar a logo, as cores do PDF saem dela.
   - VNPdfDoc: cabeçalho, rodapé, cartões, tabela, totais, textos, fotos e assinaturas.
   - vn_pdf_orcamento_v6() / vn_pdf_os_v6(): montam o documento.
   ========================================================================= */
require_once __DIR__ . '/_vn_pdf.php';

/* ---------- acesso ao banco (funciona com os helpers do bootstrap, mysqli ou PDO) ---------- */

function vn_marca_q(string $sql, array $params = [], $db = null): array {
    if ($db instanceof mysqli) {
        $st = $db->prepare($sql);
        if (!$st) throw new RuntimeException('Erro SQL.');
        if ($params) $st->bind_param(str_repeat('s', count($params)), ...array_map('strval', $params));
        $st->execute();
        $res = $st->get_result();
        $rows = [];
        while ($res && ($r = $res->fetch_assoc())) $rows[] = $r;
        return $rows;
    }
    if ($db instanceof PDO) {
        $st = $db->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    if (function_exists('vn_fetch_all')) return vn_fetch_all($sql, $params);
    throw new RuntimeException('Sem conexão com o banco.');
}
function vn_marca_x(string $sql, array $params = [], $db = null): void {
    if ($db instanceof mysqli) {
        if (!$params) { $db->query($sql); return; }
        $st = $db->prepare($sql);
        if (!$st) throw new RuntimeException('Erro SQL.');
        $st->bind_param(str_repeat('s', count($params)), ...array_map('strval', $params));
        $st->execute();
        return;
    }
    if ($db instanceof PDO) { $db->prepare($sql)->execute($params); return; }
    if (function_exists('vn_exec')) { vn_exec($sql, $params); return; }
    throw new RuntimeException('Sem conexão com o banco.');
}
function vn_marca_tabela($db = null): void {
    static $feito = false;
    if ($feito) return;
    vn_marca_x("CREATE TABLE IF NOT EXISTS vn_pdf_marca (
        linha VARCHAR(20) NOT NULL PRIMARY KEY,
        nome VARCHAR(120) NULL, slogan VARCHAR(160) NULL, cnpj VARCHAR(40) NULL, telefone VARCHAR(60) NULL,
        email VARCHAR(120) NULL, site VARCHAR(120) NULL, endereco VARCHAR(255) NULL, rodape VARCHAR(255) NULL,
        logo VARCHAR(255) NULL, assinatura VARCHAR(255) NULL, logo_nome TINYINT(1) NULL, cor1 VARCHAR(7) NULL, cor2 VARCHAR(7) NULL,
        atualizado_em DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", [], $db);
    // tabela criada na versão anterior: acrescenta "a logo já tem o nome da empresa" (sem permissão para alterar, o resto segue funcionando)
    try {
        if (!vn_marca_coluna('logo_nome', $db)) vn_marca_x('ALTER TABLE vn_pdf_marca ADD COLUMN logo_nome TINYINT(1) NULL', [], $db);
    } catch (Throwable $e) { /* segue sem a coluna */ }
    $feito = true;
}

/** A tabela vn_pdf_marca tem esta coluna? (consulta guardada na memória durante a requisição) */
function vn_marca_coluna(string $nome, $db = null): bool {
    static $cache = [];
    if (isset($cache[$nome]) && $cache[$nome]) return true;
    $r = vn_marca_q("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vn_pdf_marca' AND COLUMN_NAME = ? LIMIT 1", [$nome], $db);
    return $cache[$nome] = (bool)$r;
}

/* ---------- marca ---------- */

function vn_marca_linhas(): array { return ['cftv' => 'VN System (CFTV)', 'serralheria' => 'Expresso Serralheria']; }
function vn_marca_linha_valida($l): string { return strtolower(trim((string)$l)) === 'serralheria' ? 'serralheria' : 'cftv'; }

/** O que vale enquanto ninguém salvou a marca em "Marca dos PDFs". */
function vn_marca_padrao(string $linha): array {
    if ($linha === 'serralheria') {
        // Logo (recortada do material da própria Expresso) e frase vêm do material enviado; CNPJ, telefone e endereço não são inventados: entram em "Marca dos PDFs".
        return ['nome' => 'Expresso Serralheria', 'slogan' => 'Soluções inteligentes em segurança', 'cnpj' => '', 'telefone' => '', 'email' => '', 'site' => '', 'endereco' => '', 'rodape' => '',
            'logo' => 'imagens/expresso-serralheria.png', 'assinatura' => '', 'logo_nome' => 1, 'cor1' => '#0c0c0e', 'cor2' => '#e0ac2b'];
    }
    return ['nome' => 'VN System', 'slogan' => 'Manutenções e Instalações', 'cnpj' => '58.181.863/0001-92', 'telefone' => '(11) 96919-0724', 'email' => '', 'site' => '',
        'endereco' => 'Rua Ana Maria, 46 - São Bernardo do Campo/SP - CEP 09669-060', 'rodape' => '',
        'logo' => 'vn-orcamento.png', 'assinatura' => 'assinatura-vnsystem.jpg', 'logo_nome' => 0, 'cor1' => '#1d4ed8', 'cor2' => '#0ea5e9'];
}

/** Caminho (relativo ao site) → arquivo no disco, só dentro do site e só imagem. */
function vn_marca_arquivo(string $rel): string {
    $rel = ltrim(str_replace('\\', '/', trim($rel)), '/');
    if ($rel === '' || strpos($rel, '..') !== false) return '';
    if (!preg_match('/\.(png|jpe?g|webp|gif)$/i', $rel)) return '';
    $f = __DIR__ . '/' . $rel;
    return is_file($f) ? $f : '';
}

/**
 * Marca de uma linha ('cftv'|'serralheria'), já com arquivo da logo resolvido e cores válidas.
 * Logo e assinatura: vazio = vale a padrão; "-" = a pessoa tirou a imagem de propósito (fica sem).
 */
function vn_marca_get(string $linha = 'cftv', $db = null): array {
    $linha = vn_marca_linha_valida($linha);
    $b = vn_marca_padrao($linha);
    $b['salvo'] = false;
    try {
        vn_marca_tabela($db);
        $rows = vn_marca_q('SELECT * FROM vn_pdf_marca WHERE linha = ? LIMIT 1', [$linha], $db);
        if ($rows) {
            foreach (array_keys($b) as $k) if (array_key_exists($k, $rows[0]) && $rows[0][$k] !== null) $b[$k] = (string)$rows[0][$k];
            $b['salvo'] = true;
        }
    } catch (Throwable $e) { /* sem banco/tabela: segue com a marca padrão */ }
    $pad = vn_marca_padrao($linha);
    foreach (['logo', 'assinatura'] as $k) {
        if (trim((string)$b[$k]) === '') $b[$k] = $pad[$k];
        elseif (trim((string)$b[$k]) === '-') $b[$k] = '';
    }
    foreach (['cor1', 'cor2'] as $k) if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string)$b[$k])) $b[$k] = $pad[$k];
    // cores que a versão anterior usava como padrão da Serralheria (grafite e laranja), nunca escolhidas por ninguém: passam para as da logo
    if ($linha === 'serralheria' && strtolower((string)$b['cor1']) === '#1f2933' && strtolower((string)$b['cor2']) === '#f97316') { $b['cor1'] = $pad['cor1']; $b['cor2'] = $pad['cor2']; }
    $b['logo_nome'] = (int)$b['logo_nome'];
    $b['linha'] = $linha;
    $b['logo_file'] = vn_marca_arquivo((string)$b['logo']);
    if ($b['logo_file'] === '' && !$b['salvo'] && $linha === 'cftv') $b['logo_file'] = vn_marca_arquivo('vn.png');
    $b['assinatura_file'] = vn_marca_arquivo((string)$b['assinatura']);
    return $b;
}

/** Cores da marca para usar no PDF: principal (faixas, títulos), destaque e tons claros derivados. */
function vn_pdf_tema(string $c1, string $c2): array {
    $a = vn_pdf_rgb($c1); $b = vn_pdf_rgb($c2);
    for ($g = 0; $g < 40 && vn_pdf_contraste($a, [255, 255, 255]) < 4.5; $g++) $a = vn_pdf_mix($a, [0, 0, 0], 0.07);   // texto branco precisa ler bem
    return [
        'c1' => vn_pdf_hex($a), 'c2' => vn_pdf_hex($b),
        'c1b' => vn_pdf_hex(vn_pdf_mix($a, [255, 255, 255], 0.09)),
        'c1d' => vn_pdf_hex(vn_pdf_mix($a, [0, 0, 0], 0.25)),
        'soft' => vn_pdf_hex(vn_pdf_mix($a, [255, 255, 255], 0.945)),
        'soft2' => vn_pdf_hex(vn_pdf_mix($a, [255, 255, 255], 0.86)),
        'tint' => vn_pdf_hex(vn_pdf_mix($a, [255, 255, 255], 0.72)),
        'line' => '#dde3ea', 'ink' => vn_pdf_hex(vn_pdf_mix($a, [15, 23, 42], 0.84)), 'muted' => '#64748b',
        'on2' => vn_pdf_contraste($b, [255, 255, 255]) >= 3.2 ? '#ffffff' : '#111827',
    ];
}

/**
 * Descobre as cores de uma logo: uma principal escura o bastante para fundo de texto branco e uma de destaque.
 * Ignora fundo branco/transparente e usa a cor que mais aparece (não a média dos degradês).
 * Devolve ['c1'=>'#rrggbb','c2'=>'#rrggbb'] ou null.
 */
function vn_marca_cores_logo(string $file): ?array {
    if (!is_file($file) || !function_exists('imagecreatefromstring')) return null;
    $bin = @file_get_contents($file);
    $im = $bin ? @imagecreatefromstring($bin) : false;
    if (!$im) return null;
    $w = imagesx($im); $h = imagesy($im);
    $k = min(1, 72 / max($w, $h, 1));
    $nw = max(1, (int)round($w * $k)); $nh = max(1, (int)round($h * $k));
    $sm = imagecreatetruecolor($nw, $nh);
    imagealphablending($sm, false); imagesavealpha($sm, true);
    imagefilledrectangle($sm, 0, 0, $nw, $nh, imagecolorallocatealpha($sm, 255, 255, 255, 127));
    imagecopyresampled($sm, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($im);

    // grupos: 12 matizes (cores vivas), escuros neutros ('d') e cinzas ('g'); dentro de cada um, as cores parecidas se juntam
    $g = [];
    $visivel = 0;
    for ($y = 0; $y < $nh; $y++) {
        for ($x = 0; $x < $nw; $x++) {
            $c = imagecolorat($sm, $x, $y);
            if ((($c >> 24) & 0x7F) > 70) continue;                   // transparente
            $r = ($c >> 16) & 255; $gg = ($c >> 8) & 255; $b = $c & 255;
            [$hh, $s, $l] = vn_pdf_rgb_hsl([$r, $gg, $b]);
            if ($l > 0.92) continue;                                  // branco / brilho
            $visivel++;
            if ($s >= 0.30 && $l >= 0.12 && $l <= 0.88) $grupo = 'h' . ((int)floor(fmod($hh + 15, 360) / 30) % 12);
            elseif ($s < 0.30 && $l < 0.34) $grupo = 'd';
            elseif ($s < 0.30 && $l <= 0.80) $grupo = 'g';
            else continue;
            $q = (($r >> 4) << 8) | (($gg >> 4) << 4) | ($b >> 4);
            if (!isset($g[$grupo])) $g[$grupo] = ['n' => 0, 'sub' => []];
            $g[$grupo]['n']++;
            if (!isset($g[$grupo]['sub'][$q])) $g[$grupo]['sub'][$q] = ['n' => 0, 'r' => 0, 'g' => 0, 'b' => 0];
            $g[$grupo]['sub'][$q]['n']++; $g[$grupo]['sub'][$q]['r'] += $r; $g[$grupo]['sub'][$q]['g'] += $gg; $g[$grupo]['sub'][$q]['b'] += $b;
        }
    }
    imagedestroy($sm);
    if ($visivel < 12) return null;
    $min = max(6, (int)round($visivel * 0.03));
    $rep = function ($grp) {                                           // a cor mais comum do grupo
        $best = null;
        foreach ($grp['sub'] as $sb) if ($best === null || $sb['n'] > $best['n']) $best = $sb;
        return [$best['r'] / $best['n'], $best['g'] / $best['n'], $best['b'] / $best['n']];
    };
    $vivas = [];
    foreach ($g as $nome => $grp) if ($nome[0] === 'h' && $grp['n'] >= $min) $vivas[] = ['n' => $grp['n'], 'c' => $rep($grp), 'h' => ((int)substr($nome, 1)) * 30];
    usort($vivas, function ($a, $b) { return $b['n'] <=> $a['n']; });
    $P = $vivas[0] ?? null; $S = null;
    if ($P) foreach ($vivas as $c) {
        $d = abs($c['h'] - $P['h']); if ($d > 180) $d = 360 - $d;
        if ($d >= 45 && $c['n'] >= $P['n'] * 0.12) { $S = $c; break; }
    }
    $N = (isset($g['d']) && $g['d']['n'] >= $min) ? $rep($g['d']) : null;
    $G = (isset($g['g']) && $g['g']['n'] >= $min) ? $rep($g['g']) : null;

    $branco = [255, 255, 255];
    $ordem = array_values(array_filter([$P ? $P['c'] : null, $S ? $S['c'] : null, $N, $G]));
    if (!$ordem) return null;
    // principal: a primeira cor da logo que serve de fundo para texto branco, nem que seja escurecendo um pouco (azul escuro ainda é azul;
    // amarelo escurecido vira marrom, então nesse caso a principal passa a ser o preto/cinza-escuro da logo e o amarelo vira destaque)
    $escurecer = function ($c) use ($branco) {
        for ($t = 0.0; $t <= 0.381; $t += 0.04) { $x = vn_pdf_mix($c, [0, 0, 0], $t); if (vn_pdf_contraste($x, $branco) >= 4.5) return $x; }
        return null;
    };
    // cor clara (dourado, amarelo, laranja, prata) é ótima de destaque, mas ruim de fundo para texto branco: não serve de principal
    $claro = function ($c) { return vn_pdf_lum($c) >= 0.28; };
    $c1 = null;
    foreach ($ordem as $c) { if ($claro($c)) continue; $x = $escurecer($c); if ($x) { $c1 = $x; break; } }
    if ($c1 === null) {
        // só cores claras (ex.: logo dourada com fundo transparente): faixa quase preta, com um leve tom da cor da logo
        [$hh, $s, $l] = vn_pdf_rgb_hsl($P ? $P['c'] : $ordem[0]);
        $c1 = vn_pdf_hsl_rgb($hh, min(0.30, $s * 0.35), 0.07);
    }

    // destaque: outra cor viva da logo; senão uma versão mais clara da principal; logo só preta/cinza: o cinza
    $c2 = null;
    $longe = function ($a, $b) { return (abs($a[0] - $b[0]) + abs($a[1] - $b[1]) + abs($a[2] - $b[2])) > 120; };
    if ($S && $longe($S['c'], $c1)) $c2 = $S['c'];
    elseif ($P && $longe($P['c'], $c1)) $c2 = $P['c'];
    if ($c2 === null) {
        [$hh, $s, $l] = vn_pdf_rgb_hsl($c1);
        if ($s >= 0.30) $c2 = vn_pdf_hsl_rgb($hh, max(0.55, min(0.95, $s + 0.15)), max(0.5, min(0.62, $l + 0.2)));
        elseif ($G && $longe($G, $c1)) $c2 = $G;
        else $c2 = vn_pdf_mix($c1, [255, 255, 255], 0.55);
    }
    return ['c1' => vn_pdf_hex($c1), 'c2' => vn_pdf_hex($c2)];
}

/** Luminância média (0..1) dos pixels visíveis da logo, ignorando fundo transparente e branco puro; null se não deu para ler. */
function vn_marca_logo_luminancia(string $file): ?float {
    if (!is_file($file) || !function_exists('imagecreatefromstring')) return null;
    $bin = @file_get_contents($file);
    $im = $bin ? @imagecreatefromstring($bin) : false;
    if (!$im) return null;
    $w = imagesx($im); $h = imagesy($im);
    $k = min(1, 64 / max($w, $h, 1));
    $nw = max(1, (int)round($w * $k)); $nh = max(1, (int)round($h * $k));
    $sm = imagecreatetruecolor($nw, $nh);
    imagealphablending($sm, false); imagesavealpha($sm, true);
    imagefilledrectangle($sm, 0, 0, $nw, $nh, imagecolorallocatealpha($sm, 255, 255, 255, 127));
    imagecopyresampled($sm, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($im);
    $soma = 0.0; $n = 0;
    for ($y = 0; $y < $nh; $y++) {
        for ($x = 0; $x < $nw; $x++) {
            $c = imagecolorat($sm, $x, $y);
            if ((($c >> 24) & 0x7F) > 70) continue;
            $r = ($c >> 16) & 255; $g = ($c >> 8) & 255; $b = $c & 255;
            if ($r > 240 && $g > 240 && $b > 240) continue;
            $soma += vn_pdf_lum([$r, $g, $b]); $n++;
        }
    }
    imagedestroy($sm);
    return $n >= 12 ? $soma / $n : null;
}

/**
 * A logo aparece melhor direto na faixa colorida (sem o quadrado branco)? Vale para logo clara (dourada, prateada, branca)
 * sobre faixa escura: sobre o quadrado branco ela quase some. Logo escura ou colorida continua no quadrado branco.
 */
function vn_marca_logo_sobre_escuro(string $file, string $c1): bool {
    $l = vn_marca_logo_luminancia($file);
    if ($l === null) return false;
    $f = vn_pdf_lum(vn_pdf_tema($c1, $c1)['c1']);
    $contrasteFaixa = (max($l, $f) + 0.05) / (min($l, $f) + 0.05);
    $contrasteBranco = 1.05 / ($l + 0.05);
    return $contrasteFaixa > $contrasteBranco * 1.15;
}

function vn_pdf_iniciais(string $nome): string {
    $p = preg_split('/\s+/u', trim($nome), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $o = '';
    foreach (array_slice($p, 0, 2) as $w) $o .= function_exists('mb_substr') ? mb_substr($w, 0, 1, 'UTF-8') : substr($w, 0, 1);
    return vn_pdf_upper($o !== '' ? $o : '?');
}

/** [cor do texto, cor do fundo] para o selo de status da OS. */
function vn_pdf_status_cor(string $s): array {
    $t = strtolower($s);
    if (strpos($t, 'final') !== false || strpos($t, 'conclu') !== false) return ['#166534', '#dcfce7'];
    if (strpos($t, 'andamento') !== false || strpos($t, 'rota') !== false) return ['#92400e', '#fef3c7'];
    if (strpos($t, 'n') === 0 && strpos($t, 'realiz') !== false || strpos($t, 'cancel') !== false) return ['#991b1b', '#fee2e2'];
    if (strpos($t, 'pendente') !== false || strpos($t, 'aberta') !== false) return ['#1e40af', '#dbeafe'];
    return ['#334155', '#e2e8f0'];
}

/* =========================================================================
   Documento com a identidade da marca
   ========================================================================= */
class VNPdfDoc extends VNPdf {
    public $t;
    public $b;
    public $doc;
    protected $logoImg = null;
    protected $assImg = null;
    protected $logoDireto = false;   // logo clara: desenhada direto na faixa, sem o quadrado branco
    protected $logoNome = false;     // a logo já traz o nome da empresa: o nome não se repete ao lado dela
    protected $pend = null;          // título de seção à espera do primeiro bloco

    /**
     * $brand: vn_marca_get(). $doc: titulo (ex.: "Orçamento"), numero, sub (linha extra), data, selo (etiqueta destacada, ex.: "Válido até 20/12/2026").
     */
    public function __construct(array $brand, array $doc = []) {
        $this->b = $brand;
        $this->t = vn_pdf_tema((string)$brand['cor1'], (string)$brand['cor2']);
        $this->doc = $doc + ['titulo' => '', 'numero' => '', 'sub' => '', 'data' => '', 'selo' => ''];
        parent::__construct(['title' => trim($this->doc['titulo'] . ' ' . $this->doc['numero']), 'author' => (string)$brand['nome'], 'subject' => (string)$brand['nome']]);
        $this->mB = 56;
        if (!empty($brand['logo_file'])) $this->logoImg = $this->addImage($brand['logo_file'], ['max' => 520, 'trim' => true]);
        if ($this->logoImg) {
            $this->logoDireto = vn_marca_logo_sobre_escuro((string)$brand['logo_file'], (string)$this->t['c1']);
            $this->logoNome = !empty($brand['logo_nome']);
        }
        $this->setPageHook([$this, 'hookPagina']);
        $this->setFooterHook([$this, 'hookRodape']);
    }
    public function logo(): ?array { return $this->logoImg; }

    /* ---------- cabeçalho e rodapé ---------- */

    public function hookPagina($pdf, int $n): void {
        $t = $this->t; $W = self::PW;
        if ($n > 1) {
            $this->rect(0, 0, $W, 30, $t['c1']);
            $this->rect(0, 30, $W, 2.5, $t['c2']);
            $this->text($this->mL, 19.5, $this->fit(vn_pdf_upper((string)$this->b['nome']), 230, 8.5, 'B', 0.8), 8.5, 'B', '#ffffff', 'L', 0.8);
            $dir = trim($this->doc['titulo'] . '  ' . $this->doc['numero']);
            $this->text($W - $this->mR, 19.5, $this->fit($dir, 260, 8.5, 'B'), 8.5, 'B', '#ffffff', 'R');
            $this->y = 50;
            return;
        }
        if ($this->logoImg && $this->logoDireto && $this->logoNome) $this->headerLogoLarga();
        else $this->headerPadrao();
    }

    /** Faixa colorida do topo: painel diagonal mais claro à direita e filete de destaque embaixo. */
    protected function faixa(float $H): void {
        $t = $this->t; $W = self::PW;
        $this->rect(0, 0, $W, $H, $t['c1']);
        $this->poly([[$W - 250, 0], [$W, 0], [$W, $H], [$W - 305, $H]], $t['c1b']);
        $this->rect(0, $H, $W, 4.5, $t['c2']);
    }

    /** Cor dos textos pequenos sobre a faixa. */
    protected function corSuave(): string { return vn_pdf_hex(vn_pdf_mix($this->t['c1'], '#ffffff', 0.82)); }

    /**
     * Bloco da direita do cabeçalho: título do documento, número, data e selo de validade. Desenha e devolve a largura usada,
     * para o lado esquerdo (nome, contatos) não encostar nele. $selTopo = altura em que o selo é desenhado.
     */
    protected function blocoDireita(float $selTopo): float {
        $t = $this->t; $rx = self::PW - $this->mR; $soft = $this->corSuave();
        $tit = vn_pdf_upper(trim((string)$this->doc['titulo']));
        $ts = $this->fitSize($tit, 200, 20, 'B', 11);
        $numTxt = trim((string)$this->doc['numero']) !== '' ? $this->fit('Nº ' . $this->doc['numero'], 215, 10.5, 'B') : '';
        $extra = array_filter([trim((string)$this->doc['sub']), trim((string)$this->doc['data'])]);
        $extraTxt = $extra ? $this->fit(implode('  ·  ', $extra), 230, 8.4, 'R') : '';
        $sel = trim((string)$this->doc['selo']);
        $selW = $sel !== '' ? $this->width($sel, 8, 'B') + 18 : 0;
        $rw = max($this->width($tit, $ts, 'B') + 0.4 * strlen($this->enc($tit)), $numTxt !== '' ? $this->width($numTxt, 10.5, 'B') : 0,
            $extraTxt !== '' ? $this->width($extraTxt, 8.4, 'R') : 0, $selW);
        $yy = 33;
        $this->text($rx, $yy, $tit, $ts, 'B', '#ffffff', 'R', 0.4);
        if ($numTxt !== '') { $yy += 18; $this->text($rx, $yy, $numTxt, 10.5, 'B', vn_pdf_hex(vn_pdf_mix('#ffffff', $t['c1'], 0.08)), 'R'); }
        if ($extraTxt !== '') { $yy += 14; $this->text($rx, $yy, $extraTxt, 8.4, 'R', $soft, 'R'); }
        if ($sel !== '') {
            $this->rrect($rx - $selW, $selTopo, $selW, 18, 9, $t['c2']);
            $this->text($rx - $selW / 2, $this->mid($selTopo + 9, 8), $sel, 8, 'B', $t['on2'], 'C');
        }
        return $rw;
    }

    /** Cabeçalho comum: logo (em quadrado branco, ou direto na faixa se for clara), nome da empresa e contatos. */
    protected function headerPadrao(): void {
        $t = $this->t; $W = self::PW; $H = 112; $rx = $W - $this->mR;
        $this->faixa($H);

        $bs = 66; $bx = $this->mL; $by = ($H - $bs) / 2;
        if ($this->logoImg && $this->logoDireto) {
            $larg = $this->imageFit($this->logoImg, $bx, $by, 112, $bs, 0, 'L', 'C')[2];
        } else {
            // selo da logo: quadrado branco com a logo (ou as iniciais da marca)
            $this->rrect($bx, $by, $bs, $bs, 11, '#ffffff');
            if ($this->logoImg) $this->imageFit($this->logoImg, $bx + 6, $by + 6, $bs - 12, $bs - 12);
            else $this->text($bx + $bs / 2, $this->mid($by + $bs / 2, 24), vn_pdf_iniciais((string)$this->b['nome']), 24, 'B', $t['c1'], 'C');
            $larg = $bs;
        }

        // bloco da direita primeiro, para o nome da empresa não encostar nele
        $rw = $this->blocoDireita($H - 29);
        $selW = trim((string)$this->doc['selo']) !== '' ? $this->width(trim((string)$this->doc['selo']), 8, 'B') + 18 : 0;

        // nome e contatos (à esquerda, entre a logo e o bloco da direita)
        $nx = $bx + $larg + 16;
        $nw = max(150, min(236, ($rx - $rw) - 22 - $nx));
        $nome = vn_pdf_upper(trim((string)$this->b['nome']));
        // o nome usa o maior tamanho que caiba em até 2 linhas
        $linhas = null; $ns = 16;
        foreach ([16, 14.5, 13, 12, 11] as $tam) {
            $l = $this->wrap($nome, $nw, $tam, 'B', 0.5);
            if (count($l) <= 2) { $ns = $tam; $linhas = $l; break; }
        }
        if ($linhas === null) {
            $ns = 11; $l = $this->wrap($nome, $nw, 11, 'B', 0.5);
            $linhas = [$l[0], $this->fit(implode(' ', array_slice($l, 1)), $nw, 11, 'B', 0.5)];
        }
        $info = [];
        if (trim((string)$this->b['slogan']) !== '') $info[] = [$this->fit((string)$this->b['slogan'], $nw, 8.4, 'I'), 'I', 8.4, 12.5];
        $contato = [];
        if (trim((string)$this->b['cnpj']) !== '') $contato[] = 'CNPJ ' . trim((string)$this->b['cnpj']);
        if (trim((string)$this->b['telefone']) !== '') $contato[] = trim((string)$this->b['telefone']);
        if ($contato) $info[] = [$this->fit(implode('  ·  ', $contato), $nw, 7.8, 'R'), 'R', 7.8, 11.2];
        $mail = array_filter([trim((string)$this->b['email']), trim((string)$this->b['site'])]);
        if ($mail) $info[] = [$this->fit(implode('  ·  ', $mail), $nw, 7.8, 'R'), 'R', 7.8, 11.2];
        // o endereço fica na parte de baixo da faixa, onde o bloco da direita só tem a etiqueta de validade: pode ser mais largo
        $aw = max($nw, min(300, ($rx - max($selW, 120)) - 22 - $nx));
        if (trim((string)$this->b['endereco']) !== '') foreach (array_slice($this->wrap((string)$this->b['endereco'], $aw, 7.4, 'R'), 0, 2) as $ln) $info[] = [$ln, 'R', 7.4, 10.4];
        $alt = count($linhas) * ($ns + 3.5); foreach ($info as $i) $alt += $i[3];
        $y0 = max(10, ($H - $alt) / 2);
        foreach ($linhas as $ln) { $this->text($nx, $y0 + $ns * 0.86, $ln, $ns, 'B', '#ffffff', 'L', 0.5); $y0 += $ns + 3.5; }
        $y0 += 1;
        $soft = $this->corSuave();
        foreach ($info as $i) { $this->text($nx, $y0 + $i[2] * 0.88, $i[0], $i[2], $i[1], $soft); $y0 += $i[3]; }
        $this->y = $H + 24;
    }

    /**
     * Cabeçalho para logo larga que já traz o nome (ex.: ícone + "EXPRESSO SERRALHERIA"): a logo vai grande, direto na faixa,
     * a frase da empresa ao lado (na cor de destaque) e os contatos numa linha embaixo.
     */
    protected function headerLogoLarga(): void {
        $t = $this->t; $W = self::PW; $rx = $W - $this->mR; $soft = $this->corSuave();
        $partes = [];
        if (trim((string)$this->b['cnpj']) !== '') $partes[] = 'CNPJ ' . trim((string)$this->b['cnpj']);
        foreach (['telefone', 'email', 'site', 'endereco'] as $k) if (trim((string)$this->b[$k]) !== '') $partes[] = trim((string)$this->b[$k]);
        $linhasContato = $partes ? array_slice($this->wrap(implode('  ·  ', $partes), $this->contentWidth(), 7.8, 'R'), 0, 2) : [];
        $topo = 118;
        $H = $topo + ($linhasContato ? count($linhasContato) * 11.4 + 16 : 0);
        $this->faixa($H);

        $by = 11; $alturaLogo = $topo - 22;
        $r = $this->imageFit($this->logoImg, $this->mL, $by, 250, $alturaLogo, 0, 'L', 'C');
        $rw = $this->blocoDireita($topo - 30);

        // frase da empresa ao lado da logo, na cor de destaque
        $sx = $r[0] + $r[2] + 20; $sw = ($rx - $rw) - 18 - $sx;
        $frase = trim((string)$this->b['slogan']);
        if ($frase !== '' && $sw > 70) {
            $ls = array_slice($this->wrap(vn_pdf_upper($frase), $sw, 7.8, 'B', 0.9), 0, 3);
            $yy = $by + $alturaLogo / 2 - count($ls) * 5.8;
            foreach ($ls as $ln) { $this->text($sx, $yy + 9, $ln, 7.8, 'B', $t['c2'], 'L', 0.9); $yy += 11.6; }
        }

        // contatos numa linha (ou duas) no pé da faixa
        if ($linhasContato) {
            $this->line($this->mL, $topo + 2, $W - $this->mR, $topo + 2, vn_pdf_hex(vn_pdf_mix($t['c1'], '#ffffff', 0.2)), 0.6);
            $yy = $topo + 15;
            foreach ($linhasContato as $ln) { $this->text($this->mL, $yy, $ln, 7.8, 'R', $soft); $yy += 11.4; }
        }
        $this->y = $H + 24;
    }

    public function hookRodape($pdf, int $n, int $total): void {
        $t = $this->t; $W = self::PW; $y = self::PH - 42;
        $this->line($this->mL, $y, $W - $this->mR, $y, $t['line'], 0.7);
        $esq = array_filter([trim((string)$this->b['nome']), trim((string)$this->b['telefone']), trim((string)$this->b['email']), trim((string)$this->b['site'])]);
        $this->text($this->mL, $y + 14, $this->fit(implode('  ·  ', $esq), 400, 7.8, 'R'), 7.8, 'R', $t['muted']);
        $this->text($W - $this->mR, $y + 14, 'Página ' . $n . ' de ' . $total, 7.8, 'B', $t['muted'], 'R');
        if (trim((string)$this->b['rodape']) !== '') $this->text($this->mL, $y + 26, $this->fit((string)$this->b['rodape'], $this->contentWidth(), 7.4, 'I'), 7.4, 'I', $t['muted']);
    }

    /* ---------- blocos ---------- */

    /**
     * Título de seção (barra de destaque + texto em maiúsculas + filete). Só é desenhado junto do primeiro bloco que vier depois:
     * se o bloco não couber na página, o título vai com ele para a página seguinte em vez de ficar sozinho no pé da página.
     */
    public function section(string $title, float $espera = 0): void {
        $this->pend = $title;
    }

    /** Garante $h pontos para o próximo bloco (mais o título da seção, se houver um esperando) e desenha o título. */
    public function need(float $h): void {
        if ($this->cur < 0) $this->addPage();
        $this->ensure(($this->pend !== null ? 34 : 0) + $h);
        if ($this->pend === null) return;
        $title = $this->pend; $this->pend = null;
        $t = $this->t; $y = $this->y + 4;
        $this->rrect($this->mL, $y, 4, 16, 2, $t['c2']);
        $w = $this->text($this->mL + 12, $y + 12, vn_pdf_upper($title), 10, 'B', $t['c1'], 'L', 0.7);
        $this->line($this->mL + 12 + $w + 10, $y + 8.5, self::PW - $this->mR, $y + 8.5, $t['line'], 0.7);
        $this->y = $y + 26;
    }

    /**
     * Cartões com rótulo e valor, em $cols colunas. Cada célula: ['l'=>rótulo, 'v'=>valor, 'span'=>colunas, 'style'=>'R'|'B', 'size'=>10, 'color'=>cor, 'dot'=>cor]
     */
    public function fields(array $cells, int $cols = 2): void {
        if (!$cells) { $this->pend = null; return; }
        $gap = 8; $t = $this->t;
        $cw = ($this->contentWidth() - $gap * ($cols - 1)) / $cols;
        $rows = []; $row = []; $used = 0;
        foreach ($cells as $c) {
            $span = max(1, min($cols, (int)($c['span'] ?? 1)));
            if ($used + $span > $cols) { $rows[] = $row; $row = []; $used = 0; }
            $row[] = [$c, $span]; $used += $span;
            if ($used >= $cols) { $rows[] = $row; $row = []; $used = 0; }
        }
        if ($row) $rows[] = $row;
        foreach ($rows as $row) {
            $rh = 0; $m = []; $x = $this->mL;
            foreach ($row as $cs) {
                $c = $cs[0]; $span = $cs[1];
                $w = $cw * $span + $gap * ($span - 1);
                $size = (float)($c['size'] ?? 10); $style = (string)($c['style'] ?? 'R');
                $val = trim((string)($c['v'] ?? '')); if ($val === '') $val = '—';
                $pad = !empty($c['dot']) ? 12 : 0;
                $lines = $this->wrap($val, $w - 22 - $pad, $size, $style);
                $lead = round($size * 1.34, 2);
                $m[] = [$x, $w, $lines, $size, $style, $lead, $c, $pad];
                $rh = max($rh, 22 + count($lines) * $lead + 5);
                $x += $w + $gap;
            }
            $this->need($rh + $gap);
            foreach ($m as $q) {
                [$x, $w, $lines, $size, $style, $lead, $c, $pad] = $q;
                $this->rrect($x, $this->y, $w, $rh, 6, $t['soft'], $t['line'], 0.6);
                $this->text($x + 11, $this->y + 14.5, vn_pdf_upper((string)($c['l'] ?? '')), 6.8, 'B', $t['muted'], 'L', 0.7);
                $col = $c['color'] ?? $t['ink'];
                if (!empty($c['dot'])) $this->circle($x + 14.5, $this->y + 22 + $lead / 2 - 0.5, 3.1, $c['dot']);
                $this->para($x + 11 + $pad, $this->y + 20, $w - 22 - $pad, implode("\n", $lines), $size, $style, $col, $lead);
            }
            $this->y += $rh + $gap;
        }
    }

    /**
     * Tabela com cabeçalho que se repete a cada página.
     * $cols: [['h'=>'Descrição','w'=>'*'|pontos,'a'=>'L'|'C'|'R'], ...]
     * $rows: lista de linhas; célula = texto ou ['t'=>texto,'b'=>true,'c'=>cor,'sub'=>'texto menor']; linha de grupo = ['_group'=>'Título'].
     */
    public function table(array $cols, array $rows, array $o = []): void {
        $t = $this->t; $size = (float)($o['size'] ?? 9); $lead = round($size * 1.32, 2); $py = 6.5; $hh = 23;
        $fixo = 0; $flex = 0;
        foreach ($cols as $c) { if ($c['w'] === '*') $flex++; else $fixo += $c['w']; }
        $fw = $flex ? max(50, ($this->contentWidth() - $fixo) / $flex) : 0;
        $xs = []; $x = $this->mL;
        foreach ($cols as $i => $c) { $w = $c['w'] === '*' ? $fw : (float)$c['w']; $xs[$i] = [$x, $w]; $x += $w; }
        $cabeca = function () use ($cols, $xs, $t, $hh) {
            $this->rrect($this->mL, $this->y, $this->contentWidth(), $hh, 5, $t['c1'], null, 0.6, 'tl tr');
            foreach ($cols as $i => $c) {
                [$x, $w] = $xs[$i]; $a = $c['a'] ?? 'L';
                $pc = (float)($c['p'] ?? 7);
                $tx = $a === 'R' ? $x + $w - $pc : ($a === 'C' ? $x + $w / 2 : $x + $pc);
                $this->text($tx, $this->mid($this->y + $hh / 2, 7.6), vn_pdf_upper((string)$c['h']), 7.6, 'B', '#ffffff', $a, 0.6);
            }
            $this->y += $hh;
        };
        $this->need($hh + 34);
        $cabeca();
        $zebra = 0;
        foreach ($rows as $row) {
            if (isset($row['_group'])) {
                if ($this->y + 22 + 30 > $this->bottom()) { $this->addPage(); $cabeca(); }
                $this->rect($this->mL, $this->y, $this->contentWidth(), 21, $t['soft2']);
                $this->text($this->mL + 8, $this->mid($this->y + 10.5, 7.8), vn_pdf_upper((string)$row['_group']), 7.8, 'B', $t['c1'], 'L', 0.7);
                $this->y += 21; $zebra = 0;
                continue;
            }
            $cel = []; $rh = 0;
            foreach ($cols as $i => $c) {
                $v = $row[$i] ?? ''; if (!is_array($v)) $v = ['t' => (string)$v];
                $style = !empty($v['b']) ? 'B' : 'R';
                $w = $xs[$i][1] - 2 * (float)($c['p'] ?? 7);
                $ln = $this->wrap((string)($v['t'] ?? ''), $w, $size, $style);
                $sub = isset($v['sub']) && trim((string)$v['sub']) !== '' ? $this->wrap((string)$v['sub'], $w, $size - 1.2, 'R') : [];
                $h = count($ln) * $lead + count($sub) * ($lead - 1.4);
                $cel[$i] = [$v, $style, $ln, $sub];
                $rh = max($rh, $h);
            }
            $rh += 2 * $py;
            if ($this->y + $rh > $this->bottom()) { $this->addPage(); $cabeca(); }
            if ($zebra % 2 === 1) $this->rect($this->mL, $this->y, $this->contentWidth(), $rh, $t['soft']);
            foreach ($cols as $i => $c) {
                [$x, $w] = $xs[$i]; $a = $c['a'] ?? 'L';
                [$v, $style, $ln, $sub] = $cel[$i];
                $col = $v['c'] ?? $t['ink'];
                $yy = $this->y + $py;
                $pc = (float)($c['p'] ?? 7);
                $tx = $x + $pc; $tw = $w - 2 * $pc;
                $this->para($tx, $yy, $tw, implode("\n", $ln), $size, $style, $col, $lead, $a);
                if ($sub) $this->para($tx, $yy + count($ln) * $lead, $tw, implode("\n", $sub), $size - 1.2, 'R', $t['muted'], $lead - 1.4, $a);
            }
            $this->line($this->mL, $this->y + $rh, self::PW - $this->mR, $this->y + $rh, $t['line'], 0.5);
            $this->y += $rh; $zebra++;
        }
        $this->y += 10;
    }

    /** Quadro de totais à direita. $lines: [['rótulo','valor','neg'=>bool]]; $grand: ['TOTAL','R$ 0,00'] */
    public function totals(array $lines, ?array $grand = null, float $w = 250): void {
        $t = $this->t; $lh = 19;
        $h = count($lines) * $lh + ($lines ? 8 : 0) + ($grand ? 44 : 0);
        if ($h <= 0) { $this->pend = null; return; }
        $this->need($h + 8);
        $x = self::PW - $this->mR - $w; $y = $this->y;
        foreach ($lines as $i => $l) {
            $this->text($x + 12, $this->mid($y + $lh / 2, 9), (string)$l[0], 9, 'R', $t['muted']);
            $this->text($x + $w - 12, $this->mid($y + $lh / 2, 9.5), (string)$l[1], 9.5, 'B', !empty($l['neg']) ? '#b91c1c' : $t['ink'], 'R');
            $this->line($x, $y + $lh, $x + $w, $y + $lh, $t['line'], 0.5);
            $y += $lh;
        }
        if ($grand) {
            $y += $lines ? 8 : 0;
            $this->rrect($x, $y, $w, 38, 7, $t['c1']);
            $this->rrect($x, $y, 5, 38, 2.5, $t['c2']);
            $this->text($x + 16, $this->mid($y + 19, 8.2), vn_pdf_upper((string)$grand[0]), 8.2, 'B', vn_pdf_hex(vn_pdf_mix('#ffffff', $t['c1'], 0.1)), 'L', 0.9);
            $this->text($x + $w - 14, $this->mid($y + 19, 16.5), (string)$grand[1], 16.5, 'B', '#ffffff', 'R');
            $y += 38;
        }
        $this->y = $y + 12;
    }

    /** Texto em caixa com título. Textos longos continuam na página seguinte. */
    public function note(string $title, $text, array $o = []): void {
        $text = trim((string)$text);
        if ($text === '') { $this->pend = null; return; }
        $t = $this->t; $size = (float)($o['size'] ?? 9.4); $lead = round($size * 1.42, 2);
        $w = $this->contentWidth(); $lines = $this->wrap($text, $w - 28, $size);
        $n = count($lines); $i = 0; $first = true;
        while ($i < $n) {
            $cab = $title === '' ? 14 : 24; $pe = 10;
            $min = $cab + min($n - $i, 3) * $lead + $pe;
            $this->need($min);
            $fit = max(1, (int)floor(($this->bottom() - $this->y - $cab - $pe) / $lead));
            $parte = array_slice($lines, $i, $fit);
            $h = $cab + count($parte) * $lead + $pe;
            $this->rrect($this->mL, $this->y, $w, $h, 6, $t['soft'], $t['line'], 0.6);
            $this->rrect($this->mL, $this->y, 4.5, $h, 2.2, $t['c2']);
            if ($title !== '' || !$first) $this->text($this->mL + 16, $this->y + 15, vn_pdf_upper(($title !== '' ? $title : 'Continuação') . ($first || $title === '' ? '' : ' (continuação)')), 7.2, 'B', $t['c1'], 'L', 0.8);
            $this->para($this->mL + 16, $this->y + $cab - 3, $w - 28, implode("\n", $parte), $size, 'R', $t['ink'], $lead);
            $this->y += $h + 9;
            $i += count($parte); $first = false;
            if ($i < $n) $this->addPage();
        }
    }

    /** Altura mínima de uma linha de fotos: serve para não deixar o título da seção sozinho no fim da página. */
    public function photoRowHeight(int $cols = 2): float {
        $cw = ($this->contentWidth() - 10 * ($cols - 1)) / $cols;
        return round($cw * 0.5, 1) + 10 + 20;
    }

    /** Grade de fotos. $itens: [['src'=>caminho|data URI, 'cap'=>legenda]] */
    public function photos(array $itens, int $cols = 2): void {
        if (!$itens) { $this->pend = null; return; }
        $t = $this->t; $gap = 10;
        $cw = ($this->contentWidth() - $gap * ($cols - 1)) / $cols;
        $cap = 20;
        $carregadas = [];
        foreach ($itens as $it) {
            $img = $this->addImage($it['src'] ?? '', ['max' => 1500, 'q' => 80]);
            $carregadas[] = [$img, (string)($it['cap'] ?? '')];
        }
        for ($i = 0; $i < count($carregadas); $i += $cols) {
            $linha = array_slice($carregadas, $i, $cols);
            $precisa = 0;
            foreach ($linha as $c) $precisa = max($precisa, $c[0] ? ($cw - 10) * $c[0]['h'] / $c[0]['w'] : ($cw - 10) * 0.6);
            $ih = round(max($cw * 0.5, min($precisa, $cw * 1.0)), 1);
            $h = $ih + 10 + $cap;
            $this->need($h + $gap);
            foreach ($linha as $k => $c) {
                $x = $this->mL + $k * ($cw + $gap);
                $this->rrect($x, $this->y, $cw, $h, 7, '#ffffff', $t['line'], 0.8);
                $this->rrect($x + 5, $this->y + 5, $cw - 10, $ih, 4, $t['soft']);
                if ($c[0]) $this->imageFit($c[0], $x + 5, $this->y + 5, $cw - 10, $ih, 4);
                else $this->text($x + $cw / 2, $this->mid($this->y + 5 + $ih / 2, 9), 'Imagem indisponível', 9, 'I', $t['muted'], 'C');
                if ($c[1] !== '') $this->text($x + 10, $this->y + $ih + 10 + $cap / 2 + 2.5, $this->fit($c[1], $cw - 20, 8, 'B'), 8, 'B', $t['c1']);
            }
            $this->y += $h + $gap;
        }
    }

    /** Linhas de assinatura. $boxes: [['label'=>'Cliente','name'=>'Fulano','img'=>caminho|data URI|null]] */
    public function signatures(array $boxes): void {
        $t = $this->t; $gap = 28; $n = max(1, count($boxes));
        $w = ($this->contentWidth() - $gap * ($n - 1)) / $n;
        $temImg = false;
        foreach ($boxes as $bx) if (!empty($bx['img'])) $temImg = true;
        $topo = $temImg ? 68 : 44;                      // espaço para assinar (à mão ou com a imagem)
        $this->need($topo + 40);
        $y = $this->y + $topo;
        foreach ($boxes as $k => $bx) {
            $x = $this->mL + $k * ($w + $gap);
            if (!empty($bx['img'])) {
                $im = $this->addImage($bx['img'], ['max' => 900, 'q' => 88, 'landscape' => true]);
                if ($im) $this->imageFit($im, $x + 6, $this->y + 2, $w - 12, $topo - 6, 0, 'C', 'B');
            }
            $this->line($x, $y, $x + $w, $y, $t['c1'], 0.9);
            $this->text($x + $w / 2, $y + 13, $this->fit((string)($bx['name'] ?? ''), $w, 9.4, 'B'), 9.4, 'B', $t['ink'], 'C');
            $this->text($x + $w / 2, $y + 24.5, $this->fit((string)($bx['label'] ?? ''), $w, 7.8, 'R'), 7.8, 'R', $t['muted'], 'C');
        }
        $this->y = $y + 36;
    }
}

/* =========================================================================
   Orçamento
   ========================================================================= */

function vn_pdf_data_br($v): string {
    $v = trim((string)$v);
    if ($v === '' || strpos($v, '0000') === 0) return '';
    if (function_exists('vn_date_br')) { $r = (string)vn_date_br($v); if ($r !== '') return $r; }
    $ts = strtotime($v);
    return $ts ? date('d/m/Y', $ts) : '';
}
function vn_pdf_money($v): string {
    return function_exists('vn_money') ? (string)vn_money($v) : 'R$ ' . number_format((float)$v, 2, ',', '.');
}

/** Orçamento (array de vn_orcamento_mobile_get_v4) → bytes do PDF. A marca vem da categoria: CFTV ou Serralheria. */
function vn_pdf_orcamento_v6(array $orc, string $titulo = '', ?array $marca = null): string {
    $linha = function_exists('vn_categoria_valida') ? vn_categoria_valida($orc['categoria'] ?? 'cftv') : vn_marca_linha_valida($orc['categoria'] ?? 'cftv');
    $brand = $marca ?: vn_marca_get($linha);
    $flag = function (string $k) use ($orc): bool { return (int)($orc[$k] ?? 1) === 1; };
    $validade = vn_pdf_data_br($orc['validade'] ?? '');
    $doc = new VNPdfDoc($brand, [
        'titulo' => $titulo !== '' ? $titulo : 'Orçamento',
        'numero' => trim((string)($orc['numero'] ?? '')) !== '' ? (string)$orc['numero'] : ('#' . (int)($orc['id'] ?? 0)),
        'sub' => $linha === 'serralheria' ? 'Serralheria' : '',
        'data' => 'Emitido em ' . (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('d/m/Y'),
        'selo' => $validade !== '' ? 'Válido até ' . $validade : '',
    ]);
    $doc->addPage();
    $t = $doc->t;

    // cliente e atendimento
    $doc->section('Dados do cliente');
    $cells = [
        ['l' => 'Cliente', 'v' => $orc['cliente_nome'] ?? '', 'style' => 'B', 'size' => 10.5, 'span' => 2],
        ['l' => 'Contato', 'v' => $orc['cliente_telefone'] ?? ''],
        ['l' => 'E-mail', 'v' => $orc['cliente_email'] ?? '', 'span' => 2],
        ['l' => 'Validade', 'v' => $validade],
        ['l' => 'Endereço do atendimento', 'v' => $orc['endereco'] ?? '', 'span' => 3],
    ];
    $prazo = trim((string)($orc['prazo_execucao'] ?? ''));
    $mostraEq = $flag('mostrar_equipamento');
    $equip = $mostraEq ? trim((string)($orc['equipamento'] ?? '')) : '';
    $defeito = $mostraEq ? trim((string)($orc['defeito'] ?? '')) : '';
    if ($equip !== '') $cells[] = ['l' => 'Equipamento', 'v' => $equip, 'span' => $prazo !== '' ? 2 : 3];
    if ($prazo !== '') $cells[] = ['l' => 'Prazo de execução', 'v' => $prazo, 'span' => $equip !== '' ? 1 : 3];
    if ($defeito !== '') $cells[] = ['l' => 'Defeito relatado', 'v' => $defeito, 'span' => 3];
    $doc->fields($cells, 3);

    // itens
    $showValues = $flag('mostrar_valor_itens'); $showUnit = $flag('mostrar_valor_unitario'); $showSub = $flag('mostrar_subtotal_itens');
    $itens = $orc['itens'] ?? [];
    if ($flag('mostrar_itens')) {
        $doc->section('Itens do orçamento');
        $cols = [['h' => '#', 'w' => 28, 'a' => 'C', 'p' => 3], ['h' => 'Tipo', 'w' => 80], ['h' => 'Descrição', 'w' => '*'], ['h' => 'Qtd', 'w' => 44, 'a' => 'R']];
        if ($showValues && $showUnit) $cols[] = ['h' => 'Valor un.', 'w' => 72, 'a' => 'R'];
        if ($showValues && $showSub) $cols[] = ['h' => 'Subtotal', 'w' => 78, 'a' => 'R'];
        $rows = [];
        if (!$itens) $itens = [['tipo' => 'material', 'descricao' => 'Nenhum item informado', 'quantidade' => 0, 'valor_unitario' => 0, 'total' => 0]];
        foreach ($itens as $i => $it) {
            $q = (float)($it['quantidade'] ?? 1);
            $row = [(string)($i + 1), function_exists('vn_orc_tipo_label_v4') ? vn_orc_tipo_label_v4((string)($it['tipo'] ?? 'material')) : (string)($it['tipo'] ?? ''),
                (string)($it['descricao'] ?? ''), rtrim(rtrim(number_format($q, 2, ',', '.'), '0'), ',')];
            if ($row[3] === '') $row[3] = '0';
            if ($showValues && $showUnit) $row[] = vn_pdf_money($it['valor_unitario'] ?? 0);
            if ($showValues && $showSub) $row[] = ['t' => vn_pdf_money($it['total'] ?? 0), 'b' => true];
            $rows[] = $row;
        }
        $doc->table($cols, $rows);

        // foto do equipamento (do catálogo), quando o item é um equipamento cadastrado com foto
        $fotosEq = [];
        if (function_exists('vn_fetch_all')) {
            $catalogo = [];
            foreach (vn_fetch_all("SELECT nome, foto_url FROM vn_mobile_orcamento_catalogo WHERE foto_url IS NOT NULL AND foto_url <> ''") as $r) {
                $k = function_exists('vn_norm_key') ? vn_norm_key((string)($r['nome'] ?? '')) : strtolower((string)$r['nome']);
                if ($k !== '') $catalogo[$k] = (string)$r['foto_url'];
            }
            foreach ($itens as $it) {
                if ((string)($it['tipo'] ?? '') !== 'equipamento') continue;
                $dk = function_exists('vn_norm_key') ? vn_norm_key((string)($it['descricao'] ?? '')) : strtolower((string)($it['descricao'] ?? ''));
                foreach ($catalogo as $nk => $url) {
                    if ($nk !== '' && strpos($dk, $nk) !== false) {
                        $f = vn_marca_arquivo($url);
                        if ($f !== '') $fotosEq[] = ['src' => $f, 'cap' => (string)($it['descricao'] ?? 'Equipamento')];
                        break;
                    }
                }
            }
        }
        if ($fotosEq) { $doc->section('Fotos dos equipamentos', $doc->photoRowHeight(3) + 44); $doc->photos($fotosEq, 3); }
    }

    // valores
    $valorItens = 0.0; foreach ($orc['itens'] ?? [] as $x) $valorItens += (float)($x['total'] ?? 0);
    $linhasV = [];
    if ($showValues) $linhasV[] = ['Subtotal dos itens', vn_pdf_money($valorItens)];
    if ($flag('mostrar_visita') && (float)($orc['visita_valor'] ?? 0) != 0.0) $linhasV[] = [trim((string)($orc['visita_descricao'] ?? '')) ?: 'Visita técnica', vn_pdf_money($orc['visita_valor'])];
    if ($flag('mostrar_mao_obra') && (float)($orc['mao_obra_valor'] ?? 0) != 0.0) $linhasV[] = [trim((string)($orc['mao_obra_descricao'] ?? '')) ?: 'Mão de obra', vn_pdf_money($orc['mao_obra_valor'])];
    if ($flag('mostrar_desconto') && (float)($orc['desconto'] ?? 0) != 0.0) $linhasV[] = ['Desconto', '- ' . vn_pdf_money(abs((float)$orc['desconto'])), 'neg' => true];
    $grand = $flag('mostrar_total_geral') ? ['Total do orçamento', vn_pdf_money($orc['valor_total'] ?? 0)] : null;
    if ($linhasV || $grand) {
        $doc->section('Valores', 110);
        $doc->totals($linhasV, $grand);
    }

    // condições
    $obs = trim((string)($orc['observacoes'] ?? ''));
    if ($obs !== '') { $doc->section('Observações e condições comerciais', 80); $doc->note('', $obs); }
    $meios = trim((string)($orc['meios_pagamento'] ?? '')); $cond = trim((string)($orc['condicoes_pagamento'] ?? ''));
    if ($flag('mostrar_pagamento') && ($meios !== '' || $cond !== '')) {
        $doc->section('Pagamento', 80);
        $c = [];
        if ($meios !== '') $c[] = ['l' => 'Meios de pagamento', 'v' => $meios, 'span' => $cond !== '' ? 1 : 2];
        if ($cond !== '') $c[] = ['l' => 'Condições de pagamento', 'v' => $cond, 'span' => $meios !== '' ? 1 : 2];
        $doc->fields($c, 2);
    }
    $gar = trim((string)($orc['garantia'] ?? ''));
    if ($flag('mostrar_garantia') && $gar !== '') { $doc->section('Garantia', 80); $doc->note('', $gar); }
    $rel = trim((string)($orc['relatorio'] ?? ''));
    if ($flag('mostrar_relatorio') && $rel !== '') { $doc->section('Relatório técnico', 90); $doc->note('', $rel); }
    if ($flag('mostrar_fotos') && !empty($orc['fotos'])) {
        $fotos = [];
        foreach ($orc['fotos'] as $ft) {
            $f = vn_marca_arquivo((string)($ft['caminho'] ?? ''));
            if ($f !== '') $fotos[] = ['src' => $f, 'cap' => trim((string)($ft['legenda'] ?? ''))];
        }
        if ($fotos) { $doc->section('Fotos', $doc->photoRowHeight(2) + 44); $doc->photos($fotos, 2); }
    }
    $cla = trim((string)($orc['clausulas'] ?? ''));
    if ($flag('mostrar_clausulas') && $cla !== '') { $doc->section('Cláusulas contratuais', 90); $doc->note('', $cla, ['size' => 8.8]); }

    // assinaturas
    $doc->signatures([
        ['label' => 'Cliente / responsável', 'name' => (string)($orc['cliente_nome'] ?? '')],
        ['label' => 'Assinatura da empresa', 'name' => (string)$brand['nome'], 'img' => $brand['assinatura_file'] ?: null],
    ]);
    return $doc->output();
}


/* =========================================================================
   Ordem de serviço
   =========================================================================

   $p (todas as chaves são opcionais):
     numeroOS, cliente, tecnico, status, abertura, checkin, checkout, enderecoCliente, problema, comentario, pendencias,
     tipoServico, linha ('cftv'|'serralheria'), preventiva (bool),
     fotos [['nome'=>, 'data'=> data URI | 'file'=> caminho]], mostrarFotos (bool),
     videos [['nome'=>, 'arquivo'=>]],
     assinaturaCliente / assinaturaTecnico (data URI ou caminho), nomeClienteAss,
     servico ['descricao'=>, 'valor'=>, 'tabela'=>bool, 'total'=>bool, 'detalhes'=>bool, 'itens'=>[...], 'garantia'=>, 'condicoes'=>]
*/
function vn_pdf_os_v6(array $p, ?array $marca = null, $db = null): string {
    $linha = vn_marca_linha_valida($p['linha'] ?? 'cftv');
    $brand = $marca ?: vn_marca_get($linha, $db);
    $prev = !empty($p['preventiva']);
    $num = trim((string)($p['numeroOS'] ?? ''));
    $doc = new VNPdfDoc($brand, [
        'titulo' => 'Ordem de serviço',
        'numero' => $num,
        'sub' => $prev ? 'Manutenção preventiva' : ($linha === 'serralheria' ? 'Serralheria' : ''),
        'data' => 'Emitido em ' . (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('d/m/Y H:i'),
    ]);
    $doc->addPage();
    $t = $doc->t;
    $nao = 'Não informado';
    $v = function ($k) use ($p, $nao) { $x = trim((string)($p[$k] ?? '')); return $x !== '' ? $x : $nao; };

    $doc->section('Dados da ordem');
    [$stFg, $stBg] = vn_pdf_status_cor((string)($p['status'] ?? ''));
    $tipo = trim((string)($p['tipoServico'] ?? ''));
    $areaRot = $linha === 'serralheria' ? 'Serralheria' : 'CFTV / segurança';
    $doc->fields([
        ['l' => 'Número', 'v' => $num !== '' ? $num : $nao, 'style' => 'B'],
        ['l' => 'Status', 'v' => $v('status'), 'style' => 'B', 'color' => $stFg, 'dot' => $stFg],
        ['l' => 'Abertura', 'v' => $v('abertura'), 'size' => 9.4],
        ['l' => 'Check-in', 'v' => $v('checkin'), 'size' => 9.4],
        ['l' => 'Check-out', 'v' => $v('checkout'), 'size' => 9.4],
        ['l' => 'Técnico', 'v' => $v('tecnico')],
        ['l' => 'Tipo de serviço', 'v' => ($tipo !== '' ? $tipo . '  ·  ' : '') . $areaRot, 'span' => 2],
    ], 4);

    $doc->section('Cliente e local de atendimento');
    $doc->fields([
        ['l' => 'Cliente', 'v' => $v('cliente'), 'style' => 'B', 'size' => 10.5, 'span' => 2],
        ['l' => 'Endereço do atendimento', 'v' => $v('enderecoCliente'), 'span' => 2],
    ], 2);

    $doc->section('Serviço solicitado');
    $doc->note('Problema / serviço previsto', $v('problema'));
    $doc->note('Anotação do técnico', $v('comentario'));
    $doc->note('Pendências', $v('pendencias'));

    // bloco comercial da OS preventiva (só quando o chamador mandar)
    $sv = $p['servico'] ?? null;
    if ($prev && is_array($sv)) {
        $itens = array_values(array_filter(array_map('trim', (array)($sv['itens'] ?? [])), function ($x) { return $x !== ''; }));
        $valor = (float)($sv['valor'] ?? 0);
        $mostraTab = !empty($sv['tabela']) && $valor > 0;
        $mostraDet = !empty($sv['detalhes']) && $itens;
        if ($mostraTab || $mostraDet) {
            $doc->section('Serviços');
            if ($mostraTab) $doc->need(230);          // tabela, total e condições ficam na mesma página
            $descr = trim((string)($sv['descricao'] ?? '')) ?: 'Manutenção e suporte técnico';
            $lista = $mostraDet ? "•  " . implode("\n•  ", $itens) : '';
            if ($mostraTab) {
                $doc->table(
                    [['h' => 'Descrição', 'w' => '*'], ['h' => 'Unid.', 'w' => 44, 'a' => 'C'], ['h' => 'Preço unit.', 'w' => 86, 'a' => 'R'], ['h' => 'Qtd', 'w' => 38, 'a' => 'C'], ['h' => 'Preço', 'w' => 90, 'a' => 'R']],
                    [[['t' => $descr, 'b' => true, 'sub' => $lista], 'un.', vn_pdf_money($valor), '1', ['t' => vn_pdf_money($valor), 'b' => true]]]
                );
                if (!empty($sv['total'])) $doc->totals([], ['Total', vn_pdf_money($valor)]);
                $c = [['l' => 'Condições de pagamento', 'v' => trim((string)($sv['condicoes'] ?? '')) ?: 'À vista', 'span' => trim((string)($sv['garantia'] ?? '')) !== '' ? 1 : 2]];
                if (trim((string)($sv['garantia'] ?? '')) !== '') $c[] = ['l' => 'Garantia', 'v' => (string)$sv['garantia']];
                $doc->fields($c, 2);
            } else {
                $doc->note($descr, $lista);
            }
        }
    }

    if (!array_key_exists('mostrarFotos', $p) || $p['mostrarFotos']) {
        $doc->section('Fotos do atendimento', $doc->photoRowHeight(2) + 44);
        $fotos = [];
        foreach ((array)($p['fotos'] ?? []) as $i => $f) {
            $src = is_array($f) ? ($f['file'] ?? ($f['data'] ?? '')) : '';
            if ($src === '') continue;
            $fotos[] = ['src' => $src, 'cap' => trim((string)($f['nome'] ?? '')) ?: ('Foto ' . ($i + 1))];
        }
        if ($fotos) $doc->photos($fotos, 2);
        else $doc->note('', 'Sem fotos anexadas.');
    }

    $videos = (array)($p['videos'] ?? []);
    $doc->section('Vídeos da OS', 80);
    if ($videos) {
        $l = [];
        foreach ($videos as $i => $vd) $l[] = ($i + 1) . '.  ' . (trim((string)($vd['nome'] ?? '')) ?: ('Vídeo ' . ($i + 1)));
        $doc->note('', implode("\n", $l) . "\n\nOs vídeos ficam salvos na própria OS, no sistema.");
    } else {
        $doc->note('', 'Sem vídeos anexados.');
    }

    $doc->section('Assinaturas', 130);
    $doc->signatures([
        ['label' => 'Cliente / responsável pelo local', 'name' => trim((string)($p['nomeClienteAss'] ?? '')) ?: 'Responsável pelo local', 'img' => ($p['assinaturaCliente'] ?? '') ?: null],
        ['label' => 'Técnico responsável', 'name' => trim((string)($p['tecnico'] ?? '')) ?: 'Técnico', 'img' => ($p['assinaturaTecnico'] ?? '') ?: null],
    ]);
    return $doc->output();
}

/* =========================================================================
   Salvar a marca (página "Marca dos PDFs")
   ========================================================================= */

function vn_marca_pasta(): string { return __DIR__ . '/uploads/marca'; }

/** Cria uploads/marca com a trava que impede rodar PHP lá dentro. */
function vn_marca_pasta_pronta(): string {
    $dir = vn_marca_pasta();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) throw new Exception('Não consegui criar a pasta das imagens da marca.');
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) @file_put_contents($ht, "Options -Indexes\n<FilesMatch \"\\.(php|phtml|php[0-9]|phar|cgi|pl|asp|aspx|jsp)$\">\nRequire all denied\n</FilesMatch>\n", LOCK_EX);
    return $dir;
}

/** Apaga uma imagem antiga da marca, mas só se estiver dentro de uploads/marca (nunca o vn.png do sistema). */
function vn_marca_apagar_imagem(string $rel): void {
    $rel = ltrim(str_replace('\\', '/', $rel), '/');
    if (strpos($rel, 'uploads/marca/') !== 0 || strpos($rel, '..') !== false) return;
    $f = __DIR__ . '/' . $rel;
    if (is_file($f)) @unlink($f);
}

/**
 * Recebe o arquivo de um <input type="file"> (logo ou assinatura), confere que é imagem de verdade,
 * reduz se for enorme e guarda em uploads/marca. Devolve o caminho relativo, ou '' se nada foi enviado.
 */
function vn_marca_guardar_imagem(array $f, string $prefixo): string {
    $erro = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($erro === UPLOAD_ERR_NO_FILE) return '';
    if ($erro === UPLOAD_ERR_INI_SIZE || $erro === UPLOAD_ERR_FORM_SIZE) throw new InvalidArgumentException('A imagem passou do limite de envio do servidor. Use uma menor.');
    if ($erro !== UPLOAD_ERR_OK) throw new InvalidArgumentException('Não consegui receber a imagem. Tente de novo.');
    $tmp = (string)($f['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) throw new InvalidArgumentException('Arquivo de imagem inválido.');
    if ((int)($f['size'] ?? 0) > 8 * 1024 * 1024) throw new InvalidArgumentException('A imagem é muito grande (máximo 8 MB).');
    $info = @getimagesize($tmp);
    $mimes = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!$info || !isset($mimes[$info['mime'] ?? ''])) throw new InvalidArgumentException('Envie uma imagem PNG, JPG ou WEBP.');
    $ext = $mimes[$info['mime']];
    $dir = vn_marca_pasta_pronta();
    $nome = preg_replace('/[^a-z0-9]/', '', strtolower($prefixo)) . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
    $max = 1600;
    if (max($info[0], $info[1]) > $max && function_exists('imagecreatefromstring') && function_exists('imagecreatetruecolor')) {
        $im = @imagecreatefromstring((string)file_get_contents($tmp));
        if ($im) {
            $k = $max / max($info[0], $info[1]);
            $nw = max(1, (int)round($info[0] * $k)); $nh = max(1, (int)round($info[1] * $k));
            $dst = imagecreatetruecolor($nw, $nh);
            $png = $ext !== 'jpg';
            if ($png) { imagealphablending($dst, false); imagesavealpha($dst, true); imagefilledrectangle($dst, 0, 0, $nw, $nh, imagecolorallocatealpha($dst, 255, 255, 255, 127)); }
            imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, imagesx($im), imagesy($im));
            $arq = $dir . '/' . $nome . ($png ? '.png' : '.jpg');
            $ok = $png ? imagepng($dst, $arq, 6) : imagejpeg($dst, $arq, 88);
            imagedestroy($im); imagedestroy($dst);
            if ($ok) return 'uploads/marca/' . basename($arq);
        }
    }
    $arq = $dir . '/' . $nome . '.' . $ext;
    if (!@move_uploaded_file($tmp, $arq)) throw new InvalidArgumentException('Não consegui guardar a imagem. Confira a permissão da pasta uploads/marca.');
    return 'uploads/marca/' . basename($arq);
}

/**
 * Grava a marca de uma linha. $post = campos do formulário, $files = $_FILES.
 * Devolve a mensagem para mostrar (inclui o que aconteceu com as cores).
 */
function vn_marca_salvar(string $linha, array $post, array $files, $db = null): string {
    $linha = vn_marca_linha_valida($linha);
    vn_marca_tabela($db);
    $atual = vn_marca_get($linha, $db);
    $txt = function (string $k, int $max) use ($post): string {
        $v = trim(preg_replace('/[\r\n\t]+/', ' ', (string)($post[$k] ?? '')));
        return function_exists('mb_substr') ? mb_substr($v, 0, $max, 'UTF-8') : substr($v, 0, $max);
    };
    $d = ['nome' => $txt('nome', 120), 'slogan' => $txt('slogan', 160), 'cnpj' => $txt('cnpj', 40), 'telefone' => $txt('telefone', 60),
        'email' => $txt('email', 120), 'site' => $txt('site', 120), 'endereco' => $txt('endereco', 255), 'rodape' => $txt('rodape', 255)];
    if ($d['nome'] === '') throw new InvalidArgumentException('Informe o nome da empresa.');
    if ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('O e-mail informado não é válido.');

    $logo = (string)$atual['logo']; $assin = (string)$atual['assinatura'];
    $novoLogo = vn_marca_guardar_imagem($files['logo'] ?? [], $linha . '-logo');
    $novaAss = vn_marca_guardar_imagem($files['assinatura'] ?? [], $linha . '-ass');
    if (!empty($post['remover_logo']) && $novoLogo === '') { vn_marca_apagar_imagem($logo); $logo = ''; }
    if ($novoLogo !== '') { vn_marca_apagar_imagem($logo); $logo = $novoLogo; }
    if (!empty($post['remover_assinatura']) && $novaAss === '') { vn_marca_apagar_imagem($assin); $assin = ''; }
    if ($novaAss !== '') { vn_marca_apagar_imagem($assin); $assin = $novaAss; }

    $hex = function (string $k, string $padrao) use ($post): string {
        $v = trim((string)($post[$k] ?? ''));
        return preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtolower($v) : $padrao;
    };
    $c1 = $hex('cor1', (string)$atual['cor1']); $c2 = $hex('cor2', (string)$atual['cor2']);
    $aviso = '';
    if (!empty($post['cores_da_logo'])) {
        $arq = $logo !== '' ? vn_marca_arquivo($logo) : '';
        $cores = $arq !== '' ? vn_marca_cores_logo($arq) : null;
        if ($cores) { $c1 = $cores['c1']; $c2 = $cores['c2']; $aviso = ' As cores do PDF foram tiradas da logo.'; }
        else $aviso = $arq === '' ? ' Sem logo, as cores escolhidas foram mantidas.' : ' Não consegui tirar as cores desta logo; mantive as cores escolhidas.';
    }
    $cols = ['linha', 'nome', 'slogan', 'cnpj', 'telefone', 'email', 'site', 'endereco', 'rodape', 'logo', 'assinatura', 'cor1', 'cor2'];
    // "-" guarda que a pessoa tirou a imagem de propósito (vazio quer dizer "vale a padrão")
    $vals = [$linha, $d['nome'], $d['slogan'], $d['cnpj'], $d['telefone'], $d['email'], $d['site'], $d['endereco'], $d['rodape'], $logo !== '' ? $logo : '-', $assin !== '' ? $assin : '-', $c1, $c2];
    if (vn_marca_coluna('logo_nome', $db)) { $cols[] = 'logo_nome'; $vals[] = !empty($post['logo_nome']) ? 1 : 0; }
    vn_marca_x('REPLACE INTO vn_pdf_marca (' . implode(', ', $cols) . ', atualizado_em) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ', NOW())', $vals, $db);
    return 'Marca salva. Os próximos PDFs já saem com ela.' . $aviso;
}

/** PDF de exemplo (com dados inventados e marcado como exemplo) para ver a marca funcionando. */
function vn_pdf_exemplo(string $tipo, string $linha, $db = null): string {
    $linha = vn_marca_linha_valida($linha);
    $marca = vn_marca_get($linha, $db);
    $serr = $linha === 'serralheria';
    if ($tipo === 'os') {
        return vn_pdf_os_v6([
            'numeroOS' => 'EXEMPLO-001', 'cliente' => 'Cliente de Exemplo', 'tecnico' => 'Técnico de Exemplo', 'status' => 'Finalizada', 'linha' => $linha,
            'abertura' => date('d/m/Y') . ' às 08:00', 'checkin' => date('d/m/Y') . ' às 09:10', 'checkout' => date('d/m/Y') . ' às 11:30',
            'enderecoCliente' => 'Rua do Exemplo, 100 - Centro', 'tipoServico' => $serr ? 'Instalação' : 'Manutenção corretiva',
            'problema' => $serr ? 'Instalação de portão de correr com motor.' : 'Câmeras sem imagem à noite.',
            'comentario' => 'Serviço executado e testado com o cliente. Este é um documento de exemplo.', 'pendencias' => 'Nenhuma.',
            'fotos' => [], 'videos' => [], 'nomeClienteAss' => 'Cliente de Exemplo',
        ], $marca, $db);
    }
    $itens = $serr
        ? [['tipo' => 'material', 'descricao' => 'Portão de correr em metalon 3,00 x 2,10 m, pintura eletrostática', 'quantidade' => 1, 'valor_unitario' => 4200, 'total' => 4200],
           ['tipo' => 'material', 'descricao' => 'Motor deslizante com 2 controles', 'quantidade' => 1, 'valor_unitario' => 1450, 'total' => 1450],
           ['tipo' => 'servico', 'descricao' => 'Instalação e acabamento', 'quantidade' => 1, 'valor_unitario' => 900, 'total' => 900]]
        : [['tipo' => 'equipamento', 'descricao' => 'Câmera IP 4MP bullet com visão noturna', 'quantidade' => 4, 'valor_unitario' => 350, 'total' => 1400],
           ['tipo' => 'equipamento', 'descricao' => 'DVR 8 canais com HD 2TB', 'quantidade' => 1, 'valor_unitario' => 1290.5, 'total' => 1290.5],
           ['tipo' => 'servico', 'descricao' => 'Instalação e configuração do sistema', 'quantidade' => 1, 'valor_unitario' => 450, 'total' => 450]];
    $tot = 0.0; foreach ($itens as $i) $tot += $i['total'];
    return vn_pdf_orcamento_v6([
        'id' => 0, 'numero' => 'EXEMPLO-001', 'categoria' => $linha, 'cliente_nome' => 'Cliente de Exemplo', 'cliente_telefone' => '(11) 90000-0000',
        'cliente_email' => 'cliente@exemplo.com', 'validade' => date('Y-m-d', strtotime('+15 days')), 'endereco' => 'Rua do Exemplo, 100 - Centro',
        'prazo_execucao' => '10 dias úteis', 'itens' => $itens, 'fotos' => [], 'valor_total' => $tot, 'visita_valor' => 0, 'mao_obra_valor' => 0, 'desconto' => 0,
        'observacoes' => 'Este é um orçamento de exemplo, só para você ver como a marca aparece no PDF.',
        'meios_pagamento' => 'PIX, transferência ou cartão', 'condicoes_pagamento' => '50% na aprovação e 50% na entrega',
    ], 'Orçamento', $marca);
}
