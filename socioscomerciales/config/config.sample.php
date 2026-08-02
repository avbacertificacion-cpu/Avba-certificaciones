<?php
/**
 * Socios Comerciales AVBA — Plantilla de configuración
 *
 * Copiar este archivo como `config.php` en el servidor y llenar los
 * valores reales. `config.php` NUNCA se sube al repositorio (está en
 * .gitignore) — solo existe en el servidor de producción.
 */

// Base de datos de Socios Comerciales (Hostinger, ya creada, tablas sc_*)
define('SC_DB_HOST', 'localhost');
define('SC_DB_NAME', '');
define('SC_DB_USER', '');
define('SC_DB_PASS', '');

// ── Base de datos de Gestión (sistema de certificaciones) ──────────────
// DESACTIVADA por ahora. Se usará en la Fase 2 para leer certificaciones
// verificadas de empresas/personas desde el sistema principal (solo
// lectura). Para activarla: descomentar y llenar estas constantes, y
// descomentar la función gestDB() en config/database.php.
// define('GEST_DB_HOST', 'localhost');
// define('GEST_DB_NAME', '');
// define('GEST_DB_USER', '');
// define('GEST_DB_PASS', '');
