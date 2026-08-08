<?php
/**
 * AVBA — Reasignación de códigos QR de personal a la serie 7
 * ==========================================================
 *
 * Los participantes registrados a partir de una fecha de corte conservan su
 * folio (número de control) y sus documentos, pero se les reasigna el código QR
 * a la nueva nomenclatura: serie 7, reiniciando el conteo desde el primer
 * número libre de la serie.
 *
 * Uso (desde el navegador, con la cuenta de administración a mano):
 *
 *   1. SIMULACIÓN — no toca nada, sólo muestra qué cambiaría:
 *      /mantenimiento_qr_personal.php?secret=CLAVE
 *
 *   2. APLICAR — ejecuta los cambios dentro de una transacción:
 *      /mantenimiento_qr_personal.php?secret=CLAVE&aplicar=1
 *
 *   Parámetros opcionales:
 *      &desde=2026-07-28   fecha de corte (por omisión, la de abajo)
 *      &todos=1            incluye también a los que ya están en la serie 7
 *
 * BORRAR ESTE ARCHIVO DEL SERVIDOR CUANDO TERMINE LA REASIGNACIÓN.
 */

declare(strict_types=1);

const SECRETO_MANTENIMIENTO = 'avba_qr_2026';
const FECHA_CORTE_DEFECTO   = '2026-07-28';

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/api/helpers.php';

header('Content-Type: text/html; charset=utf-8');

if (($_GET['secret'] ?? '') !== SECRETO_MANTENIMIENTO) {
    http_response_code(403);
    exit('No autorizado.');
}

$desde   = trim($_GET['desde'] ?? '') ?: FECHA_CORTE_DEFECTO;
$aplicar = ($_GET['aplicar'] ?? '') === '1';
$todos   = ($_GET['todos'] ?? '') === '1';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) {
    exit('La fecha de corte debe venir como aaaa-mm-dd.');
}

$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

/**
 * Códigos de la serie 7 ya ocupados por cualquier registro del sistema. Se
 * cargan de una vez para poder recorrer la serie sin una consulta por número.
 */
function ocupadosSerie7(PDO $pdo): array {
    $ocupados = [];
    $fuentes = [
        ['qr_codigos',           'identificador', 'usado = 1'],
        ['participantes_cursos', 'qr_codigo',     '1=1'],
        ['equipos',              'qr_codigo',     '1=1'],
        ['accesorios_sesiones',  'qr_codigo',     '1=1'],
        ['accesorios_izaje',     'qr_codigo',     '1=1'],
        ['arneses_sesiones',     'qr_codigo',     '1=1'],
        ['arneses_items',        'qr_codigo',     '1=1'],
    ];
    foreach ($fuentes as [$tabla, $col, $cond]) {
        try {
            $st = $pdo->prepare(
                "SELECT DISTINCT `$col` FROM `$tabla`
                 WHERE $cond AND `$col` LIKE ? AND LENGTH(`$col`) = 10"
            );
            $st->execute([QR_PREFIJO_PERSONAL . '%']);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $q) {
                if ($q !== null && $q !== '') $ocupados[(string)$q] = true;
            }
        } catch (\Throwable $ex) { /* tabla o columna aún no existe */ }
    }
    return $ocupados;
}

try {
    // ── Participantes afectados ──────────────────────────────
    $cond = $todos ? '' : " AND (p.qr_codigo IS NULL OR p.qr_codigo NOT LIKE '" . QR_PREFIJO_PERSONAL . "%')";
    $st = $pdo->prepare(
        "SELECT p.id, p.nombre_completo, p.curp, p.control, p.qr_codigo, p.estatus,
                p.empresa_nombre, p.fecha_registro,
                (SELECT COUNT(*) FROM participantes_documentos d WHERE d.participante_id = p.id) AS docs
         FROM participantes_cursos p
         WHERE p.fecha_registro >= ?
           AND p.qr_codigo IS NOT NULL AND p.qr_codigo <> ''
           $cond
         ORDER BY p.id"
    );
    $st->execute([$desde . ' 00:00:00']);
    $filas = $st->fetchAll(PDO::FETCH_ASSOC);

    // ── Asignación de la nueva serie ─────────────────────────
    // Se recorre la serie 7 desde su primer número, saltando los ocupados: así
    // el conteo arranca de nuevo sin pisar códigos legítimos ya emitidos.
    $ocupados = ocupadosSerie7($pdo);
    $base     = (int)(QR_PREFIJO_PERSONAL . '000000000');
    $cursor   = $base;
    $plan     = [];

    foreach ($filas as $f) {
        do {
            $cursor++;
            $nuevo = str_pad((string)$cursor, 10, '0', STR_PAD_LEFT);
        } while (isset($ocupados[$nuevo]));

        if ($cursor >= (int)(QR_PREFIJO_PERSONAL . '999999999')) {
            throw new RuntimeException('La serie ' . QR_PREFIJO_PERSONAL . ' se agotó.');
        }
        $ocupados[$nuevo] = true;
        $plan[] = ['fila' => $f, 'anterior' => (string)$f['qr_codigo'], 'nuevo' => $nuevo];
    }

    // ── Aplicar ──────────────────────────────────────────────
    $aplicados = 0;
    if ($aplicar && $plan) {
        $pdo->beginTransaction();
        try {
            $updPart  = $pdo->prepare("UPDATE participantes_cursos SET qr_codigo = ? WHERE id = ?");
            $liberar  = $pdo->prepare("UPDATE qr_codigos SET usado = 0, equipo_id = NULL WHERE identificador = ?");
            foreach ($plan as $p) {
                $updPart->execute([$p['nuevo'], $p['fila']['id']]);
                // El código viejo vuelve al banco para que lo tome el dominio
                // que le corresponde por su prefijo.
                $liberar->execute([$p['anterior']]);
                qrRegistrarUsado($pdo, $p['nuevo'], null);
                $aplicados++;
            }
            $pdo->commit();
        } catch (\Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
    }
} catch (\Throwable $ex) {
    http_response_code(500);
    exit('<pre>Error: ' . $e($ex->getMessage()) . '</pre>');
}

$conDocs = 0;
foreach ($plan as $p) $conDocs += ((int)$p['fila']['docs'] > 0 ? 1 : 0);
?>
<!doctype html>
<meta charset="utf-8">
<title>Reasignación de QR de personal — AVBA</title>
<style>
  body { font-family: system-ui, sans-serif; margin: 0; padding: 24px; background: #f5f8fd; color: #1a1a2e; }
  h1 { font-size: 19px; color: #0C447C; margin: 0 0 4px; }
  .sub { font-size: 13px; color: #5a6072; margin-bottom: 18px; }
  .caja { background: #fff; border: 1px solid #dde5f0; border-radius: 10px; padding: 16px; margin-bottom: 14px; }
  .aviso { background: #fff8e1; border-color: #f59e0b; }
  .ok { background: #e8f5e9; border-color: #2e7d32; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  th { background: #0C447C; color: #fff; padding: 7px 8px; text-align: left; font-size: 11px; letter-spacing: .5px; }
  td { padding: 6px 8px; border-bottom: 1px solid #eef2f7; }
  tr:nth-child(even) td { background: #fafbfc; }
  .mono { font-family: ui-monospace, monospace; }
  .viejo { color: #b91c1c; text-decoration: line-through; }
  .nuevo { color: #1b7a3d; font-weight: 700; }
  a.btn { display: inline-block; background: #185FA5; color: #fff; text-decoration: none;
          padding: 9px 16px; border-radius: 7px; font-size: 13px; font-weight: 600; }
</style>

<h1>Reasignación de códigos QR de personal a la serie <?= $e(QR_PREFIJO_PERSONAL) ?></h1>
<div class="sub">
  Corte: registros con fecha de alta desde <strong><?= $e($desde) ?></strong>.
  Los folios (números de control) <strong>no se modifican</strong>.
</div>

<?php if ($aplicar): ?>
  <div class="caja ok">
    <strong>Aplicado.</strong> Se reasignaron <?= $aplicados ?> código(s).
    Los códigos anteriores volvieron al banco y quedan disponibles.
  </div>
<?php elseif ($plan): ?>
  <div class="caja aviso">
    <strong>Esto es una simulación: todavía no se ha modificado nada.</strong><br>
    Se reasignarían <?= count($plan) ?> código(s).
    <p style="margin:12px 0 0">
      <a class="btn" href="?secret=<?= $e($_GET['secret']) ?>&desde=<?= $e($desde) ?><?= $todos ? '&todos=1' : '' ?>&aplicar=1">
        Aplicar los <?= count($plan) ?> cambios
      </a>
    </p>
  </div>
<?php else: ?>
  <div class="caja ok">
    No hay participantes que reasignar con este corte.
    <?php if (!$todos): ?>
      Los registrados desde esa fecha ya están en la serie <?= $e(QR_PREFIJO_PERSONAL) ?>.
      Para reasignarlos de todos modos, agrega <span class="mono">&amp;todos=1</span>.
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($conDocs): ?>
  <div class="caja aviso">
    <strong><?= $conDocs ?> participante(s) ya tienen documentos generados.</strong>
    Su DC-3, diploma, certificado o credencial trae impreso el código anterior, así que
    hay que volver a generarlos desde el módulo de Personal para que el QR impreso
    coincida con el nuevo.
  </div>
<?php endif; ?>

<?php if ($plan): ?>
<div class="caja">
  <table>
    <tr>
      <th>#</th><th>Participante</th><th>Empresa</th><th>Folio</th>
      <th>QR anterior</th><th>QR nuevo</th><th>Estatus</th><th>Docs</th>
    </tr>
    <?php foreach ($plan as $i => $p): $f = $p['fila']; ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td><?= $e($f['nombre_completo']) ?></td>
        <td><?= $e($f['empresa_nombre'] ?: '—') ?></td>
        <td class="mono"><?= $e($f['control'] ?: '—') ?></td>
        <td class="mono viejo"><?= $e($p['anterior']) ?></td>
        <td class="mono nuevo"><?= $e($p['nuevo']) ?></td>
        <td><?= $e($f['estatus'] ?: '—') ?></td>
        <td><?= (int)$f['docs'] ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>

<div class="sub">Borra este archivo del servidor cuando termines.</div>
