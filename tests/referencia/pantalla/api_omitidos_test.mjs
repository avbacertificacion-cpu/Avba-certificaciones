// Días omitidos por la API (base local de prueba, datos inventados): ayer fue de pruebas y hoy hay
// un registro real. Omitir ayer lo saca del tablero, los turnos, el PDF, En planta, los movimientos
// por ID, las incidencias y los IDs fuera de la base; no se borra nada y se puede volver a contar.
// Sólo Administración; hoy se omite hasta ahora; omitir unas horas; la cola del teléfono sigue.
import { execSync } from 'node:child_process';
const A = 'http://localhost:8794/api/index.php';
let fallas = 0;
const ok = (c, m) => { if (!c) fallas++; console.log((c ? 'OK    ' : 'FALLA ') + m); };
const sql = q => execSync(`mariadb --default-character-set=utf8mb4 -N -B -uprueba -pprueba -h127.0.0.1 ca_prueba -e "${q}"`).toString().trim();
const hoy = new Date().toLocaleDateString('sv-SE', { timeZone: 'America/Mexico_City' });
// El servidor «a las 05:00 de hoy» (sólo en pruebas): antes del reinicio de las 05:30, quien se quedó ayer sigue en planta
const AHORA = `${hoy} 05:00:00`, X = { 'X-Prueba-Ahora': AHORA };
const get = async (accion, tok, q = {}) => {
  const r = await fetch(A + '?' + new URLSearchParams({ ...q, action: accion }), { headers: { ...X, ...(tok ? { Authorization: 'Bearer ' + tok } : {}) } });
  return Object.assign(await r.json(), { http: r.status });
};
const post = async (accion, tok, payload = {}) => {
  const r = await fetch(A, { method: 'POST', headers: { ...X, 'Content-Type': 'application/json', Authorization: 'Bearer ' + tok }, body: JSON.stringify({ action: accion, payload }) });
  return Object.assign(await r.json(), { http: r.status });
};
const dia = n => new Date(new Date(hoy + 'T12:00:00Z').getTime() - n * 86400e3).toISOString().slice(0, 10);
const ayer = dia(1), manana = dia(-1);
const reg = (uid, tipo, cap, codigo, nombre) => sql(
  `INSERT INTO accesos_registros (uid,tipo,fecha,capturado,recibido,lugar,usuario,usuario_nombre,turno_fecha,turno,nombre,codigo,origen)
   VALUES ('${uid}','${tipo}','${cap.slice(0, 10)}','${cap}',NOW(),'ACCESO','punto1','CASETA PUNTO 1','${cap.slice(0, 10)}','D','${nombre}','${codigo}','tarjeta')`);

// ── Ayer: pruebas. Hoy: un registro real ──
reg('omitido-0001', 'entrada', `${ayer} 09:00:00`, '00287', 'RAMIRO ESTEBAN VILLARREAL DUARTE');
reg('omitido-0002', 'salida', `${ayer} 10:00:00`, '00287', 'RAMIRO ESTEBAN VILLARREAL DUARTE');
reg('omitido-0003', 'entrada', `${ayer} 09:30:00`, '00777', 'PERSONA DE PRUEBA');                 // se quedó «en planta»
sql(`INSERT INTO accesos_incidencias (uid,tipo,detalle,fecha,capturado,recibido,turno_fecha,turno,codigo,nombre,usuario,usuario_nombre)
     VALUES ('omitido-inc1','OTRO','Prueba del botón','${ayer}','${ayer} 09:40:00',NOW(),'${ayer}','D','00777','PERSONA DE PRUEBA','punto1','CASETA PUNTO 1')`);
reg('omitido-0004', 'entrada', `${hoy} 04:58:00`, '00316', 'PEDRO VEGA CRUZ');                                // real, de hoy

const periodo = { desde: ayer, hasta: hoy };
const cuadro = async () => {
  const t = await get('TABLERO', 'tok-cliente', periodo);
  const pl = await get('ACCESOS_EN_PLANTA', 'tok-planta');
  const pe = await get('ACCESOS_PERSONA', 'tok-planta', { id: '777' });
  const li = await get('LISTAR_ACCESOS', 'tok-admin', { turnos: `${ayer}-D` });
  const fa = await get('PADRON_FALTANTES', 'tok-admin');
  return { e: t.kpis.entradas, s: t.kpis.salidas, inc: t.kpis.incidencias, planta: pl.personas.map(x => x.id).sort().join(','),
           mov777: pe.registros, incAyer: (li.incidencias || []).length, regAyer: li.registros.length, totAyer: li.totales[`${ayer}-D`],
           faltan: fa.ids.map(x => x.codigo).sort().join(',') };
};

// ── Antes de omitir ──
let c = await cuadro();
ok(c.e === 3 && c.s === 1 && c.inc === 1, `antes: tablero con 3 entradas, 1 salida, 1 incidencia (${c.e}/${c.s}/${c.inc})`);
ok(c.planta === '00316,00777' && c.mov777 === 1 && c.regAyer === 3 && c.incAyer === 1, `antes: en planta ${c.planta}; turno de ayer con ${c.regAyer} registros`);

// ── Quién puede ──
let r = await get('OMITIDOS_LISTAR', 'tok-planta');
ok(r.status === 'error' && /Sólo Administración/.test(r.message), 'el administrador de la planta no omite días');
r = await post('OMITIR', 'tok-punto1', { fecha: ayer });
ok(r.http === 403, 'el guardia tampoco (lo rechaza el enrutador)');
r = await post('OMITIR', 'tok-cliente', { fecha: ayer });
ok(r.http === 403, 'ni el cliente');
r = await get('OMITIR', 'tok-admin', { fecha: ayer, aplicar: 1 });
ok(r.http === 405, 'por GET no se permite');

// ── Revisar antes de omitir ──
r = await post('OMITIR', 'tok-admin', { fecha: ayer });
ok(r.status === 'success' && r.revision && r.entradas === 2 && r.salidas === 1 && r.incidencias === 1 && r.personas === 2 && r.cuentas === 'CASETA PUNTO 1',
   `revisión: ${r.entradas} entradas, ${r.salidas} salida, ${r.incidencias} incidencia, ${r.personas} personas, cuentas «${r.cuentas}»`);
ok(r.desde === `${ayer} 00:00:00` && r.hasta === `${ayer} 23:59:59`, 'todo el día: de 00:00 a 23:59');
ok(sql('SELECT COUNT(*) FROM accesos_omitidos') === '0', 'revisar no guarda nada');
r = await post('OMITIR', 'tok-admin', { fecha: manana });
ok(r.status === 'error' && /todavía no pasa/.test(r.message), 'un día que no ha pasado: no');
r = await post('OMITIR', 'tok-admin', { fecha: dia(5) });
ok(r.status === 'error' && /no hay registros/.test(r.message), 'un día sin registros: no hay nada que omitir');
r = await post('OMITIR', 'tok-admin', { fecha: ayer, desde: '11:00', hasta: '09:00' });
ok(r.status === 'error' && /antes/.test(r.message), 'horas al revés: error');
r = await post('OMITIR', 'tok-admin', { fecha: hoy });
ok(r.status === 'success' && r.hasta_ahora && r.hasta === AHORA && r.entradas === 1, 'hoy: sólo hasta ahora (lo que se registre después sí cuenta)');

// ── Omitir ayer ──
r = await post('OMITIR', 'tok-admin', { fecha: ayer, motivo: 'Pruebas del lector', aplicar: true });
ok(r.status === 'success' && /3 registros y 1 incidencia ya no cuentan/.test(r.message), r.message);
ok(sql('SELECT COUNT(*) FROM accesos_registros') === '4' && sql('SELECT COUNT(*) FROM accesos_incidencias') === '1', 'no se borró nada');
c = await cuadro();
ok(c.e === 1 && c.s === 0 && c.inc === 0, `tablero: sólo lo real (${c.e} entrada, ${c.s} salidas, ${c.inc} incidencias)`);
ok(c.planta === '00316', 'En planta: ya no aparece quien se quedó en las pruebas');
ok(c.mov777 === 0, 'movimientos por ID: sin los de las pruebas');
ok(c.regAyer === 0 && c.totAyer.entrada === 0 && c.totAyer.salida === 0 && c.incAyer === 0, 'turno de ayer: sin registros, contadores en 0 y sin incidencias');
ok(!c.faltan.includes('00777'), 'IDs fuera de la base: sin los de las pruebas (' + (c.faltan || 'ninguno') + ')');
r = await post('ACCESOS_PDF_DATOS', 'tok-planta', { turno: `${ayer}-D` });
ok(r.status === 'error' && /No hay registros/.test(r.message), 'PDF del turno de ayer: no hay registros');
r = await get('TABLERO', 'tok-cliente', { desde: ayer, hasta: ayer });
ok(r.kpis.entradas === 0 && r.por_dia[0].entradas === 0, 'el tablero de sólo ayer: en cero');

// La cola del teléfono: lo omitido sigue «llegado» (no se reenvía ni se duplica)
r = await post('ACCESOS_EXISTEN', 'tok-punto1', { uids: ['omitido-0001', 'omitido-inc1', 'no-existe-0001'] });
ok(r.existen['omitido-0001'] && r.existen['omitido-inc1'] && !r.existen['no-existe-0001'], 'ACCESOS_EXISTEN reconoce lo omitido como ya recibido');
r = await post('OMITIR', 'tok-admin', { fecha: ayer, desde: '09:00', hasta: '10:00' });
ok(r.status === 'error' && /ya está omitido/.test(r.message), 'unas horas de un día ya omitido: ya está');

// ── La lista ──
r = await get('OMITIDOS_LISTAR', 'tok-admin');
const d1 = r.dias.find(x => x.fecha === ayer), d0 = r.dias.find(x => x.fecha === hoy);
ok(d1 && d1.entradas === 2 && d1.salidas === 1 && d1.omitidos === 3 && d1.incidencias === 1 && d1.inc_omitidas === 1 && d1.cuentas === 'CASETA PUNTO 1' && d1.primero === '09:00',
   'días con registros: ayer, todo omitido');
ok(d0 && d0.entradas === 1 && d0.omitidos === 0, 'hoy: sin omitir');
ok(r.omitidos.length === 1 && r.omitidos[0].dia_completo && r.omitidos[0].motivo === 'Pruebas del lector' && r.omitidos[0].por === 'ADMINISTRACION'
   && r.omitidos[0].entradas === 2 && r.omitidos[0].incidencias === 1, 'omitidos: ayer, completo, con motivo, quién y cuánto');
const idAyer = r.omitidos[0].id;

// ── Volver a contar ──
r = await post('OMITIDO_QUITAR', 'tok-planta', { id: idAyer });
ok(r.status === 'error', 'el administrador de la planta no lo vuelve a contar');
r = await post('OMITIDO_QUITAR', 'tok-admin', { id: idAyer });
ok(r.status === 'success' && /vuelven a contar/.test(r.message), r.message);
c = await cuadro();
ok(c.e === 3 && c.s === 1 && c.inc === 1 && c.planta === '00316,00777' && c.regAyer === 3, 'todo vuelve a contar');
r = await post('OMITIDO_QUITAR', 'tok-admin', { id: idAyer });
ok(r.status === 'error', 'otra vez: ya no está omitido');
r = await get('OMITIDOS_LISTAR', 'tok-admin');
ok(r.omitidos.length === 0 && r.historial.length === 1 && r.historial[0].quitado_por === 'ADMINISTRACION', 'queda en el historial');

// ── Sólo unas horas: de 09:15 a 09:45 (la entrada de prueba de las 09:30 y la incidencia de las 09:40) ──
r = await post('OMITIR', 'tok-admin', { fecha: ayer, desde: '09:15', hasta: '09:45', aplicar: true });
ok(r.status === 'success' && r.entradas === 1 && r.salidas === 0 && r.incidencias === 1 && r.hasta === `${ayer} 09:45:59`, 'unas horas: 1 entrada y 1 incidencia');
c = await cuadro();
ok(c.e === 2 && c.s === 1 && c.inc === 0 && c.planta === '00316' && c.regAyer === 2, 'lo demás de ese día sí cuenta');
r = await get('LISTAR_ACCESOS', 'tok-admin', { turnos: `${ayer}-D` });
ok(r.registros.map(x => x.num + x.tipo).sort().join(',') === '1entrada,1salida', 'la numeración del turno sigue sin huecos (ENTRADA 001, SALIDA 001)');

// ── Base vieja sin la tabla: se crea sola ──
sql('DROP TABLE accesos_omitidos');
r = await get('TABLERO', 'tok-cliente', periodo);
ok(r.status === 'success' && r.kpis.entradas === 3, 'sin la tabla, todo sigue funcionando (y cuenta todo)');
r = await get('OMITIDOS_LISTAR', 'tok-admin');
ok(r.status === 'success' && r.omitidos.length === 0, 'y la tabla se vuelve a crear');

console.log(fallas ? fallas + ' FALLAS' : 'TODO BIEN');
process.exit(fallas ? 1 : 0);
