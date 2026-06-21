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
        html{scroll-behavior:smooth}
        body{font-family:'Segoe UI',sans-serif;background:#f0f4ff;color:#333}

        .navbar{background:linear-gradient(135deg,#27ae60 0%,#1e8449 100%);color:#fff;padding:16px 20px;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;z-index:50;box-shadow:0 4px 12px rgba(39,174,96,.15)}
        .navbar h1{font-size:20px;font-weight:700}
        .navbar a{color:#fff;text-decoration:none;font-size:13px;transition:.2s}
        .navbar a:hover{opacity:.8}

        .container{max-width:1000px;margin:24px auto;padding:0 16px}

        .page-header{margin-bottom:28px;text-align:center}
        .page-header h2{font-size:28px;color:#333;margin-bottom:8px}
        .page-header p{color:#888;font-size:14px}

        .reporte-card{background:#fff;border-radius:12px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,.06);margin-bottom:16px;display:flex;flex-direction:column;justify-content:space-between;gap:16px;transition:.2s;border-left:5px solid #27ae60}
        .reporte-card:hover{box-shadow:0 8px 20px rgba(0,0,0,.1);transform:translateY(-4px)}

        .reporte-info h3{font-size:16px;color:#333;margin-bottom:6px;font-weight:700}
        .reporte-info p{font-size:13px;color:#666}
        .reporte-meta{display:flex;gap:20px;margin-top:8px;font-size:12px;color:#888;flex-wrap:wrap}

        .btn{padding:12px 20px;border:none;border-radius:8px;cursor:pointer;font-weight:700;font-size:13px;transition:.2s;display:inline-flex;align-items:center;gap:8px;white-space:nowrap}
        .btn:active{transform:scale(.98)}

        .btn-primary{background:#27ae60;color:#fff}
        .btn-primary:hover{background:#1e8449;box-shadow:0 4px 12px rgba(39,174,96,.3)}

        .empty{text-align:center;padding:60px 20px;background:#fff;border-radius:12px;color:#aaa}
        .empty .icon{font-size:64px;margin-bottom:16px}

        .badge-pub{background:#d4edda;color:#155724;padding:5px 12px;border-radius:20px;font-size:11px;font-weight:700;display:inline-block;letter-spacing:.3px}

        @media(min-width:768px){
            .reporte-card{flex-direction:row;align-items:center;justify-content:space-between}
        }

        @media(max-width:768px){
            .navbar h1{font-size:18px}
            .page-header h2{font-size:22px}
            .btn{width:100%;justify-content:center}
            .reporte-meta{flex-direction:column;gap:8px}
        }
    </style>
</head>
<body>
<div class="navbar">
    <h1>📋 Mis Reportes</h1>
    <div>
        <a href="cliente-dashboard.php">← Panel</a>
        <span style="font-size:13px"><?= htmlspecialchars($nombre) ?></span>
    </div>
</div>

<div class="container">
    <div class="page-header">
        <h2>📋 Mis Reportes de Inspección</h2>
        <p>Descarga los reportes mensuales publicados por tu administrador</p>
    </div>

    <div id="lista"></div>
</div>

<script>
async function cargar() {
    try {
        const r = await fetch('../api/reportes_mensuales.php?action=listar_cliente');
        if (!r.ok) throw new Error(`Error ${r.status}`);

        const d = await r.json();
        const c = document.getElementById('lista');

        if (!d.success || !d.data.length) {
            c.innerHTML = `<div class="empty">
                <div class="icon">📋</div>
                <p>Aún no hay reportes disponibles para ti.</p>
                <p style="font-size:12px;margin-top:12px;color:#bbb">Tu administrador publicará los reportes aquí cuando estén listos</p>
            </div>`;
            return;
        }

        c.innerHTML = d.data.map(rep => `
            <div class="reporte-card">
                <div class="reporte-info">
                    <h3>${sanitize(rep.numero_reporte)}</h3>
                    <p><strong>${mesNombre(rep.mes)} ${rep.anio}</strong></p>
                    <p>${sanitize(rep.nombre_plantilla || 'Reporte de Inspección')}</p>
                    <div class="reporte-meta">
                        <span>📅 ${sanitize(rep.created_at.substring(0,10))}</span>
                        <span class="badge-pub">✓ Disponible</span>
                    </div>
                </div>
                <button class="btn btn-primary" onclick="descargar(${parseInt(rep.id)})" title="Descargar reporte en PDF">
                    ⬇️ Descargar PDF
                </button>
            </div>
        `).join('');
    } catch(e) {
        const c = document.getElementById('lista');
        c.innerHTML = `<div class="empty"><p>Error al cargar reportes: ${e.message}</p></div>`;
    }
}

function sanitize(str) {
    if (typeof str !== 'string') return String(str);
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function descargar(id) {
    window.open(`../api/reporte_pdf.php?reporte_id=${parseInt(id)}&preview=1`, '_blank');
}

function mesNombre(m) {
    return ['','Enero','Febrero','Marzo','Abril','Mayo','Junio',
            'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'][m];
}

cargar();
</script>
</body>
</html>
