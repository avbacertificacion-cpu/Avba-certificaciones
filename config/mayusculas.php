<?php
/**
 * Normalización a MAYÚSCULAS de lo que se guarda en la base.
 *
 * Los documentos que emite la empresa (reportes, presupuestos, etiquetas) se
 * capturan en mayúsculas, así que el texto se convierte al guardarlo y no al
 * imprimirlo: así la base queda pareja y cualquier pantalla nueva lo hereda
 * sin acordarse de convertir nada.
 *
 * No se convierte TODO, porque hay campos donde hacerlo rompería el sistema:
 *
 *  - `password` es un hash bcrypt. En mayúsculas deja de validar y nadie
 *    puede volver a entrar.
 *  - `rol`, `estado`, `modulo` y `utilidad_base` son banderas que el código
 *    compara en minúsculas por todos lados (`WHERE estado = 'activo'`,
 *    `$_SESSION['rol'] !== ROLE_ADMIN`).
 *  - `archivo`, `nombre_original` y `mime` son nombres de archivo en disco.
 *    El servidor es Linux y sí distingue mayúsculas: convertirlos deja las
 *    fotos y los documentos subidos apuntando a la nada.
 *  - `username`, `email` y `codigo_qr` identifican: se buscan tal cual se
 *    dieron de alta.
 *
 * Los acentos se conservan (mb_strtoupper con UTF-8): en español las
 * mayúsculas se acentúan, así que "Área" queda "ÁREA" y no "AREA".
 */

/**
 * Campos que nunca se convierten. Se comparan por nombre, sin importar la
 * tabla: en este sistema un campo se llama igual en todas partes.
 */
function camposSinMayusculas(): array {
    return [
        // Credenciales e identificadores de acceso
        'password', 'password_actual', 'password_nueva', 'contrasena', 'username',
        'email', 'correo',
        // Banderas internas que el código compara en minúsculas
        'rol', 'estado', 'modulo', 'utilidad_base', 'tipo',
        // Archivos en disco (Linux distingue mayúsculas de minúsculas)
        'archivo', 'nombre_original', 'mime',
        // Identificadores que se buscan tal cual
        'codigo_qr', 'ip', 'token',
        // Claves de catálogo del SAT: ya vienen en su forma oficial y validada
        'uso_cfdi', 'metodo_pago', 'forma_pago', 'moneda',
        'clave_prodserv', 'clave_unidad', 'cliente_regimen', 'regimen_fiscal',
        // Casillas del checklist de inspección: son 'OK' / 'NC'
        'ser', 'mg', 'po', 'ph', 'sg', 'ps', 'ob', 'dan', 'pin', 'fn', 'gb', 'rv',
    ];
}

/** Un texto a mayúsculas conservando los acentos. */
function aMayusculas(?string $texto): ?string {
    if ($texto === null) return null;
    return mb_strtoupper($texto, 'UTF-8');
}

/**
 * Convierte a mayúsculas los textos de un arreglo de entrada, respetando las
 * excepciones. Entra recursivamente en los arreglos anidados (las partidas de
 * una cotización, por ejemplo) y deja intactos números, nulos y booleanos.
 */
function entradaEnMayusculas($datos) {
    if (!is_array($datos)) return $datos;

    $excepciones = camposSinMayusculas();
    foreach ($datos as $clave => $valor) {
        if (is_array($valor)) {
            $datos[$clave] = entradaEnMayusculas($valor);
        } elseif (is_string($valor) && !in_array((string) $clave, $excepciones, true)) {
            $datos[$clave] = mb_strtoupper($valor, 'UTF-8');
        }
    }
    return $datos;
}
