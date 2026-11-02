-- Correção para turnos que começam em um dia e terminam no outro.
-- Use só se já existirem saídas/intervalos salvos no dia seguinte com data_ponto errada.
-- Faça backup antes de executar.

UPDATE ponto_registros r
JOIN ponto_registros e
  ON e.funcionario_id = r.funcionario_id
 AND e.tipo = 'entrada'
 AND e.data_ponto = DATE_SUB(r.data_ponto, INTERVAL 1 DAY)
LEFT JOIN ponto_registros s
  ON s.funcionario_id = e.funcionario_id
 AND s.tipo = 'saida'
 AND s.data_ponto = e.data_ponto
SET r.data_ponto = e.data_ponto
WHERE r.tipo IN ('saida_almoco','volta_almoco','saida_janta','volta_janta','saida_descanso','volta_descanso','saida')
  AND r.data_ponto = DATE_ADD(e.data_ponto, INTERVAL 1 DAY)
  AND r.data_hora > e.data_hora
  AND r.data_hora < DATE_ADD(e.data_hora, INTERVAL 24 HOUR)
  AND s.id IS NULL;
