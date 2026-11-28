// Corte del turno (de las 06:00 a la hora del reporte) y aviso de las 16 h en planta, por la API.
// El cuadre siempre cuadra: dentro al inicio + entradas − salidas − sin salida por más de 48 h = dentro al corte.
// Base local de prueba; personas e IDs inventados.
import { execSync } from 'node:child_process';
const A = 'http://localhost:8794/api/index.php';
let fallas = 0;
const ok = (c, m) => { if (!c) fallas++; console.log((c ? 'OK    ' : 'FALLA ') + m); };
const sql = q => execSync(`mariadb --default-character-set=utf8mb4 -N -B -uprueba -pprueba -h127.0.0.1 ca_prueba -e "${q}"`).toString().trim();
const get = async (accion, tok, q = {}) => {
  const r = await fetch(A + '?' + new URLSearchParams({ ...q, action: accion }), { headers: tok ? { Authorization: 'Bearer ' + tok } : {} });
  return Object.assign(await r.json(), { http: r.status });
};
const MX = { timeZone: 'America/Mexico_City' };
const hoy = new Date().toLocaleDateString('sv-SE', MX);
const dia = n => new Date(new Date(hoy + 'T12:00:00Z').getTime() - n * 86400e3).toISOString().slice(0, 10);
const haceHoras = h => new Date(Date.now() - h * 3600e3).toLocaleString('sv-SE', MX);       // «AAAA-MM-DD HH:MM:SS» en la planta
let n = 0;
const reg = (tipo, cap, codigo, nombre = '') => {
  n++;
  sql(`INSERT INTO accesos_registros (uid,tipo,fecha,capturado,recibido,lugar,usuario,usuario_nombre,turno_fecha,turno,nombre,codigo,origen)
       VALUES ('ct${n}','${tipo}','${cap.slice(0, 10)}','${cap}',NOW(),'ACCESO','punto1','CASETA PUNTO 1','${cap.slice(0, 10)}','D','${nombre}','${codigo}','${codigo ? 'tarjeta' : 'foto'}')`);
};

(async () => {
  sql('DELETE FROM accesos_registros');
  // ── Corte de ayer de 06:00 a 16:00 ──
  const Y = dia(1), Y1 = dia(2), Y2 = dia(3), Y3 = dia(4);
  reg('entrada', `${Y1} 18:00:00`, '00101', 'ANA LUCIA PRADO MENA');      // del turno de noche: dentro a las 06:00
  reg('salida', `${Y} 06:30:00`, '00101', 'ANA LUCIA PRADO MENA');
  reg('entrada', `${Y2} 08:00:00`, '00102', 'BRUNO SALAS ORTIZ');          // nunca salió: dentro a las 06:00 y a las 08:00 pasa de 48 h
  reg('entrada', `${Y3} 06:00:00`, '00103', 'CARLA NAVA RIOS');            // hace más de 48 h: ya no cuenta
  reg('entrada', `${Y} 06:05:00`, '00104', 'DIEGO LUNA VERA');
  reg('entrada', `${Y} 06:07:00`, '00104', 'DIEGO LUNA VERA');             // leída dos veces
  reg('salida', `${Y} 15:00:00`, '00104', 'DIEGO LUNA VERA');
  reg('salida', `${Y} 07:00:00`, '00105', 'ELENA RUIZ SOTO');              // salida sin entrada
  reg('entrada', `${Y} 06:10:00`, '00106', 'FERNANDO GIL PAZ');           // sigue dentro
  reg('entrada', `${Y} 06:20:00`, '00107', 'GABRIELA MORA LEAL');
  reg('salida', `${Y} 10:00:00`, '00107', 'GABRIELA MORA LEAL');
  reg('entrada', `${Y} 11:00:00`, '107', 'GABRIELA MORA LEAL');           // vuelve a entrar (ID sin ceros)
  reg('entrada', `${Y} 08:00:00`, '', '');                                 // sólo foto, sin ID
  reg('salida', `${Y} 09:00:00`, '00103', 'CARLA NAVA RIOS');              // su entrada era de hace más de 48 h
  reg('entrada', `${Y} 17:00:00`, '00108', 'HUGO TAPIA REY');              // después del corte

  let r = await get('TABLERO', 'tok-cliente', { desde: Y, hasta: Y, hora_desde: '06:00', hora_hasta: '16:00' });
  const c = r.cuadre || {};
  ok(r.status === 'success' && r.hora_desde === '06:00' && r.corte === `${Y} 16:00:00`, 'el cliente pide el corte de 06:00 a 16:00: ' + (r.message || r.corte));
  ok(c.inicio === 2, 'dentro a las 06:00: 2 (la del turno de noche y quien lleva 46 h sin salida) → ' + c.inicio);
  ok(c.entradas === 6 && c.entradas_validas === 4 && c.entradas_repetidas === 1 && c.sin_id === 1,
     `entradas leídas 6: 4 cuentan, 1 repetida y 1 sin ID → ${c.entradas} · ${c.entradas_validas} · ${c.entradas_repetidas} · ${c.sin_id}`);
  ok(c.salidas === 5 && c.salidas_validas === 3 && c.salidas_sin_entrada === 2, `salidas leídas 5: 3 cuentan y 2 sin entrada → ${c.salidas} · ${c.salidas_validas} · ${c.salidas_sin_entrada}`);
  ok(c.vencidas === 1, 'una pasó de 48 h sin salida y ya no cuenta → ' + c.vencidas);
  ok(c.fin === 2 && c.inicio + c.entradas_validas - c.salidas_validas - c.vencidas === c.fin, `cuadra: 2 + 4 − 3 − 1 = 2 → ${c.fin}`);
  ok(r.kpis.dentro === 2 && r.en_planta.map(x => x.id).sort().join(',') === '00106,00107', 'dentro a las 16:00: 00106 y 00107 → ' + r.en_planta.map(x => x.id).join(','));
  ok(r.kpis.entradas === 6 && r.kpis.salidas === 5, 'las cifras de entradas y salidas son las mismas lecturas del cuadre');
  const f = r.en_planta.find(x => x.id === '00106') || {};
  ok(f.limite === `${Y} 22:10:00` && f.alerta === '' && f.minutos === 9 * 60 + 50, 'cada quien dice cuándo cumple 16 h: ' + f.limite);
  // Sin horas: el día completo, y también cuadra
  r = await get('TABLERO', 'tok-cliente', { desde: Y, hasta: Y });
  const d = r.cuadre;
  ok(r.hora_desde === '00:00' && d.inicio + d.entradas_validas - d.salidas_validas - d.vencidas === d.fin, `el día completo también cuadra: ${d.inicio} + ${d.entradas_validas} − ${d.salidas_validas} − ${d.vencidas} = ${d.fin}`);
  r = await get('TABLERO', 'tok-cliente', { desde: Y, hasta: Y, hora_desde: '16:00', hora_hasta: '06:00' });
  ok(r.status === 'error' && /después/.test(r.message), 'la hora final antes de la inicial: ' + r.message);
  r = await (await fetch(A, { method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: 'Bearer tok-cliente' },
    body: JSON.stringify({ action: 'TABLERO_EXCEL', payload: { desde: Y, hasta: Y, hora_desde: '06:00', hora_hasta: '16:00' } }) })).json();
  ok(r.status === 'success' && /\.xlsx$/.test(r.archivo), 'Excel del corte: ' + r.archivo);

  // ── Aviso de las 16 h ──
  sql('DELETE FROM accesos_registros');
  reg('entrada', haceHoras(15), '00201', 'IRMA CANO DIAZ');                // le falta 1 h: aviso
  reg('entrada', haceHoras(17), '00202', 'JORGE PENA LIRA');               // ya cumplió
  reg('entrada', haceHoras(3), '00203', 'KARLA SOTO VEGA');                // lleva 3 h
  reg('entrada', haceHoras(20), '00204', 'LUIS MENA ROJO');                // ya salió
  reg('salida', haceHoras(1), '00204', 'LUIS MENA ROJO');
  r = await get('ACCESOS_ALERTAS_16H', 'tok-punto1');
  ok(r.status === 'success' && r.horas_limite === 16 && r.horas_aviso === 2, 'la caseta pide los avisos de 16 h');
  ok(r.personas.map(x => x.id + ' ' + x.alerta).join(', ') === '00202 limite, 00201 aviso', 'primero quien ya cumplió, luego a quien le falta menos de 2 h: ' + r.personas.map(x => x.id + ' ' + x.alerta).join(', '));
  ok(Math.abs(r.personas[1].minutos_limite - 60) <= 1 && r.personas[1].nombre === 'IRMA CANO DIAZ' && !('caseta' in r.personas[1]), 'le falta 1 h; la caseta sólo ve ID, nombre y horas');
  r = await get('ACCESOS_EN_PLANTA', 'tok-admin');
  ok(r.total === 3 && r.aviso === 1 && r.limite === 1 && r.personas.find(x => x.id === '00203').alerta === '', 'En planta: 3 dentro, 1 con aviso y 1 que ya cumplió');
  r = await get('ACCESOS_ALERTAS_16H', 'tok-cliente');
  ok(r.status === 'error', 'el cliente no la pide (la ve en su tablero)');
  r = await get('TABLERO', 'tok-cliente', { desde: hoy, hasta: hoy, hora_desde: '00:00' });
  ok(r.kpis.dentro === 3 && r.kpis.dentro_aviso === 1 && r.kpis.dentro_limite === 1 && r.en_planta[0].alerta === 'limite',
     'el tablero también: 1 con aviso, 1 que ya cumplió y van primero');

  sql('DELETE FROM accesos_registros');
  console.log(fallas ? fallas + ' FALLAS' : 'TODO BIEN');
  process.exit(fallas ? 1 : 0);
})().catch(e => { console.error('FALLO', e); process.exit(1); });
