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

    /** Esquema de um endereço, em minúsculas ("google.navigation:q=Rua" -> "google.navigation"). Vazio se não tiver. */
    static String esquemaDe(String url) {
        if (url == null) return "";
        int i = url.indexOf(':');
        if (i <= 0) return "";
        return url.substring(0, i).trim().toLowerCase(Locale.ROOT);
    }

    /**
     * Esquemas que o app entrega a outro aplicativo do celular: telefone, e-mail, SMS, WhatsApp, mapas (o site abre a
     * navegação do Google Maps com google.navigation:), Play Store e links de aplicativo (intent:). O resto é recusado.
     */
    static boolean esquemaPermitido(String esquema) {
        if (esquema == null) return false;
        switch (esquema.toLowerCase(Locale.ROOT)) {
            case "http": case "https": case "tel": case "mailto": case "sms": case "smsto": case "geo":
            case "whatsapp": case "market": case "intent": case "google.navigation": case "waze":
                return true;
            default:
                return false;
        }
    }

    /**
     * Se não houver aplicativo de mapa instalado: o mesmo destino como página da web (abre no navegador).
     * "google.navigation:q=Rua%20X&mode=d" -> "https://www.google.com/maps/dir/?api=1&travelmode=driving&destination=Rua%20X".
     * Vazio se o endereço não for desse tipo.
     */
    static String alternativaWeb(String url) {
        if (url == null || !"google.navigation".equals(esquemaDe(url))) return "";
        String consulta = url.substring(url.indexOf(':') + 1);
        String destino = "";
        String modo = "driving";
        for (String par : consulta.split("&")) {
            int ig = par.indexOf('=');
            if (ig <= 0) continue;
            String nome = par.substring(0, ig).toLowerCase(Locale.ROOT);
            String valor = par.substring(ig + 1);
            if ("q".equals(nome)) destino = valor;
            else if ("mode".equals(nome)) modo = "w".equalsIgnoreCase(valor) ? "walking" : "b".equalsIgnoreCase(valor) ? "bicycling" : "driving";
        }
        if (destino.isEmpty()) return "";
        return "https://www.google.com/maps/dir/?api=1&travelmode=" + modo + "&destination=" + destino;
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
