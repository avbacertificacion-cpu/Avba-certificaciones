/* CH Arquitectura y Construcción — Conexión con el servidor.
 *
 * Toda la información vive en el servidor (PHP + MySQL) y los archivos en
 * el almacenamiento (disco del servidor o nube compatible con S3).
 * Este archivo:
 *   - Define las listas fijas (carpetas del expediente, estados…).
 *   - Habla con la API: api/?r=modulo/accion
 *   - Guarda en memoria el "estado": todo lo que el usuario puede ver.
 *   - Sube archivos con barra de progreso.
 */
'use strict';

// Expediente estándar de cada obra (igual que en el servidor).
const CARPETAS = [
  { id: 'contrato',    nombre: 'Contrato y legal',            desc: 'Contrato, fianzas, convenios modificatorios, actas constitutivas.' },
  { id: 'proyecto',    nombre: 'Proyecto y planos',           desc: 'Planos arquitectónicos y estructurales, memorias de cálculo, especificaciones.' },
  { id: 'permisos',    nombre: 'Permisos y licencias',        desc: 'Licencia de construcción, uso de suelo, impacto ambiental, protección civil.' },
  { id: 'estimaciones',nombre: 'Estimaciones y generadores',  desc: 'Estimaciones de obra, números generadores, croquis de cuantificación.' },
  { id: 'bitacora',    nombre: 'Bitácora de obra',            desc: 'Notas de bitácora, instrucciones del supervisor, órdenes de cambio.' },
  { id: 'calidad',     nombre: 'Calidad y laboratorio',       desc: 'Pruebas de concreto, compactación, certificados de materiales.' },
  { id: 'seguridad',   nombre: 'Seguridad e higiene',         desc: 'Programa de seguridad, capacitaciones, permisos de trabajo, EPP.' },
  { id: 'informes',    nombre: 'Informes y minutas',          desc: 'Informes de avance, minutas de reunión, correspondencia oficial.' },
  { id: 'cierre',      nombre: 'Entrega y cierre',            desc: 'Actas de entrega-recepción, finiquito, planos as-built, garantías.' },
];

// Los roles y sus permisos llegan del servidor; aquí sólo el color del distintivo.
let ROLES = {};
const COLOR_ROL = { admin: 'marca', direccion: 'marca', coordinador: 'info', residente: 'ok', supervisor: 'aviso', administracion: 'info', consulta: '' };

const ESTADOS_OBRA = ['Planeación', 'En ejecución', 'Suspendida', 'Terminada'];
const ESTADOS_DOC = ['Borrador', 'En revisión', 'Aprobado', 'Obsoleto'];
const ESTADOS_PRESUPUESTO = ['Borrador', 'Enviado al cliente', 'Aprobado', 'Rechazado'];
const TIPOS_OBRA = ['Edificación', 'Industrial', 'Urbanización', 'Obra pública', 'Remodelación', 'Otro'];

function hoyISO() {
  const d = new Date();
  d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
  return d.toISOString().slice(0, 10);
}

// Error que manda el servidor (permiso, validación…). Se muestra tal cual.
class ErrorServidor extends Error {
  constructor(mensaje, estado) { super(mensaje); this.estado = estado; }
}

const Api = {
  csrf: '',
  alTerminarSesion: null, // la aplicación decide qué hacer (volver al inicio de sesión)

  async pedir(metodo, ruta, datos) {
    const opciones = { method: metodo, credentials: 'same-origin', headers: { 'X-CSRF': this.csrf } };
    if (datos !== undefined) {
      opciones.headers['Content-Type'] = 'application/json';
      opciones.body = JSON.stringify(datos);
    }
    let r;
    try {
      r = await fetch('api/?r=' + ruta, opciones);
    } catch (e) {
      throw new ErrorServidor('No hay conexión con el servidor. Revisa tu internet e intenta de nuevo.', 0);
    }
    let j = null;
    try { j = await r.json(); } catch (e) { /* respuesta sin JSON */ }
    if (!r.ok || !j || j.ok === false) {
      const mensaje = j?.error || `El servidor respondió con un error (${r.status}).`;
      if ((r.status === 401 || r.status === 419) && ruta !== 'auth/entrar' && this.alTerminarSesion) this.alTerminarSesion(mensaje);
      const err = new ErrorServidor(mensaje, r.status);
      err.datos = j;
      throw err;
    }
    return j;
  },
  get(ruta) { return this.pedir('GET', ruta); },
  post(ruta, datos = {}) { return this.pedir('POST', ruta, datos); },

  // Dirección para ver o descargar un archivo. El servidor revisa el permiso
  // y redirige a la nube con un enlace temporal (o lo entrega él mismo).
  urlArchivo(fileId, modo = 'ver') {
    return `api/?r=archivos/descargar&id=${encodeURIComponent(fileId)}&modo=${modo}`;
  },
  urlArchivoMuerto(id, modo = 'descargar') {
    return `api/?r=archivo/descargar&id=${encodeURIComponent(id)}&modo=${modo}`;
  },

  /* Sube un archivo en tres pasos:
       1. "iniciar": el servidor registra el archivo y da la dirección de subida.
       2. El navegador lo envía directo a esa dirección (la nube, o el servidor).
       3. "confirmar": el servidor revisa que llegó completo.
     alProgreso(0..1) sirve para pintar la barra. Devuelve el id del archivo. */
  async subir(archivo, extra = {}, alProgreso = null) {
    const t = await this.post('archivos/iniciar', {
      nombre: archivo.name || 'archivo', tipo: archivo.type || 'application/octet-stream', tamano: archivo.size, ...extra,
    });
    await new Promise((resolver, rechazar) => {
      const xhr = new XMLHttpRequest();
      const local = !/^https?:/i.test(t.url);
      xhr.open(t.metodo || 'PUT', t.url);
      Object.entries(t.headers || {}).forEach(([k, v]) => xhr.setRequestHeader(k, v));
      if (local) xhr.setRequestHeader('X-CSRF', this.csrf); // sólo al propio servidor
      xhr.upload.onprogress = (e) => { if (alProgreso && e.lengthComputable) alProgreso(e.loaded / e.total); };
      xhr.onload = () => (xhr.status >= 200 && xhr.status < 300 ? resolver() : rechazar(new ErrorServidor(
        `No se pudo subir «${archivo.name}» (error ${xhr.status}).${local ? '' : ' Revisa la configuración CORS del almacenamiento.'}`, xhr.status)));
      xhr.onerror = () => rechazar(new ErrorServidor(`Se cortó la conexión al subir «${archivo.name}». Intenta de nuevo.`, 0));
      xhr.send(archivo);
    });
    if (alProgreso) alProgreso(1);
    const c = await this.post('archivos/confirmar', { id: t.id });
    return c.id;
  },
};

// ── Estado en memoria ─────────────────────────────────────────────────
// S() da todo lo que el usuario puede ver: obras, documentos, usuarios…
// Después de cada cambio se vuelve a pedir al servidor.
const Store = {
  s: null,
  sesion: null, // { usuario, roles, config }

  async cargarSesion() {
    const j = await Api.get('auth/estado');
    this.aplicarSesion(j);
    return j;
  },
  aplicarSesion(j) {
    Api.csrf = j.csrf || '';
    ROLES = {};
    Object.entries(j.roles || {}).forEach(([k, r]) => { ROLES[k] = { ...r, color: COLOR_ROL[k] ?? '' }; });
    this.sesion = j;
  },
  async recargar() {
    const j = await Api.get('estado');
    delete j.ok;
    this.s = j;
    return j;
  },
};
