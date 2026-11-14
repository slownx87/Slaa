package com.vnsystem.app;

import android.app.job.JobParameters;
import android.app.job.JobService;
import android.webkit.CookieManager;

/** Confere os avisos do site a cada ~15 minutos (mesmo com o app fechado). */
public final class AvisosJob extends JobService {
    @Override public boolean onStartJob(final JobParameters params) {
        String cookies = null;
        try { cookies = CookieManager.getInstance().getCookie(Config.BASE); } catch (Exception e) { /* sem login salvo */ }
        final String ck = cookies;
        new Thread(new Runnable() {
            @Override public void run() {
                try {
                    Avisos.verificar(getApplicationContext(), ck);
                } catch (Exception e) {
                    // sem internet ou site fora do ar: tenta de novo no próximo ciclo
                } finally {
                    jobFinished(params, false);
                }
            }
        }).start();
        return true;
    }

    @Override public boolean onStopJob(JobParameters params) {
        return false;
    }
}
