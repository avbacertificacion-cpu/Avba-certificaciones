<?php
require_once '../config/config.php';
require_once '../config/roles-extra.php';
require_once '../config/empresa-config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401); echo json_encode(['error' => 'No autenticado']); exit;
}

$rol    = $_SESSION['rol'];
$uid    = $_SESSION['usuario_id'];
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'listar':               listar();               break;
    case 'listar_empresas':      listarEmpresas();       break;
    case 'listar_inspectores':   listarInspectores();    break;
    case 'crear':                crear();                break;
    case 'editar':               editar();               break;
    case 'eliminar':             eliminar();             break;
    case 'crear_empresa':        crearEmpresa();         break;
    case 'desactivar_empresa':   desactivarEmpresa();    break;
    default:
        http_response_code(400); echo json_encode(['error' => 'Acción no válida']);
}

// ─── LISTAR USUARIOS ─────────────────────────────────────────────────────────
function listar() {
    global $pdo, $rol;

    if ($rol !== ROLE_ADMIN) {
        http_response_code(403); echo json_encode(['error' => 'Sin permiso']); return;
    }

    $stmt = $pdo->query("
        SELECT u.id, u.nombre, u.username, u.email, u.rol, u.estado, u.empresa_id, u.created_at,
               e.nombre AS empresa_nombre
        FROM usuarios u
        LEFT JOIN empresas e ON e.id = u.empresa_id
        ORDER BY u.rol, u.nombre
    ");
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// ─── LISTAR EMPRESAS ─────────────────────────────────────────────────────────
function listarEmpresas() {
    global $pdo;
    // Se devuelven todos los datos de contacto: la pantalla de empresas los usa
    // para precargar el formulario de edición (si no vinieran, al guardar se
    // sobrescribirían con valores vacíos).
    $colAlertas = empresaTieneColumnaAlertas($pdo) ? 'mostrar_alertas' : '1 AS mostrar_alertas';
    $stmt = $pdo->query("
        SELECT id, nombre, rfc, domicilio, telefono, email, contacto, $colAlertas
        FROM empresas WHERE estado='activo' ORDER BY nombre
    ");
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// ─── LISTAR INSPECTORES ──────────────────────────────────────────────────────
function listarInspectores() {
    global $pdo;
    $stmt = $pdo->query("
        SELECT id, nombre FROM usuarios
        WHERE rol='inspector' AND estado='activo'
        ORDER BY nombre
    ");
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// ─── CREAR USUARIO ───────────────────────────────────────────────────────────
function crear() {
    global $pdo, $rol, $uid;

    if ($rol !== ROLE_ADMIN) {
        http_response_code(403); echo json_encode(['error' => 'Sin permiso']); return;
    }

    $d = json_decode(file_get_contents('php://input'), true);

    foreach (['nombre','username','password','rol'] as $c) {
        if (empty($d[$c])) {
            http_response_code(400); echo json_encode(['error' => "Campo requerido: $c"]); return;
        }
    }

    if (!preg_match('/^[a-z0-9_]{3,50}$/', strtolower($d['username']))) {
        http_response_code(400); echo json_encode(['error' => 'Usuario inválido: solo letras, números y _ (mín. 3 caracteres)']); return;
    }

    if (!in_array($d['rol'], ['administrador','inspector','cliente','gerente'])) {
        http_response_code(400); echo json_encode(['error' => 'Rol inválido']); return;
    }

    // Pre-chequeo: username duplicado (mensaje preciso, incluye si está desactivado)
    $usernameLimpio = strtolower(trim($d['username']));
    $chk = $pdo->prepare("SELECT estado FROM usuarios WHERE username = ? LIMIT 1");
    $chk->execute([$usernameLimpio]);
    $existente = $chk->fetch(PDO::FETCH_ASSOC);
    if ($existente) {
        $extra = (isset($existente['estado']) && $existente['estado'] === 'inactivo')
            ? ' (ese usuario existe pero está desactivado)' : '';
        http_response_code(409);
        echo json_encode(['error' => "El nombre de usuario \"$usernameLimpio\" ya está en uso$extra"]);
        return;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO usuarios (nombre, username, email, password, rol, empresa_id, estado)
            VALUES (?,?,?,?,?,?,'activo')
        ");
        // Solo clientes se asignan a empresas
        $empresa_id = null;
        if ($d['rol'] === 'cliente' && !empty($d['empresa_id'])) {
            $empresa_id = $d['empresa_id'];
        }

        $stmt->execute([
            $d['nombre'],
            strtolower(trim($d['username'])),
            !empty($d['email']) ? strtolower(trim($d['email'])) : null,
            password_hash($d['password'], PASSWORD_BCRYPT),
            $d['rol'],
            $empresa_id,
        ]);

        $newId = $pdo->lastInsertId();
        audit($uid, "Crear usuario {$d['username']} rol {$d['rol']}", 'usuarios', $newId);

        echo json_encode(['success' => true, 'id' => $newId]);
    } catch (PDOException $e) {
        [$code, $msg] = mapearErrorUsuario($e);
        http_response_code($code);
        echo json_encode(['error' => $msg]);
    }
}

// ─── Traducir errores de MySQL a mensajes claros para el admin ───────────────
// Usa el código nativo de MySQL (errorInfo[1]), no coincidencias de texto frágiles:
// 1048 = columna no puede ser NULL, 1062 = valor duplicado, 1452 = FK inválida.
function mapearErrorUsuario(PDOException $e) {
    $native  = $e->errorInfo[1] ?? null;
    $detalle = $e->errorInfo[2] ?? $e->getMessage();

    if ($native == 1048) {
        if (stripos($detalle, "'email'") !== false) {
            return [500, "La columna 'email' de la base de datos no permite dejarse vacía. Pide al administrador del sistema que la corrija en /private/reparar-rol.php."];
        }
        return [500, "Falta un dato requerido por la base de datos: " . $detalle];
    }
    if ($native == 1062) {
        if (stripos($detalle, 'username') !== false) {
            return [409, 'El nombre de usuario ya está en uso.'];
        }
        if (stripos($detalle, 'email') !== false) {
            return [409, 'El correo ya está registrado en otro usuario.'];
        }
        return [409, 'Ese valor ya está en uso por otro registro.'];
    }
    if ($native == 1452) {
        return [400, 'La empresa seleccionada no es válida. Vuelve a elegirla.'];
    }
    return [500, 'Error al guardar el usuario: ' . $detalle];
}

// ─── EDITAR USUARIO ──────────────────────────────────────────────────────────
function editar() {
    global $pdo, $rol, $uid;

    if ($rol !== ROLE_ADMIN) {
        http_response_code(403); echo json_encode(['error' => 'Sin permiso']); return;
    }

    $d  = json_decode(file_get_contents('php://input'), true);
    $id = intval($d['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID requerido']); return; }

    // Solo clientes se asignan a empresas
    $empresa_id = null;
    if ($d['rol'] === 'cliente' && !empty($d['empresa_id'])) {
        $empresa_id = $d['empresa_id'];
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE usuarios SET nombre=?, username=?, email=?, rol=?, empresa_id=?, estado=? WHERE id=?
        ");
        $stmt->execute([
            $d['nombre'],
            strtolower(trim($d['username'] ?? '')),
            !empty($d['email']) ? strtolower(trim($d['email'])) : null,
            $d['rol'],
            $empresa_id,
            $d['estado'] ?? 'activo',
            $id,
        ]);

        // Cambiar contraseña si se proporcionó
        if (!empty($d['password'])) {
            $stmt = $pdo->prepare("UPDATE usuarios SET password=? WHERE id=?");
            $stmt->execute([password_hash($d['password'], PASSWORD_BCRYPT), $id]);
        }

        audit($uid, "Editar usuario", 'usuarios', $id);
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        [$code, $msg] = mapearErrorUsuario($e);
        http_response_code($code);
        echo json_encode(['error' => $msg]);
    }
}

// ─── ELIMINAR (soft-delete) ──────────────────────────────────────────────────
function eliminar() {
    global $pdo, $rol, $uid;

    if ($rol !== ROLE_ADMIN) {
        http_response_code(403); echo json_encode(['error' => 'Sin permiso']); return;
    }

    $id = intval($_GET['id'] ?? 0);
    if (!$id || $id == $uid) {
        http_response_code(400); echo json_encode(['error' => 'No puedes eliminarte a ti mismo']); return;
    }

    $pdo->prepare("UPDATE usuarios SET estado='inactivo' WHERE id=?")->execute([$id]);
    audit($uid, "Eliminar usuario", 'usuarios', $id);
    echo json_encode(['success' => true]);
}

// ─── CREAR EMPRESA ───────────────────────────────────────────────────────────
function crearEmpresa() {
    global $pdo, $rol, $uid;

    if ($rol !== ROLE_ADMIN) {
        http_response_code(403); echo json_encode(['error' => 'Sin permiso']); return;
    }

    $d = json_decode(file_get_contents('php://input'), true);
    if (empty($d['nombre'])) {
        http_response_code(400); echo json_encode(['error' => 'Nombre requerido']); return;
    }

    $id = $d['id'] ?? null;

    // Preferencia de alertas del panel del cliente (por defecto: mostrarlas)
    asegurarColumnaAlertas($pdo);
    $tieneAlertas   = empresaTieneColumnaAlertas($pdo);
    $mostrarAlertas = array_key_exists('mostrar_alertas', $d) ? (int) !empty($d['mostrar_alertas']) : 1;

    if ($id) {
        // Editar empresa existente
        $sql = "UPDATE empresas SET nombre=?, rfc=?, domicilio=?, telefono=?, email=?, contacto="
             . ($tieneAlertas ? "?, mostrar_alertas=? WHERE id=?" : "? WHERE id=?");
        $params = [
            $d['nombre'],
            $d['rfc']       ?? null,
            $d['domicilio'] ?? null,
            $d['telefono']  ?? null,
            $d['email']     ?? null,
            $d['contacto']  ?? null,
        ];
        if ($tieneAlertas) $params[] = $mostrarAlertas;
        $params[] = $id;

        $pdo->prepare($sql)->execute($params);
        audit($uid, "Editar empresa {$d['nombre']}", 'empresas', $id);
    } else {
        // Crear empresa nueva
        $cols   = "nombre,rfc,domicilio,telefono,email,contacto" . ($tieneAlertas ? ",mostrar_alertas" : "");
        $vals   = "?,?,?,?,?,?" . ($tieneAlertas ? ",?" : "");
        $params = [
            $d['nombre'],
            $d['rfc']       ?? null,
            $d['domicilio'] ?? null,
            $d['telefono']  ?? null,
            $d['email']     ?? null,
            $d['contacto']  ?? null,
        ];
        if ($tieneAlertas) $params[] = $mostrarAlertas;

        $pdo->prepare("INSERT INTO empresas ($cols) VALUES ($vals)")->execute($params);
        $id = $pdo->lastInsertId();
        audit($uid, "Crear empresa {$d['nombre']}", 'empresas', $id);
    }

    echo json_encode(['success' => true, 'id' => $id]);
}

// ─── DESACTIVAR EMPRESA (SOFT DELETE) ──────────────────────────────────────────
function desactivarEmpresa() {
    global $pdo, $rol, $uid;

    if ($rol !== ROLE_ADMIN) {
        http_response_code(403); echo json_encode(['error' => 'Sin permiso']); return;
    }

    $id = intval($_GET['id'] ?? 0);
    if (!$id) {
        http_response_code(400); echo json_encode(['error' => 'ID requerido']); return;
    }

    // Obtener nombre de la empresa para el audit
    $stmt = $pdo->prepare("SELECT nombre FROM empresas WHERE id=?");
    $stmt->execute([$id]);
    $empresa = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$empresa) {
        http_response_code(404); echo json_encode(['error' => 'Empresa no encontrada']); return;
    }

    // Desactivar (soft delete)
    $stmt = $pdo->prepare("UPDATE empresas SET estado='inactivo' WHERE id=?");
    $stmt->execute([$id]);

    audit($uid, "Desactivar empresa {$empresa['nombre']}", 'empresas', $id);
    echo json_encode(['success' => true]);
}

// ─── HELPER ──────────────────────────────────────────────────────────────────
function audit($uid, $accion, $tabla, $rid) {
    global $pdo;
    try {
        $pdo->prepare("INSERT INTO auditoria (usuario_id,accion,tabla,registro_id,ip) VALUES (?,?,?,?,?)")
            ->execute([$uid, $accion, $tabla, $rid, $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Exception $e) {}
}
