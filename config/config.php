<?php
/**
 * AVBA Certificaciones — Configuración global
 *
 * ╔══════════════════════════════════════════════════════╗
 * ║  IMPORTANTE: Edita los valores de tu Hostinger aquí  ║
 * ╚══════════════════════════════════════════════════════╝
 */

// ── Base de datos (Hostinger MySQL) ──────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'u218429682_avba');      // <- cambia por tu nombre de BD en Hostinger
define('DB_USER', 'u218429682_marcos');      // <- cambia por tu usuario de BD
<<<<<<< HEAD
define('DB_PASS', 'Db#2026!Avba-Marcos7'); // <- cambia por tu contraseña
=======
>>>>>>> 5a9cc1470b3e1fddb1343d923f6bcaa018bff823

// ── URL base del sitio ────────────────────────────────────
define('SITE_URL',  'https://gestion.avba.com.mx'); // <- dominio en Hostinger
define('API_URL',   SITE_URL . '/api/');

// ── Correo (SMTP Hostinger) ───────────────────────────────
define('MAIL_HOST',     'smtp.hostinger.com');
define('MAIL_PORT',     465);
define('MAIL_USER',     'certificaciones@avba.com.mx');
define('MAIL_PASS',     'TU_PASSWORD_EMAIL');
define('MAIL_FROM',     'certificaciones@avba.com.mx');
define('MAIL_FROM_NAME','AVBA Inspections');

// ── Directorio de uploads ─────────────────────────────────
define('UPLOAD_DIR',  __DIR__ . '/../uploads/');
define('UPLOAD_URL',  SITE_URL . '/uploads/');

// ── Duración del token de sesión (segundos) ───────────────
define('TOKEN_TTL', 8 * 3600); // 8 horas

// ── Zona horaria ──────────────────────────────────────────
date_default_timezone_set('America/Mexico_City');

// ── Roles válidos ─────────────────────────────────────────
define('ROLES_VALIDOS', ['ADMIN', 'INSPECTOR', 'CALIDAD', 'CERTIFICACIONES', 'CLIENTE']);
