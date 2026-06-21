<?php
require_once '../config/config.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'login':
        handleLogin();
        break;
    case 'logout':
        handleLogout();
        break;
    case 'register':
        handleRegister();
        break;
    case 'check':
        checkSession();
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Acción no válida']);
}

function handleLogin() {
    global $pdo;

    $data = json_decode(file_get_contents('php://input'), true);
    $username = trim($data['username'] ?? '');
    $password = $data['password'] ?? '';

    if (empty($username) || empty($password)) {
        http_response_code(400);
        echo json_encode(['error' => 'Usuario y contraseña requeridos']);
        return;
    }

    try {
        $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE username = ? AND estado = "activo"');
        $stmt->execute([$username]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($usuario && password_verify($password, $usuario['password'])) {
            $_SESSION['usuario_id'] = $usuario['id'];
            $_SESSION['nombre'] = $usuario['nombre'];
            $_SESSION['rol'] = $usuario['rol'];
            $_SESSION['empresa_id'] = $usuario['empresa_id'];

            // Registrar en auditoría
            registrarAuditoria($usuario['id'], 'Login exitoso', 'usuarios', $usuario['id']);

            echo json_encode([
                'success' => true,
                'mensaje' => 'Login exitoso',
                'rol' => $usuario['rol']
            ]);
        } else {
            http_response_code(401);
            echo json_encode(['error' => 'Credenciales inválidas']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error en el servidor']);
    }
}

function handleLogout() {
    if (isset($_SESSION['usuario_id'])) {
        registrarAuditoria($_SESSION['usuario_id'], 'Logout', 'usuarios', $_SESSION['usuario_id']);
    }
    session_destroy();
    echo json_encode(['success' => true]);
}

function handleRegister() {
    global $pdo;

    $data = json_decode(file_get_contents('php://input'), true);
    $nombre = $data['nombre'] ?? '';
    $email = $data['email'] ?? '';
    $password = $data['password'] ?? '';

    if (empty($nombre) || empty($email) || empty($password)) {
        http_response_code(400);
        echo json_encode(['error' => 'Todos los campos son requeridos']);
        return;
    }

    try {
        $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare('INSERT INTO usuarios (nombre, email, password, rol, estado) VALUES (?, ?, ?, ?, "activo")');
        $stmt->execute([$nombre, $email, $passwordHash, ROLE_CLIENTE]);

        echo json_encode(['success' => true, 'mensaje' => 'Usuario registrado exitosamente']);
    } catch (PDOException $e) {
        http_response_code(400);
        echo json_encode(['error' => 'El email ya está registrado']);
    }
}

function checkSession() {
    if (isset($_SESSION['usuario_id'])) {
        echo json_encode([
            'loggedin' => true,
            'usuario' => $_SESSION['nombre'],
            'rol' => $_SESSION['rol']
        ]);
    } else {
        echo json_encode(['loggedin' => false]);
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
