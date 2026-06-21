<?php
require_once '../config/config.php';
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== ROLE_INSPECTOR) {
    header('Location: ../public/login.html'); exit;
}
$nombre = $_SESSION['nombre'];
$uid    = $_SESSION['usuario_id'];

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inspecciones WHERE inspector_id=?");
    $stmt->execute([$uid]); $total = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inspecciones WHERE inspector_id=? AND DATE(fecha)=CURDATE()");
    $stmt->execute([$uid]); $hoy = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inspecciones WHERE inspector_id=? AND MONTH(fecha)=MONTH(CURDATE()) AND YEAR(fecha)=YEAR(CURDATE())");
    $stmt->execute([$uid]); $mes = $stmt->fetchColumn();
} catch (Exception $e) {
    $total = $hoy = $mes = 0;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inspector – Panel</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Segoe UI',sans-serif;background:#f4f6fb}
        .navbar{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:18px 32px;display:flex;justify-content:space-between;align-items:center}
        .navbar h1{font-size:20px}
        .logout{background:rgba(255,255,255,.2);color:#fff;border:none;padding:8px 16px;border-radius:6px;cursor:pointer;font-size:13px}
        .container{max-width:900px;margin:36px auto;padding:0 20px}
        .welcome{font-size:22px;font-weight:700;color:#333;margin-bottom:28px}
        .stats{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;margin-bottom:36px}
        .stat-card{background:#fff;border-radius:12px;padding:24px;box-shadow:0 2px 8px rgba(0,0,0,.07);text-align:center}
        .stat-card .num{font-size:48px;font-weight:800;margin:8px 0}
        .stat-card .lbl{font-size:13px;color:#888;text-transform:uppercase;font-weight:600}
        .c1{color:#667eea}.c2{color:#27ae60}.c3{color:#f39c12}
        .menu{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px}
        .menu-item{background:#fff;border-radius:12px;padding:28px 20px;text-align:center;cursor:pointer;transition:.2s;box-shadow:0 2px 8px rgba(0,0,0,.07);border:2px solid transparent}
        .menu-item:hover{border-color:#667eea;transform:translateY(-3px);box-shadow:0 6px 18px rgba(102,126,234,.2)}
        .menu-item.destacado{border-color:#27ae60;background:#f0fff4}
        .menu-item .icon{font-size:40px;margin-bottom:14px}
        .menu-item h3{color:#333;font-size:15px}
        .menu-item p{color:#888;font-size:12px;margin-top:6px}
        .section-title{font-size:18px;font-weight:700;color:#444;margin-bottom:18px}
        @media(max-width:600px){.stats{grid-template-columns:1fr}}
    </style>
</head>
<body>
<div class="navbar">
    <h1>🔥 Sistema de Extintores – Inspector</h1>
    <div style="display:flex;align-items:center;gap:16px">
        <span style="font-size:13px"><?= htmlspecialchars($nombre) ?></span>
        <button class="logout" onclick="logout()">Cerrar sesión</button>
    </div>
</div>

<div class="container">
    <div class="welcome">Buen día, <?= htmlspecialchars(explode(' ',$nombre)[0]) ?> 👋</div>

    <div class="stats">
        <div class="stat-card">
            <div class="lbl">Inspecciones hoy</div>
            <div class="num c2"><?= $hoy ?></div>
        </div>
        <div class="stat-card">
            <div class="lbl">Este mes</div>
            <div class="num c3"><?= $mes ?></div>
        </div>
        <div class="stat-card">
            <div class="lbl">Total historial</div>
            <div class="num c1"><?= $total ?></div>
        </div>
    </div>

    <div class="section-title">Acciones</div>
    <div class="menu">
        <div class="menu-item destacado" onclick="go('inspeccion.php')">
            <div class="icon">🔍</div>
            <h3>Inspeccionar</h3>
            <p>Escanea QR o ingresa código</p>
        </div>
        <div class="menu-item" onclick="go('extintores.php')">
            <div class="icon">🧯</div>
            <h3>Extintores</h3>
            <p>Agregar, editar o eliminar extintores</p>
        </div>
        <div class="menu-item" onclick="go('mis-inspecciones.php')">
            <div class="icon">📂</div>
            <h3>Mis Inspecciones</h3>
            <p>Historial de inspecciones realizadas</p>
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
