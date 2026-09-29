/* ==========================================================================
   AMBULANCIAS: unidades, estado, quién la trae en este turno y cuántas
   atenciones ha dado.
   ========================================================================== */

RUTAS.ambulancias.dibujar = function () {
  const datos = DB.leer();
  const enTurno = paramedicosEnTurno(datos);
  const hace30 = new Date(Date.now() - 30 * DIA_MS).toISOString();

  const tarjetas = datos.ambulancias.map(a => {
    const est = ESTADOS_AMBULANCIA[a.estado];
    const ahora = enTurno.filter(p => p.guardia.ambulanciaId === a.id);
    const base = datos.usuarios.filter(u => u.rol === 'paramedico' && u.activo && u.ambulanciaId === a.id);
    const atenciones = datos.consultas.filter(c => c.ambulanciaId === a.id && c.fecha >= hace30).length +
      datos.evaluaciones.filter(e => e.ambulanciaId === a.id && e.fecha >= hace30).length;
    const traslados = datos.consultas.filter(c => c.ambulanciaId === a.id && c.fecha >= hace30 && c.destino === 'traslado').length;
    return `
      <div class="amb-card">
        <div class="amb-top">
          <div class="stat-icon c-azul">${icono('ambulancias')}</div>
          <div style="flex:1"><div class="amb-clave">${esc(a.clave)}</div><div class="sub">${esc(a.tipo)} · ${esc(a.placas)}</div></div>
          ${badge(est.nombre, est.badge)}
        </div>
        <div class="datos-lista">
          <div><div class="dato-l">Atenciones 30 d</div><div class="dato-v">${atenciones}</div></div>
          <div><div class="dato-l">Traslados 30 d</div><div class="dato-v">${traslados}</div></div>
        </div>
        <div class="amb-personal">
          <div class="dato-l" style="margin-bottom:6px">En turno ahora</div>
          ${ahora.map(p => `<div class="persona"><span><span class="badge badge-verde"><span class="punto"></span></span> ${esc(p.usuario.nombre)}</span>
            <span class="sub">${esc(nombreTurno(turnoPorClave(p.guardia.esquema, p.guardia.turno)))}</span></div>`).join('')
            || '<div class="sub">Nadie ha iniciado turno con esta unidad</div>'}
          <div class="dato-l" style="margin:12px 0 6px">Asignados como base</div>
          ${base.map(u => `<div class="persona"><span>${esc(u.nombre)}</span><span class="sub">${ESQUEMAS[u.esquema]}</span></div>`).join('')
            || '<div class="sub">Sin paramédicos asignados</div>'}
        </div>
        <div class="acciones" style="margin-top:14px;justify-content:flex-start">
          <select class="btn-tbl" data-estado="${a.id}" aria-label="Cambiar estado">${opciones(Object.entries(ESTADOS_AMBULANCIA).map(([k, x]) => [k, x.nombre]), a.estado)}</select>
          <button class="btn-tbl" data-editar="${a.id}">Editar</button>
        </div>
      </div>`;
  }).join('');

  document.getElementById('contenido').innerHTML = `
    <div class="filtros"><div class="espacio"></div><button class="btn btn-primario" id="btn-alta">${icono('mas')}Agregar ambulancia</button></div>
    <div class="ambs">${tarjetas}</div>
    <div class="card">
      <div class="card-hdr"><div><div class="card-hdr-t">Bitácora de asignaciones</div><div class="card-hdr-s">Qué unidad tomó cada paramédico en sus últimas guardias</div></div></div>
      <div class="tabla-wrap"><table>
        <thead><tr><th>Fecha de turno</th><th>Turno</th><th>Paramédico</th><th>Ambulancia</th><th>Entrada</th></tr></thead>
        <tbody>${datos.guardias.slice(-15).reverse().map(g => {
          const u = datos.usuarios.find(x => x.id === g.usuarioId), a = datos.ambulancias.find(x => x.id === g.ambulanciaId);
          return `<tr><td>${fechaLarga(g.fechaTurno)}</td><td>${esc(nombreTurno(turnoPorClave(g.esquema, g.turno)))}</td>
            <td>${esc(u ? u.nombre : '—')}</td><td>${a ? esc(a.clave) : '—'}</td><td class="nowrap">${horaCorta(g.entrada)}</td></tr>`;
        }).join('') || '<tr><td colspan="5" class="vacio">Todavía no hay guardias. Se registran cuando un paramédico inicia sesión.</td></tr>'}</tbody>
      </table></div>
    </div>`;

  document.querySelectorAll('[data-estado]').forEach(s => s.onchange = () => {
    datos.ambulancias.find(a => a.id === s.dataset.estado).estado = s.value;
    DB.guardar(datos);
    aviso('Estado actualizado');
    refrescar();
  });
  document.querySelectorAll('[data-editar]').forEach(b => b.onclick = () => formularioAmbulancia(datos.ambulancias.find(a => a.id === b.dataset.editar)));
  document.getElementById('btn-alta').onclick = () => formularioAmbulancia(null);
};

function formularioAmbulancia(amb) {
  const datos = DB.leer();
  const a = amb || { estado: 'disponible', tipo: 'Urgencias básicas' };
  abrirModal({
    titulo: amb ? `Editar ${amb.clave}` : 'Agregar ambulancia',
    cuerpo: `<div class="campos">
      <div class="field"><label>Clave *</label><input name="clave" value="${esc(a.clave || 'AMB-' + String(datos.ambulancias.length + 1).padStart(2, '0'))}"></div>
      <div class="field"><label>Placas</label><input name="placas" value="${esc(a.placas || '')}"></div>
      <div class="field"><label>Tipo</label><select name="tipo">${opciones(['Urgencias básicas', 'Urgencias avanzadas', 'Traslado', 'Unidad de rescate'].map(x => [x, x]), a.tipo)}</select></div>
      <div class="field"><label>Estado</label><select name="estado">${opciones(Object.entries(ESTADOS_AMBULANCIA).map(([k, x]) => [k, x.nombre]), a.estado)}</select></div>
    </div>`,
    onGuardar(v) {
      if (!v.clave) return 'Falta la clave de la unidad.';
      if (datos.ambulancias.some(x => x.clave.toLowerCase() === v.clave.toLowerCase() && x.id !== a.id)) return 'Ya existe una unidad con esa clave.';
      if (amb) Object.assign(amb, v);
      else datos.ambulancias.push({ id: DB.nuevoId('amb'), ...v });
      DB.guardar(datos);
      aviso('Ambulancia guardada');
      refrescar();
    },
  });
}
