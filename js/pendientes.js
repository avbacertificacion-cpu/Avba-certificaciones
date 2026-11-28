/* Lo que quedó guardado en este equipo sin señal se sube en cuanto hay internet, esté quien esté
   en la sesión (o nadie, en la pantalla de inicio de sesión).

   Cada registro lleva la «llave de subida» de la cuenta que lo capturó (la da el servidor al
   iniciar sesión, ver Auth::usuarioDeLlave): con ella el servidor lo guarda a nombre de esa
   cuenta y con su turno, aunque su sesión ya se haya cerrado o vencido.

   - Registros de la caseta (entradas, salidas, incidencias, permisos): IndexedDB «ca_accesos»,
     almacén «cola». accesos.html los sube con lo suyo; aquí, en las demás pantallas.
   - Tomas del servicio médico: localStorage «ca_medico_cola». medico.html las sube con lo suyo;
     aquí, en las demás pantallas.

   Uso: CA_PENDIENTES.vigilar({ caseta: true, medico: true }). Lo que el servidor no acepta se
   queda marcado con su error (lo ve quien lo capturó al volver a entrar); sin señal, se reintenta. */
(function () {
  var API = 'api/index.php';
  var corriendo = false;

  function leerJSON(k, d) { try { var v = JSON.parse(localStorage.getItem(k) || 'null'); return v == null ? d : v; } catch (e) { return d; } }
  function equipo() { var id = leerJSON('ca_accesos_equipo', ''); return /^[A-Za-z0-9-]{8,80}$/.test(id) ? id : ''; }

  /* ── Caseta (IndexedDB) ── */
  var dbProm = null;
  function db() {
    if (dbProm) return dbProm;
    dbProm = new Promise(function (ok, mal) {
      if (!window.indexedDB) { mal(new Error('sin IndexedDB')); return; }
      var r = indexedDB.open('ca_accesos', 2);
      r.onupgradeneeded = function () {
        var d = r.result;
        if (!d.objectStoreNames.contains('cola')) d.createObjectStore('cola', { keyPath: 'uid' });
        if (!d.objectStoreNames.contains('fotos')) d.createObjectStore('fotos', { keyPath: 'uid' });
      };
      r.onsuccess = function () { ok(r.result); };
      r.onerror = function () { mal(r.error); };
    });
    return dbProm;
  }
  function tx(almacen, modo, fn) {
    return db().then(function (d) {
      return new Promise(function (ok, mal) {
        var t = d.transaction(almacen, modo), rq = fn(t.objectStore(almacen)), res;
        if (rq) rq.onsuccess = function () { res = rq.result; };
        t.oncomplete = function () { ok(res); }; t.onerror = t.onabort = function () { mal(t.error); };
      });
    });
  }
  function esPermiso(x) { return x && x.clase === 'permiso'; }
  function esInc(x) { return x && x.clase === 'incidencia'; }
  /* Igual que accesos.html: la foto de la credencial se queda en el equipo; se suben la del pase de un
     permiso y la de un registro sin ID ni nombre (regla subirFotos: true, todas). */
  function subeFoto(it) { return esPermiso(it) || !!(leerJSON('ca_tarjeta_reglas', {}) || {}).subirFotos || (!it.codigo && !it.persona); }

  function formulario(it) {
    var fd = new FormData(), eq = equipo();
    ['uid', 'tipo', 'capturado', 'lugar', 'nota'].forEach(function (k) { fd.append(k, it[k] || ''); });
    if (it.codigo) { fd.append('codigo', it.codigo); fd.append('persona', it.persona || ''); fd.append('origen', it.origen || 'tarjeta'); }
    if (eq) fd.append('dispositivo', eq);
    fd.append('llave_envio', it.llave);
    if (it.datos && subeFoto(it)) fd.append('foto', new Blob([it.datos], { type: it.mime || 'image/jpeg' }), it.uid + '.jpg');
    if (esInc(it)) { fd.append('incidencia', it.incidencia); fd.append('detalle', it.detalle || ''); if (!it.codigo) fd.append('persona', it.persona || ''); }
    if (esPermiso(it)) { fd.append('tipos', it.tipos); fd.append('detalle', it.detalle || ''); fd.append('vigencia', it.vigencia || ''); }
    fd.append('action', esPermiso(it) ? 'PERMISO_AGREGAR' : esInc(it) ? 'ACCESO_INCIDENCIA' : 'ACCESO_REGISTRAR');
    return fd;
  }
  /* 'ok' | 'red' (se reintenta después) | 'rechazo' (se queda marcado) */
  function subirUno(it) {
    return fetch(API, { method: 'POST', body: formulario(it) }).then(function (r) {
      if (r.status >= 500) return 'red';
      return r.json().then(function (j) {
        if (j && j.status === 'success') return 'ok';
        it.error = (j && j.message) || 'El servidor no lo aceptó.';
        return 'rechazo';
      }, function () { return 'red'; });
    }, function () { return 'red'; });
  }
  /* ¿Ya lo tiene el servidor? Sólo entonces sale de la cola (un reenvío no duplica). */
  function loTiene(it) {
    return fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'ACCESOS_EXISTEN', payload: { uids: [it.uid], llave_envio: it.llave } }) })
      .then(function (r) { return r.json(); })
      .then(function (j) { return !!(j && j.status === 'success' && j.existen && j.existen[it.uid]); }, function () { return false; });
  }
  function guardarFoto(it) {
    var f = { uid: it.uid, creado: Date.now(), capturado: it.capturado, tipo: it.tipo, codigo: it.codigo || '', persona: it.persona || '',
              mini: it.mini || '', datos: it.datos, mime: it.mime || 'image/jpeg' };
    return tx('fotos', 'readwrite', function (st) { return st.put(f); }).catch(function () {});
  }
  function subirCaseta() {
    return tx('cola', 'readonly', function (st) { return st.getAll(); }).then(function (lista) {
      var pend = (lista || []).filter(function (x) { return x.llave && !x.error; })
        .sort(function (a, b) { return a.capturado < b.capturado ? -1 : 1; });
      var n = 0;
      return pend.reduce(function (p, it) {
        return p.then(function (seguir) {
          if (!seguir) return false;
          return subirUno(it).then(function (r) {
            if (r === 'red') return false;
            if (r === 'rechazo') return tx('cola', 'readwrite', function (st) { return st.put(it); }).then(function () { return true; });
            return loTiene(it).then(function (si) {
              if (!si) return false;                                   // sin confirmar: se queda y se reintenta
              n++;
              return (it.datos && !subeFoto(it) ? guardarFoto(it) : Promise.resolve())
                .then(function () { return tx('cola', 'readwrite', function (st) { return st.delete(it.uid); }); })
                .then(function () { return true; });
            });
          });
        });
      }, Promise.resolve(true)).then(function () { return n; });
    }).catch(function () { return 0; });
  }

  /* ── Servicio médico (localStorage) ── */
  var COLA_MED = 'ca_medico_cola';
  function subirMedico() {
    var pend = (leerJSON(COLA_MED, []) || []).filter(function (t) { return t.llave && !t.error; });
    var n = 0;
    return pend.reduce(function (p, t) {
      return p.then(function (seguir) {
        if (!seguir) return false;
        var datos = { llave_envio: t.llave };
        ['uid', 'codigo', 'nombre', 'empresa', 'sistolica', 'diastolica', 'frecuencia', 'resultado', 'observaciones', 'capturado', 'origen']
          .forEach(function (k) { datos[k] = t[k]; });
        return fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'MEDICO_REGISTRAR', payload: datos }) })
          .then(function (r) { return r.status >= 500 ? null : r.json(); })
          .then(function (j) {
            if (!j) return false;
            // Se vuelve a leer la cola (la pantalla pudo agregar otra mientras tanto)
            var cola = leerJSON(COLA_MED, []) || [];
            if (j.status === 'success') { cola = cola.filter(function (x) { return x.uid !== t.uid; }); n++; }
            else cola.forEach(function (x) { if (x.uid === t.uid) x.error = j.message || 'No se pudo guardar.'; });
            try { localStorage.setItem(COLA_MED, JSON.stringify(cola)); } catch (e) {}
            return true;
          }, function () { return false; });
      });
    }, Promise.resolve(true)).then(function () { return n; });
  }

  /** Sube lo pendiente; regresa cuántos subió. */
  function subir(op) {
    if (corriendo || (navigator.onLine === false)) return Promise.resolve(0);
    corriendo = true;
    var total = 0;
    return (op.caseta ? subirCaseta() : Promise.resolve(0))
      .then(function (n) { total += n; return op.medico ? subirMedico() : 0; })
      .then(function (n) { total += n; corriendo = false; if (total && op.alSubir) op.alSubir(total); return total; },
            function () { corriendo = false; return total; });
  }
  /* Un teléfono que inició sesión antes de que existiera la llave de subida la pide una vez */
  function asegurarLlave() {
    var s = leerJSON('ca_sesion', {}) || {};
    if (!s.token || s.llave_envio || s.rol === 'CLIENTE' || navigator.onLine === false) return;
    fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + s.token },
                 body: JSON.stringify({ action: 'LLAVE_ENVIO', payload: {} }) })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        var a = leerJSON('ca_sesion', {}) || {};
        if (j && j.status === 'success' && j.llave_envio && a.token === s.token) {
          a.llave_envio = j.llave_envio;
          try { localStorage.setItem('ca_sesion', JSON.stringify(a)); } catch (e) {}
        }
      }, function () {});
  }
  function vigilar(op) {
    op = op || {};
    var ir = function () { asegurarLlave(); subir(op); };
    setTimeout(ir, 1500);
    window.addEventListener('online', ir);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) ir(); });
    setInterval(ir, 30000);
  }
  window.CA_PENDIENTES = { subir: subir, vigilar: vigilar };
})();
