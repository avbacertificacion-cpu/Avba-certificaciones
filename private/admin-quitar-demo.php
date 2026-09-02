<?php
/**
 * Retira del sistema real las plantas de demostración que hayan quedado de una
 * carga anterior (solo ADMIN).
 *
 * Borra de verdad, así que está construida para no llevarse nada por delante:
 *  - Enseña primero, planta por planta, exactamente cuántos extintores,
 *    inspecciones, reportes y cotizaciones se irían con ella.
 *  - El administrador marca cuáles quitar. Los nombres de la lista son de
 *    centros de trabajo reales, así que alguno podría ser hoy un cliente de
 *    verdad: por eso se elige a mano y no se borra "todo lo que coincida".
 *  - Pide escribir una palabra de confirmación.
 *  - Borra dentro de una transacción; si algo falla, no queda nada a medias.
 *  - Los archivos subidos (fotos de reportes, documentos) se eliminan al
 *    final, ya con el borrado confirmado: el disco no se puede deshacer.
 */
require_once '../config/config.php';
require_once '../config/roles-extra.php';
require_once '../config/modo-demo.php';
require_once '../config/plantas-demo.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== ROLE_ADMIN) {
    header('Location: ../public/login.html'); exit;
}
$nombreAdmin = $_SESSION['nombre'];
$uid = $_SESSION['usuario_id'];
@set_time_limit(0);

const PALABRA_CONFIRMACION = 'QUITAR';

$DIR_REPORTES   = __DIR__ . '/../uploads/reportes/';
$DIR_DOCUMENTOS = __DIR__ . '/../uploads/documentos/';

$errorGeneral = null;
$resultados   = null;
$totalBorrado = [];

/** ¿Existe esa tabla? Las de cotizaciones y fotos se crean al usarse. */
function hayTabla(PDO $pdo, string $tabla): bool {
    static $cache = [];
    if (isset($cache[$tabla])) return $cache[$tabla];
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE ?");
        $st->execute([$tabla]);
        $cache[$tabla] = (bool) $st->fetchColumn();
    } catch (Exception $e) { $cache[$tabla] = false; }
    return $cache[$tabla];
}

function contar(PDO $pdo, string $sql, array $params): int {
    try { $st = $pdo->prepare($sql); $st->execute($params); return (int) $st->fetchColumn(); }
    catch (Exception $e) { return 0; }
}

/** Todo lo que se iría con una empresa, contado antes de tocar nada. */
function inventario(PDO $pdo, int $empresaId): array {
    $inv = [
        'extintores'   => contar($pdo, "SELECT COUNT(*) FROM extintores WHERE empresa_id = ?", [$empresaId]),
        'inspecciones' => contar($pdo, "SELECT COUNT(*) FROM inspecciones WHERE extintor_id IN
                                        (SELECT id FROM extintores WHERE empresa_id = ?)", [$empresaId]),
        'reportes'     => contar($pdo, "SELECT COUNT(*) FROM reportes_mensuales WHERE empresa_id = ?", [$empresaId]),
        'usuarios'     => contar($pdo, "SELECT COUNT(*) FROM usuarios WHERE empresa_id = ?", [$empresaId]),
        'fotos'        => 0,
        'cotizaciones' => 0,
    ];
    if (hayTabla($pdo, 'reporte_fotos')) {
        $inv['fotos'] = contar($pdo, "SELECT COUNT(*) FROM reporte_fotos WHERE reporte_id IN
                                      (SELECT id FROM reportes_mensuales WHERE empresa_id = ?)", [$empresaId]);
    }
    if (hayTabla($pdo, 'cotizaciones')) {
        $inv['cotizaciones'] = contar($pdo, "SELECT COUNT(*) FROM cotizaciones WHERE empresa_id = ?", [$empresaId]);
    }
    return $inv;
}

// ─── Estado actual de cada planta de la lista ────────────────────────────────
$preview = [];
foreach (centros() as $c) {
    $st = $pdo->prepare("SELECT id, estado FROM empresas WHERE nombre = ?");
    $st->execute([$c['nombre']]);
    $emp = $st->fetch(PDO::FETCH_ASSOC);
    $preview[] = [
        'nombre'     => $c['nombre'],
        'empresa_id' => $emp['id'] ?? null,
        'estado'     => $emp['estado'] ?? null,
        'inv'        => $emp ? inventario($pdo, (int) $emp['id']) : null,
    ];
}
$encontradas = array_values(array_filter($preview, fn($p) => $p['empresa_id']));

// El gerente de demostración y a cuántas plantas sigue asignado
$stGer = $pdo->prepare("SELECT id, nombre FROM usuarios WHERE username = ?");
$stGer->execute([GERENTE_USERNAME]);
$gerente = $stGer->fetch(PDO::FETCH_ASSOC);
$gerentePlantas = ($gerente && hayTabla($pdo, 'gerente_empresas'))
    ? contar($pdo, "SELECT COUNT(*) FROM gerente_empresas WHERE gerente_id = ?", [$gerente['id']])
    : 0;

// ─── Borrado ─────────────────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['accion'] ?? '') === 'quitar') {
    $elegidas = array_map('intval', (array) ($_POST['empresas'] ?? []));
    $idsValidos = array_map(fn($p) => (int) $p['empresa_id'], $encontradas);
    $elegidas = array_values(array_intersect($elegidas, $idsValidos));
    $quitarGerente = !empty($_POST['quitar_gerente']);

    if (strtoupper(trim($_POST['confirmacion'] ?? '')) !== PALABRA_CONFIRMACION) {
        $errorGeneral = 'Escribe ' . PALABRA_CONFIRMACION . ' para confirmar el borrado.';
    } elseif (!$elegidas && !$quitarGerente) {
        $errorGeneral = 'No marcaste ninguna planta.';
    } else {
        $archivos = [];   // se borran del disco hasta que la transacción cierre bien
        try {
            $pdo->beginTransaction();
            $resultados = [];

            foreach ($elegidas as $eid) {
                $nombre = $pdo->prepare("SELECT nombre FROM empresas WHERE id = ?");
                $nombre->execute([$eid]);
                $nombreEmp = $nombre->fetchColumn();
                $inv = inventario($pdo, $eid);

                // Fotos de los reportes: primero los nombres de archivo, luego las filas
                if (hayTabla($pdo, 'reporte_fotos')) {
                    $st = $pdo->prepare("SELECT archivo FROM reporte_fotos WHERE reporte_id IN
                                         (SELECT id FROM reportes_mensuales WHERE empresa_id = ?)");
                    $st->execute([$eid]);
                    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $a) $archivos[] = $DIR_REPORTES . basename($a);
                    $pdo->prepare("DELETE FROM reporte_fotos WHERE reporte_id IN
                                   (SELECT id FROM reportes_mensuales WHERE empresa_id = ?)")->execute([$eid]);
                }

                // Cotizaciones de la planta, con sus partidas y documentos adjuntos
                if (hayTabla($pdo, 'cotizaciones')) {
                    $st = $pdo->prepare("SELECT id FROM cotizaciones WHERE empresa_id = ?");
                    $st->execute([$eid]);
                    $cots = $st->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($cots as $cid) {
                        if (hayTabla($pdo, 'cotizacion_items')) {
                            $pdo->prepare("DELETE FROM cotizacion_items WHERE cotizacion_id = ?")->execute([$cid]);
                        }
                        if (hayTabla($pdo, 'documentos')) {
                            $sd = $pdo->prepare("SELECT archivo FROM documentos WHERE modulo = 'cotizacion' AND registro_id = ?");
                            $sd->execute([$cid]);
                            foreach ($sd->fetchAll(PDO::FETCH_COLUMN) as $a) $archivos[] = $DIR_DOCUMENTOS . basename($a);
                            $pdo->prepare("DELETE FROM documentos WHERE modulo = 'cotizacion' AND registro_id = ?")->execute([$cid]);
                        }
                    }
                    $pdo->prepare("DELETE FROM cotizaciones WHERE empresa_id = ?")->execute([$eid]);
                }

                // Inspecciones antes que extintores: cuelgan de ellos
                $pdo->prepare("DELETE FROM inspecciones WHERE extintor_id IN
                               (SELECT id FROM extintores WHERE empresa_id = ?)")->execute([$eid]);
                $pdo->prepare("DELETE FROM extintores WHERE empresa_id = ?")->execute([$eid]);
                $pdo->prepare("DELETE FROM reportes_mensuales WHERE empresa_id = ?")->execute([$eid]);
                if (hayTabla($pdo, 'gerente_empresas')) {
                    $pdo->prepare("DELETE FROM gerente_empresas WHERE empresa_id = ?")->execute([$eid]);
                }
                // Usuarios cliente que sólo existían para esa planta
                $pdo->prepare("DELETE FROM usuarios WHERE empresa_id = ?")->execute([$eid]);
                $pdo->prepare("DELETE FROM empresas WHERE id = ?")->execute([$eid]);

                $resultados[] = ['nombre' => $nombreEmp, 'inv' => $inv];
                foreach ($inv as $k => $v) $totalBorrado[$k] = ($totalBorrado[$k] ?? 0) + $v;
            }

            if ($quitarGerente && $gerente) {
                if (hayTabla($pdo, 'gerente_empresas')) {
                    $pdo->prepare("DELETE FROM gerente_empresas WHERE gerente_id = ?")->execute([$gerente['id']]);
                }
                $pdo->prepare("DELETE FROM usuarios WHERE id = ?")->execute([$gerente['id']]);
            }

            $st = $pdo->prepare("INSERT INTO auditoria (usuario_id,accion,tabla,registro_id,ip) VALUES (?,?,?,?,?)");
            $st->execute([$uid, 'Quitar ' . count($elegidas) . ' plantas de demostración del sistema',
                          'empresas', null, $_SERVER['REMOTE_ADDR'] ?? null]);

            $pdo->commit();

            // Ya sin vuelta atrás en la base: ahora sí, los archivos
            foreach (array_unique($archivos) as $ruta) {
                if (is_file($ruta)) @unlink($ruta);
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errorGeneral = 'No se pudo completar el borrado (no se quitó nada): ' . $e->getMessage();
            $resultados = null;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Quitar datos de demostración</title>
<style>
    *{margin:0;padding:0;box-sizing:border-box}
    body{font-family:'Segoe UI',system-ui,sans-serif;background:#eef2fb;color:#1a2138}
    .navbar{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:16px 26px;
            display:flex;justify-content:space-between;align-items:center;box-shadow:0 4px 16px rgba(102,126,234,.2)}
    .navbar a{color:#fff;text-decoration:none;font-size:13px;opacity:.9}.navbar a:hover{opacity:1;text-decoration:underline}
    .container{max-width:1060px;margin:0 auto;padding:26px 20px}
    h2{font-size:24px;color:#1e293b}
    .sub{color:#64748b;font-size:13px;margin-bottom:20px}
    .card{background:#fff;border-radius:14px;padding:20px;box-shadow:0 4px 14px rgba(30,41,59,.08);margin-bottom:20px}
    table{width:100%;border-collapse:collapse}
    thead{background:#f1f5fb}
    th{padding:10px 9px;text-align:left;font-size:11px;color:#475569;font-weight:700;text-transform:uppercase}
    td{padding:10px 9px;font-size:13px;border-bottom:1px solid #f1f5f9}
    td.n,th.n{text-align:right}
    tbody tr:hover{background:#f8faff}
    .badge{padding:4px 10px;border-radius:20px;font-size:11px;font-weight:700;display:inline-block}
    .b-hay{background:#fee2e2;color:#b91c1c}.b-no{background:#e2e8f0;color:#475569}
    .btn{padding:11px 20px;border:none;border-radius:8px;font-weight:700;cursor:pointer;font-size:14px}
    .btn-danger{background:#dc2626;color:#fff}.btn-danger:hover{background:#b91c1c}
    .btn-danger:disabled{background:#fca5a5;cursor:not-allowed}
    .btn-ghost{background:#eef2fb;color:#475569;text-decoration:none;display:inline-block}
    .alerta{padding:14px 16px;border-radius:10px;font-size:13px;margin-bottom:18px;line-height:1.6}
    .a-err{background:#fee2e2;color:#b91c1c}
    .a-ok{background:#d1fae5;color:#047857}
    .a-info{background:#e0e7ff;color:#3730a3}
    .a-warn{background:#fef3c7;color:#92400e}
    .fg{margin:16px 0}
    .fg label{display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:6px}
    .fg input[type=text]{width:220px;padding:10px;border:2px solid #e0e0ff;border-radius:8px;font-size:15px;
                         font-weight:700;letter-spacing:2px;text-transform:uppercase}
    .chk{display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer}
    .vacio{text-align:center;padding:44px 20px;color:#64748b}.vacio .ic{font-size:52px;margin-bottom:12px}
</style>
</head>
<body>
<?= cintaDemo() ?>
<div class="navbar">
    <a href="admin-dashboard.php">← Panel Admin</a>
    <span style="font-size:13px">👤 <?= htmlspecialchars($nombreAdmin) ?></span>
</div>

<div class="container">
    <h2>🧹 Quitar datos de demostración</h2>
    <div class="sub">Retira del sistema las plantas de ejemplo y todo lo que cuelga de ellas. La información real no se toca.</div>

    <?php if ($errorGeneral): ?>
        <div class="alerta a-err">⚠️ <?= htmlspecialchars($errorGeneral) ?></div>
    <?php endif; ?>

    <?php if ($resultados !== null): ?>
        <div class="card">
            <div class="alerta a-ok">✓ Listo. Se quitaron <?= count($resultados) ?> planta(s) de demostración.</div>
            <table>
                <thead><tr><th>Planta</th><th class="n">Extintores</th><th class="n">Inspecciones</th>
                <th class="n">Reportes</th><th class="n">Cotizaciones</th></tr></thead>
                <tbody>
                <?php foreach ($resultados as $r): ?>
                    <tr>
                        <td><?= htmlspecialchars($r['nombre']) ?></td>
                        <td class="n"><?= $r['inv']['extintores'] ?></td>
                        <td class="n"><?= $r['inv']['inspecciones'] ?></td>
                        <td class="n"><?= $r['inv']['reportes'] ?></td>
                        <td class="n"><?= $r['inv']['cotizaciones'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <?php if ($totalBorrado): ?>
                <tfoot><tr style="font-weight:800;background:#f8faff">
                    <td>Total</td>
                    <td class="n"><?= $totalBorrado['extintores'] ?? 0 ?></td>
                    <td class="n"><?= $totalBorrado['inspecciones'] ?? 0 ?></td>
                    <td class="n"><?= $totalBorrado['reportes'] ?? 0 ?></td>
                    <td class="n"><?= $totalBorrado['cotizaciones'] ?? 0 ?></td>
                </tr></tfoot>
                <?php endif; ?>
            </table>
        </div>
        <a class="btn btn-ghost" href="admin-quitar-demo.php">Volver a revisar</a>
        <a class="btn btn-ghost" href="admin-dashboard.php">Ir al panel</a>

    <?php elseif (!$encontradas && !$gerente): ?>
        <div class="card">
            <div class="vacio">
                <div class="ic">✅</div>
                <p><b>No hay datos de demostración en este sistema.</b></p>
                <p style="margin-top:8px">Ninguna de las 14 plantas de ejemplo está dada de alta, y tampoco existe el usuario gerente de demostración.</p>
            </div>
        </div>

    <?php else: ?>
        <div class="alerta a-warn">
            <b>Antes de marcar:</b> los nombres de esta lista son centros de trabajo reales, así que
            alguno podría ser hoy un cliente de verdad. Revisa los números de cada renglón y marca
            sólo las que efectivamente sean de la demostración. Lo que se quite no se puede recuperar.
        </div>

        <form method="post">
            <input type="hidden" name="accion" value="quitar">

            <?php if ($encontradas): ?>
            <div class="card">
                <table>
                    <thead><tr>
                        <th style="width:34px"><input type="checkbox" id="todas" onclick="marcarTodas(this)" checked></th>
                        <th>Planta</th><th class="n">Extintores</th><th class="n">Inspecciones</th>
                        <th class="n">Reportes</th><th class="n">Fotos</th><th class="n">Cotiz.</th>
                        <th class="n">Usuarios</th><th>Estado</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($preview as $p): ?>
                        <?php if (!$p['empresa_id']) continue; ?>
                        <tr>
                            <td><input type="checkbox" class="cb" name="empresas[]" value="<?= $p['empresa_id'] ?>" checked></td>
                            <td style="font-weight:600"><?= htmlspecialchars($p['nombre']) ?></td>
                            <td class="n"><?= $p['inv']['extintores'] ?></td>
                            <td class="n"><?= $p['inv']['inspecciones'] ?></td>
                            <td class="n"><?= $p['inv']['reportes'] ?></td>
                            <td class="n"><?= $p['inv']['fotos'] ?></td>
                            <td class="n"><?= $p['inv']['cotizaciones'] ?></td>
                            <td class="n"><?= $p['inv']['usuarios'] ?></td>
                            <td style="color:#64748b;font-size:12px"><?= htmlspecialchars((string) $p['estado']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <div class="alerta a-info">Ninguna de las 14 plantas de ejemplo está dada de alta en este sistema.</div>
            <?php endif; ?>

            <div class="card">
                <?php if ($gerente): ?>
                    <label class="chk">
                        <input type="checkbox" name="quitar_gerente" value="1" <?= $encontradas ? 'checked' : '' ?>>
                        Quitar también el usuario gerente de demostración
                        (<b><?= htmlspecialchars(GERENTE_USERNAME) ?></b>, asignado a <?= $gerentePlantas ?> planta(s))
                    </label>
                <?php else: ?>
                    <div class="alerta a-info" style="margin:0">El usuario gerente de demostración no existe en este sistema.</div>
                <?php endif; ?>

                <div class="fg">
                    <label>Para confirmar, escribe <b><?= PALABRA_CONFIRMACION ?></b></label>
                    <input type="text" name="confirmacion" autocomplete="off" placeholder="<?= PALABRA_CONFIRMACION ?>">
                </div>

                <button type="submit" class="btn btn-danger"
                        onclick="return confirm('Se quitarán las plantas marcadas y todo su historial. Esta acción no se puede deshacer. ¿Continuar?')">
                    Quitar lo marcado
                </button>
                <a class="btn btn-ghost" href="admin-dashboard.php" style="padding:11px 20px">Cancelar</a>
            </div>
        </form>

        <script>
        function marcarTodas(o) { document.querySelectorAll('.cb').forEach(c => c.checked = o.checked); }
        </script>
    <?php endif; ?>
</div>
</body>
</html>
