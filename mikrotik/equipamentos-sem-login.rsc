# =============================================================================
#  VN System - equipamentos sem login
#
#  Camera, DVR, interfone, elevador, catraca, portao, impressora, TV box e os
#  proprios APs nao tem como preencher um formulario. Eles precisam passar
#  direto, sem ver o portal.
#
#  Isso se chama BYPASS do hotspot. Duas formas, da melhor para a mais rapida.
# =============================================================================


# =============================================================================
#  FORMA 1 - Separar as redes  (RECOMENDADO)
# =============================================================================
#  Equipamento numa interface/VLAN propria, SEM hotspot. Como o hotspot nem
#  existe naquela rede, nao ha o que burlar, e ninguem consegue se passar por
#  equipamento para fugir do login.
#
#  Exemplo: Wi-Fi dos moradores na VLAN 10 (com hotspot) e os equipamentos na
#  VLAN 20 (sem hotspot).

# /interface vlan
# add name=vlan-equipamentos vlan-id=20 interface=bridge-principal
# /ip address add address=10.20.0.1/24 interface=vlan-equipamentos
# /ip pool add name=pool-equipamentos ranges=10.20.0.10-10.20.0.200
# /ip dhcp-server add name=dhcp-equip interface=vlan-equipamentos address-pool=pool-equipamentos disabled=no
# /ip dhcp-server network add address=10.20.0.0/24 gateway=10.20.0.1 dns-server=10.20.0.1
#
#  Nao crie nenhum /ip hotspot nessa VLAN. Fim -- os equipamentos nunca veem login.


# =============================================================================
#  FORMA 2 - Bypass por MAC, na mesma rede
# =============================================================================
#  Quando nao da para separar as redes. Cada equipamento entra numa lista de
#  ip-binding com type=bypassed e passa direto pelo hotspot.

# --- 2a) Descubra o MAC de cada equipamento ---------------------------------
#  Quem esta preso na tela de login agora:
/ip hotspot host print

#  Quem pegou IP do DHCP (mostra hostname, ajuda a identificar):
/ip dhcp-server lease print

#  Vizinhos que se anunciam (APs, switches, cameras MikroTik/Ubiquiti):
/ip neighbor print

# --- 2b) Libere cada um -----------------------------------------------------
#  Troque os MACs pelos seus. O comment e o que vai te salvar daqui a 6 meses.
/ip hotspot ip-binding
add mac-address=AA:BB:CC:00:00:01 type=bypassed comment="AP Bloco A - terreo"
add mac-address=AA:BB:CC:00:00:02 type=bypassed comment="AP Bloco B - terreo"
add mac-address=AA:BB:CC:00:00:03 type=bypassed comment="AP Bloco C - cobertura"
add mac-address=AA:BB:CC:00:00:10 type=bypassed comment="DVR portaria"
add mac-address=AA:BB:CC:00:00:11 type=bypassed comment="Camera hall - entrada"
add mac-address=AA:BB:CC:00:00:12 type=bypassed comment="Camera garagem"
add mac-address=AA:BB:CC:00:00:20 type=bypassed comment="Interfone"
add mac-address=AA:BB:CC:00:00:21 type=bypassed comment="Controle do portao"
add mac-address=AA:BB:CC:00:00:22 type=bypassed comment="Elevador - telemetria"
add mac-address=AA:BB:CC:00:00:30 type=bypassed comment="Impressora portaria"

#  Atalho no WinBox, sem digitar MAC nenhum:
#  IP > Hotspot > aba Hosts > clique no equipamento > botao "Make Binding"
#  > Type: bypassed > OK. Pronto, ele nunca mais ve o login.


# =============================================================================
#  FORMA 2b - Bypass por FAIXA DE IP  (mais facil de manter)
# =============================================================================
#  Em vez de uma linha por equipamento, voce reserva uma faixa so para eles e
#  libera a faixa inteira de uma vez. Depois e so dar IP fixo a cada aparelho.
#
#  Plano de enderecos sugerido para a rede 10.10.0.0/24:
#     10.10.0.1              -> o proprio MikroTik
#     10.10.0.2  a .49       -> equipamentos (bypass, sem login)
#     10.10.0.50 a .254      -> moradores e visitantes (com login)

# --- 3a) Libere a faixa dos equipamentos ------------------------------------
/ip hotspot ip-binding
add address=10.10.0.2-10.10.0.49 type=bypassed comment="faixa dos equipamentos - sem login"

# --- 3b) Deixe o DHCP entregar so a faixa dos moradores ---------------------
/ip pool set [find name=pool-hotspot] ranges=10.10.0.50-10.10.0.254

# --- 3c) IP fixo para cada equipamento (reserva no DHCP) --------------------
#  Assim o aparelho sempre cai dentro da faixa liberada, mesmo depois de
#  reiniciar ou trocar de porta.
/ip dhcp-server lease
add mac-address=AA:BB:CC:00:00:01 address=10.10.0.2 server=dhcp-hotspot comment="AP Bloco A"
add mac-address=AA:BB:CC:00:00:02 address=10.10.0.3 server=dhcp-hotspot comment="AP Bloco B"
add mac-address=AA:BB:CC:00:00:10 address=10.10.0.10 server=dhcp-hotspot comment="DVR portaria"
add mac-address=AA:BB:CC:00:00:11 address=10.10.0.11 server=dhcp-hotspot comment="Camera hall"
add mac-address=AA:BB:CC:00:00:20 address=10.10.0.20 server=dhcp-hotspot comment="Interfone"

#  Para transformar em fixo um lease que ja existe, sem digitar nada:
#  IP > DHCP Server > aba Leases > clique no aparelho > botao "Make Static"


# =============================================================================
#  CUIDADOS
# =============================================================================
#  1. bypassed = internet liberada, sem login, sem limite de banda e sem
#     registro de quem usou. So coloque na lista equipamento que VOCE controla.
#
#  2. MAC se clona. Se alguem copiar o MAC do DVR, entra sem login. Por isso a
#     FORMA 1 (rede separada) e melhor: nao adianta clonar MAC se o aparelho
#     nem esta na mesma rede.
#
#  3. Nunca faca bypass de uma faixa que o DHCP ainda entrega para celulares --
#     se um morador pegar IP dentro da faixa liberada, ele navega sem cadastro.
#     Por isso o passo 3b (encolher o pool) vem junto com o 3a.
#
#  4. Equipamento nao precisa de internet? Melhor ainda: bloqueie a saida dele
#     e deixe so a rede interna. Camera e DVR normalmente nao precisam sair.
#     /ip firewall filter add chain=forward src-address=10.10.0.10-10.10.0.19 \
#         out-interface=ether1-wan action=drop comment="cameras nao saem para a internet"


# =============================================================================
#  CONFERINDO
# =============================================================================
# /ip hotspot ip-binding print          # quem esta liberado
# /ip hotspot host print                # quem o hotspot esta vendo agora
# /ip hotspot active print              # quem fez login de verdade
# /ip dhcp-server lease print           # IPs entregues, fixos e dinamicos
