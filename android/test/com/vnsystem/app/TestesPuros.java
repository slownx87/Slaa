package com.vnsystem.app;

import com.sun.net.httpserver.HttpServer;

import java.io.File;
import java.io.FileInputStream;
import java.io.IOException;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.InetSocketAddress;
import java.net.URL;
import java.nio.file.Files;
import java.util.List;
import java.util.Map;

/**
 * Testes das partes do app que não dependem do Android (rodam na JVM):
 * regras de links, nomes de arquivo, download com login, leitura dos avisos.
 *   ./android/test/rodar.sh [http://127.0.0.1:8099]   (a URL é de um site de teste com login de teste; sem ela, pula os testes de rede)
 */
public final class TestesPuros {
    static int ok = 0, falhas = 0;

    static void confere(boolean condicao, String mensagem) {
        if (condicao) { ok++; System.out.println("OK    " + mensagem); }
        else { falhas++; System.out.println("FALHA " + mensagem); }
    }

    public static void main(String[] args) throws Exception {
        urlPolicy();
        nomes();
        avisosParser();
        String base = args.length > 0 ? args[0] : System.getenv("VN_TESTE_URL");
        if (base != null && !base.isEmpty()) rede(base);
        else System.out.println("(sem URL de teste: testes de rede pulados)");
        System.out.println("\n" + ok + " ok, " + falhas + " falhas");
        System.exit(falhas == 0 ? 0 : 1);
    }

    // ------------------------------------------------------------------ links
    static void urlPolicy() {
        UrlPolicy.Result r = UrlPolicy.decide("https://vnsystemltda.com/login.php", true);
        confere(r.action == UrlPolicy.Action.LOAD && r.url.equals("https://vnsystemltda.com/login.php"), "página do site abre no app");
        r = UrlPolicy.decide("http://vnsystemltda.com/painel-admin.php?x=1", true);
        confere(r.action == UrlPolicy.Action.LOAD && r.url.equals("https://vnsystemltda.com/painel-admin.php?x=1"), "http do site vira https");
        r = UrlPolicy.decide("https://www.vnsystemltda.com/", true);
        confere(r.action == UrlPolicy.Action.LOAD, "www.vnsystemltda.com é do site");
        r = UrlPolicy.decide("https://app.vnsystemltda.com/x", true);
        confere(r.action == UrlPolicy.Action.LOAD, "subdomínio é do site");
        r = UrlPolicy.decide("https://wa.me/5511969190724?text=Ol%C3%A1", true);
        confere(r.action == UrlPolicy.Action.EXTERNAL, "WhatsApp (wa.me) abre fora do app");
        r = UrlPolicy.decide("https://wa.me/5511969190724", false);
        confere(r.action == UrlPolicy.Action.LOAD, "quadro (iframe) de outro site fica onde está");
        r = UrlPolicy.decide("https://www.google.com/maps/search/?api=1&query=Rua", true);
        confere(r.action == UrlPolicy.Action.EXTERNAL, "mapa abre fora do app");
        confere(UrlPolicy.decide("tel:+5511999990000", true).action == UrlPolicy.Action.EXTERNAL, "telefone abre o discador");
        confere(UrlPolicy.decide("mailto:a@b.com", true).action == UrlPolicy.Action.EXTERNAL, "e-mail abre o aplicativo de e-mail");
        confere(UrlPolicy.decide("whatsapp://send?phone=5511", true).action == UrlPolicy.Action.EXTERNAL, "esquema whatsapp:// abre fora");
        confere(UrlPolicy.decide("intent://scan/#Intent;scheme=zxing;end", true).action == UrlPolicy.Action.EXTERNAL, "intent:// principal vai para o tratamento seguro");
        confere(UrlPolicy.decide("intent://scan/#Intent;scheme=zxing;end", false).action == UrlPolicy.Action.BLOCK, "intent:// dentro de quadro é bloqueado");
        confere(UrlPolicy.decide("blob:https://vnsystemltda.com/1234-abcd", true).action == UrlPolicy.Action.LOAD, "blob: segue com o WebView (o download trata)");
        confere(UrlPolicy.decide("data:text/html;base64,AAA", true).action == UrlPolicy.Action.LOAD, "data: segue com o WebView");
        confere(UrlPolicy.decide("javascript:void(0)", true).action == UrlPolicy.Action.LOAD, "javascript: segue com o WebView");
        confere(UrlPolicy.decide("about:blank", true).action == UrlPolicy.Action.LOAD, "about:blank segue");
        confere(UrlPolicy.decide("https://vnsystemltda.com.golpe.com/login.php", true).action == UrlPolicy.Action.EXTERNAL, "vnsystemltda.com.golpe.com NÃO é do site");
        confere(UrlPolicy.decide("https://golpevnsystemltda.com/", true).action == UrlPolicy.Action.EXTERNAL, "golpevnsystemltda.com NÃO é do site");
        confere(UrlPolicy.decide("https://vnsystemltda.com@golpe.com/", true).action == UrlPolicy.Action.EXTERNAL, "usuario@golpe.com NÃO é do site (host é o que vem depois do @)");
        confere(UrlPolicy.decide("https://golpe.com@vnsystemltda.com/", true).action == UrlPolicy.Action.LOAD, "golpe.com@vnsystemltda.com é do site");
        confere(UrlPolicy.decide("https://VNSYSTEMLTDA.COM:443/x", true).action == UrlPolicy.Action.LOAD, "maiúsculas e porta 443 são do site");
        confere(UrlPolicy.decide("", true).action == UrlPolicy.Action.BLOCK && UrlPolicy.decide(null, true).action == UrlPolicy.Action.BLOCK, "endereço vazio é bloqueado");
        confere("vnsystemltda.com".equals(UrlPolicy.hostOf("https://vnsystemltda.com?x=1")), "host antes do ?");
        confere("[::1]".equals(UrlPolicy.hostOf("http://[::1]:8080/a")), "host IPv6");
    }

    // ------------------------------------------------------------------ nomes
    static void nomes() throws IOException {
        confere("orcamento-12.pdf".equals(Names.fromContentDisposition("attachment; filename=\"orcamento-12.pdf\"")), "Content-Disposition com aspas");
        confere("orc.pdf".equals(Names.fromContentDisposition("inline; filename=orc.pdf; size=10")), "Content-Disposition sem aspas");
        confere("Orçamento nº 12.pdf".equals(Names.fromContentDisposition("attachment; filename*=UTF-8''Or%C3%A7amento%20n%C2%BA%2012.pdf")), "Content-Disposition filename* (UTF-8)");
        confere("".equals(Names.fromContentDisposition("attachment")), "Content-Disposition sem nome");
        confere("orcamento-pdf.pdf".equals(Names.guess("https://x.com/orcamento-pdf.php?id=12", null, "application/pdf")), "script .php + PDF vira .pdf");
        confere("download.pdf".equals(Names.guess("https://x.com/download", null, "application/pdf")), "sem extensão ganha a do tipo");
        confere("relatorio.csv".equals(Names.guess("https://x.com/exportar?x=1", "attachment; filename=relatorio.csv", "text/csv")), "nome do servidor vence o do endereço");
        confere("arquivo.pdf".equals(Names.guess("https://x.com/", null, "application/pdf")), "endereço sem nome vira arquivo.pdf");
        confere("foto.jpg".equals(Names.guess(null, "attachment; filename=\"foto.jpg\"", "image/jpeg")), "sem endereço usa o nome do cabeçalho");
        confere(!Names.clean("a/b\\c:d*e?f\"g<h>i|j").matches(".*[\\\\/:*?\"<>|].*"), "limpa caracteres proibidos");
        confere(Names.clean("..oculto.txt").equals("oculto.txt"), "tira pontos do começo");
        confere(Names.clean("x".repeat(300) + ".pdf").length() <= 120 && Names.clean("x".repeat(300) + ".pdf").endsWith(".pdf"), "nome longo é cortado mantendo a extensão");
        confere("pdf".equals(Names.extForMime("application/pdf; charset=binary")), "extensão do tipo com parâmetros");
        confere("application/pdf".equals(Names.mimeForName("Orçamento.PDF")), "tipo pelo nome (maiúsculas)");
        confere(Names.podeAbrirSozinho("a.pdf", "application/pdf") && Names.podeAbrirSozinho("foto.jpg", "image/jpeg"), "PDF e foto abrem sozinhos");
        confere(!Names.podeAbrirSozinho("instalador.apk", "application/vnd.android.package-archive"), "APK nunca abre sozinho");
        confere(!Names.podeAbrirSozinho("pagina.html", "text/html") && !Names.podeAbrirSozinho("x.svg", "image/svg+xml"), "HTML e SVG não abrem sozinhos");
        File dir = Files.createTempDirectory("vnapp").toFile();
        File a = new File(dir, "a.pdf");
        confere(Names.unique(dir, "a.pdf").getName().equals("a.pdf"), "nome livre fica como está");
        Files.write(a.toPath(), new byte[] { 1 });
        confere(Names.unique(dir, "a.pdf").getName().equals("a (1).pdf"), "nome repetido ganha (1)");
        Files.write(new File(dir, "a (1).pdf").toPath(), new byte[] { 1 });
        confere(Names.unique(dir, "a.pdf").getName().equals("a (2).pdf"), "nome repetido de novo ganha (2)");
    }

    // ------------------------------------------------------------------ avisos
    static void avisosParser() throws Exception {
        String json = "{\"ok\":true,\"logado\":true,\"cargo\":\"admin\",\"ultimo_id\":57,\"eventos\":[{\"id\":56,\"tipo\":\"nova\",\"titulo\":\"Nova OS #12\",\"texto\":\"Cliente: Ana\"},"
                + "{\"id\":57,\"tipo\":\"alterada\",\"titulo\":\"OS alterada #9\",\"texto\":\"Status: Concluída · Bia\"}],"
                + "\"cobrancas\":[{\"id\":3,\"titulo\":\"Cobrança: Carlos\",\"texto\":\"Parcela 1/3 · R$ 300,00 · vence hoje\"}]}";
        AvisosParser.Resposta r = AvisosParser.lerAvisos(json);
        confere(r.ok && r.logado && "admin".equals(r.cargo) && r.ultimoId == 57, "avisos: cabeçalho lido");
        confere(r.eventos.size() == 2 && r.eventos.get(0).id == 56 && "Nova OS #12".equals(r.eventos.get(0).titulo) && "alterada".equals(r.eventos.get(1).tipo), "avisos: eventos de OS");
        confere(r.cobrancas.size() == 1 && "cobranca".equals(r.cobrancas.get(0).tipo) && r.cobrancas.get(0).texto.contains("vence hoje"), "avisos: cobranças");
        AvisosParser.Resposta vazia = AvisosParser.lerAvisos("{\"ok\":true,\"logado\":false,\"cargo\":\"\",\"ultimo_id\":0,\"eventos\":[],\"cobrancas\":[]}");
        confere(vazia.ok && !vazia.logado && vazia.eventos.isEmpty(), "avisos: sem login");
        AvisosParser.Config c = AvisosParser.lerConfig("{\"ok\":true,\"logado\":true,\"cargo\":\"funcionario\",\"chave\":\"abc\"}");
        confere(c.ok && c.logado && "abc".equals(c.chave) && "funcionario".equals(c.cargo), "config: chave lida");
        boolean erro = false;
        try { AvisosParser.lerAvisos("<html>"); } catch (Exception e) { erro = true; }
        confere(erro, "resposta que não é JSON dá erro (o app ignora)");
    }

    // ------------------------------------------------------------------ rede (site de teste com login de teste)
    static String loginDeTeste(String base, String cargo, String usuario, int id) throws IOException {
        HttpURLConnection c = (HttpURLConnection) new URL(base + "/_test_login.php?cargo=" + cargo + "&u=" + usuario + "&id=" + id).openConnection();
        c.setInstanceFollowRedirects(false);
        c.getResponseCode();
        Map<String, List<String>> h = c.getHeaderFields();
        List<String> sc = h.get("Set-Cookie");
        if (sc == null) throw new IOException("sem cookie de login");
        return sc.get(0).split(";")[0];
    }

    static void rede(String base) throws Exception {
        final String cookie = loginDeTeste(base, "admin", "slownx", 1);
        final String host = UrlPolicy.hostOf(base);
        HttpDownload.Cookies cookies = new HttpDownload.Cookies() {
            @Override public String para(String url) { return UrlPolicy.hostOf(url).equals(host) ? cookie : null; }
        };
        String ua = "Mozilla/5.0 (Linux; Android 13; wv) AppleWebKit/537.36 Chrome/120.0.0.0 Mobile Safari/537.36 VNSystemApp/2.0";
        File dir = Files.createTempDirectory("vnapp-baixar").toFile();
        String id = System.getenv().getOrDefault("VN_TESTE_ORC", "50");

        HttpDownload.Result r = HttpDownload.baixar(base + "/orcamento-pdf.php?id=" + id, cookies, ua, base + "/pedido.php?id=" + id, dir, null, null);
        byte[] cab = new byte[5];
        try (FileInputStream in = new FileInputStream(r.arquivo)) { in.read(cab); }
        confere("%PDF-".equals(new String(cab, "ISO-8859-1")), "baixou o PDF do orçamento com o login (" + r.nome + ", " + r.arquivo.length() + " bytes)");
        confere("application/pdf".equals(r.mime) && r.nome.toLowerCase().endsWith(".pdf"), "tipo e nome certos: " + r.mime + " / " + r.nome);
        HttpDownload.Result r2 = HttpDownload.baixar(base + "/orcamento-pdf.php?id=" + id, cookies, ua, null, dir, null, null);
        confere(!r2.nome.equals(r.nome), "segundo download não sobrescreve o primeiro (" + r2.nome + ")");

        String msg = null;
        try { HttpDownload.baixar(base + "/orcamento-pdf.php?id=" + id, null, ua, null, dir, null, null); } catch (HttpDownload.Falha e) { msg = e.getMessage(); }
        confere(msg != null && msg.contains("sessão"), "sem login: avisa que a sessão pode ter vencido (\"" + msg + "\")");

        msg = null;
        try { HttpDownload.baixar(base + "/nao-existe-" + System.nanoTime() + ".pdf", cookies, ua, null, dir, null, null); } catch (HttpDownload.Falha e) { msg = e.getMessage(); }
        confere(msg != null && msg.contains("não encontrado"), "arquivo que não existe: \"" + msg + "\"");

        // o login não pode vazar para outro endereço que o servidor mande abrir
        final boolean[] cookieVazou = { false };
        HttpServer outro = HttpServer.create(new InetSocketAddress("127.0.0.1", 0), 0);
        final int porta = outro.getAddress().getPort();
        outro.createContext("/", new com.sun.net.httpserver.HttpHandler() {
            @Override public void handle(com.sun.net.httpserver.HttpExchange ex) throws IOException {
                if (ex.getRequestHeaders().getFirst("Cookie") != null) cookieVazou[0] = true;
                byte[] corpo = "arquivo".getBytes("UTF-8");
                ex.getResponseHeaders().add("Content-Type", "application/pdf");
                ex.sendResponseHeaders(200, corpo.length);
                OutputStream os = ex.getResponseBody(); os.write(corpo); os.close();
            }
        });
        HttpServer origem = HttpServer.create(new InetSocketAddress("127.0.0.1", 0), 0);
        final String destinoRedir = "http://localhost:" + porta + "/arquivo.pdf";
        origem.createContext("/", new com.sun.net.httpserver.HttpHandler() {
            @Override public void handle(com.sun.net.httpserver.HttpExchange ex) throws IOException {
                ex.getResponseHeaders().add("Location", destinoRedir);
                ex.sendResponseHeaders(302, -1);
                ex.close();
            }
        });
        outro.start(); origem.start();
        try {
            final String hostOrigem = "127.0.0.1";
            HttpDownload.Result rr = HttpDownload.baixar("http://127.0.0.1:" + origem.getAddress().getPort() + "/qualquer",
                    new HttpDownload.Cookies() { @Override public String para(String url) { return "PHPSESSID=segredo"; } }, ua, null, dir, null, null);
            confere(rr.arquivo.length() > 0 && !cookieVazou[0], "o login NÃO foi enviado para outro endereço no redirecionamento (host " + hostOrigem + " -> localhost)");
        } finally {
            outro.stop(0); origem.stop(0);
        }

        // avisos e configuração do app, como o app faz
        String cfg = HttpTexto.buscar(base + "/app-config.php", cookie, ua);
        AvisosParser.Config c = AvisosParser.lerConfig(cfg);
        confere(c.ok && c.logado && "admin".equals(c.cargo) && !c.chave.isEmpty(), "app-config.php entrega a chave para o administrador logado");
        AvisosParser.Config semLogin = AvisosParser.lerConfig(HttpTexto.buscar(base + "/app-config.php", null, ua));
        confere(semLogin.ok && !semLogin.logado && semLogin.chave.isEmpty(), "app-config.php sem login não entrega chave");
        String cookieCliente = loginDeTeste(base, "cliente", "Cli", 5);
        AvisosParser.Config cli = AvisosParser.lerConfig(HttpTexto.buscar(base + "/app-config.php", cookieCliente, ua));
        confere(cli.logado && cli.chave.isEmpty(), "app-config.php não entrega a chave para cliente");
        String av = HttpTexto.buscar(base + "/app-notificacoes.php?key=" + c.chave + "&after=0", cookie, ua);
        AvisosParser.Resposta resp = AvisosParser.lerAvisos(av);
        confere(resp.ok && resp.logado, "app-notificacoes.php responde com a chave certa");
        confere(HttpTexto.buscar(base + "/app-notificacoes.php?key=errada&after=0", cookie, ua) == null, "chave errada: o app recebe null (403) e ignora");
    }
}
