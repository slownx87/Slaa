# =============================================================================
#  VN System - autorizar os APs/roteadores por MAC
#
#  Objetivo: SO os roteadores cadastrados aqui entregam o Wi-Fi com o portal
#  de login. Roteador que nao esta na lista nao serve rede nenhuma.
#
#  LEIA ISTO PRIMEIRO
#  ------------------
#  O hotspot NAO enxerga o MAC do AP. Quando o AP esta em modo bridge (o caso
#  normal), ele repassa os quadros do celular sem trocar o MAC -- o MikroTik so
#  ve o MAC do celular. Entao nao existe "filtro de AP" dentro do hotspot.
#
#  O bloqueio tem que acontecer uma camada antes, e o jeito depende de como os
#  seus APs estao ligados. Escolha o cenario que bate com a sua rede.
# =============================================================================


# =============================================================================
#  PASSO 0 - Descubra o MAC de cada AP
# =============================================================================
#  Vizinhos MikroTik/CDP/LLDP (mostra identidade, IP, MAC e em que porta esta):
/ip neighbor print

#  Tudo que o bridge ja viu, com a porta de cada MAC:
/interface bridge host print where !local

#  Anote MAC + onde o AP fica fisicamente. Ex.:
#    AA:BB:CC:00:00:01  ->  Bloco A - terreo
#    AA:BB:CC:00:00:02  ->  Bloco B - terreo
#    AA:BB:CC:00:00:03  ->  Bloco C - cobertura


# =============================================================================
#  CENARIO 1 - APs MikroTik gerenciados por CAPsMAN  (RECOMENDADO)
# =============================================================================
#  Aqui o whitelist e de verdade: o AP so recebe configuracao (e so passa a
#  emitir o SSID) se o MAC dele estiver numa regra de provisionamento.
#  AP desconhecido conecta no CAPsMAN, nao recebe nada, e nao emite Wi-Fi.
#
#  --- 1a) RouterOS 7 com o pacote "wifi" / wifiwave2 --------------------------
/interface/wifi/provisioning
add radio-mac=AA:BB:CC:00:00:01 action=create-enabled master-configuration=cfg-hotspot comment="AP Bloco A - terreo"
add radio-mac=AA:BB:CC:00:00:02 action=create-enabled master-configuration=cfg-hotspot comment="AP Bloco B - terreo"
add radio-mac=AA:BB:CC:00:00:03 action=create-enabled master-configuration=cfg-hotspot comment="AP Bloco C - cobertura"
#  NAO crie uma regra catch-all (sem radio-mac). A ausencia dela e o bloqueio:
#  AP fora da lista nao casa com nenhuma regra e fica sem configuracao.

#  Confira quem esta conectado e quem ficou de fora:
/interface/wifi/capsman/remote-cap print

#  --- 1b) RouterOS 6 / CAPsMAN legado ----------------------------------------
# /caps-man provisioning
# add radio-mac=AA:BB:CC:00:00:01 action=create-dynamic-enabled master-configuration=cfg-hotspot comment="AP Bloco A"
# add radio-mac=AA:BB:CC:00:00:02 action=create-dynamic-enabled master-configuration=cfg-hotspot comment="AP Bloco B"
# /caps-man remote-cap print

#  --- 1c) Exigir certificado (impede AP clonando MAC) ------------------------
#  Sem isso, alguem pode copiar o MAC de um AP autorizado. Com certificado, nao.
# /interface/wifi/capsman/set ca-certificate=auto require-peer-certificate=yes
# /caps-man manager set ca-certificate=auto require-peer-certificate=yes


# =============================================================================
#  CENARIO 2 - APs de qualquer marca, ligados em portas do MikroTik
# =============================================================================
#  Nao da pra "autorizar o AP" (os quadros sao dos celulares). O que da, e o
#  que resolve o problema real, e bloquear ROTEADOR ESTRANHO plugado na rede:
#  um roteador desses faz NAT, entao TODO o trafego dele sai com o MAC dele.
#
#  --- 2a) Lista dos equipamentos de rede autorizados -------------------------
/interface bridge filter
#  Deixa passar os APs cadastrados
add chain=forward src-mac-address=AA:BB:CC:00:00:01/FF:FF:FF:FF:FF:FF action=accept comment="AP Bloco A - terreo"
add chain=forward src-mac-address=AA:BB:CC:00:00:02/FF:FF:FF:FF:FF:FF action=accept comment="AP Bloco B - terreo"
add chain=forward src-mac-address=AA:BB:CC:00:00:03/FF:FF:FF:FF:FF:FF action=accept comment="AP Bloco C - cobertura"

#  --- 2b) Bloqueia quem faz NAT sem estar na lista ---------------------------
#  TTL decrementado = passou por um roteador. Celular normal nao faz isso.
/ip firewall mangle
add chain=prerouting in-interface=bridge-wifi ttl=equal:127 action=mark-connection \
    new-connection-mark=roteador-nao-autorizado comment="trafego vindo de roteador estranho"
/ip firewall filter
add chain=forward connection-mark=roteador-nao-autorizado action=drop comment="bloqueia roteador plugado sem autorizacao"

#  Ajuste o valor de ttl conforme os seus clientes (Android/iOS saem com 64,
#  Windows com 128; depois de um NAT chegam com 63 / 127).
#  Teste com action=log antes de trocar para action=drop.

#  --- 2c) Impede AP falso respondendo DHCP -----------------------------------
/ip dhcp-server alert add interface=bridge-wifi alert-timeout=1h \
    valid-server=00:00:00:00:00:00 comment="troque pelo MAC do SEU servidor DHCP"


# =============================================================================
#  CENARIO 3 - Um hotspot por AP (identifica de onde a pessoa conectou)
# =============================================================================
#  Cada AP em sua propria VLAN, com seu proprio servidor hotspot. AP que nao
#  tem VLAN/hotspot configurado simplesmente nao tem login e nao navega.
#  Bonus: o portal passa a saber de qual bloco veio o acesso.
#
#  Exemplo para 3 APs (VLAN 101/102/103 sobre a bridge):
# /interface vlan
# add name=vlan-blocoA vlan-id=101 interface=bridge-wifi
# add name=vlan-blocoB vlan-id=102 interface=bridge-wifi
# add name=vlan-blocoC vlan-id=103 interface=bridge-wifi
#
# /ip address
# add address=10.10.1.1/24 interface=vlan-blocoA
# add address=10.10.2.1/24 interface=vlan-blocoB
# add address=10.10.3.1/24 interface=vlan-blocoC
#
# /ip hotspot
# add name=hs-blocoA interface=vlan-blocoA address-pool=pool-blocoA profile=perfil-vn
# add name=hs-blocoB interface=vlan-blocoB address-pool=pool-blocoB profile=perfil-vn
# add name=hs-blocoC interface=vlan-blocoC address-pool=pool-blocoC profile=perfil-vn
#
#  O nome do servidor (hs-blocoA) chega na pagina em $(server-name). Preencha
#  CONFIG.locais no login.html para o cadastro registrar o local:
#     locais: { "hs-blocoA": "Bloco A", "hs-blocoB": "Bloco B" }


# =============================================================================
#  CONFERINDO
# =============================================================================
# /interface/wifi/capsman/remote-cap print      # APs conectados (ROS7 wifi)
# /caps-man remote-cap print                    # APs conectados (ROS6)
# /interface bridge filter print stats          # quantos pacotes cada regra pegou
# /ip firewall filter print stats
# /ip dhcp-server alert print                   # servidores DHCP falsos vistos
# /log print where topics~"dhcp"
