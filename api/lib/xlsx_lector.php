<?php
/* ══════════════════════════════════════════════════════════════
   AVBA Control de Accesos — leer un Excel (.xlsx) o un .csv, sin librerías

   Regresa los renglones de la primera hoja como arreglos de textos, con el
   número de renglón que muestra Excel como llave:
     $r = LectorTabla::leer('/ruta/archivo.xlsx', 'personal.xlsx');
     // ['ok' => true, 'renglones' => [1 => ['ID', 'NOMBRE', …], 2 => ['00287', 'RAMIRO …'], …]]
     // ['ok' => false, 'message' => 'por qué no se pudo']

   El .xlsx es un ZIP con XML: el ZIP se abre aquí mismo (con zlib, que trae
   cualquier PHP), así no hace falta la extensión zip del servidor. Los números
   se regresan como texto tal cual («287», no «287.0»).
   ══════════════════════════════════════════════════════════════ */

class LectorTabla {
    public const MAX_RENGLONES = 20000;
    public const MAX_BYTES = 15 * 1048576;

    public static function leer(string $ruta, string $nombre): array {
        if (!is_file($ruta)) return ['ok' => false, 'message' => 'No llegó el archivo.'];
        if (filesize($ruta) > self::MAX_BYTES) return ['ok' => false, 'message' => 'El archivo pesa más de 15 MB.'];
        $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
        $inicio = (string)@file_get_contents($ruta, false, null, 0, 4);
        if ($ext === 'xls') return ['ok' => false, 'message' => 'El archivo es de un Excel antiguo (.xls). Ábrelo en Excel y guárdalo como «Libro de Excel (.xlsx)».'];
        if (str_starts_with($inicio, "PK\x03\x04")) return self::xlsx($ruta);
        if ($ext === 'xlsx') return ['ok' => false, 'message' => 'El archivo no parece un Excel válido.'];
        return self::csv($ruta);
    }

    /* ── .csv ── */
    private static function csv(string $ruta): array {
        $txt = (string)file_get_contents($ruta);
        if (str_starts_with($txt, "\xEF\xBB\xBF")) $txt = substr($txt, 3);
        if (!mb_check_encoding($txt, 'UTF-8')) $txt = mb_convert_encoding($txt, 'UTF-8', 'Windows-1252');   // CSV guardado por Excel en Windows
        $primera = strtok($txt, "\n") ?: '';
        $sep = ',';
        foreach ([';', "\t", '|'] as $s) if (substr_count($primera, $s) > substr_count($primera, $sep)) $sep = $s;
        $f = fopen('php://memory', 'w+');
        fwrite($f, $txt);
        rewind($f);
        $out = []; $n = 0;
        while (($r = fgetcsv($f, 0, $sep, '"', '')) !== false) {
            $n++;
            if ($r === [null]) continue;
            $out[$n] = array_map(fn($v) => trim((string)$v), $r);
            if (count($out) > self::MAX_RENGLONES + 20) break;
        }
        fclose($f);
        return ['ok' => true, 'renglones' => $out];
    }

    /* ── .xlsx ── */
    private static function xlsx(string $ruta): array {
        $zip = self::abrirZip((string)file_get_contents($ruta));
        if ($zip === null) return ['ok' => false, 'message' => 'No se pudo abrir el Excel (¿está dañado o protegido con contraseña?).'];
        // Primera hoja del libro
        $hoja = 'xl/worksheets/sheet1.xml';
        $wb = self::xml($zip, 'xl/workbook.xml');
        $rels = self::xml($zip, 'xl/_rels/workbook.xml.rels');
        if ($wb && $rels) {
            $wb->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $primera = $wb->xpath('//m:sheets/m:sheet')[0] ?? null;
            if ($primera) {
                $rid = (string)($primera->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? '');
                foreach ($rels->children() as $rel) {
                    if ((string)$rel['Id'] === $rid) {
                        $t = ltrim((string)$rel['Target'], '/');
                        $hoja = str_starts_with($t, 'xl/') ? $t : 'xl/' . $t;
                    }
                }
            }
        }
        if (!isset($zip[$hoja])) return ['ok' => false, 'message' => 'El Excel no tiene hojas con datos.'];
        // Textos compartidos
        $textos = [];
        if ($ss = self::xml($zip, 'xl/sharedStrings.xml')) {
            foreach ($ss->si as $si) $textos[] = self::textoDe($si);
        }
        $sh = self::xml($zip, $hoja);
        if (!$sh) return ['ok' => false, 'message' => 'No se pudo leer la hoja del Excel.'];
        $out = []; $n = 0;
        foreach ($sh->sheetData->row as $row) {
            $n = (int)$row['r'] > $n ? (int)$row['r'] : $n + 1;          // el número de renglón de Excel
            $ren = [];
            foreach ($row->c as $c) {
                $col = self::columna((string)$c['r']);
                $t = (string)$c['t'];
                if ($t === 's') $v = $textos[(int)$c->v] ?? '';
                elseif ($t === 'inlineStr') $v = self::textoDe($c->is);
                elseif ($t === 'b') $v = ((string)$c->v) === '1' ? 'SI' : 'NO';
                else $v = self::numero((string)$c->v);
                if ($col < 0) $col = count($ren);
                $ren[$col] = trim($v);
            }
            if (!$ren) continue;
            $max = max(array_keys($ren));
            $fila = [];
            for ($i = 0; $i <= $max; $i++) $fila[] = $ren[$i] ?? '';
            $out[$n] = $fila;
            if (count($out) > self::MAX_RENGLONES + 20) break;
        }
        return ['ok' => true, 'renglones' => $out];
    }

    /** «287», no «287.0»; números grandes sin notación científica. */
    private static function numero(string $v): string {
        if ($v === '' || !is_numeric($v)) return $v;
        if (preg_match('/^-?\d+$/', $v)) return $v;
        $f = (float)$v;
        if (floor($f) == $f && abs($f) < 1e15) return number_format($f, 0, '.', '');
        return rtrim(rtrim(sprintf('%.6F', $f), '0'), '.');
    }
    private static function textoDe($nodo): string {
        if (!$nodo) return '';
        if (isset($nodo->t)) {
            $s = (string)$nodo->t;
            if (!isset($nodo->r)) return $s;
        }
        $s = '';
        foreach ($nodo->r as $r) $s .= (string)$r->t;                     // texto con formatos mezclados
        return $s !== '' ? $s : (string)($nodo->t ?? '');
    }
    private static function columna(string $ref): int {
        if (!preg_match('/^([A-Z]+)/', $ref, $m)) return -1;
        $n = 0;
        foreach (str_split($m[1]) as $ch) $n = $n * 26 + (ord($ch) - 64);
        return $n - 1;
    }
    private static function xml(array $zip, string $nombre): ?SimpleXMLElement {
        if (!isset($zip[$nombre])) return null;
        $prev = libxml_use_internal_errors(true);
        $x = simplexml_load_string($zip[$nombre], 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        libxml_use_internal_errors($prev);
        return $x ?: null;
    }

    /** Los archivos del ZIP que interesan (xl/…), ya descomprimidos. */
    private static function abrirZip(string $d): ?array {
        $fin = strrpos($d, "PK\x05\x06");
        if ($fin === false || strlen($d) < $fin + 22) return null;
        $e = unpack('vdisco/vdiscoDir/vaqui/vtotal/Vtam/Vdesde', substr($d, $fin + 4, 16));
        $p = $e['desde'];
        $out = [];
        for ($i = 0; $i < $e['total']; $i++) {
            if (substr($d, $p, 4) !== "PK\x01\x02") return null;
            $c = unpack('vver/vnec/vbits/vmetodo/vhora/vfecha/Vcrc/Vcomp/Vtam/vlnom/vlextra/vlcom/vdisco/vint/Vext/Vlocal', substr($d, $p + 4, 42));
            $nombre = substr($d, $p + 46, $c['lnom']);
            $p += 46 + $c['lnom'] + $c['lextra'] + $c['lcom'];
            if (!str_starts_with($nombre, 'xl/') || !str_ends_with($nombre, '.xml') && !str_ends_with($nombre, '.rels')) continue;
            if ($c['tam'] > 60 * 1048576) return null;                       // demasiado grande al descomprimir
            $l = unpack('vlnom/vlextra', substr($d, $c['local'] + 26, 4));
            $datos = substr($d, $c['local'] + 30 + $l['lnom'] + $l['lextra'], $c['comp']);
            if ($c['metodo'] === 8) $datos = @gzinflate($datos);
            elseif ($c['metodo'] !== 0) return null;
            if ($datos === false) return null;
            $out[$nombre] = $datos;
        }
        return $out;
    }
}
