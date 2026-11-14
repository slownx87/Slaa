package com.vnsystem.app;

import android.webkit.JavascriptInterface;

/**
 * Ponte entre o site (JavaScript) e o app: aparece nas páginas como window.VNApp.
 * Só faz o que o site já poderia pedir ao celular (salvar o arquivo que a própria página gerou, compartilhar,
 * imprimir, abrir um link em outro aplicativo). Só atende quando a página aberta é do site.
 */
public final class Bridge {
    private final MainActivity act;

    public Bridge(MainActivity act) {
        this.act = act;
    }

    private boolean daqui() {
        return Config.isSiteHost(UrlPolicy.hostOf(act.urlAtual()));
    }

    @JavascriptInterface public boolean ehApp() { return true; }

    @JavascriptInterface public String versao() { return Config.VERSION; }

    /** O site gerou o arquivo no navegador (ex.: PDF do orçamento) e manda o conteúdo em base64 para o app salvar e abrir. */
    @JavascriptInterface public void salvarBase64(String base64, String nome, String mime) {
        if (!daqui()) return;
        act.downloader().deBase64(base64, nome, mime);
    }

    @JavascriptInterface public void compartilharTexto(String titulo, String texto, String url) {
        if (!daqui()) return;
        act.compartilharTexto(titulo == null ? "" : titulo, texto == null ? "" : texto, url == null ? "" : url);
    }

    @JavascriptInterface public void compartilharArquivo(String base64, String nome, String mime, String titulo) {
        if (!daqui()) return;
        act.compartilharArquivo(base64, nome, mime, titulo == null ? "" : titulo);
    }

    @JavascriptInterface public void imprimir() {
        if (!daqui()) return;
        act.imprimirPagina();
    }

    @JavascriptInterface public void abrirFora(String url) {
        if (!daqui()) return;
        act.abrirForaDoApp(url);
    }

    @JavascriptInterface public void aviso(String mensagem) {
        if (!daqui()) return;
        act.mostrarAviso(mensagem == null ? "" : mensagem);
    }

    /** Botão "Tentar de novo" da tela sem internet. */
    @JavascriptInterface public void recarregar() {
        act.recarregarPagina();
    }
}
