// Pantallas de la base del personal y los permisos (API real local, datos inventados):
//  · personal.html (Administración): cargar un Excel (revisar y cargar), buscar, agregar y cambiar,
//    permiso con foto y datos del equipo, quitar, IDs leídos que no están en la base, menú;
//  · accesos.html (guardia, app simulada): el nombre de la base al leer, sus permisos en la
//    confirmación y en «Última credencial leída», aviso de ID fuera de la base, agregar un resguardo
//    con la foto del pase sin señal (sale al volver la señal) y la copia de la base sin señal.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs');
const B = 'http://localhost:8794/';
const D = __dirname + '/';
let fallas = 0;
const ok = (c, m) => { if (!c) fallas++; console.log((c ? 'OK   ' : 'FALLA') + ' ' + m); };
const sql = q => execSync(`mariadb --default-character-set=utf8mb4 -N -B -uprueba -pprueba -h127.0.0.1 ca_prueba -e "${q}"`).toString().trim();
const sesion = (tok, rol, usuario, nombre, turno) => ({ token: tok, rol, usuario, nombre, turno: turno || '' });

(async () => {
  // Archivos inventados: el Excel del personal y la foto de un pase
  execSync(`python3 - "${D}personal_pantalla.xlsx" "${D}pase_prueba.jpg" <<'EOF'
import sys, openpyxl
from PIL import Image
wb = openpyxl.Workbook(); ws = wb.active
ws.append(['ID', 'Nombre completo', 'Empresa', 'Cargo'])
ws.append(['00287', 'Ramiro Esteban Villarreal Duarte', 'Montajes del Golfo', 'Soldador'])
ws.append([412, 'Ana Sofía Ruiz Páez', 'Servicios Industriales Norte', 'Ayudante'])
ws.append(['00316', 'Pedro Vega Cruz', 'Montajes del Golfo', 'Pailero'])
ws.append([None, 'Renglón sin ID', None, None])
wb.save(sys.argv[1])
Image.new('RGB', (900, 600), (230, 200, 60)).save(sys.argv[2], 'JPEG')
EOF`);
  const b = await chromium.launch();

  // ══ Administración: personal.html ══
  const ca = await b.newContext({ viewport: { width: 1200, height: 900 }, timezoneId: 'America/Mexico_City', acceptDownloads: true });
  await ca.addInitScript(s => localStorage.setItem('ca_sesion', JSON.stringify(s)), sesion('tok-admin', 'ADMIN', 'admin', 'ADMINISTRACION'));
  const p = await ca.newPage();
  const errores = []; p.on('pageerror', e => errores.push(e.message)); p.on('dialog', d => d.accept());
  await p.goto(B + 'index.html'); await p.waitForTimeout(700);
  ok(await p.isVisible('#m-personal'), 'Administración ve el mosaico «Personal y permisos»');
  await p.click('#m-personal'); await p.waitForURL(/personal\.html/); await p.waitForTimeout(900);
  ok(/La base está vacía/.test(await p.textContent('#lista')), 'base vacía: invita a cargar el Excel');

  // Cargar Excel: revisar y luego cargar
  await p.click('#b-importar');
  await p.setInputFiles('#imp-archivo', D + 'personal_pantalla.xlsx'); await p.waitForTimeout(1200);
  const cuenta = (await p.textContent('#imp-cuenta')).replace(/\s+/g, ' ');
  const sinEsp = cuenta.replace(/ /g, '');
  ok(/3personasenelarchivo/.test(sinEsp) && /3nuevas/.test(sinEsp) && /1renglónconproblema/.test(sinEsp), 'revisión del Excel: ' + cuenta);
  ok(await p.$eval('#imp-cols select[data-k=id]', s => s.value) === '0' && await p.$eval('#imp-cols select[data-k=nombre]', s => s.value) === '1', 'reconoce las columnas ID y Nombre');
  ok(/00412/.test(await p.textContent('#imp-detalle')) && /Sin ID/.test(await p.textContent('#imp-detalle')), 'muestra cómo se van a guardar (412 → 00412) y el renglón con problema');
  await p.screenshot({ path: D + 'per_importar.png', fullPage: true });
  ok(sql('SELECT COUNT(*) FROM personal') === '0', 'revisar no cambia nada');
  await p.click('#imp-aplicar'); await p.waitForTimeout(1500);
  ok(/Base actualizada: 3 nuevos/.test(await p.textContent('#imp-msg')), 'cargado: ' + await p.textContent('#imp-msg'));
  await p.click('#v-imp [data-cerrar]'); await p.waitForTimeout(400);
  ok((await p.$$eval('#lista .per', l => l.length)) === 3 && /3 personas en la base/.test(await p.textContent('#sub')), 'la lista muestra a las 3 personas');

  // Buscar
  await p.fill('#q', '412'); await p.waitForTimeout(700);
  ok((await p.$$eval('#lista .per .nom', l => l.map(x => x.textContent))).join() === 'ANA SOFIA RUIZ PAEZ', 'buscar por ID sin ceros');
  await p.fill('#q', ''); await p.waitForTimeout(700);

  // Abrir a Ramiro y agregarle un permiso con foto y datos del equipo
  await p.click('#lista .per:has-text("RAMIRO")'); await p.waitForTimeout(800);
  ok(await p.inputValue('#p-codigo') === '00287' && /Sin permisos especiales/.test(await p.textContent('#permisos')), 'la ficha de la persona, sin permisos todavía');
  await p.click('#b-nuevo-permiso');
  await p.click('#pm-guardar');
  ok(/Marca qué permiso/.test(await p.textContent('#pm-err')), 'sin marcar el tipo: lo pide');
  await p.check('#pm-tipos input[value=CELULAR]'); await p.check('#pm-tipos input[value=COMPUTADORA]');
  await p.setInputFiles('#pm-foto', D + 'pase_prueba.jpg'); await p.waitForTimeout(500);
  await p.fill('#pm-detalle', 'Celular Samsung A54, IMEI 351234567890123; laptop Dell 5420');
  await p.fill('#pm-vigencia', '2099-12-31');
  await p.click('#pm-guardar'); await p.waitForTimeout(1300);
  const perm = (await p.textContent('#permisos')).replace(/\s+/g, ' ');
  ok(/Permiso de celular/.test(perm) && /Permiso de computadora/.test(perm) && /IMEI/.test(perm) && /Vigente hasta el 31\/12\/2099/.test(perm) && await p.isVisible('#permisos img'),
     'permiso guardado con foto, datos y vigencia');
  await p.screenshot({ path: D + 'per_ficha.png', fullPage: true });

  // Cambiar el nombre
  await p.fill('#p-cargo', 'Soldador especialista'); await p.click('#p-guardar'); await p.waitForTimeout(900);
  ok(sql("SELECT cargo FROM personal WHERE clave='287'") === 'SOLDADOR ESPECIALISTA', 'cambiar datos de la persona');
  await p.click('#v-per [data-cerrar]'); await p.waitForTimeout(500);
  ok(/Permiso de celular/.test(await p.textContent('#lista')), 'la lista muestra sus permisos');

  // Agregar a mano y quitar
  await p.click('#b-nueva');
  await p.fill('#p-codigo', '733'); await p.fill('#p-nombre', 'Luis Mora Díaz'); await p.click('#p-guardar'); await p.waitForTimeout(1000);
  ok(await p.isVisible('#sec-permisos') && await p.inputValue('#p-codigo') === '00733', 'agregar a mano: ya se le pueden dar permisos');
  await p.click('#p-eliminar'); await p.waitForTimeout(900);
  ok(sql("SELECT COUNT(*) FROM personal WHERE clave='733'") === '0', 'quitar de la base');
  ok(!errores.length, 'sin errores de JavaScript (Personal)' + (errores.length ? ': ' + errores.join(' | ') : ''));

  // ══ Guardia: accesos.html con la app simulada ══
  const cg = await b.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, timezoneId: 'America/Mexico_City' });
  await cg.addInitScript(s => {
    if (!sessionStorage.getItem('ya')) { localStorage.setItem('ca_sesion', JSON.stringify(s)); sessionStorage.setItem('ya', '1'); }
    window.__lecturas = [];
    window.Capacitor = { isNativePlatform: () => true, Plugins: { LectorTarjeta: {
      leer: () => new Promise(r => setTimeout(() => r(window.__lecturas.shift() || { cancelado: true }), 150)), abrir: () => Promise.resolve({}) } } };
  }, sesion('tok-punto1', 'ACCESOS', 'punto1', 'CASETA PUNTO 1', 'D'));
  const g = await cg.newPage();
  const errG = []; g.on('pageerror', e => errG.push(e.message)); g.on('dialog', d => d.accept());
  await g.goto(B + 'accesos.html'); await g.waitForTimeout(2000);
  const copia = await g.evaluate(() => JSON.parse(localStorage.getItem('ca_padron') || '{}'));
  ok(copia.total === 3 && copia.personas['287'] && copia.permisos['287'], 'el teléfono guarda la copia de la base (3 personas y sus permisos)');

  // Lee a Ramiro con el nombre mal leído
  await g.evaluate(() => window.__lecturas.push({ codigo: '00287', nombre: 'RAMRO ESTEBAN VILARREAL', ms: 500 }));
  await g.click('.cap.ent'); await g.waitForTimeout(450);
  let hecho = (await g.textContent('#hecho')).replace(/\s+/g, ' ');
  ok(/RAMIRO ESTEBAN VILLARREAL DUARTE/.test(hecho) && /Permiso de celular/.test(hecho) && /Permiso de computadora/.test(hecho), 'confirmación: nombre de la base y sus permisos: ' + hecho);
  await g.screenshot({ path: D + 'per_caseta_hecho.png' });
  await g.waitForTimeout(3800);
  ok(await g.isHidden('#hecho'), 'con permisos la confirmación dura más, y luego se quita');
  let ult = (await g.textContent('#tarjeta-ultima')).replace(/\s+/g, ' ');
  ok(/RAMIRO ESTEBAN VILLARREAL DUARTE/.test(ult) && /IMEI/.test(ult) && /Vigente hasta el 31\/12\/2099/.test(ult), '«Última credencial leída» con los datos del permiso');
  await g.waitForTimeout(1500);
  ok(sql("SELECT nombre FROM accesos_registros WHERE codigo='00287' ORDER BY id DESC LIMIT 1") === 'RAMIRO ESTEBAN VILLARREAL DUARTE', 'la entrada se guardó con el nombre de la base');

  // Un ID que no está en la base
  await g.evaluate(() => window.__lecturas.push({ codigo: '00999', nombre: 'ALGUIEN NUEVO', ms: 500 }));
  await g.click('.cap.ent'); await g.waitForTimeout(450);
  hecho = (await g.textContent('#hecho')).replace(/\s+/g, ' ');
  ok(/no está en la base/.test(hecho), 'ID fuera de la base: lo avisa en la confirmación');
  await g.waitForTimeout(3800);
  ok(/no está en la base/.test(await g.textContent('#tarjeta-ultima')), 'y en «Última credencial leída»');

  // Sin señal: lee a Pedro (la copia sigue funcionando) y le agrega un resguardo de herramienta con foto
  await cg.setOffline(true);
  await g.evaluate(() => window.__lecturas.push({ codigo: '316', nombre: 'PEDRO VEGA', ms: 500 }));
  await g.click('.cap.ent'); await g.waitForTimeout(450);
  ok(/PEDRO VEGA CRUZ/.test(await g.textContent('#hecho')), 'sin señal también pone el nombre de la base');
  await g.waitForTimeout(1700);
  await g.click('#btn-permiso'); await g.waitForTimeout(300);
  ok(await g.inputValue('#perm-id') === '316' && /PEDRO VEGA CRUZ/.test(await g.textContent('#perm-quien')), 'la ventana de permiso trae el ID y el nombre');
  await g.click('#perm-tipos [data-v=HERRAMIENTA]');
  await g.click('#perm-guardar');
  ok(/Toma la foto/.test(await g.textContent('#perm-err')), 'sin foto del pase: la pide');
  await g.setInputFiles('#perm-archivo', D + 'pase_prueba.jpg'); await g.waitForTimeout(700);
  await g.fill('#perm-detalle', 'Pulidora y extensión');
  await g.screenshot({ path: D + 'per_caseta_ventana.png' });
  await g.click('#perm-guardar'); await g.waitForTimeout(900);
  ult = (await g.textContent('#tarjeta-ultima')).replace(/\s+/g, ' ');
  ok(/Resguardo de herramienta/.test(ult) && /Por enviar/.test(ult), 'sin señal: el resguardo queda en el teléfono «Por enviar»');
  ok(sql("SELECT COUNT(*) FROM personal_permisos WHERE clave='316'") === '0', 'todavía no llega al servidor');
  await cg.setOffline(false);
  await g.evaluate(() => window.dispatchEvent(new Event('online'))); await g.waitForTimeout(5000);
  ok(sql("SELECT CONCAT(tipos,'|',detalle,'|',origen,'|',foto<>'') FROM personal_permisos WHERE clave='316'") === 'HERRAMIENTA|Pulidora y extensión|caseta|1', 'al volver la señal se envía con su foto');
  ult = (await g.textContent('#tarjeta-ultima')).replace(/\s+/g, ' ');
  ok(/Resguardo de herramienta/.test(ult) && !/Por enviar/.test(ult) && /CASETA PUNTO 1/.test(ult), 'y la tarjeta ya lo muestra guardado');
  ok((await g.$$eval('#lista li.reg', l => l.filter(x => /permiso/i.test(x.textContent)).length)) === 0, 'el permiso no aparece como entrada o salida en la lista');

  // La próxima vez que se lee a Pedro, la caseta dice que trae herramienta
  await g.evaluate(() => window.__lecturas.push({ codigo: '00316', nombre: 'PEDRO VEGA CRUZ', ms: 500 }));
  await g.click('.cap.sal'); await g.waitForTimeout(450);
  ok(/Resguardo de herramienta/.test(await g.textContent('#hecho')), 'en la siguiente lectura dice que tiene resguardo de herramienta');
  await g.waitForTimeout(3800);
  await g.screenshot({ path: D + 'per_caseta_ultima.png', fullPage: true });
  ok(!errG.length, 'sin errores de JavaScript (caseta)' + (errG.length ? ': ' + errG.join(' | ') : ''));

  // ══ Administración ve el resguardo que agregó el guardia, y los IDs que faltan ══
  await p.goto(B + 'personal.html'); await p.waitForTimeout(1000);
  ok(/Resguardo de herramienta/.test(await p.textContent('#lista .per:has-text("PEDRO")')), 'Administración ve el resguardo que agregó la caseta');
  ok(await p.isVisible('#c-faltan') && /00999/.test(await p.textContent('#faltan')), '«Leídos en la caseta y no están en la base»: 00999');
  await p.click('#faltan [data-falta="0"]'); await p.waitForTimeout(400);
  ok(await p.inputValue('#p-codigo') === '00999' && await p.inputValue('#p-nombre') === 'ALGUIEN NUEVO', 'agregarlo trae su ID y el nombre leído');
  await p.click('#v-per [data-cerrar]');
  await p.screenshot({ path: D + 'per_lista.png', fullPage: true });

  // El administrador de la planta también; el cliente no
  const cp = await b.newContext({ viewport: { width: 1200, height: 900 } });
  await cp.addInitScript(s => localStorage.setItem('ca_sesion', JSON.stringify(s)), sesion('tok-planta', 'PLANTA', 'planta', 'ADMINISTRADOR DE PLANTA'));
  const pp = await cp.newPage();
  await pp.goto(B + 'index.html'); await pp.waitForTimeout(600);
  ok(await pp.isVisible('#m-personal'), 'el administrador de la planta ve «Personal y permisos»');
  await cp.close();
  const cc = await b.newContext({ viewport: { width: 1200, height: 900 } });
  await cc.addInitScript(s => localStorage.setItem('ca_sesion', JSON.stringify(s)), sesion('tok-cliente', 'CLIENTE', 'cliente', 'CLIENTE'));
  const pc = await cc.newPage();
  await pc.goto(B + 'index.html'); await pc.waitForTimeout(600);
  ok(await pc.isHidden('#m-personal'), 'el cliente no ve «Personal y permisos»');
  await pc.goto(B + 'personal.html'); await pc.waitForTimeout(500);
  ok(await pc.isVisible('#sin-permiso') && await pc.isHidden('#todo'), 'y si abre la página, no puede usarla');
  await b.close();
  console.log(fallas ? fallas + ' FALLAS' : 'TODO BIEN');
  process.exit(fallas ? 1 : 0);
})().catch(e => { console.error('FALLO', e); process.exit(1); });
