<?php
/**
 * AVBA Certificaciones — Comprobación de servicios externos
 *
 * Antes de confiar en que una integración funciona, probarla. Cada prueba
 * hace una llamada REAL y barata al servicio y traduce lo que conteste a algo
 * accionable: no sirve de nada un "error 400" si quien lo lee no sabe si debe
 * revisar su configuración, esperar, o llamar al proveedor.
 *
 * Las pruebas son de SÓLO LECTURA: ninguna emite un documento, manda un
 * correo a un cliente ni gasta un timbre fiscal. Se pueden correr cuantas
 * veces haga falta.
 *
 * NUNCA se devuelve una clave, ni entera ni en parte: sólo si está puesta y
 * qué contestó el servicio. Esta pantalla la ve un administrador, pero
 * tampoco a un administrador hace falta enseñarle la clave en pantalla.
 */
class Diagnostico {
    private PDO $pdo;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    /** Corre todas y devuelve una por servicio. */
    public function todo(): array {
        return ['status' => 'success', 'data' => [
            $this->gemini(),
            $this->claude(),
            $this->facturapi(),
            $this->smtp(),
            $this->baseDatos(),
            $this->almacenamiento(),
        ], 'fecha' => date('d/m/Y H:i')];
    }

    public function uno(string $cual): array {
        $m = ['gemini','claude','facturapi','smtp','basedatos','almacenamiento'];
        $cual = strtolower(trim($cual));
        if (!in_array($cual, $m, true))
            return ['status' => 'error', 'message' => 'Servicio desconocido.'];
        $fn = $cual === 'basedatos' ? 'baseDatos' : ($cual === 'almacenamiento' ? 'almacenamiento' : $cual);
        return ['status' => 'success', 'data' => [$this->$fn()], 'fecha' => date('d/m/Y H:i')];
    }

    /**
     * @param string $estado ok | aviso | error | apagado
     *        'apagado' no es un fallo: el servicio no está configurado y el
     *        sistema funciona sin él. Mezclarlo con 'error' haría que quien
     *        mira la pantalla persiguiera un problema que no existe.
     */
    private static function r(string $clave, string $nombre, string $estado,
                              string $detalle, string $queHacer = '', $ms = null): array {
        return ['clave' => $clave, 'nombre' => $nombre, 'estado' => $estado,
                'detalle' => $detalle, 'que_hacer' => $queHacer, 'ms' => $ms];
    }

    private static function definida(string $c): bool {
        return defined($c) && trim((string)constant($c)) !== '';
    }

    /** Una petición mínima, sin adjuntos ni imágenes: sólo para ver si responde. */
    private function http(string $url, array $cabeceras, ?string $cuerpo = null, int $tope = 15): array {
        $t0 = microtime(true);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $cabeceras,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => $tope,
        ]);
        if ($cuerpo !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $cuerpo);
        }
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        return [$code, (string)$resp, $err, (int)round((microtime(true) - $t0) * 1000)];
    }

    // ── Gemini ────────────────────────────────────────────
    public function gemini(): array {
        $n = 'Gemini (Google)';
        if (!self::definida('GEMINI_API_KEY'))
            return self::r('gemini', $n, 'apagado', 'No configurado.',
                'Agrega GEMINI_API_KEY en config/config.php del servidor. Sin ella, la lectura de documentos se hace a mano.');
        if (!function_exists('curl_init'))
            return self::r('gemini', $n, 'error', 'El servidor no tiene cURL.', 'Actívalo en hPanel.');

        // El mismo modelo que usará la aplicación, incluido el que se haya
        // descubierto solo: probar otro distinto no comprobaría nada.
        $modelo = (new VerificacionIA($this->pdo))->modeloActivo();

        // Se le pide que conteste una palabra: la llamada más barata que
        // comprueba la llave, el modelo y la cuota de una sola vez.
        [$code, $resp, $cerr, $ms] = $this->http(
            'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($modelo) . ':generateContent',
            ['Content-Type: application/json', 'x-goog-api-key: ' . trim((string)GEMINI_API_KEY)],
            json_encode(['contents' => [['parts' => [['text' => 'Responde solo: ok']]]],
                         'generationConfig' => ['temperature' => 0, 'maxOutputTokens' => 5]])
        );

        if ($code === 200) {
            return self::r('gemini', $n, 'ok', 'Responde correctamente con el modelo ' . $modelo . '.', '', $ms);
        }
        $motivo = VerificacionIA::motivoGemini($code, $resp, $cerr);

        if ($code === 404) {
            // 404 quiere decir que ese modelo no existe para esta clave. Los
            // nombres cambian y los viejos se retiran, así que en vez de
            // mandar a adivinar se pregunta a Google cuáles hay AHORA.
            $hay = $this->modelosGemini();
            $hacer = $hay
                ? 'Pon uno de estos en GEMINI_MODEL dentro de config/config.php: '
                  . implode(', ', array_slice($hay, 0, 6)) . '.'
                : 'Revisa GEMINI_MODEL en config/config.php. No se pudo obtener la lista de modelos disponibles.';
            return self::r('gemini', $n, 'error',
                'El modelo "' . $modelo . '" no existe o no está disponible para tu clave.', $hacer, $ms);
        }

        $hacer = $code === 429
            ? 'Es la cuota del día o del minuto. Espera y vuelve a probar; si se repite, revisa los límites de tu proyecto en Google AI Studio.'
            : 'Revisa que GEMINI_API_KEY esté bien pegada en config/config.php, sin espacios ni comillas de más.';
        return self::r('gemini', $n, $code === 429 ? 'aviso' : 'error',
                       'Gemini ' . $motivo . '.', $hacer, $ms);
    }

    /**
     * Los modelos que ESTA clave puede usar hoy, según Google.
     *
     * Se filtran los que sirven para generar contenido: la lista trae también
     * modelos de embeddings y de imagen, que no valen para lo que hacemos y
     * sólo confundirían a quien tenga que elegir uno.
     *
     * @return string[] nombres cortos, listos para pegar en GEMINI_MODEL
     */
    public function modelosGemini(): array {
        if (!self::definida('GEMINI_API_KEY')) return [];
        [$code, $resp] = $this->http(
            'https://generativelanguage.googleapis.com/v1beta/models?pageSize=200',
            ['x-goog-api-key: ' . trim((string)GEMINI_API_KEY)]
        );
        if ($code !== 200) return [];

        $datos = json_decode($resp, true);
        if (!is_array($datos['models'] ?? null)) return [];

        $utiles = [];
        foreach ($datos['models'] as $m) {
            $metodos = $m['supportedGenerationMethods'] ?? $m['supportedActions'] ?? [];
            if (!in_array('generateContent', (array)$metodos, true)) continue;
            $nombre = preg_replace('#^models/#', '', (string)($m['name'] ?? ''));
            // Se filtra con la MISMA regla que usa el sistema para elegir: si
            // aquí se sugiriera uno que allá se descarta —un modelo de texto a
            // voz, por ejemplo— se estaría recomendando algo que no funciona.
            if ($nombre === '' || !VerificacionIA::sirveParaLeer($nombre)) continue;
            $utiles[] = $nombre;
        }
        $utiles = array_values(array_unique($utiles));
        usort($utiles, fn($a, $b) => VerificacionIA::prioridadModelo($a) <=> VerificacionIA::prioridadModelo($b)
                                  ?: strcmp($a, $b));
        return $utiles;
    }

    // ── Claude ────────────────────────────────────────────
    public function claude(): array {
        $n = 'Claude (Anthropic)';
        if (!self::definida('CLAUDE_API_KEY'))
            return self::r('claude', $n, 'apagado', 'No configurado.',
                'Agrega CLAUDE_API_KEY en config/config.php. Sin ella, la redacción automática de propuestas queda deshabilitada.');

        $modelo = defined('CLAUDE_MODEL') && trim((string)CLAUDE_MODEL) !== ''
            ? trim((string)CLAUDE_MODEL) : 'claude-opus-5';

        [$code, $resp, $cerr, $ms] = $this->http(
            'https://api.anthropic.com/v1/messages',
            ['Content-Type: application/json', 'anthropic-version: 2023-06-01',
             'x-api-key: ' . trim((string)CLAUDE_API_KEY)],
            json_encode(['model' => $modelo, 'max_tokens' => 5,
                         'messages' => [['role' => 'user', 'content' => 'Responde solo: ok']]])
        );

        if ($code === 200)
            return self::r('claude', $n, 'ok', 'Responde correctamente con el modelo ' . $modelo . '.', '', $ms);

        $det = json_decode($resp, true)['error']['message'] ?? '';
        if ($code === 401 || $code === 403)
            return self::r('claude', $n, 'error', 'Rechazó la clave: no es válida o fue revocada.',
                'Revisa CLAUDE_API_KEY en config/config.php.', $ms);
        if ($code === 429)
            return self::r('claude', $n, 'aviso', 'Se alcanzó el límite de peticiones.',
                'Espera unos minutos y vuelve a probar.', $ms);
        if ($code === 404)
            return self::r('claude', $n, 'error', 'El modelo "' . $modelo . '" no existe o no está disponible para tu cuenta.',
                'Revisa CLAUDE_MODEL en config/config.php, o déjalo vacío.', $ms);
        if ($code === 0)
            return self::r('claude', $n, 'error', 'No se pudo conectar' . ($cerr ? ': ' . $cerr : '') . '.',
                'Puede ser la red del servidor o un bloqueo de salida.', $ms);
        return self::r('claude', $n, 'error', 'Devolvió HTTP ' . $code . ($det ? ' — ' . $det : '') . '.',
            'Si se repite, revisa el estado del servicio.', $ms);
    }

    // ── Facturapi ─────────────────────────────────────────
    public function facturapi(): array {
        $n = 'Facturapi (facturación)';
        if (!self::definida('FACTURAPI_KEY'))
            return self::r('facturapi', $n, 'apagado', 'No configurado.',
                'Agrega FACTURAPI_KEY en config/config.php. Sin ella no se pueden timbrar facturas.');

        // Se listan clientes con límite 1: lectura pura, no gasta timbres.
        [$code, $resp, $cerr, $ms] = $this->http(
            'https://www.facturapi.io/v2/customers?limit=1',
            ['Authorization: Basic ' . base64_encode(trim((string)FACTURAPI_KEY) . ':')]
        );

        if ($code === 200)
            return self::r('facturapi', $n, 'ok', 'Responde correctamente.', '', $ms);
        if ($code === 401)
            return self::r('facturapi', $n, 'error', 'Rechazó la clave.',
                'Revisa FACTURAPI_KEY. Ojo: la de pruebas y la de producción son distintas.', $ms);
        if ($code === 402)
            // La clave es buena: lo que falta es saldo. Decir "error de clave"
            // mandaría a revisar config.php para nada.
            return self::r('facturapi', $n, 'aviso',
                'La clave es válida, pero la cuenta no tiene plan activo o se agotaron los timbres.',
                'Entra a tu panel de Facturapi y revisa la suscripción. La facturación no funcionará hasta entonces.', $ms);
        if ($code === 0)
            return self::r('facturapi', $n, 'error', 'No se pudo conectar' . ($cerr ? ': ' . $cerr : '') . '.',
                'Puede ser la red del servidor.', $ms);
        return self::r('facturapi', $n, 'error', 'Devolvió HTTP ' . $code . '.', '', $ms);
    }

    // ── Correo saliente ───────────────────────────────────
    public function smtp(): array {
        $n = 'Correo saliente (SMTP)';
        if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            $a = __DIR__ . '/../vendor/autoload.php';
            if (file_exists($a)) require_once $a;
        }
        $clase = 'PHPMailer\\PHPMailer\\PHPMailer';
        if (!class_exists($clase))
            return self::r('smtp', $n, 'error', 'PHPMailer no está instalado.',
                'Falta vendor/ en el servidor.');

        $t0 = microtime(true);
        try {
            $mail = new $clase(true);
            configurarMailer($mail, $this->pdo);
            // Se abre la conexión y se cierra: NO se manda ningún correo.
            if (!$mail->smtpConnect())
                return self::r('smtp', $n, 'error', 'No se pudo conectar al servidor de correo.',
                    'Revisa host, puerto y contraseña en Admin → Correo SMTP.',
                    (int)round((microtime(true) - $t0) * 1000));
            $mail->smtpClose();
            $host = (string)($mail->Host ?? '');
            return self::r('smtp', $n, 'ok',
                'Conecta y autentica correctamente' . ($host ? ' con ' . $host : '') . '.',
                '', (int)round((microtime(true) - $t0) * 1000));
        } catch (\Throwable $e) {
            return self::r('smtp', $n, 'error', 'Falló: ' . $e->getMessage(),
                'Revisa la configuración en Admin → Correo SMTP.',
                (int)round((microtime(true) - $t0) * 1000));
        }
    }

    // ── Base de datos ─────────────────────────────────────
    public function baseDatos(): array {
        $n = 'Base de datos';
        $t0 = microtime(true);
        try {
            $this->pdo->query('SELECT 1')->fetchColumn();
            $v = $this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
            return self::r('basedatos', $n, 'ok', 'Responde. Versión ' . $v . '.', '',
                           (int)round((microtime(true) - $t0) * 1000));
        } catch (\Throwable $e) {
            return self::r('basedatos', $n, 'error', 'No responde: ' . $e->getMessage(),
                'Revisa las credenciales en config/config.php.');
        }
    }

    // ── Carpeta de documentos ─────────────────────────────
    public function almacenamiento(): array {
        $n = 'Carpeta de documentos';
        if (!defined('UPLOAD_DIR'))
            return self::r('almacenamiento', $n, 'error', 'UPLOAD_DIR no está definido.',
                'Revisa config/config.php.');
        $dir = rtrim(UPLOAD_DIR, '/') . '/';
        if (!is_dir($dir))
            return self::r('almacenamiento', $n, 'error', 'La carpeta no existe: ' . $dir,
                'Créala en el servidor.');
        if (!is_writable($dir))
            return self::r('almacenamiento', $n, 'error', 'No se puede escribir en ' . $dir,
                'Revisa los permisos de la carpeta (755).');

        // El espacio libre importa: sin él los PDF se generan vacíos y el
        // fallo aparece mucho después, al abrir un documento roto.
        $libre = @disk_free_space($dir);
        if ($libre !== false && $libre < 100 * 1024 * 1024)
            return self::r('almacenamiento', $n, 'aviso',
                'Quedan ' . round($libre / 1048576) . ' MB libres.',
                'Con poco espacio los PDF pueden salir incompletos. Conviene liberar.');

        return self::r('almacenamiento', $n, 'ok',
            'Escribible' . ($libre !== false ? ', ' . round($libre / 1073741824, 1) . ' GB libres' : '') . '.');
    }
}
