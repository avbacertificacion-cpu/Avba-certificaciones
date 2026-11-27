// Equipo fijo (lectura continua) en la pantalla del guardia, con la app simulada:
//  · el guardia elige en el equipo si está en la ENTRADA o en la SALIDA; registra con su cuenta y su
//    turno, el equipo lo recuerda si la app se reinicia y lo olvida al salir de la cámara;
//  · la cuenta de modo fijo (sólo entradas / sólo salidas) abre la lectura continua sola;
//  · cada lectura se registra, se ignora a quien se registró hace menos de 2 min, la app muestra el
//    nombre de la base, permisos y avisos; sin señal; la app vieja; Cuentas;
//  · credenciales con rayas de la impresora: el lector recibe los 5 dígitos del ID y los IDs de la base,
//    y una lectura con un dígito de más (app anterior) se corrige con la base.
// API real local; personas e IDs inventados.
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
const ses = (u, modo) => ({ token: 'tok-' + u, rol: 'ACCESOS', usuario: u, nombre: u.toUpperCase(), turno: '', modo });

// La app simulada: lectura continua con eventos, y lo que la página le pide mostrar
function app(conContinuo) {
    window.__ev = {}; window.__mostrados = []; window.__continuo = []; window.__leer = [];
    window.__emitir = (ev, d) => (window.__ev[ev] || []).forEach(f => f(d));
    const L = {
      disponible: () => Promise.resolve(conContinuo ? Object.assign({ disponible: true, local: true, continuo: true, rayas: conContinuo === 'rayas' },
                                                                    conContinuo === 'rayas' ? { version: 114 } : {}) : { disponible: true, local: true }),
      mostrar: d => { window.__mostrados.push(d); return Promise.resolve({ mostrado: true }); },
      detener: () => Promise.resolve(),
      addListener: (ev, fn) => { (window.__ev[ev] = window.__ev[ev] || []).push(fn); return Promise.resolve({ remove() {} }); },
      leer: o => { window.__leer.push(o); return Promise.resolve({ cancelado: true }); },
      abrir: () => Promise.resolve({})
    };
    if (conContinuo) L.continuo = o => { window.__continuo.push(o); return Promise.resolve({ iniciado: true }); };
    window.Capacitor = { isNativePlatform: () => true, Plugins: { LectorTarjeta: L } };
}
async function telefono(b, s, conContinuo = true) {
  const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, timezoneId: 'America/Mexico_City' });
  await ctx.addInitScript(s => { if (!sessionStorage.getItem('ya')) { localStorage.setItem('ca_sesion', JSON.stringify(s)); sessionStorage.setItem('ya', '1'); } }, s);
  await ctx.addInitScript(app, conContinuo);
  const p = await ctx.newPage();
  p.errores = []; p.on('pageerror', e => p.errores.push(e.message)); p.on('dialog', d => d.accept());
  await p.goto(B + 'accesos.html'); await p.waitForTimeout(2500);
  return { ctx, p };
}
const leer = (p, codigo, nombre) => p.evaluate(([c, n]) => window.__emitir('lectura', { codigo: c, nombre: n, continuo: true, ms: 600 }), [codigo, nombre]);
const ultimo = p => p.evaluate(() => window.__mostrados[window.__mostrados.length - 1] || null);
const cuenta = (cod, tipo) => sql(`SELECT COUNT(*) FROM accesos_registros WHERE TRIM(LEADING '0' FROM codigo) = '${cod}' AND tipo = '${tipo}'`);

(async () => {
  // La base del personal (inventada): Ramiro con permiso de celular; Ana con uno vencido
  await post('PADRON_GUARDAR', 'tok-admin', { codigo: '00287', nombre: 'Ramiro Esteban Villarreal Duarte', empresa: 'Montajes del Golfo' });
  await post('PADRON_GUARDAR', 'tok-admin', { codigo: '00412', nombre: 'Ana Sofia Ruiz Paez' });
  await post('PADRON_GUARDAR', 'tok-admin', { codigo: '00316', nombre: 'Pedro Vega Cruz' });
  await post('PADRON_GUARDAR', 'tok-admin', { codigo: '00230', nombre: 'Lucia Mendez Rios' });
  await post('PERMISO_AGREGAR', 'tok-admin', { codigo: '287', tipos: ['CELULAR'], detalle: 'Celular Samsung A54' });
  await post('PERMISO_AGREGAR', 'tok-admin', { codigo: '412', tipos: ['HERRAMIENTA'], detalle: 'Taladro', vigencia: '2020-01-31' });
  const b = await chromium.launch();

  // ══ Carril de ENTRADA ══
  let { ctx, p } = await telefono(b, ses('fijo.entrada', 'E'));
  const op = await p.evaluate(() => window.__continuo);
  ok(op.length === 1 && op[0].tipo === 'entrada' && op[0].titulo === 'ENTRADA', 'al entrar, la lectura continua empieza sola (de ENTRADA)');
  ok(op[0] && op[0].esperaNombre === 0 && op[0].camara === 'frontal', 'con la base del personal, el lector sólo espera el ID (esperaNombre 0) y usa la cámara frontal');
  ok(op[0] && op[0].digitosId === 5 && op[0].minDigitos === 5 && ['287', '412', '316', '230'].every(k => (op[0].ids || []).includes(k)),
     'el lector recibe los 5 dígitos del ID y los IDs de la base (para las credenciales con rayas): ' + (op[0] && (op[0].ids || []).join(',')));
  ok(await p.isVisible('.nota-app') && /versión nueva de la app/.test(await p.textContent('.nota-app')), 'con la app de antes de las rayas: avisa que hay una versión nueva');
  ok(/Equipo fijo de ENTRADA/.test(await p.textContent('#fijo-tit')) && /Leyendo credenciales/.test(await p.textContent('#fijo-estado')), 'la pantalla dice «Equipo fijo de ENTRADA · Leyendo credenciales»');
  ok(await p.isVisible('.cap.ent') && await p.isHidden('.cap.sal') && await p.isHidden('.cap.inc'), 'sólo queda el botón de ENTRADA (sin SALIDA ni INCIDENCIA)');
  ok(/Equipo fijo de entrada/.test(await p.textContent('#sub-usuario')) && !/Turno de/.test(await p.textContent('#sub-usuario')), 'encabezado: ' + await p.textContent('#sub-usuario'));

  // Llega Ramiro (nombre mal leído): se registra con el de la base y la app muestra su permiso
  await leer(p, '00287', 'RAMRO VILARREAL');
  await p.waitForTimeout(1800);
  let m = await ultimo(p);
  ok(m && m.titulo === 'ENTRADA REGISTRADA' && m.nombre === 'RAMIRO ESTEBAN VILLARREAL DUARTE' && m.color === 'verde' && m.sonido === 'ok', 'la app muestra en verde: ' + (m && m.titulo + ' · ' + m.nombre));
  ok(m && m.lineas.some(x => /^ID 00287 · \d\d:\d\d$/.test(x)) && m.lineas.some(x => /Permiso de celular/.test(x)), 'con su ID, la hora y su permiso de celular: ' + (m && m.lineas.join(' | ')));
  ok(cuenta('287', 'entrada') === '1' && sql("SELECT nombre FROM accesos_registros WHERE codigo='00287'") === 'RAMIRO ESTEBAN VILLARREAL DUARTE', 'la entrada quedó registrada con el nombre de la base');
  // Lo lee otra vez (se regresó): no se registra dos veces
  await leer(p, '287', 'RAMIRO'); await p.waitForTimeout(800);
  m = await ultimo(p);
  ok(m && m.titulo === 'ENTRADA YA REGISTRADA' && m.color === 'amarillo' && /registrada a las \d\d:\d\d/.test(m.lineas[0]) && cuenta('287', 'entrada') === '1', 'leída de nuevo: «ENTRADA YA REGISTRADA» en amarillo, sin duplicar');
  // Permiso vencido y un ID fuera de la base
  await leer(p, '00412', 'ANA'); await p.waitForTimeout(800);
  m = await ultimo(p);
  ok(m && m.color === 'amarillo' && m.lineas.some(x => /Resguardo de herramienta · VENCIDO/.test(x)), 'permiso vencido: se registra y avisa en amarillo');
  await leer(p, '00999', 'ALGUIEN NUEVO'); await p.waitForTimeout(800);
  m = await ultimo(p);
  ok(m && m.color === 'amarillo' && m.nombre === 'ALGUIEN NUEVO' && m.lineas.some(x => /No está en la base/.test(x)) && cuenta('999', 'entrada') === '1', 'ID fuera de la base: se registra y avisa «pasa con el guardia»');
  // Credencial con una raya de la impresora, leída por la app anterior: «0|0230» llega como «010230»
  await leer(p, '010230', 'LUCIA'); await p.waitForTimeout(900);
  m = await ultimo(p);
  ok(m && m.titulo === 'ENTRADA REGISTRADA' && m.nombre === 'LUCIA MENDEZ RIOS' && m.color === 'verde' && cuenta('230', 'entrada') === '1' && cuenta('10230', 'entrada') === '0',
     'un dígito de más por la raya: se corrige con la base y se registra el ID 00230 (' + (m && m.lineas[0]) + ')');
  // Una tras otra (una persona cada 10 s es de sobra: aquí cada medio segundo)
  for (const [c, n] of [['00501', 'LUIS'], ['00502', 'MARIA'], ['00503', 'JOSUE'], ['00504', 'KARLA']]) { await leer(p, c, n); await p.waitForTimeout(500); }
  await p.waitForTimeout(3000);
  ok(['501', '502', '503', '504'].every(c => cuenta(c, 'entrada') === '1'), 'cuatro personas seguidas: las cuatro registradas');
  // Sin señal
  await ctx.setOffline(true);
  await leer(p, '00316', 'PEDRO'); await p.waitForTimeout(700);
  m = await ultimo(p);
  ok(m && m.titulo === 'ENTRADA REGISTRADA' && m.nombre === 'PEDRO VEGA CRUZ', 'sin señal la app muestra la entrada al instante (nombre de la copia de la base)');
  ok(cuenta('316', 'entrada') === '0', 'todavía no llega al servidor');
  await ctx.setOffline(false); await p.evaluate(() => window.dispatchEvent(new Event('online'))); await p.waitForTimeout(4000);
  ok(cuenta('316', 'entrada') === '1', 'al volver la señal se envía');
  ok(sql("SELECT COUNT(DISTINCT turno) FROM accesos_registros WHERE usuario='fijo.entrada'") === '1', 'todas con el turno del reloj');
  // Se sale de la lectura continua: queda el botón para reanudarla
  await p.evaluate(() => window.__emitir('cerrado', {})); await p.waitForTimeout(300);
  ok(/detenida/.test(await p.textContent('#fijo-estado')) && await p.isVisible('#btn-fijo'), 'al salir de la lectura continua: «detenida» y el botón para reanudar');
  await p.click('#btn-fijo'); await p.waitForTimeout(3500);
  ok((await p.evaluate(() => window.__continuo.length)) === 2 && /Leyendo credenciales/.test(await p.textContent('#fijo-estado')), 'el botón vuelve a abrir la lectura continua');
  await p.screenshot({ path: D + 'fijo_entrada.png', fullPage: true });
  ok(!p.errores.length, 'sin errores de JavaScript (entrada)' + (p.errores.length ? ': ' + p.errores.join(' | ') : ''));
  await ctx.close();

  // ══ Carril de SALIDA ══
  ({ ctx, p } = await telefono(b, ses('fijo.salida', 'S'), 'rayas'));
  ok(await p.isHidden('.nota-app') && /App versión 114/.test(await p.textContent('#fijo-estado')), 'con la app nueva (versión 114): sin el aviso de actualizar y dice su versión');
  ok((await p.evaluate(() => window.__continuo[0] && window.__continuo[0].tipo)) === 'salida' && await p.isVisible('.cap.sal') && await p.isHidden('.cap.ent'), 'el carril de salida abre la lectura continua de SALIDA');
  await leer(p, '00287', 'RAMIRO'); await p.waitForTimeout(1500);
  m = await ultimo(p);
  ok(m && m.titulo === 'SALIDA REGISTRADA' && m.color === 'azul' && cuenta('287', 'salida') === '1', 'salida registrada (en azul)');
  // Credencial muy dañada: el guardia escribe el ID en la cámara («Escribir ID») y la cámara sigue abierta
  await p.evaluate(() => window.__emitir('lectura', { codigo: '00412', nombre: '', manual: true, continuo: true, ms: 9000 })); await p.waitForTimeout(1500);
  m = await ultimo(p);
  ok(m && m.titulo === 'SALIDA REGISTRADA' && m.nombre === 'ANA SOFIA RUIZ PAEZ' && sql("SELECT origen FROM accesos_registros WHERE tipo='salida' AND codigo='00412'") === 'manual',
     'ID escrito a mano en la lectura continua: se registra (origen manual) con el nombre de la base');
  ok(!p.errores.length, 'sin errores de JavaScript (salida)' + (p.errores.length ? ': ' + p.errores.join(' | ') : ''));
  await ctx.close();

  // ══ App vieja (sin lectura continua) ══
  ({ ctx, p } = await telefono(b, ses('fijo.entrada', 'E'), false));
  ok(/instala la versión nueva/i.test(await p.textContent('#fijo-estado')) && await p.isHidden('#btn-fijo') && await p.isVisible('.cap.ent'), 'app vieja: pide instalar la nueva y deja el botón ENTRADA');
  await p.click('.cap.ent'); await p.waitForTimeout(500);
  ok((await p.evaluate(() => window.__leer.length)) === 1, 'el botón lee una credencial a la vez, como antes');
  await ctx.close();

  // ══ El guardia elige en el equipo: ENTRADA o SALIDA ══
  const punto1 = { token: 'tok-punto1', rol: 'ACCESOS', usuario: 'punto1', nombre: 'CASETA PUNTO 1', turno: 'D' };
  ({ ctx, p } = await telefono(b, punto1));
  ok(await p.isVisible('.cap.ent') && await p.isVisible('.cap.sal') && await p.isVisible('.cap.inc'), 'el guardia conserva sus botones ENTRADA, SALIDA e INCIDENCIA');
  ok(await p.isVisible('#fijo-elegir') && /elige dónde está/.test(await p.textContent('#fijo-estado')), 'y puede dejar el equipo leyendo solo: «Este equipo está en la ENTRADA / SALIDA»');
  ok((await p.evaluate(() => window.__continuo.length)) === 0, 'no abre la lectura continua hasta que elige');
  await p.screenshot({ path: D + 'fijo_elegir.png', fullPage: true });
  await p.click('.cap.ent'); await p.waitForTimeout(400);
  ok((await p.evaluate(() => window.__leer[0] && window.__leer[0].esperaNombre)) === 0, 'con botones, su lector tampoco espera el nombre (sale de la base)');
  await p.click('#btn-carril-sal'); await p.waitForTimeout(3500);
  let oc = await p.evaluate(() => window.__continuo);
  ok(oc.length === 1 && oc[0].tipo === 'salida' && oc[0].titulo === 'SALIDA', 'elige SALIDA: se abre la lectura continua de SALIDA');
  ok(/Leyendo SALIDAS/.test(await p.textContent('#fijo-tit')) && await p.isHidden('#fijo-elegir'), 'la pantalla dice «Leyendo SALIDAS»');
  await leer(p, '00316', 'PEDRO'); await p.waitForTimeout(1800);
  m = await ultimo(p);
  ok(m && m.titulo === 'SALIDA REGISTRADA' && m.nombre === 'PEDRO VEGA CRUZ' && m.color === 'azul', 'cada credencial se registra como salida');
  ok(sql("SELECT CONCAT(tipo,'|',usuario,'|',turno) FROM accesos_registros WHERE usuario='punto1' AND tipo='salida' AND codigo LIKE '%316' ORDER BY id DESC LIMIT 1") === 'salida|punto1|D',
     'con la cuenta del guardia y su turno (día)');
  await leer(p, '00316', 'PEDRO'); await p.waitForTimeout(600);
  ok((await ultimo(p)).titulo === 'SALIDA YA REGISTRADA', 'la misma persona otra vez: no se duplica');
  // La app se reinicia: el equipo sigue en SALIDA y vuelve a leer solo
  await p.reload(); await p.waitForTimeout(4500);
  oc = await p.evaluate(() => window.__continuo);
  ok(oc.length === 1 && oc[0].tipo === 'salida', 'si la app se reinicia, el equipo vuelve a leer SALIDAS solo');
  // Sale de la cámara a propósito: se vuelve a elegir, y al reiniciar ya no empieza solo
  await p.evaluate(() => window.__emitir('cerrado', {})); await p.waitForTimeout(300);
  ok(await p.isVisible('#fijo-elegir') && (await p.evaluate(() => localStorage.getItem('ca_accesos_carril'))) === '""', 'al salir de la cámara, el equipo vuelve a preguntar dónde está');
  await p.reload(); await p.waitForTimeout(4500);
  ok((await p.evaluate(() => window.__continuo.length)) === 0 && await p.isVisible('#fijo-elegir'), 'y al reiniciar ya no empieza solo');
  // Ahora en ENTRADA
  await p.click('#btn-carril-ent'); await p.waitForTimeout(3500);
  ok((await p.evaluate(() => window.__continuo[0] && window.__continuo[0].tipo)) === 'entrada', 'puede cambiar el equipo a ENTRADA');
  await leer(p, '00316', 'PEDRO'); await p.waitForTimeout(1500);
  ok((await ultimo(p)).titulo === 'ENTRADA REGISTRADA', 'la misma persona sí se registra como entrada (otro tipo)');
  ok(!p.errores.length, 'sin errores de JavaScript (guardia que elige)' + (p.errores.length ? ': ' + p.errores.join(' | ') : ''));
  await ctx.close();

  // App vieja con un guardia normal: pide instalar la nueva y deja los botones
  ({ ctx, p } = await telefono(b, punto1, false));
  ok(/instala la versión nueva/i.test(await p.textContent('#fijo-estado')) && await p.isHidden('#fijo-elegir') && await p.isVisible('.cap.sal'), 'app vieja: pide instalar la nueva; los botones siguen');
  await ctx.close();

  // ══ Cuentas: crear un equipo fijo desde la pantalla ══
  const ca = await b.newContext({ viewport: { width: 1100, height: 900 } });
  await ca.addInitScript(() => localStorage.setItem('ca_sesion', JSON.stringify({ token: 'tok-admin', rol: 'ADMIN', usuario: 'admin', nombre: 'ADMINISTRACION' })));
  const pa = await ca.newPage();
  await pa.goto(B + 'cuentas.html'); await pa.waitForTimeout(900);
  ok(/Equipo fijo · Entradas/.test(await pa.textContent('#lista')), 'la lista de cuentas marca los equipos fijos');
  await pa.click('#b-nueva');
  await pa.fill('#c-nombre', 'Tableta carril 2'); await pa.fill('#c-usuario', 'carril2.entrada'); await pa.selectOption('#c-rol', 'ACCESOS');
  await pa.fill('#c-pass', 'contrasena-larga');
  ok(await pa.isVisible('#campo-turno'), 'la cuenta normal pide el turno');
  await pa.selectOption('#c-modo', 'E');
  ok(await pa.isHidden('#campo-turno') && /reloj/.test(await pa.textContent('#s-modo')), 'equipo fijo: sin turno (va por el reloj)');
  await pa.click('#b-guardar'); await pa.waitForTimeout(900);
  ok(sql("SELECT CONCAT(modo,'|',IFNULL(turno,'-')) FROM usuarios WHERE usuario='carril2.entrada'") === 'E|-', 'cuenta de equipo fijo creada desde Cuentas');
  await pa.screenshot({ path: D + 'fijo_cuentas.png', fullPage: true });
  await b.close();
  console.log(fallas ? fallas + ' FALLAS' : 'TODO BIEN');
  process.exit(fallas ? 1 : 0);
})().catch(e => { console.error('FALLO', e); process.exit(1); });
