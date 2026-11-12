<?php
/*
    VN System - Menu compartilhado de TODAS as páginas do sistema.
    - Usado pelo layout único (_vn_ui.php) em todas as telas internas, via vn_admin_menu_grupos()
    Para adicionar/remover página do menu, edite apenas a lista abaixo — muda em todas as telas.

    Formato de cada item: [chave, arquivo, ícone FontAwesome, ícone Material Symbols, rótulo]
*/

if (!function_exists('vn_admin_menu_grupos')) {
    function vn_admin_menu_grupos(): array
    {
        $grupos = [
            'Visão geral' => [
                ['visao',          'visao-geral.php',           'fa-gauge-high',           'space_dashboard',            'Visão Geral'],
                ['painel',         'painel-admin.php',          'fa-chart-line',           'monitoring',                 'Painel & Agenda'],
                ['agenda',         'agenda.php',                'fa-calendar-days',        'calendar_month',             'Calendário'],
            ],
            'Vendas & Financeiro' => [
                ['pedidos',        'pedidos-dashboard.php',     'fa-calculator',           'receipt_long',               'Orçamentos & Pedidos'],
                // Vendas de material, mensalidades e PIX avulso ficam dentro de Cobranças.
                ['cobrancas',      'cobrancas.php',             'fa-money-bill-wave',      'payments',                   'Cobranças'],
                ['relatorios',     'orcamentos-dashboard.php',  'fa-chart-column',         'bar_chart',                  'Relatórios Financeiros'],
                ['leads',          'leads.php',                 'fa-bullhorn',             'campaign',                   'Contatos do site'],
            ],
            'Ordens de Serviço' => [
                ['gerar-os',       'os-admin.php',              'fa-file-circle-plus',     'note_add',                   'Gerar OS'],
                ['gerar-os-serralheria', 'os-admin.php?linha=serralheria', 'fa-hammer',     'build',                      'Gerar OS de Serralheria'],
                ['area-os',        'iniciar.php',               'fa-screwdriver-wrench',   'construction',               'Área de OS'],
                ['area-os-serralheria', 'iniciar.php?linha=serralheria&tab=todos', 'fa-hammer', 'handyman',              'OS de Serralheria'],
                ['os-concluidas',  'os-concluidas.php',         'fa-file-circle-check',    'task',                       'OS Concluídas (PDF)'],
            ],
            'Estoque' => [
                ['estoque',        'estoque.php',               'fa-boxes-stacked',        'inventory_2',                'Inventário'],
                ['saidas',         'saidas-estoque.php',        'fa-box-open',             'output',                     'Saídas de Estoque'],
                ['saidas-dia',     'saidas-dia.php',            'fa-clipboard-check',      'fact_check',                 'Saídas do Dia'],
                ['retiradas',      'retirada-materiais.php',    'fa-signature',            'signature',                  'Retiradas Assinadas'],
                ['ferramentas',    'ferramentas.php',           'fa-toolbox',              'handyman',                   'Ferramentas'],
                ['compras',        'compras.php',               'fa-cart-shopping',        'shopping_cart',              'Lista de Compras'],
                ['faltando',       'estoque-baixo.php',         'fa-triangle-exclamation', 'production_quantity_limits', 'Estoque Baixo'],
            ],
            'Parceiros' => [
                ['parceiros',        'estoque-parceiros.php',   'fa-warehouse',            'warehouse',                  'Estoque de Parceiros'],
                ['saidas-parceiros', 'saidas-parceiros.php',    'fa-truck-ramp-box',       'move_up',                    'Saídas de Parceiros'],
            ],
            'Cadastros' => [
                ['clientes',       'clientes-admin.php',        'fa-building',             'apartment',                  'Clientes'],
                ['usuarios',       'usuarios.php',              'fa-users',                'group',                      'Usuários'],
            ],
        ];

        // Cada cargo vê só as páginas que pode abrir (permissões em vn_role_pages, _vn_security.php).
        $cargo = strtolower(trim((string)($_SESSION['cargo'] ?? '')));
        if ($cargo === 'funcionario') {
            foreach ($grupos as $titulo => $itens) {
                foreach ($itens as $i => $it) {
                    if ($it[0] === 'gerar-os') { $grupos[$titulo][$i][1] = 'os-funcionario.php'; }
                    if ($it[0] === 'area-os')  { $grupos[$titulo][$i][1] = 'iniciar-funcionario.php'; }
                    if ($it[0] === 'gerar-os-serralheria') { $grupos[$titulo][$i][1] = 'os-funcionario.php?linha=serralheria'; }
                    if ($it[0] === 'area-os-serralheria')  { $grupos[$titulo][$i][1] = 'iniciar-funcionario.php?linha=serralheria&tab=todos'; }
                }
            }
        }
        if ($cargo !== 'admin' && function_exists('vn_role_allows')) {
            foreach ($grupos as $titulo => $itens) {
                $grupos[$titulo] = array_values(array_filter($itens, static function ($it) use ($cargo) {
                    return vn_role_allows($cargo, explode('?', $it[1])[0]);   // o ?linha=... não conta na permissão da página
                }));
                if (!$grupos[$titulo]) {
                    unset($grupos[$titulo]);
                }
            }
        }
        return $grupos;
    }
}
