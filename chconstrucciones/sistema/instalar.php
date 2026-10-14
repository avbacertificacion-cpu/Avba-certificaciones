<?php
/*
 * Instalador del sistema.
 *
 * 1. Revisa que el servidor tenga lo necesario (versión de PHP, extensiones).
 * 2. Pide la contraseña de la base de datos que pusiste en config/config.php
 *    (así sólo quien tiene acceso al servidor puede instalar).
 * 3. Crea las tablas y la cuenta del primer administrador.
 *
 * Después de instalar queda bloqueado: ya no se puede volver a ejecutar.
 */
declare(strict_types=1);

require __DIR__ . '/src/nucleo.php';

$config = configuracion();
if ($config) arranque();
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

// ── Requisitos ────────────────────────────────────────────────────────
$requisitos = [
    ['PHP 8.1 o superior', version_compare(PHP_VERSION, '8.1.0', '>='), 'Tienes PHP ' . PHP_VERSION . '. En Hostinger: Avanzado → Configuración de PHP.'],
    ['Extensión PDO MySQL', extension_loaded('pdo_mysql'), 'Actívala en la configuración de PHP.'],
    ['Extensión cURL', extension_loaded('curl'), 'Necesaria para el almacenamiento en la nube.'],
    ['Extensión mbstring', extension_loaded('mbstring'), 'Actívala en la configuración de PHP.'],
    ['Extensión OpenSSL', extension_loaded('openssl'), 'Actívala en la configuración de PHP.'],
    ['Extensión SimpleXML', extension_loaded('simplexml'), 'Necesaria para sincronizar el archivo muerto.'],
    ['Carpeta storage/ con permiso de escritura', is_writable(__DIR__ . '/storage'), 'Dale permisos 755 a la carpeta storage.'],
];
$requisitosOk = !in_array(false, array_column($requisitos, 1), true);

$mensaje = '';
$error = '';
$estado = 'sin_config';
if ($config) $estado = instalado() ? 'instalado' : 'listo';

if ($estado === 'listo' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        if (!$requisitosOk) throw new RuntimeException('Primero resuelve los requisitos marcados en rojo.');
        if (!hash_equals((string) conf('db.clave'), (string) ($_POST['clave_db'] ?? ''))) {
            sleep(2);
            throw new RuntimeException('La contraseña de la base de datos no coincide con la de config/config.php.');
        }
        $nombre = trim((string) ($_POST['nombre'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $clave = (string) ($_POST['clave'] ?? '');
        if ($nombre === '') throw new RuntimeException('Escribe tu nombre.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Escribe un correo válido.');
        if (mb_strlen($clave) < 8) throw new RuntimeException('La contraseña del administrador debe tener al menos 8 caracteres.');
        if ($clave !== (string) ($_POST['clave2'] ?? '')) throw new RuntimeException('Las contraseñas no coinciden.');

        $pdo = db();
        $sql = (string) file_get_contents(__DIR__ . '/sql/esquema.sql');
        // Candado: la base de datos debe ser nueva y sólo de este sistema. Si tiene
        // tablas de otro sistema (por ejemplo, el de AVBA), no se toca nada.
        preg_match_all('/CREATE TABLE IF NOT EXISTS (\w+)/i', $sql, $m);
        $ajenas = array_diff($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN), $m[1]);
        if ($ajenas) {
            throw new RuntimeException('La base de datos ya tiene tablas de otro sistema (' . implode(', ', array_slice($ajenas, 0, 5))
                . (count($ajenas) > 5 ? '…' : '') . '). Crea una base de datos nueva y vacía sólo para este sistema y ponla en config/config.php.');
        }
        $sql = preg_replace('/^--.*$/m', '', $sql) ?? '';
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $sentencia) $pdo->exec($sentencia);

        if ((int) $pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() === 0) {
            $st = $pdo->prepare("INSERT INTO usuarios (nombre, email, password_hash, rol, puesto) VALUES (?, ?, ?, 'admin', 'Administrador del sistema')");
            $st->execute([$nombre, $email, password_hash($clave, PASSWORD_DEFAULT)]);
        }
        foreach (['storage/archivos', 'storage/archivo-muerto'] as $carpeta) {
            if (!is_dir(__DIR__ . "/$carpeta")) mkdir(__DIR__ . "/$carpeta", 0750, true);
        }
        if (!is_file(__DIR__ . '/storage/.secreto')) file_put_contents(__DIR__ . '/storage/.secreto', bin2hex(random_bytes(32)));
        file_put_contents(__DIR__ . '/storage/.instalado', date('c'));
        $estado = 'recien';
    } catch (PDOException $ex) {
        $error = 'No se pudo conectar o crear las tablas en la base de datos. Revisa host, nombre, usuario y contraseña en config/config.php. (' . $ex->getMessage() . ')';
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
    }
}
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Instalación — Gestión documental</title>
<style>
  body { font-family: 'Segoe UI', system-ui, sans-serif; background: #151314; color: #151314; margin: 0; padding: 32px 16px; }
  .caja { max-width: 620px; margin: 0 auto; background: #fff; border-top: 6px solid #eecd02; border-radius: 3px; padding: 28px; }
  h1 { font-size: 24px; margin: 0 0 6px; }
  p { line-height: 1.5; }
  ul.req { list-style: none; padding: 0; margin: 16px 0; }
  ul.req li { padding: 6px 0; border-bottom: 1px solid #e3e3e0; font-size: 14px; }
  .si { color: #2d6a1e; font-weight: 700; } .no { color: #b0261d; font-weight: 700; }
  .ayuda { color: #6f6c72; font-size: 13px; display: block; }
  label { display: block; font-weight: 600; font-size: 13px; margin: 14px 0 4px; }
  input { width: 100%; box-sizing: border-box; padding: 10px; border: 1px solid #c4c4bf; border-radius: 3px; font-size: 15px; }
  button, .boton { display: inline-block; margin-top: 18px; background: #eecd02; color: #151314; border: none; padding: 12px 20px; font-weight: 700; font-size: 15px; border-radius: 3px; cursor: pointer; text-decoration: none; }
  .error { background: #fbe2df; color: #b0261d; border-left: 4px solid #b0261d; padding: 10px 12px; margin: 14px 0; }
  .ok { background: #e3f0da; color: #2d6a1e; border-left: 4px solid #2d6a1e; padding: 10px 12px; margin: 14px 0; }
  code { background: #efefed; padding: 1px 5px; border-radius: 2px; }
  ol li { margin: 6px 0; }
</style>
</head>
<body>
<div class="caja">
  <h1>Instalación del sistema</h1>
  <p class="ayuda">Gestión documental de obras</p>

  <h3>1. Requisitos del servidor</h3>
  <ul class="req">
    <?php foreach ($requisitos as [$nombre, $ok, $ayuda]): ?>
      <li><span class="<?= $ok ? 'si' : 'no' ?>"><?= $ok ? '✓' : '✗' ?></span> <?= $e($nombre) ?>
        <?php if (!$ok): ?><span class="ayuda"><?= $e($ayuda) ?></span><?php endif; ?></li>
    <?php endforeach; ?>
  </ul>

  <?php if ($estado === 'sin_config'): ?>
    <h3>2. Falta la configuración</h3>
    <ol>
      <li>En el administrador de archivos de Hostinger entra a la carpeta <code>config/</code>.</li>
      <li>Copia <code>config.ejemplo.php</code> con el nombre <code>config.php</code>.</li>
      <li>Edita <code>config.php</code> y pon los datos de la base de datos MySQL que creaste.</li>
      <li>Vuelve a abrir esta página.</li>
    </ol>
    <p class="ayuda">La guía completa está en <code>docs/MIGRACION.md</code>.</p>

  <?php elseif ($estado === 'instalado'): ?>
    <div class="ok">El sistema ya está instalado. Esta página quedó bloqueada.</div>
    <a class="boton" href="./">Ir al sistema</a>

  <?php elseif ($estado === 'recien'): ?>
    <div class="ok"><b>¡Listo!</b> Se crearon las tablas y tu cuenta de administrador.</div>
    <p>Siguientes pasos:</p>
    <ol>
      <li>Entra con el correo y la contraseña que acabas de crear.</li>
      <li>En <b>Usuarios</b> da de alta al personal.</li>
      <li>En <b>Diagnóstico</b> (menú Usuarios) prueba el almacenamiento.</li>
    </ol>
    <a class="boton" href="./">Entrar al sistema</a>

  <?php else: ?>
    <h3>2. Crear tablas y administrador</h3>
    <?php if ($error): ?><div class="error"><?= $e($error) ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
      <label>Contraseña de la base de datos (la de config.php)</label>
      <input type="password" name="clave_db" required>
      <span class="ayuda">Sirve para comprobar que tú administras este servidor.</span>
      <label>Tu nombre</label>
      <input type="text" name="nombre" required value="<?= $e($_POST['nombre'] ?? '') ?>">
      <label>Tu correo (con él entrarás al sistema)</label>
      <input type="email" name="email" required value="<?= $e($_POST['email'] ?? '') ?>">
      <label>Contraseña del administrador (mínimo 8 caracteres)</label>
      <input type="password" name="clave" required minlength="8">
      <label>Repite la contraseña</label>
      <input type="password" name="clave2" required minlength="8">
      <button type="submit" <?= $requisitosOk ? '' : 'disabled' ?>>Instalar</button>
    </form>
  <?php endif; ?>
</div>
</body>
</html>
