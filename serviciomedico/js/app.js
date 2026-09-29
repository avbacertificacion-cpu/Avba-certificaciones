/* ==========================================================================
   Panel principal: menú según el rol, navegación y pantalla de inicio.
   Cada sección es una "ruta" (#inicio, #trabajadores, …) que se dibuja
   dentro de <main id="contenido">.
   ========================================================================== */

const yo = usuarioActual();
if (!yo) location.replace('index.html');

// Íconos (trazos de 16×16, mismo estilo que AVBA)
const ICONOS = {
  inicio:       '<path d="M2 7l6-5 6 5v7H2z"/><path d="M6 14V9h4v5"/>',
  tablero:      '<path d="M2 14h12M4 11V7M8 11V3M12 11V5"/>',
  consultas:    '<rect x="3" y="2" width="10" height="13" rx="1.5"/><path d="M6 2v2h4V2M5.5 8l1.5 1.5 3-3M5.5 12h5"/>',
  altoriesgo:   '<path d="M8 1.5l6.5 12H1.5z"/><path d="M8 6v3.5M8 11.5v.5"/>',
  alertas:      '<path d="M8 14s-5.5-3.3-5.5-7.2A3 3 0 0 1 8 5a3 3 0 0 1 5.5 1.8C13.5 10.7 8 14 8 14z"/><path d="M4.5 8h2l1-1.5 1.5 3 1-1.5h1.5"/>',
  trabajadores: '<path d="M5 7a3 3 0 100-6 3 3 0 000 6zM1 14c0-2.8 1.8-5 4-5s4 2.2 4 5M11 7a2.5 2.5 0 100-5M12 9c1.8.4 3 2.4 3 5"/>',
  paramedicos:  '<circle cx="8" cy="4.5" r="2.5"/><path d="M3 14.5c0-3 2.2-5 5-5s5 2 5 5"/><path d="M8 10.5v3M6.5 12h3"/>',
  medicamentos: '<rect x="1.5" y="5.5" width="13" height="5" rx="2.5" transform="rotate(-35 8 8)"/><path d="M6 5.2l3.8 5.5"/>',
  ambulancias:  '<path d="M1.5 11.5V5h8v6.5M9.5 7h3l2 2.5v2h-5"/><circle cx="4.5" cy="12" r="1.5"/><circle cx="11.5" cy="12" r="1.5"/><path d="M5.5 6.5v3M4 8h3"/>',
  reloj:        '<circle cx="8" cy="8" r="6.5"/><path d="M8 4v4l2.5 1.5"/>',
  corazon:      '<path d="M8 14s-5.5-3.3-5.5-7.2A3 3 0 0 1 8 5a3 3 0 0 1 5.5 1.8C13.5 10.7 8 14 8 14z"/>',
};
const icono = (n, extra = '') => `<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" ${extra}>${ICONOS[n]}</svg>`;

/* --------------------------------------------------------------------------
   Rutas. `etapa` indica en qué etapa del plan se construye cada sección;
   las que aún no existen muestran una página de "próximamente".
   -------------------------------------------------------------------------- */
const RUTAS = {
  inicio:       { seccion: 'Principal', titulo: 'Inicio',               sub: 'Tu turno y resumen del día',                      roles: ['admin', 'paramedico', 'planta'], etapa: 1, dibujar: dibujarInicio },
  tablero:      { seccion: 'Principal', titulo: 'Tablero',              sub: 'Indicadores y gráficas',                          roles: ['admin', 'planta'],              etapa: 5,
                  pronto: 'Gráficas de consultas por día y por turno, evaluaciones de alto riesgo, motivos de consulta más frecuentes, trabajadores con alertas y medicamentos por agotarse.' },
  consultas:    { seccion: 'Atención',  titulo: 'Consultas',            sub: 'Checklist digital de consulta',                   roles: ['admin', 'paramedico'],          etapa: 3,
                  pronto: 'Consulta con checklist digital: signos vitales, motivo, exploración, diagnóstico y receta. Al guardar, los medicamentos entregados se descuentan del inventario.' },
  altoriesgo:   { seccion: 'Atención',  titulo: 'Alto riesgo',          sub: 'Presión arterial antes de trabajos de alto riesgo', roles: ['admin', 'paramedico'],        etapa: 4,
                  pronto: 'Registro de presión por trabajador antes de trabajar en alturas, espacios confinados, trabajo eléctrico, en caliente o izaje. El sistema dictamina Apto (<140/90), Revalorar (140/90–159/99) o No apto (≥160/100).' },
  alertas:      { seccion: 'Atención',  titulo: 'Alertas de salud',     sub: 'Trabajadores propensos a enfermedades',           roles: ['admin', 'planta'],              etapa: 4,
                  pronto: 'Alertas para valoración médica calculadas con el historial: posible hipertensión, posible diabetes, obesidad, riesgo cardiometabólico y padecimientos recurrentes. Cada alerta explica por qué salió.' },
  trabajadores: { seccion: 'Registros', titulo: 'Trabajadores',         sub: 'Alta y expediente de los trabajadores de planta',  roles: ['admin', 'paramedico', 'planta'], etapa: 2,
                  pronto: 'La planta da de alta a sus trabajadores. Cada uno tendrá expediente con antecedentes, alergias, historial de consultas y gráfica de su presión arterial.' },
  paramedicos:  { seccion: 'Registros', titulo: 'Paramédicos',          sub: 'Alta de paramédicos, usuarios y contraseñas',     roles: ['admin'],                        etapa: 2,
                  pronto: 'Alta de paramédicos con su usuario y contraseña, esquema de turno (8 h o 12 h) y ambulancia asignada.' },
  medicamentos: { seccion: 'Registros', titulo: 'Medicamentos',         sub: 'Inventario y salidas',                            roles: ['admin', 'paramedico'],          etapa: 3,
                  pronto: 'Existencias, entradas, caducidades y alerta de stock mínimo. Cada salida queda ligada a la consulta en la que se entregó.' },
  ambulancias:  { seccion: 'Registros', titulo: 'Ambulancias',          sub: 'Unidades y asignaciones',                         roles: ['admin'],                        etapa: 5,
                  pronto: 'Unidades con su estado (disponible, en servicio, mantenimiento) y qué paramédico la tiene asignada en cada turno.' },
};

/* --------------------------------------------------------------------------
   Arranque
   -------------------------------------------------------------------------- */
function iniciarApp() {
  document.getElementById('sb-nombre').textContent = yo.nombre;
  document.getElementById('sb-rol').textContent = ROLES[yo.rol];
  document.getElementById('sb-avatar').textContent = iniciales(yo.nombre);

  if (yo.rol === 'admin') {
    const b = document.getElementById('btn-restablecer');
    b.style.display = 'flex';
    b.onclick = () => {
      if (!confirm('¿Borrar todo lo capturado y volver a los datos de ejemplo?')) return;
      DB.restablecer();
      aviso('Demo restablecido');
      navegar();
    };
  }

  // Menú en celular
  const sidebar = document.getElementById('sidebar'), velo = document.getElementById('velo');
  const cerrarMenu = () => { sidebar.classList.remove('abierta'); velo.classList.remove('visible'); };
  document.getElementById('btn-menu').onclick = () => { sidebar.classList.add('abierta'); velo.classList.add('visible'); };
  velo.onclick = cerrarMenu;
  document.getElementById('menu').addEventListener('click', e => { if (e.target.closest('a')) cerrarMenu(); });

  dibujarMenu();
  window.addEventListener('hashchange', navegar);
  navegar();

  // El turno cambia con la hora: se refresca cada minuto
  setInterval(() => {
    pintarTurnoTopbar();
    if (rutaActual() === 'inicio') dibujarInicio();
  }, 60000);
}

function rutaActual() {
  const r = location.hash.replace('#', '');
  return RUTAS[r] && RUTAS[r].roles.includes(yo.rol) ? r : 'inicio';
}

function dibujarMenu() {
  let html = '', seccion = '';
  for (const [clave, r] of Object.entries(RUTAS)) {
    if (!r.roles.includes(yo.rol)) continue;
    if (r.seccion !== seccion) { seccion = r.seccion; html += `<div class="sb-section">${seccion}</div>`; }
    const pronto = r.dibujar ? '' : `<span class="sb-pronto">Etapa ${r.etapa}</span>`;
    html += `<a class="sb-item" href="#${clave}" data-ruta="${clave}">${icono(clave)}${r.titulo}${pronto}</a>`;
  }
  document.getElementById('menu').innerHTML = html;
}

function navegar() {
  const clave = rutaActual();
  const r = RUTAS[clave];
  document.querySelectorAll('.sb-item[data-ruta]').forEach(a => a.classList.toggle('active', a.dataset.ruta === clave));
  document.getElementById('topbar-t').textContent = r.titulo;
  document.getElementById('topbar-s').textContent = r.sub;
  document.title = `${r.titulo} — AVBA Servicio Médico`;
  pintarTurnoTopbar();
  if (r.dibujar) r.dibujar();
  else dibujarProximamente(clave, r);
  window.scrollTo(0, 0);
}

/* --------------------------------------------------------------------------
   Turno en la barra superior
   -------------------------------------------------------------------------- */
function pintarTurnoTopbar() {
  const t = detectarTurno(new Date(), yo.esquema || '8h');
  const datos = DB.leer();
  const amb = yo.rol === 'paramedico' ? datos.ambulancias.find(a => a.id === yo.ambulanciaId) : null;
  document.getElementById('topbar-turno').innerHTML =
    `${icono('reloj')}<span><span class="txt-largo">Turno </span><b>${esc(nombreTurno(t))}</b>` +
    `<span class="txt-largo"> · ${t.horario}</span>${amb ? ` · ${esc(amb.clave)}` : ''}</span>`;
}

/* --------------------------------------------------------------------------
   INICIO
   -------------------------------------------------------------------------- */
function dibujarInicio() {
  const datos = DB.leer();
  const c = document.getElementById('contenido');
  c.innerHTML = yo.rol === 'paramedico' ? inicioParamedico(datos) : inicioGeneral(datos);
}

function inicioParamedico(datos) {
  const t = detectarTurno(new Date(), yo.esquema);
  const amb = datos.ambulancias.find(a => a.id === yo.ambulanciaId);
  const companeros = paramedicosEnTurno(datos).filter(p => p.usuario.id !== yo.id);
  const misGuardias = datos.guardias.filter(g => g.usuarioId === yo.id).slice(-6).reverse();

  return `
    <section class="turno-hero">
      <div>
        <div class="turno-hero-l">Turno detectado automáticamente</div>
        <div class="turno-hero-t">${esc(nombreTurno(t))}</div>
        <div class="turno-hero-s">${t.horario} · ${ESQUEMAS[t.esquema]} · quedan ${textoDuracion(t.minutosRestantes)}</div>
        <div class="barra"><div style="width:${Math.round(t.avance * 100)}%"></div></div>
      </div>
      <div class="turno-hero-datos">
        <div class="turno-dato"><div class="turno-dato-l">Ambulancia</div><div class="turno-dato-v">${amb ? esc(amb.clave) : 'Sin asignar'}</div></div>
        <div class="turno-dato"><div class="turno-dato-l">Unidad</div><div class="turno-dato-v">${amb ? esc(amb.tipo) : '—'}</div></div>
        <div class="turno-dato"><div class="turno-dato-l">Entrada</div><div class="turno-dato-v">${misGuardias[0] ? horaCorta(misGuardias[0].entrada) : '—'}</div></div>
      </div>
    </section>

    <div class="accesos" style="margin-bottom:16px">
      ${acceso('consultas', 'c-azul', 'Nueva consulta', 'Checklist digital y receta')}
      ${acceso('altoriesgo', 'c-ambar', 'Alto riesgo', 'Tomar presión antes de trabajar')}
      ${acceso('trabajadores', 'c-verde', 'Trabajadores', 'Buscar expediente')}
      ${acceso('medicamentos', 'c-morado', 'Medicamentos', 'Revisar existencias')}
    </div>

    <div class="grid grid-2">
      <div class="card">
        <div class="card-hdr"><div><div class="card-hdr-t">Compañeros en turno</div><div class="card-hdr-s">Paramédicos que ya iniciaron sesión en el turno en curso</div></div></div>
        ${tablaEnTurno(companeros, datos)}
      </div>
      <div class="card">
        <div class="card-hdr"><div><div class="card-hdr-t">Mis guardias recientes</div><div class="card-hdr-s">Se registran solas al iniciar sesión</div></div></div>
        <div class="tabla-wrap"><table>
          <thead><tr><th>Fecha de turno</th><th>Turno</th><th>Entrada</th></tr></thead>
          <tbody>${misGuardias.map(g => `<tr>
            <td>${fechaLarga(g.fechaTurno)}</td>
            <td><span class="badge badge-azul">${esc(nombreTurno(turnoPorClave(g.esquema, g.turno)))}</span></td>
            <td class="nowrap">${horaCorta(g.entrada)}</td></tr>`).join('') || '<tr><td colspan="3" class="vacio">Sin guardias</td></tr>'}
          </tbody></table></div>
      </div>
    </div>`;
}

function inicioGeneral(datos) {
  const activos = datos.trabajadores.filter(t => t.activo);
  const altoRiesgo = activos.filter(t => t.altoRiesgo).length;
  const paramedicos = datos.usuarios.filter(u => u.rol === 'paramedico' && u.activo);
  const enTurno = paramedicosEnTurno(datos);
  const ambDisp = datos.ambulancias.filter(a => a.estado === 'disponible').length;

  const stats = yo.rol === 'admin' ? `
      ${stat('trabajadores', 'c-azul', activos.length, 'Trabajadores activos')}
      ${stat('altoriesgo', 'c-ambar', altoRiesgo, 'Hacen trabajos de alto riesgo')}
      ${stat('paramedicos', 'c-verde', `${enTurno.length}<span style="font-size:14px;color:var(--texto-hint)"> / ${paramedicos.length}</span>`, 'Paramédicos en turno')}
      ${stat('ambulancias', 'c-morado', `${ambDisp}<span style="font-size:14px;color:var(--texto-hint)"> / ${datos.ambulancias.length}</span>`, 'Ambulancias disponibles')}` : `
      ${stat('trabajadores', 'c-azul', activos.length, 'Trabajadores activos')}
      ${stat('altoriesgo', 'c-ambar', altoRiesgo, 'Hacen trabajos de alto riesgo')}
      ${stat('paramedicos', 'c-verde', enTurno.length, 'Paramédicos en turno')}
      ${stat('inicio', 'c-morado', new Set(activos.map(t => t.area)).size, 'Áreas de la planta')}`;

  const porTurno = {};
  activos.forEach(t => { porTurno[t.turno] = (porTurno[t.turno] || 0) + 1; });

  return `
    <div class="grid grid-stats">${stats}</div>

    <div class="grid grid-2">
      <div class="card">
        <div class="card-hdr"><div><div class="card-hdr-t">Turno en curso</div><div class="card-hdr-s">Detectado con la hora de este equipo · ${new Date().toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' })}</div></div></div>
        ${lineaTurnos('8h')}
        ${lineaTurnos('12h')}
      </div>
      <div class="card">
        <div class="card-hdr"><div><div class="card-hdr-t">Paramédicos en turno ahora</div><div class="card-hdr-s">Se registran al iniciar sesión</div></div></div>
        ${tablaEnTurno(enTurno, datos)}
      </div>
    </div>

    <div class="card">
      <div class="card-hdr"><div><div class="card-hdr-t">Trabajadores por turno</div><div class="card-hdr-s">${esc(datos.planta.nombre)} · ${esc(datos.planta.ubicacion)}</div></div></div>
      <div class="tabla-wrap"><table>
        <thead><tr><th>Turno</th><th>Horario</th><th>Esquema</th><th>Trabajadores</th></tr></thead>
        <tbody>${Object.entries(TURNOS).flatMap(([esq, lista]) => lista.map(t => `<tr>
          <td><b>${esc(nombreTurno({ ...t, esquema: esq }))}</b></td>
          <td>${hh(t.inicio)} – ${hh(t.fin)}</td>
          <td>${ESQUEMAS[esq]}</td>
          <td>${porTurno[t.clave] || 0}</td></tr>`)).join('')}
        </tbody></table></div>
    </div>`;
}

/* --------------------------------------------------------------------------
   Piezas reutilizables
   -------------------------------------------------------------------------- */

// Guardias cuyo turno sigue en curso en este momento
function paramedicosEnTurno(datos) {
  const ahora = new Date();
  return datos.guardias
    .filter(g => {
      const t = detectarTurno(ahora, g.esquema);
      return t.clave === g.turno && t.fechaTurno === g.fechaTurno;
    })
    .map(g => ({ guardia: g, usuario: datos.usuarios.find(u => u.id === g.usuarioId) }))
    .filter(p => p.usuario);
}

function tablaEnTurno(lista, datos) {
  if (!lista.length) return `<div class="vacio">Nadie más ha iniciado sesión en este turno.<br>
    <span style="font-size:12px">Prueba entrar como paramédico en otra pestaña y regresa aquí.</span></div>`;
  return `<div class="tabla-wrap"><table>
    <thead><tr><th>Paramédico</th><th>Turno</th><th>Ambulancia</th><th>Entrada</th></tr></thead>
    <tbody>${lista.map(({ guardia: g, usuario: u }) => {
      const amb = datos.ambulancias.find(a => a.id === g.ambulanciaId);
      return `<tr><td><b>${esc(u.nombre)}</b></td>
        <td><span class="badge badge-verde"><span class="punto"></span>${esc(nombreTurno(turnoPorClave(g.esquema, g.turno)))}</span></td>
        <td>${amb ? esc(amb.clave) : '—'}</td><td class="nowrap">${horaCorta(g.entrada)}</td></tr>`;
    }).join('')}</tbody></table></div>`;
}

// Barra de 24 h con los turnos del esquema y el actual resaltado
function lineaTurnos(esquema) {
  const actual = detectarTurno(new Date(), esquema);
  const tramos = [];
  TURNOS[esquema].forEach(t => {
    if (t.inicio < t.fin) tramos.push({ t, desde: t.inicio, hasta: t.fin });
    else { tramos.push({ t, desde: t.inicio, hasta: 24 }); tramos.push({ t, desde: 0, hasta: t.fin }); }
  });
  tramos.sort((a, b) => a.desde - b.desde);
  const ahora = new Date(), pos = (ahora.getHours() + ahora.getMinutes() / 60) / 24 * 100;
  return `
    <div class="linea-titulo">${ESQUEMAS[esquema]}</div>
    <div class="linea-turnos">
      ${tramos.map(x => `<div class="${x.t.clave === actual.clave ? 'actual' : ''}" style="flex:${x.hasta - x.desde}">${x.hasta - x.desde >= 4 ? esc(x.t.nombre) : ''}</div>`).join('')}
      <span class="marca-ahora" style="left:${pos}%"></span>
    </div>
    <div class="linea-horas"><span>00:00</span><span>06:00</span><span>12:00</span><span>18:00</span><span>24:00</span></div>`;
}

function dibujarProximamente(clave, r) {
  document.getElementById('contenido').innerHTML = `
    <div class="card pronto">
      <div class="stat-icon c-azul">${icono(clave)}</div>
      <span class="badge badge-azul" style="margin-bottom:12px">Etapa ${r.etapa} del plan</span>
      <h2>${esc(r.titulo)}</h2>
      <p>${esc(r.pronto)}</p>
      <p style="color:var(--texto-hint);font-size:12.5px">Esta sección se construye en la etapa ${r.etapa}.</p>
    </div>`;
}

function stat(ic, color, valor, etiqueta) {
  return `<div class="stat"><div class="stat-icon ${color}">${icono(ic)}</div><div><div class="stat-n">${valor}</div><div class="stat-l">${etiqueta}</div></div></div>`;
}

function acceso(ruta, color, titulo, texto) {
  const r = RUTAS[ruta];
  const pronto = r.dibujar ? '' : `<span class="badge badge-gris acceso-pronto">Etapa ${r.etapa}</span>`;
  return `<a class="acceso" href="#${ruta}"><div class="stat-icon ${color}">${icono(ruta)}</div>${pronto}
    <div class="acceso-t">${titulo}</div><div class="acceso-s">${texto}</div></a>`;
}

function turnoPorClave(esquema, clave) {
  const t = (TURNOS[esquema] || []).find(x => x.clave === clave) || { clave, nombre: clave };
  return { ...t, esquema };
}

function iniciales(nombre) {
  return nombre.replace(/^(Dra?\.|Lic\.|Ing\.)\s*/, '').split(' ').slice(0, 2).map(p => p[0]).join('').toUpperCase();
}
function horaCorta(iso) { return new Date(iso).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' }); }
function fechaLarga(iso) {
  const [a, m, d] = iso.split('-').map(Number);
  return new Date(a, m - 1, d).toLocaleDateString('es-MX', { weekday: 'short', day: 'numeric', month: 'short' });
}

let temporizadorAviso;
function aviso(texto) {
  const t = document.getElementById('toast');
  t.textContent = texto;
  t.classList.add('visible');
  clearTimeout(temporizadorAviso);
  temporizadorAviso = setTimeout(() => t.classList.remove('visible'), 2600);
}

if (yo) iniciarApp();
