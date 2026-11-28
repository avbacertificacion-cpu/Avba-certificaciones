// Vista «En planta» de administración contra la API real local (base de prueba sembrada con sembrar_planta.php)
const { chromium } = require('playwright');
const fs = require('fs');
const B = 'http://localhost:8794/';
const D = __dirname + '/';
let fallas = 0;
const ok = (c, m) => { if (!c) fallas++; console.log((c ? 'OK   ' : 'FALLA') + ' ' + m); };
const ses = u => ({ token: 'tok-' + u, rol: u === 'admin' ? 'ADMIN' : 'ACCESOS', usuario: u, nombre: u.toUpperCase() });

async function abrir(b, sesion, vista, lector) {
  const ctx = await b.newContext({ timezoneId: 'America/Mexico_City', viewport: vista, acceptDownloads: true, ...(vista.width < 500 ? { isMobile: true, hasTouch: true } : {}) });
  await ctx.addInitScript(([s, lector]) => {
    if (!sessionStorage.getItem('ya')) { localStorage.setItem('ca_sesion', JSON.stringify(s)); sessionStorage.setItem('ya', '1'); }
    if (lector) window.Capacitor = { isNativePlatform: () => true, Plugins: { LectorTarjeta: { leer: () => Promise.resolve({ cancelado: true }), abrir: () => Promise.resolve({}) } } };
  }, [sesion, lector]);
  const p = await ctx.newPage();
  p.errores = []; p.pedidos = [];
  p.on('pageerror', e => p.errores.push(e.message));
  p.on('request', r => p.pedidos.push(r.url()));
  p.on('dialog', d => d.accept());
  await p.goto(B + 'accesos.html'); await p.waitForTimeout(1500);
  return { ctx, p };
}
const filas = p => p.$$eval('#lista li.planta', l => l.map(x => x.innerText.replace(/\s+/g, ' ').trim()));

(async () => {
  const b = await chromium.launch();
  // ── Administración en el teléfono ──
  const A = await abrir(b, ses('admin'), { width: 390, height: 844 });
  ok(await A.p.isVisible('#f-planta'), 'administración ve el filtro «En planta»');
  await A.p.click('#f-planta'); await A.p.waitForTimeout(1500);
  ok(await A.p.isHidden('#nav-turno') && await A.p.isHidden('#totales') && await A.p.isVisible('#planta-cab'), 'en planta: se ocultan turno y contadores del turno');
  ok((await A.p.textContent('#tit-lista')) === 'En planta ahora', 'título: ' + await A.p.textContent('#tit-lista'));
  let f = await filas(A.p);
  ok((await A.p.textContent('#planta-n')) === '4' && f.length === 4, 'cuatro personas en planta: ' + await A.p.textContent('#planta-n'));
  ok(!f.some(t => /00412|ANA SOFIA/.test(t)), 'quien ya salió (por otro punto) NO aparece');
  ok(/^00500 .*Cumple 16 h en planta a las \d\d:\d\d/.test(f[0]) && /^00316 PEDRO VEGA CRUZ Entró \d\d:\d\d · Punto de control 2 · 30 min dentro/.test(f[1]),
     'primero quien está por cumplir 16 h (15 h dentro), luego la más reciente, con su ID: «' + f[0] + '» · «' + f[1] + '»');
  ok(f.some(t => /^00500 .*Cumple 16 h/.test(t)) && /cumple 16 h en planta en menos de 2 h/i.test(await A.p.textContent('#banda-16')), 'quien lleva 15 h queda marcado y sale la franja del aviso de 16 h');
  ok(await A.p.$eval('#lista li.planta .id-azul', e => getComputedStyle(e).backgroundColor) === 'rgb(29, 95, 168)', 'el ID va en azul, como en la credencial');
  await A.p.screenshot({ path: D + 'planta_tel.png' });

  // Buscar por ID (con o sin ceros) y por nombre
  await A.p.fill('#planta-buscar', '316'); await A.p.waitForTimeout(200);
  f = await filas(A.p);
  ok(f.length === 1 && /^00316/.test(f[0]), 'buscar «316» encuentra al 00316');
  await A.p.fill('#planta-buscar', 'maria'); await A.p.waitForTimeout(200);
  f = await filas(A.p);
  ok(f.length === 1 && /MARIA FERNANDA RIOS/.test(f[0]), 'buscar por nombre sin acentos: «maria»');
  await A.p.fill('#planta-buscar', '00412'); await A.p.waitForTimeout(200);
  ok(/Nadie en planta con «00412»/.test(await A.p.textContent('#lista')) && await A.p.isVisible('#lista button[data-persona="00412"]'), 'quien ya salió: «Nadie en planta» y botón para ver sus movimientos');
  await A.p.click('#lista button[data-persona="00412"]'); await A.p.waitForTimeout(1000);
  const v412 = await A.p.$eval('#v-persona', e => e.innerText.replace(/\s+/g, ' '));
  ok(/ANA SOFIA RUIZ PAEZ/.test(v412) && /Fuera de planta/.test(v412) && /Punto de control 2/.test(v412) && /Punto de control 3/.test(v412) && /5 h dentro/.test(v412), 'movimientos del 00412: entró por el 2, salió por el 3, 5 h dentro');
  ok(await A.p.isHidden('#per-salida'), 'a quien ya salió no se le ofrece «Dar salida»');
  await A.p.click('#per-cerrar');
  await A.p.fill('#planta-buscar', ''); await A.p.waitForTimeout(200);

  // Movimientos y «Dar salida» del 00500
  await A.p.click('#lista li[data-persona="00500"]'); await A.p.waitForTimeout(1000);
  const v500 = await A.p.$eval('#v-persona', e => e.innerText.replace(/\s+/g, ' '));
  ok(/LUIS MORA DIAZ/.test(v500) && /En planta/.test(v500) && /sigue en planta/.test(v500), 'movimientos del 00500: sigue en planta');
  ok(await A.p.isVisible('#per-salida'), 'administración puede «Dar salida»');
  await A.p.screenshot({ path: D + 'planta_persona.png' });
  await A.p.click('#per-salida'); await A.p.waitForTimeout(1500);
  const v500b = await A.p.$eval('#v-persona', e => e.innerText.replace(/\s+/g, ' '));
  ok(/Fuera de planta/.test(v500b) && /registrada por el administrador de accesos/.test(v500b), 'tras dar salida: fuera de planta, marcada como del administrador de accesos');
  await A.p.click('#per-cerrar'); await A.p.waitForTimeout(800);
  f = await filas(A.p);
  ok(f.length === 3 && !f.some(t => /00500/.test(t)) && (await A.p.textContent('#planta-n')) === '3', 'y ya no aparece en planta (quedan 3)');

  // Un solo acceso: no hay filtro por punto de entrada (los registros de antes conservan su punto)
  ok(await A.p.isHidden('#campo-solo-punto'), 'sin casilla «Sólo este punto de control»');

  // De regreso a la lista del turno: el ID abre sus movimientos
  await A.p.click('#filtro button[data-v="todos"]'); await A.p.waitForTimeout(1500);
  ok(await A.p.isVisible('#nav-turno') && await A.p.isHidden('#planta-cab') && /Registros/.test(await A.p.textContent('#tit-lista')), 'al volver a «Todos» regresa la lista del turno');
  const btnId = await A.p.$('#lista .ver-id');
  ok(!!btnId, 'en la lista del turno el ID se puede tocar');
  if (btnId) { await btnId.click(); await A.p.waitForTimeout(900); ok(await A.p.isVisible('#v-persona') && (await A.p.textContent('#per-id')).length >= 3, 'y abre sus entradas y salidas (ID ' + await A.p.textContent('#per-id') + ')'); await A.p.click('#per-cerrar'); }
  ok(!A.p.errores.length, 'sin errores de JavaScript (administración)' + (A.p.errores.length ? ': ' + A.p.errores.join(' | ') : ''));

  // ── Administración en la computadora: Excel ──
  const C = await abrir(b, ses('admin'), { width: 1280, height: 860 });
  await C.p.click('#f-planta'); await C.p.waitForTimeout(1500);
  const [desc] = await Promise.all([C.p.waitForEvent('download'), C.p.click('#planta-csv')]);
  const ruta = D + 'en-planta.csv'; await desc.saveAs(ruta);
  const csv = fs.readFileSync(ruta, 'utf8');
  ok(/^\ufeffID,Nombre,Entrada,Punto de entrada,Tiempo dentro,Minutos dentro,Revisar/.test(csv) && /"=""00316"""/.test(csv) && csv.trim().split('\n').length === 4, 'Excel: encabezados, ID con sus ceros y 3 personas (' + desc.suggestedFilename() + ')');
  await C.p.screenshot({ path: D + 'planta_pc.png', fullPage: false });
  ok(!C.p.errores.length, 'sin errores de JavaScript (computadora)');

  // ── Caseta con la app: no ve «En planta»; las reglas vienen de la API, no de la demo ──
  const K = await abrir(b, ses('punto2'), { width: 390, height: 844 }, true);
  ok(await K.p.isHidden('#f-planta'), 'la caseta no ve «En planta»');
  ok(K.p.pedidos.some(u => /action=ACCESOS_TARJETA_REGLAS/.test(u)) && !K.p.pedidos.some(u => /demo\//.test(u)), 'la app pide las reglas a la API y nada a la demo');
  ok(!K.p.errores.length, 'sin errores de JavaScript (caseta)');
  await b.close();
  console.log(fallas ? fallas + ' FALLAS' : 'TODO BIEN');
  process.exit(fallas ? 1 : 0);
})().catch(e => { console.error('FALLO', e); process.exit(1); });
