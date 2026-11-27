// Servicio médico por la API (base local de prueba, personas inventadas): registrar tomas de
// tensión arterial con los datos de la base, apto / no apto por los límites de la NOM-030, segunda
// toma del día, sin credencial, quién tomó, la cola sin señal (uid), lo que cada perfil puede
// hacer, el tablero (cifras, por día, clasificación, compañías, no aptos), el Excel y el PDF.
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
  const r = await fetch(A, { method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + tok }, body: JSON.stringify({ action: accion, payload }) });
  return Object.assign(await r.json(), { http: r.status });
};
const hoy = new Date().toLocaleDateString('sv-SE', { timeZone: 'America/Mexico_City' });
const hace = min => new Date(Date.now() - min * 60e3).toLocaleString('sv-SE', { timeZone: 'America/Mexico_City' }).replace('T', ' ');
let n = 0;
const toma = (tok, campos) => post('MEDICO_REGISTRAR', tok, { uid: 'med-toma-' + String(++n).padStart(4, '0'), origen: 'tarjeta', ...campos });

// La base del personal (inventada)
await post('PADRON_GUARDAR', 'tok-admin', { codigo: '00287', nombre: 'Ramiro Esteban Villarreal Duarte', empresa: 'Montajes del Golfo', cargo: 'Soldador' });
await post('PADRON_GUARDAR', 'tok-admin', { codigo: '00412', nombre: 'Ana Sofia Ruiz Paez', empresa: 'Montajes del Golfo' });
await post('PADRON_GUARDAR', 'tok-admin', { codigo: '00316', nombre: 'Pedro Vega Cruz', empresa: 'Servicios Industriales del Norte' });

// ── Registrar ──
let r = await toma('tok-medico', { codigo: '287', nombre: 'RAMRO VILARREAL', sistolica: 128, diastolica: 82, frecuencia: 76, capturado: hace(40) });
ok(r.status === 'success' && r.resultado === 'A' && r.categoria === 'NORMAL' && r.toma === 1 && r.nombre === 'RAMIRO ESTEBAN VILLARREAL DUARTE' && r.en_base,
   '128/82: apto, «Normal», con el nombre de la base: ' + r.message);
const f287 = sql("SELECT CONCAT(codigo,'|',empresa,'|',cargo,'|',usuario,'|',usuario_nombre,'|',origen) FROM medico_tomas WHERE clave='287'");
ok(f287 === '00287|MONTAJES DEL GOLFO|SOLDADOR|medico|ENF. LAURA MEDINA|tarjeta', 'guarda compañía y cargo de la base, quién tomó y cómo se identificó: ' + f287);
r = await post('MEDICO_REGISTRAR', 'tok-medico', { uid: 'med-toma-0001', codigo: '287', sistolica: 128, diastolica: 82 });
ok(r.status === 'success' && r.repetida && sql("SELECT COUNT(*) FROM medico_tomas") === '1', 'la misma toma reenviada por la cola no se duplica');

r = await toma('tok-medico', { codigo: '00412', sistolica: 145, diastolica: 92, frecuencia: 104, capturado: hace(35) });
ok(r.status === 'success' && r.resultado === 'N' && r.categoria === 'HTA1' && /NO APTO/.test(r.message), '145/92: NO APTO, «Hipertensión grado 1»: ' + r.message);
r = await toma('tok-medico2', { codigo: '412', sistolica: 134, diastolica: 86, frecuencia: 88, capturado: hace(20), observaciones: 'Reposo de 10 minutos' });
ok(r.status === 'success' && r.resultado === 'A' && r.toma === 2 && r.categoria === 'ALTA' && /2\.ª toma/.test(r.message), 'segunda toma del día: 134/86 apto («Normal alta»), la 2.ª: ' + r.message);
r = await toma('tok-medico', { codigo: '316', sistolica: 95, diastolica: 58, capturado: hace(30) });
ok(r.status === 'success' && r.resultado === 'N' && r.categoria === 'BAJA', '95/58: NO APTO por presión baja (mínimo 100/60)');
r = await toma('tok-medico', { codigo: '00777', nombre: 'Persona Fuera De La Base', empresa: 'Contratista Nuevo', sistolica: 118, diastolica: 76, capturado: hace(15) });
ok(r.status === 'success' && r.resultado === 'A' && r.categoria === 'OPTIMA' && !r.en_base && r.nombre === 'PERSONA FUERA DE LA BASE', 'ID fuera de la base: con el nombre escrito, «Óptima»');
r = await toma('tok-medico2', { nombre: 'Juan Sin Credencial', empresa: 'Visitante', sistolica: 120, diastolica: 80, origen: 'manual', capturado: hace(10) });
ok(r.status === 'success' && r.resultado === 'A', 'sin credencial: sólo con el nombre');

// El límite exacto y el resultado marcado a mano
r = await toma('tok-medico', { codigo: '00501', nombre: 'Luis Mora Diaz', sistolica: 139, diastolica: 89, capturado: hace(9) });
ok(r.resultado === 'A', '139/89 (el máximo permitido): apto');
r = await toma('tok-medico', { codigo: '00502', nombre: 'Maria Lopez', sistolica: 140, diastolica: 80, resultado: 'A', capturado: hace(8) });
ok(r.status === 'error' && /escribe por qué/.test(r.message), 'marcar APTO con 140/80 sin decir por qué: no');
r = await toma('tok-medico', { codigo: '00502', nombre: 'Maria Lopez', sistolica: 140, diastolica: 80, resultado: 'A', observaciones: 'Valorada por el médico: apta', capturado: hace(8) });
ok(r.status === 'success' && r.resultado === 'A' && r.calculado === 'N', 'con la razón en Observaciones, sí (queda que la norma decía no apto)');

// Errores de captura
for (const [d, m] of [[{ codigo: '287', sistolica: 80, diastolica: 120 }, 'la sistólica menor que la diastólica'],
                      [{ codigo: '287', sistolica: 400, diastolica: 80 }, 'fuera de rango'],
                      [{ codigo: '287', sistolica: 120, diastolica: 80, frecuencia: 400 }, 'frecuencia fuera de rango'],
                      [{ sistolica: 120, diastolica: 80 }, 'sin ID ni nombre']]) {
  r = await toma('tok-medico', d);
  ok(r.status === 'error', m + ': ' + r.message);
}
r = await post('MEDICO_REGISTRAR', 'tok-medico', { uid: 'x', codigo: '287', sistolica: 120, diastolica: 80 });
ok(r.status === 'error', 'sin uid válido: no');

// ── Quién puede ──
r = await toma('tok-punto1', { codigo: '287', sistolica: 120, diastolica: 80 });
ok(r.http === 403, 'el guardia no registra tomas');
r = await toma('tok-cliente', { codigo: '287', sistolica: 120, diastolica: 80 });
ok(r.http === 403, 'el cliente tampoco');
r = await toma('tok-planta', { codigo: '287', sistolica: 120, diastolica: 80 });
ok(r.status === 'error' && /servicio médico/.test(r.message), 'ni el administrador de la planta');
r = await toma('tok-admin', { codigo: '316', sistolica: 112, diastolica: 72, capturado: hace(5) });
ok(r.status === 'success' && r.toma === 2, 'Administración sí (para probar o cubrir)');
for (const a of ['LISTAR_ACCESOS', 'ACCESOS_EN_PLANTA', 'TABLERO', 'MEDICO_TABLERO', 'PADRON_LISTAR', 'CUENTAS_LISTAR'])
  ok((await get(a, 'tok-medico')).http === 403, 'el servicio médico no puede ' + a);
ok((await get('PADRON_CAJA', 'tok-medico')).total === 3, 'sí baja la copia de la base (para trabajar sin señal)');
ok((await get('ACCESOS_TARJETA_REGLAS', 'tok-medico')).reglas.digitosId === 5, 'y las reglas del lector de la credencial');
r = await get('MEDICO_REGLAS', 'tok-medico');
ok(r.reglas.maxSistolica === 139 && r.reglas.minDiastolica === 60 && r.categorias.HTA1 === 'Hipertensión grado 1', 'reglas: 139/89 a 100/60 (NOM-030)');
const dsc = await fetch(B + 'descargar.php?tipo=acceso_foto&id=1&token=tok-medico');
ok(dsc.status === 403, 'no descarga fotos ni reportes');

// ── Consultas del servicio médico ──
r = await get('MEDICO_LISTAR', 'tok-medico');
ok(r.status === 'success' && r.fecha === hoy && r.tomas.length === 9 && r.tomas[0].tomo === 'ADMINISTRACION', 'las tomas de hoy de todo el servicio médico (9), la más reciente primero');
ok(r.tomas.some(t => t.tomo === 'DR. HUGO SALAS') && r.tomas.some(t => t.tomo === 'ENF. LAURA MEDINA'), 'cada una con quién la tomó');
r = await get('MEDICO_PERSONA', 'tok-medico', { id: '412' });
ok(r.tomas.length === 2 && r.tomas[0].toma === 2 && r.tomas[1].resultado === 'N', 'las tomas de una persona (00412): 2, la primera no apta');
r = await post('MEDICO_EXISTEN', 'tok-medico', { uids: ['med-toma-0001', 'med-toma-9999'] });
ok(r.existen['med-toma-0001'] && !r.existen['med-toma-9999'], 'la cola confirma qué tomas ya llegaron');

// ── Tablero (cliente) ──
r = await get('MEDICO_TABLERO', 'tok-cliente', { desde: hoy, hasta: hoy });
const k = r.kpis;
ok(r.status === 'success' && k.tomas === 9 && k.evaluados === 7 && k.personas === 7, `cifras: ${k.tomas} tomas, ${k.evaluados} personas evaluadas`);
ok(k.no_aptos === 0 && k.aptos === 7 && k.retomas === 2 && k.recuperados === 2, `cuenta la última toma del día: 0 no aptos, 2 con segunda toma y aptas al final (${k.no_aptos}/${k.retomas}/${k.recuperados})`);
r = await toma('tok-medico2', { codigo: '316', sistolica: 150, diastolica: 95, capturado: hace(2) });
r = await get('MEDICO_TABLERO', 'tok-cliente', { desde: hoy, hasta: hoy });
ok(r.kpis.no_aptos === 1 && r.no_aptos[0].codigo === '00316' && r.no_aptos[0].tomas_dia === 3, 'una 3.ª toma no apta: 00316 queda no apto (3 tomas)');
const cl = Object.fromEntries(r.clasificacion.map(x => [x.categoria, x.n]));
ok(cl.NORMAL === 2 && cl.ALTA === 2 && cl.OPTIMA === 1 && cl.HTA1 === 2 && cl.BAJA === 0, 'clasificación de la última toma (139/89 es «Normal alta»): ' + JSON.stringify(cl));
ok(r.por_empresa[0].empresa === 'SERVICIOS INDUSTRIALES DEL NORTE' && r.por_empresa[0].no_aptos === 1, 'compañías: primero la que tiene no aptos');
ok(r.por_dia.length === 1 && r.por_dia[0].tomas === 10 && r.bitacora.length === 10 && r.bitacora_total === 10, 'por día y la bitácora completa');
ok((await get('MEDICO_TABLERO', 'tok-planta', { desde: hoy, hasta: hoy })).status === 'success', 'el administrador de la planta también lo ve');
ok((await get('MEDICO_TABLERO', 'tok-punto1', { desde: hoy, hasta: hoy })).http === 403, 'el guardia no');

// ── Excel y PDF ──
r = await post('MEDICO_EXCEL', 'tok-cliente', { desde: hoy, hasta: hoy });
ok(r.status === 'success' && /^MEDICO_.*\.xlsx$/.test(r.archivo), 'Excel: ' + r.archivo);
let d = await fetch(B + r.url + '&token=tok-cliente');
const xlsx = Buffer.from(await d.arrayBuffer());
ok(d.status === 200 && xlsx.slice(0, 2).toString() === 'PK', 'el cliente lo descarga');
r = await post('MEDICO_PDF', 'tok-cliente', { desde: hoy, hasta: hoy });
ok(r.status === 'success' && r.paginas >= 2, 'PDF: ' + r.paginas + ' páginas');
d = await fetch(B + r.url + '&token=tok-cliente');
const pdf = Buffer.from(await d.arrayBuffer());
ok(d.status === 200 && pdf.slice(0, 4).toString() === '%PDF', 'el cliente lo descarga');
const txt = execSync('pdftotext -layout - -', { input: pdf }).toString();
ok(/BITÁCORA DE TOMA DE TENSIÓN ARTERIAL/.test(txt) && /Paro de planta 2026/.test(txt) && /No aptos del periodo \(1\)/.test(txt), 'encabezado como el formato y los no aptos');
ok(/RAMIRO ESTEBAN/.test(txt) && /VILLARREAL DUARTE/.test(txt) && /128\/82/.test(txt) && /ENF\. LAURA MEDINA/.test(txt), 'la bitácora: nombre, tensión y quién tomó');
ok(/máximos permitidos 139\/89 mmHg · mínimos 100\/60 mmHg \(NOM-030-SSA2-2009\)/.test(txt) && /Página 2 de 2/.test(txt), 'al pie: los límites completos y la página');

// ── Días omitidos: tampoco cuentan aquí ──
await post('OMITIR', 'tok-admin', { fecha: hoy, aplicar: true }).catch(() => null);
r = await get('MEDICO_TABLERO', 'tok-cliente', { desde: hoy, hasta: hoy });
ok(r.kpis.tomas === 0 || sql('SELECT COUNT(*) FROM accesos_omitidos') === '0', 'un periodo omitido tampoco cuenta en el servicio médico');

// ── La cuenta ──
r = await post('CUENTA_CREAR', 'tok-admin', { usuario: 'enfermera.noche', nombre: 'Enf. Noche', rol: 'MEDICO', password: 'contrasena-larga' });
ok(r.status === 'success', 'Administración crea cuentas de servicio médico');
r = await post('LOGIN', null, { usuario: 'enfermera.noche', password: 'contrasena-larga' });
ok(r.status === 'success' && r.rol === 'MEDICO', 'y entran con su perfil');
r = await get('SESION', 'tok-medico');
ok(r.rol === 'MEDICO' && r.nombre_rol === 'Servicio médico', 'SESION: ' + r.nombre_rol);

console.log(fallas ? fallas + ' FALLAS' : 'TODO BIEN');
process.exit(fallas ? 1 : 0);
