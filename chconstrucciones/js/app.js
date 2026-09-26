/* CH Arquitectura y Construcción — Aplicación del DEMO de gestión documental.
 *
 * Cómo está organizado este archivo:
 *   1. Utilidades (formato de fechas, dinero, escapar texto).
 *   2. Permisos: qué puede ver y hacer cada rol.
 *   3. Piezas de interfaz reutilizables (modal, avisos, subir archivos).
 *   4. Vistas: una función por pantalla, cada una devuelve su HTML.
 *   5. Acciones: lo que pasa al pulsar un botón (data-accion="...").
 *   6. Arranque y navegación (la dirección #/obras, #/obra/o1...).
 */
'use strict';

// ════════════════════════════════════════════════════════════════════
// 1. Utilidades
// ════════════════════════════════════════════════════════════════════
const $ = (sel, raiz = document) => raiz.querySelector(sel);
const $$ = (sel, raiz = document) => [...raiz.querySelectorAll(sel)];
const S = () => Store.s;

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
    return (b / 1048576).toFixed(1) + ' MB';
  },
  relativo(iso) {
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
function extension(nombre) {
  const m = /\.([a-z0-9]{1,5})$/i.exec(nombre || '');
  return m ? m[1].toLowerCase() : 'arch';
}
function iniciales(nombre) {
  return String(nombre || '?').split(/\s+/).map((p) => p[0]).slice(0, 2).join('').toUpperCase();
}

const obraPor = (id) => S().obras.find((o) => o.id === id);
const usuarioPor = (id) => S().usuarios.find((u) => u.id === id);
const nombreUsuario = (id) => usuarioPor(id)?.nombre || '—';
const carpetaPor = (id) => CARPETAS.find((c) => c.id === id);
const docsDeObra = (obraId) => S().documentos.filter((d) => d.obraId === obraId);
const ultimaVersion = (doc) => doc.versiones[doc.versiones.length - 1];

// Íconos (trazos simples en SVG, sin librerías externas).
const trazo = (d) => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${d}</svg>`;
const IC = {
  inicio: trazo('<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/>'),
  obras: trazo('<path d="M3 21h18"/><path d="M5 21V8l7-5 7 5v13"/><path d="M9 21v-6h6v6"/>'),
  buscar: trazo('<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>'),
  usuarios: trazo('<circle cx="9" cy="8" r="4"/><path d="M1 21v-1a7 7 0 0 1 14 0v1"/><path d="M16 3.1a4 4 0 0 1 0 7.8"/><path d="M23 21v-1a7 7 0 0 0-5-6.7"/>'),
  actividad: trazo('<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>'),
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
// 2. Permisos
// ════════════════════════════════════════════════════════════════════
let usuario = null; // quien tiene la sesión abierta

const esGestor = (u = usuario) => u.rol === 'admin' || u.rol === 'coordinador';
const esAdmin = () => usuario.rol === 'admin';
const puedeVer = (obraId) => esGestor() || usuario.obras.includes(obraId);
const puedeSubir = (obraId) => usuario.rol !== 'consulta' && puedeVer(obraId);
const obrasVisibles = () => S().obras.filter((o) => puedeVer(o.id));
const docsVisibles = () => S().documentos.filter((d) => puedeVer(d.obraId));

// Bitácora interna del sistema: quién hizo qué y cuándo.
function registrar(accion, obraId, detalle = '') {
  S().actividad.unshift({ id: nuevoId('a'), fecha: new Date().toISOString(), usuarioId: usuario.id, accion, obraId, detalle });
  if (S().actividad.length > 500) S().actividad.length = 500;
}
function guardarYRefrescar() {
  Store.guardar();
  render();
}

// ════════════════════════════════════════════════════════════════════
// 3. Piezas de interfaz
// ════════════════════════════════════════════════════════════════════
function aviso(mensaje) {
  $$('.toast').forEach((t) => t.remove());
  const t = document.createElement('div');
  t.className = 'toast';
  t.textContent = mensaje;
  document.body.appendChild(t);
  setTimeout(() => t.remove(), 3500);
}

// Error "esperado" (validación): se muestra dentro del formulario.
class Invalido extends Error {}

function cerrarModal() {
  $('#modal')?.remove();
  document.removeEventListener('keydown', escCierraModal);
}
function escCierraModal(e) {
  if (e.key === 'Escape') cerrarModal();
}
/* Abre una ventana emergente. Si recibe alEnviar, el contenido es un
   formulario: alEnviar(form) guarda los datos; si lanza Invalido, se
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
      <div class="modal-b">${cuerpo}<div class="error oculto" role="alert"></div></div>
      <div class="modal-f">${botones}</div>
    </form>`;
  document.body.appendChild(fondo);
  const form = $('form', fondo);
  fondo.addEventListener('mousedown', (e) => { if (e.target === fondo) cerrarModal(); });
  fondo.addEventListener('click', (e) => { if (e.target.closest('[data-cerrar]')) cerrarModal(); });
  document.addEventListener('keydown', escCierraModal);
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!alEnviar) return;
    const error = $('.error', form);
    const boton = $('[type=submit]', form);
    error.classList.add('oculto');
    if (boton) boton.disabled = true;
    try {
      await alEnviar(form);
    } catch (ex) {
      if (!(ex instanceof Invalido)) console.error(ex);
      error.textContent = ex instanceof Invalido ? ex.message : 'Ocurrió un error: ' + ex.message;
      error.classList.remove('oculto');
    } finally {
      if (boton) boton.disabled = false;
    }
  });
  hidratarImagenes(fondo);
  $('input:not([type=file]):not([type=checkbox]), select, textarea', form)?.focus();
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

// Zona para arrastrar o elegir archivos. Devuelve una función que da la
// lista de archivos seleccionados.
function campoArchivos({ multiple = false, accept = '', texto = 'Arrastra aquí o haz clic para elegir' } = {}) {
  return `<label class="soltar" data-soltar>
      <input type="file" ${multiple ? 'multiple' : ''} ${accept ? `accept="${accept}"` : ''}>
      ${IC.subir.replace('<svg', '<svg style="width:22px;height:22px;display:block;margin:0 auto 6px"')}
      <b>${esc(texto)}</b><div class="ayuda" style="margin-top:4px">Máximo 25 MB por archivo en el demo.</div>
    </label><div class="lista-archivos" data-lista></div>`;
}
const LIMITE_ARCHIVO = 25 * 1024 * 1024;
function montarArchivos(raiz, alCambiar) {
  const zona = $('[data-soltar]', raiz);
  const input = $('input[type=file]', zona);
  let archivos = [];
  const actualizar = (lista) => {
    const nuevos = [...lista];
    const grandes = nuevos.filter((f) => f.size > LIMITE_ARCHIVO);
    if (grandes.length) aviso(`Se omitieron ${grandes.length} archivo(s) de más de 25 MB.`);
    const validos = nuevos.filter((f) => f.size <= LIMITE_ARCHIVO);
    archivos = input.multiple ? archivos.concat(validos) : validos.slice(0, 1);
    const lista$ = $('[data-lista]', raiz);
    if (alCambiar) alCambiar(archivos, validos);
    else lista$.innerHTML = archivos.map((f) => `• ${esc(f.name)} <small>(${fmt.tamano(f.size)})</small>`).join('<br>');
  };
  input.addEventListener('change', () => { actualizar(input.files); input.value = ''; });
  zona.addEventListener('dragover', (e) => { e.preventDefault(); zona.classList.add('encima'); });
  zona.addEventListener('dragleave', () => zona.classList.remove('encima'));
  zona.addEventListener('drop', (e) => { e.preventDefault(); zona.classList.remove('encima'); actualizar(e.dataTransfer.files); });
  return {
    obtener: () => archivos,
    quitar: (i) => { archivos.splice(i, 1); },
  };
}

// Las fotos de los reportes se reducen antes de guardarse: una foto de
// celular pesa 4–8 MB y a 1600 px queda en ~300 KB sin perder detalle útil.
async function reducirImagen(archivo, maximo = 1600, calidad = 0.82) {
  if (!archivo.type.startsWith('image/')) return archivo;
  const url = URL.createObjectURL(archivo);
  try {
    const img = await new Promise((ok, mal) => { const i = new Image(); i.onload = () => ok(i); i.onerror = mal; i.src = url; });
    const k = Math.min(1, maximo / Math.max(img.width, img.height));
    const lienzo = document.createElement('canvas');
    lienzo.width = Math.round(img.width * k);
    lienzo.height = Math.round(img.height * k);
    lienzo.getContext('2d').drawImage(img, 0, 0, lienzo.width, lienzo.height);
    return await new Promise((ok) => lienzo.toBlob((b) => ok(b || archivo), 'image/jpeg', calidad));
  } catch (e) {
    return archivo;
  } finally {
    URL.revokeObjectURL(url);
  }
}

// Las imágenes guardadas en IndexedDB se cargan después de pintar la
// pantalla: el HTML lleva data-archivo="id" y aquí se le pone la imagen.
const urlsArchivos = new Map();
async function urlArchivo(fileId) {
  if (urlsArchivos.has(fileId)) return urlsArchivos.get(fileId);
  const blob = await Archivos.obtener(fileId);
  if (!blob) return null;
  const url = URL.createObjectURL(blob);
  urlsArchivos.set(fileId, url);
  return url;
}
function hidratarImagenes(raiz) {
  $$('img[data-archivo]', raiz).forEach(async (img) => {
    const url = await urlArchivo(img.dataset.archivo);
    if (url) img.src = url;
  });
}

// Ilustración para las fotos de ejemplo (no hay fotos reales en el demo).
const TONOS = [['#8d6e4f', '#4f3d2c'], ['#7d8792', '#46505b'], ['#5e7d5a', '#34482f'], ['#9a7b4a', '#5d4726'], ['#6b7f99', '#3a4a60'], ['#9b6a3d', '#5a3a1e']];
function fotoEjemplo(texto, tono = 1) {
  const [a, b] = TONOS[(tono - 1) % TONOS.length];
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 300"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="${a}"/><stop offset="1" stop-color="${b}"/></linearGradient></defs><rect width="400" height="300" fill="url(#g)"/><path d="M0 235 L80 180 L150 212 L245 135 L400 225 L400 300 L0 300Z" fill="rgba(255,255,255,.13)"/><rect x="250" y="60" width="8" height="120" fill="rgba(255,255,255,.22)"/><rect x="200" y="60" width="110" height="7" fill="rgba(255,255,255,.22)"/><text x="200" y="148" font-family="Segoe UI,Arial,sans-serif" font-size="21" font-weight="600" fill="#fff" text-anchor="middle">${esc(texto)}</text><text x="200" y="174" font-family="Segoe UI,Arial,sans-serif" font-size="12" fill="rgba(255,255,255,.75)" text-anchor="middle">Foto de ejemplo</text></svg>`;
  return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
}
function imgFoto(foto, extra = '') {
  return foto.fileId
    ? `<img data-archivo="${esc(foto.fileId)}" alt="${esc(foto.pie)}" ${extra}>`
    : `<img src="${fotoEjemplo(foto.ejemplo, foto.tono)}" alt="${esc(foto.pie)}" ${extra}>`;
}

async function abrirArchivo(fileId, nombre, modo = 'ver') {
  if (!fileId) {
    aviso('Es un documento de ejemplo sin archivo real. Sube una nueva versión para probar la carga y descarga.');
    return;
  }
  const url = await urlArchivo(fileId);
  if (!url) { aviso('No se encontró el archivo en este navegador.'); return; }
  const blob = await Archivos.obtener(fileId);
  const visible = blob && (blob.type.startsWith('image/') || blob.type === 'application/pdf');
  if (modo === 'ver' && visible) {
    window.open(url, '_blank', 'noopener');
    return;
  }
  const a = document.createElement('a');
  a.href = url;
  a.download = nombre || 'archivo';
  document.body.appendChild(a);
  a.click();
  a.remove();
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

// ════════════════════════════════════════════════════════════════════
// 4. Vistas
// ════════════════════════════════════════════════════════════════════
function banner() {
  return `<div class="aviso-demo no-imprimir"><span><b>DEMO</b> Datos de ejemplo. Lo que agregues se guarda sólo en este navegador.</span>
    <a href="propuesta.html">Ver la propuesta</a>
    <button type="button" data-accion="restablecer">Restablecer datos</button></div>`;
}

function vistaLogin() {
  const accesos = S().usuarios.filter((u) => u.activo && u.password === 'demo123').map((u) => `
    <button type="button" class="acceso" data-accion="accesoRapido" data-id="${esc(u.id)}">
      ${avatar(u, true)}<span><b>${esc(u.nombre)}</b><small>${esc(u.puesto)}</small></span>${chipRol(u.rol)}
    </button>`).join('');
  return `<div class="login">
    <div class="login-col">
      <form class="login-caja" id="form-login" novalidate>
        <img class="login-logo" src="img/logo.png" alt="CH Arquitectura y Construcción, S.A. de C.V.">
        <h1 class="login-t">Gestión documental de obras</h1>
        <div class="campo"><label for="l-email">Correo</label><input type="email" id="l-email" autocomplete="username" required></div>
        <div class="campo"><label for="l-pass">Contraseña</label><input type="password" id="l-pass" autocomplete="current-password" required></div>
        <div class="error oculto" role="alert"></div>
        <button type="submit" class="btn primario ancho">Entrar</button>
      </form>
      <div class="accesos"><p>Acceso rápido para probar cada rol · contraseña <b>demo123</b></p>${accesos}</div>
    </div>
  </div>`;
}
function montarLogin() {
  const form = $('#form-login');
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const email = normalizar($('#l-email').value.trim());
    const pass = $('#l-pass').value;
    const u = S().usuarios.find((x) => normalizar(x.email) === email && x.password === pass);
    const error = $('.error', form);
    if (!u) { error.textContent = 'Correo o contraseña incorrectos.'; error.classList.remove('oculto'); return; }
    if (!u.activo) { error.textContent = 'Este usuario está desactivado. Consulta al administrador.'; error.classList.remove('oculto'); return; }
    entrar(u);
  });
  $('#l-email').focus();
}

// Estructura de todas las pantallas: cabecera con navegación horizontal,
// franja negra con el título de la página y el contenido debajo.
function shell(seccion, v) {
  const enlace = (href, clave, texto, extra = '') =>
    `<a class="nav-item ${seccion === clave ? 'activo' : ''}" href="${href}">${texto}${extra}</a>`;
  const porVencer = docsVisibles().filter((d) => { const x = vencimiento(d); return x && x.dias <= 30; }).length;
  return `<header class="cabecera no-imprimir" id="cabecera">
      <div class="cab-fila">
        <a class="cab-marca" href="#/inicio"><img src="img/marca.png" alt=""><span><b>CH</b><small>Arquitectura y Construcción</small></span></a>
        <nav class="cab-nav" aria-label="Principal">
          ${enlace('#/inicio', 'inicio', 'Inicio', porVencer ? `<span class="cuenta" title="Documentos vencidos o por vencer">${porVencer}</span>` : '')}
          ${enlace('#/obras', 'obras', 'Obras')}
          ${enlace('#/buscar', 'buscar', 'Buscar')}
          ${esGestor() ? enlace('#/actividad', 'actividad', 'Actividad') : ''}
          ${esAdmin() ? enlace('#/usuarios', 'usuarios', 'Usuarios') : ''}
        </nav>
        <div class="cab-usuario">${avatar(usuario)}<span class="cab-usuario-t"><b>${esc(usuario.nombre)}</b><small>${esc(ROLES[usuario.rol].nombre)}</small></span>
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
  const o = obraPor(a.obraId);
  return `<li>${avatar(u, true)}<div><div><b>${esc(u?.nombre || 'Usuario eliminado')}</b> ${esc(a.accion)}
    ${a.detalle ? `<b>${esc(a.detalle)}</b>` : ''}
    ${conObra && o ? ` · <a href="#/obra/${esc(o.id)}">${esc(o.clave)}</a>` : ''}</div>
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
  const actividad = S().actividad.filter((a) => puedeVer(a.obraId)).slice(0, 8);

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
    : vacio('No tienes obras activas asignadas.');

  const listaVence = conVence.length ? `<ul class="lista-simple">${conVence.slice(0, 8).map(({ d, v }) => `
    <li style="cursor:pointer" data-accion="verDoc" data-id="${esc(d.id)}">${iconoExt(d.ext)}<div style="min-width:0"><b style="font-weight:600">${esc(d.nombre)}</b>
      <div style="font-size:11.5px;color:var(--tinta-3)">${esc(obraPor(d.obraId)?.clave)} · ${esc(carpetaPor(d.carpeta)?.nombre)}</div></div>
      <div class="der">${chip(v.texto, v.clase)}</div></li>`).join('')}</ul>`
    : vacio('Sin vencimientos en los próximos 30 días.');

  const listaRevision = enRevision.length ? `<ul class="lista-simple">${enRevision.slice(0, 6).map((d) => `
    <li style="cursor:pointer" data-accion="verDoc" data-id="${esc(d.id)}">${iconoExt(d.ext)}<div style="min-width:0"><b style="font-weight:600">${esc(d.nombre)}</b>
      <div style="font-size:11.5px;color:var(--tinta-3)">${esc(obraPor(d.obraId)?.clave)} · subió ${esc(nombreUsuario(ultimaVersion(d).usuarioId))}</div></div></li>`).join('')}</ul>`
    : vacio('No hay documentos esperando revisión.');

  return {
    titulo: 'Inicio',
    sub: `Hola, ${esc(usuario.nombre.split(' ')[0])}. Hoy es ${esc(fmt.fechaLarga())}.`,
    acciones: esGestor() ? `<button class="btn primario" data-accion="nuevaObra">${IC.mas}Nueva obra</button>` : '',
    html: `${kpis}
      <div class="grid g-2-1">
        <div>
          <div class="card"><div class="card-h"><span class="card-t">${esGestor() ? 'Obras activas' : 'Mis obras'}</span><a class="derecha" href="#/obras" style="font-size:12.5px">Ver todas</a></div>${listaObras}</div>
          <div class="card"><div class="card-h"><span class="card-t">Actividad reciente</span>${esGestor() ? '<a class="derecha" href="#/actividad" style="font-size:12.5px">Ver todo</a>' : ''}</div>
            ${actividad.length ? `<ul class="actividad">${actividad.map((a) => filaActividad(a)).join('')}</ul>` : vacio('Sin actividad todavía.')}</div>
        </div>
        <div>
          <div class="card"><div class="card-h"><span class="card-t">Vencimientos</span><span class="card-s">próximos 30 días</span></div>${listaVence}</div>
          ${esGestor() ? `<div class="card"><div class="card-h"><span class="card-t">Pendientes de revisión</span></div>${listaRevision}</div>` : ''}
        </div>
      </div>`,
  };
}

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
    <div class="obra-pie"><span>${docs} documentos</span><span>${reps} reportes</span><span style="margin-left:auto">${esc(nombreUsuario(o.residenteId))}</span></div>
  </div>`;
}
function vistaObras() {
  const obras = obrasVisibles();
  return {
    titulo: 'Obras',
    sub: esGestor() ? 'Todas las obras de la empresa' : 'Obras en las que participas',
    acciones: esGestor() ? `<button class="btn primario" data-accion="nuevaObra">${IC.mas}Nueva obra</button>` : '',
    html: `<div class="filtros">
        <div class="buscador">${IC.buscar}<input type="search" id="f-obras" placeholder="Buscar por nombre, clave, cliente…" aria-label="Buscar obras"></div>
        <select class="sel" id="f-estado" aria-label="Filtrar por estado"><option value="">Todos los estados</option>${opciones(ESTADOS_OBRA)}</select>
      </div>
      ${obras.length ? `<div class="grid g-3" id="lista-obras">${obras.map(tarjetaObra).join('')}</div><div id="obras-vacio" class="oculto">${vacio('Ninguna obra coincide con el filtro.')}</div>`
        : `<div class="card">${vacio(esGestor() ? 'Todavía no hay obras. Crea la primera con “Nueva obra”.' : 'No tienes obras asignadas. Pide al administrador que te asigne.')}</div>`}`,
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
function vistaObra(id, pestana = 'resumen', sub, params) {
  const o = obraPor(id);
  if (!o || !puedeVer(id)) return vistaNoEncontrada('No encontramos esta obra o no tienes acceso a ella.');
  const docs = docsDeObra(id);
  const presupuestos = S().presupuestos.filter((p) => p.obraId === id);
  const reportes = S().reportes.filter((r) => r.obraId === id);
  const equipo = S().usuarios.filter((u) => !esGestor(u) && u.obras.includes(id));
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
  const subir = puedeSubir(id);
  switch (pestana) {
    case 'documentos': {
      const r = pestanaDocumentos(o, docs, sub, params);
      cuerpo = r.html; montar = r.montar;
      if (subir) acciones = `<button class="btn primario" data-accion="subirDoc" data-obra="${esc(id)}" data-carpeta="${esc(sub || '')}">${IC.subir}Subir documento</button>`;
      break;
    }
    case 'presupuestos':
      cuerpo = pestanaPresupuestos(o, presupuestos);
      if (subir) acciones = `<button class="btn primario" data-accion="nuevoPresupuesto" data-obra="${esc(id)}">${IC.mas}Nuevo presupuesto</button>`;
      break;
    case 'reportes':
      if (sub) return vistaReporte(o, sub);
      cuerpo = pestanaReportes(o, reportes);
      if (subir) acciones = `<button class="btn primario" data-accion="nuevoReporte" data-obra="${esc(id)}">${IC.camara}Nuevo reporte</button>`;
      break;
    case 'equipo':
      cuerpo = pestanaEquipo(o, equipo);
      if (esAdmin()) acciones = `<button class="btn primario" data-accion="asignarEquipo" data-obra="${esc(id)}">${IC.equipo}Asignar personal</button>`;
      break;
    case 'actividad': {
      const lista = S().actividad.filter((a) => a.obraId === id);
      cuerpo = `<div class="card">${lista.length ? `<ul class="actividad">${lista.map((a) => filaActividad(a, false)).join('')}</ul>` : vacio('Sin actividad registrada.')}</div>`;
      break;
    }
    default:
      cuerpo = pestanaResumen(o, docs);
      if (esGestor()) acciones = `<button class="btn" data-accion="editarObra" data-id="${esc(id)}">${IC.lapiz}Editar obra</button>`;
      if (subir) acciones += `<button class="btn primario" data-accion="subirDoc" data-obra="${esc(id)}">${IC.subir}Subir documento</button>`;
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
          <div><dt>Tipo</dt><dd>${esc(o.tipo)}</dd></div>
          <div><dt>Cliente</dt><dd>${esc(o.cliente)}</dd></div>
          <div><dt>Residente</dt><dd>${esc(nombreUsuario(o.residenteId))}</dd></div>
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

function filaDocumento(d, mostrarCarpeta) {
  const ult = ultimaVersion(d);
  const v = vencimiento(d);
  const texto = normalizar([d.nombre, d.etiquetas.join(' '), d.descripcion, ult.nombreArchivo].join(' '));
  return `<tr class="clic" data-accion="verDoc" data-id="${esc(d.id)}" data-texto="${esc(texto)}" data-estado="${esc(d.estado)}">
    <td><div class="doc-nombre">${iconoExt(d.ext)}<div><b>${esc(d.nombre)}</b>
      <small>${mostrarCarpeta ? esc(carpetaPor(d.carpeta)?.nombre) + ' · ' : ''}${d.etiquetas.map((t) => `<span class="tag">${esc(t)}</span>`).join('')}</small></div></div></td>
    <td>${chip(d.estado, CLASE_DOC[d.estado])}</td>
    <td>v${ult.v}</td>
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
  if (!lista.length) return `<div class="card">${vacio(puedeSubir(o.id) ? 'Sin reportes todavía. Crea uno con “Nuevo reporte” y sube las fotos desde el celular o la computadora.' : 'Sin reportes fotográficos todavía.')}</div>`;
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
    ${esGestor() || r.usuarioId === usuario.id ? `<button class="btn peligro" data-accion="eliminarReporte" data-id="${esc(r.id)}">${IC.basura}Eliminar</button>` : ''}`;
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
  const gestores = S().usuarios.filter((u) => esGestor(u) && u.activo);
  const fila = (u, nota) => `<li>${avatar(u)}<div><b>${esc(u.nombre)}</b><div style="font-size:12px;color:var(--tinta-3)">${esc(u.puesto)} · ${esc(u.email)}</div></div>
    <div class="der">${chipRol(u.rol)}${nota ? `<div style="font-size:11px;color:var(--tinta-3);margin-top:3px">${nota}</div>` : ''}</div></li>`;
  return `<div class="grid g-2">
    <div class="card"><div class="card-h"><span class="card-t">Personal asignado</span><span class="card-s">ve y trabaja esta obra</span></div>
      ${equipo.length ? `<ul class="lista-simple">${equipo.map((u) => fila(u, u.id === o.residenteId ? 'Residente responsable' : (u.activo ? '' : 'Desactivado'))).join('')}</ul>` : vacio('Nadie asignado todavía.')}</div>
    <div class="card"><div class="card-h"><span class="card-t">Con acceso a todas las obras</span></div>
      <ul class="lista-simple">${gestores.map((u) => fila(u)).join('')}</ul></div></div>`;
}

// ── Búsqueda ────────────────────────────────────────────────────────
function vistaBuscar(q) {
  const t = normalizar(q.trim());
  let resultados = '';
  if (t) {
    const contiene = (...campos) => normalizar(campos.join(' ')).includes(t);
    const obras = obrasVisibles().filter((o) => contiene(o.clave, o.nombre, o.cliente, o.ubicacion, o.descripcion));
    const docs = docsVisibles().filter((d) => contiene(d.nombre, d.etiquetas.join(' '), d.descripcion, carpetaPor(d.carpeta)?.nombre, ...d.versiones.map((v) => v.nombreArchivo)));
    const pres = S().presupuestos.filter((p) => puedeVer(p.obraId) && contiene(p.folio, p.concepto, p.notas));
    const reps = S().reportes.filter((r) => puedeVer(r.obraId) && contiene(r.titulo, r.descripcion, r.periodo, ...r.fotos.map((f) => f.pie)));
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
function vistaUsuarios() {
  const filas = S().usuarios.map((u) => `<tr class="clic" data-accion="editarUsuario" data-id="${esc(u.id)}">
    <td><div class="doc-nombre" style="min-width:200px">${avatar(u)}<div><b>${esc(u.nombre)}</b><small>${esc(u.email)}</small></div></div></td>
    <td>${esc(u.puesto)}</td><td>${chipRol(u.rol)}</td>
    <td>${esGestor(u) ? '<span style="color:var(--tinta-3)">Todas</span>' : (u.obras.map((id) => esc(obraPor(id)?.clave || '')).filter(Boolean).join(', ') || '<span style="color:var(--tinta-3)">Ninguna</span>')}</td>
    <td>${u.activo ? chip('Activo', 'ok') : chip('Inactivo', 'alerta')}</td>
    <td class="acc"><button type="button" class="btn chico" data-accion="editarUsuario" data-id="${esc(u.id)}">${IC.lapiz}Editar</button></td></tr>`).join('');
  const si = '<span style="color:var(--ok);font-weight:700">✓</span>';
  const no = '<span style="color:var(--tinta-3)">—</span>';
  const matriz = [
    ['Ver obras', 'Todas', 'Todas', 'Asignadas', 'Asignadas'],
    ['Crear y editar obras', si, si, no, no],
    ['Subir documentos, presupuestos y reportes', si, si, si, no],
    ['Aprobar / marcar obsoleto', si, si, no, no],
    ['Eliminar documentos', si, si, no, no],
    ['Ver bitácora de actividad global', si, si, no, no],
    ['Gestionar usuarios y asignaciones', si, no, no, no],
  ];
  return {
    titulo: 'Usuarios',
    sub: 'Quién entra al sistema, con qué rol y a qué obras',
    acciones: `<button class="btn primario" data-accion="nuevoUsuario">${IC.mas}Nuevo usuario</button>`,
    html: `<div class="card"><div class="tabla-wrap"><table class="tabla">
        <thead><tr><th>Usuario</th><th>Puesto</th><th>Rol</th><th>Obras</th><th>Estado</th><th></th></tr></thead><tbody>${filas}</tbody></table></div></div>
      <div class="card"><div class="card-h"><span class="card-t">Qué puede hacer cada rol</span></div><div class="tabla-wrap"><table class="tabla">
        <thead><tr><th>Permiso</th>${Object.values(ROLES).map((r) => `<th>${esc(r.nombre)}</th>`).join('')}</tr></thead>
        <tbody>${matriz.map((f) => `<tr>${f.map((c, i) => `<td>${i === 0 ? esc(c) : c}</td>`).join('')}</tr>`).join('')}</tbody></table></div></div>`,
  };
}

// ── Actividad global (administrador y coordinador) ──────────────────
function vistaActividad() {
  const lista = S().actividad.slice(0, 200);
  return {
    titulo: 'Actividad',
    sub: 'Registro de todo lo que se sube, cambia o elimina',
    html: `<div class="filtros"><select class="sel" id="f-act" aria-label="Filtrar por obra"><option value="">Todas las obras</option>${opciones(S().obras.map((o) => [o.id, `${o.clave} · ${o.nombre}`]))}</select></div>
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

// ── Obras ───────────────────────────────────────────────────────────
function siguienteClave() {
  const anio = new Date().getFullYear();
  const usadas = S().obras.map((o) => o.clave).filter((c) => c.startsWith(`CH-${anio}-`)).map((c) => parseInt(c.split('-')[2], 10) || 0);
  return `CH-${anio}-${String(Math.max(0, ...usadas) + 1).padStart(3, '0')}`;
}
function formObra(o) {
  const nuevo = !o;
  o = o || { clave: siguienteClave(), nombre: '', cliente: '', ubicacion: '', tipo: 'Edificación', estado: 'Planeación', residenteId: '', inicio: hoyISO(), fin: '', monto: '', avance: 0, descripcion: '' };
  const residentes = S().usuarios.filter((u) => u.rol === 'residente' && u.activo);
  modal({
    titulo: nuevo ? 'Nueva obra' : 'Editar obra',
    textoEnviar: nuevo ? 'Crear obra' : 'Guardar cambios',
    cuerpo: `
      <div class="fila"><div class="campo"><label>Clave</label><input type="text" name="clave" value="${esc(o.clave)}"></div>
        <div class="campo"><label>Tipo de obra</label><select name="tipo">${opciones(TIPOS_OBRA, o.tipo)}</select></div></div>
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
    alEnviar: (f) => {
      const datos = {
        clave: valor(f, 'clave'), nombre: valor(f, 'nombre'), cliente: valor(f, 'cliente'), ubicacion: valor(f, 'ubicacion'),
        tipo: valor(f, 'tipo'), estado: valor(f, 'estado'), residenteId: valor(f, 'residenteId'), inicio: valor(f, 'inicio'),
        fin: valor(f, 'fin'), monto: Number(valor(f, 'monto')) || 0, avance: Math.max(0, Math.min(100, Number(valor(f, 'avance')) || 0)),
        descripcion: valor(f, 'descripcion'),
      };
      if (!datos.nombre) throw new Invalido('Escribe el nombre de la obra.');
      if (!datos.cliente) throw new Invalido('Escribe el cliente.');
      if (!datos.clave) throw new Invalido('La clave no puede quedar vacía.');
      if (S().obras.some((x) => x.clave.toLowerCase() === datos.clave.toLowerCase() && x.id !== o.id)) throw new Invalido('Ya existe otra obra con esa clave.');
      if (datos.inicio && datos.fin && datos.fin < datos.inicio) throw new Invalido('La fecha de término es anterior a la de inicio.');
      let obra;
      if (nuevo) {
        obra = { id: nuevoId('o'), ...datos };
        S().obras.push(obra);
        registrar('creó la obra', obra.id, `${obra.clave} · ${obra.nombre}`);
      } else {
        obra = obraPor(o.id);
        Object.assign(obra, datos);
        registrar('actualizó los datos de la obra', obra.id, obra.clave);
      }
      // El residente responsable debe poder ver su obra.
      const res = usuarioPor(obra.residenteId);
      if (res && !res.obras.includes(obra.id)) res.obras.push(obra.id);
      cerrarModal();
      Store.guardar();
      location.hash = `#/obra/${obra.id}`;
      render();
      aviso(nuevo ? 'Obra creada con su expediente.' : 'Cambios guardados.');
    },
  });
}

function asignarEquipo(obraId) {
  const o = obraPor(obraId);
  const candidatos = S().usuarios.filter((u) => !esGestor(u));
  modal({
    titulo: `Personal de ${o.clave}`,
    textoEnviar: 'Guardar asignación',
    cuerpo: `<p style="font-size:13px;color:var(--tinta-2);margin-bottom:10px">Marca quién puede ver y trabajar esta obra. Administradores y coordinadores ya ven todas.</p>
      <div class="checks">${candidatos.map((u) => `<label><input type="checkbox" name="u" value="${esc(u.id)}" ${u.obras.includes(obraId) ? 'checked' : ''}>
        ${esc(u.nombre)} <small style="color:var(--tinta-3)">(${esc(ROLES[u.rol].nombre)})</small></label>`).join('') || 'No hay residentes ni usuarios de consulta.'}</div>`,
    alEnviar: (f) => {
      const marcados = new Set($$('input[name=u]:checked', f).map((i) => i.value));
      candidatos.forEach((u) => {
        const tenia = u.obras.includes(obraId);
        if (marcados.has(u.id) && !tenia) { u.obras.push(obraId); registrar(`asignó a ${u.nombre} a la obra`, obraId); }
        if (!marcados.has(u.id) && tenia) { u.obras = u.obras.filter((x) => x !== obraId); registrar(`quitó a ${u.nombre} de la obra`, obraId); }
      });
      cerrarModal();
      guardarYRefrescar();
    },
  });
}

// ── Documentos ──────────────────────────────────────────────────────
function formSubirDoc(obraId, carpetaId) {
  const o = obraPor(obraId);
  if (!puedeSubir(obraId)) return;
  const estados = esGestor() ? ESTADOS_DOC.filter((e) => e !== 'Obsoleto') : ['Borrador', 'En revisión'];
  const form = modal({
    titulo: `Subir documento · ${o.clave}`,
    textoEnviar: 'Subir',
    cuerpo: `
      ${campoArchivos({ multiple: true, texto: 'Arrastra aquí los archivos o haz clic para elegir' })}
      <div class="campo" style="margin-top:14px"><label>Carpeta *</label><select name="carpeta">${opciones(CARPETAS.map((c) => [c.id, c.nombre]), carpetaId || 'informes')}</select></div>
      <div class="campo"><label>Nombre del documento</label><input type="text" name="nombre" placeholder="Si lo dejas vacío se usa el nombre del archivo">
        <div class="ayuda">Sólo aplica si subes un archivo. Con varios, cada uno conserva su nombre.</div></div>
      <div class="fila"><div class="campo"><label>Estado</label><select name="estado">${opciones(estados, 'En revisión')}</select></div>
        <div class="campo"><label>Fecha de vencimiento</label><input type="date" name="vence"><div class="ayuda">Para licencias, fianzas, permisos, DC-3…</div></div></div>
      <div class="campo"><label>Etiquetas</label><input type="text" name="etiquetas" placeholder="Separadas por coma: fianza, municipal"></div>
      <div class="campo"><label>Descripción</label><textarea name="descripcion" placeholder="Opcional"></textarea></div>`,
    alEnviar: async (f) => {
      const archivos = selector.obtener();
      if (!archivos.length) throw new Invalido('Elige al menos un archivo.');
      const comun = {
        carpeta: valor(f, 'carpeta'), estado: valor(f, 'estado'), vence: valor(f, 'vence'),
        etiquetas: valor(f, 'etiquetas').split(',').map((t) => t.trim()).filter(Boolean), descripcion: valor(f, 'descripcion'),
      };
      const nombreManual = valor(f, 'nombre');
      for (const archivo of archivos) {
        const fileId = nuevoId('f');
        await Archivos.guardar(fileId, archivo);
        const nombre = (archivos.length === 1 && nombreManual) || archivo.name.replace(/\.[^.]+$/, '').replace(/[_]+/g, ' ');
        const ahora = new Date().toISOString();
        S().documentos.push({
          id: nuevoId('d'), obraId, ...comun, nombre, ext: extension(archivo.name), creado: ahora, usuarioId: usuario.id,
          versiones: [{ v: 1, fileId, fecha: ahora, usuarioId: usuario.id, nombreArchivo: archivo.name, tamano: archivo.size, tipo: archivo.type, nota: 'Versión inicial' }],
        });
        registrar('subió el documento', obraId, nombre);
      }
      cerrarModal();
      Store.guardar();
      location.hash = `#/obra/${obraId}/documentos/${comun.carpeta}`;
      render();
      aviso(archivos.length === 1 ? 'Documento subido.' : `${archivos.length} documentos subidos.`);
    },
  });
  const selector = montarArchivos(form);
}

function verDoc(id) {
  const d = S().documentos.find((x) => x.id === id);
  if (!d || !puedeVer(d.obraId)) return;
  const o = obraPor(d.obraId);
  const ult = ultimaVersion(d);
  const v = vencimiento(d);
  const gestor = esGestor();
  const puedeEditar = gestor || (puedeSubir(d.obraId) && d.usuarioId === usuario.id);
  const versiones = d.versiones.slice().reverse().map((ver) => `<li><span class="v">v${ver.v}</span>
      <div style="flex:1;min-width:0"><div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(ver.nombreArchivo)}</div>
      <small style="color:var(--tinta-3)">${fmt.fecha(ver.fecha)} · ${esc(nombreUsuario(ver.usuarioId))} · ${fmt.tamano(ver.tamano)}${ver.nota ? ' · ' + esc(ver.nota) : ''}</small></div>
      <button type="button" class="btn icono chico" data-accion="abrirVersion" data-doc="${esc(d.id)}" data-v="${ver.v}" data-modo="ver" title="Ver" aria-label="Ver versión ${ver.v}">${IC.ojo}</button>
      <button type="button" class="btn icono chico" data-accion="abrirVersion" data-doc="${esc(d.id)}" data-v="${ver.v}" data-modo="descargar" title="Descargar" aria-label="Descargar versión ${ver.v}">${IC.descargar}</button></li>`).join('');
  const form = modal({
    titulo: d.nombre,
    ancho: true,
    cuerpo: `
      <div style="display:flex;gap:12px;align-items:center;margin-bottom:16px;flex-wrap:wrap">${iconoExt(d.ext)}
        <div><div style="font-size:12.5px;color:var(--tinta-2)"><a href="#/obra/${esc(o.id)}/documentos/${esc(d.carpeta)}" data-cerrar>${esc(o.clave)} · ${esc(carpetaPor(d.carpeta)?.nombre)}</a></div>
        <div style="margin-top:4px;display:flex;gap:6px;flex-wrap:wrap">${chip(d.estado, CLASE_DOC[d.estado])}${v ? chip(v.texto, v.clase) : ''}</div></div>
        <div style="margin-left:auto;display:flex;gap:6px;flex-wrap:wrap">
          <button type="button" class="btn" data-accion="abrirVersion" data-doc="${esc(d.id)}" data-v="${ult.v}" data-modo="ver">${IC.ojo}Ver</button>
          <button type="button" class="btn" data-accion="abrirVersion" data-doc="${esc(d.id)}" data-v="${ult.v}" data-modo="descargar">${IC.descargar}Descargar</button>
          ${puedeSubir(d.obraId) ? `<button type="button" class="btn primario" data-accion="nuevaVersion" data-id="${esc(d.id)}">${IC.subir}Nueva versión</button>` : ''}
        </div></div>
      <dl class="ficha" style="margin-bottom:18px">
        <div><dt>Versión vigente</dt><dd>v${ult.v} · ${fmt.tamano(ult.tamano)}</dd></div>
        <div><dt>Última actualización</dt><dd>${fmt.fecha(ult.fecha)}</dd></div>
        <div><dt>Subió</dt><dd>${esc(nombreUsuario(ult.usuarioId))}</dd></div>
        <div><dt>Vencimiento</dt><dd>${d.vence ? fmt.fecha(d.vence) : '—'}</dd></div>
        <div style="grid-column:1/-1"><dt>Etiquetas</dt><dd>${d.etiquetas.length ? d.etiquetas.map((t) => `<span class="tag">${esc(t)}</span>`).join('') : '—'}</dd></div>
        ${d.descripcion ? `<div style="grid-column:1/-1"><dt>Descripción</dt><dd>${esc(d.descripcion)}</dd></div>` : ''}
      </dl>
      ${gestor ? `<div class="campo" style="max-width:320px"><label>Estado del documento</label>
        <div style="display:flex;gap:8px"><select id="estado-doc">${opciones(ESTADOS_DOC, d.estado)}</select></div>
        <div class="ayuda">Aprobado = versión válida para usar en obra. Obsoleto = se conserva pero ya no aplica.</div></div>` : ''}
      <div style="display:flex;align-items:center;gap:8px;margin:6px 0 8px">${IC.historial.replace('<svg', '<svg width="16" height="16"')}<b style="font-size:13px">Historial de versiones</b></div>
      <ul class="versiones">${versiones}</ul>`,
    pie: `${gestor ? `<button type="button" class="btn peligro" data-accion="eliminarDoc" data-id="${esc(d.id)}" style="margin-right:auto">${IC.basura}Eliminar</button>` : ''}
      ${puedeEditar ? `<button type="button" class="btn" data-accion="editarDoc" data-id="${esc(d.id)}">${IC.lapiz}Editar datos</button>` : ''}
      <button type="button" class="btn" data-cerrar>Cerrar</button>`,
  });
  $('#estado-doc', form)?.addEventListener('change', (e) => {
    d.estado = e.target.value;
    registrar(`cambió a «${d.estado}» el documento`, d.obraId, d.nombre);
    Store.guardar();
    render();
    verDoc(d.id);
    aviso('Estado actualizado.');
  });
}

function formEditarDoc(id) {
  const d = S().documentos.find((x) => x.id === id);
  modal({
    titulo: 'Editar datos del documento',
    cuerpo: `
      <div class="campo"><label>Nombre *</label><input type="text" name="nombre" value="${esc(d.nombre)}"></div>
      <div class="fila"><div class="campo"><label>Carpeta</label><select name="carpeta">${opciones(CARPETAS.map((c) => [c.id, c.nombre]), d.carpeta)}</select></div>
        <div class="campo"><label>Fecha de vencimiento</label><input type="date" name="vence" value="${esc(d.vence)}"></div></div>
      <div class="campo"><label>Etiquetas</label><input type="text" name="etiquetas" value="${esc(d.etiquetas.join(', '))}"></div>
      <div class="campo"><label>Descripción</label><textarea name="descripcion">${esc(d.descripcion)}</textarea></div>`,
    alEnviar: (f) => {
      const nombre = valor(f, 'nombre');
      if (!nombre) throw new Invalido('El nombre no puede quedar vacío.');
      Object.assign(d, {
        nombre, carpeta: valor(f, 'carpeta'), vence: valor(f, 'vence'), descripcion: valor(f, 'descripcion'),
        etiquetas: valor(f, 'etiquetas').split(',').map((t) => t.trim()).filter(Boolean),
      });
      registrar('editó los datos del documento', d.obraId, d.nombre);
      Store.guardar();
      render();
      verDoc(d.id);
    },
  });
}

function formNuevaVersion(id) {
  const d = S().documentos.find((x) => x.id === id);
  const form = modal({
    titulo: `Nueva versión · ${d.nombre}`,
    textoEnviar: 'Subir versión',
    cuerpo: `${campoArchivos({ texto: 'Elige el archivo de la nueva versión' })}
      <div class="campo" style="margin-top:14px"><label>¿Qué cambió?</label><input type="text" name="nota" placeholder="Ej.: se corrigen cotas del eje 4"></div>
      <div class="ayuda" style="font-size:12px;color:var(--tinta-3)">La versión anterior se conserva en el historial.${esGestor() ? '' : ' El documento pasa a «En revisión» hasta que un coordinador lo apruebe.'}</div>`,
    alEnviar: async (f) => {
      const [archivo] = selector.obtener();
      if (!archivo) throw new Invalido('Elige el archivo.');
      const fileId = nuevoId('f');
      await Archivos.guardar(fileId, archivo);
      const v = ultimaVersion(d).v + 1;
      d.versiones.push({ v, fileId, fecha: new Date().toISOString(), usuarioId: usuario.id, nombreArchivo: archivo.name, tamano: archivo.size, tipo: archivo.type, nota: valor(f, 'nota') });
      d.ext = extension(archivo.name);
      if (!esGestor()) d.estado = 'En revisión';
      registrar('subió una nueva versión de', d.obraId, `${d.nombre} (v${v})`);
      Store.guardar();
      render();
      verDoc(d.id);
      aviso(`Versión ${v} guardada.`);
    },
  });
  const selector = montarArchivos(form);
}

// ── Presupuestos ────────────────────────────────────────────────────
function siguienteFolio(obraId) {
  const n = S().presupuestos.filter((p) => p.obraId === obraId).map((p) => parseInt(p.folio.replace(/\D/g, ''), 10) || 0);
  return 'PRE-' + String(Math.max(0, ...n) + 1).padStart(3, '0');
}
function formPresupuesto(obraId, p) {
  const nuevo = !p;
  p = p || { folio: siguienteFolio(obraId), concepto: '', monto: '', estado: 'Borrador', fecha: hoyISO(), notas: '' };
  const estados = esGestor() ? ESTADOS_PRESUPUESTO : ['Borrador', 'Enviado al cliente'];
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
      const datos = { folio: valor(f, 'folio') || siguienteFolio(obraId), fecha: valor(f, 'fecha'), concepto, monto, estado: valor(f, 'estado'), notas: valor(f, 'notas') };
      let destino;
      if (nuevo) {
        destino = { id: nuevoId('p'), obraId, usuarioId: usuario.id, fileId: null, nombreArchivo: '', ...datos };
        S().presupuestos.push(destino);
        registrar('creó el presupuesto', obraId, `${datos.folio} · ${concepto}`);
      } else {
        destino = S().presupuestos.find((x) => x.id === p.id);
        const cambioEstado = destino.estado !== datos.estado;
        Object.assign(destino, datos);
        registrar(cambioEstado ? `cambió a «${datos.estado}» el presupuesto` : 'editó el presupuesto', obraId, `${datos.folio} · ${concepto}`);
      }
      if (archivo) {
        const fileId = nuevoId('f');
        await Archivos.guardar(fileId, archivo);
        if (destino.fileId) Archivos.borrar(destino.fileId);
        destino.fileId = fileId;
        destino.nombreArchivo = archivo.name;
      }
      cerrarModal();
      Store.guardar();
      location.hash = `#/obra/${obraId}/presupuestos`;
      render();
      aviso('Presupuesto guardado.');
    },
  });
  const selector = montarArchivos(form);
}
function verPresupuesto(id) {
  const p = S().presupuestos.find((x) => x.id === id);
  if (!p || !puedeVer(p.obraId)) return;
  const o = obraPor(p.obraId);
  const puedeEditar = esGestor() || (puedeSubir(p.obraId) && p.usuarioId === usuario.id);
  modal({
    titulo: `${p.folio} · ${p.concepto}`,
    cuerpo: `<dl class="ficha">
        <div><dt>Obra</dt><dd>${esc(o.clave)}</dd></div>
        <div><dt>Fecha</dt><dd>${fmt.fecha(p.fecha)}</dd></div>
        <div><dt>Monto</dt><dd><b>${fmt.moneda(p.monto)}</b></dd></div>
        <div><dt>Estado</dt><dd>${chip(p.estado, CLASE_PRES[p.estado])}</dd></div>
        <div><dt>Elaboró</dt><dd>${esc(nombreUsuario(p.usuarioId))}</dd></div>
        <div><dt>Archivo</dt><dd>${p.nombreArchivo ? `<a href="#" data-accion="archivoPresupuesto" data-id="${esc(p.id)}">${esc(p.nombreArchivo)}</a>` : '—'}</dd></div>
        ${p.notas ? `<div style="grid-column:1/-1"><dt>Notas</dt><dd>${esc(p.notas)}</dd></div>` : ''}</dl>`,
    pie: `${esGestor() ? `<button type="button" class="btn peligro" data-accion="eliminarPresupuesto" data-id="${esc(p.id)}" style="margin-right:auto">${IC.basura}Eliminar</button>` : ''}
      ${puedeEditar ? `<button type="button" class="btn" data-accion="editarPresupuesto" data-id="${esc(p.id)}">${IC.lapiz}Editar</button>` : ''}
      <button type="button" class="btn" data-cerrar>Cerrar</button>`,
  });
}

// ── Reportes fotográficos ───────────────────────────────────────────
function formReporte(obraId) {
  const semana = `Semana ${Math.ceil((Date.now() - new Date(new Date().getFullYear(), 0, 1)) / 604800000)}`;
  let fotos = []; // { blob, url, pie }
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
      $$('#prev-fotos textarea', f).forEach((t) => { fotos[Number(t.dataset.i)].pie = t.value.trim(); });
      const guardadas = [];
      for (const foto of fotos) {
        const fileId = nuevoId('f');
        await Archivos.guardar(fileId, foto.blob);
        guardadas.push({ fileId, pie: foto.pie || '' });
      }
      const r = { id: nuevoId('r'), obraId, titulo, fecha: valor(f, 'fecha') || hoyISO(), periodo: valor(f, 'periodo'), descripcion: valor(f, 'descripcion'), usuarioId: usuario.id, fotos: guardadas };
      S().reportes.push(r);
      registrar('publicó el reporte fotográfico', obraId, titulo);
      cerrarModal();
      Store.guardar();
      location.hash = `#/obra/${obraId}/reportes/${r.id}`;
      render();
      aviso('Reporte publicado.');
    },
  });
  const pintar = () => {
    // Conserva lo que ya se escribió en los pies antes de volver a pintar.
    $$('#prev-fotos textarea', form).forEach((t) => { if (fotos[Number(t.dataset.i)]) fotos[Number(t.dataset.i)].pie = t.value; });
    $('#prev-fotos', form).innerHTML = fotos.map((ft, i) => `<figure style="position:relative">
        <img src="${ft.url}" alt="">
        <button type="button" class="btn icono chico" data-quitar="${i}" style="position:absolute;top:4px;right:4px" aria-label="Quitar foto">${IC.basura}</button>
        <textarea data-i="${i}" placeholder="Pie de foto: qué se ve, eje, nivel…">${esc(ft.pie)}</textarea></figure>`).join('');
  };
  $('#prev-fotos', form).addEventListener('click', (e) => {
    const b = e.target.closest('[data-quitar]');
    if (!b) return;
    $$('#prev-fotos textarea', form).forEach((t) => { fotos[Number(t.dataset.i)].pie = t.value; });
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
      const blob = await reducirImagen(img);
      fotos.push({ blob, url: URL.createObjectURL(blob), pie: '' });
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
      <button type="button" class="btn" data-cerrar>Cerrar</button>`,
  });
}

// ── Usuarios ────────────────────────────────────────────────────────
function formUsuario(u) {
  const nuevo = !u;
  u = u || { nombre: '', email: '', puesto: '', rol: 'residente', activo: true, obras: [] };
  const esYo = u.id === usuario.id;
  const form = modal({
    titulo: nuevo ? 'Nuevo usuario' : `Editar · ${u.nombre}`,
    textoEnviar: nuevo ? 'Crear usuario' : 'Guardar cambios',
    cuerpo: `
      <div class="fila"><div class="campo"><label>Nombre completo *</label><input type="text" name="nombre" value="${esc(u.nombre)}"></div>
        <div class="campo"><label>Puesto</label><input type="text" name="puesto" value="${esc(u.puesto)}" placeholder="Ej.: Residente de obra"></div></div>
      <div class="fila"><div class="campo"><label>Correo *</label><input type="email" name="email" value="${esc(u.email)}"></div>
        <div class="campo"><label>Contraseña ${nuevo ? '*' : ''}</label><input type="password" name="password" autocomplete="new-password" placeholder="${nuevo ? 'Mínimo 6 caracteres' : 'Déjala vacía para no cambiarla'}"></div></div>
      <div class="fila"><div class="campo"><label>Rol</label><select name="rol" ${esYo ? 'disabled' : ''}>${opciones(Object.entries(ROLES).map(([k, r]) => [k, r.nombre]), u.rol)}</select>
          <div class="ayuda" id="desc-rol">${esc(ROLES[u.rol].desc)}</div></div>
        <div class="campo"><label>Estado</label><label style="display:flex;gap:8px;align-items:center;font-weight:400;color:var(--tinta);margin-top:8px">
          <input type="checkbox" name="activo" ${u.activo ? 'checked' : ''} ${esYo ? 'disabled' : ''}> Puede iniciar sesión</label></div></div>
      <div class="campo" id="campo-obras"><label>Obras asignadas</label>
        <div class="checks">${S().obras.map((o) => `<label><input type="checkbox" name="obra" value="${esc(o.id)}" ${u.obras.includes(o.id) ? 'checked' : ''}> ${esc(o.clave)} · ${esc(o.nombre)}</label>`).join('')}</div></div>
      ${esYo ? '<div class="ayuda" style="font-size:12px;color:var(--tinta-3)">No puedes cambiar tu propio rol ni desactivarte.</div>' : ''}`,
    alEnviar: (f) => {
      const datos = {
        nombre: valor(f, 'nombre'), puesto: valor(f, 'puesto'), email: valor(f, 'email').toLowerCase(),
        rol: esYo ? u.rol : valor(f, 'rol'), activo: esYo ? true : f.elements.activo.checked,
        obras: $$('input[name=obra]:checked', f).map((i) => i.value),
      };
      const pass = f.elements.password.value;
      if (!datos.nombre) throw new Invalido('Escribe el nombre.');
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(datos.email)) throw new Invalido('Escribe un correo válido.');
      if (S().usuarios.some((x) => x.email.toLowerCase() === datos.email && x.id !== u.id)) throw new Invalido('Ya hay un usuario con ese correo.');
      if (nuevo && pass.length < 6) throw new Invalido('La contraseña debe tener al menos 6 caracteres.');
      if (!nuevo && pass && pass.length < 6) throw new Invalido('La contraseña debe tener al menos 6 caracteres.');
      if (nuevo) {
        S().usuarios.push({ id: nuevoId('u'), ...datos, password: pass });
        registrar('dio de alta al usuario', '', datos.nombre);
      } else {
        const destino = usuarioPor(u.id);
        Object.assign(destino, datos);
        if (pass) destino.password = pass;
        registrar('actualizó al usuario', '', datos.nombre);
      }
      cerrarModal();
      guardarYRefrescar();
      aviso(nuevo ? 'Usuario creado.' : 'Usuario actualizado.');
    },
  });
  const sel = form.elements.rol;
  const alternar = () => {
    const r = sel.value;
    $('#desc-rol', form).textContent = ROLES[r].desc;
    $('#campo-obras', form).classList.toggle('oculto', r === 'admin' || r === 'coordinador');
  };
  sel.addEventListener('change', alternar);
  alternar();
}

// ── Mapa de acciones: data-accion="nombre" en el HTML → función ────
const ACCIONES = {
  menu: (_ds, boton) => boton.setAttribute('aria-expanded', $('#cabecera').classList.toggle('abierta')),
  salir: () => { Store.cerrarSesion(); usuario = null; location.hash = ''; render(); },
  accesoRapido: (ds) => entrar(usuarioPor(ds.id)),
  restablecer: () => confirmar('Restablecer datos de ejemplo',
    'Se borrará todo lo que hayas agregado o cambiado en este navegador y se cargarán de nuevo los datos de ejemplo.',
    async () => {
      await Store.restablecer();
      urlsArchivos.clear();
      cerrarModal();
      if (usuario) usuario = usuarioPor(usuario.id) || null;
      if (!usuario) Store.cerrarSesion();
      render();
      aviso('Datos de ejemplo restablecidos.');
    }, 'Restablecer'),
  imprimir: () => window.print(),
  nuevaObra: () => formObra(),
  editarObra: (ds) => formObra(obraPor(ds.id)),
  asignarEquipo: (ds) => asignarEquipo(ds.obra),
  subirDoc: (ds) => formSubirDoc(ds.obra, ds.carpeta),
  verDoc: (ds) => verDoc(ds.id),
  editarDoc: (ds) => formEditarDoc(ds.id),
  nuevaVersion: (ds) => formNuevaVersion(ds.id),
  descargarDoc: (ds) => {
    const d = S().documentos.find((x) => x.id === ds.id);
    const v = ultimaVersion(d);
    abrirArchivo(v.fileId, v.nombreArchivo, 'descargar');
  },
  abrirVersion: (ds) => {
    const d = S().documentos.find((x) => x.id === ds.doc);
    const v = d.versiones.find((x) => x.v === Number(ds.v));
    abrirArchivo(v.fileId, v.nombreArchivo, ds.modo);
  },
  eliminarDoc: (ds) => {
    const d = S().documentos.find((x) => x.id === ds.id);
    confirmar('Eliminar documento', `¿Eliminar <b>${esc(d.nombre)}</b> y sus ${d.versiones.length} versión(es)? Esta acción no se puede deshacer.`, () => {
      d.versiones.forEach((v) => Archivos.borrar(v.fileId));
      S().documentos = S().documentos.filter((x) => x.id !== d.id);
      registrar('eliminó el documento', d.obraId, d.nombre);
      cerrarModal();
      guardarYRefrescar();
      aviso('Documento eliminado.');
    });
  },
  nuevoPresupuesto: (ds) => formPresupuesto(ds.obra),
  verPresupuesto: (ds) => verPresupuesto(ds.id),
  editarPresupuesto: (ds) => { const p = S().presupuestos.find((x) => x.id === ds.id); formPresupuesto(p.obraId, p); },
  archivoPresupuesto: (ds) => { const p = S().presupuestos.find((x) => x.id === ds.id); abrirArchivo(p.fileId, p.nombreArchivo, 'descargar'); },
  eliminarPresupuesto: (ds) => {
    const p = S().presupuestos.find((x) => x.id === ds.id);
    confirmar('Eliminar presupuesto', `¿Eliminar <b>${esc(p.folio)} · ${esc(p.concepto)}</b>?`, () => {
      Archivos.borrar(p.fileId);
      S().presupuestos = S().presupuestos.filter((x) => x.id !== p.id);
      registrar('eliminó el presupuesto', p.obraId, `${p.folio} · ${p.concepto}`);
      cerrarModal();
      guardarYRefrescar();
    });
  },
  nuevoReporte: (ds) => formReporte(ds.obra),
  verFoto: (ds) => verFoto(ds.reporte, Number(ds.i)),
  eliminarReporte: (ds) => {
    const r = S().reportes.find((x) => x.id === ds.id);
    confirmar('Eliminar reporte', `¿Eliminar <b>${esc(r.titulo)}</b> con sus ${r.fotos.length} fotos?`, () => {
      r.fotos.forEach((f) => Archivos.borrar(f.fileId));
      S().reportes = S().reportes.filter((x) => x.id !== r.id);
      registrar('eliminó el reporte fotográfico', r.obraId, r.titulo);
      cerrarModal();
      Store.guardar();
      location.hash = `#/obra/${r.obraId}/reportes`;
      render();
    });
  },
  nuevoUsuario: () => formUsuario(),
  editarUsuario: (ds) => formUsuario(usuarioPor(ds.id)),
};

document.addEventListener('click', (e) => {
  const boton = e.target.closest('[data-accion]');
  if (boton && ACCIONES[boton.dataset.accion]) {
    e.preventDefault();
    e.stopPropagation();
    ACCIONES[boton.dataset.accion](boton.dataset, boton);
    return;
  }
  const destino = e.target.closest('[data-ir]');
  if (destino && !e.target.closest('a')) location.hash = destino.dataset.ir;
});

// ════════════════════════════════════════════════════════════════════
// 6. Arranque y navegación
// ════════════════════════════════════════════════════════════════════
function entrar(u) {
  if (!u || !u.activo) return;
  usuario = u;
  Store.iniciarSesion(u.id);
  if (location.hash && location.hash !== '#/' && location.hash !== '#') render();
  else location.hash = '#/inicio';
}

function render() {
  const app = $('#app');
  cerrarModal();
  if (!usuario) {
    document.title = 'CH Arquitectura y Construcción — Acceso';
    app.innerHTML = banner() + vistaLogin();
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
    case 'obra': v = vistaObra(partes[1], partes[2], partes[3], params); seccion = 'obras'; break;
    case 'buscar': v = vistaBuscar(params.get('q') || ''); break;
    case 'usuarios': v = esAdmin() ? vistaUsuarios() : vistaNoEncontrada('Sólo el administrador gestiona usuarios.'); break;
    case 'actividad': v = esGestor() ? vistaActividad() : vistaNoEncontrada('No tienes acceso a esta sección.'); break;
    default: seccion = 'inicio'; v = vistaInicio();
  }
  document.title = `${v.titulo} — CH Arquitectura y Construcción`;
  app.innerHTML = banner() + shell(seccion, v);
  if (v.montar) v.montar();
  hidratarImagenes(app);
}

window.addEventListener('hashchange', () => { render(); window.scrollTo(0, 0); });

(function iniciar() {
  Store.cargar();
  const id = Store.sesion();
  usuario = S().usuarios.find((u) => u.id === id && u.activo) || null;
  render();
})();
