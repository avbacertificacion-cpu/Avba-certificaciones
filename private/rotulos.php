<?php
require_once '../config/config.php';
if (!isset($_SESSION['usuario_id']) || !in_array($_SESSION['rol'], [ROLE_ADMIN, ROLE_INSPECTOR], true)) {
    header('Location: ../public/login.html'); exit;
}
$nombre  = $_SESSION['nombre'];
$esAdmin = $_SESSION['rol'] === ROLE_ADMIN;
$volver  = $esAdmin ? 'admin-dashboard.php' : 'inspector-dashboard.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <link rel="stylesheet" href="../public/assets/css/movil.css">
    <link rel="stylesheet" href="../public/assets/leaflet/leaflet.css">
    <title>Rótulos</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Segoe UI',sans-serif;background:#f0f4ff;color:#333;padding-bottom:96px}
        button{font-family:inherit}

        .navbar{background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:#fff;padding:14px 18px;display:flex;justify-content:space-between;align-items:center;gap:12px;position:sticky;top:0;z-index:500;box-shadow:0 4px 12px rgba(102,126,234,.15)}
        .navbar h1{font-size:19px;font-weight:700}
        .navbar a{color:#fff;text-decoration:none;font-size:13px}

        .container{max-width:1100px;margin:18px auto;padding:0 14px}
        .ayuda{color:#64748b;font-size:13px;margin:-4px 0 14px;line-height:1.5}

        .barra{display:grid;grid-template-columns:1fr;gap:10px;margin-bottom:12px}
        @media(min-width:760px){.barra{grid-template-columns:1.4fr 1fr 1fr}}
        .barra select,.barra input{padding:12px 14px;border:2px solid #e0e0ff;border-radius:10px;font-size:15px;background:#fff;width:100%}
        .barra select:focus,.barra input:focus{outline:none;border-color:#667eea}

        .cola{display:none;background:#fff7ed;border:2px solid #fdba74;color:#9a3412;border-radius:12px;padding:12px 14px;margin-bottom:12px;font-size:14px;align-items:center;gap:10px;flex-wrap:wrap}
        .cola.visible{display:flex}
        .cola b{font-size:16px}
        .cola .msg{flex:1 1 220px}

        .resumen{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}
        .chip{padding:9px 14px;border-radius:20px;border:2px solid #e0e0ff;background:#fff;font-weight:700;font-size:13px;color:#475569;cursor:pointer}
        .chip.activa{background:#667eea;border-color:#667eea;color:#fff}
        .chip .n{margin-left:4px;opacity:.85}

        .vistas{display:flex;gap:8px;margin-bottom:12px}
        .vista{flex:1;padding:11px;border:2px solid #e0e0ff;background:#fff;border-radius:10px;font-weight:700;font-size:14px;color:#64748b;cursor:pointer}
        .vista.activa{border-color:#667eea;color:#4338ca;background:#eef2ff}

        #mapa{height:62vh;min-height:320px;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);display:none;background:#e2e8f0}
        .aviso-mapa{display:none;font-size:12px;color:#64748b;margin-top:6px}

        .lista{display:grid;grid-template-columns:1fr;gap:10px}
        @media(min-width:760px){.lista{grid-template-columns:1fr 1fr}}
        .punto{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.07);display:flex;gap:12px;padding:10px;cursor:pointer;border:2px solid transparent;text-align:left;width:100%;align-items:stretch}
        .punto:hover{border-color:#c7d2fe}
        .punto .mini{width:86px;height:86px;flex:0 0 86px;border-radius:9px;background:#e2e8f0 center/cover no-repeat;display:flex;align-items:center;justify-content:center;font-size:28px;color:#94a3b8}
        .punto .info{flex:1;min-width:0;display:flex;flex-direction:column;gap:3px}
        .punto .tit{font-weight:800;font-size:15px;color:#1e293b}
        .punto .ubi{font-size:13px;color:#475569;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
        .punto .meta{font-size:12px;color:#64748b;margin-top:auto;display:flex;gap:8px;flex-wrap:wrap}
        .badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:800;white-space:nowrap}
        .b-pendiente{background:#ffedd5;color:#9a3412}
        .b-colocado{background:#dcfce7;color:#166534}
        .b-local{background:#e0e7ff;color:#3730a3}
        .b-error{background:#fee2e2;color:#991b1b}
        .vacio{text-align:center;padding:46px 18px;background:#fff;border-radius:12px;color:#64748b}
        .vacio .icon{font-size:48px;margin-bottom:10px}

        .fab{position:fixed;right:18px;bottom:calc(18px + env(safe-area-inset-bottom));z-index:600;background:#16a34a;color:#fff;border:none;border-radius:30px;padding:16px 22px;font-size:16px;font-weight:800;box-shadow:0 8px 22px rgba(22,163,74,.35);cursor:pointer}
        .fab:disabled{background:#94a3b8;box-shadow:none}

        /* Ventanas: a pantalla completa en el teléfono */
        .modal{position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:1000;display:none;align-items:flex-start;justify-content:center;overflow-y:auto;padding:24px 12px}
        .modal.abierta{display:flex}
        .caja{background:#fff;border-radius:14px;width:100%;max-width:640px;padding:18px;position:relative}
        .caja h2{font-size:19px;margin-bottom:12px;padding-right:44px}
        .cerrar{position:absolute;top:10px;right:10px;width:40px;height:40px;border-radius:50%;border:none;background:#f1f5f9;font-size:20px;cursor:pointer}
        @media(max-width:640px){
            .modal{padding:0}
            .caja{border-radius:0;min-height:100vh;padding:16px 14px calc(24px + env(safe-area-inset-bottom))}
        }
        .seccion{border:1px solid #e2e8f0;border-radius:12px;padding:12px;margin-bottom:12px}
        .seccion h3{font-size:14px;color:#334155;margin-bottom:8px}
        .campo{margin-bottom:10px}
        .campo label{display:block;font-size:13px;font-weight:700;color:#475569;margin-bottom:4px}
        .campo input,.campo textarea{width:100%;padding:12px;border:2px solid #e0e0ff;border-radius:10px;font-size:16px;font-family:inherit}
        .campo textarea{min-height:84px;resize:vertical}
        .campo input:focus,.campo textarea:focus{outline:none;border-color:#667eea}
        .campo small{color:#94a3b8;font-size:12px}

        .btn{padding:12px 16px;border:none;border-radius:10px;font-weight:700;font-size:14px;cursor:pointer;min-height:44px}
        .btn-p{background:#667eea;color:#fff}
        .btn-v{background:#16a34a;color:#fff}
        .btn-g{background:#e2e8f0;color:#334155}
        .btn-r{background:#fee2e2;color:#b91c1c}
        .btn:disabled{opacity:.55;cursor:default}
        .fila-btn{display:flex;gap:8px;flex-wrap:wrap}
        .fila-btn .btn{flex:1 1 140px}

        .gps{background:#f8fafc;border-radius:10px;padding:12px;font-size:14px}
        .gps .estado{font-weight:800;font-size:16px}
        .gps .coord{font-family:ui-monospace,Menlo,monospace;font-size:13px;color:#475569;margin-top:4px;word-break:break-all}
        .gps .consejo{font-size:12px;color:#64748b;margin-top:6px;line-height:1.45}
        .p-buena{color:#15803d}.p-regular{color:#b45309}.p-mala{color:#b91c1c}
        .barra-p{height:8px;background:#e2e8f0;border-radius:6px;overflow:hidden;margin-top:8px}
        .barra-p div{height:100%;width:0;transition:width .4s}

        .fotos{display:grid;grid-template-columns:repeat(auto-fill,minmax(92px,1fr));gap:8px}
        .foto{position:relative;aspect-ratio:1;border-radius:9px;background:#e2e8f0 center/cover no-repeat;cursor:pointer;border:none}
        .foto .quitar{position:absolute;top:4px;right:4px;width:28px;height:28px;border-radius:50%;border:none;background:rgba(15,23,42,.7);color:#fff;font-size:15px;cursor:pointer}
        .foto .etq{position:absolute;left:4px;bottom:4px;font-size:10px;font-weight:800;background:rgba(22,163,74,.9);color:#fff;padding:2px 6px;border-radius:8px}
        .foto .etq.local{background:rgba(55,48,163,.9)}
        input[type=file]{display:none}

        .donde{font-size:15px;font-weight:800;color:#1e293b;margin:6px 0}
        #mapaPunto{height:220px;border-radius:10px;margin-top:8px;background:#e2e8f0}
        .coment{border-top:1px solid #f1f5f9;padding:9px 0;font-size:14px}
        .coment:first-child{border-top:none}
        .coment .quien{font-size:12px;color:#64748b;display:flex;justify-content:space-between;gap:8px}
        .coment .quien button{background:none;border:none;color:#b91c1c;font-size:12px;cursor:pointer}
        .coment .txt{white-space:pre-wrap;margin-top:2px;word-break:break-word}

        .visor{position:fixed;inset:0;background:#000;z-index:2000;display:none;align-items:center;justify-content:center}
        .visor.abierta{display:flex}
        .visor img{max-width:100%;max-height:100%;object-fit:contain}
        .visor button{position:absolute;top:calc(12px + env(safe-area-inset-top));right:12px;width:44px;height:44px;border-radius:50%;border:none;background:rgba(255,255,255,.2);color:#fff;font-size:22px}

        .aviso{position:fixed;left:50%;bottom:calc(90px + env(safe-area-inset-bottom));transform:translateX(-50%);background:#1e293b;color:#fff;padding:12px 18px;border-radius:12px;font-size:14px;z-index:3000;display:none;max-width:92vw;text-align:center}
        .aviso.visible{display:block}

        .num-mapa{background:#ea580c;color:#fff;border:2px solid #fff;border-radius:14px;font-weight:800;font-size:12px;padding:2px 7px;white-space:nowrap;box-shadow:0 1px 4px rgba(0,0,0,.4);transform:translate(-50%,-50%);display:inline-block}
        .num-mapa.colocado{background:#16a34a}
    </style>
</head>
<body>
<div class="navbar">
    <h1>📍 Rótulos</h1>
    <div><a href="<?= $volver ?>">← Volver</a>
        <span style="margin-left:14px;font-size:13px"><?= htmlspecialchars($nombre) ?></span></div>
</div>

<div class="container">
    <p class="ayuda">Marca con el GPS del teléfono dónde va cada rótulo y toma fotos del lugar. Escribe la numeración
        o cualquier indicación en los comentarios, para que cualquiera que vaya a colocarlo lo encuentre.</p>

    <div class="cola" id="cola">
        <span class="msg" id="colaMsg"></span>
        <button class="btn btn-p" id="btnSync" onclick="sincronizar(true)">Subir ahora</button>
    </div>

    <div class="barra">
        <select id="planta" onchange="cambiarPlanta()"><option value="">🏭 Elige la planta…</option></select>
        <input type="search" id="buscar" placeholder="🔍 Buscar número, rótulo o lugar…" oninput="pintar()">
        <button class="btn btn-g" onclick="imprimir()" id="btnImprimir" disabled>🖨️ Hoja para imprimir / PDF</button>
    </div>

    <div class="resumen" id="resumen"></div>

    <div class="vistas">
        <button class="vista activa" id="vLista" onclick="verVista('lista')">📋 Lista</button>
        <button class="vista" id="vMapa" onclick="verVista('mapa')">🗺️ Mapa</button>
    </div>

    <div id="lista" class="lista"></div>
    <div id="mapa"></div>
    <div class="aviso-mapa" id="avisoMapa">Si el mapa no se ve, el teléfono no tiene datos en este momento; los puntos y las fotos siguen guardándose.</div>
</div>

<button class="fab" id="fab" onclick="abrirNuevo()" disabled>＋ Nuevo punto</button>

<!-- Alta / edición -->
<div class="modal" id="mForm">
    <div class="caja">
        <button class="cerrar" onclick="cerrarForm()" aria-label="Cerrar">✕</button>
        <h2 id="fTitulo">Nuevo punto</h2>

        <div class="seccion">
            <h3>1. Ubicación GPS</h3>
            <div class="gps" id="gpsCaja"></div>
            <div class="fila-btn" style="margin-top:8px">
                <button class="btn btn-g" id="btnGps" onclick="iniciarGps()">📡 Tomar ubicación</button>
                <button class="btn btn-v" id="btnFijar" onclick="fijarGps()" style="display:none">✓ Usar esta ubicación</button>
            </div>
        </div>

        <div class="seccion" id="fSecFotos">
            <h3>2. Fotos del lugar</h3>
            <div class="fila-btn" style="margin-bottom:8px">
                <label class="btn btn-p" style="text-align:center;display:flex;align-items:center;justify-content:center">📷 Tomar foto
                    <input type="file" accept="image/*" capture="environment" onchange="agregarFotosForm(this)"></label>
                <label class="btn btn-g" style="text-align:center;display:flex;align-items:center;justify-content:center">🖼️ De la galería
                    <input type="file" accept="image/*" multiple onchange="agregarFotosForm(this)"></label>
            </div>
            <div class="fotos" id="fFotos"></div>
        </div>

        <div class="seccion">
            <h3><span id="fPaso">3.</span> Datos del rótulo</h3>
            <div class="campo"><label for="fNumero">Numeración</label>
                <input id="fNumero" maxlength="40" placeholder="Ej. 014 o EXT-014" autocomplete="off">
                <small>El número que llevará el rótulo o el extintor. Se sugiere el siguiente de la planta.</small></div>
            <div class="campo"><label for="fRotulo">Rótulo</label>
                <input id="fRotulo" maxlength="120" list="tiposRotulo" placeholder="Ej. EXTINTOR PQS" autocomplete="off">
                <datalist id="tiposRotulo">
                    <option value="EXTINTOR"><option value="EXTINTOR PQS"><option value="EXTINTOR CO2">
                    <option value="EXTINTOR TIPO K"><option value="HIDRANTE"><option value="RUTA DE EVACUACIÓN">
                    <option value="SALIDA DE EMERGENCIA"><option value="PUNTO DE REUNIÓN"><option value="BOTIQUÍN">
                </datalist></div>
            <div class="campo"><label for="fUbicacion">¿Dónde exactamente?</label>
                <input id="fUbicacion" maxlength="255" placeholder="Ej. Nave 2, columna C-4, junto al tablero" autocomplete="off"></div>
            <div class="campo" id="fCampoComentario"><label for="fComentario">Comentarios</label>
                <textarea id="fComentario" maxlength="2000" placeholder="Altura, numeración, qué tapa, quién autorizó…"></textarea></div>
        </div>

        <div class="fila-btn">
            <button class="btn btn-g" onclick="cerrarForm()">Cancelar</button>
            <button class="btn btn-v" id="btnGuardar" onclick="guardarForm()">💾 Guardar punto</button>
        </div>
    </div>
</div>

<!-- Detalle -->
<div class="modal" id="mDetalle">
    <div class="caja">
        <button class="cerrar" onclick="cerrarDetalle()" aria-label="Cerrar">✕</button>
        <div id="detalle"></div>
    </div>
</div>

<div class="visor" id="visor" onclick="cerrarVisor()"><img id="visorImg" alt=""><button aria-label="Cerrar">✕</button></div>
<div class="aviso" id="aviso"></div>

<script src="../public/assets/leaflet/leaflet.js"></script>
<script>
const ES_ADMIN = <?= $esAdmin ? 'true' : 'false' ?>;
const MI_ID    = <?= (int) $_SESSION['usuario_id'] ?>;
const API      = '../api/rotulos.php';

let plantas = [], puntos = [], filtroEstado = 'todos', vista = 'lista';
let mapa = null, capaPuntos = null, miPosMarca = null, vigiaMapa = null;

// ─── Utilidades ──────────────────────────────────────────────────────────────
function esc(s) {
    if (s === null || s === undefined) return '';
    const d = document.createElement('div'); d.textContent = String(s); return d.innerHTML;
}
function uuid() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    return 'x' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12) + '-' + Math.random().toString(36).slice(2, 12);
}
function avisar(t, ms = 3200) {
    const a = document.getElementById('aviso'); a.textContent = t; a.classList.add('visible');
    clearTimeout(avisar.t); avisar.t = setTimeout(() => a.classList.remove('visible'), ms);
}
function fechaHora(s) {
    if (!s) return '';
    const [f, h] = s.split(' '); const [a, m, d] = f.split('-');
    return `${d}/${m}/${a}${h ? ' ' + h.slice(0, 5) : ''}`;
}
const urlFoto = (id, mini) => `${API}?action=foto&id=${id}${mini ? '&mini=1' : ''}`;
const enlaceMapa = (lat, lng) => `https://www.google.com/maps/search/?api=1&query=${lat},${lng}`;

/** Petición a la API. Un error de red se distingue de un «no» del servidor. */
async function api(accion, { metodo = 'GET', datos = null, form = null, query = '' } = {}) {
    let r;
    try {
        r = await fetch(`${API}?action=${accion}${query}`, {
            method: form || datos ? 'POST' : metodo,
            headers: datos ? { 'Content-Type': 'application/json' } : undefined,
            body: form || (datos ? JSON.stringify(datos) : undefined),
            credentials: 'same-origin',
        });
    } catch (e) {
        const err = new Error('Sin conexión'); err.sinRed = true; throw err;
    }
    let j = null;
    try { j = await r.json(); } catch (e) {}
    if (!r.ok || !j || !j.success) {
        const err = new Error((j && j.error) || `El servidor respondió ${r.status}`);
        err.estado = r.status;
        err.sinRed = r.status >= 500 || r.status === 0 || !j;
        err.sesion = r.status === 401;
        throw err;
    }
    return j;
}

// ─── Fotos: se reducen en el teléfono antes de subir ─────────────────────────
// Una foto de teléfono pesa 3–8 MB; reducida a 1600 px queda en ~300 KB, que
// sube aunque la señal dentro de la planta sea mala.
async function reducirFoto(file, lado = 1600) {
    let fuente, ancho, alto;
    try {
        fuente = await createImageBitmap(file, { imageOrientation: 'from-image' });
        ancho = fuente.width; alto = fuente.height;
    } catch (e) {
        fuente = await new Promise((ok, mal) => {
            const img = new Image(); img.onload = () => ok(img); img.onerror = mal;
            img.src = URL.createObjectURL(file);
        });
        ancho = fuente.naturalWidth; alto = fuente.naturalHeight;
    }
    const k = Math.min(1, lado / Math.max(ancho, alto));
    const c = document.createElement('canvas');
    c.width = Math.round(ancho * k); c.height = Math.round(alto * k);
    c.getContext('2d').drawImage(fuente, 0, 0, c.width, c.height);
    if (fuente.close) fuente.close();
    return await new Promise(ok => c.toBlob(b => ok(b), 'image/jpeg', 0.82));
}

// ─── Cola en el teléfono (IndexedDB) ─────────────────────────────────────────
// Todo lo que se captura se guarda primero en el teléfono y luego se sube. Si
// en la planta no hay señal, se queda aquí hasta que la haya: no se pierde
// aunque se cierre el navegador.
const Cola = {
    db: null,
    async abrir() {
        if (this.db !== null) return this.db;
        this.db = await new Promise(ok => {
            try {
                const r = indexedDB.open('avba-rotulos', 1);
                r.onupgradeneeded = () => r.result.createObjectStore('ops', { keyPath: 'id', autoIncrement: true });
                r.onsuccess = () => ok(r.result);
                r.onerror = () => ok(false);
            } catch (e) { ok(false); }
        });
        return this.db;
    },
    async tx(modo, fn) {
        const db = await this.abrir();
        if (!db) throw new Error('sin-indexeddb');
        return await new Promise((ok, mal) => {
            const t = db.transaction('ops', modo); const s = t.objectStore('ops');
            const r = fn(s); t.oncomplete = () => ok(r && r.result); t.onerror = () => mal(t.error);
        });
    },
    poner(op)    { return this.tx('readwrite', s => s.put(op)); },
    quitar(id)   { return this.tx('readwrite', s => s.delete(id)); },
    async todas() {
        let ops = [];
        try { ops = (await this.tx('readonly', s => s.getAll())) || []; } catch (e) {}
        return ops.concat(memoria);
    },
};
let memoria = [];   // si el navegador no deja usar IndexedDB, la cola vive sólo en esta página
let colaOps = [];
let sincronizando = false;

async function encolar(op) {
    op.creado = Date.now();
    try { op.id = await Cola.poner(op); }
    catch (e) { op.id = -(Date.now() + memoria.length); memoria.push(op); }   // negativos: sólo en memoria
    await refrescarCola();
}
async function quitarDeCola(op) {
    if (op.id < 0) memoria = memoria.filter(o => o.id !== op.id);
    else await Cola.quitar(op.id);
}
async function guardarEnCola(op) {
    if (op.id > 0) await Cola.poner(op);
}

async function refrescarCola() {
    colaOps = await Cola.todas();
    const caja = document.getElementById('cola');
    const n = colaOps.length;
    caja.classList.toggle('visible', n > 0);
    if (n) {
        const conError = colaOps.filter(o => o.error).length;
        const fotos = colaOps.reduce((s, o) => s + (o.fotos ? o.fotos.length : 0), 0);
        document.getElementById('colaMsg').innerHTML = `📱 <b>${n}</b> cambio(s) guardado(s) en este teléfono`
            + (fotos ? ` con ${fotos} foto(s)` : '') + ', <b>por subir</b>. '
            + (conError ? `<br>⚠️ ${conError} no se pudo(eron) subir. <a href="#" onclick="revisarErrores();return false">Ver qué pasó</a>` : 'Se suben solos cuando haya señal.');
    }
    document.getElementById('btnSync').disabled = sincronizando;
}

/** Lo que el servidor rechazó (p. ej. el punto se borró): se dice qué fue y se puede descartar. */
async function revisarErrores() {
    const malos = colaOps.filter(o => o.error);
    const lista = malos.map(o => `• ${o.tipo === 'punto' ? 'Punto ' + (o.datos.numero || '') : o.tipo === 'fotos' ? o.fotos.length + ' foto(s)' : 'Comentario'}: ${o.error}`).join('\n');
    if (confirm(`No se pudieron subir:\n${lista}\n\n¿Descartarlos del teléfono? (Cancelar = volver a intentar más tarde)`)) {
        for (const o of malos) await quitarDeCola(o);
        await refrescarCola(); pintar();
    }
}

/** Sube lo pendiente, una cosa a la vez y en orden. */
async function sincronizar(aMano = false) {
    if (sincronizando) return;
    sincronizando = true; await refrescarCola();
    let subidos = 0, sinRed = false, sesion = false;
    try {
        const ops = (await Cola.todas()).sort((a, b) => a.creado - b.creado);
        for (const op of ops) {
            if (op.error && !aMano) continue;
            try {
                await ejecutar(op);
                await quitarDeCola(op); subidos++;
            } catch (e) {
                if (e.sesion) { sesion = true; break; }
                if (e.sinRed) { sinRed = true; break; }
                op.error = e.message; await guardarEnCola(op);   // el servidor lo rechazó: que lo vea la persona
            }
        }
    } finally {
        sincronizando = false;
        await refrescarCola();
    }
    if (sesion) avisar('Tu sesión terminó. Vuelve a entrar al sistema y lo pendiente se subirá.', 6000);
    else if (sinRed && aMano) avisar('Todavía no hay señal. Se queda guardado en el teléfono.');
    if (subidos) { avisar(`✓ Se subieron ${subidos} cambio(s)`); await cargar(); }
}

async function ejecutar(op) {
    if (op.tipo === 'punto') {
        const r = await api('guardar', { datos: op.datos });
        op.punto_id = r.id;
        await subirFotos(op, r.id);
    } else if (op.tipo === 'fotos') {
        await subirFotos(op, op.punto_id);
    } else if (op.tipo === 'comentario') {
        await api('comentar', { datos: op.datos });
    }
}
async function subirFotos(op, puntoId) {
    while (op.fotos && op.fotos.length) {
        const f = op.fotos[0];
        const form = new FormData();
        form.append('punto_id', puntoId);
        form.append('etapa', op.etapa || 'referencia');
        form.append('cliente_uuid', f.uuid);
        form.append('foto', f.blob, 'foto.jpg');
        await api('subir_foto', { form });
        op.fotos.shift();
        await guardarEnCola(op);   // si se corta a la mitad, no se vuelve a subir la que ya subió
    }
}

window.addEventListener('online', () => sincronizar());
document.addEventListener('visibilitychange', () => { if (!document.hidden) sincronizar(); });

// ─── Plantas y puntos ────────────────────────────────────────────────────────
async function cargarPlantas() {
    try {
        const r = await fetch('../api/usuarios.php?action=listar_empresas');
        const d = await r.json();
        plantas = d.success ? d.data : [];
        localStorage.setItem('rotulos.plantas', JSON.stringify(plantas.map(p => ({ id: p.id, nombre: p.nombre }))));
    } catch (e) {
        try { plantas = JSON.parse(localStorage.getItem('rotulos.plantas') || '[]'); } catch (e2) { plantas = []; }
    }
    const sel = document.getElementById('planta');
    sel.innerHTML = '<option value="">🏭 Elige la planta…</option>'
        + plantas.map(p => `<option value="${p.id}">${esc(p.nombre)}</option>`).join('');
    let ultima = '';
    try { ultima = localStorage.getItem('rotulos.planta') || ''; } catch (e) {}
    if (ultima && plantas.some(p => String(p.id) === ultima)) sel.value = ultima;
}

function plantaActual() { return document.getElementById('planta').value; }

async function cambiarPlanta() {
    try { localStorage.setItem('rotulos.planta', plantaActual()); } catch (e) {}
    await cargar();
}

async function cargar() {
    const emp = plantaActual();
    document.getElementById('fab').disabled = !emp;
    document.getElementById('btnImprimir').disabled = !emp;
    if (!emp) { puntos = []; pintar(); return; }
    try {
        const r = await api('listar', { query: `&empresa_id=${emp}` });
        puntos = r.data;
        try { localStorage.setItem('rotulos.cache.' + emp, JSON.stringify(puntos)); } catch (e) {}
    } catch (e) {
        // Sin señal: lo último que se vio de esta planta
        try { puntos = JSON.parse(localStorage.getItem('rotulos.cache.' + emp) || '[]'); } catch (e2) { puntos = []; }
        if (e.sinRed) avisar('Sin conexión: se muestra lo último que se cargó.');
        else if (e.sesion) avisar('Tu sesión terminó. Vuelve a entrar al sistema.', 6000);
    }
    await refrescarCola();
    pintar();
}

/** Los puntos del servidor más los que siguen en el teléfono. */
function todosLosPuntos() {
    const emp = plantaActual();
    const locales = colaOps.filter(o => o.tipo === 'punto' && String(o.datos.empresa_id) === emp).map(o => ({
        local: o, id: 'L' + o.id, numero: o.datos.numero, rotulo: o.datos.rotulo, ubicacion: o.datos.ubicacion,
        lat: o.datos.lat, lng: o.datos.lng, precision_m: o.datos.precision_m, estado: 'pendiente',
        fotos: o.fotos.length, comentarios: o.datos.comentario ? 1 : 0,
    }));
    return [...locales, ...puntos];
}

function verVista(v) {
    vista = v;
    document.getElementById('vLista').classList.toggle('activa', v === 'lista');
    document.getElementById('vMapa').classList.toggle('activa', v === 'mapa');
    document.getElementById('lista').style.display = v === 'lista' ? '' : 'none';
    document.getElementById('mapa').style.display = v === 'mapa' ? 'block' : 'none';
    document.getElementById('avisoMapa').style.display = v === 'mapa' ? 'block' : 'none';
    if (v === 'mapa') { prepararMapa(); setTimeout(() => { mapa.invalidateSize(); pintarMapa(true); }, 50); }
    else if (vigiaMapa !== null) { navigator.geolocation.clearWatch(vigiaMapa); vigiaMapa = null; }
}

function filtrados() {
    const q = document.getElementById('buscar').value.trim().toLowerCase();
    return todosLosPuntos().filter(p =>
        (filtroEstado === 'todos' || p.estado === filtroEstado) &&
        (!q || [p.numero, p.rotulo, p.ubicacion].some(v => (v || '').toLowerCase().includes(q))));
}

function pintar() {
    const todos = todosLosPuntos();
    const n = e => todos.filter(p => e === 'todos' || p.estado === e).length;
    document.getElementById('resumen').innerHTML = plantaActual() ? [['todos', 'Todos'], ['pendiente', 'Por colocar'], ['colocado', 'Colocados']]
        .map(([k, t]) => `<button class="chip ${filtroEstado === k ? 'activa' : ''}" onclick="filtroEstado='${k}';pintar()">${t}<span class="n">${n(k)}</span></button>`).join('') : '';

    const c = document.getElementById('lista');
    if (!plantaActual()) {
        c.innerHTML = '<div class="vacio"><div class="icon">🏭</div><p>Elige la planta para ver sus puntos o agregar uno nuevo.</p></div>';
    } else {
        const data = filtrados();
        c.innerHTML = !data.length
            ? `<div class="vacio"><div class="icon">📍</div><p>${todos.length ? 'Ningún punto coincide.' : 'Esta planta todavía no tiene puntos. Toca «＋ Nuevo punto» para marcar el primero.'}</p></div>`
            : data.map(p => {
                const local = !!p.local;
                const mini = !local && p.foto_id ? `style="background-image:url('${urlFoto(p.foto_id, true)}')"` : '';
                return `<button class="punto" onclick="${local ? `verLocal(${JSON.stringify(p.local.id)})` : `abrirDetalle(${p.id})`}">
                    <div class="mini" ${mini}>${mini ? '' : (local ? '📱' : '📍')}</div>
                    <div class="info">
                        <div class="tit">${p.numero ? '#' + esc(p.numero) + ' · ' : ''}${esc(p.rotulo || 'Rótulo')}</div>
                        <div class="ubi">${esc(p.ubicacion || 'Sin descripción del lugar')}</div>
                        <div class="meta">
                            ${local ? (p.local.error ? '<span class="badge b-error">⚠️ No se pudo subir</span>' : '<span class="badge b-local">📱 Por subir</span>')
                                    : `<span class="badge b-${p.estado}">${p.estado === 'colocado' ? '✓ Colocado' : 'Por colocar'}</span>`}
                            <span>📷 ${p.fotos}</span><span>💬 ${p.comentarios}</span>
                            ${p.lat !== null && p.lat !== undefined ? `<span>📡 ±${Math.round(p.precision_m || 0)} m</span>` : '<span>Sin GPS</span>'}
                        </div>
                    </div></button>`;
            }).join('');
    }
    if (vista === 'mapa' && mapa) pintarMapa(false);
}

// ─── Mapa ────────────────────────────────────────────────────────────────────
function prepararMapa() {
    if (mapa || typeof L === 'undefined') return;
    mapa = L.map('mapa', { zoomControl: true }).setView([23.6, -102.5], 5);
    // Satélite primero: dentro de una planta se reconocen los edificios, no las calles
    const sat = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
        { maxZoom: 20, maxNativeZoom: 19, attribution: 'Imágenes © Esri' });
    const calles = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',
        { maxZoom: 20, maxNativeZoom: 19, attribution: '© OpenStreetMap' });
    sat.addTo(mapa);
    L.control.layers({ 'Satélite': sat, 'Mapa': calles }).addTo(mapa);
    capaPuntos = L.layerGroup().addTo(mapa);
    // Dónde estoy yo, para caminar hacia el punto
    if (navigator.geolocation) {
        vigiaMapa = navigator.geolocation.watchPosition(pos => {
            const ll = [pos.coords.latitude, pos.coords.longitude];
            if (!miPosMarca) miPosMarca = L.circleMarker(ll, { radius: 8, color: '#fff', weight: 3, fillColor: '#2563eb', fillOpacity: 1 }).addTo(mapa).bindTooltip('Tú');
            else miPosMarca.setLatLng(ll);
        }, () => {}, { enableHighAccuracy: true, maximumAge: 5000 });
    }
}

function pintarMapa(encuadrar) {
    if (!mapa) return;
    capaPuntos.clearLayers();
    const conGps = filtrados().filter(p => p.lat !== null && p.lat !== undefined);
    conGps.forEach(p => {
        const ll = [Number(p.lat), Number(p.lng)];
        if (p.precision_m) L.circle(ll, { radius: Number(p.precision_m), color: '#ea580c', weight: 1, fillOpacity: .08 }).addTo(capaPuntos);
        const icono = L.divIcon({ className: '', html: `<span class="num-mapa ${p.estado === 'colocado' ? 'colocado' : ''}">${esc(p.numero || '•')}</span>`, iconSize: null });
        L.marker(ll, { icon: icono }).addTo(capaPuntos)
            .on('click', () => p.local ? verLocal(p.local.id) : abrirDetalle(p.id));
    });
    if (encuadrar && conGps.length) mapa.fitBounds(L.latLngBounds(conGps.map(p => [Number(p.lat), Number(p.lng)])).pad(0.3), { maxZoom: 19 });
}

// ─── GPS ─────────────────────────────────────────────────────────────────────
// El teléfono va afinando la posición durante unos segundos; se queda con la
// lectura más precisa. Dentro de una nave puede errar decenas de metros: por
// eso se muestra la precisión y las fotos son la referencia principal.
let gps = { vigia: null, mejor: null, fija: null, limite: null };

function calidad(m) {
    if (m <= 10) return ['p-buena', 'Buena', '#16a34a', 100];
    if (m <= 25) return ['p-regular', 'Regular', '#d97706', 60];
    return ['p-mala', 'Baja', '#dc2626', 25];
}

function pintarGps(msg) {
    const caja = document.getElementById('gpsCaja');
    const g = gps.fija || gps.mejor;
    if (msg) { caja.innerHTML = msg; return; }
    if (!g) {
        caja.innerHTML = '<div class="estado">Sin ubicación</div><div class="consejo">Toca «Tomar ubicación» parado donde va el rótulo.</div>';
        return;
    }
    const [cls, txt, color, pct] = calidad(g.precision);
    caja.innerHTML = `<div class="estado ${cls}">${gps.fija ? '📍 Ubicación fijada' : '📡 Afinando…'} · ±${Math.round(g.precision)} m (${txt})</div>
        <div class="coord">${g.lat.toFixed(6)}, ${g.lng.toFixed(6)}</div>
        <div class="barra-p"><div style="width:${pct}%;background:${color}"></div></div>
        <div class="consejo">${g.precision > 25
            ? 'Precisión baja: si puedes, espera unos segundos o acércate a una puerta o ventana. Dentro de naves el GPS puede errar bastante; las fotos serán la referencia.'
            : (gps.fija ? 'Se guardará con esta posición.' : 'Espera a que baje el ± o toca «Usar esta ubicación».')}</div>`;
}

function iniciarGps() {
    if (!navigator.geolocation) { pintarGps('<div class="estado p-mala">Este navegador no da ubicación.</div>'); return; }
    if (!window.isSecureContext) { pintarGps('<div class="estado p-mala">La ubicación sólo funciona entrando al sistema con https://</div>'); return; }
    detenerGps();
    gps.mejor = null; gps.fija = null;
    form.gpsNuevo = true;
    pintarGps('<div class="estado">📡 Buscando señal GPS…</div><div class="consejo">La primera vez el teléfono pide permiso para usar tu ubicación: acéptalo.</div>');
    document.getElementById('btnGps').textContent = '🔄 Volver a tomar';
    gps.vigia = navigator.geolocation.watchPosition(pos => {
        const l = { lat: pos.coords.latitude, lng: pos.coords.longitude, precision: pos.coords.accuracy };
        if (!gps.mejor || l.precision <= gps.mejor.precision) gps.mejor = l;
        document.getElementById('btnFijar').style.display = '';
        pintarGps();
        if (gps.mejor.precision <= 5) fijarGps();   // ya no va a mejorar
    }, err => {
        const msg = err.code === 1
            ? 'No diste permiso de ubicación. Actívalo en el candado de la barra de dirección (o en Ajustes › Safari › Ubicación en iPhone) y vuelve a intentar.'
            : 'El teléfono no pudo obtener la ubicación. Revisa que el GPS esté encendido.';
        if (!gps.mejor) pintarGps(`<div class="estado p-mala">⚠️ ${msg}</div>`);
    }, { enableHighAccuracy: true, maximumAge: 0, timeout: 30000 });
    gps.limite = setTimeout(() => { if (gps.mejor) fijarGps(); }, 45000);
}
function fijarGps() {
    if (!gps.mejor) return;
    gps.fija = gps.mejor;
    detenerGps();
    document.getElementById('btnFijar').style.display = 'none';
    pintarGps();
}
function detenerGps() {
    if (gps.vigia !== null) navigator.geolocation.clearWatch(gps.vigia);
    clearTimeout(gps.limite);
    gps.vigia = null;
}

// ─── Alta / edición ──────────────────────────────────────────────────────────
let form = { id: null, fotos: [], gpsNuevo: false };

function abrirNuevo() {
    if (!plantaActual()) return;
    form = { id: null, fotos: [], gpsNuevo: false };
    document.getElementById('fTitulo').textContent = 'Nuevo punto';
    document.getElementById('fSecFotos').style.display = '';
    document.getElementById('fCampoComentario').style.display = '';
    document.getElementById('fPaso').textContent = '3.';
    ['fNumero', 'fRotulo', 'fUbicacion', 'fComentario'].forEach(i => document.getElementById(i).value = '');
    document.getElementById('fRotulo').value = localStorage.getItem('rotulos.ultimoRotulo') || 'EXTINTOR';
    pintarFotosForm();
    document.getElementById('mForm').classList.add('abierta');
    iniciarGps();
    // Numeración sugerida: la siguiente de la planta, con el mismo formato
    // que la más alta (tras «T-902» va «T-903»; tras «007», «008»)
    const sig = siguienteNumero(todosLosPuntos().map(p => p.numero));
    if (!document.getElementById('fNumero').value) document.getElementById('fNumero').value = sig;
}

function siguienteNumero(numeros) {
    let mejor = null;
    for (const n of numeros) {
        const m = String(n || '').match(/^(.*?)(\d+)(\D*)$/);
        if (m && (!mejor || parseInt(m[2], 10) > parseInt(mejor[2], 10))) mejor = m;
    }
    if (!mejor) return '001';
    const num = String(parseInt(mejor[2], 10) + 1).padStart(mejor[2].length, '0');
    return mejor[1] + num + mejor[3];
}

function abrirEdicion(p) {
    form = { id: p.id, fotos: [], gpsNuevo: false };
    document.getElementById('fTitulo').textContent = 'Editar punto';
    document.getElementById('fSecFotos').style.display = 'none';
    document.getElementById('fCampoComentario').style.display = 'none';
    document.getElementById('fPaso').textContent = '2.';
    document.getElementById('fNumero').value = p.numero || '';
    document.getElementById('fRotulo').value = p.rotulo || '';
    document.getElementById('fUbicacion').value = p.ubicacion || '';
    detenerGps();
    gps.mejor = null;
    gps.fija = p.lat !== null ? { lat: Number(p.lat), lng: Number(p.lng), precision: Number(p.precision_m || 0) } : null;
    document.getElementById('btnGps').textContent = p.lat !== null ? '🔄 Volver a tomar aquí' : '📡 Tomar ubicación';
    document.getElementById('btnFijar').style.display = 'none';
    pintarGps(gps.fija ? null : undefined);
    if (gps.fija) document.querySelector('#gpsCaja .consejo').textContent = 'Es la posición guardada. Si estás parado en el lugar correcto, puedes volver a tomarla.';
    cerrarDetalle(true);
    document.getElementById('mForm').classList.add('abierta');
}

function cerrarForm() {
    detenerGps();
    document.getElementById('mForm').classList.remove('abierta');
}

async function agregarFotosForm(input) {
    const archivos = [...input.files]; input.value = '';
    for (const f of archivos) {
        try {
            const blob = await reducirFoto(f);
            form.fotos.push({ uuid: uuid(), blob, url: URL.createObjectURL(blob) });
        } catch (e) { avisar('No se pudo leer una de las fotos.'); }
    }
    pintarFotosForm();
}
function pintarFotosForm() {
    document.getElementById('fFotos').innerHTML = form.fotos.map((f, i) =>
        `<div class="foto" style="background-image:url('${f.url}')"><button class="quitar" onclick="quitarFotoForm(${i})" aria-label="Quitar">✕</button></div>`).join('')
        || '<div style="grid-column:1/-1;color:#94a3b8;font-size:13px">Toma una o varias fotos: de cerca y de lejos, para que se vea el lugar y la referencia.</div>';
}
function quitarFotoForm(i) { URL.revokeObjectURL(form.fotos[i].url); form.fotos.splice(i, 1); pintarFotosForm(); }

async function guardarForm() {
    const g = gps.fija || gps.mejor;
    const datos = {
        numero: document.getElementById('fNumero').value.trim(),
        rotulo: document.getElementById('fRotulo').value.trim(),
        ubicacion: document.getElementById('fUbicacion').value.trim(),
    };
    if (!form.id && !g && !form.fotos.length) { avisar('Toma la ubicación o al menos una foto.'); return; }
    // Al editar, la posición sólo se manda si se volvió a tomar
    if (g && (!form.id || form.gpsNuevo)) Object.assign(datos, { lat: g.lat, lng: g.lng, precision_m: Math.round(g.precision * 10) / 10 });
    if (datos.rotulo) try { localStorage.setItem('rotulos.ultimoRotulo', datos.rotulo); } catch (e) {}
    const btn = document.getElementById('btnGuardar'); btn.disabled = true;

    try {
        if (form.id) {
            datos.id = form.id;
            await api('guardar', { datos });
            cerrarForm(); avisar('✓ Cambios guardados');
            await cargar(); abrirDetalle(form.id);
        } else {
            datos.empresa_id = plantaActual();
            datos.comentario = document.getElementById('fComentario').value.trim();
            datos.cliente_uuid = uuid();
            // Primero al teléfono, luego al servidor: si no hay señal no se pierde
            await encolar({ tipo: 'punto', datos, fotos: form.fotos.map(f => ({ uuid: f.uuid, blob: f.blob })) });
            form.fotos.forEach(f => URL.revokeObjectURL(f.url));
            cerrarForm(); pintar();
            avisar('Guardado. Subiendo…');
            await sincronizar();
            if ((await Cola.todas()).some(o => o.datos && o.datos.cliente_uuid === datos.cliente_uuid)) {
                avisar('📱 Sin señal: el punto quedó guardado en el teléfono y se subirá solo.', 5000);
            }
        }
    } catch (e) {
        avisar(e.sinRed ? 'Sin señal: no se pudieron guardar los cambios. Inténtalo cuando haya conexión.' : e.message, 5000);
    } finally { btn.disabled = false; }
}

// ─── Punto que sigue en el teléfono ──────────────────────────────────────────
function verLocal(id) {
    const op = colaOps.find(o => o.id === id); if (!op) return;
    const d = op.datos;
    document.getElementById('detalle').innerHTML = `
        <h2>${d.numero ? '#' + esc(d.numero) + ' · ' : ''}${esc(d.rotulo || 'Rótulo')}</h2>
        <p style="margin-bottom:10px"><span class="badge ${op.error ? 'b-error' : 'b-local'}">${op.error ? '⚠️ No se pudo subir' : '📱 Guardado en el teléfono, por subir'}</span></p>
        ${op.error ? `<p style="color:#991b1b;margin-bottom:10px">El servidor respondió: ${esc(op.error)}</p>` : ''}
        <div class="seccion"><div class="donde">${esc(d.ubicacion || 'Sin descripción del lugar')}</div>
            ${d.lat !== undefined ? `<div class="coord">${Number(d.lat).toFixed(6)}, ${Number(d.lng).toFixed(6)} · ±${Math.round(d.precision_m)} m</div>` : 'Sin GPS'}
            ${d.comentario ? `<p style="margin-top:8px;white-space:pre-wrap">${esc(d.comentario)}</p>` : ''}</div>
        <div class="fotos" style="margin-bottom:12px">${op.fotos.map(f => `<div class="foto" style="background-image:url('${URL.createObjectURL(f.blob)}')"></div>`).join('')}</div>
        <div class="fila-btn">
            <button class="btn btn-p" onclick="cerrarDetalle();sincronizar(true)">⬆️ Intentar subir ahora</button>
            <button class="btn btn-r" onclick="descartarLocal(${JSON.stringify(op.id)})">Descartar</button>
        </div>`;
    document.getElementById('mDetalle').classList.add('abierta');
}
async function descartarLocal(id) {
    if (!confirm('¿Descartar este punto? Se borrará del teléfono con sus fotos y no se podrá recuperar.')) return;
    const op = colaOps.find(o => o.id === id); if (op) await quitarDeCola(op);
    cerrarDetalle(); await refrescarCola(); pintar();
}

// ─── Detalle ─────────────────────────────────────────────────────────────────
let actual = null, vigiaDetalle = null, mapaPunto = null;

async function abrirDetalle(id) {
    let p;
    try { p = (await api('obtener', { query: `&id=${id}` })).data; }
    catch (e) { avisar(e.sinRed ? 'Sin conexión: no se puede abrir el detalle ahora.' : e.message); return; }
    actual = p;
    const gpsOk = p.lat !== null;
    const ref = p.fotos.filter(f => f.etapa !== 'colocado'), col = p.fotos.filter(f => f.etapa === 'colocado');
    const foto = f => `<div class="foto" style="background-image:url('${urlFoto(f.id, true)}')" onclick="verFoto(${f.id})">
        ${f.etapa === 'colocado' ? '<span class="etq">Colocado</span>' : ''}
        ${ES_ADMIN || f.subido_por == MI_ID ? `<button class="quitar" onclick="event.stopPropagation();borrarFoto(${f.id})" aria-label="Borrar">✕</button>` : ''}</div>`;
    const pendientes = colaOps.filter(o => o.tipo === 'fotos' && o.punto_id == p.id).reduce((s, o) => s + o.fotos.length, 0);

    document.getElementById('detalle').innerHTML = `
        <h2>${p.numero ? '#' + esc(p.numero) + ' · ' : ''}${esc(p.rotulo || 'Rótulo')}</h2>
        <p style="margin-bottom:10px">
            <span class="badge b-${p.estado}">${p.estado === 'colocado' ? '✓ Colocado' : 'Por colocar'}</span>
            <span style="font-size:12px;color:#64748b;margin-left:6px">${esc(p.empresa_nombre)}</span></p>

        <div class="seccion">
            <h3>📍 Dónde va</h3>
            <div class="donde">${esc(p.ubicacion || 'Sin descripción del lugar')}</div>
            ${gpsOk ? `
                <div class="gps"><div class="coord">${Number(p.lat).toFixed(6)}, ${Number(p.lng).toFixed(6)} · precisión ±${Math.round(p.precision_m || 0)} m</div>
                    <div class="estado" id="distancia" style="font-size:15px;margin-top:6px">📡 Calculando qué tan lejos estás…</div></div>
                <div id="mapaPunto"></div>
                <div class="fila-btn" style="margin-top:8px">
                    <a class="btn btn-p" style="text-align:center;text-decoration:none" href="${esc(p.mapa)}" target="_blank" rel="noopener">🧭 Abrir en Google Maps</a>
                    <button class="btn btn-g" onclick="compartir()">📤 Compartir</button>
                </div>` : '<p style="color:#b45309;font-size:13px">Este punto no tiene ubicación GPS. Edítalo parado en el lugar para tomarla.</p>'}
        </div>

        <div class="seccion">
            <h3>📷 Fotos de referencia (${ref.length})${pendientes ? ` · <span class="badge b-local">📱 ${pendientes} por subir</span>` : ''}</h3>
            <div class="fotos">${ref.map(foto).join('') || '<span style="color:#94a3b8;font-size:13px">Sin fotos todavía.</span>'}</div>
            ${col.length ? `<h3 style="margin-top:12px">✓ Fotos ya colocado (${col.length})</h3><div class="fotos">${col.map(foto).join('')}</div>` : ''}
            <div class="fila-btn" style="margin-top:10px">
                <label class="btn btn-g" style="display:flex;align-items:center;justify-content:center">📷 Agregar foto
                    <input type="file" accept="image/*" capture="environment" onchange="agregarFotosPunto(this,'referencia')"></label>
                <label class="btn btn-g" style="display:flex;align-items:center;justify-content:center">🖼️ De la galería
                    <input type="file" accept="image/*" multiple onchange="agregarFotosPunto(this,'referencia')"></label>
            </div>
        </div>

        <div class="seccion">
            <h3>💬 Comentarios (${p.comentarios.length})</h3>
            <div>${p.comentarios.map(c => `<div class="coment">
                <div class="quien"><span><b>${esc(c.autor || '—')}</b> · ${fechaHora(c.created_at)}</span>
                    ${ES_ADMIN || c.usuario_id == MI_ID ? `<button onclick="borrarComentario(${c.id})">Borrar</button>` : ''}</div>
                <div class="txt">${esc(c.texto)}</div></div>`).join('') || '<span style="color:#94a3b8;font-size:13px">Sin comentarios.</span>'}</div>
            <div class="campo" style="margin-top:10px"><textarea id="nuevoComentario" maxlength="2000" placeholder="Numeración, altura, indicaciones…"></textarea></div>
            <button class="btn btn-p" onclick="comentar()" style="width:100%">Agregar comentario</button>
        </div>

        <div class="fila-btn">
            ${p.estado === 'colocado'
                ? `<button class="btn btn-g" onclick="cambiarEstado('pendiente')">↩︎ Marcar por colocar</button>`
                : `<label class="btn btn-v" style="display:flex;align-items:center;justify-content:center">✓ Ya se colocó (foto)
                       <input type="file" accept="image/*" capture="environment" onchange="colocadoConFoto(this)"></label>
                   <button class="btn btn-g" onclick="cambiarEstado('colocado')">✓ Colocado sin foto</button>`}
            <button class="btn btn-g" onclick='abrirEdicion(actual)'>✏️ Editar / GPS</button>
            ${ES_ADMIN ? '<button class="btn btn-r" onclick="borrarPunto()">🗑️ Borrar punto</button>' : ''}
        </div>
        <p style="font-size:12px;color:#94a3b8;margin-top:12px">Marcado por ${esc(p.creado_por_nombre || '—')} el ${fechaHora(p.created_at)}
            ${p.colocado_at ? ` · colocado ${fechaHora(p.colocado_at)} por ${esc(p.colocado_por_nombre || '—')}` : ''}</p>`;

    document.getElementById('mDetalle').classList.add('abierta');
    if (gpsOk) {
        if (typeof L !== 'undefined') {
            if (mapaPunto) { mapaPunto.remove(); mapaPunto = null; }
            const ll = [Number(p.lat), Number(p.lng)];
            mapaPunto = L.map('mapaPunto', { zoomControl: false, attributionControl: false }).setView(ll, 19);
            L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { maxZoom: 20, maxNativeZoom: 19 }).addTo(mapaPunto);
            if (p.precision_m) L.circle(ll, { radius: Number(p.precision_m), color: '#ea580c', weight: 1, fillOpacity: .1 }).addTo(mapaPunto);
            L.marker(ll, { icon: L.divIcon({ className: '', html: `<span class="num-mapa">${esc(p.numero || '•')}</span>`, iconSize: null }) }).addTo(mapaPunto);
        }
        seguirDistancia(Number(p.lat), Number(p.lng), Number(p.precision_m || 0));
    }
}

/** Qué tan lejos y hacia dónde, mientras el detalle está abierto. */
function seguirDistancia(lat, lng, prec) {
    if (vigiaDetalle !== null) navigator.geolocation.clearWatch(vigiaDetalle);
    if (!navigator.geolocation) return;
    let yo = null;
    vigiaDetalle = navigator.geolocation.watchPosition(pos => {
        const el = document.getElementById('distancia'); if (!el) return;
        const R = 6371000, rad = x => x * Math.PI / 180;
        const dLat = rad(lat - pos.coords.latitude), dLng = rad(lng - pos.coords.longitude);
        const a = Math.sin(dLat / 2) ** 2 + Math.cos(rad(pos.coords.latitude)) * Math.cos(rad(lat)) * Math.sin(dLng / 2) ** 2;
        const d = 2 * R * Math.asin(Math.sqrt(a));
        const y = Math.sin(dLng) * Math.cos(rad(lat));
        const x = Math.cos(rad(pos.coords.latitude)) * Math.sin(rad(lat)) - Math.sin(rad(pos.coords.latitude)) * Math.cos(rad(lat)) * Math.cos(dLng);
        const rumbo = ['norte', 'noreste', 'este', 'sureste', 'sur', 'suroeste', 'oeste', 'noroeste'][Math.round(((Math.atan2(y, x) * 180 / Math.PI) + 360) % 360 / 45) % 8];
        const margen = pos.coords.accuracy + prec;
        el.innerHTML = d <= Math.max(8, margen)
            ? `✅ Estás en el lugar (a ~${Math.round(d)} m, margen ±${Math.round(margen)} m). Usa las fotos para el punto exacto.`
            : `🧭 Estás a <b>${d >= 1000 ? (d / 1000).toFixed(1) + ' km' : Math.round(d) + ' m'}</b> hacia el <b>${rumbo}</b> <span style="font-weight:400;color:#64748b">(±${Math.round(margen)} m)</span>`;
        if (mapaPunto && typeof L !== 'undefined') {
            const ll = [pos.coords.latitude, pos.coords.longitude];
            if (!yo) yo = L.circleMarker(ll, { radius: 7, color: '#fff', weight: 3, fillColor: '#2563eb', fillOpacity: 1 }).addTo(mapaPunto);
            else yo.setLatLng(ll);
        }
    }, err => {
        const el = document.getElementById('distancia');
        if (el) el.textContent = err.code === 1 ? 'Da permiso de ubicación para ver qué tan lejos estás.' : 'Sin señal GPS en este momento.';
    }, { enableHighAccuracy: true, maximumAge: 3000 });
}

function cerrarDetalle(conservarActual) {
    if (vigiaDetalle !== null) { navigator.geolocation.clearWatch(vigiaDetalle); vigiaDetalle = null; }
    if (mapaPunto) { mapaPunto.remove(); mapaPunto = null; }
    document.getElementById('mDetalle').classList.remove('abierta');
    if (!conservarActual) actual = null;
}

function verFoto(id) {
    document.getElementById('visorImg').src = urlFoto(id, false);
    document.getElementById('visor').classList.add('abierta');
}
function cerrarVisor() { document.getElementById('visor').classList.remove('abierta'); document.getElementById('visorImg').src = ''; }

async function compartir() {
    const p = actual; if (!p) return;
    const texto = `Rótulo ${p.numero ? '#' + p.numero + ' ' : ''}${p.rotulo || ''} — ${p.empresa_nombre}\n${p.ubicacion || ''}\n${p.mapa}`;
    if (navigator.share) { try { await navigator.share({ title: 'Punto de rótulo', text: texto }); } catch (e) {} }
    else { try { await navigator.clipboard.writeText(texto); avisar('Copiado. Pégalo en WhatsApp o en un correo.'); } catch (e) { prompt('Copia este texto:', texto); } }
}

async function agregarFotosPunto(input, etapa) {
    const archivos = [...input.files]; input.value = '';
    if (!archivos.length || !actual) return;
    const fotos = [];
    for (const f of archivos) { try { fotos.push({ uuid: uuid(), blob: await reducirFoto(f) }); } catch (e) {} }
    if (!fotos.length) { avisar('No se pudo leer la foto.'); return; }
    const id = actual.id;
    await encolar({ tipo: 'fotos', punto_id: id, etapa, fotos });
    avisar('Subiendo foto(s)…');
    await sincronizar();
    if (colaOps.some(o => o.tipo === 'fotos' && o.punto_id == id)) avisar('📱 Sin señal: la foto quedó en el teléfono y se subirá sola.', 5000);
    if (actual && actual.id === id) abrirDetalle(id);
}

async function colocadoConFoto(input) {
    if (!input.files.length) return;
    const id = actual.id;
    await agregarFotosPunto(input, 'colocado');
    await cambiarEstado('colocado', id);
}

async function cambiarEstado(estado, id) {
    id = id || (actual && actual.id);
    try {
        await api('estado', { datos: { id, estado } });
        avisar(estado === 'colocado' ? '✓ Marcado como colocado' : 'Marcado por colocar');
        await cargar();
        if (actual && actual.id === id) abrirDetalle(id);
    } catch (e) { avisar(e.sinRed ? 'Sin señal: inténtalo cuando haya conexión.' : e.message, 5000); }
}

async function comentar() {
    const t = document.getElementById('nuevoComentario').value.trim();
    if (!t || !actual) return;
    const id = actual.id;
    await encolar({ tipo: 'comentario', datos: { punto_id: id, texto: t, cliente_uuid: uuid() } });
    await sincronizar();
    if (colaOps.some(o => o.tipo === 'comentario' && o.datos.punto_id == id)) avisar('📱 Sin señal: el comentario quedó en el teléfono y se subirá solo.', 5000);
    else abrirDetalle(id);
}

async function borrarComentario(cid) {
    if (!confirm('¿Borrar este comentario?')) return;
    try { await api('eliminar_comentario', { metodo: 'POST', query: `&id=${cid}` }); abrirDetalle(actual.id); await cargar(); }
    catch (e) { avisar(e.message); }
}
async function borrarFoto(fid) {
    if (!confirm('¿Borrar esta foto?')) return;
    try { await api('eliminar_foto', { metodo: 'POST', query: `&id=${fid}` }); abrirDetalle(actual.id); await cargar(); }
    catch (e) { avisar(e.message); }
}
async function borrarPunto() {
    if (!confirm('¿Borrar este punto con todas sus fotos y comentarios? No se puede deshacer.')) return;
    try { await api('eliminar', { metodo: 'POST', query: `&id=${actual.id}` }); cerrarDetalle(); avisar('Punto borrado'); await cargar(); }
    catch (e) { avisar(e.message); }
}

function imprimir() {
    const q = new URLSearchParams({ empresa_id: plantaActual(), estado: filtroEstado });
    window.open('../api/rotulos_pdf.php?' + q.toString(), '_blank');
}

// ─── Arranque ────────────────────────────────────────────────────────────────
(async () => {
    await cargarPlantas();
    await cargar();
    sincronizar();
})();
</script>
</body>
</html>
