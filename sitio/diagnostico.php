<?php
/**
 * AVBA · Diagnóstico del muro de operadores
 *
 * Abre https://www.avba.com.mx/diagnostico.php y esta página revisa sola
 * que todo esté listo: configuración, base de datos, carpeta de fotografías
 * y procesamiento de imágenes.
 *
 * No muestra contraseñas ni rutas del servidor.
 */

declare(strict_types=1);

header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');

$rutaConfig = __DIR__ . '/config/config.php';
$hayConfig  = is_file($rutaConfig);

if ($hayConfig) {
    require_once $rutaConfig;
}

// Si ya hay contraseña configurada, el diagnóstico queda detrás de ella.
$protegido = $hayConfig && defined('MURO_ADMIN_HASH');
$acceso    = !$protegido;
$errorClave = '';

if ($protegido) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Strict',
        'cookie_secure'   => !empty($_SERVER['HTTPS']),
    ]);
    if (!empty($_SESSION['auth'])) {
        $acceso = true;
    } elseif (isset($_POST['clave'])) {
        usleep(400000);
        if (password_verify((string)$_POST['clave'], MURO_ADMIN_HASH)) {
            session_regenerate_id(true);
            $_SESSION['auth'] = true;
            $acceso = true;
        } else {
            $errorClave = 'Contraseña incorrecta.';
        }
    }
}

$dirSubidas = __DIR__ . '/uploads/muro/';
$pruebas = [];

function prueba(string $titulo, bool $ok, string $detalle, string $comoArreglar = ''): array {
    return compact('titulo', 'ok', 'detalle', 'comoArreglar');
}

if ($acceso) {

    // ── 1. Configuración ──────────────────────────────────────
    $pruebas[] = prueba(
        'Archivo de configuración',
        $hayConfig,
        $hayConfig ? 'Encontrado y cargado.' : 'No existe config/config.php.',
        'Copia config/config.example.php como config/config.php y rellena los datos.'
    );

    $constantes = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'MURO_ADMIN_HASH', 'IP_SALT'];
    $faltan = array_values(array_filter($constantes, fn($c) => !defined($c)));
    $pruebas[] = prueba(
        'Datos de configuración completos',
        $hayConfig && !$faltan,
        $faltan ? 'Faltan: ' . implode(', ', $faltan) : 'Los seis valores están definidos.',
        'Revisa que config.php defina las seis constantes.'
    );

    // ── 2. Base de datos ──────────────────────────────────────
    $pdo = null;
    if ($hayConfig && !$faltan) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
            );
            $pruebas[] = prueba('Conexión a la base de datos', true, 'Conectado correctamente.');
        } catch (PDOException $e) {
            // Se muestra solo el tipo de fallo, nunca la cadena de conexión.
            $pruebas[] = prueba(
                'Conexión a la base de datos', false,
                'No se pudo conectar (código ' . (int)$e->getCode() . ').',
                'Revisa DB_HOST, DB_NAME, DB_USER y DB_PASS en config.php.'
            );
        }
    } else {
        $pruebas[] = prueba('Conexión a la base de datos', false, 'No se intentó: falta configuración.');
    }

    // ── 3. Tabla del muro ─────────────────────────────────────
    if ($pdo) {
        try {
            $existe = (bool)$pdo->query("SHOW TABLES LIKE 'muro_publicaciones'")->fetchColumn();
            if ($existe) {
                $n = (int)$pdo->query('SELECT COUNT(*) FROM muro_publicaciones')->fetchColumn();
                $pend = (int)$pdo->query("SELECT COUNT(*) FROM muro_publicaciones WHERE estado='pendiente'")->fetchColumn();
                $apro = (int)$pdo->query("SELECT COUNT(*) FROM muro_publicaciones WHERE estado='aprobado'")->fetchColumn();
                $pruebas[] = prueba('Tabla muro_publicaciones', true,
                    "Existe. $n publicaciones en total · $pend pendientes · $apro aprobadas.");
            } else {
                $pruebas[] = prueba('Tabla muro_publicaciones', false, 'La tabla no existe.',
                    'Ejecuta el contenido de sql/muro.sql en phpMyAdmin.');
            }
        } catch (PDOException $e) {
            $pruebas[] = prueba('Tabla muro_publicaciones', false, 'No se pudo consultar.',
                'Ejecuta sql/muro.sql en phpMyAdmin.');
        }
    }

    // ── 4. Carpeta de fotografías (el famoso paso 3) ──────────
    if (!is_dir($dirSubidas)) {
        @mkdir($dirSubidas, 0755, true);
    }
    $existeDir = is_dir($dirSubidas);
    $escribible = $existeDir && is_writable($dirSubidas);

    // Prueba real: escribir y borrar un archivo.
    $pruebaEscritura = false;
    if ($escribible) {
        $tmp = $dirSubidas . '.prueba-' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, 'ok') !== false) {
            $pruebaEscritura = true;
            @unlink($tmp);
        }
    }
    $pruebas[] = prueba(
        'Carpeta de fotografías',
        $pruebaEscritura,
        $pruebaEscritura
            ? 'La carpeta uploads/muro/ existe y el servidor puede guardar ahí.'
            : ($existeDir ? 'La carpeta existe pero no se puede escribir en ella.' : 'No se pudo crear la carpeta.'),
        'En el Administrador de archivos de Hostinger, entra a uploads/, crea la carpeta muro y dale permisos 755.'
    );

    // ── 5. Protección de la carpeta ───────────────────────────
    $hayHtaccess = is_file($dirSubidas . '.htaccess');
    $pruebas[] = prueba(
        'Protección de la carpeta',
        $hayHtaccess,
        $hayHtaccess ? 'El .htaccess que impide ejecutar scripts está en su lugar.' : 'Falta uploads/muro/.htaccess.',
        'Vuelve a desplegar el sitio: ese archivo viaja en el repositorio.'
    );

    // ── 6. Procesamiento de imágenes ──────────────────────────
    $hayGd = extension_loaded('gd') && function_exists('imagecreatetruecolor');
    $formatos = [];
    if ($hayGd) {
        foreach (['jpeg' => 'imagejpeg', 'png' => 'imagecreatefrompng', 'webp' => 'imagecreatefromwebp'] as $f => $fn) {
            if (function_exists($fn)) { $formatos[] = strtoupper($f); }
        }
    }
    $pruebas[] = prueba(
        'Procesamiento de imágenes',
        $hayGd,
        $hayGd ? 'Disponible. Formatos: ' . implode(', ', $formatos) . '.' : 'La extensión GD no está activa.',
        'Actívala en hPanel → Avanzado → Configuración de PHP → Extensiones → GD.'
    );

    // ── 7. Tamaño máximo de subida ────────────────────────────
    $subidaMax = ini_get('upload_max_filesize');
    $postMax   = ini_get('post_max_size');
    $aBytes = static function (string $v): int {
        $v = trim($v); $u = strtolower(substr($v, -1)); $n = (int)$v;
        return match ($u) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
    };
    $suficiente = $aBytes((string)$subidaMax) >= 8 * 1048576 && $aBytes((string)$postMax) >= 8 * 1048576;
    $pruebas[] = prueba(
        'Tamaño máximo de subida',
        $suficiente,
        "El servidor acepta hasta $subidaMax por archivo (envío total: $postMax).",
        'El muro pide 8 MB. Súbelo en hPanel → Configuración de PHP, o dile a Claude que baje el límite del formulario.'
    );
}

$todoOk = $acceso && $pruebas && !array_filter($pruebas, fn($p) => !$p['ok']);
$fallan  = array_values(array_filter($pruebas, fn($p) => !$p['ok']));
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Diagnóstico del muro · AVBA</title>
<link rel="icon" href="assets/favicon.png" type="image/png">
<style>
  *{box-sizing:border-box;}
  body{margin:0;font-family:-apple-system,'Segoe UI',Roboto,Arial,sans-serif;
       background:#F4F7FB;color:#12233D;line-height:1.55;}
  .barra{background:#003C78;color:#fff;padding:16px 24px;font-weight:700;}
  .env{max-width:780px;margin:0 auto;padding:28px 20px 60px;}
  .veredicto{border-radius:14px;padding:22px 24px;margin-bottom:26px;}
  .v-ok{background:#E4F6EE;border:1px solid #A7E0C6;color:#0B5033;}
  .v-no{background:#FEF3E2;border:1px solid #F5D08A;color:#7A4A05;}
  .veredicto h2{margin:0 0 6px;font-size:19px;}
  .veredicto p{margin:0;font-size:14.5px;}
  .item{background:#fff;border:1px solid #DDE5EF;border-radius:12px;
        padding:16px 18px;margin-bottom:12px;display:flex;gap:14px;align-items:flex-start;}
  .marca{width:26px;height:26px;border-radius:50%;flex-shrink:0;color:#fff;font-weight:800;
         display:flex;align-items:center;justify-content:center;font-size:15px;}
  .si{background:#009060;} .no{background:#E1483C;}
  .item h3{margin:0 0 3px;font-size:15px;}
  .item p{margin:0;font-size:13.5px;color:#5A6880;}
  .arreglo{margin-top:8px;font-size:13px;background:#F4F7FB;border-left:3px solid #0084C0;
           padding:9px 12px;border-radius:0 8px 8px 0;color:#12233D;}
  .login{max-width:380px;margin:12vh auto;background:#fff;border:1px solid #DDE5EF;
         border-radius:16px;padding:32px;}
  .login h1{font-size:19px;margin:0 0 6px;}
  .login p{font-size:13.5px;color:#5A6880;margin:0 0 20px;}
  input{width:100%;padding:12px 14px;border:1.5px solid #DDE5EF;border-radius:10px;
        font-size:15px;font-family:inherit;}
  button{width:100%;margin-top:14px;background:#003C78;color:#fff;border:0;border-radius:10px;
         padding:12px;font-size:15px;font-weight:700;font-family:inherit;cursor:pointer;min-height:44px;}
  .err{background:#FCECEA;border:1px solid #F3B7B0;color:#8C2318;padding:10px 14px;
       border-radius:9px;font-size:13.5px;margin-bottom:14px;}
  .pie{margin-top:24px;font-size:13px;color:#5A6880;}
  .pie a{color:#0A5C9C;font-weight:700;}
</style>
</head>
<body>

<?php if (!$acceso): ?>
  <div class="login">
    <h1>Diagnóstico del muro</h1>
    <p>Usa la misma contraseña del panel de moderación.</p>
    <?php if ($errorClave): ?><div class="err"><?= htmlspecialchars($errorClave) ?></div><?php endif; ?>
    <form method="post">
      <input type="password" name="clave" placeholder="Contraseña" required autofocus aria-label="Contraseña">
      <button type="submit">Revisar</button>
    </form>
  </div>
<?php else: ?>

  <div class="barra">Diagnóstico del muro de operadores</div>
  <div class="env">

    <div class="veredicto <?= $todoOk ? 'v-ok' : 'v-no' ?>">
      <?php if ($todoOk): ?>
        <h2>Todo listo</h2>
        <p>El muro está funcionando. Ya puedes recibir publicaciones y moderarlas desde el panel.</p>
      <?php else: ?>
        <h2>Falta <?= count($fallan) ?> <?= count($fallan) === 1 ? 'cosa' : 'cosas' ?></h2>
        <p>Abajo está marcado en rojo qué es y cómo resolverlo.</p>
      <?php endif; ?>
    </div>

    <?php foreach ($pruebas as $p): ?>
      <div class="item">
        <span class="marca <?= $p['ok'] ? 'si' : 'no' ?>" aria-hidden="true"><?= $p['ok'] ? '✓' : '!' ?></span>
        <div>
          <h3><?= htmlspecialchars($p['titulo']) ?></h3>
          <p><?= htmlspecialchars($p['detalle']) ?></p>
          <?php if (!$p['ok'] && $p['comoArreglar']): ?>
            <div class="arreglo"><strong>Cómo resolverlo:</strong> <?= htmlspecialchars($p['comoArreglar']) ?></div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>

    <p class="pie">
      <?php if ($todoOk): ?>
        Ir al <a href="moderacion.php">panel de moderación</a> · ver el <a href="index.html#muro">muro en el sitio</a>.
        Cuando todo funcione puedes borrar este archivo del servidor: ya no hace falta.
      <?php else: ?>
        Resuelve lo marcado y recarga esta página para volver a revisar.
      <?php endif; ?>
    </p>
  </div>

<?php endif; ?>

</body>
</html>
