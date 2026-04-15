<?php
/**
 * Cargador de FPDF + FPDI sin necesidad de Composer.
 * Incluye este archivo antes de instanciar \setasign\Fpdi\Fpdi.
 */

// 1. FPDF base class
if (!class_exists('FPDF', false)) {
    // FPDF_FONTPATH debe apuntar a los archivos de fuente incluidos en lib/fpdf/font/
    if (!defined('FPDF_FONTPATH')) {
        define('FPDF_FONTPATH', __DIR__ . '/fpdf/font/');
    }
    require_once __DIR__ . '/fpdf/fpdf.php';
}

// 2. FPDI autoloader
require_once __DIR__ . '/fpdi/autoload.php';
