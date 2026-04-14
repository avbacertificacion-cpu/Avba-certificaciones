<?php
/**
 * AVBA Certificaciones — API Principal
 * Punto de entrada único para todas las peticiones del frontend.
 *
 * GET  /api/?action=XXX         → acciones de lectura
 * POST /api/                     → acciones de escritura (JSON body)
 */

// ── Bootstrap ─────────────────────────────────────────────
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Inspecciones.php';
require_once __DIR__ . '/Calidad.php';
require_once __DIR__ . '/Certificaciones.php';
require_once __DIR__ . '/ValidarQR.php';
require_once __DIR__ . '/Admin.php';
require_once __DIR__ . '/Personal.php';

// ── Headers ───────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── PDO + módulos ─────────────────────────────────────────
$pdo   = Database::getConnection();
$auth     = new Auth($pdo);
$insp     = new Inspecciones($pdo);
$cal      = new Calidad($pdo);
$cert     = new Certificaciones($pdo);
$qr       = new ValidarQR($pdo);
$admin    = new Admin($pdo);
$personal = new Personal($pdo);

// ── Extraer token ─────────────────────────────────────────
$token = null;
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_TOKEN'] ?? '';
if (preg_match('/Bearer\s+(.+)/i', $authHeader, $m)) {
    $token = $m[1];
}

// ── Leer body (POST) ──────────────────────────────────────
$body   = [];
$raw    = file_get_contents('php://input');
if ($raw) {
    $body = json_decode($raw, true) ?? [];
}
// Soporte multipart/form-data (subida de archivos)
if (empty($body) && !empty($_POST)) {
    $body = $_POST;
}
if (!$token && isset($body['token'])) {
    $token = $body['token'];
}

// ── Determinar método y acción ────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$action = '';

if ($method === 'GET') {
    $action = $_GET['action'] ?? '';
    if (!$token && isset($_GET['token'])) {
        $token = $_GET['token'];
    }
} else {
    $action  = $body['action']  ?? '';
    $payload = $body['payload'] ?? $body;
}

// ══════════════════════════════════════════════════════════
//  RUTAS GET (lectura pública o autenticada)
// ══════════════════════════════════════════════════════════
if ($method === 'GET') {

    switch ($action) {

        // Validación pública de QR / Folio
        case 'VALIDAR_QR':
            respuesta($qr->validarQR($_GET['qr'] ?? ''));

        // Opciones de maquinaria para el selector
        case 'getMaquinaria':
        case 'GET_MAQUINARIA':
            respuesta($insp->obtenerOpcionesMaquinaria());

        // Checklist por tipo de equipo
        case 'getChecklist':
        case 'GET_CHECKLIST':
            respuesta($insp->obtenerChecklistPorEquipo($_GET['equipo'] ?? ''));

        // Panel de Calidad
        case 'getDataCalidad':
        case 'GET_DATA_CALIDAD':
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($cal->getDataCalidad());

        // Panel de Certificaciones
        case 'getDataCertificaciones':
        case 'GET_DATA_CERTIFICACIONES':
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($cert->obtenerDataCertificaciones());

        // Listado de usuarios (ADMIN)
        case 'LISTAR_USUARIOS':
            $usr = validarToken($pdo, $token);
            if (!$usr || $usr['rol'] !== 'ADMIN') respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($auth->listarUsuarios());

        // Tipos de equipo (ADMIN + CALIDAD)
        case 'LISTAR_TIPOS_EQUIPO':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($admin->listarTiposEquipo());

        // Inspector: historial propio
        case 'GET_MIS_INSPECCIONES':
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($insp->getMisInspecciones($usr['usuario']));

        // Personal: catálogos públicos (autenticados)
        case 'LISTAR_CURSOS':
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($personal->listarCursos());

        case 'LISTAR_OCUPACIONES':
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($personal->listarOcupaciones());

        case 'LISTAR_OCUPACIONES_ADMIN':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($personal->listarOcupacionesAdmin());

        case 'LISTAR_PARTICIPANTES':
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($personal->listarParticipantes([
                'curso_id' => $_GET['curso_id'] ?? '',
                'buscar'   => $_GET['buscar']   ?? '',
            ]));

        case 'OBTENER_PARTICIPANTE':
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            $p = $personal->obtenerParticipante((int)($_GET['id'] ?? 0));
            respuesta($p ? $p : ['status' => 'error', 'message' => 'No encontrado.'], $p ? 200 : 404);

        default:
            respuesta(['status' => 'error', 'message' => "Acción GET desconocida: {$action}"], 400);
    }
}

// ══════════════════════════════════════════════════════════
//  RUTAS POST (escritura)
// ══════════════════════════════════════════════════════════
if ($method === 'POST') {

    switch ($action) {

        // ── Auth ─────────────────────────────────────────
        case 'LOGIN':
            respuesta($auth->login($payload));

        case 'CREAR_USUARIO':
            $usr = validarToken($pdo, $token);
            if (!$usr || $usr['rol'] !== 'ADMIN') respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($auth->crearUsuario($payload));

        case 'EDITAR_USUARIO':
            $usr = validarToken($pdo, $token);
            if (!$usr || $usr['rol'] !== 'ADMIN') respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($auth->editarUsuario($payload, $usr['usuario']));

        case 'DESACTIVAR_USUARIO':
            $usr = validarToken($pdo, $token);
            if (!$usr || $usr['rol'] !== 'ADMIN') respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($auth->desactivarUsuario($payload));

        case 'OBTENER_DATOS_CLIENTE':
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            $idCliente = $payload['id_cliente'] ?? ($usr['id_cliente'] ?? '');
            respuesta($auth->obtenerDatosCliente($idCliente));

        // ── Inspector ────────────────────────────────────
        case 'NUEVA_INSPECCION':
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($insp->guardarInspeccion($payload, $usr['usuario']));

        // ── Calidad ──────────────────────────────────────
        case 'VALIDAR_CALIDAD':
        case 'APROBAR_CALIDAD':
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($cal->aprobarCalidad($payload, $usr['usuario']));

        case 'ACTUALIZAR_CALIDAD':
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($cal->actualizarCalidad($payload, $usr['usuario']));

        case 'RECHAZAR_CALIDAD':
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($cal->rechazarCalidad($payload, $usr['usuario']));

        // ── Certificaciones ───────────────────────────────
        case 'IMPRIMIR_PDF_CERT':
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($cert->imprimirPDF((int)($payload['id'] ?? $payload['fila'] ?? 0), 'cert'));

        case 'IMPRIMIR_PDF_DICT':
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($cert->imprimirPDF((int)($payload['id'] ?? $payload['fila'] ?? 0), 'dict'));

        case 'DESCARGAR_DOCX_CERT':
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($cert->descargarDocx((int)($payload['id'] ?? $payload['fila'] ?? 0), 'cert'));

        case 'DESCARGAR_DOCX_DICT':
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($cert->descargarDocx((int)($payload['id'] ?? $payload['fila'] ?? 0), 'dict'));

        case 'GENERAR_CERT_ENVIAR':
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($cert->generarCertEnviar($payload, $usr['usuario']));

        case 'GENERAR_DICT_ENVIAR':
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($cert->generarDictEnviar($payload, $usr['usuario']));

        case 'GENERAR_TODO_ENVIAR':
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($cert->generarTodoEnviar($payload, $usr['usuario']));

        case 'RECHAZAR_CERTIFICACION':
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($cert->rechazarACertificacion($payload, $usr['usuario']));

        case 'GUARDAR_ENVIO_CERT':
        case 'GUARDAR_ENVIO':
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($cert->guardarEnvioCert($payload));

        // ── Admin: tipos de equipo ────────────────────────
        case 'CREAR_TIPO_EQUIPO':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($admin->crearTipoEquipo($payload));

        case 'EDITAR_TIPO_EQUIPO':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($admin->editarTipoEquipo($payload));

        case 'ELIMINAR_TIPO_EQUIPO':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($admin->eliminarTipoEquipo($payload));

        case 'AGREGAR_SECCION':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($admin->agregarSeccion($payload));

        case 'EDITAR_SECCION':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($admin->editarSeccion($payload));

        case 'ELIMINAR_SECCION':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($admin->eliminarSeccion($payload));

        case 'GUARDAR_ITEM_CHECKLIST':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($admin->guardarItemChecklist($payload));

        case 'ELIMINAR_ITEM_CHECKLIST':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($admin->eliminarItemChecklist($payload));

        case 'REGENERAR_REPORTE':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($admin->regenerarReporte($payload, $usr['usuario']));

        // Subir plantilla Word para certificado/dictamen (multipart/form-data)
        case 'SUBIR_PLANTILLA':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($admin->subirPlantilla($payload, $_FILES));

        // ── Personal / Cursos ─────────────────────────────
        case 'GUARDAR_PARTICIPANTE':
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($personal->guardarParticipante($payload, $_FILES, $usr['usuario']));

        case 'ELIMINAR_PARTICIPANTE':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($personal->eliminarParticipante((int)($payload['id'] ?? 0)));

        case 'GUARDAR_CURSO':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($personal->guardarCurso($payload, $usr['usuario']));

        case 'ELIMINAR_CURSO':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($personal->eliminarCurso((int)($payload['id'] ?? 0)));

        case 'GUARDAR_OCUPACION':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($personal->guardarOcupacion($payload));

        case 'ELIMINAR_OCUPACION':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($personal->eliminarOcupacion((int)($payload['id'] ?? 0)));

        case 'GENERAR_DOC_PERSONAL':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($personal->generarDocumento(
                (int)($payload['id']   ?? 0),
                trim($payload['tipo']  ?? ''),
                $usr['usuario']
            ));

        default:
            respuesta(['status' => 'error', 'message' => "Acción POST desconocida: {$action}"], 400);
    }
}

// Método no soportado
respuesta(['status' => 'error', 'message' => 'Método no soportado.'], 405);
