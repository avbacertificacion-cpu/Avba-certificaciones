// accesos.html contra la API real (copia local, base de prueba):
//  1) lo pendiente de una cuenta no se envía a nombre de otra que entra en el mismo teléfono
//  2) si al enviar el punto está en otro equipo, el registro no se pierde ni se marca rechazado
//  3) al resolverlo Administración, la pantalla se desbloquea sola y envía lo pendiente
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
  // Estado inicial: punto1→1, punto2→2, punto4→4, punto3 sin punto; ningún equipo ligado
  for (const [pt, u] of [['PUNTO DE CONTROL 1', 'punto1'], ['PUNTO DE CONTROL 2', 'punto2'], ['PUNTO DE CONTROL 4', 'punto4']])
    await post('ACCESOS_PUNTO_ASIGNAR', 'tok-admin', { punto: pt, usuario: u });
  await post('ACCESOS_PUNTO_ASIGNAR', 'tok-admin', { punto: 'PUNTO DE CONTROL 3', usuario: '' });
  for (const n of [1, 2, 3, 4]) await post('ACCESOS_EQUIPO_LIBERAR', 'tok-admin', { punto: 'PUNTO DE CONTROL ' + n });
  sql("DELETE FROM accesos_registros WHERE codigo LIKE '5550%'");
  ok(sql("SELECT COUNT(*) FROM accesos_puntos WHERE dispositivo IS NOT NULL AND dispositivo<>''") === '0', 'inicio: ningún equipo ligado');
  const b = await chromium.launch();

  // ── 1) Pendiente de punto4 y entra punto1 en el mismo teléfono ──
  const P = await equipo(b, ses('punto4'));
  ok((await P.p.inputValue('#lugar')) === 'PUNTO DE CONTROL 4', 'el teléfono P entra como Punto 4');
  await P.ctx.setOffline(true);
  await leerTarjeta(P.p, '55500001', 'Mario Lopez Ruiz');
  ok((await badges(P.p)).some(t => /55500001/.test(t) && /En cola/.test(t)), 'sin señal: queda «En cola» en el teléfono');
  // Cierra sesión y entra la cuenta del Punto 1 antes de que vuelva la señal
  await P.p.evaluate((s) => localStorage.setItem('ca_sesion', JSON.stringify(s)), ses('punto1'));
  await P.ctx.setOffline(false);
  await P.p.waitForTimeout(2500);
  await P.p.reload(); await P.p.waitForTimeout(4000);
  ok((await P.p.inputValue('#lugar')) === 'PUNTO DE CONTROL 1', 'ahora el teléfono P está como Punto 1');
  ok(enServidor('55500001') === '', 'lo pendiente del Punto 4 NO se envió a nombre del Punto 1 (' + (enServidor('55500001') || 'no está en el servidor') + ')');
  ok(!(await badges(P.p)).some(t => /55500001/.test(t)) && await P.p.isHidden('#chip-cola'), 'y no aparece en la lista ni en «por enviar» del Punto 1');
  // Vuelve a entrar punto4 (el teléfono P sigue siendo el del Punto 4) → lo suyo sale solo
  await cambiarCuenta(P.p, 'punto4');
  await P.p.waitForTimeout(3500);
  ok(enServidor('55500001') === 'punto4|PUNTO DE CONTROL 4', 'al volver a entrar el Punto 4, se envía a su nombre y en su punto: ' + enServidor('55500001'));
  ok((await badges(P.p)).some(t => /55500001/.test(t) && !/En cola/.test(t)), 'y ya aparece en su lista como registrado');

  // ── 2) A media captura el punto queda en otro equipo ──
  const Q = await equipo(b, ses('punto2'));
  ok(await Q.p.isHidden('#banda-punto') && (await Q.p.inputValue('#lugar')) === 'PUNTO DE CONTROL 2', 'el teléfono Q entra como Punto 2');
  await Q.ctx.setOffline(true);
  await leerTarjeta(Q.p, '55500002', 'Laura Soto Rios');
  // Mientras Q no tiene señal, Administración libera el punto y otro teléfono (R) lo toma
  await post('ACCESOS_EQUIPO_LIBERAR', 'tok-admin', { punto: 'PUNTO DE CONTROL 2' });
  const R = await equipo(b, ses('punto2'));
  ok(await R.p.isHidden('#banda-punto'), 'el teléfono R toma el Punto 2');
  await Q.ctx.setOffline(false);
  await Q.p.evaluate(() => window.dispatchEvent(new Event('online')));
  await Q.p.waitForTimeout(3000);
  ok(/ya está en otro equipo/.test(await aviso(Q.p)) && await bloqueados(Q.p), 'Q: aviso de otro equipo y botones bloqueados');
  const lq = await badges(Q.p);
  ok(lq.some(t => /55500002/.test(t) && /En cola/.test(t)) && !lq.some(t => /No se aceptó/.test(t)), 'Q: el registro sigue «En cola», no «No se aceptó»');
  ok(enServidor('55500002') === '', 'y no se guardó en el punto de otro equipo');
  await Q.p.screenshot({ path: D + 'pend_bloqueado.png' });
  // Administración libera el punto otra vez → Q se desbloquea solo en su siguiente actualización (cada 30 s)
  await R.ctx.close();
  await post('ACCESOS_EQUIPO_LIBERAR', 'tok-admin', { punto: 'PUNTO DE CONTROL 2' });
  await Q.p.waitForTimeout(33000);
  ok(await Q.p.isHidden('#banda-punto') && !(await bloqueados(Q.p)), 'Q: sin recargar, el aviso se quita solo y ENTRADA/SALIDA se habilitan');
  await Q.p.waitForTimeout(2500);
  ok(enServidor('55500002') === 'punto2|PUNTO DE CONTROL 2', 'Q: lo pendiente se envía solo: ' + enServidor('55500002'));
  ok(await Q.p.isHidden('#chip-cola'), 'Q: ya no queda nada por enviar');

  // ── 3) Cuenta sin punto: Administración se lo asigna con la pantalla abierta ──
  const S = await equipo(b, ses('punto3'));
  ok(/punto de control/i.test(await aviso(S.p)) && await bloqueados(S.p), 'S (sin punto): aviso «' + (await aviso(S.p)).slice(0, 70) + '…» y botones bloqueados');
  await post('ACCESOS_PUNTO_ASIGNAR', 'tok-admin', { punto: 'PUNTO DE CONTROL 3', usuario: 'punto3' });
  await S.p.evaluate(() => window.dispatchEvent(new Event('online')));
  await S.p.waitForTimeout(2500);
  ok(await S.p.isHidden('#banda-punto') && !(await bloqueados(S.p)), 'S: al asignarle el punto se desbloquea sola');
  ok((await S.p.inputValue('#lugar')) === 'PUNTO DE CONTROL 3' && /Punto de control 3/.test(await S.p.textContent('#sub-usuario')), 'S: queda en Punto de control 3: ' + await S.p.textContent('#sub-usuario'));
  await leerTarjeta(S.p, '55500003', 'Pedro Vega Cruz');
  await S.p.waitForTimeout(1500);
  ok(enServidor('55500003') === 'punto3|PUNTO DE CONTROL 3', 'S: registra en su punto: ' + enServidor('55500003'));

  const errs = [P, Q, S].flatMap(x => x.p.errores);
  ok(!errs.length, 'sin errores de JavaScript' + (errs.length ? ': ' + errs.join(' | ') : ''));
  await b.close();
  sql("DELETE FROM accesos_registros WHERE codigo LIKE '5550%'");
  console.log(fallas ? fallas + ' FALLAS' : 'TODO BIEN');
  process.exit(fallas ? 1 : 0);
})().catch(e => { console.error('FALLO', e); process.exit(1); });
