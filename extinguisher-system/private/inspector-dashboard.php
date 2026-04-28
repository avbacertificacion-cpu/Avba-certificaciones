<?php
require_once '../config/config.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== ROLE_INSPECTOR) {
    header('Location: ../public/login.html');
    exit;
}

$nombre_usuario = $_SESSION['nombre'];
$inspector_id = $_SESSION['usuario_id'];

// Obtener estadísticas del inspector
try {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM reportes_inspeccion WHERE inspeccionado_por = ?');
    $stmt->execute([$inspector_id]);
    $total_reportes = $stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM reportes_inspeccion WHERE inspeccionado_por = ? AND estado = "pendiente_aprobacion"');
    $stmt->execute([$inspector_id]);
    $reportes_pendientes = $stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM reportes_inspeccion WHERE inspeccionado_por = ? AND estado = "aprobado"');
    $stmt->execute([$inspector_id]);
    $reportes_aprobados = $stmt->fetchColumn();
} catch (Exception $e) {
    $total_reportes = 0;
    $reportes_pendientes = 0;
    $reportes_aprobados = 0;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inspector Dashboard - Gestión de Extintores</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
        }

        .navbar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px 40px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .navbar h1 {
            font-size: 24px;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .btn-logout {
            background: rgba(255,255,255,0.2);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
        }

        .btn-logout:hover {
            background: rgba(255,255,255,0.3);
        }

        .container {
            max-width: 1200px;
            margin: 40px auto;
            padding: 0 20px;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }

        .card {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
        }

        .card h3 {
            color: #666;
            font-size: 14px;
            text-transform: uppercase;
            margin-bottom: 15px;
        }

        .card .number {
            font-size: 48px;
            font-weight: bold;
            color: #667eea;
            margin-bottom: 15px;
        }

        .menu {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 40px;
        }

        .menu-item {
            background: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .menu-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(102,126,234,0.2);
        }

        .menu-item .icon {
            font-size: 40px;
            margin-bottom: 15px;
        }

        .menu-item h3 {
            color: #333;
            font-size: 16px;
        }
    </style>
</head>
<body>
    <div class="navbar">
        <h1>🔥 Gestión de Extintores - Inspector</h1>
        <div class="user-info">
            <span><?php echo htmlspecialchars($nombre_usuario); ?></span>
            <button class="btn-logout" onclick="logout()">Cerrar Sesión</button>
        </div>
    </div>

    <div class="container">
        <div class="grid">
            <div class="card">
                <h3>Total Reportes</h3>
                <div class="number"><?php echo $total_reportes; ?></div>
            </div>
            <div class="card">
                <h3>Pendientes Aprobación</h3>
                <div class="number" style="color: #ffc107;"><?php echo $reportes_pendientes; ?></div>
            </div>
            <div class="card">
                <h3>Aprobados</h3>
                <div class="number" style="color: #28a745;"><?php echo $reportes_aprobados; ?></div>
            </div>
        </div>

        <h2 style="margin-bottom: 20px;">Mis Acciones</h2>
        <div class="menu">
            <div class="menu-item" onclick="navigateTo('nuevo-reporte')">
                <div class="icon">➕</div>
                <h3>Nuevo Reporte</h3>
            </div>
            <div class="menu-item" onclick="navigateTo('mis-reportes')">
                <div class="icon">📂</div>
                <h3>Mis Reportes</h3>
            </div>
            <div class="menu-item" onclick="navigateTo('plantillas-disponibles')">
                <div class="icon">📋</div>
                <h3>Plantillas</h3>
            </div>
        </div>
    </div>

    <script>
        function navigateTo(section) {
            window.location.href = 'inspector-' + section + '.php';
        }

        function logout() {
            fetch('../api/auth.php?action=logout', { method: 'POST' })
                .then(() => {
                    window.location.href = '../public/login.html';
                });
        }
    </script>
</body>
</html>
