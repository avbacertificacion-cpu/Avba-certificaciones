/* ==========================================================================
   MEDICAMENTOS: existencias, alertas de mínimo y caducidad, entradas de
   inventario y bitácora de movimientos (cada salida liga a su consulta).
   ========================================================================== */

const filtroMed = { texto: '', estado: '', vista: 'inventario' };

function estadoStock(m) {
  if (m.stock <= 0) return { clave: 'agotado', nombre: 'Agotado', badge: 'badge-rojo', color: COLORES.estado.no_apto };
  if (m.stock <= m.minimo) return { clave: 'bajo', nombre: 'En mínimo', badge: 'badge-ambar', color: COLORES.estado.revalorar };
  return { clave: 'ok', nombre: 'Suficiente', badge: 'badge-verde', color: COLORES.estado.apto };
}
function diasParaCaducar(m) {
  const [a, mes, d] = m.caducidad.split('-').map(Number);
  return Math.round((new Date(a, mes - 1, d) - new Date()) / DIA_MS);
}

RUTAS.medicamentos.dibujar = function () {
  const datos = DB.leer();
  const admin = puede('administrar');
  const activos = datos.medicamentos.filter(m => m.activo);
  const agotados = activos.filter(m => m.stock <= 0).length;
  const bajos = activos.filter(m => m.stock > 0 && m.stock <= m.minimo).length;
  const porCaducar = activos.filter(m => diasParaCaducar(m) <= 60).length;
  const hace30 = new Date(Date.now() - 30 * DIA_MS).toISOString();
  const salidas30 = datos.movimientos.filter(x => x.tipo === 'salida' && x.fecha >= hace30).reduce((s, x) => s + x.cantidad, 0);

  document.getElementById('contenido').innerHTML = `
    <div class="grid grid-stats">
      ${stat('medicamentos', 'c-azul', activos.length, 'Medicamentos en catálogo')}
      ${stat('altoriesgo', 'c-rojo', agotados, 'Agotados')}
      ${stat('alertas', 'c-ambar', bajos, 'En mínimo')}
      ${stat('reloj', 'c-morado', porCaducar, 'Caducan en ≤ 60 días')}
      ${stat('consultas', 'c-verde', numero(salidas30), 'Unidades entregadas (30 d)')}
    </div>
    <div class="card">
      <div class="filtros">
        <div class="segmentos" id="f-vista">${[['inventario', 'Inventario'], ['movimientos', 'Movimientos']].map(([v, t]) =>
          `<button type="button" data-v="${v}" class="${filtroMed.vista === v ? 'activo' : ''}">${t}</button>`).join('')}</div>
        <input class="buscar" id="f-texto" type="search" placeholder="Buscar medicamento…" value="${esc(filtroMed.texto)}">
        <select id="f-estado">${opciones([['agotado', 'Agotados'], ['bajo', 'En mínimo'], ['ok', 'Suficientes'], ['caduca', 'Por caducar']], filtroMed.estado, 'Todos')}</select>
        ${admin ? `<button class="btn btn-primario" id="btn-nuevo">${icono('mas')}Nuevo medicamento</button>` : ''}
      </div>
      <div class="tabla-wrap" id="tabla-med"></div>
    </div>`;

  const pintar = () => {
    const q = filtroMed.texto.toLowerCase();
    const cumple = m => (!q || m.nombre.toLowerCase().includes(q)) &&
      (!filtroMed.estado || (filtroMed.estado === 'caduca' ? diasParaCaducar(m) <= 60 : estadoStock(m).clave === filtroMed.estado));
    const zona = document.getElementById('tabla-med');

    if (filtroMed.vista === 'inventario') {
      const orden = { agotado: 0, bajo: 1, ok: 2 };
      const lista = activos.filter(cumple).sort((a, b) => orden[estadoStock(a).clave] - orden[estadoStock(b).clave] || a.nombre.localeCompare(b.nombre));
      zona.innerHTML = `<table>
        <thead><tr><th>Medicamento</th><th class="derecha">Existencia</th><th>Estado</th><th>Caducidad</th><th class="derecha">Salidas 30 d</th><th></th></tr></thead>
        <tbody>${lista.map(m => {
          const e = estadoStock(m), dias = diasParaCaducar(m);
          const usado = datos.movimientos.filter(x => x.medicamentoId === m.id && x.tipo === 'salida' && x.fecha >= hace30).reduce((s, x) => s + x.cantidad, 0);
          const pct = Math.min(100, Math.round(m.stock / Math.max(1, m.minimo * 3) * 100));
          return `<tr>
            <td><b>${esc(m.nombre)}</b><div class="sub">${esc(m.presentacion)} · Lote ${esc(m.lote)}</div></td>
            <td class="derecha num"><b>${m.stock}</b> <span class="sub">${esc(m.unidad)}</span><div class="sub">mínimo ${m.minimo}</div>
              <div class="nivel-stock" style="margin-left:auto"><div style="width:${pct}%;background:${e.color}"></div></div></td>
            <td>${badge(e.nombre, e.badge)}</td>
            <td class="nowrap">${fechaLarga(m.caducidad)}<div class="sub">${dias < 0 ? badge('Caducado', 'badge-rojo') : dias <= 60 ? badge(`${dias} días`, 'badge-ambar') : `${dias} días`}</div></td>
            <td class="derecha num">${usado}</td>
            <td>${admin ? `<div class="acciones"><button class="btn-tbl" data-entrada="${m.id}">+ Entrada</button><button class="btn-tbl" data-editar="${m.id}">Editar</button></div>` : ''}</td></tr>`;
        }).join('') || '<tr><td colspan="6" class="vacio">Sin resultados</td></tr>'}</tbody></table>`;
    } else {
      const lista = datos.movimientos.filter(x => { const m = datos.medicamentos.find(y => y.id === x.medicamentoId); return m && cumple(m); })
        .sort((a, b) => b.fecha.localeCompare(a.fecha)).slice(0, 150);
      zona.innerHTML = `<table>
        <thead><tr><th>Fecha</th><th>Medicamento</th><th>Tipo</th><th class="derecha">Cantidad</th><th>Detalle</th><th>Usuario</th></tr></thead>
        <tbody>${lista.map(x => {
          const m = datos.medicamentos.find(y => y.id === x.medicamentoId), u = datos.usuarios.find(y => y.id === x.usuarioId);
          const cons = x.consultaId && datos.consultas.find(y => y.id === x.consultaId);
          const t = cons && datos.trabajadores.find(y => y.id === cons.trabajadorId);
          const tipo = { entrada: badge('Entrada', 'badge-verde'), salida: badge('Salida', 'badge-azul'), ajuste: badge('Ajuste', 'badge-ambar') }[x.tipo];
          return `<tr>
            <td class="nowrap">${fechaHora(x.fecha)}</td><td>${esc(m.nombre)}</td><td>${tipo}</td>
            <td class="derecha num">${x.tipo === 'salida' ? '−' : x.cantidad < 0 ? '' : '+'}${x.cantidad}</td>
            <td>${cons ? `<a href="#consulta/${cons.id}">Consulta · ${esc(nombreCompleto(t))}</a>` : esc(x.nota || '')}</td>
            <td>${esc(u ? u.nombre : '—')}</td></tr>`;
        }).join('') || '<tr><td colspan="6" class="vacio">Sin movimientos</td></tr>'}</tbody></table>
        <div class="sub" style="margin-top:8px">Se muestran los 150 movimientos más recientes.</div>`;
    }
    zona.querySelectorAll('[data-entrada]').forEach(b => b.onclick = () => formularioEntrada(datos.medicamentos.find(m => m.id === b.dataset.entrada)));
    zona.querySelectorAll('[data-editar]').forEach(b => b.onclick = () => formularioMedicamento(datos.medicamentos.find(m => m.id === b.dataset.editar)));
  };
  pintar();

  document.getElementById('f-vista').onclick = e => {
    const b = e.target.closest('button'); if (!b) return;
    filtroMed.vista = b.dataset.v;
    document.querySelectorAll('#f-vista button').forEach(x => x.classList.toggle('activo', x === b));
    pintar();
  };
  document.getElementById('f-texto').oninput = e => { filtroMed.texto = e.target.value; pintar(); };
  document.getElementById('f-estado').onchange = e => { filtroMed.estado = e.target.value; pintar(); };
  if (admin) document.getElementById('btn-nuevo').onclick = () => formularioMedicamento(null);
};

function formularioEntrada(m) {
  const datos = DB.leer();
  abrirModal({
    titulo: `Entrada de inventario · ${m.nombre}`,
    textoGuardar: 'Registrar entrada',
    cuerpo: `<p class="sub" style="margin-bottom:14px">Existencia actual: <b>${m.stock} ${esc(m.unidad)}</b> · mínimo ${m.minimo}</p>
      <div class="campos">
        <div class="field"><label>Cantidad recibida *</label><div class="con-unidad"><input name="cantidad" type="number" min="1"><span>${esc(m.unidad)}</span></div></div>
        <div class="field"><label>Lote</label><input name="lote" value="${esc(m.lote)}"></div>
        <div class="field"><label>Caducidad del lote</label><input name="caducidad" type="date" value="${esc(m.caducidad)}"></div>
        <div class="field"><label>Nota</label><input name="nota" placeholder="Proveedor, factura…"></div>
      </div>`,
    onGuardar(v) {
      if (!v.cantidad || v.cantidad < 1) return 'Captura una cantidad mayor a cero.';
      const med = datos.medicamentos.find(x => x.id === m.id);
      med.stock += v.cantidad;
      if (v.lote) med.lote = v.lote;
      if (v.caducidad) med.caducidad = v.caducidad;
      datos.movimientos.push({ id: DB.nuevoId('mv'), medicamentoId: m.id, tipo: 'entrada', cantidad: v.cantidad,
        fecha: new Date().toISOString(), usuarioId: yo.id, consultaId: null, nota: v.nota || 'Entrada de inventario' });
      DB.guardar(datos);
      aviso(`Entrada registrada: ${m.nombre} ahora tiene ${med.stock}`);
      refrescar();
    },
  });
}

function formularioMedicamento(med) {
  const datos = DB.leer();
  const m = med || { unidad: 'tabletas', stock: 0, minimo: 10 };
  const caducidad = m.caducidad || fechaISO(new Date(Date.now() + 365 * DIA_MS));
  abrirModal({
    titulo: med ? 'Editar medicamento' : 'Nuevo medicamento',
    cuerpo: `<div class="campos">
      <div class="field ancho"><label>Nombre y concentración *</label><input name="nombre" value="${esc(m.nombre || '')}" placeholder="Ej. Paracetamol 500 mg"></div>
      <div class="field"><label>Presentación</label><input name="presentacion" value="${esc(m.presentacion || '')}" placeholder="Caja 20 tabletas"></div>
      <div class="field"><label>Unidad de conteo</label><input name="unidad" value="${esc(m.unidad)}" placeholder="tabletas, piezas, frascos…"></div>
      ${med ? `<div class="field"><label>Existencia (ajuste)</label><input name="stock" type="number" min="0" value="${m.stock}">
        <div class="ayuda">Si cambias este número se registra un ajuste de inventario.</div></div>`
        : `<div class="field"><label>Existencia inicial</label><input name="stock" type="number" min="0" value="0"></div>`}
      <div class="field"><label>Mínimo *</label><input name="minimo" type="number" min="0" value="${m.minimo}"><div class="ayuda">Debajo de esto se marca en alerta.</div></div>
      <div class="field"><label>Lote</label><input name="lote" value="${esc(m.lote || '')}"></div>
      <div class="field"><label>Caducidad</label><input name="caducidad" type="date" value="${esc(caducidad)}"></div>
      ${med ? `<div class="field ancho"><label class="check"><input type="checkbox" name="activo" ${m.activo ? 'checked' : ''}>Activo en el catálogo</label></div>` : ''}
    </div>`,
    onGuardar(v) {
      if (!v.nombre) return 'Falta el nombre.';
      if (v.minimo == null || v.minimo < 0) return 'Captura el mínimo.';
      if (v.stock == null || v.stock < 0) return 'La existencia no puede ser negativa.';
      const ahora = new Date().toISOString();
      if (med) {
        const diferencia = v.stock - med.stock;
        if (diferencia) datos.movimientos.push({ id: DB.nuevoId('mv'), medicamentoId: med.id, tipo: 'ajuste', cantidad: diferencia,
          fecha: ahora, usuarioId: yo.id, consultaId: null, nota: 'Ajuste manual de inventario' });
        Object.assign(datos.medicamentos.find(x => x.id === med.id), v);
      } else {
        const nuevo = { id: DB.nuevoId('m'), ...v, unidad: v.unidad || 'piezas', activo: true };
        datos.medicamentos.push(nuevo);
        if (v.stock) datos.movimientos.push({ id: DB.nuevoId('mv'), medicamentoId: nuevo.id, tipo: 'entrada', cantidad: v.stock,
          fecha: ahora, usuarioId: yo.id, consultaId: null, nota: 'Existencia inicial' });
      }
      DB.guardar(datos);
      aviso('Medicamento guardado');
      refrescar();
    },
  });
}
