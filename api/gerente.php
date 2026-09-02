<?php
require_once '../config/config.php';
require_once '../config/roles-extra.php';
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'No autenticado']);
    exit;
}

$rol    = $_SESSION['rol'];
$uid    = $_SESSION['usuario_id'];
$action = $_GET['action'] ?? '';

// Asegurar que exista la tabla de asignaciones gerente↔empresa
asegurarTablaGerenteEmpresas($pdo);

switch ($action) {
    case 'mis_empresas':          misEmpresas();            break;  // gerente
    case 'listar_gerentes':       listarGerentes();         break;  // admin
    case 'empresas_de_gerente':   empresasDeGerente();      break;  // admin
    case 'guardar_asignacion':    guardarAsignacion();      break;  // admin
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Acción no válida']);
}

// ─── Crear tabla si no existe (migración ligera) ─────────────────────────────
function asegurarTablaGerenteEmpresas($pdo) {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS gerente_empresas (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                gerente_id  INT NOT NULL,
                empresa_id  INT NOT NULL,
                created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_gerente_empresa (gerente_id, empresa_id),
                KEY idx_gerente (gerente_id),
                KEY idx_empresa (empresa_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) { /* si falla, las acciones devolverán el error */ }
}

// ─── GERENTE: sus empresas con resumen (para las tarjetas) ───────────────────
function misEmpresas() {
    global $pdo, $rol, $uid;

    if ($rol !== ROLE_GERENTE) {
        http_response_code(403); echo json_encode(['error' => 'Sin permiso']); return;
    }

    $mes  = (int) date('n');
    $anio = (int) date('Y');

    $stmt = $pdo->prepare("
        SELECT emp.id, emp.nombre, emp.domicilio,
               (SELECT COUNT(*) FROM extintores e
                  WHERE e.empresa_id = emp.id AND e.estado != 'inactivo') AS total_extintores,
               (SELECT COUNT(DISTINCT i.extintor_id) FROM inspecciones i
                  JOIN extintores e ON e.id = i.extintor_id
                  WHERE e.empresa_id = emp.id AND e.estado != 'inactivo'
                    AND MONTH(i.fecha) = ? AND YEAR(i.fecha) = ?) AS inspeccionados_mes,
               (SELECT COUNT(*) FROM extintores e
                  WHERE e.empresa_id = emp.id AND e.estado != 'inactivo'
                    AND e.fecha_ph IS NOT NULL AND e.fecha_ph < CURDATE()) AS vencidos
        FROM gerente_empresas ge
        JOIN empresas emp ON emp.id = ge.empresa_id
        WHERE ge.gerente_id = ? AND emp.estado = 'activo'
        ORDER BY emp.nombre ASC
    ");
    $stmt->execute([$mes, $anio, $uid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['total_extintores']   = (int) $r['total_extintores'];
        $r['inspeccionados_mes'] = (int) $r['inspeccionados_mes'];
        $r['vencidos']           = (int) $r['vencidos'];
        $r['porcentaje'] = $r['total_extintores'] > 0
            ? round(($r['inspeccionados_mes'] / $r['total_extintores']) * 100) : 0;
    }
    unset($r);

    echo json_encode(['success' => true, 'data' => $rows]);
}

// ─── ADMIN: listar usuarios con rol gerente ──────────────────────────────────
function listarGerentes() {
    global $pdo, $rol;

    if ($rol !== ROLE_ADMIN) {
        http_response_code(403); echo json_encode(['error' => 'Sin permiso']); return;
    }

    $stmt = $pdo->query("
        SELECT id, nombre, username, email
        FROM usuarios
        WHERE rol = 'gerente' AND estado = 'activo'
        ORDER BY nombre ASC
    ");
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// ─── ADMIN: empresas asignadas a un gerente ──────────────────────────────────
function empresasDeGerente() {
    global $pdo, $rol;

    if ($rol !== ROLE_ADMIN) {
        http_response_code(403); echo json_encode(['error' => 'Sin permiso']); return;
    }

    $gerente_id = intval($_GET['gerente_id'] ?? 0);
    if (!$gerente_id) { http_response_code(400); echo json_encode(['error' => 'gerente_id requerido']); return; }

    $stmt = $pdo->prepare("SELECT empresa_id FROM gerente_empresas WHERE gerente_id = ?");
    $stmt->execute([$gerente_id]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    echo json_encode(['success' => true, 'data' => $ids]);
}

// ─── ADMIN: guardar (reemplazar) la lista de empresas de un gerente ──────────
function guardarAsignacion() {
    global $pdo, $rol, $uid;

    if ($rol !== ROLE_ADMIN) {
        http_response_code(403); echo json_encode(['error' => 'Sin permiso']); return;
    }

    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $gerente_id = intval($d['gerente_id'] ?? 0);
    $empresas   = $d['empresas'] ?? [];
    if (!is_array($empresas)) $empresas = [];
    $empresas = array_values(array_unique(array_filter(array_map('intval', $empresas), fn($x) => $x > 0)));

    if (!$gerente_id) { http_response_code(400); echo json_encode(['error' => 'gerente_id requerido']); return; }

    // Validar que el usuario destino sea gerente
    $chk = $pdo->prepare("SELECT id FROM usuarios WHERE id = ? AND rol = 'gerente'");
    $chk->execute([$gerente_id]);
    if (!$chk->fetch()) { http_response_code(400); echo json_encode(['error' => 'El usuario no es gerente']); return; }

    try {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM gerente_empresas WHERE gerente_id = ?")->execute([$gerente_id]);
        if ($empresas) {
            $ins = $pdo->prepare("INSERT INTO gerente_empresas (gerente_id, empresa_id) VALUES (?, ?)");
            foreach ($empresas as $eid) {
                $ins->execute([$gerente_id, $eid]);
            }
        }
        $pdo->commit();
        echo json_encode(['success' => true, 'asignadas' => count($empresas)]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Error al guardar asignación: ' . $e->getMessage()]);
    }
}
