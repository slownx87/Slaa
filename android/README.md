# VN System — aplicativo Android

O site `https://vnsystemltda.com` dentro de um aplicativo, com tudo que o site faz. É um navegador próprio (WebView) mais as partes
que o WebView sozinho não faz.

APK pronto: `dist/VNSystem-2.0.1.apk` (assinado, Android 5.0 ou mais novo).

## O que o app faz

| Função | Como |
| --- | --- |
| Abrir o sistema inteiro | WebView em `https://vnsystemltda.com/login.php`. Login fica salvo (cookie do site, 90 dias). Imagens, calendário, formulários, tudo do site. |
| **Baixar PDF e arquivos** | `DownloadListener` próprio (`MainActivity.aoBaixar`). Endereço normal: baixa com o login do app (`HttpDownload`). Arquivo gerado pelo site no navegador (`blob:`/`data:`): o JavaScript entrega em base64 (`Bridge.salvarBase64`; o `vn-download.js` do site já chama isso). Salva em `Downloads/VN System` e abre no leitor de PDF. |
| Enviar fotos e vídeos | Seletor de arquivos que respeita `multiple` e `accept`, com câmera de foto e de vídeo (`MainActivity.lancarSeletor`). |
| Câmera e localização do site | `getUserMedia` (ex.: "tirar várias fotos") e `navigator.geolocation` (check-in da OS), com os pedidos de permissão do Android. |
| WhatsApp, telefone, e-mail, mapas, rotas | Links de fora do site abrem no aplicativo certo (`UrlPolicy`). Inclui a navegação do Google Maps que o botão de mapa da OS usa (`google.navigation:`); sem o Maps instalado, abre a rota no navegador. |
| "Nova aba" | O WebView não tem abas: `target="_blank"` abre na mesma tela (o Voltar traz de volta). Links do site marcados com `data-vn-janela` abrem numa **janela por cima** da página (`assets/vn-app.js`), para não perder o que está aberto (ex.: o orçamento ao consultar a tabela de preços da serralheria). O Voltar fecha a janela. |
| Compartilhar e imprimir | `navigator.share` (texto, link e arquivo) e `window.print()` ligados ao Android (`assets/vn-app.js`). |
| Tela sem internet | `assets/offline.html` + tenta de novo sozinho quando a internet volta. |
| Botão voltar | Fecha primeiro o modal aberto do site (`<dialog open>`), depois volta de página; na primeira página pede para tocar de novo para sair. |
| Vídeo em tela cheia | `onShowCustomView`. |
| Avisos de OS nova/alterada e cobranças chegando | `AvisosJob` confere a cada ~15 min (`Avisos`). Usa `app-notificacoes.php` do site e a chave `VN_APP_TOKEN`, que o app recebe de `app-config.php` (só administrador e funcionário logados). |
| WebView antigo | Avisa (1x por semana) quando o "Android System WebView" está desatualizado (Chrome < 90), que é o que quebra ícones e layout. |

## Como gerar o APK

```
sudo apt install aapt apksigner zipalign dalvik-exchange android-framework-res openjdk-17-jdk-headless zip
./build.sh
```

Sem Gradle: `aapt2` (recursos) + `javac` (código) + `dx` (dex) + `zipalign` + `apksigner`. As classes do Android 14 (só para compilar) são
baixadas sozinhas do Maven Central na primeira vez. A chave de assinatura está em `keystore/vnsystem.jks`
(senha no `build.sh`): **guarde esse arquivo**, é ele que permite instalar versões novas por cima da antiga sem desinstalar.

## Testes

* `./test/rodar.sh http://127.0.0.1:8099` — regras de links, nomes de arquivo, leitura dos avisos e download de PDF com login contra um site de teste.
* `node test/js-test.js` — o JavaScript do app (`vn-app.js`) e o `vn-download.js` do site, num Chromium sem tela, com um dublê do app.

O que não dá para testar sem um celular/emulador: o WebView de verdade, o seletor de arquivos e a câmera. Esses trechos usam só
APIs padrão do Android e seguem os padrões conhecidos; teste no aparelho antes de distribuir.

## Pastas

```
app/AndroidManifest.xml     permissões, componentes, links do site
app/java/com/vnsystem/app/  código (MainActivity, Bridge, Downloader, HttpDownload, UrlPolicy, Names, Avisos...)
app/assets/                 vn-app.js (ponte com o site), offline.html
app/res/                    ícones, cores, tema
build.sh                    gera dist/VNSystem-<versão>.apk
test/                       testes (JVM e JavaScript)
```
