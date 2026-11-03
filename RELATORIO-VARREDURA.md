# Relógio de Ponto — varredura de erros e correções

Projeto: `relogio_ponto/` (PHP + MySQL, app do funcionário e painel do admin).
Tudo abaixo foi conferido lendo o código inteiro e **rodando o sistema de verdade**
(PHP + MariaDB local, navegador headless, 31 testes automáticos das contas).

## 1. O que foi encontrado e o que foi feito

### Crítico
| # | Problema | Situação |
|---|----------|----------|
| 1 | **Senha do banco escrita em texto no `_config.php`** | No repositório (público) ela não entra: vem de `_config.local.php`. O pacote para subir já leva a senha dentro do `_config.php`, a seu pedido. |
| 2 | **8 páginas davam erro 500** (`painel-admin`, `funcionarios`, `relatorios`, `ajustes-ponto`, `logs-ponto`, `cargos`, `condominios`, `admins`): chamavam `app_header()`/`app_footer()`, que não existem mais | Viraram redirecionamento (301) para as telas novas do admin |
| 3 | **Fotos dos funcionários (rostos) abriam sem login** por link direto | Pasta fechada no `.htaccess`; admin vê por `foto.php` (confere login **e** condomínio) |
| 4 | `checar.php` tinha a chave escrita no código | Chave agora vem do `_config.local.php`; sem ela a página fica desligada |

### Lógica
| # | Problema | Situação |
|---|----------|----------|
| 5 | **Tolerância não era aplicada**: o cargo tinha "Tolerância (min)" mas só pintava de vermelho no painel; no histórico, relatório e PDF qualquer minuto virava atraso (havia até uma constante `HIST_TOLERANCIA_MINUTOS` esquecida) | **Nova regra por cargo** (seção 2), igual em todas as telas |
| 6 | Dois admins tocando "Autorizar" juntos criavam a batida **duas vezes** | Trava na transação (testado com 3 chamadas simultâneas: aplica 1) |
| 7 | Corrigir horário que cruza a meia-noite (23:50→00:10) gravava no dia errado; saída de madrugada criada pelo admin ficava antes da entrada | Escolhe o dia mais próximo; saída antes da entrada vai para o dia seguinte |
| 8 | Toque duplo / internet lenta gravava a mesma batida 2× | Mesma batida do mesmo funcionário em 60 s é barrada |
| 9 | Admin podia lançar batida em horário futuro | Recusado |
| 10 | Latitude/longitude gravavam qualquer texto | Só número válido entra |
| 11 | Admin de um condomínio podia mudar horário/cargo de **todos** os condomínios | Só admin geral (super admin ou sem condomínio) altera cargos |
| 12 | Tela de ponto aberta o dia todo (quiosque) dava "Sessão expirada" após ~24 min parada | Sessão de 12 h + o app renova o token sozinho e repete a ação |

### Tela
| # | Problema | Situação |
|---|----------|----------|
| 13 | Calendário do admin: datas calculadas em UTC (depois das 21h já era "amanhã"; no último dia do mês, mês errado) | Usa o relógio local |
| 14 | Calendário: depois de salvar, os pontinhos (falta saída / ajustado) **não atualizavam** | Corrigido |
| 15 | Calendário não mostrava a foto da batida | Ícone de câmera por batida |
| 16 | Painel: filtro "Almoço" ignorava janta e descanso | Virou "Intervalos" |
| 17 | Aviso de trocar PIN dizia "PIN de funcionário" | Mostra o nome |
| 18 | App do funcionário não era instalável (só o do admin tinha manifest) | `manifest.webmanifest` |
| 19 | Textos fixos ("8 horas"), classe CSS inexistente (`active:scale-98`) | Corrigidos |
| 20 | Erros do PHP eram totalmente apagados (erro 500 sem pista) | Vão para o log de erros da Hostinger |

### Rodada 2 (a pedido)
| # | Problema | Situação |
|---|----------|----------|
| 21 | **Dia sem batida não era falta** (o relatório só ignorava o dia) | Agora é falta; **domingo e feriado = folga**; se trabalhar, conta como dia normal (seção 2b) |
| 22 | Funcionário desativado **sumia do relatório** (quem saiu no meio do mês perdia as horas) | Continua no relatório e no CSV, marcado "(desativado)", se bateu ponto no período |
| 23 | Turno da noite: regra fixa "entrada ≥ 17:00 / saída até 08:00" | Segue o horário do cargo (ex.: 16:00-02:00 e 22:00-10:00 passam a funcionar); o padrão antigo é o mínimo, então nada muda para quem já funcionava |
| 24 | Barra "Semana" do app: 44 h para todo mundo | Meta pelo cargo (8 h/dia = 44 h como antes; 6 h/dia = 33 h; 12x36 = 38h30) |
| 25 | **PDF e ícones dependiam de internet** (cdnjs e Google Fonts) | Bibliotecas do PDF, fonte Inter e ícones hospedados em `assets/` (testado com a internet bloqueada: PDF e ZIP saem, ícones aparecem) |

### Segurança menor
CSV podia executar fórmula do Excel (nome começando com `=`) · sorteio de PIN usava `Math.random` ·
JSON dentro de `<script>` sem escape de `</script>` · `diagnostico.php` agora confere as colunas novas e a proteção das fotos.

## 2. Tolerância por cargo (o que você pediu)

**Configurações → Cargos → (relógio) → "Atrasado até" e "Antes até".** Valem para o horário principal e para os "outros horários" do cargo.

| Campo | Em branco | Preenchido com N |
|-------|-----------|------------------|
| **Atrasado até (min)** | **Sem tolerância**: todo minuto depois da entrada conta | Chegar até N min depois da entrada **não conta atraso**. Passou de N, conta **tudo** (08:20 com N=15 = 20 min) |
| **Antes até (min)** — **padrão 10** | **Sem limite**: chegar cedo não é restringido | Chegar até N min antes é aceito. **Antes disso o app avisa e NÃO deixa bater a entrada** (07:30 com N=10 p/ entrada 08:00: "entrada liberada a partir das 07:50"). A batida nem é gravada. |

- Continua valendo a regra que você já tinha: atraso = o que faltou para fechar a jornada; trabalhar além **não** gera crédito.
- O atraso tolerado entra como "cumprido" (o saldo do espelho não fica negativo por causa dele).
- Aparece igual em: histórico do funcionário, painel do admin (`+Xmin` / `-Xmin cedo`), relatório, CSV e PDF do espelho.
- Cargo novo já vem com "Atrasado até 15" e "Antes até 10"; para não ter tolerância/limite, apague o número. Os cargos que já existiam ficam com "Antes até 10" quando a coluna é criada.
- O limite "Antes até" só funciona com entrada e saída preenchidas (precisa de um horário para medir).

### Entrada antes da hora (aviso e bloqueio)
Se o cargo tem "Antes até" preenchido e a pessoa tenta **bater a entrada** antes de (horário − limite):
a tela mostra o aviso em vermelho, o botão **Entrada** fica apagado e o toque só repete o aviso; se mesmo assim
chegar ao servidor, ele **recusa e não grava**. Só vale para a **entrada** (volta de intervalo e saída não),
nunca bloqueia quem está atrasado, e cargo com vários horários usa o horário mais próximo da hora.
Batida **lançada à mão** pelo admin ou pedido de correção aprovado **não** é bloqueada (quem decide é o admin);
se ela ficar antes do limite, o tempo excedente não soma como trabalhado.

## 2b. Falta, folga e feriado

Regra aplicada no relatório, CSV, PDF do espelho e no Histórico do funcionário (selo **FALTA**):

- Dia **sem nenhuma batida** = **falta** (perde a jornada do cargo no "esperado" e no saldo do PDF).
- **Domingo** e **feriado** sem batida = **folga** (não é falta). Se a pessoa **trabalhar**, o dia conta como dia normal.
- Hoje ainda não é falta (dá tempo de bater). Antes da **primeira batida** do funcionário não é falta, e **desativado** não tem falta depois da última batida.
- **Feriados nacionais entram sozinhos** (inclui Sexta-feira Santa, e Consciência Negra desde 2024). Em **Configurações → Feriados** cadastre os municipais/estaduais e pontos facultativos (ex.: 25/01 e 09/07 de São Paulo, Carnaval, Corpus Christi), com a opção "repete todo ano".
- **Escala 12x36 / revezamento:** o dia de folga varia, então contar falta daria errado. Em **Cargos** há a caixinha *"Dia sem nenhuma batida conta como falta"* — **desligue nos cargos de escala**. Vem ligada em todos os cargos.
- Não há cadastro de férias/atestado/abono: dia desses aparece como falta até alguém lançar a batida (ou desligar o cargo).

## 3. Como atualizar no servidor (ordem importa)

1. **Faça backup** dos arquivos e do banco.
2. **Use o pacote `relogio_ponto_pronto_para_subir.zip`** entregue na conversa: o `_config.php` dele já vem com os dados do banco, é só subir. *(O repositório no GitHub é público, por isso a senha não está nele: se subir direto do GitHub, crie antes o `_config.local.php` a partir do `_config.local.php.example`, senão o site não conecta.)*
3. Suba os arquivos de `relogio_ponto/` (**não** apague `uploads/`).
4. Abra `admin-config.php` uma vez: as colunas novas (`antecipacao_minutos`) são criadas sozinhas. Se preferir na mão:
   ```sql
   ALTER TABLE ponto_cargos ADD COLUMN antecipacao_minutos INT NULL DEFAULT 10;
   ALTER TABLE ponto_cargo_turnos ADD COLUMN antecipacao_minutos INT NULL DEFAULT 10 AFTER tolerancia_minutos;
   ALTER TABLE ponto_cargos ADD COLUMN controla_falta TINYINT(1) NOT NULL DEFAULT 1;
   ```
   (A tabela `ponto_feriados` também é criada sozinha.)
5. Abra `diagnostico.php` e confira se está tudo verde.
6. (Opcional) A senha do banco já circulou no .zip original; se um dia quiser trocar, é em hPanel → Bancos de dados.
7. Teste: abra no navegador, **sem login**, o endereço direto de uma foto antiga (`/uploads/ponto_fotos/...`). Tem que dar **403/negado**.
8. No celular, limpe/atualize o app (o service worker guarda arquivos fixos).

## 4. O que NÃO foi mexido (por decisão sua)

- **PIN**: identificação só por PIN e a trava de tentativas por IP ficam como estão.
- **PDF "Certificado digitalmente por Vss E Acj"**: texto mantido.
- **Logs**: "Excluir batida" continua apagando o histórico de ajustes dessa batida (fica só a linha no log).

## 4b. Limites conhecidos

- O limite de "Antes até" só funciona com entrada e saída preenchidas no cargo.
- Ícone novo em alguma tela precisa entrar no subconjunto de `assets/fonts/material-symbols-outlined.woff2` (veja `assets/vendor/LEIA-ME.txt`); sem isso aparece o nome do ícone cortado.
- Classes novas do Tailwind não funcionam sem regerar o CSS (o `gerar-css.py` não veio no zip).
- O zip não trazia o esquema do banco; o pacote foi testado com um esquema reconstruído a partir das consultas.
- `app-android/` só tem ícones (não é um projeto Android).

## 5. Testes

`php tests/apuracao_teste.php` — 78 verificações da conta (tolerância, antecipação, turno da noite, virada de meia-noite, Páscoa/feriados, regra de falta, meta da semana, bloqueio de entrada antes da hora). Não usa banco.
