package com.vnsystem.app;

import android.content.ContentProvider;
import android.content.ContentValues;
import android.content.Context;
import android.database.Cursor;
import android.database.MatrixCursor;
import android.net.Uri;
import android.os.ParcelFileDescriptor;
import android.provider.OpenableColumns;

import java.io.File;
import java.io.FileNotFoundException;
import java.io.IOException;

/**
 * Entrega arquivos do app para outros aplicativos (câmera grava a foto aqui; leitor de PDF e WhatsApp leem o que foi baixado).
 * Só serve arquivos que estão nas pastas do próprio app (externas, internas e de cache) e só para quem recebeu permissão.
 *   content://com.vnsystem.app.files/<raiz>/<caminho>     raiz: e = arquivos externos do app, i = internos, c = cache
 */
public final class VNFileProvider extends ContentProvider {
    static final String AUTORIDADE = "com.vnsystem.app.files";

    @Override public boolean onCreate() { return true; }

    /** Endereço (content://) de um arquivo do app. */
    static Uri uriDe(Context ctx, File arquivo) throws IOException {
        String canon = arquivo.getCanonicalPath();
        File ext = ctx.getExternalFilesDir(null);
        File[] raizes = { ext, ctx.getFilesDir(), ctx.getCacheDir() };
        String[] chaves = { "e", "i", "c" };
        for (int i = 0; i < raizes.length; i++) {
            if (raizes[i] == null) continue;
            String r = raizes[i].getCanonicalPath();
            if (canon.equals(r) || canon.startsWith(r + File.separator)) {
                String rel = canon.substring(r.length());
                if (rel.startsWith(File.separator)) rel = rel.substring(1);
                Uri.Builder b = new Uri.Builder().scheme("content").authority(AUTORIDADE).appendPath(chaves[i]);
                for (String parte : rel.split("/")) if (!parte.isEmpty()) b.appendPath(parte);
                return b.build();
            }
        }
        throw new IllegalArgumentException("Arquivo fora das pastas do app: " + arquivo);
    }

    private File arquivoDe(Uri uri) throws FileNotFoundException {
        Context ctx = getContext();
        if (ctx == null) throw new FileNotFoundException("sem contexto");
        java.util.List<String> partes = uri.getPathSegments();
        if (partes.size() < 2) throw new FileNotFoundException("endereço inválido");
        File raiz;
        switch (partes.get(0)) {
            case "e": raiz = ctx.getExternalFilesDir(null); break;
            case "i": raiz = ctx.getFilesDir(); break;
            case "c": raiz = ctx.getCacheDir(); break;
            default: throw new FileNotFoundException("raiz desconhecida");
        }
        if (raiz == null) throw new FileNotFoundException("pasta indisponível");
        File f = raiz;
        for (int i = 1; i < partes.size(); i++) f = new File(f, partes.get(i));
        try {
            String canon = f.getCanonicalPath();
            String r = raiz.getCanonicalPath();
            if (!(canon.equals(r) || canon.startsWith(r + File.separator))) throw new FileNotFoundException("fora da pasta do app");
            return new File(canon);
        } catch (IOException e) {
            throw new FileNotFoundException("caminho inválido");
        }
    }

    @Override public ParcelFileDescriptor openFile(Uri uri, String modo) throws FileNotFoundException {
        File f = arquivoDe(uri);
        int m;
        if (modo.contains("w")) {
            m = ParcelFileDescriptor.MODE_READ_WRITE | ParcelFileDescriptor.MODE_CREATE;
            if (modo.contains("t")) m |= ParcelFileDescriptor.MODE_TRUNCATE;
            if (modo.contains("a")) m |= ParcelFileDescriptor.MODE_APPEND;
            File pai = f.getParentFile();
            if (pai != null && !pai.isDirectory()) //noinspection ResultOfMethodCallIgnored
                pai.mkdirs();
        } else {
            m = ParcelFileDescriptor.MODE_READ_ONLY;
        }
        return ParcelFileDescriptor.open(f, m);
    }

    @Override public Cursor query(Uri uri, String[] colunas, String selecao, String[] args, String ordem) {
        File f;
        try { f = arquivoDe(uri); } catch (FileNotFoundException e) { return null; }
        String[] cols = colunas != null ? colunas : new String[] { OpenableColumns.DISPLAY_NAME, OpenableColumns.SIZE };
        MatrixCursor c = new MatrixCursor(cols, 1);
        Object[] linha = new Object[cols.length];
        for (int i = 0; i < cols.length; i++) {
            if (OpenableColumns.DISPLAY_NAME.equals(cols[i])) linha[i] = f.getName();
            else if (OpenableColumns.SIZE.equals(cols[i])) linha[i] = f.length();
        }
        c.addRow(linha);
        return c;
    }

    @Override public String getType(Uri uri) {
        try { return Names.mimeForName(arquivoDe(uri).getName()); } catch (FileNotFoundException e) { return "application/octet-stream"; }
    }

    @Override public Uri insert(Uri uri, ContentValues valores) { throw new UnsupportedOperationException("somente leitura"); }
    @Override public int delete(Uri uri, String selecao, String[] args) { return 0; }
    @Override public int update(Uri uri, ContentValues valores, String selecao, String[] args) { return 0; }
}
