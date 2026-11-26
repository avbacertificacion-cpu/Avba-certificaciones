// Turnos por cuenta: guardias de día y de noche en el mismo punto y el mismo teléfono; lo que
// registra cada uno va a SU turno, a cualquier hora. API real local, base de prueba, datos inventados.
// Cuentas sembradas: punto1…4 (día), noche1 y noche2 (noche).
import { execSync } from 'node:child_process';
const A = 'http://localhost:8794/api/index.php';
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
const registrar = async (tok, campos) => {
  const fd = new FormData();
  for (const [k, v] of Object.entries({ action: 'ACCESO_REGISTRAR', lugar: 'X', origen: 'tarjeta', ...campos })) fd.append(k, v);
  return (await fetch(A, { method: 'POST', headers: { Authorization: 'Bearer ' + tok }, body: fd })).json();
};
const TEL = 'eq-telefono-caseta-1', OTRO = 'eq-otro-telefono-99';

// ── Asignar: día y noche en el mismo punto ──
let r = await post('ACCESOS_PUNTO_ASIGNAR', 'tok-admin', { punto: 'PUNTO DE CONTROL 1', usuario: 'punto1' });
ok(r.status === 'success' && /turno de día/.test(r.message), 'punto1 (día) al Punto 1: ' + r.message);
r = await post('ACCESOS_PUNTO_ASIGNAR', 'tok-admin', { punto: 'PUNTO DE CONTROL 1', usuario: 'noche1' });
ok(r.status === 'success' && /turno de noche/.test(r.message), 'noche1 (noche) al mismo Punto 1: ' + r.message);
r = await get('ACCESOS_PUNTOS', 'tok-admin');
let p1 = r.puntos.find(p => p.punto === 'PUNTO DE CONTROL 1');
ok(p1.usuario === 'punto1' && p1.usuario_noche === 'noche1' && r.con_noche === true, 'el Punto 1 tiene su guardia de día y su guardia de noche');

// ── Un solo teléfono para los dos ──
r = await get('ACCESOS_MI_PUNTO', 'tok-punto1', { dispositivo: TEL, dispositivo_desc: 'Android · Caseta 1' });
ok(r.status === 'success' && r.turno_cuenta === 'D', 'el de día entra en el teléfono de la caseta (queda ligado); su turno: día');
r = await get('ACCESOS_MI_PUNTO', 'tok-noche1', { dispositivo: TEL });
ok(r.status === 'success' && r.punto === 'PUNTO DE CONTROL 1' && r.turno_cuenta === 'N' && /-N$/.test(r.turno_actual), 'el de noche entra en el MISMO teléfono; su turno: noche');
r = await get('ACCESOS_MI_PUNTO', 'tok-noche1', { dispositivo: OTRO });
ok(r.status === 'error' && r.codigo === 'OTRO_EQUIPO', 'en otro teléfono, el de noche queda bloqueado');

// ── El turno de cada registro es el del guardia, a cualquier hora ──
const turnoDe = async (tok, uid, tipo, cap, codigo) => {
  const x = await registrar(tok, { uid, tipo, capturado: cap, codigo, persona: 'PERSONA ' + codigo, dispositivo: TEL });
  return x.turno || ('ERROR ' + x.message);
};
ok(await turnoDe('tok-punto1', 'tc-0000000001', 'entrada', '2026-11-26 17:57:26', '00701') === '2026-11-26-D' &&
   await turnoDe('tok-punto1', 'tc-0000000002', 'salida', '2026-11-26 17:58:02', '00701') === '2026-11-26-D',
   'guardia de día: entrada 17:57 y salida 17:58 → turno de día (el reloj ya no decide)');
ok(await turnoDe('tok-punto1', 'tc-0000000003', 'salida', '2026-11-26 20:40:00', '00702') === '2026-11-26-D', 'guardia de día que registra tarde (20:40) → día');
ok(await turnoDe('tok-noche1', 'tc-0000000004', 'entrada', '2026-11-26 17:50:00', '00703') === '2026-11-26-N', 'guardia de noche que llega temprano (17:50) → noche del 26');
ok(await turnoDe('tok-noche1', 'tc-0000000005', 'entrada', '2026-11-26 23:10:00', '00704') === '2026-11-26-N', 'guardia de noche a las 23:10 → noche del 26');
ok(await turnoDe('tok-noche1', 'tc-0000000006', 'salida', '2026-11-27 06:20:00', '00703') === '2026-11-26-N', 'guardia de noche en la madrugada (06:20 del 27) → noche del 26');
ok(await turnoDe('tok-noche1', 'tc-0000000007', 'salida', '2026-11-27 07:40:00', '00704') === '2026-11-26-N', 'guardia de noche que sale tarde (07:40 del 27) → noche del 26');
// Administración (sin turno de guardia) sigue con el reloj y la entrada abierta
r = await post('ACCESOS_DAR_SALIDA', 'tok-admin', { id: '701' });
ok(r.status === 'error', 'dar salida a quien ya salió: «' + r.message + '»');

// ── Incidencias: también al turno del guardia ──
const fd = new FormData();
for (const [k, v] of Object.entries({ action: 'ACCESO_INCIDENCIA', uid: 'tc-inc-0000001', incidencia: 'CONDUCTA', codigo: '00703', capturado: '2026-11-27 05:10:00', dispositivo: TEL })) fd.append(k, v);
r = await (await fetch(A, { method: 'POST', headers: { Authorization: 'Bearer tok-noche1' }, body: fd })).json();
ok(r.status === 'success' && sql("SELECT CONCAT(turno_fecha,'-',turno) FROM accesos_incidencias WHERE uid='tc-inc-0000001'") === '2026-11-26-N', 'incidencia del guardia de noche a las 05:10 → noche del 26');

// ── Cada guardia ve su turno en curso (lo decide el servidor) y sólo lo suyo ──
const hoy = new Date().toLocaleDateString('sv-SE', { timeZone: 'America/Mexico_City' });
const hora = +new Date().toLocaleTimeString('en-GB', { timeZone: 'America/Mexico_City', hour: '2-digit' });
const nocheActual = hora < 12 ? new Date(new Date(hoy + 'T12:00:00Z').getTime() - 86400e3).toISOString().slice(0, 10) + '-N' : hoy + '-N';
r = await get('LISTAR_ACCESOS', 'tok-punto1', { dispositivo: TEL, turnos: '2026-01-01-N' });
ok(r.status === 'success' && r.turnos.length === 1 && r.turnos[0] === hoy + '-D' && r.turno_cuenta === 'D', 'el de día ve sólo su turno de hoy, aunque pida otro: ' + r.turnos.join(','));
r = await get('LISTAR_ACCESOS', 'tok-noche1', { dispositivo: TEL });
ok(r.turnos.length === 1 && r.turnos[0] === nocheActual && r.turno_cuenta === 'N', 'el de noche ve su noche en curso: ' + r.turnos.join(','));
r = await get('LISTAR_ACCESOS', 'tok-admin', { turnos: '2026-11-26-D,2026-11-26-N' });
ok(r.totales['2026-11-26-D'].entrada === 1 && r.totales['2026-11-26-D'].salida === 2 && r.totales['2026-11-26-N'].entrada === 2 && r.totales['2026-11-26-N'].salida === 2,
   'Administración: día 1 entrada / 2 salidas, noche 2 / 2 → ' + JSON.stringify(r.totales));
r = await get('SESION', 'tok-noche1');
ok(r.turno === 'N', 'SESION dice el turno de la cuenta');

// ── Cuentas: turno y punto ──
r = await post('CUENTA_CREAR', 'tok-admin', { usuario: 'noche3', nombre: 'Caseta punto 3 noche', rol: 'ACCESOS', turno: 'N', punto: 'PUNTO DE CONTROL 3', password: 'contrasena-larga' });
ok(r.status === 'success' && /Punto de control 3, turno de noche/.test(r.message), 'crear guardia de noche con su punto de una vez: ' + r.message);
const L = await post('LOGIN', null, { usuario: 'noche3', password: 'contrasena-larga' });
ok(L.turno === 'N', 'al iniciar sesión, la cuenta trae su turno');
r = await get('CUENTAS_LISTAR', 'tok-admin');
const n3 = r.cuentas.find(c => c.usuario === 'noche3'), p1c = r.cuentas.find(c => c.usuario === 'noche1');
ok(n3.turno === 'N' && n3.punto === 'Punto de control 3' && p1c.punto === 'Punto de control 1', 'Cuentas muestra turno y punto (también del de noche)');
// Otro guardia de noche al mismo punto: reemplaza al anterior de ese turno; el de día no se toca
await post('ACCESOS_PUNTO_ASIGNAR', 'tok-admin', { punto: 'PUNTO DE CONTROL 3', usuario: 'punto3' });
r = await post('ACCESOS_PUNTO_ASIGNAR', 'tok-admin', { punto: 'PUNTO DE CONTROL 3', usuario: 'noche2' });
r = await get('ACCESOS_PUNTOS', 'tok-admin');
let p3 = r.puntos.find(p => p.punto === 'PUNTO DE CONTROL 3');
ok(p3.usuario === 'punto3' && p3.usuario_noche === 'noche2' && sql("SELECT COUNT(*) FROM accesos_puntos WHERE usuario_noche='noche3'") === '0', 'noche2 reemplaza a noche3 en la noche del Punto 3; el de día sigue');
// Cambiar de turno a una cuenta: pasa al lugar de su nuevo turno en el mismo punto
const idP3 = Number(sql("SELECT id FROM usuarios WHERE usuario='punto3'"));
r = await post('CUENTA_EDITAR', 'tok-admin', { id: idP3, turno: 'N' });
const r2 = await get('ACCESOS_PUNTOS', 'tok-admin');
p3 = r2.puntos.find(p => p.punto === 'PUNTO DE CONTROL 3');
ok(r.status === 'success' && p3.usuario === '' && p3.usuario_noche === 'punto3', 'punto3 pasa a noche: queda en la noche del Punto 3 (' + r.message + ')');
// Quitar sólo el de noche
r = await post('ACCESOS_PUNTO_ASIGNAR', 'tok-admin', { punto: 'PUNTO DE CONTROL 1', usuario: '', turno: 'N' });
p1 = (await get('ACCESOS_PUNTOS', 'tok-admin')).puntos.find(p => p.punto === 'PUNTO DE CONTROL 1');
ok(p1.usuario === 'punto1' && p1.usuario_noche === '' && p1.dispositivo === true, 'quitar sólo al de noche: el de día y el teléfono siguen');
r = await get('ACCESOS_MI_PUNTO', 'tok-noche1', { dispositivo: TEL });
ok(r.status === 'error' && r.codigo === 'SIN_PUNTO', 'y noche1 ya no tiene punto');
// Una cuenta de guardia sin turno (de antes) no se puede asignar hasta elegirlo
sql("UPDATE usuarios SET turno=NULL WHERE usuario='punto4'");
r = await post('ACCESOS_PUNTO_ASIGNAR', 'tok-admin', { punto: 'PUNTO DE CONTROL 4', usuario: 'punto4' });
ok(r.status === 'error' && /no tiene turno/.test(r.message), 'guardia sin turno: «' + r.message + '»');
r = await post('CUENTA_EDITAR', 'tok-admin', { id: Number(sql("SELECT id FROM usuarios WHERE usuario='punto4'")), punto: 'PUNTO DE CONTROL 4' });
ok(r.status === 'error' && /no tiene turno/.test(r.message), 'tampoco desde Cuentas sin elegir su turno');
// Deja de ser guardia: suelta su lugar
r = await post('CUENTA_EDITAR', 'tok-admin', { id: idP3, rol: 'PLANTA' });
ok(r.status === 'success' && sql("SELECT COUNT(*) FROM accesos_puntos WHERE usuario='punto3' OR usuario_noche='punto3'") === '0', 'pasa a administrador de planta: deja su punto');

console.log(fallas ? fallas + ' FALLAS' : 'TODO BIEN');
process.exit(fallas ? 1 : 0);
