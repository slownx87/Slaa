package com.vnsystem.app;

import android.content.ActivityNotFoundException;
import android.content.ContentResolver;
import android.content.ContentValues;
import android.content.Intent;
import android.media.MediaScannerConnection;
import android.net.Uri;
import android.os.Build;
import android.os.Environment;
import android.provider.MediaStore;
import android.util.Base64;
import android.webkit.CookieManager;
import android.widget.Toast;

import java.io.File;
import java.io.FileInputStream;
import java.io.FileOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.SocketTimeoutException;
import java.net.UnknownHostException;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

/**
 * Baixa e guarda os arquivos do site (PDF de orçamento e de OS, planilhas, fotos...):
 *  - endereço normal (http/https): baixa com o login do app (o WebView sozinho não baixa nada);
 *  - blob: e data: (o site gera o PDF no próprio navegador): o JavaScript manda o conteúdo para cá em base64.
 * O arquivo vai para a pasta Downloads/VN System do celular e abre no leitor de PDF/galeria.
 */
final class Downloader {
    private final MainActivity act;
    private final ExecutorService pool = Executors.newFixedThreadPool(2);

    Downloader(MainActivity act) {
        this.act = act;
    }

    /** Pasta própria do app (não precisa de permissão). A cópia pública vai para Downloads/VN System. */
    File pasta() {
        File d = act.getExternalFilesDir(Environment.DIRECTORY_DOWNLOADS);
        if (d == null) d = new File(act.getFilesDir(), "downloads");
        if (!d.isDirectory()) //noinspection ResultOfMethodCallIgnored
            d.mkdirs();
        return d;
    }

    File pastaCompartilhar() {
        File d = new File(act.getCacheDir(), "compartilhar");
        if (!d.isDirectory()) //noinspection ResultOfMethodCallIgnored
            d.mkdirs();
        return d;
    }

    // ------------------------------------------------------------------ pedidos de download

    void deUrl(final String url, final String userAgent, final String disposicao, final String mime, final String referer) {
        aviso("Baixando…");
        pool.execute(new Runnable() {
            @Override public void run() {
                try {
                    HttpDownload.Result r = HttpDownload.baixar(url, new HttpDownload.Cookies() {
                        @Override public String para(String u) {
                            try { return CookieManager.getInstance().getCookie(u); } catch (Exception e) { return null; }
                        }
                    }, userAgent, referer, pasta(), disposicao, mime);
                    concluir(r.arquivo, r.nome, r.mime);
                } catch (Exception e) {
                    erro(mensagem(e));
                }
            }
        });
    }

    void deBase64(final String base64, final String nomeDica, final String mimeDica) {
        pool.execute(new Runnable() {
            @Override public void run() {
                try {
                    File f = gravarBase64(base64, nomeDica, mimeDica, pasta());
                    String mime = mimeDica == null || mimeDica.isEmpty() ? Names.mimeForName(f.getName()) : mimeDica;
                    concluir(f, f.getName(), mime);
                } catch (Exception e) {
                    erro(mensagem(e));
                }
            }
        });
    }

    /** Decodifica base64 (com ou sem o prefixo data:...;base64,) e guarda na pasta com um nome que não repete. */
    File gravarBase64(String base64, String nomeDica, String mimeDica, File destino) throws IOException {
        if (base64 == null || base64.isEmpty()) throw new IOException("O arquivo veio vazio.");
        String b = base64;
        int virg = b.indexOf(',');
        if (b.startsWith("data:") && virg > 0) b = b.substring(virg + 1);
        byte[] dados;
        try {
            dados = Base64.decode(b, Base64.DEFAULT);
        } catch (IllegalArgumentException e) {
            throw new IOException("O arquivo veio corrompido.");
        }
        if (dados.length == 0) throw new IOException("O arquivo veio vazio.");
        if (dados.length > HttpDownload.MAX_BYTES) throw new IOException("O arquivo é grande demais para salvar pelo app.");
        String nome = Names.clean(nomeDica);
        String ext = Names.extForMime(mimeDica);
        if (nome.isEmpty()) nome = "arquivo-" + System.currentTimeMillis();
        if (nome.lastIndexOf('.') <= 0 && !ext.isEmpty()) nome = nome + "." + ext;
        if (!destino.isDirectory() && !destino.mkdirs()) throw new IOException("Não consegui criar a pasta de downloads do app.");
        File f = Names.unique(destino, nome);
        OutputStream out = new FileOutputStream(f);
        try { out.write(dados); out.flush(); } finally { out.close(); }
        return f;
    }

    // ------------------------------------------------------------------ depois de baixar

    private void concluir(final File arquivo, final String nome, final String mime) {
        final boolean publico = publicar(arquivo, nome, mime);
        act.runOnUiThread(new Runnable() {
            @Override public void run() {
                Toast.makeText(act, publico ? "Salvo em Downloads/VN System: " + nome : "Arquivo salvo: " + nome, Toast.LENGTH_LONG).show();
                if (Names.podeAbrirSozinho(nome, mime)) abrir(arquivo, mime, nome);
            }
        });
    }

    /** Copia para a pasta pública Downloads/VN System (melhor esforço: se não der, o arquivo continua na pasta do app). */
    private boolean publicar(File arquivo, String nome, String mime) {
        try {
            if (Build.VERSION.SDK_INT >= 29) {
                ContentResolver cr = act.getContentResolver();
                ContentValues v = new ContentValues();
                v.put(MediaStore.MediaColumns.DISPLAY_NAME, nome);
                v.put(MediaStore.MediaColumns.MIME_TYPE, mime == null || mime.isEmpty() ? "application/octet-stream" : mime);
                v.put(MediaStore.MediaColumns.RELATIVE_PATH, Environment.DIRECTORY_DOWNLOADS + "/VN System");
                v.put(MediaStore.MediaColumns.IS_PENDING, 1);
                Uri uri = cr.insert(MediaStore.Downloads.EXTERNAL_CONTENT_URI, v);
                if (uri == null) return false;
                OutputStream os = null;
                try {
                    os = cr.openOutputStream(uri);
                    if (os == null) throw new IOException("sem saída");
                    copiar(arquivo, os);
                } catch (IOException e) {
                    try { cr.delete(uri, null, null); } catch (Exception ignorar) { /* nada */ }
                    return false;
                } finally {
                    if (os != null) { try { os.close(); } catch (IOException ignorar) { /* nada */ } }
                }
                ContentValues pronto = new ContentValues();
                pronto.put(MediaStore.MediaColumns.IS_PENDING, 0);
                cr.update(uri, pronto, null, null);
                return true;
            }
            if (act.checkCallingOrSelfPermission(android.Manifest.permission.WRITE_EXTERNAL_STORAGE) != android.content.pm.PackageManager.PERMISSION_GRANTED) return false;
            File dir = new File(Environment.getExternalStoragePublicDirectory(Environment.DIRECTORY_DOWNLOADS), "VN System");
            if (!dir.isDirectory() && !dir.mkdirs()) return false;
            File destino = Names.unique(dir, nome);
            OutputStream os = new FileOutputStream(destino);
            try { copiar(arquivo, os); } finally { os.close(); }
            MediaScannerConnection.scanFile(act, new String[] { destino.getAbsolutePath() }, null, null);
            return true;
        } catch (Exception e) {
            return false;
        }
    }

    private static void copiar(File origem, OutputStream saida) throws IOException {
        InputStream in = new FileInputStream(origem);
        try {
            byte[] buf = new byte[32 * 1024];
            int n;
            while ((n = in.read(buf)) > 0) saida.write(buf, 0, n);
            saida.flush();
        } finally {
            in.close();
        }
    }

    /** Abre o arquivo no aplicativo do celular (leitor de PDF, galeria, planilha...). */
    void abrir(File arquivo, String mime, String nome) {
        try {
            Uri uri = VNFileProvider.uriDe(act, arquivo);
            Intent i = new Intent(Intent.ACTION_VIEW);
            i.setDataAndType(uri, mime == null || mime.isEmpty() ? Names.mimeForName(nome) : mime);
            i.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION);
            act.startActivity(i);
        } catch (ActivityNotFoundException e) {
            Toast.makeText(act, "Não encontrei um aplicativo para abrir este arquivo. Ele está em Downloads/VN System.", Toast.LENGTH_LONG).show();
        } catch (Exception e) {
            Toast.makeText(act, "Não consegui abrir o arquivo. Ele está em Downloads/VN System.", Toast.LENGTH_LONG).show();
        }
    }

    /** Apaga downloads antigos da pasta do app (a cópia pública fica em Downloads) e arquivos de compartilhar. */
    void limparAntigos() {
        pool.execute(new Runnable() {
            @Override public void run() {
                long limite = System.currentTimeMillis() - 7L * 24 * 3600 * 1000;
                apagarMaisAntigosQue(pasta(), limite);
                apagarMaisAntigosQue(pastaCompartilhar(), limite);
                File fotos = act.getExternalFilesDir(Environment.DIRECTORY_PICTURES);
                if (fotos != null) apagarMaisAntigosQue(fotos, limite);
            }
        });
    }

    private static void apagarMaisAntigosQue(File dir, long limite) {
        File[] lista = dir.listFiles();
        if (lista == null) return;
        for (File f : lista) {
            if (f.isFile() && f.lastModified() < limite) //noinspection ResultOfMethodCallIgnored
                f.delete();
        }
    }

    // ------------------------------------------------------------------ avisos ao usuário

    private void aviso(final String texto) {
        act.runOnUiThread(new Runnable() { @Override public void run() { Toast.makeText(act, texto, Toast.LENGTH_SHORT).show(); } });
    }

    private void erro(final String texto) {
        act.runOnUiThread(new Runnable() { @Override public void run() { Toast.makeText(act, texto, Toast.LENGTH_LONG).show(); } });
    }

    static String mensagem(Exception e) {
        if (e instanceof HttpDownload.Falha) return e.getMessage();
        if (e instanceof UnknownHostException) return "Sem internet. Confira a conexão e tente de novo.";
        if (e instanceof SocketTimeoutException) return "O servidor demorou para responder. Tente de novo.";
        String m = e.getMessage();
        return m != null && !m.isEmpty() && m.length() < 140 ? m : "Não foi possível baixar o arquivo.";
    }
}
