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
        html{scroll-behavior:smooth}
        body{font-family:'Segoe UI',sans-serif;background:#f0f4ff;color:#333}

        .navbar{background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:#fff;padding:16px 20px;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;z-index:50;box-shadow:0 4px 12px rgba(102,126,234,.15)}
        .navbar h1{font-size:20px;font-weight:700}
        .navbar a{color:#fff;text-decoration:none;font-size:13px;transition:.2s}
        .navbar a:hover{opacity:.8}

        .container{max-width:1200px;margin:24px auto;padding:0 16px}

        .page-header{margin-bottom:28px}
        .page-header h2{font-size:28px;color:#333;margin-bottom:8px}
        .page-header p{color:#888;font-size:14px}

        .toolbar{margin-bottom:24px}
        .btn{padding:12px 20px;border:none;border-radius:8px;cursor:pointer;font-weight:700;font-size:13px;transition:.2s;display:inline-flex;align-items:center;gap:8px}
        .btn:active{transform:scale(.98)}
        .btn:disabled{opacity:.5;cursor:not-allowed}

        .btn-primary{background:#667eea;color:#fff}.btn-primary:hover{background:#5568d3;box-shadow:0 4px 12px rgba(102,126,234,.3)}
        .btn-warning{background:#f39c12;color:#fff}.btn-warning:hover{background:#d68910;box-shadow:0 4px 12px rgba(243,156,18,.3)}
        .btn-danger{background:#e74c3c;color:#fff}.btn-danger:hover{background:#c0392b;box-shadow:0 4px 12px rgba(231,76,60,.3)}
        .btn-sm{padding:8px 12px;font-size:12px}

        .plantillas-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:20px;margin-top:20px}
        .plantilla-card{background:#fff;border-radius:12px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,.06);border-top:4px solid #667eea;transition:.2s;display:flex;flex-direction:column}
        .plantilla-card:hover{box-shadow:0 8px 20px rgba(0,0,0,.1);transform:translateY(-4px)}

        .plantilla-card h3{color:#333;margin-bottom:8px;font-size:16px;font-weight:700}
        .plantilla-card p{color:#666;font-size:13px;margin-bottom:16px;min-height:40px;flex:1}
        .plantilla-meta{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;font-size:12px;color:#888}
        .plantilla-meta div{background:#f9f9f9;padding:8px;border-radius:6px;text-align:center;border-left:3px solid #667eea}

        .plantilla-actions{display:flex;gap:8px;margin-top:auto}
        .plantilla-actions .btn{flex:1;justify-content:center}

        .empty{text-align:center;padding:60px 20px;background:#fff;border-radius:12px;color:#aaa}
        .empty .icon{font-size:64px;margin-bottom:16px}

        @media(max-width:768px){
            .navbar h1{font-size:18px}
            .page-header h2{font-size:22px}
            .plantillas-grid{grid-template-columns:1fr}
            .btn{width:100%;justify-content:center}
            .plantilla-actions{flex-direction:column}
        }

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
    <h1>📋 Plantillas</h1>
    <div>
        <a href="admin-dashboard.php">← Volver</a>
        <span style="margin-left:16px;font-size:13px"><?= htmlspecialchars($nombre) ?></span>
    </div>
</div>

<div class="container">
    <div class="page-header">
        <h2>📋 Plantillas de Reporte</h2>
        <p>Crea y gestiona plantillas reutilizables para inspecciones mensuales</p>
    </div>

    <div id="alert-box"></div>

    <div class="toolbar">
        <button class="btn btn-primary" onclick="abrirModalNuevo()">+ Nueva plantilla</button>
    </div>

    <div id="grid"></div>
</div>

<!-- Modal Crear Plantilla -->
<div class="modal-overlay" id="modalPlantilla" onclick="if(event.target===this) cerrarModal()">
    <div class="modal">
        <h2 id="modalTitulo">Nueva Plantilla</h2>
        <div id="modal-alert"></div>
        <input type="hidden" id="p-id">

        <div class="form-group">
            <label>Nombre de la plantilla *</label>
            <input type="text" id="p-nombre" placeholder="Ej: Inspección Quantum Energía" required>
            <small>Nombre único para identificar esta plantilla</small>
        </div>

        <div class="form-group">
            <label>Descripción</label>
            <textarea id="p-desc" placeholder="Describe para qué sirve esta plantilla…" style="resize:vertical;min-height:80px"></textarea>
            <small>Texto que aparecerá en los reportes generados con esta plantilla</small>
        </div>

        <div class="form-group">
            <label>Empresa (opcional)</label>
            <select id="p-empresa">
                <option value="">Plantilla general (todas las empresas)</option>
            </select>
            <small>Deja vacío para que cualquier empresa pueda usar esta plantilla</small>
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
        c.innerHTML = '<div class="empty"><div class="icon">📋</div><p>No hay plantillas aún. Crea la primera para comenzar.</p></div>';
        return;
    }

    c.innerHTML = `
    <div class="plantillas-grid">
    ${data.map(p => `
        <div class="plantilla-card">
            <h3>${sanitize(p.nombre)}</h3>
            <p>${sanitize(p.descripcion || 'Sin descripción')}</p>
            <div class="plantilla-meta">
                <div>📄 ${sanitize(p.numero_reporte)}</div>
                <div>🏢 ${sanitize(p.empresa_nombre || 'General')}</div>
            </div>
            <div class="plantilla-actions">
                <button class="btn btn-sm btn-primary" onclick="verDetalles(${parseInt(p.id)})" title="Ver detalles">👁️ Ver</button>
                <button class="btn btn-sm btn-danger" onclick="eliminar(${parseInt(p.id)})" title="Eliminar plantilla">🗑️</button>
            </div>
        </div>
    `).join('')}
    </div>`;
}

function sanitize(str) {
    if (typeof str !== 'string') return String(str);
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function abrirModalNuevo() {
    limpiarModal();
    document.getElementById('modalTitulo').textContent = 'Nueva Plantilla';
    document.getElementById('p-nombre').focus();
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
    limpiarModal();
}

async function crearPlantilla() {
    const nombre = document.getElementById('p-nombre').value.trim();
    const desc = document.getElementById('p-desc').value.trim();
    const empresa = document.getElementById('p-empresa').value;

    if (!nombre || nombre.length < 3) {
        modalAlert('El nombre debe tener al menos 3 caracteres', 'error');
        document.getElementById('p-nombre').focus();
        return;
    }

    try {
        const body = {
            nombre,
            descripcion: desc || null,
            empresa_id: empresa || null,
        };

        const r = await fetch('../api/plantillas.php?action=crear', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify(body),
            credentials: 'same-origin'
        });

        if (!r.ok) throw new Error(`Error ${r.status}`);

        const d = await r.json();

        if (d.success) {
            cerrarModal();
            showAlert(`✓ Plantilla "${d.numero_reporte}" creada correctamente`, 'success');
            await cargar();
        } else {
            modalAlert(d.error || 'Error al crear plantilla', 'error');
        }
    } catch(e) {
        modalAlert('Error de conexión: ' + e.message, 'error');
    }
}

async function verDetalles(id) {
    const plantilla = plantillas.find(p => p.id === id);
    if (!plantilla) return;
    showAlert('✓ Funcionalidad en desarrollo: agregar áreas y campos a plantillas', 'success');
}

async function eliminar(id) {
    const plantilla = plantillas.find(p => p.id === id);
    if (!plantilla) return;

    if (!confirm(`¿Estás seguro de que deseas eliminar la plantilla "${plantilla.nombre}"?`)) return;

    try {
        const r = await fetch(`../api/plantillas.php?action=eliminar&id=${parseInt(id)}`);
        if (!r.ok) throw new Error(`Error ${r.status}`);

        const d = await r.json();

        if (d.success) {
            showAlert(`✓ Plantilla "${plantilla.nombre}" eliminada correctamente`, 'success');
            await cargar();
        } else {
            showAlert(d.error || 'Error al eliminar', 'error');
        }
    } catch(e) {
        showAlert('Error de conexión: ' + e.message, 'error');
    }
}

function showAlert(msg, tipo) {
    const b = document.getElementById('alert-box');
    const icon = tipo === 'success' ? '✓' : '✕';
    b.innerHTML = `<div class="alert alert-${tipo}"><span class="alert-icon">${icon}</span><span>${msg}</span></div>`;
    setTimeout(() => b.innerHTML = '', 5000);
}

function modalAlert(msg, tipo) {
    const icon = tipo === 'success' ? '✓' : '⚠';
    document.getElementById('modal-alert').innerHTML = `<div class="alert alert-${tipo}"><span class="alert-icon">${icon}</span><span>${msg}</span></div>`;
}

init();
</script>
</body>
</html>
