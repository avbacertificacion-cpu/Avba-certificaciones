// Base del personal y permisos por la API (base local de prueba; personas e IDs inventados):
// quién puede qué, agregar / cambiar / quitar IDs, cargar un Excel (títulos distintos, nombre y
// apellidos separados, IDs como número, renglones con problema, columnas elegidas a mano, CSV),
// permisos desde Administración y desde la caseta (con foto, sin duplicar), la copia para la
// caseta (versión), el nombre de la base al registrar, IDs leídos que faltan, fotos y Excel.
import { execSync } from 'node:child_process';
import fs from 'node:fs';
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
const form = async (tok, campos, archivos = {}) => {
  const fd = new FormData();
  for (const [k, v] of Object.entries(campos)) fd.append(k, v);
  for (const [k, [buf, nombre, tipo]] of Object.entries(archivos)) fd.append(k, new Blob([buf], { type: tipo }), nombre);
  const r = await fetch(A, { method: 'POST', headers: { Authorization: 'Bearer ' + tok }, body: fd });
  return Object.assign(await r.json(), { http: r.status });
};
// Una foto JPEG chica (la hace Python con Pillow)
const jpg = execSync(`python3 -c "
import io,sys; from PIL import Image
b=io.BytesIO(); Image.new('RGB',(640,480),(200,170,40)).save(b,'JPEG'); sys.stdout.buffer.write(b.getvalue())"`);

// ── 1) Quién puede qué ──
let r = await get('PADRON_LISTAR', 'tok-cliente');
ok(r.http === 403, 'el cliente no ve la base');
r = await get('PADRON_LISTAR', 'tok-punto1');
ok(r.http === 403, 'el guardia no ve la lista de la base');
r = await post('PADRON_GUARDAR', 'tok-punto1', { codigo: '1', nombre: 'X' });
ok(r.http === 403, 'el guardia no agrega personas');
r = await get('PADRON_CAJA', 'tok-cliente');
ok(r.http === 403, 'el cliente no recibe la copia de la caseta');
r = await get('PADRON_CAJA', 'tok-punto1');
ok(r.status === 'success' && r.total === 0, 'el guardia sí recibe la copia (vacía todavía)');
r = await get('PADRON_LISTAR', 'tok-planta');
ok(r.status === 'success' && r.total_base === 0, 'el administrador de la planta maneja la base');

// ── 2) Agregar, cambiar y quitar ──
r = await post('PADRON_GUARDAR', 'tok-admin', { codigo: '287', nombre: 'Ramiro Esteban Villarreal Duarte', empresa: 'Montajes del Golfo', cargo: 'Soldador' });
ok(r.status === 'success' && r.codigo === '00287', 'agregar: el ID corto queda con sus ceros (00287): ' + r.message);
const idRamiro = r.id;
r = await post('PADRON_GUARDAR', 'tok-admin', { codigo: '00287', nombre: 'Otra Persona' });
ok(r.status === 'error' && /ya es de RAMIRO/.test(r.message), 'el mismo ID (con o sin ceros) no se repite: ' + r.message);
r = await post('PADRON_GUARDAR', 'tok-admin', { codigo: '', nombre: 'Sin ID' });
ok(r.status === 'error', 'sin ID no se agrega');
r = await post('PADRON_GUARDAR', 'tok-admin', { codigo: '316', nombre: '' });
ok(r.status === 'error', 'sin nombre no se agrega');
r = await post('PADRON_GUARDAR', 'tok-planta', { codigo: '412', nombre: 'Ana Sofía Ruiz Páez' });
ok(r.status === 'success' && sql("SELECT nombre FROM personal WHERE clave='412'") === 'ANA SOFIA RUIZ PAEZ', 'planta agrega; el nombre queda en mayúsculas y sin acentos');
const idAna = r.id;
r = await get('PADRON_LISTAR', 'tok-admin', { q: 'ruiz' });
ok(r.total === 1 && r.personas[0].codigo === '00412', 'buscar por nombre');
r = await get('PADRON_LISTAR', 'tok-admin', { q: '287' });
ok(r.total === 1 && r.personas[0].nombre === 'RAMIRO ESTEBAN VILLARREAL DUARTE', 'buscar por ID sin ceros');
r = await get('PADRON_LISTAR', 'tok-admin', { q: 'golfo soldador' });
ok(r.total === 1, 'buscar por empresa y cargo');

// ── 3) Permisos desde Administración ──
r = await post('PERMISO_AGREGAR', 'tok-admin', { codigo: '287', tipos: ['CELULAR', 'COMPUTADORA'], detalle: 'Celular Samsung A54; laptop Dell 5420', vigencia: '2099-12-31' });
ok(r.status === 'success' && /guardados/.test(r.message), 'Administración: permiso de celular y computadora con los datos del equipo: ' + r.message);
const idPermCel = r.id;
r = await post('PERMISO_AGREGAR', 'tok-admin', { codigo: '287', tipos: ['HERRAMIENTA'] });
ok(r.status === 'error' && /foto del pase o escribe/.test(r.message), 'sin foto ni datos no se guarda');
r = await post('PERMISO_AGREGAR', 'tok-admin', { codigo: '287', tipos: [] });
ok(r.status === 'error' && /Marca qué permiso/.test(r.message), 'sin tipo no se guarda');
r = await post('PERMISO_AGREGAR', 'tok-admin', { codigo: '287', tipos: ['OTRO'] });
ok(r.status === 'error' && /Otro permiso/.test(r.message), '«Otro» pide de qué es');
r = await post('PERMISO_AGREGAR', 'tok-admin', { codigo: '412', tipos: ['HERRAMIENTA'], detalle: 'Juego de llaves', vigencia: '2020-01-31' });
ok(r.status === 'success', 'permiso con vigencia ya pasada');
r = await form('tok-admin', { action: 'PERMISO_AGREGAR', codigo: '00412', tipos: 'CELULAR', uid: 'adm-prueba-0001' }, { foto: [jpg, 'pase.jpg', 'image/jpeg'] });
ok(r.status === 'success', 'Administración: sólo con la foto del pase');
const idPermFoto = r.id;

// ── 4) Permisos desde la caseta (con la foto del pase; misma cola sin señal) ──
r = await form('tok-punto1', { action: 'PERMISO_AGREGAR', codigo: '287', tipos: 'HERRAMIENTA', uid: 'caseta-perm-0001' });
ok(r.status === 'error' && /Toma la foto/.test(r.message), 'el guardia necesita la foto del pase');
r = await form('tok-punto1', { action: 'PERMISO_AGREGAR', codigo: '287', tipos: 'HERRAMIENTA', detalle: 'Pulidora y extensión', uid: 'caseta-perm-0001' }, { foto: [jpg, 'p.jpg', 'image/jpeg'] });
ok(r.status === 'success', 'el guardia agrega un resguardo de herramienta con foto');
const idPermCaseta = r.id;
r = await form('tok-punto1', { action: 'PERMISO_AGREGAR', codigo: '287', tipos: 'HERRAMIENTA', uid: 'caseta-perm-0001' }, { foto: [jpg, 'p.jpg', 'image/jpeg'] });
ok(r.status === 'success' && r.duplicado && r.id === idPermCaseta, 'reenvío de la cola: no se duplica');
r = await post('ACCESOS_EXISTEN', 'tok-punto1', { uids: ['caseta-perm-0001', 'no-existe-0001'] });
ok(r.existen['caseta-perm-0001'] === idPermCaseta && !r.existen['no-existe-0001'], 'la cola confirma el permiso como cualquier registro');
r = await form('tok-punto1', { action: 'PERMISO_AGREGAR', codigo: '00999', tipos: 'CELULAR', uid: 'caseta-perm-0002' }, { foto: [jpg, 'p.jpg', 'image/jpeg'] });
ok(r.status === 'success', 'el guardia puede dar un permiso a un ID que todavía no está en la base');
r = await form('tok-punto1', { action: 'PERMISO_AGREGAR', codigo: '287', tipos: 'CELULAR', uid: 'caseta-perm-0003' }, { foto: [Buffer.from('no es foto'), 'p.jpg', 'image/jpeg'] });
ok(r.status === 'error' && /no es una foto/.test(r.message), 'un archivo que no es foto se rechaza');

// ── 5) La copia para la caseta ──
r = await get('PADRON_CAJA', 'tok-punto1');
const v1 = r.version;
ok(r.status === 'success' && r.total === 2 && r.personas['287'][0] === '00287' && r.personas['412'][1] === 'ANA SOFIA RUIZ PAEZ', 'la copia trae IDs y nombres');
const p287 = r.permisos['287'] || [];
ok(p287.length === 2 && p287.some(x => x.tipos.join() === 'CELULAR,COMPUTADORA' && x.detalle) && p287.some(x => x.tipos.join() === 'HERRAMIENTA' && x.con_foto && x.origen === 'caseta'),
   'y los permisos de cada ID (los de Administración y los de la caseta)');
ok((r.permisos['412'] || []).some(x => x.vencido), 'el permiso con vigencia pasada viene marcado como vencido');
ok(p287.find(x => x.id === idPermCaseta).puede_quitar === true && p287.find(x => x.id === idPermCel).puede_quitar === false, 'el guardia puede quitar sólo lo que él agregó');
r = await get('PADRON_CAJA', 'tok-punto1', { version: v1 });
ok(r.sin_cambios === true && !r.personas, 'con la misma versión no se manda todo otra vez');

// ── 6) Quitar permisos ──
r = await post('PERMISO_QUITAR', 'tok-punto1', { id: idPermCel });
ok(r.status === 'error', 'el guardia no quita permisos de Administración');
r = await post('PERMISO_QUITAR', 'tok-punto1', { id: idPermCaseta });
ok(r.status === 'success', 'el guardia deshace el que acaba de agregar');
r = await get('PADRON_CAJA', 'tok-punto1', { version: v1 });
ok(!r.sin_cambios && r.version !== v1 && (r.permisos['287'] || []).length === 1, 'la versión cambió y la copia ya no trae el permiso quitado');
r = await get('PADRON_FICHA', 'tok-admin', { codigo: '00287' });
ok(r.permisos.length === 2 && r.permisos.some(x => !x.activo && x.quitado_por === 'CASETA PUNTO 1'), 'el permiso quitado queda en el historial con quién lo quitó');

// ── 7) Cambiar el ID se lleva sus permisos; quitar a la persona da de baja sus permisos ──
r = await post('PADRON_GUARDAR', 'tok-admin', { id: idRamiro, codigo: '2870', nombre: 'Ramiro Esteban Villarreal Duarte' });
ok(r.status === 'success' && /cambió de 00287 a 02870/.test(r.message), 'cambiar el ID: ' + r.message);
ok(sql("SELECT COUNT(*) FROM personal_permisos WHERE clave='2870'") === '2' && sql("SELECT COUNT(*) FROM personal_permisos WHERE clave='287'") === '0', 'sus permisos pasan al ID nuevo');
r = await post('PADRON_ELIMINAR', 'tok-admin', { id: idAna });
ok(r.status === 'success' && /y sus permisos/.test(r.message), 'quitar a una persona: ' + r.message);
ok(sql("SELECT COUNT(*) FROM personal_permisos WHERE clave='412' AND activo=1") === '0', 'sus permisos quedan dados de baja');

// ── 8) El nombre de la base al registrar ──
r = await form('tok-punto1', { action: 'ACCESO_REGISTRAR', uid: 'reg-base-0001', tipo: 'entrada', codigo: '02870', persona: 'RAMRO ESTEBAN VILARREAL', origen: 'tarjeta' });
ok(r.status === 'success' && sql("SELECT nombre FROM accesos_registros WHERE uid='reg-base-0001'") === 'RAMIRO ESTEBAN VILLARREAL DUARTE', 'la entrada guarda el nombre de la base, no el que leyó la cámara');
r = await form('tok-punto1', { action: 'ACCESO_REGISTRAR', uid: 'reg-base-0002', tipo: 'entrada', codigo: '00555', persona: 'PERSONA NO REGISTRADA', origen: 'tarjeta' });
ok(sql("SELECT nombre FROM accesos_registros WHERE uid='reg-base-0002'") === 'PERSONA NO REGISTRADA', 'un ID fuera de la base se registra con lo que se leyó');
r = await form('tok-punto1', { action: 'ACCESO_INCIDENCIA', uid: 'inc-base-0001', incidencia: 'CONDUCTA', codigo: '2870', persona: 'MAL LEIDO' });
ok(r.status === 'success' && sql("SELECT nombre FROM accesos_incidencias WHERE uid='inc-base-0001'") === 'RAMIRO ESTEBAN VILLARREAL DUARTE', 'la incidencia también usa el nombre de la base');
r = await get('PADRON_FALTANTES', 'tok-admin');
ok(r.ids.length === 1 && r.ids[0].codigo === '00555' && r.ids[0].nombre === 'PERSONA NO REGISTRADA', 'IDs leídos que no están en la base: ' + r.ids.map(x => x.codigo).join(', '));

// ── 9) Cargar un Excel ──
execSync(`python3 - "${D}personal_prueba.xlsx" "${D}personal_prueba.csv" <<'EOF'
import sys, openpyxl
wb = openpyxl.Workbook(); ws = wb.active; ws.title = 'Paro'
ws.append(['LISTADO DE PERSONAL CONTRATISTA'])
ws.append([])
ws.append(['No. Credencial', 'Nombre(s)', 'Apellido Paterno', 'Apellido Materno', 'Compañía', 'Puesto', 'NSS'])
ws.append([2870, 'Ramiro Esteban', 'Villarreal', 'Duarte', 'Montajes del Golfo', 'Soldador', 12345678901])
ws.append(['00316', 'Pedro', 'Vega', 'Cruz', 'Montajes del Golfo', 'Pailero', 12345678902])
ws.append([500, 'Luis', 'Mora', 'Díaz', 'Grúas del Norte', 'Operador', None])
ws.append([None, 'Sin', 'Credencial', '', None, None, None])
ws.append([733, None, None, None, 'X', None, None])
ws.append([500, 'Luis Alberto', 'Mora', 'Díaz', 'Grúas del Norte', 'Operador', None])
ws.append(['JUAN PEREZ', 'Juan', 'Pérez', 'Luna', 'Grúas del Norte', 'Operador', None])
ws.append([777, 'Ana', 'Ruiz', 'Paez', 'Montajes del Golfo', 'Ayudante', None])
ws.append(['00777', 'Hugo', 'Salas', 'Tovar', 'Grúas del Norte', 'Operador', None])
wb.save(sys.argv[1])
open(sys.argv[2], 'w', encoding='cp1252').write('Columna A;Columna B;Columna C\\n00601;MARÍA FERNANDA RÍOS;Grúas del Norte\\n602;JOSUÉ IBARRA GARZA;\\n')
EOF`);
const xlsx = fs.readFileSync(D + 'personal_prueba.xlsx'), csv = fs.readFileSync(D + 'personal_prueba.csv');
r = await form('tok-admin', { action: 'PADRON_IMPORTAR', modo: 'revisar' }, { archivo: [xlsx, 'personal.xlsx', 'application/octet-stream'] });
ok(r.status === 'success' && r.renglon_titulos === 3, 'encuentra los títulos en el renglón 3 (después de un encabezado)');
ok(r.mapa.id === 0 && r.mapa.nombre === 1 && r.mapa.paterno === 2 && r.mapa.materno === 3 && r.mapa.empresa === 4 && r.mapa.cargo === 5, 'reconoce las columnas (y no confunde el NSS con el ID)');
ok(r.renglones === 3 && r.repetidos === 1 && r.cuenta.nuevos === 2 && r.cuenta.cambian === 1, `3 personas (1 repetida): ${r.cuenta.nuevos} nuevas, ${r.cuenta.cambian} con cambios`);
ok(r.total_problemas === 5 && r.problemas.map(x => x.renglon + ' ' + x.motivo).join(', ') ===
   '7 Sin ID, 8 Sin nombre, 10 El ID no es un número, 11 ID 00777 repetido con otra persona (renglón 12), 12 ID 00777 repetido con otra persona (renglón 11)',
   'renglones con problema, con su número de renglón de Excel (un nombre en la columna del ID; el mismo ID con dos personas distintas no se carga): ' + r.problemas.map(x => x.renglon + ' ' + x.motivo).join(', '));
ok(r.cambios[0].codigo === '02870' && /Empresa: \(vacío\) → MONTAJES DEL GOLFO/.test(r.cambios[0].que), 'dice qué cambia: ' + r.cambios[0].que);
ok(r.muestra.some(x => x.codigo === '00500' && x.nombre === 'LUIS ALBERTO MORA DIAZ'), 'nombre y apellidos juntos; el ID 500 con sus ceros; el repetido: queda el último');
ok(sql('SELECT COUNT(*) FROM personal') === '1', 'revisar no cambia nada');
r = await form('tok-admin', { action: 'PADRON_IMPORTAR', modo: 'aplicar' }, { archivo: [xlsx, 'personal.xlsx', 'application/octet-stream'] });
ok(r.status === 'success' && r.aplicado && sql('SELECT COUNT(*) FROM personal') === '3', 'cargar a la base: ' + r.message);
ok(sql("SELECT CONCAT(codigo,'|',nombre,'|',empresa,'|',cargo) FROM personal WHERE clave='316'") === '00316|PEDRO VEGA CRUZ|MONTAJES DEL GOLFO|PAILERO', 'quedan ID, nombre, empresa y cargo');
r = await form('tok-admin', { action: 'PADRON_IMPORTAR', modo: 'revisar' }, { archivo: [xlsx, 'personal.xlsx', 'application/octet-stream'] });
ok(r.cuenta.iguales === 3 && r.cuenta.nuevos === 0 && r.cuenta.cambian === 0, 'el mismo Excel otra vez: todos sin cambios');
r = await form('tok-admin', { action: 'PADRON_IMPORTAR', modo: 'revisar' }, { archivo: [csv, 'personal.csv', 'text/csv'] });
ok(r.status === 'error' && r.necesita_columnas && r.titulos.length === 3, 'CSV con títulos que no reconoce: pide elegir las columnas');
r = await form('tok-admin', { action: 'PADRON_IMPORTAR', modo: 'aplicar', mapa: JSON.stringify({ id: 0, nombre: 1, empresa: 2 }) }, { archivo: [csv, 'personal.csv', 'text/csv'] });
ok(r.status === 'success' && r.aplicado && sql("SELECT nombre FROM personal WHERE clave='601'") === 'MARIA FERNANDA RIOS' && sql("SELECT codigo FROM personal WHERE clave='602'") === '00602',
   'CSV de Excel en Windows (acentos) con las columnas elegidas a mano');
r = await form('tok-admin', { action: 'PADRON_IMPORTAR', modo: 'revisar' }, { archivo: [Buffer.from('nada'), 'viejo.xls', 'application/vnd.ms-excel'] });
ok(r.status === 'error' && /\.xls/.test(r.message), 'Excel antiguo (.xls): pide guardarlo como .xlsx');
r = await form('tok-planta', { action: 'PADRON_IMPORTAR', modo: 'revisar' }, { archivo: [xlsx, 'personal.xlsx', 'application/octet-stream'] });
ok(r.status === 'success', 'planta también puede cargar el Excel');
r = await form('tok-punto1', { action: 'PADRON_IMPORTAR', modo: 'aplicar' }, { archivo: [xlsx, 'personal.xlsx', 'application/octet-stream'] });
ok(r.http === 403, 'el guardia no carga Excel');

// ── 10) Fotos y Excel de la base ──
let d = await fetch(B + `descargar.php?tipo=permiso_foto&id=${idPermFoto}&m=1&token=tok-punto1`);
ok(d.status === 200 && /image\/jpeg/.test(d.headers.get('content-type')), 'el guardia ve la foto del pase');
d = await fetch(B + `descargar.php?tipo=permiso_foto&id=${idPermFoto}&token=tok-cliente`);
ok(d.status === 403, 'el cliente no ve fotos de pases');
d = await fetch(B + `descargar.php?tipo=permiso_foto&id=${idPermFoto}`);
ok(d.status === 401, 'sin sesión no se ve');
r = await post('PADRON_EXCEL', 'tok-admin');
ok(r.status === 'success' && r.personas === 5, 'Excel de la base: ' + r.archivo);
d = await fetch(B + r.url + '&token=tok-admin');
const xb = Buffer.from(await d.arrayBuffer());
fs.writeFileSync(D + 'personal_descarga.xlsx', xb);
const hojas = JSON.parse(execSync(`python3 -c "
import json,openpyxl; wb=openpyxl.load_workbook('${D}personal_descarga.xlsx')
p=wb['Personal']; print(json.dumps({'hojas':wb.sheetnames,'ids':[r[0] for r in p.iter_rows(min_row=5,values_only=True)],'permisos':wb['Permisos'].max_row-4}))"`).toString());
ok(hojas.hojas.join() === 'Personal,Permisos' && hojas.ids.length === 5 && hojas.ids.includes('00500') && hojas.permisos >= 2, 'el Excel trae la base (ID con ceros, como texto) y los permisos: ' + hojas.ids.join(', '));
d = await fetch(B + r.url + '&token=tok-punto1');
ok(d.status === 403, 'el guardia no baja el Excel de la base');
d = await fetch(B + r.url + '&token=tok-cliente');
ok(d.status === 403, 'el cliente tampoco');

console.log(fallas ? fallas + ' FALLAS' : 'TODO BIEN');
process.exit(fallas ? 1 : 0);
