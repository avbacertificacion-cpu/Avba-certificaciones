<?php
require_once '../config/config.php';
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== ROLE_CLIENTE) {
    header('Location: ../public/login.html'); exit;
}
$nombre = $_SESSION['nombre'];
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
        html{scroll-behavior:smooth}
        body{font-family:'Segoe UI',sans-serif;background:#f0f4ff;color:#333}

        /* Navbar */
        .navbar{background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:#fff;padding:16px 20px;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;z-index:50;box-shadow:0 4px 12px rgba(102,126,234,.15)}
        .navbar h1{font-size:20px;font-weight:700}
        .navbar a{color:#fff;text-decoration:none;font-size:13px;transition:.2s;margin-left:16px}
        .navbar a:hover{opacity:.8}
        .user-info{display:flex;align-items:center;gap:12px;font-size:13px}

        /* Container */
        .container{max-width:1400px;margin:0 auto;padding:24px 16px}

        /* KPI Cards */
        .kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:32px}
        .kpi-card{background:#fff;border-radius:12px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,.06);border-left:5px solid #667eea;transition:.2s}
        .kpi-card:hover{transform:translateY(-4px);box-shadow:0 8px 20px rgba(0,0,0,.1)}
        .kpi-card.warning{border-left-color:#f39c12}
        .kpi-card.danger{border-left-color:#e74c3c}
        .kpi-card.success{border-left-color:#27ae60}
        .kpi-icon{font-size:28px;margin-bottom:8px}
        .kpi-label{font-size:12px;color:#888;text-transform:uppercase;font-weight:700;margin-bottom:4px}
        .kpi-value{font-size:32px;font-weight:700;color:#667eea}
        .kpi-card.warning .kpi-value{color:#f39c12}
        .kpi-card.danger .kpi-value{color:#e74c3c}
        .kpi-card.success .kpi-value{color:#27ae60}

        /* Toolbar */
        .toolbar{display:flex;gap:12px;margin-bottom:24px;flex-wrap:wrap;align-items:center}
        .toolbar input, .toolbar select{padding:10px 14px;border:1px solid #ddd;border-radius:8px;font-size:13px;background:#fff;cursor:pointer}
        .toolbar input::placeholder{color:#999}

        /* Grid de Tarjetas */
        .extintores-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:20px;margin-bottom:32px}
        .extintor-card{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow:hidden;transition:.2s;cursor:pointer}
        .extintor-card:hover{transform:translateY(-6px);box-shadow:0 12px 24px rgba(0,0,0,.15)}

        .card-header{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:16px;display:flex;justify-content:space-between;align-items:flex-start}
        .card-codigo{font-size:18px;font-weight:700}
        .card-tipo-badge{background:rgba(255,255,255,.3);padding:4px 10px;border-radius:20px;font-size:10px;font-weight:700}

        .card-body{padding:16px}
        .card-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;padding-bottom:12px;border-bottom:1px solid #eee}
        .card-row:last-child{border-bottom:none;margin-bottom:0;padding-bottom:0}
        .card-label{font-size:11px;color:#888;text-transform:uppercase;font-weight:700}
        .card-value{font-size:13px;font-weight:600;color:#333}

        .ubicacion-box{background:#f0f3ff;padding:10px;border-radius:6px;margin:12px 0;font-size:12px;color:#555;text-align:center;font-weight:600}

        .inspeccion-badge{display:inline-block;padding:6px 10px;border-radius:6px;font-size:11px;font-weight:700;margin-top:8px}
        .insp-ok{background:#d4edda;color:#155724}
        .insp-warning{background:#fff3cd;color:#856404}
        .insp-danger{background:#f8d7da;color:#721c24}
        .insp-none{background:#e2e3e5;color:#383d41}

        .estado-badge{display:inline-block;padding:4px 8px;border-radius:4px;font-size:10px;font-weight:700}
        .estado-activo{background:#d4edda;color:#155724}
        .estado-inactivo{background:#e2e3e5;color:#383d41}
        .estado-prestamo{background:#d1ecf1;color:#0c5460}

        /* Modal */
        .modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:100;justify-content:center;align-items:center;padding:16px}
        .modal-overlay.open{display:flex}
        .modal{background:#fff;border-radius:16px;width:100%;max-width:600px;box-shadow:0 20px 60px rgba(0,0,0,.3);animation:slideUp .3s ease}
        @keyframes slideUp{from{transform:translateY(40px);opacity:0}to{transform:translateY(0);opacity:1}}

        .modal-header{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:24px;border-radius:16px 16px 0 0;display:flex;justify-content:space-between;align-items:center}
        .modal-header h2{font-size:20px}
        .modal-close{background:rgba(255,255,255,.3);border:none;color:#fff;width:32px;height:32px;border-radius:50%;cursor:pointer;font-size:18px;display:flex;align-items:center;justify-content:center}
        .modal-close:hover{background:rgba(255,255,255,.5)}

        .modal-body{padding:24px;max-height:70vh;overflow-y:auto}

        .modal-section{margin-bottom:24px}
        .modal-section-title{font-size:14px;font-weight:700;color:#667eea;text-transform:uppercase;margin-bottom:12px;border-bottom:2px solid #f0f3ff;padding-bottom:8px}

        .modal-row{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:12px}
        .modal-row.full{grid-template-columns:1fr}
        .modal-item label{font-size:11px;color:#888;text-transform:uppercase;font-weight:700;display:block;margin-bottom:4px}
        .modal-item value{font-size:14px;color:#333;font-weight:600;display:block}

        .empty{text-align:center;padding:60px 20px;background:#fff;border-radius:12px;color:#aaa}
        .empty-icon{font-size:64px;margin-bottom:16px}

        @media(max-width:768px){
            .navbar h1{font-size:18px}
            .kpi-grid{grid-template-columns:repeat(2,1fr)}
            .extintores-grid{grid-template-columns:1fr}
            .toolbar{flex-direction:column}
            .toolbar input, .toolbar select{width:100%}
            .modal{max-width:90%}
        }
    </style>
</head>
<body>

<div class="navbar">
    <h1>🧯 Mis Extintores</h1>
    <div>
        <a href="cliente-dashboard.php">← Panel</a>
        <span class="user-info">👤 <?= htmlspecialchars($nombre) ?></span>
    </div>
</div>

<div class="container">
    <div class="kpi-grid" id="kpiGrid"></div>

    <div class="toolbar">
        <input type="text" id="busqueda" placeholder="🔍 Buscar por código o ubicación...">
        <select id="filtroTipo">
            <option value="">Todos los tipos</option>
        </select>
        <select id="filtroEstado">
            <option value="">Todos los estados</option>
            <option value="activo">Activo</option>
            <option value="inactivo">Inactivo</option>
            <option value="en_prestamo">En Préstamo</option>
        </select>
    </div>

    <div class="extintores-grid" id="grid"></div>
</div>

<div class="modal-overlay" id="modalDetalle" onclick="if(event.target===this)cerrarModal()">
    <div class="modal">
        <div class="modal-header">
            <h2 id="modalTitulo">Detalles del Extintor</h2>
            <button class="modal-close" onclick="cerrarModal()">✕</button>
        </div>
        <div class="modal-body" id="modalContenido"></div>
    </div>
</div>

<script>
let extintores = [];
let kpis = {};

async function cargar() {
    const r = await fetch(`../api/extintores.php?action=listar&empresa_id=<?= $empresa_id ?>`);
    const d = await r.json();

    if (!d.success) {
        document.getElementById('grid').innerHTML = '<div class="empty"><div class="empty-icon">❌</div><p>Error al cargar extintores</p></div>';
        return;
    }

    extintores = d.data || [];
    poblarFiltroTipo();
    calcularKPIs();
    renderizar();
}

function poblarFiltroTipo() {
    const tipos = [...new Set(extintores.map(e => e.tipo_nombre).filter(Boolean))].sort();
    const sel = document.getElementById('filtroTipo');
    sel.innerHTML = '<option value="">Todos los tipos</option>' +
        tipos.map(t => `<option value="${t}">${t}</option>`).join('');
}

function calcularKPIs() {
    kpis = {
        total: extintores.length,
        activos: extintores.filter(e => e.estado === 'activo').length,
        inspeccionados: 0,
        sinInspeccionar: 0,
        vencidos: 0,
        proximos: 0,
    };

    const hoy = new Date();

    extintores.forEach(e => {
        if (e.ultima_inspeccion) {
            kpis.inspeccionados++;
            const ultimaInsp = new Date(e.ultima_inspeccion + ' 00:00:00');
            const proxVenc = new Date(ultimaInsp);
            proxVenc.setDate(proxVenc.getDate() + 30);

            if (proxVenc < hoy) {
                kpis.vencidos++;
            } else if (proxVenc < new Date(hoy.getTime() + 7 * 24 * 60 * 60 * 1000)) {
                kpis.proximos++;
            }
        } else {
            kpis.sinInspeccionar++;
        }
    });

    renderKPIs();
}

function renderKPIs() {
    const html = `
        <div class="kpi-card">
            <div class="kpi-icon">🧯</div>
            <div class="kpi-label">Total Extintores</div>
            <div class="kpi-value">${kpis.total}</div>
        </div>
        <div class="kpi-card success">
            <div class="kpi-icon">✅</div>
            <div class="kpi-label">Activos</div>
            <div class="kpi-value">${kpis.activos}</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon">📋</div>
            <div class="kpi-label">Inspeccionados</div>
            <div class="kpi-value">${kpis.inspeccionados}</div>
        </div>
        <div class="kpi-card warning">
            <div class="kpi-icon">⏰</div>
            <div class="kpi-label">Próx. Vencimiento</div>
            <div class="kpi-value">${kpis.proximos}</div>
        </div>
        <div class="kpi-card danger">
            <div class="kpi-icon">⚠️</div>
            <div class="kpi-label">Vencidos</div>
            <div class="kpi-value">${kpis.vencidos}</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon">🔍</div>
            <div class="kpi-label">Sin Inspección</div>
            <div class="kpi-value">${kpis.sinInspeccionar}</div>
        </div>
    `;
    document.getElementById('kpiGrid').innerHTML = html;
}

function renderizar() {
    const busqueda = document.getElementById('busqueda').value.toLowerCase();
    const filtroTipo = document.getElementById('filtroTipo').value;
    const filtroEstado = document.getElementById('filtroEstado').value;

    const filtrados = extintores.filter(e => {
        const coincideBusqueda = !busqueda ||
            e.codigo_manual.toLowerCase().includes(busqueda) ||
            e.ubicacion.toLowerCase().includes(busqueda);
        const coincideTipo = !filtroTipo || e.tipo_nombre === filtroTipo;
        const coincideEstado = !filtroEstado || e.estado === filtroEstado;
        return coincideBusqueda && coincideTipo && coincideEstado;
    });

    if (!filtrados.length) {
        document.getElementById('grid').innerHTML = '<div class="empty"><div class="empty-icon">🔍</div><p>No hay extintores que coincidan con tu búsqueda</p></div>';
        return;
    }

    const html = filtrados.map(e => {
        const hoy = new Date();
        const ultimaInsp = e.ultima_inspeccion ? new Date(e.ultima_inspeccion + ' 00:00:00') : null;
        const proxVenc = ultimaInsp ? new Date(ultimaInsp.getTime() + 30 * 24 * 60 * 60 * 1000) : null;

        let inspBadge = '<span class="inspeccion-badge insp-none">Sin inspección</span>';
        if (ultimaInsp) {
            if (proxVenc < hoy) {
                inspBadge = '<span class="inspeccion-badge insp-danger">Vencido</span>';
            } else if (proxVenc < new Date(hoy.getTime() + 7 * 24 * 60 * 60 * 1000)) {
                inspBadge = '<span class="inspeccion-badge insp-warning">Próx. vencimiento</span>';
            } else {
                inspBadge = '<span class="inspeccion-badge insp-ok">Vigente</span>';
            }
        }

        return `
            <div class="extintor-card" onclick="abrirDetalle(${e.id})">
                <div class="card-header">
                    <div>
                        <div class="card-codigo">${e.codigo_manual}</div>
                        <div class="card-tipo-badge">${e.tipo_nombre}</div>
                    </div>
                    <span class="estado-badge estado-${e.estado}">${e.estado.toUpperCase()}</span>
                </div>
                <div class="card-body">
                    <div class="ubicacion-box">📍 ${e.ubicacion}</div>
                    <div class="card-row">
                        <div>
                            <div class="card-label">Capacidad</div>
                            <div class="card-value">${e.capacidad || '—'}</div>
                        </div>
                        <div>
                            <div class="card-label">Última Insp.</div>
                            <div class="card-value">${e.ultima_inspeccion || '—'}</div>
                        </div>
                    </div>
                    ${inspBadge}
                </div>
            </div>
        `;
    }).join('');

    document.getElementById('grid').innerHTML = html;
}

function abrirDetalle(id) {
    const ext = extintores.find(e => e.id === id);
    if (!ext) return;

    const hoy = new Date();
    const ultimaInsp = ext.ultima_inspeccion ? new Date(ext.ultima_inspeccion + ' 00:00:00') : null;
    const proxVenc = ultimaInsp ? new Date(ultimaInsp.getTime() + 30 * 24 * 60 * 60 * 1000) : null;

    let estadoInsp = 'Sin inspección';
    let colorInsp = 'danger';
    if (ultimaInsp) {
        if (proxVenc < hoy) {
            estadoInsp = 'Vencido';
            colorInsp = 'danger';
        } else if (proxVenc < new Date(hoy.getTime() + 7 * 24 * 60 * 60 * 1000)) {
            estadoInsp = 'Próx. vencimiento';
            colorInsp = 'warning';
        } else {
            estadoInsp = 'Vigente';
            colorInsp = 'ok';
        }
    }

    const html = `
        <div class="modal-section">
            <div class="modal-section-title">Información Básica</div>
            <div class="modal-row">
                <div class="modal-item">
                    <label>Código Manual</label>
                    <value>${ext.codigo_manual}</value>
                </div>
                <div class="modal-item">
                    <label>Tipo</label>
                    <value>${ext.tipo_nombre}</value>
                </div>
            </div>
            <div class="modal-row full">
                <div class="modal-item">
                    <label>Ubicación</label>
                    <value>${ext.ubicacion}</value>
                </div>
            </div>
            <div class="modal-row">
                <div class="modal-item">
                    <label>Capacidad</label>
                    <value>${ext.capacidad || '—'}</value>
                </div>
                <div class="modal-item">
                    <label>Estado</label>
                    <value><span class="estado-badge estado-${ext.estado}">${ext.estado.toUpperCase()}</span></value>
                </div>
            </div>
            ${ext.seccion ? `<div class="modal-row full"><div class="modal-item"><label>Sección / Edificio</label><value>${ext.seccion}</value></div></div>` : ''}
        </div>

        <div class="modal-section">
            <div class="modal-section-title">Fechas de Mantenimiento</div>
            <div class="modal-row">
                <div class="modal-item">
                    <label>Fecha Recarga</label>
                    <value>${ext.fecha_recarga || '—'}</value>
                </div>
                <div class="modal-item">
                    <label>Prueba Hidrostática</label>
                    <value>${ext.fecha_ph || '—'}</value>
                </div>
            </div>
        </div>

        <div class="modal-section">
            <div class="modal-section-title">Inspección</div>
            <div class="modal-row full">
                <div class="modal-item">
                    <label>Estado de Inspección</label>
                    <value><span class="inspeccion-badge insp-${colorInsp}">${estadoInsp}</span></value>
                </div>
            </div>
            ${ultimaInsp ? `<div class="modal-row"><div class="modal-item"><label>Última Inspección</label><value>${ext.ultima_inspeccion}</value></div><div class="modal-item"><label>Próx. Vencimiento</label><value>${proxVenc.toISOString().split('T')[0]}</value></div></div>` : '<p style="color:#999;font-size:13px">Este extintor aún no ha sido inspeccionado.</p>'}
        </div>

        ${ext.observaciones ? `<div class="modal-section"><div class="modal-section-title">Observaciones</div><div class="modal-row full"><div class="modal-item"><value>${ext.observaciones}</value></div></div></div>` : ''}
    `;

    document.getElementById('modalTitulo').textContent = `${ext.codigo_manual} — ${ext.ubicacion}`;
    document.getElementById('modalContenido').innerHTML = html;
    document.getElementById('modalDetalle').classList.add('open');
}

function cerrarModal() {
    document.getElementById('modalDetalle').classList.remove('open');
}

document.getElementById('busqueda').addEventListener('keyup', renderizar);
document.getElementById('filtroTipo').addEventListener('change', renderizar);
document.getElementById('filtroEstado').addEventListener('change', renderizar);

cargar();
</script>

</body>
</html>
