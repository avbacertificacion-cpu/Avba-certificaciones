<?php
/**
 * AVBA Certificaciones — Cliente de la API de Claude (Anthropic).
 *
 * Habla con https://api.anthropic.com/v1/messages por cURL directo, sin SDK.
 * No es por gusto: el despliegue sube archivos por SCP y nunca corre
 * `composer install` en el servidor, así que una dependencia nueva en
 * composer.json llegaría al repositorio pero no a Hostinger, y el módulo
 * fallaría en producción sin fallar aquí. El proyecto ya llama a Gemini de
 * esta misma forma en VerificacionIA.php; esto sigue esa vereda.
 *
 * La clave vive en config/config.php EN EL SERVIDOR. Esa carpeta está en
 * .gitignore y el workflow la borra antes de subir, así que nunca viaja.
 */

class ClaudeIA {

    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const VERSION  = '2023-06-01';

    /** Modelo por omisión. Se puede fijar otro con CLAUDE_MODEL en config. */
    private const MODELO_DEFAULT = 'claude-opus-5';

    /** Techo de salida. Una propuesta larga cabe de sobra en esto. */
    private const MAX_TOKENS = 16000;

    public function disponible(): bool {
        return defined('CLAUDE_API_KEY') && trim((string)CLAUDE_API_KEY) !== '';
    }

    public function modelo(): string {
        $m = defined('CLAUDE_MODEL') ? trim((string)CLAUDE_MODEL) : '';
        return $m !== '' ? $m : self::MODELO_DEFAULT;
    }

    /**
     * Cuánto se le pide que piense. La API toma 'high' por omisión; bajarlo a
     * 'medium' o 'low' acorta la espera, que en hosting compartido importa
     * porque el propio PHP tiene un límite de ejecución.
     */
    private function esfuerzo(): string {
        $e = defined('CLAUDE_EFFORT') ? strtolower(trim((string)CLAUDE_EFFORT)) : '';
        return in_array($e, ['low', 'medium', 'high', 'xhigh', 'max'], true) ? $e : 'high';
    }

    /**
     * Una sola vuelta de conversación.
     *
     * @param string $system    Instrucciones fijas (perfil de la empresa, normas,
     *                          formato de salida). Van marcadas para caché: se
     *                          repiten idénticas en cada propuesta, y la API
     *                          cobra menos por lo que ya vio.
     * @param string $usuario   El encargo concreto de esta llamada.
     * @return array{status:string, texto?:string, modelo?:string, uso?:array, message?:string}
     */
    public function mensaje(string $system, string $usuario): array {
        if (!$this->disponible()) {
            return ['status' => 'error',
                'message' => 'La generación con IA no está configurada en el servidor (falta CLAUDE_API_KEY).'];
        }
        if (!function_exists('curl_init')) {
            return ['status' => 'error', 'message' => 'El servidor no tiene cURL disponible.'];
        }
        if (trim($usuario) === '') {
            return ['status' => 'error', 'message' => 'No hay nada que pedirle al modelo.'];
        }

        $payload = [
            'model'      => $this->modelo(),
            'max_tokens' => self::MAX_TOKENS,
            // El pensamiento adaptativo lo decide el modelo. No se pide el
            // resumen: aquí sólo interesa el documento final.
            'thinking'      => ['type' => 'adaptive'],
            'output_config' => ['effort' => $this->esfuerzo()],
            'system'     => [[
                'type'          => 'text',
                'text'          => $system,
                'cache_control' => ['type' => 'ephemeral'],
            ]],
            'messages'   => [[
                'role'    => 'user',
                'content' => $usuario,
            ]],
        ];

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . trim((string)CLAUDE_API_KEY),
                'anthropic-version: ' . self::VERSION,
            ],
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 300,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($resp === false || $resp === '') {
            error_log('[ClaudeIA] cURL: ' . $cerr);
            return ['status' => 'error',
                'message' => 'No se pudo contactar el servicio de IA. Intenta de nuevo.'];
        }

        $j = json_decode((string)$resp, true);
        if (!is_array($j)) {
            error_log('[ClaudeIA] respuesta no-JSON (HTTP ' . $code . '): ' . substr((string)$resp, 0, 400));
            return ['status' => 'error', 'message' => 'El servicio de IA respondió algo inesperado.'];
        }

        if ($code !== 200) {
            $det = (string)($j['error']['message'] ?? '');
            error_log('[ClaudeIA] HTTP ' . $code . ': ' . $det);
            return ['status' => 'error', 'message' => $this->mensajeDeError($code, $det)];
        }

        // El modelo puede declinar la petición: llega HTTP 200 con stop_reason
        // "refusal" y ningún texto. Hay que mirarlo antes de leer el contenido.
        if (($j['stop_reason'] ?? '') === 'refusal') {
            $por = trim((string)($j['stop_details']['explanation'] ?? ''));
            error_log('[ClaudeIA] refusal: ' . $por);
            return ['status' => 'error',
                'message' => 'El modelo no pudo generar este documento. Revisa el texto de la solicitud.'];
        }

        // content es una lista de bloques de distintos tipos (thinking, text…).
        // Sólo interesan los de texto, y hay que concatenarlos: una respuesta
        // larga puede venir partida en varios.
        $texto = '';
        foreach ((array)($j['content'] ?? []) as $b) {
            if (($b['type'] ?? '') === 'text') $texto .= (string)($b['text'] ?? '');
        }
        $texto = trim($texto);

        if ($texto === '') {
            $razon = (string)($j['stop_reason'] ?? '');
            error_log('[ClaudeIA] respuesta vacía, stop_reason=' . $razon);
            return ['status' => 'error', 'message' => 'El modelo no devolvió contenido.'];
        }

        // Si se topó con el techo, el documento viene cortado a media frase.
        // Más vale decirlo que entregar una propuesta trunca al cliente.
        if (($j['stop_reason'] ?? '') === 'max_tokens') {
            return ['status' => 'error',
                'message' => 'La propuesta salió más larga de lo que cabe. Reduce el número de partidas o acorta los alcances.'];
        }

        return [
            'status' => 'ok',
            'texto'  => $texto,
            'modelo' => (string)($j['model'] ?? $this->modelo()),
            'uso'    => [
                'entrada' => (int)($j['usage']['input_tokens']  ?? 0),
                'salida'  => (int)($j['usage']['output_tokens'] ?? 0),
            ],
        ];
    }

    /** Traduce el código HTTP a algo que le sirva a quien está en la pantalla. */
    private function mensajeDeError(int $code, string $detalle): string {
        switch ($code) {
            case 401:
            case 403:
                return 'La clave de la API de IA no es válida o fue revocada.';
            case 429:
                return 'Se alcanzó el límite de peticiones a la IA. Espera un momento y vuelve a intentar.';
            case 400:
                return 'La petición a la IA fue rechazada' . ($detalle !== '' ? ': ' . $detalle : '.');
            case 529:
            case 503:
                return 'El servicio de IA está saturado. Intenta de nuevo en unos minutos.';
            default:
                return 'El servicio de IA devolvió un error (HTTP ' . $code . ').';
        }
    }
}
