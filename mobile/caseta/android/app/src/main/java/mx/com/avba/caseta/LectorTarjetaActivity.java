package mx.com.avba.caseta;

import android.Manifest;
import android.annotation.SuppressLint;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.graphics.Canvas;
import android.graphics.Color;
import android.graphics.Paint;
import android.graphics.Rect;
import android.graphics.RectF;
import android.graphics.drawable.GradientDrawable;
import android.os.Build;
import android.os.Bundle;
import android.os.SystemClock;
import android.text.InputType;
import android.text.TextUtils;
import android.util.Size;
import android.util.TypedValue;
import android.view.Gravity;
import android.view.HapticFeedbackConstants;
import android.view.MotionEvent;
import android.view.View;
import android.view.ViewGroup;
import android.view.WindowManager;
import android.widget.Button;
import android.widget.EditText;
import android.widget.FrameLayout;
import android.widget.LinearLayout;
import android.widget.TextView;

import androidx.annotation.NonNull;
import androidx.annotation.OptIn;
import androidx.appcompat.app.AlertDialog;
import androidx.appcompat.app.AppCompatActivity;
import androidx.camera.core.Camera;
import androidx.camera.core.CameraSelector;
import androidx.camera.core.ExperimentalGetImage;
import androidx.camera.core.FocusMeteringAction;
import androidx.camera.core.ImageAnalysis;
import androidx.camera.core.ImageProxy;
import androidx.camera.core.MeteringPoint;
import androidx.camera.core.Preview;
import androidx.camera.core.resolutionselector.ResolutionSelector;
import androidx.camera.core.resolutionselector.ResolutionStrategy;
import androidx.camera.lifecycle.ProcessCameraProvider;
import androidx.camera.view.PreviewView;
import androidx.core.content.ContextCompat;
import androidx.core.graphics.Insets;
import androidx.core.view.ViewCompat;
import androidx.core.view.WindowInsetsCompat;

import com.google.common.util.concurrent.ListenableFuture;
import com.google.mlkit.vision.common.InputImage;
import com.google.mlkit.vision.text.Text;
import com.google.mlkit.vision.text.TextRecognition;
import com.google.mlkit.vision.text.TextRecognizer;
import com.google.mlkit.vision.text.latin.TextRecognizerOptions;

import java.util.ArrayList;
import java.util.List;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.TimeUnit;

/**
 * Pantalla de la cámara que lee la tarjeta de identificación.
 *
 * TODO se hace en el teléfono: la imagen nunca sale del equipo. El texto lo
 * reconoce ML Kit con el modelo incluido en la app (funciona sin internet) y
 * AnalizadorTarjeta encuentra el nombre y el código. El código se da por bueno
 * cuando se lee igual en varias imágenes seguidas.
 *
 * Regresa a la app: codigo, nombre, texto (lo que se leyó), manual (si se
 * escribió a mano), simulado (botón de la demo) y ms (tiempo de lectura).
 */
public class LectorTarjetaActivity extends AppCompatActivity {

    public static final String EXTRA_TITULO = "titulo", EXTRA_SUBTITULO = "subtitulo", EXTRA_SIMULAR = "simular",
            EXTRA_MIN = "minDigitos", EXTRA_MAX = "maxDigitos", EXTRA_LONGITUD = "longitud", EXTRA_PATRON = "patron",
            EXTRA_CLAVES = "claves", EXTRA_NO_NOMBRE = "noNombre", EXTRA_NO_CODIGO = "noCodigo", EXTRA_CONFIRMAR = "confirmar";

    private static final int VERDE = Color.rgb(76, 175, 80), AMARILLO = Color.rgb(255, 202, 40), BLANCO = Color.WHITE;

    private PreviewView vista;
    private Marco marco;
    private TextView tEstado, tTexto;
    private Button bLinterna;
    private Camera camara;
    private boolean linterna = false;
    private ExecutorService hilo;
    private TextRecognizer lector;
    private final AnalizadorTarjeta.Reglas reglas = new AnalizadorTarjeta.Reglas();
    private AnalizadorTarjeta.Estabilizador estab;
    private volatile boolean entregado = false, pausado = false;
    private String ultimoTexto = "";
    private long inicio;
    private int lecturas = 0;

    @Override
    protected void onCreate(Bundle guardado) {
        super.onCreate(guardado);
        getWindow().addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
        inicio = SystemClock.elapsedRealtime();
        leerReglas(getIntent());
        armarPantalla();
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.CAMERA) != PackageManager.PERMISSION_GRANTED) {
            terminarConError("Sin permiso para usar la cámara");
            return;
        }
        lector = TextRecognition.getClient(TextRecognizerOptions.DEFAULT_OPTIONS);
        hilo = Executors.newSingleThreadExecutor();
        iniciarCamara();
    }

    private void leerReglas(Intent i) {
        reglas.minDigitos = Math.max(1, i.getIntExtra(EXTRA_MIN, reglas.minDigitos));
        reglas.maxDigitos = Math.max(reglas.minDigitos, i.getIntExtra(EXTRA_MAX, reglas.maxDigitos));
        reglas.longitud = Math.max(0, i.getIntExtra(EXTRA_LONGITUD, 0));
        reglas.ponerPatron(i.getStringExtra(EXTRA_PATRON));
        String[] claves = i.getStringArrayExtra(EXTRA_CLAVES);
        if (claves != null && claves.length > 0) reglas.claves.addAll(AnalizadorTarjeta.Reglas.conjunto(claves));
        String[] noNombre = i.getStringArrayExtra(EXTRA_NO_NOMBRE);
        if (noNombre != null && noNombre.length > 0) reglas.noNombre.addAll(AnalizadorTarjeta.Reglas.conjunto(noNombre));
        String[] noCodigo = i.getStringArrayExtra(EXTRA_NO_CODIGO);
        if (noCodigo != null && noCodigo.length > 0) reglas.noCodigo.addAll(AnalizadorTarjeta.Reglas.conjunto(noCodigo));
        int confirmar = Math.max(1, Math.min(6, i.getIntExtra(EXTRA_CONFIRMAR, 3)));
        estab = new AnalizadorTarjeta.Estabilizador(confirmar, Math.max(6, confirmar * 2));
    }

    /* ── Pantalla: cámara a todo lo alto, recuadro de la tarjeta, textos y botones ── */
    private int dp(float v) { return Math.round(TypedValue.applyDimension(TypedValue.COMPLEX_UNIT_DIP, v, getResources().getDisplayMetrics())); }

    @SuppressLint("ClickableViewAccessibility")
    private void armarPantalla() {
        FrameLayout raiz = new FrameLayout(this);
        raiz.setBackgroundColor(Color.BLACK);

        vista = new PreviewView(this);
        vista.setScaleType(PreviewView.ScaleType.FILL_CENTER);
        raiz.addView(vista, new FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));

        marco = new Marco(this);
        raiz.addView(marco, new FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));
        marco.setOnTouchListener((v, ev) -> {                       // tocar para enfocar
            if (ev.getAction() == MotionEvent.ACTION_UP && camara != null) {
                MeteringPoint p = vista.getMeteringPointFactory().createPoint(ev.getX(), ev.getY());
                camara.getCameraControl().startFocusAndMetering(new FocusMeteringAction.Builder(p).setAutoCancelDuration(3, TimeUnit.SECONDS).build());
            }
            return true;
        });

        // Arriba: qué hacer
        LinearLayout arriba = new LinearLayout(this);
        arriba.setOrientation(LinearLayout.VERTICAL);
        arriba.setBackgroundColor(Color.argb(150, 11, 37, 69));
        TextView tTitulo = texto(getIntent().getStringExtra(EXTRA_TITULO), 20, true, BLANCO);
        TextView tSub = texto(getIntent().getStringExtra(EXTRA_SUBTITULO), 14, false, Color.argb(225, 255, 255, 255));
        arriba.addView(tTitulo);
        arriba.addView(tSub);
        FrameLayout.LayoutParams pa = new FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT, Gravity.TOP);
        raiz.addView(arriba, pa);

        // Abajo: lo que se va leyendo y los botones
        LinearLayout abajo = new LinearLayout(this);
        abajo.setOrientation(LinearLayout.VERTICAL);
        abajo.setBackgroundColor(Color.argb(190, 11, 37, 69));
        tEstado = texto("Buscando el nombre y el código…", 17, true, BLANCO);
        tTexto = texto("", 12, false, Color.argb(190, 255, 255, 255));
        tTexto.setMaxLines(3);
        tTexto.setEllipsize(TextUtils.TruncateAt.END);
        abajo.addView(tEstado);
        abajo.addView(tTexto);

        LinearLayout fila = new LinearLayout(this);
        fila.setOrientation(LinearLayout.HORIZONTAL);
        fila.setPadding(0, dp(10), 0, 0);
        Button bCancelar = boton("Cancelar", Color.argb(60, 255, 255, 255));
        bCancelar.setOnClickListener(v -> { setResult(RESULT_CANCELED); finish(); });
        Button bEscribir = boton("Escribir código", Color.argb(60, 255, 255, 255));
        bEscribir.setOnClickListener(v -> escribirCodigo());
        bLinterna = boton("Linterna", Color.argb(60, 255, 255, 255));
        bLinterna.setVisibility(View.GONE);
        bLinterna.setOnClickListener(v -> {
            if (camara == null) return;
            linterna = !linterna;
            camara.getCameraControl().enableTorch(linterna);
            bLinterna.setText(linterna ? "Apagar luz" : "Linterna");
        });
        fila.addView(bCancelar, peso());
        fila.addView(bEscribir, peso());
        fila.addView(bLinterna, peso());
        abajo.addView(fila);
        if (getIntent().getBooleanExtra(EXTRA_SIMULAR, false)) {
            Button bSimular = boton("Simular lectura (demo)", Color.argb(40, 255, 202, 40));
            bSimular.setOnClickListener(v -> entregar(null, null, false, true));
            LinearLayout.LayoutParams ps = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(46));
            ps.topMargin = dp(8);
            abajo.addView(bSimular, ps);
        }
        raiz.addView(abajo, new FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT, Gravity.BOTTOM));

        // Márgenes para la barra de estado y la de navegación (pantalla de borde a borde)
        ViewCompat.setOnApplyWindowInsetsListener(raiz, (v, insets) -> {
            Insets b = insets.getInsets(WindowInsetsCompat.Type.systemBars() | WindowInsetsCompat.Type.displayCutout());
            arriba.setPadding(dp(18) + b.left, dp(14) + b.top, dp(18) + b.right, dp(14));
            abajo.setPadding(dp(14) + b.left, dp(14), dp(14) + b.right, dp(14) + b.bottom);
            marco.margenes(b.top + dp(96), b.bottom + dp(170));
            return insets;
        });
        setContentView(raiz);
    }

    private TextView texto(String t, float sp, boolean negrita, int color) {
        TextView v = new TextView(this);
        v.setText(t == null ? "" : t);
        v.setTextSize(TypedValue.COMPLEX_UNIT_SP, sp);
        v.setTextColor(color);
        if (negrita) v.setTypeface(v.getTypeface(), android.graphics.Typeface.BOLD);
        v.setPadding(0, dp(2), 0, dp(2));
        return v;
    }
    private Button boton(String t, int fondo) {
        Button b = new Button(this);
        b.setText(t);
        b.setAllCaps(false);
        b.setTextColor(BLANCO);
        b.setTextSize(TypedValue.COMPLEX_UNIT_SP, 14);
        GradientDrawable g = new GradientDrawable();
        g.setColor(fondo);
        g.setCornerRadius(dp(12));
        b.setBackground(g);
        b.setMinHeight(dp(46));
        return b;
    }
    private LinearLayout.LayoutParams peso() {
        LinearLayout.LayoutParams p = new LinearLayout.LayoutParams(0, dp(46), 1f);
        p.setMargins(dp(4), 0, dp(4), 0);
        return p;
    }

    /* ── Cámara ── */
    private void iniciarCamara() {
        ListenableFuture<ProcessCameraProvider> futuro = ProcessCameraProvider.getInstance(this);
        futuro.addListener(() -> {
            try {
                ProcessCameraProvider proveedor = futuro.get();
                Preview preview = new Preview.Builder().build();
                preview.setSurfaceProvider(vista.getSurfaceProvider());
                ResolutionSelector resolucion = new ResolutionSelector.Builder()
                        .setResolutionStrategy(new ResolutionStrategy(new Size(1920, 1080), ResolutionStrategy.FALLBACK_RULE_CLOSEST_HIGHER_THEN_LOWER))
                        .build();
                ImageAnalysis analisis = new ImageAnalysis.Builder()
                        .setResolutionSelector(resolucion)
                        .setBackpressureStrategy(ImageAnalysis.STRATEGY_KEEP_ONLY_LATEST)
                        .build();
                analisis.setAnalyzer(hilo, this::analizar);
                proveedor.unbindAll();
                camara = proveedor.bindToLifecycle(this, CameraSelector.DEFAULT_BACK_CAMERA, preview, analisis);
                if (camara.getCameraInfo().hasFlashUnit()) bLinterna.setVisibility(View.VISIBLE);
            } catch (Exception e) {
                terminarConError("No se pudo abrir la cámara: " + e.getMessage());
            }
        }, ContextCompat.getMainExecutor(this));
    }

    @OptIn(markerClass = ExperimentalGetImage.class)
    private void analizar(@NonNull ImageProxy imagen) {
        if (entregado || pausado || imagen.getImage() == null) { imagen.close(); return; }
        InputImage entrada = InputImage.fromMediaImage(imagen.getImage(), imagen.getImageInfo().getRotationDegrees());
        lector.process(entrada)
                .addOnSuccessListener(ContextCompat.getMainExecutor(this), this::alLeer)
                .addOnCompleteListener(t -> imagen.close());
    }

    private void alLeer(Text texto) {
        if (entregado || pausado || isFinishing()) return;
        lecturas++;
        List<AnalizadorTarjeta.Linea> lineas = new ArrayList<>();
        for (Text.TextBlock bloque : texto.getTextBlocks()) {
            for (Text.Line ln : bloque.getLines()) {
                Rect r = ln.getBoundingBox();
                if (r == null) continue;
                lineas.add(new AnalizadorTarjeta.Linea(ln.getText(), r.top, r.left, r.height()));
            }
        }
        if (!lineas.isEmpty()) ultimoTexto = AnalizadorTarjeta.unir(lineas);
        AnalizadorTarjeta.Lectura l = AnalizadorTarjeta.analizar(lineas, reglas);
        AnalizadorTarjeta.Lectura ok = estab.agregar(l);
        if (ok != null) { entregar(ok.codigo, ok.nombre, false, false); return; }
        // Lo que se va leyendo, para que el guardia sepa que sí está viendo la tarjeta
        if (l.codigo != null) {
            tEstado.setText("Código " + l.codigo + (l.nombre != null ? " · " + l.nombre : "") + " — confirmando…");
            tEstado.setTextColor(AMARILLO);
            marco.color(AMARILLO);
        } else if (l.nombre != null) {
            tEstado.setText(l.nombre + " — falta el código; acércala o enfoca");
            tEstado.setTextColor(BLANCO);
            marco.color(BLANCO);
        } else {
            tEstado.setText(lineas.isEmpty() ? "Buscando el nombre y el código…" : "Acerca la tarjeta y mantenla quieta");
            tEstado.setTextColor(BLANCO);
            marco.color(BLANCO);
        }
        tTexto.setText(ultimoTexto.replace("\n", " · "));
    }

    private void escribirCodigo() {
        pausado = true;
        EditText e = new EditText(this);
        e.setInputType(InputType.TYPE_CLASS_NUMBER);
        e.setHint("Código de la tarjeta");
        FrameLayout caja = new FrameLayout(this);
        caja.setPadding(dp(22), dp(6), dp(22), 0);
        caja.addView(e);
        AlertDialog d = new AlertDialog.Builder(this)
                .setTitle("Escribir el código")
                .setMessage("Si la tarjeta no se lee, escribe el número que trae.")
                .setView(caja)
                .setPositiveButton("Aceptar", (dlg, w) -> {
                    String c = e.getText().toString().replaceAll("\\D", "");
                    if (!c.isEmpty()) entregar(c, null, true, false); else pausado = false;
                })
                .setNegativeButton("Cancelar", (dlg, w) -> pausado = false)
                .setOnCancelListener(dlg -> pausado = false)
                .create();
        d.show();
        e.requestFocus();
    }

    private void entregar(String codigo, String nombre, boolean manual, boolean simulado) {
        if (entregado) return;
        entregado = true;
        Intent datos = new Intent();
        datos.putExtra("codigo", codigo == null ? "" : codigo);
        datos.putExtra("nombre", nombre == null ? "" : nombre);
        datos.putExtra("texto", ultimoTexto);
        datos.putExtra("manual", manual);
        datos.putExtra("simulado", simulado);
        datos.putExtra("lecturas", lecturas);
        datos.putExtra("ms", SystemClock.elapsedRealtime() - inicio);
        setResult(RESULT_OK, datos);
        if (simulado) { finish(); return; }
        marco.color(VERDE);
        tEstado.setTextColor(VERDE);
        tEstado.setText("Leída: " + (nombre != null && !nombre.isEmpty() ? nombre + " · " : "") + "código " + codigo);
        marco.performHapticFeedback(Build.VERSION.SDK_INT >= 30 ? HapticFeedbackConstants.CONFIRM : HapticFeedbackConstants.LONG_PRESS);
        marco.postDelayed(this::finish, 450);
    }

    private void terminarConError(String mensaje) {
        Intent datos = new Intent();
        datos.putExtra("error", mensaje);
        setResult(RESULT_CANCELED, datos);
        finish();
    }

    @Override
    protected void onDestroy() {
        super.onDestroy();
        if (lector != null) lector.close();
        if (hilo != null) hilo.shutdown();
    }

    /** Sombra alrededor de un recuadro del tamaño de una tarjeta (85.6 × 54 mm). */
    static final class Marco extends View {
        private final Paint sombra = new Paint(), borde = new Paint(Paint.ANTI_ALIAS_FLAG);
        private final RectF hueco = new RectF();
        private int arriba = 0, abajo = 0;
        Marco(android.content.Context c) {
            super(c);
            sombra.setColor(Color.argb(120, 0, 0, 0));
            borde.setStyle(Paint.Style.STROKE);
            borde.setStrokeWidth(TypedValue.applyDimension(TypedValue.COMPLEX_UNIT_DIP, 3, c.getResources().getDisplayMetrics()));
            borde.setColor(BLANCO);
        }
        void color(int c) { if (borde.getColor() != c) { borde.setColor(c); invalidate(); } }
        void margenes(int arriba, int abajo) { this.arriba = arriba; this.abajo = abajo; invalidate(); }
        @Override protected void onDraw(@NonNull Canvas c) {
            int w = getWidth(), h = getHeight();
            if (w == 0 || h == 0) return;
            float ancho = w * 0.88f, alto = ancho / 1.586f;
            float libreArriba = arriba, libreAbajo = h - abajo;
            float centro = libreAbajo > libreArriba + alto ? (libreArriba + libreAbajo) / 2f : h / 2f;
            hueco.set((w - ancho) / 2f, centro - alto / 2f, (w + ancho) / 2f, centro + alto / 2f);
            c.drawRect(0, 0, w, hueco.top, sombra);
            c.drawRect(0, hueco.bottom, w, h, sombra);
            c.drawRect(0, hueco.top, hueco.left, hueco.bottom, sombra);
            c.drawRect(hueco.right, hueco.top, w, hueco.bottom, sombra);
            float r = TypedValue.applyDimension(TypedValue.COMPLEX_UNIT_DIP, 14, getResources().getDisplayMetrics());
            c.drawRoundRect(hueco, r, r, borde);
        }
    }
}
