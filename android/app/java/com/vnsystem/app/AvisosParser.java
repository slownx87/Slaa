package com.vnsystem.app;

import org.json.JSONArray;
import org.json.JSONException;
import org.json.JSONObject;

import java.util.ArrayList;
import java.util.List;

/**
 * Lê a resposta do site em app-notificacoes.php (eventos de OS e cobranças chegando) e app-config.php.
 * Só usa org.json: dá para testar na JVM.
 */
final class AvisosParser {
    private AvisosParser() {}

    static final class Aviso {
        final int id;
        final String tipo;     // nova | alterada | excluida | cobranca
        final String titulo;
        final String texto;
        Aviso(int id, String tipo, String titulo, String texto) { this.id = id; this.tipo = tipo; this.titulo = titulo; this.texto = texto; }
    }

    static final class Resposta {
        boolean ok;
        boolean logado;
        String cargo = "";
        int ultimoId;
        final List<Aviso> eventos = new ArrayList<Aviso>();
        final List<Aviso> cobrancas = new ArrayList<Aviso>();
    }

    static final class Config {
        boolean ok;
        boolean logado;
        String cargo = "";
        String chave = "";
    }

    static Resposta lerAvisos(String json) throws JSONException {
        JSONObject o = new JSONObject(json);
        Resposta r = new Resposta();
        r.ok = o.optBoolean("ok", false);
        r.logado = o.optBoolean("logado", false);
        r.cargo = o.optString("cargo", "");
        r.ultimoId = o.optInt("ultimo_id", 0);
        JSONArray ev = o.optJSONArray("eventos");
        if (ev != null) {
            for (int i = 0; i < ev.length(); i++) {
                JSONObject e = ev.optJSONObject(i);
                if (e == null) continue;
                r.eventos.add(new Aviso(e.optInt("id", 0), e.optString("tipo", "nova"), e.optString("titulo", "Aviso"), e.optString("texto", "")));
            }
        }
        JSONArray cb = o.optJSONArray("cobrancas");
        if (cb != null) {
            for (int i = 0; i < cb.length(); i++) {
                JSONObject e = cb.optJSONObject(i);
                if (e == null) continue;
                r.cobrancas.add(new Aviso(e.optInt("id", 0), "cobranca", e.optString("titulo", "Cobrança"), e.optString("texto", "")));
            }
        }
        return r;
    }

    static Config lerConfig(String json) throws JSONException {
        JSONObject o = new JSONObject(json);
        Config c = new Config();
        c.ok = o.optBoolean("ok", false);
        c.logado = o.optBoolean("logado", false);
        c.cargo = o.optString("cargo", "");
        c.chave = o.optString("chave", "");
        return c;
    }
}
