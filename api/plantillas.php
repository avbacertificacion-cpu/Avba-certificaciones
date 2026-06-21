<?php
require_once '../config/config.php';

header('Content-Type: application/json');

// Verificar que es administrador
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== ROLE_ADMIN) {
    http_response_code(403);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'crear':
        crearPlantilla();
        break;
    case 'listar':
        listarPlantillas();
        break;
    case 'obtener':
        obtenerPlantilla();
        break;
    case 'editar':
        editarPlantilla();
        break;
    case 'eliminar':
        eliminarPlantilla();
        break;
    case 'agregar-area':
        agregarArea();
        break;
    case 'agregar-campo':
        agregarCampo();
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Acción no válida']);
}

function crearPlantilla() {
    global $pdo;

    $data = json_decode(file_get_contents('php://input'), true);
    $nombre = $data['nombre'] ?? '';
    $descripcion = $data['descripcion'] ?? '';
    $empresa_id = $data['empresa_id'] ?? null;

    if (empty($nombre)) {
        http_response_code(400);
        echo json_encode(['error' => 'El nombre es requerido']);
        return;
    }

    try {
        // Generar número de reporte
        $stmt = $pdo->query('SELECT COUNT(*) FROM plantillas_inspeccion');
        $count = $stmt->fetchColumn();
        $numero_reporte = 'REV-' . str_pad($count + 1, 3, '0', STR_PAD_LEFT);

        $stmt = $pdo->prepare('
            INSERT INTO plantillas_inspeccion (nombre, descripcion, numero_reporte, empresa_id, creado_por)
            VALUES (?, ?, ?, ?, ?)
        ');

        $stmt->execute([
            $nombre,
            $descripcion,
            $numero_reporte,
            $empresa_id,
            $_SESSION['usuario_id']
        ]);

        $plantilla_id = $pdo->lastInsertId();

        registrarAuditoria(
            $_SESSION['usuario_id'],
            'Crear plantilla: ' . $nombre,
            'plantillas_inspeccion',
            $plantilla_id
        );

        echo json_encode([
            'success' => true,
            'id' => $plantilla_id,
            'numero_reporte' => $numero_reporte
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error al crear plantilla']);
    }
}

function listarPlantillas() {
    global $pdo;

    try {
        $stmt = $pdo->query('
            SELECT * FROM plantillas_inspeccion
            WHERE estado = "activo"
            ORDER BY fecha_creacion DESC
        ');

        $plantillas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data' => $plantillas
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error al listar plantillas']);
    }
}

function obtenerPlantilla() {
    global $pdo;

    $id = $_GET['id'] ?? '';

    if (empty($id)) {
        http_response_code(400);
        echo json_encode(['error' => 'ID requerido']);
        return;
    }

    try {
        // Obtener plantilla
        $stmt = $pdo->prepare('SELECT * FROM plantillas_inspeccion WHERE id = ?');
        $stmt->execute([$id]);
        $plantilla = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$plantilla) {
            http_response_code(404);
            echo json_encode(['error' => 'Plantilla no encontrada']);
            return;
        }

        // Obtener áreas
        $stmt = $pdo->prepare('SELECT * FROM areas_plantilla WHERE plantilla_id = ? ORDER BY orden');
        $stmt->execute([$id]);
        $areas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Obtener campos para cada área
        foreach ($areas as &$area) {
            $stmt = $pdo->prepare('SELECT * FROM campos_area WHERE area_id = ? ORDER BY orden');
            $stmt->execute([$area['id']]);
            $area['campos'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $plantilla['areas'] = $areas;

        echo json_encode([
            'success' => true,
            'data' => $plantilla
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error al obtener plantilla']);
    }
}

function editarPlantilla() {
    global $pdo;

    $data = json_decode(file_get_contents('php://input'), true);
    $id = $data['id'] ?? '';
    $nombre = $data['nombre'] ?? '';
    $descripcion = $data['descripcion'] ?? '';

    if (empty($id) || empty($nombre)) {
        http_response_code(400);
        echo json_encode(['error' => 'Datos incompletos']);
        return;
    }

    try {
        $stmt = $pdo->prepare('UPDATE plantillas_inspeccion SET nombre = ?, descripcion = ? WHERE id = ?');
        $stmt->execute([$nombre, $descripcion, $id]);

        registrarAuditoria($_SESSION['usuario_id'], 'Editar plantilla', 'plantillas_inspeccion', $id);

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error al editar plantilla']);
    }
}

function eliminarPlantilla() {
    global $pdo;

    $id = $_GET['id'] ?? '';

    if (empty($id)) {
        http_response_code(400);
        echo json_encode(['error' => 'ID requerido']);
        return;
    }

    try {
        $stmt = $pdo->prepare('UPDATE plantillas_inspeccion SET estado = "inactivo" WHERE id = ?');
        $stmt->execute([$id]);

        registrarAuditoria($_SESSION['usuario_id'], 'Eliminar plantilla', 'plantillas_inspeccion', $id);

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error al eliminar plantilla']);
    }
}

function agregarArea() {
    global $pdo;

    $data = json_decode(file_get_contents('php://input'), true);
    $plantilla_id = $data['plantilla_id'] ?? '';
    $nombre = $data['nombre'] ?? '';
    $descripcion = $data['descripcion'] ?? '';

    if (empty($plantilla_id) || empty($nombre)) {
        http_response_code(400);
        echo json_encode(['error' => 'Datos incompletos']);
        return;
    }

    try {
        // Obtener el orden
        $stmt = $pdo->prepare('SELECT MAX(orden) FROM areas_plantilla WHERE plantilla_id = ?');
        $stmt->execute([$plantilla_id]);
        $orden = ($stmt->fetchColumn() ?? 0) + 1;

        $stmt = $pdo->prepare('
            INSERT INTO areas_plantilla (plantilla_id, nombre, descripcion, orden)
            VALUES (?, ?, ?, ?)
        ');

        $stmt->execute([$plantilla_id, $nombre, $descripcion, $orden]);

        $area_id = $pdo->lastInsertId();

        registrarAuditoria($_SESSION['usuario_id'], 'Agregar área', 'areas_plantilla', $area_id);

        echo json_encode([
            'success' => true,
            'id' => $area_id,
            'mensaje' => 'Área agregada exitosamente'
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error al agregar área']);
    }
}

function agregarCampo() {
    global $pdo;

    $data = json_decode(file_get_contents('php://input'), true);
    $area_id = $data['area_id'] ?? '';
    $nombre_campo = $data['nombre_campo'] ?? '';
    $tipo = $data['tipo'] ?? '';

    if (empty($area_id) || empty($nombre_campo)) {
        http_response_code(400);
        echo json_encode(['error' => 'Datos incompletos']);
        return;
    }

    try {
        $stmt = $pdo->prepare('SELECT MAX(orden) FROM campos_area WHERE area_id = ?');
        $stmt->execute([$area_id]);
        $orden = ($stmt->fetchColumn() ?? 0) + 1;

        $stmt = $pdo->prepare('
            INSERT INTO campos_area (area_id, nombre_campo, tipo, orden)
            VALUES (?, ?, ?, ?)
        ');

        $stmt->execute([$area_id, $nombre_campo, $tipo, $orden]);

        $campo_id = $pdo->lastInsertId();

        registrarAuditoria($_SESSION['usuario_id'], 'Agregar campo', 'campos_area', $campo_id);

        echo json_encode([
            'success' => true,
            'id' => $campo_id
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error al agregar campo']);
    }
}

function registrarAuditoria($usuario_id, $accion, $tabla, $registro_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare('INSERT INTO auditoria (usuario_id, accion, tabla, registro_id) VALUES (?, ?, ?, ?)');
        $stmt->execute([$usuario_id, $accion, $tabla, $registro_id]);
    } catch (Exception $e) {
        // Silenciar errores de auditoría
    }
}
