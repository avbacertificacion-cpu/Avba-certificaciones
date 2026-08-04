<?php
/**
 * AVBA Certificaciones — Verificación de identidad con IA (Gemini)
 *
 * Calidad revisa participantes que se auto-registraron: escribieron su nombre
 * y CURP, y subieron una foto de su identificación oficial. Este módulo manda
 * ambas cosas a Gemini para que COMPARE lo capturado contra el documento y
 * devuelva un semáforo:
 *
 *   coincide    (verde)    — los datos capturados corresponden al documento
 *   no_coincide (rojo)     — hay diferencias respecto al documento
 *   ilegible    (amarillo) — no se pudo leer el documento con confianza
 *
 * Es una AYUDA para el revisor, no un reemplazo: la decisión final (aprobar o
 * devolver) la sigue tomando Calidad. Si no hay API key configurada o el
 * servicio falla, el flujo continúa igual y se captura/valida a mano.
 */
class VerificacionIA {
    private PDO $pdo;

    /** Modelo de visión: rápido y económico, suficiente para leer una credencial. */
    private const MODELO = 'gemini-2.5-flash';
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/';

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
                  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\PDOException $e) {
            error_log('[VerificacionIA] migrate: ' . $e->getMessage());
        }
    }

    public function disponible(): bool {
        return defined('GEMINI_API_KEY') && trim((string)GEMINI_API_KEY) !== '';
    }

    /** Devuelve la verificación guardada de un participante, o null. */
    public function obtener(int $id): ?array {
        try {
            $s = $this->pdo->prepare(
                "SELECT resultado, nombre_doc, curp_doc, detalle,
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
            return ['status' => 'error', 'message' => 'La verificación con IA no está configurada en el servidor (falta GEMINI_API_KEY).'];
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

        $rel = (string)($p['foto_documentacion_url'] ?? '');
        if ($rel === '') return ['status' => 'error', 'message' => 'El participante no tiene identificación adjunta.'];

        // Resolver la ruta local de la imagen (la URL puede venir absoluta)
        $rel = preg_replace('#^https?://[^/]+/#i', '', $rel);
        $abs = dirname(__DIR__) . '/' . ltrim($rel, '/');
        if (!is_file($abs)) return ['status' => 'error', 'message' => 'No se encontró el archivo de la identificación en el servidor.'];

        $mime = $this->mimeDe($abs);
        if ($mime === '') {
            return ['status' => 'error', 'message' => 'La identificación debe ser una imagen (JPG o PNG). Si es PDF, súbela como foto.'];
        }
        $bytes = @file_get_contents($abs);
        if ($bytes === false || $bytes === '') return ['status' => 'error', 'message' => 'No se pudo leer la imagen de la identificación.'];
        if (strlen($bytes) > 15 * 1024 * 1024) {
            return ['status' => 'error', 'message' => 'La imagen es demasiado grande para verificarla.'];
        }

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

        $url = self::ENDPOINT . self::MODELO . ':generateContent';
        $ch  = curl_init($url);
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

        if ($resp === false || $code !== 200) {
            error_log('[VerificacionIA] HTTP ' . $code . ' ' . $cerr . ' ' . substr((string)$resp, 0, 500));
            $msg = $code === 429
                ? 'El servicio de verificación alcanzó su límite de uso; intenta más tarde.'
                : 'No se pudo contactar el servicio de verificación (HTTP ' . $code . ').';
            return ['status' => 'error', 'message' => $msg];
        }

        $data = json_decode((string)$resp, true);
        $txt  = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $out  = json_decode($txt, true);
        if (!is_array($out) || empty($out['resultado'])) {
            error_log('[VerificacionIA] respuesta no interpretable: ' . substr($txt, 0, 500));
            return ['status' => 'error', 'message' => 'La respuesta del servicio no se pudo interpretar.'];
        }

        $resultado = in_array($out['resultado'], ['coincide', 'no_coincide', 'ilegible'], true)
            ? $out['resultado'] : 'ilegible';
        $nombreDoc = mb_substr(trim((string)($out['nombre_doc'] ?? '')), 0, 200);
        $curpDoc   = mb_substr(strtoupper(trim((string)($out['curp_doc'] ?? ''))), 0, 30);
        $detalle   = mb_substr(trim((string)($out['detalle'] ?? '')), 0, 1000);

        $this->pdo->prepare("
            INSERT INTO participantes_verificacion
              (participante_id, resultado, nombre_doc, curp_doc, detalle, verificado_por)
            VALUES (?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE resultado=VALUES(resultado), nombre_doc=VALUES(nombre_doc),
              curp_doc=VALUES(curp_doc), detalle=VALUES(detalle), verificado_por=VALUES(verificado_por)
        ")->execute([$id, $resultado, $nombreDoc ?: null, $curpDoc ?: null, $detalle ?: null, $usuario]);

        return [
            'status'     => 'success',
            'resultado'  => $resultado,
            'nombre_doc' => $nombreDoc,
            'curp_doc'   => $curpDoc,
            'detalle'    => $detalle,
            'capturado'  => ['nombre' => $nombreCap, 'curp' => $curpCap],
        ];
    }

    /** MIME de la imagen a partir de su contenido; '' si no es imagen soportada. */
    private function mimeDe(string $path): string {
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
