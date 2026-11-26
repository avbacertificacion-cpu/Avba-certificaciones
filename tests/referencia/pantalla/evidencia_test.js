// Foto de la credencial como evidencia: pantalla de la caseta con el lector simulado (regresa una foto)
// contra la API real local (base de prueba). Credencial ficticia con el mismo formato.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs');
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
const fila = cod => { const r = sql(`SELECT CONCAT_WS('|', origen, nombre, foto, foto_mini, tipo) FROM accesos_registros WHERE codigo='${cod}' ORDER BY id DESC LIMIT 1`); return r ? r.split('|') : null; };

async function equipo(b, sesion, conLector) {
  const ctx = await b.newContext({ timezoneId: 'America/Mexico_City', viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
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
async function leer(p, lectura, tipo = 'ent') {
  await p.evaluate(l => window.__lecturas.push(l), lectura);
  await p.click('.cap.' + tipo); await p.waitForTimeout(700);
}

(async () => {
  // Estado: Punto 1 con su cuenta, sin equipo; sin registros
  sql("DELETE FROM accesos_registros");
  const b = await chromium.launch();

  // Foto de una credencial ficticia (como la toma la cámara: de pie, 720 × 1280)
  const g = await b.newPage({ viewport: { width: 720, height: 1280 } });
  await g.setContent(`<body style="margin:0;background:#6b6f76;display:flex;align-items:center;justify-content:center;height:1280px;font-family:Arial">
    <div style="width:470px;height:745px;background:#f4f6f9;border-radius:22px;box-shadow:0 0 0 6px #1b2f5a;padding:24px;box-sizing:border-box;font-weight:bold">
      <div style="width:170px;height:210px;background:#9aa3ad;margin:20px auto"></div>
      <div style="background:#1d5fa8;color:#fff;border-radius:40px;text-align:center;font-size:44px;padding:8px;margin:18px 40px">ID: 00287</div>
      <div style="font-size:24px;line-height:1.5">NOMBRE: RAMIRO ESTEBAN<br>&nbsp;&nbsp;&nbsp;&nbsp;VILLARREAL DUARTE<br>EMPRESA: MONTAJES DEL NORTE<br>CARGO: AYUDANTE<br>NSS: 12345678901</div>
      <div style="background:#1b2f5a;color:#fff;text-align:center;font-size:26px;margin-top:22px;padding:10px">CREDENCIAL DE ACCESO</div></div></body>`);
  const foto = (await g.screenshot({ type: 'jpeg', quality: 80 })).toString('base64');
  await g.close();
  ok(foto.length > 20000, 'foto de prueba: ' + Math.round(foto.length * 0.75 / 1024) + ' KB');

  // ── Caseta (Punto 1) ──
  const K = await equipo(b, ses('punto1'), true);
  ok(K.p.errores.length === 0 && (await K.p.inputValue('#lugar')) === 'ACCESO', 'la caseta abre en el acceso');
  // 1) Lectura con foto
  await leer(K.p, { codigo: '00287', nombre: 'Ramiro Esteban Villarreal Duarte', foto, ms: 900 });
  const hecho = await K.p.$eval('#hecho', e => ({ vis: !e.hidden, txt: e.innerText.replace(/\s+/g, ' '), img: !!e.querySelector('img.foto') }));
  ok(hecho.vis && hecho.img && /Foto guardada como evidencia/.test(hecho.txt), 'confirmación con la miniatura: «' + hecho.txt + '»');
  await K.p.screenshot({ path: D + 'evid_hecho.png' });
  ok((await K.p.evaluate(() => window.__opciones[0] && window.__opciones[0].foto)) === true, 'la pantalla le pide la foto al lector (reglas: foto)');
  await K.p.waitForTimeout(3500);
  let f = fila('00287');
  ok(f && f[0] === 'tarjeta' && f[1] === 'RAMIRO ESTEBAN VILLARREAL DUARTE' && /^fotos\//.test(f[2]) && /_m\.jpg$/.test(f[3]), 'en el servidor: nombre, ID y foto (' + (f || []).slice(2, 4).join(' · ') + ')');
  const ruta = f ? D + '../../../uploads/accesos/' + f[2] : '';
  const dim = ruta && fs.existsSync(ruta) ? execSync(`php -r '$i=getimagesize("${ruta}"); echo $i[0]."x".$i[1];'`).toString() : '';
  ok(/^\d+x\d+$/.test(dim) && +dim.split('x')[1] > +dim.split('x')[0], 'la foto quedó guardada (de pie, ' + dim + ')');
  let lista = await K.p.$$eval('#lista li.reg', l => l.map(x => ({ t: x.innerText.replace(/\s+/g, ' '), img: !!x.querySelector('img') })));
  ok(lista.length === 1 && lista[0].img && /00287/.test(lista[0].t), 'en su lista se ve la foto (no el ícono de tarjeta)');

  // 2) ID escrito a mano: también con foto
  await leer(K.p, { codigo: '412', nombre: '', manual: true, foto, ms: 6000 }, 'sal');
  await K.p.waitForTimeout(3500);
  f = fila('412');
  ok(f && f[0] === 'manual' && f[4] === 'salida' && /^fotos\//.test(f[2]), 'ID escrito a mano: se guarda con foto (origen manual)');

  // 3) App anterior (sin foto): se guarda igual, sin foto
  await leer(K.p, { codigo: '00733', nombre: 'Maria Fernanda Rios', ms: 800 });
  await K.p.waitForTimeout(3500);
  f = fila('00733');
  ok(f && f[0] === 'tarjeta' && f[2] === '', 'lectura sin foto (app anterior): se guarda igual');
  lista = await K.p.$$eval('#lista li.reg', l => l.map(x => ({ t: x.innerText.replace(/\s+/g, ' '), tarj: !!x.querySelector('.tarj') })));
  ok(lista.some(x => /00733/.test(x.t) && x.tarj), 'y en la lista lleva el ícono de tarjeta');

  // 4) Sin señal: la foto se queda con el registro en el teléfono y se envía al volver la señal
  await K.ctx.setOffline(true);
  await leer(K.p, { codigo: '00500', nombre: 'Luis Mora Diaz', foto, ms: 900 });
  await K.p.waitForTimeout(1500);
  const pend = await K.p.$$eval('#lista li.reg', l => l.filter(x => /00500/.test(x.innerText)).map(x => ({ cola: /En cola/.test(x.innerText), img: !!x.querySelector('img') })));
  ok(pend.length === 1 && pend[0].cola && pend[0].img, 'sin señal: «En cola» con su foto');
  ok(fila('00500') === null, 'todavía no está en el servidor');
  await K.ctx.setOffline(false);
  await K.p.evaluate(() => window.dispatchEvent(new Event('online')));
  await K.p.waitForTimeout(4000);
  f = fila('00500');
  ok(f && /^fotos\//.test(f[2]), 'al volver la señal se envía con su foto');
  ok(!K.p.errores.length, 'sin errores de JavaScript (caseta)' + (K.p.errores.length ? ': ' + K.p.errores.join(' | ') : ''));

  // ── Administración: ve las fotos ──
  const A = await equipo(b, ses('admin'), false);
  await A.p.click('#f-planta'); await A.p.waitForTimeout(1500);
  await A.p.click('#lista li[data-persona="00287"]'); await A.p.waitForTimeout(1200);
  const fotosMov = await A.p.$$eval('#per-movs .mov-fotos img', l => l.map(i => ({ ok: i.complete && i.naturalWidth > 0, alt: i.alt })));
  ok(fotosMov.length === 1 && fotosMov[0].ok, 'en sus entradas y salidas se ve la foto de la entrada');
  await A.p.screenshot({ path: D + 'evid_movimientos.png' });
  await A.p.click('#per-movs .mov-fotos img'); await A.p.waitForTimeout(1000);
  const visor = await A.p.evaluate(() => {
    const v = document.getElementById('v-foto'), img = document.getElementById('foto-grande');
    const r = img.getBoundingClientRect(), enc = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
    return { vis: !v.hidden, encima: enc === img, cargada: img.complete && img.naturalWidth > 0, pie: document.getElementById('foto-pie').textContent };
  });
  ok(visor.vis && visor.encima && visor.cargada && /ID 00287 · ENTRADA/.test(visor.pie), 'al tocarla se agranda por encima: «' + visor.pie + '»');
  await A.p.screenshot({ path: D + 'evid_grande.png' });
  await A.p.click('#foto-cerrar'); await A.p.click('#per-cerrar');
  // En la lista del turno, el registro con foto lleva su foto
  await A.p.click('#filtro button[data-v="todos"]'); await A.p.waitForTimeout(1500);
  const filasAdm = await A.p.$$eval('#lista li.reg', l => l.map(x => ({ t: x.innerText, img: !!x.querySelector('img[data-id]') })));
  ok(filasAdm.filter(x => x.img).length === 3, 'lista del turno (administración): 3 registros con foto y 1 sin foto');
  ok(!A.p.errores.length, 'sin errores de JavaScript (administración)');

  // ── PDF con fotos: cada foto con su ID y nombre ──
  // La entrada del 00287 y la salida del 412 pueden caer en turnos distintos según la hora
  // a la que corre la prueba (una salida cuenta para el turno que termina): un PDF por turno.
  const textoPdf = async (codigo) => {
    const turno = sql(`SELECT CONCAT(turno_fecha,'-',turno) FROM accesos_registros WHERE codigo='${codigo}' LIMIT 1`);
    const pdf = await post('ACCESOS_PDF', 'tok-admin', { turno, incluir: 'ambos' });
    ok(pdf.status === 'success', 'PDF con fotos del turno ' + turno + ': ' + (pdf.message || pdf.status));
    const ruta = D + '../../../uploads/accesos/pdf/' + (pdf.archivo || '');
    return { ruta, texto: pdf.archivo && fs.existsSync(ruta) ? execSync(`pdftotext -layout "${ruta}" -`).toString() : '' };
  };
  const { ruta: rutaPdf, texto } = await textoPdf('00287');
  const texto412 = (await textoPdf('412')).texto;
  ok(/ID 00287/.test(texto) && /RAMIRO ESTEBAN VILLARREAL DUARTE/.test(texto) && /ID 412/.test(texto412), 'en el PDF cada foto lleva el ID y el nombre');
  if (fs.existsSync(rutaPdf)) execSync(`pdftoppm -r 50 -png -f 1 -l 1 "${rutaPdf}" "${D}evid_pdf"`);
  await b.close();
  console.log(fallas ? fallas + ' FALLAS' : 'TODO BIEN');
  process.exit(fallas ? 1 : 0);
})().catch(e => { console.error('FALLO', e); process.exit(1); });
