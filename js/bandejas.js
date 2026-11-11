/* ══════════════════════════════════════════════════════════════
   AVBA — Bandejas: pestañas que separan una lista por su estado

   Certificaciones tenía en una sola lista lo pendiente de enviar y lo ya
   enviado; Calidad, lo pendiente junto con lo devuelto o no conforme. Esto
   pone arriba de cada lista unas pestañas con su contador ("Pendientes de
   enviar · 12", "Enviados · 340"), y la lista muestra sólo la que está
   elegida. Los filtros de cada pantalla siguen funcionando igual, dentro de
   la pestaña.

   Uso:
     const bj = Bandejas.montar({
       id: 'cert-eq',                       // recuerda la pestaña elegida
       antesDe: '#sec-maquinaria .filtros-card',
       tabs: [{ key: 'pend', label: 'Pendientes de enviar', match: r => … }, …],
       alCambiar: () => filtrar(),          // volver a pintar la lista
     });
     // dentro del render:  bj.pintar(todos);  lista = bj.filtrar(todos);
   ══════════════════════════════════════════════════════════════ */
(function () {
  const css = document.createElement('style');
  css.textContent = `
    .bandejas{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 12px;padding:4px;background:var(--gris-bg,#f2f5f9);
      border:1px solid var(--borde,#dde3ea);border-radius:12px}
    .bandeja-btn{flex:1 1 150px;display:flex;align-items:center;justify-content:center;gap:8px;min-height:42px;
      padding:8px 12px;border:none;border-radius:9px;background:transparent;color:var(--texto-sub,#5a6072);
      font-size:13px;font-weight:600;cursor:pointer;font-family:inherit}
    .bandeja-btn:hover{background:rgba(255,255,255,.7)}
    .bandeja-btn.activa{background:#fff;color:var(--azul-dark,#0B2545);box-shadow:0 1px 4px rgba(11,37,69,.12)}
    .bandeja-n{min-width:22px;padding:1px 8px;border-radius:20px;font-size:11px;font-weight:700;
      background:var(--borde,#dde3ea);color:var(--texto,#1a2233)}
    .bandeja-btn.activa .bandeja-n{background:var(--azul,#185FA5);color:#fff}
    .bandeja-btn[data-tono=alerta] .bandeja-n{background:#fdecea;color:#b42318}
    .bandeja-btn.activa[data-tono=alerta] .bandeja-n{background:#b42318;color:#fff}
    .bandeja-btn[data-tono=ok].activa .bandeja-n{background:#2e7d32;color:#fff}
    .bandeja-corto{display:none}
    @media (max-width:600px){
      .bandejas{flex-wrap:nowrap}
      .bandeja-btn{flex:1 1 0;min-width:0;padding:8px 6px;gap:5px;font-size:12px}
      .bandeja-largo{display:none}
      .bandeja-corto{display:inline;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    }`;
  document.head.appendChild(css);

  const leer = k => { try { return localStorage.getItem('bandeja:' + k); } catch (e) { return null; } };
  const guardar = (k, v) => { try { localStorage.setItem('bandeja:' + k, v); } catch (e) {} };

  function montar(op) {
    let activa = leer(op.id);
    if (!op.tabs.some(t => t.key === activa)) activa = op.tabs[0].key;
    let el = null;

    function contenedor() {
      if (el && document.body.contains(el)) return el;
      const ref = typeof op.antesDe === 'string' ? document.querySelector(op.antesDe) : op.antesDe;
      if (!ref) return null;
      el = document.createElement('div');
      el.className = 'bandejas';
      el.setAttribute('role', 'tablist');
      el.dataset.bandeja = op.id;
      ref.parentNode.insertBefore(el, ref);
      return el;
    }

    const tab = () => op.tabs.find(t => t.key === activa) || op.tabs[0];

    return {
      activa: () => activa,
      /** Sólo lo de la pestaña elegida. */
      filtrar(arr) { const t = tab(); return (arr || []).filter(x => t.match(x)); },
      /** Pinta las pestañas con lo que hay en cada una. */
      pintar(arr) {
        const c = contenedor();
        if (!c) return;
        arr = arr || [];
        c.innerHTML = op.tabs.map(t => {
          const n = arr.filter(x => t.match(x)).length;
          return `<button type="button" role="tab" class="bandeja-btn${t.key === activa ? ' activa' : ''}"
                    data-key="${t.key}"${t.tono ? ` data-tono="${t.tono}"` : ''} aria-selected="${t.key === activa}">
                    <span class="bandeja-largo">${t.label}</span><span class="bandeja-corto">${t.corto || t.label}</span>
                    <span class="bandeja-n">${n}</span></button>`;
        }).join('');
        c.querySelectorAll('.bandeja-btn').forEach(b => b.addEventListener('click', () => {
          if (b.dataset.key === activa) return;
          activa = b.dataset.key;
          guardar(op.id, activa);
          if (typeof op.alCambiar === 'function') op.alCambiar();
        }));
      },
      /** Para llevar a alguien directo a una pestaña (p. ej. desde un aviso). */
      elegir(key) {
        if (!op.tabs.some(t => t.key === key)) return;
        activa = key; guardar(op.id, key);
        if (typeof op.alCambiar === 'function') op.alCambiar();
      },
    };
  }

  window.Bandejas = { montar };
}());
