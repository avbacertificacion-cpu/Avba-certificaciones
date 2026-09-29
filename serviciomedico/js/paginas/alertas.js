/* ==========================================================================
   ALERTAS DE SALUD: trabajadores propensos a enfermedades según su
   historial, del más al menos urgente. Cada alerta dice por qué salió.
   ========================================================================== */

const filtroAl = { nivel: 'con', tipo: '', area: '' };
const TIPOS_ALERTA = {
  hipertension: 'Presión arterial', diabetes: 'Diabetes', prediabetes: 'Prediabetes', obesidad: 'Obesidad',
  cardiometabolico: 'Cardiometabólico', recurrente: 'Recurrente', no_apto: 'No apto recurrente',
};

RUTAS.alertas.dibujar = function () {
  const datos = DB.leer();
  const todos = evaluarTodos(datos);
  const cuenta = n => todos.filter(s => s.riesgo === n).length;
  const porTipo = {};
  todos.forEach(s => new Set(s.alertas.map(a => a.clave)).forEach(k => { porTipo[k] = (porTipo[k] || 0) + 1; }));

  document.getElementById('contenido').innerHTML = `
    <div class="grid grid-stats">
      ${stat('alertas', 'c-rojo', cuenta('alto'), 'Riesgo alto')}
      ${stat('alertas', 'c-ambar', cuenta('medio'), 'Riesgo medio')}
      ${stat('trabajadores', 'c-verde', cuenta('bajo'), 'Sin alertas')}
      ${stat('corazon', 'c-azul', porTipo.hipertension || 0, 'Con presión elevada')}
      ${stat('consultas', 'c-morado', (porTipo.diabetes || 0) + (porTipo.prediabetes || 0), 'Con glucosa alterada')}
    </div>
    <div class="card">
      <div class="filtros">
        <div class="segmentos" id="f-nivel">${[['con', 'Con alertas'], ['alto', 'Riesgo alto'], ['medio', 'Riesgo medio'], ['todos', 'Todos']].map(([v, t]) =>
          `<button type="button" data-v="${v}" class="${filtroAl.nivel === v ? 'activo' : ''}">${t}</button>`).join('')}</div>
        <select id="f-tipo">${opciones(Object.entries(TIPOS_ALERTA).map(([k, n]) => [k, `${n} (${porTipo[k] || 0})`]), filtroAl.tipo, 'Todos los tipos')}</select>
        <select id="f-area">${opciones(AREAS.map(a => [a, a]), filtroAl.area, 'Todas las áreas')}</select>
      </div>
      <div class="nota-privada" style="margin-bottom:14px">Estas alertas se calculan con reglas sobre el historial (presiones de los últimos 30 días,
        glucosas de 90 días, IMC, antecedentes y consultas repetidas). Son avisos para valoración médica, no diagnósticos.</div>
      <div class="tabla-wrap" id="tabla-al"></div>
    </div>`;

  const pintar = () => {
    const lista = todos.filter(s => {
      if (filtroAl.nivel === 'con' && !s.alertas.length) return false;
      if (['alto', 'medio'].includes(filtroAl.nivel) && s.riesgo !== filtroAl.nivel) return false;
      if (filtroAl.tipo && !s.alertas.some(a => a.clave === filtroAl.tipo)) return false;
      if (filtroAl.area && s.trabajador.area !== filtroAl.area) return false;
      return true;
    });
    document.getElementById('tabla-al').innerHTML = `<table>
      <thead><tr><th>Trabajador</th><th>Riesgo</th><th>Alertas y motivo</th></tr></thead>
      <tbody>${lista.map(s => {
        const t = s.trabajador, r = RIESGOS[s.riesgo];
        return `<tr class="clic" data-id="${t.id}" style="vertical-align:top">
          <td style="min-width:190px"><b>${esc(nombreCompleto(t))}</b><div class="sub">${esc(t.puesto)} · ${esc(t.area)}</div>
            ${t.altoRiesgo ? `<div style="margin-top:4px">${badge('Alto riesgo', 'badge-ambar')}</div>` : ''}</td>
          <td>${badge(r.nombre, r.badge)}</td>
          <td>${s.alertas.map(a => `<div style="margin-bottom:6px">${badge(a.titulo, NIVELES[a.nivel].badge)}<div class="sub">${esc(a.porque)}</div></div>`).join('') || '<span class="sub">—</span>'}</td></tr>`;
      }).join('') || '<tr><td colspan="3" class="vacio">Nadie coincide con esos filtros</td></tr>'}</tbody></table>`;
  };
  pintar();

  document.getElementById('f-nivel').onclick = e => {
    const b = e.target.closest('button'); if (!b) return;
    filtroAl.nivel = b.dataset.v;
    document.querySelectorAll('#f-nivel button').forEach(x => x.classList.toggle('activo', x === b));
    pintar();
  };
  document.getElementById('f-tipo').onchange = e => { filtroAl.tipo = e.target.value; pintar(); };
  document.getElementById('f-area').onchange = e => { filtroAl.area = e.target.value; pintar(); };
  document.getElementById('tabla-al').onclick = e => { const f = e.target.closest('tr[data-id]'); if (f) ir('trabajador/' + f.dataset.id); };
};
