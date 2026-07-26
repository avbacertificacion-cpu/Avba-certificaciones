<?php
require_once '../config/config.php';
require_once '../config/roles-extra.php';
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
    $stmt = $pdo->query("SELECT id, nombre FROM empresas WHERE estado='activo' ORDER BY nombre");
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
        if ($e->getCode() == 23000) {
            // El username ya se validó arriba, así que el conflicto es de otro dato
            $msg = 'No se pudo crear el usuario por un conflicto de datos.';
            $detalle = $e->getMessage();
            if (stripos($detalle, 'email') !== false) {
                $msg = 'El correo ya está registrado en otro usuario.';
            } elseif (stripos($detalle, 'foreign key') !== false || stripos($detalle, 'empresa') !== false) {
                $msg = 'La empresa seleccionada no es válida. Vuelve a elegirla.';
            }
            http_response_code(409);
            echo json_encode(['error' => $msg]);
        } else {
            http_response_code(500); echo json_encode(['error' => 'Error al crear usuario']);
        }
    }
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

    if ($id) {
        // Editar empresa existente
        $stmt = $pdo->prepare("
            UPDATE empresas
            SET nombre=?, rfc=?, domicilio=?, telefono=?, email=?, contacto=?
            WHERE id=?
        ");
        $stmt->execute([
            $d['nombre'],
            $d['rfc']       ?? null,
            $d['domicilio'] ?? null,
            $d['telefono']  ?? null,
            $d['email']     ?? null,
            $d['contacto']  ?? null,
            $id
        ]);
        audit($uid, "Editar empresa {$d['nombre']}", 'empresas', $id);
    } else {
        // Crear empresa nueva
        $stmt = $pdo->prepare("INSERT INTO empresas (nombre,rfc,domicilio,telefono,email,contacto) VALUES (?,?,?,?,?,?)");
        $stmt->execute([
            $d['nombre'],
            $d['rfc']       ?? null,
            $d['domicilio'] ?? null,
            $d['telefono']  ?? null,
            $d['email']     ?? null,
            $d['contacto']  ?? null,
        ]);
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
