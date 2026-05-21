<?php
require_once '../config/config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'No autenticado']);
    exit;
}

// Cliente no tiene acceso a extintores directamente
if ($_SESSION['rol'] === ROLE_CLIENTE) {
    http_response_code(403);
    echo json_encode(['error' => 'Sin permiso']);
    exit;
}

$rol    = $_SESSION['rol'];
$uid    = $_SESSION['usuario_id'];
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'listar':    listar();    break;
    case 'obtener':   obtener();   break;
    case 'buscar_qr': buscarQR();  break;
    case 'crear':     crear();     break;
    case 'editar':    editar();    break;
    case 'eliminar':  eliminar();  break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Acción no válida']);
}

// ─── LISTAR ──────────────────────────────────────────────────────────────────
function listar() {
    global $pdo, $rol, $uid;

    $empresa_id = $_GET['empresa_id'] ?? null;

    // Cliente sólo ve los de su empresa
    if ($rol === ROLE_CLIENTE) {
        $empresa_id = $_SESSION['empresa_id'];
    }

    $where = $empresa_id ? 'WHERE e.empresa_id = :eid' : '';
    $params = $empresa_id ? [':eid' => $empresa_id] : [];

    $stmt = $pdo->prepare("
        SELECT e.*,
               emp.nombre AS empresa_nombre,
               u.nombre   AS creado_por_nombre,
               (SELECT fecha FROM inspecciones
                WHERE extintor_id = e.id
                ORDER BY fecha DESC, hora DESC LIMIT 1) AS ultima_inspeccion
        FROM extintores e
        JOIN empresas  emp ON emp.id = e.empresa_id
        JOIN usuarios  u   ON u.id  = e.creado_por
        $where
        ORDER BY e.codigo_manual ASC
    ");
    $stmt->execute($params);

    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// ─── OBTENER UNO ─────────────────────────────────────────────────────────────
function obtener() {
    global $pdo;

    $id = intval($_GET['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID requerido']); return; }

    $stmt = $pdo->prepare("
        SELECT e.*, emp.nombre AS empresa_nombre
        FROM extintores e
        JOIN empresas emp ON emp.id = e.empresa_id
        WHERE e.id = ?
    ");
    $stmt->execute([$id]);
    $ext = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ext) { http_response_code(404); echo json_encode(['error' => 'No encontrado']); return; }

    // Últimas 5 inspecciones
    $stmt = $pdo->prepare("
        SELECT i.*, u.nombre AS inspector_nombre
        FROM inspecciones i
        JOIN usuarios u ON u.id = i.inspector_id
        WHERE i.extintor_id = ?
        ORDER BY i.fecha DESC, i.hora DESC
        LIMIT 5
    ");
    $stmt->execute([$id]);
    $ext['inspecciones'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $ext]);
}

// ─── BUSCAR POR QR O CÓDIGO MANUAL ───────────────────────────────────────────
function buscarQR() {
    global $pdo;

    $codigo = trim($_GET['codigo'] ?? '');
    $empresa_id = isset($_GET['empresa_id']) ? intval($_GET['empresa_id']) : null;

    if (!$codigo) { http_response_code(400); echo json_encode(['error' => 'Código requerido']); return; }

    $where = "WHERE (e.codigo_qr = ? OR e.codigo_manual = ?)";
    $params = [$codigo, $codigo];

    if ($empresa_id) {
        $where .= " AND e.empresa_id = ?";
        $params[] = $empresa_id;
    }

    $stmt = $pdo->prepare("
        SELECT e.*, emp.nombre AS empresa_nombre
        FROM extintores e
        JOIN empresas emp ON emp.id = e.empresa_id
        $where
        LIMIT 1
    ");
    $stmt->execute($params);
    $ext = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ext) {
        echo json_encode(['found' => false]);
        return;
    }

    // Última inspección
    $stmt = $pdo->prepare("
        SELECT i.*, u.nombre AS inspector_nombre
        FROM inspecciones i
        JOIN usuarios u ON u.id = i.inspector_id
        WHERE i.extintor_id = ?
        ORDER BY i.fecha DESC LIMIT 1
    ");
    $stmt->execute([$ext['id']]);
    $ext['ultima_inspeccion'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    echo json_encode(['found' => true, 'data' => $ext]);
}

// ─── CREAR ───────────────────────────────────────────────────────────────────
function crear() {
    global $pdo, $rol, $uid;

    if (!in_array($rol, [ROLE_ADMIN, ROLE_INSPECTOR])) {
        http_response_code(403); echo json_encode(['error' => 'Sin permiso']); return;
    }

    $d = json_decode(file_get_contents('php://input'), true);

    $campos_req = ['empresa_id', 'ubicacion', 'tipo'];
    foreach ($campos_req as $c) {
        if (empty($d[$c])) {
            http_response_code(400);
            echo json_encode(['error' => "Campo requerido: $c"]);
            return;
        }
    }

    // Generar código QR único
    $codigo_qr = bin2hex(random_bytes(16));

    // Generar código manual correlativo por empresa
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM extintores WHERE empresa_id = ?");
    $stmt->execute([$d['empresa_id']]);
    $num = $stmt->fetchColumn() + 1;
    $codigo_manual = $d['codigo_manual'] ?? ('EXT-' . str_pad($num, 3, '0', STR_PAD_LEFT));

    try {
        $stmt = $pdo->prepare("
            INSERT INTO extintores
                (codigo_qr, codigo_manual, empresa_id, seccion, ubicacion, tipo, capacidad,
                 fecha_recarga, fecha_ph, estado, observaciones, creado_por)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $codigo_qr,
            $codigo_manual,
            $d['empresa_id'],
            $d['seccion']       ?? null,
            $d['ubicacion'],
            $d['tipo'],
            $d['capacidad']     ?? null,
            $d['fecha_recarga'] ?? null,
            $d['fecha_ph']      ?? null,
            $d['estado']        ?? 'activo',
            $d['observaciones'] ?? null,
            $uid,
        ]);

        $id = $pdo->lastInsertId();
        audit($uid, "Crear extintor $codigo_manual", 'extintores', $id);

        echo json_encode([
            'success'       => true,
            'id'            => $id,
            'codigo_qr'     => $codigo_qr,
            'codigo_manual' => $codigo_manual,
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            http_response_code(409);
            echo json_encode(['error' => 'El código manual ya existe']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Error al crear extintor']);
        }
    }
}

// ─── EDITAR ───────────────────────────────────────────────────────────────────
function editar() {
    global $pdo, $rol, $uid;

    if (!in_array($rol, [ROLE_ADMIN, ROLE_INSPECTOR])) {
        http_response_code(403); echo json_encode(['error' => 'Sin permiso']); return;
    }

    $d  = json_decode(file_get_contents('php://input'), true);
    $id = intval($d['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID requerido']); return; }

    $stmt = $pdo->prepare("
        UPDATE extintores SET
            seccion       = ?,
            ubicacion     = ?,
            tipo          = ?,
            capacidad     = ?,
            fecha_recarga = ?,
            fecha_ph      = ?,
            estado        = ?,
            observaciones = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $d['seccion']       ?? null,
        $d['ubicacion']     ?? '',
        $d['tipo']          ?? '',
        $d['capacidad']     ?? null,
        $d['fecha_recarga'] ?? null,
        $d['fecha_ph']      ?? null,
        $d['estado']        ?? 'activo',
        $d['observaciones'] ?? null,
        $id,
    ]);

    audit($uid, "Editar extintor", 'extintores', $id);
    echo json_encode(['success' => true]);
}

// ─── ELIMINAR ────────────────────────────────────────────────────────────────
function eliminar() {
    global $pdo, $rol, $uid;

    if (!in_array($rol, [ROLE_ADMIN, ROLE_INSPECTOR])) {
        http_response_code(403); echo json_encode(['error' => 'Sin permiso']); return;
    }

    $id = intval($_GET['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID requerido']); return; }

    // Soft-delete
    $stmt = $pdo->prepare("UPDATE extintores SET estado = 'inactivo' WHERE id = ?");
    $stmt->execute([$id]);

    audit($uid, "Eliminar extintor", 'extintores', $id);
    echo json_encode(['success' => true]);
}

// ─── HELPER AUDITORÍA ────────────────────────────────────────────────────────
function audit($uid, $accion, $tabla, $rid) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("INSERT INTO auditoria (usuario_id,accion,tabla,registro_id,ip) VALUES (?,?,?,?,?)");
        $stmt->execute([$uid, $accion, $tabla, $rid, $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Exception $e) {}
}
