// Pantalla real de control de accesos (accesos.html) con el lector de la app simulado
// y la API simulada (no toca ninguna base de datos).
const { chromium } = require('playwright');
const B = 'http://localhost:8793/';
const D = __dirname + '/';
const ok = (c, m) => console.log((c ? 'OK   ' : 'FALLA') + ' ' + m);

function api(estado) {
  return async (route) => {
    const req = route.request();
    const url = new URL(req.url());
    let accion = url.searchParams.get('action');
    let cuerpo = req.postData() || '';
    if (!accion && cuerpo) {
      const m = /name="action"\r\n\r\n([^\r]+)/.exec(cuerpo); if (m) accion = m[1];
      try { const j = JSON.parse(cuerpo); accion = j.action; estado.ultimoJson = j; } catch (e) {}
    }
    estado.acciones.push(accion);
    const json = (o) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(o) });
    if (accion === 'ACCESO_REGISTRAR') {
      const campo = (n) => { const m = new RegExp('name="' + n + '"\\r\\n\\r\\n([^\\r]*)').exec(cuerpo); return m ? m[1] : null; };
      const uid = campo('uid');
      (estado.cuerpos = estado.cuerpos || []).push(cuerpo);
      estado.registrados.push({ uid, tipo: campo('tipo'), codigo: campo('codigo'), persona: campo('persona'), origen: campo('origen'), lugar: campo('lugar'), conFoto: /name="foto"/.test(cuerpo) });
      estado.ids[uid] = 100 + estado.registrados.length;
      return json({ status: 'success', id: estado.ids[uid] });
    }
    if (accion === 'ACCESOS_EXISTEN') {
      const uids = (estado.ultimoJson && estado.ultimoJson.payload && estado.ultimoJson.payload.uids) || [];
      const ex = {}; uids.forEach(u => { if (estado.ids[u]) ex[u] = estado.ids[u]; });
      return json({ status: 'success', existen: ex });
    }
    if (accion === 'LISTAR_ACCESOS_LUGARES') return json({ status: 'success', lugares: ['PUNTO DE CONTROL 1', 'PUNTO DE CONTROL 2', 'PUNTO DE CONTROL 3', 'PUNTO DE CONTROL 4'] });
    if (accion === 'ACCESOS_MI_PUNTO') return json({ status: 'success', punto: 'PUNTO DE CONTROL 1', nombre_punto: 'Punto de control 1' });
    if (accion === 'ACCESOS_PUNTOS') return json({ status: 'success', puntos: [], cuentas: [], puede_editar: true });
    if (accion === 'LISTAR_ACCESOS') {
      const t = url.searchParams.get('turnos').split(',')[0];
      return json({ status: 'success', turnos: [t], mas: false, lugares: [], totales: { [t]: { entrada: 2, salida: 1 } }, registros: [
        { id: 7, uid: 'srv-1', tipo: 'salida', num: 1, turno: t, capturado: t.slice(0, 10) + ' 14:02:00', hora: '14:02:00', lugar: 'QUANTUM ENERGY CASETA 1', nota: '', usuario: 'caseta1', nombre: 'CASETA 1', persona: 'LUIS FLORES DIAZ', codigo: '7788', origen: 'tarjeta', con_foto: false, puede_borrar: true },
        { id: 6, uid: 'srv-2', tipo: 'entrada', num: 2, turno: t, capturado: t.slice(0, 10) + ' 07:00:00', hora: '07:00:00', lugar: 'QUANTUM ENERGY CASETA 1', nota: '', usuario: 'caseta1', nombre: 'CASETA 1', persona: '', codigo: '55120', origen: 'manual', con_foto: false, puede_borrar: true },
        { id: 5, uid: 'srv-3', tipo: 'entrada', num: 1, turno: t, capturado: t.slice(0, 10) + ' 06:05:00', hora: '06:05:00', lugar: 'QUANTUM ENERGY CASETA 1', nota: '', usuario: 'caseta1', nombre: 'CASETA 1', persona: '', codigo: '', origen: 'foto', con_foto: true, puede_borrar: true }
      ] });
    }
    if (accion === 'ACCESOS_PDF_DATOS') {
      estado.pdfPedido = estado.ultimoJson && estado.ultimoJson.payload;
      return json({ status: 'success', archivo: 'ACCESOS_2026-11-26-D_TODOS_datos_1.pdf', url: 'descargar.php?tipo=accesos_pdf&f=ACCESOS_2026-11-26-D_TODOS_datos_1.pdf', personas: 4, entradas: 4, salidas: 3, sin_salida: 2, sin_entrada: 1, sin_codigo: 1, renglones: 5, paginas: 1, bytes: 30000 });
    }
    return json({ status: 'success' });
  };
}

async function pantalla(b, rol, conLector) {
  const estado = { acciones: [], registrados: [], ids: {}, abiertos: [] };
  const ctx = await b.newContext({ timezoneId: 'America/Mexico_City', viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
  await ctx.addInitScript(([rol, conLector]) => {
    localStorage.setItem('avba_session', JSON.stringify({ token: 'prueba', rol, usuario: rol === 'ACCESOS' ? 'caseta1' : 'admin', nombre: rol === 'ACCESOS' ? 'Caseta 1' : 'Administración' }));
    localStorage.setItem('avba_accesos_lugar', JSON.stringify('QUANTUM ENERGY CASETA 1'));
    if (conLector) {
      window.__lecturas = []; window.__abiertos = []; window.__opciones = [];
      window.Capacitor = { isNativePlatform: () => true, Plugins: { LectorTarjeta: {
        leer: (o) => { window.__opciones.push(o); return new Promise(r => setTimeout(() => r(window.__lecturas.shift() || { cancelado: true }), 250)); },
        abrir: (o) => { window.__abiertos.push(o.url); return Promise.resolve({}); }
      } } };
    }
  }, [rol, conLector]);
  const p = await ctx.newPage();
  p.on('pageerror', e => (estado.errores = estado.errores || []).push(e.message));
  await p.route('**/api/index.php**', api(estado));
  await p.route('**/demo/sala.php?tarjeta=1', r => r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, reglas: { longitud: 0, noNombre: ['QUANTUM', 'ENERGY'] } }) }));
  await p.route('**/descargar.php**', r => r.fulfill({ status: 200, contentType: 'image/png', body: Buffer.alloc(0) }));
  await p.goto(B + 'accesos.html'); await p.waitForTimeout(900);
  return { ctx, p, estado };
}

(async () => {
  const b = await chromium.launch();
  // ── 1) Cuenta de caseta en la app (con lector) ──
  let { ctx, p, estado } = await pantalla(b, 'ACCESOS', true);
  ok((await p.inputValue('#lugar')) === 'PUNTO DE CONTROL 1', 'la caseta queda en su punto: ' + await p.inputValue('#lugar'));
  ok((await p.textContent('#lbl-ent')) === 'Leer tarjeta', 'botón ENTRADA dice «Leer tarjeta»');
  ok(await p.isHidden('#cam-apagada'), 'sin la cámara continua de la página (la hace el lector)');
  ok(await p.isHidden('#tarjeta-pdf'), 'la cuenta de caseta no ve el PDF');
  await p.evaluate(() => window.__lecturas.push({ codigo: '004512', nombre: 'Juan Pérez López', ms: 820 }));
  await p.click('.cap.ent'); await p.waitForTimeout(500);
  const hecho = await p.$eval('#hecho', e => ({ visible: !e.hidden, txt: e.innerText.replace(/\s+/g, ' '), cls: e.className }));
  ok(hecho.visible && /ENTRADA REGISTRADA Juan Pérez López ID 004512/.test(hecho.txt) && /entrada/.test(hecho.cls), 'confirmación grande: ' + hecho.txt);
  await p.screenshot({ path: D + 'acc_hecho.png' });
  await p.waitForTimeout(2500);
  const r1 = estado.registrados[0];
  ok(r1 && r1.tipo === 'entrada' && r1.codigo === '004512' && r1.persona === 'Juan Pérez López' && r1.origen === 'tarjeta' && !r1.conFoto, 'se envió sin foto: ' + JSON.stringify(r1));
  ok(/name="dispositivo"\r\n\r\neq-/.test(estado.cuerpos[0] || ''), 'el registro lleva el identificador del equipo');
  const op = await p.evaluate(() => window.__opciones[0]);
  ok(op && op.orientacion === 'vertical' && /credencial/.test(op.subtitulo || ''), 'la cámara recibe la credencial de pie (orientacion: ' + (op && op.orientacion) + ')');
  { const rs = (await (await fetch('http://localhost:8793/demo/sala.php?tarjeta=1')).json()).reglas || {};
    ok(rs.orientacion === 'vertical' && rs.noNombre.includes('SOLUCIONES') && rs.noNombre.includes('AYUDANTE') && rs.noNombre.includes('THE'), 'el servidor entrega las reglas de la credencial (vertical; empresa, oficios y logotipos no son nombre)'); }
  ok(op && /ENTRADA · PUNTO DE CONTROL 1/.test(op.titulo) && op.simular === false && op.minDigitos === 4, 'opciones del lector: ' + op.titulo);
  // Salida con código escrito a mano
  await p.evaluate(() => window.__lecturas.push({ codigo: '004512', nombre: '', manual: true, ms: 5000 }));
  await p.click('.cap.sal'); await p.waitForTimeout(400);
  const hecho2 = await p.$eval('#hecho', e => e.innerText.replace(/\s+/g, ' '));
  ok(/SALIDA REGISTRADA Nombre no leído ID 004512/.test(hecho2), 'salida con código a mano: ' + hecho2);
  await p.waitForTimeout(2600);
  const r2 = estado.registrados[1];
  ok(r2 && r2.tipo === 'salida' && r2.origen === 'manual', 'salida enviada como «manual»');
  // Cancelar el lector no registra nada
  await p.evaluate(() => window.__lecturas.push({ cancelado: true }));
  await p.click('.cap.ent'); await p.waitForTimeout(800);
  ok(estado.registrados.length === 2 && await p.isHidden('#hecho'), 'cancelar el lector no registra');
  // Lista: tarjetas con nombre y código, foto donde la hay
  const lista = await p.$$eval('#lista li.reg', l => l.map(x => ({ t: x.innerText.replace(/\s+/g, ' '), tarj: !!x.querySelector('.tarj'), img: !!x.querySelector('img') })));
  ok(lista.some(x => x.tarj && /LUIS FLORES DIAZ · 7788/.test(x.t)), 'lista: tarjeta con nombre y código');
  ok(lista.some(x => x.tarj && /Nombre no leído · 55120/.test(x.t)), 'lista: código sin nombre');
  ok(lista.some(x => !x.tarj && (x.img || /Foto sin señal/.test(x.t))), 'lista: el registro con foto sigue con su foto (no como tarjeta)');
  ok(lista.some(x => /Juan Pérez López · 004512/.test(x.t)), 'lista: lo recién registrado con su nombre (' + lista.length + ' renglones)');
  await p.screenshot({ path: D + 'acc_lista.png', fullPage: true });
  ok(!estado.errores, 'sin errores de JavaScript' + (estado.errores ? ': ' + estado.errores.join(' | ') : ''));
  await ctx.close();

  // ── 2) Administración en la app: PDF de datos ──
  ({ ctx, p, estado } = await pantalla(b, 'ADMIN', true));
  ok(await p.isVisible('#btn-pdf-datos'), 'administración ve «Descargar PDF de entradas y salidas»');
  await p.click('#btn-pdf-datos'); await p.waitForTimeout(800);
  const res = await p.$eval('#pdf-datos-res', e => e.innerText.replace(/\s+/g, ' '));
  ok(/4 personas · 4 entradas · 3 salidas/.test(res) && /2 sin salida/.test(res), 'resultado: ' + res);
  ok(estado.pdfPedido && /^\d{4}-\d{2}-\d{2}-[DN]$/.test(estado.pdfPedido.turno), 'pide el turno elegido: ' + JSON.stringify(estado.pdfPedido));
  await p.click('#pdf-datos-res a'); await p.waitForTimeout(400);
  const abiertos = await p.evaluate(() => window.__abiertos);
  ok(abiertos.length === 1 && /descargar\.php\?tipo=accesos_pdf&f=ACCESOS_.*_datos_1\.pdf&token=prueba/.test(abiertos[0]), 'en la app el PDF se abre fuera: ' + abiertos[0]);
  await p.screenshot({ path: D + 'acc_pdf.png', fullPage: true });
  ok(!estado.errores, 'sin errores de JavaScript (admin)' + (estado.errores ? ': ' + estado.errores.join(' | ') : ''));
  await ctx.close();

  // ── 3) En el navegador (sin lector): todo como antes ──
  ({ ctx, p, estado } = await pantalla(b, 'ACCESOS', false));
  ok((await p.textContent('#lbl-ent')) === 'Foto de la identificación', 'navegador: ENTRADA sigue con foto');
  ok(await p.isVisible('#cam-apagada'), 'navegador: la cámara continua sigue disponible');
  ok(!estado.errores, 'sin errores de JavaScript (navegador)' + (estado.errores ? ': ' + estado.errores.join(' | ') : ''));
  await ctx.close();
  await b.close();
})().catch(e => { console.error('FALLO', e); process.exit(1); });
