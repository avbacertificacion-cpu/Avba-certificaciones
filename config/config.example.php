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
define('NO_ACREDITACION', '0147-I-0022');  // Número de acreditación EMA

// ── Microservicio de conversión (opcional) ────────────────
define('CONVERT_SERVICE_URL', 'https://mi-vps.com/convert.php');
define('CONVERT_SERVICE_KEY', 'clave_secreta_fuerte_aqui');

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
