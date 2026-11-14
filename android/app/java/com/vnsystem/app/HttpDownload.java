package com.vnsystem.app;

import java.io.File;
import java.io.FileOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.util.Locale;

/**
 * Baixa um arquivo do site como o navegador faria: com o login (cookies) e o mesmo User-Agent.
 * Não depende de classes do Android (dá para testar na JVM contra um servidor de verdade).
 */
final class HttpDownload {
    private HttpDownload() {}

    /** Entrega os cookies do site para um endereço (no app: o CookieManager do WebView). */
    interface Cookies { String para(String url); }

    static final class Result {
        final File arquivo;
        final String nome;
        final String mime;
        Result(File arquivo, String nome, String mime) { this.arquivo = arquivo; this.nome = nome; this.mime = mime; }
    }

    /** Erro com mensagem pronta para mostrar ao usuário. */
    static final class Falha extends IOException {
        final int codigo;
        Falha(String mensagem, int codigo) { super(mensagem); this.codigo = codigo; }
    }

    static final int MAX_BYTES = 200 * 1024 * 1024;

    /**
     * @param url        endereço do arquivo
     * @param cookies    de onde tirar os cookies (pode ser null)
     * @param userAgent  o mesmo do WebView
     * @param referer    página de onde veio o clique (pode ser null)
     * @param destino    pasta onde guardar
     * @param dicaDisp   Content-Disposition que o WebView já viu (usado se o servidor não mandar)
     * @param dicaMime   tipo que o WebView já viu
     */
    static Result baixar(String url, Cookies cookies, String userAgent, String referer, File destino, String dicaDisp, String dicaMime) throws IOException {
        String atual = url;
        String hostInicial = UrlPolicy.hostOf(url);
        for (int salto = 0; salto < 8; salto++) {
            HttpURLConnection c = (HttpURLConnection) new URL(atual).openConnection();
            try {
                c.setInstanceFollowRedirects(false);
                c.setConnectTimeout(20000);
                c.setReadTimeout(60000);
                c.setRequestProperty("User-Agent", userAgent == null || userAgent.isEmpty() ? "Mozilla/5.0 (Linux; Android) VNSystemApp" : userAgent);
                c.setRequestProperty("Accept", "*/*");
                c.setRequestProperty("Accept-Language", "pt-BR,pt;q=0.9,en;q=0.5");
                if (referer != null && !referer.isEmpty()) c.setRequestProperty("Referer", referer);
                // o login só vai para o próprio site (nunca para outro endereço que o servidor mande)
                if (cookies != null && UrlPolicy.hostOf(atual).equals(hostInicial)) {
                    String ck = cookies.para(atual);
                    if (ck != null && !ck.isEmpty()) c.setRequestProperty("Cookie", ck);
                }
                int codigo = c.getResponseCode();
                if (codigo == 301 || codigo == 302 || codigo == 303 || codigo == 307 || codigo == 308) {
                    String loc = c.getHeaderField("Location");
                    if (loc == null || loc.isEmpty()) throw new Falha("O servidor mandou abrir outro endereço, mas não disse qual.", codigo);
                    atual = new URL(new URL(atual), loc).toString();
                    continue;
                }
                if (codigo == 401 || codigo == 403) throw new Falha("Sem permissão para baixar este arquivo. Entre no sistema de novo e tente outra vez.", codigo);
                if (codigo == 404) throw new Falha("Arquivo não encontrado no servidor.", codigo);
                if (codigo >= 400) throw new Falha("O servidor não conseguiu entregar o arquivo (erro " + codigo + ").", codigo);

                String tipo = c.getContentType();
                String tipoLimpo = tipo == null ? "" : tipo.toLowerCase(Locale.ROOT).split(";")[0].trim();
                if (tipoLimpo.equals("text/html") || tipoLimpo.equals("application/xhtml+xml")) {
                    // pedimos um arquivo e veio uma página: quase sempre é a tela de login (sessão vencida)
                    throw new Falha("Veio uma página em vez do arquivo. Sua sessão pode ter vencido: entre de novo e tente outra vez.", codigo);
                }
                if (tipoLimpo.isEmpty() || tipoLimpo.equals("application/octet-stream")) {
                    if (dicaMime != null && !dicaMime.isEmpty() && !dicaMime.equals("application/octet-stream")) tipoLimpo = dicaMime.toLowerCase(Locale.ROOT).split(";")[0].trim();
                }
                String disp = c.getHeaderField("Content-Disposition");
                String nome = Names.guess(atual, disp != null && !disp.isEmpty() ? disp : dicaDisp, tipoLimpo);
                if (tipoLimpo.isEmpty() || tipoLimpo.equals("application/octet-stream")) tipoLimpo = Names.mimeForName(nome);

                if (!destino.isDirectory() && !destino.mkdirs()) throw new Falha("Não consegui criar a pasta de downloads do app.", 0);
                File saida = Names.unique(destino, nome);
                File parcial = new File(destino, saida.getName() + ".parte");
                long total = 0;
                InputStream in = c.getInputStream();
                OutputStream out = null;
                try {
                    out = new FileOutputStream(parcial);
                    byte[] buf = new byte[32 * 1024];
                    int n;
                    while ((n = in.read(buf)) > 0) {
                        total += n;
                        if (total > MAX_BYTES) throw new Falha("O arquivo é grande demais para baixar pelo app.", 0);
                        out.write(buf, 0, n);
                    }
                    out.flush();
                } catch (IOException e) {
                    try { if (out != null) out.close(); } catch (IOException ignorar) { /* nada */ }
                    out = null;
                    //noinspection ResultOfMethodCallIgnored
                    parcial.delete();
                    throw e;
                } finally {
                    try { in.close(); } catch (IOException ignorar) { /* nada */ }
                    if (out != null) { try { out.close(); } catch (IOException ignorar) { /* nada */ } }
                }
                if (total == 0) {
                    //noinspection ResultOfMethodCallIgnored
                    parcial.delete();
                    throw new Falha("O servidor devolveu um arquivo vazio.", codigo);
                }
                if (!parcial.renameTo(saida)) {
                    //noinspection ResultOfMethodCallIgnored
                    parcial.delete();
                    throw new Falha("Não consegui salvar o arquivo.", 0);
                }
                return new Result(saida, saida.getName(), tipoLimpo);
            } finally {
                c.disconnect();
            }
        }
        throw new Falha("Redirecionamentos demais ao baixar o arquivo.", 0);
    }
}
