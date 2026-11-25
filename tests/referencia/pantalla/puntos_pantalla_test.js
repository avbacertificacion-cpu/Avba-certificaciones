// accesos.html contra la API real (copia local con base de prueba) y el lector simulado
const { chromium } = require('playwright');
const B = 'http://localhost:8794/';
const D = __dirname + '/';
let fallas = 0;
const ok = (c, m) => { if (!c) fallas++; console.log((c ? 'OK   ' : 'FALLA') + ' ' + m); };

async function equipo(b, sesion, conLector) {
  const ctx = await b.newContext({ timezoneId: 'America/Mexico_City', viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
  await ctx.addInitScript(([s, conLector]) => {
    if (!sessionStorage.getItem('ya')) { localStorage.setItem('avba_session', JSON.stringify(s)); localStorage.setItem('avba_accesos_lugar', JSON.stringify('CASETA 1')); sessionStorage.setItem('ya', '1'); }
    if (conLector) {
      window.__lecturas = JSON.parse(sessionStorage.getItem('lecturas') || '[]'); window.__abiertos = [];
      window.Capacitor = { isNativePlatform: () => true, Plugins: { LectorTarjeta: {
        leer: () => new Promise(r => setTimeout(() => r(window.__lecturas.shift() || { cancelado: true }), 200)),
        abrir: (o) => { window.__abiertos.push(o.url); return Promise.resolve({}); }
      } } };
    }
  }, [sesion, conLector]);
  const p = await ctx.newPage();
  p.errores = [];
  p.on('pageerror', e => p.errores.push(e.message));
  p.on('dialog', d => d.accept());
  await p.goto(B + 'accesos.html'); await p.waitForTimeout(1500);
  return { ctx, p };
}
const caseta2 = { token: 'tok-punto2', rol: 'ACCESOS', usuario: 'punto2', nombre: 'CASETA PUNTO 2' };

(async () => {
  const b = await chromium.launch();
  // ── Equipo A: el guardia del Punto 2 entra ──
  let A = await equipo(b, caseta2, true);
  ok((await A.p.inputValue('#lugar')) === 'PUNTO DE CONTROL 2' && await A.p.$eval('#lugar', e => e.readOnly), 'entra directo a su punto: ' + await A.p.inputValue('#lugar') + ' (sin poder cambiarlo)');
  ok(/Punto de control 2/.test(await A.p.textContent('#sub-usuario')), 'encabezado: ' + await A.p.textContent('#sub-usuario'));
  ok(await A.p.isHidden('#banda-punto'), 'sin aviso de equipo');
  ok(await A.p.isHidden('#err-lista'), 'la lista carga sin «Acción no reconocida»');
  await A.p.evaluate(() => window.__lecturas.push({ codigo: '27716004', nombre: 'Sergio Ibarra Montes', ms: 700 }));
  await A.p.click('.cap.ent'); await A.p.waitForTimeout(3500);
  await A.p.evaluate(() => window.__lecturas.push({ codigo: '77001234', nombre: 'Ana Ruiz Pérez', ms: 700 }));
  await A.p.click('.cap.ent'); await A.p.waitForTimeout(3500);
  let lista = await A.p.$$eval('#lista li.reg', l => l.map(x => x.innerText.replace(/\s+/g, ' ')));
  ok(lista.length === 2 && lista.some(t => /SERGIO IBARRA MONTES · 27716004/.test(t)) && lista.every(t => /PUNTO DE CONTROL 2/.test(t)), 'registrados y listados desde el servidor: ' + lista.length);
  const tot = await A.p.$eval('#totales', e => e.innerText.replace(/\s+/g, ' '));
  ok(/^2 Entradas/.test(tot.trim()) || /2 Entradas/.test(tot), 'contadores: ' + tot.slice(0, 60));
  await A.p.screenshot({ path: D + 'pt_caseta.png' });
  // Cerrar sesión y volver a entrar: no se pierde nada
  await A.p.evaluate(() => { localStorage.removeItem('avba_session'); });
  await A.p.evaluate((s) => localStorage.setItem('avba_session', JSON.stringify(s)), caseta2);
  await A.p.reload(); await A.p.waitForTimeout(1800);
  lista = await A.p.$$eval('#lista li.reg', l => l.map(x => x.innerText.replace(/\s+/g, ' ')));
  ok(lista.length === 2 && (await A.p.inputValue('#lugar')) === 'PUNTO DE CONTROL 2', 'al volver a entrar sigue su avance: ' + lista.length + ' registros en ' + await A.p.inputValue('#lugar'));
  ok(!A.p.errores.length, 'sin errores de JavaScript (equipo A)' + (A.p.errores.length ? ': ' + A.p.errores.join(' | ') : ''));

  // ── Equipo B: la misma cuenta en otro teléfono ──
  let Bq = await equipo(b, caseta2, true);
  const aviso = await Bq.p.$eval('#banda-punto', e => e.hidden ? '' : e.textContent);
  ok(/ya está en otro equipo/.test(aviso), 'otro equipo: aviso «' + aviso.slice(0, 90) + '…»');
  ok(await Bq.p.$eval('.cap.ent', e => e.disabled) && await Bq.p.$eval('.cap.sal', e => e.disabled), 'otro equipo: ENTRADA y SALIDA bloqueadas');
  await Bq.p.screenshot({ path: D + 'pt_bloqueado.png' });

  // ── Administración: ve los puntos y libera el equipo del Punto 2 ──
  const Adm = await equipo(b, { token: 'tok-admin', rol: 'ADMIN', usuario: 'admin', nombre: 'ADMINISTRACION' }, false);
  ok(await Adm.p.isVisible('#tarjeta-puntos'), 'administración ve «Puntos de control»');
  const filas = await Adm.p.$$eval('#puntos-lista .punto', l => l.map(x => x.innerText.replace(/\s+/g, ' ')));
  ok(filas.length === 4 && /Punto de control 2.*Equipo: App AVBA Caseta/.test(filas[1]), 'Punto 2 con su equipo: ' + (filas[1] || '').slice(0, 110));
  const lugares = await Adm.p.$$eval('#lugares option', l => l.map(o => o.value));
  ok(['PUNTO DE CONTROL 1', 'PUNTO DE CONTROL 4'].every(x => lugares.includes(x)), 'el punto de control para filtrar ofrece los 4 puntos');
  await Adm.p.screenshot({ path: D + 'pt_admin.png', fullPage: true });
  await Adm.p.click('#puntos-lista [data-liberar="PUNTO DE CONTROL 2"]'); await Adm.p.waitForTimeout(1200);
  ok(/Sin equipo/.test((await Adm.p.$$eval('#puntos-lista .punto', l => l.map(x => x.innerText)))[1]), 'liberado: el Punto 2 queda sin equipo');
  // El equipo B entra ahora sí
  await Bq.p.reload(); await Bq.p.waitForTimeout(1800);
  ok(await Bq.p.isHidden('#banda-punto') && !(await Bq.p.$eval('.cap.ent', e => e.disabled)), 'tras liberar, el equipo B ya puede registrar');
  ok((await Bq.p.$$eval('#lista li.reg', l => l.length)) === 2, 'y ve lo que lleva el punto (2 registros)');
  // y el A queda fuera
  await A.p.reload(); await A.p.waitForTimeout(1800);
  ok(/ya está en otro equipo/.test(await A.p.$eval('#banda-punto', e => e.textContent)), 'el equipo A ahora queda bloqueado');
  // Asignar desde la tarjeta: Punto 4 ← punto4
  await Adm.p.selectOption('#puntos-lista select[data-punto="PUNTO DE CONTROL 4"]', 'punto4'); await Adm.p.waitForTimeout(1200);
  ok(/punto4/.test((await Adm.p.$$eval('#puntos-lista .punto', l => l.map(x => x.innerText)))[3] || '') || (await Adm.p.$eval('#puntos-lista select[data-punto="PUNTO DE CONTROL 4"]', s => s.value)) === 'punto4', 'asignar la cuenta del Punto 4 desde la tarjeta');
  ok(!Adm.p.errores.length && !Bq.p.errores.length, 'sin errores de JavaScript (admin y equipo B)');
  await b.close();
  console.log(fallas ? fallas + ' FALLAS' : 'TODO BIEN');
  process.exit(fallas ? 1 : 0);
})().catch(e => { console.error('FALLO', e); process.exit(1); });
