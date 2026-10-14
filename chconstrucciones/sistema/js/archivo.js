/* CH Arquitectura y Construcción — Archivo muerto.
 *
 * Son los documentos de obras terminadas. El catálogo (nombre, obra,
 * carpeta, tamaño) vive en la base de datos y se consulta al instante.
 * El sistema trabaja de una de dos formas (config/config.php):
 *
 *   - Acceso inmediato: los archivos se descargan al momento.
 *   - En frío: están en almacenamiento muy barato que no se abre al momento.
 *     1. El usuario marca archivos y hace una requisición.
 *     2. Si no puede aprobar, un coordinador la aprueba.
 *     3. El almacenamiento los "descongela" (≈ 12 h estándar / ≈ 48 h
 *        económica) y el sistema avisa; la descarga queda abierta N días.
 *
 * Este archivo se carga antes que app.js y usa sus funciones al ejecutarse.
 */
'use strict';

const PRIORIDADES = {
  estandar:  { nombre: 'Estándar',  real: '≈ 12 horas', desc: 'Para lo que se necesita en el día.' },
  economica: { nombre: 'Económica', real: '≈ 48 horas', desc: 'Más barata; para lo que puede esperar.' },
};
const ESTADO_REQ = {
  pendiente:   { nombre: 'Por aprobar',     clase: 'aviso' },
  restaurando: { nombre: 'En restauración', clase: 'info' },
  disponible:  { nombre: 'Disponible',      clase: 'ok' },
  expirada:    { nombre: 'Expirada',        clase: '' },
  rechazada:   { nombre: 'Rechazada',       clase: 'alerta' },
};
const modoFrio = () => CONFIG().archivoModo === 'frio';
const diasDisponible = () => CONFIG().diasDisponible || 7;

// Lo que se pidió al servidor (para no volver a pedirlo en cada pantalla).
const AM = {
  obras: null,           // [{anio, clave, nombre, archivos, tamano}]
  total: null,           // {archivos, tamano}
  vistos: new Map(),     // id → archivo del catálogo ya mostrado
  seleccion: new Map(),  // id → archivo marcado para solicitar
};
// Después de cargar o sincronizar, se vuelve a pedir el resumen del catálogo.
function olvidarCatalogo() {
  AM.obras = null;
  AM.total = null;
}
async function cargarObrasArchivo() {
  const j = await Api.get('archivo/obras');
  AM.obras = j.obras;
  AM.total = j.total;
}
const sumaTamanos = (lista) => lista.reduce((t, a) => t + (a.tamano || 0), 0);
const rutaObraArchivo = (anio, clave) => `#/archivo/obra/${anio ?? '-'}/${encodeURIComponent(clave)}`;

// ── Requisiciones (vienen en el estado: el servidor ya filtra cuáles ve cada quien)
const requisiciones = () => S()?.requisiciones || [];
const chipReq = (r) => chip(ESTADO_REQ[r.estado]?.nombre || r.estado, ESTADO_REQ[r.estado]?.clase || '');

// Número para el globo del menú: por aprobar (quien aprueba) o listas para descargar (el resto).
function avisosArchivo() {
  if (!modoFrio()) return 0;
  if (puede('archivo_admin')) return requisiciones().filter((r) => r.estado === 'pendiente').length;
  return requisiciones().filter((r) => r.estado === 'disponible' && r.usuarioId === usuario.id).length;
}

function faltan(iso) {
  const ms = new Date(iso).getTime() - Date.now();
  if (!iso || ms <= 0) return 'en cualquier momento';
  const h = Math.floor(ms / 3600000);
  const m = Math.ceil((ms % 3600000) / 60000);
  return h ? `faltan ≈ ${h} h ${m} min` : `faltan ≈ ${m} min`;
}
function textoTiempo(r) {
  const p = PRIORIDADES[r.prioridad] || PRIORIDADES.estandar;
  if (r.estado === 'pendiente') return `Espera aprobación · luego ${p.real}`;
  if (r.estado === 'restaurando') return `Lista aprox. ${fmt.fechaHora(r.listoEstimado)} <small>(${faltan(r.listoEstimado)})</small>`;
  if (r.estado === 'disponible') return `Descarga hasta ${fmt.fecha(r.expira)}`;
  if (r.estado === 'expirada') return 'Se puede volver a solicitar';
  if (r.estado === 'rechazada') return r.nota ? `Motivo: ${esc(r.nota)}` : '—';
  return '—';
}

// ── Vistas ──────────────────────────────────────────────────────────
function vistaArchivo(partes, params) {
  const sub = partes[1];
  const frio = modoFrio();
  const tabs = frio ? `<nav class="tabs">
    <a class="tab ${sub !== 'requisiciones' ? 'activo' : ''}" href="#/archivo">Catálogo</a>
    <a class="tab ${sub === 'requisiciones' ? 'activo' : ''}" href="#/archivo/requisiciones">Requisiciones<span class="n">${requisiciones().length}</span></a>
  </nav>` : '';
  const base = {
    titulo: 'Archivo muerto',
    sub: AM.obras && AM.total
      ? `${AM.obras.length} obras archivadas · ${AM.total.archivos.toLocaleString('es-MX')} archivos · ${fmt.tamano(AM.total.tamano)}${frio ? ' en almacenamiento en frío' : ''}`
      : 'Documentos de obras terminadas',
    acciones: puede('archivo_admin')
      ? `<button class="btn" data-accion="sincronizarArchivo">Sincronizar catálogo</button><button class="btn primario" data-accion="cargarArchivo">${IC.subir}Cargar archivos</button>`
      : '',
  };
  if (sub === 'requisiciones' && frio) return { ...base, html: tabs + vistaRequisiciones() };
  const filtro = sub === 'obra'
    ? { anio: partes[2] === '-' ? '' : partes[2] || '', obra: partes[3] || '', carpeta: params.get('carpeta') || '', q: '' }
    : { anio: '', obra: '', carpeta: '', q: params.get('q') || '' };
  filtro.pagina = Math.max(1, parseInt(params.get('pagina') || '1', 10) || 1);
  const r = vistaCatalogo(filtro);
  return { ...base, html: tabs + r.html, montar: r.montar };
}

function navObrasArchivo(filtro) {
  if (!AM.obras) return `<nav class="carpetas">${vacio('Cargando…')}</nav>`;
  const anios = [...new Set(AM.obras.map((o) => o.anio))];
  return `<nav class="carpetas" aria-label="Obras archivadas">
    <a class="carpeta ${!filtro.obra && !filtro.q ? 'activo' : ''}" href="#/archivo">${IC.carpeta}<span>Todas las obras</span></a>
    ${anios.map((anio) => `<div class="archivo-anio">${anio ?? 'Sin año'}</div>
      ${AM.obras.filter((o) => o.anio === anio).map((o) => `<a class="carpeta ${filtro.obra === o.clave && String(filtro.anio) === String(o.anio ?? '') ? 'activo' : ''}" href="${rutaObraArchivo(o.anio, o.clave)}" title="${esc(o.clave)} · ${esc(o.nombre)}">
        ${IC.carpeta}<span class="archivo-obra-t">${esc(o.nombre || o.clave)}</span></a>`).join('')}`).join('')}
  </nav>`;
}

function introArchivo() {
  const frio = modoFrio();
  const obras = AM.obras || [];
  return `<div class="archivo-intro">
      ${frio ? `<p>Los archivos del archivo muerto están guardados en <b>almacenamiento en frío</b>: cuesta muy poco,
         pero no se abren al momento. Búscalos aquí, márcalos y haz una <b>requisición</b>; te avisamos cuando estén listos para descargar.</p>
      <ol><li>Busca o navega por obra y carpeta.</li><li>Marca los archivos y pulsa <b>Solicitar</b>.</li>
        <li>${puede('archivo_admin') ? 'Como tú apruebas, la restauración empieza de inmediato.' : 'Un coordinador aprueba la solicitud.'}</li>
        <li>Estándar ${PRIORIDADES.estandar.real}, económica ${PRIORIDADES.economica.real}. Luego se descargan durante ${diasDisponible()} días.</li></ol>`
      : `<p>Aquí está el expediente de las obras terminadas. Busca por nombre, obra o carpeta y descarga al momento.</p>`}
    </div>
    ${obras.length ? `<div class="tabla-wrap"><table class="tabla">
      <thead><tr><th>Obra</th><th>Año</th><th class="num">Archivos</th><th class="num">Tamaño</th></tr></thead>
      <tbody>${obras.map((o) => `<tr class="clic" data-ir="${rutaObraArchivo(o.anio, o.clave)}"><td><b>${esc(o.clave)}</b>${o.nombre ? ' · ' + esc(o.nombre) : ''}</td><td>${o.anio ?? '—'}</td>
        <td class="num">${o.archivos.toLocaleString('es-MX')}</td><td class="num">${fmt.tamano(o.tamano)}</td></tr>`).join('')}</tbody>
    </table></div>` : vacio(puede('archivo_admin') ? 'El catálogo está vacío. Usa “Cargar archivos” o, si ya subiste carpetas al almacenamiento, “Sincronizar catálogo”.' : 'El catálogo está vacío todavía.')}`;
}

function filaArchivo(a, conObra) {
  const frio = modoFrio();
  return `<tr>
    ${frio ? `<td style="width:34px"><input type="checkbox" class="sel-arch" value="${esc(a.id)}" ${AM.seleccion.has(a.id) ? 'checked' : ''} aria-label="Seleccionar ${esc(a.nombre)}"></td>` : ''}
    <td><div class="doc-nombre">${iconoExt(a.ext)}<div><b>${esc(a.nombre)}</b>
      <small>${conObra ? esc(a.obraClave) + ' · ' : ''}${esc(a.carpeta)}</small></div></div></td>
    <td>${fmt.fecha(a.fecha)}</td>
    <td class="num">${fmt.tamano(a.tamano)}</td>
    <td class="acc">${frio
      ? `<button type="button" class="btn chico" data-accion="solicitarArchivo" data-id="${esc(a.id)}">Solicitar</button>`
      : `<button type="button" class="btn chico" data-accion="descargarArchivado" data-id="${esc(a.id)}">${IC.descargar}Descargar</button>`}</td>
  </tr>`;
}

function tablaCatalogo(j, filtro) {
  const frio = modoFrio();
  const conObra = !filtro.obra;
  const enlace = (cambios) => {
    const p = new URLSearchParams();
    const f = { ...filtro, ...cambios };
    if (f.q) p.set('q', f.q);
    if (f.carpeta) p.set('carpeta', f.carpeta);
    if (f.pagina > 1) p.set('pagina', f.pagina);
    const base = filtro.obra ? rutaObraArchivo(filtro.anio || null, filtro.obra) : '#/archivo';
    return base + (p.toString() ? '?' + p : '');
  };
  const chips = filtro.obra && j.carpetas.length > 1 ? `<div class="archivo-carpetas">
      <a class="chip ${!filtro.carpeta ? 'marca' : ''}" href="${enlace({ carpeta: '', pagina: 1 })}">Todas</a>
      ${j.carpetas.map((c) => `<a class="chip ${filtro.carpeta === c ? 'marca' : ''}" href="${enlace({ carpeta: c, pagina: 1 })}">${esc(c)}</a>`).join('')}
    </div>` : '';
  if (!j.items.length) return chips + vacio('Ningún archivo coincide.');
  const paginas = j.paginas > 1 ? `<div class="paginas">
      ${j.pagina > 1 ? `<a class="btn chico" href="${enlace({ pagina: j.pagina - 1 })}">‹ Anterior</a>` : ''}
      <span>Página ${j.pagina} de ${j.paginas} · ${j.total.toLocaleString('es-MX')} archivos</span>
      ${j.pagina < j.paginas ? `<a class="btn chico" href="${enlace({ pagina: j.pagina + 1 })}">Siguiente ›</a>` : ''}</div>` : '';
  return `${chips}<div class="tabla-wrap"><table class="tabla">
    <thead><tr>${frio ? '<th><input type="checkbox" id="sel-todos" aria-label="Seleccionar todos"></th>' : ''}<th>Archivo</th><th>Fecha</th><th class="num">Tamaño</th><th></th></tr></thead>
    <tbody id="tabla-archivo">${j.items.map((a) => filaArchivo(a, conObra)).join('')}</tbody></table></div>${paginas}`;
}

function vistaCatalogo(filtro) {
  const obra = filtro.obra ? AM.obras?.find((o) => o.clave === filtro.obra && String(o.anio ?? '') === String(filtro.anio)) : null;
  let titulo, desc;
  if (filtro.q) { titulo = `Resultados para “${esc(filtro.q)}”`; desc = 'Buscando…'; }
  else if (filtro.obra) { titulo = esc(filtro.obra) + (obra?.nombre ? ' · ' + esc(obra.nombre) : ''); desc = obra ? `${obra.anio ?? 'Sin año'} · ${obra.archivos.toLocaleString('es-MX')} archivos · ${fmt.tamano(obra.tamano)}` : ''; }
  else { titulo = 'Obras archivadas'; desc = 'Elige una obra o busca un archivo por nombre.'; }
  const lista = !!(filtro.q || filtro.obra);

  return {
    html: `<div class="docs-layout">${navObrasArchivo(filtro)}
      <div>
        <form class="filtros" id="form-archivo" role="search" style="margin-bottom:14px">
          <div class="buscador" style="max-width:none">${IC.buscar}<input type="search" id="q-archivo" value="${esc(filtro.q)}" placeholder="Buscar en todo el archivo muerto: bitácora, as-built, CH-2019…" aria-label="Buscar en el archivo muerto"></div>
          <button class="btn" type="submit">Buscar</button>
        </form>
        <div class="card" style="margin:0"><div class="card-h"><div><div class="card-t">${titulo}</div><div class="carpeta-desc" id="am-desc">${desc}</div></div></div>
          <div id="am-cuerpo">${lista ? vacio('Cargando…') : (AM.obras ? introArchivo() : vacio('Cargando…'))}</div></div>
        <div class="barra-seleccion ${AM.seleccion.size ? '' : 'oculto'}" id="barra-seleccion">
          <span id="sel-resumen"></span>
          <button type="button" class="btn" data-accion="limpiarSeleccion">Quitar selección</button>
          <button type="button" class="btn primario" data-accion="solicitarSeleccion">Solicitar seleccionados</button>
        </div>
      </div></div>`,
    async montar() {
      const hash = location.hash;
      $('#form-archivo').addEventListener('submit', (e) => {
        e.preventDefault();
        const texto = $('#q-archivo').value.trim();
        location.hash = texto ? '#/archivo?q=' + encodeURIComponent(texto) : '#/archivo';
      });
      if (!AM.obras) {
        try { await cargarObrasArchivo(); } catch (e) { $('#am-cuerpo').innerHTML = vacio(esc(e.message)); return; }
        if (location.hash === hash && !$('#modal')) render(); // ya con el menú de obras
        return;
      }
      actualizarSeleccion();
      if (!lista) return;
      const p = new URLSearchParams({ pagina: filtro.pagina });
      ['q', 'obra', 'anio', 'carpeta'].forEach((k) => { if (filtro[k]) p.set(k, filtro[k]); });
      let j;
      try { j = await Api.get('archivo/catalogo&' + p); } catch (e) { $('#am-cuerpo').innerHTML = vacio(esc(e.message)); return; }
      if (location.hash !== hash) return; // el usuario ya se fue a otra pantalla
      j.items.forEach((a) => AM.vistos.set(a.id, a));
      if (filtro.q) $('#am-desc').textContent = `${j.total.toLocaleString('es-MX')} archivo(s)`;
      $('#am-cuerpo').innerHTML = tablaCatalogo(j, filtro);
      $('#tabla-archivo')?.addEventListener('change', (e) => {
        if (!e.target.classList.contains('sel-arch')) return;
        marcar(e.target.value, e.target.checked);
        actualizarSeleccion();
      });
      $('#sel-todos')?.addEventListener('change', (e) => {
        $$('.sel-arch').forEach((m) => { m.checked = e.target.checked; marcar(m.value, m.checked); });
        actualizarSeleccion();
      });
      actualizarSeleccion();
    },
  };
}
function marcar(id, si) {
  if (si) AM.seleccion.set(id, AM.vistos.get(id)); else AM.seleccion.delete(id);
}
function actualizarSeleccion() {
  const barra = $('#barra-seleccion');
  if (!barra) return;
  barra.classList.toggle('oculto', !AM.seleccion.size);
  $('#sel-resumen').innerHTML = `<b>${AM.seleccion.size}</b> archivo(s) · ${fmt.tamano(sumaTamanos([...AM.seleccion.values()]))}`;
  const todos = $('#sel-todos');
  if (todos) {
    const marcas = $$('.sel-arch');
    todos.checked = marcas.length > 0 && marcas.every((m) => m.checked);
  }
}

function vistaRequisiciones() {
  const lista = requisiciones();
  const porAprobar = puede('archivo_admin') ? lista.filter((r) => r.estado === 'pendiente') : [];
  const fila = (r) => `<tr class="clic" data-accion="verRequisicion" data-id="${esc(r.id)}">
      <td><b>${esc(r.folio)}</b><div style="font-size:12.5px;color:var(--tinta-3)">${fmt.relativo(r.solicitada)}</div></td>
      <td>${esc(nombreUsuario(r.usuarioId))}</td>
      <td>${r.archivos.length} · ${fmt.tamano(sumaTamanos(r.archivos))}</td>
      <td>${esc(PRIORIDADES[r.prioridad]?.nombre || r.prioridad)}</td>
      <td>${chipReq(r)}</td>
      <td style="font-size:13.5px">${textoTiempo(r)}</td></tr>`;
  return `${porAprobar.length ? `<div class="card"><div class="card-h"><span class="card-t">Por aprobar</span><span class="card-s">Recuperar archivos del frío tiene costo; revisa el tamaño antes de aprobar.</span></div>
      <ul class="lista-simple">${porAprobar.map((r) => `<li><div style="flex:1;min-width:0"><b>${esc(r.folio)}</b> · ${esc(nombreUsuario(r.usuarioId))} · ${r.archivos.length} archivo(s), ${fmt.tamano(sumaTamanos(r.archivos))}
          <div style="font-size:13px;color:var(--tinta-3)">${esc(r.motivo)}</div></div>
        <div class="der" style="display:flex;gap:6px"><button class="btn chico" data-accion="rechazarRequisicion" data-id="${esc(r.id)}">Rechazar</button>
          <button class="btn chico primario" data-accion="aprobarRequisicion" data-id="${esc(r.id)}">Aprobar</button></div></li>`).join('')}</ul></div>` : ''}
    <div class="card">${lista.length ? `<div class="tabla-wrap"><table class="tabla">
      <thead><tr><th>Folio</th><th>Solicitó</th><th>Archivos</th><th>Prioridad</th><th>Estado</th><th>Tiempo</th></tr></thead>
      <tbody>${lista.map(fila).join('')}</tbody></table></div>`
      : vacio('Todavía no hay requisiciones. Ve al catálogo, marca los archivos que necesitas y pulsa “Solicitar”.')}</div>
    <p class="ayuda">Cuando una requisición esté lista te llega un correo y aparece aquí como <b>Disponible</b>. La descarga queda abierta ${diasDisponible()} días.</p>`;
}

// ── Formularios y acciones ──────────────────────────────────────────
function formRequisicion(items) {
  items = items.filter(Boolean);
  if (!items.length) return;
  const aprueba = puede('archivo_admin');
  const muestra = items.slice(0, 8).map((a) => `<li>${esc(a.nombre)} <small style="color:var(--tinta-3)">· ${esc(a.obraClave)} · ${fmt.tamano(a.tamano)}</small></li>`).join('');
  modal({
    titulo: 'Nueva requisición',
    textoEnviar: aprueba ? 'Solicitar y restaurar' : 'Enviar para aprobación',
    cuerpo: `<p style="margin-bottom:8px"><b>${items.length}</b> archivo(s) · <b>${fmt.tamano(sumaTamanos(items))}</b></p>
      <ul class="lista" style="margin:0 0 14px 18px;font-size:13.5px">${muestra}${items.length > 8 ? `<li>y ${items.length - 8} más…</li>` : ''}</ul>
      <div class="campo"><label>Motivo *</label><textarea name="motivo" placeholder="Para qué se necesitan (auditoría, referencia técnica, solicitud del cliente…)"></textarea></div>
      <div class="campo"><label>Prioridad</label>
        ${Object.entries(PRIORIDADES).map(([k, p]) => `<label class="opcion-radio"><input type="radio" name="prioridad" value="${k}" ${k === 'estandar' ? 'checked' : ''}>
          <span><b>${p.nombre} · ${p.real}</b><small>${p.desc}</small></span></label>`).join('')}</div>
      <div class="ayuda">${aprueba ? 'Como tú apruebas, la restauración empieza de inmediato.' : 'La solicitud la aprueba un coordinador o el administrador.'}
        Cuando esté lista, la descarga queda disponible ${diasDisponible()} días.</div>`,
    alEnviar: async (f) => {
      const motivo = valor(f, 'motivo');
      if (!motivo) throw new Invalido('Escribe el motivo de la solicitud.');
      const r = await Api.post('archivo/solicitar', { archivos: items.map((a) => a.id), motivo, prioridad: f.elements.prioridad.value });
      AM.seleccion.clear();
      cerrarModal();
      location.hash = '#/archivo/requisiciones';
      await refrescar();
      aviso(aprueba ? `${r.folio}: restauración iniciada.` : `${r.folio} enviada para aprobación.`);
    },
  });
}

function verRequisicion(id) {
  const r = requisiciones().find((x) => x.id === id);
  if (!r) return;
  const propia = r.usuarioId === usuario.id;
  const pasos = [
    ['Solicitada', r.solicitada, nombreUsuario(r.usuarioId), true],
    r.estado === 'rechazada' ? ['Rechazada', r.aprobada, nombreUsuario(r.aprobadaPor), true] : ['Aprobada', r.aprobada, r.aprobadaPor ? nombreUsuario(r.aprobadaPor) : '', !!r.aprobada],
    ['Disponible para descargar', r.disponible || r.listoEstimado, r.disponible ? '' : 'estimado', !!r.disponible],
  ];
  const archivos = r.archivos.map((a) => `<li>${iconoExt(a.ext)}<div style="flex:1;min-width:0"><b style="font-weight:600">${esc(a.nombre)}</b>
      <div style="font-size:12.5px;color:var(--tinta-3)">${esc(a.obraClave)} · ${esc(a.carpeta)} · ${fmt.tamano(a.tamano)}</div></div>
      ${r.estado === 'disponible' && propia ? `<button type="button" class="btn chico" data-accion="descargarArchivado" data-id="${esc(a.id)}">${IC.descargar}Descargar</button>` : ''}</li>`).join('');
  modal({
    titulo: `${r.folio} · ${ESTADO_REQ[r.estado]?.nombre || r.estado}`,
    ancho: true,
    cuerpo: `<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px">${chipReq(r)}
        <span>${esc(PRIORIDADES[r.prioridad]?.nombre || r.prioridad)} · ${r.archivos.length} archivo(s) · ${fmt.tamano(sumaTamanos(r.archivos))}</span>
        <span style="margin-left:auto">${textoTiempo(r)}</span></div>
      <p style="margin-bottom:14px"><b>Motivo:</b> ${esc(r.motivo)}${r.nota ? `<br><b>Nota:</b> ${esc(r.nota)}` : ''}</p>
      <ol class="linea-tiempo">${pasos.map(([t, cuando, quien, hecho]) => `<li class="${hecho ? 'hecho' : ''}">
        <b>${t}</b><small>${cuando ? fmt.fechaHora(cuando) + (quien ? ' · ' + esc(quien) : '') : 'Pendiente'}</small></li>`).join('')}</ol>
      ${r.estado === 'disponible' && !propia ? '<p class="ayuda">Sólo quien hizo la requisición puede descargar estos archivos.</p>' : ''}
      <ul class="lista-simple" style="border:1px solid var(--linea);margin-top:14px">${archivos}</ul>`,
    pie: `${puede('archivo_admin') && r.estado === 'pendiente' ? `<button type="button" class="btn" data-accion="rechazarRequisicion" data-id="${esc(r.id)}">Rechazar</button>
        <button type="button" class="btn primario" data-accion="aprobarRequisicion" data-id="${esc(r.id)}">Aprobar</button>` : ''}
      ${r.estado === 'expirada' && propia ? `<button type="button" class="btn primario" data-accion="resolicitar" data-id="${esc(r.id)}">Volver a solicitar</button>` : ''}
      <button type="button" class="btn" data-cerrar>Cerrar</button>`,
  });
}

function formCargarArchivo() {
  const obras = (AM.obras || []).map((o) => (o.nombre ? `${o.clave} - ${o.nombre}` : o.clave));
  const carpetas = [...new Set(['General', ...CARPETAS.map((c) => c.nombre)])];
  const form = modal({
    titulo: 'Cargar al archivo muerto',
    textoEnviar: 'Cargar',
    ancho: true,
    cuerpo: `<div class="fila"><div class="campo"><label>Año de la obra *</label><input type="number" name="anio" min="1950" max="2100" value="${new Date().getFullYear() - 1}"></div>
        <div class="campo"><label>Obra *</label><input type="text" name="obra" list="am-obras" placeholder="CH-2019-014 - Bodega Celaya">
          <datalist id="am-obras">${obras.map((o) => `<option value="${esc(o)}">`).join('')}</datalist>
          <div class="ayuda">Clave, guion y nombre. Si ya existe, elígela de la lista.</div></div></div>
      <div class="campo"><label>Carpeta</label><input type="text" name="carpeta" list="am-carpetas" value="General">
        <datalist id="am-carpetas">${carpetas.map((c) => `<option value="${esc(c)}">`).join('')}</datalist></div>
      ${campoArchivos({ multiple: true, texto: 'Arrastra aquí los archivos de la obra' })}
      <div class="ayuda" style="margin-top:8px">${modoFrio() ? 'Los archivos se guardan directamente en el almacenamiento en frío. ' : ''}Para cargar muchas carpetas de una vez (varios GB), es más práctico subirlas con una herramienta como rclone al almacenamiento y después usar «Sincronizar catálogo».</div>`,
    alEnviar: async (f) => {
      const archivos = selector.obtener();
      const anio = parseInt(valor(f, 'anio'), 10);
      const obra = valor(f, 'obra');
      if (!(anio >= 1950 && anio <= 2100)) throw new Invalido('Indica el año de la obra.');
      if (!obra) throw new Invalido('Indica la obra.');
      if (!archivos.length) throw new Invalido('Elige al menos un archivo.');
      const ids = await subirArchivos(f, archivos, { destino: 'archivo', anio, obra, carpeta: valor(f, 'carpeta') || 'General' });
      const r = await Api.post('archivo/registrar', { archivos: ids });
      olvidarCatalogo();
      cerrarModal();
      render();
      aviso(`${r.nuevos} archivo(s) agregados al catálogo.`);
    },
  });
  const selector = montarArchivos(form);
}

function formSincronizar() {
  modal({
    titulo: 'Sincronizar catálogo',
    textoEnviar: 'Sincronizar',
    cuerpo: `<p>Revisa todo lo que hay en el almacenamiento del archivo muerto y agrega al catálogo lo que falte.
      Úsalo después de subir carpetas directamente (por ejemplo con rclone).</p>
      <p class="ayuda" style="margin-top:8px">Las carpetas deben seguir el orden <code>AÑO/CLAVE - Nombre de la obra/Carpeta/archivo</code>.</p>`,
    alEnviar: async (f) => {
      const barra = progreso(f);
      let siguiente = null;
      let revisados = 0;
      let nuevos = 0;
      do {
        barra.fijar(`Revisados ${revisados.toLocaleString('es-MX')} archivos…`, 0.5);
        const r = await Api.post('archivo/sincronizar', siguiente ? { siguiente } : {});
        revisados += r.revisados;
        nuevos += r.nuevos;
        siguiente = r.siguiente;
      } while (siguiente);
      olvidarCatalogo();
      cerrarModal();
      render();
      aviso(`Se revisaron ${revisados.toLocaleString('es-MX')} archivos: ${nuevos.toLocaleString('es-MX')} nuevos en el catálogo.`);
    },
  });
}

const ACCIONES_ARCHIVO = {
  solicitarArchivo: (ds) => formRequisicion([AM.vistos.get(ds.id)]),
  solicitarSeleccion: () => formRequisicion([...AM.seleccion.values()]),
  limpiarSeleccion: () => { AM.seleccion.clear(); render(); },
  verRequisicion: (ds) => verRequisicion(ds.id),
  resolicitar: (ds) => formRequisicion(requisiciones().find((x) => x.id === ds.id).archivos),
  aprobarRequisicion: async (ds) => {
    const r = requisiciones().find((x) => x.id === ds.id);
    try {
      await Api.post('archivo/aprobar', { id: r.id });
      await refrescar();
      aviso(`${r.folio} aprobada: restauración iniciada.`);
    } catch (e) { aviso(e.message); }
  },
  rechazarRequisicion: (ds) => {
    const r = requisiciones().find((x) => x.id === ds.id);
    modal({
      titulo: `Rechazar ${r.folio}`,
      textoEnviar: 'Rechazar',
      cuerpo: `<div class="campo"><label>Motivo del rechazo *</label><textarea name="nota" placeholder="Se avisará a quien la solicitó"></textarea></div>`,
      alEnviar: async (f) => {
        const nota = valor(f, 'nota');
        if (!nota) throw new Invalido('Escribe el motivo del rechazo.');
        await ejecutar(() => Api.post('archivo/rechazar', { id: r.id, nota }), `${r.folio} rechazada.`);
      },
    });
  },
  descargarArchivado: (ds) => descargarUrl(Api.urlArchivoMuerto(ds.id)),
  cargarArchivo: () => formCargarArchivo(),
  sincronizarArchivo: () => formSincronizar(),
};

// Cada minuto, si el usuario tiene requisiciones en espera, pregunta al servidor.
// Cuando una queda lista, vuelve a pintar (si no hay una ventana abierta) y avisa.
setInterval(async () => {
  if (typeof usuario === 'undefined' || !usuario || !S() || !modoFrio() || document.hidden || $('#modal')) return;
  if (!requisiciones().some((r) => r.usuarioId === usuario.id && (r.estado === 'restaurando' || r.estado === 'pendiente'))) return;
  const antes = new Set(requisiciones().filter((r) => r.estado === 'disponible').map((r) => r.id));
  try { await Store.recargar(); } catch (e) { return; }
  const nuevas = requisiciones().filter((r) => r.estado === 'disponible' && !antes.has(r.id) && r.usuarioId === usuario.id);
  if (!nuevas.length || $('#modal')) return;
  render();
  aviso(`${nuevas[0].folio} ya está lista para descargar.`);
}, 60000);
