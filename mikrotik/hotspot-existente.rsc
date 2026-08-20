# =============================================================================
#  VN System - portal Wi-Fi
#  USE ESTE se o hotspot JA EXISTE e funciona. Sao 3 comandos.
#
#  Antes: suba os arquivos da pasta hotspot/ do repositorio para uma pasta
#  chamada  hotspot-vn  dentro de Files no roteador.
# =============================================================================

# --- 1) Descubra qual perfil o seu hotspot usa -------------------------------
#  Rode e olhe a coluna PROFILE (normalmente "hsprof1"):
/ip hotspot print

# --- 2) Aponte esse perfil para a pasta dos arquivos -------------------------
#  Troque hsprof1 pelo nome que apareceu acima.
/ip hotspot profile set [find name="hsprof1"] \
    html-directory=hotspot-vn \
    login-by=cookie,http-chap \
    http-cookie-lifetime=3d

# --- 3) Crie a conta que a pagina usa para logar -----------------------------
#  Tem que bater EXATAMENTE com CONFIG.usuario / CONFIG.senha do login.html.
/ip hotspot user profile add name=moradores shared-users=500 rate-limit=10M/50M idle-timeout=30m
/ip hotspot user add name=visitante password=visitante profile=moradores comment="conta usada pelo portal VN"

# --- Pronto. Teste conectando um celular. -------------------------------------

# --- Opcional: webhook (so se voce preencheu CONFIG.webhook) -----------------
# /ip hotspot walled-garden add dst-host=script.google.com comment="webhook VN"
# /ip hotspot walled-garden add dst-host=script.googleusercontent.com comment="webhook VN"

# --- Opcional: derrubar quem esta conectado para ver a tela nova -------------
# /ip hotspot active remove [find]
