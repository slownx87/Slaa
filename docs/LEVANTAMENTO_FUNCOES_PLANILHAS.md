# Levantamento de Funções das Planilhas de Precificação

> Base: 13 arquivos `.xlsx` do pacote "Preço de Venda Completo" (ExcelGenial —
> Roberto Sardinha Junior). Todos os arquivos compartilham a **mesma
> estrutura de abas e fórmulas**; o que muda entre eles são os dados
> cadastrados (produto, custos, materiais). Este documento mapeia aba por
> aba as regras de negócio e fórmulas, para servir de especificação
> funcional de um aplicativo que substitua a planilha.

Arquivos analisados: `Preço_Venda_Completo-{Coxinha,Hotmart,esfiha,risole}.xlsx`,
`Calculo {Bolinha de queijo, Batata1, Colinha, empanação, Massa basica esfiha,
quibe, Recheio carne esfiha, Recheio frango coxinha}.xlsx`,
`recheio 2 carne.xlsx`.

---

## Visão geral — módulos do aplicativo

| Módulo | Aba(s) de origem | Função |
|---|---|---|
| 1. Custos Fixos | `CUSTOS_FIXOS` | Cadastro de despesas fixas mensais + % sobre faturamento |
| 2. Custos Variáveis | `CUSTOS_VARIÁVEIS` | Cadastro de despesas variáveis (% sobre cada venda) |
| 3. Cadastro de Materiais | `MATERIAIS` | Base de insumos (preço por unidade de referência) |
| 4. Precificação — Custo Conhecido | `PREÇO_CUSTO_CON` | Preço de venda quando já se sabe o custo do produto |
| 5. Precificação — Custo Desconhecido | `PREÇO_CUSTO_DESC*` | Calcula o custo a partir da ficha técnica (receita) e o preço de venda |
| 6. Tabela de Precificação em Lote | `TABELA_PRECIF_COM_CT` / `TABELA_PRECIF_SEM_CT` | Precifica vários produtos de uma vez, com ou sem os % de custo fixo/variável de referência |
| 7. Limites dos Indicadores | `LIMITES_VENDA_SEM_CT` / `LIMITES_VENDA_COM_CT` | Simulador "quanto posso variar cada indicador sem passar do preço-limite" |
| 8. Precificação de Serviços | `SERVIÇOS` | Preço da hora de trabalho a partir de salário desejado |
| 9. Curso Online — CPL | `CURSO_ONLINE_CPL` | Preço de curso a partir do custo por clique/lead |
| 10. Curso Online — Audiência | `CURSO_ONLINE_AUD` | Preço de curso a partir do tamanho de audiência/conversão |
| 11. Tela Inicial | `INICIAL` | Apenas estática (autor, links) — não entra no app |

Todas as abas de cálculo (4–10) dependem dos **totais calculados nos módulos
1 e 2** (`% Custo Fixo` e `% Custo Variável` sobre o faturamento). Esse é o
dado mestre que atravessa o sistema inteiro.

---

## 1. Custos Fixos (`CUSTOS_FIXOS`)

**Entrada do usuário:** lista de nome do custo + valor mensal (Aluguel,
IPTU, Manutenção, Internet, Energia, Água, Auxiliar, MEI, Pro-labore,
Depreciação, Contador, ...) — linhas livres, quantas o usuário quiser.

**Campo manual:** Faturamento Mensal estimado (`J8`).

**Fórmulas:**
```
Faturamento Mensal           = informado manualmente (ex.: (300*1.3)*26 → turnos*ticket*dias)
Total de Custo Fixo          = SOMA(valores mensais dos custos)
% Custo Fixo / Faturamento   = Total de Custo Fixo / Faturamento Mensal
```
**Saída usada por outras telas:** `% Custo Fixo` (equivalente a
`CUSTOS_FIXOS!J12`).

**Função para o app:**
```
calcularCustoFixo(custos: [{nome, valorMensal}], faturamentoMensal) -> {
  totalCustoFixo: number,
  percentualCustoFixo: number  // totalCustoFixo / faturamentoMensal
}
```

---

## 2. Custos Variáveis (`CUSTOS_VARIÁVEIS`)

**Entrada do usuário:** lista de nome do custo + **percentual sobre o
faturamento** (Maquininha, Impostos, Delivery, Limpeza, Embalagens, ...).
Pode ser digitado já em % ou como `valor/faturamento` (ex. maquininha
calculada como `2.29 / faturamento_mensal`).

**Fórmula:**
```
Total de Custo Variável (%) = SOMA(percentuais de cada custo)
```
**Saída usada por outras telas:** `% Custo Variável`
(`CUSTOS_VARIÁVEIS!J8`).

**Função para o app:**
```
calcularCustoVariavel(custos: [{nome, percentual}]) -> {
  totalCustoVariavelPercent: number
}
```

---

## 3. Cadastro de Materiais (`MATERIAIS`)

**Entrada do usuário:** tabela (`Tab_Materiais`, colunas `Nome do Produto`,
`Qtd. Ref`, `Vlr. Ref`) — cada insumo com a quantidade de referência da
compra e o valor pago por essa quantidade (ex.: "Carne moída, 1000 g,
R$25,00" → custo de R$0,025/g).

Alguns itens são compostos (preço vem de **outra planilha** via referência
externa, ex. `=[1]PREÇO_CUSTO_DESC!$K$12` — o custo unitário calculado de um
sub-produto, como "massa" ou "recheio", entra como insumo de outro produto)
ou calculados inline (`=41.5/5`).

**Função para o app:** é essencialmente um **CRUD de insumos**:
```
Material { nome: string, qtdReferencia: number, valorReferencia: number }
custoUnitario(material) = valorReferencia / qtdReferencia
```
Importante para o app: permitir que o **custo unitário de uma receita
("ficha técnica") vire um material** de outra receita — ou seja, produtos
podem compor outros produtos (ex.: massa de esfiha é usada no cálculo da
esfiha pronta). No app isso deve ser modelado como referência entre
"Fichas Técnicas" (não como link de arquivo externo do Excel).

---

## 4. Preço de Venda — Custo Conhecido (`PREÇO_CUSTO_CON`)

Usado quando o usuário já sabe o custo do produto (não quer detalhar a
receita).

**Entradas:** `Nome do Produto`, `Valor Custo` (E8), `Margem Desejada` (%) (E11).

**Fórmulas:**
```
custoFixoPct     = CUSTOS_FIXOS.percentualCustoFixo
custoVariavelPct = CUSTOS_VARIÁVEIS.totalCustoVariavelPercent
margem           = margemDesejada (%)
markup           = 1 / (1 - (custoFixoPct + custoVariavelPct + margem))
precoVenda       = valorCusto * markup

// Rateio de cada custo variável individual sobre o preço (DRE)
para cada custoVariavel_i:
    valor_i = custoVariavel_i.percentual * precoVenda
    pctPreco_i = valor_i / precoVenda

totalCustosVariaveis = soma(valor_i)
totalCustosFixos     = custoFixoPct * precoVenda
custoProduto         = valorCusto
totalCustos          = totalCustosVariaveis + totalCustosFixos + custoProduto
margemLucro          = precoVenda - totalCustos   // = precoVenda * margem
```

**Função para o app:**
```
precificarComCustoConhecido(valorCusto, margemDesejada, custoFixoPct, custoVariavelPct, listaCustosVariaveis)
  -> { markup, precoVenda, dre: { custoFixo, custoVariavel[], custoProduto, margemLucro } }
```
Regra de validação: `custoFixoPct + custoVariavelPct + margemDesejada < 1`
(senão o markup diverge/fica negativo — a planilha não trata esse erro,
mas o app deveria).

---

## 5. Preço de Venda — Custo Desconhecido / Ficha Técnica (`PREÇO_CUSTO_DESC*`)

Esta é a aba mais rica: calcula o custo do produto **a partir da receita**
(lista de ingredientes) e depois precifica. Aparece múltiplas vezes por
arquivo (uma aba por variação de produto/tamanho, ex. "esfiha P", "esfiha
Grande", "média", "carne", "frango", "calabreza").

**Entradas:**
- Lista de itens da receita: `Nome do Item` (busca no cadastro de
  Materiais), `Qtd` usada no preparo.
- `Margem Desejada` (%).
- `Esse lote produz...` = quantidade de unidades que a receita rende.

**Fórmulas — custo do item (busca em Materiais):**
```
qtdRef    = VLOOKUP(nomeItem, Tab_Materiais, "Qtd. Ref")
valorRef  = VLOOKUP(nomeItem, Tab_Materiais, "Vlr. Ref")
custoItem = qtdUsada / qtdRef * valorRef
custoTotalReceita = SOMA(custoItem de todos os ingredientes)
```

**Fórmulas — custo unitário e preço:**
```
custoFixoPct     = CUSTOS_FIXOS.percentualCustoFixo
custoVariavelPct = CUSTOS_VARIÁVEIS.totalCustoVariavelPercent
margem           = margemDesejada
markup           = 1 / (1 - (custoFixoPct + custoVariavelPct + margem))

custoUnitario    = custoTotalReceita / unidadesPorLote
precoVendaUnit   = custoUnitario * markup

// DRE individual (por unidade) e DRE por lote são calculados em paralelo:
precoVendaLote   = precoVendaUnit * ??? (na planilha, "Lote" replica o
                   mesmo cálculo de "Individual" — útil se o usuário quiser
                   ver o preço do lote inteiro em vez da unidade)

para cada custoVariavel_i:
    valor_i    = custoVariavel_i.percentual * precoVenda   (individual ou lote)
    pctPreco_i = valor_i / precoVenda

totalCustosVariaveis = soma(valor_i)
totalCustosFixos     = custoFixoPct * precoVenda
custoProduto         = custoUnitario
totalCustos          = totalCustosVariaveis + totalCustosFixos + custoProduto
margemLucro          = precoVenda - totalCustos
```

**Função para o app:**
```
calcularFichaTecnica(ingredientes: [{materialId, qtdUsada}], unidadesPorLote)
  -> custoTotalReceita, custoUnitario

precificarComCustoDesconhecido(custoUnitario, margemDesejada, custoFixoPct,
  custoVariavelPct, listaCustosVariaveis)
  -> { markup, precoVendaUnitario, precoVendaLote, dre }
```
Este módulo reaproveita a mesma função de precificação do módulo 4
(`markup = 1/(1-soma)`), só muda a origem do "Valor Custo" (vem da ficha
técnica, não é digitado direto).

---

## 6. Tabela de Precificação em Lote (`TABELA_PRECIF_COM_CT` / `TABELA_PRECIF_SEM_CT`)

Permite cadastrar **vários produtos de uma vez**, cada um com seu próprio
custo e margem, reaproveitando os % de custo fixo/variável.

- **COM_CT** ("com custo referência"): `% Ct. Fix.` e `% Ct. Var.` vêm
  automaticamente de `CUSTOS_FIXOS` / `CUSTOS_VARIÁVEIS` (fixos para toda a
  tabela).
- **SEM_CT** ("sem custo referência" / custos abertos): `% Ct. Fix.` e
  `% Ct. Var.` são digitados linha a linha (o usuário define manualmente,
  por produto).

**Colunas por linha:** `Nome do Produto`, `Descrição`, `Valor Custo`,
`Margem %`, `% Ct. Fixo`, `% Ct. Variável` (manual no SEM_CT), e saída:
`Markup`, `Valor Venda`, `Ct. Fix. (R$)`, `Ct. Var. (R$)`, `Margem (R$)`.

**Fórmula (idêntica nas duas, só muda a origem de H/I):**
```
custoFixoPct     = <ref automática>  OU  <valor digitado na linha>
custoVariavelPct = <ref automática>  OU  <valor digitado na linha>
markup           = 1 / (1 - (custoFixoPct + custoVariavelPct + margemPct))
valorVenda        = valorCusto * markup
ctFixoReais       = custoFixoPct * valorVenda
ctVarReais        = custoVariavelPct * valorVenda
margemReais       = margemPct * valorVenda
```

**Função para o app:**
```
precificarEmLote(produtos: [{nome, descricao, valorCusto, margemPct,
   custoFixoPct?, custoVariavelPct?}], modo: "COM_REFERENCIA"|"CUSTOS_ABERTOS")
   -> [{...produto, markup, valorVenda, ctFixoReais, ctVarReais, margemReais}]
```
No app isso é uma **grid/tabela editável** (até 50 linhas na planilha
original, mas no app pode ser ilimitado).

---

## 7. Limite dos Indicadores (`LIMITES_VENDA_SEM_CT` / `LIMITES_VENDA_COM_CT`)

Simulador de sensibilidade: dado um **preço de venda limite** (teto que o
mercado aceita) e os indicadores atuais (preço de custo, % custo fixo, %
custo variável, margem), mostra **até quanto cada indicador pode subir
isoladamente** sem que o preço calculado passe do limite — mantendo os
outros três fixos.

**Entradas:** `Preço de Venda Limite`, `Preço de Custo`, `% Custo Fixo`,
`% Custo Variável`, `Margem` (os 4 últimos vêm de referência, no COM_CT
puxados de `CUSTOS_FIXOS`/`CUSTOS_VARIÁVEIS`; no SEM_CT digitados).

**Fórmulas (coluna "Seus Indicadores" — situação atual):**
```
markup       = 1 / (1 - (custoFixoPct + custoVariavelPct + margemPct))
precoCalc    = precoCusto * markup
```

**Fórmulas (para cada indicador isolado, nas colunas I/J/K/L — "quanto
esse indicador pode ir até o limite, mantendo os outros 3"):**
```
// Coluna "Preço de Custo" livre, outros fixos:
precoCustoMax = precoVendaLimite / precoVendaAtual  * precoCusto   // (I11 = E8/E15 depois escalado)

// Coluna "% Custo Fixo" livre:
custoFixoMax = 1 - precoCusto/precoVendaLimite - (custoVariavelPct + margemPct)

// Coluna "% Custo Variável" livre:
custoVariavelMax = 1 - precoCusto/precoVendaLimite - (custoFixoPct + margemPct)

// Coluna "Margem" livre:
margemMax = 1 - precoCusto/precoVendaLimite - (custoVariavelPct + custoFixoPct)

// cada coluna recalcula o markup e o preço de venda resultante (deve bater com o limite)
markup_i    = 1 / (1 - soma dos 3 indicadores daquela coluna)
precoCalc_i = precoCusto_i * markup_i
```
Se o resultado for negativo, a planilha retorna `""` (indicador já
inviável — custo sozinho já ultrapassa o limite).

**Função para o app:**
```
simularLimites(precoVendaLimite, precoCusto, custoFixoPct, custoVariavelPct, margemPct)
 -> {
   atual: { markup, precoVendaCalculado },
   maxPrecoCusto, maxCustoFixoPct, maxCustoVariavelPct, maxMargemPct
   // cada "max" = null/inválido se o valor restante for negativo
 }
```

---

## 8. Precificação de Serviços (`SERVIÇOS`)

Calcula o **valor da hora de trabalho** a partir de um salário/renda
desejada.

**Entradas:** `Salário Mensal Desejado`, `Horas de Trabalho no Mês`,
`Total Custos Fixos (R$)`, `Total % Custos Variáveis`, `Margem Final
pós-Salário`.

**Fórmulas:**
```
valorHora = (salarioMensalDesejado + totalCustosFixos)
            / (1 - custoVariavelPct - margemFinalPct)
            / horasTrabalhoMes

// DRE por hora
precoVendaHora   = valorHora
custoVariavelH   = custoVariavelPct * precoVendaHora
custoFixoH       = totalCustosFixos / horasTrabalhoMes
totalCustosH     = custoVariavelH + custoFixoH
margemLucroH     = precoVendaHora - totalCustosH

// DRE total do mês = tudo * horasTrabalhoMes
precoVendaMes    = precoVendaHora * horasTrabalhoMes
...
```

**Função para o app:**
```
precificarServico(salarioDesejado, horasMes, custosFixosReais,
  custoVariavelPct, margemPct)
  -> { valorHora, dreHora, dreMes }
```

---

## 9. Curso Online — Custo por Lead/Clique (`CURSO_ONLINE_CPL`)

Precifica um infoproduto a partir do **custo de tráfego pago**.

**Entradas:** `Custo por Clique`, `Conversão de Campanha` (% clique→lead),
`Taxa % na Venda` (plataforma, ex. gateway/Hotmart), `Taxa Fixa na Venda`
(R$), `Margem Desejada`.

**Fórmulas:**
```
custo100Cliques   = custoPorClique * 100
vendasEm100Cliques = 100 * conversaoCampanha
custoPorVenda      = custo100Cliques / vendasEm100Cliques
custoFinal         = custoPorVenda + taxaFixaNaVenda

precoVenda = custoFinal / (1 - taxaPctNaVenda - margemDesejada)

// DRE
totalCustosVariaveis = taxaPctNaVenda * precoVenda
totalTaxaFixaVenda   = taxaFixaNaVenda
investimentoTrafego  = custoPorVenda
totalCustos          = totalCustosVariaveis + totalTaxaFixaVenda + investimentoTrafego
margemLucro          = precoVenda - totalCustos
```

**Função para o app:**
```
precificarCursoCPL(custoPorClique, conversaoCampanha, taxaPctVenda,
  taxaFixaVenda, margemDesejada) -> { precoVenda, dre }
```

---

## 10. Curso Online — Audiência (`CURSO_ONLINE_AUD`)

Mesma ideia do módulo 9, mas partindo do **tamanho da audiência e meta de
faturamento**, não do custo de clique.

**Entradas:** `Resultado Esperado` (faturamento meta), `Tamanho da
Audiência`, `Conversão da Campanha`, `Taxa % na Venda`, `Taxa Fixa na
Venda`, `Custos Fixos (R$ total do projeto)`.

**Fórmulas:**
```
qtdVendas   = tamanhoAudiencia * conversaoCampanha
custoFixoPorVenda = custosFixos / qtdVendas

precoVenda = (custoFixoPorVenda + taxaFixaVenda) / (1 - taxaPctVenda - lucroDesejadoImplicito)
// Na planilha, "lucro/venda" é derivado de resultadoEsperado/qtdVendas:
lucroPorVenda = resultadoEsperado / qtdVendas
custoTotalPorVenda = custoFixoPorVenda + taxaFixaVenda
precoVenda = custoTotalPorVenda / (1 - taxaPctVenda)   // ajustado para cobrir o lucro-meta

// DRE por venda
totalCustosVariaveis = taxaPctVenda * precoVenda
totalTaxaFixaVenda   = taxaFixaVenda
totalCustoFixo        = custoFixoPorVenda
totalCustos           = soma dos 3
margemLucro            = precoVenda - totalCustos      // deve aproximar lucroPorVenda

// DRE do projeto = tudo * qtdVendas
precoVendaProjeto = precoVenda * qtdVendas
...
margemLucroProjeto = margemLucro * qtdVendas            // deve aproximar resultadoEsperado
```

**Função para o app:**
```
precificarCursoAudiencia(resultadoEsperado, tamanhoAudiencia,
  conversaoCampanha, taxaPctVenda, taxaFixaVenda, custosFixos)
  -> { qtdVendas, precoVendaPorVenda, drePorVenda, precoVendaProjeto, dreProjeto }
```

---

## Regras transversais (usar em todo o app)

1. **Fórmula-base de precificação (markup divisor), repetida em quase
   todas as abas:**
   ```
   markup = 1 / (1 - Σ percentuais)   // percentuais = custo fixo % + custo
                                       // variável % + margem % (+ taxas
                                       // específicas do módulo)
   precoVenda = custo * markup
   ```
   Essa é a função central: **toda a lógica do app gira em torno dela.**
   Deve ser implementada uma única vez e reutilizada pelos módulos 4, 5, 6,
   7, 9 e 10.

2. **Proteção de divisão por zero / percentuais inválidos:** a planilha
   usa `IFERROR(...,"")` e `IF(1-...<0,"",...)` para esconder erros. No
   app isso deve virar validação explícita (ex.: "a soma dos percentuais
   não pode ser ≥ 100%", "produto não encontrado no cadastro de
   materiais").

3. **Busca de material por nome (`VLOOKUP` em `Tab_Materiais`)** → no app
   deve ser uma referência por **ID**, não por texto livre, para evitar
   erros de digitação/duplicidade.

4. **Encadeamento de fichas técnicas** (custo de uma receita usado como
   insumo de outra, via referência entre arquivos) → modelar como grafo de
   "Produto usa Produto" dentro do mesmo banco de dados do app, não como
   arquivo externo.

5. **DRE em % e em R$ sempre em paralelo** (colunas tipo `Valor` / `%/Preço`)
   — no app, calcular o valor absoluto e derivar o percentual
   (`valor / precoVenda`), nunca o contrário.

---

## Sugestão de modelagem de dados (entidades)

```
NegocioConfig
 ├─ custosFixos: CustoFixo[]           { nome, valorMensal }
 ├─ faturamentoMensalEstimado: number
 └─ custosVariaveis: CustoVariavel[]   { nome, percentual }

Material { id, nome, qtdReferencia, valorReferencia, unidade }

FichaTecnica (Produto)
 ├─ nome
 ├─ ingredientes: { materialId | fichaTecnicaId, qtdUsada }[]
 ├─ unidadesPorLote
 └─ margemDesejada

PrecificacaoSimples { produtoNome, valorCusto, margemDesejada }

TabelaPrecificacao { modo: "referencia"|"aberto", linhas: [...] }

SimulacaoLimite { precoVendaLimite, precoCusto, custoFixoPct, custoVariavelPct, margemPct }

Servico { salarioDesejado, horasMes, custosFixos, custoVariavelPct, margemFinalPct }

CursoCPL { custoPorClique, conversaoCampanha, taxaPctVenda, taxaFixaVenda, margemDesejada }

CursoAudiencia { resultadoEsperado, tamanhoAudiencia, conversaoCampanha, taxaPctVenda, taxaFixaVenda, custosFixos }
```

## Próximos passos sugeridos para o app

1. Implementar o **motor de cálculo** (módulo comum do item "Regras
   transversais #1") com testes cobrindo os casos das planilhas (os
   números acima, extraídos do arquivo "Coxinha", servem como casos de
   teste de regressão).
2. Telas: Cadastro de Custos Fixos/Variáveis → Cadastro de Materiais →
   Fichas Técnicas (receitas) → Precificação (simples / em lote) →
   Simulador de Limites → Serviços → Cursos Online.
3. Persistir tudo em banco relacional simples (negócio → custos →
   materiais → fichas técnicas → produtos precificados), permitindo múltiplos
   produtos por usuário (hoje cada planilha = 1 produto, o app deve suportar
   N produtos num só lugar).
