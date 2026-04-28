<?php
require_once '../config/config.php';
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== ROLE_ADMIN) {
    header('Location: ../public/login.html'); exit;
}
$nombre = $_SESSION['nombre'];

try {
    $stats = [];
    foreach ([
        'inspectores'  => "SELECT COUNT(*) FROM usuarios WHERE rol='inspector' AND estado='activo'",
        'clientes'     => "SELECT COUNT(*) FROM usuarios WHERE rol='cliente'  AND estado='activo'",
        'extintores'   => "SELECT COUNT(*) FROM extintores WHERE estado='activo'",
        'pendientes'   => "SELECT COUNT(*) FROM inspecciones WHERE DATE(fecha)=CURDATE()",
    ] as $k => $q) {
        $stats[$k] = $pdo->query($q)->fetchColumn();
    }
} catch (Exception $e) {
    $stats = ['inspectores'=>0,'clientes'=>0,'extintores'=>0,'pendientes'=>0];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin – Panel</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Segoe UI',sans-serif;background:#f4f6fb}
        .navbar{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:18px 32px;display:flex;justify-content:space-between;align-items:center}
        .navbar h1{font-size:20px}
        .logout{background:rgba(255,255,255,.2);color:#fff;border:none;padding:8px 16px;border-radius:6px;cursor:pointer;font-size:13px}
        .logout:hover{background:rgba(255,255,255,.3)}
        .container{max-width:1100px;margin:36px auto;padding:0 20px}
        .welcome{font-size:22px;font-weight:700;color:#333;margin-bottom:28px}
        .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:18px;margin-bottom:36px}
        .stat-card{background:#fff;border-radius:12px;padding:24px;box-shadow:0 2px 8px rgba(0,0,0,.07);text-align:center}
        .stat-card .num{font-size:48px;font-weight:800;margin:8px 0}
        .stat-card .lbl{font-size:13px;color:#888;text-transform:uppercase;font-weight:600}
        .c1{color:#667eea}.c2{color:#764ba2}.c3{color:#27ae60}.c4{color:#f39c12}
        .menu{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:16px}
        .menu-item{background:#fff;border-radius:12px;padding:28px 20px;text-align:center;cursor:pointer;transition:.2s;box-shadow:0 2px 8px rgba(0,0,0,.07);border:2px solid transparent}
        .menu-item:hover{border-color:#667eea;transform:translateY(-3px);box-shadow:0 6px 18px rgba(102,126,234,.2)}
        .menu-item .icon{font-size:40px;margin-bottom:14px}
        .menu-item h3{color:#333;font-size:15px}
        .menu-item p{color:#888;font-size:12px;margin-top:6px}
        .section-title{font-size:18px;font-weight:700;color:#444;margin-bottom:18px}
    </style>
</head>
<body>
<div class="navbar">
    <h1>🔥 Sistema de Extintores – Administrador</h1>
    <div style="display:flex;align-items:center;gap:16px">
        <span style="font-size:13px"><?= htmlspecialchars($nombre) ?></span>
        <button class="logout" onclick="logout()">Cerrar sesión</button>
    </div>
</div>

<div class="container">
    <div class="welcome">Buen día, <?= htmlspecialchars(explode(' ',$nombre)[0]) ?> 👋</div>

    <div class="stats">
        <div class="stat-card">
            <div class="lbl">Inspectores</div>
            <div class="num c1"><?= $stats['inspectores'] ?></div>
        </div>
        <div class="stat-card">
            <div class="lbl">Clientes</div>
            <div class="num c2"><?= $stats['clientes'] ?></div>
        </div>
        <div class="stat-card">
            <div class="lbl">Extintores activos</div>
            <div class="num c3"><?= $stats['extintores'] ?></div>
        </div>
        <div class="stat-card">
            <div class="lbl">Inspecciones hoy</div>
            <div class="num c4"><?= $stats['pendientes'] ?></div>
        </div>
    </div>

    <div class="section-title">Módulos</div>
    <div class="menu">
        <div class="menu-item" onclick="go('extintores.php')">
            <div class="icon">🧯</div>
            <h3>Extintores</h3>
            <p>Agregar, editar, eliminar y ver QRs</p>
        </div>
        <div class="menu-item" onclick="go('admin-plantillas.php')">
            <div class="icon">📋</div>
            <h3>Plantillas</h3>
            <p>Crear plantillas de reporte para clientes</p>
        </div>
        <div class="menu-item" onclick="go('admin-reportes.php')">
            <div class="icon">📊</div>
            <h3>Reportes</h3>
            <p>Generar reportes mensuales PDF</p>
        </div>
        <div class="menu-item" onclick="go('admin-usuarios.php')">
            <div class="icon">👥</div>
            <h3>Usuarios</h3>
            <p>Crear y gestionar usuarios</p>
        </div>
        <div class="menu-item" onclick="go('admin-inspecciones.php')">
            <div class="icon">🔍</div>
            <h3>Inspecciones</h3>
            <p>Historial de inspecciones realizadas</p>
        </div>
        <div class="menu-item" onclick="go('admin-empresas.php')">
            <div class="icon">🏢</div>
            <h3>Empresas</h3>
            <p>Gestión de empresas cliente</p>
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
