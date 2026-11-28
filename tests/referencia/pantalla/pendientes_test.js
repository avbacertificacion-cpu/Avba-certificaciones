// accesos.html contra la API real (copia local, base de prueba):
//  1) lo pendiente de una cuenta se sube aunque entre otra en el mismo teléfono, a nombre de quien lo capturó
//  2) dos teléfonos sin señal a la vez, con cuentas distintas: cada uno manda lo suyo al volver
//  3) el guardia cierra sesión con registros pendientes y nadie entra: se suben desde la pantalla de inicio
//     de sesión en cuanto hay internet (también las tomas del servicio médico que quedaron en el teléfono)
//  4) lo de antes de la llave de subida (sin «llave») de otra cuenta sigue esperando a esa cuenta
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const B = 'http://localhost:8794/';
const API = B + 'api/index.php';
const D = __dirname + '/';
let fallas = 0;
const ok = (c, m) => { if (!c) fallas++; console.log((c ? 'OK   ' : 'FALLA') + ' ' + m); };
const sql = q => execSync(`mariadb --default-character-set=utf8mb4 -N -B -uprueba -pprueba -h127.0.0.1 ca_prueba -e "${q}"`).toString().trim();
const post = async (accion, tok, payload = {}) => (await fetch(API, {
  method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + tok },
  body: JSON.stringify({ action: accion, token: tok, payload })
})).json();
const crypto = require('crypto');
const llave = u => 'L' + crypto.createHash('sha256').update('llave-' + u).digest('hex').slice(0, 48);   // ver sembrar-cuentas.sql
const ses = u => ({ token: 'tok-' + u, rol: u === 'admin' ? 'ADMIN' : u.startsWith('medico') ? 'MEDICO' : 'ACCESOS', usuario: u, nombre: u.toUpperCase(), llave_envio: llave(u) });

async function equipo(b, sesion) {
  const ctx = await b.newContext({ timezoneId: 'America/Mexico_City', viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
  await ctx.addInitScript((s) => {
    if (!sessionStorage.getItem('ya')) { localStorage.setItem('ca_sesion', JSON.stringify(s)); sessionStorage.setItem('ya', '1'); }
    window.__lecturas = []; window.__abiertos = [];
    window.Capacitor = { isNativePlatform: () => true, Plugins: { LectorTarjeta: {
      leer: () => new Promise(r => setTimeout(() => r(window.__lecturas.shift() || { cancelado: true }), 200)),
      abrir: (o) => { window.__abiertos.push(o.url); return Promise.resolve({}); }
    } } };
  }, sesion);
  const p = await ctx.newPage();
  p.errores = [];
  p.on('pageerror', e => p.errores.push(e.message));
  p.on('dialog', d => d.accept());
  await p.goto(B + 'accesos.html'); await p.waitForTimeout(1800);
  return { ctx, p };
}
async function leerTarjeta(p, codigo, nombre, tipo = 'ent') {
  await p.evaluate(([c, n]) => window.__lecturas.push({ codigo: c, nombre: n, ms: 600 }), [codigo, nombre]);
  await p.click('.cap.' + tipo); await p.waitForTimeout(3000);
}
async function cambiarCuenta(p, u) {
  await p.evaluate((s) => localStorage.setItem('ca_sesion', JSON.stringify(s)), ses(u));
  await p.reload(); await p.waitForTimeout(2500);
}
const enServidor = cod => sql(`SELECT CONCAT(usuario,'|',lugar) FROM accesos_registros WHERE codigo='${cod}'`);
const badges = p => p.$$eval('#lista li', l => l.map(x => x.innerText.replace(/\s+/g, ' ')));
const aviso = p => p.$eval('#banda-punto', e => e.hidden ? '' : e.textContent);
const bloqueados = p => p.$eval('.cap.ent', e => e.disabled);

(async () => {
  // Un solo acceso: no hay puntos que asignar ni teléfonos ligados
  sql("DELETE FROM accesos_registros WHERE codigo LIKE '5550%'");
  const b = await chromium.launch();

  // ── 1) Pendiente de punto4 y entra punto1 en el mismo teléfono ──
  const P = await equipo(b, ses('punto4'));
  ok(/^Acceso · /.test(await P.p.textContent('#sub-usuario')) && /PUNTO4/.test(await P.p.textContent('#sub-usuario')), 'el teléfono P entra con la cuenta punto4');
  await P.ctx.setOffline(true);
  await leerTarjeta(P.p, '55500001', 'Mario Lopez Ruiz');
  ok((await badges(P.p)).some(t => /55500001/.test(t) && /En cola/.test(t)), 'sin señal: queda «En cola» en el teléfono');
  // Cierra sesión (el servidor ya no tiene su sesión) y entra la cuenta punto1 antes de que vuelva la señal
  sql("DELETE FROM sesiones_usuario WHERE token='tok-punto4'");
  await P.p.evaluate((s) => localStorage.setItem('ca_sesion', JSON.stringify(s)), ses('punto1'));
  // Sigue sin llegar al servidor (la página sí abre): se cortan las peticiones a la API
  await P.ctx.route('**/api/index.php**', r => r.abort('internetdisconnected'));
  await P.ctx.setOffline(false);
  await P.p.reload(); await P.p.waitForTimeout(1500);
  ok(/PUNTO1/.test(await P.p.textContent('#sub-usuario')) && /de otra cuenta/.test(await P.p.textContent('#chip-cola')),
     'ahora está punto1 y ve que hay pendientes de otra cuenta: «' + await P.p.textContent('#chip-cola') + '»');
  await P.ctx.unroute('**/api/index.php**');
  await P.p.evaluate(() => window.dispatchEvent(new Event('online')));
  await P.p.waitForTimeout(4000);
  ok(enServidor('55500001') === 'punto4|ACCESO', 'en cuanto hay señal se sube, a nombre de punto4 (aunque su sesión se cerró): ' + (enServidor('55500001') || 'no está en el servidor'));
  ok(sql("SELECT CONCAT(turno_fecha <> '', turno) FROM accesos_registros WHERE codigo='55500001'") === '1D', 'con el turno de punto4 (día)');
  ok(!(await badges(P.p)).some(t => /55500001/.test(t)) && await P.p.isHidden('#chip-cola'), 'no aparece en la lista de punto1 y ya no hay nada por enviar');
  sql("INSERT IGNORE INTO sesiones_usuario (token, usuario_id, origen, creado, expira) SELECT 'tok-punto4', id, 'prueba', NOW(), NOW() + INTERVAL 30 DAY FROM usuarios WHERE usuario='punto4'");

  // ── 2) Dos teléfonos sin señal a la vez, con cuentas distintas ──
  const Q = await equipo(b, ses('punto2'));
  const R = await equipo(b, ses('punto3'));
  await Q.ctx.setOffline(true); await R.ctx.setOffline(true);
  await Promise.all([leerTarjeta(Q.p, '55500002', 'Laura Soto Rios'), leerTarjeta(R.p, '55500003', 'Pedro Vega Cruz')]);
  await Promise.all([leerTarjeta(Q.p, '55500004', 'Ana Ruiz Paez'), leerTarjeta(R.p, '55500005', 'Luis Mora Diaz')]);
  ok(enServidor('55500002') === '' && enServidor('55500003') === '', 'sin señal: los dos guardan en su teléfono');
  await Q.ctx.setOffline(false); await R.ctx.setOffline(false);
  await Promise.all([Q.p.evaluate(() => window.dispatchEvent(new Event('online'))), R.p.evaluate(() => window.dispatchEvent(new Event('online')))]);
  await Q.p.waitForTimeout(5000);
  ok(enServidor('55500002') === 'punto2|ACCESO' && enServidor('55500004') === 'punto2|ACCESO' &&
     enServidor('55500003') === 'punto3|ACCESO' && enServidor('55500005') === 'punto3|ACCESO', 'al volver la señal, cada teléfono manda lo suyo a nombre de su cuenta');
  ok(await Q.p.isHidden('#chip-cola') && await R.p.isHidden('#chip-cola') && !(await bloqueados(Q.p)) && !(await bloqueados(R.p)), 'nada por enviar y ningún botón bloqueado');
  ok(!(await aviso(Q.p)) && !(await aviso(R.p)), 'sin avisos de «otro equipo»');

  // ── 3) Cierra sesión con pendientes y nadie entra: se suben desde la pantalla de inicio de sesión ──
  const S = await equipo(b, ses('punto3'));
  // Sin señal hacia el servidor (las páginas sí abren): se cortan las peticiones a la API
  await S.ctx.route('**/api/index.php**', r => r.abort('internetdisconnected'));
  await leerTarjeta(S.p, '55500006', 'Irma Cano Diaz');
  await leerTarjeta(S.p, '55500007', 'Jorge Pena Lira', 'sal');
  // Una toma del servicio médico que quedó en este teléfono (otra cuenta, sin enviar)
  await S.p.evaluate((l) => localStorage.setItem('ca_medico_cola', JSON.stringify([{ uid: 'med-pend-000001', codigo: '55500008', nombre: 'KARLA SOTO VEGA',
    empresa: '', sistolica: 120, diastolica: 80, frecuencia: 72, resultado: 'A', observaciones: '', capturado: new Date(Date.now() - 6e5).toLocaleString('sv-SE', { timeZone: 'America/Mexico_City' }),
    origen: 'manual', llave: l }])), llave('medico'));
  let msg = '';
  S.p.removeAllListeners('dialog'); S.p.on('dialog', d => { msg += (d.type() === 'confirm' ? '' : d.type() + ': ') + d.message(); d.accept(); });
  await S.p.click('#atras'); await S.p.waitForTimeout(1500);
  ok(/se subirán solos/.test(msg) && /login\.html/.test(S.p.url()), 'al salir avisa que se subirán solos: «' + msg + '» (' + S.p.url() + ')');
  ok(enServidor('55500006') === '' && sql("SELECT COUNT(*) FROM medico_tomas WHERE uid='med-pend-000001'") === '0', 'sin señal todavía no llegan');
  await S.ctx.unroute('**/api/index.php**');
  await S.p.evaluate(() => window.dispatchEvent(new Event('online')));
  await S.p.waitForTimeout(4000);
  ok(enServidor('55500006') === 'punto3|ACCESO' && enServidor('55500007') === 'punto3|ACCESO', 'con señal, desde la pantalla de inicio de sesión (sin nadie dentro) se suben a nombre de punto3');
  ok(sql("SELECT usuario FROM medico_tomas WHERE uid='med-pend-000001'") === 'medico', 'y la toma del servicio médico, a nombre de quien la tomó');
  const quedan = await S.p.evaluate(() => new Promise(ok => { const r = indexedDB.open('ca_accesos', 2); r.onsuccess = () => { const q = r.result.transaction('cola').objectStore('cola').getAll(); q.onsuccess = () => ok(q.result.length); }; }));
  ok(quedan === 0 && await S.p.evaluate(() => JSON.parse(localStorage.getItem('ca_medico_cola') || '[]').length) === 0, 'el teléfono ya no tiene nada pendiente');

  // ── 4) Lo de antes de la llave (sin «llave») de otra cuenta espera a esa cuenta ──
  const V = await equipo(b, ses('punto2'));
  await V.p.evaluate(() => new Promise(ok => { const r = indexedDB.open('ca_accesos', 2); r.onsuccess = () => {
    const t = r.result.transaction('cola', 'readwrite'); t.objectStore('cola').put({ uid: 'viejo-0000000001', tipo: 'entrada', capturado: new Date(Date.now() - 3e5).toLocaleString('sv-SE', { timeZone: 'America/Mexico_City' }),
      lugar: 'ACCESO', codigo: '55500009', persona: 'Luis Mena Rojo', origen: 'tarjeta', cuenta: 'PUNTO1' }); t.oncomplete = ok; }; }));
  await V.p.reload(); await V.p.waitForTimeout(3500);
  ok(enServidor('55500009') === '', 'un registro de antes, de otra cuenta y sin llave, no se sube a nombre de punto2');
  await cambiarCuenta(V.p, 'punto1'); await V.p.waitForTimeout(3000);
  ok(enServidor('55500009') === 'punto1|ACCESO', 'cuando entra su cuenta, se sube: ' + enServidor('55500009'));

  // ── 5) Un teléfono con la sesión abierta desde antes de la llave la pide solo ──
  const sinLlave = Object.assign(ses('noche1'), { rol: 'ACCESOS' }); delete sinLlave.llave_envio;
  const W = await equipo(b, sinLlave);
  await W.p.waitForTimeout(1500);
  ok(/^L[0-9a-f]{48}$/.test(await W.p.evaluate(() => JSON.parse(localStorage.getItem('ca_sesion')).llave_envio || '')), 'una sesión de antes recibe su llave sin volver a entrar');

  const errs = [P, Q, R, S, V, W].flatMap(x => x.p.errores);
  ok(!errs.length, 'sin errores de JavaScript' + (errs.length ? ': ' + errs.join(' | ') : ''));
  await b.close();
  sql("DELETE FROM accesos_registros WHERE codigo LIKE '5550%'; DELETE FROM medico_tomas WHERE uid='med-pend-000001'");
  console.log(fallas ? fallas + ' FALLAS' : 'TODO BIEN');
  process.exit(fallas ? 1 : 0);
})().catch(e => { console.error('FALLO', e); process.exit(1); });
