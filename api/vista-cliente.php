<?php
/**
 * Entrar y salir de la vista de cliente de una planta (ver config/vista-cliente.php).
 *
 *  entrar (POST, JSON {empresa_id}): sólo un administrador. Su sesión se
 *          guarda aparte y la sesión pasa a ser la de un cliente de esa planta.
 *  salir  (GET): vuelve a la sesión del administrador y lo regresa a Empresas.
 */
require_once '../config/config.php';
require_once '../config/vista-cliente.php';

$action = $_GET['action'] ?? '';

if ($action === 'salir') {
    if (salirVistaCliente()) {
        auditar($pdo, (int) $_SESSION['usuario_id'], 'Salir de la vista de cliente');
        header('Location: ../private/admin-empresas.php');
    } else {
        header('Location: ../public/login.html');
    }
    exit;
}

header('Content-Type: application/json');
header('Cache-Control: no-store');

if ($action !== 'entrar' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400); echo json_encode(['error' => 'Acción no válida']); exit;
}
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== ROLE_ADMIN || enVistaCliente()) {
    http_response_code(403); echo json_encode(['error' => 'Sólo un administrador puede ver como cliente.']); exit;
}

$d = json_decode(file_get_contents('php://input'), true) ?: [];
$empresaId = intval($d['empresa_id'] ?? 0);
$st = $pdo->prepare("SELECT id, nombre FROM empresas WHERE id = ?");
$st->execute([$empresaId]);
$empresa = $st->fetch(PDO::FETCH_ASSOC);
if (!$empresa) { http_response_code(404); echo json_encode(['error' => 'Planta no encontrada.']); exit; }

// El nombre con el que saluda el panel: el del cliente de esa planta, si tiene
$st = $pdo->prepare("SELECT nombre FROM usuarios WHERE rol = ? AND empresa_id = ? AND estado = 'activo' ORDER BY id LIMIT 1");
$st->execute([ROLE_CLIENTE, $empresaId]);
$nombreCliente = $st->fetchColumn() ?: $empresa['nombre'];

$_SESSION['vista_cliente'] = [
    'admin' => [
        'usuario_id' => $_SESSION['usuario_id'],
        'nombre'     => $_SESSION['nombre'],
        'rol'        => $_SESSION['rol'],
        'empresa_id' => $_SESSION['empresa_id'] ?? null,
    ],
    'empresa_nombre' => $empresa['nombre'],
];
// El usuario sigue siendo el administrador (lo que quede en la auditoría es
// suyo); lo que cambia es el papel y la planta, que es lo que ven las pantallas.
$_SESSION['rol']        = ROLE_CLIENTE;
$_SESSION['empresa_id'] = (int) $empresa['id'];
$_SESSION['nombre']     = $nombreCliente;
session_regenerate_id(true);

auditar($pdo, (int) $_SESSION['usuario_id'], "Ver como cliente: {$empresa['nombre']}", (int) $empresa['id']);
echo json_encode(['success' => true, 'ir' => 'cliente-dashboard.php']);

function auditar(PDO $pdo, int $uid, string $accion, ?int $empresaId = null): void {
    try {
        $pdo->prepare("INSERT INTO auditoria (usuario_id, accion, tabla, registro_id, ip) VALUES (?,?,?,?,?)")
            ->execute([$uid, $accion, 'empresas', $empresaId, $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Exception $e) {}
}
