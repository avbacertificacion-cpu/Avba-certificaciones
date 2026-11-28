// Datos móviles: lo que bajan los teléfonos debe ser poco. Las respuestas grandes van comprimidas (gzip);
// la lista del turno, la de tomas del servicio médico y la copia de la base sólo bajan si cambiaron; si
// sólo cambió un permiso, sólo bajan los permisos. Base local de prueba; 1,200 personas inventadas.
import { execSync } from 'node:child_process';
import http from 'node:http';
const A = 'http://localhost:8794/api/index.php';
let fallas = 0;
const ok = (c, m) => { if (!c) fallas++; console.log((c ? 'OK    ' : 'FALLA ') + m); };
const sql = q => execSync(`mariadb --default-character-set=utf8mb4 -N -B -uprueba -pprueba -h127.0.0.1 ca_prueba -e "${q}"`).toString().trim();
const get = async (accion, tok, q = {}) => (await fetch(A + '?' + new URLSearchParams({ ...q, action: accion }), { headers: { Authorization: 'Bearer ' + tok } })).json();
const post = async (accion, tok, payload = {}) => (await fetch(A, { method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + tok }, body: JSON.stringify({ action: accion, payload }) })).json();
/** Lo que de verdad viaja por la red (bytes del cuerpo, comprimido o no) */
const bytes = (accion, tok, q = {}, gzip = true) => new Promise((ok, mal) => {
  const u = new URL(A + '?' + new URLSearchParams({ ...q, action: accion }));
  http.get(u, { headers: { Authorization: 'Bearer ' + tok, ...(gzip ? { 'Accept-Encoding': 'gzip' } : {}) } }, r => {
    let n = 0; r.on('data', c => { n += c.length; }); r.on('end', () => ok({ n, gz: r.headers['content-encoding'] === 'gzip' }));
  }).on('error', mal);
});
const kb = n => (n / 1024).toFixed(1) + ' KB';

(async () => {
  // 1,200 personas inventadas, como la base del cliente (ID, nombre, empresa, puesto)
  const emp = ['MONTAJES DEL NORTE SA DE CV', 'GRUAS DEL GOLFO', 'SERVICIOS INDUSTRIALES ROCA', 'TUBERIAS Y SOLDADURAS DEL BAJIO'];
  const nom = ['RAMIRO', 'ANA SOFIA', 'PEDRO', 'LUIS ALBERTO', 'MARIA FERNANDA', 'JOSUE', 'IRMA', 'BRUNO'];
  const ape = ['VILLARREAL', 'DUARTE', 'RUIZ', 'PAEZ', 'VEGA', 'CRUZ', 'MORA', 'DIAZ', 'IBARRA', 'GARZA'];
  const filas = [];
  for (let i = 1; i <= 1200; i++) {
    const c = String(i).padStart(5, '0');
    filas.push(`('${i}','${c}','${nom[i % 8]} ${ape[i % 10]} ${ape[(i * 7) % 10]}','${emp[i % 4]}','AYUDANTE GENERAL',NOW(),NOW(),'prueba')`);
  }
  sql('DELETE FROM personal; DELETE FROM personal_permisos; DELETE FROM ca_ajustes WHERE clave IN (\'padron_version\',\'permisos_version\')');
  sql(`INSERT INTO personal (clave,codigo,nombre,empresa,cargo,creado,actualizado,actualizado_por) VALUES ${filas.join(',')}`);
  sql("INSERT INTO ca_ajustes (clave, valor, actualizado) VALUES ('padron_version', '7', NOW())");

  // ── La copia de la base ──
  const sin = await bytes('PADRON_CAJA', 'tok-punto1', {}, false), con = await bytes('PADRON_CAJA', 'tok-punto1');
  ok(con.gz && con.n < sin.n / 3, `la base de 1,200 personas viaja comprimida: ${kb(con.n)} (sin comprimir ${kb(sin.n)})`);
  let r = await get('PADRON_CAJA', 'tok-punto1');
  const v = r.version;
  ok(r.total === 1200 && /^7\.0$/.test(v), 'versión «base.permisos»: ' + v);
  const igual = await bytes('PADRON_CAJA', 'tok-punto1', { version: v });
  r = await get('PADRON_CAJA', 'tok-punto1', { version: v });
  ok(r.sin_cambios && igual.n < 120, `sin cambios: sólo lo dice (${igual.n} bytes)`);
  // Otro guardia agrega un permiso: sólo cambian los permisos
  r = await post('PERMISO_AGREGAR', 'tok-admin', { codigo: '287', tipos: ['HERRAMIENTA'], detalle: 'Taladro rotomartillo' });
  ok(r.status === 'success', 'se agrega un permiso: ' + (r.message || ''));
  const p16 = await get('ACCESOS_ALERTAS_16H', 'tok-punto1');
  ok(p16.padron_version === '7.1', 'la consulta de cada minuto avisa que cambiaron los permisos: ' + p16.padron_version);
  const sol = await bytes('PADRON_CAJA', 'tok-punto1', { version: v });
  r = await get('PADRON_CAJA', 'tok-punto1', { version: v });
  ok(r.solo_permisos && !r.personas && r.permisos['287'] && r.permisos['287'][0].detalle === 'Taladro rotomartillo' && sol.n < 1024,
     `sólo bajan los permisos (${sol.n} bytes), no la base`);
  r = await get('PADRON_CAJA', 'tok-punto1', { version: '7' });
  ok(r.total === 1200 && r.personas, 'un teléfono con la app de antes (versión sin punto) baja todo, como antes');
  sql("UPDATE personal SET nombre='RAMIRO ESTEBAN VILLARREAL DUARTE' WHERE clave='287'");
  r = await post('PADRON_GUARDAR', 'tok-admin', { codigo: '01288', nombre: 'Persona Nueva Inventada', empresa: 'GRUAS DEL GOLFO' });
  r = await get('PADRON_CAJA', 'tok-punto1', { version: '7.1' });
  ok(r.version === '8.1' && r.total === 1201 && r.personas, 'si cambia una persona, baja la base completa: ' + r.version);
  const p16b = await bytes('ACCESOS_ALERTAS_16H', 'tok-punto1');
  ok(p16b.n < 400, `la consulta de cada minuto pesa ${p16b.n} bytes`);

  // ── La lista del turno de la caseta ──
  sql('DELETE FROM accesos_registros');
  const hoy = new Date().toLocaleString('sv-SE', { timeZone: 'America/Mexico_City' });
  for (let i = 1; i <= 60; i++) sql(`INSERT INTO accesos_registros (uid,tipo,fecha,capturado,recibido,lugar,usuario,usuario_nombre,turno_fecha,turno,nombre,codigo,origen)
    VALUES ('dt${i}','entrada','${hoy.slice(0, 10)}','${hoy}',NOW(),'ACCESO','punto1','PUNTO 1','${hoy.slice(0, 10)}','D','PERSONA INVENTADA ${i}','${String(i).padStart(5, '0')}','tarjeta')`);
  const l1 = await get('LISTAR_ACCESOS', 'tok-punto1', { limite: 60 });
  const lista = await bytes('LISTAR_ACCESOS', 'tok-punto1', { limite: 60 }), listaSin = await bytes('LISTAR_ACCESOS', 'tok-punto1', { limite: 60 }, false);
  ok(l1.firma && lista.gz && lista.n < listaSin.n / 3, `la lista de 60 registros: ${kb(lista.n)} comprimida (${kb(listaSin.n)} sin comprimir)`);
  const t = l1.turnos.join(',');
  r = await get('LISTAR_ACCESOS', 'tok-punto1', { limite: 60, turnos: t, firma: l1.firma });
  const l2 = await bytes('LISTAR_ACCESOS', 'tok-punto1', { limite: 60, turnos: t, firma: l1.firma });
  ok(r.sin_cambios && !r.registros && l2.n < 200, `sin registros nuevos, cada 30 s baja ${l2.n} bytes`);
  sql(`INSERT INTO accesos_registros (uid,tipo,fecha,capturado,recibido,lugar,usuario,usuario_nombre,turno_fecha,turno,nombre,codigo,origen)
    VALUES ('dt99','salida','${hoy.slice(0, 10)}','${hoy}',NOW(),'ACCESO','punto1','PUNTO 1','${hoy.slice(0, 10)}','D','PERSONA INVENTADA 1','00001','tarjeta')`);
  r = await get('LISTAR_ACCESOS', 'tok-punto1', { limite: 60, turnos: t, firma: l1.firma });
  ok(!r.sin_cambios && r.registros.length === 60 && r.firma !== l1.firma, 'con un registro nuevo, baja la lista');

  // ── Las tomas de hoy del servicio médico ──
  const m1 = await get('MEDICO_LISTAR', 'tok-medico');
  r = await get('MEDICO_LISTAR', 'tok-medico', { firma: m1.firma });
  ok(m1.firma && r.sin_cambios && !r.tomas, 'servicio médico: sin tomas nuevas, sólo lo dice');

  sql('DELETE FROM accesos_registros; DELETE FROM personal; DELETE FROM personal_permisos');
  console.log(fallas ? fallas + ' FALLAS' : 'TODO BIEN');
  process.exit(fallas ? 1 : 0);
})().catch(e => { console.error('FALLO', e); process.exit(1); });
