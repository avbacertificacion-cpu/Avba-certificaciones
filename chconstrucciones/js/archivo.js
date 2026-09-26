/* CH Arquitectura y Construcción — Archivo muerto (demo).
 *
 * El archivo muerto son los documentos de obras viejas guardados en
 * almacenamiento en frío (en la versión real: Amazon S3 Glacier Deep
 * Archive). Es muy barato, pero un archivo no se puede abrir al momento:
 * hay que pedir que lo "descongelen", y eso tarda de 12 a 48 horas.
 *
 * Por eso el sistema funciona así:
 *   1. Catálogo: la lista de todos los archivos (nombre, obra, carpeta,
 *      tamaño) vive en la base de datos y se consulta al instante.
 *   2. Requisición: el usuario elige archivos y los solicita. Si no es
 *      administrador o coordinador, uno de ellos la aprueba.
 *   3. Restauración: se pide al almacenamiento en frío y se muestra
 *      cuánto falta. Al terminar, la descarga queda disponible 7 días.
 *
 * En el demo la espera se simula en 1 minuto (estándar) o 2 (económica).
 * Este archivo se carga antes que app.js y usa sus funciones al ejecutarse.
 */
'use strict';

const PRIORIDADES = {
  estandar:  { nombre: 'Estándar',  real: '≈ 12 horas', demoSeg: 60,  desc: 'Para lo que se necesita en el día.' },
  economica: { nombre: 'Económica', real: '≈ 48 horas', demoSeg: 120, desc: 'Más barata; para lo que puede esperar.' },
};
const DIAS_DISPONIBLE = 7;

// ── Catálogo de ejemplo ─────────────────────────────────────────────
// Se genera siempre igual (números "aleatorios" con semilla fija), así
// no ocupa espacio en el navegador y todos ven el mismo catálogo.
function aleatorioConSemilla(semilla) {
  return () => {
    semilla |= 0; semilla = (semilla + 0x6D2B79F5) | 0;
    let t = Math.imul(semilla ^ (semilla >>> 15), 1 | semilla);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

const OBRAS_ARCHIVADAS = [
  { id: 'a16', clave: 'CH-2016-004', anio: 2016, nombre: 'Casa habitación Lomas del Valle', cliente: 'Particular' },
  { id: 'a17', clave: 'CH-2017-009', anio: 2017, nombre: 'Remodelación de oficinas Grupo Norte', cliente: 'Grupo Norte' },
  { id: 'a18', clave: 'CH-2018-002', anio: 2018, nombre: 'Bodega industrial El Salto', cliente: 'Logística El Salto' },
  { id: 'a19', clave: 'CH-2019-011', anio: 2019, nombre: 'Plaza comercial Los Arcos', cliente: 'Desarrollos Los Arcos' },
  { id: 'a20', clave: 'CH-2020-005', anio: 2020, nombre: 'Escuela primaria (obra pública)', cliente: 'Gobierno municipal' },
  { id: 'a21', clave: 'CH-2021-003', anio: 2021, nombre: 'Nave logística Periférico', cliente: 'Almacenes del Centro' },
  { id: 'a22', clave: 'CH-2022-007', anio: 2022, nombre: 'Pavimentación Av. Industria', cliente: 'Gobierno municipal' },
  { id: 'a23', clave: 'CH-2023-012', anio: 2023, nombre: 'Clínica de especialidades', cliente: 'Servicios Médicos del Bajío' },
];

// Plantillas de archivos por carpeta: [nombre, extensión, MB mínimo, MB máximo, cuántos]
const PLANTILLAS_ARCHIVO = {
  contrato:     [['Contrato de obra', 'pdf', 2, 12, 1], ['Fianza', 'pdf', 1, 4, 2], ['Convenio modificatorio', 'pdf', 1, 6, 1]],
  proyecto:     [['Planos arquitectónicos', 'dwg', 8, 60, 2], ['Planos estructurales', 'dwg', 8, 60, 2], ['Memoria de cálculo', 'pdf', 3, 40, 1], ['Planos escaneados', 'pdf', 80, 600, 1]],
  permisos:     [['Licencia de construcción', 'pdf', 1, 8, 1], ['Dictamen de uso de suelo', 'pdf', 1, 6, 1]],
  estimaciones: [['Estimación', 'xlsx', 1, 6, 6], ['Números generadores', 'pdf', 5, 90, 2]],
  bitacora:     [['Bitácora de obra escaneada', 'pdf', 60, 900, 2]],
  calidad:      [['Pruebas de laboratorio', 'pdf', 2, 30, 3]],
  seguridad:    [['Programa de seguridad', 'docx', 1, 5, 1], ['Constancias de capacitación', 'pdf', 5, 60, 1]],
  informes:     [['Reporte fotográfico', 'zip', 900, 7000, 5], ['Video de avance', 'mp4', 1500, 12000, 2], ['Informe mensual', 'pdf', 3, 40, 3]],
  cierre:       [['Acta de entrega-recepción', 'pdf', 1, 8, 1], ['Planos as-built', 'dwg', 10, 80, 1], ['Finiquito', 'pdf', 1, 6, 1]],
};

let _catalogo = null;
function catalogoArchivo() {
  if (_catalogo) return _catalogo;
  const lista = [];
  OBRAS_ARCHIVADAS.forEach((o, io) => {
    const azar = aleatorioConSemilla(1000 + io * 97);
    CARPETAS.forEach((c) => {
      (PLANTILLAS_ARCHIVO[c.id] || []).forEach(([base, ext, min, max, cuantos]) => {
        for (let n = 1; n <= cuantos; n++) {
          const mb = min + azar() * (max - min);
          const mes = 1 + Math.floor(azar() * 12);
          const dia = 1 + Math.floor(azar() * 27);
          lista.push({
            id: `${o.id}-${c.id}-${lista.length}`,
            obraId: o.id, carpeta: c.id, ext,
            nombre: `${base}${cuantos > 1 ? ' ' + String(n).padStart(2, '0') : ''}.${ext}`,
            tamano: Math.round(mb * 1048576),
            fecha: `${o.anio}-${String(mes).padStart(2, '0')}-${String(dia).padStart(2, '0')}`,
          });
        }
      });
    });
  });
  _catalogo = lista;
  return lista;
}
const archivoPor = (id) => catalogoArchivo().find((a) => a.id === id);
const obraArchivadaPor = (id) => OBRAS_ARCHIVADAS.find((o) => o.id === id);
const sumaTamanos = (ids) => ids.reduce((t, id) => t + (archivoPor(id)?.tamano || 0), 0);

// Formato para tamaños grandes (el de app.js llega hasta MB).
function tamanoGrande(b) {
  if (b >= 1024 ** 4) return (b / 1024 ** 4).toFixed(2) + ' TB';
  if (b >= 1024 ** 3) return (b / 1024 ** 3).toFixed(1) + ' GB';
  return fmt.tamano(b);
}

// ── Requisiciones ───────────────────────────────────────────────────
function requisicionesIniciales() {
  const ahora = Date.now();
  const cat = catalogoArchivo();
  const buscar = (obraId, texto) => cat.filter((a) => a.obraId === obraId && a.nombre.startsWith(texto)).map((a) => a.id);
  return [
    { id: 'rq1', folio: 'REQ-0001', usuarioId: 'u3', archivos: buscar('a21', 'Planos estructurales'), prioridad: 'estandar',
      motivo: 'Referencia de conexiones de estructura para la nave Parque Norte.', estado: 'restaurando',
      solicitada: ahora - 3 * 86400000, aprobadaPor: 'u2', aprobada: ahora - 3 * 86400000 + 3600000,
      listoDemo: ahora - 2 * 86400000 },
    { id: 'rq2', folio: 'REQ-0002', usuarioId: 'u5', archivos: buscar('a19', 'Contrato de obra').concat(buscar('a19', 'Finiquito')), prioridad: 'economica',
      motivo: 'Auditoría contable del ejercicio 2019.', estado: 'pendiente', solicitada: ahora - 5 * 3600000 },
    { id: 'rq3', folio: 'REQ-0003', usuarioId: 'u2', archivos: buscar('a23', 'Planos as-built'), prioridad: 'estandar',
      motivo: 'El cliente pide copia para su área de mantenimiento.', estado: 'restaurando',
      solicitada: ahora - 20000, aprobadaPor: 'u2', aprobada: ahora - 20000, listoDemo: ahora + 40000 },
  ];
}
function requisiciones() {
  if (!S().requisiciones) { S().requisiciones = requisicionesIniciales(); Store.guardar(); }
  return S().requisiciones;
}

// El estado "disponible" y "expirada" se calculan con la hora actual.
function estadoRequisicion(r) {
  if (r.estado === 'restaurando') {
    if (Date.now() < r.listoDemo) return 'restaurando';
    return Date.now() > r.listoDemo + DIAS_DISPONIBLE * 86400000 ? 'expirada' : 'disponible';
  }
  return r.estado; // pendiente | rechazada
}
const ESTADO_REQ = {
  pendiente:   { nombre: 'Por aprobar',      clase: 'aviso' },
  restaurando: { nombre: 'En restauración',  clase: 'info' },
  disponible:  { nombre: 'Disponible',       clase: 'ok' },
  expirada:    { nombre: 'Expirada',         clase: '' },
  rechazada:   { nombre: 'Rechazada',        clase: 'alerta' },
};
const chipReq = (r) => { const e = ESTADO_REQ[estadoRequisicion(r)]; return chip(e.nombre, e.clase); };

function cuentaRegresiva(ms) {
  const s = Math.max(0, Math.ceil(ms / 1000));
  return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
}
function textoTiempo(r) {
  const est = estadoRequisicion(r);
  const p = PRIORIDADES[r.prioridad];
  if (est === 'pendiente') return `Espera aprobación · luego ${p.real}`;
  if (est === 'restaurando') return `Lista en <b data-listo="${r.listoDemo}">${cuentaRegresiva(r.listoDemo - Date.now())}</b> <small>(real: ${p.real})</small>`;
  if (est === 'disponible') return `Descarga hasta ${fmt.fecha(new Date(r.listoDemo + DIAS_DISPONIBLE * 86400000).toISOString())}`;
  if (est === 'expirada') return 'Se puede volver a solicitar';
  return '—';
}
const misRequisiciones = () => requisiciones().filter((r) => esGestor() || r.usuarioId === usuario.id);
// Número para el globo del menú: por aprobar (gestores) o listas para descargar (el resto).
function avisosArchivo() {
  if (esGestor()) return requisiciones().filter((r) => r.estado === 'pendiente').length;
  return misRequisiciones().filter((r) => estadoRequisicion(r) === 'disponible').length;
}

function siguienteFolioReq() {
  const n = requisiciones().map((r) => parseInt(r.folio.replace(/\D/g, ''), 10) || 0);
  return 'REQ-' + String(Math.max(0, ...n) + 1).padStart(4, '0');
}

// Archivos marcados en el catálogo (se conservan al cambiar de carpeta).
const seleccionArchivo = new Set();

// ── Vistas ──────────────────────────────────────────────────────────
function vistaArchivo(partes, params) {
  const sub = partes[1];
  const tabs = `<nav class="tabs">
    <a class="tab ${sub !== 'requisiciones' ? 'activo' : ''}" href="#/archivo">Catálogo</a>
    <a class="tab ${sub === 'requisiciones' ? 'activo' : ''}" href="#/archivo/requisiciones">Requisiciones<span class="n">${misRequisiciones().length}</span></a>
  </nav>`;
  const cat = catalogoArchivo();
  const base = {
    titulo: 'Archivo muerto',
    sub: `${OBRAS_ARCHIVADAS.length} obras archivadas · ${cat.length.toLocaleString('es-MX')} archivos · ${tamanoGrande(cat.reduce((t, a) => t + a.tamano, 0))} en almacenamiento en frío (catálogo de ejemplo)`,
  };
  if (sub === 'requisiciones') return { ...base, html: tabs + vistaRequisiciones() };
  const obraId = sub === 'obra' ? partes[2] : null;
  const carpetaId = sub === 'obra' ? partes[3] : null;
  const r = vistaCatalogo(obraId, carpetaId, params.get('q') || '');
  return { ...base, html: tabs + r.html, montar: r.montar };
}

function filaArchivo(a, conObra) {
  const o = obraArchivadaPor(a.obraId);
  return `<tr data-id="${esc(a.id)}">
    <td style="width:34px"><input type="checkbox" class="sel-arch" value="${esc(a.id)}" ${seleccionArchivo.has(a.id) ? 'checked' : ''} aria-label="Seleccionar ${esc(a.nombre)}"></td>
    <td><div class="doc-nombre">${iconoExt(a.ext)}<div><b>${esc(a.nombre)}</b>
      <small>${conObra ? esc(o.clave) + ' · ' : ''}${esc(carpetaPor(a.carpeta)?.nombre)}</small></div></div></td>
    <td>${fmt.fecha(a.fecha)}</td>
    <td class="num">${tamanoGrande(a.tamano)}</td>
    <td class="acc"><button type="button" class="btn chico" data-accion="solicitarArchivo" data-id="${esc(a.id)}">Solicitar</button></td>
  </tr>`;
}

function vistaCatalogo(obraId, carpetaId, q) {
  const cat = catalogoArchivo();
  const obra = obraArchivadaPor(obraId);
  const anios = [...new Set(OBRAS_ARCHIVADAS.map((o) => o.anio))].sort((a, b) => b - a);
  const nav = `<nav class="carpetas" aria-label="Obras archivadas">
    <a class="carpeta ${!obra && !q ? 'activo' : ''}" href="#/archivo">${IC.carpeta}<span>Todas las obras</span></a>
    ${anios.map((anio) => `<div class="archivo-anio">${anio}</div>
      ${OBRAS_ARCHIVADAS.filter((o) => o.anio === anio).map((o) => `<a class="carpeta ${obra?.id === o.id ? 'activo' : ''}" href="#/archivo/obra/${o.id}" title="${esc(o.nombre)}">
        ${IC.carpeta}<span class="archivo-obra-t">${esc(o.nombre)}</span></a>`).join('')}`).join('')}
  </nav>`;

  let titulo, desc, lista;
  if (q) {
    const t = normalizar(q);
    lista = cat.filter((a) => normalizar([a.nombre, obraArchivadaPor(a.obraId).nombre, obraArchivadaPor(a.obraId).clave, carpetaPor(a.carpeta).nombre].join(' ')).includes(t));
    titulo = `Resultados para “${esc(q)}”`;
    desc = `${lista.length} archivos`;
  } else if (obra) {
    lista = cat.filter((a) => a.obraId === obra.id && (!carpetaId || a.carpeta === carpetaId));
    titulo = `${esc(obra.clave)} · ${esc(obra.nombre)}`;
    desc = `${esc(obra.cliente)} · ${obra.anio}`;
  } else {
    lista = null;
    titulo = 'Obras archivadas';
    desc = 'Elige una obra o busca un archivo por nombre.';
  }

  let cuerpo;
  if (!lista) {
    cuerpo = `<div class="archivo-intro">
        <p>Los archivos del archivo muerto están guardados en <b>almacenamiento en frío</b>: cuesta muy poco,
           pero no se abren al momento. Búscalos aquí, márcalos y haz una <b>requisición</b>; te avisamos cuando estén listos para descargar.</p>
        <ol><li>Busca o navega por obra y carpeta.</li><li>Marca los archivos y pulsa <b>Solicitar</b>.</li>
          <li>${esGestor() ? 'La restauración empieza de inmediato.' : 'Un coordinador aprueba la solicitud.'}</li>
          <li>Estándar ≈ 12 h, económica ≈ 48 h. Luego se descargan durante ${DIAS_DISPONIBLE} días.</li></ol>
      </div>
      <div class="tabla-wrap"><table class="tabla">
        <thead><tr><th>Obra</th><th>Año</th><th class="num">Archivos</th><th class="num">Tamaño</th></tr></thead>
        <tbody>${OBRAS_ARCHIVADAS.map((o) => { const as = cat.filter((a) => a.obraId === o.id);
          return `<tr class="clic" data-ir="#/archivo/obra/${o.id}"><td><b>${esc(o.clave)}</b> · ${esc(o.nombre)}</td><td>${o.anio}</td>
            <td class="num">${as.length}</td><td class="num">${tamanoGrande(as.reduce((t, a) => t + a.tamano, 0))}</td></tr>`; }).join('')}</tbody>
      </table></div>`;
  } else if (!lista.length) {
    cuerpo = vacio('Ningún archivo coincide.');
  } else {
    const chips = obra ? `<div class="archivo-carpetas">
        <a class="chip ${!carpetaId ? 'marca' : ''}" href="#/archivo/obra/${obra.id}">Todas</a>
        ${CARPETAS.filter((c) => cat.some((a) => a.obraId === obra.id && a.carpeta === c.id)).map((c) =>
          `<a class="chip ${carpetaId === c.id ? 'marca' : ''}" href="#/archivo/obra/${obra.id}/${c.id}">${esc(c.nombre)}</a>`).join('')}
      </div>` : '';
    cuerpo = `${chips}<div class="tabla-wrap"><table class="tabla">
      <thead><tr><th><input type="checkbox" id="sel-todos" aria-label="Seleccionar todos"></th><th>Archivo</th><th>Fecha</th><th class="num">Tamaño</th><th></th></tr></thead>
      <tbody id="tabla-archivo">${lista.map((a) => filaArchivo(a, !obra)).join('')}</tbody></table></div>`;
  }

  return {
    html: `<div class="docs-layout">${nav}
      <div>
        <form class="filtros" id="form-archivo" role="search" style="margin-bottom:14px">
          <div class="buscador" style="max-width:none">${IC.buscar}<input type="search" id="q-archivo" value="${esc(q)}" placeholder="Buscar en todo el archivo muerto: bitácora, as-built, CH-2019…" aria-label="Buscar en el archivo muerto"></div>
          <button class="btn" type="submit">Buscar</button>
        </form>
        <div class="card" style="margin:0"><div class="card-h"><div><div class="card-t">${titulo}</div><div class="carpeta-desc">${desc}</div></div></div>${cuerpo}</div>
        <div class="barra-seleccion ${seleccionArchivo.size ? '' : 'oculto'}" id="barra-seleccion">
          <span id="sel-resumen"></span>
          <button type="button" class="btn" data-accion="limpiarSeleccion">Quitar selección</button>
          <button type="button" class="btn primario" data-accion="solicitarSeleccion">Solicitar seleccionados</button>
        </div>
      </div></div>`,
    montar() {
      const barra = $('#barra-seleccion');
      const actualizar = () => {
        barra.classList.toggle('oculto', !seleccionArchivo.size);
        $('#sel-resumen').innerHTML = `<b>${seleccionArchivo.size}</b> archivo(s) · ${tamanoGrande(sumaTamanos([...seleccionArchivo]))}`;
        const todos = $('#sel-todos');
        if (todos) {
          const marcas = $$('.sel-arch');
          todos.checked = marcas.length > 0 && marcas.every((m) => m.checked);
        }
      };
      $('#tabla-archivo')?.addEventListener('change', (e) => {
        if (!e.target.classList.contains('sel-arch')) return;
        if (e.target.checked) seleccionArchivo.add(e.target.value); else seleccionArchivo.delete(e.target.value);
        actualizar();
      });
      $('#sel-todos')?.addEventListener('change', (e) => {
        $$('.sel-arch').forEach((m) => { m.checked = e.target.checked; if (m.checked) seleccionArchivo.add(m.value); else seleccionArchivo.delete(m.value); });
        actualizar();
      });
      $('#form-archivo').addEventListener('submit', (e) => {
        e.preventDefault();
        const valor = $('#q-archivo').value.trim();
        location.hash = valor ? '#/archivo?q=' + encodeURIComponent(valor) : '#/archivo';
      });
      actualizar();
    },
  };
}

function vistaRequisiciones() {
  const lista = misRequisiciones().slice().sort((a, b) => b.solicitada - a.solicitada);
  const porAprobar = esGestor() ? lista.filter((r) => r.estado === 'pendiente') : [];
  const fila = (r) => {
    const u = usuarioPor(r.usuarioId);
    return `<tr class="clic" data-accion="verRequisicion" data-id="${esc(r.id)}">
      <td><b>${esc(r.folio)}</b><div style="font-size:12.5px;color:var(--tinta-3)">${fmt.relativo(new Date(r.solicitada).toISOString())}</div></td>
      <td>${esc(u?.nombre || '—')}</td>
      <td>${r.archivos.length} · ${tamanoGrande(sumaTamanos(r.archivos))}</td>
      <td>${esc(PRIORIDADES[r.prioridad].nombre)}</td>
      <td>${chipReq(r)}</td>
      <td style="font-size:13.5px">${textoTiempo(r)}</td></tr>`;
  };
  return `${porAprobar.length ? `<div class="card"><div class="card-h"><span class="card-t">Por aprobar</span><span class="card-s">Recuperar archivos del frío tiene costo; revisa el tamaño antes de aprobar.</span></div>
      <ul class="lista-simple">${porAprobar.map((r) => `<li><div style="flex:1;min-width:0"><b>${esc(r.folio)}</b> · ${esc(usuarioPor(r.usuarioId)?.nombre)} · ${r.archivos.length} archivo(s), ${tamanoGrande(sumaTamanos(r.archivos))}
          <div style="font-size:13px;color:var(--tinta-3)">${esc(r.motivo)}</div></div>
        <div class="der" style="display:flex;gap:6px"><button class="btn chico" data-accion="rechazarRequisicion" data-id="${esc(r.id)}">Rechazar</button>
          <button class="btn chico primario" data-accion="aprobarRequisicion" data-id="${esc(r.id)}">Aprobar</button></div></li>`).join('')}</ul></div>` : ''}
    <div class="card">${lista.length ? `<div class="tabla-wrap"><table class="tabla">
      <thead><tr><th>Folio</th><th>Solicitó</th><th>Archivos</th><th>Prioridad</th><th>Estado</th><th>Tiempo</th></tr></thead>
      <tbody>${lista.map(fila).join('')}</tbody></table></div>`
      : vacio('Todavía no hay requisiciones. Ve al catálogo, marca los archivos que necesitas y pulsa “Solicitar”.')}</div>
    <p class="ayuda">En este demo la restauración se simula en ${PRIORIDADES.estandar.demoSeg / 60} min (estándar) o ${PRIORIDADES.economica.demoSeg / 60} min (económica). En la versión real tarda ${PRIORIDADES.estandar.real} u ${PRIORIDADES.economica.real} y se avisa por correo.</p>`;
}

// ── Formularios y acciones ──────────────────────────────────────────
function formRequisicion(ids) {
  if (!ids.length) return;
  const muestra = ids.slice(0, 8).map((id) => { const a = archivoPor(id); return `<li>${esc(a.nombre)} <small style="color:var(--tinta-3)">· ${esc(obraArchivadaPor(a.obraId).clave)} · ${tamanoGrande(a.tamano)}</small></li>`; }).join('');
  modal({
    titulo: 'Nueva requisición',
    textoEnviar: esGestor() ? 'Solicitar y restaurar' : 'Enviar para aprobación',
    cuerpo: `<p style="margin-bottom:8px"><b>${ids.length}</b> archivo(s) · <b>${tamanoGrande(sumaTamanos(ids))}</b></p>
      <ul class="lista" style="margin:0 0 14px 18px;font-size:13.5px">${muestra}${ids.length > 8 ? `<li>y ${ids.length - 8} más…</li>` : ''}</ul>
      <div class="campo"><label>Motivo *</label><textarea name="motivo" placeholder="Para qué se necesitan (auditoría, referencia técnica, solicitud del cliente…)"></textarea></div>
      <div class="campo"><label>Prioridad</label>
        ${Object.entries(PRIORIDADES).map(([k, p]) => `<label class="opcion-radio"><input type="radio" name="prioridad" value="${k}" ${k === 'estandar' ? 'checked' : ''}>
          <span><b>${p.nombre} · ${p.real}</b><small>${p.desc}</small></span></label>`).join('')}</div>
      <div class="ayuda">${esGestor() ? 'Como coordinador, la restauración empieza de inmediato.' : 'La solicitud la aprueba un coordinador o el administrador.'}
        Cuando esté lista, la descarga queda disponible ${DIAS_DISPONIBLE} días.</div>`,
    alEnviar: (f) => {
      const motivo = valor(f, 'motivo');
      if (!motivo) throw new Invalido('Escribe el motivo de la solicitud.');
      const prioridad = f.elements.prioridad.value;
      const ahora = Date.now();
      const r = { id: nuevoId('rq'), folio: siguienteFolioReq(), usuarioId: usuario.id, archivos: ids.slice(), prioridad, motivo, solicitada: ahora, estado: 'pendiente' };
      if (esGestor()) Object.assign(r, { estado: 'restaurando', aprobadaPor: usuario.id, aprobada: ahora, listoDemo: ahora + PRIORIDADES[prioridad].demoSeg * 1000 });
      requisiciones().push(r);
      registrar('solicitó del archivo muerto', '', `${r.folio} · ${ids.length} archivo(s)`);
      seleccionArchivo.clear();
      cerrarModal();
      Store.guardar();
      location.hash = '#/archivo/requisiciones';
      render();
      aviso(esGestor() ? `${r.folio}: restauración iniciada.` : `${r.folio} enviada para aprobación.`);
    },
  });
}

function verRequisicion(id) {
  const r = requisiciones().find((x) => x.id === id);
  if (!r || (!esGestor() && r.usuarioId !== usuario.id)) return;
  const est = estadoRequisicion(r);
  const pasos = [
    ['Solicitada', r.solicitada, usuarioPor(r.usuarioId)?.nombre],
    r.estado === 'rechazada' ? ['Rechazada', r.rechazada, usuarioPor(r.rechazadaPor)?.nombre] : ['Aprobada', r.aprobada, usuarioPor(r.aprobadaPor)?.nombre],
    ['Disponible para descargar', r.estado === 'restaurando' ? r.listoDemo : null, ''],
  ];
  const archivos = r.archivos.map((aid) => { const a = archivoPor(aid);
    return `<li>${iconoExt(a.ext)}<div style="flex:1;min-width:0"><b style="font-weight:600">${esc(a.nombre)}</b>
      <div style="font-size:12.5px;color:var(--tinta-3)">${esc(obraArchivadaPor(a.obraId).clave)} · ${esc(carpetaPor(a.carpeta).nombre)} · ${tamanoGrande(a.tamano)}</div></div>
      ${est === 'disponible' ? `<button type="button" class="btn chico" data-accion="descargarArchivado" data-id="${esc(a.id)}">${IC.descargar}Descargar</button>` : ''}</li>`; }).join('');
  modal({
    titulo: `${r.folio} · ${ESTADO_REQ[est].nombre}`,
    ancho: true,
    cuerpo: `<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px">${chipReq(r)}
        <span>${esc(PRIORIDADES[r.prioridad].nombre)} · ${r.archivos.length} archivo(s) · ${tamanoGrande(sumaTamanos(r.archivos))}</span>
        <span style="margin-left:auto">${textoTiempo(r)}</span></div>
      <p style="margin-bottom:14px"><b>Motivo:</b> ${esc(r.motivo)}${r.nota ? `<br><b>Nota:</b> ${esc(r.nota)}` : ''}</p>
      <ol class="linea-tiempo">${pasos.map(([t, cuando, quien]) => `<li class="${cuando && cuando <= Date.now() ? 'hecho' : ''}">
        <b>${t}</b><small>${cuando ? fmt.fecha(new Date(cuando).toISOString()) + (quien ? ' · ' + esc(quien) : '') : 'Pendiente'}</small></li>`).join('')}</ol>
      <ul class="lista-simple" style="border:1px solid var(--linea);margin-top:14px">${archivos}</ul>`,
    pie: `${esGestor() && r.estado === 'pendiente' ? `<button type="button" class="btn" data-accion="rechazarRequisicion" data-id="${esc(r.id)}">Rechazar</button>
        <button type="button" class="btn primario" data-accion="aprobarRequisicion" data-id="${esc(r.id)}">Aprobar</button>` : ''}
      ${est === 'expirada' ? `<button type="button" class="btn primario" data-accion="resolicitar" data-id="${esc(r.id)}">Volver a solicitar</button>` : ''}
      <button type="button" class="btn" data-cerrar>Cerrar</button>`,
  });
}

const ACCIONES_ARCHIVO = {
  solicitarArchivo: (ds) => formRequisicion([ds.id]),
  solicitarSeleccion: () => formRequisicion([...seleccionArchivo]),
  limpiarSeleccion: () => { seleccionArchivo.clear(); render(); },
  verRequisicion: (ds) => verRequisicion(ds.id),
  resolicitar: (ds) => { const r = requisiciones().find((x) => x.id === ds.id); formRequisicion(r.archivos); },
  aprobarRequisicion: (ds) => {
    const r = requisiciones().find((x) => x.id === ds.id);
    const ahora = Date.now();
    Object.assign(r, { estado: 'restaurando', aprobadaPor: usuario.id, aprobada: ahora, listoDemo: ahora + PRIORIDADES[r.prioridad].demoSeg * 1000 });
    registrar('aprobó la requisición del archivo muerto', '', r.folio);
    guardarYRefrescar();
    aviso(`${r.folio} aprobada: restauración iniciada.`);
  },
  rechazarRequisicion: (ds) => {
    const r = requisiciones().find((x) => x.id === ds.id);
    modal({
      titulo: `Rechazar ${r.folio}`,
      textoEnviar: 'Rechazar',
      cuerpo: `<div class="campo"><label>Motivo del rechazo *</label><textarea name="nota" placeholder="Se avisará a quien la solicitó"></textarea></div>`,
      alEnviar: (f) => {
        const nota = valor(f, 'nota');
        if (!nota) throw new Invalido('Escribe el motivo del rechazo.');
        Object.assign(r, { estado: 'rechazada', rechazadaPor: usuario.id, rechazada: Date.now(), nota });
        registrar('rechazó la requisición del archivo muerto', '', r.folio);
        cerrarModal();
        guardarYRefrescar();
      },
    });
  },
  descargarArchivado: () => aviso('Archivo de ejemplo: en la versión real aquí se descarga el archivo restaurado.'),
};

// Cada segundo actualiza los contadores. Cuando una restauración termina,
// vuelve a pintar la pantalla (si no hay una ventana abierta) y avisa.
let _listasAvisadas = null;
let _usuarioAvisos = null;
setInterval(() => {
  if (typeof usuario === 'undefined' || !usuario || !S()) return;
  if (_usuarioAvisos !== usuario.id) { _usuarioAvisos = usuario.id; _listasAvisadas = null; }
  $$('[data-listo]').forEach((el) => { el.textContent = cuentaRegresiva(Number(el.dataset.listo) - Date.now()); });
  const listas = misRequisiciones().filter((r) => estadoRequisicion(r) === 'disponible').map((r) => r.id);
  if (_listasAvisadas === null) { _listasAvisadas = new Set(listas); return; }
  const nuevas = listas.filter((id) => !_listasAvisadas.has(id));
  if (!nuevas.length) return;
  nuevas.forEach((id) => _listasAvisadas.add(id));
  if (!$('#modal')) render();
  const r = requisiciones().find((x) => x.id === nuevas[0]);
  aviso(`${r.folio} ya está lista para descargar.`);
}, 1000);
