<?php
/*
 * Página antiga, substituída por admin-calendario.php.
 *
 * O arquivo original chamava app_header()/app_footer(), funções que não existem
 * mais — abrir esta página dava erro 500. Fica só o redirecionamento para os
 * favoritos e atalhos antigos continuarem funcionando.
 */
header('Location: admin-calendario.php', true, 301);
exit;
