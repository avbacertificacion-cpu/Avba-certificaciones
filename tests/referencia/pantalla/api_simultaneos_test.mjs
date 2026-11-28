// Un solo acceso, varios guardias a la vez: cada cuenta registra desde su teléfono (o desde varios),
// al mismo tiempo, sin bloqueos; lo de cada guardia va a SU turno (día o noche) a cualquier hora.
// API real local, base de prueba, datos inventados. Cuentas sembradas: punto1…4 (día), noche1 y noche2 (noche).
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
const dos = n => String(n).padStart(2, '0');

// ── Al entrar: dónde registra y su turno, sin asignar nada ──
let r = await get('ACCESOS_MI_PUNTO', 'tok-punto1', { dispositivo: 'eq-telefono-a-0001' });
ok(r.status === 'success' && r.punto === 'ACCESO' && r.nombre_punto === 'Acceso' && r.turno_cuenta === 'D', 'el guardia de día entra sin que le asignen nada: Acceso, turno de día');
r = await get('ACCESOS_MI_PUNTO', 'tok-noche1', { dispositivo: 'eq-telefono-a-0001' });
ok(r.status === 'success' && r.turno_cuenta === 'N', 'otro guardia (de noche) en el MISMO teléfono: sin bloqueo');
r = await get('ACCESOS_MI_PUNTO', 'tok-punto1', { dispositivo: 'eq-telefono-b-0002' });
ok(r.status === 'success', 'la misma cuenta en otro teléfono: sin bloqueo');
r = await get('ACCESOS_PUNTOS', 'tok-admin');
ok(r.http === 400, 'ya no existen los puntos de control que asignar');

// ── Varios teléfonos, varias cuentas, al mismo tiempo ──
const quienes = [['tok-punto1', 'eq-tel-01'], ['tok-punto2', 'eq-tel-02'], ['tok-punto3', 'eq-tel-03'], ['tok-punto1', 'eq-tel-04'], ['tok-noche1', 'eq-tel-05']];
const envios = [];
for (let i = 0; i < 40; i++) {
  const [tok, eq] = quienes[i % quienes.length];
  envios.push(registrar(tok, { uid: 'sim-' + String(i).padStart(10, '0'), tipo: i % 2 ? 'salida' : 'entrada', capturado: `2026-11-26 10:${dos(i)}:00`,
                               codigo: String(1000 + (i >> 1)), persona: 'PERSONA ' + i, dispositivo: eq }));
}
// Y los mismos envíos repetidos a la vez (se cortó la señal a medias): no se duplican
for (let i = 0; i < 10; i++) {
  const [tok, eq] = quienes[i % quienes.length];
  envios.push(registrar(tok, { uid: 'sim-' + String(i).padStart(10, '0'), tipo: i % 2 ? 'salida' : 'entrada', capturado: `2026-11-26 10:${dos(i)}:00`,
                               codigo: String(1000 + (i >> 1)), persona: 'PERSONA ' + i, dispositivo: eq }));
}
const res = await Promise.all(envios);
ok(res.every(x => x.status === 'success'), '50 envíos al mismo tiempo desde 5 teléfonos y 4 cuentas: todos aceptados');
ok(sql("SELECT COUNT(*) FROM accesos_registros WHERE uid LIKE 'sim-%'") === '40', 'quedaron 40 registros (los repetidos no se duplican)');
ok(sql("SELECT GROUP_CONCAT(DISTINCT lugar) FROM accesos_registros WHERE uid LIKE 'sim-%'") === 'ACCESO', 'todos en el acceso');
const porCuenta = sql("SELECT GROUP_CONCAT(CONCAT(usuario,':',n) ORDER BY usuario) FROM (SELECT usuario, COUNT(*) n FROM accesos_registros WHERE uid LIKE 'sim-%' GROUP BY usuario) t");
ok(porCuenta === 'noche1:8,punto1:16,punto2:8,punto3:8', 'cada registro guarda qué cuenta lo capturó: ' + porCuenta);

// ── El turno es el del guardia, a cualquier hora ──
const turnoDe = async (tok, uid, tipo, cap, codigo) => {
  const x = await registrar(tok, { uid, tipo, capturado: cap, codigo, persona: 'PERSONA ' + codigo, dispositivo: 'eq-tel-01' });
  return x.turno || ('ERROR ' + x.message);
};
ok(await turnoDe('tok-punto1', 'tc-0000000001', 'entrada', '2026-11-26 17:57:26', '00701') === '2026-11-26-D' &&
   await turnoDe('tok-punto2', 'tc-0000000002', 'salida', '2026-11-26 17:58:02', '00701') === '2026-11-26-D',
   'entrada 17:57 con un guardia de día y salida 17:58 con otro de día → turno de día');
ok(await turnoDe('tok-punto1', 'tc-0000000003', 'salida', '2026-11-26 20:40:00', '00702') === '2026-11-26-D', 'guardia de día que registra tarde (20:40) → día');
ok(await turnoDe('tok-noche1', 'tc-0000000004', 'entrada', '2026-11-26 17:50:00', '00703') === '2026-11-26-N', 'guardia de noche que llega temprano (17:50) → noche del 26');
ok(await turnoDe('tok-noche2', 'tc-0000000005', 'entrada', '2026-11-26 23:10:00', '00704') === '2026-11-26-N', 'otro guardia de noche a las 23:10 → noche del 26');
ok(await turnoDe('tok-noche1', 'tc-0000000006', 'salida', '2026-11-27 06:20:00', '00703') === '2026-11-26-N', 'guardia de noche en la madrugada (06:20 del 27) → noche del 26');
ok(await turnoDe('tok-noche2', 'tc-0000000007', 'salida', '2026-11-27 07:40:00', '00704') === '2026-11-26-N', 'guardia de noche que sale tarde (07:40 del 27) → noche del 26');
const fd = new FormData();
for (const [k, v] of Object.entries({ action: 'ACCESO_INCIDENCIA', uid: 'tc-inc-0000001', incidencia: 'CONDUCTA', codigo: '00703', capturado: '2026-11-27 05:10:00', dispositivo: 'eq-tel-05' })) fd.append(k, v);
r = await (await fetch(A, { method: 'POST', headers: { Authorization: 'Bearer tok-noche1' }, body: fd })).json();
ok(r.status === 'success' && sql("SELECT CONCAT(lugar,' ',turno_fecha,'-',turno) FROM accesos_incidencias WHERE uid='tc-inc-0000001'") === 'ACCESO 2026-11-26-N', 'incidencia del guardia de noche a las 05:10 → noche del 26, en el acceso');

// ── Cada guardia ve su turno en curso y sólo lo suyo; Administración ve todo ──
const hoy = new Date().toLocaleDateString('sv-SE', { timeZone: 'America/Mexico_City' });
const hora = +new Date().toLocaleTimeString('en-GB', { timeZone: 'America/Mexico_City', hour: '2-digit' });
const nocheActual = hora < 12 ? new Date(new Date(hoy + 'T12:00:00Z').getTime() - 86400e3).toISOString().slice(0, 10) + '-N' : hoy + '-N';
r = await get('LISTAR_ACCESOS', 'tok-punto1', { dispositivo: 'eq-tel-01', turnos: '2026-01-01-N' });
ok(r.status === 'success' && r.turnos.length === 1 && r.turnos[0] === hoy + '-D' && r.turno_cuenta === 'D', 'el de día ve sólo su turno de hoy, aunque pida otro: ' + r.turnos.join(','));
r = await get('LISTAR_ACCESOS', 'tok-noche1', { dispositivo: 'eq-tel-05' });
ok(r.turnos.length === 1 && r.turnos[0] === nocheActual && r.turno_cuenta === 'N', 'el de noche ve su noche en curso: ' + r.turnos.join(','));
r = await get('LISTAR_ACCESOS', 'tok-punto2', { turnos: '2026-11-26-D', dispositivo: 'eq-tel-02' });
r = await get('LISTAR_ACCESOS', 'tok-admin', { turnos: '2026-11-26-D,2026-11-26-N', limite: 200 });
const usuarios = [...new Set(r.registros.map(x => x.usuario))].sort().join(',');
ok(usuarios === 'noche1,noche2,punto1,punto2,punto3', 'Administración ve lo de todos los guardias: ' + usuarios);
ok(r.totales['2026-11-26-D'].entrada === 17 && r.totales['2026-11-26-D'].salida === 17 && r.totales['2026-11-26-D'].sin_entrada === 1, 'turno de día del 26 (la salida de 00702 a las 20:40 no tiene entrada: va aparte): ' + JSON.stringify(r.totales['2026-11-26-D']));
r = await get('SESION', 'tok-noche1');
ok(r.turno === 'N', 'SESION dice el turno de la cuenta');

// ── Cuentas: sólo el turno ──
r = await post('CUENTA_CREAR', 'tok-admin', { usuario: 'noche3', nombre: 'Guardia noche tres', rol: 'ACCESOS', turno: 'N', password: 'contrasena-larga' });
ok(r.status === 'success' && /Turno de noche/.test(r.message), 'crear guardia de noche: ' + r.message);
const L = await post('LOGIN', null, { usuario: 'noche3', password: 'contrasena-larga' });
ok(L.turno === 'N', 'al iniciar sesión, la cuenta trae su turno');
const r1 = await registrar(L.token, { uid: 'tc-n3-00000001', tipo: 'entrada', capturado: '2026-11-26 19:00:00', codigo: '00801', dispositivo: 'eq-nuevo-telefono' });
ok(r1.status === 'success' && r1.turno === '2026-11-26-N', 'registra de inmediato, desde un teléfono nuevo, sin que nadie le asigne nada');
r = await get('CUENTAS_LISTAR', 'tok-admin');
ok(r.cuentas.find(c => c.usuario === 'noche3').turno === 'N' && r.cuentas.every(c => c.punto === undefined), 'Cuentas muestra el turno (ya no hay punto)');
const idN3 = Number(sql("SELECT id FROM usuarios WHERE usuario='noche3'"));
r = await post('CUENTA_EDITAR', 'tok-admin', { id: idN3, turno: 'D' });
ok(r.status === 'success' && /turno de día/.test(r.message) && sql(`SELECT turno FROM usuarios WHERE id=${idN3}`) === 'D', 'cambiar de turno: ' + r.message);
const r2 = await registrar(L.token, { uid: 'tc-n3-00000002', tipo: 'salida', capturado: '2026-11-26 19:30:00', codigo: '00801', dispositivo: 'eq-nuevo-telefono' });
ok(r2.turno === '2026-11-26-D', 'y lo que registra desde ahora va a su turno nuevo (sin volver a entrar)');
r = await post('CUENTA_EDITAR', 'tok-admin', { id: idN3, rol: 'PLANTA' });
ok(r.status === 'success' && sql(`SELECT IFNULL(turno,'-') FROM usuarios WHERE id=${idN3}`) === '-', 'si deja de ser guardia, ya no tiene turno');

console.log(fallas ? fallas + ' FALLAS' : 'TODO BIEN');
process.exit(fallas ? 1 : 0);
