/* ══════════════════════════════════════════════════════════════
   AVBA — DEMO de control de accesos: datos ficticios y reglas compartidas

   Lo usan las dos pantallas de la beta:
     · demo/control-accesos.html  panel del administrador de accesos
     · demo/caseta.html           pantalla del guardia en campo

   Las personas, empresas, incidencias y vales se generan en el navegador con
   una semilla del día (las dos pantallas ven los mismos). Lo que se registra
   en la demo (entradas, salidas, vetos, permisos) se guarda sólo en este
   navegador (localStorage) y se comparte entre las dos pantallas abiertas en
   pestañas: el guardia registra una entrada y el panel la muestra al instante.
   Nada se envía al servidor.
   ══════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var MIN = 60000, HORA = 3600000, DIA = 86400000;
  function ahora() { return new Date(); }
  function dos(n) { return (n < 10 ? '0' : '') + n; }
  function esc(t) { return String(t == null ? '' : t).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function num(n) { return Number(n || 0).toLocaleString('es-MX'); }
  function ico(id, cls) { return '<svg class="i' + (cls ? ' ' + cls : '') + '"><use href="#i-' + id + '"/></svg>'; }
  function inicioDia(d) { var x = new Date(d); x.setHours(0, 0, 0, 0); return x; }
  function sumarDias(d, n) { var x = new Date(d); x.setDate(x.getDate() + n); return x; }
  function conHora(d, h, m) { var x = new Date(d); x.setHours(h, m || 0, 0, 0); return x; }
  function hhmm(d) { return dos(d.getHours()) + ':' + dos(d.getMinutes()); }
  function claveDia(d) { return d.getFullYear() + '-' + dos(d.getMonth() + 1) + '-' + dos(d.getDate()); }
  function fechaCorta(d) { return d.toLocaleDateString('es-MX', { weekday: 'short', day: 'numeric', month: 'short' }); }
  function fecha(d) { return d.toLocaleDateString('es-MX', { day: 'numeric', month: 'short', year: 'numeric' }); }
  function cuando(d) { return claveDia(d) === claveDia(ahora()) ? 'hoy ' + hhmm(d) : (claveDia(d) === claveDia(sumarDias(ahora(), -1)) ? 'ayer ' + hhmm(d) : fechaCorta(d) + ' ' + hhmm(d)); }
  function dur(ms) { var m = Math.max(0, Math.round(ms / MIN)), h = Math.floor(m / 60); return h + ' h ' + dos(m % 60) + ' min'; }
  function fmtH(h) { return (Math.round(h * 10) / 10).toLocaleString('es-MX') + ' h'; }
  function iniciales(n) { var p = n.split(' '); return (p[0][0] + (p[1] ? p[1][0] : '')).toUpperCase(); }

  /* ══ Datos ficticios (con semilla: no cambian al recargar el mismo día) ══ */
  var hoy0 = inicioDia(ahora());
  var semilla = hoy0.getFullYear() * 1000 + hoy0.getMonth() * 40 + hoy0.getDate();
  var rnd = (function (a) { return function () { a |= 0; a = a + 0x6D2B79F5 | 0; var t = Math.imul(a ^ a >>> 15, 1 | a); t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t; return ((t ^ t >>> 14) >>> 0) / 4294967296; }; })(semilla);
  function entre(a, b) { return a + Math.floor(rnd() * (b - a + 1)); }
  function elegir(arr) { return arr[Math.floor(rnd() * arr.length)]; }
  function prob(p) { return rnd() < p; }
  function mezclar(arr) { var a = arr.slice(); for (var i = a.length - 1; i > 0; i--) { var j = Math.floor(rnd() * (i + 1)), t = a[i]; a[i] = a[j]; a[j] = t; } return a; }

  var EMPRESAS = [
    { id: 'MIG', nombre: 'Montajes Industriales del Golfo', corto: 'Montajes del Golfo', repse: 'Vigente', n: 40 },
    { id: 'EBN', nombre: 'Eléctrica Bajío Norte', corto: 'Eléctrica Bajío', repse: 'Vigente', n: 28 },
    { id: 'PTH', nombre: 'Pailería Técnica Huasteca', corto: 'Pailería Huasteca', repse: 'Vigente', n: 26 },
    { id: 'CMO', nombre: 'Constructora Moctezuma', corto: 'Const. Moctezuma', repse: 'Vigente', n: 24 },
    { id: 'AMS', nombre: 'Andamios y Maniobras Sierra', corto: 'Andamios Sierra', repse: 'Por vencer', n: 22 },
    { id: 'LIA', nombre: 'Limpieza Industrial Alfa', corto: 'Limpieza Alfa', repse: 'Vencido', n: 18 },
    { id: 'AVB', nombre: 'AVBA Inspecciones', corto: 'AVBA', repse: 'Vigente', n: 8 }
  ];
  var EMP = {}; EMPRESAS.forEach(function (e) { EMP[e.id] = e; });
  var PUESTOS = {
    MIG: ['Mecánico montador', 'Ayudante general', 'Operador de grúa', 'Maniobrista', 'Supervisor mecánico'],
    EBN: ['Electricista', 'Ayudante de electricista', 'Técnico instrumentista', 'Supervisor eléctrico'],
    PTH: ['Soldador', 'Pailero', 'Ayudante general', 'Supervisor de pailería'],
    CMO: ['Albañil', 'Oficial fierrero', 'Ayudante general', 'Operador de retroexcavadora', 'Residente de obra'],
    AMS: ['Andamiero', 'Maniobrista', 'Ayudante general', 'Supervisor de andamios'],
    LIA: ['Auxiliar de limpieza', 'Operador de hidrolavadora', 'Supervisora de limpieza'],
    AVB: ['Inspector de grúas', 'Inspector de accesorios', 'Supervisor de seguridad']
  };
  var CURSOS = {
    altura: 'Trabajos en altura (NOM-009)', soldadura: 'Soldadura y corte (NOM-027)', electrica: 'Seguridad eléctrica (NOM-029)',
    izaje: 'Manejo de materiales e izaje (NOM-006)', confinado: 'Espacios confinados (NOM-033)', loto: 'Bloqueo de energías (NOM-004)'
  };
  function cursosDe(puesto) {
    if (/Soldador|Pailero/.test(puesto)) return ['soldadura', 'altura'];
    if (/Electricista|instrumentista|eléctrico/.test(puesto)) return ['electrica', 'loto'];
    if (/Andamiero|andamios/.test(puesto)) return ['altura'];
    if (/Maniobrista|grúa|Inspector/.test(puesto)) return ['izaje', 'altura'];
    if (/hidrolavadora/.test(puesto)) return ['confinado'];
    if (/Supervisor|Residente/.test(puesto)) return ['altura'];
    return [];
  }
  var NOM_H = ['José', 'Juan', 'Luis', 'Carlos', 'Miguel', 'Jorge', 'Francisco', 'Alejandro', 'Roberto', 'Fernando', 'Ricardo', 'Eduardo', 'Javier', 'Raúl', 'Arturo', 'Héctor', 'Manuel', 'Daniel', 'Sergio', 'Omar', 'Iván', 'Rubén', 'Marco Antonio', 'Jesús', 'Pedro', 'Alberto', 'Gerardo', 'Víctor', 'Hugo', 'Ramón', 'Ernesto', 'Ángel', 'Mario', 'Andrés', 'Felipe', 'Gustavo', 'Rodrigo', 'Óscar', 'Saúl', 'Adrián'];
  var NOM_M = ['María', 'Guadalupe', 'Ana', 'Laura', 'Patricia', 'Verónica', 'Rosa', 'Leticia', 'Claudia', 'Gabriela', 'Alejandra', 'Mónica', 'Adriana', 'Diana', 'Sandra', 'Elizabeth', 'Karla', 'Brenda', 'Norma', 'Silvia'];
  var APE = ['Hernández', 'García', 'Martínez', 'López', 'González', 'Pérez', 'Rodríguez', 'Sánchez', 'Ramírez', 'Cruz', 'Flores', 'Gómez', 'Morales', 'Vázquez', 'Reyes', 'Jiménez', 'Torres', 'Díaz', 'Gutiérrez', 'Ruiz', 'Mendoza', 'Aguilar', 'Ortiz', 'Moreno', 'Castillo', 'Romero', 'Álvarez', 'Méndez', 'Chávez', 'Rivera', 'Juárez', 'Ramos', 'Domínguez', 'Herrera', 'Medina', 'Castro', 'Vargas', 'Guzmán', 'Velázquez', 'Salazar', 'Rojas', 'Contreras', 'Luna', 'Ortega', 'Delgado', 'Estrada', 'Cervantes', 'Zamora', 'Montes', 'Ibarra'];
  var HERR = ['Esmeriladora angular 4½"', 'Taladro rotomartillo', 'Juego de llaves combinadas', 'Multímetro digital', 'Pinza amperimétrica', 'Llave de impacto', 'Máquina de soldar portátil', 'Polipasto de cadena 1 t', 'Nivel láser', 'Juego de dados ½"', 'Detector de voltaje', 'Cortadora de disco', 'Torquímetro'];
  var EQUIPO_PLANTA = ['Radio portátil', 'Detector de 4 gases', 'Arnés de planta', 'Candado de bloqueo', 'Lámpara antiexplosión'];
  var MATERIAL = ['Retazos de tubería (chatarra)', 'Cable de cobre sobrante', 'Andamio de la contratista (12 piezas)', 'Bomba para reparación en taller externo'];
  var CASETAS = ['Caseta 1 (principal)', 'Caseta 2 (contratistas)'];
  var TIPO_ID = ['INE', 'INE', 'INE', 'INE', 'INE', 'INE', 'INE', 'Licencia de conducir', 'Licencia de conducir', 'Gafete de contratista', 'Pasaporte'];

  var TIPOS_INC = {
    EPP: { n: 'Incumplimiento de EPP', g: 'Baja', d: ['Sin lentes de seguridad en área de proceso.', 'Sin barbiquejo trabajando en altura.', 'Guantes inadecuados para la tarea.'] },
    ACTO: { n: 'Acto inseguro', g: 'Media', d: ['Cruzó el área acordonada de una maniobra.', 'Retiró una guarda de protección.', 'Subió a andamio sin tarjeta de liberación.'] },
    CASI: { n: 'Casi accidente', g: 'Media', d: ['Cayó una herramienta desde el nivel 3 del andamio; sin lesionados.', 'Resbalón por derrame de aceite; sin lesión.'] },
    ACC: { n: 'Accidente con lesión', g: 'Alta', d: ['Golpe en la mano con llave de impacto; atendido en servicio médico.', 'Esguince de tobillo al bajar de la plataforma.', 'Quemadura leve en antebrazo durante corte.'] },
    ALC: { n: 'Aliento alcohólico / alcoholímetro positivo', g: 'Alta', d: ['Alcoholímetro en caseta: 0.32 g/L.', 'Aliento alcohólico detectado por supervisión; prueba positiva.'] },
    DRO: { n: 'Sospecha de consumo de drogas', g: 'Alta', d: ['Conducta errática reportada por supervisión; se negó a la prueba.'] },
    RIN: { n: 'Riña o agresión', g: 'Alta', d: ['Discusión con agresión física en el comedor.'] },
    ROB: { n: 'Sustracción de material o herramienta', g: 'Alta', d: ['Intentó sacar cable de cobre sin vale de salida.', 'No presentó a la salida herramienta registrada a la entrada.'] },
    CEL: { n: 'Celular o cámara sin autorización', g: 'Baja', d: ['Tomó fotografías en área restringida.', 'Ingresó celular sin autorización (no lo resguardó).'] },
    SUP: { n: 'Suplantación o documento falso', g: 'Alta', d: ['Presentó la identificación de otra persona.'] },
    VET: { n: 'Intento de ingreso estando vetado', g: 'Alta', d: ['Intentó ingresar por Caseta 2 estando vetado.'] }
  };
  var CORTO_INC = { EPP: 'Sin EPP', ACTO: 'Acto inseguro', CASI: 'Casi accidente', ACC: 'Accidente', ALC: 'Alcohol', DRO: 'Drogas', RIN: 'Riña', ROB: 'Sustracción', CEL: 'Celular / cámara', SUP: 'Suplantación', VET: 'Intento estando vetado' };
  var ACCIONES = ['Plática de seguridad', 'Amonestación por escrito', 'Suspensión 3 días', 'Suspensión 7 días', 'Veto 30 días', 'Veto 90 días', 'Veto permanente en esta planta', 'Veto en todas las plantas del cliente'];
  var TIPO_VALE = { herr: 'Ingreso de herramienta', equipo: 'Préstamo de equipo de planta', mat: 'Salida de material' };

  var PERSONAS = [], P = {}, INCIDENCIAS = [], VALES = [], INTENTOS = [], MOVS = [];

  (function generar() {
    var N = ahora(), hoy = inicioDia(N), n = 0, nInc = 0, nVale = 180;
    EMPRESAS.forEach(function (e) {
      for (var i = 0; i < e.n; i++) {
        var mujer = prob(e.id === 'LIA' ? 0.55 : (e.id === 'AVB' ? 0.3 : 0.08));
        var lista = PUESTOS[e.id], sup = i < (e.n > 20 ? 2 : 1);
        var puesto = sup ? lista[lista.length - 1] : elegir(lista.slice(0, -1));
        var r = rnd(), pat;
        if (e.id === 'AVB') pat = { on: 5, off: 2, h: 10 };
        else if (r < 0.58) pat = { on: 4, off: 3, h: 12 };
        else if (r < 0.86) pat = { on: 5, off: 2, h: 10 };
        else if (r < 0.94) pat = { on: 5, off: 2, h: 11 };
        else if (r < 0.98) pat = { on: 6, off: 1, h: 12 };
        else pat = { on: 30, off: 0, h: 11 };
        var herrAut = !sup && e.id !== 'LIA' && prob(0.55);
        var p = {
          id: 'P' + String(++n).padStart(3, '0'),
          nombre: (mujer ? elegir(NOM_M) : elegir(NOM_H)) + ' ' + elegir(APE) + ' ' + elegir(APE),
          emp: e.id, puesto: puesto, sup: sup,
          turno: e.id === 'AVB' ? 'D' : (prob(0.65) ? 'D' : 'N'),
          pat: pat, fase: entre(0, pat.on + pat.off - 1),
          idTipo: elegir(TIPO_ID), idNum: String(entre(1000, 9999)),
          aut: { cel: sup || e.id === 'AVB' || prob(0.12), tab: (sup || e.id === 'AVB') ? prob(0.45) : prob(0.03), herr: herrAut, pc: (sup || e.id === 'AVB') && prob(0.7), auto: sup ? prob(0.6) : prob(0.04), cam: e.id === 'AVB' && prob(0.6) },
          herr: herrAut ? mezclar(HERR).slice(0, entre(2, 4)) : [],
          placas: '', estado: 'activo', veto: null, ints: [],
          alta: sumarDias(hoy, -entre(20, 400)),
          docs: {
            induccion: sumarDias(hoy, prob(0.05) ? -entre(1, 20) : entre(5, 340)),
            medico: sumarDias(hoy, prob(0.04) ? -entre(1, 15) : entre(3, 300)),
            imss: (e.id === 'LIA' ? prob(0.3) : prob(0.03)) ? 'pendiente' : 'vigente',
            dc3: cursosDe(puesto).map(function (c) { return { curso: CURSOS[c], vence: sumarDias(hoy, prob(0.05) ? -entre(1, 30) : entre(5, 360)), avba: prob(0.6) }; })
          }
        };
        if (p.aut.auto) p.placas = String.fromCharCode(65 + entre(0, 25), 65 + entre(0, 25), 65 + entre(0, 25)) + '-' + entre(100, 999) + '-' + String.fromCharCode(65 + entre(0, 25));
        // Turnos de los últimos 16 días
        var ciclo = p.pat.on + p.pat.off;
        for (var k = 16; k >= 0; k--) {
          if ((p.fase + 1000 - k) % ciclo >= p.pat.on) continue;
          var s = sumarDias(hoy, -k);
          var base = p.turno === 'D' ? conHora(s, 6, 0) : conHora(s, 18, 0);
          var ent = new Date(+base + entre(-25, 40) * MIN + entre(0, 59) * 1000 + (prob(0.04) ? entre(60, 150) * MIN : 0));
          var mins = p.pat.h * 60 + entre(-25, 15) + (prob(0.05) ? entre(60, 180) : 0);
          var sal = new Date(+ent + mins * MIN + entre(0, 59) * 1000);
          if (ent > N) continue;
          p.ints.push({ ent: ent, sal: sal > N ? null : sal, cE: CASETAS[prob(0.7) ? 0 : 1], cS: CASETAS[prob(0.7) ? 0 : 1], obs: '' });
        }
        PERSONAS.push(p); P[p.id] = p;
      }
    });

    function nuevaInc(p, tipo, f, accion, extra) {
      var t = TIPOS_INC[tipo];
      var inc = { folio: 'INC-' + String(++nInc + 400).padStart(4, '0'), pid: p.id, tipo: tipo, grav: t.g, fecha: f, accion: accion,
        desc: elegir(t.d), estado: (N - f) < 10 * DIA && prob(0.5) ? 'En seguimiento' : 'Cerrada', por: 'Admin. de accesos' };
      if (extra) for (var k in extra) inc[k] = extra[k];
      INCIDENCIAS.push(inc); return inc;
    }
    // Vetos (con su incidencia)
    var candidatos = mezclar(PERSONAS.filter(function (p) { return !p.sup && p.emp !== 'AVB'; }));
    var motivos = [['ALC', 'Veto 90 días'], ['ROB', 'Veto permanente en esta planta'], ['RIN', 'Veto en todas las plantas del cliente'], ['ALC', 'Veto 30 días'], ['SUP', 'Veto permanente en esta planta'], ['DRO', 'Veto 90 días']];
    motivos.forEach(function (m, i) {
      var p = candidatos[i], f = conHora(sumarDias(hoy, -entre(3, 40)), entre(6, 20), entre(0, 59));
      var inc = nuevaInc(p, m[0], f, m[1]);
      var dias = /30/.test(m[1]) ? 30 : (/90/.test(m[1]) ? 90 : 0);
      p.estado = 'vetado';
      p.veto = { desde: f, hasta: dias ? sumarDias(f, dias) : null, motivo: TIPOS_INC[m[0]].n, alcance: /todas/.test(m[1]) ? 'Todas las plantas del cliente' : 'Esta planta', folio: inc.folio };
      p.ints = p.ints.filter(function (it) { return it.ent < f; });
      if (p.ints.length && !p.ints[p.ints.length - 1].sal) p.ints[p.ints.length - 1].sal = new Date(+f + 20 * MIN);
    });
    // Dos intentos de ingreso estando vetado (últimas horas)
    [0, 1].forEach(function (i) {
      var p = candidatos[i], t = new Date(+N - entre(1, 30) * HORA - entre(0, 59) * MIN);
      INTENTOS.push({ pid: p.id, t: t, caseta: CASETAS[1] });
      nuevaInc(p, 'VET', t, 'Se negó el acceso', { estado: 'En seguimiento' });
    });
    // Incidencias de los últimos 6 meses
    var pesos = ['EPP', 'EPP', 'EPP', 'EPP', 'ACTO', 'ACTO', 'ACTO', 'CASI', 'CASI', 'ACC', 'ACC', 'CEL', 'CEL', 'ALC', 'ROB'];
    var activos = PERSONAS.filter(function (p) { return p.estado === 'activo'; });
    for (var i = 0; i < 62; i++) {
      var tipo = elegir(pesos), p = elegir(activos), f = conHora(sumarDias(hoy, -entre(0, 179)), entre(6, 22), entre(0, 59));
      var acc = { EPP: 'Plática de seguridad', ACTO: 'Amonestación por escrito', CASI: 'Plática de seguridad', ACC: 'Plática de seguridad', CEL: 'Amonestación por escrito', ALC: 'Suspensión 7 días', ROB: 'Suspensión 3 días' }[tipo];
      nuevaInc(p, tipo, f, acc, tipo === 'ACC' ? { incapacidad: entre(0, 6), st7: true } : null);
    }
    // Personas «doblando turno» y una por cumplir su tope
    var libres = mezclar(activos.filter(function (p) { var u = p.ints[p.ints.length - 1]; return !u || u.sal; }));
    [15.3, 13.4, 12.6, 11.6].forEach(function (h, i) {
      var p = libres[i], ent = new Date(+N - h * HORA - entre(0, 9) * MIN);
      p.ints = p.ints.filter(function (it) { return it.ent < new Date(+ent - 10 * HORA) && it.sal && it.sal < new Date(+ent - 8 * HORA); });
      p.ints.push({ ent: ent, sal: null, cE: CASETAS[0], cS: '', obs: '' });
    });
    // Vales
    function vale(p, tipo, items, abierto, cerrado, extra) {
      var v = { folio: 'V-' + String(++nVale).padStart(4, '0'), pid: p.id, tipo: tipo, items: items, abierto: abierto, cerrado: cerrado || null };
      if (extra) for (var k in extra) v[k] = extra[k];
      VALES.push(v); return v;
    }
    PERSONAS.forEach(function (p) {
      var u = p.ints[p.ints.length - 1];
      if (p.aut.herr && u && !u.sal && prob(0.7)) vale(p, 'herr', p.herr.slice(), u.ent, null);
      p.ints.slice(-6, -1).forEach(function (it) { if (p.aut.herr && it.sal && prob(0.15)) vale(p, 'herr', p.herr.slice(), it.ent, it.sal); });
    });
    mezclar(activos.filter(function (p) { return p.aut.herr; })).slice(0, 5).forEach(function (p) {
      var u = p.ints.filter(function (it) { return it.sal; }).slice(-entre(2, 4))[0];
      if (u) vale(p, 'herr', p.herr.slice(0, entre(1, p.herr.length)), u.ent, null, { nota: 'Salió sin presentar la herramienta' });
    });
    mezclar(activos).slice(0, 9).forEach(function (p, i) {
      var ab = new Date(+N - (i < 5 ? entre(4, 9) : entre(0, 2)) * DIA - entre(0, 600) * MIN);
      vale(p, 'equipo', [elegir(EQUIPO_PLANTA)], ab, i > 6 ? new Date(+ab + entre(2, 20) * HORA) : null);
    });
    mezclar(activos.filter(function (p) { return p.sup; })).slice(0, 7).forEach(function (p, i) {
      var ab = new Date(+N - entre(0, 6) * DIA - entre(0, 600) * MIN);
      vale(p, 'mat', [elegir(MATERIAL)], ab, i < 3 ? null : new Date(+ab + entre(1, 30) * HORA), { autoriza: i < 2 ? 'Pendiente de autorizar' : 'Autorizado por Mantenimiento' });
    });
  })();

  /* ══ Reglas (LFT reformada, DOF 1 de mayo de 2026) ══════════════════════
     Jornada ordinaria semanal y horas extra a pago doble por año (transitorios);
     sobre eso, hasta 4 horas a la semana a pago triple (art. 68). */
  var TABLA_LEY = { 2026: { ord: 48, dob: 9 }, 2027: { ord: 46, dob: 9 }, 2028: { ord: 44, dob: 10 }, 2029: { ord: 42, dob: 11 }, 2030: { ord: 40, dob: 12 } };
  var anioAct = Math.min(2030, Math.max(2026, ahora().getFullYear()));
  var REGLAS = { anio: anioAct, perm: 12, diasSeguidos: 6, vale: 3, alc: 10, docs: true, cel: true, herr: true };
  function limites() { var t = TABLA_LEY[REGLAS.anio]; return { ord: t.ord, dob: t.dob, tri: 4, max: t.ord + t.dob + 4 }; }

  /* ══ Cálculos por persona ══ */
  var RELOJ = 0;
  function stats(p) {
    if (p._t === RELOJ && p._s) return p._s;
    var N = ahora(), u = p.ints[p.ints.length - 1], dentro = !!(u && !u.sal);
    var desde7 = +N - 7 * DIA, h7 = 0;
    p.ints.forEach(function (it) { var a = Math.max(+it.ent, desde7), b = +(it.sal || N); if (b > a) h7 += b - a; });
    h7 /= HORA;
    var dias = {}; p.ints.forEach(function (it) { dias[claveDia(it.ent)] = 1; });
    var d = inicioDia(N), seg = 0;
    if (!dias[claveDia(d)]) d = sumarDias(d, -1);
    while (dias[claveDia(d)]) { seg++; d = sumarDias(d, -1); }
    var lim = limites();
    var s = { dentro: dentro, desde: dentro ? u.ent : null, perm: dentro ? N - u.ent : 0, h7: h7, seguidos: seg, docs: docs(p),
      ord: Math.min(h7, lim.ord), dob: Math.min(Math.max(h7 - lim.ord, 0), lim.dob), tri: Math.min(Math.max(h7 - lim.ord - lim.dob, 0), lim.tri),
      exc: Math.max(h7 - lim.max, 0), ultima: u || null };
    p._t = RELOJ; p._s = s;
    return s;
  }
  function docs(p) {
    var h = inicioDia(ahora()), lista = [];
    function chk(nombre, vence, extra) { var d = Math.round((inicioDia(vence) - h) / DIA); lista.push({ nombre: nombre, vence: vence, dias: d, est: d < 0 ? 'vencido' : (d <= 15 ? 'porvencer' : 'ok'), extra: extra || '' }); }
    chk('Inducción de seguridad de la planta', p.docs.induccion, 'Vigencia anual');
    chk('Examen médico', p.docs.medico);
    p.docs.dc3.forEach(function (c) { chk('DC-3 · ' + c.curso, c.vence, c.avba ? 'Emitida por AVBA: se valida sola' : 'Emitida por otro agente capacitador'); });
    lista.push({ nombre: 'Alta en el IMSS y pago SUA del mes', est: p.docs.imss === 'vigente' ? 'ok' : 'vencido', extra: p.docs.imss === 'vigente' ? 'Comprobante del mes cargado por la contratista' : 'La contratista no ha cargado el comprobante del mes' });
    var est = lista.some(function (x) { return x.est === 'vencido'; }) ? 'vencido' : (lista.some(function (x) { return x.est === 'porvencer'; }) ? 'porvencer' : 'ok');
    return { est: est, lista: lista };
  }
  function valesAbiertos(p) { return VALES.filter(function (v) { return v.pid === p.id && !v.cerrado; }); }
  function vetoVigente(p) { return p.estado === 'vetado' && (!p.veto.hasta || p.veto.hasta > ahora()); }

  function tick() { RELOJ++; }

  /* ══ Turnos (igual que accesos.html y ControlAccesos::turnoDe) ══
     Día 06:30–18:30, noche 18:30–06:30. Una entrada cuenta para el turno que
     empieza (desde 2 h antes); una salida, para el que termina (hasta 2 h después). */
  function turnoDe(tipo, d) {
    var m = d.getHours() * 60 + d.getMinutes(), f = claveDia(d);
    var ini = tipo === 'salida' ? 510 : 270, fin = tipo === 'salida' ? 1230 : 990;
    if (m >= ini && m < fin) return f + '-D';
    if (m >= fin) return f + '-N';
    return claveDia(sumarDias(d, -1)) + '-N';
  }
  function turnosEnCurso() { var n = ahora(), e = turnoDe('entrada', n), s = turnoDe('salida', n); return e === s ? [e] : [s, e].sort(); }
  function nombreTurno(k) { return k.slice(-1) === 'D' ? 'Turno de día' : 'Turno de noche'; }

  /* ══ Revisión en la puerta y casos de la simulación ══ */
  function candidato(caso) {
    tick();
    var lim = limites(), fuera = PERSONAS.filter(function (p) { return !stats(p).dentro; });
    var okBase = function (p) { return p.estado === 'activo' && stats(p).docs.est !== 'vencido'; };
    var jornadaOk = function (p) { var s = stats(p); return s.seguidos < REGLAS.diasSeguidos && s.h7 + p.pat.h <= lim.max; };
    var f = {
      ok: function (p) { return okBase(p) && jornadaOk(p) && !valesAbiertos(p).length; },
      herr: function (p) { return okBase(p) && jornadaOk(p) && p.aut.herr && !valesAbiertos(p).length; },
      cel: function (p) { return okBase(p) && jornadaOk(p) && !p.aut.cel && !valesAbiertos(p).length && !p.aut.herr; },
      veto: function (p) { return vetoVigente(p); },
      vale: function (p) { return okBase(p) && jornadaOk(p) && valesAbiertos(p).length > 0; },
      semana: function (p) { return okBase(p) && stats(p).h7 + p.pat.h > lim.max; },
      doc: function (p) { return p.estado === 'activo' && stats(p).docs.est === 'vencido'; },
      alcohol: function (p) { return okBase(p) && jornadaOk(p) && !valesAbiertos(p).length; },
      disp: function (p) { return okBase(p) && jornadaOk(p) && !valesAbiertos(p).length && (p.aut.tab || p.aut.pc); }
    }[caso];
    var c = fuera.filter(f);
    if (!c.length) c = fuera.filter(function (p) { return okBase(p) && jornadaOk(p); });
    if (!c.length) c = fuera.filter(okBase);
    return c[Math.floor(Math.random() * c.length)];
  }
  function revisarEntrada(p, caso) {
    caso = caso || '';
    var s = stats(p), lim = limites(), ch = [], inst = [], res = 'pasa', N = ahora(), motivos = [], guardia = caso === 'guardia' || caso.indexOf('g-') === 0;
    var peor = function (r) { var o = { pasa: 0, obs: 1, prueba: 2, esp: 3, nopasa: 4 }; if (o[r] > o[res]) res = r; };
    if (vetoVigente(p)) {
      ch.push({ s: 'critico', i: 'veto', t: 'Vetado', d: p.veto.motivo + ' · desde ' + fecha(p.veto.desde) + (p.veto.hasta ? ' hasta ' + fecha(p.veto.hasta) : ' · permanente') + ' · ' + p.veto.alcance + ' (' + p.veto.folio + ').' });
      ch.push({ s: 'info', i: 'campana', t: 'Se te envía una alerta', d: 'El intento queda en su expediente y en Alertas.' });
      return { ch: ch, inst: [], res: 'nopasa', motivos: ['Vetado ' + (p.veto.hasta ? 'hasta el ' + fecha(p.veto.hasta) : 'de forma permanente') + ' · ' + p.veto.alcance.toLowerCase()] };
    }
    s.docs.lista.forEach(function (d) {
      if (d.est === 'vencido') { ch.push({ s: REGLAS.docs ? 'critico' : 'aviso', i: 'doc', t: d.nombre, d: d.vence ? 'Venció el ' + fecha(d.vence) + '.' : d.extra + '.' }); peor(REGLAS.docs ? 'nopasa' : 'obs'); if (REGLAS.docs) motivos.push('Documento vencido: ' + d.nombre); }
      else if (d.est === 'porvencer') ch.push({ s: 'aviso', i: 'doc', t: d.nombre, d: 'Vence en ' + d.dias + ' días: se avisa a la contratista.' });
    });
    if (!ch.some(function (x) { return x.i === 'doc'; })) ch.push({ s: 'ok', i: 'doc', t: 'Documentos al día', d: 'Inducción, examen médico, IMSS/SUA' + (p.docs.dc3.length ? ' y DC-3 (' + p.docs.dc3.map(function (c) { return c.curso.replace(/ \(.*/, ''); }).join(', ') + ')' : '') + '.' });
    if (EMP[p.emp].repse !== 'Vigente') ch.push({ s: 'aviso', i: 'doc', t: 'REPSE de la contratista ' + (EMP[p.emp].repse === 'Vencido' ? 'vencido' : 'por vencer'), d: 'No detiene a la persona; se avisa a ' + EMP[p.emp].corto + '.' });
    // Jornada
    var hTurno = p.pat.h;
    if (s.seguidos >= REGLAS.diasSeguidos) { ch.push({ s: 'grave', i: 'reloj', t: 'Sería su día ' + (s.seguidos + 1) + ' seguido', d: 'La LFT pide un día de descanso por cada seis de trabajo (art. 69). Requiere autorización.' }); peor('esp'); motivos.push('Sería su día ' + (s.seguidos + 1) + ' seguido sin descanso'); }
    if (s.h7 + hTurno > lim.max) { ch.push({ s: 'grave', i: 'reloj', t: 'Excedería el máximo semanal', d: 'Lleva ' + fmtH(s.h7) + ' en 7 días; con este turno llegaría a ' + fmtH(s.h7 + hTurno) + ' (máximo ' + lim.max + ' h). Requiere autorización.' }); peor('esp'); motivos.push('Pasaría el máximo de horas de la semana'); }
    else if (s.h7 + hTurno > lim.ord) ch.push({ s: 'aviso', i: 'reloj', t: 'Este turno genera horas extra', d: 'Lleva ' + fmtH(s.h7) + ' en 7 días; jornada ordinaria ' + lim.ord + ' h.' });
    else ch.push({ s: 'ok', i: 'reloj', t: 'Jornada', d: 'Lleva ' + fmtH(s.h7) + ' en 7 días y ' + s.seguidos + ' día(s) seguidos.' });
    // Celular
    var traeCel = !guardia && (caso === 'cel' || (caso !== 'ok' && caso !== 'disp' && Math.random() < 0.6) || p.aut.cel);
    var disp = [['tab', 'Tableta'], ['pc', 'Computadora'], ['cam', 'Cámara']].filter(function (x) { return p.aut[x[0]]; }).map(function (x) { return x[1]; });
    if (disp.length) ch.push({ s: 'ok', i: 'pc', t: 'Dispositivos autorizados', d: disp.join(', ') + '.' });
    if (traeCel) {
      if (p.aut.cel) ch.push({ s: 'ok', i: 'cel', t: 'Celular autorizado', d: 'Puede ingresar con su celular.' });
      else if (REGLAS.cel) { ch.push({ s: 'aviso', i: 'cel', t: 'Trae celular sin autorización', d: 'Se resguarda en casillero y se le entrega a la salida.' }); inst.push(['cel', 'Resguardar el celular en casillero']); peor('obs'); }
    }
    if (p.aut.herr && REGLAS.herr) { ch.push({ s: 'info', i: 'herr', t: 'Ingresa herramienta autorizada', d: 'Se abre un vale con lo que ingresa; a la salida se revisa que salga.', herr: p.herr }); inst.push(['herr', 'Registrar ' + p.herr.length + ' herramientas que ingresa']); }
    if (p.aut.auto) { ch.push({ s: 'ok', i: 'auto', t: 'Vehículo autorizado', d: 'Placas ' + p.placas + '.' }); inst.push(['auto', 'Vehículo ' + p.placas + ': puede pasar']); }
    valesAbiertos(p).forEach(function (v) { ch.push({ s: N - v.abierto > REGLAS.vale * DIA ? 'grave' : 'aviso', i: 'vale', t: 'Vale pendiente ' + v.folio, d: TIPO_VALE[v.tipo] + ': ' + v.items.join(', ') + ' · abierto ' + cuando(v.abierto) + (v.nota ? ' · ' + v.nota : '') + '.' }); peor('obs'); });
    var incR = INCIDENCIAS.filter(function (i) { return i.pid === p.id && N - i.fecha < 90 * DIA; });
    if (incR.length && !vetoVigente(p)) ch.push({ s: 'aviso', i: 'escudo', t: incR.length + ' incidencia(s) en 90 días', d: incR.map(function (i) { return TIPOS_INC[i.tipo].n.toLowerCase(); }).join(', ') + '.' });
    var base = res;                                     // el resultado sin la prueba de alcoholímetro
    var alc = res !== 'nopasa' && (caso === 'alcohol' || caso === 'g-alcohol' || (caso.indexOf('g-') !== 0 || caso === 'g-ok') && Math.random() * 100 < REGLAS.alc);
    if (alc) { ch.push({ s: 'info', i: 'copa', t: 'Seleccionado para alcoholímetro aleatorio', d: 'El guardia hace la prueba y captura el resultado en su teléfono.' }); peor('prueba'); inst.unshift(['copa', 'Hacer prueba de alcoholímetro']); }
    return { ch: ch, inst: inst, res: res, base: base, motivos: motivos };
  }

  /* ══ Lo que se registra en la demo (compartido entre pestañas) ══ */
  var CLAVE = 'avba_demo_accesos_' + semilla, OPS = [], aplicadas = 0, oyentes = [];
  function leerOps() { try { var v = JSON.parse(localStorage.getItem(CLAVE) || '[]'); return Array.isArray(v) ? v : []; } catch (e) { return OPS; } }
  function guardarOps() { try { localStorage.setItem(CLAVE, JSON.stringify(OPS)); } catch (e) {} }
  function folioInc() { return 'INC-' + String(400 + INCIDENCIAS.length + 1).padStart(4, '0'); }
  function folioVale() { return 'V-' + String(180 + VALES.length + 1).padStart(4, '0'); }
  function aplicar(op) {
    var p = P[op.pid], t = new Date(op.t), u;
    if (op.k !== 'cv' && !p) return null;
    switch (op.k) {
      case 'ent':                                           // entrada
        u = p.ints[p.ints.length - 1];
        if (u && !u.sal) return null;
        p.ints.push({ ent: t, sal: null, cE: op.caseta || CASETAS[0], cS: '', obs: op.obs || '' });
        if (op.herr && p.herr.length) VALES.push({ folio: folioVale(), pid: p.id, tipo: 'herr', items: p.herr.slice(), abierto: t, cerrado: null });
        MOVS.unshift({ t: t, p: p, tipo: 'e', res: op.res || 'Permitido', caseta: op.caseta || CASETAS[0], quien: op.quien || '' });
        return true;
      case 'sal':                                           // salida
        u = p.ints[p.ints.length - 1];
        if (u && !u.sal) { u.sal = t; u.cS = op.caseta || CASETAS[0]; }
        VALES.forEach(function (v) {
          if (v.pid !== p.id || v.cerrado) return;
          if (v.tipo === 'herr') { if (op.falta && op.falta.length) v.nota = 'Salió sin presentar: ' + op.falta.join(', '); else v.cerrado = t; }
          else if (v.tipo === 'equipo') { if (op.equipoNo) v.nota = 'No devolvió el equipo'; else v.cerrado = t; }
        });
        MOVS.unshift({ t: t, p: p, tipo: 's', res: op.falta && op.falta.length ? 'Sin herramienta' : (op.res || 'Permitido'), caseta: op.caseta || CASETAS[0], quien: op.quien || '' });
        return true;
      case 'int':                                           // intento de entrada negado
        if (vetoVigente(p)) INTENTOS.push({ pid: p.id, t: t, caseta: op.caseta || CASETAS[0] });
        MOVS.unshift({ t: t, p: p, tipo: 'e', res: 'Negado · ' + (op.motivo || 'acceso negado'), caseta: op.caseta || CASETAS[0], quien: op.quien || '' });
        return true;
      case 'inc':                                           // incidencia (y veto, si la medida lo es)
        var inc = { folio: folioInc(), pid: p.id, tipo: op.tipo, grav: TIPOS_INC[op.tipo].g, fecha: t, accion: op.accion,
          desc: op.desc || TIPOS_INC[op.tipo].d[0], estado: 'En seguimiento', por: op.por || 'Admin. de accesos' };
        INCIDENCIAS.push(inc);
        if (/Veto/.test(op.accion)) {
          var dias = /30/.test(op.accion) ? 30 : (/90/.test(op.accion) ? 90 : 0);
          p.estado = 'vetado';
          p.veto = { desde: t, hasta: dias ? sumarDias(t, dias) : null, motivo: TIPOS_INC[op.tipo].n, alcance: /todas/.test(op.accion) ? 'Todas las plantas del cliente' : 'Esta planta', folio: inc.folio };
        }
        return inc;
      case 'aut': p.aut[op.c] = !!op.v; return true;        // permiso de dispositivo o herramienta
      case 'lev': p.estado = 'activo'; p.veto = null; return true;   // levantar veto
      case 'cv':                                            // cerrar vale
        VALES.forEach(function (v) { if (v.folio === op.folio && !v.cerrado) v.cerrado = t; });
        return true;
    }
    return null;
  }
  function sincronizar() {
    var todas = leerOps();
    if (todas.length < aplicadas) { location.reload(); return false; }     // se reinició la demo en otra pestaña
    for (var i = aplicadas; i < todas.length; i++) aplicar(todas[i]);
    var hubo = todas.length > aplicadas;
    OPS = todas; aplicadas = todas.length;
    if (hubo) tick();
    return hubo;
  }
  function registrar(op) {
    sincronizar();
    op.t = op.t || Date.now();
    OPS.push(op); aplicadas = OPS.length; guardarOps();
    var r = aplicar(op); tick();
    return r;
  }
  window.addEventListener('storage', function (e) {
    if (e.key !== CLAVE && e.key !== null) return;
    if (sincronizar()) oyentes.forEach(function (f) { try { f(); } catch (x) {} });
  });
  sincronizar();

  window.DEMO = {
    EMPRESAS: EMPRESAS, EMP: EMP, TIPOS_INC: TIPOS_INC, CORTO_INC: CORTO_INC, ACCIONES: ACCIONES, TIPO_VALE: TIPO_VALE, CASETAS: CASETAS,
    PERSONAS: PERSONAS, P: P, INCIDENCIAS: INCIDENCIAS, VALES: VALES, INTENTOS: INTENTOS, MOVS: MOVS,
    TABLA_LEY: TABLA_LEY, REGLAS: REGLAS, limites: limites, stats: stats, docs: docs, valesAbiertos: valesAbiertos, vetoVigente: vetoVigente,
    candidato: candidato, revisar: revisarEntrada, turnoDe: turnoDe, turnosEnCurso: turnosEnCurso, nombreTurno: nombreTurno,
    tick: tick, registrar: registrar, alCambiar: function (f) { oyentes.push(f); },
    reiniciar: function () { try { localStorage.removeItem(CLAVE); } catch (e) {} location.reload(); },
    cuantos: function () { return OPS.length; }
  };
})();
