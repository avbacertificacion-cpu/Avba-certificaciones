/**
 * AVBA — Solicitudes de certificación.
 *
 * Dos caras del mismo expediente:
 *   · Calidad las recibe y las trabaja        → SolicitudesCert.montarBandeja()
 *   · El cliente las pide desde su portal     → SolicitudesCert.pedir() / misSolicitudes()
 *
 * Están en un solo archivo porque comparten el vocabulario —estados, colores y
 * cómo se lee un renglón—, y tenerlo en dos sitios garantizaba que al cambiar
 * un estado una de las dos pantallas se quedara diciendo otra cosa.
 */
(function (global) {
  'use strict';

  const ESTADOS = {
    ENVIADA:     ['Enviada',     '#1e5fa8', '#e8f1fb'],
    EN_REVISION: ['En revisión', '#b8860b', '#fdf6e3'],
    PROGRAMADA:  ['Programada',  '#2e9e5b', '#e9f7ef'],
    ATENDIDA:    ['Atendida',    '#0B2545', '#eef1f5'],
    RECHAZADA:   ['Rechazada',   '#c0392b', '#fdecea'],
    CANCELADA:   ['Cancelada',   '#7a8494', '#f1f3f5'],
  };

  function esc(s) {
    return String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
  }

  function pastilla(estado) {
    const [txt, color, fondo] = ESTADOS[estado] || [estado, '#7a8494', '#f1f3f5'];
    return `<span style="display:inline-block;padding:3px 10px;border-radius:99px;background:${fondo};
            color:${color};font-size:11px;font-weight:700;white-space:nowrap">${esc(txt)}</span>`;
  }

  async function pedirApi(api, action, payload) {
    const res = await fetch(api, { method: 'POST', body: JSON.stringify({ action, payload: payload || {} }) });
    return res.json();
  }

  /* ════════════════════════════════════════════════════════
     CALIDAD — la bandeja
     ════════════════════════════════════════════════════════ */
  function montarBandeja(contenedorId, api, opciones) {
    const cont = document.getElementById(contenedorId);
    if (!cont) return null;
    const soloLectura = !!(opciones && opciones.soloLectura);
    let datos = [];
    let abierta = null;      // id de la solicitud desplegada

    cont.innerHTML = `
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
        <select id="${contenedorId}-estado" style="padding:8px 11px;border:1.5px solid var(--borde);border-radius:8px;font-size:13px">
          <option value="ABIERTAS">Abiertas</option>
          <option value="ENVIADA">Enviadas</option>
          <option value="EN_REVISION">En revisión</option>
          <option value="PROGRAMADA">Programadas</option>
          <option value="ATENDIDA">Atendidas</option>
          <option value="RECHAZADA">Rechazadas</option>
          <option value="TODAS">Todas</option>
        </select>
        <select id="${contenedorId}-tipo" style="padding:8px 11px;border:1.5px solid var(--borde);border-radius:8px;font-size:13px">
          <option value="">Personal y equipos</option>
          <option value="personal">Sólo personal</option>
          <option value="equipo">Sólo equipos</option>
        </select>
        <input type="text" id="${contenedorId}-cliente" placeholder="Empresa"
               style="padding:8px 11px;border:1.5px solid var(--borde);border-radius:8px;font-size:13px;min-width:150px">
        <button type="button" id="${contenedorId}-btn" style="padding:8px 16px;border-radius:8px;border:none;
                background:var(--azul,#185FA5);color:#fff;font-size:13px;font-weight:600;cursor:pointer">Buscar</button>
      </div>
      <div id="${contenedorId}-lista"></div>`;

    const $ = id => document.getElementById(contenedorId + '-' + id);
    $('btn').addEventListener('click', cargar);
    ['estado', 'tipo'].forEach(k => $(k).addEventListener('change', cargar));
    $('cliente').addEventListener('keydown', e => { if (e.key === 'Enter') cargar(); });

    async function cargar() {
      $('lista').innerHTML = `<p style="text-align:center;padding:26px;color:var(--texto-hint,#7a8494)">Cargando…</p>`;
      const d = await pedirApi(api, 'SOLCERT_BANDEJA', {
        estado: $('estado').value, tipo: $('tipo').value, cliente: $('cliente').value.trim(),
      });
      if (d.status !== 'success') {
        $('lista').innerHTML = `<p style="text-align:center;padding:26px;color:#c0392b">${esc(d.message || 'No se pudo cargar.')}</p>`;
        return;
      }
      datos = d.data || [];
      pintar();
    }

    function pintar() {
      if (!datos.length) {
        $('lista').innerHTML = `<p style="text-align:center;padding:30px;color:var(--texto-hint,#7a8494);font-size:14px">
          No hay solicitudes con esos filtros.</p>`;
        return;
      }
      $('lista').innerHTML = datos.map(tarjeta).join('');
      cont.querySelectorAll('[data-sol]').forEach(el => {
        el.addEventListener('click', ev => {
          if (ev.target.closest('[data-accion]')) return;   // los botones hacen lo suyo
          const id = Number(el.dataset.sol);
          abierta = abierta === id ? null : id;
          pintar();
        });
      });
      cont.querySelectorAll('[data-accion]').forEach(b => {
        b.addEventListener('click', () => accion(Number(b.dataset.sol), b.dataset.accion));
      });
    }

    function tarjeta(s) {
      const desplegada = abierta === Number(s.id);
      const icono = s.tipo === 'personal' ? '👷' : '🏗️';
      return `
      <div style="border:1.5px solid var(--borde,#cdd8e3);border-radius:11px;margin-bottom:10px;overflow:hidden;background:#fff">
        <div data-sol="${s.id}" style="padding:12px 14px;cursor:pointer;display:flex;gap:12px;align-items:center;flex-wrap:wrap">
          <span style="font-size:18px">${icono}</span>
          <div style="flex:1;min-width:190px">
            <div style="font-size:14px;font-weight:700;color:var(--texto,#0B2545)">${esc(s.cliente)}</div>
            <div style="font-size:11.5px;color:var(--texto-hint,#7a8494)">
              ${esc(s.folio)} · ${s.total_items} ${s.tipo === 'personal' ? 'persona(s)' : 'equipo(s)'}
              ${s.servicio ? ' · ' + esc(s.servicio) : ''}
            </div>
          </div>
          <div style="text-align:right">
            ${pastilla(s.estado)}
            <div style="font-size:11px;color:var(--texto-hint,#7a8494);margin-top:3px">${esc(s.fecha)}</div>
          </div>
        </div>
        ${desplegada ? detalle(s) : ''}
      </div>`;
    }

    function detalle(s) {
      const dato = (etq, v) => v ? `<div style="margin-bottom:5px"><span style="color:var(--texto-hint,#7a8494)">${etq}:</span> <strong>${esc(v)}</strong></div>` : '';
      return `
      <div style="border-top:1.5px solid var(--borde,#cdd8e3);background:var(--gris-bg,#f6f8fb);padding:14px;font-size:12.5px">
        <div style="display:flex;gap:26px;flex-wrap:wrap;margin-bottom:12px">
          <div style="flex:1;min-width:210px">
            ${dato('Fecha deseada', s.fecha_deseada)}
            ${dato('Sede', s.sede)}
            ${dato('Contacto', [s.contacto_nombre, s.contacto_tel].filter(Boolean).join(' · '))}
            ${dato('Solicitó', s.solicitado_por)}
          </div>
          <div style="flex:1;min-width:210px">
            ${dato('Programada', s.programada_para)}
            ${dato('Inspector', s.inspector)}
            ${dato('Revisó', s.revisado_por)}
          </div>
        </div>
        ${s.notas   ? `<div style="margin-bottom:10px"><span style="color:var(--texto-hint,#7a8494)">Notas del cliente:</span> ${esc(s.notas)}</div>` : ''}
        ${s.motivo  ? `<div style="margin-bottom:10px;padding:8px 11px;background:#fdecea;border-left:3px solid #c0392b;border-radius:5px">
                         <strong>Motivo:</strong> ${esc(s.motivo)}</div>` : ''}
        <div style="font-weight:700;margin-bottom:6px">Lo solicitado</div>
        <div style="background:#fff;border:1px solid var(--borde,#cdd8e3);border-radius:8px;overflow:hidden;margin-bottom:12px">
          ${(s.items || []).map((i, n) => `
            <div style="padding:8px 11px;${n ? 'border-top:1px solid #eef1f5' : ''}">
              <strong>${esc(i.nombre)}</strong>
              ${i.detalle ? `<span style="color:var(--texto-hint,#7a8494)"> · ${esc(i.detalle)}</span>` : ''}
              ${i.curp ? `<div style="font-size:11px;color:var(--texto-hint,#7a8494)">CURP: ${esc(i.curp)}</div>` : ''}
            </div>`).join('')}
        </div>
        ${soloLectura ? '' : acciones(s)}
      </div>`;
    }

    function acciones(s) {
      const btn = (acc, txt, color) =>
        `<button type="button" data-accion="${acc}" data-sol="${s.id}"
                 style="padding:7px 14px;border-radius:7px;border:1.5px solid ${color};background:#fff;
                        color:${color};font-size:12px;font-weight:600;cursor:pointer">${txt}</button>`;
      const b = [`${btn('docs', 'Ver documentación', '#185FA5')}`];
      if (s.estado === 'ENVIADA')     b.push(btn('EN_REVISION', 'Tomar para revisión', '#b8860b'));
      if (s.estado === 'ENVIADA' || s.estado === 'EN_REVISION') b.push(btn('PROGRAMADA', 'Programar', '#2e9e5b'));
      if (s.estado === 'PROGRAMADA')  b.push(btn('ATENDIDA', 'Marcar atendida', '#0B2545'));
      if (['ENVIADA','EN_REVISION','PROGRAMADA'].includes(s.estado)) b.push(btn('RECHAZADA', 'Rechazar', '#c0392b'));
      return `<div style="display:flex;gap:8px;flex-wrap:wrap">${b.join('')}</div>`;
    }

    async function accion(id, acc) {
      if (acc === 'docs') return verDocumentos(id, api);

      const payload = { id, estado: acc };
      if (acc === 'PROGRAMADA') {
        const f = prompt('¿Para qué fecha se programa? (AAAA-MM-DD)');
        if (f === null) return;
        payload.programada_para = f.trim();
        const i = prompt('¿Qué inspector la atiende? (puedes dejarlo en blanco)');
        if (i !== null) payload.inspector = i.trim();
      }
      if (acc === 'RECHAZADA') {
        const m = prompt('¿Por qué se rechaza? El cliente recibe este texto.');
        if (m === null) return;
        payload.motivo = m.trim();
      }
      const d = await pedirApi(api, 'SOLCERT_ACTUALIZAR', payload);
      if (d.status !== 'success') { alert(d.message || 'No se pudo actualizar.'); return; }
      await cargar();
    }

    cargar();
    return { cargar };
  }

  /** Lo que el cliente ya subió de cada registro pedido. */
  async function verDocumentos(id, api) {
    const d = await pedirApi(api, 'SOLCERT_DOCS', { id });
    if (d.status !== 'success') { alert(d.message || 'No se pudo leer la documentación.'); return; }

    const texto = (d.items || []).map(i => {
      const docs = i.documentos || [];
      if (!docs.length) return `• ${i.nombre}\n    SIN DOCUMENTOS CARGADOS`;
      return `• ${i.nombre}\n` + docs.map(x =>
        `    – ${x.nombre}${x.vigencia ? ' (vence ' + x.vigencia + ')' : ''}` +
        (Number(x.vencido) ? '  ⚠ VENCIDO' : '')).join('\n');
    }).join('\n\n');
    alert('Documentación cargada por el cliente\n\n' + (texto || 'Sin registros.'));
  }

  global.SolicitudesCert = { montarBandeja, verDocumentos, pastilla, esc, ESTADOS };
}(window));
