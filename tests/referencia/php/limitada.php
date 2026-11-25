<?php
declare(strict_types=1);
date_default_timezone_set('America/Mexico_City');
define('UPLOAD_DIR', __DIR__ . '/uploads_lim/');
@mkdir(UPLOAD_DIR, 0755, true);
require __DIR__ . '/../../../api/helpers.php';
require __DIR__ . '/../../../api/ControlAccesos.php';
$f = 0; function ok($c, $m) { global $f; if (!$c) $f++; echo ($c ? "OK    " : "FALLA ") . "$m\n"; }
$root = new PDO('mysql:host=127.0.0.1;dbname=ca_limitada', 'prueba', 'prueba', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$root->exec("DROP TABLE IF EXISTS accesos_registros");
$root->exec("CREATE TABLE accesos_registros (id INT AUTO_INCREMENT PRIMARY KEY, uid VARCHAR(64) NOT NULL, tipo VARCHAR(10) NOT NULL, fecha DATE NOT NULL,
  capturado DATETIME NOT NULL, recibido DATETIME NOT NULL, lugar VARCHAR(120) NOT NULL DEFAULT '', nota VARCHAR(255) NOT NULL DEFAULT '',
  foto VARCHAR(255) NOT NULL DEFAULT '', foto_mini VARCHAR(255) NOT NULL DEFAULT '', usuario VARCHAR(50) NOT NULL DEFAULT '',
  usuario_nombre VARCHAR(150) NOT NULL DEFAULT '', turno_fecha DATE NULL, turno CHAR(1) NULL, UNIQUE KEY uk_uid (uid)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo = new PDO('mysql:host=127.0.0.1;dbname=ca_limitada;charset=utf8mb4', 'limitado', 'limitado', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$ca = new ControlAccesos($pdo);
$u = ['usuario' => 'admin', 'nombre' => 'ADMINISTRACION', 'rol' => 'ADMIN'];
ok(ControlAccesos::conTarjeta($pdo) === false, 'sin permiso de ALTER: trabaja como antes');
$img = imagecreatetruecolor(200, 120); $tmp = __DIR__ . '/foto_prueba.jpg'; imagejpeg($img, $tmp);
$r = $ca->registrar(['uid' => 'foto-00000000001', 'tipo' => 'entrada', 'capturado' => '2026-11-26 06:10:00', 'lugar' => 'CASETA'], ['tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)], $u);
ok($r['status'] === 'success', 'con foto se registra igual que hoy: ' . ($r['message'] ?? ''));
$r2 = $ca->registrar(['uid' => 'tarj-00000000001', 'tipo' => 'entrada', 'capturado' => '2026-11-26 06:11:00', 'lugar' => 'CASETA', 'codigo' => '123', 'persona' => 'A B'], [], $u);
ok($r2['status'] === 'error', 'tarjeta sin columnas: aviso claro (' . $r2['message'] . ')');
$l = $ca->listar(['turnos' => '2026-11-26-D'], ['usuario' => 'admin', 'rol' => 'ADMIN']);
ok($l['status'] === 'success' && count($l['registros']) === 1 && $l['registros'][0]['codigo'] === '', 'la lista sigue funcionando');
$p = $ca->generarPdfDatos(['turno' => '2026-11-26-D'], ['usuario' => 'admin', 'rol' => 'ADMIN']);
ok($p['status'] === 'error', 'PDF de datos: ' . $p['message']);
echo $f ? "$f FALLAS\n" : "TODO BIEN\n";
