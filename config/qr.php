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
 * El tope de 20 es el de la columna `extintores.codigo_qr`.
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
