<?php
require_once '../config/config.php';
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] === ROLE_CLIENTE) {
    header('Location: ../public/login.html'); exit;
}
$rol    = $_SESSION['rol'];
$nombre = $_SESSION['nombre'];
$es_admin = $rol === ROLE_ADMIN;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Extintores – Gestión</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Segoe UI',sans-serif;background:#f4f6fb}
        .navbar{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:16px 32px;display:flex;justify-content:space-between;align-items:center}
        .navbar h1{font-size:20px}
        .navbar a{color:#fff;text-decoration:none;font-size:13px;margin-left:16px}
        .container{max-width:1200px;margin:32px auto;padding:0 20px}
        .toolbar{display:flex;gap:12px;margin-bottom:24px;flex-wrap:wrap;align-items:center}
        .toolbar input,.toolbar select{padding:10px 14px;border:1px solid #ddd;border-radius:6px;font-size:14px;min-width:200px}
        .btn{padding:10px 20px;border:none;border-radius:6px;cursor:pointer;font-size:14px;font-weight:600;transition:.2s}
        .btn-primary{background:#667eea;color:#fff}
        .btn-primary:hover{background:#5568d3}
        .btn-danger{background:#e74c3c;color:#fff}
        .btn-danger:hover{background:#c0392b}
        .btn-warning{background:#f39c12;color:#fff}
        .btn-warning:hover{background:#d68910}
        .btn-success{background:#27ae60;color:#fff}
        .btn-success:hover{background:#1e8449}
        .btn-sm{padding:6px 12px;font-size:12px}
        table{width:100%;border-collapse:collapse;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08)}
        thead{background:#667eea;color:#fff}
        th,td{padding:13px 16px;text-align:left;font-size:13px}
        tbody tr:hover{background:#f0f3ff}
        tbody tr:nth-child(even){background:#fafafa}
        tbody tr:nth-child(even):hover{background:#f0f3ff}
        .badge{display:inline-block;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700}
        .badge-activo{background:#d4edda;color:#155724}
        .badge-inactivo{background:#e2e3e5;color:#383d41}
        .badge-prestamo{background:#fff3cd;color:#856404}
        .tipo-tag{background:#e8ecff;color:#3a4db7;padding:3px 8px;border-radius:4px;font-size:11px;font-weight:600}
        .empty{text-align:center;padding:60px;color:#999}
        .empty .icon{font-size:56px;margin-bottom:16px}

        /* Modal */
        .modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;justify-content:center;align-items:center}
        .modal-overlay.open{display:flex}
        .modal{background:#fff;border-radius:12px;padding:32px;width:100%;max-width:560px;max-height:90vh;overflow-y:auto;box-shadow:0 10px 40px rgba(0,0,0,.2)}
        .modal h2{margin-bottom:24px;color:#333}
        .form-row{display:grid;grid-template-columns:1fr 1fr;gap:16px}
        .form-group{margin-bottom:16px}
        .form-group label{display:block;margin-bottom:6px;font-weight:600;font-size:13px;color:#444}
        .form-group input,.form-group select,.form-group textarea{width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;font-size:14px;font-family:inherit}
        .form-group textarea{resize:vertical;min-height:80px}
        .modal-actions{display:flex;gap:12px;justify-content:flex-end;margin-top:20px;border-top:1px solid #eee;padding-top:20px}

        /* QR Modal */
        .qr-box{text-align:center;padding:20px}
        .qr-box canvas,.qr-box img{margin:0 auto 16px;display:block}
        .qr-code-text{font-family:monospace;background:#f4f6fb;padding:8px 16px;border-radius:6px;font-size:13px;margin-bottom:16px;word-break:break-all}

        .alert{padding:12px 16px;border-radius:6px;margin-bottom:16px;font-size:14px}
        .alert-success{background:#d4edda;color:#155724}
        .alert-error{background:#f8d7da;color:#721c24}
    </style>
</head>
<body>

<div class="navbar">
    <h1>🔥 Gestión de Extintores</h1>
    <div>
        <a href="<?= $es_admin ? 'admin-dashboard.php' : 'inspector-dashboard.php' ?>">← Panel</a>
        <span style="margin-left:16px;font-size:13px"><?= htmlspecialchars($nombre) ?></span>
    </div>
</div>

<div class="container">
    <div id="alert-box"></div>

    <div class="toolbar">
        <input type="text" id="busqueda" placeholder="Buscar por código, ubicación…" oninput="filtrar()">
        <?php if ($es_admin): ?>
        <select id="filtroEmpresa" onchange="filtrar()">
            <option value="">Todas las empresas</option>
        </select>
        <?php endif; ?>
        <select id="filtroEstado" onchange="filtrar()">
            <option value="">Todos los estados</option>
            <option value="activo">Activo</option>
            <option value="inactivo">Inactivo</option>
            <option value="en_prestamo">En préstamo</option>
        </select>
        <button class="btn btn-primary" onclick="abrirModalNuevo()">+ Agregar extintor</button>
        <?php if ($es_admin): ?>
        <button class="btn btn-success" onclick="window.location.href='admin-reportes.php'">📊 Reportes</button>
        <?php endif; ?>
        <button class="btn btn-warning" onclick="window.location.href='inspeccion.php'">🔍 Inspeccionar</button>
    </div>

    <div id="tabla-container"></div>
</div>

<!-- Modal Crear / Editar -->
<div class="modal-overlay" id="modalExtintor">
    <div class="modal">
        <h2 id="modalTitulo">Nuevo Extintor</h2>
        <div id="modal-alert"></div>
        <input type="hidden" id="ext-id">

        <div class="form-row">
            <div class="form-group">
                <label>Código Manual</label>
                <input type="text" id="ext-codigo" placeholder="EXT-001 (auto si vacío)">
            </div>
            <div class="form-group">
                <label>Empresa *</label>
                <select id="ext-empresa"></select>
            </div>
        </div>
        <div class="form-group">
            <label>Sección / Edificio</label>
            <input type="text" id="ext-seccion" placeholder="Ej: EDIFICIO DE VIGILANCIA" style="text-transform:uppercase">
            <small style="color:#888;font-size:11px">Agrupa los extintores en el reporte mensual</small>
        </div>
        <div class="form-group">
            <label>Ubicación / Área específica *</label>
            <input type="text" id="ext-ubicacion" placeholder="Ej: Caseta vigilancia baños">
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Tipo *</label>
                <select id="ext-tipo"></select>
            </div>
            <div class="form-group">
                <label>Capacidad</label>
                <input type="text" id="ext-capacidad" placeholder="Ej: 6 kg">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Fecha de Recarga</label>
                <input type="date" id="ext-recarga">
            </div>
            <div class="form-group">
                <label>Fecha Prueba Hidrostática</label>
                <input type="date" id="ext-ph">
            </div>
        </div>
        <div class="form-group">
            <label>Estado</label>
            <select id="ext-estado">
                <option value="activo">Activo</option>
                <option value="inactivo">Inactivo</option>
                <option value="en_prestamo">En préstamo</option>
            </select>
        </div>
        <div class="form-group">
            <label>Observaciones</label>
            <textarea id="ext-obs" placeholder="Notas adicionales…"></textarea>
        </div>

        <div class="modal-actions">
            <button class="btn btn-warning" onclick="cerrarModal()">Cancelar</button>
            <button class="btn btn-primary" onclick="guardarExtintor()">Guardar</button>
        </div>
    </div>
</div>

<!-- Modal QR -->
<div class="modal-overlay" id="modalQR">
    <div class="modal" style="max-width:400px">
        <h2>Código QR</h2>
        <div class="qr-box">
            <div id="qr-canvas"></div>
            <div class="qr-code-text" id="qr-codigo-manual"></div>
            <p id="qr-ubicacion" style="color:#666;font-size:13px;margin-bottom:16px"></p>
        </div>
        <div class="modal-actions">
            <button class="btn btn-primary" onclick="imprimirQR()">🖨️ Imprimir etiqueta</button>
            <button class="btn btn-warning" onclick="cerrarModalQR()">Cerrar</button>
        </div>
    </div>
</div>

<!-- QR Generator library (CDN) -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
let extintores = [];
let empresas   = [];
let tipos      = [];

// ── Cargar datos iniciales ───────────────────────────────────────────────────
async function init() {
    await Promise.all([cargarEmpresas(), cargarTipos(), cargarExtintores()]);
}

async function cargarTipos() {
    try {
        const r = await fetch('../api/extintores.php?action=get_tipos');
        const d = await r.json();
        if (d.success) {
            tipos = d.data;
            const sel = document.getElementById('ext-tipo');
            sel.innerHTML = tipos.map(t => `<option value="${t.id}">${t.nombre}</option>`).join('');
        }
    } catch(e) { console.error('Error cargar tipos:', e); }
}

async function cargarEmpresas() {
    try {
        const r = await fetch('../api/usuarios.php?action=listar_empresas');
        const d = await r.json();
        if (d.success) {
            empresas = d.data;
            const sel = document.getElementById('ext-empresa');
            sel.innerHTML = empresas.map(e => `<option value="${e.id}">${e.nombre}</option>`).join('');

            // Cargar filtro de empresa para admin
            const filtro = document.getElementById('filtroEmpresa');
            if (filtro) {
                filtro.innerHTML = '<option value="">Todas las empresas</option>' +
                    empresas.map(e => `<option value="${e.id}">${e.nombre}</option>`).join('');
            }
        }
    } catch(e) {}
}

async function cargarExtintores() {
    const r = await fetch('../api/extintores.php?action=listar');
    const d = await r.json();
    if (d.success) {
        extintores = d.data;
        renderTabla(extintores);
    }
}

// ── Render tabla ─────────────────────────────────────────────────────────────
function renderTabla(data) {
    const c = document.getElementById('tabla-container');
    if (!data.length) {
        c.innerHTML = `<div class="empty"><div class="icon">🧯</div><p>No hay extintores registrados</p></div>`;
        return;
    }
    const esAdmin = <?= $es_admin ? 'true' : 'false' ?>;

    c.innerHTML = `
    <table>
        <thead><tr>
            <th>Código</th><th>Empresa</th><th>Ubicación</th><th>Tipo</th>
            <th>Capacidad</th><th>Recarga</th><th>PH</th><th>Última insp.</th>
            <th>Estado</th><th>Acciones</th>
        </tr></thead>
        <tbody>
        ${data.map(e => `
            <tr>
                <td><strong>${e.codigo_manual}</strong></td>
                <td>${e.empresa_nombre}</td>
                <td>${e.ubicacion}</td>
                <td><span class="tipo-tag">${e.tipo_nombre}</span></td>
                <td>${e.capacidad || '—'}</td>
                <td>${e.fecha_recarga || '—'}</td>
                <td>${e.fecha_ph || '—'}</td>
                <td>${e.ultima_inspeccion ? e.ultima_inspeccion.substring(0,10) : '<span style="color:#e74c3c">Sin inspección</span>'}</td>
                <td><span class="badge badge-${e.estado}">${estadoLabel(e.estado)}</span></td>
                <td style="white-space:nowrap">
                    <button class="btn btn-sm btn-primary" onclick="verQR(${e.id})">QR</button>
                    <button class="btn btn-sm btn-warning" onclick="editarExtintor(${e.id})">✏️</button>
                    ${esAdmin ? `<button class="btn btn-sm btn-danger" onclick="eliminarExtintor(${e.id})">🗑️</button>` : ''}
                </td>
            </tr>
        `).join('')}
        </tbody>
    </table>`;
}

function estadoLabel(s) {
    return {activo:'Activo', inactivo:'Inactivo', en_prestamo:'En préstamo'}[s] ?? s;
}

// ── Filtrar ───────────────────────────────────────────────────────────────────
function filtrar() {
    const q = document.getElementById('busqueda').value.toLowerCase();
    const e = document.getElementById('filtroEstado').value;
    const empr = document.getElementById('filtroEmpresa')?.value || '';
    renderTabla(extintores.filter(x =>
        (!q || x.codigo_manual.toLowerCase().includes(q) || x.ubicacion.toLowerCase().includes(q)) &&
        (!e || x.estado === e) &&
        (!empr || x.empresa_id == empr)
    ));
}

// ── Modal Nuevo / Editar ──────────────────────────────────────────────────────
function abrirModalNuevo() {
    limpiarModal();
    document.getElementById('modalTitulo').textContent = 'Nuevo Extintor';
    document.getElementById('modalExtintor').classList.add('open');
}

function editarExtintor(id) {
    const ext = extintores.find(e => e.id == id);
    if (!ext) return;
    limpiarModal();
    document.getElementById('modalTitulo').textContent = 'Editar Extintor';
    document.getElementById('ext-id').value        = ext.id;
    document.getElementById('ext-codigo').value    = ext.codigo_manual;
    document.getElementById('ext-empresa').value   = ext.empresa_id;
    document.getElementById('ext-seccion').value   = ext.seccion || '';
    document.getElementById('ext-ubicacion').value = ext.ubicacion;
    document.getElementById('ext-tipo').value      = ext.tipo;
    document.getElementById('ext-capacidad').value = ext.capacidad || '';
    document.getElementById('ext-recarga').value   = ext.fecha_recarga || '';
    document.getElementById('ext-ph').value        = ext.fecha_ph || '';
    document.getElementById('ext-estado').value    = ext.estado;
    document.getElementById('ext-obs').value       = ext.observaciones || '';
    document.getElementById('modalExtintor').classList.add('open');
}

function limpiarModal() {
    ['ext-id','ext-codigo','ext-seccion','ext-ubicacion','ext-capacidad','ext-recarga','ext-ph','ext-obs']
        .forEach(id => document.getElementById(id).value = '');
    document.getElementById('ext-tipo').value   = tipos.length > 0 ? tipos[0].id : '';
    document.getElementById('ext-estado').value = 'activo';
    document.getElementById('modal-alert').innerHTML = '';
}

function cerrarModal() {
    document.getElementById('modalExtintor').classList.remove('open');
}

async function guardarExtintor() {
    const id       = document.getElementById('ext-id').value;
    const empresa  = document.getElementById('ext-empresa').value;
    const ubicacion= document.getElementById('ext-ubicacion').value.trim();

    if (!empresa || !ubicacion) {
        modalAlert('Empresa y Ubicación son requeridos', 'error'); return;
    }

    const body = {
        empresa_id:     parseInt(empresa),
        seccion:        document.getElementById('ext-seccion').value.trim().toUpperCase() || null,
        ubicacion,
        tipo:           document.getElementById('ext-tipo').value,
        capacidad:      document.getElementById('ext-capacidad').value || null,
        fecha_recarga:  document.getElementById('ext-recarga').value  || null,
        fecha_ph:       document.getElementById('ext-ph').value       || null,
        estado:         document.getElementById('ext-estado').value,
        observaciones:  document.getElementById('ext-obs').value      || null,
        codigo_manual:  document.getElementById('ext-codigo').value   || null,
    };
    if (id) body.id = parseInt(id);

    const action = id ? 'editar' : 'crear';
    const r = await fetch(`../api/extintores.php?action=${action}`, {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify(body)
    });
    const d = await r.json();

    if (d.success) {
        cerrarModal();
        showAlert(`Extintor ${id ? 'actualizado' : 'creado'} correctamente`, 'success');
        await cargarExtintores();
    } else {
        modalAlert(d.error || 'Error al guardar', 'error');
    }
}

// ── Eliminar ──────────────────────────────────────────────────────────────────
async function eliminarExtintor(id) {
    if (!confirm('¿Eliminar este extintor? Se marcará como inactivo.')) return;
    const r = await fetch(`../api/extintores.php?action=eliminar&id=${id}`);
    const d = await r.json();
    if (d.success) { showAlert('Extintor eliminado', 'success'); cargarExtintores(); }
    else showAlert(d.error, 'error');
}

// ── QR ────────────────────────────────────────────────────────────────────────
function verQR(id) {
    const ext = extintores.find(e => e.id == id);
    if (!ext) return;

    document.getElementById('qr-codigo-manual').textContent = ext.codigo_manual;
    document.getElementById('qr-ubicacion').textContent     = ext.ubicacion;

    const canvas = document.getElementById('qr-canvas');
    canvas.innerHTML = '';

    const url = `${location.origin}${location.pathname.replace('extintores.php','inspeccion.php')}?qr=${ext.codigo_qr}`;

    new QRCode(canvas, {
        text:          url,
        width:         220,
        height:        220,
        colorDark:     '#000000',
        colorLight:    '#ffffff',
        correctLevel:  QRCode.CorrectLevel.M
    });

    document.getElementById('modalQR').classList.add('open');
}

function cerrarModalQR() {
    document.getElementById('modalQR').classList.remove('open');
}

function imprimirQR() {
    const codigo   = document.getElementById('qr-codigo-manual').textContent;
    const ubicacion= document.getElementById('qr-ubicacion').textContent;
    const canvas   = document.getElementById('qr-canvas').querySelector('canvas');
    if (!canvas) { alert('QR no generado aún'); return; }

    const dataURL = canvas.toDataURL('image/png');
    const win = window.open('', '_blank');
    win.document.write(`
        <html><head>
            <title>Etiqueta Extintor ${codigo}</title>
            <style>
                body{font-family:Arial,sans-serif;text-align:center;padding:20px}
                .etiqueta{border:3px solid #333;display:inline-block;padding:20px 30px;border-radius:8px;max-width:260px}
                h3{color:#c0392b;margin:0 0 8px;font-size:16px}
                h2{margin:0 0 4px;font-size:18px}
                p{margin:0;color:#555;font-size:12px}
                img{margin:12px auto;display:block}
                @media print{@page{margin:10mm}}
            </style>
        </head><body>
            <div class="etiqueta">
                <h3>🔥 Extintor</h3>
                <h2>${codigo}</h2>
                <p>${ubicacion}</p>
                <img src="${dataURL}" width="180">
                <p style="margin-top:8px;font-size:11px;color:#999">Escanear para inspeccionar</p>
            </div>
            <script>window.onload=()=>{window.print();window.close()}<\/script>
        </body></html>
    `);
    win.document.close();
}

// ── Alertas ───────────────────────────────────────────────────────────────────
function showAlert(msg, tipo) {
    const b = document.getElementById('alert-box');
    b.innerHTML = `<div class="alert alert-${tipo}">${msg}</div>`;
    setTimeout(() => b.innerHTML = '', 4000);
}
function modalAlert(msg, tipo) {
    document.getElementById('modal-alert').innerHTML = `<div class="alert alert-${tipo}">${msg}</div>`;
}

init();
</script>
</body>
</html>
