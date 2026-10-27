<?php
require_once '../config/config.php';
require_once '../config/vencimientos.php';
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== ROLE_ADMIN) {
    header('Location: ../public/login.html'); exit;
}
$nombre = $_SESSION['nombre'];

// Se puede llegar ya filtrado, p. ej. desde la tarjeta de Vencimientos del panel
$rangoInicial    = rangoPedido($_GET['rango'] ?? null);
$conceptoInicial = conceptoPedido($_GET['concepto'] ?? null);
$empresaInicial  = intval($_GET['empresa_id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../public/assets/css/movil.css">
    <title>Vencimientos</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Segoe UI',sans-serif;background:#f0f4ff;color:#333}

        .navbar{background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:#fff;padding:16px 20px;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;z-index:50;box-shadow:0 4px 12px rgba(102,126,234,.15)}
        .navbar h1{font-size:20px;font-weight:700}
        .navbar a{color:#fff;text-decoration:none;font-size:13px;transition:.2s}
        .navbar a:hover{opacity:.8}

        .container{max-width:1400px;margin:24px auto;padding:0 16px}
        .page-header{margin-bottom:22px}
        .page-header h2{font-size:28px;color:#333;margin-bottom:8px}
        .page-header p{color:#888;font-size:14px}

        /* Los tres cortes, con cuántos hay en cada uno */
        .rangos{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:16px}
        .rango{padding:14px 16px;border:2px solid #e0e0ff;background:#fff;border-radius:12px;cursor:pointer;
               text-align:left;transition:.15s;font-family:inherit}
        .rango:hover{border-color:#c7d2fe}
        .rango .n{display:block;font-size:28px;font-weight:800;line-height:1.1}
        .rango .t{display:block;font-size:13px;font-weight:700;color:#64748b;margin-top:2px}
        .rango.vencidos .n{color:#b91c1c}
        .rango.d15 .n{color:#c2410c}
        .rango.d30 .n{color:#a16207}
        .rango.activa{border-color:#667eea;background:#eef2ff;box-shadow:0 0 0 3px rgba(102,126,234,.15)}

        .toolbar{display:grid;grid-template-columns:1fr;gap:12px;margin-bottom:16px}
        @media(min-width:768px){.toolbar{grid-template-columns:1fr 1fr 1.3fr}}
        .toolbar input,.toolbar select{padding:12px 14px;border:2px solid #e0e0ff;border-radius:8px;font-size:14px;background:#fff;transition:.2s;width:100%}
        .toolbar input:focus,.toolbar select:focus{outline:none;border-color:#667eea;box-shadow:0 0 0 3px rgba(102,126,234,.1)}

        .resumen{background:#fff;border-radius:12px;padding:16px 18px;margin-bottom:14px;
                 box-shadow:0 2px 8px rgba(30,41,59,.08);font-size:14px;line-height:1.7}
        .resumen .cifra{font-size:24px;font-weight:800}
        .resumen .planta{display:flex;justify-content:space-between;gap:16px;padding:7px 0;border-top:1px solid #f1f5f9}
        .resumen .planta button{background:none;border:none;color:#4f46e5;font:inherit;cursor:pointer;text-align:left;padding:0}
        .resumen .planta button:hover{text-decoration:underline}
        .acciones{margin:0 0 18px;display:flex;gap:10px;flex-wrap:wrap;align-items:center}
        .btn-pdf{padding:12px 20px;border:none;border-radius:9px;background:#667eea;color:#fff;font-weight:700;font-size:14px;cursor:pointer}
        .btn-pdf:hover{background:#5568d3}
        .nota{font-size:12px;color:#888}

        .table-wrapper{background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.06);overflow-x:auto}
        table{width:100%;border-collapse:collapse}
        thead{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff}
        th{padding:14px 12px;text-align:left;font-weight:600;font-size:12px;letter-spacing:.3px;white-space:nowrap}
        td{padding:12px;font-size:12px;border-bottom:1px solid #f0f0f0;vertical-align:top}
        tbody tr:hover{background:#f8faff}

        td.td-titulo{white-space:nowrap}
        .vence{display:block;white-space:nowrap}
        .vence + .vence{margin-top:4px}
        .dias{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;margin-left:4px}
        .dias.vencido{background:#fee2e2;color:#991b1b}
        .dias.pronto{background:#ffedd5;color:#9a3412}
        .dias.mes{background:#fef9c3;color:#854d0e}
        .b-taller{background:#fef3c7;color:#92400e;padding:3px 8px;border-radius:10px;font-size:11px;font-weight:700;white-space:nowrap}

        .empty{text-align:center;padding:60px 20px;background:#fff;border-radius:12px;color:#888}
        .empty .icon{font-size:56px;margin-bottom:12px}

        @media(max-width:768px){
            .navbar h1{font-size:18px}
            .page-header h2{font-size:22px}
            .rangos{gap:8px}
            .rango{padding:10px}
            .rango .n{font-size:22px}
            .rango .t{font-size:11px}
            .vence{white-space:normal}
        }
    </style>
</head>
<body>
<div class="navbar">
    <h1>⏰ Vencimientos</h1>
    <div>
        <a href="admin-dashboard.php">← Volver</a>
        <span style="margin-left:16px;font-size:13px"><?= htmlspecialchars($nombre) ?></span>
    </div>
</div>

<div class="container">
    <div class="page-header">
        <h2>⏰ Extintores por vencer</h2>
        <p>Vencidos y los que vencen en los próximos 15 o 30 días, por planta</p>
    </div>

    <div class="rangos">
        <button class="rango vencidos" data-rango="vencidos" onclick="elegirRango('vencidos')">
            <span class="n" id="n-vencidos">—</span><span class="t">Vencidos</span></button>
        <button class="rango d15" data-rango="15" onclick="elegirRango('15')">
            <span class="n" id="n-15">—</span><span class="t">Vencen en 15 días</span></button>
        <button class="rango d30" data-rango="30" onclick="elegirRango('30')">
            <span class="n" id="n-30">—</span><span class="t">Vencen en 30 días</span></button>
    </div>

    <div class="toolbar">
        <select id="filtroEmpresa" onchange="cargar()" title="Filtrar por planta">
            <option value="">🏭 Todas las plantas</option>
        </select>
        <select id="filtroConcepto" onchange="cargar()" title="Qué vence">
            <?php foreach (VENCE_CONCEPTOS as $k => $t): ?>
                <option value="<?= $k ?>"<?= $k === $conceptoInicial ? ' selected' : '' ?>><?= htmlspecialchars($t) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" id="filtroTexto" placeholder="🔍 Buscar código, área o ubicación…" oninput="pintar()">
    </div>

    <div id="resumen" class="resumen"></div>
    <div class="acciones">
        <button class="btn-pdf" onclick="descargarPDF()">📄 Descargar PDF</button>
        <span class="nota">Recarga: vence un año después de la última. Prueba hidrostática: la fecha de vigencia registrada.
            «30 días» incluye los de 15.</span>
    </div>

    <div class="table-wrapper"><div id="tabla"></div></div>
</div>

<script>
const RANGOS = <?= json_encode(VENCE_RANGOS, JSON_UNESCAPED_UNICODE) ?>;
const EMPRESA_INICIAL = <?= $empresaInicial ?>;
let rango = <?= json_encode($rangoInicial) ?>;
let filas = [];
let enTaller = 0;
let pedido = 0;   // si se cambian filtros rápido, sólo cuenta la última respuesta

function sanitize(str) {
    if (str === null || str === undefined) return '';
    const div = document.createElement('div');
    div.textContent = String(str);
    return div.innerHTML;
}

function fecha(iso) {
    if (!iso) return '—';
    const [a, m, d] = iso.substring(0, 10).split('-');
    return `${d}/${m}/${a}`;
}

function textoDias(n) {
    if (n === 0)  return 'vence hoy';
    if (n === 1)  return 'vence mañana';
    if (n > 0)    return `vence en ${n} días`;
    if (n === -1) return 'venció ayer';
    return `venció hace ${-n} días`;
}

function filtros() {
    const q = new URLSearchParams({ rango, concepto: document.getElementById('filtroConcepto').value });
    const emp = document.getElementById('filtroEmpresa').value;
    if (emp) q.set('empresa_id', emp);
    return q;
}

function elegirRango(r) {
    rango = r;
    cargar();
}

async function cargarPlantas() {
    try {
        const r = await fetch('../api/usuarios.php?action=listar_empresas');
        const d = await r.json();
        if (d.success) {
            document.getElementById('filtroEmpresa').innerHTML = '<option value="">🏭 Todas las plantas</option>'
                + d.data.map(e => `<option value="${e.id}">${sanitize(e.nombre)}</option>`).join('');
            if (EMPRESA_INICIAL) document.getElementById('filtroEmpresa').value = String(EMPRESA_INICIAL);
        }
    } catch (e) { console.error('Error al cargar plantas:', e); }
}

async function cargar() {
    document.querySelectorAll('.rango').forEach(b => b.classList.toggle('activa', b.dataset.rango === rango));
    // Que la dirección guarde los filtros: al recargar o compartir se ve lo mismo
    history.replaceState(null, '', '?' + filtros().toString());

    const n = ++pedido;
    let d;
    try {
        const r = await fetch('../api/vencimientos.php?' + filtros().toString());
        d = await r.json();
    } catch (e) {
        d = { success: false };
    }
    if (n !== pedido) return;
    if (!d.success) {
        document.getElementById('resumen').innerHTML = '';
        document.getElementById('tabla').innerHTML =
            '<div class="empty"><div class="icon">⚠️</div><p>No se pudo cargar la lista. Intenta de nuevo.</p></div>';
        return;
    }
    filas = d.data;
    enTaller = d.en_taller;
    for (const k of Object.keys(RANGOS)) document.getElementById('n-' + k).textContent = d.conteo[k];
    pintar();
}

function filtrarPlanta(id) {
    document.getElementById('filtroEmpresa').value = String(id);
    cargar();
}

function pintar() {
    const q = document.getElementById('filtroTexto').value.trim().toLowerCase();
    const data = !q ? filas : filas.filter(x =>
        [x.codigo_manual, x.seccion, x.ubicacion, x.empresa_nombre, x.codigo_qr]
            .some(v => (v || '').toLowerCase().includes(q)));
    pintarResumen();
    pintarTabla(data);
}

function pintarResumen() {
    const caja = document.getElementById('resumen');
    const nombre = rango === 'vencidos' ? 'vencido(s)' : `que vence(n) en los ${RANGOS[rango].toLowerCase()}`;
    if (!filas.length) {
        caja.innerHTML = `<div><span class="cifra" style="color:#047857">✓ 0</span> ${rango === 'vencidos'
            ? 'extintores vencidos.' : `extintores vencen en los ${RANGOS[rango].toLowerCase()}.`}</div>`;
        return;
    }
    const porPlanta = {};
    filas.forEach(x => {
        porPlanta[x.empresa_id] = porPlanta[x.empresa_id] || { nombre: x.empresa_nombre, n: 0 };
        porPlanta[x.empresa_id].n++;
    });
    const plantas = Object.entries(porPlanta).sort((a, b) => b[1].n - a[1].n);
    const color = rango === 'vencidos' ? '#b91c1c' : (rango === '15' ? '#c2410c' : '#a16207');
    const varias = !document.getElementById('filtroEmpresa').value && plantas.length > 1;
    caja.innerHTML = `<div><span class="cifra" style="color:${color}">${filas.length}</span> extintor(es) ${nombre}
          ${enTaller ? `· <span class="b-taller">${enTaller} ya en el taller</span>` : ''}</div>
        ${varias ? plantas.map(([id, p]) => `<div class="planta">
            <button onclick="filtrarPlanta(${Number(id)})" title="Ver sólo esta planta">${sanitize(p.nombre)}</button>
            <b style="color:${color}">${p.n}</b></div>`).join('') : ''}`;
}

function pintarTabla(data) {
    const c = document.getElementById('tabla');
    if (!data.length) {
        c.innerHTML = `<div class="empty"><div class="icon">✅</div><p>${filas.length
            ? 'Ningún extintor coincide con la búsqueda.'
            : (rango === 'vencidos' ? 'No hay extintores vencidos.' : 'No vence ningún extintor en ese plazo.')}</p></div>`;
        return;
    }
    const clase = n => n < 0 ? 'vencido' : (n <= 15 ? 'pronto' : 'mes');
    c.innerHTML = `
    <div class="tabla-env"><table class="tabla">
        <thead><tr>
            <th>Código</th><th>Planta</th><th>Área</th><th>Ubicación</th>
            <th>Tipo</th><th>Cap.</th><th>Qué vence</th><th>Situación</th>
        </tr></thead>
        <tbody>
        ${data.map(x => `<tr>
            <td data-et="Código" class="td-titulo"><strong>${sanitize(x.codigo_manual || '—')}</strong></td>
            <td data-et="Planta">${sanitize(x.empresa_nombre)}</td>
            <td data-et="Área">${sanitize(x.seccion || '—')}</td>
            <td data-et="Ubicación">${sanitize(x.ubicacion || '—')}</td>
            <td data-et="Tipo">${sanitize(x.tipo_nombre || '—')}</td>
            <td data-et="Cap.">${sanitize(x.capacidad || '—')}</td>
            <td data-et="Qué vence">${x.vencimientos.map(v => `<span class="vence">${sanitize(v.nombre)}: ${fecha(v.fecha)}
                <span class="dias ${clase(v.dias)}">${textoDias(v.dias)}</span></span>`).join('')}</td>
            <td data-et="Situación">${x.taller_desde
                ? `<span class="b-taller">🛠️ En taller (${sanitize(x.taller_motivo || 'sin motivo')}) desde ${fecha(x.taller_desde)}</span>`
                : (x.estado === 'en_prestamo' ? '<span class="b-taller">En préstamo</span>' : 'En planta')}</td>
        </tr>`).join('')}
        </tbody>
    </table></div>`;
}

// Se abre con los mismos filtros que se están viendo
function descargarPDF() {
    window.open('../api/vencimientos_pdf.php?' + filtros().toString(), '_blank');
}

cargarPlantas().then(cargar);
</script>
</body>
</html>
