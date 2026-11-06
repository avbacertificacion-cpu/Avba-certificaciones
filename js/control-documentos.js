/* ══════════════════════════════════════════════════════════════
   AVBA — Control de documentos (pantalla de Certificaciones)

   Certificaciones tiene que saber, sin ir a buscarlo, si lo que el cliente
   tiene —en el portal o por correo— sigue diciendo lo mismo que el sistema.
   El servidor (api/Huella.php) guarda con qué datos se generó cada PDF y con
   cuáles salió cada correo; aquí se pinta:

     · un aviso arriba, en todas las secciones, con lo que ya no coincide y un
       botón para abrir cada registro;
     · en la ficha de cada equipo, sesión de accesorios y participante, la
       comparativa completa: qué tiene el portal, qué se envió, cuándo, a quién,
       y qué cambió desde entonces;
     · una marca ⚠ en las listas.

   Después de enviar, publicar, emitir o subir un PDF se vuelve a revisar solo:
   no hay que acordarse de recargar para que el aviso se quite.
   ══════════════════════════════════════════════════════════════ */
(function () {
  const API = 'api/index.php';
  const MODULO = { equipo: 'Maquinaria', accesorio: 'Accesorios', personal: 'Personal' };

  let alertas   = [];
  let porClave  = {};
  let revisados = 0;
  let cargado   = false;
  let abierto   = false;
  let cargando  = null;
  const cajas   = [];          // fichas abiertas, para refrescarlas tras un envío

  const esc = s => String(s == null ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

  async function pedir(action, payload) {
    const r = await fetch(API, { method: 'POST', body: JSON.stringify({ action, payload: payload || {} }) });
    return r.json();
  }

  // ── Estilos ──────────────────────────────────────────────────
  const css = document.createElement('style');
  css.textContent = `
    #ctl-docs-banner{margin:10px auto 0;max-width:1400px;padding:0 16px;box-sizing:border-box}
    .ctl-ban{border-radius:10px;padding:10px 14px;font-size:13px;line-height:1.45;display:flex;gap:10px;align-items:center;flex-wrap:wrap}
    .ctl-ban.mal{background:#fff4e5;border:1.5px solid #e8a33d;color:#6b4100}
    .ctl-ban.bien{background:transparent;border:1px dashed var(--borde,#d6dde6);color:var(--texto-hint,#7a8494);font-size:12px;padding:6px 12px}
    .ctl-ban b{font-weight:700}
    .ctl-ban .ctl-txt{flex:1 1 260px}
    .ctl-bt{border:1.5px solid currentColor;background:#fff;color:inherit;border-radius:7px;padding:6px 11px;font-size:12px;font-weight:600;cursor:pointer;min-height:34px}
    .ctl-ban.bien .ctl-bt{border:none;background:none;text-decoration:underline;padding:2px 4px;min-height:0;font-weight:500}
    .ctl-lista{margin-top:8px;background:#fff;border:1px solid #e8c48a;border-radius:10px;overflow:hidden}
    .ctl-grupo{padding:6px 14px;background:#fdf6ea;font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#8a5a00}
    .ctl-item{display:flex;gap:10px;align-items:flex-start;padding:10px 14px;border-top:1px solid #f1e3c9}
    .ctl-item-t{font-weight:600;font-size:13px;color:var(--texto,#1a2233)}
    .ctl-item ul{margin:4px 0 0 16px;padding:0;font-size:12px;color:#5a4a2a}
    .ctl-item li{margin:2px 0}
    .ctl-item .ctl-bt{flex:none;margin-left:auto;color:#8a5a00}
    .ctl-marca{display:inline-block;margin-left:6px;padding:1px 7px;border-radius:20px;background:#fff4e5;border:1px solid #e8a33d;color:#8a5a00;font-size:10px;font-weight:700;white-space:nowrap;vertical-align:middle;cursor:help}
    .ctl-marca:empty{display:none}
    .ctl-caja{border-radius:9px;padding:12px 14px;margin:0 0 14px;font-size:12.5px;line-height:1.5;border:1.5px solid}
    .ctl-caja.mal{background:#fff8ee;border-color:#e8a33d;color:#4a3410}
    .ctl-caja.bien{background:#f3faf5;border-color:#a8d5b5;color:#24502f}
    .ctl-caja.nada{background:var(--gris-bg,#f5f7fa);border-color:var(--borde,#d6dde6);color:var(--texto-sub,#5a6072)}
    .ctl-caja-t{font-weight:700;font-size:13px;margin-bottom:6px}
    .ctl-caja ul{margin:3px 0 4px 18px;padding:0}
    .ctl-caja li{margin:1px 0}
    .ctl-cruce{background:#fde8e8;border:1px solid #e9a3a3;border-radius:7px;padding:8px 10px;margin:6px 0 8px;color:#7a1f1f}
    .ctl-doc{border-top:1px solid rgba(0,0,0,.08);padding:7px 0 5px}
    .ctl-doc:first-of-type{border-top:none}
    .ctl-doc-n{font-weight:700;font-size:12px;text-transform:uppercase;letter-spacing:.03em;margin-bottom:2px}
    .ctl-ok{color:#2e7d32;font-weight:600}
    .ctl-no{color:#b45f00;font-weight:700}
    .ctl-nota{font-size:11.5px;opacity:.85;margin-top:4px}
    .ctl-que{margin-top:8px;padding-top:7px;border-top:1px dashed rgba(0,0,0,.15);font-weight:600}
    .ctl-resaltar{animation:ctlResalta 2.4s ease-out}
    @keyframes ctlResalta{0%{background:#fff1c9}100%{background:transparent}}
    @media (max-width:640px){
      .ctl-ban .ctl-extra{display:none}
      .ctl-item{flex-wrap:wrap}
      .ctl-item .ctl-bt{margin-left:0;width:100%}
    }`;
  document.head.appendChild(css);

  // ── Aviso general ───────────────────────────────────────────
  function contenedorBanner() {
    let el = document.getElementById('ctl-docs-banner');
    if (el) return el;
    const nav = document.querySelector('.tabs-nav');
    if (!nav) return null;
    el = document.createElement('div');
    el.id = 'ctl-docs-banner';
    const sub = document.getElementById('sub-nav-personal');
    (sub || nav).insertAdjacentElement('afterend', el);
    return el;
  }

  function pintarBanner() {
    const el = contenedorBanner();
    if (!el) return;
    if (!alertas.length) {
      el.innerHTML = cargado
        ? `<div class="ctl-ban bien"><span class="ctl-txt">✔ Lo emitido y lo enviado coincide con lo que hay en el sistema${revisados ? ` (${revisados} registro${revisados !== 1 ? 's' : ''} revisado${revisados !== 1 ? 's' : ''})` : ''}.</span>
           <button class="ctl-bt" type="button" onclick="CtlDocs.cargar()">Volver a revisar</button></div>`
        : '';
      return;
    }
    const n = alertas.length;
    let lista = '';
    if (abierto) {
      const grupos = {};
      alertas.forEach(a => { (grupos[a.modulo] = grupos[a.modulo] || []).push(a); });
      lista = '<div class="ctl-lista">' + Object.keys(MODULO).filter(m => grupos[m]).map(m =>
        `<div class="ctl-grupo">${MODULO[m]} · ${grupos[m].length}</div>` +
        grupos[m].map(a => `
          <div class="ctl-item">
            <div style="min-width:0;flex:1 1 auto">
              <div class="ctl-item-t">${esc(a.titulo)}</div>
              <ul>${a.renglones.map(r => `<li>${esc(r)}</li>`).join('')}</ul>
            </div>
            <button class="ctl-bt" type="button" onclick="CtlDocs.abrir('${a.modulo}',${a.registro_id})">Abrir</button>
          </div>`).join('')
      ).join('') + '</div>';
    }
    el.innerHTML = `
      <div class="ctl-ban mal">
        <span class="ctl-txt">⚠ <b>${n} registro${n !== 1 ? 's' : ''}</b> con documentos que ya no coinciden con lo que hay en el sistema.
          <span class="ctl-extra">Lo que tiene el cliente (portal o correo) es de antes de un cambio: hay que reemitir y reenviar.</span></span>
        <button class="ctl-bt" type="button" onclick="CtlDocs.alternar()">${abierto ? 'Ocultar' : 'Ver cuáles'}</button>
        <button class="ctl-bt" type="button" onclick="CtlDocs.cargar()" title="Volver a revisar">↻</button>
      </div>${lista}`;
  }

  async function cargar() {
    if (cargando) return cargando;
    cargando = (async () => {
      try {
        const d = await pedir('ALERTAS_DOCUMENTOS');
        if ((d.status || '') !== 'success') return;
        alertas   = d.alertas || [];
        revisados = d.revisados || 0;
        porClave  = {};
        alertas.forEach(a => { porClave[a.modulo + ':' + a.registro_id] = a; });
        cargado = true;
        pintarBanner();
        pintarMarcas();
      } catch (e) { /* sin conexión: el aviso se queda como estaba */ }
      finally { cargando = null; }
    })();
    return cargando;
  }

  // ── Marcas en las listas ────────────────────────────────────
  /** Lo que pone cada lista junto al registro. Se rellena al llegar los avisos. */
  function marca(modulo, ids) {
    const lista = (Array.isArray(ids) ? ids : [ids]).map(Number).join(',');
    return `<span class="ctl-marca" data-ctl-mod="${modulo}" data-ctl-ids="${lista}">${textoMarca(modulo, lista)}</span>`;
  }
  function textoMarca(modulo, lista) {
    const ids = String(lista).split(',').filter(Boolean);
    const n = ids.filter(id => porClave[modulo + ':' + id]).length;
    if (!n) return '';
    return ids.length > 1 ? `⚠ ${n} con documentos desactualizados` : '⚠ Documentos desactualizados';
  }
  function pintarMarcas() {
    document.querySelectorAll('.ctl-marca[data-ctl-mod]').forEach(el => {
      el.innerHTML = textoMarca(el.dataset.ctlMod, el.dataset.ctlIds);
      const a = porClave[el.dataset.ctlMod + ':' + el.dataset.ctlIds];
      el.title = a ? a.renglones.join('\n') : '';
    });
  }

  // ── Comparativa de un registro ──────────────────────────────
  /** "el certificado", "el DC-3", "la credencial": el nombre dentro de una frase. */
  function conArticulo(n) {
    const t = /^[A-Z0-9-]+$/.test(n) ? n : n.charAt(0).toLowerCase() + n.slice(1);
    return (/^credencial/i.test(n) ? 'la ' : 'el ') + t;
  }
  const mayuscula = t => t.charAt(0).toUpperCase() + t.slice(1);

  function renglones(lista) {
    return lista && lista.length ? '<ul>' + lista.map(r => `<li>${esc(r)}</li>`).join('') + '</ul>' : '';
  }

  function htmlDoc(d) {
    const e = d.emitido, v = d.enviado;
    let h = `<div class="ctl-doc"><div class="ctl-doc-n">${esc(d.nombre)}</div>`;
    if (e) {
      const base = e.origen === 'linea_base';
      const que  = e.origen === 'manual' ? 'PDF subido a mano el ' : (base ? 'Emitido antes de este control; se compara desde el ' : 'Generado el ');
      h += `<div>Vigente (portal): ${que}${esc(e.at)}${e.por && !base ? ' por ' + esc(e.por) : ''} — `
        + (e.al_dia ? '<span class="ctl-ok">✔ coincide con el sistema</span>'
                    : '<span class="ctl-no">⚠ ya no coincide. Cambió desde entonces:</span>' + renglones(e.cambios))
        + '</div>';
      if (!e.al_dia && e.origen === 'manual') {
        h += '<div class="ctl-nota">Es un PDF subido a mano: el sistema no lo puede rehacer. Sube uno corregido o quita el reemplazo.</div>';
      }
    }
    if (v) {
      const base = v.origen === 'linea_base';
      h += `<div>Enviado por correo: ${esc(v.at)}${v.a ? ' a ' + esc(v.a) : ''}${v.veces > 1 ? ` (${v.veces} envíos)` : ''}${base ? ' (según la bitácora de envíos)' : ''} — `
        + (v.al_dia ? '<span class="ctl-ok">✔ coincide con el sistema</span>'
                    : '<span class="ctl-no">⚠ lo que recibió el cliente ya no coincide. Cambió desde el envío:</span>' + renglones(v.cambios))
        + '</div>';
      if (v.al_dia && v.igual_al_vigente === false) {
        h += '<div class="ctl-nota">El portal tiene una versión más nueva que la del correo, con los mismos datos que imprime este documento.</div>';
      }
    } else if (e) {
      h += '<div class="ctl-nota">No se ha enviado por correo desde que existe este control.</div>';
    }
    return h + '</div>';
  }

  function htmlEstado(e, compacto) {
    const docs   = (e.docs || []).filter(d => d.pieza === null);
    const piezas = (e.docs || []).filter(d => d.pieza !== null);
    if (!docs.length && !piezas.length) {
      return compacto ? '' : '<div class="ctl-caja nada"><div class="ctl-caja-t">Control de documentos</div>Todavía no se ha emitido ningún documento de este registro.</div>';
    }
    if (!e.alerta && compacto) {
      const env = docs.map(d => d.enviado).filter(Boolean).sort((a, b) => (a.at_iso < b.at_iso ? 1 : -1))[0];
      return `<div class="ctl-caja bien" style="padding:6px 10px;margin:4px 0 8px;font-size:12px">✔ Documentos al día con el sistema${env ? ` · último envío ${esc(env.at)}${env.a ? ' a ' + esc(env.a) : ''}` : ''}</div>`;
    }

    let h = `<div class="ctl-caja ${e.alerta ? 'mal' : 'bien'}">`;
    h += `<div class="ctl-caja-t">${e.alerta ? '⚠ Control de documentos: hay algo que ya no coincide' : '✔ Control de documentos: todo coincide con el sistema'}</div>`;

    (e.cruces || []).forEach(c => {
      h += `<div class="ctl-cruce"><b>${esc(mayuscula(conArticulo(c.a)))} (${esc(c.a_at)}) y ${esc(conArticulo(c.b))} (${esc(c.b_at)}) no dicen lo mismo.</b> `
        + `De lo que dice ${esc(conArticulo(c.a))} a lo que dice ${esc(conArticulo(c.b))}:${renglones(c.cambios)}</div>`;
    });
    if ((e.previos || []).length) {
      h += `<div class="ctl-cruce" style="background:#fff4e5;border-color:#e8a33d;color:#6b4100"><b>Cambios registrados después del último envío</b>, antes de que existiera este control. `
        + `Si el cliente recibió el documento antes de estos cambios, tiene una versión que ya no corresponde:${renglones(e.previos)}</div>`;
    }

    h += docs.map(htmlDoc).join('');

    if (piezas.length) {
      const mal = piezas.filter(d => d.alerta);
      h += `<div class="ctl-doc"><div class="ctl-doc-n">Certificados por pieza</div>${piezas.length} emitido${piezas.length !== 1 ? 's' : ''} — `
        + (mal.length
          ? `<span class="ctl-no">⚠ ${mal.length} ya no coincide${mal.length !== 1 ? 'n' : ''}:</span><ul>`
            + mal.slice(0, 12).map(d => `<li><b>${esc(d.pieza)}</b>: ${esc(((d.enviado && !d.enviado.al_dia ? d.enviado.cambios : d.emitido && d.emitido.cambios) || []).join('; '))}</li>`).join('')
            + (mal.length > 12 ? `<li>… y ${mal.length - 12} más</li>` : '') + '</ul>'
          : '<span class="ctl-ok">✔ todos coinciden</span>')
        + '</div>';
    }

    if (e.alerta) {
      const qué = [];
      if ((e.cruces || []).length || docs.some(d => d.emitido && !d.emitido.al_dia)) qué.push('reemite (Publicar / Emitir) los documentos marcados para que el portal quede al día');
      if (docs.some(d => d.enviado && !d.enviado.al_dia) || (e.previos || []).length) qué.push('reenvíalos por correo para que el cliente tenga la versión correcta');
      if (piezas.some(d => d.alerta)) qué.push('vuelve a emitir los certificados por pieza');
      if (qué.length) h += `<div class="ctl-que">Qué hacer: ${qué.join('; y ')}.</div>`;
    }
    return h + '</div>';
  }

  /** Pinta la comparativa de un registro en `el`. */
  async function caja(modulo, id, el, opciones) {
    if (!el) return;
    const compacto = !!(opciones && opciones.compacto);
    if (!cajas.some(c => c.el === el)) cajas.push({ el, modulo, id, compacto });
    el.dataset.ctlMod = modulo; el.dataset.ctlId = id;
    if (!el.innerHTML.trim()) el.innerHTML = compacto ? '' : '<div class="ctl-caja nada">Revisando documentos…</div>';
    try {
      const d = await pedir('ESTADO_DOCUMENTOS', { modulo, id });
      if (String(el.dataset.ctlId) !== String(id)) return;     // ya se abrió otro registro
      el.innerHTML = (d.status === 'success') ? htmlEstado(d, compacto) : '';
    } catch (e) { el.innerHTML = ''; }
  }

  /** Varios participantes de una vez: rellena cada `.ctl-docs-per[data-id]`. */
  async function cajasPersonal(contenedor) {
    const els = Array.from((contenedor || document).querySelectorAll('.ctl-docs-per[data-id]'));
    if (!els.length) return;
    els.forEach(el => { if (!cajas.some(c => c.el === el)) cajas.push({ el, modulo: 'personal', id: +el.dataset.id, compacto: true, lote: true }); });
    try {
      const d = await pedir('ESTADO_DOCUMENTOS', { modulo: 'personal', ids: els.map(el => +el.dataset.id) });
      if (d.status !== 'success') return;
      els.forEach(el => {
        const e = (d.estados || {})[el.dataset.id];
        el.innerHTML = e ? htmlEstado(e, true) : '';
      });
    } catch (e) { /* se queda vacío */ }
  }

  function refrescarCajas() {
    for (let i = cajas.length - 1; i >= 0; i--) {
      const c = cajas[i];
      if (!document.body.contains(c.el)) { cajas.splice(i, 1); continue; }
      if (c.lote) continue;
      caja(c.modulo, c.id, c.el, { compacto: c.compacto });
    }
    const per = cajas.filter(c => c.lote).map(c => c.el.closest('.modal-body')).filter(Boolean);
    Array.from(new Set(per)).forEach(m => cajasPersonal(m));
  }

  // ── Ir al registro ──────────────────────────────────────────
  async function abrir(modulo, id) {
    if (modulo === 'equipo') {
      if (typeof verSeccion === 'function') verSeccion('maquinaria');
      const hay = typeof datosGlobal !== 'undefined' && datosGlobal.some(x => +x.fila === +id);
      if (hay && typeof ver === 'function') { ver(+id); return; }
      return abrirSuelto(modulo, id);
    }
    if (modulo === 'accesorio') {
      if (typeof verSeccion === 'function') verSeccion('accesorios');
      if (typeof abrirAccCert === 'function') { abrirAccCert(+id); return; }
      return abrirSuelto(modulo, id);
    }
    if (modulo === 'personal') {
      if (typeof verSeccion === 'function') verSeccion('personal');
      // La lista de sesiones se carga sola al entrar; se espera a que llegue.
      for (let i = 0; i < 40; i++) {
        const gs = window._cursosCertGrupos || [];
        const idx = gs.findIndex(g => (g.participantes || []).some(p => +p.id === +id));
        if (idx >= 0 && typeof abrirCursoCert === 'function' && (typeof perCertCargados === 'undefined' || perCertCargados)) {
          abrirCursoCert(idx);
          setTimeout(() => {
            const el = document.querySelector(`.ctl-docs-per[data-id="${+id}"]`);
            const fila = el && el.parentElement;
            if (fila) { fila.scrollIntoView({ block: 'center', behavior: 'smooth' }); fila.classList.add('ctl-resaltar'); }
          }, 250);
          return;
        }
        await new Promise(r => setTimeout(r, 200));
      }
      return abrirSuelto(modulo, id);
    }
  }

  /** Cuando el registro no está en las listas de la pantalla: la comparativa sola. */
  function abrirSuelto(modulo, id) {
    let ov = document.getElementById('overlay-ctl-docs');
    if (!ov) {
      ov = document.createElement('div');
      ov.className = 'overlay';
      ov.id = 'overlay-ctl-docs';
      ov.innerHTML = `
        <div class="modal">
          <div class="modal-hdr">
            <div class="modal-hdr-l"><div class="m-avatar">📄</div>
              <div><div class="m-title" id="ctl-docs-tit">Control de documentos</div><div class="m-sub" id="ctl-docs-sub"></div></div>
            </div>
            <button class="btn-close" type="button" onclick="document.getElementById('overlay-ctl-docs').classList.remove('open')">×</button>
          </div>
          <div class="modal-body" id="ctl-docs-body"></div>
        </div>`;
      ov.addEventListener('click', e => { if (e.target === ov) ov.classList.remove('open'); });
      document.body.appendChild(ov);
    }
    const a = porClave[modulo + ':' + id];
    document.getElementById('ctl-docs-sub').textContent = (MODULO[modulo] || '') + (a ? ' · ' + a.titulo : '');
    ov.classList.add('open');
    caja(modulo, id, document.getElementById('ctl-docs-body'));
  }

  // ── Revisar otra vez después de enviar, publicar o emitir ──
  const ACCIONES = /ENVIAR|PUBLICAR|EMITIR|SUBIR_DOC|GENERAR_CERT|GENERAR_DOC|IMPRIMIR|GENERAR_PDF|RETORNAR|DEVOLVER|ACTUALIZAR/;
  let pendiente = null;
  function programarRevision() {
    clearTimeout(pendiente);
    pendiente = setTimeout(() => { cargar(); refrescarCajas(); }, 1200);
  }
  const _fetch = window.fetch;
  window.fetch = function (url, opts) {
    let accion = '';
    try {
      if (typeof url === 'string' && url.includes('api/index.php')) {
        const m = url.match(/[?&]action=([A-Z_]+)/);
        if (m) accion = m[1];
        const b = opts && opts.body;
        if (!accion && typeof b === 'string') accion = (JSON.parse(b).action || '');
        else if (!accion && b instanceof FormData) accion = String(b.get('action') || '');
      }
    } catch (e) { /* no es JSON: no interesa */ }
    const p = _fetch.apply(this, arguments);
    if (accion && accion !== 'ESTADO_DOCUMENTOS' && accion !== 'ALERTAS_DOCUMENTOS' && ACCIONES.test(accion)) {
      p.then(r => { if (r && r.ok) programarRevision(); }).catch(() => {});
    }
    return p;
  };

  window.CtlDocs = { cargar, alternar() { abierto = !abierto; pintarBanner(); }, marca, caja, cajasPersonal, abrir, pintarMarcas };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => cargar());
  else cargar();
}());
