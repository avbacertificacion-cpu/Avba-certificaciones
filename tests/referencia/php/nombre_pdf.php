<?php
declare(strict_types=1);
date_default_timezone_set('America/Mexico_City');
define('UPLOAD_DIR', __DIR__ . '/prueba_accesos/uploads/');
require __DIR__ . '/../../../api/helpers.php';
require __DIR__ . '/../../../api/ControlAccesos.php';
$f = fn($id, $tipo, $hora, $cod, $nom) => ['id' => $id, 'uid' => 'u' . $id, 'tipo' => $tipo, 'capturado' => "2026-11-26 $hora", 'lugar' => 'PUNTO DE CONTROL 1', 'nombre' => $nom, 'codigo' => $cod];
$filas = [
  $f(1, 'entrada', '06:10:00', '00287', 'RAMIRO ESTEBAN'),
  $f(2, 'salida',  '18:20:00', '00287', 'RAMIRO ESTEBAN VILLARREAL DUARTE'),
  $f(3, 'entrada', '06:20:00', '00412', 'ANA RUIZ'),
  $f(4, 'salida',  '12:00:00', '00412', 'PEDRO LOPEZ'),
  $f(5, 'entrada', '13:00:00', '00412', 'ANA RUIZ'),
  $f(6, 'entrada', '06:30:00', '00500', 'LUIS MORA'),
  $f(7, 'salida',  '18:00:00', '00500', 'LUIS MORAL'),
];
$e = ControlAccesos::emparejar($filas);
$n = []; foreach ($e as $r) $n[$r['codigo']][] = $r['nombre'];
$ok = fn($c, $m) => print(($c ? 'OK    ' : 'FALLA ') . $m . "\n");
$ok(array_unique($n['00287']) === ['RAMIRO ESTEBAN VILLARREAL DUARTE'], 'lectura parcial + completa → el completo: ' . json_encode($n['00287']));
$ok(array_unique($n['00412']) === ['ANA RUIZ'], 'el más leído gana a una mala lectura: ' . json_encode(array_values(array_unique($n['00412']))));
$ok(array_unique($n['00500']) === ['LUIS MORAL'], 'empate entre dos distintos → el más completo: ' . json_encode($n['00500']));
