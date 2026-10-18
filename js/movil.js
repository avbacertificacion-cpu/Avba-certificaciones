/* ══════════════════════════════════════════════════════════════════════
   AVBA — Comportamiento común para teléfono, tablet y computadora

   Acompaña a css/movil.css. Cuatro cosas que el CSS solo no puede hacer:

   1. Avisar que una barra de pestañas tiene más pestañas escondidas, con un
      degradado en el borde que sólo aparece mientras de verdad hay más.
   2. Llevar a la vista la pestaña recién elegida, si quedó medio oculta.
   3. Al cambiar de sección, llevar la pantalla al contenido cuando quedó
      abajo. En el portal del cliente el menú mide más que la pantalla del
      teléfono: se tocaba "Mis Equipos" y el contenido aparecía 975 px abajo,
      fuera de la vista, sin que nada en pantalla cambiara. En computadora
      pasaba lo mismo en menor medida: el contenido empezaba en el último
      cuarto de la pantalla.
   4. En teléfono y tablet, un botón para regresar a ese menú.
   ══════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  const BARRAS = '.tabs-nav, .tabs-admin, .sub-nav, .tabs-sub, .sub-tabs';

  /* ── 1. Degradado de "hay más" ── */
  function marcar(barra) {
    const resto = barra.scrollWidth - barra.clientWidth;
    barra.classList.toggle('avba-mas-der', resto > 4 && barra.scrollLeft < resto - 4);
    barra.classList.toggle('avba-mas-izq', barra.scrollLeft > 4);
  }

  // Cada barra se vuelve a medir sola cuando cambia de tamaño, también al
  // pasar de oculta a visible (los submenús de Calidad y Certificaciones).
  const alCambiarTamano = 'ResizeObserver' in window
    ? new ResizeObserver(entradas => entradas.forEach(e => marcar(e.target)))
    : null;

  function prepararBarras() {
    document.querySelectorAll(BARRAS).forEach(barra => {
      if (barra.dataset.avbaMovil) return;
      barra.dataset.avbaMovil = '1';
      barra.addEventListener('scroll', () => marcar(barra), { passive: true });
      if (alCambiarTamano) alCambiarTamano.observe(barra);
      marcar(barra);
    });
  }

  /* ── 2. La pestaña elegida, a la vista ── */
  function centrarActiva(barra) {
    const activa = barra.querySelector('.active, .activo, .on');
    if (!activa) return;
    const b = barra.getBoundingClientRect();
    const a = activa.getBoundingClientRect();
    if (a.left < b.left || a.right > b.right) {
      barra.scrollBy({ left: (a.left + a.width / 2) - (b.left + b.width / 2), behavior: 'smooth' });
    }
  }

  /* ── 3. Al cambiar de sección, ir al contenido ──
     Se envuelven las funciones con que cada módulo cambia de sección. Los
     onclick del HTML las buscan en window al momento del clic, así que basta
     con reemplazarlas ahí. Para cada una, dónde puede quedar el contenido:
     en Calidad y Certificaciones algunas secciones primero muestran un
     submenú (sub-nav-…) y el contenido va debajo de él. */
  const PANELES = {
    verTab:      id => ['tab-' + id],
    verSeccion:  id => ['sub-nav-' + id, 'sec-' + id],
    verTabAdmin: id => ['tab-sec-' + id],
  };
  const visible = el => el && el.getClientRects().length > 0;

  // Sólo se mueve la pantalla si el cambio lo pidió un toque del usuario: los
  // módulos también llaman a estas funciones al abrir, y ahí brincar hacia
  // abajo escondería el menú antes de que la persona lo viera.
  let ultimoToque = 0;
  document.addEventListener('click', () => { ultimoToque = Date.now(); }, true);

  function encabezadoFijo() {
    const h = document.querySelector('header, .header, .hdr, .topbar');
    return h && /fixed|sticky/.test(getComputedStyle(h).position) ? h.offsetHeight : 0;
  }

  // Sólo se desplaza si el contenido quedó por debajo de la mitad de la
  // pantalla: si ya se ve, no hay que mover nada.
  function irAlContenido(panel) {
    if (!visible(panel) || Date.now() - ultimoToque > 1000) return;
    const top = panel.getBoundingClientRect().top;
    if (top < window.innerHeight * 0.5) return;
    window.scrollTo({ top: window.scrollY + top - encabezadoFijo() - 12, behavior: 'smooth' });
  }

  function envolver() {
    Object.entries(PANELES).forEach(([nombre, candidatos]) => {
      const original = window[nombre];
      if (typeof original !== 'function' || original.__avbaMovil) return;
      const envuelta = function (id) {
        const r = original.apply(this, arguments);
        requestAnimationFrame(() => {
          irAlContenido(candidatos(id).map(c => document.getElementById(c)).find(visible));
          document.querySelectorAll(BARRAS).forEach(b => { marcar(b); centrarActiva(b); });
        });
        return r;
      };
      envuelta.__avbaMovil = true;
      window[nombre] = envuelta;
    });
  }

  /* ── 4. Regresar al menú ──
     En el portal del cliente el menú queda muy arriba una vez que se baja al
     contenido. Un botón flotante lleva de vuelta; sólo aparece en teléfono y
     tablet (css/movil.css) y sólo cuando el menú ya no se ve. */
  function botonMenu() {
    const menu = document.querySelector('.tab-cards-grid');
    if (!menu || !('IntersectionObserver' in window)) return;
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'avba-ir-menu';
    b.setAttribute('aria-label', 'Volver al menú');
    b.innerHTML = '<span aria-hidden="true">☰</span> Menú';
    b.addEventListener('click', () => {
      window.scrollTo({ top: Math.max(0, window.scrollY + menu.getBoundingClientRect().top - encabezadoFijo() - 12), behavior: 'smooth' });
    });
    document.body.appendChild(b);
    // El encabezado fijo tapa la franja de arriba: un menú que sólo asoma
    // detrás de él ya no se ve, aunque técnicamente siga en pantalla.
    const tapa = encabezadoFijo() + 20;
    new IntersectionObserver(([e]) => {
      b.classList.toggle('avba-ir-menu--ver', !e.isIntersecting && e.boundingClientRect.top < tapa);
    }, { rootMargin: '-' + tapa + 'px 0px 0px 0px' }).observe(menu);
  }

  function arrancar() {
    prepararBarras();
    envolver();
    botonMenu();
    document.querySelectorAll(BARRAS).forEach(centrarActiva);
    // Barras que se agregan después al documento. Sólo se buscan las nuevas,
    // sin medir nada: esto corre seguido mientras se mueve un mapa.
    let pendiente = false;
    new MutationObserver(() => {
      if (pendiente) return;
      pendiente = true;
      requestAnimationFrame(() => { pendiente = false; prepararBarras(); });
    }).observe(document.body, { childList: true, subtree: true });
    if (!alCambiarTamano) {
      window.addEventListener('resize', () => document.querySelectorAll(BARRAS).forEach(marcar), { passive: true });
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', arrancar);
  else arrancar();
}());
