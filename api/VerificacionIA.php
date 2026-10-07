<?php
/**
 * AVBA Certificaciones — Verificación de identidad con IA (Gemini o Claude)
 *
 * Calidad revisa participantes que se auto-registraron: escribieron su nombre
 * y CURP, y subieron una foto de su identificación oficial. Este módulo manda
 * ambas cosas al modelo para que COMPARE lo capturado contra el documento y
 * devuelva un semáforo:
 *
 *   coincide    (verde)    — los datos capturados corresponden al documento
 *   no_coincide (rojo)     — hay diferencias respecto al documento
 *   ilegible    (amarillo) — no se pudo leer el documento con confianza
 *
 * Lee con Gemini o con Claude, el que esté configurado. Si están los dos, va
 * primero Gemini —más rápido y barato para leer una credencial— y si falla por
 * cuota o por red, lo reintenta Claude en lugar de dejar al revisor sin
 * respuesta. Queda constancia de qué modelo contestó: si alguien pregunta más
 * adelante por qué se aprobó una identidad, hay que poder decir quién la leyó.
 *
 * Es una AYUDA para el revisor, no un reemplazo: la decisión final (aprobar o
 * devolver) la sigue tomando Calidad. Si no hay ninguna API key configurada o
 * los dos servicios fallan, el flujo continúa igual y se valida a mano.
 */
class VerificacionIA {
    private PDO $pdo;

    /**
     * Modelo de visión por defecto: rápido y económico, suficiente para leer
     * una credencial. Se puede cambiar sin tocar código definiendo
     * GEMINI_MODEL en config/config.php (por ejemplo 'gemini-2.5-pro' si se
     * necesita más precisión en documentos difíciles).
     */
    private const MODELO_DEFAULT = 'gemini-2.5-flash';
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/';

    /**
     * Qué modelo usar.
     *
     * Manda GEMINI_MODEL si está puesto: si alguien eligió uno a propósito,
     * no se le cambia por detrás. Si no, se usa el que se haya descubierto y
     * guardado; y si tampoco hay, el predeterminado.
     *
     * Hace falta porque Google RETIRA nombres de modelo. Tener uno escrito a
     * fuego significa que el día que lo jubilen, la lectura de documentos deja
     * de funcionar con un 404 aunque la clave esté perfecta — que es
     * exactamente lo que pasó.
     */
    protected function modelo(): string {
        $m = defined('GEMINI_MODEL') ? trim((string)GEMINI_MODEL) : '';
        if ($m !== '') return $m;
        $guardado = $this->modeloGuardado();
        return $guardado !== '' ? $guardado : self::MODELO_DEFAULT;
    }

    /**
     * ¿Sirve este modelo para lo que hacemos: leer texto e imágenes?
     *
     * La lista de Google trae de todo. Entre los "flash" vienen modelos de
     * texto a voz (gemini-...-flash-tts), de generación de imágenes y de
     * embeddings: elegir uno de ésos para leer una credencial falla, y el
     * fallo aparece tarde y mal explicado.
     *
     * También se descartan los "preview" y "exp": cambian y desaparecen sin
     * aviso, que es justo el problema que se está resolviendo.
     */
    public static function sirveParaLeer(string $nombre): bool {
        foreach (['tts', 'audio', 'image-generation', 'imagen', 'embedding',
                  'aqa', 'preview', '-exp'] as $malo) {
            if (str_contains($nombre, $malo)) return false;
        }
        return true;
    }

    /**
     * De mejor a peor, para elegir uno sin tener que adivinar versiones.
     *
     * Google publica alias "-latest" precisamente para que nadie fije un
     * número de versión que mañana se retira. Son la primera opción.
     */
    public static function prioridadModelo(string $n): int {
        if ($n === 'gemini-flash-latest')         return 0;
        if (str_ends_with($n, '-flash-latest'))   return 1;
        if (str_contains($n, 'flash') && str_contains($n, 'latest')) return 2;
        if (str_contains($n, 'flash'))            return 3;
        if (str_contains($n, 'latest'))           return 4;
        return 5;
    }

    /** El modelo que se usará de verdad. Lo consulta la pantalla de servicios. */
    public function modeloActivo(): string { return $this->modelo(); }

    /**
     * Busca un modelo vigente y lo deja guardado. Lo usa el botón de la
     * pantalla de servicios: diagnosticar sin poder arreglar obliga a entrar
     * por FTP a editar config.php por algo que el sistema sabe resolver.
     */
    public function repararModelo(): array {
        if (!$this->hayGemini())
            return ['status' => 'error', 'message' => 'No hay GEMINI_API_KEY configurada.'];
        if (defined('GEMINI_MODEL') && trim((string)GEMINI_MODEL) !== '')
            return ['status' => 'error', 'message' =>
                'GEMINI_MODEL está fijado a mano en config/config.php. Déjalo vacío para que el '
              . 'sistema elija uno vigente, o cámbialo por uno de la lista.'];

        $nuevo = $this->descubrirModelo();
        if ($nuevo === '')
            return ['status' => 'error', 'message' =>
                'Google no devolvió ningún modelo que sirva para leer documentos.'];

        $this->guardarModelo($nuevo);
        return ['status' => 'success', 'modelo' => $nuevo,
                'message' => 'Ahora se usará ' . $nuevo . '.'];
    }

    private function modeloGuardado(): string {
        try {
            $st = $this->pdo->prepare("SELECT valor FROM avba_config WHERE clave = 'gemini_modelo'");
            $st->execute();
            return trim((string)($st->fetchColumn() ?: ''));
        } catch (\Throwable $e) {
            return '';
        }
    }

    protected function guardarModelo(string $modelo): void {
        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS avba_config (
                clave VARCHAR(60) NOT NULL PRIMARY KEY, valor TEXT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $this->pdo->prepare(
                "INSERT INTO avba_config (clave, valor) VALUES ('gemini_modelo', ?)
                 ON DUPLICATE KEY UPDATE valor = VALUES(valor)"
            )->execute([$modelo]);
        } catch (\Throwable $e) {
            error_log('[VerificacionIA] guardar modelo: ' . $e->getMessage());
        }
    }

    /**
     * Busca un modelo que esta clave sí pueda usar.
     *
     * Se le pregunta a Google en vez de llevar una lista nuestra, que volvería
     * a quedarse vieja. Se prefieren los "flash": son los de la cuota gratuita
     * y sobran para leer una credencial o una placa.
     */
    protected function descubrirModelo(): string {
        if (!$this->hayGemini()) return '';
        $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models?pageSize=200');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['x-goog-api-key: ' . trim((string)GEMINI_API_KEY)],
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 20,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) return '';

        $datos = json_decode((string)$resp, true);
        $candidatos = [];
        foreach (($datos['models'] ?? []) as $m) {
            $metodos = $m['supportedGenerationMethods'] ?? $m['supportedActions'] ?? [];
            if (!in_array('generateContent', (array)$metodos, true)) continue;
            $nombre = preg_replace('#^models/#', '', (string)($m['name'] ?? ''));
            if ($nombre === '' || !self::sirveParaLeer($nombre)) continue;
            $candidatos[] = $nombre;
        }
        if (!$candidatos) return '';
        usort($candidatos, fn($a, $b) => self::prioridadModelo($a) <=> self::prioridadModelo($b)
                                      ?: strcmp($a, $b));
        return $candidatos[0];
    }

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->migrate();
    }

    private function migrate(): void {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS participantes_verificacion (
                  participante_id INT PRIMARY KEY,
                  resultado       VARCHAR(20) NOT NULL,
                  nombre_doc      VARCHAR(200) NULL,
                  curp_doc        VARCHAR(30)  NULL,
                  detalle         TEXT NULL,
                  verificado_por  VARCHAR(120) NULL,
                  motor           VARCHAR(40)  NULL,
                  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\PDOException $e) {
            error_log('[VerificacionIA] migrate: ' . $e->getMessage());
        }
        // La tabla puede venir de antes de que hubiera dos motores. Se consulta
        // information_schema porque ADD COLUMN IF NOT EXISTS sólo existe en
        // MariaDB, y un fallo aquí dejaría la pantalla de Calidad sin abrir.
        try {
            $hay = (int)$this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = 'participantes_verificacion'
                   AND COLUMN_NAME  = 'motor'"
            )->fetchColumn();
            if (!$hay) {
                $this->pdo->exec(
                    "ALTER TABLE participantes_verificacion ADD COLUMN motor VARCHAR(40) NULL"
                );
            }
        } catch (\Throwable $e) {
            error_log('[VerificacionIA] columna motor: ' . $e->getMessage());
        }
    }

    /** Hay con qué leer si está configurado cualquiera de los dos modelos. */
    public function disponible(): bool {
        return $this->hayGemini() || $this->hayClaude();
    }

    protected function hayGemini(): bool {
        return defined('GEMINI_API_KEY') && trim((string)GEMINI_API_KEY) !== '';
    }

    protected function hayClaude(): bool {
        return defined('CLAUDE_API_KEY') && trim((string)CLAUDE_API_KEY) !== '';
    }

    /** Devuelve la verificación guardada de un participante, o null. */
    public function obtener(int $id): ?array {
        try {
            // La columna motor puede no existir en una base sin migrar; nombrarla
            // a secas rompería la consulta y Calidad no vería la verificación
            // que sí está guardada.
            $motor = (function_exists('columnaExiste')
                && columnaExiste($this->pdo, 'participantes_verificacion', 'motor'))
                ? 'motor' : "'' AS motor";
            $s = $this->pdo->prepare(
                "SELECT resultado, nombre_doc, curp_doc, detalle, $motor,
                        DATE_FORMAT(created_at,'%d/%m/%Y %H:%i') AS fecha
                 FROM participantes_verificacion WHERE participante_id = ?"
            );
            $s->execute([$id]);
            return $s->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (\PDOException $e) {
            return null;
        }
    }

    /**
     * Compara los datos capturados por el participante contra su identificación.
     * @param int $id Participante
     */
    public function verificar(int $id, string $usuario): array {
        if (!$this->disponible()) {
            return ['status' => 'error',
                'message' => 'La lectura con IA no está configurada en el servidor: '
                           . 'falta GEMINI_API_KEY o CLAUDE_API_KEY en config/config.php.'];
        }
        if (!function_exists('curl_init')) {
            return ['status' => 'error', 'message' => 'El servidor no tiene cURL disponible.'];
        }

        $s = $this->pdo->prepare(
            "SELECT nombre_completo, curp, foto_documentacion_url
             FROM participantes_cursos WHERE id = ?"
        );
        $s->execute([$id]);
        $p = $s->fetch(PDO::FETCH_ASSOC);
        if (!$p) return ['status' => 'error', 'message' => 'Participante no encontrado.'];

        [$mime, $bytes, $errImg] = $this->imagenDe((string)($p['foto_documentacion_url'] ?? ''));
        if ($errImg !== '') return ['status' => 'error', 'message' => $errImg];

        $nombreCap = trim((string)$p['nombre_completo']);
        $curpCap   = strtoupper(trim((string)$p['curp']));

        $prompt =
            "Eres un verificador de identidad. Te doy una imagen de un documento oficial de identidad mexicano " .
            "(INE, pasaporte o constancia de CURP) y los datos que una persona capturó al registrarse.\n\n" .
            "DATOS CAPTURADOS POR LA PERSONA:\n" .
            "- Nombre completo: \"{$nombreCap}\"\n" .
            "- CURP: \"{$curpCap}\"\n\n" .
            "TAREA: lee el documento y compáralo con los datos capturados.\n" .
            "Criterios:\n" .
            "- Ignora diferencias por acentos, mayúsculas/minúsculas y el ORDEN de nombre y apellidos.\n" .
            "- Las abreviaturas razonables del nombre se consideran coincidencia.\n" .
            "- La CURP debe coincidir carácter por carácter si es legible en el documento.\n" .
            "- Si el documento no muestra CURP, evalúa solo el nombre.\n\n" .
            "Responde:\n" .
            "- resultado = \"coincide\" si los datos corresponden a la persona del documento.\n" .
            "- resultado = \"no_coincide\" si hay diferencias reales (otra persona, nombre distinto o CURP distinta).\n" .
            "- resultado = \"ilegible\" si la imagen no permite leer los datos con confianza, no es un documento de identidad, o está muy borrosa/cortada.\n" .
            "- nombre_doc y curp_doc: lo que leíste EN EL DOCUMENTO (cadena vacía si no se lee).\n" .
            "- detalle: una frase breve en español explicando el porqué.";

        [$out, $motor, $error] = $this->analizar($prompt, $mime, $bytes);
        if ($out === null) return ['status' => 'error', 'message' => $error];

        $resultado = in_array($out['resultado'], ['coincide', 'no_coincide', 'ilegible'], true)
            ? $out['resultado'] : 'ilegible';
        $nombreDoc = mb_substr(trim((string)($out['nombre_doc'] ?? '')), 0, 200);
        $curpDoc   = mb_substr(strtoupper(trim((string)($out['curp_doc'] ?? ''))), 0, 30);
        $detalle   = mb_substr(trim((string)($out['detalle'] ?? '')), 0, 1000);

        try {
            $this->pdo->prepare("
                INSERT INTO participantes_verificacion
                  (participante_id, resultado, nombre_doc, curp_doc, detalle, verificado_por, motor)
                VALUES (?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE resultado=VALUES(resultado), nombre_doc=VALUES(nombre_doc),
                  curp_doc=VALUES(curp_doc), detalle=VALUES(detalle),
                  verificado_por=VALUES(verificado_por), motor=VALUES(motor)
            ")->execute([$id, $resultado, $nombreDoc ?: null, $curpDoc ?: null, $detalle ?: null, $usuario, $motor]);
        } catch (\Throwable $e) {
            // Si la columna motor no llegó a crearse, se guarda sin ella antes
            // que perder la verificación que el revisor acaba de pedir.
            error_log('[VerificacionIA] guardado con motor: ' . $e->getMessage());
            $this->pdo->prepare("
                INSERT INTO participantes_verificacion
                  (participante_id, resultado, nombre_doc, curp_doc, detalle, verificado_por)
                VALUES (?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE resultado=VALUES(resultado), nombre_doc=VALUES(nombre_doc),
                  curp_doc=VALUES(curp_doc), detalle=VALUES(detalle), verificado_por=VALUES(verificado_por)
            ")->execute([$id, $resultado, $nombreDoc ?: null, $curpDoc ?: null, $detalle ?: null, $usuario]);
        }

        return [
            'status'     => 'success',
            'resultado'  => $resultado,
            'nombre_doc' => $nombreDoc,
            'curp_doc'   => $curpDoc,
            'detalle'    => $detalle,
            'motor'      => $motor,
            'capturado'  => ['nombre' => $nombreCap, 'curp' => $curpCap],
        ];
    }

    /**
     * Manda el documento al modelo y devuelve [datos, motor, error].
     *
     * Gemini primero cuando está: leer una credencial no necesita más y cuesta
     * menos. Si no está configurado, o contesta con un error de servicio —cuota,
     * red, respuesta ilegible—, lo intenta Claude. Un "no_coincide" NO es un
     * fallo: es una respuesta, y no se reintenta con el otro modelo, porque
     * preguntar dos veces hasta que uno diga que sí no es verificar.
     *
     * @return array{0:?array,1:string,2:string}
     */
    private function analizar(string $prompt, string $mime, string $bytes): array {
        $errores = [];

        if ($this->hayGemini()) {
            [$out, $err] = $this->conGemini($prompt, $mime, $bytes);
            if ($out !== null) return [$out, 'gemini:' . $this->modelo(), ''];
            $errores[] = 'Gemini: ' . $err;
        }

        if ($this->hayClaude()) {
            [$out, $err, $modelo] = $this->conClaude($prompt, $mime, $bytes);
            if ($out !== null) return [$out, 'claude:' . $modelo, ''];
            $errores[] = 'Claude: ' . $err;
        }

        error_log('[VerificacionIA] sin lectura: ' . implode(' | ', $errores));
        // Al revisor se le dice qué hacer, no la traza: eso va al log.
        return [null, '', count($errores) > 1
            ? 'Ninguno de los dos servicios de lectura respondió. Revisa la identificación a mano.'
            : ($errores[0] ?? 'No se pudo leer la identificación.')];
    }

    /**
     * Qué salió mal, en términos de quien tiene que arreglarlo.
     *
     * Antes todo lo que no fuera 429 decía "no contestó (HTTP 400)", que
     * suena a problema de red cuando casi siempre es la llave mal pegada en
     * config.php. Quien lee ese mensaje tiene que saber si llamar al
     * proveedor, esperar, o revisar su propia configuración.
     */
    public static function motivoGemini(int $code, string $cuerpo = '', string $cerr = ''): string {
        // Gemini manda 400 con API_KEY_INVALID cuando la llave está mal
        // escrita, y 403 cuando es válida pero no tiene permiso.
        if ($code === 400 && stripos($cuerpo, 'API_KEY_INVALID') !== false)
            return 'la clave GEMINI_API_KEY no es válida (revísala en config/config.php)';
        switch ($code) {
            case 0:   return 'no se pudo conectar' . ($cerr !== '' ? ': ' . $cerr : '');
            case 400: return 'rechazó la petición (HTTP 400)';
            case 401:
            case 403: return 'rechazó la clave: no es válida, fue revocada o no tiene permiso';
            case 404: return 'el modelo configurado en GEMINI_MODEL no existe';
            case 429: return 'alcanzó su límite de uso (cuota agotada)';
            case 500:
            case 503: return 'el servicio está saturado; inténtalo en unos minutos';
            default:  return 'no contestó (HTTP ' . $code . ')';
        }
    }

    /** @return array{0:?array,1:string} [datos, error] */
    protected function conGemini(string $prompt, string $mime, string $bytes, bool $reintento = false): array {
        $payload = [
            'contents' => [[
                'parts' => [
                    ['text' => $prompt],
                    ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($bytes)]],
                ],
            ]],
            'generationConfig' => [
                'temperature'      => 0,
                'responseMimeType' => 'application/json',
                'responseSchema'   => [
                    'type'       => 'OBJECT',
                    'properties' => [
                        'resultado'  => ['type' => 'STRING', 'enum' => ['coincide', 'no_coincide', 'ilegible']],
                        'nombre_doc' => ['type' => 'STRING'],
                        'curp_doc'   => ['type' => 'STRING'],
                        'detalle'    => ['type' => 'STRING'],
                    ],
                    'required'   => ['resultado', 'detalle'],
                ],
            ],
        ];

        $ch = curl_init(self::ENDPOINT . $this->modelo() . ':generateContent');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . trim((string)GEMINI_API_KEY),
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 60,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($code === 404 && !$reintento) {
            // El modelo ya no existe. Se busca uno vigente, se guarda para las
            // próximas veces y se reintenta UNA vez: así un nombre retirado no
            // deja el sistema parado hasta que alguien edite config.php.
            $nuevo = $this->descubrirModelo();
            if ($nuevo !== '' && $nuevo !== $this->modelo()) {
                error_log('[VerificacionIA] modelo ' . $this->modelo() . ' no existe; se usa ' . $nuevo);
                $this->guardarModelo($nuevo);
                return $this->conGemini($prompt, $mime, $bytes, true);
            }
        }
        if ($resp === false || $code !== 200) {
            error_log('[VerificacionIA] Gemini HTTP ' . $code . ' ' . $cerr . ' ' . substr((string)$resp, 0, 500));
            return [null, self::motivoGemini($code, (string)$resp, $cerr)];
        }

        $data = json_decode((string)$resp, true);
        $txt  = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $out  = json_decode($txt, true);
        if (!is_array($out) || empty($out['resultado'])) {
            error_log('[VerificacionIA] Gemini no interpretable: ' . substr($txt, 0, 500));
            return [null, 'devolvió una respuesta que no se pudo interpretar'];
        }
        return [$out, ''];
    }

    /**
     * Lo mismo con Claude. No tiene esquema de salida forzado como Gemini, así
     * que el JSON se le pide en el enunciado y se rescata del texto: el modelo
     * puede envolverlo en un bloque de código.
     *
     * @return array{0:?array,1:string,2:string} [datos, error, modelo]
     */
    protected function conClaude(string $prompt, string $mime, string $bytes): array {
        if (!class_exists('ClaudeIA')) {
            $ruta = __DIR__ . '/ClaudeIA.php';
            if (is_file($ruta)) require_once $ruta;
        }
        if (!class_exists('ClaudeIA')) return [null, 'no está instalado en el servidor', ''];

        $ia = new ClaudeIA();
        $sistema = 'Eres un verificador de identidad de una unidad de inspección acreditada. '
                 . 'Respondes ÚNICAMENTE con un objeto JSON, sin texto alrededor y sin bloque de código, '
                 . 'con estas claves exactas: resultado (uno de: "coincide", "no_coincide", "ilegible"), '
                 . 'nombre_doc (string), curp_doc (string), detalle (string, una frase breve en español).';

        $r = $ia->mensaje($sistema, $prompt, [[
            'tipo'       => 'imagen',
            'media_type' => $mime,
            'datos'      => base64_encode($bytes),
        ]]);
        $modelo = (string)($r['modelo'] ?? $ia->modelo());
        if (($r['status'] ?? '') !== 'success') {
            return [null, (string)($r['message'] ?? 'no contestó'), $modelo];
        }

        $txt = trim((string)($r['texto'] ?? ''));
        $out = json_decode($txt, true);
        if (!is_array($out)) {
            // Rescatar el objeto si vino envuelto en explicación o en ```json.
            if (preg_match('/\{.*\}/s', $txt, $m)) $out = json_decode($m[0], true);
        }
        if (!is_array($out) || empty($out['resultado'])) {
            error_log('[VerificacionIA] Claude no interpretable: ' . substr($txt, 0, 500));
            return [null, 'devolvió una respuesta que no se pudo interpretar', $modelo];
        }
        return [$out, '', $modelo];
    }

    /**
     * Carga la identificación desde el disco. Devuelve [mime, bytes, error];
     * con error !== '' los otros dos no sirven.
     *
     * Va aparte porque verificar() ya hacía tres cosas —buscar el participante,
     * cargar su imagen y juzgarla— y porque así se puede probar el despacho
     * entre modelos sin necesitar un archivo real en el disco.
     */
    protected function imagenDe(string $rel): array {
        $rel = trim($rel);
        if ($rel === '') return ['', '', 'El participante no tiene identificación adjunta.'];

        // La URL guardada puede venir absoluta; lo que se necesita es la ruta local.
        $rel = preg_replace('#^https?://[^/]+/#i', '', $rel);
        $abs = dirname(__DIR__) . '/' . ltrim($rel, '/');
        if (!is_file($abs)) return ['', '', 'No se encontró el archivo de la identificación en el servidor.'];

        $mime = $this->mimeDe($abs);
        if ($mime === '') {
            return ['', '', 'La identificación debe ser una imagen (JPG o PNG). Si es PDF, súbela como foto.'];
        }
        $bytes = @file_get_contents($abs);
        if ($bytes === false || $bytes === '') return ['', '', 'No se pudo leer la imagen de la identificación.'];
        if (strlen($bytes) > 15 * 1024 * 1024) {
            return ['', '', 'La imagen es demasiado grande para verificarla.'];
        }
        return [$mime, $bytes, ''];
    }

    /** MIME de la imagen a partir de su contenido; '' si no es imagen soportada. */
    protected function mimeDe(string $path): string {
        $info = @getimagesize($path);
        $tipo = $info[2] ?? null;
        return match ($tipo) {
            IMAGETYPE_JPEG => 'image/jpeg',
            IMAGETYPE_PNG  => 'image/png',
            IMAGETYPE_WEBP => 'image/webp',
            default        => '',
        };
    }
}
