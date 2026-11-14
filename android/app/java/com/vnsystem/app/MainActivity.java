package com.vnsystem.app;

import android.Manifest;
import android.app.Activity;
import android.app.AlertDialog;
import android.content.ActivityNotFoundException;
import android.content.ClipData;
import android.content.Context;
import android.content.DialogInterface;
import android.content.Intent;
import android.content.SharedPreferences;
import android.content.res.ColorStateList;
import android.content.pm.PackageManager;
import android.graphics.Bitmap;
import android.graphics.Color;
import android.net.ConnectivityManager;
import android.net.Network;
import android.net.NetworkRequest;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Environment;
import android.os.Message;
import android.os.SystemClock;
import android.print.PrintAttributes;
import android.print.PrintDocumentAdapter;
import android.print.PrintManager;
import android.provider.MediaStore;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.webkit.ConsoleMessage;
import android.webkit.CookieManager;
import android.webkit.DownloadListener;
import android.webkit.GeolocationPermissions;
import android.webkit.PermissionRequest;
import android.webkit.ValueCallback;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.Button;
import android.widget.FrameLayout;
import android.widget.LinearLayout;
import android.widget.ProgressBar;
import android.widget.TextView;
import android.widget.Toast;

import java.io.ByteArrayOutputStream;
import java.io.File;
import java.io.IOException;
import java.io.InputStream;
import java.text.SimpleDateFormat;
import java.util.ArrayList;
import java.util.Date;
import java.util.List;
import java.util.Locale;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

/**
 * Tela única do app: o site do VN System dentro de um WebView, com tudo que o site faz:
 *  - imagens, login que fica salvo, formulários, calendário, tudo do site;
 *  - baixar PDF e outros arquivos (inclusive os que o site gera no próprio navegador);
 *  - enviar fotos e vídeos (várias de uma vez, da galeria ou da câmera);
 *  - câmera ("tirar várias fotos") e localização do site;
 *  - WhatsApp, telefone, e-mail e mapas abrem no aplicativo certo;
 *  - compartilhar, imprimir, tela sem internet, botão voltar, vídeo em tela cheia.
 */
public final class MainActivity extends Activity {
    private static final int REQ_ARQUIVO = 101;
    private static final int PERM_CAMERA_ARQUIVO = 201;
    private static final int PERM_WEB = 202;
    private static final int PERM_GEO = 203;
    private static final int PERM_INICIAIS = 204;

    private static final String PREFS = "vn_app";

    private FrameLayout raiz;
    private WebView web;
    private ProgressBar barra;
    private Downloader downloader;
    private String scriptApp = "";

    private static final String OFFLINE = "file:///android_asset/offline.html";

    private volatile String urlAtual = "";
    private String urlComErro;            // endereço que não abriu (o botão "Tentar de novo" volta para ele)
    private boolean limparHistorico;      // depois de voltar da tela sem internet, a tela sem internet sai do "voltar"
    private long ultimoVoltar;

    // tela cheia (vídeo)
    private View telaCheia;
    private WebChromeClient.CustomViewCallback telaCheiaCb;

    // seleção de arquivos / câmera
    private ValueCallback<Uri[]> retornoArquivo;
    private WebChromeClient.FileChooserParams parametrosArquivo;
    private File fotoTemp;
    private File videoTemp;

    // pedidos de permissão do site (câmera do navegador, localização)
    private PermissionRequest pedidoWeb;
    private List<String> recursosWeb;
    private GeolocationPermissions.Callback geoRetorno;
    private String geoOrigem;

    private ConnectivityManager.NetworkCallback redeCb;

    // ------------------------------------------------------------------ ciclo de vida

    @Override protected void onCreate(Bundle estado) {
        super.onCreate(estado);
        downloader = new Downloader(this);
        scriptApp = lerAsset("vn-app.js");

        raiz = new FrameLayout(this);
        raiz.setBackgroundColor(0xFFF8F9FA);
        try {
            web = new WebView(this);
        } catch (RuntimeException e) {
            mostrarSemWebView();
            return;
        }
        raiz.addView(web, new FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));
        barra = new ProgressBar(this, null, android.R.attr.progressBarStyleHorizontal);
        barra.setMax(100);
        barra.setVisibility(View.GONE);
        barra.setProgressTintList(ColorStateList.valueOf(0xFF2563EB));
        barra.setProgressBackgroundTintList(ColorStateList.valueOf(0x00000000));
        raiz.addView(barra, new FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(3), Gravity.TOP));
        setContentView(raiz);

        configurarWebView();
        if (estado == null || web.restoreState(estado) == null) abrirDoIntent(getIntent());

        avisarSeWebViewAntigo();
        pedirPermissoesIniciais();
        downloader.limparAntigos();
    }

    @Override protected void onNewIntent(Intent intent) {
        super.onNewIntent(intent);
        setIntent(intent);
        if (web != null) abrirDoIntent(intent);
    }

    @Override protected void onStart() {
        super.onStart();
        registrarRede();
    }

    @Override protected void onResume() {
        super.onResume();
        if (web != null) {
            web.onResume();
            if (naTelaOffline(urlAtual) && temRede()) recarregarPagina();
        }
    }

    @Override protected void onPause() {
        if (web != null) {
            try { CookieManager.getInstance().flush(); } catch (Exception ignorar) { /* nada */ }
            web.onPause();
        }
        super.onPause();
    }

    @Override protected void onStop() {
        desregistrarRede();
        super.onStop();
    }

    @Override protected void onDestroy() {
        if (web != null) {
            web.stopLoading();
            raiz.removeView(web);
            web.destroy();
            web = null;
        }
        super.onDestroy();
    }

    @Override protected void onSaveInstanceState(Bundle saida) {
        super.onSaveInstanceState(saida);
        if (web != null) web.saveState(saida);
    }

    /** Voltar fecha primeiro a janela (modal) aberta do site, como o Esc no computador; só depois volta de página. */
    private static final String JS_FECHAR_JANELA =
            "(function(){try{var l=document.querySelectorAll('dialog[open]');if(l.length){var u=l[l.length-1];"
            + "var e=new Event('cancel',{cancelable:true});u.dispatchEvent(e);if(!e.defaultPrevented&&u.open)u.close();return 1;}}catch(x){}return 0;})()";

    @Override public void onBackPressed() {
        if (telaCheia != null) { sairDaTelaCheia(); return; }
        if (web == null) { super.onBackPressed(); return; }
        web.evaluateJavascript(JS_FECHAR_JANELA, new ValueCallback<String>() {
            @Override public void onReceiveValue(String resultado) {
                if (!"1".equals(resultado)) voltarOuSair();
            }
        });
    }

    private void voltarOuSair() {
        if (web != null && web.canGoBack()) { web.goBack(); return; }
        long agora = SystemClock.elapsedRealtime();
        if (agora - ultimoVoltar < 2000) {
            finish();
        } else {
            ultimoVoltar = agora;
            Toast.makeText(this, "Toque de novo em voltar para sair", Toast.LENGTH_SHORT).show();
        }
    }

    // ------------------------------------------------------------------ acesso de outras classes

    String urlAtual() { return urlAtual; }

    Downloader downloader() { return downloader; }

    private int dp(int valor) {
        return (int) (valor * getResources().getDisplayMetrics().density + 0.5f);
    }

    private String lerAsset(String nome) {
        InputStream in = null;
        try {
            in = getAssets().open(nome);
            ByteArrayOutputStream out = new ByteArrayOutputStream();
            byte[] buf = new byte[8192];
            int n;
            while ((n = in.read(buf)) > 0) out.write(buf, 0, n);
            return out.toString("UTF-8");
        } catch (IOException e) {
            return "";
        } finally {
            if (in != null) { try { in.close(); } catch (IOException ignorar) { /* nada */ } }
        }
    }

    // ------------------------------------------------------------------ WebView

    private void configurarWebView() {
        CookieManager cookies = CookieManager.getInstance();
        cookies.setAcceptCookie(true);
        cookies.setAcceptThirdPartyCookies(web, true);

        WebSettings s = web.getSettings();
        s.setJavaScriptEnabled(true);
        s.setDomStorageEnabled(true);
        s.setDatabaseEnabled(true);
        s.setLoadsImagesAutomatically(true);
        s.setBlockNetworkImage(false);
        s.setBlockNetworkLoads(false);
        s.setCacheMode(WebSettings.LOAD_DEFAULT);
        s.setMixedContentMode(WebSettings.MIXED_CONTENT_COMPATIBILITY_MODE);
        s.setAllowFileAccess(false);
        s.setAllowContentAccess(false);
        s.setAllowFileAccessFromFileURLs(false);
        s.setAllowUniversalAccessFromFileURLs(false);
        s.setUseWideViewPort(true);
        s.setLoadWithOverviewMode(true);
        s.setSupportZoom(true);
        s.setBuiltInZoomControls(true);
        s.setDisplayZoomControls(false);
        s.setTextZoom(100);
        s.setMediaPlaybackRequiresUserGesture(true);
        s.setGeolocationEnabled(true);
        s.setJavaScriptCanOpenWindowsAutomatically(true);
        s.setSupportMultipleWindows(false);
        s.setUserAgentString(s.getUserAgentString() + Config.UA_SUFFIX);

        web.addJavascriptInterface(new Bridge(this), "VNApp");
        web.setWebViewClient(new ClienteWeb());
        web.setWebChromeClient(new ChromeWeb());
        web.setDownloadListener(new DownloadListener() {
            @Override public void onDownloadStart(String url, String userAgent, String disposicao, String mime, long tamanho) {
                aoBaixar(url, userAgent, disposicao, mime);
            }
        });
    }

    private void abrirDoIntent(Intent intent) {
        String alvo = Config.START_URL;
        if (intent != null) {
            Uri d = intent.getData();
            if (d != null && "https".equalsIgnoreCase(d.getScheme()) && Config.isSiteHost(d.getHost())) {
                alvo = d.toString();
            }
            String relativo = intent.getStringExtra("abrir");          // vem das notificações
            if (relativo != null && !relativo.isEmpty() && !relativo.contains("://")) {
                alvo = Config.BASE + relativo.replaceFirst("^/+", "");
            }
        }
        web.loadUrl(alvo);
    }

    void recarregarPagina() {
        runOnUiThread(new Runnable() {
            @Override public void run() {
                if (web == null) return;
                limparHistorico = naTelaOffline(urlAtual);
                web.loadUrl(urlComErro != null ? urlComErro : Config.START_URL);
            }
        });
    }

    /** Algo que o WebView não sabe baixar sozinho: PDF, planilha, arquivo gerado pelo site. */
    private void aoBaixar(String url, String userAgent, String disposicao, String mime) {
        if (url == null) return;
        if (url.startsWith("blob:")) {
            baixarBlob(url, disposicao, mime);
        } else if (url.startsWith("data:")) {
            downloader.deBase64(url, Names.guess(null, disposicao, mime), mime);
        } else if (url.startsWith("http://") || url.startsWith("https://")) {
            downloader.deUrl(url, userAgent, disposicao, mime, urlAtual);
        }
    }

    /** O arquivo só existe dentro da página (blob:): pede para a própria página entregar o conteúdo em base64. */
    private void baixarBlob(String url, String disposicao, String mime) {
        String nome = Names.fromContentDisposition(disposicao);
        String js = "(function(){try{var x=new XMLHttpRequest();x.open('GET'," + aspas(url) + ",true);x.responseType='blob';"
                + "x.onload=function(){var r=new FileReader();r.onloadend=function(){var s=String(r.result||''),i=s.indexOf(',');"
                + "VNApp.salvarBase64(i>=0?s.substring(i+1):'',"+ aspas(nome) + "," + aspas(mime == null ? "" : mime) + ");};r.readAsDataURL(x.response);};"
                + "x.onerror=function(){VNApp.aviso('Não foi possível baixar o arquivo.');};x.send();}"
                + "catch(e){VNApp.aviso('Não foi possível baixar o arquivo.');}})();";
        web.evaluateJavascript(js, null);
    }

    private static String aspas(String s) {
        if (s == null) s = "";
        StringBuilder b = new StringBuilder("'");
        for (int i = 0; i < s.length(); i++) {
            char c = s.charAt(i);
            if (c == '\'' || c == '\\') b.append('\\').append(c);
            else if (c == '\n') b.append("\\n");
            else if (c == '\r') b.append("\\r");
            else if (c < 32) b.append(' ');
            else b.append(c);
        }
        return b.append('\'').toString();
    }

    private void injetarScript() {
        if (web != null && !scriptApp.isEmpty()) web.evaluateJavascript(scriptApp, null);
    }

    // ------------------------------------------------------------------ cliente do WebView (navegação, erros)

    private final class ClienteWeb extends WebViewClient {
        @Override public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
            return tratarEndereco(view, request.getUrl().toString(), request.isForMainFrame(), "GET".equalsIgnoreCase(request.getMethod()));
        }

        @SuppressWarnings("deprecation")
        @Override public boolean shouldOverrideUrlLoading(WebView view, String url) {   // Android 5 a 6
            return tratarEndereco(view, url, true, true);
        }

        @Override public void onPageStarted(WebView view, String url, Bitmap favicon) {
            super.onPageStarted(view, url, favicon);
            urlAtual = url == null ? "" : url;
            if (barra != null) { barra.setProgress(5); barra.setVisibility(View.VISIBLE); }
            injetarScript();
        }

        @Override public void onPageCommitVisible(WebView view, String url) {
            super.onPageCommitVisible(view, url);
            injetarScript();
        }

        @Override public void onPageFinished(WebView view, String url) {
            super.onPageFinished(view, url);
            if (barra != null) barra.setVisibility(View.GONE);
            urlAtual = url == null ? "" : url;
            injetarScript();
            try { CookieManager.getInstance().flush(); } catch (Exception ignorar) { /* nada */ }
            if (!naTelaOffline(url)) {
                if (limparHistorico) { limparHistorico = false; view.clearHistory(); }   // a tela sem internet não fica no "voltar"
                depoisDeCarregar(url);
            }
        }

        @Override public void onReceivedError(WebView view, WebResourceRequest request, WebResourceError erro) {
            if (!request.isForMainFrame()) return;
            CharSequence descricao = erro.getDescription();
            if (descricao != null && descricao.toString().contains("ERR_ABORTED")) return;   // clique que virou download, por exemplo
            mostrarOffline(request.getUrl().toString());
        }

        @SuppressWarnings("deprecation")
        @Override public void onReceivedError(WebView view, int codigo, String descricao, String urlFalhou) {   // Android 5
            if (Build.VERSION.SDK_INT < 23) mostrarOffline(urlFalhou);
        }

        @Override public boolean onRenderProcessGone(WebView view, android.webkit.RenderProcessGoneDetail detalhe) {
            Toast.makeText(MainActivity.this, "O navegador do app foi reiniciado.", Toast.LENGTH_LONG).show();
            recreate();
            return true;
        }
    }

    private boolean tratarEndereco(WebView view, String url, boolean principal, boolean get) {
        UrlPolicy.Result r = UrlPolicy.decide(url, principal);
        switch (r.action) {
            case LOAD:
                if (!r.url.equals(url) && get) { view.loadUrl(r.url); return true; }   // http do site -> https (envio de formulário não é refeito)
                return false;
            case EXTERNAL:
                abrirForaDoApp(url);
                return true;
            default:
                return true;
        }
    }

    /** Telefone, e-mail, WhatsApp, mapa e outros sites abrem no aplicativo certo do celular. */
    void abrirForaDoApp(final String url) {
        runOnUiThread(new Runnable() {
            @Override public void run() {
                if (url == null || url.isEmpty()) return;
                if (!UrlPolicy.esquemaPermitido(UrlPolicy.esquemaDe(url))) {
                    Toast.makeText(MainActivity.this, "Este tipo de link não pode ser aberto pelo app.", Toast.LENGTH_LONG).show();
                    return;
                }
                Intent i;
                try {
                    if (url.startsWith("intent:")) {
                        i = Intent.parseUri(url, Intent.URI_INTENT_SCHEME);
                        i.addCategory(Intent.CATEGORY_BROWSABLE);
                        i.setComponent(null);
                        i.setSelector(null);
                    } else {
                        i = new Intent(Intent.ACTION_VIEW, Uri.parse(url));
                    }
                } catch (Exception e) {
                    Toast.makeText(MainActivity.this, "Não consegui abrir este link.", Toast.LENGTH_LONG).show();
                    return;
                }
                try {
                    startActivity(i);
                } catch (ActivityNotFoundException e) {
                    if (!abrirAlternativa(url, i)) {
                        Toast.makeText(MainActivity.this, "Não encontrei um aplicativo para abrir este link.", Toast.LENGTH_LONG).show();
                    }
                } catch (Exception e) {
                    Toast.makeText(MainActivity.this, "Não consegui abrir este link.", Toast.LENGTH_LONG).show();
                }
            }
        });
    }

    /**
     * O aplicativo pedido não está instalado (ex.: Google Maps): abre o mesmo destino no navegador.
     * Link intent:// com aplicativo fixo -> tira o aplicativo e abre o endereço https; google.navigation: -> página do Maps na web.
     */
    private boolean abrirAlternativa(String url, Intent original) {
        try {
            if (url.startsWith("intent:") && original.getPackage() != null) {
                original.setPackage(null);
                startActivity(original);
                return true;
            }
            String web = UrlPolicy.alternativaWeb(url);
            if (!web.isEmpty()) {
                startActivity(new Intent(Intent.ACTION_VIEW, Uri.parse(web)));
                return true;
            }
        } catch (Exception ignorar) { /* sem alternativa */ }
        return false;
    }

    void mostrarAviso(final String texto) {
        runOnUiThread(new Runnable() {
            @Override public void run() { Toast.makeText(MainActivity.this, texto, Toast.LENGTH_LONG).show(); }
        });
    }

    // ------------------------------------------------------------------ sem internet

    private boolean naTelaOffline(String url) {
        return url != null && url.startsWith(OFFLINE);
    }

    /** A página principal não abriu (sem internet, servidor fora do ar): mostra a tela "Sem conexão" com o botão "Tentar de novo". */
    private void mostrarOffline(String urlFalhou) {
        if (web == null) return;
        if (urlFalhou != null && !urlFalhou.startsWith("file:")) urlComErro = urlFalhou;
        web.loadUrl(OFFLINE);
    }

    private boolean temRede() {
        try {
            ConnectivityManager cm = (ConnectivityManager) getSystemService(Context.CONNECTIVITY_SERVICE);
            android.net.NetworkInfo ni = cm.getActiveNetworkInfo();
            return ni != null && ni.isConnected();
        } catch (Exception e) {
            return true;
        }
    }

    private void registrarRede() {
        try {
            ConnectivityManager cm = (ConnectivityManager) getSystemService(Context.CONNECTIVITY_SERVICE);
            redeCb = new ConnectivityManager.NetworkCallback() {
                @Override public void onAvailable(Network rede) {
                    runOnUiThread(new Runnable() {
                        @Override public void run() { if (naTelaOffline(urlAtual)) recarregarPagina(); }
                    });
                }
            };
            cm.registerNetworkCallback(new NetworkRequest.Builder().addCapability(android.net.NetworkCapabilities.NET_CAPABILITY_INTERNET).build(), redeCb);
        } catch (Exception e) {
            redeCb = null;
        }
    }

    private void desregistrarRede() {
        try {
            if (redeCb != null) ((ConnectivityManager) getSystemService(Context.CONNECTIVITY_SERVICE)).unregisterNetworkCallback(redeCb);
        } catch (Exception ignorar) { /* nada */ }
        redeCb = null;
    }

    /** Sem o componente de navegador do Android, o app não abre: explica e leva à Play Store. */
    private void mostrarSemWebView() {
        LinearLayout caixa = new LinearLayout(this);
        caixa.setOrientation(LinearLayout.VERTICAL);
        caixa.setGravity(Gravity.CENTER);
        caixa.setPadding(dp(24), dp(24), dp(24), dp(24));
        TextView t = new TextView(this);
        t.setText("Não encontrei o Android System WebView neste celular. Instale ou ative esse componente na Play Store para usar o VN System.");
        t.setTextSize(16);
        t.setTextColor(Color.DKGRAY);
        caixa.addView(t);
        Button b = new Button(this);
        b.setText("Abrir na Play Store");
        b.setOnClickListener(new View.OnClickListener() {
            @Override public void onClick(View v) { abrirPlayStoreWebView(); }
        });
        caixa.addView(b);
        setContentView(caixa);
    }

    private void abrirPlayStoreWebView() {
        try {
            startActivity(new Intent(Intent.ACTION_VIEW, Uri.parse("market://details?id=com.google.android.webview")));
        } catch (Exception e) {
            abrirForaDoApp("https://play.google.com/store/apps/details?id=com.google.android.webview");
        }
    }

    // ------------------------------------------------------------------ depois que a página carregou

    private boolean avisosVerificados;

    private void depoisDeCarregar(String url) {
        if (url == null) return;
        String host = UrlPolicy.hostOf(url);
        if (!Config.isSiteHost(host)) return;
        boolean telaLogin = url.contains("/login.php");
        if (!telaLogin && !avisosVerificados) {
            avisosVerificados = true;
            Avisos.configurar(this);
        }
    }

    // ------------------------------------------------------------------ permissões

    private boolean tem(String permissao) {
        return Build.VERSION.SDK_INT < 23 || checkSelfPermission(permissao) == PackageManager.PERMISSION_GRANTED;
    }

    private void pedirPermissoesIniciais() {
        SharedPreferences p = getSharedPreferences(PREFS, MODE_PRIVATE);
        if (p.getBoolean("pediu_iniciais", false)) return;
        p.edit().putBoolean("pediu_iniciais", true).apply();
        List<String> pedir = new ArrayList<String>();
        if (Build.VERSION.SDK_INT >= 33 && !tem("android.permission.POST_NOTIFICATIONS")) pedir.add("android.permission.POST_NOTIFICATIONS");
        if (Build.VERSION.SDK_INT >= 23 && Build.VERSION.SDK_INT <= 28 && !tem(Manifest.permission.WRITE_EXTERNAL_STORAGE)) pedir.add(Manifest.permission.WRITE_EXTERNAL_STORAGE);
        if (!pedir.isEmpty() && Build.VERSION.SDK_INT >= 23) requestPermissions(pedir.toArray(new String[0]), PERM_INICIAIS);
    }

    @Override public void onRequestPermissionsResult(int codigo, String[] permissoes, int[] resultados) {
        super.onRequestPermissionsResult(codigo, permissoes, resultados);
        boolean todas = resultados.length > 0;
        for (int r : resultados) if (r != PackageManager.PERMISSION_GRANTED) todas = false;

        if (codigo == PERM_CAMERA_ARQUIVO) {
            if (retornoArquivo != null) lancarSeletor(todas);
        } else if (codigo == PERM_WEB) {
            if (pedidoWeb != null) {
                List<String> liberar = new ArrayList<String>();
                for (String recurso : recursosWeb) {
                    String perm = PermissionRequest.RESOURCE_VIDEO_CAPTURE.equals(recurso) ? Manifest.permission.CAMERA : Manifest.permission.RECORD_AUDIO;
                    if (tem(perm)) liberar.add(recurso);
                }
                if (liberar.isEmpty()) pedidoWeb.deny(); else pedidoWeb.grant(liberar.toArray(new String[0]));
                pedidoWeb = null; recursosWeb = null;
            }
        } else if (codigo == PERM_GEO) {
            if (geoRetorno != null) {
                boolean ok = tem(Manifest.permission.ACCESS_FINE_LOCATION) || tem(Manifest.permission.ACCESS_COARSE_LOCATION);
                geoRetorno.invoke(geoOrigem, ok, false);
                geoRetorno = null; geoOrigem = null;
            }
        } else if (codigo == PERM_INICIAIS) {
            if (Build.VERSION.SDK_INT >= 33) Avisos.reagendarSePossivel(this);
        }
    }

    // ------------------------------------------------------------------ cliente "chrome" (arquivos, câmera, localização, tela cheia)

    private final class ChromeWeb extends WebChromeClient {
        @Override public void onProgressChanged(WebView view, int progresso) {
            if (barra == null) return;
            barra.setProgress(progresso);
            barra.setVisibility(progresso < 100 ? View.VISIBLE : View.GONE);
        }

        @Override public boolean onShowFileChooser(WebView view, ValueCallback<Uri[]> retorno, FileChooserParams parametros) {
            if (retornoArquivo != null) { retornoArquivo.onReceiveValue(null); retornoArquivo = null; }
            retornoArquivo = retorno;
            parametrosArquivo = parametros;
            boolean camera = querCamera(parametros);
            if (camera && Build.VERSION.SDK_INT >= 23 && !tem(Manifest.permission.CAMERA)) {
                requestPermissions(new String[] { Manifest.permission.CAMERA }, PERM_CAMERA_ARQUIVO);
            } else {
                lancarSeletor(camera);
            }
            return true;
        }

        @Override public void onPermissionRequest(final PermissionRequest pedido) {
            runOnUiThread(new Runnable() {
                @Override public void run() { aoPedirPermissaoWeb(pedido); }
            });
        }

        @Override public void onPermissionRequestCanceled(PermissionRequest pedido) {
            if (pedido == pedidoWeb) { pedidoWeb = null; recursosWeb = null; }
        }

        @Override public void onGeolocationPermissionsShowPrompt(String origem, GeolocationPermissions.Callback retorno) {
            String host = UrlPolicy.hostOf(origem == null ? "" : origem);
            if (!Config.isSiteHost(host)) { retorno.invoke(origem, false, false); return; }
            if (tem(Manifest.permission.ACCESS_FINE_LOCATION) || tem(Manifest.permission.ACCESS_COARSE_LOCATION)) {
                retorno.invoke(origem, true, false);
            } else if (Build.VERSION.SDK_INT >= 23) {
                geoRetorno = retorno;
                geoOrigem = origem;
                requestPermissions(new String[] { Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION }, PERM_GEO);
            } else {
                retorno.invoke(origem, true, false);
            }
        }

        @Override public void onShowCustomView(View view, CustomViewCallback cb) {
            if (telaCheia != null) { cb.onCustomViewHidden(); return; }
            telaCheia = view;
            telaCheiaCb = cb;
            web.setVisibility(View.INVISIBLE);
            raiz.addView(view, new FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));
            getWindow().getDecorView().setSystemUiVisibility(View.SYSTEM_UI_FLAG_FULLSCREEN | View.SYSTEM_UI_FLAG_HIDE_NAVIGATION
                    | View.SYSTEM_UI_FLAG_IMMERSIVE_STICKY | View.SYSTEM_UI_FLAG_LAYOUT_STABLE);
        }

        @Override public void onHideCustomView() {
            sairDaTelaCheia();
        }

        @Override public Bitmap getDefaultVideoPoster() {
            return Bitmap.createBitmap(1, 1, Bitmap.Config.ARGB_8888);   // evita o erro de pôster nulo de alguns aparelhos
        }

        @Override public boolean onConsoleMessage(ConsoleMessage m) {
            return super.onConsoleMessage(m);
        }

        @Override public boolean onCreateWindow(WebView view, boolean dialogo, boolean gesto, Message msg) {
            return false;
        }
    }

    private void sairDaTelaCheia() {
        if (telaCheia == null) return;
        raiz.removeView(telaCheia);
        telaCheia = null;
        if (telaCheiaCb != null) { telaCheiaCb.onCustomViewHidden(); telaCheiaCb = null; }
        web.setVisibility(View.VISIBLE);
        getWindow().getDecorView().setSystemUiVisibility(View.SYSTEM_UI_FLAG_VISIBLE);
    }

    /** O site pediu a câmera/microfone do navegador (ex.: "tirar várias fotos"). */
    private void aoPedirPermissaoWeb(PermissionRequest pedido) {
        String host = pedido.getOrigin() == null ? "" : pedido.getOrigin().getHost();
        if (!Config.isSiteHost(host)) { pedido.deny(); return; }
        List<String> recursos = new ArrayList<String>();
        List<String> faltam = new ArrayList<String>();
        for (String r : pedido.getResources()) {
            if (PermissionRequest.RESOURCE_VIDEO_CAPTURE.equals(r)) {
                recursos.add(r);
                if (!tem(Manifest.permission.CAMERA)) faltam.add(Manifest.permission.CAMERA);
            } else if (PermissionRequest.RESOURCE_AUDIO_CAPTURE.equals(r)) {
                recursos.add(r);
                if (!tem(Manifest.permission.RECORD_AUDIO)) faltam.add(Manifest.permission.RECORD_AUDIO);
            }
        }
        if (recursos.isEmpty()) { pedido.deny(); return; }
        if (faltam.isEmpty() || Build.VERSION.SDK_INT < 23) { pedido.grant(recursos.toArray(new String[0])); return; }
        if (pedidoWeb != null) pedidoWeb.deny();
        pedidoWeb = pedido;
        recursosWeb = recursos;
        requestPermissions(faltam.toArray(new String[0]), PERM_WEB);
    }

    // ------------------------------------------------------------------ enviar arquivos (galeria, câmera, vídeo)

    /** A câmera entra como opção quando o campo aceita imagem/vídeo ou não filtra o tipo. */
    private boolean querCamera(WebChromeClient.FileChooserParams p) {
        boolean[] t = tipos(p);
        return t[0] || t[1];
    }

    /** [aceita imagem, aceita vídeo, filtra tipo] a partir do atributo accept do campo. */
    private boolean[] tipos(WebChromeClient.FileChooserParams p) {
        boolean imagem = false, video = false, algum = false;
        String[] aceita = p.getAcceptTypes();
        if (aceita != null) {
            for (String a : aceita) {
                if (a == null) continue;
                String t = a.trim().toLowerCase(Locale.ROOT);
                if (t.isEmpty()) continue;
                algum = true;
                if (t.startsWith("image/") || t.equals(".jpg") || t.equals(".jpeg") || t.equals(".png") || t.equals(".webp") || t.equals(".heic")) imagem = true;
                if (t.startsWith("video/") || t.equals(".mp4") || t.equals(".mov") || t.equals(".webm")) video = true;
            }
        }
        if (!algum) imagem = true;        // sem filtro: oferece a câmera de foto (como o navegador faz)
        return new boolean[] { imagem, video, algum };
    }

    private void lancarSeletor(boolean comCamera) {
        final ValueCallback<Uri[]> retorno = retornoArquivo;
        if (retorno == null) return;
        WebChromeClient.FileChooserParams p = parametrosArquivo;
        try {
            Intent conteudo;
            try {
                conteudo = p.createIntent();
            } catch (Exception e) {
                conteudo = new Intent(Intent.ACTION_GET_CONTENT);
                conteudo.addCategory(Intent.CATEGORY_OPENABLE);
                conteudo.setType("*/*");
                if (p.getMode() == WebChromeClient.FileChooserParams.MODE_OPEN_MULTIPLE) conteudo.putExtra(Intent.EXTRA_ALLOW_MULTIPLE, true);
            }

            List<Intent> cameras = new ArrayList<Intent>();
            fotoTemp = null;
            videoTemp = null;
            if (comCamera) {
                boolean[] t = tipos(p);
                if (t[0]) {
                    Intent foto = intentCamera(MediaStore.ACTION_IMAGE_CAPTURE, "foto_", ".jpg", true);
                    if (foto != null) cameras.add(foto);
                }
                if (t[1] && t[2]) {   // vídeo só quando o campo pede vídeo de verdade
                    Intent vid = intentCamera(MediaStore.ACTION_VIDEO_CAPTURE, "video_", ".mp4", false);
                    if (vid != null) cameras.add(vid);
                }
            }

            Intent escolha;
            if (p.isCaptureEnabled() && !cameras.isEmpty()) {
                escolha = cameras.get(0);
                if (cameras.size() > 1) {
                    escolha = Intent.createChooser(cameras.get(0), "Câmera");
                    escolha.putExtra(Intent.EXTRA_INITIAL_INTENTS, cameras.subList(1, cameras.size()).toArray(new Intent[0]));
                }
            } else {
                escolha = Intent.createChooser(conteudo, "Selecionar arquivo");
                if (!cameras.isEmpty()) escolha.putExtra(Intent.EXTRA_INITIAL_INTENTS, cameras.toArray(new Intent[0]));
            }
            startActivityForResult(escolha, REQ_ARQUIVO);
        } catch (Exception e) {
            retornoArquivo = null;
            retorno.onReceiveValue(null);
            Toast.makeText(this, "Não consegui abrir o seletor de arquivos.", Toast.LENGTH_LONG).show();
        }
    }

    /** Intent da câmera (foto ou vídeo) que grava o arquivo numa pasta do app, entregue pelo VNFileProvider. */
    private Intent intentCamera(String acao, String prefixo, String sufixo, boolean foto) {
        try {
            Intent i = new Intent(acao);
            if (i.resolveActivity(getPackageManager()) == null) return null;
            File dir = getExternalFilesDir(Environment.DIRECTORY_PICTURES);
            if (dir == null) dir = getCacheDir();
            if (!dir.isDirectory() && !dir.mkdirs()) return null;
            String carimbo = new SimpleDateFormat("yyyyMMdd_HHmmss", Locale.US).format(new Date());
            File arq = new File(dir, prefixo + carimbo + sufixo);
            Uri uri = VNFileProvider.uriDe(this, arq);
            i.putExtra(MediaStore.EXTRA_OUTPUT, uri);
            i.addFlags(Intent.FLAG_GRANT_WRITE_URI_PERMISSION | Intent.FLAG_GRANT_READ_URI_PERMISSION);
            i.setClipData(ClipData.newRawUri("midia", uri));
            if (foto) fotoTemp = arq; else videoTemp = arq;
            return i;
        } catch (Exception e) {
            return null;
        }
    }

    @Override protected void onActivityResult(int codigo, int resultado, Intent dados) {
        if (codigo != REQ_ARQUIVO) { super.onActivityResult(codigo, resultado, dados); return; }
        ValueCallback<Uri[]> retorno = retornoArquivo;
        retornoArquivo = null;
        Uri[] arquivos = null;
        if (retorno != null && resultado == RESULT_OK) {
            if (dados != null && (dados.getData() != null || dados.getClipData() != null)) {
                arquivos = WebChromeClient.FileChooserParams.parseResult(resultado, dados);
            } else {
                arquivos = midiaCapturada();
            }
        }
        if (retorno != null) retorno.onReceiveValue(arquivos);
        // foto/vídeo que ninguém gravou não fica sobrando na pasta
        if (fotoTemp != null && fotoTemp.exists() && fotoTemp.length() == 0) //noinspection ResultOfMethodCallIgnored
            fotoTemp.delete();
        if (videoTemp != null && videoTemp.exists() && videoTemp.length() == 0) //noinspection ResultOfMethodCallIgnored
            videoTemp.delete();
        fotoTemp = null;
        videoTemp = null;
    }

    private Uri[] midiaCapturada() {
        try {
            if (fotoTemp != null && fotoTemp.exists() && fotoTemp.length() > 0) return new Uri[] { VNFileProvider.uriDe(this, fotoTemp) };
            if (videoTemp != null && videoTemp.exists() && videoTemp.length() > 0) return new Uri[] { VNFileProvider.uriDe(this, videoTemp) };
        } catch (IOException e) {
            return null;
        }
        return null;
    }

    // ------------------------------------------------------------------ compartilhar e imprimir

    void compartilharTexto(final String titulo, final String texto, final String url) {
        runOnUiThread(new Runnable() {
            @Override public void run() {
                StringBuilder s = new StringBuilder();
                if (!texto.isEmpty()) s.append(texto);
                if (!url.isEmpty()) { if (s.length() > 0) s.append('\n'); s.append(url); }
                if (s.length() == 0 && !titulo.isEmpty()) s.append(titulo);
                Intent i = new Intent(Intent.ACTION_SEND);
                i.setType("text/plain");
                i.putExtra(Intent.EXTRA_TEXT, s.toString());
                if (!titulo.isEmpty()) i.putExtra(Intent.EXTRA_SUBJECT, titulo);
                try { startActivity(Intent.createChooser(i, "Compartilhar")); } catch (Exception e) { mostrarAviso("Não consegui compartilhar."); }
            }
        });
    }

    void compartilharArquivo(final String base64, final String nome, final String mime, final String titulo) {
        new Thread(new Runnable() {
            @Override public void run() {
                try {
                    final File f = downloader.gravarBase64(base64, nome, mime, downloader.pastaCompartilhar());
                    final String tipo = mime == null || mime.isEmpty() ? Names.mimeForName(f.getName()) : mime;
                    runOnUiThread(new Runnable() {
                        @Override public void run() {
                            try {
                                Uri uri = VNFileProvider.uriDe(MainActivity.this, f);
                                Intent i = new Intent(Intent.ACTION_SEND);
                                i.setType(tipo);
                                i.putExtra(Intent.EXTRA_STREAM, uri);
                                if (!titulo.isEmpty()) i.putExtra(Intent.EXTRA_SUBJECT, titulo);
                                i.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION);
                                i.setClipData(ClipData.newRawUri(f.getName(), uri));
                                startActivity(Intent.createChooser(i, "Compartilhar"));
                            } catch (Exception e) {
                                mostrarAviso("Não consegui compartilhar o arquivo.");
                            }
                        }
                    });
                } catch (Exception e) {
                    mostrarAviso(Downloader.mensagem(e));
                }
            }
        }).start();
    }

    void imprimirPagina() {
        runOnUiThread(new Runnable() {
            @Override public void run() {
                try {
                    PrintManager pm = (PrintManager) getSystemService(Context.PRINT_SERVICE);
                    String titulo = web.getTitle();
                    String nome = "VN System" + (titulo == null || titulo.isEmpty() ? "" : " - " + titulo);
                    PrintDocumentAdapter adaptador = web.createPrintDocumentAdapter(nome);
                    pm.print(nome, adaptador, new PrintAttributes.Builder().build());
                } catch (Exception e) {
                    mostrarAviso("Não consegui abrir a impressão.");
                }
            }
        });
    }

    // ------------------------------------------------------------------ WebView antigo

    /** WebView muito antigo (Chrome < 90) deixa o site feio ou quebrado: avisa uma vez por semana. */
    private void avisarSeWebViewAntigo() {
        try {
            Matcher m = Pattern.compile("Chrome/(\\d+)").matcher(web.getSettings().getUserAgentString());
            if (!m.find()) return;
            int versao = Integer.parseInt(m.group(1));
            if (versao >= 90) return;
            SharedPreferences p = getSharedPreferences(PREFS, MODE_PRIVATE);
            long agora = System.currentTimeMillis();
            if (agora - p.getLong("aviso_webview", 0) < 7L * 24 * 3600 * 1000) return;
            p.edit().putLong("aviso_webview", agora).apply();
            new AlertDialog.Builder(this)
                    .setTitle("Atualize o navegador do Android")
                    .setMessage("O componente 'Android System WebView' deste celular está desatualizado (versão " + versao + "). "
                            + "Com ele antigo, imagens e botões do VN System podem não aparecer direito. Atualize na Play Store.")
                    .setPositiveButton("Abrir Play Store", new DialogInterface.OnClickListener() {
                        @Override public void onClick(DialogInterface d, int q) { abrirPlayStoreWebView(); }
                    })
                    .setNegativeButton("Agora não", null)
                    .show();
        } catch (Exception ignorar) {
            // aviso é só um extra
        }
    }
}
