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
require_once __DIR__ . '/Pnd.php';
require_once __DIR__ . '/ClienteEquipos.php';
require_once __DIR__ . '/ClientePersonal.php';

// ── Headers de seguridad ──────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Eliminar header que expone versión de PHP
header_remove('X-Powered-By');

// CORS: solo orígenes explícitamente permitidos
$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = array_map('trim', explode(',', defined('CORS_ORIGINS') ? CORS_ORIGINS : ''));
if ($requestOrigin && in_array($requestOrigin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $requestOrigin);
    header('Vary: Origin');
} elseif (!$requestOrigin) {
    // Misma origen (sin header Origin)
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
$accesorios     = new Accesorios($pdo);
$pnd            = new Pnd($pdo);
$cliEquipos     = new ClienteEquipos($pdo);
$cliPersonal    = new ClientePersonal($pdo);

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

            // Estrategia 1: extraer la porción relativa usando el path de UPLOAD_URL
            // (ignora protocolo y host, resuelve diferencias http/https)
            $uploadUrlPath = rtrim(parse_url(UPLOAD_URL, PHP_URL_PATH) ?: '', '/') . '/';
            $urlPath       = rtrim(parse_url($url,       PHP_URL_PATH) ?: $url, '/') . '/';
            $relPath       = '';
            if ($uploadUrlPath !== '/' && strpos($urlPath, $uploadUrlPath) === 0) {
                $relPath = trim(substr($urlPath, strlen($uploadUrlPath)), '/');
            }
            // Estrategia 2 (fallback): tomar solo el último segmento del path
            if (!$relPath) {
                $relPath = basename(rtrim(parse_url($url, PHP_URL_PATH) ?: $url, '/'));
            }

            // Seguridad: sin traversal ni caracteres nulos
            if (!$relPath || strpos($relPath, '..') !== false || strpos($relPath, "\0") !== false) {
                respuesta(['status' => 'success', 'imagenes' => []]);
            }

            $localPath = rtrim(UPLOAD_DIR, '/') . '/' . $relPath . '/';
            $dirExists = is_dir($localPath);
            $debug     = ['url' => $url, 'relPath' => $relPath, 'localPath' => $localPath, 'dirExists' => $dirExists];

            // Usar is_dir() en lugar de realpath() para no fallar con symlinks
            if (!$dirExists) {
                respuesta(['status' => 'success', 'imagenes' => [], '_debug' => $debug]);
            }

            // Verificación de seguridad (solo falla si realpath devuelve algo fuera de UPLOAD_DIR)
            $realPath = realpath($localPath);
            $realBase = realpath(UPLOAD_DIR);
            if ($realPath && $realBase && strncmp($realPath, $realBase, strlen($realBase)) !== 0) {
                respuesta(['status' => 'success', 'imagenes' => [], '_debug' => $debug]);
            }

            $archivos = glob($localPath . '*.{jpg,jpeg,png,JPG,JPEG,PNG,webp,WEBP}', GLOB_BRACE) ?: [];
            sort($archivos);
            $imagenes = array_map(fn($f) => str_replace(
                rtrim(UPLOAD_DIR, '/'),
                rtrim(UPLOAD_URL, '/'),
                $f
            ), array_slice($archivos, 0, 30));
            $debug['archivos'] = count($archivos);
            respuesta(['status' => 'success', 'imagenes' => array_values($imagenes), '_debug' => $debug]);
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

        case 'GET_NEXT_QR_ACC':
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($accesorios->getSiguienteQrAcc());

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

        case 'GET_MIS_ACCESORIOS':
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($accesorios->getMisSesiones($usr['usuario']));

        case 'GET_MIS_CURSOS':
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($personal->listarParticipantes(['inspector' => $usr['usuario']]));

        // PND: inspector — historial propio
        case 'GET_MIS_PND':
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta(['status' => 'success', 'data' => $pnd->getMisInspecciones($usr['usuario'])]);

        // PND: calidad — todos los registros
        case 'GET_DATA_PND':
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta(['status' => 'success', 'data' => $pnd->getDataCalidad()]);

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

        case 'LISTAR_EMPRESAS_DC3':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($personal->listarEmpresas());

        case 'LISTAR_USUARIOS_INSTRUCTOR':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($personal->listarUsuariosInstructor());

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

        // ── OCR: extraer nombre/CURP desde foto del documento ─────
        case 'OCR_EXTRAER_PARTICIPANTE': {
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD']))
                respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);

            $id = (int)($_GET['id'] ?? 0);
            if (!$id) respuesta(['status' => 'error', 'message' => 'ID requerido.']);

            $p = $personal->obtenerParticipante($id);
            if (!$p) respuesta(['status' => 'error', 'message' => 'Participante no encontrado.'], 404);

            $fotoUrl = $p['foto_documentacion_url'] ?? '';
            if (!$fotoUrl) respuesta(['status' => 'error', 'message' => 'Este participante no tiene foto del documento.']);

            if (!function_exists('curl_init'))
                respuesta(['status' => 'error', 'message' => 'cURL no disponible en el servidor.']);

            // Descargar la imagen del documento
            $ch = curl_init($fotoUrl);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => true]);
            $imgData = curl_exec($ch);
            $curlErr = curl_error($ch);
            curl_close($ch);
            if ($imgData === false || !$imgData)
                respuesta(['status' => 'error', 'message' => 'No se pudo descargar la imagen: ' . $curlErr]);

            // Guardar temporalmente
            $tmpFile = sys_get_temp_dir() . '/avba_ocr_' . $id . '_' . time() . '.jpg';
            file_put_contents($tmpFile, $imgData);

            // Enviar a OCR.space
            $apiKey = defined('OCR_API_KEY') ? OCR_API_KEY : 'helloworld';
            $ch2 = curl_init('https://api.ocr.space/parse/image');
            curl_setopt_array($ch2, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_POSTFIELDS     => [
                    'apikey'            => $apiKey,
                    'language'          => 'spa',
                    'isOverlayRequired' => 'false',
                    'detectOrientation' => 'true',
                    'scale'             => 'true',
                    'file'              => new CURLFile($tmpFile, 'image/jpeg', 'documento.jpg'),
                ],
            ]);
            $ocrRaw = curl_exec($ch2);
            curl_close($ch2);
            @unlink($tmpFile);

            if ($ocrRaw === false) respuesta(['status' => 'error', 'message' => 'Error al conectar con OCR.']);

            $ocrData = json_decode($ocrRaw, true);
            if (!empty($ocrData['IsErroredOnProcessing'])) {
                $msg = is_array($ocrData['ErrorMessage'] ?? '') ? implode('; ', $ocrData['ErrorMessage']) : ($ocrData['ErrorMessage'] ?? 'Error OCR');
                respuesta(['status' => 'error', 'message' => $msg]);
            }

            $texto = strtoupper($ocrData['ParsedResults'][0]['ParsedText'] ?? '');
            if (!$texto) respuesta(['status' => 'error', 'message' => 'No se pudo leer texto en la imagen.']);

            // Extraer CURP
            $curp = '';
            if (preg_match('/[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d/', $texto, $m)) {
                $curp = $m[0];
            }

            // Extraer nombre (CURP doc RENAPO: PRIMER APELLIDO + SEGUNDO APELLIDO + NOMBRE)
            $nombre = '';
            $ap1 = $ap2 = $nom = '';
            if (preg_match('/PRIMER\s+APELLIDO[^:]*:\s*([A-ZÁÉÍÓÚÑ][A-ZÁÉÍÓÚÑ\s]{1,25})/u', $texto, $m)) $ap1 = trim($m[1]);
            if (preg_match('/SEGUNDO\s+APELLIDO[^:]*:\s*([A-ZÁÉÍÓÚÑ][A-ZÁÉÍÓÚÑ\s]{1,25})/u', $texto, $m)) $ap2 = trim($m[1]);
            if (preg_match('/NOMBRES?[^:]*:\s*\n?\s*([A-ZÁÉÍÓÚÑ][A-ZÁÉÍÓÚÑ\s]{2,40})/u', $texto, $m)) $nom = trim($m[1]);
            if ($ap1 && $nom)  $nombre = trim("$ap1 $ap2 $nom");
            elseif ($nom)      $nombre = $nom;
            // Title case
            if ($nombre) {
                $nombre = implode(' ', array_map(fn($w) => mb_strtoupper(mb_substr($w,0,1)) . mb_strtolower(mb_substr($w,1)), explode(' ', $nombre)));
            }

            respuesta(['status' => 'success', 'curp' => $curp, 'nombre' => $nombre, 'texto_ocr' => $texto]);
        }

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

        case 'BUSCAR_ACCESORIOS': {
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            $idClienteBusq = '';
            if ($usr['rol'] === 'CLIENTE') {
                $idClienteBusq = trim($usr['id_cliente'] ?? '');
                if ($idClienteBusq && ctype_digit($idClienteBusq))
                    $idClienteBusq = str_pad($idClienteBusq, 5, '0', STR_PAD_LEFT);
            }
            respuesta($accesorios->buscarAccesorios($_GET['q'] ?? '', $_GET['estatus'] ?? '', $idClienteBusq));
        }

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

        case 'GET_SMTP_CONFIG':
            $usr = validarToken($pdo, $token);
            if (!$usr || $usr['rol'] !== 'ADMIN') respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($admin->getSmtpConfig());

        // Buscar empresa/cliente en la BD (para autocomplete inspector)
        case 'BUSCAR_CLIENTE': {
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            $q = trim($_GET['q'] ?? '');
            if (strlen($q) < 2) respuesta(['status' => 'success', 'clientes' => []]);
            $stmt = $pdo->prepare(
                "SELECT nombre_cliente, primera_parte,
                        COALESCE(rfc,'') AS rfc,
                        COALESCE(representante,'') AS representante,
                        COALESCE(direccion,'') AS direccion,
                        COALESCE(representante_trabajadores,'') AS representante_trabajadores
                 FROM clientes
                 WHERE nombre_cliente LIKE ? ORDER BY nombre_cliente LIMIT 15"
            );
            $stmt->execute(['%' . $q . '%']);
            respuesta(['status' => 'success', 'clientes' => $stmt->fetchAll()]);
        }

        // Info de sesión para participante (cursos, empresas, fecha)
        case 'SESION_INFO': {
            $sesion = $auth->validarTokenParticipante($token);
            if (!$sesion) respuesta(['status' => 'error', 'message' => 'Sesión expirada o cerrada.'], 401);
            respuesta($auth->getSesionInfo($sesion['id']));
        }

        // Datos para portal de participante autenticado (lista de ya-registrados)
        case 'PARTICIPANTE_MIS_DATOS': {
            $sesion = $auth->validarTokenParticipante($token);
            if (!$sesion) respuesta(['status' => 'error', 'message' => 'Sesión expirada o cerrada.'], 401);
            $participantes = $auth->getParticipantesEnSesion($sesion['id']);
            respuesta(['status' => 'success', 'participantes' => $participantes, 'sesion_nombre' => $sesion['sesion_nombre']]);
        }

        // ── Portal cliente: equipos ───────────────────────
        case 'GET_MI_PERSONAL':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['CLIENTE','ADMIN'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($cliPersonal->listar($usr['id_cliente'] ?? ''));

        case 'GET_DASHBOARD_PERSONAL':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['CLIENTE','ADMIN'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($cliPersonal->dashboard($usr['id_cliente'] ?? ''));

        case 'GET_DETALLE_PERSONAL':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['CLIENTE','ADMIN'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($cliPersonal->detalle((int)($_GET['id'] ?? 0), $usr['id_cliente'] ?? ''));

        case 'GET_PREFS_NOTIF':
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status'=>'error','message'=>'No autorizado.'],401);
            $idc = $usr['id_cliente'] ?? '';
            respuesta($cliPersonal->getPrefsNotif($idc));

        case 'GET_ALERTAS_VENCIMIENTO':
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status'=>'error','message'=>'No autorizado.'],401);
            $idc = $usr['id_cliente'] ?? '';
            respuesta($cliPersonal->getAlertasVencimiento($idc));

        case 'GET_MIS_EQUIPOS':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['CLIENTE','ADMIN'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($cliEquipos->listar($usr['id_cliente'] ?? ''));

        case 'GET_DASHBOARD_EQUIPOS':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['CLIENTE','ADMIN'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($cliEquipos->dashboard($usr['id_cliente'] ?? ''));

        case 'GET_DETALLE_EQUIPO':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['CLIENTE','ADMIN'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($cliEquipos->detalle((int)($_GET['id'] ?? 0), $usr['id_cliente'] ?? ''));

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

        // ── Sesión de acceso para participantes ──────────
        case 'CREAR_SESION_ACCESO':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','INSPECTOR'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($auth->crearSesionAcceso($payload, (int)$usr['id']));

        case 'CERRAR_SESION_ACCESO':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','INSPECTOR'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($auth->cerrarSesionAcceso((int)($payload['sesion_id'] ?? 0), (int)$usr['id']));

        case 'SESION_AGREGAR_EMPRESA':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','INSPECTOR'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($auth->agregarEmpresaSesion($payload, (int)$usr['id']));

        case 'OBTENER_PARTICIPANTES_SESION':
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            $sesionId = (int)($payload['sesion_id'] ?? 0);
            if (!$sesionId) respuesta(['status' => 'error', 'message' => 'sesion_id requerido.']);
            respuesta(['status' => 'success', 'participantes' => $auth->getParticipantesEnSesion($sesionId)]);

        case 'PARTICIPANTE_REGISTRAR': {
            $sesion = $auth->validarTokenParticipante($token);
            if (!$sesion) respuesta(['status' => 'error', 'message' => 'Sesión expirada o cerrada.'], 401);
            respuesta($personal->participanteRegistrar($payload, $_FILES, $sesion['id']));
        }

        case 'PARTICIPANTE_GUARDAR': {
            $sesion = $auth->validarTokenParticipante($token);
            if (!$sesion) respuesta(['status' => 'error', 'message' => 'Sesión expirada o cerrada.'], 401);
            respuesta($personal->participanteAutoGuardar($payload, $sesion['id']));
        }

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

        case 'ELIMINAR_USUARIO':
            $usr = validarToken($pdo, $token);
            if (!$usr || $usr['rol'] !== 'ADMIN') respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($auth->eliminarUsuario((int)($payload['id'] ?? 0), $usr['usuario']));

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

        // ── PND (Pruebas No Destructivas) ─────────────────
        case 'NUEVA_PND':
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($pnd->nueva($payload, $usr['usuario']));

        case 'ACTUALIZAR_PND':
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($pnd->actualizar($payload, $usr['usuario']));

        case 'GENERAR_REPORTE_PND':
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($pnd->generarReporte((int)($payload['id'] ?? 0)));

        case 'APROBAR_PND':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($pnd->aprobar($payload, $usr['usuario']));

        case 'RECHAZAR_PND':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($pnd->rechazar($payload, $usr['usuario']));

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

        // Asignar plantilla HTML de dictamen
        case 'SET_PLANTILLA_DICT_HTML':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($admin->setPlantillaDictHtml($payload));

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
            $correosEnviar = is_array($payload['correos'] ?? null)
                ? $payload['correos']
                : [(string)($payload['correo'] ?? '')];
            respuesta($personal->enviarDocumento(
                (int)   ($payload['id']   ?? 0),
                (string)($payload['tipo'] ?? ''),
                $correosEnviar,
                $usr['usuario']
            ));

        // ── Personal / Cursos ─────────────────────────────
        case 'GUARDAR_PARTICIPANTE':
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($personal->guardarParticipante($payload, $_FILES, $usr['usuario']));

        case 'ELIMINAR_EQUIPO':
            $usr = validarToken($pdo, $token);
            if (!$usr || $usr['rol'] !== 'ADMIN') respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($cal->eliminarEquipo((int)($payload['id'] ?? 0)));

        case 'ELIMINAR_SESION_ACC':
            $usr = validarToken($pdo, $token);
            if (!$usr || $usr['rol'] !== 'ADMIN') respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($accesorios->eliminarSesionAcc((int)($payload['id'] ?? 0)));

        case 'ELIMINAR_PARTICIPANTE':
            $usr = validarToken($pdo, $token);
            if (!$usr || $usr['rol'] !== 'ADMIN') respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($personal->eliminarParticipante((int)($payload['id'] ?? 0)));

        case 'APROBAR_PARTICIPANTE':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($personal->aprobarParticipante((int)($payload['id'] ?? 0), $usr['usuario'], trim($payload['qr'] ?? '')));

        case 'APROBAR_SELECCIONADOS_PERSONAL':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            $ids = array_filter(array_map('intval', (array)($payload['ids'] ?? [])));
            if (empty($ids)) respuesta(['status' => 'error', 'message' => 'IDs requeridos.']);
            respuesta($personal->aprobarSeleccionados($ids, $usr['usuario']));

        case 'ENVIAR_SESION_PERSONAL':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CERTIFICACIONES'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($personal->enviarSesionPersonal($payload, $usr['usuario']));

        case 'DEVOLVER_PARTICIPANTE':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD','CERTIFICACIONES'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($personal->devolverParticipante((int)($payload['id'] ?? 0), $usr['usuario']));

        case 'ACTUALIZAR_EMPRESA_PERSONAL':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($personal->actualizarEmpresa($payload, $usr['usuario']));

        case 'ACTUALIZAR_DATOS_OCR':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($personal->actualizarDatosOCR($payload));

        case 'ACTUALIZAR_INFO_PARTICIPANTE':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($personal->actualizarInfoParticipante($payload, $usr['usuario']));

        case 'EMITIR_DOC_PERSONAL':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CERTIFICACIONES'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            $correosEmitir = is_array($payload['correos'] ?? null)
                ? $payload['correos']
                : [(string)($payload['correo'] ?? '')];
            respuesta($personal->emitirDocumentoPersonal(
                (int)   ($payload['id']  ?? 0),
                (string)($payload['tipo'] ?? ''),
                $correosEmitir,
                $usr['usuario'],
                !empty($payload['enviar_credenciales'])
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

        case 'CAMBIAR_INSTRUCTOR_SESION':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($personal->cambiarInstructorSesion($payload));

        case 'GUARDAR_EMPRESA_DC3':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($personal->guardarEmpresaDC3($payload));

        case 'ELIMINAR_EMPRESA_DC3':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($personal->eliminarEmpresaDC3((int)($payload['id'] ?? 0)));

        case 'GENERAR_DOC_PERSONAL':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CALIDAD','CERTIFICACIONES'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($personal->generarDocumento(
                (int)($payload['id']   ?? 0),
                trim($payload['tipo']  ?? ''),
                $usr['usuario']
            ));

        case 'GENERAR_CREDENCIALES_LOTE':
            $usr = validarToken($pdo, $token);
            if (!$usr || $usr['rol'] !== 'CERTIFICACIONES') respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($personal->generarCredencialesLote($payload, $usr['usuario']));

        case 'GENERAR_CREDENCIAL':
            $usr = validarToken($pdo, $token);
            if (!$usr || $usr['rol'] !== 'CERTIFICACIONES') respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($personal->generarCredencial(
                (int)($payload['id'] ?? 0),
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

        case 'ASIGNAR_QR_ACCESORIO':
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($accesorios->asignarQrAccesorio((int)($payload['id'] ?? 0), trim($payload['qr'] ?? '')));

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

        case 'ENVIAR_TODO_ACC':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['ADMIN','CERTIFICACIONES'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($accesorios->enviarTodoAcc(
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

        case 'SAVE_SMTP_CONFIG':
            $usr = validarToken($pdo, $token);
            if (!$usr || $usr['rol'] !== 'ADMIN') respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($admin->saveSmtpConfig($payload));

        case 'TEST_SMTP_CONFIG':
            $usr = validarToken($pdo, $token);
            if (!$usr || $usr['rol'] !== 'ADMIN') respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($admin->testSmtpConfig($payload));

        // ── Proxy OCR (evita CORS y oculta API key) ───────────────
        case 'OCR_PROXY': {
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);

            if (empty($_FILES['file']['tmp_name'])) {
                respuesta(['status' => 'error', 'message' => 'No se recibió imagen.'], 400);
            }

            if (!function_exists('curl_init')) {
                respuesta(['status' => 'error', 'message' => 'cURL no disponible en el servidor.']);
            }

            $apiKey  = defined('OCR_API_KEY') ? OCR_API_KEY : 'helloworld';
            $tmpFile = $_FILES['file']['tmp_name'];
            $mime    = mime_content_type($tmpFile) ?: 'image/jpeg';

            $ch = curl_init('https://api.ocr.space/parse/image');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_POSTFIELDS     => [
                    'apikey'            => $apiKey,
                    'language'          => 'spa',
                    'isOverlayRequired' => 'false',
                    'detectOrientation' => 'true',
                    'scale'             => 'true',
                    'file'              => new CURLFile($tmpFile, $mime, 'documento.jpg'),
                ],
            ]);

            $ocrRaw  = curl_exec($ch);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($ocrRaw === false) {
                respuesta(['status' => 'error', 'message' => 'Error de conexión OCR: ' . $curlErr]);
            }

            $ocrData = json_decode($ocrRaw, true);
            if (!$ocrData) {
                respuesta(['status' => 'error', 'message' => 'Respuesta OCR inválida.']);
            }
            if (!empty($ocrData['IsErroredOnProcessing']) || !empty($ocrData['ErrorMessage'])) {
                $msg = is_array($ocrData['ErrorMessage'])
                    ? implode('; ', $ocrData['ErrorMessage'])
                    : ($ocrData['ErrorMessage'] ?? 'Error OCR desconocido');
                respuesta(['status' => 'error', 'message' => $msg]);
            }

            $texto = strtoupper($ocrData['ParsedResults'][0]['ParsedText'] ?? '');
            respuesta(['status' => 'success', 'texto' => $texto]);
        }

        // ── Portal cliente: gestión de equipos ───────────────
        case 'GUARDAR_EQUIPO_CLI':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['CLIENTE','ADMIN'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($cliEquipos->guardar($_POST, $_FILES, $usr['id_cliente'] ?? ''));

        case 'ELIMINAR_EQUIPO_CLI':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['CLIENTE','ADMIN'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($cliEquipos->eliminar((int)($payload['id'] ?? 0), $usr['id_cliente'] ?? ''));

        case 'SUBIR_DOC_EQUIPO':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['CLIENTE','ADMIN'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($cliEquipos->subirDoc($_POST, $_FILES, $usr['id_cliente'] ?? ''));

        case 'ELIMINAR_DOC_EQUIPO':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['CLIENTE','ADMIN'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($cliEquipos->eliminarDoc((int)($payload['id'] ?? 0), $usr['id_cliente'] ?? ''));

        case 'REGISTRAR_HOROMETRO':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['CLIENTE','ADMIN'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($cliEquipos->registrarHora($payload, $usr['id_cliente'] ?? ''));

        case 'ELIMINAR_HOROMETRO':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['CLIENTE','ADMIN'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($cliEquipos->eliminarHora((int)($payload['id'] ?? 0), $usr['id_cliente'] ?? ''));

        case 'GUARDAR_CERT_EQUIPO':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['CLIENTE','ADMIN'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($cliEquipos->guardarCert($_POST, $_FILES, $usr['id_cliente'] ?? ''));

        case 'ELIMINAR_CERT_EQUIPO':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['CLIENTE','ADMIN'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($cliEquipos->eliminarCert((int)($payload['id'] ?? 0), $usr['id_cliente'] ?? ''));

        // ── Personal del cliente ─────────────────────────────────
        case 'GUARDAR_PERSONAL_CLI':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['CLIENTE','ADMIN'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($cliPersonal->guardar($payload, $usr['id_cliente'] ?? ''));

        case 'ELIMINAR_PERSONAL_CLI':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['CLIENTE','ADMIN'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($cliPersonal->eliminar((int)($payload['id'] ?? 0), $usr['id_cliente'] ?? ''));

        case 'GUARDAR_PREFS_NOTIF':
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status'=>'error','message'=>'No autorizado.'],401);
            $idc = $usr['id_cliente'] ?? '';
            respuesta($cliPersonal->guardarPrefsNotif($payload, $idc));

        case 'SUBIR_DOC_PERSONAL':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['CLIENTE','ADMIN'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($cliPersonal->subirDoc($_POST, $_FILES, $usr['id_cliente'] ?? ''));

        case 'ELIMINAR_DOC_PERSONAL':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['CLIENTE','ADMIN'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($cliPersonal->eliminarDoc((int)($payload['id'] ?? 0), $usr['id_cliente'] ?? ''));

        case 'EDITAR_DOC_PERSONAL':
            $usr = validarToken($pdo, $token);
            if (!$usr) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            $idc = $usr['id_cliente'] ?? '';
            respuesta($cliPersonal->editarDoc($payload ?? $_POST, $_FILES, $idc));

        case 'GUARDAR_CERT_PERSONAL':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['CLIENTE','ADMIN'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($cliPersonal->guardarCert($_POST, $_FILES, $usr['id_cliente'] ?? ''));

        case 'ELIMINAR_CERT_PERSONAL':
            $usr = validarToken($pdo, $token);
            if (!$usr || !in_array($usr['rol'], ['CLIENTE','ADMIN'])) respuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
            respuesta($cliPersonal->eliminarCert((int)($payload['id'] ?? 0), $usr['id_cliente'] ?? ''));

        default:
            respuesta(['status' => 'error', 'message' => "Acción POST desconocida: {$action}"], 400);
    }
}

// Método no soportado
respuesta(['status' => 'error', 'message' => 'Método no soportado.'], 405);
