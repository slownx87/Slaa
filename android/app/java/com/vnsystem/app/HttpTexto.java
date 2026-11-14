package com.vnsystem.app;

import java.io.ByteArrayOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.net.HttpURLConnection;
import java.net.URL;

/** Busca um texto (JSON) do site com o login do app. Não depende de classes do Android: dá para testar na JVM. */
final class HttpTexto {
    private HttpTexto() {}

    /**
     * @return o corpo da resposta (UTF-8) quando o servidor responde 200; null para qualquer outra resposta
     *         (inclusive redirecionamento para a tela de login, que significa "não está logado").
     */
    static String buscar(String url, String cookies, String userAgent) throws IOException {
        HttpURLConnection c = (HttpURLConnection) new URL(url).openConnection();
        try {
            c.setInstanceFollowRedirects(false);
            c.setConnectTimeout(15000);
            c.setReadTimeout(20000);
            c.setRequestProperty("User-Agent", userAgent == null || userAgent.isEmpty() ? "Mozilla/5.0 (Linux; Android) VNSystemApp" : userAgent);
            c.setRequestProperty("Accept", "application/json,text/plain,*/*");
            if (cookies != null && !cookies.isEmpty()) c.setRequestProperty("Cookie", cookies);
            if (c.getResponseCode() != 200) return null;
            InputStream in = c.getInputStream();
            try {
                ByteArrayOutputStream out = new ByteArrayOutputStream();
                byte[] buf = new byte[8192];
                int n, total = 0;
                while ((n = in.read(buf)) > 0) {
                    total += n;
                    if (total > 512 * 1024) throw new IOException("resposta grande demais");
                    out.write(buf, 0, n);
                }
                return out.toString("UTF-8");
            } finally {
                in.close();
            }
        } finally {
            c.disconnect();
        }
    }
}
