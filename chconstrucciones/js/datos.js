/* CH Arquitectura y Construcción — Capa de datos del DEMO.
 *
 * En el demo todo vive en el navegador de quien lo usa:
 *   - La información (obras, usuarios, documentos...) en localStorage.
 *   - Los archivos que se suben (PDF, fotos...) en IndexedDB.
 * En la versión real esto se reemplaza por PHP + MySQL y los archivos se
 * guardan en el servidor. La forma de los datos es la misma que tendrán
 * las tablas, por eso sirve para validar el diseño.
 */

// Expediente estándar de cada obra. Es la estructura que se repite en todas
// para que cualquier persona encuentre un documento en el mismo lugar.
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

const ROLES = {
  admin:       { nombre: 'Administrador',        color: 'acento', desc: 'Todo, incluida la gestión de usuarios.' },
  coordinador: { nombre: 'Coordinador',          color: 'azul',    desc: 'Todas las obras: crea obras, aprueba y elimina documentos.' },
  residente:   { nombre: 'Residente de obra',    color: 'verde',   desc: 'Sólo sus obras asignadas: sube documentos, presupuestos y reportes.' },
  consulta:    { nombre: 'Consulta',             color: '',        desc: 'Sólo lectura de las obras asignadas.' },
};

const ESTADOS_OBRA = ['Planeación', 'En ejecución', 'Suspendida', 'Terminada'];
const ESTADOS_DOC = ['Borrador', 'En revisión', 'Aprobado', 'Obsoleto'];
const ESTADOS_PRESUPUESTO = ['Borrador', 'Enviado al cliente', 'Aprobado', 'Rechazado'];
const TIPOS_OBRA = ['Edificación', 'Industrial', 'Urbanización', 'Obra pública', 'Remodelación', 'Otro'];

// ── Utilidades de fecha ─────────────────────────────────────────────
function hoyISO() {
  const d = new Date();
  d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
  return d.toISOString().slice(0, 10);
}
function diasDesdeHoy(n) {
  const d = new Date();
  d.setDate(d.getDate() + n);
  d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
  return d.toISOString().slice(0, 10);
}
function momentoDesdeHoy(dias, hora = 10) {
  const d = new Date();
  d.setDate(d.getDate() + dias);
  d.setHours(hora, Math.floor(Math.random() * 60), 0, 0);
  return d.toISOString();
}
function nuevoId(prefijo) {
  return prefijo + '_' + Date.now().toString(36) + Math.random().toString(36).slice(2, 7);
}

// ── Datos de ejemplo ────────────────────────────────────────────────
// Las fechas se calculan a partir de hoy para que los vencimientos y la
// actividad reciente siempre se vean realistas al abrir el demo.
function datosIniciales() {
  const usuarios = [
    { id: 'u1', nombre: 'Carlos Hernández', email: 'admin@chconstrucciones.mx',       password: 'demo123', rol: 'admin',       puesto: 'Director general',        activo: true, obras: [] },
    { id: 'u2', nombre: 'Laura Méndez',     email: 'coordinador@chconstrucciones.mx', password: 'demo123', rol: 'coordinador', puesto: 'Gerente de proyectos',    activo: true, obras: [] },
    { id: 'u3', nombre: 'Jorge Ramírez',    email: 'residente@chconstrucciones.mx',   password: 'demo123', rol: 'residente',   puesto: 'Residente de obra',       activo: true, obras: ['o1', 'o2', 'o4'] },
    { id: 'u4', nombre: 'Ana Torres',       email: 'atorres@chconstrucciones.mx',     password: 'demo123', rol: 'residente',   puesto: 'Residente de obra',       activo: true, obras: ['o3'] },
    { id: 'u5', nombre: 'Patricia Luna',    email: 'consulta@chconstrucciones.mx',    password: 'demo123', rol: 'consulta',    puesto: 'Administración y compras', activo: true, obras: ['o1', 'o2'] },
  ];

  const obras = [
    { id: 'o1', clave: 'CH-2026-001', nombre: 'Nave industrial Parque Norte', cliente: 'Grupo Industrial del Bajío', ubicacion: 'Parque Industrial Norte, lote 14', tipo: 'Industrial',
      residenteId: 'u3', inicio: diasDesdeHoy(-128), fin: diasDesdeHoy(95), monto: 18450000, avance: 62, estado: 'En ejecución',
      descripcion: 'Nave de 4,800 m² con estructura metálica, losa de concreto industrial y oficinas en mezzanine.' },
    { id: 'o2', clave: 'CH-2026-002', nombre: 'Pavimentación calle Hidalgo', cliente: 'H. Ayuntamiento (obra pública)', ubicacion: 'Calle Hidalgo, tramo Juárez–Morelos', tipo: 'Obra pública',
      residenteId: 'u3', inicio: diasDesdeHoy(-46), fin: diasDesdeHoy(74), monto: 6230000, avance: 35, estado: 'En ejecución',
      descripcion: 'Pavimento de concreto hidráulico MR-45, guarniciones, banquetas y red de drenaje pluvial (820 m).' },
    { id: 'o3', clave: 'CH-2026-003', nombre: 'Edificio de oficinas Torre Sur', cliente: 'Inmobiliaria Sur Desarrollos', ubicacion: 'Av. Principal 1200, zona centro', tipo: 'Edificación',
      residenteId: 'u4', inicio: diasDesdeHoy(21), fin: diasDesdeHoy(420), monto: 41800000, avance: 5, estado: 'Planeación',
      descripcion: 'Edificio de 7 niveles y 2 sótanos de estacionamiento, estructura de concreto armado.' },
    { id: 'o4', clave: 'CH-2025-014', nombre: 'Bodega de almacenamiento San Juan', cliente: 'Distribuidora San Juan', ubicacion: 'Carretera estatal km 8.5', tipo: 'Industrial',
      residenteId: 'u3', inicio: diasDesdeHoy(-310), fin: diasDesdeHoy(-60), monto: 7900000, avance: 100, estado: 'Terminada',
      descripcion: 'Bodega de 1,500 m² con andenes de carga. Obra entregada y en periodo de garantía.' },
  ];

  // [obra, carpeta, nombre, extensión, estado, vence (días desde hoy o null), nº de versiones, usuario, etiquetas]
  const semillaDocs = [
    ['o1', 'contrato', 'Contrato de obra a precio alzado', 'pdf', 'Aprobado', null, 1, 'u2', 'contrato,firmado'],
    ['o1', 'contrato', 'Fianza de cumplimiento', 'pdf', 'Aprobado', 12, 1, 'u2', 'fianza'],
    ['o1', 'contrato', 'Fianza de anticipo', 'pdf', 'Aprobado', 40, 1, 'u2', 'fianza'],
    ['o1', 'proyecto', 'Planos estructurales rev. C', 'dwg', 'Aprobado', null, 3, 'u3', 'estructura,planos'],
    ['o1', 'proyecto', 'Memoria de cálculo estructural', 'pdf', 'Aprobado', null, 2, 'u2', 'estructura'],
    ['o1', 'proyecto', 'Planos de instalaciones eléctricas', 'dwg', 'En revisión', null, 1, 'u3', 'eléctrico,planos'],
    ['o1', 'permisos', 'Licencia de construcción', 'pdf', 'Aprobado', 25, 1, 'u2', 'licencia,municipal'],
    ['o1', 'permisos', 'Dictamen de protección civil', 'pdf', 'Aprobado', -5, 1, 'u2', 'protección civil'],
    ['o1', 'estimaciones', 'Estimación 01', 'xlsx', 'Aprobado', null, 1, 'u3', 'estimación'],
    ['o1', 'estimaciones', 'Estimación 02', 'xlsx', 'Aprobado', null, 1, 'u3', 'estimación'],
    ['o1', 'estimaciones', 'Estimación 03 y números generadores', 'xlsx', 'En revisión', null, 2, 'u3', 'estimación,generadores'],
    ['o1', 'bitacora', 'Bitácora de obra — folios 1 a 48', 'pdf', 'Aprobado', null, 4, 'u3', 'bitácora'],
    ['o1', 'calidad', 'Pruebas de resistencia a compresión — losa', 'pdf', 'Aprobado', null, 1, 'u3', 'concreto,laboratorio'],
    ['o1', 'calidad', 'Certificado de calidad acero estructural', 'pdf', 'Aprobado', null, 1, 'u3', 'acero,proveedor'],
    ['o1', 'seguridad', 'Programa de seguridad y salud en obra', 'docx', 'Aprobado', 60, 2, 'u2', 'seguridad'],
    ['o1', 'seguridad', 'Constancias DC-3 trabajos en altura', 'pdf', 'Aprobado', 18, 1, 'u3', 'capacitación,alturas'],
    ['o1', 'informes', 'Minuta de reunión semanal', 'docx', 'Borrador', null, 1, 'u3', 'minuta'],
    ['o1', 'informes', 'Informe mensual de avance', 'pdf', 'En revisión', null, 1, 'u3', 'informe'],
    ['o2', 'contrato', 'Contrato de obra pública a precios unitarios', 'pdf', 'Aprobado', null, 1, 'u2', 'contrato,obra pública'],
    ['o2', 'contrato', 'Fianza de vicios ocultos (formato)', 'docx', 'Borrador', null, 1, 'u2', 'fianza'],
    ['o2', 'proyecto', 'Proyecto geométrico y perfiles', 'dwg', 'Aprobado', null, 2, 'u3', 'planos,vialidad'],
    ['o2', 'permisos', 'Permiso de cierre de vialidad', 'pdf', 'Aprobado', 6, 1, 'u3', 'vialidad,municipal'],
    ['o2', 'estimaciones', 'Estimación 01 — terracerías', 'xlsx', 'En revisión', null, 1, 'u3', 'estimación'],
    ['o2', 'bitacora', 'Notas de bitácora — semana 6', 'pdf', 'Aprobado', null, 1, 'u3', 'bitácora'],
    ['o2', 'calidad', 'Pruebas de compactación de base', 'pdf', 'Aprobado', null, 1, 'u3', 'compactación,laboratorio'],
    ['o2', 'seguridad', 'Plan de señalización y desvíos', 'pdf', 'Aprobado', null, 1, 'u3', 'señalización'],
    ['o3', 'contrato', 'Propuesta técnica y económica', 'pdf', 'En revisión', null, 2, 'u2', 'propuesta'],
    ['o3', 'proyecto', 'Proyecto arquitectónico ejecutivo', 'dwg', 'En revisión', null, 1, 'u4', 'arquitectónico,planos'],
    ['o3', 'proyecto', 'Estudio de mecánica de suelos', 'pdf', 'Aprobado', null, 1, 'u4', 'geotecnia'],
    ['o3', 'permisos', 'Solicitud de licencia de construcción', 'pdf', 'Borrador', null, 1, 'u4', 'licencia'],
    ['o3', 'permisos', 'Manifestación de impacto ambiental', 'pdf', 'En revisión', 90, 1, 'u4', 'ambiental'],
    ['o4', 'contrato', 'Contrato y convenio modificatorio', 'pdf', 'Aprobado', null, 2, 'u2', 'contrato'],
    ['o4', 'contrato', 'Fianza de vicios ocultos', 'pdf', 'Aprobado', 300, 1, 'u2', 'fianza,garantía'],
    ['o4', 'cierre', 'Acta de entrega-recepción', 'pdf', 'Aprobado', null, 1, 'u2', 'entrega'],
    ['o4', 'cierre', 'Planos as-built', 'dwg', 'Aprobado', null, 1, 'u3', 'as-built,planos'],
    ['o4', 'cierre', 'Finiquito de obra', 'pdf', 'Aprobado', null, 1, 'u2', 'finiquito'],
  ];
  const tamanos = { pdf: 1850000, dwg: 4200000, xlsx: 310000, docx: 180000 };
  const documentos = semillaDocs.map((d, i) => {
    const [obraId, carpeta, nombre, ext, estado, vence, nv, usuarioId, etiquetas] = d;
    const creado = momentoDesdeHoy(-(60 - i), 9 + (i % 8));
    const versiones = [];
    for (let v = 1; v <= nv; v++) {
      versiones.push({
        v, fileId: null, fecha: momentoDesdeHoy(-(60 - i) + (v - 1) * 7, 11), usuarioId,
        nombreArchivo: nombre.replace(/[^\wáéíóúñÁÉÍÓÚÑ -]/g, '').replace(/\s+/g, '_') + (nv > 1 ? `_v${v}` : '') + '.' + ext,
        tamano: Math.round(tamanos[ext] * (0.7 + (i % 5) / 10)), tipo: '',
        nota: v === 1 ? 'Versión inicial' : 'Correcciones solicitadas en revisión',
      });
    }
    return {
      id: 'd' + (i + 1), obraId, carpeta, nombre, ext, estado,
      vence: vence === null ? '' : diasDesdeHoy(vence),
      etiquetas: etiquetas.split(','), descripcion: '',
      versiones, creado, usuarioId,
    };
  });

  const presupuestos = [
    { id: 'p1', obraId: 'o1', folio: 'PRE-001', concepto: 'Presupuesto base contratado', monto: 18450000, estado: 'Aprobado', fecha: diasDesdeHoy(-140), usuarioId: 'u2', fileId: null, nombreArchivo: 'Presupuesto_base.xlsx', notas: 'Incluye catálogo de 214 conceptos.' },
    { id: 'p2', obraId: 'o1', folio: 'PRE-002', concepto: 'Adicional: cimentación de maquinaria', monto: 865400, estado: 'Enviado al cliente', fecha: diasDesdeHoy(-9), usuarioId: 'u3', fileId: null, nombreArchivo: 'Adicional_cimentacion.xlsx', notas: 'Solicitado en nota de bitácora 41.' },
    { id: 'p3', obraId: 'o1', folio: 'PRE-003', concepto: 'Extraordinario: cambio de lámina a aislada', monto: 412000, estado: 'Rechazado', fecha: diasDesdeHoy(-30), usuarioId: 'u3', fileId: null, nombreArchivo: 'Extraordinario_lamina.xlsx', notas: 'El cliente mantiene la especificación original.' },
    { id: 'p4', obraId: 'o2', folio: 'PRE-001', concepto: 'Presupuesto contratado (catálogo licitación)', monto: 6230000, estado: 'Aprobado', fecha: diasDesdeHoy(-60), usuarioId: 'u2', fileId: null, nombreArchivo: 'Catalogo_licitacion.xlsx', notas: '' },
    { id: 'p5', obraId: 'o2', folio: 'PRE-002', concepto: 'Volúmenes adicionales de drenaje', monto: 318750, estado: 'Borrador', fecha: diasDesdeHoy(-2), usuarioId: 'u3', fileId: null, nombreArchivo: 'Volumenes_adicionales.xlsx', notas: 'Pendiente validar con supervisión.' },
    { id: 'p6', obraId: 'o3', folio: 'PRE-001', concepto: 'Presupuesto paramétrico preliminar', monto: 41800000, estado: 'Enviado al cliente', fecha: diasDesdeHoy(-15), usuarioId: 'u2', fileId: null, nombreArchivo: 'Parametrico_TorreSur.xlsx', notas: 'Precio por m² construido.' },
  ];

  // Las fotos de ejemplo no son imágenes reales: se dibujan como ilustración
  // con el texto de lo que mostrarían (ver fotoEjemplo() en app.js).
  const reportes = [
    { id: 'r1', obraId: 'o1', titulo: 'Reporte fotográfico semana 18', fecha: diasDesdeHoy(-4), periodo: 'Semana 18', usuarioId: 'u3',
      descripcion: 'Avance en montaje de estructura metálica y colado de losa de oficinas.',
      fotos: [
        { ejemplo: 'Montaje de marcos eje 5-8', tono: 1, pie: 'Montaje de marcos rígidos en ejes 5 al 8 con grúa de 50 t.' },
        { ejemplo: 'Colado losa mezzanine', tono: 2, pie: 'Colado de losa de mezzanine, concreto f\'c=250 kg/cm².' },
        { ejemplo: 'Toma de muestras', tono: 3, pie: 'Toma de cilindros para prueba de resistencia por laboratorio.' },
        { ejemplo: 'Anclas de columnas', tono: 4, pie: 'Revisión de anclas y nivelación de placas base.' },
        { ejemplo: 'Vista general', tono: 5, pie: 'Vista general del avance desde acceso poniente.' },
        { ejemplo: 'Almacén de materiales', tono: 6, pie: 'Resguardo de lámina y material de cubierta.' },
      ] },
    { id: 'r2', obraId: 'o1', titulo: 'Reporte fotográfico semana 17', fecha: diasDesdeHoy(-11), periodo: 'Semana 17', usuarioId: 'u3',
      descripcion: 'Terminación de cimentación y preparación para montaje.',
      fotos: [
        { ejemplo: 'Zapatas eje 9-12', tono: 2, pie: 'Zapatas aisladas coladas en ejes 9 al 12.' },
        { ejemplo: 'Relleno compactado', tono: 5, pie: 'Relleno compactado al 95% Proctor en área de piso.' },
        { ejemplo: 'Recepción de estructura', tono: 1, pie: 'Llegada de primer embarque de estructura metálica.' },
        { ejemplo: 'Plática de seguridad', tono: 3, pie: 'Plática de 5 minutos con cuadrilla de montaje.' },
      ] },
    { id: 'r3', obraId: 'o2', titulo: 'Reporte fotográfico semana 6', fecha: diasDesdeHoy(-3), periodo: 'Semana 6', usuarioId: 'u3',
      descripcion: 'Excavación para drenaje pluvial y colocación de tubería.',
      fotos: [
        { ejemplo: 'Excavación zanja', tono: 4, pie: 'Excavación de zanja para tubería de 45 cm.' },
        { ejemplo: 'Colocación de tubería', tono: 2, pie: 'Colocación de tubería de PEAD sobre plantilla.' },
        { ejemplo: 'Señalización', tono: 6, pie: 'Señalización y desvío vehicular en cruce con calle Juárez.' },
        { ejemplo: 'Pozo de visita', tono: 1, pie: 'Construcción de pozo de visita PV-03.' },
      ] },
  ];

  const actividad = [
    [-0.2, 'u3', 'subió el documento', 'o1', 'Informe mensual de avance'],
    [-1, 'u2', 'aprobó el documento', 'o1', 'Estimación 02'],
    [-2, 'u3', 'creó el presupuesto', 'o2', 'PRE-002 · Volúmenes adicionales de drenaje'],
    [-3, 'u3', 'publicó el reporte fotográfico', 'o2', 'Reporte fotográfico semana 6'],
    [-4, 'u3', 'publicó el reporte fotográfico', 'o1', 'Reporte fotográfico semana 18'],
    [-5, 'u4', 'subió una nueva versión de', 'o3', 'Propuesta técnica y económica (v2)'],
    [-7, 'u2', 'creó la obra', 'o3', 'CH-2026-003 · Edificio de oficinas Torre Sur'],
    [-9, 'u3', 'envió al cliente el presupuesto', 'o1', 'PRE-002 · Adicional: cimentación de maquinaria'],
    [-12, 'u1', 'asignó a Ana Torres a la obra', 'o3', ''],
  ].map((a, i) => ({ id: 'a' + (i + 1), fecha: momentoDesdeHoy(Math.floor(a[0]), 9 + i), usuarioId: a[1], accion: a[2], obraId: a[3], detalle: a[4] }));

  return { version: 1, usuarios, obras, documentos, presupuestos, reportes, actividad };
}

// ── Almacén principal (localStorage) ───────────────────────────────
const Store = {
  CLAVE: 'chc_demo_datos_v1',
  CLAVE_SESION: 'chc_demo_sesion',
  s: null,

  cargar() {
    try {
      const crudo = localStorage.getItem(this.CLAVE);
      this.s = crudo ? JSON.parse(crudo) : null;
    } catch (e) { this.s = null; }
    if (!this.s || this.s.version !== 1) {
      this.s = datosIniciales();
      this.guardar();
    }
  },
  guardar() {
    try { localStorage.setItem(this.CLAVE, JSON.stringify(this.s)); }
    catch (e) { console.warn('No se pudo guardar en este navegador', e); }
  },
  async restablecer() {
    this.s = datosIniciales();
    this.guardar();
    await Archivos.vaciar();
  },

  sesion() {
    try { return localStorage.getItem(this.CLAVE_SESION); } catch (e) { return this._sesionMemoria || null; }
  },
  iniciarSesion(id) {
    this._sesionMemoria = id;
    try { localStorage.setItem(this.CLAVE_SESION, id); } catch (e) { /* sólo en memoria */ }
  },
  cerrarSesion() {
    this._sesionMemoria = null;
    try { localStorage.removeItem(this.CLAVE_SESION); } catch (e) { /* nada */ }
  },
};

// ── Archivos subidos (IndexedDB) ────────────────────────────────────
// Si el navegador no permite IndexedDB (modo privado en algunos casos)
// los archivos se quedan en memoria mientras la pestaña esté abierta.
const Archivos = {
  db: null,
  memoria: new Map(),
  _abriendo: null,

  abrir() {
    if (this._abriendo) return this._abriendo;
    this._abriendo = new Promise((resolver) => {
      try {
        const peticion = indexedDB.open('chc_demo_archivos', 1);
        peticion.onupgradeneeded = () => peticion.result.createObjectStore('archivos');
        peticion.onsuccess = () => { this.db = peticion.result; resolver(this.db); };
        peticion.onerror = () => resolver(null);
      } catch (e) { resolver(null); }
    });
    return this._abriendo;
  },
  async guardar(id, blob) {
    const db = await this.abrir();
    if (!db) { this.memoria.set(id, blob); return; }
    await new Promise((ok, mal) => {
      const tx = db.transaction('archivos', 'readwrite');
      tx.objectStore('archivos').put(blob, id);
      tx.oncomplete = ok; tx.onerror = () => mal(tx.error);
    });
  },
  async obtener(id) {
    if (!id) return null;
    if (this.memoria.has(id)) return this.memoria.get(id);
    const db = await this.abrir();
    if (!db) return null;
    return new Promise((ok) => {
      const peticion = db.transaction('archivos').objectStore('archivos').get(id);
      peticion.onsuccess = () => ok(peticion.result || null);
      peticion.onerror = () => ok(null);
    });
  },
  async borrar(id) {
    if (!id) return;
    this.memoria.delete(id);
    const db = await this.abrir();
    if (!db) return;
    db.transaction('archivos', 'readwrite').objectStore('archivos').delete(id);
  },
  async vaciar() {
    this.memoria.clear();
    const db = await this.abrir();
    if (!db) return;
    await new Promise((ok) => {
      const tx = db.transaction('archivos', 'readwrite');
      tx.objectStore('archivos').clear();
      tx.oncomplete = ok; tx.onerror = ok;
    });
  },
};
