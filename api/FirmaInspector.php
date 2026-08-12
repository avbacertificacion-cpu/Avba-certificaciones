<?php
/**
 * AVBA Certificaciones — Tratamiento de firmas de inspector para los PDF
 *
 * Vive aparte porque lo usan varios módulos (arneses, accesorios de izaje) y el
 * problema que resuelve costó encontrarlo: si cada módulo lleva su propia copia,
 * el arreglo se aplica en uno y se olvida en el otro.
 *
 * El punto clave está en `sobreBlanco()`. Las firmas llegan de dos maneras:
 * escaneadas sobre papel (JPG, opacas) o recortadas con fondo transparente
 * (PNG). En las segundas `imagecolorat` devuelve NEGRO en el fondo —el color que
 * hay debajo del canal alfa—, así que leer sólo el RGB tomaba todo el fondo por
 * trazo y la firma salía como un recuadro negro. Por eso lo primero que se hace
 * es componer contra blanco, y el resultado se guarda SIEMPRE en JPEG: un JPEG
 * no admite transparencia ni máscaras, de modo que mPDF no puede incrustarlo
 * como imagen negra + máscara aparte.
 */
class FirmaInspector {

    /**
     * Versión del tratamiento. Súbela al cambiar cómo se procesan las imágenes:
     * es lo que invalida las ya generadas, porque los archivos de origen no
     * cambian y la caché seguiría entregando las viejas.
     */
    public const VERSION = 7;

    /** Umbrales de luminancia al limpiar y realzar una firma escaneada. */
    private const LUM_PAPEL = 248;   // de aquí en adelante es hoja
    private const LUM_TINTA = 235;   // por debajo de aquí es trazo pleno
    private const LUM_META  = 140;   // a cuánto se lleva el tono más claro del trazo

    /** Raíz del proyecto, para resolver rutas relativas. */
    private static function raiz(): string { return __DIR__ . '/../'; }

    private static function dirDestino(): string {
        return (defined('UPLOAD_DIR') ? UPLOAD_DIR : self::raiz() . 'uploads/') . 'firmas_procesadas/';
    }

    /**
     * Ruta relativa de la firma registrada de un inspector.
     *
     * Se busca primero por cuenta y sólo después por nombre: los nombres se
     * escriben distinto y cambian, la cuenta no. Devuelve '' si no hay firma o
     * si el archivo ya no está en disco.
     */
    public static function rutaDe(PDO $pdo, string $usuario, string $nombre = ''): string {
        $rel = '';
        try {
            if ($usuario !== '') {
                $st = $pdo->prepare("SELECT firma_imagen FROM usuarios WHERE usuario = ? LIMIT 1");
                $st->execute([$usuario]);
                $rel = (string)($st->fetchColumn() ?: '');
            }
            if ($rel === '' && $nombre !== '') {
                $st = $pdo->prepare(
                    "SELECT firma_imagen FROM usuarios WHERE rol = 'INSPECTOR' AND nombre = ? LIMIT 1"
                );
                $st->execute([$nombre]);
                $rel = (string)($st->fetchColumn() ?: '');
            }
        } catch (\Throwable $e) { return ''; }

        $rel = ltrim($rel, '/');
        return ($rel !== '' && is_file(self::raiz() . $rel)) ? $rel : '';
    }

    /**
     * Deja la firma lista para el documento: resuelve la transparencia contra
     * blanco, quita el fondo del papel, le devuelve contraste y recorta el
     * margen sobrante.
     *
     * @return string Ruta relativa a la imagen procesada, o la original si no se
     *                pudo procesar (nunca devuelve algo que no exista).
     */
    public static function procesada(string $firmaRel): string {
        if ($firmaRel === '') return '';
        if (!function_exists('imagecreatetruecolor')) return $firmaRel;

        $fAbs = self::raiz() . $firmaRel;
        if (!is_file($fAbs)) return $firmaRel;

        $huella = substr(md5(self::VERSION . '|' . filemtime($fAbs) . filesize($fAbs)), 0, 12);
        $dir    = self::dirDestino();
        $rel    = 'uploads/firmas_procesadas/firma_' . $huella . '.jpg';
        if (is_file(self::raiz() . $rel)) return $rel;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return $firmaRel;

        try {
            $src = @imagecreatefromstring((string)file_get_contents($fAbs));
            if (!$src) return $firmaRel;

            // Lo PRIMERO es resolver la transparencia contra blanco (ver cabecera).
            $src = self::sobreBlanco($src);

            // El realce recorre la imagen píxel por píxel: una firma de varios
            // megapíxeles tardaría segundos sin aportar nada, porque en el
            // documento se imprime a unos 58 px de alto.
            $src = self::limitarLado($src, 900);
            $w = imagesx($src); $h = imagesy($src);

            $lum = fn(int $c): int => (int)round(
                0.299 * (($c >> 16) & 0xFF) + 0.587 * (($c >> 8) & 0xFF) + 0.114 * ($c & 0xFF)
            );

            // Tono más oscuro presente, para estirar desde el trazo real y no
            // desde un negro teórico que un escaneo claro nunca alcanza.
            $minLum = 255;
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    $l = $lum(imagecolorat($src, $x, $y));
                    if ($l < $minLum) $minLum = $l;
                }
            }
            $rango = max(1, self::LUM_TINTA - $minLum);

            // Lienzo opaco desde el inicio: nunca hay canal alfa que guardar.
            $out = imagecreatetruecolor($w, $h);
            imagealphablending($out, false);
            imagesavealpha($out, false);
            imagefilledrectangle($out, 0, 0, $w, $h, imagecolorallocate($out, 255, 255, 255));

            $x1 = $w; $y1 = $h; $x2 = -1; $y2 = -1;   // recuadro del trazo
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    $c = imagecolorat($src, $x, $y);
                    $l = $lum($c);
                    if ($l >= self::LUM_PAPEL) continue;          // papel: queda blanco

                    $r = ($c >> 16) & 0xFF; $g = ($c >> 8) & 0xFF; $b = $c & 0xFF;

                    if ($l >= self::LUM_TINTA) {
                        // Borde: se mezcla con el blanco según lo claro que sea,
                        // para que el trazo no salga dentado.
                        $m = ($l - self::LUM_TINTA) / (self::LUM_PAPEL - self::LUM_TINTA);
                        $r = (int)round($r + (255 - $r) * $m);
                        $g = (int)round($g + (255 - $g) * $m);
                        $b = (int)round($b + (255 - $b) * $m);
                    } else {
                        // Trazo: se estira el tono hacia lo oscuro conservando el
                        // matiz, escalando los tres canales por igual.
                        $t = min(1.0, max(0.0, ($l - $minLum) / $rango));
                        $f = ($t * self::LUM_META) / max(1, $l);
                        $r = min(255, (int)round($r * $f));
                        $g = min(255, (int)round($g * $f));
                        $b = min(255, (int)round($b * $f));
                        if ($x < $x1) $x1 = $x;  if ($x > $x2) $x2 = $x;
                        if ($y < $y1) $y1 = $y;  if ($y > $y2) $y2 = $y;
                    }
                    imagesetpixel($out, $x, $y, imagecolorallocate($out, $r, $g, $b));
                }
            }
            imagedestroy($src);

            // Recorte con un margen mínimo alrededor del trazo
            if ($x2 > $x1 && $y2 > $y1) {
                $m  = (int)round(max($x2 - $x1, $y2 - $y1) * 0.04);
                $rx = max(0, $x1 - $m); $ry = max(0, $y1 - $m);
                $rw = min($w - $rx, $x2 - $x1 + 1 + $m * 2);
                $rh = min($h - $ry, $y2 - $y1 + 1 + $m * 2);

                $rec = imagecreatetruecolor($rw, $rh);
                imagealphablending($rec, false);
                imagesavealpha($rec, false);
                imagefilledrectangle($rec, 0, 0, $rw, $rh, imagecolorallocate($rec, 255, 255, 255));
                imagecopy($rec, $out, 0, 0, $rx, $ry, $rw, $rh);
                imagedestroy($out);
                $out = $rec;
            }

            $ok = imagejpeg($out, $dir . basename($rel), 94);
            imagedestroy($out);
            return $ok ? $rel : $firmaRel;
        } catch (\Throwable $e) {
            error_log('[FirmaInspector] procesada: ' . $e->getMessage());
            return $firmaRel;
        }
    }

    /**
     * Aplana una imagen con transparencia sobre blanco, sin retocarla. Se usa
     * para el sello, que no debe realzarse pero sí perder el canal alfa.
     */
    public static function aplanada(string $rel): string {
        if ($rel === '' || !function_exists('imagecreatetruecolor')) return $rel;
        $abs = self::raiz() . $rel;
        if (!is_file($abs)) return $rel;

        $huella  = substr(md5(self::VERSION . '|' . $rel . filemtime($abs) . filesize($abs)), 0, 12);
        $dir     = self::dirDestino();
        $destino = 'uploads/firmas_procesadas/plano_' . $huella . '.jpg';
        if (is_file(self::raiz() . $destino)) return $destino;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return $rel;

        try {
            $src = @imagecreatefromstring((string)file_get_contents($abs));
            if (!$src) return $rel;
            $w = imagesx($src); $h = imagesy($src);

            $dst = imagecreatetruecolor($w, $h);
            imagealphablending($dst, false);
            imagesavealpha($dst, false);
            imagefilledrectangle($dst, 0, 0, $w, $h, imagecolorallocate($dst, 255, 255, 255));
            // Con blending activo, imagecopy mezcla el alfa del origen contra el
            // blanco: el resultado queda opaco y sin canal alfa que guardar.
            imagealphablending($dst, true);
            imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);

            $ok = imagejpeg($dst, $dir . basename($destino), 94);
            imagedestroy($dst); imagedestroy($src);
            return $ok ? $destino : $rel;
        } catch (\Throwable $e) {
            error_log('[FirmaInspector] aplanada: ' . $e->getMessage());
            return $rel;
        }
    }

    /** La firma ya procesada, en data URI, lista para incrustar en el HTML. */
    public static function dataUri(PDO $pdo, string $usuario, string $nombre = ''): string {
        $rel = self::procesada(self::rutaDe($pdo, $usuario, $nombre));
        if ($rel === '') return '';
        $abs = self::raiz() . $rel;
        if (!is_file($abs)) return '';
        $ext  = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        $mime = $ext === 'png' ? 'image/png' : 'image/jpeg';
        return 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($abs));
    }

    /**
     * Devuelve la imagen compuesta sobre blanco, resolviendo su transparencia.
     * Un píxel medio transparente se mezcla con el blanco en la proporción que
     * le corresponde, para que el borde del trazo no quede duro.
     */
    public static function sobreBlanco(\GdImage $img): \GdImage {
        $w = imagesx($img); $h = imagesy($img);
        $out = imagecreatetruecolor($w, $h);
        imagealphablending($out, false);
        imagesavealpha($out, false);
        imagefilledrectangle($out, 0, 0, $w, $h, imagecolorallocate($out, 255, 255, 255));

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($img, $x, $y);
                $a = ($c >> 24) & 0x7F;               // 0 opaco … 127 transparente
                if ($a >= 127) continue;              // del todo transparente: queda blanco

                $op = (127 - $a) / 127;               // opacidad real del píxel
                $r  = (int)round((($c >> 16) & 0xFF) * $op + 255 * (1 - $op));
                $g  = (int)round((($c >> 8)  & 0xFF) * $op + 255 * (1 - $op));
                $b  = (int)round(( $c        & 0xFF) * $op + 255 * (1 - $op));
                imagesetpixel($out, $x, $y, imagecolorallocate($out, $r, $g, $b));
            }
        }
        imagedestroy($img);
        return $out;
    }

    /** Reduce la imagen si excede el lado máximo, conservando la proporción. */
    public static function limitarLado(\GdImage $img, int $maxLado): \GdImage {
        $w = imagesx($img); $h = imagesy($img);
        $mayor = max($w, $h);
        if ($mayor <= $maxLado) return $img;

        $f  = $maxLado / $mayor;
        $nw = max(1, (int)round($w * $f));
        $nh = max(1, (int)round($h * $f));
        $out = imagecreatetruecolor($nw, $nh);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);
        return $out;
    }
}
