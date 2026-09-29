/* ==========================================================================
   PARAMÉDICOS: alta con usuario y contraseña, esquema de turno y
   ambulancia asignada. Sólo el administrador.
   ========================================================================== */

RUTAS.paramedicos.dibujar = function () {
  const datos = DB.leer();
  const enTurno = new Set(paramedicosEnTurno(datos).map(p => p.usuario.id));
  const lista = datos.usuarios.filter(u => u.rol === 'paramedico').sort((a, b) => b.activo - a.activo || a.nombre.localeCompare(b.nombre));
  const hace30 = new Date(Date.now() - 30 * DIA_MS).toISOString();

  document.getElementById('contenido').innerHTML = `
    <div class="card">
      <div class="card-hdr">
        <div><div class="card-hdr-t">Paramédicos registrados</div><div class="card-hdr-s">Cada uno entra con su usuario; el sistema detecta su turno según su esquema</div></div>
        <button class="btn btn-primario" id="btn-alta">${icono('mas')}Alta de paramédico</button>
      </div>
      <div class="tabla-wrap"><table>
        <thead><tr><th>Paramédico</th><th>Usuario</th><th>Esquema</th><th>Ambulancia base</th><th>Ahora</th><th class="derecha">Consultas 30 d</th><th></th></tr></thead>
        <tbody>${lista.map(u => {
          const amb = datos.ambulancias.find(a => a.id === u.ambulanciaId);
          const n = datos.consultas.filter(c => c.paramedicoId === u.id && c.fecha >= hace30).length;
          const ahora = !u.activo ? badge('Inactivo', 'badge-gris')
            : enTurno.has(u.id) ? `<span class="badge badge-verde"><span class="punto"></span>En turno</span>` : '<span class="sub">Fuera de turno</span>';
          return `<tr>
            <td><b>${esc(u.nombre)}</b><div class="sub">${esc(u.cedula || '')} · ${esc(u.telefono || '')}</div></td>
            <td><code>${esc(u.usuario)}</code></td>
            <td>${ESQUEMAS[u.esquema]}</td>
            <td>${amb ? esc(amb.clave) : '<span class="sub">Sin asignar</span>'}</td>
            <td>${ahora}</td>
            <td class="derecha num">${n}</td>
            <td><div class="acciones"><button class="btn-tbl" data-editar="${u.id}">Editar</button></div></td></tr>`;
        }).join('')}</tbody></table></div>
    </div>

    <div class="card">
      <div class="card-hdr"><div><div class="card-hdr-t">Esquemas de turno</div><div class="card-hdr-s">Así detecta el sistema el turno de cada paramédico al entrar</div></div></div>
      ${lineaTurnos('8h')}${lineaTurnos('12h')}
    </div>`;

  document.getElementById('btn-alta').onclick = () => formularioParamedico(null);
  document.querySelectorAll('[data-editar]').forEach(b => b.onclick = () =>
    formularioParamedico(datos.usuarios.find(u => u.id === b.dataset.editar)));
};

function formularioParamedico(usuario) {
  const datos = DB.leer();
  const u = usuario || { esquema: '8h', activo: true };
  abrirModal({
    titulo: usuario ? 'Editar paramédico' : 'Alta de paramédico',
    textoGuardar: usuario ? 'Guardar cambios' : 'Dar de alta',
    cuerpo: `
      <div class="campos">
        <div class="field ancho"><label>Nombre completo *</label><input name="nombre" value="${esc(u.nombre || '')}"></div>
        <div class="field"><label>Cédula / certificación</label><input name="cedula" value="${esc(u.cedula || '')}" placeholder="TUM-00000"></div>
        <div class="field"><label>Teléfono</label><input name="telefono" value="${esc(u.telefono || '')}"></div>
        <div class="separador">Acceso al sistema</div>
        <div class="field"><label>Usuario *</label><input name="usuario" value="${esc(u.usuario || '')}" autocomplete="off"></div>
        <div class="field"><label>${usuario ? 'Nueva contraseña' : 'Contraseña *'}</label><input name="password" type="text" autocomplete="off" placeholder="${usuario ? 'Déjala vacía para no cambiarla' : 'Mínimo 4 caracteres'}"></div>
        <div class="separador">Turno y unidad</div>
        <div class="field"><label>Esquema de turno</label><select name="esquema">${opciones([['8h', 'Turnos de 8 h (matutino / vespertino / nocturno)'], ['12h', 'Turnos de 12 h (diurno / nocturno)']], u.esquema)}</select></div>
        <div class="field"><label>Ambulancia base</label><select name="ambulanciaId">${opciones(datos.ambulancias.map(a => [a.id, `${a.clave} · ${a.tipo}`]), u.ambulanciaId, 'Sin asignar')}</select></div>
        ${usuario ? `<div class="field ancho"><label class="check"><input type="checkbox" name="activo" ${u.activo ? 'checked' : ''}>Cuenta activa (puede iniciar sesión)</label></div>` : ''}
      </div>
      <p class="ayuda" style="font-size:12px;color:var(--texto-hint);margin-bottom:14px">Demo: la contraseña se guarda en este navegador sin cifrar.</p>`,
    onGuardar(v) {
      if (!v.nombre) return 'Falta el nombre.';
      if (!/^[a-z0-9._-]{3,}$/i.test(v.usuario)) return 'El usuario debe tener al menos 3 caracteres, sin espacios.';
      if (datos.usuarios.some(x => x.usuario.toLowerCase() === v.usuario.toLowerCase() && x.id !== u.id)) return `El usuario "${v.usuario}" ya existe.`;
      if (!usuario && (v.password || '').length < 4) return 'La contraseña debe tener al menos 4 caracteres.';
      if (usuario && v.password && v.password.length < 4) return 'La contraseña debe tener al menos 4 caracteres.';

      const cambios = { nombre: v.nombre, cedula: v.cedula, telefono: v.telefono, usuario: v.usuario,
        esquema: v.esquema, ambulanciaId: v.ambulanciaId || null };
      if (v.password) cambios.password = v.password;
      if (usuario) {
        if (usuario.id === yo.id && v.activo === false) return 'No puedes desactivar tu propia cuenta.';
        Object.assign(usuario, cambios, { activo: v.activo });
      } else {
        datos.usuarios.push({ id: DB.nuevoId('u'), rol: 'paramedico', activo: true, ...cambios });
      }
      DB.guardar(datos);
      aviso(usuario ? 'Paramédico actualizado' : `Paramédico dado de alta · usuario: ${v.usuario}`);
      refrescar();
    },
  });
}
