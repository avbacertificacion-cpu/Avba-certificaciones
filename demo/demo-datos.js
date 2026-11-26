/* ══════════════════════════════════════════════════════════════
   AVBA — DEMO de control de accesos: datos ficticios y reglas compartidas

   Lo usan las dos pantallas de la beta:
     · demo/control-accesos.html  panel del administrador de accesos
     · demo/caseta.html           pantalla del guardia en campo

   Las personas, empresas, incidencias y vales se generan en el navegador con
   una semilla del día: cualquier dispositivo, a cualquier hora, genera los
   mismos. Cada turno trae su hora planeada de entrada y salida, y quién está
   dentro se calcula con la hora actual, así que la demo se mueve sola.
   Lo que se registra en la demo (entradas, salidas, vetos, permisos,
   solicitudes de autorización) se comparte:
     · sin sala, entre las pestañas de este navegador (localStorage);
     · con sala (?sala=CODIGO, desde demo/index.html), entre dispositivos,
       a través de demo/sala.php.
   Todo es ficticio; nada toca la base del sistema.
   ══════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var MIN = 60000, HORA = 3600000, DIA = 86400000;
  function ahora() { return new Date(); }
  /* «Ahora» de la vista: si llegó una operación de un equipo con el reloj unos
     segundos adelantado, se toma su hora para que se vea de inmediato (máx. 5 min). */
  var ultimaOp = 0;
  function ahoraVista() { return new Date(Math.max(Date.now(), ultimaOp)); }
  function notarHora(op) { var t = +op.t || 0; if (t > ultimaOp && t - Date.now() < 5 * 60000) ultimaOp = t; }
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
  function pl(n, uno, varios) { return n + ' ' + (n === 1 ? uno : varios); }
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
  /* Personal de la planta que se registra al leer su tarjeta en la caseta (app AVBA Caseta).
     No va en EMPRESAS: así no cambia la generación de los datos del día. */
  EMP.QEN = { id: 'QEN', nombre: 'Quantum Energy', corto: 'Quantum Energy', repse: 'Vigente', n: 0 };
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
    ALC: { n: 'Aliento alcohólico / alcoholímetro positivo', g: 'Alta', d: ['Alcoholímetro en caseta: 0.16 mg/L en aire espirado.', 'Aliento alcohólico detectado por supervisión; prueba positiva.'] },
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

  var PERSONAS = [], P = {};
  /* Lo que se ve en este momento: se recalcula con la hora (ver refrescar()).
     Las pantallas guardan estas listas por referencia, así que se rellenan en su lugar. */
  var INCIDENCIAS = [], VALES = [], INTENTOS = [], MOVS = [];
  /* Solicitudes de autorización del guardia al administrador (p. ej. exceso de horas) */
  var SOLICITUDES = [];
  /* Todo lo planeado para el día, incluso lo que todavía no ocurre */
  var T_INC = [], T_VALES = [], T_INT = [];
  var nInc = 0, nVale = 180;

  /* ══ Generación ══════════════════════════════════════════════════════
     Todo sale de la semilla del día y de horas fijas del día, NUNCA de la hora
     en que se abrió la página: la laptop y el celular (y cada recarga) generan
     exactamente lo mismo. Cada turno trae su hora planeada de entrada y de
     salida; quién está dentro se calcula con la hora actual, así que la demo
     «vive»: la gente sale a su hora y el turno siguiente entra solo. */
  function valeT(p, tipo, items, abierto, extra) {
    var v = { folio: 'V-' + String(++nVale).padStart(4, '0'), pid: p.id, tipo: tipo, items: items, abierto: abierto,
              it: null, cerradoPlan: null, cerradoReal: null, forzado: false, nota: '', cerrado: null };
    if (extra) for (var k in extra) v[k] = extra[k];
    T_VALES.push(v); return v;
  }
  function nuevaInc(p, tipo, f, accion, extra) {
    var t = TIPOS_INC[tipo], d = elegir(t.d), seg = prob(0.5);
    var inc = { folio: 'INC-' + String(++nInc + 400).padStart(4, '0'), pid: p.id, tipo: tipo, grav: t.g, fecha: f, accion: accion,
      desc: d, estado: (hoy0 - f) < 10 * DIA && seg ? 'En seguimiento' : 'Cerrada', por: 'Admin. de accesos' };
    if (extra) for (var k in extra) inc[k] = extra[k];
    T_INC.push(inc); return inc;
  }

  /* Sin apellidos repetidos ni combinaciones que provoquen risa, y el puesto
     en femenino cuando corresponde. No usa el azar: los datos no cambian. */
  var FEM = [[/^Operador /, 'Operadora '], [/^Supervisor /, 'Supervisora '], [/^Inspector /, 'Inspectora '], [/^Técnico /, 'Técnica '],
             [/^Mecánico montador$/, 'Mecánica montadora'], [/^Soldador$/, 'Soldadora'], [/^Pailero$/, 'Pailera'], [/^Andamiero$/, 'Andamiera'],
             [/^Oficial fierrero$/, 'Oficial fierrera']];
  function pulirNombre(p, mujer) {
    var x = p.nombre.split(' '), n = x.length;
    if (x[n - 1] === x[n - 2]) x[n - 1] = APE[(APE.indexOf(x[n - 2]) + 7) % APE.length];
    if (x[0] === 'Mario' && x[n - 2] === 'Moreno') x[n - 2] = 'Morales';
    p.nombre = x.join(' ');
    p.mujer = mujer;
    if (mujer) FEM.forEach(function (f) { p.puesto = p.puesto.replace(f[0], f[1]); });
    else p.puesto = p.puesto.replace(/^Supervisora /, 'Supervisor ');
  }
  (function generar() {
    var hoy = hoy0, n = 0;
    EMPRESAS.forEach(function (e) {
      for (var i = 0; i < e.n; i++) {
        var mujer = prob(e.id === 'LIA' ? 0.55 : (e.id === 'AVB' ? 0.3 : 0.08));
        var lista = PUESTOS[e.id], sup = i < (e.n > 20 ? 2 : 1);
        var puesto = sup ? lista[lista.length - 1] : elegir(lista.slice(0, -1));
        var r = rnd(), pat;
        if (e.id === 'AVB') pat = { on: 5, off: 2, h: 10 };
        else if (r < 0.58) pat = { on: 4, off: 3, h: 12 };
        else if (r < 0.88) pat = { on: 5, off: 2, h: 10 };
        else if (r < 0.96) pat = { on: 5, off: 2, h: 11 };
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
          aut: { cel: sup || e.id === 'AVB' || prob(0.12), tab: (sup || e.id === 'AVB') ? prob(0.45) : prob(0.08), herr: herrAut, pc: (sup || e.id === 'AVB') && prob(0.7), auto: sup ? prob(0.6) : prob(0.04), cam: e.id === 'AVB' && prob(0.6) },
          herr: herrAut ? mezclar(HERR).slice(0, entre(2, 4)) : [],
          placas: '', estado: 'activo', veto: null, plan: [], ints: [],
          alta: sumarDias(hoy, -entre(20, 400)),
          docs: {
            induccion: sumarDias(hoy, prob(0.03) ? -entre(1, 20) : entre(5, 340)),
            medico: sumarDias(hoy, prob(0.025) ? -entre(1, 15) : entre(3, 300)),
            imss: (e.id === 'LIA' ? prob(0.25) : prob(0.015)) ? 'pendiente' : 'vigente',
            dc3: cursosDe(puesto).map(function (c) { return { curso: CURSOS[c], vence: sumarDias(hoy, prob(0.03) ? -entre(1, 30) : entre(5, 360)), avba: prob(0.6) }; })
          }
        };
        if (p.aut.auto) p.placas = String.fromCharCode(65 + entre(0, 25), 65 + entre(0, 25), 65 + entre(0, 25)) + '-' + entre(100, 999) + '-' + String.fromCharCode(65 + entre(0, 25));
        // Turnos de los últimos 16 días y los de hoy (aunque todavía no empiecen)
        var ciclo = p.pat.on + p.pat.off;
        for (var k = 16; k >= 0; k--) {
          if ((p.fase + 1000 - k) % ciclo >= p.pat.on) continue;
          var s = sumarDias(hoy, -k);
          var base = p.turno === 'D' ? conHora(s, 6, 0) : conHora(s, 18, 0);
          var ent = new Date(+base + entre(-25, 40) * MIN + entre(0, 59) * 1000 + (prob(0.04) ? entre(60, 150) * MIN : 0));
          var mins = p.pat.h * 60 + entre(-25, 15) + (prob(0.05) ? entre(60, 180) : 0);
          var sal = new Date(+ent + mins * MIN + entre(0, 59) * 1000);
          var cE = CASETAS[prob(0.7) ? 0 : 1], cS = CASETAS[prob(0.7) ? 0 : 1];
          p.plan.push({ ent: ent, salPlan: sal, salReal: null, cE: cE, cS: cS, obs: '', cancel: false, manual: false });
        }
        pulirNombre(p, mujer);
        PERSONAS.push(p); P[p.id] = p;
      }
    });

    // Vetos (con su incidencia)
    var candidatos = mezclar(PERSONAS.filter(function (p) { return !p.sup && p.emp !== 'AVB'; }));
    var motivos = [['ALC', 'Veto 90 días'], ['ROB', 'Veto permanente en esta planta'], ['RIN', 'Veto en todas las plantas del cliente'], ['ALC', 'Veto 30 días'], ['SUP', 'Veto permanente en esta planta'], ['DRO', 'Veto 90 días']];
    motivos.forEach(function (m, i) {
      var p = candidatos[i], f = conHora(sumarDias(hoy, -entre(3, 40)), entre(6, 20), entre(0, 59));
      var inc = nuevaInc(p, m[0], f, m[1]);
      var dias = /30/.test(m[1]) ? 30 : (/90/.test(m[1]) ? 90 : 0);
      p.estado = 'vetado';
      p.veto = { desde: f, hasta: dias ? sumarDias(f, dias) : null, motivo: TIPOS_INC[m[0]].n, alcance: /todas/.test(m[1]) ? 'Todas las plantas del cliente' : 'Esta planta', folio: inc.folio };
      p.plan = p.plan.filter(function (it) { return it.ent < f; });
      var u = p.plan[p.plan.length - 1];
      if (u && u.salPlan > f) u.salPlan = new Date(+f + 20 * MIN);
    });
    // Intentos de ingreso estando vetado: ayer en la tarde, hoy temprano y hoy después de comer
    [[0, -6.2], [1, 6.3], [0, 13.7]].forEach(function (x) {
      var p = candidatos[x[0]], t = new Date(+hoy + x[1] * HORA + entre(0, 40) * MIN);
      T_INT.push({ pid: p.id, t: t, caseta: CASETAS[1] });
      nuevaInc(p, 'VET', t, 'Se negó el acceso', { estado: 'En seguimiento' });
    });
    // Incidencias de los últimos 6 meses
    var pesos = ['EPP', 'EPP', 'EPP', 'EPP', 'ACTO', 'ACTO', 'ACTO', 'CASI', 'CASI', 'ACC', 'ACC', 'CEL', 'CEL', 'ALC', 'ROB'];
    var activos = PERSONAS.filter(function (p) { return p.estado === 'activo'; });
    for (var i = 0; i < 62; i++) {
      var tipo = elegir(pesos), p = elegir(activos), f = conHora(sumarDias(hoy, -entre(1, 179)), entre(6, 22), entre(0, 59));
      var acc = { EPP: 'Plática de seguridad', ACTO: 'Amonestación por escrito', CASI: 'Plática de seguridad', ACC: 'Plática de seguridad', CEL: 'Amonestación por escrito', ALC: 'Suspensión 7 días', ROB: 'Suspensión 3 días' }[tipo];
      var inc = entre(0, 6);
      nuevaInc(p, tipo, f, acc, tipo === 'ACC' ? { incapacidad: inc, st7: true } : null);
    }
    /* Personas «doblando turno»: estancias de unas 15 h que empiezan cada 2 h,
       desde ayer a las 08:00 hasta hoy a las 12:00. A cualquier hora del día
       hay una o dos pasando su tope de permanencia y alguna por cumplirlo. */
    // Se eligen entre quienes trabajan 5 días de 10 h, para que su semana no se dispare
    var dobles = mezclar(activos.filter(function (p) { return !p.sup && p.emp !== 'AVB' && p.pat.on === 5 && p.pat.h === 10; })).slice(0, 15);
    dobles.forEach(function (p, i) {
      var ent = new Date(+hoy - 16 * HORA + i * 2 * HORA + entre(-20, 20) * MIN);
      var sal = new Date(+ent + (15 * 60 + entre(0, 50)) * MIN);
      p.plan = p.plan.filter(function (it) { return it.salPlan < new Date(+ent - 9 * HORA) || it.ent > new Date(+sal + 9 * HORA); });
      p.plan.push({ ent: ent, salPlan: sal, salReal: null, cE: CASETAS[0], cS: CASETAS[0], obs: '', cancel: false, manual: false });
      p.plan.sort(function (a, b) { return a.ent - b.ent; });
    });
    // Vales de herramienta de cada turno reciente (se cierran solos al salir)
    PERSONAS.forEach(function (p) {
      if (!p.aut.herr) return;
      p.plan.forEach(function (it) {
        var dif = (hoy - inicioDia(it.ent)) / DIA;
        var toca = prob(0.6);
        if (dif <= 3 && toca) valeT(p, 'herr', p.herr.slice(), it.ent, { it: it });
      });
    });
    // Herramienta que no se presentó a la salida (vales vencidos)
    mezclar(activos.filter(function (p) { return p.aut.herr; })).slice(0, 5).forEach(function (p) {
      var hechos = p.plan.filter(function (it) { return it.salPlan < new Date(+hoy - DIA); });
      var u = hechos.slice(-entre(2, 4))[0];
      var cuantos = entre(1, Math.max(1, p.herr.length));
      if (u) valeT(p, 'herr', p.herr.slice(0, cuantos), u.ent, { forzado: true, nota: 'Salió sin presentar la herramienta' });
    });
    // Equipo de planta prestado
    mezclar(activos).slice(0, 9).forEach(function (p, i) {
      var dias = i < 5 ? entre(4, 9) : entre(1, 2);
      var ab = new Date(+hoy - dias * DIA + entre(360, 1080) * MIN);
      var cer = new Date(+ab + entre(2, 20) * HORA);
      valeT(p, 'equipo', [elegir(EQUIPO_PLANTA)], ab, { cerradoPlan: i > 6 ? cer : null });
    });
    // Salidas de material
    mezclar(activos.filter(function (p) { return p.sup; })).slice(0, 7).forEach(function (p, i) {
      var ab = new Date(+hoy - entre(1, 6) * DIA + entre(360, 1080) * MIN);
      var cer = new Date(+ab + entre(1, 30) * HORA);
      valeT(p, 'mat', [elegir(MATERIAL)], ab, { cerradoPlan: i < 3 ? null : cer, autoriza: i < 2 ? 'Pendiente de autorizar' : 'Autorizado por Mantenimiento' });
    });
  })();

  /* ══ Lo que se ve a esta hora ══════════════════════════════════════════ */
  function salidaDe(it, N) { if (it.salReal) return it.salReal; return it.salPlan && it.salPlan <= N ? it.salPlan : null; }
  function cierreVale(v, N) {
    if (v.cerradoReal) return v.cerradoReal <= N ? v.cerradoReal : null;
    if (v.forzado) return null;
    if (v.it) return salidaDe(v.it, N);
    return v.cerradoPlan && v.cerradoPlan <= N ? v.cerradoPlan : null;
  }
  function itemDentro(p, t) {
    var r = null;
    p.plan.forEach(function (it) {
      if (it.cancel || it.ent > t) return;
      var s = it.salReal || it.salPlan;
      if (!s || s > t) r = it;
    });
    return r;
  }
  function rellenar(arr, nuevos) { arr.length = 0; Array.prototype.push.apply(arr, nuevos); }
  var vistaEn = 0;
  function refrescar(forzar) {
    var N = ahoraVista();
    if (!forzar && Math.abs(N - vistaEn) < 1000) return;
    vistaEn = +N;
    PERSONAS.forEach(function (p) {
      var v = [];
      p.plan.forEach(function (it) {
        if (it.cancel || it.ent > N) return;
        v.push({ ent: it.ent, sal: salidaDe(it, N), cE: it.cE, cS: it.cS, obs: it.obs });
      });
      v.sort(function (a, b) { return a.ent - b.ent; });
      p.ints = v;
    });
    rellenar(INCIDENCIAS, T_INC.filter(function (i) { return i.fecha <= N; }));
    rellenar(INTENTOS, T_INT.filter(function (x) { return x.t <= N; }));
    rellenar(VALES, T_VALES.filter(function (v) { return v.abierto <= N && !(v.it && v.it.cancel); }).map(function (v) { v.cerrado = cierreVale(v, N); return v; }));
  }

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
    refrescar(false);
    var N = ahoraVista(), u = p.ints[p.ints.length - 1], dentro = !!(u && !u.sal);
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

  function tick() { RELOJ++; refrescar(true); }

  /* ══ Turnos (igual que accesos.html y ControlAccesos::turnoDe) ══
     Día 06:00–18:00, noche 18:00–06:00. Una entrada cuenta para el turno que
     empieza (desde 2 h antes); una salida, para el que termina (hasta 2 h después). */
  function turnoDe(tipo, d) {
    var m = d.getHours() * 60 + d.getMinutes(), f = claveDia(d);
    var ini = tipo === 'salida' ? 480 : 240, fin = tipo === 'salida' ? 1200 : 960;
    if (m >= ini && m < fin) return f + '-D';
    if (m >= fin) return f + '-N';
    return claveDia(sumarDias(d, -1)) + '-N';
  }
  function turnosEnCurso() { var n = ahora(), e = turnoDe('entrada', n), s = turnoDe('salida', n); return e === s ? [e] : [s, e].sort(); }
  function nombreTurno(k) { return k.slice(-1) === 'D' ? 'Turno de día' : 'Turno de noche'; }

  /* ══ Revisión en la puerta y casos de la simulación ══ */
  /* Persona de cada caso del guion: no es al azar, sale de un orden fijo por
     día y caso. En el ensayo y en la presentación aparece la misma; si ya
     entró, la siguiente de la lista. */
  function hash(t) { var h = 2166136261; for (var i = 0; i < t.length; i++) { h ^= t.charCodeAt(i); h = Math.imul(h, 16777619); } return h >>> 0; }
  function candidato(caso) {
    tick();
    // Los casos del guion son personas de la demo, nunca alguien registrado con su tarjeta real
    var lim = limites(), fuera = PERSONAS.filter(function (p) { return !p.tarjeta && !stats(p).dentro; });
    var okBase = function (p) { return p.estado === 'activo' && stats(p).docs.est !== 'vencido'; };
    var jornadaOk = function (p) { var s = stats(p); return s.seguidos < REGLAS.diasSeguidos && s.h7 + p.pat.h <= lim.max; };
    var limpio = function (p) { return okBase(p) && jornadaOk(p) && !valesAbiertos(p).length && stats(p).docs.est === 'ok'; };
    var especial = function (p) { return p.aut.tab || p.aut.pc; };          // se reservan para el caso «tableta o computadora»
    var sinHerr = function (p) { return !p.aut.herr; };
    var sinAvba = function (p) { return p.emp !== 'AVB'; };              // AVBA no aparece en los casos negativos
    var y = function () { var fs = arguments; return function (p) { for (var k = 0; k < fs.length; k++) if (!fs[k](p)) return false; return true; }; };
    var ok = function (p) { return limpio(p) && sinHerr(p) && !especial(p) && EMP[p.emp].repse === 'Vigente'; };
    var semana = function (p) { return okBase(p) && stats(p).h7 + p.pat.h > lim.max && sinAvba(p); };
    var doc = function (p) { return p.estado === 'activo' && stats(p).docs.est === 'vencido' && sinAvba(p); };
    var alc = function (p) { return limpio(p) && !especial(p) && sinAvba(p); };
    var disp = function (p) { return okBase(p) && jornadaOk(p) && !valesAbiertos(p).length && especial(p); };
    // Para cada caso, del filtro preferido al más amplio
    var filtros = {
      ok: [y(ok, function (p) { return p.aut.cel; }), ok],                 // de preferencia con celular autorizado (se ve en verde)
      herr: [function (p) { return limpio(p) && p.aut.herr && p.herr.length > 0 && !especial(p); }],
      cel: [function (p) { return limpio(p) && !p.aut.cel && sinHerr(p) && !especial(p) && sinAvba(p); }],
      veto: [vetoVigente],
      vale: [function (p) { return okBase(p) && jornadaOk(p) && valesAbiertos(p).length > 0; }],
      semana: [y(semana, function (p) { return sinHerr(p) && stats(p).h7 <= lim.max; }), y(semana, sinHerr), semana],
      doc: [y(doc, sinHerr), doc],
      alcohol: [y(alc, sinHerr), alc],
      disp: [y(disp, sinHerr), disp]
    }[caso] || [];
    var rasgo = { disp: especial, herr: function (p) { return p.aut.herr && p.herr.length > 0; }, cel: function (p) { return !p.aut.cel; }, vale: function (p) { return valesAbiertos(p).length > 0; } }[caso];
    var c = [];
    for (var k = 0; k < filtros.length && !c.length; k++) c = fuera.filter(filtros[k]);
    if (!c.length && rasgo) c = fuera.filter(function (p) { return okBase(p) && rasgo(p); });   // conserva lo que se quiere mostrar
    if (!c.length) c = fuera.filter(function (p) { return okBase(p) && jornadaOk(p); });
    if (!c.length) c = fuera.filter(okBase);
    if (!c.length) return null;
    c.sort(function (a, b) { return hash(semilla + caso + a.id) - hash(semilla + caso + b.id); });
    return c[0];
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
    if (s.seguidos >= REGLAS.diasSeguidos) { ch.push({ s: 'grave', i: 'reloj', t: 'Sería su día ' + (s.seguidos + 1) + ' seguido', d: 'La LFT pide un día de descanso por cada seis de trabajo (art. 69). Requiere autorización.' }); peor('esp'); motivos.push('Sería su día ' + (s.seguidos + 1) + ' seguido sin descanso (la ley pide uno de cada seis)'); }
    if (s.h7 + hTurno > lim.max) { ch.push({ s: 'grave', i: 'reloj', t: 'Excedería el máximo semanal', d: 'Lleva ' + fmtH(s.h7) + ' en 7 días; con este turno llegaría a ' + fmtH(s.h7 + hTurno) + ' (máximo ' + lim.max + ' h). Requiere autorización.' }); peor('esp'); motivos.push('Lleva ' + fmtH(s.h7) + ' en 7 días; con este turno de ' + hTurno + ' h llegaría a ' + fmtH(s.h7 + hTurno) + ' (máximo ' + lim.max + ' h)'); }
    else if (s.h7 + hTurno > lim.ord) ch.push({ s: 'aviso', i: 'reloj', t: 'Este turno genera horas extra', d: 'Lleva ' + fmtH(s.h7) + ' en 7 días; jornada ordinaria ' + lim.ord + ' h.' });
    else ch.push({ s: 'ok', i: 'reloj', t: 'Jornada', d: 'Lleva ' + fmtH(s.h7) + ' en 7 días y ' + pl(s.seguidos, 'día seguido', 'días seguidos') + '.' });
    // Celular
    var traeCel = caso === 'g-cel' || (!guardia && (caso === 'cel' || (caso === '' && Math.random() < 0.6) || p.aut.cel));
    var disp = [['tab', 'Tableta'], ['pc', 'Computadora'], ['cam', 'Cámara']].filter(function (x) { return p.aut[x[0]]; }).map(function (x) { return x[1]; });
    if (disp.length) ch.push({ s: 'ok', i: 'pc', t: 'Dispositivos autorizados', d: disp.join(', ') + '.' });
    if (traeCel) {
      if (p.aut.cel) ch.push({ s: 'ok', i: 'cel', t: 'Celular autorizado', d: 'Puede ingresar con su celular.' });
      else if (REGLAS.cel) { ch.push({ s: 'aviso', i: 'cel', t: 'Trae celular sin autorización', d: 'Se resguarda en casillero y se le entrega a la salida.' }); inst.push(['cel', 'Resguardar el celular en casillero']); peor('obs'); }
    }
    if (p.aut.herr && REGLAS.herr && p.herr.length) { ch.push({ s: 'info', i: 'herr', t: 'Ingresa herramienta autorizada', d: 'Se abre un vale con lo que ingresa; a la salida se revisa que salga.', herr: p.herr }); inst.push(['herr', 'Registrar ' + p.herr.length + ' herramientas que ingresa']); }
    if (p.aut.auto) { ch.push({ s: 'ok', i: 'auto', t: 'Vehículo autorizado', d: 'Placas ' + p.placas + '.' }); inst.push(['auto', 'Vehículo ' + p.placas + ': puede pasar']); }
    valesAbiertos(p).forEach(function (v) { ch.push({ s: N - v.abierto > REGLAS.vale * DIA ? 'grave' : 'aviso', i: 'vale', t: 'Vale pendiente ' + v.folio, d: TIPO_VALE[v.tipo] + ': ' + v.items.join(', ') + ' · abierto ' + cuando(v.abierto) + (v.nota ? ' · ' + v.nota : '') + '.' }); peor('obs'); });
    var incR = INCIDENCIAS.filter(function (i) { return i.pid === p.id && N - i.fecha < 90 * DIA; });
    if (incR.length && !vetoVigente(p)) ch.push({ s: 'aviso', i: 'escudo', t: pl(incR.length, 'incidencia', 'incidencias') + ' en 90 días', d: incR.map(function (i) { return TIPOS_INC[i.tipo].n.toLowerCase(); }).join(', ') + '.' });
    var base = res;                                     // el resultado sin la prueba de alcoholímetro
    var azar = caso === 'guardia' || caso === '';         // en los casos del guion no se sortea
    var alc = res !== 'nopasa' && (caso === 'alcohol' || caso === 'g-alcohol' || (azar && Math.random() * 100 < REGLAS.alc));
    if (alc) { ch.push({ s: 'info', i: 'copa', t: 'Seleccionado para alcoholímetro aleatorio', d: 'El guardia hace la prueba y captura el resultado en su teléfono.' }); peor('prueba'); inst.unshift(['copa', 'Hacer prueba de alcoholímetro']); }
    return { ch: ch, inst: inst, res: res, base: base, motivos: motivos };
  }

  /* ══ Lo que se registra en la demo ═══════════════════════════════════
     Cada operación (entrada, salida, intento, incidencia, permiso, veto, vale)
     lleva un id y la hora en que ocurrió, y se aplica igual en cualquier
     dispositivo, en el mismo orden.
       · Sin sala: se guarda en este navegador (localStorage) y las pestañas
         abiertas se sincronizan solas.
       · Con sala (?sala=CODIGO, ver demo/index.html): demo/sala.php guarda las
         operaciones y todos los dispositivos de la sala las reciben; así el
         celular del guardia y la laptop del administrador ven lo mismo. */
  function folioInc() { return 'INC-' + String(400 + (++nInc)).padStart(4, '0'); }
  function folioVale() { return 'V-' + String(++nVale).padStart(4, '0'); }
  function aplicar(op) {
    var p = P[op.pid], t = new Date(op.t), it;
    if (op.k !== 'cv' && op.k !== 'resp' && op.k !== 'reg' && op.k !== 'am' && op.k !== 'alta' && !p) return null;
    if (isNaN(t)) return null;
    switch (op.k) {
      case 'ent':                                           // entrada
        if (itemDentro(p, t)) return null;                  // ya estaba dentro
        p.plan.forEach(function (x) { if (!x.manual && x.ent > t) x.cancel = true; });   // lo planeado después ya no ocurre
        it = { ent: t, salPlan: null, salReal: null, cE: op.caseta || CASETAS[0], cS: '', obs: op.obs || '', cancel: false, manual: true };
        p.plan.push(it);
        var items = Array.isArray(op.items) && op.items.length ? p.herr.filter(function (h) { return op.items.indexOf(h) >= 0; }) : p.herr.slice();
        if (op.herr && items.length) { var ex = { it: it }; if (op.folio) ex.folio = op.folio; valeT(p, 'herr', items, t, ex); }
        MOVS.unshift({ t: t, p: p, tipo: 'e', res: op.res || 'Permitido', caseta: op.caseta || CASETAS[0], quien: op.quien || '' });
        return true;
      case 'sal':                                           // salida
        it = itemDentro(p, t);
        if (!it) return null;                               // ya estaba fuera
        var faltan = {};                                    // «V-0231: Torquímetro» → por vale
        (op.falta || []).forEach(function (x) { var m = /^(V-\d{4}): (.+)$/.exec(String(x)); if (m) (faltan[m[1]] = faltan[m[1]] || []).push(m[2]); });
        var noDev = op.noDevuelto || [];
        T_VALES.forEach(function (v) {
          if (v.pid !== p.id || v.abierto > t || v.cerradoReal || (v.it && v.it.cancel) || (cierreVale(v, t) && !v.forzado)) return;
          if (v.tipo === 'herr') {
            if (faltan[v.folio]) { v.forzado = true; v.itemsOrig = v.itemsOrig || v.items; v.items = faltan[v.folio].slice(); v.nota = 'Salió sin presentar: ' + faltan[v.folio].join(', '); }
            else v.cerradoReal = t;
          } else if (v.tipo === 'equipo') {
            if (noDev.indexOf(v.folio) >= 0) { v.forzado = true; v.nota = 'No devolvió el equipo'; }
            else v.cerradoReal = t;
          }
        });
        if (it) { it.salReal = t; it.cS = op.caseta || CASETAS[0]; }        // después de revisar los vales
        var hayFalta = Object.keys(faltan).length > 0 || noDev.length > 0;
        MOVS.unshift({ t: t, p: p, tipo: 's', res: hayFalta ? 'Sin herramienta' : (op.res || 'Permitido'), caseta: op.caseta || CASETAS[0], quien: op.quien || '' });
        return true;
      case 'int':                                           // intento de entrada negado
        p.plan.forEach(function (x) { if (!x.manual && x.ent > t) x.cancel = true; });     // no entra sola más tarde
        if (p.estado === 'vetado' && p.veto && +p.veto.desde < +t - MIN) T_INT.push({ pid: p.id, t: t, caseta: op.caseta || CASETAS[0] });
        MOVS.unshift({ t: t, p: p, tipo: 'e', res: 'Negado · ' + (op.motivo || 'acceso negado'), caseta: op.caseta || CASETAS[0], quien: op.quien || '' });
        return true;
      case 'inc':                                           // incidencia (y veto, si la medida lo es)
        if (!TIPOS_INC[op.tipo]) return null;
        var inc = { nueva: true, folio: op.folio || folioInc(), pid: p.id, tipo: op.tipo, grav: TIPOS_INC[op.tipo].g, fecha: t, accion: op.accion,
          desc: op.desc || TIPOS_INC[op.tipo].d[0], estado: 'En seguimiento', por: op.por || 'Admin. de accesos' };
        T_INC.push(inc);
        if (/Veto/.test(op.accion || '')) {
          var dias = /30/.test(op.accion) ? 30 : (/90/.test(op.accion) ? 90 : 0);
          p.estado = 'vetado';
          p.veto = { desde: t, hasta: dias ? sumarDias(t, dias) : null, motivo: TIPOS_INC[op.tipo].n, alcance: /todas/.test(op.accion) ? 'Todas las plantas del cliente' : 'Esta planta', folio: inc.folio };
          p.plan.forEach(function (x) { if (!x.manual && x.ent > t) x.cancel = true; });   // un vetado no entra
        }
        return inc;
      case 'aut':                                           // permiso de dispositivo o herramienta
        if (!(op.c in p.aut)) return null;
        p.aut[op.c] = !!op.v;
        if (op.c === 'herr' && op.v && !p.herr.length) p.herr = ['Juego de llaves combinadas', 'Taladro rotomartillo'];
        return true;
      case 'reg':                                           // reglas de la demo (se comparten y sobreviven a recargar)
        var r = op.r || {};
        ['anio', 'perm', 'diasSeguidos', 'vale', 'alc'].forEach(function (k) { if (typeof r[k] === 'number' && isFinite(r[k])) REGLAS[k] = r[k]; });
        ['docs', 'cel', 'herr'].forEach(function (k) { if (typeof r[k] === 'boolean') REGLAS[k] = r[k]; });
        if (!TABLA_LEY[REGLAS.anio]) REGLAS.anio = anioAct;
        return true;
      case 'am':                                            // autorizar una salida de material
        T_VALES.forEach(function (v) { if (v.folio === op.folio && v.tipo === 'mat') v.autoriza = 'Autorizado por ' + (op.quien || 'Admin. de accesos'); });
        return true;
      case 'alta':                                          // persona nueva: tarjeta leída en la caseta
        var cod = String(op.codigo || '').replace(/\D/g, '').slice(0, 16);
        if (!cod || op.pid !== 'Q' + cod) return null;
        if (P[op.pid]) return P[op.pid];
        var nueva = {
          id: op.pid, nombre: limpiarNombre(op.nombre) || 'Tarjeta ' + cod, emp: 'QEN', puesto: String(op.puesto || '').slice(0, 60) || 'Personal de planta',
          sup: false, turno: t.getHours() >= 6 && t.getHours() < 18 ? 'D' : 'N', pat: { on: 5, off: 2, h: 9 }, fase: 0,
          idTipo: 'Tarjeta Quantum', idNum: cod.slice(-4), codigo: cod, tarjeta: true,
          aut: { cel: true, tab: false, herr: false, pc: false, auto: false, cam: false }, herr: [], placas: '',
          estado: 'activo', veto: null, plan: [], ints: [], alta: t, mujer: false,
          docs: { induccion: sumarDias(hoy0, 320), medico: sumarDias(hoy0, 250), imss: 'vigente', dc3: [] }
        };
        PERSONAS.push(nueva); P[nueva.id] = nueva;
        return nueva;
      case 'lev': p.estado = 'activo'; p.veto = null; return true;          // levantar veto
      case 'cv':                                            // cerrar vale
        T_VALES.forEach(function (v) { if (v.folio === op.folio && !v.cerradoReal) { v.cerradoReal = t; v.forzado = false; } });
        return true;
      case 'sol':                                           // el guardia pide autorización
        var sol = { u: op.u, pid: p.id, p: p, t: t, motivo: op.motivo || 'Requiere autorización', caseta: op.caseta || CASETAS[0], quien: op.quien || '', estado: 'pendiente', resp: null };
        SOLICITUDES.unshift(sol);
        return sol;
      case 'resp':                                          // el administrador responde
        for (var i = 0; i < SOLICITUDES.length; i++) {
          var x = SOLICITUDES[i];
          if (x.u === op.sol && x.estado === 'pendiente') { x.estado = op.ok ? 'autorizada' : 'negada'; x.resp = { t: t, quien: op.quien || '' }; return x; }
        }
        return null;
    }
    return null;
  }

  // Dentro de la app AVBA Caseta la pantalla no está en el servidor: app-config.js dice a dónde enviar
  var URL_SALA = (window.AVBA_APP && window.AVBA_APP.urlSala) || 'sala.php';
  function limpiarNombre(n) {
    return String(n || '').replace(/[^A-Za-z' .\-\u00C0-\u024F]/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 80);
  }
  /* Persona por el código de su tarjeta (lectura en la app de la caseta) */
  function porCodigo(c) {
    c = String(c || '').replace(/\D/g, '');
    if (!c) return null;
    if (P['Q' + c]) return P['Q' + c];
    for (var i = 0; i < PERSONAS.length; i++) if (PERSONAS[i].codigo === c) return PERSONAS[i];
    return null;
  }
  var SALA = (function () {
    var q = '', s = '';
    try { q = (new URLSearchParams(location.search).get('sala') || '').toUpperCase(); } catch (e) {}
    if (q === 'NO' || q === 'LOCAL') { try { localStorage.removeItem('avba_demo_sala'); } catch (e) {} return ''; }
    if (/^[A-Z0-9]{4,10}$/.test(q)) { try { localStorage.setItem('avba_demo_sala', q); } catch (e) {} return q; }
    try { s = localStorage.getItem('avba_demo_sala') || ''; } catch (e) {}
    return /^[A-Z0-9]{4,10}$/.test(s) ? s : '';
  })();
  var CLAVE = 'avba_demo_accesos_' + semilla;                       // sin sala
  var CLAVE_PEND = 'avba_demo_pend_' + SALA + '_' + semilla;        // con sala: lo que falta subir
  var vistos = {}, oyentes = [], oyentesSala = [], oyentesError = [], aplicadasLocal = 0, opsMem = [];
  var sala = { estado: SALA ? 'conectando' : 'local', n: 0, epoca: null };
  function nuevoId() { return Date.now().toString(36) + Math.random().toString(36).slice(2, 9); }
  function aplicarNueva(op) {
    if (!op || !op.u || vistos[op.u]) return false;
    vistos[op.u] = 1;
    notarHora(op);
    try { aplicar(op); } catch (e) { if (window.console) console.warn('[demo] operación ignorada', e); }
    return true;
  }
  function avisar() { oyentes.forEach(function (f) { try { f(); } catch (x) { if (window.console) console.error(x); } }); }
  function marcar(estado) {
    if (sala.estado === estado) return;
    sala.estado = estado;
    oyentesSala.forEach(function (f) { try { f(estado); } catch (x) {} });
  }

  /* Sin sala: localStorage */
  function leerLocal() { try { var v = JSON.parse(localStorage.getItem(CLAVE) || '[]'); return Array.isArray(v) ? v : null; } catch (e) { return null; } }
  var lsOk = true;                                       // false si localStorage no guarda (lleno o bloqueado)
  function sincronizarLocal() {
    var todas = (lsOk && leerLocal()) || opsMem;
    if (todas.length < aplicadasLocal) { if (lsOk) location.reload(); return false; }   // se reinició en otra pestaña
    var hubo = false;
    for (var i = aplicadasLocal; i < todas.length; i++) if (aplicarNueva(todas[i])) hubo = true;
    aplicadasLocal = todas.length; opsMem = todas;
    if (hubo) tick();
    return hubo;
  }

  /* Con sala: sala.php */
  var pendientes = [], subiendo = false, consultando = false, tConsulta = null;
  function leerPend() { try { var v = JSON.parse(localStorage.getItem(CLAVE_PEND) || '[]'); return Array.isArray(v) ? v : []; } catch (e) { return []; } }
  function guardarPend() { try { localStorage.setItem(CLAVE_PEND, JSON.stringify(pendientes)); } catch (e) {} }
  function subir() {
    if (subiendo || !pendientes.length) return;
    subiendo = true;
    fetch(URL_SALA, { method: 'POST', headers: { 'Content-Type': 'application/json' }, cache: 'no-store',
                      body: JSON.stringify({ sala: SALA, dia: semilla, op: pendientes[0] }) })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        subiendo = false;
        if (j && j.error === 'invalida') {
          var mala = pendientes.shift(); guardarPend();
          oyentesError.forEach(function (f) { try { f(mala); } catch (x) {} });
          subir();
        } else if (j && j.ok) { pendientes.shift(); guardarPend(); marcar('conectado'); subir(); }
        else marcar('sin-conexion');
      })
      .catch(function () { subiendo = false; marcar('sin-conexion'); });
  }
  function consultar() {
    clearTimeout(tConsulta);
    if (consultando) return;
    consultando = true;
    fetch(URL_SALA + '?sala=' + SALA + '&dia=' + semilla + '&desde=' + sala.n, { cache: 'no-store' })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(function (j) {
        consultando = false;
        if (!j || !j.ok) throw new Error('respuesta');
        if (sala.epoca !== null && j.epoca !== sala.epoca) { pendientes = []; guardarPend(); location.reload(); return; }   // se reinició la sala
        sala.epoca = j.epoca;
        var hubo = false;
        (j.ops || []).forEach(function (op) { if (aplicarNueva(op)) hubo = true; });
        sala.n = j.total;
        marcar('conectado');
        if (hubo) { tick(); avisar(); }
        subir();
        programar();
      })
      .catch(function () { consultando = false; marcar('sin-conexion'); programar(); });
  }
  function programar() { clearTimeout(tConsulta); tConsulta = setTimeout(consultar, document.hidden ? 6000 : 1500); }

  function folioLibre(pref, lista) {
    for (var i = 0; i < 50; i++) {
      var f = pref + (5000 + Math.floor(Math.random() * 4999));
      if (!lista.some(function (x) { return x.folio === f; })) return f;
    }
    return pref + (5000 + Math.floor(Math.random() * 4999));
  }
  function registrar(op) {
    op.u = op.u || nuevoId();
    op.t = op.t || Date.now();
    if (op.k === 'ent' && op.herr && !op.folio) op.folio = folioLibre('V-', T_VALES);
    if (op.k === 'inc' && !op.folio) op.folio = folioLibre('INC-', T_INC);
    var r;
    if (SALA) {
      vistos[op.u] = 1;
      notarHora(op);
      try { r = aplicar(op); } catch (e) { r = null; }
      tick();
      pendientes.push(op); guardarPend(); subir();
    } else {
      sincronizarLocal();
      var todas = (lsOk && leerLocal()) || opsMem;
      todas.push(op); opsMem = todas;
      try { localStorage.setItem(CLAVE, JSON.stringify(todas)); } catch (e) { lsOk = false; }
      aplicadasLocal = todas.length;
      vistos[op.u] = 1;
      notarHora(op);
      try { r = aplicar(op); } catch (e) { r = null; }
      tick();
    }
    return r;
  }
  function reiniciar() {
    if (!SALA) { try { localStorage.removeItem(CLAVE); } catch (e) {} location.reload(); return; }
    fetch(URL_SALA, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ sala: SALA, dia: semilla, accion: 'reiniciar' }) })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (!j || !j.ok) throw new Error(); pendientes = []; guardarPend(); location.reload(); })
      .catch(function () { alert('No se pudo reiniciar la sala ' + SALA + ': revisa la conexión e inténtalo de nuevo.'); });
  }

  if (SALA) {
    pendientes = leerPend();
    pendientes.forEach(function (op) { aplicarNueva(op); });         // lo que no alcanzó a subirse
    consultar();
    document.addEventListener('visibilitychange', function () { if (!document.hidden) consultar(); });
  } else {
    window.addEventListener('storage', function (e) {
      if (e.key !== CLAVE && e.key !== null) return;
      if (sincronizarLocal()) avisar();
    });
    sincronizarLocal();
  }
  refrescar(true);
  function revisarDia() { if (claveDia(ahora()) !== claveDia(hoy0)) location.reload(); }
  setInterval(revisarDia, 30000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) revisarDia(); });

  window.DEMO = {
    EMPRESAS: EMPRESAS, EMP: EMP, TIPOS_INC: TIPOS_INC, CORTO_INC: CORTO_INC, ACCIONES: ACCIONES, TIPO_VALE: TIPO_VALE, CASETAS: CASETAS,
    PERSONAS: PERSONAS, P: P, INCIDENCIAS: INCIDENCIAS, VALES: VALES, INTENTOS: INTENTOS, MOVS: MOVS, SOLICITUDES: SOLICITUDES,
    TABLA_LEY: TABLA_LEY, REGLAS: REGLAS, limites: limites, stats: stats, docs: docs, valesAbiertos: valesAbiertos, vetoVigente: vetoVigente,
    candidato: candidato, revisar: revisarEntrada, turnoDe: turnoDe, turnosEnCurso: turnosEnCurso, nombreTurno: nombreTurno,
    tick: tick, registrar: registrar, alCambiar: function (f) { oyentes.push(f); }, reiniciar: reiniciar,
    hash: hash, pl: pl, porCodigo: porCodigo,
    sala: SALA, estadoSala: function () { return sala.estado; }, alCambiarSala: function (f) { oyentesSala.push(f); }, alError: function (f) { oyentesError.push(f); },
    semilla: semilla
  };
})();
