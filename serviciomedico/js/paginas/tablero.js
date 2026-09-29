/* ==========================================================================
   TABLERO: indicadores y gráficas del servicio médico. Cada gráfica tiene
   debajo su tabla de datos ("Ver datos en tabla").
   ========================================================================== */

let periodoTablero = 30;

RUTAS.tablero.dibujar = function () {
  const datos = DB.leer();
  const admin = yo.rol === 'admin';
  const dias = ultimosDias(periodoTablero);
  const desde = dias[0];
  const enPeriodo = x => fechaISO(new Date(x.fecha)) >= desde;
  const consultas = datos.consultas.filter(enPeriodo);
  const evals = datos.evaluaciones.filter(enPeriodo);
  const hoy = fechaISO(new Date());
  const consultasHoy = datos.consultas.filter(c => fechaISO(new Date(c.fecha)) === hoy).length;
  const salud = evaluarTodos(datos);
  const noAptos = evals.filter(e => e.dictamen === 'no_apto').length;
  const traslados = consultas.filter(c => c.destino === 'traslado').length;
  const promedio = (consultas.length / periodoTablero).toFixed(1);

  // --- Series ---
  const porDia = dias.map(d => consultas.filter(c => fechaISO(new Date(c.fecha)) === d).length);
  const turnos = listaTurnos();
  const porTurno = turnos.map(t => consultas.filter(c => c.turno === t.clave).length);
  const evalDia = k => dias.map(d => evals.filter(e => e.dictamen === k && fechaISO(new Date(e.fecha)) === d).length);
  const motivos = contar(consultas, c => c.motivo).slice(0, 8);
  const areas = contar(consultas, c => (datos.trabajadores.find(t => t.id === c.trabajadorId) || {}).area || '—');
  const niveles = ['alto', 'medio', 'bajo'].map(n => salud.filter(s => s.riesgo === n).length);
  const usoMed = contar(datos.movimientos.filter(m => m.tipo === 'salida' && enPeriodo(m)), m => m.medicamentoId, m => m.cantidad)
    .slice(0, 8).map(([id, n]) => [(datos.medicamentos.find(x => x.id === id) || {}).nombre || id, n]);
  const porParamedico = contar(consultas, c => (datos.usuarios.find(u => u.id === c.paramedicoId) || {}).nombre || '—');
  const propensos = salud.filter(s => s.alertas.length).slice(0, 8);
  const enMinimo = datos.medicamentos.filter(m => m.activo && m.stock <= m.minimo).sort((a, b) => a.stock - b.stock);

  const tarjeta = (titulo, sub, cuerpo, extra = '') => `<div class="card" ${extra}><div class="card-hdr"><div><div class="card-hdr-t">${titulo}</div><div class="card-hdr-s">${sub}</div></div></div>${cuerpo}</div>`;

  document.getElementById('contenido').innerHTML = `
    <div class="filtros">
      <div class="segmentos" id="f-periodo">${[[7, '7 días'], [30, '30 días'], [60, '60 días']].map(([v, t]) =>
        `<button type="button" data-v="${v}" class="${periodoTablero === v ? 'activo' : ''}">${t}</button>`).join('')}</div>
      <span class="sub">Del ${etiquetaDia(desde)} al ${etiquetaDia(hoy)}</span>
    </div>

    <div class="grid fila-kpi">
      <div class="card" style="margin:0;display:flex;flex-direction:column;justify-content:center">
        <div class="stat-l">Consultas hoy</div>
        <div class="hero-num" style="margin:6px 0">${consultasHoy}</div>
        <div class="sub">Promedio del periodo: ${promedio} por día</div>
      </div>
      <div class="grid grid-stats" style="margin:0">
        ${stat('consultas', 'c-azul', numero(consultas.length), 'Consultas en el periodo')}
        ${stat('altoriesgo', 'c-ambar', numero(evals.length), 'Evaluaciones de alto riesgo')}
        ${stat('altoriesgo', 'c-rojo', noAptos, 'Dictámenes "No apto"')}
        ${stat('ambulancias', 'c-morado', traslados, 'Traslados a hospital')}
        ${stat('alertas', 'c-rojo', niveles[0], 'Trabajadores en riesgo alto', '#alertas')}
        ${admin ? stat('medicamentos', 'c-ambar', enMinimo.length, 'Medicamentos en mínimo', '#medicamentos') : stat('trabajadores', 'c-verde', niveles[2], 'Trabajadores sin alertas')}
      </div>
    </div>

    ${tarjeta('Consultas por día', `Total ${consultas.length} en ${periodoTablero} días`,
      `<div class="grafica"><canvas id="g-dia" aria-label="Consultas por día"></canvas></div>` +
      tablaDeGrafica('t-dia', ['Día', 'Consultas'], dias.map((d, i) => [etiquetaDia(d), porDia[i]])))}

    <div class="grid grid-2">
      ${tarjeta('Consultas por turno', 'El turno se detecta solo al registrar la consulta',
        `<div class="grafica baja"><canvas id="g-turno" aria-label="Consultas por turno"></canvas></div>` +
        tablaDeGrafica('t-turno', ['Turno', 'Consultas'], turnos.map((t, i) => [nombreTurno(t), porTurno[i]])))}
      ${tarjeta('Motivos de consulta más frecuentes', 'Los 8 principales',
        `<div class="grafica baja"><canvas id="g-motivo" aria-label="Motivos de consulta"></canvas></div>` +
        tablaDeGrafica('t-motivo', ['Motivo', 'Consultas'], motivos))}
    </div>

    ${tarjeta('Evaluaciones de alto riesgo por día', `${evals.length} tomas de presión · ${noAptos} no aptos`,
      leyenda([['Apto', COLORES.estado.apto], ['Revalorar', COLORES.estado.revalorar], ['No apto', COLORES.estado.no_apto]]) +
      `<div class="grafica"><canvas id="g-eval" aria-label="Evaluaciones de alto riesgo por día"></canvas></div>` +
      tablaDeGrafica('t-eval', ['Día', 'Apto', 'Revalorar', 'No apto'], dias.map((d, i) => [etiquetaDia(d), evalDia('apto')[i], evalDia('revalorar')[i], evalDia('no_apto')[i]])))}

    <div class="grid grid-2">
      ${tarjeta('Trabajadores por nivel de riesgo', 'Según las alertas de salud actuales',
        `<div class="grafica baja"><canvas id="g-riesgo" aria-label="Trabajadores por nivel de riesgo"></canvas></div>` +
        tablaDeGrafica('t-riesgo', ['Nivel', 'Trabajadores'], [['Riesgo alto', niveles[0]], ['Riesgo medio', niveles[1]], ['Sin alertas', niveles[2]]]))}
      ${tarjeta('Más propensos a enfermedades', 'Trabajadores con más alertas',
        `<div class="tabla-wrap"><table><thead><tr><th>Trabajador</th><th>Riesgo</th><th>Alertas</th></tr></thead><tbody>
        ${propensos.map(s => `<tr class="clic" onclick="ir('trabajador/${s.trabajador.id}')"><td><b>${esc(nombreCompleto(s.trabajador))}</b><div class="sub">${esc(s.trabajador.area)}</div></td>
          <td>${badge(RIESGOS[s.riesgo].nombre, RIESGOS[s.riesgo].badge)}</td><td class="sub">${esc(s.alertas.map(a => a.titulo).join(' · '))}</td></tr>`).join('')
          || '<tr><td colspan="3" class="vacio">Sin alertas</td></tr>'}</tbody></table></div>
        <a class="ver-tabla" href="#alertas" style="display:inline-block;margin-top:10px">Ver todas las alertas ›</a>`)}
    </div>

    <div class="grid grid-2">
      ${tarjeta('Consultas por área', 'Dónde se concentran las atenciones',
        `<div class="grafica baja"><canvas id="g-area" aria-label="Consultas por área"></canvas></div>` + tablaDeGrafica('t-area', ['Área', 'Consultas'], areas))}
      ${admin ? tarjeta('Consultas por paramédico', 'Carga de trabajo en el periodo',
        `<div class="grafica baja"><canvas id="g-param" aria-label="Consultas por paramédico"></canvas></div>` + tablaDeGrafica('t-param', ['Paramédico', 'Consultas'], porParamedico)) : ''}
    </div>

    ${admin ? `<div class="grid grid-2">
      ${tarjeta('Medicamentos más entregados', 'Unidades que salieron en consultas',
        `<div class="grafica baja"><canvas id="g-med" aria-label="Medicamentos más entregados"></canvas></div>` + tablaDeGrafica('t-med', ['Medicamento', 'Unidades'], usoMed))}
      ${tarjeta('Por agotarse', 'Existencia en o por debajo del mínimo',
        `<div class="tabla-wrap"><table><thead><tr><th>Medicamento</th><th class="derecha">Existencia</th><th class="derecha">Mínimo</th></tr></thead><tbody>
        ${enMinimo.map(m => `<tr><td>${esc(m.nombre)}</td><td class="derecha num">${m.stock === 0 ? badge('Agotado', 'badge-rojo') : `<b>${m.stock}</b>`}</td><td class="derecha num">${m.minimo}</td></tr>`).join('')
          || '<tr><td colspan="3" class="vacio">Todo el inventario está sobre el mínimo</td></tr>'}</tbody></table></div>
        <a class="ver-tabla" href="#medicamentos" style="display:inline-block;margin-top:10px">Ir al inventario ›</a>`)}
    </div>` : ''}`;

  // --- Gráficas ---
  const etiquetas = dias.map(etiquetaDia);
  const tooltipTitulo = { callbacks: { title: items => items[0].label } };
  grafica('g-dia', { type: 'bar', data: { labels: etiquetas, datasets: [barras('Consultas', porDia, COLORES.serie[0])] },
    options: { scales: ejes(), plugins: { tooltip: tooltipTitulo } } });

  grafica('g-turno', { type: 'bar', data: { labels: turnos.map(nombreTurno), datasets: [barras('Consultas', porTurno, COLORES.serie[0])] },
    options: { indexAxis: 'y', scales: ejes({ horizontal: true }) } });

  grafica('g-motivo', { type: 'bar', data: { labels: motivos.map(m => m[0]), datasets: [barras('Consultas', motivos.map(m => m[1]), COLORES.serie[0])] },
    options: { indexAxis: 'y', scales: ejes({ horizontal: true }) } });

  const apilada = (k, nombre, ultimo) => barras(nombre, evalDia(k), COLORES.estado[k],
    { borderRadius: ultimo ? 4 : 0, borderWidth: { top: 2 }, borderColor: COLORES.superficie, borderSkipped: 'start' });
  grafica('g-eval', { type: 'bar', data: { labels: etiquetas, datasets: [apilada('apto', 'Apto'), apilada('revalorar', 'Revalorar'), apilada('no_apto', 'No apto', true)] },
    options: { scales: ejes({ apilada: true }), interaction: { mode: 'index', intersect: false } } });

  grafica('g-riesgo', { type: 'bar', data: { labels: ['Riesgo alto', 'Riesgo medio', 'Sin alertas'],
    datasets: [barras('Trabajadores', niveles, [COLORES.riesgo.alto, COLORES.riesgo.medio, COLORES.riesgo.bajo])] },
    options: { indexAxis: 'y', scales: ejes({ horizontal: true }) } });

  grafica('g-area', { type: 'bar', data: { labels: areas.map(a => a[0]), datasets: [barras('Consultas', areas.map(a => a[1]), COLORES.serie[0])] },
    options: { indexAxis: 'y', scales: ejes({ horizontal: true }) } });

  if (admin) {
    grafica('g-param', { type: 'bar', data: { labels: porParamedico.map(p => p[0]), datasets: [barras('Consultas', porParamedico.map(p => p[1]), COLORES.serie[0])] },
      options: { indexAxis: 'y', scales: ejes({ horizontal: true }) } });
    grafica('g-med', { type: 'bar', data: { labels: usoMed.map(m => m[0]), datasets: [barras('Unidades', usoMed.map(m => m[1]), COLORES.serie[0])] },
      options: { indexAxis: 'y', scales: ejes({ horizontal: true }) } });
  }

  document.getElementById('f-periodo').onclick = e => {
    const b = e.target.closest('button'); if (!b) return;
    periodoTablero = Number(b.dataset.v);
    refrescar();
  };
};

// Agrupa y cuenta: devuelve [[clave, total], ...] de mayor a menor
function contar(lista, clave, valor = () => 1) {
  const m = {};
  lista.forEach(x => { const k = clave(x); m[k] = (m[k] || 0) + valor(x); });
  return Object.entries(m).sort((a, b) => b[1] - a[1]);
}
