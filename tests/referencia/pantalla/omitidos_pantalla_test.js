// Pantalla «Días omitidos» (sólo Administración), en un teléfono: ver los días con registros,
// omitir el de pruebas (primero dice cuánto se deja de contar), hoy sólo hasta ahora, unas horas,
// volver a contar y el historial. El menú sólo la muestra a Administración. Datos inventados.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const B = 'http://localhost:8794/';
const A = B + 'api/index.php';
const D = __dirname + '/';
let fallas = 0;
const ok = (c, m) => { if (!c) fallas++; console.log((c ? 'OK   ' : 'FALLA') + ' ' + m); };
const sql = q => execSync(`mariadb --default-character-set=utf8mb4 -N -B -uprueba -pprueba -h127.0.0.1 ca_prueba -e "${q}"`).toString().trim();
const hoy = new Date().toLocaleDateString('sv-SE', { timeZone: 'America/Mexico_City' });
const ayer = new Date(new Date(hoy + 'T12:00:00Z').getTime() - 86400e3).toISOString().slice(0, 10);
const hace = min => new Date(Date.now() - min * 60e3).toLocaleString('sv-SE', { timeZone: 'America/Mexico_City' }).replace('T', ' ');
const reg = (uid, tipo, cap, codigo, nombre, usr, usrNom) => sql(
  `INSERT INTO accesos_registros (uid,tipo,fecha,capturado,recibido,lugar,usuario,usuario_nombre,turno_fecha,turno,nombre,codigo,origen)
   VALUES ('${uid}','${tipo}','${cap.slice(0, 10)}','${cap}',NOW(),'ACCESO','${usr}','${usrNom}','${cap.slice(0, 10)}','D','${nombre}','${codigo}','tarjeta')`);
const tablero = async () => (await (await fetch(A + '?action=TABLERO&desde=' + ayer + '&hasta=' + hoy, { headers: { Authorization: 'Bearer tok-cliente' } })).json()).kpis;
const ses = (rol, u) => ({ token: 'tok-' + u, rol, usuario: u, nombre: u.toUpperCase() });

(async () => {
  // Ayer: pruebas con la cuenta de prueba; hoy: lo real
  reg('omp-00000001', 'entrada', `${ayer} 09:00:00`, '00287', 'RAMIRO ESTEBAN VILLARREAL DUARTE', 'punto1', 'CASETA PUNTO 1');
  reg('omp-00000002', 'salida', `${ayer} 10:00:00`, '00287', 'RAMIRO ESTEBAN VILLARREAL DUARTE', 'punto1', 'CASETA PUNTO 1');
  reg('omp-00000003', 'entrada', `${ayer} 09:30:00`, '00777', 'PERSONA DE PRUEBA', 'punto1', 'CASETA PUNTO 1');
  sql(`INSERT INTO accesos_incidencias (uid,tipo,detalle,fecha,capturado,recibido,turno_fecha,turno,codigo,nombre,usuario,usuario_nombre)
       VALUES ('omp-inc00001','OTRO','Prueba del botón','${ayer}','${ayer} 09:40:00',NOW(),'${ayer}','D','00777','PERSONA DE PRUEBA','punto1','CASETA PUNTO 1')`);
  reg('omp-00000004', 'entrada', hace(5), '00316', 'PEDRO VEGA CRUZ', 'punto2', 'CASETA PUNTO 2');
  ok((await tablero()).entradas === 3, 'antes: el tablero cuenta las 3 entradas (2 de prueba)');

  const b = await chromium.launch();
  const tel = async s => {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, timezoneId: 'America/Mexico_City' });
    await ctx.addInitScript(s => localStorage.setItem('ca_sesion', JSON.stringify(s)), s);
    const p = await ctx.newPage();
    p.errores = []; p.on('pageerror', e => p.errores.push(e.message)); p.on('dialog', d => d.accept());
    return { ctx, p };
  };

  // ── El menú ──
  let { ctx, p } = await tel(ses('ADMIN', 'admin'));
  await p.goto(B + 'index.html'); await p.waitForTimeout(800);
  ok(await p.isVisible('#m-omitidos'), 'Administración ve «Días omitidos» en el inicio');
  await p.click('#m-omitidos'); await p.waitForTimeout(1200);
  ok(/omitidos\.html$/.test(p.url()), 'y entra a la pantalla');

  // ── Los días con registros ──
  const filaDe = f => p.locator('#dias .dia', { has: p.locator(`button[data-omitir="${f}"]`) });
  let t = await filaDe(ayer).textContent();
  ok(/2 entradas · 1 salida · 1 incidencia/.test(t) && /de 09:00 a 10:00/.test(t) && /CASETA PUNTO 1/.test(t), 'ayer: cuántos, de qué hora a qué hora y con qué cuenta: ' + t.replace(/\s+/g, ' ').trim());
  ok(/hoy/.test(await filaDe(hoy).textContent()) && /CASETA PUNTO 2/.test(await filaDe(hoy).textContent()), 'hoy aparece marcado y con su cuenta');
  ok(/No hay días omitidos/.test(await p.textContent('#omitidos')), 'todavía no hay días omitidos');
  await p.screenshot({ path: D + 'omitidos_lista.png', fullPage: true });

  // ── Hoy: sólo hasta ahora ──
  await p.click(`button[data-omitir="${hoy}"]`); await p.waitForTimeout(900);
  ok(await p.isVisible('#f-hoy') && /sólo hasta ahora/.test(await p.textContent('#f-hoy')), 'hoy: avisa que se omite sólo hasta ahora');
  ok(/1 entrada · 0 salidas/.test(await p.textContent('#f-rev')), 'y dice cuánto: ' + (await p.textContent('#f-rev')).trim());
  await p.click('#b-cancelar');
  ok(await p.isHidden('#f-omitir'), 'Cancelar cierra sin omitir');

  // ── Omitir ayer ──
  await p.click(`button[data-omitir="${ayer}"]`); await p.waitForTimeout(900);
  ok(/Omitir el .* \d+ /.test(await p.textContent('#f-tit')) && await p.isHidden('#f-hoy'), 'título: ' + await p.textContent('#f-tit'));
  t = await p.textContent('#f-rev');
  ok(/Se dejarán de contar: 2 entradas · 1 salida · 1 incidencia \(2 personas\), de 00:00 a 23:59/.test(t) && /CASETA PUNTO 1/.test(t), 'antes de omitir dice cuánto: ' + t.trim());
  // Unas horas: la revisión cambia
  await p.check('input[name=rango][value=horas]'); await p.fill('#f-desde', '09:15'); await p.fill('#f-hasta', '09:45');
  await p.dispatchEvent('#f-hasta', 'change'); await p.waitForTimeout(900);
  ok(/1 entrada · 0 salidas · 1 incidencia/.test(await p.textContent('#f-rev')), 'sólo de 09:15 a 09:45: ' + (await p.textContent('#f-rev')).trim());
  await p.check('input[name=rango][value=dia]'); await p.waitForTimeout(900);
  await p.fill('#f-motivo', 'Pruebas del lector');
  await p.screenshot({ path: D + 'omitidos_form.png', fullPage: true });
  await p.click('#b-omitir'); await p.waitForTimeout(1300);
  ok(/3 registros y 1 incidencia ya no cuentan/.test(await p.textContent('#toast')), 'aviso: ' + await p.textContent('#toast'));
  t = await p.textContent('#omitidos');
  ok(/todo el día/.test(t) && /Pruebas del lector/.test(t) && /Omitido por ADMIN/.test(t), 'aparece en «Omitidos ahora»: ' + t.replace(/\s+/g, ' ').trim().slice(0, 140));
  ok(await p.locator(`button[data-omitir="${ayer}"]`).count() === 0 && /Omitido/.test(await p.locator('#dias .dia.omitido').first().textContent()), 'ayer queda marcado «Omitido», sin botón');
  ok((await tablero()).entradas === 1, 'el tablero del cliente ya sólo cuenta lo real');
  const ancho = await p.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth);
  ok(ancho, 'en el teléfono no se sale de la pantalla');
  await p.screenshot({ path: D + 'omitidos_hecho.png', fullPage: true });

  // ── Volver a contar ──
  await p.click('button[data-contar]'); await p.waitForTimeout(1300);
  ok(/vuelven a contar/.test(await p.textContent('#toast')) && /No hay días omitidos/.test(await p.textContent('#omitidos')), 'Volver a contar: ' + await p.textContent('#toast'));
  ok(await p.isVisible('#c-hist') && /vuelto a contar por ADMIN/.test(await p.textContent('#historial')), 'queda en el historial');
  ok((await tablero()).entradas === 3, 'el tablero vuelve a contar todo');
  // Otra fecha sin registros
  await p.fill('#otra-fecha', '2020-01-15'); await p.click('#b-otra'); await p.waitForTimeout(900);
  ok(/no hay registros/.test(await p.textContent('#f-err')) && await p.isDisabled('#b-omitir'), 'otra fecha sin registros: no hay nada que omitir');
  ok(!p.errores.length, 'sin errores de JavaScript' + (p.errores.length ? ': ' + p.errores.join(' | ') : ''));
  await ctx.close();

  // ── La planta no ──
  ({ ctx, p } = await tel(ses('PLANTA', 'planta')));
  await p.goto(B + 'index.html'); await p.waitForTimeout(800);
  ok(await p.isHidden('#m-omitidos'), 'el administrador de la planta no ve «Días omitidos»');
  await p.goto(B + 'omitidos.html'); await p.waitForTimeout(600);
  ok(await p.isVisible('#sin-permiso') && await p.isHidden('#todo'), 'y si abre la dirección: «Sólo Administración AVBA omite días»');
  await ctx.close();

  await b.close();
  console.log(fallas ? fallas + ' FALLAS' : 'TODO BIEN');
  process.exit(fallas ? 1 : 0);
})().catch(e => { console.error('FALLO', e); process.exit(1); });
