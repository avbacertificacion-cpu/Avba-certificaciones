<?php
/**
 * Presupuesto para el cliente, con el formato fiscal de la empresa.
 *
 * Es una página pensada para imprimir (o "Guardar como PDF" desde el
 * navegador), igual que reporte_pdf.php: así no hace falta ninguna librería
 * de PDF en el hosting.
 *
 * Muestra sólo lo que el cliente debe ver: cantidades, precios de venta,
 * impuestos y total. Los costos del proveedor y la utilidad nunca salen aquí.
 */
require_once '../config/config.php';
require_once '../config/emisor.php';

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401); exit('No autenticado');
}
if ($_SESSION['rol'] !== ROLE_ADMIN) {
    http_response_code(403); exit('Sin permiso');
}

$id = intval($_GET['id'] ?? 0);
if (!$id) exit('ID de cotización requerido');

$stmt = $pdo->prepare("
    SELECT c.*, u.nombre AS creador_nombre
    FROM cotizaciones c
    LEFT JOIN usuarios u ON u.id = c.creado_por
    WHERE c.id = ?
");
$stmt->execute([$id]);
$cot = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$cot) exit('Cotización no encontrada');

$stmt = $pdo->prepare("SELECT * FROM cotizacion_items WHERE cotizacion_id = ? ORDER BY orden, id");
$stmt->execute([$id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$emisor = emisorDatos();
$moneda = $cot['moneda'] ?: 'MXN';

// ─── Importes ────────────────────────────────────────────────────────────────
// El IVA se acumula por tasa: una misma cotización puede llevar partidas al
// 16% y al 8% (franja fronteriza), y cada tasa se traslada en su propio renglón.
$subtotal = 0.0;
$baseporTasa = [];
foreach ($items as $i => $it) {
    $importe = round(((float) $it['cantidad']) * ((float) $it['precio_unitario']), 2);
    $items[$i]['importe'] = $importe;
    $subtotal += $importe;

    $tasa = (float) ($it['iva_tasa'] ?? 16);
    if (!isset($baseporTasa[(string) $tasa])) $baseporTasa[(string) $tasa] = 0.0;
    $baseporTasa[(string) $tasa] += $importe;
}
$subtotal = round($subtotal, 2);

$traslados = [];   // tasa => IVA trasladado
foreach ($baseporTasa as $tasa => $base) {
    $t = (float) $tasa;
    if ($t <= 0) continue;                       // el 0% no genera renglón de traslado
    $traslados[$tasa] = round($base * $t / 100, 2);
}
krsort($traslados, SORT_NUMERIC);                // 16% antes que 8%, como en el formato
$totalIva = round(array_sum($traslados), 2);
$total    = round($subtotal + $totalIva, 2);

// La fecha de emisión lleva la hora de captura; la cotización sólo guarda el
// día, así que se completa con la hora en que quedó registrada.
$fechaEmision = substr($cot['fecha'], 0, 10)
    . ' ' . substr($cot['created_at'] ?? '', 11, 8);

$dinero = fn($n) => number_format((float) $n, 2, '.', ',');
$e      = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Presupuesto <?= $e($cot['folio']) ?></title>
<style>
    *{margin:0;padding:0;box-sizing:border-box}
    body{font-family:Arial,Helvetica,sans-serif;font-size:10px;color:#000;background:#e8ebf0;padding:18px}
    .hoja{width:279mm;max-width:100%;margin:0 auto;background:#fff;padding:12mm 10mm;
          box-shadow:0 2px 12px rgba(0,0,0,.18)}

    .barra{display:flex;justify-content:space-between;font-size:9px;margin-bottom:14px}

    /* Encabezado: emisor al centro, datos del documento a la derecha */
    .enc{display:flex;justify-content:space-between;gap:20px;margin-bottom:26px}
    .enc .emisor{flex:1;padding-left:34%}
    .enc .emisor .rs{font-weight:bold}
    .enc .doc{width:210px;font-size:9px}
    .enc .doc .folio{text-align:right;font-weight:bold;margin-bottom:6px}
    .enc .doc .et{font-weight:bold}

    /* Receptor a la izquierda, datos fiscales a la derecha */
    .partes{display:flex;justify-content:space-between;gap:24px;margin-bottom:22px}
    .partes .receptor{flex:1}
    .partes .receptor .nombre{font-weight:bold;margin-bottom:10px}
    .partes .fiscal{width:46%;font-size:9px}
    .partes .fiscal div{margin-bottom:2px}
    .partes .fiscal b{font-weight:bold}

    h1{text-align:center;font-size:13px;font-weight:bold;margin-bottom:6px}

    table{width:100%;border-collapse:collapse;font-size:9px}
    thead th{background:#f0f0f0;border:1px solid #999;padding:5px 4px;font-weight:bold;text-align:center;
             -webkit-print-color-adjust:exact;print-color-adjust:exact}
    tbody td{border:1px solid #ccc;padding:6px 4px;vertical-align:top}
    tbody tr{page-break-inside:avoid}
    .c{text-align:center}.r{text-align:right}

    /* Totales: pegados a la derecha, con el importe con letra a la izquierda */
    .cierre{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;margin-top:14px}
    .letra{flex:1;font-size:9px;padding-bottom:6px}
    .totales{width:340px;font-size:9px}
    .totales div{display:flex;justify-content:flex-end;gap:12px;padding:4px 0}
    .totales .cel-et{width:110px;text-align:right}
    .totales .cel-tasa{width:80px;text-align:right}
    .totales .cel-imp{width:100px;text-align:right}
    .totales .total{border-top:1px solid #000;font-weight:bold;font-size:11px;padding-top:6px}

    .notas{margin-top:18px;font-size:9px;border-top:1px solid #ddd;padding-top:8px}
    .leyenda{text-align:center;font-weight:bold;font-size:9px;margin-top:26px}
    .pie{text-align:center;font-size:8px;color:#555;margin-top:20px}

    .acciones{max-width:279mm;margin:0 auto 14px;text-align:right}
    .acciones button{background:#667eea;color:#fff;border:none;padding:9px 18px;border-radius:6px;
                     font-size:13px;font-weight:bold;cursor:pointer}
    @media print{
        body{background:#fff;padding:0}
        .hoja{width:auto;box-shadow:none;padding:0}
        .acciones{display:none}
        @page{size:letter landscape;margin:12mm}
    }
</style>
</head>
<body>

<div class="acciones"><button onclick="window.print()">🖨️ Imprimir / Guardar PDF</button></div>

<div class="hoja">
    <div class="barra">
        <span>Presupuesto realizado por cuenta <?= $e($cot['creador_nombre'] ?: 'Admin') ?>.</span>
    </div>

    <div class="enc">
        <div class="emisor">
            <div class="rs"><?= $e($emisor['nombre']) ?></div>
            <div>RFC: <?= $e($emisor['rfc']) ?></div>
            <div>Régimen: <?= $e(catEtiqueta(catRegimenFiscal(), $emisor['regimen'])) ?></div>
            <div>C.P. <?= $e($emisor['cp']) ?></div>
            <div>Teléfono: <?= $e($emisor['telefono']) ?></div>
            <div>Correo: <?= $e($emisor['correo']) ?></div>
        </div>
        <div class="doc">
            <div class="folio">Folio: <?= $e($cot['folio']) ?></div>
            <div class="et">Fecha de emisión:</div>
            <div><?= $e(trim($fechaEmision)) ?></div>
            <div class="et" style="margin-top:4px">Lugar de expedición:</div>
            <div><?= $e($emisor['cp']) ?></div>
        </div>
    </div>

    <div class="partes">
        <div class="receptor">
            <div class="nombre"><?= $e(mb_strtoupper($cot['cliente_nombre'])) ?></div>
            <?php if ($cot['cliente_rfc']): ?>       <div>RFC: <?= $e($cot['cliente_rfc']) ?></div><?php endif; ?>
            <?php if ($cot['cliente_regimen']): ?>   <div>Régimen: <?= $e(catEtiqueta(catRegimenFiscal(), $cot['cliente_regimen'])) ?></div><?php endif; ?>
            <?php if ($cot['cliente_cp']): ?>        <div>C.P.: <?= $e($cot['cliente_cp']) ?></div><?php endif; ?>
            <?php if ($cot['contacto']): ?>          <div style="margin-top:6px">Atención: <?= $e($cot['contacto']) ?></div><?php endif; ?>
        </div>
        <div class="fiscal">
            <div><b>Uso CFDI:</b> <?= $e(catEtiqueta(catUsoCfdi(), $cot['uso_cfdi'], ', ')) ?></div>
            <div><b>Método de pago:</b> <?= $e(catEtiqueta(catMetodoPago(), $cot['metodo_pago'], ' ')) ?></div>
            <div><b>Forma pago:</b> <?= $e(catEtiqueta(catFormaPago(), $cot['forma_pago'])) ?></div>
            <div><b>Moneda:</b> <?= $e($moneda) ?> &nbsp; <b>Tipo cambio:</b> <?= $e(number_format((float) $cot['tipo_cambio'], 6, '.', '')) ?></div>
            <div><b>Condiciones de Pago:</b> <?= $e($cot['condiciones_pago']) ?></div>
            <?php if ((int) $cot['vigencia_dias'] > 0): ?>
                <div><b>Vigencia:</b> <?= (int) $cot['vigencia_dias'] ?> días</div>
            <?php endif; ?>
        </div>
    </div>

    <h1>Presupuesto</h1>

    <table>
        <thead>
            <tr>
                <th style="width:62px">Cantidad</th>
                <th style="width:80px">Código</th>
                <th style="width:70px">Clave<br>prod/sv</th>
                <th style="width:58px">Clave<br>unidad</th>
                <th>Descripción</th>
                <th style="width:86px">P/U</th>
                <th style="width:96px">Importe</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$items): ?>
            <tr><td colspan="7" class="c" style="padding:14px">Esta cotización no tiene partidas.</td></tr>
        <?php endif; ?>
        <?php foreach ($items as $it): ?>
            <tr>
                <td class="c"><?= $e(rtrim(rtrim(number_format((float) $it['cantidad'], 2, '.', ','), '0'), '.')) ?></td>
                <td class="c"><?= $e($it['codigo']) ?></td>
                <td class="c"><?= $e($it['clave_prodserv'] ?: $cot['clave_prodserv']) ?></td>
                <td class="c"><?= $e($it['clave_unidad'] ?: $cot['clave_unidad']) ?></td>
                <td><?= $e($it['descripcion']) ?></td>
                <td class="r"><?= $dinero($it['precio_unitario']) ?></td>
                <td class="r"><?= $dinero($it['importe']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="cierre">
        <div class="letra"><?= $e(importeALetras($total, $moneda)) ?></div>
        <div class="totales">
            <div>
                <span class="cel-et"></span>
                <span class="cel-tasa">Subtotal</span>
                <span class="cel-imp"><?= $dinero($subtotal) ?></span>
            </div>
            <?php foreach ($traslados as $tasa => $importe): ?>
                <div>
                    <span class="cel-et">Traslado IVA</span>
                    <span class="cel-tasa">Tasa <?= $e(rtrim(rtrim(number_format((float) $tasa, 2, '.', ''), '0'), '.')) ?>%</span>
                    <span class="cel-imp"><?= $dinero($importe) ?></span>
                </div>
            <?php endforeach; ?>
            <div class="total">
                <span class="cel-et"></span>
                <span class="cel-tasa">TOTAL</span>
                <span class="cel-imp"><?= $dinero($total) ?></span>
            </div>
        </div>
    </div>

    <?php if (trim((string) $cot['notas']) !== ''): ?>
        <div class="notas"><b>Observaciones:</b> <?= nl2br($e($cot['notas'])) ?></div>
    <?php endif; ?>

    <div class="leyenda"><?= $e($emisor['leyenda']) ?></div>
    <div class="pie"><?= $e($emisor['nombre']) ?> · Tel. <?= $e($emisor['telefono']) ?> · <?= $e($emisor['correo']) ?></div>
</div>

</body>
</html>
