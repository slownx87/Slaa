<?php
/*
    VN System - Central de Cobranças: desenho da tela (cobrancas.php).
    Só funções que devolvem HTML. A leitura dos dados e as ações (POST) ficam em cobrancas.php.

    Cada "item de cobrança" ($it) vem montado por cobrancas.php:
      key, tipo (parcela | mensalidade | material), estado (atrasada | hoje | semana | futura | aprovar | paga),
      cliente, etq, valor, venc, pago_em, titulo, sub, wpp, pix + os dados brutos de cada tipo.
*/

if (!function_exists('cb_estado_venc')) {
    /* Situação de uma data de vencimento em relação a hoje. */
    function cb_estado_venc(string $venc, string $hoje, string $fimSemana): string {
        if ($venc === '') return 'futura';
        if ($venc < $hoje) return 'atrasada';
        if ($venc === $hoje) return 'hoje';
        if ($venc <= $fimSemana) return 'semana';
        return 'futura';
    }
}

if (!function_exists('cb_quando')) {
    /* "Venceu há 5 dias", "Vence hoje", "Vence amanhã", "Vence em 3 dias", "Vence 15/11". */
    function cb_quando(string $venc, string $hoje): string {
        if ($venc === '') return 'Sem vencimento';
        try {
            $d = (int)(new DateTime($hoje))->diff(new DateTime($venc))->format('%r%a');
        } catch (Throwable $e) {
            return 'Vence ' . vn_date_br($venc);
        }
        if ($d < 0) return 'Venceu há ' . abs($d) . (abs($d) === 1 ? ' dia' : ' dias');
        if ($d === 0) return 'Vence hoje';
        if ($d === 1) return 'Vence amanhã';
        if ($d <= 7) return 'Vence em ' . $d . ' dias';
        return 'Vence ' . substr(vn_date_br($venc), 0, 5);
    }
}

if (!function_exists('cb_ico')) {
    function cb_ico(string $nome, string $extra = ''): string {
        return '<span class="material-symbols-outlined"' . $extra . ' aria-hidden="true">' . $nome . '</span>';
    }
}

if (!function_exists('cb_tag')) {
    function cb_tag(string $etq): string {
        return $etq === '' ? '' : ' <span class="vn-tag ' . vn_etiqueta_tag_class($etq) . '">' . vn_h($etq) . '</span>';
    }
}

if (!function_exists('cb_form_pago')) {
    /* Formulário (enviado sem recarregar) de marcar/desmarcar pagamento de uma parcela ou mensalidade. */
    function cb_form_pago(array $it, int $pago, string $rotulo, string $classe, string $icone = 'check'): string {
        if ($it['tipo'] === 'parcela') {
            $campos = '<input type="hidden" name="acao" value="marcar_parcela"><input type="hidden" name="parcela_id" value="' . (int)($it['prox']['id'] ?? 0) . '">';
        } else {
            $campos = '<input type="hidden" name="acao" value="marcar_pagamento"><input type="hidden" name="pagamento_id" value="' . (int)($it['raw']['pagamento_id'] ?? 0) . '">';
        }
        return '<form method="post" data-async' . ($pago === 1 ? ' data-undo' : '') . '>' . $campos
            . '<input type="hidden" name="pago" value="' . $pago . '">'
            . '<button type="submit" class="cb-btn ' . $classe . '">' . cb_ico($icone) . $rotulo . '</button></form>';
    }
}

if (!function_exists('cb_form_parcela_pago')) {
    /* Igual ao anterior, mas para uma parcela específica (dentro do detalhe da cobrança). */
    function cb_form_parcela_pago(array $p, int $pago, string $rotulo, string $classe, string $icone = 'check'): string {
        return '<form method="post" data-async' . ($pago === 1 ? ' data-undo' : '') . '>'
            . '<input type="hidden" name="acao" value="marcar_parcela"><input type="hidden" name="parcela_id" value="' . (int)$p['id'] . '">'
            . '<input type="hidden" name="pago" value="' . $pago . '">'
            . '<button type="submit" class="cb-btn sm ' . $classe . '">' . cb_ico($icone) . $rotulo . '</button></form>';
    }
}

if (!function_exists('cb_btn_pix')) {
    function cb_btn_pix(string $pix, string $classe = ''): string {
        if ($pix === '') return '<span class="cb-slot" aria-hidden="true"></span>';   // mantém os botões alinhados no PC
        return '<button type="button" class="cb-btn ' . $classe . '" data-copy="' . vn_h($pix) . '" data-copy-ok="PIX copia e cola copiado.">' . cb_ico('content_copy') . 'PIX</button>';
    }
}

if (!function_exists('cb_btn_wpp')) {
    function cb_btn_wpp(string $url, string $rotulo = 'Cobrar', string $classe = 'ok'): string {
        return '<a class="cb-btn ' . $classe . '" target="_blank" rel="noopener" href="' . vn_h($url) . '">' . cb_ico('chat') . $rotulo . '</a>';
    }
}

if (!function_exists('cb_whatsapp_varias')) {
    /* Uma mensagem só cobrando várias parcelas atrasadas de uma cobrança, com o PIX do total. */
    function cb_whatsapp_varias(array $cob, array $parcs): string {
        $fone = preg_replace('/\D+/', '', (string)($cob['telefone'] ?? ''));
        if ($fone !== '' && strlen($fone) <= 11) $fone = '55' . $fone;
        $soma = 0.0; $linhas = [];
        foreach ($parcs as $p) {
            $soma += (float)$p['valor'];
            $linhas[] = '- Parcela ' . (int)$p['numero'] . '/' . (int)$cob['parcelas_total'] . ': ' . vn_money($p['valor']) . ' (venceu em ' . vn_date_br($p['vencimento']) . ')';
        }
        $msg = 'Olá ' . trim((string)$cob['cliente_nome']) . ", tudo bem? Passando para lembrar das parcelas em aberto:\n" . implode("\n", $linhas) . "\n\nTotal: " . vn_money($soma) . '.';
        if (!empty($cob['chave_pix'])) $msg .= "\nChave PIX: " . $cob['chave_pix'];
        $pix = vn_pix_payload((string)($cob['chave_pix'] ?? ''), $soma);
        if ($pix !== '') $msg .= "\n\nPIX copia e cola (já com o valor total):\n" . $pix;
        return 'https://wa.me/' . $fone . '?text=' . rawurlencode($msg);
    }
}

if (!function_exists('cb_linha')) {
    /* Uma linha da lista. Clicar nela (ou em "abrir") mostra o detalhe. */
    function cb_linha(array $it, string $hoje): string {
        $estado = (string)$it['estado'];
        $dlg = 'd-' . $it['key'];
        $icone = ['parcela' => 'payments', 'mensalidade' => 'event_repeat', 'material' => 'inventory_2'][$it['tipo']] ?? 'payments';
        $busca = vn_lower($it['cliente'] . ' ' . $it['etq'] . ' ' . $it['titulo'] . ' ' . strip_tags((string)$it['sub']));

        if ($estado === 'paga') {
            $quando = '<span class="cb-venc paga">Pago' . ($it['pago_em'] !== '' ? ' em ' . vn_h(substr(vn_date_br($it['pago_em']), 0, 5)) : '') . '</span>';
        } elseif ($estado === 'aprovar') {
            $quando = '<span class="cb-venc">Aguardando aprovação</span>';
        } else {
            $quando = '<span class="cb-venc ' . ($estado === 'atrasada' ? 'atrasada' : ($estado === 'hoje' ? 'hoje' : '')) . '">' . vn_h(cb_quando($it['venc'], $hoje)) . '</span>';
        }

        $acoes = '';
        if ($estado === 'paga') {
            if ($it['tipo'] === 'mensalidade') $acoes .= cb_form_pago($it, 0, 'Desfazer', '', 'history');
            if ($it['tipo'] === 'parcela') $acoes .= '<a class="cb-btn" target="_blank" rel="noopener" href="cobranca-recibo-pdf.php?id=' . (int)$it['cob']['id'] . '">' . cb_ico('receipt_long') . 'Recibo</a>';
        } elseif ($it['tipo'] === 'material') {
            $acoes .= cb_btn_wpp($it['wpp']);
            $acoes .= '<button type="button" class="cb-btn" data-abrir="' . vn_h($dlg) . '">' . cb_ico('inventory_2') . 'Peças</button>';
            $acoes .= '<form method="post" data-async data-confirm="Aprovar a venda de ' . vn_h($it['cliente']) . ' (' . vn_h($it['mesLabel']) . ')? Ela vira uma cobrança de ' . vn_h(vn_money($it['valor'])) . '." data-confirm-ok="Aprovar venda">'
                . '<input type="hidden" name="acao" value="aprovar_venda"><input type="hidden" name="av_cliente" value="' . vn_h($it['cliente']) . '"><input type="hidden" name="av_mes" value="' . vn_h($it['mes']) . '">'
                . '<button type="submit" class="cb-btn pri">' . cb_ico('check') . 'Aprovar</button></form>';
        } else {
            $acoes .= cb_btn_wpp($it['wpp']);
            $acoes .= cb_btn_pix($it['pix']);
            // várias parcelas atrasadas na mesma linha: paga-se uma a uma dentro do detalhe
            $acoes .= !empty($it['multi'])
                ? '<button type="button" class="cb-btn pri" data-abrir="' . vn_h($dlg) . '">' . cb_ico('payments') . 'Parcelas</button>'
                : cb_form_pago($it, 1, 'Pago', 'pri');
        }
        $acoes .= '<button type="button" class="cb-btn icon" data-abrir="' . vn_h($dlg) . '" aria-label="Abrir detalhes de ' . vn_h($it['cliente']) . '">' . cb_ico('chevron_right') . '</button>';

        return '<article class="cb-row is-' . vn_h($estado) . '" data-estado="' . vn_h($estado) . '" data-tipo="' . vn_h($it['tipo']) . '"'
            . ' data-etq="' . vn_h($it['etq']) . '" data-valor="' . vn_h((string)round((float)$it['valor'], 2)) . '" data-busca="' . vn_h($busca) . '" data-dlg="' . vn_h($dlg) . '">'
            . '<span class="cb-ico">' . cb_ico($icone) . '</span>'
            . '<div class="cb-main"><button type="button" class="cb-name" data-abrir="' . vn_h($dlg) . '">' . vn_h($it['cliente']) . '</button>' . cb_tag($it['etq'])
            . '<div class="cb-sub">' . vn_h($it['titulo']) . ($it['sub'] !== '' ? ' · ' . $it['sub'] : '') . '</div></div>'
            . '<div class="cb-when"><strong class="cb-valor">' . vn_h(vn_money($it['valor'])) . '</strong>' . $quando . '</div>'
            . '<div class="cb-acts">' . $acoes . '</div>'
            . '</article>';
    }
}

if (!function_exists('cb_janela')) {
    /* Moldura de uma janela de detalhe/criação. $corpo e $rodape já vêm montados. */
    function cb_janela(string $id, string $titulo, string $sub, string $corpo, string $rodape = '', string $classe = ''): string {
        return '<dialog class="cb-sheet' . ($classe !== '' ? ' ' . $classe : '') . '" id="' . vn_h($id) . '" aria-labelledby="' . vn_h($id) . '-t"><div class="cb-sheet-in">'
            . '<div class="cb-sheet-head"><h3 id="' . vn_h($id) . '-t">' . $titulo . ($sub !== '' ? '<small>' . $sub . '</small>' : '') . '</h3>'
            . '<button type="button" class="cb-x" data-fechar aria-label="Fechar">' . cb_ico('close') . '</button></div>'
            . '<div class="cb-sheet-body">' . $corpo . '</div>'
            . ($rodape !== '' ? '<div class="cb-sheet-foot">' . $rodape . '</div>' : '')
            . '</div></dialog>';
    }
}

if (!function_exists('cb_fato')) {
    function cb_fato(string $rotulo, string $valor): string {
        return '<div class="cb-fact"><span>' . vn_h($rotulo) . '</span><strong>' . $valor . '</strong></div>';
    }
}

if (!function_exists('cb_montador_linhas')) {
    /* Linhas de parcelas já existentes (edição). Parcela paga não pode ser removida. */
    function cb_montador_linhas(array $parcelas): string {
        $h = '';
        foreach ($parcelas as $p) {
            $paga = (int)$p['pago'] === 1;
            $h .= '<div class="cb-pr"><span class="n"></span>'
                . '<input type="date" name="parc_venc[]" value="' . vn_h((string)$p['vencimento']) . '" required aria-label="Vencimento">'
                . '<input name="parc_valor[]" inputmode="decimal" value="' . vn_h(number_format((float)$p['valor'], 2, ',', '.')) . '" required aria-label="Valor da parcela">'
                . '<input type="hidden" name="parc_id[]" value="' . (int)$p['id'] . '">'
                . ($paga ? '<span class="pago" title="Parcela já paga: não pode ser removida">paga</span>'
                         : '<button type="button" class="del" data-pb-del aria-label="Remover parcela">×</button>')
                . '</div>';
        }
        return $h;
    }
}

if (!function_exists('cb_janela_parcela')) {
    /* Detalhe de uma cobrança em parcelas: parcelas, editar, recibo, voltar para venda e excluir. */
    function cb_janela_parcela(array $it, string $hoje, string $fimSemana, bool $veioDeVenda): string {
        $cob = $it['cob']; $cid = (int)$cob['id'];
        $total = (float)$cob['valor_total'];
        $nPagas = 0; foreach ($it['parcelas'] as $p) { if ((int)$p['pago'] === 1) $nPagas++; }
        $nTotal = count($it['parcelas']);

        $fatos = cb_fato('Total', vn_h(vn_money($total)) . ' em ' . (int)$cob['parcelas_total'] . 'x')
            . cb_fato('Já pago', vn_h(vn_money($it['somaPagas'])) . ' (' . $nPagas . '/' . $nTotal . ')')
            . cb_fato('Falta pagar', vn_h(vn_money($it['somaAberta'])))
            . cb_fato('Chave PIX', ($cob['chave_pix'] ?? '') !== '' ? vn_h((string)$cob['chave_pix']) : '—');

        $lista = '';
        foreach ($it['parcelas'] as $p) {
            $pago = (int)$p['pago'] === 1;
            $venc = (string)($p['vencimento'] ?? '');
            $est = $pago ? 'paga' : cb_estado_venc($venc, $hoje, $fimSemana);
            $info = $pago ? 'Paga' . (!empty($p['pago_em']) ? ' em ' . vn_date_br($p['pago_em']) : '') : cb_quando($venc, $hoje) . ' · ' . vn_date_br($venc);
            $acts = $pago
                ? cb_form_parcela_pago($p, 0, 'Desfazer', '', 'history')
                : cb_btn_wpp(vn_cobranca_whatsapp($cob, $p), 'Cobrar', 'ok sm') . cb_btn_pix(vn_cobranca_pix($cob, $p), 'sm') . cb_form_parcela_pago($p, 1, 'Pago', 'pri');
            $lista .= '<div class="cb-parc is-' . ($est === 'hoje' || $est === 'semana' || $est === 'futura' ? 'aberta' : $est) . '">'
                . '<span class="num">' . (int)$p['numero'] . '/' . (int)$cob['parcelas_total'] . '</span>'
                . '<div class="info"><strong>' . vn_h(vn_money($p['valor'])) . '</strong><small>' . vn_h($info) . '</small></div>'
                . '<div class="acts">' . $acts . '</div></div>';
        }

        $hoje0 = vn_h($hoje);
        $edicao = '<form method="post" class="cb-form" data-async data-pb-form data-vista="editar" hidden>'
            . '<input type="hidden" name="acao" value="editar_cobranca"><input type="hidden" name="cobranca_id" value="' . $cid . '">'
            . '<div class="cb-f"><label>Cliente *</label><input name="cliente_nome" required value="' . vn_h((string)$cob['cliente_nome']) . '"></div>'
            . '<div class="cb-f"><label>WhatsApp / telefone</label><input name="telefone" inputmode="tel" value="' . vn_h((string)($cob['telefone'] ?? '')) . '"></div>'
            . '<div class="cb-f full"><label>Chave PIX</label><input name="chave_pix" value="' . vn_h((string)($cob['chave_pix'] ?? '')) . '" placeholder="CPF, e-mail, telefone ou aleatória"></div>'
            . '<div class="cb-f full"><label>Observação</label><textarea name="observacao">' . vn_h((string)($cob['observacao'] ?? '')) . '</textarea></div>'
            . '<div class="cb-f full"><span class="lbl">Parcelas</span>'
            . '<div class="cb-pb" data-pb data-hoje="' . $hoje0 . '"><div class="cb-pb-rows" data-pb-rows>' . cb_montador_linhas($it['parcelas']) . '</div>'
            . '<div class="cb-pb-foot"><button type="button" class="cb-btn sm" data-pb-add>' . cb_ico('add') . 'Adicionar parcela</button>'
            . '<span class="cb-pb-total">Total · <span data-pb-count>0</span>x · <span data-pb-total>R$ 0,00</span></span></div>'
            . '<p class="cb-pb-hint">Parcelas pagas não podem ser removidas: desfaça o pagamento antes.</p></div></div>'
            . '<div class="cb-f full" style="flex-direction:row;gap:8px"><button type="submit" class="cb-btn pri" style="min-height:46px">Salvar alterações</button>'
            . '<button type="button" class="cb-btn" style="min-height:46px" data-vista-ir="ver">Cancelar</button></div></form>';

        $corpo = '<div data-vista="ver"><div class="cb-facts">' . $fatos . '</div>'
            . (!empty($cob['observacao']) ? '<p class="cb-note" style="margin-top:12px">' . vn_h((string)$cob['observacao']) . '</p>' : '')
            . '<h4 class="cb-h" style="margin-top:16px">Parcelas</h4><div class="cb-parcs">' . $lista . '</div></div>' . $edicao;

        $rodape = '<button type="button" class="cb-btn" data-vista-ir="editar">' . cb_ico('edit') . 'Editar</button>'
            . '<a class="cb-btn" target="_blank" rel="noopener" href="cobranca-recibo-pdf.php?id=' . $cid . '">' . cb_ico('receipt_long') . 'Recibo (PDF)</a>';
        if ($veioDeVenda) {
            $rodape .= '<form method="post" data-async data-danger data-confirm="Desfazer esta cobrança e devolver o material para a lista de aprovação?" data-confirm-ok="Voltar para venda">'
                . '<input type="hidden" name="acao" value="voltar_venda"><input type="hidden" name="cobranca_id" value="' . $cid . '">'
                . '<button type="submit" class="cb-btn">' . cb_ico('history') . 'Voltar para venda</button></form>';
        }
        $rodape .= '<form method="post" class="push" data-async data-danger data-confirm="Excluir esta cobrança e todas as parcelas? Não dá para desfazer." data-confirm-ok="Excluir">'
            . '<input type="hidden" name="acao" value="excluir_cobranca"><input type="hidden" name="cobranca_id" value="' . $cid . '">'
            . '<button type="submit" class="cb-btn bad">' . cb_ico('delete') . 'Excluir</button></form>';

        return cb_janela('d-' . $it['key'], vn_h((string)$cob['cliente_nome']) . cb_tag($it['etq']),
            ($veioDeVenda ? 'Venda de material' : 'Cobrança avulsa') . ' · criada em ' . vn_h(vn_date_br(substr((string)$cob['criado_em'], 0, 10))), $corpo, $rodape);
    }
}

if (!function_exists('cb_janela_mensalidade')) {
    /* Detalhe de uma mensalidade: pagar/desfazer, cobrar, editar na própria janela (ver ⇄ editar), pausar/reativar. */
    function cb_janela_mensalidade(array $it, string $hoje): string {
        $r = $it['raw'];
        $pago = $it['estado'] === 'paga';
        $mid = (int)($r['mensalidade_id'] ?? 0);
        $ativa = (int)($r['ativo'] ?? 1) === 1;
        $valorMensal = (float)($r['valor_mensal'] ?? $r['valor'] ?? 0);
        $sit = $pago ? 'Paga' . (!empty($r['pago_em']) ? ' em ' . vn_date_br($r['pago_em']) : '')
                     : cb_quando((string)$it['venc'], $hoje);
        $ver = '<div data-vista="ver"><div class="cb-facts">'
            . cb_fato('Serviço', vn_h(vn_mens_categoria($r)))
            . cb_fato('Mês', vn_h(substr((string)$r['competencia'], 5, 2) . '/' . substr((string)$r['competencia'], 0, 4)))
            . cb_fato('Valor', vn_h(vn_money($r['valor'])))
            . cb_fato('Vencimento', vn_h(vn_date_br((string)($r['vencimento'] ?? ''))))
            . cb_fato('Situação', vn_h($sit))
            . cb_fato('Telefone', ($r['telefone'] ?? '') !== '' ? vn_h((string)$r['telefone']) : '—')
            . cb_fato('Chave PIX', ($r['chave_pix'] ?? '') !== '' ? vn_h((string)$r['chave_pix']) : '—')
            . cb_fato('Todo mês', 'dia ' . (int)($r['dia_vencimento'] ?? 0)) . '</div>'
            . (!empty($r['observacao']) ? '<p class="cb-note" style="margin-top:12px">' . vn_h((string)$r['observacao']) . '</p>' : '')
            . (!$ativa ? '<p class="cb-note" style="margin-top:12px">Esta mensalidade está pausada: não gera mais meses novos. Use "Reativar" abaixo para voltar.</p>' : '')
            . '</div>';

        $edicao = '<form method="post" class="cb-form" data-async data-vista="editar" hidden>'
            . '<input type="hidden" name="acao" value="editar_mensalidade"><input type="hidden" name="mensalidade_id" value="' . $mid . '">'
            . '<div class="cb-f full"><label>Cliente *</label><input name="cliente_nome" required value="' . vn_h((string)$r['cliente_nome']) . '"></div>'
            . '<div class="cb-f full"><label>Serviço cobrado</label><input name="categoria" list="cb-cat-lista" maxlength="80" value="' . vn_h((string)($r['categoria'] ?? '')) . '" placeholder="Ex.: Portaria remota, Monitoramento…"></div>'
            . '<div class="cb-f"><label>Valor mensal (R$) *</label><input name="valor_mensal" inputmode="decimal" required autocomplete="off" value="' . vn_h(number_format($valorMensal, 2, ',', '.')) . '"></div>'
            . '<div class="cb-f"><label>Vence todo dia *</label><input name="dia_vencimento" type="number" min="1" max="31" required inputmode="numeric" value="' . (int)($r['dia_vencimento'] ?? 10) . '"></div>'
            . '<div class="cb-f"><label>WhatsApp / telefone</label><input name="telefone" inputmode="tel" value="' . vn_h((string)($r['telefone'] ?? '')) . '"></div>'
            . '<div class="cb-f"><label>Chave PIX</label><input name="chave_pix" value="' . vn_h((string)($r['chave_pix'] ?? '')) . '" placeholder="CPF, e-mail, telefone ou aleatória"></div>'
            . '<div class="cb-f full"><label>Observação</label><textarea name="observacao">' . vn_h((string)($r['observacao'] ?? '')) . '</textarea></div>'
            . '<p class="cb-note full">Os meses que ainda não foram pagos passam a usar o novo valor e o novo dia de vencimento. Os já pagos não mudam.</p>'
            . '<div class="cb-f full" style="flex-direction:row;gap:8px"><button type="submit" class="cb-btn pri" style="min-height:46px">Salvar alterações</button>'
            . '<button type="button" class="cb-btn" style="min-height:46px" data-vista-ir="ver">Cancelar</button></div></form>';

        $pausar = '<form method="post" data-async'
            . ($ativa ? ' data-danger data-confirm="Pausar esta mensalidade? Ela deixa de gerar cobrança nos próximos meses. Dá para reativar depois."' : '') . '>'
            . '<input type="hidden" name="acao" value="alternar_mensalidade"><input type="hidden" name="mensalidade_id" value="' . $mid . '">'
            . '<button type="submit" class="cb-btn"' . ($ativa ? ' data-confirm-ok="Pausar"' : '') . '>' . cb_ico($ativa ? 'schedule' : 'refresh') . ($ativa ? 'Pausar' : 'Reativar') . '</button></form>';

        $rodape = ($pago ? cb_form_pago($it, 0, 'Desfazer pagamento', '', 'history')
                         : cb_form_pago($it, 1, 'Marcar como pago', 'pri') . cb_btn_wpp($it['wpp']) . cb_btn_pix($it['pix']))
            . '<button type="button" class="cb-btn" data-vista-ir="editar">' . cb_ico('edit') . 'Editar</button>'
            . $pausar
            . '<a class="cb-btn push" href="mensalidades.php">' . cb_ico('event_repeat') . 'Histórico e exclusão</a>';
        return cb_janela('d-' . $it['key'], vn_h((string)$r['cliente_nome']) . cb_tag($it['etq']), 'Mensalidade · ' . vn_h(vn_mens_categoria($r)), $ver . $edicao, $rodape);
    }
}

if (!function_exists('cb_janela_material')) {
    function cb_janela_material(array $it): string {
        $d = $it['dados'];
        $pecas = '';
        foreach ($d['itens'] as $i) {
            $pid = (int)$i['id'];
            $semValor = (float)$i['valor_unitario'] <= 0;
            $pecas .= '<div class="cb-peca"><div class="cb-peca-top"><strong>' . vn_h($i['item_nome']) . '</strong><span class="tot">' . vn_h(vn_money($i['valor_total'])) . '</span>'
                . '<small>' . vn_h(number_format((float)$i['quantidade'], 2, ',', '.')) . ' × ' . vn_h(vn_money($i['valor_unitario'])) . ' · '
                . vn_h(date('d/m/Y', strtotime((string)($i['criado_em'] ?? 'now')))) . ($semValor ? ' · <span class="warn">sem valor: edite</span>' : '') . '</small></div>'
                . '<div class="cb-peca-acts"><button type="button" class="cb-btn sm" data-alternar="pe-' . $pid . '">' . cb_ico('edit') . 'Editar</button>'
                . '<form method="post" data-async data-danger data-confirm="Excluir esta peça da venda? A quantidade volta para o estoque." data-confirm-ok="Excluir">'
                . '<input type="hidden" name="acao" value="excluir_peca"><input type="hidden" name="peca_id" value="' . $pid . '">'
                . '<button type="submit" class="cb-btn sm bad">' . cb_ico('delete') . 'Excluir peça</button></form></div>'
                . '<form method="post" class="cb-peca-edit" id="pe-' . $pid . '" data-async hidden><input type="hidden" name="acao" value="editar_peca"><input type="hidden" name="peca_id" value="' . $pid . '">'
                . '<div class="cb-f full"><label>Nome na cobrança</label><input name="item_nome" maxlength="190" value="' . vn_h((string)$i['item_nome']) . '" required></div>'
                . '<div class="cb-f"><label>Quantidade</label><input name="quantidade" inputmode="decimal" value="' . vn_h(number_format((float)$i['quantidade'], 2, ',', '.')) . '" required></div>'
                . '<div class="cb-f"><label>Valor unitário (R$)</label><input name="valor_unitario" inputmode="decimal" value="' . vn_h(number_format((float)$i['valor_unitario'], 2, ',', '.')) . '" required></div>'
                . '<div class="cb-f full"><label>Data da venda desta peça</label><input type="date" name="data_peca" value="' . vn_h(substr((string)($i['criado_em'] ?? ''), 0, 10)) . '" required></div>'
                . '<div class="cb-f full"><button type="submit" class="cb-btn pri">Salvar peça</button></div></form></div>';
        }
        // datas das peças desta venda: uma só ("12/11/2026") ou o intervalo ("03/11 a 12/11")
        $datas = [];
        foreach ($d['itens'] as $i) { $dd = substr((string)($i['criado_em'] ?? ''), 0, 10); if ($dd !== '') $datas[$dd] = true; }
        $datas = array_keys($datas); sort($datas);
        $textoData = !$datas ? '—' : (count($datas) === 1 ? vn_date_br($datas[0]) : substr(vn_date_br($datas[0]), 0, 5) . ' a ' . substr(vn_date_br($datas[count($datas) - 1]), 0, 5));
        $dataBase = $datas ? $datas[count($datas) - 1] : date('Y-m-d');
        $formData = '<form method="post" class="cb-datavenda" data-async>'
            . '<input type="hidden" name="acao" value="mudar_data_venda"><input type="hidden" name="av_cliente" value="' . vn_h($it['cliente']) . '"><input type="hidden" name="av_mes" value="' . vn_h($it['mes']) . '">'
            . '<div class="cb-f"><label>Data da venda</label><input type="date" name="nova_data" value="' . vn_h($dataBase) . '" required></div>'
            . '<button type="submit" class="cb-btn">' . cb_ico('event') . 'Mudar data</button></form>';
        $corpo = '<div class="cb-facts">' . cb_fato('Total', vn_h(vn_money($it['valor']))) . cb_fato('Data', vn_h($textoData))
            . cb_fato('Peças', (string)count($d['itens'])) . cb_fato('Situação', 'Aguardando aprovação') . '</div>'
            . $formData
            . '<p class="cb-note cb-dica">Muda a data de todas as peças desta venda. Para uma peça só, use Editar nela.</p>'
            . ($it['semValor'] > 0 ? '<p class="cb-note">' . (int)$it['semValor'] . ' peça(s) sem valor. Edite o valor antes de aprovar.</p>' : '')
            . '<div><h4 class="cb-h">Peças e serviços</h4><div class="cb-pecas">' . $pecas . '</div></div>';
        $rodape = '<form method="post" class="cb-aprovar" data-async data-confirm="Aprovar a venda de ' . vn_h($it['cliente']) . ' (' . vn_h($it['mesLabel']) . ')? Ela vira uma cobrança de ' . vn_h(vn_money($it['valor'])) . '." data-confirm-ok="Aprovar venda">'
            . '<input type="hidden" name="acao" value="aprovar_venda"><input type="hidden" name="av_cliente" value="' . vn_h($it['cliente']) . '"><input type="hidden" name="av_mes" value="' . vn_h($it['mes']) . '">'
            . '<label class="cb-venc-in"><span>Vence em</span><input type="date" name="av_venc" value="' . vn_h(date('Y-m-d')) . '" required aria-label="Vencimento da cobrança"></label>'
            . '<button type="submit" class="cb-btn pri">' . cb_ico('check') . 'Aprovar venda</button></form>'
            . '<button type="button" class="cb-btn" data-add-peca data-cliente-id="' . (int)$d['cliente_id'] . '" data-cliente-nome="' . vn_h($it['cliente']) . '">' . cb_ico('add') . 'Peça / serviço</button>'
            . cb_btn_wpp($it['wpp']);
        return cb_janela('d-' . $it['key'], vn_h($it['cliente']) . cb_tag($it['etq']), 'Venda de material · ' . vn_h($it['mesLabel']) . ' · ' . vn_h($textoData), $corpo, $rodape);
    }
}

if (!function_exists('cb_catalogo_lista')) {
    /*
        Lista de peças e serviços do catálogo na janela "Cobrar peça ou serviço". Cada item tem "Editar"; no topo, "Novo material".
        O conteúdo todo mora em #cb-peca-lista: depois de salvar um material o JS troca só essa parte (sem perder o que já foi marcado).
    */
    function cb_catalogo_lista(array $catalogoPecas, array $catalogoServicos): string {
        $h = '<div id="cb-peca-lista">'
            . '<div class="cb-cat-topo"><span>Peças e serviços do catálogo</span>'
            . '<button type="button" class="cb-btn sm" data-cat-novo>' . cb_ico('add') . 'Novo material</button></div>';
        if (!$catalogoPecas && !$catalogoServicos) {
            $h .= '<p class="cb-note">Nenhum material no catálogo ainda. Toque em "Novo material" para cadastrar, ou escreva um serviço acima.</p>';
        }
        foreach ([['Peças do estoque', $catalogoPecas, false], ['Serviços do catálogo', $catalogoServicos, true]] as [$tituloGrupo, $grupo, $ehServico]) {
            if (!$grupo) continue;
            $h .= '<div class="cb-grp">' . vn_h($tituloGrupo) . '</div>';
            foreach ($grupo as $c) {
                $dados = [
                    'id' => (int)$c['id'], 'tipo' => (string)$c['tipo'], 'nome' => (string)$c['nome'],
                    'descricao' => ((string)($c['descricao'] ?? '') === (string)$c['nome']) ? '' : (string)($c['descricao'] ?? ''),
                    'foto' => (string)($c['foto_url'] ?? ''), 'unidade' => (string)($c['unidade'] ?? 'un'),
                    'valor' => number_format((float)($c['valor_unitario'] ?? 0), 2, ',', '.'),
                    'custo' => number_format((float)($c['custo_unitario'] ?? 0), 2, ',', '.'),
                    'estoque' => number_format((float)($c['quantidade_estoque'] ?? 0), 2, ',', '.'),
                    'linha' => (string)($c['linha'] ?? 'cftv'),
                ];
                $json = json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                $h .= '<div class="cb-sel" data-sel data-cat-id="' . (int)$c['id'] . '" data-cat="' . vn_h((string)$json) . '" data-busca="' . vn_h(vn_lower(($c['nome'] ?? '') . ' ' . ($c['descricao'] ?? ''))) . '">'
                    . (!empty($c['foto_url']) ? '<img src="' . vn_h($c['foto_url']) . '" alt="" loading="lazy">' : '<span class="sem-img"></span>')
                    . '<div class="info"><strong>' . vn_h((string)$c['nome']) . '</strong>'
                    . '<small>' . ($ehServico ? 'Serviço (não mexe no estoque)' : 'Estoque: ' . vn_h(number_format((float)($c['quantidade_estoque'] ?? 0), 2, ',', '.')) . ' ' . vn_h((string)($c['unidade'] ?? 'un')))
                    . ' · valor sugerido ' . vn_h(vn_money($c['valor_unitario'] ?? 0)) . '</small>'
                    . '<button type="button" class="cb-edit" data-cat-edit aria-label="Editar ' . vn_h((string)$c['nome']) . '">' . cb_ico('edit') . 'Editar material</button></div>'
                    . '<span class="chk">✓</span>'
                    . '<div class="campos">'
                    . '<input type="hidden" name="peca_catalogo_id[]" value="' . (int)$c['id'] . '" disabled>'
                    . '<label>Qtd<input name="peca_qtd[]" inputmode="decimal" value="1" disabled></label>'
                    . '<label>Valor unit. (R$)<input name="peca_valor[]" inputmode="decimal" value="' . vn_h(number_format((float)($c['valor_unitario'] ?? 0), 2, ',', '.')) . '" disabled></label>'
                    . '</div></div>';
            }
        }
        return $h . '</div>';
    }
}

if (!function_exists('cb_janela_catalogo')) {
    /* Janela (por cima de "Cobrar peça ou serviço") para cadastrar ou editar um material: nome, tipo, valor, custo, estoque, foto. */
    function cb_janela_catalogo(): string {
        $tipos = '';
        foreach (vn_cobr_tipos_catalogo() as $k => $rot) $tipos .= '<option value="' . vn_h($k) . '">' . vn_h($rot) . '</option>';
        return '<dialog class="cb-sheet cb-catmodal" id="cb-cat-modal" aria-labelledby="cb-cat-modal-t"><div class="cb-sheet-in">'
            . '<form method="post" enctype="multipart/form-data" id="cb-form-cat" data-cat-form style="display:flex;flex-direction:column;min-height:0;flex:1">'
            . '<input type="hidden" name="acao" value="salvar_catalogo_cb"><input type="hidden" name="catalogo_id" value="0"><input type="hidden" name="catalogo_estoque_orig" value="">'
            . '<div class="cb-sheet-head"><h3 id="cb-cat-modal-t"><span data-cat-titulo>Novo material</span><small>O que você mudar aqui vale também no estoque e nos orçamentos.</small></h3>'
            . '<button type="button" class="cb-x" data-fechar aria-label="Fechar">' . cb_ico('close') . '</button></div>'
            . '<div class="cb-sheet-body"><div class="cb-form">'
            . '<div class="cb-f full"><label for="cb-cat-nome">Nome *</label><input id="cb-cat-nome" name="catalogo_nome" required maxlength="190" autocomplete="off" placeholder="Ex.: Cabo UTP cat6, Câmera bullet, Visita técnica"></div>'
            . '<div class="cb-f"><label for="cb-cat-tipo">Tipo</label><select id="cb-cat-tipo" name="catalogo_tipo">' . $tipos . '</select></div>'
            . '<div class="cb-f"><label for="cb-cat-linha">Linha</label><select id="cb-cat-linha" name="catalogo_linha"><option value="cftv">CFTV</option><option value="serralheria">Serralheria</option><option value="geral">CFTV e serralheria (só serviço)</option></select></div>'
            . '<div class="cb-f"><label for="cb-cat-valor">Valor de venda (R$) *</label><input id="cb-cat-valor" name="catalogo_valor" inputmode="decimal" required placeholder="0,00" autocomplete="off"></div>'
            . '<div class="cb-f"><label for="cb-cat-custo">Custo (R$)</label><input id="cb-cat-custo" name="catalogo_custo" inputmode="decimal" placeholder="0,00" autocomplete="off"></div>'
            . '<div class="cb-f" data-cat-fisico><label for="cb-cat-un">Unidade</label><input id="cb-cat-un" name="catalogo_unidade" maxlength="30" value="un" autocomplete="off"></div>'
            . '<div class="cb-f" data-cat-fisico><label for="cb-cat-qtd">Quantidade em estoque</label><input id="cb-cat-qtd" name="catalogo_quantidade_estoque" inputmode="decimal" placeholder="0,00" autocomplete="off"></div>'
            . '<div class="cb-f full"><label for="cb-cat-foto">Foto</label>'
            . '<div class="cb-cat-foto"><img data-cat-foto-img alt="" hidden><input id="cb-cat-foto" type="file" name="catalogo_foto" accept="image/*"></div>'
            . '<small data-cat-foto-dica>Tire uma foto ou escolha da galeria.</small></div>'
            . '<div class="cb-f full"><label for="cb-cat-desc">Descrição</label><textarea id="cb-cat-desc" name="catalogo_descricao" placeholder="Opcional: aparece nos orçamentos"></textarea></div>'
            . '</div></div>'
            . '<div class="cb-sheet-foot"><button type="button" class="cb-btn" data-fechar>Cancelar</button><button type="submit" class="cb-btn pri grow" data-cat-ok>Salvar material</button></div>'
            . '</form></div></dialog>';
    }
}
