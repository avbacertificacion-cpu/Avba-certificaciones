// Llave de subida: lo que un teléfono guardó sin señal se sube a nombre de la cuenta que lo capturó, aunque su
// sesión ya se haya cerrado o vencido, o aunque ahora esté otra cuenta. La llave sólo sirve para subir registros.
// Base local de prueba; cuentas y personas inventadas.
import { execSync } from 'node:child_process';
const A = 'http://localhost:8794/api/index.php';
let fallas = 0;
const ok = (c, m) => { if (!c) fallas++; console.log((c ? 'OK    ' : 'FALLA ') + m); };
const sql = q => execSync(`mariadb --default-character-set=utf8mb4 -N -B -uprueba -pprueba -h127.0.0.1 ca_prueba -e "${q}"`).toString().trim();
const post = async (accion, tok, payload = {}) => {
  const r = await fetch(A, { method: 'POST', headers: { 'Content-Type': 'application/json', ...(tok ? { Authorization: 'Bearer ' + tok } : {}) }, body: JSON.stringify({ action: accion, payload }) });
  return Object.assign(await r.json(), { http: r.status });
};
const subir = (tok, campos) => {
  const fd = new FormData();
  Object.entries({ action: 'ACCESO_REGISTRAR', tipo: 'entrada', lugar: 'ACCESO', origen: 'tarjeta', ...campos }).forEach(([k, v]) => fd.append(k, v));
  return fetch(A, { method: 'POST', headers: tok ? { Authorization: 'Bearer ' + tok } : {}, body: fd }).then(async r => Object.assign(await r.json(), { http: r.status }));
};
const ahora = (m = 0) => new Date(Date.now() - m * 60e3).toLocaleString('sv-SE', { timeZone: 'America/Mexico_City' });
const quien = uid => sql(`SELECT CONCAT(usuario, '|', turno) FROM accesos_registros WHERE uid='${uid}'`);

(async () => {
  sql('DELETE FROM accesos_registros');
  let r = await post('LOGIN', null, { usuario: 'punto1', password: 'prueba-local' });
  const L1 = r.llave_envio, T1 = r.token;
  ok(r.status === 'success' && /^L[0-9a-f]{48}$/.test(L1 || ''), 'al iniciar sesión el teléfono recibe su llave de subida');
  ok(sql(`SELECT COUNT(*) FROM ca_llaves_envio WHERE llave='${L1}'`) === '0', 'el servidor sólo guarda su huella, no la llave');
  r = await post('LOGIN', null, { usuario: 'cliente', password: 'prueba-local' });
  ok(r.status === 'success' && r.llave_envio === '', 'el cliente sólo consulta: no recibe llave');

  // Cierra sesión con registros pendientes
  await post('LOGOUT', T1);
  ok((await post('SESION', T1)).http === 401, 'la sesión ya se cerró');
  r = await subir(null, { uid: 'llave-000000000001', capturado: ahora(30), codigo: '00287', persona: 'RAMIRO ESTEBAN VILLARREAL DUARTE', llave_envio: L1 });
  ok(r.status === 'success' && quien('llave-000000000001') === 'punto1|D', 'con la llave se sube sin sesión, a nombre de punto1 y con su turno: ' + (r.message || quien('llave-000000000001')));
  r = await post('ACCESOS_EXISTEN', null, { uids: ['llave-000000000001'], llave_envio: L1 });
  ok(r.status === 'success' && r.existen['llave-000000000001'], 'y con la llave confirma que el servidor ya lo tiene');
  // Otra cuenta en el teléfono: el registro sigue a nombre de quien lo capturó
  r = await subir('tok-punto2', { uid: 'llave-000000000002', capturado: ahora(20), codigo: '00412', persona: 'ANA SOFIA RUIZ PAEZ', llave_envio: L1 });
  ok(r.status === 'success' && quien('llave-000000000002') === 'punto1|D', 'subido con la sesión de punto2: queda a nombre de punto1');
  r = await subir(null, { uid: 'llave-000000000001', capturado: ahora(30), codigo: '00287', persona: 'RAMIRO ESTEBAN VILLARREAL DUARTE', llave_envio: L1 });
  ok(r.status === 'success' && sql("SELECT COUNT(*) FROM accesos_registros WHERE uid='llave-000000000001'") === '1', 'un reenvío no duplica');

  // La llave sólo sirve para subir
  r = await fetch(A + '?' + new URLSearchParams({ action: 'LISTAR_ACCESOS', llave_envio: L1 })).then(async x => Object.assign(await x.json(), { http: x.status }));
  ok(r.http === 401 && r.codigo === 'SIN_SESION', 'con la llave no se consulta la lista (sólo se suben registros)');
  r = await post('ACCESOS_EN_PLANTA', null, { llave_envio: L1 });
  ok(r.http === 401, 'ni En planta ni nada más');
  r = await subir(null, { uid: 'llave-000000000003', capturado: ahora(10), codigo: '00316', llave_envio: 'L' + '0'.repeat(48) });
  ok(r.http === 401 && r.codigo === 'LLAVE', 'una llave inventada no sirve: ' + r.message);
  // Cuenta desactivada o llave de más de 30 días
  sql("UPDATE usuarios SET activo=0 WHERE usuario='punto1'");
  r = await subir(null, { uid: 'llave-000000000004', capturado: ahora(5), codigo: '00316', llave_envio: L1 });
  ok(r.http === 401 && r.codigo === 'LLAVE', 'si la cuenta se desactivó, su llave ya no sube nada');
  sql("UPDATE usuarios SET activo=1 WHERE usuario='punto1'");
  sql(`UPDATE ca_llaves_envio SET creado = NOW() - INTERVAL 31 DAY WHERE llave = SHA2('${L1}', 256)`);
  r = await subir(null, { uid: 'llave-000000000005', capturado: ahora(5), codigo: '00316', llave_envio: L1 });
  ok(r.http === 401 && r.codigo === 'LLAVE', 'la llave dura 30 días');

  // Un teléfono con la sesión abierta desde antes de la llave la pide una vez
  r = await post('LLAVE_ENVIO', 'tok-punto2');
  ok(r.status === 'success' && /^L[0-9a-f]{48}$/.test(r.llave_envio || ''), 'un teléfono con la sesión ya abierta pide su llave');
  r = await subir(null, { uid: 'llave-000000000007', capturado: ahora(3), codigo: '00316', persona: 'PEDRO VEGA CRUZ', llave_envio: r.llave_envio });
  ok(r.status === 'success' && quien('llave-000000000007') === 'punto2|D', 'y sirve para subir a su nombre');
  ok((await post('LLAVE_ENVIO', 'tok-cliente')).http === 403 && (await post('LLAVE_ENVIO', null)).http === 401, 'el cliente no pide llave, y sin sesión tampoco');

  // Servicio médico: la toma queda a nombre de quien la tomó
  r = await post('LOGIN', null, { usuario: 'medico', password: 'prueba-local' });
  const LM = r.llave_envio;
  r = await post('MEDICO_REGISTRAR', 'tok-medico2', { uid: 'med-llave-0000001', codigo: '00733', nombre: 'MARIA FERNANDA RIOS', sistolica: 118, diastolica: 76, frecuencia: 70,
                                                       resultado: 'A', capturado: ahora(15), origen: 'manual', llave_envio: LM });
  ok(r.status === 'success' && sql("SELECT usuario FROM medico_tomas WHERE uid='med-llave-0000001'") === 'medico', 'la toma médica sube a nombre de quien la tomó (aunque esté otra cuenta)');
  // El rol de la llave manda: la llave del servicio médico no registra accesos
  r = await subir(null, { uid: 'llave-000000000006', capturado: ahora(5), codigo: '00316', llave_envio: LM });
  ok(r.http === 403, 'la llave del servicio médico no registra entradas: ' + r.message);

  sql("DELETE FROM accesos_registros; DELETE FROM medico_tomas WHERE uid='med-llave-0000001'");
  console.log(fallas ? fallas + ' FALLAS' : 'TODO BIEN');
  process.exit(fallas ? 1 : 0);
})().catch(e => { console.error('FALLO', e); process.exit(1); });
