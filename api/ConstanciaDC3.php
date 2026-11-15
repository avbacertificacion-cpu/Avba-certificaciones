<?php
/**
 * Constancia DC-3 (formato oficial STPS) · diseño «Línea AVBA 2026».
 *
 * Personal::htmlDC3() delega aquí la presentación; los datos se calculan igual que antes
 * (mayúsculas, CURP, RFC con guiones, fecha fin = inicio + ceil(horas/8) − 1 días, agente
 * capacitador = registro STPS o nombre del instructor, firma propia o la del director).
 *
 * Cambios de presentación respecto del formato anterior:
 *  · Nombres bajo las firmas en MAYÚSCULAS y del mismo tamaño los tres; leyenda «Nombre y firma».
 *  · Ocupaciones una por renglón con sangría francesa (clave | denominación); si son muchas, en
 *    párrafo corrido separadas por «·». Las entradas vacías no dejan renglón en blanco.
 *  · RFC vacío: retícula 4-6-3 con guiones para llenarse a mano; RFC fuera de patrón: sus
 *    caracteres tal cual, completando 13 cajas.
 *  · Duración que no es un número entero: se escribe a tamaño de valor y sin «HORAS».
 *  · Imágenes con su MIME real; GIF/WEBP/BMP se convierten a PNG conservando la transparencia
 *    (mPDF no lee una firma GIF/WEBP ni un logo JPEG etiquetado como PNG). La firma se re-entinta
 *    en azul tinta y el papel de una firma escaneada se vuelve transparente.
 *  · El anverso se MIDE con mPDF y se compacta sólo lo necesario para que quepa siempre en una
 *    hoja carta, con el pie a la misma altura; lo que sobra se le da al renglón de firmas.
 *
 * Igual que antes, a propósito: la duración se imprime entera ('4.5' → '4'); la fecha fin sí usa
 * el valor real.
 */
final class ConstanciaDC3
{
    /**
     * @param array      $p        datos del participante (los mismos que recibía htmlDC3)
     * @param string     $qrB64    data URI del QR de validación ('' = sin QR)
     * @param callable   $asset    fn(string $rel): string — data URI de un archivo de assets/
     * @param array|null $cfgMpdf  configuración de mPDF con la que se generará el PDF (Letter);
     *                             null = sin medición (sólo el estimador)
     * @return string documento HTML completo (un <style> y el <body>, como lo espera htmlToPdfMpdf)
     */
    public static function html(array $p, string $qrB64, callable $asset, ?array $cfgMpdf): string
    {
        $esc  = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
        $raiz = dirname(__DIR__);

        // ── Datos (idéntico a htmlDC3) ─────────────────────────────────────
        $up = fn($s) => mb_strtoupper(trim((string)$s), 'UTF-8');
        $nombre    = $up($p['nombre_completo'] ?? '');
        $curp      = strtoupper(trim($p['curp'] ?? ''));
        $puesto    = $up($p['puesto'] ?? '');
        $empresa   = $up($p['empresa_nombre'] ?? '');
        $rfcRaw    = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $p['empresa_rfc'] ?? ''));
        $patron    = $up($p['empresa_representante'] ?? '');
        $repTrab   = $up($p['empresa_rep_trabajadores'] ?? '');
        $curso     = $up($p['curso_nombre'] ?? '');
        $horasVal  = (float)($p['duracion_horas'] ?? 0);
        $horas     = $horasVal > 0 ? (string)(int)$horasVal : trim($p['duracion_horas'] ?? '');   // (int): ver encabezado
        $area      = trim($p['area_tematica'] ?? '');
        $fechaYmd  = $p['fecha_curso'] ?? null;

        // Ocupaciones específicas de la empresa; si no hay, la del participante
        $ocupEmpresa = $p['ocupaciones_empresa'] ?? [];
        if (empty($ocupEmpresa) && !empty($p['ocupacion_nombre'])) {
            $ocupEmpresa = [trim($p['ocupacion_nombre'])];
        }

        // Fecha fin: 8 h/día máximo
        $fechaFinYmd = $fechaYmd;
        if ($fechaYmd && $horasVal > 0) {
            $diasExtra = max(0, (int)ceil($horasVal / 8) - 1);
            $fechaFinYmd = date('Y-m-d', strtotime($fechaYmd . " +{$diasExtra} days"));
        }

        // Instructor / agente capacitador: el registro STPS tal cual; si no hay, el nombre del instructor
        $instrNombre    = trim($p['instructor_nombre'] ?? '');
        $instrStps      = trim($p['instructor_stps'] ?? '');
        $instrFirmaPath = trim($p['instructor_firma_imagen'] ?? '');
        $agente = $instrStps
            ? strtoupper($instrStps)
            : ($instrNombre ? $up($instrNombre) : 'AVBA CERTIFICACIONES');

        // RFC con guiones para mostrar (LLLL-YYMMDD-XXX)
        $rfcFmt = $rfcRaw;
        if (preg_match('/^([A-Z]{3,4})(\d{6})([A-Z0-9]{2,3})$/', $rfcRaw, $m)) {
            $rfcFmt = $m[1] . '-' . $m[2] . '-' . $m[3];
        }

        // ── Imágenes ────────────────────────────────────────────────────────
        // Contenido binario de un data URI (null si no es data URI)
        $decodificar = function (string $uri): ?string {
            if (strncasecmp($uri, 'data:', 5) !== 0 || ($coma = strpos($uri, ',')) === false) return null;
            $meta = substr($uri, 5, $coma - 5);
            $bin  = stripos($meta, ';base64') !== false ? base64_decode(substr($uri, $coma + 1)) : rawurldecode(substr($uri, $coma + 1));
            return ($bin === false || $bin === '') ? null : $bin;
        };
        // MIME por el CONTENIDO, no por la extensión ni por la etiqueta del data URI
        $mimeDe = function (string $bin): string {
            $i = @getimagesizefromstring($bin);
            if ($i && !empty($i['mime'])) return $i['mime'];
            return (preg_match('/^(?:\xEF\xBB\xBF)?\s*(?:<\?xml|<!--|<!DOCTYPE\s+svg|<svg)/i', substr($bin, 0, 512))
                    && stripos($bin, '<svg') !== false) ? 'image/svg+xml' : '';
        };
        // ¿Hay memoria para que GD decodifique w×h px (4 bytes/px + margen)? Una foto de 48 MP pide ~200 MB
        // y agotaría memory_limit (256M en producción) con un error fatal; sin memoria, la imagen va tal cual.
        $memoriaGd = function (int $w, int $h): bool {
            $lim = trim((string)ini_get('memory_limit'));
            if ($lim === '' || $lim === '-1') return true;
            $u = strtolower(substr($lim, -1));
            $bytes = (float)$lim * ($u === 'g' ? 1073741824 : ($u === 'm' ? 1048576 : ($u === 'k' ? 1024 : 1)));
            return $w * $h * 5.0 + 24e6 < $bytes - memory_get_usage();
        };
        // Imagen → data URI que mPDF lee bien: PNG/JPEG/SVG con su MIME real; GIF, WEBP, BMP… → PNG con su
        // transparencia (mPDF pinta de negro el fondo transparente de un WEBP y no lee un GIF/WEBP/JPEG
        // con la etiqueta equivocada). Los mapas de bits de más de $maxLado px se reducen (cada pasada del
        // medidor los decodifica). '' si el contenido no es una imagen: mejor nada que una imagen rota.
        $normalizar = function (string $uri, int $maxLado) use ($decodificar, $mimeDe, $memoriaGd): string {
            if ($uri === '') return '';
            $bin = $decodificar($uri);
            if ($bin === null) return $uri;                                   // ruta o URL: tal cual
            $mime = $mimeDe($bin);
            if ($mime === '') return '';
            if ($mime === 'image/svg+xml') return 'data:image/svg+xml;base64,' . base64_encode($bin);
            $info   = @getimagesizefromstring($bin);
            $grande = $info && max($info[0], $info[1]) > $maxLado;
            $etiqueta = 'data:' . $mime . ';base64,' . base64_encode($bin);
            if (($mime === 'image/png' || $mime === 'image/jpeg') && !$grande) return $etiqueta;
            if (!function_exists('imagecreatefromstring') || !$info || !$memoriaGd($info[0], $info[1])
                || !($src = @imagecreatefromstring($bin))) return $etiqueta;  // sin GD: al menos con su MIME real
            if (!imageistruecolor($src)) imagepalettetotruecolor($src);
            [$w, $h] = [imagesx($src), imagesy($src)];
            $f  = min(1.0, $maxLado / max($w, $h));
            [$nw, $nh] = [max(1, (int)round($w * $f)), max(1, (int)round($h * $f))];
            $im = imagecreatetruecolor($nw, $nh);
            imagealphablending($im, false);
            imagesavealpha($im, true);
            imagefilledrectangle($im, 0, 0, $nw, $nh, imagecolorallocatealpha($im, 0, 0, 0, 127));
            imagecopyresampled($im, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($src);
            $jpg = $mime === 'image/jpeg';
            ob_start();
            $jpg ? imagejpeg($im, null, 90) : imagepng($im, null, 9);
            $out = ob_get_clean();
            imagedestroy($im);
            return $out ? 'data:image/' . ($jpg ? 'jpeg' : 'png') . ';base64,' . base64_encode($out) : $etiqueta;
        };

        $logoB64  = $asset('logos/avba.png');
        $selloB64 = $asset('sellos/sello.png');
        // Firma del instructor: la propia si existe y es una imagen; si no, la del director
        $instrFirmaB64 = '';
        if ($instrFirmaPath) {
            $absPath = $raiz . '/' . ltrim($instrFirmaPath, '/');
            if (file_exists($absPath)) {
                $ext  = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
                $mime = in_array($ext, ['jpg', 'jpeg']) ? 'image/jpeg' : 'image/png';       // como htmlDC3; $normalizar corrige
                $instrFirmaB64 = $normalizar('data:' . $mime . ';base64,' . base64_encode(file_get_contents($absPath)), 1600);
                if ($instrFirmaB64 === '') error_log('[DC-3] La firma del instructor no es una imagen legible: ' . $absPath);
            } else {
                error_log('[DC-3] No se encontró la firma del instructor (se usa firma_director.png): ' . $absPath);
            }
        }
        if (!$instrFirmaB64) $instrFirmaB64 = $asset('logos/firma_director.png');
        $logoEmpB64 = $normalizar((string)($p['empresa_logo_b64'] ?? ''), 1000);

        // Firma legible al imprimir (requiere GD; sin GD se usa la original):
        //  · trazos CLAROS sobre fondo transparente (logos/firma_director.png los trae
        //    blancos) → se re-entintan en azul tinta #162C6A conservando la transparencia;
        //  · firma escaneada sin transparencia (JPG/PNG/GIF/WEBP sobre papel claro) → el
        //    papel se vuelve transparente y el trazo queda en su propia tinta, así no tapa
        //    la línea ni deja un recuadro gris;
        //  · firma oscura sobre transparente → se deja tal cual.
        // Llega ya normalizada (PNG o JPEG); si aun así no lo fuera, las salidas «tal cual»
        // devuelven el PNG re-codificado en vez del original, que mPDF no podría leer.
        $entintar = function (string $uri) use ($decodificar, $mimeDe, $memoriaGd): string {
            $bin = function_exists('imagecreatefromstring') && function_exists('imagefilter') ? $decodificar($uri) : null;
            if ($bin === null) return $uri;
            $info = @getimagesizefromstring($bin);
            if (!$info || !$memoriaGd($info[0], $info[1])) return $uri;
            $src = @imagecreatefromstring($bin);
            if (!$src) return $uri;
            $nativo = in_array($mimeDe($bin), ['image/png', 'image/jpeg'], true);
            if (!imageistruecolor($src)) imagepalettetotruecolor($src);
            [$w, $h] = [imagesx($src), imagesy($src)];
            $nw = min($w, 760);                                   // ≈ 600 ppp al tamaño impreso
            $nh = max(1, (int)round($h * $nw / $w));
            $im = imagecreatetruecolor($nw, $nh);
            imagealphablending($im, false);
            imagesavealpha($im, true);
            imagefilledrectangle($im, 0, 0, $nw, $nh, imagecolorallocatealpha($im, 0, 0, 0, 127));
            imagecopyresampled($im, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($src);
            $png = function () use ($im): string {
                ob_start();
                imagepng($im, null, 9);
                $out = ob_get_clean();
                imagedestroy($im);
                return $out ? 'data:image/png;base64,' . base64_encode($out) : '';
            };
            $talCual = function () use ($nativo, $uri, $im, $png): string {
                if ($nativo) { imagedestroy($im); return $uri; }
                return $png() ?: $uri;
            };
            $lum = fn(int $c) => 0.299 * (($c >> 16) & 0xFF) + 0.587 * (($c >> 8) & 0xFF) + 0.114 * ($c & 0xFF);
            // Muestreo: transparencia, luminancia de lo visible
            $paso = max(1, intdiv(max($nw, $nh), 180));
            [$suma, $peso, $transp, $lums] = [0.0, 0.0, 0, []];
            for ($y = 0; $y < $nh; $y += $paso) {
                for ($x = 0; $x < $nw; $x += $paso) {
                    $c = imagecolorat($im, $x, $y);
                    $a = ($c >> 24) & 0x7F;                          // 0 opaco … 127 transparente
                    if ($a >= 120) { $transp++; continue; }
                    $op = (127 - $a) / 127;
                    $l = $lum($c);
                    $lums[] = $l;
                    $suma += $op * $l;
                    $peso += $op;
                }
            }
            $total = $transp + count($lums);
            if ($peso <= 0) return $talCual();
            if ($transp >= $total * 0.2) {                            // ya tiene fondo transparente
                if ($suma / $peso < 150) return $talCual();           // tinta oscura: tal cual
                imagefilter($im, IMG_FILTER_BRIGHTNESS, -255);       // todo a negro, el alfa intacto
                imagefilter($im, IMG_FILTER_COLORIZE, 0x16, 0x2C, 0x6A);   // → azul tinta #162C6A
            } else {                                                  // escaneo opaco
                sort($lums);
                $papel = $lums[(int)(count($lums) * 0.85)];
                if ($papel < 170) return $talCual();                  // fondo oscuro o foto: tal cual
                // Tinta = color medio de lo claramente oscuro, llevado a luminancia ≤ 70
                [$r, $g, $b, $n] = [0, 0, 0, 0];
                for ($y = 0; $y < $nh; $y += $paso) {
                    for ($x = 0; $x < $nw; $x += $paso) {
                        $c = imagecolorat($im, $x, $y);
                        if ($lum($c) < $papel - 90) { $r += ($c >> 16) & 0xFF; $g += ($c >> 8) & 0xFF; $b += $c & 0xFF; $n++; }
                    }
                }
                if ($n === 0) return $talCual();
                [$r, $g, $b] = [$r / $n, $g / $n, $b / $n];
                $f = min(1.0, 70 / max(1.0, 0.299 * $r + 0.587 * $g + 0.114 * $b));
                $tinta = ((int)($r * $f) << 16) | ((int)($g * $f) << 8) | (int)($b * $f);
                $lo = 70.0;                                            // desde aquí, opaco
                $hi = $papel - 14;                                     // desde aquí, transparente
                for ($y = 0; $y < $nh; $y++) {
                    for ($x = 0; $x < $nw; $x++) {
                        $t = ($hi - $lum(imagecolorat($im, $x, $y))) / ($hi - $lo);
                        $a = $t >= 1 ? 0 : ($t <= 0 ? 127 : (int)round(127 * (1 - $t)));
                        imagesetpixel($im, $x, $y, ($a << 24) | $tinta);
                    }
                }
            }
            return $png() ?: $uri;
        };
        if ($instrFirmaB64) $instrFirmaB64 = $entintar($instrFirmaB64);

        // Proporción ancho/alto de una imagen (SVG: width/height o viewBox); null si no se puede saber
        $proporcion = function (string $uri) use ($decodificar): ?float {
            $bin = $uri !== '' ? $decodificar($uri) : null;
            if ($bin === null) return null;
            $i = @getimagesizefromstring($bin);
            if ($i && $i[0] > 0 && $i[1] > 0) return $i[0] / $i[1];
            if (!preg_match('/<svg\b[^>]*>/is', $bin, $t)) return null;
            $u = ['' => 1.0, 'px' => 1.0, 'pt' => 4 / 3, 'pc' => 16.0, 'mm' => 96 / 25.4, 'cm' => 96 / 2.54, 'in' => 96.0];
            $dim = fn(string $a) => preg_match('/\s' . $a . '\s*=\s*["\']\s*([\d.]+)\s*(px|pt|pc|mm|cm|in)?\s*["\']/i', $t[0], $x)
                ? (float)$x[1] * $u[strtolower($x[2] ?? '')] : 0.0;
            [$w, $h] = [$dim('width'), $dim('height')];
            if (!($w > 0 && $h > 0)
                && preg_match('/\sviewBox\s*=\s*["\']\s*[-\d.e]+[\s,]+[-\d.e]+[\s,]+([\d.e]+)[\s,]+([\d.e]+)/i', $t[0], $vb)) {
                [$w, $h] = [(float)$vb[1], (float)$vb[2]];
            }
            return ($w > 0 && $h > 0) ? $w / $h : null;
        };
        // <img> dentro de una caja máxima (mm) respetando la proporción; si no se conoce, sólo el alto
        $imgCaja = function (string $uri, ?float $r, float $hMax, float $wMax, string $alt): string {
            if ($uri === '') return '';
            if ($r === null) return '<img src="' . $uri . '" style="height:' . round($hMax, 1) . 'mm;width:auto" alt="' . $alt . '">';
            [$w, $h] = [$hMax * $r, $hMax];
            if ($w > $wMax) [$w, $h] = [$wMax, $wMax / $r];
            return '<img src="' . $uri . '" style="width:' . round($w, 1) . 'mm;height:' . round($h, 1) . 'mm" alt="' . $alt . '">';
        };
        $rLogoEmp = $proporcion($logoEmpB64);
        $rFirma   = $proporcion($instrFirmaB64);
        $rSello   = $proporcion($selloB64);

        $instrDisplay = $up($instrNombre ?: 'Ing. José Marcos González Calderón');   // MAYÚSCULAS: cambio intencional (ver encabezado)

        // ── Medición de textos ──────────────────────────────────────────────
        // $cfgMpdf es la MISMA configuración con la que Personal::htmlToPdfMpdf genera el PDF (formato
        // Letter). Con ella se miden los anchos de texto y, sobre todo, el anverso completo (ver «Relleno de
        // página»). Sin mPDF o sin las fuentes de la marca se usa sólo el estimador.
        // El medidor se crea una vez por proceso y por configuración.
        static $medidores = [];
        $medidor = null;
        if ($cfgMpdf !== null && class_exists('\\Mpdf\\Mpdf')) {
            $clave = md5(serialize($cfgMpdf));
            if (!array_key_exists($clave, $medidores)) {
                try { $medidores[$clave] = new \Mpdf\Mpdf($cfgMpdf); } catch (\Throwable $e) { $medidores[$clave] = null; }
                if ($medidores[$clave] && empty($medidores[$clave]->fontdata['avbainter'])) $medidores[$clave] = null;   // sin las fuentes de marca
            }
            $medidor = $medidores[$clave];
        }
        if ($medidor === null) $cfgMpdf = null;
        $usarMedidor = $medidor !== null;
        // Ancho (mm) de un texto. Sin mPDF: promedio por carácter de mayúsculas (aprox. ±10 %; sólo
        // orienta el estimador, que en ese caso tampoco puede medir el anverso).
        $ancho = function (string $s, float $pt, string $fam, string $st) use ($medidor, $usarMedidor): float {
            if (!mb_check_encoding($s, 'UTF-8')) return 0.0;              // $esc lo dejará vacío
            if ($usarMedidor) { $medidor->SetFont($fam, $st, $pt); return $medidor->GetStringWidth($s); }
            return mb_strlen($s) * ($fam === 'avbamont' ? 0.237 : 0.222) * $pt;
        };
        // Renglones que ocupa $s en un ancho dado (ajuste por palabras, como mPDF; una palabra más ancha
        // que el renglón se parte —overflow:wrap— y ocupa los renglones que haga falta)
        $lineas = function (string $s, float $anchoMm, float $pt, string $fam = 'avbainter', string $st = 'B') use ($ancho): int {
            $s = trim($s);
            if ($s === '' || !mb_check_encoding($s, 'UTF-8')) return 1;   // UTF-8 inválido: $esc lo deja vacío, como htmlDC3
            $esp = $ancho(' ', $pt, $fam, $st);
            [$n, $act] = [1, 0.0];
            foreach (preg_split('/\s+/u', $s) ?: [$s] as $w) {
                $ww = $ancho($w, $pt, $fam, $st);
                if ($act > 0 && $act + $esp + $ww > $anchoMm) { $n++; $act = $ww; }
                else $act += ($act > 0 ? $esp : 0) + $ww;
                while ($act > $anchoMm) { $n++; $act -= $anchoMm; }
            }
            return $n;
        };
        // Estilo de un valor: sin cortes en guiones (mPDF partiría «NOM-006-» / «STPS-2014») salvo que alguna
        // palabra no quepa en el renglón; entonces mPDF la parte en sus guiones o donde termine el renglón.
        // Así no hace falta insertar espacios de ancho cero, que se quedaban en el texto copiable del PDF.
        $sinGuion = function (string $s, float $anchoMm, float $pt = 9.8) use ($ancho): string {
            if (!mb_check_encoding($s, 'UTF-8')) return '';
            foreach (preg_split('/\s+/u', trim($s)) ?: [] as $w) {
                if ($w !== '' && $ancho($w, $pt, 'avbainter', 'B') > $anchoMm) return '';
            }
            return ' style="hyphens:none"';
        };

        // Nombres en las firmas: UN solo tamaño para los tres, el que necesita el más
        // largo. Es el mayor tamaño (hasta 8.2 pt si todos caben en un renglón, 7.4 pt
        // si alguno necesita dos o más; nunca menos de 6.5 pt) con el que ningún nombre
        // ocupa más renglones de los que ocuparía al mínimo de 6.5 pt.
        $ANCHO_FIRMA = 56.6;   // medido: mPDF parte antes de 57.6 mm en la celda de 58 mm
        $firmantes = [$instrDisplay, $patron, $repTrab];
        $renglonesA = fn(float $pt) => array_map(fn($n) => $lineas($n, $ANCHO_FIRMA, $pt, 'avbamont', ''), $firmantes);
        $minimos = $renglonesA(6.5);
        $firLineas = max($minimos);
        $sigPt = 6.5;
        for ($t = ($firLineas === 1 ? 8.2 : 7.4); $t > 6.5; $t -= 0.1) {
            if ($renglonesA($t) === $minimos) { $sigPt = round($t, 1); break; }
        }

        // ── Ocupaciones ─────────────────────────────────────────────────────
        // Una por renglón (como htmlDC3) con sangría francesa: la clave en su columna y la
        // denominación alineada, así una ocupación que ocupa dos renglones no parece dos.
        // Si son tantas que obligarían a achicar la letra del anverso: párrafo corrido
        // separadas por «·», cada una sin partirse (y, si no cabe sola en el renglón, con
        // la clave pegada a su primera palabra). Devuelve [renglones, html].
        $ANCHO_OCUP = 80.5;
        $itemsOcup = [];
        foreach ($ocupEmpresa as $o) {
            $o = trim((string)$o);
            if ($o === '') continue;                              // htmlDC3 dejaría un renglón en blanco
            $itemsOcup[] = (mb_check_encoding($o, 'UTF-8') && preg_match('/^(\d{2}(?:\.\d{1,2})?)\s+(\S.*)$/u', $o, $mo))
                ? [$mo[1], $mo[2], $o] : ['', $o, $o];
        }
        $nOcup = count($itemsOcup);
        $ocupBase = $nOcup >= 4 ? 8.2 : 9.0;             // muchas ocupaciones → letra menor
        $ocupar = function (float $fs, bool $parrafo) use ($itemsOcup, $nOcup, $ancho, $lineas, $esc, $ANCHO_OCUP): array {
            if ($nOcup === 0) return [1, ''];
            if (!$parrafo) {
                $kw = 0.0;
                foreach ($itemsOcup as [$k]) if ($k !== '') $kw = max($kw, $ancho($k, $fs, 'avbainter', 'B'));
                $kw = $kw > 0 ? round($kw + 1.6, 1) : 0.0;      // columna de la clave + separación
                $td = 'padding:0;border:none;vertical-align:top;font-weight:bold;color:#0B2545;line-height:1.25;';
                [$n, $filas] = [0, ''];
                foreach ($itemsOcup as [$k, $den, $o]) {
                    if ($k !== '') {
                        $n += $lineas($den, $ANCHO_OCUP - $kw, $fs);
                        $filas .= '<tr><td style="' . $td . 'width:' . $kw . 'mm">' . $esc($k) . '</td><td style="' . $td . '">' . $esc($den) . '</td></tr>';
                    } else {
                        $n += $lineas($o, $ANCHO_OCUP, $fs);
                        $filas .= '<tr><td style="' . $td . '"' . ($kw > 0 ? ' colspan="2"' : '') . '>' . $esc($o) . '</td></tr>';
                    }
                }
                return [$n, '<table style="width:100%;border-collapse:collapse;overflow:wrap;hyphens:none;font-size:' . $fs . 'pt;margin-top:.5mm">' . $filas . '</table>'];
            }
            $partes = [];
            [$n, $act, $sepW] = [1, 0.0, $ancho(' · ', $fs, 'avbainter', 'B')];
            foreach ($itemsOcup as [$k, $den, $o]) {
                $wo = $ancho($o, $fs, 'avbainter', 'B');
                if ($wo <= $ANCHO_OCUP) {
                    $partes[] = str_replace(' ', '&nbsp;', $esc($o));
                    if ($act > 0 && $act + $sepW + $wo > $ANCHO_OCUP) { $n++; $act = $wo; }
                    else $act += ($act > 0 ? $sepW : 0) + $wo;
                } else {                                         // no cabe en un renglón: se parte, pero la clave va con su denominación
                    $pal = preg_split('/\s+/u', $o) ?: [$o];
                    $partes[] = count($pal) > 1 ? $esc($pal[0]) . '&nbsp;' . $esc(implode(' ', array_slice($pal, 1))) : $esc($o);
                    $n += $lineas($o, $ANCHO_OCUP, $fs) + ($act > 0 ? 1 : 0) - 1;
                    $act = $ANCHO_OCUP;
                }
            }
            // el «·» va pegado a la ocupación anterior: un renglón nunca empieza con el separador
            return [$n, '<div class="val val-s" style="font-size:' . $fs . 'pt">' . implode('&nbsp;<span style="color:#C9A84C">·</span> ', $partes) . '</div>'];
        };

        // ── Relleno de página ───────────────────────────────────────────────
        // El anverso debe ocupar exactamente UNA hoja carta y verse lleno con cualquier dato.
        // Un parámetro de compactación s lo resume:
        //   s = 0      normal; la holgura se entrega al renglón de firmas (más espacio para firmar);
        //   0 < s ≤ 1  márgenes internos menores (kc);
        //   1 < s ≤ 2  banda, encabezado, instrucciones y firma/sello más compactos, valores 9.8 → 8.4 pt (kx);
        //   2 < s ≤ 3  sólo con datos al límite de la BD o absurdos: valores 8.4 → 7.2 pt (kz).
        // Un estimador (renglones por campo) propone s; luego se MIDE el anverso con mPDF y se busca el
        // menor s con el que cabe dejando $LIBRE mm al pie; lo que sobra va al renglón de firmas.
        // Así el pie queda igual en todos los casos y nunca se pasa a una 2.ª hoja.
        // Anchos útiles medidos sobre renders reales (carta, márgenes de 12 mm):
        // ancho completo 184 mm, media celda 88.5, ocupaciones 80.5, nombre en firma 56.6.
        $LV0 = 4.2;                                      // mm por renglón de un valor a 9.8 pt (medido)
        $estimar = function (float $vp, bool $parrafo) use ($lineas, $ocupar, $ocupBase, $LV0, $nombre, $puesto, $empresa, $curso, $area, $agente): float {
            $n = $lineas($nombre, 184.0, $vp) + $lineas($puesto, 184.0, $vp) + $lineas($empresa, 184.0, $vp)
               + $lineas($curso, 184.0, $vp)                                             // celdas de ancho completo
               + max($lineas($area, 88.5, $vp), $lineas($agente, 88.5, $vp));          // medias celdas
            $e = $n * $LV0 * $vp / 9.8 - 5 * $LV0;
            $fs = $ocupBase * $vp / 9.8;
            [$ocL] = $ocupar($fs, $parrafo);
            if ($ocL >= 2) $e += 0.5 + ($ocL - 2) * $fs * 1.25 * 0.3528;               // la fila del CURP aloja ~2 renglones
            return $e;
        };
        // Firmas: el caso base es un renglón a 8.2 pt; cada renglón mide tamaño × 1.25
        $extraFirmas = $firLineas * $sigPt * 1.25 * 0.3528 - 8.2 * 1.25 * 0.3528;
        $ALTO_MIN = 15.6;   // alto mínimo del renglón de firmas (lo fijan la firma y el sello)
        $HOLGURA  = 7.0;    // libre en el caso base con firmas al mínimo (8.8 mm, medido) menos 1.8 mm de seguridad
        $AHORRO   = 20.0;   // lo que se recupera en la etapa 1 completa (medido)
        $AHORRO_X = 14.0;   // lo que se recupera en la etapa 2 completa sin contar los valores (medido: 14.6)
        $LIBRE    = 2.0;    // mm libres al pie del anverso tras medir (≈3 mm hasta el filete del pie)
        // Con muchas ocupaciones, párrafo corrido sólo si una por renglón obligaría a pasar a la etapa 2
        $parrafo = $nOcup >= 5 && $estimar(9.8, false) + $extraFirmas > $HOLGURA + $AHORRO;
        $extra = $estimar(9.8, $parrafo) + $extraFirmas;
        if ($extra <= $HOLGURA) {
            [$s, $altoFirma] = [0.0, $ALTO_MIN + $HOLGURA - $extra];
        } elseif ($extra <= $HOLGURA + $AHORRO) {
            [$s, $altoFirma] = [($extra - $HOLGURA) / $AHORRO, $ALTO_MIN];
        } else {
            for ($kx = 0.1; $kx < 1.0; $kx += 0.1) {
                if ($estimar(9.8 - 1.4 * $kx, $parrafo) + $extraFirmas - $AHORRO_X * $kx <= $HOLGURA + $AHORRO) break;
            }
            [$s, $altoFirma] = [1 + min(1.0, $kx), $ALTO_MIN];
        }
        // Medición real: espacio libre (mm) al pie del anverso con esta configuración; negativo si se pasa
        // de hoja; null sin medidor. Usa la MISMA configuración y el mismo modo (CSS + body) que htmlToPdfMpdf.
        $medir = function (string $html) use ($cfgMpdf, $usarMedidor): ?float {
            if (!$usarMedidor || !$cfgMpdf) return null;
            $prev = ini_get('pcre.backtrack_limit');
            try {
                ini_set('pcre.backtrack_limit', '200000000');
                $m = new \Mpdf\Mpdf($cfgMpdf);
                $m->SetHTMLFooter('');
                $css  = preg_match('/<style[^>]*>(.*?)<\/style>/is', $html, $mm) ? $mm[1] : '';
                $body = preg_match('/<body[^>]*>(.*)<\/body>/is', $html, $mm) ? $mm[1] : $html;
                if ($css !== '') $m->WriteHTML($css, \Mpdf\HTMLParserMode::HEADER_CSS);
                $m->WriteHTML($body, \Mpdf\HTMLParserMode::HTML_BODY);
                $altoUtil = $m->h - $m->tMargin - $m->bMargin;
                $libre = $m->page > 1 ? -(($m->page - 2) * $altoUtil + ($m->y - $m->tMargin)) : ($m->h - $m->bMargin) - $m->y;
                unset($m);
                return $libre;
            } catch (\Throwable $e) {
                return null;
            } finally {
                ini_set('pcre.backtrack_limit', (string)$prev);
            }
        };

        // ── Cajas de caracteres ─────────────────────────────────────────────
        $box = 'width:5.2mm;height:6mm;border:.3mm solid #134074;background:#ffffff;text-align:center;'
             . 'vertical-align:middle;font-family:avbainter;font-weight:bold;font-size:9.6pt;color:#0B2545;padding:0;';
        // Separador del RFC: un guion real (U+002D) — se copia/pega como «-» — trazado como barra entre grupos
        $sep = 'width:3.6mm;text-align:center;vertical-align:middle;font-family:avbainter;font-weight:bold;font-size:12pt;'
             . 'color:#134074;padding:0;border:none;';
        $boxes = function (string $str, int $pad = 0, bool $guiones = false) use ($box, $sep, $esc): string {
            if ($pad) $str = str_pad($str, $pad);
            $out = '';
            foreach (str_split($str) as $c) {
                if ($guiones && $c === '-') { $out .= '<td style="' . $sep . '">-</td>'; continue; }
                $out .= '<td style="' . $box . '">' . ($c !== ' ' ? $esc($c) : '&nbsp;') . '</td>';
            }
            return $out;
        };
        $boxRow = fn(string $str, int $pad = 0, bool $g = false) =>
            '<table style="border-collapse:collapse;margin-top:1mm"><tr>' . $boxes($str, $pad, $g) . '</tr></table>';

        // Campo de fecha: prefijo (De / a) + Año(4) Mes(2) Día(2) con su rótulo encima
        $dateField = function (string $pre, ?string $ymd, bool $derecha = false) use ($boxes): string {
            [$y, $mo, $d] = ['    ', '  ', '  '];
            if ($ymd && preg_match('/(\d{4})-(\d{2})-(\d{2})/', $ymd, $mt)) {
                [$y, $mo, $d] = [$mt[1], $mt[2], $mt[3]];
            }
            $hdr = 'font-size:6.2pt;color:#45546a;text-align:center;padding:0 0 .6mm;font-weight:bold;letter-spacing:.3px';
            $gap = '<td style="width:2mm"></td>';
            return '<table style="border-collapse:collapse' . ($derecha ? ';margin-left:auto' : '') . '"><tr>'
                 .   '<td style="padding:0"></td>'
                 .   '<td colspan="4" style="' . $hdr . '">Año</td><td></td>'
                 .   '<td colspan="2" style="' . $hdr . '">Mes</td><td></td>'
                 .   '<td colspan="2" style="' . $hdr . '">Día</td>'
                 . '</tr><tr>'
                 .   '<td style="font-family:avbamont;font-weight:bold;font-size:9.6pt;color:#0B2545;padding:0 2.2mm 0 0;'
                 .     'vertical-align:middle;text-align:right;width:6mm">' . $pre . '</td>'
                 .   $boxes($y) . $gap . $boxes($mo) . $gap . $boxes($d)
                 . '</tr></table>';
        };

        $curpPad = str_pad($curp, 18);
        // RFC: con patrón válido, en sus grupos (3|4)-6-3 con guion; vacío, la retícula 4-6-3 para
        // llenarse a mano; fuera de patrón, sus caracteres tal cual completando 13 cajas (máx. de un RFC).
        $rfcCajas = $rfcRaw === '' ? '    -      -   ' : ($rfcFmt !== $rfcRaw ? $rfcFmt : str_pad($rfcRaw, 13));

        // Pie fijo (razón social, contacto y marca de cara)
        $pie = function (string $nombre, string $cara): string {
            return '<htmlpagefooter name="' . $nombre . '"><table class="pie"><tr>'
                 . '<td class="pie-t"><b>AVBA Inspections, Certifications and Maintenance S.A.S. de C.V.</b><br>'
                 . '899-137-3127 / 55-2285-1209 / 951-608-6188 &nbsp;·&nbsp; certificaciones@avba.com.mx &nbsp;·&nbsp; www.avba.com.mx</td>'
                 . '<td class="pie-m"><span class="pie-dc">DC-3</span>&nbsp; <span class="pie-cara">' . $cara . '</span></td>'
                 . '</tr></table></htmlpagefooter>';
        };

        // mPDF reduce <sup> al 55 % (≈3.7 pt) salvo con tamaño en línea: 6.2 pt, alzado 1.2 pt
        // (el alzado «super» completo agrandaría cada renglón ~1 mm)
        $sup = fn(string $n) => '<sup style="font-size:6.2pt;vertical-align:1.2pt">' . $n . '</sup>';
        $v   = function (string $s) use ($esc): string { $e = $esc($s); return $e !== '' ? $e : '&nbsp;'; };
        $sigFs = number_format($sigPt, 1, '.', '');
        $nomFirma = fn(string $n) =>
            '<table style="width:58mm;border-collapse:collapse;margin:0 auto;overflow:wrap"><tr><td class="nf" style="font-size:' . $sigFs . 'pt">'
            . $v($n) . '</td></tr></table>';
        // Duración: número entero en grande con su unidad; cualquier otro texto, a tamaño de valor
        $horasNum = preg_match('/^-?\d+$/', $horas) === 1;
        $hyCurso = $sinGuion($curso, 184.0);
        $hyNombre = $sinGuion($nombre, 184.0);
        $hyPuesto = $sinGuion($puesto, 184.0);
        $hyEmpresa = $sinGuion($empresa, 184.0);
        $hyArea = $sinGuion($area, 88.5);
        $hyAgente = $sinGuion($agente, 88.5);

        // Líneas estructurales (marcos de campos y de firmas, filetes del catálogo): gris azulado que
        // sobrevive a la fotocopia y al escaneo en B/N (luminancia ≈ 170; antes #d3dbe5, 1.37:1).
        $LINEA     = '#9eadbf';
        $LINEA_CAT = '#a9b7c7';

        // ── ANVERSO: se construye con la compactación s y se mide hasta que cabe ──────────
        [$sOk, $fOk, $sNo, $fNo] = [null, null, null, null];
        $final = false;
        for ($pase = 1; ; $pase++) {
            $kc = min(1.0, max(0.0, $s));
            $kx = min(1.0, max(0.0, $s - 1));
            $kz = min(1.0, max(0.0, $s - 2));
            $altoMin   = $ALTO_MIN - 2.6 * $kx;
            $altoFirma = max($altoMin, min(36.0, $altoFirma));
            $valPt  = round(9.8 - 1.4 * $kx - 1.2 * $kz, 2);
            $ocupFs = round($ocupBase * $valPt / 9.8, 2);
            [, $ocupacionHtml] = $ocupar($ocupFs, $parrafo);
            $mm  = fn(float $a, float $b) => number_format($a - ($a - $b) * $kc, 2, '.', '') . 'mm';   // interpola normal→compacto
            $xn  = fn(float $a, float $b) => round($a - ($a - $b) * $kx, 2);                            // etapa 2 (número)
            $xmm = fn(float $a, float $b) => number_format($xn($a, $b), 2, '.', '') . 'mm';
            $xpt = fn(float $a, float $b) => number_format($xn($a, $b), 2, '.', '') . 'pt';
            $insPt = number_format(6.8 - 0.2 * $kc - 0.4 * $kx, 2, '.', '');   // nunca < 6.2 pt (la etapa 3 sólo achica los valores)

            // ── Piezas gráficas ─────────────────────────────────────────────
            $logoHdr = $logoB64
                ? '<img src="' . $logoB64 . '" style="height:' . $xn(12, 9.5) . 'mm;width:auto" alt="AVBA">'
                : '<span style="font-family:avbamont;font-weight:bold;color:#0B2545;font-size:12pt">AVBA</span>';
            $logoEmpHtml = $imgCaja($logoEmpB64, $rLogoEmp, $xn(12, 9.5), 30, 'Empresa');
            $firmaHtml   = $imgCaja($instrFirmaB64, $rFirma, $xn(12.5, 10.5), 32, 'Firma');
            $selloHtml   = $imgCaja($selloB64, $rSello, $xn(13.5, 11.3), 15.5, 'Sello');

            // Lado derecho de la banda: con QR, la validación en su ficha blanca; sin QR, el emisor
            // (mismo nombre que el encabezado) en una celda del mismo alto, así la banda no cambia.
            $lq = $xn(14, 11);                         // lado del QR (mm)
            $padQr = $xn(1.8, 1);
            if ($qrB64) {
                $heroDer = '<td class="hero-qt"><span class="hero-k">VALIDACIÓN</span><br>Escanee el código para<br>validar esta constancia</td>'
                         . '<td class="hero-qr" style="padding:' . $padQr . 'mm 4mm ' . $padQr . 'mm 0">'
                         . '<table style="border-collapse:collapse;margin-left:auto"><tr>'
                         . '<td style="background:#ffffff;padding:1mm;border:none;border-bottom:.8mm solid #C9A84C">'
                         . '<img src="' . $qrB64 . '" style="width:' . $lq . 'mm;height:' . $lq . 'mm" alt="QR"></td></tr></table></td>';
            } else {
                $heroDer = '<td class="hero-qt" style="width:54mm;padding-right:5mm;height:' . number_format($lq + 2.8 + 2 * $padQr, 2, '.', '') . 'mm">'
                         . '<span class="hero-k">EMITIDA POR</span><br>AVBA Inspections<br>www.avba.com.mx</td>';
            }

            // Encabezado común a ambas caras
            $encabezado = function (string $titulo, string $sub) use ($logoHdr, $logoEmpHtml): string {
                $emp = $logoEmpHtml ? '<td class="h-cli">' . $logoEmpHtml . '</td>' : '';
                return '<table class="h"><tr>'
                     . '<td class="h-logo">' . $logoHdr . '</td>'
                     . '<td class="h-emp"><div class="h-emp-n">AVBA INSPECTIONS</div>'
                     .   '<div class="h-emp-s">Certifications and Maintenance S.A.S. de C.V.</div>'
                     .   '<div class="h-emp-a">Capacitación, adiestramiento y productividad</div></td>'
                     . $emp
                     . '<td class="h-doc"><div class="h-doc-t">' . $titulo . '</div><div class="h-doc-e">' . $sub . '</div></td>'
                     . '</tr></table><div class="regla"></div>';
            };

            // ── Estilo ──────────────────────────────────────────────────────
            // Contraste para impresión: grises de texto ≥ 6:1 sobre blanco y dorado de texto oscurecido a
            // #8A6A1C (5:1); el dorado de marca #C9A84C queda sólo para filetes y barras. Nada del anverso
            // baja de 6 pt. Texto calado sobre la banda: blanco, negritas y ≥ 7 pt (aguanta la fotocopia).
            $css = <<<CSS
    @page { margin: 8mm 12mm 15mm 12mm; margin-footer: 5mm; footer: html_pieAnverso; }
    @page reverso { margin: 8mm 12mm 15mm 12mm; margin-footer: 5mm; footer: html_pieReverso; }
    body { font-family: avbainter, dejavusans, sans-serif; font-size: 8.4pt; color: #1f2d3d; }
    /* Encabezado */
    .h { width:100%; border-collapse:collapse; }
    .h td { vertical-align:middle; padding:0; }
    .h-logo { width:26mm; }
    .h td.h-emp { border-left:1.2mm solid #C9A84C; padding-left:4mm; }
    .h-emp-n { font-family:avbamont; font-weight:bold; font-size:11pt; color:#0B2545; letter-spacing:.3px; }
    .h-emp-s { font-size:7.1pt; color:#45546a; margin-top:.3mm; }
    .h-emp-a { font-size:7.1pt; color:#8A6A1C; font-weight:bold; margin-top:.2mm; }
    .h-cli { width:34mm; text-align:center; }
    .h-doc { width:70mm; text-align:right; }
    .h-doc-t { font-family:avbamont; font-weight:bold; font-size:11pt; color:#0B2545; letter-spacing:.3px; }
    .h-doc-e { font-size:7.1pt; color:#45546a; font-style:italic; margin-top:.3mm; }
    .regla { height:0; border-top:.6mm solid #0B2545; margin:{$xmm(1.8, 1.2)} 0 {$xmm(2.4, 1.6)}; }
    /* Banda principal */
    .hero { width:100%; border-collapse:collapse; }
    .hero td { vertical-align:middle; background:#0B2545; }
    .hero-oro { height:0; border-top:1mm solid #C9A84C; margin:0; }
    .hero-txt { border-left:1.8mm solid #C9A84C; padding:{$xmm(3, 1.6)} 5mm; }
    .hero-t { font-family:avbamont; font-weight:bold; font-size:{$xpt(19, 15.5)}; color:#ffffff; letter-spacing:.6px; }
    .hero-s { font-family:avbamont; font-weight:normal; font-size:{$xpt(9.4, 8.6)}; color:#e3c97a; margin-top:.6mm; letter-spacing:.3px; }
    .hero-qt { width:33mm; text-align:right; font-size:7pt; font-weight:bold; color:#ffffff; line-height:1.4; padding:0 3mm 0 0; }
    .hero-k { color:#f3dd96; font-weight:bold; letter-spacing:.4px; }   /* oro claro: a 7 pt aguanta la fotocopia */
    .hero-qr { width:19mm; text-align:right; }
    /* Secciones */
    .sec { background:#0B2545; color:#ffffff; font-family:avbamont; font-weight:normal; font-size:8.4pt; letter-spacing:.7px;
           padding:{$mm(1.3, 1.05)} 3.6mm; border-left:1.8mm solid #C9A84C; margin-top:{$mm(2.3, 1.5)}; }
    .dt { width:100%; border-collapse:collapse; }
    .dt td.c { border:.3mm solid {$LINEA}; border-top:none; padding:{$mm(1.2, .75)} 3.4mm {$mm(1.4, .9)}; vertical-align:top; background:#ffffff; }
    .lbl { font-size:6.8pt; font-weight:bold; color:#45546a; }
    sup { color:#8A6A1C; font-weight:bold; }
    .val { font-size:{$valPt}pt; font-weight:bold; color:#0B2545; margin-top:.5mm; line-height:1.25; }
    .val-s { line-height:1.25; }
    .horas { font-family:avbamont; font-weight:bold; font-size:18pt; color:#0B2545; }
    .horas-u { font-family:avbamont; font-size:7.6pt; color:#8A6A1C; letter-spacing:1.2px; }
    /* Leyenda: dos renglones parejos (el texto mide 214 mm a 7.8 pt; renglón útil ≈ 116 mm) */
    .leyenda { margin-top:{$mm(2.3, 1.5)}; background:#f3f6fa; border-left:1.8mm solid #C9A84C; padding:{$mm(1.8, 1.2)} 37mm; font-size:7.8pt;
               color:#1f2d3d; text-align:center; line-height:1.4; }
    /* Firmas: rol arriba · espacio para firmar · línea · nombre · «Nombre y firma» */
    .fir { width:100%; border-collapse:collapse; margin-top:{$mm(2, 1.4)}; }
    .fir td.fh, .fir td.fi, .fir td.fn, .fir td.fc { text-align:center; border-left:.3mm solid {$LINEA}; border-right:.3mm solid {$LINEA}; padding:0 3mm; }
    .fir td.fh { border-top:.3mm solid {$LINEA}; vertical-align:bottom; padding-top:1.3mm; }   /* abajo: los tres rótulos en la misma línea base aunque dos lleven 4/ 5/ */
    .fir td.fi { vertical-align:bottom; padding-top:.3mm; }
    .fir td.fn { vertical-align:top; padding-top:.6mm; }
    .fir td.fc { border-bottom:.3mm solid {$LINEA}; vertical-align:top; padding-top:.5mm; padding-bottom:1.5mm;
                 font-size:6.2pt; color:#505f74; letter-spacing:.2px; }
    .fir td.nf { border:none; border-top:.35mm solid #0B2545; padding:1mm 0 0; font-family:avbamont; color:#0B2545; line-height:1.25; text-align:center; }
    .rol { font-size:7pt; color:#2f3f55; font-weight:bold; }
    /* Instrucciones */
    .ins-h { font-family:avbamont; font-weight:bold; font-size:8.4pt; color:#0B2545; letter-spacing:.7px; border-bottom:.5mm solid #C9A84C;
             padding-bottom:.7mm; margin-top:{$mm(2.4, 1.6)}; }
    .ins { width:100%; border-collapse:collapse; margin-top:1mm; }
    .ins td { font-size:{$insPt}pt; line-height:1.3; color:#2b3d52; vertical-align:top; padding:{$mm(.25, .1)} 0; }
    .ins td.n { width:6mm; font-weight:bold; color:#8A6A1C; }
    /* Pie */
    .pie { width:100%; border-collapse:collapse; }
    .pie td { vertical-align:middle; }
    .pie-t { border-top:.4mm solid #C9A84C; padding-top:1.4mm; font-size:6.7pt; color:#45546a; line-height:1.45; }
    .pie-t b { color:#0B2545; }
    .pie-m { width:36mm; text-align:center; background:#0B2545; border-top:.4mm solid #C9A84C; padding:1.8mm 0; }
    .pie-dc { font-family:avbamont; font-weight:bold; font-size:10pt; color:#ffffff; letter-spacing:.5px; }
    .pie-cara { font-family:avbamont; font-size:8pt; color:#e3c97a; letter-spacing:1px; }
    /* Reverso */
    .rv-sec { background:#0B2545; color:#ffffff; font-family:avbamont; font-size:8.4pt; letter-spacing:.5px; line-height:1.3;
              padding:1.8mm 3.6mm; border-left:1.8mm solid #C9A84C; margin-top:3.2mm; }
    .cat { width:100%; border-collapse:collapse; }
    .cat th { background:#134074; color:#ffffff; font-size:6.1pt; font-weight:bold; letter-spacing:.4px; text-align:left; padding:1.4mm 2.2mm; vertical-align:middle; }
    .cat td { font-size:7.9pt; padding:.7mm 2.2mm; border-bottom:.25mm solid {$LINEA_CAT}; vertical-align:top; color:#1f2d3d; }
    .cat td.k { color:#45546a; }
    .cat td.ka, .cat td.a { font-weight:bold; color:#0B2545; background:#eef2f8; }   /* tinte = fila de ÁREA del CNO */
    .cat td.kt { font-weight:bold; color:#0B2545; }                                  /* clave de área temática: sin tinte */
    .cat td.gap, .cat th.gap { width:2%; border:none; background:#ffffff; }
    .tm td { font-size:8pt; padding:1.8mm 2.2mm; }   /* reverso: deja 3 mm libres al pie (antes 0.75 mm) */
    CSS;

            // Duración / Periodo: los dígitos de «16» sobre la misma línea base que los de las cajas de
            // fecha (medido: 152.1 mm ambos; el relleno de 2.3 mm no agranda la fila); las dos fechas
            // repartidas a lo ancho (De a la izquierda, a a la derecha, al ras del margen de la celda).
            $horasHtml = $horasNum || $horas === ''
                ? '<table style="border-collapse:collapse"><tr><td style="padding:2.3mm 0 0 0;line-height:1">'   // mPDF ignora margin en un div dentro de celda
                  . '<span class="horas">' . $v($horas) . '</span>&nbsp; <span class="horas-u">HORAS</span></td></tr></table>'
                : '<div class="val"' . $sinGuion($horas, 50.5) . '>' . $v($horas) . '</div>';

            $anverso = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><style>' . $css . '</style></head><body>'
                . $pie('pieAnverso', 'ANVERSO')
                . $pie('pieReverso', 'REVERSO')
                . $encabezado('CONSTANCIA DC-3', 'Formato oficial · STPS')
                . '<table class="hero"><tr><td class="hero-txt">'
                .   '<div class="hero-t">FORMATO DC-3</div>'
                .   '<div class="hero-s">CONSTANCIA DE COMPETENCIAS O DE HABILIDADES LABORALES</div>'
                . '</td>' . $heroDer . '</tr></table><div class="hero-oro"></div>'

                // Datos del trabajador
                . '<div class="sec">DATOS DEL TRABAJADOR</div>'
                . '<table class="dt" style="overflow:wrap">'
                .   '<tr><td class="c" colspan="2"><div class="lbl">Nombre (Anotar apellido paterno, apellido materno y nombre(s))</div>'
                .     '<div class="val"' . $hyNombre . '>' . $v($nombre) . '</div></td></tr>'
                .   '<tr><td class="c" style="width:54%"><div class="lbl">Clave Única de Registro de Población</div>' . $boxRow($curpPad) . '</td>'
                .     '<td class="c"><div class="lbl">Ocupación específica (Catálogo Nacional de Ocupaciones) ' . $sup('1/') . '</div>'
                .     ($ocupacionHtml !== '' ? $ocupacionHtml : '<div class="val">&nbsp;</div>') . '</td></tr>'
                .   '<tr><td class="c" colspan="2"><div class="lbl">Puesto*</div><div class="val"' . $hyPuesto . '>' . $v($puesto) . '</div></td></tr>'
                . '</table>'

                // Datos de la empresa
                . '<div class="sec">DATOS DE LA EMPRESA</div>'
                . '<table class="dt" style="overflow:wrap">'
                .   '<tr><td class="c"><div class="lbl">Nombre o razón social (En caso de persona física, anotar apellido paterno, apellido materno y nombre(s))</div>'
                .     '<div class="val"' . $hyEmpresa . '>' . $v($empresa) . '</div></td></tr>'
                .   '<tr><td class="c"><div class="lbl">Registro Federal de Contribuyentes con homoclave (SHCP)</div>' . $boxRow($rfcCajas, 0, true) . '</td></tr>'
                . '</table>'

                // Datos del programa
                . '<div class="sec">DATOS DEL PROGRAMA DE CAPACITACIÓN, ADIESTRAMIENTO Y PRODUCTIVIDAD</div>'
                . '<table class="dt" style="overflow:wrap">'
                .   '<tr><td class="c" colspan="2"><div class="lbl">Nombre del curso</div><div class="val"' . $hyCurso . '>' . $v($curso) . '</div></td></tr>'
                .   '<tr><td class="c" style="width:30%"><div class="lbl">Duración en horas</div>' . $horasHtml . '</td>'
                .     '<td class="c"><div class="lbl">Periodo de ejecución</div>'
                .       '<table style="border-collapse:collapse;margin-top:.2mm;width:100%"><tr>'
                .         '<td style="padding:0;vertical-align:bottom;text-align:left">' . $dateField('De', $fechaYmd) . '</td>'
                .         '<td style="padding:0;vertical-align:bottom;text-align:right">' . $dateField('a', $fechaFinYmd, true) . '</td>'
                .       '</tr></table></td></tr>'
                . '</table>'
                . '<table class="dt" style="overflow:wrap">'
                .   '<tr><td class="c" style="width:50%"><div class="lbl">Área temática del curso ' . $sup('2/') . '</div><div class="val"' . $hyArea . '>' . $v($area) . '</div></td>'
                .     '<td class="c"><div class="lbl">Nombre del agente capacitador o STPS ' . $sup('3/') . '</div><div class="val"' . $hyAgente . '>' . $v($agente) . '</div></td></tr>'
                . '</table>'

                // Leyenda + firmas
                . '<div class="leyenda">Los datos se asientan en esta constancia bajo protesta de decir verdad, apercibidos de la responsabilidad en que incurre todo aquel que no se conduce con verdad.</div>'
                . '<table class="fir">'
                .   '<tr>'
                .     '<td class="fh" style="width:33.4%"><span class="rol">Instructor o tutor</span></td>'
                .     '<td class="fh" style="width:33.3%"><span class="rol">Patrón o representante legal ' . $sup('4/') . '</span></td>'
                .     '<td class="fh" style="width:33.3%"><span class="rol">Representante de los trabajadores ' . $sup('5/') . '</span></td>'
                .   '</tr><tr>'
                .     '<td class="fi" style="height:' . number_format($altoFirma, 2, '.', '') . 'mm">'
                .       '<table style="border-collapse:collapse;margin:0 auto"><tr>'
                .       '<td style="vertical-align:bottom;padding:0 1mm;border:none">' . $firmaHtml . '</td>'
                .       '<td style="vertical-align:bottom;padding:0 1mm;border:none">' . $selloHtml . '</td></tr></table></td>'
                .     '<td class="fi"></td><td class="fi"></td>'
                .   '</tr><tr>'
                .     '<td class="fn">' . $nomFirma($instrDisplay) . '</td>'
                .     '<td class="fn">' . $nomFirma($patron) . '</td>'
                .     '<td class="fn">' . $nomFirma($repTrab) . '</td>'
                .   '</tr><tr>'
                .     '<td class="fc">Nombre y firma</td><td class="fc">Nombre y firma</td><td class="fc">Nombre y firma</td>'
                .   '</tr>'
                . '</table>'

                // Instrucciones
                . '<div class="ins-h">INSTRUCCIONES</div>'
                . '<table class="ins">'
                .   '<tr><td class="n">-</td><td>Llenar a máquina o con letra de molde.</td></tr>'
                .   '<tr><td class="n">-</td><td>Deberá entregarse al trabajador dentro de los veinte días hábiles siguientes al término del curso de capacitación aprobado.</td></tr>'
                .   '<tr><td class="n">1/</td><td>Las áreas y subáreas ocupacionales del Catálogo Nacional de Ocupaciones se encuentran disponibles en el reverso de este formato y en la página www.stps.gob.mx</td></tr>'
                .   '<tr><td class="n">2/</td><td>Las áreas temáticas de los cursos se encuentran disponibles en el reverso de este formato y en la página www.stps.gob.mx</td></tr>'
                .   '<tr><td class="n">3/</td><td>Cursos impartidos por el área competente de la Secretaría del Trabajo y Previsión Social.</td></tr>'
                .   '<tr><td class="n">4/</td><td>Para empresas con menos de 51 trabajadores. Para empresas con más de 50 trabajadores firmaría el representante del patrón ante la Comisión mixta de capacitación, adiestramiento y productividad.</td></tr>'
                .   '<tr><td class="n">5/</td><td>Solo para empresas con más de 50 trabajadores.</td></tr>'
                .   '<tr><td class="n">*</td><td>Dato no obligatorio.</td></tr>'
                . '</table>';

            if ($final) break;
            // Medir. f = libre con el renglón de firmas al mínimo (el alto de ese renglón suma lineal).
            $libre = $medir($anverso . '</body></html>');
            if ($libre === null) break;                               // sin medidor: queda lo estimado
            $f = $libre + ($altoFirma - $altoMin);
            if ($f >= $LIBRE) { [$sOk, $fOk] = [$s, $f]; } else { [$sNo, $fNo] = [$s, $f]; }
            $listo = $sOk !== null && ($sOk <= 0 || $fOk - $LIBRE <= 1.0 || ($sNo !== null && $sOk - $sNo <= 0.04) || $pase >= 7);
            if ($listo || ($sOk === null && ($sNo >= 3.0 || $pase >= 7))) {
                if ($sOk === null) error_log('[DC-3] El anverso no cabe en una hoja ni con la compactación máxima.');
                [$sFin, $fFin] = $sOk !== null ? [$sOk, $fOk] : [3.0, $fNo];
                $altoFin = max(0.0, $ALTO_MIN - 2.6 * min(1.0, max(0.0, $sFin - 1))) + max(0.0, $fFin - $LIBRE);
                if (abs($sFin - $s) < 1e-9 && abs(min(36.0, $altoFin) - $altoFirma) < 0.005) break;   // lo construido ya es lo final
                [$s, $altoFirma, $final] = [$sFin, $altoFin, true];
                continue;
            }
            // Siguiente intento: interpolación dentro del intervalo [no cabe, cabe]; si aún no hay
            // intervalo, un paso según lo que se recupera por unidad de s en cada etapa.
            $pend = fn(float $x) => $x < 1 ? 20.0 : ($x < 2 ? 16.0 : 10.0);
            if ($sOk !== null && $sNo !== null) {
                $t = ($LIBRE - $fNo) / max(1e-6, $fOk - $fNo);
                $s = $sNo + min(0.85, max(0.15, $t)) * ($sOk - $sNo);
            } elseif ($sOk !== null) {
                $s = max(0.0, $s - ($f - $LIBRE - 0.5) / $pend($s));
            } else {
                $s = min(3.0, $s + ($LIBRE - $f) / $pend($s) + 0.03);
            }
        }

        // ── REVERSO (catálogos) ─────────────────────────────────────────────
        $cnoLeft = [
            ['01','Cultivo, crianza y aprovechamiento'],['01.1','Agricultura y silvicultura'],
            ['01.2','Ganadería'],['01.3','Pesca y acuacultura'],
            ['02','Extracción y suministro'],['02.1','Exploración'],['02.2','Extracción'],
            ['02.3','Refinación y beneficio'],['02.4','Provisión de energía'],['02.5','Provisión de agua'],
            ['03','Construcción'],['03.1','Planeación y dirección de obras'],
            ['03.2','Edificación y urbanización'],['03.3','Acabado'],
            ['03.4','Instalación y mantenimiento'],
            ['04','Tecnología'],['04.1','Mecánica'],['04.2','Electricidad'],['04.3','Electrónica'],
            ['04.4','Informática'],['04.5','Telecomunicaciones'],['04.6','Procesos industriales'],
            ['05','Procesamiento y fabricación'],['05.1','Minerales no metálicos'],['05.2','Metales'],
            ['05.3','Alimentos y bebidas'],['05.4','Textiles y prendas de vestir'],
            ['05.5','Materia orgánica'],['05.6','Productos químicos'],
            ['05.7','Productos metálicos y de hule y plástico'],
            ['05.8','Productos eléctricos y electrónicos'],['05.9','Productos impresos'],
        ];
        $cnoRight = [
            ['06','Transporte'],['06.1','Ferroviario'],['06.2','Autotransporte'],['06.3','Aéreo'],
            ['06.4','Marítimo y fluvial'],['06.5','Servicios de apoyo'],
            ['07','Provisión de bienes y servicios'],['07.1','Comercio'],
            ['07.2','Alimentación y hospedaje'],['07.3','Turismo'],['07.4','Deporte y esparcimiento'],
            ['07.5','Servicios personales'],
            ['07.6','Reparación de artículos de uso doméstico y personal'],
            ['07.7','Limpieza'],['07.8','Servicio postal y mensajería'],
            ['08','Gestión y soporte administrativo'],['08.1','Bolsa, banca y seguros'],
            ['08.2','Administración'],['08.3','Servicios legales'],
            ['09','Salud y protección social'],['09.1','Servicios médicos'],
            ['09.2','Inspección sanitaria y del medio ambiente'],['09.3','Seguridad social'],
            ['09.4','Protección de bienes y/o personas'],
            ['10','Comunicación'],['10.1','Publicación'],['10.2','Radio, cine, televisión y teatro'],
            ['10.3','Interpretación artística'],['10.4','Traducción e interpretación lingüística'],
            ['10.5','Publicidad, propaganda y relaciones públicas'],
            ['11','Desarrollo y extensión del conocimiento'],
            ['11.1','Investigación'],['11.2','Enseñanza'],['11.3','Difusión cultural'],
        ];
        // Columnas (las mismas en ambas tablas, así las retículas coinciden): clave 12.5 % · denominación 32 %
        // · separación 2 % · clave 12.5 % · denominación 41 %. La clave cabe «ÁREA/SUBÁREA» a 6.1 pt (18.4 mm
        // + 4.4 de relleno ≤ 24 mm): mPDF ya no encoge la tabla del CNO.
        [$wK, $wL, $wR] = ['12.5%', '32%', '41%'];
        $celdas = function (array $v, string $wDen) use ($esc, $wK): string {
            if ($v[0] === '') return '<td style="width:' . $wK . '"></td><td style="width:' . $wDen . '"></td>';
            $esArea = strlen($v[0]) <= 2;   // área (01) en negritas y con tinte; subárea (01.1) normal
            return '<td class="' . ($esArea ? 'ka' : 'k') . '" style="width:' . $wK . '">' . $esc($v[0]) . '</td>'
                 . '<td class="' . ($esArea ? 'a' : '') . '" style="width:' . $wDen . '">' . $esc($v[1]) . '</td>';
        };
        $cnoRows = '';
        $maxRows = max(count($cnoLeft), count($cnoRight));
        for ($i = 0; $i < $maxRows; $i++) {
            $cnoRows .= '<tr>' . $celdas($cnoLeft[$i] ?? ['', ''], $wL) . '<td class="gap"></td>' . $celdas($cnoRight[$i] ?? ['', ''], $wR) . '</tr>';
        }

        $temas = [
            ['1000','Producción general'],['2000','Servicios'],
            ['3000','Administración, contabilidad y economía'],['4000','Comercialización'],
            ['5000','Mantenimiento y reparación'],['6000','Seguridad'],
            ['7000','Desarrollo personal y familiar'],
            ['8000','Uso de tecnologías de la información y comunicación'],
            ['9000','Participación social'],
        ];
        $temasRows = '';
        $half = (int)ceil(count($temas) / 2);
        for ($i = 0; $i < $half; $i++) {
            $l = $temas[$i];
            $r = $temas[$i + $half] ?? ['', ''];
            $temasRows .= '<tr>'
                . '<td class="kt" style="width:' . $wK . '">' . $esc($l[0]) . '</td><td style="width:' . $wL . '">' . $esc($l[1]) . '</td>'
                . '<td class="gap"></td>'
                . ($r[0] !== '' ? '<td class="kt" style="width:' . $wK . '">' . $esc($r[0]) . '</td><td style="width:' . $wR . '">' . $esc($r[1]) . '</td>'
                                : '<td style="width:' . $wK . '"></td><td style="width:' . $wR . '"></td>')
                . '</tr>';
        }

        $thCno = '<tr><th>CLAVE DEL ÁREA/SUBÁREA</th><th>DENOMINACIÓN</th><th class="gap"></th><th>CLAVE DEL ÁREA/SUBÁREA</th><th>DENOMINACIÓN</th></tr>';
        $thTem = '<tr><th>CLAVE DEL ÁREA</th><th>DENOMINACIÓN</th><th class="gap"></th><th>CLAVE DEL ÁREA</th><th>DENOMINACIÓN</th></tr>';

        $reverso = '<pagebreak page-selector="reverso" />'
            . $encabezado('FORMATO DC-3', 'Catálogos de referencia')
            . '<div class="rv-sec" style="margin-top:0">CLAVES Y DENOMINACIONES DE ÁREAS Y SUBÁREAS DEL CATÁLOGO NACIONAL DE OCUPACIONES</div>'
            . '<table class="cat">' . $thCno . $cnoRows . '</table>'
            . '<div class="rv-sec">CLAVES Y DENOMINACIONES DEL CATÁLOGO DE ÁREAS TEMÁTICAS DE LOS CURSOS</div>'
            . '<table class="cat tm">' . $thTem . $temasRows . '</table>';

        return $anverso . $reverso . '</body></html>';
    }
}
