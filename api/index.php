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

// Un espacio o salto de línea fuera de las etiquetas de PHP en config.php no debe colarse en las respuestas
ob_start();

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
require_once __DIR__ . '/Tablero.php';
require_once __DIR__ . '/Personal.php';

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
            'PADRON_CAJA', 'PERMISO_AGREGAR', 'PERMISO_QUITAR',
        ], true)) {
        respuesta(['status' => 'error', 'message' => 'Esta cuenta sólo puede registrar entradas y salidas.'], 403);
    }
    // El servicio médico sólo toma la tensión arterial (con la copia de la base y las reglas del lector)
    if (Medico::esMedico($usr) && !in_array($accion, [
            'SESION', 'CAMBIAR_MI_CONTRASENA', 'PADRON_CAJA', 'ACCESOS_TARJETA_REGLAS',
            'MEDICO_REGISTRAR', 'MEDICO_EXISTEN', 'MEDICO_LISTAR', 'MEDICO_PERSONA', 'MEDICO_REGLAS',
        ], true)) {
        respuesta(['status' => 'error', 'message' => 'Esta cuenta sólo registra tomas del servicio médico.'], 403);
    }
    // El cliente sólo consulta el tablero y descarga sus reportes (también los del servicio médico)
    if (ControlAccesos::esCliente($usr) && !in_array($accion, [
            'SESION', 'CAMBIAR_MI_CONTRASENA', 'TABLERO', 'TABLERO_EXCEL', 'TABLERO_PDF', 'MEDICO_TABLERO', 'MEDICO_EXCEL', 'MEDICO_PDF',
        ], true)) {
        respuesta(['status' => 'error', 'message' => 'Esta cuenta sólo consulta el tablero y sus reportes.'], 403);
    }

    // Lo que cambia algo va por POST (un enlace no puede borrar ni dar salidas)
    $soloPost = ['CAMBIAR_MI_CONTRASENA', 'CUENTA_CREAR', 'CUENTA_EDITAR', 'ACCESO_REGISTRAR', 'ACCESO_ELIMINAR',
                 'ACCESO_INCIDENCIA', 'INCIDENCIA_ELIMINAR',
                 'ACCESOS_PDF', 'ACCESOS_PDF_DATOS', 'ACCESOS_DAR_SALIDA', 'TABLERO_EXCEL', 'TABLERO_PDF',
                 'PADRON_GUARDAR', 'PADRON_ELIMINAR', 'PADRON_IMPORTAR', 'PADRON_EXCEL', 'PERMISO_AGREGAR', 'PERMISO_QUITAR',
                 'OMITIR', 'OMITIDO_QUITAR', 'MEDICO_REGISTRAR', 'MEDICO_EXCEL', 'MEDICO_PDF'];
    if ($metodo !== 'POST' && in_array($accion, $soloPost, true)) respuesta(['status' => 'error', 'message' => 'Método no permitido.'], 405);

    $esAdmin = ($usr['rol'] ?? '') === 'ADMIN';
    $ca = new ControlAccesos($pdo);
    switch ($accion) {
        // ── Sesión y cuentas ──
        case 'SESION':
            respuesta(['status' => 'success', 'usuario' => $usr['usuario'], 'nombre' => $usr['nombre'], 'rol' => $usr['rol'],
                       'turno' => ControlAccesos::turnoCuenta($usr), 'modo' => ControlAccesos::modoCuenta($usr),
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
        case 'ACCESOS_EN_PLANTA':      respuesta($ca->enPlanta($datos, $usr));
        case 'ACCESOS_PERSONA':        respuesta($ca->persona($datos, $usr));
        case 'ACCESOS_DAR_SALIDA':     respuesta($ca->darSalida($datos, $usr));
        case 'ACCESOS_PDF':            respuesta($ca->generarPdf($datos, $usr));
        case 'ACCESOS_PDF_DATOS':      respuesta($ca->generarPdfDatos($datos, $usr));

        // ── Base del personal y permisos (Personal.php). La caseta consulta su copia y agrega permisos ──
        case 'PADRON_CAJA':       respuesta((new Personal($pdo))->caja($datos, $usr));
        case 'PERMISO_AGREGAR':   respuesta((new Personal($pdo))->permisoAgregar($datos, $_FILES['foto'] ?? [], $usr));
        case 'PERMISO_QUITAR':    respuesta((new Personal($pdo))->permisoQuitar($datos, $usr));
        case 'PADRON_LISTAR':     respuesta((new Personal($pdo))->listar($datos, $usr));
        case 'PADRON_FICHA':      respuesta((new Personal($pdo))->ficha($datos, $usr));
        case 'PADRON_FALTANTES':  respuesta((new Personal($pdo))->faltantes($datos, $usr));
        case 'PADRON_GUARDAR':    respuesta((new Personal($pdo))->guardar($datos, $usr));
        case 'PADRON_ELIMINAR':   respuesta((new Personal($pdo))->eliminar($datos, $usr));
        case 'PADRON_IMPORTAR':   respuesta((new Personal($pdo))->importar($datos, $_FILES['archivo'] ?? [], $usr));
        case 'PADRON_EXCEL':      respuesta((new Personal($pdo))->excel($usr));

        // ── Días omitidos (Omitidos.php): lo de las pruebas no cuenta. Sólo Administración AVBA ──
        case 'OMITIDOS_LISTAR':   respuesta((new Omitidos($pdo))->listar($usr));
        case 'OMITIR':            respuesta((new Omitidos($pdo))->omitir($datos, $usr));
        case 'OMITIDO_QUITAR':    respuesta((new Omitidos($pdo))->quitar($datos, $usr));

        // ── Servicio médico (Medico.php): tomas de tensión arterial ──
        case 'MEDICO_REGLAS':     respuesta(Medico::reglasPublicas());
        case 'MEDICO_REGISTRAR':  respuesta((new Medico($pdo))->registrar($datos, $usr));
        case 'MEDICO_EXISTEN':    respuesta((new Medico($pdo))->existen((array)($datos['uids'] ?? [])));
        case 'MEDICO_LISTAR':     respuesta((new Medico($pdo))->listar($datos, $usr));
        case 'MEDICO_PERSONA':    respuesta((new Medico($pdo))->persona($datos, $usr));
        case 'MEDICO_TABLERO':
        case 'MEDICO_EXCEL':
        case 'MEDICO_PDF':
            if (!Tablero::puede($usr)) respuesta(['status' => 'error', 'message' => 'Tu cuenta no tiene acceso al tablero.'], 403);
            $md = new Medico($pdo);
            if ($accion === 'MEDICO_TABLERO') respuesta($md->tablero($datos, $usr));
            if ($accion === 'MEDICO_EXCEL') respuesta($md->excel($datos, $usr));
            respuesta($md->pdf($datos, $usr));

        // ── Tablero (Tablero.php): gráficas, Excel y PDF. Cliente, planta y Administración ──
        case 'TABLERO':
        case 'TABLERO_EXCEL':
        case 'TABLERO_PDF':
            if (!Tablero::puede($usr)) respuesta(['status' => 'error', 'message' => 'Tu cuenta no tiene acceso al tablero.'], 403);
            $tb = new Tablero($pdo);
            if ($accion === 'TABLERO') respuesta($tb->datos($datos, $usr));
            if ($accion === 'TABLERO_EXCEL') respuesta($tb->excel($datos, $usr));
            respuesta($tb->pdf($datos, $usr));
    }
    respuesta(['status' => 'error', 'message' => 'Acción no reconocida.'], 400);
} catch (\Throwable $e) {
    error_log('[api] ' . $accion . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    respuesta(['status' => 'error', 'message' => 'Ocurrió un error en el servidor. Inténtalo de nuevo.'], 500);
}
