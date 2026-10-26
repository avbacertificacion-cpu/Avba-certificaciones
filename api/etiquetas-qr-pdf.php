<?php
require_once '../config/config.php';
require_once '../config/qr.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== ROLE_ADMIN) {
    http_response_code(401); exit('No autorizado');
}

$primer   = preg_replace('/\D/', '', $_GET['primer']   ?? '1');
$cantidad = max(1, min(500, intval($_GET['cantidad']   ?? 10)));
// El largo lo manda el código con el que se empieza: si se teclea uno de 13
// dígitos, toda la serie sale de 13. Si no se indica ninguno, el por defecto.
$primer   = $primer ?: '1';
$largo    = max(QR_LARGO_POR_DEFECTO, strlen($primer));
$largo    = min($largo, QR_MAX_DIGITOS);
$primer   = str_pad($primer, $largo, '0', STR_PAD_LEFT);
$primerNum = (int)$primer;

// Dirección que lleva cada QR: la corta (ver q.php), sobre el dominio fijo
// del sistema (ver qrDireccionBase en config/qr.php). Lleva a la misma página
// de validación; con 23 letras menos el QR baja de 41×41 a 33×33 cuadritos.
$baseURL = qrDireccionBase() . '/q.php';

// Generar array de códigos QR
$codigos = [];
for ($i = 0; $i < $cantidad; $i++) {
    $codigos[] = str_pad((string) ($primerNum + $i), $largo, '0', STR_PAD_LEFT);
}

// ─── La plantilla ────────────────────────────────────────────────────────────
// Se enlaza una sola vez por su ruta. Antes se incrustaba en base64 dentro de
// cada etiqueta: 1.7 MB por etiqueta, que con las 160 que propone el
// generador hacían una página de 270 MB que el navegador no alcanzaba a
// dibujar. Ahora el navegador la descarga una vez y la reutiliza.
$plantillaURL = '../public/assets/img/plantilla-etiqueta.png';
$hayPlantilla = file_exists($plantillaURL);

// ─── Geometría, en píxeles de la plantilla (1024 × 1536) ─────────────────────
// Medidas sobre la propia imagen. El diseño final no lleva los recuadros
// verdes que la plantilla trae alrededor del QR y del número: se tapan con
// blanco y encima van el QR y el número, directo sobre el fondo.
const ANCHO_PL = 1024;
const ALTO_PL  = 1536;
const TAPA_QR  = ['x' => 568, 'y' => 652,  'w' => 396, 'h' => 552];   // recuadro verde del QR: 574–958 × 658–1198
const TAPA_COD = ['x' => 50,  'y' => 1292, 'w' => 923, 'h' => 138];   // caja verde del número: 57–966 × 1292–1421
// Medido sobre la etiqueta de referencia: el QR ocupa el 35 % del ancho y
// queda centrado a 3/4 del ancho y a 60 % del alto; el número ocupa el 84 % del
// ancho y sus dígitos miden el 58 % del alto de su franja.
const QR_CENTRO = [772, 931];
const QR_LADO   = 360;            // deja al menos 4 módulos de margen blanco, que es lo que pide un lector
const COD_ANCHO = 860;
const COD_CENTRO_Y = 1366;        // entre la barra verde (termina en 1291) y el pie (empieza en 1438)
const COD_LETRA_MAX = 116;        // con esto los dígitos miden ~84 px, el 58 % de la franja

$porPagina = 9;   // 3 columnas × 3 filas: la hoja de siempre
$paginas   = array_chunk($codigos, $porPagina);

/**
 * Tamaño de letra del número.
 *
 * El diseño usa Arial Black: medido sobre la etiqueta de referencia, cada
 * dígito es 0.93 veces tan ancho como alto, que es justo la proporción de esa
 * letra. Viene en Windows y en Mac, pero no se puede distribuir con el sitio;
 * donde falte entra Montserrat ExtraBold, que sí viaja con el sitio. El ancho
 * final lo fija el SVG (textLength), así que con cualquiera de las dos el
 * número ocupa lo mismo.
 *
 * Con 11 dígitos va a su tamaño máximo; con 13 se reduce lo justo para no
 * tener que comprimir los dígitos. 0.667 em es el ancho de un dígito de Arial
 * Black.
 */
function letraCodigo(string $codigo): float {
    return min((float) COD_LETRA_MAX, COD_ANCHO / (strlen($codigo) * 0.667));
}
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Etiquetas QR - AVBA</title>
    <!-- La librería viaja con el sitio. Venía de cdnjs, y cuando ese servidor
         no respondía los recuadros del QR salían vacíos. -->
    <script src="../public/assets/js/qrcode.min.js"></script>
    <style>
        /* Respaldo para el número donde no haya Arial Black (Linux, Android).
           Licencia SIL OFL: public/assets/fonts/MONTSERRAT-OFL.txt */
        @font-face {
            font-family: 'Montserrat AVBA';
            src: url('../public/assets/fonts/montserrat-latin-800-normal.woff2') format('woff2');
            font-weight: 800; font-style: normal; font-display: block;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #d9dde5; font-family: Arial, Helvetica, sans-serif; }

        .btn-bar { max-width: 21.6cm; margin: 16px auto; display: flex; flex-wrap: wrap;
                   align-items: center; gap: 10px; padding: 0 12px; }
        .btn-bar button { padding: 11px 22px; border: none; border-radius: 7px; cursor: pointer;
                          font-weight: 700; font-size: 14px; }
        .btn-print { background: #667eea; color: #fff; }
        .btn-back  { background: #fff; color: #333; }
        .resumen   { font-size: 13px; color: #374151; }
        .aviso     { width: 100%; background: #fee2e2; color: #991b1b; border-radius: 8px;
                     padding: 10px 14px; font-size: 13px; display: none; }

        /* La hoja reproduce la que imprimía el generador original, medida
           sobre su PDF: 3 × 3 etiquetas de 5.08 × 7.39 cm, con 0.28 cm entre
           ellas, centradas en un A4 y empezando a 1.26 cm del borde superior.

           El original declaraba 5.5 × 8 cm, pero su hoja medía 21 cm de
           ancho más los márgenes, más que el área imprimible del A4, y Chrome
           la reducía sola al 92.4 % al imprimir. Ése es el tamaño que salió
           en papel y el que ya se usa en planta, así que aquí se escribe tal
           cual (5.5, 8 y 0.3 por 0.924) en lugar de depender de que el
           navegador encoja la página. */
        .page { width: 21cm; min-height: 29.7cm; background: #fff; margin: 0 auto 22px;
                padding: 1.262cm 0 0; box-shadow: 0 3px 14px rgba(0,0,0,.18); }
        .etiquetas-grid { display: grid; grid-template-columns: repeat(3, 5.081cm);
                          gap: 0.277cm; justify-content: center; }
        .etiqueta { width: 5.081cm; height: 7.391cm; break-inside: avoid; page-break-inside: avoid; }
        .etiqueta svg { width: 100%; height: 100%; display: block; }

        @page { size: A4 portrait; margin: 8mm; }
        @media print {
            body { background: #fff; }
            .btn-bar { display: none; }
            /* Dentro del margen de 8 mm: lo que falta para llegar a 1.262 cm */
            .page { width: auto; min-height: 0; margin: 0; padding: 0.462cm 0 0; box-shadow: none;
                    page-break-after: always; break-after: page; }
            .page:last-child { page-break-after: auto; break-after: auto; }
            * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
        /* Sólo en PANTALLA. Al imprimir, Chrome evalúa las media queries con
           el ancho de la hoja —carta deja 755 px útiles—, y sin «screen» esta
           regla de teléfono se aplicaba también al PDF: dos columnas de
           9.5 × 14.3 cm, dos etiquetas por hoja. */
        @media screen and (max-width: 760px) {
            .page { width: 100%; min-height: 0; padding: 12px 0; }
            .etiquetas-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px;
                              padding: 0 12px; }
            .etiqueta { width: 100%; height: auto; aspect-ratio: 5.081 / 7.391; }
            .btn-bar button { flex: 1; min-height: 44px; }
        }
    </style>
</head>
<body>

<div class="btn-bar no-print">
    <button class="btn-print" onclick="imprimir()">🖨️ Imprimir / Guardar PDF</button>
    <button class="btn-back"  onclick="window.close()">✕ Cerrar</button>
    <span class="resumen">
        <?= $cantidad ?> etiqueta(s) · <?= count($paginas) ?> hoja(s) ·
        <?= $h($codigos[0]) ?> al <?= $h($codigos[count($codigos) - 1]) ?>
    </span>
    <div class="aviso" id="aviso"></div>
</div>

<?php foreach ($paginas as $pag): ?>
<div class="page">
    <div class="etiquetas-grid">
    <?php foreach ($pag as $codigo): $f = letraCodigo($codigo); ?>
        <div class="etiqueta">
            <svg viewBox="0 0 <?= ANCHO_PL ?> <?= ALTO_PL ?>" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg"
                 role="img" aria-label="Etiqueta <?= $h($codigo) ?>">
                <?php if ($hayPlantilla): ?>
                <image href="<?= $h($plantillaURL) ?>" x="0" y="0"
                       width="<?= ANCHO_PL ?>" height="<?= ALTO_PL ?>" preserveAspectRatio="none"/>
                <?php else: ?>
                <rect width="<?= ANCHO_PL ?>" height="<?= ALTO_PL ?>" rx="60" fill="#fff" stroke="#0b1f44" stroke-width="18"/>
                <?php endif; ?>
                <!-- Fuera los recuadros verdes de la plantilla -->
                <rect x="<?= TAPA_QR['x'] ?>"  y="<?= TAPA_QR['y'] ?>"  width="<?= TAPA_QR['w'] ?>"  height="<?= TAPA_QR['h'] ?>"  fill="#fff"/>
                <rect x="<?= TAPA_COD['x'] ?>" y="<?= TAPA_COD['y'] ?>" width="<?= TAPA_COD['w'] ?>" height="<?= TAPA_COD['h'] ?>" fill="#fff"/>
                <!-- El QR se dibuja aquí como vector (ver el script de abajo) -->
                <g class="qr" data-codigo="<?= $h($codigo) ?>"
                   transform="translate(<?= QR_CENTRO[0] - QR_LADO / 2 ?> <?= QR_CENTRO[1] - QR_LADO / 2 ?>)"></g>
                <!-- El número. Se centra verticalmente con la altura de sus
                     dígitos (~0.73 em en las dos letras). -->
                <text x="<?= ANCHO_PL / 2 ?>" y="<?= round(COD_CENTRO_Y + 0.73 * $f / 2) ?>"
                      text-anchor="middle"
                      font-family="'Arial Black', 'Montserrat AVBA', 'Arial', sans-serif"
                      font-weight="800" font-size="<?= round($f) ?>" fill="#0d1726"
                      textLength="<?= COD_ANCHO ?>" lengthAdjust="spacingAndGlyphs"><?= $h($codigo) ?></text>
            </svg>
        </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>

<script>
// Se imprime cuando la letra del número ya cargó: si no, la primera hoja
// podría salir con la letra de respaldo del navegador.
function imprimir() {
    (document.fonts ? document.fonts.ready : Promise.resolve()).then(() => window.print());
}

// El QR lleva la dirección pública de validación: con cualquier cámara abre la
// ficha del extintor, y el lector del sistema saca el código del ?qr=.
const baseURL = <?= json_encode($baseURL) ?>;
const LADO = <?= QR_LADO ?>;

(function () {
    if (typeof QRCode === 'undefined') {
        const a = document.getElementById('aviso');
        a.textContent = 'No se pudo cargar el generador de códigos QR: las etiquetas saldrían sin QR. '
                      + 'Recarga la página antes de imprimir.';
        a.style.display = 'block';
        return;
    }
    // Un solo contenedor oculto para calcular cada matriz
    const tmp = document.createElement('div');
    tmp.style.display = 'none';
    document.body.appendChild(tmp);

    document.querySelectorAll('g.qr').forEach(function (g) {
        tmp.innerHTML = '';
        const q = new QRCode(tmp, {
            text: baseURL + '?qr=' + encodeURIComponent(g.dataset.codigo),
            width: 8, height: 8, correctLevel: QRCode.CorrectLevel.M
        });
        const m = q._oQRCode, n = m.getModuleCount(), t = LADO / n;
        // Se juntan los módulos oscuros contiguos de cada renglón en un solo
        // trazo: el QR queda como vector y se imprime nítido a cualquier tamaño.
        let d = '';
        for (let r = 0; r < n; r++) {
            let c = 0;
            while (c < n) {
                if (!m.isDark(r, c)) { c++; continue; }
                let fin = c;
                while (fin < n && m.isDark(r, fin)) fin++;
                d += 'M' + (c * t).toFixed(2) + ' ' + (r * t).toFixed(2)
                   + 'h' + ((fin - c) * t).toFixed(2) + 'v' + t.toFixed(2)
                   + 'h-' + ((fin - c) * t).toFixed(2) + 'z';
                c = fin;
            }
        }
        const p = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        p.setAttribute('d', d);
        p.setAttribute('fill', '#000');
        p.setAttribute('shape-rendering', 'crispEdges');
        g.appendChild(p);
    });
    tmp.remove();
})();
</script>
</body>
</html>
