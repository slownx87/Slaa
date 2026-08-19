# Portal Wi-Fi com identificação — MikroTik Hotspot

Página de login para o Hotspot do MikroTik em que a pessoa precisa informar
**nome completo, CPF, bloco e apartamento** antes de a internet ser liberada.

Feita para rodar dentro do roteador: **não usa nenhum arquivo externo** (sem CDN,
sem fonte do Google, sem framework). Antes do login o aparelho não tem internet,
então qualquer recurso externo simplesmente não carregaria.

---

## 1. O que tem neste repositório

| Caminho | O que é |
|---|---|
| `hotspot/login.html` | A página de cadastro/login. **É aqui que você configura tudo.** |
| `hotspot/md5.js` | MD5 usado pelo login em modo HTTP-CHAP (a senha não trafega em texto puro). |
| `hotspot/alogin.html` | Tela de "conectado com sucesso". |
| `hotspot/status.html` | Status da conexão (consumo, tempo, botão desconectar). |
| `hotspot/logout.html` | Tela de "desconectado". |
| `hotspot/error.html` | Tela de erro. |
| `hotspot/rlogin.html`, `hotspot/redirect.html` | Páginas internas de redirecionamento do hotspot. |
| `hotspot/estilo.css` | Estilo comum das telas simples. |
| `mikrotik/setup-hotspot.rsc` | Comandos do RouterOS para criar o hotspot do zero. |
| `webhook/google-apps-script.js` | Opcional: grava cada cadastro numa planilha do Google. |

---

## 2. Como funciona o fluxo

1. A pessoa conecta no Wi-Fi e o MikroTik intercepta o primeiro acesso.
2. Aparece a `login.html` pedindo nome, CPF, bloco e apartamento.
3. O JavaScript valida os dados **no aparelho** (inclusive os dígitos
   verificadores do CPF — CPF inventado é recusado).
4. Os dados são gravados no navegador (`localStorage`) e, se você configurar um
   webhook, enviados para a sua planilha/servidor junto com MAC, IP e data/hora.
5. Só então a página envia o login de verdade para o hotspot, usando uma conta
   compartilhada, e a internet é liberada.

> **Importante entender:** o cadastro identifica *quem* está usando; a
> autenticação do hotspot em si continua sendo feita por uma conta do RouterOS.
> Sem RADIUS/User Manager o roteador não tem como criar uma conta por CPF
> sozinho — por isso o modo padrão é a conta compartilhada.

---

## 3. Personalizar a página

Abra `hotspot/login.html` e edite **apenas** o bloco `var CONFIG = { ... }`:

```js
var CONFIG = {
  titulo: "Acesso a rede Wi-Fi",
  rodape: "Rede protegida - uso restrito a moradores e visitantes autorizados",

  blocos: ["A", "B", "C", "D", "E", "F"],   // os blocos do seu condomínio
  pedirCelular: true,                        // false esconde o campo

  modo: "compartilhado",                     // "compartilhado" ou "cpf"
  usuario: "visitante",                      // precisa existir em /ip hotspot user
  senha:   "visitante",

  senhaCpf: "",                              // só no modo "cpf"

  webhook: "",                               // "" = não envia para lugar nenhum
  webhookTimeout: 3500
};
```

**`modo: "compartilhado"`** (padrão) — todo mundo entra com a mesma conta do
hotspot. Simples, funciona sem servidor nenhum. É o recomendado.

**`modo: "cpf"`** — envia o CPF (só os dígitos) como nome de usuário. Só use se
você tiver **RADIUS / User Manager** aceitando qualquer usuário, ou se cadastrar
os moradores por CPF manualmente. Sem isso, o login vai falhar com
"invalid username or password".

Para testar o visual, é só abrir `hotspot/login.html` no navegador do computador
— a página detecta que está fora do roteador e funciona normalmente (só o login
final não acontece).

---

## 4. Instalar no MikroTik

### 4.1 Suba os arquivos

Coloque **todo o conteúdo da pasta `hotspot/`** dentro de uma pasta no roteador.
Use um nome próprio, por exemplo `hotspot-slaa` — assim uma atualização do
RouterOS nunca sobrescreve a sua página.

**Pelo WinBox (mais fácil):**
1. `Files` → crie/abra a pasta desejada.
2. Arraste os arquivos do `hotspot/` para dentro da janela.
   (Se a pasta não existir, arraste primeiro para a raiz e depois use FTP para
   mover, ou crie a pasta arrastando um arquivo com o caminho já montado.)

**Por FTP** (usuário e senha são os mesmos do RouterOS):
```bash
ftp 192.168.88.1
# depois: mkdir hotspot-slaa ; cd hotspot-slaa ; binary ; mput *
```

**Por SCP/SFTP** (se o serviço SSH estiver ligado):
```bash
scp -r hotspot/* admin@192.168.88.1:hotspot-slaa/
```

**Baixando direto de uma URL** (dentro do terminal do RouterOS):
```
/tool fetch url="https://seu-servidor/login.html" dst-path=hotspot-slaa/login.html
```

### 4.2 Configure o hotspot

Se ainda **não** tem hotspot, o caminho rápido é o assistente:
```
/ip hotspot setup
```
Ele pergunta interface, faixa de IP, DNS e cria um usuário. Depois ajuste:

```
/ip hotspot profile set [find] html-directory=hotspot-slaa login-by=cookie,http-chap http-cookie-lifetime=3d
/ip hotspot user profile add name=moradores shared-users=500 rate-limit=10M/50M
/ip hotspot user add name=visitante password=visitante profile=moradores
```

Se quiser montar tudo do zero, use `mikrotik/setup-hotspot.rsc` como referência
(leia antes de aplicar, ajustando interface e faixa de IP).

Confira que `usuario`/`senha` do `CONFIG` são **exatamente** os mesmos do
`/ip hotspot user`, senão o login sempre falha.

### 4.3 Se for usar o webhook

O domínio precisa estar liberado **antes** do login:
```
/ip hotspot walled-garden add dst-host=script.google.com
/ip hotspot walled-garden add dst-host=script.googleusercontent.com
```
Instruções de como publicar a planilha estão em `webhook/google-apps-script.js`.

Se você deixar `webhook: ""`, nada é enviado para fora — os cadastros ficam só
no aparelho da pessoa e o registro de acesso fica no log do roteador.

### 4.4 Guardar os logs de acesso

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
/file print where name~"hotspot-slaa"
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
| A página abre sem estilo / sem validação | Faltou subir `md5.js` ou `estilo.css` na mesma pasta. |
| Aparece `$(link-login-only)` na tela | Os arquivos estão na pasta errada — o `html-directory` do perfil não aponta para eles. |
| Cadastro não chega na planilha | Domínio do webhook não está no walled garden. |
| Só um aparelho conecta por vez | `shared-users` do perfil do usuário está baixo. Aumente. |
| Portal não abre no celular | Verifique DNS (`/ip dns`) e se o `dns-name` do perfil resolve dentro da rede. |

---

## 8. Sobre os dados coletados

Você passa a tratar dados pessoais (nome e CPF são dados pessoais pela LGPD).
Na prática isso significa:

- Colete só o necessário e guarde apenas o tempo necessário.
- Restrinja quem tem acesso à planilha/servidor com os cadastros.
- Deixe claro para o morador o que é coletado — o texto de aceite da página já
  faz isso, mas vale ter um aviso no quadro do condomínio.
- CPF não é obrigatório por lei para liberar Wi-Fi. O que o Marco Civil exige é
  a guarda dos **registros de conexão** (IP, data e hora). Se quiser reduzir
  risco, dá para pedir só nome + bloco + apartamento: basta apagar o campo de
  CPF do formulário.
