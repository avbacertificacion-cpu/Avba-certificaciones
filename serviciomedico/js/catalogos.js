/* ==========================================================================
   Catálogos y reglas clínicas del demo.
   Aquí viven las listas fijas (motivos de consulta, checklist, tipos de
   trabajo) y las reglas que clasifican presión arterial y alto riesgo.
   Los límites son ajustables desde la pantalla de Alto riesgo.
   ========================================================================== */

// Dictamen para trabajos de alto riesgo (mmHg)
const LIMITES_PRESION = {
  aptoSistolica: 140,    // por debajo de 140/90 → Apto
  aptoDiastolica: 90,
  noAptoSistolica: 160,  // desde 160/100 → No apto
  noAptoDiastolica: 100, // en medio → Revalorar
};

const DICTAMENES = {
  apto:      { nombre: 'Apto',      badge: 'badge-verde', icono: '✓' },
  revalorar: { nombre: 'Revalorar', badge: 'badge-ambar', icono: '!' },
  no_apto:   { nombre: 'No apto',   badge: 'badge-rojo',  icono: '✕' },
};

function dictaminar(sistolica, diastolica, limites = LIMITES_PRESION) {
  if (sistolica >= limites.noAptoSistolica || diastolica >= limites.noAptoDiastolica) return 'no_apto';
  if (sistolica >= limites.aptoSistolica || diastolica >= limites.aptoDiastolica) return 'revalorar';
  return 'apto';
}

// Clasificación general de la presión (para consultas)
function clasificarPresion(sis, dia) {
  if (!sis || !dia) return null;
  if (sis >= 180 || dia >= 120) return { texto: 'Crisis hipertensiva', badge: 'badge-rojo' };
  if (sis >= 140 || dia >= 90)  return { texto: 'Elevada (hipertensión)', badge: 'badge-rojo' };
  if (sis >= 130 || dia >= 85)  return { texto: 'Normal alta', badge: 'badge-ambar' };
  if (sis < 90 || dia < 60)     return { texto: 'Baja', badge: 'badge-ambar' };
  return { texto: 'Normal', badge: 'badge-verde' };
}

function calcularIMC(peso, tallaCm) {
  if (!peso || !tallaCm) return null;
  return Math.round(peso / ((tallaCm / 100) ** 2) * 10) / 10;
}
function clasificarIMC(imc) {
  if (imc == null) return null;
  if (imc >= 35) return { texto: 'Obesidad grado II+', badge: 'badge-rojo' };
  if (imc >= 30) return { texto: 'Obesidad', badge: 'badge-rojo' };
  if (imc >= 25) return { texto: 'Sobrepeso', badge: 'badge-ambar' };
  if (imc < 18.5) return { texto: 'Bajo peso', badge: 'badge-ambar' };
  return { texto: 'Normal', badge: 'badge-verde' };
}

const MOTIVOS = [
  'Cefalea',
  'Dolor lumbar / muscular',
  'Herida o laceración',
  'Quemadura',
  'Cuerpo extraño en ojo',
  'Malestar gastrointestinal',
  'Infección respiratoria',
  'Control de presión arterial',
  'Mareo / lipotimia',
  'Reacción alérgica',
  'Control de glucosa',
  'Golpe o contusión',
  'Otro',
];

// Checklist digital de la consulta. Los obligatorios deben marcarse para guardar.
const CHECKLIST = [
  { clave: 'identidad',   texto: 'Identidad del trabajador confirmada', obligatorio: true },
  { clave: 'alergias',    texto: 'Alergias verificadas antes de medicar', obligatorio: true },
  { clave: 'signos',      texto: 'Signos vitales tomados', obligatorio: true },
  { clave: 'interrogatorio', texto: 'Interrogatorio del padecimiento actual', obligatorio: true },
  { clave: 'exploracion', texto: 'Exploración física de la zona afectada' },
  { clave: 'epp',         texto: 'Revisión de equipo de protección personal (si aplica)' },
  { clave: 'curacion',    texto: 'Curación / limpieza realizada' },
  { clave: 'indicaciones',texto: 'Indicaciones explicadas al trabajador', obligatorio: true },
  { clave: 'supervisor',  texto: 'Supervisor notificado' },
];

const DESTINOS = {
  regresa:  { nombre: 'Regresa a su puesto',      badge: 'badge-verde' },
  observacion: { nombre: 'Observación en servicio médico', badge: 'badge-azul' },
  reposo:   { nombre: 'Enviado a casa / reposo',  badge: 'badge-ambar' },
  traslado: { nombre: 'Traslado a hospital',      badge: 'badge-rojo' },
};

const ESTADOS_AMBULANCIA = {
  disponible:    { nombre: 'Disponible',    badge: 'badge-verde' },
  en_servicio:   { nombre: 'En servicio',   badge: 'badge-azul' },
  mantenimiento: { nombre: 'Mantenimiento', badge: 'badge-ambar' },
  fuera:         { nombre: 'Fuera de servicio', badge: 'badge-rojo' },
};

const AREAS = ['Producción', 'Mantenimiento', 'Almacén', 'Calidad', 'Seguridad', 'Administración'];
const TIPOS_SANGRE = ['O+', 'O-', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-'];
const ANTECEDENTES = {
  diabetes: 'Diabetes',
  hipertension: 'Hipertensión',
  cardiopatia: 'Cardiopatía',
  asma: 'Asma',
  tabaquismo: 'Tabaquismo',
};

// Todos los turnos con su clave, para selects y filtros
function listaTurnos() {
  return Object.entries(TURNOS).flatMap(([esq, lista]) => lista.map(t => ({ ...t, esquema: esq })));
}
function turnoPorClave(esquema, clave) {
  if (!esquema) { const t = listaTurnos().find(x => x.clave === clave); if (t) return t; }
  const t = (TURNOS[esquema] || []).find(x => x.clave === clave) || { clave, nombre: clave };
  return { ...t, esquema };
}
