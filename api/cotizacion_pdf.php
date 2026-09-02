<?php
/**
 * Presupuesto imprimible, en dos copias:
 *
 *  - vista=cliente  (por defecto) — la que se le manda al cliente: cantidades,
 *    precio de venta, impuestos y total. Los costos y la utilidad ni siquiera
 *    se escriben en el HTML, para que no puedan verse en el código fuente.
 *  - vista=interna — la copia de trabajo: agrega proveedor, costo, porcentaje
 *    y utilidad por partida, con sus totales. Va marcada de forma bien visible
 *    para que no se confunda con la del cliente.
 *
 * Es una página pensada para imprimir (o "Guardar como PDF" desde el
 * navegador), igual que reporte_pdf.php: así no hace falta ninguna librería
 * de PDF en el hosting.
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

// Cualquier valor que no sea exactamente "interna" cae en la copia del cliente:
// ante una URL mal escrita, lo seguro es no enseñar costos.
$interna = (($_GET['vista'] ?? '') === 'interna');

$stmt = $pdo->prepare("
    SELECT c.*, u.nombre AS creador_nombre
    FROM cotizaciones c
    LEFT JOIN usuarios u ON u.id = c.creado_por
    WHERE c.id = ?
");
$stmt->execute([$id]);
$cot = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$cot) exit('Cotización no encontrada');

$stmt = $pdo->prepare("
    SELECT i.*, p.nombre AS proveedor_nombre
    FROM cotizacion_items i
    LEFT JOIN proveedores p ON p.id = i.proveedor_id
    WHERE i.cotizacion_id = ?
    ORDER BY i.orden, i.id
");
$stmt->execute([$id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$emisor = emisorDatos();
$moneda = $cot['moneda'] ?: 'MXN';

// ─── Importes ────────────────────────────────────────────────────────────────
// El IVA se acumula por tasa: una misma cotización puede llevar partidas al
// 16% y al 8% (franja fronteriza), y cada tasa se traslada en su propio renglón.
$subtotal = 0.0;
$costoTotal = 0.0;
$baseporTasa = [];
foreach ($items as $i => $it) {
    $importe = round(((float) $it['cantidad']) * ((float) $it['precio_unitario']), 2);
    $costo   = round(((float) $it['cantidad']) * ((float) $it['costo_unitario']), 2);
    $items[$i]['importe']     = $importe;
    $items[$i]['costo_linea'] = $costo;
    $items[$i]['utilidad']    = round($importe - $costo, 2);
    $items[$i]['pct']         = $costo > 0 ? ($importe - $costo) / $costo * 100 : 0;
    $subtotal   += $importe;
    $costoTotal += $costo;

    $tasa = (float) ($it['iva_tasa'] ?? 16);
    if (!isset($baseporTasa[(string) $tasa])) $baseporTasa[(string) $tasa] = 0.0;
    $baseporTasa[(string) $tasa] += $importe;
}
$subtotal   = round($subtotal, 2);
$costoTotal = round($costoTotal, 2);
$utilidad   = round($subtotal - $costoTotal, 2);
$gananciaPct = $costoTotal > 0 ? $utilidad / $costoTotal * 100 : 0;

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

$dinero  = fn($n) => number_format((float) $n, 2, '.', ',');
$e       = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
/** 10.00 → "10", 2.50 → "2.5": las cantidades se leen mejor sin ceros de relleno. */
$cifra   = fn($n) => rtrim(rtrim(number_format((float) $n, 2, '.', ','), '0'), '.');
$columnas = $interna ? 11 : 7;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Presupuesto <?= $e($cot['folio']) ?><?= $interna ? ' (interna)' : '' ?></title>
<style>
    *{margin:0;padding:0;box-sizing:border-box}
    :root{
        --tinta:#1e293b; --suave:#64748b; --linea:#dbe2f0;
        --marca:<?= $interna ? '#b45309' : '#5b63d3' ?>;
        --marca2:<?= $interna ? '#f59e0b' : '#7d5cc6' ?>;
        --tenue:<?= $interna ? '#fff8ed' : '#f4f6fe' ?>;
    }
    body{font-family:'Segoe UI',Arial,Helvetica,sans-serif;font-size:10px;color:var(--tinta);
         background:#e8ebf2;padding:20px}
    .hoja{width:279mm;max-width:100%;margin:0 auto;background:#fff;
          box-shadow:0 6px 26px rgba(15,23,42,.16);overflow:hidden}
    .cuerpo{padding:5mm 8mm 5mm}

    /* ── Banda superior de marca ── */
    .banda{background:linear-gradient(120deg,var(--marca),var(--marca2));color:#fff;
           padding:9px 8mm;display:flex;justify-content:space-between;align-items:center;gap:20px;
           -webkit-print-color-adjust:exact;print-color-adjust:exact}
    .banda .rs{font-size:14px;font-weight:700;letter-spacing:.2px;line-height:1.25}
    .banda .rs small{display:block;font-size:9px;font-weight:400;opacity:.9;margin-top:3px;letter-spacing:.4px}
    .banda .doc{text-align:right;flex-shrink:0}
    .banda .doc .tit{font-size:19px;font-weight:800;letter-spacing:3px;text-transform:uppercase;line-height:1}
    .banda .folio{display:inline-block;margin-top:6px;background:rgba(255,255,255,.22);
                  border:1px solid rgba(255,255,255,.45);border-radius:20px;padding:3px 12px;
                  font-size:11px;font-weight:700;letter-spacing:.4px}

    .aviso-interno{background:#7c2d12;color:#fff;text-align:center;padding:6px;font-size:10px;
                   font-weight:700;letter-spacing:1.6px;text-transform:uppercase;
                   -webkit-print-color-adjust:exact;print-color-adjust:exact}


    /* ── Emisor / documento ── */
    .tira{display:flex;gap:12px;margin-bottom:11px}
    .tira .bloque{flex:1;border:1px solid var(--linea);border-radius:8px;padding:8px 11px;background:#fff}
    .tira .bloque.acento{background:var(--tenue);border-color:var(--marca);border-width:1px}
    .rot{font-size:8px;font-weight:800;letter-spacing:1.1px;text-transform:uppercase;
         color:var(--marca);margin-bottom:6px}
    .dato{font-size:9.5px;line-height:1.45}
    .dato b{color:var(--tinta)}
    .dato .k{color:var(--suave)}
    .nombre-cliente{font-size:12px;font-weight:800;margin-bottom:5px;line-height:1.25}

    /* ── Partidas ── */
    table{width:100%;border-collapse:collapse;font-size:9px}
    thead th{background:var(--marca);color:#fff;padding:8px 5px;font-weight:700;text-align:center;
             font-size:8.5px;letter-spacing:.4px;text-transform:uppercase;
             -webkit-print-color-adjust:exact;print-color-adjust:exact}
    thead th small{display:block;font-weight:400;font-size:7.5px;opacity:.85;text-transform:none;letter-spacing:0}
    tbody td{border-bottom:1px solid var(--linea);padding:6px 5px;vertical-align:top}
    tbody tr:nth-child(even){background:#f8fafd;-webkit-print-color-adjust:exact;print-color-adjust:exact}
    tbody tr{page-break-inside:avoid}
    tbody .desc{font-weight:600;color:var(--tinta)}
    .c{text-align:center}.r{text-align:right}
    .num{font-variant-numeric:tabular-nums;white-space:nowrap}
    .clave{font-size:8.5px;color:var(--suave)}
    .pill{display:inline-block;background:#eef2fb;border-radius:10px;padding:1px 7px;font-size:8px;
          font-weight:700;color:#475569;-webkit-print-color-adjust:exact;print-color-adjust:exact}
    /* Las columnas de costo van resaltadas. En el encabezado el tono es oscuro:
       sobre el crema del cuerpo, el texto blanco de las cabeceras no se leería. */
    thead th.interna-col{background:#7c2d12}
    tbody td.interna-col{background:#fffaf0;-webkit-print-color-adjust:exact;print-color-adjust:exact}
    tbody tr:nth-child(even) td.interna-col{background:#fdf3e3}
    .pos{color:#047857;font-weight:700}.neg{color:#b91c1c;font-weight:700}

    /* ── Cierre ── */
    /* El recuadro de la letra se estira hasta la altura de los totales: si se
       dejara sólo a su alto, queda un hueco vacío a la izquierda de la hoja. */
    .cierre{display:flex;justify-content:space-between;align-items:stretch;gap:16px;margin-top:12px}
    .letra{flex:1;border-left:3px solid var(--marca);background:var(--tenue);border-radius:0 8px 8px 0;
           padding:10px 12px;display:flex;flex-direction:column;justify-content:center;
           -webkit-print-color-adjust:exact;print-color-adjust:exact}
    .letra .rot{margin-bottom:3px}
    .letra .txt{font-size:10px;font-weight:700}
    .cierre, .negocio{page-break-inside:avoid}
    .totales{width:330px;flex-shrink:0;border:1px solid var(--linea);border-radius:8px;overflow:hidden}
    .totales .fila{display:flex;justify-content:space-between;gap:10px;padding:6px 12px;font-size:9.5px}
    .totales .fila:nth-child(even){background:#f8fafd;-webkit-print-color-adjust:exact;print-color-adjust:exact}
    .totales .fila .et{color:var(--suave)}
    .totales .fila .im{font-weight:700;font-variant-numeric:tabular-nums}
    .totales .gran{background:linear-gradient(120deg,var(--marca),var(--marca2));color:#fff;
                   padding:10px 12px;display:flex;justify-content:space-between;align-items:center;
                   -webkit-print-color-adjust:exact;print-color-adjust:exact}
    .totales .gran .et{font-size:10px;font-weight:700;letter-spacing:1.4px}
    .totales .gran .im{font-size:16px;font-weight:800;font-variant-numeric:tabular-nums}

    .negocio{display:flex;gap:10px;margin-top:11px}
    .negocio .caja{flex:1;border:1px solid #fcd9a4;background:#fffaf0;border-radius:8px;padding:7px 11px;
                   -webkit-print-color-adjust:exact;print-color-adjust:exact}
    .negocio .caja .v{font-size:13.5px;font-weight:800;font-variant-numeric:tabular-nums;margin-top:1px}

    .notas{margin-top:10px;border:1px dashed var(--linea);border-radius:8px;padding:8px 11px;font-size:9.5px;line-height:1.45}
    .leyenda{margin-top:12px;text-align:center;font-weight:800;font-size:9px;letter-spacing:.8px;
             color:var(--marca);border-top:1px solid var(--linea);border-bottom:1px solid var(--linea);padding:7px 0}
    .pie{background:#f4f6fb;color:var(--suave);text-align:center;font-size:8.5px;padding:8px 8mm;
         -webkit-print-color-adjust:exact;print-color-adjust:exact}

    /* ── Barra de acciones (nunca se imprime) ── */
    .acciones{width:279mm;max-width:100%;margin:0 auto 14px;display:flex;gap:9px;justify-content:flex-end;flex-wrap:wrap}
    .acciones a,.acciones button{border:none;border-radius:8px;padding:9px 16px;font-size:12.5px;
        font-weight:700;cursor:pointer;text-decoration:none;font-family:inherit}
    .b-print{background:var(--marca);color:#fff}
    .b-otra{background:#fff;color:var(--tinta);border:1px solid var(--linea)}

    @media print{
        body{background:#fff;padding:0}
        .hoja{width:auto;box-shadow:none}
        .acciones{display:none}
        @page{size:letter landscape;margin:9mm}
    }
</style>
</head>
<body>

<div class="acciones">
    <?php if ($interna): ?>
        <a class="b-otra" href="?id=<?= $id ?>">👤 Ver copia del cliente</a>
    <?php else: ?>
        <a class="b-otra" href="?id=<?= $id ?>&vista=interna">🔒 Ver copia interna (con costos)</a>
    <?php endif; ?>
    <button class="b-print" onclick="window.print()">🖨️ Imprimir / Guardar PDF</button>
</div>

<div class="hoja">
    <?php if ($interna): ?>
        <div class="aviso-interno">Copia interna · contiene costos y utilidad · no entregar al cliente</div>
    <?php endif; ?>

    <div class="banda">
        <div class="rs">
            <?= $e($emisor['nombre']) ?>
            <small>RFC <?= $e($emisor['rfc']) ?> · <?= $e(catEtiqueta(catRegimenFiscal(), $emisor['regimen'])) ?></small>
        </div>
        <div class="doc">
            <div class="tit">Presupuesto</div>
            <div class="folio">Folio <?= $e($cot['folio']) ?></div>
        </div>
    </div>

    <div class="cuerpo">
        <div class="tira">
            <div class="bloque">
                <div class="rot">Emisor</div>
                <div class="dato">
                    <b><?= $e($emisor['nombre']) ?></b><br>
                    <span class="k">RFC:</span> <?= $e($emisor['rfc']) ?><br>
                    <span class="k">Régimen:</span> <?= $e(catEtiqueta(catRegimenFiscal(), $emisor['regimen'])) ?><br>
                    <span class="k">C.P.:</span> <?= $e($emisor['cp']) ?> &nbsp;·&nbsp;
                    <span class="k">Tel.:</span> <?= $e($emisor['telefono']) ?><br>
                    <span class="k">Correo:</span> <?= $e($emisor['correo']) ?>
                </div>
            </div>

            <div class="bloque acento">
                <div class="rot">Cliente</div>
                <div class="nombre-cliente"><?= $e(mb_strtoupper($cot['cliente_nombre'])) ?></div>
                <div class="dato">
                    <?php if ($cot['cliente_rfc']): ?><span class="k">RFC:</span> <?= $e($cot['cliente_rfc']) ?><br><?php endif; ?>
                    <?php if ($cot['cliente_regimen']): ?><span class="k">Régimen:</span> <?= $e(catEtiqueta(catRegimenFiscal(), $cot['cliente_regimen'])) ?><br><?php endif; ?>
                    <?php if ($cot['cliente_cp']): ?><span class="k">C.P.:</span> <?= $e($cot['cliente_cp']) ?><br><?php endif; ?>
                    <?php if ($cot['contacto']): ?><span class="k">Atención:</span> <?= $e($cot['contacto']) ?><?php endif; ?>
                </div>
            </div>

            <div class="bloque">
                <div class="rot">Datos del documento</div>
                <div class="dato">
                    <span class="k">Fecha de emisión:</span> <b><?= $e(trim($fechaEmision)) ?></b><br>
                    <span class="k">Lugar de expedición:</span> <?= $e($emisor['cp']) ?><br>
                    <span class="k">Uso CFDI:</span> <?= $e(catEtiqueta(catUsoCfdi(), $cot['uso_cfdi'], ', ')) ?><br>
                    <span class="k">Método de pago:</span> <?= $e(catEtiqueta(catMetodoPago(), $cot['metodo_pago'], ' ')) ?><br>
                    <span class="k">Forma de pago:</span> <?= $e(catEtiqueta(catFormaPago(), $cot['forma_pago'])) ?><br>
                    <span class="k">Moneda:</span> <?= $e($moneda) ?> · <span class="k">T.C.:</span> <?= $e(number_format((float) $cot['tipo_cambio'], 6, '.', '')) ?><br>
                    <span class="k">Condiciones de pago:</span> <?= $e($cot['condiciones_pago'] ?: '—') ?>
                    <?php if ((int) $cot['vigencia_dias'] > 0): ?>
                        <br><span class="k">Vigencia:</span> <b><?= (int) $cot['vigencia_dias'] ?> días</b>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th style="width:52px">Cant.</th>
                    <th style="width:68px">Código</th>
                    <th style="width:62px">Clave<br>prod/serv</th>
                    <th style="width:52px">Clave<br>unidad</th>
                    <th>Descripción</th>
                    <?php if ($interna): ?>
                        <th class="interna-col" style="width:92px">Proveedor</th>
                        <th class="interna-col" style="width:74px">Costo unit.<small>lo que compré</small></th>
                        <th class="interna-col" style="width:52px">% util.</th>
                    <?php endif; ?>
                    <th style="width:76px">P/U</th>
                    <th style="width:88px">Importe</th>
                    <?php if ($interna): ?>
                        <th class="interna-col" style="width:82px">Utilidad</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php if (!$items): ?>
                <tr><td colspan="<?= $columnas ?>" class="c" style="padding:16px;color:#94a3b8">Esta cotización no tiene partidas.</td></tr>
            <?php endif; ?>
            <?php foreach ($items as $it): ?>
                <tr>
                    <td class="c num"><?= $e($cifra($it['cantidad'])) ?><?= $it['unidad'] ? ' <span class="clave">' . $e($it['unidad']) . '</span>' : '' ?></td>
                    <td class="c clave"><?= $e($it['codigo']) ?></td>
                    <td class="c clave"><?= $e($it['clave_prodserv'] ?: $cot['clave_prodserv']) ?></td>
                    <td class="c clave"><?= $e($it['clave_unidad'] ?: $cot['clave_unidad']) ?></td>
                    <td class="desc">
                        <?= $e($it['descripcion']) ?>
                        <?php if ((float) $it['iva_tasa'] != 16.0): ?>
                            <span class="pill">IVA <?= $e($cifra($it['iva_tasa'])) ?>%</span>
                        <?php endif; ?>
                    </td>
                    <?php if ($interna): ?>
                        <td class="interna-col clave"><?= $e($it['proveedor_nombre'] ?: '—') ?></td>
                        <td class="interna-col r num"><?= $dinero($it['costo_unitario']) ?></td>
                        <td class="interna-col c num"><?= $e(number_format($it['pct'], 1)) ?>%</td>
                    <?php endif; ?>
                    <td class="r num"><?= $dinero($it['precio_unitario']) ?></td>
                    <td class="r num" style="font-weight:700"><?= $dinero($it['importe']) ?></td>
                    <?php if ($interna): ?>
                        <td class="interna-col r num <?= $it['utilidad'] < 0 ? 'neg' : 'pos' ?>"><?= $dinero($it['utilidad']) ?></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div class="cierre">
            <div class="letra">
                <div class="rot">Importe con letra</div>
                <div class="txt"><?= $e(importeALetras($total, $moneda)) ?></div>
            </div>
            <div class="totales">
                <div class="fila"><span class="et">Subtotal</span><span class="im"><?= $dinero($subtotal) ?></span></div>
                <?php foreach ($traslados as $tasa => $importe): ?>
                    <div class="fila">
                        <span class="et">Traslado IVA · Tasa <?= $e($cifra($tasa)) ?>%</span>
                        <span class="im"><?= $dinero($importe) ?></span>
                    </div>
                <?php endforeach; ?>
                <div class="gran">
                    <span class="et">TOTAL <?= $e($moneda) ?></span>
                    <span class="im">$<?= $dinero($total) ?></span>
                </div>
            </div>
        </div>

        <?php if ($interna): ?>
            <div class="negocio">
                <div class="caja"><div class="rot">Costo total</div><div class="v"><?= $dinero($costoTotal) ?></div></div>
                <div class="caja"><div class="rot">Venta sin IVA</div><div class="v"><?= $dinero($subtotal) ?></div></div>
                <div class="caja"><div class="rot">Utilidad</div>
                    <div class="v <?= $utilidad < 0 ? 'neg' : 'pos' ?>"><?= $dinero($utilidad) ?></div></div>
                <div class="caja"><div class="rot">% de ganancia sobre costo</div>
                    <div class="v"><?= $e(number_format($gananciaPct, 1)) ?>%</div></div>
            </div>
        <?php endif; ?>

        <?php if (trim((string) $cot['notas']) !== ''): ?>
            <div class="notas"><b>Observaciones:</b> <?= nl2br($e($cot['notas'])) ?></div>
        <?php endif; ?>

        <div class="leyenda"><?= $e($emisor['leyenda']) ?></div>
    </div>

    <div class="pie">
        <?= $e($emisor['nombre']) ?> · Tel. <?= $e($emisor['telefono']) ?> · <?= $e($emisor['correo']) ?> · C.P. <?= $e($emisor['cp']) ?>
        · Presupuesto realizado por cuenta <?= $e($cot['creador_nombre'] ?: 'Admin') ?>
    </div>
</div>

</body>
</html>
