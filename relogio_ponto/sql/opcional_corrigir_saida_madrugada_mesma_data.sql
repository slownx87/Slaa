-- OPCIONAL: use apenas se quiser corrigir registros antigos no banco.
-- Antes, rode o SELECT para conferir quais registros seriam alterados.
-- Regra: funcionário tem Entrada a partir de 17:00 no mesmo data_ponto
-- e uma Saída/Volta cadastrada entre 00:00 e 08:00 com a mesma data.

SELECT r_saida.*
FROM ponto_registros r_saida
WHERE r_saida.tipo <> 'entrada'
  AND DATE(r_saida.data_hora) = r_saida.data_ponto
  AND TIME(r_saida.data_hora) BETWEEN '00:00:00' AND '08:00:00'
  AND EXISTS (
      SELECT 1
      FROM ponto_registros r_entrada
      WHERE r_entrada.funcionario_id = r_saida.funcionario_id
        AND r_entrada.data_ponto = r_saida.data_ponto
        AND r_entrada.tipo = 'entrada'
        AND TIME(r_entrada.data_hora) >= '17:00:00'
  );

-- Depois de conferir o SELECT acima, remova os comentários do UPDATE abaixo se quiser corrigir definitivamente.

-- UPDATE ponto_registros r_saida
-- SET r_saida.data_hora = DATE_ADD(r_saida.data_hora, INTERVAL 1 DAY),
--     r_saida.observacao = CONCAT(COALESCE(r_saida.observacao, ''), '\n[Correção automática: saída de madrugada movida para o dia seguinte]')
-- WHERE r_saida.tipo <> 'entrada'
--   AND DATE(r_saida.data_hora) = r_saida.data_ponto
--   AND TIME(r_saida.data_hora) BETWEEN '00:00:00' AND '08:00:00'
--   AND EXISTS (
--       SELECT 1
--       FROM ponto_registros r_entrada
--       WHERE r_entrada.funcionario_id = r_saida.funcionario_id
--         AND r_entrada.data_ponto = r_saida.data_ponto
--         AND r_entrada.tipo = 'entrada'
--         AND TIME(r_entrada.data_hora) >= '17:00:00'
--   );
