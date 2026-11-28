// Corte del turno (de las 06:00 a la hora del reporte) y aviso de las 16 h en planta, por la API.
// Reinicio diario a las 05:30: quien no registró su salida deja de contar como dentro.
// El cuadre siempre cuadra: dentro al inicio + entradas − salidas − sin salida al reinicio = dentro al corte.
// Base local de prueba; personas e IDs inventados.
import { execSync } from 'node:child_process';
const A = 'http://localhost:8794/api/index.php';
let fallas = 0;
const ok = (c, m) => { if (!c) fallas++; console.log((c ? 'OK    ' : 'FALLA ') + m); };
const sql = q => execSync(`mariadb --default-character-set=utf8mb4 -N -B -uprueba -pprueba -h127.0.0.1 ca_prueba -e "${q}"`).toString().trim();
// «ahora»: la hora del servidor fijada para la prueba (encabezado que sólo acepta la configuración de pruebas)
const get = async (accion, tok, q = {}, ahora = '') => {
  const h = { ...(tok ? { Authorization: 'Bearer ' + tok } : {}), ...(ahora ? { 'X-Prueba-Ahora': ahora } : {}) };
  const r = await fetch(A + '?' + new URLSearchParams({ ...q, action: accion }), { headers: h });
  return Object.assign(await r.json(), { http: r.status });
};
const MX = { timeZone: 'America/Mexico_City' };
const hoy = new Date().toLocaleDateString('sv-SE', MX);
const dia = n => new Date(new Date(hoy + 'T12:00:00Z').getTime() - n * 86400e3).toISOString().slice(0, 10);
let n = 0;
const reg = (tipo, cap, codigo, nombre = '') => {
  n++;
  sql(`INSERT INTO accesos_registros (uid,tipo,fecha,capturado,recibido,lugar,usuario,usuario_nombre,turno_fecha,turno,nombre,codigo,origen)
       VALUES ('ct${n}','${tipo}','${cap.slice(0, 10)}','${cap}',NOW(),'ACCESO','punto1','CASETA PUNTO 1','${cap.slice(0, 10)}','D','${nombre}','${codigo}','${codigo ? 'tarjeta' : 'foto'}')`);
};

(async () => {
  sql('DELETE FROM accesos_registros');
  // ── Corte de ayer de 06:00 a 16:00 ──
  const Y = dia(1), Y1 = dia(2);
  reg('entrada', `${Y1} 18:00:00`, '00101', 'ANA LUCIA PRADO MENA');      // del turno de noche: el reinicio de las 05:30 la quita
  reg('salida', `${Y} 06:30:00`, '00101', 'ANA LUCIA PRADO MENA');        // su salida cuenta en su noche
  reg('entrada', `${Y} 05:40:00`, '00102', 'BRUNO SALAS ORTIZ');           // llegó antes de las 06:00: dentro al inicio
  reg('salida', `${Y} 12:00:00`, '00102', 'BRUNO SALAS ORTIZ');
  reg('entrada', `${Y1} 06:00:00`, '00103', 'CARLA NAVA RIOS');            // ayer sin salida: ya no cuenta
  reg('salida', `${Y} 09:00:00`, '00103', 'CARLA NAVA RIOS');              // su entrada fue hace 27 h: sin entrada
  reg('entrada', `${Y} 06:05:00`, '00104', 'DIEGO LUNA VERA');
  reg('entrada', `${Y} 06:07:00`, '00104', 'DIEGO LUNA VERA');             // leída dos veces
  reg('salida', `${Y} 15:00:00`, '00104', 'DIEGO LUNA VERA');
  reg('salida', `${Y} 07:00:00`, '00105', 'ELENA RUIZ SOTO');              // salida sin entrada
  reg('entrada', `${Y} 06:10:00`, '00106', 'FERNANDO GIL PAZ');           // sigue dentro
  reg('entrada', `${Y} 06:20:00`, '00107', 'GABRIELA MORA LEAL');
  reg('salida', `${Y} 10:00:00`, '00107', 'GABRIELA MORA LEAL');
  reg('entrada', `${Y} 11:00:00`, '107', 'GABRIELA MORA LEAL');           // vuelve a entrar (ID sin ceros)
  reg('entrada', `${Y} 08:00:00`, '', '');                                 // sólo foto, sin ID
  reg('entrada', `${Y} 05:45:00`, '00109', 'IVAN CRUZ OLMO');              // dentro al inicio y sigue dentro
  reg('entrada', `${Y} 17:00:00`, '00108', 'HUGO TAPIA REY');              // después del corte

  let r = await get('TABLERO', 'tok-cliente', { desde: Y, hasta: Y, hora_desde: '06:00', hora_hasta: '16:00' });
  const c = r.cuadre || {};
  ok(r.status === 'success' && r.hora_desde === '06:00' && r.corte === `${Y} 16:00:00`, 'el cliente pide el corte de 06:00 a 16:00: ' + (r.message || r.corte));
  ok(c.inicio === 2 && c.reinicio === '05:30', 'dentro a las 06:00: 2 (entraron después del reinicio de las 05:30) → ' + c.inicio);
  ok(c.entradas === 6 && c.entradas_validas === 4 && c.entradas_repetidas === 1 && c.sin_id === 1,
     `entradas leídas 6: 4 cuentan, 1 repetida y 1 sin ID → ${c.entradas} · ${c.entradas_validas} · ${c.entradas_repetidas} · ${c.sin_id}`);
  ok(c.salidas === 6 && c.salidas_validas === 3 && c.salidas_turno_anterior === 1 && c.salidas_sin_entrada === 2,
     `salidas leídas 6: 3 cuentan, 1 de quien entró antes del reinicio y 2 sin entrada → ${c.salidas} · ${c.salidas_validas} · ${c.salidas_turno_anterior} · ${c.salidas_sin_entrada}`);
  ok(c.vencidas === 0, 'en el periodo no hubo reinicio → ' + c.vencidas);
  ok(c.fin === 3 && c.inicio + c.entradas_validas - c.salidas_validas - c.vencidas === c.fin, `cuadra: 2 + 4 − 3 − 0 = 3 → ${c.fin}`);
  ok(r.kpis.dentro === 3 && r.en_planta.map(x => x.id).sort().join(',') === '00106,00107,00109', 'dentro a las 16:00: 00106, 00107 y 00109 → ' + r.en_planta.map(x => x.id).join(','));
  ok(r.kpis.entradas === 4 && r.kpis.salidas === 3 && c.inicio + r.kpis.entradas - r.kpis.salidas === r.kpis.dentro,
     `las cifras cuadran con quién está dentro: ${c.inicio} + ${r.kpis.entradas} − ${r.kpis.salidas} = ${r.kpis.dentro} (la repetida y las salidas sin entrada van aparte)`);
  const f = r.en_planta.find(x => x.id === '00106') || {};
  ok(f.limite === `${Y} 22:10:00` && f.alerta === '' && f.minutos === 9 * 60 + 50, 'cada quien dice cuándo cumple 16 h: ' + f.limite);
  // Sin horas: el día completo, y también cuadra
  r = await get('TABLERO', 'tok-cliente', { desde: Y, hasta: Y });
  const d = r.cuadre;
  ok(r.hora_desde === '00:00' && d.inicio === 2 && d.vencidas === 1 && d.inicio + d.entradas_validas - d.salidas_validas - d.vencidas === d.fin,
     `el día completo (con el reinicio de las 05:30 en medio) también cuadra: ${d.inicio} + ${d.entradas_validas} − ${d.salidas_validas} − ${d.vencidas} = ${d.fin}`);
  r = await get('TABLERO', 'tok-cliente', { desde: Y, hasta: Y, hora_desde: '16:00', hora_hasta: '06:00' });
  ok(r.status === 'error' && /después/.test(r.message), 'la hora final antes de la inicial: ' + r.message);
  r = await (await fetch(A, { method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: 'Bearer tok-cliente' },
    body: JSON.stringify({ action: 'TABLERO_EXCEL', payload: { desde: Y, hasta: Y, hora_desde: '06:00', hora_hasta: '16:00' } }) })).json();
  ok(r.status === 'success' && /\.xlsx$/.test(r.archivo), 'Excel del corte: ' + r.archivo);

  // ── Aviso de las 16 h (el servidor «a las 23:00» de ayer) ──
  sql('DELETE FROM accesos_registros');
  const ahora = `${Y} 23:00:00`;
  reg('entrada', `${Y} 08:00:00`, '00201', 'IRMA CANO DIAZ');              // 15 h: le falta 1 h, aviso
  reg('entrada', `${Y} 06:00:00`, '00202', 'JORGE PENA LIRA');             // 17 h: ya cumplió
  reg('entrada', `${Y} 20:00:00`, '00203', 'KARLA SOTO VEGA');             // lleva 3 h
  reg('entrada', `${Y} 06:30:00`, '00204', 'LUIS MENA ROJO');              // ya salió
  reg('salida', `${Y} 22:00:00`, '00204', 'LUIS MENA ROJO');
  reg('entrada', `${Y} 05:00:00`, '00205', 'MARTA RUIZ LARA');             // antes del reinicio de las 05:30: ya no cuenta
  r = await get('ACCESOS_ALERTAS_16H', 'tok-punto1', {}, ahora);
  ok(r.status === 'success' && r.horas_limite === 16 && r.horas_aviso === 2, 'la caseta pide los avisos de 16 h');
  ok((r.personas || []).map(x => x.id + ' ' + x.alerta).join(', ') === '00202 limite, 00201 aviso', 'primero quien ya cumplió, luego a quien le falta menos de 2 h: ' + (r.personas || []).map(x => x.id + ' ' + x.alerta).join(', '));
  const irma = (r.personas || [])[1] || {};
  ok(Math.abs(irma.minutos_limite - 60) <= 1 && irma.nombre === 'IRMA CANO DIAZ' && !('caseta' in irma), 'le falta 1 h; la caseta sólo ve ID, nombre y horas');
  r = await get('ACCESOS_EN_PLANTA', 'tok-admin', {}, ahora);
  ok(r.total === 3 && r.aviso === 1 && r.limite === 1 && (r.personas.find(x => x.id === '00203') || {}).alerta === '' && !r.personas.find(x => x.id === '00205'),
     'En planta: 3 dentro, 1 con aviso y 1 que ya cumplió; quien entró antes de las 05:30 ya no');
  r = await get('ACCESOS_ALERTAS_16H', 'tok-cliente', {}, ahora);
  ok(r.status === 'error', 'el cliente no la pide (la ve en su tablero)');
  r = await get('TABLERO', 'tok-cliente', { desde: Y, hasta: Y, hora_desde: '00:00' }, ahora);
  ok(r.kpis.dentro === 3 && r.kpis.dentro_aviso === 1 && r.kpis.dentro_limite === 1 && r.en_planta[0].alerta === 'limite',
     'el tablero también: 1 con aviso, 1 que ya cumplió y van primero');
  r = await get('ACCESOS_EN_PLANTA', 'tok-admin', {}, `${dia(0)} 05:31:00`);
  ok(r.total === 0, 'después del reinicio de las 05:30 de hoy, nadie de ayer sigue dentro: ' + r.total);

  sql('DELETE FROM accesos_registros');
  console.log(fallas ? fallas + ' FALLAS' : 'TODO BIEN');
  process.exit(fallas ? 1 : 0);
})().catch(e => { console.error('FALLO', e); process.exit(1); });
