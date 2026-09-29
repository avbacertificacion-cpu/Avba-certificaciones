/* ==========================================================================
   CONSULTAS: historial, nueva consulta con checklist digital (al guardar se
   descuentan del inventario los medicamentos entregados) y constancia.
   ========================================================================== */

const filtroCons = { rango: '7', turno: '', motivo: '', texto: '', mias: false };

RUTAS.consultas.dibujar = function () {
  const datos = DB.leer();
  const c = document.getElementById('contenido');
  c.innerHTML = `
    <div class="card">
      <div class="filtros">
        <div class="segmentos" id="f-rango">${[['1', 'Hoy'], ['7', '7 días'], ['30', '30 días'], ['todo', 'Todo']].map(([v, t]) =>
          `<button type="button" data-v="${v}" class="${filtroCons.rango === v ? 'activo' : ''}">${t}</button>`).join('')}</div>
        <input class="buscar" id="f-texto" type="search" placeholder="Buscar trabajador…" value="${esc(filtroCons.texto)}">
        <select id="f-turno">${opciones(listaTurnos().map(t => [t.clave, nombreTurno(t)]), filtroCons.turno, 'Todos los turnos')}</select>
        <select id="f-motivo">${opciones(MOTIVOS.map(m => [m, m]), filtroCons.motivo, 'Todos los motivos')}</select>
        ${yo.rol === 'paramedico' ? `<label class="check"><input type="checkbox" id="f-mias" ${filtroCons.mias ? 'checked' : ''}>Sólo mías</label>` : ''}
        <a class="btn btn-primario" href="#consulta-nueva">${icono('mas')}Nueva consulta</a>
      </div>
      <div id="resumen-cons" class="chips" style="margin-bottom:12px"></div>
      <div class="tabla-wrap" id="tabla-cons"></div>
    </div>`;

  const pintar = () => {
    const desde = filtroCons.rango === 'todo' ? '' : filtroCons.rango === '1' ? fechaISO(new Date())
      : fechaISO(new Date(Date.now() - (Number(filtroCons.rango) - 1) * DIA_MS));
    const q = filtroCons.texto.toLowerCase();
    const lista = datos.consultas.filter(x => {
      if (desde && fechaISO(new Date(x.fecha)) < desde) return false;
      if (filtroCons.turno && x.turno !== filtroCons.turno) return false;
      if (filtroCons.motivo && x.motivo !== filtroCons.motivo) return false;
      if (filtroCons.mias && x.paramedicoId !== yo.id) return false;
      if (q) { const t = datos.trabajadores.find(y => y.id === x.trabajadorId); if (!nombreCompleto(t).toLowerCase().includes(q)) return false; }
      return true;
    }).sort((a, b) => b.fecha.localeCompare(a.fecha));

    const traslados = lista.filter(x => x.destino === 'traslado').length;
    const piezas = lista.reduce((s, x) => s + x.receta.reduce((a, r) => a + r.cantidad, 0), 0);
    document.getElementById('resumen-cons').innerHTML =
      badge(`${lista.length} consultas`, 'badge-azul') + badge(`${traslados} traslados`, traslados ? 'badge-rojo' : 'badge-gris') + badge(`${piezas} unidades de medicamento entregadas`, 'badge-gris');

    document.getElementById('tabla-cons').innerHTML = `<table>
      <thead><tr><th>Fecha</th><th>Trabajador</th><th>Motivo</th><th>Diagnóstico</th><th>Destino</th><th>Atendió</th></tr></thead>
      <tbody>${lista.slice(0, 200).map(x => {
        const t = datos.trabajadores.find(y => y.id === x.trabajadorId);
        const u = datos.usuarios.find(y => y.id === x.paramedicoId);
        return `<tr class="clic" data-id="${x.id}">
          <td class="nowrap">${fechaHora(x.fecha)}<div class="sub">${esc(nombreTurno(turnoPorClave(x.esquema, x.turno)))}</div></td>
          <td><b>${esc(nombreCompleto(t))}</b><div class="sub">${esc(t ? t.puesto : '')}</div></td>
          <td>${esc(x.motivo)}</td>
          <td>${esc(x.diagnostico)}${x.receta.length ? `<div class="sub">${x.receta.length} medicamento(s)</div>` : ''}</td>
          <td>${badge(DESTINOS[x.destino].nombre, DESTINOS[x.destino].badge)}</td>
          <td>${esc(u ? u.nombre : '—')}</td></tr>`;
      }).join('') || '<tr><td colspan="6" class="vacio">No hay consultas con esos filtros</td></tr>'}</tbody></table>
      ${lista.length > 200 ? '<div class="sub" style="margin-top:8px">Se muestran las 200 más recientes.</div>' : ''}`;
  };
  pintar();

  document.getElementById('f-rango').onclick = e => {
    const b = e.target.closest('button'); if (!b) return;
    filtroCons.rango = b.dataset.v;
    document.querySelectorAll('#f-rango button').forEach(x => x.classList.toggle('activo', x === b));
    pintar();
  };
  document.getElementById('f-texto').oninput = e => { filtroCons.texto = e.target.value; pintar(); };
  document.getElementById('f-turno').onchange = e => { filtroCons.turno = e.target.value; pintar(); };
  document.getElementById('f-motivo').onchange = e => { filtroCons.motivo = e.target.value; pintar(); };
  const mias = document.getElementById('f-mias');
  if (mias) mias.onchange = () => { filtroCons.mias = mias.checked; pintar(); };
  document.getElementById('tabla-cons').onclick = e => { const f = e.target.closest('tr[data-id]'); if (f) ir('consulta/' + f.dataset.id); };
};

/* ---------- Nueva consulta ---------- */

// Alergias de grupo: si el trabajador es alérgico, se avisa al recetar
const GRUPOS_ALERGIA = {
  'AINE': ['Ibuprofeno', 'Naproxeno', 'Diclofenaco', 'Metamizol'],
  'Sulfas': ['Sulfadiazina'],
};
function choqueAlergia(trabajador, medicamento) {
  const alergia = (trabajador.alergias || '').toLowerCase();
  if (!alergia || alergia === 'ninguna') return null;
  for (const [grupo, meds] of Object.entries(GRUPOS_ALERGIA))
    if (alergia.includes(grupo.toLowerCase()) && meds.some(m => medicamento.nombre.startsWith(m))) return grupo;
  const primera = medicamento.nombre.split(' ')[0].toLowerCase();
  return alergia.includes(primera) ? medicamento.nombre : null;
}

RUTAS['consulta-nueva'].dibujar = function (trabajadorId) {
  const datos = DB.leer();
  const c = document.getElementById('contenido');
  const activos = datos.trabajadores.filter(t => t.activo).sort((a, b) => a.apellidos.localeCompare(b.apellidos));
  const meds = datos.medicamentos.filter(m => m.activo);
  const t8 = detectarTurno(new Date(), yo.esquema || '8h');

  c.innerHTML = `
    <a class="volver" href="#consultas">${icono('volver', 'width="14" height="14"')}Consultas</a>
    <form id="form-consulta" novalidate>
      <div class="card"><div class="paso"><div class="paso-n">1</div><div class="paso-c">
        <div class="card-hdr"><div><div class="card-hdr-t">Trabajador</div><div class="card-hdr-s">Turno ${esc(nombreTurno(t8))} · ${new Date().toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' })}</div></div></div>
        <div class="campos">
          <div class="field"><label>Buscar</label><input id="buscar-trab" type="search" placeholder="Nombre o número de empleado"></div>
          <div class="field"><label>Trabajador *</label><select name="trabajadorId" id="sel-trab">${opciones(activos.map(t => [t.id, `${t.apellidos}, ${t.nombre} · ${t.numEmpleado}`]), trabajadorId, 'Selecciona…')}</select></div>
        </div>
        <div id="ficha-trab"></div>
      </div></div></div>

      <div class="card"><div class="paso"><div class="paso-n">2</div><div class="paso-c">
        <div class="card-hdr"><div><div class="card-hdr-t">Signos vitales</div><div class="card-hdr-s">La presión es obligatoria; alimenta las alertas de salud</div></div></div>
        <div class="campos campos-4">
          <div class="field"><label>Sistólica *</label><div class="con-unidad"><input name="sistolica" type="number" min="60" max="260" inputmode="numeric"><span>mmHg</span></div></div>
          <div class="field"><label>Diastólica *</label><div class="con-unidad"><input name="diastolica" type="number" min="30" max="160" inputmode="numeric"><span>mmHg</span></div></div>
          <div class="field"><label>Frec. cardiaca</label><div class="con-unidad"><input name="fc" type="number" min="30" max="220" inputmode="numeric"><span>lpm</span></div></div>
          <div class="field"><label>Frec. respiratoria</label><div class="con-unidad"><input name="fr" type="number" min="6" max="60" inputmode="numeric"><span>rpm</span></div></div>
          <div class="field"><label>Temperatura</label><div class="con-unidad"><input name="temperatura" type="number" step="0.1" min="33" max="43" inputmode="decimal"><span>°C</span></div></div>
          <div class="field"><label>Saturación O₂</label><div class="con-unidad"><input name="spo2" type="number" min="50" max="100" inputmode="numeric"><span>%</span></div></div>
          <div class="field"><label>Glucosa</label><div class="con-unidad"><input name="glucosa" type="number" min="20" max="600" inputmode="numeric"><span>mg/dL</span></div>
            <label class="check" style="margin-top:6px;padding:5px 9px"><input type="checkbox" name="ayuno">En ayuno</label></div>
          <div class="field"><label>Peso / talla</label><div style="display:flex;gap:6px">
            <div class="con-unidad" style="flex:1"><input name="peso" type="number" min="30" max="250" inputmode="decimal"><span>kg</span></div>
            <div class="con-unidad" style="flex:1"><input name="talla" type="number" min="120" max="220" inputmode="numeric"><span>cm</span></div></div></div>
        </div>
        <div class="chips" id="indicadores"></div>
      </div></div></div>

      <div class="card"><div class="paso"><div class="paso-n">3</div><div class="paso-c">
        <div class="card-hdr"><div><div class="card-hdr-t">Motivo y checklist</div><div class="card-hdr-s">Los puntos marcados como obligatorios deben completarse para guardar</div></div></div>
        <div class="campos">
          <div class="field"><label>Motivo de consulta *</label><select name="motivo">${opciones(MOTIVOS.map(m => [m, m]), '', 'Selecciona…')}</select></div>
          <div class="field"><label>Descripción</label><input name="descripcion" placeholder="¿Qué le pasó? ¿Desde cuándo?"></div>
        </div>
        <div class="checklist">${CHECKLIST.map(x => `<label class="check"><input type="checkbox" name="chk_${x.clave}">${esc(x.texto)}${x.obligatorio ? '<span class="oblig">OBLIGATORIO</span>' : ''}</label>`).join('')}</div>
      </div></div></div>

      <div class="card"><div class="paso"><div class="paso-n">4</div><div class="paso-c">
        <div class="card-hdr"><div><div class="card-hdr-t">Diagnóstico y medicamentos</div><div class="card-hdr-s">Lo que se entregue se descuenta del inventario al guardar</div></div>
          <button type="button" class="btn btn-borde" id="btn-agregar-med">${icono('mas')}Agregar medicamento</button></div>
        <div class="field"><label>Diagnóstico / impresión clínica *</label><input name="diagnostico"></div>
        <div id="receta"></div>
        <div id="aviso-alergia"></div>
      </div></div></div>

      <div class="card"><div class="paso"><div class="paso-n">5</div><div class="paso-c">
        <div class="card-hdr"><div><div class="card-hdr-t">Destino</div></div></div>
        <div class="checks" style="margin-bottom:14px">${Object.entries(DESTINOS).map(([k, d], i) =>
          `<label class="check"><input type="radio" name="destino" value="${k}" ${i === 0 ? 'checked' : ''} style="accent-color:var(--azul)">${esc(d.nombre)}</label>`).join('')}</div>
        <div class="campos">
          <div class="field"><label>Ambulancia</label><select name="ambulanciaId">${opciones(datos.ambulancias.map(a => [a.id, `${a.clave} · ${a.tipo}`]), ambulanciaActual(datos), 'Sin ambulancia')}</select></div>
          <div class="field"><label>Notas</label><input name="notas" placeholder="Opcional"></div>
        </div>
      </div></div></div>

      <div class="barra-guardar">
        <span class="estado" id="estado-form"></span>
        <a class="btn btn-borde" href="#consultas">Cancelar</a>
        <button class="btn btn-primario" type="submit">${icono('consultas')}Guardar consulta</button>
      </div>
    </form>`;

  const form = document.getElementById('form-consulta');
  const selTrab = document.getElementById('sel-trab');
  const trab = () => datos.trabajadores.find(t => t.id === selTrab.value);

  // Filtro rápido del select de trabajadores
  document.getElementById('buscar-trab').oninput = e => {
    const q = e.target.value.toLowerCase();
    [...selTrab.options].forEach(o => { if (o.value) o.hidden = q && !o.text.toLowerCase().includes(q); });
    const visibles = [...selTrab.options].filter(o => o.value && !o.hidden);
    if (visibles.length === 1) { selTrab.value = visibles[0].value; selTrab.onchange(); }
  };

  selTrab.onchange = () => {
    const t = trab();
    const ficha = document.getElementById('ficha-trab');
    if (!t) { ficha.innerHTML = ''; return; }
    const s = evaluarSalud(datos, t);
    const ant = Object.entries(ANTECEDENTES).filter(([k]) => t.antecedentes[k]).map(([, n]) => n);
    const alergico = t.alergias && t.alergias !== 'Ninguna';
    ficha.innerHTML = `
      <div class="datos-lista" style="margin-top:4px">
        <div><div class="dato-l">Edad</div><div class="dato-v">${edad(t.fechaNacimiento)} años · ${t.sexo === 'H' ? 'H' : 'M'}</div></div>
        <div><div class="dato-l">Puesto</div><div class="dato-v">${esc(t.puesto)}</div></div>
        <div><div class="dato-l">Alergias</div><div class="dato-v" style="${alergico ? 'color:var(--rojo);font-weight:700' : ''}">${alergico ? '⚠ ' : ''}${esc(t.alergias)}</div></div>
        <div><div class="dato-l">Antecedentes</div><div class="dato-v">${ant.length ? esc(ant.join(', ')) : 'Ninguno'}</div></div>
        <div><div class="dato-l">Salud</div><div class="dato-v">${badge(RIESGOS[s.riesgo].nombre, RIESGOS[s.riesgo].badge)}</div></div>
      </div>
      ${s.alertas.length ? `<div class="sub" style="margin-top:10px">Alertas: ${esc(s.alertas.map(a => a.titulo).join(' · '))}</div>` : ''}`;
    if (form.peso.value === '') form.peso.value = t.peso ?? '';
    if (form.talla.value === '') form.talla.value = t.talla ?? '';
    indicadores();
    revisarReceta();
  };

  // Indicadores en vivo de los signos
  const indicadores = () => {
    const v = leerFormulario(form), chips = [];
    const p = clasificarPresion(v.sistolica, v.diastolica);
    if (p) chips.push(badge(`Presión: ${p.texto}`, p.badge));
    const imc = calcularIMC(v.peso, v.talla), ci = clasificarIMC(imc);
    if (ci) chips.push(badge(`IMC ${imc}: ${ci.texto}`, ci.badge));
    if (v.temperatura >= 38) chips.push(badge('Fiebre', 'badge-rojo'));
    else if (v.temperatura >= 37.5) chips.push(badge('Febrícula', 'badge-ambar'));
    if (v.spo2 && v.spo2 < 92) chips.push(badge('Saturación baja', 'badge-rojo'));
    if (v.fc && (v.fc > 100 || v.fc < 50)) chips.push(badge(v.fc > 100 ? 'Taquicardia' : 'Bradicardia', 'badge-ambar'));
    if (v.glucosa) {
      const alta = v.ayuno ? v.glucosa >= 126 : v.glucosa >= 200, limite = v.ayuno ? v.glucosa >= 100 : v.glucosa >= 140;
      chips.push(badge(`Glucosa ${alta ? 'alta' : limite ? 'en límite' : 'normal'}`, alta ? 'badge-rojo' : limite ? 'badge-ambar' : 'badge-verde'));
    }
    document.getElementById('indicadores').innerHTML = chips.join('');
    actualizarEstado();
  };
  form.addEventListener('input', e => { if (e.target.closest('.campos-4')) indicadores(); if (e.target.name && e.target.name.startsWith('chk_')) actualizarEstado(); });
  form.addEventListener('change', e => { if (e.target.name && e.target.name.startsWith('chk_')) actualizarEstado(); if (e.target.name === 'ayuno') indicadores(); });

  const actualizarEstado = () => {
    const oblig = CHECKLIST.filter(x => x.obligatorio);
    const hechos = oblig.filter(x => form['chk_' + x.clave].checked).length;
    document.getElementById('estado-form').innerHTML =
      `Checklist: <b>${hechos} / ${oblig.length}</b> obligatorios` + (hechos === oblig.length ? ' ✓' : '');
  };

  // Receta: filas de medicamento
  const receta = document.getElementById('receta');
  const agregarFila = () => {
    const fila = document.createElement('div');
    fila.className = 'receta-fila';
    fila.innerHTML = `
      <div><select class="r-med">${opciones(meds.map(m => [m.id, `${m.nombre} (${m.stock} ${m.unidad})`]), '', 'Medicamento…')}</select><div class="stock-info"></div></div>
      <input class="r-cant" type="number" min="1" placeholder="Cantidad" inputmode="numeric">
      <input class="r-ind ind" placeholder="Indicaciones (dosis, cada cuánto)">
      <button type="button" class="btn-quitar" title="Quitar">×</button>`;
    receta.appendChild(fila);
    fila.querySelector('.btn-quitar').onclick = () => { fila.remove(); revisarReceta(); };
    fila.querySelector('.r-med').onchange = revisarReceta;
    fila.querySelector('.r-cant').oninput = revisarReceta;
  };
  document.getElementById('btn-agregar-med').onclick = agregarFila;

  // Revisa existencias (sumando filas repetidas) y alergias
  const revisarReceta = () => {
    const t = trab(), usados = {}, choques = [];
    const filas = [...receta.querySelectorAll('.receta-fila')];
    filas.forEach(f => { const id = f.querySelector('.r-med').value; if (id) usados[id] = (usados[id] || 0) + (Number(f.querySelector('.r-cant').value) || 0); });
    let problemas = 0;
    filas.forEach(f => {
      const m = meds.find(x => x.id === f.querySelector('.r-med').value), info = f.querySelector('.stock-info');
      if (!m) { info.textContent = ''; info.classList.remove('mal'); return; }
      const falta = usados[m.id] > m.stock;
      info.textContent = m.stock === 0 ? 'Agotado' : falta ? `Sólo hay ${m.stock} ${m.unidad}` : `Quedarán ${m.stock - usados[m.id]} ${m.unidad}`;
      info.classList.toggle('mal', falta || m.stock === 0);
      if (falta || m.stock === 0) problemas++;
      const choque = t && choqueAlergia(t, m);
      if (choque) choques.push(`${m.nombre} (alergia a ${choque})`);
    });
    document.getElementById('aviso-alergia').innerHTML = choques.length
      ? `<div class="alerta alto" style="margin-top:8px"><div class="alerta-i">!</div><div><div class="alerta-t">Posible alergia</div>
         <div class="alerta-p">${esc(nombreCompleto(t))} tiene alergia registrada a <b>${esc(t.alergias)}</b>: ${esc(choques.join(', '))}.</div></div></div>` : '';
    return { problemas, choques, usados };
  };

  form.onsubmit = e => {
    e.preventDefault();
    const v = leerFormulario(form);
    const t = trab();
    const error = msg => { aviso(msg); };
    if (!t) return error('Selecciona al trabajador.');
    if (!v.sistolica || !v.diastolica) return error('Captura la presión arterial.');
    if (v.sistolica <= v.diastolica) return error('La sistólica debe ser mayor que la diastólica.');
    if (!v.motivo) return error('Selecciona el motivo de consulta.');
    const faltan = CHECKLIST.filter(x => x.obligatorio && !v['chk_' + x.clave]);
    if (faltan.length) return error(`Falta en el checklist: ${faltan[0].texto}.`);
    if (!v.diagnostico) return error('Escribe el diagnóstico.');

    const { problemas, choques } = revisarReceta();
    const filas = [...receta.querySelectorAll('.receta-fila')].map(f => ({
      medicamentoId: f.querySelector('.r-med').value, cantidad: Number(f.querySelector('.r-cant').value) || 0,
      indicaciones: f.querySelector('.r-ind').value.trim() })).filter(r => r.medicamentoId || r.cantidad);
    if (filas.some(r => !r.medicamentoId || r.cantidad < 1)) return error('Cada medicamento necesita cantidad (1 o más).');
    if (problemas) return error('No hay suficiente existencia de algún medicamento.');
    if (choques.length && !confirm(`Atención: posible alergia (${choques.join(', ')}). ¿Entregar de todos modos?`)) return;

    const destino = form.querySelector('input[name=destino]:checked').value;
    const ahora = new Date();
    const consulta = {
      id: DB.nuevoId('c'), trabajadorId: t.id, paramedicoId: yo.id, ambulanciaId: v.ambulanciaId || null,
      fecha: ahora.toISOString(), ...sellarTurno(ahora),
      signos: { sistolica: v.sistolica, diastolica: v.diastolica, fc: v.fc, fr: v.fr, temperatura: v.temperatura, spo2: v.spo2,
        glucosa: v.glucosa, ayuno: v.glucosa ? !!v.ayuno : null, peso: v.peso, talla: v.talla },
      motivo: v.motivo, descripcion: v.descripcion,
      checklist: Object.fromEntries(CHECKLIST.map(x => [x.clave, !!v['chk_' + x.clave]])),
      diagnostico: v.diagnostico, receta: filas, destino, notas: v.notas,
    };

    // Descontar del inventario y dejar rastro del movimiento
    filas.forEach(r => {
      const m = datos.medicamentos.find(x => x.id === r.medicamentoId);
      m.stock -= r.cantidad;
      datos.movimientos.push({ id: DB.nuevoId('mv'), medicamentoId: m.id, tipo: 'salida', cantidad: r.cantidad,
        fecha: ahora.toISOString(), usuarioId: yo.id, consultaId: consulta.id, nota: 'Entregado en consulta' });
    });
    if (v.peso) t.peso = v.peso;
    if (v.talla) t.talla = v.talla;
    datos.consultas.push(consulta);
    DB.guardar(datos);

    const bajos = filas.map(r => datos.medicamentos.find(x => x.id === r.medicamentoId)).filter(m => m.stock <= m.minimo);
    aviso(bajos.length ? `Consulta guardada. ${bajos[0].nombre} quedó en mínimo de inventario.` : 'Consulta guardada');
    ir('consulta/' + consulta.id);
  };

  actualizarEstado();
  if (trabajadorId) selTrab.onchange();
};

/* ---------- Detalle / constancia ---------- */
RUTAS.consulta.dibujar = function (id) {
  const datos = DB.leer();
  const x = datos.consultas.find(y => y.id === id);
  const c = document.getElementById('contenido');
  if (!x) { c.innerHTML = '<div class="card vacio">No se encontró la consulta. <a href="#consultas">Volver</a></div>'; return; }
  const t = datos.trabajadores.find(y => y.id === x.trabajadorId);
  const u = datos.usuarios.find(y => y.id === x.paramedicoId);
  const a = datos.ambulancias.find(y => y.id === x.ambulanciaId);
  const s = x.signos;
  const p = clasificarPresion(s.sistolica, s.diastolica);
  const imc = calcularIMC(s.peso, s.talla);
  const signo = (l, val, uni) => `<div><div class="dato-l">${l}</div><div class="dato-v">${val == null || val === '' ? '—' : `${esc(val)} ${uni}`}</div></div>`;

  c.innerHTML = `
    <div class="no-imprimir" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
      <a class="volver" style="margin:0 auto 0 0" href="#consultas">${icono('volver', 'width="14" height="14"')}Consultas</a>
      <a class="btn btn-borde" href="#trabajador/${t.id}">Ver expediente</a>
      <button class="btn btn-primario" onclick="window.print()">${icono('imprimir')}Imprimir constancia</button>
    </div>
    <div class="card">
      <div class="solo-impresion" style="margin-bottom:12px"><b>AVBA · Servicio Médico</b> — ${esc(datos.planta.nombre)}</div>
      <div class="perfil">
        <div class="perfil-avatar">${esc(iniciales(nombreCompleto(t)))}</div>
        <div><div class="perfil-n">${esc(nombreCompleto(t))}</div>
          <div class="perfil-s">${esc(t.numEmpleado)} · ${esc(t.puesto)} · ${edad(t.fechaNacimiento)} años · Alergias: ${esc(t.alergias)}</div></div>
        <div class="acciones">${badge(DESTINOS[x.destino].nombre, DESTINOS[x.destino].badge)}</div>
      </div>
      <div class="datos-lista">
        <div><div class="dato-l">Fecha y hora</div><div class="dato-v">${new Date(x.fecha).toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' })}</div></div>
        <div><div class="dato-l">Turno</div><div class="dato-v">${esc(nombreTurno(turnoPorClave(x.esquema, x.turno)))}</div></div>
        <div><div class="dato-l">Atendió</div><div class="dato-v">${esc(u ? u.nombre : '—')}</div></div>
        <div><div class="dato-l">Ambulancia</div><div class="dato-v">${a ? esc(a.clave) : '—'}</div></div>
      </div>
    </div>
    <div class="grid grid-2">
      <div class="card">
        <div class="card-hdr"><div class="card-hdr-t">Signos vitales</div>${p ? badge(p.texto, p.badge) : ''}</div>
        <div class="datos-lista" style="border:none;margin:0;padding:0">
          ${signo('Presión', `${s.sistolica}/${s.diastolica}`, 'mmHg')}${signo('Frec. cardiaca', s.fc, 'lpm')}${signo('Frec. resp.', s.fr, 'rpm')}
          ${signo('Temperatura', s.temperatura, '°C')}${signo('Saturación', s.spo2, '%')}
          ${signo('Glucosa', s.glucosa, s.glucosa ? `mg/dL${s.ayuno ? ' (ayuno)' : ''}` : '')}
          ${signo('Peso', s.peso, 'kg')}${signo('Talla', s.talla, 'cm')}${signo('IMC', imc, '')}
        </div>
      </div>
      <div class="card">
        <div class="card-hdr"><div class="card-hdr-t">Checklist</div></div>
        ${CHECKLIST.map(k => `<div class="persona"><span>${esc(k.texto)}</span>${x.checklist[k.clave] ? badge('Sí', 'badge-verde') : badge('No', 'badge-gris')}</div>`).join('')}
      </div>
    </div>
    <div class="card">
      <div class="datos-lista" style="border:none;margin:0 0 14px;padding:0">
        <div><div class="dato-l">Motivo</div><div class="dato-v">${esc(x.motivo)}</div></div>
        <div style="grid-column:span 2"><div class="dato-l">Diagnóstico</div><div class="dato-v">${esc(x.diagnostico)}</div></div>
        ${x.descripcion ? `<div style="grid-column:1/-1"><div class="dato-l">Descripción</div><div class="dato-v">${esc(x.descripcion)}</div></div>` : ''}
        ${x.notas ? `<div style="grid-column:1/-1"><div class="dato-l">Notas</div><div class="dato-v">${esc(x.notas)}</div></div>` : ''}
      </div>
      <div class="card-hdr-t" style="margin-bottom:8px">Medicamentos entregados</div>
      <div class="tabla-wrap"><table>
        <thead><tr><th>Medicamento</th><th class="derecha">Cantidad</th><th>Indicaciones</th></tr></thead>
        <tbody>${x.receta.map(r => { const m = datos.medicamentos.find(y => y.id === r.medicamentoId);
          return `<tr><td>${esc(m ? m.nombre : '—')}</td><td class="derecha num">${r.cantidad} ${esc(m ? m.unidad : '')}</td><td>${esc(r.indicaciones || '—')}</td></tr>`; }).join('')
          || '<tr><td colspan="3" class="vacio">No se entregaron medicamentos</td></tr>'}</tbody></table></div>
      <div class="solo-impresion" style="margin-top:50px"><div style="display:flex;justify-content:space-between">
        <div style="border-top:1px solid #333;padding-top:6px;width:40%;text-align:center;font-size:12px">${esc(u ? u.nombre : '')}<br>Atendió</div>
        <div style="border-top:1px solid #333;padding-top:6px;width:40%;text-align:center;font-size:12px">${esc(nombreCompleto(t))}<br>Trabajador</div>
      </div></div>
    </div>`;
};
