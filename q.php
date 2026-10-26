<?php
/**
 * Dirección corta de las etiquetas QR.
 *
 * Las etiquetas impresas llevan https://gestion.avba.com.mx/extintores/q.php?qr=CÓDIGO
 * en lugar de la dirección completa de la página de validación. Es lo mismo
 * —esto redirige ahí, con el mismo código— pero 23 letras más corto, y en un
 * QR cada letra son cuadritos: la dirección completa daba un QR de 41×41 y
 * ésta uno de 33×33, con cada cuadrito un 24 % más grande. Se lee más fácil,
 * sobre todo en iPhone, donde el lector del navegador trabaja con poca
 * resolución.
 *
 * No depende de reglas de reescritura del servidor: es un archivo que existe,
 * así que funciona aunque el .htaccess cambie. Tampoco toca la base de datos
 * ni la sesión: sólo valida el código y redirige.
 *
 * Las etiquetas que ya están pegadas llevan la dirección larga, que sigue
 * funcionando igual.
 */
// El código tiene que venir limpio. Quitarle lo que no sea dígito y quedarse
// con el resto convertiría «12abc345678» en otro código distinto.
$codigo  = trim((string) ($_GET['qr'] ?? ''));
$destino = 'public/validar-extintor.html';
if (preg_match('/^\d{8,20}$/', $codigo)) {
    $destino .= '?qr=' . $codigo;
}

// 302 y no 301: si algún día la página de validación cambia de lugar, basta
// con cambiar esta línea y las etiquetas ya impresas siguen sirviendo.
header('Location: ' . $destino, true, 302);
header('Cache-Control: no-store');
