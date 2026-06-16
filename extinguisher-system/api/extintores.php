<?php
require_once '../config/config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'No autenticado']);
    exit;
}

$rol    = $_SESSION['rol'];
$uid    = $_SESSION['usuario_id'];
$action = $_GET['action'] ?? '';

// Cliente solo puede listar (ver sus extintores)
if ($rol === ROLE_CLIENTE && !in_array($action, ['listar'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Sin permiso']);
    exit;
}

switch ($action) {
    case 'listar':                listar();                 break;
    case 'obtener':               obtener();                break;
    case 'buscar_qr':             buscarQR();               break;
    case 'crear':                 crear();                  break;
    case 'editar':                editar();                 break;
    case 'eliminar':              eliminar();               break;
    case 'get_tipos':             obtenerTipos();           break;
    case 'obtener_qr_disponible': obtenerQRDisponible();    break;
    case 'asignar_qr':            asignarQR();              break;
    case 'modificar_qr':          modificarQR();            break;
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
               te.nombre AS tipo_nombre,
               emp.nombre AS empresa_nombre,
               u.nombre   AS creado_por_nombre,
               (SELECT fecha FROM inspecciones
                WHERE extintor_id = e.id
                ORDER BY fecha DESC, hora DESC LIMIT 1) AS ultima_inspeccion
        FROM extintores e
        JOIN tipos_extintores te ON te.id = e.tipo
        JOIN empresas  emp ON emp.id = e.empresa_id
        JOIN usuarios  u   ON u.id  = e.creado_por
        $where
        ORDER BY CAST(REPLACE(e.codigo_manual, 'EXT-', '') AS UNSIGNED) ASC
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
        SELECT e.*, te.nombre AS tipo_nombre, emp.nombre AS empresa_nombre
        FROM extintores e
        JOIN tipos_extintores te ON te.id = e.tipo
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
        SELECT e.*, te.nombre AS tipo_nombre, emp.nombre AS empresa_nombre
        FROM extintores e
        JOIN tipos_extintores te ON te.id = e.tipo
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

    // Validar que el tipo existe
    $stmt = $pdo->prepare("SELECT id FROM tipos_extintores WHERE id = ? AND estado = 'activo'");
    $stmt->execute([$d['tipo']]);
    if (!$stmt->fetch()) {
        http_response_code(400);
        echo json_encode(['error' => 'Tipo de extintor no válido']);
        return;
    }

    // QR es opcional — si se provee debe ser 11 dígitos únicos
    $codigo_qr = null;
    if (!empty($d['codigo_qr'])) {
        $codigo_qr = trim($d['codigo_qr']);
        if (!preg_match('/^\d{11}$/', $codigo_qr)) {
            http_response_code(400);
            echo json_encode(['error' => 'El código QR debe ser exactamente 11 dígitos numéricos']);
            return;
        }
        $stmt = $pdo->prepare("SELECT id FROM extintores WHERE codigo_qr = ?");
        $stmt->execute([$codigo_qr]);
        if ($stmt->fetch()) {
            http_response_code(409);
            echo json_encode(['error' => 'Este código QR ya está asignado a otro extintor']);
            return;
        }
    }

    // Generar código manual correlativo por empresa
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM extintores WHERE empresa_id = ?");
    $stmt->execute([$d['empresa_id']]);
    $num = $stmt->fetchColumn() + 1;
    $codigo_manual = $d['codigo_manual'] ?? ('EXT-' . str_pad($num, 3, '0', STR_PAD_LEFT));

    try {
        $pdo->beginTransaction();
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
            intval($d['tipo']),
            $d['capacidad']     ?? null,
            $d['fecha_recarga'] ?? null,
            $d['fecha_ph']      ?? null,
            $d['estado']        ?? 'activo',
            $d['observaciones'] ?? null,
            $uid,
        ]);

        $id = $pdo->lastInsertId();

        // Si se asignó QR, registrar en auditoría de QR y actualizar secuencia
        if ($codigo_qr) {
            registrarAsignacionQR($id, null, $codigo_qr, $uid, $rol);
            actualizarSecuenciaQR($codigo_qr, $uid);
        }

        audit($uid, "Crear extintor $codigo_manual", 'extintores', $id);
        $pdo->commit();

        echo json_encode([
            'success'       => true,
            'id'            => $id,
            'codigo_qr'     => $codigo_qr,
            'codigo_manual' => $codigo_manual,
        ]);
    } catch (PDOException $e) {
        $pdo->rollBack();
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

    // Si se cambia el codigo_manual, validar que no exista en la misma empresa
    if (!empty($d['codigo_manual'])) {
        $stmt = $pdo->prepare("
            SELECT empresa_id FROM extintores WHERE id = ?
        ");
        $stmt->execute([$id]);
        $extintor = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($extintor) {
            $stmt = $pdo->prepare("
                SELECT id FROM extintores
                WHERE empresa_id = ? AND codigo_manual = ? AND id != ?
                LIMIT 1
            ");
            $stmt->execute([$extintor['empresa_id'], $d['codigo_manual'], $id]);
            if ($stmt->fetch()) {
                http_response_code(400);
                echo json_encode(['error' => 'Este código ya existe en la empresa']);
                return;
            }
        }
    }

    // Validar tipo si se proporciona
    if (!empty($d['tipo'])) {
        $stmt = $pdo->prepare("SELECT id FROM tipos_extintores WHERE id = ? AND estado = 'activo'");
        $stmt->execute([$d['tipo']]);
        if (!$stmt->fetch()) {
            http_response_code(400);
            echo json_encode(['error' => 'Tipo de extintor no válido']);
            return;
        }
    }

    $stmt = $pdo->prepare("
        UPDATE extintores SET
            codigo_manual = ?,
            empresa_id    = ?,
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
        $d['codigo_manual'] ?? null,
        $d['empresa_id']    ?? null,
        $d['seccion']       ?? null,
        $d['ubicacion']     ?? '',
        !empty($d['tipo']) ? intval($d['tipo']) : null,
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

// ─── OBTENER TIPOS DE EXTINTORES ──────────────────────────────────────────────
function obtenerTipos() {
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT id, nombre, descripcion
        FROM tipos_extintores
        WHERE estado = 'activo'
        ORDER BY nombre ASC
    ");
    $stmt->execute();

    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// ─── OBTENER PRÓXIMO QR DISPONIBLE ───────────────────────────────────────────
function obtenerQRDisponible() {
    global $pdo, $rol;

    if (!in_array($rol, [ROLE_ADMIN, ROLE_INSPECTOR])) {
        http_response_code(403); echo json_encode(['error' => 'Sin permiso']); return;
    }

    try {
        $stmt = $pdo->prepare("SELECT proximo_qr FROM qr_secuencias WHERE id = 1");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            http_response_code(500);
            echo json_encode(['error' => 'Tabla qr_secuencias no inicializada']);
            return;
        }

        $proximo = (int)$row['proximo_qr'];
        $qr = str_pad($proximo, 11, '0', STR_PAD_LEFT);

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM extintores WHERE codigo_qr IS NOT NULL");
        $stmt->execute();
        $asignados = (int)$stmt->fetchColumn();

        echo json_encode([
            'success'        => true,
            'proximo_qr'     => $qr,
            'total_asignados'=> $asignados,
            'disponibles'    => 99999999999 - $proximo + 1,
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error al obtener QR']);
    }
}

// ─── ASIGNAR QR (primera vez, inspector o admin) ──────────────────────────────
function asignarQR() {
    global $pdo, $rol, $uid;

    if (!in_array($rol, [ROLE_ADMIN, ROLE_INSPECTOR])) {
        http_response_code(403); echo json_encode(['error' => 'Sin permiso']); return;
    }

    $d           = json_decode(file_get_contents('php://input'), true);
    $extintor_id = intval($d['extintor_id'] ?? 0);
    $codigo_qr   = trim($d['codigo_qr'] ?? '');

    if (!$extintor_id) { http_response_code(400); echo json_encode(['error' => 'extintor_id requerido']); return; }

    if (!preg_match('/^\d{11}$/', $codigo_qr)) {
        http_response_code(400);
        echo json_encode(['error' => 'El código QR debe ser exactamente 11 dígitos numéricos']);
        return;
    }

    $stmt = $pdo->prepare("SELECT id, codigo_qr, empresa_id FROM extintores WHERE id = ?");
    $stmt->execute([$extintor_id]);
    $ext = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ext) { http_response_code(404); echo json_encode(['error' => 'Extintor no encontrado']); return; }

    // Inspector: solo puede asignar si aún no tiene QR
    if ($rol === ROLE_INSPECTOR && $ext['codigo_qr'] !== null) {
        http_response_code(403);
        echo json_encode(['error' => 'Este extintor ya tiene QR asignado. Solo Admin puede modificarlo']);
        return;
    }

    // Verificar unicidad
    $stmt = $pdo->prepare("SELECT id FROM extintores WHERE codigo_qr = ? AND id != ?");
    $stmt->execute([$codigo_qr, $extintor_id]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'Este código QR ya está asignado a otro extintor']);
        return;
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("UPDATE extintores SET codigo_qr = ? WHERE id = ?");
        $stmt->execute([$codigo_qr, $extintor_id]);
        registrarAsignacionQR($extintor_id, $ext['codigo_qr'], $codigo_qr, $uid, $rol);
        actualizarSecuenciaQR($codigo_qr, $uid);
        audit($uid, "Asignar QR $codigo_qr a extintor #$extintor_id", 'extintores', $extintor_id);
        $pdo->commit();
        echo json_encode(['success' => true, 'codigo_qr' => $codigo_qr]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Error al asignar QR']);
    }
}

// ─── MODIFICAR QR (solo admin) ────────────────────────────────────────────────
function modificarQR() {
    global $pdo, $rol, $uid;

    if ($rol !== ROLE_ADMIN) {
        http_response_code(403); echo json_encode(['error' => 'Solo Admin puede modificar QR']); return;
    }

    $d              = json_decode(file_get_contents('php://input'), true);
    $extintor_id    = intval($d['extintor_id'] ?? 0);
    $codigo_qr_nuevo = trim($d['codigo_qr_nuevo'] ?? '');

    if (!$extintor_id) { http_response_code(400); echo json_encode(['error' => 'extintor_id requerido']); return; }

    if (!preg_match('/^\d{11}$/', $codigo_qr_nuevo)) {
        http_response_code(400);
        echo json_encode(['error' => 'El código QR debe ser exactamente 11 dígitos numéricos']);
        return;
    }

    $stmt = $pdo->prepare("SELECT id, codigo_qr FROM extintores WHERE id = ?");
    $stmt->execute([$extintor_id]);
    $ext = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ext) { http_response_code(404); echo json_encode(['error' => 'Extintor no encontrado']); return; }

    $stmt = $pdo->prepare("SELECT id FROM extintores WHERE codigo_qr = ? AND id != ?");
    $stmt->execute([$codigo_qr_nuevo, $extintor_id]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'El nuevo QR ya está asignado a otro extintor']);
        return;
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("UPDATE extintores SET codigo_qr = ? WHERE id = ?");
        $stmt->execute([$codigo_qr_nuevo, $extintor_id]);
        registrarAsignacionQR($extintor_id, $ext['codigo_qr'], $codigo_qr_nuevo, $uid, $rol);
        actualizarSecuenciaQR($codigo_qr_nuevo, $uid);
        audit($uid, "Modificar QR {$ext['codigo_qr']} → $codigo_qr_nuevo", 'extintores', $extintor_id);
        $pdo->commit();
        echo json_encode(['success' => true, 'codigo_qr_anterior' => $ext['codigo_qr'], 'codigo_qr_nuevo' => $codigo_qr_nuevo]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Error al modificar QR']);
    }
}

// ─── HELPER: Registrar asignación en qr_asignaciones ─────────────────────────
function registrarAsignacionQR($extintor_id, $anterior, $nuevo, $uid, $rol) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            INSERT INTO qr_asignaciones (extintor_id, qr_anterior, qr_nuevo, asignado_por, rol_asignador)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$extintor_id, $anterior, $nuevo, $uid, $rol]);
    } catch (Exception $e) {}
}

// ─── HELPER: Actualizar secuencia QR ─────────────────────────────────────────
function actualizarSecuenciaQR($codigo_qr, $uid) {
    global $pdo;
    try {
        $num = (int)$codigo_qr;
        $stmt = $pdo->prepare("
            UPDATE qr_secuencias
            SET proximo_qr = GREATEST(proximo_qr, ? + 1), actualizado_por = ?
            WHERE id = 1
        ");
        $stmt->execute([$num, $uid]);
    } catch (Exception $e) {}
}

// ─── HELPER AUDITORÍA ────────────────────────────────────────────────────────
function audit($uid, $accion, $tabla, $rid) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("INSERT INTO auditoria (usuario_id,accion,tabla,registro_id,ip) VALUES (?,?,?,?,?)");
        $stmt->execute([$uid, $accion, $tabla, $rid, $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Exception $e) {}
}
