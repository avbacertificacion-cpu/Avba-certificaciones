package mx.com.avba.caseta;

import android.Manifest;
import android.annotation.SuppressLint;
import android.content.Intent;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;
import android.graphics.Bitmap;
import android.graphics.Canvas;
import android.graphics.Color;
import android.graphics.Matrix;
import android.graphics.Paint;
import android.graphics.Rect;
import android.graphics.RectF;
import android.graphics.drawable.GradientDrawable;
import android.media.AudioManager;
import android.media.ToneGenerator;
import android.os.Build;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.os.SystemClock;
import android.text.InputType;
import android.text.TextUtils;
import android.util.Base64;
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
import android.widget.Toast;

import androidx.activity.OnBackPressedCallback;
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

import com.getcapacitor.JSObject;
import com.google.common.util.concurrent.ListenableFuture;
import com.google.mlkit.vision.common.InputImage;
import com.google.mlkit.vision.text.Text;
import com.google.mlkit.vision.text.TextRecognition;
import com.google.mlkit.vision.text.TextRecognizer;
import com.google.mlkit.vision.text.latin.TextRecognizerOptions;

import java.io.ByteArrayOutputStream;
import java.io.File;
import java.io.FileOutputStream;
import java.lang.ref.WeakReference;
import java.util.ArrayList;
import java.util.HashMap;
import java.util.HashSet;
import java.util.List;
import java.util.Map;
import java.util.Set;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.TimeUnit;

import org.json.JSONArray;

/**
 * Pantalla de la cámara que lee la tarjeta de identificación.
 *
 * TODO se hace en el teléfono: la imagen nunca sale del equipo. El texto lo
 * reconoce ML Kit con el modelo incluido en la app (funciona sin internet) y
 * AnalizadorTarjeta encuentra el nombre y el código. El código se da por bueno
 * cuando se lee igual en varias imágenes seguidas.
 *
 * Regresa a la app: codigo, nombre, texto (lo que se leyó), manual (si se
 * escribió a mano), simulado (botón de la demo), ms (tiempo de lectura) y
 * camara (la que se usó: frontal o trasera).
 *
 * Cámara: la regla «camara» del servidor dice con cuál empieza (frontal o
 * trasera). El botón «Cambiar cámara» pasa a la otra y este teléfono la
 * recuerda mientras la regla del servidor no cambie.
 *
 * Lectura continua (equipo fijo de entrada o de salida): la pantalla no se
 * cierra. Cada credencial leída se avisa a la página (evento «lectura» del
 * plugin), que la registra y responde qué mostrar (mostrarResultado: nombre,
 * permisos, avisos) en grande sobre la cámara, con un tono. Si la página no
 * responde a tiempo, se muestra «Leída» de todos modos. La misma credencial
 * frente a la cámara no se vuelve a mandar hasta que se retire unos segundos.
 * Para salir hay que mantener presionado «Salir» 3 segundos.
 */
public class LectorTarjetaActivity extends AppCompatActivity {

    public static final String EXTRA_TITULO = "titulo", EXTRA_SUBTITULO = "subtitulo", EXTRA_SIMULAR = "simular",
            EXTRA_MIN = "minDigitos", EXTRA_MAX = "maxDigitos", EXTRA_LONGITUD = "longitud", EXTRA_PATRON = "patron",
            EXTRA_CLAVES = "claves", EXTRA_NO_NOMBRE = "noNombre", EXTRA_NO_CODIGO = "noCodigo", EXTRA_CONFIRMAR = "confirmar",
            EXTRA_VERTICAL = "vertical", EXTRA_FOTO = "foto", EXTRA_CAMARA = "camara",
            EXTRA_CONTINUO = "continuo", EXTRA_TIPO = "tipo", EXTRA_ESPERA_NOMBRE = "esperaNombre",
            EXTRA_DIGITOS_ID = "digitosId";

    /** Los IDs de la base del personal que mandó la página (el plugin los deja aquí antes de abrir el lector). */
    static volatile String[] idsDeLaBase = new String[0];

    private static final int VERDE = Color.rgb(76, 175, 80), AMARILLO = Color.rgb(255, 202, 40), BLANCO = Color.WHITE;

    private PreviewView vista;
    private Marco marco;
    private TextView tEstado, tTexto;
    private Button bLinterna, bCambiar;
    private Camera camara;
    private boolean linterna = false;

    /* Cámara frontal o trasera. La pedida es la regla del servidor; la elegida, la que el guardia
       dejó con «Cambiar cámara» en este teléfono (vale mientras la regla no cambie). */
    private ProcessCameraProvider proveedor;
    private Preview preview;
    private ImageAnalysis analisis;
    private boolean frontal = false;
    private String camaraPedida = "trasera";
    private static final String PREFS = "ca_lector", PREF_PEDIDA = "camara_pedida", PREF_ELEGIDA = "camara_elegida";
    private ExecutorService hilo;
    private TextRecognizer lector;
    private final AnalizadorTarjeta.Reglas reglas = new AnalizadorTarjeta.Reglas();
    private AnalizadorTarjeta.Estabilizador estab;
    private volatile boolean entregado = false, pausado = false;
    private String ultimoTexto = "";
    private AnalizadorTarjeta.Lectura confirmado = null;   // código ya seguro; se espera un momento por el nombre
    private long confirmadoEn = 0;
    private long esperaNombreMs = 1500;     // tras confirmar el ID, cuánto se espera por el nombre (0 con la base del personal)

    /* Lectura continua (equipo fijo) */
    private boolean continuo = false, cerradoAvisado = false;
    private String tipoContinuo = "entrada";
    private String ultimoEnviado = "";
    private long ultimoEnviadoEn = 0;
    private static final long MISMA_CREDENCIAL_MS = 6000;   // la misma credencial frente a la cámara no se reenvía
    private static final long AVISO_PROPIO_MS = 2500;       // si la página no responde en este tiempo, se muestra «Leída»
    private static WeakReference<LectorTarjetaActivity> actual = new WeakReference<>(null);
    private final Handler mano = new Handler(Looper.getMainLooper());
    private LinearLayout panel;
    private TextView pTitulo, pNombre, pLineas;
    private ToneGenerator tono;
    private Runnable ocultarPanel, avisoPropio;
    private long inicio;
    private int lecturas = 0;

    /* Foto de la credencial como evidencia: sale de la misma imagen de la cámara en la que se leyó
       el ID (no hace falta otra foto ni más tiempo). Mientras se ve un ID se guarda su imagen (de
       preferencia una en la que también se leyó el nombre); si no, una por segundo, que es la que
       queda si el ID se escribe a mano. */
    private boolean conFoto = true;
    private volatile byte[] fotoReciente = null;
    private volatile boolean leyoId = false, faltaFoto = false;
    private volatile long fotoEn = 0;
    private final Map<String, byte[]> fotoPorId = new HashMap<>();      // sólo en el hilo principal
    private final Set<String> fotoConNombre = new HashSet<>();
    private static final long CADA_FOTO_MS = 1000, FOTO_ID_MS = 250;

    @Override
    protected void onCreate(Bundle guardado) {
        super.onCreate(guardado);
        getWindow().addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
        inicio = SystemClock.elapsedRealtime();
        File[] viejas = carpetaEvidencia(this).listFiles();                 // por si alguna no se recogió
        if (viejas != null) for (File f : viejas) if (System.currentTimeMillis() - f.lastModified() > 3600_000L) f.delete();
        leerReglas(getIntent());
        armarPantalla();
        if (continuo) {
            actual = new WeakReference<>(this);
            try { tono = new ToneGenerator(AudioManager.STREAM_NOTIFICATION, 90); } catch (Exception e) { tono = null; }
            getOnBackPressedDispatcher().addCallback(this, new OnBackPressedCallback(true) {
                @Override public void handleOnBackPressed() {
                    Toast.makeText(LectorTarjetaActivity.this, "Para salir, mantén presionado «Mantén para salir» 3 segundos.", Toast.LENGTH_SHORT).show();
                }
            });
        }
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
        reglas.digitosId = Math.max(0, Math.min(12, i.getIntExtra(EXTRA_DIGITOS_ID, 0)));
        reglas.ponerIds(idsDeLaBase);
        reglas.ponerPatron(i.getStringExtra(EXTRA_PATRON));
        String[] claves = i.getStringArrayExtra(EXTRA_CLAVES);
        if (claves != null && claves.length > 0) reglas.claves.addAll(AnalizadorTarjeta.Reglas.conjunto(claves));
        String[] noNombre = i.getStringArrayExtra(EXTRA_NO_NOMBRE);
        if (noNombre != null && noNombre.length > 0) reglas.noNombre.addAll(AnalizadorTarjeta.Reglas.conjunto(noNombre));
        String[] noCodigo = i.getStringArrayExtra(EXTRA_NO_CODIGO);
        if (noCodigo != null && noCodigo.length > 0) reglas.noCodigo.addAll(AnalizadorTarjeta.Reglas.conjunto(noCodigo));
        conFoto = i.getBooleanExtra(EXTRA_FOTO, true);
        continuo = i.getBooleanExtra(EXTRA_CONTINUO, false);
        tipoContinuo = "salida".equals(i.getStringExtra(EXTRA_TIPO)) ? "salida" : "entrada";
        esperaNombreMs = Math.max(0, Math.min(3000, i.getIntExtra(EXTRA_ESPERA_NOMBRE, 1500)));
        camaraPedida = "frontal".equalsIgnoreCase(i.getStringExtra(EXTRA_CAMARA)) ? "frontal" : "trasera";
        SharedPreferences sp = getSharedPreferences(PREFS, MODE_PRIVATE);
        String elegida = camaraPedida.equals(sp.getString(PREF_PEDIDA, "")) ? sp.getString(PREF_ELEGIDA, camaraPedida) : camaraPedida;
        frontal = "frontal".equals(elegida);
        int confirmar = Math.max(1, Math.min(6, i.getIntExtra(EXTRA_CONFIRMAR, 3)));
        estab = new AnalizadorTarjeta.Estabilizador(confirmar, Math.max(10, confirmar * 3));   // tolera imágenes borrosas entre las buenas
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
        marco.vertical = getIntent().getBooleanExtra(EXTRA_VERTICAL, true);     // la credencial de acceso va de pie
        marco.frontal = frontal;
        raiz.addView(marco, new FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));
        marco.setOnTouchListener((v, ev) -> {                       // tocar para enfocar
            if (ev.getAction() == MotionEvent.ACTION_UP && camara != null) {
                try {                                               // la frontal muchas veces no enfoca: se ignora
                    MeteringPoint p = vista.getMeteringPointFactory().createPoint(ev.getX(), ev.getY());
                    camara.getCameraControl().startFocusAndMetering(new FocusMeteringAction.Builder(p).setAutoCancelDuration(3, TimeUnit.SECONDS).build());
                } catch (Exception ignorada) { }
            }
            return true;
        });

        // Arriba: qué hacer
        LinearLayout arriba = new LinearLayout(this);
        arriba.setOrientation(LinearLayout.VERTICAL);
        arriba.setBackgroundColor(continuo ? colorDe("salida".equals(tipoContinuo) ? "azul" : "verde", 235) : Color.argb(150, 11, 37, 69));
        TextView tTitulo = texto(getIntent().getStringExtra(EXTRA_TITULO), continuo ? 30 : 20, true, BLANCO);
        if (continuo) tTitulo.setGravity(Gravity.CENTER_HORIZONTAL);
        TextView tSub = texto(getIntent().getStringExtra(EXTRA_SUBTITULO), 14, false, Color.argb(225, 255, 255, 255));
        arriba.addView(tTitulo);
        arriba.addView(tSub);
        FrameLayout.LayoutParams pa = new FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT, Gravity.TOP);
        raiz.addView(arriba, pa);

        // Abajo: lo que se va leyendo y los botones
        LinearLayout abajo = new LinearLayout(this);
        abajo.setOrientation(LinearLayout.VERTICAL);
        abajo.setBackgroundColor(Color.argb(190, 11, 37, 69));
        tEstado = texto(continuo ? "Acerca tu credencial al recuadro" : "Buscando el nombre y el ID…", 17, true, BLANCO);
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
        Button bEscribir = boton("Escribir ID", Color.argb(60, 255, 255, 255));
        bEscribir.setOnClickListener(v -> escribirCodigo());
        bLinterna = boton("Linterna", Color.argb(60, 255, 255, 255));
        bLinterna.setVisibility(View.GONE);
        bLinterna.setOnClickListener(v -> {
            if (camara == null) return;
            linterna = !linterna;
            camara.getCameraControl().enableTorch(linterna);
            bLinterna.setText(linterna ? "Apagar luz" : "Linterna");
        });
        bCambiar = boton("Cambiar cámara", Color.argb(60, 255, 255, 255));
        bCambiar.setVisibility(View.GONE);                          // sólo si el teléfono tiene las dos
        bCambiar.setOnClickListener(v -> cambiarCamara());
        if (continuo) {                                           // equipo fijo: salir manteniendo 3 s
            bCancelar.setText("Mantén para salir");
            bCancelar.setOnClickListener(null);
            salirManteniendo(bCancelar);
            // «Escribir ID» se queda: una credencial dañada (rayas de la impresora) se registra sin cerrar la cámara
        }
        fila.addView(bCancelar, peso());
        fila.addView(bEscribir, peso());
        fila.addView(bLinterna, peso());
        fila.addView(bCambiar, peso());
        abajo.addView(fila);
        if (getIntent().getBooleanExtra(EXTRA_SIMULAR, false)) {
            Button bSimular = boton("Simular lectura (demo)", Color.argb(40, 255, 202, 40));
            bSimular.setOnClickListener(v -> entregar(null, null, false, true));
            LinearLayout.LayoutParams ps = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(46));
            ps.topMargin = dp(8);
            abajo.addView(bSimular, ps);
        }
        raiz.addView(abajo, new FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT, Gravity.BOTTOM));
        if (continuo) armarPanel(raiz);

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
        b.setMinWidth(0);
        b.setMinimumWidth(0);
        b.setPadding(dp(6), dp(2), dp(6), dp(2));
        b.setMaxLines(2);
        return b;
    }
    private LinearLayout.LayoutParams peso() {
        LinearLayout.LayoutParams p = new LinearLayout.LayoutParams(0, dp(52), 1f);
        p.setMargins(dp(4), 0, dp(4), 0);
        return p;
    }

    /* ── Cámara ── */
    private void iniciarCamara() {
        ListenableFuture<ProcessCameraProvider> futuro = ProcessCameraProvider.getInstance(this);
        futuro.addListener(() -> {
            try {
                proveedor = futuro.get();
                preview = new Preview.Builder().build();
                preview.setSurfaceProvider(vista.getSurfaceProvider());
                ResolutionSelector resolucion = new ResolutionSelector.Builder()
                        .setResolutionStrategy(new ResolutionStrategy(new Size(1920, 1080), ResolutionStrategy.FALLBACK_RULE_CLOSEST_HIGHER_THEN_LOWER))
                        .build();
                analisis = new ImageAnalysis.Builder()
                        .setResolutionSelector(resolucion)
                        .setBackpressureStrategy(ImageAnalysis.STRATEGY_KEEP_ONLY_LATEST)
                        .build();
                analisis.setAnalyzer(hilo, this::analizar);
                enlazarCamara();
            } catch (Exception e) {
                terminarConError("No se pudo abrir la cámara: " + e.getMessage());
            }
        }, ContextCompat.getMainExecutor(this));
    }

    private boolean hayCamara(CameraSelector s) {
        try { return proveedor != null && proveedor.hasCamera(s); } catch (Exception e) { return false; }
    }

    /** Abre la cámara elegida (frontal o trasera); si el teléfono no la tiene, la otra. */
    private void enlazarCamara() {
        boolean hayFrontal = hayCamara(CameraSelector.DEFAULT_FRONT_CAMERA), hayTrasera = hayCamara(CameraSelector.DEFAULT_BACK_CAMERA);
        if (frontal && !hayFrontal) frontal = false;
        else if (!frontal && !hayTrasera && hayFrontal) frontal = true;
        bCambiar.setVisibility(hayFrontal && hayTrasera ? View.VISIBLE : View.GONE);
        if (linterna && camara != null) { try { camara.getCameraControl().enableTorch(false); } catch (Exception ignorada) { } }
        linterna = false;
        bLinterna.setText("Linterna");
        proveedor.unbindAll();
        camara = proveedor.bindToLifecycle(this, frontal ? CameraSelector.DEFAULT_FRONT_CAMERA : CameraSelector.DEFAULT_BACK_CAMERA, preview, analisis);
        bLinterna.setVisibility(camara.getCameraInfo().hasFlashUnit() ? View.VISIBLE : View.GONE);   // la frontal no suele tener
        marco.frontal = frontal;
        marco.invalidate();
    }

    /** Botón «Cambiar cámara»: pasa a la otra y este teléfono la recuerda. */
    private void cambiarCamara() {
        if (proveedor == null || entregado) return;
        boolean antes = frontal;
        frontal = !frontal;
        try {
            enlazarCamara();
        } catch (Exception e) {                                    // si la otra no abre, se queda con la que estaba
            frontal = antes;
            try { enlazarCamara(); } catch (Exception e2) { terminarConError("No se pudo abrir la cámara: " + e2.getMessage()); return; }
        }
        getSharedPreferences(PREFS, MODE_PRIVATE).edit()
                .putString(PREF_PEDIDA, camaraPedida)
                .putString(PREF_ELEGIDA, frontal ? "frontal" : "trasera")
                .apply();
        tEstado.setText(frontal ? "Cámara frontal: muestra la credencial a la pantalla" : "Cámara trasera");
        tEstado.setTextColor(BLANCO);
        marco.color(BLANCO);
    }

    @OptIn(markerClass = ExperimentalGetImage.class)
    private void analizar(@NonNull ImageProxy imagen) {
        if (entregado || pausado || imagen.getImage() == null) { imagen.close(); return; }
        byte[] foto = null;
        long ahora = SystemClock.elapsedRealtime();
        if (conFoto && ((leyoId && faltaFoto && ahora - fotoEn >= FOTO_ID_MS) || ahora - fotoEn >= CADA_FOTO_MS)) {
            try { foto = jpeg(imagen); fotoEn = ahora; } catch (Throwable t) { foto = null; }
        }
        final byte[] f = foto;
        InputImage entrada = InputImage.fromMediaImage(imagen.getImage(), imagen.getImageInfo().getRotationDegrees());
        lector.process(entrada)
                .addOnSuccessListener(ContextCompat.getMainExecutor(this), t -> alLeer(t, f))
                .addOnCompleteListener(t -> imagen.close());
    }

    /**
     * Cada carácter del renglón con su caja en la imagen (Text.Symbol), en orden. Con ella el analizador
     * reconoce la raya de la impresora por su forma (mucho más delgada que cualquier número). Vacío si el
     * reconocimiento no la da: entonces se decide sólo por el texto.
     */
    private static List<AnalizadorTarjeta.Simbolo> simbolos(Text.Line ln) {
        List<AnalizadorTarjeta.Simbolo> s = new ArrayList<>();
        try {
            for (Text.Element el : ln.getElements()) {
                for (Text.Symbol sim : el.getSymbols()) {
                    String t = sim.getText();
                    Rect b = sim.getBoundingBox();
                    if (t == null || t.length() != 1 || b == null) return new ArrayList<>();
                    s.add(new AnalizadorTarjeta.Simbolo(t.charAt(0), b.left, b.top, b.right, b.bottom));
                }
            }
        } catch (RuntimeException e) {
            return new ArrayList<>();
        }
        return s;
    }

    private void alLeer(Text texto, byte[] foto) {
        if (entregado || pausado || isFinishing()) return;
        if (foto != null) fotoReciente = foto;
        lecturas++;
        List<AnalizadorTarjeta.Linea> lineas = new ArrayList<>();
        for (Text.TextBlock bloque : texto.getTextBlocks()) {
            for (Text.Line ln : bloque.getLines()) {
                Rect r = ln.getBoundingBox();
                if (r == null) continue;
                lineas.add(new AnalizadorTarjeta.Linea(ln.getText(), r.top, r.left, r.height(), r.width(), simbolos(ln)));
            }
        }
        if (!lineas.isEmpty()) ultimoTexto = AnalizadorTarjeta.unir(lineas);
        AnalizadorTarjeta.Lectura l = AnalizadorTarjeta.analizar(lineas, reglas);
        if (foto != null && l.codigo != null) {                              // la credencial a la vista, con su ID legible
            boolean conNombre = l.nombre != null;
            if (!fotoPorId.containsKey(l.codigo) || (conNombre && !fotoConNombre.contains(l.codigo))) {
                if (fotoPorId.size() >= 6 && !fotoPorId.containsKey(l.codigo)) { fotoPorId.clear(); fotoConNombre.clear(); }
                fotoPorId.put(l.codigo, foto);
                if (conNombre) fotoConNombre.add(l.codigo);
            }
        }
        leyoId = l.codigo != null;
        faltaFoto = l.codigo != null && !fotoConNombre.contains(l.codigo);
        AnalizadorTarjeta.Lectura ok = estab.agregar(l);
        if (confirmado == null && ok != null) { confirmado = ok; confirmadoEn = SystemClock.elapsedRealtime(); }
        if (confirmado != null) {
            // El código ya es seguro: se entrega en cuanto hay nombre (o tras un momento sin él)
            String nombre = estab.nombreProbable(confirmado.codigo);
            if (nombre != null || SystemClock.elapsedRealtime() - confirmadoEn >= esperaNombreMs) {
                if (continuo) entregarContinuo(confirmado.codigo, nombre, false);
                else entregar(confirmado.codigo, nombre, false, false);
                return;
            }
            tEstado.setText("ID " + confirmado.codigo + " — falta leer el nombre…");
            tEstado.setTextColor(AMARILLO);
            marco.color(AMARILLO);
            tTexto.setText(ultimoTexto.replace("\n", " · "));
            return;
        }
        // Lo que se va leyendo, para que el guardia sepa que sí está viendo la tarjeta
        if (l.codigo != null) {
            tEstado.setText("ID " + l.codigo + (l.nombre != null ? " · " + l.nombre : "") + " — confirmando…");
            tEstado.setTextColor(AMARILLO);
            marco.color(AMARILLO);
        } else if (l.enRecuadro != null) {
            // Se ve el recuadro del ID pero el número no es seguro: se dice qué se lee (ayuda a saber qué pasa)
            tEstado.setText("En el recuadro leo «" + l.enRecuadro + "»: no se distingue el ID. "
                    + (frontal ? "Mantenla quieta, sin reflejos" : "Acércala o enfoca") + ", o toca «Escribir ID».");
            tEstado.setTextColor(AMARILLO);
            marco.color(BLANCO);
        } else if (l.nombre != null) {
            tEstado.setText(l.nombre + (frontal ? " — falta el ID; mantenla quieta, sin reflejos" : " — falta el ID; acércala o enfoca"));
            tEstado.setTextColor(BLANCO);
            marco.color(BLANCO);
        } else {
            tEstado.setText(lineas.isEmpty() ? "Buscando el nombre y el ID…"
                    : (frontal ? "Mantén la credencial quieta dentro del recuadro" : "Acerca la credencial y mantenla quieta"));
            tEstado.setTextColor(BLANCO);
            marco.color(BLANCO);
        }
        tTexto.setText(ultimoTexto.replace("\n", " · "));
    }

    private void escribirCodigo() {
        pausado = true;
        EditText e = new EditText(this);
        e.setInputType(InputType.TYPE_CLASS_NUMBER);
        e.setHint("Número del ID");
        FrameLayout caja = new FrameLayout(this);
        caja.setPadding(dp(22), dp(6), dp(22), 0);
        caja.addView(e);
        AlertDialog d = new AlertDialog.Builder(this)
                .setTitle("Escribir el ID")
                .setMessage("Si la credencial no se lee, escribe el número del ID (el del recuadro azul).")
                .setView(caja)
                .setPositiveButton("Aceptar", (dlg, w) -> {
                    String c = e.getText().toString().replaceAll("\\D", "");
                    if (c.isEmpty()) pausado = false;
                    else if (continuo) { pausado = false; entregarContinuo(c, null, true); }   // la cámara sigue abierta
                    else entregar(c, null, true, false);
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
        datos.putExtra("camara", frontal ? "frontal" : "trasera");
        if (conFoto && !simulado) {
            String ruta = guardarFoto(fotoDe(codigo));
            if (ruta != null) datos.putExtra("foto", ruta);
        }
        setResult(RESULT_OK, datos);
        if (simulado) { finish(); return; }
        marco.color(VERDE);
        tEstado.setTextColor(VERDE);
        tEstado.setText("Leída: " + (nombre != null && !nombre.isEmpty() ? nombre + " · " : "") + "ID " + codigo);
        marco.performHapticFeedback(Build.VERSION.SDK_INT >= 30 ? HapticFeedbackConstants.CONFIRM : HapticFeedbackConstants.LONG_PRESS);
        marco.postDelayed(this::finish, 450);
    }

    /* ── Foto de evidencia ── */
    static File carpetaEvidencia(android.content.Context c) {
        File d = new File(c.getCacheDir(), "evidencia");
        if (!d.isDirectory()) d.mkdirs();
        return d;
    }

    private static String sinCeros(String c) {
        String x = c == null ? "" : c.trim();
        if (!x.matches("\\d+")) return x;
        x = x.replaceFirst("^0+", "");
        return x.isEmpty() ? "0" : x;
    }

    /** La imagen en la que se leyó ese ID (aunque se haya escrito a mano sin los ceros); si no hay, la más reciente. */
    private byte[] fotoDe(String codigo) {
        if (codigo != null) {
            byte[] f = fotoPorId.get(codigo);
            if (f != null) return f;
            String k = sinCeros(codigo);
            for (Map.Entry<String, byte[]> e : fotoPorId.entrySet()) if (sinCeros(e.getKey()).equals(k)) return e.getValue();
        }
        return fotoReciente;
    }

    /** La imagen de la cámara como JPEG de hasta 1440 px, derecha (como la ve el guardia). */
    private static byte[] jpeg(ImageProxy img) {
        Bitmap b = img.toBitmap();
        int rot = img.getImageInfo().getRotationDegrees();
        float k = Math.min(1f, 1440f / Math.max(b.getWidth(), b.getHeight()));
        Matrix m = new Matrix();
        if (k < 1f) m.postScale(k, k);
        if (rot != 0) m.postRotate(rot);
        Bitmap r = m.isIdentity() ? b : Bitmap.createBitmap(b, 0, 0, b.getWidth(), b.getHeight(), m, true);
        ByteArrayOutputStream o = new ByteArrayOutputStream(220 * 1024);
        r.compress(Bitmap.CompressFormat.JPEG, 80, o);
        if (r != b) r.recycle();
        b.recycle();
        return o.toByteArray();
    }

    /** Se entrega por un archivo temporal (no cabe con seguridad en el resultado); el plugin lo lee y lo borra. */
    private String guardarFoto(byte[] f) {
        if (f == null || f.length == 0) return null;
        try {
            File a = new File(carpetaEvidencia(this), "credencial-" + System.currentTimeMillis() + ".jpg");
            try (FileOutputStream o = new FileOutputStream(a)) { o.write(f); }
            return a.getAbsolutePath();
        } catch (Exception e) {
            return null;
        }
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
        if (continuo) {
            mano.removeCallbacksAndMessages(null);
            if (actual.get() == this) actual = new WeakReference<>(null);
            if (tono != null) tono.release();
            avisarCerrado();
        }
    }

    /* ══ Lectura continua (equipo fijo de entrada o de salida) ══════════════ */

    /** Manda la credencial leída a la página y queda lista para la siguiente. */
    private void entregarContinuo(String codigo, String nombre, boolean manual) {
        long ahora = SystemClock.elapsedRealtime();
        if (!manual && codigo.equals(ultimoEnviado) && ahora - ultimoEnviadoEn < MISMA_CREDENCIAL_MS) {
            ultimoEnviadoEn = ahora;                                 // sigue frente a la cámara: no se reenvía
            reiniciarLectura();
            return;
        }
        ultimoEnviado = codigo;
        ultimoEnviadoEn = ahora;
        JSObject d = new JSObject();
        d.put("codigo", codigo);
        d.put("nombre", nombre == null ? "" : nombre);
        d.put("texto", ultimoTexto);
        d.put("manual", manual);
        d.put("continuo", true);
        d.put("tipo", tipoContinuo);
        d.put("lecturas", lecturas);
        d.put("ms", ahora - inicio);
        d.put("camara", frontal ? "frontal" : "trasera");
        if (conFoto) {
            byte[] f = fotoDe(codigo);
            if (f != null && f.length > 0) d.put("foto", Base64.encodeToString(f, Base64.NO_WRAP));
        }
        LectorTarjetaPlugin.emitir("lectura", d);
        marco.performHapticFeedback(Build.VERSION.SDK_INT >= 30 ? HapticFeedbackConstants.CONFIRM : HapticFeedbackConstants.LONG_PRESS);
        // Si la página no contesta a tiempo (sin señal no importa: registra en el teléfono), se avisa aquí
        final String cod = codigo, nom = nombre == null ? "" : nombre;
        if (avisoPropio != null) mano.removeCallbacks(avisoPropio);
        avisoPropio = () -> pintarPanel(("salida".equals(tipoContinuo) ? "SALIDA" : "ENTRADA") + " LEÍDA", nom, "ID " + cod,
                "salida".equals(tipoContinuo) ? "azul" : "verde", 2500, "ok");
        mano.postDelayed(avisoPropio, AVISO_PROPIO_MS);
        tEstado.setText("ID " + codigo + " — registrando…");
        tEstado.setTextColor(VERDE);
        marco.color(VERDE);
        reiniciarLectura();
    }

    /** Lista para la siguiente persona: se olvida lo leído (menos la última credencial enviada). */
    private void reiniciarLectura() {
        confirmado = null;
        int confirmar = Math.max(1, Math.min(6, getIntent().getIntExtra(EXTRA_CONFIRMAR, 3)));
        estab = new AnalizadorTarjeta.Estabilizador(confirmar, Math.max(10, confirmar * 3));   // tolera imágenes borrosas entre las buenas
        fotoPorId.clear();
        fotoConNombre.clear();
        fotoReciente = null;
        leyoId = false;
        faltaFoto = false;
        inicio = SystemClock.elapsedRealtime();
        lecturas = 0;
    }

    /** La página dice qué mostrar de la lectura (desde LectorTarjetaPlugin.mostrar). */
    static boolean mostrarEnPantalla(JSObject d) {
        LectorTarjetaActivity a = actual.get();
        if (a == null || a.isFinishing()) return false;
        a.runOnUiThread(() -> a.mostrarResultado(d));
        return true;
    }
    static void detenerContinuo() {
        LectorTarjetaActivity a = actual.get();
        if (a != null) a.runOnUiThread(a::finish);
    }
    private void mostrarResultado(JSObject d) {
        if (avisoPropio != null) mano.removeCallbacks(avisoPropio);
        StringBuilder lineas = new StringBuilder();
        JSONArray l = d.optJSONArray("lineas");
        if (l != null) for (int i = 0; i < l.length(); i++) { if (lineas.length() > 0) lineas.append('\n'); lineas.append(l.optString(i, "")); }
        long ms = (long) Math.max(800, Math.min(8000, d.optDouble("segundos", 2.5) * 1000));
        pintarPanel(d.optString("titulo", ""), d.optString("nombre", ""), lineas.toString(), d.optString("color", "verde"), ms, d.optString("sonido", "ok"));
    }
    private void pintarPanel(String titulo, String nombre, String lineas, String color, long ms, String sonido) {
        if (panel == null) return;
        GradientDrawable g = new GradientDrawable();
        g.setColor(colorDe(color, 242));
        g.setCornerRadius(dp(22));
        panel.setBackground(g);
        pTitulo.setText(titulo);
        pNombre.setText(nombre);
        pNombre.setVisibility(nombre.isEmpty() ? View.GONE : View.VISIBLE);
        pLineas.setText(lineas);
        pLineas.setVisibility(lineas.isEmpty() ? View.GONE : View.VISIBLE);
        panel.setVisibility(View.VISIBLE);
        if (tono != null) {
            try { tono.startTone("aviso".equals(sonido) ? ToneGenerator.TONE_PROP_NACK : ToneGenerator.TONE_PROP_ACK, 220); } catch (Exception ignorada) { }
        }
        if (ocultarPanel != null) mano.removeCallbacks(ocultarPanel);
        ocultarPanel = () -> {
            panel.setVisibility(View.GONE);
            tEstado.setText("Acerca tu credencial al recuadro");
            tEstado.setTextColor(BLANCO);
            marco.color(BLANCO);
        };
        mano.postDelayed(ocultarPanel, ms);
    }
    private static int colorDe(String c, int alfa) {
        switch (c == null ? "" : c) {
            case "azul":     return Color.argb(alfa, 24, 95, 165);
            case "amarillo": return Color.argb(alfa, 180, 83, 9);
            case "rojo":     return Color.argb(alfa, 163, 45, 45);
            default:         return Color.argb(alfa, 47, 107, 30);   // verde
        }
    }
    /** El recuadro grande con el resultado, sobre la cámara. */
    private void armarPanel(FrameLayout raiz) {
        panel = new LinearLayout(this);
        panel.setOrientation(LinearLayout.VERTICAL);
        panel.setGravity(Gravity.CENTER_HORIZONTAL);
        panel.setPadding(dp(20), dp(22), dp(20), dp(22));
        pTitulo = texto("", 28, true, BLANCO);
        pNombre = texto("", 23, true, BLANCO);
        pLineas = texto("", 18, false, BLANCO);
        for (TextView t : new TextView[] { pTitulo, pNombre, pLineas }) { t.setGravity(Gravity.CENTER_HORIZONTAL); panel.addView(t); }
        pNombre.setPadding(0, dp(10), 0, dp(4));
        pLineas.setLineSpacing(0, 1.15f);
        panel.setVisibility(View.GONE);
        FrameLayout.LayoutParams pp = new FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT, Gravity.CENTER);
        pp.setMargins(dp(18), 0, dp(18), 0);
        raiz.addView(panel, pp);
    }
    /** «Mantén para salir»: hay que tenerlo presionado 3 segundos (que nadie lo cierre por accidente). */
    @SuppressLint("ClickableViewAccessibility")
    private void salirManteniendo(Button b) {
        final Runnable salir = () -> { avisarCerrado(); finish(); };
        b.setOnTouchListener((v, ev) -> {
            if (ev.getAction() == MotionEvent.ACTION_DOWN) { b.setText("Sigue presionando…"); mano.postDelayed(salir, 3000); }
            else if (ev.getAction() == MotionEvent.ACTION_UP || ev.getAction() == MotionEvent.ACTION_CANCEL) { b.setText("Mantén para salir"); mano.removeCallbacks(salir); }
            return true;
        });
    }
    private void avisarCerrado() {
        if (cerradoAvisado) return;
        cerradoAvisado = true;
        LectorTarjetaPlugin.emitir("cerrado", new JSObject());
    }

    /** Sombra alrededor de un recuadro del tamaño de una tarjeta (85.6 × 54 mm), de pie o acostada.
     *  Con la cámara frontal el recuadro es más chico: la credencial se pone un poco más lejos, donde
     *  esa cámara (casi siempre de foco fijo y más abierta) la ve nítida. */
    static final class Marco extends View {
        private final Paint sombra = new Paint(), borde = new Paint(Paint.ANTI_ALIAS_FLAG);
        private final RectF hueco = new RectF();
        private int arriba = 0, abajo = 0;
        boolean vertical = true, frontal = false;
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
            float libreArriba = arriba, libreAbajo = h - abajo, libre = libreAbajo - libreArriba;
            float ancho, alto;
            if (vertical) {                    // credencial de pie: llena el espacio entre los textos y los botones
                alto = Math.min(w * (frontal ? 0.46f : 0.64f) * 1.586f, libre > 0 ? libre * 0.94f : h * 0.6f);   // trasera ~11 cm: enfoca bien
                ancho = alto / 1.586f;
            } else {
                ancho = w * (frontal ? 0.66f : 0.88f); alto = ancho / 1.586f;
            }
            float centro = libre > alto ? (libreArriba + libreAbajo) / 2f : h / 2f;
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
