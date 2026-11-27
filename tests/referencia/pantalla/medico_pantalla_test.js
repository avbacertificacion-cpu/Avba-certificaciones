// Pantalla del servicio médico (medico.html) en un teléfono, con la app simulada: escanear la
// credencial (o escribir el ID, o sin credencial), los datos de la base, la toma con apto / no
// apto automático (NOM-030), segunda toma, marcar otro resultado con la razón, sin señal, la
// lista de hoy y quién tomó. API real local; personas inventadas.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const B = 'http://localhost:8794/';
const A = B + 'api/index.php';
const D = __dirname + '/';
let fallas = 0;
const ok = (c, m) => { if (!c) fallas++; console.log((c ? 'OK   ' : 'FALLA') + ' ' + m); };
const sql = q => execSync(`mariadb --default-character-set=utf8mb4 -N -B -uprueba -pprueba -h127.0.0.1 ca_prueba -e "${q}"`).toString().trim();
const post = async (accion, tok, payload = {}) => (await fetch(A, {
  method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + tok }, body: JSON.stringify({ action: accion, payload })
})).json();

function app() {
  window.__leer = []; window.__lecturas = [];
  window.Capacitor = { isNativePlatform: () => true, Plugins: { LectorTarjeta: {
    disponible: () => Promise.resolve({ disponible: true, local: true, continuo: true, rayas: true }),
    leer: o => { window.__leer.push(o); return new Promise(r => setTimeout(() => r(window.__lecturas.shift() || { cancelado: true }), 200)); },
    addListener: () => Promise.resolve({ remove() {} })
  } } };
}
const escribe = async (p, sel, txt) => { await p.click(sel); await p.fill(sel, ''); await p.type(sel, txt, { delay: 20 }); };

(async () => {
  await post('PADRON_GUARDAR', 'tok-admin', { codigo: '00287', nombre: 'Ramiro Esteban Villarreal Duarte', empresa: 'Montajes del Golfo', cargo: 'Soldador' });
  await post('PADRON_GUARDAR', 'tok-admin', { codigo: '00412', nombre: 'Ana Sofia Ruiz Paez', empresa: 'Montajes del Golfo' });
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, timezoneId: 'America/Mexico_City' });
  await ctx.addInitScript(() => { if (!sessionStorage.getItem('ya')) { localStorage.setItem('ca_sesion', JSON.stringify({ token: 'tok-medico', rol: 'MEDICO', usuario: 'medico', nombre: 'ENF. LAURA MEDINA' })); sessionStorage.setItem('ya', '1'); } });
  await ctx.addInitScript(app);
  const p = await ctx.newPage();
  const errores = []; p.on('pageerror', e => errores.push(e.message)); p.on('dialog', d => d.accept());

  // La app abre accesos.html: el servicio médico va a su pantalla
  await p.goto(B + 'accesos.html'); await p.waitForTimeout(1800);
  ok(/medico\.html$/.test(p.url()), 'la app abre accesos.html y el servicio médico pasa a su pantalla');
  ok(await p.isVisible('#b-escanear') && /ENF\. LAURA MEDINA/.test(await p.textContent('#sub')), 'con la app: botón «Escanear credencial» y quién está en sesión');
  await p.screenshot({ path: D + 'medico_inicio.png', fullPage: true });

  // ── Escanear: la base llena nombre y compañía ──
  await p.evaluate(() => window.__lecturas.push({ codigo: '00287', nombre: 'RAMRO VILARREAL', ms: 900 }));
  await p.click('#b-escanear'); await p.waitForTimeout(700);
  const op = await p.evaluate(() => window.__leer[0]);
  ok(op && op.titulo === 'SERVICIO MÉDICO' && op.foto === false && op.esperaNombre === 0 && op.ids.includes('287') && op.digitosId === 5,
     'el lector: título del servicio médico, sin foto, sólo el ID, con los IDs de la base');
  let t = await p.textContent('#per');
  ok(/ID 00287/.test(t) && /RAMIRO ESTEBAN VILLARREAL DUARTE/.test(t) && /MONTAJES DEL GOLFO/.test(t), 'la persona con el nombre y la compañía de la base: ' + t.replace(/\s+/g, ' ').slice(0, 90));
  ok(await p.evaluate(() => document.activeElement.id) === 'sis', 'el cursor queda en la sistólica');
  await p.type('#sis', '128', { delay: 20 });
  ok(await p.evaluate(() => document.activeElement.id) === 'dia', 'con 3 dígitos pasa sola a la diastólica');
  await p.type('#dia', '82', { delay: 20 }); await escribe(p, '#fc', '76');
  t = await p.textContent('#clasif');
  ok(/APTO/.test(t) && /Normal/.test(t) && await p.getAttribute('.resultado [data-r=A]', 'aria-pressed') === 'true', '128/82: APTO, «Normal», marcado solo: ' + t.trim());
  await p.screenshot({ path: D + 'medico_toma.png', fullPage: true });
  await p.click('#b-guardar'); await p.waitForTimeout(1500);
  ok(await p.isVisible('#hecho') && (await p.textContent('#h-res')) === 'APTO' && /128\/82 mmHg · 76 lpm/.test(await p.textContent('#h-ta')), 'guardada: APTO en grande con la tensión');
  ok(await p.isHidden('#b-repetir'), 'si es apto no ofrece volver a tomarle');
  ok(sql("SELECT CONCAT(codigo,'|',nombre,'|',sistolica,'/',diastolica,'|',frecuencia,'|',resultado,'|',usuario_nombre,'|',origen) FROM medico_tomas WHERE clave='287'")
     === '00287|RAMIRO ESTEBAN VILLARREAL DUARTE|128/82|76|A|ENF. LAURA MEDINA|tarjeta', 'en el servidor: con quién tomó y que fue con la credencial');

  // ── Escribir el ID; no apto; segunda toma ──
  await p.click('#b-siguiente'); await p.waitForTimeout(300);
  await escribe(p, '#id', '412'); await p.click('#f-id button'); await p.waitForTimeout(300);
  ok(/ANA SOFIA RUIZ PAEZ/.test(await p.textContent('#per')), 'escribiendo el ID (412) también la encuentra');
  await p.type('#sis', '145', { delay: 20 }); await p.type('#dia', '92', { delay: 20 });
  t = await p.textContent('#clasif');
  ok(/NO APTO/.test(t) && /Hipertensión grado 1/.test(t) && /100\/60 a 139\/89/.test(t), '145/92: NO APTO, «Hipertensión grado 1», con los límites: ' + t.trim());
  await p.click('#b-guardar'); await p.waitForTimeout(1500);
  ok((await p.textContent('#h-res')) === 'NO APTO' && await p.isVisible('#b-repetir'), 'NO APTO en grande, con «Volver a tomarle»');
  await p.screenshot({ path: D + 'medico_noapto.png', fullPage: true });
  await p.click('#b-repetir'); await p.waitForTimeout(400);
  ok(/Hoy ya tiene 1 toma/.test(await p.textContent('#per')) && /145\/92/.test(await p.textContent('#per')) && /Esta será la 2\.ª/.test(await p.textContent('#per')), 'vuelve a tomarle: dice la toma anterior y que es la 2.ª');
  await p.type('#sis', '134', { delay: 20 }); await p.type('#dia', '86', { delay: 20 }); await escribe(p, '#obs', 'Reposo de 10 minutos');
  await p.click('#b-guardar'); await p.waitForTimeout(1500);
  ok(/2\.ª toma de hoy/.test(await p.textContent('#h-ta')) && sql("SELECT GROUP_CONCAT(CONCAT(toma,resultado) ORDER BY toma) FROM medico_tomas WHERE clave='412'") === '1N,2A', '2.ª toma apta (en el servidor: 1.ª no apta, 2.ª apta)');

  // ── ID fuera de la base; marcar otro resultado pide la razón ──
  await p.click('#b-siguiente');
  await escribe(p, '#id', '00999'); await p.click('#f-id button'); await p.waitForTimeout(300);
  ok(/no está en la base/.test(await p.textContent('#per')) && await p.isVisible('#p-nombre'), 'ID fuera de la base: avisa y pide nombre y compañía');
  await escribe(p, '#p-nombre', 'Persona Nueva Contratista'); await escribe(p, '#p-empresa', 'Andamios del Sur');
  await escribe(p, '#sis', '142'); await escribe(p, '#dia', '88');
  await p.click('.resultado [data-r=A]');
  ok(await p.isVisible('#r-nota') && /escribe por qué/.test(await p.textContent('#r-nota')), 'marcar APTO con 142/88: pide escribir por qué');
  await p.click('#b-guardar'); await p.waitForTimeout(400);
  ok(await p.isVisible('#t-err') && await p.isVisible('#f-toma'), 'sin la razón no se guarda');
  await escribe(p, '#obs', 'Valorado por el médico de guardia');
  await p.click('#b-guardar'); await p.waitForTimeout(1500);
  ok(sql("SELECT CONCAT(nombre,'|',empresa,'|',resultado,calculado,'|',en_base) FROM medico_tomas WHERE clave='999'") === 'PERSONA NUEVA CONTRATISTA|ANDAMIOS DEL SUR|AN|0',
     'se guarda con el nombre escrito, APTO marcado a mano (la norma decía no apto)');

  // ── Sin credencial ──
  await p.click('#b-siguiente'); await p.click('#b-sin');
  await escribe(p, '#sis', '118'); await escribe(p, '#dia', '76');
  await p.click('#b-guardar'); await p.waitForTimeout(300);
  ok(/nombre del trabajador/.test(await p.textContent('#t-err')), 'sin credencial: pide el nombre');
  await escribe(p, '#p-nombre', 'Visitante Sin Credencial'); await p.click('#b-guardar'); await p.waitForTimeout(1500);
  ok(sql("SELECT COUNT(*) FROM medico_tomas WHERE nombre='VISITANTE SIN CREDENCIAL' AND codigo=''") === '1', 'se guarda sólo con el nombre');

  // ── Sin señal ──
  await ctx.setOffline(true); await p.evaluate(() => window.dispatchEvent(new Event('offline')));
  await p.click('#b-siguiente');
  await p.evaluate(() => window.__lecturas.push({ codigo: '287', nombre: '', ms: 700 }));
  await p.click('#b-escanear'); await p.waitForTimeout(600);
  ok(/RAMIRO/.test(await p.textContent('#per')) && /Hoy ya tiene 1 toma/.test(await p.textContent('#per')), 'sin señal: la base y las tomas de hoy siguen en el teléfono');
  await p.type('#sis', '122', { delay: 20 }); await p.type('#dia', '79', { delay: 20 });
  await p.click('#b-guardar'); await p.waitForTimeout(800);
  ok((await p.textContent('#h-res')) === 'APTO' && /1 toma por enviar/.test(await p.textContent('#senal')), 'sin señal se guarda en el teléfono: «1 toma por enviar»');
  ok(/Por enviar/.test(await p.textContent('#lista')) && sql("SELECT COUNT(*) FROM medico_tomas WHERE clave='287'") === '1', 'aparece en la lista como «Por enviar»; no ha llegado al servidor');
  await ctx.setOffline(false); await p.evaluate(() => window.dispatchEvent(new Event('online'))); await p.waitForTimeout(2500);
  ok(sql("SELECT GROUP_CONCAT(toma ORDER BY toma) FROM medico_tomas WHERE clave='287'") === '1,2' && /todo enviado/.test(await p.textContent('#senal')), 'al volver la señal se envía (2.ª toma de 00287) y dice «todo enviado»');

  // ── Lista de hoy ──
  t = await p.textContent('#n-hoy');
  ok(/6 tomas · 4 aptos · 0 no aptos/.test(t), 'tomas de hoy: ' + t);
  ok((await p.locator('#lista .toma').count()) === 6 && /tomó ENF\. LAURA MEDINA/.test(await p.textContent('#lista')), 'la lista con quién tomó');
  await p.fill('#q', 'ana'); await p.waitForTimeout(100);
  ok((await p.locator('#lista .toma').count()) === 2, 'buscar «ana»: sus 2 tomas');
  await p.fill('#q', '');
  ok(await p.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), 'en el teléfono no se sale de la pantalla');
  await p.screenshot({ path: D + 'medico_lista.png', fullPage: true });
  ok(!errores.length, 'sin errores de JavaScript' + (errores.length ? ': ' + errores.join(' | ') : ''));
  await ctx.close();

  // ── Otros perfiles ──
  const c2 = await b.newContext({ viewport: { width: 390, height: 844 } });
  await c2.addInitScript(() => localStorage.setItem('ca_sesion', JSON.stringify({ token: 'tok-punto1', rol: 'ACCESOS', usuario: 'punto1', nombre: 'CASETA PUNTO 1' })));
  const g = await c2.newPage(); await g.goto(B + 'medico.html'); await g.waitForTimeout(500);
  ok(await g.isVisible('#sin-permiso'), 'el guardia no entra a la pantalla del servicio médico');
  await c2.close();
  const c3 = await b.newContext({ viewport: { width: 1000, height: 800 } });
  await c3.addInitScript(() => localStorage.setItem('ca_sesion', JSON.stringify({ token: 'tok-admin', rol: 'ADMIN', usuario: 'admin', nombre: 'ADMINISTRACION' })));
  const a = await c3.newPage(); await a.goto(B + 'index.html'); await a.waitForTimeout(800);
  ok(await a.isVisible('#m-medico'), 'Administración ve «Servicio médico» en el inicio');
  await a.goto(B + 'medico.html'); await a.waitForTimeout(1200);
  ok(await a.isHidden('#b-escanear') && await a.isVisible('#f-id'), 'en el navegador (sin la app): sólo escribir el ID');
  await c3.close();
  // Iniciar sesión con una cuenta de servicio médico lleva a su pantalla
  const c4 = await b.newContext({ viewport: { width: 390, height: 844 } });
  const l = await c4.newPage(); await l.goto(B + 'login.html?volver=accesos.html'); await l.waitForTimeout(400);
  await l.fill('#usuario', 'medico2'); await l.fill('#password', 'prueba-local'); await l.click('button[type=submit]'); await l.waitForTimeout(1500);
  ok(/medico\.html$/.test(l.url()), 'al iniciar sesión, el servicio médico llega a su pantalla');
  await c4.close();

  // ── El tablero del cliente: pestaña «Servicio médico» ──
  await post('PADRON_GUARDAR', 'tok-admin', { codigo: '00316', nombre: 'Pedro Vega Cruz', empresa: 'Servicios Industriales del Norte' });
  await post('MEDICO_REGISTRAR', 'tok-medico2', { uid: 'med-tablero-0001', codigo: '316', sistolica: 152, diastolica: 96, frecuencia: 92, origen: 'tarjeta' });
  for (const [ancho, alto, nombre] of [[1200, 900, 'escritorio'], [390, 844, 'telefono']]) {
    const c5 = await b.newContext({ viewport: { width: ancho, height: alto }, timezoneId: 'America/Mexico_City', acceptDownloads: true });
    await c5.addInitScript(() => { localStorage.setItem('ca_sesion', JSON.stringify({ token: 'tok-cliente', rol: 'CLIENTE', usuario: 'cliente', nombre: 'CLIENTE DE PRUEBA' }));
                                   localStorage.setItem('ca_tablero_periodo', JSON.stringify({ p: '7' })); });
    const t5 = await c5.newPage(); const err5 = []; t5.on('pageerror', e => err5.push(e.message));
    await t5.goto(B + 'tablero.html'); await t5.waitForTimeout(1500);
    ok(await t5.isVisible('.vistas [data-v=medico]') && await t5.isVisible('#v-accesos'), nombre + ': el cliente ve las pestañas «Accesos» y «Servicio médico»');
    await t5.click('.vistas [data-v=medico]'); await t5.waitForTimeout(1800);
    const k = await t5.textContent('#kpis-m');
    ok(/Personas evaluadas\s*5\s*7 tomas · 5 personas distintas/.test(k) && /No aptos\s*1\s*20 %/.test(k) && /Más de una toma\s*2\s*1 apto en la siguiente toma/.test(k),
       nombre + ': cifras del servicio médico: ' + k.replace(/\s+/g, ' ').slice(0, 160));
    ok(await t5.isVisible('[data-g=mdia] canvas') && await t5.isVisible('[data-g=mclase] canvas') && await t5.isVisible('[data-g=mempresa] canvas'), nombre + ': las 3 gráficas');
    ok(/PEDRO VEGA CRUZ/.test(await t5.textContent('#t-noaptos')) && /152\/96/.test(await t5.textContent('#t-noaptos')) && /DR\. HUGO SALAS/.test(await t5.textContent('#t-noaptos')),
       nombre + ': no aptos con su tensión y quién tomó');
    ok((await t5.locator('#t-bitacora tbody tr').count()) === 7 && /100\/60 a 139\/89/.test(await t5.textContent('#nota-limites')), nombre + ': la bitácora (7 tomas) y los límites');
    await t5.fill('#buscar-bitacora', 'ana'); await t5.waitForTimeout(100);
    ok((await t5.locator('#t-bitacora tbody tr').count()) === 2, nombre + ': buscar en la bitácora');
    await t5.fill('#buscar-bitacora', '');
    ok(await t5.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), nombre + ': no se sale de la pantalla');
    await t5.screenshot({ path: D + 'medico_tablero_' + nombre + '.png', fullPage: true });
    if (nombre === 'escritorio') {
      const [dx] = await Promise.all([t5.waitForEvent('download', { timeout: 15000 }), t5.click('#b-excel')]);
      ok(/^MEDICO_.*\.xlsx$/.test(dx.suggestedFilename()), 'Descargar Excel en la pestaña médica: ' + dx.suggestedFilename());
      await t5.waitForTimeout(500);
      const [dp] = await Promise.all([t5.waitForEvent('download', { timeout: 20000 }), t5.click('#b-pdf')]);
      const ruta = await dp.path();
      const txt = execSync(`pdftotext -layout "${ruta}" -`).toString().replace(/\ufb01/g, 'fi');   // el PDF une «fi» en una letra
      ok(/^MEDICO_.*\.pdf$/.test(dp.suggestedFilename()) && /Aptos y no aptos por día/.test(txt) && /Clasificación de la tensión arterial/.test(txt), 'el PDF lleva las gráficas de la pantalla');
      // Se recuerda la pestaña
      await t5.reload(); await t5.waitForTimeout(1500);
      ok(await t5.isVisible('#v-medico') && await t5.isHidden('#v-accesos'), 'al volver a entrar sigue en «Servicio médico»');
      await t5.click('.vistas [data-v=accesos]'); await t5.waitForTimeout(1200);
      ok(await t5.isVisible('#v-accesos') && /Entradas/.test(await t5.textContent('#kpis')), 'y regresa a «Accesos»');
    }
    ok(!err5.length, nombre + ': sin errores de JavaScript' + (err5.length ? ': ' + err5.join(' | ') : ''));
    await c5.close();
  }

  await b.close();
  console.log(fallas ? fallas + ' FALLAS' : 'TODO BIEN');
  process.exit(fallas ? 1 : 0);
})().catch(e => { console.error('FALLO', e); process.exit(1); });
