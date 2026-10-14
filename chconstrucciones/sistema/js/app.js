/* CH Arquitectura y Construcción — Gestión documental de obras.
 *
 * Cómo está organizado este archivo:
 *   1. Utilidades (formato de fechas, dinero, escapar texto).
 *   2. Permisos: qué puede ver y hacer cada rol (el servidor decide siempre;
 *      aquí sólo se ocultan los botones que no aplican).
 *   3. Piezas de interfaz reutilizables (modal, avisos, subir archivos).
 *   4. Vistas: una función por pantalla, cada una devuelve su HTML.
 *   5. Acciones: lo que pasa al pulsar un botón (data-accion="...").
 *   6. Arranque y navegación (la dirección #/obras, #/obra/12...).
 */
'use strict';

// ════════════════════════════════════════════════════════════════════
// 1. Utilidades
// ════════════════════════════════════════════════════════════════════
const $ = (sel, raiz = document) => raiz.querySelector(sel);
const $$ = (sel, raiz = document) => [...raiz.querySelectorAll(sel)];
const S = () => Store.s;
const CONFIG = () => Store.sesion?.config || {};

// Todo texto que venga de datos pasa por esc() antes de ir al HTML,
// así un nombre como "<script>" se muestra tal cual y no se ejecuta.
function esc(v) {
  return String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
// Para buscar sin importar acentos ni mayúsculas: "Bitácora" = "bitacora".
function normalizar(s) {
  return String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}
function aFecha(iso) {
  return iso.length === 10 ? new Date(iso + 'T12:00:00') : new Date(iso);
}
const fmt = {
  fecha(iso) {
    if (!iso) return '—';
    return aFecha(iso).toLocaleDateString('es-MX', { day: '2-digit', month: 'short', year: 'numeric' });
  },
  fechaHora(iso) {
    if (!iso) return '—';
    return aFecha(iso).toLocaleString('es-MX', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
  },
  fechaLarga(d = new Date()) {
    return d.toLocaleDateString('es-MX', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
  },
  moneda(n) {
    return Number(n || 0).toLocaleString('es-MX', { style: 'currency', currency: 'MXN', maximumFractionDigits: 0 });
  },
  tamano(b) {
    if (!b) return '—';
    if (b < 1024) return b + ' B';
    if (b < 1048576) return (b / 1024).toFixed(0) + ' KB';
    if (b < 1073741824) return (b / 1048576).toFixed(1) + ' MB';
    if (b < 1099511627776) return (b / 1073741824).toFixed(1) + ' GB';
    return (b / 1099511627776).toFixed(2) + ' TB';
  },
  relativo(iso) {
    if (!iso) return '—';
    const seg = (Date.now() - new Date(iso).getTime()) / 1000;
    if (seg < 60) return 'hace un momento';
    if (seg < 3600) return `hace ${Math.floor(seg / 60)} min`;
    if (seg < 86400) return `hace ${Math.floor(seg / 3600)} h`;
    const dias = Math.floor(seg / 86400);
    if (dias === 1) return 'ayer';
    if (dias < 7) return `hace ${dias} días`;
    return fmt.fecha(iso);
  },
};
function diasHasta(fecha) {
  return Math.round((aFecha(fecha) - aFecha(hoyISO())) / 86400000);
}
function iniciales(nombre) {
  return String(nombre || '?').split(/\s+/).map((p) => p[0]).slice(0, 2).join('').toUpperCase();
}

const obraPor = (id) => S().obras.find((o) => o.id === id);
const usuarioPor = (id) => S().usuarios.find((u) => u.id === id);
const nombreUsuario = (id) => usuarioPor(id)?.nombre || '—';
const carpetaPor = (id) => CARPETAS.find((c) => c.id === id);
const docPor = (id) => S().documentos.find((d) => d.id === id);
const docsDeObra = (obraId) => S().documentos.filter((d) => d.obraId === obraId);
const ultimaVersion = (doc) => doc.versiones[doc.versiones.length - 1];

// Íconos (trazos simples en SVG, sin librerías externas).
const trazo = (d) => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${d}</svg>`;
const IC = {
  buscar: trazo('<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>'),
  salir: trazo('<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>'),
  mas: trazo('<path d="M12 5v14M5 12h14"/>'),
  subir: trazo('<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5"/><path d="M12 3v12"/>'),
  descargar: trazo('<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/>'),
  ojo: trazo('<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>'),
  lapiz: trazo('<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>'),
  basura: trazo('<path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/>'),
  camara: trazo('<path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/>'),
  imprimir: trazo('<path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>'),
  menu: trazo('<path d="M3 6h18M3 12h18M3 18h18"/>'),
  historial: trazo('<path d="M3 3v5h5"/><path d="M3.05 13A9 9 0 1 0 6 5.3L3 8"/><path d="M12 7v5l4 2"/>'),
  equipo: trazo('<circle cx="12" cy="7" r="4"/><path d="M5.5 21a6.5 6.5 0 0 1 13 0"/>'),
  compartir: trazo('<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/>'),
  llave: trazo('<circle cx="7.5" cy="15.5" r="4.5"/><path d="M10.7 12.3L21 2M16 7l3 3M19 4l2 2"/>'),
  pulso: trazo('<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>'),
  carpeta: '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3 6a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>',
};

const CLASE_OBRA = { 'Planeación': 'info', 'En ejecución': 'marca', 'Suspendida': 'alerta', 'Terminada': 'ok' };
const CLASE_DOC = { 'Borrador': '', 'En revisión': 'aviso', 'Aprobado': 'ok', 'Obsoleto': 'alerta' };
const CLASE_PRES = { 'Borrador': '', 'Enviado al cliente': 'info', 'Aprobado': 'ok', 'Rechazado': 'alerta' };
const chip = (texto, clase = '') => `<span class="chip ${clase}">${esc(texto)}</span>`;
const chipRol = (rol) => chip(ROLES[rol]?.nombre || rol, ROLES[rol]?.color || '');

// Situación de un vencimiento: vencido, por vencer (≤30 días) o vigente.
function vencimiento(doc) {
  if (!doc.vence || doc.estado === 'Obsoleto') return null;
  const d = diasHasta(doc.vence);
  if (d < 0) return { clase: 'alerta', texto: `Vencido hace ${-d} d`, dias: d };
  if (d === 0) return { clase: 'alerta', texto: 'Vence hoy', dias: d };
  if (d <= 30) return { clase: 'aviso', texto: `Vence en ${d} d`, dias: d };
  return { clase: '', texto: fmt.fecha(doc.vence), dias: d };
}

// ════════════════════════════════════════════════════════════════════
// 2. Permisos (los mismos nombres que en src/permisos.php)
// ════════════════════════════════════════════════════════════════════
let usuario = null; // quien tiene la sesión abierta

const puede = (cap) => !!usuario && usuario.permisos.includes(cap);
const rolVeTodas = (rol) => !!ROLES[rol]?.caps.includes('ver_todas');
const puedeVer = (obraId) => puede('ver_todas') || usuario.obras.includes(obraId);
const puedeSubir = (obraId) => puede('subir') && puedeVer(obraId);
const puedeAprobar = (obraId) => puede('aprobar') && puedeVer(obraId);
const puedeVersionar = (d) => puedeSubir(d.obraId) || d.miPermiso === 'editar';
const puedeEditarDoc = (d) => puedeAprobar(d.obraId) || (d.usuarioId === usuario.id && puedeVersionar(d));
const puedeEditarPres = (p) => puede('presupuestos') && puedeVer(p.obraId) && (p.usuarioId === usuario.id || puede('ver_todas'));
const obrasVisibles = () => S().obras.filter((o) => !o.soloCompartido);
const docsVisibles = () => S().documentos.filter((d) => puedeVer(d.obraId));
const docsCompartidos = () => S().documentos.filter((d) => d.miPermiso);

// ════════════════════════════════════════════════════════════════════
// 3. Piezas de interfaz
// ════════════════════════════════════════════════════════════════════
function aviso(mensaje) {
  $$('.toast').forEach((t) => t.remove());
  const t = document.createElement('div');
  t.className = 'toast';
  t.textContent = mensaje;
  document.body.appendChild(t);
  setTimeout(() => t.remove(), 4000);
}

// Error "esperado" (validación): se muestra dentro del formulario.
class Invalido extends Error {}

function cerrarModal() {
  $('#modal')?.remove();
  document.removeEventListener('keydown', escCierraModal);
}
function escCierraModal(e) {
  if (e.key === 'Escape' && !$('#modal')?.dataset.ocupado) cerrarModal();
}
/* Abre una ventana emergente. Si recibe alEnviar, el contenido es un
   formulario: alEnviar(form) guarda los datos; si lanza un error, se
   muestra el mensaje y la ventana sigue abierta. */
function modal({ titulo, cuerpo, alEnviar, textoEnviar = 'Guardar', ancho = false, pie = null }) {
  cerrarModal();
  const fondo = document.createElement('div');
  fondo.className = 'modal-fondo';
  fondo.id = 'modal';
  const botones = pie ?? (alEnviar
    ? `<button type="button" class="btn" data-cerrar>Cancelar</button><button type="submit" class="btn primario">${esc(textoEnviar)}</button>`
    : '<button type="button" class="btn" data-cerrar>Cerrar</button>');
  fondo.innerHTML = `
    <form class="modal ${ancho ? 'ancho' : ''}" novalidate role="dialog" aria-modal="true" aria-label="${esc(titulo)}">
      <div class="modal-h"><h3>${esc(titulo)}</h3><button type="button" class="cerrar" data-cerrar aria-label="Cerrar">×</button></div>
      <div class="modal-b">${cuerpo}<div class="progreso-global oculto" aria-live="polite"><div class="barra"><i></i></div><span></span></div><div class="error oculto" role="alert"></div></div>
      <div class="modal-f">${botones}</div>
    </form>`;
  document.body.appendChild(fondo);
  const form = $('form', fondo);
  // Mientras se sube algo, no se cierra por accidente.
  fondo.addEventListener('mousedown', (e) => { if (e.target === fondo && !fondo.dataset.ocupado) cerrarModal(); });
  fondo.addEventListener('click', (e) => { if (e.target.closest('[data-cerrar]') && !fondo.dataset.ocupado) cerrarModal(); });
  document.addEventListener('keydown', escCierraModal);
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!alEnviar || fondo.dataset.ocupado) return;
    const error = $('.error', form);
    const boton = $('[type=submit]', form);
    error.classList.add('oculto');
    if (boton) boton.disabled = true;
    fondo.dataset.ocupado = '1';
    try {
      await alEnviar(form);
    } catch (ex) {
      if (!(ex instanceof Invalido) && !(ex instanceof ErrorServidor)) console.error(ex);
      error.textContent = ex instanceof Invalido || ex instanceof ErrorServidor ? ex.message : 'Ocurrió un error: ' + ex.message;
      error.classList.remove('oculto');
    } finally {
      delete fondo.dataset.ocupado;
      if (boton) boton.disabled = false;
    }
  });
  $('input:not([type=file]):not([type=checkbox]):not([type=radio]), select, textarea', form)?.focus();
  return form;
}
function confirmar(titulo, mensaje, alConfirmar, textoBoton = 'Eliminar') {
  modal({
    titulo,
    cuerpo: `<p>${mensaje}</p>`,
    pie: `<button type="button" class="btn" data-cerrar>Cancelar</button><button type="submit" class="btn primario">${esc(textoBoton)}</button>`,
    alEnviar: async () => { await alConfirmar(); },
  });
}

// Barra de progreso de la ventana: sirve para todas las subidas.
function progreso(form) {
  const caja = $('.progreso-global', form);
  return {
    fijar(texto, fraccion) {
      caja.classList.remove('oculto');
      $('span', caja).textContent = texto;
      $('i', caja).style.width = Math.round(Math.max(0, Math.min(1, fraccion)) * 100) + '%';
    },
    quitar() { caja.classList.add('oculto'); },
  };
}

/* Sube varios archivos uno por uno mostrando el avance total.
   cadaUno(archivoId, archivo, i) se llama al terminar cada archivo (por
   ejemplo, para crear el documento). */
async function subirArchivos(form, archivos, extra = {}, cadaUno = null) {
  const barra = progreso(form);
  const total = archivos.reduce((t, a) => t + a.size, 0) || 1;
  let hechos = 0;
  const ids = [];
  try {
    for (let i = 0; i < archivos.length; i++) {
      const a = archivos[i];
      const texto = archivos.length > 1 ? `Subiendo ${i + 1} de ${archivos.length}: ${a.name}` : `Subiendo ${a.name}`;
      barra.fijar(texto, hechos / total);
      const id = await Api.subir(a, extra, (f) => barra.fijar(texto, (hechos + f * a.size) / total));
      hechos += a.size;
      ids.push(id);
      if (cadaUno) await cadaUno(id, a, i);
    }
    barra.fijar('Listo', 1);
  } catch (e) {
    barra.quitar();
    e.subidos = ids.length;
    throw e;
  }
  return ids;
}

// Zona para arrastrar o elegir archivos.
const limiteBytes = () => (CONFIG().maxMb || 2048) * 1048576;
function campoArchivos({ multiple = false, accept = '', texto = 'Arrastra aquí o haz clic para elegir' } = {}) {
  const mb = CONFIG().maxMb || 2048;
  return `<label class="soltar" data-soltar>
      <input type="file" ${multiple ? 'multiple' : ''} ${accept ? `accept="${accept}"` : ''}>
      ${IC.subir.replace('<svg', '<svg style="width:22px;height:22px;display:block;margin:0 auto 6px"')}
      <b>${esc(texto)}</b><div class="ayuda" style="margin-top:4px">Máximo ${mb >= 1024 ? (mb / 1024).toFixed(mb % 1024 ? 1 : 0) + ' GB' : mb + ' MB'} por archivo.</div>
    </label><div class="lista-archivos" data-lista></div>`;
}
function montarArchivos(raiz, alCambiar) {
  const zona = $('[data-soltar]', raiz);
  const input = $('input[type=file]', zona);
  let archivos = [];
  const pintar = () => {
    $('[data-lista]', raiz).innerHTML = archivos.map((f, i) => `<div class="archivo-item">• ${esc(f.name)} <small>(${fmt.tamano(f.size)})</small>
      <button type="button" class="quitar" data-quitar-archivo="${i}" aria-label="Quitar ${esc(f.name)}">×</button></div>`).join('');
  };
  const actualizar = (lista) => {
    const nuevos = [...lista];
    const grandes = nuevos.filter((f) => f.size > limiteBytes());
    if (grandes.length) aviso(`Se omitieron ${grandes.length} archivo(s) más grandes que el máximo permitido.`);
    const vacios = nuevos.filter((f) => f.size === 0);
    if (vacios.length) aviso(`Se omitieron ${vacios.length} archivo(s) vacíos.`);
    const validos = nuevos.filter((f) => f.size > 0 && f.size <= limiteBytes());
    archivos = input.multiple ? archivos.concat(validos) : validos.slice(0, 1);
    if (alCambiar) alCambiar(archivos, validos);
    else pintar();
  };
  input.addEventListener('change', () => { actualizar(input.files); input.value = ''; });
  zona.addEventListener('dragover', (e) => { e.preventDefault(); zona.classList.add('encima'); });
  zona.addEventListener('dragleave', () => zona.classList.remove('encima'));
  zona.addEventListener('drop', (e) => { e.preventDefault(); zona.classList.remove('encima'); actualizar(e.dataTransfer.files); });
  $('[data-lista]', raiz).addEventListener('click', (e) => {
    const b = e.target.closest('[data-quitar-archivo]');
    if (!b) return;
    archivos.splice(Number(b.dataset.quitarArchivo), 1);
    pintar();
  });
  return { obtener: () => archivos };
}

// Las fotos de los reportes se reducen antes de subirse: una foto de
// celular pesa 4–8 MB y a 1600 px queda en ~300 KB sin perder detalle útil.
async function reducirImagen(archivo, maximo = 1600, calidad = 0.82) {
  if (!archivo.type.startsWith('image/') || archivo.type === 'image/gif') return archivo;
  const url = URL.createObjectURL(archivo);
  try {
    const img = await new Promise((ok, mal) => { const i = new Image(); i.onload = () => ok(i); i.onerror = mal; i.src = url; });
    const k = Math.min(1, maximo / Math.max(img.width, img.height));
    const lienzo = document.createElement('canvas');
    lienzo.width = Math.round(img.width * k);
    lienzo.height = Math.round(img.height * k);
    lienzo.getContext('2d').drawImage(img, 0, 0, lienzo.width, lienzo.height);
    const blob = await new Promise((ok) => lienzo.toBlob((b) => ok(b), 'image/jpeg', calidad));
    if (!blob) return archivo;
    return new File([blob], archivo.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' });
  } catch (e) {
    return archivo;
  } finally {
    URL.revokeObjectURL(url);
  }
}

function imgFoto(foto, extra = '') {
  return `<img src="${esc(Api.urlArchivo(foto.fileId, 'ver'))}" alt="${esc(foto.pie)}" ${extra}>`;
}

/* Abre un archivo en otra pestaña (ver) o lo descarga.
   La descarga usa un marco invisible: si el servidor responde con un error
   (por ejemplo, sin permiso), se muestra el mensaje en lugar de salir de la página. */
function abrirArchivo(fileId, modo = 'ver') {
  if (!fileId) { aviso('Este registro no tiene archivo.'); return; }
  const url = Api.urlArchivo(fileId, modo === 'ver' ? 'ver' : 'descargar');
  if (modo === 'ver') { window.open(url, '_blank', 'noopener'); return; }
  descargarUrl(url);
}
function descargarUrl(url) {
  const marco = document.createElement('iframe');
  marco.className = 'oculto';
  marco.addEventListener('load', () => {
    try {
      const j = JSON.parse(marco.contentDocument?.body?.innerText || '');
      if (j && j.error) aviso(j.error);
    } catch (e) { /* era la descarga o una página de otro dominio */ }
  });
  marco.src = url;
  document.body.appendChild(marco);
  setTimeout(() => marco.remove(), 120000);
}

function iconoExt(ext) {
  return `<span class="ext ${esc(ext)}">${esc(ext)}</span>`;
}
function avatar(u, chico = false) {
  return `<span class="avatar ${chico ? 'chico' : ''}" title="${esc(u?.nombre)}">${esc(iniciales(u?.nombre))}</span>`;
}
function barra(pct) {
  const p = Math.max(0, Math.min(100, Number(pct) || 0));
  return `<div class="barra-fila"><div class="barra"><i style="width:${p}%"></i></div><b>${p}%</b></div>`;
}
function opciones(lista, actual) {
  return lista.map((v) => {
    const [valor, texto] = Array.isArray(v) ? v : [v, v];
    return `<option value="${esc(valor)}" ${valor === actual ? 'selected' : ''}>${esc(texto)}</option>`;
  }).join('');
}
function vacio(texto) {
  return `<div class="vacio">${texto}</div>`;
}

// Después de guardar algo: pide de nuevo los datos y vuelve a pintar.
async function refrescar() {
  await Store.recargar();
  const yo = S().usuarios.find((u) => u.id === usuario.id);
  if (yo) usuario.obras = yo.obras;
  render();
}

// ════════════════════════════════════════════════════════════════════
// 4. Vistas
// ════════════════════════════════════════════════════════════════════
let mensajeLogin = '';
function vistaLogin() {
  return `<div class="login">
    <div class="login-col">
      <form class="login-caja" id="form-login" novalidate>
        <img class="login-logo" src="img/logo.png" alt="CH Arquitectura y Construcción, S.A. de C.V.">
        <h1 class="login-t">Gestión documental de obras</h1>
        <div class="campo"><label for="l-email">Correo</label><input type="email" id="l-email" autocomplete="username" required></div>
        <div class="campo"><label for="l-pass">Contraseña</label><input type="password" id="l-pass" autocomplete="current-password" required></div>
        <div class="error ${mensajeLogin ? '' : 'oculto'}" role="alert">${esc(mensajeLogin)}</div>
        <button type="submit" class="btn primario ancho">Entrar</button>
        <p class="login-ayuda">¿Olvidaste tu contraseña? Pide al administrador del sistema que te asigne una nueva.</p>
      </form>
    </div>
  </div>`;
}
function montarLogin() {
  const form = $('#form-login');
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const error = $('.error', form);
    const boton = $('[type=submit]', form);
    error.classList.add('oculto');
    boton.disabled = true;
    try {
      const j = await Api.post('auth/entrar', { email: $('#l-email').value.trim(), password: $('#l-pass').value });
      Store.aplicarSesion(j);
      mensajeLogin = '';
      await entrar(j.usuario);
    } catch (ex) {
      error.textContent = ex.message;
      error.classList.remove('oculto');
      boton.disabled = false;
    }
  });
  $('#l-email').focus();
}

// Estructura de todas las pantallas: cabecera con navegación horizontal,
// franja negra con el título de la página y el contenido debajo.
function shell(seccion, v) {
  const enlace = (href, clave, texto, extra = '') =>
    `<a class="nav-item ${seccion === clave ? 'activo' : ''}" href="${href}">${texto}${extra}</a>`;
  const porVencer = docsVisibles().filter((d) => { const x = vencimiento(d); return x && x.dias <= 30; }).length;
  const compartidos = docsCompartidos().length;
  const avisosAm = puede('archivo') ? avisosArchivo() : 0;
  return `<header class="cabecera no-imprimir" id="cabecera">
      <div class="cab-fila">
        <a class="cab-marca" href="#/inicio"><img src="img/marca.png" alt=""><span><b>CH</b><small>Arquitectura y Construcción</small></span></a>
        <nav class="cab-nav" aria-label="Principal">
          ${enlace('#/inicio', 'inicio', 'Inicio', porVencer ? `<span class="cuenta" title="Documentos vencidos o por vencer">${porVencer}</span>` : '')}
          ${enlace('#/obras', 'obras', 'Obras')}
          ${compartidos ? enlace('#/compartidos', 'compartidos', 'Compartidos', `<span class="cuenta gris" title="Documentos que te compartieron">${compartidos}</span>`) : ''}
          ${enlace('#/buscar', 'buscar', 'Buscar')}
          ${puede('archivo') ? enlace('#/archivo', 'archivo', 'Archivo muerto', avisosAm ? `<span class="cuenta" title="${puede('archivo_admin') ? 'Requisiciones por aprobar' : 'Requisiciones listas para descargar'}">${avisosAm}</span>` : '') : ''}
          ${puede('actividad') ? enlace('#/actividad', 'actividad', 'Actividad') : ''}
          ${puede('usuarios') ? enlace('#/usuarios', 'usuarios', 'Usuarios') : ''}
        </nav>
        <div class="cab-usuario">${avatar(usuario)}<button type="button" class="cab-usuario-t" data-accion="miCuenta" title="Mi cuenta"><b>${esc(usuario.nombre)}</b><small>${esc(ROLES[usuario.rol]?.nombre || usuario.rol)}</small></button>
          <button type="button" class="btn icono" data-accion="salir" title="Cerrar sesión" aria-label="Cerrar sesión">${IC.salir}</button></div>
        <button type="button" class="btn icono cab-menu" data-accion="menu" aria-label="Abrir menú" aria-expanded="false">${IC.menu}</button>
      </div>
    </header>
    <section class="titular">
      <div class="titular-fila">
        <div class="titular-texto">${v.migas ? `<div class="migas">${v.migas}</div>` : ''}<h1>${esc(v.titulo)}</h1>${v.sub ? `<div class="titular-sub">${v.sub}</div>` : ''}</div>
        ${v.acciones ? `<div class="acciones">${v.acciones}</div>` : ''}
      </div>
    </section>
    <main class="contenido">${v.html}</main>`;
}

function filaActividad(a, conObra = true) {
  const u = usuarioPor(a.usuarioId);
  const o = a.obraId ? obraPor(a.obraId) : null;
  return `<li>${avatar(u, true)}<div><div><b>${esc(u?.nombre || 'Sistema')}</b> ${esc(a.accion)}
    ${a.detalle ? `<b>${esc(a.detalle)}</b>` : ''}
    ${conObra && o && !o.soloCompartido ? ` · <a href="#/obra/${esc(o.id)}">${esc(o.clave)}</a>` : ''}</div>
    <div class="cuando">${fmt.relativo(a.fecha)}</div></div></li>`;
}

// ── Inicio ──────────────────────────────────────────────────────────
function vistaInicio() {
  const obras = obrasVisibles();
  const activas = obras.filter((o) => o.estado === 'En ejecución' || o.estado === 'Planeación');
  const docs = docsVisibles();
  const conVence = docs.map((d) => ({ d, v: vencimiento(d) })).filter((x) => x.v && x.v.dias <= 30).sort((a, b) => a.v.dias - b.v.dias);
  const vencidos = conVence.filter((x) => x.v.dias < 0).length;
  const enRevision = docs.filter((d) => d.estado === 'En revisión');
  const porAprobar = enRevision.filter((d) => puedeAprobar(d.obraId));
  const actividad = S().actividad.slice(0, 8);

  const kpis = `<div class="grid g-4" style="margin-bottom:18px">
    <div class="kpi"><div class="kpi-t">Obras activas</div><div class="kpi-v">${activas.length}</div><div class="kpi-s">${obras.length} en total</div></div>
    <div class="kpi"><div class="kpi-t">Documentos</div><div class="kpi-v">${docs.length}</div><div class="kpi-s">${enRevision.length} en revisión</div></div>
    <div class="kpi ${conVence.length - vencidos ? 'aviso' : ''}"><div class="kpi-t">Por vencer (30 días)</div><div class="kpi-v">${conVence.length - vencidos}</div><div class="kpi-s">Licencias, fianzas, permisos…</div></div>
    <div class="kpi ${vencidos ? 'alerta' : ''}"><div class="kpi-t">Vencidos</div><div class="kpi-v">${vencidos}</div><div class="kpi-s">Requieren renovación</div></div>
  </div>`;

  const listaObras = activas.length ? `<ul class="lista-simple">${activas.map((o) => `
    <li class="clic" data-ir="#/obra/${esc(o.id)}" style="cursor:pointer"><div style="flex:1;min-width:0">
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap"><span class="obra-clave">${esc(o.clave)}</span><b>${esc(o.nombre)}</b>${chip(o.estado, CLASE_OBRA[o.estado])}</div>
      <div style="margin-top:6px;max-width:360px">${barra(o.avance)}</div></div>
      <div class="der"><small style="color:var(--tinta-3)">${docsDeObra(o.id).length} documentos</small></div></li>`).join('')}</ul>`
    : vacio(puede('obras') ? 'Todavía no hay obras activas. Crea la primera con “Nueva obra”.' : 'No tienes obras activas asignadas.');

  const listaVence = conVence.length ? `<ul class="lista-simple">${conVence.slice(0, 8).map(({ d, v }) => `
    <li style="cursor:pointer" data-accion="verDoc" data-id="${esc(d.id)}">${iconoExt(d.ext)}<div style="min-width:0"><b style="font-weight:600">${esc(d.nombre)}</b>
      <div style="font-size:11.5px;color:var(--tinta-3)">${esc(obraPor(d.obraId)?.clave)} · ${esc(carpetaPor(d.carpeta)?.nombre)}</div></div>
      <div class="der">${chip(v.texto, v.clase)}</div></li>`).join('')}</ul>`
    : vacio('Sin vencimientos en los próximos 30 días.');

  const listaRevision = porAprobar.length ? `<ul class="lista-simple">${porAprobar.slice(0, 6).map((d) => `
    <li style="cursor:pointer" data-accion="verDoc" data-id="${esc(d.id)}">${iconoExt(d.ext)}<div style="min-width:0"><b style="font-weight:600">${esc(d.nombre)}</b>
      <div style="font-size:11.5px;color:var(--tinta-3)">${esc(obraPor(d.obraId)?.clave)} · subió ${esc(nombreUsuario(ultimaVersion(d).usuarioId))}</div></div></li>`).join('')}</ul>`
    : vacio('No hay documentos esperando revisión.');

  return {
    titulo: 'Inicio',
    sub: `Hola, ${esc(usuario.nombre.split(' ')[0])}. Hoy es ${esc(fmt.fechaLarga())}.`,
    acciones: puede('obras') ? `<button class="btn primario" data-accion="nuevaObra">${IC.mas}Nueva obra</button>` : '',
    html: `${kpis}
      <div class="grid g-2-1">
        <div>
          <div class="card"><div class="card-h"><span class="card-t">${puede('ver_todas') ? 'Obras activas' : 'Mis obras'}</span><a class="derecha" href="#/obras" style="font-size:12.5px">Ver todas</a></div>${listaObras}</div>
          <div class="card"><div class="card-h"><span class="card-t">Actividad reciente</span>${puede('actividad') ? '<a class="derecha" href="#/actividad" style="font-size:12.5px">Ver todo</a>' : ''}</div>
            ${actividad.length ? `<ul class="actividad">${actividad.map((a) => filaActividad(a)).join('')}</ul>` : vacio('Sin actividad todavía.')}</div>
        </div>
        <div>
          <div class="card"><div class="card-h"><span class="card-t">Vencimientos</span><span class="card-s">próximos 30 días</span></div>${listaVence}</div>
          ${puede('aprobar') ? `<div class="card"><div class="card-h"><span class="card-t">Pendientes de revisión</span></div>${listaRevision}</div>` : ''}
          ${docsCompartidos().length ? `<div class="card"><div class="card-h"><span class="card-t">Compartidos conmigo</span><a class="derecha" href="#/compartidos" style="font-size:12.5px">Ver todos</a></div>
            <ul class="lista-simple">${docsCompartidos().slice(0, 5).map((d) => `<li style="cursor:pointer" data-accion="verDoc" data-id="${esc(d.id)}">${iconoExt(d.ext)}<div style="min-width:0"><b style="font-weight:600">${esc(d.nombre)}</b>
              <div style="font-size:11.5px;color:var(--tinta-3)">${esc(obraPor(d.obraId)?.clave)}</div></div><div class="der">${chipPermiso(d.miPermiso)}</div></li>`).join('')}</ul></div>` : ''}
        </div>
      </div>`,
  };
}
const chipPermiso = (p) => (p === 'editar' ? chip('Puede editar', 'info') : chip('Sólo ver', ''));

// ── Obras ───────────────────────────────────────────────────────────
function tarjetaObra(o) {
  const docs = docsDeObra(o.id).length;
  const reps = S().reportes.filter((r) => r.obraId === o.id).length;
  const texto = normalizar([o.clave, o.nombre, o.cliente, o.ubicacion, nombreUsuario(o.residenteId)].join(' '));
  return `<div class="obra-card" data-ir="#/obra/${esc(o.id)}" data-texto="${esc(texto)}" data-estado="${esc(o.estado)}">
    <div style="display:flex;gap:8px;align-items:center"><span class="obra-clave">${esc(o.clave)}</span><span style="margin-left:auto">${chip(o.estado, CLASE_OBRA[o.estado])}</span></div>
    <div class="obra-nombre">${esc(o.nombre)}</div>
    <div class="obra-meta"><span>${esc(o.cliente)}</span><span>${esc(o.ubicacion)}</span></div>
    ${barra(o.avance)}
    <div class="obra-pie"><span>${docs} documentos</span><span>${reps} reportes</span><span style="margin-left:auto">${esc(o.residenteId ? nombreUsuario(o.residenteId) : '')}</span></div>
  </div>`;
}
function vistaObras() {
  const obras = obrasVisibles();
  return {
    titulo: 'Obras',
    sub: puede('ver_todas') ? 'Todas las obras de la empresa' : 'Obras en las que participas',
    acciones: puede('obras') ? `<button class="btn primario" data-accion="nuevaObra">${IC.mas}Nueva obra</button>` : '',
    html: `<div class="filtros">
        <div class="buscador">${IC.buscar}<input type="search" id="f-obras" placeholder="Buscar por nombre, clave, cliente…" aria-label="Buscar obras"></div>
        <select class="sel" id="f-estado" aria-label="Filtrar por estado"><option value="">Todos los estados</option>${opciones(ESTADOS_OBRA)}</select>
      </div>
      ${obras.length ? `<div class="grid g-3" id="lista-obras">${obras.map(tarjetaObra).join('')}</div><div id="obras-vacio" class="oculto">${vacio('Ninguna obra coincide con el filtro.')}</div>`
        : `<div class="card">${vacio(puede('obras') ? 'Todavía no hay obras. Crea la primera con “Nueva obra”.' : 'No tienes obras asignadas. Pide al administrador que te asigne.')}</div>`}`,
    montar() {
      const filtrar = () => {
        const q = normalizar($('#f-obras').value.trim());
        const est = $('#f-estado').value;
        let visibles = 0;
        $$('#lista-obras .obra-card').forEach((c) => {
          const ok = (!q || c.dataset.texto.includes(q)) && (!est || c.dataset.estado === est);
          c.classList.toggle('oculto', !ok);
          if (ok) visibles++;
        });
        $('#obras-vacio')?.classList.toggle('oculto', visibles > 0);
      };
      $('#f-obras')?.addEventListener('input', filtrar);
      $('#f-estado')?.addEventListener('change', filtrar);
    },
  };
}

// ── Detalle de una obra ─────────────────────────────────────────────
function vistaObra(id, pestana = 'resumen', sub) {
  const o = obraPor(id);
  if (!o || !puedeVer(id)) return vistaNoEncontrada('No encontramos esta obra o no tienes acceso a ella.');
  const docs = docsDeObra(id);
  const presupuestos = S().presupuestos.filter((p) => p.obraId === id);
  const reportes = S().reportes.filter((r) => r.obraId === id);
  const equipo = S().usuarios.filter((u) => !rolVeTodas(u.rol) && u.obras.includes(id));
  if (!['documentos', 'presupuestos', 'reportes', 'equipo', 'actividad'].includes(pestana)) pestana = 'resumen';
  const tabs = [
    ['resumen', 'Resumen', null],
    ['documentos', 'Documentos', docs.length],
    ['presupuestos', 'Presupuestos', presupuestos.length],
    ['reportes', 'Reportes fotográficos', reportes.length],
    ['equipo', 'Equipo', equipo.length],
    ['actividad', 'Actividad', null],
  ];
  const tabsHtml = `<nav class="tabs no-imprimir">${tabs.map(([k, t, n]) =>
    `<a class="tab ${pestana === k ? 'activo' : ''}" href="#/obra/${esc(id)}/${k}">${t}${n !== null ? `<span class="n">${n}</span>` : ''}</a>`).join('')}</nav>`;

  let cuerpo;
  let acciones = '';
  let montar = null;
  switch (pestana) {
    case 'documentos': {
      const r = pestanaDocumentos(o, docs, sub);
      cuerpo = r.html; montar = r.montar;
      if (puedeSubir(id)) acciones = `<button class="btn primario" data-accion="subirDoc" data-obra="${esc(id)}" data-carpeta="${esc(sub || '')}">${IC.subir}Subir documento</button>`;
      break;
    }
    case 'presupuestos':
      cuerpo = pestanaPresupuestos(o, presupuestos);
      if (puede('presupuestos')) acciones = `<button class="btn primario" data-accion="nuevoPresupuesto" data-obra="${esc(id)}">${IC.mas}Nuevo presupuesto</button>`;
      break;
    case 'reportes':
      if (sub) return vistaReporte(o, sub);
      cuerpo = pestanaReportes(o, reportes);
      if (puede('reportes')) acciones = `<button class="btn primario" data-accion="nuevoReporte" data-obra="${esc(id)}">${IC.camara}Nuevo reporte</button>`;
      break;
    case 'equipo':
      cuerpo = pestanaEquipo(o, equipo);
      if (puede('obras')) acciones = `<button class="btn primario" data-accion="asignarEquipo" data-obra="${esc(id)}">${IC.equipo}Asignar personal</button>`;
      break;
    case 'actividad': {
      const lista = S().actividad.filter((a) => a.obraId === id);
      cuerpo = `<div class="card">${lista.length ? `<ul class="actividad">${lista.map((a) => filaActividad(a, false)).join('')}</ul>` : vacio('Sin actividad registrada.')}</div>`;
      break;
    }
    default:
      cuerpo = pestanaResumen(o, docs);
      if (puede('obras')) acciones = `<button class="btn" data-accion="editarObra" data-id="${esc(id)}">${IC.lapiz}Editar obra</button>`;
      if (puedeSubir(id)) acciones += `<button class="btn primario" data-accion="subirDoc" data-obra="${esc(id)}">${IC.subir}Subir documento</button>`;
  }
  return {
    titulo: o.nombre,
    migas: `<a href="#/obras">Obras</a> / ${esc(o.clave)}`,
    sub: `${chip(o.estado, CLASE_OBRA[o.estado])} &nbsp;${[o.cliente, o.ubicacion].filter(Boolean).map(esc).join(' · ')}`,
    acciones,
    html: tabsHtml + cuerpo,
    montar,
  };
}

function pestanaResumen(o, docs) {
  const porCarpeta = CARPETAS.map((c) => ({ c, n: docs.filter((d) => d.carpeta === c.id).length }));
  const completas = porCarpeta.filter((x) => x.n > 0).length;
  const vences = docs.map((d) => ({ d, v: vencimiento(d) })).filter((x) => x.v).sort((a, b) => a.v.dias - b.v.dias);
  const act = S().actividad.filter((a) => a.obraId === o.id).slice(0, 6);
  return `<div class="grid g-2-1">
    <div>
      <div class="card"><div class="card-h"><span class="card-t">Datos de la obra</span></div><div class="card-b">
        <dl class="ficha">
          <div><dt>Clave</dt><dd>${esc(o.clave)}</dd></div>
          <div><dt>Tipo</dt><dd>${esc(o.tipo || '—')}</dd></div>
          <div><dt>Cliente</dt><dd>${esc(o.cliente)}</dd></div>
          <div><dt>Residente</dt><dd>${esc(o.residenteId ? nombreUsuario(o.residenteId) : '—')}</dd></div>
          <div><dt>Inicio</dt><dd>${fmt.fecha(o.inicio)}</dd></div>
          <div><dt>Término</dt><dd>${fmt.fecha(o.fin)}</dd></div>
          <div><dt>Monto de contrato</dt><dd>${fmt.moneda(o.monto)}</dd></div>
          <div><dt>Avance físico</dt><dd>${barra(o.avance)}</dd></div>
        </dl>
        ${o.descripcion ? `<p style="margin-top:14px;color:var(--tinta-2);font-size:13px">${esc(o.descripcion)}</p>` : ''}
      </div></div>
      <div class="card"><div class="card-h"><span class="card-t">Expediente de obra</span><span class="card-s">${completas} de ${CARPETAS.length} carpetas con documentos</span></div>
        <ul class="lista-simple">${porCarpeta.map(({ c, n }) => `
          <li><a class="carpeta" style="flex:1;padding:0" href="#/obra/${esc(o.id)}/documentos/${c.id}">${IC.carpeta}<span>${esc(c.nombre)}</span></a>
            <span class="der">${n ? `<b>${n}</b> <small style="color:var(--tinta-3)">doc.</small>` : '<small style="color:var(--tinta-3)">vacía</small>'}</span></li>`).join('')}</ul></div>
    </div>
    <div>
      <div class="card"><div class="card-h"><span class="card-t">Vencimientos de la obra</span></div>
        ${vences.length ? `<ul class="lista-simple">${vences.map(({ d, v }) => `<li style="cursor:pointer" data-accion="verDoc" data-id="${esc(d.id)}"><span style="flex:1">${esc(d.nombre)}</span><span class="der">${chip(v.texto, v.clase)}</span></li>`).join('')}</ul>`
          : vacio('Ningún documento de esta obra tiene fecha de vencimiento.')}</div>
      <div class="card"><div class="card-h"><span class="card-t">Actividad reciente</span></div>
        ${act.length ? `<ul class="actividad">${act.map((a) => filaActividad(a, false)).join('')}</ul>` : vacio('Sin actividad todavía.')}</div>
    </div></div>`;
}

function filaDocumento(d, mostrarCarpeta, mostrarObra = false) {
  const ult = ultimaVersion(d);
  const v = vencimiento(d);
  const texto = normalizar([d.nombre, d.etiquetas.join(' '), d.descripcion, ult.nombreArchivo].join(' '));
  const detalle = [mostrarObra ? esc(obraPor(d.obraId)?.clave) : '', mostrarCarpeta ? esc(carpetaPor(d.carpeta)?.nombre) : ''].filter(Boolean).join(' · ');
  return `<tr class="clic" data-accion="verDoc" data-id="${esc(d.id)}" data-texto="${esc(texto)}" data-estado="${esc(d.estado)}">
    <td><div class="doc-nombre">${iconoExt(d.ext)}<div><b>${esc(d.nombre)}</b>
      <small>${detalle}${detalle ? ' · ' : ''}${d.etiquetas.map((t) => `<span class="tag">${esc(t)}</span>`).join('')}${d.compartidos.length ? ` <span class="tag" title="Compartido con ${d.compartidos.length} persona(s)">compartido</span>` : ''}</small></div></div></td>
    <td>${chip(d.estado, CLASE_DOC[d.estado])}</td>
    <td>${mostrarObra && d.miPermiso ? chipPermiso(d.miPermiso) + '<br>' : ''}v${ult.v}</td>
    <td>${v ? chip(v.texto, v.clase) : '<span style="color:var(--tinta-3)">—</span>'}</td>
    <td><div style="font-size:12.5px">${fmt.fecha(ult.fecha)}</div><small style="color:var(--tinta-3)">${esc(nombreUsuario(ult.usuarioId))}</small></td>
    <td class="acc"><button type="button" class="btn icono chico" data-accion="descargarDoc" data-id="${esc(d.id)}" title="Descargar" aria-label="Descargar">${IC.descargar}</button></td>
  </tr>`;
}
function pestanaDocumentos(o, docs, carpetaId) {
  const carpeta = carpetaPor(carpetaId);
  const lista = (carpeta ? docs.filter((d) => d.carpeta === carpeta.id) : docs)
    .slice().sort((a, b) => ultimaVersion(b).fecha.localeCompare(ultimaVersion(a).fecha));
  const nav = `<nav class="carpetas" aria-label="Carpetas">
    <a class="carpeta ${!carpeta ? 'activo' : ''}" href="#/obra/${esc(o.id)}/documentos">${IC.carpeta}<span>Todos los documentos</span><span class="n">${docs.length}</span></a>
    ${CARPETAS.map((c) => `<a class="carpeta ${carpeta?.id === c.id ? 'activo' : ''}" href="#/obra/${esc(o.id)}/documentos/${c.id}">${IC.carpeta}<span>${esc(c.nombre)}</span><span class="n">${docs.filter((d) => d.carpeta === c.id).length || ''}</span></a>`).join('')}
  </nav>`;
  const tabla = lista.length ? `<div class="tabla-wrap"><table class="tabla">
      <thead><tr><th>Documento</th><th>Estado</th><th>Versión</th><th>Vencimiento</th><th>Actualizado</th><th></th></tr></thead>
      <tbody id="tabla-docs">${lista.map((d) => filaDocumento(d, !carpeta)).join('')}</tbody></table></div>
      <div id="docs-vacio" class="oculto">${vacio('Ningún documento coincide con el filtro.')}</div>`
    : vacio(puedeSubir(o.id) ? 'Esta carpeta está vacía. Usa “Subir documento” para agregar el primero.' : 'Esta carpeta está vacía.');
  return {
    html: `<div class="docs-layout">${nav}
      <div class="card" style="margin:0"><div class="card-h"><div><div class="card-t">${esc(carpeta ? carpeta.nombre : 'Todos los documentos')}</div>
          <div class="carpeta-desc">${esc(carpeta ? carpeta.desc : 'Expediente completo de la obra.')}</div></div>
        <div class="derecha"><div class="buscador" style="min-width:160px">${IC.buscar}<input type="search" id="f-docs" placeholder="Filtrar…" aria-label="Filtrar documentos"></div>
          <select class="sel" id="f-estado-doc" aria-label="Filtrar por estado"><option value="">Todos</option>${opciones(ESTADOS_DOC)}</select></div></div>
        ${tabla}</div></div>`,
    montar() {
      const filtrar = () => {
        const q = normalizar($('#f-docs').value.trim());
        const est = $('#f-estado-doc').value;
        let n = 0;
        $$('#tabla-docs tr').forEach((tr) => {
          const ok = (!q || tr.dataset.texto.includes(q)) && (!est || tr.dataset.estado === est);
          tr.classList.toggle('oculto', !ok);
          if (ok) n++;
        });
        $('#docs-vacio')?.classList.toggle('oculto', n > 0);
      };
      $('#f-docs').addEventListener('input', filtrar);
      $('#f-estado-doc').addEventListener('change', filtrar);
    },
  };
}

function pestanaPresupuestos(o, lista) {
  const suma = (est) => lista.filter((p) => est.includes(p.estado)).reduce((t, p) => t + Number(p.monto || 0), 0);
  const ordenados = lista.slice().sort((a, b) => b.fecha.localeCompare(a.fecha));
  return `<div class="grid g-3" style="margin-bottom:18px">
      <div class="kpi"><div class="kpi-t">Aprobado</div><div class="kpi-v" style="font-size:22px">${fmt.moneda(suma(['Aprobado']))}</div><div class="kpi-s">Contrato + adicionales aprobados</div></div>
      <div class="kpi"><div class="kpi-t">En trámite</div><div class="kpi-v" style="font-size:22px">${fmt.moneda(suma(['Borrador', 'Enviado al cliente']))}</div><div class="kpi-s">Borradores y enviados</div></div>
      <div class="kpi"><div class="kpi-t">Rechazado</div><div class="kpi-v" style="font-size:22px">${fmt.moneda(suma(['Rechazado']))}</div><div class="kpi-s">Se conserva como antecedente</div></div>
    </div>
    <div class="card">${ordenados.length ? `<div class="tabla-wrap"><table class="tabla">
      <thead><tr><th>Folio</th><th>Concepto</th><th>Fecha</th><th class="num">Monto</th><th>Estado</th><th>Elaboró</th></tr></thead>
      <tbody>${ordenados.map((p) => `<tr class="clic" data-accion="verPresupuesto" data-id="${esc(p.id)}">
        <td><b>${esc(p.folio)}</b></td><td>${esc(p.concepto)}</td><td>${fmt.fecha(p.fecha)}</td>
        <td class="num">${fmt.moneda(p.monto)}</td><td>${chip(p.estado, CLASE_PRES[p.estado])}</td><td>${esc(nombreUsuario(p.usuarioId))}</td></tr>`).join('')}</tbody></table></div>`
    : vacio('Aún no hay presupuestos registrados para esta obra.')}</div>`;
}

function pestanaReportes(o, lista) {
  if (!lista.length) return `<div class="card">${vacio(puede('reportes') ? 'Sin reportes todavía. Crea uno con “Nuevo reporte” y sube las fotos desde el celular o la computadora.' : 'Sin reportes fotográficos todavía.')}</div>`;
  const ordenados = lista.slice().sort((a, b) => b.fecha.localeCompare(a.fecha));
  return `<div class="grid g-3">${ordenados.map((r) => {
    const cuatro = r.fotos.slice(0, 4);
    while (cuatro.length < 4) cuatro.push(null);
    return `<div class="reporte-card" data-ir="#/obra/${esc(o.id)}/reportes/${esc(r.id)}">
      <div class="mosaico">${cuatro.map((f) => f ? imgFoto(f, 'loading="lazy"') : '<div></div>').join('')}</div>
      <div class="reporte-info"><b>${esc(r.titulo)}</b><small>${fmt.fecha(r.fecha)} · ${r.fotos.length} fotos · ${esc(nombreUsuario(r.usuarioId))}</small></div></div>`;
  }).join('')}</div>`;
}

function vistaReporte(o, reporteId) {
  const r = S().reportes.find((x) => x.id === reporteId && x.obraId === o.id);
  if (!r) return vistaNoEncontrada('No encontramos este reporte.');
  const acciones = `<a class="btn" href="#/obra/${esc(o.id)}/reportes">Volver</a>
    <button class="btn primario" data-accion="imprimir">${IC.imprimir}Imprimir / guardar PDF</button>
    ${puede('eliminar') || r.usuarioId === usuario.id ? `<button class="btn peligro" data-accion="eliminarReporte" data-id="${esc(r.id)}">${IC.basura}Eliminar</button>` : ''}`;
  return {
    titulo: r.titulo,
    migas: `<a href="#/obras">Obras</a> / <a href="#/obra/${esc(o.id)}/reportes">${esc(o.clave)}</a> / Reporte fotográfico`,
    sub: `${fmt.fecha(r.fecha)} · ${r.fotos.length} fotos`,
    acciones,
    html: `<div class="hoja">
      <div class="hoja-enc"><img class="hoja-logo" src="img/marca.png" alt="CH Arquitectura y Construcción"><div><h2>Reporte fotográfico</h2><div style="font-size:12.5px;color:var(--tinta-2)">CH Arquitectura y Construcción, S.A. de C.V.</div></div>
        <div class="der"><b style="color:var(--tinta)">${esc(r.periodo || '')}</b><br>${fmt.fecha(r.fecha)}</div></div>
      <div class="hoja-datos">
        <div><b>Obra</b>${esc(o.nombre)}</div><div><b>Clave</b>${esc(o.clave)}</div><div><b>Cliente</b>${esc(o.cliente)}</div>
        <div><b>Ubicación</b>${esc(o.ubicacion)}</div><div><b>Elaboró</b>${esc(nombreUsuario(r.usuarioId))}</div><div><b>Avance físico</b>${Number(o.avance) || 0}%</div>
      </div>
      ${r.descripcion ? `<p style="font-size:13px;margin-bottom:14px">${esc(r.descripcion)}</p>` : ''}
      <div class="fotos-grid">${r.fotos.map((f, i) => `<figure class="foto">${imgFoto(f, `data-accion="verFoto" data-reporte="${esc(r.id)}" data-i="${i}"`)}
        <figcaption><b>Foto ${i + 1}.</b>${esc(f.pie)}</figcaption></figure>`).join('')}</div>
      <div class="hoja-firmas"><div>${esc(nombreUsuario(r.usuarioId))}<br><small>Elaboró</small></div><div>&nbsp;<br><small>Vo. Bo. Supervisión</small></div></div>
    </div>`,
  };
}

function pestanaEquipo(o, equipo) {
  const generales = S().usuarios.filter((u) => rolVeTodas(u.rol) && u.activo);
  const fila = (u, nota) => `<li>${avatar(u)}<div><b>${esc(u.nombre)}</b><div style="font-size:12px;color:var(--tinta-3)">${esc([u.puesto, u.email].filter(Boolean).join(' · '))}</div></div>
    <div class="der">${chipRol(u.rol)}${nota ? `<div style="font-size:11px;color:var(--tinta-3);margin-top:3px">${nota}</div>` : ''}</div></li>`;
  return `<div class="grid g-2">
    <div class="card"><div class="card-h"><span class="card-t">Personal asignado</span><span class="card-s">ve y trabaja esta obra</span></div>
      ${equipo.length ? `<ul class="lista-simple">${equipo.map((u) => fila(u, u.id === o.residenteId ? 'Residente responsable' : (u.activo ? '' : 'Desactivado'))).join('')}</ul>` : vacio('Nadie asignado todavía.')}</div>
    <div class="card"><div class="card-h"><span class="card-t">Con acceso a todas las obras</span></div>
      ${generales.length ? `<ul class="lista-simple">${generales.map((u) => fila(u)).join('')}</ul>` : vacio('—')}</div></div>`;
}

// ── Compartidos conmigo ─────────────────────────────────────────────
function vistaCompartidos() {
  const lista = docsCompartidos().slice().sort((a, b) => ultimaVersion(b).fecha.localeCompare(ultimaVersion(a).fecha));
  return {
    titulo: 'Compartidos conmigo',
    sub: 'Documentos que otras personas te compartieron, aunque no estés asignado a su obra',
    html: `<div class="card">${lista.length ? `<div class="tabla-wrap"><table class="tabla">
        <thead><tr><th>Documento</th><th>Estado</th><th>Versión</th><th>Vencimiento</th><th>Actualizado</th><th></th></tr></thead>
        <tbody>${lista.map((d) => filaDocumento(d, true, true)).join('')}</tbody></table></div>`
      : vacio('Nadie te ha compartido documentos todavía.')}</div>`,
  };
}

// ── Búsqueda ────────────────────────────────────────────────────────
function vistaBuscar(q) {
  const t = normalizar(q.trim());
  let resultados = '';
  if (t) {
    const contiene = (...campos) => normalizar(campos.join(' ')).includes(t);
    const obras = obrasVisibles().filter((o) => contiene(o.clave, o.nombre, o.cliente, o.ubicacion, o.descripcion));
    const docs = S().documentos.filter((d) => contiene(d.nombre, d.etiquetas.join(' '), d.descripcion, carpetaPor(d.carpeta)?.nombre, ...d.versiones.map((v) => v.nombreArchivo)));
    const pres = S().presupuestos.filter((p) => contiene(p.folio, p.concepto, p.notas));
    const reps = S().reportes.filter((r) => contiene(r.titulo, r.descripcion, r.periodo, ...r.fotos.map((f) => f.pie)));
    const total = obras.length + docs.length + pres.length + reps.length;
    const seccion = (titulo, n, html) => n ? `<div class="card"><div class="card-h"><span class="card-t">${titulo}</span><span class="card-s">${n}</span></div>${html}</div>` : '';
    resultados = total === 0 ? `<div class="card">${vacio(`Sin resultados para “${esc(q)}”.`)}</div>` :
      seccion('Obras', obras.length, `<ul class="lista-simple">${obras.map((o) => `<li style="cursor:pointer" data-ir="#/obra/${esc(o.id)}"><span class="obra-clave">${esc(o.clave)}</span><b>${esc(o.nombre)}</b><span class="der">${chip(o.estado, CLASE_OBRA[o.estado])}</span></li>`).join('')}</ul>`) +
      seccion('Documentos', docs.length, `<div class="tabla-wrap"><table class="tabla"><tbody>${docs.map((d) => `<tr class="clic" data-accion="verDoc" data-id="${esc(d.id)}">
          <td><div class="doc-nombre">${iconoExt(d.ext)}<div><b>${esc(d.nombre)}</b><small>${esc(obraPor(d.obraId)?.clave)} · ${esc(carpetaPor(d.carpeta)?.nombre)}</small></div></div></td>
          <td>${chip(d.estado, CLASE_DOC[d.estado])}</td><td>v${ultimaVersion(d).v}</td></tr>`).join('')}</tbody></table></div>`) +
      seccion('Presupuestos', pres.length, `<ul class="lista-simple">${pres.map((p) => `<li style="cursor:pointer" data-accion="verPresupuesto" data-id="${esc(p.id)}"><b>${esc(p.folio)}</b><span>${esc(p.concepto)}</span><span class="der">${fmt.moneda(p.monto)}<br><small style="color:var(--tinta-3)">${esc(obraPor(p.obraId)?.clave)}</small></span></li>`).join('')}</ul>`) +
      seccion('Reportes fotográficos', reps.length, `<ul class="lista-simple">${reps.map((r) => `<li style="cursor:pointer" data-ir="#/obra/${esc(r.obraId)}/reportes/${esc(r.id)}">${IC.camara.replace('<svg', '<svg width="17" height="17"')}<b>${esc(r.titulo)}</b><span class="der"><small style="color:var(--tinta-3)">${esc(obraPor(r.obraId)?.clave)} · ${fmt.fecha(r.fecha)}</small></span></li>`).join('')}</ul>`);
  }
  return {
    titulo: 'Buscar',
    sub: 'Busca en obras, documentos, etiquetas, presupuestos y pies de foto',
    html: `<form id="form-buscar" class="filtros" role="search">
        <div class="buscador" style="max-width:520px">${IC.buscar}<input type="search" id="q" value="${esc(q)}" placeholder="Ej.: fianza, licencia, estimación, colado…" aria-label="Buscar"></div>
        <button class="btn primario" type="submit">Buscar</button></form>
      ${resultados || `<div class="card">${vacio('Escribe una palabra para buscar. No importan acentos ni mayúsculas.')}</div>`}`,
    montar() {
      const input = $('#q');
      input.focus();
      input.setSelectionRange(input.value.length, input.value.length);
      $('#form-buscar').addEventListener('submit', (e) => {
        e.preventDefault();
        location.hash = '#/buscar?q=' + encodeURIComponent(input.value.trim());
      });
    },
  };
}

// ── Usuarios (sólo administrador) ───────────────────────────────────
const PERMISOS_TEXTO = [
  ['ver_todas', 'Ver obras', 'Todas', 'Asignadas'],
  ['obras', 'Crear y editar obras, asignar personal'],
  ['subir', 'Subir documentos y nuevas versiones'],
  ['presupuestos', 'Crear y editar presupuestos'],
  ['reportes', 'Publicar reportes fotográficos'],
  ['aprobar', 'Aprobar documentos / marcar obsoletos'],
  ['eliminar', 'Eliminar documentos, presupuestos y reportes'],
  ['compartir', 'Compartir documentos con otras personas'],
  ['actividad', 'Ver la bitácora de todo el sistema'],
  ['archivo', 'Consultar el archivo muerto'],
  ['archivo_admin', 'Aprobar requisiciones y cargar al archivo muerto'],
  ['usuarios', 'Administrar usuarios y diagnóstico'],
];
function vistaUsuarios() {
  const lista = S().usuarios;
  const filas = lista.map((u) => `<tr class="clic" data-accion="editarUsuario" data-id="${esc(u.id)}" data-texto="${esc(normalizar([u.nombre, u.email, u.puesto, ROLES[u.rol]?.nombre].join(' ')))}">
    <td><div class="doc-nombre" style="min-width:200px">${avatar(u)}<div><b>${esc(u.nombre)}</b><small>${esc(u.email)}</small></div></div></td>
    <td>${esc(u.puesto)}</td><td>${chipRol(u.rol)}</td>
    <td>${rolVeTodas(u.rol) ? '<span style="color:var(--tinta-3)">Todas</span>' : (u.obras.map((id) => esc(obraPor(id)?.clave || '')).filter(Boolean).join(', ') || '<span style="color:var(--tinta-3)">Ninguna</span>')}</td>
    <td>${u.activo ? chip('Activo', 'ok') : chip('Inactivo', 'alerta')}</td>
    <td class="acc"><button type="button" class="btn chico" data-accion="editarUsuario" data-id="${esc(u.id)}">${IC.lapiz}Editar</button></td></tr>`).join('');
  const si = '<span style="color:var(--ok);font-weight:700">✓</span>';
  const no = '<span style="color:var(--tinta-3)">—</span>';
  const roles = Object.entries(ROLES);
  const activos = lista.filter((u) => u.activo).length;
  return {
    titulo: 'Usuarios',
    sub: `${activos} usuario(s) activo(s) · quién entra al sistema, con qué rol y a qué obras`,
    acciones: `<a class="btn" href="#/diagnostico">${IC.pulso}Diagnóstico</a><button class="btn primario" data-accion="nuevoUsuario">${IC.mas}Nuevo usuario</button>`,
    html: `<div class="filtros"><div class="buscador">${IC.buscar}<input type="search" id="f-usuarios" placeholder="Buscar por nombre, correo, puesto o rol…" aria-label="Buscar usuarios"></div></div>
      <div class="card"><div class="tabla-wrap"><table class="tabla">
        <thead><tr><th>Usuario</th><th>Puesto</th><th>Rol</th><th>Obras</th><th>Estado</th><th></th></tr></thead><tbody id="tabla-usuarios">${filas}</tbody></table></div></div>
      <div class="card"><div class="card-h"><span class="card-t">Qué puede hacer cada rol</span><span class="card-s">${roles.length} roles</span></div><div class="tabla-wrap"><table class="tabla matriz">
        <thead><tr><th>Permiso</th>${roles.map(([, r]) => `<th>${esc(r.nombre)}</th>`).join('')}</tr></thead>
        <tbody>${PERMISOS_TEXTO.map(([cap, texto, siTexto, noTexto]) => `<tr><td>${esc(texto)}</td>${roles.map(([, r]) => {
          const tiene = r.caps.includes(cap);
          return `<td>${siTexto ? `<span style="${tiene ? 'font-weight:600' : 'color:var(--tinta-3)'}">${tiene ? siTexto : noTexto}</span>` : (tiene ? si : no)}</td>`;
        }).join('')}</tr>`).join('')}</tbody></table></div></div>`,
    montar() {
      $('#f-usuarios').addEventListener('input', (e) => {
        const q = normalizar(e.target.value.trim());
        $$('#tabla-usuarios tr').forEach((tr) => tr.classList.toggle('oculto', !!q && !tr.dataset.texto.includes(q)));
      });
    },
  };
}

// ── Diagnóstico del servidor y del almacenamiento ───────────────────
function vistaDiagnostico() {
  return {
    titulo: 'Diagnóstico',
    migas: '<a href="#/usuarios">Usuarios</a> / Diagnóstico',
    sub: 'Prueba que el almacenamiento funcione: guarda, lee y borra un archivo de prueba',
    acciones: `<button class="btn primario" data-accion="repetirDiagnostico">Probar otra vez</button>`,
    html: `<div id="diag">${vacio('Probando el almacenamiento…')}</div>`,
    async montar() {
      const caja = $('#diag');
      try {
        const d = await Api.get('sistema/diagnostico');
        const nube = d.almacen.trabajo.driver === 's3' || d.almacen.archivo.driver === 's3';
        const linea = (titulo, r) => `<li><span style="flex:1"><b>${titulo}</b><div style="font-size:12.5px;color:var(--tinta-3)">Tipo: ${esc(r.driver === 's3' ? 'Nube compatible con S3' : 'Disco del servidor')} · ${esc(r.detalle)}</div></span>
          <span class="der">${r.ok ? chip('Funciona', 'ok') : chip('Con error', 'alerta')}</span></li>`;
        caja.innerHTML = `<div class="grid g-2">
          <div class="card"><div class="card-h"><span class="card-t">Almacenamiento</span></div><ul class="lista-simple">
            ${linea('Archivos de trabajo (obras, versiones, fotos)', d.almacen.trabajo)}
            ${linea('Archivo muerto', d.almacen.archivo)}</ul></div>
          <div class="card"><div class="card-h"><span class="card-t">Servidor</span></div><div class="card-b"><dl class="ficha">
            <div><dt>Versión de PHP</dt><dd>${esc(d.php.version)}</dd></div>
            <div><dt>Memoria</dt><dd>${esc(d.php.memory_limit)}</dd></div>
            <div><dt>Archivo muerto</dt><dd>${d.archivoModo === 'frio' ? 'En frío (con requisición)' : 'Acceso inmediato'}</dd></div>
            <div><dt>Avisos por correo</dt><dd>${d.correo ? 'Activados' : 'Desactivados'}</dd></div>
          </dl></div></div></div>
          <div class="card"><div class="card-h"><span class="card-t">Subidas desde el navegador</span></div><div class="card-b">
            <p style="font-size:13.5px;margin-bottom:12px">Los archivos viajan directo del navegador al almacenamiento, sin pasar por el servidor.
              ${nube ? 'Para eso la nube debe aceptar este sitio: si la prueba falla, pulsa <b>Configurar subidas</b> (sólo se hace una vez, o al cambiar de dominio).' : 'Con almacenamiento local no se necesita configurar nada.'}</p>
            <div class="acciones"><button type="button" class="btn" data-accion="probarSubida">${IC.subir}Probar subida</button>
              ${nube ? '<button type="button" class="btn" data-accion="configurarCors">Configurar subidas</button>' : ''}</div>
            <div id="diag-subida" style="margin-top:12px;font-size:13.5px"></div></div></div>`;
      } catch (e) {
        caja.innerHTML = `<div class="card">${vacio(esc(e.message))}</div>`;
      }
    },
  };
}

// ── Actividad global ────────────────────────────────────────────────
function vistaActividad() {
  const lista = S().actividad;
  return {
    titulo: 'Actividad',
    sub: `Registro de lo que se sube, cambia, descarga o elimina (últimos ${lista.length} movimientos)`,
    html: `<div class="filtros"><select class="sel" id="f-act" aria-label="Filtrar por obra"><option value="">Todas las obras</option>${opciones(obrasVisibles().map((o) => [o.id, `${o.clave} · ${o.nombre}`]))}</select></div>
      <div class="card">${lista.length ? `<ul class="actividad" id="lista-act">${lista.map((a) => filaActividad(a).replace('<li>', `<li data-obra="${esc(a.obraId)}">`)).join('')}</ul>` : vacio('Sin actividad.')}</div>`,
    montar() {
      $('#f-act').addEventListener('change', (e) => {
        $$('#lista-act li').forEach((li) => li.classList.toggle('oculto', !!e.target.value && li.dataset.obra !== e.target.value));
      });
    },
  };
}

function vistaNoEncontrada(texto) {
  return { titulo: 'No disponible', html: `<div class="card">${vacio(esc(texto) + '<br><br><a href="#/inicio">Volver al inicio</a>')}</div>` };
}

// ════════════════════════════════════════════════════════════════════
// 5. Acciones (formularios y botones)
// ════════════════════════════════════════════════════════════════════
function valor(form, nombre) {
  const el = form.elements[nombre];
  return el ? String(el.value).trim() : '';
}
const listaEtiquetas = (texto) => texto.split(',').map((t) => t.trim()).filter(Boolean);

// ── Obras ───────────────────────────────────────────────────────────
function siguienteClave() {
  const anio = new Date().getFullYear();
  const usadas = S().obras.map((o) => o.clave).filter((c) => c.startsWith(`CH-${anio}-`)).map((c) => parseInt(c.split('-')[2], 10) || 0);
  return `CH-${anio}-${String(Math.max(0, ...usadas) + 1).padStart(3, '0')}`;
}
function formObra(o) {
  const nuevo = !o;
  o = o || { clave: siguienteClave(), nombre: '', cliente: '', ubicacion: '', tipo: 'Edificación', estado: 'Planeación', residenteId: '', inicio: hoyISO(), fin: '', monto: '', avance: 0, descripcion: '' };
  const residentes = S().usuarios.filter((u) => (u.rol === 'residente' && u.activo) || u.id === o.residenteId);
  modal({
    titulo: nuevo ? 'Nueva obra' : 'Editar obra',
    textoEnviar: nuevo ? 'Crear obra' : 'Guardar cambios',
    cuerpo: `
      <div class="fila"><div class="campo"><label>Clave</label><input type="text" name="clave" value="${esc(o.clave)}"></div>
        <div class="campo"><label>Tipo de obra</label><select name="tipo">${opciones(TIPOS_OBRA.includes(o.tipo) || !o.tipo ? TIPOS_OBRA : [...TIPOS_OBRA, o.tipo], o.tipo)}</select></div></div>
      <div class="campo"><label>Nombre de la obra *</label><input type="text" name="nombre" value="${esc(o.nombre)}" placeholder="Ej.: Nave industrial Parque Norte"></div>
      <div class="fila"><div class="campo"><label>Cliente *</label><input type="text" name="cliente" value="${esc(o.cliente)}"></div>
        <div class="campo"><label>Ubicación</label><input type="text" name="ubicacion" value="${esc(o.ubicacion)}"></div></div>
      <div class="fila"><div class="campo"><label>Residente responsable</label><select name="residenteId"><option value="">— Sin asignar —</option>${opciones(residentes.map((u) => [u.id, u.nombre]), o.residenteId)}</select></div>
        <div class="campo"><label>Estado</label><select name="estado">${opciones(ESTADOS_OBRA, o.estado)}</select></div></div>
      <div class="fila"><div class="campo"><label>Fecha de inicio</label><input type="date" name="inicio" value="${esc(o.inicio)}"></div>
        <div class="campo"><label>Fecha de término</label><input type="date" name="fin" value="${esc(o.fin)}"></div></div>
      <div class="fila"><div class="campo"><label>Monto de contrato (MXN)</label><input type="number" name="monto" min="0" step="0.01" value="${esc(o.monto)}"></div>
        <div class="campo"><label>Avance físico (%)</label><input type="number" name="avance" min="0" max="100" value="${esc(o.avance)}"></div></div>
      <div class="campo"><label>Descripción</label><textarea name="descripcion">${esc(o.descripcion)}</textarea></div>
      ${nuevo ? '<div class="ayuda" style="font-size:12px;color:var(--tinta-3)">Al crearla se genera automáticamente el expediente con las 9 carpetas estándar.</div>' : ''}`,
    alEnviar: async (f) => {
      const datos = {
        id: o.id, clave: valor(f, 'clave'), nombre: valor(f, 'nombre'), cliente: valor(f, 'cliente'), ubicacion: valor(f, 'ubicacion'),
        tipo: valor(f, 'tipo'), estado: valor(f, 'estado'), residenteId: valor(f, 'residenteId'), inicio: valor(f, 'inicio'),
        fin: valor(f, 'fin'), monto: Number(valor(f, 'monto')) || 0, avance: Math.max(0, Math.min(100, Number(valor(f, 'avance')) || 0)),
        descripcion: valor(f, 'descripcion'),
      };
      if (!datos.nombre) throw new Invalido('Escribe el nombre de la obra.');
      if (!datos.cliente) throw new Invalido('Escribe el cliente.');
      if (datos.inicio && datos.fin && datos.fin < datos.inicio) throw new Invalido('La fecha de término es anterior a la de inicio.');
      const r = await Api.post('obras/guardar', datos);
      cerrarModal();
      location.hash = `#/obra/${r.id}`;
      await refrescar();
      aviso(nuevo ? 'Obra creada con su expediente.' : 'Cambios guardados.');
    },
  });
}

function asignarEquipo(obraId) {
  const o = obraPor(obraId);
  const candidatos = S().usuarios.filter((u) => !rolVeTodas(u.rol) && (u.activo || u.obras.includes(obraId)));
  const form = modal({
    titulo: `Personal de ${o.clave}`,
    textoEnviar: 'Guardar asignación',
    cuerpo: `<p style="font-size:13px;color:var(--tinta-2);margin-bottom:10px">Marca quién puede ver y trabajar esta obra. Los roles con acceso a todas las obras no necesitan asignación.</p>
      ${candidatos.length > 8 ? `<div class="buscador" style="margin-bottom:8px">${IC.buscar}<input type="search" id="f-equipo" placeholder="Buscar persona…" aria-label="Buscar persona"></div>` : ''}
      <div class="checks" id="lista-equipo">${candidatos.map((u) => `<label data-texto="${esc(normalizar(u.nombre + ' ' + u.puesto))}"><input type="checkbox" name="u" value="${esc(u.id)}" ${u.obras.includes(obraId) ? 'checked' : ''}>
        ${esc(u.nombre)} <small style="color:var(--tinta-3)">(${esc(ROLES[u.rol]?.nombre || u.rol)}${u.activo ? '' : ', inactivo'})</small></label>`).join('') || 'No hay usuarios que requieran asignación.'}</div>`,
    alEnviar: async (f) => {
      await Api.post('obras/equipo', { obraId, usuarios: $$('input[name=u]:checked', f).map((i) => i.value) });
      cerrarModal();
      await refrescar();
      aviso('Asignación guardada.');
    },
  });
  $('#f-equipo', form)?.addEventListener('input', (e) => {
    const q = normalizar(e.target.value.trim());
    $$('#lista-equipo label', form).forEach((l) => l.classList.toggle('oculto', !!q && !l.dataset.texto.includes(q)));
  });
}

// ── Documentos ──────────────────────────────────────────────────────
const estadosParaSubir = (obraId) => (puedeAprobar(obraId) ? ['Borrador', 'En revisión', 'Aprobado'] : ['Borrador', 'En revisión']);

function formSubirDoc(obraId, carpetaId) {
  const o = obraPor(obraId);
  if (!puedeSubir(obraId)) return;
  const form = modal({
    titulo: `Subir documento · ${o.clave}`,
    textoEnviar: 'Subir',
    cuerpo: `
      ${campoArchivos({ multiple: true, texto: 'Arrastra aquí los archivos o haz clic para elegir' })}
      <div class="campo" style="margin-top:14px"><label>Carpeta *</label><select name="carpeta">${opciones(CARPETAS.map((c) => [c.id, c.nombre]), carpetaId || 'informes')}</select></div>
      <div class="campo"><label>Nombre del documento</label><input type="text" name="nombre" placeholder="Si lo dejas vacío se usa el nombre del archivo">
        <div class="ayuda">Sólo aplica si subes un archivo. Con varios, cada uno conserva su nombre.</div></div>
      <div class="fila"><div class="campo"><label>Estado</label><select name="estado">${opciones(estadosParaSubir(obraId), 'En revisión')}</select></div>
        <div class="campo"><label>Fecha de vencimiento</label><input type="date" name="vence"><div class="ayuda">Para licencias, fianzas, permisos, DC-3…</div></div></div>
      <div class="campo"><label>Etiquetas</label><input type="text" name="etiquetas" placeholder="Separadas por coma: fianza, municipal"></div>
      <div class="campo"><label>Descripción</label><textarea name="descripcion" placeholder="Opcional"></textarea></div>`,
    alEnviar: async (f) => {
      const archivos = selector.obtener();
      if (!archivos.length) throw new Invalido('Elige al menos un archivo.');
      const comun = {
        obraId, carpeta: valor(f, 'carpeta'), estado: valor(f, 'estado'), vence: valor(f, 'vence'),
        etiquetas: listaEtiquetas(valor(f, 'etiquetas')), descripcion: valor(f, 'descripcion'),
      };
      const nombreManual = archivos.length === 1 ? valor(f, 'nombre') : '';
      let creados = 0;
      try {
        await subirArchivos(f, archivos, {}, async (archivoId) => {
          await Api.post('documentos/crear', { ...comun, archivoId, nombre: nombreManual });
          creados++;
        });
      } catch (e) {
        if (creados) { await Store.recargar(); e.message += ` (Se guardaron ${creados} de ${archivos.length}.)`; }
        throw e;
      }
      cerrarModal();
      location.hash = `#/obra/${obraId}/documentos/${comun.carpeta}`;
      await refrescar();
      aviso(archivos.length === 1 ? 'Documento subido.' : `${archivos.length} documentos subidos.`);
    },
  });
  const selector = montarArchivos(form);
}

function verDoc(id) {
  const d = docPor(id);
  if (!d) return;
  const o = obraPor(d.obraId);
  const ult = ultimaVersion(d);
  const v = vencimiento(d);
  const deLaObra = puedeVer(d.obraId);
  const versiones = d.versiones.slice().reverse().map((ver) => `<li><span class="v">v${ver.v}</span>
      <div style="flex:1;min-width:0"><div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(ver.nombreArchivo)}</div>
      <small style="color:var(--tinta-3)">${fmt.fecha(ver.fecha)} · ${esc(nombreUsuario(ver.usuarioId))} · ${fmt.tamano(ver.tamano)}${ver.nota ? ' · ' + esc(ver.nota) : ''}</small></div>
      <button type="button" class="btn icono chico" data-accion="abrirVersion" data-doc="${esc(d.id)}" data-v="${ver.v}" data-modo="ver" title="Ver" aria-label="Ver versión ${ver.v}">${IC.ojo}</button>
      <button type="button" class="btn icono chico" data-accion="abrirVersion" data-doc="${esc(d.id)}" data-v="${ver.v}" data-modo="descargar" title="Descargar" aria-label="Descargar versión ${ver.v}">${IC.descargar}</button></li>`).join('');
  const compartidoCon = d.compartidos.map((c) => `<span class="tag">${esc(nombreUsuario(c.usuarioId))} · ${c.permiso === 'editar' ? 'edita' : 've'}</span>`).join('');
  const ubicacion = deLaObra
    ? `<a href="#/obra/${esc(o.id)}/documentos/${esc(d.carpeta)}" data-cerrar>${esc(o.clave)} · ${esc(carpetaPor(d.carpeta)?.nombre)}</a>`
    : `${esc(o?.clave)} · ${esc(carpetaPor(d.carpeta)?.nombre)} <span class="tag">compartido contigo</span>`;
  const form = modal({
    titulo: d.nombre,
    ancho: true,
    cuerpo: `
      <div style="display:flex;gap:12px;align-items:center;margin-bottom:16px;flex-wrap:wrap">${iconoExt(d.ext)}
        <div><div style="font-size:12.5px;color:var(--tinta-2)">${ubicacion}</div>
        <div style="margin-top:4px;display:flex;gap:6px;flex-wrap:wrap">${chip(d.estado, CLASE_DOC[d.estado])}${v ? chip(v.texto, v.clase) : ''}${d.miPermiso ? chipPermiso(d.miPermiso) : ''}</div></div>
        <div style="margin-left:auto;display:flex;gap:6px;flex-wrap:wrap">
          <button type="button" class="btn" data-accion="abrirVersion" data-doc="${esc(d.id)}" data-v="${ult.v}" data-modo="ver">${IC.ojo}Ver</button>
          <button type="button" class="btn" data-accion="abrirVersion" data-doc="${esc(d.id)}" data-v="${ult.v}" data-modo="descargar">${IC.descargar}Descargar</button>
          ${puede('compartir') && deLaObra ? `<button type="button" class="btn" data-accion="compartirDoc" data-id="${esc(d.id)}">${IC.compartir}Compartir</button>` : ''}
          ${puedeVersionar(d) ? `<button type="button" class="btn primario" data-accion="nuevaVersion" data-id="${esc(d.id)}">${IC.subir}Nueva versión</button>` : ''}
        </div></div>
      <dl class="ficha" style="margin-bottom:18px">
        <div><dt>Versión vigente</dt><dd>v${ult.v} · ${fmt.tamano(ult.tamano)}</dd></div>
        <div><dt>Última actualización</dt><dd>${fmt.fecha(ult.fecha)}</dd></div>
        <div><dt>Subió</dt><dd>${esc(nombreUsuario(ult.usuarioId))}</dd></div>
        <div><dt>Vencimiento</dt><dd>${d.vence ? fmt.fecha(d.vence) : '—'}</dd></div>
        <div style="grid-column:1/-1"><dt>Etiquetas</dt><dd>${d.etiquetas.length ? d.etiquetas.map((t) => `<span class="tag">${esc(t)}</span>`).join('') : '—'}</dd></div>
        ${d.descripcion ? `<div style="grid-column:1/-1"><dt>Descripción</dt><dd>${esc(d.descripcion)}</dd></div>` : ''}
        ${compartidoCon ? `<div style="grid-column:1/-1"><dt>Compartido con</dt><dd>${compartidoCon}</dd></div>` : ''}
      </dl>
      ${puedeAprobar(d.obraId) ? `<div class="campo" style="max-width:320px"><label>Estado del documento</label>
        <div style="display:flex;gap:8px"><select id="estado-doc">${opciones(ESTADOS_DOC, d.estado)}</select></div>
        <div class="ayuda">Aprobado = versión válida para usar en obra. Obsoleto = se conserva pero ya no aplica.</div></div>` : ''}
      <div style="display:flex;align-items:center;gap:8px;margin:6px 0 8px">${IC.historial.replace('<svg', '<svg width="16" height="16"')}<b style="font-size:13px">Historial de versiones</b></div>
      <ul class="versiones">${versiones}</ul>`,
    pie: `${puede('eliminar') && deLaObra ? `<button type="button" class="btn peligro" data-accion="eliminarDoc" data-id="${esc(d.id)}" style="margin-right:auto">${IC.basura}Eliminar</button>` : ''}
      ${puedeEditarDoc(d) ? `<button type="button" class="btn" data-accion="editarDoc" data-id="${esc(d.id)}">${IC.lapiz}Editar datos</button>` : ''}
      <button type="button" class="btn" data-cerrar>Cerrar</button>`,
  });
  $('#estado-doc', form)?.addEventListener('change', async (e) => {
    try {
      await Api.post('documentos/estado', { id: d.id, estado: e.target.value });
      await refrescar();
      verDoc(d.id);
      aviso('Estado actualizado.');
    } catch (ex) { aviso(ex.message); e.target.value = d.estado; }
  });
}

function formEditarDoc(id) {
  const d = docPor(id);
  modal({
    titulo: 'Editar datos del documento',
    cuerpo: `
      <div class="campo"><label>Nombre *</label><input type="text" name="nombre" value="${esc(d.nombre)}"></div>
      <div class="fila"><div class="campo"><label>Carpeta</label><select name="carpeta">${opciones(CARPETAS.map((c) => [c.id, c.nombre]), d.carpeta)}</select></div>
        <div class="campo"><label>Fecha de vencimiento</label><input type="date" name="vence" value="${esc(d.vence)}"></div></div>
      <div class="campo"><label>Etiquetas</label><input type="text" name="etiquetas" value="${esc(d.etiquetas.join(', '))}"></div>
      <div class="campo"><label>Descripción</label><textarea name="descripcion">${esc(d.descripcion)}</textarea></div>`,
    alEnviar: async (f) => {
      const nombre = valor(f, 'nombre');
      if (!nombre) throw new Invalido('El nombre no puede quedar vacío.');
      await Api.post('documentos/editar', {
        id: d.id, nombre, carpeta: valor(f, 'carpeta'), vence: valor(f, 'vence'), descripcion: valor(f, 'descripcion'),
        etiquetas: listaEtiquetas(valor(f, 'etiquetas')),
      });
      await refrescar();
      verDoc(d.id);
      aviso('Datos actualizados.');
    },
  });
}

function formNuevaVersion(id) {
  const d = docPor(id);
  const form = modal({
    titulo: `Nueva versión · ${d.nombre}`,
    textoEnviar: 'Subir versión',
    cuerpo: `${campoArchivos({ texto: 'Elige el archivo de la nueva versión' })}
      <div class="campo" style="margin-top:14px"><label>¿Qué cambió?</label><input type="text" name="nota" placeholder="Ej.: se corrigen cotas del eje 4"></div>
      <div class="ayuda" style="font-size:12px;color:var(--tinta-3)">La versión anterior se conserva en el historial.${puedeAprobar(d.obraId) ? '' : ' El documento pasa a «En revisión» hasta que alguien con permiso lo apruebe.'}</div>`,
    alEnviar: async (f) => {
      const [archivo] = selector.obtener();
      if (!archivo) throw new Invalido('Elige el archivo.');
      const [archivoId] = await subirArchivos(f, [archivo]);
      const r = await Api.post('documentos/version', { documentoId: d.id, archivoId, nota: valor(f, 'nota') });
      await refrescar();
      verDoc(d.id);
      aviso(`Versión ${r.version} guardada.`);
    },
  });
  const selector = montarArchivos(form);
}

function formCompartir(id) {
  const d = docPor(id);
  const actual = Object.fromEntries(d.compartidos.map((c) => [c.usuarioId, c.permiso]));
  const personas = S().usuarios.filter((u) => u.id !== usuario.id && (u.activo || actual[u.id]));
  const conAcceso = (u) => rolVeTodas(u.rol) || u.obras.includes(d.obraId);
  const fila = (u) => `<li data-texto="${esc(normalizar(u.nombre + ' ' + u.puesto + ' ' + (ROLES[u.rol]?.nombre || '')))}">${avatar(u, true)}
      <div style="flex:1;min-width:0"><b style="font-weight:600">${esc(u.nombre)}</b><div style="font-size:12px;color:var(--tinta-3)">${esc([u.puesto, ROLES[u.rol]?.nombre].filter(Boolean).join(' · '))}</div></div>
      <div class="der">${conAcceso(u) && !actual[u.id] ? '<small style="color:var(--tinta-3)">Ya ve la obra</small>'
        : `<select class="sel chico" name="p-${esc(u.id)}" aria-label="Permiso para ${esc(u.nombre)}">${opciones([['', 'Sin acceso'], ['ver', 'Puede ver'], ['editar', 'Puede editar']], actual[u.id] || '')}</select>`}</div></li>`;
  const form = modal({
    titulo: `Compartir · ${d.nombre}`,
    textoEnviar: 'Guardar',
    ancho: true,
    cuerpo: `<p style="font-size:13px;color:var(--tinta-2);margin-bottom:10px">Comparte este documento con personas que no están en la obra. <b>Puede ver</b>: lo consulta y descarga.
        <b>Puede editar</b>: además sube nuevas versiones. A quien agregues le llega un aviso y lo encuentra en «Compartidos».</p>
      <div class="buscador" style="margin-bottom:8px">${IC.buscar}<input type="search" id="f-compartir" placeholder="Buscar persona…" aria-label="Buscar persona"></div>
      <ul class="lista-simple compartir-lista" id="lista-compartir">${personas.map(fila).join('') || vacio('No hay más usuarios.')}</ul>`,
    alEnviar: async (f) => {
      const usuarios = personas.map((u) => ({ id: u.id, permiso: f.elements['p-' + u.id]?.value || '' })).filter((x) => x.permiso);
      await Api.post('documentos/compartir', { id: d.id, usuarios });
      await refrescar();
      verDoc(d.id);
      aviso(usuarios.length ? `Compartido con ${usuarios.length} persona(s).` : 'Ya no está compartido con nadie.');
    },
  });
  $('#f-compartir', form).addEventListener('input', (e) => {
    const q = normalizar(e.target.value.trim());
    $$('#lista-compartir li', form).forEach((li) => li.classList.toggle('oculto', !!q && !li.dataset.texto?.includes(q)));
  });
}

// ── Presupuestos ────────────────────────────────────────────────────
function siguienteFolio(obraId) {
  const n = S().presupuestos.filter((p) => p.obraId === obraId).map((p) => parseInt(p.folio.replace(/\D/g, ''), 10) || 0);
  return 'PRE-' + String(Math.max(0, ...n) + 1).padStart(3, '0');
}
function formPresupuesto(obraId, p) {
  const nuevo = !p;
  p = p || { folio: siguienteFolio(obraId), concepto: '', monto: '', estado: 'Borrador', fecha: hoyISO(), notas: '' };
  const estados = puede('aprobar') || puede('ver_todas') ? ESTADOS_PRESUPUESTO.slice() : ['Borrador', 'Enviado al cliente'];
  if (!estados.includes(p.estado)) estados.push(p.estado);
  const form = modal({
    titulo: nuevo ? 'Nuevo presupuesto' : `Editar ${p.folio}`,
    cuerpo: `
      <div class="fila"><div class="campo"><label>Folio</label><input type="text" name="folio" value="${esc(p.folio)}"></div>
        <div class="campo"><label>Fecha</label><input type="date" name="fecha" value="${esc(p.fecha)}"></div></div>
      <div class="campo"><label>Concepto *</label><input type="text" name="concepto" value="${esc(p.concepto)}" placeholder="Ej.: Adicional de cimentación"></div>
      <div class="fila"><div class="campo"><label>Monto (MXN, sin IVA) *</label><input type="number" name="monto" min="0" step="0.01" value="${esc(p.monto)}"></div>
        <div class="campo"><label>Estado</label><select name="estado">${opciones(estados, p.estado)}</select></div></div>
      <div class="campo"><label>Archivo del presupuesto${nuevo ? '' : ' (déjalo vacío para conservar el actual)'}</label>${campoArchivos({ texto: 'Excel, PDF u Opus exportado' })}</div>
      <div class="campo"><label>Notas</label><textarea name="notas">${esc(p.notas)}</textarea></div>`,
    alEnviar: async (f) => {
      const concepto = valor(f, 'concepto');
      const monto = Number(valor(f, 'monto'));
      if (!concepto) throw new Invalido('Escribe el concepto.');
      if (!(monto > 0)) throw new Invalido('Escribe un monto mayor a cero.');
      const [archivo] = selector.obtener();
      const archivoId = archivo ? (await subirArchivos(f, [archivo]))[0] : null;
      await Api.post('presupuestos/guardar', {
        id: p.id, obraId, folio: valor(f, 'folio'), fecha: valor(f, 'fecha'), concepto, monto,
        estado: valor(f, 'estado'), notas: valor(f, 'notas'), archivoId,
      });
      cerrarModal();
      location.hash = `#/obra/${obraId}/presupuestos`;
      await refrescar();
      aviso('Presupuesto guardado.');
    },
  });
  const selector = montarArchivos(form);
}
function verPresupuesto(id) {
  const p = S().presupuestos.find((x) => x.id === id);
  if (!p) return;
  const o = obraPor(p.obraId);
  modal({
    titulo: `${p.folio} · ${p.concepto}`,
    cuerpo: `<dl class="ficha">
        <div><dt>Obra</dt><dd>${esc(o?.clave)}</dd></div>
        <div><dt>Fecha</dt><dd>${fmt.fecha(p.fecha)}</dd></div>
        <div><dt>Monto</dt><dd><b>${fmt.moneda(p.monto)}</b></dd></div>
        <div><dt>Estado</dt><dd>${chip(p.estado, CLASE_PRES[p.estado])}</dd></div>
        <div><dt>Elaboró</dt><dd>${esc(nombreUsuario(p.usuarioId))}</dd></div>
        <div><dt>Archivo</dt><dd>${p.fileId ? `<a href="#" data-accion="archivoPresupuesto" data-id="${esc(p.id)}">${esc(p.nombreArchivo)}</a>` : '—'}</dd></div>
        ${p.notas ? `<div style="grid-column:1/-1"><dt>Notas</dt><dd>${esc(p.notas)}</dd></div>` : ''}</dl>`,
    pie: `${puede('eliminar') ? `<button type="button" class="btn peligro" data-accion="eliminarPresupuesto" data-id="${esc(p.id)}" style="margin-right:auto">${IC.basura}Eliminar</button>` : ''}
      ${puedeEditarPres(p) ? `<button type="button" class="btn" data-accion="editarPresupuesto" data-id="${esc(p.id)}">${IC.lapiz}Editar</button>` : ''}
      <button type="button" class="btn" data-cerrar>Cerrar</button>`,
  });
}

// ── Reportes fotográficos ───────────────────────────────────────────
function formReporte(obraId) {
  const semana = `Semana ${Math.ceil((Date.now() - new Date(new Date().getFullYear(), 0, 1)) / 604800000)}`;
  const fotos = []; // { archivo, url, pie }
  const form = modal({
    titulo: `Nuevo reporte fotográfico · ${obraPor(obraId).clave}`,
    ancho: true,
    textoEnviar: 'Publicar reporte',
    cuerpo: `
      <div class="fila"><div class="campo"><label>Título *</label><input type="text" name="titulo" value="Reporte fotográfico ${esc(semana.toLowerCase())}"></div>
        <div class="fila"><div class="campo"><label>Fecha</label><input type="date" name="fecha" value="${hoyISO()}"></div>
          <div class="campo"><label>Periodo</label><input type="text" name="periodo" value="${esc(semana)}"></div></div></div>
      <div class="campo"><label>Descripción general</label><textarea name="descripcion" placeholder="Resumen de los trabajos del periodo"></textarea></div>
      ${campoArchivos({ multiple: true, accept: 'image/*', texto: 'Agrega fotos (desde el celular puedes usar la cámara)' })}
      <div class="prev-fotos" id="prev-fotos"></div>`,
    alEnviar: async (f) => {
      const titulo = valor(f, 'titulo');
      if (!titulo) throw new Invalido('Escribe un título.');
      if (!fotos.length) throw new Invalido('Agrega al menos una foto.');
      guardarPies();
      const ids = await subirArchivos(f, fotos.map((x) => x.archivo));
      const r = await Api.post('reportes/crear', {
        obraId, titulo, fecha: valor(f, 'fecha') || hoyISO(), periodo: valor(f, 'periodo'), descripcion: valor(f, 'descripcion'),
        fotos: ids.map((archivoId, i) => ({ archivoId, pie: fotos[i].pie || '' })),
      });
      fotos.forEach((x) => URL.revokeObjectURL(x.url));
      cerrarModal();
      location.hash = `#/obra/${obraId}/reportes/${r.id}`;
      await refrescar();
      aviso('Reporte publicado.');
    },
  });
  // Conserva lo que ya se escribió en los pies antes de volver a pintar.
  const guardarPies = () => $$('#prev-fotos textarea', form).forEach((t) => { if (fotos[Number(t.dataset.i)]) fotos[Number(t.dataset.i)].pie = t.value.trim(); });
  const pintar = () => {
    guardarPies();
    $('#prev-fotos', form).innerHTML = fotos.map((ft, i) => `<figure style="position:relative">
        <img src="${ft.url}" alt="">
        <button type="button" class="btn icono chico" data-quitar="${i}" style="position:absolute;top:4px;right:4px" aria-label="Quitar foto">${IC.basura}</button>
        <textarea data-i="${i}" placeholder="Pie de foto: qué se ve, eje, nivel…">${esc(ft.pie)}</textarea></figure>`).join('');
  };
  $('#prev-fotos', form).addEventListener('click', (e) => {
    const b = e.target.closest('[data-quitar]');
    if (!b) return;
    guardarPies();
    URL.revokeObjectURL(fotos[Number(b.dataset.quitar)].url);
    fotos.splice(Number(b.dataset.quitar), 1);
    $('#prev-fotos', form).innerHTML = '';
    pintar();
  });
  montarArchivos(form, async (_todos, nuevos) => {
    const imagenes = nuevos.filter((a) => a.type.startsWith('image/'));
    if (imagenes.length < nuevos.length) aviso('Sólo se aceptan imágenes en el reporte fotográfico.');
    const espacio = 40 - fotos.length;
    if (imagenes.length > espacio) aviso('Máximo 40 fotos por reporte.');
    for (const img of imagenes.slice(0, Math.max(0, espacio))) {
      const archivo = await reducirImagen(img);
      fotos.push({ archivo, url: URL.createObjectURL(archivo), pie: '' });
      pintar();
    }
  });
}

function verFoto(reporteId, i) {
  const r = S().reportes.find((x) => x.id === reporteId);
  const f = r?.fotos[i];
  if (!f) return;
  modal({
    titulo: `Foto ${i + 1} de ${r.fotos.length}`,
    ancho: true,
    cuerpo: `<div class="visor">${imgFoto(f)}</div><p style="margin-top:10px;font-size:13px">${esc(f.pie)}</p>`,
    pie: `<button type="button" class="btn" data-accion="verFoto" data-reporte="${esc(r.id)}" data-i="${(i - 1 + r.fotos.length) % r.fotos.length}">‹ Anterior</button>
      <button type="button" class="btn" data-accion="verFoto" data-reporte="${esc(r.id)}" data-i="${(i + 1) % r.fotos.length}">Siguiente ›</button>
      <button type="button" class="btn" data-accion="descargarFoto" data-id="${esc(f.fileId)}">${IC.descargar}Descargar</button>
      <button type="button" class="btn" data-cerrar>Cerrar</button>`,
  });
}

// ── Usuarios ────────────────────────────────────────────────────────
function formUsuario(u) {
  const nuevo = !u;
  u = u || { nombre: '', email: '', puesto: '', rol: 'residente', activo: true, obras: [] };
  const esYo = u.id === usuario.id;
  const obras = S().obras.slice().sort((a, b) => b.clave.localeCompare(a.clave));
  const form = modal({
    titulo: nuevo ? 'Nuevo usuario' : `Editar · ${u.nombre}`,
    textoEnviar: nuevo ? 'Crear usuario' : 'Guardar cambios',
    cuerpo: `
      <div class="fila"><div class="campo"><label>Nombre completo *</label><input type="text" name="nombre" value="${esc(u.nombre)}"></div>
        <div class="campo"><label>Puesto</label><input type="text" name="puesto" value="${esc(u.puesto)}" placeholder="Ej.: Residente de obra"></div></div>
      <div class="fila"><div class="campo"><label>Correo *</label><input type="email" name="email" value="${esc(u.email)}"></div>
        <div class="campo"><label>Contraseña ${nuevo ? '*' : ''}</label><input type="password" name="password" autocomplete="new-password" placeholder="${nuevo ? 'Mínimo 8 caracteres' : 'Déjala vacía para no cambiarla'}"></div></div>
      <div class="fila"><div class="campo"><label>Rol</label><select name="rol" ${esYo ? 'disabled' : ''}>${opciones(Object.entries(ROLES).map(([k, r]) => [k, r.nombre]), u.rol)}</select>
          <div class="ayuda" id="desc-rol"></div></div>
        <div class="campo"><label>Estado</label><label style="display:flex;gap:8px;align-items:center;font-weight:400;color:var(--tinta);margin-top:8px">
          <input type="checkbox" name="activo" ${u.activo ? 'checked' : ''} ${esYo ? 'disabled' : ''}> Puede iniciar sesión</label></div></div>
      <div class="campo" id="campo-obras"><label>Obras asignadas</label>
        <div class="checks">${obras.map((o) => `<label><input type="checkbox" name="obra" value="${esc(o.id)}" ${u.obras.includes(o.id) ? 'checked' : ''}> ${esc(o.clave)} · ${esc(o.nombre)}</label>`).join('') || '<span class="ayuda">Todavía no hay obras.</span>'}</div></div>
      ${esYo ? '<div class="ayuda" style="font-size:12px;color:var(--tinta-3)">No puedes cambiar tu propio rol ni desactivarte.</div>' : ''}`,
    alEnviar: async (f) => {
      const datos = {
        id: u.id, nombre: valor(f, 'nombre'), puesto: valor(f, 'puesto'), email: valor(f, 'email').toLowerCase(),
        rol: esYo ? u.rol : valor(f, 'rol'), activo: esYo ? true : f.elements.activo.checked,
        obras: $$('input[name=obra]:checked', f).map((i) => i.value), password: f.elements.password.value,
      };
      if (!datos.nombre) throw new Invalido('Escribe el nombre.');
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(datos.email)) throw new Invalido('Escribe un correo válido.');
      if ((nuevo || datos.password) && datos.password.length < 8) throw new Invalido('La contraseña debe tener al menos 8 caracteres.');
      await Api.post('usuarios/guardar', datos);
      cerrarModal();
      await refrescar();
      aviso(nuevo ? 'Usuario creado.' : 'Usuario actualizado.');
    },
  });
  const sel = form.elements.rol;
  const alternar = () => {
    const r = sel.value;
    $('#desc-rol', form).textContent = ROLES[r]?.desc || '';
    $('#campo-obras', form).classList.toggle('oculto', rolVeTodas(r));
  };
  sel.addEventListener('change', alternar);
  alternar();
}

function formMiCuenta() {
  modal({
    titulo: 'Mi cuenta',
    textoEnviar: 'Cambiar contraseña',
    cuerpo: `<dl class="ficha" style="margin-bottom:16px">
        <div><dt>Nombre</dt><dd>${esc(usuario.nombre)}</dd></div><div><dt>Correo</dt><dd>${esc(usuario.email)}</dd></div>
        <div><dt>Rol</dt><dd>${chipRol(usuario.rol)}</dd></div><div><dt>Puesto</dt><dd>${esc(usuario.puesto || '—')}</dd></div></dl>
      <div class="campo"><label>Contraseña actual</label><input type="password" name="actual" autocomplete="current-password"></div>
      <div class="fila"><div class="campo"><label>Nueva contraseña</label><input type="password" name="nueva" autocomplete="new-password" placeholder="Mínimo 8 caracteres"></div>
        <div class="campo"><label>Repite la nueva</label><input type="password" name="nueva2" autocomplete="new-password"></div></div>`,
    alEnviar: async (f) => {
      const nueva = f.elements.nueva.value;
      if (nueva.length < 8) throw new Invalido('La nueva contraseña debe tener al menos 8 caracteres.');
      if (nueva !== f.elements.nueva2.value) throw new Invalido('Las contraseñas nuevas no coinciden.');
      await Api.post('auth/clave', { actual: f.elements.actual.value, nueva });
      cerrarModal();
      aviso('Contraseña actualizada.');
    },
  });
}

// Para confirmaciones (eliminar…): ejecuta, refresca y avisa. Si falla,
// el error se muestra en la misma ventana de confirmación.
async function ejecutar(fn, mensaje) {
  await fn();
  cerrarModal();
  await refrescar();
  if (mensaje) aviso(mensaje);
}

// ── Mapa de acciones: data-accion="nombre" en el HTML → función ────
const ACCIONES = {
  ...ACCIONES_ARCHIVO, // definidas en archivo.js
  menu: (_ds, boton) => boton.setAttribute('aria-expanded', $('#cabecera').classList.toggle('abierta')),
  salir: async () => {
    try { await Api.post('auth/salir'); } catch (e) { /* aunque falle, se sale */ }
    usuario = null;
    Store.s = null;
    location.hash = '';
    await Store.cargarSesion().catch(() => {});
    render();
  },
  miCuenta: () => formMiCuenta(),
  imprimir: () => window.print(),
  nuevaObra: () => formObra(),
  editarObra: (ds) => formObra(obraPor(ds.id)),
  asignarEquipo: (ds) => asignarEquipo(ds.obra),
  subirDoc: (ds) => formSubirDoc(ds.obra, ds.carpeta),
  verDoc: (ds) => verDoc(ds.id),
  editarDoc: (ds) => formEditarDoc(ds.id),
  nuevaVersion: (ds) => formNuevaVersion(ds.id),
  compartirDoc: (ds) => formCompartir(ds.id),
  descargarDoc: (ds) => abrirArchivo(ultimaVersion(docPor(ds.id)).fileId, 'descargar'),
  abrirVersion: (ds) => {
    const v = docPor(ds.doc).versiones.find((x) => x.v === Number(ds.v));
    abrirArchivo(v.fileId, ds.modo);
  },
  eliminarDoc: (ds) => {
    const d = docPor(ds.id);
    confirmar('Eliminar documento', `¿Eliminar <b>${esc(d.nombre)}</b> y sus ${d.versiones.length} versión(es)? Esta acción no se puede deshacer.`,
      () => ejecutar(() => Api.post('documentos/eliminar', { id: d.id }), 'Documento eliminado.'));
  },
  nuevoPresupuesto: (ds) => formPresupuesto(ds.obra),
  verPresupuesto: (ds) => verPresupuesto(ds.id),
  editarPresupuesto: (ds) => { const p = S().presupuestos.find((x) => x.id === ds.id); formPresupuesto(p.obraId, p); },
  archivoPresupuesto: (ds) => abrirArchivo(S().presupuestos.find((x) => x.id === ds.id).fileId, 'descargar'),
  eliminarPresupuesto: (ds) => {
    const p = S().presupuestos.find((x) => x.id === ds.id);
    confirmar('Eliminar presupuesto', `¿Eliminar <b>${esc(p.folio)} · ${esc(p.concepto)}</b>?`,
      () => ejecutar(() => Api.post('presupuestos/eliminar', { id: p.id }), 'Presupuesto eliminado.'));
  },
  nuevoReporte: (ds) => formReporte(ds.obra),
  verFoto: (ds) => verFoto(ds.reporte, Number(ds.i)),
  descargarFoto: (ds) => abrirArchivo(ds.id, 'descargar'),
  eliminarReporte: (ds) => {
    const r = S().reportes.find((x) => x.id === ds.id);
    confirmar('Eliminar reporte', `¿Eliminar <b>${esc(r.titulo)}</b> con sus ${r.fotos.length} fotos?`, async () => {
      await Api.post('reportes/eliminar', { id: r.id });
      cerrarModal();
      location.hash = `#/obra/${r.obraId}/reportes`;
      await refrescar();
      aviso('Reporte eliminado.');
    });
  },
  nuevoUsuario: () => formUsuario(),
  editarUsuario: (ds) => formUsuario(usuarioPor(ds.id)),
  repetirDiagnostico: () => render(),
  // Sube un archivo pequeño por el mismo camino que los documentos. Si se queda
  // sin usar, la tarea diaria lo borra.
  probarSubida: async () => {
    const caja = $('#diag-subida');
    caja.textContent = 'Subiendo un archivo de prueba…';
    try {
      await Api.subir(new File([`Prueba de subida ${new Date().toISOString()}`], 'prueba-del-navegador.txt', { type: 'text/plain' }));
      caja.innerHTML = `${chip('Funciona', 'ok')} El navegador pudo subir un archivo al almacenamiento.`;
    } catch (e) {
      caja.innerHTML = `${chip('Con error', 'alerta')} ${esc(e.message)}`;
    }
  },
  configurarCors: async () => {
    const caja = $('#diag-subida');
    caja.textContent = 'Configurando…';
    try {
      const r = await Api.post('sistema/cors');
      caja.innerHTML = Object.entries({ trabajo: 'Archivos de trabajo', archivo: 'Archivo muerto' })
        .map(([k, t]) => `<div style="margin-bottom:6px">${chip(r.almacen[k].ok ? 'Listo' : 'Con error', r.almacen[k].ok ? 'ok' : 'alerta')} <b>${t}:</b> ${esc(r.almacen[k].detalle)}</div>`).join('')
        + '<div class="ayuda">Ahora pulsa «Probar subida».</div>';
    } catch (e) {
      caja.innerHTML = `${chip('Con error', 'alerta')} ${esc(e.message)}`;
    }
  },
};

document.addEventListener('click', (e) => {
  const boton = e.target.closest('[data-accion]');
  if (boton && ACCIONES[boton.dataset.accion]) {
    e.preventDefault();
    e.stopPropagation();
    Promise.resolve(ACCIONES[boton.dataset.accion](boton.dataset, boton)).catch((ex) => console.error(ex));
    return;
  }
  const destino = e.target.closest('[data-ir]');
  if (destino && !e.target.closest('a')) location.hash = destino.dataset.ir;
});

// ════════════════════════════════════════════════════════════════════
// 6. Arranque y navegación
// ════════════════════════════════════════════════════════════════════
async function entrar(u) {
  usuario = u;
  await Store.recargar();
  if (location.hash && location.hash !== '#/' && location.hash !== '#') render();
  else location.hash = '#/inicio';
}

function render() {
  const app = $('#app');
  cerrarModal();
  const nombre = CONFIG().nombre || 'CH Arquitectura y Construcción';
  if (!usuario || !S()) {
    document.title = `${nombre} — Acceso`;
    app.innerHTML = vistaLogin();
    montarLogin();
    return;
  }
  const [ruta, consulta] = location.hash.replace(/^#\/?/, '').split('?');
  const partes = ruta.split('/').filter(Boolean).map(decodeURIComponent);
  const params = new URLSearchParams(consulta || '');
  let seccion = partes[0] || 'inicio';
  let v;
  switch (seccion) {
    case 'obras': v = vistaObras(); break;
    case 'obra': v = vistaObra(partes[1], partes[2], partes[3]); seccion = 'obras'; break;
    case 'compartidos': v = vistaCompartidos(); break;
    case 'buscar': v = vistaBuscar(params.get('q') || ''); break;
    case 'archivo': v = puede('archivo') ? vistaArchivo(partes, params) : vistaNoEncontrada('No tienes acceso al archivo muerto.'); break;
    case 'usuarios': v = puede('usuarios') ? vistaUsuarios() : vistaNoEncontrada('Sólo el administrador gestiona usuarios.'); break;
    case 'diagnostico': v = puede('usuarios') ? vistaDiagnostico() : vistaNoEncontrada('Sólo el administrador ve el diagnóstico.'); seccion = 'usuarios'; break;
    case 'actividad': v = puede('actividad') ? vistaActividad() : vistaNoEncontrada('No tienes acceso a esta sección.'); break;
    default: seccion = 'inicio'; v = vistaInicio();
  }
  document.title = `${v.titulo} — ${nombre}`;
  app.innerHTML = shell(seccion, v);
  if (v.montar) Promise.resolve(v.montar()).catch((e) => console.error(e));
}

window.addEventListener('hashchange', () => { render(); window.scrollTo(0, 0); });

// Si la sesión vence (o se cierra en otra pestaña), vuelve al inicio de sesión.
Api.alTerminarSesion = (mensaje) => {
  if (!usuario) return;
  usuario = null;
  Store.s = null;
  mensajeLogin = mensaje;
  Store.cargarSesion().catch(() => {}).finally(render);
};

function pantallaMensaje(titulo, html) {
  $('#app').innerHTML = `<div class="login"><div class="login-col"><div class="login-caja">
    <img class="login-logo" src="img/logo.png" alt="CH Arquitectura y Construcción, S.A. de C.V.">
    <h1 class="login-t">${esc(titulo)}</h1><p class="login-ayuda" style="font-size:14px">${html}</p></div></div></div>`;
}

(async function iniciar() {
  try {
    const j = await Store.cargarSesion();
    if (j.sesion && j.usuario) { usuario = j.usuario; await Store.recargar(); }
  } catch (e) {
    if (e.datos && e.datos.instalado === false) {
      pantallaMensaje('Falta instalar el sistema', 'Abre <a href="instalar.php">instalar.php</a> para crear la base de datos y la cuenta del administrador.');
      return;
    }
    if (!usuario) {
      pantallaMensaje('No se pudo conectar', `${esc(e.message)}<br><br><a href="./">Intentar de nuevo</a>`);
      return;
    }
  }
  render();
})();
