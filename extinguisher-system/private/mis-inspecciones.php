<?php
require_once '../config/config.php';
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== ROLE_INSPECTOR) {
    header('Location: ../public/login.html'); exit;
}
$nombre = $_SESSION['nombre'];
$uid    = $_SESSION['usuario_id'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Inspecciones</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Segoe UI',sans-serif;background:#f4f6fb}
        .navbar{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:16px 32px;display:flex;justify-content:space-between;align-items:center}
        .navbar a{color:#fff;text-decoration:none;font-size:13px;margin-left:16px}
        .container{max-width:1100px;margin:32px auto;padding:0 20px}
        .toolbar{display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap}
        .toolbar input,.toolbar select{padding:10px 14px;border:1px solid #ddd;border-radius:6px;font-size:13px}
        table{width:100%;border-collapse:collapse;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08)}
        thead{background:#667eea;color:#fff}
        th,td{padding:12px 14px;text-align:left;font-size:13px}
        tbody tr:hover{background:#f0f3ff}
        .badge{display:inline-block;padding:3px 8px;border-radius:10px;font-size:11px;font-weight:700}
        .ok{background:#d4edda;color:#155724}
        .nc{background:#f8d7da;color:#721c24}
        .na{background:#e2e3e5;color:#383d41}
        .po{background:#fff3cd;color:#856404}
        .empty{text-align:center;padding:50px;color:#999}
    </style>
</head>
<body>
<div class="navbar">
    <h1>📂 Mis Inspecciones</h1>
    <div>
        <a href="inspector-dashboard.php">← Panel</a>
        <span style="margin-left:16px;font-size:13px"><?= htmlspecialchars($nombre) ?></span>
    </div>
</div>

<div class="container">
    <div class="toolbar">
        <input type="month" id="filtroMes" onchange="cargar()">
        <input type="text" id="filtroCodigo" placeholder="Filtrar por código…" oninput="filtrar()">
    </div>
    <div id="tabla"></div>
</div>

<script>
let data = [];

async function cargar() {
    const mes  = document.getElementById('filtroMes').value;
    let url    = '../api/inspecciones.php?action=listar';
    if (mes) {
        const [anio, m] = mes.split('-');
        url += `&mes=${m}&anio=${anio}`;
    }
    const r = await fetch(url);
    const d = await r.json();
    data = d.success ? d.data : [];
    filtrar();
}

function filtrar() {
    const q = document.getElementById('filtroCodigo').value.toLowerCase();
    const filtrado = data.filter(x =>
        !q || x.codigo_manual.toLowerCase().includes(q) || x.ubicacion.toLowerCase().includes(q)
    );
    render(filtrado);
}

function badge(v) {
    if (!v) return '—';
    return `<span class="badge ${v.toLowerCase()}">${v}</span>`;
}

function render(rows) {
    const c = document.getElementById('tabla');
    if (!rows.length) {
        c.innerHTML = '<div class="empty">No hay inspecciones para mostrar</div>'; return;
    }
    c.innerHTML = `
    <table>
        <thead><tr>
            <th>Fecha</th><th>Código</th><th>Ubicación</th><th>Empresa</th>
            <th>SER</th><th>MG</th><th>PO</th><th>PH</th><th>SG</th>
            <th>PS</th><th>OB</th><th>DAÑ</th><th>PIN</th>
            <th>Observaciones</th>
        </tr></thead>
        <tbody>
        ${rows.map(r => `<tr>
            <td>${r.fecha} ${r.hora.substring(0,5)}</td>
            <td><strong>${r.codigo_manual}</strong></td>
            <td>${r.ubicacion}</td>
            <td>${r.empresa_nombre}</td>
            <td>${badge(r.ser)}</td><td>${badge(r.mg)}</td>
            <td>${badge(r.po)}</td><td>${badge(r.ph)}</td>
            <td>${badge(r.sg)}</td><td>${badge(r.ps)}</td>
            <td>${badge(r.ob)}</td><td>${badge(r.dan)}</td>
            <td>${badge(r.pin)}</td>
            <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${r.observaciones||'—'}</td>
        </tr>`).join('')}
        </tbody>
    </table>`;
}

// Mes actual por defecto
const hoy = new Date();
document.getElementById('filtroMes').value =
    `${hoy.getFullYear()}-${String(hoy.getMonth()+1).padStart(2,'0')}`;
cargar();
</script>
</body>
</html>
