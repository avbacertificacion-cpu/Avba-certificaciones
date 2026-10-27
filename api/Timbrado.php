<?php
/**
 * AVBA Certificaciones — Timbrado de CFDI 4.0: contrato y transporte.
 *
 * El sistema nació timbrando con Facturapi, que cobra $299 al mes más el
 * timbre. Con el volumen de AVBA —unas 100 facturas al mes— la mensualidad es
 * casi todo el gasto, así que se cambió a Facturama, que cobra la API una vez
 * al año y $0.50 por folio. El que paga por timbre no debe decidirse en el
 * código: se elige en config/config.php con FACTURA_PROVEEDOR.
 *
 * Facturapi NO se retira. Una factura sólo se puede cancelar, descargar y
 * reenviar con quien la timbró, y las que ya están emitidas son suyas. Por eso
 * cada presupuesto guarda en factura_proveedor con quién se timbró, y de ahí
 * sale el proveedor al que se le habla después, no de la configuración de hoy.
 *
 * Lo que entra y lo que sale de estas clases está en el idioma de AVBA, no en
 * el de ningún proveedor: una factura es la del método crearFactura() de abajo,
 * y la respuesta siempre trae las mismas llaves. Así quien llama no sabe —ni
 * tiene que saber— con quién está hablando.
 */

/**
 * Lo que AVBA necesita de un proveedor de timbrado.
 *
 * La factura que recibe crearFactura():
 *
 *   [
 *     'cliente' => ['razon_social','rfc','regimen_fiscal','cp_fiscal','uso_cfdi'],
 *     'forma_pago'  => '03',     // catálogo del SAT
 *     'metodo_pago' => 'PUE',
 *     'moneda'      => 'MXN',
 *     'tipo_cambio' => 1.0,      // sólo cuenta si la moneda no es MXN
 *     'condiciones' => '',
 *     'referencia'  => '',       // folio del presupuesto, para conciliar
 *     'partidas' => [[
 *        'clave_prodserv'  => '',  'clave_unidad'  => '',
 *        'unidad'          => '',  'descripcion'   => '',
 *        'clave_interna'   => '',
 *        'cantidad'        => 1.0, 'precio_unitario' => 0.0,
 *        'importe_bruto'   => 0.0, // cantidad × precio, ya redondeado
 *        'descuento'       => 0.0, // de la partida, con su parte del global
 *        'tasa_iva'        => 16.0,
 *     ]],
 *   ]
 *
 * La respuesta de crearFactura(), cuando sale bien:
 *
 *   ['status'=>'ok','id'=>…,'uuid'=>…,'serie'=>…,'total'=>0.0,'estado'=>'valid']
 *
 * El 'id' es el del proveedor y es con el que se cancela, se descarga y se
 * reenvía: se guarda tal cual. El 'uuid' es el folio fiscal del SAT.
 */
interface Timbrado {

    /** Nombre corto que se guarda en factura_proveedor. */
    public function nombre(): string;

    /** ¿Está configurado en este servidor? */
    public function disponible(): bool;

    /** 'pruebas' | 'produccion' */
    public function modo(): string;

    public function esPruebas(): bool;

    /** Crea y timbra el CFDI 4.0. */
    public function crearFactura(array $factura): array;

    /** Cancela ante el SAT. El motivo 01 exige el UUID que la sustituye. */
    public function cancelar(string $id, string $motivo, string $sustitucion = ''): array;

    /** Manda el CFDI al cliente con XML y PDF adjuntos. */
    public function enviarPorCorreo(string $id, array $correos): array;

    /**
     * Baja el archivo del CFDI.
     * @param string $tipo 'pdf' | 'xml'
     * @return array ['status'=>'ok','bytes'=>…] con el archivo ya en claro
     */
    public function descargar(string $id, string $tipo): array;

    /** Busca una clave ProdServ del SAT por texto. */
    public function buscarProductos(string $q): array;

    /** Busca una clave de unidad del SAT por texto. */
    public function buscarUnidades(string $q): array;
}


/**
 * Lo que los dos proveedores comparten: los catálogos del SAT y el cable.
 *
 * Se habla por cURL directo, sin SDK. Los dos publican uno para PHP, pero los
 * dos exigen Composer y Guzzle, y el despliegue de este proyecto sube archivos
 * por SCP sin correr `composer install` en el servidor: una dependencia nueva
 * llegaría al repositorio y no a Hostinger, y el módulo fallaría en producción
 * sin fallar aquí. Es el mismo camino que VerificacionIA y ClaudeIA.
 */
abstract class TimbradoBase implements Timbrado {

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

    /** Proveedores que el sistema sabe manejar. */
    public const PROVEEDORES = ['facturama', 'facturapi'];

    /**
     * Devuelve el proveedor pedido, o el configurado si no se pide ninguno.
     *
     * Un nombre desconocido cae en el configurado en vez de reventar: la
     * alternativa sería que una fila con el dato sucio tumbara la pantalla de
     * presupuestos completa, cuando lo único que estorba es una factura.
     */
    public static function elegir(?string $cual = null): Timbrado {
        $cual = strtolower(trim((string)$cual));
        if (!in_array($cual, self::PROVEEDORES, true)) {
            $cual = defined('FACTURA_PROVEEDOR')
                ? strtolower(trim((string)FACTURA_PROVEEDOR)) : 'facturama';
        }
        return $cual === 'facturapi' ? new Facturapi() : new Facturama();
    }

    public function esPruebas(): bool {
        return $this->modo() === 'pruebas';
    }

    // ══════════════════════════════════════════════════════════
    //  LO QUE CADA PROVEEDOR PONE
    // ══════════════════════════════════════════════════════════

    /** Raíz de la API, sin diagonal al final. */
    abstract protected function base(): string;

    /** Contenido de la cabecera Authorization. */
    abstract protected function autorizacion(): string;

    /** Saca del cuerpo de un error lo que se le puede decir a una persona. */
    abstract protected function detalleDeError(string $cuerpo): string;

    /** Traduce el código HTTP al lenguaje de quien está frente a la pantalla. */
    abstract protected function mensajeDeError(int $code, string $detalle): string;

    /** Para los registros del servidor. */
    abstract protected function etiqueta(): string;

    /** Qué falta en config/config.php, cuando falta algo. */
    abstract protected function queFalta(): string;

    // ══════════════════════════════════════════════════════════
    //  TRANSPORTE
    // ══════════════════════════════════════════════════════════

    /** Envoltura que espera JSON de vuelta. */
    protected function llamar(string $metodo, string $ruta, ?array $cuerpo = null): array {
        $r = $this->peticion($metodo, $ruta, $cuerpo);
        if (($r['status'] ?? '') !== 'ok') return $r;

        // Una cancelación puede contestar con el cuerpo vacío: eso es un sí.
        if (trim((string)$r['bytes']) === '') return ['status' => 'ok', 'datos' => []];

        $j = json_decode((string)$r['bytes'], true);
        if (!is_array($j)) {
            error_log('[' . $this->etiqueta() . '] respuesta no-JSON: ' . substr((string)$r['bytes'], 0, 400));
            return ['status' => 'error',
                'message' => $this->etiqueta() . ' respondió algo inesperado.'];
        }
        return ['status' => 'ok', 'datos' => $j];
    }

    /**
     * @param string|null $acepta Qué se espera de vuelta. Por omisión JSON. El
     *   PDF y el XML se piden aceptando cualquier tipo, porque un proveedor que
     *   ve "Accept: application/json" puede contestar el archivo envuelto en un
     *   JSON en vez del archivo.
     */
    protected function peticion(string $metodo, string $ruta, ?array $cuerpo = null,
                               ?string $acepta = null): array {
        if (!$this->disponible()) {
            return ['status' => 'error',
                'message' => 'La facturación no está configurada en el servidor (falta '
                           . $this->queFalta() . ' en config/config.php).'];
        }
        if (!function_exists('curl_init')) {
            return ['status' => 'error', 'message' => 'El servidor no tiene cURL disponible.'];
        }

        $cabeceras = [
            'Authorization: ' . $this->autorizacion(),
            'Accept: ' . ($acepta ?? 'application/json'),
            'User-Agent: avba-certificaciones',
        ];
        if ($cuerpo !== null) $cabeceras[] = 'Content-Type: application/json';

        $ch = curl_init($this->base() . $ruta);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $metodo,
            CURLOPT_HTTPHEADER     => $cabeceras,
            CURLOPT_CONNECTTIMEOUT => 15,
            // El timbrado pasa por el PAC y por el SAT; 120 s es holgado pero
            // no eterno. Cortar antes deja la duda de si la factura se emitió.
            CURLOPT_TIMEOUT        => 120,
        ] + ($cuerpo !== null
            ? [CURLOPT_POSTFIELDS => json_encode($cuerpo, JSON_UNESCAPED_UNICODE)]
            : []));

        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            error_log('[' . $this->etiqueta() . '] cURL ' . $metodo . ' ' . $ruta . ': ' . $cerr);
            return ['status' => 'error',
                'message' => 'No se pudo contactar a ' . $this->etiqueta()
                           . '. Revisa la conexión del servidor.'];
        }

        if ($code < 200 || $code >= 300) {
            error_log('[' . $this->etiqueta() . '] HTTP ' . $code . ' ' . $ruta . ': '
                    . substr((string)$resp, 0, 500));
            return ['status' => 'error', 'http' => $code,
                'message' => $this->mensajeDeError($code, $this->detalleDeError((string)$resp))];
        }

        return ['status' => 'ok', 'bytes' => (string)$resp];
    }

    // ══════════════════════════════════════════════════════════
    //  AYUDAS COMPARTIDAS
    // ══════════════════════════════════════════════════════════

    /** Las dos búsquedas de catálogo piden al menos tres letras. */
    protected function textoBuscable(string $q): string {
        return mb_strlen(trim($q)) >= 3 ? trim($q) : '';
    }

    protected function faltanLetras(): array {
        return ['status' => 'error', 'message' => 'Escribe al menos tres letras para buscar.'];
    }

    /**
     * Revisa el motivo de cancelación antes de gastar una llamada.
     *
     * El 01 sustituye una factura por otra, así que el SAT exige el UUID de la
     * que la reemplaza. Sin él la cancelación se rechaza.
     */
    protected function motivoInvalido(string $motivo, string $sustitucion): ?array {
        if (!isset(self::MOTIVOS[$motivo])) {
            return ['status' => 'error', 'message' => 'Motivo de cancelación no reconocido.'];
        }
        if ($motivo === '01' && trim($sustitucion) === '') {
            return ['status' => 'error',
                'message' => 'El motivo 01 exige el UUID de la factura que sustituye a ésta.'];
        }
        return null;
    }
}
