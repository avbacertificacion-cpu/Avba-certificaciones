<?php
/**
 * AVBA Certificaciones — Módulo de Calidad
 * Migración de Codigo.gs (getDataCalidad, aprobarCalidad, actualizarCalidad)
 */

class Calidad {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // ── Obtener registros pendientes de revisión ───────────
    public function getDataCalidad(): array {
        $stmt = $this->pdo->query(
            "SELECT id, id AS fila,
                    DATE_FORMAT(marca_temporal, '%d/%m/%Y %H:%i') AS marca_temporal,
                    cliente, coords_inspeccion, maquinaria, marca, modelo, serie,
                    id_equipo,
                    DATE_FORMAT(fecha_inspeccion, '%d/%m/%Y') AS fecha,
                    correo, control, evidencia_url AS evidencia, direccion, capacidad,
                    disponibilidad, estado, motivo, qr_codigo,
                    certificado_url AS link, dictamen_url AS dictamen,
                    envio_direccion AS envio, coordenadas_envio,
                    reporte_url, prueba_carga, inspector
             FROM equipos
             WHERE estado IN ('PENDIENTE', 'CONFORME', 'NO CONFORME', 'RECHAZADO', 'RETORNADO')
             ORDER BY marca_temporal DESC"
        );
        $rows = $stmt->fetchAll();

        // Agregar URL del QR y decodificar prueba_carga
        foreach ($rows as &$r) {
            $r['qr_url'] = $r['qr_codigo'] ? urlQR($r['qr_codigo']) : '';
            $r['prueba_carga'] = !empty($r['prueba_carga'])
                ? (json_decode($r['prueba_carga'], true) ?? null)
                : null;
        }
        unset($r);

        return $rows;
    }

    // ── Aprobar inspección ─────────────────────────────────
    public function aprobarCalidad(array $payload, string $usuario): array {
        $id = (int) ($payload['id'] ?? $payload['fila'] ?? 0);
        $qr = trim($payload['qr'] ?? '');

        if (!$id) return ['status' => 'error', 'message' => 'ID de equipo requerido.'];
        if (!$qr)  return ['status' => 'error', 'message' => 'El código QR es requerido.'];

        $row = $this->obtenerEquipo($id);
        if (!$row) return ['status' => 'error', 'message' => 'Registro no encontrado.'];

        if (!qrEsDeSerie($qr, QR_PREFIJO_EQUIPO)) {
            return ['status' => 'error', 'message' =>
                'Los códigos QR de equipo empiezan con ' . QR_PREFIJO_EQUIPO . '. El código indicado no pertenece a esa serie.'];
        }

        // El código lo captura Calidad libremente: NO tiene que existir en el
        // banco de códigos pre-generados. Sólo se impide reutilizar uno que ya
        // esté asignado a OTRO registro.
        $stmtQR = $this->pdo->prepare(
            "SELECT id, usado, equipo_id FROM qr_codigos WHERE identificador = ?"
        );
        $stmtQR->execute([$qr]);
        $qrRow = $stmtQR->fetch();

        $qrYaEsDeEsteEquipo = $qrRow && $qrRow['usado'] && (int)$qrRow['equipo_id'] === $id;
        $avisoSustitucion = '';
        if ($qrRow && $qrRow['usado'] && !$qrYaEsDeEsteEquipo) {
            $excepto = ['tabla' => 'equipos', 'id' => $id];
            // Con autorización expresa, el personal cede el código: la etiqueta
            // ya está pegada en el equipo y la de la persona se puede recalcular.
            if (!empty($payload['sustituir_personal'])) {
                $sus = sustituirQrDePersonal($this->pdo, $qr, $usuario);
                if (!$sus['ok']) return ['status' => 'error', 'message' => $sus['message']];
                $avisoSustitucion = ' ' . $sus['message'];
            } else {
                return respuestaQrOcupado($this->pdo, $qr, $excepto);
            }
        }

        $estadoAnterior = $row['estado'];
        $nuevoEstado    = 'APROBADO CALIDAD';
        $qrAnterior     = $row['qr_codigo'] ?? '';

        // Si hay un QR previo diferente, liberarlo
        if ($qrAnterior && $qrAnterior !== $qr) {
            $this->pdo->prepare(
                "UPDATE qr_codigos SET usado = 0, equipo_id = NULL WHERE identificador = ?"
            )->execute([$qrAnterior]);
        }

        // Asignar QR al equipo y aprobar
        $this->pdo->prepare(
            "UPDATE equipos SET estado = ?, motivo = NULL, qr_codigo = ? WHERE id = ?"
        )->execute([$nuevoEstado, $qr, $id]);

        // Registrar el código como usado (lo inserta en el banco si no existía)
        qrRegistrarUsado($this->pdo, $qr, $id);

        registrarHistorial($this->pdo, $usuario, $id, 'estado', $estadoAnterior, $nuevoEstado);
        registrarHistorial($this->pdo, $usuario, $id, 'qr_codigo', $row['qr_codigo'] ?? null, $qr);

        return ['status' => 'success',
            'message' => 'Inspección aprobada y QR asignado correctamente.' . $avisoSustitucion];
    }

    // ── Cambiar el inspector que firma la inspección ───────
    public function cambiarInspectorEquipo(array $payload, string $usuario): array {
        $id        = (int) ($payload['id'] ?? $payload['fila'] ?? 0);
        $inspector = trim((string)($payload['inspector'] ?? ''));
        if (!$id) return ['status' => 'error', 'message' => 'ID de equipo requerido.'];
        if ($inspector === '') return ['status' => 'error', 'message' => 'Selecciona un inspector.'];

        $row = $this->obtenerEquipo($id);
        if (!$row) return ['status' => 'error', 'message' => 'Registro no encontrado.'];

        // Validar que el inspector exista y tenga rol INSPECTOR
        $chk = $this->pdo->prepare("SELECT nombre FROM usuarios WHERE usuario = ? AND rol = 'INSPECTOR' LIMIT 1");
        $chk->execute([$inspector]);
        $insp = $chk->fetch();
        if (!$insp) return ['status' => 'error', 'message' => 'El inspector seleccionado no es válido.'];

        $anterior = $row['inspector'] ?? '';
        $this->pdo->prepare("UPDATE equipos SET inspector = ? WHERE id = ?")->execute([$inspector, $id]);
        registrarHistorial($this->pdo, $usuario, $id, 'inspector', $anterior, $inspector);

        return ['status' => 'success', 'message' => 'Inspector que firma actualizado: ' . ($insp['nombre'] ?? $inspector) . '.'];
    }

    // ── Actualizar datos en calidad ────────────────────────
    public function actualizarCalidad(array $payload, string $usuario): array {
        $id = (int) ($payload['id'] ?? $payload['fila'] ?? 0);
        if (!$id) return ['status' => 'error', 'message' => 'ID de equipo requerido.'];

        $row = $this->obtenerEquipo($id);
        if (!$row) return ['status' => 'error', 'message' => 'Registro no encontrado.'];

        $campos  = ['cliente','maquinaria','marca','modelo','serie','capacidad','correo','id_equipo','estado','motivo','direccion'];
        $sets    = [];
        $params  = [];

        foreach ($campos as $campo) {
            if (array_key_exists($campo, $payload)) {
                $sets[]  = "`{$campo}` = ?";
                $params[] = $payload[$campo] === '' ? null : $payload[$campo];
                registrarHistorial($this->pdo, $usuario, $id, $campo, $row[$campo] ?? null, $payload[$campo]);
            }
        }

        // Fecha de inspección: el frontend envía 'fecha' en dd/mm/aaaa (o aaaa-mm-dd)
        if (array_key_exists('fecha', $payload) && trim((string)$payload['fecha']) !== '') {
            $fechaRaw = trim((string)$payload['fecha']);
            $fechaDb  = null;
            if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $fechaRaw, $m)) {
                $fechaDb = "{$m[3]}-{$m[2]}-{$m[1]}";
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaRaw)) {
                $fechaDb = $fechaRaw;
            }
            if ($fechaDb === null) {
                return ['status' => 'error', 'message' => 'Formato de fecha inválido. Usa dd/mm/aaaa.'];
            }
            $sets[]   = '`fecha_inspeccion` = ?';
            $params[] = $fechaDb;
            registrarHistorial($this->pdo, $usuario, $id, 'fecha_inspeccion', $row['fecha_inspeccion'] ?? null, $fechaDb);
        }

        // QR manual
        if (!empty($payload['qr_codigo'])) {
            $sets[]  = '`qr_codigo` = ?';
            $params[] = $payload['qr_codigo'];
            registrarHistorial($this->pdo, $usuario, $id, 'qr_codigo', $row['qr_codigo'] ?? null, $payload['qr_codigo']);
        }

        // Prueba de carga: se guardan TODOS los campos que capture cada
        // plantilla (no solo grúa). Ángulo/altura se recalculan solos cuando
        // hay radio y pluma. Los campos de captura única (CML, etc.) llegan en
        // '__header'. Los campos calculados que reporta el cliente se ignoran
        // (se recalculan aquí) para no confiar en valores manipulables.
        if (!empty($payload['prueba_carga_updates']) && is_array($payload['prueba_carga_updates'])) {
            $updates = $payload['prueba_carga_updates'];
            if (!empty($updates['plantilla'])) {
                $pc = !empty($row['prueba_carga'])
                    ? (json_decode($row['prueba_carga'], true) ?? [])
                    : [];
                $pc['plantilla'] = $updates['plantilla'];

                // Campos de captura única (no por fila): CML, tipo de plataforma, etc.
                if (!empty($updates['__header']) && is_array($updates['__header'])) {
                    foreach ($updates['__header'] as $hk => $hv) {
                        if ($hk === '' ) continue;
                        $pc[$hk] = is_scalar($hv) ? (string)$hv : $hv;
                    }
                }

                foreach ($updates as $rowKey => $fields) {
                    if ($rowKey === 'plantilla' || $rowKey === '__header' || !is_array($fields)) continue;
                    if (!isset($pc[$rowKey]) || !is_array($pc[$rowKey])) $pc[$rowKey] = [];

                    // Guardar todos los campos enviados
                    foreach ($fields as $f => $v) {
                        if ($f === 'angulo' || $f === 'altura') continue; // se recalculan abajo
                        $pc[$rowKey][$f] = is_scalar($v) ? (string)$v : $v;
                    }

                    // Recalcular ángulo/altura de grúa cuando aplican (radio y pluma)
                    $radio = (float)($pc[$rowKey]['radio'] ?? 0);
                    $pluma = (float)($pc[$rowKey]['pluma'] ?? 0);
                    if ($radio > 0 && $pluma > 0 && $radio <= $pluma) {
                        $pc[$rowKey]['angulo'] = (string)round(acos($radio / $pluma) * 180 / M_PI, 1);
                        $pc[$rowKey]['altura'] = (string)round(sqrt($pluma * $pluma - $radio * $radio), 2);
                    } else {
                        // Sin geometría de grúa (no hay radio+pluma): ángulo/altura
                        // se capturan a mano (telehandler, montacargas, ptem…) —
                        // respetar lo que envió el cliente.
                        foreach (['angulo', 'altura'] as $f) {
                            if (array_key_exists($f, $fields)) {
                                $pc[$rowKey][$f] = is_scalar($fields[$f]) ? (string)$fields[$f] : $fields[$f];
                            }
                        }
                    }
                }

                $pcJson = json_encode($pc, JSON_UNESCAPED_UNICODE);
                $sets[]   = '`prueba_carga` = ?';
                $params[]  = $pcJson;
                registrarHistorial($this->pdo, $usuario, $id, 'prueba_carga', $row['prueba_carga'] ?? null, $pcJson);
            }
        }

        if (empty($sets)) return ['status' => 'success', 'message' => 'Sin cambios.'];

        $params[] = $id;
        $this->pdo->prepare("UPDATE equipos SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);

        return ['status' => 'success', 'message' => 'Datos actualizados correctamente.'];
    }

    // ── Rechazar inspección ────────────────────────────────
    public function rechazarCalidad(array $payload, string $usuario): array {
        $id     = (int) ($payload['id'] ?? $payload['fila'] ?? 0);
        $motivo = trim($payload['motivo'] ?? '');

        if (!$id) return ['status' => 'error', 'message' => 'ID de equipo requerido.'];

        $row = $this->obtenerEquipo($id);
        if (!$row) return ['status' => 'error', 'message' => 'Registro no encontrado.'];

        $this->pdo->prepare(
            "UPDATE equipos SET estado = 'NO CONFORME', motivo = ? WHERE id = ?"
        )->execute([$motivo ?: null, $id]);

        registrarHistorial($this->pdo, $usuario, $id, 'estado', $row['estado'], 'NO CONFORME');

        return ['status' => 'success', 'message' => 'Inspección rechazada.'];
    }

    // ── Auxiliar: obtener registro por id ──────────────────
    private function obtenerEquipo(int $id): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM equipos WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    // ══════════════════════════════════════════════════════════
    //  EVIDENCIA FOTOGRÁFICA
    // ══════════════════════════════════════════════════════════

    /** Lo que la API sabe recibir como foto. */
    private const EV_TIPOS = ['image/jpeg', 'image/png', 'image/webp'];

    /** 12 MB por foto. Una cámara de celular no pasa de ahí. */
    private const EV_MAX_BYTES = 12582912;

    /** Cuántas se admiten de una sola vez. */
    private const EV_MAX_ARCHIVOS = 12;

    /**
     * Agrega fotos a la evidencia de un equipo desde Calidad.
     *
     * Se AGREGA, nunca se reemplaza. Las fotos del inspector son la evidencia
     * de lo que se vio en campo el día de la inspección; lo que Calidad suma
     * —un detalle que faltaba, la placa de datos que salió borrosa— se pone al
     * lado, no encima.
     *
     * Por eso también van con su propio prefijo: en un expediente acreditado
     * importa poder distinguir quién aportó cada imagen. Y ese prefijo empieza
     * con "z" a propósito: el dictamen toma las primeras nueve fotos en orden
     * alfabético, así que las de Calidad se ordenan al final y no desplazan a
     * las del inspector.
     */
    public function subirEvidencia(array $post, array $files, string $usuario): array {
        $id = (int)($post['id'] ?? $post['fila'] ?? 0);
        if ($id <= 0) return ['status' => 'error', 'message' => 'ID de equipo requerido.'];

        $eq = $this->obtenerEquipo($id);
        if (!$eq) return ['status' => 'error', 'message' => 'Registro no encontrado.'];

        // Normaliza la subida: el navegador manda un arreglo cuando el input es
        // múltiple y un solo valor cuando no.
        $entrada = $files['fotos'] ?? $files['foto'] ?? null;
        if (!$entrada) return ['status' => 'error', 'message' => 'No llegó ninguna foto.'];
        $lote = is_array($entrada['name'] ?? null)
            ? array_map(fn($i) => [
                'name'     => $entrada['name'][$i],
                'type'     => $entrada['type'][$i]     ?? '',
                'tmp_name' => $entrada['tmp_name'][$i] ?? '',
                'error'    => $entrada['error'][$i]    ?? UPLOAD_ERR_NO_FILE,
                'size'     => $entrada['size'][$i]     ?? 0,
              ], array_keys($entrada['name']))
            : [$entrada];

        $lote = array_slice($lote, 0, self::EV_MAX_ARCHIVOS);

        // ── Carpeta destino ───────────────────────────────────
        // Si el equipo ya tiene una carpeta nuestra, se usa esa. Si su
        // evidencia apunta a otro lado —una liga externa de las viejas— no se
        // toca: se crea la carpeta local y a partir de ahí vive aquí.
        $baseDir = rtrim(UPLOAD_DIR, '/') . '/evidencias/';
        $carpeta = '';
        $urlPrev = trim((string)($eq['evidencia_url'] ?? ''));
        if ($urlPrev !== '') {
            $rel = trim((string)parse_url($urlPrev, PHP_URL_PATH), '/');
            $rel = basename($rel);
            if ($rel !== '' && strpos($rel, '..') === false && is_dir($baseDir . $rel)) {
                $carpeta = $rel;
            }
        }
        if ($carpeta === '') {
            // El folio de control identifica al equipo mejor que su id interno.
            $base = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($eq['control'] ?? '')) ?: ('eq' . $id);
            $carpeta = $base;
        }
        $destino = $baseDir . $carpeta . '/';
        if (!is_dir($destino) && !@mkdir($destino, 0755, true)) {
            error_log('[Calidad] evidencia: no se pudo crear ' . $destino);
            return ['status' => 'error', 'message' => 'No se pudo crear la carpeta de evidencia.'];
        }

        $guardadas = 0;
        $rechazos  = [];
        $sello     = date('Ymd_His');

        foreach ($lote as $n => $f) {
            $nombre = (string)($f['name'] ?? ('foto ' . ($n + 1)));
            if ((int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $rechazos[] = "$nombre: no llegó completa";
                continue;
            }
            // El peso se revisa antes: una foto enorme merece que se le diga que
            // pesa de más, no un "archivo no válido" que no explica nada. La
            // guarda de is_uploaded_file sigue delante de cualquier escritura.
            if ((int)($f['size'] ?? 0) > self::EV_MAX_BYTES) { $rechazos[] = "$nombre: pesa más de 12 MB"; continue; }
            $tmp = (string)($f['tmp_name'] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) { $rechazos[] = "$nombre: archivo no válido"; continue; }

            // El tipo se decide leyendo el archivo, no por lo que diga el nombre.
            $mime = '';
            if (function_exists('finfo_open') && ($fi = finfo_open(FILEINFO_MIME_TYPE))) {
                $mime = (string)finfo_file($fi, $tmp);
                finfo_close($fi);
            }
            if (!in_array($mime, self::EV_TIPOS, true)) { $rechazos[] = "$nombre: no es una imagen"; continue; }

            $salida = $destino . 'z_calidad_' . $sello . '_' . ($n + 1) . '.jpg';
            // Se comprime como el resto de las imágenes del sistema: una foto de
            // celular pesa varios MB y en el dictamen se ve igual.
            $final = comprimirImagen($tmp, $salida, 1600, 1600, 78);
            if ($final === false) {
                if (!@move_uploaded_file($tmp, $salida)) { $rechazos[] = "$nombre: no se pudo guardar"; continue; }
            }
            $guardadas++;
        }

        if ($guardadas === 0) {
            return ['status' => 'error',
                'message' => 'No se guardó ninguna foto.' . ($rechazos ? ' ' . implode('; ', $rechazos) . '.' : '')];
        }

        // Si el equipo no tenía carpeta propia, ahora la tiene.
        $urlCarpeta = rtrim(UPLOAD_URL, '/') . '/evidencias/' . $carpeta;
        if ($urlPrev === '' || $urlPrev !== $urlCarpeta) {
            $this->pdo->prepare("UPDATE equipos SET evidencia_url = ? WHERE id = ?")
                      ->execute([$urlCarpeta, $id]);
            registrarHistorial($this->pdo, $usuario, $id, 'evidencia_url', $urlPrev ?: null, $urlCarpeta);
        }
        error_log("[Calidad] $usuario agregó $guardadas foto(s) de evidencia al equipo $id");

        $msg = $guardadas . ' foto(s) agregadas a la evidencia.';
        if ($rechazos) $msg .= ' No se pudieron subir: ' . implode('; ', $rechazos) . '.';

        return ['status' => 'success', 'message' => $msg,
                'guardadas' => $guardadas, 'evidencia_url' => $urlCarpeta];
    }

    // ── Listar info de códigos QR ──────────────────────────
    public function listarQrInfo(string $filtro = 'todos'): array {
        $this->ensureQrTable();

        $total      = (int) $this->pdo->query("SELECT COUNT(*) FROM qr_codigos")->fetchColumn();
        $usados     = (int) $this->pdo->query("SELECT COUNT(*) FROM qr_codigos WHERE usado = 1")->fetchColumn();
        $disponibles = $total - $usados;
        $ultimo     = $this->pdo->query("SELECT MAX(CAST(identificador AS UNSIGNED)) FROM qr_codigos")->fetchColumn();

        $where = match($filtro) {
            'disponibles' => 'WHERE usado = 0',
            'usados'      => 'WHERE usado = 1',
            default       => '',
        };
        $codigos = $this->pdo->query(
            "SELECT identificador, usado FROM qr_codigos {$where}
             ORDER BY CAST(identificador AS UNSIGNED) DESC LIMIT 200"
        )->fetchAll();

        return [
            'status'      => 'success',
            'total'       => $total,
            'usados'      => $usados,
            'disponibles' => $disponibles,
            'ultimo'      => $ultimo ?: null,
            'codigos'     => $codigos,
            // Una placa puesta en dos registros no se anuncia sola: cada uno se
            // ve bien por su lado y el choque sólo sale cuando alguien escanea.
            'duplicados'  => qrDuplicados($this->pdo),
        ];
    }

    // ── Generar lote de códigos QR consecutivos ────────────
    public function generarQrLote(array $payload): array {
        $this->ensureQrTable();

        // Tope de seguridad por lote (evita intentar generar millones de golpe)
        $MAX_LOTE = 100000;

        $hasta = trim($payload['hasta'] ?? '');
        if (!qrFormatoValido($hasta)) {
            return ['status' => 'error', 'message' => qrMensajeFormato()];
        }
        // Las placas conviven en dos longitudes (10 dígitos maquinaria y
        // personal, 9 el lote nuevo de accesorios). Un lote se genera dentro de
        // UNA longitud: mezclarlas produciría códigos que no existen impresos.
        $digitos = strlen($hasta);

        $desdeRaw = trim($payload['desde'] ?? '');
        if ($desdeRaw !== '') {
            if (!qrFormatoValido($desdeRaw)) {
                return ['status' => 'error', 'message' => qrMensajeFormato()];
            }
            if (strlen($desdeRaw) !== $digitos) {
                return ['status' => 'error', 'message' =>
                    "El inicial tiene " . strlen($desdeRaw) . " dígitos y el final $digitos. Un lote no puede mezclar longitudes: son series de placas distintas."];
            }
            $desde = (int) $desdeRaw;
        } else {
            // Por defecto continúa desde el último de ESA misma longitud. Sin
            // esta condición, un lote de 9 dígitos arrancaría a partir del
            // mayor de 10 (4 000 000 000 > 635 261 114) y nunca cuadraría.
            $st = $this->pdo->prepare(
                "SELECT MAX(CAST(identificador AS UNSIGNED)) FROM qr_codigos WHERE LENGTH(identificador) = ?"
            );
            // Se enlaza como entero a propósito: LENGTH() devuelve número y un
            // parámetro de texto no compara igual en todos los motores.
            $st->bindValue(1, $digitos, PDO::PARAM_INT);
            $st->execute();
            $ultimoRaw = $st->fetchColumn();
            $desde = ($ultimoRaw ? (int) $ultimoRaw : 0) + 1;
        }
        $hastaInt = (int) $hasta;

        // El rango no puede cambiar de longitud a mitad de camino.
        if (strlen((string)$desde) !== $digitos) {
            return ['status' => 'error', 'message' =>
                "El rango se sale de los $digitos dígitos. Indica el número inicial con \"Iniciar en\"."];
        }

        if ($hastaInt < $desde) {
            return ['status' => 'error', 'message' => "El número final ({$hastaInt}) debe ser mayor o igual que el inicial ({$desde})."];
        }

        $cantidad = $hastaInt - $desde + 1;
        if ($cantidad > $MAX_LOTE) {
            return ['status' => 'error', 'message' =>
                'El rango solicitado genera ' . number_format($cantidad) . ' códigos, y el máximo por lote es ' .
                number_format($MAX_LOTE) . '. Usa el campo "Iniciar en" para arrancar una serie nueva (p. ej. 5000000000) y define un rango más pequeño.'];
        }

        $stmt = $this->pdo->prepare(
            "INSERT IGNORE INTO qr_codigos (identificador, usado) VALUES (?, 0)"
        );
        $this->pdo->beginTransaction();
        try {
            $insertados = 0;
            for ($n = $desde; $n <= $hastaInt; $n++) {
                // Se rellena a la longitud del lote, no a 10: un código de 9
                // dígitos con un cero delante no sería el que está impreso.
                $stmt->execute([str_pad((string) $n, $digitos, '0', STR_PAD_LEFT)]);
                $insertados += $stmt->rowCount();
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            return ['status' => 'error', 'message' => 'Error al insertar códigos: ' . $e->getMessage()];
        }

        $omitidos = $cantidad - $insertados;
        $msg = number_format($insertados) . ' código' . ($insertados !== 1 ? 's' : '') . ' generado' . ($insertados !== 1 ? 's' : '') . ' correctamente.';
        if ($omitidos > 0) $msg .= ' (' . number_format($omitidos) . ' ya existían y se omitieron.)';

        return ['status' => 'success', 'message' => $msg, 'cantidad' => $insertados];
    }

    private function ensureQrTable(): void {
        // Si la tabla ya existe —el caso normal— un fallo aquí no debe impedir
        // cargar el lote: se deja constancia en el log y se sigue.
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS qr_codigos (
                    id            INT AUTO_INCREMENT PRIMARY KEY,
                    identificador VARCHAR(20) NOT NULL UNIQUE,
                    usado         TINYINT(1)  DEFAULT 0,
                    equipo_id     INT         DEFAULT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\Throwable $e) {
            error_log('[Calidad] ensureQrTable: ' . $e->getMessage());
        }
    }

    // ── Eliminar equipo y liberar QR ───────────────────────
    /**
     * Elimina un equipo inspeccionado (grúa, maquinaria).
     *
     * Calidad puede eliminar aunque el registro ya esté enviado y publicado
     * —para eso está: quitar duplicados y capturas equivocadas—, pero entonces
     * se exige un motivo y la baja queda anotada en el historial, porque el
     * registro desaparece también del portal del cliente.
     */
    public function eliminarEquipo(int $id, string $usuario = '', string $motivo = ''): array {
        $row = $this->pdo->prepare(
            "SELECT cliente, control, maquinaria, serie, estado, qr_codigo FROM equipos WHERE id = ?"
        );
        $row->execute([$id]);
        $equipo = $row->fetch();
        if (!$equipo) return ['status' => 'error', 'message' => 'Registro no encontrado.'];

        $publicado = in_array((string)$equipo['estado'], ['ENVIADO', 'RETORNADO'], true);
        $motivo    = trim($motivo);
        if ($publicado && $motivo === '') {
            return ['status' => 'error', 'requiere_motivo' => true, 'message' =>
                'Este equipo ya tiene documentos entregados al cliente. Indica el motivo de la baja para poder eliminarlo.'];
        }

        try {
            if (!empty($equipo['qr_codigo'])) {
                $this->pdo->prepare(
                    "UPDATE qr_codigos SET usado = 0, equipo_id = NULL WHERE identificador = ?"
                )->execute([$equipo['qr_codigo']]);
            }
            // inspeccion_checklist tiene ON DELETE CASCADE → se borra automáticamente
            // historial_general tiene ON DELETE SET NULL → no bloquea el DELETE
            $this->pdo->prepare("DELETE FROM equipos WHERE id = ?")->execute([$id]);

            if (function_exists('registrarEliminacion')) {
                registrarEliminacion(
                    $this->pdo, $usuario ?: 'sistema', "equipo#$id",
                    'Equipo ' . ($equipo['control'] ?: 's/folio') . ' — ' . $equipo['cliente']
                        . ' — ' . trim(($equipo['maquinaria'] ?? '') . ' ' . ($equipo['serie'] ?? ''))
                        . ' — estado ' . ($equipo['estado'] ?: 'PENDIENTE'),
                    $motivo
                );
            }

            return [
                'status'    => 'success',
                'publicado' => $publicado,
                'message'   => 'Registro eliminado.' . ($publicado ? ' Ya no aparece en el portal del cliente.' : ''),
            ];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
}
