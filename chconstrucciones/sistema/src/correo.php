<?php
/*
 * Avisos por correo. Usa la función mail() del hosting (en Hostinger funciona
 * con un correo del propio dominio como remitente). Si está desactivado en la
 * configuración, no hace nada.
 */
declare(strict_types=1);

function enviarCorreo(string $para, string $asunto, string $texto): bool
{
    if (!conf('correo.activo') || !filter_var($para, FILTER_VALIDATE_EMAIL)) return false;
    $remitente = (string) conf('correo.remitente', '');
    $nombre = (string) conf('app.nombre', 'Gestión documental');
    $url = (string) conf('correo.url_sistema', '');
    $cuerpo = $texto . ($url ? "\n\nEntrar al sistema: $url" : '') . "\n\n— $nombre\n(Este es un aviso automático; no respondas a este correo.)";
    $headers = [
        'From: ' . mb_encode_mimeheader($nombre) . " <$remitente>",
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    return @mail($para, mb_encode_mimeheader($asunto), $cuerpo, implode("\r\n", $headers), $remitente ? "-f$remitente" : '');
}

/** Correos de los usuarios activos que tienen cierta capacidad. */
function correosConCapacidad(string $cap): array
{
    $roles = array_keys(array_filter(ROLES, fn($r) => in_array($cap, $r['caps'], true)));
    if (!$roles) return [];
    $marcas = implode(',', array_fill(0, count($roles), '?'));
    return array_column(filas("SELECT email FROM usuarios WHERE activo = 1 AND rol IN ($marcas)", $roles), 'email');
}
