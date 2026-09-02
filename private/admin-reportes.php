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
    <title>Reportes Mensuales – Admin</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Segoe UI',sans-serif;background:#f4f6fb}
        .navbar{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:16px 32px;display:flex;justify-content:space-between;align-items:center}
        .navbar a{color:#fff;text-decoration:none;font-size:13px;margin-left:16px}
        .container{max-width:1100px;margin:32px auto;padding:0 20px}
        .toolbar{display:flex;gap:12px;margin-bottom:24px;flex-wrap:wrap;align-items:center}
        .toolbar select{padding:10px 14px;border:1px solid #ddd;border-radius:6px;font-size:14px}
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
        .b-borrador{background:#e2e3e5;color:#383d41}
        .b-generado{background:#d1ecf1;color:#0c5460}
        .b-publicado{background:#d4edda;color:#155724}
        .empty{text-align:center;padding:50px;color:#aaa}

        /* Modal */
        .modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100;justify-content:center;align-items:center}
        .modal-overlay.open{display:flex}
        .modal{background:#fff;border-radius:12px;padding:32px;width:100%;max-width:500px;box-shadow:0 10px 40px rgba(0,0,0,.2)}
        .modal h2{margin-bottom:24px}
        .form-group{margin-bottom:18px}
        .form-group label{display:block;font-size:13px;font-weight:700;color:#444;margin-bottom:6px}
        .form-group select,.form-group input,.form-group textarea{width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;font-size:14px;font-family:inherit}
        .form-group textarea{resize:vertical;min-height:80px}
        .form-row{display:grid;grid-template-columns:1fr 1fr;gap:16px}
        .modal-actions{display:flex;gap:12px;justify-content:flex-end;margin-top:20px;padding-top:18px;border-top:1px solid #eee}
        .alert{padding:12px;border-radius:6px;margin-bottom:14px;font-size:13px}
        .alert-success{background:#d4edda;color:#155724}
        .alert-error{background:#f8d7da;color:#721c24}

        .info-publicado{background:#d4edda;border:1px solid #c3e6cb;border-radius:8px;padding:12px 16px;font-size:13px;color:#155724;margin-bottom:20px}
    </style>
</head>
<body>
<div class="navbar">
    <h1>📊 Reportes Mensuales</h1>
    <div>
        <a href="admin-dashboard.php">← Panel</a>
        <span style="margin-left:16px;font-size:13px"><?= htmlspecialchars($nombre) ?></span>
    </div>
</div>

<div class="container">
    <div id="alert-box"></div>

    <div class="info-publicado">
        💡 Solo los reportes con estado <strong>Publicado</strong> son visibles para el cliente.
        Los reportes en estado Borrador o Generado son privados.
    </div>

    <div class="toolbar">
        <select id="filtroEmpresa" onchange="cargar()">
            <option value="">Todas las empresas</option>
        </select>
        <button class="btn btn-primary" onclick="abrirModal()">+ Nuevo reporte</button>
    </div>

    <div id="tabla"></div>
</div>

<!-- Modal de fotografías del reporte -->
<div class="modal-overlay" id="modalFotos">
    <div class="modal" style="max-width:640px">
        <h2 style="margin-bottom:6px">📷 Evidencia fotográfica</h2>
        <p style="color:#666;font-size:13px;margin-bottom:16px" id="fotos-titulo"></p>
        <div id="fotos-alert"></div>
        <div class="form-group">
            <label>Agregar fotografías <span style="font-weight:400;color:#666">(máximo 9 en total)</span></label>
            <input type="file" id="f-archivos" accept="image/*" multiple>
            <button class="btn btn-primary" style="margin-top:10px" onclick="agregarFotos()">⬆️ Subir</button>
        </div>
        <div id="fotos-lista"></div>
        <div class="modal-actions">
            <button class="btn btn-warning" onclick="cerrarFotos()">Cerrar</button>
        </div>
    </div>
</div>

<!-- Modal crear reporte -->
<div class="modal-overlay" id="modalReporte">
    <div class="modal">
        <h2>Nuevo Reporte Mensual</h2>
        <div id="modal-alert"></div>
        <div class="form-group">
            <label>Empresa *</label>
            <select id="r-empresa"></select>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Mes *</label>
                <select id="r-mes">
                    <?php
                    $meses = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio',
                              'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
                    for ($m = 1; $m <= 12; $m++) {
                        $sel = $m == date('n') ? 'selected' : '';
                        echo "<option value=\"$m\" $sel>{$meses[$m]}</option>";
                    }
                    ?>
                </select>
            </div>
            <div class="form-group">
                <label>Año *</label>
                <input type="number" id="r-anio" value="<?= date('Y') ?>" min="2020" max="2099">
            </div>
        </div>
        <div class="form-group">
            <label>Inspector Responsable *</label>
            <select id="r-inspector"></select>
        </div>
        <div class="form-group">
            <label>Ubicación</label>
            <input type="text" id="r-obs" placeholder="Ej: EAA, PLANTA NORTE…" style="text-transform:uppercase">
        </div>
        <div class="form-group" id="bloque-fotos" style="display:none;background:#f7f9fc;border:1.5px solid #e0e0ff;border-radius:8px;padding:14px">
            <label>📷 Evidencia fotográfica <span style="font-weight:400;color:#666">(máximo 9)</span></label>
            <input type="file" id="r-fotos" accept="image/*" multiple>
            <small style="display:block;margin-top:6px;color:#666" id="fotos-nota">
                Esta planta tiene activado el módulo de evidencia fotográfica.
                Las fotos se incluirán al final del reporte.
            </small>
        </div>
        <div class="modal-actions">
            <button class="btn btn-warning" onclick="cerrarModal()">Cancelar</button>
            <button class="btn btn-primary" onclick="crearReporte()">Crear</button>
        </div>
    </div>
</div>

<script>
const meses = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio',
               'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];

async function init() {
    await cargarEmpresas();
    await cargarInspectores();
    cargar();
}

let empresas = [];

async function cargarEmpresas() {
    const r = await fetch('../api/usuarios.php?action=listar_empresas');
    const d = await r.json();
    if (!d.success) return;
    empresas = d.data;
    const sel1 = document.getElementById('filtroEmpresa');
    const sel2 = document.getElementById('r-empresa');
    d.data.forEach(e => {
        sel1.innerHTML += `<option value="${e.id}">${e.nombre}</option>`;
        sel2.innerHTML += `<option value="${e.id}">${e.nombre}</option>`;
    });
    sel2.addEventListener('change', revisarModuloFotos);
    revisarModuloFotos();
}

/** El bloque de fotos sólo aparece si la planta elegida tiene el módulo activo. */
function revisarModuloFotos() {
    const id = document.getElementById('r-empresa').value;
    const emp = empresas.find(e => String(e.id) === String(id));
    const pide = emp && Number(emp.requiere_fotos) === 1;
    document.getElementById('bloque-fotos').style.display = pide ? '' : 'none';
    if (!pide) document.getElementById('r-fotos').value = '';
}

async function cargarInspectores() {
    const r = await fetch('../api/usuarios.php?action=listar_inspectores');
    const d = await r.json();
    if (!d.success) return;
    const sel = document.getElementById('r-inspector');
    sel.innerHTML = '<option value="">-- Selecciona un inspector --</option>';
    d.data.forEach(u => {
        sel.innerHTML += `<option value="${u.id}">${u.nombre}</option>`;
    });
}

async function cargar() {
    const emp = document.getElementById('filtroEmpresa').value;
    const url = '../api/reportes_mensuales.php?action=listar' + (emp ? `&empresa_id=${emp}` : '');
    const r   = await fetch(url);
    const d   = await r.json();

    const c = document.getElementById('tabla');
    if (!d.success || !d.data.length) {
        c.innerHTML = '<div class="empty">No hay reportes. Crea el primero.</div>'; return;
    }

    c.innerHTML = `
    <table>
        <thead><tr>
            <th>Número</th><th>Empresa</th><th>Período</th>
            <th>Estado</th><th>Acciones</th>
        </tr></thead>
        <tbody>
        ${d.data.map(rep => `<tr>
            <td><strong>${rep.numero_reporte}</strong></td>
            <td>${rep.empresa_nombre}</td>
            <td>${meses[rep.mes]} ${rep.anio}</td>
            <td><span class="badge b-${rep.estado}">${estadoLabel(rep.estado)}</span></td>
            <td style="white-space:nowrap">
                ${rep.estado !== 'publicado'
                    ? `<button class="btn btn-sm btn-success" onclick="publicar(${rep.id})">✅ Publicar</button>`
                    : `<button class="btn btn-sm btn-warning" onclick="despublicar(${rep.id})">🔒 Ocultar</button>`
                }
                ${plantaPideFotos(rep.empresa_id) ? `<button class="btn btn-sm" style="background:#eef2fb;color:#475569" onclick="abrirFotos(${rep.id}, '${String(rep.numero_reporte).replace(/'/g, "\\'")}')">📷 Fotos</button>` : ''}
                <button class="btn btn-sm btn-primary" onclick="verPDF(${rep.id})">📄 PDF</button>
                <button class="btn btn-sm btn-danger" onclick="eliminar(${rep.id})">🗑️</button>
            </td>
        </tr>`).join('')}
        </tbody>
    </table>`;
}

function estadoLabel(s) {
    return {borrador:'Borrador', generado:'Generado', publicado:'Publicado ✓'}[s] ?? s;
}

// ── Publicar / Despublicar ────────────────────────────────────────────────────
async function publicar(id) {
    if (!confirm('¿Publicar este reporte? El cliente podrá verlo y descargarlo.')) return;
    const r = await fetch(`../api/reportes_mensuales.php?action=publicar&id=${id}`);
    const d = await r.json();
    if (d.success) { showAlert('Reporte publicado – visible para el cliente', 'success'); cargar(); }
    else showAlert(d.error, 'error');
}

async function despublicar(id) {
    if (!confirm('¿Ocultar este reporte? El cliente dejará de verlo.')) return;
    const r = await fetch(`../api/reportes_mensuales.php?action=despublicar&id=${id}`);
    const d = await r.json();
    if (d.success) { showAlert('Reporte ocultado', 'success'); cargar(); }
    else showAlert(d.error, 'error');
}

async function eliminar(id) {
    if (!confirm('¿Eliminar este reporte definitivamente?')) return;
    const r = await fetch(`../api/reportes_mensuales.php?action=eliminar&id=${id}`);
    const d = await r.json();
    if (d.success) { showAlert('Reporte eliminado', 'success'); cargar(); }
    else showAlert(d.error, 'error');
}

function verPDF(id) {
    window.open(`../api/reporte_pdf.php?reporte_id=${id}&preview=1`, '_blank');
}

// ── Modal ─────────────────────────────────────────────────────────────────────
/** Sube las fotos elegidas en el modal al reporte recién creado. Devuelve cuántas entraron. */
async function subirFotosDelModal(reporteId) {
    const input = document.getElementById('r-fotos');
    if (document.getElementById('bloque-fotos').style.display === 'none') return 0;
    const archivos = Array.from(input.files).slice(0, 9);
    if (!archivos.length) return 0;

    let ok = 0, fallos = [];
    for (const f of archivos) {
        const fd = new FormData();
        fd.append('foto', f);
        fd.append('reporte_id', reporteId);
        try {
            const r = await fetch('../api/reporte_fotos.php?action=subir', { method: 'POST', body: fd });
            const d = await r.json();
            if (r.ok && d.success) ok++; else fallos.push(`${f.name}: ${d.error || 'error'}`);
        } catch (e) { fallos.push(`${f.name}: no se pudo enviar`); }
    }
    input.value = '';
    // Si alguna falló se avisa, pero el reporte ya quedó creado: se pueden agregar
    // después con el botón 📷 del listado.
    if (fallos.length) showAlert('Algunas fotos no se subieron — ' + fallos.join('; '), 'error');
    return ok;
}

// ── Gestión de fotos de un reporte ya creado ────────────────────────────────
let fotosReporteId = null;

function plantaPideFotos(empresaId) {
    const e = empresas.find(x => String(x.id) === String(empresaId));
    return e && Number(e.requiere_fotos) === 1;
}

function abrirFotos(id, numero) {
    fotosReporteId = id;
    document.getElementById('fotos-titulo').textContent = 'Reporte ' + numero;
    document.getElementById('fotos-alert').innerHTML = '';
    document.getElementById('f-archivos').value = '';
    document.getElementById('modalFotos').classList.add('open');
    cargarFotos();
}
function cerrarFotos() { document.getElementById('modalFotos').classList.remove('open'); }

async function cargarFotos() {
    const cont = document.getElementById('fotos-lista');
    cont.innerHTML = '<p style="color:#888;font-size:13px">Cargando…</p>';
    const r = await fetch(`../api/reporte_fotos.php?action=listar&reporte_id=${fotosReporteId}`);
    const d = await r.json();
    if (!r.ok || !d.success) { cont.innerHTML = '<p style="color:#c0392b;font-size:13px">No se pudo cargar.</p>'; return; }

    if (!d.data.length) {
        cont.innerHTML = '<p style="color:#888;font-size:13px">Todavía no hay fotografías en este reporte.</p>';
        return;
    }
    cont.innerHTML = `
        <p style="font-size:12px;color:#666;margin-bottom:8px">${d.data.length} de ${d.maximo} fotografías</p>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px">
        ${d.data.map((f, i) => `
            <div style="border:1px solid #e8eeff;border-radius:8px;overflow:hidden;background:#fafbff">
                <img src="../uploads/reportes/${f.archivo}" style="width:100%;height:90px;object-fit:cover;display:block">
                <div style="display:flex;justify-content:space-between;align-items:center;padding:5px 7px">
                    <span style="font-size:11px;color:#666">Foto ${i + 1}</span>
                    <button onclick="borrarFoto(${f.id})" title="Eliminar"
                        style="background:#fee2e2;color:#b91c1c;border:none;border-radius:5px;padding:3px 7px;cursor:pointer;font-size:11px;font-weight:700">🗑️</button>
                </div>
            </div>`).join('')}
        </div>`;
}

async function agregarFotos() {
    const input = document.getElementById('f-archivos');
    const archivos = Array.from(input.files);
    if (!archivos.length) return;

    let fallos = [];
    for (const f of archivos) {
        const fd = new FormData();
        fd.append('foto', f);
        fd.append('reporte_id', fotosReporteId);
        const r = await fetch('../api/reporte_fotos.php?action=subir', { method: 'POST', body: fd });
        const d = await r.json().catch(() => ({}));
        if (!r.ok || !d.success) fallos.push(`${f.name}: ${d.error || 'error'}`);
    }
    input.value = '';
    document.getElementById('fotos-alert').innerHTML = fallos.length
        ? `<div class="alert alert-error">${fallos.join('<br>')}</div>` : '';
    cargarFotos();
}

async function borrarFoto(id) {
    if (!confirm('¿Eliminar esta fotografía del reporte?')) return;
    const r = await fetch(`../api/reporte_fotos.php?action=eliminar&id=${id}`);
    const d = await r.json().catch(() => ({}));
    if (!r.ok || !d.success) {
        document.getElementById('fotos-alert').innerHTML =
            `<div class="alert alert-error">${d.error || 'No se pudo eliminar'}</div>`;
    }
    cargarFotos();
}

function abrirModal() { document.getElementById('modalReporte').classList.add('open'); }
function cerrarModal() { document.getElementById('modalReporte').classList.remove('open'); }

async function crearReporte() {
    const body = {
        empresa_id:    parseInt(document.getElementById('r-empresa').value),
        inspector_id:  parseInt(document.getElementById('r-inspector').value),
        mes:           parseInt(document.getElementById('r-mes').value),
        anio:          parseInt(document.getElementById('r-anio').value),
        observaciones: document.getElementById('r-obs').value || null,
    };
    if (!body.empresa_id) {
        document.getElementById('modal-alert').innerHTML =
            '<div class="alert alert-error">Selecciona una empresa</div>'; return;
    }
    if (!body.inspector_id) {
        document.getElementById('modal-alert').innerHTML =
            '<div class="alert alert-error">Selecciona un inspector responsable</div>'; return;
    }
    const archivos = document.getElementById('r-fotos').files;
    if (document.getElementById('bloque-fotos').style.display !== 'none' && archivos.length > 9) {
        document.getElementById('modal-alert').innerHTML =
            `<div class="alert alert-error">Máximo 9 fotografías (elegiste ${archivos.length})</div>`; return;
    }

    const r = await fetch('../api/reportes_mensuales.php?action=crear', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify(body)
    });
    const d = await r.json();
    if (d.success) {
        const subidas = await subirFotosDelModal(d.id);
        cerrarModal();
        showAlert(
            `Reporte ${d.numero_reporte} creado${subidas ? ` con ${subidas} fotografía${subidas === 1 ? '' : 's'}` : ''}. ` +
            `Publícalo cuando esté listo.`, 'success');
        cargar();
    } else {
        document.getElementById('modal-alert').innerHTML =
            `<div class="alert alert-error">${d.error}</div>`;
    }
}

// ── Alertas ───────────────────────────────────────────────────────────────────
function showAlert(msg, tipo) {
    const b = document.getElementById('alert-box');
    b.innerHTML = `<div class="alert alert-${tipo}" style="padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:14px">${msg}</div>`;
    setTimeout(() => b.innerHTML = '', 5000);
}

init();
</script>
</body>
</html>
