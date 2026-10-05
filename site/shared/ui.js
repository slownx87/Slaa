/*
 * Pequenos comportamentos de interface reaproveitados entre páginas.
 * Hoje só tem uma coisa: confirmação em 2 toques antes de apagar algo
 * salvo (sem modal, sem dependência nova — só troca o texto do botão
 * no primeiro toque e só executa de verdade no segundo).
 */
(function (global) {
  "use strict";

  // Liga um botão de "Remover" para pedir confirmação antes de executar.
  // No primeiro clique, o botão vira "Confirmar remoção?" por alguns
  // segundos; clicar de novo (nesse intervalo) executa aoConfirmar().
  // Clicar em qualquer outro lugar, ou esperar o tempo passar, cancela.
  function confirmarAntes(botao, aoConfirmar) {
    var pendente = false;
    var timeoutId = null;
    var textoOriginal = botao.textContent;

    function cancelar() {
      pendente = false;
      if (timeoutId) { clearTimeout(timeoutId); timeoutId = null; }
      botao.textContent = textoOriginal;
      botao.classList.remove("confirmando");
      botao.removeAttribute("aria-label");
    }

    botao.addEventListener("click", function () {
      if (pendente) { cancelar(); aoConfirmar(); return; }
      pendente = true;
      botao.textContent = "Confirmar remoção?";
      botao.setAttribute("aria-label", "Confirmar remoção — toque de novo pra remover de verdade");
      botao.classList.add("confirmando");
      timeoutId = setTimeout(cancelar, 3000);
    });
    botao.addEventListener("blur", function () { if (pendente) cancelar(); });
  }

  global.UI = { confirmarAntes: confirmarAntes };
})(window);
