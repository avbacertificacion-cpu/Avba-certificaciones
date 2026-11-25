// API propia de la plataforma (copia local, base de prueba): instalación, inicio de sesión,
// cuentas, los tres perfiles y descargar.php. Datos inventados.
import { execSync } from 'node:child_process';
const B = 'http://localhost:8794/';
const A = B + 'api/index.php';
let fallas = 0;
const ok = (c, m) => { if (!c) fallas++; console.log((c ? 'OK    ' : 'FALLA ') + m); };
const sql = q => execSync(`mariadb --default-character-set=utf8mb4 -N -B -uprueba -pprueba -h127.0.0.1 ca_prueba -e "${q}"`).toString().trim();
const get = async (accion, tok, q = {}) => {
  const r = await fetch(A + '?' + new URLSearchParams({ ...q, action: accion }), { headers: tok ? { Authorization: 'Bearer ' + tok } : {} });
  return Object.assign(await r.json(), { http: r.status });
};
const post = async (accion, tok, payload = {}) => {
  const h = { 'Content-Type': 'application/json' };
  if (tok) h.Authorization = 'Bearer ' + tok;
  const r = await fetch(A, { method: 'POST', headers: h, body: JSON.stringify({ action: accion, payload }) });
  return Object.assign(await r.json(), { http: r.status });
};
const entrar = async (usuario, password) => (await post('LOGIN', null, { usuario, password, dispositivo_desc: 'Prueba' })).token;

// ── 1) Instalación: sólo con la base vacía y con la clave de config.php ──
const cuentasSembradas = sql('SELECT COUNT(*) FROM usuarios');
let r = await get('INSTALAR_ESTADO');
ok(r.status === 'success' && r.instalar === false, 'con cuentas, no ofrece instalar');
r = await post('INSTALAR', null, { clave: 'clave-local-prueba', usuario: 'intruso', nombre: 'X', password: 'contrasena-larga' });
ok(r.status === 'error' && /ya tiene cuentas/.test(r.message), 'con cuentas, instalar se rechaza: ' + r.message);
sql('DELETE FROM sesiones_usuario; DELETE FROM usuarios; DELETE FROM login_intentos');
r = await get('INSTALAR_ESTADO');
ok(r.instalar === true && r.con_clave === true, 'base vacía: ofrece crear la primera cuenta');
r = await post('INSTALAR', null, { clave: 'otra-clave', usuario: 'jefa.avba', nombre: 'Lucia Prueba', password: 'contrasena-larga' });
ok(r.status === 'error' && /no coincide/.test(r.message), 'clave de instalación equivocada: ' + r.message);
r = await post('INSTALAR', null, { clave: 'clave-local-prueba', usuario: 'jefa.avba', nombre: 'Lucía Prueba', password: 'corta' });
ok(r.status === 'error' && /8 caracteres/.test(r.message), 'contraseña corta: ' + r.message);
r = await post('INSTALAR', null, { clave: 'clave-local-prueba', usuario: 'jefa.avba', nombre: 'Lucía Prueba', password: 'contrasena-larga' });
ok(r.status === 'success' && r.rol === 'ADMIN' && r.token && r.nombre === 'LUCIA PRUEBA', 'primera cuenta ADMIN creada y con sesión');
const tJefa = r.token;
r = await post('INSTALAR', null, { clave: 'clave-local-prueba', usuario: 'segunda', nombre: 'X', password: 'contrasena-larga' });
ok(r.status === 'error', 'la instalación se apaga sola después de la primera cuenta');
// Se vuelven a sembrar las cuentas de prueba para lo demás
execSync('mariadb -uprueba -pprueba -h127.0.0.1 ca_prueba < tests/referencia/sembrar-cuentas.sql');
ok(Number(sql('SELECT COUNT(*) FROM usuarios')) === Number(cuentasSembradas) + 1, 'cuentas de prueba sembradas de nuevo');

// ── 2) Iniciar sesión ──
r = await post('LOGIN', null, { usuario: 'admin', password: 'mala' });
ok(r.status === 'error' && r.message === 'Usuario o contraseña incorrectos.', 'contraseña equivocada: ' + r.message);
r = await post('LOGIN', null, { usuario: 'nadie', password: 'mala' });
ok(r.status === 'error' && r.message === 'Usuario o contraseña incorrectos.', 'cuenta que no existe: el mismo mensaje (no delata cuáles existen)');
r = await get('LOGIN', null, { usuario: 'admin', password: 'prueba-local' });
ok(r.http === 405, 'iniciar sesión por GET (en la URL) no se permite');
const tAdmin = await entrar('ADMIN', 'prueba-local');
ok(!!tAdmin, 'entra con el usuario en mayúsculas (no distingue)');
r = await get('SESION', tAdmin);
ok(r.status === 'success' && r.rol === 'ADMIN' && r.nombre_rol === 'Administración AVBA', 'SESION: ' + r.nombre_rol);
r = await get('SESION', 'token-inventado');
ok(r.http === 401 && r.codigo === 'SIN_SESION', 'token inventado: 401');
// Varios fallos seguidos: espera que crece
for (let i = 0; i < 4; i++) await post('LOGIN', null, { usuario: 'punto3', password: 'mala' + i });
r = await post('LOGIN', null, { usuario: 'punto3', password: 'mala' });
ok(r.status === 'error' && r.espera === 10, '5.º fallo: espera de 10 s («' + r.message + '»)');
r = await post('LOGIN', null, { usuario: 'punto3', password: 'prueba-local' });
ok(r.status === 'error' && r.espera > 0, 'durante la espera ni la contraseña buena entra');
// Cerrar sesión
const tTemp = await entrar('planta', 'prueba-local');
r = await post('LOGOUT', tTemp);
ok(r.status === 'success' && (await get('SESION', tTemp)).http === 401, 'cerrar sesión invalida el token');

// ── 3) Cuentas (sólo ADMIN) ──
r = await get('CUENTAS_LISTAR', 'tok-planta');
ok(r.http === 403, 'el administrador de la planta no ve las cuentas');
r = await get('CUENTAS_LISTAR', 'tok-punto1');
ok(r.http === 403, 'el guardia no ve las cuentas');
r = await get('CUENTA_CREAR', tAdmin, { usuario: 'x', nombre: 'x', rol: 'ACCESOS', password: 'contrasena-larga' });
ok(r.http === 405, 'crear cuenta por GET no se permite');
r = await post('CUENTA_CREAR', tAdmin, { usuario: 'Con Espacio', nombre: 'X', rol: 'ACCESOS', password: 'contrasena-larga' });
ok(r.status === 'error' && /minúsculas/.test(r.message), 'usuario con espacios: ' + r.message);
r = await post('CUENTA_CREAR', tAdmin, { usuario: 'punto1', nombre: 'X', rol: 'ACCESOS', password: 'contrasena-larga' });
ok(r.status === 'error' && /Ya existe/.test(r.message), 'usuario repetido: ' + r.message);
r = await post('CUENTA_CREAR', tAdmin, { usuario: 'otro', nombre: 'X', rol: 'INSPECTOR', password: 'contrasena-larga' });
ok(r.status === 'error', 'perfil que no existe en esta plataforma: ' + r.message);
r = await post('CUENTA_CREAR', tAdmin, { usuario: 'caseta.norte', nombre: 'Caseta Norte', rol: 'ACCESOS', password: 'contrasena-larga' });
ok(r.status === 'success', 'crear guardia de caseta: ' + r.message);
const idNorte = r.id;
const tNorte = await entrar('caseta.norte', 'contrasena-larga');
ok(!!tNorte, 'la cuenta nueva entra con su contraseña');
r = await get('CUENTAS_LISTAR', tAdmin);
const norte = (r.cuentas || []).find(c => c.usuario === 'caseta.norte');
ok(r.status === 'success' && norte && norte.nombre === 'CASETA NORTE' && norte.rol === 'ACCESOS' && norte.sesiones === 1, 'la lista trae la cuenta nueva con 1 sesión abierta');
// Asignarle un punto y cambiarle el perfil: deja el punto libre
await post('ACCESOS_PUNTO_ASIGNAR', tAdmin, { punto: 'PUNTO DE CONTROL 3', usuario: 'caseta.norte' });
r = await get('CUENTAS_LISTAR', tAdmin);
ok((r.cuentas.find(c => c.usuario === 'caseta.norte') || {}).punto === 'Punto de control 3', 'la lista muestra su punto de control');
r = await post('CUENTA_EDITAR', tAdmin, { id: idNorte, rol: 'PLANTA' });
ok(r.status === 'success' && sql("SELECT COUNT(*) FROM accesos_puntos WHERE usuario='caseta.norte'") === '0', 'deja de ser guardia: su punto queda libre');
ok((await get('SESION', tNorte)).http === 401, 'con perfil nuevo, sus sesiones abiertas se cierran');
// Contraseña nueva
r = await post('CUENTA_EDITAR', tAdmin, { id: idNorte, password: 'nueva-contrasena' });
ok(r.status === 'success' && !(await entrar('caseta.norte', 'contrasena-larga')) && !!(await entrar('caseta.norte', 'nueva-contrasena')), 'contraseña cambiada por Administración');
// Desactivar
const tN2 = await entrar('caseta.norte', 'nueva-contrasena');
r = await post('CUENTA_EDITAR', tAdmin, { id: idNorte, activo: false });
ok(r.status === 'success' && (await get('SESION', tN2)).http === 401 && !(await entrar('caseta.norte', 'nueva-contrasena')), 'desactivada: no entra y su sesión se cierra');
r = await post('CUENTA_EDITAR', tAdmin, { id: idNorte, activo: true });
ok(r.status === 'success' && !!(await entrar('caseta.norte', 'nueva-contrasena')), 'activada de nuevo');
// No dejar la plataforma sin Administración
const idAdmin = Number(sql("SELECT id FROM usuarios WHERE usuario='admin'"));
r = await post('CUENTA_EDITAR', tAdmin, { id: idAdmin, activo: false });
ok(r.status === 'error' && /propia cuenta/.test(r.message), 'no puede desactivarse a sí mismo: ' + r.message);
const idJefa = Number(sql("SELECT id FROM usuarios WHERE usuario='jefa.avba'"));
r = await post('CUENTA_EDITAR', tAdmin, { id: idJefa, rol: 'PLANTA' });
ok(r.status === 'success' && (await get('SESION', tJefa)).http === 401, 'otro ADMIN pasa a planta: su sesión se cierra');
r = await post('CUENTA_EDITAR', tAdmin, { id: idJefa, rol: 'ADMIN' });
ok(r.status === 'success', 'y vuelve a ser ADMIN');
// Mi contraseña
const tOtroEquipo = await entrar('planta', 'prueba-local');
const tEste = await entrar('planta', 'prueba-local');
r = await post('CAMBIAR_MI_CONTRASENA', tEste, { actual: 'mala', nueva: 'otra-contrasena' });
ok(r.status === 'error', 'cambiar mi contraseña con la actual equivocada: ' + r.message);
r = await post('CAMBIAR_MI_CONTRASENA', tEste, { actual: 'prueba-local', nueva: 'otra-contrasena' });
ok(r.status === 'success' && (await get('SESION', tEste)).status === 'success' && (await get('SESION', tOtroEquipo)).http === 401,
   'mi contraseña: sigo dentro en este equipo y se cierra la sesión del otro');
await post('CAMBIAR_MI_CONTRASENA', tEste, { actual: 'otra-contrasena', nueva: 'prueba-local' });
// (cambiar la contraseña cerró las demás sesiones de «planta», también tok-planta)
const tPlanta = await entrar('planta', 'prueba-local');

// ── 4) Perfiles en el control de accesos ──
r = await get('ACCESOS_EN_PLANTA', 'tok-punto1');
ok(r.http === 403, 'el guardia no ve «En planta»');
r = await post('ACCESOS_PDF_DATOS', 'tok-punto1', { turno: '2026-11-26-D' });
ok(r.http === 403, 'el guardia no genera PDF');
r = await get('ACCESOS_TARJETA_REGLAS', 'tok-punto1');
ok(r.status === 'success' && r.reglas && r.reglas.orientacion, 'el guardia recibe las reglas del lector');
r = await get('ACCESOS_EN_PLANTA', tPlanta);
ok(r.status === 'success', 'el administrador de la planta ve «En planta»');
r = await get('ACCESOS_PUNTOS', tPlanta);
ok(r.status === 'success' && r.puede_editar === false && r.puede_liberar === true, 'planta ve los puntos: no asigna, sí libera');
r = await post('ACCESOS_PUNTO_ASIGNAR', tPlanta, { punto: 'PUNTO DE CONTROL 1', usuario: 'punto1' });
ok(r.status === 'error' && /Administración/.test(r.message), 'planta no asigna puntos: ' + r.message);
await post('ACCESOS_PUNTO_ASIGNAR', 'tok-admin', { punto: 'PUNTO DE CONTROL 1', usuario: 'punto1' });
r = await get('ACCESOS_MI_PUNTO', 'tok-punto1', { dispositivo: 'eq-prueba-plataforma-1' });
ok(r.status === 'success' && r.punto === 'PUNTO DE CONTROL 1', 'el guardia entra a su punto y liga su equipo');
r = await post('ACCESOS_EQUIPO_LIBERAR', tPlanta, { punto: 'PUNTO DE CONTROL 1' });
ok(r.status === 'success', 'planta libera el equipo: ' + r.message);
r = await post('ACCESOS_EQUIPO_LIBERAR', 'tok-punto1', { punto: 'PUNTO DE CONTROL 1', dispositivo: 'eq-prueba-plataforma-1' });
ok(r.http === 403, 'el guardia no libera equipos');
// Registrar con tarjeta y dar salida como planta
const fd = new FormData();
for (const [k, v] of Object.entries({ action: 'ACCESO_REGISTRAR', uid: 'plat-000000000001', tipo: 'entrada', capturado: new Date(Date.now() - 3600e3).toLocaleString('sv-SE', { timeZone: 'America/Mexico_City' }),
  lugar: 'X', codigo: '00287', persona: 'Ramiro Esteban Villarreal Duarte', origen: 'tarjeta', dispositivo: 'eq-prueba-plataforma-1' })) fd.append(k, v);
r = await (await fetch(A, { method: 'POST', headers: { Authorization: 'Bearer tok-punto1' }, body: fd })).json();
ok(r.status === 'success', 'el guardia registra una entrada: ' + r.message);
r = await post('ACCESOS_DAR_SALIDA', 'tok-punto1', { id: '287' });
ok(r.http === 403, 'el guardia no da salidas desde la administración');
r = await post('ACCESOS_DAR_SALIDA', tPlanta, { id: '287' });
ok(r.status === 'success' && /ya no aparece en planta/.test(r.message), 'planta da salida: ' + r.message);
ok(sql("SELECT CONCAT(origen,'|',nota) FROM accesos_registros WHERE tipo='salida' AND codigo='00287'") === 'admin|SALIDA REGISTRADA POR EL ADMINISTRADOR DE ACCESOS', 'la salida queda marcada (origen admin, por el administrador de accesos)');
r = await get('NO_EXISTE', 'tok-admin');
ok(r.http === 400 && r.message === 'Acción no reconocida.', 'acción desconocida: 400');

// ── 5) descargar.php: sólo con sesión ──
let d = await fetch(B + 'descargar.php?tipo=acceso_foto&id=1');
ok(d.status === 401, 'foto sin sesión: 401');
d = await fetch(B + 'descargar.php?tipo=accesos_pdf&f=ACCESOS_2026-11-26-D_TODOS_datos_1.pdf&token=tok-punto1');
ok(d.status === 403, 'el guardia no baja PDF: 403');
d = await fetch(B + 'descargar.php?tipo=accesos_pdf&f=../../config/config.php&token=tok-admin');
ok(d.status === 404, 'nombre de archivo raro: no sale nada (404)');
d = await fetch(B + 'descargar.php?tipo=otra&token=tok-admin');
ok(d.status === 400, 'tipo desconocido: 400');

console.log(fallas ? fallas + ' FALLAS' : 'TODO BIEN');
process.exit(fallas ? 1 : 0);
