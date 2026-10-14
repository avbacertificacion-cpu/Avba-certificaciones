<?php
/*
 * Asigna una contraseña nueva a un usuario desde la terminal (por SSH).
 * Sirve cuando el único administrador olvidó la suya.
 *
 *     php cron/cambiar_clave.php correo@dominio.com NuevaContraseña
 *
 * Sólo funciona desde la línea de comandos: desde el navegador no hace nada.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/nucleo.php';

if (!instalado()) {
    fwrite(STDERR, "El sistema no está instalado todavía.\n");
    exit(1);
}
arranque();

[, $email, $clave] = $argv + [null, '', ''];
$email = strtolower(trim((string) $email));
if ($email === '' || mb_strlen((string) $clave) < 8) {
    fwrite(STDERR, "Uso: php cron/cambiar_clave.php correo@dominio.com NuevaContraseña (mínimo 8 caracteres)\n");
    exit(1);
}
$u = fila('SELECT id, nombre FROM usuarios WHERE email = ?', [$email]);
if (!$u) {
    fwrite(STDERR, "No hay ningún usuario con el correo $email.\n");
    exit(1);
}
q('UPDATE usuarios SET password_hash = ?, activo = 1 WHERE id = ?', [password_hash((string) $clave, PASSWORD_DEFAULT), $u['id']]);
q('DELETE FROM intentos_login WHERE email = ?', [$email]);
q("INSERT INTO actividad (usuario_id, accion, detalle) VALUES (?, 'recibió una contraseña nueva desde el servidor', '')", [$u['id']]);
echo "Listo: {$u['nombre']} ya puede entrar con la contraseña nueva.\n";
