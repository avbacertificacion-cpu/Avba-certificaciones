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

// ── Administradores del portal ────────────────────────────────────────
// Correos que ven el panel de administración (admin.html) y pueden
// bloquear o eliminar cuentas.
//
// La lista vive AQUÍ y no en la base de datos a propósito: este archivo
// solo existe en el servidor y no se versiona, así que ni un INSERT
// malicioso ni una fuga de la base convierten a nadie en administrador.
//
// El administrador se registra primero como una cuenta normal (candidato o
// empresa) desde el portal, y luego se añade su correo a esta lista. Para
// quitarle el permiso, basta con borrarlo de aquí.
// define('SC_ADMINS', ['tu-correo@avba.com.mx']);

// ── RECOMENDADO: clave del diagnóstico ────────────────────────────────
// api/index.php?action=DIAGNOSTICO informa de la versión de PHP, las
// extensiones cargadas, la versión de MariaDB y el estado del esquema. Sin
// esta clave el diagnóstico queda DESACTIVADO (responde 403), porque esa
// información abierta a internet es un mapa para quien busque por dónde
// entrar. Pon aquí una cadena larga y consúltalo con:
//   api/index.php?action=DIAGNOSTICO&clave=LA_QUE_PONGAS
// define('SC_DIAG_CLAVE', 'cámbiala-por-una-cadena-larga-y-aleatoria');

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
