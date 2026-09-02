<?php
/**
 * Módulo de Cotizaciones (solo ADMIN)
 *  - Proveedores
 *  - Catálogo de precios por proveedor
 *  - Cotizaciones con partidas: costo del proveedor vs. precio de venta y utilidad
 *
 * Las tablas se crean solas la primera vez (no requiere migración manual).
 */
require_once '../config/config.php';
require_once '../config/documentos-lib.php';
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401); echo json_encode(['error' => 'No autenticado']); exit;
}

$rol = $_SESSION['rol'];
$uid = $_SESSION['usuario_id'];

if ($rol !== ROLE_ADMIN) {
    http_response_code(403); echo json_encode(['error' => 'Sin permiso']); exit;
}

$action = $_GET['action'] ?? '';

asegurarTablasCotizaciones($pdo);

switch ($action) {
    // Proveedores
    case 'listar_proveedores':  listarProveedores();   break;
    case 'guardar_proveedor':   guardarProveedor();    break;
    case 'eliminar_proveedor':  eliminarProveedor();   break;
    // Catálogo de precios
    case 'listar_catalogo':     listarCatalogo();      break;
    case 'guardar_precio':      guardarPrecio();       break;
    case 'eliminar_precio':     eliminarPrecio();      break;
    // Cotizaciones
    case 'listar_cotizaciones': listarCotizaciones();  break;
    case 'obtener_cotizacion':  obtenerCotizacion();   break;
    case 'guardar_cotizacion':  guardarCotizacion();   break;
    case 'eliminar_cotizacion': eliminarCotizacion();  break;
    case 'cambiar_estado':      cambiarEstado();       break;
    case 'resumen':             resumen();             break;
    default:
        http_response_code(400); echo json_encode(['error' => 'Acción no válida']);
}

// ─── Migración ligera ────────────────────────────────────────────────────────
function asegurarTablasCotizaciones($pdo) {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS proveedores (
                id        INT AUTO_INCREMENT PRIMARY KEY,
                nombre    VARCHAR(150) NOT NULL,
                contacto  VARCHAR(150) DEFAULT NULL,
                telefono  VARCHAR(40)  DEFAULT NULL,
                email     VARCHAR(150) DEFAULT NULL,
                notas     TEXT         DEFAULT NULL,
                estado    VARCHAR(20)  NOT NULL DEFAULT 'activo',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS catalogo_precios (
                id           INT AUTO_INCREMENT PRIMARY KEY,
                proveedor_id INT DEFAULT NULL,
                descripcion  VARCHAR(255) NOT NULL,
                unidad       VARCHAR(40)  DEFAULT NULL,
                costo        DECIMAL(12,2) NOT NULL DEFAULT 0,
                estado       VARCHAR(20)  NOT NULL DEFAULT 'activo',
                actualizado  DATETIME DEFAULT CURRENT_TIMESTAMP,
                KEY idx_cat_prov (proveedor_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS cotizaciones (
                id             INT AUTO_INCREMENT PRIMARY KEY,
                folio          VARCHAR(30) NOT NULL,
                empresa_id     INT DEFAULT NULL,
                cliente_nombre VARCHAR(200) NOT NULL,
                contacto       VARCHAR(150) DEFAULT NULL,
                fecha          DATE NOT NULL,
                vigencia_dias  INT NOT NULL DEFAULT 15,
                estado         VARCHAR(20) NOT NULL DEFAULT 'pendiente',
                notas          TEXT DEFAULT NULL,
                creado_por     INT DEFAULT NULL,
                created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_folio (folio),
                KEY idx_cot_empresa (empresa_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS cotizacion_items (
                id              INT AUTO_INCREMENT PRIMARY KEY,
                cotizacion_id   INT NOT NULL,
                descripcion     VARCHAR(255) NOT NULL,
                cantidad        DECIMAL(12,2) NOT NULL DEFAULT 1,
                unidad          VARCHAR(40) DEFAULT NULL,
                proveedor_id    INT DEFAULT NULL,
                costo_unitario  DECIMAL(12,2) NOT NULL DEFAULT 0,
                precio_unitario DECIMAL(12,2) NOT NULL DEFAULT 0,
                orden           INT NOT NULL DEFAULT 0,
                KEY idx_item_cot (cotizacion_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) { /* las acciones reportarán el error */ }

    // Porcentaje de utilidad con el que se calcula el precio de venta.
    // Va aparte para que las bases de datos ya creadas también lo reciban.
    agregarColumna($pdo, 'cotizaciones', 'utilidad_pct',  "DECIMAL(8,2) NOT NULL DEFAULT 0");
    agregarColumna($pdo, 'cotizaciones', 'utilidad_base', "VARCHAR(10) NOT NULL DEFAULT 'costo'");
    // Producto del catálogo del que salió la partida, para reabrirla ya elegido
    agregarColumna($pdo, 'cotizacion_items', 'catalogo_id', "INT DEFAULT NULL");

    // Los estados pasaron a ser pendiente → aceptada → pagada. Las cotizaciones
    // que venían en 'borrador' o 'enviada' quedan como pendientes.
    try {
        $pdo->exec("UPDATE cotizaciones SET estado='pendiente' WHERE estado IN ('borrador','enviada')");
        // El porcentaje pasó a ser siempre sobre el costo. Los precios guardados
        // no cambian; sólo la base con la que se vuelve a leer el porcentaje.
        $pdo->exec("UPDATE cotizaciones SET utilidad_base='costo' WHERE utilidad_base <> 'costo'");
    } catch (Exception $e) { /* si falla, las cotizaciones viejas conservan su estado */ }
}

/** Estados válidos de una cotización, en el orden en que avanzan. */
function estadosValidos(): array {
    return ['pendiente', 'aceptada', 'pagada', 'rechazada'];
}

/** Agrega una columna solo si falta. */
function agregarColumna($pdo, string $tabla, string $columna, string $definicion): void {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$tabla` LIKE ?");
        $stmt->execute([$columna]);
        if ($stmt->fetch()) return;
        $pdo->exec("ALTER TABLE `$tabla` ADD COLUMN `$columna` $definicion");
    } catch (Exception $e) { /* si otra petición la creó primero, no pasa nada */ }
}

// ═══════════════════════ PROVEEDORES ═══════════════════════
function listarProveedores() {
    global $pdo;
    $stmt = $pdo->query("
        SELECT p.*,
               (SELECT COUNT(*) FROM catalogo_precios c
                 WHERE c.proveedor_id = p.id AND c.estado='activo') AS productos
        FROM proveedores p
        WHERE p.estado='activo'
        ORDER BY p.nombre
    ");
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function guardarProveedor() {
    global $pdo, $uid;
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $nombre = trim($d['nombre'] ?? '');
    if ($nombre === '') { http_response_code(400); echo json_encode(['error' => 'El nombre del proveedor es obligatorio']); return; }

    $id = intval($d['id'] ?? 0);
    $params = [
        $nombre,
        trim($d['contacto'] ?? '') ?: null,
        trim($d['telefono'] ?? '') ?: null,
        trim($d['email'] ?? '') ?: null,
        trim($d['notas'] ?? '') ?: null,
    ];
    try {
        if ($id) {
            $params[] = $id;
            $pdo->prepare("UPDATE proveedores SET nombre=?, contacto=?, telefono=?, email=?, notas=? WHERE id=?")->execute($params);
        } else {
            $pdo->prepare("INSERT INTO proveedores (nombre,contacto,telefono,email,notas) VALUES (?,?,?,?,?)")->execute($params);
            $id = $pdo->lastInsertId();
        }
        audit($uid, "Guardar proveedor $nombre", 'proveedores', $id);
        echo json_encode(['success' => true, 'id' => $id]);
    } catch (Exception $e) {
        http_response_code(500); echo json_encode(['error' => 'Error al guardar proveedor: ' . $e->getMessage()]);
    }
}

function eliminarProveedor() {
    global $pdo, $uid;
    $id = intval($_GET['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID requerido']); return; }
    // Baja lógica: las cotizaciones históricas siguen apuntando a este proveedor
    $pdo->prepare("UPDATE proveedores SET estado='inactivo' WHERE id=?")->execute([$id]);
    audit($uid, "Eliminar proveedor", 'proveedores', $id);
    echo json_encode(['success' => true]);
}

// ═══════════════════════ CATÁLOGO DE PRECIOS ═══════════════════════
function listarCatalogo() {
    global $pdo;
    $prov = intval($_GET['proveedor_id'] ?? 0);
    $where  = "c.estado='activo'";
    $params = [];
    if ($prov) { $where .= " AND c.proveedor_id = ?"; $params[] = $prov; }

    $stmt = $pdo->prepare("
        SELECT c.*, p.nombre AS proveedor_nombre
        FROM catalogo_precios c
        LEFT JOIN proveedores p ON p.id = c.proveedor_id
        WHERE $where
        ORDER BY p.nombre, c.descripcion
    ");
    $stmt->execute($params);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function guardarPrecio() {
    global $pdo, $uid;
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $desc = trim($d['descripcion'] ?? '');
    if ($desc === '') { http_response_code(400); echo json_encode(['error' => 'La descripción es obligatoria']); return; }

    $id = intval($d['id'] ?? 0);
    $params = [
        intval($d['proveedor_id'] ?? 0) ?: null,
        $desc,
        trim($d['unidad'] ?? '') ?: null,
        round((float)($d['costo'] ?? 0), 2),
    ];
    try {
        if ($id) {
            $params[] = $id;
            $pdo->prepare("UPDATE catalogo_precios SET proveedor_id=?, descripcion=?, unidad=?, costo=?, actualizado=NOW() WHERE id=?")->execute($params);
        } else {
            $pdo->prepare("INSERT INTO catalogo_precios (proveedor_id,descripcion,unidad,costo) VALUES (?,?,?,?)")->execute($params);
            $id = $pdo->lastInsertId();
        }
        audit($uid, "Guardar precio $desc", 'catalogo_precios', $id);
        echo json_encode(['success' => true, 'id' => $id]);
    } catch (Exception $e) {
        http_response_code(500); echo json_encode(['error' => 'Error al guardar el precio: ' . $e->getMessage()]);
    }
}

function eliminarPrecio() {
    global $pdo, $uid;
    $id = intval($_GET['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID requerido']); return; }
    $pdo->prepare("UPDATE catalogo_precios SET estado='inactivo' WHERE id=?")->execute([$id]);
    audit($uid, "Eliminar precio", 'catalogo_precios', $id);
    echo json_encode(['success' => true]);
}

// ═══════════════════════ COTIZACIONES ═══════════════════════
// Los totales se calculan siempre a partir de las partidas (nunca se guardan
// duplicados, así no pueden quedar desfasados).
function sqlTotales() {
    return "
        (SELECT COALESCE(SUM(i.cantidad * i.costo_unitario),0)  FROM cotizacion_items i WHERE i.cotizacion_id = c.id) AS total_costo,
        (SELECT COALESCE(SUM(i.cantidad * i.precio_unitario),0) FROM cotizacion_items i WHERE i.cotizacion_id = c.id) AS total_venta,
        (SELECT COUNT(*) FROM cotizacion_items i WHERE i.cotizacion_id = c.id) AS num_partidas
    ";
}

function listarCotizaciones() {
    global $pdo;
    $estado = trim($_GET['estado'] ?? '');
    $where  = "1=1";
    $params = [];
    if ($estado !== '') { $where .= " AND c.estado = ?"; $params[] = $estado; }

    $stmt = $pdo->prepare("
        SELECT c.*, " . sqlTotales() . "
        FROM cotizaciones c
        WHERE $where
        ORDER BY c.fecha DESC, c.id DESC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) $r = conUtilidad($r);
    unset($r);
    echo json_encode(['success' => true, 'data' => $rows]);
}

/** Agrega utilidad en pesos y los dos porcentajes de uso común. */
function conUtilidad(array $r): array {
    $costo = (float) $r['total_costo'];
    $venta = (float) $r['total_venta'];
    $util  = $venta - $costo;
    $r['total_costo'] = round($costo, 2);
    $r['total_venta'] = round($venta, 2);
    $r['utilidad']    = round($util, 2);
    // El porcentaje de ganancia del sistema es siempre sobre el costo:
    // lo que cuesta 10 con 50% se vende en 15.
    $r['markup_pct']  = $costo > 0 ? round($util / $costo * 100, 1) : 0;
    // Se conserva el margen sobre venta por si hace falta en algún cálculo,
    // pero no se muestra: dos porcentajes distintos para lo mismo confundían.
    $r['margen_pct']  = $venta > 0 ? round($util / $venta * 100, 1) : 0;
    return $r;
}

function obtenerCotizacion() {
    global $pdo;
    $id = intval($_GET['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID requerido']); return; }

    $stmt = $pdo->prepare("SELECT c.*, " . sqlTotales() . " FROM cotizaciones c WHERE c.id = ?");
    $stmt->execute([$id]);
    $cot = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cot) { http_response_code(404); echo json_encode(['error' => 'Cotización no encontrada']); return; }
    $cot = conUtilidad($cot);

    $stmt = $pdo->prepare("
        SELECT i.*, p.nombre AS proveedor_nombre
        FROM cotizacion_items i
        LEFT JOIN proveedores p ON p.id = i.proveedor_id
        WHERE i.cotizacion_id = ?
        ORDER BY i.orden, i.id
    ");
    $stmt->execute([$id]);
    $cot['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $cot]);
}

function siguienteFolio($pdo): string {
    $anio = date('Y');
    $stmt = $pdo->prepare("SELECT folio FROM cotizaciones WHERE folio LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute(["COT-$anio-%"]);
    $ultimo = $stmt->fetchColumn();
    $n = 1;
    if ($ultimo && preg_match('/(\d+)$/', $ultimo, $m)) $n = intval($m[1]) + 1;
    return "COT-$anio-" . str_pad($n, 3, '0', STR_PAD_LEFT);
}

function guardarCotizacion() {
    global $pdo, $uid;
    $d = json_decode(file_get_contents('php://input'), true) ?: [];

    $id    = intval($d['id'] ?? 0);
    $items = $d['items'] ?? [];
    if (!is_array($items)) $items = [];

    $empresa_id = intval($d['empresa_id'] ?? 0) ?: null;
    $cliente    = trim($d['cliente_nombre'] ?? '');
    if ($cliente === '') { http_response_code(400); echo json_encode(['error' => 'Indica quién solicita la cotización (cliente)']); return; }

    $fecha = trim($d['fecha'] ?? '');
    $dt = DateTime::createFromFormat('Y-m-d', $fecha);
    if (!$dt || $dt->format('Y-m-d') !== $fecha) $fecha = date('Y-m-d');

    $estado = in_array($d['estado'] ?? '', estadosValidos(), true) ? $d['estado'] : 'pendiente';

    // Porcentaje con el que se calculó el precio de venta: al reabrir la
    // cotización debe seguir vigente el mismo criterio.
    $util_pct  = max(0, min(100000, round((float)($d['utilidad_pct'] ?? 0), 2)));
    $util_base = 'costo'; // el porcentaje del sistema siempre es sobre el costo

    try {
        $pdo->beginTransaction();

        if ($id) {
            $pdo->prepare("
                UPDATE cotizaciones
                SET empresa_id=?, cliente_nombre=?, contacto=?, fecha=?, vigencia_dias=?, estado=?, notas=?,
                    utilidad_pct=?, utilidad_base=?
                WHERE id=?
            ")->execute([
                $empresa_id, $cliente,
                trim($d['contacto'] ?? '') ?: null,
                $fecha,
                max(0, intval($d['vigencia_dias'] ?? 15)),
                $estado,
                trim($d['notas'] ?? '') ?: null,
                $util_pct, $util_base,
                $id,
            ]);
        } else {
            $folio = siguienteFolio($pdo);
            $pdo->prepare("
                INSERT INTO cotizaciones
                    (folio, empresa_id, cliente_nombre, contacto, fecha, vigencia_dias, estado, notas, utilidad_pct, utilidad_base, creado_por)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)
            ")->execute([
                $folio, $empresa_id, $cliente,
                trim($d['contacto'] ?? '') ?: null,
                $fecha,
                max(0, intval($d['vigencia_dias'] ?? 15)),
                $estado,
                trim($d['notas'] ?? '') ?: null,
                $util_pct, $util_base,
                $uid,
            ]);
            $id = $pdo->lastInsertId();
        }

        // Las partidas se reescriben completas en cada guardado
        $pdo->prepare("DELETE FROM cotizacion_items WHERE cotizacion_id = ?")->execute([$id]);
        $ins = $pdo->prepare("
            INSERT INTO cotizacion_items
                (cotizacion_id, descripcion, cantidad, unidad, proveedor_id, catalogo_id, costo_unitario, precio_unitario, orden)
            VALUES (?,?,?,?,?,?,?,?,?)
        ");
        $orden = 0;
        foreach ($items as $it) {
            $desc = trim($it['descripcion'] ?? '');
            if ($desc === '') continue;
            $ins->execute([
                $id,
                $desc,
                max(0, round((float)($it['cantidad'] ?? 1), 2)),
                trim($it['unidad'] ?? '') ?: null,
                intval($it['proveedor_id'] ?? 0) ?: null,
                intval($it['catalogo_id'] ?? 0) ?: null,
                max(0, round((float)($it['costo_unitario'] ?? 0), 2)),
                max(0, round((float)($it['precio_unitario'] ?? 0), 2)),
                $orden++,
            ]);
        }

        $pdo->commit();
        audit($uid, "Guardar cotización #$id ($cliente)", 'cotizaciones', $id);
        echo json_encode(['success' => true, 'id' => $id]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Error al guardar la cotización: ' . $e->getMessage()]);
    }
}

function eliminarCotizacion() {
    global $pdo, $uid;
    $id = intval($_GET['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID requerido']); return; }
    try {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM cotizacion_items WHERE cotizacion_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM cotizaciones WHERE id = ?")->execute([$id]);
        $pdo->commit();
        borrarDocumentosDe($pdo, 'cotizacion', $id);
        audit($uid, "Eliminar cotización", 'cotizaciones', $id);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500); echo json_encode(['error' => 'No se pudo eliminar: ' . $e->getMessage()]);
    }
}

function cambiarEstado() {
    global $pdo, $uid;
    $d  = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = intval($d['id'] ?? 0);
    $estado = $d['estado'] ?? '';
    if (!$id || !in_array($estado, estadosValidos(), true)) {
        http_response_code(400); echo json_encode(['error' => 'Datos inválidos']); return;
    }
    $pdo->prepare("UPDATE cotizaciones SET estado=? WHERE id=?")->execute([$estado, $id]);
    audit($uid, "Cotización a estado $estado", 'cotizaciones', $id);
    echo json_encode(['success' => true]);
}

// ─── Resumen para las tarjetas del módulo ────────────────────────────────────
function resumen() {
    global $pdo;
    $stmt = $pdo->query("
        SELECT c.estado, " . sqlTotales() . "
        FROM cotizaciones c
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $r = ['total' => 0, 'aceptadas' => 0, 'venta_aceptada' => 0, 'utilidad_aceptada' => 0,
          'venta_pendiente' => 0, 'venta_pagada' => 0, 'ganancia_promedio' => 0];
    $ganancias = [];
    foreach ($rows as $row) {
        $row = conUtilidad($row);
        $r['total']++;
        // Aceptada y pagada son negocio ganado; la pagada además ya se cobró.
        if (in_array($row['estado'], ['aceptada','pagada'], true)) {
            $r['aceptadas']++;
            $r['venta_aceptada']    += $row['total_venta'];
            $r['utilidad_aceptada'] += $row['utilidad'];
            if ($row['estado'] === 'pagada') $r['venta_pagada'] += $row['total_venta'];
        } elseif ($row['estado'] === 'pendiente') {
            $r['venta_pendiente'] += $row['total_venta'];
        }
        if ($row['total_costo'] > 0) $ganancias[] = $row['markup_pct'];
    }
    $r['venta_aceptada']    = round($r['venta_aceptada'], 2);
    $r['utilidad_aceptada'] = round($r['utilidad_aceptada'], 2);
    $r['venta_pendiente']   = round($r['venta_pendiente'], 2);
    $r['venta_pagada']      = round($r['venta_pagada'], 2);
    $r['ganancia_promedio'] = $ganancias ? round(array_sum($ganancias) / count($ganancias), 1) : 0;

    echo json_encode(['success' => true, 'data' => $r]);
}

// ─── Auditoría ───────────────────────────────────────────────────────────────
function audit($uid, $accion, $tabla, $rid) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("INSERT INTO auditoria (usuario_id,accion,tabla,registro_id,ip) VALUES (?,?,?,?,?)");
        $stmt->execute([$uid, $accion, $tabla, $rid, $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Exception $e) {}
}
