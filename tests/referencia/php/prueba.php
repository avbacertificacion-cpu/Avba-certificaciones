<?php
/* Prueba del control de accesos con tarjeta contra una base MariaDB local de prueba
   (nunca la de producción). Simula una tabla creada antes del cambio. */
declare(strict_types=1);
date_default_timezone_set('America/Mexico_City');
define('UPLOAD_DIR', __DIR__ . '/uploads/');
@mkdir(UPLOAD_DIR, 0755, true);
require __DIR__ . '/../../../api/helpers.php';
require __DIR__ . '/../../../api/ControlAccesos.php';

$fallas = 0;
function ok($c, $m) { global $fallas; if (!$c) $fallas++; echo ($c ? "OK    " : "FALLA ") . $m . "\n"; }

$pdo = new PDO('mysql:host=127.0.0.1;dbname=ca_prueba;charset=utf8mb4', 'prueba', 'prueba', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdo->exec("DROP TABLE IF EXISTS accesos_registros");
// La tabla como está hoy en producción (antes de la tarjeta)
$pdo->exec("CREATE TABLE accesos_registros (
  id INT AUTO_INCREMENT PRIMARY KEY, uid VARCHAR(64) NOT NULL, tipo VARCHAR(10) NOT NULL, fecha DATE NOT NULL,
  capturado DATETIME NOT NULL, recibido DATETIME NOT NULL, lugar VARCHAR(120) NOT NULL DEFAULT '', nota VARCHAR(255) NOT NULL DEFAULT '',
  foto VARCHAR(255) NOT NULL DEFAULT '', foto_mini VARCHAR(255) NOT NULL DEFAULT '', usuario VARCHAR(50) NOT NULL DEFAULT '',
  usuario_nombre VARCHAR(150) NOT NULL DEFAULT '', turno_fecha DATE NULL, turno CHAR(1) NULL,
  UNIQUE KEY uk_uid (uid), KEY idx_fecha (fecha), KEY idx_turno (turno_fecha, turno)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("INSERT INTO accesos_registros (uid,tipo,fecha,capturado,recibido,lugar,foto,foto_mini,usuario,usuario_nombre,turno_fecha,turno)
            VALUES ('fotovieja000001','entrada','2026-11-26','2026-11-26 06:05:00','2026-11-26 06:05:03','QUANTUM ENERGY CASETA 1','fotos/2026/11/x.jpg','','caseta1','CASETA 1','2026-11-26','D')");

$admin = ['usuario' => 'admin', 'nombre' => 'ADMINISTRADOR', 'rol' => 'ADMIN'];
$caseta = ['usuario' => 'punto1', 'nombre' => 'CASETA PUNTO 1', 'rol' => 'ACCESOS'];
$ca = new ControlAccesos($pdo);

// 1) Migración de la tabla vieja
ControlAccesos::asegurarTabla($pdo);
$cols = array_column($pdo->query("SHOW COLUMNS FROM accesos_registros")->fetchAll(), 'Field');
ok(in_array('nombre', $cols) && in_array('codigo', $cols) && in_array('origen', $cols), 'migración: columnas nombre, código y origen');
ok($pdo->query("SELECT origen FROM accesos_registros WHERE uid='fotovieja000001'")->fetchColumn() === 'foto', 'lo anterior queda como «foto»');
$idx = array_column($pdo->query("SHOW INDEX FROM accesos_registros")->fetchAll(), 'Key_name');
ok(in_array('idx_codigo', $idx), 'índice por código');

// 2) La cuenta de caseta registra en el único acceso, sin punto que asignar ni teléfono ligado
$sinPunto = $ca->registrar(['uid' => 'sinp-0000000001', 'tipo' => 'entrada', 'capturado' => '2026-11-26 06:00:00', 'lugar' => 'X', 'codigo' => '1', 'persona' => 'A', 'dispositivo' => 'eq-cualquiera-01'], [], $caseta);
ok($sinPunto['status'] === 'success' && $pdo->query("SELECT lugar FROM accesos_registros WHERE uid='sinp-0000000001'")->fetchColumn() === 'ACCESO', 'registra en «ACCESO» sin asignar nada');
$pdo->exec("DELETE FROM accesos_registros WHERE uid='sinp-0000000001'");

// 3) Registros con tarjeta (sin foto)
$reg = function ($uid, $tipo, $cap, $codigo, $persona, $usr, $origen = 'tarjeta', $lugar = 'Quantum Energy · Caseta 1') use ($ca) {
    return $ca->registrar(['uid' => $uid, 'tipo' => $tipo, 'capturado' => $cap, 'lugar' => $lugar, 'codigo' => $codigo, 'persona' => $persona, 'origen' => $origen, 'dispositivo' => 'eq-prueba-0001'], [], $usr);
};
$r = $reg('tarj-0000000001', 'entrada', '2026-11-26 06:10:12', '004512', 'Juan Pérez López', $caseta);
ok($r['status'] === 'success' && $r['turno'] === '2026-11-26-D', 'entrada con tarjeta sin foto: ' . ($r['message'] ?? json_encode($r)));
$reg('tarj-0000000002', 'entrada', '2026-11-26 06:31:40', '88231', 'Carlos Ruiz Ortega', $caseta);
$reg('tarj-0000000003', 'salida', '2026-11-26 18:22:05', '004512', 'Juan Perez Lopez', $caseta);
$reg('tarj-0000000004', 'salida', '2026-11-26 14:02:00', '7788', 'Luis Flores Díaz', $caseta);
$reg('tarj-0000000005', 'entrada', '2026-11-26 07:00:00', '55120', '', $caseta, 'manual');
$reg('tarj-0000000006', 'salida', '2026-11-26 12:00:00', '55120', '', $caseta, 'manual');
$reg('tarj-0000000007', 'entrada', '2026-11-26 13:00:00', '55120', '', $caseta, 'manual');
$r2 = $reg('tarj-0000000001', 'entrada', '2026-11-26 06:10:12', '004512', 'Juan Pérez López', $caseta);
ok(!empty($r2['duplicado']), 'reenvío del mismo registro no se duplica');
$r3 = $ca->registrar(['uid' => 'nada-0000000001', 'tipo' => 'entrada', 'capturado' => '2026-11-26 08:00:00', 'lugar' => 'X', 'dispositivo' => 'eq-prueba-0001'], [], $caseta);
ok($r3['status'] === 'error' && strpos($r3['message'], 'código de la tarjeta') !== false, 'sin foto ni código se rechaza: ' . $r3['message']);
$fila = $pdo->query("SELECT nombre, codigo, origen, foto, lugar FROM accesos_registros WHERE uid='tarj-0000000001'")->fetch();
ok($fila['nombre'] === 'JUAN PEREZ LOPEZ' && $fila['codigo'] === '004512' && $fila['origen'] === 'tarjeta' && $fila['foto'] === '' && $fila['lugar'] === 'ACCESO', 'guardado en el acceso: ' . json_encode($fila, JSON_UNESCAPED_UNICODE));
ok($pdo->query("SELECT origen FROM accesos_registros WHERE uid='tarj-0000000005'")->fetchColumn() === 'manual', 'código escrito a mano: origen «manual»');
$cod = $ca->registrar(['uid' => 'raro-0000000001', 'tipo' => 'entrada', 'capturado' => '2026-11-26 08:00:00', 'lugar' => 'X', 'codigo' => "12<script>34", 'persona' => 'A', 'dispositivo' => 'eq-prueba-0001'], [], $caseta);
ok($cod['status'] === 'success' && $pdo->query("SELECT codigo FROM accesos_registros WHERE uid='raro-0000000001'")->fetchColumn() === '12SCRIPT34', 'el código sólo guarda letras, números y guiones');
$pdo->exec("DELETE FROM accesos_registros WHERE uid='raro-0000000001'");

// 3) Listar
$l = $ca->listar(['turnos' => '2026-11-26-D', 'limite' => 60], $admin);
$x = array_values(array_filter($l['registros'], fn($r) => $r['uid'] === 'tarj-0000000003'))[0] ?? null;
ok($x && $x['persona'] === 'JUAN PEREZ LOPEZ' && $x['codigo'] === '004512' && $x['con_foto'] === false && $x['origen'] === 'tarjeta', 'listar trae persona, código y si tiene foto');
$v = array_values(array_filter($l['registros'], fn($r) => $r['uid'] === 'fotovieja000001'))[0] ?? null;
ok($v && $v['con_foto'] === true && $v['codigo'] === '', 'el registro con foto sigue igual');
ok($l['totales']['2026-11-26-D']['entrada'] === 5 && $l['totales']['2026-11-26-D']['salida'] === 3, 'totales del turno: ' . json_encode($l['totales']));

// 4) Emparejar
$filas = $pdo->query("SELECT id, uid, tipo, capturado, lugar, nombre, codigo FROM accesos_registros WHERE turno_fecha='2026-11-26' AND turno='D' ORDER BY capturado, id")->fetchAll();
$e = ControlAccesos::emparejar($filas);
$porCod = []; foreach ($e as $r) $porCod[$r['codigo']][] = $r;
ok(count($porCod['004512']) === 1 && $porCod['004512'][0]['entrada'] === '2026-11-26 06:10:12' && $porCod['004512'][0]['salida'] === '2026-11-26 18:22:05' && $porCod['004512'][0]['minutos'] === 732, 'Juan: entrada 06:10, salida 18:22, 12 h 12 min');
ok(count($porCod['88231']) === 1 && $porCod['88231'][0]['salida'] === '', 'Carlos: entrada sin salida');
ok(count($porCod['7788']) === 1 && $porCod['7788'][0]['entrada'] === '', 'Luis: salida sin entrada');
ok(count($porCod['55120']) === 2 && $porCod['55120'][0]['salida'] === '2026-11-26 12:00:00' && $porCod['55120'][1]['salida'] === '', 'código 55120: salió a comer y volvió a entrar (2 renglones)');
ok(!isset($porCod['']), 'el registro con foto sin código no se empareja');

// 5) PDF de datos
$pdf = $ca->generarPdfDatos(['turno' => '2026-11-26-D'], $admin);
ok($pdf['status'] === 'success' && $pdf['personas'] === 4 && $pdf['sin_codigo'] === 1 && $pdf['renglones'] === 5, 'PDF de datos: ' . json_encode(array_intersect_key($pdf, array_flip(['status', 'archivo', 'personas', 'renglones', 'entradas', 'salidas', 'sin_salida', 'sin_entrada', 'sin_codigo', 'paginas', 'message']))));
ok(ControlAccesos::rutaPdf($pdf['archivo'] ?? '') !== null, 'descargar.php reconoce el archivo del PDF de datos');
$pc = $ca->generarPdfDatos(['turno' => '2026-11-26-D'], $caseta);
ok($pc['status'] === 'error', 'la cuenta de caseta no genera el PDF');
$vacio = $ca->generarPdfDatos(['turno' => '2026-11-20-D'], $admin);
ok($vacio['status'] === 'error', 'turno sin registros: ' . $vacio['message']);
@copy(UPLOAD_DIR . 'accesos/pdf/' . $pdf['archivo'], __DIR__ . '/datos.pdf');

// 6) PDF con fotos: sólo los que traen foto
$pf = $ca->generarPdf(['turno' => '2026-11-26-D'], $admin);
ok($pf['status'] === 'success' && $pf['entradas'] === 1 && $pf['salidas'] === 0, 'PDF con fotos sólo con el registro con foto (' . ($pf['message'] ?? $pf['entradas'] . ' entradas') . ')');

echo $fallas ? "$fallas FALLAS\n" : "TODO BIEN\n";
exit($fallas ? 1 : 0);
