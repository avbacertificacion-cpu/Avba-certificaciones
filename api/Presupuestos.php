<?php
/**
 * AVBA Certificaciones — Servicios, precios, clientes y presupuestos.
 *
 * La cadena completa vive aquí:
 *
 *   catálogo de servicios ─┐
 *   datos del cliente     ─┼─→ PRESUPUESTO ─→ PROPUESTA TÉCNICO-ECONÓMICA ─→ FACTURA
 *   precios y condiciones ─┘      (PDF)            (redactada con IA)          (PAC)
 *
 * Dos decisiones que conviene entender antes de tocar nada:
 *
 * 1. Los totales NUNCA se toman de lo que manda el navegador. Llegan las
 *    partidas y aquí se recalcula todo. Un presupuesto es una oferta con
 *    consecuencias: si el importe se pudiera inyectar desde el cliente, un
 *    campo oculto mal puesto —o alguien con la consola abierta— cambiaría lo
 *    que AVBA se compromete a cobrar.
 *
 * 2. El diseño de la propuesta es de PHP, no de la IA. El modelo redacta el
 *    texto; el encabezado, los logotipos, la tabla de importes y el pie salen
 *    de esta clase. Así dos propuestas hechas con seis meses de diferencia se
 *    ven idénticas, y ninguna puede inventarse una acreditación que no tenemos.
 */

class Presupuestos {

    private PDO $pdo;

    /** Estados por los que pasa un presupuesto. */
    private const ESTADOS = ['BORRADOR', 'ENVIADO', 'ACEPTADO', 'RECHAZADO', 'FACTURADO', 'CANCELADO'];

    /** IVA general vigente. Se puede cambiar por partida (p. ej. 0 en exportación). */
    private const IVA_DEFAULT = 16.0;

    /**
     * Regímenes fiscales del SAT. Se guardan aquí para que la captura sea una
     * lista y no un campo libre: un régimen mal tecleado no se descubre hasta
     * que el timbrado lo rechaza, con el cliente esperando su factura.
     */
    public const REGIMENES = [
        '601' => 'General de Ley Personas Morales',
        '603' => 'Personas Morales con Fines no Lucrativos',
        '605' => 'Sueldos y Salarios e Ingresos Asimilados a Salarios',
        '606' => 'Arrendamiento',
        '607' => 'Régimen de Enajenación o Adquisición de Bienes',
        '608' => 'Demás ingresos',
        '610' => 'Residentes en el Extranjero sin Establecimiento Permanente en México',
        '611' => 'Ingresos por Dividendos (socios y accionistas)',
        '612' => 'Personas Físicas con Actividades Empresariales y Profesionales',
        '614' => 'Ingresos por intereses',
        '615' => 'Régimen de los ingresos por obtención de premios',
        '616' => 'Sin obligaciones fiscales',
        '620' => 'Sociedades Cooperativas de Producción que optan por diferir sus ingresos',
        '621' => 'Incorporación Fiscal',
        '622' => 'Actividades Agrícolas, Ganaderas, Silvícolas y Pesqueras',
        '623' => 'Opcional para Grupos de Sociedades',
        '624' => 'Coordinados',
        '625' => 'Régimen de las Actividades Empresariales con ingresos a través de Plataformas Tecnológicas',
        '626' => 'Régimen Simplificado de Confianza',
    ];

    /** Usos de CFDI. Para un servicio de inspección lo habitual es G03. */
    public const USOS_CFDI = [
        'G01' => 'Adquisición de mercancías',
        'G02' => 'Devoluciones, descuentos o bonificaciones',
        'G03' => 'Gastos en general',
        'I01' => 'Construcciones',
        'I02' => 'Mobiliario y equipo de oficina por inversiones',
        'I03' => 'Equipo de transporte',
        'I04' => 'Equipo de cómputo y accesorios',
        'I05' => 'Dados, troqueles, moldes, matrices y herramental',
        'I06' => 'Comunicaciones telefónicas',
        'I07' => 'Comunicaciones satelitales',
        'I08' => 'Otra maquinaria y equipo',
        'S01' => 'Sin efectos fiscales',
        'CP01' => 'Pagos',
    ];

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->migrar();
    }

    // ══════════════════════════════════════════════════════════
    //  ESQUEMA
    // ══════════════════════════════════════════════════════════

    /**
     * Cada bloque va en su propio try. Si una tabla falla —permisos, una
     * versión de MariaDB que no traga algo— las demás siguen creándose, y sobre
     * todo el constructor no lanza: index.php construye TODAS las clases antes
     * de enrutar, así que una excepción aquí tumbaría hasta el login.
     */
    private function migrar(): void {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS pres_config (
                  clave      VARCHAR(60) NOT NULL PRIMARY KEY,
                  valor      MEDIUMTEXT NULL,
                  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\Throwable $e) { error_log('[Presupuestos] migrar config: ' . $e->getMessage()); }

        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS pres_servicios (
                  id             INT AUTO_INCREMENT PRIMARY KEY,
                  clave          VARCHAR(40)  NOT NULL,
                  nombre         VARCHAR(200) NOT NULL,
                  descripcion    TEXT NULL,
                  alcance        TEXT NULL,
                  normas         VARCHAR(400) NULL,
                  entregables    TEXT NULL,
                  unidad         VARCHAR(30)  NOT NULL DEFAULT 'Servicio',
                  precio         DECIMAL(12,2) NOT NULL DEFAULT 0,
                  moneda         VARCHAR(3)   NOT NULL DEFAULT 'MXN',
                  tasa_iva       DECIMAL(5,2) NOT NULL DEFAULT 16.00,
                  clave_prodserv VARCHAR(10)  NULL,
                  clave_unidad   VARCHAR(10)  NULL,
                  orden          INT NOT NULL DEFAULT 0,
                  activo         TINYINT NOT NULL DEFAULT 1,
                  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  UNIQUE KEY uk_clave (clave)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\Throwable $e) { error_log('[Presupuestos] migrar servicios: ' . $e->getMessage()); }

        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS pres_clientes (
                  id               INT AUTO_INCREMENT PRIMARY KEY,
                  cliente_id       INT NULL,
                  nombre_comercial VARCHAR(200) NOT NULL,
                  razon_social     VARCHAR(250) NULL,
                  rfc              VARCHAR(13)  NULL,
                  cp_fiscal        VARCHAR(5)   NULL,
                  regimen_fiscal   VARCHAR(3)   NULL,
                  uso_cfdi         VARCHAR(4)   NOT NULL DEFAULT 'G03',
                  correo           VARCHAR(200) NULL,
                  telefono         VARCHAR(40)  NULL,
                  contacto         VARCHAR(150) NULL,
                  direccion        VARCHAR(300) NULL,
                  notas            TEXT NULL,
                  activo           TINYINT NOT NULL DEFAULT 1,
                  created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  KEY idx_nombre (nombre_comercial),
                  KEY idx_rfc (rfc)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\Throwable $e) { error_log('[Presupuestos] migrar clientes: ' . $e->getMessage()); }

        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS pres_presupuestos (
                  id             INT AUTO_INCREMENT PRIMARY KEY,
                  folio          VARCHAR(30) NOT NULL,
                  cliente_pres_id INT NULL,
                  cliente_nombre VARCHAR(250) NOT NULL,
                  cliente_correo VARCHAR(200) NULL,
                  atencion       VARCHAR(150) NULL,
                  fecha          DATE NOT NULL,
                  vigencia_dias  INT NOT NULL DEFAULT 15,
                  lugar_servicio VARCHAR(250) NULL,
                  moneda         VARCHAR(3) NOT NULL DEFAULT 'MXN',
                  tipo_cambio    DECIMAL(10,4) NOT NULL DEFAULT 1.0000,
                  subtotal       DECIMAL(14,2) NOT NULL DEFAULT 0,
                  descuento      DECIMAL(14,2) NOT NULL DEFAULT 0,
                  iva            DECIMAL(14,2) NOT NULL DEFAULT 0,
                  total          DECIMAL(14,2) NOT NULL DEFAULT 0,
                  estado         VARCHAR(15) NOT NULL DEFAULT 'BORRADOR',
                  condiciones    TEXT NULL,
                  notas          TEXT NULL,
                  pdf_url        VARCHAR(300) NULL,
                  enviado_at     DATETIME NULL,
                  forma_pago     VARCHAR(3) NOT NULL DEFAULT '03',
                  metodo_pago    VARCHAR(3) NOT NULL DEFAULT 'PUE',
                  factura_id     VARCHAR(40) NULL,
                  factura_uuid   VARCHAR(50) NULL,
                  factura_serie  VARCHAR(25) NULL,
                  factura_fecha  DATETIME NULL,
                  factura_modo   VARCHAR(12) NULL,
                  factura_total  DECIMAL(14,2) NULL,
                  factura_estado VARCHAR(15) NULL,
                  factura_cancelada_at DATETIME NULL,
                  factura_pdf    VARCHAR(300) NULL,
                  factura_xml    VARCHAR(300) NULL,
                  usuario        VARCHAR(120) NULL,
                  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  UNIQUE KEY uk_folio (folio),
                  KEY idx_estado (estado),
                  KEY idx_fecha (fecha)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\Throwable $e) { error_log('[Presupuestos] migrar presupuestos: ' . $e->getMessage()); }

        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS pres_partidas (
                  id              INT AUTO_INCREMENT PRIMARY KEY,
                  presupuesto_id  INT NOT NULL,
                  servicio_id     INT NULL,
                  clave           VARCHAR(40) NULL,
                  descripcion     VARCHAR(300) NOT NULL,
                  alcance         TEXT NULL,
                  normas          VARCHAR(400) NULL,
                  unidad          VARCHAR(30) NOT NULL DEFAULT 'Servicio',
                  cantidad        DECIMAL(12,2) NOT NULL DEFAULT 1,
                  precio_unitario DECIMAL(12,2) NOT NULL DEFAULT 0,
                  descuento_pct   DECIMAL(5,2) NOT NULL DEFAULT 0,
                  tasa_iva        DECIMAL(5,2) NOT NULL DEFAULT 16.00,
                  importe         DECIMAL(14,2) NOT NULL DEFAULT 0,
                  clave_prodserv  VARCHAR(10) NULL,
                  clave_unidad    VARCHAR(10) NULL,
                  orden           INT NOT NULL DEFAULT 0,
                  KEY idx_presupuesto (presupuesto_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\Throwable $e) { error_log('[Presupuestos] migrar partidas: ' . $e->getMessage()); }

        /*
         * Qué inspecciones cubre cada presupuesto.
         *
         * Vive de este lado a propósito: `equipos` es la tabla del corazón del
         * sistema —la que llenan inspectores, calidad y el portal del cliente—
         * y agregarle una columna de facturación la volvería también una tabla
         * de dinero. Aquí no se toca ni una línea de Certificaciones.
         *
         * La llave única sobre equipo_id es el candado que importa: una misma
         * inspección no puede quedar colgada de dos presupuestos. Facturar dos
         * veces el mismo trabajo no se descubre revisando el sistema, se
         * descubre cuando el cliente reclama.
         *
         * El control, la máquina y la fecha se copian congelados, igual que en
         * las partidas: el presupuesto tiene que seguir diciendo lo mismo
         * dentro de un año, aunque el registro del equipo se corrija después.
         */
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS pres_inspecciones (
                  id               INT AUTO_INCREMENT PRIMARY KEY,
                  presupuesto_id   INT NOT NULL,
                  equipo_id        INT NOT NULL,
                  control          VARCHAR(30)  NULL,
                  maquinaria       VARCHAR(150) NULL,
                  detalle          VARCHAR(300) NULL,
                  fecha_inspeccion DATE NULL,
                  created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  UNIQUE KEY uk_equipo (equipo_id),
                  KEY idx_presupuesto (presupuesto_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\Throwable $e) { error_log('[Presupuestos] migrar inspecciones: ' . $e->getMessage()); }

        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS pres_propuestas (
                  id             INT AUTO_INCREMENT PRIMARY KEY,
                  presupuesto_id INT NOT NULL,
                  html           MEDIUMTEXT NULL,
                  pdf_url        VARCHAR(300) NULL,
                  modelo         VARCHAR(60) NULL,
                  tokens_in      INT NOT NULL DEFAULT 0,
                  tokens_out     INT NOT NULL DEFAULT 0,
                  usuario        VARCHAR(120) NULL,
                  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  KEY idx_presupuesto (presupuesto_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\Throwable $e) { error_log('[Presupuestos] migrar propuestas: ' . $e->getMessage()); }

        // Las bases que ya tenían el módulo antes de la facturación necesitan
        // las columnas nuevas. Cada una en su propio try: en MariaDB vieja,
        // IF NOT EXISTS no existe y la sentencia falla —queremos que falle
        // sola, sin arrastrar a las demás ni tumbar el constructor.
        foreach ([
            "forma_pago     VARCHAR(3) NOT NULL DEFAULT '03'",
            "metodo_pago    VARCHAR(3) NOT NULL DEFAULT 'PUE'",
            'factura_id     VARCHAR(40) NULL',
            'factura_serie  VARCHAR(25) NULL',
            'factura_fecha  DATETIME NULL',
            'factura_modo   VARCHAR(12) NULL',
            'factura_total  DECIMAL(14,2) NULL',
            'factura_estado VARCHAR(15) NULL',
            'factura_cancelada_at DATETIME NULL',
        ] as $col) {
            try {
                $this->pdo->exec("ALTER TABLE pres_presupuestos ADD COLUMN IF NOT EXISTS $col");
            } catch (\Throwable $e) { /* ya existe, o el motor no lo soporta */ }
        }

        $this->sembrarConfig();
        $this->sembrarServicios();
    }

    /**
     * Catálogo inicial con las líneas que AVBA de veras vende. Se siembra una
     * sola vez y sólo si el catálogo está vacío: la pantalla de un módulo nuevo
     * en blanco no dice qué se espera que uno escriba, y el primer presupuesto
     * se atora ahí.
     *
     * Los precios entran en cero a propósito. Un precio inventado por el
     * sistema es peor que ningún precio: se cuela al presupuesto, de ahí a la
     * propuesta y acaba siendo lo que AVBA se comprometió a cobrar. Que lo
     * ponga quien lo cotiza.
     *
     * Las claves del SAT también quedan vacías, y el catálogo las marca en
     * ámbar. Son las que deciden si el timbrado pasa: se eligen con el buscador
     * del SAT que ya trae la pantalla, no se adivinan aquí.
     */
    private function sembrarServicios(): void {
        try {
            $hecho = $this->pdo->query("SELECT valor FROM pres_config WHERE clave = 'servicios_sembrados'")
                               ->fetchColumn();
            if ($hecho) return;

            // Si ya hay catálogo, esto no tiene nada que hacer: sólo deja la
            // marca para no volver a preguntar en cada arranque.
            $hay = (int)$this->pdo->query("SELECT COUNT(*) FROM pres_servicios")->fetchColumn();

            if ($hay === 0) {
                $entregaEquipo = "Dictamen de cumplimiento, certificado con folio y código QR verificable, "
                               . "y reporte fotográfico de la inspección.";
                $base = [
                    ['INS-GRUA',  'Inspección y certificación de grúa',
                     'Inspección de grúa hidráulica sobre camión o neumáticos, con verificación de estructura, sistema hidráulico, cables, ganchos, frenos y dispositivos de seguridad.',
                     'NOM-004-STPS-1999 · ASME B30.5', 'Equipo'],
                    ['INS-TORRE', 'Inspección y certificación de grúa torre',
                     'Inspección de grúa torre: estructura, arriostramientos, mecanismos de giro y elevación, limitadores de carga y dispositivos de seguridad.',
                     'NOM-004-STPS-1999 · ASME B30.3', 'Equipo'],
                    ['INS-MEWP',  'Inspección y certificación de plataforma de elevación (MEWP)',
                     'Inspección de plataforma de elevación de personal: estructura, sistema hidráulico, controles, paros de emergencia y sistemas anticaída.',
                     'NOM-009-STPS-2011 · ANSI A92', 'Equipo'],
                    ['INS-MONTA', 'Inspección y certificación de montacargas',
                     'Inspección de montacargas: mástil, horquillas, cadenas, sistema hidráulico, frenos, dirección y dispositivos de seguridad.',
                     'NOM-006-STPS-2014 · ASME B56.1', 'Equipo'],
                    ['INS-TELE',  'Inspección y certificación de manipulador telescópico',
                     'Inspección de telehandler: pluma telescópica, estabilizadores, sistema hidráulico, aditamentos y limitadores de carga.',
                     'NOM-006-STPS-2014 · ASME B30.22', 'Equipo'],
                    ['INS-ACC',   'Inspección y certificación de accesorios de izaje',
                     'Inspección de eslingas, grilletes, ganchos, estrobos y demás accesorios de izaje, con identificación individual y capacidad verificada.',
                     'NOM-006-STPS-2014 · ASME B30.9 · ASME B30.26', 'Pieza'],
                    ['INS-ARN',   'Inspección y certificación de equipo de protección contra caídas',
                     'Inspección de arneses, líneas de vida, absorbedores y conectores, con revisión de costuras, herrajes y vigencia del fabricante.',
                     'NOM-009-STPS-2011 · ANSI Z359', 'Pieza'],
                    ['CAP-PERS',  'Certificación de personal',
                     'Evaluación y certificación de competencia del operador, con constancia de habilidades laborales DC-3 y credencial con código QR verificable.',
                     'NOM-004-STPS-1999 · NOM-006-STPS-2014 · NOM-009-STPS-2011', 'Persona'],
                    ['PND',       'Pruebas no destructivas',
                     'Aplicación de pruebas no destructivas —líquidos penetrantes, partículas magnéticas o ultrasonido— sobre elementos estructurales y de izaje.',
                     'ASNT SNT-TC-1A', 'Servicio'],
                ];

                $ins = $this->pdo->prepare(
                    "INSERT INTO pres_servicios
                       (clave, nombre, descripcion, normas, entregables, unidad, precio, orden, activo)
                     VALUES (?, ?, ?, ?, ?, ?, 0, ?, 1)"
                );
                foreach ($base as $i => [$clave, $nombre, $desc, $normas, $unidad]) {
                    $entrega = $unidad === 'Persona'
                        ? "Constancia de habilidades laborales DC-3, credencial con código QR verificable y lista de asistencia."
                        : ($unidad === 'Servicio'
                            ? "Reporte de resultados con la técnica aplicada, criterios de aceptación e indicaciones encontradas."
                            : $entregaEquipo);
                    try {
                        $ins->execute([$clave, $nombre, $desc, $normas, $entrega, $unidad, ($i + 1) * 10]);
                    } catch (\Throwable $e) { /* clave repetida: alguien ya la capturó */ }
                }
            }

            $this->pdo->prepare("INSERT IGNORE INTO pres_config (clave, valor) VALUES ('servicios_sembrados', ?)")
                      ->execute([date('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {
            error_log('[Presupuestos] sembrarServicios: ' . $e->getMessage());
        }
    }

    /**
     * Texto base del prompt. Se siembra una sola vez; a partir de ahí lo edita
     * Administración desde la pantalla, y esa versión editada manda. Sembrar en
     * cada arranque pisaría lo que el usuario escribió.
     */
    private function sembrarConfig(): void {
        $base = [
            'perfil_empresa' =>
                "AVBA Inspections es una unidad de verificación con acreditación UVNMX 057 ante la ema.\n" .
                "Inspeccionamos y certificamos equipos de izaje, grúas, plataformas de elevación, montacargas, " .
                "accesorios de izaje y equipo de protección contra caídas.\n" .
                "Cobertura en todo México y en el extranjero.",
            'normas' =>
                "NOM-004-STPS-1999 — Sistemas de protección y dispositivos de seguridad en maquinaria.\n" .
                "NOM-006-STPS-2014 — Manejo y almacenamiento de materiales.\n" .
                "NOM-009-STPS-2011 — Trabajos en altura.\n" .
                "NOM-020-STPS-2011 — Recipientes sujetos a presión.\n" .
                "ASME B30 — Normas de seguridad para grúas y aparatos de izaje.",
            'condiciones' =>
                "Precios en moneda nacional, más IVA.\n" .
                "Vigencia de la oferta: 15 días naturales.\n" .
                "Condiciones de pago: a convenir.\n" .
                "El servicio se programa una vez recibida la orden de compra.",
            'instrucciones_ia' =>
                "Redacta en español de México, en tercera persona y en tono técnico-comercial sobrio.\n" .
                "No prometas resultados de aprobación: el dictamen depende del estado real del equipo.\n" .
                "No menciones acreditaciones, normas ni alcances que no estén en la información entregada.",
        ];
        foreach ($base as $clave => $valor) {
            try {
                $this->pdo->prepare("INSERT IGNORE INTO pres_config (clave, valor) VALUES (?, ?)")
                          ->execute([$clave, $valor]);
            } catch (\Throwable $e) {
                error_log('[Presupuestos] sembrarConfig: ' . $e->getMessage());
            }
        }
    }

    // ══════════════════════════════════════════════════════════
    //  CONFIGURACIÓN (el "prompt de nosotros")
    // ══════════════════════════════════════════════════════════

    /** Claves que la pantalla puede leer y escribir. */
    private const CONFIG_EDITABLE = ['perfil_empresa', 'normas', 'condiciones', 'instrucciones_ia'];

    public function config(): array {
        // La tabla también guarda el contador de folios. Sale de aquí filtrado:
        // no es texto que nadie deba editar desde la pantalla.
        $out = [];
        try {
            foreach ($this->pdo->query("SELECT clave, valor FROM pres_config")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (in_array($r['clave'], self::CONFIG_EDITABLE, true)) $out[$r['clave']] = (string)$r['valor'];
            }
        } catch (\Throwable $e) { error_log('[Presupuestos] config: ' . $e->getMessage()); }
        return ['status' => 'ok', 'config' => $out,
                'regimenes' => self::REGIMENES, 'usos_cfdi' => self::USOS_CFDI];
    }

    public function guardarConfig(array $p): array {
        $permitidas = self::CONFIG_EDITABLE;
        $st = $this->pdo->prepare(
            "INSERT INTO pres_config (clave, valor) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE valor = VALUES(valor)"
        );
        foreach ($permitidas as $c) {
            if (array_key_exists($c, $p)) $st->execute([$c, trim((string)$p[$c])]);
        }
        return ['status' => 'ok', 'message' => 'Configuración guardada.'];
    }

    private function cfg(string $clave, string $porDefecto = ''): string {
        try {
            $s = $this->pdo->prepare("SELECT valor FROM pres_config WHERE clave = ?");
            $s->execute([$clave]);
            $v = $s->fetchColumn();
            if ($v !== false && trim((string)$v) !== '') return (string)$v;
        } catch (\Throwable $e) { error_log('[Presupuestos] cfg: ' . $e->getMessage()); }
        return $porDefecto;
    }

    // ══════════════════════════════════════════════════════════
    //  CATÁLOGO DE SERVICIOS
    // ══════════════════════════════════════════════════════════

    public function servicios(bool $soloActivos = false): array {
        $sql = "SELECT * FROM pres_servicios";
        if ($soloActivos) $sql .= " WHERE activo = 1";
        $sql .= " ORDER BY orden ASC, nombre ASC";
        try {
            $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('[Presupuestos] servicios: ' . $e->getMessage());
            $rows = [];
        }
        return ['status' => 'ok', 'servicios' => $rows];
    }

    public function guardarServicio(array $p): array {
        $id     = (int)($p['id'] ?? 0);
        $nombre = trim((string)($p['nombre'] ?? ''));
        if ($nombre === '') return ['status' => 'error', 'message' => 'El servicio necesita un nombre.'];

        $clave = strtoupper(trim((string)($p['clave'] ?? '')));
        if ($clave === '') $clave = $this->claveDesde($nombre);

        // La clave identifica al servicio en el presupuesto y en la factura;
        // repetida, dos partidas distintas acabarían pareciendo la misma.
        $dup = $this->pdo->prepare("SELECT id FROM pres_servicios WHERE clave = ? AND id <> ?");
        $dup->execute([$clave, $id]);
        if ($dup->fetchColumn()) {
            return ['status' => 'error', 'message' => "Ya existe un servicio con la clave {$clave}."];
        }

        $campos = [
            'clave'          => $clave,
            'nombre'         => $nombre,
            'descripcion'    => trim((string)($p['descripcion'] ?? '')),
            'alcance'        => trim((string)($p['alcance'] ?? '')),
            'normas'         => trim((string)($p['normas'] ?? '')),
            'entregables'    => trim((string)($p['entregables'] ?? '')),
            'unidad'         => trim((string)($p['unidad'] ?? '')) ?: 'Servicio',
            'precio'         => $this->dinero($p['precio'] ?? 0),
            'moneda'         => $this->moneda($p['moneda'] ?? 'MXN'),
            'tasa_iva'       => $this->tasa($p['tasa_iva'] ?? self::IVA_DEFAULT),
            'clave_prodserv' => preg_replace('/\D/', '', (string)($p['clave_prodserv'] ?? '')),
            'clave_unidad'   => strtoupper(trim((string)($p['clave_unidad'] ?? ''))),
            'orden'          => (int)($p['orden'] ?? 0),
            'activo'         => !empty($p['activo']) ? 1 : 0,
        ];

        if ($id > 0) {
            $sets = implode(', ', array_map(fn($c) => "$c = ?", array_keys($campos)));
            $st = $this->pdo->prepare("UPDATE pres_servicios SET $sets WHERE id = ?");
            $st->execute([...array_values($campos), $id]);
            return ['status' => 'ok', 'id' => $id, 'message' => 'Servicio actualizado.'];
        }

        $cols = implode(', ', array_keys($campos));
        $marc = implode(', ', array_fill(0, count($campos), '?'));
        $this->pdo->prepare("INSERT INTO pres_servicios ($cols) VALUES ($marc)")
                  ->execute(array_values($campos));
        return ['status' => 'ok', 'id' => (int)$this->pdo->lastInsertId(), 'message' => 'Servicio agregado.'];
    }

    /**
     * Borra el servicio del catálogo, no de los presupuestos que ya lo usan.
     * Las partidas guardan su propia copia de descripción y precio: un
     * presupuesto emitido tiene que seguir diciendo lo mismo dentro de un año,
     * aunque el catálogo haya cambiado tres veces.
     */
    public function eliminarServicio(int $id): array {
        if ($id <= 0) return ['status' => 'error', 'message' => 'Servicio no válido.'];
        $this->pdo->prepare("DELETE FROM pres_servicios WHERE id = ?")->execute([$id]);
        return ['status' => 'ok', 'message' => 'Servicio eliminado del catálogo.'];
    }

    private function claveDesde(string $nombre): string {
        $base = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', $this->sinAcentos($nombre)));
        $base = trim(substr($base, 0, 24), '-');
        if ($base === '') $base = 'SERV';
        $clave = $base;
        $n = 1;
        while (true) {
            $s = $this->pdo->prepare("SELECT id FROM pres_servicios WHERE clave = ?");
            $s->execute([$clave]);
            if (!$s->fetchColumn()) return $clave;
            $clave = $base . '-' . (++$n);
        }
    }

    private function sinAcentos(string $s): string {
        return strtr($s, [
            'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u',
            'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N','Ü'=>'U',
        ]);
    }

    // ══════════════════════════════════════════════════════════
    //  CLIENTES (con sus datos fiscales)
    // ══════════════════════════════════════════════════════════

    /**
     * Trae al catálogo de presupuestos los clientes que ya existen en el
     * directorio de AVBA (tabla `clientes`, la que alimentan Certificaciones y
     * Personal). Sin esto habría que recapturar a mano toda la cartera, y peor:
     * el mismo cliente acabaría escrito de dos formas distintas, una en cada
     * módulo, sin manera de saber cuál es la buena.
     *
     * El reparto es: el directorio manda sobre la identidad (el nombre), y los
     * datos fiscales que el SAT exige para timbrar —RFC, régimen, CP, uso de
     * CFDI— viven aquí, porque aquella tabla no los tiene. Lo que se capture en
     * presupuestos no se pisa nunca: la sincronización sólo da de alta lo que
     * falta y corrige el nombre si allá lo cambiaron.
     */
    private function sincronizarDirectorio(): void {
        try {
            // Las columnas de `clientes` han ido creciendo con los módulos que
            // la usan (Personal las agrega sobre la marcha). Se pregunta antes
            // de leer, para no romper en una base que todavía no las tenga.
            $cols = [];
            foreach ($this->pdo->query("SHOW COLUMNS FROM clientes")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $c) {
                $cols[strtolower((string)$c['Field'])] = true;
            }
            if (!isset($cols['nombre_cliente'])) return;

            $extra = array_values(array_filter(
                ['rfc', 'direccion', 'correo_contacto', 'representante'],
                fn($c) => isset($cols[$c])
            ));
            $sel = 'id, nombre_cliente' . ($extra ? ', ' . implode(', ', $extra) : '');
            $dir = $this->pdo->query("SELECT $sel FROM clientes")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (!$dir) return;

            $ya = [];
            foreach ($this->pdo->query(
                "SELECT id, cliente_id FROM pres_clientes WHERE cliente_id IS NOT NULL"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $ya[(int)$r['cliente_id']] = (int)$r['id'];
            }

            $ins = $this->pdo->prepare(
                "INSERT INTO pres_clientes (cliente_id, nombre_comercial, rfc, direccion, correo, contacto)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            // Sólo escribe si de veras cambió: así una carga de la pestaña no
            // deja una tanda de UPDATE inútiles en la bitácora del servidor.
            $upd = $this->pdo->prepare(
                "UPDATE pres_clientes SET nombre_comercial = ? WHERE id = ? AND nombre_comercial <> ?"
            );

            foreach ($dir as $c) {
                $cid    = (int)$c['id'];
                $nombre = trim((string)$c['nombre_cliente']);
                if ($cid <= 0 || $nombre === '') continue;

                if (isset($ya[$cid])) { $upd->execute([$nombre, $ya[$cid], $nombre]); continue; }

                // El RFC del directorio se capturó para otros fines y puede
                // venir con formato libre. Si no pasa el patrón del SAT entra
                // vacío: mejor que lo pidan a que el timbrado lo rechace.
                $rfc = strtoupper(preg_replace('/\s+/', '', (string)($c['rfc'] ?? '')));
                if ($rfc !== '' && !preg_match('/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/', $rfc)) $rfc = '';

                $correo = trim((string)($c['correo_contacto'] ?? ''));
                if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) $correo = '';

                $ins->execute([
                    $cid, mb_substr($nombre, 0, 200), $rfc,
                    mb_substr(trim((string)($c['direccion'] ?? '')), 0, 300),
                    mb_substr($correo, 0, 200),
                    mb_substr(trim((string)($c['representante'] ?? '')), 0, 150),
                ]);
            }
        } catch (\Throwable $e) {
            // Que el directorio no se deje leer no debe dejar sin catálogo al
            // módulo: sigue sirviendo con los clientes capturados aquí.
            error_log('[Presupuestos] sincronizar directorio: ' . $e->getMessage());
        }
    }

    public function clientes(string $busca = ''): array {
        $this->sincronizarDirectorio();
        try {
            if (trim($busca) !== '') {
                $st = $this->pdo->prepare(
                    "SELECT * FROM pres_clientes
                     WHERE nombre_comercial LIKE ? OR razon_social LIKE ? OR rfc LIKE ?
                     ORDER BY nombre_comercial ASC"
                );
                $like = '%' . trim($busca) . '%';
                $st->execute([$like, $like, $like]);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } else {
                $rows = $this->pdo->query(
                    "SELECT * FROM pres_clientes ORDER BY nombre_comercial ASC"
                )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
        } catch (\Throwable $e) {
            error_log('[Presupuestos] clientes: ' . $e->getMessage());
            $rows = [];
        }
        // Marca lo que le falta a cada cliente para poder facturarle. Descubrirlo
        // al momento de timbrar, con el cliente esperando, es demasiado tarde.
        foreach ($rows as &$r) {
            $r['falta_fiscal'] = $this->faltaFiscal($r);
            $r['origen']       = !empty($r['cliente_id']) ? 'directorio' : 'presupuestos';
        }
        unset($r);
        return ['status' => 'ok', 'clientes' => $rows];
    }

    /** Devuelve la lista de datos fiscales que impedirían timbrar. */
    private function faltaFiscal(array $c): array {
        $falta = [];
        $rfc = strtoupper(trim((string)($c['rfc'] ?? '')));
        if ($rfc === '' || !preg_match('/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/', $rfc)) $falta[] = 'RFC';
        if (trim((string)($c['razon_social'] ?? '')) === '')                      $falta[] = 'razón social';
        if (!preg_match('/^\d{5}$/', (string)($c['cp_fiscal'] ?? '')))            $falta[] = 'código postal fiscal';
        if (!isset(self::REGIMENES[(string)($c['regimen_fiscal'] ?? '')]))        $falta[] = 'régimen fiscal';
        return $falta;
    }

    // ══════════════════════════════════════════════════════════
    //  LECTURA DE LA CONSTANCIA DE SITUACIÓN FISCAL
    // ══════════════════════════════════════════════════════════

    /** Tipos que la API sabe leer, con su extensión para el mensaje de error. */
    private const CSF_TIPOS = [
        'application/pdf' => 'pdf',
        'image/jpeg'      => 'imagen',
        'image/png'       => 'imagen',
        'image/webp'      => 'imagen',
    ];

    /** 10 MB. El límite de la API es mayor, pero un PDF sano no pesa esto. */
    private const CSF_MAX_BYTES = 10485760;

    /**
     * Lee una constancia de situación fiscal y devuelve los campos que el SAT
     * exige para timbrar.
     *
     * Dos reglas que definen esta función:
     *
     * 1. NO guarda nada. Devuelve los datos para que se revisen en pantalla y
     *    sea una persona quien apriete Guardar. Un RFC mal leído que entra solo
     *    al catálogo no se descubre hasta que el timbrado lo rechaza, con el
     *    cliente esperando su factura.
     *
     * 2. Lo que el modelo devuelve se valida aquí contra las mismas reglas que
     *    la captura a mano: el RFC contra su patrón, el CP contra cinco
     *    dígitos, el régimen contra el catálogo del SAT. Lo que no pasa se
     *    descarta y se reporta como no leído, en vez de colarse.
     *
     * El archivo tampoco se conserva: se lee, se manda y se descarta. Una
     * constancia trae domicilio y datos personales del contribuyente; no hay
     * razón para acumularlas en el servidor si lo que se quería eran cinco
     * campos.
     */
    public function leerConstanciaFiscal(array $archivo): array {
        $err = (int)($archivo['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) {
            return ['status' => 'error', 'message' => 'No llegó ningún archivo.'];
        }
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            return ['status' => 'error', 'message' => 'El archivo excede el tamaño que admite el servidor.'];
        }
        if ($err !== UPLOAD_ERR_OK) {
            return ['status' => 'error', 'message' => 'La subida del archivo falló. Intenta de nuevo.'];
        }

        $ruta = (string)($archivo['tmp_name'] ?? '');
        if ($ruta === '' || !is_uploaded_file($ruta)) {
            return ['status' => 'error', 'message' => 'Archivo no válido.'];
        }
        if (filesize($ruta) > self::CSF_MAX_BYTES) {
            return ['status' => 'error', 'message' => 'La constancia pesa más de 10 MB. Sube el PDF original, no un escaneo grande.'];
        }

        // El tipo se decide leyendo el archivo, no por lo que diga el nombre ni
        // el navegador: ese dato lo pone quien sube.
        $mime = '';
        if (function_exists('finfo_open') && ($fi = finfo_open(FILEINFO_MIME_TYPE))) {
            $mime = (string)finfo_file($fi, $ruta);
            finfo_close($fi);
        }
        if (!isset(self::CSF_TIPOS[$mime])) {
            return ['status' => 'error',
                'message' => 'La constancia debe ser PDF o una imagen (JPG, PNG o WEBP).'];
        }

        $bin = @file_get_contents($ruta);
        if ($bin === false || $bin === '') {
            return ['status' => 'error', 'message' => 'No se pudo leer el archivo.'];
        }

        $ia = new ClaudeIA();
        if (!$ia->disponible()) {
            return ['status' => 'error',
                'message' => 'La lectura automática no está configurada en el servidor (falta CLAUDE_API_KEY). '
                           . 'Puedes capturar los datos a mano.'];
        }

        $regimenes = [];
        foreach (self::REGIMENES as $clave => $nombre) $regimenes[] = "$clave = $nombre";

        $system = "Eres un asistente que lee Constancias de Situación Fiscal del SAT (México) y extrae "
                . "los datos de identificación fiscal del contribuyente.\n\n"
                . "Devuelve ÚNICAMENTE un objeto JSON, sin texto antes ni después y sin cercas de código, "
                . "con exactamente estas claves:\n"
                . "  rfc            — el RFC del contribuyente, 12 o 13 caracteres, sin espacios ni guiones.\n"
                . "  razon_social   — para persona moral, la denominación o razón social TAL CUAL aparece, "
                . "incluyendo el régimen de capital (S.A. DE C.V., S. DE R.L., etc.) si la constancia lo trae. "
                . "Para persona física, el nombre completo: nombre y apellidos.\n"
                . "  cp_fiscal      — el código postal del domicilio fiscal, 5 dígitos.\n"
                . "  regimen_fiscal — la CLAVE numérica de tres dígitos del régimen. Si la constancia lista "
                . "varios regímenes vigentes, elige el que corresponda a la actividad principal. Claves válidas:\n    "
                . implode("\n    ", $regimenes) . "\n"
                . "  direccion      — el domicilio fiscal en una sola línea (calle, número, colonia, "
                . "municipio y estado).\n\n"
                . "Reglas:\n"
                . "- Copia los datos literalmente de la constancia. No corrijas ortografía, no completes "
                . "abreviaturas y no deduzcas nada que no esté escrito.\n"
                . "- Si un dato no aparece o no puedes leerlo con certeza, pon null en esa clave. "
                . "Es preferible null a un dato inventado: con estos campos se timbran facturas.\n"
                . "- Si el documento NO es una constancia de situación fiscal, devuelve "
                . "{\"error\":\"no_es_csf\"}.";

        $r = $ia->mensaje(
            $system,
            'Extrae los datos fiscales de esta constancia y devuélvelos como JSON.',
            [[
                'tipo'       => self::CSF_TIPOS[$mime],
                'media_type' => $mime,
                'datos'      => base64_encode($bin),
            ]]
        );
        unset($bin);

        if (($r['status'] ?? '') !== 'ok') return $r;

        $datos = $this->jsonDeRespuesta((string)($r['texto'] ?? ''));
        if ($datos === null) {
            error_log('[Presupuestos] CSF: respuesta no interpretable');
            return ['status' => 'error',
                'message' => 'No se pudo interpretar la lectura. Captura los datos a mano.'];
        }
        if (($datos['error'] ?? '') === 'no_es_csf') {
            return ['status' => 'error',
                'message' => 'Ese documento no parece una constancia de situación fiscal.'];
        }

        return ['status' => 'ok'] + $this->validarDatosCsf($datos);
    }

    /**
     * Saca el objeto JSON de la respuesta.
     *
     * Se pidió JSON puro, pero un modelo puede envolverlo en cercas de código o
     * anteponer una frase. Recortar entre la primera llave y la última es más
     * barato que fallar por una tilde de más.
     */
    private function jsonDeRespuesta(string $texto): ?array {
        $t = trim($texto);
        $t = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $t);
        $ini = strpos($t, '{');
        $fin = strrpos($t, '}');
        if ($ini === false || $fin === false || $fin <= $ini) return null;
        $j = json_decode(substr($t, $ini, $fin - $ini + 1), true);
        return is_array($j) ? $j : null;
    }

    /**
     * Filtra lo leído con las mismas reglas de la captura a mano. Lo que no
     * pasa no se devuelve: se nombra como no leído para que se capture.
     */
    private function validarDatosCsf(array $d): array {
        $campos = [];
        $noLeidos = [];

        // Al RFC leído por máquina se le exige además que la fecha exista. El
        // patrón de siempre da por buena una cifra cualquiera, y un dígito mal
        // reconocido —un 3 por un 8 en el mes— produce justo eso: un RFC con
        // forma correcta y fecha imposible. Vale más pedirlo a mano.
        $rfc = strtoupper(preg_replace('/[^A-Za-z0-9Ññ&]/', '', (string)($d['rfc'] ?? '')));
        $rfcOk = $rfc !== '' && preg_match('/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/', $rfc);
        if ($rfcOk) {
            $f = substr($rfc, strlen($rfc) === 13 ? 4 : 3, 6);
            $rfcOk = checkdate((int)substr($f, 2, 2), (int)substr($f, 4, 2), 2000 + (int)substr($f, 0, 2));
        }
        if ($rfcOk) $campos['rfc'] = $rfc;
        else        $noLeidos[] = 'RFC';

        $razon = trim(preg_replace('/\s+/', ' ', (string)($d['razon_social'] ?? '')));
        if ($razon !== '') $campos['razon_social'] = mb_substr($razon, 0, 250);
        else $noLeidos[] = 'razón social';

        $cp = preg_replace('/\D/', '', (string)($d['cp_fiscal'] ?? ''));
        if (preg_match('/^\d{5}$/', $cp)) $campos['cp_fiscal'] = $cp;
        else $noLeidos[] = 'código postal fiscal';

        // Puede venir la clave o el nombre del régimen; se aceptan las dos, pero
        // sólo si corresponden a un régimen que el SAT reconoce.
        $reg  = trim((string)($d['regimen_fiscal'] ?? ''));
        $codigo = '';
        if (isset(self::REGIMENES[$reg])) {
            $codigo = $reg;
        } elseif ($reg !== '') {
            $norm = fn($s) => strtolower(trim(preg_replace('/\s+/', ' ',
                strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','Á'=>'a','É'=>'e','Í'=>'i','Ó'=>'o','Ú'=>'u']))));
            // El casteo a cadena no es cosmético: PHP convierte a entero las
            // claves numéricas del catálogo, y la columna guarda texto.
            foreach (self::REGIMENES as $c => $n) {
                if ($norm($n) === $norm($reg)) { $codigo = (string)$c; break; }
            }
        }
        if ($codigo !== '') $campos['regimen_fiscal'] = $codigo;
        else $noLeidos[] = 'régimen fiscal';

        $dir = trim(preg_replace('/\s+/', ' ', (string)($d['direccion'] ?? '')));
        if ($dir !== '') $campos['direccion'] = mb_substr($dir, 0, 300);

        return ['campos' => $campos, 'no_leidos' => $noLeidos];
    }

    public function guardarCliente(array $p): array {
        $id     = (int)($p['id'] ?? 0);
        $nombre = trim((string)($p['nombre_comercial'] ?? ''));
        if ($nombre === '') return ['status' => 'error', 'message' => 'El cliente necesita un nombre.'];

        $rfc = strtoupper(preg_replace('/\s+/', '', (string)($p['rfc'] ?? '')));
        if ($rfc !== '' && !preg_match('/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/', $rfc)) {
            return ['status' => 'error', 'message' => 'El RFC no tiene un formato válido.'];
        }
        $cp = preg_replace('/\D/', '', (string)($p['cp_fiscal'] ?? ''));
        if ($cp !== '' && !preg_match('/^\d{5}$/', $cp)) {
            return ['status' => 'error', 'message' => 'El código postal fiscal debe tener 5 dígitos.'];
        }
        $reg = trim((string)($p['regimen_fiscal'] ?? ''));
        if ($reg !== '' && !isset(self::REGIMENES[$reg])) {
            return ['status' => 'error', 'message' => 'Régimen fiscal no reconocido.'];
        }
        $uso = strtoupper(trim((string)($p['uso_cfdi'] ?? 'G03')));
        if (!isset(self::USOS_CFDI[$uso])) $uso = 'G03';

        $correo = trim((string)($p['correo'] ?? ''));
        if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            return ['status' => 'error', 'message' => 'El correo del cliente no es válido.'];
        }

        $campos = [
            'cliente_id'       => ($p['cliente_id'] ?? null) ? (int)$p['cliente_id'] : null,
            'nombre_comercial' => $nombre,
            'razon_social'     => trim((string)($p['razon_social'] ?? '')),
            'rfc'              => $rfc,
            'cp_fiscal'        => $cp,
            'regimen_fiscal'   => $reg,
            'uso_cfdi'         => $uso,
            'correo'           => $correo,
            'telefono'         => trim((string)($p['telefono'] ?? '')),
            'contacto'         => trim((string)($p['contacto'] ?? '')),
            'direccion'        => trim((string)($p['direccion'] ?? '')),
            'notas'            => trim((string)($p['notas'] ?? '')),
            'activo'           => array_key_exists('activo', $p) ? (!empty($p['activo']) ? 1 : 0) : 1,
        ];

        if ($id > 0) {
            // El vínculo con el directorio de AVBA no se toca al editar: el
            // formulario no lo manda, y borrarlo haría que la sincronización
            // diera de alta al mismo cliente otra vez, ahora duplicado.
            if (!array_key_exists('cliente_id', $p)) {
                $st = $this->pdo->prepare("SELECT cliente_id FROM pres_clientes WHERE id = ?");
                $st->execute([$id]);
                $prev = $st->fetchColumn();
                $campos['cliente_id'] = ($prev === false || $prev === null) ? null : (int)$prev;
            }
            $sets = implode(', ', array_map(fn($c) => "$c = ?", array_keys($campos)));
            $this->pdo->prepare("UPDATE pres_clientes SET $sets WHERE id = ?")
                      ->execute([...array_values($campos), $id]);
            return ['status' => 'ok', 'id' => $id, 'message' => 'Cliente actualizado.'];
        }

        $cols = implode(', ', array_keys($campos));
        $marc = implode(', ', array_fill(0, count($campos), '?'));
        $this->pdo->prepare("INSERT INTO pres_clientes ($cols) VALUES ($marc)")
                  ->execute(array_values($campos));
        return ['status' => 'ok', 'id' => (int)$this->pdo->lastInsertId(), 'message' => 'Cliente agregado.'];
    }

    public function eliminarCliente(int $id): array {
        if ($id <= 0) return ['status' => 'error', 'message' => 'Cliente no válido.'];

        // A los que vienen del directorio de AVBA no tiene caso borrarlos aquí:
        // la siguiente carga de la pestaña los daría de alta otra vez, y de
        // paso se perderían sus datos fiscales. Se desactivan.
        $s = $this->pdo->prepare("SELECT cliente_id FROM pres_clientes WHERE id = ?");
        $s->execute([$id]);
        if ((int)$s->fetchColumn() > 0) {
            return ['status' => 'error',
                'message' => 'Ese cliente viene del directorio de AVBA y se da de baja desde Personal. '
                           . 'Aquí puedes desactivarlo para que deje de aparecer en los presupuestos.'];
        }

        $s = $this->pdo->prepare("SELECT COUNT(*) FROM pres_presupuestos WHERE cliente_pres_id = ?");
        $s->execute([$id]);
        if ((int)$s->fetchColumn() > 0) {
            return ['status' => 'error',
                'message' => 'Ese cliente tiene presupuestos. Desactívalo en lugar de borrarlo.'];
        }
        $this->pdo->prepare("DELETE FROM pres_clientes WHERE id = ?")->execute([$id]);
        return ['status' => 'ok', 'message' => 'Cliente eliminado.'];
    }

    // ══════════════════════════════════════════════════════════
    //  INSPECCIONES QUE SE PUEDEN COBRAR
    // ══════════════════════════════════════════════════════════

    /**
     * Las inspecciones de un cliente que ya están publicadas en su portal.
     *
     * Ése es el criterio de "trabajo entregado", y no lo inventa este módulo:
     * es el mismo con el que ClienteEquipos arma "Mis Equipos" —estado
     * 'ENVIADO' y el folio de control con el prefijo del cliente—. Certificaciones
     * pone las tres marcas juntas al emitir el certificado: estado, publicado y
     * fecha_enviado. Si algún día cambia esa regla, cambia en un solo lugar y
     * aquí se sigue cobrando lo mismo que el cliente ve.
     *
     * El cruce con el cliente NO es por nombre. El folio de control se arma como
     * "{primera_parte}-{consecutivo}", así que se navega por la llave:
     * pres_clientes.cliente_id → clientes.primera_parte → prefijo del control.
     * Emparejar por nombre en esta base es justo lo que falla en silencio: hay
     * dos colaciones distintas conviviendo y el resultado sería una lista vacía
     * sin explicación.
     */
    public function inspeccionesDisponibles(int $clientePresId, int $presupuestoId = 0): array {
        if ($clientePresId <= 0) {
            return ['status' => 'ok', 'inspecciones' => [],
                'aviso' => 'Elige primero el cliente del presupuesto.'];
        }
        try {
            $sc = $this->pdo->prepare("SELECT cliente_id, nombre_comercial FROM pres_clientes WHERE id = ?");
            $sc->execute([$clientePresId]);
            $cli = $sc->fetch(PDO::FETCH_ASSOC);
            if (!$cli) return ['status' => 'error', 'message' => 'Cliente no encontrado.'];

            // Un cliente capturado sólo aquí no tiene expediente en
            // Certificaciones, así que no hay inspecciones que traerle. Decirlo
            // es mejor que devolver una lista vacía que parece una falla.
            if (empty($cli['cliente_id'])) {
                return ['status' => 'ok', 'inspecciones' => [],
                    'aviso' => 'Este cliente se capturó sólo en presupuestos, no viene del directorio de AVBA, '
                             . 'así que no tiene inspecciones en Certificaciones.'];
            }

            $sp = $this->pdo->prepare("SELECT primera_parte FROM clientes WHERE id = ?");
            $sp->execute([(int)$cli['cliente_id']]);
            $pp = trim((string)$sp->fetchColumn());
            if ($pp === '') {
                return ['status' => 'ok', 'inspecciones' => [],
                    'aviso' => 'El cliente no tiene número de expediente en el directorio.'];
            }

            // generarControl() rellena el prefijo a cinco dígitos; el directorio
            // lo guarda como se capturó. Se buscan las dos formas para que un
            // expediente viejo y corto no quede fuera.
            $prefijos = array_values(array_unique([$pp . '-%', str_pad($pp, 5, '0', STR_PAD_LEFT) . '-%']));
            $marcas   = implode(' OR ', array_fill(0, count($prefijos), 'e.control LIKE ?'));

            // `publicado` la agrega ensurePublicado() sobre la marcha; puede no
            // estar en una base que todavía no ha pasado por ahí.
            $hayPublicado = false;
            try {
                $hayPublicado = (bool)$this->pdo->query("SHOW COLUMNS FROM equipos LIKE 'publicado'")->fetch();
            } catch (\Throwable $e) { /* se decide sin ella */ }
            $filtroPub = $hayPublicado ? ' AND e.publicado = 1' : '';

            /*
             * Dos grupos entran a la lista:
             *
             *   1. Lo publicado en el portal de este cliente, que es lo que se
             *      puede empezar a cobrar.
             *   2. Lo que ESTE presupuesto ya trae enganchado, sin importar en
             *      qué estado esté hoy.
             *
             * El segundo grupo no es un adorno. Certificaciones puede regresar a
             * Calidad una inspección ya enviada (estado 'RETORNADO'); si eso pasa
             * después de engancharla, sin esta rama desaparecería de la lista y
             * al guardar el presupuesto su propia inspección se rechazaría por
             * "ya no disponible", dejándolo imposible de editar.
             */
            $st = $this->pdo->prepare("
                SELECT e.id, e.control, e.maquinaria, e.marca, e.modelo, e.serie, e.id_equipo,
                       e.capacidad, e.fecha_inspeccion, e.estado, e.certificado_url, e.dictamen_url,
                       pi.presupuesto_id AS tomada_por_id, pp.folio AS tomada_por_folio
                  FROM equipos e
                  LEFT JOIN pres_inspecciones pi ON pi.equipo_id = e.id
                  LEFT JOIN pres_presupuestos pp ON pp.id = pi.presupuesto_id
                 WHERE pi.presupuesto_id = ?
                    OR (e.estado = 'ENVIADO'$filtroPub AND ($marcas))
                 ORDER BY e.fecha_inspeccion DESC, e.id DESC
            ");
            $st->execute([$presupuestoId, ...$prefijos]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('[Presupuestos] inspeccionesDisponibles: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'No se pudieron leer las inspecciones.'];
        }

        $out = [];
        foreach ($rows as $r) {
            $tomadaPor = (int)($r['tomada_por_id'] ?? 0);
            $out[] = [
                'id'               => (int)$r['id'],
                'control'          => (string)($r['control'] ?? ''),
                'maquinaria'       => (string)($r['maquinaria'] ?? ''),
                'detalle'          => $this->detalleEquipo($r),
                'fecha_inspeccion' => $r['fecha_inspeccion'],
                'tiene_certificado'=> !empty($r['certificado_url']) || !empty($r['dictamen_url']),
                // Se enganchó estando publicada y después Calidad la regresó.
                // Se avisa en vez de esconderla: puede que el presupuesto ya no
                // deba cobrarla.
                'regreso_a_calidad'=> (string)($r['estado'] ?? '') !== 'ENVIADO',
                // Ya está en este presupuesto: se muestra palomeada.
                'en_este'          => $presupuestoId > 0 && $tomadaPor === $presupuestoId,
                // Está en otro: se muestra bloqueada y se dice en cuál, para que
                // se pueda ir a verlo en vez de quedarse adivinando.
                'ocupada_por'      => ($tomadaPor > 0 && $tomadaPor !== $presupuestoId)
                                      ? (string)($r['tomada_por_folio'] ?? '') : '',
            ];
        }
        return ['status' => 'ok', 'inspecciones' => $out];
    }

    /** Cómo se nombra un equipo en el presupuesto y en la propuesta. */
    private function detalleEquipo(array $e): string {
        $p = [];
        foreach (['maquinaria', 'marca', 'modelo'] as $k) {
            $v = trim((string)($e[$k] ?? ''));
            if ($v !== '') $p[] = $v;
        }
        $txt = implode(' ', $p);
        $serie = trim((string)($e['serie'] ?? ''));
        if ($serie !== '') $txt .= ' · s/n ' . $serie;
        $eco = trim((string)($e['id_equipo'] ?? ''));
        if ($eco !== '') $txt .= ' · núm. ' . $eco;
        return mb_substr(trim($txt) ?: 'Equipo sin identificar', 0, 300);
    }

    /** Las inspecciones ya enganchadas a un presupuesto. */
    private function inspeccionesDe(int $presupuestoId): array {
        try {
            $st = $this->pdo->prepare(
                "SELECT equipo_id, control, maquinaria, detalle, fecha_inspeccion
                   FROM pres_inspecciones WHERE presupuesto_id = ?
                  ORDER BY fecha_inspeccion DESC, id ASC"
            );
            $st->execute([$presupuestoId]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) { return []; }
    }

    // ══════════════════════════════════════════════════════════
    //  NORMALIZADORES
    // ══════════════════════════════════════════════════════════

    private function dinero($v): float {
        $n = (float)str_replace([',', '$', ' '], '', (string)$v);
        return round(max(0, $n), 2);
    }

    private function cantidad($v): float {
        $n = (float)str_replace([',', ' '], '', (string)$v);
        return round(max(0, $n), 2);
    }

    private function moneda($v): string {
        $m = strtoupper(trim((string)$v));
        return in_array($m, ['MXN', 'USD', 'EUR'], true) ? $m : 'MXN';
    }

    private function tasa($v): float {
        $n = (float)$v;
        return round(min(100, max(0, $n)), 2);
    }

    private function porcentaje($v): float {
        $n = (float)$v;
        return round(min(100, max(0, $n)), 2);
    }

    // ══════════════════════════════════════════════════════════
    //  PRESUPUESTOS
    // ══════════════════════════════════════════════════════════

    /**
     * Folio consecutivo por año: PRE-2026-0001.
     *
     * El número sale de un contador que sólo avanza, no de contar filas ni de
     * mirar el mayor existente. La diferencia importa cuando se borra un
     * presupuesto: con cualquiera de esas dos cuentas, eliminar el último
     * devolvería su número al siguiente, y dos clientes distintos acabarían
     * con papeles que dicen el mismo folio. El contador no retrocede.
     *
     * Si el contador se perdiera —una base restaurada a medias, por ejemplo—
     * se recupera del mayor folio que sí exista, para no volver a empezar en 1.
     * La columna es UNIQUE: si dos capturas simultáneas llegaran al mismo
     * número, la segunda falla en vez de duplicarlo en silencio.
     */
    private function siguienteFolio(): string {
        $anio  = (int)date('Y');
        $clave = 'folio_' . $anio;
        $n = 0;
        try {
            $s = $this->pdo->prepare("SELECT valor FROM pres_config WHERE clave = ?");
            $s->execute([$clave]);
            $n = (int)($s->fetchColumn() ?: 0);

            $m = $this->pdo->prepare(
                "SELECT folio FROM pres_presupuestos WHERE folio LIKE ? ORDER BY folio DESC LIMIT 1"
            );
            $m->execute(["PRE-{$anio}-%"]);
            $ult = (string)($m->fetchColumn() ?: '');
            if ($ult !== '') $n = max($n, (int)substr($ult, -4));
        } catch (\Throwable $e) {
            error_log('[Presupuestos] siguienteFolio: ' . $e->getMessage());
        }
        $n++;
        try {
            $this->pdo->prepare(
                "INSERT INTO pres_config (clave, valor) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE valor = VALUES(valor)"
            )->execute([$clave, (string)$n]);
        } catch (\Throwable $e) {
            error_log('[Presupuestos] siguienteFolio contador: ' . $e->getMessage());
        }
        return sprintf('PRE-%d-%04d', $anio, $n);
    }

    public function listar(string $estado = '', string $busca = ''): array {
        $sql = "SELECT p.*,
                       (SELECT COUNT(*) FROM pres_partidas d WHERE d.presupuesto_id = p.id) AS partidas,
                       (SELECT COUNT(*) FROM pres_propuestas r WHERE r.presupuesto_id = p.id) AS propuestas
                FROM pres_presupuestos p WHERE 1=1";
        $args = [];
        if ($estado !== '' && in_array($estado, self::ESTADOS, true)) {
            $sql .= " AND p.estado = ?";
            $args[] = $estado;
        }
        if (trim($busca) !== '') {
            $sql .= " AND (p.folio LIKE ? OR p.cliente_nombre LIKE ?)";
            $like = '%' . trim($busca) . '%';
            $args[] = $like; $args[] = $like;
        }
        $sql .= " ORDER BY p.fecha DESC, p.id DESC LIMIT 500";
        try {
            $st = $this->pdo->prepare($sql);
            $st->execute($args);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('[Presupuestos] listar: ' . $e->getMessage());
            $rows = [];
        }
        $hoy = new DateTimeImmutable('today');
        foreach ($rows as &$r) {
            // "Vencido" sólo tiene sentido mientras la oferta sigue en pie.
            // Un presupuesto ya aceptado o facturado no vence.
            $vence = (new DateTimeImmutable((string)$r['fecha']))
                        ->modify('+' . (int)$r['vigencia_dias'] . ' days');
            $r['vence_el'] = $vence->format('Y-m-d');
            $r['vencido']  = in_array($r['estado'], ['BORRADOR', 'ENVIADO'], true) && $vence < $hoy;
        }
        return ['status' => 'ok', 'presupuestos' => $rows, 'estados' => self::ESTADOS];
    }

    public function detalle(int $id): array {
        if ($id <= 0) return ['status' => 'error', 'message' => 'Presupuesto no válido.'];
        $st = $this->pdo->prepare("SELECT * FROM pres_presupuestos WHERE id = ?");
        $st->execute([$id]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) return ['status' => 'error', 'message' => 'Presupuesto no encontrado.'];

        $sp = $this->pdo->prepare("SELECT * FROM pres_partidas WHERE presupuesto_id = ? ORDER BY orden ASC, id ASC");
        $sp->execute([$id]);
        $p['partidas'] = $sp->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $p['inspecciones'] = $this->inspeccionesDe($id);

        $sr = $this->pdo->prepare(
            "SELECT id, pdf_url, modelo, tokens_in, tokens_out, usuario, created_at
             FROM pres_propuestas WHERE presupuesto_id = ? ORDER BY id DESC"
        );
        $sr->execute([$id]);
        $p['propuestas'] = $sr->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (!empty($p['cliente_pres_id'])) {
            $sc = $this->pdo->prepare("SELECT * FROM pres_clientes WHERE id = ?");
            $sc->execute([(int)$p['cliente_pres_id']]);
            $cli = $sc->fetch(PDO::FETCH_ASSOC);
            if ($cli) {
                $cli['falta_fiscal'] = $this->faltaFiscal($cli);
                $p['cliente'] = $cli;
            }
        }
        return ['status' => 'ok', 'presupuesto' => $p];
    }

    /**
     * Crea o actualiza un presupuesto con sus partidas.
     *
     * Las partidas se reemplazan enteras en lugar de irse comparando una por
     * una: es una lista corta que se edita de golpe en la pantalla, y calcar el
     * estado exacto de lo que ve el usuario evita el peor error posible aquí,
     * que es una partida fantasma cobrando de más.
     */
    public function guardar(array $p, string $usuario): array {
        $id = (int)($p['id'] ?? 0);

        if ($id > 0) {
            $s = $this->pdo->prepare("SELECT estado FROM pres_presupuestos WHERE id = ?");
            $s->execute([$id]);
            $estado = (string)($s->fetchColumn() ?: '');
            if ($estado === '') return ['status' => 'error', 'message' => 'Presupuesto no encontrado.'];
            // Un presupuesto ya facturado sostiene un CFDI. Cambiarle los
            // importes dejaría la factura describiendo algo que ya no existe.
            if ($estado === 'FACTURADO') {
                return ['status' => 'error',
                    'message' => 'Este presupuesto ya está facturado y no puede modificarse.'];
            }
        }

        $clienteId = (int)($p['cliente_pres_id'] ?? 0);
        $cliente   = null;
        if ($clienteId > 0) {
            $sc = $this->pdo->prepare("SELECT * FROM pres_clientes WHERE id = ?");
            $sc->execute([$clienteId]);
            $cliente = $sc->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $nombre = $cliente['nombre_comercial'] ?? trim((string)($p['cliente_nombre'] ?? ''));
        if ($nombre === '') return ['status' => 'error', 'message' => 'Falta el cliente.'];

        $partidas = $this->normalizarPartidas((array)($p['partidas'] ?? []));
        if (!$partidas) return ['status' => 'error', 'message' => 'El presupuesto necesita al menos una partida.'];

        /*
         * Las inspecciones que este presupuesto cobra.
         *
         * Los ids llegan del navegador, así que no se guardan tal cual: se
         * validan contra la misma lista que la pantalla ofrece. Sin eso,
         * cualquiera con la consola abierta podría colgarle a su presupuesto
         * inspecciones de otro cliente, o una que ya se cobró.
         */
        $idsInsp   = array_values(array_unique(array_map('intval', (array)($p['inspecciones'] ?? []))));
        $inspFilas = [];
        if ($idsInsp) {
            $disp = $this->inspeccionesDisponibles($clienteId, $id);
            if (($disp['status'] ?? '') !== 'ok') return $disp;
            $porId  = array_column($disp['inspecciones'], null, 'id');
            $ajenas = [];
            foreach ($idsInsp as $eid) {
                $row = $porId[$eid] ?? null;
                if (!$row) {
                    return ['status' => 'error',
                        'message' => 'Una de las inspecciones ya no está disponible para este cliente. '
                                   . 'Vuelve a abrir la lista para ver las vigentes.'];
                }
                if ($row['ocupada_por'] !== '') { $ajenas[] = $row['control'] . ' → ' . $row['ocupada_por']; continue; }
                $inspFilas[] = $row;
            }
            // Se nombran una por una con el folio que ya las cobra: así se puede
            // ir a ver el otro presupuesto en vez de quedarse adivinando.
            if ($ajenas) {
                return ['status' => 'error',
                    'message' => 'Estas inspecciones ya están cobradas en otro presupuesto: '
                               . implode(', ', $ajenas) . '.'];
            }
        }

        $descuentoGlobal = $this->dinero($p['descuento'] ?? 0);
        $tot = $this->calcularTotales($partidas, $descuentoGlobal);

        $fecha = trim((string)($p['fecha'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) $fecha = date('Y-m-d');

        $correo = trim((string)($p['cliente_correo'] ?? ($cliente['correo'] ?? '')));
        if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            return ['status' => 'error', 'message' => 'El correo del cliente no es válido.'];
        }

        $campos = [
            'cliente_pres_id' => $clienteId > 0 ? $clienteId : null,
            'cliente_nombre'  => $nombre,
            'cliente_correo'  => $correo,
            'atencion'        => trim((string)($p['atencion'] ?? ($cliente['contacto'] ?? ''))),
            'fecha'           => $fecha,
            'vigencia_dias'   => max(1, min(365, (int)($p['vigencia_dias'] ?? 15))),
            'lugar_servicio'  => trim((string)($p['lugar_servicio'] ?? '')),
            'moneda'          => $this->moneda($p['moneda'] ?? 'MXN'),
            'tipo_cambio'     => max(0.0001, (float)($p['tipo_cambio'] ?? 1)),
            'subtotal'        => $tot['subtotal'],
            'descuento'       => $tot['descuento'],
            'iva'             => $tot['iva'],
            'total'           => $tot['total'],
            'condiciones'     => trim((string)($p['condiciones'] ?? '')) ?: $this->cfg('condiciones'),
            'notas'           => trim((string)($p['notas'] ?? '')),
        ];

        $this->pdo->beginTransaction();
        try {
            if ($id > 0) {
                $sets = implode(', ', array_map(fn($c) => "$c = ?", array_keys($campos)));
                $this->pdo->prepare("UPDATE pres_presupuestos SET $sets WHERE id = ?")
                          ->execute([...array_values($campos), $id]);
            } else {
                $campos['folio']   = $this->siguienteFolio();
                $campos['estado']  = 'BORRADOR';
                $campos['usuario'] = $usuario;
                $cols = implode(', ', array_keys($campos));
                $marc = implode(', ', array_fill(0, count($campos), '?'));
                $this->pdo->prepare("INSERT INTO pres_presupuestos ($cols) VALUES ($marc)")
                          ->execute(array_values($campos));
                $id = (int)$this->pdo->lastInsertId();
            }

            $this->pdo->prepare("DELETE FROM pres_partidas WHERE presupuesto_id = ?")->execute([$id]);
            $ins = $this->pdo->prepare(
                "INSERT INTO pres_partidas
                   (presupuesto_id, servicio_id, clave, descripcion, alcance, normas, unidad,
                    cantidad, precio_unitario, descuento_pct, tasa_iva, importe,
                    clave_prodserv, clave_unidad, orden)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            );
            foreach ($partidas as $i => $d) {
                $ins->execute([
                    $id, $d['servicio_id'], $d['clave'], $d['descripcion'], $d['alcance'],
                    $d['normas'], $d['unidad'], $d['cantidad'], $d['precio_unitario'],
                    $d['descuento_pct'], $d['tasa_iva'], $d['importe'],
                    $d['clave_prodserv'], $d['clave_unidad'], $i,
                ]);
            }

            // Se reemplazan enteras, igual que las partidas: lo que quedó fuera
            // de la lista se suelta y vuelve a estar disponible para cobrarse en
            // otro presupuesto.
            $this->pdo->prepare("DELETE FROM pres_inspecciones WHERE presupuesto_id = ?")->execute([$id]);
            if ($inspFilas) {
                $ii = $this->pdo->prepare(
                    "INSERT INTO pres_inspecciones
                       (presupuesto_id, equipo_id, control, maquinaria, detalle, fecha_inspeccion)
                     VALUES (?,?,?,?,?,?)"
                );
                foreach ($inspFilas as $f) {
                    $ii->execute([$id, $f['id'], $f['control'], $f['maquinaria'],
                                  $f['detalle'], $f['fecha_inspeccion']]);
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            error_log('[Presupuestos] guardar: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'No se pudo guardar el presupuesto.'];
        }

        return ['status' => 'ok', 'id' => $id, 'totales' => $tot, 'message' => 'Presupuesto guardado.'];
    }

    /**
     * Convierte lo que llega del navegador en partidas confiables.
     *
     * Cada partida se congela con su descripción, su precio y sus claves del
     * SAT: si el catálogo cambia mañana, el presupuesto de hoy sigue diciendo
     * lo que se ofreció.
     */
    private function normalizarPartidas(array $crudas): array {
        $out = [];
        foreach ($crudas as $c) {
            if (!is_array($c)) continue;

            $servicioId = (int)($c['servicio_id'] ?? 0);
            $base = [];
            if ($servicioId > 0) {
                $s = $this->pdo->prepare("SELECT * FROM pres_servicios WHERE id = ?");
                $s->execute([$servicioId]);
                $base = $s->fetch(PDO::FETCH_ASSOC) ?: [];
            }

            $desc = trim((string)($c['descripcion'] ?? '')) ?: (string)($base['nombre'] ?? '');
            if ($desc === '') continue;

            $cant   = $this->cantidad($c['cantidad'] ?? 1);
            $precio = array_key_exists('precio_unitario', $c)
                ? $this->dinero($c['precio_unitario'])
                : $this->dinero($base['precio'] ?? 0);
            if ($cant <= 0) $cant = 1;

            $descPct = $this->porcentaje($c['descuento_pct'] ?? 0);
            $iva     = array_key_exists('tasa_iva', $c)
                ? $this->tasa($c['tasa_iva'])
                : $this->tasa($base['tasa_iva'] ?? self::IVA_DEFAULT);

            $importe = round($cant * $precio * (1 - $descPct / 100), 2);

            $out[] = [
                'servicio_id'     => $servicioId > 0 ? $servicioId : null,
                'clave'           => trim((string)($c['clave'] ?? ($base['clave'] ?? ''))),
                'descripcion'     => mb_substr($desc, 0, 300),
                'alcance'         => trim((string)($c['alcance'] ?? ($base['alcance'] ?? ''))),
                'normas'          => trim((string)($c['normas'] ?? ($base['normas'] ?? ''))),
                'unidad'          => trim((string)($c['unidad'] ?? ($base['unidad'] ?? ''))) ?: 'Servicio',
                'cantidad'        => $cant,
                'precio_unitario' => $precio,
                'descuento_pct'   => $descPct,
                'tasa_iva'        => $iva,
                'importe'         => $importe,
                'clave_prodserv'  => trim((string)($c['clave_prodserv'] ?? ($base['clave_prodserv'] ?? ''))),
                'clave_unidad'    => trim((string)($c['clave_unidad'] ?? ($base['clave_unidad'] ?? ''))),
            ];
        }
        return $out;
    }

    /**
     * Suma el presupuesto.
     *
     * El descuento global se reparte proporcionalmente entre las partidas antes
     * de calcular el IVA, porque cada partida puede llevar tasa distinta: bajar
     * el total en bloque y luego aplicar un 16% plano daría un impuesto que no
     * corresponde a ninguna de las dos bases.
     */
    private function calcularTotales(array $partidas, float $descuentoGlobal): array {
        $subtotal = 0.0;
        foreach ($partidas as $d) $subtotal += $d['importe'];
        $subtotal = round($subtotal, 2);

        $descuentoGlobal = min($descuentoGlobal, $subtotal);
        $factor = $subtotal > 0 ? (1 - $descuentoGlobal / $subtotal) : 1.0;

        $iva = 0.0;
        foreach ($partidas as $d) {
            $iva += round($d['importe'] * $factor * ($d['tasa_iva'] / 100), 2);
        }
        $iva = round($iva, 2);

        $base = round($subtotal - $descuentoGlobal, 2);
        return [
            'subtotal'  => $subtotal,
            'descuento' => round($descuentoGlobal, 2),
            'base'      => $base,
            'iva'       => $iva,
            'total'     => round($base + $iva, 2),
        ];
    }

    public function cambiarEstado(int $id, string $estado): array {
        $estado = strtoupper(trim($estado));
        if (!in_array($estado, self::ESTADOS, true)) {
            return ['status' => 'error', 'message' => 'Estado no reconocido.'];
        }
        // FACTURADO no se pone a mano: lo escribe el timbrado cuando la factura
        // existe de verdad. Marcarlo aquí dejaría un presupuesto bloqueado
        // apuntando a un CFDI que nadie emitió.
        if ($estado === 'FACTURADO') {
            return ['status' => 'error',
                'message' => 'El estado Facturado lo asigna el sistema al emitir la factura.'];
        }
        $s = $this->pdo->prepare("SELECT estado FROM pres_presupuestos WHERE id = ?");
        $s->execute([$id]);
        $actual = (string)($s->fetchColumn() ?: '');
        if ($actual === '') return ['status' => 'error', 'message' => 'Presupuesto no encontrado.'];
        if ($actual === 'FACTURADO') {
            return ['status' => 'error', 'message' => 'Un presupuesto facturado ya no cambia de estado.'];
        }
        $this->pdo->prepare("UPDATE pres_presupuestos SET estado = ? WHERE id = ?")->execute([$estado, $id]);
        return ['status' => 'ok', 'message' => 'Estado actualizado.'];
    }

    public function eliminar(int $id, string $usuario): array {
        if ($id <= 0) return ['status' => 'error', 'message' => 'Presupuesto no válido.'];
        $s = $this->pdo->prepare("SELECT folio, estado FROM pres_presupuestos WHERE id = ?");
        $s->execute([$id]);
        $p = $s->fetch(PDO::FETCH_ASSOC);
        if (!$p) return ['status' => 'error', 'message' => 'Presupuesto no encontrado.'];
        if ($p['estado'] === 'FACTURADO') {
            return ['status' => 'error', 'message' => 'No se puede borrar un presupuesto facturado.'];
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("DELETE FROM pres_partidas   WHERE presupuesto_id = ?")->execute([$id]);
            $this->pdo->prepare("DELETE FROM pres_propuestas WHERE presupuesto_id = ?")->execute([$id]);
            // Las inspecciones que cobraba vuelven a estar libres. Si no se
            // soltaran, borrar un presupuesto equivocado dejaría ese trabajo sin
            // poder cobrarse nunca, y sin nada que explicara por qué.
            $this->pdo->prepare("DELETE FROM pres_inspecciones WHERE presupuesto_id = ?")->execute([$id]);
            $this->pdo->prepare("DELETE FROM pres_presupuestos WHERE id = ?")->execute([$id]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            error_log('[Presupuestos] eliminar: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'No se pudo eliminar el presupuesto.'];
        }
        error_log('[Presupuestos] ' . $usuario . ' eliminó ' . $p['folio']);
        return ['status' => 'ok', 'message' => 'Presupuesto eliminado.'];
    }

    // ══════════════════════════════════════════════════════════
    //  DOCUMENTOS
    // ══════════════════════════════════════════════════════════

    private function esc(?string $s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }

    private function num(float $n): string {
        return number_format($n, 2, '.', ',');
    }

    /**
     * El armazón de todo documento que sale de este módulo.
     *
     * Encabezado, logotipos, colores y pie viven aquí y sólo aquí: es lo que
     * hace que el presupuesto y la propuesta se vean como la misma casa, y lo
     * que impide que un texto generado decida por su cuenta cómo se ve AVBA.
     */
    private function documentoHtml(string $titulo, string $cuerpo, array $pre): string {
        $folio  = $this->esc((string)$pre['folio']);
        $fecha  = date('d/m/Y', strtotime((string)$pre['fecha']));
        $vence  = date('d/m/Y', strtotime((string)$pre['fecha'] . ' +' . (int)$pre['vigencia_dias'] . ' days'));
        $cli    = $this->esc((string)$pre['cliente_nombre']);
        $aten   = $this->esc((string)($pre['atencion'] ?? ''));
        $lugar  = $this->esc((string)($pre['lugar_servicio'] ?? ''));

        return '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><style>
  @page { margin: 18mm 14mm 20mm 14mm; }
  body { font-family: dejavusans, sans-serif; font-size: 9.6pt; color: #23262F; line-height: 1.55; }
  .enc { border-bottom: 2.5pt solid #0C447C; padding-bottom: 8pt; margin-bottom: 14pt; }
  .enc td { vertical-align: middle; }
  .marca { font-size: 7.4pt; color: #6B7280; line-height: 1.4; }
  .doc-tit { font-size: 16pt; font-weight: bold; color: #0C447C; letter-spacing: .4pt; }
  .doc-folio { font-size: 9pt; color: #B8860B; font-weight: bold; }
  .ficha { width: 100%; border-collapse: collapse; margin-bottom: 14pt; }
  .ficha td { border: .5pt solid #D8DCE4; padding: 5pt 7pt; font-size: 8.8pt; }
  .ficha .et { background: #F2F5F9; color: #4A5162; width: 21%; font-weight: bold; }
  h2.p-titulo { font-size: 11pt; color: #0C447C; border-left: 3.5pt solid #B8860B;
                padding: 2pt 0 2pt 7pt; margin: 15pt 0 7pt; text-transform: uppercase;
                letter-spacing: .3pt; }
  h3 { font-size: 9.6pt; color: #1B2A6B; margin: 10pt 0 4pt; }
  p { margin: 0 0 7pt; text-align: justify; }
  ul, ol { margin: 0 0 8pt 14pt; padding: 0; }
  li { margin-bottom: 3pt; }
  .p-nota { background: #F2F5F9; border-left: 3pt solid #0C447C; padding: 7pt 10pt;
            font-size: 8.6pt; color: #4A5162; margin: 8pt 0; }
  .p-destacado { background: #FBF4E2; border-left: 3pt solid #B8860B; padding: 7pt 10pt;
                 font-size: 8.8pt; margin: 8pt 0; }
  table.p-tabla, table.imp { width: 100%; border-collapse: collapse; margin: 8pt 0 10pt; }
  table.p-tabla th, table.imp th { background: #0C447C; color: #fff; font-size: 8.2pt;
              padding: 5pt 6pt; text-align: left; }
  table.p-tabla td, table.imp td { border-bottom: .5pt solid #DFE3EA; padding: 5pt 6pt;
              font-size: 8.6pt; vertical-align: top; }
  .der { text-align: right; }
  .tot td { padding: 4pt 6pt; font-size: 9pt; border: none; }
  .tot .lab { text-align: right; color: #4A5162; }
  .tot .granTotal { background: #0C447C; color: #fff; font-weight: bold; font-size: 10.5pt; }
  .firma { margin-top: 26pt; }
  .firma .linea { border-top: .8pt solid #23262F; width: 210pt; padding-top: 4pt;
                  font-size: 8.4pt; color: #4A5162; }
  .pie { margin-top: 18pt; border-top: .5pt solid #D8DCE4; padding-top: 6pt;
         font-size: 7.2pt; color: #8A90A0; text-align: center; }
</style></head><body>

<table class="enc" width="100%"><tr>
  <td width="26%"><img src="assets/logos/avba.png" style="height:44pt"></td>
  <td width="40%" class="marca">
    AVBA Inspections · Unidad de Verificación<br>
    Acreditación <strong>UVNMX 057</strong><br>
    Cobertura en todo México y en el extranjero
  </td>
  <td width="34%" align="right">
    <div class="doc-tit">' . $this->esc($titulo) . '</div>
    <div class="doc-folio">' . $folio . '</div>
  </td>
</tr></table>

<table class="ficha">
  <tr><td class="et">Cliente</td><td>' . $cli . '</td>
      <td class="et">Fecha</td><td>' . $fecha . '</td></tr>
  <tr><td class="et">Atención</td><td>' . ($aten !== '' ? $aten : '—') . '</td>
      <td class="et">Vigencia</td><td>Hasta el ' . $vence . '</td></tr>
  <tr><td class="et">Lugar del servicio</td><td colspan="3">' . ($lugar !== '' ? $lugar : 'Por definir con el cliente') . '</td></tr>
</table>

' . $cuerpo . '

<div class="pie">
  AVBA Inspections · Unidad de Verificación acreditada UVNMX 057 ·
  Documento generado el ' . date('d/m/Y H:i') . ' · ' . $folio . '
</div>
</body></html>';
    }

    /** Tabla de importes. La escribe PHP siempre: los números no se delegan. */
    /**
     * El anexo que dice, equipo por equipo, qué ampara la oferta.
     *
     * Una partida que dice "inspección de montacargas · 2 equipos" no le sirve a
     * quien recibe el documento: no puede confirmar que sean los suyos, ni
     * cotejarlo después contra la factura. Con el folio de control y el número
     * de serie sí, y son los mismos folios que ve en su portal.
     */
    private function bloqueInspecciones(array $insp): string {
        if (!$insp) return '';
        $h = '<h2 class="p-titulo">Inspecciones que ampara esta oferta</h2>
<table class="imp">
  <tr><th width="18%">Folio</th><th width="58%">Equipo</th><th width="24%">Fecha</th></tr>';
        foreach ($insp as $i) {
            $f = trim((string)($i['fecha_inspeccion'] ?? ''));
            $h .= '<tr>'
                . '<td>' . $this->esc((string)($i['control'] ?? '')) . '</td>'
                . '<td>' . $this->esc((string)($i['detalle'] ?? '')) . '</td>'
                . '<td>' . ($f !== '' ? $this->esc(date('d/m/Y', strtotime($f))) : '—') . '</td>'
                . '</tr>';
        }
        return $h . '</table>';
    }

    private function bloqueEconomico(array $pre, array $partidas): string {
        $mon = $this->esc((string)$pre['moneda']);
        $h = '<h2 class="p-titulo">Propuesta económica</h2>
<table class="imp">
  <tr><th width="6%">#</th><th width="46%">Concepto</th><th width="9%">Unidad</th>
      <th width="9%" class="der">Cant.</th><th width="15%" class="der">P. unitario</th>
      <th width="15%" class="der">Importe</th></tr>';
        foreach ($partidas as $i => $d) {
            $desc = $this->esc((string)$d['descripcion']);
            if (trim((string)$d['clave']) !== '') {
                $desc = '<strong>' . $this->esc((string)$d['clave']) . '</strong> — ' . $desc;
            }
            if ((float)$d['descuento_pct'] > 0) {
                $desc .= '<br><span style="font-size:7.8pt;color:#8A6A0B">Descuento aplicado '
                       . $this->num((float)$d['descuento_pct']) . '%</span>';
            }
            $h .= '<tr>'
                . '<td>' . ($i + 1) . '</td>'
                . '<td>' . $desc . '</td>'
                . '<td>' . $this->esc((string)$d['unidad']) . '</td>'
                . '<td class="der">' . $this->num((float)$d['cantidad']) . '</td>'
                . '<td class="der">$' . $this->num((float)$d['precio_unitario']) . '</td>'
                . '<td class="der">$' . $this->num((float)$d['importe']) . '</td>'
                . '</tr>';
        }
        $h .= '</table>';

        $desc = (float)$pre['descuento'];
        $h .= '<table class="tot" width="100%">'
            . '<tr><td class="lab" width="72%">Subtotal</td><td class="der" width="28%">$'
            . $this->num((float)$pre['subtotal']) . ' ' . $mon . '</td></tr>';
        if ($desc > 0) {
            $h .= '<tr><td class="lab">Descuento</td><td class="der">− $' . $this->num($desc) . '</td></tr>';
        }
        $h .= '<tr><td class="lab">IVA</td><td class="der">$' . $this->num((float)$pre['iva']) . '</td></tr>'
            . '<tr><td class="lab granTotal">TOTAL</td><td class="der granTotal">$'
            . $this->num((float)$pre['total']) . ' ' . $mon . '</td></tr></table>';

        return $h;
    }

    private function bloqueCondiciones(array $pre): string {
        $cond = trim((string)($pre['condiciones'] ?? '')) ?: $this->cfg('condiciones');
        $h = '';
        if ($cond !== '') {
            $h .= '<h2 class="p-titulo">Condiciones comerciales</h2><ul>';
            foreach (preg_split('/\R/', $cond) as $l) {
                $l = trim($l);
                if ($l !== '') $h .= '<li>' . $this->esc($l) . '</li>';
            }
            $h .= '</ul>';
        }
        $notas = trim((string)($pre['notas'] ?? ''));
        if ($notas !== '') {
            $h .= '<div class="p-nota">' . nl2br($this->esc($notas)) . '</div>';
        }
        $h .= '<div class="firma"><div class="linea">'
            . 'AVBA Inspections · ' . $this->esc((string)($pre['usuario'] ?? 'Área Comercial'))
            . '</div></div>';
        return $h;
    }

    /** Escribe el PDF y devuelve su ruta relativa (uploads/...). */
    private function aPdf(string $html, string $folio, string $sufijo): string {
        if (!class_exists('\\Mpdf\\Mpdf')) {
            $autoload = __DIR__ . '/../vendor/autoload.php';
            if (file_exists($autoload)) require_once $autoload;
        }
        if (!class_exists('\\Mpdf\\Mpdf')) {
            throw new \RuntimeException('mPDF no disponible. Verifica vendor/autoload.php.');
        }
        $dir = UPLOAD_DIR . 'reportes/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $mpdf = new \Mpdf\Mpdf([
            'mode'         => 'utf-8',
            'format'       => 'A4',
            'dpi'          => 96,
            'default_font' => 'dejavusans',
            'tempDir'      => sys_get_temp_dir() . '/mpdf',
        ]);
        $mpdf->SetBasePath(__DIR__ . '/../');
        $mpdf->SetHTMLFooter('<div style="text-align:right;font-size:7pt;color:#9AA0AE">'
            . 'Página {PAGENO} de {nbpg}</div>');

        $prev = (int)ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', 10000000);
        $mpdf->WriteHTML($html);
        ini_set('pcre.backtrack_limit', $prev);

        $nombre = $sufijo . '_' . preg_replace('/[^A-Za-z0-9\-]/', '', $folio) . '_' . date('Ymd_His') . '.pdf';
        $mpdf->Output($dir . $nombre, 'F');
        return 'uploads/reportes/' . $nombre;
    }

    private function urlAbs(string $rel): string {
        if ($rel === '') return '';
        if (preg_match('#^https?://#i', $rel)) return $rel;
        return rtrim(SITE_URL, '/') . '/' . ltrim($rel, '/');
    }

    /** PDF del presupuesto: la oferta escueta, sin narrativa. */
    public function pdfPresupuesto(int $id): array {
        $d = $this->detalle($id);
        if (($d['status'] ?? '') !== 'ok') return $d;
        $pre = $d['presupuesto'];
        if (!$pre['partidas']) return ['status' => 'error', 'message' => 'El presupuesto no tiene partidas.'];

        $cuerpo = $this->bloqueEconomico($pre, $pre['partidas'])
                . $this->bloqueInspecciones($pre['inspecciones'] ?? [])
                . $this->bloqueCondiciones($pre);
        try {
            $rel = $this->aPdf($this->documentoHtml('PRESUPUESTO', $cuerpo, $pre), (string)$pre['folio'], 'PRESUPUESTO');
        } catch (\Throwable $e) {
            error_log('[Presupuestos] pdfPresupuesto: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'No se pudo generar el PDF: ' . $e->getMessage()];
        }
        $this->pdo->prepare("UPDATE pres_presupuestos SET pdf_url = ? WHERE id = ?")->execute([$rel, $id]);
        return ['status' => 'ok', 'url' => $this->urlAbs($rel), 'message' => 'Presupuesto generado.'];
    }

    // ══════════════════════════════════════════════════════════
    //  PROPUESTA TÉCNICO-ECONÓMICA (redactada con IA)
    // ══════════════════════════════════════════════════════════

    /**
     * Etiquetas que sobreviven al filtro. Todo lo demás se va.
     *
     * El texto lo escribe un modelo y termina en un PDF que se manda al
     * cliente. No se trata de desconfiar del modelo, sino de que la salida es
     * contenido no verificado entrando a un documento con nuestro membrete:
     * un <script>, un <style> o una <img> a un servidor ajeno no tienen nada
     * que hacer ahí, y el diseño ya lo pone PHP.
     */
    private const TAGS_PERMITIDOS = '<h2><h3><h4><p><ul><ol><li><strong><b><em><i><br>'
                                  . '<table><thead><tbody><tr><th><td><div><span>';

    private function sanitizarHtml(string $html): string {
        // Fuera bloques completos: strip_tags dejaría el contenido de <style>
        // y <script> como texto suelto en medio del documento.
        $html = preg_replace('#<(script|style|iframe|object|embed|head|title)\b[^>]*>.*?</\1>#is', '', $html);
        $html = preg_replace('#<(script|style|iframe|object|embed|link|meta|img|base|form|input)\b[^>]*/?>#i', '', $html);
        $html = strip_tags($html, self::TAGS_PERMITIDOS);

        // De los atributos sólo sobrevive class, y sólo con las clases del
        // armazón. Así no entran estilos en línea, ni eventos onclick, ni href.
        $html = preg_replace_callback('#<([a-z0-9]+)\b([^>]*)>#i', function ($m) {
            $tag = strtolower($m[1]);
            if (preg_match('/\bclass\s*=\s*"([^"]*)"/i', $m[2], $c)) {
                $ok = array_values(array_intersect(
                    preg_split('/\s+/', trim($c[1])),
                    ['p-titulo', 'p-tabla', 'p-nota', 'p-destacado', 'der']
                ));
                if ($ok) return '<' . $tag . ' class="' . implode(' ', $ok) . '">';
            }
            return '<' . $tag . '>';
        }, $html);

        return trim($html);
    }

    /** Las instrucciones fijas: quiénes somos, qué normas usamos, cómo se escribe. */
    private function systemPrompt(): string {
        return
"Eres el redactor técnico-comercial de AVBA Inspections. Escribes propuestas técnico-económicas
para clientes industriales en México.

QUIÉNES SOMOS
" . $this->cfg('perfil_empresa') . "

NORMAS QUE MANEJAMOS
" . $this->cfg('normas') . "

CÓMO ESCRIBIMOS
" . $this->cfg('instrucciones_ia') . "

QUÉ DEBES ENTREGAR
Solo el cuerpo técnico de la propuesta, en HTML, con estas cinco secciones y en este orden:

  <h2 class=\"p-titulo\">Presentación</h2>      — dos párrafos: quiénes somos y qué se ofrece a ESTE cliente.
  <h2 class=\"p-titulo\">Alcance del servicio</h2> — un <h3> por cada partida, con lo que incluye.
  <h2 class=\"p-titulo\">Metodología</h2>        — cómo se ejecuta, en lista ordenada por etapas.
  <h2 class=\"p-titulo\">Normas aplicables</h2>  — lista; SOLO las que apliquen a las partidas de esta propuesta.
  <h2 class=\"p-titulo\">Entregables</h2>        — lista de documentos que recibe el cliente.

REGLAS DE SALIDA — sin excepción
1. Devuelve HTML y nada más. Ni explicaciones, ni bloques de código, ni ``` de apertura o cierre.
2. Prohibido: <html>, <head>, <body>, <style>, <script>, <img>, atributos style= y cualquier
   color, tipografía o tamaño. El diseño ya está puesto; tú solo aportas el texto.
3. Etiquetas permitidas: h2, h3, h4, p, ul, ol, li, strong, em, br, table, tr, th, td, div, span.
   Las únicas clases permitidas son: p-titulo, p-tabla, p-nota, p-destacado.
4. NO escribas precios, importes, subtotales, totales, IVA ni condiciones de pago. La sección
   económica y las condiciones comerciales las agrega el sistema después, con las cifras reales.
   Si inventas un número, la propuesta saldría contradiciéndose a sí misma.
5. No prometas que el equipo va a aprobar: el dictamen depende de lo que se encuentre en campo.
6. No cites normas, acreditaciones ni alcances que no aparezcan arriba o en los datos del cliente.
7. Escribe en español de México. Sin emojis. Sin superlativos publicitarios.";
    }

    /** El encargo concreto: este cliente, estas partidas. */
    private function userPrompt(array $pre): string {
        $t  = "PROPUESTA PARA:\n";
        $t .= "- Cliente: " . $pre['cliente_nombre'] . "\n";
        if (trim((string)($pre['atencion'] ?? '')) !== '')       $t .= "- Atención: " . $pre['atencion'] . "\n";
        if (trim((string)($pre['lugar_servicio'] ?? '')) !== '') $t .= "- Lugar del servicio: " . $pre['lugar_servicio'] . "\n";
        $t .= "- Folio: " . $pre['folio'] . "\n";
        $t .= "- Fecha: " . date('d/m/Y', strtotime((string)$pre['fecha'])) . "\n\n";

        $t .= "PARTIDAS CONTRATADAS (" . count($pre['partidas']) . "):\n";
        foreach ($pre['partidas'] as $i => $d) {
            $t .= "\n" . ($i + 1) . ". " . $d['descripcion'] . "\n";
            $t .= "   Cantidad: " . rtrim(rtrim(number_format((float)$d['cantidad'], 2, '.', ''), '0'), '.')
                . " " . $d['unidad'] . "\n";
            if (trim((string)$d['alcance']) !== '') $t .= "   Alcance registrado: " . $d['alcance'] . "\n";
            if (trim((string)$d['normas'])  !== '') $t .= "   Normas de esta partida: " . $d['normas'] . "\n";
            if ($d['servicio_id']) {
                $s = $this->pdo->prepare("SELECT descripcion, entregables FROM pres_servicios WHERE id = ?");
                $s->execute([(int)$d['servicio_id']]);
                if ($cat = $s->fetch(PDO::FETCH_ASSOC)) {
                    if (trim((string)$cat['descripcion']) !== '') $t .= "   Descripción del catálogo: " . $cat['descripcion'] . "\n";
                    if (trim((string)$cat['entregables']) !== '') $t .= "   Entregables: " . $cat['entregables'] . "\n";
                }
            }
        }

        if (trim((string)($pre['notas'] ?? '')) !== '') {
            $t .= "\nNOTAS INTERNAS SOBRE ESTE TRABAJO (úsalas como contexto, no las copies literal):\n"
                . $pre['notas'] . "\n";
        }
        $t .= "\nRedacta ahora las cinco secciones. Solo HTML.";
        return $t;
    }

    /**
     * Genera la propuesta y la guarda como una versión más.
     *
     * No se sobrescribe la anterior: si la nueva redacción sale peor, el
     * comercial todavía tiene a mano la que ya le gustaba.
     */
    public function generarPropuesta(int $id, string $usuario): array {
        $d = $this->detalle($id);
        if (($d['status'] ?? '') !== 'ok') return $d;
        $pre = $d['presupuesto'];
        if (!$pre['partidas']) {
            return ['status' => 'error', 'message' => 'Agrega partidas antes de generar la propuesta.'];
        }

        $ia = new ClaudeIA();
        if (!$ia->disponible()) {
            return ['status' => 'error',
                'message' => 'La generación con IA no está configurada en el servidor (falta CLAUDE_API_KEY en config/config.php).'];
        }

        // Redactar toma su tiempo; sin esto PHP corta a la mitad y el usuario
        // solo ve "Error de conexión".
        @set_time_limit(360);

        $r = $ia->mensaje($this->systemPrompt(), $this->userPrompt($pre));
        if (($r['status'] ?? '') !== 'ok') return $r;

        // A veces el texto llega envuelto en una valla de código a pesar de
        // pedirle que no lo haga. Quitarla es más barato que reintentar.
        $bruto = preg_replace('/^\s*```(?:html)?\s*|\s*```\s*$/i', '', (string)$r['texto']);
        $tecnico = $this->sanitizarHtml($bruto);
        if ($tecnico === '') {
            return ['status' => 'error', 'message' => 'La respuesta del modelo quedó vacía tras el filtrado.'];
        }

        $cuerpo = $tecnico
                . $this->bloqueEconomico($pre, $pre['partidas'])
                . $this->bloqueCondiciones($pre);
        $html   = $this->documentoHtml('PROPUESTA TÉCNICO-ECONÓMICA', $cuerpo, $pre);

        try {
            $rel = $this->aPdf($html, (string)$pre['folio'], 'PROPUESTA');
        } catch (\Throwable $e) {
            error_log('[Presupuestos] generarPropuesta pdf: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'La propuesta se redactó pero no se pudo generar el PDF: ' . $e->getMessage()];
        }

        $this->pdo->prepare(
            "INSERT INTO pres_propuestas (presupuesto_id, html, pdf_url, modelo, tokens_in, tokens_out, usuario)
             VALUES (?,?,?,?,?,?,?)"
        )->execute([
            $id, $html, $rel, (string)($r['modelo'] ?? ''),
            (int)($r['uso']['entrada'] ?? 0), (int)($r['uso']['salida'] ?? 0), $usuario,
        ]);

        return [
            'status'  => 'ok',
            'id'      => (int)$this->pdo->lastInsertId(),
            'url'     => $this->urlAbs($rel),
            'modelo'  => (string)($r['modelo'] ?? ''),
            'uso'     => $r['uso'] ?? [],
            'message' => 'Propuesta generada.',
        ];
    }

    /** Devuelve el HTML de una versión para verla en pantalla antes de enviarla. */
    public function verPropuesta(int $propuestaId): array {
        $s = $this->pdo->prepare("SELECT * FROM pres_propuestas WHERE id = ?");
        $s->execute([$propuestaId]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        if (!$r) return ['status' => 'error', 'message' => 'Propuesta no encontrada.'];
        $r['pdf_url'] = $this->urlAbs((string)$r['pdf_url']);
        return ['status' => 'ok', 'propuesta' => $r];
    }

    public function eliminarPropuesta(int $propuestaId): array {
        $s = $this->pdo->prepare("SELECT pdf_url FROM pres_propuestas WHERE id = ?");
        $s->execute([$propuestaId]);
        $rel = (string)($s->fetchColumn() ?: '');
        if ($rel === '') return ['status' => 'error', 'message' => 'Propuesta no encontrada.'];
        $abs = $this->rutaAbsoluta($rel);
        if ($abs !== '' && is_file($abs)) @unlink($abs);
        $this->pdo->prepare("DELETE FROM pres_propuestas WHERE id = ?")->execute([$propuestaId]);
        return ['status' => 'ok', 'message' => 'Versión eliminada.'];
    }

    /**
     * Ruta en disco de un archivo de uploads.
     *
     * UPLOAD_DIR ya termina en uploads/, y lo guardado empieza con uploads/.
     * Concatenar a lo bruto daba uploads/uploads/… y el borrado no encontraba
     * nada. Además se comprueba que el resultado siga dentro de la carpeta,
     * para que un valor manipulado no alcance archivos de más arriba.
     */
    private function rutaAbsoluta(string $rel): string {
        $rel = ltrim(preg_replace('#^https?://[^/]+/#i', '', $rel), '/');
        $rel = preg_replace('#^uploads/#', '', $rel);
        $abs = realpath(UPLOAD_DIR . $rel);
        $raiz = realpath(UPLOAD_DIR);
        if ($abs === false || $raiz === false || !str_starts_with($abs, $raiz)) return '';
        return $abs;
    }

    // ══════════════════════════════════════════════════════════
    //  ENVÍO AL CLIENTE
    // ══════════════════════════════════════════════════════════

    /**
     * Manda al cliente el presupuesto y, si existe, la última propuesta.
     *
     * Al enviarse pasa a ENVIADO — pero sólo desde BORRADOR: reenviar un
     * presupuesto ya aceptado no debe retroceder su estado.
     */
    public function enviar(int $id, string $correo, string $usuario, bool $incluirPropuesta = true): array {
        $d = $this->detalle($id);
        if (($d['status'] ?? '') !== 'ok') return $d;
        $pre = $d['presupuesto'];

        $correo = trim($correo) !== '' ? trim($correo) : (string)$pre['cliente_correo'];
        $destinos = array_values(array_filter(
            array_map('trim', explode(',', $correo)),
            fn($a) => filter_var($a, FILTER_VALIDATE_EMAIL)
        ));
        if (!$destinos) return ['status' => 'error', 'message' => 'No hay un correo válido de destino.'];

        $adjuntos = [];

        $pdf = $this->pdfPresupuesto($id);
        if (($pdf['status'] ?? '') !== 'ok') return $pdf;
        $abs = $this->rutaAbsoluta((string)$pdf['url']);
        if ($abs !== '') $adjuntos[$abs] = 'Presupuesto_' . $pre['folio'] . '.pdf';

        if ($incluirPropuesta && !empty($pre['propuestas'])) {
            $ultima = $pre['propuestas'][0];
            $absP = $this->rutaAbsoluta((string)$ultima['pdf_url']);
            if ($absP !== '') $adjuntos[$absP] = 'Propuesta_' . $pre['folio'] . '.pdf';
        }
        if (!$adjuntos) return ['status' => 'error', 'message' => 'No hay documentos que enviar.'];

        try {
            $this->enviarCorreo($destinos, $pre, $adjuntos);
        } catch (\Throwable $e) {
            error_log('[Presupuestos] enviar: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'No se pudo enviar el correo: ' . $e->getMessage()];
        }

        $this->pdo->prepare(
            "UPDATE pres_presupuestos
                SET enviado_at = NOW(), estado = CASE WHEN estado = 'BORRADOR' THEN 'ENVIADO' ELSE estado END
              WHERE id = ?"
        )->execute([$id]);

        return ['status' => 'ok',
            'message' => 'Enviado a ' . implode(', ', $destinos) . '.',
            'adjuntos' => array_values($adjuntos)];
    }

    private function enviarCorreo(array $destinos, array $pre, array $adjuntos): void {
        if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
            $autoload = __DIR__ . '/../vendor/autoload.php';
            if (file_exists($autoload)) require_once $autoload;
        }
        if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
            throw new \RuntimeException('PHPMailer no disponible.');
        }

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        configurarMailer($mail, $this->pdo);
        foreach ($destinos as $a) $mail->addAddress($a);

        $folio = $this->esc((string)$pre['folio']);
        $vence = date('d/m/Y', strtotime((string)$pre['fecha'] . ' +' . (int)$pre['vigencia_dias'] . ' days'));

        $mail->Subject = 'Propuesta de servicios AVBA — ' . $pre['folio'];
        $mail->isHTML(true);

        $cuerpo = '
      <p style="font-size:15px;color:#1a1a2e;margin:0 0 12px">Estimado(a) <strong>'
        . $this->esc((string)($pre['atencion'] ?: $pre['cliente_nombre'])) . '</strong>,</p>
      <p style="font-size:14px;color:#5a6072;line-height:1.7;margin:0 0 20px">
        Adjuntamos la propuesta de servicios de inspección y certificación solicitada.
        Quedamos atentos a cualquier ajuste que requiera antes de programar el servicio.
      </p>
      <div style="background:#E6F1FB;border-radius:8px;padding:14px 18px;margin-bottom:20px">
        <p style="font-size:13px;color:#0C447C;margin:0"><strong>Folio:</strong> ' . $folio . '</p>
        <p style="font-size:13px;color:#0C447C;margin:6px 0 0"><strong>Total:</strong> $'
        . $this->num((float)$pre['total']) . ' ' . $this->esc((string)$pre['moneda']) . ' (IVA incluido)</p>
        <p style="font-size:12px;color:#1B2A6B;margin:8px 0 0">
          Esta oferta es válida hasta el ' . $vence . '.
        </p>
      </div>';

        $mail->Body = function_exists('plantillaCorreoHtml')
            ? plantillaCorreoHtml($this->pdo, $cuerpo)
            : $cuerpo;

        foreach ($adjuntos as $ruta => $nombre) $mail->addAttachment($ruta, $nombre);
        $mail->send();
    }

    // ══════════════════════════════════════════════════════════
    //  FACTURACIÓN (CFDI 4.0 vía Facturapi)
    // ══════════════════════════════════════════════════════════

    /**
     * Revisa que el presupuesto pueda convertirse en CFDI, ANTES de llamar al PAC.
     *
     * Se hace aquí y no allá por una razón práctica: Facturapi cobra el timbre
     * y el SAT registra el intento. Un rechazo por un dato que teníamos a la
     * vista —un RFC vacío, una partida sin clave del SAT— es un error que se
     * puede señalar en la pantalla, con el nombre del campo, en vez de
     * devolver un mensaje del PAC que nadie sabe dónde corregir.
     *
     * @return string[] Lista de problemas. Vacía significa que se puede timbrar.
     */
    public function problemasParaFacturar(array $pre): array {
        $p = [];

        if (($pre['estado'] ?? '') === 'FACTURADO')  $p[] = 'El presupuesto ya está facturado.';
        if (($pre['estado'] ?? '') === 'CANCELADO')  $p[] = 'El presupuesto está cancelado.';
        if (empty($pre['partidas']))                 $p[] = 'El presupuesto no tiene partidas.';
        if ((float)($pre['total'] ?? 0) <= 0)        $p[] = 'El total del presupuesto es cero.';

        $cli = $pre['cliente'] ?? null;
        if (!$cli) {
            $p[] = 'El presupuesto no está ligado a un cliente del catálogo, así que no hay datos fiscales.';
        } else {
            foreach ($this->faltaFiscal($cli) as $f) {
                $p[] = 'Al cliente le falta su ' . $f . '.';
            }
        }

        foreach (($pre['partidas'] ?? []) as $i => $d) {
            $n = $i + 1;
            if (trim((string)$d['clave_prodserv']) === '') {
                $p[] = "La partida {$n} (" . $d['descripcion'] . ") no tiene clave ProdServ del SAT.";
            }
            if (trim((string)$d['clave_unidad']) === '') {
                $p[] = "La partida {$n} (" . $d['descripcion'] . ") no tiene clave de unidad del SAT.";
            }
        }
        return $p;
    }

    /**
     * Arma el cuerpo del CFDI a partir del presupuesto.
     *
     * El descuento global del presupuesto se reparte entre las partidas, porque
     * el CFDI no tiene un descuento a nivel comprobante: sólo por concepto. El
     * sobrante del redondeo se le carga a la última partida, para que la suma
     * de los descuentos sea exactamente la que se le ofreció al cliente y la
     * factura no difiera del presupuesto por unos centavos.
     */
    private function payloadFactura(array $pre, string $formaPago, string $metodoPago): array {
        $cli = $pre['cliente'];

        $subtotal = 0.0;
        foreach ($pre['partidas'] as $d) $subtotal += (float)$d['importe'];

        $descGlobal = (float)$pre['descuento'];
        $repartido  = 0.0;
        $ultimo     = count($pre['partidas']) - 1;

        $items = [];
        foreach ($pre['partidas'] as $i => $d) {
            $cant   = (float)$d['cantidad'];
            $precio = (float)$d['precio_unitario'];
            $bruto  = round($cant * $precio, 2);
            $descLinea = round($bruto - (float)$d['importe'], 2);

            if ($descGlobal > 0 && $subtotal > 0) {
                if ($i === $ultimo) {
                    $parte = round($descGlobal - $repartido, 2);
                } else {
                    $parte = round($descGlobal * ((float)$d['importe'] / $subtotal), 2);
                    $repartido += $parte;
                }
                $descLinea = round($descLinea + $parte, 2);
            }

            $tasa = (float)$d['tasa_iva'];
            // Tasa 0 se manda sin impuestos: el CFDI lo toma como exento. Los
            // servicios de AVBA van todos al 16%, así que esto es la excepción.
            $impuestos = $tasa > 0
                ? [['type' => 'IVA', 'rate' => round($tasa / 100, 6), 'factor' => 'Tasa', 'withholding' => false]]
                : [];

            $items[] = [
                'quantity' => $cant,
                'discount' => max(0, $descLinea),
                'product'  => [
                    'description'  => mb_substr((string)$d['descripcion'], 0, 1000),
                    'product_key'  => (string)$d['clave_prodserv'],
                    'unit_key'     => (string)$d['clave_unidad'],
                    'unit_name'    => (string)$d['unidad'],
                    'price'        => $precio,
                    'tax_included' => false,
                    'taxes'        => $impuestos,
                ],
            ];
        }

        $cuerpo = [
            'customer' => [
                'legal_name' => (string)$cli['razon_social'],
                'tax_id'     => strtoupper((string)$cli['rfc']),
                'tax_system' => (string)$cli['regimen_fiscal'],
                'address'    => ['zip' => (string)$cli['cp_fiscal'], 'country' => 'MEX'],
            ],
            'items'          => $items,
            'use'            => (string)($cli['uso_cfdi'] ?: 'G03'),
            'payment_form'   => $formaPago,
            'payment_method' => $metodoPago,
            // Deja el folio del presupuesto escrito en el CFDI: al conciliar
            // meses después, la factura dice sola de dónde vino.
            'external_id'    => (string)$pre['folio'],
            'conditions'     => mb_substr(trim((string)($pre['condiciones'] ?? '')), 0, 1000),
        ];

        if (strtoupper((string)$pre['moneda']) !== 'MXN') {
            $cuerpo['currency'] = strtoupper((string)$pre['moneda']);
            $cuerpo['exchange'] = (float)$pre['tipo_cambio'];
        }
        return $cuerpo;
    }

    /**
     * Timbra el CFDI y guarda el XML y el PDF junto al presupuesto.
     *
     * El XML se descarga y se archiva en el servidor a propósito: es el
     * documento fiscal, el que vale ante el SAT, y no debe depender de que la
     * cuenta de Facturapi siga viva dentro de cinco años.
     */
    public function facturar(int $id, array $opciones, string $usuario): array {
        $d = $this->detalle($id);
        if (($d['status'] ?? '') !== 'ok') return $d;
        $pre = $d['presupuesto'];

        $problemas = $this->problemasParaFacturar($pre);
        if ($problemas) {
            return ['status' => 'error', 'message' => 'Falta información para facturar.', 'problemas' => $problemas];
        }

        $api = new Facturapi();
        if (!$api->disponible()) {
            return ['status' => 'error',
                'message' => 'La facturación no está configurada en el servidor (falta FACTURAPI_KEY en config/config.php).'];
        }

        $formaPago  = (string)($opciones['forma_pago'] ?? $pre['forma_pago'] ?? '03');
        $metodoPago = strtoupper((string)($opciones['metodo_pago'] ?? $pre['metodo_pago'] ?? 'PUE'));
        if (!isset(Facturapi::FORMAS_PAGO[$formaPago]))   $formaPago  = '03';
        if (!isset(Facturapi::METODOS_PAGO[$metodoPago])) $metodoPago = 'PUE';

        @set_time_limit(180);
        $r = $api->crearFactura($this->payloadFactura($pre, $formaPago, $metodoPago));
        if (($r['status'] ?? '') !== 'ok') return $r;

        $f = $r['datos'];
        $facturaId = (string)($f['id'] ?? '');
        if ($facturaId === '') {
            error_log('[Presupuestos] facturar: respuesta sin id — ' . json_encode($f));
            return ['status' => 'error', 'message' => 'Facturapi no devolvió el identificador de la factura.'];
        }

        // Si el total del CFDI no coincide con el del presupuesto, algo se
        // torció al repartir descuentos o redondear. Se avisa en vez de dejar
        // pasar en silencio una factura que dice otra cifra que la oferta.
        $aviso = '';
        $totalCfdi = (float)($f['total'] ?? 0);
        if ($totalCfdi > 0 && abs($totalCfdi - (float)$pre['total']) > 1.0) {
            $aviso = 'Atención: la factura quedó en $' . $this->num($totalCfdi)
                   . ' y el presupuesto decía $' . $this->num((float)$pre['total']) . '. Revísala.';
            error_log('[Presupuestos] facturar: descuadre ' . $pre['folio'] . " {$totalCfdi} vs {$pre['total']}");
        }

        $pdfRel = $this->archivarFactura($api, $facturaId, (string)$pre['folio'], 'pdf');
        $xmlRel = $this->archivarFactura($api, $facturaId, (string)$pre['folio'], 'xml');

        $serie = trim(((string)($f['series'] ?? '')) . ' ' . ((string)($f['folio_number'] ?? '')));

        $this->pdo->prepare(
            "UPDATE pres_presupuestos SET
                estado = 'FACTURADO', forma_pago = ?, metodo_pago = ?,
                factura_id = ?, factura_uuid = ?, factura_serie = ?, factura_fecha = ?,
                factura_modo = ?, factura_total = ?, factura_estado = ?,
                factura_pdf = ?, factura_xml = ?
              WHERE id = ?"
        )->execute([
            $formaPago, $metodoPago, $facturaId, (string)($f['uuid'] ?? ''), $serie,
            date('Y-m-d H:i:s'), $api->modo(), $totalCfdi, (string)($f['status'] ?? 'valid'),
            $pdfRel, $xmlRel, $id,
        ]);

        error_log('[Presupuestos] ' . $usuario . ' facturó ' . $pre['folio']
                . ' → UUID ' . ($f['uuid'] ?? '?') . ' (' . $api->modo() . ')');

        $msg = $api->esPruebas()
            ? 'Factura de PRUEBA generada: no tiene validez ante el SAT.'
            : 'Factura timbrada correctamente.';

        return [
            'status'  => 'ok',
            'uuid'    => (string)($f['uuid'] ?? ''),
            'modo'    => $api->modo(),
            'total'   => $totalCfdi,
            'pdf'     => $pdfRel !== '' ? $this->urlAbs($pdfRel) : '',
            'xml'     => $xmlRel !== '' ? $this->urlAbs($xmlRel) : '',
            'aviso'   => $aviso,
            'message' => $msg . ($aviso !== '' ? ' ' . $aviso : ''),
        ];
    }

    /** Descarga un archivo del CFDI y lo deja en uploads/facturas/. */
    private function archivarFactura(Facturapi $api, string $facturaId, string $folio, string $tipo): string {
        $r = $api->descargar($facturaId, $tipo);
        if (($r['status'] ?? '') !== 'ok') {
            // Que falle la descarga no invalida la factura: ya está timbrada y
            // se puede bajar después. Se anota y se sigue.
            error_log('[Presupuestos] archivarFactura ' . $tipo . ': ' . ($r['message'] ?? ''));
            return '';
        }
        $dir = UPLOAD_DIR . 'facturas/';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $nombre = 'CFDI_' . preg_replace('/[^A-Za-z0-9\-]/', '', $folio) . '_' . date('Ymd_His') . '.' . $tipo;
        if (@file_put_contents($dir . $nombre, $r['bytes']) === false) {
            error_log('[Presupuestos] archivarFactura: no se pudo escribir ' . $dir . $nombre);
            return '';
        }
        return 'uploads/facturas/' . $nombre;
    }

    /** Manda el CFDI al cliente desde Facturapi, con XML y PDF adjuntos. */
    public function enviarFactura(int $id, string $correo): array {
        $d = $this->detalle($id);
        if (($d['status'] ?? '') !== 'ok') return $d;
        $pre = $d['presupuesto'];
        if (trim((string)($pre['factura_id'] ?? '')) === '') {
            return ['status' => 'error', 'message' => 'Este presupuesto todavía no tiene factura.'];
        }
        $correo = trim($correo) !== '' ? trim($correo) : (string)$pre['cliente_correo'];
        $destinos = array_values(array_filter(
            array_map('trim', explode(',', $correo)),
            fn($a) => filter_var($a, FILTER_VALIDATE_EMAIL)
        ));
        if (!$destinos) return ['status' => 'error', 'message' => 'No hay un correo válido de destino.'];

        $r = (new Facturapi())->enviarPorCorreo((string)$pre['factura_id'], $destinos);
        if (($r['status'] ?? '') !== 'ok') return $r;
        return ['status' => 'ok', 'message' => 'Factura enviada a ' . implode(', ', $destinos) . '.'];
    }

    /**
     * Cancela el CFDI ante el SAT.
     *
     * El presupuesto NO vuelve a BORRADOR: pasa a CANCELADO y ahí se queda. Una
     * factura cancelada dejó rastro en el SAT, y devolver el presupuesto a
     * edición dejaría un documento fiscal apuntando a cifras que ya cambiaron.
     * Si hay que rehacer el trabajo, se hace un presupuesto nuevo.
     */
    public function cancelarFactura(int $id, string $motivo, string $sustitucion, string $usuario): array {
        $d = $this->detalle($id);
        if (($d['status'] ?? '') !== 'ok') return $d;
        $pre = $d['presupuesto'];

        $facturaId = trim((string)($pre['factura_id'] ?? ''));
        if ($facturaId === '') return ['status' => 'error', 'message' => 'Este presupuesto no tiene factura que cancelar.'];
        if (($pre['factura_estado'] ?? '') === 'canceled') {
            return ['status' => 'error', 'message' => 'Esa factura ya está cancelada.'];
        }

        $r = (new Facturapi())->cancelar($facturaId, trim($motivo), trim($sustitucion));
        if (($r['status'] ?? '') !== 'ok') return $r;

        $this->pdo->prepare(
            "UPDATE pres_presupuestos
                SET estado = 'CANCELADO', factura_estado = 'canceled', factura_cancelada_at = NOW()
              WHERE id = ?"
        )->execute([$id]);

        error_log('[Presupuestos] ' . $usuario . ' canceló la factura de ' . $pre['folio'] . ' (motivo ' . $motivo . ')');
        return ['status' => 'ok', 'message' => 'Factura cancelada ante el SAT.'];
    }

    /** Catálogos y estado del servicio, para que la pantalla sepa qué ofrecer. */
    public function facturacionInfo(): array {
        $api = new Facturapi();
        return [
            'status'        => 'ok',
            'disponible'    => $api->disponible(),
            'modo'          => $api->disponible() ? $api->modo() : '',
            'formas_pago'   => Facturapi::FORMAS_PAGO,
            'metodos_pago'  => Facturapi::METODOS_PAGO,
            'motivos'       => Facturapi::MOTIVOS,
        ];
    }

    /**
     * Busca en los catálogos del SAT a través de Facturapi.
     *
     * Vive aquí y no en la clase de facturación porque la pantalla que lo usa
     * es la del catálogo de servicios: se buscan las claves al dar de alta el
     * servicio, no al facturar. Para entonces ya deben estar puestas.
     *
     * @param string $tipo 'prodserv' | 'unidad'
     */
    public function buscarCatalogoSat(string $tipo, string $q): array {
        $api = new Facturapi();
        if (!$api->disponible()) {
            return ['status' => 'error',
                'message' => 'El buscador de claves del SAT necesita FACTURAPI_KEY. Puedes escribir la clave a mano.'];
        }
        return $tipo === 'unidad' ? $api->buscarUnidades($q) : $api->buscarProductos($q);
    }
}
