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
