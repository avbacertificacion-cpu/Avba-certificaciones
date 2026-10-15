<?php
/**
 * AVBA Certificaciones — Solicitudes de certificación del cliente
 *
 * El cliente ya tiene en su portal su personal y sus equipos, con su
 * documentación. Lo que faltaba era que pudiera decir "certifícame éstos".
 * Hasta ahora la cadena arrancaba en el inspector, que creaba el registro desde
 * campo: el cliente no tenía por dónde pedirlo.
 *
 * La solicitud llega a Calidad, que es quien ya revisa documentación y aprueba
 * el dictamen. El inspector la ve cuando Calidad la programa, de modo que
 * recibe trabajo ya filtrado y con papeles verificados, en vez de enterarse en
 * planta de que falta un documento.
 *
 * Una sola bandeja para personal y equipos: el ciclo de vida es el mismo y
 * Calidad revisa en un solo lugar.
 */
class SolicitudesCert {
    private PDO $pdo;

    /** Lo que puede pedirse. 'personal' incluye cursos y certificación de operador. */
    public const TIPOS = ['equipo', 'personal'];

    /**
     * Estados de la solicitud.
     *
     * No hay paso por presupuesto: son clientes establecidos que ya manejan los
     * costos, así que la solicitud entra directo a revisión.
     */
    public const ESTADOS = [
        'ENVIADA'     => 'Enviada',
        'EN_REVISION' => 'En revisión',
        'PROGRAMADA'  => 'Programada',
        'ATENDIDA'    => 'Atendida',
        'RECHAZADA'   => 'Rechazada',
        'CANCELADA'   => 'Cancelada',
    ];

    /** Lo que el cliente todavía puede cancelar. Una vez programada, ya se movió gente. */
    public const CANCELABLES = ['ENVIADA', 'EN_REVISION'];

    /**
     * Con qué se puede certificar a una persona.
     *
     * Es el mismo catálogo que Calidad administra en Catálogos → Ocupaciones y
     * que se imprime como "acreditado como" en el certificado. Se siembra sólo
     * si está vacío: donde ya haya una lista curada, no se toca.
     */
    private const OCUPACIONES_BASE = [
        // ── Operación de equipos de izaje ──
        'Operador de grúa viajera (puente grúa)',
        'Operador de grúa pórtico',
        'Operador de grúa móvil',
        'Operador de grúa articulada (hidráulica tipo HIAB)',
        'Operador de grúa torre',
        'Operador de grúa sobre orugas',
        'Operador de polipasto y malacate',
        'Operador de grúa de pedestal',
        // ── Equipo de carga y maniobra ──
        'Operador de montacargas',
        'Operador de montacargas de terreno irregular',
        'Operador de manipulador telescópico (telehandler)',
        'Operador de plataforma de elevación (MEWP)',
        'Operador de plataforma de tijera',
        'Operador de plataforma articulada',
        'Operador de patín hidráulico y transpaleta',
        'Operador de gato hidráulico y equipo de empuje',
        // ── Maniobras ──
        'Maniobrista (rigger)',
        'Maniobrista especializado en izajes críticos',
        'Señalero de maniobras',
        'Supervisor de maniobras',
        'Coordinador de izaje',
        'Planeador de maniobras de izaje',
        'Amarrador de carga (estrobador)',
        // ── Seguridad ──
        'Trabajos en altura',
        'Persona competente en protección contra caídas',
        'Rescate en alturas',
        'Trabajos en espacios confinados',
        'Supervisor de seguridad en maniobras',
        // ── Inspección y verificación ──
        'Inspector de accesorios de izaje',
        'Inspector de arneses y líneas de vida',
        'Inspector de cables de acero',
        'Inspector de eslingas y estrobos',
        'Inspector de ganchos y grilletes',
        // ── Mantenimiento ──
        'Mantenimiento de equipos de izaje',
        'Mantenimiento de montacargas',
        'Técnico en pruebas no destructivas (PND)',
    ];

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->migrate();
        $this->sembrarOcupaciones();
    }

    /** Da de alta el catálogo base la primera vez, para que no nazca vacío. */
    private function sembrarOcupaciones(): void {
        try {
            $n = (int)$this->pdo->query("SELECT COUNT(*) FROM ocupaciones_especificas")->fetchColumn();
            if ($n > 0) return;   // ya hay lista: es de Calidad, no se toca
            $ins = $this->pdo->prepare("INSERT INTO ocupaciones_especificas (nombre) VALUES (?)");
            foreach (self::OCUPACIONES_BASE as $o) $ins->execute([$o]);
        } catch (\Throwable $e) {
            // La tabla puede no existir todavía en una instalación nueva.
            error_log('[SolicitudesCert] sembrarOcupaciones: ' . $e->getMessage());
        }
    }

    private function migrate(): void {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS cliente_solicitudes_cert (
                  id              INT AUTO_INCREMENT PRIMARY KEY,
                  folio           VARCHAR(30)  NOT NULL,
                  id_cliente      VARCHAR(20)  NOT NULL,
                  tipo            VARCHAR(20)  NOT NULL,
                  estado          VARCHAR(20)  NOT NULL DEFAULT 'ENVIADA',
                  servicio        VARCHAR(200) NULL,
                  fecha_deseada   DATE         NULL,
                  sede            VARCHAR(300) NULL,
                  contacto_nombre VARCHAR(150) NULL,
                  contacto_tel    VARCHAR(40)  NULL,
                  notas           TEXT         NULL,
                  solicitado_por  VARCHAR(150) NULL,
                  revisado_por    VARCHAR(50)  NULL,
                  programada_para DATE         NULL,
                  inspector       VARCHAR(150) NULL,
                  motivo          TEXT         NULL,
                  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  updated_at      TIMESTAMP NULL,
                  KEY idx_cli (id_cliente),
                  KEY idx_estado (estado)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            /* Los renglones guardan COPIA de lo pedido, no sólo el id de origen.
               Si el cliente da de baja al operador o borra el equipo después de
               solicitar, la solicitud tiene que seguir diciendo qué se pidió:
               es el respaldo de un servicio que quizá ya se prestó. */
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS cliente_solicitudes_cert_item (
                  id           INT AUTO_INCREMENT PRIMARY KEY,
                  solicitud_id INT          NOT NULL,
                  origen_id    INT          NULL,
                  nombre       VARCHAR(250) NOT NULL,
                  detalle      VARCHAR(400) NULL,
                  curp         VARCHAR(20)  NULL,
                  ocupacion    VARCHAR(150) NULL,
                  estado       VARCHAR(20)  NOT NULL DEFAULT 'PENDIENTE',
                  avba_ref_id  INT          NULL,
                  notas        TEXT         NULL,
                  KEY idx_sol (solicitud_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            /* Copia de la documentación tal como estaba al solicitar.
               No basta con apuntar al archivo del cliente: si da de baja a la
               persona o borra el documento, nos quedamos sin el soporte de una
               certificación que ya emitimos. Esto guarda el qué (nombre, tipo,
               vigencia) y una copia del archivo. */
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS cliente_solicitudes_cert_doc (
                  id           INT AUTO_INCREMENT PRIMARY KEY,
                  solicitud_id INT          NOT NULL,
                  item_id      INT          NOT NULL,
                  origen       VARCHAR(20)  NOT NULL DEFAULT 'documento',
                  tipo_doc     VARCHAR(80)  NULL,
                  nombre       VARCHAR(250) NOT NULL,
                  archivo_url  VARCHAR(500) NULL,
                  copia_url    VARCHAR(500) NULL,
                  vigencia     DATE         NULL,
                  vencido      TINYINT(1)   NOT NULL DEFAULT 0,
                  bytes        INT          NULL,
                  nota         VARCHAR(200) NULL,
                  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  KEY idx_sol (solicitud_id),
                  KEY idx_item (item_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            // Para instalaciones donde la tabla nació sin la columna.
            try {
                $this->pdo->exec("ALTER TABLE cliente_solicitudes_cert_item
                                  ADD COLUMN IF NOT EXISTS ocupacion VARCHAR(150) NULL");
            } catch (\Throwable $e) { /* ya existe */ }
        } catch (\Throwable $e) {
            error_log('[SolicitudesCert] migrate: ' . $e->getMessage());
        }
    }

    private function norm(string $id): string {
        $id = trim($id);
        return ctype_digit($id) ? str_pad($id, 5, '0', STR_PAD_LEFT) : $id;
    }

    /** Folio por cliente: SC-00123-0004. Secuencial por cliente, como el de materiales. */
    private function siguienteFolio(string $idCliente): string {
        try {
            $s = $this->pdo->prepare("SELECT COUNT(*) FROM cliente_solicitudes_cert WHERE id_cliente = ?");
            $s->execute([$idCliente]);
            $n = (int)$s->fetchColumn() + 1;
        } catch (\Throwable $e) { $n = 1; }
        return 'SC-' . $idCliente . '-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
    }

    // ════════════════════════════════════════════════════════
    //  LADO DEL CLIENTE
    // ════════════════════════════════════════════════════════

    /**
     * Crea una solicitud con uno o varios renglones.
     *
     * Van varios en una sola porque así se pide en la práctica —"certifícame
     * estos seis montacargas" es una visita, no seis— y cada renglón lleva su
     * propio estado, de modo que uno pueda resultar conforme y otro no sin
     * enredar el seguimiento.
     */
    public function crear(array $payload, string $idCliente, string $quien): array {
        $idCliente = $this->norm($idCliente);
        if (!$idCliente) return ['status' => 'error', 'message' => 'No se pudo identificar la empresa.'];

        $tipo = strtolower(trim((string)($payload['tipo'] ?? '')));
        if (!in_array($tipo, self::TIPOS, true)) {
            return ['status' => 'error', 'message' => 'Indica si la solicitud es de personal o de equipos.'];
        }

        $ids = $payload['ids'] ?? [];
        if (!is_array($ids)) $ids = [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return ['status' => 'error', 'message' => 'Selecciona al menos un registro.'];
        if (count($ids) > 50) return ['status' => 'error', 'message' => 'Máximo 50 por solicitud. Divídela en varias.'];

        // Sólo lo que es suyo: un id de otra empresa no entra aunque se inyecte.
        $filas = $this->leerOrigen($tipo, $ids, $idCliente);
        if (!$filas) return ['status' => 'error', 'message' => 'No se encontraron esos registros en tu empresa.'];

        $curps = is_array($payload['curps'] ?? null) ? $payload['curps'] : [];
        /* Qué se certifica a cada quien: operador de grúa, maniobrista,
           supervisor… Es lo que acaba impreso como "acreditado como" en el
           certificado, así que sin eso la solicitud no dice qué se pide. En
           equipos no aplica: lo define el propio tipo de equipo. */
        $ocupas = is_array($payload['ocupaciones'] ?? null) ? $payload['ocupaciones'] : [];
        if ($tipo === 'personal') {
            foreach ($filas as $f) {
                if (trim((string)($ocupas[$f['id']] ?? '')) === '') {
                    return ['status' => 'error', 'message' =>
                        'Indica qué certificación necesita ' . $f['nombre']
                        . ' (operador de grúa, maniobrista, supervisor…).'];
                }
                // La CURP va impresa en el certificado: sin ella no hay documento
                // que emitir. Normalmente viene de la ficha y no se pide aquí.
                $curpFicha = trim((string)($f['curp'] ?? ''));
                $curpForm  = strtoupper(preg_replace('/\s+/', '', (string)($curps[$f['id']] ?? '')));
                if ($curpFicha === '' && !preg_match('/^[A-Z0-9]{18}$/', $curpForm)) {
                    return ['status' => 'error', 'message' =>
                        'Falta la CURP de ' . $f['nombre'] . ' (18 caracteres).'];
                }
            }
        }

        $folio = $this->siguienteFolio($idCliente);
        try {
            $this->pdo->prepare(
                "INSERT INTO cliente_solicitudes_cert
                   (folio, id_cliente, tipo, estado, servicio, fecha_deseada, sede,
                    contacto_nombre, contacto_tel, notas, solicitado_por)
                 VALUES (?,?,?,'ENVIADA',?,?,?,?,?,?,?)"
            )->execute([
                $folio, $idCliente, $tipo,
                mb_substr(trim((string)($payload['servicio'] ?? '')), 0, 200) ?: null,
                $this->fecha($payload['fecha_deseada'] ?? ''),
                mb_substr(trim((string)($payload['sede'] ?? '')), 0, 300) ?: null,
                mb_substr(trim((string)($payload['contacto_nombre'] ?? '')), 0, 150) ?: null,
                mb_substr(trim((string)($payload['contacto_tel'] ?? '')), 0, 40) ?: null,
                mb_substr(trim((string)($payload['notas'] ?? '')), 0, 2000) ?: null,
                mb_substr($quien, 0, 150) ?: null,
            ]);
            $solId = (int)$this->pdo->lastInsertId();

            $ins = $this->pdo->prepare(
                "INSERT INTO cliente_solicitudes_cert_item (solicitud_id, origen_id, nombre, detalle, curp, ocupacion)
                 VALUES (?,?,?,?,?,?)"
            );
            foreach ($filas as $f) {
                /* La CURP de la ficha manda. Sólo si la persona todavía no la
                   tiene se toma la que se escribió aquí, y se guarda en su
                   ficha para no volver a pedirla en la próxima solicitud. */
                $curp = strtoupper(trim((string)($f['curp'] ?? '')));
                if ($curp === '') {
                    $curp = strtoupper(preg_replace('/\s+/', '', (string)($curps[$f['id']] ?? '')));
                    if ($tipo === 'personal' && preg_match('/^[A-Z0-9]{18}$/', $curp)) {
                        try {
                            $this->pdo->prepare("UPDATE cliente_personal SET curp = ? WHERE id = ? AND id_cliente = ?")
                                ->execute([$curp, (int)$f['id'], $idCliente]);
                        } catch (\Throwable $e) { /* la columna puede faltar: no detiene la solicitud */ }
                    }
                }
                $ocup = trim((string)($ocupas[$f['id']] ?? ''));
                $ins->execute([
                    $solId, (int)$f['id'],
                    mb_substr($f['nombre'], 0, 250),
                    mb_substr($f['detalle'], 0, 400) ?: null,
                    $curp !== '' ? mb_substr($curp, 0, 20) : null,
                    $ocup !== '' ? mb_substr($ocup, 0, 150) : null,
                ]);
            }
        } catch (\Throwable $e) {
            error_log('[SolicitudesCert] crear: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'No se pudo registrar la solicitud.'];
        }

        $docs = $this->copiarDocumentacion($solId, $folio, $tipo);

        return ['status' => 'success', 'folio' => $folio, 'id' => $solId, 'documentos' => $docs,
                'message' => 'Solicitud ' . $folio . ' enviada'
                    . ($docs ? ' con ' . $docs . ' documento(s) de respaldo' : '')
                    . '. Calidad la revisará.'];
    }

    /**
     * Nombre y detalle de cada registro, comprobando que sea del cliente.
     *
     * @return array<int,array{id:int,nombre:string,detalle:string}>
     */
    private function leerOrigen(string $tipo, array $ids, string $idCliente): array {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $sql = $tipo === 'personal'
            ? "SELECT id, nombre, COALESCE(curp,'') AS curp,
                      CONCAT_WS(' · ', NULLIF(puesto,''), NULLIF(numero_empleado,'')) AS detalle
               FROM cliente_personal WHERE id_cliente = ? AND id IN ($in)"
            : "SELECT id, nombre,
                      CONCAT_WS(' · ', NULLIF(tipo,''), NULLIF(marca,''), NULLIF(modelo,''),
                                NULLIF(serie,''), NULLIF(capacidad,'')) AS detalle
               FROM cliente_equipos WHERE id_cliente = ? AND id IN ($in)";
        try {
            $st = $this->pdo->prepare($sql);
            $st->execute(array_merge([$idCliente], $ids));
            return array_map(fn($r) => [
                'id'      => (int)$r['id'],
                'nombre'  => (string)$r['nombre'],
                'detalle' => (string)($r['detalle'] ?? ''),
                'curp'    => (string)($r['curp'] ?? ''),
            ], $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } catch (\Throwable $e) {
            error_log('[SolicitudesCert] leerOrigen: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Guarda, junto con la solicitud, la documentación que el cliente tiene
     * cargada de cada persona o equipo pedido.
     *
     * Dos cosas, no una: el registro de qué documentos había —con su vigencia y
     * si ya estaban vencidos al momento de pedir— y una COPIA del archivo. Lo
     * segundo es lo que convierte esto en soporte: el cliente puede dar de baja
     * al operador mañana, y la constancia de con qué se le certificó tiene que
     * seguir siendo nuestra.
     *
     * Nunca tumba la solicitud. Un documento que no se pueda copiar se anota
     * con el motivo y se sigue: vale más una solicitud con un faltante señalado
     * que ninguna solicitud.
     *
     * @return int cuántos documentos quedaron registrados
     */
    private function copiarDocumentacion(int $solId, string $folio, string $tipo): int {
        try {
            $it = $this->pdo->prepare(
                "SELECT id, origen_id FROM cliente_solicitudes_cert_item WHERE solicitud_id = ?"
            );
            $it->execute([$solId]);
            $items = $it->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) { return 0; }
        if (!$items) return 0;

        // De personal interesan sus documentos Y sus certificaciones previas:
        // unos acreditan quién es, otras qué sabe hacer.
        $fuentes = $tipo === 'personal'
            ? [
                ['documento',    'cliente_personal_docs', 'personal_id', 'tipo_doc', 'nombre',    'vigencia'],
                ['certificacion','cliente_personal_cert', 'personal_id', 'entidad',  'tipo_cert', 'fecha_vigencia'],
              ]
            : [
                ['documento',    'cliente_equipos_docs',  'equipo_id',   'tipo_doc', 'nombre',    'vigencia'],
                ['certificacion','cliente_equipos_cert',  'equipo_id',   'folio',    'tipo_cert', 'fecha_vigencia'],
              ];

        $destinoDir = rtrim(UPLOAD_DIR, '/') . '/solicitudes/' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $folio) . '/';
        $guardar = $this->pdo->prepare(
            "INSERT INTO cliente_solicitudes_cert_doc
               (solicitud_id, item_id, origen, tipo_doc, nombre, archivo_url, copia_url, vigencia, vencido, bytes, nota)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)"
        );
        $hoy = date('Y-m-d');
        $n   = 0;

        foreach ($items as $item) {
            $origenId = (int)($item['origen_id'] ?? 0);
            if (!$origenId) continue;

            foreach ($fuentes as [$clase, $tabla, $col, $colTipo, $colNombre, $colVig]) {
                try {
                    $q = $this->pdo->prepare(
                        "SELECT `$colTipo` AS tipo_doc, `$colNombre` AS nombre, archivo_url, `$colVig` AS vigencia
                         FROM `$tabla` WHERE `$col` = ? ORDER BY id"
                    );
                    $q->execute([$origenId]);
                    $filas = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
                } catch (\Throwable $e) { continue; }

                foreach ($filas as $d) {
                    $vig = trim((string)($d['vigencia'] ?? ''));
                    [$copia, $bytes, $nota] = $this->copiarArchivo((string)($d['archivo_url'] ?? ''), $destinoDir);
                    try {
                        $guardar->execute([
                            $solId, (int)$item['id'], $clase,
                            mb_substr((string)($d['tipo_doc'] ?? ''), 0, 80) ?: null,
                            mb_substr((string)($d['nombre'] ?? 'Documento'), 0, 250),
                            mb_substr((string)($d['archivo_url'] ?? ''), 0, 500) ?: null,
                            $copia,
                            $vig !== '' ? $vig : null,
                            ($vig !== '' && $vig < $hoy) ? 1 : 0,
                            $bytes, $nota,
                        ]);
                        $n++;
                    } catch (\Throwable $e) {
                        error_log('[SolicitudesCert] guardar doc: ' . $e->getMessage());
                    }
                }
            }
        }
        return $n;
    }

    /** Tope por archivo. Un documento de identidad no pesa esto; algo mayor es otra cosa. */
    private const MAX_COPIA_BYTES = 15728640;   // 15 MB

    /**
     * Copia un archivo del cliente a la carpeta de la solicitud.
     *
     * @return array{0:?string,1:?int,2:?string} ruta de la copia, tamaño y, si no se pudo, el motivo
     */
    private function copiarArchivo(string $url, string $destinoDir): array {
        $url = trim($url);
        if ($url === '') return [null, null, 'El cliente no adjuntó archivo'];

        $origen = $this->rutaFisica($url);
        if ($origen === null) return [null, null, 'No se encontró el archivo'];

        $bytes = @filesize($origen) ?: 0;
        if ($bytes > self::MAX_COPIA_BYTES) {
            return [null, $bytes, 'Archivo demasiado grande para copiar'];
        }

        try {
            if (!is_dir($destinoDir) && !@mkdir($destinoDir, 0755, true) && !is_dir($destinoDir)) {
                return [null, $bytes, 'No se pudo crear la carpeta de respaldo'];
            }
            // El nombre lleva un prefijo único: dos personas pueden tener su INE
            // con el mismo nombre de archivo y una no debe pisar a la otra.
            $base = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', basename($url));
            $destino = $destinoDir . substr(md5($url), 0, 8) . '_' . $base;
            if (!@copy($origen, $destino)) return [null, $bytes, 'No se pudo copiar el archivo'];
        } catch (\Throwable $e) {
            return [null, $bytes, 'No se pudo copiar el archivo'];
        }

        return [UPLOAD_URL . 'solicitudes/' . basename(rtrim($destinoDir, '/')) . '/' . basename($destino), $bytes, null];
    }

    /**
     * Dónde vive de verdad un archivo cuyo archivo_url guardado es una ruta web.
     *
     * Se guarda con el prefijo "uploads/", que ya es la propia carpeta de subidas,
     * así que concatenarlo a secas da una ruta con "uploads/uploads/". Se prueban
     * las dos formas antes de darlo por perdido.
     */
    private function rutaFisica(string $url): ?string {
        $base = rtrim(UPLOAD_DIR, '/') . '/';
        $rel  = ltrim($url, '/');
        $pref = ltrim(UPLOAD_URL, '/');
        foreach ([
            $base . ($pref !== '' && str_starts_with($rel, $pref) ? substr($rel, strlen($pref)) : $rel),
            $base . $rel,
            $rel,
        ] as $cand) {
            if ($cand !== '' && is_file($cand)) return $cand;
        }
        return null;
    }

    private function fecha($v): ?string {
        $v = trim((string)$v);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    }

    /**
     * El cliente agrega al catálogo una certificación que no estaba.
     *
     * Queda disponible para todos, que es lo pedido: si un cliente necesita algo
     * que no teníamos contemplado, la siguiente empresa que lo necesite ya lo
     * encuentra. Se marca su origen para que Calidad pueda revisar después qué
     * entró por esta vía y depurarlo.
     *
     * No se duplica lo que ya existe aunque venga con otras mayúsculas o
     * espacios: sin eso, "maniobrista" y "Maniobrista " acabarían siendo dos
     * renglones distintos en una lista que se imprime en un certificado.
     */
    public function agregarOcupacion(array $payload): array {
        $nombre = trim(preg_replace('/\s+/', ' ', (string)($payload['nombre'] ?? '')));
        if ($nombre === '')            return ['status' => 'error', 'message' => 'Escribe la certificación.'];
        if (mb_strlen($nombre) < 4)    return ['status' => 'error', 'message' => 'Escribe el nombre completo de la certificación.'];
        if (mb_strlen($nombre) > 150)  return ['status' => 'error', 'message' => 'El nombre es demasiado largo.'];

        try {
            $this->pdo->exec("ALTER TABLE ocupaciones_especificas ADD COLUMN IF NOT EXISTS origen VARCHAR(20) NULL");
        } catch (\Throwable $e) { /* ya existe o no aplica */ }

        try {
            $dup = $this->pdo->prepare(
                "SELECT nombre, activo FROM ocupaciones_especificas
                 WHERE UPPER(TRIM(nombre)) = ? LIMIT 1"
            );
            $dup->execute([mb_strtoupper($nombre)]);
            $ya = $dup->fetch(PDO::FETCH_ASSOC);
            if ($ya) {
                // Si existía pero estaba dada de baja, se reactiva: la necesitan.
                if (!(int)($ya['activo'] ?? 1)) {
                    $this->pdo->prepare("UPDATE ocupaciones_especificas SET activo = 1 WHERE UPPER(TRIM(nombre)) = ?")
                        ->execute([mb_strtoupper($nombre)]);
                }
                return ['status' => 'success', 'nombre' => $ya['nombre'],
                        'message' => 'Esa certificación ya estaba en la lista.'];
            }

            try {
                $this->pdo->prepare("INSERT INTO ocupaciones_especificas (nombre, activo, origen) VALUES (?,1,'cliente')")
                    ->execute([$nombre]);
            } catch (\Throwable $e) {
                $this->pdo->prepare("INSERT INTO ocupaciones_especificas (nombre, activo) VALUES (?,1)")
                    ->execute([$nombre]);
            }
        } catch (\Throwable $e) {
            error_log('[SolicitudesCert] agregarOcupacion: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'No se pudo agregar la certificación.'];
        }
        return ['status' => 'success', 'nombre' => $nombre, 'message' => 'Certificación agregada a la lista.'];
    }

    /** Las solicitudes de una empresa, con sus renglones. */
    public function misSolicitudes(string $idCliente): array {
        $idCliente = $this->norm($idCliente);
        if (!$idCliente) return ['status' => 'error', 'message' => 'No se pudo identificar la empresa.'];
        return ['status' => 'success', 'data' => $this->listado('s.id_cliente = ?', [$idCliente])];
    }

    /** El cliente retira una solicitud que todavía no se programó. */
    public function cancelar(int $id, string $idCliente): array {
        $idCliente = $this->norm($idCliente);
        $st = $this->pdo->prepare("SELECT estado, folio FROM cliente_solicitudes_cert WHERE id = ? AND id_cliente = ?");
        $st->execute([$id, $idCliente]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return ['status' => 'error', 'message' => 'Solicitud no encontrada.'];

        if (!in_array($row['estado'], self::CANCELABLES, true)) {
            return ['status' => 'error', 'message' =>
                'La solicitud ya está ' . (self::ESTADOS[$row['estado']] ?? $row['estado'])
                . '. Comunícate con Calidad para cancelarla.'];
        }
        $this->pdo->prepare("UPDATE cliente_solicitudes_cert SET estado='CANCELADA', updated_at=NOW() WHERE id = ?")
            ->execute([$id]);
        return ['status' => 'success', 'message' => 'Solicitud ' . $row['folio'] . ' cancelada.'];
    }

    // ════════════════════════════════════════════════════════
    //  LADO DE CALIDAD
    // ════════════════════════════════════════════════════════

    /** La bandeja. Por omisión, lo que está abierto. */
    public function bandeja(array $filtros = []): array {
        $where  = [];
        $params = [];

        $estado = strtoupper(trim((string)($filtros['estado'] ?? '')));
        if ($estado === 'ABIERTAS' || $estado === '') {
            $where[] = "s.estado IN ('ENVIADA','EN_REVISION','PROGRAMADA')";
        } elseif ($estado !== 'TODAS') {
            if (!isset(self::ESTADOS[$estado])) return ['status' => 'error', 'message' => 'Estado desconocido.'];
            $where[] = 's.estado = ?';
            $params[] = $estado;
        }

        $tipo = strtolower(trim((string)($filtros['tipo'] ?? '')));
        if (in_array($tipo, self::TIPOS, true)) { $where[] = 's.tipo = ?'; $params[] = $tipo; }

        $cli = trim((string)($filtros['cliente'] ?? ''));
        if ($cli !== '') { $where[] = 's.id_cliente LIKE ?'; $params[] = '%' . $cli . '%'; }

        return ['status' => 'success',
                'data'   => $this->listado($where ? implode(' AND ', $where) : '1=1', $params)];
    }

    private function listado(string $where, array $params): array {
        try {
            $st = $this->pdo->prepare("
                SELECT s.id, s.folio, s.id_cliente, s.tipo, s.estado, s.servicio,
                       DATE_FORMAT(s.fecha_deseada,'%d/%m/%Y')   AS fecha_deseada,
                       DATE_FORMAT(s.programada_para,'%d/%m/%Y') AS programada_para,
                       s.sede, s.contacto_nombre, s.contacto_tel, s.notas,
                       s.solicitado_por, s.revisado_por, s.inspector, s.motivo,
                       DATE_FORMAT(s.created_at,'%d/%m/%Y %H:%i') AS fecha,
                       COALESCE(c.nombre_cliente, s.id_cliente)   AS cliente
                FROM cliente_solicitudes_cert s
                LEFT JOIN clientes c ON c.primera_parte = s.id_cliente
                WHERE $where
                ORDER BY FIELD(s.estado,'ENVIADA','EN_REVISION','PROGRAMADA','ATENDIDA','RECHAZADA','CANCELADA'),
                         s.created_at DESC
                LIMIT 300
            ");
            $st->execute($params);
            $filas = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('[SolicitudesCert] listado: ' . $e->getMessage());
            return [];
        }
        if (!$filas) return [];

        $ids = array_column($filas, 'id');
        $in  = implode(',', array_fill(0, count($ids), '?'));
        try {
            $it = $this->pdo->prepare(
                "SELECT id, solicitud_id, origen_id, nombre, detalle, curp, ocupacion, estado, avba_ref_id, notas
                 FROM cliente_solicitudes_cert_item WHERE solicitud_id IN ($in) ORDER BY id"
            );
            $it->execute($ids);
            $porSol = [];
            foreach ($it->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $porSol[(int)$r['solicitud_id']][] = $r;
            }
        } catch (\Throwable $e) { $porSol = []; }

        foreach ($filas as &$f) {
            $f['items']        = $porSol[(int)$f['id']] ?? [];
            $f['total_items']  = count($f['items']);
            $f['estado_texto'] = self::ESTADOS[$f['estado']] ?? $f['estado'];
        }
        return $filas;
    }

    /**
     * Calidad mueve la solicitud de estado.
     *
     * Las transiciones están acotadas: una solicitud atendida, rechazada o
     * cancelada ya no se mueve, y rechazar exige decir por qué —el cliente
     * recibe ese texto y "no procede" a secas no le sirve de nada.
     */
    public function actualizar(array $payload, string $quien): array {
        $id = (int)($payload['id'] ?? 0);
        if (!$id) return ['status' => 'error', 'message' => 'Falta la solicitud.'];

        $nuevo = strtoupper(trim((string)($payload['estado'] ?? '')));
        if (!isset(self::ESTADOS[$nuevo])) return ['status' => 'error', 'message' => 'Estado desconocido.'];

        $st = $this->pdo->prepare("SELECT estado, folio FROM cliente_solicitudes_cert WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return ['status' => 'error', 'message' => 'Solicitud no encontrada.'];

        $permitido = [
            'ENVIADA'     => ['EN_REVISION', 'PROGRAMADA', 'RECHAZADA'],
            'EN_REVISION' => ['PROGRAMADA', 'RECHAZADA', 'ENVIADA'],
            'PROGRAMADA'  => ['ATENDIDA', 'RECHAZADA', 'EN_REVISION'],
        ];
        if (!in_array($nuevo, $permitido[$row['estado']] ?? [], true)) {
            return ['status' => 'error', 'message' =>
                'No se puede pasar de ' . (self::ESTADOS[$row['estado']] ?? $row['estado'])
                . ' a ' . self::ESTADOS[$nuevo] . '.'];
        }

        $motivo = trim((string)($payload['motivo'] ?? ''));
        if ($nuevo === 'RECHAZADA' && $motivo === '') {
            return ['status' => 'error', 'message' => 'Escribe por qué se rechaza: el cliente recibe ese texto.'];
        }

        $sets   = ['estado = ?', 'revisado_por = ?', 'updated_at = NOW()'];
        $params = [$nuevo, mb_substr($quien, 0, 50)];

        if ($nuevo === 'PROGRAMADA') {
            $sets[]   = 'programada_para = ?';
            $params[] = $this->fecha($payload['programada_para'] ?? '');
            $sets[]   = 'inspector = ?';
            $params[] = mb_substr(trim((string)($payload['inspector'] ?? '')), 0, 150) ?: null;
        }
        if ($motivo !== '') { $sets[] = 'motivo = ?'; $params[] = mb_substr($motivo, 0, 2000); }

        $params[] = $id;
        $this->pdo->prepare("UPDATE cliente_solicitudes_cert SET " . implode(', ', $sets) . " WHERE id = ?")
            ->execute($params);

        return ['status' => 'success', 'message' =>
            'Solicitud ' . $row['folio'] . ': ' . self::ESTADOS[$nuevo] . '.'];
    }

    /**
     * Lo que el cliente ya subió de cada registro pedido.
     *
     * Es la razón de ser de la bandeja: Calidad ve la documentación antes de
     * programar, en vez de que el inspector se entere en planta de que falta.
     */
    public function documentos(int $solicitudId): array {
        $st = $this->pdo->prepare(
            "SELECT folio, tipo, id_cliente FROM cliente_solicitudes_cert WHERE id = ?"
        );
        $st->execute([$solicitudId]);
        $sol = $st->fetch(PDO::FETCH_ASSOC);
        if (!$sol) return ['status' => 'error', 'message' => 'Solicitud no encontrada.'];

        $it = $this->pdo->prepare(
            "SELECT id, origen_id, nombre, detalle, curp, ocupacion
             FROM cliente_solicitudes_cert_item WHERE solicitud_id = ? ORDER BY id"
        );
        $it->execute([$solicitudId]);
        $items = $it->fetchAll(PDO::FETCH_ASSOC) ?: [];

        /* Se lee el respaldo guardado al solicitar, no lo que el cliente tenga
           hoy. Es la diferencia entre "esto es lo que nos presentó" y "esto es
           lo que tiene ahora", y para sostener un certificado emitido sirve lo
           primero. */
        $porItem = [];
        try {
            $d = $this->pdo->prepare(
                "SELECT item_id, origen, tipo_doc, nombre, archivo_url, copia_url,
                        DATE_FORMAT(vigencia,'%d/%m/%Y') AS vigencia, vencido, bytes, nota
                 FROM cliente_solicitudes_cert_doc WHERE solicitud_id = ? ORDER BY item_id, origen, id"
            );
            $d->execute([$solicitudId]);
            foreach ($d->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $porItem[(int)$r['item_id']][] = $r;
            }
        } catch (\Throwable $e) {
            error_log('[SolicitudesCert] documentos: ' . $e->getMessage());
        }

        $total = 0;
        foreach ($items as &$i) {
            $i['documentos'] = $porItem[(int)$i['id']] ?? [];
            $total += count($i['documentos']);
        }

        return ['status' => 'success', 'tipo' => $sol['tipo'], 'folio' => $sol['folio'],
                'total' => $total, 'items' => $items];
    }
}
