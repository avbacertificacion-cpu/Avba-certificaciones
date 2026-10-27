<?php
/**
 * AVBA Certificaciones — Timbrado de CFDI 4.0 con Facturama (API Web).
 *
 * De los dos productos de Facturama se usa el "API Web": un solo emisor, el
 * dueño de la cuenta, con su Perfil Fiscal y su CSD cargados allá. Es el que
 * corresponde: AVBA factura siempre a nombre de AVBA. El otro —Multiemisor—
 * exige subir el certificado de cada emisor por API y no refleja nada en la
 * plataforma web, así que las facturas no se podrían consultar desde ahí.
 *
 * Las rutas y los nombres de los campos salen del SDK oficial de Facturama
 * (github.com/Facturama/facturama-php-sdk, wiki de API Web) y no de la
 * documentación en línea, que en varias páginas todavía muestra versiones
 * viejas del endpoint de timbrado.
 *
 *   Timbrar    POST   /3/cfdis
 *   Cancelar   DELETE /Cfdi/{id}?type=issued&motive=NN[&uuidReplacement=UUID]
 *   Descargar  GET    /cfdi/{pdf|xml}/issued/{id}   → JSON con Content en base64
 *   Enviar     POST   /Cfdi?cfdiType=issued&cfdiId={id}&email=UNO
 *   Catálogos  GET    /catalogs/ProductsOrServices?keyword=…  y  /catalogs/Units
 *
 * Autenticación: Basic con el usuario y la contraseña de la cuenta —NO una
 * llave de API—, y el ambiente se escoge por servidor, no por credencial:
 * apisandbox.facturama.mx para pruebas, api.facturama.mx para facturas reales.
 * Las cuentas de los dos ambientes son distintas.
 *
 * Todo eso vive en config/config.php EN EL SERVIDOR. Esa carpeta está en
 * .gitignore y el workflow la borra antes de subir, así que nunca viaja.
 */

class Facturama extends TimbradoBase {

    private const HOST_PRUEBAS    = 'https://apisandbox.facturama.mx';
    private const HOST_PRODUCCION = 'https://api.facturama.mx';

    /** Los comprobantes que emite AVBA son de ingreso y propios. */
    private const TIPO = 'issued';

    public function nombre(): string   { return 'facturama'; }
    protected function etiqueta(): string { return 'Facturama'; }
    protected function queFalta(): string { return 'FACTURAMA_USER y FACTURAMA_PASS'; }

    public function disponible(): bool {
        return $this->usuario() !== '' && $this->clave() !== '';
    }

    private function usuario(): string {
        return defined('FACTURAMA_USER') ? trim((string)FACTURAMA_USER) : '';
    }

    private function clave(): string {
        return defined('FACTURAMA_PASS') ? (string)FACTURAMA_PASS : '';
    }

    /** CP del lugar de expedición: una sucursal dada de alta en Facturama. */
    private function lugarExpedicion(): string {
        $cp = defined('FACTURAMA_CP_EXPEDICION') ? trim((string)FACTURAMA_CP_EXPEDICION) : '';
        return preg_match('/^\d{5}$/', $cp) ? $cp : '';
    }

    /**
     * Pruebas o producción.
     *
     * Aquí sí hace falta un interruptor: en Facturama el ambiente está en el
     * servidor al que se le habla, no en la credencial, así que no hay prefijo
     * que delate el modo como pasaba con Facturapi. Por eso el valor que falta
     * o no se entiende cae en 'pruebas': equivocarse hacia pruebas no cuesta
     * nada, y equivocarse hacia producción emite un CFDI real ante el SAT.
     */
    public function modo(): string {
        $m = defined('FACTURAMA_MODO') ? strtolower(trim((string)FACTURAMA_MODO)) : '';
        return $m === 'produccion' ? 'produccion' : 'pruebas';
    }

    protected function base(): string {
        return $this->esPruebas() ? self::HOST_PRUEBAS : self::HOST_PRODUCCION;
    }

    protected function autorizacion(): string {
        return 'Basic ' . base64_encode($this->usuario() . ':' . $this->clave());
    }

    // ══════════════════════════════════════════════════════════
    //  TIMBRAR
    // ══════════════════════════════════════════════════════════

    public function crearFactura(array $factura): array {
        if ($this->lugarExpedicion() === '') {
            return ['status' => 'error',
                'message' => 'Falta FACTURAMA_CP_EXPEDICION en config/config.php: '
                           . 'el código postal de cinco dígitos del lugar de expedición, '
                           . 'dado de alta como sucursal en Facturama.'];
        }
        // Las credenciales públicas de ejemplo de Facturama apuntan a una cuenta
        // compartida de pruebas. Si alguien las deja puestas y cambia el modo a
        // producción, lo que sale no es una factura de AVBA.
        if (!$this->esPruebas() && in_array(strtolower($this->usuario()), ['pruebas', 'usuario'], true)) {
            return ['status' => 'error',
                'message' => 'FACTURAMA_USER todavía tiene las credenciales de ejemplo. '
                           . 'Pon las de la cuenta de producción de AVBA o regresa FACTURAMA_MODO a pruebas.'];
        }

        $cuerpo = $this->cuerpoCfdi($factura);
        if (isset($cuerpo['status'])) return $cuerpo;

        $r = $this->llamar('POST', '/3/cfdis', $cuerpo);
        if (($r['status'] ?? '') !== 'ok') return $r;

        $f  = $r['datos'];
        $id = (string)($f['Id'] ?? '');
        if ($id === '') {
            error_log('[Facturama] crearFactura: respuesta sin Id — ' . json_encode($f));
            return ['status' => 'error',
                'message' => 'Facturama no devolvió el identificador de la factura.'];
        }

        $serie = trim(((string)($f['Serie'] ?? '')) . ' ' . ((string)($f['Folio'] ?? '')));

        return [
            'status' => 'ok',
            'id'     => $id,
            'uuid'   => $this->uuidDe($f),
            'serie'  => $serie,
            'total'  => (float)($f['Total'] ?? 0),
            'estado' => strtolower((string)($f['Status'] ?? 'valid')),
        ];
    }

    /**
     * Saca el folio fiscal de la respuesta.
     *
     * Se busca en los dos lugares donde Facturama lo ha puesto —dentro de
     * Complement, como en el XML del SAT, y suelto en la raíz— porque sus
     * propios ejemplos no coinciden: el SDK de JavaScript lo lee en
     * Complement.TaxStamp.Uuid y la guía de la API lo muestra en TaxStamp.
     * Quedarse con una sola y errar dejaría la factura timbrada y bien cobrada
     * pero sin UUID guardado, que es el dato con el que se consulta al SAT.
     */
    private function uuidDe(array $f): string {
        foreach ([$f['Complement']['TaxStamp'] ?? null, $f['TaxStamp'] ?? null] as $sello) {
            if (is_array($sello) && trim((string)($sello['Uuid'] ?? '')) !== '') {
                return (string)$sello['Uuid'];
            }
        }
        if (trim((string)($f['Uuid'] ?? '')) !== '') return (string)$f['Uuid'];
        error_log('[Facturama] respuesta sin UUID — ' . json_encode(array_keys($f)));
        return '';
    }

    /**
     * Traduce la factura de AVBA al CFDI 4.0 que espera Facturama.
     *
     * La diferencia de fondo con Facturapi: allá se mandaba el precio y el
     * proveedor calculaba el impuesto; aquí hay que mandar ya resueltos el
     * importe, la base, el impuesto y el total de cada partida. Se calculan con
     * los mismos redondeos que usa calcularTotales() del presupuesto —a dos
     * decimales, partida por partida— para que la factura diga exactamente la
     * cifra que se le ofreció al cliente y no unos centavos más.
     */
    private function cuerpoCfdi(array $factura): array {
        $cli = $factura['cliente'] ?? [];
        $partidas = $factura['partidas'] ?? [];
        if (!$partidas) {
            return ['status' => 'error', 'message' => 'La factura no tiene partidas.'];
        }

        $items = [];
        foreach ($partidas as $d) {
            $bruto = round((float)($d['importe_bruto'] ?? 0), 2);
            $desc  = round((float)($d['descuento'] ?? 0), 2);
            $base  = round($bruto - $desc, 2);
            $tasa  = (float)($d['tasa_iva'] ?? 0);

            $item = [
                'ProductCode'          => (string)($d['clave_prodserv'] ?? ''),
                'Description'          => mb_substr((string)($d['descripcion'] ?? ''), 0, 1000),
                'Unit'                 => mb_substr((string)($d['unidad'] ?? ''), 0, 100),
                'UnitCode'             => (string)($d['clave_unidad'] ?? ''),
                'Quantity'             => round((float)($d['cantidad'] ?? 0), 6),
                'UnitPrice'            => round((float)($d['precio_unitario'] ?? 0), 6),
                'Subtotal'             => $bruto,
            ];
            if (trim((string)($d['clave_interna'] ?? '')) !== '') {
                $item['IdentificationNumber'] = mb_substr((string)$d['clave_interna'], 0, 100);
            }
            if ($desc > 0) $item['Discount'] = $desc;

            if ($tasa > 0) {
                $iva = round($base * $tasa / 100, 2);
                // 02 = sí objeto de impuesto. Es el caso de todo lo que vende AVBA.
                $item['TaxObject'] = '02';
                $item['Taxes'] = [[
                    'Name'        => 'IVA',
                    'Rate'        => round($tasa / 100, 6),
                    'Base'        => $base,
                    'Total'       => $iva,
                    'IsRetention' => false,
                ]];
                $item['Total'] = round($base + $iva, 2);
            } else {
                // Tasa 0 va sin impuestos, igual que iba a Facturapi, y eso en
                // CFDI 4.0 se declara con TaxObject 01: no objeto de impuesto.
                $item['TaxObject'] = '01';
                $item['Total']     = $base;
            }

            $items[] = $item;
        }

        $cuerpo = [
            'CfdiType'        => 'I',
            'PaymentForm'     => (string)($factura['forma_pago'] ?? '03'),
            'PaymentMethod'   => (string)($factura['metodo_pago'] ?? 'PUE'),
            'ExpeditionPlace' => $this->lugarExpedicion(),
            'Exportation'     => '01',  // no es una exportación
            'Receiver' => [
                'Rfc'          => strtoupper(trim((string)($cli['rfc'] ?? ''))),
                'Name'         => mb_substr((string)($cli['razon_social'] ?? ''), 0, 300),
                'CfdiUse'      => (string)($cli['uso_cfdi'] ?? 'G03'),
                'FiscalRegime' => (string)($cli['regimen_fiscal'] ?? ''),
                'TaxZipCode'   => (string)($cli['cp_fiscal'] ?? ''),
            ],
            'Items' => $items,
        ];

        // La fecha se deja a Facturama a propósito. El SAT sólo acepta
        // comprobantes de las últimas 72 horas, y la hora de Hostinger no es la
        // de México: mandarla desde aquí es exponerse a un rechazo por husos.

        $moneda = strtoupper((string)($factura['moneda'] ?? 'MXN'));
        $cuerpo['Currency'] = $moneda !== '' ? $moneda : 'MXN';
        if ($cuerpo['Currency'] !== 'MXN') {
            $cuerpo['ExchangeRate'] = (float)($factura['tipo_cambio'] ?? 1);
        }

        $cond = trim((string)($factura['condiciones'] ?? ''));
        if ($cond !== '') $cuerpo['PaymentConditions'] = mb_substr($cond, 0, 1000);

        // Deja el folio del presupuesto escrito en el CFDI: al conciliar meses
        // después, la factura dice sola de dónde vino. Serie y folio los pone
        // Facturama con su propio consecutivo, para no chocar con los que la
        // cuenta ya tenga emitidos.
        $ref = trim((string)($factura['referencia'] ?? ''));
        if ($ref !== '') {
            $cuerpo['OrderNumber']  = mb_substr($ref, 0, 100);
            $cuerpo['Observations'] = 'Presupuesto ' . mb_substr($ref, 0, 100);
        }

        return $cuerpo;
    }

    // ══════════════════════════════════════════════════════════
    //  CANCELAR, DESCARGAR, ENVIAR
    // ══════════════════════════════════════════════════════════

    public function cancelar(string $id, string $motivo, string $sustitucion = ''): array {
        if ($mal = $this->motivoInvalido($motivo, $sustitucion)) return $mal;

        $q = ['type' => self::TIPO, 'motive' => $motivo];
        if (trim($sustitucion) !== '') $q['uuidReplacement'] = trim($sustitucion);

        return $this->llamar('DELETE', '/Cfdi/' . rawurlencode($id) . '?' . http_build_query($q));
    }

    /**
     * Baja el PDF o el XML.
     *
     * Facturama no devuelve el archivo: devuelve un JSON con el archivo dentro,
     * en base64, en el campo Content. Se decodifica aquí para que quien llama
     * reciba bytes, como con el otro proveedor.
     */
    public function descargar(string $id, string $tipo): array {
        $tipo = strtolower($tipo);
        if (!in_array($tipo, ['pdf', 'xml', 'html'], true)) {
            return ['status' => 'error', 'message' => 'Tipo de archivo no válido.'];
        }

        $r = $this->llamar('GET', '/cfdi/' . $tipo . '/' . self::TIPO . '/' . rawurlencode($id));
        if (($r['status'] ?? '') !== 'ok') return $r;

        $b64 = (string)($r['datos']['Content'] ?? '');
        $bytes = $b64 !== '' ? base64_decode($b64, true) : false;
        if ($bytes === false || $bytes === '') {
            error_log('[Facturama] descargar ' . $tipo . ': Content vacío o mal codificado');
            return ['status' => 'error', 'message' => 'Facturama devolvió un archivo vacío.'];
        }
        return ['status' => 'ok', 'bytes' => $bytes];
    }

    /**
     * Manda el CFDI al cliente con XML y PDF adjuntos.
     *
     * Facturama acepta un destinatario por llamada, así que se recorre la lista.
     * Si uno falla se sigue con los demás y al final se dice a quién sí llegó:
     * cortar en el primer error dejaría al cliente sin su factura por culpa de
     * un correo mal escrito del contador.
     */
    public function enviarPorCorreo(string $id, array $correos): array {
        $correos = array_values(array_filter(array_map('trim', $correos),
            fn($c) => filter_var($c, FILTER_VALIDATE_EMAIL)));
        if (!$correos) return ['status' => 'error', 'message' => 'No hay un correo válido de destino.'];

        $ok = [];
        $fallos = [];
        foreach ($correos as $correo) {
            $r = $this->llamar('POST', '/Cfdi?' . http_build_query([
                'cfdiType' => self::TIPO,
                'cfdiId'   => $id,
                'email'    => $correo,
            ]));
            if (($r['status'] ?? '') === 'ok') $ok[] = $correo;
            else $fallos[] = $correo . ' (' . ($r['message'] ?? 'falló') . ')';
        }

        if (!$ok) {
            return ['status' => 'error',
                'message' => 'No se pudo enviar la factura: ' . implode('; ', $fallos)];
        }
        return ['status' => 'ok', 'enviados' => $ok,
            'message' => 'Factura enviada a ' . implode(', ', $ok) . '.'
                       . ($fallos ? ' No salió a ' . implode('; ', $fallos) . '.' : '')];
    }

    // ══════════════════════════════════════════════════════════
    //  CATÁLOGOS DEL SAT
    // ══════════════════════════════════════════════════════════

    public function buscarProductos(string $q): array {
        $q = $this->textoBuscable($q);
        if ($q === '') return $this->faltanLetras();

        $r = $this->llamar('GET', '/catalogs/ProductsOrServices?' . http_build_query(['keyword' => $q]));
        if (($r['status'] ?? '') !== 'ok') return $r;
        return ['status' => 'ok',
            'resultados' => array_slice($this->normalizarCatalogo($r['datos']), 0, 25)];
    }

    /**
     * Unidades del SAT.
     *
     * A diferencia de las claves de producto, Facturama no filtra las unidades:
     * entrega el catálogo completo, unos cuantos miles de renglones. Se guarda
     * en el servidor y se filtra aquí, para no bajar la lista entera en cada
     * letra que alguien escribe en el buscador.
     */
    public function buscarUnidades(string $q): array {
        $q = $this->textoBuscable($q);
        if ($q === '') return $this->faltanLetras();

        $todas = $this->unidades();
        if (isset($todas['status'])) return $todas;

        $hallados = array_values(array_filter($todas, function ($u) use ($q) {
            return mb_stripos($u['clave'], $q) !== false
                || mb_stripos($u['descripcion'], $q) !== false;
        }));
        return ['status' => 'ok', 'resultados' => array_slice($hallados, 0, 25)];
    }

    /** El catálogo de unidades, del archivo si está fresco o de Facturama. */
    private function unidades(): array {
        static $memoria = null;
        if (is_array($memoria)) return $memoria;

        $archivo = defined('UPLOAD_DIR') ? UPLOAD_DIR . 'cache/facturama_unidades.json' : '';

        if ($archivo !== '' && is_file($archivo) && filemtime($archivo) > time() - 30 * 86400) {
            $guardado = json_decode((string)@file_get_contents($archivo), true);
            if (is_array($guardado) && $guardado) return $memoria = $guardado;
        }

        $r = $this->llamar('GET', '/catalogs/Units');
        if (($r['status'] ?? '') !== 'ok') return $r;

        $lista = $this->normalizarCatalogo($r['datos']);
        if (!$lista) {
            return ['status' => 'error', 'message' => 'Facturama devolvió el catálogo de unidades vacío.'];
        }

        if ($archivo !== '') {
            if (!is_dir(dirname($archivo))) @mkdir(dirname($archivo), 0755, true);
            @file_put_contents($archivo, json_encode($lista, JSON_UNESCAPED_UNICODE));
        }
        return $memoria = $lista;
    }

    /**
     * Aplana la respuesta del catálogo a [clave, descripción].
     *
     * Se lee de forma tolerante —la lista puede venir suelta o dentro de un
     * campo, y la clave puede llamarse Value, Key o Code— porque estas dos
     * respuestas no están tipadas en ningún SDK. Si cambia el nombre de un
     * campo, lo que se pierde es a lo sumo la descripción, no el resultado.
     */
    private function normalizarCatalogo(array $j): array {
        $filas = array_is_list($j) ? $j : ($j['Data'] ?? $j['data'] ?? []);
        $out = [];
        foreach ($filas as $f) {
            if (is_string($f)) { $out[] = ['clave' => $f, 'descripcion' => '']; continue; }
            if (!is_array($f)) continue;
            $clave = (string)($f['Value'] ?? $f['Key'] ?? $f['Code'] ?? $f['value'] ?? $f['key'] ?? '');
            if ($clave === '') continue;
            $desc = (string)($f['Name'] ?? $f['Description'] ?? $f['Label'] ?? $f['name'] ?? $f['description'] ?? '');
            $out[] = ['clave' => $clave, 'descripcion' => $desc];
        }
        return $out;
    }

    // ══════════════════════════════════════════════════════════
    //  ERRORES
    // ══════════════════════════════════════════════════════════

    /**
     * Facturama contesta los errores con Message y, cuando el problema está en
     * un campo, con ModelState: el campo y qué tiene mal. Eso último es lo que
     * de verdad sirve —"Receiver.FiscalRegime es obligatorio" se corrige en la
     * pantalla—, así que se junta todo.
     */
    protected function detalleDeError(string $cuerpo): string {
        $j = json_decode($cuerpo, true);
        if (!is_array($j)) return '';

        $partes = [];
        $msg = trim((string)($j['Message'] ?? $j['message'] ?? ''));
        if ($msg !== '') $partes[] = $msg;

        $estado = $j['ModelState'] ?? $j['modelState'] ?? null;
        if (is_array($estado)) {
            foreach ($estado as $campo => $avisos) {
                foreach ((array)$avisos as $aviso) {
                    $aviso = trim((string)$aviso);
                    if ($aviso === '' || in_array($aviso, $partes, true)) continue;
                    $partes[] = is_string($campo) && !ctype_digit($campo)
                        ? $campo . ': ' . $aviso : $aviso;
                }
            }
        }
        return mb_substr(implode('; ', $partes), 0, 600);
    }

    protected function mensajeDeError(int $code, string $detalle): string {
        switch ($code) {
            case 400:
            case 422:
                return 'El SAT o Facturama rechazaron la factura'
                     . ($detalle !== '' ? ': ' . $detalle : '. Revisa los datos fiscales.');
            case 401:
                return 'Facturama no aceptó el usuario o la contraseña. Revisa que sean los del '
                     . ($this->esPruebas() ? 'ambiente de pruebas' : 'ambiente de producción') . '.';
            case 402:
                return 'La cuenta de Facturama no tiene folios disponibles.';
            case 403:
                return 'La cuenta de Facturama no tiene permiso para esta operación'
                     . ($detalle !== '' ? ': ' . $detalle : '.');
            case 404:
                return 'Facturama no encontró esa factura.';
            case 429:
                return 'Demasiadas peticiones a Facturama. Espera un momento.';
            default:
                if ($code >= 500) {
                    return 'Facturama está fuera de servicio (HTTP ' . $code . '). Intenta más tarde.';
                }
                return 'Facturama devolvió un error (HTTP ' . $code . ')'
                     . ($detalle !== '' ? ': ' . $detalle : '.');
        }
    }
}
