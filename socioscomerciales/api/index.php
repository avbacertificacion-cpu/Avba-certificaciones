<?php
/**
 * Socios Comerciales AVBA — API Principal
 * Punto de entrada único para todas las peticiones del frontend.
 *
 * GET  /api/?action=XXX   → acciones de lectura
 * POST /api/               → acciones de escritura (JSON body o multipart/form-data para archivos)
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
$pdo      = scDB();
$auth     = new ScAuth($pdo);
$personas = new ScPersonas($pdo);
$empresas = new ScEmpresas($pdo);

// ── Extraer token (header Authorization: Bearer, X-Token, body o query) ──
$token = null;
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_TOKEN'] ?? '';
if (preg_match('/Bearer\s+(.+)/i', $authHeader, $m)) {
    $token = trim($m[1]);
}

$method       = $_SERVER['REQUEST_METHOD'];
$esMultipart  = $method === 'POST' && str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data');
$action       = '';
$payload      = [];

if ($method === 'GET') {
    $action = $_GET['action'] ?? '';
    if (!$token && isset($_GET['token'])) $token = $_GET['token'];
} elseif ($esMultipart) {
    $action = $_POST['action'] ?? '';
    if (!$token && isset($_POST['token'])) $token = $_POST['token'];
    $payload = $_POST;
} else {
    $raw  = file_get_contents('php://input');
    $body = $raw ? (json_decode($raw, true) ?? []) : [];
    $action  = $body['action']  ?? '';
    $payload = $body['payload'] ?? $body;
    if (!$token && isset($body['token'])) $token = $body['token'];
}

function scRequerirSesion(PDO $pdo, ?string $token): array {
    $usr = scValidarToken($pdo, $token);
    if (!$usr) scRespuesta(['status' => 'error', 'message' => 'No autorizado.'], 401);
    return $usr;
}

// ══════════════════════════════════════════════════════════
//  RUTAS GET
// ══════════════════════════════════════════════════════════
if ($method === 'GET') {
    switch ($action) {

        case 'GET_PERFIL_PERSONA':
            $usr = scRequerirSesion($pdo, $token);
            if ($usr['tipo'] !== 'persona') scRespuesta(['status' => 'error', 'message' => 'No autorizado.'], 403);
            scRespuesta($personas->obtenerPerfil((int) $usr['id']));

        case 'GET_PERFIL_EMPRESA':
            $usr = scRequerirSesion($pdo, $token);
            if ($usr['tipo'] !== 'empresa') scRespuesta(['status' => 'error', 'message' => 'No autorizado.'], 403);
            scRespuesta($empresas->obtenerPerfil((int) $usr['id']));

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
            scRespuesta($auth->registrarPersona($payload));

        case 'REGISTRO_EMPRESA':
            scRespuesta($auth->registrarEmpresa($payload));

        case 'LOGIN':
            scRespuesta($auth->login($payload));

        case 'LOGOUT':
            $usr = scRequerirSesion($pdo, $token);
            scRespuesta($auth->logout((int) $usr['id']));

        // ── Perfil persona ─────────────────────────────────
        case 'ACTUALIZAR_PERFIL_PERSONA':
            $usr = scRequerirSesion($pdo, $token);
            if ($usr['tipo'] !== 'persona') scRespuesta(['status' => 'error', 'message' => 'No autorizado.'], 403);
            scRespuesta($personas->actualizarPerfil((int) $usr['id'], $payload));

        case 'SUBIR_CV':
            $usr = scRequerirSesion($pdo, $token);
            if ($usr['tipo'] !== 'persona') scRespuesta(['status' => 'error', 'message' => 'No autorizado.'], 403);
            scRespuesta($personas->subirCV((int) $usr['id'], $_FILES['archivo'] ?? []));

        case 'SUBIR_FOTO':
            $usr = scRequerirSesion($pdo, $token);
            if ($usr['tipo'] !== 'persona') scRespuesta(['status' => 'error', 'message' => 'No autorizado.'], 403);
            scRespuesta($personas->subirFoto((int) $usr['id'], $_FILES['archivo'] ?? []));

        case 'AGREGAR_EXPERIENCIA':
            $usr = scRequerirSesion($pdo, $token);
            if ($usr['tipo'] !== 'persona') scRespuesta(['status' => 'error', 'message' => 'No autorizado.'], 403);
            scRespuesta($personas->agregarExperiencia((int) $usr['id'], $payload));

        case 'ELIMINAR_EXPERIENCIA':
            $usr = scRequerirSesion($pdo, $token);
            if ($usr['tipo'] !== 'persona') scRespuesta(['status' => 'error', 'message' => 'No autorizado.'], 403);
            scRespuesta($personas->eliminarExperiencia((int) $usr['id'], (int) ($payload['id'] ?? 0)));

        case 'AGREGAR_EDUCACION':
            $usr = scRequerirSesion($pdo, $token);
            if ($usr['tipo'] !== 'persona') scRespuesta(['status' => 'error', 'message' => 'No autorizado.'], 403);
            scRespuesta($personas->agregarEducacion((int) $usr['id'], $payload));

        case 'ELIMINAR_EDUCACION':
            $usr = scRequerirSesion($pdo, $token);
            if ($usr['tipo'] !== 'persona') scRespuesta(['status' => 'error', 'message' => 'No autorizado.'], 403);
            scRespuesta($personas->eliminarEducacion((int) $usr['id'], (int) ($payload['id'] ?? 0)));

        case 'AGREGAR_HABILIDAD':
            $usr = scRequerirSesion($pdo, $token);
            if ($usr['tipo'] !== 'persona') scRespuesta(['status' => 'error', 'message' => 'No autorizado.'], 403);
            scRespuesta($personas->agregarHabilidad((int) $usr['id'], $payload));

        case 'ELIMINAR_HABILIDAD':
            $usr = scRequerirSesion($pdo, $token);
            if ($usr['tipo'] !== 'persona') scRespuesta(['status' => 'error', 'message' => 'No autorizado.'], 403);
            scRespuesta($personas->eliminarHabilidad((int) $usr['id'], (int) ($payload['id'] ?? 0)));

        // ── Perfil empresa ──────────────────────────────────
        case 'ACTUALIZAR_PERFIL_EMPRESA':
            $usr = scRequerirSesion($pdo, $token);
            if ($usr['tipo'] !== 'empresa') scRespuesta(['status' => 'error', 'message' => 'No autorizado.'], 403);
            scRespuesta($empresas->actualizarPerfil((int) $usr['id'], $payload));

        case 'SUBIR_LOGO':
            $usr = scRequerirSesion($pdo, $token);
            if ($usr['tipo'] !== 'empresa') scRespuesta(['status' => 'error', 'message' => 'No autorizado.'], 403);
            scRespuesta($empresas->subirLogo((int) $usr['id'], $_FILES['archivo'] ?? []));

        default:
            scRespuesta(['status' => 'error', 'message' => "Acción POST desconocida: {$action}"], 400);
    }
}

// Método no soportado
scRespuesta(['status' => 'error', 'message' => 'Método no soportado.'], 405);
