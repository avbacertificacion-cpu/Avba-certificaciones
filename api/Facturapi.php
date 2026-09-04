<?php
/**
 * AVBA Certificaciones — Timbrado de CFDI 4.0 con Facturapi.
 *
 * Habla con https://www.facturapi.io/v2 por cURL directo. El SDK oficial de
 * PHP existe, pero exige Composer y Guzzle, y el despliegue de este proyecto
 * sube archivos por SCP sin correr `composer install` en el servidor: una
 * dependencia nueva llegaría al repositorio y no a Hostinger, y el módulo
 * fallaría en producción sin fallar aquí. Se sigue el mismo camino que
 * VerificacionIA y ClaudeIA.
 *
 * Autenticación: Basic con la llave como usuario y contraseña vacía
 * —Authorization: Basic base64("sk_...:")—, tal como lo arma el SDK oficial.
 *
 * La llave vive en config/config.php EN EL SERVIDOR. Esa carpeta está en
 * .gitignore y el workflow la borra antes de subir, así que nunca viaja.
 */

class Facturapi {

    private const BASE = 'https://www.facturapi.io/v2';

    /** Motivos de cancelación del SAT. */
    public const MOTIVOS = [
        '01' => 'Comprobante emitido con errores, con relación',
        '02' => 'Comprobante emitido con errores, sin relación',
        '03' => 'No se llevó a cabo la operación',
        '04' => 'Operación nominativa relacionada en una factura global',
    ];

    /** Formas de pago más usadas. El catálogo del SAT es más largo. */
    public const FORMAS_PAGO = [
        '01' => 'Efectivo',
        '02' => 'Cheque nominativo',
        '03' => 'Transferencia electrónica de fondos',
        '04' => 'Tarjeta de crédito',
        '28' => 'Tarjeta de débito',
        '99' => 'Por definir',
    ];

    public const METODOS_PAGO = [
        'PUE' => 'Pago en una sola exhibición',
        'PPD' => 'Pago en parcialidades o diferido',
    ];

    public function disponible(): bool {
        return $this->llave() !== '';
    }

    private function llave(): string {
        return defined('FACTURAPI_KEY') ? trim((string)FACTURAPI_KEY) : '';
    }

    /**
     * Pruebas o producción, deducido de la llave misma.
     *
     * Facturapi entrega dos llaves distintas: sk_test_… no timbra ante el SAT,
     * sk_live_… sí. Se lee del prefijo en vez de un interruptor aparte, porque
     * un interruptor puede quedar en el valor equivocado mientras la llave dice
     * otra cosa, y entonces nadie sabe si la factura que salió es real.
     */
    public function modo(): string {
        return str_starts_with($this->llave(), 'sk_test') ? 'pruebas' : 'produccion';
    }

    public function esPruebas(): bool {
        return $this->modo() === 'pruebas';
    }

    // ══════════════════════════════════════════════════════════
    //  OPERACIONES
    // ══════════════════════════════════════════════════════════

    /** POST /invoices — crea y timbra el CFDI. */
    public function crearFactura(array $payload): array {
        return $this->llamar('POST', '/invoices', $payload);
    }

    /** GET /invoices/{id} */
    public function consultar(string $id): array {
        return $this->llamar('GET', '/invoices/' . rawurlencode($id));
    }

    /** DELETE /invoices/{id}?motive=…&substitution=… */
    public function cancelar(string $id, string $motivo, string $sustitucion = ''): array {
        if (!isset(self::MOTIVOS[$motivo])) {
            return ['status' => 'error', 'message' => 'Motivo de cancelación no reconocido.'];
        }
        // El motivo 01 sustituye una factura por otra, así que el SAT exige
        // el UUID de la que la reemplaza. Sin él la cancelación se rechaza.
        if ($motivo === '01' && trim($sustitucion) === '') {
            return ['status' => 'error',
                'message' => 'El motivo 01 exige el UUID de la factura que sustituye a ésta.'];
        }
        $q = ['motive' => $motivo];
        if (trim($sustitucion) !== '') $q['substitution'] = trim($sustitucion);
        return $this->llamar('DELETE', '/invoices/' . rawurlencode($id) . '?' . http_build_query($q));
    }

    /** POST /invoices/{id}/email — lo manda Facturapi, con XML y PDF adjuntos. */
    public function enviarPorCorreo(string $id, array $correos): array {
        return $this->llamar('POST', '/invoices/' . rawurlencode($id) . '/email',
            ['email' => array_values($correos)]);
    }

    /**
     * Descarga el PDF o el XML. Devuelve bytes, no JSON.
     * @param string $tipo 'pdf' | 'xml' | 'zip'
     */
    public function descargar(string $id, string $tipo): array {
        if (!in_array($tipo, ['pdf', 'xml', 'zip'], true)) {
            return ['status' => 'error', 'message' => 'Tipo de archivo no válido.'];
        }
        $r = $this->peticion('GET', '/invoices/' . rawurlencode($id) . '/' . $tipo, null, true);
        if (($r['status'] ?? '') !== 'ok') return $r;
        if (($r['bytes'] ?? '') === '') {
            return ['status' => 'error', 'message' => 'Facturapi devolvió un archivo vacío.'];
        }
        return $r;
    }

    // ══════════════════════════════════════════════════════════
    //  TRANSPORTE
    // ══════════════════════════════════════════════════════════

    /** Envoltura que espera JSON de vuelta. */
    private function llamar(string $metodo, string $ruta, ?array $cuerpo = null): array {
        $r = $this->peticion($metodo, $ruta, $cuerpo, false);
        if (($r['status'] ?? '') !== 'ok') return $r;
        $j = json_decode((string)$r['bytes'], true);
        if (!is_array($j)) {
            error_log('[Facturapi] respuesta no-JSON: ' . substr((string)$r['bytes'], 0, 400));
            return ['status' => 'error', 'message' => 'Facturapi respondió algo inesperado.'];
        }
        return ['status' => 'ok', 'datos' => $j];
    }

    private function peticion(string $metodo, string $ruta, ?array $cuerpo, bool $binario): array {
        if (!$this->disponible()) {
            return ['status' => 'error',
                'message' => 'La facturación no está configurada en el servidor (falta FACTURAPI_KEY).'];
        }
        if (!function_exists('curl_init')) {
            return ['status' => 'error', 'message' => 'El servidor no tiene cURL disponible.'];
        }

        $cabeceras = [
            'Authorization: Basic ' . base64_encode($this->llave() . ':'),
            'Accept: ' . ($binario ? '*/*' : 'application/json'),
            'User-Agent: avba-certificaciones',
        ];

        $ch = curl_init(self::BASE . $ruta);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $metodo,
            CURLOPT_HTTPHEADER     => $cabeceras,
            CURLOPT_CONNECTTIMEOUT => 15,
            // El timbrado pasa por el PAC y por el SAT; 120 s es holgado pero
            // no eterno. Cortar antes deja la duda de si la factura se emitió.
            CURLOPT_TIMEOUT        => 120,
        ];
        if ($cuerpo !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
            $cabeceras[] = 'Content-Type: application/json';
            $opts[CURLOPT_HTTPHEADER] = $cabeceras;
        }
        curl_setopt_array($ch, $opts);

        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            error_log('[Facturapi] cURL ' . $metodo . ' ' . $ruta . ': ' . $cerr);
            return ['status' => 'error',
                'message' => 'No se pudo contactar a Facturapi. Revisa la conexión del servidor.'];
        }

        if ($code < 200 || $code >= 300) {
            $j = json_decode((string)$resp, true);
            $det = is_array($j) ? trim((string)($j['message'] ?? '')) : '';
            error_log('[Facturapi] HTTP ' . $code . ' ' . $ruta . ': ' . substr((string)$resp, 0, 500));
            return ['status' => 'error', 'message' => $this->mensajeDeError($code, $det), 'http' => $code];
        }

        return ['status' => 'ok', 'bytes' => (string)$resp];
    }

    /**
     * Traduce el error al lenguaje de quien está frente a la pantalla.
     *
     * El detalle de Facturapi se conserva cuando lo hay: en el 400 suele decir
     * exactamente qué campo del CFDI está mal, y esconderlo obligaría a leer
     * los registros del servidor para algo que el usuario puede corregir solo.
     */
    private function mensajeDeError(int $code, string $detalle): string {
        switch ($code) {
            case 400:
            case 422:
                return 'El SAT o Facturapi rechazaron la factura'
                     . ($detalle !== '' ? ': ' . $detalle : '. Revisa los datos fiscales.');
            case 401:
                return 'La llave de Facturapi no es válida o fue revocada.';
            case 402:
                return 'La cuenta de Facturapi no tiene timbres disponibles.';
            case 404:
                return 'Facturapi no encontró esa factura.';
            case 429:
                return 'Demasiadas peticiones a Facturapi. Espera un momento.';
            default:
                if ($code >= 500) return 'Facturapi está fuera de servicio (HTTP ' . $code . '). Intenta más tarde.';
                return 'Facturapi devolvió un error (HTTP ' . $code . ')'
                     . ($detalle !== '' ? ': ' . $detalle : '.');
        }
    }
}
