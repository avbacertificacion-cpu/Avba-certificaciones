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
    <title>Gestión de Usuarios</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Segoe UI',sans-serif;background:#f4f6fb}
        .navbar{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:16px 32px;display:flex;justify-content:space-between;align-items:center}
        .navbar a{color:#fff;text-decoration:none;font-size:13px;margin-left:16px}
        .container{max-width:1200px;margin:32px auto;padding:0 20px}
        .toolbar{display:flex;gap:12px;margin-bottom:24px;flex-wrap:wrap;align-items:center}
        .toolbar input,.toolbar select{padding:10px 14px;border:1px solid #ddd;border-radius:6px;font-size:14px;min-width:200px}
        .btn{padding:10px 20px;border:none;border-radius:8px;cursor:pointer;font-weight:700;font-size:13px;transition:.2s}
        .btn-primary{background:#667eea;color:#fff}.btn-primary:hover{background:#5568d3}
        .btn-success{background:#27ae60;color:#fff}.btn-success:hover{background:#1e8449}
        .btn-warning{background:#f39c12;color:#fff}.btn-warning:hover{background:#d68910}
        .btn-danger{background:#e74c3c;color:#fff}.btn-danger:hover{background:#c0392b}
        .btn-sm{padding:6px 12px;font-size:12px}
        table{width:100%;border-collapse:collapse;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08)}
        thead{background:#667eea;color:#fff}
        th,td{padding:13px 16px;text-align:left;font-size:13px}
        tbody tr:hover{background:#f0f3ff}
        .badge{display:inline-block;padding:4px 10px;border-radius:12px;font-size:11px;font-weight:700}
        .b-admin{background:#fce4e6;color:#c62828}
        .b-inspector{background:#e3f2fd;color:#1565c0}
        .b-cliente{background:#f3e5f5;color:#6a1b9a}
        .b-activo{background:#d4edda;color:#155724}
        .b-inactivo{background:#e2e3e5;color:#383d41}
        .empty{text-align:center;padding:50px;color:#aaa}

        /* Modal */
        .modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100;justify-content:center;align-items:center}
        .modal-overlay.open{display:flex}
        .modal{background:#fff;border-radius:12px;padding:32px;width:100%;max-width:600px;max-height:90vh;overflow-y:auto;box-shadow:0 10px 40px rgba(0,0,0,.2)}
        .modal h2{margin-bottom:24px}
        .form-row{display:grid;grid-template-columns:1fr 1fr;gap:16px}
        .form-group{margin-bottom:16px}
        .form-group label{display:block;font-size:13px;font-weight:700;color:#444;margin-bottom:6px}
        .form-group input,.form-group select{width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;font-size:14px;font-family:inherit}
        .form-group.full{grid-column:1/-1}
        .modal-actions{display:flex;gap:12px;justify-content:flex-end;margin-top:20px;padding-top:18px;border-top:1px solid #eee}
        .alert{padding:12px;border-radius:6px;margin-bottom:14px;font-size:13px}
        .alert-success{background:#d4edda;color:#155724}
        .alert-error{background:#f8d7da;color:#721c24}
    </style>
</head>
<body>
<div class="navbar">
    <h1>👥 Gestión de Usuarios</h1>
    <div>
        <a href="admin-dashboard.php">← Panel</a>
        <span style="margin-left:16px;font-size:13px"><?= htmlspecialchars($nombre) ?></span>
    </div>
</div>

<div class="container">
    <div id="alert-box"></div>

    <div class="toolbar">
        <input type="text" id="busqueda" placeholder="Buscar por nombre o email…" oninput="filtrar()">
        <select id="filtroRol" onchange="filtrar()">
            <option value="">Todos los roles</option>
            <option value="administrador">Administrador</option>
            <option value="inspector">Inspector</option>
            <option value="cliente">Cliente</option>
        </select>
        <select id="filtroEstado" onchange="filtrar()">
            <option value="">Todos los estados</option>
            <option value="activo">Activo</option>
            <option value="inactivo">Inactivo</option>
        </select>
        <button class="btn btn-primary" onclick="abrirModalNuevo()">+ Nuevo usuario</button>
    </div>

    <div id="tabla"></div>
</div>

<!-- Modal Crear / Editar -->
<div class="modal-overlay" id="modalUsuario">
    <div class="modal">
        <h2 id="modalTitulo">Nuevo Usuario</h2>
        <div id="modal-alert"></div>
        <input type="hidden" id="u-id">

        <div class="form-row">
            <div class="form-group">
                <label>Nombre completo *</label>
                <input type="text" id="u-nombre" placeholder="Ej: Juan Pérez">
            </div>
            <div class="form-group">
                <label>Email *</label>
                <input type="email" id="u-email" placeholder="juan@empresa.com">
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Rol *</label>
                <select id="u-rol" onchange="actualizarVisibilidadEmpresa()">
                    <option value="administrador">Administrador</option>
                    <option value="inspector">Inspector</option>
                    <option value="cliente">Cliente</option>
                </select>
            </div>
            <div class="form-group" id="empresa-group" style="display:none">
                <label>Empresa (para Inspectores y Clientes)</label>
                <select id="u-empresa"></select>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Contraseña <span id="pwd-requerida">*</span></label>
                <input type="password" id="u-password" placeholder="Mínimo 8 caracteres">
                <small style="color:#888;font-size:11px" id="pwd-nota"></small>
            </div>
            <div class="form-group">
                <label>Estado</label>
                <select id="u-estado">
                    <option value="activo">Activo</option>
                    <option value="inactivo">Inactivo</option>
                </select>
            </div>
        </div>

        <div class="modal-actions">
            <button class="btn btn-warning" onclick="cerrarModal()">Cancelar</button>
            <button class="btn btn-primary" onclick="guardarUsuario()">Guardar</button>
        </div>
    </div>
</div>

<script>
let usuarios = [];
let empresas  = [];

// ── Cargar datos ──────────────────────────────────────────────────────────────
async function init() {
    await Promise.all([cargarEmpresas(), cargarUsuarios()]);
}

async function cargarEmpresas() {
    const r = await fetch('../api/usuarios.php?action=listar_empresas');
    const d = await r.json();
    if (d.success) {
        empresas = d.data;
        const sel = document.getElementById('u-empresa');
        sel.innerHTML = '<option value="">Sin asignar</option>' +
            empresas.map(e => `<option value="${e.id}">${e.nombre}</option>`).join('');
    }
}

async function cargarUsuarios() {
    const r = await fetch('../api/usuarios.php?action=listar');
    const d = await r.json();
    if (d.success) {
        usuarios = d.data;
        render(usuarios);
    }
}

// ── Render tabla ──────────────────────────────────────────────────────────────
function render(data) {
    const c = document.getElementById('tabla');
    if (!data.length) {
        c.innerHTML = '<div class="empty">No hay usuarios. Crea el primero.</div>'; return;
    }

    c.innerHTML = `
    <table>
        <thead><tr>
            <th>Nombre</th><th>Email</th><th>Rol</th><th>Empresa</th><th>Estado</th><th>Acciones</th>
        </tr></thead>
        <tbody>
        ${data.map(u => `<tr>
            <td><strong>${htmlEscape(u.nombre)}</strong></td>
            <td>${htmlEscape(u.email)}</td>
            <td><span class="badge b-${u.rol}">${rolLabel(u.rol)}</span></td>
            <td>${u.empresa_nombre || '—'}</td>
            <td><span class="badge b-${u.estado}">${estadoLabel(u.estado)}</span></td>
            <td style="white-space:nowrap">
                <button class="btn btn-sm btn-warning" onclick="editarUsuario(${u.id})">✏️</button>
                <button class="btn btn-sm btn-danger" onclick="eliminarUsuario(${u.id})">🗑️</button>
            </td>
        </tr>`).join('')}
        </tbody>
    </table>`;
}

function rolLabel(r) {
    return {administrador:'Admin', inspector:'Inspector', cliente:'Cliente'}[r] ?? r;
}
function estadoLabel(e) {
    return {activo:'Activo', inactivo:'Inactivo'}[e] ?? e;
}
function htmlEscape(s) {
    const p = document.createElement('p');
    p.textContent = s;
    return p.innerHTML;
}

// ── Filtrar ───────────────────────────────────────────────────────────────────
function filtrar() {
    const q = document.getElementById('busqueda').value.toLowerCase();
    const r = document.getElementById('filtroRol').value;
    const e = document.getElementById('filtroEstado').value;

    render(usuarios.filter(u =>
        (!q || u.nombre.toLowerCase().includes(q) || u.email.toLowerCase().includes(q)) &&
        (!r || u.rol === r) &&
        (!e || u.estado === e)
    ));
}

// ── Modal ─────────────────────────────────────────────────────────────────────
function abrirModalNuevo() {
    limpiarModal();
    document.getElementById('modalTitulo').textContent = 'Nuevo Usuario';
    document.getElementById('u-password').value = '';
    document.getElementById('pwd-requerida').textContent = '*';
    document.getElementById('pwd-nota').textContent = '';
    document.getElementById('modalUsuario').classList.add('open');
}

function editarUsuario(id) {
    const u = usuarios.find(x => x.id == id);
    if (!u) return;
    limpiarModal();
    document.getElementById('modalTitulo').textContent = 'Editar Usuario';
    document.getElementById('u-id').value = u.id;
    document.getElementById('u-nombre').value = u.nombre;
    document.getElementById('u-email').value = u.email;
    document.getElementById('u-rol').value = u.rol;
    document.getElementById('u-empresa').value = u.empresa_id || '';
    document.getElementById('u-estado').value = u.estado;
    document.getElementById('u-password').value = '';
    document.getElementById('pwd-requerida').textContent = '';
    document.getElementById('pwd-nota').textContent = 'Dejar vacío para no cambiar';
    actualizarVisibilidadEmpresa();
    document.getElementById('modalUsuario').classList.add('open');
}

function limpiarModal() {
    document.getElementById('u-id').value = '';
    document.getElementById('u-nombre').value = '';
    document.getElementById('u-email').value = '';
    document.getElementById('u-rol').value = 'administrador';
    document.getElementById('u-empresa').value = '';
    document.getElementById('u-password').value = '';
    document.getElementById('u-estado').value = 'activo';
    document.getElementById('modal-alert').innerHTML = '';
}

function cerrarModal() {
    document.getElementById('modalUsuario').classList.remove('open');
}

function actualizarVisibilidadEmpresa() {
    const rol = document.getElementById('u-rol').value;
    const grupo = document.getElementById('empresa-group');
    grupo.style.display = (rol !== 'administrador') ? 'block' : 'none';
}

// ── Guardar usuario ───────────────────────────────────────────────────────────
async function guardarUsuario() {
    const id       = document.getElementById('u-id').value;
    const nombre   = document.getElementById('u-nombre').value.trim();
    const email    = document.getElementById('u-email').value.trim();
    const rol      = document.getElementById('u-rol').value;
    const empresa  = document.getElementById('u-empresa').value;
    const password = document.getElementById('u-password').value;
    const estado   = document.getElementById('u-estado').value;

    if (!nombre || !email) {
        modalAlert('Nombre y email son requeridos', 'error'); return;
    }

    if (!id && !password) {
        modalAlert('La contraseña es requerida para usuarios nuevos', 'error'); return;
    }

    if (password && password.length < 8) {
        modalAlert('La contraseña debe tener mínimo 8 caracteres', 'error'); return;
    }

    const body = { nombre, email, rol, estado };
    if (rol !== 'administrador') body.empresa_id = empresa || null;
    if (password) body.password = password;
    if (id) body.id = parseInt(id);

    const action = id ? 'editar' : 'crear';
    const r = await fetch(`../api/usuarios.php?action=${action}`, {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify(body)
    });
    const d = await r.json();

    if (d.success) {
        cerrarModal();
        showAlert(`Usuario ${id ? 'actualizado' : 'creado'} correctamente`, 'success');
        cargarUsuarios();
    } else {
        modalAlert(d.error || 'Error al guardar', 'error');
    }
}

// ── Eliminar usuario ──────────────────────────────────────────────────────────
async function eliminarUsuario(id) {
    if (!confirm('¿Desactivar este usuario? Se marcará como inactivo.')) return;

    const r = await fetch(`../api/usuarios.php?action=eliminar&id=${id}`);
    const d = await r.json();

    if (d.success) {
        showAlert('Usuario desactivado', 'success');
        cargarUsuarios();
    } else {
        showAlert(d.error || 'Error', 'error');
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
