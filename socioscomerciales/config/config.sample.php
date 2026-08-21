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

// ── Correo saliente (SMTP) ────────────────────────────────────────────
// COPIA AQUÍ los mismos valores que ya usa el sistema de certificaciones.
// Están en el config.php de la RAÍZ del hosting, con estos nombres:
//
//     MAIL_HOST       →  SC_MAIL_HOST
//     MAIL_USER       →  SC_MAIL_USER
//     MAIL_PASS       →  SC_MAIL_PASS
//     MAIL_PORT       →  SC_MAIL_PORT
//     MAIL_FROM       →  SC_MAIL_FROM
//     MAIL_FROM_NAME  →  SC_MAIL_FROM_NOMBRE
//
// Se copian en vez de leer aquel archivo para no acoplar los dos sistemas:
// cargarlo traería también las credenciales de su base de datos.
//
// Sin estos valores el portal usa la función mail() de PHP, que funciona
// pero acaba en spam con frecuencia: un correo SMTP autenticado sale con
// el SPF y el DKIM del dominio, y ese sí llega a la bandeja de entrada.
//
// define('SC_MAIL_HOST',        'smtp.hostinger.com');
// define('SC_MAIL_USER',        'no-reply@avba.com.mx');
// define('SC_MAIL_PASS',        'la-contraseña-del-buzón');
// define('SC_MAIL_PORT',        465);   // 465 = SSL, 587 = STARTTLS
// define('SC_MAIL_FROM',        'no-reply@avba.com.mx');
// define('SC_MAIL_FROM_NOMBRE', 'AVBA Socios Comerciales');

// ── Correo automático a la semana del registro ────────────────────────
// Clave para que el cron del hosting pueda dispararlo:
//     https://gestion.avba.com.mx/socioscomerciales/api/cron.php?clave=...
// Si no se define, el portal igual manda esos correos aprovechando el
// tráfico normal, pero con cron salen a su hora aunque nadie entre.
// define('SC_CRON_CLAVE', 'cámbiala-por-una-cadena-larga-y-aleatoria');

// ── WhatsApp de contacto ──────────────────────────────────────────────
// Si se define, los correos pueden llevar un botón que abre WhatsApp con el
// mensaje ya escrito (nombre y folio incluidos). En México se contesta
// WhatsApp mucho más que el correo, así que suele ser el botón que más
// respuestas genera. Formato internacional, sin espacios ni signos.
// define('SC_WHATSAPP', '5215512345678');

// ── Firma de los enlaces de los correos ───────────────────────────────
// Los botones de "sí sigo interesado", "puedo empezar en 15 días", etc. van
// firmados para que nadie pueda contestar por otra persona cambiando un
// número en la URL. Si no se define, el portal genera un secreto solo y lo
// guarda en la base, que es suficiente. Definirlo aquí sirve para poder
// invalidar de golpe todos los enlaces ya enviados: basta con cambiarlo.
// define('SC_FIRMA_CLAVE', 'cámbiala-por-una-cadena-larga-y-aleatoria');

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
