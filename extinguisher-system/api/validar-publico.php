<?php
require_once '../config/config.php';
require_once '../config/rate-limiter.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

$ip = getClientIP();
$endpoint = '/api/validar-publico.php';

if (!checkRateLimit($ip, $endpoint, 10, 60)) {
    http_response_code(429);
    logApiAccess($ip, $endpoint, 429);
    echo json_encode(['error' => 'Demasiadas solicitudes. Intenta más tarde.']);
    exit;
}

$codigo = trim($_GET['codigo'] ?? '');

if (!$codigo) {
    http_response_code(400);
    logApiAccess($ip, $endpoint, 400);
    echo json_encode(['error' => 'Código requerido']);
    exit;
}

// Validación de longitud: máximo 50 caracteres (EXT-999 = 7 chars, QR = 11 digits)
if (strlen($codigo) > 50) {
    http_response_code(400);
    logApiAccess($ip, $endpoint, 400);
    echo json_encode(['error' => 'Código inválido']);
    exit;
}

$codigo = htmlspecialchars($codigo, ENT_QUOTES, 'UTF-8');

try {
    $stmt = $pdo->prepare("
        SELECT e.id, e.codigo_qr, e.codigo_manual, e.ubicacion, e.tipo, e.capacidad,
               e.seccion, e.estado, e.observaciones, te.nombre AS tipo_nombre,
               emp.nombre AS empresa_nombre, emp.domicilio AS empresa_domicilio,
               u.nombre AS creado_por_nombre
        FROM extintores e
        JOIN tipos_extintores te ON te.id = e.tipo
        JOIN empresas emp ON emp.id = e.empresa_id
        JOIN usuarios u ON u.id = e.creado_por
        WHERE (e.codigo_qr = ? OR e.codigo_manual = ?)
        LIMIT 1
    ");
    $stmt->execute([$codigo, $codigo]);
    $extintor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$extintor) {
        logApiAccess($ip, $endpoint, 200);
        echo json_encode(['found' => false]);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT i.id, i.fecha, i.hora, i.estado, i.observaciones,
               u.nombre AS inspector_nombre
        FROM inspecciones i
        JOIN usuarios u ON u.id = i.inspector_id
        WHERE i.extintor_id = ?
        ORDER BY i.fecha DESC, i.hora DESC
        LIMIT 5
    ");
    $stmt->execute([$extintor['id']]);
    $inspecciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $data = [
        'codigo_manual' => $extintor['codigo_manual'],
        'codigo_qr' => $extintor['codigo_qr'],
        'tipo' => $extintor['tipo_nombre'],
        'capacidad' => $extintor['capacidad'],
        'ubicacion' => $extintor['ubicacion'],
        'seccion' => $extintor['seccion'],
        'estado' => $extintor['estado'],
        'empresa' => $extintor['empresa_nombre'],
        'observaciones' => $extintor['observaciones'],
        'inspecciones' => $inspecciones
    ];

    logApiAccess($ip, $endpoint, 200);
    echo json_encode(['found' => true, 'data' => $data]);

} catch (Exception $e) {
    http_response_code(500);
    logApiAccess($ip, $endpoint, 500, $e->getMessage());
    echo json_encode(['error' => 'Error al consultar extintor']);
}
?>
