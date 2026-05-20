<?php
require_once '../config/config.php';
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== ROLE_ADMIN) {
    header('Location: ../public/login.html'); exit;
}
$nombre = $_SESSION['nombre'];

try {
    $stats = [];
    foreach ([
        'inspectores' => "SELECT COUNT(*) FROM usuarios  WHERE rol='inspector' AND estado='activo'",
        'clientes'    => "SELECT COUNT(*) FROM usuarios  WHERE rol='cliente'   AND estado='activo'",
        'extintores'  => "SELECT COUNT(*) FROM extintores WHERE estado='activo'",
        'insp_hoy'    => "SELECT COUNT(*) FROM inspecciones WHERE DATE(fecha)=CURDATE()",
    ] as $k => $q) {
        $stats[$k] = $pdo->query($q)->fetchColumn();
    }

    // Extintores SIN inspección en los últimos 30 días
    $alertas = $pdo->query("
        SELECT e.codigo_manual, e.ubicacion, emp.nombre AS empresa_nombre,
               MAX(i.fecha) AS ultima_fecha
        FROM extintores e
        JOIN empresas emp ON emp.id = e.empresa_id
        LEFT JOIN inspecciones i ON i.extintor_id = e.id
        WHERE e.estado = 'activo'
        GROUP BY e.id
        HAVING ultima_fecha IS NULL OR ultima_fecha < DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        ORDER BY ultima_fecha ASC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $stats  = ['inspectores'=>0,'clientes'=>0,'extintores'=>0,'insp_hoy'=>0];
    $alertas = [];
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
        .container{max-width:1200px;margin:36px auto;padding:0 20px}
        .welcome{font-size:22px;font-weight:700;color:#333;margin-bottom:28px}

        /* Stats */
        .stats{display:grid;grid-template-columns:repeat(4,1fr);gap:18px;margin-bottom:36px}
        .stat-card{background:#fff;border-radius:12px;padding:22px;box-shadow:0 2px 8px rgba(0,0,0,.07);text-align:center}
        .stat-card .num{font-size:44px;font-weight:800;margin:8px 0}
        .stat-card .lbl{font-size:12px;color:#888;text-transform:uppercase;font-weight:600}
        .c1{color:#667eea}.c2{color:#764ba2}.c3{color:#27ae60}.c4{color:#f39c12}

        /* Alertas */
        .alertas-box{background:#fff;border-radius:12px;padding:24px;box-shadow:0 2px 8px rgba(0,0,0,.07);margin-bottom:36px;border-left:5px solid #e74c3c}
        .alertas-box h2{color:#e74c3c;margin-bottom:16px;font-size:16px;display:flex;align-items:center;gap:8px}
        .alerta-item{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid #f5f5f5;font-size:13px}
        .alerta-item:last-child{border-bottom:none}
        .alerta-codigo{font-weight:700;color:#333}
        .alerta-ubi{color:#666;margin-left:10px}
        .alerta-empresa{color:#888;font-size:12px}
        .alerta-fecha{color:#e74c3c;font-weight:600;font-size:12px;white-space:nowrap}
        .sin-insp{color:#e74c3c;font-weight:700}
        .alertas-vacio{color:#27ae60;font-size:14px;text-align:center;padding:16px 0}

        /* Módulos */
        .section-title{font-size:17px;font-weight:700;color:#444;margin-bottom:18px}
        .menu{display:grid;grid-template-columns:repeat(auto-fit,minmax(185px,1fr));gap:16px}
        .menu-item{background:#fff;border-radius:12px;padding:26px 18px;text-align:center;cursor:pointer;transition:.2s;box-shadow:0 2px 8px rgba(0,0,0,.07);border:2px solid transparent}
        .menu-item:hover{border-color:#667eea;transform:translateY(-3px);box-shadow:0 6px 18px rgba(102,126,234,.2)}
        .menu-item .icon{font-size:38px;margin-bottom:12px}
        .menu-item h3{color:#333;font-size:14px;font-weight:700}
        .menu-item p{color:#888;font-size:11px;margin-top:5px}

        @media(max-width:768px){.stats{grid-template-columns:repeat(2,1fr)}}
        @media(max-width:480px){.stats{grid-template-columns:1fr}}
    </style>
</head>
<body>
<div class="navbar">
    <h1>🔥 Sistema de Gestión de Extintores</h1>
    <div style="display:flex;align-items:center;gap:16px">
        <span style="font-size:13px"><?= htmlspecialchars($nombre) ?></span>
        <button class="logout" onclick="logout()">Cerrar sesión</button>
    </div>
</div>

<div class="container">
    <div class="welcome">Buen día, <?= htmlspecialchars(explode(' ',$nombre)[0]) ?> 👋</div>

    <!-- Estadísticas -->
    <div class="stats">
        <div class="stat-card">
            <div class="lbl">Extintores activos</div>
            <div class="num c3"><?= $stats['extintores'] ?></div>
        </div>
        <div class="stat-card">
            <div class="lbl">Inspecciones hoy</div>
            <div class="num c4"><?= $stats['insp_hoy'] ?></div>
        </div>
        <div class="stat-card">
            <div class="lbl">Inspectores</div>
            <div class="num c1"><?= $stats['inspectores'] ?></div>
        </div>
        <div class="stat-card">
            <div class="lbl">Clientes</div>
            <div class="num c2"><?= $stats['clientes'] ?></div>
        </div>
    </div>

    <!-- Alertas: extintores sin inspección reciente -->
    <div class="alertas-box">
        <h2>⚠️ Extintores sin inspección (últimos 30 días)</h2>
        <?php if (empty($alertas)): ?>
            <div class="alertas-vacio">✅ Todos los extintores tienen inspección reciente</div>
        <?php else: ?>
            <?php foreach ($alertas as $a): ?>
            <div class="alerta-item">
                <div>
                    <span class="alerta-codigo"><?= htmlspecialchars($a['codigo_manual']) ?></span>
                    <span class="alerta-ubi">– <?= htmlspecialchars($a['ubicacion']) ?></span>
                    <br>
                    <span class="alerta-empresa"><?= htmlspecialchars($a['empresa_nombre']) ?></span>
                </div>
                <div class="alerta-fecha">
                    <?= $a['ultima_fecha']
                        ? 'Última: ' . $a['ultima_fecha']
                        : '<span class="sin-insp">Sin inspecciones</span>' ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Módulos -->
    <div class="section-title">Módulos</div>
    <div class="menu">
        <div class="menu-item" onclick="go('extintores.php')">
            <div class="icon">🧯</div>
            <h3>Extintores</h3>
            <p>Gestionar, crear QRs e imprimir etiquetas</p>
        </div>
        <div class="menu-item" onclick="go('admin-inspecciones.php')">
            <div class="icon">🔍</div>
            <h3>Inspecciones</h3>
            <p>Ver historial completo de inspecciones</p>
        </div>
        <div class="menu-item" onclick="go('admin-reportes.php')">
            <div class="icon">📊</div>
            <h3>Reportes</h3>
            <p>Generar y publicar reportes mensuales</p>
        </div>
        <div class="menu-item" onclick="go('admin-usuarios.php')">
            <div class="icon">👥</div>
            <h3>Usuarios</h3>
            <p>Crear y gestionar todos los usuarios</p>
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
