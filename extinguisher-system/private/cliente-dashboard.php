<?php
require_once '../config/config.php';
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== ROLE_CLIENTE) {
    header('Location: ../public/login.html'); exit;
}
$nombre     = $_SESSION['nombre'];
$empresa_id = $_SESSION['empresa_id'];

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM extintores WHERE empresa_id=? AND estado='activo'");
    $stmt->execute([$empresa_id]); $ext_activos = $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM inspecciones i
        JOIN extintores e ON e.id=i.extintor_id
        WHERE e.empresa_id=? AND MONTH(i.fecha)=MONTH(CURDATE()) AND YEAR(i.fecha)=YEAR(CURDATE())
    ");
    $stmt->execute([$empresa_id]); $insp_mes = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM reportes_mensuales WHERE empresa_id=? AND estado='generado'");
    $stmt->execute([$empresa_id]); $reportes = $stmt->fetchColumn();
} catch (Exception $e) {
    $ext_activos = $insp_mes = $reportes = 0;
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
        .container{max-width:900px;margin:36px auto;padding:0 20px}
        .welcome{font-size:22px;font-weight:700;color:#333;margin-bottom:28px}
        .stats{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;margin-bottom:36px}
        .stat-card{background:#fff;border-radius:12px;padding:24px;box-shadow:0 2px 8px rgba(0,0,0,.07);text-align:center}
        .stat-card .num{font-size:48px;font-weight:800;margin:8px 0}
        .stat-card .lbl{font-size:13px;color:#888;text-transform:uppercase;font-weight:600}
        .c1{color:#27ae60}.c2{color:#667eea}.c3{color:#f39c12}
        .menu{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px}
        .menu-item{background:#fff;border-radius:12px;padding:28px 20px;text-align:center;cursor:pointer;transition:.2s;box-shadow:0 2px 8px rgba(0,0,0,.07);border:2px solid transparent}
        .menu-item:hover{border-color:#27ae60;transform:translateY(-3px);box-shadow:0 6px 18px rgba(39,174,96,.2)}
        .menu-item .icon{font-size:40px;margin-bottom:14px}
        .menu-item h3{color:#333;font-size:15px}
        .menu-item p{color:#888;font-size:12px;margin-top:6px}
        @media(max-width:600px){.stats{grid-template-columns:1fr}}
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

    <div class="stats">
        <div class="stat-card">
            <div class="lbl">Extintores activos</div>
            <div class="num c1"><?= $ext_activos ?></div>
        </div>
        <div class="stat-card">
            <div class="lbl">Inspecciones este mes</div>
            <div class="num c2"><?= $insp_mes ?></div>
        </div>
        <div class="stat-card">
            <div class="lbl">Reportes disponibles</div>
            <div class="num c3"><?= $reportes ?></div>
        </div>
    </div>

    <div class="menu">
        <div class="menu-item" onclick="go('cliente-extintores.php')">
            <div class="icon">🧯</div>
            <h3>Mis Extintores</h3>
            <p>Ver estado y ubicación de cada extintor</p>
        </div>
        <div class="menu-item" onclick="go('cliente-reportes.php')">
            <div class="icon">📋</div>
            <h3>Reportes Mensuales</h3>
            <p>Descargar reportes de inspección</p>
        </div>
        <div class="menu-item" onclick="go('cliente-historial.php')">
            <div class="icon">📂</div>
            <h3>Historial</h3>
            <p>Inspecciones realizadas por extintor</p>
        </div>
    </div>
</div>

<script>
function go(url) { window.location.href = url; }
function logout() {
    fetch('../api/auth.php?action=logout', {method:'POST'})
        .then(() => window.location.href = '../public/login.html');
}
</script>
</body>
</html>
