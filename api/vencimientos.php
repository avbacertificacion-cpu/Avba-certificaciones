<?php
/**
 * Extintores vencidos o por vencer, para la pantalla de Vencimientos.
 *
 * La consulta vive en config/vencimientos.php porque el PDF descargable usa
 * exactamente la misma.
 */
require_once '../config/config.php';
require_once '../config/vencimientos.php';
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'No autenticado']);
    exit;
}
if ($_SESSION['rol'] !== ROLE_ADMIN) {
    http_response_code(403);
    echo json_encode(['error' => 'Sin permiso']);
    exit;
}

$rango     = rangoPedido($_GET['rango'] ?? null);
$concepto  = conceptoPedido($_GET['concepto'] ?? null);
$empresaId = intval($_GET['empresa_id'] ?? 0);

try {
    $r = extintoresPorVencer($pdo, $rango, $concepto, $empresaId);
    echo json_encode([
        'success'   => true,
        'rango'     => $rango,
        'concepto'  => $concepto,
        'conteo'    => $r['conteo'],
        'en_taller' => $r['en_taller'],
        'data'      => $r['filas'],
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'No se pudo consultar los vencimientos.']);
}
