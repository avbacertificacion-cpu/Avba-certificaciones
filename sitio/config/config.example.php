<?php
/**
 * AVBA · Sitio principal — configuración
 *
 * CÓMO USARLO
 *   1. Copia este archivo como  config/config.php  EN EL SERVIDOR.
 *   2. Rellena los valores reales.
 *   3. No lo subas al repositorio: config/config.php está en .gitignore
 *      justamente para que las credenciales nunca lleguen a GitHub.
 */

// ── Base de datos ────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'CAMBIAR_nombre_base');
define('DB_USER', 'CAMBIAR_usuario');
define('DB_PASS', 'CAMBIAR_contrasena');

// ── Panel de moderación ──────────────────────────────────────
// Genera el hash ejecutando una sola vez en el servidor:
//   php -r "echo password_hash('TU_CONTRASENA', PASSWORD_DEFAULT), PHP_EOL;"
// y pega aquí el resultado. Nunca guardes la contraseña en texto plano.
define('MURO_ADMIN_HASH', 'CAMBIAR_pega_aqui_el_hash');

// ── Salt para anonimizar direcciones IP ──────────────────────
// Cualquier cadena larga y aleatoria. Sirve para limitar el abuso sin
// almacenar las IP de los visitantes en claro.
define('IP_SALT', 'CAMBIAR_cadena_larga_y_aleatoria');
