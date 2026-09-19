<?php
/**
 * AVBA Certificaciones — Exámenes de evaluación para candidatos.
 *
 * Sirve para un caso concreto: mandarle un enlace a alguien que todavía no es
 * cliente —un candidato a operador— para que se identifique, presente un
 * examen teórico y quede su calificación registrada.
 *
 * Tres decisiones que conviene entender antes de tocar nada:
 *
 * 1. El acceso NO inventa un mecanismo nuevo. Se apoya en las sesiones de
 *    acceso que ya existen para los cursos de independientes: el candidato
 *    entra con el identificador CUR-XXXXXX y su contraseña, igual que un
 *    participante. Un sistema de cuentas paralelo sería otra puerta que
 *    mantener y otra que puede quedar mal cerrada.
 *
 * 2. La calificación la pone el servidor, nunca el navegador. Las respuestas
 *    correctas jamás se mandan al cliente: si viajaran, bastaría abrir la
 *    consola para ver el examen resuelto. Lo que llega del navegador son
 *    opciones elegidas, y aquí se comparan contra la base.
 *
 * 3. Aprobar NO certifica a nadie. El que pasa entra a la cola de Calidad como
 *    candidato, y son ellos quienes deciden si procede. Un examen en línea, sin
 *    práctica ni instructor presente, no sustenta una constancia DC-3; tratarlo
 *    como si lo hiciera es un hallazgo de auditoría esperando a ocurrir.
 */

class Examenes {

    private PDO $pdo;

    /** Estados por los que pasa un intento. */
    private const ESTADOS = ['EN_CURSO', 'TERMINADO', 'EXPIRADO'];

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->migrar();
    }

    // ══════════════════════════════════════════════════════════
    //  ESQUEMA
    // ══════════════════════════════════════════════════════════

    /**
     * Cada bloque en su propio try: si una tabla falla —permisos, una versión
     * de MariaDB que no traga algo— las demás siguen creándose, y sobre todo el
     * constructor no lanza. index.php construye TODAS las clases antes de
     * enrutar, así que una excepción aquí tumbaría hasta el login.
     */
    private function migrar(): void {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS examenes (
                  id                  INT AUTO_INCREMENT PRIMARY KEY,
                  clave               VARCHAR(40)  NOT NULL,
                  nombre              VARCHAR(200) NOT NULL,
                  descripcion         TEXT NULL,
                  curso_id            INT NULL,
                  num_preguntas       INT NOT NULL DEFAULT 20,
                  calificacion_minima DECIMAL(5,2) NOT NULL DEFAULT 80.00,
                  minutos             INT NOT NULL DEFAULT 40,
                  intentos_max        INT NOT NULL DEFAULT 1,
                  activo              TINYINT NOT NULL DEFAULT 1,
                  created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  UNIQUE KEY uk_clave (clave)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\Throwable $e) { error_log('[Examenes] migrar examenes: ' . $e->getMessage()); }

        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS examen_preguntas (
                  id          INT AUTO_INCREMENT PRIMARY KEY,
                  examen_id   INT NOT NULL,
                  texto       TEXT NOT NULL,
                  referencia  VARCHAR(250) NULL,
                  orden       INT NOT NULL DEFAULT 0,
                  activa      TINYINT NOT NULL DEFAULT 1,
                  KEY idx_examen (examen_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\Throwable $e) { error_log('[Examenes] migrar preguntas: ' . $e->getMessage()); }

        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS examen_opciones (
                  id          INT AUTO_INCREMENT PRIMARY KEY,
                  pregunta_id INT NOT NULL,
                  texto       VARCHAR(600) NOT NULL,
                  correcta    TINYINT NOT NULL DEFAULT 0,
                  orden       INT NOT NULL DEFAULT 0,
                  KEY idx_pregunta (pregunta_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\Throwable $e) { error_log('[Examenes] migrar opciones: ' . $e->getMessage()); }

        /*
         * El intento guarda copia de los datos del candidato en vez de sólo
         * apuntar al participante: cuando alguien presenta el examen todavía no
         * es nadie en el sistema, y si después se corrige su expediente el
         * examen tiene que seguir diciendo con qué datos lo presentó.
         */
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS examen_intentos (
                  id               INT AUTO_INCREMENT PRIMARY KEY,
                  examen_id        INT NOT NULL,
                  sesion_acceso_id INT NULL,
                  participante_id  INT NULL,
                  token            CHAR(32) NOT NULL,
                  nombre_completo  VARCHAR(300) NOT NULL,
                  curp             VARCHAR(18)  NULL,
                  puesto           VARCHAR(200) NULL,
                  empresa_nombre   VARCHAR(300) NULL,
                  correo           VARCHAR(200) NULL,
                  telefono         VARCHAR(40)  NULL,
                  estado           VARCHAR(20)  NOT NULL DEFAULT 'EN_CURSO',
                  total_preguntas  INT NOT NULL DEFAULT 0,
                  aciertos         INT NOT NULL DEFAULT 0,
                  calificacion     DECIMAL(5,2) NOT NULL DEFAULT 0,
                  aprobado         TINYINT NOT NULL DEFAULT 0,
                  iniciado_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  terminado_at     DATETIME NULL,
                  ip               VARCHAR(45) NULL,
                  UNIQUE KEY uk_token (token),
                  KEY idx_examen (examen_id),
                  KEY idx_estado (estado)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\Throwable $e) { error_log('[Examenes] migrar intentos: ' . $e->getMessage()); }

        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS examen_respuestas (
                  id          INT AUTO_INCREMENT PRIMARY KEY,
                  intento_id  INT NOT NULL,
                  pregunta_id INT NOT NULL,
                  opcion_id   INT NULL,
                  correcta    TINYINT NOT NULL DEFAULT 0,
                  UNIQUE KEY uk_intento_pregunta (intento_id, pregunta_id),
                  KEY idx_intento (intento_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\Throwable $e) { error_log('[Examenes] migrar respuestas: ' . $e->getMessage()); }

        $this->sembrarGruaTorre();
    }

    // ══════════════════════════════════════════════════════════
    //  BANCO INICIAL
    // ══════════════════════════════════════════════════════════

    /**
     * Deja cargado el examen de operador de grúa torre.
     *
     * Se siembra una sola vez y sólo si ese examen no existe: a partir de ahí
     * lo edita quien lo administre, y sembrar en cada arranque pisaría sus
     * cambios.
     *
     * El contenido se redactó sobre el marco que AVBA ya cita en sus propios
     * documentos —NOM-004-STPS-1999, NOM-006-STPS-2014, NOM-009-STPS-2011 y
     * ASME B30.3— y ESTÁ PENDIENTE DE REVISIÓN TÉCNICA. Quien lo apruebe se
     * hace responsable de su contenido: aquí sólo se dejó capturado.
     */
    private function sembrarGruaTorre(): void {
        try {
            $ya = $this->pdo->prepare("SELECT id FROM examenes WHERE clave = ?");
            $ya->execute(['OP-GRUA-TORRE']);
            if ($ya->fetchColumn()) return;

            $this->pdo->prepare(
                "INSERT INTO examenes (clave, nombre, descripcion, num_preguntas,
                                       calificacion_minima, minutos, intentos_max, activo)
                 VALUES (?,?,?,?,?,?,?,1)"
            )->execute([
                'OP-GRUA-TORRE',
                'Evaluación teórica — Operador de grúa torre',
                'Examen de conocimientos para candidatos a operador de grúa torre. '
                . 'Evalúa tabla de cargas, dispositivos de seguridad, señales, inspección previa '
                . 'y condiciones que obligan a suspender la maniobra. No sustituye la evaluación '
                . 'práctica ni la certificación.',
                20, 80.00, 40, 1,
            ]);
            $examenId = (int)$this->pdo->lastInsertId();

            $insP = $this->pdo->prepare(
                "INSERT INTO examen_preguntas (examen_id, texto, referencia, orden) VALUES (?,?,?,?)"
            );
            $insO = $this->pdo->prepare(
                "INSERT INTO examen_opciones (pregunta_id, texto, correcta, orden) VALUES (?,?,?,?)"
            );

            foreach ($this->bancoGruaTorre() as $i => $p) {
                $insP->execute([$examenId, $p['texto'], $p['ref'] ?? null, ($i + 1) * 10]);
                $preguntaId = (int)$this->pdo->lastInsertId();
                foreach ($p['opciones'] as $j => [$texto, $correcta]) {
                    $insO->execute([$preguntaId, $texto, $correcta ? 1 : 0, ($j + 1) * 10]);
                }
            }
            error_log('[Examenes] sembrado el examen de grúa torre con ' . count($this->bancoGruaTorre()) . ' preguntas');
        } catch (\Throwable $e) {
            error_log('[Examenes] sembrarGruaTorre: ' . $e->getMessage());
        }
    }

    /** @return array<array{texto:string,ref:string,opciones:array<array{0:string,1:bool}>}> */
    private function bancoGruaTorre(): array {
        return [
            ['texto' => 'En una grúa torre, conforme el carro se desplaza hacia la punta de la pluma, la carga máxima que puede izarse:',
             'ref' => 'Tabla de cargas del fabricante · ASME B30.3',
             'opciones' => [
                ['Disminuye, porque aumenta el radio de trabajo', true],
                ['Aumenta, porque se acerca al contrapeso', false],
                ['Se mantiene igual en toda la pluma', false],
                ['Depende sólo de la potencia del malacate', false],
             ]],
            ['texto' => 'Antes de izar una carga, el dato que determina si la maniobra es posible es:',
             'ref' => 'Tabla de cargas del fabricante',
             'opciones' => [
                ['El peso real de la carga y el radio al que se va a izar, comparados contra la tabla de cargas', true],
                ['La experiencia del operador en maniobras parecidas', false],
                ['Que el cable no se vea tenso al levantarla', false],
                ['La capacidad máxima que indica la placa de la grúa', false],
             ]],
            ['texto' => 'Si se desconoce el peso de la carga que se va a izar, el operador debe:',
             'ref' => 'NOM-006-STPS-2014',
             'opciones' => [
                ['No realizar la maniobra hasta determinar el peso', true],
                ['Izarla lentamente y suspender si el cable vibra', false],
                ['Estimarlo a criterio y dejar un margen de seguridad', false],
                ['Consultarlo con el señalero y proceder', false],
             ]],
            ['texto' => 'Cuando la velocidad del viento supera el límite establecido por el fabricante, el operador debe:',
             'ref' => 'ASME B30.3 · Manual del fabricante',
             'opciones' => [
                ['Suspender las maniobras y poner la grúa fuera de servicio', true],
                ['Reducir la carga a la mitad y continuar', false],
                ['Continuar sólo con cargas cercanas a la torre', false],
                ['Girar la pluma contra el viento y seguir operando', false],
             ]],
            ['texto' => 'Al terminar la jornada, la pluma de una grúa torre debe quedar:',
             'ref' => 'ASME B30.3 · Procedimiento de fuera de servicio',
             'opciones' => [
                ['Libre para girar con el viento (en veleta), sin carga y con el gancho elevado', true],
                ['Frenada y bloqueada en la posición del último izaje', false],
                ['Orientada siempre hacia el norte y con el freno de giro puesto', false],
                ['Con el carro en la punta y el gancho apoyado en el piso', false],
             ]],
            ['texto' => 'Durante una maniobra dirigida por señales manuales, el operador debe obedecer:',
             'ref' => 'NOM-004-STPS-1999 · Código de señales',
             'opciones' => [
                ['Únicamente al señalero designado, salvo la señal de alto, que puede darla cualquiera', true],
                ['A cualquier persona que esté en el área de maniobra', false],
                ['Únicamente al supervisor de obra', false],
                ['Al señalero designado, sin excepción alguna', false],
             ]],
            ['texto' => 'La función del limitador de momento (o limitador de carga) es:',
             'ref' => 'NOM-004-STPS-1999 · Dispositivos de seguridad',
             'opciones' => [
                ['Impedir que se exceda el momento de carga máximo permitido por el fabricante', true],
                ['Medir la velocidad del viento en la punta de la pluma', false],
                ['Frenar el giro cuando la pluma alcanza su límite', false],
                ['Indicar al operador cuánto cable queda en el tambor', false],
             ]],
            ['texto' => 'Si un dispositivo de seguridad de la grúa está fallando, lo correcto es:',
             'ref' => 'NOM-004-STPS-1999',
             'opciones' => [
                ['Sacar la grúa de servicio, reportarlo y no operar hasta que se repare', true],
                ['Puentearlo temporalmente mientras se termina la maniobra en curso', false],
                ['Operar con cargas menores hasta que llegue el técnico', false],
                ['Anotarlo en la bitácora y continuar la jornada', false],
             ]],
            ['texto' => 'El interruptor de fin de carrera de elevación sirve para:',
             'ref' => 'ASME B30.3',
             'opciones' => [
                ['Evitar que el gancho suba más allá de su límite y choque con la pluma', true],
                ['Detener el giro cuando hay otra grúa cerca', false],
                ['Limitar el recorrido del carro sobre la pluma', false],
                ['Cortar la energía cuando se abre la puerta de la cabina', false],
             ]],
            ['texto' => 'Un gancho debe retirarse de servicio cuando:',
             'ref' => 'NOM-006-STPS-2014 · ASME B30.10',
             'opciones' => [
                ['Presenta fisuras, deformación, apertura de garganta fuera de tolerancia o el seguro no funciona', true],
                ['Tiene rayones superficiales por el uso normal', false],
                ['Ha izado más de cien cargas en el mes', false],
                ['Su pintura está desgastada', false],
             ]],
            ['texto' => 'Respecto al seguro (pestillo) del gancho:',
             'ref' => 'NOM-006-STPS-2014',
             'opciones' => [
                ['Debe estar presente y funcionando; sin él no debe usarse el gancho', true],
                ['Puede retirarse cuando la carga es de poco peso', false],
                ['Sólo es obligatorio en izajes sobre vía pública', false],
                ['Es opcional si la eslinga tiene grillete', false],
             ]],
            ['texto' => 'Un cable de acero debe retirarse de servicio cuando presenta:',
             'ref' => 'ISO 4309 · ASME B30.3',
             'opciones' => [
                ['Hilos rotos por encima del criterio del fabricante, reducción de diámetro, aplastamiento o corrosión severa', true],
                ['Restos de grasa en su superficie', false],
                ['Marcas de uso en la zona que pasa por la polea', false],
                ['Más de seis meses de instalado, sin importar su estado', false],
             ]],
            ['texto' => 'Está prohibido que la carga pase por encima de:',
             'ref' => 'NOM-006-STPS-2014',
             'opciones' => [
                ['Personas', true],
                ['Material apilado', false],
                ['Equipo estacionado', false],
                ['La caseta de herramienta', false],
             ]],
            ['texto' => 'Izar una carga arrastrándola o jalándola en diagonal (carga lateral) es:',
             'ref' => 'ASME B30.3',
             'opciones' => [
                ['Una práctica prohibida: el gancho debe quedar a plomo sobre la carga', true],
                ['Aceptable si la carga no supera el 50% de la capacidad', false],
                ['Aceptable cuando lo autoriza el señalero', false],
                ['Aceptable si se hace a velocidad mínima', false],
             ]],
            ['texto' => 'Antes de iniciar el izaje, el gancho debe quedar:',
             'ref' => 'NOM-006-STPS-2014 · Fundamentos de eslingado',
             'opciones' => [
                ['Directamente sobre el centro de gravedad de la carga', true],
                ['Sobre el extremo más pesado de la carga', false],
                ['En cualquier punto, si las eslingas son de igual longitud', false],
                ['Desplazado hacia el lado del operador para tener visibilidad', false],
             ]],
            ['texto' => 'Al reducirse el ángulo entre los ramales de una eslinga y la horizontal, la tensión en cada ramal:',
             'ref' => 'Fundamentos de eslingado',
             'opciones' => [
                ['Aumenta', true],
                ['Disminuye', false],
                ['No cambia, porque el peso es el mismo', false],
                ['Se reparte por igual sin importar el ángulo', false],
             ]],
            ['texto' => 'Una vez que la carga se separa unos centímetros del piso, lo correcto es:',
             'ref' => 'NOM-006-STPS-2014',
             'opciones' => [
                ['Detenerse y verificar el equilibrio, el eslingado y la retención del freno antes de continuar', true],
                ['Elevarla de inmediato a la altura de traslado para liberar el área', false],
                ['Girar la pluma mientras se sigue elevando, para ganar tiempo', false],
                ['Soltar el freno y dejar que la carga se estabilice sola', false],
             ]],
            ['texto' => 'Si se pierde la comunicación con el señalero durante una maniobra, el operador debe:',
             'ref' => 'NOM-004-STPS-1999',
             'opciones' => [
                ['Detener la maniobra hasta restablecer la comunicación', true],
                ['Completar el movimiento en curso y luego detenerse', false],
                ['Continuar guiándose por lo que alcance a ver desde la cabina', false],
                ['Bajar la carga al piso de inmediato, en el punto en que esté', false],
             ]],
            ['texto' => 'Respecto al área bajo la carga y la trayectoria del izaje:',
             'ref' => 'NOM-006-STPS-2014',
             'opciones' => [
                ['Debe delimitarse y mantenerse libre de personal', true],
                ['Basta con advertir verbalmente a quienes estén cerca', false],
                ['Sólo requiere delimitarse en cargas de más de una tonelada', false],
                ['Puede ocuparse mientras la carga esté por encima de dos metros', false],
             ]],
            ['texto' => 'El operador NO debe abandonar los controles de la grúa cuando:',
             'ref' => 'ASME B30.3',
             'opciones' => [
                ['Hay una carga suspendida del gancho', true],
                ['La pluma está orientada hacia el edificio', false],
                ['El carro se encuentra a media pluma', false],
                ['Falta menos de una hora para terminar la jornada', false],
             ]],
            ['texto' => 'Al trabajar cerca de líneas eléctricas energizadas, lo correcto es:',
             'ref' => 'NOM-029-STPS-2011 · ASME B30.3',
             'opciones' => [
                ['Respetar la distancia mínima de seguridad o gestionar el libramiento o desenergización de la línea', true],
                ['Operar con precaución si la pluma no toca el cable', false],
                ['Aislar el gancho con material plástico y continuar', false],
                ['Trabajar sólo en días secos y sin viento', false],
             ]],
            ['texto' => 'La inspección previa al inicio de la jornada debe:',
             'ref' => 'ISO 9927-1 · NOM-004-STPS-1999',
             'opciones' => [
                ['Realizarla el operador y quedar registrada, incluyendo cables, gancho, frenos y dispositivos de seguridad', true],
                ['Realizarla únicamente el personal de mantenimiento, una vez al mes', false],
                ['Hacerse de forma visual y sin registro, para no retrasar la obra', false],
                ['Limitarse a verificar el nivel de combustible y la energía', false],
             ]],
            ['texto' => 'El montaje, desmontaje y telescopado de una grúa torre debe realizarlo:',
             'ref' => 'ASME B30.3',
             'opciones' => [
                ['Personal competente, conforme al procedimiento del fabricante y bajo supervisión', true],
                ['El operador de la grúa, por ser quien mejor la conoce', false],
                ['Cualquier cuadrilla de la obra con apoyo del operador', false],
                ['El personal de mantenimiento, sin necesidad de procedimiento escrito', false],
             ]],
            ['texto' => 'Cuando dos grúas torre comparten un área de trabajo con radios que se cruzan:',
             'ref' => 'ASME B30.3 · Plan de izaje',
             'opciones' => [
                ['Debe existir un procedimiento de interferencia con prioridades y comunicación entre operadores', true],
                ['Basta con que cada operador observe a la otra grúa', false],
                ['La grúa más alta siempre tiene el paso', false],
                ['Se opera por turnos alternados de una hora', false],
             ]],
            ['texto' => 'Si la visibilidad se pierde por niebla, lluvia intensa u oscuridad sin iluminación suficiente:',
             'ref' => 'NOM-004-STPS-1999',
             'opciones' => [
                ['Deben suspenderse las maniobras', true],
                ['Se continúa apoyándose únicamente en el radio', false],
                ['Se reduce la velocidad y se continúa con precaución', false],
                ['Se continúa si el señalero mantiene contacto visual con la carga', false],
             ]],
            ['texto' => 'La bitácora de la grúa debe contener:',
             'ref' => 'ISO 9927-1',
             'opciones' => [
                ['El registro de inspecciones, mantenimientos, fallas y reparaciones', true],
                ['Únicamente las horas trabajadas por el operador', false],
                ['Sólo los incidentes con daño a persona o equipo', false],
                ['El listado de cargas izadas durante el mes', false],
             ]],
            ['texto' => 'Un operador que se encuentra fatigado o bajo el efecto de un medicamento que produce somnolencia:',
             'ref' => 'NOM-004-STPS-1999',
             'opciones' => [
                ['No debe operar y debe reportar su condición', true],
                ['Puede operar si se limita a maniobras sencillas', false],
                ['Puede operar acompañado de otro operador en cabina', false],
                ['Puede operar durante la primera mitad de la jornada', false],
             ]],
            ['texto' => 'El contrapeso de una grúa torre:',
             'ref' => 'Manual del fabricante · ASME B30.3',
             'opciones' => [
                ['Debe corresponder exactamente a la configuración indicada por el fabricante', true],
                ['Puede aumentarse para izar cargas mayores a las de la tabla', false],
                ['Puede reducirse cuando se trabaja con radios cortos', false],
                ['Se ajusta en obra según el peso de la carga del día', false],
             ]],
            ['texto' => 'Respecto al arriostramiento y la cimentación de la grúa torre:',
             'ref' => 'Manual del fabricante · Proyecto estructural',
             'opciones' => [
                ['Deben cumplir el proyecto del fabricante y estar verificados antes de operar', true],
                ['Se definen en obra según el espacio disponible', false],
                ['Sólo son necesarios en grúas de más de 40 metros', false],
                ['Los revisa el operador durante la inspección diaria', false],
             ]],
            ['texto' => 'Ante una falla que se presenta durante la operación, el operador debe:',
             'ref' => 'NOM-004-STPS-1999',
             'opciones' => [
                ['Detener la maniobra en condiciones seguras, sacar la grúa de servicio y reportar', true],
                ['Intentar repararla de inmediato para no detener la obra', false],
                ['Terminar la jornada y reportarla al día siguiente', false],
                ['Continuar mientras la falla no afecte el izaje en curso', false],
             ]],
        ];
    }

    // ══════════════════════════════════════════════════════════
    //  LADO PÚBLICO — EL CANDIDATO
    // ══════════════════════════════════════════════════════════

    /** Los exámenes que puede presentar quien entró con una sesión de acceso. */
    public function disponibles(): array {
        try {
            $rows = $this->pdo->query(
                "SELECT id, clave, nombre, descripcion, num_preguntas,
                        calificacion_minima, minutos, intentos_max
                   FROM examenes WHERE activo = 1 ORDER BY nombre"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('[Examenes] disponibles: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'No se pudieron leer los exámenes.'];
        }
        return ['status' => 'success', 'examenes' => $rows];
    }

    /**
     * Abre un intento y entrega las preguntas.
     *
     * Los datos del candidato se piden aquí, con las mismas reglas que el
     * registro de participantes independientes: el nombre es obligatorio y la
     * CURP, si viene, se valida con su dígito verificador. Quien presenta un
     * examen tiene que quedar identificado; una calificación sin nombre no
     * sirve para nada.
     */
    public function iniciar(array $p, ?int $sesionId, string $ip = ''): array {
        $examenId = (int)($p['examen_id'] ?? 0);
        $nombre   = trim((string)($p['nombre_completo'] ?? ''));
        $curp     = strtoupper(trim((string)($p['curp'] ?? '')));
        $puesto   = trim((string)($p['puesto'] ?? ''));
        $empresa  = trim((string)($p['empresa_nombre'] ?? ''));
        $correo   = trim((string)($p['correo'] ?? ''));
        $telefono = trim((string)($p['telefono'] ?? ''));

        if ($nombre === '')  return ['status' => 'error', 'message' => 'El nombre completo es obligatorio.'];
        if ($examenId <= 0)  return ['status' => 'error', 'message' => 'Selecciona el examen a presentar.'];

        if ($curp !== '') {
            $chk = validarCURPCompleta($curp);
            if (!$chk['valida']) return ['status' => 'error', 'message' => $chk['error']];
        }
        if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            return ['status' => 'error', 'message' => 'El correo no tiene un formato válido.'];
        }

        $ex = $this->examen($examenId);
        if (!$ex) return ['status' => 'error', 'message' => 'Examen no encontrado o inactivo.'];

        // Un intento por persona, salvo que el examen admita más. Se cuenta por
        // CURP cuando la hay —es lo único que identifica sin ambigüedad— y por
        // nombre cuando no.
        $limite = (int)$ex['intentos_max'];
        if ($limite > 0) {
            try {
                if ($curp !== '') {
                    $st = $this->pdo->prepare(
                        "SELECT COUNT(*) FROM examen_intentos
                          WHERE examen_id = ? AND curp = ? AND estado = 'TERMINADO'"
                    );
                    $st->execute([$examenId, $curp]);
                } else {
                    $st = $this->pdo->prepare(
                        "SELECT COUNT(*) FROM examen_intentos
                          WHERE examen_id = ? AND nombre_completo = ? AND estado = 'TERMINADO'"
                    );
                    $st->execute([$examenId, $nombre]);
                }
                if ((int)$st->fetchColumn() >= $limite) {
                    return ['status' => 'error',
                        'message' => 'Ya presentaste este examen. Si necesitas otro intento, solicítalo a AVBA.'];
                }
            } catch (\Throwable $e) { /* si no se puede contar, se deja pasar */ }
        }

        $preguntas = $this->preguntasParaIntento($examenId, (int)$ex['num_preguntas']);
        if (!$preguntas) {
            return ['status' => 'error', 'message' => 'Este examen todavía no tiene preguntas cargadas.'];
        }

        $token = bin2hex(random_bytes(16));
        try {
            $this->pdo->prepare(
                "INSERT INTO examen_intentos
                   (examen_id, sesion_acceso_id, token, nombre_completo, curp, puesto,
                    empresa_nombre, correo, telefono, estado, total_preguntas, ip)
                 VALUES (?,?,?,?,?,?,?,?,?, 'EN_CURSO', ?, ?)"
            )->execute([
                $examenId, $sesionId ?: null, $token, mb_substr($nombre, 0, 300),
                $curp ?: null, $puesto ?: null, mb_substr($empresa, 0, 300) ?: null,
                $correo ?: null, $telefono ?: null, count($preguntas), mb_substr($ip, 0, 45) ?: null,
            ]);
        } catch (\Throwable $e) {
            error_log('[Examenes] iniciar: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'No se pudo abrir el examen. Intenta de nuevo.'];
        }

        return [
            'status'    => 'success',
            'token'     => $token,
            'examen'    => [
                'nombre'  => $ex['nombre'],
                'minutos' => (int)$ex['minutos'],
                'minima'  => (float)$ex['calificacion_minima'],
                'total'   => count($preguntas),
            ],
            'preguntas' => $preguntas,
        ];
    }

    /**
     * Elige las preguntas del intento y las baraja.
     *
     * Las opciones también se barajan: con un orden fijo, quien ya presentó el
     * examen puede pasarle a otro la secuencia de letras y acabamos midiendo
     * memoria en vez de conocimiento.
     *
     * Nunca se incluye cuál es la correcta. Ese dato no sale del servidor.
     */
    private function preguntasParaIntento(int $examenId, int $cuantas): array {
        try {
            $st = $this->pdo->prepare(
                "SELECT id, texto FROM examen_preguntas
                  WHERE examen_id = ? AND activa = 1 ORDER BY orden, id"
            );
            $st->execute([$examenId]);
            $todas = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) { return []; }
        if (!$todas) return [];

        shuffle($todas);
        if ($cuantas > 0 && count($todas) > $cuantas) $todas = array_slice($todas, 0, $cuantas);

        $out = [];
        foreach ($todas as $q) {
            try {
                $so = $this->pdo->prepare(
                    "SELECT id, texto FROM examen_opciones WHERE pregunta_id = ? ORDER BY orden, id"
                );
                $so->execute([(int)$q['id']]);
                $ops = $so->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (\Throwable $e) { continue; }
            if (count($ops) < 2) continue;
            shuffle($ops);
            $out[] = [
                'id'       => (int)$q['id'],
                'texto'    => $q['texto'],
                'opciones' => array_map(fn($o) => ['id' => (int)$o['id'], 'texto' => $o['texto']], $ops),
            ];
        }
        return $out;
    }

    /**
     * Recibe las respuestas, califica y cierra el intento.
     *
     * La calificación se calcula aquí comparando contra la base. Lo que manda
     * el navegador son ids de opción y nada más; si llegara la calificación
     * hecha, cualquiera se pondría un 100.
     */
    public function enviar(array $p): array {
        $token = trim((string)($p['token'] ?? ''));
        if ($token === '') return ['status' => 'error', 'message' => 'Falta el identificador del examen.'];

        try {
            $st = $this->pdo->prepare("SELECT * FROM examen_intentos WHERE token = ?");
            $st->execute([$token]);
            $intento = $st->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('[Examenes] enviar: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'No se pudo registrar el examen.'];
        }
        if (!$intento) return ['status' => 'error', 'message' => 'Examen no encontrado.'];
        if ($intento['estado'] !== 'EN_CURSO') {
            return ['status' => 'error', 'message' => 'Este examen ya fue entregado.'];
        }

        $ex = $this->examen((int)$intento['examen_id']);
        if (!$ex) return ['status' => 'error', 'message' => 'Examen no encontrado.'];

        // Fuera de tiempo: se entrega igual, con lo que haya contestado. Anular
        // el intento castigaría a quien se quedó sin internet un minuto.
        $fueraDeTiempo = false;
        if ((int)$ex['minutos'] > 0) {
            $transcurrido = time() - strtotime((string)$intento['iniciado_at']);
            // Se conceden dos minutos de gracia por el envío y la red.
            if ($transcurrido > ((int)$ex['minutos'] + 2) * 60) $fueraDeTiempo = true;
        }

        $respuestas = (array)($p['respuestas'] ?? []);
        $intentoId  = (int)$intento['id'];
        $aciertos   = 0;

        try {
            $this->pdo->beginTransaction();
            $ins = $this->pdo->prepare(
                "INSERT INTO examen_respuestas (intento_id, pregunta_id, opcion_id, correcta)
                 VALUES (?,?,?,?)
                 ON DUPLICATE KEY UPDATE opcion_id = VALUES(opcion_id), correcta = VALUES(correcta)"
            );
            $chk = $this->pdo->prepare(
                "SELECT o.correcta FROM examen_opciones o
                   JOIN examen_preguntas q ON q.id = o.pregunta_id
                  WHERE o.id = ? AND o.pregunta_id = ? AND q.examen_id = ?"
            );

            foreach ($respuestas as $r) {
                $preguntaId = (int)($r['pregunta_id'] ?? 0);
                $opcionId   = (int)($r['opcion_id'] ?? 0);
                if ($preguntaId <= 0) continue;

                $correcta = 0;
                if ($opcionId > 0) {
                    // La opción tiene que pertenecer a la pregunta y la pregunta
                    // al examen: sin esta comprobación se podría mandar el id de
                    // una opción correcta de otra pregunta.
                    $chk->execute([$opcionId, $preguntaId, (int)$intento['examen_id']]);
                    $v = $chk->fetchColumn();
                    if ($v === false) continue;
                    $correcta = (int)$v === 1 ? 1 : 0;
                }
                $ins->execute([$intentoId, $preguntaId, $opcionId ?: null, $correcta]);
                $aciertos += $correcta;
            }

            $total = max(1, (int)$intento['total_preguntas']);
            $calif = round($aciertos * 100 / $total, 2);
            $aprobado = $calif >= (float)$ex['calificacion_minima'] ? 1 : 0;

            $this->pdo->prepare(
                "UPDATE examen_intentos
                    SET estado = 'TERMINADO', aciertos = ?, calificacion = ?, aprobado = ?,
                        terminado_at = NOW()
                  WHERE id = ?"
            )->execute([$aciertos, $calif, $aprobado, $intentoId]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            error_log('[Examenes] calificar: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'No se pudo calificar el examen.'];
        }

        // Quien aprueba entra a la cola de Calidad como candidato. No queda
        // certificado: queda en la fila para que Calidad decida.
        $enCola = false;
        if ($aprobado) $enCola = $this->pasarACalidad($intentoId);

        return [
            'status'        => 'success',
            'aciertos'      => $aciertos,
            'total'         => (int)$intento['total_preguntas'],
            'calificacion'  => $calif,
            'minima'        => (float)$ex['calificacion_minima'],
            'aprobado'      => (bool)$aprobado,
            'fuera_tiempo'  => $fueraDeTiempo,
            'en_cola'       => $enCola,
        ];
    }

    /**
     * Da de alta al candidato aprobado en la cola de Calidad.
     *
     * Se inserta con estatus PENDIENTE y usuario_registro 'EXAMEN', que es como
     * queda claro de dónde salió. Si algo falla aquí no se pierde el examen: la
     * calificación ya quedó guardada y el alta se puede rehacer a mano.
     */
    private function pasarACalidad(int $intentoId): bool {
        try {
            $st = $this->pdo->prepare(
                "SELECT i.*, e.curso_id, e.nombre AS examen_nombre
                   FROM examen_intentos i JOIN examenes e ON e.id = i.examen_id
                  WHERE i.id = ?"
            );
            $st->execute([$intentoId]);
            $d = $st->fetch(PDO::FETCH_ASSOC);
            if (!$d || !empty($d['participante_id'])) return false;

            $empresa = trim((string)($d['empresa_nombre'] ?? ''));
            $control = $empresa !== ''
                ? generarControl($this->pdo, $empresa)
                : controlSinCliente($this->pdo);

            $this->pdo->prepare(
                "INSERT INTO participantes_cursos
                   (nombre_completo, curp, puesto, curso_id, fecha_curso, control,
                    empresa_nombre, correo, estatus, usuario_registro)
                 VALUES (?,?,?,?,CURDATE(),?,?,?, 'PENDIENTE', 'EXAMEN')"
            )->execute([
                $d['nombre_completo'], $d['curp'] ?: null, $d['puesto'] ?: null,
                $d['curso_id'] ? (int)$d['curso_id'] : null, $control,
                $empresa ?: null, $d['correo'] ?: null,
            ]);
            $participanteId = (int)$this->pdo->lastInsertId();

            $this->pdo->prepare("UPDATE examen_intentos SET participante_id = ? WHERE id = ?")
                      ->execute([$participanteId, $intentoId]);

            if (!empty($d['sesion_acceso_id'])) {
                try {
                    $this->pdo->prepare(
                        "INSERT IGNORE INTO sesion_acceso_participantes (sesion_acceso_id, participante_id)
                         VALUES (?,?)"
                    )->execute([(int)$d['sesion_acceso_id'], $participanteId]);
                } catch (\Throwable $e) { /* la liga es informativa */ }
            }
            return true;
        } catch (\Throwable $e) {
            error_log('[Examenes] pasarACalidad: ' . $e->getMessage());
            return false;
        }
    }

    // ══════════════════════════════════════════════════════════
    //  LADO DE AVBA — LOS RESULTADOS
    // ══════════════════════════════════════════════════════════

    /** Los intentos presentados, con filtros. */
    public function resultados(array $f = []): array {
        $where  = ["i.estado = 'TERMINADO'"];
        $params = [];

        $busca = trim((string)($f['q'] ?? ''));
        if ($busca !== '') {
            $where[] = '(i.nombre_completo LIKE ? OR i.curp LIKE ? OR i.empresa_nombre LIKE ?)';
            $like = '%' . $busca . '%';
            array_push($params, $like, $like, $like);
        }
        $res = strtolower(trim((string)($f['resultado'] ?? '')));
        if ($res === 'aprobados')  $where[] = 'i.aprobado = 1';
        if ($res === 'reprobados') $where[] = 'i.aprobado = 0';

        $examenId = (int)($f['examen_id'] ?? 0);
        if ($examenId > 0) { $where[] = 'i.examen_id = ?'; $params[] = $examenId; }

        $sql = "SELECT i.id, i.nombre_completo, i.curp, i.puesto, i.empresa_nombre,
                       i.correo, i.telefono, i.aciertos, i.total_preguntas, i.calificacion,
                       i.aprobado, i.iniciado_at, i.terminado_at, i.participante_id,
                       e.nombre AS examen, e.calificacion_minima
                  FROM examen_intentos i
                  JOIN examenes e ON e.id = i.examen_id
                 WHERE " . implode(' AND ', $where) . "
                 ORDER BY i.terminado_at DESC
                 LIMIT 500";
        try {
            $st = $this->pdo->prepare($sql);
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('[Examenes] resultados: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'No se pudieron leer los resultados.'];
        }
        return ['status' => 'success', 'resultados' => $rows];
    }

    /** El detalle de un intento: qué contestó y qué era lo correcto. */
    public function detalle(int $intentoId): array {
        if ($intentoId <= 0) return ['status' => 'error', 'message' => 'Intento no válido.'];
        try {
            $st = $this->pdo->prepare(
                "SELECT i.*, e.nombre AS examen, e.calificacion_minima
                   FROM examen_intentos i JOIN examenes e ON e.id = i.examen_id
                  WHERE i.id = ?"
            );
            $st->execute([$intentoId]);
            $intento = $st->fetch(PDO::FETCH_ASSOC);
            if (!$intento) return ['status' => 'error', 'message' => 'Intento no encontrado.'];

            $sr = $this->pdo->prepare(
                "SELECT r.pregunta_id, r.opcion_id, r.correcta,
                        q.texto AS pregunta, q.referencia,
                        (SELECT texto FROM examen_opciones WHERE id = r.opcion_id) AS respondio,
                        (SELECT texto FROM examen_opciones
                          WHERE pregunta_id = r.pregunta_id AND correcta = 1 LIMIT 1) AS correcta_texto
                   FROM examen_respuestas r
                   JOIN examen_preguntas q ON q.id = r.pregunta_id
                  WHERE r.intento_id = ?
                  ORDER BY r.id"
            );
            $sr->execute([$intentoId]);
            $intento['respuestas'] = $sr->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('[Examenes] detalle: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'No se pudo leer el intento.'];
        }
        return ['status' => 'success', 'intento' => $intento];
    }

    // ══════════════════════════════════════════════════════════
    //  AUXILIARES
    // ══════════════════════════════════════════════════════════

    private function examen(int $id): ?array {
        try {
            $st = $this->pdo->prepare("SELECT * FROM examenes WHERE id = ? AND activo = 1");
            $st->execute([$id]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (\Throwable $e) { return null; }
    }
}
