// ¿Tiene entrada? Al escanear una SALIDA, la caseta dice a qué hora entró la persona (aunque haya entrado
// por otro teléfono) o que no tiene entrada; al escanear una ENTRADA, si ya tenía una sin salida. También en la
// lectura continua y sin señal (con lo último que bajó el teléfono). Lector simulado; personas inventadas.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const B = 'http://localhost:8794/';
let fallas = 0;
const ok = (c, m) => { if (!c) fallas++; console.log((c ? 'OK   ' : 'FALLA') + ' ' + m); };
const sql = q => execSync(`mariadb --default-character-set=utf8mb4 -N -B -uprueba -pprueba -h127.0.0.1 ca_prueba -e "${q}"`).toString().trim();
const MX = { timeZone: 'America/Mexico_City' };
const hace = min => new Date(Date.now() - min * 60000).toLocaleString('sv-SE', MX);
const reg = (uid, tipo, cap, codigo, nombre, usuario = 'punto2') =>
  sql(`INSERT INTO accesos_registros (uid,tipo,fecha,capturado,recibido,lugar,usuario,usuario_nombre,turno_fecha,turno,nombre,codigo,origen)
       VALUES ('${uid}','${tipo}','${cap.slice(0, 10)}','${cap}',NOW(),'ACCESO','${usuario}','${usuario.toUpperCase()}','${cap.slice(0, 10)}','D','${nombre}','${codigo}','tarjeta')`);

(async () => {
  sql('DELETE FROM accesos_registros');
  const entro = hace(125);                              // entró hace 2 h 05 min, por otro teléfono
  reg('pv1', 'entrada', entro, '00287', 'RAMIRO ESTEBAN VILLARREAL DUARTE');
  reg('pv2', 'entrada', hace(300), '00412', 'ANA SOFIA RUIZ PAEZ');
  reg('pv3', 'salida', hace(200), '00412', 'ANA SOFIA RUIZ PAEZ');   // ya salió
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, timezoneId: 'America/Mexico_City' });
  await ctx.addInitScript(() => {
    localStorage.setItem('ca_sesion', JSON.stringify({ token: 'tok-punto1', rol: 'ACCESOS', usuario: 'punto1', nombre: 'PUNTO1' }));
    window.__lecturas = [];
    window.Capacitor = { isNativePlatform: () => true, Plugins: { LectorTarjeta: {
      leer: () => new Promise(r => setTimeout(() => r(window.__lecturas.shift() || { cancelado: true }), 120)),
      abrir: () => Promise.resolve({})
    } } };
  });
  const p = await ctx.newPage(); const err = []; p.on('pageerror', e => err.push(e.message)); p.on('dialog', d => d.accept());
  await p.goto(B + 'accesos.html'); await p.waitForTimeout(2500);
  const leer = async (codigo, tipo) => {
    await p.evaluate(c => window.__lecturas.push({ codigo: c, nombre: '', ms: 800 }), codigo);
    await p.click('.cap.' + tipo); await p.waitForTimeout(900);
    return (await p.$eval('#hecho', e => e.innerText)).replace(/\s+/g, ' ');
  };
  let t = await leer('00287', 'sal');
  ok(new RegExp('Entró a las ' + entro.slice(11, 16) + ' · 2 h [56] min dentro').test(t), 'salida: dice a qué hora entró (por otra caseta) y cuánto lleva: «' + t + '»');
  t = await leer('00412', 'sal');
  ok(/⚠ Sin entrada registrada/.test(t), 'salida de quien ya había salido: «' + t + '»');
  t = await leer('00733', 'ent');
  ok(!/Ya tenía|Sin entrada/.test(t), 'entrada normal: sin aviso');
  t = await leer('00733', 'ent');
  ok(/⚠ Ya tenía entrada a las \d\d:\d\d sin salida/.test(t), 'otra entrada de quien no ha salido (la anota el teléfono al momento): «' + t + '»');
  // Sin señal: con lo último que bajó el teléfono
  reg('pv4', 'entrada', hace(30), '00500', 'LUIS MORA DIAZ');
  await p.evaluate(() => window.dispatchEvent(new Event('online')));   // la consulta de cada minuto
  await p.waitForTimeout(1500);
  await ctx.setOffline(true);
  t = await leer('00500', 'sal');
  ok(/Entró a las \d\d:\d\d · 3[01] min dentro/.test(t), 'sin señal también sabe a qué hora entró: «' + t + '»');
  await ctx.setOffline(false);
  ok(!err.length, 'sin errores de JavaScript' + (err.length ? ': ' + err.join(' | ') : ''));
  await b.close();
  sql('DELETE FROM accesos_registros');
  console.log(fallas ? fallas + ' FALLAS' : 'TODO BIEN');
  process.exit(fallas ? 1 : 0);
})().catch(e => { console.error('FALLO', e); process.exit(1); });
