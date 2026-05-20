<?php
require_once '../config/config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'No autenticado']);
    exit;
}

// Cliente no tiene acceso a inspecciones individuales
if ($_SESSION['rol'] === ROLE_CLIENTE) {
    http_response_code(403);
    echo json_encode(['error' => 'Sin permiso']);
    exit;
}

$rol    = $_SESSION['rol'];
$uid    = $_SESSION['usuario_id'];
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'guardar':  guardar();  break;
    case 'listar':   listar();   break;
    case 'obtener':  obtener();  break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Acción no válida']);
}

// ─── GUARDAR INSPECCIÓN ──────────────────────────────────────────────────────
function guardar() {
    global $pdo, $rol, $uid;

    if ($rol !== ROLE_INSPECTOR) {
        http_response_code(403);
        echo json_encode(['error' => 'Solo inspectores pueden registrar inspecciones']);
        return;
    }

    $d = json_decode(file_get_contents('php://input'), true);

    if (empty($d['extintor_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'extintor_id requerido']);
        return;
    }

    $campos_checklist = ['ser','mg','po','ph','sg','ps','ob','dan','pin','fn','gb','rv'];
    $valores_validos  = ['OK','NC','NA','PO',null];

    foreach ($campos_checklist as $c) {
        $v = $d[$c] ?? null;
        if ($v !== null && !in_array($v, $valores_validos)) {
            http_response_code(400);
            echo json_encode(['error' => "Valor inválido para $c"]);
            return;
        }
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO inspecciones
                (extintor_id, inspector_id, fecha, hora,
                 ser, mg, po, ph, sg, ps, ob, dan, pin, fn, gb, rv,
                 tipo_inspeccion, capacidad_insp,
                 observaciones)
            VALUES (?,?,CURDATE(),CURTIME(),?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $d['extintor_id'],
            $uid,
            $d['ser']             ?? null,
            $d['mg']              ?? null,
            $d['po']              ?? null,
            $d['ph']              ?? null,
            $d['sg']              ?? null,
            $d['ps']              ?? null,
            $d['ob']              ?? null,
            $d['dan']             ?? null,
            $d['pin']             ?? null,
            $d['fn']              ?? null,
            $d['gb']              ?? null,
            $d['rv']              ?? null,
            $d['tipo_inspeccion'] ?? null,
            $d['capacidad_insp']  ?? null,
            $d['observaciones']   ?? null,
        ]);

        $id = $pdo->lastInsertId();
        audit($uid, "Inspección extintor #{$d['extintor_id']}", 'inspecciones', $id);

        echo json_encode(['success' => true, 'id' => $id]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error al guardar inspección']);
    }
}

// ─── LISTAR ──────────────────────────────────────────────────────────────────
function listar() {
    global $pdo, $rol, $uid;

    $extintor_id = intval($_GET['extintor_id'] ?? 0);
    $empresa_id  = intval($_GET['empresa_id']  ?? 0);
    $mes         = intval($_GET['mes']         ?? 0);
    $anio        = intval($_GET['anio']        ?? 0);

    $where  = ['1=1'];
    $params = [];

    if ($extintor_id) { $where[] = 'i.extintor_id = ?'; $params[] = $extintor_id; }
    if ($empresa_id)  { $where[] = 'e.empresa_id  = ?'; $params[] = $empresa_id;  }
    if ($mes)         { $where[] = 'MONTH(i.fecha) = ?'; $params[] = $mes;        }
    if ($anio)        { $where[] = 'YEAR(i.fecha)  = ?'; $params[] = $anio;       }

    // Inspector sólo ve las suyas
    if ($rol === ROLE_INSPECTOR) {
        $where[]  = 'i.inspector_id = ?';
        $params[] = $uid;
    }

    $w = implode(' AND ', $where);

    $stmt = $pdo->prepare("
        SELECT i.*,
               e.codigo_manual, e.ubicacion, e.tipo AS tipo_extintor,
               emp.nombre AS empresa_nombre,
               u.nombre   AS inspector_nombre
        FROM inspecciones i
        JOIN extintores e  ON e.id   = i.extintor_id
        JOIN empresas   emp ON emp.id = e.empresa_id
        JOIN usuarios   u  ON u.id   = i.inspector_id
        WHERE $w
        ORDER BY i.fecha DESC, i.hora DESC
    ");
    $stmt->execute($params);

    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// ─── OBTENER UNA ─────────────────────────────────────────────────────────────
function obtener() {
    global $pdo;

    $id = intval($_GET['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID requerido']); return; }

    $stmt = $pdo->prepare("
        SELECT i.*,
               e.codigo_manual, e.ubicacion, e.tipo AS tipo_extintor,
               emp.nombre AS empresa_nombre,
               u.nombre   AS inspector_nombre
        FROM inspecciones i
        JOIN extintores e   ON e.id   = i.extintor_id
        JOIN empresas   emp ON emp.id = e.empresa_id
        JOIN usuarios   u   ON u.id   = i.inspector_id
        WHERE i.id = ?
    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) { http_response_code(404); echo json_encode(['error' => 'No encontrada']); return; }

    echo json_encode(['success' => true, 'data' => $row]);
}

// ─── HELPER ──────────────────────────────────────────────────────────────────
function audit($uid, $accion, $tabla, $rid) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("INSERT INTO auditoria (usuario_id,accion,tabla,registro_id,ip) VALUES (?,?,?,?,?)");
        $stmt->execute([$uid, $accion, $tabla, $rid, $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Exception $e) {}
}
