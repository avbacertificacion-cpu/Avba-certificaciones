/* ==========================================================================
   ALTO RIESGO: toma de presión antes de trabajos de alto riesgo con
   dictamen automático (Apto / Revalorar / No apto), pendientes del turno
   y registro del día. El administrador puede ajustar los límites.
   ========================================================================== */

let rangoAR = '1';

// Esquema al que pertenece el turno de un trabajador
const esquemaDeTurno = clave => ['diurno', 'nocturno12'].includes(clave) ? '12h' : '8h';

RUTAS.altoriesgo.dibujar = function (trabajadorId) {
  const datos = DB.leer();
  const lim = datos.config.limites;
  const candidatos = datos.trabajadores.filter(t => t.activo && t.altoRiesgo).sort((a, b) => a.apellidos.localeCompare(b.apellidos));
  const ahora = new Date();

  // Trabajadores de alto riesgo cuyo turno está en curso y aún no se evalúan en él
  const pendientes = candidatos.filter(t => {
    const turno = detectarTurno(ahora, esquemaDeTurno(t.turno));
    if (turno.clave !== t.turno) return false;
    return !datos.evaluaciones.some(e => e.trabajadorId === t.id && new Date(e.fecha) >= turno.inicioFecha);
  });

  document.getElementById('contenido').innerHTML = `
    <div class="grid grid-2">
      <div class="card">
        <div class="card-hdr"><div><div class="card-hdr-t">Nueva toma de presión</div><div class="card-hdr-s">Antes de iniciar el trabajo de alto riesgo</div></div></div>
        <form id="form-ar" novalidate>
          <div class="field"><label>Buscar</label><input id="buscar-ar" type="search" placeholder="Nombre o número de empleado"></div>
          <div class="field"><label>Trabajador *</label><select name="trabajadorId" id="sel-ar">${opciones(candidatos.map(t => [t.id, `${t.apellidos}, ${t.nombre} · ${t.puesto}`]), trabajadorId, 'Selecciona…')}</select>
            <div class="ayuda">Sólo aparecen los trabajadores que la planta marcó con trabajos de alto riesgo.</div></div>
          <div class="field"><label>Trabajo a realizar *</label><select name="tipoTrabajo" id="sel-tipo">${opciones(TIPOS_ALTO_RIESGO.map(x => [x, x]), '', 'Selecciona…')}</select></div>
          <div class="campos campos-3">
            <div class="field"><label>Sistólica *</label><div class="con-unidad"><input name="sistolica" type="number" min="60" max="260" inputmode="numeric"><span>mmHg</span></div></div>
            <div class="field"><label>Diastólica *</label><div class="con-unidad"><input name="diastolica" type="number" min="30" max="160" inputmode="numeric"><span>mmHg</span></div></div>
            <div class="field"><label>Frec. cardiaca</label><div class="con-unidad"><input name="fc" type="number" min="30" max="220" inputmode="numeric"><span>lpm</span></div></div>
          </div>
          <div class="field"><label>Observaciones</label><input name="observaciones" placeholder="Opcional"></div>
          <div id="info-ar"></div>
          <button class="btn btn-primario" type="submit" style="width:100%;justify-content:center;margin-top:4px">${icono('altoriesgo')}Registrar y dictaminar</button>
        </form>
      </div>
      <div>
        <div class="card">
          <div class="card-hdr"><div class="card-hdr-t">Dictamen</div>${puede('administrar') ? `<button class="btn-tbl" id="btn-limites">Ajustar límites</button>` : ''}</div>
          <div class="dictamen" id="dictamen"><div class="dictamen-t">—</div><div class="dictamen-s">Captura la presión para ver el dictamen</div></div>
          <div class="datos-lista" style="grid-template-columns:repeat(3,1fr)">
            <div><div class="dato-l">Apto</div><div class="dato-v">&lt; ${lim.aptoSistolica}/${lim.aptoDiastolica}</div></div>
            <div><div class="dato-l">Revalorar</div><div class="dato-v">${lim.aptoSistolica}/${lim.aptoDiastolica} – ${lim.noAptoSistolica - 1}/${lim.noAptoDiastolica - 1}</div></div>
            <div><div class="dato-l">No apto</div><div class="dato-v">≥ ${lim.noAptoSistolica}/${lim.noAptoDiastolica}</div></div>
          </div>
        </div>
        <div class="card">
          <div class="card-hdr"><div><div class="card-hdr-t">Pendientes de este turno</div><div class="card-hdr-s">Trabajadores de alto riesgo en turno sin toma todavía</div></div>${badge(String(pendientes.length), pendientes.length ? 'badge-ambar' : 'badge-verde')}</div>
          ${pendientes.length ? pendientes.slice(0, 8).map(t => `<div class="persona"><span><b>${esc(nombreCompleto(t))}</b><div class="sub">${esc(t.tiposAltoRiesgo.join(', '))}</div></span>
            <button class="btn-tbl" data-evaluar="${t.id}">Evaluar</button></div>`).join('') + (pendientes.length > 8 ? `<div class="sub">y ${pendientes.length - 8} más…</div>` : '')
            : '<div class="vacio">Todos los trabajadores de alto riesgo del turno ya fueron evaluados.</div>'}
        </div>
      </div>
    </div>
    <div class="card">
      <div class="filtros">
        <div><div class="card-hdr-t">Registro de evaluaciones</div></div>
        <div class="espacio"></div>
        <div class="segmentos" id="f-rango-ar">${[['1', 'Hoy'], ['7', '7 días'], ['30', '30 días']].map(([v, t]) =>
          `<button type="button" data-v="${v}" class="${rangoAR === v ? 'activo' : ''}">${t}</button>`).join('')}</div>
      </div>
      <div class="chips" id="resumen-ar" style="margin-bottom:12px"></div>
      <div class="tabla-wrap" id="tabla-ar"></div>
    </div>`;

  const form = document.getElementById('form-ar');
  const sel = document.getElementById('sel-ar'), selTipo = document.getElementById('sel-tipo');

  document.getElementById('buscar-ar').oninput = e => {
    const q = e.target.value.toLowerCase();
    [...sel.options].forEach(o => { if (o.value) o.hidden = q && !o.text.toLowerCase().includes(q); });
    const vis = [...sel.options].filter(o => o.value && !o.hidden);
    if (vis.length === 1) { sel.value = vis[0].value; sel.onchange(); }
  };

  sel.onchange = () => {
    const t = datos.trabajadores.find(x => x.id === sel.value);
    const info = document.getElementById('info-ar');
    if (!t) { info.innerHTML = ''; return; }
    selTipo.innerHTML = opciones(t.tiposAltoRiesgo.map(x => [x, x]), t.tiposAltoRiesgo[0]);
    const s = evaluarSalud(datos, t);
    const ultima = datos.evaluaciones.filter(e => e.trabajadorId === t.id).sort((a, b) => b.fecha.localeCompare(a.fecha))[0];
    info.innerHTML = `<div class="nota-privada" style="margin-bottom:14px">
      ${ultima ? `Última toma: <b>${ultima.sistolica}/${ultima.diastolica}</b> el ${fechaHora(ultima.fecha)} · ${DICTAMENES[ultima.dictamen].nombre}` : 'Sin tomas previas.'}
      ${t.antecedentes.hipertension ? '<br>Antecedente de hipertensión.' : ''}
      ${s.alertas.length ? `<br>Alertas: ${esc(s.alertas.map(a => a.titulo).join(' · '))}` : ''}</div>`;
  };

  const pintarDictamen = () => {
    const v = leerFormulario(form), caja = document.getElementById('dictamen');
    if (!v.sistolica || !v.diastolica) { caja.className = 'dictamen'; caja.innerHTML = '<div class="dictamen-t">—</div><div class="dictamen-s">Captura la presión para ver el dictamen</div>'; return; }
    const d = dictaminar(v.sistolica, v.diastolica, lim);
    const texto = { apto: 'Puede realizar el trabajo', revalorar: 'Repetir toma en 10 min en reposo antes de decidir', no_apto: 'NO debe realizar el trabajo. Notificar al supervisor.' }[d];
    caja.className = 'dictamen ' + d;
    caja.innerHTML = `<div class="dictamen-t">${DICTAMENES[d].icono} ${DICTAMENES[d].nombre}</div><div class="dictamen-s">${v.sistolica}/${v.diastolica} mmHg · ${texto}</div>`;
  };
  form.addEventListener('input', pintarDictamen);

  form.onsubmit = e => {
    e.preventDefault();
    const v = leerFormulario(form);
    const t = datos.trabajadores.find(x => x.id === v.trabajadorId);
    if (!t) return aviso('Selecciona al trabajador.');
    if (!v.tipoTrabajo) return aviso('Selecciona el trabajo a realizar.');
    if (!v.sistolica || !v.diastolica) return aviso('Captura la presión arterial.');
    if (v.sistolica <= v.diastolica) return aviso('La sistólica debe ser mayor que la diastólica.');
    const d = dictaminar(v.sistolica, v.diastolica, lim);
    if (d === 'no_apto' && !confirm(`${nombreCompleto(t)} resulta NO APTO (${v.sistolica}/${v.diastolica}). ¿Registrar el dictamen? Recuerda avisar al supervisor.`)) return;
    const fecha = new Date();
    datos.evaluaciones.push({
      id: DB.nuevoId('e'), trabajadorId: t.id, paramedicoId: yo.id, ambulanciaId: ambulanciaActual(datos),
      fecha: fecha.toISOString(), ...sellarTurno(fecha), tipoTrabajo: v.tipoTrabajo,
      sistolica: v.sistolica, diastolica: v.diastolica, fc: v.fc, dictamen: d, observaciones: v.observaciones,
    });
    DB.guardar(datos);
    aviso(`${nombreCompleto(t)}: ${DICTAMENES[d].nombre}`);
    if (location.hash === '#altoriesgo') refrescar(); else ir('altoriesgo');
  };

  // Registro
  const pintarRegistro = () => {
    const desde = rangoAR === '1' ? fechaISO(new Date()) : fechaISO(new Date(Date.now() - (Number(rangoAR) - 1) * DIA_MS));
    const lista = datos.evaluaciones.filter(e => fechaISO(new Date(e.fecha)) >= desde).sort((a, b) => b.fecha.localeCompare(a.fecha));
    const cuenta = k => lista.filter(e => e.dictamen === k).length;
    document.getElementById('resumen-ar').innerHTML = badge(`${lista.length} evaluaciones`, 'badge-azul') +
      Object.entries(DICTAMENES).map(([k, d]) => badge(`${d.icono} ${d.nombre}: ${cuenta(k)}`, d.badge)).join('');
    document.getElementById('tabla-ar').innerHTML = `<table>
      <thead><tr><th>Fecha</th><th>Trabajador</th><th>Trabajo</th><th class="derecha">Presión</th><th class="derecha">FC</th><th>Dictamen</th><th>Paramédico</th></tr></thead>
      <tbody>${lista.slice(0, 150).map(e => {
        const t = datos.trabajadores.find(x => x.id === e.trabajadorId), u = datos.usuarios.find(x => x.id === e.paramedicoId);
        const d = DICTAMENES[e.dictamen];
        return `<tr class="clic" onclick="ir('trabajador/${e.trabajadorId}')">
          <td class="nowrap">${fechaHora(e.fecha)}<div class="sub">${esc(nombreTurno(turnoPorClave(e.esquema, e.turno)))}</div></td>
          <td><b>${esc(nombreCompleto(t))}</b></td><td>${esc(e.tipoTrabajo)}</td>
          <td class="derecha num">${e.sistolica}/${e.diastolica}</td><td class="derecha num">${e.fc ?? '—'}</td>
          <td>${badge(`${d.icono} ${d.nombre}`, d.badge)}</td><td>${esc(u ? u.nombre : '—')}</td></tr>`;
      }).join('') || '<tr><td colspan="7" class="vacio">Sin evaluaciones en este periodo</td></tr>'}</tbody></table>`;
  };
  pintarRegistro();
  document.getElementById('f-rango-ar').onclick = e => {
    const b = e.target.closest('button'); if (!b) return;
    rangoAR = b.dataset.v;
    document.querySelectorAll('#f-rango-ar button').forEach(x => x.classList.toggle('activo', x === b));
    pintarRegistro();
  };

  document.querySelectorAll('[data-evaluar]').forEach(b => b.onclick = () => { sel.value = b.dataset.evaluar; sel.onchange(); form.sistolica.focus(); window.scrollTo({ top: 0, behavior: 'smooth' }); });
  const btnLim = document.getElementById('btn-limites');
  if (btnLim) btnLim.onclick = () => formularioLimites(datos);
  if (trabajadorId) sel.onchange();
};

function formularioLimites(datos) {
  const l = datos.config.limites;
  abrirModal({
    titulo: 'Límites de presión para alto riesgo',
    cuerpo: `<p class="sub" style="margin-bottom:14px">Aplican a las evaluaciones nuevas y a las alertas de salud. Deben validarlos el médico responsable.</p>
      <div class="campos">
        <div class="separador">Apto por debajo de</div>
        <div class="field"><label>Sistólica</label><input name="aptoSistolica" type="number" value="${l.aptoSistolica}"></div>
        <div class="field"><label>Diastólica</label><input name="aptoDiastolica" type="number" value="${l.aptoDiastolica}"></div>
        <div class="separador">No apto desde</div>
        <div class="field"><label>Sistólica</label><input name="noAptoSistolica" type="number" value="${l.noAptoSistolica}"></div>
        <div class="field"><label>Diastólica</label><input name="noAptoDiastolica" type="number" value="${l.noAptoDiastolica}"></div>
      </div>
      <button type="button" class="btn-tbl" id="btn-def">Volver a los valores propuestos (140/90 y 160/100)</button><div style="height:14px"></div>`,
    alAbrir(form) {
      form.querySelector('#btn-def').onclick = () => Object.entries(LIMITES_PRESION).forEach(([k, v]) => { form[k].value = v; });
    },
    onGuardar(v) {
      if (Object.values(v).some(x => !x)) return 'Captura los cuatro valores.';
      if (v.noAptoSistolica <= v.aptoSistolica || v.noAptoDiastolica <= v.aptoDiastolica) return 'El límite de "No apto" debe ser mayor que el de "Apto".';
      datos.config.limites = v;
      DB.guardar(datos);
      aviso('Límites actualizados');
      refrescar();
    },
  });
}
