# MikroTik RB951Ui-2HnD

Script `rb951ui-2hnd.rsc` — parte de um MikroTik com a **configuração padrão de fábrica** (LAN `192.168.88.1/24`, WAN em `ether1` via DHCP).

## O que ele faz

| Item | Valor |
|---|---|
| IP do MikroTik (LAN) | `10.0.104.1/24` |
| Pool DHCP | `10.0.104.100 – 10.0.104.254` |
| WAN | `ether1` (DHCP client do padrão, não alterado) |

Redirecionamentos de porta (todos TCP, entrando por `ether1`):

| Porta externa | Destino | Equipamento |
|---|---|---|
| 35555 | `10.0.104.22:35555` | DVR Tolotti |
| 2000 | `10.0.104.22:80` | DVR Tolotti (web) |
| 554 | `10.0.104.22:3000` | DVR Tolotti (RTSP) |
| 5000 | `10.0.104.9:80` | Módulo Guarita (HTTP) |
| 9000 | `10.0.104.9:9000` | Módulo Guarita |
| 8888 | `10.0.104.40:8888` | ATA FXO (web) |
| 7777 | `10.0.104.39:7777` | ATA FXS (web) |
| 9009 | `10.0.104.30:9009` | Equipamento .30 |

O firewall padrão já libera o tráfego que passou por dstnat (`drop all from WAN not DSTNATed`), por isso não há regra de filter extra.

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
- As regras usam `dst-address-type=local` (qualquer IP do próprio MikroTik), então funcionam com WAN DHCP, PPPoE ou outra interface, sem precisar informar o nome dela.
- A regra `PF: hairpin LAN` (srcnat/masquerade LAN→LAN) permite que aparelhos **da mesma rede** acessem pelo IP público ou nome DNS (DDNS).
- Acesso de fora só funciona se o IP da WAN for público. Se começar com `10.`, `172.16–31`, `192.168.` ou `100.64–127`, há outro roteador ou CGNAT na frente e nenhuma abertura funciona sem liberar lá.
- Se o DVR usar mais portas além da 35555 (mídia/RTSP/UDP), é só acrescentar outra linha `/ip firewall nat add ...` no mesmo formato.
