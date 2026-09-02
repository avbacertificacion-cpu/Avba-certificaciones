<?php
/**
 * Pasa a MAYÚSCULAS el texto que ya está guardado en la base (solo ADMIN).
 *
 * De aquí en adelante todo entra en mayúsculas por sí solo (config/mayusculas.php),
 * pero lo capturado antes conserva su forma original. Esta pantalla lo empareja.
 *
 * Convierte una lista explícita de columnas, no "todo el texto": hay campos
 * donde hacerlo rompería el sistema —el hash de la contraseña, las banderas
 * que el código compara en minúsculas (`estado`, `rol`, `modulo`), los nombres
 * de archivo subidos (el disco distingue mayúsculas) y los correos—. Esos no
 * aparecen en la lista y no se tocan nunca.
 *
 * Se puede volver a ejecutar sin problema: lo que ya está en mayúsculas no
 * cuenta como pendiente y no se vuelve a escribir.
 */
require_once '../config/config.php';
require_once '../config/roles-extra.php';
require_once '../config/mayusculas.php';
require_once '../config/modo-demo.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== ROLE_ADMIN) {
    header('Location: ../public/login.html'); exit;
}
$nombreAdmin = $_SESSION['nombre'];
$uid = $_SESSION['usuario_id'];
@set_time_limit(0);

/**
 * Columnas que se convierten, por tabla. Es una lista explícita a propósito:
 * añadir aquí una columna es una decisión, no un descuido.
 */
function columnasAConvertir(): array {
    return [
        'empresas'           => ['nombre', 'domicilio', 'contacto', 'rfc'],
        'usuarios'           => ['nombre'],
        'extintores'         => ['codigo_manual', 'seccion', 'ubicacion', 'capacidad', 'observaciones'],
        'tipos_extintores'   => ['nombre', 'descripcion'],
        'inspecciones'       => ['observaciones'],
        'reportes_mensuales' => ['numero_reporte', 'observaciones'],
        'reporte_fotos'      => ['descripcion'],
        'proveedores'        => ['nombre', 'contacto', 'notas'],
        'catalogo_precios'   => ['descripcion', 'unidad'],
        'cotizaciones'       => ['folio', 'cliente_nombre', 'contacto', 'notas', 'condiciones_pago',
                                 'cliente_rfc', 'cliente_cp'],
        'cotizacion_items'   => ['descripcion', 'unidad', 'codigo'],
        'documentos'         => ['descripcion'],
        'ordenes_compra'     => ['descripcion'],
    ];
}

function existeTabla(PDO $pdo, string $tabla): bool {
    try { $st = $pdo->prepare("SHOW TABLES LIKE ?"); $st->execute([$tabla]); return (bool) $st->fetchColumn(); }
    catch (Exception $e) { return false; }
}

function existeColumna(PDO $pdo, string $tabla, string $columna): bool {
    try { $st = $pdo->prepare("SHOW COLUMNS FROM `$tabla` LIKE ?"); $st->execute([$columna]); return (bool) $st->fetch(); }
    catch (Exception $e) { return false; }
}

/**
 * Cuántas filas cambiarían. La comparación va con BINARY porque el cotejamiento
 * de la base ignora mayúsculas: sin él, `col = UPPER(col)` siempre sería cierto
 * y el conteo daría cero aunque hubiera texto en minúsculas.
 */
function pendientes(PDO $pdo, string $tabla, string $columna): int {
    try {
        return (int) $pdo->query("
            SELECT COUNT(*) FROM `$tabla`
            WHERE `$columna` IS NOT NULL AND `$columna` <> ''
              AND BINARY `$columna` <> BINARY UPPER(`$columna`)
        ")->fetchColumn();
    } catch (Exception $e) { return 0; }
}

// ─── Qué hay pendiente ───────────────────────────────────────────────────────
$preview = [];
$totalPendiente = 0;
foreach (columnasAConvertir() as $tabla => $columnas) {
    if (!existeTabla($pdo, $tabla)) continue;
    foreach ($columnas as $columna) {
        if (!existeColumna($pdo, $tabla, $columna)) continue;
        $n = pendientes($pdo, $tabla, $columna);
        $preview[] = ['tabla' => $tabla, 'columna' => $columna, 'pendientes' => $n];
        $totalPendiente += $n;
    }
}

$errorGeneral = null;
$resultados   = null;
$totalCambiado = 0;

// ─── Conversión ──────────────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['accion'] ?? '') === 'convertir') {
    if (empty($_POST['entendido'])) {
        $errorGeneral = 'Marca la casilla de confirmación para continuar.';
    } else {
        try {
            $pdo->beginTransaction();
            $resultados = [];
            foreach (columnasAConvertir() as $tabla => $columnas) {
                if (!existeTabla($pdo, $tabla)) continue;
                foreach ($columnas as $columna) {
                    if (!existeColumna($pdo, $tabla, $columna)) continue;
                    $st = $pdo->prepare("
                        UPDATE `$tabla` SET `$columna` = UPPER(`$columna`)
                        WHERE `$columna` IS NOT NULL AND `$columna` <> ''
                          AND BINARY `$columna` <> BINARY UPPER(`$columna`)
                    ");
                    $st->execute();
                    $n = $st->rowCount();
                    if ($n > 0) {
                        $resultados[] = ['tabla' => $tabla, 'columna' => $columna, 'cambiadas' => $n];
                        $totalCambiado += $n;
                    }
                }
            }

            $st = $pdo->prepare("INSERT INTO auditoria (usuario_id,accion,tabla,registro_id,ip) VALUES (?,?,?,?,?)");
            $st->execute([$uid, "Convertir a mayúsculas $totalCambiado registros existentes",
                          'varias', null, $_SERVER['REMOTE_ADDR'] ?? null]);

            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errorGeneral = 'No se pudo completar la conversión (no se cambió nada): ' . $e->getMessage();
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
<title>Convertir a mayúsculas</title>
<style>
    *{margin:0;padding:0;box-sizing:border-box}
    body{font-family:'Segoe UI',system-ui,sans-serif;background:#eef2fb;color:#1a2138}
    .navbar{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:16px 26px;
            display:flex;justify-content:space-between;align-items:center;box-shadow:0 4px 16px rgba(102,126,234,.2)}
    .navbar a{color:#fff;text-decoration:none;font-size:13px;opacity:.9}.navbar a:hover{opacity:1;text-decoration:underline}
    .container{max-width:860px;margin:0 auto;padding:26px 20px}
    h2{font-size:24px;color:#1e293b}
    .sub{color:#64748b;font-size:13px;margin-bottom:20px}
    .card{background:#fff;border-radius:14px;padding:20px;box-shadow:0 4px 14px rgba(30,41,59,.08);margin-bottom:20px}
    table{width:100%;border-collapse:collapse}
    thead{background:#f1f5fb}
    th{padding:10px 9px;text-align:left;font-size:11px;color:#475569;font-weight:700;text-transform:uppercase}
    td{padding:9px;font-size:13px;border-bottom:1px solid #f1f5f9}
    td.n,th.n{text-align:right}
    tbody tr:hover{background:#f8faff}
    .cero{color:#94a3b8}
    .btn{padding:11px 20px;border:none;border-radius:8px;font-weight:700;cursor:pointer;font-size:14px}
    .btn-primary{background:#667eea;color:#fff}.btn-primary:hover{background:#5568d3}
    .btn-primary:disabled{background:#a5b4fc;cursor:not-allowed}
    .btn-ghost{background:#eef2fb;color:#475569;text-decoration:none;display:inline-block;padding:11px 20px}
    .alerta{padding:14px 16px;border-radius:10px;font-size:13px;margin-bottom:18px;line-height:1.6}
    .a-err{background:#fee2e2;color:#b91c1c}.a-ok{background:#d1fae5;color:#047857}
    .a-warn{background:#fef3c7;color:#92400e}.a-info{background:#e0e7ff;color:#3730a3}
    .chk{display:flex;align-items:flex-start;gap:9px;font-size:13px;cursor:pointer;margin:16px 0;line-height:1.5}
    .chk input{width:auto;margin-top:2px}
    .vacio{text-align:center;padding:44px 20px;color:#64748b}.vacio .ic{font-size:52px;margin-bottom:12px}
    .intactos{font-size:12px;color:#64748b;line-height:1.7}
    .intactos code{background:#f1f5fb;padding:1px 6px;border-radius:4px}
</style>
</head>
<body>
<?= cintaDemo() ?>
<div class="navbar">
    <a href="admin-dashboard.php">← Panel Admin</a>
    <span style="font-size:13px">👤 <?= htmlspecialchars($nombreAdmin) ?></span>
</div>

<div class="container">
    <h2>🔠 Convertir a mayúsculas</h2>
    <div class="sub">Pasa a mayúsculas el texto que ya estaba guardado. Lo nuevo entra en mayúsculas por sí solo.</div>

    <?php if ($errorGeneral): ?>
        <div class="alerta a-err">⚠️ <?= htmlspecialchars($errorGeneral) ?></div>
    <?php endif; ?>

    <?php if ($resultados !== null): ?>
        <div class="card">
            <div class="alerta a-ok">✓ Listo. Se convirtieron <?= number_format($totalCambiado) ?> registro(s).</div>
            <?php if ($resultados): ?>
                <table>
                    <thead><tr><th>Tabla</th><th>Columna</th><th class="n">Convertidos</th></tr></thead>
                    <tbody>
                    <?php foreach ($resultados as $r): ?>
                        <tr><td><?= htmlspecialchars($r['tabla']) ?></td>
                            <td><?= htmlspecialchars($r['columna']) ?></td>
                            <td class="n"><?= number_format($r['cambiadas']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p style="font-size:13px;color:#64748b">No había nada pendiente.</p>
            <?php endif; ?>
        </div>
        <a class="btn btn-ghost" href="admin-mayusculas.php">Volver a revisar</a>
        <a class="btn btn-ghost" href="admin-dashboard.php">Ir al panel</a>

    <?php elseif ($totalPendiente === 0): ?>
        <div class="card">
            <div class="vacio">
                <div class="ic">✅</div>
                <p><b>Todo el texto ya está en mayúsculas.</b></p>
                <p style="margin-top:8px">No hay ningún registro pendiente de convertir.</p>
            </div>
        </div>
        <a class="btn btn-ghost" href="admin-dashboard.php">Ir al panel</a>

    <?php else: ?>
        <div class="alerta a-warn">
            Se convertirán <b><?= number_format($totalPendiente) ?></b> registros. El cambio
            <b>no se puede deshacer</b>: la forma original de cada texto no queda guardada en ningún lado.
        </div>

        <div class="card">
            <table>
                <thead><tr><th>Tabla</th><th>Columna</th><th class="n">Por convertir</th></tr></thead>
                <tbody>
                <?php foreach ($preview as $p): ?>
                    <tr>
                        <td><?= htmlspecialchars($p['tabla']) ?></td>
                        <td><?= htmlspecialchars($p['columna']) ?></td>
                        <td class="n <?= $p['pendientes'] ? '' : 'cero' ?>"><?= number_format($p['pendientes']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr style="font-weight:800;background:#f8faff">
                    <td colspan="2">Total</td><td class="n"><?= number_format($totalPendiente) ?></td>
                </tr></tfoot>
            </table>
        </div>

        <div class="card">
            <div class="alerta a-info" style="margin-bottom:14px">
                <b>Lo que no se toca nunca</b>
                <div class="intactos" style="margin-top:8px">
                    <code>password</code> — es un hash; en mayúsculas nadie podría volver a entrar.<br>
                    <code>estado</code>, <code>rol</code>, <code>modulo</code> — el sistema los compara en minúsculas
                    (<code>WHERE estado = 'activo'</code>).<br>
                    <code>archivo</code>, <code>nombre_original</code>, <code>mime</code> — nombres de archivo; el
                    servidor distingue mayúsculas y las fotos dejarían de abrir.<br>
                    <code>username</code>, <code>email</code>, <code>codigo_qr</code> — identifican, se buscan tal cual.
                </div>
            </div>

            <form method="post">
                <input type="hidden" name="accion" value="convertir">
                <label class="chk">
                    <input type="checkbox" name="entendido" value="1">
                    Entiendo que el texto quedará en mayúsculas de forma permanente y que no se puede deshacer.
                </label>
                <button type="submit" class="btn btn-primary"
                        onclick="return confirm('Se convertirán <?= number_format($totalPendiente) ?> registros a mayúsculas. ¿Continuar?')">
                    Convertir a mayúsculas
                </button>
                <a class="btn btn-ghost" href="admin-dashboard.php">Cancelar</a>
            </form>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
