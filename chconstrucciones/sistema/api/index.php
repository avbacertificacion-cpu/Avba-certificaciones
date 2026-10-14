<?php
/*
 * Punto de entrada de la API.  Todas las llamadas son  api/?r=modulo/accion
 *   - GET  para consultar (y para descargar archivos).
 *   - POST con cuerpo JSON para cambiar datos (requiere el token CSRF).
 */
declare(strict_types=1);

require __DIR__ . '/../src/nucleo.php';
require __DIR__ . '/../src/permisos.php';
require __DIR__ . '/../src/almacen.php';
require __DIR__ . '/../src/correo.php';
require __DIR__ . '/../src/rutas.php';
require __DIR__ . '/../src/rutas_archivo.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

try {
    if (!instalado()) {
        responder(['ok' => false, 'instalado' => false, 'error' => 'El sistema todavía no está instalado. Abre instalar.php.'], 503);
    }
    arranque();
    iniciarSesion();

    $ruta = preg_replace('#[^a-z0-9/_-]#', '', strtolower((string) ($_GET['r'] ?? '')));
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $rutas = rutasApi();
    if (!isset($rutas[$ruta])) fallar('Ruta no encontrada.', 404);
    [$metodoEsperado, $funcion, $publica] = $rutas[$ruta] + [2 => false];

    if ($metodo !== $metodoEsperado) fallar('Método no permitido.', 405);
    if ($metodo === 'POST' || $metodo === 'PUT') {
        // Protección CSRF: la página manda el token que recibió al iniciar sesión.
        $token = (string) ($_SERVER['HTTP_X_CSRF'] ?? '');
        if (!$publica && !hash_equals((string) $_SESSION['csrf'], $token)) fallar('La sesión no es válida. Recarga la página.', 419);
    }
    if (!$publica) requerirUsuario();

    $entrada = [];
    if ($metodo === 'POST') {
        $crudo = file_get_contents('php://input') ?: '';
        $entrada = $crudo === '' ? [] : json_decode($crudo, true, 32);
        if (!is_array($entrada)) fallar('Datos no válidos.');
    }
    // Los cambios se hacen dentro de una transacción: si algo falla a la mitad,
    // la base de datos vuelve a como estaba. (El inicio de sesión queda fuera
    // para que los intentos fallidos sí se guarden.)
    if ($metodo === 'POST' && !$publica) db()->beginTransaction();
    $resultado = $funcion($entrada);
    if (db()->inTransaction()) db()->commit();
    responder(['ok' => true] + ($resultado ?? []));
} catch (ErrorApi $e) {
    if (instalado() && db()->inTransaction()) db()->rollBack();
    responder(['ok' => false, 'error' => $e->getMessage()], $e->estado);
} catch (Throwable $e) {
    if (instalado() && db()->inTransaction()) db()->rollBack();
    error_log('[CHC] ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    $mensaje = conf('app.debug') ? $e->getMessage() : 'Ocurrió un error en el servidor. Intenta de nuevo.';
    responder(['ok' => false, 'error' => $mensaje], 500);
}
