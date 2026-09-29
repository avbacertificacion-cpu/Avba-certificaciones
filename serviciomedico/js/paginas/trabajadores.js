/* ==========================================================================
   TRABAJADORES: lista con filtros, alta/edición (la hace la planta) y
   expediente con historial clínico.
   ========================================================================== */

const filtroTrab = { texto: '', area: '', turno: '', riesgo: '', estado: 'activos' };

RUTAS.trabajadores.dibujar = function () {
  const datos = DB.leer();
  const c = document.getElementById('contenido');
  const editar = puede('editarTrabajadores');

  c.innerHTML = `
    <div class="card">
      <div class="filtros">
        <input class="buscar" id="f-texto" type="search" placeholder="Buscar por nombre, número o puesto…" value="${esc(filtroTrab.texto)}">
        <select id="f-area">${opciones(AREAS.map(a => [a, a]), filtroTrab.area, 'Todas las áreas')}</select>
        <select id="f-turno">${opciones(listaTurnos().map(t => [t.clave, nombreTurno(t)]), filtroTrab.turno, 'Todos los turnos')}</select>
        <select id="f-riesgo">${opciones([['si', 'Hace alto riesgo'], ['no', 'Sin alto riesgo'], ['alerta', 'Con alertas de salud']], filtroTrab.riesgo, 'Todos')}</select>
        <select id="f-estado">${opciones([['activos', 'Activos'], ['baja', 'Dados de baja'], ['todos', 'Activos y bajas']], filtroTrab.estado)}</select>
        ${editar ? `<button class="btn btn-primario" id="btn-alta">${icono('mas')}Alta de trabajador</button>` : ''}
      </div>
      <div class="tabla-wrap" id="tabla-trab"></div>
    </div>`;

  const pintar = () => {
    const salud = Object.fromEntries(evaluarTodos(datos).map(s => [s.trabajador.id, s]));
    const q = filtroTrab.texto.toLowerCase();
    const lista = datos.trabajadores.filter(t => {
      if (filtroTrab.estado === 'activos' && !t.activo) return false;
      if (filtroTrab.estado === 'baja' && t.activo) return false;
      if (filtroTrab.area && t.area !== filtroTrab.area) return false;
      if (filtroTrab.turno && t.turno !== filtroTrab.turno) return false;
      if (filtroTrab.riesgo === 'si' && !t.altoRiesgo) return false;
      if (filtroTrab.riesgo === 'no' && t.altoRiesgo) return false;
      if (filtroTrab.riesgo === 'alerta' && !(salud[t.id] && salud[t.id].alertas.length)) return false;
      if (q && !`${t.nombre} ${t.apellidos} ${t.numEmpleado} ${t.puesto}`.toLowerCase().includes(q)) return false;
      return true;
    }).sort((a, b) => a.apellidos.localeCompare(b.apellidos));

    document.getElementById('tabla-trab').innerHTML = `
      <table>
        <thead><tr><th>Trabajador</th><th>Puesto</th><th>Turno</th><th>Alto riesgo</th><th>Salud</th><th></th></tr></thead>
        <tbody>${lista.map(t => {
          const s = salud[t.id];
          const r = s ? RIESGOS[s.riesgo] : null;
          return `<tr class="clic" data-id="${t.id}">
            <td><b>${esc(t.apellidos)}, ${esc(t.nombre)}</b><div class="sub">${esc(t.numEmpleado)} · ${edad(t.fechaNacimiento)} años</div></td>
            <td>${esc(t.puesto)}<div class="sub">${esc(t.area)}</div></td>
            <td class="nowrap">${esc(nombreTurno(turnoPorClave(null, t.turno)))}</td>
            <td>${t.altoRiesgo ? badge('Sí', 'badge-ambar') : '<span class="sub">No</span>'}</td>
            <td>${!t.activo ? badge('Baja', 'badge-gris') : r ? badge(r.nombre, r.badge) : ''}</td>
            <td class="derecha"><span class="sub">Ver expediente ›</span></td></tr>`;
        }).join('') || '<tr><td colspan="6" class="vacio">No hay trabajadores con esos filtros</td></tr>'}</tbody>
      </table>
      <div class="sub" style="margin-top:10px">${lista.length} trabajador(es)</div>`;
  };
  pintar();

  const enlazar = (id, campo, evento = 'change') => document.getElementById(id).addEventListener(evento, e => { filtroTrab[campo] = e.target.value; pintar(); });
  enlazar('f-texto', 'texto', 'input');
  enlazar('f-area', 'area'); enlazar('f-turno', 'turno'); enlazar('f-riesgo', 'riesgo'); enlazar('f-estado', 'estado');
  document.getElementById('tabla-trab').addEventListener('click', e => {
    const fila = e.target.closest('tr[data-id]');
    if (fila) ir('trabajador/' + fila.dataset.id);
  });
  if (editar) document.getElementById('btn-alta').onclick = () => formularioTrabajador(null);
};

/* ---------- Alta / edición ---------- */
function formularioTrabajador(trabajador) {
  const t = trabajador || { sexo: 'H', turno: 'matutino', area: 'Producción', tipoSangre: 'O+', alergias: 'Ninguna',
    antecedentes: {}, tiposAltoRiesgo: [], altoRiesgo: false, activo: true };
  const datos = DB.leer();
  abrirModal({
    titulo: trabajador ? 'Editar trabajador' : 'Alta de trabajador',
    textoGuardar: trabajador ? 'Guardar cambios' : 'Dar de alta',
    cuerpo: `
      <div class="campos">
        <div class="separador">Datos generales</div>
        <div class="field"><label>Número de empleado *</label><input name="numEmpleado" value="${esc(t.numEmpleado || siguienteNumEmpleado(datos))}"></div>
        <div class="field"><label>Fecha de nacimiento *</label><input name="fechaNacimiento" type="date" value="${esc(t.fechaNacimiento || '')}"></div>
        <div class="field"><label>Nombre(s) *</label><input name="nombre" value="${esc(t.nombre || '')}"></div>
        <div class="field"><label>Apellidos *</label><input name="apellidos" value="${esc(t.apellidos || '')}"></div>
        <div class="field"><label>Sexo</label><select name="sexo">${opciones([['H', 'Hombre'], ['M', 'Mujer']], t.sexo)}</select></div>
        <div class="field"><label>Teléfono de emergencia</label><input name="telefonoEmergencia" value="${esc(t.telefonoEmergencia || '')}"></div>
        <div class="separador">Puesto</div>
        <div class="field"><label>Puesto *</label><input name="puesto" value="${esc(t.puesto || '')}"></div>
        <div class="field"><label>Área</label><select name="area">${opciones(AREAS.map(a => [a, a]), t.area)}</select></div>
        <div class="field ancho"><label>Turno</label><select name="turno">${opciones(listaTurnos().map(x => [x.clave, `${nombreTurno(x)} · ${hh(x.inicio)}–${hh(x.fin)}`]), t.turno)}</select></div>
        <div class="field ancho"><label>Trabajos de alto riesgo que realiza</label>
          <div class="checks">${TIPOS_ALTO_RIESGO.map(x => `<label class="check"><input type="checkbox" name="tiposAltoRiesgo" value="${esc(x)}" ${t.tiposAltoRiesgo.includes(x) ? 'checked' : ''}>${esc(x)}</label>`).join('')}</div>
          <div class="ayuda">Si marca alguno, el trabajador deberá pasar toma de presión antes de esos trabajos.</div></div>
        <div class="separador">Datos médicos</div>
        <div class="field"><label>Tipo de sangre</label><select name="tipoSangre">${opciones(TIPOS_SANGRE.map(x => [x, x]), t.tipoSangre)}</select></div>
        <div class="field"><label>Alergias</label><input name="alergias" value="${esc(t.alergias || '')}" placeholder="Ninguna"></div>
        <div class="field"><label>Peso</label><div class="con-unidad"><input name="peso" type="number" min="30" max="250" value="${t.peso ?? ''}"><span>kg</span></div></div>
        <div class="field"><label>Talla</label><div class="con-unidad"><input name="talla" type="number" min="120" max="220" value="${t.talla ?? ''}"><span>cm</span></div></div>
        <div class="field ancho"><label>Antecedentes</label>
          <div class="checks">${Object.entries(ANTECEDENTES).map(([k, n]) => `<label class="check"><input type="checkbox" name="ant_${k}" ${t.antecedentes[k] ? 'checked' : ''}>${n}</label>`).join('')}</div></div>
      </div>`,
    onGuardar(v) {
      for (const [campo, nombre] of [['numEmpleado', 'número de empleado'], ['nombre', 'nombre'], ['apellidos', 'apellidos'], ['fechaNacimiento', 'fecha de nacimiento'], ['puesto', 'puesto']])
        if (!v[campo]) return `Falta capturar el ${nombre}.`;
      if (datos.trabajadores.some(x => x.numEmpleado.toLowerCase() === v.numEmpleado.toLowerCase() && x.id !== t.id))
        return `Ya existe un trabajador con el número ${v.numEmpleado}.`;
      if (new Date(v.fechaNacimiento) > new Date()) return 'La fecha de nacimiento no puede ser futura.';

      const registro = {
        ...t,
        numEmpleado: v.numEmpleado, nombre: v.nombre, apellidos: v.apellidos, sexo: v.sexo,
        fechaNacimiento: v.fechaNacimiento, telefonoEmergencia: v.telefonoEmergencia,
        puesto: v.puesto, area: v.area, turno: v.turno,
        tiposAltoRiesgo: v.tiposAltoRiesgo || [], altoRiesgo: (v.tiposAltoRiesgo || []).length > 0,
        tipoSangre: v.tipoSangre, alergias: v.alergias || 'Ninguna', peso: v.peso, talla: v.talla,
        antecedentes: Object.fromEntries(Object.keys(ANTECEDENTES).map(k => [k, !!v['ant_' + k]])),
      };
      if (trabajador) Object.assign(trabajador, registro);
      else { registro.id = DB.nuevoId('t'); registro.fechaAlta = fechaISO(new Date()); registro.activo = true; datos.trabajadores.push(registro); }
      DB.guardar(datos);
      aviso(trabajador ? 'Cambios guardados' : 'Trabajador dado de alta');
      if (trabajador) refrescar(); else ir('trabajador/' + registro.id);
    },
  });
}

function siguienteNumEmpleado(datos) {
  const max = Math.max(0, ...datos.trabajadores.map(t => parseInt(t.numEmpleado.replace(/\D/g, ''), 10) || 0));
  return 'EMP-' + String(max + 1).padStart(5, '0');
}

/* ---------- Expediente ---------- */
RUTAS.trabajador.dibujar = function (id) {
  const datos = DB.leer();
  const t = datos.trabajadores.find(x => x.id === id);
  const c = document.getElementById('contenido');
  if (!t) { c.innerHTML = `<div class="card vacio">No se encontró el trabajador. <a href="#trabajadores">Volver a la lista</a></div>`; return; }

  const clinico = puede('verClinico');
  const salud = evaluarSalud(datos, t);
  const imcC = clasificarIMC(salud.imc);
  const consultas = datos.consultas.filter(x => x.trabajadorId === t.id).sort((a, b) => b.fecha.localeCompare(a.fecha));
  const evals = datos.evaluaciones.filter(x => x.trabajadorId === t.id).sort((a, b) => b.fecha.localeCompare(a.fecha));
  const lim = datos.config.limites;
  const nombreDe = uid => { const u = datos.usuarios.find(x => x.id === uid); return u ? u.nombre : '—'; };

  const acciones = [];
  if (puede('atender') && t.activo) {
    acciones.push(`<a class="btn btn-primario" href="#consulta-nueva/${t.id}">${icono('consultas')}Nueva consulta</a>`);
    if (t.altoRiesgo) acciones.push(`<a class="btn btn-borde" href="#altoriesgo/${t.id}">${icono('altoriesgo')}Evaluar alto riesgo</a>`);
  }
  if (puede('editarTrabajadores')) {
    acciones.push(`<button class="btn btn-borde" id="btn-editar">${icono('editar')}Editar</button>`);
    acciones.push(`<button class="btn btn-borde ${t.activo ? 'btn-peligro' : ''}" id="btn-baja">${t.activo ? 'Dar de baja' : 'Reactivar'}</button>`);
  }

  const ant = Object.entries(ANTECEDENTES).filter(([k]) => t.antecedentes[k]).map(([, n]) => n);

  c.innerHTML = `
    <a class="volver" href="#trabajadores">${icono('volver', 'width="14" height="14"')}Trabajadores</a>
    <div class="card">
      <div class="perfil">
        <div class="perfil-avatar">${esc(iniciales(t.nombre + ' ' + t.apellidos))}</div>
        <div>
          <div class="perfil-n">${esc(nombreCompleto(t))} ${!t.activo ? badge('Baja', 'badge-gris') : ''}</div>
          <div class="perfil-s">${esc(t.numEmpleado)} · ${esc(t.puesto)} · ${esc(t.area)}</div>
          <div style="margin-top:8px" class="chips">
            ${badge(RIESGOS[salud.riesgo].nombre, RIESGOS[salud.riesgo].badge)}
            ${t.altoRiesgo ? t.tiposAltoRiesgo.map(x => badge(x, 'badge-ambar')).join('') : badge('Sin trabajos de alto riesgo', 'badge-gris')}
          </div>
        </div>
        <div class="acciones">${acciones.join('')}</div>
      </div>
      <div class="datos-lista">
        <div><div class="dato-l">Edad</div><div class="dato-v">${edad(t.fechaNacimiento)} años</div></div>
        <div><div class="dato-l">Sexo</div><div class="dato-v">${t.sexo === 'H' ? 'Hombre' : 'Mujer'}</div></div>
        <div><div class="dato-l">Turno</div><div class="dato-v">${esc(nombreTurno(turnoPorClave(null, t.turno)))}</div></div>
        <div><div class="dato-l">Tipo de sangre</div><div class="dato-v">${esc(t.tipoSangre)}</div></div>
        <div><div class="dato-l">Alergias</div><div class="dato-v" style="${t.alergias && t.alergias !== 'Ninguna' ? 'color:var(--rojo);font-weight:700' : ''}">${esc(t.alergias || 'Ninguna')}</div></div>
        <div><div class="dato-l">IMC</div><div class="dato-v">${salud.imc ?? '—'} ${imcC ? badge(imcC.texto, imcC.badge) : ''}</div></div>
        <div><div class="dato-l">Antecedentes</div><div class="dato-v">${ant.length ? esc(ant.join(', ')) : 'Ninguno'}</div></div>
        <div><div class="dato-l">Tel. de emergencia</div><div class="dato-v">${esc(t.telefonoEmergencia || '—')}</div></div>
        <div><div class="dato-l">Alta en planta</div><div class="dato-v">${t.fechaAlta ? fechaLarga(t.fechaAlta) : '—'}</div></div>
      </div>
    </div>

    <div class="grid grid-2">
      <div class="card">
        <div class="card-hdr"><div><div class="card-hdr-t">Alertas de salud</div><div class="card-hdr-s">Calculadas con su historial · para valoración médica</div></div></div>
        ${salud.alertas.length ? salud.alertas.map(a => `
          <div class="alerta ${a.nivel}"><div class="alerta-i">!</div><div>
            <div class="alerta-t">${esc(a.titulo)}</div><div class="alerta-p">${esc(a.porque)}</div></div></div>`).join('')
          : '<div class="vacio">Sin alertas con la información disponible.</div>'}
      </div>
      <div class="card">
        <div class="card-hdr"><div><div class="card-hdr-t">Presión arterial</div><div class="card-hdr-s">Últimos 60 días · consultas y tomas de alto riesgo</div></div></div>
        <div id="zona-presion"></div>
      </div>
    </div>

    <div class="card">
      <div class="card-hdr"><div><div class="card-hdr-t">Evaluaciones de alto riesgo</div><div class="card-hdr-s">${evals.length} registradas</div></div></div>
      <div class="tabla-wrap"><table>
        <thead><tr><th>Fecha</th><th>Trabajo</th><th class="derecha">Presión</th><th>Dictamen</th><th>Paramédico</th></tr></thead>
        <tbody>${evals.slice(0, 12).map(e => {
          const d = DICTAMENES[e.dictamen];
          return `<tr><td class="nowrap">${fechaHora(e.fecha)}</td><td>${esc(e.tipoTrabajo)}</td>
            <td class="derecha num">${clinico ? `${e.sistolica}/${e.diastolica}` : '—'}</td><td>${badge(d.nombre, d.badge)}</td><td>${esc(nombreDe(e.paramedicoId))}</td></tr>`;
        }).join('') || '<tr><td colspan="5" class="vacio">Sin evaluaciones</td></tr>'}</tbody></table></div>
    </div>

    <div class="card">
      <div class="card-hdr"><div><div class="card-hdr-t">Historial de consultas</div><div class="card-hdr-s">${consultas.length} consultas</div></div></div>
      ${clinico ? `<div class="tabla-wrap"><table>
        <thead><tr><th>Fecha</th><th>Motivo</th><th>Diagnóstico</th><th>Destino</th><th>Atendió</th></tr></thead>
        <tbody>${consultas.slice(0, 20).map(x => `<tr class="clic" onclick="ir('consulta/${x.id}')">
          <td class="nowrap">${fechaHora(x.fecha)}</td><td>${esc(x.motivo)}</td><td>${esc(x.diagnostico)}</td>
          <td>${badge(DESTINOS[x.destino].nombre, DESTINOS[x.destino].badge)}</td><td>${esc(nombreDe(x.paramedicoId))}</td></tr>`).join('')
          || '<tr><td colspan="5" class="vacio">Sin consultas</td></tr>'}</tbody></table></div>`
        : `<div class="nota-privada">El detalle clínico (diagnósticos, recetas y signos vitales) sólo lo ve el servicio médico.
            La planta ve el número de consultas, las alertas y los dictámenes de alto riesgo.</div>`}
    </div>`;

  // Gráfica de presión
  const presiones = presionesDe(datos, t.id).filter(p => p.fecha >= new Date(Date.now() - 60 * DIA_MS).toISOString());
  const zona = document.getElementById('zona-presion');
  if (!clinico) zona.innerHTML = '<div class="nota-privada">Los valores de presión sólo los ve el servicio médico.</div>';
  else if (presiones.length < 2) zona.innerHTML = '<div class="vacio">Aún no hay suficientes tomas para graficar.</div>';
  else {
    zona.innerHTML = leyenda([['Sistólica', COLORES.serie[0]], ['Diastólica', COLORES.serie[1]], [`Límite ${lim.aptoSistolica}/${lim.aptoDiastolica}`, COLORES.tenue]]) +
      `<div class="grafica baja"><canvas id="g-presion" aria-label="Presión arterial en el tiempo"></canvas></div>` +
      tablaDeGrafica('t-presion', ['Fecha', 'Origen', 'Sistólica', 'Diastólica'], presiones.map(p => [fechaHora(p.fecha), p.origen, p.sis, p.dia]));
    const etiquetas = presiones.map(p => etiquetaDia(fechaISO(new Date(p.fecha))));
    grafica('g-presion', {
      type: 'line',
      data: { labels: etiquetas, datasets: [
        linea('Sistólica', presiones.map(p => p.sis), COLORES.serie[0]),
        linea('Diastólica', presiones.map(p => p.dia), COLORES.serie[1]),
        linea(`Límite sistólica`, presiones.map(() => lim.aptoSistolica), COLORES.tenue, { borderWidth: 1, pointRadius: 0, pointHoverRadius: 0, tension: 0 }),
        linea(`Límite diastólica`, presiones.map(() => lim.aptoDiastolica), COLORES.tenue, { borderWidth: 1, pointRadius: 0, pointHoverRadius: 0, tension: 0 }),
      ] },
      options: {
        scales: ejes({ min: 50, max: 190 }),
        interaction: { mode: 'index', intersect: false },
        plugins: { tooltip: { filter: i => i.datasetIndex < 2, callbacks: { afterBody: items => presiones[items[0].dataIndex].origen } } },
      },
    });
  }

  if (puede('editarTrabajadores')) {
    document.getElementById('btn-editar').onclick = () => formularioTrabajador(t);
    document.getElementById('btn-baja').onclick = () => {
      const texto = t.activo ? `¿Dar de baja a ${nombreCompleto(t)}? Su historial se conserva.` : `¿Reactivar a ${nombreCompleto(t)}?`;
      if (!confirm(texto)) return;
      t.activo = !t.activo;
      DB.guardar(datos);
      aviso(t.activo ? 'Trabajador reactivado' : 'Trabajador dado de baja');
      refrescar();
    };
  }
};
