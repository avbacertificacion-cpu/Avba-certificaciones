/* ══════════════════════════════════════════════════════════════
   AVBA — Entrar con la seguridad del dispositivo

   Una sola forma de usarlo en todas las plataformas:

     · Navegador (computadora, tablet, celular): llave de acceso WebAuthn.
       Windows Hello en Windows, Touch ID en Mac, Face ID / Touch ID en
       iPhone y iPad, huella o rostro en Android.
     · App de Android e iOS: el sensor del teléfono. La credencial del
       dispositivo vive en el llavero (Keychain / Keystore) y sólo se lee
       con huella o rostro.

   Flujo:
     1. La persona entra con su contraseña.
     2. Si el dispositivo puede, se le ofrece activar el acceso (una vez;
        si dice «Ahora no», se vuelve a ofrecer en 30 días).
     3. Las siguientes veces entra con un toque. La contraseña sigue
        funcionando siempre.

   En la app, sin señal: con la huella o el rostro se recupera la última
   sesión guardada en el teléfono y se sigue trabajando; lo capturado se
   envía al volver la señal.

   Lo usan login.html y la entrada de la app (mobile/src/index.html).
   ══════════════════════════════════════════════════════════════ */
(function (global) {
  'use strict';

  var API = 'api/index.php';
  var Cap = global.Capacitor;
  var esApp = !!(Cap && typeof Cap.isNativePlatform === 'function' && Cap.isNativePlatform());
  function plugin(n) { return (Cap && Cap.Plugins && Cap.Plugins[n]) || null; }

  var LLAVERO = 'mx.com.avba.certificaciones.acceso';
  var CLAVE   = 'avba_acceso_disp';       // { usuario, nombre, tipo, credenciales[], etiqueta }
  var CLAVE_NO = 'avba_acceso_disp_no';   // { usuario: hasta (ms) }
  var CLAVE_ULTIMA = 'avba_ultima_sesion'; // sólo app: para entrar sin señal
  var DIAS_SIN_PREGUNTAR = 30;
  var CANCELADO = { '10': 1, '11': 1, '13': 1, '15': 1, '16': 1, '17': 1 };

  /* ── Almacenamiento local (puede fallar en modo privado) ─────────────── */
  function leer(k) { try { return JSON.parse(localStorage.getItem(k) || 'null'); } catch (e) { return null; } }
  function guardar(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
  function borrar(k) { try { localStorage.removeItem(k); } catch (e) {} }

  /* ── Qué dispositivo es ──────────────────────────────────────────────── */
  function plataforma() {
    var ua = navigator.userAgent || '';
    var p = (navigator.userAgentData && navigator.userAgentData.platform) || navigator.platform || '';
    if (esApp) return (Cap.getPlatform && Cap.getPlatform()) || 'app';      // ios | android
    if (/iPhone|iPod/.test(ua)) return 'iphone';
    if (/iPad/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1)) return 'ipad';
    if (/Android/.test(ua)) return 'android';
    if (/CrOS/.test(ua)) return 'chromeos';
    if (/Win/i.test(p) || /Windows/.test(ua)) return 'windows';
    if (/Mac/i.test(p)) return 'mac';
    if (/Linux/i.test(p)) return 'linux';
    return 'otro';
  }
  function navegador() {
    var ua = navigator.userAgent || '';
    if (/Edg\//.test(ua)) return 'Edge';
    if (/OPR\//.test(ua)) return 'Opera';
    if (/SamsungBrowser/.test(ua)) return 'Samsung Internet';
    if (/Firefox\//.test(ua)) return 'Firefox';
    if (/Chrome\//.test(ua) || /CriOS/.test(ua)) return 'Chrome';
    if (/Safari\//.test(ua)) return 'Safari';
    return 'Navegador';
  }
  function modeloAndroid() {
    var m = /Android [\d.]+; ([^;)]+)/.exec(navigator.userAgent || '');
    return m ? m[1].replace(/ Build\/.*$/, '').trim() : '';
  }
  function nombreDispositivo() {
    var p = plataforma();
    if (esApp) {
      if (p === 'ios') return 'App AVBA · ' + (/iPad/.test(navigator.userAgent) ? 'iPad' : 'iPhone');
      var mod = modeloAndroid();
      return 'App AVBA · Android' + (mod && mod !== 'K' ? ' ' + mod : '');
    }
    var nombres = { iphone: 'iPhone', ipad: 'iPad', android: 'Android', windows: 'Windows', mac: 'Mac',
                    chromeos: 'Chromebook', linux: 'Linux', otro: '' };
    var n = nombres[p] || '';
    if (p === 'android') { var m2 = modeloAndroid(); if (m2 && m2 !== 'K') n += ' ' + m2; }
    return navegador() + (n ? ' · ' + n : '');
  }

  /** Cómo se llama lo que va a usar, en palabras de la persona. */
  function etiqueta(tipoBio) {
    var p = plataforma();
    if (esApp) {
      if (tipoBio === 2) return 'Face ID';
      if (tipoBio === 1) return 'Touch ID';
      if (tipoBio === 3) return 'tu huella';
      if (tipoBio === 4) return 'tu rostro';
      return p === 'ios' ? 'Face ID o Touch ID' : 'tu huella o rostro';
    }
    if (p === 'windows') return 'Windows Hello';
    if (p === 'mac') return 'Touch ID';
    if (p === 'iphone' || p === 'ipad') return 'Face ID o Touch ID';
    if (p === 'android') return 'tu huella o rostro';
    return 'tu huella o PIN';
  }

  /* ── ¿Se puede en este dispositivo? ──────────────────────────────────── */
  var _disp = null;
  function disponible() {
    if (_disp) return _disp;
    if (esApp) {
      var Bio = plugin('NativeBiometric');
      _disp = !Bio ? Promise.resolve({ ok: false })
        : Bio.isAvailable({ useFallback: false }).then(function (r) {
            return { ok: !!(r && r.isAvailable), tipo: r && r.biometryType, etiqueta: etiqueta(r && r.biometryType) };
          }).catch(function () { return { ok: false }; });
      return _disp;
    }
    if (!global.PublicKeyCredential || !navigator.credentials || !global.isSecureContext
        || typeof PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable !== 'function') {
      _disp = Promise.resolve({ ok: false });
      return _disp;
    }
    _disp = PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable()
      .then(function (ok) { return { ok: !!ok, etiqueta: etiqueta() }; })
      .catch(function () { return { ok: false }; });
    return _disp;
  }

  function activado() {
    var l = leer(CLAVE);
    return l && l.usuario && (l.tipo === (esApp ? 'app' : 'passkey')) ? l : null;
  }

  /* ── Servidor ────────────────────────────────────────────────────────── */
  function llamar(accion, payload, token) {
    var cuerpo = { action: accion, payload: payload || {} };
    if (token) cuerpo.token = token;
    var h = { 'Content-Type': 'application/json' };
    if (token) h.Authorization = 'Bearer ' + token;
    return fetch(API, { method: 'POST', headers: h, body: JSON.stringify(cuerpo) })
      .then(function (r) { return r.text(); })
      .then(function (t) {
        try { return JSON.parse(t); } catch (e) { throw new Error('Respuesta inesperada del servidor.'); }
      });
  }

  /* ── WebAuthn: base64url ↔ bytes ─────────────────────────────────────── */
  function aBytes(b64u) {
    var s = String(b64u).replace(/-/g, '+').replace(/_/g, '/');
    while (s.length % 4) s += '=';
    var bin = atob(s), u = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) u[i] = bin.charCodeAt(i);
    return u.buffer;
  }
  function aB64u(buf) {
    var u = new Uint8Array(buf), s = '';
    for (var i = 0; i < u.length; i++) s += String.fromCharCode(u[i]);
    return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }

  function error(msg, cancelado) { var e = new Error(msg); e.propio = true; if (cancelado) e.cancelado = true; return e; }
  function traducirWebAuthn(e) {
    var n = e && e.name;
    if (e && e.propio) return e;
    if (n === 'NotAllowedError' || n === 'AbortError') return error('Se canceló o no se reconoció. Puedes intentar de nuevo o entrar con tu contraseña.', true);
    if (n === 'InvalidStateError') return error('Este dispositivo ya estaba activado para tu cuenta.');
    if (n === 'SecurityError') return error('Este navegador no permite usar la seguridad del dispositivo en esta dirección.');
    if (n === 'NotSupportedError') return error('Este dispositivo no tiene huella, rostro ni Windows Hello configurados.');
    return e instanceof Error ? e : error('No se pudo usar la seguridad del dispositivo.');
  }
  function traducirNativo(e) {
    var c = e && String(e.code || '');
    if (CANCELADO[c]) return error('Se canceló. Puedes intentar de nuevo o entrar con tu contraseña.', true);
    if (c === '2' || c === '4') return error('Demasiados intentos. Desbloquea el teléfono y vuelve a intentarlo, o entra con tu contraseña.');
    if (c === '3') return error('El teléfono no tiene huella ni rostro registrados. Configúralos en los ajustes del teléfono.');
    if (c === '21') return error('El acceso de este teléfono se borró (por ejemplo, al cambiar las huellas registradas). Entra con tu contraseña y vuelve a activarlo.');
    if (e && e.propio) return e;
    return error('No se pudo usar la huella o el rostro.' + (e && e.message ? ' (' + e.message + ')' : ''));
  }

  /* ── Activar en este dispositivo ─────────────────────────────────────── */
  function activarPasskey(ses) {
    return llamar('PASSKEY_OPCIONES_REGISTRO', {}, ses.token).then(function (o) {
      if (o.status !== 'success') throw error(o.message || 'No se pudo preparar el acceso.');
      var pk = o.publicKey;
      pk.challenge = aBytes(pk.challenge);
      pk.user.id = aBytes(pk.user.id);
      pk.excludeCredentials = (pk.excludeCredentials || []).map(function (c) { return { type: c.type, id: aBytes(c.id) }; });
      return navigator.credentials.create({ publicKey: pk }).catch(function (e) { throw traducirWebAuthn(e); });
    }).then(function (cred) {
      var r = cred.response;
      return llamar('PASSKEY_REGISTRAR', {
        id: aB64u(cred.rawId),
        clientDataJSON: aB64u(r.clientDataJSON),
        attestationObject: aB64u(r.attestationObject),
        nombre: nombreDispositivo(),
        plataforma: plataforma(),
      }, ses.token);
    }).then(function (res) {
      if (res.status !== 'success') throw error(res.message || 'No se pudo activar.');
      var l = activado();
      var creds = (l && l.usuario === ses.usuario && l.credenciales) || [];
      creds.push(res.credencial_id);
      guardar(CLAVE, { usuario: ses.usuario, nombre: ses.nombre, tipo: 'passkey', credenciales: creds.slice(-5) });
      return res;
    }, function (e) {
      // Ya activado aquí antes (el navegador lo sabe aunque esta página no):
      // queda marcado y el navegador ofrecerá la llave que ya tiene.
      if (/ya estaba activado/.test(e.message)) {
        guardar(CLAVE, { usuario: ses.usuario, nombre: ses.nombre, tipo: 'passkey', credenciales: [] });
        return { status: 'success' };
      }
      throw e;
    });
  }

  function activarApp(ses, info) {
    var Bio = plugin('NativeBiometric');
    if (!Bio) return Promise.reject(error('La app no tiene acceso al sensor.'));
    var reg = null;
    var paso = plataforma() === 'ios'
      // En iOS guardar en el llavero no pide Face ID: se pide antes, para que
      // la persona confirme que es ella y vea que funciona.
      ? Bio.verifyIdentity({ reason: 'Activar la entrada con ' + info.etiqueta, useFallback: false })
      : Promise.resolve();
    return paso.catch(function (e) { throw traducirNativo(e); })
      .then(function () { return llamar('APP_REGISTRAR_DISPOSITIVO', { nombre: nombreDispositivo(), plataforma: plataforma() }, ses.token); })
      .then(function (r) {
        if (r.status !== 'success') throw error(r.message || 'No se pudo activar.');
        reg = r;
        return Bio.deleteCredentials({ server: LLAVERO }).catch(function () {}).then(function () {
          return Bio.setCredentials({
            server: LLAVERO,
            username: JSON.stringify({ u: ses.usuario, c: r.credencial_id }),
            password: r.secreto,
            accessControl: 2,                       // BIOMETRY_ANY: sobrevive a registrar otra huella
            title: 'Activar la entrada con ' + info.etiqueta,
            negativeButtonText: 'Cancelar',
          });
        });
      })
      .then(function () {
        guardar(CLAVE, { usuario: ses.usuario, nombre: ses.nombre, tipo: 'app', etiqueta: info.etiqueta });
        recordarSesion(ses);
        return { status: 'success' };
      }, function (e) {
        if (reg) llamar('APP_OLVIDAR_DISPOSITIVO', { credencial_id: reg.credencial_id, secreto: reg.secreto }).catch(function () {});
        throw traducirNativo(e);
      });
  }

  function activar(ses) {
    return disponible().then(function (info) {
      if (!info.ok) throw error('Este dispositivo no tiene huella, rostro ni Windows Hello disponibles.');
      borrarNo(ses.usuario);
      return esApp ? activarApp(ses, info) : activarPasskey(ses);
    });
  }

  /* ── Entrar ──────────────────────────────────────────────────────────── */
  function entrarPasskey() {
    var l = activado();
    return llamar('PASSKEY_OPCIONES_ACCESO', { credenciales: (l && l.credenciales) || [] }).then(function (o) {
      if (o.status !== 'success') throw error(o.message || 'No se pudo preparar el acceso.');
      var pk = o.publicKey;
      pk.challenge = aBytes(pk.challenge);
      pk.allowCredentials = (pk.allowCredentials || []).map(function (c) { return { type: c.type, id: aBytes(c.id) }; });
      return navigator.credentials.get({ publicKey: pk }).catch(function (e) { throw traducirWebAuthn(e); });
    }).then(function (a) {
      var r = a.response;
      return llamar('PASSKEY_ACCEDER', {
        id: aB64u(a.rawId),
        clientDataJSON: aB64u(r.clientDataJSON),
        authenticatorData: aB64u(r.authenticatorData),
        signature: aB64u(r.signature),
        userHandle: r.userHandle ? aB64u(r.userHandle) : '',
        dispositivo: nombreDispositivo(),
      });
    }).then(function (ses) {
      if (ses.status !== 'success') {
        if (ses.revocado) olvidarLocal();
        throw error(ses.message || 'No se pudo entrar.');
      }
      return ses;
    });
  }

  function hayRed() {
    if (global.AVBAApp && typeof AVBAApp.enLinea === 'function') return AVBAApp.enLinea();
    return navigator.onLine !== false;
  }

  function entrarApp() {
    var Bio = plugin('NativeBiometric');
    var l = activado();
    if (!Bio || !l) return Promise.reject(error('El acceso con huella o rostro no está activado en este teléfono.'));
    var et = l.etiqueta || etiqueta();
    var cred;
    return Bio.getSecureCredentials({
      server: LLAVERO, reason: 'Entrar a AVBA', title: 'Entrar a AVBA',
      subtitle: l.nombre || l.usuario, negativeButtonText: 'Usar contraseña',
    }).catch(function (e) {
      var t = traducirNativo(e);
      if (String(e && e.code) === '21') olvidarLocal();
      throw t;
    }).then(function (c) {
      cred = c;
      var info = {};
      try { info = JSON.parse(c.username || '{}'); } catch (e) {}
      if (!hayRed()) return sinSenal(info);
      return llamar('APP_ACCEDER', { credencial_id: info.c, secreto: c.password, dispositivo: nombreDispositivo() })
        .then(function (ses) {
          if (ses.status !== 'success') {
            if (ses.revocado) { olvidarLocal(); Bio.deleteCredentials({ server: LLAVERO }).catch(function () {}); }
            throw error(ses.message || 'No se pudo entrar.');
          }
          return ses;
        }, function () { return sinSenal(info); });        // la red falló a medio camino
    });
    function sinSenal(info) {
      // La identidad ya la confirmó el sensor: se sigue con la última sesión
      // del teléfono. Lo que se capture se envía al volver la señal.
      var u = leer(CLAVE_ULTIMA);
      if (u && u.token && u.usuario === info.u) { u.sin_senal = true; return u; }
      throw error('Sin señal. La primera vez después de cerrar sesión se necesita internet; luego puedes trabajar sin señal.');
    }
  }

  function entrar() { return esApp ? entrarApp() : entrarPasskey(); }

  /* ── Sesión guardada (app, para entrar sin señal) ─────────────────────── */
  function recordarSesion(ses) {
    if (!esApp || !ses || !ses.token) return;
    var l = activado();
    if (!l || l.usuario !== ses.usuario) return;
    var copia = {};
    for (var k in ses) if (k !== 'status' && k !== 'message' && k !== 'sin_senal') copia[k] = ses[k];
    guardar(CLAVE_ULTIMA, copia);
  }

  /* ── Dejar de usarlo en este dispositivo ─────────────────────────────── */
  function olvidarLocal() { borrar(CLAVE); borrar(CLAVE_ULTIMA); }
  function olvidar() {
    var l = activado();
    olvidarLocal();
    if (!esApp || !l) return Promise.resolve();
    var Bio = plugin('NativeBiometric');
    if (!Bio) return Promise.resolve();
    // Sin pedir la huella no se puede leer el secreto para darlo de baja en el
    // servidor; basta con borrarlo del llavero: sin él la credencial no sirve.
    return Bio.deleteCredentials({ server: LLAVERO }).catch(function () {});
  }

  /* ── «Ahora no» ──────────────────────────────────────────────────────── */
  function dijoNo(usuario) { var m = leer(CLAVE_NO) || {}; return m[usuario] && m[usuario] > Date.now(); }
  function anotarNo(usuario) { var m = leer(CLAVE_NO) || {}; m[usuario] = Date.now() + DIAS_SIN_PREGUNTAR * 864e5; guardar(CLAVE_NO, m); }
  function borrarNo(usuario) { var m = leer(CLAVE_NO) || {}; delete m[usuario]; guardar(CLAVE_NO, m); }

  /* ── Ventana para ofrecerlo tras entrar con contraseña ───────────────── */
  function esc(t) { return String(t == null ? '' : t).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  function icono() {
    return '<svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round">' +
      '<path d="M12 11c0 3.5-.6 6.5-2 9"/><path d="M8.5 8.8A4 4 0 0116 11c0 2.2-.2 4.3-.7 6.2"/>' +
      '<path d="M5.6 6.4A7.6 7.6 0 0119.6 11c0 1.4-.1 2.8-.3 4.1"/><path d="M4.4 15.2c.4-1.4.6-2.8.6-4.2 0-.9.2-1.8.5-2.6"/>' +
      '<path d="M12.2 14.8c-.2 2-.7 3.9-1.5 5.6"/></svg>';
  }

  /**
   * Después de entrar con contraseña. Resuelve cuando la persona decide (o de
   * inmediato si no aplica). Nunca rechaza: entrar no depende de esto.
   */
  function ofrecer(ses) {
    if (!ses || !ses.token || ses.rol === 'PARTICIPANTE') return Promise.resolve(false);
    var l = activado();
    if (l && l.usuario === ses.usuario) return Promise.resolve(false);
    if (dijoNo(ses.usuario)) return Promise.resolve(false);
    return disponible().then(function (info) {
      if (!info.ok) return false;
      return new Promise(function (listo) {
        var fondo = document.createElement('div');
        fondo.setAttribute('role', 'dialog');
        fondo.setAttribute('aria-modal', 'true');
        fondo.style.cssText = 'position:fixed;inset:0;z-index:99999;background:rgba(11,37,69,.55);display:flex;' +
          'align-items:flex-end;justify-content:center;padding:16px;padding-bottom:max(16px,env(safe-area-inset-bottom));' +
          'font-family:inherit;-webkit-tap-highlight-color:transparent';
        var ancho = global.matchMedia && global.matchMedia('(min-width: 600px)').matches;
        if (ancho) fondo.style.alignItems = 'center';
        fondo.innerHTML =
          '<div style="background:#fff;border-radius:18px;max-width:400px;width:100%;padding:24px 22px 18px;' +
          'box-shadow:0 20px 60px rgba(0,0,0,.25);text-align:center;color:#1a1a2e">' +
            '<div style="width:58px;height:58px;border-radius:50%;background:#E6F1FB;color:#185FA5;display:flex;' +
            'align-items:center;justify-content:center;margin:0 auto 12px">' + icono() + '</div>' +
            '<div style="font-size:17px;font-weight:700;margin-bottom:6px">Entra más rápido</div>' +
            '<div style="font-size:14px;color:#5a6072;line-height:1.5;margin-bottom:18px">' +
              'La próxima vez entra con <strong>' + esc(info.etiqueta) + '</strong> en este dispositivo, ' +
              'sin escribir tu contraseña.' + (esApp ? ' También funciona sin señal.' : '') + '</div>' +
            '<div data-msg style="display:none;font-size:13px;margin:-6px 0 12px;line-height:1.45"></div>' +
            '<button data-si type="button" style="width:100%;padding:13px;border:none;border-radius:10px;background:#185FA5;' +
            'color:#fff;font-size:15px;font-weight:600;cursor:pointer;min-height:48px">Activar ' + esc(info.etiqueta) + '</button>' +
            '<button data-no type="button" style="width:100%;padding:11px;border:none;background:none;color:#5a6072;' +
            'font-size:14px;cursor:pointer;margin-top:6px;min-height:44px">Ahora no</button>' +
          '</div>';
        document.body.appendChild(fondo);
        var si = fondo.querySelector('[data-si]'), no = fondo.querySelector('[data-no]'), msg = fondo.querySelector('[data-msg]');
        function cerrar(r) { if (fondo.parentNode) fondo.parentNode.removeChild(fondo); listo(r); }
        no.onclick = function () { anotarNo(ses.usuario); cerrar(false); };
        si.onclick = function () {
          si.disabled = no.disabled = true;
          si.textContent = 'Activando…';
          activar(ses).then(function () {
            msg.style.display = 'block'; msg.style.color = '#3B6D11';
            msg.textContent = '¡Listo! La próxima vez entra con ' + info.etiqueta + '.';
            si.textContent = 'Entrando…';
            setTimeout(function () { cerrar(true); }, 900);
          }, function (e) {
            si.disabled = no.disabled = false;
            si.textContent = 'Intentar de nuevo';
            msg.style.display = 'block'; msg.style.color = e.cancelado ? '#5a6072' : '#A32D2D';
            msg.textContent = e.message;
            no.textContent = 'Seguir sin activarlo';
          });
        };
        si.focus();
      });
    }).catch(function () { return false; });
  }

  global.AVBAAcceso = {
    esApp: esApp,
    disponible: disponible,
    activado: activado,
    activar: activar,
    entrar: entrar,
    ofrecer: ofrecer,
    olvidar: olvidar,
    recordarSesion: recordarSesion,
    etiqueta: etiqueta,
    nombreDispositivo: nombreDispositivo,
    plataforma: plataforma,
    verificar: function (motivo) {
      // Sólo app: confirmar que es la persona (al abrir con la sesión guardada)
      var Bio = plugin('NativeBiometric');
      if (!esApp || !Bio) return Promise.resolve();
      return Bio.verifyIdentity({ reason: motivo || 'Entrar a AVBA', title: 'Entrar a AVBA',
                                  negativeButtonText: 'Usar contraseña', useFallback: false })
        .catch(function (e) { throw traducirNativo(e); });
    },
  };
})(window);
