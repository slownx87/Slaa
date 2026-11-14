<?php
/* A tabela de preços da serralheria não existe mais: cada produto define o seu cálculo (m², metro, degrau, hora, kg)
   quando é cadastrado no orçamento. Quem ainda abrir o endereço antigo vai para o orçamento de serralheria. */
header('Location: orcamento-novo.php?cat=serralheria', true, 302);
exit;
