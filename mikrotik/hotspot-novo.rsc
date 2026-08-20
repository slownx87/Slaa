# =============================================================================
#  VN System - portal Wi-Fi
#  USE ESTE se voce ainda NAO TEM hotspot nenhum e vai montar do zero.
#  Se ja tem hotspot funcionando, use hotspot-existente.rsc (bem mais curto).
#
#  RouterOS 6.4x e 7.x
#
#  NAO cole tudo de uma vez sem ler. Ajuste os nomes de interface, faixa de IP
#  e senhas antes de aplicar. Rode bloco por bloco no terminal do WinBox.
# =============================================================================

# --- 0) SEMPRE FACA BACKUP ANTES -------------------------------------------
/system backup save name=antes-hotspot
/export file=config-antes-hotspot

# --- 1) Variaveis que voce deve ajustar -------------------------------------
#   IFACE     = interface (ou bridge) onde ficam os APs / o Wi-Fi
#   REDE      = faixa do hotspot
#   DIR       = pasta dos arquivos HTML (use uma pasta propria, assim uma
#               atualizacao do RouterOS nunca sobrescreve sua pagina)
#
#   IFACE = bridge-wifi
#   REDE  = 10.10.0.0/24  -> gateway 10.10.0.1
#   DIR   = hotspot-vn

# --- 2) Endereco, pool e DHCP ------------------------------------------------
/ip address add address=10.10.0.1/24 interface=bridge-wifi comment="gateway hotspot"
/ip pool add name=pool-hotspot ranges=10.10.0.10-10.10.0.254
/ip dhcp-server add name=dhcp-hotspot interface=bridge-wifi address-pool=pool-hotspot lease-time=2h disabled=no
/ip dhcp-server network add address=10.10.0.0/24 gateway=10.10.0.1 dns-server=10.10.0.1

# --- 3) Perfil do servidor hotspot ------------------------------------------
#  login-by=cookie,http-chap  -> cookie evita pedir o cadastro toda hora
#  html-directory             -> pasta onde voce subiu os arquivos
/ip hotspot profile add name=perfil-vn \
    hotspot-address=10.10.0.1 \
    dns-name=wifi.vnsystem \
    html-directory=hotspot-vn \
    login-by=cookie,http-chap \
    http-cookie-lifetime=3d

# Se estiver editando o perfil que ja existe, use:
# /ip hotspot profile set [find name=hsprof1] html-directory=hotspot-vn login-by=cookie,http-chap http-cookie-lifetime=3d

# --- 4) Servidor hotspot -----------------------------------------------------
/ip hotspot add name=wifi-vn interface=bridge-wifi address-pool=pool-hotspot \
    profile=perfil-vn addresses-per-mac=2 idle-timeout=30m keepalive-timeout=5m disabled=no

# --- 5) Perfil de usuario (velocidade e quantos aparelhos por conta) ---------
#  shared-users alto porque TODO MUNDO usa a mesma conta; quem identifica a
#  pessoa e o cadastro da pagina, nao o usuario do hotspot.
/ip hotspot user profile add name=moradores \
    shared-users=500 \
    rate-limit=10M/50M \
    session-timeout=12h \
    idle-timeout=30m \
    status-autorefresh=1m

# --- 6) A conta compartilhada usada pela pagina ------------------------------
#  Precisa bater EXATAMENTE com CONFIG.usuario / CONFIG.senha do login.html
/ip hotspot user add name=visitante password=visitante profile=moradores comment="conta usada pela pagina de cadastro"

# --- 7) Walled garden --------------------------------------------------------
#  So e necessario se voce configurar CONFIG.webhook no login.html.
#  Libere o dominio do seu webhook ANTES do login, senao o envio falha.
/ip hotspot walled-garden add dst-host=script.google.com     comment="webhook cadastro"
/ip hotspot walled-garden add dst-host=script.googleusercontent.com comment="webhook cadastro"

# --- 8) DNS ------------------------------------------------------------------
/ip dns set servers=1.1.1.1,8.8.8.8 allow-remote-requests=yes

# --- 9) Guardar o log de acessos (Marco Civil) -------------------------------
#  Manda o log do hotspot para um arquivo em disco, com rotacao.
/system logging action add name=arquivo-hotspot target=disk disk-file-name=hotspot disk-lines-per-file=20000 disk-file-count=10
/system logging add topics=hotspot,info action=arquivo-hotspot

# --- 10) Conferir -------------------------------------------------------------
# /ip hotspot print
# /ip hotspot active print
# /ip hotspot user print
# /file print where name~"hotspot-vn"
