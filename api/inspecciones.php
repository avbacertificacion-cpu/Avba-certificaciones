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
    case 'guardar':             guardar();             break;
    case 'listar':              listar();              break;
    case 'obtener':             obtener();             break;
    case 'editar_fecha':        editarFecha();         break;
    case 'inspeccionar_planta': inspeccionarPlanta();  break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Acción no válida']);
}

// ─── MARCAR TODA UNA PLANTA COMO INSPECCIONADA ───────────────────────────────
// Crea una inspección del mes destino para cada extintor de la empresa,
// copiando el estatus de su última inspección previa (el "mes anterior").
// No sobrescribe extintores que ya tengan inspección en el mes destino.
function inspeccionarPlanta() {
    global $pdo, $rol, $uid;

    if (!in_array($rol, [ROLE_ADMIN, ROLE_INSPECTOR])) {
        http_response_code(403); echo json_encode(['error' => 'Sin permiso']); return;
    }

    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $empresa_id = intval($d['empresa_id'] ?? 0);
    $fecha      = trim($d['fecha'] ?? '');

    if (!$empresa_id) {
        http_response_code(400); echo json_encode(['error' => 'Selecciona una planta (empresa)']); return;
    }

    $dt = DateTime::createFromFormat('Y-m-d', $fecha);
    if (!$dt || $dt->format('Y-m-d') !== $fecha) {
        http_response_code(400); echo json_encode(['error' => 'Fecha inválida (formato AAAA-MM-DD)']); return;
    }
    $mes  = (int) $dt->format('n');
    $anio = (int) $dt->format('Y');
    $hora = date('H:i:s');

    try {
        $pdo->beginTransaction();

        $extStmt = $pdo->prepare("SELECT id FROM extintores WHERE empresa_id = ?");
        $extStmt->execute([$empresa_id]);
        $ids = $extStmt->fetchAll(PDO::FETCH_COLUMN);

        // ¿Ya tiene inspección en el mes destino?
        $yaMes = $pdo->prepare("SELECT id FROM inspecciones WHERE extintor_id=? AND MONTH(fecha)=? AND YEAR(fecha)=? LIMIT 1");
        // Última inspección ANTES del mes destino (estatus del mes anterior)
        $prev = $pdo->prepare("
            SELECT ser,mg,po,ph,sg,ps,ob,dan,pin,fn,gb,rv,observaciones
            FROM inspecciones
            WHERE extintor_id = ?
              AND (YEAR(fecha) < ? OR (YEAR(fecha) = ? AND MONTH(fecha) < ?))
            ORDER BY fecha DESC, hora DESC
            LIMIT 1
        ");
        $ins = $pdo->prepare("
            INSERT INTO inspecciones
                (extintor_id, inspector_id, fecha, hora,
                 ser, mg, po, ph, sg, ps, ob, dan, pin, fn, gb, rv, observaciones)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");

        $creados = 0; $ya_tenian = 0; $sin_historial = 0;
        foreach ($ids as $eid) {
            $yaMes->execute([$eid, $mes, $anio]);
            if ($yaMes->fetch()) { $ya_tenian++; continue; }

            $prev->execute([$eid, $anio, $anio, $mes]);
            $p = $prev->fetch(PDO::FETCH_ASSOC);
            if (!$p) { $sin_historial++; continue; }

            $ins->execute([
                $eid, $uid, $fecha, $hora,
                $p['ser'], $p['mg'], $p['po'], $p['ph'], $p['sg'], $p['ps'],
                $p['ob'], $p['dan'], $p['pin'], $p['fn'], $p['gb'], $p['rv'],
                $p['observaciones']
            ]);
            $creados++;
        }

        $pdo->commit();

        $msg = "$creados extintor(es) marcados como inspeccionados este mes.";
        if ($ya_tenian > 0)     $msg .= " $ya_tenian ya tenían inspección este mes (se respetaron).";
        if ($sin_historial > 0) $msg .= " $sin_historial sin inspección previa (no se pudo copiar el estatus).";

        audit($uid, "Marcar planta #$empresa_id inspeccionada ($fecha): $creados creados", 'inspecciones', $empresa_id);
        echo json_encode([
            'success'       => true,
            'creados'       => $creados,
            'ya_tenian'     => $ya_tenian,
            'sin_historial' => $sin_historial,
            'message'       => $msg
        ]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Error al marcar la planta: ' . $e->getMessage()]);
    }
}

// ─── EDITAR FECHA DE UNA O VARIAS INSPECCIONES (ADMIN) ───────────────────────
// Reasigna la fecha de inspecciones seleccionadas (p.ej. inspecciones hechas el
// 1-2 de julio que corresponden al reporte de junio → 30/06).
function editarFecha() {
    global $pdo, $rol, $uid;

    if ($rol !== ROLE_ADMIN) {
        http_response_code(403);
        echo json_encode(['error' => 'Solo el administrador puede cambiar fechas de inspección']);
        return;
    }

    $d   = json_decode(file_get_contents('php://input'), true) ?: [];
    $ids = $d['ids'] ?? [];
    if (!is_array($ids)) $ids = [$ids];
    $ids = array_values(array_filter(array_map('intval', $ids), fn($x) => $x > 0));

    $nueva = trim($d['nueva_fecha'] ?? '');

    if (empty($ids)) {
        http_response_code(400);
        echo json_encode(['error' => 'No se seleccionaron inspecciones']);
        return;
    }

    // Validar fecha destino real (AAAA-MM-DD)
    $dt = DateTime::createFromFormat('Y-m-d', $nueva);
    if (!$dt || $dt->format('Y-m-d') !== $nueva) {
        http_response_code(400);
        echo json_encode(['error' => 'Fecha destino inválida (formato AAAA-MM-DD)']);
        return;
    }
    $mesDest  = (int) $dt->format('n');
    $anioDest = (int) $dt->format('Y');

    try {
        $pdo->beginTransaction();

        $sel      = $pdo->prepare("SELECT extintor_id, fecha FROM inspecciones WHERE id = ?");
        $conflict = $pdo->prepare("
            SELECT id FROM inspecciones
            WHERE extintor_id = ? AND MONTH(fecha) = ? AND YEAR(fecha) = ? AND id <> ?
            LIMIT 1
        ");
        $upd = $pdo->prepare("UPDATE inspecciones SET fecha = ? WHERE id = ?");

        $cambiados   = 0;
        $conflictos  = 0;
        foreach ($ids as $id) {
            $sel->execute([$id]);
            $row = $sel->fetch(PDO::FETCH_ASSOC);
            if (!$row) continue;
            if ($row['fecha'] === $nueva) continue;

            // Evitar duplicar inspección del mismo extintor en el mes destino
            $conflict->execute([$row['extintor_id'], $mesDest, $anioDest, $id]);
            if ($conflict->fetch()) { $conflictos++; continue; }

            $upd->execute([$nueva, $id]);
            audit($uid, "Cambiar fecha inspección #$id de {$row['fecha']} a $nueva", 'inspecciones', $id);
            $cambiados++;
        }

        $pdo->commit();

        $msg = "Fecha actualizada en $cambiados inspección(es).";
        if ($conflictos > 0) {
            $msg .= " $conflictos se omitieron porque ese extintor ya tenía una inspección en el mes destino.";
        }
        echo json_encode(['success' => true, 'cambiados' => $cambiados, 'conflictos' => $conflictos, 'message' => $msg]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Error al cambiar la fecha: ' . $e->getMessage()]);
    }
}

// ─── GUARDAR INSPECCIÓN ──────────────────────────────────────────────────────
function guardar() {
    global $pdo, $rol, $uid;

    if (!in_array($rol, [ROLE_INSPECTOR, ROLE_ADMIN])) {
        http_response_code(403);
        echo json_encode(['error' => 'No tienes permiso para registrar inspecciones']);
        return;
    }

    $d = json_decode(file_get_contents('php://input'), true);

    if (empty($d['extintor_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'extintor_id requerido']);
        return;
    }

    if (empty($d['fecha'])) {
        http_response_code(400);
        echo json_encode(['error' => 'fecha requerida']);
        return;
    }

    if (empty($d['hora'])) {
        http_response_code(400);
        echo json_encode(['error' => 'hora requerida']);
        return;
    }

    // Validar que el extintor pertenece a la empresa seleccionada (si se proporciona)
    if (!empty($d['empresa_id'])) {
        $stmt = $pdo->prepare("SELECT empresa_id FROM extintores WHERE id = ?");
        $stmt->execute([$d['extintor_id']]);
        $extintor = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$extintor || $extintor['empresa_id'] != $d['empresa_id']) {
            http_response_code(403);
            echo json_encode(['error' => 'Extintor no pertenece a la empresa seleccionada']);
            return;
        }
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
        // Extraer mes y año de la fecha
        $fecha_parts = explode('-', $d['fecha']);
        $mes = intval($fecha_parts[1]);
        $anio = intval($fecha_parts[0]);

        // Buscar inspección existente del mismo extintor en el mismo mes/año
        $stmt = $pdo->prepare("
            SELECT id FROM inspecciones
            WHERE extintor_id = ? AND MONTH(fecha) = ? AND YEAR(fecha) = ?
            LIMIT 1
        ");
        $stmt->execute([$d['extintor_id'], $mes, $anio]);
        $inspeccion_existente = $stmt->fetch(PDO::FETCH_ASSOC);

        $id = null;

        if ($inspeccion_existente) {
            // UPDATE: Reemplazar inspección del mismo mes
            $id = $inspeccion_existente['id'];
            $stmt = $pdo->prepare("
                UPDATE inspecciones
                SET inspector_id=?, fecha=?, hora=?,
                    ser=?, mg=?, po=?, ph=?, sg=?, ps=?, ob=?, dan=?, pin=?, fn=?, gb=?, rv=?,
                    observaciones=?
                WHERE id=?
            ");
            $stmt->execute([
                $uid,
                $d['fecha'],
                $d['hora'],
                $d['ser']           ?? null,
                $d['mg']            ?? null,
                $d['po']            ?? null,
                $d['ph']            ?? null,
                $d['sg']            ?? null,
                $d['ps']            ?? null,
                $d['ob']            ?? null,
                $d['dan']           ?? null,
                $d['pin']           ?? null,
                $d['fn']            ?? null,
                $d['gb']            ?? null,
                $d['rv']            ?? null,
                $d['observaciones'] ?? null,
                $id
            ]);
            audit($uid, "Actualizar inspección extintor #{$d['extintor_id']}", 'inspecciones', $id);
        } else {
            // INSERT: Nueva inspección de otro mes
            $stmt = $pdo->prepare("
                INSERT INTO inspecciones
                    (extintor_id, inspector_id, fecha, hora,
                     ser, mg, po, ph, sg, ps, ob, dan, pin, fn, gb, rv,
                     observaciones)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt->execute([
                $d['extintor_id'],
                $uid,
                $d['fecha'],
                $d['hora'],
                $d['ser']           ?? null,
                $d['mg']            ?? null,
                $d['po']            ?? null,
                $d['ph']            ?? null,
                $d['sg']            ?? null,
                $d['ps']            ?? null,
                $d['ob']            ?? null,
                $d['dan']           ?? null,
                $d['pin']           ?? null,
                $d['fn']            ?? null,
                $d['gb']            ?? null,
                $d['rv']            ?? null,
                $d['observaciones'] ?? null,
            ]);
            $id = $pdo->lastInsertId();
            audit($uid, "Crear inspección extintor #{$d['extintor_id']}", 'inspecciones', $id);
        }

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
