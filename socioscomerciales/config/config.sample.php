<?php
/**
 * Socios Comerciales AVBA — Plantilla de configuración
 *
 * Copiar este archivo como `config.php` en el servidor y llenar los
 * valores reales. `config.php` NUNCA se sube al repositorio (está en
 * .gitignore) — solo existe en el servidor de producción.
 */

// ── OBLIGATORIO: base de datos de Socios Comerciales (tablas sc_*) ─────
define('SC_DB_HOST', 'localhost');
define('SC_DB_NAME', '');
define('SC_DB_USER', '');
define('SC_DB_PASS', '');

// ── OPCIONAL: correos del portal ──────────────────────────────────────
// Si no se definen, se usan estos valores por defecto. Conviene usar una
// cuenta real del dominio para que los correos no caigan en spam.
// define('SC_MAIL_FROM',        'no-reply@avba.com.mx');
// define('SC_MAIL_FROM_NOMBRE', 'AVBA Socios Comerciales');

// ── OPCIONAL: URL base del portal ─────────────────────────────────────
// Solo hace falta si la detección automática falla (p. ej. detrás de un
// proxy). Se usa para armar los enlaces de verificación de correo.
// define('SC_URL_BASE', 'https://gestion.avba.com.mx/socioscomerciales');

// ── Base de datos de Gestión (sistema de certificaciones) ─────────────
// DESACTIVADA por ahora. Se usará más adelante para leer certificaciones
// verificadas desde el sistema principal (solo lectura). Para activarla:
// descomentar estas constantes y la función gestDB() en config/database.php.
// define('GEST_DB_HOST', 'localhost');
// define('GEST_DB_NAME', '');
// define('GEST_DB_USER', '');
// define('GEST_DB_PASS', '');
