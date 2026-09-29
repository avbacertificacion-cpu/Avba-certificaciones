/* ==========================================================================
   Núcleo del panel: menú según el rol, navegación, utilidades comunes y la
   pantalla de inicio. Cada sección vive en js/paginas/*.js y se registra
   en RUTAS. Las direcciones son del tipo #ruta o #ruta/parametro
   (p. ej. #trabajador/t5).
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
  mas:          '<path d="M8 3v10M3 8h10"/>',
  editar:       '<path d="M11 2.5l2.5 2.5L6 12.5H3.5V10z"/>',
  imprimir:     '<path d="M4 6V2h8v4M4 12H2.5V7h11v5H12"/><rect x="4" y="10" width="8" height="4"/>',
  volver:       '<path d="M10 3L5 8l5 5"/>',
  entrada:      '<path d="M8 2v9M4.5 7.5L8 11l3.5-3.5M2.5 14h11"/>',
};
const icono = (n, extra = '') => `<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" ${extra}>${ICONOS[n] || ''}</svg>`;

/* --------------------------------------------------------------------------
   Rutas. Las páginas completan `dibujar` desde su propio archivo.
   `oculta` = no sale en el menú; `padre` = qué opción del menú se resalta.
   -------------------------------------------------------------------------- */
const RUTAS = {
  inicio:       { seccion: 'Principal', titulo: 'Inicio',           sub: 'Tu turno y resumen del día',                         roles: ['admin', 'paramedico', 'planta'] },
  tablero:      { seccion: 'Principal', titulo: 'Tablero',          sub: 'Indicadores y gráficas',                             roles: ['admin', 'planta'] },
  consultas:    { seccion: 'Atención',  titulo: 'Consultas',        sub: 'Historial de consultas',                             roles: ['admin', 'paramedico'] },
  'consulta-nueva': { oculta: true, padre: 'consultas', titulo: 'Nueva consulta', sub: 'Checklist digital de consulta',        roles: ['admin', 'paramedico'] },
  consulta:     { oculta: true, padre: 'consultas', titulo: 'Detalle de consulta', sub: 'Constancia de atención',              roles: ['admin', 'paramedico'] },
  altoriesgo:   { seccion: 'Atención',  titulo: 'Alto riesgo',      sub: 'Presión arterial antes de trabajos de alto riesgo',  roles: ['admin', 'paramedico'] },
  alertas:      { seccion: 'Atención',  titulo: 'Alertas de salud', sub: 'Trabajadores propensos a enfermedades',              roles: ['admin', 'planta', 'paramedico'] },
  trabajadores: { seccion: 'Registros', titulo: 'Trabajadores',     sub: 'Alta y expediente de los trabajadores de planta',    roles: ['admin', 'paramedico', 'planta'] },
  trabajador:   { oculta: true, padre: 'trabajadores', titulo: 'Expediente', sub: 'Historial clínico del trabajador',          roles: ['admin', 'paramedico', 'planta'] },
  paramedicos:  { seccion: 'Registros', titulo: 'Paramédicos',      sub: 'Alta de paramédicos, usuarios y contraseñas',        roles: ['admin'] },
  medicamentos: { seccion: 'Registros', titulo: 'Medicamentos',     sub: 'Inventario, entradas y salidas',                     roles: ['admin', 'paramedico'] },
  ambulancias:  { seccion: 'Registros', titulo: 'Ambulancias',      sub: 'Unidades, estado y asignaciones',                    roles: ['admin'] },
};

// ¿Quién puede hacer qué?
const PERMISOS = {
  editarTrabajadores: ['admin', 'planta'],
  verClinico:         ['admin', 'paramedico'],   // diagnósticos, recetas, signos
  atender:            ['admin', 'paramedico'],   // consultas y evaluaciones
  administrar:        ['admin'],                 // inventario, paramédicos, ambulancias, límites
};
const puede = accion => PERMISOS[accion].includes(yo.rol);

/* --------------------------------------------------------------------------
   Arranque y navegación
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
    if (rutaActual().clave === 'inicio' && !document.querySelector('.modal-fondo')) dibujarInicio();
  }, 60000);
}

function rutaActual() {
  const [clave, ...resto] = location.hash.replace('#', '').split('/');
  const param = decodeURIComponent(resto.join('/'));
  if (RUTAS[clave] && RUTAS[clave].roles.includes(yo.rol)) return { clave, param };
  return { clave: 'inicio', param: '' };
}

function ir(ruta) { location.hash = ruta; }

function dibujarMenu() {
  let html = '', seccion = '';
  for (const [clave, r] of Object.entries(RUTAS)) {
    if (r.oculta || !r.roles.includes(yo.rol)) continue;
    if (r.seccion !== seccion) { seccion = r.seccion; html += `<div class="sb-section">${seccion}</div>`; }
    html += `<a class="sb-item" href="#${clave}" data-ruta="${clave}">${icono(clave)}${r.titulo}</a>`;
  }
  document.getElementById('menu').innerHTML = html;
}

let graficas = [];
function navegar() {
  const { clave, param } = rutaActual();
  const r = RUTAS[clave];
  const menu = r.padre || clave;
  document.querySelectorAll('.sb-item[data-ruta]').forEach(a => a.classList.toggle('active', a.dataset.ruta === menu));
  document.getElementById('topbar-t').textContent = r.titulo;
  document.getElementById('topbar-s').textContent = r.sub;
  document.title = `${r.titulo} — AVBA Servicio Médico`;
  cerrarModal();
  graficas.forEach(g => g.destroy());
  graficas = [];
  pintarTurnoTopbar();
  r.dibujar(param);
  window.scrollTo(0, 0);
}

// Vuelve a dibujar la pantalla actual (después de guardar algo)
function refrescar() { navegar(); }

/* --------------------------------------------------------------------------
   Turno y guardia
   -------------------------------------------------------------------------- */

// Guardia del paramédico en el turno en curso (se crea al iniciar sesión)
function guardiaActual(datos, usuario = yo) {
  if (usuario.rol !== 'paramedico') return null;
  const t = detectarTurno(new Date(), usuario.esquema);
  let g = datos.guardias.find(x => x.usuarioId === usuario.id && x.fechaTurno === t.fechaTurno && x.turno === t.clave);
  if (!g && usuario.id === yo.id) {
    // Cambió el turno sin cerrar sesión: se abre la guardia del turno nuevo
    registrarGuardia(datos, usuario);
    g = datos.guardias.find(x => x.usuarioId === usuario.id && x.fechaTurno === t.fechaTurno && x.turno === t.clave);
  }
  return g || null;
}

// Ambulancia con la que se está trabajando ahora
function ambulanciaActual(datos) {
  const g = guardiaActual(datos);
  return g ? g.ambulanciaId : (yo.ambulanciaId || null);
}

// Datos de turno que se guardan en cada consulta o evaluación
function sellarTurno(fecha = new Date()) {
  const t = detectarTurno(fecha, yo.esquema || '8h');
  return { turno: t.clave, esquema: t.esquema, fechaTurno: t.fechaTurno };
}

function pintarTurnoTopbar() {
  const datos = DB.leer();
  const t = detectarTurno(new Date(), yo.esquema || '8h');
  const ambId = yo.rol === 'paramedico' ? ambulanciaActual(datos) : null;
  const amb = datos.ambulancias.find(a => a.id === ambId);
  document.getElementById('topbar-turno').innerHTML =
    `${icono('reloj')}<span><span class="txt-largo">Turno </span><b>${esc(nombreTurno(t))}</b>` +
    `<span class="txt-largo"> · ${t.horario}</span>${amb ? ` · ${esc(amb.clave)}` : ''}</span>`;
}

// Paramédicos cuya guardia sigue en curso en este momento
function paramedicosEnTurno(datos) {
  const ahora = new Date();
  return datos.guardias
    .filter(g => {
      const t = detectarTurno(ahora, g.esquema);
      return t.clave === g.turno && t.fechaTurno === g.fechaTurno;
    })
    .map(g => ({ guardia: g, usuario: datos.usuarios.find(u => u.id === g.usuarioId) }))
    .filter(p => p.usuario && p.usuario.activo);
}

/* --------------------------------------------------------------------------
   INICIO
   -------------------------------------------------------------------------- */
RUTAS.inicio.dibujar = dibujarInicio;

function dibujarInicio() {
  const datos = DB.leer();
  const c = document.getElementById('contenido');
  if (yo.rol === 'paramedico') {
    c.innerHTML = inicioParamedico(datos);
    const sel = document.getElementById('cambiar-amb');
    if (sel) sel.onchange = () => {
      const g = guardiaActual(datos);
      if (!g) return;
      g.ambulanciaId = sel.value || null;
      DB.guardar(datos);
      aviso('Ambulancia del turno actualizada');
      refrescar();
    };
  } else {
    c.innerHTML = inicioGeneral(datos);
  }
}

function inicioParamedico(datos) {
  const t = detectarTurno(new Date(), yo.esquema);
  const g = guardiaActual(datos);
  const amb = datos.ambulancias.find(a => a.id === (g ? g.ambulanciaId : yo.ambulanciaId));
  const companeros = paramedicosEnTurno(datos).filter(p => p.usuario.id !== yo.id);
  const misGuardias = datos.guardias.filter(x => x.usuarioId === yo.id).slice(-6).reverse();
  const delTurno = x => x.paramedicoId === yo.id && x.fechaTurno === t.fechaTurno && x.turno === t.clave;
  const consultasTurno = datos.consultas.filter(delTurno);
  const evalTurno = datos.evaluaciones.filter(delTurno);
  const opcionesAmb = datos.ambulancias
    .filter(a => ['disponible', 'en_servicio'].includes(a.estado) || (amb && a.id === amb.id))
    .map(a => `<option value="${a.id}" ${amb && a.id === amb.id ? 'selected' : ''}>${esc(a.clave)} · ${esc(a.tipo)}</option>`).join('');

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
        <div class="turno-dato"><div class="turno-dato-l">Consultas</div><div class="turno-dato-v">${consultasTurno.length} este turno</div></div>
        <div class="turno-dato"><div class="turno-dato-l">Alto riesgo</div><div class="turno-dato-v">${evalTurno.length} evaluaciones</div></div>
        <div class="turno-dato"><div class="turno-dato-l">Entrada</div><div class="turno-dato-v">${g ? horaCorta(g.entrada) : '—'}</div></div>
      </div>
    </section>

    <div class="accesos" style="margin-bottom:16px">
      ${acceso('#consulta-nueva', 'consultas', 'c-azul', 'Nueva consulta', 'Checklist digital y receta')}
      ${acceso('#altoriesgo', 'altoriesgo', 'c-ambar', 'Alto riesgo', 'Tomar presión antes de trabajar')}
      ${acceso('#trabajadores', 'trabajadores', 'c-verde', 'Trabajadores', 'Buscar expediente')}
      ${acceso('#medicamentos', 'medicamentos', 'c-morado', 'Medicamentos', 'Revisar existencias')}
    </div>

    <div class="grid grid-2">
      <div class="card">
        <div class="card-hdr"><div><div class="card-hdr-t">Ambulancia de este turno</div><div class="card-hdr-s">Si hoy te tocó otra unidad, cámbiala aquí</div></div></div>
        <div class="field" style="margin-bottom:10px">
          <select id="cambiar-amb"><option value="">Sin ambulancia</option>${opcionesAmb}</select>
        </div>
        <div class="card-hdr" style="margin:18px 0 10px"><div><div class="card-hdr-t">Compañeros en turno</div><div class="card-hdr-s">Paramédicos que ya iniciaron sesión en el turno en curso</div></div></div>
        ${tablaEnTurno(companeros, datos)}
      </div>
      <div class="card">
        <div class="card-hdr"><div><div class="card-hdr-t">Mis guardias recientes</div><div class="card-hdr-s">Se registran solas al iniciar sesión</div></div></div>
        <div class="tabla-wrap"><table>
          <thead><tr><th>Fecha de turno</th><th>Turno</th><th>Unidad</th><th>Entrada</th></tr></thead>
          <tbody>${misGuardias.map(x => {
            const a = datos.ambulancias.find(y => y.id === x.ambulanciaId);
            return `<tr>
            <td>${fechaLarga(x.fechaTurno)}</td>
            <td><span class="badge badge-azul">${esc(nombreTurno(turnoPorClave(x.esquema, x.turno)))}</span></td>
            <td>${a ? esc(a.clave) : '—'}</td>
            <td class="nowrap">${horaCorta(x.entrada)}</td></tr>`;
          }).join('') || '<tr><td colspan="4" class="vacio">Sin guardias</td></tr>'}
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
  const hoy = fechaISO(new Date());
  const consultasHoy = datos.consultas.filter(c => fechaISO(new Date(c.fecha)) === hoy).length;
  const salud = evaluarTodos(datos);
  const riesgoAlto = salud.filter(s => s.riesgo === 'alto').length;
  const bajos = datos.medicamentos.filter(m => m.activo && m.stock <= m.minimo).length;

  const stats = yo.rol === 'admin' ? `
      ${stat('consultas', 'c-azul', consultasHoy, 'Consultas hoy')}
      ${stat('paramedicos', 'c-verde', `${enTurno.length}<span class="stat-de"> / ${paramedicos.length}</span>`, 'Paramédicos en turno')}
      ${stat('ambulancias', 'c-morado', `${ambDisp}<span class="stat-de"> / ${datos.ambulancias.length}</span>`, 'Ambulancias disponibles')}
      ${stat('alertas', 'c-rojo', riesgoAlto, 'Trabajadores en riesgo alto', '#alertas')}
      ${stat('medicamentos', 'c-ambar', bajos, 'Medicamentos en mínimo', '#medicamentos')}` : `
      ${stat('trabajadores', 'c-azul', activos.length, 'Trabajadores activos', '#trabajadores')}
      ${stat('altoriesgo', 'c-ambar', altoRiesgo, 'Hacen trabajos de alto riesgo')}
      ${stat('consultas', 'c-verde', consultasHoy, 'Consultas hoy')}
      ${stat('alertas', 'c-rojo', riesgoAlto, 'Trabajadores en riesgo alto', '#alertas')}`;

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
      <div class="card-hdr"><div><div class="card-hdr-t">Trabajadores por turno</div><div class="card-hdr-s">${esc(datos.planta.nombre)} · ${esc(datos.planta.ubicacion)}</div></div>
        <a class="btn btn-borde" href="#tablero">${icono('tablero')}Ver tablero</a></div>
      <div class="tabla-wrap"><table>
        <thead><tr><th>Turno</th><th>Horario</th><th>Esquema</th><th class="derecha">Trabajadores</th></tr></thead>
        <tbody>${listaTurnos().map(t => `<tr>
          <td><b>${esc(nombreTurno(t))}</b></td>
          <td>${hh(t.inicio)} – ${hh(t.fin)}</td>
          <td>${ESQUEMAS[t.esquema]}</td>
          <td class="derecha num">${porTurno[t.clave] || 0}</td></tr>`).join('')}
        </tbody></table></div>
    </div>`;
}

/* --------------------------------------------------------------------------
   Piezas reutilizables
   -------------------------------------------------------------------------- */
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

function stat(ic, color, valor, etiqueta, enlace) {
  const dentro = `<div class="stat-icon ${color}">${icono(ic)}</div><div><div class="stat-n">${valor}</div><div class="stat-l">${etiqueta}</div></div>`;
  return enlace ? `<a class="stat stat-link" href="${enlace}">${dentro}</a>` : `<div class="stat">${dentro}</div>`;
}

function acceso(enlace, ic, color, titulo, texto) {
  return `<a class="acceso" href="${enlace}"><div class="stat-icon ${color}">${icono(ic)}</div>
    <div class="acceso-t">${titulo}</div><div class="acceso-s">${texto}</div></a>`;
}

function badge(texto, clase) { return `<span class="badge ${clase}">${esc(texto)}</span>`; }

function opciones(lista, seleccion, vacio) {
  // lista: [[valor, texto], ...]
  return (vacio ? `<option value="">${esc(vacio)}</option>` : '') +
    lista.map(([v, t]) => `<option value="${esc(v)}" ${String(v) === String(seleccion ?? '') ? 'selected' : ''}>${esc(t)}</option>`).join('');
}

function nombreCompleto(t) { return t ? `${t.nombre} ${t.apellidos}` : '—'; }
function edad(fechaNac) {
  const n = new Date(fechaNac + 'T12:00:00'), h = new Date();
  let e = h.getFullYear() - n.getFullYear();
  if (h < new Date(h.getFullYear(), n.getMonth(), n.getDate())) e--;
  return e;
}
function iniciales(nombre) {
  return nombre.replace(/^(Dra?\.|Lic\.|Ing\.)\s*/, '').split(' ').slice(0, 2).map(p => p[0]).join('').toUpperCase();
}
function horaCorta(iso) { return new Date(iso).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' }); }
function fechaCorta(iso) { return new Date(iso).toLocaleDateString('es-MX', { day: 'numeric', month: 'short', year: 'numeric' }); }
function fechaHora(iso) { return `${new Date(iso).toLocaleDateString('es-MX', { day: 'numeric', month: 'short' })} · ${horaCorta(iso)}`; }
function fechaLarga(iso) {
  const [a, m, d] = iso.split('-').map(Number);
  return new Date(a, m - 1, d).toLocaleDateString('es-MX', { weekday: 'short', day: 'numeric', month: 'short' });
}
function numero(n) { return Number(n).toLocaleString('es-MX'); }

let temporizadorAviso;
function aviso(texto) {
  const t = document.getElementById('toast');
  t.textContent = texto;
  t.classList.add('visible');
  clearTimeout(temporizadorAviso);
  temporizadorAviso = setTimeout(() => t.classList.remove('visible'), 2600);
}

/* --------------------------------------------------------------------------
   Ventana emergente con formulario.
   onGuardar(valores, form) devuelve un texto de error para mantenerla
   abierta, o nada para cerrarla.
   -------------------------------------------------------------------------- */
function abrirModal({ titulo, cuerpo, textoGuardar = 'Guardar', onGuardar, alAbrir }) {
  cerrarModal();
  const fondo = document.createElement('div');
  fondo.className = 'modal-fondo';
  fondo.innerHTML = `
    <form class="modal" novalidate>
      <div class="modal-hdr"><div class="modal-hdr-t">${esc(titulo)}</div><button type="button" class="btn-cerrar" aria-label="Cerrar">×</button></div>
      <div class="modal-body"><div class="modal-error"></div>${cuerpo}</div>
      <div class="modal-footer">
        <button type="button" class="btn btn-borde" data-cancelar>Cancelar</button>
        <button type="submit" class="btn btn-primario">${esc(textoGuardar)}</button>
      </div>
    </form>`;
  document.body.appendChild(fondo);
  const form = fondo.querySelector('form');
  fondo.querySelector('.btn-cerrar').onclick = cerrarModal;
  fondo.querySelector('[data-cancelar]').onclick = cerrarModal;
  fondo.addEventListener('mousedown', e => { if (e.target === fondo) cerrarModal(); });
  form.onsubmit = e => {
    e.preventDefault();
    const error = onGuardar(leerFormulario(form), form);
    if (error) {
      const caja = form.querySelector('.modal-error');
      caja.textContent = error;
      caja.classList.add('visible');
      caja.scrollIntoView({ block: 'nearest' });
    } else cerrarModal();
  };
  if (alAbrir) alAbrir(form);
  const primero = form.querySelector('input:not([type=checkbox]), select, textarea');
  if (primero) primero.focus();
  return form;
}
function cerrarModal() { document.querySelectorAll('.modal-fondo').forEach(m => m.remove()); }
document.addEventListener('keydown', e => { if (e.key === 'Escape') cerrarModal(); });

// Convierte un formulario en objeto. Los checkbox con el mismo name y
// value se juntan en arreglo; los sueltos quedan como true/false.
function leerFormulario(form) {
  const v = {};
  form.querySelectorAll('input, select, textarea').forEach(el => {
    if (!el.name) return;
    if (el.type === 'checkbox') {
      if (el.value && el.value !== 'on') { (v[el.name] = v[el.name] || []); if (el.checked) v[el.name].push(el.value); }
      else v[el.name] = el.checked;
    } else if (el.type === 'number') v[el.name] = el.value === '' ? null : Number(el.value);
    else v[el.name] = el.value.trim();
  });
  return v;
}

// Tabla escondida debajo de una gráfica (alternativa accesible)
function tablaDeGrafica(id, encabezados, filas) {
  return `<button class="ver-tabla" type="button" onclick="document.getElementById('${id}').classList.toggle('visible')">Ver datos en tabla</button>
    <div class="tabla-oculta tabla-wrap" id="${id}"><table>
      <thead><tr>${encabezados.map((h, i) => `<th class="${i ? 'derecha' : ''}">${esc(h)}</th>`).join('')}</tr></thead>
      <tbody>${filas.map(f => `<tr>${f.map((c, i) => `<td class="${i ? 'derecha num' : ''}">${esc(c)}</td>`).join('')}</tr>`).join('')}</tbody>
    </table></div>`;
}
