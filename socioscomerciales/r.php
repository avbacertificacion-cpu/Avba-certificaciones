<?php
/**
 * Socios Comerciales AVBA — Respuesta desde el correo
 *
 * Aquí aterriza quien pulsa un botón de un correo nuestro. El enlace va
 * firmado, así que se sabe quién es sin pedirle la contraseña: contestar
 * tiene que costar un clic o no lo hace nadie.
 *
 * Además de guardar la respuesta, la página aprovecha para seguir la
 * conversación: enseña la siguiente pregunta que esa persona no ha
 * contestado. El correo abre el tema; la página lo continúa.
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/api/helpers.php';
require_once __DIR__ . '/api/Interaccion.php';

ini_set('display_errors', '0');
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

$titulo   = 'No pudimos registrar tu respuesta';
$mensaje  = 'El enlace no es válido. Si te llegó en un correo nuestro, escríbenos y lo revisamos.';
$simbolo  = 'error';
$detalle  = '';
$extras   = '';
$usuario  = null;

try {
    $pdo   = scDB();
    $inter = new ScInteraccion($pdo);
    $datos = scVerificarEnlace($pdo, $_GET['t'] ?? null);

    if ($datos === null) {
        // Nada que hacer: se queda el mensaje de arriba.
    } elseif (!empty($datos['caducado'])) {
        $titulo  = 'Este enlace ya caducó';
        $mensaje = 'Los enlaces de nuestros correos valen ' . SC_FIRMA_TTL_DIAS . ' días. '
                 . 'Entra al portal y lo haces desde tu perfil, o espera al siguiente correo.';
        $simbolo = 'aviso';
        $extras  = botonPortal();

    } elseif ($datos['tipo'] === 'franja') {
        // ── Apartar una entrevista ──────────────────────────────
        $r = $inter->reservarFranja($datos['usuario_id'], (int) $datos['valor']);

        if (($r['status'] ?? '') === 'success') {
            $f       = $r['franja'];
            $simbolo = 'ok';
            $titulo  = !empty($r['repetida']) ? 'Ya tenías este horario apartado' : '¡Listo, quedó apartado!';
            $mensaje = 'Te esperamos el ' . scFechaLargaEs($f['inicio']) . '.';
            $detalle = etiquetaModo($f['modo']) . ' · ' . (int) $f['minutos'] . ' minutos'
                     . (!empty($f['nota']) ? ' · ' . htmlspecialchars($f['nota']) : '');
            $extras  = botonPortal();
        } else {
            $simbolo = 'aviso';
            $titulo  = 'Ese horario ya no está libre';
            $mensaje = $r['message'] ?? 'Elige otro de la lista.';
            if (!empty($r['libres'])) {
                $extras = bloqueFranjas($pdo, $datos['usuario_id'], $r['libres']);
            }
        }

    } else {
        // ── Una respuesta normal ────────────────────────────────
        $r = $inter->registrar($datos['usuario_id'], $datos['tipo'], $datos['valor']);

        if (($r['status'] ?? '') === 'success') {
            $simbolo = 'ok';
            $usuario = $r['usuario'];
            $titulo  = 'Gracias, quedó registrado';
            $mensaje = $r['titulo'];
            $detalle = 'Tu respuesta: <strong>' . htmlspecialchars($r['etiqueta']) . '</strong>';

            if ($datos['tipo'] === 'interes' && $datos['valor'] === 'no') {
                $mensaje = 'Tomamos nota. Dejaremos de escribirte sobre vacantes.';
                $detalle = 'Tu cuenta sigue activa: cuando quieras retomar, entra al portal y actualiza tu perfil.';
                $extras  = botonPortal();
            } else {
                $extras = seguirConversacion($pdo, $inter, (int) $datos['usuario_id']);
            }
        } else {
            $simbolo = 'aviso';
            $titulo  = 'No pudimos guardar tu respuesta';
            $mensaje = $r['message'] ?? 'Inténtalo de nuevo en un momento.';
        }
    }

} catch (Throwable $e) {
    error_log('r.php: ' . $e->getMessage());
    $titulo  = 'Algo falló de nuestro lado';
    $mensaje = 'Inténtalo en unos minutos. Si sigue igual, escríbenos.';
    $simbolo = 'error';
}


// ══════════════════════════════════════════════════════════════
//  Piezas de la página
// ══════════════════════════════════════════════════════════════

/**
 * La siguiente pregunta sin contestar, más el avance del perfil.
 *
 * Esto es lo que convierte un clic suelto en una conversación: quien acaba
 * de decir que sigue interesado ya está aquí y con ganas, así que es el
 * mejor momento para preguntarle cuándo puede empezar.
 */
function seguirConversacion(PDO $pdo, ScInteraccion $inter, int $usuarioId): string {
    $ya    = $inter->respuestasDe($usuarioId);
    $html  = '';

    // Solo se pregunta de una en una. Una página con cuatro preguntas es un
    // formulario, y un formulario es justo lo que se quería evitar.
    foreach (['interes', 'disponibilidad', 'sueldo', 'movilidad', 'certificacion'] as $tipo) {
        if (isset($ya[$tipo])) continue;

        $pregunta = ScInteraccion::PREGUNTAS[$tipo];
        $botones  = '';
        foreach ($pregunta['opciones'] as $valor => $etiqueta) {
            $botones .= '<a class="opcion" href="' . htmlspecialchars(scUrlRespuesta($pdo, $usuarioId, $tipo, $valor), ENT_QUOTES) . '">'
                      . htmlspecialchars($etiqueta) . '</a>';
        }

        $html .= '<div class="siguiente">'
               . '<p class="rotulo">Ya que estás aquí</p>'
               . '<h2>' . htmlspecialchars($pregunta['titulo']) . '</h2>'
               . '<div class="opciones' . (!empty($pregunta['multiple']) ? ' chips' : '') . '">' . $botones . '</div>'
               . (!empty($pregunta['multiple']) ? '<p class="pista">Puedes marcar varias, una por una.</p>' : '')
               . '</div>';
        break;
    }

    // Franjas de entrevista libres, si el administrador abrió alguna
    $franja = $inter->franjaDe($usuarioId);
    if ($franja) {
        $html .= '<div class="siguiente"><p class="rotulo">Tu entrevista</p>'
               . '<h2>' . scFechaLargaEs($franja['inicio']) . '</h2>'
               . '<p class="pista">' . etiquetaModo($franja['modo']) . ' · ' . (int) $franja['minutos'] . ' minutos</p></div>';
    } else {
        $libres = $inter->franjasLibres(4);
        if ($libres) $html .= bloqueFranjas($pdo, $usuarioId, $libres);
    }

    // Avance del perfil
    $avance = $inter->avancePerfil($usuarioId);
    if (!$avance['completo']) {
        $lista = '';
        foreach (array_slice($avance['faltantes'], 0, 3) as $falta) {
            $lista .= '<li>' . htmlspecialchars($falta['texto']) . '</li>';
        }
        $html .= '<div class="siguiente">'
               . '<p class="rotulo">Tu perfil</p>'
               . '<div class="barra"><span style="width:' . (int) $avance['porcentaje'] . '%"></span></div>'
               . '<p class="pct">' . (int) $avance['porcentaje'] . ' % completo</p>'
               . '<ul class="faltan">' . $lista . '</ul>'
               . '<a class="btn" href="' . htmlspecialchars(perfilUrl($usuarioId), ENT_QUOTES) . '">Completar mi perfil</a>'
               . '</div>';
    } else {
        $html .= '<div class="siguiente"><p class="rotulo">Tu perfil</p>'
               . '<h2>Está completo</h2>'
               . '<p class="pista">No te falta nada. Gracias por mantenerlo al día.</p>'
               . botonPortal() . '</div>';
    }

    return $html;
}

function bloqueFranjas(PDO $pdo, int $usuarioId, array $libres): string {
    $botones = '';
    foreach ($libres as $f) {
        $botones .= '<a class="opcion" href="'
                  . htmlspecialchars(scUrlRespuesta($pdo, $usuarioId, 'franja', (string) $f['id']), ENT_QUOTES) . '">'
                  . htmlspecialchars(scFechaCortaEs($f['inicio'])) . '</a>';
    }
    return '<div class="siguiente">'
         . '<p class="rotulo">Entrevista</p>'
         . '<h2>Escoge cuándo te llamamos</h2>'
         . '<div class="opciones">' . $botones . '</div>'
         . '<p class="pista">Se aparta al instante. Puedes cambiarlo eligiendo otro horario.</p>'
         . '</div>';
}

function perfilUrl(int $usuarioId): string {
    return scUrlBase() . '/inicio.html';
}

function botonPortal(): string {
    return '<a class="btn" href="' . htmlspecialchars(scUrlBase() . '/inicio.html', ENT_QUOTES) . '">Entrar al portal</a>';
}

function etiquetaModo(string $modo): string {
    return ['llamada' => 'Llamada telefónica',
            'videollamada' => 'Videollamada',
            'presencial' => 'En nuestras oficinas'][$modo] ?? $modo;
}

$iconos = [
    'ok'     => '<path d="M20 6L9 17l-5-5"/>',
    'aviso'  => '<path d="M12 9v4m0 4h.01M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L14.7 3.9a2 2 0 00-3.4 0z"/>',
    'error'  => '<circle cx="12" cy="12" r="10"/><path d="M15 9l-6 6M9 9l6 6"/>',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($titulo) ?> — Socios Comerciales AVBA</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" type="image/png" href="assets/favicon.png">
<link rel="stylesheet" href="assets/avba.css">
<style>
  body {
    background: linear-gradient(160deg, var(--hero-1) 0%, var(--hero-2) 100%);
    min-height: 100vh; display: flex; align-items: center; justify-content: center;
    padding: 32px 20px;
  }
  .caja { width: 100%; max-width: 470px; }
  .tarjeta {
    background: var(--blanco); border-radius: var(--r-lg);
    box-shadow: var(--sombra-lg); padding: 34px 28px 28px; text-align: center;
  }
  .marca { padding-bottom: 18px; margin-bottom: 22px; border-bottom: 1px solid var(--borde); }
  .marca img { height: 40px; width: auto; display: block; margin: 0 auto 8px; }
  .marca p { font-size: 11.5px; font-weight: 700; color: var(--azul); letter-spacing: .6px; text-transform: uppercase; }

  .simbolo {
    width: 62px; height: 62px; border-radius: 50%; margin: 0 auto 18px;
    display: flex; align-items: center; justify-content: center;
  }
  .simbolo svg { width: 30px; height: 30px; fill: none; stroke: currentColor; stroke-width: 2.2; stroke-linecap: round; stroke-linejoin: round; }
  .simbolo.ok    { background: var(--verde-bg); color: var(--verde); }
  .simbolo.aviso { background: var(--ambar-bg); color: var(--ambar); }
  .simbolo.error { background: var(--rojo-bg);  color: var(--rojo); }

  h1 { font-size: 20px; font-weight: 800; margin-bottom: 8px; }
  .lead { font-size: 14.5px; color: var(--texto-sub); }
  .detalle { font-size: 14px; color: var(--texto); margin-top: 12px; background: var(--azul-claro);
             border-radius: var(--r-sm); padding: 11px 14px; }

  .siguiente { margin-top: 24px; padding-top: 22px; border-top: 1px solid var(--borde); text-align: left; }
  .rotulo { font-size: 11px; font-weight: 700; letter-spacing: .8px; text-transform: uppercase;
            color: var(--texto-tenue); margin-bottom: 7px; }
  .siguiente h2 { font-size: 16.5px; font-weight: 700; margin-bottom: 14px; }
  .pista { font-size: 12.5px; color: var(--texto-tenue); margin-top: 10px; }

  .opciones { display: flex; flex-direction: column; gap: 9px; }
  .opciones.chips { flex-direction: row; flex-wrap: wrap; }
  .opcion {
    display: block; padding: 12px 16px; border: 1.5px solid var(--borde);
    border-radius: var(--r-sm); font-size: 14.5px; font-weight: 600; color: var(--texto);
    background: var(--blanco); transition: border-color .15s, background .15s, color .15s;
  }
  .opciones.chips .opcion { padding: 8px 13px; font-size: 13.5px; border-radius: 20px; }
  .opcion:hover { border-color: var(--azul); background: var(--azul-claro); color: var(--azul-oscuro); }

  .barra { height: 9px; background: var(--gris-fondo); border-radius: 6px; overflow: hidden; }
  .barra span { display: block; height: 100%; background: var(--azul); border-radius: 6px; }
  .pct { font-size: 12.5px; font-weight: 700; color: var(--azul); margin-top: 7px; }
  .faltan { margin: 10px 0 16px 18px; font-size: 13.5px; color: var(--texto-sub); }
  .faltan li { margin-bottom: 4px; }

  .btn {
    display: inline-block; background: var(--azul); color: #fff; font-weight: 700;
    font-size: 14.5px; padding: 12px 22px; border-radius: var(--r-sm); margin-top: 4px;
  }
  .btn:hover { background: var(--azul-oscuro); }
  .tarjeta > .btn { margin-top: 20px; }

  .legal { margin-top: 16px; font-size: 11.5px; color: rgba(255,255,255,.5); text-align: center; }
  .legal a { color: rgba(255,255,255,.75); font-weight: 600; }
  .legal a:hover { text-decoration: underline; }
</style>
</head>
<body>

<div class="caja">
  <div class="tarjeta">
    <div class="marca">
      <img src="assets/avba-logo.png" alt="AVBA">
      <p>Socios Comerciales</p>
    </div>

    <div class="simbolo <?= htmlspecialchars($simbolo) ?>">
      <svg viewBox="0 0 24 24"><?= $iconos[$simbolo] ?? $iconos['error'] ?></svg>
    </div>

    <h1><?= htmlspecialchars($titulo) ?></h1>
    <p class="lead"><?= htmlspecialchars($mensaje) ?></p>
    <?php if ($detalle !== ''): ?><p class="detalle"><?= $detalle ?></p><?php endif; ?>

    <?= $extras ?>
  </div>

  <p class="legal">
    <a href="terminos.html">Términos y Condiciones</a> ·
    <a href="aviso-privacidad.html">Aviso de Privacidad</a><br>
    AVBA Inspections, Certifications and Maintenance S.A.S. de C.V.
  </p>
</div>

</body>
</html>
