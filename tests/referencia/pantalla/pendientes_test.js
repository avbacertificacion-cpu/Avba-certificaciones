// accesos.html contra la API real (copia local, base de prueba):
//  1) lo pendiente de una cuenta no se envía a nombre de otra que entra en el mismo teléfono
//  2) dos teléfonos sin señal a la vez, con cuentas distintas: cada uno manda lo suyo al volver
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
const ses = u => ({ token: 'tok-' + u, rol: u === 'admin' ? 'ADMIN' : 'ACCESOS', usuario: u, nombre: u.toUpperCase() });

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
  // Cierra sesión y entra la cuenta punto1 antes de que vuelva la señal
  await P.p.evaluate((s) => localStorage.setItem('ca_sesion', JSON.stringify(s)), ses('punto1'));
  await P.ctx.setOffline(false);
  await P.p.waitForTimeout(2500);
  await P.p.reload(); await P.p.waitForTimeout(4000);
  ok(/PUNTO1/.test(await P.p.textContent('#sub-usuario')), 'ahora el teléfono P está con la cuenta punto1');
  ok(enServidor('55500001') === '', 'lo pendiente de punto4 NO se envió a nombre de punto1 (' + (enServidor('55500001') || 'no está en el servidor') + ')');
  ok(!(await badges(P.p)).some(t => /55500001/.test(t)) && await P.p.isHidden('#chip-cola'), 'y no aparece en la lista ni en «por enviar» de punto1');
  // Vuelve a entrar punto4 → lo suyo sale solo, a su nombre
  await cambiarCuenta(P.p, 'punto4');
  await P.p.waitForTimeout(3500);
  ok(enServidor('55500001') === 'punto4|ACCESO', 'al volver a entrar punto4, se envía a su nombre: ' + enServidor('55500001'));
  ok((await badges(P.p)).some(t => /55500001/.test(t) && !/En cola/.test(t)), 'y ya aparece en su lista como registrado');

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

  const errs = [P, Q, R].flatMap(x => x.p.errores);
  ok(!errs.length, 'sin errores de JavaScript' + (errs.length ? ': ' + errs.join(' | ') : ''));
  await b.close();
  sql("DELETE FROM accesos_registros WHERE codigo LIKE '5550%'");
  console.log(fallas ? fallas + ' FALLAS' : 'TODO BIEN');
  process.exit(fallas ? 1 : 0);
})().catch(e => { console.error('FALLO', e); process.exit(1); });
