<?php
/**
 * Socios Comerciales AVBA — Interacción desde el correo
 *
 * Todo lo que una persona puede contestar o hacer pulsando un enlace del
 * correo, sin escribir la contraseña: confirmar que sigue interesada, decir
 * cuándo puede empezar, marcar sus certificaciones, apartar una entrevista.
 *
 * El enlace va firmado (ver scFirmarEnlace en helpers.php), así que lleva
 * dentro a quién pertenece. Nadie puede contestar por otro cambiando un
 * número en la URL, y aun así no hace falta sesión.
 */

class ScInteraccion {

    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // ══════════════════════════════════════════════════════════
    //  CATÁLOGO
    // ══════════════════════════════════════════════════════════

    /**
     * Las preguntas que puede llevar un correo.
     *
     * Vive aquí y no en la base a propósito: son textos de cara al candidato
     * y el valor que se guarda es una clave corta y estable. Si mañana se
     * reescribe una etiqueta, las respuestas ya recogidas siguen valiendo.
     */
    public const PREGUNTAS = [
        'interes' => [
            'multiple' => false,
            'titulo'   => '¿Sigues buscando trabajo en la industria?',
            'opciones' => [
                'si' => 'Sí, sigo interesado',
                'no' => 'Por ahora no',
            ],
        ],
        'disponibilidad' => [
            'multiple' => false,
            'titulo'   => '¿Cuándo podrías empezar?',
            'opciones' => [
                'inmediata' => 'De inmediato',
                'quince'    => 'En 15 días',
                'mes'       => 'En un mes',
                'despues'   => 'Más adelante',
            ],
        ],
        'sueldo' => [
            'multiple' => false,
            'titulo'   => '¿Qué sueldo mensual buscas?',
            'opciones' => [
                'hasta15'  => 'Hasta $15,000',
                'de15a25'  => '$15,000 – $25,000',
                'de25a40'  => '$25,000 – $40,000',
                'mas40'    => 'Más de $40,000',
            ],
        ],
        'movilidad' => [
            'multiple' => false,
            'titulo'   => '¿Podrías trabajar fuera de tu ciudad?',
            'opciones' => [
                'foranea'  => 'Sí, donde sea',
                'region'   => 'Solo en mi región',
                'local'    => 'Solo en mi ciudad',
            ],
        ],
        'certificacion' => [
            'multiple' => true,
            'titulo'   => '¿Qué certificaciones tienes vigentes?',
            'opciones' => [
                'cwi'        => 'Inspector de soldadura CWI (AWS)',
                'api510'     => 'API 510 — Recipientes a presión',
                'api570'     => 'API 570 — Tubería',
                'api653'     => 'API 653 — Tanques de almacenamiento',
                'ndt2'       => 'Ensayos no destructivos nivel II (ASNT)',
                'irata'      => 'Trabajos verticales (IRATA / SPRAT)',
                'alturas'    => 'Trabajos en altura',
                'confinados' => 'Espacios confinados',
                'nom020'     => 'NOM-020-STPS',
                'nom029'     => 'NOM-029-STPS (eléctrica)',
                'izaje'      => 'Maniobras e izaje',
                'ninguna'    => 'Ninguna por ahora',
            ],
        ],
    ];

    /** Etiqueta legible de una respuesta ya guardada. */
    public static function etiqueta(string $tipo, string $valor): string {
        return self::PREGUNTAS[$tipo]['opciones'][$valor] ?? $valor;
    }

    public static function tituloPregunta(string $tipo): string {
        return self::PREGUNTAS[$tipo]['titulo'] ?? $tipo;
    }

    // ══════════════════════════════════════════════════════════
    //  REGISTRAR UNA RESPUESTA
    // ══════════════════════════════════════════════════════════

    /**
     * Guarda lo que alguien contestó desde el correo.
     *
     * En las preguntas de una sola respuesta la última manda: si contesta
     * "en 15 días" y luego "de inmediato", se queda lo segundo. En las de
     * varias (certificaciones) se van sumando, y volver a pulsar la misma no
     * duplica nada.
     */
    public function registrar(int $usuarioId, string $tipo, string $valor, string $origen = 'correo'): array {
        $pregunta = self::PREGUNTAS[$tipo] ?? null;
        if (!$pregunta) {
            return ['status' => 'error', 'message' => 'Esa pregunta ya no está disponible.'];
        }
        if (!isset($pregunta['opciones'][$valor])) {
            return ['status' => 'error', 'message' => 'Esa respuesta ya no está disponible.'];
        }

        $usuario = $this->usuario($usuarioId);
        if (!$usuario) {
            return ['status' => 'error', 'message' => 'La cuenta ya no existe.'];
        }
        if ((int) $usuario['activo'] !== 1) {
            return ['status' => 'error', 'message' => 'Esta cuenta está desactivada. Escríbenos si crees que es un error.'];
        }

        try {
            $this->pdo->beginTransaction();

            // Pregunta de respuesta única: fuera lo anterior.
            if (empty($pregunta['multiple'])) {
                $this->pdo->prepare("DELETE FROM sc_respuestas WHERE usuario_id = ? AND tipo = ?")
                          ->execute([$usuarioId, $tipo]);
            } elseif ($valor === 'ninguna') {
                // "Ninguna" y una lista de certificaciones no pueden convivir.
                $this->pdo->prepare("DELETE FROM sc_respuestas WHERE usuario_id = ? AND tipo = ?")
                          ->execute([$usuarioId, $tipo]);
            } else {
                $this->pdo->prepare("DELETE FROM sc_respuestas WHERE usuario_id = ? AND tipo = ? AND valor = 'ninguna'")
                          ->execute([$usuarioId, $tipo]);
            }

            $this->pdo->prepare(
                "INSERT INTO sc_respuestas (usuario_id, tipo, valor, origen, ip)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE creado = NOW(), origen = VALUES(origen)"
            )->execute([$usuarioId, $tipo, $valor, mb_substr($origen, 0, 20), scIpCliente()]);

            $this->pdo->commit();
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            error_log('ScInteraccion::registrar: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'No se pudo guardar tu respuesta. Inténtalo otra vez.'];
        }

        return [
            'status'   => 'success',
            'tipo'     => $tipo,
            'valor'    => $valor,
            'etiqueta' => $pregunta['opciones'][$valor],
            'titulo'   => $pregunta['titulo'],
            'usuario'  => $usuario,
        ];
    }

    /** Todo lo que ha contestado una persona, agrupado por pregunta. */
    public function respuestasDe(int $usuarioId): array {
        $stmt = $this->pdo->prepare(
            "SELECT tipo, valor, creado FROM sc_respuestas
             WHERE usuario_id = ? ORDER BY tipo, creado"
        );
        $stmt->execute([$usuarioId]);

        $salida = [];
        foreach ($stmt->fetchAll() as $f) {
            $salida[$f['tipo']][] = [
                'valor'    => $f['valor'],
                'etiqueta' => self::etiqueta($f['tipo'], $f['valor']),
                'creado'   => $f['creado'],
            ];
        }
        return $salida;
    }

    /** Conteos para el panel: cuánta gente contestó qué. */
    public function resumen(): array {
        $stmt = $this->pdo->query(
            "SELECT tipo, valor, COUNT(*) AS total
             FROM sc_respuestas GROUP BY tipo, valor"
        );

        $porTipo = [];
        foreach ($stmt->fetchAll() as $f) {
            $porTipo[$f['tipo']][$f['valor']] = (int) $f['total'];
        }

        $salida = [];
        foreach (self::PREGUNTAS as $tipo => $pregunta) {
            $opciones = [];
            foreach ($pregunta['opciones'] as $clave => $etiqueta) {
                $opciones[] = [
                    'valor'    => $clave,
                    'etiqueta' => $etiqueta,
                    'total'    => $porTipo[$tipo][$clave] ?? 0,
                ];
            }
            $salida[] = [
                'tipo'     => $tipo,
                'titulo'   => $pregunta['titulo'],
                'multiple' => !empty($pregunta['multiple']),
                'opciones' => $opciones,
                'total'    => array_sum(array_column($opciones, 'total')),
            ];
        }
        return $salida;
    }

    // ══════════════════════════════════════════════════════════
    //  AVANCE DEL PERFIL
    // ══════════════════════════════════════════════════════════

    /**
     * Cuánto le falta a alguien para tener un perfil aprovechable.
     *
     * Los pesos no son iguales a propósito: un perfil sin currículum no sirve
     * para presentarlo a un cliente por muchas habilidades que tenga.
     */
    public function avancePerfil(int $usuarioId): array {
        $usuario = $this->usuario($usuarioId);
        if (!$usuario) return ['porcentaje' => 0, 'faltantes' => [], 'completo' => false];

        return $usuario['tipo'] === 'empresa'
            ? $this->avanceEmpresa($usuarioId)
            : $this->avancePersona($usuarioId);
    }

    private function avancePersona(int $usuarioId): array {
        $stmt = $this->pdo->prepare(
            "SELECT p.id, p.headline, p.ubicacion, p.resumen, p.cv_url, p.foto_url, p.telefono,
                    (SELECT COUNT(*) FROM sc_experiencia  WHERE persona_id = p.id) AS experiencias,
                    (SELECT COUNT(*) FROM sc_educacion    WHERE persona_id = p.id) AS estudios,
                    (SELECT COUNT(*) FROM sc_habilidades  WHERE persona_id = p.id) AS habilidades
             FROM sc_personas p WHERE p.usuario_id = ?"
        );
        $stmt->execute([$usuarioId]);
        $p = $stmt->fetch();

        $perfil = '/perfil-persona.html';
        if (!$p) {
            return ['porcentaje' => 0, 'completo' => false, 'faltantes' => [
                ['texto' => 'Completar tu perfil', 'url' => $perfil, 'peso' => 100],
            ]];
        }

        $puntos = [
            ['ok' => !empty($p['cv_url']),           'peso' => 20, 'texto' => 'Subir tu currículum',            'url' => $perfil],
            ['ok' => (int) $p['experiencias'] > 0,   'peso' => 15, 'texto' => 'Agregar tu experiencia laboral', 'url' => $perfil],
            ['ok' => (int) $p['habilidades'] >= 3,   'peso' => 12, 'texto' => 'Agregar al menos tres habilidades', 'url' => $perfil],
            ['ok' => !empty($p['foto_url']),         'peso' => 10, 'texto' => 'Poner tu fotografía',            'url' => $perfil],
            ['ok' => !empty($p['headline']),         'peso' => 10, 'texto' => 'Escribir tu puesto o especialidad', 'url' => $perfil],
            ['ok' => !empty($p['telefono']),         'peso' => 10, 'texto' => 'Dejar un teléfono de contacto',  'url' => $perfil],
            ['ok' => !empty($p['ubicacion']),        'peso' => 10, 'texto' => 'Indicar dónde vives',            'url' => $perfil],
            ['ok' => !empty($p['resumen']),          'peso' =>  8, 'texto' => 'Escribir un resumen breve',      'url' => $perfil],
            ['ok' => (int) $p['estudios'] > 0,       'peso' =>  5, 'texto' => 'Agregar tu formación',           'url' => $perfil],
        ];

        return $this->armarAvance($puntos);
    }

    private function avanceEmpresa(int $usuarioId): array {
        $stmt = $this->pdo->prepare(
            "SELECT e.id, e.giro, e.descripcion, e.sitio_web, e.logo_url, e.ubicacion,
                    (SELECT COUNT(*) FROM sc_vacantes WHERE empresa_id = e.id AND estatus = 'abierta') AS vacantes
             FROM sc_empresas e WHERE e.usuario_id = ?"
        );
        $stmt->execute([$usuarioId]);
        $e = $stmt->fetch();

        $perfil = '/perfil-empresa.html';
        if (!$e) {
            return ['porcentaje' => 0, 'completo' => false, 'faltantes' => [
                ['texto' => 'Completar el perfil de tu empresa', 'url' => $perfil, 'peso' => 100],
            ]];
        }

        $puntos = [
            ['ok' => (int) $e['vacantes'] > 0,  'peso' => 25, 'texto' => 'Publicar tu primera vacante', 'url' => '/mis-vacantes.html'],
            ['ok' => !empty($e['logo_url']),    'peso' => 20, 'texto' => 'Subir el logotipo',           'url' => $perfil],
            ['ok' => !empty($e['descripcion']), 'peso' => 20, 'texto' => 'Describir a qué se dedica',   'url' => $perfil],
            ['ok' => !empty($e['giro']),        'peso' => 15, 'texto' => 'Indicar el giro',             'url' => $perfil],
            ['ok' => !empty($e['ubicacion']),   'peso' => 10, 'texto' => 'Indicar dónde están',         'url' => $perfil],
            ['ok' => !empty($e['sitio_web']),   'peso' => 10, 'texto' => 'Poner el sitio web',          'url' => $perfil],
        ];

        return $this->armarAvance($puntos);
    }

    private function armarAvance(array $puntos): array {
        $total = 0;
        $faltantes = [];

        foreach ($puntos as $punto) {
            if ($punto['ok']) {
                $total += $punto['peso'];
            } else {
                $faltantes[] = ['texto' => $punto['texto'], 'url' => $punto['url'], 'peso' => $punto['peso']];
            }
        }

        // Los que más pesan, primero: son los que conviene enseñar si solo
        // caben tres renglones en el correo.
        usort($faltantes, fn($a, $b) => $b['peso'] <=> $a['peso']);

        return [
            'porcentaje' => min(100, $total),
            'completo'   => $faltantes === [],
            'faltantes'  => $faltantes,
        ];
    }

    // ══════════════════════════════════════════════════════════
    //  VACANTES QUE LE ENCAJAN
    // ══════════════════════════════════════════════════════════

    /**
     * Vacantes abiertas para enseñarle a alguien dentro del correo.
     *
     * Primero las que coinciden con alguna de sus habilidades o con su
     * ciudad; si no sale nada, las más recientes. Un correo con tres
     * vacantes cualesquiera sigue siendo mejor que uno sin ninguna.
     */
    public function vacantesPara(int $usuarioId, int $max = 3): array {
        $max = max(1, min($max, 6));

        $stmt = $this->pdo->prepare(
            "SELECT p.id, p.ubicacion,
                    (SELECT GROUP_CONCAT(habilidad SEPARATOR ' ')
                       FROM sc_habilidades WHERE persona_id = p.id) AS habilidades
             FROM sc_personas p WHERE p.usuario_id = ?"
        );
        $stmt->execute([$usuarioId]);
        $persona = $stmt->fetch();

        $terminos = [];
        if ($persona) {
            foreach (preg_split('/[\s,;]+/', (string) $persona['habilidades']) as $palabra) {
                $palabra = trim($palabra);
                if (mb_strlen($palabra) >= 4) $terminos[] = $palabra;
            }
            $terminos = array_slice(array_unique($terminos), 0, 8);
        }

        $vacantes = [];

        if ($terminos || !empty($persona['ubicacion'])) {
            $donde  = [];
            $params = [];
            foreach ($terminos as $t) {
                $donde[]  = "(v.titulo LIKE ? OR v.descripcion LIKE ?)";
                $like     = '%' . scEscaparLike($t) . '%';
                $params[] = $like;
                $params[] = $like;
            }
            if (!empty($persona['ubicacion'])) {
                $donde[]  = "v.ubicacion LIKE ?";
                $params[] = '%' . scEscaparLike($persona['ubicacion']) . '%';
            }

            $sql = "SELECT v.id, v.titulo, v.ubicacion, v.modalidad, v.salario, e.nombre AS empresa
                    FROM sc_vacantes v
                    JOIN sc_empresas e ON e.id = v.empresa_id
                    WHERE v.estatus = 'abierta' AND (" . implode(' OR ', $donde) . ")
                    ORDER BY v.creado DESC LIMIT {$max}";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $vacantes = $stmt->fetchAll();
        }

        if (count($vacantes) < $max) {
            $ya    = array_column($vacantes, 'id');
            $hueco = $max - count($vacantes);
            $sql   = "SELECT v.id, v.titulo, v.ubicacion, v.modalidad, v.salario, e.nombre AS empresa
                      FROM sc_vacantes v
                      JOIN sc_empresas e ON e.id = v.empresa_id
                      WHERE v.estatus = 'abierta'"
                   . ($ya ? " AND v.id NOT IN (" . implode(',', array_map('intval', $ya)) . ")" : "")
                   . " ORDER BY v.creado DESC LIMIT {$hueco}";
            $vacantes = array_merge($vacantes, $this->pdo->query($sql)->fetchAll());
        }

        return $vacantes;
    }

    // ══════════════════════════════════════════════════════════
    //  AGENDA DE ENTREVISTAS
    // ══════════════════════════════════════════════════════════

    /** Franjas libres de aquí en adelante. */
    public function franjasLibres(int $max = 4): array {
        $max  = max(1, min($max, 12));
        $stmt = $this->pdo->query(
            "SELECT id, inicio, minutos, modo, nota FROM sc_franjas
             WHERE usuario_id IS NULL AND inicio > NOW()
             ORDER BY inicio ASC LIMIT {$max}"
        );
        return $stmt->fetchAll();
    }

    /** La franja que ya tiene apartada una persona, si tiene alguna. */
    public function franjaDe(int $usuarioId): ?array {
        $stmt = $this->pdo->prepare(
            "SELECT id, inicio, minutos, modo, nota FROM sc_franjas
             WHERE usuario_id = ? AND inicio > NOW() ORDER BY inicio ASC LIMIT 1"
        );
        $stmt->execute([$usuarioId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Aparta una franja.
     *
     * El UPDATE lleva la condición `usuario_id IS NULL` dentro: si dos
     * personas pulsan el mismo horario a la vez, solo una de las dos ve
     * rowCount() === 1 y la otra recibe un aviso claro en vez de quedarse
     * las dos convencidas de tener la cita.
     */
    public function reservarFranja(int $usuarioId, int $franjaId): array {
        $stmt = $this->pdo->prepare("SELECT * FROM sc_franjas WHERE id = ?");
        $stmt->execute([$franjaId]);
        $franja = $stmt->fetch();

        if (!$franja) {
            return ['status' => 'error', 'message' => 'Ese horario ya no está en la agenda.'];
        }
        if ((int) $franja['usuario_id'] === $usuarioId) {
            return ['status' => 'success', 'franja' => $franja, 'repetida' => true];
        }
        if (strtotime($franja['inicio']) < time()) {
            return ['status' => 'error', 'message' => 'Ese horario ya pasó. Escríbenos y buscamos otro.'];
        }

        // Una cita por persona: si ya tenía otra, se libera al tomar la nueva.
        $this->pdo->prepare(
            "UPDATE sc_franjas SET usuario_id = NULL, tomada = NULL
             WHERE usuario_id = ? AND id <> ?"
        )->execute([$usuarioId, $franjaId]);

        $reserva = $this->pdo->prepare(
            "UPDATE sc_franjas SET usuario_id = ?, tomada = NOW()
             WHERE id = ? AND usuario_id IS NULL"
        );
        $reserva->execute([$usuarioId, $franjaId]);

        if ($reserva->rowCount() !== 1) {
            return [
                'status'  => 'error',
                'message' => 'Alguien tomó ese horario hace un momento. Elige otro de la lista.',
                'libres'  => $this->franjasLibres(4),
            ];
        }

        $stmt->execute([$franjaId]);
        return ['status' => 'success', 'franja' => $stmt->fetch(), 'repetida' => false];
    }

    // ══════════════════════════════════════════════════════════
    //  APOYO
    // ══════════════════════════════════════════════════════════

    private function usuario(int $usuarioId): ?array {
        $stmt = $this->pdo->prepare(
            "SELECT u.id, u.correo, u.tipo, u.activo, u.estatus, u.estatus_fecha, u.creado,
                    COALESCE(p.nombre, e.nombre) AS nombre
             FROM sc_usuarios u
             LEFT JOIN sc_personas p ON p.usuario_id = u.id
             LEFT JOIN sc_empresas e ON e.usuario_id = u.id
             WHERE u.id = ?"
        );
        $stmt->execute([$usuarioId]);
        return $stmt->fetch() ?: null;
    }

    /** Los estatus, con el texto que ve la persona en la página pública. */
    public const ESTATUS = [
        'nuevo'         => ['titulo' => 'Registro recibido',        'texto' => 'Tenemos tu información y está en la fila para revisarse.'],
        'en_revision'   => ['titulo' => 'En revisión',              'texto' => 'Estamos revisando tu documentación.'],
        'primer_filtro' => ['titulo' => 'Pasaste el primer filtro', 'texto' => 'Tu información fue procesada y pasó el primer filtro de nuestro proceso de selección.'],
        'entrevista'    => ['titulo' => 'En entrevista',            'texto' => 'Estás en la etapa de entrevistas.'],
        'aprobado'      => ['titulo' => 'Aprobado',                 'texto' => 'Quedaste dentro de nuestro padrón de socios comerciales.'],
        'no_procede'    => ['titulo' => 'Sin continuidad por ahora','texto' => 'Por ahora no continuamos con tu proceso. Tu información queda en nuestro padrón por si surge algo que encaje.'],
    ];

    /** Estado público de una cuenta, buscada por folio. */
    public function estadoPorFolio(string $folio): array {
        $id = scFolioAId($folio);
        if ($id <= 0) {
            return ['status' => 'error', 'message' => 'Ese folio no tiene el formato correcto. Es algo como SC-000123.'];
        }

        $usuario = $this->usuario($id);
        if (!$usuario || (int) $usuario['activo'] !== 1) {
            return ['status' => 'error', 'message' => 'No encontramos ese folio.'];
        }

        $estatus = self::ESTATUS[$usuario['estatus']] ?? self::ESTATUS['nuevo'];
        $avance  = $this->avancePerfil($id);

        // A propósito NO se devuelve el correo ni el nombre completo: el
        // folio va en un correo, pero cualquiera que pruebe números
        // consecutivos llegaría a esta página. Lo que ve es su propio
        // avance y en qué punto va, nada que identifique a nadie.
        return [
            'status'     => 'success',
            'folio'      => scFolio($id),
            'etapa'      => $usuario['estatus'],
            'titulo'     => $estatus['titulo'],
            'texto'      => $estatus['texto'],
            'desde'      => $usuario['estatus_fecha'],
            'registrado' => $usuario['creado'],
            'avance'     => $avance['porcentaje'],
            'faltantes'  => array_column(array_slice($avance['faltantes'], 0, 4), 'texto'),
        ];
    }
}
