<?php
/* =========================================================================
   VN System — Serralheria por medida: como o produto é cobrado

   Serralheiro não cobra por "peça": cobra pela MEDIDA. Portão e grade de janela por metro quadrado
   (largura × altura), corrimão, guarda-corpo e gradil por metro linear, escada por degrau, estrutura
   metálica por quilo, serviço avulso por hora.

   Não existe tabela de preços nem estrutura pronta. Cada produto define o seu cálculo quando é cadastrado
   (aba "Cadastrar" do orçamento): "como cobrar" (m², metro, degrau, hora ou kg) e o valor de cada medida.
   Ao adicionar o produto no orçamento, a tela pede a medida e faz a conta: medida × valor da medida.

   O produto guarda isso em vn_mobile_orcamento_catalogo.calculo (m2, m, degrau, h, kg; vazio = por unidade,
   quantidade simples). Esta biblioteca só tem os nomes das medidas, usados nas telas, no pedido e no PDF.
   ========================================================================= */

/** Medidas conhecidas: chave => [rótulo curto, "cobrar por", plural]. 'un' vale só para o item do pedido (quantidade simples). */
function vn_serr_unidades(): array {
    return [
        'm2'     => ['m²',     'Metro quadrado (m²)',  'm²'],
        'm'      => ['m',      'Metro linear (m)',     'm'],
        'un'     => ['un',     'Unidade',              'un'],
        'h'      => ['h',      'Hora (h)',             'h'],
        'degrau' => ['degrau', 'Degrau',               'degraus'],
        'kg'     => ['kg',     'Quilo (kg)',           'kg'],
    ];
}
/** "por m²", "por metro linear"... (texto que acompanha o preço). */
function vn_serr_unidade_por($u): string {
    static $por = ['m2' => 'por m²', 'm' => 'por metro linear', 'un' => 'por unidade', 'h' => 'por hora', 'degrau' => 'por degrau', 'kg' => 'por kg'];
    return $por[vn_serr_unidade_valida($u)] ?? '';
}
/** Chave válida da medida ('' quando não é nenhuma conhecida). */
function vn_serr_unidade_valida($u): string {
    $u = strtolower(trim((string)$u));
    return isset(vn_serr_unidades()[$u]) ? $u : '';
}
/** Rótulo da medida junto da quantidade: 1 degrau, 12 degraus, 6,3 m². */
function vn_serr_unidade_rotulo($u, float $qtd = 1.0): string {
    $d = vn_serr_unidades()[vn_serr_unidade_valida($u)] ?? null;
    if (!$d) return '';
    return abs($qtd - 1.0) < 0.0001 ? $d[0] : $d[2];
}

/* ---------------------------------------------------------------- cálculo do produto */

/** Formas de cobrar que pedem uma medida na hora de orçar (a quantidade simples não está aqui): [chave, texto da lista]. */
function vn_serr_calculos_opcoes(): array {
    return [
        ['m2',     'Por m² (largura × altura)'],
        ['m',      'Por metro linear (m)'],
        ['degrau', 'Por degrau'],
        ['h',      'Por hora'],
        ['kg',     'Por quilo (kg)'],
    ];
}
/** Chave de cálculo válida do produto: m2, m, degrau, h ou kg. Qualquer outra coisa (inclusive 'un') = '' (por unidade). */
function vn_serr_calculo_valido($c): string {
    $c = strtolower(trim((string)$c));
    foreach (vn_serr_calculos_opcoes() as $o) { if ($o[0] === $c) return $c; }
    return '';
}
/** Texto da unidade do produto cobrado por medida (vai na coluna "unidade" do catálogo: "Preço por m²"). */
function vn_serr_calculo_unidade(string $calculo): string {
    $d = vn_serr_unidades()[vn_serr_calculo_valido($calculo)] ?? null;
    return $d ? $d[0] : '';
}

/** <select> "Como cobrar" para os formulários do Inventário (name="catalogo_calculo"); $atual = a chave que já está salva no produto. */
function vn_serr_calculo_select(string $atual = ''): string {
    $atual = vn_serr_calculo_valido($atual);
    $h = '<select name="catalogo_calculo"><option value="">Por unidade (quantidade)</option>';
    foreach (vn_serr_calculos_opcoes() as $o) {
        $h .= '<option value="' . htmlspecialchars($o[0], ENT_QUOTES, 'UTF-8') . '"' . ($o[0] === $atual ? ' selected' : '') . '>' . htmlspecialchars($o[1], ENT_QUOTES, 'UTF-8') . '</option>';
    }
    return $h . '</select>';
}
