<?php
/* El turno de cada lectura (base local de prueba, datos inventados).
   Regla: una ENTRADA cuenta para el turno que empieza (desde 2 h antes) y una SALIDA para el
   que termina (hasta 2 h después), PERO la salida de quien tiene su entrada abierta (últimas
   16 h) va al turno de esa entrada: entrar a las 17:57 y salir a las 17:58 no se parte en dos. */
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
ControlAccesos::asegurarTabla($pdo);
$pdo->exec("DELETE FROM accesos_registros");
$ca = new ControlAccesos($pdo);
$admin = ['usuario' => 'admin', 'nombre' => 'ADMINISTRACION', 'rol' => 'ADMIN'];
$n = 0;
$lee = function (string $tipo, string $cap, string $codigo) use ($ca, $admin, &$n): string {
    $n++;
    $r = $ca->registrar(['uid' => 'turno-' . str_pad((string)$n, 10, '0', STR_PAD_LEFT), 'tipo' => $tipo, 'capturado' => $cap,
                         'lugar' => 'PUNTO DE CONTROL 1', 'codigo' => $codigo, 'persona' => 'PERSONA ' . $codigo, 'origen' => 'tarjeta'], [], $admin);
    return $r['turno'] ?? ('ERROR: ' . ($r['message'] ?? ''));
};

// 1) El caso reportado: entrada 17:57 y salida 17:58 (minutos después) → los dos en el turno de noche
$e = $lee('entrada', '2026-11-26 17:57:26', '00501');
$s = $lee('salida',  '2026-11-26 17:58:02', '00501');
ok($e === '2026-11-26-N' && $s === '2026-11-26-N', "entrada 17:57 y salida 17:58: mismo turno ($e / $s)");

// 2) Turno de día que se queda tarde: entra 06:20, sale 21:15 → día (por reloj sería noche)
ok($lee('entrada', '2026-11-26 06:20:00', '00502') === '2026-11-26-D' && $lee('salida', '2026-11-26 21:15:00', '00502') === '2026-11-26-D',
   'entra 06:20 y sale 21:15: turno de día (su entrada manda)');

// 3) Salida sin entrada: decide el reloj (hasta 2 h después del cambio cuenta para el que termina)
ok($lee('salida', '2026-11-26 17:58:00', '00503') === '2026-11-26-D', 'salida 17:58 sin entrada: turno de día (reloj)');

// 4) Llega muy temprano: entra 05:00 (día) y sale 07:00 → día (por reloj sería la noche anterior)
ok($lee('entrada', '2026-11-26 05:00:00', '00504') === '2026-11-26-D' && $lee('salida', '2026-11-26 07:00:00', '00504') === '2026-11-26-D',
   'entra 05:00 y sale 07:00: turno de día');

// 5) Entrada de hace más de 16 h (no pasó su salida): ya no arrastra; decide el reloj
$lee('entrada', '2026-11-25 01:00:00', '00505');
ok($lee('salida', '2026-11-26 17:58:00', '00505') === '2026-11-26-D', 'entrada de hace más de 16 h: la salida va por el reloj');

// 6) Turno de noche normal: entra 18:10, sale 06:45 del día siguiente → noche del 26
ok($lee('entrada', '2026-11-26 18:10:00', '00506') === '2026-11-26-N' && $lee('salida', '2026-11-27 06:45:00', '00506') === '2026-11-26-N',
   'noche: entra 18:10 y sale 06:45 del día siguiente');

// 7) El ID se compara sin ceros: entrada «00507», salida «507» escrita a mano
ok($lee('entrada', '2026-11-26 17:00:00', '00507') === '2026-11-26-N' && $lee('salida', '2026-11-26 17:30:00', '507') === '2026-11-26-N',
   'entrada 00507 y salida 507: mismo turno');

// 8) Ya salió: una segunda salida no tiene entrada abierta y va por el reloj
$lee('entrada', '2026-11-26 17:00:00', '00508');
$lee('salida', '2026-11-26 17:10:00', '00508');
ok($lee('salida', '2026-11-26 17:20:00', '00508') === '2026-11-26-D', 'segunda salida (ya había salido): reloj');

// 9) Otra persona no cuenta: la entrada abierta es por ID
$lee('entrada', '2026-11-26 17:40:00', '00509');
ok($lee('salida', '2026-11-26 17:45:00', '00510') === '2026-11-26-D', 'la entrada de otro ID no arrastra la salida');

// 10) En el turno de noche, la persona del caso 1 queda emparejada (entrada y salida en el mismo renglón)
$filas = $pdo->query("SELECT id, uid, tipo, capturado, lugar, nombre, codigo FROM accesos_registros WHERE turno_fecha='2026-11-26' AND turno='N' ORDER BY capturado, id")->fetchAll();
$p = array_values(array_filter(ControlAccesos::emparejar($filas), fn($r) => $r['codigo'] === '00501'));
ok(count($p) === 1 && $p[0]['entrada'] === '2026-11-26 17:57:26' && $p[0]['salida'] === '2026-11-26 17:58:02' && $p[0]['minutos'] === 1,
   'en el PDF del turno de noche sale en un solo renglón (1 min dentro)');
$tot = $ca->listar(['turnos' => '2026-11-26-D,2026-11-26-N'], $admin)['totales'];
ok($tot['2026-11-26-N']['entrada'] === 5 && $tot['2026-11-26-N']['salida'] === 4, 'contadores del turno de noche: ' . json_encode($tot['2026-11-26-N']));

// 11) Horario de la planta: día 06:00–18:00 y noche 18:00–06:00 (margen de 2 h en cada cambio)
$t = fn($tipo, $hora) => implode('-', ControlAccesos::turnoDe($tipo, "2026-11-26 $hora"));
ok($t('entrada', '15:59:59') === '2026-11-26-D' && $t('entrada', '16:00:00') === '2026-11-26-N', 'entrada: hasta 15:59 es día; desde 16:00 (2 h antes de las 18:00) es noche');
ok($t('entrada', '03:59:59') === '2026-11-25-N' && $t('entrada', '04:00:00') === '2026-11-26-D', 'entrada: hasta 03:59 es la noche anterior; desde 04:00 es día');
ok($t('salida', '07:59:59') === '2026-11-25-N' && $t('salida', '08:00:00') === '2026-11-26-D', 'salida (sin entrada): hasta 07:59 es la noche anterior; desde 08:00 es día');
ok($t('salida', '19:59:59') === '2026-11-26-D' && $t('salida', '20:00:00') === '2026-11-26-N', 'salida (sin entrada): hasta 19:59 es día; desde 20:00 es noche');
$r = fn($hora) => implode('-', ControlAccesos::turnoReloj("2026-11-26 $hora"));
ok($r('05:59:59') === '2026-11-25-N' && $r('06:00:00') === '2026-11-26-D' && $r('17:59:59') === '2026-11-26-D' && $r('18:00:00') === '2026-11-26-N',
   'incidencias (reloj, sin margen): el cambio es a las 06:00 y a las 18:00');
ok(str_contains(ControlAccesos::nombreTurno('2026-11-26', 'D'), '(06:00 a 18:00)') && str_contains(ControlAccesos::nombreTurno('2026-11-26', 'N'), '18:00 al'),
   'el PDF dice «06:00 a 18:00»: ' . ControlAccesos::nombreTurno('2026-11-26', 'D'));

echo $fallas ? "$fallas FALLAS\n" : "TODO BIEN\n";
exit($fallas ? 1 : 0);
