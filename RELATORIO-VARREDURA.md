# Relógio de Ponto — varredura de erros e correções

Projeto: `relogio_ponto/` (PHP + MySQL, app do funcionário e painel do admin).
Tudo abaixo foi conferido lendo o código inteiro e **rodando o sistema de verdade**
(PHP + MariaDB local, navegador headless, 31 testes automáticos das contas).

## 1. O que foi encontrado e o que foi feito

### Crítico
| # | Problema | Situação |
|---|----------|----------|
| 1 | **Senha do banco escrita em texto no `_config.php`** (e dentro do .zip) | Corrigido no código: agora vem de `_config.local.php` (fora do git). **Troque a senha do banco no painel da Hostinger** — ela já circulou. |
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

### Segurança menor
CSV podia executar fórmula do Excel (nome começando com `=`) · sorteio de PIN usava `Math.random` ·
JSON dentro de `<script>` sem escape de `</script>` · `diagnostico.php` agora confere as colunas novas e a proteção das fotos.

## 2. Tolerância por cargo (o que você pediu)

**Configurações → Cargos → (relógio) → "Atrasado até" e "Antes até".** Valem para o horário principal e para os "outros horários" do cargo.

| Campo | Em branco | Preenchido com N |
|-------|-----------|------------------|
| **Atrasado até (min)** | **Sem tolerância**: todo minuto depois da entrada conta | Chegar até N min depois da entrada **não conta atraso**. Passou de N, conta **tudo** (08:20 com N=15 = 20 min) |
| **Antes até (min)** | **Sem limite**: chegar cedo não é restringido | Chegar até N min antes é aceito. O que passar disso **não soma como trabalhado** (07:30 com N=10 p/ entrada 08:00: 20 min não contam) |

- Continua valendo a regra que você já tinha: atraso = o que faltou para fechar a jornada; trabalhar além **não** gera crédito.
- O atraso tolerado entra como "cumprido" (o saldo do espelho não fica negativo por causa dele).
- Aparece igual em: histórico do funcionário, painel do admin (`+Xmin` / `-Xmin cedo`), relatório, CSV e PDF do espelho.
- Cargo novo já vem com "Atrasado até 15" (o comportamento que existia); para não ter tolerância, apague o número.
- O limite "Antes até" só funciona com entrada e saída preenchidas (precisa de um horário para medir).

## 3. Como atualizar no servidor (ordem importa)

1. **Faça backup** dos arquivos e do banco.
2. Na pasta do site, **crie o `_config.local.php`** (copie `_config.local.php.example`) com os dados do banco. *Sem isso o site para de conectar.*
3. Suba os arquivos de `relogio_ponto/` (**não** apague `uploads/`).
4. Abra `admin-config.php` uma vez: as colunas novas (`antecipacao_minutos`) são criadas sozinhas. Se preferir na mão:
   ```sql
   ALTER TABLE ponto_cargos ADD COLUMN antecipacao_minutos INT NULL;
   ALTER TABLE ponto_cargo_turnos ADD COLUMN antecipacao_minutos INT NULL AFTER tolerancia_minutos;
   ```
5. Abra `diagnostico.php` e confira se está tudo verde.
6. **Troque a senha do banco** (hPanel → Bancos de dados) e atualize no `_config.local.php`.
7. Teste: abra no navegador, **sem login**, o endereço direto de uma foto antiga (`/uploads/ponto_fotos/...`). Tem que dar **403/negado**.
8. No celular, limpe/atualize o app (o service worker guarda arquivos fixos).

## 4. O que NÃO foi mexido (decisão sua ou risco maior)

- **PIN de 4 dígitos é o único identificador.** A trava de tentativas é por IP e zera quando alguém acerta (quem tem o próprio PIN consegue testar PINs alheios em lotes). Recomendação: PIN + escolher o nome na tela, ou PIN maior.
- Turno da noite: as regras "entrada ≥ 17:00" e "saída entre 00:00 e 08:00" estão fixas no código (`ponto_normalizar_turno_noite_registros`). Escala que vira a noite começando antes das 17h não é reconhecida.
- Barra "Semana" do app usa 44 h fixas, não o cargo.
- O PDF imprime **"Certificado digitalmente por Vss E Acj"**, mas não há assinatura digital de verdade — vale trocar o texto (risco jurídico).
- O PDF baixa jsPDF/JSZip do cdnjs sem hash de integridade e os ícones vêm do Google Fonts: sem internet não exporta PDF/ícones viram texto. Ideal: hospedar localmente.
- O relatório só lista funcionário **ativo** (quem saiu no meio do mês some do total) e não conta **faltas** (dia sem batida) — falta cadastrar escala/dias de trabalho por cargo.
- "Excluir batida" apaga o histórico de ajustes dessa batida (fica só o texto no log).
- O zip não trazia o **esquema do banco** (CREATE TABLE) nem o `ferramentas/gerar-css.py` citado no `README-css.md`. Classes novas do Tailwind não funcionam sem regerar o CSS; por isso as telas novas só usam classes que já existiam (+2 utilitários em `admin_css()`).
- Pasta `app-android/` só tem ícones (não é um projeto Android).

## 5. Testes

`php tests/apuracao_teste.php` — 31 verificações da conta (tolerância, antecipação, turno da noite, virada de meia-noite). Não usa banco.
