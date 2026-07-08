/**
 * AVBA Certificaciones — Motor de guardado offline
 *
 * Dos piezas:
 *  1. Borradores: autoguarda el formulario en curso (uno por tipo) en
 *     IndexedDB, para que un cierre accidental de la app/pestaña o un
 *     reinicio del teléfono no pierda el avance.
 *  2. Cola de pendientes: si al enviar no hay conexión, el envío completo
 *     se guarda en IndexedDB y se reintenta automáticamente en cuanto
 *     vuelve la señal — el inspector no tiene que repetir nada.
 *
 * Sin dependencias externas. Degrada de forma segura si IndexedDB no
 * está disponible (los métodos devuelven null/false/[] en vez de tronar).
 */
const AvbaOffline = (() => {
  const DB_NAME    = 'avba_offline';
  const DB_VERSION = 1;
  const ST_DRAFTS  = 'drafts';
  const ST_PENDING = 'pending';

  let dbPromise = null;

  function abrirDB() {
    if (dbPromise) return dbPromise;
    dbPromise = new Promise((resolve, reject) => {
      if (!('indexedDB' in window)) { reject(new Error('IndexedDB no disponible')); return; }
      const req = indexedDB.open(DB_NAME, DB_VERSION);
      req.onupgradeneeded = () => {
        const db = req.result;
        if (!db.objectStoreNames.contains(ST_DRAFTS)) {
          db.createObjectStore(ST_DRAFTS, { keyPath: 'tipo' });
        }
        if (!db.objectStoreNames.contains(ST_PENDING)) {
          const st = db.createObjectStore(ST_PENDING, { keyPath: 'id' });
          st.createIndex('tipo', 'tipo', { unique: false });
        }
      };
      req.onsuccess = () => resolve(req.result);
      req.onerror   = () => reject(req.error);
    });
    return dbPromise;
  }

  async function store(nombre, modo) {
    const db = await abrirDB();
    return db.transaction(nombre, modo).objectStore(nombre);
  }

  function prom(req) {
    return new Promise((resolve, reject) => {
      req.onsuccess = () => resolve(req.result);
      req.onerror   = () => reject(req.error);
    });
  }

  function uid() {
    return Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 9);
  }

  return {
    // ── Borrador en curso (uno por tipo de formulario) ──────────────
    async guardarBorrador(tipo, data) {
      try {
        const s = await store(ST_DRAFTS, 'readwrite');
        await prom(s.put({ tipo, data, actualizado: Date.now() }));
        return true;
      } catch (e) { console.warn('[AvbaOffline] guardarBorrador:', e); return false; }
    },
    async obtenerBorrador(tipo) {
      try {
        const s = await store(ST_DRAFTS, 'readonly');
        const row = await prom(s.get(tipo));
        return row ? row.data : null;
      } catch (e) { console.warn('[AvbaOffline] obtenerBorrador:', e); return null; }
    },
    async borrarBorrador(tipo) {
      try {
        const s = await store(ST_DRAFTS, 'readwrite');
        await prom(s.delete(tipo));
      } catch (e) { console.warn('[AvbaOffline] borrarBorrador:', e); }
    },

    // ── Cola de envíos pendientes (varios, se procesan en orden) ────
    async encolar(tipo, url, body) {
      const id = uid();
      try {
        const s = await store(ST_PENDING, 'readwrite');
        await prom(s.put({ id, tipo, url, body, creado: Date.now(), intentos: 0 }));
        return id;
      } catch (e) { console.warn('[AvbaOffline] encolar:', e); return null; }
    },
    async listarPendientes(tipo) {
      try {
        const s = await store(ST_PENDING, 'readonly');
        const all = await prom(s.getAll());
        return tipo ? all.filter(x => x.tipo === tipo) : all;
      } catch (e) { console.warn('[AvbaOffline] listarPendientes:', e); return []; }
    },
    async contarPendientes(tipo) {
      const items = await this.listarPendientes(tipo);
      return items.length;
    },
    async eliminarPendiente(id) {
      try {
        const s = await store(ST_PENDING, 'readwrite');
        await prom(s.delete(id));
      } catch (e) { console.warn('[AvbaOffline] eliminarPendiente:', e); }
    },

    /**
     * Intenta enviar cada pendiente del tipo indicado, en orden.
     * onItem(item, status, data) se llama por cada intento:
     *   status = 'ok'      → se envió y se quitó de la cola
     *   status = 'error'   → el servidor lo rechazó (datos inválidos); se
     *                        deja en cola para revisión manual, no se
     *                        reintenta solo (evitar loop infinito)
     *   status = 'network' → sigue sin haber conexión; se detiene el resto
     * Devuelve cuántos se enviaron con éxito.
     */
    async procesarPendientes(tipo, onItem) {
      const items = await this.listarPendientes(tipo);
      let enviados = 0;
      for (const item of items) {
        try {
          const resp = await fetch(item.url, {
            method:  'POST',
            headers: { 'Content-Type': 'text/plain' },
            body:    JSON.stringify(item.body)
          });
          const data = await resp.json().catch(() => null);
          if (data && data.status === 'success') {
            await this.eliminarPendiente(item.id);
            enviados++;
            if (onItem) onItem(item, 'ok', data);
          } else {
            if (onItem) onItem(item, 'error', data);
          }
        } catch (netErr) {
          if (onItem) onItem(item, 'network', netErr);
          break;
        }
      }
      return enviados;
    }
  };
})();
window.AvbaOffline = AvbaOffline;
