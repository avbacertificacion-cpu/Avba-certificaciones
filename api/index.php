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
require_once __DIR__ . '/Accesorios.php';

// ── Headers ───────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');

// CORS: solo orígenes explícitamente permitidos
$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = array_map('trim', explode(',', defined('CORS_ORIGINS') ? CORS_ORIGINS : ''));
if ($requestOrigin && in_array($requestOrigin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $requestOrigin);
    header('Vary: Origin');
} elseif (!$requestOrigin) {
    // Misma origen (sin header Origin) — petición directa del servidor o mismo dominio
    header('Access-Control-Allow-Origin: ' . ($allowedOrigins[0] ?? '*'));
}
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
$accesorios = new Accesorios($pdo);

// ── Extraer token ─────────────────────────────────────────
$token = null;
// Authorization: Bearer <token>  (directo o via REDIRECT_ en algunos configs de Apache)
$authHeader = $_SERVER['HTTP_AUTHORIZATION']
    ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
    ?? '';
if (preg_match('/Bearer\s+(.+)/i', $authHeader, $m)) {
    $token = trim($m[1]);
}
// X-Token: <token>  (header personalizado, sin prefijo Bearer)
if (!$token && !empty($_SERVER['HTTP_X_TOKEN'])) {
    $token = trim($_SERVER['HTTP_X_TOKEN']);
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
set_exception_handler(function(Throwable $e) {
    error_log('[AVBA] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
    }
    echo json_encode(['status' => 'error', 'message' => 'Error interno del servidor.']);
    exit;
});

if ($method === 'GET') {

    switch ($action) {

        // Validación pública de QR / Folio
        case 'VALIDAR_QR':
            respuesta($qr->validarQR($_GET['qr'] ?? ''));

        // Listar imágenes de carpeta de evidencia
        case 'LISTAR_EVIDENCIAS': {
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            $url = $_GET['url'] ?? '';
            if (!$url) respuesta(['status' => 'error', 'message' => 'url requerido.']);
            // Convertir URL a ruta local y listar imágenes
            $localPath = rtrim(str_replace(
                rtrim(UPLOAD_URL, '/'),
                rtrim(UPLOAD_DIR, '/'),
                rtrim($url, '/')
            ), '/') . '/';
            $realPath  = realpath($localPath);
            $realBase  = realpath(UPLOAD_DIR);
            if (!$realPath || !$realBase || strncmp($realPath, $realBase, strlen($realBase)) !== 0) {
                respuesta(['status' => 'success', 'imagenes' => []]);
            }
            $archivos = glob($realPath . '*.{jpg,jpeg,png,JPG,JPEG,PNG,webp,WEBP}', GLOB_BRACE) ?: [];
            sort($archivos);
            $imagenes = array_map(fn($f) => str_replace(
                rtrim(UPLOAD_DIR, '/'),
                rtrim(UPLOAD_URL, '/'),
                $f
            ), array_slice($archivos, 0, 30));
            respuesta(['status' => 'success', 'imagenes' => array_values($imagenes)]);
        }

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

        case 'LISTAR_QR_INFO':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($cal->listarQrInfo($_GET['filtro'] ?? 'todos'));

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

        case 'GET_TODAS_INSPECCIONES':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($insp->getTodasInspecciones());

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
                'estatus'  => $_GET['estatus']  ?? '',
            ]));

        case 'OBTENER_PARTICIPANTE':
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            $p = $personal->obtenerParticipante((int)($_GET['id'] ?? 0));
            respuesta($p ? $p : ['status' => 'error', 'message' => 'No encontrado.'], $p ? 200 : 404);

        case 'LISTAR_TIPOS_ACCESORIO':
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($accesorios->listarTipos());

        case 'LISTAR_TIPOS_ACCESORIO_ADMIN':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($accesorios->listarTiposAdmin());

        case 'OBTENER_CAMPOS_PDF':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($admin->obtenerCamposPdf((int)($_GET['tipo_id'] ?? 0)));

        case 'LISTAR_PLANTILLAS_PERSONAL':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($admin->listarPlantillasPersonal());

        case 'OBTENER_CAMPOS_PDF_PERSONAL':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($admin->obtenerCamposPdfPersonal($_GET['tipo'] ?? ''));

        case 'LISTAR_SESIONES_ACCESORIOS':
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($accesorios->listarSesiones($_GET['estatus'] ?? ''));

        case 'DETALLE_SESION_ACCESORIOS':
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($accesorios->detalleSesion((int)($_GET['id'] ?? 0)));

        case 'OBTENER_PLANTILLA_ACC':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($accesorios->obtenerPlantillaAcc());

        case 'OBTENER_CAMPOS_PDF_ACC':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($accesorios->obtenerCamposPdfAcc());

        default:
            respuesta(['status' => 'error', 'message' => 'Acción no reconocida.'], 400);
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

        case 'SUBIR_FIRMA':
            $usr = validarToken($pdo, $token);
            if (!$usr || $usr['rol'] !== 'ADMIN') respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($auth->subirFirma((int)($_POST['usuario_id'] ?? 0), $_FILES['firma'] ?? []));

        case 'ELIMINAR_FIRMA':
            $usr = validarToken($pdo, $token);
            if (!$usr || $usr['rol'] !== 'ADMIN') respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($auth->eliminarFirma((int)($payload['usuario_id'] ?? 0)));

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

        case 'GENERAR_QR_LOTE':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($cal->generarQrLote($payload));

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

        case 'GENERAR_PDF_WORD_CERT':
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($cert->generarPdfDesdeWord((int)($payload['id'] ?? $payload['fila'] ?? 0), 'cert'));

        case 'GENERAR_PDF_WORD_DICT':
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($cert->generarPdfDesdeWord((int)($payload['id'] ?? $payload['fila'] ?? 0), 'dict'));

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

        // Subir plantilla PDF (multipart/form-data)
        case 'SUBIR_PLANTILLA_PDF':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($admin->subirPlantillaPdf($_POST, $_FILES));

        // Guardar coordenadas de campos para plantilla PDF
        case 'GUARDAR_CAMPOS_PDF':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($admin->guardarCamposPdf($payload));

        // Vista previa de plantilla PDF con datos del primer registro
        case 'PREVISUALIZAR_PDF':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($cert->previsualizarPdf(
                (int)($payload['tipo_id']  ?? 0),
                (string)($payload['doc_tipo'] ?? 'cert'),
                (array)($payload['campos']  ?? [])
            ));

        // ── Personal: plantillas PDF ─────────────────────────
        case 'SUBIR_PLANTILLA_PDF_PERSONAL':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($admin->subirPlantillaPdfPersonal($_POST, $_FILES));

        case 'GUARDAR_CAMPOS_PDF_PERSONAL':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($admin->guardarCamposPdfPersonal($payload));

        case 'PREVISUALIZAR_PDF_PERSONAL':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($cert->previsualizarPdfPersonal(
                (string)($payload['tipo']   ?? ''),
                (array) ($payload['campos'] ?? [])
            ));

        case 'ENVIAR_DOC_PERSONAL':
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($personal->enviarDocumento(
                (int)   ($payload['id']     ?? 0),
                (string)($payload['tipo']   ?? ''),
                (string)($payload['correo'] ?? ''),
                $usr['usuario']
            ));

        // ── Personal / Cursos ─────────────────────────────
        case 'GUARDAR_PARTICIPANTE':
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($personal->guardarParticipante($payload, $_FILES, $usr['usuario']));

        case 'ELIMINAR_PARTICIPANTE':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($personal->eliminarParticipante((int)($payload['id'] ?? 0)));

        case 'APROBAR_PARTICIPANTE':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($personal->aprobarParticipante((int)($payload['id'] ?? 0), $usr['usuario'], trim($payload['qr'] ?? '')));

        case 'DEVOLVER_PARTICIPANTE':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD','CERTIFICACIONES'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($personal->devolverParticipante((int)($payload['id'] ?? 0), $usr['usuario']));

        case 'EMITIR_DOC_PERSONAL':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CERTIFICACIONES'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($personal->emitirDocumentoPersonal(
                (int)   ($payload['id']     ?? 0),
                (string)($payload['tipo']   ?? ''),
                (string)($payload['correo'] ?? ''),
                $usr['usuario']
            ));

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
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD','CERTIFICACIONES'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($personal->generarDocumento(
                (int)($payload['id']   ?? 0),
                trim($payload['tipo']  ?? ''),
                $usr['usuario']
            ));

        case 'CREAR_TIPO_ACCESORIO':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($accesorios->crearTipo($payload));

        case 'EDITAR_TIPO_ACCESORIO':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($accesorios->editarTipo($payload));

        case 'ELIMINAR_TIPO_ACCESORIO':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($accesorios->eliminarTipo($payload));

        case 'CREAR_SESION_ACCESORIOS':
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($accesorios->crearSesion($payload, $usr['usuario']));

        case 'GUARDAR_ACCESORIO':
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($accesorios->guardarAccesorio($_POST, $_FILES, $usr['usuario']));

        case 'EDITAR_ACCESORIO':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($accesorios->editarAccesorio($payload));

        case 'APROBAR_SESION_ACC':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($accesorios->aprobarSesion((int)($payload['id'] ?? 0), $usr['usuario'], trim($payload['qr'] ?? '')));

        case 'DEVOLVER_SESION_ACC':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD','CERTIFICACIONES'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($accesorios->devolverSesion((int)($payload['id'] ?? 0), $usr['usuario']));

        case 'EMITIR_INFORME_ACC':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CERTIFICACIONES'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($accesorios->emitirInforme((int)($payload['sesion_id'] ?? 0), $usr['usuario']));

        case 'SUBIR_PLANTILLA_ACC':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($accesorios->subirPlantillaAcc($_POST, $_FILES));

        case 'ELIMINAR_PLANTILLA_ACC':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($accesorios->eliminarPlantillaAcc());

        case 'GUARDAR_CAMPOS_PDF_ACC':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($accesorios->guardarCamposPdfAcc($payload));

        case 'PREVISUALIZAR_CERT_ACC':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD','CERTIFICACIONES'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($accesorios->previsualizarCertAcc($payload));

        case 'GENERAR_CERT_ACC':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD','CERTIFICACIONES'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($accesorios->generarCertAcc((int)($payload['sesion_id'] ?? 0), $usr['usuario']));

        case 'EMITIR_CERT_ACC':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CERTIFICACIONES'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($accesorios->emitirCertAcc((int)($payload['sesion_id'] ?? 0), $usr['usuario']));

        case 'ENVIAR_CERT_ACC':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CERTIFICACIONES'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($accesorios->enviarCertAcc(
                (int)   ($payload['sesion_id'] ?? 0),
                (string)($payload['correo']    ?? ''),
                $usr['usuario']
            ));

        case 'ENVIAR_INFORME_ACC':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CERTIFICACIONES'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($accesorios->enviarInformeAcc(
                (int)   ($payload['sesion_id'] ?? 0),
                (string)($payload['correo']    ?? ''),
                $usr['usuario']
            ));

        case 'GENERAR_INFORME_CUMPLE':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD','CERTIFICACIONES'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($accesorios->generarInformeCumple((int)($payload['sesion_id'] ?? 0), $usr['usuario']));

        case 'ENVIAR_INFORME_CUMPLE':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CERTIFICACIONES'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($accesorios->enviarInformeCumple(
                (int)   ($payload['sesion_id'] ?? 0),
                (string)($payload['correo']    ?? ''),
                $usr['usuario']
            ));

        case 'PREVISUALIZAR_INFORME_ACC':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD','CERTIFICACIONES'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($accesorios->previsualizarInformeAcc($usr['usuario']));

        case 'GENERAR_INFORME_ACCESORIOS':
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($accesorios->generarInforme((int)($payload['sesion_id'] ?? 0), $usr['usuario']));

        default:
            respuesta(['status' => 'error', 'message' => "Acción POST desconocida: {$action}"], 400);
    }
}

// Método no soportado
respuesta(['status' => 'error', 'message' => 'Método no soportado.'], 405);
