<?php
/**
 * Socios Comerciales — API independiente
 *
 * Sistema separado alojado en gestion.avba.com.mx/socioscomerciales. Comparte
 * el mismo servidor MySQL que el sistema principal (reutiliza las credenciales
 * de /config) pero TODAS sus tablas usan el prefijo `sc_`, de modo que no
 * interfiere con las tablas del sistema de certificaciones.
 *
 * Punto de entrada aislado: no incluye ni depende de /api del sistema raíz.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

// Prefijo de tablas exclusivo de este sistema (evita colisiones con la raíz)
const SC_PREFIX = 'sc_';

/** Respuesta JSON y fin. */
function sc_resp(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Reutilizar la conexión a MySQL del sistema principal (config no se despliega
// por Git; existe en el servidor en /config). Si no está disponible, la API
// responde de todos modos para poder verificar que el subsistema está montado.
$pdo = null;
$cfg = __DIR__ . '/../../config/config.php';
$dbf = __DIR__ . '/../../config/database.php';
if (is_file($cfg) && is_file($dbf)) {
    require_once $cfg;
    require_once $dbf;
    try { $pdo = Database::getConnection(); } catch (\Throwable $e) { $pdo = null; }
}

// Enrutado mínimo
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $method === 'GET' ? ($_GET['action'] ?? 'PING') : ($_POST['action'] ?? '');
if ($action === '' ) {
    $raw = file_get_contents('php://input');
    if ($raw) { $b = json_decode($raw, true); if (is_array($b)) $action = $b['action'] ?? 'PING'; }
}

switch ($action) {
    case 'PING':
        sc_resp([
            'status'  => 'success',
            'service' => 'socioscomerciales',
            'db'      => $pdo ? 'ok' : 'sin-conexion',
            'prefix'  => SC_PREFIX,
        ]);

    default:
        sc_resp(['status' => 'error', 'message' => "Acción no reconocida: {$action}"], 400);
}
