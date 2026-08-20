/**
 * Webhook opcional: grava cada cadastro do Wi-Fi numa planilha do Google.
 *
 * Como usar
 *  1. Crie uma planilha nova no Google Sheets.
 *  2. Menu Extensoes > Apps Script, apague tudo e cole este arquivo.
 *  3. Implantar > Nova implantacao > tipo "App da Web".
 *       Executar como:    Eu
 *       Quem pode acessar: Qualquer pessoa
 *  4. Copie a URL gerada (https://script.google.com/macros/s/.../exec)
 *     e cole em CONFIG.webhook dentro de hotspot/login.html.
 *  5. No MikroTik, libere no walled garden:
 *       /ip hotspot walled-garden add dst-host=script.google.com
 *       /ip hotspot walled-garden add dst-host=script.googleusercontent.com
 */

function doPost(e) {
  var aba = SpreadsheetApp.getActiveSpreadsheet().getSheets()[0];

  if (aba.getLastRow() === 0) {
    aba.appendRow([
      'Data/hora', 'Nome completo', 'E-mail', 'Bloco', 'Apartamento',
      'Celular', 'AP / Local', 'Confere?', 'MAC', 'IP', 'Servidor', 'Roteador'
    ]);
    aba.setFrozenRows(1);
  }

  var d = {};
  try { d = JSON.parse(e.postData.contents); } catch (err) { d = e.parameter || {}; }

  aba.appendRow([
    new Date(),
    d.nome  || '',
    d.email || '',
    d.bloco || '',
    d.ap    || '',
    d.fone  || '',
    d.local || '',
    d.confere || 'ok',
    d.mac   || '',
    d.ip    || '',
    d.servidor || '',
    d.roteador || ''
  ]);

  return ContentService
    .createTextOutput(JSON.stringify({ ok: true }))
    .setMimeType(ContentService.MimeType.JSON);
}


function doGet() {
  return ContentService.createTextOutput('Webhook do Wi-Fi ativo.');
}
