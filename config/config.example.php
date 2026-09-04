<?php
/**
 * AVBA Certificaciones — Plantilla de configuración
 *
 * Copia este archivo a config.php y rellena con tus valores reales.
 * config.php está en .gitignore y NUNCA debe subirse al repositorio.
 *
 * PASOS HOSTINGER:
 * 1. Panel → Bases de datos → MySQL → Crear BD y usuario
 * 2. Copiar host, nombre, usuario y contraseña en DB_* abajo
 * 3. Configurar tu dominio en CORS_ORIGINS
 * 4. Subir via FTP/File Manager al servidor
 */

// ── Base de datos ─────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'u123456_avba');
define('DB_USER', 'u123456_avba');
define('DB_PASS', 'ContraseñaSegura123!');

/*
 * ── Divisiones ────────────────────────────────────────────
 * Cada división lleva su propio expediente en su propia base, con el mismo
 * esquema y sus propias cuentas. La aplicación es la misma: elige la base
 * según en cuál vive la cuenta que entró.
 *
 * Para dar de alta una división:
 *   1. Crear la base en el panel del hosting, con el mismo usuario de MySQL.
 *   2. Importar en ella el esquema (las tablas se crean solas al primer uso,
 *      pero conviene partir de un respaldo de la principal SIN sus datos).
 *   3. Declararla aquí y crear ahí su primera cuenta ADMIN.
 *
 * Deja el arreglo vacío mientras haya una sola división.
 *
 * El banco de placas QR es independiente en cada base: cárgale a cada división
 * SU PROPIO lote de placas. Cargar el mismo lote en las dos haría que un mismo
 * número existiera dos veces, y al escanearlo aparecería sólo el primero que
 * se encuentre.
 */
define('DB_DIVISIONES', [
    // 'secundaria' => 'u123456_avba_div2',
]);

// ── Seguridad ─────────────────────────────────────────────
define('TOKEN_TTL', 28800);           // 8 horas en segundos
define('LOGIN_MAX_INTENTOS', 5);      // bloquear tras N fallos
define('LOGIN_BLOQUEO_MIN', 15);      // minutos de bloqueo

// ── CORS ──────────────────────────────────────────────────
define('CORS_ORIGINS', 'https://mi-dominio.com,https://www.mi-dominio.com');

// ── URL pública del sitio (sin barra final) ───────────────
define('SITE_URL', 'https://mi-dominio.com');

// ── Rutas de uploads ──────────────────────────────────────
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('UPLOAD_URL', 'uploads/');

// ── Datos de la Unidad de Inspección ──────────────────────
define('NO_ACREDITACION', 'UVNMX 057');  // Número de registro oficial

// ── Microservicio de conversión (opcional) ────────────────
define('CONVERT_SERVICE_URL', 'https://mi-vps.com/convert.php');
define('CONVERT_SERVICE_KEY', 'clave_secreta_fuerte_aqui');

// ── Verificación de identidad con IA (opcional) ───────────
// API key de Google Gemini (https://aistudio.google.com/apikey).
// Permite que Calidad compare los datos capturados por el participante
// contra su identificación oficial. Si se deja vacía, el botón de
// verificación queda deshabilitado y todo se revisa a mano.
define('GEMINI_API_KEY', '');
// Modelo a usar (opcional). Si se deja vacío o no se define, se usa
// 'gemini-2.5-flash' (rápido y económico). Para documentos difíciles puede
// cambiarse a 'gemini-2.5-pro'.
define('GEMINI_MODEL', '');

// ── IA para propuestas técnico-económicas (Anthropic / Claude) ──
// Clave de la API de Claude (https://console.anthropic.com/settings/keys).
// Sin ella, el módulo de presupuestos sigue funcionando: se pueden capturar
// servicios, clientes y ofertas, y sale el PDF del presupuesto. Lo único que
// queda deshabilitado es la redacción automática de la propuesta.
define('CLAUDE_API_KEY', '');

// Modelo a usar. Vacío = claude-opus-5, el recomendado para redactar.
define('CLAUDE_MODEL', '');

// Cuánto se le pide que piense: low | medium | high | xhigh | max.
// Vacío = high. En hosting compartido, si la petición se corta por el límite
// de ejecución de PHP, bajarlo a 'medium' acorta la espera.
define('CLAUDE_EFFORT', '');

// ── Facturación electrónica (Facturapi) ──────────────────
// Llave secreta de Facturapi (https://dashboard.facturapi.io → Llaves de API).
//   sk_test_…  → modo de pruebas: la factura se genera pero NO se timbra ante
//                el SAT y no tiene validez fiscal. Sirve para probar todo.
//   sk_live_…  → producción: timbra de verdad y consume un timbre.
// El sistema deduce el modo del prefijo de la llave, así que cambiar de
// pruebas a producción es cambiar esta línea y nada más.
// Sin llave, el módulo de presupuestos sigue funcionando completo; sólo queda
// apagado el botón de facturar.
define('FACTURAPI_KEY', '');

// ── Entorno ───────────────────────────────────────────────
define('APP_ENV', 'production');

if (APP_ENV === 'production') {
    error_reporting(0);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}
