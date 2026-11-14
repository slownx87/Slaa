package com.vnsystem.app;

import java.util.Locale;

/** Endereços e ajustes fixos do app. */
final class Config {
    private Config() {}

    /** Domínio do site. Tudo que for deste domínio abre dentro do app; o resto abre no navegador/aplicativo certo. */
    static final String HOST = "vnsystemltda.com";
    static final String BASE = "https://" + HOST + "/";
    /** Primeira tela: o próprio site decide para onde mandar (painel do administrador, área do funcionário, painel do cliente). */
    static final String START_URL = BASE + "login.php";
    /** Marca no final do User-Agent: o servidor e o JavaScript sabem que é o app. */
    static final String UA_SUFFIX = " VNSystemApp/2.0";
    /** Versão do app (também no AndroidManifest/build.sh). */
    static final String VERSION = "2.0.0";

    /** O host é do site (vnsystemltda.com, www.vnsystemltda.com ou subdomínio)? */
    static boolean isSiteHost(String host) {
        if (host == null) return false;
        String h = host.toLowerCase(Locale.ROOT);
        return h.equals(HOST) || h.endsWith("." + HOST);
    }
}
