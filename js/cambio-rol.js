/* ══════════════════════════════════════════════════════════════
   AVBA — Cambio de módulo para cuentas con más de un rol

   Una misma persona puede tener asignados varios módulos: el inspector que
   además entra a Calidad y a Certificaciones. La sesión trabaja con UNO a la
   vez —el rol activo— y esto es lo que permite cambiarlo sin volver a entrar.

   No decide permisos: sólo ofrece los módulos que la cuenta ya tiene. El
   servidor vuelve a comprobar cuáles son al cambiar, así que alterar esta
   lista desde el navegador no abre ninguna puerta.

   Se incluye al final de cada página, después de su interceptor de fetch, que
   es quien pone el token en cada petición.
   ══════════════════════════════════════════════════════════════ */
(function () {
  const API = 'api/index.php';

  const DESTINO = {
    ADMIN:           'admin.html',
    INSPECTOR:       'inspector.html',
    CALIDAD:         'calidad.html',
    CERTIFICACIONES: 'certificaciones.html',
    ADMINISTRATIVO:  'administrativo.html',
  };
  const ETIQUETA = {
    ADMIN:           'Administración',
    INSPECTOR:       'Inspector',
    CALIDAD:         'Calidad',
    CERTIFICACIONES: 'Certificaciones',
    ADMINISTRATIVO:  'Administrativo',
  };

  function sesion() {
    try { return JSON.parse(localStorage.getItem('avba_session') || '{}'); }
    catch (e) { return {}; }
  }

  async function pedir(action, payload) {
    const res = await fetch(API, {
      method: 'POST',
      body: JSON.stringify({ action: action, payload: payload || {} }),
    });
    return res.json();
  }

  async function cambiar(rol) {
    const d = await pedir('CAMBIAR_ROL', { rol: rol });
    if ((d.status || '') !== 'success') {
      alert(d.message || 'No se pudo cambiar de módulo.');
      return;
    }
    // El rol guardado se actualiza ANTES de salir: la página de destino lo
    // primero que hace es comprobarlo, y con el anterior rebotaría al login.
    const s = sesion();
    s.rol = d.rol;
    s.roles = d.roles || s.roles;
    localStorage.setItem('avba_session', JSON.stringify(s));
    window.location.href = DESTINO[d.rol] || 'login.html';
  }

  function pintar(actual, roles) {
    const otros = roles.filter(function (r) { return r !== actual && DESTINO[r]; });
    if (!otros.length) return;

    const caja = document.createElement('div');
    caja.id = 'avba-cambio-rol';
    caja.innerHTML =
      '<button type="button" id="avba-rol-btn" aria-haspopup="true" aria-expanded="false">' +
        '<span class="avba-rol-punto"></span>' +
        '<span class="avba-rol-txt">' + (ETIQUETA[actual] || actual) + '</span>' +
        '<span class="avba-rol-flecha">▾</span>' +
      '</button>' +
      '<div id="avba-rol-menu" role="menu">' +
        '<div class="avba-rol-tit">Cambiar de módulo</div>' +
        otros.map(function (r) {
          return '<button type="button" class="avba-rol-op" data-rol="' + r + '" role="menuitem">' +
                 (ETIQUETA[r] || r) + '</button>';
        }).join('') +
        '<div class="avba-rol-pie">Queda registrado con qué módulo trabajas.</div>' +
      '</div>';

    const css = document.createElement('style');
    css.textContent =
      '#avba-cambio-rol{position:fixed;left:14px;bottom:14px;z-index:9999;font-family:inherit}' +
      '#avba-rol-btn{display:flex;align-items:center;gap:7px;padding:8px 13px;border-radius:99px;' +
        'border:1.5px solid #cdd8e3;background:#fff;color:#0B2545;font-size:12px;font-weight:600;' +
        'cursor:pointer;box-shadow:0 2px 10px rgba(11,37,69,.14)}' +
      '#avba-rol-btn:hover{border-color:#185FA5}' +
      '.avba-rol-punto{width:7px;height:7px;border-radius:50%;background:#2e9e5b;flex-shrink:0}' +
      '.avba-rol-flecha{font-size:10px;opacity:.6}' +
      '#avba-rol-menu{display:none;position:absolute;left:0;bottom:46px;min-width:210px;background:#fff;' +
        'border:1.5px solid #cdd8e3;border-radius:11px;box-shadow:0 8px 26px rgba(11,37,69,.2);padding:6px;overflow:hidden}' +
      '#avba-cambio-rol.abierto #avba-rol-menu{display:block}' +
      '.avba-rol-tit{font-size:10px;text-transform:uppercase;letter-spacing:.4px;color:#7a8494;padding:7px 10px 5px}' +
      '.avba-rol-op{display:block;width:100%;text-align:left;padding:9px 10px;border:none;background:none;' +
        'border-radius:7px;font-size:13px;color:#0B2545;cursor:pointer;font-weight:500}' +
      '.avba-rol-op:hover{background:#eef3f9}' +
      '.avba-rol-pie{font-size:10px;color:#7a8494;padding:7px 10px 4px;border-top:1px solid #eef1f5;margin-top:4px;line-height:1.4}' +
      '@media print{#avba-cambio-rol{display:none}}';

    document.head.appendChild(css);
    document.body.appendChild(caja);

    const btn = document.getElementById('avba-rol-btn');
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      const abierto = caja.classList.toggle('abierto');
      btn.setAttribute('aria-expanded', abierto ? 'true' : 'false');
    });
    caja.querySelectorAll('.avba-rol-op').forEach(function (op) {
      op.addEventListener('click', function () { cambiar(op.dataset.rol); });
    });
    document.addEventListener('click', function () {
      caja.classList.remove('abierto');
      btn.setAttribute('aria-expanded', 'false');
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') caja.classList.remove('abierto');
    });
  }

  async function arrancar() {
    if (!sesion().token) return;
    let d;
    // Los roles se piden al servidor y no se leen de la sesión guardada: si
    // Admin acaba de quitarle uno, el menú no debe seguir ofreciéndolo.
    try { d = await pedir('MIS_ROLES'); } catch (e) { return; }
    if ((d.status || '') !== 'success') return;

    const roles = d.roles || [];
    if (roles.length < 2) return;   // una sola cuenta, un solo módulo: nada que mostrar

    // La sesión guardada se pone al día con lo que dice el servidor.
    const s = sesion();
    if (s.rol !== d.rol || !s.roles) {
      s.rol = d.rol; s.roles = roles;
      localStorage.setItem('avba_session', JSON.stringify(s));
    }
    pintar(d.rol, roles);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', arrancar);
  } else {
    arrancar();
  }
}());
