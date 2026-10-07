/**
 * AVBA — Bitácora de envíos por correo.
 *
 * Una sola pantalla para Admin, Certificaciones y Calidad. Cada página le da
 * un contenedor y ella monta filtros y tabla.
 *
 * Lo que muestra es qué se INTENTÓ enviar: fecha, destinatario, documento y
 * quién lo mandó. Que el correo saliera del servidor no quiere decir que se
 * haya leído, así que la columna de resultado dice "enviado" o "falló", no
 * "entregado".
 */
(function (global) {
  'use strict';

  const MODULOS = [
    ['', 'Todos los módulos'], ['equipo', 'Maquinaria'], ['accesorio', 'Accesorios'],
    ['arnes', 'Arneses'], ['personal', 'Personal'], ['pnd', 'PND'],
    ['presupuesto', 'Presupuestos'],
  ];

  function esc(s) {
    return String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
  }

  function montar(contenedorId, api) {
    const cont = document.getElementById(contenedorId);
    if (!cont) return null;
    const p = contenedorId;   // prefijo de ids, para que convivan varias
    let desplazamiento = 0;
    let ultimoTotal = 0;

    cont.innerHTML = `
      <div class="tabla-card">
        <div class="tabla-header">
          <span class="tabla-title">Envíos por correo</span>
          <span class="tabla-count" id="${p}-count" style="margin-left:auto">—</span>
        </div>
        <div style="padding:12px 14px;display:flex;gap:9px;flex-wrap:wrap;align-items:flex-end;
                    border-bottom:1px solid var(--borde)">
          <div style="flex:2;min-width:220px">
            <label class="env-lbl">Buscar</label>
            <input id="${p}-q" class="env-inp" placeholder="Cliente, correo, folio o archivo…">
          </div>
          <div style="flex:1;min-width:130px">
            <label class="env-lbl">Desde</label><input id="${p}-desde" type="date" class="env-inp">
          </div>
          <div style="flex:1;min-width:130px">
            <label class="env-lbl">Hasta</label><input id="${p}-hasta" type="date" class="env-inp">
          </div>
          <div style="flex:1;min-width:140px">
            <label class="env-lbl">Módulo</label>
            <select id="${p}-mod" class="env-inp">
              ${MODULOS.map(([v, t]) => `<option value="${v}">${t}</option>`).join('')}
            </select>
          </div>
          <div style="flex:1;min-width:120px">
            <label class="env-lbl">Resultado</label>
            <select id="${p}-ok" class="env-inp">
              <option value="">Todos</option><option value="1">Enviados</option>
              <option value="0">Fallidos</option>
            </select>
          </div>
          <button class="env-btn" id="${p}-buscar">Buscar</button>
          <button class="env-btn sec" id="${p}-limpiar">Limpiar</button>
        </div>
        <div class="tabla-wrap">
          <table>
            <thead><tr>
              <th style="width:135px">Fecha y hora</th>
              <th>Cliente</th><th style="width:150px">Folio</th>
              <th>Destinatario</th><th>Documento</th>
              <th style="width:110px">Módulo</th><th style="width:110px">Enviado por</th>
              <th style="width:90px">Resultado</th>
            </tr></thead>
            <tbody id="${p}-tb">
              <tr><td colspan="8" style="text-align:center;padding:30px;color:var(--texto-hint)">Cargando…</td></tr>
            </tbody>
          </table>
        </div>
        <div id="${p}-mas" style="padding:10px;text-align:center"></div>
      </div>`;

    const $ = id => document.getElementById(p + '-' + id);

    function filtros() {
      return {
        q: $('q').value.trim(), desde: $('desde').value, hasta: $('hasta').value,
        modulo: $('mod').value, ok: $('ok').value,
        limite: 200, desplazamiento,
      };
    }

    async function cargar(acumular) {
      const tb = $('tb');
      if (!acumular) { desplazamiento = 0; tb.innerHTML =
        `<tr><td colspan="8" style="text-align:center;padding:30px;color:var(--texto-hint)">Cargando…</td></tr>`; }
      try {
        const res = await fetch(api, {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'LISTAR_ENVIOS', payload: filtros() })
        });
        const d = await res.json();
        if (d.status !== 'success') {
          tb.innerHTML = `<tr><td colspan="8" style="text-align:center;padding:26px;color:var(--rojo)">${esc(d.message || 'No se pudo cargar.')}</td></tr>`;
          return;
        }
        ultimoTotal = d.total || 0;
        $('count').textContent = ultimoTotal + ' envío' + (ultimoTotal !== 1 ? 's' : '');
        const filas = (d.data || []).map(r => `
          <tr${r.ok ? '' : ' style="background:#fdf3f3"'}>
            <td style="font-size:12px;color:var(--texto-sub);white-space:nowrap">${esc(r.fecha)}</td>
            <td style="font-weight:600">${esc(r.cliente || '—')}</td>
            <td style="font-family:monospace;font-size:11.5px;color:var(--azul-dark)">${esc(r.control || '—')}</td>
            <td style="font-size:12px">${esc(r.correo || '—')}</td>
            <td style="font-size:11.5px;color:var(--texto-sub);word-break:break-all">${esc(r.archivo || '—')}</td>
            <td style="font-size:11.5px">${esc(r.modulo_label || '—')}</td>
            <td style="font-size:11.5px;color:var(--texto-sub)">${esc(r.usuario || '—')}</td>
            <td>${r.ok
              ? '<span class="env-ok">✓ Enviado</span>'
              : `<span class="env-no" title="${esc(r.error || '')}">✕ Falló</span>`}</td>
          </tr>`).join('');
        if (acumular) tb.insertAdjacentHTML('beforeend', filas);
        else tb.innerHTML = filas || `<tr><td colspan="8" style="text-align:center;padding:30px;color:var(--texto-hint)">No hay envíos que coincidan.</td></tr>`;

        // Esta tabla sólo crece: se traen de 200 en 200 en vez de todo.
        const vistos = desplazamiento + (d.data || []).length;
        $('mas').innerHTML = vistos < ultimoTotal
          ? `<button class="env-btn sec" id="${p}-vermas">Ver más (${ultimoTotal - vistos} restantes)</button>` : '';
        const bm = $('vermas');
        if (bm) bm.onclick = () => { desplazamiento = vistos; cargar(true); };
      } catch (e) {
        tb.innerHTML = `<tr><td colspan="8" style="text-align:center;padding:26px;color:var(--rojo)">Error de conexión.</td></tr>`;
      }
    }

    $('buscar').onclick = () => cargar(false);
    $('limpiar').onclick = () => {
      ['q','desde','hasta'].forEach(k => $(k).value = '');
      $('mod').value = ''; $('ok').value = '';
      cargar(false);
    };
    $('q').onkeydown = e => { if (e.key === 'Enter') cargar(false); };

    return { cargar: () => cargar(false) };
  }

  /** Los envíos de un registro, para la pestaña de historial de su detalle. */
  async function deRegistro(api, modulo, registroId) {
    try {
      const res = await fetch(api, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'ENVIOS_DE_REGISTRO',
                               payload: { modulo, registro_id: registroId } })
      });
      const d = await res.json();
      return d.status === 'success' ? (d.data || []) : [];
    } catch (e) { return []; }
  }

  global.Envios = { montar, deRegistro, esc };
})(window);
