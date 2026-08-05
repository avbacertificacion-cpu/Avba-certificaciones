<?php
/**
 * AVBA · Generador de la contraseña del panel de moderación
 *
 * Escribe la contraseña que quieras usar y esta página te devuelve la línea
 * exacta para pegar en config/config.php.
 *
 * Por qué existe: la contraseña nunca se guarda tal cual, se guarda cifrada
 * (un "hash"). Así, aunque alguien llegara a ver el archivo de configuración,
 * no podría deducir la contraseña.
 *
 * Esta herramienta no guarda nada ni modifica ningún archivo: solo calcula.
 * Cuando termines de configurar, bórrala del servidor.
 */

declare(strict_types=1);

header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
// La contraseña viaja en el formulario: que no quede en cachés intermedias.
header('Cache-Control: no-store, no-cache, must-revalidate');

$hash = '';
$aviso = '';
$fuerza = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $clave = (string)($_POST['clave'] ?? '');

    if (mb_strlen($clave) < 10) {
        $aviso = 'Usa al menos 10 caracteres. Esta contraseña protege lo que se publica en tu sitio.';
    } else {
        $hash = password_hash($clave, PASSWORD_DEFAULT);

        // Valoración simple, solo informativa.
        $puntos = 0;
        if (mb_strlen($clave) >= 12)            { $puntos++; }
        if (mb_strlen($clave) >= 16)            { $puntos++; }
        if (preg_match('/[a-záéíóúñ]/u', $clave) && preg_match('/[A-ZÁÉÍÓÚÑ]/u', $clave)) { $puntos++; }
        if (preg_match('/\d/', $clave))         { $puntos++; }
        if (preg_match('/[^\p{L}\p{N}]/u', $clave)) { $puntos++; }
        $fuerza = $puntos;
    }
}

$yaConfigurada = false;
$rutaConfig = __DIR__ . '/config/config.php';
if (is_file($rutaConfig)) {
    $contenido = (string)@file_get_contents($rutaConfig);
    $yaConfigurada = str_contains($contenido, 'MURO_ADMIN_HASH')
        && !str_contains($contenido, 'CAMBIAR_pega_aqui_el_hash');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Contraseña del panel · AVBA</title>
<link rel="icon" href="assets/favicon.png" type="image/png">
<style>
  *{box-sizing:border-box;}
  body{margin:0;font-family:-apple-system,'Segoe UI',Roboto,Arial,sans-serif;
       background:#F4F7FB;color:#12233D;line-height:1.6;}
  .barra{background:#003C78;color:#fff;padding:16px 24px;font-weight:700;}
  .env{max-width:660px;margin:0 auto;padding:28px 20px 60px;}
  .caja{background:#fff;border:1px solid #DDE5EF;border-radius:14px;padding:26px;margin-bottom:20px;}
  h1{font-size:21px;margin:0 0 8px;}
  .intro{font-size:14.5px;color:#5A6880;margin:0 0 22px;}
  label{display:block;font-size:13px;font-weight:700;margin-bottom:6px;}
  input[type=text]{width:100%;padding:13px 14px;border:1.5px solid #DDE5EF;border-radius:10px;
                   font-size:16px;font-family:inherit;}
  input[type=text]:focus{border-color:#0084C0;outline:none;}
  button{margin-top:14px;background:#003C78;color:#fff;border:0;border-radius:10px;
         padding:13px 22px;font-size:15px;font-weight:700;font-family:inherit;cursor:pointer;min-height:44px;}
  button:hover{background:#0A5C9C;}
  .pista{font-size:12.5px;color:#5A6880;margin-top:8px;}
  .aviso{background:#FEF3E2;border:1px solid #F5D08A;color:#7A4A05;padding:12px 15px;
         border-radius:10px;font-size:14px;margin-bottom:18px;}
  .ok{background:#E4F6EE;border:1px solid #A7E0C6;color:#0B5033;padding:12px 15px;
      border-radius:10px;font-size:14px;margin-bottom:18px;}
  .resultado{background:#0B1220;border-radius:12px;padding:18px;margin:18px 0;position:relative;}
  .resultado code{display:block;color:#8FE3C0;font-family:ui-monospace,Menlo,Consolas,monospace;
                  font-size:13px;word-break:break-all;line-height:1.65;}
  .copiar{position:absolute;top:12px;right:12px;background:#1d2b45;color:#cfe3ff;border:0;
          border-radius:8px;padding:7px 12px;font-size:12.5px;font-weight:700;cursor:pointer;margin:0;min-height:auto;}
  .copiar:hover{background:#2a3d5e;}
  ol{padding-left:20px;font-size:14.5px;} ol li{margin-bottom:9px;}
  .fuerza{display:flex;gap:5px;margin-top:10px;}
  .barrita{height:6px;flex:1;border-radius:3px;background:#E3E9F2;}
  .barrita.on{background:#009060;}
  .nota{font-size:13px;color:#5A6880;}
  .peligro{background:#FCECEA;border:1px solid #F3B7B0;color:#8C2318;padding:14px 16px;
           border-radius:10px;font-size:14px;}
  a{color:#0A5C9C;font-weight:700;}
</style>
</head>
<body>

<div class="barra">Contraseña del panel de moderación</div>

<div class="env">

  <?php if ($yaConfigurada && !$hash): ?>
    <div class="ok">
      Ya tienes una contraseña configurada. Si la recuerdas, no necesitas hacer nada:
      entra directo al <a href="moderacion.php">panel de moderación</a>.
      Usa esta página solo si quieres cambiarla.
    </div>
  <?php endif; ?>

  <div class="caja">
    <h1>Elige tu contraseña</h1>
    <p class="intro">
      Escríbela abajo y te doy la línea lista para pegar en tu archivo de configuración.
      La contraseña no se guarda en ningún lado: se convierte en un código cifrado,
      de modo que ni siquiera leyendo el archivo se puede averiguar.
    </p>

    <?php if ($aviso): ?><div class="aviso"><?= htmlspecialchars($aviso) ?></div><?php endif; ?>

    <form method="post" autocomplete="off">
      <label for="clave">Contraseña que quieres usar</label>
      <input type="text" id="clave" name="clave" required minlength="10"
             placeholder="Por ejemplo: Gruas-Altamira-2026!" autofocus>
      <p class="pista">Mínimo 10 caracteres. Se muestra en pantalla para que la copies bien: asegúrate de que nadie más la vea.</p>
      <button type="submit">Generar</button>
    </form>

    <?php if ($fuerza !== null): ?>
      <div class="fuerza" title="Qué tan robusta es">
        <?php for ($i = 1; $i <= 5; $i++): ?>
          <span class="barrita <?= $i <= $fuerza ? 'on' : '' ?>"></span>
        <?php endfor; ?>
      </div>
      <p class="pista">
        Robustez: <?= ['muy baja','baja','aceptable','buena','muy buena','excelente'][$fuerza] ?>.
        Mejora si combinas mayúsculas, minúsculas, números y algún símbolo.
      </p>
    <?php endif; ?>
  </div>

  <?php if ($hash): ?>
    <div class="caja">
      <h1>Ya está. Ahora pega esto</h1>
      <div class="resultado">
        <button class="copiar" type="button" onclick="copiar(this)">Copiar</button>
        <code id="linea">define('MURO_ADMIN_HASH', '<?= htmlspecialchars($hash) ?>');</code>
      </div>

      <ol>
        <li>Entra al <strong>Administrador de archivos</strong> de Hostinger.</li>
        <li>Abre la carpeta del sitio y luego <strong>config</strong> → <strong>config.php</strong>.</li>
        <li>Busca la línea que empieza con <code>define('MURO_ADMIN_HASH'</code> y
            <strong>reemplázala completa</strong> por la de arriba.</li>
        <li>Guarda el archivo.</li>
        <li>Entra al <a href="moderacion.php">panel de moderación</a> con la contraseña que acabas de elegir.</li>
      </ol>

      <p class="nota">
        Si algo no cuadra, abre el <a href="diagnostico.php">diagnóstico</a>: te dirá qué falta.
      </p>
    </div>

    <div class="peligro">
      <strong>Último paso, importante:</strong> cuando ya puedas entrar al panel,
      borra del servidor este archivo (<code>generar-clave.php</code>).
      Ya cumplió su función y no conviene dejarlo publicado.
    </div>
  <?php endif; ?>

</div>

<script>
function copiar(boton){
  var texto = document.getElementById('linea').textContent;
  var listo = function(){ boton.textContent = 'Copiado'; setTimeout(function(){ boton.textContent = 'Copiar'; }, 1800); };
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(texto).then(listo, function(){ manual(texto, listo); });
  } else {
    manual(texto, listo);
  }
}
function manual(texto, listo){
  var t = document.createElement('textarea');
  t.value = texto; t.style.position = 'fixed'; t.style.opacity = '0';
  document.body.appendChild(t); t.select();
  try { document.execCommand('copy'); listo(); } catch (e) {}
  document.body.removeChild(t);
}
</script>

</body>
</html>
