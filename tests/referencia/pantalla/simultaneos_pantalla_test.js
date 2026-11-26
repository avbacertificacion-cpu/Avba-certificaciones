// Un solo acceso, varios teléfonos a la vez: dos guardias de día, cada uno en su teléfono, leen
// credenciales al mismo tiempo; cada uno ve lo suyo y Administración ve todo, con quién lo capturó.
// API real local (base de prueba), lector de la app simulado, reloj a las 10:00, datos inventados.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const B = 'http://localhost:8794/';
const D = __dirname + '/';
let fallas = 0;
const ok = (c, m) => { if (!c) fallas++; console.log((c ? 'OK   ' : 'FALLA') + ' ' + m); };
const sql = q => execSync(`mariadb --default-character-set=utf8mb4 -N -B -uprueba -pprueba -h127.0.0.1 ca_prueba -e "${q}"`).toString().trim();
const hoy = new Date().toLocaleDateString('sv-SE', { timeZone: 'America/Mexico_City' });
const ses = (u, rol, t) => ({ token: 'tok-' + u, rol, usuario: u, nombre: u.toUpperCase(), turno: t || '' });

async function telefono(b, sesion, conLector, movil = true) {
  const ctx = await b.newContext(movil
    ? { timezoneId: 'America/Mexico_City', viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true }
    : { timezoneId: 'America/Mexico_City', viewport: { width: 1280, height: 900 } });
  await ctx.addInitScript(([s, conLector]) => {
    if (!sessionStorage.getItem('ya')) { localStorage.setItem('ca_sesion', JSON.stringify(s)); sessionStorage.setItem('ya', '1'); }
    window.__lecturas = [];
    if (conLector) window.Capacitor = { isNativePlatform: () => true, Plugins: { LectorTarjeta: {
      leer: () => new Promise(r => setTimeout(() => r(window.__lecturas.shift() || { cancelado: true }), 150)),
      abrir: () => Promise.resolve({})
    } } };
  }, [sesion, conLector]);
  const p = await ctx.newPage();
  p.errores = []; p.on('pageerror', e => p.errores.push(e.message)); p.on('dialog', d => d.accept());
  await p.clock.setFixedTime(new Date(hoy + 'T10:00:00-06:00'));
  await p.goto(B + 'accesos.html'); await p.waitForTimeout(1800);
  return { ctx, p };
}
const leer = (p, codigo, nombre, tipo) => p.evaluate(l => window.__lecturas.push(l), { codigo, nombre, ms: 400 }).then(() => p.click('.cap.' + tipo));

(async () => {
  const b = await chromium.launch();
  const T1 = await telefono(b, ses('punto1', 'ACCESOS', 'D'), true);
  const T2 = await telefono(b, ses('punto2', 'ACCESOS', 'D'), true);
  ok(await T1.p.isHidden('#banda-punto') && await T2.p.isHidden('#banda-punto'), 'los dos teléfonos entran sin bloqueo («ya está en otro equipo» ya no existe)');
  ok(/^Acceso · Turno de día · PUNTO1$/.test(await T1.p.textContent('#sub-usuario')), 'encabezado: «' + await T1.p.textContent('#sub-usuario') + '»');
  ok(await T1.p.isHidden('#tarjeta-lugar'), 'no hay punto de control que elegir (un solo acceso)');
  await T1.p.screenshot({ path: D + 'sim_telefono1.png' });

  // Los dos leen a la vez, varias veces
  for (let i = 0; i < 3; i++) {
    await Promise.all([
      leer(T1.p, '0091' + i, 'Persona Uno ' + i, 'ent'),
      leer(T2.p, '0092' + i, 'Persona Dos ' + i, 'ent'),
    ]);
    await Promise.all([T1.p.waitForTimeout(1600), T2.p.waitForTimeout(1600)]);
  }
  await Promise.all([leer(T1.p, '00920', 'Persona Dos 0', 'sal'), leer(T2.p, '00910', 'Persona Uno 0', 'sal')]);   // sale con el otro guardia
  await T1.p.waitForTimeout(3500);
  ok(sql("SELECT COUNT(*) FROM accesos_registros") === '8' && sql("SELECT GROUP_CONCAT(DISTINCT lugar) FROM accesos_registros") === 'ACCESO', '8 registros de los dos teléfonos, todos en el acceso');
  ok(sql("SELECT GROUP_CONCAT(CONCAT(usuario,':',n) ORDER BY usuario) FROM (SELECT usuario, COUNT(*) n FROM accesos_registros GROUP BY usuario) t") === 'punto1:4,punto2:4', 'cada uno con su cuenta (4 y 4)');
  const l1 = await T1.p.$$eval('#lista li.reg', l => l.map(x => x.innerText.replace(/\s+/g, ' ')));
  ok(l1.length === 4 && l1.every(t => !/PERSONA DOS [12]/.test(t)), 'el guardia 1 ve sólo lo suyo (4)');
  ok(/3 Entradas 1 Salidas/.test((await T1.p.$$eval('.bloque-turno', l => l.map(x => x.innerText.replace(/\s+/g, ' '))))[0]), 'sus contadores: 3 entradas, 1 salida');

  // La misma cuenta en otro teléfono al mismo tiempo
  const T3 = await telefono(b, ses('punto1', 'ACCESOS', 'D'), true);
  await leer(T3.p, '00930', 'Persona Tres', 'ent'); await T3.p.waitForTimeout(3000);
  ok(await T3.p.isHidden('#banda-punto') && sql("SELECT usuario FROM accesos_registros WHERE codigo='00930'") === 'punto1', 'la cuenta del guardia 1 en un tercer teléfono también registra');

  // Administración: todo, con quién lo capturó; «En planta» por ID aunque haya salido con otro guardia
  const A = await telefono(b, ses('admin', 'ADMIN'), false, false);
  const la = await A.p.$$eval('#lista li.reg', l => l.map(x => x.innerText.replace(/\s+/g, ' ')));
  ok(la.length === 9 && la.some(t => /Acceso · CASETA PUNTO 1$/.test(t)) && la.some(t => /Acceso · CASETA PUNTO 2$/.test(t)), 'Administración ve los 9, con el guardia que capturó cada uno');
  ok(await A.p.isHidden('#tarjeta-lugar') && await A.p.isHidden('#campo-solo-punto') && await A.p.isHidden('#tarjeta-puntos').catch(() => true), 'sin selector de punto ni tarjeta «Puntos de control»');
  await A.p.click('#f-planta'); await A.p.waitForTimeout(1500);
  ok((await A.p.textContent('#planta-n')) === '5', 'en planta: 5 personas (entraron 7, salieron 2 con el otro guardia)');
  await A.p.screenshot({ path: D + 'sim_admin.png' });
  for (const [n, x] of [['teléfono 1', T1], ['teléfono 2', T2], ['teléfono 3', T3], ['Administración', A]]) ok(!x.p.errores.length, 'sin errores de JavaScript (' + n + ')' + (x.p.errores.length ? ': ' + x.p.errores.join(' | ') : ''));
  await b.close();
  console.log(fallas ? fallas + ' FALLAS' : 'TODO BIEN');
  process.exit(fallas ? 1 : 0);
})().catch(e => { console.error('FALLO', e); process.exit(1); });
