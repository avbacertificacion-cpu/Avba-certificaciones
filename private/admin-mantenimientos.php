<?php
/**
 * Mantenimiento de extintores (solo ADMIN).
 *
 * El taller es nuestro: el extintor ENTRA al taller y luego se DEVUELVE al
 * cliente. Un movimiento sin fecha de devolución es un extintor que todavía
 * tenemos aquí.
 *
 * Los motivos del ingreso son un catálogo editable desde la propia pantalla.
 */
require_once '../config/config.php';
require_once '../config/modo-demo.php';
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
<title>Mantenimiento de Extintores</title>
<link rel="stylesheet" href="../public/assets/css/movil.css">
<style>
    *{margin:0;padding:0;box-sizing:border-box}
    body{font-family:'Segoe UI',system-ui,sans-serif;background:#eef2fb;color:#1a2138}
    .navbar{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:16px 26px;
            display:flex;justify-content:space-between;align-items:center;box-shadow:0 4px 16px rgba(102,126,234,.2)}
    .navbar a{color:#fff;text-decoration:none;font-size:13px;opacity:.9}.navbar a:hover{opacity:1;text-decoration:underline}
    .container{max-width:1280px;margin:0 auto;padding:26px 20px}
    h2{font-size:24px;color:#1e293b}
    .sub{color:#64748b;font-size:13px;margin-bottom:20px}

    .kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;margin-bottom:22px}
    .kpi{background:#fff;border-radius:14px;padding:18px;box-shadow:0 4px 14px rgba(30,41,59,.08);border-left:5px solid #667eea}
    .kpi.warn{border-left-color:#f39c12}.kpi.ok{border-left-color:#27ae60}.kpi.pur{border-left-color:#8e44ad}
    .kpi .v{font-size:26px;font-weight:800;color:#1e293b;line-height:1.2}
    .kpi .l{font-size:12px;color:#64748b;margin-top:4px}

    .btn{padding:10px 18px;border:none;border-radius:8px;font-weight:700;cursor:pointer;font-size:14px}
    .btn-primary{background:#667eea;color:#fff}.btn-primary:hover{background:#5568d3}
    .btn-danger{background:#e74c3c;color:#fff}.btn-warning{background:#f39c12;color:#fff}
    .btn-ok{background:#27ae60;color:#fff}
    .btn-ghost{background:#eef2fb;color:#475569}
    .btn-sm{padding:6px 11px;font-size:12px}

    .card{background:#fff;border-radius:14px;padding:20px;box-shadow:0 4px 14px rgba(30,41,59,.08)}
    .toolbar{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:16px}
    .toolbar input,.toolbar select{padding:10px 12px;border:2px solid #e0e0ff;border-radius:8px;font-size:14px}
    .toolbar input:focus,.toolbar select:focus{outline:none;border-color:#667eea}

    table{width:100%;border-collapse:collapse}
    thead{background:#f1f5fb}
    th{padding:11px 9px;text-align:left;font-size:11px;color:#475569;font-weight:700;text-transform:uppercase}
    td{padding:11px 9px;font-size:13px;border-bottom:1px solid #f1f5f9}
    tbody tr:hover{background:#f8faff}
    td.acciones{white-space:nowrap;text-align:right}

    .badge{padding:4px 10px;border-radius:20px;font-size:11px;font-weight:700;display:inline-block}
    .b-motivo{background:#dbeafe;color:#1d4ed8}
    .b-taller{background:#fee2e2;color:#b91c1c}
    .b-devuelto{background:#d1fae5;color:#047857}
    .b-inactivo{background:#e2e8f0;color:#475569}
    .dias{font-size:11px;color:#b91c1c;font-weight:700}
    .cod-ext{background:none;border:none;padding:0;font:inherit;font-weight:700;color:#4f46e5;
             cursor:pointer;text-align:left}
    .cod-ext:hover{text-decoration:underline}

    .modal-ov{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100;
              align-items:flex-start;justify-content:center;padding:20px;overflow-y:auto}
    .modal-ov.open{display:flex}
    .modal{background:#fff;border-radius:14px;width:100%;max-width:820px;padding:24px;margin:auto}
    .modal h3{font-size:19px;margin-bottom:16px;color:#1e293b}
    .fg{margin-bottom:14px}
    .fg label{display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:5px}
    .fg input,.fg select,.fg textarea{width:100%;padding:10px;border:2px solid #e0e0ff;border-radius:8px;
                                      font-size:14px;font-family:inherit}
    .fg input:focus,.fg select:focus,.fg textarea:focus{outline:none;border-color:#667eea}
    .grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .grid3{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
    .modal-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:14px;flex-wrap:wrap}
    .hint{font-size:11px;color:#94a3b8;margin-top:4px}

    /* Ficha del extintor elegido */
    .ficha{border:2px solid #dbe3f7;background:#f8faff;border-radius:12px;padding:14px 16px;margin-bottom:14px}
    .ficha .vacia{color:#94a3b8;font-size:13px;text-align:center;padding:10px}
    .ficha .cab{display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap;
                border-bottom:1px solid #e2e8f0;padding-bottom:9px;margin-bottom:10px}
    .ficha .cod{font-size:18px;font-weight:800;color:#1e293b}
    .ficha .datos{display:grid;grid-template-columns:repeat(auto-fit,minmax(135px,1fr));gap:10px}
    .ficha .dato span{display:block;font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase}
    .ficha .dato b{font-size:13px;color:#1e293b;font-weight:600}
    .ficha .hist{margin-top:11px;border-top:1px solid #e2e8f0;padding-top:9px;font-size:12px;color:#475569}
    .ficha .hist .h{font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:5px}
    .ficha .hist li{margin-bottom:3px;list-style:none}

    /* Sugerencias de quién realizó el servicio */
    .sug-caja{position:relative}
    .sugerencias{display:none;position:absolute;left:0;right:0;top:100%;z-index:20;background:#fff;
                 border:2px solid #667eea;border-top:none;border-radius:0 0 10px 10px;
                 box-shadow:0 8px 20px rgba(30,41,59,.14);max-height:260px;overflow-y:auto}
    .sugerencias.abierta{display:block}
    .sug{padding:10px 12px;cursor:pointer;display:flex;justify-content:space-between;
         align-items:baseline;gap:12px;border-bottom:1px solid #f1f5f9}
    .sug:last-child{border-bottom:none}
    .sug:hover,.sug.marcada{background:#eef2fb}
    .sug .nom{font-weight:700;font-size:13px;color:#1e293b}
    .sug .nom mark{background:#fde68a;color:inherit;padding:0 1px;border-radius:2px}
    .sug .det{font-size:11px;color:#94a3b8;white-space:nowrap}
    .sug.nueva .nom{color:#475569;font-weight:600}

    .chk{display:flex;align-items:flex-start;gap:8px;font-size:13px;cursor:pointer;line-height:1.45}
    .chk input{width:auto;margin-top:3px}

    .empty{text-align:center;padding:50px 20px;color:#94a3b8}.empty .ic{font-size:52px;margin-bottom:10px}
    .msg{font-size:13px;font-weight:600;margin-bottom:12px}
    .aviso-filtro{background:#e0e7ff;color:#3730a3;border-radius:10px;padding:10px 14px;font-size:13px;
                  margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;gap:12px}

    @media(max-width:760px){ .grid2,.grid3{grid-template-columns:1fr} }
</style>
</head>
<body>
<?= cintaDemo() ?>
<div class="navbar">
    <a href="admin-dashboard.php">← Panel Admin</a>
    <span style="font-size:13px">👤 <?= htmlspecialchars($nombre) ?></span>
</div>

<div class="container">
    <h2>🛠️ Mantenimiento de Extintores</h2>
    <div class="sub">Qué extintor entró al taller, por qué motivo, cuándo y quién lo atendió.</div>

    <div class="kpis" id="kpis"></div>
    <div id="msg" class="msg"></div>
    <div id="avisoFiltro"></div>

    <div class="card">
        <div class="toolbar">
            <select id="fEmpresa" onchange="cargar()"></select>
            <select id="fTipo" onchange="render()"></select>
            <select id="fEstado" onchange="render()">
                <option value="">En taller y devueltos</option>
                <option value="taller">Sólo los que siguen en el taller</option>
                <option value="devuelto">Sólo los devueltos</option>
            </select>
            <input type="text" id="busca" placeholder="🔍 Buscar extintor o responsable…" oninput="render()">
            <button class="btn btn-primary" onclick="nuevo()">＋ Registrar entrada</button>
            <button class="btn btn-ghost" onclick="abrirMotivos()">⚙️ Motivos</button>
        </div>
        <div id="tabla"></div>
    </div>
</div>

<!-- ── Alta / edición ─────────────────────────────────────────────────────── -->
<div class="modal-ov" id="modalMov">
    <div class="modal">
        <h3 id="tituloMov">Registrar entrada al taller</h3>
        <input type="hidden" id="k-id">

        <div class="grid2">
            <div class="fg">
                <label>Planta del cliente *</label>
                <select id="k-empresa" onchange="empresaElegida()"></select>
            </div>
            <div class="fg">
                <label>Extintor *</label>
                <input type="text" id="k-buscaExt" placeholder="Filtrar por código o ubicación…" oninput="filtrarExtintores()">
                <select id="k-extintor" onchange="extintorElegido()" style="margin-top:7px"></select>
            </div>
        </div>

        <div class="ficha" id="ficha">
            <div class="vacia">Elige la planta y el extintor para ver su información.</div>
        </div>

        <div class="grid3">
            <div class="fg">
                <label>Motivo del ingreso *</label>
                <select id="k-tipo" onchange="tipoElegido()"></select>
            </div>
            <div class="fg">
                <label>Entró al taller *</label>
                <input type="date" id="k-entrada">
            </div>
            <div class="fg">
                <label>Devuelto al cliente</label>
                <input type="date" id="k-devolucion" onchange="tipoElegido()">
                <div class="hint">Déjala vacía si todavía lo tenemos.</div>
            </div>
        </div>

        <div class="fg sug-caja">
            <label>¿Quién lo realizó? *</label>
            <input type="text" id="k-quien" maxlength="150" autocomplete="off"
                   placeholder="Ej: Ing. Michel Ábalos"
                   oninput="buscarSugerencias()" onfocus="buscarSugerencias()"
                   onkeydown="teclaSugerencia(event)">
            <div class="sugerencias" id="sugerencias"></div>
            <div class="hint">Escribe el nombre. Según teclees aparecerán los que ya hicieron mantenimientos antes.</div>
        </div>

        <div class="fg" id="fgRecarga" style="display:none">
            <label class="chk">
                <input type="checkbox" id="k-actualizar">
                Actualizar también la <b>fecha de recarga del extintor</b> con la fecha de devolución,
                para que su ficha no quede diciendo otra cosa.
            </label>
        </div>

        <div class="fg">
            <label>Notas</label>
            <textarea id="k-notas" rows="2" placeholder="Qué se le hizo, número de folio, costo…"></textarea>
        </div>

        <div class="modal-actions">
            <button class="btn btn-ghost" onclick="cerrar('modalMov')">Cancelar</button>
            <button class="btn btn-primary" onclick="guardar()">Guardar</button>
        </div>
    </div>
</div>

<!-- ── Registrar devolución ───────────────────────────────────────────────── -->
<div class="modal-ov" id="modalDev">
    <div class="modal" style="max-width:480px">
        <h3>Devolver al cliente</h3>
        <p style="font-size:13px;color:#475569;margin-bottom:14px" id="devDetalle"></p>
        <input type="hidden" id="d-id">
        <div class="fg">
            <label>Fecha de devolución *</label>
            <input type="date" id="d-fecha">
        </div>
        <div class="fg" id="dRecarga" style="display:none">
            <label class="chk">
                <input type="checkbox" id="d-actualizar" checked>
                Actualizar la fecha de recarga del extintor con esta fecha.
            </label>
        </div>
        <div class="modal-actions">
            <button class="btn btn-ghost" onclick="cerrar('modalDev')">Cancelar</button>
            <button class="btn btn-ok" onclick="guardarDevolucion()">Registrar devolución</button>
        </div>
    </div>
</div>

<!-- ── Catálogo de motivos ────────────────────────────────────────────────── -->
<div class="modal-ov" id="modalMotivos">
    <div class="modal" style="max-width:700px">
        <h3>⚙️ Motivos de ingreso al taller</h3>
        <p style="font-size:13px;color:#64748b;margin-bottom:16px">
            Son los que aparecen al registrar una entrada. Puedes agregar los tuyos,
            cambiarles el nombre o quitar los que no uses.
        </p>
        <div id="tablaMotivos"></div>

        <div class="card" style="box-shadow:none;border:2px solid #e0e0ff;margin-top:16px;padding:16px">
            <input type="hidden" id="t-id">
            <div class="grid2">
                <div class="fg" style="margin-bottom:8px">
                    <label id="t-titulo">Nuevo motivo</label>
                    <input type="text" id="t-nombre" maxlength="80" placeholder="Ej: Prueba hidrostática">
                </div>
                <div class="fg" style="margin-bottom:8px;display:flex;align-items:flex-end">
                    <label class="chk">
                        <input type="checkbox" id="t-recarga">
                        Al devolverlo, ofrecer actualizar la <b>fecha de recarga</b> del extintor
                    </label>
                </div>
            </div>
            <div class="modal-actions" style="margin-top:8px">
                <button class="btn btn-ghost btn-sm" id="t-cancelar" onclick="limpiarMotivo()" style="display:none">Cancelar edición</button>
                <button class="btn btn-primary btn-sm" onclick="guardarMotivo()">Guardar motivo</button>
            </div>
        </div>

        <div class="modal-actions">
            <button class="btn btn-ghost" onclick="cerrarMotivos()">Cerrar</button>
        </div>
    </div>
</div>

<script>
const API = '../api/mantenimientos.php';
let movimientos = [], empresas = [], extintores = [], motivos = [], filtroExtintor = null;

const esc = s => { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };
const cerrar = id => document.getElementById(id).classList.remove('open');
const hoy = () => new Date().toISOString().substring(0,10);
function aviso(t, ok) {
    const m = document.getElementById('msg');
    m.textContent = t; m.style.color = ok ? '#27ae60' : '#c0392b';
    setTimeout(() => { m.textContent = ''; }, 5000);
}
function fechaCorta(f) {
    if (!f) return '—';
    const p = String(f).substring(0,10).split('-');
    return p.length === 3 ? `${p[2]}/${p[1]}/${p[0]}` : f;
}
/** Días que lleva el extintor en el taller. */
function diasEnTaller(entrada) {
    const d = Math.floor((Date.now() - new Date(entrada + 'T00:00:00')) / 86400000);
    return d > 0 ? d : 0;
}

// ── Carga ───────────────────────────────────────────────────────────────────
async function inicio() {
    const re = await fetch('../api/usuarios.php?action=listar_empresas')
        .then(r => r.json()).catch(() => ({}));
    empresas = re.success ? re.data : [];
    document.getElementById('fEmpresa').innerHTML = '<option value="">Todas las plantas</option>' +
        empresas.map(e => `<option value="${e.id}">${esc(e.nombre)}</option>`).join('');
    document.getElementById('k-empresa').innerHTML = '<option value="">— Elige la planta —</option>' +
        empresas.map(e => `<option value="${e.id}">${esc(e.nombre)}</option>`).join('');
    await cargarMotivos();
    cargar();
}

async function cargarMotivos() {
    const r = await fetch(`${API}?action=listar_tipos`).then(x => x.json()).catch(() => ({}));
    motivos = r.success ? r.data : [];
    document.getElementById('fTipo').innerHTML = '<option value="">Todos los motivos</option>' +
        motivos.map(t => `<option value="${t.id}">${esc(t.nombre)}</option>`).join('');
    document.getElementById('k-tipo').innerHTML = motivos.length
        ? motivos.map(t => `<option value="${t.id}">${esc(t.nombre)}</option>`).join('')
        : '<option value="">— Sin motivos: créalos en ⚙️ Motivos —</option>';
}

async function cargar() {
    const eid = document.getElementById('fEmpresa').value;
    const q = new URLSearchParams();
    if (eid) q.set('empresa_id', eid);
    if (filtroExtintor) q.set('extintor_id', filtroExtintor.id);

    const [rm, rs] = await Promise.all([
        fetch(`${API}?action=listar&${q}`).then(r => r.json()).catch(() => ({})),
        fetch(`${API}?action=resumen${eid ? '&empresa_id=' + eid : ''}`).then(r => r.json()).catch(() => ({})),
    ]);
    movimientos = rm.success ? rm.data : [];
    pintarKpis(rs.success ? rs.data : null);
    pintarAvisoFiltro();
    render();
}

function pintarKpis(r) {
    const c = document.getElementById('kpis');
    if (!r) { c.innerHTML = ''; return; }
    const porMotivo = (r.por_motivo || []).map(m =>
        `<div class="kpi pur"><div class="v">${m.n}</div><div class="l">${esc(m.nombre)}</div></div>`).join('');
    c.innerHTML = `
        <div class="kpi warn"><div class="v">${r.en_taller}</div><div class="l">En el taller ahora</div></div>
        <div class="kpi"><div class="v">${r.total}</div><div class="l">Movimientos registrados</div></div>
        <div class="kpi ok"><div class="v">${r.mes}</div><div class="l">Entradas de este mes</div></div>
        ${porMotivo}`;
}

/** Cuando se está viendo el control de un solo extintor, se dice y se puede salir. */
function pintarAvisoFiltro() {
    const c = document.getElementById('avisoFiltro');
    c.innerHTML = filtroExtintor
        ? `<div class="aviso-filtro">
             <span>Viendo el control del extintor <b>${esc(filtroExtintor.codigo)}</b>
             — ${esc(filtroExtintor.empresa)}</span>
             <button class="btn btn-ghost btn-sm" onclick="quitarFiltroExtintor()">Ver todos</button>
           </div>` : '';
}

function verControlDe(id, codigo, empresa) {
    filtroExtintor = {id, codigo, empresa};
    cargar();
}
function quitarFiltroExtintor() { filtroExtintor = null; cargar(); }

// ── Listado ─────────────────────────────────────────────────────────────────
function render() {
    const q  = document.getElementById('busca').value.toLowerCase();
    const ft = document.getElementById('fTipo').value;
    const fe = document.getElementById('fEstado').value;

    const data = movimientos.filter(m =>
        (!ft || String(m.tipo_id) === ft) &&
        (!fe || (fe === 'taller' ? Number(m.en_taller) === 1 : Number(m.en_taller) === 0)) &&
        (!q || (`${m.codigo_manual} ${m.ubicacion} ${m.realizado_por} ${m.empresa_nombre} ${m.motivo || ''}`)
                 .toLowerCase().includes(q)));

    const cont = document.getElementById('tabla');
    if (!data.length) {
        cont.innerHTML = '<div class="empty"><div class="ic">🛠️</div><p>Sin movimientos todavía. Usa “Registrar entrada”.</p></div>';
        return;
    }
    cont.innerHTML = `<div class="tabla-env"><table class="tabla">
        <thead><tr>
            <th>Extintor</th><th>Planta</th><th>Motivo</th><th>Entró al taller</th>
            <th>Devuelto</th><th>Realizado por</th><th>Estado</th><th></th>
        </tr></thead>
        <tbody>${data.map(m => `<tr>
            <td data-et="Extintor" class="td-titulo">
                <button class="cod-ext" onclick="verControlDe(${m.extintor_id},'${esc(m.codigo_manual)}','${esc(m.empresa_nombre)}')"
                        title="Ver el control completo de este extintor">${esc(m.codigo_manual)}</button>
                <div style="font-weight:400;font-size:12px;color:#64748b">${esc(m.ubicacion || '')}</div></td>
            <td data-et="Planta">${esc(m.empresa_nombre)}</td>
            <td data-et="Motivo"><span class="badge b-motivo">${esc(m.motivo || '—')}</span></td>
            <td data-et="Entró al taller">${fechaCorta(m.fecha_entrada)}</td>
            <td data-et="Devuelto">${m.fecha_devolucion ? fechaCorta(m.fecha_devolucion)
                : `<span class="dias">${diasEnTaller(m.fecha_entrada)} días en taller</span>`}</td>
            <td data-et="Realizado por">${esc(m.realizado_por || '—')}</td>
            <td data-et="Estado"><span class="badge ${Number(m.en_taller) ? 'b-taller' : 'b-devuelto'}">${Number(m.en_taller) ? 'En taller' : 'Devuelto'}</span></td>
            <td class="acciones">
                ${Number(m.en_taller) ? `<button class="btn btn-ok btn-sm" data-lbl="Devolver" onclick="abrirDevolucion(${m.id})" title="Registrar la devolución">✅</button>` : ''}
                <button class="btn btn-warning btn-sm" data-lbl="Editar" onclick="editar(${m.id})" title="Editar">✏️</button>
                <button class="btn btn-danger btn-sm" data-lbl="Eliminar" onclick="borrar(${m.id})" title="Eliminar">🗑️</button>
            </td></tr>`).join('')}</tbody></table></div>`;
}

// ── Formulario ──────────────────────────────────────────────────────────────
function nuevo() {
    if (!motivos.length) { aviso('Primero crea al menos un motivo en ⚙️ Motivos.', false); return; }
    document.getElementById('tituloMov').textContent = 'Registrar entrada al taller';
    document.getElementById('k-id').value = '';
    document.getElementById('k-empresa').value = document.getElementById('fEmpresa').value || '';
    document.getElementById('k-buscaExt').value = '';
    document.getElementById('k-tipo').selectedIndex = 0;
    document.getElementById('k-entrada').value = hoy();
    document.getElementById('k-devolucion').value = '';
    document.getElementById('k-quien').value = '';
    document.getElementById('k-notas').value = '';
    document.getElementById('k-actualizar').checked = true;
    cerrarSugerencias();
    limpiarFicha();
    empresaElegida();
    tipoElegido();
    document.getElementById('modalMov').classList.add('open');
}

async function editar(id) {
    const r = await fetch(`${API}?action=obtener&id=${id}`);
    const d = await r.json().catch(() => ({}));
    if (!r.ok || !d.success) { aviso(d.error || 'No se pudo abrir el movimiento', false); return; }
    const m = d.data;

    document.getElementById('tituloMov').textContent = 'Editar movimiento';
    document.getElementById('k-id').value = m.id;
    document.getElementById('k-empresa').value = m.empresa_id;
    document.getElementById('k-buscaExt').value = '';
    document.getElementById('k-tipo').value = m.tipo_id || '';
    document.getElementById('k-entrada').value = (m.fecha_entrada || '').substring(0,10);
    document.getElementById('k-devolucion').value = (m.fecha_devolucion || '').substring(0,10);
    document.getElementById('k-quien').value = m.realizado_por || '';
    document.getElementById('k-notas').value = m.notas || '';
    document.getElementById('k-actualizar').checked = false;
    cerrarSugerencias();

    await empresaElegida(m.extintor_id);
    tipoElegido();
    document.getElementById('modalMov').classList.add('open');
}

/** La casilla de la recarga sólo aplica al motivo que lo pide y ya devuelto. */
function tipoElegido() {
    const id = document.getElementById('k-tipo').value;
    const motivo = motivos.find(t => String(t.id) === String(id));
    const aplica = motivo && Number(motivo.actualiza_recarga) === 1;
    const devuelto = !!document.getElementById('k-devolucion').value;
    document.getElementById('fgRecarga').style.display = (aplica && devuelto) ? '' : 'none';
}

// ── Extintores de la planta elegida ─────────────────────────────────────────
async function empresaElegida(seleccionar) {
    const eid = document.getElementById('k-empresa').value;
    const sel = document.getElementById('k-extintor');
    if (!eid) {
        extintores = [];
        sel.innerHTML = '<option value="">— Elige primero la planta —</option>';
        limpiarFicha();
        return;
    }
    sel.innerHTML = '<option value="">Cargando…</option>';
    const r = await fetch(`../api/extintores.php?action=listar&empresa_id=${eid}`).then(r => r.json()).catch(() => ({}));
    extintores = r.success ? r.data : [];
    filtrarExtintores();
    if (seleccionar) { sel.value = seleccionar; await extintorElegido(); }
}

function filtrarExtintores() {
    const q = document.getElementById('k-buscaExt').value.toLowerCase();
    const sel = document.getElementById('k-extintor');
    const previo = sel.value;
    const lista = extintores.filter(e =>
        !q || (`${e.codigo_manual} ${e.ubicacion || ''} ${e.seccion || ''}`).toLowerCase().includes(q));

    sel.innerHTML = '<option value="">— Elige el extintor —</option>' + lista.map(e =>
        `<option value="${e.id}">${esc(e.codigo_manual)} · ${esc(e.tipo_nombre || '')} ${esc(e.capacidad || '')} · ${esc(e.ubicacion || '')}</option>`).join('');
    if (previo && lista.some(e => String(e.id) === previo)) sel.value = previo;
    if (!lista.length) sel.innerHTML = '<option value="">— Ningún extintor coincide —</option>';
}

function limpiarFicha() {
    document.getElementById('ficha').innerHTML =
        '<div class="vacia">Elige la planta y el extintor para ver su información.</div>';
}

async function extintorElegido() {
    const id = document.getElementById('k-extintor').value;
    if (!id) { limpiarFicha(); return; }
    const r = await fetch(`${API}?action=extintor&id=${id}`).then(r => r.json()).catch(() => ({}));
    if (!r.success) { limpiarFicha(); return; }
    const e = r.data;

    const enTaller = (e.historial || []).find(h => Number(h.en_taller) === 1);
    const hist = (e.historial || []).slice(0, 4);

    document.getElementById('ficha').innerHTML = `
        <div class="cab">
            <span class="cod">${esc(e.codigo_manual)}</span>
            <span style="font-size:12px;color:#64748b">${esc(e.empresa_nombre)}</span>
        </div>
        <div class="datos">
            <div class="dato"><span>Tipo</span><b>${esc(e.tipo_extintor || '—')}</b></div>
            <div class="dato"><span>Capacidad</span><b>${esc(e.capacidad || '—')}</b></div>
            <div class="dato"><span>Sección</span><b>${esc(e.seccion || '—')}</b></div>
            <div class="dato"><span>Ubicación</span><b>${esc(e.ubicacion || '—')}</b></div>
            <div class="dato"><span>Última recarga</span><b>${fechaCorta(e.fecha_recarga)}</b></div>
            <div class="dato"><span>Prueba hidrostática</span><b>${fechaCorta(e.fecha_ph)}</b></div>
            <div class="dato"><span>Última inspección</span><b>${fechaCorta(e.ultima_inspeccion)}</b></div>
            <div class="dato"><span>Estado</span><b>${esc(e.estado || '—')}</b></div>
        </div>
        ${enTaller ? `<div class="hist" style="color:#b91c1c;font-weight:700">
            ⚠️ Este extintor ya está en el taller desde el ${fechaCorta(enTaller.fecha_entrada)}
            (${esc(enTaller.motivo || '')}, ${esc(enTaller.realizado_por || 'sin responsable')}).
        </div>` : ''}
        ${hist.length ? `<div class="hist"><div class="h">Movimientos anteriores</div>
            <ul>${hist.map(h => `<li>• ${fechaCorta(h.fecha_entrada)} — ${esc(h.motivo || '')}
                ${h.fecha_devolucion ? `(devuelto ${fechaCorta(h.fecha_devolucion)})` : '<b>(sigue en el taller)</b>'}
                · ${esc(h.realizado_por || '—')}</li>`).join('')}</ul></div>` : ''}`;
}

// ── Sugerencias de quién realizó el servicio ────────────────────────────────
let sugerencias = [], marcada = -1, peticionSug = 0;

async function buscarSugerencias() {
    const q = document.getElementById('k-quien').value.trim();
    const mia = ++peticionSug;
    const r = await fetch(`${API}?action=sugerir&q=${encodeURIComponent(q)}`)
        .then(x => x.json()).catch(() => ({}));
    if (mia !== peticionSug) return;          // llegó tarde: ya se tecleó más
    sugerencias = r.success ? r.data : [];
    marcada = -1;
    pintarSugerencias(q);
}

/** Resalta dentro del nombre el trozo tecleado, para que se vea por qué coincide. */
function resaltar(nombre, q) {
    if (!q) return esc(nombre);
    const sinAcentos = t => t.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    const i = sinAcentos(nombre).indexOf(sinAcentos(q));
    if (i < 0) return esc(nombre);
    return esc(nombre.slice(0, i)) + '<mark>' + esc(nombre.slice(i, i + q.length)) + '</mark>'
         + esc(nombre.slice(i + q.length));
}

function pintarSugerencias(q) {
    const caja = document.getElementById('sugerencias');
    const escrito = document.getElementById('k-quien').value.trim();
    const exacta = sugerencias.some(s => s.nombre.toLowerCase() === escrito.toLowerCase());
    const filas = sugerencias.map((s, i) =>
        `<div class="sug ${i === marcada ? 'marcada' : ''}" onmousedown="elegirSugerencia(${i})">
            <span class="nom">${resaltar(s.nombre, q)}</span>
            <span class="det">${esc(s.detalle)}</span>
        </div>`).join('');
    const nueva = (escrito && !exacta)
        ? `<div class="sug nueva ${marcada === sugerencias.length ? 'marcada' : ''}" onmousedown="elegirSugerencia(${sugerencias.length})">
             <span class="nom">✎ Usar “${esc(escrito)}”</span>
             <span class="det">nombre nuevo</span>
           </div>` : '';

    caja.innerHTML = filas + nueva;
    caja.classList.toggle('abierta', !!(filas || nueva));
}

function elegirSugerencia(i) {
    if (i < sugerencias.length) document.getElementById('k-quien').value = sugerencias[i].nombre;
    cerrarSugerencias();
}
function cerrarSugerencias() {
    document.getElementById('sugerencias').classList.remove('abierta');
    marcada = -1;
}

function teclaSugerencia(ev) {
    const caja = document.getElementById('sugerencias');
    if (!caja.classList.contains('abierta')) return;
    const escrito = document.getElementById('k-quien').value.trim();
    const exacta = sugerencias.some(s => s.nombre.toLowerCase() === escrito.toLowerCase());
    const total = sugerencias.length + ((escrito && !exacta) ? 1 : 0);
    if (!total) return;

    if (ev.key === 'ArrowDown')    { ev.preventDefault(); marcada = (marcada + 1) % total; }
    else if (ev.key === 'ArrowUp') { ev.preventDefault(); marcada = (marcada - 1 + total) % total; }
    else if (ev.key === 'Enter' && marcada >= 0) { ev.preventDefault(); elegirSugerencia(marcada); return; }
    else if (ev.key === 'Escape')  { cerrarSugerencias(); return; }
    else return;
    pintarSugerencias(escrito);
}

// ── Guardar ─────────────────────────────────────────────────────────────────
async function guardar() {
    const cuerpo = {
        id:               parseInt(document.getElementById('k-id').value) || 0,
        extintor_id:      parseInt(document.getElementById('k-extintor').value) || 0,
        tipo_id:          parseInt(document.getElementById('k-tipo').value) || 0,
        fecha_entrada:    document.getElementById('k-entrada').value,
        fecha_devolucion: document.getElementById('k-devolucion').value,
        realizado_por:    document.getElementById('k-quien').value.trim(),
        notas:            document.getElementById('k-notas').value.trim(),
        actualizar_recarga: document.getElementById('k-actualizar').checked ? 1 : 0,
    };

    if (!cuerpo.extintor_id)   { aviso('Elige el extintor.', false); return; }
    if (!cuerpo.tipo_id)       { aviso('Elige el motivo del ingreso.', false); return; }
    if (!cuerpo.fecha_entrada) { aviso('Indica la fecha en que entró al taller.', false); return; }
    if (cuerpo.fecha_devolucion && cuerpo.fecha_devolucion < cuerpo.fecha_entrada) {
        aviso('La fecha de devolución no puede ser anterior a la de entrada.', false); return;
    }
    if (!cuerpo.realizado_por) { aviso('Indica quién realizó el servicio.', false); return; }

    const r = await fetch(`${API}?action=guardar`, {
        method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(cuerpo)
    });
    const d = await r.json().catch(() => ({}));
    if (r.ok && d.success) { cerrar('modalMov'); aviso('✓ Movimiento guardado', true); cargar(); }
    else aviso(d.error || 'Error al guardar', false);
}

// ── Devolución ──────────────────────────────────────────────────────────────
function abrirDevolucion(id) {
    const m = movimientos.find(x => x.id === id);
    if (!m) return;
    document.getElementById('d-id').value = id;
    document.getElementById('d-fecha').value = hoy();
    document.getElementById('devDetalle').innerHTML =
        `<b>${esc(m.codigo_manual)}</b> — ${esc(m.motivo || '')}, entró el ${fechaCorta(m.fecha_entrada)}
         (${diasEnTaller(m.fecha_entrada)} días en el taller). Atendido por ${esc(m.realizado_por || '—')}.`;
    document.getElementById('dRecarga').style.display = Number(m.actualiza_recarga) === 1 ? '' : 'none';
    document.getElementById('modalDev').classList.add('open');
}

async function guardarDevolucion() {
    const cuerpo = {
        id: parseInt(document.getElementById('d-id').value) || 0,
        fecha_devolucion: document.getElementById('d-fecha').value,
        actualizar_recarga: document.getElementById('d-actualizar').checked ? 1 : 0,
    };
    if (!cuerpo.fecha_devolucion) { aviso('Indica la fecha de devolución.', false); return; }
    const r = await fetch(`${API}?action=registrar_devolucion`, {
        method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(cuerpo)
    });
    const d = await r.json().catch(() => ({}));
    if (r.ok && d.success) { cerrar('modalDev'); aviso('✓ Devolución registrada', true); cargar(); }
    else aviso(d.error || 'Error al registrar la devolución', false);
}

async function borrar(id) {
    if (!confirm('¿Eliminar este movimiento del registro?')) return;
    const r = await fetch(`${API}?action=eliminar&id=${id}`);
    const d = await r.json().catch(() => ({}));
    if (r.ok && d.success) { aviso('✓ Movimiento eliminado', true); cargar(); }
    else aviso(d.error || 'Error al eliminar', false);
}

// ── Catálogo de motivos ─────────────────────────────────────────────────────
async function abrirMotivos() {
    limpiarMotivo();
    await pintarMotivos();
    document.getElementById('modalMotivos').classList.add('open');
}

async function cerrarMotivos() {
    cerrar('modalMotivos');
    await cargarMotivos();   // los filtros y el formulario reflejan los cambios
    cargar();
}

async function pintarMotivos() {
    const r = await fetch(`${API}?action=listar_tipos&todos=1`).then(x => x.json()).catch(() => ({}));
    const datos = r.success ? r.data : [];
    const cont = document.getElementById('tablaMotivos');
    if (!datos.length) {
        cont.innerHTML = '<div class="empty" style="padding:24px"><p>Sin motivos. Crea el primero abajo.</p></div>';
        return;
    }
    cont.innerHTML = `<div class="tabla-env"><table class="tabla">
        <thead><tr><th>Motivo</th><th>Actualiza recarga</th><th>Usos</th><th>Estado</th><th></th></tr></thead>
        <tbody>${datos.map(t => `<tr>
            <td data-et="Motivo" class="td-titulo">${esc(t.nombre)}</td>
            <td data-et="Actualiza recarga">${Number(t.actualiza_recarga) ? 'Sí' : 'No'}</td>
            <td data-et="Usos">${t.usos}</td>
            <td data-et="Estado">${t.estado === 'activo'
                ? '<span class="badge b-devuelto">Activo</span>'
                : '<span class="badge b-inactivo">Inactivo</span>'}</td>
            <td class="acciones">
                <button class="btn btn-warning btn-sm" data-lbl="Editar" onclick='editarMotivo(${JSON.stringify(t)})' title="Editar">✏️</button>
                ${t.estado === 'activo'
                    ? `<button class="btn btn-danger btn-sm" data-lbl="Quitar" onclick="borrarMotivo(${t.id})" title="Quitar">🗑️</button>`
                    : `<button class="btn btn-ok btn-sm" data-lbl="Reactivar" onclick='reactivarMotivo(${JSON.stringify(t)})' title="Reactivar">↩️</button>`}
            </td></tr>`).join('')}</tbody></table></div>`;
}

function editarMotivo(t) {
    document.getElementById('t-id').value = t.id;
    document.getElementById('t-nombre').value = t.nombre;
    document.getElementById('t-recarga').checked = Number(t.actualiza_recarga) === 1;
    document.getElementById('t-titulo').textContent = 'Editando «' + t.nombre + '»';
    document.getElementById('t-cancelar').style.display = '';
    document.getElementById('t-nombre').focus();
}

function limpiarMotivo() {
    document.getElementById('t-id').value = '';
    document.getElementById('t-nombre').value = '';
    document.getElementById('t-recarga').checked = false;
    document.getElementById('t-titulo').textContent = 'Nuevo motivo';
    document.getElementById('t-cancelar').style.display = 'none';
}

async function guardarMotivo() {
    const cuerpo = {
        id: parseInt(document.getElementById('t-id').value) || 0,
        nombre: document.getElementById('t-nombre').value.trim(),
        actualiza_recarga: document.getElementById('t-recarga').checked ? 1 : 0,
    };
    if (!cuerpo.nombre) { aviso('Escribe el nombre del motivo.', false); return; }
    const r = await fetch(`${API}?action=guardar_tipo`, {
        method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(cuerpo)
    });
    const d = await r.json().catch(() => ({}));
    if (r.ok && d.success) { limpiarMotivo(); await pintarMotivos(); await cargarMotivos(); aviso('✓ Motivo guardado', true); }
    else aviso(d.error || 'Error al guardar el motivo', false);
}

async function reactivarMotivo(t) {
    const r = await fetch(`${API}?action=guardar_tipo`, {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({id: t.id, nombre: t.nombre, actualiza_recarga: t.actualiza_recarga, estado: 'activo'})
    });
    const d = await r.json().catch(() => ({}));
    if (r.ok && d.success) { await pintarMotivos(); await cargarMotivos(); aviso('✓ Motivo reactivado', true); }
    else aviso(d.error || 'Error al reactivar', false);
}

async function borrarMotivo(id) {
    if (!confirm('¿Quitar este motivo de la lista?')) return;
    const r = await fetch(`${API}?action=eliminar_tipo&id=${id}`);
    const d = await r.json().catch(() => ({}));
    if (r.ok && d.success) {
        await pintarMotivos(); await cargarMotivos();
        aviso(d.mensaje || '✓ Motivo quitado', true);
    } else aviso(d.error || 'Error al quitar el motivo', false);
}

document.querySelectorAll('.modal-ov').forEach(m =>
    m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); }));

document.addEventListener('click', e => {
    if (!e.target.closest('.sug-caja')) cerrarSugerencias();
});

inicio();
</script>
</body>
</html>
