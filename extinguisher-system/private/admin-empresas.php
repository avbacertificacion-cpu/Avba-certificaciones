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
    <title>Gestión de Empresas</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Segoe UI',sans-serif;background:#f4f6fb}
        .navbar{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:16px 32px;display:flex;justify-content:space-between;align-items:center}
        .navbar a{color:#fff;text-decoration:none;font-size:13px;margin-left:16px}
        .container{max-width:1200px;margin:32px auto;padding:0 20px}
        .toolbar{margin-bottom:24px}
        .btn{padding:10px 20px;border:none;border-radius:8px;cursor:pointer;font-weight:700;font-size:13px;transition:.2s}
        .btn-primary{background:#667eea;color:#fff}.btn-primary:hover{background:#5568d3}
        .btn-warning{background:#f39c12;color:#fff}.btn-warning:hover{background:#d68910}
        .btn-danger{background:#e74c3c;color:#fff}.btn-danger:hover{background:#c0392b}
        .btn-sm{padding:6px 12px;font-size:12px}
        .empresa-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:20px;margin-top:20px}
        .empresa-card{background:#fff;border-radius:12px;padding:24px;box-shadow:0 2px 8px rgba(0,0,0,.08);border-left:5px solid #667eea;display:flex;flex-direction:column}
        .empresa-card h3{color:#333;margin-bottom:12px;font-size:16px}
        .empresa-info{flex:1;margin-bottom:16px;font-size:13px;color:#666}
        .empresa-info p{margin-bottom:6px}
        .empresa-info strong{color:#444}
        .empresa-actions{display:flex;gap:8px}
        .empty{text-align:center;padding:60px 20px;color:#aaa}

        /* Modal */
        .modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100;justify-content:center;align-items:center}
        .modal-overlay.open{display:flex}
        .modal{background:#fff;border-radius:12px;padding:32px;width:100%;max-width:500px;box-shadow:0 10px 40px rgba(0,0,0,.2)}
        .modal h2{margin-bottom:24px}
        .form-group{margin-bottom:16px}
        .form-group label{display:block;font-size:13px;font-weight:700;color:#444;margin-bottom:6px}
        .form-group input,.form-group textarea{width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;font-size:14px;font-family:inherit}
        .form-group textarea{resize:vertical;min-height:80px}
        .modal-actions{display:flex;gap:12px;justify-content:flex-end;margin-top:20px;padding-top:18px;border-top:1px solid #eee}
        .alert{padding:12px;border-radius:6px;margin-bottom:14px;font-size:13px}
        .alert-success{background:#d4edda;color:#155724}
        .alert-error{background:#f8d7da;color:#721c24}
    </style>
</head>
<body>
<div class="navbar">
    <h1>🏢 Gestión de Empresas</h1>
    <div>
        <a href="admin-dashboard.php">← Panel</a>
        <span style="margin-left:16px;font-size:13px"><?= htmlspecialchars($nombre) ?></span>
    </div>
</div>

<div class="container">
    <div id="alert-box"></div>

    <div class="toolbar">
        <button class="btn btn-primary" onclick="abrirModalNuevo()">+ Nueva empresa</button>
    </div>

    <div id="grid"></div>
</div>

<!-- Modal Crear / Editar -->
<div class="modal-overlay" id="modalEmpresa">
    <div class="modal">
        <h2 id="modalTitulo">Nueva Empresa</h2>
        <div id="modal-alert"></div>
        <input type="hidden" id="e-id">

        <div class="form-group">
            <label>Nombre *</label>
            <input type="text" id="e-nombre" placeholder="Nombre de la empresa">
        </div>

        <div class="form-group">
            <label>RFC</label>
            <input type="text" id="e-rfc" placeholder="RFC (13 caracteres)">
        </div>

        <div class="form-group">
            <label>Email</label>
            <input type="email" id="e-email" placeholder="contacto@empresa.com">
        </div>

        <div class="form-group">
            <label>Teléfono</label>
            <input type="tel" id="e-telefono" placeholder="+1234567890">
        </div>

        <div class="form-group">
            <label>Contacto (nombre)</label>
            <input type="text" id="e-contacto" placeholder="Nombre del contacto">
        </div>

        <div class="form-group">
            <label>Domicilio</label>
            <textarea id="e-domicilio" placeholder="Dirección completa"></textarea>
        </div>

        <div class="modal-actions">
            <button class="btn btn-warning" onclick="cerrarModal()">Cancelar</button>
            <button class="btn btn-primary" onclick="guardarEmpresa()">Guardar</button>
        </div>
    </div>
</div>

<script>
let empresas = [];

// ── Cargar ────────────────────────────────────────────────────────────────────
async function cargar() {
    const r = await fetch('../api/usuarios.php?action=listar_empresas');
    const d = await r.json();
    if (d.success) {
        empresas = d.data;
        render(empresas);
    }
}

// ── Render ────────────────────────────────────────────────────────────────────
function render(data) {
    const c = document.getElementById('grid');
    if (!data.length) {
        c.innerHTML = '<div class="empty"><div style="font-size:56px;margin-bottom:16px">🏢</div><p>Sin empresas registradas</p></div>';
        return;
    }

    c.innerHTML = `
    <div class="empresa-grid">
    ${data.map(e => `
        <div class="empresa-card">
            <h3>${htmlEscape(e.nombre)}</h3>
            <div class="empresa-info">
                ${e.rfc ? `<p><strong>RFC:</strong> ${htmlEscape(e.rfc)}</p>` : ''}
                ${e.email ? `<p><strong>Email:</strong> ${htmlEscape(e.email)}</p>` : ''}
                ${e.telefono ? `<p><strong>Teléfono:</strong> ${htmlEscape(e.telefono)}</p>` : ''}
                ${e.contacto ? `<p><strong>Contacto:</strong> ${htmlEscape(e.contacto)}</p>` : ''}
                ${e.domicilio ? `<p><strong>Domicilio:</strong> ${htmlEscape(e.domicilio)}</p>` : ''}
            </div>
            <div class="empresa-actions">
                <button class="btn btn-sm btn-warning" onclick="editarEmpresa(${e.id})">✏️ Editar</button>
                <button class="btn btn-sm btn-danger" onclick="eliminarEmpresa(${e.id})">🗑️</button>
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
    document.getElementById('modalTitulo').textContent = 'Nueva Empresa';
    document.getElementById('modalEmpresa').classList.add('open');
}

function editarEmpresa(id) {
    const e = empresas.find(x => x.id == id);
    if (!e) return;
    limpiarModal();
    document.getElementById('modalTitulo').textContent = 'Editar Empresa';
    document.getElementById('e-id').value = e.id;
    document.getElementById('e-nombre').value = e.nombre;
    document.getElementById('e-rfc').value = e.rfc || '';
    document.getElementById('e-email').value = e.email || '';
    document.getElementById('e-telefono').value = e.telefono || '';
    document.getElementById('e-contacto').value = e.contacto || '';
    document.getElementById('e-domicilio').value = e.domicilio || '';
    document.getElementById('modalEmpresa').classList.add('open');
}

function limpiarModal() {
    document.getElementById('e-id').value = '';
    document.getElementById('e-nombre').value = '';
    document.getElementById('e-rfc').value = '';
    document.getElementById('e-email').value = '';
    document.getElementById('e-telefono').value = '';
    document.getElementById('e-contacto').value = '';
    document.getElementById('e-domicilio').value = '';
    document.getElementById('modal-alert').innerHTML = '';
}

function cerrarModal() {
    document.getElementById('modalEmpresa').classList.remove('open');
}

// ── Guardar empresa ───────────────────────────────────────────────────────────
async function guardarEmpresa() {
    const id     = document.getElementById('e-id').value;
    const nombre = document.getElementById('e-nombre').value.trim();

    if (!nombre) {
        modalAlert('El nombre es requerido', 'error'); return;
    }

    const body = {
        nombre,
        rfc:      document.getElementById('e-rfc').value      || null,
        email:    document.getElementById('e-email').value    || null,
        telefono: document.getElementById('e-telefono').value || null,
        contacto: document.getElementById('e-contacto').value || null,
        domicilio: document.getElementById('e-domicilio').value || null,
    };

    // Endpoint para crear empresa (puede que no exista, lo creamos inline)
    const r = await fetch('../api/usuarios.php?action=crear_empresa', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify(body)
    });
    const d = await r.json();

    if (d.success) {
        cerrarModal();
        showAlert('Empresa guardada correctamente', 'success');
        cargar();
    } else {
        modalAlert(d.error || 'Error al guardar', 'error');
    }
}

// ── Eliminar empresa ──────────────────────────────────────────────────────────
async function eliminarEmpresa(id) {
    if (!confirm('¿Eliminar esta empresa? Se marcará como inactiva.')) return;
    // TODO: Implementar endpoint para desactivar empresa
    alert('Funcionalidad en desarrollo');
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

cargar();
</script>
</body>
</html>
