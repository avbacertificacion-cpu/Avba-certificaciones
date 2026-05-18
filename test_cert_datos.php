<?php
/**
 * test_cert_datos.php
 * Página de prueba — genera certificados PDF con datos editables.
 * USO EXCLUSIVO EN QA / HOSTINGER. No exponer en producción.
 */

// ── Modo PDF: recibir POST y stream directo al navegador ────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    require __DIR__ . '/vendor/autoload.php';

    $tipo = $_POST['tipo'] ?? 'equipos';

    $templates = [
        'equipos'    => __DIR__ . '/certificado_preview.html',
        'accesorios' => __DIR__ . '/certificado_accesorios_preview.html',
    ];
    $templatePath = $templates[$tipo] ?? $templates['equipos'];

    if (!file_exists($templatePath)) {
        http_response_code(500);
        die('ERROR: plantilla no encontrada: ' . basename($templatePath));
    }

    $e = fn($k, $def = '') => htmlspecialchars(trim($_POST[$k] ?? $def), ENT_QUOTES, 'UTF-8');

    $folio           = $e('folio',           'CERT-TEST-001');
    $cliente         = $e('cliente',         'EMPRESA DE PRUEBA SA DE CV');
    $domicilio       = $e('domicilio',       'AV. REFORMA 123, COL. CENTRO, CDMX CP 06600');
    $fecha_inspeccion = $e('fecha_inspeccion', date('d/m/Y'));
    $no_acreditacion  = $e('no_acreditacion',  '0147-I-0022');

    // Vigencia = fecha inspección + 1 año
    $vigencia = '';
    try {
        $fv = DateTime::createFromFormat('d/m/Y', $fecha_inspeccion)
              ?: new DateTime($fecha_inspeccion);
        $fv->modify('+1 year');
        $vigencia = $fv->format('d/m/Y');
    } catch (Exception $ex) {
        $vigencia = '—';
    }

    $qr_placeholder = 'data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==';

    // Campos comunes
    $map = [
        '{folio}'           => $folio,
        '{cliente}'         => $cliente,
        '{domicilio}'       => $domicilio,
        '{fecha_inspeccion}'=> $fecha_inspeccion,
        '{vigencia}'        => $vigencia,
        '{no_acreditacion}' => $no_acreditacion,
        '{qr_imagen}'       => $qr_placeholder,
    ];

    // Campos específicos por tipo
    if ($tipo === 'equipos') {
        $map['{tipo_maquinaria}']   = $e('tipo_maquinaria',   'MONTACARGAS ELÉCTRICO');
        $map['{capacidad}']         = $e('capacidad',         '3,000 KG');
        $map['{marca}']             = $e('marca',             'TOYOTA');
        $map['{modelo}']            = $e('modelo',            '8FGU30');
        $map['{no_serie}']          = $e('no_serie',          '8FGU30-00001');
        $map['{no_identificacion}'] = $e('no_identificacion', 'MF-001');
    } elseif ($tipo === 'accesorios') {
        $map['{resumen_items}'] = $e('resumen_items', '3 eslingas cadena G80 / 2 grilletes forjados 3/4" / 1 gancho de seguridad');
    }

    $html = file_get_contents($templatePath);
    $html = str_replace(array_keys($map), array_values($map), $html);

    $override = '<style>.page{min-height:0!important;margin:0!important;box-shadow:none!important;}</style>';
    $html = str_replace('</head>', $override . '</head>', $html);

    if (!class_exists('\\Mpdf\\Mpdf')) {
        http_response_code(500);
        die('ERROR: mPDF no disponible. Verifica vendor/autoload.php.');
    }

    $mpdf = new \Mpdf\Mpdf([
        'mode'          => 'utf-8',
        'format'        => [215, 279],
        'margin_left'   => 0,
        'margin_right'  => 0,
        'margin_top'    => 0,
        'margin_bottom' => 0,
        'margin_header' => 0,
        'margin_footer' => 0,
        'dpi'           => 96,
        'default_font'  => 'dejavusans',
    ]);
    $mpdf->SetBasePath(__DIR__ . '/');
    $mpdf->SetHTMLFooter('');
    $mpdf->use_kwt          = false;
    $mpdf->useSubstitutions = true;
    $mpdf->WriteHTML($html);

    $nombre = 'certificado_' . $tipo . '_prueba.pdf';
    $modo   = ($_POST['accion'] === 'descargar') ? 'D' : 'I';
    $mpdf->Output($nombre, $modo);
    exit;
}

$tipo_activo = $_GET['tipo'] ?? 'equipos';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Prueba Certificados — AVBA</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Segoe UI', Arial, sans-serif; background: #f0f4f8; min-height: 100vh; padding-bottom: 40px; }
.banner { background: #f59e0b; color: #7c2d12; font-size: 11px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; text-align: center; padding: 9px 24px; }
.container { max-width: 720px; margin: 32px auto 0; padding: 0 16px; }
.card { background: #fff; border-radius: 12px; box-shadow: 0 4px 24px rgba(11,37,69,.10); overflow: hidden; }
.tabs { display: flex; border-bottom: 2px solid #e2e8f0; }
.tab { flex: 1; padding: 14px 20px; font-size: 13px; font-weight: 700; color: #64748b; text-align: center; cursor: pointer; text-decoration: none; border-bottom: 3px solid transparent; margin-bottom: -2px; transition: color .15s, border-color .15s; }
.tab.active { color: #0B2545; border-bottom-color: #0B2545; }
.tab:hover:not(.active) { color: #2060a8; }
.form-body { padding: 32px 40px 36px; }
.logo-area { text-align: center; margin-bottom: 22px; }
.logo-area img { height: 48px; width: auto; }
h1 { font-size: 17px; font-weight: 700; color: #0B2545; margin-bottom: 4px; text-align: center; }
.subtitle { font-size: 12px; color: #64748b; text-align: center; margin-bottom: 26px; }
.section-title { font-size: 10px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; color: #94a3b8; margin: 20px 0 10px; padding-bottom: 6px; border-bottom: 1px solid #e2e8f0; }
.grid { display: grid; gap: 12px; }
.grid-2 { grid-template-columns: 1fr 1fr; }
.grid-3 { grid-template-columns: 1fr 1fr 1fr; }
label { display: block; font-size: 11px; font-weight: 600; color: #334155; margin-bottom: 4px; }
input[type=text], textarea {
  width: 100%; padding: 8px 10px; border: 1.5px solid #cbd5e1; border-radius: 6px;
  font-size: 13px; color: #1e293b; background: #f8fafc; transition: border-color .15s;
  font-family: inherit;
}
textarea { resize: vertical; min-height: 72px; line-height: 1.5; }
input[type=text]:focus, textarea:focus { outline: none; border-color: #2060a8; background: #fff; }
.actions { display: flex; gap: 12px; margin-top: 28px; }
.btn { flex: 1; padding: 13px 20px; border-radius: 8px; font-size: 13px; font-weight: 700; border: none; cursor: pointer; letter-spacing: .3px; transition: opacity .15s; }
.btn:hover { opacity: .87; }
.btn-primary { background: #0B2545; color: #fff; }
.btn-secondary { background: #f4f7fb; color: #0B2545; border: 1.5px solid #cdd8e3; }
.info-row { background: #f4f7fb; border: 1px solid #cdd8e3; border-radius: 8px; padding: 11px 16px; font-size: 11.5px; color: #64748b; margin-top: 18px; line-height: 1.6; }
.info-row strong { color: #0B2545; }
</style>
</head>
<body>

<div class="banner">⚠ Entorno de pruebas — No usar en producción</div>

<div class="container">
<div class="card">

  <div class="tabs">
    <a class="tab <?= $tipo_activo === 'equipos' ? 'active' : '' ?>" href="?tipo=equipos">Certificado de Equipos</a>
    <a class="tab <?= $tipo_activo === 'accesorios' ? 'active' : '' ?>" href="?tipo=accesorios">Certificado de Accesorios</a>
  </div>

  <div class="form-body">
    <div class="logo-area">
      <img src="assets/logos/avba.png" alt="AVBA">
    </div>

<?php if ($tipo_activo === 'equipos'): ?>

    <h1>Certificado de Equipos — Prueba</h1>
    <p class="subtitle">Completa los datos y genera el PDF para verificar en Hostinger</p>

    <form method="POST" target="_blank">
      <input type="hidden" name="tipo" value="equipos">

      <div class="section-title">Identificación del certificado</div>
      <div class="grid grid-2">
        <div><label>Folio</label><input type="text" name="folio" value="CERT-TEST-001"></div>
        <div><label>No. Acreditación EMA</label><input type="text" name="no_acreditacion" value="0147-I-0022"></div>
      </div>

      <div class="section-title">Datos del cliente</div>
      <div class="grid">
        <div><label>Cliente</label><input type="text" name="cliente" value="EMPRESA DE PRUEBA SA DE CV"></div>
        <div><label>Domicilio</label><input type="text" name="domicilio" value="AV. REFORMA 123, COL. CENTRO, CDMX CP 06600"></div>
      </div>

      <div class="section-title">Datos del equipo</div>
      <div class="grid grid-2">
        <div><label>Tipo de maquinaria</label><input type="text" name="tipo_maquinaria" value="MONTACARGAS ELÉCTRICO"></div>
        <div><label>Capacidad</label><input type="text" name="capacidad" value="3,000 KG"></div>
      </div>
      <div class="grid grid-3">
        <div><label>Marca</label><input type="text" name="marca" value="TOYOTA"></div>
        <div><label>Modelo</label><input type="text" name="modelo" value="8FGU30"></div>
        <div><label>No. Serie</label><input type="text" name="no_serie" value="8FGU30-00001"></div>
      </div>
      <div class="grid grid-2">
        <div><label>No. Identificación</label><input type="text" name="no_identificacion" value="MF-001"></div>
        <div><label>Fecha de inspección (dd/mm/aaaa)</label><input type="text" name="fecha_inspeccion" value="<?= date('d/m/Y') ?>"></div>
      </div>

      <div class="actions">
        <button class="btn btn-primary" type="submit" name="accion" value="ver">▶ Ver PDF</button>
        <button class="btn btn-secondary" type="submit" name="accion" value="descargar">⬇ Descargar PDF</button>
      </div>

      <div class="info-row">
        <strong>Plantilla:</strong> certificado_preview.html &nbsp;·&nbsp;
        <strong>Vigencia:</strong> +1 año a la fecha de inspección
      </div>
    </form>

<?php else: ?>

    <h1>Certificado de Accesorios — Prueba</h1>
    <p class="subtitle">Completa los datos y genera el PDF para verificar en Hostinger</p>

    <form method="POST" target="_blank">
      <input type="hidden" name="tipo" value="accesorios">

      <div class="section-title">Identificación del certificado</div>
      <div class="grid grid-2">
        <div><label>Folio</label><input type="text" name="folio" value="ACC-TEST-001"></div>
        <div><label>No. Acreditación EMA</label><input type="text" name="no_acreditacion" value="0147-I-0022"></div>
      </div>

      <div class="section-title">Datos del cliente</div>
      <div class="grid">
        <div><label>Cliente</label><input type="text" name="cliente" value="EMPRESA DE PRUEBA SA DE CV"></div>
        <div><label>Domicilio</label><input type="text" name="domicilio" value="AV. REFORMA 123, COL. CENTRO, CDMX CP 06600"></div>
      </div>

      <div class="section-title">Ítems inspeccionados</div>
      <div class="grid">
        <div>
          <label>Resumen de ítems inspeccionados</label>
          <textarea name="resumen_items">3 eslingas cadena G80 1/2" cap. 3.5 t / 2 grilletes forjados 3/4" / 1 gancho de seguridad c/pasador</textarea>
        </div>
        <div><label>Fecha de inspección (dd/mm/aaaa)</label><input type="text" name="fecha_inspeccion" value="<?= date('d/m/Y') ?>"></div>
      </div>

      <div class="actions">
        <button class="btn btn-primary" type="submit" name="accion" value="ver">▶ Ver PDF</button>
        <button class="btn btn-secondary" type="submit" name="accion" value="descargar">⬇ Descargar PDF</button>
      </div>

      <div class="info-row">
        <strong>Plantilla:</strong> certificado_accesorios_preview.html &nbsp;·&nbsp;
        <strong>Vigencia:</strong> +1 año a la fecha de inspección
      </div>
    </form>

<?php endif; ?>

  </div><!-- /.form-body -->
</div><!-- /.card -->
</div><!-- /.container -->

</body>
</html>
