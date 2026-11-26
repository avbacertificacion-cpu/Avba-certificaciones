<?php
/* ══════════════════════════════════════════════════════════════
   AVBA Control de Accesos — API (gestion.avba.com.mx/controlaccesos/api/)

   Punto de entrada único. Sólo sesión, cuentas y control de accesos: nada del
   Sistema de Gestión. Respuestas JSON {status, message, …}.

     GET  api/index.php?action=XXX&…           consultas
     POST api/index.php  {action, payload}      cambios (JSON)
     POST api/index.php  multipart (action, …)  registro con foto

   El token va en «Authorization: Bearer …» (o en token=… para los enlaces).
   ══════════════════════════════════════════════════════════════ */

if (!is_file(__DIR__ . '/../config/config.php')) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Falta config/config.php en el servidor (ver config/config.example.php).'], JSON_UNESCAPED_UNICODE);
    exit;
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/ControlAccesos.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header_remove('X-Powered-By');
// Sin encabezados CORS a propósito: las pantallas y la app (que abre el sitio
// con server.url) llaman desde el mismo dominio.

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($metodo === 'OPTIONS') { http_response_code(204); exit; }

// ── Petición ──────────────────────────────────────────────
$cuerpo = [];
if ($metodo === 'POST') {
    $crudo = file_get_contents('php://input');
    if ($crudo !== '' && $crudo !== false && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
        $cuerpo = json_decode($crudo, true);
        if (!is_array($cuerpo)) respuesta(['status' => 'error', 'message' => 'Petición no válida.'], 400);
    } elseif (!empty($_POST)) {
        $cuerpo = $_POST;                               // multipart: registro con foto
    }
}
$accion = (string)($metodo === 'GET' ? ($_GET['action'] ?? '') : ($cuerpo['action'] ?? ''));
$datos  = $metodo === 'GET' ? $_GET : (is_array($cuerpo['payload'] ?? null) ? $cuerpo['payload'] : $cuerpo);
$token  = tokenDePeticion($cuerpo);

try {
    $pdo  = Database::conexion();
    $auth = new Auth($pdo);

    // ── Sin sesión ────────────────────────────────────────
    switch ($accion) {
        case 'LOGIN':
            if ($metodo !== 'POST') respuesta(['status' => 'error', 'message' => 'Método no permitido.'], 405);
            respuesta($auth->login($datos));
        case 'INSTALAR_ESTADO':
            respuesta($auth->estadoInstalacion());
        case 'INSTALAR':
            if ($metodo !== 'POST') respuesta(['status' => 'error', 'message' => 'Método no permitido.'], 405);
            respuesta($auth->instalar($datos));
        case 'LOGOUT':
            respuesta($auth->cerrarSesion($token));
    }

    // ── Con sesión ────────────────────────────────────────
    $usr = validarToken($pdo, $token);
    if (!$usr) respuesta(['status' => 'error', 'codigo' => 'SIN_SESION', 'message' => 'Tu sesión venció. Vuelve a iniciar sesión.'], 401);
    $usr['token'] = $token;
    if (!ControlAccesos::permitido($usr)) respuesta(['status' => 'error', 'message' => 'Tu cuenta no tiene un perfil válido.'], 403);

    // El guardia de caseta sólo registra: todo lo demás se rechaza aquí, sin
    // depender de que cada acción revise el perfil por su cuenta.
    if (ControlAccesos::esCaseta($usr) && !in_array($accion, [
            'SESION', 'CAMBIAR_MI_CONTRASENA',
            'ACCESO_REGISTRAR', 'LISTAR_ACCESOS', 'LISTAR_ACCESOS_LUGARES', 'ACCESO_ELIMINAR',
            'ACCESO_INCIDENCIA', 'INCIDENCIA_ELIMINAR',
            'ACCESOS_EXISTEN', 'ACCESOS_MI_PUNTO', 'ACCESOS_TARJETA_REGLAS',
        ], true)) {
        respuesta(['status' => 'error', 'message' => 'Esta cuenta sólo puede registrar entradas y salidas.'], 403);
    }

    // Lo que cambia algo va por POST (un enlace no puede borrar ni dar salidas)
    $soloPost = ['CAMBIAR_MI_CONTRASENA', 'CUENTA_CREAR', 'CUENTA_EDITAR', 'ACCESO_REGISTRAR', 'ACCESO_ELIMINAR',
                 'ACCESO_INCIDENCIA', 'INCIDENCIA_ELIMINAR',
                 'ACCESOS_PDF', 'ACCESOS_PDF_DATOS', 'ACCESOS_PUNTO_ASIGNAR', 'ACCESOS_EQUIPO_LIBERAR', 'ACCESOS_DAR_SALIDA'];
    if ($metodo !== 'POST' && in_array($accion, $soloPost, true)) respuesta(['status' => 'error', 'message' => 'Método no permitido.'], 405);

    $esAdmin = ($usr['rol'] ?? '') === 'ADMIN';
    $ca = new ControlAccesos($pdo);
    switch ($accion) {
        // ── Sesión y cuentas ──
        case 'SESION':
            respuesta(['status' => 'success', 'usuario' => $usr['usuario'], 'nombre' => $usr['nombre'], 'rol' => $usr['rol'],
                       'nombre_rol' => Auth::NOMBRES_ROL[$usr['rol']] ?? $usr['rol']]);
        case 'CAMBIAR_MI_CONTRASENA':
            respuesta($auth->cambiarMiContrasena($datos, $usr));
        case 'CUENTAS_LISTAR':
        case 'CUENTA_CREAR':
        case 'CUENTA_EDITAR':
            if (!$esAdmin) respuesta(['status' => 'error', 'message' => 'Sólo Administración AVBA maneja las cuentas.'], 403);
            if ($accion === 'CUENTAS_LISTAR') respuesta($auth->listar());
            if ($accion === 'CUENTA_CREAR') respuesta($auth->crear($datos));
            respuesta($auth->editar($datos, $usr));

        // ── Control de accesos (ControlAccesos.php) ──
        case 'ACCESO_REGISTRAR':       respuesta($ca->registrar($datos, $_FILES['foto'] ?? [], $usr));
        case 'LISTAR_ACCESOS':         respuesta($ca->listar($datos, $usr));
        case 'LISTAR_ACCESOS_LUGARES': respuesta($ca->lugares());
        case 'ACCESO_ELIMINAR':        respuesta($ca->eliminar((int)($datos['id'] ?? 0), $usr));
        // Botón INCIDENCIA de la caseta (alcoholímetro, conducta, problema con la persona)
        case 'ACCESO_INCIDENCIA':      respuesta($ca->registrarIncidencia($datos, $_FILES['foto'] ?? [], $usr));
        case 'INCIDENCIA_ELIMINAR':    respuesta($ca->eliminarIncidencia((int)($datos['id'] ?? 0), $usr));
        case 'ACCESOS_EXISTEN':        respuesta($ca->existen((array)($datos['uids'] ?? [])));
        case 'ACCESOS_MI_PUNTO':       respuesta($ca->miPunto($datos, $usr));
        case 'ACCESOS_TARJETA_REGLAS': respuesta(ControlAccesos::reglasTarjeta());
        case 'ACCESOS_PUNTOS':         respuesta($ca->puntos($usr));
        case 'ACCESOS_PUNTO_ASIGNAR':  respuesta($ca->asignarPunto($datos, $usr));
        case 'ACCESOS_EQUIPO_LIBERAR': respuesta($ca->liberarEquipo($datos, $usr));
        case 'ACCESOS_EN_PLANTA':      respuesta($ca->enPlanta($datos, $usr));
        case 'ACCESOS_PERSONA':        respuesta($ca->persona($datos, $usr));
        case 'ACCESOS_DAR_SALIDA':     respuesta($ca->darSalida($datos, $usr));
        case 'ACCESOS_PDF':            respuesta($ca->generarPdf($datos, $usr));
        case 'ACCESOS_PDF_DATOS':      respuesta($ca->generarPdfDatos($datos, $usr));
    }
    respuesta(['status' => 'error', 'message' => 'Acción no reconocida.'], 400);
} catch (\Throwable $e) {
    error_log('[api] ' . $accion . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    respuesta(['status' => 'error', 'message' => 'Ocurrió un error en el servidor. Inténtalo de nuevo.'], 500);
}
