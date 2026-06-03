<?php
require_once '../config/config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== ROLE_ADMIN) {
    http_response_code(403);
    echo json_encode(['error' => 'Sin permiso']);
    exit;
}

$d = json_decode(file_get_contents('php://input'), true);

if (empty($d['datos']) || !is_array($d['datos'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No hay datos para importar']);
    exit;
}

$uid = $_SESSION['usuario_id'];
$insertados = 0;
$errores_insert = [];

try {
    $pdo->beginTransaction();

    foreach ($d['datos'] as $extintor) {
        // Generar código QR único (hash del código_manual + empresa_id + timestamp)
        $codigo_qr = hash('sha256', $extintor['codigo_manual'] . $extintor['empresa_id'] . time() . rand());

        try {
            $stmt = $pdo->prepare("
                INSERT INTO extintores
                    (codigo_qr, codigo_manual, empresa_id, ubicacion, tipo, capacidad,
                     seccion, fecha_recarga, fecha_ph, estado, observaciones, creado_por)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'activo', ?, ?)
            ");

            $stmt->execute([
                $codigo_qr,
                $extintor['codigo_manual'],
                intval($extintor['empresa_id']),
                $extintor['ubicacion'],
                $extintor['tipo'],
                $extintor['capacidad'],
                $extintor['seccion'],
                $extintor['fecha_recarga'] ?: null,
                $extintor['fecha_ph'] ?: null,
                $extintor['observaciones'],
                $uid
            ]);

            $insertados++;
        } catch (Exception $e) {
            $errores_insert[] = "Error en {$extintor['codigo_manual']}: " . $e->getMessage();
        }
    }

    $pdo->commit();

    // Auditoría
    $stmt = $pdo->prepare("
        INSERT INTO auditoria (usuario_id, accion, tabla, registro_id, ip)
        VALUES (?, ?, 'extintores', 0, ?)
    ");
    $stmt->execute([
        $uid,
        "Importación masiva: $insertados extintores",
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    echo json_encode([
        'success' => true,
        'insertados' => $insertados,
        'errores' => $errores_insert
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'Error en la transacción: ' . $e->getMessage()]);
}
