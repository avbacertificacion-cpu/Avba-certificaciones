/**
 * AVBA Certificaciones — Puente nativo (Capacitor)
 * ------------------------------------------------------------------
 * Este archivo se carga tanto en el navegador web como dentro de la
 * app móvil (Capacitor). Cuando se ejecuta dentro de la app expone
 * helpers para usar funciones nativas (notificaciones, cámara, etc.).
 * En el navegador normal todo degrada de forma segura (no-op).
 *
 * Inclúyelo en las páginas del portal ANTES de tu script principal:
 *   <script src="js/native-bridge.js"></script>
 *
 * Uso desde el portal:
 *   AVBANative.notificarVencimientos([
 *     { titulo: 'Licencia por vencer', cuerpo: 'Juan Pérez — vence en 15 días', dias: 15 },
 *     ...
 *   ]);
 */
(function (global) {
  'use strict';

  var Cap = global.Capacitor;
  var esNativo = !!(Cap && typeof Cap.isNativePlatform === 'function' && Cap.isNativePlatform());

  function plugin(nombre) {
    return (Cap && Cap.Plugins && Cap.Plugins[nombre]) ? Cap.Plugins[nombre] : null;
  }

  var API = {
    /** true si corremos dentro de la app nativa (Android/iOS). */
    esNativo: esNativo,

    /** Plataforma: 'android' | 'ios' | 'web'. */
    plataforma: (esNativo && Cap.getPlatform) ? Cap.getPlatform() : 'web',

    /**
     * Solicita permiso de notificaciones (idempotente). Devuelve Promise<boolean>.
     */
    pedirPermisoNotificaciones: function () {
      var LN = plugin('LocalNotifications');
      if (!esNativo || !LN) {
        // Web: usar Notification API si existe
        if (global.Notification && Notification.requestPermission) {
          return Notification.requestPermission().then(function (p) { return p === 'granted'; });
        }
        return Promise.resolve(false);
      }
      return LN.checkPermissions()
        .then(function (r) {
          if (r.display === 'granted') return true;
          return LN.requestPermissions().then(function (rr) { return rr.display === 'granted'; });
        })
        .catch(function () { return false; });
    },

    /**
     * Agenda notificaciones locales para una lista de vencimientos.
     * @param {Array<{titulo:string, cuerpo:string, dias?:number, id?:number}>} items
     * Sólo dispara en la app nativa; en web usa Notification API como respaldo.
     */
    notificarVencimientos: function (items) {
      if (!Array.isArray(items) || !items.length) return Promise.resolve(false);

      var LN = plugin('LocalNotifications');
      var self = this;

      return this.pedirPermisoNotificaciones().then(function (ok) {
        if (!ok) return false;

        if (esNativo && LN) {
          var base = Math.floor(Date.now() % 1000000);
          var notifs = items.slice(0, 24).map(function (it, i) {
            return {
              id: it.id || (base + i + 1),
              title: it.titulo || 'Documento por vencer',
              body: it.cuerpo || '',
              smallIcon: 'ic_stat_avba',
              iconColor: '#0b3d91',
              // dispara ~5s después para no saturar; el portal decide la cadencia
              schedule: { at: new Date(Date.now() + 5000 + i * 1500) }
            };
          });
          return LN.schedule({ notifications: notifs }).then(function () { return true; }).catch(function () { return false; });
        }

        // Respaldo web
        if (global.Notification && Notification.permission === 'granted') {
          items.slice(0, 8).forEach(function (it) {
            try { new Notification(it.titulo || 'Documento por vencer', { body: it.cuerpo || '', icon: 'icon-192.png' }); } catch (e) {}
          });
          return true;
        }
        return false;
      });
    },

    /**
     * Toma o selecciona una foto usando la cámara nativa.
     * Devuelve Promise<string|null> con un dataURL (base64) listo para subir.
     * En web devuelve null (usa el <input type=file> normal).
     */
    tomarFoto: function (opciones) {
      var Camera = plugin('Camera');
      if (!esNativo || !Camera) return Promise.resolve(null);
      opciones = opciones || {};
      return Camera.getPhoto({
        quality: opciones.quality || 70,
        allowEditing: false,
        resultType: 'dataUrl',
        source: opciones.source || 'PROMPT',
        saveToGallery: false
      }).then(function (img) {
        return img && img.dataUrl ? img.dataUrl : null;
      }).catch(function () { return null; });
    },

    /**
     * Aplica el color de barra de estado al entrar (sólo nativo).
     */
    configurarUI: function () {
      var SB = plugin('StatusBar');
      if (esNativo && SB) {
        try { SB.setBackgroundColor({ color: '#0b3d91' }); } catch (e) {}
        try { SB.setStyle({ style: 'LIGHT' }); } catch (e) {}
      }
    }
  };

  // Manejo del botón "atrás" físico de Android: salir sólo en el login.
  if (esNativo) {
    var App = plugin('App');
    if (App && App.addListener) {
      App.addListener('backButton', function (e) {
        if (e.canGoBack) { global.history.back(); }
        else { App.exitApp(); }
      });
    }
    document.addEventListener('DOMContentLoaded', function () { API.configurarUI(); });
  }

  global.AVBANative = API;
})(window);
