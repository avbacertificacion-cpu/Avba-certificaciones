<?php
/**
 * AVBA Control de Accesos — Plantilla de configuración
 *
 * Copia este archivo a config.php (en el servidor:
 * public_html/controlaccesos/config/config.php) y rellénalo con los datos de la
 * base PROPIA de Control de Accesos. config.php no se sube al repositorio ni
 * lo publica el despliegue: vive sólo en el servidor.
 *
 * PASOS HOSTINGER (una sola vez):
 *   1. hPanel → Bases de datos → MySQL → crear la base (p. ej.
 *      u218429682_controlaccesos) y su usuario con contraseña.
 *   2. Copiar aquí host, nombre, usuario y contraseña.
 *   3. Las tablas se crean solas al primer uso; sql/esquema-inicial.sql trae
 *      el esquema por si se prefiere importarlo en phpMyAdmin.
 *   4. Inventar una CLAVE_INSTALACION (abajo) y abrir
 *      https://gestion.avba.com.mx/controlaccesos/login.html: mientras no haya
 *      ninguna cuenta, ofrece crear la primera de Administración pidiendo esa
 *      clave. Ya creada, la instalación se apaga sola.
 */

// ── Base de datos propia de Control de Accesos ────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'u218429682_controlaccesos');
define('DB_USER', 'u218429682_controlaccesos');
define('DB_PASS', 'ContraseñaSegura123!');

// ── Primera cuenta de Administración ──────────────────────
// Sólo sirve mientras la base no tiene ninguna cuenta. Al menos 8 caracteres;
// no es la contraseña de nadie (puede borrarse después de instalar).
define('CLAVE_INSTALACION', 'cambia-esta-clave');

// ── Sesiones ──────────────────────────────────────────────
define('TOKEN_TTL', 43200);            // 12 horas (un turno de caseta)

// ── Sitio ─────────────────────────────────────────────────
define('SITE_URL', 'https://gestion.avba.com.mx/controlaccesos');

// ── Archivos (fotos de credenciales, PDF) ─────────────────
// Carpeta propia de la plataforma; uploads/accesos/.htaccess la protege y las
// fotos se sirven sólo por descargar.php con sesión.
define('UPLOAD_DIR', __DIR__ . '/../uploads/');

// ── Zona horaria de la planta ─────────────────────────────
date_default_timezone_set('America/Mexico_City');
