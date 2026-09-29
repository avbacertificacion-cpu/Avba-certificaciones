/* ==========================================================================
   Almacenamiento del demo (SIN base de datos).

   Todo vive en el localStorage del navegador bajo una sola clave. La primera
   vez se llena con datos de ejemplo. Cada navegador tiene su propia copia:
   lo que se captura en un equipo no se ve en otro.

   Si cambia la estructura de los datos en una etapa nueva, se sube
   VERSION_DATOS y el demo se vuelve a sembrar solo.
   ========================================================================== */

const CLAVE_DATOS = 'avba_serviciomedico_demo';
const VERSION_DATOS = 2;

// Si otra pestaña cambia los datos, se descarta la copia en memoria
if (typeof window !== 'undefined') window.addEventListener('storage', e => { if (e.key === CLAVE_DATOS) DB._cache = null; });

const DB = {
  // Copia en memoria para no releer y convertir el JSON en cada pantalla
  _cache: null,
  leer() {
    if (this._cache) return this._cache;
    try {
      const datos = JSON.parse(localStorage.getItem(CLAVE_DATOS));
      if (datos && datos.version === VERSION_DATOS) return (this._cache = datos);
    } catch (e) { /* datos corruptos o storage bloqueado: se vuelve a sembrar */ }
    return this.restablecer();
  },
  guardar(datos) {
    this._cache = datos;
    try { localStorage.setItem(CLAVE_DATOS, JSON.stringify(datos)); }
    catch (e) { console.warn('No se pudo guardar en el navegador', e); }
  },
  restablecer() {
    const datos = crearDatosEjemplo();
    this.guardar(datos);
    return datos;
  },
  nuevoId(prefijo) {
    return prefijo + '_' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6);
  },
};

/* --------------------------------------------------------------------------
   Datos de ejemplo. Se generan con un azar "con semilla" para que al
   restablecer salgan siempre los mismos trabajadores.
   -------------------------------------------------------------------------- */

function azarConSemilla(semilla) {
  return function () {
    semilla |= 0; semilla = (semilla + 0x6D2B79F5) | 0;
    let t = Math.imul(semilla ^ (semilla >>> 15), 1 | semilla);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

const TIPOS_ALTO_RIESGO = [
  'Trabajo en alturas',
  'Espacios confinados',
  'Trabajo eléctrico',
  'Trabajo en caliente',
  'Izaje de cargas',
];

function crearDatosEjemplo() {
  const azar = azarConSemilla(20260929);
  const elegir = lista => lista[Math.floor(azar() * lista.length)];
  const entre = (min, max) => min + Math.floor(azar() * (max - min + 1));

  const ambulancias = [
    { id: 'amb1', clave: 'AMB-01', tipo: 'Urgencias básicas', placas: 'SMA-4821', estado: 'disponible' },
    { id: 'amb2', clave: 'AMB-02', tipo: 'Urgencias avanzadas', placas: 'SMA-4822', estado: 'disponible' },
    { id: 'amb3', clave: 'AMB-03', tipo: 'Urgencias básicas', placas: 'SMA-5190', estado: 'mantenimiento' },
  ];

  // Usuarios del sistema. Contraseñas en texto plano: es un demo sin servidor.
  const usuarios = [
    { id: 'u_admin', usuario: 'admin', password: 'admin123', rol: 'admin',
      nombre: 'Dra. Laura Méndez Ríos', puesto: 'Coordinadora de Servicio Médico', activo: true },
    { id: 'u_planta', usuario: 'planta', password: 'planta123', rol: 'planta',
      nombre: 'Roberto Salinas Ortiz', puesto: 'Recursos Humanos · Planta', activo: true },

    { id: 'u_pm1', usuario: 'jmedina', password: '1234', rol: 'paramedico', activo: true,
      nombre: 'José Medina Castro', cedula: 'TUM-10482', telefono: '81 1234 5501', esquema: '8h', ambulanciaId: 'amb1' },
    { id: 'u_pm2', usuario: 'agarza', password: '1234', rol: 'paramedico', activo: true,
      nombre: 'Ana Garza Villarreal', cedula: 'TUM-10977', telefono: '81 1234 5502', esquema: '8h', ambulanciaId: 'amb1' },
    { id: 'u_pm3', usuario: 'lrios', password: '1234', rol: 'paramedico', activo: true,
      nombre: 'Luis Ríos Tamez', cedula: 'TUM-11320', telefono: '81 1234 5503', esquema: '8h', ambulanciaId: 'amb2' },
    { id: 'u_pm4', usuario: 'mleal', password: '1234', rol: 'paramedico', activo: true,
      nombre: 'Mariana Leal Cantú', cedula: 'TUM-09854', telefono: '81 1234 5504', esquema: '12h', ambulanciaId: 'amb2' },
    { id: 'u_pm5', usuario: 'csoto', password: '1234', rol: 'paramedico', activo: true,
      nombre: 'Carlos Soto Elizondo', cedula: 'TUM-12066', telefono: '81 1234 5505', esquema: '12h', ambulanciaId: 'amb1' },
  ];

  const nombresH = ['Juan', 'Miguel', 'Pedro', 'Jorge', 'Ricardo', 'Fernando', 'Raúl', 'Héctor', 'Arturo', 'Daniel', 'Óscar', 'Eduardo', 'Alejandro', 'Francisco', 'Sergio'];
  const nombresM = ['María', 'Guadalupe', 'Patricia', 'Sofía', 'Claudia', 'Verónica', 'Adriana', 'Rosa', 'Elena', 'Gabriela'];
  const apellidos = ['Hernández', 'García', 'Martínez', 'López', 'González', 'Rodríguez', 'Pérez', 'Sánchez', 'Ramírez', 'Torres', 'Flores', 'Rivera', 'Gómez', 'Díaz', 'Reyes', 'Morales', 'Cruz', 'Ortiz', 'Gutiérrez', 'Chávez', 'Vázquez', 'Castillo', 'Jiménez', 'Moreno', 'Treviño', 'Cavazos'];
  const puestos = [
    { puesto: 'Operador de grúa',          area: 'Producción',     riesgo: ['Izaje de cargas', 'Trabajo en alturas'] },
    { puesto: 'Soldador',                  area: 'Producción',     riesgo: ['Trabajo en caliente'] },
    { puesto: 'Electricista industrial',   area: 'Mantenimiento',  riesgo: ['Trabajo eléctrico', 'Trabajo en alturas'] },
    { puesto: 'Montador de estructuras',   area: 'Producción',     riesgo: ['Trabajo en alturas'] },
    { puesto: 'Mecánico',                  area: 'Mantenimiento',  riesgo: ['Espacios confinados'] },
    { puesto: 'Técnico de tanques',        area: 'Mantenimiento',  riesgo: ['Espacios confinados', 'Trabajo en caliente'] },
    { puesto: 'Operador de montacargas',   area: 'Almacén',        riesgo: [] },
    { puesto: 'Almacenista',               area: 'Almacén',        riesgo: [] },
    { puesto: 'Inspector de calidad',      area: 'Calidad',        riesgo: [] },
    { puesto: 'Supervisor de seguridad',   area: 'Seguridad',      riesgo: ['Trabajo en alturas'] },
    { puesto: 'Ayudante general',          area: 'Producción',     riesgo: [] },
    { puesto: 'Auxiliar administrativo',   area: 'Administración', riesgo: [] },
  ];
  const sangre = ['O+', 'O+', 'O+', 'A+', 'A+', 'B+', 'O-', 'AB+', 'A-'];
  const turnosTrab = ['matutino', 'matutino', 'vespertino', 'vespertino', 'nocturno', 'diurno', 'nocturno12'];
  const alergias = ['Ninguna', 'Ninguna', 'Ninguna', 'Ninguna', 'Penicilina', 'Sulfas', 'AINE (ibuprofeno)', 'Polvo'];

  const hoy = new Date();
  const trabajadores = [];
  for (let i = 0; i < 40; i++) {
    const sexo = azar() < 0.78 ? 'H' : 'M';
    const p = elegir(puestos);
    const edad = entre(21, 58);
    const nacimiento = new Date(hoy.getFullYear() - edad, entre(0, 11), entre(1, 28));
    const alta = new Date(hoy.getFullYear() - entre(0, 8), entre(0, 11), entre(1, 28));
    const talla = sexo === 'H' ? entre(162, 186) : entre(150, 172);
    // El IMC se reparte de forma realista: la mayoría entre 22 y 31
    const imc = 21 + azar() * 12;
    const peso = Math.round(imc * (talla / 100) ** 2);

    trabajadores.push({
      id: 't' + (i + 1),
      numEmpleado: 'EMP-' + String(1040 + i * 7).padStart(5, '0'),
      nombre: sexo === 'H' ? elegir(nombresH) : elegir(nombresM),
      apellidos: elegir(apellidos) + ' ' + elegir(apellidos),
      sexo,
      fechaNacimiento: fechaISO(nacimiento),
      puesto: p.puesto,
      area: p.area,
      turno: elegir(turnosTrab),
      altoRiesgo: p.riesgo.length > 0,
      tiposAltoRiesgo: p.riesgo,
      tipoSangre: elegir(sangre),
      alergias: elegir(alergias),
      antecedentes: {
        diabetes: azar() < 0.1,
        hipertension: edad > 40 && azar() < 0.25,
        cardiopatia: edad > 45 && azar() < 0.06,
        asma: azar() < 0.05,
        tabaquismo: azar() < 0.22,
      },
      peso, talla,
      telefonoEmergencia: '81 ' + entre(1000, 9999) + ' ' + entre(1000, 9999),
      fechaAlta: fechaISO(alta),
      activo: true,
    });
  }

  const medicamentos = crearMedicamentos();
  const historial = crearHistorial({ azar, elegir, entre, trabajadores, usuarios, medicamentos, hoy });

  return {
    version: VERSION_DATOS,
    creado: new Date().toISOString(),
    planta: { nombre: 'Planta Industrial AVBA', ubicacion: 'Monterrey, N.L.' },
    config: { limites: { ...LIMITES_PRESION } },
    usuarios,
    ambulancias,
    trabajadores,
    medicamentos,
    movimientos: historial.movimientos,
    consultas: historial.consultas,
    evaluaciones: historial.evaluaciones,
    guardias: [], // registro de entradas de paramédicos a su turno
  };
}

function crearMedicamentos() {
  // [nombre, presentación, unidad, existencia, mínimo, días para caducar]
  const lista = [
    ['Paracetamol 500 mg', 'Caja 20 tabletas', 'tabletas', 186, 60, 420],
    ['Ibuprofeno 400 mg', 'Caja 20 tabletas', 'tabletas', 44, 60, 300],
    ['Naproxeno 250 mg', 'Caja 30 tabletas', 'tabletas', 120, 40, 510],
    ['Diclofenaco gel 1%', 'Tubo 50 g', 'tubos', 9, 5, 260],
    ['Loratadina 10 mg', 'Caja 10 tabletas', 'tabletas', 58, 20, 38],
    ['Omeprazol 20 mg', 'Caja 14 cápsulas', 'cápsulas', 70, 28, 390],
    ['Butilhioscina 10 mg', 'Caja 20 grageas', 'grageas', 16, 20, 220],
    ['Metamizol 500 mg', 'Caja 10 tabletas', 'tabletas', 64, 20, 610],
    ['Captopril 25 mg', 'Caja 30 tabletas', 'tabletas', 25, 10, 180],
    ['Ambroxol jarabe', 'Frasco 120 ml', 'frascos', 6, 4, 25],
    ['Suero oral', 'Sobre 27.9 g', 'sobres', 48, 30, 700],
    ['Lágrimas artificiales', 'Gotero 15 ml', 'goteros', 3, 4, 330],
    ['Solución salina 0.9%', 'Bolsa 500 ml', 'bolsas', 22, 10, 540],
    ['Gasas estériles', 'Paquete 10 piezas', 'paquetes', 35, 15, 900],
    ['Venda elástica 10 cm', 'Pieza', 'piezas', 14, 8, 1200],
    ['Sulfadiazina de plata', 'Tubo 28 g', 'tubos', 0, 3, 400],
  ];
  const hoy = new Date();
  return lista.map(([nombre, presentacion, unidad, stock, minimo, dias], i) => {
    const cad = new Date(hoy); cad.setDate(cad.getDate() + dias);
    return { id: 'm' + (i + 1), nombre, presentacion, unidad, stock, minimo,
      caducidad: fechaISO(cad), lote: 'L' + (24100 + i * 37), activo: true };
  });
}

/* Historial de consultas y evaluaciones de los últimos 60 días.
   Algunos trabajadores reciben "tendencias" a propósito para que el módulo
   de alertas tenga algo que detectar. */
function crearHistorial({ azar, elegir, entre, trabajadores, usuarios, medicamentos, hoy }) {
  const paramedicos = usuarios.filter(u => u.rol === 'paramedico');
  const med = nombre => medicamentos.find(m => m.nombre.startsWith(nombre));

  // Tendencias: presión alta, glucosa alta o dolor recurrente
  const riesgoAR = trabajadores.filter(t => t.altoRiesgo);
  const tendencia = {};
  [riesgoAR[0], riesgoAR[3], riesgoAR[7], trabajadores[11]].forEach(t => t && (tendencia[t.id] = 'presion'));
  [trabajadores[5], trabajadores[19], trabajadores[26]].forEach(t => t && (tendencia[t.id] = 'glucosa'));
  [trabajadores[8], trabajadores[30]].forEach(t => t && (tendencia[t.id] = 'lumbar'));
  // Los de glucosa tienen además sobrepeso marcado
  [trabajadores[5], trabajadores[19]].forEach(t => { t.peso = Math.round(33 * (t.talla / 100) ** 2); });

  // Presión "de base" de cada trabajador
  const base = {};
  trabajadores.forEach(t => {
    let b = entre(108, 128);
    if (t.antecedentes.hipertension) b += 12;
    if (tendencia[t.id] === 'presion') b = entre(146, 156);
    base[t.id] = b;
  });
  const tomarPresion = t => {
    const sis = base[t.id] + entre(-8, 12);
    return { sis, dia: Math.round(sis * 0.63 + entre(-4, 7)) };
  };

  // Recetas típicas por motivo: [medicamento, cantidad]
  const recetas = {
    'Cefalea': [['Paracetamol', 4], ['Metamizol', 2]],
    'Dolor lumbar / muscular': [['Naproxeno', 6], ['Diclofenaco', 1], ['Ibuprofeno', 6]],
    'Herida o laceración': [['Gasas', 1], ['Solución salina', 1]],
    'Quemadura': [['Sulfadiazina', 1], ['Gasas', 1]],
    'Cuerpo extraño en ojo': [['Lágrimas', 1], ['Solución salina', 1]],
    'Malestar gastrointestinal': [['Butilhioscina', 4], ['Omeprazol', 7], ['Suero oral', 2]],
    'Infección respiratoria': [['Paracetamol', 6], ['Ambroxol', 1], ['Loratadina', 3]],
    'Control de presión arterial': [['Captopril', 2]],
    'Mareo / lipotimia': [['Suero oral', 2]],
    'Reacción alérgica': [['Loratadina', 3]],
    'Golpe o contusión': [['Ibuprofeno', 6], ['Venda elástica', 1]],
  };
  const diagnosticos = {
    'Cefalea': 'Cefalea tensional', 'Dolor lumbar / muscular': 'Lumbalgia mecánica',
    'Herida o laceración': 'Herida superficial en mano', 'Quemadura': 'Quemadura de primer grado',
    'Cuerpo extraño en ojo': 'Cuerpo extraño corneal (retirado)', 'Malestar gastrointestinal': 'Gastritis aguda',
    'Infección respiratoria': 'Rinofaringitis aguda', 'Control de presión arterial': 'Hipertensión arterial en control',
    'Mareo / lipotimia': 'Lipotimia por deshidratación', 'Reacción alérgica': 'Rinitis alérgica',
    'Control de glucosa': 'Hiperglucemia en estudio', 'Golpe o contusión': 'Contusión simple',
  };
  const motivosComunes = ['Cefalea', 'Cefalea', 'Dolor lumbar / muscular', 'Dolor lumbar / muscular', 'Herida o laceración',
    'Malestar gastrointestinal', 'Infección respiratoria', 'Infección respiratoria', 'Cuerpo extraño en ojo', 'Golpe o contusión',
    'Quemadura', 'Reacción alérgica', 'Mareo / lipotimia'];

  const consultas = [], evaluaciones = [], movimientos = [];
  const ahora = hoy.getTime();

  const momento = (dia, hora, min) => { const f = new Date(hoy); f.setDate(f.getDate() - dia); f.setHours(hora, min, 0, 0); return f; };
  const infoTurno = (fecha, pm) => { const t = detectarTurno(fecha, pm.esquema); return { turno: t.clave, esquema: t.esquema, fechaTurno: t.fechaTurno }; };

  for (let dia = 60; dia >= 0; dia--) {
    const finDeSemana = [0, 6].includes(momento(dia, 12, 0).getDay());

    // Evaluaciones de alto riesgo: la mayoría al inicio de cada turno
    riesgoAR.forEach(t => {
      if (azar() > (finDeSemana ? 0.2 : 0.45)) return;
      const hora = elegir([6, 6, 7, 7, 8, 14, 14, 15, 22]);
      const fecha = momento(dia, hora, entre(0, 50));
      if (fecha.getTime() > ahora) return;
      const pm = elegir(paramedicos);
      const { sis, dia: dias } = tomarPresion(t);
      evaluaciones.push({
        id: 'e' + (evaluaciones.length + 1), trabajadorId: t.id, paramedicoId: pm.id, ambulanciaId: pm.ambulanciaId,
        fecha: fecha.toISOString(), ...infoTurno(fecha, pm),
        tipoTrabajo: elegir(t.tiposAltoRiesgo), sistolica: sis, diastolica: dias, fc: entre(62, 96),
        dictamen: dictaminar(sis, dias), observaciones: '',
      });
    });

    // Consultas
    const n = finDeSemana ? entre(0, 3) : entre(3, 8);
    for (let k = 0; k < n; k++) {
      let t = elegir(trabajadores), motivo = elegir(motivosComunes);
      // Los trabajadores con tendencia aparecen más seguido con su motivo
      if (azar() < 0.22) {
        const idTend = elegir(Object.keys(tendencia));
        t = trabajadores.find(x => x.id === idTend);
        motivo = { presion: 'Control de presión arterial', glucosa: 'Control de glucosa', lumbar: 'Dolor lumbar / muscular' }[tendencia[t.id]];
      }
      const fecha = momento(dia, elegir([7, 9, 10, 11, 12, 13, 15, 16, 17, 19, 20, 23, 2, 4]), entre(0, 59));
      if (fecha.getTime() > ahora) continue;
      const pm = elegir(paramedicos);
      const { sis, dia: dias } = tomarPresion(t);
      const glucosaAlta = tendencia[t.id] === 'glucosa';
      const conGlucosa = glucosaAlta || t.antecedentes.diabetes || azar() < 0.25;
      const receta = (recetas[motivo] ? [elegir(recetas[motivo])] : [])
        .map(([nombre, cant]) => ({ medicamentoId: med(nombre).id, cantidad: cant, indicaciones: 'Según indicación' }));
      const id = 'c' + (consultas.length + 1);
      consultas.push({
        id, trabajadorId: t.id, paramedicoId: pm.id, ambulanciaId: pm.ambulanciaId,
        fecha: fecha.toISOString(), ...infoTurno(fecha, pm),
        signos: {
          sistolica: sis, diastolica: dias, fc: entre(64, 100), fr: entre(14, 20),
          temperatura: motivo === 'Infección respiratoria' ? 37.4 + entre(0, 12) / 10 : 36.2 + entre(0, 6) / 10,
          spo2: entre(95, 99),
          glucosa: !conGlucosa ? null : glucosaAlta ? entre(128, 175) : t.antecedentes.diabetes ? entre(98, 132) : entre(78, 104),
          ayuno: conGlucosa ? azar() < 0.7 : null,
          peso: t.peso, talla: t.talla,
        },
        motivo, descripcion: '', checklist: Object.fromEntries(CHECKLIST.map(c => [c.clave, !!c.obligatorio || azar() < 0.5])),
        diagnostico: diagnosticos[motivo] || 'En valoración', receta,
        destino: azar() < 0.86 ? 'regresa' : elegir(['observacion', 'reposo', 'reposo', 'traslado']),
        notas: '',
      });
      receta.forEach(r => movimientos.push({
        id: 'mv' + (movimientos.length + 1), medicamentoId: r.medicamentoId, tipo: 'salida', cantidad: r.cantidad,
        fecha: fecha.toISOString(), usuarioId: pm.id, consultaId: id, nota: 'Entregado en consulta',
      }));
    }
  }

  // Una entrada de inventario al inicio del periodo por medicamento
  medicamentos.forEach(m => {
    const f = new Date(hoy); f.setDate(f.getDate() - 61); f.setHours(9, 0, 0, 0);
    movimientos.unshift({ id: 'mv0_' + m.id, medicamentoId: m.id, tipo: 'entrada', cantidad: m.stock + 40,
      fecha: f.toISOString(), usuarioId: 'u_admin', consultaId: null, nota: 'Compra inicial' });
  });

  return { consultas, evaluaciones, movimientos };
}
