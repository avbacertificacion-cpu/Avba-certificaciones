<?php
/**
 * Mantenimiento de extintores (solo ADMIN).
 *
 * El taller es nuestro: el extintor no "sale" a ningún lado, ENTRA al taller
 * y más tarde se DEVUELVE al cliente. Por eso las fechas se llaman entrada y
 * devolución, y un registro sin fecha de devolución es un extintor que sigue
 * en el taller. El estado sale de ahí, no de una columna aparte que pudiera
 * contradecir a las fechas.
 *
 * Los motivos (mantenimiento, recarga, garantía…) son un catálogo editable:
 * cada taller trabaja con los suyos y deben poder darse de alta sin tocar el
 * código. Uno de ellos puede marcar "al devolver, actualiza la fecha de
 * recarga del extintor", que es lo que antes estaba escrito a mano.
 *
 * Las tablas se crean solas la primera vez (no requiere migración manual).
 */
require_once '../config/config.php';
require_once '../config/mayusculas.php';
require_once '../config/taller.php';
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401); echo json_encode(['error' => 'No autenticado']); exit;
}
$rol = $_SESSION['rol'];
$uid = $_SESSION['usuario_id'];
if ($rol !== ROLE_ADMIN) {
    http_response_code(403); echo json_encode(['error' => 'Sin permiso']); exit;
}

asegurarTablas($pdo);

switch ($_GET['action'] ?? '') {
    case 'listar':             listar();            break;
    case 'obtener':            obtener();           break;
    case 'extintor':           fichaExtintor();     break;
    case 'guardar':            guardar();           break;
    case 'registrar_devolucion': registrarDevolucion(); break;
    case 'eliminar':           eliminar();          break;
    case 'sugerir':            sugerir();           break;
    case 'responsables':       responsables();      break;
    case 'resumen':            resumen();           break;
    // Catálogo de motivos
    case 'listar_tipos':       listarTipos();       break;
    case 'guardar_tipo':       guardarTipo();       break;
    case 'eliminar_tipo':      eliminarTipo();      break;
    default:
        http_response_code(400); echo json_encode(['error' => 'Acción no válida']);
}

// ─── Migración ligera ────────────────────────────────────────────────────────
function sqlMovimiento(): string {
    return "
        SELECT m.*,
               e.codigo_manual, e.seccion, e.ubicacion, e.capacidad,
               e.fecha_recarga, e.fecha_ph,
               te.nombre  AS tipo_extintor,
               emp.id     AS empresa_id,
               emp.nombre AS empresa_nombre,
               mt.nombre  AS motivo,
               mt.actualiza_recarga,
               (m.fecha_devolucion IS NULL) AS en_taller
        FROM extintor_mantenimientos m
        JOIN extintores e   ON e.id = m.extintor_id
        JOIN empresas emp   ON emp.id = e.empresa_id
        LEFT JOIN tipos_extintores te ON te.id = e.tipo
        LEFT JOIN mantenimiento_tipos mt ON mt.id = m.tipo_id
    ";
}

// ─── Listado ─────────────────────────────────────────────────────────────────
function listar() {
    global $pdo;
    $where = ['1=1'];
    $params = [];

    if ($eid = intval($_GET['empresa_id'] ?? 0)) { $where[] = 'e.empresa_id = ?'; $params[] = $eid; }
    if ($xid = intval($_GET['extintor_id'] ?? 0)) { $where[] = 'm.extintor_id = ?'; $params[] = $xid; }
    if ($tid = intval($_GET['tipo_id'] ?? 0))     { $where[] = 'm.tipo_id = ?';    $params[] = $tid; }

    // "taller" = todavía lo tenemos; "devuelto" = ya se entregó
    $estado = trim($_GET['estado'] ?? '');
    if ($estado === 'taller')   $where[] = 'm.fecha_devolucion IS NULL';
    if ($estado === 'devuelto') $where[] = 'm.fecha_devolucion IS NOT NULL';

    $sql = sqlMovimiento() . ' WHERE ' . implode(' AND ', $where)
         . ' ORDER BY m.fecha_entrada DESC, m.id DESC LIMIT 500';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    echo json_encode(['success' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}

function obtener() {
    global $pdo;
    $id = intval($_GET['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID requerido']); return; }
    $st = $pdo->prepare(sqlMovimiento() . ' WHERE m.id = ?');
    $st->execute([$id]);
    $m = $st->fetch(PDO::FETCH_ASSOC);
    if (!$m) { http_response_code(404); echo json_encode(['error' => 'Movimiento no encontrado']); return; }
    echo json_encode(['success' => true, 'data' => $m]);
}

// ─── Ficha del extintor + su control completo ────────────────────────────────
function fichaExtintor() {
    global $pdo;
    $id = intval($_GET['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID requerido']); return; }

    $st = $pdo->prepare("
        SELECT e.id, e.codigo_manual, e.codigo_qr, e.seccion, e.ubicacion, e.capacidad,
               e.fecha_recarga, e.fecha_ph, e.estado, e.observaciones,
               te.nombre AS tipo_extintor, emp.nombre AS empresa_nombre,
               (SELECT fecha FROM inspecciones WHERE extintor_id = e.id
                ORDER BY fecha DESC, hora DESC LIMIT 1) AS ultima_inspeccion
        FROM extintores e
        LEFT JOIN tipos_extintores te ON te.id = e.tipo
        JOIN empresas emp ON emp.id = e.empresa_id
        WHERE e.id = ?
    ");
    $st->execute([$id]);
    $ext = $st->fetch(PDO::FETCH_ASSOC);
    if (!$ext) { http_response_code(404); echo json_encode(['error' => 'Extintor no encontrado']); return; }

    $st = $pdo->prepare(sqlMovimiento() . ' WHERE m.extintor_id = ? ORDER BY m.fecha_entrada DESC, m.id DESC');
    $st->execute([$id]);
    $ext['historial'] = $st->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $ext]);
}

// ─── Alta y edición ──────────────────────────────────────────────────────────
function guardar() {
    global $pdo, $uid;
    $d = entradaEnMayusculas(json_decode(file_get_contents('php://input'), true)) ?: [];

    $id        = intval($d['id'] ?? 0);
    $extId     = intval($d['extintor_id'] ?? 0);
    $tipoId    = intval($d['tipo_id'] ?? 0);
    $entrada   = fechaValida($d['fecha_entrada'] ?? '');
    $devuelto  = fechaValida($d['fecha_devolucion'] ?? '');

    if (!$extId)   { http_response_code(400); echo json_encode(['error' => 'Elige el extintor']); return; }
    if (!$entrada) { http_response_code(400); echo json_encode(['error' => 'Indica la fecha en que entró al taller']); return; }
    if ($devuelto && $devuelto < $entrada) {
        http_response_code(400);
        echo json_encode(['error' => 'La fecha de devolución no puede ser anterior a la de entrada']);
        return;
    }

    $st = $pdo->prepare("SELECT id FROM extintores WHERE id = ?");
    $st->execute([$extId]);
    if (!$st->fetchColumn()) { http_response_code(400); echo json_encode(['error' => 'El extintor no existe']); return; }

    $st = $pdo->prepare("SELECT id, actualiza_recarga FROM mantenimiento_tipos WHERE id = ? AND estado = 'activo'");
    $st->execute([$tipoId]);
    $tipo = $st->fetch(PDO::FETCH_ASSOC);
    if (!$tipo) { http_response_code(400); echo json_encode(['error' => 'Elige el motivo del ingreso']); return; }

    $quien = trim($d['realizado_por'] ?? '');
    if ($quien === '') {
        http_response_code(400); echo json_encode(['error' => 'Indica quién realizó el servicio']); return;
    }
    $quien = mb_substr($quien, 0, 150);
    $notas = trim($d['notas'] ?? '') ?: null;
    $campos = [$extId, $tipoId, $entrada, $devuelto, $quien, $notas];

    try {
        $pdo->beginTransaction();

        if ($id) {
            $pdo->prepare("
                UPDATE extintor_mantenimientos
                SET extintor_id=?, tipo_id=?, fecha_entrada=?, fecha_devolucion=?, realizado_por=?, notas=?
                WHERE id=?
            ")->execute(array_merge($campos, [$id]));
        } else {
            $pdo->prepare("
                INSERT INTO extintor_mantenimientos
                    (extintor_id, tipo_id, fecha_entrada, fecha_devolucion, realizado_por, notas, creado_por)
                VALUES (?,?,?,?,?,?,?)
            ")->execute(array_merge($campos, [$uid]));
            $id = $pdo->lastInsertId();
        }

        // Un motivo marcado como "actualiza la recarga" puede llevar su fecha de
        // devolución a la ficha del extintor. Va con casilla desde la pantalla:
        // si no, el extintor y el movimiento acaban diciendo cosas distintas.
        if (!empty($d['actualizar_recarga']) && (int) $tipo['actualiza_recarga'] === 1 && $devuelto) {
            $pdo->prepare("UPDATE extintores SET fecha_recarga = ? WHERE id = ?")->execute([$devuelto, $extId]);
        }

        recordarResponsable($pdo, $quien, $entrada);

        $pdo->commit();
        audit($uid, "Guardar mantenimiento #$id (extintor $extId)", 'extintor_mantenimientos', $id);
        echo json_encode(['success' => true, 'id' => $id]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500); echo json_encode(['error' => 'No se pudo guardar: ' . $e->getMessage()]);
    }
}

/** Marca la devolución al cliente sin abrir el formulario completo. */
function registrarDevolucion() {
    global $pdo, $uid;
    $d = entradaEnMayusculas(json_decode(file_get_contents('php://input'), true)) ?: [];
    $id       = intval($d['id'] ?? 0);
    $devuelto = fechaValida($d['fecha_devolucion'] ?? '') ?? date('Y-m-d');
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID requerido']); return; }

    $st = $pdo->prepare("
        SELECT m.extintor_id, m.fecha_entrada, mt.actualiza_recarga
        FROM extintor_mantenimientos m
        LEFT JOIN mantenimiento_tipos mt ON mt.id = m.tipo_id
        WHERE m.id = ?
    ");
    $st->execute([$id]);
    $mov = $st->fetch(PDO::FETCH_ASSOC);
    if (!$mov) { http_response_code(404); echo json_encode(['error' => 'Movimiento no encontrado']); return; }
    if ($devuelto < $mov['fecha_entrada']) {
        http_response_code(400);
        echo json_encode(['error' => 'La fecha de devolución no puede ser anterior a la de entrada']);
        return;
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE extintor_mantenimientos SET fecha_devolucion = ? WHERE id = ?")->execute([$devuelto, $id]);
        if (!empty($d['actualizar_recarga']) && (int) ($mov['actualiza_recarga'] ?? 0) === 1) {
            $pdo->prepare("UPDATE extintores SET fecha_recarga = ? WHERE id = ?")->execute([$devuelto, $mov['extintor_id']]);
        }
        $pdo->commit();
        audit($uid, "Registrar devolución del mantenimiento #$id", 'extintor_mantenimientos', $id);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500); echo json_encode(['error' => 'No se pudo registrar la devolución: ' . $e->getMessage()]);
    }
}

function eliminar() {
    global $pdo, $uid;
    $id = intval($_GET['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID requerido']); return; }
    $pdo->prepare("DELETE FROM extintor_mantenimientos WHERE id = ?")->execute([$id]);
    audit($uid, "Eliminar mantenimiento #$id", 'extintor_mantenimientos', $id);
    echo json_encode(['success' => true]);
}

// ─── Catálogo de motivos ─────────────────────────────────────────────────────
function listarTipos() {
    global $pdo;
    $incluirInactivos = !empty($_GET['todos']);
    $sql = "
        SELECT t.*, (SELECT COUNT(*) FROM extintor_mantenimientos m WHERE m.tipo_id = t.id) AS usos
        FROM mantenimiento_tipos t
    ";
    if (!$incluirInactivos) $sql .= " WHERE t.estado = 'activo'";
    $sql .= " ORDER BY t.orden, t.nombre";
    try {
        echo json_encode(['success' => true, 'data' => $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        echo json_encode(['success' => true, 'data' => []]);
    }
}

function guardarTipo() {
    global $pdo, $uid;
    $d = entradaEnMayusculas(json_decode(file_get_contents('php://input'), true)) ?: [];
    $id     = intval($d['id'] ?? 0);
    $nombre = trim($d['nombre'] ?? '');
    if ($nombre === '') { http_response_code(400); echo json_encode(['error' => 'Escribe el nombre del motivo']); return; }
    $nombre = mb_substr($nombre, 0, 80);

    $actualiza = !empty($d['actualiza_recarga']) ? 1 : 0;
    $estado    = (($d['estado'] ?? 'activo') === 'inactivo') ? 'inactivo' : 'activo';
    // El orden sólo cambia si lo mandan: editar el nombre de un motivo no debe
    // moverlo de sitio en la lista.
    $orden = array_key_exists('orden', $d) ? intval($d['orden']) : null;

    try {
        if ($id) {
            if ($orden === null) {
                $pdo->prepare("UPDATE mantenimiento_tipos SET nombre=?, actualiza_recarga=?, estado=? WHERE id=?")
                    ->execute([$nombre, $actualiza, $estado, $id]);
            } else {
                $pdo->prepare("UPDATE mantenimiento_tipos SET nombre=?, actualiza_recarga=?, orden=?, estado=? WHERE id=?")
                    ->execute([$nombre, $actualiza, $orden, $estado, $id]);
            }
        } else {
            if (!$orden) {
                $orden = 1 + (int) $pdo->query("SELECT COALESCE(MAX(orden),0) FROM mantenimiento_tipos")->fetchColumn();
            }
            $pdo->prepare("INSERT INTO mantenimiento_tipos (nombre, actualiza_recarga, orden, estado) VALUES (?,?,?,?)")
                ->execute([$nombre, $actualiza, $orden, $estado]);
            $id = $pdo->lastInsertId();
        }
        audit($uid, "Guardar motivo de mantenimiento «$nombre»", 'mantenimiento_tipos', $id);
        echo json_encode(['success' => true, 'id' => $id]);
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? null) == 1062) {
            http_response_code(409); echo json_encode(['error' => "Ya existe un motivo llamado «$nombre»"]); return;
        }
        http_response_code(500); echo json_encode(['error' => 'No se pudo guardar el motivo: ' . $e->getMessage()]);
    }
}

/**
 * Un motivo que ya se usó no se borra: se desactiva. Borrarlo dejaría los
 * movimientos históricos sin decir por qué entró el extintor.
 */
function eliminarTipo() {
    global $pdo, $uid;
    $id = intval($_GET['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID requerido']); return; }

    $st = $pdo->prepare("SELECT COUNT(*) FROM extintor_mantenimientos WHERE tipo_id = ?");
    $st->execute([$id]);
    $usos = (int) $st->fetchColumn();

    if ($usos > 0) {
        $pdo->prepare("UPDATE mantenimiento_tipos SET estado='inactivo' WHERE id = ?")->execute([$id]);
        audit($uid, "Desactivar motivo de mantenimiento #$id", 'mantenimiento_tipos', $id);
        echo json_encode(['success' => true, 'desactivado' => true,
            'mensaje' => "Ese motivo ya se usó en $usos movimiento(s), así que se desactivó en vez de borrarse: "
                       . "deja de ofrecerse al capturar, pero el historial sigue diciendo por qué entró cada extintor."]);
        return;
    }

    $pdo->prepare("DELETE FROM mantenimiento_tipos WHERE id = ?")->execute([$id]);
    audit($uid, "Eliminar motivo de mantenimiento #$id", 'mantenimiento_tipos', $id);
    echo json_encode(['success' => true, 'desactivado' => false]);
}

// ─── Quién realizó el servicio ───────────────────────────────────────────────
/**
 * Sugerencias según lo que se va escribiendo: sólo las personas que ya han
 * hecho mantenimientos. No se mezclan los proveedores del catálogo de compras
 * —son otra cosa: a quién le compramos, no quién hizo el trabajo—.
 *
 * Ordena por utilidad, no alfabéticamente: primero los que empiezan por lo
 * tecleado y, dentro de esos, los que más veces han trabajado.
 */
function sugerir() {
    global $pdo;
    $q = trim($_GET['q'] ?? '');
    $sugerencias = [];

    try {
        $sql = "SELECT nombre, veces FROM mantenimiento_responsables";
        $params = [];
        if ($q !== '') {
            $sql .= " WHERE nombre LIKE ?";
            $params[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        }
        $sql .= " ORDER BY veces DESC, nombre LIMIT 40";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $veces = (int) $r['veces'];
            $sugerencias[] = [
                'nombre'  => $r['nombre'],
                'veces'   => $veces,
                'detalle' => $veces === 1 ? '1 mantenimiento' : "$veces mantenimientos",
            ];
        }
    } catch (Exception $e) { /* sin tabla todavía, no hay a quién sugerir */ }

    if ($q !== '') {
        $qn = mb_strtolower($q);
        usort($sugerencias, function ($a, $b) use ($qn) {
            $ea = mb_strpos(mb_strtolower($a['nombre']), $qn) === 0 ? 0 : 1;
            $eb = mb_strpos(mb_strtolower($b['nombre']), $qn) === 0 ? 0 : 1;
            if ($ea !== $eb) return $ea - $eb;
            if ($a['veces'] !== $b['veces']) return $b['veces'] - $a['veces'];
            return strcmp($a['nombre'], $b['nombre']);
        });
    }

    echo json_encode(['success' => true, 'data' => array_slice($sugerencias, 0, 12)]);
}

function responsables() {
    global $pdo;
    $datos = [];
    try {
        $datos = $pdo->query("
            SELECT nombre, veces, ultima_vez FROM mantenimiento_responsables
            ORDER BY veces DESC, nombre
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $datos = []; }
    echo json_encode(['success' => true, 'data' => $datos]);
}

// ─── Resumen para las tarjetas ───────────────────────────────────────────────
function resumen() {
    global $pdo;
    $eid = intval($_GET['empresa_id'] ?? 0);
    $filtro = $eid ? ' AND e.empresa_id = ' . $eid : '';

    $uno = function (string $sql) use ($pdo) {
        try { return (int) $pdo->query($sql)->fetchColumn(); } catch (Exception $e) { return 0; }
    };
    $base = "FROM extintor_mantenimientos m JOIN extintores e ON e.id = m.extintor_id WHERE 1=1$filtro";

    $porMotivo = [];
    try {
        $st = $pdo->query("
            SELECT t.nombre, COUNT(m.id) AS n
            FROM mantenimiento_tipos t
            LEFT JOIN extintor_mantenimientos m ON m.tipo_id = t.id
            LEFT JOIN extintores e ON e.id = m.extintor_id
            WHERE t.estado = 'activo' " . ($eid ? " AND (e.empresa_id = $eid OR m.id IS NULL)" : "") . "
            GROUP BY t.id, t.nombre ORDER BY t.orden, t.nombre
        ");
        $porMotivo = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $porMotivo = []; }

    echo json_encode(['success' => true, 'data' => [
        'en_taller' => $uno("SELECT COUNT(*) $base AND m.fecha_devolucion IS NULL"),
        'total'     => $uno("SELECT COUNT(*) $base"),
        'mes'       => $uno("SELECT COUNT(*) $base AND m.fecha_entrada >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"),
        'por_motivo' => $porMotivo,
    ]]);
}

// ─── Auditoría ───────────────────────────────────────────────────────────────
function audit($uid, $accion, $tabla, $rid) {
    global $pdo;
    try {
        $st = $pdo->prepare("INSERT INTO auditoria (usuario_id,accion,tabla,registro_id,ip) VALUES (?,?,?,?,?)");
        $st->execute([$uid, $accion, $tabla, $rid, $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Exception $e) {}
}
