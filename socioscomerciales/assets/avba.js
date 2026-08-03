/* ==========================================================
   Socios Comerciales AVBA — Utilidades compartidas
   Sesión, llamadas al API, navbar, toasts e iconos.
   ========================================================== */

const SC_API = 'api/index.php';

/* ── Iconos SVG (sin CDNs) ─────────────────────────────── */
const SC_ICONOS = {
  inicio:      '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/>',
  maletin:     '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
  personas:    '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/>',
  persona:     '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8"/>',
  edificio:    '<path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6"/>',
  documento:   '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>',
  enviar:      '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
  salir:       '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
  lupa:        '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
  ubicacion:   '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
  reloj:       '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
  dinero:      '<circle cx="12" cy="12" r="9"/><path d="M15 9.5c-.5-1-1.6-1.5-3-1.5-1.7 0-3 .8-3 2s1.3 1.8 3 2 3 .8 3 2-1.3 2-3 2c-1.4 0-2.5-.5-3-1.5"/><path d="M12 6v12"/>',
  check:       '<path d="M20 6 9 17l-5-5"/>',
  verificado:  '<path d="m12 2 2.4 2.1 3.2-.4 1 3 2.8 1.6-1.2 3 1.2 3-2.8 1.6-1 3-3.2-.4L12 22l-2.4-2.1-3.2.4-1-3L2.6 15.7l1.2-3-1.2-3 2.8-1.6 1-3 3.2.4Z"/><path d="m9 12 2 2 4-4"/>',
  alerta:      '<circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.5v.5"/>',
  mas:         '<path d="M12 5v14M5 12h14"/>',
  bote:        '<path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/>',
  lapiz:       '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
  camara:      '<path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/>',
  correo:      '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2 7 10 6 10-6"/>',
  vacia:       '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M3 13h18"/>',
  enlace:      '<path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1"/><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1"/>',
  atras:       '<path d="M19 12H5"/><path d="m12 19-7-7 7-7"/>',
};

function scIcono(nombre, clase) {
  const d = SC_ICONOS[nombre] || '';
  return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
    stroke-linecap="round" stroke-linejoin="round"${clase ? ` class="${clase}"` : ''}>${d}</svg>`;
}

/* ── Sesión ────────────────────────────────────────────── */
const scSesion = {
  leer() {
    try {
      const raw = localStorage.getItem('sc_session');
      return raw ? JSON.parse(raw) : null;
    } catch (e) { return null; }
  },
  guardar(datos) {
    localStorage.setItem('sc_session', JSON.stringify({ ...datos, ts: Date.now() }));
  },
  actualizar(parciales) {
    const s = this.leer();
    if (s) this.guardar({ ...s, ...parciales });
  },
  borrar() { localStorage.removeItem('sc_session'); },
};

/**
 * Exige sesión iniciada. Si falta, manda al login.
 * `tipo` opcional: 'persona' | 'empresa' — redirige si no coincide.
 */
function scExigirSesion(tipo) {
  const s = scSesion.leer();
  if (!s || !s.token) { location.href = 'login.html'; return null; }
  if (tipo && s.tipo !== tipo) {
    location.href = s.tipo === 'empresa' ? 'perfil-empresa.html' : 'perfil-persona.html';
    return null;
  }
  return s;
}

/* ── Llamadas al API ───────────────────────────────────── */

/** Lee la respuesta como JSON, con mensaje claro si el servidor devolvió otra cosa. */
async function scLeerRespuesta(res) {
  const texto = await res.text();
  let datos;
  try {
    datos = JSON.parse(texto);
  } catch (e) {
    // El servidor devolvió HTML (error 500, límite de subida, etc.).
    // Antes esto rompía la promesa en silencio y no pasaba nada en pantalla.
    console.error('Respuesta no JSON del servidor:', texto.slice(0, 500));
    if (res.status === 413) throw new Error('El archivo es demasiado grande para el servidor.');
    throw new Error(`El servidor respondió con un error (${res.status}). Intenta de nuevo.`);
  }
  if (datos && datos.status === 'error' && res.status === 401) {
    scSesion.borrar();
    location.href = 'login.html';
    throw new Error(datos.message || 'Sesión expirada.');
  }
  return datos;
}

/** POST con cuerpo JSON. */
async function scPost(action, payload) {
  const s = scSesion.leer();
  const cabeceras = { 'Content-Type': 'application/json' };
  if (s && s.token) cabeceras['Authorization'] = 'Bearer ' + s.token;

  const res = await fetch(SC_API, {
    method: 'POST',
    headers: cabeceras,
    body: JSON.stringify({ action, payload: payload || {} }),
  });
  return scLeerRespuesta(res);
}

/** GET con parámetros. */
async function scGet(action, params) {
  const s = scSesion.leer();
  const qs = new URLSearchParams({ action, ...(params || {}) });
  const cabeceras = {};
  if (s && s.token) cabeceras['Authorization'] = 'Bearer ' + s.token;

  const res = await fetch(`${SC_API}?${qs}`, { headers: cabeceras });
  return scLeerRespuesta(res);
}

/** Sube un archivo (multipart). Devuelve la respuesta del API. */
async function scSubir(action, archivo) {
  const s = scSesion.leer();
  const fd = new FormData();
  fd.append('action', action);
  fd.append('archivo', archivo);

  const cabeceras = {};
  if (s && s.token) cabeceras['Authorization'] = 'Bearer ' + s.token;
  // Ojo: NO fijar Content-Type; el navegador debe poner el boundary.

  const res = await fetch(SC_API, { method: 'POST', headers: cabeceras, body: fd });
  return scLeerRespuesta(res);
}

/* ── Toast ─────────────────────────────────────────────── */
let scToastTimer = null;
function scToast(mensaje, tipo) {
  let el = document.getElementById('sc-toast');
  if (!el) {
    el = document.createElement('div');
    el.id = 'sc-toast';
    el.className = 'toast';
    // Para que un lector de pantalla anuncie el aviso sin robar el foco
    el.setAttribute('role', 'status');
    el.setAttribute('aria-live', 'polite');
    document.body.appendChild(el);
  }
  el.textContent = mensaje;
  el.className = 'toast visible' + (tipo ? ' ' + tipo : '');

  clearTimeout(scToastTimer);
  scToastTimer = setTimeout(() => { el.className = 'toast'; }, 3200);
}

/* ── Navbar ────────────────────────────────────────────── */
const SC_MENU = {
  persona: [
    { href: 'inicio.html',            texto: 'Inicio',      icono: 'inicio' },
    { href: 'vacantes.html',          texto: 'Vacantes',    icono: 'maletin' },
    { href: 'mis-postulaciones.html', texto: 'Postulaciones', icono: 'enviar' },
    { href: 'empresas.html',          texto: 'Empresas',    icono: 'edificio' },
    { href: 'perfil-persona.html',    texto: 'Mi perfil',   icono: 'persona' },
  ],
  empresa: [
    { href: 'inicio.html',         texto: 'Inicio',      icono: 'inicio' },
    { href: 'mis-vacantes.html',   texto: 'Mis vacantes', icono: 'maletin' },
    { href: 'candidatos.html',     texto: 'Candidatos',  icono: 'personas' },
    { href: 'perfil-empresa.html', texto: 'Mi empresa',  icono: 'edificio' },
  ],
};

/** Dibuja la barra de navegación en el contenedor con id "navbar". */
function scPintarNavbar(activo) {
  const cont = document.getElementById('navbar');
  if (!cont) return;

  const s = scSesion.leer();
  const menu = (s && SC_MENU[s.tipo]) ? SC_MENU[s.tipo] : [];
  const actual = activo || location.pathname.split('/').pop();

  cont.className = 'navbar';
  cont.innerHTML = `
    <div class="wrap">
      <a href="${s ? 'inicio.html' : 'index.html'}">
        <img src="assets/avba-logo.png" alt="AVBA" class="logo logo-nav">
      </a>
      <span class="brand-tagline">Socios Comerciales</span>
      <nav class="nav-menu">
        ${menu.map(m => `
          <a href="${m.href}" class="${m.href === actual ? 'activo' : ''}">
            ${scIcono(m.icono)}<span>${m.texto}</span>
          </a>`).join('')}
        <button class="nav-salir" onclick="scCerrarSesion()">
          ${scIcono('salir')}<span>Salir</span>
        </button>
      </nav>
    </div>`;
}

async function scCerrarSesion() {
  try { await scPost('LOGOUT'); } catch (e) { /* da igual si falla */ }
  scSesion.borrar();
  location.href = 'index.html';
}

/* ── Aviso de correo sin verificar ─────────────────────── */
/** Inserta el aviso en el contenedor con id "aviso-verificacion" si aplica. */
function scAvisoVerificacion(verificado) {
  const cont = document.getElementById('aviso-verificacion');
  if (!cont) return;

  if (verificado) { cont.innerHTML = ''; return; }

  cont.innerHTML = `
    <div class="alerta alerta-aviso">
      ${scIcono('correo')}
      <div>
        <strong>Confirma tu correo electrónico.</strong>
        Te enviamos un enlace al registrarte. Verifícalo para que tu perfil se muestre como confiable.
      </div>
      <div class="alerta-acciones">
        <button class="btn btn-sm btn-gris" id="btn-reenviar" onclick="scReenviarVerificacion()">Reenviar</button>
      </div>
    </div>`;
}

async function scReenviarVerificacion() {
  const btn = document.getElementById('btn-reenviar');
  if (btn) { btn.disabled = true; btn.textContent = 'Enviando...'; }
  try {
    const r = await scPost('REENVIAR_VERIFICACION');
    scToast(r.message, r.status === 'success' ? 'ok' : 'error');
  } catch (e) {
    scToast(e.message, 'error');
  } finally {
    if (btn) { btn.disabled = false; btn.textContent = 'Reenviar'; }
  }
}

/* ── Formato y escape ──────────────────────────────────── */

/**
 * Escapa texto para insertarlo con innerHTML sin riesgo de inyección.
 *
 * textContent → innerHTML escapa < > &, pero NO las comillas. Como este
 * texto también se inserta dentro de atributos (alt="…", href="…", src="…"),
 * una comilla en un dato del usuario cerraría el atributo y permitiría
 * inyectar otro (por ejemplo onload=), logrando XSS almacenado. Por eso se
 * escapan explícitamente ambas comillas.
 */
function scEsc(valor) {
  const div = document.createElement('div');
  div.textContent = valor === null || valor === undefined ? '' : String(valor);
  return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

/**
 * Devuelve la URL solo si es http(s); si no, cadena vacía.
 * Evita que un `sitio_web` como "javascript:..." acabe en un href.
 */
function scUrlSegura(url) {
  const v = String(url || '').trim();
  return /^https?:\/\//i.test(v) ? v : '';
}

/** Iniciales para el avatar cuando no hay imagen. */
function scIniciales(nombre) {
  if (!nombre) return '?';
  return nombre.trim().split(/\s+/).slice(0, 2).map(p => p[0]).join('');
}

/** Devuelve el HTML de un avatar (imagen o iniciales). */
function scAvatar(url, nombre, tam, cuadrado) {
  const clases = `avatar avatar-${tam || 'md'}${cuadrado ? ' cuadrado' : ''}`;
  return url
    ? `<div class="${clases}"><img src="${scEsc(url)}" alt="${scEsc(nombre)}"></div>`
    : `<div class="${clases}">${scEsc(scIniciales(nombre))}</div>`;
}

/** Insignia de cuenta verificada. */
function scBadgeVerificado(verificado) {
  return verificado
    ? `<span class="badge badge-verde">${scIcono('verificado')}Verificado</span>`
    : '';
}

const SC_MODALIDADES = { presencial: 'Presencial', remoto: 'Remoto', hibrido: 'Híbrido' };
const SC_ESTATUS_POSTULACION = {
  enviada:     { texto: 'Enviada',     clase: 'badge-azul' },
  en_revision: { texto: 'En revisión', clase: 'badge-ambar' },
  aceptada:    { texto: 'Aceptada',    clase: 'badge-verde' },
  rechazada:   { texto: 'No avanzó',   clase: 'badge-rojo' },
};

/** Fecha "hace X" a partir de un DATETIME de MySQL. */
function scHace(fecha) {
  if (!fecha) return '';
  const d = new Date(String(fecha).replace(' ', 'T'));
  if (isNaN(d)) return '';

  const seg = Math.floor((Date.now() - d.getTime()) / 1000);
  if (seg < 60)     return 'hace un momento';
  if (seg < 3600)   return `hace ${Math.floor(seg / 60)} min`;
  if (seg < 86400)  return `hace ${Math.floor(seg / 3600)} h`;
  const dias = Math.floor(seg / 86400);
  if (dias === 1)   return 'ayer';
  if (dias < 30)    return `hace ${dias} días`;
  if (dias < 365)   return `hace ${Math.floor(dias / 30)} meses`;
  return `hace ${Math.floor(dias / 365)} años`;
}

/** Formatea un rango de fechas de experiencia. */
function scRangoFechas(desde, hasta, actual) {
  const f = (v) => {
    if (!v) return '';
    const d = new Date(String(v).replace(' ', 'T'));
    if (isNaN(d)) return String(v);
    return d.toLocaleDateString('es-MX', { month: 'short', year: 'numeric' });
  };
  const ini = f(desde);
  const fin = actual == 1 || actual === true ? 'Actual' : f(hasta);
  if (!ini && !fin) return '';
  if (!ini) return fin;
  return fin ? `${ini} — ${fin}` : ini;
}

/* ── Modal ─────────────────────────────────────────────── */
function scAbrirModal(id) {
  const el = document.getElementById(id);
  if (el) el.classList.add('abierto');
}
function scCerrarModal(id) {
  const el = document.getElementById(id);
  if (el) el.classList.remove('abierto');
}
/** Cierra el modal al hacer clic en el fondo. */
document.addEventListener('click', (e) => {
  if (e.target.classList && e.target.classList.contains('modal-fondo')) {
    e.target.classList.remove('abierto');
  }
});
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-fondo.abierto').forEach(m => m.classList.remove('abierto'));
  }
});

/* ── Estado vacío reutilizable ─────────────────────────── */
function scVacio(icono, titulo, texto, botonHtml) {
  return `<div class="vacio">
    ${scIcono(icono)}
    <h4>${scEsc(titulo)}</h4>
    <p>${scEsc(texto)}</p>
    ${botonHtml || ''}
  </div>`;
}

function scCargando(texto) {
  return `<div class="cargando"><div class="spin spin-azul"></div>${scEsc(texto || 'Cargando...')}</div>`;
}

/**
 * Esqueleto de lista: reserva el espacio del contenido real mientras carga,
 * para que la pantalla no salte cuando llegan los datos.
 * @param {number} n     cuántas tarjetas dibujar
 * @param {boolean} dos  true para rejilla de dos columnas
 */
function scEsqueletoLista(n, dos) {
  const tarjeta = `
    <div class="esq-tarjeta">
      <div style="display:flex;gap:13px;align-items:flex-start">
        <div class="esq esq-circulo" style="width:48px;height:48px;flex-shrink:0"></div>
        <div style="flex:1;min-width:0">
          <div class="esq esq-linea media" style="height:15px"></div>
          <div class="esq esq-linea corta"></div>
          <div class="esq esq-linea larga" style="margin-top:12px"></div>
        </div>
      </div>
    </div>`;
  return `<div class="resultados${dos ? ' resultados-2' : ''}" aria-busy="true" aria-label="Cargando resultados">
    ${tarjeta.repeat(n || 4)}
  </div>`;
}

/* ── Pie de página ─────────────────────────────────────── */
function scPintarPie() {
  const cont = document.getElementById('pie');
  if (!cont) return;
  // El pie es oscuro y el logo solo se ve bien sobre blanco, así que aquí
  // va la marca en texto y el logo queda reservado a las barras claras.
  cont.className = 'pie';
  cont.innerHTML = `
    <div class="wrap">
      <span class="pie-marca">Socios Comerciales AVBA</span>
      <p class="pie-legal">AVBA Inspections, Certifications and Maintenance S.A.S. de C.V. — avba.com.mx</p>
    </div>`;
}
