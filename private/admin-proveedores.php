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
<title>Proveedores y Precios</title>
<link rel="stylesheet" href="../public/assets/css/movil.css">
    <style>
    *{margin:0;padding:0;box-sizing:border-box}
    body{font-family:'Segoe UI',system-ui,sans-serif;background:#eef2fb;color:#1a2138}
    .navbar{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:16px 26px;
            display:flex;justify-content:space-between;align-items:center;box-shadow:0 4px 16px rgba(102,126,234,.2)}
    .navbar a{color:#fff;text-decoration:none;font-size:13px;opacity:.9}.navbar a:hover{opacity:1;text-decoration:underline}
    .container{max-width:1250px;margin:0 auto;padding:26px 20px}
    h2{font-size:24px;color:#1e293b}
    .sub{color:#64748b;font-size:13px;margin-bottom:20px}

    .tabs{display:flex;gap:8px;margin-bottom:20px;border-bottom:2px solid #e2e8f0}
    .tab{padding:11px 20px;border:none;background:none;cursor:pointer;font-size:14px;font-weight:700;
         color:#64748b;border-bottom:3px solid transparent;margin-bottom:-2px}
    .tab.active{color:#667eea;border-bottom-color:#667eea}

    .btn{padding:10px 18px;border:none;border-radius:8px;font-weight:700;cursor:pointer;font-size:14px}
    .btn-primary{background:#667eea;color:#fff}.btn-primary:hover{background:#5568d3}
    .btn-danger{background:#e74c3c;color:#fff}.btn-warning{background:#f39c12;color:#fff}
    .btn-sm{padding:6px 12px;font-size:12px}

    .card{background:#fff;border-radius:14px;padding:20px;box-shadow:0 4px 14px rgba(30,41,59,.08)}
    .toolbar{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:16px}
    .toolbar input,.toolbar select{padding:10px 12px;border:2px solid #e0e0ff;border-radius:8px;font-size:14px}
    .toolbar input:focus,.toolbar select:focus{outline:none;border-color:#667eea}

    table{width:100%;border-collapse:collapse}
    thead{background:#f1f5fb}
    th{padding:11px 10px;text-align:left;font-size:11px;color:#475569;font-weight:700;text-transform:uppercase}
    td{padding:11px 10px;font-size:13px;border-bottom:1px solid #f1f5f9}
    td.n,th.n{text-align:right}
    tbody tr:hover{background:#f8faff}

    .modal-ov{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100;align-items:center;justify-content:center;padding:16px}
    .modal-ov.open{display:flex}
    .modal{background:#fff;border-radius:14px;width:100%;max-width:480px;max-height:92vh;overflow-y:auto;padding:24px}
    .modal h3{font-size:18px;margin-bottom:16px;color:#1e293b}
    .fg{margin-bottom:14px}
    .fg label{display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:5px}
    .fg input,.fg select,.fg textarea{width:100%;padding:10px;border:2px solid #e0e0ff;border-radius:8px;font-size:14px;font-family:inherit}
    .fg input:focus,.fg select:focus,.fg textarea:focus{outline:none;border-color:#667eea}
    .row2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .modal-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:8px}
    .empty{text-align:center;padding:50px 20px;color:#94a3b8}.empty .ic{font-size:52px;margin-bottom:10px}
    .msg{font-size:13px;font-weight:600;margin-bottom:12px}
    @media(max-width:760px){ .row2{grid-template-columns:1fr} }
</style>
</head>
<body>
<div class="navbar">
    <a href="admin-dashboard.php">← Panel Admin</a>
    <span style="font-size:13px">👤 <?= htmlspecialchars($nombre) ?></span>
</div>

<div class="container">
    <h2>🏭 Proveedores y Precios</h2>
    <div class="sub">Administra a quién le compras y a qué precio, para usarlo al cotizar.</div>

    <div class="tabs">
        <button class="tab active" id="tab-prov" onclick="verTab('prov')">Proveedores</button>
        <button class="tab" id="tab-cat" onclick="verTab('cat')">Catálogo de precios</button>
    </div>

    <div id="msg" class="msg"></div>

    <!-- PROVEEDORES -->
    <div id="sec-prov">
        <div class="toolbar">
            <input type="text" id="buscaProv" placeholder="🔍 Buscar proveedor…" oninput="renderProv()">
            <button class="btn btn-primary" onclick="abrirProv()">＋ Nuevo proveedor</button>
        </div>
        <div class="card"><div id="tablaProv"></div></div>
    </div>

    <!-- CATÁLOGO -->
    <div id="sec-cat" style="display:none">
        <div class="toolbar">
            <input type="text" id="buscaCat" placeholder="🔍 Buscar producto o servicio…" oninput="renderCat()">
            <select id="filtroProv" onchange="renderCat()"><option value="">Todos los proveedores</option></select>
            <button class="btn btn-primary" onclick="abrirPrecio()">＋ Nuevo precio</button>
        </div>
        <div class="card"><div id="tablaCat"></div></div>
    </div>
</div>

<!-- Modal proveedor -->
<div class="modal-ov" id="modalProv">
    <div class="modal">
        <h3 id="tituloProv">Nuevo proveedor</h3>
        <input type="hidden" id="p-id">
        <div class="fg"><label>Nombre del proveedor *</label><input type="text" id="p-nombre" placeholder="Ej: Extintores del Golfo S.A."></div>
        <div class="fg row2">
            <div><label>Persona de contacto</label><input type="text" id="p-contacto" placeholder="Nombre"></div>
            <div><label>Teléfono</label><input type="text" id="p-telefono" placeholder="+52 833 ..."></div>
        </div>
        <div class="fg"><label>Email</label><input type="email" id="p-email" placeholder="ventas@proveedor.com"></div>
        <div class="fg"><label>Notas</label><textarea id="p-notas" rows="2" placeholder="Condiciones, tiempos de entrega…"></textarea></div>
        <div class="modal-actions">
            <button class="btn btn-warning" onclick="cerrar('modalProv')">Cancelar</button>
            <button class="btn btn-primary" onclick="guardarProv()">Guardar</button>
        </div>
    </div>
</div>

<!-- Modal precio -->
<div class="modal-ov" id="modalPrecio">
    <div class="modal">
        <h3 id="tituloPrecio">Nuevo precio</h3>
        <input type="hidden" id="c-id">
        <div class="fg"><label>Descripción del producto o servicio *</label><input type="text" id="c-desc" placeholder="Ej: Extintor PQS 9 kg"></div>
        <div class="fg"><label>Proveedor</label><select id="c-prov"></select></div>
        <div class="fg row2">
            <div><label>Unidad</label><input type="text" id="c-unidad" placeholder="pieza, servicio, kg…"></div>
            <div><label>Costo del proveedor *</label><input type="number" id="c-costo" step="0.01" min="0" placeholder="0.00"></div>
        </div>
        <div class="modal-actions">
            <button class="btn btn-warning" onclick="cerrar('modalPrecio')">Cancelar</button>
            <button class="btn btn-primary" onclick="guardarPrecio()">Guardar</button>
        </div>
    </div>
</div>

<script>
const API = '../api/cotizaciones.php';
let proveedores = [], catalogo = [];

const esc = s => { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };
const money = n => '$' + Number(n || 0).toLocaleString('es-MX', {minimumFractionDigits:2, maximumFractionDigits:2});
const cerrar = id => document.getElementById(id).classList.remove('open');
function aviso(t, ok) {
    const m = document.getElementById('msg');
    m.textContent = t; m.style.color = ok ? '#27ae60' : '#c0392b';
    setTimeout(() => { m.textContent = ''; }, 4000);
}

function verTab(t) {
    document.getElementById('sec-prov').style.display = t === 'prov' ? '' : 'none';
    document.getElementById('sec-cat').style.display  = t === 'cat'  ? '' : 'none';
    document.getElementById('tab-prov').classList.toggle('active', t === 'prov');
    document.getElementById('tab-cat').classList.toggle('active', t === 'cat');
}

// ── Cargar ──────────────────────────────────────────────────────────────────
async function cargar() {
    const [rp, rc] = await Promise.all([
        fetch(`${API}?action=listar_proveedores`).then(r => r.json()),
        fetch(`${API}?action=listar_catalogo`).then(r => r.json()),
    ]);
    proveedores = rp.success ? rp.data : [];
    catalogo    = rc.success ? rc.data : [];

    const opts = '<option value="">— Sin proveedor —</option>' +
        proveedores.map(p => `<option value="${p.id}">${esc(p.nombre)}</option>`).join('');
    document.getElementById('c-prov').innerHTML = opts;
    document.getElementById('filtroProv').innerHTML = '<option value="">Todos los proveedores</option>' +
        proveedores.map(p => `<option value="${p.id}">${esc(p.nombre)}</option>`).join('');

    renderProv(); renderCat();
}

// ── Proveedores ─────────────────────────────────────────────────────────────
function renderProv() {
    const q = document.getElementById('buscaProv').value.toLowerCase();
    const data = proveedores.filter(p => !q || (p.nombre + ' ' + (p.contacto||'')).toLowerCase().includes(q));
    const c = document.getElementById('tablaProv');
    if (!data.length) { c.innerHTML = '<div class="empty"><div class="ic">🏭</div><p>Sin proveedores todavía.</p></div>'; return; }
    c.innerHTML = `<div class="tabla-env"><table class="tabla">
        <thead><tr><th>Proveedor</th><th>Contacto</th><th>Teléfono</th><th>Email</th><th class="n">Precios</th><th></th></tr></thead>
        <tbody>${data.map(p => `<tr>
            <td data-et="Proveedor" class="td-titulo">${esc(p.nombre)}</td>
            <td data-et="Contacto">${esc(p.contacto || '—')}</td>
            <td data-et="Teléfono">${esc(p.telefono || '—')}</td>
            <td data-et="Email">${esc(p.email || '—')}</td>
            <td data-et="Precios" class="n">${p.productos}</td>
            <td class="acciones">
                <button class="btn btn-warning btn-sm" data-lbl="Editar" onclick='editarProv(${JSON.stringify(p)})'>✏️</button>
                <button class="btn btn-danger btn-sm" data-lbl="Eliminar" onclick="borrarProv(${p.id})">🗑️</button>
            </td></tr>`).join('')}</tbody></table></div>`;
}

function abrirProv() {
    document.getElementById('tituloProv').textContent = 'Nuevo proveedor';
    ['p-id','p-nombre','p-contacto','p-telefono','p-email','p-notas'].forEach(i => document.getElementById(i).value = '');
    document.getElementById('modalProv').classList.add('open');
}
function editarProv(p) {
    document.getElementById('tituloProv').textContent = 'Editar proveedor';
    document.getElementById('p-id').value = p.id;
    document.getElementById('p-nombre').value = p.nombre || '';
    document.getElementById('p-contacto').value = p.contacto || '';
    document.getElementById('p-telefono').value = p.telefono || '';
    document.getElementById('p-email').value = p.email || '';
    document.getElementById('p-notas').value = p.notas || '';
    document.getElementById('modalProv').classList.add('open');
}
async function guardarProv() {
    const body = {
        id: parseInt(document.getElementById('p-id').value) || 0,
        nombre: document.getElementById('p-nombre').value.trim(),
        contacto: document.getElementById('p-contacto').value.trim(),
        telefono: document.getElementById('p-telefono').value.trim(),
        email: document.getElementById('p-email').value.trim(),
        notas: document.getElementById('p-notas').value.trim(),
    };
    if (!body.nombre) { alert('El nombre es obligatorio'); return; }
    const r = await fetch(`${API}?action=guardar_proveedor`, {
        method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body)
    });
    const d = await r.json().catch(() => ({}));
    if (r.ok && d.success) { cerrar('modalProv'); aviso('✓ Proveedor guardado', true); cargar(); }
    else aviso(d.error || 'Error al guardar', false);
}
async function borrarProv(id) {
    if (!confirm('¿Eliminar este proveedor? Las cotizaciones anteriores lo conservarán.')) return;
    const r = await fetch(`${API}?action=eliminar_proveedor&id=${id}`);
    const d = await r.json().catch(() => ({}));
    if (r.ok && d.success) { aviso('✓ Proveedor eliminado', true); cargar(); }
    else aviso(d.error || 'Error al eliminar', false);
}

// ── Catálogo ────────────────────────────────────────────────────────────────
function renderCat() {
    const q = document.getElementById('buscaCat').value.toLowerCase();
    const fp = document.getElementById('filtroProv').value;
    const data = catalogo.filter(c =>
        (!q || (c.descripcion + ' ' + (c.proveedor_nombre||'')).toLowerCase().includes(q)) &&
        (!fp || String(c.proveedor_id) === fp));
    const cont = document.getElementById('tablaCat');
    if (!data.length) { cont.innerHTML = '<div class="empty"><div class="ic">🏷️</div><p>Sin precios registrados.</p></div>'; return; }
    cont.innerHTML = `<div class="tabla-env"><table class="tabla">
        <thead><tr><th>Descripción</th><th>Proveedor</th><th>Unidad</th><th class="n">Costo</th><th></th></tr></thead>
        <tbody>${data.map(c => `<tr>
            <td data-et="Descripción" class="td-titulo">${esc(c.descripcion)}</td>
            <td data-et="Proveedor">${esc(c.proveedor_nombre || '—')}</td>
            <td data-et="Unidad">${esc(c.unidad || '—')}</td>
            <td data-et="Costo" class="n" style="font-weight:700">${money(c.costo)}</td>
            <td class="acciones">
                <button class="btn btn-warning btn-sm" data-lbl="Editar" onclick='editarPrecio(${JSON.stringify(c)})'>✏️</button>
                <button class="btn btn-danger btn-sm" data-lbl="Eliminar" onclick="borrarPrecio(${c.id})">🗑️</button>
            </td></tr>`).join('')}</tbody></table></div>`;
}

function abrirPrecio() {
    document.getElementById('tituloPrecio').textContent = 'Nuevo precio';
    ['c-id','c-desc','c-unidad','c-costo'].forEach(i => document.getElementById(i).value = '');
    document.getElementById('c-prov').value = '';
    document.getElementById('modalPrecio').classList.add('open');
}
function editarPrecio(c) {
    document.getElementById('tituloPrecio').textContent = 'Editar precio';
    document.getElementById('c-id').value = c.id;
    document.getElementById('c-desc').value = c.descripcion || '';
    document.getElementById('c-prov').value = c.proveedor_id || '';
    document.getElementById('c-unidad').value = c.unidad || '';
    document.getElementById('c-costo').value = c.costo || '';
    document.getElementById('modalPrecio').classList.add('open');
}
async function guardarPrecio() {
    const body = {
        id: parseInt(document.getElementById('c-id').value) || 0,
        descripcion: document.getElementById('c-desc').value.trim(),
        proveedor_id: parseInt(document.getElementById('c-prov').value) || 0,
        unidad: document.getElementById('c-unidad').value.trim(),
        costo: parseFloat(document.getElementById('c-costo').value) || 0,
    };
    if (!body.descripcion) { alert('La descripción es obligatoria'); return; }
    const r = await fetch(`${API}?action=guardar_precio`, {
        method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body)
    });
    const d = await r.json().catch(() => ({}));
    if (r.ok && d.success) { cerrar('modalPrecio'); aviso('✓ Precio guardado', true); cargar(); }
    else aviso(d.error || 'Error al guardar', false);
}
async function borrarPrecio(id) {
    if (!confirm('¿Eliminar este precio del catálogo?')) return;
    const r = await fetch(`${API}?action=eliminar_precio&id=${id}`);
    const d = await r.json().catch(() => ({}));
    if (r.ok && d.success) { aviso('✓ Precio eliminado', true); cargar(); }
    else aviso(d.error || 'Error al eliminar', false);
}

document.querySelectorAll('.modal-ov').forEach(m =>
    m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); }));

cargar();
</script>
</body>
</html>
