// Entrada y salida seguidas de la misma persona cerca del cambio de turno (reloj del teléfono a las
// 17:57): las dos deben quedar en el mismo turno, con señal y sin señal. API real local, datos inventados.
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
const a = hora => new Date(`${hoy}T${hora}-06:00`);           // hora de la planta

async function leer(p, hora, tipo, codigo, nombre) {
  await p.clock.setFixedTime(a(hora));
  await p.evaluate(([c, n]) => window.__lecturas.push({ codigo: c, nombre: n, ms: 500 }), [codigo, nombre]);
  await p.click('.cap.' + tipo); await p.waitForTimeout(2200);
}
const bloques = p => p.$$eval('.bloque-turno', l => l.map(x => x.innerText.replace(/\s+/g, ' ')));

(async () => {
  await post('ACCESOS_PUNTO_ASIGNAR', 'tok-admin', { punto: 'PUNTO DE CONTROL 1', usuario: 'punto1' });
  const b = await chromium.launch();
  const ctx = await b.newContext({ timezoneId: 'America/Mexico_City', viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
  await ctx.addInitScript((s) => {
    if (!sessionStorage.getItem('ya')) { localStorage.setItem('ca_sesion', JSON.stringify(s)); sessionStorage.setItem('ya', '1'); }
    window.__lecturas = [];
    window.Capacitor = { isNativePlatform: () => true, Plugins: { LectorTarjeta: {
      leer: () => new Promise(r => setTimeout(() => r(window.__lecturas.shift() || { cancelado: true }), 150)),
      abrir: () => Promise.resolve({})
    } } };
  }, { token: 'tok-punto1', rol: 'ACCESOS', usuario: 'punto1', nombre: 'CASETA PUNTO 1' });
  const p = await ctx.newPage();
  const errores = []; p.on('pageerror', e => errores.push(e.message)); p.on('dialog', d => d.accept());
  await p.clock.setFixedTime(a('17:56:00'));
  await p.goto(B + 'accesos.html'); await p.waitForTimeout(1800);
  ok(/Cambio de turno/.test(await p.textContent('#t-nombre')), 'a las 17:56 la pantalla muestra el cambio de turno (día saliendo, noche entrando)');
  const rangos = await bloques(p);
  ok(/06:00 a 18:00/.test(rangos[0]) && /18:00 a .*06:00/.test(rangos[1]), 'horario de la planta en pantalla: día 06:00 a 18:00 y noche 18:00 a 06:00');

  // ── Con señal: entrada 17:57:26 y salida 17:58:02 ──
  await leer(p, '17:57:26', 'ent', '00601', 'Ramiro Esteban Villarreal Duarte');
  await leer(p, '17:58:02', 'sal', '00601', 'Ramiro Esteban Villarreal Duarte');
  await p.waitForTimeout(2000);
  ok(sql("SELECT GROUP_CONCAT(CONCAT(tipo,':',turno_fecha,'-',turno) ORDER BY id) FROM accesos_registros WHERE codigo='00601'") === `entrada:${hoy}-N,salida:${hoy}-N`,
     'en el servidor: la entrada y la salida quedan en el turno de noche');
  let t = await bloques(p);
  ok(/Turno de día.*0 Entradas 0 Salidas 0 Diferencia/.test(t[0]) && /Turno de noche.*1 Entradas 1 Salidas 0 Diferencia/.test(t[1]),
     'contadores: día 0/0 y noche 1/1 (antes: día 0/1 y noche 1/0)');
  const lista = await p.$$eval('#lista li.reg', l => l.map(x => x.innerText.replace(/\s+/g, ' ')));
  ok(lista.length === 2 && lista.every(x => /Turno de noche/.test(x)), 'en la lista, las dos dicen «Turno de noche»');
  await p.screenshot({ path: D + 'turnos_con_senal.png' });

  // ── Sin señal: el mismo caso con otra persona; mientras está en cola ya se cuenta bien ──
  await ctx.setOffline(true);
  await leer(p, '17:59:10', 'ent', '00602', 'Ana Sofia Ruiz Paez');
  await leer(p, '17:59:40', 'sal', '00602', 'Ana Sofia Ruiz Paez');
  t = await bloques(p);
  ok(/Turno de día.*0 Entradas 0 Salidas/.test(t[0]) && /Turno de noche.*2 Entradas 2 Salidas/.test(t[1]), 'sin señal (en cola): día 0/0 y noche 2/2');
  await ctx.setOffline(false);
  await p.evaluate(() => window.dispatchEvent(new Event('online'))); await p.waitForTimeout(4000);
  ok(sql("SELECT GROUP_CONCAT(CONCAT(tipo,':',turno) ORDER BY id) FROM accesos_registros WHERE codigo='00602'") === 'entrada:N,salida:N', 'al volver la señal, el servidor las guarda igual: noche y noche');
  t = await bloques(p);
  ok(/Turno de noche.*2 Entradas 2 Salidas/.test(t[1]) && /Turno de día.*0 Entradas 0 Salidas/.test(t[0]), 'y los contadores no cambian al enviarse');

  // ── Alguien del turno de día que sale a las 18:00 sin entrada leída hoy: cuenta para el día ──
  await leer(p, '18:00:30', 'sal', '00603', 'Pedro Vega Cruz');
  await p.waitForTimeout(1500);
  ok(sql("SELECT turno FROM accesos_registros WHERE codigo='00603'") === 'D', 'salida sin entrada: turno de día (como siempre)');
  ok(!errores.length, 'sin errores de JavaScript' + (errores.length ? ': ' + errores.join(' | ') : ''));
  await b.close();
  console.log(fallas ? fallas + ' FALLAS' : 'TODO BIEN');
  process.exit(fallas ? 1 : 0);
})().catch(e => { console.error('FALLO', e); process.exit(1); });
