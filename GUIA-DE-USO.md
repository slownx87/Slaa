# Relógio de Ponto — guia simples

Este sistema registra a entrada e a saída dos funcionários pelo celular, com PIN e foto, e depois
calcula horas trabalhadas, atrasos e faltas para o administrador conferir e imprimir.

Existem **duas partes**:

- **App do funcionário** — onde se bate o ponto (normalmente um celular fixo em cada prédio).
- **Painel do administrador** — onde se confere, corrige, cadastra e tira os relatórios.

---

## 1. Para o funcionário

### Bater o ponto
1. Abra o app e toque no botão grande **Bater Ponto**.
2. Digite o seu **PIN de 4 números**.
3. Escolha o que quer registrar: **Entrada**, **Saída para almoço**, **Volta do almoço**, **Saída para janta**, **Volta da janta**, **Saída para descanso**, **Volta do descanso** ou **Saída final**.
   - O botão do almoço só aparece a partir das 12h e o da janta a partir das 18h.
   - Só aparecem as opções que fazem sentido naquele momento (não dá para "voltar do almoço" sem ter saído).
4. Tire a **foto** do rosto (é obrigatória) e toque em **Confirmar**.
5. Aparece o **comprovante** com tipo, hora e data. Pronto.

### "Ainda não dá para bater a entrada"
Se o seu cargo tem limite para chegar cedo (o padrão é **10 minutos antes** do horário), quem tenta bater a entrada antes disso vê um aviso vermelho dizendo **a partir de que horas** pode bater. O botão fica apagado e nada é registrado. É só voltar na hora indicada.

### Esqueci de bater a saída de ontem
Na tela de escolha aparece o aviso **"Dia sem saída"**. Toque em **Fechar**, informe a hora em que você saiu e envie. O administrador recebe o pedido e confere.

### Ver o histórico do mês
Menu **Histórico** → digite o PIN. Cada dia aparece com um selo:

| Selo | Significa |
|------|-----------|
| **OK** | Jornada cumprida |
| **ATRASO** | Faltou tempo para fechar a jornada (mostra quanto) |
| **PEND** | Falta bater algo (por exemplo, a saída) |
| **FALTA** | Nenhuma batida no dia. **Domingo e feriado não contam** como falta |

Chegar atrasado **dentro da tolerância do cargo** (padrão 15 minutos) não conta como atraso. Trabalhar além da jornada não vira hora extra.

### Uma batida está errada ou esqueci de bater
No **Histórico**, toque no dia:
- Toque na batida errada, informe o horário certo e escreva o **motivo** (pelo menos 10 letras); ou
- Toque em **Faltou uma batida**, escolha qual foi, a hora e o motivo.

O pedido vai para o administrador. Acompanhe em **Pedidos** (Aguardando, Aprovado ou Recusado, com a resposta dele). A batida só muda depois da aprovação.

### Outras telas
- **Perfil**: mostra as suas batidas de hoje.
- **Como usar o aplicativo**: tutorial passo a passo dentro do próprio app.
- **Advertências** aparecem no Histórico, no cartão "Advert.".

---

## 2. Para o administrador

Entre pelo endereço do painel com **usuário e senha**. Na parte de baixo ficam 4 abas: **Painel**, **Equipe**, **Ajustes** e **Relatórios**. A engrenagem no topo abre as **Configurações**.

### Painel (tela inicial)
- Números do dia: funcionários, batidas de hoje, quem está em expediente e pedidos esperando.
- **Pedidos de ajuste**: toque em **Autorizar** (o sistema corrige a batida sozinho e refaz as horas do dia) ou **Recusar** (escreva o motivo; o funcionário vê).
- **Batidas de hoje**, em tempo real, com filtro (Entrada, Intervalos, Saída). Atraso aparece em vermelho (`+12min`), chegada fora do limite aparece como `-15min cedo`. O ícone de câmera abre a **foto** da batida.

### Equipe
- Buscar funcionário pelo nome, cargo ou prédio.
- Botão **+**: cadastrar (nome, prédio, cargo, telefone e PIN de 4 números — dá para **Sortear**).
- **Trocar PIN**: o novo PIN aparece **uma única vez** na tela; anote e entregue. Ninguém consegue "ver" um PIN antigo.
- **Desativar** tira a pessoa do sistema sem perder o histórico. **Excluir** só funciona para quem nunca bateu ponto.

### Ajustes (corrigir batidas)
1. Escolha o funcionário, o mês e toque no dia.
2. Toque numa batida para **mudar o horário**, ou em **Incluir** para criar uma que faltou, ou **Excluir** a errada. Sempre escreva o **motivo**; tudo fica registrado.
3. **Preencher vários dias**: marque os dias (ou **Seg a Sex**), toque em Continuar, confira os horários que vêm do cargo, escreva o motivo e **Lançar**. Batida que a pessoa já fez não é alterada.

### Relatórios
- Escolha o período (este mês, mês anterior ou datas), o prédio e o funcionário.
- Mostra por pessoa: **horas trabalhadas**, **atrasos**, **faltas** (com as datas) e quanto era o esperado.
- **Exportar espelho em PDF**: um arquivo com todos (uma página por pessoa) ou **um PDF por funcionário (ZIP)**.
- **Exportar resumo em CSV**: para abrir no Excel.
- Funcionário que saiu no meio do mês continua aparecendo, marcado "(desativado)".

### Configurações

**Cargos** (cada cargo tem as suas regras — só o administrador geral altera):
- **Entrada e saída**, e o **intervalo** (ex.: 60 min de almoço). Daí sai a jornada do dia.
- **Atrasado até (min)** — padrão **15**: até quantos minutos depois da entrada a pessoa pode chegar sem contar atraso. Passou disso, conta o atraso inteiro. *Em branco = sem tolerância.*
- **Antes até (min)** — padrão **10**: até quantos minutos antes ela pode bater a entrada. Antes disso o app avisa e **não deixa**. *Em branco = sem limite.*
- **Dia sem batida conta como falta**: ligado por padrão. **Desligue nos cargos de escala 12x36 / revezamento**, onde a folga muda de dia.
- **Outros horários aceitos**: para quem faz escala com mais de um horário.

**Feriados**:
- Os feriados nacionais já entram sozinhos. Cadastre aqui os **municipais/estaduais** e os dias em que a empresa não trabalha (Carnaval, Corpus Christi...). Pode marcar "repete todo ano".
- Regra: **dia sem nenhuma batida é falta, menos domingo e feriado**. Se a pessoa trabalhar no domingo ou feriado, conta como dia normal.

**Advertências**: registre (verbal, escrita ou suspensão); o funcionário vê no histórico dele.
**Condomínios** e **Administradores**: cadastro de prédios e de quem acessa o painel (administrador geral). Um administrador de prédio só vê o **seu** prédio.
**Diagnóstico**: confere se o sistema está tudo certo (use se algo parecer estranho).

---

## 3. Primeiros passos (uma vez só)
1. Entre no painel e abra **Configurações**.
2. Em **Cargos**, confira entrada, saída, intervalo e tolerâncias de cada cargo. **Desligue "conta falta" nos cargos de escala 12x36.**
3. Em **Feriados**, cadastre os feriados do município e do estado.
4. Em **Equipe**, confira se todo mundo tem cargo e PIN.
5. Nos celulares dos prédios, abra o app uma vez e (se quiser) instale na tela inicial.

## 4. Se algo der errado
- **"Sessão expirada"**: o app tenta se resolver sozinho; se repetir, atualize a página.
- **Funcionário não consegue bater a entrada cedo**: é o limite do cargo ("Antes até"). Confira o horário do cargo ou aumente o limite.
- **Dia aparece como falta mas a pessoa trabalhou**: lance a batida em **Ajustes**, ou peça para ela fazer o pedido pelo Histórico.
- **Tela com erro**: abra **Configurações → Diagnóstico** e avise quem cuida do sistema.
