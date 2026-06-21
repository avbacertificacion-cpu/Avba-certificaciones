<?php
require_once '../config/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'crear':
        crearReporte();
        break;
    case 'listar':
        listarReportes();
        break;
    case 'obtener':
        obtenerReporte();
        break;
    case 'guardar-datos':
        guardarDatos();
        break;
    case 'aprobar':
        aprobarReporte();
        break;
    case 'descargar-pdf':
        descargarPDF();
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Acción no válida']);
}

function crearReporte() {
    global $pdo;

    if ($_SESSION['rol'] !== ROLE_INSPECTOR) {
        http_response_code(403);
        echo json_encode(['error' => 'Solo inspectores pueden crear reportes']);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    $plantilla_id = $data['plantilla_id'] ?? '';

    if (empty($plantilla_id)) {
        http_response_code(400);
        echo json_encode(['error' => 'Plantilla requerida']);
        return;
    }

    try {
        // Obtener información de la plantilla
        $stmt = $pdo->prepare('SELECT * FROM plantillas_inspeccion WHERE id = ?');
        $stmt->execute([$plantilla_id]);
        $plantilla = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$plantilla) {
            http_response_code(404);
            echo json_encode(['error' => 'Plantilla no encontrada']);
            return;
        }

        $stmt = $pdo->prepare('
            INSERT INTO reportes_inspeccion (plantilla_id, numero_reporte, empresa_id, inspeccionado_por, estado, fecha_inspeccion)
            VALUES (?, ?, ?, ?, "borrador", NOW())
        ');

        $stmt->execute([
            $plantilla_id,
            $plantilla['numero_reporte'],
            $plantilla['empresa_id'],
            $_SESSION['usuario_id']
        ]);

        $reporte_id = $pdo->lastInsertId();

        registrarAuditoria($_SESSION['usuario_id'], 'Crear reporte', 'reportes_inspeccion', $reporte_id);

        echo json_encode([
            'success' => true,
            'id' => $reporte_id,
            'mensaje' => 'Reporte creado. Comienza a llenar la información.'
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error al crear reporte']);
    }
}

function listarReportes() {
    global $pdo;

    try {
        if ($_SESSION['rol'] === ROLE_INSPECTOR) {
            $stmt = $pdo->prepare('
                SELECT r.*, p.nombre as plantilla_nombre, e.nombre as empresa_nombre
                FROM reportes_inspeccion r
                JOIN plantillas_inspeccion p ON r.plantilla_id = p.id
                JOIN empresas e ON r.empresa_id = e.id
                WHERE r.inspeccionado_por = ?
                ORDER BY r.fecha_creacion DESC
            ');
            $stmt->execute([$_SESSION['usuario_id']]);
        } elseif ($_SESSION['rol'] === ROLE_ADMIN) {
            $stmt = $pdo->query('
                SELECT r.*, p.nombre as plantilla_nombre, e.nombre as empresa_nombre, u.nombre as inspector_nombre
                FROM reportes_inspeccion r
                JOIN plantillas_inspeccion p ON r.plantilla_id = p.id
                JOIN empresas e ON r.empresa_id = e.id
                JOIN usuarios u ON r.inspeccionado_por = u.id
                ORDER BY r.fecha_creacion DESC
            ');
        } elseif ($_SESSION['rol'] === ROLE_CLIENTE) {
            $stmt = $pdo->prepare('
                SELECT r.*, p.nombre as plantilla_nombre, e.nombre as empresa_nombre
                FROM reportes_inspeccion r
                JOIN plantillas_inspeccion p ON r.plantilla_id = p.id
                JOIN empresas e ON r.empresa_id = e.id
                WHERE r.empresa_id = ? AND r.estado = "aprobado"
                ORDER BY r.fecha_creacion DESC
            ');
            $stmt->execute([$_SESSION['empresa_id']]);
        }

        $reportes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data' => $reportes
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error al listar reportes']);
    }
}

function obtenerReporte() {
    global $pdo;

    $id = $_GET['id'] ?? '';

    if (empty($id)) {
        http_response_code(400);
        echo json_encode(['error' => 'ID requerido']);
        return;
    }

    try {
        $stmt = $pdo->prepare('
            SELECT r.*, p.nombre as plantilla_nombre, e.nombre as empresa_nombre
            FROM reportes_inspeccion r
            JOIN plantillas_inspeccion p ON r.plantilla_id = p.id
            JOIN empresas e ON r.empresa_id = e.id
            WHERE r.id = ?
        ');
        $stmt->execute([$id]);
        $reporte = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$reporte) {
            http_response_code(404);
            echo json_encode(['error' => 'Reporte no encontrado']);
            return;
        }

        // Obtener áreas y datos
        $stmt = $pdo->prepare('SELECT * FROM areas_plantilla WHERE plantilla_id = ? ORDER BY orden');
        $stmt->execute([$reporte['plantilla_id']]);
        $areas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($areas as &$area) {
            $stmt = $pdo->prepare('SELECT * FROM campos_area WHERE area_id = ? ORDER BY orden');
            $stmt->execute([$area['id']]);
            $campos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($campos as &$campo) {
                $stmt = $pdo->prepare('
                    SELECT * FROM datos_inspeccion
                    WHERE reporte_id = ? AND area_id = ? AND campo_id = ?
                ');
                $stmt->execute([$id, $area['id'], $campo['id']]);
                $dato = $stmt->fetch(PDO::FETCH_ASSOC);
                $campo['valor'] = $dato['valor'] ?? '';
                $campo['observaciones'] = $dato['observaciones'] ?? '';
            }

            $area['campos'] = $campos;
        }

        $reporte['areas'] = $areas;

        echo json_encode([
            'success' => true,
            'data' => $reporte
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error al obtener reporte']);
    }
}

function guardarDatos() {
    global $pdo;

    $data = json_decode(file_get_contents('php://input'), true);
    $reporte_id = $data['reporte_id'] ?? '';
    $area_id = $data['area_id'] ?? '';
    $campo_id = $data['campo_id'] ?? '';
    $valor = $data['valor'] ?? '';
    $observaciones = $data['observaciones'] ?? '';

    if (empty($reporte_id) || empty($area_id) || empty($campo_id)) {
        http_response_code(400);
        echo json_encode(['error' => 'Datos incompletos']);
        return;
    }

    try {
        // Verificar si ya existe
        $stmt = $pdo->prepare('
            SELECT id FROM datos_inspeccion
            WHERE reporte_id = ? AND area_id = ? AND campo_id = ?
        ');
        $stmt->execute([$reporte_id, $area_id, $campo_id]);
        $existe = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existe) {
            $stmt = $pdo->prepare('
                UPDATE datos_inspeccion
                SET valor = ?, observaciones = ?
                WHERE reporte_id = ? AND area_id = ? AND campo_id = ?
            ');
        } else {
            $stmt = $pdo->prepare('
                INSERT INTO datos_inspeccion (reporte_id, area_id, campo_id, valor, observaciones)
                VALUES (?, ?, ?, ?, ?)
            ');
        }

        if ($existe) {
            $stmt->execute([$valor, $observaciones, $reporte_id, $area_id, $campo_id]);
        } else {
            $stmt->execute([$reporte_id, $area_id, $campo_id, $valor, $observaciones]);
        }

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error al guardar datos']);
    }
}

function aprobarReporte() {
    global $pdo;

    if ($_SESSION['rol'] !== ROLE_ADMIN) {
        http_response_code(403);
        echo json_encode(['error' => 'Solo administradores pueden aprobar']);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    $id = $data['id'] ?? '';
    $estado = $data['estado'] ?? 'aprobado';

    if (empty($id)) {
        http_response_code(400);
        echo json_encode(['error' => 'ID requerido']);
        return;
    }

    try {
        $stmt = $pdo->prepare('
            UPDATE reportes_inspeccion
            SET estado = ?, revisado_por = ?, fecha_aprobacion = NOW()
            WHERE id = ?
        ');
        $stmt->execute([$estado, $_SESSION['usuario_id'], $id]);

        registrarAuditoria($_SESSION['usuario_id'], 'Aprobar reporte', 'reportes_inspeccion', $id);

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error al aprobar reporte']);
    }
}

function descargarPDF() {
    // Implementar generación de PDF aquí
    echo json_encode(['error' => 'Funcionalidad en desarrollo']);
}

function registrarAuditoria($usuario_id, $accion, $tabla, $registro_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare('INSERT INTO auditoria (usuario_id, accion, tabla, registro_id) VALUES (?, ?, ?, ?)');
        $stmt->execute([$usuario_id, $accion, $tabla, $registro_id]);
    } catch (Exception $e) {
        // Silenciar errores
    }
}
