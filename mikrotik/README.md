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
| 5000 | `10.0.104.9:80` | Módulo Guarita (HTTP) |
| 9000 | `10.0.104.9:9000` | Módulo Guarita |

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

- **DVR (.22) e Guarita (.9) precisam estar com IP fixo** nesses endereços. O pool DHCP começa em `.100` justamente para não conflitar.
- Se a WAN for PPPoE ou outra interface, trocar `wanIf` no topo do script (ex.: `pppoe-out1`).
- Se o DVR usar mais portas além da 35555 (mídia/RTSP/UDP), é só acrescentar outra linha `/ip firewall nat add ...` no mesmo formato.
