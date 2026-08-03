<?php
/**
 * Socios Comerciales AVBA — API Principal
 * Punto de entrada único para todas las peticiones del frontend.
 *
 * GET  /api/?action=XXX   → acciones de lectura
 * POST /api/               → escritura (JSON body, o multipart/form-data para archivos)
 *
 * Sistema 100% aislado del API de certificaciones (/api en la raíz del repo).
 */

// ── Bootstrap ─────────────────────────────────────────────
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Personas.php';
require_once __DIR__ . '/Empresas.php';
require_once __DIR__ . '/Vacantes.php';
require_once __DIR__ . '/diagnostico.php';

// Nunca mostrar avisos de PHP en la respuesta: romperían el JSON
ini_set('display_errors', '0');

// Aislar la cookie de sesión de la del sistema de certificaciones.
// Se fija aquí y no solo en .user.ini porque este host no aplica ese
// archivo (el diagnóstico muestra los límites globales, no los nuestros).
// Hoy la autenticación es por token, pero esto deja el aislamiento
// garantizado si en el futuro se usan sesiones PHP.
ini_set('session.cookie_path', '/socioscomerciales');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', '1');

// ── Headers ───────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Cualquier error fatal debe salir como JSON, no como una página HTML de error
// (si no, el frontend recibe HTML y res.json() falla en silencio).
set_exception_handler(function (Throwable $e) {
    error_log('SC API: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) http_response_code(500);

    // Las RuntimeException las lanzamos nosotros con texto pensado para el
    // usuario (p. ej. fallos de esquema); el resto se reporta en genérico
    // para no filtrar detalles internos.
    $mensaje = $e instanceof RuntimeException
        ? $e->getMessage()
        : 'Error interno del servidor.';

    echo json_encode([
        'status'  => 'error',
        'message' => $mensaje,
        'ayuda'   => 'Si el problema persiste, abre api/index.php?action=DIAGNOSTICO',
    ], JSON_UNESCAPED_UNICODE);
});
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['status' => 'error', 'message' => 'Error interno del servidor.'], JSON_UNESCAPED_UNICODE);
    }
});

// El diagnóstico corre ANTES de conectar: así puede reportar también los
// fallos de conexión y de migración, que abortarían el arranque normal.
if (($_GET['action'] ?? '') === 'DIAGNOSTICO') {
    echo json_encode(scDiagnostico(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ── PDO + módulos ─────────────────────────────────────────
$pdo      = scDB();
$auth     = new ScAuth($pdo);
$personas = new ScPersonas($pdo);
$empresas = new ScEmpresas($pdo);
$vacantes = new ScVacantes($pdo);

// ── Extraer token (Authorization: Bearer, X-Token, body o query) ──
$token = null;
$cabecera = $_SERVER['HTTP_AUTHORIZATION']
    ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
    ?? $_SERVER['HTTP_X_TOKEN']
    ?? '';
if (preg_match('/Bearer\s+(.+)/i', $cabecera, $m)) {
    $token = trim($m[1]);
} elseif ($cabecera !== '') {
    $token = trim($cabecera);
}

$method      = $_SERVER['REQUEST_METHOD'];
$tipoContenido = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
$esMultipart = $method === 'POST' && stripos($tipoContenido, 'multipart/form-data') === 0;
$action      = '';
$payload     = [];

if ($method === 'GET') {
    $action = $_GET['action'] ?? '';
    if (!$token && isset($_GET['token'])) $token = $_GET['token'];
    $payload = $_GET;
} elseif ($esMultipart) {
    $action  = $_POST['action'] ?? '';
    $payload = $_POST;
    if (!$token && isset($_POST['token'])) $token = $_POST['token'];

    // Si el cuerpo superó post_max_size, PHP descarta $_POST y $_FILES enteros
    if (empty($_POST) && empty($_FILES)) {
        scRespuesta([
            'status'  => 'error',
            'message' => 'El archivo es demasiado grande para el servidor (límite: '
                         . ini_get('post_max_size') . ').',
        ], 413);
    }
} else {
    $raw  = file_get_contents('php://input');
    $body = $raw ? (json_decode($raw, true) ?? []) : [];
    $action  = $body['action']  ?? '';
    $payload = $body['payload'] ?? $body;
    if (!$token && isset($body['token'])) $token = $body['token'];
}

/** Exige sesión válida; corta con 401 si no la hay. */
function scSesion(PDO $pdo, ?string $token): array {
    $usr = scValidarToken($pdo, $token);
    if (!$usr) scRespuesta(['status' => 'error', 'message' => 'Tu sesión expiró. Inicia sesión de nuevo.'], 401);
    return $usr;
}

/** Exige sesión de un tipo concreto (persona o empresa). */
function scSesionTipo(PDO $pdo, ?string $token, string $tipo): array {
    $usr = scSesion($pdo, $token);
    if ($usr['tipo'] !== $tipo) {
        scRespuesta(['status' => 'error', 'message' => 'Tu tipo de cuenta no tiene acceso a esta acción.'], 403);
    }
    return $usr;
}

// ══════════════════════════════════════════════════════════
//  RUTAS GET
// ══════════════════════════════════════════════════════════
if ($method === 'GET') {
    switch ($action) {

        // Verificación de correo: llega desde el enlace del correo, así que
        // responde con una redirección al HTML, no con JSON.
        case 'VERIFICAR_CORREO':
            $res = $auth->verificarCorreo(trim($_GET['t'] ?? ''));
            $ok  = $res['status'] === 'success' ? '1' : '0';
            header('Location: ' . scUrlBase() . '/verificar.html?ok=' . $ok
                   . '&msg=' . urlencode($res['message']), true, 302);
            exit;

        case 'GET_PERFIL_PERSONA':
            $usr = scSesionTipo($pdo, $token, 'persona');
            scRespuesta($personas->obtenerPerfil((int) $usr['id']));

        case 'GET_PERFIL_EMPRESA':
            $usr = scSesionTipo($pdo, $token, 'empresa');
            scRespuesta($empresas->obtenerPerfil((int) $usr['id']));

        case 'GET_INICIO':
            $usr = scSesion($pdo, $token);
            scRespuesta($vacantes->resumenInicio($usr));

        // ── Perfiles públicos ────────────────────────────────
        case 'GET_PERSONA_PUBLICA':
            scSesion($pdo, $token);
            scRespuesta($personas->obtenerPerfilPublico((int) ($_GET['id'] ?? 0)));

        case 'GET_EMPRESA_PUBLICA':
            scRespuesta($empresas->obtenerPerfilPublico((int) ($_GET['id'] ?? 0)));

        case 'LISTAR_EMPRESAS':
            scRespuesta($empresas->listarEmpresas($_GET['texto'] ?? ''));

        // ── Vacantes ─────────────────────────────────────────
        case 'BUSCAR_VACANTES':
            $usr       = scValidarToken($pdo, $token);
            $personaId = null;
            if ($usr && $usr['tipo'] === 'persona') {
                $s = $pdo->prepare("SELECT id FROM sc_personas WHERE usuario_id = ?");
                $s->execute([$usr['id']]);
                $personaId = (int) $s->fetchColumn() ?: null;
            }
            scRespuesta($vacantes->buscar($payload, $personaId));

        case 'GET_VACANTE':
            $usr       = scValidarToken($pdo, $token);
            $personaId = null;
            if ($usr && $usr['tipo'] === 'persona') {
                $s = $pdo->prepare("SELECT id FROM sc_personas WHERE usuario_id = ?");
                $s->execute([$usr['id']]);
                $personaId = (int) $s->fetchColumn() ?: null;
            }
            scRespuesta($vacantes->obtener((int) ($_GET['id'] ?? 0), $personaId));

        case 'LISTAR_MIS_VACANTES':
            $usr = scSesionTipo($pdo, $token, 'empresa');
            scRespuesta($vacantes->listarMisVacantes((int) $usr['id']));

        // ── Postulaciones ────────────────────────────────────
        case 'MIS_POSTULACIONES':
            $usr = scSesionTipo($pdo, $token, 'persona');
            scRespuesta($vacantes->misPostulaciones((int) $usr['id']));

        case 'POSTULACIONES_VACANTE':
            $usr = scSesionTipo($pdo, $token, 'empresa');
            scRespuesta($vacantes->postulacionesDeVacante((int) $usr['id'], (int) ($_GET['vacante_id'] ?? 0)));

        // ── Candidatos ───────────────────────────────────────
        case 'BUSCAR_CANDIDATOS':
            scSesionTipo($pdo, $token, 'empresa');
            scRespuesta($personas->buscarCandidatos($payload));

        default:
            scRespuesta(['status' => 'error', 'message' => "Acción GET desconocida: {$action}"], 400);
    }
}

// ══════════════════════════════════════════════════════════
//  RUTAS POST
// ══════════════════════════════════════════════════════════
if ($method === 'POST') {
    switch ($action) {

        // ── Auth ─────────────────────────────────────────
        case 'REGISTRO_PERSONA':
            scRespuesta($auth->registrar('persona', $payload));

        case 'REGISTRO_EMPRESA':
            scRespuesta($auth->registrar('empresa', $payload));

        case 'LOGIN':
            scRespuesta($auth->login($payload));

        case 'LOGOUT':
            $usr = scSesion($pdo, $token);
            scRespuesta($auth->logout((int) $usr['id']));

        case 'REENVIAR_VERIFICACION':
            $usr = scSesion($pdo, $token);
            scRespuesta($auth->reenviarVerificacion((int) $usr['id']));

        // ── Contraseña ───────────────────────────────────
        case 'SOLICITAR_RESET':
            scRespuesta($auth->solicitarReset($payload));

        case 'RESTABLECER_PASSWORD':
            scRespuesta($auth->restablecerPassword($payload));

        case 'CAMBIAR_PASSWORD':
            $usr = scSesion($pdo, $token);
            scRespuesta($auth->cambiarPassword((int) $usr['id'], $payload));

        // ── Perfil persona ─────────────────────────────────
        case 'ACTUALIZAR_PERFIL_PERSONA':
            $usr = scSesionTipo($pdo, $token, 'persona');
            scRespuesta($personas->actualizarPerfil((int) $usr['id'], $payload));

        case 'SUBIR_CV':
            $usr = scSesionTipo($pdo, $token, 'persona');
            scRespuesta($personas->subirCV((int) $usr['id'], $_FILES['archivo'] ?? []));

        case 'SUBIR_FOTO':
            $usr = scSesionTipo($pdo, $token, 'persona');
            scRespuesta($personas->subirFoto((int) $usr['id'], $_FILES['archivo'] ?? []));

        case 'AGREGAR_EXPERIENCIA':
            $usr = scSesionTipo($pdo, $token, 'persona');
            scRespuesta($personas->agregarExperiencia((int) $usr['id'], $payload));

        case 'ACTUALIZAR_EXPERIENCIA':
            $usr = scSesionTipo($pdo, $token, 'persona');
            scRespuesta($personas->actualizarExperiencia((int) $usr['id'], $payload));

        case 'ELIMINAR_EXPERIENCIA':
            $usr = scSesionTipo($pdo, $token, 'persona');
            scRespuesta($personas->eliminarExperiencia((int) $usr['id'], (int) ($payload['id'] ?? 0)));

        case 'AGREGAR_EDUCACION':
            $usr = scSesionTipo($pdo, $token, 'persona');
            scRespuesta($personas->agregarEducacion((int) $usr['id'], $payload));

        case 'ACTUALIZAR_EDUCACION':
            $usr = scSesionTipo($pdo, $token, 'persona');
            scRespuesta($personas->actualizarEducacion((int) $usr['id'], $payload));

        case 'ELIMINAR_EDUCACION':
            $usr = scSesionTipo($pdo, $token, 'persona');
            scRespuesta($personas->eliminarEducacion((int) $usr['id'], (int) ($payload['id'] ?? 0)));

        case 'AGREGAR_HABILIDAD':
            $usr = scSesionTipo($pdo, $token, 'persona');
            scRespuesta($personas->agregarHabilidad((int) $usr['id'], $payload));

        case 'ELIMINAR_HABILIDAD':
            $usr = scSesionTipo($pdo, $token, 'persona');
            scRespuesta($personas->eliminarHabilidad((int) $usr['id'], (int) ($payload['id'] ?? 0)));

        // ── Perfil empresa ──────────────────────────────────
        case 'ACTUALIZAR_PERFIL_EMPRESA':
            $usr = scSesionTipo($pdo, $token, 'empresa');
            scRespuesta($empresas->actualizarPerfil((int) $usr['id'], $payload));

        case 'SUBIR_LOGO':
            $usr = scSesionTipo($pdo, $token, 'empresa');
            scRespuesta($empresas->subirLogo((int) $usr['id'], $_FILES['archivo'] ?? []));

        // ── Vacantes ────────────────────────────────────────
        case 'CREAR_VACANTE':
            $usr = scSesionTipo($pdo, $token, 'empresa');
            scRespuesta($vacantes->crear((int) $usr['id'], $payload));

        case 'ACTUALIZAR_VACANTE':
            $usr = scSesionTipo($pdo, $token, 'empresa');
            scRespuesta($vacantes->actualizar((int) $usr['id'], $payload));

        case 'ELIMINAR_VACANTE':
            $usr = scSesionTipo($pdo, $token, 'empresa');
            scRespuesta($vacantes->eliminar((int) $usr['id'], (int) ($payload['id'] ?? 0)));

        // ── Postulaciones ───────────────────────────────────
        case 'POSTULAR':
            $usr = scSesionTipo($pdo, $token, 'persona');
            scRespuesta($vacantes->postular((int) $usr['id'], $payload));

        case 'CAMBIAR_ESTATUS_POSTULACION':
            $usr = scSesionTipo($pdo, $token, 'empresa');
            scRespuesta($vacantes->cambiarEstatusPostulacion((int) $usr['id'], $payload));

        default:
            scRespuesta(['status' => 'error', 'message' => "Acción POST desconocida: {$action}"], 400);
    }
}

// Método no soportado
scRespuesta(['status' => 'error', 'message' => 'Método no soportado.'], 405);
