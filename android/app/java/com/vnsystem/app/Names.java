package com.vnsystem.app;

import java.io.File;
import java.io.UnsupportedEncodingException;
import java.net.URLDecoder;
import java.util.Locale;

/** Nome de arquivo para o que o app baixa (sem depender de classes do Android: dá para testar na JVM). */
final class Names {
    private Names() {}

    /** Tira o que o Android não aceita em nome de arquivo e limita o tamanho. */
    static String clean(String nome) {
        if (nome == null) return "";
        String n = nome.replaceAll("[\\\\/:*?\"<>|\\u0000-\\u001f]+", "_").replaceAll("\\s+", " ").trim();
        while (n.startsWith(".")) n = n.substring(1);
        if (n.length() > 120) {
            int dot = n.lastIndexOf('.');
            String ext = (dot > 0 && n.length() - dot <= 8) ? n.substring(dot) : "";
            n = n.substring(0, 120 - ext.length()) + ext;
        }
        return n.trim();
    }

    /** Nome do Content-Disposition (filename*=UTF-8''... ou filename="..."). Vazio se não tiver. */
    static String fromContentDisposition(String cd) {
        if (cd == null || cd.isEmpty()) return "";
        String low = cd.toLowerCase(Locale.ROOT);
        int i = low.indexOf("filename*=");
        if (i >= 0) {
            String v = cd.substring(i + 10).trim();
            int fim = v.indexOf(';');
            if (fim >= 0) v = v.substring(0, fim);
            v = v.trim();
            int q = v.indexOf("''");
            if (q >= 0) v = v.substring(q + 2);
            v = unquote(v);
            try { return clean(URLDecoder.decode(v.replace("+", "%2B"), "UTF-8")); } catch (UnsupportedEncodingException e) { return clean(v); } catch (IllegalArgumentException e) { return clean(v); }
        }
        i = low.indexOf("filename=");
        if (i >= 0) {
            String v = cd.substring(i + 9).trim();
            if (v.startsWith("\"")) {
                int fim = v.indexOf('"', 1);
                v = fim > 0 ? v.substring(1, fim) : v.substring(1);
            } else {
                int fim = v.indexOf(';');
                if (fim >= 0) v = v.substring(0, fim);
            }
            return clean(v.trim());
        }
        return "";
    }

    private static String unquote(String v) {
        if (v.length() >= 2 && v.startsWith("\"") && v.endsWith("\"")) return v.substring(1, v.length() - 1);
        return v;
    }

    /** Extensão (sem ponto) para um tipo de arquivo. Vazio se não conhecer. */
    static String extForMime(String mime) {
        if (mime == null) return "";
        String m = mime.toLowerCase(Locale.ROOT);
        int pv = m.indexOf(';');
        if (pv >= 0) m = m.substring(0, pv);
        m = m.trim();
        switch (m) {
            case "application/pdf": return "pdf";
            case "image/jpeg": case "image/jpg": return "jpg";
            case "image/png": return "png";
            case "image/webp": return "webp";
            case "image/gif": return "gif";
            case "image/svg+xml": return "svg";
            case "text/csv": case "application/csv": return "csv";
            case "text/plain": return "txt";
            case "application/zip": return "zip";
            case "application/json": return "json";
            case "application/vnd.ms-excel": return "xls";
            case "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet": return "xlsx";
            case "application/msword": return "doc";
            case "application/vnd.openxmlformats-officedocument.wordprocessingml.document": return "docx";
            case "video/mp4": return "mp4";
            case "video/webm": return "webm";
            case "video/quicktime": return "mov";
            case "audio/mpeg": return "mp3";
            case "audio/mp4": case "audio/aac": return "m4a";
            case "audio/webm": case "audio/ogg": return "ogg";
            default: return "";
        }
    }

    /** Tipo de arquivo pela extensão do nome (para abrir/compartilhar). application/octet-stream se não souber. */
    static String mimeForName(String nome) {
        if (nome == null) return "application/octet-stream";
        String n = nome.toLowerCase(Locale.ROOT);
        int dot = n.lastIndexOf('.');
        String ext = dot >= 0 ? n.substring(dot + 1) : "";
        switch (ext) {
            case "pdf": return "application/pdf";
            case "jpg": case "jpeg": return "image/jpeg";
            case "png": return "image/png";
            case "webp": return "image/webp";
            case "gif": return "image/gif";
            case "svg": return "image/svg+xml";
            case "csv": return "text/csv";
            case "txt": return "text/plain";
            case "zip": return "application/zip";
            case "json": return "application/json";
            case "xls": return "application/vnd.ms-excel";
            case "xlsx": return "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet";
            case "doc": return "application/msword";
            case "docx": return "application/vnd.openxmlformats-officedocument.wordprocessingml.document";
            case "mp4": return "video/mp4";
            case "webm": return "video/webm";
            case "mov": return "video/quicktime";
            case "mp3": return "audio/mpeg";
            case "m4a": return "audio/mp4";
            case "ogg": return "audio/ogg";
            default: return "application/octet-stream";
        }
    }

    /** O app pode abrir este arquivo sozinho depois de baixar? (nunca executáveis, instaladores ou páginas) */
    static boolean podeAbrirSozinho(String nome, String mime) {
        String m = mime == null ? "" : mime.toLowerCase(Locale.ROOT);
        if (m.equals("application/pdf") || m.startsWith("image/") || m.startsWith("video/") || m.startsWith("audio/")
                || m.equals("text/plain") || m.equals("text/csv") || m.contains("spreadsheetml") || m.contains("wordprocessingml")
                || m.equals("application/vnd.ms-excel") || m.equals("application/msword")) {
            return !m.equals("image/svg+xml");
        }
        String n = nome == null ? "" : nome.toLowerCase(Locale.ROOT);
        return n.endsWith(".pdf") || n.endsWith(".jpg") || n.endsWith(".jpeg") || n.endsWith(".png") || n.endsWith(".csv") || n.endsWith(".xlsx");
    }

    /** Nome final: o do servidor (Content-Disposition), senão o último pedaço do endereço, senão "arquivo"; com extensão certa. */
    static String guess(String url, String contentDisposition, String mime) {
        String nome = fromContentDisposition(contentDisposition);
        if (nome.isEmpty() && url != null) {
            String path = url;
            int q = path.indexOf('?'); if (q >= 0) path = path.substring(0, q);
            int h = path.indexOf('#'); if (h >= 0) path = path.substring(0, h);
            int barra = path.lastIndexOf('/');
            String ultimo = barra >= 0 ? path.substring(barra + 1) : path;
            try { ultimo = URLDecoder.decode(ultimo.replace("+", "%2B"), "UTF-8"); } catch (Exception e) { /* mantém */ }
            nome = clean(ultimo);
        }
        String ext = extForMime(mime);
        if (nome.isEmpty()) nome = "arquivo";
        int dot = nome.lastIndexOf('.');
        String atual = dot > 0 ? nome.substring(dot + 1).toLowerCase(Locale.ROOT) : "";
        boolean scriptDoServidor = atual.equals("php") || atual.equals("asp") || atual.equals("aspx") || atual.equals("jsp") || atual.equals("cgi") || atual.equals("py");
        if (!ext.isEmpty()) {
            if (dot <= 0) nome = nome + "." + ext;                                 // sem extensão: põe a do tipo
            else if (scriptDoServidor) nome = nome.substring(0, dot) + "." + ext;  // orcamento-pdf.php -> orcamento-pdf.pdf
        } else if (scriptDoServidor) {
            nome = nome.substring(0, dot);
        }
        return clean(nome);
    }

    /** Se já existir um arquivo com o nome, devolve "nome (1).ext", "nome (2).ext"... */
    static File unique(File dir, String nome) {
        File f = new File(dir, nome);
        if (!f.exists()) return f;
        int dot = nome.lastIndexOf('.');
        String base = dot > 0 ? nome.substring(0, dot) : nome;
        String ext = dot > 0 ? nome.substring(dot) : "";
        for (int i = 1; i < 1000; i++) {
            f = new File(dir, base + " (" + i + ")" + ext);
            if (!f.exists()) return f;
        }
        return new File(dir, base + "-" + System.currentTimeMillis() + ext);
    }
}
