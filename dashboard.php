<?php
require_once 'config/config.php';
require_once 'config/roles-extra.php';

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
    case ROLE_GERENTE:
        header('Location: private/gerente-dashboard.php');
        break;
    case ROLE_CLIENTE:
        header('Location: private/cliente-dashboard.php');
        break;
    default:
        // Rol no reconocido (p.ej. columna ENUM sin el valor): avisar en vez de rebotar
        header('Content-Type: text/html; charset=utf-8');
        $rolMostrar = htmlspecialchars($rol === '' ? '(vacío)' : $rol);
        echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">'
           . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
           . '<title>Rol no reconocido</title></head>'
           . '<body style="font-family:Segoe UI,sans-serif;background:#f0f4ff;display:flex;'
           . 'align-items:center;justify-content:center;min-height:100vh;margin:0">'
           . '<div style="background:#fff;max-width:460px;padding:32px;border-radius:14px;'
           . 'box-shadow:0 6px 24px rgba(0,0,0,.1);text-align:center">'
           . '<div style="font-size:44px;margin-bottom:12px">⚠️</div>'
           . '<h2 style="color:#1e293b;margin-bottom:10px">Tu rol no está configurado</h2>'
           . '<p style="color:#64748b;font-size:14px;line-height:1.5">Tu cuenta tiene el rol '
           . '<b>' . $rolMostrar . '</b>, que el sistema no reconoce. '
           . 'Pide al administrador que corrija el rol de tu usuario.</p>'
           . '<a href="public/login.html" style="display:inline-block;margin-top:20px;'
           . 'background:#4338ca;color:#fff;text-decoration:none;padding:10px 22px;border-radius:8px;'
           . 'font-weight:700">Volver al inicio</a>'
           . '</div></body></html>';
        exit;
}
exit;
