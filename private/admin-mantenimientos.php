<?php
/**
 * Mantenimiento de extintores (solo ADMIN).
 *
 * Lleva el registro de cada salida de un extintor —a mantenimiento, recarga o
 * garantía—: cuándo salió, cuándo volvió y quién lo atendió. Al elegir la
 * planta y el extintor se muestra su ficha, para capturar sabiendo qué se
 * tiene enfrente.
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
    /* Los tres botones en un solo renglón; en el teléfono movil.css los reparte */
    td.acciones{white-space:nowrap;text-align:right}

    .badge{padding:4px 10px;border-radius:20px;font-size:11px;font-weight:700;display:inline-block}
    .b-mantenimiento{background:#dbeafe;color:#1d4ed8}
    .b-recarga{background:#fef3c7;color:#92400e}
    .b-garantia{background:#ede9fe;color:#6d28d9}
    .b-fuera{background:#fee2e2;color:#b91c1c}
    .b-devuelto{background:#d1fae5;color:#047857}

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
    .dias{font-size:11px;color:#b91c1c;font-weight:700}

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
    <div class="sub">Qué extintor salió, por qué motivo, cuándo y quién lo atendió.</div>

    <div class="kpis" id="kpis"></div>
    <div id="msg" class="msg"></div>

    <div class="card">
        <div class="toolbar">
            <select id="fEmpresa" onchange="cargar()"></select>
            <select id="fTipo" onchange="render()">
                <option value="">Todos los motivos</option>
                <option value="mantenimiento">Mantenimiento</option>
                <option value="recarga">Recarga</option>
                <option value="garantia">Garantía</option>
            </select>
            <select id="fEstado" onchange="render()">
                <option value="">Fuera y devueltos</option>
                <option value="fuera">Sólo los que siguen fuera</option>
                <option value="devuelto">Sólo los devueltos</option>
            </select>
            <input type="text" id="busca" placeholder="🔍 Buscar extintor o responsable…" oninput="render()">
            <button class="btn btn-primary" onclick="nuevo()">＋ Registrar salida</button>
        </div>
        <div id="tabla"></div>
    </div>
</div>

<!-- ── Alta / edición ─────────────────────────────────────────────────────── -->
<div class="modal-ov" id="modalMov">
    <div class="modal">
        <h3 id="tituloMov">Registrar salida a mantenimiento</h3>
        <input type="hidden" id="k-id">

        <div class="grid2">
            <div class="fg">
                <label>Planta *</label>
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
                <label>Motivo de la salida *</label>
                <select id="k-tipo" onchange="tipoElegido()">
                    <option value="mantenimiento">Mantenimiento</option>
                    <option value="recarga">Recarga</option>
                    <option value="garantia">Garantía</option>
                </select>
            </div>
            <div class="fg">
                <label>Fecha en que salió *</label>
                <input type="date" id="k-salida">
            </div>
            <div class="fg">
                <label>Fecha en que regresó</label>
                <input type="date" id="k-retorno" onchange="tipoElegido()">
                <div class="hint">Déjala vacía si todavía no regresa.</div>
            </div>
        </div>

        <div class="fg sug-caja">
            <label>¿Quién lo realizó? *</label>
            <input type="text" id="k-quien" maxlength="150" autocomplete="off"
                   placeholder="Ej: Ing. Michel Ábalos — o el nombre del taller"
                   oninput="buscarSugerencias()" onfocus="buscarSugerencias()"
                   onkeydown="teclaSugerencia(event)">
            <div class="sugerencias" id="sugerencias"></div>
            <div class="hint">Escribe el nombre. Según teclees aparecerán los que ya hicieron mantenimientos antes.</div>
        </div>

        <div class="fg" id="fgRecarga" style="display:none">
            <label class="chk">
                <input type="checkbox" id="k-actualizar">
                Actualizar también la <b>fecha de recarga del extintor</b> con la fecha de retorno,
                para que su ficha no quede diciendo otra cosa.
            </label>
        </div>

        <div class="fg">
            <label>Notas</label>
            <textarea id="k-notas" rows="2" placeholder="Qué se le hizo, número de folio del taller, costo…"></textarea>
        </div>

        <div class="modal-actions">
            <button class="btn btn-ghost" onclick="cerrar('modalMov')">Cancelar</button>
            <button class="btn btn-primary" onclick="guardar()">Guardar</button>
        </div>
    </div>
</div>

<!-- ── Registrar retorno ──────────────────────────────────────────────────── -->
<div class="modal-ov" id="modalRet">
    <div class="modal" style="max-width:480px">
        <h3>Registrar el regreso</h3>
        <p style="font-size:13px;color:#475569;margin-bottom:14px" id="retDetalle"></p>
        <input type="hidden" id="r-id">
        <div class="fg">
            <label>Fecha en que regresó *</label>
            <input type="date" id="r-fecha">
        </div>
        <div class="fg" id="rRecarga" style="display:none">
            <label class="chk">
                <input type="checkbox" id="r-actualizar" checked>
                Actualizar la fecha de recarga del extintor con esta fecha.
            </label>
        </div>
        <div class="modal-actions">
            <button class="btn btn-ghost" onclick="cerrar('modalRet')">Cancelar</button>
            <button class="btn btn-ok" onclick="guardarRetorno()">Registrar regreso</button>
        </div>
    </div>
</div>

<script>
const API = '../api/mantenimientos.php';
let movimientos = [], empresas = [], extintores = [];

const esc = s => { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };
const cerrar = id => document.getElementById(id).classList.remove('open');
const hoy = () => new Date().toISOString().substring(0,10);
function aviso(t, ok) {
    const m = document.getElementById('msg');
    m.textContent = t; m.style.color = ok ? '#27ae60' : '#c0392b';
    setTimeout(() => { m.textContent = ''; }, 4000);
}
function fechaCorta(f) {
    if (!f) return '—';
    const p = String(f).substring(0,10).split('-');
    return p.length === 3 ? `${p[2]}/${p[1]}/${p[0]}` : f;
}
const ETIQUETA = {mantenimiento:'Mantenimiento', recarga:'Recarga', garantia:'Garantía'};

/** Días que lleva fuera un extintor que aún no regresa. */
function diasFuera(salida) {
    const d = Math.floor((Date.now() - new Date(salida + 'T00:00:00')) / 86400000);
    return d > 0 ? d : 0;
}

// ── Carga ───────────────────────────────────────────────────────────────────
async function inicio() {
    // Las sugerencias se piden al escribir, no aquí: la lista puede crecer y
    // no tiene sentido traerla entera para abrir la pantalla.
    const re = await fetch('../api/usuarios.php?action=listar_empresas')
        .then(r => r.json()).catch(() => ({}));
    empresas = re.success ? re.data : [];

    const opciones = '<option value="">Todas las plantas</option>' +
        empresas.map(e => `<option value="${e.id}">${esc(e.nombre)}</option>`).join('');
    document.getElementById('fEmpresa').innerHTML = opciones;
    document.getElementById('k-empresa').innerHTML =
        '<option value="">— Elige la planta —</option>' +
        empresas.map(e => `<option value="${e.id}">${esc(e.nombre)}</option>`).join('');

    cargar();
}

// ── Sugerencias de quién realizó el servicio ────────────────────────────────
// Se consulta al servidor según se teclea: la lista vive en la base, así que
// funciona desde cualquier equipo y no sólo donde se escribió el nombre.
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

/** Resalta dentro del nombre el trozo que se tecleó, para que se vea por qué coincide. */
function resaltar(nombre, q) {
    if (!q) return esc(nombre);
    const sinAcentos = t => t.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    const i = sinAcentos(nombre).indexOf(sinAcentos(q));
    if (i < 0) return esc(nombre);
    return esc(nombre.slice(0, i)) + '<mark>' + esc(nombre.slice(i, i + q.length)) + '</mark>'
         + esc(nombre.slice(i + q.length));
}

function pintarSugerencias(q) {
    const caja = document.getElementById('sugerencias');
    const escrito = document.getElementById('k-quien').value.trim();
    // Si lo escrito no coincide con ninguna, se ofrece darlo de alta
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

/** Flechas para recorrer, Enter para elegir, Escape para cerrar. */
function teclaSugerencia(ev) {
    const caja = document.getElementById('sugerencias');
    if (!caja.classList.contains('abierta')) return;
    const escrito = document.getElementById('k-quien').value.trim();
    const exacta = sugerencias.some(s => s.nombre.toLowerCase() === escrito.toLowerCase());
    const total = sugerencias.length + ((escrito && !exacta) ? 1 : 0);
    if (!total) return;

    if (ev.key === 'ArrowDown')      { ev.preventDefault(); marcada = (marcada + 1) % total; }
    else if (ev.key === 'ArrowUp')   { ev.preventDefault(); marcada = (marcada - 1 + total) % total; }
    else if (ev.key === 'Enter' && marcada >= 0) { ev.preventDefault(); elegirSugerencia(marcada); return; }
    else if (ev.key === 'Escape')    { cerrarSugerencias(); return; }
    else return;
    pintarSugerencias(document.getElementById('k-quien').value.trim());
}

async function cargar() {
    const eid = document.getElementById('fEmpresa').value;
    const [rm, rs] = await Promise.all([
        fetch(`${API}?action=listar${eid ? '&empresa_id=' + eid : ''}`).then(r => r.json()).catch(() => ({})),
        fetch(`${API}?action=resumen${eid ? '&empresa_id=' + eid : ''}`).then(r => r.json()).catch(() => ({})),
    ]);
    movimientos = rm.success ? rm.data : [];
    pintarKpis(rs.success ? rs.data : null);
    render();
}

function pintarKpis(r) {
    const c = document.getElementById('kpis');
    if (!r) { c.innerHTML = ''; return; }
    c.innerHTML = `
        <div class="kpi warn"><div class="v">${r.fuera}</div><div class="l">Fuera ahora mismo</div></div>
        <div class="kpi"><div class="v">${r.total}</div><div class="l">Movimientos registrados</div></div>
        <div class="kpi ok"><div class="v">${r.mes}</div><div class="l">Salidas de este mes</div></div>
        <div class="kpi"><div class="v">${r.mantenimiento}</div><div class="l">A mantenimiento</div></div>
        <div class="kpi pur"><div class="v">${r.recarga}</div><div class="l">A recarga</div></div>
        <div class="kpi pur"><div class="v">${r.garantia}</div><div class="l">A garantía</div></div>`;
}

// ── Listado ─────────────────────────────────────────────────────────────────
function render() {
    const q  = document.getElementById('busca').value.toLowerCase();
    const ft = document.getElementById('fTipo').value;
    const fe = document.getElementById('fEstado').value;

    const data = movimientos.filter(m =>
        (!ft || m.tipo === ft) &&
        (!fe || (fe === 'fuera' ? Number(m.sigue_fuera) === 1 : Number(m.sigue_fuera) === 0)) &&
        (!q || (`${m.codigo_manual} ${m.ubicacion} ${m.realizado_por} ${m.empresa_nombre}`).toLowerCase().includes(q)));

    const cont = document.getElementById('tabla');
    if (!data.length) {
        cont.innerHTML = '<div class="empty"><div class="ic">🛠️</div><p>Sin movimientos registrados todavía. Usa “Registrar salida”.</p></div>';
        return;
    }
    cont.innerHTML = `<div class="tabla-env"><table class="tabla">
        <thead><tr>
            <th>Extintor</th><th>Planta</th><th>Motivo</th><th>Salió</th>
            <th>Regresó</th><th>Realizado por</th><th>Estado</th><th></th>
        </tr></thead>
        <tbody>${data.map(m => `<tr>
            <td data-et="Extintor" class="td-titulo">${esc(m.codigo_manual)}
                <div style="font-weight:400;font-size:12px;color:#64748b">${esc(m.ubicacion || '')}</div></td>
            <td data-et="Planta">${esc(m.empresa_nombre)}</td>
            <td data-et="Motivo"><span class="badge b-${esc(m.tipo)}">${ETIQUETA[m.tipo] || esc(m.tipo)}</span></td>
            <td data-et="Salió">${fechaCorta(m.fecha_salida)}</td>
            <td data-et="Regresó">${m.fecha_retorno ? fechaCorta(m.fecha_retorno)
                : `<span class="dias">${diasFuera(m.fecha_salida)} días fuera</span>`}</td>
            <td data-et="Realizado por">${esc(m.realizado_por || '—')}</td>
            <td data-et="Estado"><span class="badge ${Number(m.sigue_fuera) ? 'b-fuera' : 'b-devuelto'}">${Number(m.sigue_fuera) ? 'Fuera' : 'Devuelto'}</span></td>
            <td class="acciones">
                ${Number(m.sigue_fuera) ? `<button class="btn btn-ok btn-sm" data-lbl="Regresó" onclick="abrirRetorno(${m.id})" title="Registrar el regreso">✅</button>` : ''}
                <button class="btn btn-warning btn-sm" data-lbl="Editar" onclick="editar(${m.id})" title="Editar">✏️</button>
                <button class="btn btn-danger btn-sm" data-lbl="Eliminar" onclick="borrar(${m.id})" title="Eliminar">🗑️</button>
            </td></tr>`).join('')}</tbody></table></div>`;
}

// ── Formulario ──────────────────────────────────────────────────────────────
function nuevo() {
    document.getElementById('tituloMov').textContent = 'Registrar salida a mantenimiento';
    document.getElementById('k-id').value = '';
    document.getElementById('k-empresa').value = document.getElementById('fEmpresa').value || '';
    document.getElementById('k-buscaExt').value = '';
    document.getElementById('k-tipo').value = 'mantenimiento';
    document.getElementById('k-salida').value = hoy();
    document.getElementById('k-retorno').value = '';
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
    document.getElementById('k-tipo').value = m.tipo;
    document.getElementById('k-salida').value = (m.fecha_salida || '').substring(0,10);
    document.getElementById('k-retorno').value = (m.fecha_retorno || '').substring(0,10);
    document.getElementById('k-notas').value = m.notas || '';
    document.getElementById('k-actualizar').checked = false;

    await empresaElegida(m.extintor_id);
    document.getElementById('k-quien').value = m.realizado_por || '';
    cerrarSugerencias();
    tipoElegido();
    document.getElementById('modalMov').classList.add('open');
}

/** La casilla de actualizar la recarga sólo tiene sentido en recargas ya devueltas. */
function tipoElegido() {
    const esRecarga = document.getElementById('k-tipo').value === 'recarga';
    const hayRetorno = !!document.getElementById('k-retorno').value;
    document.getElementById('fgRecarga').style.display = (esRecarga && hayRetorno) ? '' : 'none';
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

    const fuera = (e.historial || []).find(h => Number(h.sigue_fuera) === 1);
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
        ${fuera ? `<div class="hist" style="color:#b91c1c;font-weight:700">
            ⚠️ Este extintor ya figura fuera desde el ${fechaCorta(fuera.fecha_salida)}
            (${ETIQUETA[fuera.tipo] || fuera.tipo}, ${esc(fuera.realizado_por || 'sin responsable')}).
        </div>` : ''}
        ${hist.length ? `<div class="hist"><div class="h">Movimientos anteriores</div>
            <ul>${hist.map(h => `<li>• ${fechaCorta(h.fecha_salida)} — ${ETIQUETA[h.tipo] || esc(h.tipo)}
                ${h.fecha_retorno ? `(regresó ${fechaCorta(h.fecha_retorno)})` : '<b>(sigue fuera)</b>'}
                · ${esc(h.realizado_por || '—')}</li>`).join('')}</ul></div>` : ''}`;
}

// ── Guardar ─────────────────────────────────────────────────────────────────
async function guardar() {
    const cuerpo = {
        id:            parseInt(document.getElementById('k-id').value) || 0,
        extintor_id:   parseInt(document.getElementById('k-extintor').value) || 0,
        tipo:          document.getElementById('k-tipo').value,
        fecha_salida:  document.getElementById('k-salida').value,
        fecha_retorno: document.getElementById('k-retorno').value,
        notas:         document.getElementById('k-notas').value.trim(),
        actualizar_recarga: document.getElementById('k-actualizar').checked ? 1 : 0,
        realizado_por: document.getElementById('k-quien').value.trim(),
    };

    if (!cuerpo.extintor_id) { aviso('Elige el extintor.', false); return; }
    if (!cuerpo.fecha_salida) { aviso('Indica la fecha en que salió.', false); return; }
    if (cuerpo.fecha_retorno && cuerpo.fecha_retorno < cuerpo.fecha_salida) {
        aviso('La fecha de retorno no puede ser anterior a la de salida.', false); return;
    }
    if (!cuerpo.realizado_por) { aviso('Indica quién realizó el servicio.', false); return; }

    const r = await fetch(`${API}?action=guardar`, {
        method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(cuerpo)
    });
    const d = await r.json().catch(() => ({}));
    if (r.ok && d.success) {
        cerrar('modalMov');
        aviso('✓ Movimiento guardado', true);
        cargar();
    } else aviso(d.error || 'Error al guardar', false);
}

// ── Retorno ─────────────────────────────────────────────────────────────────
function abrirRetorno(id) {
    const m = movimientos.find(x => x.id === id);
    if (!m) return;
    document.getElementById('r-id').value = id;
    document.getElementById('r-fecha').value = hoy();
    document.getElementById('retDetalle').innerHTML =
        `<b>${esc(m.codigo_manual)}</b> — ${ETIQUETA[m.tipo] || esc(m.tipo)}, salió el ${fechaCorta(m.fecha_salida)}
         (${diasFuera(m.fecha_salida)} días). Atendido por ${esc(m.realizado_por || '—')}.`;
    document.getElementById('rRecarga').style.display = m.tipo === 'recarga' ? '' : 'none';
    document.getElementById('modalRet').classList.add('open');
}

async function guardarRetorno() {
    const cuerpo = {
        id: parseInt(document.getElementById('r-id').value) || 0,
        fecha_retorno: document.getElementById('r-fecha').value,
        actualizar_recarga: document.getElementById('r-actualizar').checked ? 1 : 0,
    };
    if (!cuerpo.fecha_retorno) { aviso('Indica la fecha en que regresó.', false); return; }
    const r = await fetch(`${API}?action=registrar_retorno`, {
        method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(cuerpo)
    });
    const d = await r.json().catch(() => ({}));
    if (r.ok && d.success) { cerrar('modalRet'); aviso('✓ Regreso registrado', true); cargar(); }
    else aviso(d.error || 'Error al registrar el regreso', false);
}

async function borrar(id) {
    if (!confirm('¿Eliminar este movimiento del registro?')) return;
    const r = await fetch(`${API}?action=eliminar&id=${id}`);
    const d = await r.json().catch(() => ({}));
    if (r.ok && d.success) { aviso('✓ Movimiento eliminado', true); cargar(); }
    else aviso(d.error || 'Error al eliminar', false);
}

document.querySelectorAll('.modal-ov').forEach(m =>
    m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); }));

// Un clic fuera del campo cierra las sugerencias
document.addEventListener('click', e => {
    if (!e.target.closest('.sug-caja')) cerrarSugerencias();
});

inicio();
</script>
</body>
</html>
