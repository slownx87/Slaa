package com.vnsystem.app;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.app.job.JobInfo;
import android.app.job.JobScheduler;
import android.content.ComponentName;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.os.Build;
import android.webkit.CookieManager;
import android.webkit.WebSettings;

import java.net.URLEncoder;
import java.text.SimpleDateFormat;
import java.util.Date;
import java.util.HashSet;
import java.util.Locale;
import java.util.Set;

/**
 * Avisos do sistema no celular: OS nova, alterada ou excluída e cobranças chegando ao vencimento.
 * O site já tem a parte dele (app-notificacoes.php). O app confere de tempos em tempos (a cada ~15 min, mesmo fechado)
 * usando o login que ficou salvo no app. A chave dos avisos vem do site (app-config.php) só para administrador e funcionário.
 */
final class Avisos {
    private Avisos() {}

    static final int JOB_ID = 4201;
    private static final String CANAL = "avisos";
    private static final String PREFS = "vn_avisos";

    private static SharedPreferences prefs(Context ctx) {
        return ctx.getSharedPreferences(PREFS, Context.MODE_PRIVATE);
    }

    static String userAgent(Context ctx) {
        String base;
        try { base = WebSettings.getDefaultUserAgent(ctx); } catch (Exception e) { base = "Mozilla/5.0 (Linux; Android " + Build.VERSION.RELEASE + ")"; }
        return base + Config.UA_SUFFIX;
    }

    private static String cookiesDoSite() {
        try { return CookieManager.getInstance().getCookie(Config.BASE); } catch (Exception e) { return null; }
    }

    // ------------------------------------------------------------------ ligar

    /** Depois do login: pede ao site a chave dos avisos e liga a conferência periódica. Pode ser chamado da tela. */
    static void configurar(final Context ctx) {
        final Context app = ctx.getApplicationContext();
        final String cookies = cookiesDoSite();      // lido na tela principal (o CookieManager gosta disso)
        final String ua = userAgent(app);
        new Thread(new Runnable() {
            @Override public void run() {
                try {
                    String json = HttpTexto.buscar(Config.BASE + "app-config.php", cookies, ua);
                    if (json == null) return;                       // site sem app-config.php ou não está logado
                    AvisosParser.Config c = AvisosParser.lerConfig(json);
                    if (!c.ok || !c.logado) return;
                    if (c.chave.isEmpty()) {                        // o servidor não tem a chave dos avisos configurada
                        prefs(app).edit().remove("chave").apply();
                        cancelar(app);
                        return;
                    }
                    prefs(app).edit().putString("chave", c.chave).putString("cargo", c.cargo).apply();
                    agendar(app);
                } catch (Exception e) {
                    // sem avisos por enquanto; tenta de novo na próxima abertura
                }
            }
        }).start();
    }

    /** Liga a conferência periódica (se já temos a chave). */
    static void agendarSeConfigurado(Context ctx) {
        if (!prefs(ctx).getString("chave", "").isEmpty()) agendar(ctx);
    }

    static void reagendarSePossivel(Context ctx) {
        agendarSeConfigurado(ctx.getApplicationContext());
    }

    static void agendar(Context ctx) {
        try {
            JobScheduler js = (JobScheduler) ctx.getSystemService(Context.JOB_SCHEDULER_SERVICE);
            if (js == null) return;
            for (JobInfo j : js.getAllPendingJobs()) if (j.getId() == JOB_ID) return;     // já está ligado
            ComponentName cn = new ComponentName(ctx, AvisosJob.class);
            long intervalo = 15L * 60 * 1000;
            JobInfo.Builder b = new JobInfo.Builder(JOB_ID, cn).setRequiredNetworkType(JobInfo.NETWORK_TYPE_ANY);
            if (Build.VERSION.SDK_INT >= 24) b.setPeriodic(intervalo, 5L * 60 * 1000); else b.setPeriodic(intervalo);
            try {
                js.schedule(b.setPersisted(true).build());
            } catch (SecurityException e) {
                js.schedule(b.setPersisted(false).build());
            }
        } catch (Exception e) {
            // sem avisos neste aparelho
        }
    }

    static void cancelar(Context ctx) {
        try {
            JobScheduler js = (JobScheduler) ctx.getSystemService(Context.JOB_SCHEDULER_SERVICE);
            if (js != null) js.cancel(JOB_ID);
        } catch (Exception ignorar) { /* nada */ }
    }

    // ------------------------------------------------------------------ conferir (roda em segundo plano)

    /** Chamado pelo AvisosJob. Os cookies precisam ter sido lidos na thread principal. */
    static void verificar(Context ctx, String cookies) throws Exception {
        SharedPreferences p = prefs(ctx);
        String chave = p.getString("chave", "");
        if (chave.isEmpty()) return;
        int ultimo = p.getInt("ultimo_id", 0);
        String url = Config.BASE + "app-notificacoes.php?key=" + URLEncoder.encode(chave, "UTF-8") + "&after=" + ultimo;
        String json = HttpTexto.buscar(url, cookies, userAgent(ctx));
        if (json == null) return;
        AvisosParser.Resposta r = AvisosParser.lerAvisos(json);
        if (!r.ok || !r.logado) return;
        String cargo = r.cargo;
        String telaOS = "funcionario".equals(cargo) ? "iniciar-funcionario.php" : "iniciar.php";

        if (ultimo == 0) {
            // primeira vez: só marca até onde já foi, sem avisar do que já existia
            p.edit().putInt("ultimo_id", r.ultimoId).apply();
        } else {
            int maior = ultimo;
            int mostrados = 0;
            for (AvisosParser.Aviso a : r.eventos) {
                if (a.id <= ultimo) continue;
                if (a.id > maior) maior = a.id;
                if (mostrados < 8) { mostrar(ctx, 100000 + a.id, a.titulo, a.texto, telaOS); mostrados++; }
            }
            if (r.ultimoId > maior) maior = r.ultimoId;
            p.edit().putInt("ultimo_id", maior).apply();
        }

        // cobranças chegando (só administrador): cada uma avisa uma vez por dia
        if (!r.cobrancas.isEmpty()) {
            String hoje = new SimpleDateFormat("yyyy-MM-dd", Locale.US).format(new Date());
            Set<String> vistas = new HashSet<String>();
            if (hoje.equals(p.getString("cobrancas_dia", ""))) vistas.addAll(p.getStringSet("cobrancas_vistas", new HashSet<String>()));
            int mostradas = 0;
            for (AvisosParser.Aviso a : r.cobrancas) {
                String k = String.valueOf(a.id);
                if (vistas.contains(k)) continue;
                vistas.add(k);
                if (mostradas < 5) { mostrar(ctx, 200000 + a.id, a.titulo, a.texto, "cobrancas.php"); mostradas++; }
            }
            p.edit().putString("cobrancas_dia", hoje).putStringSet("cobrancas_vistas", vistas).apply();
        }
    }

    // ------------------------------------------------------------------ notificação

    private static void criarCanal(Context ctx) {
        if (Build.VERSION.SDK_INT < 26) return;
        NotificationManager nm = (NotificationManager) ctx.getSystemService(Context.NOTIFICATION_SERVICE);
        if (nm == null || nm.getNotificationChannel(CANAL) != null) return;
        NotificationChannel c = new NotificationChannel(CANAL, ctx.getString(R.string.canal_avisos), NotificationManager.IMPORTANCE_DEFAULT);
        c.setDescription("OS nova, OS alterada e cobranças chegando");
        nm.createNotificationChannel(c);
    }

    static void mostrar(Context ctx, int id, String titulo, String texto, String abrir) {
        try {
            criarCanal(ctx);
            NotificationManager nm = (NotificationManager) ctx.getSystemService(Context.NOTIFICATION_SERVICE);
            if (nm == null) return;
            Intent i = new Intent(ctx, MainActivity.class);
            i.putExtra("abrir", abrir);
            i.setFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_SINGLE_TOP);
            PendingIntent pi = PendingIntent.getActivity(ctx, id, i, PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
            Notification.Builder b = Build.VERSION.SDK_INT >= 26 ? new Notification.Builder(ctx, CANAL) : new Notification.Builder(ctx);
            b.setSmallIcon(R.drawable.ic_stat_vn)
                    .setContentTitle(titulo)
                    .setContentText(texto)
                    .setStyle(new Notification.BigTextStyle().bigText(texto))
                    .setAutoCancel(true)
                    .setColor(0xFF2563EB)
                    .setContentIntent(pi);
            if (Build.VERSION.SDK_INT < 26) b.setDefaults(Notification.DEFAULT_SOUND | Notification.DEFAULT_VIBRATE);
            nm.notify(id, b.build());
        } catch (Exception e) {
            // sem permissão de notificação ou erro do aparelho: não derruba nada
        }
    }
}
