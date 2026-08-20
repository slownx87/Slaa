# =============================================================================
#  VN System - guardar os acessos em .txt dentro do MikroTik
#
#  LEIA ANTES
#  ----------
#  O hotspot do MikroTik so SERVE arquivos estaticos. Ele nao roda codigo do
#  lado do servidor, entao NAO existe como a pagina mandar o nome e o CPF para
#  dentro do roteador e ele gravar num arquivo. Nao e limitacao da pagina, e do
#  RouterOS: nao ha para onde postar.
#
#  O que o roteador consegue guardar sozinho e o que ELE ja sabe:
#      data, hora, usuario, IP e MAC de cada conexao.
#  Isso e exatamente o que o Marco Civil exige (registro de conexao). O nome e
#  o CPF sao a sua camada de identificacao, e para grava-los e preciso algo
#  fora do roteador -- planilha do Google (de graca), site, ou um container.
#
#  Este arquivo entrega as duas formas de .txt que o roteador faz sozinho.
# =============================================================================


# =============================================================================
#  FORMA 1 - Log do hotspot em arquivo  (RECOMENDADA)
# =============================================================================
#  Nativo, aguenta volume, roda em qualquer modelo e faz rotacao sozinho.
#  Gera arquivos  cadastros-wifi.0.txt, .1.txt ...  la em Files.

/system logging action
add name=txt-wifi target=disk disk-file-name=cadastros-wifi \
    disk-lines-per-file=20000 disk-file-count=10 disk-stop-on-full=no

/system logging
add topics=hotspot,info action=txt-wifi

#  Cada login vira uma linha assim:
#    hotspot,info,account 10.10.0.55 (AA:BB:CC:11:22:33): logged in
#
#  Ver sem baixar o arquivo:
#    /log print where topics~"hotspot"
#  Baixar: Files > cadastros-wifi.0.txt > arrastar para o computador.


# =============================================================================
#  FORMA 2 - Um .txt limpo, uma linha por acesso
# =============================================================================
#  Escreve so o que interessa, separado por ponto e virgula, pronto para abrir
#  no Excel. Roda a cada login, pelo on-login do perfil de usuario.
#
#  CUIDADO COM O TAMANHO: o RouterOS reescreve o arquivo inteiro a cada linha
#  nova. Passando de umas poucas milhares de linhas isso fica lento e pode
#  falhar. Use a FORMA 1 se o predio for grande; esta aqui e para volume baixo
#  ou para quem quer o arquivo mastigado.

# --- 2a) Script que grava a linha -------------------------------------------
/system script
add name=wifi-registrar policy=read,write,test source={
    :local arq "acessos-wifi.txt"
    :local data [/system clock get date]
    :local hora [/system clock get time]
    :local linha ($data . ";" . $hora . ";" . $user . ";" . $address . ";" . $"mac-address")

    # cria o arquivo na primeira vez
    :if ([:len [/file find name=$arq]] = 0) do={
        /file add name=$arq contents=("data;hora;usuario;ip;mac\r\n")
        :delay 1s
    }

    # limite de seguranca: para de escrever perto de 500 KB
    :local atual [/file get [find name=$arq] size]
    :if ($atual > 500000) do={
        :log warning "wifi-registrar: acessos-wifi.txt cheio, renomeie o arquivo"
    } else={
        :local conteudo [/file get [find name=$arq] contents]
        /file set [find name=$arq] contents=($conteudo . $linha . "\r\n")
    }
}

# --- 2b) Chama o script a cada login ----------------------------------------
#  Troque "moradores" pelo nome do seu perfil de usuario do hotspot.
/ip hotspot user profile
set [find name="moradores"] on-login="/system script run wifi-registrar"

# --- 2c) Conferir ------------------------------------------------------------
# /file print where name~"acessos-wifi"
# /system script run wifi-registrar        (testa na mao)
# /log print where message~"wifi-registrar"


# =============================================================================
#  GUARDAR O ARQUIVO FORA DO ROTEADOR
# =============================================================================
#  A memoria do MikroTik e pequena e some se voce der reset. Vale mandar o log
#  para fora. Duas opcoes:

# --- Syslog em outra maquina (a mais robusta) -------------------------------
# /system logging action add name=syslog target=remote remote=192.168.88.50 remote-port=514
# /system logging add topics=hotspot,info action=syslog

# --- Copia por FTP uma vez por dia ------------------------------------------
# /system scheduler
# add name=backup-log interval=1d on-event={
#     /tool fetch address=192.168.88.50 src-path=cadastros-wifi.0.txt \
#         user=backup password=senha upload=yes mode=ftp
# }


# =============================================================================
#  RESUMINDO
# =============================================================================
#  No .txt do roteador voce tera:  data, hora, usuario, IP e MAC.
#  Para ter tambem NOME, CPF, BLOCO e APARTAMENTO num arquivo, o cadastro
#  precisa sair do roteador. A opcao gratuita e a planilha do Google --
#  webhook/google-apps-script.js no repositorio.
