<?php
/* Siembra registros de prueba para la vista «En planta» (base local de prueba), relativos a las 21:00 de hoy:
   la pantalla y el servidor se prueban con esa hora fija, así todo queda después del reinicio de las 05:30. */
declare(strict_types=1);
date_default_timezone_set('America/Mexico_City');
define('UPLOAD_DIR', __DIR__ . '/uploads/');
require __DIR__ . '/../../../api/helpers.php';
require __DIR__ . '/../../../api/ControlAccesos.php';
$pdo = new PDO('mysql:host=127.0.0.1;dbname=ca_prueba;charset=utf8mb4', 'prueba', 'prueba', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
ControlAccesos::asegurarTabla($pdo);
$pdo->exec("DELETE FROM accesos_registros");
$base = strtotime(date('Y-m-d') . ' 21:00:00');
$n = 0;
$mete = function (string $tipo, int $min, string $cod, string $nom, string $lugar) use ($pdo, $base, &$n) {
    $n++; $cap = date('Y-m-d H:i:s', $base - $min * 60); [$tf, $t] = ControlAccesos::turnoDe($tipo, $cap);
    $pdo->prepare("INSERT INTO accesos_registros (uid,tipo,fecha,capturado,recibido,lugar,usuario,usuario_nombre,turno_fecha,turno,nombre,codigo,origen)
                   VALUES (?,?,?,?,NOW(),?,'punto1','CASETA PUNTO 1',?,?,?,?,'tarjeta')")
        ->execute(['s' . str_pad((string)$n, 14, '0', STR_PAD_LEFT), $tipo, substr($cap, 0, 10), $cap, $lugar, $tf, $t, $nom, $cod]);
};
$mete('entrada', 300, '00287', 'RAMIRO ESTEBAN VILLARREAL DUARTE', 'PUNTO DE CONTROL 1');
$mete('entrada', 360, '00412', 'ANA SOFIA RUIZ PAEZ', 'PUNTO DE CONTROL 2');
$mete('salida', 60, '00412', 'ANA SOFIA RUIZ PAEZ', 'PUNTO DE CONTROL 3');
$mete('entrada', 600, '00316', 'PEDRO VEGA CRUZ', 'PUNTO DE CONTROL 1');
$mete('salida', 400, '316', '', 'PUNTO DE CONTROL 1');
$mete('entrada', 30, '00316', 'PEDRO VEGA CRUZ', 'PUNTO DE CONTROL 2');
$mete('entrada', 900, '00500', 'LUIS MORA DIAZ', 'PUNTO DE CONTROL 4');
$mete('entrada', 45, '00733', 'MARIA FERNANDA RIOS', 'PUNTO DE CONTROL 2');
echo "sembrado: $n registros\n";
