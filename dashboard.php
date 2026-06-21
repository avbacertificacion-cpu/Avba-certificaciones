<?php
require_once 'config/config.php';

// Verificar sesión
if (!isset($_SESSION['usuario_id'])) {
    header('Location: public/login.html');
    exit;
}

$rol = $_SESSION['rol'];
$nombre_usuario = $_SESSION['nombre'];

// Redirigir según rol
switch ($rol) {
    case ROLE_ADMIN:
        header('Location: private/admin-dashboard.php');
        break;
    case ROLE_INSPECTOR:
        header('Location: private/inspector-dashboard.php');
        break;
    case ROLE_CLIENTE:
        header('Location: private/cliente-dashboard.php');
        break;
    default:
        header('Location: public/login.html');
}
exit;
