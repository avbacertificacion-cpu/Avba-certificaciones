<?php
/**
 * Mantenimientos de extintores (solo ADMIN).
 *
 * Registra cada vez que un extintor sale de la planta: a mantenimiento, a
 * recarga o a garantía. Guarda cuándo salió, cuándo volvió y quién lo atendió,
 * que puede ser un proveedor del catálogo o un nombre escrito a mano.
 *
 * Un movimiento sin fecha de retorno es un extintor que sigue fuera: de ahí
 * sale el estado, no se guarda aparte para que no puedan contradecirse.
 *
 * La tabla se crea sola la primera vez (no requiere migración manual).
 */
require_once '../config/config.php';
require_once '../config/mayusculas.php';
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401); echo json_encode(['error' => 'No autenticado']); exit;
}
$rol = $_SESSION['rol'];
$uid = $_SESSION['usuario_id'];
if ($rol !== ROLE_ADMIN) {
    http_response_code(403); echo json_encode(['error' => 'Sin permiso']); exit;
}

asegurarTablaMantenimientos($pdo);

switch ($_GET['action'] ?? '') {
    case 'listar':          listar();          break;
    case 'obtener':         obtener();         break;
    case 'extintor':        fichaExtintor();   break;
    case 'guardar':         guardar();         break;
    case 'registrar_retorno': registrarRetorno(); break;
    case 'eliminar':        eliminar();        break;
    case 'responsables':    responsables();    break;
    case 'resumen':         resumen();         break;
    default:
        http_response_code(400); echo json_encode(['error' => 'Acción no válida']);
}

// ─── Migración ligera ────────────────────────────────────────────────────────
function asegurarTablaMantenimientos($pdo) {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS extintor_mantenimientos (
                id            INT AUTO_INCREMENT PRIMARY KEY,
                extintor_id   INT NOT NULL,
                tipo          VARCHAR(20) NOT NULL,
                fecha_salida  DATE NOT NULL,
                fecha_retorno DATE DEFAULT NULL,
                proveedor_id  INT DEFAULT NULL,
                realizado_por VARCHAR(150) DEFAULT NULL,
                notas         TEXT DEFAULT NULL,
                creado_por    INT DEFAULT NULL,
                created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
                KEY idx_mant_extintor (extintor_id),
                KEY idx_mant_salida (fecha_salida)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) { /* las acciones reportarán el error */ }
}

/** Los tres motivos por los que un extintor sale de la planta. */
function tiposValidos(): array {
    return ['mantenimiento', 'recarga', 'garantia'];
}

function fechaValida($v): ?string {
    $v = trim((string) $v);
    if ($v === '') return null;
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : null;
}

/** SELECT común: el movimiento con los datos del extintor y su planta. */
function sqlMovimiento(): string {
    return "
        SELECT m.*,
               e.codigo_manual, e.seccion, e.ubicacion, e.capacidad,
               e.fecha_recarga, e.fecha_ph,
               te.nombre  AS tipo_extintor,
               emp.id     AS empresa_id,
               emp.nombre AS empresa_nombre,
               p.nombre   AS proveedor_nombre,
               (m.fecha_retorno IS NULL) AS sigue_fuera
        FROM extintor_mantenimientos m
        JOIN extintores e   ON e.id = m.extintor_id
        JOIN empresas emp   ON emp.id = e.empresa_id
        LEFT JOIN tipos_extintores te ON te.id = e.tipo
        LEFT JOIN proveedores p ON p.id = m.proveedor_id
    ";
}

// ─── Listado ─────────────────────────────────────────────────────────────────
function listar() {
    global $pdo;
    $where = ['1=1'];
    $params = [];

    if ($eid = intval($_GET['empresa_id'] ?? 0)) { $where[] = 'e.empresa_id = ?'; $params[] = $eid; }
    if ($xid = intval($_GET['extintor_id'] ?? 0)) { $where[] = 'm.extintor_id = ?'; $params[] = $xid; }
    $tipo = trim($_GET['tipo'] ?? '');
    if (in_array($tipo, tiposValidos(), true)) { $where[] = 'm.tipo = ?'; $params[] = $tipo; }

    // "fuera" = todavía no regresa; "devuelto" = ya regresó
    $estado = trim($_GET['estado'] ?? '');
    if ($estado === 'fuera')    $where[] = 'm.fecha_retorno IS NULL';
    if ($estado === 'devuelto') $where[] = 'm.fecha_retorno IS NOT NULL';

    $sql = sqlMovimiento() . ' WHERE ' . implode(' AND ', $where)
         . ' ORDER BY m.fecha_salida DESC, m.id DESC LIMIT 500';
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

// ─── Ficha del extintor + su historial ───────────────────────────────────────
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

    $st = $pdo->prepare(sqlMovimiento() . ' WHERE m.extintor_id = ? ORDER BY m.fecha_salida DESC, m.id DESC');
    $st->execute([$id]);
    $ext['historial'] = $st->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $ext]);
}

// ─── Alta y edición ──────────────────────────────────────────────────────────
function guardar() {
    global $pdo, $uid;
    $d = entradaEnMayusculas(json_decode(file_get_contents('php://input'), true)) ?: [];

    $id      = intval($d['id'] ?? 0);
    $extId   = intval($d['extintor_id'] ?? 0);
    $tipo    = strtolower(trim($d['tipo'] ?? ''));
    $salida  = fechaValida($d['fecha_salida'] ?? '');
    $retorno = fechaValida($d['fecha_retorno'] ?? '');

    if (!$extId)  { http_response_code(400); echo json_encode(['error' => 'Elige el extintor']); return; }
    if (!in_array($tipo, tiposValidos(), true)) {
        http_response_code(400); echo json_encode(['error' => 'Elige el motivo: mantenimiento, recarga o garantía']); return;
    }
    if (!$salida) { http_response_code(400); echo json_encode(['error' => 'Indica la fecha en que salió']); return; }
    if ($retorno && $retorno < $salida) {
        http_response_code(400); echo json_encode(['error' => 'La fecha de retorno no puede ser anterior a la de salida']); return;
    }

    $st = $pdo->prepare("SELECT id FROM extintores WHERE id = ?");
    $st->execute([$extId]);
    if (!$st->fetchColumn()) { http_response_code(400); echo json_encode(['error' => 'El extintor no existe']); return; }

    // Quién lo atendió: un proveedor del catálogo o un nombre escrito a mano
    $provId = intval($d['proveedor_id'] ?? 0) ?: null;
    $quien  = trim($d['realizado_por'] ?? '');
    if ($provId) {
        $st = $pdo->prepare("SELECT nombre FROM proveedores WHERE id = ?");
        $st->execute([$provId]);
        $nombreProv = $st->fetchColumn();
        if (!$nombreProv) { $provId = null; } else { $quien = $nombreProv; }
    }
    if ($quien === '') {
        http_response_code(400); echo json_encode(['error' => 'Indica quién realizó el servicio']); return;
    }

    $notas = trim($d['notas'] ?? '') ?: null;
    $campos = [$extId, $tipo, $salida, $retorno, $provId, mb_substr($quien, 0, 150), $notas];

    try {
        $pdo->beginTransaction();

        if ($id) {
            $pdo->prepare("
                UPDATE extintor_mantenimientos
                SET extintor_id=?, tipo=?, fecha_salida=?, fecha_retorno=?, proveedor_id=?, realizado_por=?, notas=?
                WHERE id=?
            ")->execute(array_merge($campos, [$id]));
        } else {
            $pdo->prepare("
                INSERT INTO extintor_mantenimientos
                    (extintor_id, tipo, fecha_salida, fecha_retorno, proveedor_id, realizado_por, notas, creado_por)
                VALUES (?,?,?,?,?,?,?,?)
            ")->execute(array_merge($campos, [$uid]));
            $id = $pdo->lastInsertId();
        }

        // Una recarga que ya regresó puede actualizar la fecha de recarga del
        // extintor. Va marcado desde la pantalla: si no, el dato del extintor y
        // el del movimiento acaban diciendo cosas distintas.
        if (!empty($d['actualizar_recarga']) && $tipo === 'recarga' && $retorno) {
            $pdo->prepare("UPDATE extintores SET fecha_recarga = ? WHERE id = ?")->execute([$retorno, $extId]);
        }

        $pdo->commit();
        audit($uid, "Guardar mantenimiento #$id (extintor $extId, $tipo)", 'extintor_mantenimientos', $id);
        echo json_encode(['success' => true, 'id' => $id]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500); echo json_encode(['error' => 'No se pudo guardar: ' . $e->getMessage()]);
    }
}

/** Marca el regreso de un extintor sin tener que abrir el formulario completo. */
function registrarRetorno() {
    global $pdo, $uid;
    $d = entradaEnMayusculas(json_decode(file_get_contents('php://input'), true)) ?: [];
    $id      = intval($d['id'] ?? 0);
    $retorno = fechaValida($d['fecha_retorno'] ?? '') ?? date('Y-m-d');
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID requerido']); return; }

    $st = $pdo->prepare("SELECT extintor_id, tipo, fecha_salida FROM extintor_mantenimientos WHERE id = ?");
    $st->execute([$id]);
    $mov = $st->fetch(PDO::FETCH_ASSOC);
    if (!$mov) { http_response_code(404); echo json_encode(['error' => 'Movimiento no encontrado']); return; }
    if ($retorno < $mov['fecha_salida']) {
        http_response_code(400); echo json_encode(['error' => 'La fecha de retorno no puede ser anterior a la de salida']); return;
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE extintor_mantenimientos SET fecha_retorno = ? WHERE id = ?")->execute([$retorno, $id]);
        if (!empty($d['actualizar_recarga']) && $mov['tipo'] === 'recarga') {
            $pdo->prepare("UPDATE extintores SET fecha_recarga = ? WHERE id = ?")->execute([$retorno, $mov['extintor_id']]);
        }
        $pdo->commit();
        audit($uid, "Registrar retorno del mantenimiento #$id", 'extintor_mantenimientos', $id);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500); echo json_encode(['error' => 'No se pudo registrar el retorno: ' . $e->getMessage()]);
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

/**
 * Para el desplegable de "quién lo realizó": los proveedores del catálogo y,
 * además, los nombres que ya se escribieron antes a mano, para reutilizarlos.
 */
function responsables() {
    global $pdo;
    $proveedores = [];
    try {
        $proveedores = $pdo->query("SELECT id, nombre FROM proveedores WHERE estado='activo' ORDER BY nombre")
                           ->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $proveedores = []; }

    $sueltos = [];
    try {
        $sueltos = $pdo->query("
            SELECT DISTINCT realizado_por FROM extintor_mantenimientos
            WHERE proveedor_id IS NULL AND realizado_por IS NOT NULL AND realizado_por <> ''
            ORDER BY realizado_por
        ")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) { $sueltos = []; }

    echo json_encode(['success' => true, 'data' => ['proveedores' => $proveedores, 'otros' => $sueltos]]);
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

    $data = [
        'fuera'         => $uno("SELECT COUNT(*) $base AND m.fecha_retorno IS NULL"),
        'total'         => $uno("SELECT COUNT(*) $base"),
        'mantenimiento' => $uno("SELECT COUNT(*) $base AND m.tipo = 'mantenimiento'"),
        'recarga'       => $uno("SELECT COUNT(*) $base AND m.tipo = 'recarga'"),
        'garantia'      => $uno("SELECT COUNT(*) $base AND m.tipo = 'garantia'"),
        'mes'           => $uno("SELECT COUNT(*) $base AND m.fecha_salida >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"),
    ];
    echo json_encode(['success' => true, 'data' => $data]);
}

// ─── Auditoría ───────────────────────────────────────────────────────────────
function audit($uid, $accion, $tabla, $rid) {
    global $pdo;
    try {
        $st = $pdo->prepare("INSERT INTO auditoria (usuario_id,accion,tabla,registro_id,ip) VALUES (?,?,?,?,?)");
        $st->execute([$uid, $accion, $tabla, $rid, $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Exception $e) {}
}
