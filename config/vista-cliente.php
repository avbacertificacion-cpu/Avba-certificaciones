<?php
/**
 * «Ver como cliente»: el administrador entra a las pantallas del cliente de
 * una planta para ver exactamente lo que ese cliente ve.
 *
 * No se imita nada: la sesión pasa a ser la de un cliente de esa planta (rol
 * cliente, su empresa), así que las pantallas y las consultas son las mismas
 * que usa el cliente, con sus mismos permisos. El cliente sólo consulta, de
 * modo que en esta vista tampoco se puede cambiar nada. La sesión del
 * administrador se guarda aparte y se recupera al salir.
 *
 * La entrada y la salida pasan por api/vista-cliente.php; aquí está lo que
 * comparten esa API, las pantallas del cliente y el cierre de sesión.
 */

function enVistaCliente(): bool {
    return !empty($_SESSION['vista_cliente']['admin']);
}

/** Vuelve a la sesión del administrador. Devuelve false si no había vista. */
function salirVistaCliente(): bool {
    if (!enVistaCliente()) return false;
    $admin = $_SESSION['vista_cliente']['admin'];
    unset($_SESSION['vista_cliente']);
    $_SESSION['usuario_id'] = $admin['usuario_id'];
    $_SESSION['nombre']     = $admin['nombre'];
    $_SESSION['rol']        = $admin['rol'];
    $_SESSION['empresa_id'] = $admin['empresa_id'];
    session_regenerate_id(true);
    return true;
}

/**
 * La barra que avisa que es la vista de cliente, con la salida. Va fija abajo
 * para no mover nada de la pantalla del cliente, y no sale al imprimir.
 */
function barraVistaCliente(): void {
    if (!enVistaCliente()) return;
    $planta = htmlspecialchars($_SESSION['vista_cliente']['empresa_nombre'] ?? '', ENT_QUOTES, 'UTF-8');
    echo <<<HTML
<div id="barra-vista-cliente" style="position:fixed;left:0;right:0;bottom:0;z-index:99999;background:#1e1b4b;color:#fff;
     display:flex;align-items:center;justify-content:center;gap:12px;flex-wrap:wrap;padding:10px 14px calc(10px + env(safe-area-inset-bottom));
     font:600 13px/1.3 'Segoe UI',Arial,sans-serif;box-shadow:0 -4px 14px rgba(0,0,0,.25)">
    <span>👁️ Viendo como cliente · <b>{$planta}</b></span>
    <a href="../api/vista-cliente.php?action=salir" style="background:#fff;color:#1e1b4b;text-decoration:none;
       padding:8px 14px;border-radius:8px;font-weight:800;white-space:nowrap">← Volver a admin</a>
</div>
<div style="height:64px"></div>
<style>@media print{#barra-vista-cliente{display:none!important}}</style>
HTML;
}
