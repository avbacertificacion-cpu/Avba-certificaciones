// Equipos fijos por la API (base local de prueba, datos inventados): cuentas de sólo entradas o
// sólo salidas (sin turno: van por el reloj), lo que devuelven al entrar, que no registran el
// otro tipo, el turno de sus registros por la hora y cambiar el modo en Cuentas.
import { execSync } from 'node:child_process';
const A = 'http://localhost:8794/api/index.php';
let fallas = 0;
const ok = (c, m) => { if (!c) fallas++; console.log((c ? 'OK    ' : 'FALLA ') + m); };
const sql = q => execSync(`mariadb --default-character-set=utf8mb4 -N -B -uprueba -pprueba -h127.0.0.1 ca_prueba -e "${q}"`).toString().trim();
const get = async (accion, tok) => (await fetch(A + '?action=' + accion, { headers: { Authorization: 'Bearer ' + tok } })).json();
const post = async (accion, tok, payload = {}) => {
  const h = { 'Content-Type': 'application/json' };
  if (tok) h.Authorization = 'Bearer ' + tok;
  return (await fetch(A, { method: 'POST', headers: h, body: JSON.stringify({ action: accion, payload }) })).json();
};
const registrar = async (tok, campos) => {
  const fd = new FormData(); fd.append('action', 'ACCESO_REGISTRAR');
  for (const [k, v] of Object.entries(campos)) fd.append(k, v);
  return (await fetch(A, { method: 'POST', headers: { Authorization: 'Bearer ' + tok }, body: fd })).json();
};
const hoy = new Date().toLocaleDateString('sv-SE', { timeZone: 'America/Mexico_City' });

// ── Crear cuentas de equipo fijo ──
let r = await post('CUENTA_CREAR', 'tok-admin', { usuario: 'carril.entrada', nombre: 'Carril de entrada', rol: 'ACCESOS', modo: 'E', turno: 'D', password: 'contrasena-larga' });
ok(r.status === 'success' && /Equipo fijo de entradas/.test(r.message), 'cuenta de equipo fijo de entradas: ' + r.message);
ok(sql("SELECT CONCAT(modo,'|',IFNULL(turno,'-')) FROM usuarios WHERE usuario='carril.entrada'") === 'E|-', 'el equipo fijo no tiene turno (va por el reloj), aunque se mande uno');
r = await post('CUENTA_CREAR', 'tok-admin', { usuario: 'carril.salida', nombre: 'Carril de salida', rol: 'ACCESOS', modo: 'S', password: 'contrasena-larga' });
ok(r.status === 'success', 'cuenta de equipo fijo de salidas (sin turno)');
r = await post('CUENTA_CREAR', 'tok-admin', { usuario: 'carril.raro', nombre: 'X', rol: 'ACCESOS', modo: 'Z', password: 'contrasena-larga' });
ok(r.status === 'error', 'modo desconocido: rechazado');
r = await post('CUENTA_CREAR', 'tok-admin', { usuario: 'guardia.sin', nombre: 'X', rol: 'ACCESOS', password: 'contrasena-larga' });
ok(r.status === 'error' && /turno/.test(r.message), 'el guardia con botones sigue necesitando su turno');
r = await post('CUENTA_CREAR', 'tok-admin', { usuario: 'planta.modo', nombre: 'X', rol: 'PLANTA', modo: 'E', password: 'contrasena-larga' });
ok(r.status === 'success' && sql("SELECT IFNULL(modo,'-') FROM usuarios WHERE usuario='planta.modo'") === '-', 'el modo sólo aplica a cuentas de guardia');
r = await post('CUENTA_CREAR', 'tok-planta', { usuario: 'carril.otro', nombre: 'X', rol: 'ACCESOS', modo: 'E', password: 'contrasena-larga' });
ok(r.status === 'error', 'sólo Administración crea cuentas');

// ── Al entrar ──
r = await post('LOGIN', null, { usuario: 'carril.entrada', password: 'contrasena-larga', dispositivo_desc: 'Tableta del carril' });
ok(r.status === 'success' && r.modo === 'E' && r.turno === '', 'al entrar devuelve modo E y sin turno');
const tE = r.token;
r = await get('SESION', tE);
ok(r.modo === 'E' && r.turno === '', 'SESION: modo E');
r = await get('ACCESOS_MI_PUNTO', tE);
ok(r.modo === 'E' && r.punto === 'ACCESO' && r.turno_cuenta === '', 'ACCESOS_MI_PUNTO: modo E, el acceso, turno del reloj');
r = await get('SESION', 'tok-punto1');
ok(r.modo === '' && r.turno === 'D', 'el guardia con botones: sin modo, con su turno');

// ── Sólo registra su tipo; el turno lo decide la hora ──
r = await registrar(tE, { uid: 'fijo-e-000001', tipo: 'salida', codigo: '00287', persona: 'RAMIRO ESTEBAN VILLARREAL DUARTE', capturado: `${hoy} 07:00:00` });
ok(r.status === 'error' && r.codigo === 'MODO_FIJO', 'el equipo de entrada no registra salidas: ' + r.message);
r = await registrar(tE, { uid: 'fijo-e-000002', tipo: 'entrada', codigo: '00287', persona: 'RAMIRO ESTEBAN VILLARREAL DUARTE', capturado: `${hoy} 05:20:00` });
ok(r.status === 'success' && r.turno === `${hoy}-D`, 'entrada a las 05:20: turno de día (entra para el turno que empieza): ' + r.turno);
r = await registrar(tE, { uid: 'fijo-e-000003', tipo: 'entrada', codigo: '00412', persona: 'ANA SOFIA RUIZ PAEZ', capturado: `${hoy} 17:10:00` });
ok(r.status === 'success' && r.turno === `${hoy}-N`, 'entrada a las 17:10: turno de noche: ' + r.turno);
const tS = (await post('LOGIN', null, { usuario: 'carril.salida', password: 'contrasena-larga' })).token;
r = await registrar(tS, { uid: 'fijo-s-000001', tipo: 'entrada', codigo: '00287' });
ok(r.status === 'error' && r.codigo === 'MODO_FIJO', 'el equipo de salida no registra entradas');
r = await registrar(tS, { uid: 'fijo-s-000002', tipo: 'salida', codigo: '287', capturado: `${hoy} 18:40:00` });
ok(r.status === 'success' && r.turno === `${hoy}-D`, 'salida a las 18:40 de quien entró en el día: va a su turno de día (por su entrada abierta): ' + r.turno);
ok(sql("SELECT usuario FROM accesos_registros WHERE uid='fijo-s-000002'") === 'carril.salida', 'cada registro guarda qué equipo lo capturó');

// ── Cambiar el modo en Cuentas ──
const idS = sql("SELECT id FROM usuarios WHERE usuario='carril.salida'");
r = await post('CUENTA_EDITAR', 'tok-admin', { id: +idS, modo: '', turno: 'N' });
ok(r.status === 'success' && /registra con botones/.test(r.message) && sql(`SELECT CONCAT(IFNULL(modo,'-'),'|',turno) FROM usuarios WHERE id=${idS}`) === '-|N', 'pasar a guardia con botones (con su turno): ' + r.message);
r = await post('CUENTA_EDITAR', 'tok-admin', { id: +idS, modo: 'E' });
ok(r.status === 'success' && sql(`SELECT CONCAT(modo,'|',IFNULL(turno,'-')) FROM usuarios WHERE id=${idS}`) === 'E|-', 'volver a equipo fijo (de entradas): pierde el turno');
r = await post('CUENTA_EDITAR', 'tok-admin', { id: +idS, nombre: 'Carril 2' });
ok(r.status === 'success' && sql(`SELECT modo FROM usuarios WHERE id=${idS}`) === 'E', 'cambiar el nombre no le quita el modo');
r = await get('CUENTAS_LISTAR', 'tok-admin');
const c = r.cuentas.find(x => x.usuario === 'carril.entrada');
ok(c && c.modo === 'E' && c.turno === '' && r.cuentas.find(x => x.usuario === 'punto1').modo === '', 'Cuentas lista el modo de cada cuenta');

// ── Base vieja sin la columna modo: todo sigue funcionando ──
sql('ALTER TABLE usuarios DROP COLUMN modo');
r = await get('SESION', 'tok-punto1');
ok(r.status === 'success' && r.modo === '', 'sin la columna modo, las sesiones siguen funcionando');
r = await get('CUENTAS_LISTAR', 'tok-admin');
ok(r.status === 'success' && sql("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='ca_prueba' AND TABLE_NAME='usuarios' AND COLUMN_NAME='modo'") === '1', 'y la columna se vuelve a agregar sola');

console.log(fallas ? fallas + ' FALLAS' : 'TODO BIEN');
process.exit(fallas ? 1 : 0);
