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
    <title>Plantillas de Reporte</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Segoe UI',sans-serif;background:#f4f6fb}
        .navbar{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:16px 32px;display:flex;justify-content:space-between;align-items:center}
        .navbar a{color:#fff;text-decoration:none;font-size:13px;margin-left:16px}
        .container{max-width:1100px;margin:32px auto;padding:0 20px}
        .toolbar{margin-bottom:24px}
        .btn{padding:10px 20px;border:none;border-radius:8px;cursor:pointer;font-weight:700;font-size:13px;transition:.2s}
        .btn-primary{background:#667eea;color:#fff}.btn-primary:hover{background:#5568d3}
        .btn-warning{background:#f39c12;color:#fff}.btn-warning:hover{background:#d68910}
        .btn-danger{background:#e74c3c;color:#fff}.btn-danger:hover{background:#c0392b}
        .btn-sm{padding:6px 12px;font-size:12px}

        .plantillas-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:20px;margin-top:20px}
        .plantilla-card{background:#fff;border-radius:12px;padding:24px;box-shadow:0 2px 8px rgba(0,0,0,.08);border-top:4px solid #667eea}
        .plantilla-card h3{color:#333;margin-bottom:8px;font-size:16px}
        .plantilla-card p{color:#666;font-size:13px;margin-bottom:16px;min-height:40px}
        .plantilla-meta{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;font-size:12px;color:#888}
        .plantilla-actions{display:flex;gap:8px}

        .empty{text-align:center;padding:60px 20px;color:#aaa}

        /* Modal */
        .modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100;justify-content:center;align-items:center}
        .modal-overlay.open{display:flex}
        .modal{background:#fff;border-radius:12px;padding:32px;width:100%;max-width:600px;max-height:90vh;overflow-y:auto;box-shadow:0 10px 40px rgba(0,0,0,.2)}
        .modal h2{margin-bottom:24px}
        .form-group{margin-bottom:16px}
        .form-group label{display:block;font-size:13px;font-weight:700;color:#444;margin-bottom:6px}
        .form-group input,.form-group select,.form-group textarea{width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;font-size:14px;font-family:inherit}
        .form-group textarea{resize:vertical;min-height:80px}
        .modal-actions{display:flex;gap:12px;justify-content:flex-end;margin-top:20px;padding-top:18px;border-top:1px solid #eee}
        .alert{padding:12px;border-radius:6px;margin-bottom:14px;font-size:13px}
        .alert-success{background:#d4edda;color:#155724}
        .alert-error{background:#f8d7da;color:#721c24}

        .areas-list{margin-top:16px;padding-top:16px;border-top:1px solid #eee}
        .area-item{background:#f9f9f9;padding:12px;border-radius:8px;margin-bottom:8px;border-left:3px solid #667eea}
        .area-nombre{font-weight:600;color:#333;margin-bottom:4px}
        .area-campos{font-size:12px;color:#666;margin-left:8px}
    </style>
</head>
<body>
<div class="navbar">
    <h1>📋 Plantillas de Reporte</h1>
    <div>
        <a href="admin-dashboard.php">← Panel</a>
        <span style="margin-left:16px;font-size:13px"><?= htmlspecialchars($nombre) ?></span>
    </div>
</div>

<div class="container">
    <div id="alert-box"></div>

    <div class="toolbar">
        <button class="btn btn-primary" onclick="abrirModalNuevo()">+ Nueva plantilla</button>
    </div>

    <div id="grid"></div>
</div>

<!-- Modal Crear Plantilla -->
<div class="modal-overlay" id="modalPlantilla">
    <div class="modal">
        <h2 id="modalTitulo">Nueva Plantilla</h2>
        <div id="modal-alert"></div>
        <input type="hidden" id="p-id">

        <div class="form-group">
            <label>Nombre *</label>
            <input type="text" id="p-nombre" placeholder="Ej: Inspección Quantum Energía">
        </div>

        <div class="form-group">
            <label>Descripción</label>
            <textarea id="p-desc" placeholder="Descripción de la plantilla…"></textarea>
        </div>

        <div class="form-group">
            <label>Empresa (opcional)</label>
            <select id="p-empresa">
                <option value="">Sin empresa específica</option>
            </select>
        </div>

        <div class="modal-actions">
            <button class="btn btn-warning" onclick="cerrarModal()">Cancelar</button>
            <button class="btn btn-primary" onclick="crearPlantilla()">Crear Plantilla</button>
        </div>
    </div>
</div>

<script>
let plantillas = [];
let empresas = [];

// ── Cargar ────────────────────────────────────────────────────────────────────
async function init() {
    await cargarEmpresas();
    await cargar();
}

async function cargarEmpresas() {
    const r = await fetch('../api/usuarios.php?action=listar_empresas');
    const d = await r.json();
    if (d.success) {
        empresas = d.data;
        const sel = document.getElementById('p-empresa');
        d.data.forEach(e => {
            const opt = document.createElement('option');
            opt.value = e.id;
            opt.textContent = e.nombre;
            sel.appendChild(opt);
        });
    }
}

async function cargar() {
    const r = await fetch('../api/plantillas.php?action=listar');
    const d = await r.json();
    plantillas = d.success ? d.data : [];
    render(plantillas);
}

// ── Render ────────────────────────────────────────────────────────────────────
function render(data) {
    const c = document.getElementById('grid');
    if (!data.length) {
        c.innerHTML = '<div class="empty"><div style="font-size:56px;margin-bottom:16px">📋</div><p>Sin plantillas creadas</p></div>';
        return;
    }

    c.innerHTML = `
    <div class="plantillas-grid">
    ${data.map(p => `
        <div class="plantilla-card">
            <h3>${htmlEscape(p.nombre)}</h3>
            <p>${htmlEscape(p.descripcion || 'Sin descripción')}</p>
            <div class="plantilla-meta">
                <div><strong>${p.numero_reporte}</strong></div>
                <div>${p.empresa_nombre || 'General'}</div>
            </div>
            <div class="plantilla-actions">
                <button class="btn btn-sm btn-primary" onclick="verDetalles(${p.id})">👁️ Ver</button>
                <button class="btn btn-sm btn-danger" onclick="eliminar(${p.id})">🗑️</button>
            </div>
        </div>
    `).join('')}
    </div>`;
}

function htmlEscape(s) {
    const p = document.createElement('p');
    p.textContent = s;
    return p.innerHTML;
}

// ── Modal ─────────────────────────────────────────────────────────────────────
function abrirModalNuevo() {
    limpiarModal();
    document.getElementById('modalTitulo').textContent = 'Nueva Plantilla';
    document.getElementById('modalPlantilla').classList.add('open');
}

function limpiarModal() {
    document.getElementById('p-id').value = '';
    document.getElementById('p-nombre').value = '';
    document.getElementById('p-desc').value = '';
    document.getElementById('p-empresa').value = '';
    document.getElementById('modal-alert').innerHTML = '';
}

function cerrarModal() {
    document.getElementById('modalPlantilla').classList.remove('open');
}

// ── Crear plantilla ───────────────────────────────────────────────────────────
async function crearPlantilla() {
    const nombre = document.getElementById('p-nombre').value.trim();
    if (!nombre) {
        modalAlert('El nombre es requerido', 'error'); return;
    }

    const body = {
        nombre,
        descripcion: document.getElementById('p-desc').value || null,
        empresa_id: document.getElementById('p-empresa').value || null,
    };

    const r = await fetch('../api/plantillas.php?action=crear', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify(body)
    });
    const d = await r.json();

    if (d.success) {
        cerrarModal();
        showAlert(`Plantilla ${d.numero_reporte} creada. Ahora puedes agregar áreas.`, 'success');
        cargar();
    } else {
        modalAlert(d.error || 'Error', 'error');
    }
}

// ── Ver detalles / eliminar ───────────────────────────────────────────────────
async function verDetalles(id) {
    alert('Funcionalidad en desarrollo: agregar áreas y campos a plantillas');
}

async function eliminar(id) {
    if (!confirm('¿Eliminar esta plantilla?')) return;
    const r = await fetch(`../api/plantillas.php?action=eliminar&id=${id}`);
    const d = await r.json();
    if (d.success) {
        showAlert('Plantilla eliminada', 'success');
        cargar();
    } else {
        showAlert(d.error, 'error');
    }
}

// ── Alertas ───────────────────────────────────────────────────────────────────
function showAlert(msg, tipo) {
    const b = document.getElementById('alert-box');
    b.innerHTML = `<div class="alert alert-${tipo}">${msg}</div>`;
    setTimeout(() => b.innerHTML = '', 4000);
}

function modalAlert(msg, tipo) {
    document.getElementById('modal-alert').innerHTML =
        `<div class="alert alert-${tipo}">${msg}</div>`;
}

init();
</script>
</body>
</html>
