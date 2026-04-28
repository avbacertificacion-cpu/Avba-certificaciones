<?php
require_once '../config/config.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== ROLE_ADMIN) {
    header('Location: ../public/login.html');
    exit;
}

$nombre_usuario = $_SESSION['nombre'];

// Obtener estadísticas
try {
    $stmt = $pdo->query('SELECT COUNT(*) FROM usuarios WHERE rol = "' . ROLE_INSPECTOR . '"');
    $total_inspectores = $stmt->fetchColumn();

    $stmt = $pdo->query('SELECT COUNT(*) FROM usuarios WHERE rol = "' . ROLE_CLIENTE . '"');
    $total_clientes = $stmt->fetchColumn();

    $stmt = $pdo->query('SELECT COUNT(*) FROM plantillas_inspeccion WHERE estado = "activo"');
    $total_plantillas = $stmt->fetchColumn();

    $stmt = $pdo->query('SELECT COUNT(*) FROM reportes_inspeccion WHERE estado = "pendiente_aprobacion"');
    $reportes_pendientes = $stmt->fetchColumn();
} catch (Exception $e) {
    $total_inspectores = 0;
    $total_clientes = 0;
    $total_plantillas = 0;
    $reportes_pendientes = 0;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Gestión de Extintores</title>
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

        .user-info span {
            font-size: 14px;
        }

        .btn-logout {
            background: rgba(255,255,255,0.2);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: background 0.3s;
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

        .card-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-top: 20px;
        }

        .btn {
            padding: 12px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s;
        }

        .btn-primary {
            background: #667eea;
            color: white;
        }

        .btn-primary:hover {
            background: #5568d3;
        }

        .btn-secondary {
            background: #e0e0e0;
            color: #333;
        }

        .btn-secondary:hover {
            background: #d0d0d0;
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

        .alert-pending {
            background: #fff3cd;
            color: #856404;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border-left: 4px solid #ffc107;
        }
    </style>
</head>
<body>
    <div class="navbar">
        <h1>🔥 Gestión de Extintores - Administrador</h1>
        <div class="user-info">
            <span><?php echo htmlspecialchars($nombre_usuario); ?></span>
            <button class="btn-logout" onclick="logout()">Cerrar Sesión</button>
        </div>
    </div>

    <div class="container">
        <?php if ($reportes_pendientes > 0): ?>
        <div class="alert-pending">
            ⚠️ Tienes <strong><?php echo $reportes_pendientes; ?></strong> reporte(s) pendiente(s) de aprobación
        </div>
        <?php endif; ?>

        <div class="grid">
            <div class="card">
                <h3>Inspectores</h3>
                <div class="number"><?php echo $total_inspectores; ?></div>
            </div>
            <div class="card">
                <h3>Clientes</h3>
                <div class="number"><?php echo $total_clientes; ?></div>
            </div>
            <div class="card">
                <h3>Plantillas Activas</h3>
                <div class="number"><?php echo $total_plantillas; ?></div>
            </div>
            <div class="card">
                <h3>Reportes Pendientes</h3>
                <div class="number" style="color: #ff6b6b;"><?php echo $reportes_pendientes; ?></div>
            </div>
        </div>

        <h2 style="margin-bottom: 20px;">Panel de Control</h2>
        <div class="menu">
            <div class="menu-item" onclick="navigateTo('usuarios')">
                <div class="icon">👥</div>
                <h3>Gestionar Usuarios</h3>
            </div>
            <div class="menu-item" onclick="navigateTo('plantillas')">
                <div class="icon">📋</div>
                <h3>Crear Plantillas</h3>
            </div>
            <div class="menu-item" onclick="navigateTo('reportes')">
                <div class="icon">📊</div>
                <h3>Revisar Reportes</h3>
            </div>
            <div class="menu-item" onclick="navigateTo('empresas')">
                <div class="icon">🏢</div>
                <h3>Empresas</h3>
            </div>
            <div class="menu-item" onclick="navigateTo('auditoria')">
                <div class="icon">📝</div>
                <h3>Auditoría</h3>
            </div>
        </div>
    </div>

    <script>
        function navigateTo(section) {
            window.location.href = 'admin-' + section + '.php';
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
