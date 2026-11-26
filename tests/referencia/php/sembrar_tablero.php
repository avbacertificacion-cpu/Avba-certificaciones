<?php
/* Siembra un mes de entradas, salidas e incidencias INVENTADAS (relativas a ahora) para el
   tablero. Base local de prueba. Uso: php sembrar_tablero.php [días=30]
   Personas y IDs inventados: personal de día (06:00–18:00) y de noche (18:00–06:00), con
   descansos, algunas salidas sin registrar, lecturas de sólo foto e incidencias. */
declare(strict_types=1);
date_default_timezone_set('America/Mexico_City');
define('UPLOAD_DIR', __DIR__ . '/uploads/');
require __DIR__ . '/../../../api/helpers.php';
require __DIR__ . '/../../../api/ControlAccesos.php';
$pdo = new PDO('mysql:host=127.0.0.1;dbname=ca_prueba;charset=utf8mb4', 'prueba', 'prueba', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
ControlAccesos::asegurarTabla($pdo);
ControlAccesos::asegurarIncidencias($pdo);
$pdo->exec("DELETE FROM accesos_registros");
$pdo->exec("DELETE FROM accesos_incidencias");
mt_srand(7);

$dias = max(1, (int)($argv[1] ?? 30));
$nombres = ['RAMIRO ESTEBAN', 'ANA SOFIA', 'PEDRO', 'LUIS ALBERTO', 'MARIA FERNANDA', 'JORGE', 'CARLOS IVAN', 'ROSA ELENA',
            'MIGUEL ANGEL', 'JUAN PABLO', 'ERIKA', 'HECTOR', 'JOSUE', 'DIANA LAURA', 'OSCAR', 'FRANCISCO JAVIER', 'KARLA',
            'ADRIAN', 'SERGIO', 'PATRICIA'];
$apellidos = ['VILLARREAL', 'DUARTE', 'RUIZ', 'PAEZ', 'VEGA', 'CRUZ', 'MORA', 'DIAZ', 'RIOS', 'SALAZAR', 'IBARRA', 'GARZA',
              'TREVIÑO', 'CANTU', 'ESPINOZA', 'OCHOA', 'ZAVALA', 'MONTES', 'ROBLES', 'QUIROGA'];
$gente = [];
for ($i = 0; $i < 36; $i++) {
    $gente[] = ['id' => str_pad((string)(101 + $i), 5, '0', STR_PAD_LEFT),
                'nombre' => $nombres[$i % 20] . ' ' . $apellidos[($i * 7) % 20] . ' ' . $apellidos[($i * 3 + 5) % 20],
                'turno' => $i < 24 ? 'D' : 'N'];
}

$ahora = time();
$n = 0;
$mete = function (string $tipo, int $t, string $cod, string $nom, string $origen = 'tarjeta') use ($pdo, &$n, $ahora) {
    if ($t > $ahora) return false;
    $n++;
    $cap = date('Y-m-d H:i:s', $t);
    [$tf, $tu] = ControlAccesos::turnoDe($tipo, $cap);
    $pdo->prepare("INSERT INTO accesos_registros (uid,tipo,fecha,capturado,recibido,lugar,usuario,usuario_nombre,turno_fecha,turno,nombre,codigo,origen)
                   VALUES (?,?,?,?,NOW(),'ACCESO','punto1','CASETA PUNTO 1',?,?,?,?,?)")
        ->execute(['tb' . str_pad((string)$n, 12, '0', STR_PAD_LEFT), $tipo, substr($cap, 0, 10), $cap, $tf, $tu, $nom, $cod, $origen]);
    return true;
};
$hoy = date('Y-m-d');
for ($k = $dias - 1; $k >= 0; $k--) {
    $d = date('Y-m-d', strtotime("$hoy 12:00:00 -$k days"));
    foreach ($gente as $j => $p) {
        if (($j + $k) % 7 === 0) continue;                                    // su día de descanso
        $base = strtotime($d . ($p['turno'] === 'D' ? ' 06:00:00' : ' 18:00:00'));
        $ent = $base + mt_rand(-40, 35) * 60;
        $sal = $base + 12 * 3600 + mt_rand(-25, 55) * 60;
        if (mt_rand(1, 100) <= 6) $sal += mt_rand(60, 150) * 60;             // se quedó más tiempo
        $mete('entrada', $ent, $p['id'], $p['nombre']);
        if (mt_rand(1, 100) > 3) $mete('salida', $sal, $p['id'], mt_rand(1, 10) === 1 ? '' : $p['nombre']);   // a veces no pasa su credencial al salir
    }
    // Lecturas de sólo foto (sin ID)
    if ($k % 5 === 0) $mete('entrada', strtotime($d . ' 07:15:00'), '', '', 'foto');
}

// Incidencias inventadas
$inc = [['ALCOHOLIMETRO', 'Prueba positiva 0.4; se le negó el acceso.'], ['CONDUCTA', 'Discutió con el guardia en la caseta.'],
        ['PERSONA', 'Intentó entrar con la credencial de otra persona.'], ['OTRO', 'Visitante sin credencial, se anunció con el supervisor.'],
        ['ALCOHOLIMETRO', 'Prueba positiva 0.2.'], ['CONDUCTA', 'Llegó en estado inconveniente.'], ['OTRO', 'Olvidó su credencial en casa.']];
$ni = 0;
foreach ($inc as $i => [$tipo, $detalle]) {
    $t = strtotime(date('Y-m-d', strtotime("$hoy 12:00:00 -" . (($i * 4 + 1) % max(1, $dias)) . ' days')) . ' 06:20:00');
    if ($t > $ahora) continue;
    $p = $gente[($i * 5) % count($gente)];
    $cap = date('Y-m-d H:i:s', $t);
    [$tf, $tu] = ControlAccesos::turnoReloj($cap);
    $ni++;
    $pdo->prepare("INSERT INTO accesos_incidencias (uid,tipo,detalle,fecha,capturado,recibido,turno_fecha,turno,lugar,codigo,nombre,origen,usuario,usuario_nombre)
                   VALUES (?,?,?,?,?,NOW(),?,?,'ACCESO',?,?,'tarjeta','punto1','CASETA PUNTO 1')")
        ->execute(['ti' . $ni, $tipo, $detalle, substr($cap, 0, 10), $cap, $tf, $tu, $tipo === 'OTRO' && $i === 3 ? '' : $p['id'], $tipo === 'OTRO' && $i === 3 ? 'VISITANTE SIN CREDENCIAL' : $p['nombre']]);
}
echo "sembrado: $n registros, $ni incidencias, " . count($gente) . " personas, $dias días\n";
