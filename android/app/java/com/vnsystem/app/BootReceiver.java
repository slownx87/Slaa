package com.vnsystem.app;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;

/** Depois de ligar o celular (ou atualizar o app), volta a conferir os avisos. */
public final class BootReceiver extends BroadcastReceiver {
    @Override public void onReceive(Context context, Intent intent) {
        try {
            Avisos.agendarSeConfigurado(context.getApplicationContext());
        } catch (Exception e) {
            // nada a fazer
        }
    }
}
