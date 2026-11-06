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

  /* ── 5. Ir a las acciones de una ficha ──
     En Certificaciones y Calidad las acciones de un registro van al pie de la
     ficha, debajo de toda la información. En teléfono eso queda varias
     pantallas abajo —en Maquinaria empiezan en el pixel 1192 de una pantalla
     de 844— y no hay nada que avise que ahí hay botones: había que ir a
     buscarlos. Este atajo aparece mientras el pie no se ve y lleva a él. */
  function atajoAcciones() {
    const overlays = document.querySelectorAll('.overlay');
    if (!overlays.length) return;
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'avba-ir-acciones';
    b.innerHTML = 'Acciones <span aria-hidden="true">↓</span>';
    document.body.appendChild(b);

    // El pie de la ficha que está hasta arriba. Si encima hay otra ventana —la
    // de confirmar, por ejemplo— ésa manda y el atajo se esconde.
    function pieActual() {
      const abiertos = [...document.querySelectorAll('.overlay.open')];
      const arriba = abiertos[abiertos.length - 1];
      if (!arriba) return null;
      const pie = arriba.querySelector('.modal-footer');
      return pie && visible(pie) && pie.querySelector('button') ? pie : null;
    }

    let pendiente = false;
    function revisar() {
      if (pendiente) return;
      pendiente = true;
      requestAnimationFrame(() => {
        pendiente = false;
        const pie = pieActual();
        const lejos = !!pie && pie.getBoundingClientRect().top > window.innerHeight - 90;
        b.classList.toggle('avba-ir-acciones--ver', lejos);
      });
    }

    b.addEventListener('click', () => {
      const pie = pieActual();
      if (pie) pie.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
    // El scroll del overlay no sube hasta el documento, pero sí se ve en la
    // fase de captura.
    document.addEventListener('scroll', revisar, { capture: true, passive: true });
    window.addEventListener('resize', revisar, { passive: true });
    const obs = new MutationObserver(revisar);
    overlays.forEach(o => obs.observe(o, { attributes: true, attributeFilter: ['class'] }));
  }

  /* ── 6. Ventanas emergentes: saber cuándo hay una abierta ──
     Cada pantalla abre sus ventanas a su manera —clase .open, display por
     estilo, elementos creados al vuelo— y por eso el bloqueo de la página de
     atrás sólo funcionaba con algunas. Aquí se mira lo que de verdad se ve:
     un elemento fijo que cubre casi toda la pantalla y que por nombre es una
     ventana. Mientras haya uno, <html> lleva avba-ventana-abierta
     (css/movil.css, sección 8). */
  const NOMBRE_VENTANA = /overlay|modal|backdrop|^ov-/i;
  const tacto = () => window.matchMedia('(max-width: 1024px), (pointer: coarse)').matches;

  function ventanasAbiertas() {
    const out = [];
    document.querySelectorAll('[id*="overlay"],[id*="modal"],[id^="ov-"],[class*="overlay"],[class*="modal"]').forEach(e => {
      if (e.id && e.id.startsWith('avba-')) return;
      const nombre = (e.id || '') + ' ' + (typeof e.className === 'string' ? e.className : '');
      if (!NOMBRE_VENTANA.test(nombre)) return;
      const s = getComputedStyle(e);
      // pointer-events: none es como Admin, Administrativo y Presupuestos
      // dan por cerrada una ventana: la dejan en display:flex y la apagan
      // con opacidad, que se desvanece en .2 s. Mirando sólo la opacidad,
      // al cerrar todavía se veía "abierta" y la página se quedaba trabada.
      // La opacidad no se mira: al abrir con transición arranca en 0 y la
      // ventana ya está ahí; una invisible que sí recibe toques sería otro
      // defecto, no una ventana cerrada.
      if (s.position !== 'fixed' || s.display === 'none' || s.visibility === 'hidden'
          || s.pointerEvents === 'none') return;
      const r = e.getBoundingClientRect();
      if (r.width < window.innerWidth * 0.85 || r.height < window.innerHeight * 0.85) return;
      out.push(e);
    });
    return out;
  }

  /* El botón de cerrar de cada ventana, agrandado para el dedo. Se busca
     por lo que es —una ×— y no por una clase, porque cada pantalla le puso
     la suya o ninguna. */
  function agrandarCerrar(ventana) {
    if (!tacto()) return;
    ventana.querySelectorAll('button, span[onclick], a[onclick], div[onclick]').forEach(b => {
      if (b.classList.contains('avba-cerrar')) return;
      const t = (b.textContent || '').trim();
      const nombre = (b.className && typeof b.className === 'string' ? b.className : '') + ' ' + (b.id || '');
      if (!/^(×|✕|✖|x)$/i.test(t) && !/(^|[-_ ])(close|cerrar)([-_ ]|$)/i.test(nombre)) return;
      const r = b.getBoundingClientRect();
      if (!r.width || !r.height) return;
      const extra = Math.max(4, Math.ceil((44 - Math.min(r.width, r.height)) / 2));
      b.style.setProperty('--avba-cerrar-extra', extra + 'px');
      b.classList.add('avba-cerrar');
      if (getComputedStyle(b).position === 'static') b.classList.add('avba-cerrar-rel');
    });
  }

  function vigilarVentanas() {
    let pendiente = false;
    const revisar = () => {
      if (pendiente) return;
      pendiente = true;
      requestAnimationFrame(() => {
        pendiente = false;
        const abiertas = ventanasAbiertas();
        document.documentElement.classList.toggle('avba-ventana-abierta', abiertas.length > 0);
        abiertas.forEach(agrandarCerrar);
      });
    };
    new MutationObserver(revisar).observe(document.body, {
      subtree: true, childList: true, attributes: true, attributeFilter: ['class', 'style'],
    });
    window.addEventListener('resize', revisar, { passive: true });
    // Las que se abren o cierran con una transición terminan de cambiar sin
    // tocar ningún atributo: se vuelve a mirar cuando acaba.
    document.addEventListener('transitionend', revisar, true);
    document.addEventListener('animationend', revisar, true);
    revisar();
  }

  function arrancar() {
    prepararBarras();
    envolver();
    botonMenu();
    atajoAcciones();
    vigilarVentanas();
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
