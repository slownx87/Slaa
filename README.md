# Portal Wi-Fi VN System — MikroTik Hotspot

Página de login para o Hotspot do MikroTik em que a pessoa precisa informar
**nome completo, bloco e apartamento** (e e-mail, se você quiser) antes de a
internet ser liberada. Visual no tema escuro da VN System.

Feita para rodar dentro do roteador: **não usa nenhum arquivo externo** (sem CDN,
sem fonte do Google, sem framework). Antes do login o aparelho não tem internet,
então qualquer recurso externo simplesmente não carregaria.

---

## 1. O que tem neste repositório

| Caminho | O que é |
|---|---|
| `hotspot/login.html` | A página de cadastro/login. **É aqui que você configura tudo.** |
| `hotspot/md5.js` | MD5 usado pelo login em modo HTTP-CHAP (a senha não trafega em texto puro). |
| `hotspot/vn.css` | Design system VN System + os estilos do portal. |
| `hotspot/logo.png` | **Você precisa subir este arquivo.** É a sua logo. Sem ela a página mostra um wordmark "VN SYSTEM" desenhado em CSS. |
| `hotspot/alogin.html` | Tela de "conectado com sucesso". |
| `hotspot/status.html` | Status da conexão (consumo, tempo, botão desconectar). |
| `hotspot/logout.html` | Tela de "desconectado". |
| `hotspot/error.html` | Tela de erro. |
| `hotspot/rlogin.html`, `hotspot/redirect.html` | Páginas internas de redirecionamento do hotspot. |
| `mikrotik/hotspot-existente.rsc` | **Use este se você já tem hotspot.** 3 comandos para apontar o portal. |
| `mikrotik/hotspot-novo.rsc` | Use este se vai montar o hotspot do zero (IP, pool, DHCP, perfil). |
| `mikrotik/aps-autorizados.rsc` | Autorizar os APs/roteadores por MAC — só os cadastrados entregam o Wi-Fi. |
| `mikrotik/salvar-em-txt.rsc` | Guardar os acessos num `.txt` dentro do próprio roteador. |
| `mikrotik/equipamentos-sem-login.rsc` | Câmeras, DVR, interfone, APs e afins passando direto, sem ver o portal. |
| `coletor/servidor.js` | Recebe os cadastros **no seu computador** e grava em CSV/JSONL. Sem nuvem. |
| `coletor/iniciar-windows.bat` | Duplo clique para ligar o coletor no Windows. |
| `webhook/google-apps-script.js` | Alternativa: grava cada cadastro numa planilha do Google. |

---

## 2. Como funciona o fluxo

1. A pessoa conecta no Wi-Fi e o MikroTik intercepta o primeiro acesso.
2. Aparece a `login.html` pedindo nome, e-mail, bloco e apartamento.
3. O JavaScript valida os dados **no aparelho** (se o e-mail estiver ligado,
   o formato é conferido).
4. Os dados são gravados no navegador (`localStorage`) e, se você configurar um
   webhook, enviados para a sua planilha/servidor junto com MAC, IP e data/hora.
5. Só então a página envia o login de verdade para o hotspot, usando uma conta
   compartilhada, e a internet é liberada.

> **Importante entender:** o cadastro identifica *quem* está usando; a
> autenticação do hotspot em si continua sendo feita por uma conta do RouterOS.
> Sem RADIUS/User Manager o roteador não tem como criar uma conta por e-mail
> sozinho — por isso o modo padrão é a conta compartilhada.

---

## 3. Personalizar a página

Abra `hotspot/login.html` e edite **apenas** o bloco `var CONFIG = { ... }`:

```js
var CONFIG = {
  titulo:   "Acesso &agrave; rede Wi-Fi",   // aceita HTML e entidades
  subtitulo:"Identifique-se para liberar a conex&atilde;o.",
  rodape:   "<b>Rede gerenciada por VN System</b><br>...",

  blocos: ["A", "B", "C", "D", "E", "F"],   // os blocos do seu condomínio

  pedirEmail: true,                          // false esconde o campo de e-mail
  pedirCelular: true,                        // false esconde o campo de celular

  modo: "compartilhado",                     // "compartilhado" ou "cpf"
  usuario: "visitante",                      // precisa existir em /ip hotspot user
  senha:   "visitante",

  senhaCpf: "",                              // só no modo "cpf"

  webhook: "",                               // "" = não envia para lugar nenhum
  webhookTimeout: 3500
};
```

Para pedir **só nome, bloco e apartamento**, deixe `pedirEmail: false` e
`pedirCelular: false` — os campos somem e param de ser validados.

**`modo: "compartilhado"`** (padrão) — todo mundo entra com a mesma conta do
hotspot. Simples, funciona sem servidor nenhum. É o recomendado.

**`modo: "cpf"`** — envia o e-mail (só os dígitos) como nome de usuário. Só use se
você tiver **RADIUS / User Manager** aceitando qualquer usuário, ou se cadastrar
os moradores por e-mail manualmente. Sem isso, o login vai falhar com
"invalid username or password".

Para testar o visual, é só abrir `hotspot/login.html` no navegador do computador
— a página detecta que está fora do roteador e funciona normalmente (só o login
final não acontece).

---

## 4. Instalar no MikroTik

### 4.1 Coloque sua logo

Salve a logo da VN System como **`logo.png`** dentro da pasta `hotspot/` antes
de subir. Fundo transparente, altura de uns 160px, largura livre — a página
redimensiona para 74px de altura.

Se o arquivo não existir, a página não quebra: ela cai automaticamente para um
wordmark "VN SYSTEM" desenhado em CSS, com o mesmo degradê azul da marca.

### 4.2 Suba os arquivos

Coloque **todo o conteúdo da pasta `hotspot/`** dentro de uma pasta no roteador.
Use um nome próprio, por exemplo `hotspot-vn` — assim uma atualização do
RouterOS nunca sobrescreve a sua página.

**Pelo WinBox (mais fácil):**
1. `Files` → crie/abra a pasta desejada.
2. Arraste os arquivos do `hotspot/` para dentro da janela.
   (Se a pasta não existir, arraste primeiro para a raiz e depois use FTP para
   mover, ou crie a pasta arrastando um arquivo com o caminho já montado.)

**Por FTP** (usuário e senha são os mesmos do RouterOS):
```bash
ftp 192.168.88.1
# depois: mkdir hotspot-vn ; cd hotspot-vn ; binary ; mput *
```

**Por SCP/SFTP** (se o serviço SSH estiver ligado):
```bash
scp -r hotspot/* admin@192.168.88.1:hotspot-vn/
```

**Baixando direto de uma URL** (dentro do terminal do RouterOS):
```
/tool fetch url="https://seu-servidor/login.html" dst-path=hotspot-vn/login.html
```

### 4.3 Configure o hotspot

**Se você JÁ TEM hotspot funcionando** — é só apontar para a pasta nova e criar
a conta. Abra o terminal do WinBox e cole (`mikrotik/hotspot-existente.rsc`):

```
/ip hotspot print
```
Veja o nome na coluna `PROFILE` (normalmente `hsprof1`) e use ele abaixo:
```
/ip hotspot profile set [find name="hsprof1"] html-directory=hotspot-vn login-by=cookie,http-chap http-cookie-lifetime=3d
/ip hotspot user profile add name=moradores shared-users=500 rate-limit=10M/50M idle-timeout=30m
/ip hotspot user add name=visitante password=visitante profile=moradores
```

**Se você AINDA NÃO TEM hotspot** — o caminho rápido é o assistente, que
pergunta interface, faixa de IP e DNS:
```
/ip hotspot setup
```
Depois rode os três comandos acima. Para montar tudo na mão (IP, pool, DHCP,
perfil, logs), use `mikrotik/hotspot-novo.rsc` como referência — leia antes de
aplicar, ajustando interface e faixa de IP.

> Os `.rsc` **não são para importar às cegas** com `/import`: eles têm nomes de
> interface e faixas de IP de exemplo. Abra, ajuste e cole bloco por bloco.

Confira que `usuario`/`senha` do `CONFIG` são **exatamente** os mesmos do
`/ip hotspot user`, senão o login sempre falha.

### 4.4 Se for usar o webhook

O domínio precisa estar liberado **antes** do login:
```
/ip hotspot walled-garden add dst-host=script.google.com
/ip hotspot walled-garden add dst-host=script.googleusercontent.com
```
Instruções de como publicar a planilha estão em `webhook/google-apps-script.js`.

Se você deixar `webhook: ""`, nada é enviado para fora — os cadastros ficam só
no aparelho da pessoa e o registro de acesso fica no log do roteador.

### 4.4b Salvar os cadastros no seu computador

Se você não quer os dados dos moradores numa planilha do Google, o coletor em
`coletor/` roda na sua máquina e recebe os cadastros direto do portal. Não usa
nuvem, não usa biblioteca nenhuma — só o Node.js.

**1. Instale o Node.js** ([nodejs.org](https://nodejs.org), versão LTS).

**2. Ligue o coletor.** No Windows, duplo clique em `iniciar-windows.bat`. No
Linux/Mac, `./iniciar-linux-mac.sh`. Ou, em qualquer sistema:
```
node servidor.js
```
Ele abre o painel em `http://localhost:3000` e grava dois arquivos ao lado do
`servidor.js`:

| Arquivo | Para quê |
|---|---|
| `cadastros.csv` | Abre no Excel com dois cliques (já vem com BOM e separador `;`). |
| `cadastros.jsonl` | Um cadastro por linha, para reprocessar depois. |

**3. Descubra o IP do seu computador na rede** (`ipconfig` no Windows,
`ip a` no Linux). Precisa ser um IP fixo — se mudar, o portal para de gravar.
Reserve no MikroTik:
```
/ip dhcp-server lease add mac-address=<MAC-do-PC> address=10.10.0.50 server=dhcp-hotspot comment="PC do coletor"
```

**4. Libere o acesso ao coletor antes do login.** Sem isso o navegador do
morador não alcança o seu PC, porque ele ainda não passou pelo hotspot:
```
/ip hotspot walled-garden ip add dst-address=10.10.0.50 action=accept comment="coletor VN"
```

**5. Aponte o portal para ele**, no `CONFIG` do `login.html`:
```js
webhook: "http://10.10.0.50:3000/",
```

Pronto. Cada cadastro aparece na hora no painel e no terminal.

> **O coletor precisa estar ligado na hora do cadastro.** Se o PC estiver
> desligado, a pessoa **ainda consegue conectar** — o portal espera até
> `webhookTimeout` (3,5s por padrão) e libera o acesso mesmo assim. Isso é de
> propósito: morador não pode ficar sem internet porque o seu PC caiu. Mas o
> cadastro daquele acesso se perde.
>
> Se o registro não pode ter buracos, deixe o coletor num equipamento que fica
> sempre ligado (um mini PC na portaria, um Raspberry Pi), ou use a planilha do
> Google como segunda via.

Os arquivos ficam soltos na pasta, sem senha. Se o computador é compartilhado,
guarde a pasta num diretório com acesso restrito — são nome, e-mail e endereço de
morador.

### 4.5 Guardar os logs de acesso

O Marco Civil pede a guarda dos registros de conexão por 1 ano. Para gravar em
disco no próprio roteador:
```
/system logging action add name=arquivo-hotspot target=disk disk-file-name=hotspot disk-lines-per-file=20000 disk-file-count=10
/system logging add topics=hotspot,info action=arquivo-hotspot
```
Para volume maior, o certo é mandar para um syslog externo:
```
/system logging action add name=syslog target=remote remote=192.168.88.50 remote-port=514
/system logging add topics=hotspot,info action=syslog
```

### 4.6 Hospedar na Hostinger

A Hostinger é PHP + MySQL, então o coletor em Node (`coletor/`) não roda lá.
Use a pasta **`hostinger/`** — mesma função, escrita em PHP.

**1. Crie o banco.** hPanel → *Bancos de dados MySQL* → crie banco e usuário.
Anote os três nomes (eles vêm com prefixo, tipo `u123456789_wifi`).

**2. Crie a tabela.** hPanel → *phpMyAdmin* → aba **SQL** → cole o conteúdo de
`hostinger/banco.sql` → Executar.

**3. Configure.** Abra `hostinger/config.php` e preencha `DB_*`. Depois gere
seus próprios segredos:
```
php -r "echo bin2hex(random_bytes(16));"                    # o TOKEN
php -r "echo password_hash('suasenha', PASSWORD_DEFAULT);"  # o PAINEL_HASH
```
A senha padrão do painel é `trocar123`. **Troque.**

**4. Suba os arquivos.** Gerenciador de arquivos do hPanel → dentro de
`public_html`, crie a pasta `wifi` e mande a pasta `hostinger/` inteira para lá
(incluindo o `.htaccess` e a sua `logo.png`).

**5. Ligue o portal no site.** No `hotspot/login.html`:
```js
webhook: "https://seudominio.com.br/wifi/receber.php",
token:   "o-mesmo-token-do-config.php",
```

**6. Libere no walled garden.** Sem isso o celular não alcança o site antes do
login. Para HTTPS tem que ser o walled garden **de IP**:
```
/ip hotspot walled-garden ip add dst-host=seudominio.com.br action=accept
```
> O `/ip hotspot walled-garden` (sem o `ip`) só funciona para HTTP. Para HTTPS
> é o `walled-garden ip`, que resolve o domínio e libera no nível de IP.

**Pronto.** O painel fica em `https://seudominio.com.br/wifi/painel.php` — tem
busca por nome/e-mail/apartamento/MAC, filtro por bloco e período, os contadores
do dia, exportação para CSV e botão de excluir (direito de exclusão da LGPD).

O que foi verificado: o endpoint recusa `GET`, recusa token errado, revalida
nome e e-mail no servidor (o navegador pode ser burlado), usa *prepared
statements* — uma tentativa de `DROP TABLE` no campo nome foi gravada como
texto e a tabela continuou lá — e o painel escapa a saída, então um
`<script>` no nome aparece como texto, sem executar.

Três coisas para saber:

- **O token não é segredo forte.** Quem está na rede Wi-Fi consegue lê-lo no
  código-fonte da página. Ele serve para barrar bot que varre a internet, não
  para autenticar. Quem quiser fraudar um cadastro consegue.
- **Ative o HTTPS** no hPanel (o certificado é gratuito). O `.htaccess` já
  força o redirecionamento.
- **Você passa a guardar e-mail num servidor.** Backup do banco, senha forte no
  painel e apagar o que não precisa mais deixaram de ser opcionais.

### 4.7 Equipamentos que não devem fazer login

Câmera, DVR, interfone, elevador, portão, impressora, TV box e os próprios APs
não têm como preencher um formulário. Eles precisam de **bypass** do hotspot —
o arquivo é `mikrotik/equipamentos-sem-login.rsc`.

**O jeito certo é separar as redes.** Equipamento numa VLAN/interface própria,
sem hotspot nenhum configurado nela. Não existe o que burlar, e ninguém
consegue se passar por equipamento para fugir do cadastro.

**Se não der para separar**, libera por MAC na mesma rede:
```
/ip hotspot ip-binding
add mac-address=AA:BB:CC:00:00:10 type=bypassed comment="DVR portaria"
add mac-address=AA:BB:CC:00:00:11 type=bypassed comment="Camera hall"
```

No WinBox dá para fazer sem digitar MAC: `IP → Hotspot → aba Hosts`, clique no
equipamento que está preso no login → botão **Make Binding** → Type:
`bypassed`. Pronto.

**Para muitos equipamentos**, é mais fácil reservar uma faixa de IP e liberar a
faixa inteira de uma vez:

| Faixa | Uso |
|---|---|
| `10.10.0.1` | o próprio MikroTik |
| `10.10.0.2` – `.49` | equipamentos (bypass, sem login) |
| `10.10.0.50` – `.254` | moradores e visitantes (com login) |

```
/ip hotspot ip-binding add address=10.10.0.2-10.10.0.49 type=bypassed comment="equipamentos"
/ip pool set [find name=pool-hotspot] ranges=10.10.0.50-10.10.0.254
```

> O segundo comando não é opcional. Se o DHCP continuar entregando IPs dentro
> da faixa liberada, um morador pode pegar um `10.10.0.30` e navegar sem
> cadastro nenhum. Encolher o pool é o que fecha essa brecha.

Depois dê IP fixo a cada aparelho (`IP → DHCP Server → Leases → Make Static`)
para ele sempre cair dentro da faixa liberada.

Dois cuidados: `bypassed` significa internet liberada **sem login, sem limite
de banda e sem registro de quem usou** — só coloque na lista equipamento que
você controla. E MAC se clona; se a câmera não precisa de internet (quase nunca
precisa), bloqueie a saída dela para a WAN e deixe só a rede interna.

### 4.8 Autorizar os APs por MAC

Se você quer que **só os roteadores cadastrados** entreguem o Wi-Fi com o
portal, o arquivo é `mikrotik/aps-autorizados.rsc`.

Antes de aplicar, entenda a limitação: **o hotspot não enxerga o MAC do AP.**
Um AP em modo bridge repassa os quadros do celular sem trocar o MAC de origem,
então o MikroTik só vê o MAC do celular. Não existe "filtro de AP" dentro do
hotspot — o bloqueio precisa acontecer uma camada antes, e o método depende de
como os seus APs estão ligados:

| Sua rede | Como autorizar |
|---|---|
| APs MikroTik com CAPsMAN | Regra de provisionamento por `radio-mac`. AP fora da lista conecta mas não recebe configuração, então não emite SSID nenhum. É o whitelist de verdade. |
| APs de outra marca em portas do MikroTik | Não dá para autorizar o AP em si, mas dá para bloquear **roteador estranho plugado na rede** (ele faz NAT, então o tráfego sai com o MAC dele e com o TTL decrementado). |
| Um hotspot/VLAN por AP | AP sem VLAN e sem hotspot configurado simplesmente não tem login. De quebra, o portal passa a saber de qual bloco veio o acesso. |

Para descobrir o MAC de cada AP:
```
/ip neighbor print
/interface bridge host print where !local
```

Se você for pelo caminho de um hotspot por AP, preencha o `CONFIG.locais` do
`login.html` para o cadastro registrar o local:
```js
locais: {
  "hs-blocoA": "Bloco A - térreo",
  "hs-blocoB": "Bloco B - térreo"
},
conferirBloco: true
```
Com isso o cadastro grava de qual AP a pessoa conectou e marca quando o bloco
declarado não bate com o AP — útil para achar quem informa bloco errado. **Não
bloqueia o acesso**, só registra a divergência na coluna `Confere?`.

---

## 5. Como atualizar a página depois

1. Edite o arquivo aqui.
2. Suba por cima no roteador (WinBox/FTP substitui direto).
3. **Não precisa reiniciar** o roteador nem o hotspot.
4. Force o recarregamento no celular de teste: desconecte do Wi-Fi, esqueça a
   rede e conecte de novo. O portal cativo do Android/iOS guarda cache com
   bastante teimosia.
5. Para derrubar quem está conectado e ver a tela nova:
   ```
   /ip hotspot active remove [find]
   ```

---

## 6. Como atualizar o MikroTik

São **duas atualizações diferentes** e a ordem importa: primeiro o **RouterOS**
(o sistema), depois o **firmware da RouterBOARD** (o "bootloader" da placa).

### Antes de qualquer coisa: backup

```
/system backup save name=antes-update
/export file=config-antes-update
```
Baixe os dois arquivos (`.backup` e `.rsc`) em `Files` para o seu computador.
O `.backup` restaura o roteador idêntico; o `.rsc` é texto e serve para ler e
reaplicar comandos.

> Atualize **presencialmente** ou com alguém no local. Se algo der errado no
> reboot, você perde o acesso remoto e só resolve com cabo.

### Passo 1 — Escolher o canal

```
/system package update set channel=stable
```
- `long-term` — só correções, quase nunca quebra nada. Melhor para condomínio.
- `stable` — versão atual recomendada. É o padrão e serve bem.
- `testing` / `development` — não use em produção.

### Passo 2 — Atualizar o RouterOS

**Pelo WinBox:** `System` → `Packages` → `Check For Updates` →
`Download & Install`. O roteador reinicia sozinho (2 a 5 minutos).

**Pelo terminal:**
```
/system package update check-for-updates
/system package update print
```
Compare `installed-version` com `latest-version`. Se houver versão nova:
```
/system package update install
```
Isso baixa, instala e **reinicia automaticamente**. Se preferir separar:
```
/system package update download
/system reboot
```

Precisa de internet no roteador. Sem internet, baixe o pacote
`routeros-<versão>-<arquitetura>.npk` no site da MikroTik, arraste para `Files`
pelo WinBox e reinicie — ele instala no boot.

### Passo 3 — Atualizar o firmware da RouterBOARD

Depois que o roteador voltar:
```
/system routerboard print
```
Se `current-firmware` estiver menor que `upgrade-firmware`:
```
/system routerboard upgrade
/system reboot
```
Esse segundo reboot é obrigatório — sem ele o firmware novo não entra.

### Passo 4 — Conferir se o hotspot continua de pé

```
/system package update print
/system routerboard print
/ip hotspot print
/ip hotspot profile print
/file print where name~"hotspot-vn"
```
Confirme que o `html-directory` ainda aponta para a sua pasta e que os arquivos
continuam lá. Depois conecte um celular e teste o cadastro de ponta a ponta.

### Observações importantes

- **Pulando de v6 para v7:** só atualize a partir da **6.45.1 ou superior**, e
  leia as notas de versão antes. Configurações de wireless mudam bastante (o
  pacote `wireless` virou `wifiwave2`/`wifi` em vários modelos) e o hotspot pode
  precisar de ajuste. Faça isso com o roteador na sua frente.
- **Espaço em disco:** modelos antigos (hAP lite, hEX lite) têm pouca memória
  flash. Se faltar espaço, remova pacotes que não usa em `System → Packages`.
- **Voltar atrás:** com o `.npk` da versão antiga em `Files`, rode
  `/system package downgrade`. O roteador reinicia na versão anterior.
- **Emergência:** se o roteador não subir depois do update, use o
  [Netinstall](https://mikrotik.com/download) com cabo de rede direto — ele
  reinstala o RouterOS do zero (e apaga a configuração; por isso o backup).
- Uma atualização do RouterOS **não apaga** arquivos que você subiu, mas pode
  recriar a pasta padrão `hotspot/` com as páginas originais da MikroTik. É
  exatamente por isso que este guia usa uma pasta com nome próprio.

---

## 7. Problemas comuns

| Sintoma | Causa provável |
|---|---|
| "invalid username or password" | `CONFIG.usuario`/`CONFIG.senha` não batem com `/ip hotspot user`, ou o perfil está no modo `cpf` sem RADIUS. |
| A página abre sem estilo / sem validação | Faltou subir `vn.css` ou `md5.js` na mesma pasta. |
| Aparece o wordmark em vez da logo | Falta o `logo.png` na pasta do hotspot. |
| Aparece `$(link-login-only)` na tela | Os arquivos estão na pasta errada — o `html-directory` do perfil não aponta para eles. |
| Cadastro não chega na planilha | Domínio do webhook não está no walled garden. |
| Só um aparelho conecta por vez | `shared-users` do perfil do usuário está baixo. Aumente. |
| Portal não abre no celular | Verifique DNS (`/ip dns`) e se o `dns-name` do perfil resolve dentro da rede. |

---

## 8. Sobre os dados coletados

Você passa a tratar dados pessoais (nome e e-mail são dados pessoais pela LGPD).
Na prática isso significa:

- Colete só o necessário e guarde apenas o tempo necessário.
- Restrinja quem tem acesso à planilha/servidor com os cadastros.
- Deixe claro para o morador o que é coletado — o texto de aceite da página já
  faz isso, mas vale ter um aviso no quadro do condomínio.
- e-mail não é obrigatório por lei para liberar Wi-Fi. O que o Marco Civil exige é
  a guarda dos **registros de conexão** (IP, data e hora). Se quiser reduzir
  risco, dá para pedir só nome + bloco + apartamento: basta deixar
  `pedirEmail: false` no `CONFIG`.
