// Cargar permisos desde Excel (lista de equipos autorizados): un renglón por equipo con el ID, el nombre, tipo,
// marca, modelo y serie. Se revisa antes de guardar; el nombre debe ser del ID; no se repite un equipo que la
// persona ya tiene. Base local de prueba; personas, equipos y series inventados.
import { execSync } from 'node:child_process';
import { writeFileSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
const A = 'http://localhost:8794/api/index.php';
let fallas = 0;
const ok = (c, m) => { if (!c) fallas++; console.log((c ? 'OK    ' : 'FALLA ') + m); };
const sql = q => execSync(`mariadb --default-character-set=utf8mb4 -N -B -uprueba -pprueba -h127.0.0.1 ca_prueba -e "${q}"`).toString().trim();
const dir = mkdtempSync(tmpdir() + '/permisos-');
const subir = async (tok, modo, texto) => {
  writeFileSync(dir + '/lista.csv', texto);
  const fd = new FormData();
  fd.append('action', 'PERMISOS_IMPORTAR'); fd.append('modo', modo);
  fd.append('archivo', new Blob([texto], { type: 'text/csv' }), 'lista.csv');
  const r = await fetch(A, { method: 'POST', headers: { Authorization: 'Bearer ' + tok }, body: fd });
  return Object.assign(await r.json(), { http: r.status });
};
const LISTA = [
  'Registro de ingreso de equipos electrónicos (lista autorizada)',
  'ID,Nombre completo,Empresa,Tipo de equipo,Marca,Modelo,Número de serie,Vigencia,Referencia',
  '00287,Ramiro Villarreal Duarte,MONTAJES DEL NORTE,Celular,Apple,iPhone 15,SERIE0001AA,,Folio PRUEBA-001',
  '287,Ramiro Villarreal Duarte,MONTAJES DEL NORTE,Laptop,Dell,Latitude 5450,SERIE0002BB,31/12/2026,Folio PRUEBA-001',
  '00287,Ramiro Villarreal Duarte,MONTAJES DEL NORTE,Pantalla portatil,Generico,S/n,S/n,,Folio PRUEBA-001',
  '00287,Ramiro Villarreal Duarte,MONTAJES DEL NORTE,Celular,Apple,iPhone 15,SERIE0001AA,,Folio PRUEBA-001',   // repetido en la lista
  '00412,Ana Sofia Ruiz Paez,GRUAS DEL GOLFO,Teléfono celular,Samsung,S24,SERIE0003CC,,',
  '00412,Pedro Vega Cruz,GRUAS DEL GOLFO,Laptop,HP,15,SERIE0004DD,,',                                           // el nombre no es el del ID
  '99999,Persona Que No Esta,GRUAS DEL GOLFO,Celular,Xiaomi,14,SERIE0005EE,,',                                 // no está en la base
  ',Sin Credencial Inventada,GRUAS DEL GOLFO,Celular,Xiaomi,14,SERIE0006FF,,',                                 // sin ID
  '00316,Pedro Vega Cruz,GRUAS DEL GOLFO,Tablet,Apple,iPad,SERIE0007GG,fecha mala,',                           // vigencia no válida
].join('\n');

(async () => {
  sql("DELETE FROM personal; DELETE FROM personal_permisos");
  sql(`INSERT INTO personal (clave,codigo,nombre,empresa,cargo,creado,actualizado,actualizado_por) VALUES
       ('287','00287','RAMIRO ESTEBAN VILLARREAL DUARTE','MONTAJES DEL NORTE','SOLDADOR',NOW(),NOW(),'prueba'),
       ('412','00412','ANA SOFIA RUIZ PAEZ','GRUAS DEL GOLFO','MANIOBRISTA',NOW(),NOW(),'prueba'),
       ('316','00316','PEDRO VEGA CRUZ','GRUAS DEL GOLFO','OPERADOR',NOW(),NOW(),'prueba')`);

  let r = await subir('tok-punto1', 'revisar', LISTA);
  ok(r.http === 403 || r.status === 'error', 'la caseta no carga listas de permisos');
  r = await subir('tok-admin', 'revisar', LISTA);
  ok(r.status === 'success' && r.permisos === 4 && r.personas === 2 && r.repetidos === 1, `revisión: 4 permisos para 2 personas y 1 repetido en la lista → ${r.permisos} · ${r.personas} · ${r.repetidos}`);
  const mot = (r.problemas || []).map(x => x.motivo).join(' | ');
  ok(r.total_problemas === 4 && /no coincide con el del ID 00412/.test(mot) && /99999 no está en la base/.test(mot) && /Sin ID/.test(mot) && /vigencia no es una fecha/.test(mot),
     'renglones con problema: nombre de otra persona, ID fuera de la base, sin ID y vigencia mala: ' + mot);
  ok(sql('SELECT COUNT(*) FROM personal_permisos') === '0', 'revisar no guarda nada');
  const m = Object.fromEntries((r.muestra || []).map(x => [x.detalle.split(' · ')[1], x]));
  ok(m['Serie SERIE0001AA'].tipo === 'CELULAR' && m['Serie SERIE0002BB'].tipo === 'COMPUTADORA' && m['Sin número de serie'].tipo === 'OTRO' && m['Serie SERIE0003CC'].tipo === 'CELULAR',
     'celular, laptop (computadora), pantalla (otro) y teléfono celular');
  ok(m['Serie SERIE0002BB'].vigencia === '2026-12-31' && m['Serie SERIE0001AA'].vigencia === null, 'vigencia 31/12/2026; sin vigencia, sin vencimiento');
  ok(m['Serie SERIE0001AA'].detalle === 'Celular Apple iPhone 15 · Serie SERIE0001AA · Folio PRUEBA-001' && m['Serie SERIE0001AA'].codigo === '00287' && m['Serie SERIE0002BB'].codigo === '00287',
     'el detalle lleva equipo, serie y referencia; «287» es el 00287: ' + m['Serie SERIE0001AA'].detalle);

  r = await subir('tok-admin', 'aplicar', LISTA);
  ok(r.status === 'success' && r.aplicado && sql("SELECT COUNT(*) FROM personal_permisos WHERE activo=1 AND origen='excel'") === '4', 'se guardan los 4: ' + r.message);
  const caja = await (await fetch(A + '?action=PADRON_CAJA', { headers: { Authorization: 'Bearer tok-punto1' } })).json();
  ok((caja.permisos['287'] || []).length === 3 && (caja.permisos['412'] || []).length === 1, 'el teléfono de la caseta los recibe en su copia de la base');
  r = await subir('tok-admin', 'revisar', LISTA);
  ok(r.permisos === 0 && r.ya_tienen === 4, 'la misma lista otra vez: nada nuevo (ya los tenían) → ' + r.ya_tienen);
  r = await subir('tok-admin', 'revisar', 'Nombre,Marca\nRamiro,Apple');
  ok(r.status === 'error' && /columnas/.test(r.message), 'sin las columnas: lo dice: ' + r.message);

  sql("DELETE FROM personal; DELETE FROM personal_permisos");
  console.log(fallas ? fallas + ' FALLAS' : 'TODO BIEN');
  process.exit(fallas ? 1 : 0);
})().catch(e => { console.error('FALLO', e); process.exit(1); });
