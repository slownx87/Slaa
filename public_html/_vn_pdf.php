<?php
/* =========================================================================
   VN System — gerador de PDF (sem bibliotecas externas)

   Escreve PDF de verdade (fontes Helvetica padrão, imagens JPEG/PNG com transparência,
   várias páginas, rodapé com "Página X de Y"). Os PDFs saem como arquivo, por isso baixam
   dentro do app instalado no celular — diferente de "imprimir a página", que o Android
   manda abrir no Chrome.

   As coordenadas são em pontos (1 pt = 1/72 pol.), A4 = 595,28 x 841,89, com a origem no
   canto de CIMA à esquerda (y cresce para baixo). Texto sempre em UTF-8; a conversão para
   a codificação do PDF (WinAnsi/CP1252) é feita aqui dentro.
   ========================================================================= */

/* ---------- cores ---------- */

/** '#1d4ed8', '#fff' ou [29,78,216] → [r,g,b] inteiros 0..255. */
function vn_pdf_rgb($c): array {
    if (is_array($c)) {
        return [max(0, min(255, (int)round($c[0] ?? 0))), max(0, min(255, (int)round($c[1] ?? 0))), max(0, min(255, (int)round($c[2] ?? 0)))];
    }
    $c = ltrim(trim((string)$c), '#');
    if (strlen($c) === 3) $c = $c[0] . $c[0] . $c[1] . $c[1] . $c[2] . $c[2];
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $c)) return [0, 0, 0];
    return [hexdec(substr($c, 0, 2)), hexdec(substr($c, 2, 2)), hexdec(substr($c, 4, 2))];
}
function vn_pdf_hex($c): string {
    $c = vn_pdf_rgb($c);
    return sprintf('#%02x%02x%02x', $c[0], $c[1], $c[2]);
}
/** Mistura a com b: t=0 devolve a, t=1 devolve b. */
function vn_pdf_mix($a, $b, float $t): array {
    $a = vn_pdf_rgb($a); $b = vn_pdf_rgb($b);
    return [$a[0] + ($b[0] - $a[0]) * $t, $a[1] + ($b[1] - $a[1]) * $t, $a[2] + ($b[2] - $a[2]) * $t];
}
/** Luminância relativa (WCAG), 0 = preto, 1 = branco. */
function vn_pdf_lum($c): float {
    $c = vn_pdf_rgb($c);
    $f = function ($v) { $v /= 255; return $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4); };
    return 0.2126 * $f($c[0]) + 0.7152 * $f($c[1]) + 0.0722 * $f($c[2]);
}
/** Contraste entre duas cores (1 a 21). Texto legível pede 4,5 ou mais. */
function vn_pdf_contraste($a, $b): float {
    $la = vn_pdf_lum($a); $lb = vn_pdf_lum($b);
    if ($la < $lb) { $t = $la; $la = $lb; $lb = $t; }
    return ($la + 0.05) / ($lb + 0.05);
}
/** [r,g,b] → [h 0..360, s 0..1, l 0..1] */
function vn_pdf_rgb_hsl($c): array {
    $c = vn_pdf_rgb($c);
    $r = $c[0] / 255; $g = $c[1] / 255; $b = $c[2] / 255;
    $mx = max($r, $g, $b); $mn = min($r, $g, $b); $l = ($mx + $mn) / 2; $d = $mx - $mn;
    if ($d < 1e-9) return [0.0, 0.0, $l];
    $s = $l > 0.5 ? $d / (2 - $mx - $mn) : $d / ($mx + $mn);
    if ($mx === $r) $h = fmod((($g - $b) / $d), 6);
    elseif ($mx === $g) $h = (($b - $r) / $d) + 2;
    else $h = (($r - $g) / $d) + 4;
    $h *= 60; if ($h < 0) $h += 360;
    return [$h, $s, $l];
}
function vn_pdf_hsl_rgb(float $h, float $s, float $l): array {
    $h = fmod($h, 360); if ($h < 0) $h += 360;
    $s = max(0, min(1, $s)); $l = max(0, min(1, $l));
    $c = (1 - abs(2 * $l - 1)) * $s; $x = $c * (1 - abs(fmod($h / 60, 2) - 1)); $m = $l - $c / 2;
    if ($h < 60) { $r = $c; $g = $x; $b = 0; } elseif ($h < 120) { $r = $x; $g = $c; $b = 0; }
    elseif ($h < 180) { $r = 0; $g = $c; $b = $x; } elseif ($h < 240) { $r = 0; $g = $x; $b = $c; }
    elseif ($h < 300) { $r = $x; $g = 0; $b = $c; } else { $r = $c; $g = 0; $b = $x; }
    return [($r + $m) * 255, ($g + $m) * 255, ($b + $m) * 255];
}
/** MAIÚSCULAS com acento (mb_strtoupper quando existe). */
function vn_pdf_upper(string $s): string {
    if (function_exists('mb_strtoupper')) return mb_strtoupper($s, 'UTF-8');
    return strtr(strtoupper($s), ['á' => 'Á', 'à' => 'À', 'â' => 'Â', 'ã' => 'Ã', 'é' => 'É', 'ê' => 'Ê', 'í' => 'Í', 'ó' => 'Ó', 'ô' => 'Ô', 'õ' => 'Õ', 'ú' => 'Ú', 'ü' => 'Ü', 'ç' => 'Ç']);
}

/* =========================================================================
   Motor
   ========================================================================= */
class VNPdf {
    const PW = 595.28;
    const PH = 841.89;

    public $mL = 36.0;
    public $mR = 36.0;
    public $mT = 36.0;
    public $mB = 52.0;
    /** Cursor vertical (de cima para baixo). Os componentes de layout leem e avançam. */
    public $y = 36.0;

    protected $pages = [];
    protected $cur = -1;
    protected $images = [];
    protected $imageMeta = [];
    protected $gs = [];
    protected $onPage = null;
    protected $onFooter = null;
    protected $meta;
    protected $encCache = [];

    /* Larguras (em milésimos de em) dos caracteres 0..255 em WinAnsi: Helvetica, Helvetica-Bold, Helvetica-Oblique. */
    private static $LR = [
        0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
        0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
        278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
        1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
        333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
        556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584, 761,
        556, 0, 222, 556, 333, 1000, 556, 556, 333, 1000, 667, 333, 1000, 0, 611, 0,
        0, 222, 222, 333, 333, 350, 556, 1000, 333, 1000, 500, 333, 944, 0, 500, 667,
        278, 333, 556, 556, 556, 556, 260, 556, 333, 737, 370, 556, 584, 333, 737, 333,
        400, 584, 333, 333, 333, 556, 537, 278, 333, 333, 365, 556, 834, 834, 834, 611,
        667, 667, 667, 667, 667, 667, 1000, 722, 667, 667, 667, 667, 278, 278, 278, 278,
        722, 722, 778, 778, 778, 778, 778, 584, 778, 722, 722, 722, 722, 667, 667, 611,
        556, 556, 556, 556, 556, 556, 889, 500, 556, 556, 556, 556, 278, 278, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 584, 611, 556, 556, 556, 556, 500, 556, 500,
    ];
    private static $LB = [
        0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
        0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
        278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611,
        975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556,
        333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611,
        611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584, 761,
        556, 0, 278, 556, 500, 1000, 556, 556, 333, 1000, 667, 333, 1000, 0, 611, 0,
        0, 278, 278, 500, 500, 350, 556, 1000, 333, 1000, 556, 333, 944, 0, 500, 667,
        278, 333, 556, 556, 556, 556, 280, 556, 333, 737, 370, 556, 584, 333, 737, 333,
        400, 584, 333, 333, 333, 611, 556, 278, 333, 333, 365, 556, 834, 834, 834, 611,
        722, 722, 722, 722, 722, 722, 1000, 722, 667, 667, 667, 667, 278, 278, 278, 278,
        722, 722, 778, 778, 778, 778, 778, 584, 778, 722, 722, 722, 722, 667, 667, 611,
        556, 556, 556, 556, 556, 556, 889, 556, 556, 556, 556, 556, 278, 278, 278, 278,
        611, 611, 611, 611, 611, 611, 611, 584, 611, 611, 611, 611, 611, 556, 611, 556,
    ];
    private static $LI = [
        0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
        0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
        278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
        1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
        333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
        556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584, 761,
        556, 0, 222, 556, 333, 1000, 556, 556, 333, 1000, 667, 333, 1000, 0, 611, 0,
        0, 222, 222, 333, 333, 350, 556, 1000, 333, 1000, 500, 333, 944, 0, 500, 667,
        278, 333, 556, 556, 556, 556, 260, 556, 333, 737, 370, 556, 584, 333, 737, 333,
        400, 584, 333, 333, 333, 556, 537, 278, 333, 333, 365, 556, 834, 834, 834, 611,
        667, 667, 667, 667, 667, 667, 1000, 722, 667, 667, 667, 667, 278, 278, 278, 278,
        722, 722, 778, 778, 778, 778, 778, 584, 778, 722, 722, 722, 722, 667, 667, 611,
        556, 556, 556, 556, 556, 556, 889, 500, 556, 556, 556, 556, 278, 278, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 584, 611, 556, 556, 556, 556, 500, 556, 500,
    ];

    public function __construct(array $meta = []) {
        $this->meta = $meta + ['title' => '', 'author' => '', 'subject' => '', 'creator' => 'VN System'];
    }

    /* ---------- páginas ---------- */

    /** Chamado sempre que uma página nasce: function(VNPdf $pdf, int $numero). Serve para o cabeçalho. */
    public function setPageHook(callable $fn): void { $this->onPage = $fn; }
    /** Chamado no fim, uma vez por página: function(VNPdf $pdf, int $numero, int $total). Serve para o rodapé. */
    public function setFooterHook(callable $fn): void { $this->onFooter = $fn; }

    public function addPage(): void {
        $this->cur = count($this->pages);
        $this->pages[] = ['c' => '', 'im' => [], 'gs' => []];
        $this->y = $this->mT;
        if ($this->onPage) call_user_func($this->onPage, $this, $this->cur + 1);
    }
    public function pageCount(): int { return count($this->pages); }
    public function pageNo(): int { return $this->cur + 1; }
    public function contentWidth(): float { return self::PW - $this->mL - $this->mR; }
    public function bottom(): float { return self::PH - $this->mB; }
    public function room(): float { return $this->bottom() - $this->y; }

    /** Garante $h pontos livres; se não couber, abre outra página. Devolve true quando abriu. */
    public function ensure(float $h): bool {
        if ($this->cur < 0) { $this->addPage(); return true; }
        if ($this->y + $h > $this->bottom() + 0.01) { $this->addPage(); return true; }
        return false;
    }

    /* ---------- texto: codificação e medidas ---------- */

    /** UTF-8 → bytes WinAnsi (CP1252). Caractere que a fonte não tem vira "?". */
    public function enc(string $s): string {
        if (isset($this->encCache[$s])) return $this->encCache[$s];
        $r = $this->encRaw($s);
        if (count($this->encCache) > 5000) $this->encCache = [];
        return $this->encCache[$s] = $r;
    }
    private function encRaw(string $s): string {
        $s = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $s);
        if ($s === '' || $s === null) return '';
        $s = str_replace("\t", '    ', $s);
        if (!preg_match('//u', $s)) return $s;   // não é UTF-8: já está em Latin-1/CP1252
        static $c1 = [0x20AC => 0x80, 0x201A => 0x82, 0x0192 => 0x83, 0x201E => 0x84, 0x2026 => 0x85, 0x2020 => 0x86, 0x2021 => 0x87,
            0x02C6 => 0x88, 0x2030 => 0x89, 0x0160 => 0x8A, 0x2039 => 0x8B, 0x0152 => 0x8C, 0x017D => 0x8E, 0x2018 => 0x91, 0x2019 => 0x92,
            0x201C => 0x93, 0x201D => 0x94, 0x2022 => 0x95, 0x2013 => 0x96, 0x2014 => 0x97, 0x02DC => 0x98, 0x2122 => 0x99, 0x0161 => 0x9A,
            0x203A => 0x9B, 0x0153 => 0x9C, 0x017E => 0x9E, 0x0178 => 0x9F];
        static $fb = [0x2192 => '->', 0x2190 => '<-', 0x2713 => 'v', 0x2714 => 'v', 0x2011 => '-', 0x2012 => '-', 0x2212 => '-',
            0x2009 => ' ', 0x2002 => ' ', 0x2003 => ' ', 0x202F => ' ', 0x200B => '', 0x200C => '', 0x200D => '', 0xFEFF => '', 0x00AD => '',
            0x25CF => chr(0x95), 0x25AA => chr(0x95), 0x2713 => 'v', 0x00D7 => 'x'];
        $out = '';
        foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            $o = ord($ch[0]);
            if ($o < 0x80) { $out .= $ch; continue; }
            if ($o < 0xE0) $cp = (($o & 0x1F) << 6) | (ord($ch[1]) & 0x3F);
            elseif ($o < 0xF0) $cp = (($o & 0x0F) << 12) | ((ord($ch[1]) & 0x3F) << 6) | (ord($ch[2]) & 0x3F);
            else $cp = (($o & 0x07) << 18) | ((ord($ch[1]) & 0x3F) << 12) | ((ord($ch[2]) & 0x3F) << 6) | (ord($ch[3]) & 0x3F);
            if ($cp === 0xA0) $out .= ' ';
            elseif ($cp > 0xA0 && $cp <= 0xFF) $out .= chr($cp);
            elseif (isset($c1[$cp])) $out .= chr($c1[$cp]);
            elseif (isset($fb[$cp])) $out .= $fb[$cp];
            else $out .= '?';
        }
        return $out;
    }

    /** Largura do texto em pontos (tabelas de largura da Helvetica; confere com o resultado de um leitor de PDF). */
    public function width($s, float $size, string $style = 'R', float $cs = 0.0): float {
        $b = $this->enc((string)$s);
        $t = $style === 'B' || $style === 'BI' ? self::$LB : ($style === 'I' ? self::$LI : self::$LR);
        $w = 0;
        for ($i = 0, $n = strlen($b); $i < $n; $i++) $w += $t[ord($b[$i])];
        return $w * $size / 1000 + $cs * strlen($b);      // $cs = espaçamento extra entre letras (o mesmo de text())
    }

    /** Quebra o texto em linhas que cabem em $maxW. Respeita "\n"; palavra maior que a linha é cortada. */
    public function wrap($s, float $maxW, float $size, string $style = 'R', float $cs = 0.0): array {
        $s = str_replace(["\r\n", "\r", "\xC2\xA0"], ["\n", "\n", ' '], (string)$s);
        if ($maxW < 4) return [trim($s)];
        $space = $this->width(' ', $size, $style, $cs);
        $out = [];
        foreach (explode("\n", $s) as $para) {
            $words = preg_split('/\s+/u', trim($para), -1, PREG_SPLIT_NO_EMPTY);
            if (!$words) { $out[] = ''; continue; }
            $line = ''; $lw = 0.0;
            foreach ($words as $word) {
                $ww = $this->width($word, $size, $style, $cs);
                if ($ww > $maxW) {
                    if ($line !== '') { $out[] = $line; $line = ''; $lw = 0.0; }
                    $piece = ''; $pw = 0.0;
                    foreach (preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
                        $cw = $this->width($ch, $size, $style, $cs);
                        if ($pw + $cw > $maxW && $piece !== '') { $out[] = $piece; $piece = ''; $pw = 0.0; }
                        $piece .= $ch; $pw += $cw;
                    }
                    $line = $piece; $lw = $pw;
                    continue;
                }
                if ($line === '') { $line = $word; $lw = $ww; }
                elseif ($lw + $space + $ww <= $maxW) { $line .= ' ' . $word; $lw += $space + $ww; }
                else { $out[] = $line; $line = $word; $lw = $ww; }
            }
            if ($line !== '') $out[] = $line;
        }
        return $out ?: [''];
    }

    /** Corta com "…" para caber numa linha só. */
    public function fit($s, float $maxW, float $size, string $style = 'R', float $cs = 0.0): string {
        $s = trim(preg_replace('/\s+/u', ' ', (string)$s));
        if ($this->width($s, $size, $style, $cs) <= $maxW) return $s;
        $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
        $ell = '…'; $ew = $this->width($ell, $size, $style, $cs);
        $out = ''; $w = 0.0;
        foreach ($chars as $ch) {
            $cw = $this->width($ch, $size, $style, $cs);
            if ($w + $cw + $ew > $maxW) break;
            $out .= $ch; $w += $cw;
        }
        return rtrim($out) . $ell;
    }

    /** Maior tamanho (até $size) em que $s cabe em $maxW numa linha; nunca abaixo de $min. */
    public function fitSize($s, float $maxW, float $size, string $style = 'B', float $min = 8): float {
        while ($size > $min && $this->width($s, $size, $style) > $maxW) $size -= 0.5;
        return $size;
    }

    /* ---------- baixo nível ---------- */

    protected function out(string $s): void {
        if ($this->cur < 0) $this->addPage();
        $this->pages[$this->cur]['c'] .= $s . "\n";
    }
    protected function n($v): string {
        $s = number_format((float)$v, 2, '.', '');
        if (strpos($s, '.') !== false) $s = rtrim(rtrim($s, '0'), '.');
        return ($s === '' || $s === '-0') ? '0' : $s;
    }
    protected function py($y): string { return $this->n(self::PH - $y); }
    protected function colorCmd($c, bool $stroke = false): string {
        $c = vn_pdf_rgb($c);
        return sprintf('%.3F %.3F %.3F %s', $c[0] / 255, $c[1] / 255, $c[2] / 255, $stroke ? 'RG' : 'rg');
    }
    protected function paint(string $path, $fill, $stroke, float $lw): void {
        $s = 'q ';
        if ($fill !== null) $s .= $this->colorCmd($fill) . ' ';
        if ($stroke !== null) $s .= $this->colorCmd($stroke, true) . ' ' . $this->n($lw) . ' w ';
        $op = ($fill !== null && $stroke !== null) ? 'B' : ($fill !== null ? 'f' : ($stroke !== null ? 'S' : 'n'));
        $this->out($s . $path . ' ' . $op . ' Q');
    }

    /* ---------- desenho ---------- */

    public function rect(float $x, float $y, float $w, float $h, $fill = null, $stroke = null, float $lw = 0.6): void {
        $this->paint($this->n($x) . ' ' . $this->py($y + $h) . ' ' . $this->n($w) . ' ' . $this->n($h) . ' re', $fill, $stroke, $lw);
    }

    /** Caminho de retângulo arredondado. $corners: quais cantos arredondar ("tl tr br bl"). */
    protected function rrPath(float $x, float $y, float $w, float $h, float $r, string $corners): string {
        $r = max(0, min($r, $w / 2, $h / 2));
        $tl = strpos($corners, 'tl') !== false ? $r : 0; $tr = strpos($corners, 'tr') !== false ? $r : 0;
        $br = strpos($corners, 'br') !== false ? $r : 0; $bl = strpos($corners, 'bl') !== false ? $r : 0;
        $k = 0.5523;
        $P = function ($px, $py) { return $this->n($px) . ' ' . $this->py($py); };
        $p = $P($x + $tl, $y) . ' m ' . $P($x + $w - $tr, $y) . ' l ';
        if ($tr) $p .= $P($x + $w - $tr + $tr * $k, $y) . ' ' . $P($x + $w, $y + $tr - $tr * $k) . ' ' . $P($x + $w, $y + $tr) . ' c ';
        $p .= $P($x + $w, $y + $h - $br) . ' l ';
        if ($br) $p .= $P($x + $w, $y + $h - $br + $br * $k) . ' ' . $P($x + $w - $br + $br * $k, $y + $h) . ' ' . $P($x + $w - $br, $y + $h) . ' c ';
        $p .= $P($x + $bl, $y + $h) . ' l ';
        if ($bl) $p .= $P($x + $bl - $bl * $k, $y + $h) . ' ' . $P($x, $y + $h - $bl + $bl * $k) . ' ' . $P($x, $y + $h - $bl) . ' c ';
        $p .= $P($x, $y + $tl) . ' l ';
        if ($tl) $p .= $P($x, $y + $tl - $tl * $k) . ' ' . $P($x + $tl - $tl * $k, $y) . ' ' . $P($x + $tl, $y) . ' c ';
        return $p . 'h';
    }
    public function rrect(float $x, float $y, float $w, float $h, float $r, $fill = null, $stroke = null, float $lw = 0.6, string $corners = 'tl tr br bl'): void {
        $this->paint($this->rrPath($x, $y, $w, $h, $r, $corners), $fill, $stroke, $lw);
    }
    public function circle(float $cx, float $cy, float $r, $fill = null, $stroke = null, float $lw = 0.6): void {
        $k = 0.5523 * $r;
        $P = function ($px, $py) { return $this->n($px) . ' ' . $this->py($py); };
        $p = $P($cx + $r, $cy) . ' m '
            . $P($cx + $r, $cy + $k) . ' ' . $P($cx + $k, $cy + $r) . ' ' . $P($cx, $cy + $r) . ' c '
            . $P($cx - $k, $cy + $r) . ' ' . $P($cx - $r, $cy + $k) . ' ' . $P($cx - $r, $cy) . ' c '
            . $P($cx - $r, $cy - $k) . ' ' . $P($cx - $k, $cy - $r) . ' ' . $P($cx, $cy - $r) . ' c '
            . $P($cx + $k, $cy - $r) . ' ' . $P($cx + $r, $cy - $k) . ' ' . $P($cx + $r, $cy) . ' c h';
        $this->paint($p, $fill, $stroke, $lw);
    }
    /** Polígono: $pts = [[x,y],[x,y],...] */
    public function poly(array $pts, $fill = null, $stroke = null, float $lw = 0.6): void {
        if (count($pts) < 3) return;
        $p = '';
        foreach ($pts as $i => $pt) $p .= $this->n($pt[0]) . ' ' . $this->py($pt[1]) . ($i === 0 ? ' m ' : ' l ');
        $this->paint($p . 'h', $fill, $stroke, $lw);
    }
    public function line(float $x1, float $y1, float $x2, float $y2, $color = '#cccccc', float $lw = 0.6, ?array $dash = null): void {
        $s = 'q ' . $this->colorCmd($color, true) . ' ' . $this->n($lw) . ' w ';
        if ($dash) $s .= '[' . implode(' ', array_map([$this, 'n'], $dash)) . '] 0 d ';
        $this->out($s . $this->n($x1) . ' ' . $this->py($y1) . ' m ' . $this->n($x2) . ' ' . $this->py($y2) . ' l S Q');
    }

    /**
     * Texto numa linha. $base é a linha de base (onde "assenta" a letra), não o topo.
     * $align: L (começa em x), C (centrado em x), R (termina em x). Devolve a largura usada.
     */
    public function text(float $x, float $base, $s, float $size = 10, string $style = 'R', $color = '#000000', string $align = 'L', float $cs = 0.0): float {
        $s = (string)$s;
        $b = $this->enc($s);
        if ($b === '') return 0.0;
        $w = $this->width($s, $size, $style) + $cs * strlen($b);
        if ($align === 'R') $x -= $w; elseif ($align === 'C') $x -= $w / 2;
        $font = $style === 'BI' ? 'F4' : ($style === 'B' ? 'F2' : ($style === 'I' ? 'F3' : 'F1'));
        $esc = preg_replace_callback('/[\\\\()\x80-\xFF]/', function ($m) {
            $c = $m[0];
            return ($c === '\\' || $c === '(' || $c === ')') ? '\\' . $c : sprintf('\\%03o', ord($c));
        }, $b);
        $this->out('q ' . $this->colorCmd($color) . ' BT /' . $font . ' ' . $this->n($size) . ' Tf ' . ($cs != 0 ? $this->n($cs) . ' Tc ' : '')
            . $this->n($x) . ' ' . $this->py($base) . ' Td (' . $esc . ') Tj ET Q');
        return $w;
    }
    /** Linha de base para centralizar o texto na vertical em torno de $yMid. */
    public function mid(float $yMid, float $size): float { return $yMid + $size * 0.36; }

    /** Parágrafo com quebra automática; $y é o topo da primeira linha. Devolve a altura usada. */
    public function para(float $x, float $y, float $w, $s, float $size = 10, string $style = 'R', $color = '#111827', ?float $lead = null, string $align = 'L'): float {
        $lead = $lead ?: round($size * 1.38, 2);
        $yy = $y;
        $ax = $align === 'R' ? $x + $w : ($align === 'C' ? $x + $w / 2 : $x);
        foreach ($this->wrap($s, $w, $size, $style) as $ln) {
            if ($ln !== '') $this->text($ax, $this->mid($yy + $lead / 2, $size), $ln, $size, $style, $color, $align);
            $yy += $lead;
        }
        return $yy - $y;
    }
    public function paraHeight($s, float $w, float $size = 10, string $style = 'R', ?float $lead = null): float {
        $lead = $lead ?: round($size * 1.38, 2);
        return count($this->wrap($s, $w, $size, $style)) * $lead;
    }

    /* ---------- imagens ---------- */

    /** Lê os bytes de: caminho de arquivo, "data:image/...;base64,..." ou os próprios bytes. */
    protected function imageBytes($src): string {
        if (!is_string($src) || $src === '') return '';
        if (strncmp($src, 'data:', 5) === 0) {
            $p = strpos($src, ',');
            if ($p === false) return '';
            $raw = base64_decode(substr($src, $p + 1), true);
            return $raw === false ? '' : $raw;
        }
        if (strlen($src) < 1024 && strpos($src, "\0") === false && @is_file($src)) {
            $raw = @file_get_contents($src);
            return $raw === false ? '' : $raw;
        }
        return $src;
    }

    /** Orientação gravada pelo celular na foto (1..8). Sem isso as fotos de celular saem deitadas. */
    public static function exifOrientation(string $jpeg): int {
        if (strlen($jpeg) < 20 || $jpeg[0] !== "\xFF" || $jpeg[1] !== "\xD8") return 1;
        $pos = 2; $len = strlen($jpeg);
        while ($pos + 4 < $len) {
            if ($jpeg[$pos] !== "\xFF") break;
            $mk = ord($jpeg[$pos + 1]);
            if ($mk === 0xD9 || $mk === 0xDA) break;
            $seg = (ord($jpeg[$pos + 2]) << 8) | ord($jpeg[$pos + 3]);
            if ($mk === 0xE1 && substr($jpeg, $pos + 4, 6) === "Exif\0\0") {
                $t = $pos + 10;                       // início do cabeçalho TIFF
                $le = substr($jpeg, $t, 2) === 'II';
                $u16 = function ($o) use ($jpeg, $le) { $d = substr($jpeg, $o, 2); if (strlen($d) < 2) return 0; $v = unpack($le ? 'v' : 'n', $d); return $v[1]; };
                $u32 = function ($o) use ($jpeg, $le) { $d = substr($jpeg, $o, 4); if (strlen($d) < 4) return 0; $v = unpack($le ? 'V' : 'N', $d); return $v[1]; };
                $ifd = $t + $u32($t + 4);
                $cnt = $u16($ifd);
                for ($i = 0; $i < $cnt && $i < 60; $i++) {
                    $e = $ifd + 2 + $i * 12;
                    if ($u16($e) === 0x0112) { $v = $u16($e + 8); return ($v >= 1 && $v <= 8) ? $v : 1; }
                }
                return 1;
            }
            $pos += 2 + $seg;
        }
        return 1;
    }

    /** memory_limit do PHP em bytes (PHP_INT_MAX quando sem limite). */
    protected static function memLimit(): int {
        $v = trim((string)ini_get('memory_limit'));
        if ($v === '' || $v === '-1') return PHP_INT_MAX;
        $n = (int)$v;
        switch (strtolower(substr($v, -1))) { case 'g': return $n * 1073741824; case 'm': return $n * 1048576; case 'k': return $n * 1024; }
        return $n;
    }

    /**
     * Registra uma imagem e devolve ['name'=>, 'w'=>, 'h'=>] (w/h em pixels) ou null se não der.
     * Opções: max (maior lado em px; fotos grandes são reduzidas), q (qualidade JPEG), alpha (mantém transparência).
     * JPEG pequeno e em pé entra como está; o resto passa pelo GD (reduz, corrige a orientação, preserva o fundo transparente).
     */
    public function addImage($src, array $opt = []): ?array {
        $bin = $this->imageBytes($src);
        if ($bin === '') return null;
        $max = (int)($opt['max'] ?? 1600); $q = (int)($opt['q'] ?? 82); $keepA = !isset($opt['alpha']) || $opt['alpha'];
        $trim = !empty($opt['trim']);
        $land = !empty($opt['landscape']);
        $key = md5($bin) . ':' . $max . ':' . $q . ':' . (int)$keepA . ':' . (int)$trim . ':' . (int)$land;
        if (isset($this->imageMeta[$key])) return $this->imageMeta[$key];
        $info = @getimagesizefromstring($bin);
        if (!$info || $info[0] < 1 || $info[1] < 1) return null;
        $w = (int)$info[0]; $h = (int)$info[1]; $type = (int)$info[2];
        $gd = function_exists('imagecreatefromstring') && function_exists('imagecreatetruecolor');
        // foto de muitos megapixels: o GD precisa de ~5 bytes por pixel; se a memória do servidor não der, entra como está (JPEG) ou fica de fora
        if ($gd && memory_get_usage(true) + $w * $h * 5 > self::memLimit() * 0.85) $gd = false;
        $desc = null;

        if ($type === IMAGETYPE_JPEG && !$trim && !($land && $h > $w)) {
            $ori = self::exifOrientation($bin);
            $ch = (int)($info['channels'] ?? 3);
            $cabe = max($w, $h) <= (int)($max * 1.3) && strlen($bin) <= 600000;
            if (($ori <= 1 && $cabe) || !$gd || ($w * $h) > 40000000) {
                $desc = ['w' => $w, 'h' => $h, 'cs' => $ch === 1 ? 'DeviceGray' : ($ch === 4 ? 'DeviceCMYK' : 'DeviceRGB'), 'bpc' => 8, 'filter' => 'DCTDecode',
                    'data' => $bin, 'decode' => $ch === 4 ? '[1 0 1 0 1 0 1 0]' : '', 'smask' => null];
            }
        }
        if ($desc === null) {
            if (!$gd || ($w * $h) > 60000000) {
                if ($type === IMAGETYPE_JPEG && !$trim && !$land) {          // sem GD (ou sem memória): JPEG entra direto, mesmo grande
                    $ch = (int)($info['channels'] ?? 3);
                    $desc = ['w' => $w, 'h' => $h, 'cs' => $ch === 1 ? 'DeviceGray' : ($ch === 4 ? 'DeviceCMYK' : 'DeviceRGB'), 'bpc' => 8, 'filter' => 'DCTDecode',
                        'data' => $bin, 'decode' => $ch === 4 ? '[1 0 1 0 1 0 1 0]' : '', 'smask' => null];
                    $name = 'Im' . (count($this->images) + 1);
                    $this->images[$name] = $desc;
                    return $this->imageMeta[$key] = ['name' => $name, 'w' => $w, 'h' => $h];
                }
                return null;
            }
            $im = @imagecreatefromstring($bin);
            if (!$im) return null;
            if (function_exists('imagepalettetotruecolor') && !imageistruecolor($im)) @imagepalettetotruecolor($im);
            if ($type === IMAGETYPE_JPEG) {
                $ori = self::exifOrientation($bin);
                if ($ori === 3) $im = $this->gdRot($im, 180);
                elseif ($ori === 6) $im = $this->gdRot($im, -90);
                elseif ($ori === 8) $im = $this->gdRot($im, 90);
                elseif ($ori === 2 && function_exists('imageflip')) imageflip($im, IMG_FLIP_HORIZONTAL);
                elseif ($ori === 4 && function_exists('imageflip')) imageflip($im, IMG_FLIP_VERTICAL);
            }
            if ($trim) $im = $this->gdTrim($im);
            if ($land && imagesy($im) > imagesx($im)) {
                $r = @imagerotate($im, 90, imagecolorallocate($im, 255, 255, 255));
                if ($r) { imagedestroy($im); $im = $r; }
            }
            $w = imagesx($im); $h = imagesy($im);
            $semAlpha = ($type === IMAGETYPE_JPEG);
            $lim = $semAlpha ? $max : min($max, 700);   // com transparência o laço é por pixel: limita o tamanho
            if (max($w, $h) > $lim) {
                $k = $lim / max($w, $h);
                $nw = max(1, (int)round($w * $k)); $nh = max(1, (int)round($h * $k));
                $dst = imagecreatetruecolor($nw, $nh);
                if (!$semAlpha) {
                    imagealphablending($dst, false); imagesavealpha($dst, true);
                    imagefilledrectangle($dst, 0, 0, $nw, $nh, imagecolorallocatealpha($dst, 255, 255, 255, 127));
                }
                imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
                imagedestroy($im); $im = $dst; $w = $nw; $h = $nh;
            }
            if ($semAlpha || ($w * $h > 700 * 700)) {
                // foto (ou PNG grande): achata sobre branco e grava como JPEG
                $flat = imagecreatetruecolor($w, $h);
                imagefilledrectangle($flat, 0, 0, $w, $h, imagecolorallocate($flat, 255, 255, 255));
                imagealphablending($flat, true);
                imagecopy($flat, $im, 0, 0, 0, 0, $w, $h);
                ob_start(); imagejpeg($flat, null, $q); $jpg = (string)ob_get_clean();
                imagedestroy($flat); imagedestroy($im);
                if ($jpg === '') return null;
                $desc = ['w' => $w, 'h' => $h, 'cs' => 'DeviceRGB', 'bpc' => 8, 'filter' => 'DCTDecode', 'data' => $jpg, 'decode' => '', 'smask' => null];
            } else {
                // logo/ícone: pixels sem perda e, se houver, o canal de transparência
                $rgb = ''; $al = ''; $has = false;
                for ($yy = 0; $yy < $h; $yy++) {
                    $r1 = []; $a1 = [];
                    for ($xx = 0; $xx < $w; $xx++) {
                        $c = imagecolorat($im, $xx, $yy);
                        $a = ($c >> 24) & 0x7F;
                        if ($a) $has = true;
                        $r1[] = ($c >> 16) & 255; $r1[] = ($c >> 8) & 255; $r1[] = $c & 255;
                        $a1[] = 255 - (int)round($a * 255 / 127);
                    }
                    $rgb .= pack('C*', ...$r1); $al .= pack('C*', ...$a1);
                }
                imagedestroy($im);
                $z = function_exists('gzcompress');
                $desc = ['w' => $w, 'h' => $h, 'cs' => 'DeviceRGB', 'bpc' => 8, 'filter' => $z ? 'FlateDecode' : null, 'data' => $z ? gzcompress($rgb, 6) : $rgb, 'decode' => '',
                    'smask' => ($has && $keepA) ? ['w' => $w, 'h' => $h, 'data' => $z ? gzcompress($al, 6) : $al, 'filter' => $z ? 'FlateDecode' : null] : null];
            }
        }
        $name = 'Im' . (count($this->images) + 1);
        $this->images[$name] = $desc;
        return $this->imageMeta[$key] = ['name' => $name, 'w' => $desc['w'], 'h' => $desc['h']];
    }
    /** Corta a borda vazia (transparente ou quase branca) ao redor do desenho, deixando uma folga pequena. */
    private function gdTrim($im) {
        $w = imagesx($im); $h = imagesy($im);
        $st = max(1, (int)floor(max($w, $h) / 260));
        $x0 = $w; $y0 = $h; $x1 = -1; $y1 = -1;
        for ($y = 0; $y < $h; $y += $st) {
            for ($x = 0; $x < $w; $x += $st) {
                $c = imagecolorat($im, $x, $y);
                if ((($c >> 24) & 0x7F) > 100) continue;
                if ((($c >> 16) & 255) > 242 && (($c >> 8) & 255) > 242 && ($c & 255) > 242) continue;
                if ($x < $x0) $x0 = $x;
                if ($x > $x1) $x1 = $x;
                if ($y < $y0) $y0 = $y;
                if ($y > $y1) $y1 = $y;
            }
        }
        if ($x1 < 0) return $im;
        $m = (int)round(max($w, $h) * 0.02) + $st;
        $x0 = max(0, $x0 - $m); $y0 = max(0, $y0 - $m); $x1 = min($w - 1, $x1 + $m); $y1 = min($h - 1, $y1 + $m);
        $cw = $x1 - $x0 + 1; $ch = $y1 - $y0 + 1;
        if ($cw >= $w * 0.95 && $ch >= $h * 0.95) return $im;
        $dst = imagecreatetruecolor($cw, $ch);
        imagealphablending($dst, false); imagesavealpha($dst, true);
        imagefilledrectangle($dst, 0, 0, $cw, $ch, imagecolorallocatealpha($dst, 255, 255, 255, 127));
        imagecopy($dst, $im, 0, 0, $x0, $y0, $cw, $ch);
        imagedestroy($im);
        return $dst;
    }
    private function gdRot($im, int $deg) {
        $r = @imagerotate($im, $deg, 0);
        if ($r) { imagedestroy($im); return $r; }
        return $im;
    }

    /** Desenha a imagem (já registrada) exatamente em x,y,w,h. $r > 0 recorta com cantos arredondados. */
    public function image(array $img, float $x, float $y, float $w, float $h, float $r = 0): void {
        if ($this->cur < 0) $this->addPage();
        $this->pages[$this->cur]['im'][$img['name']] = true;
        $clip = $r > 0 ? $this->rrPath($x, $y, $w, $h, $r, 'tl tr br bl') . ' W n ' : '';
        $this->out('q ' . $clip . $this->n($w) . ' 0 0 ' . $this->n($h) . ' ' . $this->n($x) . ' ' . $this->py($y + $h) . ' cm /' . $img['name'] . ' Do Q');
    }
    /** Cabe a imagem inteira na caixa (sem cortar), centralizada. Devolve [x,y,w,h] do que foi desenhado. */
    public function imageFit(array $img, float $x, float $y, float $bw, float $bh, float $r = 0, string $ax = 'C', string $ay = 'C'): array {
        $k = min($bw / $img['w'], $bh / $img['h']);
        $dw = $img['w'] * $k; $dh = $img['h'] * $k;
        $dx = $ax === 'L' ? $x : ($ax === 'R' ? $x + $bw - $dw : $x + ($bw - $dw) / 2);
        $dy = $ay === 'T' ? $y : ($ay === 'B' ? $y + $bh - $dh : $y + ($bh - $dh) / 2);
        $this->image($img, $dx, $dy, $dw, $dh, $r);
        return [$dx, $dy, $dw, $dh];
    }

    /* ---------- saída ---------- */

    protected function pdfString(string $s): string {
        if (!preg_match('/[^\x20-\x7E]/', $s)) return '(' . strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']) . ')';
        if (function_exists('mb_convert_encoding')) return '<FEFF' . strtoupper(bin2hex(mb_convert_encoding($s, 'UTF-16BE', 'UTF-8'))) . '>';
        return '(' . strtr($this->enc($s), ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']) . ')';
    }
    protected function streamObj(string $dict, string $data, ?string $filter = null): string {
        return '<< ' . $dict . ($filter ? ' /Filter /' . $filter : '') . ' /Length ' . strlen($data) . " >>\nstream\n" . $data . "\nendstream";
    }

    /** Monta o arquivo PDF e devolve os bytes. */
    public function output(): string {
        if ($this->cur < 0) $this->addPage();
        $total = count($this->pages);
        if ($this->onFooter) {
            $keep = $this->cur;
            for ($i = 0; $i < $total; $i++) { $this->cur = $i; call_user_func($this->onFooter, $this, $i + 1, $total); }
            $this->cur = $keep;
        }
        $objs = [];
        $add = function ($body) use (&$objs) { $objs[] = $body; return count($objs); };
        $catalogId = $add('');
        $pagesId = $add('');
        $fontIds = [];
        foreach (['F1' => 'Helvetica', 'F2' => 'Helvetica-Bold', 'F3' => 'Helvetica-Oblique', 'F4' => 'Helvetica-BoldOblique'] as $k => $bf) {
            $fontIds[$k] = $add('<< /Type /Font /Subtype /Type1 /BaseFont /' . $bf . ' /Encoding /WinAnsiEncoding >>');
        }
        $imgIds = [];
        foreach ($this->images as $name => $im) {
            $sm = '';
            if (!empty($im['smask'])) {
                $s = $im['smask'];
                $sid = $add($this->streamObj('/Type /XObject /Subtype /Image /Width ' . $s['w'] . ' /Height ' . $s['h'] . ' /ColorSpace /DeviceGray /BitsPerComponent 8', $s['data'], $s['filter'] ?? null));
                $sm = ' /SMask ' . $sid . ' 0 R';
            }
            $dict = '/Type /XObject /Subtype /Image /Width ' . $im['w'] . ' /Height ' . $im['h'] . ' /ColorSpace /' . $im['cs'] . ' /BitsPerComponent ' . $im['bpc']
                . ($im['decode'] !== '' ? ' /Decode ' . $im['decode'] : '') . $sm;
            $imgIds[$name] = $add($this->streamObj($dict, $im['data'], $im['filter']));
        }
        $gsIds = [];
        foreach ($this->gs as $a => $name) $gsIds[$name] = $add('<< /Type /ExtGState /ca ' . $a . ' /CA ' . $a . ' >>');
        $z = function_exists('gzcompress');
        $kids = [];
        foreach ($this->pages as $pg) {
            $c = $pg['c'];
            $cid = $z ? $add($this->streamObj('', gzcompress($c, 6), 'FlateDecode')) : $add($this->streamObj('', $c));
            $xo = '';
            foreach (array_keys($pg['im']) as $nm) if (isset($imgIds[$nm])) $xo .= '/' . $nm . ' ' . $imgIds[$nm] . ' 0 R ';
            $gx = '';
            foreach (array_keys($pg['gs']) as $nm) if (isset($gsIds[$nm])) $gx .= '/' . $nm . ' ' . $gsIds[$nm] . ' 0 R ';
            $res = '<< /Font << /F1 ' . $fontIds['F1'] . ' 0 R /F2 ' . $fontIds['F2'] . ' 0 R /F3 ' . $fontIds['F3'] . ' 0 R /F4 ' . $fontIds['F4'] . ' 0 R >>'
                . ($xo !== '' ? ' /XObject << ' . $xo . '>>' : '') . ($gx !== '' ? ' /ExtGState << ' . $gx . '>>' : '') . ' /ProcSet [/PDF /Text /ImageB /ImageC] >>';
            $pid = $add('<< /Type /Page /Parent ' . $pagesId . ' 0 R /MediaBox [0 0 ' . $this->n(self::PW) . ' ' . $this->n(self::PH) . '] /Resources ' . $res . ' /Contents ' . $cid . ' 0 R >>');
            $kids[] = $pid . ' 0 R';
        }
        $objs[$pagesId - 1] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        $objs[$catalogId - 1] = '<< /Type /Catalog /Pages ' . $pagesId . ' 0 R /PageMode /UseNone >>';
        $m = $this->meta;
        $dt = 'D:' . date('YmdHis');
        $infoId = $add('<< /Title ' . $this->pdfString((string)$m['title']) . ' /Author ' . $this->pdfString((string)$m['author']) . ' /Subject ' . $this->pdfString((string)$m['subject'])
            . ' /Creator ' . $this->pdfString((string)$m['creator']) . ' /Producer (VN System) /CreationDate (' . $dt . ") >>");

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offs = [];
        foreach ($objs as $i => $body) {
            $offs[$i + 1] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $cnt = count($objs) + 1;
        $pdf .= "xref\n0 " . $cnt . "\n0000000000 65535 f \n";
        for ($i = 1; $i < $cnt; $i++) $pdf .= sprintf("%010d 00000 n \n", $offs[$i]);
        $id = strtoupper(md5($pdf));
        $pdf .= "trailer\n<< /Size " . $cnt . ' /Root ' . $catalogId . ' 0 R /Info ' . $infoId . ' 0 R /ID [<' . $id . '> <' . $id . ">] >>\nstartxref\n" . $xref . "\n%%EOF\n";
        return $pdf;
    }
}
