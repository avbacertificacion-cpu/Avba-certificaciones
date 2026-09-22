<?php
/**
 * Retira lo que la siembra de demostración dejó en el sistema (solo ADMIN).
 *
 * La diferencia importante frente a "borrar las plantas de ejemplo": varios de
 * esos catorce centros son clientes de verdad que YA tenían extintores,
 * usuarios, reportes y cotizaciones antes de que se sembrara nada. A ellos la
 * siembra sólo les agregó extintores de ejemplo con su historial de
 * inspecciones. Esta pantalla quita exactamente eso y nada más:
 *
 *  - Se van los extintores sembrados (reconocidos uno por uno, ver
 *    config/huella-demo.php) y las inspecciones que cuelgan de ellos.
 *  - Se quedan los extintores que ya estaban, con su historial, y también los
 *    reportes, las fotos, las cotizaciones, los documentos y los usuarios de
 *    la planta: la siembra nunca creó nada de eso.
 *  - La planta sólo se da de baja cuando al quitar lo sembrado no le queda
 *    absolutamente nada propio; si le queda algo, la empresa se conserva.
 *  - El usuario gerente de demostración se quita aparte, si se marca.
 *
 * Antes de confirmar se enseña, planta por planta, qué se va y qué se queda.
 */
require_once '../config/config.php';
require_once '../config/roles-extra.php';
require_once '../config/modo-demo.php';
require_once '../config/plantas-demo.php';
require_once '../config/huella-demo.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== ROLE_ADMIN) {
    header('Location: ../public/login.html'); exit;
}
$nombreAdmin = $_SESSION['nombre'];
$uid = $_SESSION['usuario_id'];
@set_time_limit(0);

const PALABRA_CONFIRMACION = 'QUITAR';

$errorGeneral = null;
$resultados   = null;
$totalBorrado = [];

/** Estado de cada centro de la lista: qué sembró la demo ahí y qué había ya. */
function revisarCentros(PDO $pdo): array {
    $preview = [];
    foreach (centros() as $c) {
        $st = $pdo->prepare("SELECT id, estado FROM empresas WHERE nombre = ?");
        $st->execute([$c['nombre']]);
        $emp = $st->fetch(PDO::FETCH_ASSOC);
        $preview[] = [
            'nombre'     => $c['nombre'],
            'centro'     => $c,
            'empresa_id' => $emp ? (int) $emp['id'] : null,
            'estado'     => $emp['estado'] ?? null,
            'huella'     => $emp ? huellaDeEmpresa($pdo, $c, (int) $emp['id']) : null,
        ];
    }
    return $preview;
}

$preview = revisarCentros($pdo);
// Sólo tiene sentido ofrecer las plantas donde la siembra dejó algo
$conHuella = array_values(array_filter($preview, fn($p) => $p['huella'] && $p['huella']['extintores'] > 0));
$presentes = array_values(array_filter($preview, fn($p) => $p['empresa_id']));

// El gerente de demostración y a cuántas plantas sigue asignado
$stGer = $pdo->prepare("SELECT id, nombre FROM usuarios WHERE username = ?");
$stGer->execute([GERENTE_USERNAME]);
$gerente = $stGer->fetch(PDO::FETCH_ASSOC);
$gerentePlantas = ($gerente && huellaHayTablaSuelta($pdo, 'gerente_empresas'))
    ? huellaContar($pdo, "SELECT COUNT(*) FROM gerente_empresas WHERE gerente_id = ?", [$gerente['id']])
    : 0;

// ─── Borrado ─────────────────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['accion'] ?? '') === 'quitar') {
    $elegidas   = array_map('intval', (array) ($_POST['empresas'] ?? []));
    $idsValidos = array_map(fn($p) => $p['empresa_id'], $conHuella);
    $elegidas   = array_values(array_intersect($elegidas, $idsValidos));
    $quitarGerente = !empty($_POST['quitar_gerente']);

    if (strtoupper(trim($_POST['confirmacion'] ?? '')) !== PALABRA_CONFIRMACION) {
        $errorGeneral = 'Escribe ' . PALABRA_CONFIRMACION . ' para confirmar.';
    } elseif (!$elegidas && !$quitarGerente) {
        $errorGeneral = 'No marcaste ninguna planta.';
    } else {
        try {
            $pdo->beginTransaction();
            $resultados = [];

            foreach ($elegidas as $eid) {
                $p = null;
                foreach ($conHuella as $c) if ($c['empresa_id'] === $eid) $p = $c;
                if (!$p) continue;

                // Se vuelve a identificar dentro de la transacción: lo que se
                // borra es lo que se acaba de comprobar, no lo que se vio al
                // pintar la pantalla hace un rato.
                $h   = huellaDeEmpresa($pdo, $p['centro'], $eid);
                $ids = $h['ids'];
                if (!$ids) continue;

                $marcas = implode(',', array_fill(0, count($ids), '?'));
                $pdo->prepare("DELETE FROM inspecciones WHERE extintor_id IN ($marcas)")->execute($ids);
                $pdo->prepare("DELETE FROM extintores WHERE id IN ($marcas)")->execute($ids);

                // La empresa sólo se va si no le quedó nada propio
                $empresaBorrada = false;
                if ($h['borrar_empresa']) {
                    if (huellaHayTablaSuelta($pdo, 'gerente_empresas')) {
                        $pdo->prepare("DELETE FROM gerente_empresas WHERE empresa_id = ?")->execute([$eid]);
                    }
                    $pdo->prepare("DELETE FROM empresas WHERE id = ?")->execute([$eid]);
                    $empresaBorrada = true;
                }
                if (huellaHayTabla($pdo)) {
                    $pdo->prepare("DELETE FROM demo_siembra WHERE empresa_id = ?")->execute([$eid]);
                }

                $resultados[] = [
                    'nombre'  => $p['nombre'],
                    'huella'  => $h,
                    'empresa' => $empresaBorrada,
                ];
                $totalBorrado['extintores']   = ($totalBorrado['extintores']   ?? 0) + $h['extintores'];
                $totalBorrado['inspecciones'] = ($totalBorrado['inspecciones'] ?? 0) + $h['inspecciones'];
                $totalBorrado['empresas']     = ($totalBorrado['empresas']     ?? 0) + ($empresaBorrada ? 1 : 0);
            }

            if ($quitarGerente && $gerente) {
                if (huellaHayTablaSuelta($pdo, 'gerente_empresas')) {
                    $pdo->prepare("DELETE FROM gerente_empresas WHERE gerente_id = ?")->execute([$gerente['id']]);
                }
                $pdo->prepare("DELETE FROM usuarios WHERE id = ?")->execute([$gerente['id']]);
            }

            $pdo->prepare("INSERT INTO auditoria (usuario_id,accion,tabla,registro_id,ip) VALUES (?,?,?,?,?)")
                ->execute([$uid,
                    'Quitar lo sembrado por la demo en ' . count($resultados) . ' planta(s): '
                    . ($totalBorrado['extintores'] ?? 0) . ' extintores',
                    'extintores', null, $_SERVER['REMOTE_ADDR'] ?? null]);

            $pdo->commit();
            $preview   = revisarCentros($pdo);
            $conHuella = array_values(array_filter($preview, fn($p) => $p['huella'] && $p['huella']['extintores'] > 0));
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errorGeneral = 'No se pudo completar (no se quitó nada): ' . $e->getMessage();
            $resultados = null;
        }
    }
}

/** Resumen de una línea de lo que se queda en una planta. */
function frasePropios(array $pr): string {
    $partes = [];
    foreach ([['extintores', 'extintor', 'extintores'], ['inspecciones', 'inspección', 'inspecciones'],
              ['usuarios', 'usuario', 'usuarios'], ['reportes', 'reporte', 'reportes'],
              ['cotizaciones', 'cotización', 'cotizaciones']] as [$k, $uno, $varios]) {
        if (!empty($pr[$k])) $partes[] = $pr[$k] . ' ' . ($pr[$k] == 1 ? $uno : $varios);
    }
    return $partes ? implode(' · ', $partes) : '—';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Quitar datos de demostración</title>
<link rel="stylesheet" href="../public/assets/css/movil.css">
<style>
    *{margin:0;padding:0;box-sizing:border-box}
    body{font-family:'Segoe UI',system-ui,sans-serif;background:#eef2fb;color:#1a2138}
    .navbar{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:16px 26px;
            display:flex;justify-content:space-between;align-items:center;box-shadow:0 4px 16px rgba(102,126,234,.2)}
    .navbar a{color:#fff;text-decoration:none;font-size:13px;opacity:.9}.navbar a:hover{opacity:1;text-decoration:underline}
    .container{max-width:1100px;margin:0 auto;padding:26px 20px}
    h2{font-size:24px;color:#1e293b}
    .sub{color:#64748b;font-size:13px;margin-bottom:20px}
    .card{background:#fff;border-radius:14px;padding:20px;box-shadow:0 4px 14px rgba(30,41,59,.08);margin-bottom:20px}
    .tabla-env{overflow-x:auto}
    table{width:100%;border-collapse:collapse}
    thead{background:#f1f5fb}
    th{padding:10px 9px;text-align:left;font-size:11px;color:#475569;font-weight:700;text-transform:uppercase}
    td{padding:10px 9px;font-size:13px;border-bottom:1px solid #f1f5f9;vertical-align:top}
    td.n,th.n{text-align:right}
    .se-va{color:#b91c1c;font-weight:700}
    .se-queda{color:#047857}
    .nota{font-size:11px;color:#64748b;display:block;margin-top:3px}
    .badge{padding:4px 10px;border-radius:20px;font-size:11px;font-weight:700;display:inline-block}
    .b-baja{background:#fee2e2;color:#b91c1c}.b-queda{background:#d1fae5;color:#047857}
    .b-exacto{background:#e0e7ff;color:#3730a3}.b-indicios{background:#fef3c7;color:#92400e}
    .btn{padding:11px 20px;border:none;border-radius:8px;font-weight:700;cursor:pointer;font-size:14px}
    .btn-danger{background:#dc2626;color:#fff}.btn-danger:hover{background:#b91c1c}
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
    .chk{display:flex;align-items:flex-start;gap:9px;font-size:13px;line-height:1.55;cursor:pointer}
    .chk input{margin-top:3px;flex:none}
    .vacio{text-align:center;padding:44px 20px;color:#64748b}.vacio .ic{font-size:52px;margin-bottom:12px}
    @media (max-width:760px){
        .tabla thead{display:none}
        .tabla tr{display:block;border:1px solid #e8ecf7;border-radius:10px;margin-bottom:10px;padding:8px}
        .tabla td{display:flex;justify-content:space-between;gap:12px;border:0;padding:6px 4px;text-align:right}
        .tabla td::before{content:attr(data-et);font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;text-align:left}
        .tabla td.planta{display:block;text-align:left;border-bottom:1px solid #f1f5f9;padding-bottom:8px;margin-bottom:4px}
        .tabla td.planta::before{content:none}
        .tabla td.marca{display:block;text-align:left}
        .tabla td.marca::before{content:none}
    }
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
    <div class="sub">Se retiran únicamente los extintores que sembró la demostración y su historial. Lo que ya tenías se queda.</div>

    <?php if ($errorGeneral): ?>
        <div class="alerta a-err">⚠️ <?= htmlspecialchars($errorGeneral) ?></div>
    <?php endif; ?>

    <?php if ($resultados !== null): ?>
        <div class="card">
            <div class="alerta a-ok">✓ Listo. Se limpiaron <?= count($resultados) ?> planta(s).</div>
            <div class="tabla-env"><table class="tabla">
                <thead><tr><th>Planta</th><th class="n">Extintores quitados</th>
                <th class="n">Inspecciones quitadas</th><th>Lo que se conservó</th></tr></thead>
                <tbody>
                <?php foreach ($resultados as $r): ?>
                    <tr>
                        <td class="planta" data-et="Planta"><?= htmlspecialchars($r['nombre']) ?>
                            <?php if ($r['empresa']): ?><span class="nota">La planta se dio de baja: no le quedaba nada propio.</span><?php endif; ?>
                        </td>
                        <td class="n" data-et="Extintores quitados"><?= $r['huella']['extintores'] ?></td>
                        <td class="n" data-et="Inspecciones quitadas"><?= $r['huella']['inspecciones'] ?></td>
                        <td data-et="Se conservó" class="se-queda"><?= htmlspecialchars(frasePropios($r['huella']['propios'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <?php if ($totalBorrado): ?>
                <tfoot><tr style="font-weight:800;background:#f8faff">
                    <td>Total</td>
                    <td class="n"><?= $totalBorrado['extintores'] ?? 0 ?></td>
                    <td class="n"><?= $totalBorrado['inspecciones'] ?? 0 ?></td>
                    <td><?= (int) ($totalBorrado['empresas'] ?? 0) ?> planta(s) dadas de baja</td>
                </tr></tfoot>
                <?php endif; ?>
            </table></div>
        </div>
        <a class="btn btn-ghost" href="admin-quitar-demo.php">Volver a revisar</a>
        <a class="btn btn-ghost" href="admin-dashboard.php">Ir al panel</a>

    <?php elseif (!$conHuella && !$gerente): ?>
        <div class="card">
            <div class="vacio">
                <div class="ic">✅</div>
                <p><b>No quedan datos de demostración en este sistema.</b></p>
                <p style="margin-top:8px">
                    <?php if ($presentes): ?>
                        Hay <?= count($presentes) ?> planta(s) de la lista dadas de alta, pero ninguna
                        conserva extintores sembrados: lo que tienen es información tuya.
                    <?php else: ?>
                        Ninguna de las 14 plantas de ejemplo está dada de alta, y tampoco existe el usuario gerente de demostración.
                    <?php endif; ?>
                </p>
            </div>
        </div>

    <?php else: ?>
        <div class="alerta a-info">
            <b>Qué se va y qué se queda.</b> De cada planta se quitan sólo los extintores que escribió
            la siembra —se reconocen uno por uno— junto con sus inspecciones. Los extintores que
            capturaste tú, y los reportes, fotos, cotizaciones, documentos y usuarios de la planta,
            no se tocan: la siembra nunca creó nada de eso. Una planta sólo se da de baja si al
            quitar lo sembrado no le queda nada propio.
        </div>

        <form method="post">
            <input type="hidden" name="accion" value="quitar">

            <?php if ($conHuella): ?>
            <div class="card">
                <div class="tabla-env"><table class="tabla">
                    <thead><tr>
                        <th style="width:34px"><input type="checkbox" id="todas" onclick="marcarTodas(this)" checked></th>
                        <th>Planta</th>
                        <th class="n">Extintores<br>sembrados</th>
                        <th class="n">Inspecciones<br>que se van</th>
                        <th>Lo tuyo, que se queda</th>
                        <th>La planta</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($conHuella as $p): $h = $p['huella']; ?>
                        <tr>
                            <td class="marca" data-et=""><input type="checkbox" class="cb" name="empresas[]" value="<?= $p['empresa_id'] ?>" checked></td>
                            <td class="planta" data-et="Planta" style="font-weight:600"><?= htmlspecialchars($p['nombre']) ?>
                                <span class="nota">
                                    <?php if ($h['origen'] === 'registro'): ?>
                                        <span class="badge b-exacto">registrado</span> sembrada el <?= htmlspecialchars(substr((string) $h['sembrado_en'], 0, 10)) ?>
                                    <?php else: ?>
                                        <span class="badge b-indicios">por indicios</span> reconocidos por cómo los escribió la siembra
                                    <?php endif; ?>
                                </span>
                            </td>
                            <td class="n se-va" data-et="Extintores sembrados"><?= $h['extintores'] ?></td>
                            <td class="n se-va" data-et="Inspecciones que se van"><?= $h['inspecciones'] ?></td>
                            <td data-et="Se queda" class="se-queda"><?= htmlspecialchars(frasePropios($h['propios'])) ?></td>
                            <td data-et="La planta">
                                <?php if ($h['borrar_empresa']): ?>
                                    <span class="badge b-baja">se da de baja</span>
                                <?php else: ?>
                                    <span class="badge b-queda">se conserva</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div>
            <?php else: ?>
                <div class="alerta a-info">Ninguna planta conserva extintores sembrados por la demostración.</div>
            <?php endif; ?>

            <div class="card">
                <?php if ($gerente): ?>
                    <label class="chk">
                        <input type="checkbox" name="quitar_gerente" value="1" <?= $conHuella ? 'checked' : '' ?>>
                        <span>Quitar también el usuario gerente de demostración
                        <b><?= htmlspecialchars(GERENTE_USERNAME) ?></b>, asignado a <?= $gerentePlantas ?> planta(s).</span>
                    </label>
                    <?php if ($gerentePlantas > count($conHuella)): ?>
                        <div class="alerta a-warn" style="margin:12px 0 0">
                            Ese usuario está asignado a más plantas de las que sembró la demostración.
                            Si lo estás usando como gerente de verdad, no lo marques.
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="alerta a-info" style="margin:0">El usuario gerente de demostración no existe en este sistema.</div>
                <?php endif; ?>

                <div class="fg">
                    <label>Para confirmar, escribe <b><?= PALABRA_CONFIRMACION ?></b></label>
                    <input type="text" name="confirmacion" autocomplete="off" placeholder="<?= PALABRA_CONFIRMACION ?>">
                </div>

                <button type="submit" class="btn btn-danger"
                        onclick="return confirm('Se quitarán los extintores sembrados y su historial de las plantas marcadas. Esta acción no se puede deshacer. ¿Continuar?')">
                    Quitar lo sembrado
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
