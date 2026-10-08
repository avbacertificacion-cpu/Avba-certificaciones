<?php
/**
 * AVBA Certificaciones — Lector de documentos con IA
 *
 * Recibe una o varias fotos y un esquema de lo que hay que sacar, y devuelve
 * esos campos. Gemini primero —rápido y barato para leer una placa o una
 * etiqueta— y Claude de respaldo si Gemini falla por cuota o por red.
 *
 * LO QUE NO SE PUEDE LEER, VUELVE VACÍO. Nunca se rellena a ojo: un número de
 * serie inventado es peor que un campo en blanco, porque nadie lo revisa.
 * Por eso cada campo viene con lo que el modelo dice haber visto y qué tan
 * seguro está, y lo que no alcanza el mínimo se devuelve vacío y marcado.
 *
 * Esto NO decide nada: propone. Quien captura confirma y firma.
 */
class LectorIA {
    private PDO $pdo;

    /** Debajo de esto, el campo se devuelve vacío y marcado como ilegible. */
    private const CONFIANZA_MINIMA = 0.55;

    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/';

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function hayGemini(): bool {
        return defined('GEMINI_API_KEY') && trim((string)GEMINI_API_KEY) !== '';
    }
    public function hayClaude(): bool {
        return defined('CLAUDE_API_KEY') && trim((string)CLAUDE_API_KEY) !== '';
    }
    public function disponible(): bool { return $this->hayGemini() || $this->hayClaude(); }

    /** El modelo que usa el resto del sistema, incluido el descubierto solo. */
    public function modelo(): string {
        return class_exists('VerificacionIA')
            ? (new VerificacionIA($this->pdo))->modeloActivo()
            : 'gemini-flash-latest';
    }

    /**
     * Lee las imágenes y devuelve los campos pedidos.
     *
     * @param array $campos  [clave => descripción de qué buscar]
     * @param array $imagenes [['mime' => ..., 'bytes' => ...], ...]
     * @param string $contexto Qué es lo que se está mirando, para el enunciado.
     */
    public function leer(array $campos, array $imagenes, string $contexto = ''): array {
        if (!$campos)   return ['status' => 'error', 'message' => 'No se indicó qué extraer.'];
        if (!$imagenes) return ['status' => 'error', 'message' => 'No se recibió ninguna imagen.'];
        if (!$this->disponible())
            return ['status' => 'error', 'message' =>
                'No hay ninguna IA configurada: falta GEMINI_API_KEY o CLAUDE_API_KEY en config/config.php.'];

        $prompt = $this->enunciado($campos, $contexto);
        $t0 = microtime(true);

        if ($this->hayGemini()) {
            [$datos, $err] = $this->conGemini($prompt, $campos, $imagenes);
            if ($datos !== null)
                return $this->resultado($datos, $campos, 'gemini:' . $this->modelo(), $t0);
            $errores[] = 'Gemini: ' . $err;
        }
        if ($this->hayClaude()) {
            [$datos, $err, $modelo] = $this->conClaude($prompt, $campos, $imagenes);
            if ($datos !== null)
                return $this->resultado($datos, $campos, 'claude:' . $modelo, $t0);
            $errores[] = 'Claude: ' . $err;
        }
        return ['status' => 'error', 'message' => implode(' | ', $errores ?? ['Sin respuesta.'])];
    }

    /**
     * El enunciado. Se le insiste en no adivinar porque es justo lo que un
     * modelo hace por defecto: completar lo que falta con algo plausible.
     */
    private function enunciado(array $campos, string $contexto): string {
        $lista = '';
        foreach ($campos as $clave => $desc) $lista .= "- {$clave}: {$desc}\n";
        return
            "Eres un asistente que lee datos de fotografías para una unidad de inspección.\n"
          . ($contexto !== '' ? "Lo que estás mirando: {$contexto}\n" : '')
          . "\nExtrae EXACTAMENTE estos campos:\n{$lista}"
          . "\nREGLAS, en orden de importancia:\n"
          . "1. NO ADIVINES. Si un dato no se lee con claridad, deja el campo vacío.\n"
          . "   Un dato inventado es peor que uno en blanco: nadie lo va a revisar.\n"
          . "2. Transcribe EXACTAMENTE lo que ves, carácter por carácter. No corrijas\n"
          . "   ni completes abreviaturas, ni 'arregles' un número que te parezca raro.\n"
          . "3. Si el dato aparece varias veces y no coinciden, deja el campo vacío\n"
          . "   y dilo en 'notas'.\n"
          . "4. Para cada campo indica 'confianza' de 0 a 1: qué tan seguro estás de\n"
          . "   haberlo leído bien. Si dudas, baja la confianza en vez de inventar.\n"
          . "5. En 'notas' escribe en español lo que convenga saber: si la foto está\n"
          . "   borrosa, cortada, con reflejo, o si no es lo que se esperaba.\n";
    }

    /** El esquema que se le obliga a devolver a Gemini. */
    private function esquema(array $campos): array {
        $props = [];
        foreach (array_keys($campos) as $c) {
            $props[$c] = ['type' => 'OBJECT', 'properties' => [
                'valor'      => ['type' => 'STRING'],
                'confianza'  => ['type' => 'NUMBER'],
            ], 'required' => ['valor', 'confianza']];
        }
        $props['notas'] = ['type' => 'STRING'];
        return ['type' => 'OBJECT', 'properties' => $props, 'required' => ['notas']];
    }

    /** @return array{0:?array,1:string} */
    private function conGemini(string $prompt, array $campos, array $imagenes): array {
        $partes = [['text' => $prompt]];
        foreach ($imagenes as $img) {
            $partes[] = ['inline_data' => ['mime_type' => $img['mime'],
                                           'data' => base64_encode($img['bytes'])]];
        }
        $payload = [
            'contents' => [['parts' => $partes]],
            'generationConfig' => [
                'temperature'      => 0,
                'responseMimeType' => 'application/json',
                'responseSchema'   => $this->esquema($campos),
            ],
        ];

        $ch = curl_init(self::ENDPOINT . rawurlencode($this->modelo()) . ':generateContent');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json',
                                       'x-goog-api-key: ' . trim((string)GEMINI_API_KEY)],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 90,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($code !== 200) {
            error_log('[LectorIA] Gemini HTTP ' . $code . ' ' . substr((string)$resp, 0, 400));
            return [null, class_exists('VerificacionIA')
                ? VerificacionIA::motivoGemini($code, (string)$resp, $cerr)
                : 'devolvió HTTP ' . $code];
        }
        $txt = json_decode((string)$resp, true)['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $out = json_decode($txt, true);
        return is_array($out) ? [$out, ''] : [null, 'devolvió algo que no se pudo interpretar'];
    }

    /** @return array{0:?array,1:string,2:string} */
    private function conClaude(string $prompt, array $campos, array $imagenes): array {
        $modelo = defined('CLAUDE_MODEL') && trim((string)CLAUDE_MODEL) !== ''
            ? trim((string)CLAUDE_MODEL) : 'claude-opus-5';

        $contenido = [];
        foreach ($imagenes as $img) {
            $contenido[] = ['type' => 'image', 'source' => ['type' => 'base64',
                'media_type' => $img['mime'], 'data' => base64_encode($img['bytes'])]];
        }
        // Claude no tiene esquema forzado: se le pide el JSON en el enunciado
        // y se rescata del texto, porque suele envolverlo en explicación.
        $claves = implode(', ', array_map(fn($c) => '"' . $c . '"', array_keys($campos)));
        $contenido[] = ['type' => 'text', 'text' => $prompt
            . "\nResponde SOLO con un objeto JSON, sin texto alrededor, con las claves "
            . $claves . " y \"notas\". Cada campo es un objeto {\"valor\": \"...\", \"confianza\": 0.0}."];

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'model' => $modelo, 'max_tokens' => 2000,
                'messages' => [['role' => 'user', 'content' => $contenido]],
            ], JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json',
                'anthropic-version: 2023-06-01', 'x-api-key: ' . trim((string)CLAUDE_API_KEY)],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 120,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) {
            error_log('[LectorIA] Claude HTTP ' . $code . ' ' . substr((string)$resp, 0, 400));
            return [null, 'devolvió HTTP ' . $code, $modelo];
        }
        $txt = json_decode((string)$resp, true)['content'][0]['text'] ?? '';
        if (preg_match('/\{.*\}/s', $txt, $m)) {
            $out = json_decode($m[0], true);
            if (is_array($out)) return [$out, '', $modelo];
        }
        return [null, 'devolvió algo que no se pudo interpretar', $modelo];
    }

    /**
     * Normaliza lo que contestó el modelo.
     *
     * Aquí se aplica la regla que hace esto seguro: lo que venga con poca
     * confianza se vacía. El modelo puede estar convencido de haber leído un
     * número borroso; el umbral es lo que impide que ese número acabe en un
     * certificado.
     */
    private function resultado(array $datos, array $campos, string $motor, float $t0): array {
        $salida = [];
        $dudosos = [];
        foreach (array_keys($campos) as $c) {
            $v  = trim((string)($datos[$c]['valor'] ?? ''));
            $cf = (float)($datos[$c]['confianza'] ?? 0);
            $fiable = $v !== '' && $cf >= self::CONFIANZA_MINIMA;
            if ($v !== '' && !$fiable) $dudosos[] = $c;
            $salida[$c] = [
                'valor'     => $fiable ? $v : '',
                'leido'     => $v,          // lo que dijo haber visto, para poder revisarlo
                'confianza' => round($cf, 2),
                'fiable'    => $fiable,
            ];
        }
        return [
            'status'  => 'success',
            'campos'  => $salida,
            'notas'   => trim((string)($datos['notas'] ?? '')),
            'dudosos' => $dudosos,
            'motor'   => $motor,
            'ms'      => (int)round((microtime(true) - $t0) * 1000),
            'umbral'  => self::CONFIANZA_MINIMA,
        ];
    }

    /**
     * Lo que se sabe pedir hoy. Cada juego es el que usará su módulo cuando
     * se construya el autollenado; aquí sirven para afinarlos con fotos reales.
     */
    public static function plantillas(): array {
        return [
            'placa' => [
                'nombre'   => 'Placa de datos de un equipo',
                'contexto' => 'la placa de datos (data plate) de una máquina: grúa, montacargas, plataforma o similar.',
                'campos'   => [
                    'marca'     => 'fabricante del equipo',
                    'modelo'    => 'modelo o designación',
                    'serie'     => 'número de serie (serial number, S/N)',
                    'capacidad' => 'capacidad nominal con su unidad, tal como aparece',
                    'anio'      => 'año de fabricación, sólo los cuatro dígitos',
                ],
            ],
            'accesorio' => [
                'nombre'   => 'Etiqueta de un accesorio de izaje',
                'contexto' => 'la etiqueta de una eslinga, grillete, gancho u otro accesorio de izaje.',
                'campos'   => [
                    'tipo'      => 'qué accesorio es (eslinga de cable, grillete, etc.)',
                    'marca'     => 'fabricante',
                    'modelo'    => 'modelo o referencia',
                    'serie'     => 'número de serie o identificador',
                    'capacidad' => 'capacidad de carga con su unidad (WLL)',
                    'medidas'   => 'longitud, diámetro o medidas que aparezcan',
                ],
            ],
            'arnes' => [
                'nombre'   => 'Etiqueta de un arnés',
                'contexto' => 'la etiqueta de un arnés de seguridad o línea de vida.',
                'campos'   => [
                    'marca'             => 'fabricante',
                    'modelo'            => 'modelo',
                    'serie'             => 'número de serie',
                    'norma'             => 'norma que cita la etiqueta (ANSI, NOM, EN…)',
                    'fecha_fabricacion' => 'fecha de fabricación tal como aparece',
                ],
            ],
            'identificacion' => [
                'nombre'   => 'Identificación oficial (INE, pasaporte, CURP)',
                'contexto' => 'un documento oficial de identidad mexicano.',
                'campos'   => [
                    'nombre'            => 'nombre completo de la persona',
                    'curp'              => 'CURP, 18 caracteres',
                    'fecha_nacimiento'  => 'fecha de nacimiento',
                ],
            ],
        ];
    }
}
