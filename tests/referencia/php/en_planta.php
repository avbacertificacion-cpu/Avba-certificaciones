<?php
/* En planta, ID como llave, movimientos y dar salida: base MariaDB local de prueba (nunca producción). */
declare(strict_types=1);
date_default_timezone_set('America/Mexico_City');
define('UPLOAD_DIR', __DIR__ . '/uploads/');
// Hora fija (sólo en pruebas): las 21:00, así todas las entradas de abajo son después del reinicio de las 05:30
define('MODO_PRUEBAS', true);
$_SERVER['HTTP_X_PRUEBA_AHORA'] = '2026-11-26 21:00:00';
require __DIR__ . '/../../../api/helpers.php';
require __DIR__ . '/../../../api/ControlAccesos.php';
$fallas = 0;
function ok($c, $m) { global $fallas; if (!$c) $fallas++; echo ($c ? "OK    " : "FALLA ") . $m . "\n"; }
$pdo = new PDO('mysql:host=127.0.0.1;dbname=ca_prueba;charset=utf8mb4', 'prueba', 'prueba', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
ControlAccesos::asegurarTabla($pdo);
$pdo->exec("DELETE FROM accesos_registros");
$admin = ['usuario' => 'admin', 'nombre' => 'ADMINISTRADOR', 'rol' => 'ADMIN'];
$calidad = ['usuario' => 'cal', 'nombre' => 'CALIDAD', 'rol' => 'CALIDAD'];
$caseta = ['usuario' => 'punto1', 'nombre' => 'CASETA PUNTO 1', 'rol' => 'ACCESOS'];
$ca = new ControlAccesos($pdo);
$hace = fn(int $min) => date('Y-m-d H:i:s', ControlAccesos::ahora() - $min * 60);
$n = 0;
$mete = function (string $tipo, int $minAtras, string $cod, string $nom, string $lugar) use ($pdo, $hace, &$n) {
    $n++; $cap = $hace($minAtras); [$tf, $t] = ControlAccesos::turnoDe($tipo, $cap);
    $pdo->prepare("INSERT INTO accesos_registros (uid,tipo,fecha,capturado,recibido,lugar,usuario,usuario_nombre,turno_fecha,turno,nombre,codigo,origen)
                   VALUES (?,?,?,?,NOW(),?,'punto1','CASETA PUNTO 1',?,?,?,?,'tarjeta')")
        ->execute(['t' . str_pad((string)$n, 14, '0', STR_PAD_LEFT), $tipo, substr($cap, 0, 10), $cap, $lugar, $tf, $t, $nom, $cod]);
};
// 00287: entró por el punto 1 hace 5 h (y una lectura parcial del nombre)
$mete('entrada', 300, '00287', 'RAMIRO ESTEBAN', 'PUNTO DE CONTROL 1');
// 00412: entró por el 2 hace 6 h y salió por el 3 hace 1 h → ya no está
$mete('entrada', 360, '00412', 'ANA SOFIA RUIZ PAEZ', 'PUNTO DE CONTROL 2');
$mete('salida', 60, '00412', 'ANA SOFIA RUIZ PAEZ', 'PUNTO DE CONTROL 3');
// 00316 leído y 161 escrito a mano: la misma persona. Entró, salió y volvió a entrar
$mete('entrada', 600, '00316', 'PEDRO VEGA CRUZ', 'PUNTO DE CONTROL 1');
$mete('salida', 400, '316', '', 'PUNTO DE CONTROL 1');
$mete('entrada', 30, '00316', 'PEDRO VEGA CRUZ', 'PUNTO DE CONTROL 2');
// 00500: entró hace 15 h (06:00, después del reinicio) y no registró salida → aparece, marcado para revisar
$mete('entrada', 900, '00500', 'LUIS MORA DIAZ', 'PUNTO DE CONTROL 4');
// 00600: entró hace 3 días → fuera de la ventana de 48 h
$mete('entrada', 3 * 24 * 60, '00600', 'JUAN SOTO RIOS', 'PUNTO DE CONTROL 1');
// la salida de 00287 escrita sin ceros (287) hace 2 días, antes de su entrada de hoy: no cuenta
$mete('salida', 2 * 24 * 60 - 10, '287', '', 'PUNTO DE CONTROL 1');
// segunda lectura del 00287 con el nombre completo
$pdo->exec("UPDATE accesos_registros SET nombre='RAMIRO ESTEBAN VILLARREAL DUARTE' WHERE codigo='00287' AND tipo='entrada'");
$mete('entrada', 299, '00287', 'RAMIRO ESTEBAN', 'PUNTO DE CONTROL 1');   // doble lectura de la misma entrada

ok(ControlAccesos::claveId('00316') === '316' && ControlAccesos::claveId('316') === '316' && ControlAccesos::claveId('000') === '0' && ControlAccesos::claveId('qe-0012') === 'QE-0012', 'claveId: sin ceros a la izquierda');

$r = $ca->enPlanta([], $admin);
$ids = array_column($r['personas'], 'id');
ok($r['status'] === 'success', 'en planta responde');
ok(in_array('00287', $ids) && in_array('00316', $ids) && in_array('00500', $ids), 'en planta: 00287, 00316 (volvió a entrar) y 00500 → ' . implode(', ', $ids));
ok(!in_array('00412', $ids), 'el que ya salió (aunque por otro punto) NO aparece');
ok(!in_array('00600', $ids), 'una entrada de hace 3 días queda fuera de la ventana');
ok(count($ids) === 3 && $r['total'] === 3, 'una sola vez cada persona: ' . $r['total']);
$p287 = array_values(array_filter($r['personas'], fn($x) => $x['id'] === '00287'))[0];
ok($p287['nombre'] === 'RAMIRO ESTEBAN VILLARREAL DUARTE', 'nombre completo aunque una lectura fue parcial: ' . $p287['nombre']);
$p316 = array_values(array_filter($r['personas'], fn($x) => $x['id'] === '00316'))[0];
ok($p316['lugar'] === 'PUNTO DE CONTROL 2' && $p316['minutos'] >= 29 && $p316['minutos'] <= 31, '00316: su última entrada (punto 2, hace 30 min)');
$p500 = array_values(array_filter($r['personas'], fn($x) => $x['id'] === '00500'))[0];
ok($p500['revisar'] === true && $p316['revisar'] === false, 'más de 13 h dentro: marcado para revisar');
ok($r['personas'][0]['id'] === '00316', 'las entradas más recientes primero');
$r2 = $ca->enPlanta(['lugar' => 'PUNTO DE CONTROL 1'], $admin);
ok(array_column($r2['personas'], 'id') === ['00287'], 'filtro por punto de entrada: ' . json_encode(array_column($r2['personas'], 'id')));
ok($ca->enPlanta([], $caseta)['status'] === 'error', 'la caseta no consulta quién está en planta');
ok($ca->enPlanta([], $calidad)['status'] === 'success', 'otras cuentas con acceso al módulo sí');

// Movimientos de un ID (con y sin ceros)
$m = $ca->persona(['id' => '316'], $admin);
ok($m['status'] === 'success' && $m['id'] === '00316' && $m['nombre'] === 'PEDRO VEGA CRUZ' && $m['en_planta'] === true, 'movimientos de «316» = 00316, en planta');
ok(count($m['movimientos']) === 2 && $m['movimientos'][0]['salida'] === '' && $m['movimientos'][1]['salida'] !== '', 'dos movimientos: el de ahora (sin salida) y el anterior (con salida): ' . json_encode(array_map(fn($x) => [$x['entrada'], $x['salida']], $m['movimientos'])));
$m412 = $ca->persona(['id' => '00412'], $admin);
ok($m412['en_planta'] === false && $m412['movimientos'][0]['lugar'] === 'PUNTO DE CONTROL 2' && $m412['movimientos'][0]['lugar_salida'] === 'PUNTO DE CONTROL 3' && $m412['movimientos'][0]['minutos'] === 300, '00412: entró por el 2, salió por el 3, 5 h dentro');
ok($ca->persona(['id' => '99999'], $admin)['movimientos'] === [], 'ID sin registros: lista vacía');
ok($ca->persona(['id' => ''], $admin)['status'] === 'error', 'sin ID: pide el número');
ok($ca->persona(['id' => '00316'], $caseta)['status'] === 'error', 'la caseta no consulta movimientos');

// Emparejar (PDF) también por ID
$filas = $pdo->query("SELECT id, uid, tipo, capturado, lugar, nombre, codigo, origen FROM accesos_registros ORDER BY capturado, id")->fetchAll();
$e = ControlAccesos::emparejar($filas);
$de316 = array_values(array_filter($e, fn($x) => $x['codigo'] === '00316'));
ok(count($de316) === 2 && !array_filter($e, fn($x) => $x['codigo'] === '316'), 'PDF: «316» escrito a mano se junta con 00316');

// Dar salida (Administración)
ok($ca->darSalida(['id' => '00500'], $calidad)['status'] === 'error', 'sólo Administración da salidas');
ok($ca->darSalida(['id' => '00412'], $admin)['status'] === 'error', 'no se da salida a quien ya salió');
$d = $ca->darSalida(['id' => '500'], $admin);
ok($d['status'] === 'success' && str_contains($d['message'], 'LUIS MORA DIAZ'), 'dar salida a 500 (= 00500): ' . ($d['message'] ?? ''));
$fila = $pdo->query("SELECT tipo, lugar, origen, codigo, nombre, nota, usuario FROM accesos_registros WHERE uid LIKE 'adm-%'")->fetch();
ok($fila && $fila['tipo'] === 'salida' && $fila['lugar'] === 'ADMINISTRACION' && $fila['origen'] === 'admin' && $fila['codigo'] === '00500' && $fila['usuario'] === 'admin', 'queda marcada: ' . json_encode($fila, JSON_UNESCAPED_UNICODE));
ok(!in_array('00500', array_column($ca->enPlanta([], $admin)['personas'], 'id')), 'y ya no aparece en planta');
$pdo->exec("DELETE FROM accesos_registros WHERE uid LIKE 'adm-%'");
// Una caseta no puede marcar «admin» como origen
$ca->registrar(['uid' => 'falso-00000001', 'tipo' => 'salida', 'capturado' => $hace(1), 'lugar' => 'X', 'codigo' => '00287', 'persona' => 'X', 'origen' => 'admin'], [], $calidad);
ok($pdo->query("SELECT origen FROM accesos_registros WHERE uid='falso-00000001'")->fetchColumn() === 'tarjeta', 'sólo Administración puede marcar origen «admin»');

// Reglas del lector desde la API
$rg = ControlAccesos::reglasTarjeta();
ok($rg['status'] === 'success' && ($rg['reglas']['orientacion'] ?? '') === 'vertical' && in_array('AYUDANTE', $rg['reglas']['noNombre']), 'reglas del lector desde la API (no de la demo)');
ok(($rg['reglas']['camara'] ?? '') === 'frontal', 'el lector empieza con la cámara frontal (regla «camara»)');

$pdo->exec("DELETE FROM accesos_registros");
echo $fallas ? "$fallas FALLAS\n" : "TODO BIEN\n";
exit($fallas ? 1 : 0);
