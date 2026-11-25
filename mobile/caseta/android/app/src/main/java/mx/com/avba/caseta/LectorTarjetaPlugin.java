package mx.com.avba.caseta;

import android.Manifest;
import android.app.Activity;
import android.content.Intent;
import android.net.Uri;

import androidx.activity.result.ActivityResult;

import com.getcapacitor.JSArray;
import com.getcapacitor.JSObject;
import com.getcapacitor.PermissionState;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.ActivityCallback;
import com.getcapacitor.annotation.CapacitorPlugin;
import com.getcapacitor.annotation.Permission;
import com.getcapacitor.annotation.PermissionCallback;

/**
 * Lector de tarjetas de identificación para la pantalla del guardia.
 *
 * Desde la página: Capacitor.Plugins.LectorTarjeta.leer({ titulo, subtitulo,
 * simular, minDigitos, maxDigitos, longitud, patron, claves, noNombre,
 * noCodigo, confirmar }) → { codigo, nombre, texto, manual, simulado, ms } o
 * { cancelado: true }. La lectura se hace en el teléfono (LectorTarjetaActivity).
 *
 * Capacitor.Plugins.LectorTarjeta.abrir({ url }) abre una dirección https fuera
 * de la app (por ejemplo, el PDF de entradas y salidas en el visor del teléfono).
 */
@CapacitorPlugin(
    name = "LectorTarjeta",
    permissions = { @Permission(strings = { Manifest.permission.CAMERA }, alias = "camera") }
)
public class LectorTarjetaPlugin extends Plugin {

    @PluginMethod
    public void disponible(PluginCall call) {
        JSObject r = new JSObject();
        r.put("disponible", true);
        r.put("local", true);
        call.resolve(r);
    }

    @PluginMethod
    public void abrir(PluginCall call) {
        String url = call.getString("url", "");
        if (url == null || !url.matches("^https://[^\\s]+$")) { call.reject("Dirección no válida."); return; }
        try {
            Intent i = new Intent(Intent.ACTION_VIEW, Uri.parse(url));
            i.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
            getContext().startActivity(i);
            call.resolve();
        } catch (Exception e) {
            call.reject("No hay una app para abrirlo en este teléfono.");
        }
    }

    @PluginMethod
    public void leer(PluginCall call) {
        if (getPermissionState("camera") != PermissionState.GRANTED) {
            requestPermissionForAlias("camera", call, "alResponderPermiso");
            return;
        }
        abrirLector(call);
    }

    @PermissionCallback
    private void alResponderPermiso(PluginCall call) {
        if (getPermissionState("camera") == PermissionState.GRANTED) abrirLector(call);
        else call.reject("Se necesita permiso de la cámara para leer la tarjeta.", "SIN_PERMISO");
    }

    private void abrirLector(PluginCall call) {
        Intent i = new Intent(getContext(), LectorTarjetaActivity.class);
        i.putExtra(LectorTarjetaActivity.EXTRA_TITULO, call.getString("titulo", "Leer la tarjeta"));
        i.putExtra(LectorTarjetaActivity.EXTRA_SUBTITULO, call.getString("subtitulo", "Acomoda la credencial dentro del recuadro. Se lee en este teléfono, sin internet."));
        i.putExtra(LectorTarjetaActivity.EXTRA_VERTICAL, !"horizontal".equalsIgnoreCase(call.getString("orientacion", "vertical")));
        i.putExtra(LectorTarjetaActivity.EXTRA_SIMULAR, Boolean.TRUE.equals(call.getBoolean("simular", false)));
        i.putExtra(LectorTarjetaActivity.EXTRA_MIN, entero(call, "minDigitos", 4));
        i.putExtra(LectorTarjetaActivity.EXTRA_MAX, entero(call, "maxDigitos", 12));
        i.putExtra(LectorTarjetaActivity.EXTRA_LONGITUD, entero(call, "longitud", 0));
        i.putExtra(LectorTarjetaActivity.EXTRA_CONFIRMAR, entero(call, "confirmar", 3));
        String patron = call.getString("patron", "");
        if (patron != null && !patron.isEmpty()) i.putExtra(LectorTarjetaActivity.EXTRA_PATRON, patron);
        i.putExtra(LectorTarjetaActivity.EXTRA_CLAVES, textos(call.getArray("claves")));
        i.putExtra(LectorTarjetaActivity.EXTRA_NO_NOMBRE, textos(call.getArray("noNombre")));
        i.putExtra(LectorTarjetaActivity.EXTRA_NO_CODIGO, textos(call.getArray("noCodigo")));
        startActivityForResult(call, i, "alTerminar");
    }

    @ActivityCallback
    private void alTerminar(PluginCall call, ActivityResult resultado) {
        if (call == null) return;
        JSObject r = new JSObject();
        Intent d = resultado.getData();
        if (resultado.getResultCode() == Activity.RESULT_OK && d != null) {
            r.put("codigo", texto(d.getStringExtra("codigo")));
            r.put("nombre", texto(d.getStringExtra("nombre")));
            r.put("texto", texto(d.getStringExtra("texto")));
            r.put("manual", d.getBooleanExtra("manual", false));
            r.put("simulado", d.getBooleanExtra("simulado", false));
            r.put("lecturas", d.getIntExtra("lecturas", 0));
            r.put("ms", d.getLongExtra("ms", 0));
            r.put("local", true);
        } else {
            r.put("cancelado", true);
            if (d != null && d.getStringExtra("error") != null) r.put("error", d.getStringExtra("error"));
        }
        call.resolve(r);
    }

    private static String texto(String s) { return s == null ? "" : s; }

    private static int entero(PluginCall call, String clave, int omision) {
        Object v = call.getData().opt(clave);
        if (v instanceof Number) return ((Number) v).intValue();
        if (v instanceof String) { try { return Integer.parseInt(((String) v).trim()); } catch (NumberFormatException e) { return omision; } }
        return omision;
    }

    private static String[] textos(JSArray a) {
        if (a == null) return new String[0];
        String[] r = new String[a.length()];
        for (int i = 0; i < a.length(); i++) r[i] = a.optString(i, "");
        return r;
    }
}
