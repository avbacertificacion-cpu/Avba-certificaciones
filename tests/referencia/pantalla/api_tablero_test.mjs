// Tablero (perfil del cliente) por la API: permisos de cada perfil, las cuentas del periodo con
// datos conocidos (visitas que cruzan la medianoche, ID sin ceros, salida sin registrar, sólo
// foto, alguien dentro ahora), el Excel (abre con sus hojas, el ID como texto) y el PDF.
// Base local de prueba; personas e IDs inventados.
import { execSync } from 'node:child_process';
const B = 'http://localhost:8794/';
const A = B + 'api/index.php';
const D = new URL('.', import.meta.url).pathname;
let fallas = 0;
const ok = (c, m) => { if (!c) fallas++; console.log((c ? 'OK    ' : 'FALLA ') + m); };
const sql = q => execSync(`mariadb --default-character-set=utf8mb4 -N -B -uprueba -pprueba -h127.0.0.1 ca_prueba -e "${q}"`).toString().trim();
const get = async (accion, tok, q = {}) => {
  const r = await fetch(A + '?' + new URLSearchParams({ ...q, action: accion }), { headers: tok ? { Authorization: 'Bearer ' + tok } : {} });
  return Object.assign(await r.json(), { http: r.status });
};
const post = async (accion, tok, payload = {}) => {
  const r = await fetch(A, { method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + tok }, body: JSON.stringify({ action: accion, payload }) });
  return Object.assign(await r.json(), { http: r.status });
};
// Fechas de la planta (Ciudad de México): hoy y n días antes
const hoy = new Date().toLocaleDateString('sv-SE', { timeZone: 'America/Mexico_City' });
const dia = n => new Date(new Date(hoy + 'T12:00:00Z').getTime() - n * 86400e3).toISOString().slice(0, 10);
let n = 0;
const reg = (tipo, cap, codigo, nombre, turno = 'D', origen = 'tarjeta') => {
  n++;
  sql(`INSERT INTO accesos_registros (uid,tipo,fecha,capturado,recibido,lugar,usuario,usuario_nombre,turno_fecha,turno,nombre,codigo,origen)
       VALUES ('tt${n}','${tipo}','${cap.slice(0, 10)}','${cap}',NOW(),'ACCESO','punto1','CASETA PUNTO 1','${cap.slice(0, 10)}','${turno}','${nombre}','${codigo}','${origen}')`);
};

// ── Datos conocidos (del día -3 a hoy) ──
reg('entrada', `${dia(10)} 06:00:00`, '00287', 'RAMIRO ESTEBAN VILLARREAL DUARTE');        // fuera del periodo
reg('salida', `${dia(10)} 18:00:00`, '00287', 'RAMIRO ESTEBAN VILLARREAL DUARTE');
reg('entrada', `${dia(3)} 06:00:00`, '00287', 'RAMIRO ESTEBAN VILLARREAL DUARTE');          // 12 h 30 min
reg('salida', `${dia(3)} 18:30:00`, '00287', 'RAMIRO ESTEBAN VILLARREAL DUARTE');
reg('entrada', `${dia(3)} 18:00:00`, '00412', 'ANA SOFIA RUIZ PAEZ', 'N');                   // noche: 12 h 10 min, cruza la medianoche
reg('salida', `${dia(2)} 06:10:00`, '412', '', 'N');                                         // salida escrita sin ceros
reg('entrada', `${dia(2)} 06:00:00`, '00316', 'PEDRO VEGA CRUZ');                            // salió sin pasar su credencial
reg('entrada', `${dia(2)} 07:00:00`, '', '', 'D', 'foto');                                   // sólo foto, sin ID
reg('entrada', `${dia(1)} 06:05:00`, '00316', 'PEDRO VEGA CRUZ');                            // 14 h 25 min
reg('salida', `${dia(1)} 20:30:00`, '00316', 'PEDRO VEGA CRUZ');
const yaDentro = new Date(Date.now() - 60e3).toLocaleString('sv-SE', { timeZone: 'America/Mexico_City' }).replace('T', ' ');
reg('entrada', yaDentro, '00500', 'LUIS MORA DIAZ');                                         // dentro ahora
sql(`INSERT INTO accesos_incidencias (uid,tipo,detalle,fecha,capturado,recibido,turno_fecha,turno,codigo,nombre,usuario,usuario_nombre)
     VALUES ('ti1','ALCOHOLIMETRO','Prueba positiva 0.4','${dia(2)}','${dia(2)} 06:20:00',NOW(),'${dia(2)}','D','00287','RAMIRO ESTEBAN VILLARREAL DUARTE','punto1','CASETA PUNTO 1'),
            ('ti2','CONDUCTA','Fuera del periodo','${dia(20)}','${dia(20)} 06:20:00',NOW(),'${dia(20)}','D','00412','ANA SOFIA RUIZ PAEZ','punto1','CASETA PUNTO 1')`);

// ── 1) Quién puede ver el tablero ──
const periodo = { desde: dia(3), hasta: hoy };
for (const t of ['tok-cliente', 'tok-planta', 'tok-admin']) {
  const r = await get('TABLERO', t, periodo);
  ok(r.status === 'success', `${t.slice(4)} ve el tablero`);
}
let r = await get('TABLERO', 'tok-punto1', periodo);
ok(r.status === 'error' && r.http === 403, 'el guardia no ve el tablero');
r = await get('TABLERO', null, periodo);
ok(r.http === 401, 'sin sesión: 401');
for (const a of ['LISTAR_ACCESOS', 'ACCESOS_EN_PLANTA', 'ACCESOS_PERSONA', 'CUENTAS_LISTAR', 'LISTAR_ACCESOS_LUGARES', 'ACCESOS_TARJETA_REGLAS']) {
  r = await get(a, 'tok-cliente', { id: '287' });
  ok(r.status === 'error' && r.http === 403 && /sólo consulta el tablero/.test(r.message), `el cliente no puede ${a}`);
}
for (const a of ['ACCESO_REGISTRAR', 'ACCESO_ELIMINAR', 'ACCESOS_DAR_SALIDA', 'ACCESOS_PDF_DATOS', 'ACCESO_INCIDENCIA', 'CUENTA_CREAR']) {
  r = await post(a, 'tok-cliente', { id: 1, tipo: 'entrada', uid: 'x-cliente-1', codigo: '287' });
  ok(r.status === 'error' && r.http === 403, `el cliente no puede ${a}`);
}
ok(sql("SELECT COUNT(*) FROM accesos_registros WHERE uid = 'x-cliente-1'") === '0', 'y no se registró nada');
r = await get('SESION', 'tok-cliente');
ok(r.status === 'success' && r.rol === 'CLIENTE' && /Cliente/.test(r.nombre_rol), 'SESION del cliente: ' + r.nombre_rol);

// ── 2) Las cuentas del periodo ──
r = await get('TABLERO', 'tok-cliente', periodo);
const k = r.kpis;
ok(r.desde === dia(3) && r.hasta === hoy && r.dias === 4, `periodo del ${r.desde} al ${r.hasta} (${r.dias} días)`);
ok(k.entradas === 6 && k.salidas === 3, `entradas ${k.entradas} y salidas ${k.salidas} (sin lo de hace 10 días)`);
ok(k.personas === 4 && k.sin_id === 1, `personas distintas ${k.personas} (412 = 00412) y 1 sólo foto (${k.sin_id})`);
ok(k.visitas === 3 && k.minutos === 750 + 730 + 865 && k.promedio_min === 782, `3 visitas completas: ${k.minutos} min, promedio ${k.promedio_min}`);
ok(k.sin_salida === 1, `1 entrada sin salida (quien sigue dentro no cuenta): ${k.sin_salida}`);
ok(k.dentro === 1 && r.en_planta[0].id === '00500' && r.en_planta[0].registro === undefined, 'dentro ahora: el 00500 (sin datos internos del registro)');
ok(k.incidencias === 1 && r.incidencias_tipo.find(x => x.tipo === 'ALCOHOLIMETRO').n === 1, 'incidencias del periodo: 1 alcoholímetro (la de hace 20 días no)');
ok(k.entradas_dia === 5 && k.entradas_noche === 1, `entradas por turno: día ${k.entradas_dia}, noche ${k.entradas_noche}`);
const pd = Object.fromEntries(r.por_dia.map(x => [x.fecha, x]));
ok(pd[dia(3)].entradas === 2 && pd[dia(3)].salidas === 1 && pd[dia(2)].entradas === 2 && pd[dia(2)].salidas === 1 && pd[dia(2)].incidencias === 1,
   'por día: cada lectura en el día en que se hizo');
const hDentro = +yaDentro.slice(11, 13);                                                   // la entrada de «ahora» cae en su hora
ok(r.por_hora[6].entradas === 3 + (hDentro === 6) && r.por_hora[18].entradas === 1 + (hDentro === 18) && r.por_hora[18].salidas === 1,
   'por hora: 3 entradas a las 06 h, 1 entrada y 1 salida a las 18 h');
const perm = Object.fromEntries(r.permanencia.map(x => [x.rango, x.n]));
ok(perm['11 a 13 h'] === 2 && perm['13 a 15 h'] === 1, 'tiempo dentro: 2 de 11 a 13 h y 1 de 13 a 15 h');
ok(r.top_personas.map(x => x.id).join(',') === '00316,00287,00412', 'más horas: ' + r.top_personas.map(x => x.id + ' ' + x.minutos).join(', '));
ok(r.top_personas[2].nombre === 'ANA SOFIA RUIZ PAEZ', 'el 412 conserva su nombre leído en la entrada');
ok(r.ocupacion.nivel === 'hora' && Math.max(...r.ocupacion.puntos.map(x => x.n)) === 2, 'personas dentro hora por hora: máximo 2 (en los cambios de turno)');
const p18 = r.ocupacion.puntos.find(x => x.t === `${dia(3)} 18:00`), p06 = r.ocupacion.puntos.find(x => x.t === `${dia(2)} 06:00`);
ok(p18 && p18.n === 2 && p06 && p06.n === 2, 'a las 18 h del día -3 y a las 06 h del día -2 hubo 2 dentro');
ok(r.movimientos_total === 5, 'movimientos del periodo: ' + r.movimientos_total);

// Periodos
r = await get('TABLERO', 'tok-cliente', { desde: dia(200), hasta: hoy });
ok(r.status === 'error' && /hasta 93 días/.test(r.message), 'más de 3 meses: ' + r.message);
r = await get('TABLERO', 'tok-cliente', { desde: hoy, hasta: dia(3) });
ok(r.status === 'success' && r.desde === dia(3) && r.hasta === hoy, 'desde y hasta al revés: se acomodan');
r = await get('TABLERO', 'tok-cliente', { desde: dia(1), hasta: '2099-01-01' });
ok(r.status === 'success' && r.hasta === hoy, 'hasta en el futuro: llega a hoy');
r = await get('TABLERO', 'tok-cliente');
ok(r.status === 'success' && r.dias === 7 && r.hasta === hoy, 'sin fechas: los últimos 7 días');
r = await get('TABLERO', 'tok-cliente', { desde: dia(30), hasta: hoy });
ok(r.ocupacion.nivel === 'dia' && r.ocupacion.puntos.length === 31, 'más de 7 días: el máximo de cada día');

// ── 3) Excel ──
r = await post('TABLERO_EXCEL', 'tok-cliente', periodo);
ok(r.status === 'success' && /^TABLERO_.*\.xlsx$/.test(r.archivo) && r.movimientos === 5, 'Excel generado: ' + r.archivo);
let d = await fetch(B + r.url + '&token=tok-cliente');
const xlsx = Buffer.from(await d.arrayBuffer());
ok(d.status === 200 && /spreadsheetml/.test(d.headers.get('content-type')) && /attachment/.test(d.headers.get('content-disposition')) && xlsx.slice(0, 2).toString() === 'PK',
   'el Excel se descarga como archivo (' + xlsx.length + ' bytes)');
const ruta = D + 'tablero.xlsx';
(await import('node:fs')).writeFileSync(ruta, xlsx);
const py = execSync(`python3 - "${ruta}" <<'EOF'
import sys, json, openpyxl
wb = openpyxl.load_workbook(sys.argv[1])
m = wb['Movimientos']
filas = [r for r in m.iter_rows(min_row=6, values_only=True)]
print(json.dumps({'hojas': wb.sheetnames, 'movs': len(filas), 'id': filas[0][0], 'tipoId': type(filas[0][0]).__name__,
                  'entrada': str(filas[0][2]), 'horas': filas[0][5], 'obs': sorted(set(str(f[6]) for f in filas)),
                  'resumen': [[str(c) for c in r] for r in wb['Resumen'].iter_rows(min_row=5, max_row=7, values_only=True)],
                  'personas': wb['Por persona'].max_row - 5, 'inc': wb['Incidencias'].max_row - 5}))
EOF`).toString();
const x = JSON.parse(py);
ok(x.hojas.join(',') === 'Resumen,Por día,Movimientos,Por persona,Por hora,Tiempo dentro,Incidencias,En planta al corte', 'hojas: ' + x.hojas.join(', '));
ok(x.movs === 5 && x.id === '00287' && x.tipoId === 'str', `movimientos: ${x.movs}, el ID va como texto con sus ceros (${x.id})`);
ok(x.entrada === `${dia(3)} 06:00:00` && x.horas === 12.5, `fecha y hora de Excel (${x.entrada}) y horas en número (${x.horas})`);
ok(x.obs.includes('Sin salida registrada') && x.obs.includes('Dentro de la planta'), 'observaciones: ' + x.obs.join(' | '));
ok(/^Dentro de la planta al corte \(/.test(x.resumen[0][0]) && x.resumen[0][1] === '1', 'resumen: ' + JSON.stringify(x.resumen[0]));
ok(x.personas === 4 && x.inc === 1, `por persona ${x.personas}, incidencias ${x.inc}`);

// ── 4) PDF ──
const png = execSync(`python3 -c "
import zlib,struct,base64
w,h=300,120
raw=b''.join(b'\\x00'+bytes([37,125,224])*w for _ in range(h))
def ch(t,d): return struct.pack('>I',len(d))+t+d+struct.pack('>I',zlib.crc32(t+d)&0xffffffff)
p=b'\\x89PNG\\r\\n\\x1a\\n'+ch(b'IHDR',struct.pack('>IIBBBBB',w,h,8,2,0,0,0))+ch(b'IDAT',zlib.compress(raw))+ch(b'IEND',b'')
print(base64.b64encode(p).decode())"`).toString().trim();
r = await post('TABLERO_PDF', 'tok-cliente', { ...periodo, graficas: [
  { titulo: 'Gráfica de prueba', sub: 'Azul', imagen: 'data:image/png;base64,' + png },
  { titulo: 'No es imagen', imagen: 'data:image/png;base64,' + Buffer.from('<script>alert(1)</script>'.repeat(10)).toString('base64') },
  { titulo: 'Otro formato', imagen: 'data:image/jpeg;base64,' + png }] });
ok(r.status === 'success' && r.graficas === 1 && r.paginas >= 1, `PDF con ${r.graficas} gráfica válida (las otras se ignoran), ${r.paginas} página(s)`);
d = await fetch(B + r.url + '&token=tok-cliente');
const pdf = Buffer.from(await d.arrayBuffer());
ok(d.status === 200 && pdf.slice(0, 4).toString() === '%PDF' && /application\/pdf/.test(d.headers.get('content-type')), 'el PDF se descarga');
const txt = execSync(`pdftotext -layout - - `, { input: pdf }).toString().replace(/\ufb01/g, 'fi');   // el PDF une «fi» en una letra
ok(/CONTROL DE ACCESOS/.test(txt) && /RAMIRO ESTEBAN VILLARREAL DUARTE/.test(txt) && /Gráfica de prueba/.test(txt) && /Prueba positiva 0.4/.test(txt) && /LUIS MORA DIAZ/.test(txt),
   'el PDF trae cifras, gráfica, personas, incidencias y quién está dentro');
ok(!/tmp-/.test(execSync(`ls uploads/accesos/reportes/ 2>/dev/null || ls tests/referencia/php/uploads/accesos/reportes/ 2>/dev/null || true`).toString()), 'no quedan carpetas temporales');

// ── 5) Descargas: cada quien lo suyo ──
d = await fetch(B + r.url + '&token=tok-punto1');
ok(d.status === 403, 'el guardia no baja reportes del tablero');
d = await fetch(B + r.url);
ok(d.status === 401, 'sin sesión no se baja');
d = await fetch(B + 'descargar.php?tipo=reporte&f=..%2F..%2Fconfig%2Fconfig.php&token=tok-cliente');
ok(d.status === 404, 'nombre de archivo inventado: 404');
const idReg = sql("SELECT id FROM accesos_registros WHERE codigo <> '' LIMIT 1");
for (const q of [`acceso_foto&id=${idReg}`, 'incidencia_foto&id=1', 'accesos_pdf&f=ACCESOS_2026-01-01-D_TODOS_datos_1.pdf']) {
  d = await fetch(B + 'descargar.php?tipo=' + q + '&token=tok-cliente');
  ok(d.status === 403, 'el cliente no baja ' + q.split('&')[0]);
}

// ── 6) Cuenta de cliente nueva ──
r = await post('CUENTA_CREAR', 'tok-admin', { usuario: 'cliente.planta', nombre: 'Cliente Planta Norte', rol: 'CLIENTE', password: 'contrasena-larga' });
ok(r.status === 'success', 'Administración crea una cuenta de cliente');
r = await post('LOGIN', 'x', { usuario: 'cliente.planta', password: 'contrasena-larga', dispositivo_desc: 'Prueba' });
ok(r.status === 'success' && r.rol === 'CLIENTE', 'el cliente nuevo entra con su contraseña');
const tNuevo = r.token;
r = await get('TABLERO', tNuevo, periodo);
ok(r.status === 'success' && r.kpis.entradas === 6, 'y ve el tablero');
r = await post('CAMBIAR_MI_CONTRASENA', tNuevo, { actual: 'contrasena-larga', nueva: 'otra-contrasena-larga' });
ok(r.status === 'success', 'y puede cambiar su contraseña');
r = await get('CUENTAS_LISTAR', 'tok-admin');
ok(r.roles.CLIENTE && r.cuentas.some(c => c.usuario === 'cliente.planta' && c.rol === 'CLIENTE' && c.turno === ''), 'Cuentas lista al cliente (sin turno)');

console.log(fallas ? fallas + ' FALLAS' : 'TODO BIEN');
process.exit(fallas ? 1 : 0);
