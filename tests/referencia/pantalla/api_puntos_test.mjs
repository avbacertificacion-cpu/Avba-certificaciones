// Prueba de punta a punta de la API real (copia local, base de prueba): puntos de control
const A = 'http://localhost:8794/api/index.php';
let fallas = 0;
const ok = (c, m) => { if (!c) fallas++; console.log((c ? 'OK    ' : 'FALLA ') + m); };
const get = async (accion, tok, q = {}) => {
  const u = new URLSearchParams({ ...q, action: accion, token: tok });
  return (await fetch(A + '?' + u)).json();
};
const post = async (accion, tok, payload = {}) => (await fetch(A, {
  method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + tok },
  body: JSON.stringify({ action: accion, token: tok, payload })
})).json();
const registrar = async (tok, campos) => {
  const fd = new FormData();
  for (const [k, v] of Object.entries(campos)) fd.append(k, v);
  fd.append('action', 'ACCESO_REGISTRAR'); fd.append('token', tok);
  return (await fetch(A, { method: 'POST', headers: { Authorization: 'Bearer ' + tok }, body: fd })).json();
};
const EQ_A = 'eq-11111111-aaaa', EQ_B = 'eq-22222222-bbbb';
const dosDig = n => String(n).padStart(2, '0');
// La fecha de hoy en la planta (no la del equipo donde corre la prueba, que puede estar en UTC)
const hoy = new Date().toLocaleDateString('sv-SE', { timeZone: 'America/Mexico_City' });
const hora = h => hoy + ' ' + h;

let r = await get('ACCESOS_MI_PUNTO', 'tok-punto1', { dispositivo: EQ_A });
ok(r.status === 'error' && r.codigo === 'SIN_PUNTO', 'cuenta sin punto: ' + r.message);
r = await post('ACCESOS_PUNTO_ASIGNAR', 'tok-punto1', { punto: 'PUNTO DE CONTROL 1', usuario: 'punto1' });
ok(r.status === 'error', 'la caseta no puede asignarse un punto: ' + r.message);
r = await post('ACCESOS_PUNTO_ASIGNAR', 'tok-admin', { punto: 'PUNTO DE CONTROL 1', usuario: 'punto1' });
ok(r.status === 'success', 'admin asigna punto1 → Punto 1: ' + r.message);
r = await post('ACCESOS_PUNTO_ASIGNAR', 'tok-admin', { punto: 'PUNTO DE CONTROL 2', usuario: 'punto2' });
r = await get('ACCESOS_MI_PUNTO', 'tok-punto1', { dispositivo: EQ_A, dispositivo_desc: 'App Control de Accesos · Android · SM-A145M' });
ok(r.status === 'success' && r.punto === 'PUNTO DE CONTROL 1' && r.nombre_punto === 'Punto de control 1', 'al entrar: su punto y el equipo A queda ligado');
r = await get('ACCESOS_MI_PUNTO', 'tok-punto1', { dispositivo: EQ_B });
ok(r.status === 'error' && r.codigo === 'OTRO_EQUIPO' && /SM-A145M/.test(r.message), 'otro equipo con la misma cuenta: bloqueado («' + r.message + '»)');
r = await get('ACCESOS_MI_PUNTO', 'tok-punto1', { dispositivo: '' });
ok(r.status === 'error' && r.codigo === 'SIN_EQUIPO', 'sin identificador de equipo: rechazado');

// Registrar: queda en SU punto aunque la pantalla mande otro
r = await registrar('tok-punto1', { uid: 'e2e-00000000001', tipo: 'entrada', capturado: hora('09:01:55'), lugar: 'OTRA CASETA', nota: '', codigo: '27716004', persona: 'Sergio Ibarra Montes', origen: 'tarjeta', dispositivo: EQ_A });
ok(r.status === 'success', 'entrada desde el equipo ligado: ' + r.message);
r = await registrar('tok-punto1', { uid: 'e2e-00000000002', tipo: 'salida', capturado: hora('09:02:25'), lugar: 'X', nota: '', codigo: '27716004', persona: 'Mexicana Nacionalidad', origen: 'tarjeta', dispositivo: EQ_B });
ok(r.status === 'error' && r.codigo === 'OTRO_EQUIPO', 'registro desde otro equipo: rechazado');
r = await registrar('tok-punto1', { uid: 'e2e-00000000003', tipo: 'salida', capturado: hora('09:02:25'), lugar: 'X', nota: '', codigo: '27716004', persona: 'Mexicana Nacionalidad', origen: 'tarjeta', dispositivo: EQ_A });
r = await registrar('tok-punto1', { uid: 'e2e-00000000001', tipo: 'entrada', capturado: hora('09:01:55'), lugar: 'X', nota: '', codigo: '27716004', persona: 'Sergio Ibarra Montes', origen: 'tarjeta', dispositivo: EQ_B });
ok(r.status === 'success' && r.duplicado, 'reenvío de algo ya guardado: se acepta (no se pierde)');

// Lista: la caseta ve su avance, desde su equipo
r = await get('LISTAR_ACCESOS', 'tok-punto1', { dispositivo: EQ_A });
const k = r.turnos && r.turnos[0];
ok(r.status === 'success' && r.punto === 'PUNTO DE CONTROL 1' && r.registros.length >= 2 && r.registros.every(x => x.lugar === 'PUNTO DE CONTROL 1'), 'lista de la caseta: ' + (r.registros || []).length + ' registros, todos en Punto de control 1');
ok(r.registros.some(x => x.codigo === '27716004' && x.persona === 'SERGIO IBARRA MONTES'), 'la lista trae nombre y código');
r = await get('LISTAR_ACCESOS', 'tok-punto1', { dispositivo: EQ_B });
ok(r.status === 'error' && r.codigo === 'OTRO_EQUIPO', 'lista desde otro equipo: bloqueada');
r = await get('LISTAR_ACCESOS_LUGARES', 'tok-admin');
ok(r.status === 'success' && ['PUNTO DE CONTROL 1', 'PUNTO DE CONTROL 2', 'PUNTO DE CONTROL 3', 'PUNTO DE CONTROL 4'].every(p => r.lugares.includes(p)), 'lugares: los 4 puntos fijos primero → ' + r.lugares.slice(0, 5).join(', '));

// Administración: puntos, liberar, mover
r = await get('ACCESOS_PUNTOS', 'tok-admin');
const p1 = (r.puntos || []).find(p => p.punto === 'PUNTO DE CONTROL 1');
ok(r.status === 'success' && r.puntos.length === 4 && p1.usuario === 'punto1' && p1.dispositivo === true && /SM-A145M/.test(p1.dispositivo_desc) && r.cuentas.length === 6 && r.cuentas.filter(c => c.turno === 'N').length === 2 && r.puede_editar, 'admin ve los 4 puntos, las cuentas (con su turno) y el equipo ligado');
r = await get('ACCESOS_PUNTOS', 'tok-punto1', { dispositivo: EQ_A });
ok(r.status === 'error', 'la caseta no ve la administración de puntos');
r = await post('ACCESOS_EQUIPO_LIBERAR', 'tok-admin', { punto: 'PUNTO DE CONTROL 1' });
ok(r.status === 'success', 'admin libera el equipo: ' + r.message);
r = await get('ACCESOS_MI_PUNTO', 'tok-punto1', { dispositivo: EQ_B, dispositivo_desc: 'Tablet nueva' });
ok(r.status === 'success', 'después de liberar, el equipo B se liga');
r = await get('ACCESOS_MI_PUNTO', 'tok-punto1', { dispositivo: EQ_A });
ok(r.status === 'error' && r.codigo === 'OTRO_EQUIPO', 'y el equipo A ya queda fuera');
r = await post('ACCESOS_PUNTO_ASIGNAR', 'tok-admin', { punto: 'PUNTO DE CONTROL 3', usuario: 'punto1' });
r = await get('ACCESOS_PUNTOS', 'tok-admin');
const porP = Object.fromEntries(r.puntos.map(p => [p.punto, p]));
ok(porP['PUNTO DE CONTROL 1'].usuario === '' && porP['PUNTO DE CONTROL 3'].usuario === 'punto1' && !porP['PUNTO DE CONTROL 3'].dispositivo, 'mover la cuenta al Punto 3: el 1 queda libre y el equipo se vuelve a ligar');
r = await post('ACCESOS_PUNTO_ASIGNAR', 'tok-admin', { punto: 'PUNTO DE CONTROL 9', usuario: 'punto1' });
ok(r.status === 'error', 'punto inexistente: ' + r.message);
r = await post('ACCESOS_PUNTO_ASIGNAR', 'tok-admin', { punto: 'PUNTO DE CONTROL 4', usuario: 'admin' });
ok(r.status === 'error', 'sólo cuentas de control de accesos: ' + r.message);

// PDF de datos: el nombre más leído (la salida leyó «Mexicana Nacionalidad»)
// Los registros son de las 09:0x: turno de día de hoy (no el que esté en curso al correr la prueba)
r = await post('ACCESOS_PDF_DATOS', 'tok-admin', { turno: hoy + '-D' });
ok(r.status === 'success' && r.personas === 1 && r.renglones === 1 && r.sin_salida === 0, 'PDF de datos: ' + JSON.stringify({ personas: r.personas, renglones: r.renglones, salidas: r.salidas, msg: r.message }));
console.log(fallas ? fallas + ' FALLAS' : 'TODO BIEN');
process.exit(fallas ? 1 : 0);
