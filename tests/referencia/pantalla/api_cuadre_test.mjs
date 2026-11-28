// Todo cuadra: entradas − salidas = personal en planta, en la lista del turno, En planta, el tablero y el PDF.
//  - Varias entradas seguidas del mismo ID sin salida (lo regresaron sin pasar su salida): cuenta sólo la última,
//    y su hora es la de esa última entrada.
//  - Salidas sin entrada registrada: van aparte (se listan para revisarlas) y no se restan.
// Base local de prueba; personas e IDs inventados. El servidor «a las 17:00» de ayer (sólo en pruebas).
import { execSync } from 'node:child_process';
const A = 'http://localhost:8794/api/index.php';
let fallas = 0;
const ok = (c, m) => { if (!c) fallas++; console.log((c ? 'OK    ' : 'FALLA ') + m); };
const sql = q => execSync(`mariadb --default-character-set=utf8mb4 -N -B -uprueba -pprueba -h127.0.0.1 ca_prueba -e "${q}"`).toString().trim();
const hoy = new Date().toLocaleDateString('sv-SE', { timeZone: 'America/Mexico_City' });
const Y = new Date(new Date(hoy + 'T12:00:00Z').getTime() - 86400e3).toISOString().slice(0, 10);
const AHORA = `${Y} 17:00:00`;
const get = async (accion, tok, q = {}) => (await fetch(A + '?' + new URLSearchParams({ ...q, action: accion }), { headers: { Authorization: 'Bearer ' + tok, 'X-Prueba-Ahora': AHORA } })).json();
let n = 0;
const reg = (tipo, hora, codigo, nombre, usuario = 'punto1') => {
  n++;
  sql(`INSERT INTO accesos_registros (uid,tipo,fecha,capturado,recibido,lugar,usuario,usuario_nombre,turno_fecha,turno,nombre,codigo,origen)
       VALUES ('cq${n}','${tipo}','${Y}','${Y} ${hora}',NOW(),'ACCESO','${usuario}','${usuario.toUpperCase()}','${Y}','D','${nombre}','${codigo}','tarjeta')`);
};

(async () => {
  sql('DELETE FROM accesos_registros');
  reg('entrada', '07:00:00', '00287', 'RAMIRO ESTEBAN VILLARREAL DUARTE');
  reg('entrada', '07:10:00', '00287', 'RAMIRO ESTEBAN VILLARREAL DUARTE', 'punto2');   // lo regresaron: entra otra vez
  reg('entrada', '07:50:00', '287', 'RAMIRO ESTEBAN VILLARREAL DUARTE');               // y otra (ID sin ceros)
  reg('entrada', '07:05:00', '00412', 'ANA SOFIA RUIZ PAEZ');
  reg('salida', '12:00:00', '00412', 'ANA SOFIA RUIZ PAEZ', 'punto2');
  reg('entrada', '08:00:00', '00316', 'PEDRO VEGA CRUZ');
  reg('salida', '09:00:00', '00733', 'MARIA FERNANDA RIOS', 'punto2');                 // salida sin entrada
  reg('salida', '10:00:00', '00500', 'LUIS MORA DIAZ', 'punto2');                      // otra sin entrada
  reg('entrada', '11:00:00', '00600', 'JUAN SOTO RIOS');
  reg('salida', '11:30:00', '00600', 'JUAN SOTO RIOS', 'punto2');
  reg('salida', '11:40:00', '00600', 'JUAN SOTO RIOS', 'punto2');                      // salida doble: la segunda sin entrada

  // ── Lista del turno (Administración) ──
  const l = await get('LISTAR_ACCESOS', 'tok-admin', { turnos: `${Y}-D`, limite: 100 });
  const t = l.totales[`${Y}-D`];
  const planta = await get('ACCESOS_EN_PLANTA', 'tok-admin');
  ok(t.entrada === 4 && t.salida === 2 && t.repetidas === 2 && t.sin_entrada === 3, 'turno: 4 entradas (de las 3 de 00287 cuenta 1), 2 salidas; aparte 2 repetidas y 3 salidas sin entrada → ' + JSON.stringify(t));
  ok(t.entrada - t.salida === planta.total && planta.total === 2, `entradas − salidas = en planta: ${t.entrada} − ${t.salida} = ${planta.total}`);
  const de287 = l.registros.filter(x => x.codigo.replace(/^0+/, '') === '287').sort((a, b) => a.hora.localeCompare(b.hora));
  ok(de287.map(x => x.no_cuenta || x.num).join(',') === 'repetida,repetida,' + de287[2].num && de287[2].num > 0, 'las dos primeras entradas de 00287 dicen «no cuenta» y la última lleva número: ' + de287.map(x => x.hora + ' ' + (x.no_cuenta || x.num)).join(' · '));
  const nums = l.registros.filter(x => x.tipo === 'entrada' && x.num).map(x => x.num).sort((a, b) => a - b).join(',');
  ok(nums === '1,2,3,4', 'la numeración de las entradas que cuentan va de 1 a 4: ' + nums);
  const sinE = l.registros.filter(x => x.no_cuenta === 'sin_entrada').map(x => x.codigo + ' ' + x.hora).join(', ');
  ok(/00733/.test(sinE) && /00500/.test(sinE) && /00600 11:40/.test(sinE), 'las salidas sin entrada se ven en la lista para revisarlas: ' + sinE);
  const p287 = planta.personas.find(x => x.id === '00287') || {};
  ok(p287.entrada && p287.entrada.slice(11, 16) === '07:50', 'En planta: 00287 con su última entrada (07:50): ' + p287.entrada);

  // ── La caseta (punto2) ve lo suyo con la misma regla ──
  const g = await get('LISTAR_ACCESOS', 'tok-punto2', { turnos: `${Y}-D`, limite: 100 });
  const tg = g.totales[`${Y}-D`] || {};
  ok(tg.salida === 2 && tg.sin_entrada === 3 && tg.entrada === 0 && tg.repetidas === 1, 'el guardia punto2 ve sus lecturas con la misma regla: ' + JSON.stringify(tg));

  // ── Tablero: turno de hoy desde 05:30 hasta las 17:00 ──
  const r = await get('TABLERO', 'tok-cliente', { desde: Y, hasta: Y, hora_desde: '05:30', hora_hasta: '17:00' });
  const k = r.kpis, c = r.cuadre;
  ok(k.entradas === 4 && k.salidas === 2 && k.dentro === 2 && k.entradas - k.salidas === k.dentro && c.inicio === 0,
     `tablero: ${k.entradas} entradas − ${k.salidas} salidas = ${k.dentro} en planta`);
  ok(c.entradas_repetidas === 2 && c.salidas_sin_entrada === 3, 'aparte: 2 entradas repetidas y 3 salidas sin entrada');
  ok((r.en_planta.find(x => x.id === '00287') || {}).entrada === `${Y} 07:50:00`, 'en el tablero también entró a las 07:50');
  const ph = Object.fromEntries(r.por_hora.map(x => [x.hora, x]));
  ok(ph[7].entradas === 2 && ph[9].salidas === 0 && ph[11].entradas === 1 && ph[11].salidas === 1 && ph[12].salidas === 1,
     'la gráfica por hora cuenta lo mismo (07: 00287 y 00412; las salidas sin entrada no)');

  // ── PDF de datos del turno: un renglón por estancia ──
  const pdf = await (await fetch(A, { method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: 'Bearer tok-admin', 'X-Prueba-Ahora': AHORA },
    body: JSON.stringify({ action: 'ACCESOS_PDF_DATOS', payload: { turno: `${Y}-D` } }) })).json();
  ok(pdf.status === 'success' && pdf.entradas === 4 && pdf.salidas === 2 && pdf.sin_salida === 2 && pdf.sin_entrada === 3,
     `PDF: ${pdf.entradas} entradas, ${pdf.salidas} salidas, ${pdf.sin_salida} sin salida (= en planta), ${pdf.sin_entrada} salidas sin entrada aparte`);

  sql('DELETE FROM accesos_registros');
  console.log(fallas ? fallas + ' FALLAS' : 'TODO BIEN');
  process.exit(fallas ? 1 : 0);
})().catch(e => { console.error('FALLO', e); process.exit(1); });
