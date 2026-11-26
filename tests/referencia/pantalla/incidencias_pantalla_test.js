// Botón INCIDENCIA en la pantalla del guardia (lector de la app simulado, con foto) contra la API
// real local (base de prueba), sin señal, sin credencial, y lo que ve Administración. Datos inventados.
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
const ses = u => ({ token: 'tok-' + u, rol: u === 'admin' ? 'ADMIN' : u === 'planta' ? 'PLANTA' : 'ACCESOS', usuario: u, nombre: u.toUpperCase() });

async function equipo(b, sesion, conLector, movil = true) {
  const ctx = await b.newContext(movil
    ? { timezoneId: 'America/Mexico_City', viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true }
    : { timezoneId: 'America/Mexico_City', viewport: { width: 1280, height: 900 } });
  await ctx.addInitScript(([s, conLector]) => {
    if (!sessionStorage.getItem('ya')) { localStorage.setItem('ca_sesion', JSON.stringify(s)); sessionStorage.setItem('ya', '1'); }
    window.__lecturas = []; window.__opciones = [];
    if (conLector) window.Capacitor = { isNativePlatform: () => true, Plugins: { LectorTarjeta: {
      leer: (o) => { window.__opciones.push(o); return new Promise(r => setTimeout(() => r(window.__lecturas.shift() || { cancelado: true }), 150)); },
      abrir: () => Promise.resolve({})
    } } };
  }, [sesion, conLector]);
  const p = await ctx.newPage();
  p.errores = []; p.on('pageerror', e => p.errores.push(e.message)); p.on('dialog', d => d.accept());
  // Reloj del navegador a las 10:00 de hoy: los guardias de prueba son de día y Administración ve el
  // turno en curso por reloj; así la prueba no depende de la hora a la que se corre.
  await p.clock.setFixedTime(new Date(new Date().toLocaleDateString('sv-SE', { timeZone: 'America/Mexico_City' }) + 'T10:00:00-06:00'));
  await p.goto(B + 'accesos.html'); await p.waitForTimeout(1800);
  return { ctx, p };
}
const tarjetaInc = p => p.$$eval('#lista-inc li', l => l.map(x => x.innerText.replace(/\s+/g, ' ')));

(async () => {
  await post('ACCESOS_PUNTO_ASIGNAR', 'tok-admin', { punto: 'PUNTO DE CONTROL 1', usuario: 'punto1' });
  const b = await chromium.launch();

  // Foto de una credencial ficticia (de pie)
  const g = await b.newPage({ viewport: { width: 360, height: 640 } });
  await g.setContent(`<body style="margin:0;background:#f4f6f9;font-family:Arial;font-weight:bold;padding:20px">
    <div style="background:#1d5fa8;color:#fff;border-radius:30px;text-align:center;font-size:30px;padding:6px;margin:120px 30px 20px">ID: 00287</div>
    <div style="font-size:18px">NOMBRE: RAMIRO ESTEBAN<br>VILLARREAL DUARTE<br>NSS: 12345678901</div></body>`);
  const foto = (await g.screenshot({ type: 'jpeg', quality: 80 })).toString('base64');
  await g.close();

  // ── Administración abre su pantalla antes (para ver el aviso de las nuevas) ──
  const A = await equipo(b, ses('admin'), false, false);
  ok(await A.p.isHidden('#tarjeta-inc'), 'Administración: sin incidencias, la tarjeta no aparece');

  // ── 1) Guardia: INCIDENCIA con la credencial leída ──
  const K = await equipo(b, ses('punto1'), true);
  ok(await K.p.isVisible('.cap.inc') && /INCIDENCIA/.test(await K.p.textContent('.cap.inc')), 'el guardia ve el botón INCIDENCIA');
  await K.p.screenshot({ path: D + 'inc_botones.png' });
  await K.p.evaluate(f => window.__lecturas.push({ codigo: '00287', nombre: 'Ramiro Esteban Villarreal Duarte', foto: f, ms: 900 }), foto);
  await K.p.click('.cap.inc'); await K.p.waitForTimeout(800);
  ok(/^INCIDENCIA · PUNTO DE CONTROL 1$/.test(await K.p.evaluate(() => window.__opciones[0].titulo)), 'el lector se abre con el título «INCIDENCIA · PUNTO DE CONTROL 1»');
  ok(await K.p.isVisible('#v-inc'), 'después de leer, se abre la ventana «¿Qué pasó?»');
  ok((await K.p.inputValue('#inc-id')) === '00287' && /ramiro esteban/i.test(await K.p.inputValue('#inc-nombre')) && await K.p.isVisible('#inc-img'),
     'con el ID, el nombre y la foto de la credencial');
  await K.p.click('#inc-guardar');
  ok(/Elige qué pasó/.test(await K.p.textContent('#inc-err')), 'sin elegir el tipo: «Elige qué pasó.»');
  await K.p.click('#inc-tipos [data-v="ALCOHOLIMETRO"]');
  await K.p.fill('#inc-detalle', 'Prueba positiva 0.4, se le negó el acceso');
  await K.p.screenshot({ path: D + 'inc_ventana.png' });
  await K.p.click('#inc-guardar'); await K.p.waitForTimeout(300);
  const hecho = await K.p.$eval('#hecho', e => ({ vis: !e.hidden, txt: e.innerText.replace(/\s+/g, ' '), clase: e.className }));
  ok(hecho.vis && /INCIDENCIA REGISTRADA/.test(hecho.txt) && /Alcoholímetro positivo/.test(hecho.txt) && /incidencia/.test(hecho.clase), 'confirmación: «' + hecho.txt.slice(0, 80) + '»');
  await K.p.screenshot({ path: D + 'inc_hecho.png' });
  await K.p.waitForTimeout(3500);
  ok(sql("SELECT CONCAT(tipo,'|',codigo,'|',lugar,'|',(foto<>''),'|',detalle) FROM accesos_incidencias") === 'ALCOHOLIMETRO|00287|PUNTO DE CONTROL 1|1|Prueba positiva 0.4, se le negó el acceso',
     'en el servidor: tipo, ID, punto, foto y descripción');
  let filas = await tarjetaInc(K.p);
  ok(filas.length === 1 && /ALCOHOLÍMETRO POSITIVO/.test(filas[0]) && /RAMIRO ESTEBAN VILLARREAL DUARTE · 00287/.test(filas[0]), 'la tarjeta «Tus incidencias» la muestra: ' + (filas[0] || '').slice(0, 90));
  const tot = await K.p.$eval('#totales', e => e.innerText.replace(/\s+/g, ' '));
  ok(/^0 Entradas 0 Salidas/.test(tot.trim()) || !/[1-9] Entradas/.test(tot), 'no cuenta como entrada ni salida: ' + tot.slice(0, 40));
  ok(sql('SELECT COUNT(*) FROM accesos_registros') === '0', 'ni en la tabla de registros');

  // ── 2) Sin señal: queda en la cola del teléfono y sale al volver ──
  await K.ctx.setOffline(true);
  await K.p.evaluate(f => window.__lecturas.push({ codigo: '00412', nombre: 'Ana Sofia Ruiz Paez', foto: f, ms: 700 }), foto);
  await K.p.click('.cap.inc'); await K.p.waitForTimeout(700);
  await K.p.click('#inc-tipos [data-v="CONDUCTA"]');
  await K.p.click('#inc-guardar'); await K.p.waitForTimeout(2500);
  filas = await tarjetaInc(K.p);
  ok(filas.some(t => /MALA CONDUCTA/.test(t) && /En cola/.test(t)), 'sin señal: «Mala conducta» queda «En cola»');
  ok(sql("SELECT COUNT(*) FROM accesos_incidencias WHERE tipo='CONDUCTA'") === '0', 'todavía no llega al servidor');
  await K.ctx.setOffline(false);
  await K.p.evaluate(() => window.dispatchEvent(new Event('online'))); await K.p.waitForTimeout(4000);
  ok(sql("SELECT CONCAT(codigo,'|',(foto<>'')) FROM accesos_incidencias WHERE tipo='CONDUCTA'") === '00412|1', 'al volver la señal se envía (con su foto)');
  filas = await tarjetaInc(K.p);
  ok(filas.length === 2 && !filas.some(t => /En cola/.test(t)), 'y la tarjeta ya no la marca en cola');

  // ── 3) Sin credencial: se cierra el lector y se reporta con el nombre ──
  await K.p.click('.cap.inc'); await K.p.waitForTimeout(700);      // el lector regresa «cancelado»
  ok(await K.p.isVisible('#v-inc') && (await K.p.inputValue('#inc-id')) === '' && await K.p.isHidden('#inc-img'), 'sin credencial: la ventana se abre vacía');
  await K.p.click('#inc-tipos [data-v="OTRO"]');
  await K.p.click('#inc-guardar');
  ok(/quién es/.test(await K.p.textContent('#inc-err')), 'pide saber quién es');
  await K.p.fill('#inc-nombre', 'Visitante sin credencial');
  await K.p.click('#inc-guardar');
  ok(/Describe/.test(await K.p.textContent('#inc-err')), '«Otro» pide la descripción');
  await K.p.fill('#inc-detalle', 'Quiso entrar por la caseta sin cita');
  await K.p.click('#inc-guardar'); await K.p.waitForTimeout(3500);
  ok(sql("SELECT CONCAT(nombre,'|',origen) FROM accesos_incidencias WHERE tipo='OTRO'") === 'VISITANTE SIN CREDENCIAL|sin_id', 'guardada con el nombre escrito');
  ok(!K.p.errores.length, 'sin errores de JavaScript (guardia)' + (K.p.errores.length ? ': ' + K.p.errores.join(' | ') : ''));
  await K.p.screenshot({ path: D + 'inc_guardia_lista.png', fullPage: true });

  // ── 4) Administración: aviso de nuevas, tarjeta e historial de la persona ──
  await A.p.waitForTimeout(31000);                                  // la lista se actualiza sola cada 30 s
  const aviso = await A.p.$eval('#banda-inc', e => e.hidden ? '' : e.innerText.replace(/\s+/g, ' '));
  ok(/3 incidencias nuevas/.test(aviso), 'franja de aviso a Administración: «' + aviso.slice(0, 110) + '»');
  await A.p.screenshot({ path: D + 'inc_aviso_admin.png' });
  await A.p.click('#banda-inc');
  ok(await A.p.isHidden('#banda-inc'), 'al tocarla se quita y lleva a la tarjeta');
  filas = await tarjetaInc(A.p);
  ok(filas.length === 3 && filas.every(t => /CASETA PUNTO 1/.test(t)), 'Administración ve las 3, con quién las reportó');
  await A.p.screenshot({ path: D + 'inc_admin.png' });
  await A.p.click('#lista-inc [data-persona="00287"]'); await A.p.waitForTimeout(1200);
  const per = await A.p.$eval('#per-inc', e => e.hidden ? '' : e.innerText.replace(/\s+/g, ' '));
  ok(/Incidencias \(1\)/.test(per) && /ALCOHOLÍMETRO POSITIVO/.test(per) && /negó el acceso/.test(per), 'historial del 00287: ' + per.slice(0, 90));
  ok(await A.p.$$eval('#per-inc img[data-foto-inc]', l => l.length) === 1, 'con la foto de la credencial');
  await A.p.screenshot({ path: D + 'inc_persona.png' });
  await A.p.click('#per-cerrar');
  // Borrar (Administración)
  await A.p.click('#lista-inc [data-borrar-inc]'); await A.p.waitForTimeout(1500);
  ok(sql('SELECT COUNT(*) FROM accesos_incidencias') === '2' && (await tarjetaInc(A.p)).length === 2, 'Administración elimina una incidencia');
  ok(!A.p.errores.length, 'sin errores de JavaScript (Administración)' + (A.p.errores.length ? ': ' + A.p.errores.join(' | ') : ''));

  // ── 5) En el navegador sin la app: foto de la cámara y la misma ventana ──
  const N = await equipo(b, ses('admin'), false);
  const [fc] = await Promise.all([N.p.waitForEvent('filechooser'), N.p.click('.cap.inc')]);
  await fc.setFiles({ name: 'cred.jpg', mimeType: 'image/jpeg', buffer: Buffer.from(foto, 'base64') });
  await N.p.waitForTimeout(1000);
  ok(await N.p.isVisible('#v-inc') && await N.p.isVisible('#inc-img') && (await N.p.inputValue('#inc-id')) === '', 'navegador: la foto va a la ventana y el ID se escribe a mano');
  await N.p.fill('#inc-id', '287'); await N.p.click('#inc-tipos [data-v="PERSONA"]');
  await N.p.click('#inc-guardar'); await N.p.waitForTimeout(3500);
  ok(sql("SELECT CONCAT(codigo,'|',origen,'|',(foto<>'')) FROM accesos_incidencias WHERE tipo='PERSONA'") === '287|manual|1', 'guardada con el ID escrito y la foto');
  ok(!N.p.errores.length, 'sin errores de JavaScript (navegador)' + (N.p.errores.length ? ': ' + N.p.errores.join(' | ') : ''));

  await b.close();
  console.log(fallas ? fallas + ' FALLAS' : 'TODO BIEN');
  process.exit(fallas ? 1 : 0);
})().catch(e => { console.error('FALLO', e); process.exit(1); });
