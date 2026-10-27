# ============================================================================
# MikroTik RB951Ui-2HnD - configuracao a partir do padrao de fabrica (defconf)
#
#   LAN : 10.0.104.0/24  (MikroTik = 10.0.104.1)
#   WAN : ether1 (DHCP client do padrao de fabrica)
#
#   Redirecionamentos (porta externa -> destino):
#     tcp 35555 -> 10.0.104.22:35555   DVR Tolotti
#     tcp 554   -> 10.0.104.22:3000    DVR Tolotti (RTSP)
#     tcp 5000  -> 10.0.104.9:80       Modulo Guarita (HTTP)
#     tcp 9000  -> 10.0.104.9:9000     Modulo Guarita
#     tcp+udp 8888 -> 10.0.104.40:8888 ATA FXO
#     tcp+udp 7777 -> 10.0.104.39:7777 ATA FXS
#
# Compativel com RouterOS 6.x e 7.x. Pode ser executado mais de uma vez.
# Uso:  enviar para /Files e rodar   /import file-name=rb951ui-2hnd.rsc
#
# ATENCAO: no final o IP da LAN muda de 192.168.88.1 para 10.0.104.1 e a
# sessao (Winbox/SSH) cai. Reconecte em 10.0.104.1 depois de renovar o DHCP.
# ============================================================================

:local lanIp      "10.0.104.1"
:local lanNet     "10.0.104.0"
:local lanCidr    "10.0.104.1/24"
:local lanNetCidr "10.0.104.0/24"
# Pool comeca em .100 para nao colidir com os IPs fixos (.9 guarita, .22 DVR)
:local poolRange  "10.0.104.100-10.0.104.254"

# Interface de entrada da internet (se usar PPPoE, trocar por pppoe-out1)
:local wanIf      "ether1"

:local ipDvr      "10.0.104.22"
:local ipGuarita  "10.0.104.9"
:local ipAtaFxo   "10.0.104.40"
:local ipAtaFxs   "10.0.104.39"

:put ("Board: " . [/system resource get board-name])

# ---------------------------------------------------------------------------
# 1) Redirecionamento de portas (dstnat)
#    O firewall padrao ja libera o que foi DSTNATed, nao precisa de filter.
# ---------------------------------------------------------------------------
/ip firewall nat remove [find where comment~"^PF:"]

/ip firewall nat add chain=dstnat action=dst-nat protocol=tcp dst-port=35555 \
    in-interface=$wanIf to-addresses=$ipDvr to-ports=35555 \
    comment="PF: DVR Tolotti 35555"

/ip firewall nat add chain=dstnat action=dst-nat protocol=tcp dst-port=554 \
    in-interface=$wanIf to-addresses=$ipDvr to-ports=3000 \
    comment="PF: DVR Tolotti RTSP 554 -> 3000"

/ip firewall nat add chain=dstnat action=dst-nat protocol=tcp dst-port=5000 \
    in-interface=$wanIf to-addresses=$ipGuarita to-ports=80 \
    comment="PF: Guarita HTTP 5000 -> 80"

/ip firewall nat add chain=dstnat action=dst-nat protocol=tcp dst-port=9000 \
    in-interface=$wanIf to-addresses=$ipGuarita to-ports=9000 \
    comment="PF: Guarita 9000"

/ip firewall nat add chain=dstnat action=dst-nat protocol=tcp dst-port=8888 \
    in-interface=$wanIf to-addresses=$ipAtaFxo to-ports=8888 \
    comment="PF: ATA FXO 8888 tcp"

/ip firewall nat add chain=dstnat action=dst-nat protocol=udp dst-port=8888 \
    in-interface=$wanIf to-addresses=$ipAtaFxo to-ports=8888 \
    comment="PF: ATA FXO 8888 udp"

/ip firewall nat add chain=dstnat action=dst-nat protocol=tcp dst-port=7777 \
    in-interface=$wanIf to-addresses=$ipAtaFxs to-ports=7777 \
    comment="PF: ATA FXS 7777 tcp"

/ip firewall nat add chain=dstnat action=dst-nat protocol=udp dst-port=7777 \
    in-interface=$wanIf to-addresses=$ipAtaFxs to-ports=7777 \
    comment="PF: ATA FXS 7777 udp"

# ---------------------------------------------------------------------------
# 2) DHCP: pool, rede, gateway e DNS na nova faixa
# ---------------------------------------------------------------------------
:foreach s in=[/ip dhcp-server find] do={
    :local p [/ip dhcp-server get $s address-pool]
    :foreach i in=[/ip pool find where name=$p] do={
        /ip pool set $i ranges=$poolRange
    }
}

:foreach n in=[/ip dhcp-server network find where address="192.168.88.0/24"] do={
    /ip dhcp-server network set $n address=$lanNetCidr gateway=$lanIp dns-server=$lanIp
}

# leases antigos da faixa 192.168.88.x
/ip dhcp-server lease remove [find where dynamic]

# ---------------------------------------------------------------------------
# 3) DNS local (router.lan -> novo IP)
# ---------------------------------------------------------------------------
:foreach d in=[/ip dns static find where address="192.168.88.1"] do={
    /ip dns static set $d address=$lanIp
}

# ---------------------------------------------------------------------------
# 4) IP da LAN - por ultimo, porque derruba a sessao atual
# ---------------------------------------------------------------------------
:foreach a in=[/ip address find where address="192.168.88.1/24"] do={
    /ip address set $a address=$lanCidr network=$lanNet
}

:put "Pronto. LAN em 10.0.104.1/24 - reconecte em 10.0.104.1"
