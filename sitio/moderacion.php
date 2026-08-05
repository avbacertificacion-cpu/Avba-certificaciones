<?php
/**
 * AVBA · Panel de moderación del muro de operadores
 *
 * Aquí se aprueba o rechaza lo que envían los visitantes. Nada aparece en
 * el sitio público hasta que se aprueba desde esta pantalla.
 *
 * Acceso: https://www.avba.com.mx/moderacion.php
 */

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Strict',
    'cookie_secure'   => !empty($_SERVER['HTTPS']),
]);

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

const DIR_SUBIDAS = __DIR__ . '/uploads/muro/';

function pdo(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
             PDO::ATTR_EMULATE_PREPARES => false]
        );
    }
    return $pdo;
}

function e(?string $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function tokenValido(): bool {
    return !empty($_POST['csrf'])
        && !empty($_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], (string)$_POST['csrf']);
}

// ── Salir ────────────────────────────────────────────────────
if (isset($_GET['salir'])) {
    session_destroy();
    header('Location: moderacion.php');
    exit;
}

// ── Entrar ───────────────────────────────────────────────────
$errorLogin = '';
if (($_POST['accion'] ?? '') === 'entrar') {
    // Espera entre intentos: hace inviable probar contraseñas por fuerza bruta.
    usleep(400000);
    if (password_verify((string)($_POST['clave'] ?? ''), MURO_ADMIN_HASH)) {
        session_regenerate_id(true);
        $_SESSION['auth'] = true;
    } else {
        $errorLogin = 'Contraseña incorrecta.';
    }
}

$autenticado = !empty($_SESSION['auth']);

// ── Moderar ──────────────────────────────────────────────────
$aviso = '';
if ($autenticado && in_array($_POST['accion'] ?? '', ['aprobar', 'rechazar', 'eliminar'], true)) {
    if (!tokenValido()) {
        $aviso = 'Sesión expirada, vuelve a intentarlo.';
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $accion = $_POST['accion'];

        if ($accion === 'eliminar') {
            $st = pdo()->prepare('SELECT archivo FROM muro_publicaciones WHERE id = ?');
            $st->execute([$id]);
            $archivo = $st->fetchColumn();
            if ($archivo && is_file(DIR_SUBIDAS . $archivo)) {
                @unlink(DIR_SUBIDAS . $archivo);
            }
            pdo()->prepare('DELETE FROM muro_publicaciones WHERE id = ?')->execute([$id]);
            $aviso = 'Publicación eliminada.';
        } else {
            $estado = $accion === 'aprobar' ? 'aprobado' : 'rechazado';
            $st = pdo()->prepare(
                'UPDATE muro_publicaciones
                    SET estado = ?, moderado_en = NOW(), moderado_por = ?
                  WHERE id = ?'
            );
            $st->execute([$estado, 'panel', $id]);
            $aviso = $accion === 'aprobar' ? 'Publicación aprobada y visible en el sitio.' : 'Publicación rechazada.';
        }
    }
}

// ── Datos ────────────────────────────────────────────────────
$filtro = $_GET['estado'] ?? 'pendiente';
if (!in_array($filtro, ['pendiente', 'aprobado', 'rechazado'], true)) {
    $filtro = 'pendiente';
}

$publicaciones = [];
$conteos = ['pendiente' => 0, 'aprobado' => 0, 'rechazado' => 0];

if ($autenticado) {
    foreach (pdo()->query('SELECT estado, COUNT(*) n FROM muro_publicaciones GROUP BY estado') as $r) {
        $conteos[$r['estado']] = (int)$r['n'];
    }
    $st = pdo()->prepare('SELECT * FROM muro_publicaciones WHERE estado = ? ORDER BY creado_en DESC LIMIT 100');
    $st->execute([$filtro]);
    $publicaciones = $st->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Moderación del muro · AVBA</title>
<link rel="icon" href="assets/favicon.png" type="image/png">
<style>
  :root{
    --marino:#003C78;--azul:#0A5C9C;--verde:#009060;--rojo:#E1483C;--ambar:#F59E0B;
    --texto:#12233D;--sub:#5A6880;--bg:#F4F7FB;--sup:#fff;--borde:#DDE5EF;
  }
  *{box-sizing:border-box;}
  body{margin:0;font-family:-apple-system,'Segoe UI',Roboto,Arial,sans-serif;background:var(--bg);color:var(--texto);line-height:1.55;}
  .barra{background:var(--marino);color:#fff;padding:16px 24px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;}
  .barra strong{font-size:16px;}
  .barra a{color:#BFD9FF;font-size:14px;}
  .env{max-width:1080px;margin:0 auto;padding:28px 20px 60px;}
  .aviso{background:#E4F6EE;border:1px solid #A7E0C6;color:#0B5033;padding:12px 16px;border-radius:10px;margin-bottom:20px;font-size:14px;}
  .tabs{display:flex;gap:8px;margin-bottom:22px;flex-wrap:wrap;}
  .tab{padding:9px 16px;border-radius:99px;border:1px solid var(--borde);background:var(--sup);color:var(--sub);text-decoration:none;font-size:14px;font-weight:600;}
  .tab.on{background:var(--marino);border-color:var(--marino);color:#fff;}
  .tarjeta{background:var(--sup);border:1px solid var(--borde);border-radius:14px;padding:18px;margin-bottom:16px;display:grid;grid-template-columns:220px 1fr;gap:18px;}
  .tarjeta img{width:100%;border-radius:10px;display:block;background:#eef2f7;}
  .meta{font-size:12.5px;color:var(--sub);margin-bottom:8px;}
  .nom{font-size:16px;font-weight:700;}
  .com{margin:10px 0 14px;white-space:pre-wrap;overflow-wrap:anywhere;}
  .acc{display:flex;gap:8px;flex-wrap:wrap;}
  button{font-family:inherit;font-size:14px;font-weight:700;padding:10px 16px;border-radius:9px;border:0;cursor:pointer;color:#fff;min-height:40px;}
  .ok{background:var(--verde);} .no{background:var(--ambar);} .del{background:var(--rojo);}
  button:hover{filter:brightness(1.08);}
  .vacio{background:var(--sup);border:1px dashed var(--borde);border-radius:14px;padding:48px 20px;text-align:center;color:var(--sub);}
  .login{max-width:380px;margin:12vh auto;background:var(--sup);border:1px solid var(--borde);border-radius:16px;padding:32px;}
  .login h1{font-size:19px;margin:0 0 6px;}
  .login p{font-size:13.5px;color:var(--sub);margin:0 0 20px;}
  .login input{width:100%;padding:12px 14px;border:1.5px solid var(--borde);border-radius:10px;font-size:15px;font-family:inherit;}
  .login button{width:100%;background:var(--marino);margin-top:14px;}
  .err{background:#FCECEA;border:1px solid #F3B7B0;color:#8C2318;padding:10px 14px;border-radius:9px;font-size:13.5px;margin-bottom:14px;}
  @media (max-width:640px){ .tarjeta{grid-template-columns:1fr;} }
</style>
</head>
<body>

<?php if (!$autenticado): ?>

  <div class="login">
    <h1>Moderación del muro</h1>
    <p>Acceso exclusivo del equipo de AVBA.</p>
    <?php if ($errorLogin): ?><div class="err"><?= e($errorLogin) ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
      <input type="hidden" name="accion" value="entrar">
      <input type="password" name="clave" placeholder="Contraseña" required autofocus aria-label="Contraseña">
      <button type="submit">Entrar</button>
    </form>
  </div>

<?php else: ?>

  <div class="barra">
    <strong>Muro de operadores · Moderación</strong>
    <a href="?salir=1">Cerrar sesión</a>
  </div>

  <div class="env">
    <?php if ($aviso): ?><div class="aviso"><?= e($aviso) ?></div><?php endif; ?>

    <div class="tabs">
      <?php foreach (['pendiente' => 'Pendientes', 'aprobado' => 'Aprobadas', 'rechazado' => 'Rechazadas'] as $k => $etiqueta): ?>
        <a class="tab <?= $filtro === $k ? 'on' : '' ?>" href="?estado=<?= $k ?>"><?= $etiqueta ?> (<?= $conteos[$k] ?>)</a>
      <?php endforeach; ?>
    </div>

    <?php if (!$publicaciones): ?>
      <div class="vacio">No hay publicaciones <?= e($filtro) ?>s.</div>
    <?php endif; ?>

    <?php foreach ($publicaciones as $p): ?>
      <div class="tarjeta">
        <div>
          <?php if ($p['archivo'] && is_file(DIR_SUBIDAS . $p['archivo'])): ?>
            <img src="uploads/muro/<?= e($p['archivo']) ?>" alt="Fotografía enviada por <?= e($p['nombre']) ?>" loading="lazy">
          <?php else: ?>
            <div class="vacio" style="padding:28px 12px;font-size:13px;">Sin fotografía</div>
          <?php endif; ?>
        </div>
        <div>
          <div class="meta">#<?= (int)$p['id'] ?> · <?= e($p['creado_en']) ?><?= $p['consentimiento'] ? ' · autorización otorgada' : '' ?></div>
          <div class="nom"><?= e($p['nombre']) ?></div>
          <div class="meta">
            <?= e($p['puesto'] ?: 'Sin puesto') ?><?= $p['empresa'] ? ' · ' . e($p['empresa']) : '' ?>
          </div>
          <div class="com"><?= e($p['comentario']) ?></div>
          <form method="post" class="acc">
            <input type="hidden" name="csrf" value="<?= e(token()) ?>">
            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <?php if ($p['estado'] !== 'aprobado'): ?>
              <button class="ok"  name="accion" value="aprobar">Aprobar</button>
            <?php endif; ?>
            <?php if ($p['estado'] !== 'rechazado'): ?>
              <button class="no"  name="accion" value="rechazar">Rechazar</button>
            <?php endif; ?>
            <button class="del" name="accion" value="eliminar"
                    onclick="return confirm('¿Eliminar esta publicación y su fotografía? No se puede deshacer.')">Eliminar</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

<?php endif; ?>

</body>
</html>
