<?php
/* ══════════════════════════════════════════════════════════════
   AVBA Control de Accesos — Excel (.xlsx) sencillo, sin librerías

   Arma un libro de Excel con varias hojas: renglón de títulos (en negritas,
   fijo al bajar y con filtros), anchos de columna, textos, números, fechas
   y horas. El .xlsx es un ZIP con archivos XML; el ZIP también se arma aquí
   (no hace falta la extensión zip del servidor).

     $x = new XlsxSimple();
     $x->hoja('Movimientos', ['ID', 'Nombre', 'Entrada'], $renglones, [10, 36, 18],
              ['texto', 'texto', 'fechahora']);
     $x->guardar('/ruta/archivo.xlsx');

   Tipos de columna: texto (el ID va como texto: no pierde sus ceros), entero,
   decimal (0.0), fechahora ('YYYY-MM-DD HH:MM:SS'), fecha ('YYYY-MM-DD').
   ══════════════════════════════════════════════════════════════ */

class XlsxSimple {
    /** @var array<int,array{nombre:string,xml:string}> */
    private array $hojas = [];

    // Estilos (índices de cellXfs en styles.xml)
    private const S_TITULO = 1, S_ENCABEZADO = 2, S_FECHAHORA = 3, S_FECHA = 4, S_DECIMAL = 5, S_NEGRITA = 6;

    /**
     * Agrega una hoja con tabla: títulos y renglones.
     * @param string[] $titulos
     * @param array<int,array<int,mixed>> $renglones
     * @param int[] $anchos  ancho de cada columna (en caracteres)
     * @param string[] $tipos tipo de cada columna
     * @param string[] $arriba renglones de texto antes de la tabla (título, periodo…)
     */
    public function hoja(string $nombre, array $titulos, array $renglones, array $anchos = [], array $tipos = [], array $arriba = []): void {
        $filas = [];
        $r = 0;
        foreach ($arriba as $i => $t) {
            $r++;
            $filas[] = '<row r="' . $r . '">' . self::celdaTexto('A' . $r, (string)$t, $i === 0 ? self::S_TITULO : 0) . '</row>';
        }
        if ($arriba) $r++;                                   // un renglón en blanco antes de la tabla
        $encabezado = $r + 1;
        $r++;
        $celdas = '';
        foreach (array_values($titulos) as $c => $t) $celdas .= self::celdaTexto(self::col($c) . $r, (string)$t, self::S_ENCABEZADO);
        $filas[] = '<row r="' . $r . '">' . $celdas . '</row>';
        foreach ($renglones as $ren) {
            $r++;
            $celdas = '';
            foreach (array_values($ren) as $c => $v) {
                $celdas .= self::celda(self::col($c) . $r, $v, $tipos[$c] ?? 'texto');
            }
            $filas[] = '<row r="' . $r . '">' . $celdas . '</row>';
        }
        $ultimaCol = self::col(max(0, count($titulos) - 1));
        $cols = '';
        foreach (array_values($anchos) as $c => $w) $cols .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . max(4, (int)$w) . '" customWidth="1"/>';
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
             . '<sheetViews><sheetView workbookViewId="0">'
             . '<pane ySplit="' . $encabezado . '" topLeftCell="A' . ($encabezado + 1) . '" activePane="bottomLeft" state="frozen"/>'
             . '</sheetView></sheetViews>'
             . '<sheetFormatPr defaultRowHeight="15"/>'
             . ($cols !== '' ? '<cols>' . $cols . '</cols>' : '')
             . '<sheetData>' . implode('', $filas) . '</sheetData>'
             . ($renglones ? '<autoFilter ref="A' . $encabezado . ':' . $ultimaCol . $r . '"/>' : '')
             . '<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>'
             . '</worksheet>';
        $this->hojas[] = ['nombre' => self::nombreHoja($nombre), 'xml' => $xml];
    }

    /** Hoja de resumen: título, renglones de texto y pares «concepto | valor». */
    public function resumen(string $nombre, array $arriba, array $pares): void {
        $filas = [];
        $r = 0;
        foreach ($arriba as $i => $t) {
            $r++;
            $filas[] = '<row r="' . $r . '">' . self::celdaTexto('A' . $r, (string)$t, $i === 0 ? self::S_TITULO : 0) . '</row>';
        }
        $r++;
        foreach ($pares as [$concepto, $valor, $tipo]) {
            $r++;
            $filas[] = '<row r="' . $r . '">' . self::celdaTexto('A' . $r, (string)$concepto, self::S_NEGRITA)
                     . self::celda('B' . $r, $valor, $tipo ?? 'texto') . '</row>';
        }
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
             . '<sheetFormatPr defaultRowHeight="15"/>'
             . '<cols><col min="1" max="1" width="44" customWidth="1"/><col min="2" max="2" width="22" customWidth="1"/></cols>'
             . '<sheetData>' . implode('', $filas) . '</sheetData>'
             . '<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>'
             . '</worksheet>';
        $this->hojas[] = ['nombre' => self::nombreHoja($nombre), 'xml' => $xml];
    }

    public function guardar(string $ruta): bool {
        if (!$this->hojas) $this->hoja('Hoja1', [''], []);
        $n = count($this->hojas);
        $tipos = '';
        $hojasWb = '';
        $rels = '';
        for ($i = 1; $i <= $n; $i++) {
            $tipos .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $hojasWb .= '<sheet name="' . self::x($this->hojas[$i - 1]['nombre']) . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
            $rels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
        }
        $rels .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $archivos = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
                . $tipos . '</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
                . '</Relationships>',
            'docProps/core.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
                . '<dc:title>Control de accesos</dc:title><dc:creator>AVBA Control de Accesos</dc:creator>'
                . '<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created>'
                . '</cp:coreProperties>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                . '<bookViews><workbookView/></bookViews><sheets>' . $hojasWb . '</sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>',
            'xl/styles.xml' => self::estilos(),
        ];
        foreach ($this->hojas as $i => $h) $archivos['xl/worksheets/sheet' . ($i + 1) . '.xml'] = $h['xml'];
        return @file_put_contents($ruta, self::zip($archivos)) !== false;
    }

    /* ── Celdas ── */
    private static function celda(string $ref, $v, string $tipo): string {
        if ($v === null || $v === '') return '';
        switch ($tipo) {
            case 'entero':
            case 'decimal':
                if (!is_numeric($v)) return self::celdaTexto($ref, (string)$v, 0);
                $num = $tipo === 'entero' ? (string)(int)round((float)$v) : rtrim(rtrim(sprintf('%.4F', (float)$v), '0'), '.');
                return '<c r="' . $ref . '"' . ($tipo === 'decimal' ? ' s="' . self::S_DECIMAL . '"' : '') . '><v>' . $num . '</v></c>';
            case 'fechahora':
            case 'fecha':
                $serie = self::serieExcel((string)$v);
                if ($serie === null) return self::celdaTexto($ref, (string)$v, 0);
                return '<c r="' . $ref . '" s="' . ($tipo === 'fecha' ? self::S_FECHA : self::S_FECHAHORA) . '"><v>' . $serie . '</v></c>';
            default:
                return self::celdaTexto($ref, (string)$v, 0);
        }
    }
    private static function celdaTexto(string $ref, string $t, int $estilo): string {
        if ($t === '') return '';
        return '<c r="' . $ref . '" t="inlineStr"' . ($estilo ? ' s="' . $estilo . '"' : '') . '><is><t xml:space="preserve">' . self::x($t) . '</t></is></c>';
    }
    /** Fecha y hora de la planta (como se ve en la pantalla) como número de serie de Excel. */
    private static function serieExcel(string $v): ?string {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/', $v, $m)) return null;
        $dias = intdiv(gmmktime(0, 0, 0, (int)$m[2], (int)$m[3], (int)$m[1]), 86400) + 25569;   // 25569 = 1970-01-01 en Excel
        $seg = (int)($m[4] ?? 0) * 3600 + (int)($m[5] ?? 0) * 60 + (int)($m[6] ?? 0);
        return rtrim(rtrim(sprintf('%.10F', $dias + $seg / 86400), '0'), '.');
    }
    private static function col(int $i): string {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
        return $s;
    }
    private static function x(string $t): string {
        $t = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $t);       // caracteres que el XML no acepta
        return htmlspecialchars((string)$t, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
    private static function nombreHoja(string $n): string {
        $n = trim(preg_replace('/[\[\]:*?\/\\\\]/', ' ', $n));
        return mb_substr($n !== '' ? $n : 'Hoja', 0, 31);
    }

    private static function estilos(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="3"><numFmt numFmtId="164" formatCode="dd/mm/yyyy hh:mm"/><numFmt numFmtId="165" formatCode="dd/mm/yyyy"/><numFmt numFmtId="166" formatCode="0.0"/></numFmts>'
            . '<fonts count="4">'
            . '<font><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
            . '<font><b/><sz val="14"/><color rgb="FF0C447C"/><name val="Calibri"/><family val="2"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/><family val="2"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
            . '</fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF0C447C"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="7">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="2" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="166" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    /** ZIP con los archivos dados (comprimidos si el servidor tiene zlib). */
    private static function zip(array $archivos): string {
        $datos = '';
        $central = '';
        $hora = getdate();
        $dosHora = ($hora['hours'] << 11) | ($hora['minutes'] << 5) | intdiv($hora['seconds'], 2);
        $dosFecha = (max(0, $hora['year'] - 1980) << 9) | ($hora['mon'] << 5) | $hora['mday'];
        foreach ($archivos as $nombre => $contenido) {
            $crc = crc32($contenido);
            $comprimido = function_exists('gzdeflate') ? gzdeflate($contenido, 6) : false;
            $metodo = $comprimido !== false ? 8 : 0;
            if ($comprimido === false) $comprimido = $contenido;
            $cabecera = pack('vvvvvVVVvv', 20, 0, $metodo, $dosHora, $dosFecha, $crc, strlen($comprimido), strlen($contenido), strlen($nombre), 0);
            $central .= pack('V', 0x02014b50) . pack('v', 20) . $cabecera . pack('vvvVV', 0, 0, 0, 0, strlen($datos)) . $nombre;
            $datos .= pack('V', 0x04034b50) . $cabecera . $nombre . $comprimido;
        }
        $n = count($archivos);
        return $datos . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, strlen($central), strlen($datos), 0);
    }
}
