/* ==========================================================================
   Detección automática de turno.

   Hay dos esquemas de trabajo:
     - "8h":  Matutino 06–14, Vespertino 14–22, Nocturno 22–06
     - "12h": Diurno 07–19, Nocturno 19–07

   Cada paramédico tiene un esquema; con la hora actual el sistema sabe en qué
   turno está. Los nocturnos cruzan la medianoche, así que un registro a las
   02:00 del martes pertenece al turno que EMPEZÓ el lunes: eso es lo que
   guarda `fechaTurno`, y es lo que usaremos para agrupar consultas por turno.
   ========================================================================== */

const TURNOS = {
  '8h': [
    { clave: 'matutino',   nombre: 'Matutino',   inicio: 6,  fin: 14 },
    { clave: 'vespertino', nombre: 'Vespertino', inicio: 14, fin: 22 },
    { clave: 'nocturno',   nombre: 'Nocturno',   inicio: 22, fin: 6  },
  ],
  '12h': [
    { clave: 'diurno',       nombre: 'Diurno',   inicio: 7,  fin: 19 },
    { clave: 'nocturno12',   nombre: 'Nocturno', inicio: 19, fin: 7  },
  ],
};

const ESQUEMAS = {
  '8h':  'Turnos de 8 horas',
  '12h': 'Turnos de 12 horas',
};

// ¿La hora (con decimales, p. ej. 13.5 = 13:30) cae dentro del turno?
function horaEnTurno(hora, turno) {
  if (turno.inicio < turno.fin) return hora >= turno.inicio && hora < turno.fin;
  // Cruza la medianoche (p. ej. 22 → 6)
  return hora >= turno.inicio || hora < turno.fin;
}

// Fecha local en formato AAAA-MM-DD (sin pasar por UTC, que movería el día).
function fechaISO(fecha) {
  const a = fecha.getFullYear();
  const m = String(fecha.getMonth() + 1).padStart(2, '0');
  const d = String(fecha.getDate()).padStart(2, '0');
  return `${a}-${m}-${d}`;
}

function detectarTurno(fecha = new Date(), esquema = '8h') {
  const lista = TURNOS[esquema] || TURNOS['8h'];
  const hora = fecha.getHours() + fecha.getMinutes() / 60;
  const turno = lista.find(t => horaEnTurno(hora, t));

  // Momento exacto en que empezó este turno
  const inicio = new Date(fecha);
  inicio.setHours(turno.inicio, 0, 0, 0);
  if (inicio > fecha) inicio.setDate(inicio.getDate() - 1); // empezó ayer

  const duracion = (turno.fin - turno.inicio + 24) % 24; // horas
  const fin = new Date(inicio.getTime() + duracion * 3600000);

  return {
    ...turno,
    esquema,
    duracion,
    inicioFecha: inicio,
    finFecha: fin,
    fechaTurno: fechaISO(inicio),
    horario: `${hh(turno.inicio)} – ${hh(turno.fin)}`,
    minutosRestantes: Math.max(0, Math.round((fin - fecha) / 60000)),
    avance: Math.min(1, (fecha - inicio) / (fin - inicio)),
  };
}

function hh(h) { return String(h).padStart(2, '0') + ':00'; }

function textoDuracion(minutos) {
  const h = Math.floor(minutos / 60), m = minutos % 60;
  if (h === 0) return `${m} min`;
  return m === 0 ? `${h} h` : `${h} h ${m} min`;
}

// Nombre completo para mostrar: en 12 h se aclara, porque "Nocturno" existe
// en los dos esquemas con horarios distintos.
function nombreTurno(t) {
  return t.esquema === '12h' ? `${t.nombre} (12 h)` : t.nombre;
}
