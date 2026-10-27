<?php
/**
 * AVBA Certificaciones — Timbrado de CFDI 4.0 con Facturapi.
 *
 * Fue el primer proveedor del sistema. Hoy el que timbra es Facturama, que con
 * el volumen de AVBA cuesta menos de la mitad, pero esta clase se queda y tiene
 * que seguir funcionando: una factura sólo se puede cancelar, descargar y
 * reenviar con quien la timbró, y todas las emitidas hasta el cambio son de
 * aquí. Cada presupuesto guarda en factura_proveedor con quién se timbró y de
 * ahí sale a quién se le habla.
 *
 * Para volver a timbrar con Facturapi basta poner FACTURA_PROVEEDOR='facturapi'
 * en config/config.php; no hay nada que cambiar en el código.
 *
 * Habla con https://www.facturapi.io/v2 por cURL directo. El SDK oficial de
 * PHP existe, pero exige Composer y Guzzle, y el despliegue de este proyecto
 * sube archivos por SCP sin correr `composer install` en el servidor.
 *
 * Autenticación: Basic con la llave como usuario y contraseña vacía
 * —Authorization: Basic base64("sk_...:")—, tal como lo arma el SDK oficial.
 *
 * La llave vive en config/config.php EN EL SERVIDOR. Esa carpeta está en
 * .gitignore y el workflow la borra antes de subir, así que nunca viaja.
 */

class Facturapi extends TimbradoBase {

    private const BASE = 'https://www.facturapi.io/v2';

    public function nombre(): string      { return 'facturapi'; }
    protected function etiqueta(): string { return 'Facturapi'; }
    protected function queFalta(): string { return 'FACTURAPI_KEY'; }
    protected function base(): string     { return self::BASE; }

    public function disponible(): bool {
        return $this->llave() !== '';
    }

    private function llave(): string {
        return defined('FACTURAPI_KEY') ? trim((string)FACTURAPI_KEY) : '';
    }

    protected function autorizacion(): string {
        return 'Basic ' . base64_encode($this->llave() . ':');
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

    // ══════════════════════════════════════════════════════════
    //  OPERACIONES
    // ══════════════════════════════════════════════════════════

    /** POST /invoices — crea y timbra el CFDI. */
    public function crearFactura(array $factura): array {
        $cuerpo = $this->cuerpoCfdi($factura);
        if (isset($cuerpo['status'])) return $cuerpo;

        $r = $this->llamar('POST', '/invoices', $cuerpo);
        if (($r['status'] ?? '') !== 'ok') return $r;

        $f  = $r['datos'];
        $id = (string)($f['id'] ?? '');
        if ($id === '') {
            error_log('[Facturapi] crearFactura: respuesta sin id — ' . json_encode($f));
            return ['status' => 'error',
                'message' => 'Facturapi no devolvió el identificador de la factura.'];
        }

        return [
            'status' => 'ok',
            'id'     => $id,
            'uuid'   => (string)($f['uuid'] ?? ''),
            'serie'  => trim(((string)($f['series'] ?? '')) . ' ' . ((string)($f['folio_number'] ?? ''))),
            'total'  => (float)($f['total'] ?? 0),
            'estado' => strtolower((string)($f['status'] ?? 'valid')),
        ];
    }

    /**
     * Traduce la factura de AVBA al cuerpo que espera Facturapi.
     *
     * Aquí sólo se manda el precio unitario y la tasa: Facturapi calcula el
     * importe y el impuesto de cada partida. Por eso no se le pasan las cifras
     * ya resueltas que sí necesita Facturama.
     */
    private function cuerpoCfdi(array $factura): array {
        $cli = $factura['cliente'] ?? [];
        $partidas = $factura['partidas'] ?? [];
        if (!$partidas) {
            return ['status' => 'error', 'message' => 'La factura no tiene partidas.'];
        }

        $items = [];
        foreach ($partidas as $d) {
            $tasa = (float)($d['tasa_iva'] ?? 0);
            // Tasa 0 se manda sin impuestos: el CFDI lo toma como no objeto de
            // impuesto. Los servicios de AVBA van todos al 16%.
            $impuestos = $tasa > 0
                ? [['type' => 'IVA', 'rate' => round($tasa / 100, 6), 'factor' => 'Tasa', 'withholding' => false]]
                : [];

            $items[] = [
                'quantity' => (float)($d['cantidad'] ?? 0),
                'discount' => max(0, round((float)($d['descuento'] ?? 0), 2)),
                'product'  => [
                    'description'  => mb_substr((string)($d['descripcion'] ?? ''), 0, 1000),
                    'product_key'  => (string)($d['clave_prodserv'] ?? ''),
                    'unit_key'     => (string)($d['clave_unidad'] ?? ''),
                    'unit_name'    => (string)($d['unidad'] ?? ''),
                    'price'        => (float)($d['precio_unitario'] ?? 0),
                    'tax_included' => false,
                    'taxes'        => $impuestos,
                ],
            ];
        }

        $cuerpo = [
            'customer' => [
                'legal_name' => (string)($cli['razon_social'] ?? ''),
                'tax_id'     => strtoupper(trim((string)($cli['rfc'] ?? ''))),
                'tax_system' => (string)($cli['regimen_fiscal'] ?? ''),
                'address'    => ['zip' => (string)($cli['cp_fiscal'] ?? ''), 'country' => 'MEX'],
            ],
            'items'          => $items,
            'use'            => (string)(($cli['uso_cfdi'] ?? '') ?: 'G03'),
            'payment_form'   => (string)($factura['forma_pago'] ?? '03'),
            'payment_method' => (string)($factura['metodo_pago'] ?? 'PUE'),
            // Deja el folio del presupuesto escrito en el CFDI: al conciliar
            // meses después, la factura dice sola de dónde vino.
            'external_id'    => (string)($factura['referencia'] ?? ''),
            'conditions'     => mb_substr(trim((string)($factura['condiciones'] ?? '')), 0, 1000),
        ];

        $moneda = strtoupper((string)($factura['moneda'] ?? 'MXN'));
        if ($moneda !== '' && $moneda !== 'MXN') {
            $cuerpo['currency'] = $moneda;
            $cuerpo['exchange'] = (float)($factura['tipo_cambio'] ?? 1);
        }
        return $cuerpo;
    }

    /** GET /invoices/{id} */
    public function consultar(string $id): array {
        return $this->llamar('GET', '/invoices/' . rawurlencode($id));
    }

    /** DELETE /invoices/{id}?motive=…&substitution=… */
    public function cancelar(string $id, string $motivo, string $sustitucion = ''): array {
        if ($mal = $this->motivoInvalido($motivo, $sustitucion)) return $mal;

        $q = ['motive' => $motivo];
        if (trim($sustitucion) !== '') $q['substitution'] = trim($sustitucion);
        return $this->llamar('DELETE', '/invoices/' . rawurlencode($id) . '?' . http_build_query($q));
    }

    /** POST /invoices/{id}/email — lo manda Facturapi, con XML y PDF adjuntos. */
    public function enviarPorCorreo(string $id, array $correos): array {
        $correos = array_values(array_filter(array_map('trim', $correos),
            fn($c) => filter_var($c, FILTER_VALIDATE_EMAIL)));
        if (!$correos) return ['status' => 'error', 'message' => 'No hay un correo válido de destino.'];

        $r = $this->llamar('POST', '/invoices/' . rawurlencode($id) . '/email', ['email' => $correos]);
        if (($r['status'] ?? '') !== 'ok') return $r;
        return ['status' => 'ok', 'enviados' => $correos,
            'message' => 'Factura enviada a ' . implode(', ', $correos) . '.'];
    }

    /**
     * Descarga el PDF o el XML. Facturapi los entrega como archivo, no en JSON.
     * @param string $tipo 'pdf' | 'xml' | 'zip'
     */
    public function descargar(string $id, string $tipo): array {
        if (!in_array($tipo, ['pdf', 'xml', 'zip'], true)) {
            return ['status' => 'error', 'message' => 'Tipo de archivo no válido.'];
        }
        $r = $this->peticion('GET', '/invoices/' . rawurlencode($id) . '/' . $tipo, null, '*/*');
        if (($r['status'] ?? '') !== 'ok') return $r;
        if (($r['bytes'] ?? '') === '') {
            return ['status' => 'error', 'message' => 'Facturapi devolvió un archivo vacío.'];
        }
        return $r;
    }

    // ══════════════════════════════════════════════════════════
    //  CATÁLOGOS DEL SAT
    // ══════════════════════════════════════════════════════════

    /** GET /catalogs/products — busca una clave ProdServ por texto. */
    public function buscarProductos(string $q): array {
        return $this->buscarCatalogo('/catalogs/products', $q);
    }

    /** GET /catalogs/units — busca una clave de unidad por texto. */
    public function buscarUnidades(string $q): array {
        return $this->buscarCatalogo('/catalogs/units', $q);
    }

    private function buscarCatalogo(string $ruta, string $q): array {
        $q = $this->textoBuscable($q);
        if ($q === '') return $this->faltanLetras();

        $r = $this->llamar('GET', $ruta . '?' . http_build_query(['q' => $q, 'limit' => 25]));
        if (($r['status'] ?? '') !== 'ok') return $r;
        return ['status' => 'ok', 'resultados' => $this->normalizarCatalogo($r['datos'])];
    }

    /**
     * Aplana la respuesta del catálogo a [clave, descripción].
     *
     * Se lee de forma tolerante —la lista puede venir suelta o dentro de
     * "data", y la clave puede llamarse key, value o code— porque el SDK
     * oficial declara estas dos respuestas como `any`, sin tipo que garantice
     * la forma. Adivinar una sola y equivocarse dejaría el buscador devolviendo
     * una lista vacía sin decir por qué; así, si el nombre del campo cambia, lo
     * que se pierde es a lo sumo la descripción, no el resultado.
     */
    private function normalizarCatalogo(array $j): array {
        $filas = $j['data'] ?? (array_is_list($j) ? $j : []);
        $out = [];
        foreach ($filas as $f) {
            if (is_string($f)) { $out[] = ['clave' => $f, 'descripcion' => '']; continue; }
            if (!is_array($f)) continue;
            $clave = (string)($f['key'] ?? $f['value'] ?? $f['code'] ?? $f['id'] ?? '');
            if ($clave === '') continue;
            $desc  = (string)($f['description'] ?? $f['name'] ?? $f['label'] ?? $f['text'] ?? '');
            $out[] = ['clave' => $clave, 'descripcion' => $desc];
        }
        return $out;
    }

    // ══════════════════════════════════════════════════════════
    //  ERRORES
    // ══════════════════════════════════════════════════════════

    protected function detalleDeError(string $cuerpo): string {
        $j = json_decode($cuerpo, true);
        return is_array($j) ? mb_substr(trim((string)($j['message'] ?? '')), 0, 600) : '';
    }

    /**
     * Traduce el error al lenguaje de quien está frente a la pantalla.
     *
     * El detalle de Facturapi se conserva cuando lo hay: en el 400 suele decir
     * exactamente qué campo del CFDI está mal, y esconderlo obligaría a leer
     * los registros del servidor para algo que el usuario puede corregir solo.
     */
    protected function mensajeDeError(int $code, string $detalle): string {
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
