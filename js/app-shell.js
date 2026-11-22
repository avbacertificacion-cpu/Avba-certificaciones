/**
 * AVBA Certificaciones — Capa de la app nativa (Android / iOS)
 * ══════════════════════════════════════════════════════════════════════════
 * La app lleva DENTRO todas sus pantallas (HTML, JS, CSS, imágenes): abre sin
 * internet. Este archivo se carga primero en cada pantalla de la app y hace
 * que el mismo código del portal web funcione en el teléfono, con o sin señal:
 *
 *  1. SERVIDOR: lo que las pantallas piden con rutas relativas al servidor
 *     (api/index.php, uploads/…, descargar.php, geocode.php, tile.php, qr.php)
 *     se manda a https://gestion.avba.com.mx. Los PDF y descargas se abren en
 *     el navegador interno del sistema.
 *
 *  2. CONSULTAS SIN SEÑAL: cada consulta que responde el servidor se guarda en
 *     el teléfono. Sin señal se contesta con la última copia guardada. Con
 *     señal, la app además precarga lo que el inspector necesita en campo
 *     (tipos de equipo, listas de revisión, tipos de accesorio y de arnés,
 *     clientes, cursos, sus inspecciones) para que esté a la mano sin señal.
 *
 *  3. BANDEJA DE SALIDA: lo que el inspector captura en campo (sesiones de
 *     accesorios y de arneses con sus piezas, participantes, recomendaciones…)
 *     se guarda en el teléfono si no hay señal y se envía solo, en el mismo
 *     orden, cuando vuelve. Lo creado sin señal lleva un número provisional
 *     (negativo); al enviarse, la app lo cambia por el número real del
 *     servidor en todo lo que siga. La inspección de maquinaria ya tenía su
 *     propia cola (js/offline-sync.js) y la sigue usando.
 *
 *  4. SESIÓN: sin señal no se cierra la sesión por tiempo; al volver la señal,
 *     si el servidor ya no la reconoce, se pide iniciar sesión otra vez y lo
 *     pendiente se envía después, sin perderse.
 *
 *  5. ACTUALIZACIÓN AUTOMÁTICA: cada despliegue del sitio publica también el
 *     paquete de pantallas de la app (app-paquete/). La app lo descarga sola y
 *     lo aplica la siguiente vez que se abre desde cerrada (o en el inicio de
 *     sesión): nunca a media captura. Si la versión nueva no arranca, el
 *     teléfono regresa solo a la anterior.
 *
 *  Lo que cada módulo hace sin señal se declara en js/app-sin-senal.js; este
 *  archivo no se toca para agregar módulos.
 *
 * En el navegador web (fuera de la app) este archivo no hace nada.
 * ══════════════════════════════════════════════════════════════════════════
 */
(function () {
  'use strict';

  var Cap = window.Capacitor;
  var ES_APP = !!(Cap && typeof Cap.isNativePlatform === 'function' && Cap.isNativePlatform())
            || window.__AVBA_APP_PRUEBA__ === true;          // pruebas automáticas en navegador
  if (!ES_APP || window.AVBAApp) return;

  var SERVIDOR = String(window.__AVBA_SERVIDOR__ || 'https://gestion.avba.com.mx').replace(/\/+$/, '') + '/';
  var TIEMPO_RED = 12000;   // ms: sin respuesta en este tiempo, se usa la copia guardada

  function plugin(n) { return (Cap && Cap.Plugins && Cap.Plugins[n]) || null; }

  /* Qué trae este paquete de pantallas (lo escribe mobile/scripts/build-www.mjs
     en js/app-paquete.js): versión y lista de archivos. */
  var PAQ = window.__AVBA_PAQUETE__ || {};
  var ARCHIVOS = null;
  if (PAQ.archivos && PAQ.archivos.length) {
    ARCHIVOS = {};
    PAQ.archivos.forEach(function (a) { ARCHIVOS[a] = 1; });
  }

  /* Reglas de cada módulo sin señal (js/app-sin-senal.js) */
  var REGLAS = window.AVBA_SIN_SENAL || {};
  function lista(v) { return Array.isArray(v) ? v : []; }

  /* ── 1. Rutas al servidor ────────────────────────────────────────────── */
  /* Va al servidor: la API, lo subido, cualquier .php (la app no trae PHP) y
     cualquier archivo que no venga en el paquete —por ejemplo, la imagen de un
     módulo nuevo que este teléfono todavía no descarga—. */
  function vaAlServidor(ruta) {
    var limpia = ruta.split(/[?#]/)[0];
    if (/^(api|uploads)\//i.test(limpia) || /\.php$/i.test(limpia)) return true;
    if (!ARCHIVOS || !limpia || limpia.slice(-1) === '/') return false;
    try { limpia = decodeURIComponent(limpia); } catch (e) {}
    return !ARCHIVOS[limpia];
  }
  function aServidor(url) {
    if (typeof url !== 'string' || !url) return null;
    if (url.indexOf(SERVIDOR) === 0) return url;
    var origen = location.origin && location.origin !== 'null' ? location.origin + '/' : '';
    if (origen && url.indexOf(origen) === 0) url = url.slice(origen.length);
    if (/^[a-z][a-z0-9+.-]*:/i.test(url) || url.indexOf('//') === 0) return null;   // otro sitio o data:
    if (url.charAt(0) === '#' || url.charAt(0) === '?') return null;                  // la misma pantalla
    var ruta = url.replace(/^\.?\//, '');
    return vaAlServidor(ruta) ? SERVIDOR + ruta : null;
  }
  function esApi(url) { return url.indexOf(SERVIDOR + 'api/index.php') === 0; }

  /* ── Sesión ──────────────────────────────────────────────────────────── */
  function sesion() {
    try { return JSON.parse(localStorage.getItem('avba_session') || '{}') || {}; } catch (e) { return {}; }
  }

  /* ── Conexión ────────────────────────────────────────────────────────── */
  var enLinea = navigator.onLine !== false;
  var oyentes = [];
  function cambiarConexion(v) {
    if (v === enLinea) return;
    enLinea = v;
    pintarAvisos();
    oyentes.forEach(function (f) { try { f(v); } catch (e) {} });
    if (v) { procesarSalida(); precargar(false); setTimeout(function () { revisarActualizacion(false); }, 5000); }
  }
  window.addEventListener('online',  function () { cambiarConexion(true); });
  window.addEventListener('offline', function () { cambiarConexion(false); });
  var tipoRed = '';                      // wifi | cellular | … (para no gastar datos de más)
  var Net = plugin('Network');
  if (Net) {
    try {
      Net.getStatus().then(function (s) { tipoRed = s.connectionType || ''; cambiarConexion(!!s.connected); });
      Net.addListener('networkStatusChange', function (s) { tipoRed = s.connectionType || ''; cambiarConexion(!!s.connected); });
    } catch (e) {}
  }

  /* Sin señal la sesión no vence por tiempo: las pantallas cierran la sesión a
     las 8 h (ts), y en campo eso dejaría al inspector fuera sin poder volver a
     entrar. Se renueva la marca de tiempo; el servidor sigue siendo quien
     decide cuando hay señal (responde 401 y se pide entrar otra vez). */
  function renovarSesionSinSenal() {
    if (enLinea) return;
    try {
      var s = sesion();
      if (s && s.token) { s.ts = Date.now(); localStorage.setItem('avba_session', JSON.stringify(s)); }
    } catch (e) {}
  }
  renovarSesionSinSenal();

  /* El service worker del sitio web no hace falta en la app: las pantallas ya
     están dentro del teléfono, y uno viejo podría servir versiones atrasadas. */
  try {
    if (navigator.serviceWorker) {
      navigator.serviceWorker.register = function () { return Promise.resolve({ scope: '/' }); };
    }
  } catch (e) {}

  /* ── Base de datos local (IndexedDB) ─────────────────────────────────── */
  var DB_NOMBRE = 'avba_app';
  var dbProm = null;
  function db() {
    if (dbProm) return dbProm;
    dbProm = new Promise(function (ok, mal) {
      var r = indexedDB.open(DB_NOMBRE, 1);
      r.onupgradeneeded = function () {
        var d = r.result;
        if (!d.objectStoreNames.contains('cache'))  d.createObjectStore('cache',  { keyPath: 'clave' });
        if (!d.objectStoreNames.contains('salida')) d.createObjectStore('salida', { keyPath: 'n', autoIncrement: true });
        if (!d.objectStoreNames.contains('mapa'))   d.createObjectStore('mapa',   { keyPath: 'local' });
      };
      r.onsuccess = function () { ok(r.result); };
      r.onerror   = function () { mal(r.error); };
    });
    return dbProm;
  }
  function tx(st, modo, fn) {
    return db().then(function (d) {
      return new Promise(function (ok, mal) {
        var t = d.transaction(st, modo), s = t.objectStore(st), res;
        var r = fn(s);
        if (r) r.onsuccess = function () { res = r.result; };
        t.oncomplete = function () { ok(res); };
        t.onerror = t.onabort = function () { mal(t.error); };
      });
    });
  }

  /* ── 2. Copia local de las consultas ─────────────────────────────────── */
  var PARAM_FUERA = { token: 1, _: 1, t: 1, v: 1 };
  function claveConsulta(url, cuerpoLectura) {
    var u;
    try { u = new URL(url); } catch (e) { return null; }
    var pares = [];
    u.searchParams.forEach(function (v, k) { if (!PARAM_FUERA[k]) pares.push(k + '=' + v); });
    pares.sort();
    var s = sesion();
    return (s.usuario || '-') + '|' + u.pathname + '?' + pares.join('&') + (cuerpoLectura ? '|' + cuerpoLectura : '');
  }
  function guardarCopia(clave, texto, tipo) {
    if (!clave) return Promise.resolve();
    return tx('cache', 'readwrite', function (s) {
      return s.put({ clave: clave, texto: texto, tipo: tipo || 'application/json', fecha: Date.now() });
    }).catch(function () {});
  }
  function leerCopia(clave) {
    if (!clave) return Promise.resolve(null);
    return tx('cache', 'readonly', function (s) { return s.get(clave); }).catch(function () { return null; });
  }
  function respuestaDe(texto, tipo, extra) {
    var h = { 'Content-Type': tipo || 'application/json' };
    if (extra) for (var k in extra) h[k] = extra[k];
    return new Response(texto, { status: 200, headers: h });
  }
  function respuestaJson(obj, extra) { return respuestaDe(JSON.stringify(obj), 'application/json', extra); }

  /* Lecturas que se mandan por POST (el portal pide así algunas consultas) */
  var LEC_PREFIJOS = lista(REGLAS.lecturas && REGLAS.lecturas.prefijos);
  if (!LEC_PREFIJOS.length) LEC_PREFIJOS = ['GET_', 'LISTAR_', 'DETALLE_', 'BUSCAR_', 'OBTENER_', 'SUGERENCIAS_'];
  var LEC_ACCIONES = lista(REGLAS.lecturas && REGLAS.lecturas.acciones);
  function esLectura(accion) {
    accion = String(accion || '');
    for (var i = 0; i < LEC_PREFIJOS.length; i++) if (accion.indexOf(LEC_PREFIJOS[i]) === 0) return true;
    return LEC_ACCIONES.indexOf(accion) >= 0;
  }

  /* Búsqueda de clientes sin señal: sobre la lista completa precargada */
  function buscarClienteLocal(url) {
    var q = '';
    try { q = (new URL(url).searchParams.get('q') || '').trim(); } catch (e) {}
    var norma = function (t) { return String(t || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toUpperCase(); };
    return leerCopia(claveConsulta(SERVIDOR + 'api/index.php?action=CLIENTES_OFFLINE')).then(function (c) {
      var lista = [];
      try { lista = (JSON.parse(c ? c.texto : '{}').clientes) || []; } catch (e) {}
      var nq = norma(q);
      var hay = q.length < 2 ? [] : lista.filter(function (x) { return norma(x.nombre_cliente).indexOf(nq) >= 0; }).slice(0, 15);
      return respuestaJson({ status: 'success', clientes: hay }, { 'X-AVBA-Sin-Senal': '1' });
    });
  }

  /* ── 3. Bandeja de salida ────────────────────────────────────────────── */
  /* Qué devuelve el servidor al crear algo: de ahí sale el número real que
     sustituye al provisional. */
  var CREA = {};
  var creaReglas = (REGLAS.bandeja && REGLAS.bandeja.crea) || {};
  for (var kCrea in creaReglas) if (Object.prototype.hasOwnProperty.call(creaReglas, kCrea)) CREA[kCrea] = creaReglas[kCrea];
  var SIN_NUMERO = lista(REGLAS.bandeja && REGLAS.bandeja.sinNumero);
  function vaABandeja(accion) {
    return CREA.hasOwnProperty(accion) || SIN_NUMERO.indexOf(accion) >= 0 || accion === 'DUPLICAR_ACCESORIO';
  }

  var MAPA = {};                         // provisional → real (en memoria; persiste en 'mapa')
  var pendientes = { total: 0, errores: 0, provisionales: {} };
  function cargarEstado() {
    return Promise.all([
      tx('mapa', 'readonly', function (s) { return s.getAll(); }),
      tx('salida', 'readonly', function (s) { return s.getAll(); }),
    ]).then(function (r) {
      (r[0] || []).forEach(function (m) { MAPA[m.local] = m.real; });
      var items = r[1] || [];
      pendientes.total = items.length;
      pendientes.errores = items.filter(function (i) { return i.estado !== 'pendiente'; }).length;
      pendientes.provisionales = {};
      items.forEach(function (i) { (i.provisionales || []).forEach(function (p) { pendientes.provisionales[p] = 1; }); });
      pintarAvisos();
    }).catch(function () {});
  }

  function nuevoProvisional() {
    var n;
    do { n = -(1000000000 + Math.floor(Math.random() * 999999999)); } while (MAPA[n] || pendientes.provisionales[n]);
    return n;
  }

  /* Reemplaza los números provisionales ya enviados por los reales, y dice si
     queda alguno todavía sin enviar. */
  function esProvisional(v) { var n = Number(v); return isFinite(n) && n <= -1000000000 && String(v).trim() !== ''; }
  function mapearValor(v, info) {
    if ((typeof v === 'number' || typeof v === 'string') && esProvisional(v)) {
      var real = MAPA[Number(v)];
      if (real !== undefined) return typeof v === 'number' ? Number(real) : String(real);
      info.sinEnviar = true;
    }
    return v;
  }
  function mapearJson(o, info) {
    if (Array.isArray(o)) return o.map(function (x) { return mapearJson(x, info); });
    if (o && typeof o === 'object') {
      var r = {};
      for (var k in o) if (Object.prototype.hasOwnProperty.call(o, k)) r[k] = mapearJson(o[k], info);
      return r;
    }
    return mapearValor(o, info);
  }
  function mapearUrl(url, info) {
    return url.replace(/([?&][^=&#]+=)(-\d{10,})/g, function (m, pre, num) { return pre + mapearValor(num, info); });
  }

  /* Cuerpo de una petición, serializable para guardarlo en el teléfono */
  function leerCuerpo(body) {
    if (body == null) return Promise.resolve({ tipo: 'nada' });
    if (typeof body === 'string') {
      try { return Promise.resolve({ tipo: 'json', datos: JSON.parse(body) }); }
      catch (e) { return Promise.resolve({ tipo: 'texto', datos: body }); }
    }
    if (typeof FormData !== 'undefined' && body instanceof FormData) {
      var campos = [], esperas = [];
      body.forEach(function (v, k) {
        if (typeof v === 'string') { campos.push({ k: k, v: v }); return; }
        var c = { k: k, archivo: true, nombre: v.name || 'archivo', mime: v.type || 'application/octet-stream' };
        campos.push(c);
        esperas.push(v.arrayBuffer().then(function (ab) { c.datos = ab; }));
      });
      return Promise.all(esperas).then(function () { return { tipo: 'form', datos: campos }; });
    }
    return Promise.resolve({ tipo: 'nada' });
  }
  function accionDe(url, cuerpo) {
    try { var a = new URL(url).searchParams.get('action'); if (a) return a; } catch (e) {}
    if (cuerpo && cuerpo.tipo === 'json' && cuerpo.datos) return String(cuerpo.datos.action || '');
    if (cuerpo && cuerpo.tipo === 'form') {
      for (var i = 0; i < cuerpo.datos.length; i++) if (cuerpo.datos[i].k === 'action') return String(cuerpo.datos[i].v || '');
    }
    return '';
  }
  function payloadDe(cuerpo) {
    if (cuerpo.tipo === 'json') return (cuerpo.datos && (cuerpo.datos.payload || cuerpo.datos)) || {};
    if (cuerpo.tipo === 'form') { var p = {}; cuerpo.datos.forEach(function (c) { if (!c.archivo) p[c.k] = c.v; }); return p; }
    return {};
  }
  function mapearCuerpo(cuerpo, info) {
    if (cuerpo.tipo === 'json') return { tipo: 'json', datos: mapearJson(cuerpo.datos, info) };
    if (cuerpo.tipo === 'form') {
      return { tipo: 'form', datos: cuerpo.datos.map(function (c) {
        return c.archivo ? c : { k: c.k, v: String(mapearValor(c.v, info)) };
      }) };
    }
    return cuerpo;
  }
  /* El cuerpo listo para enviar, con el token de la sesión de AHORA (lo guardado
     pudo capturarse con un token que ya venció). */
  function armarCuerpo(cuerpo) {
    var tk = sesion().token || '';
    if (cuerpo.tipo === 'json') { var d = cuerpo.datos || {}; if (d && typeof d === 'object' && 'token' in d) d.token = tk; return JSON.stringify(d); }
    if (cuerpo.tipo === 'texto') return cuerpo.datos;
    if (cuerpo.tipo === 'form') {
      var fd = new FormData();
      cuerpo.datos.forEach(function (c) {
        if (c.archivo) fd.append(c.k, new Blob([c.datos], { type: c.mime }), c.nombre);
        else fd.append(c.k, c.k === 'token' ? tk : c.v);
      });
      return fd;
    }
    return undefined;
  }
  function cabeceras(init, cuerpo) {
    var h = {};
    var orig = init && init.headers;
    if (orig) {
      if (typeof Headers !== 'undefined' && orig instanceof Headers) orig.forEach(function (v, k) { h[k] = v; });
      else for (var k in orig) h[k] = orig[k];
    }
    if (cuerpo && cuerpo.tipo === 'form') { delete h['Content-Type']; delete h['content-type']; }
    var tk = sesion().token || '';
    if (tk) { h['Authorization'] = 'Bearer ' + tk; h['X-Token'] = tk; }
    return h;
  }

  /* Respuesta que reciben las pantallas cuando lo capturado se queda en el teléfono */
  function respuestaGuardada(accion, payload, provisional, copias) {
    var r = { status: 'success', sin_senal: true,
              message: 'Guardado en el teléfono: se enviará solo en cuanto haya señal.' };
    if (CREA[accion]) r[CREA[accion]] = provisional;
    if (accion === 'CREAR_SESION_ACCESORIOS') r.control = '';
    if (accion === 'GUARDAR_ACCESORIO') r.qr_codigo = payload.qr_codigo || '';
    if (accion === 'DUPLICAR_ACCESORIO') {
      var series = payload.series || [], qrs = payload.qrs || [], ids = payload.ids || [];
      r.copias = copias.map(function (p, i) { return { id: p, serie: series[i] || '', qr: qrs[i] || '', id_accesorio: ids[i] || '' }; });
      r.message = copias.length + ' copia' + (copias.length === 1 ? '' : 's') + ' guardada' + (copias.length === 1 ? '' : 's')
                + ' en el teléfono: se enviarán solas en cuanto haya señal.';
    }
    return r;
  }

  function aBandeja(url, init, cuerpo, accion) {
    var payload = payloadDe(cuerpo);
    var provisionales = [], copias = [];
    if (CREA[accion]) provisionales.push(nuevoProvisional());
    if (accion === 'DUPLICAR_ACCESORIO') {
      var n = Math.max(0, parseInt(payload.copias, 10) || 0);
      for (var i = 0; i < n; i++) { var p = nuevoProvisional(); copias.push(p); provisionales.push(p); }
    }
    var item = { url: url, metodo: 'POST', cuerpo: cuerpo, cabeceras: cabeceras(init, cuerpo), accion: accion,
                 provisionales: provisionales, copias: copias, estado: 'pendiente', creado: Date.now(), intentos: 0 };
    return tx('salida', 'readwrite', function (s) { return s.add(item); }).then(function () {
      provisionales.forEach(function (p) { pendientes.provisionales[p] = 1; });
      pendientes.total++;
      pintarAvisos();
      if (enLinea) setTimeout(procesarSalida, 50);
      return respuestaJson(respuestaGuardada(accion, payload, provisionales[0], copias), { 'X-AVBA-Sin-Senal': '1' });
    });
  }

  var procesando = false;
  function procesarSalida() {
    if (procesando || !enLinea) return Promise.resolve();
    procesando = true;
    var enviados = 0;
    return tx('salida', 'readonly', function (s) { return s.getAll(); }).then(function (items) {
      items = (items || []).sort(function (a, b) { return a.n - b.n; });
      var cadena = Promise.resolve(true);
      items.forEach(function (it) {
        cadena = cadena.then(function (seguir) {
          if (!seguir || it.estado !== 'pendiente') return seguir;
          var info = {};
          var url = mapearUrl(it.url, info), cuerpo = mapearCuerpo(it.cuerpo, info);
          if (info.sinEnviar) {       // depende de algo que no se pudo enviar
            it.estado = 'bloqueado'; it.error = 'Depende de un registro anterior que no se pudo enviar.';
            return tx('salida', 'readwrite', function (s) { return s.put(it); }).then(function () { return true; });
          }
          var cab = it.cabeceras || {};
          var tk = sesion().token || '';
          if (tk) { cab.Authorization = 'Bearer ' + tk; cab['X-Token'] = tk; }
          if (cuerpo.tipo === 'form') { delete cab['Content-Type']; delete cab['content-type']; }
          return _fetch(url, { method: 'POST', headers: cab, body: armarCuerpo(cuerpo) }).then(function (resp) {
            if (resp.status === 401) { avisoSesion(); return false; }   // se queda; se envía al volver a entrar
            return resp.json().catch(function () { return null; }).then(function (data) {
              if (data && data.status === 'success') {
                var nuevos = [];
                if (CREA[it.accion] && it.provisionales[0] !== undefined && data[CREA[it.accion]] !== undefined) {
                  nuevos.push({ local: it.provisionales[0], real: data[CREA[it.accion]] });
                }
                if (it.accion === 'DUPLICAR_ACCESORIO' && Array.isArray(data.copias)) {
                  it.copias.forEach(function (p, i) { if (data.copias[i] && data.copias[i].id !== undefined) nuevos.push({ local: p, real: data.copias[i].id }); });
                }
                nuevos.forEach(function (m) { MAPA[m.local] = m.real; });
                enviados++;
                return Promise.all(nuevos.map(function (m) { return tx('mapa', 'readwrite', function (s) { return s.put(m); }); }))
                  .then(function () { return tx('salida', 'readwrite', function (s) { return s.delete(it.n); }); })
                  .then(function () { return true; });
              }
              it.estado = 'error'; it.intentos++;
              it.error = (data && data.message) || ('El servidor respondió ' + resp.status + '.');
              return tx('salida', 'readwrite', function (s) { return s.put(it); }).then(function () { return true; });
            });
          }, function () { return false; });   // sin red: se detiene y se reintenta después
        });
      });
      return cadena;
    }).catch(function () {}).then(function () {
      procesando = false;
      return cargarEstado().then(function () {
        if (enviados) {
          aviso(enviados + (enviados === 1 ? ' registro enviado' : ' registros enviados') + ' al servidor.', 'ok');
          oyentes.forEach(function (f) { try { f(true, 'enviados'); } catch (e) {} });
        }
      });
    });
  }

  /* ── fetch ───────────────────────────────────────────────────────────── */
  var _fetch = window.fetch.bind(window);
  function conTiempo(p, ms) {
    return new Promise(function (ok, mal) {
      var t = setTimeout(function () { mal(new TypeError('Tiempo de espera agotado')); }, ms);
      p.then(function (v) { clearTimeout(t); ok(v); }, function (e) { clearTimeout(t); mal(e); });
    });
  }
  function sinConexion() { return Promise.reject(new TypeError('Sin conexión a internet')); }

  window.fetch = function (input, init) {
    var url0 = typeof input === 'string' ? input : (input && input.url) || '';
    var url = aServidor(url0);
    if (!url) return _fetch(input, init);
    init = init || {};
    var metodo = String(init.method || (typeof input !== 'string' && input && input.method) || 'GET').toUpperCase();
    if (!esApi(url)) return _fetch(url, init);   // archivos, geocodificación, mapas: directo al servidor

    if (metodo === 'GET') {
      var info = {};
      url = mapearUrl(url, info);
      var accionG = accionDe(url, null);
      if (info.sinEnviar) return Promise.resolve(respuestaJson({ status: 'success', data: [], sin_senal: true }));
      var clave = claveConsulta(url);
      var deCopia = function () {
        if (accionG === 'BUSCAR_CLIENTE') {
          return leerCopia(clave).then(function (c) { return c ? respuestaDe(c.texto, c.tipo, { 'X-AVBA-Sin-Senal': '1' }) : buscarClienteLocal(url); });
        }
        return leerCopia(clave).then(function (c) { return c ? respuestaDe(c.texto, c.tipo, { 'X-AVBA-Sin-Senal': '1' }) : sinConexion(); });
      };
      if (!enLinea) return deCopia();
      return conTiempo(_fetch(url, init), TIEMPO_RED).then(function (resp) {
        if (resp.status === 401) return resp;
        if (resp.ok) {
          resp.clone().text().then(function (t) { guardarCopia(clave, t, resp.headers.get('Content-Type')); });
        }
        return resp;
      }, function () { return deCopia(); });
    }

    // POST: lectura (se guarda copia) o escritura (bandeja de salida si aplica)
    return leerCuerpo(init.body).then(function (cuerpo) {
      var accion = accionDe(url, cuerpo);
      var info = {};
      var url2 = mapearUrl(url, info), cuerpo2 = mapearCuerpo(cuerpo, info);

      if (esLectura(accion)) {
        var p = payloadDe(cuerpo2), copia = {};
        for (var k in p) if (k !== 'token') copia[k] = p[k];
        var clave = claveConsulta(url2 + (url2.indexOf('?') >= 0 ? '&' : '?') + 'action=' + accion, JSON.stringify(copia));
        var deCopia = function () { return leerCopia(clave).then(function (c) { return c ? respuestaDe(c.texto, c.tipo, { 'X-AVBA-Sin-Senal': '1' }) : sinConexion(); }); };
        if (info.sinEnviar) return respuestaJson({ status: 'success', data: [], sin_senal: true });
        if (!enLinea) return deCopia();
        return conTiempo(_fetch(url2, Object.assign({}, init, { body: armarCuerpo(cuerpo2), headers: cabeceras(init, cuerpo2) })), TIEMPO_RED)
          .then(function (resp) {
            if (resp.ok) resp.clone().text().then(function (t) { guardarCopia(clave, t, resp.headers.get('Content-Type')); });
            return resp;
          }, function () { return deCopia(); });
      }

      if (vaABandeja(accion)) {
        // Si hay algo esperando, lo nuevo va detrás: el orden importa (una
        // pieza no puede llegar después de cerrar su sesión).
        if (!enLinea || info.sinEnviar || pendientes.total > 0) return aBandeja(url, init, cuerpo, accion);
        return _fetch(url2, Object.assign({}, init, { body: armarCuerpo(cuerpo2), headers: cabeceras(init, cuerpo2) }))
          .catch(function () { return aBandeja(url, init, cuerpo, accion); });
      }

      if (info.sinEnviar) {
        return respuestaJson({ status: 'error', sin_senal: true,
          message: 'Este registro todavía está en el teléfono: espera a que se envíe para hacer este cambio.' });
      }
      if (!enLinea) return sinConexion();
      return _fetch(url2, Object.assign({}, init, { body: armarCuerpo(cuerpo2), headers: cabeceras(init, cuerpo2) }));
    });
  };

  /* XMLHttpRequest (lo usan algunas librerías): sólo la ruta al servidor */
  var _open = XMLHttpRequest.prototype.open;
  XMLHttpRequest.prototype.open = function (m, url) {
    var a = aServidor(url);
    var args = Array.prototype.slice.call(arguments);
    if (a) args[1] = a;
    return _open.apply(this, args);
  };

  /* ── Archivos del servidor en la pantalla (fotos, firmas, mapas, QR) ──── */
  function corregirAtributo(el, attr) {
    var v = el.getAttribute && el.getAttribute(attr);
    var a = v && aServidor(v);
    if (a && a !== v) el.setAttribute(attr, a);   // sólo si cambia: si no, el observador se llamaría sin fin
  }
  function corregirArbol(nodo) {
    if (!nodo || nodo.nodeType !== 1) return;
    if (/^(IMG|IFRAME|SOURCE|EMBED|VIDEO|AUDIO)$/.test(nodo.tagName)) corregirAtributo(nodo, 'src');
    if (nodo.tagName === 'OBJECT') corregirAtributo(nodo, 'data');
    var hijos = nodo.querySelectorAll ? nodo.querySelectorAll('img[src],iframe[src],source[src],embed[src],video[src],audio[src],object[data]') : [];
    for (var i = 0; i < hijos.length; i++) corregirAtributo(hijos[i], hijos[i].tagName === 'OBJECT' ? 'data' : 'src');
  }
  [['HTMLImageElement', 'src'], ['HTMLIFrameElement', 'src'], ['HTMLSourceElement', 'src']].forEach(function (p) {
    var C = window[p[0]];
    var d = C && Object.getOwnPropertyDescriptor(C.prototype, p[1]);
    if (!d || !d.set) return;
    Object.defineProperty(C.prototype, p[1], {
      configurable: true, enumerable: d.enumerable, get: d.get,
      set: function (v) { var a = aServidor(String(v)); d.set.call(this, a || v); },
    });
  });
  new MutationObserver(function (lista) {
    lista.forEach(function (m) {
      if (m.type === 'attributes') corregirAtributo(m.target, m.attributeName);
      else for (var i = 0; i < m.addedNodes.length; i++) corregirArbol(m.addedNodes[i]);
    });
  }).observe(document.documentElement, { subtree: true, childList: true, attributes: true, attributeFilter: ['src', 'data'] });

  /* ── PDF, descargas y enlaces externos: en el navegador interno ──────── */
  function abrirExterno(url) {
    var B = plugin('Browser');
    if (B && B.open) return B.open({ url: url, presentationStyle: 'fullscreen' }).catch(function () { window.location.href = url; });
    try { window.__AVBA_ABIERTO__ = url; } catch (e) {}
    return Promise.resolve();
  }
  function abrir(url) {
    var a = aServidor(url) || (/^https?:\/\//i.test(url) ? url : null);
    if (a) { abrirExterno(a); return true; }
    if (url) { window.location.href = url; return true; }   // otra pantalla de la app: en la misma vista
    return false;
  }
  var _wopen = window.open;
  window.open = function (url) {
    if (url && abrir(String(url))) return ventanaFalsa();
    if (!url) return ventanaFalsa();     // se abre vacía y luego se le pone la dirección
    return _wopen.apply(window, arguments);
  };
  function ventanaFalsa() {
    var loc = {};
    Object.defineProperty(loc, 'href', { set: function (v) { abrir(String(v)); }, get: function () { return 'about:blank'; } });
    loc.assign = loc.replace = function (v) { abrir(String(v)); };
    var w = { closed: false, close: function () { w.closed = true; }, focus: function () {}, document: { write: function () {}, close: function () {} } };
    Object.defineProperty(w, 'location', { get: function () { return loc; }, set: function (v) { abrir(String(v)); } });
    return w;
  }
  document.addEventListener('click', function (e) {
    var a = e.target && e.target.closest && e.target.closest('a[href]');
    if (!a) return;
    var href = a.getAttribute('href') || '';
    if (/^(#|javascript:|mailto:|tel:)/i.test(href)) return;
    var srv = aServidor(href) || (/^https?:\/\//i.test(href) ? href : null);
    if (srv || a.target === '_blank' || a.hasAttribute('download')) {
      e.preventDefault();
      abrir(srv || href);
    }
  }, true);

  /* ── 4. Precarga para trabajar sin señal ─────────────────────────────── */
  var PRECARGA_CADA = 4 * 3600 * 1000;
  function precargar(forzar) {
    var s = sesion();
    if (!enLinea || !s.token) return Promise.resolve();
    var roles = [s.rol].concat(s.roles || []);
    var rolesPre = lista(REGLAS.precarga && REGLAS.precarga.roles);
    if (!roles.some(function (r) { return rolesPre.indexOf(r) >= 0; })) return Promise.resolve();
    var marca = 'avba_precarga_' + (s.usuario || '');
    var ultima = +(localStorage.getItem(marca) || 0);
    if (!forzar && Date.now() - ultima < PRECARGA_CADA) return Promise.resolve();
    localStorage.setItem(marca, String(Date.now()));
    var api = 'api/index.php?action=';
    var tk = '&token=' + encodeURIComponent(s.token);
    var h = { headers: { 'X-Token': s.token } };
    var json = function (u) { return window.fetch(u, h).then(function (r) { return r.json(); }).catch(function () { return null; }); };
    var serie = function (urls) {
      return urls.reduce(function (p, u) { return p.then(function () { return json(u); }); }, Promise.resolve());
    };
    return serie(lista(REGLAS.precarga && REGLAS.precarga.consultas).map(function (a) { return api + a; }))
      .then(function () { return json(api + 'getMaquinaria'); })
      .then(function (tipos) {
        return serie((Array.isArray(tipos) ? tipos : []).map(function (t) { return api + 'getChecklist&equipo=' + encodeURIComponent(t.nombre); }));
      })
      .then(function () { return json(api + 'LISTAR_TIPOS_ACCESORIO'); })
      .then(function (r) {
        var tipos = (r && (r.data || r.tipos)) || (Array.isArray(r) ? r : []);
        return serie(tipos.map(function (t) { return api + 'SUGERENCIAS_ACC&tipo_id=' + encodeURIComponent(t.id) + tk; }));
      })
      .then(function () { return json(api + 'LISTAR_TIPOS_ARNES' + tk); })
      .then(function (r) {
        var tipos = (r && (r.data || r.tipos)) || (Array.isArray(r) ? r : []);
        return serie(tipos.reduce(function (l, t) {
          return l.concat([api + 'LISTAR_CHECKLIST_ARNES&tipo_id=' + encodeURIComponent(t.id) + tk,
                           api + 'SUGERENCIAS_ARNES&tipo_id=' + encodeURIComponent(t.id) + tk]);
        }, []));
      })
      .catch(function () {});
  }

  /* ── Avisos en pantalla ──────────────────────────────────────────────── */
  var estilos = '#avba-app-red{position:fixed;left:0;right:0;top:0;z-index:2147483000;background:#7a4b00;color:#fff;'
    + 'font:600 12.5px/1.35 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;padding:calc(6px + env(safe-area-inset-top)) 12px 6px;text-align:center;display:none}'
    + '#avba-app-salida{position:fixed;right:12px;bottom:calc(12px + env(safe-area-inset-bottom));z-index:2147483000;display:none;'
    + 'background:#0B2545;color:#fff;border:0;border-radius:999px;padding:9px 14px;font:700 12.5px system-ui,-apple-system,Segoe UI,Roboto,sans-serif;'
    + 'box-shadow:0 4px 14px rgba(0,0,0,.25)}#avba-app-salida.err{background:#a32d2d}'
    + '#avba-app-toast{position:fixed;left:50%;transform:translateX(-50%);bottom:calc(64px + env(safe-area-inset-bottom));z-index:2147483001;'
    + 'background:#1e6b34;color:#fff;border-radius:10px;padding:9px 14px;font:600 12.5px system-ui,-apple-system,sans-serif;display:none;max-width:88vw;text-align:center}'
    + '#avba-app-panel{position:fixed;inset:0;z-index:2147483002;background:rgba(0,0,0,.45);display:none;align-items:flex-end;justify-content:center}'
    + '#avba-app-panel .p{background:#fff;color:#1f2d3d;width:100%;max-width:560px;max-height:80vh;overflow:auto;border-radius:16px 16px 0 0;'
    + 'padding:16px 16px calc(16px + env(safe-area-inset-bottom));font:14px/1.45 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}'
    + '#avba-app-panel h3{font-size:16px;margin:0 0 8px}#avba-app-panel .i{border:1px solid #dfe6ee;border-radius:10px;padding:8px 10px;margin:6px 0;font-size:13px}'
    + '#avba-app-panel .e{color:#a32d2d;font-size:12px;margin-top:3px}#avba-app-panel button{border:0;border-radius:8px;padding:9px 14px;font-weight:700;margin:8px 6px 0 0}';
  var nodos = {};
  function prepararNodos() {
    if (nodos.red || !document.body) return;
    var st = document.createElement('style'); st.textContent = estilos; document.head.appendChild(st);
    nodos.red = document.createElement('div'); nodos.red.id = 'avba-app-red';
    nodos.red.textContent = 'Sin conexión: puedes seguir trabajando; lo capturado se guarda en el teléfono.';
    nodos.salida = document.createElement('button'); nodos.salida.id = 'avba-app-salida'; nodos.salida.type = 'button';
    nodos.salida.onclick = abrirPanel;
    nodos.toast = document.createElement('div'); nodos.toast.id = 'avba-app-toast';
    nodos.panel = document.createElement('div'); nodos.panel.id = 'avba-app-panel';
    nodos.panel.onclick = function (e) { if (e.target === nodos.panel) nodos.panel.style.display = 'none'; };
    document.body.appendChild(nodos.red); document.body.appendChild(nodos.salida);
    document.body.appendChild(nodos.toast); document.body.appendChild(nodos.panel);
  }
  function pintarAvisos() {
    prepararNodos();
    if (!nodos.red) return;
    nodos.red.style.display = enLinea ? 'none' : 'block';
    var n = pendientes.total;
    nodos.salida.style.display = n ? 'block' : 'none';
    nodos.salida.className = pendientes.errores ? 'err' : '';
    nodos.salida.textContent = pendientes.errores
      ? '⚠ ' + pendientes.errores + ' con error · ' + n + ' en el teléfono'
      : '⇅ ' + n + (n === 1 ? ' registro por enviar' : ' registros por enviar');
  }
  var tToast = null;
  function aviso(txt, tipo) {
    prepararNodos();
    if (!nodos.toast) return;
    nodos.toast.textContent = txt;
    nodos.toast.style.background = tipo === 'err' ? '#a32d2d' : '#1e6b34';
    nodos.toast.style.display = 'block';
    clearTimeout(tToast); tToast = setTimeout(function () { nodos.toast.style.display = 'none'; }, 4200);
  }
  var avisadoSesion = false;
  function avisoSesion() {
    if (avisadoSesion) return; avisadoSesion = true;
    aviso('Tu sesión venció: entra de nuevo para enviar lo que está en el teléfono. No se pierde nada.', 'err');
  }
  var NOMBRES = {
    CREAR_SESION_ACCESORIOS: 'Sesión de accesorios', COMPLETAR_SESION_ACC: 'Datos de la sesión de accesorios',
    GUARDAR_ACCESORIO: 'Accesorio', DUPLICAR_ACCESORIO: 'Copias de accesorio', ENVIAR_SESION_ACC: 'Envío de la sesión a Calidad',
    CREAR_SESION_ARNESES: 'Sesión de arneses', GUARDAR_ITEM_ARNES: 'Pieza de arnés', GUARDAR_PARTICIPANTE: 'Participante',
    SESION_AGREGAR_EMPRESA: 'Empresa de la sesión', CERRAR_SESION_ACCESO: 'Cierre de la sesión del curso',
    CREAR_RECOMENDACION: 'Recomendación', ELIMINAR_RECOMENDACION: 'Borrado de recomendación',
  };
  function esc(t) { return String(t == null ? '' : t).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function abrirPanel() {
    tx('salida', 'readonly', function (s) { return s.getAll(); }).then(function (items) {
      items = (items || []).sort(function (a, b) { return a.n - b.n; });
      var html = '<div class="p"><h3>En el teléfono, por enviar</h3>'
        + '<div style="font-size:12.5px;color:#4b5a6e">Se envían solos, en orden, en cuanto hay señal.'
        + (enLinea ? '' : ' <b>Ahora no hay conexión.</b>') + '</div>';
      items.forEach(function (it) {
        html += '<div class="i"><b>' + esc(NOMBRES[it.accion] || it.accion) + '</b> · '
          + new Date(it.creado).toLocaleString('es-MX', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
          + (it.estado !== 'pendiente' ? '<div class="e">' + esc(it.error || 'No se pudo enviar.') + '</div>' : '') + '</div>';
      });
      html += '<button type="button" style="background:#0B2545;color:#fff" id="avba-app-enviar">Enviar ahora</button>'
        + (items.some(function (i) { return i.estado !== 'pendiente'; })
            ? '<button type="button" style="background:#f3f6fa;color:#1f2d3d" id="avba-app-reintentar">Reintentar los que fallaron</button>'
            + '<button type="button" style="background:#fdecea;color:#a32d2d" id="avba-app-descartar">Descartar los que fallaron</button>' : '')
        + '<button type="button" style="background:#f3f6fa;color:#1f2d3d" id="avba-app-cerrar">Cerrar</button></div>';
      nodos.panel.innerHTML = html;
      nodos.panel.style.display = 'flex';
      var $ = function (id) { return document.getElementById(id); };
      $('avba-app-cerrar').onclick = function () { nodos.panel.style.display = 'none'; };
      $('avba-app-enviar').onclick = function () {
        nodos.panel.style.display = 'none';
        if (!enLinea) { aviso('Sin conexión: se enviarán solos al volver la señal.', 'err'); return; }
        procesarSalida();
      };
      if ($('avba-app-reintentar')) $('avba-app-reintentar').onclick = function () {
        Promise.all(items.filter(function (i) { return i.estado !== 'pendiente'; }).map(function (i) {
          i.estado = 'pendiente'; i.error = ''; return tx('salida', 'readwrite', function (s) { return s.put(i); });
        })).then(function () { nodos.panel.style.display = 'none'; return cargarEstado(); }).then(procesarSalida);
      };
      if ($('avba-app-descartar')) $('avba-app-descartar').onclick = function () {
        if (!confirm('Se borrarán del teléfono los registros que el servidor rechazó. ¿Continuar?')) return;
        Promise.all(items.filter(function (i) { return i.estado !== 'pendiente'; }).map(function (i) {
          return tx('salida', 'readwrite', function (s) { return s.delete(i.n); });
        })).then(function () { nodos.panel.style.display = 'none'; return cargarEstado(); });
      };
    });
  }

  /* ── 5. Actualización automática de pantallas ────────────────────────── */
  /* El servidor publica en cada despliegue el paquete de pantallas
     (app-paquete/paquete.zip) y su ficha (acción APP_VERSION). La app:
       · revisa con señal: cada 30 min en wifi, cada 6 h con datos;
       · descarga sola, verificando la huella SHA-256 del paquete;
       · lo aplica al abrir la app desde cerrada o en el inicio de sesión, nunca
         a media captura (por eso no se usa el «aplicar al pasar a segundo
         plano» del plugin: en Android abrir la cámara cuenta como eso);
       · si la versión nueva no arranca (no llega a avisar que está lista), el
         plugin regresa a la anterior y esa versión no se vuelve a intentar.
     Si el paquete pide una app más nueva (plugin o permiso nuevo), no se
     aplica: se avisa que hay versión nueva en la tienda. */
  var Upd = plugin('CapacitorUpdater');
  var CLAVE_PEND = 'avba_paquete_pendiente', CLAVE_REV = 'avba_paquete_revisado',
      CLAVE_FALLO = 'avba_paquete_intento', CLAVE_TIENDA = 'avba_paquete_tienda';
  function leerLS(k) { try { return JSON.parse(localStorage.getItem(k) || 'null'); } catch (e) { return null; } }
  function guardarLS(k, v) { try { v === null ? localStorage.removeItem(k) : localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }

  // Esta versión arrancó: el plugin deja de contar para regresar a la anterior.
  if (Upd) { try { Upd.notifyAppReady(); } catch (e) {} }
  (function () {
    var intento = leerLS(CLAVE_FALLO);
    if (intento && intento.version === PAQ.version) guardarLS(CLAVE_FALLO, null);   // se aplicó bien
  })();

  function numVersion(v) { return String(v || '0').split(/[^0-9]+/).filter(Boolean).slice(0, 3).map(Number); }
  function alMenos(a, b) {           // ¿la versión a es igual o mayor que b?
    var x = numVersion(a), y = numVersion(b);
    for (var i = 0; i < 3; i++) { if ((x[i] || 0) !== (y[i] || 0)) return (x[i] || 0) > (y[i] || 0); }
    return true;
  }
  function esMasNueva(ficha) { return !!(ficha && ficha.armado && (!PAQ.armado || ficha.armado > PAQ.armado)); }

  var revisando = null;
  function revisarActualizacion(forzar) {
    if (!Upd || !enLinea || revisando) return revisando || Promise.resolve(null);
    var ahora = Date.now(), ult = +(localStorage.getItem(CLAVE_REV) || 0);
    var cada = tipoRed === 'cellular' ? 6 * 3600e3 : 30 * 60e3;
    if (!forzar && ahora - ult < cada) return Promise.resolve(null);
    localStorage.setItem(CLAVE_REV, String(ahora));
    revisando = window.fetch(SERVIDOR + 'api/index.php?action=APP_VERSION&_=' + ahora)
      .then(function (r) { return r.json(); })
      .then(function (f) {
        if (!f || f.status !== 'success' || !f.version || !f.archivo || !esMasNueva(f)) return null;
        var pend = leerLS(CLAVE_PEND), fallo = leerLS(CLAVE_FALLO);
        if (pend && pend.version === f.version) return pend;                 // ya descargada
        if (fallo && fallo.version === f.version) return null;               // ya falló una vez aquí
        return Upd.current().then(function (c) {
          if (f.nativoMinimo && c && c.native && !alMenos(c.native, f.nativoMinimo)) {
            if (localStorage.getItem(CLAVE_TIENDA) !== f.version) {
              localStorage.setItem(CLAVE_TIENDA, f.version);
              aviso('Hay una versión nueva de AVBA en la tienda. Actualízala para ver lo más reciente.');
            }
            return null;
          }
          return Upd.download({
            url: SERVIDOR + 'app-paquete/' + encodeURIComponent(f.archivo) + '?v=' + encodeURIComponent(f.version),
            version: f.version,
            checksum: f.sha256 || undefined,
          }).then(function (b) {
            if (pend && pend.id && pend.id !== b.id) Upd.delete({ id: pend.id }).catch(function () {});
            var nuevo = { id: b.id, version: f.version, armado: f.armado };
            guardarLS(CLAVE_PEND, nuevo);
            return nuevo;
          });
        });
      })
      .catch(function () { return null; })
      .then(function (r) { revisando = null; return r; });
    return revisando;
  }

  /* Aplica la versión descargada. Recarga la app: sólo se llama al abrirla
     (mobile/src/index.html) o en el inicio de sesión, donde no hay nada a medias. */
  function aplicarActualizacion() {
    var p = leerLS(CLAVE_PEND);
    if (!Upd || !p || !p.id) return Promise.resolve(false);
    guardarLS(CLAVE_PEND, null);
    if (!esMasNueva(p)) return Promise.resolve(false);                       // ya es la que corre
    guardarLS(CLAVE_FALLO, { version: p.version, fecha: Date.now() });       // se borra si arranca bien
    return Promise.race([
      Upd.set({ id: p.id }).then(function () { return true; }, function () { guardarLS(CLAVE_FALLO, null); return false; }),
      new Promise(function (ok) { setTimeout(function () { ok(false); }, 5000); }),
    ]);
  }

  /* ── Arranque ────────────────────────────────────────────────────────── */
  document.addEventListener('DOMContentLoaded', function () {
    pintarAvisos();
    corregirArbol(document.body);
    cargarEstado().then(function () { if (enLinea) procesarSalida(); });
    setTimeout(function () { precargar(false); }, 2500);
    // En el inicio de sesión no hay nada a medias: buen momento para aplicar
    if (/\/login\.html$/.test(location.pathname) && !/[?&]sesion=/.test(location.search)) aplicarActualizacion();
    setTimeout(function () { revisarActualizacion(false); }, 10000);
  });
  var App = plugin('App');
  if (App && App.addListener) {
    try {
      App.addListener('appStateChange', function (s) {
        if (s.isActive) { renovarSesionSinSenal(); if (enLinea) procesarSalida(); revisarActualizacion(false); }
      });
    } catch (e) {}
  }
  setInterval(function () { if (enLinea && pendientes.total) procesarSalida(); }, 60000);

  window.AVBAApp = {
    esApp: true,
    servidor: SERVIDOR,
    enLinea: function () { return enLinea; },
    alCambiarConexion: function (f) { oyentes.push(f); },
    pendientes: function () { return pendientes.total; },
    enviarPendientes: procesarSalida,
    precargar: function () { return precargar(true); },
    abrirExterno: abrirExterno,
    rutaServidor: aServidor,
    version: PAQ.version || '',
    revisarActualizacion: function () { return revisarActualizacion(true); },
    aplicarActualizacion: aplicarActualizacion,
  };
})();
