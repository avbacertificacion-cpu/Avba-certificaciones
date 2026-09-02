<?php
/**
 * Datos fiscales del emisor y catálogos del SAT que aparecen en el presupuesto.
 *
 * El emisor es uno solo y no cambia, así que vive aquí y no en la base: para
 * corregir un teléfono o un correo se edita este archivo y queda corregido en
 * todos los presupuestos, sin migraciones ni pantallas de por medio.
 *
 * Los catálogos son el subconjunto que de verdad se usa al cotizar servicios;
 * no pretenden ser el catálogo completo del SAT.
 */

/** Datos del emisor tal como se imprimen en el encabezado del presupuesto. */
function emisorDatos(): array {
    return [
        'nombre'   => 'AVBA INSPECTIONS, CERTIFICATIONS AND MAINTENANCE',
        'rfc'      => 'AIC250329EM6',
        'regimen'  => '626',  // Régimen Simplificado de Confianza
        'cp'       => '89603',
        'telefono' => '8991373127',
        'correo'   => 'avba.certificaciones@gmail.com',
        // Leyenda al pie del presupuesto
        'leyenda'  => 'PRESUPUESTO SUJETO A CAMBIO DE PRECIOS SIN PREVIO AVISO',
    ];
}

/** Régimen fiscal: clave => descripción. */
function catRegimenFiscal(): array {
    return [
        '601' => 'General de Ley Personas Morales',
        '603' => 'Personas Morales con Fines no Lucrativos',
        '605' => 'Sueldos y Salarios e Ingresos Asimilados a Salarios',
        '606' => 'Arrendamiento',
        '607' => 'Régimen de Enajenación o Adquisición de Bienes',
        '608' => 'Demás ingresos',
        '610' => 'Residentes en el Extranjero sin Establecimiento Permanente en México',
        '611' => 'Ingresos por Dividendos (socios y accionistas)',
        '612' => 'Personas Físicas con Actividades Empresariales y Profesionales',
        '614' => 'Ingresos por intereses',
        '616' => 'Sin obligaciones fiscales',
        '620' => 'Sociedades Cooperativas de Producción que optan por diferir sus ingresos',
        '621' => 'Incorporación Fiscal',
        '622' => 'Actividades Agrícolas, Ganaderas, Silvícolas y Pesqueras',
        '623' => 'Opcional para Grupos de Sociedades',
        '624' => 'Coordinados',
        '625' => 'Actividades Empresariales con ingresos a través de Plataformas Tecnológicas',
        '626' => 'Régimen Simplificado de Confianza',
    ];
}

/** Uso del CFDI: clave => descripción. */
function catUsoCfdi(): array {
    return [
        'G01' => 'Adquisición de mercancias',
        'G02' => 'Devoluciones, descuentos o bonificaciones',
        'G03' => 'Gastos en general',
        'I01' => 'Construcciones',
        'I02' => 'Mobiliario y equipo de oficina por inversiones',
        'I03' => 'Equipo de transporte',
        'I04' => 'Equipo de cómputo y accesorios',
        'I05' => 'Dados, troqueles, moldes, matrices y herramental',
        'I06' => 'Comunicaciones telefónicas',
        'I07' => 'Comunicaciones satelitales',
        'I08' => 'Otra maquinaria y equipo',
        'S01' => 'Sin efectos fiscales',
        'CP01' => 'Pagos',
    ];
}

/** Forma de pago: clave => descripción. */
function catFormaPago(): array {
    return [
        '01' => 'Efectivo',
        '02' => 'Cheque nominativo',
        '03' => 'Transferencia electrónica de fondos',
        '04' => 'Tarjeta de crédito',
        '05' => 'Monedero electrónico',
        '08' => 'Vales de despensa',
        '17' => 'Compensación',
        '28' => 'Tarjeta de débito',
        '30' => 'Aplicación de anticipos',
        '99' => 'Por definir',
    ];
}

/** Método de pago: clave => descripción. */
function catMetodoPago(): array {
    return [
        'PUE' => 'Pago en una sola exhibición',
        'PPD' => 'Pago en parcialidades o diferido',
    ];
}

/** Clave de unidad del SAT: clave => descripción. */
function catClaveUnidad(): array {
    return [
        'E48' => 'Unidad de servicio',
        'E51' => 'Trabajo',
        'H87' => 'Pieza',
        'ACT' => 'Actividad',
        'EA'  => 'Elemento',
        'XBX' => 'Caja',
        'KGM' => 'Kilogramo',
        'LTR' => 'Litro',
        'MTR' => 'Metro',
        'HUR' => 'Hora',
        'DAY' => 'Día',
        'MON' => 'Mes',
    ];
}

/** "601" => "601 - General de Ley Personas Morales" (o la clave sola si no está en el catálogo). */
function catEtiqueta(array $catalogo, ?string $clave, string $separador = ' - '): string {
    $clave = trim((string) $clave);
    if ($clave === '') return '';
    return isset($catalogo[$clave]) ? $clave . $separador . $catalogo[$clave] : $clave;
}

// ─── Importe con letra ───────────────────────────────────────────────────────

/** 0–999 con letra. */
function seccionALetras(int $n): string {
    $unidades = ['', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve', 'diez',
                 'once', 'doce', 'trece', 'catorce', 'quince', 'dieciséis', 'diecisiete', 'dieciocho', 'diecinueve',
                 'veinte', 'veintiuno', 'veintidós', 'veintitrés', 'veinticuatro', 'veinticinco',
                 'veintiséis', 'veintisiete', 'veintiocho', 'veintinueve'];
    $decenas  = [3 => 'treinta', 4 => 'cuarenta', 5 => 'cincuenta', 6 => 'sesenta',
                 7 => 'setenta', 8 => 'ochenta', 9 => 'noventa'];
    $centenas = [1 => 'ciento', 2 => 'doscientos', 3 => 'trescientos', 4 => 'cuatrocientos', 5 => 'quinientos',
                 6 => 'seiscientos', 7 => 'setecientos', 8 => 'ochocientos', 9 => 'novecientos'];

    if ($n === 100) return 'cien';   // "cien", no "ciento", cuando va solo

    $out = '';
    $c = intdiv($n, 100);
    $r = $n % 100;
    if ($c) $out .= $centenas[$c];
    if ($r) {
        if ($out !== '') $out .= ' ';
        if ($r < 30) {
            $out .= $unidades[$r];
        } else {
            $u = $r % 10;
            $out .= $decenas[intdiv($r, 10)] . ($u ? ' y ' . $unidades[$u] : '');
        }
    }
    return $out;
}

/**
 * "uno" pierde la o delante de un sustantivo masculino: veintiún mil,
 * treinta y un pesos. Se aplica antes de "mil", "millones" y de la moneda.
 */
function apocoparUno(string $s): string {
    if (substr($s, -9) === 'veintiuno') return substr($s, 0, -9) . 'veintiún';
    if (substr($s, -3) === 'uno')       return substr($s, 0, -3) . 'un';
    return $s;
}

/** 0–999 999 con letra. */
function hasta999999ALetras(int $n): string {
    $miles = intdiv($n, 1000);
    $resto = $n % 1000;
    $out = '';
    if ($miles === 1)      $out = 'mil';
    elseif ($miles > 1)    $out = apocoparUno(seccionALetras($miles)) . ' mil';
    if ($resto)            $out .= ($out !== '' ? ' ' : '') . seccionALetras($resto);
    return $out;
}

/** Entero con letra, hasta cientos de miles de millones. */
function enteroALetras(int $n): string {
    if ($n < 0) return 'menos ' . enteroALetras(-$n);
    if ($n === 0) return 'cero';

    $millones = intdiv($n, 1000000);
    $resto    = $n % 1000000;

    $out = '';
    if ($millones === 1)   $out = 'un millón';
    elseif ($millones > 1) $out = apocoparUno(hasta999999ALetras($millones)) . ' millones';
    if ($resto)            $out .= ($out !== '' ? ' ' : '') . hasta999999ALetras($resto);
    return $out;
}

/**
 * Importe con letra al estilo de los comprobantes fiscales:
 * 104015.53 → "Ciento cuatro mil quince pesos 53/100 M.N."
 */
function importeALetras(float $importe, string $moneda = 'MXN'): string {
    $negativo = $importe < 0;
    $importe  = abs($importe);

    // Se redondea primero: 0.005 debe subir al centavo, no perderse al truncar.
    $centavos = (int) round($importe * 100);
    $entero   = intdiv($centavos, 100);
    $cents    = $centavos % 100;

    $moneda = strtoupper($moneda) ?: 'MXN';
    $nombres = [
        'MXN' => ['peso', 'pesos', 'M.N.'],
        'USD' => ['dólar', 'dólares', 'USD'],
        'EUR' => ['euro', 'euros', 'EUR'],
    ];
    [$sing, $plural, $sufijo] = $nombres[$moneda] ?? ['peso', 'pesos', $moneda];

    $letras = apocoparUno(enteroALetras($entero));
    $texto  = mb_strtoupper(mb_substr($letras, 0, 1)) . mb_substr($letras, 1);

    // "Dos millones DE pesos", pero "Un millón quinientos mil pesos": la
    // preposición sólo aparece cuando la cifra termina justo en el millón.
    $de = ($entero >= 1000000 && $entero % 1000000 === 0) ? 'de ' : '';

    return ($negativo ? 'Menos ' : '') . $texto . ' ' . $de
         . ($entero === 1 ? $sing : $plural) . ' '
         . str_pad((string) $cents, 2, '0', STR_PAD_LEFT) . '/100 ' . $sufijo;
}
