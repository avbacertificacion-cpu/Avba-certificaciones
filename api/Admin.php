<?php
/**
 * AVBA Certificaciones — Módulo Admin
 * Gestión de tipos de equipo, secciones y checklist items.
 * Accesible por ADMIN y CALIDAD.
 */

class Admin {
    private PDO $pdo;

    /**
     * Tipos de maquinaria que deben existir con su plantilla de dictamen ya
     * asignada. Se dan de alta una sola vez cada uno; después Calidad los
     * renombra, les cambia la plantilla o los elimina y no vuelven a aparecer.
     *
     * Las plantillas ya existían en el repositorio; lo que faltaba era que el
     * tipo estuviera dado de alta y apuntando a la suya.
     */
    private const TIPOS_BASE = [
        'montacargas' => ['MONTACARGAS',  'dictamen_montacargas.html'],
        'grua_torre'  => ['GRÚA TORRE',   'dictamen_grua_torre.html'],
        'grua_oruga'  => ['GRÚA ESTRUCTURAL SOBRE ORUGAS', 'dictamen_grua_oruga.html'],
        'grua_portico' => ['GRÚA PÓRTICO', 'dictamen_grua_portico.html'],
        'canastilla'   => ['CANASTILLA ESTRUCTURAL', 'dictamen_canastilla.html'],
        'canastilla_sp' => ['CANASTILLA ESTRUCTURAL SIN PUERTA', 'dictamen_canastilla_sin_puerta.html'],
        'oreja_carga'   => ['OREJA DE CARGA', 'dictamen_oreja_carga.html'],
        'grua_camion'   => ['GRÚA HIDRÁULICA SOBRE CAMIÓN', 'dictamen_preview.html'],
    ];

    /**
     * Secciones y puntos de revisión con los que nace un tipo.
     *
     * Un tipo sin checklist no sirve para nada: el inspector no tiene qué
     * capturar y no hay reporte de inspección que emitir. Por eso el alta
     * siembra también su lista, no sólo el nombre y la plantilla.
     *
     * Es un punto de partida: Calidad lo edita desde el panel como cualquier
     * otro, y como la siembra se marca una sola vez, lo que ajusten no se
     * repone.
     */
    private const CHECKLIST_BASE = [
        'grua_oruga' => [
            'secciones' => [
                ['A', 'Estructura y tren de rodaje'],
                ['B', 'Mecanismos de izaje y giro'],
                ['C', 'Cables, poleas y accesorios de carga'],
                ['D', 'Cabina, controles e instrumentos'],
                ['E', 'Sistemas de seguridad'],
                ['F', 'Motor y sistemas auxiliares'],
                ['G', 'Documentación'],
            ],
            'items' => [
                ['A', 'A-01', 'Secciones de pluma reticulada: celosía, cordones y diagonales sin deformación ni fisuras'],
                ['A', 'A-02', 'Plumín (jib) y sus tirantes: estado, pasadores y seguros'],
                ['A', 'A-03', 'Pasadores y seguros de unión entre secciones'],
                ['A', 'A-04', 'Cadenas de oruga: tensado, eslabones, zapatas y pernos'],
                ['A', 'A-05', 'Rodillos superiores e inferiores, rueda guía y rueda motriz'],
                ['A', 'A-06', 'Bastidor, carro inferior y vigas laterales sin fisuras ni corrosión'],
                ['A', 'A-07', 'Contrapeso: cantidad, sujeción y correspondencia con la tabla de capacidades'],
                ['B', 'B-01', 'Corona de giro: juego, pernos de anclaje y engrasado'],
                ['B', 'B-02', 'Piñón y reductor de giro: ruido, fugas y nivel de aceite'],
                ['B', 'B-03', 'Tambores de izaje principal y auxiliar: ranurado y anclaje del cable'],
                ['B', 'B-04', 'Frenos de izaje, giro y traslación: retención y ajuste'],
                ['B', 'B-05', 'Mecanismo de abatimiento de pluma y su freno'],
                ['B', 'B-06', 'Sistema hidráulico: mangueras, cilindros, fugas y presión'],
                ['C', 'C-01', 'Cable de izaje: hilos rotos, aplastamiento, corrosión y lubricación'],
                ['C', 'C-02', 'Cable de abatimiento y tirantes: estado y terminaciones'],
                ['C', 'C-03', 'Poleas: gargantas, rodamientos y guardacables'],
                ['C', 'C-04', 'Gancho principal: apertura, torsión, desgaste y seguro'],
                ['C', 'C-05', 'Gancho auxiliar y bola de contrapeso'],
                ['C', 'C-06', 'Grilletes, eslingas y accesorios de izaje del equipo'],
                ['D', 'D-01', 'Acceso, asiento, cinturón y visibilidad desde la cabina'],
                ['D', 'D-02', 'Palancas, pedales y mandos: identificación y retorno a neutral'],
                ['D', 'D-03', 'Instrumentos: indicadores de ángulo, radio, longitud y carga'],
                ['D', 'D-04', 'Nivel de burbuja o indicador de nivelación'],
                ['D', 'D-05', 'Cristales, limpiaparabrisas, espejos y cámaras'],
                ['E', 'E-01', 'Indicador y limitador de momento de carga (LMI): funcionamiento y calibración'],
                ['E', 'E-02', 'Limitador de fin de carrera de izaje (anti two-block)'],
                ['E', 'E-03', 'Paro de emergencia y bloqueo de mandos'],
                ['E', 'E-04', 'Alarma de traslación, claxon y señalización acústica'],
                ['E', 'E-05', 'Extintor vigente, botiquín y punto de anclaje para arnés'],
                ['E', 'E-06', 'Tabla de capacidades legible y a bordo del equipo'],
                ['E', 'E-07', 'Calcomanías de advertencia y señalización de seguridad'],
                ['F', 'F-01', 'Motor: niveles, fugas, humos y ruidos anormales'],
                ['F', 'F-02', 'Sistema eléctrico: batería, cableado y protecciones'],
                ['F', 'F-03', 'Iluminación de trabajo y luces de posición'],
                ['F', 'F-04', 'Sistema de combustible y filtros'],
                ['G', 'G-01', 'Manual del operador y de mantenimiento a bordo'],
                ['G', 'G-02', 'Bitácora de mantenimiento preventivo al día'],
                ['G', 'G-03', 'Certificados vigentes de cables, ganchos y accesorios'],
                ['G', 'G-04', 'Constancia de habilidades del operador'],
            ],
            // Lo que ampara la inspección y lo que se consulta de referencia.
            // Las tres primeras son el alcance acreditado ante la ema; las NOM
            // son obligación legal en México y se citan como tal.
            'normas' => [
                ['NMX-GR-9927-1-IMNC-2019', 'acreditada'],
                ['NMX-GR-23814-IMNC-2011',  'acreditada'],
                ['NMX-EC-17020-IMNC-2014',  'acreditada'],
                ['NOM-004-STPS-1999',       'acreditada'],
                ['NOM-006-STPS-2014',       'acreditada'],
                ['ASME B30.5',              'referencia'],
                ['ASME B30.10',             'referencia'],
                ['ASME B30.26',             'referencia'],
            ],
        ],

        /* Grúa pórtico: puente sobre dos patas que rueda por carriles, con un
           carro que recorre el puente y un polipasto colgado de él. No tiene
           pluma, ni radio, ni estabilizadores; lo que se revisa son la
           estructura del puente, los caminos de rodadura y los tres
           movimientos (traslación del pórtico, traslación del carro e izaje),
           cada uno con su freno y su fin de carrera. */
        'grua_portico' => [
            'secciones' => [
                ['A', 'Estructura del puente y patas'],
                ['B', 'Carriles, testeros y traslación del pórtico'],
                ['C', 'Carro y traslación transversal'],
                ['D', 'Polipasto, cable y gancho'],
                ['E', 'Sistema eléctrico y mandos'],
                ['F', 'Dispositivos de seguridad'],
                ['G', 'Documentación'],
            ],
            'items' => [
                ['A', 'A-01', 'Viga principal: fisuras, abolladuras, corrosión y deformación permanente'],
                ['A', 'A-02', 'Flecha del puente sin carga dentro de lo admisible'],
                ['A', 'A-03', 'Uniones soldadas y empalmes: sin fisuras ni corrosión'],
                ['A', 'A-04', 'Patas o columnas: verticalidad, anclajes y arriostramiento'],
                ['A', 'A-05', 'Tornillería estructural: presencia, apriete y estado'],
                ['A', 'A-06', 'Pintura y protección anticorrosiva'],
                ['A', 'A-07', 'Placa de capacidad legible y a la vista desde el piso'],
                ['B', 'B-01', 'Carriles: alineación, nivelación, desgaste y fijación a la base'],
                ['B', 'B-02', 'Juntas de carril y holgura entre tramos'],
                ['B', 'B-03', 'Ruedas de traslación: desgaste, pestañas y rodamientos'],
                ['B', 'B-04', 'Testeros: estado estructural y paralelismo'],
                ['B', 'B-05', 'Motorreductores de traslación: ruido, fugas y sujeción'],
                ['B', 'B-06', 'Freno de traslación del pórtico: retención y ajuste'],
                ['B', 'B-07', 'Topes de fin de recorrido y amortiguadores en ambos extremos'],
                ['B', 'B-08', 'Mordazas o anclajes contra deslizamiento por viento, si aplica'],
                ['C', 'C-01', 'Carro: bastidor, ruedas y guías sobre la viga'],
                ['C', 'C-02', 'Motorreductor y freno de traslación del carro'],
                ['C', 'C-03', 'Topes de fin de recorrido del carro en ambos extremos'],
                ['C', 'C-04', 'Alimentación del carro: festón, carril conductor o carrete'],
                ['D', 'D-01', 'Polipasto: carcasa, sujeción y ausencia de fugas'],
                ['D', 'D-02', 'Cable o cadena de izaje: hilos rotos, desgaste, corrosión y lubricación'],
                ['D', 'D-03', 'Enrollado en el tambor: ranurado, capas y anclaje del extremo'],
                ['D', 'D-04', 'Poleas: gargantas, rodamientos y guardacables'],
                ['D', 'D-05', 'Gancho: apertura, torsión, desgaste y seguro funcional'],
                ['D', 'D-06', 'Freno de izaje: retención de la carga sin deslizamiento'],
                ['D', 'D-07', 'Freno de emergencia o segundo freno de izaje, si aplica'],
                ['E', 'E-01', 'Tablero eléctrico: protecciones, identificación y cierre'],
                ['E', 'E-02', 'Puesta a tierra de estructura, carriles y equipos'],
                ['E', 'E-03', 'Cableado y canalizaciones: estado y sujeción'],
                ['E', 'E-04', 'Botonera o control remoto: identificación y retorno a cero'],
                ['E', 'E-05', 'Paro de emergencia accesible y funcional'],
                ['E', 'E-06', 'Interruptor principal con bloqueo para mantenimiento'],
                ['F', 'F-01', 'Limitador de carga: funcionamiento y ajuste'],
                ['F', 'F-02', 'Fin de carrera superior e inferior de izaje'],
                ['F', 'F-03', 'Fines de carrera de traslación del puente y del carro'],
                ['F', 'F-04', 'Alarma acústica de traslación y señalización luminosa'],
                ['F', 'F-05', 'Protecciones de partes móviles: engranes, ejes y poleas'],
                ['F', 'F-06', 'Accesos, escaleras, pasillos y barandales'],
                ['F', 'F-07', 'Señalización de seguridad y delimitación del área de maniobra'],
                ['G', 'G-01', 'Manual del operador y de mantenimiento disponible'],
                ['G', 'G-02', 'Bitácora de mantenimiento preventivo al día'],
                ['G', 'G-03', 'Certificados vigentes de cable, gancho y accesorios de izaje'],
                ['G', 'G-04', 'Constancia de habilidades del operador'],
                ['G', 'G-05', 'Registro de la última prueba de carga'],
            ],
            // El pórtico ampara sólo la 23814 (competencia del inspector) y la
            // 17020 (la unidad); lo demás se cita como referencia.
            'normas' => [
                ['NMX-GR-23814-IMNC-2011',  'acreditada'],
                ['NMX-EC-17020-IMNC-2014',  'acreditada'],
                ['NMX-GR-9927-1-IMNC-2019', 'referencia'],
                ['NOM-004-STPS-1999',       'referencia'],
                ['NOM-006-STPS-2014',       'referencia'],
                // B30.2 es LA norma de puentes y pórticos; B30.16 cubre el
                // polipasto y B30.26 los accesorios con los que se cuelga.
                ['ASME B30.2',              'referencia'],
                ['ASME B30.16',             'referencia'],
                ['CMAA 74',                 'referencia'],
            ],
        ],

        /* Canastilla estructural: la plataforma que se cuelga del gancho para
           subir personal. No es un equipo que opere por sí mismo —no tiene
           motor, mandos ni movimientos propios—, así que lo que se revisa es la
           estructura, de qué cuelga, y lo que impide que alguien se caiga.

           El izaje de personal es una maniobra excepcional: sólo procede cuando
           no hay un medio de acceso más seguro. Por eso la lista carga hacia la
           protección contra caídas y hacia la prueba de carga, que es lo que
           acredita que la estructura aguanta. */
        'canastilla' => [
            'secciones' => [
                ['A', 'Estructura y piso'],
                ['B', 'Barandal, rodapié y acceso'],
                ['C', 'Puntos de suspensión y aparejo'],
                ['D', 'Protección contra caídas'],
                ['E', 'Señalización y placa de capacidad'],
                ['F', 'Prueba de carga'],
                ['G', 'Documentación'],
            ],
            'items' => [
                ['A', 'A-01', 'Bastidor y largueros: fisuras, abolladuras o deformación permanente'],
                ['A', 'A-02', 'Soldaduras: sin fisuras, poros ni socavación visible'],
                ['A', 'A-03', 'Corrosión: sin pérdida de sección en elementos portantes'],
                ['A', 'A-04', 'Piso antiderrapante completo, sin perforaciones ni zonas flojas'],
                ['A', 'A-05', 'Drenaje del piso libre, para que no acumule agua'],
                ['A', 'A-06', 'Tornillería estructural: presencia, apriete y estado'],
                ['A', 'A-07', 'Sin modificaciones ni reparaciones no autorizadas por el fabricante'],
                ['B', 'B-01', 'Barandal superior a la altura de diseño, continuo y sin deformación'],
                ['B', 'B-02', 'Barandal intermedio presente en todo el perímetro'],
                ['B', 'B-03', 'Rodapié en todo el perímetro, a la altura de diseño'],
                ['B', 'B-04', 'Malla o cerramiento lateral íntegro, si el diseño lo incluye'],
                ['B', 'B-05', 'Puerta de acceso: abre hacia adentro y no deja hueco al cerrar'],
                ['B', 'B-06', 'Cierre positivo de la puerta: no se abre por sí solo ni por golpe'],
                ['B', 'B-07', 'Sin bordes cortantes ni rebabas en zonas de contacto'],
                ['C', 'C-01', 'Orejas o puntos de izaje: sin deformación, elongación ni fisuras'],
                ['C', 'C-02', 'Desgaste del ojo de izaje dentro de lo admisible'],
                ['C', 'C-03', 'Grilletes del aparejo: tipo, capacidad y seguro de pasador'],
                ['C', 'C-04', 'Eslingas o cables del aparejo: estado, marcado y vigencia'],
                ['C', 'C-05', 'Anillo maestro o argolla de reunión: estado y capacidad'],
                ['C', 'C-06', 'Ángulo de las ramas dentro de lo previsto por el fabricante'],
                ['C', 'C-07', 'Dispositivo antigiro o de estabilización, si el diseño lo incluye'],
                ['D', 'D-01', 'Puntos de anclaje para arnés, independientes de la estructura de izaje'],
                ['D', 'D-02', 'Cantidad de anclajes suficiente para el número máximo de ocupantes'],
                ['D', 'D-03', 'Anclajes identificados y sin deformación'],
                ['D', 'D-04', 'Línea de vida o conector secundario al gancho, si aplica'],
                ['E', 'E-01', 'Placa con capacidad nominal legible y fijada a la canastilla'],
                ['E', 'E-02', 'Tara (peso propio) indicada en la placa'],
                ['E', 'E-03', 'Número máximo de ocupantes indicado'],
                ['E', 'E-04', 'Número de serie o identificación visible'],
                ['E', 'E-05', 'Leyenda de uso exclusivo para izaje de personal'],
                ['E', 'E-06', 'Señalización de uso obligatorio de arnés'],
                ['F', 'F-01', 'Prueba de carga estática al 125% de la capacidad nominal'],
                ['F', 'F-02', 'Carga distribuida sobre el piso durante la prueba'],
                ['F', 'F-03', 'Sin deformación permanente al retirar la carga'],
                ['F', 'F-04', 'Sin fisuras ni desprendimientos tras la prueba'],
                ['F', 'F-05', 'Puntos de izaje y aparejo sin elongación tras la prueba'],
                ['G', 'G-01', 'Memoria de cálculo o certificado estructural del fabricante'],
                ['G', 'G-02', 'Manual de uso y de montaje del aparejo'],
                ['G', 'G-03', 'Registro de la última prueba de carga'],
                ['G', 'G-04', 'Certificados vigentes de grilletes, eslingas y accesorios del aparejo'],
                ['G', 'G-05', 'Procedimiento de izaje de personal y plan de rescate'],
            ],
            // La 23814 y la 17020 son el alcance acreditado; el resto se cita
            // como criterio técnico. B30.23 es la de izaje de personal y B30.20
            // la de dispositivos que cuelgan del gancho, que es lo que esto es.
            'normas' => [
                ['NMX-GR-23814-IMNC-2011',  'acreditada'],
                ['NMX-EC-17020-IMNC-2014',  'acreditada'],
                ['ASME B30.23',             'referencia'],
                ['ASME B30.20',             'referencia'],
                ['ASME B30.26',             'referencia'],
                ['ASME B30.9',              'referencia'],
                ['NOM-009-STPS-2011',       'referencia'],
                ['NOM-004-STPS-1999',       'referencia'],
            ],
        ],

        /* Canastilla sin puerta: se ingresa por la parte superior, pasando por
           encima del barandal. No hay hueco en el cerramiento ni puerta que
           cierre, así que el perímetro se revisa continuo y cerrado, y lo que
           importa del ingreso es el canto por donde se pasa y de dónde se
           agarra uno al hacerlo.

           Tampoco lleva tornillería estructural: el cuerpo es soldado y lo
           desmontable son los pernos de pivote con sus seguros, que es donde
           cuelga la canastilla entera.

           Sin puerta no hay barrera física: la protección del ocupante depende
           del arnés, y por eso el anclaje tiene que permitir conectarse ANTES
           de entrar y soltarse DESPUÉS de salir. */
        'canastilla_sp' => [
            'secciones' => [
                ['A', 'Estructura y piso'],
                ['B', 'Barandal, cerramiento e ingreso superior'],
                ['C', 'Izaje, bastidor de suspensión, pernos y seguros'],
                ['D', 'Protección contra caídas'],
                ['E', 'Señalización y placa de capacidad'],
                ['F', 'Prueba de carga'],
                ['G', 'Documentación'],
            ],
            'items' => [
                ['A', 'A-01', 'Bastidor y largueros: fisuras, abolladuras o deformación permanente'],
                ['A', 'A-02', 'Soldaduras: sin fisuras, poros ni socavación visible'],
                ['A', 'A-03', 'Corrosión: sin pérdida de sección en elementos portantes'],
                ['A', 'A-04', 'Piso de rejilla: completo, sin zonas flojas, perforaciones ni barras faltantes'],
                ['A', 'A-05', 'Fijación del piso al bastidor en todo su perímetro'],
                ['A', 'A-06', 'Drenaje libre, para que el piso no acumule agua'],
                ['A', 'A-07', 'Sin modificaciones ni reparaciones no autorizadas por el fabricante'],
                ['B', 'B-01', 'Barandal superior continuo y cerrado en todo el perímetro, a la altura de diseño'],
                ['B', 'B-02', 'Barandal intermedio presente en todo el perímetro'],
                ['B', 'B-03', 'Rodapié continuo, sin interrupciones'],
                ['B', 'B-04', 'Cerramiento lateral —malla, rejilla o lámina— íntegro y bien sujeto'],
                ['B', 'B-05', 'Canto superior por donde se ingresa: sin bordes cortantes ni rebabas'],
                ['B', 'B-06', 'Asidero firme del que sostenerse al pasar por encima del barandal'],
                ['B', 'B-07', 'Peldaño o apoyo de ingreso, si el diseño lo incluye: sin deformación ni desgaste del antiderrapante'],
                ['B', 'B-08', 'Altura libre sobre el barandal suficiente para ingresar sin forzar la postura'],
                ['C', 'C-01', 'Oreja de izaje: ojo sin elongación, sin deformación ni fisuras'],
                ['C', 'C-02', 'Soldadura de la oreja al bastidor de suspensión'],
                ['C', 'C-03', 'Bastidor o yugo de suspensión: montantes, diagonales y sus soldaduras'],
                ['C', 'C-04', 'Pernos de pivote: diámetro correcto, sin desgaste, deformación ni ovalamiento'],
                ['C', 'C-05', 'Seguros de los pernos completos y en su lugar: chavetas, pernos de retención o tuerca con seguro'],
                ['C', 'C-06', 'Barrenos de los pivotes sin ovalamiento ni elongación'],
                ['C', 'C-07', 'Juego en los pivotes dentro de lo admisible'],
                ['C', 'C-08', 'Grillete de conexión al gancho: tipo, capacidad y seguro de pasador'],
                ['C', 'C-09', 'Dispositivo antigiro o de orientación, si el diseño lo incluye'],
                ['C', 'C-10', 'Sin carga, el conjunto cuelga a plomo y la canastilla queda nivelada'],
                ['D', 'D-01', 'Puntos de anclaje para arnés, independientes de la estructura de izaje'],
                ['D', 'D-02', 'Un anclaje por ocupante, al menos hasta el número máximo indicado en la placa'],
                ['D', 'D-03', 'Anclajes identificados, sin deformación y sin corrosión'],
                ['D', 'D-04', 'El anclaje se alcanza desde fuera: permite conectarse antes de entrar y soltarse después de salir'],
                ['D', 'D-05', 'Longitud del conector tal que el ocupante quede retenido dentro de la canastilla'],
                ['D', 'D-06', 'Línea de vida o conector secundario al gancho, si aplica'],
                ['E', 'E-01', 'Placa con capacidad nominal legible y fijada a la canastilla'],
                ['E', 'E-02', 'Tara (peso propio) indicada en la placa'],
                ['E', 'E-03', 'Número máximo de ocupantes indicado'],
                ['E', 'E-04', 'Número de serie o identificación visible'],
                ['E', 'E-05', 'Leyenda de uso exclusivo para izaje de personal'],
                ['E', 'E-06', 'Leyenda de uso permanente de arnés, visible desde fuera antes de ingresar'],
                ['F', 'F-01', 'Prueba de carga estática al 125% de la capacidad nominal'],
                ['F', 'F-02', 'Carga distribuida sobre el piso durante la prueba'],
                ['F', 'F-03', 'Sin deformación permanente al retirar la carga'],
                ['F', 'F-04', 'Sin fisuras ni desprendimientos tras la prueba'],
                ['F', 'F-05', 'Oreja, pernos y barrenos de pivote sin elongación ni juego nuevo tras la prueba'],
                ['G', 'G-01', 'Memoria de cálculo o certificado estructural del fabricante'],
                ['G', 'G-02', 'Manual de uso y de montaje de la suspensión'],
                ['G', 'G-03', 'Registro de la última prueba de carga'],
                ['G', 'G-04', 'Certificados vigentes del grillete y accesorios de la suspensión'],
                ['G', 'G-05', 'Procedimiento de izaje de personal y plan de rescate'],
            ],
            'normas' => [
                ['NMX-GR-23814-IMNC-2011',  'acreditada'],
                ['NMX-EC-17020-IMNC-2014',  'acreditada'],
                ['ASME B30.23',             'referencia'],
                ['ASME B30.20',             'referencia'],
                ['ASME B30.26',             'referencia'],
                ['ASME B30.9',              'referencia'],
                ['NOM-009-STPS-2011',       'referencia'],
                ['NOM-004-STPS-1999',       'referencia'],
            ],
        ],

        /* Oreja de carga: la placa con un ojo, soldada o atornillada a un equipo
           o a una estructura, de la que se iza con un grillete. No tiene partes
           móviles: lo que decide si sirve es que la placa no se haya deformado
           ni agrietado, que el ojo no se haya alargado y que la unión a la
           estructura aguante.

           Por eso la lista carga hacia lo que no se ve a simple vista —las
           fisuras en la soldadura y en el contorno del ojo, que se buscan con
           líquidos penetrantes— y hacia la dirección de la carga: una oreja
           diseñada para tirar en su plano falla con mucho menos si se le jala
           de lado.

           Las hay soldadas y atornilladas, así que la sección de la unión trae
           los puntos de ambas: en cada inspección se marcan como no aplicables
           los que no correspondan. */
        'oreja_carga' => [
            'secciones' => [
                ['A', 'Identificación y marcado'],
                ['B', 'Placa de la oreja'],
                ['C', 'Ojo de izaje'],
                ['D', 'Unión a la estructura: soldadura o tornillería'],
                ['E', 'Orientación y compatibilidad con el aparejo'],
                ['F', 'Prueba de carga'],
                ['G', 'Documentación'],
            ],
            'items' => [
                ['A', 'A-01', 'Capacidad de carga de trabajo marcada en la oreja, legible y permanente (estampado o placa)'],
                ['A', 'A-02', 'Número de serie o de identificación marcado y legible'],
                ['A', 'A-03', 'Espesor de placa, diámetro del ojo y material corresponden con el plano o la memoria de cálculo'],
                ['B', 'B-01', 'Sin fisuras, en especial en el contorno del ojo y en la transición hacia la base'],
                ['B', 'B-02', 'Sin deformación: doblez, torsión ni pandeo fuera del plano'],
                ['B', 'B-03', 'Sin pérdida de espesor por corrosión, picaduras o desgaste'],
                ['B', 'B-04', 'Sin muescas, golpes, cortes ni quemaduras de arco'],
                ['B', 'B-05', 'Sin barrenos, cortes, calentamientos ni reparaciones no autorizadas'],
                ['C', 'C-01', 'Ojo sin elongación ni ovalamiento: diámetro medido en dos direcciones'],
                ['C', 'C-02', 'Desgaste del ojo dentro de lo admisible por el plano o la memoria de cálculo'],
                ['C', 'C-03', 'Bordes del ojo sin rebabas, filos ni muescas que dañen el perno del grillete'],
                ['C', 'C-04', 'Holgura entre el ojo y el perno del grillete adecuada, sin juego excesivo'],
                ['C', 'C-05', 'Placas de refuerzo del ojo íntegras y bien unidas, si el diseño las incluye'],
                ['D', 'D-01', 'Soldadura completa en todo su perímetro, sin interrupciones'],
                ['D', 'D-02', 'Soldadura sin fisuras, poros, socavación ni falta de fusión visibles'],
                ['D', 'D-03', 'Líquidos penetrantes o partículas magnéticas en soldadura y contorno del ojo: sin indicaciones relevantes'],
                ['D', 'D-04', 'Tornillería de la base completa, del grado especificado, apretada y con tuerca de seguridad'],
                ['D', 'D-05', 'Barrenos de la base sin elongación ni fisuras; base asentada sin holgura'],
                ['D', 'D-06', 'Estructura donde va montada sin fisuras ni deformación alrededor de la unión'],
                ['E', 'E-01', 'Oreja alineada con la dirección de la carga: no trabaja con carga lateral fuera de su plano'],
                ['E', 'E-02', 'Ángulo de la eslinga dentro de lo previsto en el diseño'],
                ['E', 'E-03', 'Grillete compatible en tamaño y capacidad, sin rozar con la estructura'],
                ['E', 'E-04', 'Espacio libre suficiente para conectar y desconectar el grillete'],
                ['F', 'F-01', 'Prueba de carga estática con el factor especificado (125% de la capacidad nominal, salvo otra indicación del diseño)'],
                ['F', 'F-02', 'Carga aplicada en la dirección de diseño de la oreja'],
                ['F', 'F-03', 'Sin deformación permanente al retirar la carga'],
                ['F', 'F-04', 'Sin fisuras ni indicaciones en la revisión posterior a la prueba'],
                ['G', 'G-01', 'Plano o memoria de cálculo de la oreja: capacidad, material y espesor'],
                ['G', 'G-02', 'Certificado de calidad del material de la placa'],
                ['G', 'G-03', 'Procedimiento de soldadura y calificación del soldador, si es soldada'],
                ['G', 'G-04', 'Registro de la última prueba de carga y de las pruebas no destructivas'],
            ],
            // Las acreditadas, como en la canastilla: es otro dispositivo de
            // izaje que se inspecciona con el mismo alcance. De referencia,
            // B30.20 (lo que va bajo el gancho) y BTH-1 (con qué se diseña una
            // oreja), B30.26 por el grillete, y para la soldadura su código
            // y el método de líquidos penetrantes.
            'normas' => [
                ['NMX-GR-23814-IMNC-2011',  'acreditada'],
                ['NMX-EC-17020-IMNC-2014',  'acreditada'],
                ['ASME B30.20',             'referencia'],
                ['ASME BTH-1',              'referencia'],
                ['ASME B30.26',             'referencia'],
                ['AWS D1.1/D1.1M',          'referencia'],
                ['ASTM E165/E165M',         'referencia'],
                ['NOM-006-STPS-2014',       'referencia'],
            ],
        ],

        /* Grúa hidráulica montada sobre camión (boom truck): la pluma
           telescópica con su winche va sobre el bastidor de un camión, que
           además es quien la transporta y la sostiene.

           Por eso la lista revisa dos equipos en uno: la grúa —pluma,
           hidráulico, cable y gancho— y lo que la mantiene de pie, que son los
           estabilizadores y el chasis donde está anclada. La capacidad depende
           del radio, así que la tabla de cargas y los dispositivos que avisan
           al acercarse al límite (LMAP/LMI y anti-doble bloqueo) pesan tanto
           como la estructura.

           Es la plantilla de dictamen de grúa móvil: su prueba de carga se
           mide con peso, radio y longitud de pluma. */
        'grua_camion' => [
            'secciones' => [
                ['A', 'Documentación y placas'],
                ['B', 'Chasis, base y estabilizadores'],
                ['C', 'Pluma y estructura'],
                ['D', 'Sistema hidráulico'],
                ['E', 'Cable, poleas y gancho'],
                ['F', 'Controles y dispositivos de seguridad'],
                ['G', 'Prueba de carga'],
            ],
            'items' => [
                ['A', 'A-01', 'Manual de operación y mantenimiento a bordo de la unidad'],
                ['A', 'A-02', 'Tabla de capacidades completa y legible, correspondiente a la configuración'],
                ['A', 'A-03', 'Placa de datos del fabricante legible: modelo, número de serie y capacidad'],
                ['A', 'A-04', 'Diagrama de alcance y notas de operación legibles'],
                ['A', 'A-05', 'Bitácora de mantenimiento e inspecciones previas'],
                ['A', 'A-06', 'Registro de la última prueba de carga'],
                ['A', 'A-07', 'Certificados vigentes de los accesorios de izaje que acompañan al equipo'],
                ['B', 'B-01', 'Chasis y bastidor auxiliar: sin fisuras, deformación ni corrosión con pérdida de sección'],
                ['B', 'B-02', 'Tornillería de anclaje de la base al chasis: completa, del grado correcto y con apriete'],
                ['B', 'B-03', 'Corona de giro: juego dentro de lo admisible, tornillería completa y engrasada'],
                ['B', 'B-04', 'Reductor y freno de giro: sin fugas, retiene la carga'],
                ['B', 'B-05', 'Vigas y gatos de los estabilizadores: sin deformación, fugas ni desgaste'],
                ['B', 'B-06', 'Zapatas o bases de apoyo completas y en condición'],
                ['B', 'B-07', 'Pasadores y seguros de los estabilizadores en su lugar'],
                ['B', 'B-08', 'Indicador de nivel presente y legible'],
                ['B', 'B-09', 'Llantas, suspensión y frenos del camión en condición de servicio'],
                ['C', 'C-01', 'Secciones telescópicas: sin abolladuras, pandeo ni fisuras'],
                ['C', 'C-02', 'Almohadillas de deslizamiento y guías: desgaste dentro de lo admisible'],
                ['C', 'C-03', 'Pasadores y bujes del pie de pluma: sin desgaste ni ovalamiento'],
                ['C', 'C-04', 'Soldaduras estructurales sin fisuras visibles'],
                ['C', 'C-05', 'Cilindro de elevación: vástago sin rayado, anclajes íntegros y sin deriva'],
                ['C', 'C-06', 'Cilindro telescópico y sus cables o cadenas de extensión: tensión y estado'],
                ['C', 'C-07', 'Punta de pluma: poleas, guardacables y pasadores'],
                ['C', 'C-08', 'Plumín (jib): montaje, pasadores, seguros y sujeción cuando va estibado'],
                ['D', 'D-01', 'Bomba y toma de fuerza: sin ruido anormal ni fugas'],
                ['D', 'D-02', 'Mangueras y conexiones sin fugas, roce ni abrasión'],
                ['D', 'D-03', 'Nivel y condición del aceite hidráulico'],
                ['D', 'D-04', 'Filtros dentro de su periodo de servicio'],
                ['D', 'D-05', 'Presiones de trabajo conforme a la placa: winche, pluma y giro'],
                ['D', 'D-06', 'Válvulas de retención en los cilindros: la carga no baja sola'],
                ['D', 'D-07', 'Depósito, respiradero y enfriador en condición'],
                ['E', 'E-01', 'Cable de acero: hilos rotos, aplastamiento, corrosión y deformación dentro del criterio de retiro'],
                ['E', 'E-02', 'Anclaje del cable al tambor y mínimo tres vueltas muertas'],
                ['E', 'E-03', 'Enrollado parejo en el tambor, sin cruces ni montado de capas'],
                ['E', 'E-04', 'Winche: freno y trinquete retienen la carga; reductor sin fugas'],
                ['E', 'E-05', 'Poleas: canal sin desgaste, giro libre y guardacables en su lugar'],
                ['E', 'E-06', 'Gancho principal: apertura de garganta, torsión y desgaste dentro de lo admisible'],
                ['E', 'E-07', 'Seguro del gancho presente y funcional'],
                ['E', 'E-08', 'Motón o bola de contrapeso: sin fisuras, giro libre y capacidad marcada'],
                ['F', 'F-01', 'Puestos de mando: palancas identificadas, con retorno a neutro y sin juego excesivo'],
                ['F', 'F-02', 'Control remoto, si lo tiene: funcionamiento y paro de emergencia'],
                ['F', 'F-03', 'LMAP o indicador de momento de carga: enciende, se calibra y bloquea al llegar al límite'],
                ['F', 'F-04', 'Dispositivo anti-doble bloqueo funcional'],
                ['F', 'F-05', 'Indicadores de ángulo, longitud de pluma y radio legibles y correctos'],
                ['F', 'F-06', 'Paro de emergencia accesible y funcional'],
                ['F', 'F-07', 'Alarma audible de maniobra y señalización'],
                ['F', 'F-08', 'Calcomanías de seguridad completas y legibles, incluida la de riesgo de electrocución'],
                ['F', 'F-09', 'Guardas de partes móviles en su lugar'],
                ['F', 'F-10', 'Accesos, escalones y pasamanos antiderrapantes'],
                ['F', 'F-11', 'Extintor vigente a bordo'],
                ['G', 'G-01', 'Prueba sin carga: movimientos suaves en toda la trayectoria'],
                ['G', 'G-02', 'Prueba con carga conforme a la tabla de capacidades para el radio de prueba'],
                ['G', 'G-03', 'Sosteniendo la carga, el cilindro de elevación y el winche no derivan'],
                ['G', 'G-04', 'Estabilidad: sin levantamiento de estabilizadores ni asentamiento del terreno'],
                ['G', 'G-05', 'Frenos de giro y de winche retienen la carga'],
                ['G', 'G-06', 'Sin fugas, ruidos ni vibraciones anormales durante la prueba'],
                ['G', 'G-07', 'El LMAP responde y bloquea al acercarse al límite'],
            ],
            // Es grúa, así que ampara el alcance completo: 9927-1 y 23814 de
            // grúas, y la 17020 de la unidad. De referencia, la B30.5 que la
            // propia placa del equipo declara cumplir, y las del aparejo.
            'normas' => [
                ['NMX-GR-9927-1-IMNC-2019', 'acreditada'],
                ['NMX-GR-23814-IMNC-2011',  'acreditada'],
                ['NMX-EC-17020-IMNC-2014',  'acreditada'],
                ['ASME B30.5',              'referencia'],
                ['ASME B30.10',             'referencia'],
                ['ASME B30.9',              'referencia'],
                ['ASME B30.26',             'referencia'],
                ['NOM-004-STPS-1999',       'referencia'],
                ['NOM-006-STPS-2014',       'referencia'],
            ],
        ],
    ];

    /**
     * Alcance acreditado ante la ema — acreditación UVNMX 057, ref. 25UI5073.
     *
     * El anexo técnico acredita a AVBA como Unidad de Inspección Tipo A bajo la
     * NMX-EC-17020, y para la materia "Verificación de Grúas" lista como norma o
     * método la NMX-GR-9927-1 (inspecciones de grúas) y la NMX-GR-23814
     * (competencia de inspectores de grúas).
     *
     * La 17020 es la norma de la unidad, no del equipo: aplica a todo lo que se
     * emite. Las dos de grúas sólo donde el equipo es una grúa.
     */
    private const NORMA_UNIDAD = 'NMX-EC-17020-IMNC-2014';
    private const NORMAS_GRUA  = [
        'NMX-GR-9927-1-IMNC-2019',
        'NMX-GR-23814-IMNC-2011',
    ];
    /** Marcas de aplicación: cada paso se corre una sola vez por instalación. */
    private const SEED_NORMAS_EMA     = 'normas_ema_25ui5073';
    private const SEED_NORMAS_PORTICO = 'normas_portico_23814_17020';
    private const SEED_CATALOGO       = 'catalogo_normas_v1';
    private const SEED_CHECKLIST_CSP  = 'checklist_canastilla_sp_v2';
    private const SEED_CATALOGO_V2    = 'catalogo_normas_v2';

    /**
     * Topes por grupo. Son de espacio, no de criterio: con siete normas
     * acreditadas el bloque del certificado empuja el pie a una segunda página.
     * Verificado renderizando con mPDF.
     */
    private const MAX_ACREDITADAS = 6;
    private const MAX_REFERENCIA  = 8;

    /**
     * Catálogo con el que nace la instalación. Es el punto de partida de la
     * lista que Calidad marca por tipo de equipo; desde el panel agrega las que
     * falten y quita las que no use.
     */
    private const CATALOGO_NORMAS_BASE = [
        ['NMX-EC-17020-IMNC-2014',  'Unidad de inspección — norma bajo la que la ema acreditó a AVBA'],
        ['NMX-GR-23814-IMNC-2011',  'Grúas: requisitos de competencia para inspectores de grúas'],
        ['NMX-GR-9927-1-IMNC-2019', 'Grúas — Inspecciones — Parte 1: Generalidades'],
        ['NOM-004-STPS-1999',       'Sistemas de protección y dispositivos de seguridad en maquinaria'],
        ['NOM-006-STPS-2014',       'Manejo y almacenamiento de materiales'],
        ['NOM-009-STPS-2011',       'Trabajos en altura'],
        ['NOM-031-STPS-2011',       'Construcción — condiciones de seguridad y salud'],
        ['ASME B30.2',              'Puentes grúa y pórticos de viga simple o doble'],
        ['ASME B30.3',              'Grúas torre'],
        ['ASME B30.5',              'Grúas móviles y sobre orugas'],
        ['ASME B30.9',              'Eslingas'],
        ['ASME B30.10',             'Ganchos'],
        ['ASME B30.16',             'Polipastos suspendidos y estacionarios'],
        ['ASME B30.20',             'Dispositivos de sujeción bajo el gancho'],
        ['ASME B30.23',             'Sistemas de elevación de personal'],
        ['ASME B30.26',             'Accesorios de aparejo'],
        ['CMAA 70',                 'Puentes grúa viajeros de viga doble'],
        ['CMAA 74',                 'Puentes grúa viajeros de viga simple'],
        ['ANSI/ITSDF B56.1',        'Montacargas de contrapeso'],
        ['ANSI/ITSDF B56.6',        'Montacargas de terreno irregular y manipuladores telescópicos'],
        ['ANSI A92.22',             'Plataformas de elevación — prácticas seguras de uso'],
        ['ANSI A92.24',             'Plataformas de elevación — capacitación'],
    ];

    /**
     * Normas que se sumaron al catálogo después de la primera siembra, con las
     * orejas de carga. Van aparte porque la siembra original no se vuelve a
     * correr: reponer la lista completa devolvería las que Calidad ya quitó.
     */
    private const CATALOGO_NORMAS_V2 = [
        ['ASME BTH-1',      'Diseño de dispositivos de izaje bajo el gancho (orejas, vigas, yugos)'],
        ['AWS D1.1/D1.1M',  'Código de soldadura estructural — acero'],
        ['ASTM E165/E165M', 'Inspección por líquidos penetrantes'],
        ['ASTM E709',       'Inspección por partículas magnéticas'],
    ];

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->seedTiposMaquinaria();
        $this->sembrarNormasAcreditacion();
        $this->ajustarNormasPortico();
        $this->sembrarCatalogoNormas();
        $this->ampliarCatalogoNormas();
        $this->corregirChecklistCanastillaSinPuerta();
    }

    /**
     * Agrega al catálogo las normas de CATALOGO_NORMAS_V2, una sola vez. Las
     * que ya estén no se duplican; si entraron sin descripción —en una
     * instalación nueva las recoge la primera siembra porque ya las usa la
     * oreja de carga—, se les pone la suya.
     */
    private function ampliarCatalogoNormas(): void {
        try {
            $st = $this->pdo->prepare("SELECT 1 FROM maquinaria_tipos_seed WHERE clave = ?");
            $st->execute([self::SEED_CATALOGO_V2]);
            if ($st->fetch()) return;

            $ya = array_map(
                'strtoupper',
                $this->pdo->query("SELECT norma FROM normas_catalogo")->fetchAll(PDO::FETCH_COLUMN) ?: []
            );
            $ins = $this->pdo->prepare("INSERT INTO normas_catalogo (norma, descripcion) VALUES (?, ?)");
            $des = $this->pdo->prepare(
                "UPDATE normas_catalogo SET descripcion = ?
                 WHERE UPPER(norma) = ? AND (descripcion IS NULL OR descripcion = '')"
            );
            foreach (self::CATALOGO_NORMAS_V2 as [$norma, $desc]) {
                if (in_array(strtoupper($norma), $ya, true)) {
                    $des->execute([$desc, strtoupper($norma)]);
                    continue;
                }
                $ins->execute([$norma, $desc]);
            }

            $this->pdo->prepare("INSERT INTO maquinaria_tipos_seed (clave) VALUES (?)")
                ->execute([self::SEED_CATALOGO_V2]);
        } catch (\Throwable $e) {
            error_log('[Admin] ampliarCatalogoNormas: ' . $e->getMessage());
        }
    }

    /**
     * Rehace el checklist de la canastilla sin puerta.
     *
     * La primera versión se sembró con puntos que ese equipo no tiene: hueco de
     * acceso —se ingresa por encima del barandal, el perímetro va cerrado— y
     * tornillería estructural, cuando el cuerpo es soldado y lo desmontable son
     * los pernos de pivote con sus seguros.
     *
     * Sólo se rehace si la lista sigue siendo la que se sembró. La seña es el
     * punto del hueco de acceso, que es justamente lo que no existe; si ya no
     * está, alguien la editó y no se toca. Las normas no se tocan en ningún
     * caso: van en su propia tabla y pueden traer ajustes de Calidad.
     */
    private function corregirChecklistCanastillaSinPuerta(): void {
        try {
            $st = $this->pdo->prepare("SELECT 1 FROM maquinaria_tipos_seed WHERE clave = ?");
            $st->execute([self::SEED_CHECKLIST_CSP]);
            if ($st->fetch()) return;
        } catch (\Throwable $e) { return; }

        try {
            [$nombre] = self::TIPOS_BASE['canastilla_sp'];
            $t = $this->pdo->prepare(
                "SELECT id FROM maquinaria_tipos WHERE UPPER(TRIM(nombre)) = ? LIMIT 1"
            );
            $t->execute([$nombre]);
            $tipoId = (int)($t->fetchColumn() ?: 0);

            if ($tipoId) {
                $seña = $this->pdo->prepare(
                    "SELECT COUNT(*) FROM checklist_items
                     WHERE maquinaria_tipo_id = ? AND descripcion LIKE '%hueco de acceso%'"
                );
                $seña->execute([$tipoId]);

                if ((int)$seña->fetchColumn() > 0) {
                    $this->pdo->prepare("DELETE FROM checklist_items     WHERE maquinaria_tipo_id = ?")->execute([$tipoId]);
                    $this->pdo->prepare("DELETE FROM checklist_secciones WHERE maquinaria_tipo_id = ?")->execute([$tipoId]);
                    $this->sembrarSeccionesItems('canastilla_sp', $tipoId);
                } else {
                    error_log('[Admin] checklist canastilla sin puerta: ya no trae la lista sembrada, se respeta');
                }
            }

            $this->pdo->prepare("INSERT INTO maquinaria_tipos_seed (clave) VALUES (?)")
                ->execute([self::SEED_CHECKLIST_CSP]);
        } catch (\Throwable $e) {
            error_log('[Admin] corregirChecklistCanastillaSinPuerta: ' . $e->getMessage());
        }
    }

    /**
     * Pone las normas del alcance acreditado en los tipos que ya existían.
     *
     * La siembra de tipos sólo corre al dar de alta uno nuevo, así que los tipos
     * que ya están capturados nunca verían estas normas. Esto las agrega una
     * sola vez y de forma aditiva: no borra ninguna norma existente, y lo que
     * Calidad ajuste después no se repone.
     *
     * Si la norma ya está capturada como referencia, se promueve a acreditada en
     * lugar de duplicarla: es la misma norma, cambia el renglón donde se imprime.
     */
    private function sembrarNormasAcreditacion(): void {
        try {
            $st = $this->pdo->prepare("SELECT 1 FROM maquinaria_tipos_seed WHERE clave = ?");
            $st->execute([self::SEED_NORMAS_EMA]);
            if ($st->fetch()) return;
        } catch (\Throwable $e) {
            // La tabla de marcas la crea seedTiposMaquinaria; si no está, no hay
            // dónde registrar que esto ya corrió y se reintentaría cada petición.
            return;
        }

        try {
            $this->pdo->exec(
                "ALTER TABLE maquinaria_normas
                 ADD COLUMN IF NOT EXISTS tipo VARCHAR(20) NOT NULL DEFAULT 'acreditada'"
            );
        } catch (\Throwable $e) { /* ya existe o no aplica */ }

        try {
            $tipos = $this->pdo->query("SELECT id, nombre FROM maquinaria_tipos")->fetchAll();

            $leer     = $this->pdo->prepare(
                "SELECT id, norma, tipo, orden FROM maquinaria_normas WHERE maquinaria_tipo_id = ?"
            );
            $promover = $this->pdo->prepare(
                "UPDATE maquinaria_normas SET tipo = 'acreditada' WHERE id = ?"
            );
            $insertar = $this->pdo->prepare(
                "INSERT INTO maquinaria_normas (maquinaria_tipo_id, norma, tipo, orden)
                 VALUES (?, ?, 'acreditada', ?)"
            );

            foreach ($tipos as $t) {
                $tipoId = (int)($t['id'] ?? 0);
                if (!$tipoId) continue;

                $deben = $this->normasQueDebe((string)($t['nombre'] ?? ''));

                $leer->execute([$tipoId]);
                $filas = $leer->fetchAll();

                $porClave = [];
                $acreditadas = 0;
                $orden = 0;
                foreach ($filas as $f) {
                    $porClave[$this->claveNorma((string)$f['norma'])] = $f;
                    if (($f['tipo'] ?? 'acreditada') !== 'referencia') $acreditadas++;
                    $orden = max($orden, (int)($f['orden'] ?? 0));
                }

                foreach ($deben as $norma) {
                    $clave = $this->claveNorma($norma);
                    $ya    = $porClave[$clave] ?? null;

                    if ($ya) {
                        if (($ya['tipo'] ?? 'acreditada') === 'referencia') {
                            $promover->execute([$ya['id']]);
                            $acreditadas++;
                        }
                        continue;   // ya está, con su redacción
                    }
                    if ($acreditadas >= self::MAX_ACREDITADAS) {
                        error_log("[Admin] normas ema: tipo $tipoId ya tiene "
                            . self::MAX_ACREDITADAS . " acreditadas, se omite $norma");
                        break;
                    }
                    $insertar->execute([$tipoId, $norma, ++$orden]);
                    $acreditadas++;
                }
            }

            $this->pdo->prepare("INSERT INTO maquinaria_tipos_seed (clave) VALUES (?)")
                ->execute([self::SEED_NORMAS_EMA]);
        } catch (\Throwable $e) {
            error_log('[Admin] sembrarNormasAcreditacion: ' . $e->getMessage());
        }
    }

    /**
     * Normas acreditadas que le corresponden a un tipo por su nombre.
     *
     * El pórtico ampara sólo la competencia del inspector y la norma de la
     * unidad: la 9927-1 se cita ahí como referencia, no como alcance.
     */
    private function normasQueDebe(string $nombre): array {
        if (!$this->esGrua($nombre))   return [self::NORMA_UNIDAD];
        if ($this->esPortico($nombre)) return ['NMX-GR-23814-IMNC-2011', self::NORMA_UNIDAD];
        return array_merge(self::NORMAS_GRUA, [self::NORMA_UNIDAD]);
    }

    /**
     * Deja el pórtico con 23814 y 17020 como únicas acreditadas.
     *
     * Lo que sobra no se borra: baja a referencia, así sigue impreso en el
     * documento y Calidad puede devolverlo desde el panel si hace falta. Corre
     * una sola vez; después manda lo que Calidad haya decidido.
     */
    private function ajustarNormasPortico(): void {
        try {
            $st = $this->pdo->prepare("SELECT 1 FROM maquinaria_tipos_seed WHERE clave = ?");
            $st->execute([self::SEED_NORMAS_PORTICO]);
            if ($st->fetch()) return;
        } catch (\Throwable $e) { return; }

        try {
            $quedan = ['23814', '17020'];
            $tipos  = $this->pdo->query("SELECT id, nombre FROM maquinaria_tipos")->fetchAll();
            $leer   = $this->pdo->prepare(
                "SELECT id, norma FROM maquinaria_normas
                 WHERE maquinaria_tipo_id = ? AND tipo <> 'referencia'"
            );
            $bajar  = $this->pdo->prepare(
                "UPDATE maquinaria_normas SET tipo = 'referencia' WHERE id = ?"
            );

            foreach ($tipos as $t) {
                if (!$this->esPortico((string)($t['nombre'] ?? ''))) continue;
                $leer->execute([(int)$t['id']]);
                foreach ($leer->fetchAll() as $f) {
                    if (in_array($this->claveNorma((string)$f['norma']), $quedan, true)) continue;
                    $bajar->execute([$f['id']]);
                }
            }

            $this->pdo->prepare("INSERT INTO maquinaria_tipos_seed (clave) VALUES (?)")
                ->execute([self::SEED_NORMAS_PORTICO]);
        } catch (\Throwable $e) {
            error_log('[Admin] ajustarNormasPortico: ' . $e->getMessage());
        }
    }

    /**
     * Da de alta el catálogo de normas que Calidad marca por tipo de equipo.
     *
     * Además del catálogo base recoge las normas que ya estén capturadas en
     * algún tipo, para que la lista no omita ninguna de las que hoy se imprimen.
     */
    private function sembrarCatalogoNormas(): void {
        try {
            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS normas_catalogo (
                   id          INT AUTO_INCREMENT PRIMARY KEY,
                   norma       VARCHAR(300) NOT NULL,
                   descripcion VARCHAR(300) NULL,
                   creada      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                   UNIQUE KEY uq_norma (norma)
                 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } catch (\Throwable $e) {
            error_log('[Admin] catálogo de normas (tabla): ' . $e->getMessage());
            return;
        }

        try {
            $st = $this->pdo->prepare("SELECT 1 FROM maquinaria_tipos_seed WHERE clave = ?");
            $st->execute([self::SEED_CATALOGO]);
            if ($st->fetch()) return;
        } catch (\Throwable $e) { return; }

        try {
            $ins = $this->pdo->prepare(
                "INSERT INTO normas_catalogo (norma, descripcion) VALUES (?, ?)"
            );
            $ya = array_map(
                'strtoupper',
                $this->pdo->query("SELECT norma FROM normas_catalogo")->fetchAll(PDO::FETCH_COLUMN) ?: []
            );

            foreach (self::CATALOGO_NORMAS_BASE as [$norma, $desc]) {
                if (in_array(strtoupper($norma), $ya, true)) continue;
                $ins->execute([$norma, $desc]);
                $ya[] = strtoupper($norma);
            }

            // Lo que ya esté en uso tiene que aparecer en la lista aunque no
            // venga en el catálogo base.
            $enUso = $this->pdo->query("SELECT DISTINCT norma FROM maquinaria_normas")
                ->fetchAll(PDO::FETCH_COLUMN) ?: [];
            foreach ($enUso as $norma) {
                $norma = trim((string)$norma);
                if ($norma === '' || in_array(strtoupper($norma), $ya, true)) continue;
                $ins->execute([$norma, null]);
                $ya[] = strtoupper($norma);
            }

            $this->pdo->prepare("INSERT INTO maquinaria_tipos_seed (clave) VALUES (?)")
                ->execute([self::SEED_CATALOGO]);
        } catch (\Throwable $e) {
            error_log('[Admin] sembrarCatalogoNormas: ' . $e->getMessage());
        }
    }

    // ── Catálogo de normas (ADMIN + CALIDAD) ───────────────────

    /** Lista el catálogo con el número de tipos que usa cada norma. */
    public function listarCatalogoNormas(): array {
        try {
            $filas = $this->pdo->query(
                "SELECT c.id, c.norma, c.descripcion,
                        (SELECT COUNT(DISTINCT n.maquinaria_tipo_id)
                           FROM maquinaria_normas n WHERE n.norma = c.norma) AS en_uso
                 FROM normas_catalogo c
                 ORDER BY c.norma"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => 'No se pudo leer el catálogo de normas.'];
        }
        return [
            'status' => 'success',
            'data'   => $filas,
            'topes'  => ['acreditadas' => self::MAX_ACREDITADAS, 'referencia' => self::MAX_REFERENCIA],
        ];
    }

    /** Agrega una norma al catálogo para que quede disponible en todos los tipos. */
    public function agregarNormaCatalogo(array $payload): array {
        $norma = trim((string)($payload['norma'] ?? ''));
        $desc  = trim((string)($payload['descripcion'] ?? ''));
        if ($norma === '')            return ['status' => 'error', 'message' => 'Escribe la norma.'];
        if (mb_strlen($norma) > 300)  return ['status' => 'error', 'message' => 'La norma es demasiado larga.'];

        try {
            $dup = $this->pdo->prepare("SELECT id FROM normas_catalogo WHERE UPPER(norma) = ?");
            $dup->execute([mb_strtoupper($norma)]);
            if ($dup->fetch()) return ['status' => 'error', 'message' => 'Esa norma ya está en el catálogo.'];

            $this->pdo->prepare("INSERT INTO normas_catalogo (norma, descripcion) VALUES (?, ?)")
                ->execute([$norma, $desc !== '' ? mb_substr($desc, 0, 300) : null]);
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => 'No se pudo guardar la norma.'];
        }
        return ['status' => 'success', 'message' => 'Norma agregada al catálogo.'];
    }

    /**
     * Quita una norma del catálogo.
     *
     * Si algún tipo la tiene marcada no se borra: se estaría quitando de la
     * lista algo que hoy se imprime en certificados y dictámenes.
     */
    public function eliminarNormaCatalogo(array $payload): array {
        $id = (int)($payload['id'] ?? 0);
        if (!$id) return ['status' => 'error', 'message' => 'Falta la norma.'];

        try {
            $st = $this->pdo->prepare("SELECT norma FROM normas_catalogo WHERE id = ?");
            $st->execute([$id]);
            $norma = $st->fetchColumn();
            if ($norma === false) return ['status' => 'error', 'message' => 'La norma ya no existe.'];

            $uso = $this->pdo->prepare(
                "SELECT t.nombre FROM maquinaria_normas n
                 INNER JOIN maquinaria_tipos t ON t.id = n.maquinaria_tipo_id
                 WHERE n.norma = ? ORDER BY t.nombre"
            );
            $uso->execute([$norma]);
            $tipos = $uso->fetchAll(PDO::FETCH_COLUMN) ?: [];
            if ($tipos) {
                return ['status' => 'error', 'message' =>
                    'No se puede quitar: la usan ' . implode(', ', array_unique($tipos))
                    . '. Desmárcala de esos tipos primero.'];
            }

            $this->pdo->prepare("DELETE FROM normas_catalogo WHERE id = ?")->execute([$id]);
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => 'No se pudo quitar la norma.'];
        }
        return ['status' => 'success', 'message' => 'Norma quitada del catálogo.'];
    }

    /** Si el tipo de equipo es un pórtico. */
    private function esPortico(string $nombre): bool {
        return str_contains(
            strtoupper(strtr($nombre, ['Ó' => 'O', 'ó' => 'o'])), 'PORTICO'
        );
    }

    /** Si el tipo de equipo es una grúa, sin depender del acento ni de mayúsculas. */
    private function esGrua(string $nombre): bool {
        $n = strtoupper(strtr($nombre, ['Ú' => 'U', 'ú' => 'u']));
        return str_contains($n, 'GRUA');
    }

    /**
     * Núcleo identificador de una norma, para no duplicar la que ya esté
     * capturada con otra redacción ("ISO 9927-1:2013" y "NMX-GR-9927-1-IMNC-2019"
     * son la misma). Se toma el primer grupo de 4 o 5 dígitos, que es el número
     * de la norma; si no hay, se compara el texto sin separadores.
     */
    private function claveNorma(string $norma): string {
        if (preg_match('/(\d{4,5})/', $norma, $m)) return $m[1];
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $norma) ?? $norma);
    }

    /**
     * Alta única de los tipos base. La marca de aplicado va en su propia tabla
     * para no reponer lo que Calidad haya decidido quitar.
     *
     * Se atrapa Throwable en todo: esta clase se construye en TODAS las
     * peticiones, incluida la de iniciar sesión, así que un fallo aquí no puede
     * tumbar el sistema.
     */
    private function seedTiposMaquinaria(): void {
        try {
            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS maquinaria_tipos_seed (
                   clave    VARCHAR(40) NOT NULL PRIMARY KEY,
                   aplicado TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            $this->pdo->exec(
                "ALTER TABLE maquinaria_tipos
                 ADD COLUMN IF NOT EXISTS plantilla_dict_html VARCHAR(100) NULL"
            );
        } catch (\Throwable $e) {
            error_log('[Admin] seedTiposMaquinaria (marca): ' . $e->getMessage());
            return;
        }

        foreach (self::TIPOS_BASE as $clave => [$nombre, $plantilla]) {
            try {
                $st = $this->pdo->prepare("SELECT 1 FROM maquinaria_tipos_seed WHERE clave = ?");
                $st->execute([$clave]);
                if ($st->fetch()) continue;

                // Puede existir ya, capturado a mano y quizá con acento distinto.
                $dup = $this->pdo->prepare(
                    "SELECT id, plantilla_dict_html FROM maquinaria_tipos
                     WHERE UPPER(TRIM(nombre)) = ? LIMIT 1"
                );
                $dup->execute([$nombre]);
                $fila = $dup->fetch();

                if ($fila) {
                    // No se duplica: sólo se le asigna la plantilla si le falta.
                    if (empty($fila['plantilla_dict_html'])) {
                        $this->pdo->prepare(
                            "UPDATE maquinaria_tipos SET plantilla_dict_html = ? WHERE id = ?"
                        )->execute([$plantilla, $fila['id']]);
                    }
                    $tipoId = (int)$fila['id'];
                } else {
                    $this->pdo->prepare(
                        "INSERT INTO maquinaria_tipos (nombre, plantilla_dict_html) VALUES (?, ?)"
                    )->execute([$nombre, $plantilla]);
                    $tipoId = (int)$this->pdo->lastInsertId();
                }
                $this->sembrarChecklist($clave, $tipoId);
                $this->pdo->prepare("INSERT INTO maquinaria_tipos_seed (clave) VALUES (?)")->execute([$clave]);
            } catch (\Throwable $e) {
                error_log("[Admin] seedTiposMaquinaria ($clave): " . $e->getMessage());
            }
        }
    }

    // ── Listar tipos de equipo con sus secciones ───────────────
    /**
     * Secciones, puntos de revisión y normas con los que nace un tipo.
     *
     * Sólo se siembra lo que falte: si el tipo ya existía con su checklist
     * capturado a mano, no se le agrega nada encima. Un fallo aquí no puede
     * impedir el alta del tipo, que es lo importante.
     */
    private function sembrarChecklist(string $clave, int $tipoId): void {
        $this->sembrarSeccionesItems($clave, $tipoId);
        $this->sembrarNormasTipo($clave, $tipoId);
    }

    /**
     * Secciones y puntos de revisión. Aparte de las normas porque rehacer una
     * lista no debe duplicar lo que Calidad tenga capturado en la otra tabla.
     */
    private function sembrarSeccionesItems(string $clave, int $tipoId): void {
        $base = self::CHECKLIST_BASE[$clave] ?? null;
        if (!$base || !$tipoId) return;

        try {
            $ya = $this->pdo->prepare("SELECT COUNT(*) FROM checklist_items WHERE maquinaria_tipo_id = ?");
            $ya->execute([$tipoId]);
            if ((int)$ya->fetchColumn() > 0) return;   // ya tiene lista propia
        } catch (\Throwable $e) { return; }

        try {
            $sec = $this->pdo->prepare(
                "INSERT IGNORE INTO checklist_secciones (maquinaria_tipo_id, codigo, nombre, orden)
                 VALUES (?,?,?,?)"
            );
            foreach ($base['secciones'] as $i => [$codigo, $nombre]) {
                $sec->execute([$tipoId, $codigo, $nombre, $i + 1]);
            }

            $it = $this->pdo->prepare(
                "INSERT IGNORE INTO checklist_items (maquinaria_tipo_id, seccion, tag, descripcion, orden)
                 VALUES (?,?,?,?,?)"
            );
            foreach ($base['items'] as $i => [$seccion, $tag, $descripcion]) {
                $it->execute([$tipoId, $seccion, $tag, $descripcion, $i + 1]);
            }
        } catch (\Throwable $e) {
            error_log("[Admin] sembrarChecklist ($clave): " . $e->getMessage());
        }
    }

    /** Normas del tipo. Su tabla pudo no existir en instalaciones viejas. */
    private function sembrarNormasTipo(string $clave, int $tipoId): void {
        $base = self::CHECKLIST_BASE[$clave] ?? null;
        if (!$base || !$tipoId) return;

        try {
            $this->pdo->exec(
                "ALTER TABLE maquinaria_normas
                 ADD COLUMN IF NOT EXISTS tipo VARCHAR(20) NOT NULL DEFAULT 'acreditada'"
            );
        } catch (\Throwable $e) { /* ya existe o no aplica */ }
        try {
            $n = $this->pdo->prepare(
                "INSERT INTO maquinaria_normas (maquinaria_tipo_id, norma, tipo, orden) VALUES (?,?,?,?)"
            );
            foreach ($base['normas'] as $i => [$norma, $tipo]) {
                $n->execute([$tipoId, $norma, $tipo, $i + 1]);
            }
        } catch (\Throwable $e) {
            error_log("[Admin] sembrarNormas ($clave): " . $e->getMessage());
        }
    }

    public function listarTiposEquipo(): array {
        // Auto-aplicar migration_007 si las columnas PDF no existen todavía
        $this->ensureColumnsPdf();

        $tipos = $this->pdo->query(
            "SELECT id, nombre, plantilla,
                    plantilla_cert, plantilla_dict,
                    plantilla_cert_envio, plantilla_dict_envio,
                    plantilla_cert_pdf, plantilla_dict_pdf,
                    plantilla_dict_html
             FROM maquinaria_tipos ORDER BY id"
        )->fetchAll();

        foreach ($tipos as &$tipo) {
            $tipo['secciones'] = $this->pdo->prepare(
                "SELECT id, codigo, nombre, orden
                 FROM checklist_secciones
                 WHERE maquinaria_tipo_id = ?
                 ORDER BY orden"
            )->execute([$tipo['id']]) ? [] : [];

            $stmtSec = $this->pdo->prepare(
                "SELECT id, codigo, nombre, orden
                 FROM checklist_secciones
                 WHERE maquinaria_tipo_id = ? ORDER BY orden"
            );
            $stmtSec->execute([$tipo['id']]);
            $tipo['secciones'] = $stmtSec->fetchAll();

            $stmtItems = $this->pdo->prepare(
                "SELECT id, seccion, tag, descripcion, orden
                 FROM checklist_items
                 WHERE maquinaria_tipo_id = ? ORDER BY seccion, orden"
            );
            $stmtItems->execute([$tipo['id']]);
            $tipo['checklist'] = $stmtItems->fetchAll();

            $stmtNormas = $this->pdo->prepare(
                "SELECT id, norma, tipo, orden FROM maquinaria_normas
                 WHERE maquinaria_tipo_id = ?
                 ORDER BY FIELD(tipo,'acreditada','referencia'), orden"
            );
            $stmtNormas->execute([$tipo['id']]);
            $tipo['normas'] = $stmtNormas->fetchAll();
        }
        unset($tipo);

        return ['status' => 'success', 'data' => $tipos];
    }

    // ── Crear nuevo tipo de equipo ─────────────────────────────
    public function crearTipoEquipo(array $payload): array {
        $nombre = trim($payload['nombre'] ?? '');
        if (!$nombre) return ['status' => 'error', 'message' => 'El nombre es requerido.'];

        // Verificar que no exista
        $existe = $this->pdo->prepare("SELECT id FROM maquinaria_tipos WHERE nombre = ?");
        $existe->execute([$nombre]);
        if ($existe->fetch()) return ['status' => 'error', 'message' => 'Ya existe un tipo con ese nombre.'];

        $this->pdo->prepare("INSERT INTO maquinaria_tipos (nombre) VALUES (?)")
            ->execute([$nombre]);
        $tipoId = (int) $this->pdo->lastInsertId();

        // Insertar secciones si vienen en el payload
        $secciones = $payload['secciones'] ?? [];
        foreach ($secciones as $sec) {
            $this->pdo->prepare(
                "INSERT IGNORE INTO checklist_secciones
                 (maquinaria_tipo_id, codigo, nombre, orden) VALUES (?,?,?,?)"
            )->execute([
                $tipoId,
                strtoupper(trim($sec['codigo'] ?? '')),
                trim($sec['nombre'] ?? ''),
                (int) ($sec['orden'] ?? 0)
            ]);
        }

        // Insertar items de checklist si vienen en el payload
        $items = $payload['checklist'] ?? [];
        foreach ($items as $idx => $item) {
            $tag = strtoupper(trim($item['tag'] ?? ''));
            if (!$tag) continue;
            $this->pdo->prepare(
                "INSERT IGNORE INTO checklist_items
                 (maquinaria_tipo_id, seccion, tag, descripcion, orden) VALUES (?,?,?,?,?)"
            )->execute([
                $tipoId,
                strtoupper(trim($item['seccion'] ?? '')),
                $tag,
                trim($item['descripcion'] ?? ''),
                (int) ($item['orden'] ?? $idx + 1)
            ]);
        }

        // Insertar normas (máx. 6)
        $this->guardarNormasParaTipo($tipoId, $payload);

        return ['status' => 'success', 'message' => 'Tipo de equipo creado.', 'id' => $tipoId];
    }

    // ── Editar tipo de equipo (nombre + normas) ────────────────
    public function editarTipoEquipo(array $payload): array {
        $id     = (int) ($payload['tipo_id'] ?? 0);
        $nombre = trim($payload['nombre'] ?? '');

        if (!$id || !$nombre)
            return ['status' => 'error', 'message' => 'tipo_id y nombre son requeridos.'];

        // Evitar duplicados
        $dup = $this->pdo->prepare("SELECT id FROM maquinaria_tipos WHERE nombre = ? AND id != ?");
        $dup->execute([$nombre, $id]);
        if ($dup->fetch())
            return ['status' => 'error', 'message' => 'Ya existe otro tipo con ese nombre.'];

        $this->pdo->prepare("UPDATE maquinaria_tipos SET nombre = ? WHERE id = ?")
            ->execute([$nombre, $id]);

        // Actualizar normas si vienen en el payload
        if (array_key_exists('normas', $payload) || array_key_exists('normas_acreditadas', $payload) || array_key_exists('normas_referencia', $payload)) {
            $this->guardarNormasParaTipo($id, $payload);
        }

        return ['status' => 'success', 'message' => 'Tipo de equipo actualizado.'];
    }

    // ── Guardar normas de un tipo (reemplaza todas) ────────────
    // Accepts either:
    //   new format: payload has normas_acreditadas[] + normas_referencia[]
    //   legacy format: plain normas[] array (treated as acreditadas)
    private function guardarNormasParaTipo(int $tipoId, array $payload): void {
        // Auto-add tipo column if migration_012 not applied yet
        try {
            $this->pdo->exec(
                "ALTER TABLE maquinaria_normas
                 ADD COLUMN IF NOT EXISTS tipo ENUM('acreditada','referencia')
                 NOT NULL DEFAULT 'acreditada' AFTER norma"
            );
        } catch (\Throwable $e) {}

        $this->pdo->prepare("DELETE FROM maquinaria_normas WHERE maquinaria_tipo_id = ?")
            ->execute([$tipoId]);

        $stmt = $this->pdo->prepare(
            "INSERT INTO maquinaria_normas (maquinaria_tipo_id, norma, tipo, orden)
             VALUES (?, ?, ?, ?)"
        );

        // New format: separate arrays
        if (array_key_exists('normas_acreditadas', $payload) || array_key_exists('normas_referencia', $payload)) {
            $acreditadas = array_slice(
                array_filter(array_map('trim', $payload['normas_acreditadas'] ?? []), fn($n) => $n !== ''),
                0, self::MAX_ACREDITADAS
            );
            $referencia = array_slice(
                array_filter(array_map('trim', $payload['normas_referencia'] ?? []), fn($n) => $n !== ''),
                0, self::MAX_REFERENCIA
            );
            foreach ($acreditadas as $i => $norma) {
                $stmt->execute([$tipoId, $norma, 'acreditada', $i + 1]);
            }
            foreach ($referencia as $i => $norma) {
                $stmt->execute([$tipoId, $norma, 'referencia', $i + 1]);
            }
        } else {
            // Legacy: plain normas array → acreditadas
            $normas = is_array($payload['normas'] ?? null)
                ? $payload['normas']
                : (is_array($payload) && !isset($payload['normas_acreditadas']) ? $payload : []);
            $filtradas = array_slice(
                array_filter(array_map('trim', $normas), fn($n) => $n !== ''),
                0, self::MAX_ACREDITADAS
            );
            foreach ($filtradas as $i => $norma) {
                $stmt->execute([$tipoId, $norma, 'acreditada', $i + 1]);
            }
        }
    }

    // ── Eliminar tipo de equipo (con secciones e ítems) ────────
    public function eliminarTipoEquipo(array $payload): array {
        $id = (int) ($payload['tipo_id'] ?? 0);
        if (!$id) return ['status' => 'error', 'message' => 'tipo_id requerido.'];

        $this->pdo->prepare("DELETE FROM maquinaria_normas    WHERE maquinaria_tipo_id = ?")->execute([$id]);
        $this->pdo->prepare("DELETE FROM checklist_items      WHERE maquinaria_tipo_id = ?")->execute([$id]);
        $this->pdo->prepare("DELETE FROM checklist_secciones  WHERE maquinaria_tipo_id = ?")->execute([$id]);
        $this->pdo->prepare("DELETE FROM maquinaria_tipos     WHERE id = ?")->execute([$id]);

        return ['status' => 'success', 'message' => 'Tipo de equipo eliminado.'];
    }

    // ── Agregar sección a un tipo existente ────────────────────
    public function agregarSeccion(array $payload): array {
        $tipoId = (int) ($payload['tipo_id'] ?? 0);
        $codigo = strtoupper(trim($payload['codigo'] ?? ''));
        $nombre = trim($payload['nombre'] ?? '');
        $orden  = (int) ($payload['orden'] ?? 0);

        if (!$tipoId || !$codigo || !$nombre)
            return ['status' => 'error', 'message' => 'tipo_id, codigo y nombre son requeridos.'];

        $this->pdo->prepare(
            "INSERT INTO checklist_secciones (maquinaria_tipo_id, codigo, nombre, orden)
             VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), orden = VALUES(orden)"
        )->execute([$tipoId, $codigo, $nombre, $orden]);

        return ['status' => 'success', 'message' => 'Sección guardada.'];
    }

    // ── Editar nombre de sección ───────────────────────────────
    public function editarSeccion(array $payload): array {
        $id     = (int) ($payload['seccion_id'] ?? 0);
        $nombre = trim($payload['nombre'] ?? '');

        if (!$id || !$nombre)
            return ['status' => 'error', 'message' => 'seccion_id y nombre son requeridos.'];

        $this->pdo->prepare("UPDATE checklist_secciones SET nombre = ? WHERE id = ?")
            ->execute([$nombre, $id]);

        return ['status' => 'success', 'message' => 'Sección actualizada.'];
    }

    // ── Eliminar sección y sus ítems ───────────────────────────
    public function eliminarSeccion(array $payload): array {
        $id = (int) ($payload['seccion_id'] ?? 0);
        if (!$id) return ['status' => 'error', 'message' => 'seccion_id requerido.'];

        // Obtener codigo y maquinaria_tipo_id para eliminar también sus ítems
        $stmt = $this->pdo->prepare(
            "SELECT maquinaria_tipo_id, codigo FROM checklist_secciones WHERE id = ?"
        );
        $stmt->execute([$id]);
        $sec = $stmt->fetch();

        if (!$sec) return ['status' => 'error', 'message' => 'Sección no encontrada.'];

        // Eliminar ítems de esa sección
        $this->pdo->prepare(
            "DELETE FROM checklist_items WHERE maquinaria_tipo_id = ? AND seccion = ?"
        )->execute([$sec['maquinaria_tipo_id'], $sec['codigo']]);

        // Eliminar la sección
        $this->pdo->prepare("DELETE FROM checklist_secciones WHERE id = ?")->execute([$id]);

        return ['status' => 'success', 'message' => 'Sección e ítems eliminados.'];
    }

    // ── Agregar/editar item del checklist ──────────────────────
    public function guardarItemChecklist(array $payload): array {
        $tipoId = (int) ($payload['tipo_id'] ?? 0);
        $seccion = strtoupper(trim($payload['seccion'] ?? ''));
        $tag     = strtoupper(trim($payload['tag'] ?? ''));
        $desc    = trim($payload['descripcion'] ?? '');
        $orden   = (int) ($payload['orden'] ?? 0);

        if (!$tipoId || !$seccion || !$tag || !$desc)
            return ['status' => 'error', 'message' => 'tipo_id, seccion, tag y descripcion son requeridos.'];

        $this->pdo->prepare(
            "INSERT INTO checklist_items
             (maquinaria_tipo_id, seccion, tag, descripcion, orden)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion), orden = VALUES(orden)"
        )->execute([$tipoId, $seccion, $tag, $desc, $orden]);

        return ['status' => 'success', 'message' => 'Item guardado.'];
    }

    // ── Eliminar item del checklist ────────────────────────────
    public function eliminarItemChecklist(array $payload): array {
        $id = (int) ($payload['item_id'] ?? 0);
        if (!$id) return ['status' => 'error', 'message' => 'item_id requerido.'];

        $this->pdo->prepare("DELETE FROM checklist_items WHERE id = ?")->execute([$id]);
        return ['status' => 'success', 'message' => 'Item eliminado.'];
    }

    // ── Subir plantilla Word para certificado o dictamen ──────
    /**
     * Recibe un archivo .docx vía multipart/form-data.
     * Parámetros POST: tipo_id (int), doc_tipo ('cert'|'dict')
     * Parámetro FILE:  plantilla (el archivo .docx)
     *
     * Etiquetas disponibles en la plantilla Word:
     *   ${folio}         → AB.XXXXX-XXXXX-2026MX
     *   ${cliente}       → Nombre del cliente
     *   ${domicilio}     → Dirección
     *   ${maquinaria}    → Tipo de equipo
     *   ${marca}         → Marca
     *   ${modelo}        → Modelo
     *   ${serie}         → Número de serie
     *   ${id_equipo}     → ID del equipo
     *   ${capacidad}     → Capacidad
     *   ${fecha}         → Fecha de inspección (dd/mm/yyyy)
     *   ${vigencia}      → Fecha de vencimiento (dd/mm/yyyy)
     *   ${qr_codigo}     → Código QR (texto)
     *   ${anio}          → Año actual
     *
     *   Para dictamen — fila de tabla a clonar:
     *   ${item_seccion}      → Nombre de sección
     *   ${item_descripcion}  → Descripción del ítem
     *   ${item_valor}        → CONFORME / NO CONFORME / N/A
     */
    public function subirPlantilla(array $post, array $files): array {
        $tipoId  = (int)($post['tipo_id']  ?? 0);
        $docTipo = trim($post['doc_tipo']  ?? '');

        if (!$tipoId || !in_array($docTipo, ['cert', 'dict', 'cert_envio', 'dict_envio'], true))
            return ['status' => 'error', 'message' => 'tipo_id y doc_tipo (cert|dict|cert_envio|dict_envio) son requeridos.'];

        // Verificar que el tipo existe
        $chk = $this->pdo->prepare("SELECT id FROM maquinaria_tipos WHERE id = ?");
        $chk->execute([$tipoId]);
        if (!$chk->fetch())
            return ['status' => 'error', 'message' => 'Tipo de equipo no encontrado.'];

        $file = $files['plantilla'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)
            return ['status' => 'error', 'message' => 'No se recibió archivo o hubo un error al subirlo.'];

        $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        if ($ext !== 'docx')
            return ['status' => 'error', 'message' => 'Solo se aceptan archivos .docx'];

        $colMap = [
            'cert'       => 'plantilla_cert',
            'dict'       => 'plantilla_dict',
            'cert_envio' => 'plantilla_cert_envio',
            'dict_envio' => 'plantilla_dict_envio',
        ];
        if (!array_key_exists($docTipo, $colMap))
            return ['status' => 'error', 'message' => 'doc_tipo inválido. Usa: cert, dict, cert_envio, dict_envio'];

        $dir = __DIR__ . '/../uploads/plantillas/';
        if (!is_dir($dir) && !mkdir($dir, 0755, true))
            return ['status' => 'error', 'message' => 'No se pudo crear el directorio de plantillas.'];

        $filename = "tipo_{$tipoId}_{$docTipo}.docx";
        $destPath = $dir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destPath))
            return ['status' => 'error', 'message' => 'Error al guardar el archivo en el servidor.'];

        $col = $colMap[$docTipo];
        $this->pdo->prepare("UPDATE maquinaria_tipos SET `{$col}` = ? WHERE id = ?")
            ->execute([$filename, $tipoId]);

        return ['status' => 'success', 'message' => 'Plantilla guardada correctamente.', 'archivo' => $filename];
    }

    // ── Auto-migración: agrega columnas PDF si no existen ─────
    private function ensureColumnsPdf(): void {
        // Verifica y agrega cada columna individualmente.
        // Compatible con MySQL 5.x (no tiene ADD COLUMN IF NOT EXISTS)
        // y con MySQL 8.x.
        $needed = [
            'plantilla_cert_pdf' => "ALTER TABLE maquinaria_tipos ADD COLUMN plantilla_cert_pdf VARCHAR(500) NULL AFTER plantilla_cert",
            'plantilla_dict_pdf' => "ALTER TABLE maquinaria_tipos ADD COLUMN plantilla_dict_pdf VARCHAR(500) NULL AFTER plantilla_dict",
            'cert_pdf_campos'    => "ALTER TABLE maquinaria_tipos ADD COLUMN cert_pdf_campos    JSON          NULL",
            'dict_pdf_campos'    => "ALTER TABLE maquinaria_tipos ADD COLUMN dict_pdf_campos    JSON          NULL",
        ];

        foreach ($needed as $col => $ddl) {
            $exists = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = 'maquinaria_tipos'
                   AND COLUMN_NAME  = '{$col}'"
            )->fetchColumn();
            if (!$exists) {
                try { $this->pdo->exec($ddl); } catch (\PDOException $e) { /* ignore */ }
            }
        }
    }

    public function subirPlantillaPdf(array $post, array $files): array {
        // Garantizar que las columnas existen antes de actualizar
        $this->ensureColumnsPdf();
        $tipoId  = (int)($post['tipo_id']  ?? 0);
        $docTipo = trim($post['doc_tipo']  ?? ''); // cert_pdf | dict_pdf

        if (!$tipoId || !in_array($docTipo, ['cert_pdf', 'dict_pdf'], true))
            return ['status' => 'error', 'message' => 'tipo_id y doc_tipo (cert_pdf|dict_pdf) son requeridos.'];

        $chk = $this->pdo->prepare("SELECT id FROM maquinaria_tipos WHERE id = ?");
        $chk->execute([$tipoId]);
        if (!$chk->fetch())
            return ['status' => 'error', 'message' => 'Tipo de equipo no encontrado.'];

        $file = $files['plantilla'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)
            return ['status' => 'error', 'message' => 'No se recibió archivo o hubo un error al subirlo.'];

        $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        if ($ext !== 'pdf')
            return ['status' => 'error', 'message' => 'Solo se aceptan archivos .pdf'];

        $dir = __DIR__ . '/../uploads/plantillas/';
        if (!is_dir($dir) && !mkdir($dir, 0755, true))
            return ['status' => 'error', 'message' => 'No se pudo crear el directorio de plantillas.'];

        $colMap = ['cert_pdf' => 'plantilla_cert_pdf', 'dict_pdf' => 'plantilla_dict_pdf'];
        $col    = $colMap[$docTipo];

        // Borrar el archivo anterior si existe
        $anterior = $this->pdo->prepare("SELECT `{$col}` FROM maquinaria_tipos WHERE id = ?");
        $anterior->execute([$tipoId]);
        $oldFile = ($anterior->fetchColumn()) ?: '';
        if ($oldFile && file_exists($dir . $oldFile)) {
            @unlink($dir . $oldFile);
        }

        // Nombre único que preserva el nombre original + timestamp
        $baseName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($file['name'] ?? 'plantilla', PATHINFO_FILENAME));
        $filename = "tipo_{$tipoId}_{$docTipo}_{$baseName}_" . time() . ".pdf";

        if (!move_uploaded_file($file['tmp_name'], $dir . $filename))
            return ['status' => 'error', 'message' => 'Error al guardar el archivo en el servidor.'];

        try {
            $this->pdo->prepare("UPDATE maquinaria_tipos SET `{$col}` = ? WHERE id = ?")
                ->execute([$filename, $tipoId]);
        } catch (\PDOException $e) {
            return ['status' => 'error', 'message' => 'Error al guardar en la base de datos: ' . $e->getMessage()];
        }

        return ['status' => 'success', 'message' => 'Plantilla PDF guardada.', 'archivo' => $filename];
    }

    // ── Guardar campos / coordenadas del PDF ──────────────────
    public function guardarCamposPdf(array $payload): array {
        $tipoId  = (int)($payload['tipo_id']  ?? 0);
        $docTipo = trim($payload['doc_tipo']  ?? ''); // cert | dict
        $campos  = $payload['campos'] ?? '[]';

        if (!$tipoId || !in_array($docTipo, ['cert', 'dict'], true))
            return ['status' => 'error', 'message' => 'tipo_id y doc_tipo (cert|dict) requeridos.'];

        // Validar JSON
        $decoded = is_string($campos) ? json_decode($campos, true) : $campos;
        if (!is_array($decoded))
            return ['status' => 'error', 'message' => 'El parámetro campos debe ser un array JSON.'];

        $colMap = ['cert' => 'cert_pdf_campos', 'dict' => 'dict_pdf_campos'];
        $col    = $colMap[$docTipo];
        $json   = json_encode($decoded, JSON_UNESCAPED_UNICODE);

        $this->pdo->prepare("UPDATE maquinaria_tipos SET `{$col}` = ? WHERE id = ?")
            ->execute([$json, $tipoId]);

        return ['status' => 'success', 'message' => 'Campos PDF guardados.'];
    }

    // ── Obtener campos PDF de un tipo de equipo ───────────────
    public function obtenerCamposPdf(int $tipoId): array {
        $stmt = $this->pdo->prepare(
            "SELECT plantilla_cert_pdf, plantilla_dict_pdf,
                    cert_pdf_campos, dict_pdf_campos
             FROM maquinaria_tipos WHERE id = ?"
        );
        $stmt->execute([$tipoId]);
        $row = $stmt->fetch();
        if (!$row) return ['status' => 'error', 'message' => 'Tipo no encontrado.'];

        $base = rtrim(UPLOAD_URL, '/') . '/plantillas/';
        return [
            'status'             => 'success',
            'plantilla_cert_pdf' => $row['plantilla_cert_pdf'] ?? null,
            'plantilla_dict_pdf' => $row['plantilla_dict_pdf'] ?? null,
            'cert_pdf_url'       => $row['plantilla_cert_pdf'] ? $base . $row['plantilla_cert_pdf'] : null,
            'dict_pdf_url'       => $row['plantilla_dict_pdf'] ? $base . $row['plantilla_dict_pdf'] : null,
            'cert_pdf_campos'    => json_decode($row['cert_pdf_campos'] ?? '[]', true) ?: [],
            'dict_pdf_campos'    => json_decode($row['dict_pdf_campos'] ?? '[]', true) ?: [],
        ];
    }

    // ── Regenerar reporte de inspección ───────────────────────
    public function regenerarReporte(array $payload, string $usuario = ''): array {
        $equipoId = (int) ($payload['equipo_id'] ?? 0);
        if (!$equipoId) return ['status' => 'error', 'message' => 'equipo_id requerido.'];

        require_once __DIR__ . '/ReporteInspeccion.php';
        return ReporteInspeccion::generar($this->pdo, $equipoId, $usuario);
    }

    // ══════════════════════════════════════════════════════
    //  PLANTILLAS PDF — PERSONAL / CAPACITACIÓN
    // ══════════════════════════════════════════════════════

    private function ensurePlantillasPersonal(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS plantillas_personal (
              tipo          ENUM('diploma','certificado','dc3') NOT NULL,
              plantilla_pdf VARCHAR(500)  NULL,
              pdf_campos    JSON          NULL,
              actualizado   TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (tipo)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $this->pdo->exec("INSERT IGNORE INTO plantillas_personal (tipo) VALUES ('diploma'),('certificado'),('dc3')");
    }

    public function listarPlantillasPersonal(): array {
        $this->ensurePlantillasPersonal();
        $stmt = $this->pdo->query(
            "SELECT tipo, plantilla_pdf, pdf_campos
             FROM plantillas_personal
             ORDER BY FIELD(tipo,'diploma','certificado','dc3')"
        );
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['pdf_campos'] = json_decode($r['pdf_campos'] ?? '[]', true) ?: [];
        }
        unset($r);
        return ['status' => 'success', 'data' => $rows];
    }

    public function subirPlantillaPdfPersonal(array $post, array $files): array {
        $this->ensurePlantillasPersonal();
        $tipo = trim($post['tipo'] ?? '');
        if (!in_array($tipo, ['diploma','certificado','dc3'], true))
            return ['status' => 'error', 'message' => 'tipo debe ser diploma, certificado o dc3.'];

        $file = $files['plantilla'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)
            return ['status' => 'error', 'message' => 'No se recibió archivo o hubo un error al subirlo.'];

        $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        if ($ext !== 'pdf')
            return ['status' => 'error', 'message' => 'Solo se aceptan archivos .pdf'];

        $dir = __DIR__ . '/../uploads/plantillas/';
        if (!is_dir($dir) && !mkdir($dir, 0755, true))
            return ['status' => 'error', 'message' => 'No se pudo crear el directorio de plantillas.'];

        $filename = "personal_{$tipo}.pdf";
        if (!move_uploaded_file($file['tmp_name'], $dir . $filename))
            return ['status' => 'error', 'message' => 'Error al guardar el archivo en el servidor.'];

        $this->pdo->prepare("UPDATE plantillas_personal SET plantilla_pdf = ? WHERE tipo = ?")
            ->execute([$filename, $tipo]);

        return ['status' => 'success', 'message' => 'Plantilla PDF guardada.', 'archivo' => $filename];
    }

    public function guardarCamposPdfPersonal(array $payload): array {
        $this->ensurePlantillasPersonal();
        $tipo   = trim($payload['tipo']   ?? '');
        $campos = $payload['campos'] ?? [];

        if (!in_array($tipo, ['diploma','certificado','dc3'], true))
            return ['status' => 'error', 'message' => 'tipo debe ser diploma, certificado o dc3.'];

        $decoded = is_string($campos) ? json_decode($campos, true) : $campos;
        if (!is_array($decoded))
            return ['status' => 'error', 'message' => 'campos debe ser un array JSON.'];

        $this->pdo->prepare("UPDATE plantillas_personal SET pdf_campos = ? WHERE tipo = ?")
            ->execute([json_encode($decoded, JSON_UNESCAPED_UNICODE), $tipo]);

        return ['status' => 'success', 'message' => 'Campos PDF guardados.'];
    }

    public function obtenerCamposPdfPersonal(string $tipo): array {
        $this->ensurePlantillasPersonal();
        if (!in_array($tipo, ['diploma','certificado','dc3'], true))
            return ['status' => 'error', 'message' => 'tipo inválido.'];

        $stmt = $this->pdo->prepare("SELECT plantilla_pdf, pdf_campos FROM plantillas_personal WHERE tipo = ?");
        $stmt->execute([$tipo]);
        $row = $stmt->fetch();
        if (!$row) return ['status' => 'error', 'message' => 'Tipo no encontrado.'];

        $pdfUrl = ($row['plantilla_pdf'] ?? null)
            ? rtrim(UPLOAD_URL, '/') . '/plantillas/' . $row['plantilla_pdf']
            : null;
        return [
            'status'        => 'success',
            'plantilla_pdf' => $row['plantilla_pdf'] ?? null,
            'pdf_campos'    => json_decode($row['pdf_campos'] ?? '[]', true) ?: [],
            'pdf_url'       => $pdfUrl,
        ];
    }

    // ── Configuración SMTP ────────────────────────────────────

    public function getSmtpConfig(): array {
        $cfg = getSmtpConfig($this->pdo);
        unset($cfg['password']); // no exponer contraseña
        return ['status' => 'success', 'config' => $cfg];
    }

    public function saveSmtpConfig(array $payload): array {
        getSmtpConfig($this->pdo); // ensures table + row exist

        $allowed = ['host', 'port', 'encryption', 'username', 'password', 'from_email', 'from_name', 'firma_nombre', 'firma_web', 'firma_extra', 'asunto_cert', 'cuerpo_intro', 'firma_html'];
        $sets = [];
        $params = [];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $payload)) {
                $sets[]   = "`{$f}` = ?";
                $params[] = ($f === 'port') ? (int)$payload[$f] : ($payload[$f] === '' ? null : $payload[$f]);
            }
        }
        if (empty($sets)) return ['status' => 'error', 'message' => 'Sin campos para actualizar.'];

        try {
            $params[] = 1;
            $this->pdo->prepare("UPDATE avba_smtp_config SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
            return ['status' => 'success', 'message' => 'Configuración SMTP guardada.'];
        } catch (\PDOException $e) {
            return ['status' => 'error', 'message' => 'Error al guardar: ' . $e->getMessage()];
        }
    }

    public function testSmtpConfig(array $payload): array {
        $correo = trim($payload['correo'] ?? '');
        if (!$correo || !filter_var($correo, FILTER_VALIDATE_EMAIL))
            return ['status' => 'error', 'message' => 'Correo de prueba inválido.'];

        if (!class_exists('PHPMailer\PHPMailer\PHPMailer'))
            return ['status' => 'error', 'message' => 'PHPMailer no está disponible en el servidor.'];

        $cfg = getSmtpConfig($this->pdo);
        $info = sprintf('Host:%s | Puerto:%s | Cifrado:%s | Usuario:%s | Pass:%s',
            $cfg['host']       ?: '(vacío)',
            $cfg['port']       ?: '(vacío)',
            $cfg['encryption'] ?: '(vacío)',
            $cfg['username']   ?: '(vacío)',
            !empty($cfg['password']) ? '***(' . strlen((string)$cfg['password']) . ' chars)' : '(vacío)'
        );

        $debugLog = '';
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->SMTPDebug   = \PHPMailer\PHPMailer\SMTP::DEBUG_SERVER;
            $mail->Debugoutput = function(string $msg, int $level) use (&$debugLog) {
                $debugLog .= trim($msg) . "\n";
            };
            configurarMailer($mail, $this->pdo);
            $mail->addAddress($correo);
            $mail->Subject = 'Prueba SMTP — AVBA Certificaciones';
            $mail->isHTML(true);
            $mail->Body    = '<p>Correo de prueba desde el panel de administración AVBA.</p>';
            enviarYRegistrar($this->pdo, $mail, []);
            return ['status' => 'success', 'message' => "Correo enviado a {$correo}. Config: {$info}"];
        } catch (\Exception $e) {
            return [
                'status'  => 'error',
                'message' => 'Error: ' . $e->getMessage() . "\n\nConfig usada: " . $info . "\n\nDebug SMTP:\n" . ($debugLog ?: '(sin debug)')
            ];
        }
    }

    // ── Asignar plantilla HTML de dictamen a tipo de equipo ───
    public function setPlantillaDictHtml(array $payload): array {
        $tipoId = (int) ($payload['tipo_id'] ?? 0);
        $html   = trim($payload['plantilla_dict_html'] ?? '');

        if (!$tipoId) return ['status' => 'error', 'message' => 'tipo_id requerido.'];

        $this->ensureColumnsPdf(); // auto-aplicar migration_011 si es necesario

        // Ensure the column exists (migration_011)
        try {
            $this->pdo->exec(
                "ALTER TABLE maquinaria_tipos
                 ADD COLUMN IF NOT EXISTS plantilla_dict_html VARCHAR(100) NULL"
            );
        } catch (\Throwable $e) {}

        $allowed = [
            '', 'dictamen_preview.html', 'dictamen_montacargas.html',
            'dictamen_izaje.html', 'dictamen_ptem.html',
            'dictamen_grua_torre.html', 'dictamen_telehandler.html',
            'dictamen_mewp.html', 'dictamen_grua_oruga.html',
            'dictamen_grua_portico.html',
            // Faltaban: la siembra se las asigna a sus tipos, pero si Calidad
            // cambiaba la plantilla ya no podía regresarla.
            'dictamen_canastilla.html', 'dictamen_canastilla_sin_puerta.html',
            'dictamen_oreja_carga.html',
        ];
        if (!in_array($html, $allowed, true)) {
            return ['status' => 'error', 'message' => 'Plantilla HTML no válida.'];
        }

        $chk = $this->pdo->prepare("SELECT id FROM maquinaria_tipos WHERE id = ?");
        $chk->execute([$tipoId]);
        if (!$chk->fetch()) return ['status' => 'error', 'message' => 'Tipo de equipo no encontrado.'];

        $this->pdo->prepare(
            "UPDATE maquinaria_tipos SET plantilla_dict_html = ? WHERE id = ?"
        )->execute([$html ?: null, $tipoId]);

        return ['status' => 'success', 'message' => 'Plantilla HTML de dictamen asignada.'];
    }
}
