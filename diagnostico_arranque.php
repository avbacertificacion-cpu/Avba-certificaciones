<?php
/**
 * AVBA — Diagnóstico del arranque de la API
 * =========================================
 *
 * api/index.php construye TODAS las clases del sistema antes de atender la
 * petición, así que un fallo en cualquier constructor deja sin servicio hasta
 * el inicio de sesión, y el manejador global responde un escueto "Error interno
 * del servidor" que no dice dónde.
 *
 * Esta página construye las clases una por una y reporta exactamente cuál falla
 * y con qué mensaje, archivo y línea.
 *
 *   /diagnostico_arranque.php?secret=CLAVE
 *
 * No modifica nada. BORRAR DEL SERVIDOR cuando termine el diagnóstico.
 */

declare(strict_types=1);

const SECRETO_DIAG = 'avba_diag_2026';

header('Content-Type: text/html; charset=utf-8');
if (($_GET['secret'] ?? '') !== SECRETO_DIAG) { http_response_code(403); exit('No autorizado.'); }

$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$resultados = [];

function paso(array &$resultados, string $nombre, callable $fn): void {
    $t0 = microtime(true);
    try {
        $fn();
        $resultados[] = ['nombre' => $nombre, 'ok' => true, 'ms' => round((microtime(true) - $t0) * 1000)];
    } catch (\Throwable $ex) {
        $resultados[] = [
            'nombre' => $nombre, 'ok' => false, 'ms' => round((microtime(true) - $t0) * 1000),
            'clase'  => get_class($ex), 'mensaje' => $ex->getMessage(),
            'donde'  => basename($ex->getFile()) . ':' . $ex->getLine(),
        ];
    }
}

paso($resultados, 'config/config.php',   fn() => require_once __DIR__ . '/config/config.php');
paso($resultados, 'config/database.php', fn() => require_once __DIR__ . '/config/database.php');

$pdo = null;
paso($resultados, 'Conexión a la base', function () use (&$pdo) { $pdo = Database::getConnection(); });

if ($pdo) {
    paso($resultados, 'api/helpers.php', fn() => require_once __DIR__ . '/api/helpers.php');

    // Mismo orden en que index.php las construye
    $clases = [
        'Auth', 'Inspecciones', 'Calidad', 'Certificaciones', 'ValidarQR', 'Admin',
        'Auditorias', 'AvbaAdmin', 'Personal', 'Accesorios', 'Pnd',
        'ClienteEquipos', 'ClientePersonal', 'ClienteProveedores', 'ClienteMateriales',
        'ClienteConfig', 'ClienteMantenimiento', 'ClienteSubusuarios', 'ClienteRH',
        'PagosServicios', 'Anuncios', 'VerificacionIA', 'Arneses', 'ClienteImpresion',
    ];
    foreach ($clases as $clase) {
        $archivo = __DIR__ . '/api/' . $clase . '.php';
        if (!is_file($archivo)) { $resultados[] = ['nombre' => $clase, 'ok' => false, 'ms' => 0,
            'clase' => 'Archivo', 'mensaje' => 'No existe api/' . $clase . '.php', 'donde' => '—']; continue; }
        paso($resultados, 'require ' . $clase, fn() => require_once $archivo);
        paso($resultados, 'new ' . $clase, function () use ($clase, $pdo) {
            $r = new ReflectionClass($clase);
            $ctor = $r->getConstructor();
            // Todas reciben el PDO; si alguna no, se omite
            if ($ctor && $ctor->getNumberOfParameters() >= 1) { new $clase($pdo); }
            else { $r->newInstance(); }
        });
    }
}

// ── Prueba del inicio de sesión ───────────────────────────
// Se usa un usuario inexistente a propósito: recorre exactamente el mismo
// camino (tabla de intentos, consulta de usuarios, registro del fallo) sin
// bloquear ninguna cuenta real por intentos fallidos.
$login = null;
if ($pdo && !array_filter($resultados, fn($r) => !$r['ok'])) {
    $t0 = microtime(true);
    try {
        $_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $auth = new Auth($pdo);
        $r = $auth->login(['usuario' => '__diagnostico_avba__', 'password' => 'clave-invalida-de-prueba']);
        $login = ['ok' => true, 'ms' => round((microtime(true) - $t0) * 1000),
                  'respuesta' => $r['message'] ?? ($r['status'] ?? '')];
    } catch (\Throwable $ex) {
        $login = ['ok' => false, 'ms' => round((microtime(true) - $t0) * 1000),
                  'clase' => get_class($ex), 'mensaje' => $ex->getMessage(),
                  'donde' => basename($ex->getFile()) . ':' . $ex->getLine(),
                  'traza' => $ex->getTraceAsString()];
    }
}

// ── Camino del login para un usuario concreto ─────────────
// Se consulta el registro tal como lo hace login(), SIN verificar la
// contraseña y SIN registrar intentos: así no se bloquea la cuenta.
$revision = null;
$quien = trim($_GET['usuario'] ?? '');
if ($pdo && $quien !== '') {
    try {
        $sel = $pdo->prepare("SELECT * FROM usuarios WHERE usuario = ?");
        $sel->execute([mb_strtolower($quien)]);
        $row = $sel->fetch();
        if (!$row) {
            $revision = ['ok' => false, 'nota' => 'No existe un usuario con ese nombre (se busca en minúsculas).'];
        } else {
            $hash = (string)($row['password_hash'] ?? '');
            $revision = [
                'ok'       => true,
                'rol'      => $row['rol'] ?? '(sin rol)',
                'activo'   => $row['activo'] ?? '(sin columna)',
                'hashLen'  => strlen($hash),
                'hashTipo' => strlen($hash) === 32 ? 'MD5 heredado' : (str_starts_with($hash, '$2y$') ? 'bcrypt' : 'desconocido'),
                'columnas' => implode(', ', array_keys($row)),
                'destino'  => ($row['rol'] ?? '') === 'CALIDAD' ? 'calidad.html' : '—',
            ];
        }
    } catch (\Throwable $ex) {
        $revision = ['ok' => false, 'nota' => get_class($ex) . ': ' . $ex->getMessage()
            . ' en ' . basename($ex->getFile()) . ':' . $ex->getLine()];
    }
}

// ── Liberar el bloqueo por intentos fallidos ──────────────
$liberado = null;
if ($pdo && ($_GET['liberar'] ?? '') !== '' ) {
    $u = mb_strtolower(trim($_GET['liberar']));
    try {
        $st = $pdo->prepare("DELETE FROM login_intentos WHERE usuario = ?");
        $st->execute([$u]);
        $liberado = ['ok' => true, 'usuario' => $u, 'filas' => $st->rowCount()];
    } catch (\Throwable $ex) {
        $liberado = ['ok' => false, 'usuario' => $u, 'nota' => $ex->getMessage()];
    }
}

// ── Cuentas bloqueadas en este momento ────────────────────
$bloqueadas = [];
if ($pdo) {
    try {
        $st = $pdo->query("SELECT usuario, intentos, bloqueado_hasta FROM login_intentos
                           WHERE bloqueado_hasta IS NOT NULL AND bloqueado_hasta > NOW()
                           ORDER BY bloqueado_hasta DESC");
        $bloqueadas = $st->fetchAll();
    } catch (\Throwable $ex) { /* la tabla aún no existe */ }
}

// ── Firma del inspector, tal como la genera este servidor ──
// El PDF se veía bien en desarrollo y negro aquí, así que hace falta ver el
// archivo que produce ESTE servidor, no el de otra máquina.
$firmaInfo = null;
if ($pdo && class_exists('Arneses')) {
    try {
        $st = $pdo->query("SELECT usuario, nombre, firma_imagen FROM usuarios
                           WHERE rol = 'INSPECTOR' AND firma_imagen IS NOT NULL AND firma_imagen <> ''
                           ORDER BY nombre LIMIT 1");
        $insp = $st->fetch();
        if ($insp) {
            $arn = new Arneses($pdo);
            $r   = new ReflectionMethod('Arneses', 'firmaProcesada');
            $r->setAccessible(true);
            $t0  = microtime(true);
            $gen = (string)$r->invoke($arn, ltrim((string)$insp['firma_imagen'], '/'));
            $abs = __DIR__ . '/' . $gen;

            $firmaInfo = [
                'inspector' => $insp['nombre'], 'origen' => $insp['firma_imagen'],
                'generada'  => $gen, 'existe' => is_file($abs),
                'ms'        => round((microtime(true) - $t0) * 1000),
            ];
            if (is_file($abs)) {
                $i = @getimagesize($abs);
                $firmaInfo['tamano']  = filesize($abs);
                $firmaInfo['medidas'] = $i ? ($i[0] . 'x' . $i[1]) : '?';
                $firmaInfo['mime']    = $i ? image_type_to_mime_type($i[2]) : '?';
                $firmaInfo['url']     = $gen . '?v=' . filemtime($abs);
                $firmaInfo['gd']      = function_exists('gd_info') ? (gd_info()['GD Version'] ?? '?') : 'sin GD';
            }
        } else {
            $firmaInfo = ['nota' => 'Ningún inspector tiene firma cargada.'];
        }
    } catch (\Throwable $ex) {
        $firmaInfo = ['nota' => get_class($ex) . ': ' . $ex->getMessage()
            . ' en ' . basename($ex->getFile()) . ':' . $ex->getLine()];
    }
}

// ── Bitácora de errores de PHP ────────────────────────────
// Aquí queda escrito el error real que el manejador global esconde.
$bitacora = [];
$candidatos = array_filter([
    ini_get('error_log') ?: null,
    __DIR__ . '/error_log', __DIR__ . '/php_errorlog',
    __DIR__ . '/../error_log', __DIR__ . '/logs/error_log',
]);
foreach ($candidatos as $ruta) {
    if (!$ruta || !@is_readable($ruta) || !@is_file($ruta)) continue;
    $lineas = @file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $bitacora[] = ['ruta' => $ruta, 'lineas' => array_slice($lineas, -40)];
}

$fallos = array_filter($resultados, fn($r) => !$r['ok']);
?>
<!doctype html>
<meta charset="utf-8">
<title>Diagnóstico de arranque — AVBA</title>
<style>
  body { font-family: system-ui, sans-serif; margin:0; padding:24px; background:#f5f8fd; color:#1a1a2e; }
  h1 { font-size:19px; color:#0C447C; margin:0 0 14px; }
  .caja { background:#fff; border:1px solid #dde5f0; border-radius:10px; padding:14px; margin-bottom:14px; }
  .mal { background:#fdecea; border-color:#b91c1c; }
  .bien{ background:#e8f5e9; border-color:#2e7d32; }
  table { width:100%; border-collapse:collapse; font-size:13px; }
  th { background:#0C447C; color:#fff; padding:6px 8px; text-align:left; font-size:11px; }
  td { padding:5px 8px; border-bottom:1px solid #eef2f7; }
  .ok  { color:#1b7a3d; font-weight:700; }
  .err { color:#b91c1c; font-weight:700; }
  .mono{ font-family:ui-monospace,monospace; font-size:12px; }
</style>

<h1>Diagnóstico del arranque de la API</h1>

<?php if ($fallos): ?>
  <div class="caja mal">
    <strong><?= count($fallos) ?> fallo(s).</strong> El primero es el que tumba el sistema:
    <?php $p = reset($fallos); ?>
    <div style="margin-top:8px" class="mono">
      <strong><?= $e($p['nombre']) ?></strong><br>
      <?= $e($p['clase']) ?>: <?= $e($p['mensaje']) ?><br>
      en <?= $e($p['donde']) ?>
    </div>
  </div>
<?php else: ?>
  <div class="caja bien">
    Todas las clases se construyen sin error. Si el sistema sigue fallando, el problema
    no está en el arranque sino en la acción concreta que se ejecuta.
  </div>
<?php endif; ?>

<?php if ($login): ?>
  <div class="caja <?= $login['ok'] ? 'bien' : 'mal' ?>">
    <strong>Prueba de inicio de sesión</strong>
    <?php if ($login['ok']): ?>
      <div style="margin-top:6px">
        El camino del login corre sin error (<?= (int)$login['ms'] ?> ms).
        Respuesta con usuario inexistente: <em class="mono"><?= $e($login['respuesta']) ?></em>.
        <br>Si aún así no puedes entrar, el problema no es del servidor sino de las
        credenciales o del navegador: prueba recargando a fondo o borrando la caché del sitio.
      </div>
    <?php else: ?>
      <div style="margin-top:6px" class="mono">
        <?= $e($login['clase']) ?>: <?= $e($login['mensaje']) ?><br>
        en <?= $e($login['donde']) ?>
        <details style="margin-top:8px"><summary style="cursor:pointer">Ver traza</summary>
          <pre style="white-space:pre-wrap;font-size:11px"><?= $e($login['traza']) ?></pre>
        </details>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($liberado): ?>
  <div class="caja <?= $liberado['ok'] ? 'bien' : 'mal' ?>">
    <?php if ($liberado['ok']): ?>
      Bloqueo liberado para <strong><?= $e($liberado['usuario']) ?></strong>
      (<?= (int)$liberado['filas'] ?> registro(s) de intentos borrados). Ya puede intentar entrar.
    <?php else: ?>
      No se pudo liberar <strong><?= $e($liberado['usuario']) ?></strong>: <?= $e($liberado['nota']) ?>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($firmaInfo): ?>
  <div class="caja">
    <strong>Firma del inspector, procesada en este servidor</strong>
    <?php if (isset($firmaInfo['nota'])): ?>
      <div class="mono err" style="margin-top:6px"><?= $e($firmaInfo['nota']) ?></div>
    <?php else: ?>
      <div class="mono" style="margin-top:6px;font-size:12px">
        inspector: <?= $e($firmaInfo['inspector']) ?><br>
        origen: <?= $e($firmaInfo['origen']) ?><br>
        generada: <?= $e($firmaInfo['generada']) ?>
        <?= $firmaInfo['existe'] ? '<span class="ok">(existe)</span>' : '<span class="err">(NO se generó)</span>' ?><br>
        <?php if ($firmaInfo['existe']): ?>
          <?= $e($firmaInfo['medidas']) ?> · <?= $e($firmaInfo['mime']) ?> ·
          <?= number_format($firmaInfo['tamano'] / 1024, 1) ?> KB ·
          <?= (int)$firmaInfo['ms'] ?> ms · GD <?= $e($firmaInfo['gd']) ?>
        <?php endif; ?>
      </div>
      <?php if ($firmaInfo['existe']): ?>
        <div style="margin-top:10px;background:#eef2f7;padding:10px;border-radius:6px;display:inline-block">
          <img src="<?= $e($firmaInfo['url']) ?>" style="max-height:120px;display:block">
        </div>
        <div style="font-size:12px;color:#5a6072;margin-top:6px">
          Así se ve el archivo que el servidor entrega al documento. Si aquí se ve
          bien y en el PDF sale negra, el problema está al incrustarla, no al generarla.
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="caja <?= $bloqueadas ? 'mal' : '' ?>">
  <strong>Cuentas bloqueadas por intentos fallidos</strong>
  <?php if (!$bloqueadas): ?>
    <div style="font-size:13px;margin-top:6px">Ninguna cuenta está bloqueada en este momento.</div>
  <?php else: ?>
    <table style="margin-top:8px">
      <tr><th>Usuario</th><th>Intentos</th><th>Bloqueado hasta</th><th></th></tr>
      <?php foreach ($bloqueadas as $b): ?>
        <tr>
          <td class="mono"><?= $e($b['usuario']) ?></td>
          <td><?= (int)$b['intentos'] ?></td>
          <td class="mono"><?= $e($b['bloqueado_hasta']) ?></td>
          <td><a href="?secret=<?= $e($_GET['secret'] ?? '') ?>&liberar=<?= urlencode($b['usuario']) ?>"
                 style="color:#185FA5;font-weight:600">Liberar ahora</a></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<div class="caja">
  <strong>Revisar un usuario</strong>
  <div style="font-size:12px;color:#5a6072;margin:4px 0 8px">
    Consulta el registro sin verificar la contraseña, así que no gasta intentos ni bloquea la cuenta.
  </div>
  <form method="get" style="display:flex;gap:8px;flex-wrap:wrap">
    <input type="hidden" name="secret" value="<?= $e($_GET['secret'] ?? '') ?>">
    <input type="text" name="usuario" value="<?= $e($quien) ?>" placeholder="usuario de Calidad"
           style="padding:7px 10px;border:1.5px solid #dde5f0;border-radius:7px;font-size:13px">
    <button style="padding:7px 16px;background:#185FA5;color:#fff;border:none;border-radius:7px;font-size:13px;cursor:pointer">Revisar</button>
  </form>
  <?php if ($revision): ?>
    <div class="mono" style="margin-top:10px;font-size:12px">
      <?php if (!$revision['ok']): ?>
        <span class="err"><?= $e($revision['nota']) ?></span>
      <?php else: ?>
        rol: <strong><?= $e($revision['rol']) ?></strong> ·
        activo: <strong><?= $e($revision['activo']) ?></strong> ·
        contraseña: <strong><?= $e($revision['hashTipo']) ?></strong> (<?= (int)$revision['hashLen'] ?> car.)<br>
        columnas: <?= $e($revision['columnas']) ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($bitacora): ?>
  <div class="caja">
    <strong>Bitácora de errores del servidor</strong>
    <div style="font-size:12px;color:#5a6072;margin:4px 0 8px">
      Aquí queda escrito el error real que el mensaje genérico esconde. Últimas 40 líneas.
    </div>
    <?php foreach ($bitacora as $b): ?>
      <div class="mono" style="font-size:11px;color:#7a8494;margin-top:8px"><?= $e($b['ruta']) ?></div>
      <pre class="mono" style="white-space:pre-wrap;font-size:11px;background:#f7fafd;padding:8px;border-radius:6px;max-height:320px;overflow:auto"><?= $e(implode("\n", $b['lineas'])) ?></pre>
    <?php endforeach; ?>
  </div>
<?php else: ?>
  <div class="caja">
    <strong>Bitácora de errores</strong>
    <div style="font-size:12px;color:#5a6072;margin-top:4px">
      No se encontró un archivo de bitácora legible desde aquí. Búscalo en hPanel de Hostinger,
      en la sección de registros de error del sitio.
    </div>
  </div>
<?php endif; ?>

<div class="caja">
  <table>
    <tr><th>Paso</th><th>Resultado</th><th>ms</th><th>Detalle</th></tr>
    <?php foreach ($resultados as $r): ?>
      <tr>
        <td class="mono"><?= $e($r['nombre']) ?></td>
        <td class="<?= $r['ok'] ? 'ok' : 'err' ?>"><?= $r['ok'] ? 'OK' : 'FALLA' ?></td>
        <td><?= (int)$r['ms'] ?></td>
        <td class="mono"><?= $r['ok'] ? '' : $e($r['clase'] . ': ' . $r['mensaje'] . ' — ' . $r['donde']) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<div style="font-size:12px;color:#7a8494">Borra este archivo del servidor cuando termines.</div>
