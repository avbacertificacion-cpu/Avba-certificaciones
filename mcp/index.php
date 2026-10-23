<?php
/**
 * Conector de correo (IMAP/SMTP) para Claude — servidor MCP remoto en un solo archivo.
 *
 * - Funciona en hosting compartido (Hostinger) con PHP 7.4 o superior.
 * - No necesita extensiones especiales (ni php-imap): habla IMAP y SMTP directamente.
 * - Claude lo usa como "conector personalizado" con la URL:
 *       https://TU-DOMINIO/mcp/index.php?k=TU_CLAVE_SECRETA
 * - Abre esa misma URL en el navegador para ver el diagnóstico de la conexión.
 *
 * NO necesitas editar este archivo. Toma el correo y la contraseña de config/config.php del sitio.
 * Para obtener tu URL: abre https://TU-DOMINIO/mcp/ en el navegador y escribe la contraseña del correo.
 */

declare(strict_types=1);

@ini_set('display_errors', '0');
@set_time_limit(90);
mb_internal_encoding('UTF-8');

set_error_handler(function ($no, $msg, $file, $line) {
    if (!(error_reporting() & $no)) {
        return false;
    }
    throw new ErrorException($msg, 0, $no, $file, $line);
});

const VERSION_CONECTOR = '1.0.0';
const MAX_TEXTO = 15000;

/**
 * Carga la configuración. Orden:
 *  1) mcp/config.php (si existe; uso independiente, fuera de un sitio).
 *  2) config/config.php del sitio (ya existe en el servidor y NO está en GitHub):
 *     reutiliza MAIL_USER, MAIL_PASS, MAIL_HOST, MAIL_PORT y MAIL_FROM_NAME.
 *     Opcionales ahí mismo: MCP_CORREO, MCP_CONTRASENA, MCP_CLAVE, MCP_IMAP_HOST, MCP_IMAP_PUERTO.
 * La clave del conector se deriva de la contraseña del correo, así nunca se guarda en el repositorio.
 */
function cargarConfig(): array
{
    $cfg = [
        'origen' => '',
        'clave_secreta' => '',
        'correo' => '',
        'contrasena' => '',
        'nombre_remitente' => '',
        'imap_host' => 'imap.hostinger.com',
        'imap_puerto' => 993,
        'imap_ssl' => true,
        'smtp_host' => 'smtp.hostinger.com',
        'smtp_puerto' => 465,
        'smtp_ssl' => true,
        'max_envios_por_hora' => 30,
        'zona_horaria' => 'America/Monterrey',
    ];
    $local = __DIR__ . '/config.php';
    if (is_file($local)) {
        $cfg = array_merge($cfg, (array) require $local);
        $cfg['origen'] = 'mcp/config.php';
        return $cfg;
    }
    $sitio = dirname(__DIR__) . '/config/config.php';
    if (is_file($sitio)) {
        ob_start();
        try {
            require_once $sitio;
        } finally {
            ob_end_clean();
        }
        $cfg['origen'] = 'config/config.php del sitio';
    }
    $c = function (string $n, $def = '') {
        return defined($n) ? constant($n) : $def;
    };
    $cfg['correo'] = (string) $c('MCP_CORREO', $c('MAIL_USER'));
    $cfg['contrasena'] = (string) $c('MCP_CONTRASENA', $c('MAIL_PASS'));
    $cfg['nombre_remitente'] = (string) $c('MCP_NOMBRE', $c('MAIL_FROM_NAME'));
    $cfg['smtp_host'] = (string) $c('MAIL_HOST', $cfg['smtp_host']);
    $cfg['smtp_puerto'] = (int) $c('MAIL_PORT', $cfg['smtp_puerto']);
    $cfg['smtp_ssl'] = $cfg['smtp_puerto'] === 465;
    $cfg['imap_host'] = (string) $c('MCP_IMAP_HOST', preg_replace('/^smtp\./i', 'imap.', $cfg['smtp_host']));
    $cfg['imap_puerto'] = (int) $c('MCP_IMAP_PUERTO', 993);
    $cfg['imap_ssl'] = (bool) $c('MCP_IMAP_SSL', $cfg['imap_puerto'] === 993);
    $cfg['smtp_starttls'] = (bool) $c('MCP_SMTP_STARTTLS', !$cfg['smtp_ssl']);
    $cfg['clave_secreta'] = (string) $c('MCP_CLAVE', '');
    if ($cfg['clave_secreta'] === '' && $cfg['contrasena'] !== '') {
        $cfg['clave_secreta'] = substr(hash_hmac('sha256', 'conector-correo-claude-v1|' . strtolower($cfg['correo']), $cfg['contrasena']), 0, 40);
    }
    return $cfg;
}

$CFG = cargarConfig();
date_default_timezone_set($CFG['zona_horaria'] ?? 'UTC');

// =====================================================================
//  Utilidades IMAP
// =====================================================================

final class Lit
{
    public $data;
    public function __construct(string $data)
    {
        $this->data = $data;
    }
}

final class Imap
{
    private $s;
    private $n = 0;
    private $seleccionada = null;
    private $carpetas = null;
    public $caps = [];

    public function __construct(string $host, int $port, bool $ssl, int $timeout = 25)
    {
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
        $addr = ($ssl ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $errno = 0;
        $errstr = '';
        $this->s = @stream_socket_client($addr, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->s) {
            throw new RuntimeException("No pude conectar al servidor IMAP $host:$port ($errstr)");
        }
        stream_set_timeout($this->s, $timeout);
        $saludo = $this->leerLinea();
        if (strncmp($saludo, '* OK', 4) !== 0 && strncmp($saludo, '* PREAUTH', 9) !== 0) {
            throw new RuntimeException('El servidor IMAP no respondió como se esperaba: ' . trim($saludo));
        }
    }

    public static function str(string $s)
    {
        if ($s === '') {
            return '""';
        }
        if (preg_match('/^[\x20-\x7e]*$/', $s)) {
            return '"' . addcslashes($s, '"\\') . '"';
        }
        return new Lit($s);
    }

    private function escribir(string $d): void
    {
        $total = strlen($d);
        $hecho = 0;
        while ($hecho < $total) {
            $w = @fwrite($this->s, substr($d, $hecho));
            if ($w === false || $w === 0) {
                throw new RuntimeException('Se perdió la conexión con el servidor IMAP.');
            }
            $hecho += $w;
        }
    }

    private function leerLinea(): string
    {
        $l = @fgets($this->s);
        if ($l === false) {
            throw new RuntimeException('El servidor IMAP cerró la conexión o tardó demasiado.');
        }
        return $l;
    }

    private function leerBytes(int $n): string
    {
        $d = '';
        while (strlen($d) < $n) {
            $c = @fread($this->s, min(65536, $n - strlen($d)));
            if ($c === false || $c === '') {
                $meta = stream_get_meta_data($this->s);
                if ($meta['timed_out'] || feof($this->s)) {
                    throw new RuntimeException('Se cortó la descarga del correo.');
                }
                continue;
            }
            $d .= $c;
        }
        return $d;
    }

    /** Lee una respuesta completa; los literales {n} se guardan aparte y se marcan como \x01N\x01 */
    private function leerRespuesta(): array
    {
        $texto = '';
        $lits = [];
        while (true) {
            $l = $this->leerLinea();
            if (preg_match('/\{(\d+)\+?\}\r?\n$/', $l, $m)) {
                $texto .= substr($l, 0, -strlen($m[0])) . "\x01" . count($lits) . "\x01";
                $lits[] = $this->leerBytes((int) $m[1]);
                continue;
            }
            $texto .= rtrim($l, "\r\n");
            return [$texto, $lits];
        }
    }

    public function cmd(array $partes): array
    {
        $tag = 'A' . (++$this->n);
        $buf = $tag;
        foreach ($partes as $p) {
            if ($p instanceof Lit) {
                $this->escribir($buf . ' {' . strlen($p->data) . "}\r\n");
                while (true) {
                    [$t] = $this->leerRespuesta();
                    if ($t !== '' && $t[0] === '+') {
                        break;
                    }
                    if (strncmp($t, $tag . ' ', strlen($tag) + 1) === 0) {
                        throw new RuntimeException('El servidor de correo rechazó la orden: ' . substr($t, strlen($tag) + 1));
                    }
                }
                $this->escribir($p->data);
                $buf = '';
            } else {
                $buf .= ' ' . $p;
            }
        }
        $this->escribir($buf . "\r\n");

        $out = [];
        while (true) {
            [$t, $l] = $this->leerRespuesta();
            if (strncmp($t, $tag . ' ', strlen($tag) + 1) === 0) {
                $resto = substr($t, strlen($tag) + 1);
                if (strncasecmp($resto, 'OK', 2) !== 0) {
                    throw new RuntimeException('El servidor de correo respondió: ' . trim(preg_replace('/^(NO|BAD)\s*/i', '', $resto)));
                }
                return $out;
            }
            if ($t !== '' && $t[0] === '+') {
                continue;
            }
            $out[] = ['t' => $t, 'l' => $l];
        }
    }

    public function login(string $u, string $p): void
    {
        try {
            $this->cmd(['LOGIN', self::str($u), self::str($p)]);
        } catch (RuntimeException $e) {
            throw new RuntimeException('Usuario o contraseña de correo incorrectos (' . $e->getMessage() . ')');
        }
        foreach ($this->cmd(['CAPABILITY']) as $r) {
            if (stripos($r['t'], '* CAPABILITY') === 0) {
                $this->caps = array_map('strtoupper', explode(' ', substr($r['t'], 13)));
            }
        }
    }

    public function tiene(string $cap): bool
    {
        return in_array(strtoupper($cap), $this->caps, true);
    }

    public function carpetas(): array
    {
        if ($this->carpetas !== null) {
            return $this->carpetas;
        }
        $res = [];
        foreach ($this->cmd(['LIST', '""', '"*"']) as $r) {
            if (!preg_match('/^\* LIST \(([^)]*)\) (NIL|"(?:[^"\\\\]|\\\\.)*") (.*)$/i', $r['t'], $m)) {
                continue;
            }
            $nombre = $m[3];
            if (preg_match('/^\x01(\d+)\x01$/', $nombre, $q)) {
                $nombre = $r['l'][(int) $q[1]];
            } elseif ($nombre !== '' && $nombre[0] === '"') {
                $nombre = preg_replace('/\\\\(.)/', '$1', substr($nombre, 1, -1));
            }
            $delim = strtoupper($m[2]) === 'NIL' ? '' : preg_replace('/\\\\(.)/', '$1', substr($m[2], 1, -1));
            $legible = dec7($nombre);
            $corto = $delim !== '' && strpos($legible, $delim) !== false ? substr($legible, strrpos($legible, $delim) + strlen($delim)) : $legible;
            $res[] = [
                'raw' => $nombre,
                'nombre' => $legible,
                'corto' => $corto,
                'flags' => $m[1],
                'seleccionable' => stripos($m[1], '\\Noselect') === false && stripos($m[1], '\\NonExistent') === false,
            ];
        }
        return $this->carpetas = $res;
    }

    public function seleccionar(string $raw, bool $soloLectura = true): void
    {
        $clave = ($soloLectura ? 'R:' : 'W:') . $raw;
        if ($this->seleccionada === $clave || ($soloLectura && $this->seleccionada === 'W:' . $raw)) {
            return;
        }
        $this->cmd([$soloLectura ? 'EXAMINE' : 'SELECT', self::str($raw)]);
        $this->seleccionada = $clave;
    }

    public function estado(string $raw): array
    {
        $r = $this->cmd(['STATUS', self::str($raw), '(MESSAGES UNSEEN)']);
        $tot = $nl = null;
        foreach ($r as $x) {
            if (preg_match('/MESSAGES (\d+)/', $x['t'], $m)) {
                $tot = (int) $m[1];
            }
            if (preg_match('/UNSEEN (\d+)/', $x['t'], $m)) {
                $nl = (int) $m[1];
            }
        }
        return [$tot, $nl];
    }

    public function buscar(array $criterios): array
    {
        $usaLit = false;
        foreach ($criterios as $c) {
            if ($c instanceof Lit) {
                $usaLit = true;
            }
        }
        $partes = array_merge(['UID', 'SEARCH'], $usaLit ? ['CHARSET', 'UTF-8'] : [], $criterios ?: ['ALL']);
        $uids = [];
        foreach ($this->cmd($partes) as $r) {
            if (stripos($r['t'], '* SEARCH') === 0) {
                foreach (preg_split('/\s+/', trim(substr($r['t'], 8))) as $u) {
                    if ($u !== '' && ctype_digit($u)) {
                        $uids[] = (int) $u;
                    }
                }
            }
        }
        sort($uids);
        return $uids;
    }

    /** Devuelve [uid => ['flags'=>..., 'size'=>..., 'data'=>literal]] */
    public function traer(array $uids, string $items): array
    {
        if (!$uids) {
            return [];
        }
        $res = [];
        foreach ($this->cmd(['UID', 'FETCH', implode(',', $uids), '(' . $items . ')']) as $r) {
            if (!preg_match('/^\* \d+ FETCH /i', $r['t']) || !preg_match('/\bUID (\d+)/i', $r['t'], $m)) {
                continue;
            }
            $u = (int) $m[1];
            $e = $res[$u] ?? ['flags' => '', 'size' => 0, 'data' => null];
            if (preg_match('/FLAGS \(([^)]*)\)/i', $r['t'], $f)) {
                $e['flags'] = $f[1];
            }
            if (preg_match('/RFC822\.SIZE (\d+)/i', $r['t'], $s)) {
                $e['size'] = (int) $s[1];
            }
            if (isset($r['l'][0])) {
                $e['data'] = $r['l'][0];
            }
            $res[$u] = $e;
        }
        return $res;
    }

    public function banderas(array $uids, string $op, string $flags): void
    {
        $this->cmd(['UID', 'STORE', implode(',', $uids), $op . 'FLAGS.SILENT', '(' . $flags . ')']);
    }

    public function mover(array $uids, string $destRaw): void
    {
        $set = implode(',', $uids);
        if ($this->tiene('MOVE')) {
            $this->cmd(['UID', 'MOVE', $set, self::str($destRaw)]);
            return;
        }
        $this->cmd(['UID', 'COPY', $set, self::str($destRaw)]);
        $this->banderas($uids, '+', '\\Deleted');
        $this->cmd($this->tiene('UIDPLUS') ? ['UID', 'EXPUNGE', $set] : ['EXPUNGE']);
    }

    public function agregar(string $carpetaRaw, string $flags, string $mensaje): void
    {
        $this->cmd(['APPEND', self::str($carpetaRaw), '(' . $flags . ')', new Lit($mensaje)]);
    }

    public function salir(): void
    {
        try {
            $this->cmd(['LOGOUT']);
        } catch (Throwable $e) {
        }
        @fclose($this->s);
    }
}

function dec7(string $s): string
{
    if (strpos($s, '&') === false) {
        return $s;
    }
    try {
        return mb_convert_encoding($s, 'UTF-8', 'UTF7-IMAP');
    } catch (Throwable $e) {
        return $s;
    }
}

// =====================================================================
//  Utilidades SMTP
// =====================================================================

final class Smtp
{
    private $s;
    private $ext = '';

    public function __construct(string $host, int $port, bool $ssl, int $timeout = 25)
    {
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
        $addr = ($ssl ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $errno = 0;
        $errstr = '';
        $this->s = @stream_socket_client($addr, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->s) {
            throw new RuntimeException("No pude conectar al servidor SMTP $host:$port ($errstr)");
        }
        stream_set_timeout($this->s, $timeout);
        $this->esperar([220]);
    }

    private function esperar(array $codigos): string
    {
        $todo = '';
        while (true) {
            $l = @fgets($this->s);
            if ($l === false) {
                throw new RuntimeException('El servidor SMTP cerró la conexión o tardó demasiado.');
            }
            $todo .= $l;
            if (strlen($l) < 4 || $l[3] !== '-') {
                break;
            }
        }
        $codigo = (int) substr($todo, 0, 3);
        if (!in_array($codigo, $codigos, true)) {
            throw new RuntimeException('El servidor de envío respondió: ' . trim(preg_replace('/\r?\n/', ' ', $todo)));
        }
        return $todo;
    }

    private function orden(string $linea, array $codigos): string
    {
        if (@fwrite($this->s, $linea . "\r\n") === false) {
            throw new RuntimeException('Se perdió la conexión con el servidor SMTP.');
        }
        return $this->esperar($codigos);
    }

    public function iniciar(string $dominio, bool $starttls, string $usuario, string $clave): void
    {
        $this->ext = $this->orden('EHLO ' . $dominio, [250]);
        if ($starttls) {
            $this->orden('STARTTLS', [220]);
            if (!@stream_socket_enable_crypto($this->s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('No se pudo activar el cifrado TLS con el servidor SMTP.');
            }
            $this->ext = $this->orden('EHLO ' . $dominio, [250]);
        }
        try {
            if (preg_match('/AUTH[ =][^\r\n]*\bPLAIN\b/i', $this->ext)) {
                $this->orden('AUTH PLAIN ' . base64_encode("\0" . $usuario . "\0" . $clave), [235]);
            } else {
                $this->orden('AUTH LOGIN', [334]);
                $this->orden(base64_encode($usuario), [334]);
                $this->orden(base64_encode($clave), [235]);
            }
        } catch (RuntimeException $e) {
            throw new RuntimeException('No se pudo iniciar sesión para enviar (revisa la contraseña). ' . $e->getMessage());
        }
    }

    public function enviar(string $de, array $destinatarios, string $mensaje): void
    {
        $this->orden('MAIL FROM:<' . $de . '>', [250]);
        foreach ($destinatarios as $r) {
            $this->orden('RCPT TO:<' . $r . '>', [250, 251]);
        }
        $this->orden('DATA', [354]);
        $datos = preg_replace('/^\./m', '..', $mensaje);
        if (@fwrite($this->s, $datos . "\r\n.\r\n") === false) {
            throw new RuntimeException('Se perdió la conexión mientras se enviaba el correo.');
        }
        $this->esperar([250]);
    }

    public function salir(): void
    {
        try {
            $this->orden('QUIT', [221]);
        } catch (Throwable $e) {
        }
        @fclose($this->s);
    }
}

// =====================================================================
//  Lectura de mensajes (MIME)
// =====================================================================

function separarCabecera(string $raw): array
{
    if (preg_match('/^\r?\n/', $raw, $m)) {
        return ['', substr($raw, strlen($m[0]))];
    }
    $p = strpos($raw, "\r\n\r\n");
    $q = strpos($raw, "\n\n");
    if ($p === false && $q === false) {
        return [$raw, ''];
    }
    if ($q === false || ($p !== false && $p < $q)) {
        return [substr($raw, 0, $p), (string) substr($raw, $p + 4)];
    }
    return [substr($raw, 0, $q), (string) substr($raw, $q + 2)];
}

function leerCabeceras(string $h): array
{
    $h = preg_replace("/\r?\n[ \t]+/", ' ', $h);
    $out = [];
    foreach (preg_split("/\r?\n/", $h) as $linea) {
        $i = strpos($linea, ':');
        if ($i === false) {
            continue;
        }
        $out[strtolower(trim(substr($linea, 0, $i)))][] = trim(substr($linea, $i + 1));
    }
    return $out;
}

function cab(array $h, string $n): string
{
    return $h[$n][0] ?? '';
}

function aUtf8(string $s, string $charset): string
{
    $cs = strtolower(trim($charset, " \"'"));
    $alias = ['utf8' => 'UTF-8', 'us-ascii' => 'UTF-8', 'ascii' => 'UTF-8', 'iso-8859-1' => 'Windows-1252', 'latin1' => 'Windows-1252',
        'ks_c_5601-1987' => 'CP949', 'gb2312' => 'GB18030', 'gbk' => 'GB18030', 'iso-8859-8-i' => 'ISO-8859-8'];
    $cs = $alias[$cs] ?? ($cs ?: 'UTF-8');
    if (strtoupper($cs) === 'UTF-8') {
        return mb_check_encoding($s, 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    }
    try {
        return mb_convert_encoding($s, 'UTF-8', $cs);
    } catch (Throwable $e) {
        $r = @iconv($cs, 'UTF-8//TRANSLIT//IGNORE', $s);
        if ($r !== false) {
            return $r;
        }
        return mb_check_encoding($s, 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    }
}

function decCab(string $v): string
{
    if (strpos($v, '=?') !== false) {
        $d = @iconv_mime_decode($v, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        $v = $d !== false ? $d : mb_decode_mimeheader($v);
    }
    if (!mb_check_encoding($v, 'UTF-8')) {
        $v = mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
    }
    return trim($v);
}

/** "text/plain; charset=utf-8; name=..." -> ['text/plain', ['charset'=>'utf-8', ...]] */
function parametros(string $v): array
{
    $pos = strpos($v, ';');
    $valor = trim($pos === false ? $v : substr($v, 0, $pos));
    $params = [];
    $cont = [];
    if ($pos !== false) {
        preg_match_all('/;\s*([^=\s;]+)\s*=\s*("(?:[^"\\\\]|\\\\.)*"|[^;]*)/', $v, $mm, PREG_SET_ORDER);
        foreach ($mm as $m) {
            $k = strtolower($m[1]);
            $val = trim($m[2]);
            if ($val !== '' && $val[0] === '"') {
                $val = preg_replace('/\\\\(.)/', '$1', substr($val, 1, -1));
            }
            if (preg_match('/^(.+?)\*(\d+)(\*?)$/', $k, $q)) {
                $cont[$q[1]][(int) $q[2]] = [$val, $q[3] === '*'];
                continue;
            }
            if (substr($k, -1) === '*') {
                $params[substr($k, 0, -1)] = rfc2231($val);
                continue;
            }
            $params[$k] = $val;
        }
        foreach ($cont as $nombre => $piezas) {
            ksort($piezas);
            $charset = '';
            $junto = '';
            foreach ($piezas as $i => [$val, $cod]) {
                if ($cod) {
                    if ($i === 0 && preg_match("/^([^']*)'[^']*'(.*)$/s", $val, $q)) {
                        $charset = $q[1];
                        $val = $q[2];
                    }
                    $junto .= rawurldecode($val);
                } else {
                    $junto .= $val;
                }
            }
            $params[$nombre] = $charset !== '' ? aUtf8($junto, $charset) : $junto;
        }
    }
    return [$valor, $params];
}

function rfc2231(string $v): string
{
    if (preg_match("/^([^']*)'[^']*'(.*)$/s", $v, $m)) {
        return aUtf8(rawurldecode($m[2]), $m[1] ?: 'UTF-8');
    }
    return rawurldecode($v);
}

function decodificarCuerpo(string $b, string $cte): string
{
    $cte = strtolower(trim($cte));
    if ($cte === 'base64') {
        return (string) base64_decode(preg_replace('/[^A-Za-z0-9+\/=]/', '', $b));
    }
    if ($cte === 'quoted-printable') {
        return quoted_printable_decode($b);
    }
    return $b;
}

function partesMultipart(string $cuerpo, string $limite): array
{
    $segs = explode('--' . $limite, $cuerpo);
    array_shift($segs);
    $res = [];
    foreach ($segs as $s) {
        if (strncmp($s, '--', 2) === 0) {
            break;
        }
        $s = preg_replace('/^[ \t]*\r?\n/', '', $s, 1);
        $s = preg_replace('/\r?\n$/', '', $s, 1);
        $res[] = $s;
    }
    return $res;
}

function recorrerMime(string $raw, array &$out, int $nivel = 0): void
{
    [$hRaw, $cuerpo] = separarCabecera($raw);
    $h = leerCabeceras($hRaw);
    [$tipo, $cp] = parametros(cab($h, 'content-type') ?: 'text/plain');
    $tipo = strtolower($tipo);
    [$disp, $dp] = parametros(cab($h, 'content-disposition'));
    $nombre = decCab((string) ($dp['filename'] ?? $cp['name'] ?? ''));

    if (strpos($tipo, 'multipart/') === 0 && !empty($cp['boundary']) && $nivel < 12) {
        foreach (partesMultipart($cuerpo, $cp['boundary']) as $p) {
            recorrerMime($p, $out, $nivel + 1);
        }
        return;
    }
    $cte = cab($h, 'content-transfer-encoding');
    $esAdjunto = strtolower($disp) === 'attachment' || ($nombre !== '' && strpos($tipo, 'text/') !== 0);
    if (!$esAdjunto && ($tipo === 'text/plain' || $tipo === 'text/html')) {
        $texto = aUtf8(decodificarCuerpo($cuerpo, $cte), (string) ($cp['charset'] ?? ''));
        $out[$tipo === 'text/html' ? 'html' : 'plain'][] = $texto;
        return;
    }
    $tam = strtolower(trim($cte)) === 'base64' ? (int) floor(strlen(preg_replace('/\s+/', '', $cuerpo)) * 3 / 4) : strlen($cuerpo);
    $out['adjuntos'][] = [
        'nombre' => $nombre !== '' ? $nombre : ($tipo === 'message/rfc822' ? 'mensaje-adjunto.eml' : '(sin nombre)'),
        'tipo' => $tipo,
        'tamano_kb' => round($tam / 1024, 1),
    ];
}

function htmlATexto(string $h): string
{
    $h = preg_replace('#<(script|style|head|title)\b[^>]*>.*?</\1\s*>#is', '', $h);
    $h = preg_replace('#<br\s*/?>#i', "\n", $h);
    $h = preg_replace('#</(p|div|tr|li|h[1-6]|table|blockquote|ul|ol)\s*>#i', "\n", $h);
    $h = preg_replace('#<li\b[^>]*>#i', '• ', $h);
    $h = preg_replace('#</t[dh]\s*>#i', ' | ', $h);
    $t = html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = preg_replace('/[ \t\x{00A0}]+/u', ' ', $t);
    $t = preg_replace('/ *\n */', "\n", $t);
    $t = preg_replace("/\n{3,}/", "\n\n", $t);
    return trim($t);
}

function analizarMensaje(string $raw): array
{
    [$hRaw] = separarCabecera($raw);
    $h = leerCabeceras($hRaw);
    $out = ['plain' => [], 'html' => [], 'adjuntos' => []];
    recorrerMime($raw, $out);
    $texto = $out['plain'] ? implode("\n\n", $out['plain']) : htmlATexto(implode("\n\n", $out['html']));
    $texto = trim(str_replace("\r\n", "\n", $texto));
    return ['h' => $h, 'texto' => $texto, 'adjuntos' => $out['adjuntos']];
}

function fecha(string $d): string
{
    $t = strtotime(preg_replace('/\s*\([^)]*\)\s*$/', '', $d));
    return $t ? date('Y-m-d H:i', $t) : $d;
}

function correosDe(string $lista): array
{
    preg_match_all('/[A-Z0-9._%+\-\']+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $lista, $m);
    return array_values(array_unique(array_map('strtolower', $m[0])));
}

// =====================================================================
//  Construcción y envío de mensajes
// =====================================================================

function encCab(string $s): string
{
    $s = str_replace(["\r", "\n"], ' ', $s);
    if (preg_match('/^[\x20-\x7e]*$/', $s)) {
        return $s;
    }
    return mb_encode_mimeheader($s, 'UTF-8', 'B', "\r\n");
}

/** Acepta "correo@x.com" o "Nombre <correo@x.com>". Devuelve [correo, texto_para_cabecera]. */
function direccion(string $in): array
{
    $in = trim(str_replace(["\r", "\n"], '', $in));
    if (preg_match('/^\s*"?([^"<]*?)"?\s*<([^>]+)>\s*$/', $in, $m)) {
        $correo = trim($m[2]);
        $nombre = trim($m[1]);
    } else {
        $correo = $in;
        $nombre = '';
    }
    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException("La dirección \"$in\" no es un correo válido.");
    }
    if ($nombre === '') {
        return [$correo, $correo];
    }
    $n = preg_match('/^[\x20-\x7e]*$/', $nombre) ? '"' . addcslashes($nombre, '"\\') . '"' : encCab($nombre);
    return [$correo, $n . ' <' . $correo . '>'];
}

function listaDirecciones($v): array
{
    if ($v === null || $v === '' || $v === []) {
        return [];
    }
    if (is_string($v)) {
        $v = preg_split('/[,;]\s*(?=(?:[^"]*"[^"]*")*[^"]*$)/', $v);
    }
    $res = [];
    foreach ((array) $v as $x) {
        if (trim((string) $x) !== '') {
            $res[] = direccion((string) $x);
        }
    }
    return $res;
}

function textoPlanoDeHtml(string $html): string
{
    return htmlATexto($html);
}

/**
 * Devuelve ['raw' => mensaje para enviar, 'raw_copia' => con Bcc para "Enviados", 'id' => Message-ID, 'rcpts' => [...]]
 */
function construirMensaje(array $cfg, array $o): array
{
    $para = listaDirecciones($o['para'] ?? []);
    $cc = listaDirecciones($o['cc'] ?? []);
    $cco = listaDirecciones($o['cco'] ?? []);
    if (!$para && !$cc && !$cco && empty($o['borrador'])) {
        throw new InvalidArgumentException('Falta al menos un destinatario.');
    }
    $dominio = dominioDe($cfg);
    $id = '<' . bin2hex(random_bytes(12)) . '@' . $dominio . '>';
    $nombreDe = (string) ($cfg['nombre_remitente'] ?? '');
    $de = $nombreDe !== '' ? direccion($nombreDe . ' <' . $cfg['correo'] . '>')[1] : $cfg['correo'];

    $cab = [];
    $cab[] = 'Date: ' . date('r');
    $cab[] = 'From: ' . $de;
    if ($para) {
        $cab[] = 'To: ' . implode(', ', array_column($para, 1));
    }
    if ($cc) {
        $cab[] = 'Cc: ' . implode(', ', array_column($cc, 1));
    }
    $cabBcc = $cco ? 'Bcc: ' . implode(', ', array_column($cco, 1)) : null;
    $cab[] = 'Subject: ' . encCab((string) ($o['asunto'] ?? ''));
    $cab[] = 'Message-ID: ' . $id;
    if (!empty($o['in_reply_to'])) {
        $cab[] = 'In-Reply-To: ' . $o['in_reply_to'];
    }
    if (!empty($o['references'])) {
        $cab[] = 'References: ' . $o['references'];
    }
    $cab[] = 'MIME-Version: 1.0';

    $cuerpo = str_replace(["\r\n", "\r"], "\n", (string) ($o['cuerpo'] ?? ''));
    $esHtml = ($o['formato'] ?? 'texto') === 'html';
    $qp = function (string $s): string {
        return quoted_printable_encode(str_replace("\n", "\r\n", $s));
    };
    if ($esHtml) {
        $b = 'alt_' . bin2hex(random_bytes(8));
        $cab[] = 'Content-Type: multipart/alternative; boundary="' . $b . '"';
        $html = $cuerpo;
        if (stripos($html, '<html') === false) {
            $html = "<!DOCTYPE html><html><head><meta charset=\"UTF-8\"></head><body>\n" . $html . "\n</body></html>";
        }
        $partes = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
            . $qp(textoPlanoDeHtml($cuerpo)) . "\r\n"
            . "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
            . $qp($html) . "\r\n--$b--\r\n";
    } else {
        $cab[] = 'Content-Type: text/plain; charset=UTF-8';
        $cab[] = 'Content-Transfer-Encoding: quoted-printable';
        $partes = $qp($cuerpo) . "\r\n";
    }
    $raw = implode("\r\n", $cab) . "\r\n\r\n" . $partes;
    // La copia que se guarda en Enviados incluye la copia oculta (Bcc); el mensaje enviado no.
    $copia = $cabBcc ? implode("\r\n", array_merge(array_slice($cab, 0, 2), [$cabBcc], array_slice($cab, 2))) . "\r\n\r\n" . $partes : $raw;

    $rcpts = array_values(array_unique(array_map('strtolower', array_merge(array_column($para, 0), array_column($cc, 0), array_column($cco, 0)))));
    return ['raw' => $raw, 'raw_copia' => $copia, 'id' => $id, 'rcpts' => $rcpts];
}

function archivoLimite(): string
{
    return __DIR__ . '/.envios.json';
}

function revisarLimite(array $cfg): void
{
    $max = (int) ($cfg['max_envios_por_hora'] ?? 30);
    $f = archivoLimite();
    $d = is_file($f) ? (json_decode((string) @file_get_contents($f), true) ?: []) : [];
    $ahora = time();
    $d = array_filter($d, function ($t) use ($ahora) {
        return $t > $ahora - 3600;
    });
    if (count($d) >= $max) {
        throw new RuntimeException("Se alcanzó el límite de seguridad de $max correos por hora. Espera un poco o súbelo en config.php.");
    }
}

function registrarEnvio(): void
{
    $f = archivoLimite();
    $d = is_file($f) ? (json_decode((string) @file_get_contents($f), true) ?: []) : [];
    $ahora = time();
    $d = array_values(array_filter($d, function ($t) use ($ahora) {
        return $t > $ahora - 3600;
    }));
    $d[] = $ahora;
    @file_put_contents($f, json_encode($d), LOCK_EX);
}

// =====================================================================
//  Carpetas
// =====================================================================

const ESPECIALES = [
    'sent' => ['\\Sent', ['sent', 'enviados', 'sent items', 'sent messages', 'elementos enviados']],
    'drafts' => ['\\Drafts', ['drafts', 'borradores', 'draft']],
    'trash' => ['\\Trash', ['trash', 'papelera', 'deleted', 'deleted items', 'deleted messages', 'eliminados']],
    'junk' => ['\\Junk', ['junk', 'spam', 'correo no deseado', 'bulk']],
    'archive' => ['\\Archive', ['archive', 'archivo', 'archivados', 'archived']],
];
const ALIAS_CARPETAS = [
    'enviados' => 'sent', 'sent' => 'sent', 'elementos enviados' => 'sent', 'enviado' => 'sent',
    'borradores' => 'drafts', 'drafts' => 'drafts', 'borrador' => 'drafts',
    'papelera' => 'trash', 'trash' => 'trash', 'eliminados' => 'trash', 'basura' => 'trash', 'borrados' => 'trash',
    'spam' => 'junk', 'junk' => 'junk', 'correo no deseado' => 'junk', 'no deseado' => 'junk',
    'archivo' => 'archive', 'archive' => 'archive', 'archivados' => 'archive',
];

function carpetaEspecial(Imap $im, string $tipo): ?string
{
    [$flag, $nombres] = ESPECIALES[$tipo];
    foreach ($im->carpetas() as $f) {
        if ($f['seleccionable'] && stripos($f['flags'], $flag) !== false) {
            return $f['raw'];
        }
    }
    foreach ($im->carpetas() as $f) {
        if ($f['seleccionable'] && in_array(mb_strtolower($f['corto']), $nombres, true)) {
            return $f['raw'];
        }
    }
    return null;
}

function tipoCarpeta(array $f): string
{
    $nombres = ['sent' => 'enviados', 'drafts' => 'borradores', 'trash' => 'papelera', 'junk' => 'spam', 'archive' => 'archivo'];
    if (strtoupper($f['raw']) === 'INBOX') {
        return 'bandeja de entrada';
    }
    foreach (ESPECIALES as $k => [$flag, $lista]) {
        if (stripos($f['flags'], $flag) !== false || in_array(mb_strtolower($f['corto']), $lista, true)) {
            return $nombres[$k];
        }
    }
    return '';
}

function resolverCarpeta(Imap $im, $entrada): string
{
    $in = trim((string) $entrada);
    $bajo = mb_strtolower($in);
    if ($in === '' || in_array($bajo, ['inbox', 'entrada', 'bandeja de entrada', 'recibidos'], true)) {
        return 'INBOX';
    }
    $fs = $im->carpetas();
    foreach ($fs as $f) {
        if (mb_strtolower($f['nombre']) === $bajo || mb_strtolower($f['raw']) === $bajo) {
            return $f['raw'];
        }
    }
    foreach ($fs as $f) {
        if (mb_strtolower($f['corto']) === $bajo) {
            return $f['raw'];
        }
    }
    if (isset(ALIAS_CARPETAS[$bajo])) {
        $r = carpetaEspecial($im, ALIAS_CARPETAS[$bajo]);
        if ($r !== null) {
            return $r;
        }
    }
    throw new InvalidArgumentException("No encontré la carpeta \"$in\". Carpetas disponibles: " . implode(', ', array_column($fs, 'nombre')));
}

// =====================================================================
//  Herramientas que verá Claude
// =====================================================================

function dominioDe(array $cfg): string
{
    $p = strrchr((string) $cfg['correo'], '@');
    return $p !== false && strlen($p) > 1 ? substr($p, 1) : 'localhost';
}

function conectar(array $cfg): Imap
{
    $im = new Imap($cfg['imap_host'], (int) $cfg['imap_puerto'], (bool) $cfg['imap_ssl']);
    $im->login($cfg['correo'], $cfg['contrasena']);
    return $im;
}

function uidsDe($v): array
{
    $v = is_array($v) ? $v : [$v];
    $r = [];
    foreach ($v as $x) {
        if (is_numeric($x) && (int) $x > 0) {
            $r[] = (int) $x;
        }
    }
    if (!$r) {
        throw new InvalidArgumentException('Indica al menos un uid de correo (número que aparece en los listados).');
    }
    return array_values(array_unique($r));
}

function resumenes(Imap $im, array $uids): array
{
    $datos = $im->traer($uids, 'UID FLAGS RFC822.SIZE BODY.PEEK[HEADER.FIELDS (FROM TO CC SUBJECT DATE)]');
    $res = [];
    foreach (array_reverse($uids) as $u) {
        if (!isset($datos[$u])) {
            continue;
        }
        $h = leerCabeceras((string) $datos[$u]['data']);
        $fl = $datos[$u]['flags'];
        $res[] = [
            'uid' => $u,
            'fecha' => fecha(cab($h, 'date')),
            'de' => decCab(cab($h, 'from')),
            'para' => decCab(cab($h, 'to')),
            'asunto' => decCab(cab($h, 'subject')) ?: '(sin asunto)',
            'leido' => stripos($fl, '\\Seen') !== false,
            'destacado' => stripos($fl, '\\Flagged') !== false,
            'respondido' => stripos($fl, '\\Answered') !== false,
            'tamano_kb' => round($datos[$u]['size'] / 1024, 1),
        ];
    }
    return $res;
}

function fechaImap(string $f, int $sumarDias = 0): string
{
    $t = strtotime($f);
    if ($t === false) {
        throw new InvalidArgumentException("Fecha no válida: \"$f\". Usa el formato AAAA-MM-DD.");
    }
    return date('d-M-Y', $t + $sumarDias * 86400);
}

function h_listar_carpetas(array $cfg, array $a): array
{
    $im = conectar($cfg);
    $res = [];
    foreach ($im->carpetas() as $f) {
        if (!$f['seleccionable']) {
            continue;
        }
        [$tot, $nl] = $im->estado($f['raw']);
        $res[] = ['carpeta' => $f['nombre'], 'tipo' => tipoCarpeta($f), 'total' => $tot, 'no_leidos' => $nl];
    }
    $im->salir();
    return ['cuenta' => $cfg['correo'], 'carpetas' => $res];
}

function h_listar_correos(array $cfg, array $a): array
{
    $im = conectar($cfg);
    $carpeta = resolverCarpeta($im, $a['carpeta'] ?? 'INBOX');
    $im->seleccionar($carpeta);
    $uids = $im->buscar(!empty($a['solo_no_leidos']) ? ['UNSEEN'] : ['ALL']);
    $limite = max(1, min(50, (int) ($a['limite'] ?? 15)));
    $pagina = max(1, (int) ($a['pagina'] ?? 1));
    $total = count($uids);
    $fin = $total - ($pagina - 1) * $limite;
    $ini = max(0, $fin - $limite);
    $tramo = $fin > 0 ? array_slice($uids, $ini, $fin - $ini) : [];
    $res = resumenes($im, $tramo);
    $im->salir();
    return [
        'carpeta' => dec7($carpeta),
        'total_coincidencias' => $total,
        'pagina' => $pagina,
        'hay_mas_antiguos' => $ini > 0,
        'correos' => $res,
    ];
}

function h_buscar_correos(array $cfg, array $a): array
{
    $c = [];
    if (!empty($a['solo_no_leidos'])) {
        $c[] = 'UNSEEN';
    }
    if (!empty($a['solo_destacados'])) {
        $c[] = 'FLAGGED';
    }
    foreach (['remitente' => 'FROM', 'destinatario' => 'TO', 'asunto' => 'SUBJECT', 'texto' => 'TEXT'] as $k => $crit) {
        if (isset($a[$k]) && trim((string) $a[$k]) !== '') {
            $c[] = $crit;
            $c[] = Imap::str(trim((string) $a[$k]));
        }
    }
    if (!empty($a['desde'])) {
        $c[] = 'SINCE';
        $c[] = fechaImap((string) $a['desde']);
    }
    if (!empty($a['hasta'])) {
        $c[] = 'BEFORE';
        $c[] = fechaImap((string) $a['hasta'], 1);
    }
    $im = conectar($cfg);
    $carpeta = resolverCarpeta($im, $a['carpeta'] ?? 'INBOX');
    $im->seleccionar($carpeta);
    $uids = $im->buscar($c);
    $limite = max(1, min(50, (int) ($a['limite'] ?? 20)));
    $res = resumenes($im, array_slice($uids, -$limite));
    $im->salir();
    return [
        'carpeta' => dec7($carpeta),
        'total_coincidencias' => count($uids),
        'mostrando' => count($res),
        'correos' => $res,
    ];
}

function h_leer_correos(array $cfg, array $a): array
{
    $uids = array_slice(uidsDe($a['uids'] ?? ($a['uid'] ?? null)), 0, 10);
    $im = conectar($cfg);
    $carpeta = resolverCarpeta($im, $a['carpeta'] ?? 'INBOX');
    $marcar = !empty($a['marcar_como_leido']);
    $im->seleccionar($carpeta, !$marcar);
    $tam = $im->traer($uids, 'UID RFC822.SIZE FLAGS');
    $res = [];
    foreach ($uids as $u) {
        if (!isset($tam[$u])) {
            $res[] = ['uid' => $u, 'error' => 'No existe un correo con ese uid en esta carpeta.'];
            continue;
        }
        $grande = $tam[$u]['size'] > 8 * 1024 * 1024;
        $d = $im->traer([$u], 'UID ' . ($grande ? 'BODY.PEEK[]<0.3000000>' : 'BODY.PEEK[]'));
        $m = analizarMensaje((string) ($d[$u]['data'] ?? ''));
        $h = $m['h'];
        $texto = $m['texto'];
        $cortado = mb_strlen($texto) > MAX_TEXTO;
        if ($cortado) {
            $texto = mb_substr($texto, 0, MAX_TEXTO);
        }
        $res[] = [
            'uid' => $u,
            'fecha' => fecha(cab($h, 'date')),
            'de' => decCab(cab($h, 'from')),
            'para' => decCab(cab($h, 'to')),
            'cc' => decCab(cab($h, 'cc')),
            'responder_a' => decCab(cab($h, 'reply-to')),
            'asunto' => decCab(cab($h, 'subject')) ?: '(sin asunto)',
            'leido' => $marcar || stripos($tam[$u]['flags'], '\\Seen') !== false,
            'texto' => $texto,
            'texto_recortado' => $cortado || $grande,
            'adjuntos' => $m['adjuntos'],
        ];
    }
    if ($marcar) {
        $im->banderas($uids, '+', '\\Seen');
    }
    $im->salir();
    return ['carpeta' => dec7($carpeta), 'correos' => $res];
}

/** Prepara destinatarios, asunto y cita para responder a un correo existente. */
function datosRespuesta(array $cfg, Imap $im, string $carpeta, int $uid, bool $todos, string $cuerpo): array
{
    $im->seleccionar($carpeta, false);
    $d = $im->traer([$uid], 'UID BODY.PEEK[]');
    if (!isset($d[$uid]['data'])) {
        throw new InvalidArgumentException("No existe un correo con uid $uid en esa carpeta.");
    }
    $m = analizarMensaje($d[$uid]['data']);
    $h = $m['h'];
    $yo = strtolower($cfg['correo']);
    $para = correosDe(cab($h, 'reply-to') ?: cab($h, 'from'));
    $cc = [];
    if ($todos) {
        $cc = array_diff(array_merge(correosDe(implode(',', $h['to'] ?? [])), correosDe(implode(',', $h['cc'] ?? []))), $para, [$yo]);
    }
    $para = array_values(array_diff($para, [$yo])) ?: correosDe(cab($h, 'to'));
    $asunto = decCab(cab($h, 'subject'));
    if (!preg_match('/^\s*(re|rv|aw)\s*:/i', $asunto)) {
        $asunto = 'Re: ' . $asunto;
    }
    $mid = trim(cab($h, 'message-id'));
    $refs = trim(trim(cab($h, 'references')) . ' ' . $mid);
    $cita = mb_substr($m['texto'], 0, 4000);
    $cita = implode("\n", array_map(function ($l) {
        return '> ' . $l;
    }, explode("\n", $cita)));
    $cuerpo = rtrim($cuerpo) . "\n\nEl " . fecha(cab($h, 'date')) . ', ' . decCab(cab($h, 'from')) . " escribió:\n" . $cita . "\n";
    return [
        'para' => $para,
        'cc' => array_values($cc),
        'asunto' => $asunto,
        'cuerpo' => $cuerpo,
        'in_reply_to' => $mid,
        'references' => $refs,
    ];
}

function enviarYGuardar(array $cfg, array $msg, ?Imap $im): array
{
    revisarLimite($cfg);
    $dominio = dominioDe($cfg);
    $smtp = new Smtp($cfg['smtp_host'], (int) $cfg['smtp_puerto'], (bool) $cfg['smtp_ssl']);
    $smtp->iniciar($dominio, (bool) ($cfg['smtp_starttls'] ?? !$cfg['smtp_ssl']), $cfg['correo'], $cfg['contrasena']);
    $smtp->enviar($cfg['correo'], $msg['rcpts'], $msg['raw']);
    $smtp->salir();
    registrarEnvio();

    $nota = 'Copia guardada en Enviados.';
    try {
        $im = $im ?: conectar($cfg);
        $enviados = carpetaEspecial($im, 'sent');
        if ($enviados !== null) {
            $im->agregar($enviados, '\\Seen', $msg['raw_copia']);
        } else {
            $nota = 'Enviado, pero no encontré la carpeta Enviados para guardar copia.';
        }
    } catch (Throwable $e) {
        $nota = 'Enviado, pero no pude guardar la copia en Enviados: ' . $e->getMessage();
    }
    return ['enviado' => true, 'destinatarios' => $msg['rcpts'], 'message_id' => $msg['id'], 'nota' => $nota];
}

function h_enviar_correo(array $cfg, array $a): array
{
    $msg = construirMensaje($cfg, $a);
    $r = enviarYGuardar($cfg, $msg, null);
    $r['asunto'] = (string) ($a['asunto'] ?? '');
    return $r;
}

function h_responder_correo(array $cfg, array $a): array
{
    $uid = uidsDe($a['uid'] ?? null)[0];
    $im = conectar($cfg);
    $carpeta = resolverCarpeta($im, $a['carpeta'] ?? 'INBOX');
    $datos = datosRespuesta($cfg, $im, $carpeta, $uid, !empty($a['responder_a_todos']), (string) ($a['cuerpo'] ?? ''));
    if (!empty($a['cc_adicional'])) {
        $datos['cc'] = array_merge($datos['cc'], (array) $a['cc_adicional']);
    }
    $datos['formato'] = $a['formato'] ?? 'texto';
    $msg = construirMensaje($cfg, $datos);
    $r = enviarYGuardar($cfg, $msg, $im);
    try {
        $im->seleccionar($carpeta, false);
        $im->banderas([$uid], '+', '\\Answered \\Seen');
    } catch (Throwable $e) {
    }
    $im->salir();
    $r['asunto'] = $datos['asunto'];
    return $r;
}

function h_crear_borrador(array $cfg, array $a): array
{
    $im = conectar($cfg);
    if (!empty($a['responder_a_uid'])) {
        $carpeta = resolverCarpeta($im, $a['carpeta'] ?? 'INBOX');
        $datos = datosRespuesta($cfg, $im, $carpeta, uidsDe($a['responder_a_uid'])[0], !empty($a['responder_a_todos']), (string) ($a['cuerpo'] ?? ''));
        $datos['formato'] = $a['formato'] ?? 'texto';
    } else {
        $datos = $a;
    }
    $datos['borrador'] = true;
    $msg = construirMensaje($cfg, $datos);
    $dest = carpetaEspecial($im, 'drafts');
    if ($dest === null) {
        $im->cmd(['CREATE', 'Drafts']);
        $dest = 'Drafts';
    }
    $im->agregar($dest, '\\Draft \\Seen', $msg['raw_copia']);
    $im->salir();
    return ['borrador_guardado' => true, 'carpeta' => dec7($dest), 'asunto' => $datos['asunto'] ?? '',
        'nota' => 'Puedes revisarlo y enviarlo desde el webmail de Hostinger (carpeta Borradores).'];
}

function h_marcar_correos(array $cfg, array $a): array
{
    $uids = uidsDe($a['uids'] ?? ($a['uid'] ?? null));
    $im = conectar($cfg);
    $carpeta = resolverCarpeta($im, $a['carpeta'] ?? 'INBOX');
    $im->seleccionar($carpeta, false);
    $hecho = [];
    if (array_key_exists('leido', $a) && $a['leido'] !== null) {
        $im->banderas($uids, $a['leido'] ? '+' : '-', '\\Seen');
        $hecho[] = $a['leido'] ? 'marcados como leídos' : 'marcados como no leídos';
    }
    if (array_key_exists('destacado', $a) && $a['destacado'] !== null) {
        $im->banderas($uids, $a['destacado'] ? '+' : '-', '\\Flagged');
        $hecho[] = $a['destacado'] ? 'destacados' : 'sin destacar';
    }
    $im->salir();
    if (!$hecho) {
        throw new InvalidArgumentException('Indica "leido" y/o "destacado" (true o false).');
    }
    return ['uids' => $uids, 'resultado' => implode(' y ', $hecho)];
}

function h_mover_correos(array $cfg, array $a): array
{
    $uids = uidsDe($a['uids'] ?? ($a['uid'] ?? null));
    $im = conectar($cfg);
    $origen = resolverCarpeta($im, $a['carpeta'] ?? 'INBOX');
    $destino = resolverCarpeta($im, $a['carpeta_destino'] ?? '');
    if ($origen === $destino) {
        throw new InvalidArgumentException('La carpeta de origen y destino son la misma.');
    }
    $im->seleccionar($origen, false);
    $im->mover($uids, $destino);
    $im->salir();
    return ['movidos' => count($uids), 'de' => dec7($origen), 'a' => dec7($destino)];
}

// =====================================================================
//  Definición de herramientas (lo que Claude ve)
// =====================================================================

function herramientas(): array
{
    $carpeta = ['type' => 'string', 'description' => 'Carpeta de correo. Por defecto INBOX (bandeja de entrada). Acepta nombres como "Enviados", "Spam", "Papelera", "Borradores" o el nombre exacto de listar_carpetas.'];
    $uids = ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Lista de uids (identificadores) de correos, tal como aparecen en listar_correos o buscar_correos.'];
    $direcciones = ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Direcciones de correo. Formato "correo@dominio.com" o "Nombre <correo@dominio.com>".'];
    $formato = ['type' => 'string', 'enum' => ['texto', 'html'], 'description' => 'Formato del cuerpo. "texto" (por defecto) o "html".'];
    $soloLectura = ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false];

    return [
        [
            'name' => 'listar_carpetas',
            'title' => 'Listar carpetas',
            'description' => 'Lista las carpetas del buzón de correo (Hostinger) con el total de mensajes y no leídos.',
            'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
            'annotations' => $soloLectura,
        ],
        [
            'name' => 'listar_correos',
            'title' => 'Listar correos',
            'description' => 'Lista los correos más recientes de una carpeta (remitente, asunto, fecha, uid, leído). Para ver el contenido usa leer_correos con el uid.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'carpeta' => $carpeta,
                'limite' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'description' => 'Cuántos correos mostrar (1-50, por defecto 15).'],
                'pagina' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Página: 1 = los más recientes, 2 = los siguientes más antiguos, etc.'],
                'solo_no_leidos' => ['type' => 'boolean', 'description' => 'Mostrar solo correos no leídos.'],
            ]],
            'annotations' => $soloLectura,
        ],
        [
            'name' => 'buscar_correos',
            'title' => 'Buscar correos',
            'description' => 'Busca correos por remitente, destinatario, asunto, texto y rango de fechas. Todos los filtros son opcionales y se combinan.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'carpeta' => $carpeta,
                'texto' => ['type' => 'string', 'description' => 'Texto a buscar en todo el mensaje (asunto, cuerpo, remitente).'],
                'remitente' => ['type' => 'string', 'description' => 'Parte del correo o nombre del remitente.'],
                'destinatario' => ['type' => 'string', 'description' => 'Parte del correo o nombre del destinatario.'],
                'asunto' => ['type' => 'string', 'description' => 'Texto contenido en el asunto.'],
                'desde' => ['type' => 'string', 'description' => 'Fecha inicial, formato AAAA-MM-DD.'],
                'hasta' => ['type' => 'string', 'description' => 'Fecha final (incluida), formato AAAA-MM-DD.'],
                'solo_no_leidos' => ['type' => 'boolean'],
                'solo_destacados' => ['type' => 'boolean'],
                'limite' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'description' => 'Máximo de resultados (por defecto 20, los más recientes).'],
            ]],
            'annotations' => $soloLectura,
        ],
        [
            'name' => 'leer_correos',
            'title' => 'Leer correos',
            'description' => 'Lee el contenido completo de uno o varios correos (hasta 10): remitente, destinatarios, texto y lista de adjuntos. Úsalo para resumir o analizar.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'uids' => $uids,
                'carpeta' => $carpeta,
                'marcar_como_leido' => ['type' => 'boolean', 'description' => 'Si es true, los marca como leídos. Por defecto no cambia nada.'],
            ], 'required' => ['uids']],
            'annotations' => $soloLectura,
        ],
        [
            'name' => 'enviar_correo',
            'title' => 'Enviar correo',
            'description' => 'Envía un correo nuevo desde la cuenta de Hostinger y guarda copia en Enviados. Confirma el contenido con el usuario antes de enviar.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'para' => $direcciones,
                'cc' => $direcciones,
                'cco' => array_merge($direcciones, ['description' => 'Copia oculta (los demás no la ven).']),
                'asunto' => ['type' => 'string'],
                'cuerpo' => ['type' => 'string', 'description' => 'Contenido del correo.'],
                'formato' => $formato,
            ], 'required' => ['para', 'asunto', 'cuerpo']],
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'openWorldHint' => true],
        ],
        [
            'name' => 'responder_correo',
            'title' => 'Responder correo',
            'description' => 'Responde a un correo existente (mantiene el hilo, agrega "Re:" y cita el mensaje original). Confirma el contenido con el usuario antes de enviar.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'uid' => ['type' => 'integer', 'description' => 'uid del correo al que se responde.'],
                'carpeta' => $carpeta,
                'cuerpo' => ['type' => 'string', 'description' => 'Texto de la respuesta (sin la cita; se agrega sola).'],
                'responder_a_todos' => ['type' => 'boolean', 'description' => 'Incluir a todos los destinatarios originales.'],
                'cc_adicional' => $direcciones,
                'formato' => $formato,
            ], 'required' => ['uid', 'cuerpo']],
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'openWorldHint' => true],
        ],
        [
            'name' => 'crear_borrador',
            'title' => 'Guardar borrador',
            'description' => 'Guarda un correo en la carpeta Borradores sin enviarlo, para que el usuario lo revise. Puede ser nuevo o una respuesta (responder_a_uid).',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'para' => $direcciones,
                'cc' => $direcciones,
                'asunto' => ['type' => 'string'],
                'cuerpo' => ['type' => 'string'],
                'responder_a_uid' => ['type' => 'integer', 'description' => 'Opcional: uid del correo al que responde (llena destinatarios y asunto solo).'],
                'responder_a_todos' => ['type' => 'boolean'],
                'carpeta' => $carpeta,
                'formato' => $formato,
            ], 'required' => ['cuerpo']],
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'openWorldHint' => false],
        ],
        [
            'name' => 'marcar_correos',
            'title' => 'Marcar correos',
            'description' => 'Marca correos como leídos/no leídos y/o destacados.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'uids' => $uids,
                'carpeta' => $carpeta,
                'leido' => ['type' => 'boolean'],
                'destacado' => ['type' => 'boolean'],
            ], 'required' => ['uids']],
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
        ],
        [
            'name' => 'mover_correos',
            'title' => 'Mover correos',
            'description' => 'Mueve correos a otra carpeta. Para eliminar, muévelos a "Papelera"; para archivar, a "Archivo"; para spam, a "Spam".',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'uids' => $uids,
                'carpeta' => $carpeta,
                'carpeta_destino' => ['type' => 'string', 'description' => 'Carpeta destino.'],
            ], 'required' => ['uids', 'carpeta_destino']],
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'openWorldHint' => false],
        ],
    ];
}

// =====================================================================
//  Protocolo MCP (JSON-RPC sobre HTTP)
// =====================================================================

function json($d): string
{
    return json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}

function rpc(array $cfg, $req)
{
    if (!is_array($req) || !isset($req['method'])) {
        return ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'Solicitud no válida']];
    }
    $esNotificacion = !array_key_exists('id', $req);
    $id = $req['id'] ?? null;
    $p = isset($req['params']) && is_array($req['params']) ? $req['params'] : [];
    $ok = function ($r) use ($id) {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $r];
    };
    $err = function ($c, $m) use ($id) {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $c, 'message' => $m]];
    };

    switch ($req['method']) {
        case 'initialize':
            $soportadas = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];
            $pedida = (string) ($p['protocolVersion'] ?? '');
            return $ok([
                'protocolVersion' => in_array($pedida, $soportadas, true) ? $pedida : '2025-06-18',
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'correo-hostinger', 'title' => 'Correo Hostinger', 'version' => VERSION_CONECTOR],
                'instructions' => 'Herramientas para el correo ' . $cfg['correo'] . ' (Hostinger). Usa listar_correos o buscar_correos para encontrar mensajes y leer_correos con los uids para ver su contenido. '
                    . 'Antes de enviar o responder, muestra el borrador al usuario y espera su confirmación. Las fechas están en la zona ' . ($cfg['zona_horaria'] ?? 'UTC') . '.',
            ]);
        case 'notifications/initialized':
        case 'notifications/cancelled':
            return null;
        case 'ping':
            return $ok(new stdClass());
        case 'tools/list':
            return $ok(['tools' => herramientas()]);
        case 'tools/call':
            $nombre = (string) ($p['name'] ?? '');
            $args = isset($p['arguments']) && is_array($p['arguments']) ? $p['arguments'] : [];
            $fn = 'h_' . $nombre;
            if (!in_array($nombre, array_column(herramientas(), 'name'), true) || !function_exists($fn)) {
                return $err(-32602, "Herramienta desconocida: $nombre");
            }
            try {
                $r = $fn($cfg, $args);
                return $ok(['content' => [['type' => 'text', 'text' => json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)]], 'isError' => false]);
            } catch (Throwable $e) {
                $msg = $e instanceof ErrorException ? 'Error interno: ' . $e->getMessage() : $e->getMessage();
                return $ok(['content' => [['type' => 'text', 'text' => $msg]], 'isError' => true]);
            }
        default:
            return $esNotificacion ? null : $err(-32601, 'Método no soportado: ' . $req['method']);
    }
}

// =====================================================================
//  Página de diagnóstico (abrir en el navegador)
// =====================================================================

function diagnostico(array $cfg): void
{
    header('Content-Type: text/html; charset=utf-8');
    $filas = [];
    $agregar = function ($ok, $titulo, $detalle = '') use (&$filas) {
        $filas[] = [$ok, $titulo, $detalle];
    };
    $agregar(PHP_VERSION_ID >= 70400, 'Versión de PHP', PHP_VERSION);
    $agregar(extension_loaded('openssl'), 'Extensión openssl');
    $agregar(extension_loaded('mbstring'), 'Extensión mbstring');
    $agregar(extension_loaded('iconv'), 'Extensión iconv');
    $configOk = $cfg['correo'] !== '' && $cfg['contrasena'] !== '' && $cfg['correo'] !== 'tu@tudominio.com' && $cfg['contrasena'] !== 'TU-CONTRASEÑA-DEL-CORREO';
    $agregar($configOk, 'Datos del correo', $configOk ? $cfg['correo'] . ' (tomados de ' . $cfg['origen'] . ')' : 'No encontré MAIL_USER / MAIL_PASS en config/config.php del sitio');
    if ($configOk) {
        try {
            $im = conectar($cfg);
            $agregar(true, 'Conexión IMAP (recibir)', $cfg['imap_host'] . ':' . $cfg['imap_puerto']);
            [$tot, $nl] = $im->estado('INBOX');
            $agregar(true, 'Bandeja de entrada', "$tot correos, $nl sin leer");
            $s = carpetaEspecial($im, 'sent');
            $b = carpetaEspecial($im, 'drafts');
            $agregar($s !== null, 'Carpeta Enviados', $s !== null ? dec7($s) : 'no encontrada');
            $agregar(true, 'Carpeta Borradores', $b !== null ? dec7($b) : 'no existe todavía; se creará al guardar el primer borrador');
            $im->salir();
        } catch (Throwable $e) {
            $agregar(false, 'Conexión IMAP (recibir)', $e->getMessage());
        }
        try {
            $dominio = dominioDe($cfg);
            $smtp = new Smtp($cfg['smtp_host'], (int) $cfg['smtp_puerto'], (bool) $cfg['smtp_ssl']);
            $smtp->iniciar($dominio, (bool) ($cfg['smtp_starttls'] ?? !$cfg['smtp_ssl']), $cfg['correo'], $cfg['contrasena']);
            $smtp->salir();
            $agregar(true, 'Conexión SMTP (enviar)', $cfg['smtp_host'] . ':' . $cfg['smtp_puerto'] . ' — sesión iniciada (no se envió nada)');
        } catch (Throwable $e) {
            $agregar(false, 'Conexión SMTP (enviar)', $e->getMessage());
        }
    }
    $todoOk = !in_array(false, array_column($filas, 0), true);
    $esquema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
    $url = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'tu-dominio.com') . strtok($_SERVER['REQUEST_URI'] ?? '/mcp/index.php', '?') . '?k=' . rawurlencode($cfg['clave_secreta']);
    $e = function ($s) {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    };
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Diagnóstico del conector</title>';
    echo '<style>body{font-family:system-ui,-apple-system,sans-serif;max-width:640px;margin:24px auto;padding:0 16px;color:#1f2328;background:#fafafa}h1{font-size:1.3rem}'
        . '.f{display:flex;gap:10px;padding:10px 12px;background:#fff;border:1px solid #e5e5e5;border-radius:8px;margin:6px 0}.i{font-size:1.1rem}.d{color:#666;font-size:.9rem;word-break:break-word}'
        . '.ok{border-left:4px solid #1a7f37}.no{border-left:4px solid #cf222e}code{display:block;background:#fff;border:1px solid #ddd;border-radius:8px;padding:12px;word-break:break-all;font-size:.85rem}'
        . '.r{padding:12px;border-radius:8px;margin:16px 0;font-weight:600}.rok{background:#dafbe1}.rno{background:#ffebe9}</style></head><body>';
    echo '<h1>Conector de correo para Claude</h1>';
    foreach ($filas as [$ok, $t, $d]) {
        echo '<div class="f ' . ($ok ? 'ok' : 'no') . '"><div class="i">' . ($ok ? '✅' : '❌') . '</div><div><div>' . $e($t) . '</div><div class="d">' . $e($d) . '</div></div></div>';
    }
    if ($esquema !== 'https') {
        echo '<div class="r rno">Abre esta página con https:// (activa SSL en tu dominio). Claude solo se conecta por https.</div>';
    }
    if ($todoOk) {
        echo '<div class="r rok">Todo listo. Copia esta URL y pégala en Claude → Configuración → Conectores → Agregar conector personalizado:</div><code>' . $e($url) . '</code>';
    } else {
        echo '<div class="r rno">Corrige los puntos en rojo y recarga esta página.</div>';
    }
    echo '<p class="d">Versión ' . VERSION_CONECTOR . '. No compartas esta URL: contiene tu clave secreta.</p></body></html>';
}

// =====================================================================
//  Página para obtener la URL (pide la contraseña del correo)
// =====================================================================

function archivoIntentos(): string
{
    return __DIR__ . '/.intentos.json';
}

function intentosFallidos(): array
{
    $f = archivoIntentos();
    $d = is_file($f) ? (json_decode((string) @file_get_contents($f), true) ?: []) : [];
    $ahora = time();
    return array_values(array_filter($d, function ($t) use ($ahora) {
        return $t > $ahora - 900;
    }));
}

function paginaAcceso(array $cfg, string $mensaje = ''): void
{
    http_response_code($mensaje === '' ? 200 : 403);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $e = function ($s) {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    };
    $correo = $cfg['correo'] !== '' ? preg_replace('/^(.).*(@.*)$/', '$1•••$2', $cfg['correo']) : '';
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Conector de correo</title>'
        . '<style>body{font-family:system-ui,-apple-system,sans-serif;max-width:420px;margin:40px auto;padding:0 16px;color:#1f2328;background:#fafafa}'
        . 'input,button{width:100%;box-sizing:border-box;font-size:1rem;padding:12px;border-radius:8px;border:1px solid #ccc;margin-top:8px}'
        . 'button{background:#185FA5;color:#fff;border:0;font-weight:600}.m{background:#ffebe9;padding:10px;border-radius:8px;margin:12px 0}.d{color:#666;font-size:.9rem}</style></head><body>'
        . '<h1 style="font-size:1.3rem">Conector de correo para Claude</h1>';
    if ($mensaje !== '') {
        echo '<div class="m">' . $e($mensaje) . '</div>';
    }
    if ($cfg['clave_secreta'] === '') {
        echo '<p>Aún no hay datos de correo en el servidor (config/config.php).</p></body></html>';
        return;
    }
    echo '<form method="post"><p>Para ver la URL del conector, escribe la contraseña del correo <b>' . $e($correo) . '</b>.</p>'
        . '<input type="password" name="contrasena_correo" autocomplete="current-password" required autofocus>'
        . '<button type="submit">Ver mi URL</button></form>'
        . '<p class="d">La contraseña no se guarda; solo se compara con la del servidor.</p></body></html>';
}

// =====================================================================
//  Entrada principal
// =====================================================================

function responder(int $codigo, $cuerpo = null): void
{
    http_response_code($codigo);
    header('Cache-Control: no-store');
    if ($cuerpo !== null) {
        header('Content-Type: application/json; charset=utf-8');
        echo json($cuerpo);
    }
    exit;
}

$clave = (string) ($_GET['k'] ?? '');
if ($clave === '') {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (stripos($auth, 'Bearer ') === 0) {
        $clave = trim(substr($auth, 7));
    }
}
$claveOk = strlen((string) $CFG['clave_secreta']) >= 20 && hash_equals((string) $CFG['clave_secreta'], $clave);

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$tipo = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
$quiereHtml = stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html') !== false;

// Formulario: la contraseña correcta muestra la página de diagnóstico con la URL.
if (!$claveOk && $metodo === 'POST' && strpos($tipo, 'application/x-www-form-urlencoded') === 0) {
    if (count(intentosFallidos()) >= 5) {
        paginaAcceso($CFG, 'Demasiados intentos. Espera 15 minutos.');
        exit;
    }
    $dada = (string) ($_POST['contrasena_correo'] ?? '');
    if ($CFG['contrasena'] !== '' && hash_equals((string) $CFG['contrasena'], $dada)) {
        header('Location: ' . strtok($_SERVER['REQUEST_URI'] ?? '', '?') . '?k=' . rawurlencode($CFG['clave_secreta']), true, 303);
        exit;
    }
    $f = intentosFallidos();
    $f[] = time();
    @file_put_contents(archivoIntentos(), json_encode($f), LOCK_EX);
    sleep(2);
    paginaAcceso($CFG, 'Contraseña incorrecta.');
    exit;
}

if (!$claveOk) {
    if ($metodo === 'GET' && $quiereHtml && $clave === '') {
        paginaAcceso($CFG);
        exit;
    }
    usleep(300000);
    responder(403, ['error' => 'Acceso denegado. Revisa la clave (?k=...) en la URL del conector.']);
}

if ($metodo === 'GET') {
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    if (stripos($accept, 'text/event-stream') !== false && stripos($accept, 'text/html') === false) {
        header('Allow: POST');
        responder(405);
    }
    diagnostico($CFG);
    exit;
}

if ($metodo === 'OPTIONS') {
    header('Allow: POST, GET');
    responder(204);
}

if ($metodo !== 'POST') {
    header('Allow: POST, GET');
    responder(405);
}

$entrada = json_decode((string) file_get_contents('php://input'), true);
if ($entrada === null) {
    responder(400, ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'JSON no válido']]);
}

if (is_array($entrada) && array_keys($entrada) === range(0, count($entrada) - 1) && $entrada !== []) {
    $res = array_values(array_filter(array_map(function ($r) use ($CFG) {
        return rpc($CFG, $r);
    }, $entrada)));
    if ($res) {
        responder(200, $res);
    }
    responder(202);
}

$r = rpc($CFG, $entrada);
if ($r === null) {
    responder(202);
}
responder(200, $r);
