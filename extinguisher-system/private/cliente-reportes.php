<?php
require_once '../config/config.php';
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== ROLE_CLIENTE) {
    header('Location: ../public/login.html'); exit;
}
$nombre     = $_SESSION['nombre'];
$empresa_id = $_SESSION['empresa_id'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Reportes</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Segoe UI',sans-serif;background:#f4f6fb}
        .navbar{background:linear-gradient(135deg,#27ae60,#1e8449);color:#fff;padding:16px 32px;display:flex;justify-content:space-between;align-items:center}
        .navbar a{color:#fff;text-decoration:none;font-size:13px;margin-left:16px}
        .container{max-width:900px;margin:32px auto;padding:0 20px}
        .reporte-card{background:#fff;border-radius:12px;padding:24px;box-shadow:0 2px 8px rgba(0,0,0,.08);margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;gap:20px}
        .reporte-info h3{font-size:16px;color:#333;margin-bottom:6px}
        .reporte-info p{font-size:13px;color:#888}
        .reporte-meta{display:flex;gap:20px;margin-top:8px;font-size:12px;color:#aaa}
        .btn{padding:10px 20px;border:none;border-radius:8px;cursor:pointer;font-weight:700;font-size:13px;transition:.2s;white-space:nowrap}
        .btn-primary{background:#27ae60;color:#fff}
        .btn-primary:hover{background:#1e8449}
        .empty{text-align:center;padding:60px;color:#aaa}
        .empty .icon{font-size:56px;margin-bottom:16px}
        .badge-pub{background:#d4edda;color:#155724;padding:4px 10px;border-radius:12px;font-size:11px;font-weight:700}
    </style>
</head>
<body>
<div class="navbar">
    <h1>📋 Mis Reportes</h1>
    <div>
        <a href="cliente-dashboard.php">← Panel</a>
        <span style="margin-left:16px;font-size:13px"><?= htmlspecialchars($nombre) ?></span>
    </div>
</div>

<div class="container">
    <div id="lista"></div>
</div>

<script>
async function cargar() {
    const r = await fetch('../api/reportes_mensuales.php?action=listar_cliente');
    const d = await r.json();
    const c = document.getElementById('lista');

    if (!d.success || !d.data.length) {
        c.innerHTML = `<div class="empty">
            <div class="icon">📋</div>
            <p>Aún no tienes reportes disponibles.</p>
        </div>`;
        return;
    }

    c.innerHTML = d.data.map(rep => `
        <div class="reporte-card">
            <div class="reporte-info">
                <h3>${rep.numero_reporte} – ${mesNombre(rep.mes)} ${rep.anio}</h3>
                <p>${rep.nombre_plantilla}</p>
                <div class="reporte-meta">
                    <span>Generado: ${rep.created_at.substring(0,10)}</span>
                    <span class="badge-pub">Disponible</span>
                </div>
            </div>
            <button class="btn btn-primary" onclick="descargar(${rep.id})">
                ⬇ Descargar PDF
            </button>
        </div>
    `).join('');
}

function descargar(id) {
    window.open(`../api/reportes_mensuales.php?action=pdf&id=${id}`, '_blank');
}

function mesNombre(m) {
    return ['','Enero','Febrero','Marzo','Abril','Mayo','Junio',
            'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'][m];
}

cargar();
</script>
</body>
</html>
