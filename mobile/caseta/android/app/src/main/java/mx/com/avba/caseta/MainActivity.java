package mx.com.avba.caseta;

import android.os.Bundle;
import android.view.WindowManager;

import com.getcapacitor.BridgeActivity;

public class MainActivity extends BridgeActivity {
    @Override
    public void onCreate(Bundle savedInstanceState) {
        // El lector de tarjetas es parte de esta app (no es un plugin instalado aparte)
        registerPlugin(LectorTarjetaPlugin.class);
        super.onCreate(savedInstanceState);
        // En la caseta la pantalla no se apaga mientras la app está abierta
        getWindow().addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
    }
}
