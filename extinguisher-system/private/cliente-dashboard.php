<?php
require_once '../config/config.php';
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== ROLE_CLIENTE) {
    header('Location: ../public/login.html'); exit;
}
$nombre     = $_SESSION['nombre'];
$empresa_id = $_SESSION['empresa_id'];

try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM reportes_mensuales
        WHERE empresa_id = ? AND estado = 'publicado'
    ");
    $stmt->execute([$empresa_id]);
    $total_reportes = $stmt->fetchColumn();
} catch (Exception $e) {
    $total_reportes = 0;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portal Cliente – Extintores</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Segoe UI',sans-serif;background:#f4f6fb}
        .navbar{background:linear-gradient(135deg,#27ae60,#1e8449);color:#fff;padding:18px 32px;display:flex;justify-content:space-between;align-items:center}
        .navbar h1{font-size:20px}
        .logout{background:rgba(255,255,255,.2);color:#fff;border:none;padding:8px 16px;border-radius:6px;cursor:pointer;font-size:13px}
        .container{max-width:700px;margin:36px auto;padding:0 20px}
        .welcome{font-size:22px;font-weight:700;color:#333;margin-bottom:28px}
        .stat-card{background:#fff;border-radius:12px;padding:28px;box-shadow:0 2px 8px rgba(0,0,0,.07);text-align:center;margin-bottom:28px}
        .stat-card .num{font-size:56px;font-weight:800;color:#27ae60;margin:8px 0}
        .stat-card .lbl{font-size:14px;color:#888;text-transform:uppercase;font-weight:600}
        .menu-item{background:#fff;border-radius:12px;padding:28px 24px;text-align:center;cursor:pointer;transition:.2s;box-shadow:0 2px 8px rgba(0,0,0,.07);border:2px solid transparent}
        .menu-item:hover{border-color:#27ae60;transform:translateY(-3px);box-shadow:0 6px 18px rgba(39,174,96,.2)}
        .menu-item .icon{font-size:48px;margin-bottom:14px}
        .menu-item h3{color:#333;font-size:17px;margin-bottom:8px}
        .menu-item p{color:#888;font-size:13px}
    </style>
</head>
<body>
<div class="navbar">
    <h1>🧯 Portal de Inspección de Extintores</h1>
    <div style="display:flex;align-items:center;gap:16px">
        <span style="font-size:13px"><?= htmlspecialchars($nombre) ?></span>
        <button class="logout" onclick="logout()">Cerrar sesión</button>
    </div>
</div>

<div class="container">
    <div class="welcome">Hola, <?= htmlspecialchars(explode(' ',$nombre)[0]) ?> 👋</div>

    <div class="stat-card">
        <div class="lbl">Reportes disponibles</div>
        <div class="num"><?= $total_reportes ?></div>
    </div>

    <div class="menu-item" onclick="window.location.href='cliente-reportes.php'">
        <div class="icon">📋</div>
        <h3>Mis Reportes de Inspección</h3>
        <p>Ver y descargar los reportes mensuales autorizados</p>
    </div>
</div>

<script>
function logout() {
    fetch('../api/auth.php?action=logout', {method:'POST'})
        .then(() => window.location.href = '../public/login.html');
}
</script>
</body>
</html>
