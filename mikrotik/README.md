# MikroTik RB951Ui-2HnD

Script `rb951ui-2hnd.rsc` — parte de um MikroTik (RouterOS 6.39.2) com a **configuração padrão de fábrica** (LAN `192.168.88.1/24`, WAN em `ether1` via DHCP).

## O que ele faz

| Item | Valor |
|---|---|
| IP do MikroTik (LAN) | `10.0.104.1/24` |
| Pool DHCP | `10.0.104.100 – 10.0.104.254` |
| WAN | `ether1` (DHCP client do padrão, não alterado) |

Redirecionamentos de porta (todos TCP, entrando por `ether1`):

| Porta externa | Destino | Equipamento |
|---|---|---|
| 35555 | `10.0.104.22:35555` | DVR Tolotti (app) |
| 80 | `10.0.104.22:2000` | DVR Tolotti (web) |
| 2000 | `10.0.104.22:2000` | DVR Tolotti (web, porta externa 2000) |
| 554 | `10.0.104.22:3000` | DVR Tolotti (RTSP) |
| 5000 | `10.0.104.9:80` | Módulo Guarita (HTTP) |
| 9000 | `10.0.104.9:9000` | Módulo Guarita |
| 8888 | `10.0.104.40:8888` | ATA FXO (web) |
| 7777 | `10.0.104.39:7777` | ATA FXS (web) |
| 9009 | `10.0.104.30:9009` | Equipamento .30 |

O firewall padrão já libera o tráfego que passou por dstnat (`drop all from WAN not DSTNATed`), por isso não há regra de filter extra para os redirecionamentos.

## Como aplicar

1. Winbox → **Files** → arrastar `rb951ui-2hnd.rsc`.
2. **New Terminal**: `/import file-name=rb951ui-2hnd.rsc`
3. A sessão cai (o IP da LAN muda). Renove o DHCP do PC (ou fixe `10.0.104.x`) e entre em `10.0.104.1`.

O script pode ser rodado de novo sem duplicar regras (as regras NAT têm comentário `PF:`).

## Conferir

```
/ip address print
/ip firewall nat print
/ip dhcp-server network print
```

Teste de fora: `http://<IP-WAN>:35555`, `http://<IP-WAN>:5000`, porta `9000`.

## Observações

- **DVR (.22), Guarita (.9), ATA FXO (.40), ATA FXS (.39) e o equipamento .30 precisam estar com IP fixo** nesses endereços. O pool DHCP começa em `.100` justamente para não conflitar.
- **NAT duplo (visto em campo):** o `/ip cloud print` mostra `your local IP was 192.168.15.2`, ou seja, a WAN do MikroTik (`ether1`) está em `192.168.15.2`, atrás de um modem da operadora que é quem tem o IP público. De fora, o pedido chega primeiro no modem: cada porta da tabela acima também precisa ser encaminhada **no modem** para `192.168.15.2` (mesma porta), ou o modem precisa estar em DMZ para esse IP, ou em modo bridge com PPPoE no MikroTik. Confira os contadores em `/ip firewall nat print stats`.
- Por dentro da rede o IP público/nome DDNS não funciona (o IP público é do modem, não do MikroTik); use o IP interno do equipamento.
- Se o DVR usar mais portas além das acima (mídia/UDP), é só acrescentar outra linha `/ip firewall nat add ...` no mesmo formato.

## Acesso remoto ao próprio MikroTik (Winbox / API / app)

Não faz parte do script. Fica bloqueado pelo firewall padrão: `defconf: drop all from WAN` (chain `input`, `in-interface=ether1`) derruba tudo que não é conexão já estabelecida.

No equipamento as portas dos serviços foram alteradas em `/ip service`: **API 18728**, API-SSL 18729, SSH 122, Telnet 123, FTP 121 (www desativado). O **Winbox continua em 8291**.

Para liberar, é preciso uma regra `accept` **antes** do `drop all from WAN`, e as portas 8291 e 18728 também precisam ser encaminhadas no modem para `192.168.15.2`.

Uma linha só, liberada para **qualquer IP**. Só mexe na chain `input` (tráfego para o próprio roteador): não afeta os redirecionamentos, que passam pela chain `forward`.

```
/ip firewall filter add chain=input action=accept protocol=tcp dst-port=8291,18728 in-interface=ether1 comment="Admin remoto Winbox/API"; /ip firewall filter move [find where comment="Admin remoto Winbox/API"] destination=[find where comment="defconf: drop all from WAN"]
```

**Risco:** o RouterOS 6.39.2 tem falha conhecida no Winbox (CVE-2018-14847, corrigida na 6.42.1) que permite ler a senha do admin sem login. Com a porta aberta para qualquer IP, atualizar para a 6.49.x e usar senha forte é o mínimo. Para reduzir a exposição, deixar só `dst-port=18728` (API, não afetada por essa falha) ou acrescentar `src-address=IP-DO-ADMIN` na regra.

Desfazer:

```
/ip firewall filter remove [find where comment="Admin remoto Winbox/API"]
```
