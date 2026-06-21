<?php
require_once '../config/config.php';
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== ROLE_ADMIN) {
    header('Location: ../public/login.html'); exit;
}
$nombre = $_SESSION['nombre'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historial de Inspecciones</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        html{scroll-behavior:smooth}
        body{font-family:'Segoe UI',sans-serif;background:#f0f4ff;color:#333}

        .navbar{background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:#fff;padding:16px 20px;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;z-index:50;box-shadow:0 4px 12px rgba(102,126,234,.15)}
        .navbar h1{font-size:20px;font-weight:700}
        .navbar a{color:#fff;text-decoration:none;font-size:13px;transition:.2s}
        .navbar a:hover{opacity:.8}

        .container{max-width:1400px;margin:24px auto;padding:0 16px}

        .page-header{margin-bottom:28px}
        .page-header h2{font-size:28px;color:#333;margin-bottom:8px}
        .page-header p{color:#888;font-size:14px}

        .toolbar{display:grid;grid-template-columns:1fr;gap:12px;margin-bottom:24px}
        @media(min-width:768px){.toolbar{grid-template-columns:1fr 1fr 1fr}}

        .toolbar input,.toolbar select{padding:12px 14px;border:2px solid #e0e0ff;border-radius:8px;font-size:14px;background:#fff;transition:.2s;width:100%}
        .toolbar input:focus,.toolbar select:focus{outline:none;border-color:#667eea;box-shadow:0 0 0 3px rgba(102,126,234,.1)}

        .table-wrapper{background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.06);overflow-x:auto}
        table{width:100%;border-collapse:collapse}
        thead{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff}
        th{padding:14px 12px;text-align:left;font-weight:600;font-size:12px;letter-spacing:.3px;white-space:nowrap}
        td{padding:12px;font-size:12px;border-bottom:1px solid #f0f0f0}
        tbody tr{transition:.2s}
        tbody tr:hover{background:#f8faff}

        .badge{display:inline-block;padding:4px 8px;border-radius:6px;font-size:10px;font-weight:700;white-space:nowrap}
        .ok{background:#d4edda;color:#155724}
        .nc{background:#f8d7da;color:#721c24}
        .na{background:#fff3cd;color:#856404}
        .po{background:#cce5ff;color:#004085}

        .empty{text-align:center;padding:60px 20px;background:#fff;border-radius:12px;color:#aaa}
        .empty .icon{font-size:64px;margin-bottom:16px}

        @media(max-width:768px){
            .navbar h1{font-size:18px}
            .page-header h2{font-size:22px}
            .toolbar{grid-template-columns:1fr}
            th,td{padding:8px 6px;font-size:11px}
            .badge{padding:3px 6px;font-size:9px}
        }
    </style>
</head>
<body>
<div class="navbar">
    <h1>🔍 Inspecciones</h1>
    <div>
        <a href="admin-dashboard.php">← Volver</a>
        <span style="margin-left:16px;font-size:13px"><?= htmlspecialchars($nombre) ?></span>
    </div>
</div>

<div class="container">
    <div class="page-header">
        <h2>🔍 Historial de Inspecciones</h2>
        <p>Revisa todas las inspecciones realizadas por los inspectores</p>
    </div>

    <div class="toolbar">
        <input type="month" id="filtroMes" onchange="cargar()" title="Filtrar por mes y año">
        <input type="text" id="filtroCodigo" placeholder="🔍 Buscar código o ubicación…" oninput="filtrar()" title="Búsqueda en tiempo real">
        <select id="filtroInspector" onchange="cargar()" title="Filtrar por inspector">
            <option value="">📋 Todos los inspectores</option>
        </select>
    </div>

    <div class="table-wrapper">
        <div id="tabla"></div>
    </div>
</div>

<script>
let inspecciones = [];
let inspectores = new Set();

// ── Cargar ────────────────────────────────────────────────────────────────────
async function cargar() {
    const mes = document.getElementById('filtroMes').value;
    let url = '../api/inspecciones.php?action=listar';
    if (mes) {
        const [anio, m] = mes.split('-');
        url += `&mes=${m}&anio=${anio}`;
    }

    const r = await fetch(url);
    const d = await r.json();
    inspecciones = d.success ? d.data : [];

    // Cargar inspectores si es primera vez
    if (inspectores.size === 0) {
        inspecciones.forEach(i => inspectores.add(i.inspector_nombre));
        const sel = document.getElementById('filtroInspector');
        inspectores.forEach(name => {
            const opt = document.createElement('option');
            opt.value = name;
            opt.textContent = name;
            sel.appendChild(opt);
        });
    }

    filtrar();
}

function filtrar() {
    const q = document.getElementById('filtroCodigo').value.toLowerCase();
    const insp = document.getElementById('filtroInspector').value;

    render(inspecciones.filter(x =>
        (!q || x.codigo_manual.toLowerCase().includes(q) || x.ubicacion.toLowerCase().includes(q)) &&
        (!insp || x.inspector_nombre === insp)
    ));
}

// ── Render ────────────────────────────────────────────────────────────────────
function badge(v) {
    if (!v) return '—';
    const cls = {OK:'ok', NC:'nc', NA:'na', PO:'po'}[v] || '';
    return `<span class="badge ${cls}">${v}</span>`;
}

function render(data) {
    const c = document.getElementById('tabla');
    if (!data.length) {
        c.innerHTML = '<div class="empty"><div class="icon">🔍</div><p>No hay inspecciones para mostrar</p></div>';
        return;
    }

    c.innerHTML = `
    <table>
        <thead><tr>
            <th>📅 Fecha</th><th>📍 Código</th><th>🏢 Ubicación</th><th>🏭 Empresa</th><th>👤 Inspector</th>
            <th>SÑ</th><th>MG</th><th>PO</th><th>PH</th><th>SG</th>
            <th>PS</th><th>OB</th><th>DÑ</th><th>PI</th><th>Observaciones</th>
        </tr></thead>
        <tbody>
        ${data.map(i => `<tr>
            <td><strong>${sanitize(i.fecha)} ${sanitize(i.hora.substring(0,5))}</strong></td>
            <td>${sanitize(i.codigo_manual)}</td>
            <td>${sanitize(i.ubicacion)}</td>
            <td>${sanitize(i.empresa_nombre)}</td>
            <td>${sanitize(i.inspector_nombre)}</td>
            <td>${badge(i.ser)}</td><td>${badge(i.mg)}</td>
            <td>${badge(i.po)}</td><td>${badge(i.ph)}</td>
            <td>${badge(i.sg)}</td><td>${badge(i.ps)}</td>
            <td>${badge(i.ob)}</td><td>${badge(i.dan)}</td>
            <td>${badge(i.pin)}</td>
            <td title="${sanitize(i.observaciones || '')}">${sanitize((i.observaciones || '').substring(0, 30))}${(i.observaciones && i.observaciones.length > 30) ? '...' : ''}</td>
        </tr>`).join('')}
        </tbody>
    </table>`;
}

function sanitize(str) {
    if (typeof str !== 'string') return String(str);
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// Mes actual por defecto
const hoy = new Date();
document.getElementById('filtroMes').value =
    `${hoy.getFullYear()}-${String(hoy.getMonth()+1).padStart(2,'0')}`;

cargar();
</script>
</body>
</html>
