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
    <title>Mis Extintores</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Segoe UI',sans-serif;background:#f4f6fb}
        .navbar{background:linear-gradient(135deg,#27ae60,#1e8449);color:#fff;padding:16px 32px;display:flex;justify-content:space-between;align-items:center}
        .navbar a{color:#fff;text-decoration:none;font-size:13px;margin-left:16px}
        .container{max-width:1100px;margin:32px auto;padding:0 20px}
        .toolbar{margin-bottom:20px}
        .toolbar input{padding:10px 14px;border:1px solid #ddd;border-radius:6px;font-size:13px;min-width:280px}
        table{width:100%;border-collapse:collapse;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08)}
        thead{background:#27ae60;color:#fff}
        th,td{padding:12px 14px;text-align:left;font-size:13px}
        tbody tr:hover{background:#f0fff4}
        .badge{display:inline-block;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700}
        .b-activo{background:#d4edda;color:#155724}
        .b-inactivo{background:#e2e3e5;color:#383d41}
        .b-prestamo{background:#fff3cd;color:#856404}
        .sin-insp{color:#e74c3c;font-weight:600}
        .tipo-tag{background:#e8f5e9;color:#1e8449;padding:3px 8px;border-radius:4px;font-size:11px;font-weight:600}
        .empty{text-align:center;padding:50px;color:#999}
    </style>
</head>
<body>
<div class="navbar">
    <h1>🧯 Mis Extintores</h1>
    <div>
        <a href="cliente-dashboard.php">← Panel</a>
        <span style="margin-left:16px;font-size:13px"><?= htmlspecialchars($nombre) ?></span>
    </div>
</div>

<div class="container">
    <div class="toolbar">
        <input type="text" id="busqueda" placeholder="Buscar por código o ubicación…" oninput="filtrar()">
    </div>
    <div id="tabla"></div>
</div>

<script>
let extintores = [];

async function cargar() {
    const r = await fetch('../api/extintores.php?action=listar&empresa_id=<?= $empresa_id ?>');
    const d = await r.json();
    extintores = d.success ? d.data : [];
    filtrar();
}

function filtrar() {
    const q = document.getElementById('busqueda').value.toLowerCase();
    render(extintores.filter(e =>
        !q || e.codigo_manual.toLowerCase().includes(q) || e.ubicacion.toLowerCase().includes(q)
    ));
}

function render(data) {
    const c = document.getElementById('tabla');
    if (!data.length) {
        c.innerHTML = '<div class="empty">No hay extintores registrados</div>'; return;
    }
    c.innerHTML = `
    <table>
        <thead><tr>
            <th>Código</th><th>Ubicación</th><th>Tipo</th><th>Capacidad</th>
            <th>Recarga</th><th>Prueba Hidrostática</th><th>Última Inspección</th><th>Estado</th>
        </tr></thead>
        <tbody>
        ${data.map(e => `<tr>
            <td><strong>${e.codigo_manual}</strong></td>
            <td>${e.ubicacion}</td>
            <td><span class="tipo-tag">${e.tipo}</span></td>
            <td>${e.capacidad||'—'}</td>
            <td>${e.fecha_recarga||'—'}</td>
            <td>${e.fecha_ph||'—'}</td>
            <td>${e.ultima_inspeccion
                ? e.ultima_inspeccion.substring(0,10)
                : '<span class="sin-insp">Sin inspección</span>'}</td>
            <td><span class="badge b-${e.estado}">${estadoLabel(e.estado)}</span></td>
        </tr>`).join('')}
        </tbody>
    </table>`;
}

function estadoLabel(s) {
    return {activo:'Activo',inactivo:'Inactivo',en_prestamo:'En préstamo'}[s]??s;
}

cargar();
</script>
</body>
</html>
