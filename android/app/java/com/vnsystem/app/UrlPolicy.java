package com.vnsystem.app;

import java.util.Locale;

/**
 * Decide o que fazer com cada endereço que o WebView quer abrir (sem depender de classes do Android: dá para testar na JVM).
 *  - páginas do próprio site: abrem dentro do app (http vira https);
 *  - telefone, e-mail, WhatsApp, mapa, outros sites: abrem no aplicativo certo do celular;
 *  - blob:/data:/about:/javascript: seguem com o WebView (o download trata blob e data).
 */
final class UrlPolicy {
    private UrlPolicy() {}

    enum Action { LOAD, EXTERNAL, BLOCK }

    static final class Result {
        final Action action;
        /** Endereço a usar (pode ser diferente do pedido: http -> https). */
        final String url;
        Result(Action action, String url) { this.action = action; this.url = url; }
        @Override public String toString() { return action + " " + url; }
    }

    static Result decide(String url, boolean mainFrame) {
        if (url == null || url.trim().isEmpty()) return new Result(Action.BLOCK, "");
        String u = url.trim();
        String lower = u.toLowerCase(Locale.ROOT);

        if (lower.startsWith("about:") || lower.startsWith("javascript:") || lower.startsWith("data:") || lower.startsWith("blob:")) {
            return new Result(Action.LOAD, u);
        }
        if (lower.startsWith("http://") || lower.startsWith("https://")) {
            String host = hostOf(u);
            if (Config.isSiteHost(host)) {
                if (lower.startsWith("http://")) return new Result(Action.LOAD, "https://" + u.substring("http://".length()));
                return new Result(Action.LOAD, u);
            }
            // quadro (iframe) de outro site: fica onde está; só a página principal sai do app
            return mainFrame ? new Result(Action.EXTERNAL, u) : new Result(Action.LOAD, u);
        }
        // tel:, mailto:, sms:, geo:, whatsapp:, intent:, market: ...
        return mainFrame ? new Result(Action.EXTERNAL, u) : new Result(Action.BLOCK, u);
    }

    /** Host de um endereço http/https (minúsculo, sem usuário nem porta). Vazio se não achar. */
    static String hostOf(String url) {
        int i = url.indexOf("://");
        if (i < 0) return "";
        String rest = url.substring(i + 3);
        int fim = rest.length();
        for (int k = 0; k < rest.length(); k++) {
            char c = rest.charAt(k);
            if (c == '/' || c == '?' || c == '#') { fim = k; break; }
        }
        String auth = rest.substring(0, fim);
        int at = auth.lastIndexOf('@');
        if (at >= 0) auth = auth.substring(at + 1);
        if (auth.startsWith("[")) {                 // IPv6
            int fecha = auth.indexOf(']');
            return fecha > 0 ? auth.substring(0, fecha + 1).toLowerCase(Locale.ROOT) : "";
        }
        int dp = auth.indexOf(':');
        if (dp >= 0) auth = auth.substring(0, dp);
        return auth.toLowerCase(Locale.ROOT);
    }
}
