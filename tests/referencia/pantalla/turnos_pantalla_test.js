// Turnos por cuenta en la pantalla del guardia: el de día y el de noche comparten el teléfono del
// Punto 1; lo que registra cada uno va a SU turno, a cualquier hora (con y sin señal), y cada uno ve
// sólo su turno. API real local (base de prueba), reloj del teléfono simulado, datos inventados.
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
  body: JSON.stringify({ action: accion, payload })
})).json();
const hoy = new Date().toLocaleDateString('sv-SE', { timeZone: 'America/Mexico_City' });
const a = hora => new Date(`${hoy}T${hora}-06:00`);           // hora de la planta, hoy
const ses = (u, t) => ({ token: 'tok-' + u, rol: 'ACCESOS', usuario: u, nombre: u.toUpperCase(), turno: t });

async function leer(p, hora, tipo, codigo, nombre) {
  await p.clock.setFixedTime(a(hora));
  await p.evaluate(([c, n]) => window.__lecturas.push({ codigo: c, nombre: n, ms: 500 }), [codigo, nombre]);
  await p.click('.cap.' + tipo); await p.waitForTimeout(2200);
}
const bloques = p => p.$$eval('.bloque-turno', l => l.map(x => x.innerText.replace(/\s+/g, ' ')));
const turnoBD = cod => sql(`SELECT GROUP_CONCAT(CONCAT(tipo,':',turno_fecha,'-',turno) ORDER BY id) FROM accesos_registros WHERE codigo='${cod}'`);

(async () => {
  const b = await chromium.launch();
  // El teléfono de la caseta (el mismo para los dos guardias)
  const ctx = await b.newContext({ timezoneId: 'America/Mexico_City', viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
  await ctx.addInitScript((s) => {
    if (!sessionStorage.getItem('ya')) { localStorage.setItem('ca_sesion', JSON.stringify(s)); sessionStorage.setItem('ya', '1'); }
    window.__lecturas = [];
    window.Capacitor = { isNativePlatform: () => true, Plugins: { LectorTarjeta: {
      leer: () => new Promise(r => setTimeout(() => r(window.__lecturas.shift() || { cancelado: true }), 150)),
      abrir: () => Promise.resolve({})
    } } };
  }, ses('punto1', 'D'));
  const p = await ctx.newPage();
  const errores = []; p.on('pageerror', e => errores.push(e.message)); p.on('dialog', d => d.accept());

  // ── Guardia de DÍA a las 17:56 ──
  await p.clock.setFixedTime(a('17:56:00'));
  await p.goto(B + 'accesos.html'); await p.waitForTimeout(1800);
  ok(/^Acceso · Turno de día · PUNTO1$/.test(await p.textContent('#sub-usuario')), 'encabezado: «' + await p.textContent('#sub-usuario') + '»');
  let t = await bloques(p);
  ok(t.length === 1 && /Turno de día · en curso/.test(await p.textContent('#t-nombre')), 'sólo ve su turno (sin «cambio de turno»): ' + await p.textContent('#t-nombre'));
  await leer(p, '17:57:26', 'ent', '00601', 'Ramiro Esteban Villarreal Duarte');
  await leer(p, '17:58:02', 'sal', '00601', 'Ramiro Esteban Villarreal Duarte');
  await p.waitForTimeout(1500);
  ok(turnoBD('00601') === `entrada:${hoy}-D,salida:${hoy}-D`, 'entrada 17:57 y salida 17:58 del guardia de día: las dos en el turno de día');
  t = await bloques(p);
  ok(/1 Entradas 1 Salidas 0 Diferencia/.test(t[0]), 'contadores del turno de día: ' + t[0]);
  await leer(p, '19:10:00', 'sal', '00602', 'Ana Sofia Ruiz Paez');      // se quedó tarde: sigue siendo su turno
  await p.waitForTimeout(1200);
  ok(turnoBD('00602') === `salida:${hoy}-D`, 'el guardia de día registra a las 19:10: turno de día');
  await p.screenshot({ path: D + 'turnos_guardia_dia.png' });

  // ── Cambio de guardia en el MISMO teléfono: entra el de NOCHE a las 17:59 ──
  await p.evaluate((s) => localStorage.setItem('ca_sesion', JSON.stringify(s)), ses('noche1', 'N'));
  await p.clock.setFixedTime(a('17:59:00'));
  await p.reload(); await p.waitForTimeout(2000);
  ok(/Turno de noche/.test(await p.textContent('#sub-usuario')) && await p.isHidden('#banda-punto'), 'el de noche entra en el mismo teléfono: «' + await p.textContent('#sub-usuario') + '»');
  ok((await p.$$eval('#lista li.reg', l => l.length)) === 0, 'su lista empieza vacía (lo del de día no es suyo)');
  // Sin señal
  await ctx.setOffline(true);
  await leer(p, '17:59:30', 'ent', '00603', 'Pedro Vega Cruz');
  t = await bloques(p);
  const sub = await p.textContent('#t-sub');
  ok(t.length === 1 && /1 Entradas 0 Salidas/.test(t[0]) && /17:30 a .*05:30/.test(sub), 'sin señal (en cola): cuenta en su noche (' + sub + '): ' + t[0]);
  await ctx.setOffline(false);
  await p.evaluate(() => window.dispatchEvent(new Event('online'))); await p.waitForTimeout(4000);
  ok(turnoBD('00603') === `entrada:${hoy}-N`, 'al volver la señal: turno de noche de hoy');
  t = await bloques(p);
  ok(/1 Entradas 0 Salidas/.test(t[0]), 'y el contador no brinca al enviarse');
  await p.screenshot({ path: D + 'turnos_guardia_noche.png' });

  // ── La madrugada siguiente (06:20) sigue siendo la noche de hoy ──
  const manana = new Date(new Date(hoy + 'T12:00:00Z').getTime() + 86400e3).toISOString().slice(0, 10);
  await p.clock.setFixedTime(new Date(`${manana}T06:20:00-06:00`));
  await p.evaluate(() => window.__lecturas.push({ codigo: '00603', nombre: 'Pedro Vega Cruz', ms: 500 }));
  await p.click('.cap.sal'); await p.waitForTimeout(2500);
  ok(turnoBD('00603') === `entrada:${hoy}-N,salida:${hoy}-N`, 'salida a las 06:20 del día siguiente: la misma noche (de hoy)');
  ok(!errores.length, 'sin errores de JavaScript' + (errores.length ? ': ' + errores.join(' | ') : ''));
  await b.close();
  console.log(fallas ? fallas + ' FALLAS' : 'TODO BIEN');
  process.exit(fallas ? 1 : 0);
})().catch(e => { console.error('FALLO', e); process.exit(1); });
