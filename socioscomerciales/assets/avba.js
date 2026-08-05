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
  // El API rechazó la acción por correo sin confirmar. Puede pasar aunque la
  // interfaz creyera lo contrario (sesión vieja en localStorage), así que se
  // corrige la copia local para que el aviso aparezca al recargar.
  if (datos && datos.codigo === 'CORREO_NO_VERIFICADO') {
    scSesion.actualizar({ correo_verificado: 0 });
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

/**
 * POST multipart con campos de texto y un archivo opcional.
 *
 * scSubir() solo manda `archivo`; PUBLICAR necesita además el texto en el
 * mismo envío, porque la publicación y su imagen son una sola cosa.
 */
async function scEnviarFormulario(action, campos, archivo) {
  const s = scSesion.leer();
  const fd = new FormData();
  fd.append('action', action);

  Object.entries(campos || {}).forEach(([k, v]) => {
    if (v !== null && v !== undefined) fd.append(k, v);
  });
  if (archivo) fd.append('archivo', archivo);

  const cabeceras = {};
  if (s && s.token) cabeceras['Authorization'] = 'Bearer ' + s.token;
  // Ojo: NO fijar Content-Type; el navegador debe poner el boundary.

  const res = await fetch(SC_API, { method: 'POST', headers: cabeceras, body: fd });
  return scLeerRespuesta(res);
}

/**
 * Abre el CV de un candidato en una pestaña nueva.
 *
 * El PDF ya no es un archivo estático: se pide al API con la cabecera
 * Authorization, así que no se puede enlazar con un href normal. Se descarga
 * como blob y se muestra desde memoria, de modo que el token nunca viaja en
 * la URL. La pestaña se abre ANTES del fetch porque, si se abriera después,
 * el navegador lo tomaría por una ventana emergente y la bloquearía.
 */
async function scVerCV(personaId) {
  const s = scSesion.leer();
  if (!s || !s.token) { location.href = 'login.html'; return; }

  const ventana = window.open('', '_blank');
  try {
    const res = await fetch(`${SC_API}?action=DESCARGAR_CV&id=${Number(personaId)}`, {
      headers: { 'Authorization': 'Bearer ' + s.token },
    });

    if (!res.ok) {
      let mensaje = 'No se pudo abrir el CV.';
      try { mensaje = (await res.json()).message || mensaje; } catch (e) { /* no era JSON */ }
      if (ventana) ventana.close();
      scToast(mensaje, 'error');
      return;
    }

    const url = URL.createObjectURL(await res.blob());
    if (ventana) {
      ventana.location = url;
    } else {
      // La pestaña fue bloqueada: se ofrece como descarga
      const a = document.createElement('a');
      a.href = url; a.download = 'CV.pdf';
      document.body.appendChild(a); a.click(); a.remove();
    }
    // Liberar la memoria del blob una vez que la pestaña lo cargó
    setTimeout(() => URL.revokeObjectURL(url), 60000);

  } catch (e) {
    if (ventana) ventana.close();
    scToast(e.message || 'No se pudo abrir el CV.', 'error');
  }
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
  const menu = (s && SC_MENU[s.tipo]) ? SC_MENU[s.tipo].slice() : [];

  // El panel se añade al menú de su tipo de cuenta: administración no es un
  // tipo aparte, es un permiso encima de una cuenta normal.
  if (s && Number(s.es_admin) === 1) {
    menu.push({ href: 'admin.html', texto: 'Administración', icono: 'personas' });
  }

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
        ${s
          ? `<button class="nav-salir" onclick="scCerrarSesion()">
               ${scIcono('salir')}<span>Salir</span>
             </button>`
          : `<a class="nav-salir" href="login.html">
               ${scIcono('persona')}<span>Entrar</span>
             </a>`}
      </nav>
    </div>`;
}

async function scCerrarSesion() {
  try { await scPost('LOGOUT'); } catch (e) { /* da igual si falla */ }
  scSesion.borrar();
  location.href = 'index.html';
}

/* ── Verificación de correo ────────────────────────────── */

/**
 * ¿La cuenta ya confirmó su correo?
 *
 * Es solo una pista para la interfaz: el permiso de verdad lo decide el API,
 * que responde 403 con codigo CORREO_NO_VERIFICADO. Nunca uses esto como
 * única barrera; el valor vive en localStorage y el usuario puede editarlo.
 */
function scVerificado(sesion) {
  const s = sesion || scSesion.leer();
  return !!(s && Number(s.correo_verificado) === 1);
}

/** Inserta el aviso en el contenedor con id "aviso-verificacion" si aplica. */
function scAvisoVerificacion(verificado) {
  const cont = document.getElementById('aviso-verificacion');
  if (!cont) return;

  // Mantener sincronizada la sesión local con lo que dice el servidor
  scSesion.actualizar({ correo_verificado: verificado ? 1 : 0 });

  if (verificado) { cont.innerHTML = ''; return; }

  cont.innerHTML = `
    <div class="alerta alerta-aviso">
      ${scIcono('correo')}
      <div>
        <strong>Confirma tu correo electrónico para activar tu cuenta.</strong>
        Mientras no lo hagas no puedes editar tu perfil, publicar o postularte,
        ni ver los perfiles de otras cuentas. Te enviamos un enlace al registrarte.
      </div>
      <div class="alerta-acciones">
        <button class="btn btn-sm btn-gris" id="btn-reenviar" onclick="scReenviarVerificacion()">Reenviar enlace</button>
        <button class="btn btn-sm btn-gris" onclick="scRevisarVerificacion(this)">Ya lo confirmé</button>
      </div>
    </div>`;
}

/**
 * Bloquea una pantalla completa cuando la cuenta no ha confirmado su correo.
 *
 * Devuelve true si puede continuar. Si no, pinta el aviso dentro del
 * contenedor indicado y el llamador debe cortar (return) sin pedir datos:
 * el API los negaría de todos modos con 403.
 */
function scBloquearSinVerificar(contenedorId, queSeBloquea) {
  if (scVerificado()) return true;

  const cont = document.getElementById(contenedorId);
  if (cont) {
    cont.innerHTML = `
      <div class="vacio" data-sin-bloqueo>
        ${scIcono('correo')}
        <h4>Confirma tu correo para continuar</h4>
        <p>${scEsc(queSeBloquea || 'Esta sección')} se habilita en cuanto verifiques
           tu correo electrónico. Abre el enlace que te enviamos al registrarte
           o pide uno nuevo.</p>
        <button class="btn btn-azul" id="btn-reenviar" onclick="scReenviarVerificacion()">Reenviar enlace</button>
        <button class="btn btn-outline" onclick="scRevisarVerificacion(this)">Ya lo confirmé</button>
      </div>`;
  }
  return false;
}

/**
 * Deja la pantalla en solo lectura mientras el correo no esté confirmado.
 *
 * Desactiva campos y botones dentro de los contenedores indicados, salvo los
 * marcados con data-sin-bloqueo (reenviar el enlace, cerrar sesión, etc.).
 * Es únicamente cortesía visual: quien fuerce el clic recibirá igualmente el
 * 403 del API, que es donde está la regla de verdad.
 */
function scSoloLecturaSinVerificar(selectores) {
  if (scVerificado()) return true;

  (selectores || ['main']).forEach(sel => {
    document.querySelectorAll(sel).forEach(cont => {
      cont.querySelectorAll('input, textarea, select, button').forEach(el => {
        if (el.closest('[data-sin-bloqueo]')) return;
        el.disabled = true;
        el.title = 'Confirma tu correo electrónico para poder editar.';
      });
    });
  });
  return false;
}

/**
 * Vuelve a preguntar al servidor si el correo ya quedó confirmado.
 *
 * Hace falta porque el enlace del correo se abre a menudo en otro navegador
 * (o en el teléfono): la copia en localStorage seguiría diciendo que no está
 * verificado y el usuario quedaría bloqueado sin motivo. GET_INICIO devuelve
 * el dato al día y no exige verificación, justo para poder salir de aquí.
 */
async function scRevisarVerificacion(boton) {
  const original = boton ? boton.textContent : '';
  if (boton) { boton.disabled = true; boton.textContent = 'Revisando...'; }
  try {
    const r = await scGet('GET_INICIO');
    if (r && Number(r.correo_verificado) === 1) {
      scSesion.actualizar({ correo_verificado: 1 });
      location.reload();
      return;
    }
    scToast('Tu correo sigue sin confirmar. Abre el enlace que te enviamos.', 'error');
  } catch (e) {
    scToast(e.message, 'error');
  } finally {
    if (boton) { boton.disabled = false; boton.textContent = original; }
  }
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

/* ── Paginación ────────────────────────────────────────── */

/**
 * Botón "Ver más" para las listas que llegan paginadas del API.
 *
 * Antes todos los listados traían un LIMIT 60 fijo y sin desplazamiento:
 * a partir de la vacante 61 el resto era invisible y no había manera de
 * llegar a ellas. `onclick` es el nombre de una función global de la página.
 */
function scBotonVerMas(mostrados, total, funcionGlobal) {
  const n = Number(mostrados) || 0;
  const t = Number(total) || 0;
  if (n >= t) return '';

  return `
    <div class="ver-mas">
      <button type="button" class="btn btn-outline" onclick="${funcionGlobal}()">Ver más resultados</button>
      <p class="ver-mas-nota">Mostrando ${n} de ${t}</p>
    </div>`;
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
/** "2026-08-03" → "3 de agosto de 2026". Para las páginas legales. */
function scFechaLarga(iso) {
  const d = new Date(String(iso).replace(' ', 'T') + (String(iso).length === 10 ? 'T12:00:00' : ''));
  if (isNaN(d)) return String(iso);
  const meses = ['enero','febrero','marzo','abril','mayo','junio',
                 'julio','agosto','septiembre','octubre','noviembre','diciembre'];
  return `${d.getDate()} de ${meses[d.getMonth()]} de ${d.getFullYear()}`;
}

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
      <p class="pie-enlaces">
        <a href="terminos.html">Términos y Condiciones</a>
        <span aria-hidden="true">·</span>
        <a href="aviso-privacidad.html">Aviso de Privacidad</a>
      </p>
      <p class="pie-legal">AVBA Inspections, Certifications and Maintenance S.A.S. de C.V. — avba.com.mx</p>
    </div>`;
}

/* ══════════════════════════════════════════════════════════
   MURO DEL PORTAL (feed)

   Componente completo: se monta con scMontarFeed('id-contenedor') y se
   encarga del compositor, la lista, los comentarios y los borrados.
   Vive aquí y no en la página para poder reutilizarlo tal cual.

   El muro NO es público: el API exige sesión con el correo confirmado,
   porque muestra nombres y fotos de otras cuentas.
   ══════════════════════════════════════════════════════════ */

const SC_FEED_MAX_TEXTO      = 3000;   // igual que ScFeed::MAX_TEXTO
const SC_FEED_MAX_COMENTARIO = 1000;

let scFeedEstado = {
  contenedor: null,
  publicaciones: [],
  total: 0,
  archivo: null,       // File elegido en el compositor
  previaUrl: null,     // objectURL de la vista previa, hay que liberarlo
  expandidos: {},      // id de publicación → comentarios completos cargados
};

/** Enlace al perfil público del autor, según su tipo de cuenta. */
function scFeedEnlaceAutor(a) {
  if (a.autor_tipo === 'empresa' && a.autor_empresa_id) {
    return 'ver-empresa.html?id=' + Number(a.autor_empresa_id);
  }
  if (a.autor_tipo === 'persona' && a.autor_persona_id) {
    return 'ver-perfil.html?id=' + Number(a.autor_persona_id);
  }
  return '';
}

function scFeedCabeceraAutor(a, fecha, acciones) {
  const enlace = scFeedEnlaceAutor(a);
  const nombre = scEsc(a.autor_nombre || 'Cuenta sin nombre');

  return `
    <div class="feed-autor">
      ${scAvatar(a.autor_foto, a.autor_nombre, 'sm', a.autor_tipo === 'empresa')}
      <div class="feed-autor-datos">
        <div class="feed-autor-nombre">
          ${enlace ? `<a href="${enlace}">${nombre}</a>` : nombre}
          ${Number(a.autor_verificado) ? scIcono('verificado', 'feed-verificado') : ''}
        </div>
        <div class="feed-fecha">${scEsc(scHace(fecha))}</div>
      </div>
      ${acciones || ''}
    </div>`;
}

/** ¿Puedo borrar esto? El API lo vuelve a comprobar; esto solo pinta. */
function scFeedPuedoBorrar(autorId, duenoPublicacionId) {
  const s = scSesion.leer();
  if (!s) return false;
  if (Number(s.es_admin) === 1) return true;
  if (Number(s.usuario_id) === Number(autorId)) return true;
  return duenoPublicacionId !== undefined
      && Number(s.usuario_id) === Number(duenoPublicacionId);
}

function scFeedComentario(c, duenoPublicacionId) {
  const borrar = scFeedPuedoBorrar(c.autor_id, duenoPublicacionId)
    ? `<button type="button" class="feed-borrar" title="Eliminar comentario"
         data-feed="borrar-comentario" data-id="${Number(c.id)}"
         data-pub="${Number(c.publicacion_id)}" aria-label="Eliminar comentario">&times;</button>`
    : '';

  const enlace = scFeedEnlaceAutor(c);
  const nombre = scEsc(c.autor_nombre || 'Cuenta sin nombre');

  return `
    <div class="feed-comentario">
      ${scAvatar(c.autor_foto, c.autor_nombre, 'xs', c.autor_tipo === 'empresa')}
      <div class="feed-comentario-burbuja">
        <div class="feed-comentario-cabecera">
          <span class="feed-comentario-autor">${enlace ? `<a href="${enlace}">${nombre}</a>` : nombre}</span>
          <span class="feed-comentario-fecha">${scEsc(scHace(c.creado))}</span>
          ${borrar}
        </div>
        <div class="feed-comentario-texto">${scEsc(c.texto)}</div>
      </div>
    </div>`;
}

function scFeedPublicacion(pub) {
  const acciones = scFeedPuedoBorrar(pub.autor_id)
    ? `<button type="button" class="feed-borrar" title="Eliminar publicación"
         data-feed="borrar-publicacion" data-id="${Number(pub.id)}"
         aria-label="Eliminar publicación">&times;</button>`
    : '';

  const total = Number(pub.n_comentarios) || 0;
  const expandido = !!scFeedEstado.expandidos[pub.id];
  const comentarios = expandido ? scFeedEstado.expandidos[pub.id] : (pub.comentarios || []);

  // Solo se ofrece "ver todos" si hay más de los que se muestran de entrada
  const verTodos = (!expandido && total > comentarios.length)
    ? `<button type="button" class="feed-ver-mas" data-feed="ver-comentarios" data-id="${Number(pub.id)}">
         Ver los ${total} comentarios
       </button>`
    : '';

  // Solo su autor (y administración) reciben lo no aprobado, así que este
  // aviso nunca lo ve un visitante cualquiera.
  let aviso = '';
  if (pub.estado === 'pendiente') {
    aviso = `<div class="feed-estado pendiente">${scIcono('reloj')}
      <span>Pendiente de revisión. Solo tú la ves hasta que AVBA la apruebe.</span></div>`;
  } else if (pub.estado === 'rechazada') {
    aviso = `<div class="feed-estado rechazada">${scIcono('alerta')}
      <span>No aprobada${pub.moderado_motivo ? ': ' + scEsc(pub.moderado_motivo) : '.'}</span></div>`;
  }

  return `
    <article class="feed-publicacion ${pub.estado && pub.estado !== 'aprobada' ? 'sin-aprobar' : ''}" id="pub-${Number(pub.id)}">
      ${scFeedCabeceraAutor(pub, pub.creado, acciones)}
      ${aviso}

      ${pub.texto ? `<div class="feed-texto">${scEsc(pub.texto)}</div>` : ''}
      ${pub.imagen_url
        ? `<div class="feed-imagen">
             <img src="${scEsc(pub.imagen_url)}" alt="Fotografía de la publicación" loading="lazy">
           </div>` : ''}

      ${pub.estado && pub.estado !== 'aprobada' ? '' : `
        <div class="feed-pie">
          <span class="feed-cuenta">${total === 1 ? '1 comentario' : total + ' comentarios'}</span>
        </div>

        <div class="feed-comentarios">
          ${verTodos}
          ${comentarios.map(c => scFeedComentario(c, pub.autor_id)).join('')}
        </div>

        ${scVerificado()
          ? `<form class="feed-responder" data-feed="form-comentario" data-id="${Number(pub.id)}">
               <input type="text" maxlength="${SC_FEED_MAX_COMENTARIO}" required
                      placeholder="Escribe un comentario..." aria-label="Escribe un comentario">
               <button type="submit" class="btn btn-sm btn-primary">Comentar</button>
             </form>`
          : `<p class="feed-responder-cerrado">
               <a href="registro.html">Crea tu cuenta</a> para comentar.
             </p>`}
      `}
    </article>`;
}

/**
 * Encabezado del muro: el compositor si puedes publicar, y si no la
 * invitación correspondiente. El muro se lee sin cuenta, pero publicar
 * exige una con el correo confirmado.
 */
function scFeedEncabezado() {
  const s = scSesion.leer();

  if (!s || !s.token) {
    return `
      <div class="feed-invitacion">
        ${scIcono('personas')}
        <div>
          <strong>¿Quieres publicar aquí?</strong>
          Crea tu cuenta gratis y comparte tus obras, novedades y vacantes con
          toda la comunidad industrial de AVBA.
        </div>
        <div class="feed-invitacion-acciones">
          <a class="btn btn-sm btn-primary" href="registro.html">Crear cuenta</a>
          <a class="btn btn-sm btn-outline" href="login.html">Entrar</a>
        </div>
      </div>`;
  }

  if (!scVerificado(s)) {
    return `
      <div class="feed-invitacion">
        ${scIcono('correo')}
        <div>
          <strong>Confirma tu correo para publicar.</strong>
          Puedes leer el muro, pero para publicar y comentar hace falta verificar
          tu correo electrónico.
        </div>
        <div class="feed-invitacion-acciones">
          <a class="btn btn-sm btn-primary" href="inicio.html">Ir a mi panel</a>
        </div>
      </div>`;
  }

  return scFeedCompositor();
}

function scFeedCompositor() {
  const s = scSesion.leer();
  const previa = scFeedEstado.previaUrl
    ? `<div class="feed-previa">
         <img src="${scFeedEstado.previaUrl}" alt="Vista previa de la fotografía">
         <button type="button" class="feed-previa-quitar" data-feed="quitar-imagen"
                 aria-label="Quitar la fotografía">&times;</button>
       </div>`
    : '';

  return `
    <form class="feed-compositor" id="feed-compositor">
      <div class="feed-compositor-fila">
        ${scAvatar(null, s ? s.nombre : '', 'sm', s && s.tipo === 'empresa')}
        <textarea id="feed-texto" rows="2" maxlength="${SC_FEED_MAX_TEXTO}"
          placeholder="Comparte una noticia, una obra terminada o una novedad de tu empresa..."
          aria-label="Texto de la publicación"></textarea>
      </div>
      ${previa}
      <div class="feed-compositor-pie">
        <button type="button" class="btn btn-sm btn-gris" data-feed="elegir-imagen">
          ${scIcono('camara')}Añadir foto
        </button>
        <input type="file" id="feed-archivo" class="oculto-file"
               accept="image/jpeg,image/png,image/webp,image/gif">
        <span class="feed-contador" id="feed-contador"></span>
        <button type="submit" class="btn btn-sm btn-primary" id="feed-publicar">Publicar</button>
      </div>
      <p class="feed-nota-moderacion">
        ${scIcono('alerta')}
        El muro es público. Tu publicación aparece cuando AVBA la revisa.
      </p>
    </form>`;
}

function scFeedPintar() {
  const cont = document.getElementById(scFeedEstado.contenedor);
  if (!cont) return;

  const lista = scFeedEstado.publicaciones;

  cont.innerHTML = scFeedEncabezado() + (lista.length
    ? `<div class="feed-lista">${lista.map(scFeedPublicacion).join('')}</div>
       ${scBotonVerMas(lista.length, scFeedEstado.total, 'scFeedVerMas')}`
    : scVacio('documento', 'Todavía no hay publicaciones',
        scVerificado()
          ? 'Sé el primero en compartir algo con la comunidad del portal.'
          : 'Aquí aparecerán las novedades que comparta la comunidad del portal.',
        ''));

  scFeedActualizarContador();
}

function scFeedActualizarContador() {
  const campo = document.getElementById('feed-texto');
  const el    = document.getElementById('feed-contador');
  if (!campo || !el) return;

  const restantes = SC_FEED_MAX_TEXTO - campo.value.length;
  el.textContent = restantes < 300 ? restantes + ' caracteres restantes' : '';
  el.classList.toggle('cerca', restantes < 100);
}

/* ── Carga ──────────────────────────────────────────────── */
async function scFeedCargar() {
  const cont = document.getElementById(scFeedEstado.contenedor);
  if (!cont) return;
  cont.innerHTML = scCargando('Cargando el muro...');

  try {
    const datos = await scGet('GET_FEED', { offset: 0 });
    if (datos.status !== 'success') {
      cont.innerHTML = scVacio('alerta', 'No se pudo cargar el muro', datos.message || '', '');
      return;
    }
    scFeedEstado.publicaciones = datos.publicaciones || [];
    scFeedEstado.total = Number(datos.total) || scFeedEstado.publicaciones.length;
    scFeedPintar();
  } catch (e) {
    cont.innerHTML = scVacio('alerta', 'Error', e.message, '');
  }
}

async function scFeedVerMas() {
  if (scFeedEstado.publicaciones.length >= scFeedEstado.total) return;
  try {
    const datos = await scGet('GET_FEED', { offset: scFeedEstado.publicaciones.length });
    if (datos.status !== 'success') { scToast(datos.message || 'No se pudo cargar más.', 'error'); return; }

    scFeedEstado.publicaciones = scFeedEstado.publicaciones.concat(datos.publicaciones || []);
    scFeedEstado.total = Number(datos.total) || scFeedEstado.publicaciones.length;
    scFeedPintar();
  } catch (e) {
    scToast(e.message, 'error');
  }
}

/* ── Acciones ───────────────────────────────────────────── */
function scFeedLimpiarImagen() {
  if (scFeedEstado.previaUrl) URL.revokeObjectURL(scFeedEstado.previaUrl);
  scFeedEstado.previaUrl = null;
  scFeedEstado.archivo = null;
}

async function scFeedPublicar() {
  const campo = document.getElementById('feed-texto');
  const texto = campo ? campo.value.trim() : '';

  if (!texto && !scFeedEstado.archivo) {
    scToast('Escribe algo o adjunta una fotografía.', 'error');
    return;
  }

  const btn = document.getElementById('feed-publicar');
  if (btn) { btn.disabled = true; btn.innerHTML = '<div class="spin"></div> Publicando...'; }

  try {
    const datos = await scEnviarFormulario('PUBLICAR', { texto }, scFeedEstado.archivo);
    if (datos.status !== 'success') { scToast(datos.message || 'No se pudo publicar.', 'error'); return; }

    scFeedLimpiarImagen();
    scToast(datos.message, 'ok');
    scFeedCargar();
  } catch (e) {
    scToast(e.message, 'error');
  } finally {
    if (btn) { btn.disabled = false; btn.textContent = 'Publicar'; }
  }
}

async function scFeedComentar(publicacionId, texto, form) {
  if (!texto.trim()) return;

  const btn = form.querySelector('button[type="submit"]');
  if (btn) btn.disabled = true;

  try {
    const datos = await scPost('COMENTAR', { publicacion_id: publicacionId, texto });
    if (datos.status !== 'success') { scToast(datos.message || 'No se pudo comentar.', 'error'); return; }

    // Si estaba expandido se recarga la lista completa para no dejarla a medias
    if (scFeedEstado.expandidos[publicacionId]) {
      await scFeedVerComentarios(publicacionId);
    } else {
      await scFeedCargar();
    }
  } catch (e) {
    scToast(e.message, 'error');
  } finally {
    if (btn) btn.disabled = false;
  }
}

async function scFeedVerComentarios(publicacionId) {
  try {
    const datos = await scGet('GET_COMENTARIOS', { publicacion_id: publicacionId });
    if (datos.status !== 'success') { scToast(datos.message || 'No se pudieron cargar.', 'error'); return; }

    scFeedEstado.expandidos[publicacionId] = datos.comentarios || [];

    const pub = scFeedEstado.publicaciones.find(p => Number(p.id) === Number(publicacionId));
    if (pub) pub.n_comentarios = (datos.comentarios || []).length;

    scFeedPintar();
  } catch (e) {
    scToast(e.message, 'error');
  }
}

async function scFeedBorrar(accion, id, publicacionId) {
  const esPublicacion = accion === 'borrar-publicacion';
  const pregunta = esPublicacion
    ? '¿Eliminar esta publicación?\n\nSe borra también su fotografía y sus comentarios.'
    : '¿Eliminar este comentario?';
  if (!confirm(pregunta)) return;

  try {
    const datos = await scPost(esPublicacion ? 'ELIMINAR_PUBLICACION' : 'ELIMINAR_COMENTARIO', { id });
    scToast(datos.message, datos.status === 'success' ? 'ok' : 'error');
    if (datos.status !== 'success') return;

    if (esPublicacion) {
      delete scFeedEstado.expandidos[id];
      scFeedCargar();
    } else if (scFeedEstado.expandidos[publicacionId]) {
      scFeedVerComentarios(publicacionId);
    } else {
      scFeedCargar();
    }
  } catch (e) {
    scToast(e.message, 'error');
  }
}

/**
 * Monta el muro dentro del contenedor indicado.
 * Los eventos se enganchan una sola vez, por delegación: el contenido se
 * redibuja entero en cada cambio y unos listeners directos se perderían.
 */
function scMontarFeed(contenedorId) {
  scFeedEstado.contenedor = contenedorId;
  const cont = document.getElementById(contenedorId);
  if (!cont) return;

  cont.addEventListener('click', (e) => {
    const el = e.target.closest('[data-feed]');
    if (!el) return;

    const id = Number(el.dataset.id || 0);
    switch (el.dataset.feed) {
      case 'elegir-imagen':
        document.getElementById('feed-archivo').click();
        break;
      case 'quitar-imagen':
        scFeedLimpiarImagen();
        scFeedPintar();
        break;
      case 'ver-comentarios':
        scFeedVerComentarios(id);
        break;
      case 'borrar-publicacion':
      case 'borrar-comentario':
        scFeedBorrar(el.dataset.feed, id, Number(el.dataset.pub || 0));
        break;
    }
  });

  cont.addEventListener('change', (e) => {
    if (e.target.id !== 'feed-archivo') return;

    const archivo = e.target.files && e.target.files[0];
    if (!archivo) return;

    if (archivo.size > 6 * 1024 * 1024) {
      scToast('La fotografía no puede pasar de 6 MB.', 'error');
      e.target.value = '';
      return;
    }

    // El texto ya escrito se conserva: scFeedPintar() lo redibuja vacío, así
    // que se guarda antes y se repone después.
    const campo = document.getElementById('feed-texto');
    const texto = campo ? campo.value : '';

    scFeedLimpiarImagen();
    scFeedEstado.archivo = archivo;
    scFeedEstado.previaUrl = URL.createObjectURL(archivo);
    scFeedPintar();

    const nuevo = document.getElementById('feed-texto');
    if (nuevo) nuevo.value = texto;
  });

  cont.addEventListener('input', (e) => {
    if (e.target.id === 'feed-texto') scFeedActualizarContador();
  });

  cont.addEventListener('submit', (e) => {
    e.preventDefault();

    if (e.target.id === 'feed-compositor') { scFeedPublicar(); return; }

    if (e.target.dataset.feed === 'form-comentario') {
      const campo = e.target.querySelector('input');
      scFeedComentar(Number(e.target.dataset.id), campo.value, e.target);
      campo.value = '';
    }
  });

  scFeedCargar();
}
