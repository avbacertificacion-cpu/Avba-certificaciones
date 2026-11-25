<?php
/* ══════════════════════════════════════════════════════════════
   AVBA — DEMO de control de accesos: «sala» compartida entre dispositivos

   Guarda las operaciones de la demo (entradas, salidas, intentos, permisos,
   vetos, vales, solicitudes de autorización y sus respuestas) para que la laptop del administrador y el celular del guardia
   vean lo mismo al mismo tiempo. Sólo datos ficticios de la demo: no toca la
   base del sistema ni pide sesión.

     GET  ?sala=ABCD&dia=2026426&desde=N        → { ok, epoca, total, ops }
     POST { sala, dia, op }                      → { ok, total }
     POST { sala, dia, accion: "reiniciar" }     → { ok, epoca }
     GET  ?qr=caseta&sala=ABCD                   → código QR (SVG) para abrir
                                                   la pantalla del guardia en el celular
     GET  ?qr=apk                                → código QR para descargar la app
                                                   AVBA Caseta (Android)
     GET  ?tarjeta=1                             → reglas para leer la tarjeta de
                                                   identificación (api/accesos-tarjeta.json)

   La app AVBA Caseta lleva la pantalla del guardia dentro y llama desde su
   propio origen (https://localhost en Android): se permite con CORS.

   Cada sala y día se guarda en uploads/demo-salas/<SALA>-<dia>.json (carpeta
   cerrada al acceso directo). Las salas de días anteriores se borran solas.
   ══════════════════════════════════════════════════════════════ */
declare(strict_types=1);

const MAX_OPS = 1000;
const MAX_SALAS = 300;                     // salas (archivos) a la vez; las de días anteriores se borran solas
const MAX_CUERPO = 20000;
$DIR = dirname(__DIR__) . '/uploads/demo-salas/';

// La app AVBA Caseta (mobile/caseta) llama desde su propio origen
$origenApp = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
if (in_array($origenApp, ['https://localhost', 'capacitor://localhost'], true)) {
    header('Access-Control-Allow-Origin: ' . $origenApp);
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 600');
    header('Vary: Origin');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

function salir(array $d, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}
function texto($v, int $max): string {
    $v = is_scalar($v) ? (string)$v : '';
    $v = (string)preg_replace('/[\x00-\x1F\x7F]/u', ' ', $v);
    return mb_substr(trim($v), 0, $max);
}
function salaValida($v): string {
    $v = strtoupper(is_scalar($v) ? (string)$v : '');
    return preg_match('/^[A-Z0-9]{4,10}$/', $v) ? $v : '';
}
function diaValido($v): string {
    $v = is_scalar($v) ? (string)$v : '';
    return preg_match('/^\d{6,8}$/', $v) ? $v : '';
}

/* Una operación de la demo, campo por campo: lo que no se reconoce se descarta. */
function opValida($o): ?array {
    if (!is_array($o)) return null;
    $k = $o['k'] ?? '';
    if (!in_array($k, ['ent', 'sal', 'int', 'inc', 'aut', 'lev', 'cv', 'sol', 'resp', 'reg', 'am', 'alta'], true)) return null;
    $u = is_string($o['u'] ?? null) ? $o['u'] : '';
    if (!preg_match('/^[a-z0-9]{6,24}$/', $u)) return null;
    $t = $o['t'] ?? null;
    if (!is_int($t) && !is_float($t)) return null;
    $t = (int)$t;
    $ahora = (int)(microtime(true) * 1000);
    $atras = $k === 'inc' ? 400 * 86400000 : 3 * 86400000;      // una incidencia puede registrarse con fecha pasada
    if ($t - $ahora > 3 * 86400000 || $ahora - $t > $atras) return null;
    $r = ['k' => $k, 'u' => $u, 't' => $t];
    if (!in_array($k, ['cv', 'resp', 'reg', 'am'], true)) {
        $pid = is_string($o['pid'] ?? null) ? $o['pid'] : '';
        // P001…: personas de la demo · Q + código: tarjeta leída en la caseta
        if (!preg_match('/^(P\d{3}|Q\d{1,16})$/', $pid) || ($k === 'alta' && $pid[0] !== 'Q')) return null;
        $r['pid'] = $pid;
    }
    foreach (['caseta' => 60, 'quien' => 60, 'res' => 60] as $c => $max) {
        if (isset($o[$c])) $r[$c] = texto($o[$c], $max);
    }
    switch ($k) {
        case 'ent':
            $r['obs'] = texto($o['obs'] ?? '', 200);
            $r['herr'] = !empty($o['herr']);
            $r['items'] = [];
            foreach (array_slice(is_array($o['items'] ?? null) ? $o['items'] : [], 0, 10) as $x) { $x = texto($x, 60); if ($x !== '') $r['items'][] = $x; }
            if (is_string($o['folio'] ?? null) && preg_match('/^V-\d{4}$/', $o['folio'])) $r['folio'] = $o['folio'];
            break;
        case 'sal':
            $r['falta'] = [];
            foreach (array_slice(is_array($o['falta'] ?? null) ? $o['falta'] : [], 0, 20) as $x) {
                $x = texto($x, 100);
                if (preg_match('/^V-\d{4}: .+$/u', $x)) $r['falta'][] = $x;
            }
            $r['noDevuelto'] = [];
            foreach (array_slice(is_array($o['noDevuelto'] ?? null) ? $o['noDevuelto'] : [], 0, 10) as $x) {
                if (is_string($x) && preg_match('/^V-\d{4}$/', $x)) $r['noDevuelto'][] = $x;
            }
            break;
        case 'int':
            $r['motivo'] = texto($o['motivo'] ?? '', 150);
            break;
        case 'inc':
            $tipos = ['EPP', 'ACTO', 'CASI', 'ACC', 'ALC', 'DRO', 'RIN', 'ROB', 'CEL', 'SUP', 'VET'];
            $acciones = ['Plática de seguridad', 'Amonestación por escrito', 'Suspensión 3 días', 'Suspensión 7 días', 'Veto 30 días',
                         'Veto 90 días', 'Veto permanente en esta planta', 'Veto en todas las plantas del cliente', 'Se negó el acceso'];
            if (!in_array($o['tipo'] ?? '', $tipos, true) || !in_array($o['accion'] ?? '', $acciones, true)) return null;
            $r['tipo'] = $o['tipo'];
            $r['accion'] = $o['accion'];
            $r['desc'] = texto($o['desc'] ?? '', 300);
            $r['por'] = texto($o['por'] ?? '', 60);
            if (is_string($o['folio'] ?? null) && preg_match('/^INC-\d{4}$/', $o['folio'])) $r['folio'] = $o['folio'];
            break;
        case 'reg':
            $in = is_array($o['r'] ?? null) ? $o['r'] : [];
            $lim = ['anio' => [2026, 2030], 'perm' => [8, 16], 'diasSeguidos' => [5, 13], 'vale' => [1, 30], 'alc' => [0, 100]];
            $r['r'] = [];
            foreach ($lim as $c => $mm) { if (isset($in[$c]) && is_numeric($in[$c])) $r['r'][$c] = max($mm[0], min($mm[1], 0 + $in[$c])); }
            foreach (['docs', 'cel', 'herr'] as $c) { if (isset($in[$c]) && is_bool($in[$c])) $r['r'][$c] = $in[$c]; }
            break;
        case 'am':
            $f = is_string($o['folio'] ?? null) ? $o['folio'] : '';
            if (!preg_match('/^V-\d{4}$/', $f)) return null;
            $r['folio'] = $f;
            break;
        case 'aut':
            if (!in_array($o['c'] ?? '', ['cel', 'tab', 'pc', 'cam', 'herr', 'auto'], true)) return null;
            $r['c'] = $o['c'];
            $r['v'] = !empty($o['v']);
            break;
        case 'sol':
            $r['motivo'] = texto($o['motivo'] ?? '', 150);
            break;
        case 'resp':
            $sol = is_string($o['sol'] ?? null) ? $o['sol'] : '';
            if (!preg_match('/^[a-z0-9]{6,24}$/', $sol)) return null;
            $r['sol'] = $sol;
            $r['ok'] = !empty($o['ok']);
            break;
        case 'alta':                                          // persona nueva (tarjeta leída en la caseta)
            $cod = preg_replace('/\D/', '', is_scalar($o['codigo'] ?? null) ? (string)$o['codigo'] : '');
            if ($cod === '' || strlen($cod) > 16 || 'Q' . $cod !== $r['pid']) return null;
            $r['codigo'] = $cod;
            $nom = texto($o['nombre'] ?? '', 80);
            $r['nombre'] = preg_match("/^[\p{L} .'\-]{0,80}$/u", $nom) ? $nom : '';
            $r['puesto'] = texto($o['puesto'] ?? '', 60);
            break;
        case 'cv':
            $f = is_string($o['folio'] ?? null) ? $o['folio'] : '';
            if (!preg_match('/^V-\d{4}$/', $f)) return null;
            $r['folio'] = $f;
            break;
    }
    return $r;
}

function asegurarCarpeta(string $dir): bool {
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return false;
    if (!is_file($dir . '.htaccess')) {
        @file_put_contents($dir . '.htaccess', "Options -Indexes\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    }
    return true;
}
function vacia(): array { return ['epoca' => 1, 'ops' => []]; }
function decodificar(string $txt): array {
    $j = json_decode($txt, true);
    return is_array($j) && isset($j['ops']) && is_array($j['ops']) ? $j : vacia();
}
/* Lee y, si se pide, modifica la sala con candado de archivo (varios dispositivos a la vez). */
function conSala(string $archivo, ?callable $cambio): array {
    $fp = @fopen($archivo, $cambio ? 'c+' : 'r');
    if (!$fp) return vacia();
    flock($fp, $cambio ? LOCK_EX : LOCK_SH);
    $datos = decodificar((string)stream_get_contents($fp));
    if ($cambio) {
        $datos = $cambio($datos);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($datos, JSON_UNESCAPED_UNICODE));
        fflush($fp);
    }
    flock($fp, LOCK_UN);
    fclose($fp);
    return $datos;
}

/* ── Código QR para abrir la caseta (o el panel) en otro dispositivo ── */
if (isset($_GET['qr'])) {
    $apk = $_GET['qr'] === 'apk';
    $sala = salaValida($_GET['sala'] ?? '');
    $destino = ($_GET['qr'] === 'panel') ? 'control-accesos.html' : 'caseta.html';
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ((!$sala && !$apk) || !preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $host)) { http_response_code(400); exit; }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $ruta = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/demo/sala.php'))), '/');
    $url = ($https ? 'https' : 'http') . '://' . $host . $ruta . '/' . ($apk ? 'app/avba-caseta.apk' : $destino . '?sala=' . $sala);
    require_once dirname(__DIR__) . '/api/lib/qrcode.php';
    $qr = QRCode::getMinimumQRCode($url, QR_ERROR_CORRECT_LEVEL_M);
    $n = $qr->getModuleCount();
    $m = 2;                                                   // margen (módulos)
    $path = '';
    for ($r = 0; $r < $n; $r++) {
        for ($c = 0; $c < $n; $c++) {
            if ($qr->isDark($r, $c)) $path .= 'M' . ($c + $m) . ' ' . ($r + $m) . 'h1v1h-1z';
        }
    }
    $lado = $n + 2 * $m;
    header('Content-Type: image/svg+xml; charset=utf-8');
    header('Cache-Control: public, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $lado . ' ' . $lado . '" shape-rendering="crispEdges">'
       . '<rect width="100%" height="100%" fill="#fff"/><path fill="#0B2545" d="' . $path . '"/></svg>';
    exit;
}

/* ── Reglas para leer la tarjeta de identificación (se ajustan sin reinstalar la app) ── */
if (isset($_GET['tarjeta'])) {
    $j = @file_get_contents(__DIR__ . '/../api/accesos-tarjeta.json');      // las mismas reglas que la app real
    $reglas = is_string($j) ? json_decode($j, true) : null;
    salir(['ok' => true, 'reglas' => is_array($reglas) ? $reglas : new stdClass()]);
}

/* ── Consultar la sala ── */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $sala = salaValida($_GET['sala'] ?? '');
    $dia = diaValido($_GET['dia'] ?? '');
    if (!$sala || !$dia) salir(['ok' => false, 'error' => 'sala'], 400);
    $desde = max(0, (int)($_GET['desde'] ?? 0));
    $datos = conSala($DIR . $sala . '-' . $dia . '.json', null);
    $total = count($datos['ops']);
    salir(['ok' => true, 'epoca' => (int)$datos['epoca'], 'total' => $total, 'ops' => $desde < $total ? array_slice($datos['ops'], $desde) : []]);
}

/* ── Registrar una operación o reiniciar la sala ── */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') salir(['ok' => false, 'error' => 'metodo'], 405);
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > MAX_CUERPO) salir(['ok' => false, 'error' => 'grande'], 413);
$cuerpo = json_decode((string)file_get_contents('php://input', false, null, 0, MAX_CUERPO + 1), true);
if (!is_array($cuerpo)) salir(['ok' => false, 'error' => 'json'], 400);
$sala = salaValida($cuerpo['sala'] ?? '');
$dia = diaValido($cuerpo['dia'] ?? '');
if (!$sala || !$dia) salir(['ok' => false, 'error' => 'sala'], 400);
if (!asegurarCarpeta($DIR)) salir(['ok' => false, 'error' => 'carpeta'], 500);
$archivo = $DIR . $sala . '-' . $dia . '.json';

// Limpieza ocasional: salas de días anteriores
if (mt_rand(1, 20) === 1) {
    foreach ((array)glob($DIR . '*.json') as $f) {
        if (is_string($f) && @filemtime($f) < time() - 3 * 86400) @unlink($f);
    }
}
// Tope de salas: una sala nueva sólo se crea si hay lugar
if (!is_file($archivo) && count((array)glob($DIR . '*.json')) >= MAX_SALAS) salir(['ok' => false, 'error' => 'lleno'], 429);

if (($cuerpo['accion'] ?? '') === 'reiniciar') {
    $datos = conSala($archivo, function (array $d) { return ['epoca' => (int)$d['epoca'] + 1, 'ops' => []]; });
    salir(['ok' => true, 'epoca' => (int)$datos['epoca'], 'total' => 0]);
}

$op = opValida($cuerpo['op'] ?? null);
if (!$op) salir(['ok' => false, 'error' => 'invalida'], 422);
$lleno = false;
$datos = conSala($archivo, function (array $d) use ($op, &$lleno) {
    foreach ($d['ops'] as $x) { if (($x['u'] ?? '') === $op['u']) return $d; }   // ya estaba (reintento)
    if (count($d['ops']) >= MAX_OPS) { $lleno = true; return $d; }
    $d['ops'][] = $op;
    return $d;
});
if ($lleno) salir(['ok' => false, 'error' => 'llena'], 409);
salir(['ok' => true, 'epoca' => (int)$datos['epoca'], 'total' => count($datos['ops'])]);
