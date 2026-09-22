<?php
/**
 * Formato de los códigos QR de las etiquetas.
 *
 * Las etiquetas impresas no siempre traen el mismo número de dígitos: las
 * generadas por el sistema salen de un consecutivo de 11, pero las que vienen
 * impresas de fábrica pueden ser más largas (13 dígitos, por ejemplo). El
 * sistema acepta cualquiera dentro de este rango mientras sea numérico y no
 * esté repetido; el largo lo pone la etiqueta, no el programa.
 *
 * El tope de 20 lo fija esta constante, y las columnas que guardan un código se
 * ensanchan solas hasta ahí la primera vez que hace falta: las bases creadas
 * antes de esto tienen `codigo_qr` de 11 caracteres, y guardar uno más largo
 * fallaba con la base en modo estricto.
 */

const QR_MIN_DIGITOS     = 8;
const QR_MAX_DIGITOS     = 20;
/** Largo con el que se generan los consecutivos cuando no se indica otro. */
const QR_LARGO_POR_DEFECTO = 11;

/** ¿Es un código de etiqueta válido? */
function qrValido($codigo): bool {
    return (bool) preg_match('/^\d{' . QR_MIN_DIGITOS . ',' . QR_MAX_DIGITOS . '}$/', (string) $codigo);
}

/** Mensaje único, para que todas las pantallas digan lo mismo. */
function qrMensajeFormato(): string {
    return 'El código QR debe ser numérico, de ' . QR_MIN_DIGITOS . ' a ' . QR_MAX_DIGITOS . ' dígitos';
}

/**
 * Expresión regular equivalente, para las pantallas. Se inyecta en el HTML en
 * vez de escribirla a mano en cada una: si el rango cambia, cambia en todas.
 */
function qrPatron(): string {
    return '[0-9]{' . QR_MIN_DIGITOS . ',' . QR_MAX_DIGITOS . '}';
}

/**
 * Ensancha, si hiciera falta, las columnas que guardan un código de etiqueta.
 *
 * Se llama justo antes de escribir un QR, no en cada petición: es una consulta
 * al diccionario de la base y sólo hace el ALTER la primera vez. En una base ya
 * al día no cambia nada.
 */
function qrAsegurarColumnas(PDO $pdo): void {
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;

    $columnas = [
        ['extintores',      'codigo_qr'],
        ['qr_asignaciones', 'qr_anterior'],
        ['qr_asignaciones', 'qr_nuevo'],
    ];
    foreach ($columnas as [$tabla, $columna]) {
        try {
            $st = $pdo->prepare("
                SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
            ");
            $st->execute([$tabla, $columna]);
            $largo = $st->fetchColumn();
            if ($largo === false || $largo === null) continue;          // no existe esa tabla o columna
            if ((int) $largo >= QR_MAX_DIGITOS) continue;               // ya cabe
            $pdo->exec("ALTER TABLE `$tabla` MODIFY `$columna` VARCHAR(" . QR_MAX_DIGITOS . ") DEFAULT NULL");
        } catch (Exception $e) {
            error_log("No se pudo ensanchar $tabla.$columna: " . $e->getMessage());
        }
    }
}
