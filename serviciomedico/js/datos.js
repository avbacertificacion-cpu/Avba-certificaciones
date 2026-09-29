/* ==========================================================================
   Almacenamiento del demo (SIN base de datos).

   Todo vive en el localStorage del navegador bajo una sola clave. La primera
   vez se llena con datos de ejemplo. Cada navegador tiene su propia copia:
   lo que se captura en un equipo no se ve en otro.

   Si cambia la estructura de los datos en una etapa nueva, se sube
   VERSION_DATOS y el demo se vuelve a sembrar solo.
   ========================================================================== */

const CLAVE_DATOS = 'avba_serviciomedico_demo';
const VERSION_DATOS = 1;

const DB = {
  leer() {
    try {
      const datos = JSON.parse(localStorage.getItem(CLAVE_DATOS));
      if (datos && datos.version === VERSION_DATOS) return datos;
    } catch (e) { /* datos corruptos o storage bloqueado: se vuelve a sembrar */ }
    return this.restablecer();
  },
  guardar(datos) {
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

  return {
    version: VERSION_DATOS,
    creado: new Date().toISOString(),
    planta: { nombre: 'Planta Industrial AVBA', ubicacion: 'Monterrey, N.L.' },
    usuarios,
    ambulancias,
    trabajadores,
    guardias: [], // registro de entradas de paramédicos a su turno
  };
}
