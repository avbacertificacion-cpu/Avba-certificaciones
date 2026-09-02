<?php
require_once '../config/config.php';
require_once '../config/mayusculas.php';
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== ROLE_ADMIN) {
    http_response_code(403);
    echo json_encode(['error' => 'Sin permiso']);
    exit;
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'listar':          listar();           break;
    case 'obtener':         obtener();          break;
    case 'crear':           crear();            break;
    case 'editar':          editar();           break;
    case 'cambiar_estado':  cambiarEstado();    break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Acción no válida']);
}

// ─── LISTAR ──────────────────────────────────────────────────────────────────
function listar() {
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT id, nombre, descripcion, estado
        FROM tipos_extintores
        ORDER BY estado DESC, nombre ASC
    ");
    $stmt->execute();

    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// ─── OBTENER UNO ─────────────────────────────────────────────────────────────
function obtener() {
    global $pdo;

    $id = intval($_GET['id'] ?? 0);
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID requerido']);
        return;
    }

    $stmt = $pdo->prepare("
        SELECT id, nombre, descripcion, estado
        FROM tipos_extintores
        WHERE id = ?
    ");
    $stmt->execute([$id]);
    $tipo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tipo) {
        http_response_code(404);
        echo json_encode(['error' => 'No encontrado']);
        return;
    }

    echo json_encode(['success' => true, 'data' => $tipo]);
}

// ─── CREAR ───────────────────────────────────────────────────────────────────
function crear() {
    global $pdo;

    $d = entradaEnMayusculas(json_decode(file_get_contents('php://input'), true));

    if (empty($d['nombre'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Nombre requerido']);
        return;
    }

    $nombre = trim($d['nombre']);
    $descripcion = trim($d['descripcion'] ?? '');

    // Verificar que no exista
    $stmt = $pdo->prepare("SELECT id FROM tipos_extintores WHERE nombre = ?");
    $stmt->execute([$nombre]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'Este tipo ya existe']);
        return;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO tipos_extintores (nombre, descripcion, estado)
            VALUES (?, ?, 'activo')
        ");
        $stmt->execute([$nombre, $descripcion ?: null]);

        $id = $pdo->lastInsertId();
        echo json_encode(['success' => true, 'id' => $id]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error al crear tipo']);
    }
}

// ─── EDITAR ───────────────────────────────────────────────────────────────────
function editar() {
    global $pdo;

    $d = entradaEnMayusculas(json_decode(file_get_contents('php://input'), true));

    $id = intval($d['id'] ?? 0);
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID requerido']);
        return;
    }

    if (empty($d['nombre'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Nombre requerido']);
        return;
    }

    $nombre = trim($d['nombre']);
    $descripcion = trim($d['descripcion'] ?? '');

    // Verificar que no exista otro con el mismo nombre
    $stmt = $pdo->prepare("SELECT id FROM tipos_extintores WHERE nombre = ? AND id != ?");
    $stmt->execute([$nombre, $id]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'Este tipo ya existe']);
        return;
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE tipos_extintores
            SET nombre = ?, descripcion = ?
            WHERE id = ?
        ");
        $stmt->execute([$nombre, $descripcion ?: null, $id]);

        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error al actualizar']);
    }
}

// ─── CAMBIAR ESTADO ──────────────────────────────────────────────────────────
function cambiarEstado() {
    global $pdo;

    $d = entradaEnMayusculas(json_decode(file_get_contents('php://input'), true));

    $id = intval($d['id'] ?? 0);
    $estado = $d['estado'] ?? '';

    if (!$id || !in_array($estado, ['activo', 'inactivo'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Parámetros inválidos']);
        return;
    }

    try {
        $stmt = $pdo->prepare("UPDATE tipos_extintores SET estado = ? WHERE id = ?");
        $stmt->execute([$estado, $id]);

        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error al cambiar estado']);
    }
}
