// API de incidencias (botón INCIDENCIA de la caseta), contra la copia local con base de prueba.
// Datos inventados.
import { execSync } from 'node:child_process';
const B = 'http://localhost:8794/';
const A = B + 'api/index.php';
let fallas = 0;
const ok = (c, m) => { if (!c) fallas++; console.log((c ? 'OK    ' : 'FALLA ') + m); };
const sql = q => execSync(`mariadb --default-character-set=utf8mb4 -N -B -uprueba -pprueba -h127.0.0.1 ca_prueba -e "${q}"`).toString().trim();
const get = async (accion, tok, q = {}) => {
  const r = await fetch(A + '?' + new URLSearchParams({ ...q, action: accion }), { headers: { Authorization: 'Bearer ' + tok } });
  return Object.assign(await r.json(), { http: r.status });
};
const post = async (accion, tok, payload = {}) => {
  const r = await fetch(A, { method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + tok }, body: JSON.stringify({ action: accion, payload }) });
  return Object.assign(await r.json(), { http: r.status });
};
// JPEG mínimo válido (1×1) como «foto de la credencial»
const JPEG = Buffer.from('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAMCAgICAgMCAgIDAwMDBAYEBAQEBAgGBgUGCQgKCgkICQkKDA8MCgsOCwkJDRENDg8QEBEQCgwSExIQEw8QEBD/yQALCAABAAEBAREA/8wABgAQEAX/2gAIAQEAAD8A0s8g/9k=', 'base64');
const incidencia = async (tok, campos, conFoto = false) => {
  const fd = new FormData();
  for (const [k, v] of Object.entries({ action: 'ACCESO_INCIDENCIA', ...campos })) fd.append(k, v);
  if (conFoto) fd.append('foto', new Blob([JPEG], { type: 'image/jpeg' }), 'cred.jpg');
  const r = await fetch(A, { method: 'POST', headers: { Authorization: 'Bearer ' + tok }, body: fd });
  return Object.assign(await r.json(), { http: r.status });
};
const ahora = () => new Date().toLocaleString('sv-SE', { timeZone: 'America/Mexico_City' });
const EQ = 'eq-prueba-incidencias-1';

await post('ACCESOS_PUNTO_ASIGNAR', 'tok-admin', { punto: 'PUNTO DE CONTROL 1', usuario: 'punto1' });
await post('ACCESOS_PUNTO_ASIGNAR', 'tok-admin', { punto: 'PUNTO DE CONTROL 2', usuario: 'punto2' });
await get('ACCESOS_MI_PUNTO', 'tok-punto1', { dispositivo: EQ });
await get('ACCESOS_MI_PUNTO', 'tok-punto2', { dispositivo: 'eq-prueba-incidencias-2' });

// ── Validaciones ──
let r = await incidencia('tok-punto1', { uid: 'inc-000000000001', incidencia: 'NO_EXISTE', codigo: '00287', dispositivo: EQ });
ok(r.status === 'error' && /tipo de incidencia/.test(r.message), 'tipo desconocido: ' + r.message);
r = await incidencia('tok-punto1', { uid: 'inc-000000000001', incidencia: 'OTRO', codigo: '00287', dispositivo: EQ });
ok(r.status === 'error' && /Describe/.test(r.message), '«Otro» sin descripción: ' + r.message);
r = await incidencia('tok-punto1', { uid: 'inc-000000000001', incidencia: 'CONDUCTA', dispositivo: EQ });
ok(r.status === 'error' && /quién es/.test(r.message), 'sin ID, nombre ni foto: ' + r.message);
r = await incidencia('tok-punto1', { uid: 'inc-000000000001', incidencia: 'CONDUCTA', codigo: '00287', dispositivo: 'eq-otro-equipo-xx' });
ok(r.status === 'error' && r.codigo === 'OTRO_EQUIPO', 'desde otro equipo: rechazada (como las entradas)');
r = await get('ACCESO_INCIDENCIA', 'tok-punto1', { uid: 'inc-000000000001', incidencia: 'CONDUCTA', codigo: '1' });
ok(r.http === 405, 'por GET no se permite');

// ── Registrar ──
r = await incidencia('tok-punto1', { uid: 'inc-000000000001', incidencia: 'ALCOHOLIMETRO', detalle: 'Prueba positiva 0.4', codigo: '00287',
  persona: 'Ramiro Esteban Villarreal Duarte', origen: 'tarjeta', capturado: ahora(), lugar: 'X', dispositivo: EQ }, true);
ok(r.status === 'success' && /Alcoholímetro positivo · RAMIRO ESTEBAN VILLARREAL DUARTE/.test(r.message), 'alcoholímetro con foto: ' + r.message);
const idInc = r.id;
const fila = sql("SELECT CONCAT(tipo,'|',codigo,'|',nombre,'|',lugar,'|',usuario,'|',origen,'|',(foto<>'')) FROM accesos_incidencias WHERE uid='inc-000000000001'");
ok(fila === 'ALCOHOLIMETRO|00287|RAMIRO ESTEBAN VILLARREAL DUARTE|PUNTO DE CONTROL 1|punto1|tarjeta|1', 'guardada en su punto, con foto: ' + fila);
r = await incidencia('tok-punto1', { uid: 'inc-000000000001', incidencia: 'ALCOHOLIMETRO', codigo: '00287', dispositivo: EQ });
ok(r.status === 'success' && r.duplicado && r.id === idInc, 'reenvío (sin señal a medias): no se duplica');
r = await incidencia('tok-punto1', { uid: 'inc-000000000002', incidencia: 'PERSONA', persona: 'Visitante sin credencial', detalle: 'Quiso entrar sin cita', dispositivo: EQ });
ok(r.status === 'success' && sql("SELECT CONCAT(codigo,'|',origen) FROM accesos_incidencias WHERE uid='inc-000000000002'") === '|sin_id', 'sin credencial: con su nombre (origen sin_id)');
r = await incidencia('tok-punto2', { uid: 'inc-000000000003', incidencia: 'OTRO', detalle: 'Traía una herramienta sin vale', codigo: '412', dispositivo: 'eq-prueba-incidencias-2' });
ok(r.status === 'success', 'otro punto, «Otro» con descripción');
ok(sql('SELECT COUNT(*) FROM accesos_registros') === '0', 'las incidencias no crean entradas ni salidas');

// ── La cola del teléfono las confirma igual que los registros ──
r = await post('ACCESOS_EXISTEN', 'tok-punto1', { uids: ['inc-000000000001', 'inc-000000000002', 'no-existe-00001'], dispositivo: EQ });
ok(r.existen['inc-000000000001'] === idInc && r.existen['inc-000000000002'] && !r.existen['no-existe-00001'], 'ACCESOS_EXISTEN reconoce las incidencias');

// ── Listas ──
r = await get('LISTAR_ACCESOS', 'tok-punto1', { dispositivo: EQ });
ok(r.status === 'success' && r.incidencias.length === 2 && r.incidencias.every(x => x.usuario === 'punto1'), 'la caseta ve sólo sus incidencias: ' + r.incidencias.length);
const k = r.turnos.find(t => r.incidencias.some(x => x.turno === t)) || r.turnos[0];
ok(Object.values(r.totales).every(t => t.entrada === 0 && t.salida === 0), 'los contadores de entradas y salidas no cambian');
const alc = r.incidencias.find(x => x.tipo === 'ALCOHOLIMETRO');
ok(alc && alc.tipo_nombre === 'Alcoholímetro positivo' && alc.detalle === 'Prueba positiva 0.4' && alc.con_foto && alc.puede_borrar, 'trae tipo, descripción, foto y se puede borrar');
// Los guardias punto1 y punto2 son de día: lo suyo va al turno de día de hoy, a cualquier hora
const kDia = new Date().toLocaleDateString('sv-SE', { timeZone: 'America/Mexico_City' }) + '-D';
r = await get('LISTAR_ACCESOS', 'tok-admin', { turnos: kDia });
ok(r.incidencias.length === 3, 'Administración ve las de todos los puntos: ' + r.incidencias.length);
r = await get('LISTAR_ACCESOS', 'tok-planta', { lugar: 'PUNTO DE CONTROL 2', turnos: kDia });
ok(r.incidencias.length === 1 && r.incidencias[0].codigo === '412', 'filtradas por punto de control');
r = await get('ACCESOS_EN_PLANTA', 'tok-admin');
ok(r.total === 0, 'una incidencia no pone a nadie «en planta»');

// ── Historial de la persona ──
r = await get('ACCESOS_PERSONA', 'tok-admin', { id: '287' });
ok(r.status === 'success' && r.incidencias.length === 1 && r.incidencias[0].tipo === 'ALCOHOLIMETRO' && r.nombre === 'RAMIRO ESTEBAN VILLARREAL DUARTE',
   'movimientos del ID 287 (sin ceros): trae su incidencia y su nombre');
r = await get('ACCESOS_PERSONA', 'tok-punto1', { id: '287' });
ok(r.http === 403, 'el guardia no consulta el historial');

// ── Fotos ──
let d = await fetch(B + `descargar.php?tipo=incidencia_foto&id=${idInc}&m=1&token=tok-punto1`);
ok(d.status === 200 && /image\//.test(d.headers.get('content-type')), 'la caseta ve la foto de su incidencia');
d = await fetch(B + `descargar.php?tipo=incidencia_foto&id=${idInc}&token=tok-punto2`);
ok(d.status === 404, 'otra caseta no la ve');
d = await fetch(B + `descargar.php?tipo=incidencia_foto&id=${idInc}&token=tok-planta`);
ok(d.status === 200, 'el administrador de la planta sí');
d = await fetch(B + `descargar.php?tipo=incidencia_foto&id=${idInc}`);
ok(d.status === 401, 'sin sesión: 401');

// ── Eliminar ──
r = await post('INCIDENCIA_ELIMINAR', 'tok-punto2', { id: idInc, dispositivo: 'eq-prueba-incidencias-2' });
ok(r.status === 'error', 'otra caseta no la borra: ' + r.message);
r = await post('INCIDENCIA_ELIMINAR', 'tok-planta', { id: idInc });
ok(r.status === 'error', 'el administrador de la planta no borra incidencias (sólo quien la registró o Administración)');
const rutaFoto = sql(`SELECT foto FROM accesos_incidencias WHERE id=${idInc}`);
r = await post('INCIDENCIA_ELIMINAR', 'tok-punto1', { id: idInc, dispositivo: EQ });
ok(r.status === 'success' && sql(`SELECT COUNT(*) FROM accesos_incidencias WHERE id=${idInc}`) === '0', 'quien la registró la borra (primeras 18 h)');
ok(!execSync(`ls uploads/accesos/${rutaFoto} 2>/dev/null || true`).toString().trim(), 'y su foto se borra del servidor');

console.log(fallas ? fallas + ' FALLAS' : 'TODO BIEN');
process.exit(fallas ? 1 : 0);
