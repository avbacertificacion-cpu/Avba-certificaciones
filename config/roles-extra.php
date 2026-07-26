<?php
/**
 * Roles adicionales del sistema (definidos en el repo para no depender de
 * cambios manuales en config.php). Se definen sólo si no existen ya.
 */
if (!defined('ROLE_GERENTE')) {
    define('ROLE_GERENTE', 'gerente');
}
