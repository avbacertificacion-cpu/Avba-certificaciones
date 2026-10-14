<?php
/*
 * Almacenamiento de archivos.
 *
 * Dos formas de guardar, con la misma interfaz para el resto del sistema:
 *   - AlmacenLocal: en una carpeta del propio servidor. Para empezar o probar.
 *   - AlmacenS3:    en la nube, con el protocolo S3 que usan Backblaze B2,
 *                   Amazon S3, Wasabi, Cloudflare R2, etc.
 *
 * Con S3 los archivos no pasan por el hosting: el servidor genera una URL
 * firmada de corta duración y el navegador sube o descarga directo de la nube.
 * Así no aplica el límite de tamaño de subida del hosting y la llave secreta
 * nunca llega al navegador.
 */
declare(strict_types=1);

interface Almacen
{
    /** Datos para que el navegador suba el archivo: ['metodo','url','headers']. */
    public function urlSubida(string $clave, string $mime, int $archivoId): array;
    /** Tamaño en bytes si el objeto existe; null si no. */
    public function tamano(string $clave): ?int;
    /** Entrega el archivo al navegador (redirige a la nube o lo transmite). */
    public function entregar(string $clave, string $nombre, string $mime, bool $adjunto): never;
    public function borrar(string $clave): void;
    /** Lista objetos bajo un prefijo: ['objetos' => [[clave, tamano, fecha]], 'siguiente' => ?string]. */
    public function listar(string $prefijo, ?string $continuar = null): array;
    /** Pide sacar un objeto del almacenamiento en frío (no hace nada si no aplica). */
    public function restaurar(string $clave, int $dias, string $nivel): void;
    /** 'listo' (se puede descargar), 'en_proceso' o 'congelado'. */
    public function estadoRestauracion(string $clave): string;
    /** Guarda contenido directamente desde el servidor (pruebas y diagnóstico). */
    public function poner(string $clave, string $contenido, string $mime): void;
    /** Permite que el navegador suba directo al almacenamiento desde estos sitios (regla CORS). */
    public function configurarCors(array $origenes): void;
}

/** Tipos que el navegador puede mostrar sin riesgo; el resto se descarga. */
function tipoVisibleSeguro(string $mime): bool
{
    return (bool) preg_match('#^(image/(jpeg|png|gif|webp)|application/pdf|text/plain|video/mp4)$#', $mime);
}

function disposicion(string $nombre, bool $adjunto): string
{
    $ascii = preg_replace('/[^\x20-\x7E]|["\\\\]/', '_', $nombre) ?? 'archivo';
    return ($adjunto ? 'attachment' : 'inline') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($nombre);
}

// ════════════════════════════════════════════════════════════════════
final class AlmacenLocal implements Almacen
{
    private string $raiz;

    public function __construct(string $raiz)
    {
        $this->raiz = rtrim($raiz, '/');
    }

    private function ruta(string $clave): string
    {
        if ($clave === '' || str_contains($clave, '..') || str_starts_with($clave, '/')) {
            throw new RuntimeException('Clave de almacenamiento no válida');
        }
        return $this->raiz . '/' . $clave;
    }

    public function urlSubida(string $clave, string $mime, int $archivoId): array
    {
        return [
            'metodo'  => 'PUT',
            'url'     => 'api/?r=archivos/recibir&id=' . $archivoId . '&t=' . tokenSubida($archivoId, $clave),
            'headers' => ['Content-Type' => $mime],
        ];
    }

    /** Recibe el cuerpo de una petición PUT y lo guarda (sólo almacenamiento local). */
    public function recibir(string $clave, int $maxBytes): int
    {
        $destino = $this->ruta($clave);
        if (!is_dir(dirname($destino)) && !mkdir(dirname($destino), 0750, true) && !is_dir(dirname($destino))) {
            throw new RuntimeException('No se pudo crear la carpeta de almacenamiento');
        }
        $entrada = fopen('php://input', 'rb');
        $temporal = $destino . '.subiendo';
        $salida = fopen($temporal, 'wb');
        $total = 0;
        while (!feof($entrada)) {
            $bloque = fread($entrada, 1048576);
            if ($bloque === false) break;
            $total += strlen($bloque);
            if ($total > $maxBytes) {
                fclose($salida);
                unlink($temporal);
                fallar('El archivo es más grande de lo permitido.', 413);
            }
            fwrite($salida, $bloque);
        }
        fclose($entrada);
        fclose($salida);
        rename($temporal, $destino);
        return $total;
    }

    public function tamano(string $clave): ?int
    {
        $r = $this->ruta($clave);
        return is_file($r) ? (int) filesize($r) : null;
    }

    public function entregar(string $clave, string $nombre, string $mime, bool $adjunto): never
    {
        $r = $this->ruta($clave);
        if (!is_file($r)) fallar('El archivo no se encontró en el almacenamiento.', 404);
        if (!tipoVisibleSeguro($mime)) {
            $adjunto = true;
            $mime = 'application/octet-stream';
        }
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($r));
        header('Content-Disposition: ' . disposicion($nombre, $adjunto));
        header('X-Content-Type-Options: nosniff');
        header($adjunto ? 'Cache-Control: no-store' : 'Cache-Control: private, max-age=300');
        readfile($r);
        exit;
    }

    public function borrar(string $clave): void
    {
        $r = $this->ruta($clave);
        if (is_file($r)) unlink($r);
    }

    public function listar(string $prefijo, ?string $continuar = null): array
    {
        $base = $this->raiz . '/' . trim($prefijo, '/');
        $objetos = [];
        if (is_dir($base)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (!$f->isFile() || str_ends_with($f->getFilename(), '.subiendo')) continue;
                $clave = substr(str_replace('\\', '/', $f->getPathname()), strlen($this->raiz) + 1);
                $objetos[] = ['clave' => $clave, 'tamano' => $f->getSize(), 'fecha' => date('Y-m-d', $f->getMTime())];
            }
        }
        return ['objetos' => $objetos, 'siguiente' => null];
    }

    // El almacenamiento local no tiene "frío": la recuperación se simula en el sistema.
    public function restaurar(string $clave, int $dias, string $nivel): void {}

    public function configurarCors(array $origenes): void {} // las subidas llegan al mismo servidor

    public function estadoRestauracion(string $clave): string
    {
        return 'listo';
    }

    public function poner(string $clave, string $contenido, string $mime): void
    {
        $r = $this->ruta($clave);
        if (!is_dir(dirname($r))) mkdir(dirname($r), 0750, true);
        file_put_contents($r, $contenido);
    }
}

// ════════════════════════════════════════════════════════════════════
/** Firma de peticiones AWS Signature Version 4 (la que piden S3 y Backblaze B2). */
final class FirmaS3
{
    public function __construct(
        private string $llave,
        private string $secreto,
        private string $region,
        private string $servicio = 's3'
    ) {}

    public static function codificar(string $s): string
    {
        return str_replace('%7E', '~', rawurlencode($s));
    }

    public static function rutaCanonica(string $ruta): string
    {
        return implode('/', array_map([self::class, 'codificar'], explode('/', $ruta)));
    }

    public static function consultaCanonica(array $consulta): string
    {
        $pares = [];
        foreach ($consulta as $k => $v) $pares[self::codificar((string) $k)] = self::codificar((string) $v);
        ksort($pares, SORT_STRING);
        $partes = [];
        foreach ($pares as $k => $v) $partes[] = $k . '=' . $v;
        return implode('&', $partes);
    }

    private function claveFirma(string $dia): string
    {
        $k = hash_hmac('sha256', $dia, 'AWS4' . $this->secreto, true);
        $k = hash_hmac('sha256', $this->region, $k, true);
        $k = hash_hmac('sha256', $this->servicio, $k, true);
        return hash_hmac('sha256', 'aws4_request', $k, true);
    }

    /** @param array<string,string> $headers  ya en minúsculas */
    private function firmar(string $metodo, string $ruta, array $consulta, array $headers, string $hashCuerpo, string $fechaAmz): array
    {
        ksort($headers, SORT_STRING);
        $canonHeaders = '';
        foreach ($headers as $k => $v) $canonHeaders .= $k . ':' . trim(preg_replace('/\s+/', ' ', $v)) . "\n";
        $firmados = implode(';', array_keys($headers));
        $solicitud = implode("\n", [
            $metodo, self::rutaCanonica($ruta), self::consultaCanonica($consulta), $canonHeaders, $firmados, $hashCuerpo,
        ]);
        $dia = substr($fechaAmz, 0, 8);
        $alcance = "$dia/{$this->region}/{$this->servicio}/aws4_request";
        $aFirmar = "AWS4-HMAC-SHA256\n$fechaAmz\n$alcance\n" . hash('sha256', $solicitud);
        $firma = hash_hmac('sha256', $aFirmar, $this->claveFirma($dia));
        return [$firma, $firmados, $alcance];
    }

    /** URL prefirmada (la firma va en la consulta). */
    public function prefirmar(string $metodo, string $host, string $ruta, array $consulta, array $headersExtra, int $segundos, ?int $momento = null): string
    {
        $fechaAmz = gmdate('Ymd\THis\Z', $momento ?? time());
        $headers = ['host' => $host];
        foreach ($headersExtra as $k => $v) $headers[strtolower($k)] = (string) $v;
        ksort($headers, SORT_STRING);
        $dia = substr($fechaAmz, 0, 8);
        $consulta += [
            'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential'    => $this->llave . "/$dia/{$this->region}/{$this->servicio}/aws4_request",
            'X-Amz-Date'          => $fechaAmz,
            'X-Amz-Expires'       => (string) $segundos,
            'X-Amz-SignedHeaders' => implode(';', array_keys($headers)),
        ];
        [$firma] = $this->firmar($metodo, $ruta, $consulta, $headers, 'UNSIGNED-PAYLOAD', $fechaAmz);
        return 'https://' . $host . self::rutaCanonica($ruta) . '?' . self::consultaCanonica($consulta) . '&X-Amz-Signature=' . $firma;
    }

    /** Headers firmados para una petición hecha desde el servidor. */
    public function headersFirmados(string $metodo, string $host, string $ruta, array $consulta, array $headersExtra, string $cuerpo, ?int $momento = null): array
    {
        $fechaAmz = gmdate('Ymd\THis\Z', $momento ?? time());
        $hashCuerpo = hash('sha256', $cuerpo);
        $headers = ['host' => $host, 'x-amz-date' => $fechaAmz, 'x-amz-content-sha256' => $hashCuerpo];
        foreach ($headersExtra as $k => $v) $headers[strtolower($k)] = (string) $v;
        [$firma, $firmados, $alcance] = $this->firmar($metodo, $ruta, $consulta, $headers, $hashCuerpo, $fechaAmz);
        $headers['authorization'] = "AWS4-HMAC-SHA256 Credential={$this->llave}/$alcance, SignedHeaders=$firmados, Signature=$firma";
        return $headers;
    }
}

// ════════════════════════════════════════════════════════════════════
final class AlmacenS3 implements Almacen
{
    private FirmaS3 $firma;
    private string $host;
    private string $esquema;
    private string $bucket;
    private bool $rutaEstilo;
    private string $clase;

    public function __construct(array $c, string $clase = '')
    {
        foreach (['endpoint', 'region', 'bucket', 'key', 'secret'] as $k) {
            if (empty($c[$k])) throw new RuntimeException("Falta '$k' en la configuración del almacenamiento S3");
        }
        $url = parse_url((string) $c['endpoint']);
        $this->esquema = $url['scheme'] ?? 'https';
        $this->host = $url['host'] . (isset($url['port']) ? ':' . $url['port'] : '');
        $this->bucket = (string) $c['bucket'];
        $this->rutaEstilo = (bool) ($c['path_style'] ?? true);
        $this->clase = $clase;
        $this->firma = new FirmaS3((string) $c['key'], (string) $c['secret'], (string) $c['region']);
    }

    /** Host y ruta según el estilo del proveedor. */
    private function destino(string $clave): array
    {
        return $this->rutaEstilo
            ? [$this->host, '/' . $this->bucket . ($clave === '' ? '/' : '/' . $clave)]
            : [$this->bucket . '.' . $this->host, '/' . $clave];
    }

    private function urlPrefirmada(string $metodo, string $clave, array $consulta, array $headers, int $segundos): string
    {
        [$host, $ruta] = $this->destino($clave);
        $url = $this->firma->prefirmar($metodo, $host, $ruta, $consulta, $headers, $segundos);
        return $this->esquema === 'https' ? $url : preg_replace('#^https://#', $this->esquema . '://', $url);
    }

    public function urlSubida(string $clave, string $mime, int $archivoId): array
    {
        $headers = ['Content-Type' => $mime];
        if ($this->clase !== '') $headers['x-amz-storage-class'] = $this->clase;
        return [
            'metodo'  => 'PUT',
            'url'     => $this->urlPrefirmada('PUT', $clave, [], $headers, 3600),
            'headers' => $headers,
        ];
    }

    /** Petición firmada desde el servidor. Devuelve [estado, headers, cuerpo]. */
    private function peticion(string $metodo, string $clave, array $consulta = [], array $headers = [], string $cuerpo = ''): array
    {
        [$host, $ruta] = $this->destino($clave);
        $firmados = $this->firma->headersFirmados($metodo, $host, $ruta, $consulta, $headers, $cuerpo);
        $url = $this->esquema . '://' . $host . FirmaS3::rutaCanonica($ruta) . ($consulta ? '?' . FirmaS3::consultaCanonica($consulta) : '');
        $lineas = [];
        foreach ($firmados as $k => $v) if ($k !== 'host') $lineas[] = "$k: $v";
        $respHeaders = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $metodo,
            CURLOPT_HTTPHEADER     => $lineas,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_NOBODY         => $metodo === 'HEAD',
            CURLOPT_HEADERFUNCTION => function ($ch, $linea) use (&$respHeaders) {
                $p = strpos($linea, ':');
                if ($p !== false) $respHeaders[strtolower(trim(substr($linea, 0, $p)))] = trim(substr($linea, $p + 1));
                return strlen($linea);
            },
        ]);
        if ($cuerpo !== '') curl_setopt($ch, CURLOPT_POSTFIELDS, $cuerpo);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('No se pudo conectar con el almacenamiento: ' . $err);
        }
        $estado = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$estado, $respHeaders, (string) $resp];
    }

    private function errorS3(string $que, int $estado, string $cuerpo): RuntimeException
    {
        $codigo = preg_match('#<Code>(.*?)</Code>#', $cuerpo, $m) ? $m[1] : '';
        $detalle = preg_match('#<Message>(.*?)</Message>#s', $cuerpo, $m) ? ' — ' . mb_substr(trim($m[1]), 0, 200) : '';
        return new RuntimeException("Almacenamiento ($que): HTTP $estado $codigo$detalle");
    }

    public function tamano(string $clave): ?int
    {
        [$estado, $h] = $this->peticion('HEAD', $clave);
        if ($estado === 404) return null;
        if ($estado !== 200) throw $this->errorS3('consultar', $estado, '');
        return (int) ($h['content-length'] ?? 0);
    }

    public function entregar(string $clave, string $nombre, string $mime, bool $adjunto): never
    {
        if (!tipoVisibleSeguro($mime)) {
            $adjunto = true;
            $mime = 'application/octet-stream';
        }
        $url = $this->urlPrefirmada('GET', $clave, [
            'response-content-disposition' => disposicion($nombre, $adjunto),
            'response-content-type'        => $mime,
        ], [], 600);
        // Lo que se ve en pantalla (fotos de reportes) se guarda 5 min en el navegador
        // para que no vuelva a descargarse cada vez que cambia la pantalla.
        header($adjunto ? 'Cache-Control: no-store' : 'Cache-Control: private, max-age=300');
        header('Location: ' . $url, true, 302);
        exit;
    }

    public function borrar(string $clave): void
    {
        [$estado, , $cuerpo] = $this->peticion('DELETE', $clave);
        if ($estado !== 204 && $estado !== 200 && $estado !== 404) throw $this->errorS3('borrar', $estado, $cuerpo);
    }

    public function listar(string $prefijo, ?string $continuar = null): array
    {
        $consulta = ['list-type' => '2', 'prefix' => $prefijo, 'max-keys' => '1000'];
        if ($continuar) $consulta['continuation-token'] = $continuar;
        [$estado, , $cuerpo] = $this->peticion('GET', '', $consulta);
        if ($estado !== 200) throw $this->errorS3('listar', $estado, $cuerpo);
        $xml = simplexml_load_string($cuerpo);
        $objetos = [];
        foreach ($xml->Contents ?? [] as $c) {
            $clave = (string) $c->Key;
            if (str_ends_with($clave, '/')) continue; // "carpetas" vacías
            $objetos[] = ['clave' => $clave, 'tamano' => (int) $c->Size, 'fecha' => substr((string) $c->LastModified, 0, 10)];
        }
        $truncado = strtolower((string) ($xml->IsTruncated ?? 'false')) === 'true';
        return ['objetos' => $objetos, 'siguiente' => $truncado ? (string) $xml->NextContinuationToken : null];
    }

    public function restaurar(string $clave, int $dias, string $nivel): void
    {
        if ($this->clase === '') return; // no está en frío
        $tier = $nivel === 'economica' ? 'Bulk' : 'Standard';
        $cuerpo = '<RestoreRequest><Days>' . max(1, $dias) . '</Days><GlacierJobParameters><Tier>' . $tier . '</Tier></GlacierJobParameters></RestoreRequest>';
        [$estado, , $resp] = $this->peticion('POST', $clave, ['restore' => ''], [
            'content-type' => 'application/xml',
            'content-md5'  => base64_encode(md5($cuerpo, true)),
        ], $cuerpo);
        // 202 = iniciada · 200 = ya estaba restaurado · 409 = ya en proceso
        if (!in_array($estado, [200, 202, 409], true)) throw $this->errorS3('restaurar', $estado, $resp);
    }

    public function estadoRestauracion(string $clave): string
    {
        if ($this->clase === '') return 'listo';
        [$estado, $h] = $this->peticion('HEAD', $clave);
        if ($estado !== 200) throw $this->errorS3('estado', $estado, '');
        $r = $h['x-amz-restore'] ?? '';
        if ($r === '') return 'congelado';
        return str_contains($r, 'ongoing-request="true"') ? 'en_proceso' : 'listo';
    }

    /* Backblaze B2 y Amazon S3 aceptan la misma llamada (PutBucketCors).
       En B2 no hay que poner reglas CORS desde su página web: si el bucket ya
       tiene reglas "nativas", esta llamada se rechaza y hay que quitarlas. */
    public function configurarCors(array $origenes): void
    {
        $sitios = '';
        foreach ($origenes as $o) $sitios .= '<AllowedOrigin>' . htmlspecialchars($o, ENT_XML1) . '</AllowedOrigin>';
        $cuerpo = '<CORSConfiguration><CORSRule>' . $sitios
            . '<AllowedMethod>PUT</AllowedMethod><AllowedMethod>GET</AllowedMethod><AllowedMethod>HEAD</AllowedMethod>'
            . '<AllowedHeader>content-type</AllowedHeader><AllowedHeader>x-amz-storage-class</AllowedHeader>'
            . '<ExposeHeader>ETag</ExposeHeader><MaxAgeSeconds>3600</MaxAgeSeconds></CORSRule></CORSConfiguration>';
        [$estado, , $resp] = $this->peticion('PUT', '', ['cors' => ''], [
            'content-type' => 'application/xml',
            'content-md5'  => base64_encode(md5($cuerpo, true)),
        ], $cuerpo);
        if ($estado !== 200 && $estado !== 204) throw $this->errorS3('configurar CORS', $estado, $resp);
    }

    public function poner(string $clave, string $contenido, string $mime): void
    {
        $h = ['content-type' => $mime];
        if ($this->clase !== '') $h['x-amz-storage-class'] = $this->clase;
        [$estado, , $cuerpo] = $this->peticion('PUT', $clave, [], $h, $contenido);
        if ($estado !== 200) throw $this->errorS3('guardar', $estado, $cuerpo);
    }
}

// ── Fábricas ──────────────────────────────────────────────────────────
function almacenTrabajo(): Almacen
{
    static $a = null;
    return $a ??= crearAlmacen((array) conf('almacen', []), '');
}

function almacenArchivo(): Almacen
{
    static $a = null;
    return $a ??= crearAlmacen((array) conf('archivo_muerto', []), (string) conf('archivo_muerto.clase', ''));
}

function crearAlmacen(array $c, string $clase): Almacen
{
    if (($c['driver'] ?? 'local') === 's3') return new AlmacenS3((array) ($c['s3'] ?? []), $clase);
    return new AlmacenLocal((string) ($c['local_ruta'] ?? RAIZ . '/storage/archivos'));
}

/** Secreto propio de esta instalación (para tokens internos). */
function secretoInstalacion(): string
{
    $f = RAIZ . '/storage/.secreto';
    if (!is_file($f)) {
        file_put_contents($f, bin2hex(random_bytes(32)));
        @chmod($f, 0600);
    }
    return trim((string) file_get_contents($f));
}

function tokenSubida(int $archivoId, string $clave): string
{
    return hash_hmac('sha256', $archivoId . '|' . $clave, secretoInstalacion());
}
