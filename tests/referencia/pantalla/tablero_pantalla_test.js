// Pantalla del tablero (tablero.html) con la API real local y un mes de datos inventados
// (php/sembrar_tablero.php): el cliente entra directo al tablero, cifras, gráficas, periodos,
// «Ver datos», buscar quién está dentro, descargas de Excel y PDF (con las gráficas) y el teléfono.
const { chromium } = require('playwright');
const fs = require('fs');
const B = 'http://localhost:8794/';
const D = __dirname + '/';
let fallas = 0;
const ok = (c, m) => { if (!c) fallas++; console.log((c ? 'OK   ' : 'FALLA') + ' ' + m); };
const hoy = new Date().toLocaleDateString('sv-SE', { timeZone: 'America/Mexico_City' });
const dia = n => new Date(new Date(hoy + 'T12:00:00Z').getTime() - n * 86400e3).toISOString().slice(0, 10);

(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 1280, height: 900 }, timezoneId: 'America/Mexico_City', acceptDownloads: true });
  const p = await ctx.newPage();
  const errores = []; p.on('pageerror', e => errores.push(e.message));

  // ── El cliente entra con su contraseña y va directo al tablero ──
  await p.goto(B + 'login.html');
  await p.fill('#usuario', 'cliente'); await p.fill('#password', 'prueba-local');
  await p.click('#f-login button[type=submit]');
  await p.waitForURL(/tablero\.html/); await p.waitForTimeout(1500);
  ok(/tablero\.html$/.test(p.url()), 'el cliente entra directo al tablero');
  ok(/CLIENTE DE PRUEBA · Cliente/.test(await p.textContent('#sub')), 'encabezado: ' + await p.textContent('#sub'));
  ok(await p.getAttribute('[data-p="7"]', 'aria-pressed') === 'true' && /7 días/.test(await p.textContent('#periodo-txt')), 'empieza con los últimos 7 días');
  const kpis = await p.$$eval('.kpi', l => l.map(x => x.innerText.replace(/\s+/g, ' ')));
  ok(kpis.length === 6 && /^Dentro de la planta ahora \d+/.test(kpis[0]) && /Entradas \d/.test(kpis[1]) && /Incidencias \d/.test(kpis[5]), 'seis cifras: ' + kpis[0] + ' | ' + kpis[1]);
  const graficas = async () => p.$$eval('#v-accesos .graf[data-g]', l => l.filter(c => !c.hidden).map(c => ({ g: c.dataset.g, chart: !!Chart.getChart(c.querySelector('canvas')), vacio: !c.querySelector('.vacio').hidden })));
  let g = await graficas();
  ok(g.length === 6 && g.every(x => x.chart && !x.vacio), 'seis gráficas dibujadas: ' + g.map(x => x.g).join(', '));
  ok(/Máximo en cada hora/.test(await p.textContent('[data-g="ocupacion"] [data-sub]')), 'con 7 días, las personas dentro van hora por hora');
  await p.screenshot({ path: D + 'tab_7dias.png', fullPage: true });

  // ── Periodos ──
  await p.click('[data-p="30"]'); await p.waitForTimeout(1500);
  ok(/30 días/.test(await p.textContent('#periodo-txt')) && /Máximo de cada día/.test(await p.textContent('[data-g="ocupacion"] [data-sub]')), '30 días: el máximo de cada día');
  const e30 = +(await p.textContent('.kpi:nth-child(2) .v')).replace(/\D/g, '');
  await p.click('[data-p="hoy"]'); await p.waitForTimeout(1200);
  g = await graficas();
  ok(!g.some(x => x.g === 'dia') && /1 día/.test(await p.textContent('#periodo-txt')), 'hoy: sin la gráfica por día (la cuenta la de por hora)');
  const eHoy = +(await p.textContent('.kpi:nth-child(2) .v')).replace(/\D/g, '');
  ok(e30 > eHoy && eHoy > 0, `las entradas cambian con el periodo (30 días ${e30}, hoy ${eHoy})`);
  await p.click('[data-p="otro"]');
  ok(await p.isVisible('#fechas'), '«Otras fechas» muestra desde y hasta');
  await p.fill('#desde', dia(70)); await p.fill('#hasta', dia(60)); await p.click('#fechas button[type=submit]'); await p.waitForTimeout(1200);
  g = await graficas();
  ok(g.every(x => x.vacio) && /Nadie aparece|Sin entradas|Nadie registró/.test(await p.textContent('.rejilla')), 'periodo sin registros: cada gráfica lo dice (sin errores)');
  await p.fill('#desde', dia(200)); await p.click('#fechas button[type=submit]'); await p.waitForTimeout(800);
  ok(/hasta 93 días/.test(await p.textContent('#msg')), 'más de 3 meses: lo avisa');
  await p.fill('#desde', dia(13)); await p.fill('#hasta', dia(0)); await p.click('#fechas button[type=submit]'); await p.waitForTimeout(1300);
  ok(/14 días/.test(await p.textContent('#periodo-txt')) && await p.isHidden('#msg'), 'otras fechas: 14 días');
  await p.reload(); await p.waitForTimeout(1500);
  ok(await p.getAttribute('[data-p="otro"]', 'aria-pressed') === 'true' && /14 días/.test(await p.textContent('#periodo-txt')), 'recuerda el periodo al volver a entrar');

  // ── Ver datos (la tabla de cada gráfica) ──
  await p.click('[data-g="perm"] .ver');
  const filas = await p.$$eval('[data-g="perm"] .tabla-env tbody tr', l => l.map(x => x.innerText.replace(/\s+/g, ' ')));
  ok(filas.length === 5 && /11 a 13 h \d+/.test(filas.join('|')) && await p.textContent('[data-g="perm"] .ver') === 'Ocultar datos', 'ver datos del tiempo dentro: ' + filas.join(' | '));

  // ── Quién está dentro: buscar ──
  const dentro = await p.$$eval('#t-planta tbody tr', l => l.length);
  const unId = (await p.textContent('#t-planta tbody tr td.id')).trim();
  await p.fill('#buscar-planta', unId.replace(/^0+/, ''));
  const encontrados = await p.$$eval('#t-planta tbody tr td.id', l => l.map(x => x.textContent));
  ok(dentro > 0 && encontrados.length === 1 && encontrados[0] === unId, `buscar por ID sin ceros (${unId}): ${encontrados.length} de ${dentro}`);
  await p.fill('#buscar-planta', 'zzz');
  ok(/Nadie coincide/.test(await p.textContent('#t-planta')), 'búsqueda sin resultados');
  await p.fill('#buscar-planta', '');
  ok((await p.$$eval('#t-incs tbody tr', l => l.length)) > 0, 'incidencias del periodo en lista');

  // ── Descargas ──
  let [d] = await Promise.all([p.waitForEvent('download', { timeout: 30000 }), p.click('#b-excel')]);
  await d.saveAs(D + 'tablero_pantalla.xlsx');
  ok(/^TABLERO_.*\.xlsx$/.test(d.suggestedFilename()) && fs.readFileSync(D + 'tablero_pantalla.xlsx').slice(0, 2).toString() === 'PK', 'Descargar Excel: ' + d.suggestedFilename());
  const respPdf = p.waitForResponse(r => r.request().method() === 'POST' && /TABLERO_PDF/.test(r.request().postData() || ''));
  [d] = await Promise.all([p.waitForEvent('download', { timeout: 60000 }), p.click('#b-pdf')]);
  const rp = await (await respPdf).json();
  await d.saveAs(D + 'tablero_pantalla.pdf');
  ok(/\.pdf$/.test(d.suggestedFilename()) && fs.readFileSync(D + 'tablero_pantalla.pdf').slice(0, 4).toString() === '%PDF', 'Descargar PDF: ' + d.suggestedFilename());
  ok(rp.graficas === 6 && rp.paginas >= 2, `el PDF lleva las ${rp.graficas} gráficas (${rp.paginas} páginas)`);
  ok(/Listo: el PDF/.test(await p.textContent('#msg')), 'aviso: ' + await p.textContent('#msg'));

  // ── Menú del cliente y lo que no le toca ──
  await p.goto(B + 'index.html'); await p.waitForTimeout(800);
  ok(await p.isVisible('#m-tablero') && await p.isHidden('#m-accesos') && await p.isHidden('#m-maqueta') && await p.isHidden('#m-cuentas') && await p.isVisible('#m-pass'),
     'menú del cliente: Tablero y Mi contraseña');
  await p.goto(B + 'accesos.html'); await p.waitForURL(/tablero\.html/);
  ok(/tablero\.html$/.test(p.url()), 'si abre el control de accesos, regresa al tablero');
  ok(!errores.length, 'sin errores de JavaScript' + (errores.length ? ': ' + errores.join(' | ') : ''));
  await ctx.close();

  // ── Administración: Tablero en su menú ──
  const ca = await b.newContext({ viewport: { width: 1280, height: 900 }, timezoneId: 'America/Mexico_City' });
  await ca.addInitScript(() => localStorage.setItem('ca_sesion', JSON.stringify({ token: 'tok-admin', rol: 'ADMIN', usuario: 'admin', nombre: 'ADMINISTRACION' })));
  const pa = await ca.newPage();
  await pa.goto(B + 'index.html'); await pa.waitForTimeout(800);
  ok(await pa.isVisible('#m-tablero') && await pa.isVisible('#m-accesos') && await pa.isVisible('#m-cuentas'), 'Administración: Tablero, Control de accesos y Cuentas');
  await pa.goto(B + 'cuentas.html'); await pa.waitForTimeout(800);
  ok((await pa.$$eval('#c-rol option', l => l.map(o => o.value))).includes('CLIENTE'), 'en Cuentas se puede elegir el perfil Cliente');
  await ca.close();

  // ── En el teléfono ──
  const ct = await b.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, timezoneId: 'America/Mexico_City' });
  await ct.addInitScript(() => localStorage.setItem('ca_sesion', JSON.stringify({ token: 'tok-cliente', rol: 'CLIENTE', usuario: 'cliente', nombre: 'CLIENTE DE PRUEBA' })));
  const pt = await ct.newPage();
  const errT = []; pt.on('pageerror', e => errT.push(e.message));
  await pt.goto(B + 'tablero.html'); await pt.waitForTimeout(1500);
  const ancho = await pt.evaluate(() => document.documentElement.scrollWidth);
  ok(ancho <= 390, 'en el teléfono no se sale de lado (' + ancho + ' px)');
  const etq = await pt.evaluate(() => Chart.getChart(document.querySelector('[data-g="top"] canvas')).data.labels);
  ok(etq.every(x => x.length <= 19), 'nombres cortos en «más horas» para que quepan: ' + etq[0]);
  await pt.screenshot({ path: D + 'tab_telefono.png', fullPage: true });
  ok(!errT.length, 'sin errores de JavaScript (teléfono)' + (errT.length ? ': ' + errT.join(' | ') : ''));
  await b.close();
  console.log(fallas ? fallas + ' FALLAS' : 'TODO BIEN');
  process.exit(fallas ? 1 : 0);
})().catch(e => { console.error('FALLO', e); process.exit(1); });
