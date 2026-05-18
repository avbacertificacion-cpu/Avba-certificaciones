<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Prueba — Certificado AVBA</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Segoe UI', Arial, sans-serif; background: #f0f4f8; min-height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center; }

  .test-banner {
    background: #f59e0b;
    color: #7c2d12;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 1px;
    text-transform: uppercase;
    text-align: center;
    padding: 8px 24px;
    width: 100%;
    position: fixed;
    top: 0;
  }

  .card {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 4px 32px rgba(11,37,69,.12);
    padding: 48px 56px;
    max-width: 500px;
    width: 90%;
    text-align: center;
    margin-top: 40px;
  }

  .logo-area { margin-bottom: 28px; }
  .logo-area img { height: 64px; width: auto; }

  h1 { font-size: 18px; font-weight: 700; color: #0B2545; margin-bottom: 6px; }
  .subtitle { font-size: 13px; color: #64748b; margin-bottom: 32px; }

  .divider { border: none; border-top: 1px solid #e2e8f0; margin: 28px 0; }

  .btn {
    display: inline-block;
    padding: 14px 32px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 700;
    text-decoration: none;
    cursor: pointer;
    border: none;
    letter-spacing: .3px;
    transition: opacity .15s;
  }
  .btn:hover { opacity: .88; }

  .btn-primary {
    background: #0B2545;
    color: #fff;
    width: 100%;
    margin-bottom: 12px;
  }
  .btn-secondary {
    background: #f4f7fb;
    color: #0B2545;
    border: 1.5px solid #cdd8e3;
    width: 100%;
  }

  .info-box {
    background: #f4f7fb;
    border: 1px solid #cdd8e3;
    border-radius: 8px;
    padding: 14px 18px;
    text-align: left;
    margin-bottom: 28px;
  }
  .info-box p { font-size: 12px; color: #64748b; line-height: 1.6; }
  .info-box p + p { margin-top: 4px; }
  .info-box strong { color: #0B2545; }

  .tag-temporal {
    display: inline-block;
    background: #fef3c7;
    color: #92400e;
    border: 1px solid #f59e0b;
    border-radius: 4px;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: .5px;
    padding: 2px 8px;
    text-transform: uppercase;
    margin-bottom: 20px;
  }

  .footer-note { font-size: 11px; color: #94a3b8; margin-top: 24px; }
</style>
</head>
<body>

<div class="test-banner">⚠ Entorno de pruebas — No usar en producción</div>

<div class="card">

  <div class="logo-area">
    <img src="assets/logos/avba.png" alt="AVBA Inspections">
  </div>

  <div class="tag-temporal">Temporal · QA</div>

  <h1>Generador de Certificado</h1>
  <p class="subtitle">Prueba de renderizado PDF — Hostinger</p>

  <hr class="divider">

  <div class="info-box">
    <p><strong>Plantilla:</strong> certificado_preview.html</p>
    <p><strong>Motor PDF:</strong> mPDF (compatible Hostinger shared)</p>
    <p><strong>Páginas:</strong> 1 página / Letter 215×279 mm</p>
    <p><strong>DPI:</strong> 96</p>
  </div>

  <a class="btn btn-primary" href="generar_cert_web.php" target="_blank">
    ▶ Ver PDF en el navegador
  </a>

  <a class="btn btn-secondary" href="generar_cert_web.php?descarga=1">
    ⬇ Descargar PDF
  </a>

  <p class="footer-note">
    AVBA Inspections, Certifications and Maintenance SAS. de C.V.
  </p>

</div>

</body>
</html>
