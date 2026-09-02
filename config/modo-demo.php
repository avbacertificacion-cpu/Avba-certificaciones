<?php
/**
 * Modo demostración.
 *
 * El sistema de demo y el real son dos instalaciones distintas, cada una con
 * su propio `config/config.php` y, sobre todo, con su propia base de datos.
 * Ese archivo no viaja en el repositorio, así que el aislamiento no depende
 * de ninguna bandera dentro del código: son dos bases separadas y punto.
 *
 * Esta constante sólo sirve para que la instalación sepa cuál de las dos es:
 * en la de demo se habilita la siembra de plantas de ejemplo y se marca la
 * pantalla; en la real, sembrar está bloqueado, para que los datos de ejemplo
 * no puedan volver a entrar por descuido.
 *
 * Para convertir una instalación en demo, se agrega a su config/config.php:
 *
 *     define('MODO_DEMO', true);
 *
 * Si la constante no existe —el caso del sistema real— el modo demo está
 * apagado. Así, actualizar el código no cambia nada en producción.
 */

/** ¿Esta instalación es la de demostración? */
function esModoDemo(): bool {
    return defined('MODO_DEMO') && MODO_DEMO === true;
}

/**
 * Cinta que marca la instalación de demo. Se imprime tal cual dentro del
 * <body>; en el sistema real no devuelve nada.
 */
function cintaDemo(): string {
    if (!esModoDemo()) return '';
    return '<div style="background:#7c2d12;color:#fff;text-align:center;padding:7px 14px;'
         . 'font:700 12px/1.4 \'Segoe UI\',system-ui,sans-serif;letter-spacing:1.2px;'
         . 'text-transform:uppercase;-webkit-print-color-adjust:exact;print-color-adjust:exact">'
         . 'Sistema de demostración · datos de ejemplo · base de datos independiente'
         . '</div>';
}
