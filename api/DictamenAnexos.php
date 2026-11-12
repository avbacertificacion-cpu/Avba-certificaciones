<?php
/**
 * AVBA — Páginas técnicas de los dictámenes
 *
 * El dictamen de cada tipo de equipo lleva, además de su portada, fotos,
 * prueba de carga y recomendaciones, unas páginas que lo explican:
 *
 *   A · Descripción del equipo, metodología y marco normativo
 *   B · Qué se revisó: criterios de aceptación por componente y, si el
 *       inspector capturó el checklist, cada punto con su resultado
 *   C · Seguridad en la operación: riesgos y controles, revisión antes de
 *       operar, condiciones de paro inmediato y EPP
 *   D · (al final) Alcance y limitaciones, responsabilidades del usuario,
 *       glosario y validación del dictamen con su QR
 *
 * El contenido técnico vive aquí, por tipo de plantilla, y no dentro de las
 * plantillas: así se corrige en un solo lugar y las plantillas conservan la
 * estructura que el servidor rellena.
 */
class DictamenAnexos {

    /** Plantilla → perfil de contenido. */
    private const PLANTILLA_PERFIL = [
        'dictamen_preview.html'               => 'grua_movil',
        'dictamen_grua_torre.html'            => 'grua_torre',
        'dictamen_grua_torre_abatible.html'   => 'grua_torre_abatible',
        'dictamen_montacargas.html'           => 'montacargas',
        'dictamen_izaje.html'                 => 'izaje',
        'dictamen_ptem.html'                  => 'plataforma',
        'dictamen_mewp.html'                  => 'plataforma',
        'dictamen_telehandler.html'           => 'telehandler',
        'dictamen_grua_oruga.html'            => 'grua_oruga',
        'dictamen_grua_portico.html'          => 'grua_portico',
        'dictamen_canastilla.html'            => 'canastilla',
        'dictamen_canastilla_sin_puerta.html' => 'canastilla_sp',
        'dictamen_oreja_carga.html'           => 'oreja',
    ];

    /** Inserta las páginas en el HTML ya rellenado del dictamen. */
    public static function insertar(PDO $pdo, string $html, string $plantilla, array $d, array $items, string $qrB64): string {
        $perfil = self::PLANTILLA_PERFIL[basename($plantilla)] ?? null;
        if ($perfil === null) return $html;
        $p = self::perfil($perfil);
        if (!$p) return $html;

        // El logo del encabezado se toma de la propia plantilla: es el mismo
        // archivo incrustado, no se vuelve a cargar.
        $logo = '';
        if (preg_match('#<td class="d-logo">(.*?)</td>#s', $html, $m)) $logo = $m[1];

        $e     = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $salto = '<div style="page-break-after:always;height:0;line-height:0;font-size:0;overflow:hidden;"></div>';
        $hdr   = fn($t, $s) => '<table class="d-hdr"><tr><td class="d-logo">' . $logo . '</td>'
            . '<td class="d-emp"><div class="d-emp-n">AVBA INSPECTIONS</div>'
            . '<div class="d-emp-s">Certifications and Maintenance S.A.S. de C.V.</div>'
            . '<div class="d-emp-a">Unidad de Inspección Tipo A · Acreditada ante la EMA</div></td>'
            . '<td class="d-doc"><div class="d-doc-t">' . $t . '</div><div class="d-doc-e">' . $s . '</div>'
            . '<div class="d-doc-f"><span>FOLIO <b>' . $e(formatoFolio($d['control'] ?? '')) . '</b></span></div></td>'
            . '</tr></table><div class="d-regla"></div>';

        $equipo = $e(textoEstandar($d['maquinaria'] ?? '') . ' ' . ($d['marca'] ?? '') . ' ' . ($d['modelo'] ?? ''));

        // ── A · Equipo, metodología y normas ────────────────────────────────
        $a  = $hdr('Información Técnica', 'Equipo, metodología y marco normativo');
        $a .= self::titulo('Descripción del equipo', 'Equipment description');
        $a .= '<div class="ax-caja"><div class="ax-p">' . $p['descripcion'] . '</div>'
            . '<table class="ax-ficha"><tr>'
            . self::dato('Equipo', $equipo) . self::dato('No. de serie', $e($d['serie'] ?? ''))
            . self::dato('Capacidad', $e($d['capacidad'] ?? '')) . self::dato('Inspección', $e($d['fecha_fmt'] ?? ''))
            . '</tr></table></div>';

        $a .= self::titulo('Metodología de inspección', 'Inspection methodology');
        $etapas = [
            ['1', 'Identificación', 'Placa, número de serie, capacidad y configuración del equipo.'],
            ['2', 'Revisión documental', 'Manual, tabla de capacidades, bitácora y mantenimientos.'],
            ['3', 'Inspección visual y dimensional', 'Estructura, mecanismos y componentes críticos, punto por punto.'],
            ['4', 'Pruebas funcionales', 'Controles, frenos, dispositivos de seguridad y, cuando aplica, prueba de carga.'],
            ['5', 'Dictaminación', 'Evaluación contra los criterios de aceptación y emisión del resultado.'],
        ];
        $a .= '<table class="ax-etapas"><tr>';
        foreach ($etapas as $i => [$n, $t, $x]) {
            $a .= '<td class="' . ($i === 4 ? 'ax-et-fin' : '') . '"><div class="ax-et-n">' . $n . '</div>'
                . '<div class="ax-et-t">' . $t . '</div><div class="ax-et-x">' . $x . '</div></td>';
        }
        $a .= '</tr></table>';

        $a .= '<table class="ax-crit"><tr>'
            . '<td class="ax-c-ok"><b>C · Conforme</b><br>El punto cumple con el criterio de aceptación de la norma y del fabricante.</td>'
            . '<td class="ax-c-sep"></td>'
            . '<td class="ax-c-nc"><b>NC · No conforme</b><br>El punto presenta una condición de rechazo. Una sola NC crítica impide el dictamen favorable.</td>'
            . '<td class="ax-c-sep"></td>'
            . '<td class="ax-c-na"><b>N/A · No aplica</b><br>El componente no existe en esta configuración del equipo.</td>'
            . '</tr></table>';

        $a .= self::titulo('Marco normativo', 'Standards');
        $a .= '<table class="ax-tab"><thead><tr><th style="width:30%">Norma / documento</th><th>Título</th><th style="width:19%">Aplicación</th></tr></thead><tbody>';
        foreach (self::normasEquipo($pdo, (string)($d['maquinaria'] ?? '')) as [$clave, $tipo]) {
            $a .= '<tr><td class="ax-cl">' . $e($clave) . '</td><td>' . $e(self::tituloNorma($clave)) . '</td>'
                . '<td><span class="ax-tag ' . ($tipo === 'referencia' ? 'ax-tag-r' : 'ax-tag-a') . '">'
                . ($tipo === 'referencia' ? 'De referencia' : 'Acreditada') . '</span></td></tr>';
        }
        foreach ($p['refs'] as [$clave, $titulo]) {
            $a .= '<tr><td class="ax-cl">' . $clave . '</td><td>' . $titulo . '</td><td><span class="ax-tag ax-tag-c">Consulta técnica</span></td></tr>';
        }
        $a .= '</tbody></table>'
            . '<div class="ax-nota">Las normas <b>acreditadas</b> definen el alcance de la acreditación de AVBA ante la EMA para este tipo de equipo. '
            . 'Los documentos de <b>consulta técnica</b> se emplean como buena práctica de la industria para los criterios de inspección y uso seguro.</div>';

        // ── B · Qué se revisó ───────────────────────────────────────────────
        $b  = $hdr('Puntos de Inspección', 'Criterios de aceptación y resultados');
        $b .= self::titulo('Criterios de aceptación por componente', 'Acceptance criteria');
        $b .= '<table class="ax-tab"><thead><tr><th style="width:24%">Componente</th><th>Qué se verifica</th><th style="width:31%">Criterio de rechazo</th></tr></thead><tbody>';
        foreach ($p['criterios'] as [$c, $q, $r]) {
            $b .= '<tr><td class="ax-cl">' . $c . '</td><td>' . $q . '</td><td class="ax-rech">' . $r . '</td></tr>';
        }
        $b .= '</tbody></table>';
        $b .= self::checklist($items);

        // ── C · Seguridad ───────────────────────────────────────────────────
        $c  = $hdr('Seguridad en la Operación', 'Riesgos, controles y revisión diaria');
        $c .= self::titulo('Riesgos principales y medidas de control', 'Hazards and controls');
        $c .= '<table class="ax-tab"><thead><tr><th style="width:33%">Riesgo</th><th>Medida de control</th></tr></thead><tbody>';
        foreach ($p['riesgos'] as [$r, $m]) {
            $c .= '<tr><td class="ax-ries">' . $r . '</td><td>' . $m . '</td></tr>';
        }
        $c .= '</tbody></table>';
        $c .= '<table class="ax-dos"><tr>'
            . '<td class="ax-dos-i">' . self::titulo('Revisión antes de operar', 'Pre-use check') . '</td>'
            . '<td class="ax-dos-s"></td>'
            . '<td class="ax-dos-d">' . self::titulo('Paro inmediato', 'Stop work') . '</td></tr><tr>'
            . '<td class="ax-lista">' . self::lista($p['preuso'], 'ax-chk', '☐') . '</td>'
            . '<td class="ax-dos-s"></td>'
            . '<td class="ax-paro"><div class="ax-paro-t">Detener la operación y reportar si:</div>'
            . self::lista($p['paro'], 'ax-x', '✖') . '</td></tr></table>';
        $c .= self::titulo('Programa de inspecciones recomendado', 'Inspection program');
        $c .= '<table class="ax-prog"><tr>'
            . '<td><div class="ax-pg-h">Diaria</div><div class="ax-pg-q">Operador · antes de cada turno</div>Revisión visual y funcional con la lista de esta página; registro en bitácora.</td>'
            . '<td><div class="ax-pg-h">Frecuente</div><div class="ax-pg-q">Mantenimiento · mensual</div>Componentes de desgaste, cables, ganchos, frenos y dispositivos de seguridad.</td>'
            . '<td class="ax-pg-avba"><div class="ax-pg-h">Periódica</div><div class="ax-pg-q">Unidad de inspección · anual</div>Inspección completa y dictamen, como el presente documento.</td>'
            . '<td><div class="ax-pg-h">Especial</div><div class="ax-pg-q">Cuando ocurra</div>Después de un incidente, sobrecarga, reparación estructural, modificación o largo tiempo sin uso.</td>'
            . '</tr></table>';
        $c .= self::titulo('Equipo de protección personal', 'PPE');
        $c .= '<table class="ax-epp"><tr>';
        foreach ($p['epp'] as $x) $c .= '<td>' . $x . '</td>';
        $c .= '</tr></table>';

        // ── D · Cierre ──────────────────────────────────────────────────────
        $dd  = $hdr('Alcance y Validación', 'Condiciones del dictamen');
        $dd .= self::titulo('Alcance y limitaciones', 'Scope and limitations');
        $dd .= '<div class="ax-caja">' . self::lista([
            'El dictamen refleja las condiciones del equipo <b>observadas en la fecha de inspección</b> y en la configuración inspeccionada.',
            'La inspección es visual, dimensional y funcional; los ensayos no destructivos se aplican sólo donde se indica en este documento.',
            'No sustituye las inspecciones diarias del operador, las inspecciones frecuentes y periódicas ni el programa de mantenimiento del fabricante.',
            'Pierde validez si el equipo se modifica, repara estructuralmente, sufre un accidente o cambia de configuración sin una nueva evaluación.',
            'La vigencia es de un (1) año a partir de la fecha de inspección, condicionada al cumplimiento de las recomendaciones de este dictamen.',
        ], 'ax-pt', '■') . '</div>';
        $dd .= self::titulo('Responsabilidades del usuario', 'Owner responsibilities');
        $dd .= '<div class="ax-caja">' . self::lista([
            'Operar el equipo sólo con personal capacitado, evaluado y autorizado por escrito.',
            'Conservar a bordo o en sitio el manual, la tabla de capacidades y la bitácora del equipo.',
            'Atender las recomendaciones y mantener vigentes los programas de mantenimiento preventivo y correctivo.',
            'Reportar a AVBA cualquier modificación, reparación mayor o incidente relacionado con el equipo.',
        ], 'ax-pt', '■') . '</div>';
        $dd .= self::titulo('Glosario', 'Glossary');
        $dd .= '<table class="ax-tab ax-glo"><tbody>';
        foreach ($p['glosario'] as [$t, $x]) $dd .= '<tr><td class="ax-cl">' . $t . '</td><td>' . $x . '</td></tr>';
        $dd .= '</tbody></table>';
        $qr = $qrB64 !== '' ? '<img src="' . $qrB64 . '" style="width:27mm;height:27mm;">' : '';
        $dd .= '<table class="ax-val"><tr><td class="ax-val-qr">' . $qr . '</td><td class="ax-val-t">'
            . '<div class="ax-val-h">Valide este dictamen</div>'
            . '<div>Escanee el código QR o ingrese en <b>gestion.avba.com.mx/validar.html</b> el folio '
            . '<b class="ax-or">' . $e(formatoFolio($d['control'] ?? '')) . '</b> para confirmar que el documento es auténtico y está vigente.</div>'
            . '<div class="ax-val-s">Un dictamen que no aparece en la validación, o cuyos datos no coinciden, no debe considerarse válido.</div>'
            . '</td></tr></table>';

        // Las páginas técnicas van antes de las recomendaciones; el cierre, al final.
        $marca = '<!-- ════════ PÁGINA 3 ════════ -->';
        $pos   = strpos($html, $marca);
        if ($pos !== false) {
            $corte = strrpos(substr($html, 0, $pos), '<div style="page-break-after:always');
            if ($corte === false) $corte = $pos;
            $nuevas = $salto . '<div class="page ax">' . $a . '</div>'
                    . $salto . '<div class="page ax">' . $b . '</div>'
                    . $salto . '<div class="page ax">' . $c . '</div>';
            $html = substr($html, 0, $corte) . $nuevas . substr($html, $corte);
        }
        $fin  = strrpos($html, '</body>');
        if ($fin !== false) {
            $html = substr($html, 0, $fin) . $salto . '<div class="page ax">' . $dd . '</div>' . substr($html, $fin);
        }
        return str_replace('</head>', self::CSS . '</head>', $html);
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    private static function titulo(string $es, string $en): string {
        return '<div class="ax-h">' . $es . ' <span>· ' . $en . '</span></div>';
    }

    private static function dato(string $k, string $v): string {
        return '<td><div class="ax-k">' . $k . '</div><div class="ax-v">' . $v . '</div></td>';
    }

    private static function lista(array $xs, string $cls, string $vineta): string {
        $o = '<table class="ax-ul">';
        foreach ($xs as $x) $o .= '<tr><td class="' . $cls . '">' . $vineta . '</td><td>' . $x . '</td></tr>';
        return $o . '</table>';
    }

    /** El checklist capturado por el inspector, por sección, con su resultado. */
    public static function checklist(array $items): string {
        $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $items = array_values(array_filter($items, fn($i) => trim((string)($i['descripcion'] ?? '')) !== ''));
        if (!$items) {
            return self::titulo('Resultados por punto de inspección', 'Inspection checklist')
                . '<div class="ax-nota">Los puntos de inspección se evaluaron conforme a los criterios de aceptación anteriores. '
                . 'El registro detallado punto por punto se conserva en el expediente de la inspección.</div>';
        }
        $tot = ['C' => 0, 'NC' => 0, 'NA' => 0];
        foreach ($items as $i) { $v = strtoupper(trim((string)$i['valor'])); if (isset($tot[$v])) $tot[$v]++; }

        $o  = self::titulo('Resultados por punto de inspección', 'Inspection checklist');
        $o .= '<table class="ax-res"><tr>'
            . '<td class="ax-r-t"><div class="ax-r-n">' . array_sum($tot) . '</div><div class="ax-r-l">Puntos evaluados</div></td>'
            . '<td class="ax-r-c"><div class="ax-r-n">' . $tot['C'] . '</div><div class="ax-r-l">Conformes</div></td>'
            . '<td class="ax-r-x"><div class="ax-r-n">' . $tot['NC'] . '</div><div class="ax-r-l">No conformes</div></td>'
            . '<td class="ax-r-a"><div class="ax-r-n">' . $tot['NA'] . '</div><div class="ax-r-l">No aplica</div></td>'
            . '</tr></table>';
        $o .= '<table class="ax-tab ax-cl-tab"><thead><tr><th style="width:8%">#</th><th>Punto de inspección</th><th style="width:17%">Resultado</th></tr></thead><tbody>';
        $sec = null; $n = 0;
        foreach ($items as $i) {
            $s = (string)($i['seccion_nombre'] ?? '');
            if ($s !== $sec) {
                $sec = $s;
                $o .= '<tr><td colspan="3" class="ax-sec">' . $e($s !== '' ? $s : 'General') . '</td></tr>';
            }
            $n++;
            $v = strtoupper(trim((string)$i['valor']));
            [$cls, $txt] = match ($v) {
                'C'  => ['ax-v-c', '✔ Conforme'],
                'NC' => ['ax-v-nc', '✖ No conforme'],
                'NA' => ['ax-v-na', 'No aplica'],
                default => ['ax-v-na', '—'],
            };
            $o .= '<tr><td class="ax-num">' . $n . '</td><td>' . $e($i['descripcion']) . '</td><td class="' . $cls . '">' . $txt . '</td></tr>';
        }
        return $o . '</tbody></table>';
    }

    /** Normas acreditadas y de referencia dadas de alta para el tipo de equipo. */
    private static function normasEquipo(PDO $pdo, string $maquinaria): array {
        if ($maquinaria === '') return [];
        try {
            $st = $pdo->prepare(
                "SELECT mn.norma, mn.tipo FROM maquinaria_normas mn
                 INNER JOIN maquinaria_tipos t ON t.id = mn.maquinaria_tipo_id
                 WHERE t.nombre = ? ORDER BY mn.tipo, mn.norma"
            );
            $st->execute([$maquinaria]);
            return array_map(fn($r) => [(string)$r['norma'], (string)($r['tipo'] ?? 'acreditada')], $st->fetchAll());
        } catch (\Throwable $ex) {
            return [];
        }
    }

    /** Título de una norma conocida; las demás se dejan con su clave. */
    private static function tituloNorma(string $clave): string {
        $k = strtoupper(preg_replace('/\s+/', ' ', $clave));
        $t = [
            'NOM-006-STPS' => 'Manejo y almacenamiento de materiales — Condiciones de seguridad y salud en el trabajo.',
            'NOM-004-STPS' => 'Sistemas de protección y dispositivos de seguridad en la maquinaria y equipo.',
            'NOM-009-STPS' => 'Condiciones de seguridad para realizar trabajos en altura.',
            'NMX-GR-23814' => 'Grúas — Requisitos de competencia para inspectores de grúas (ISO 23814).',
            'NMX-GR-9927'  => 'Grúas — Inspecciones — Parte 1: Generalidades (ISO 9927-1).',
            'NMX-EC-17020' => 'Requisitos para el funcionamiento de diferentes tipos de unidades de inspección (ISO/IEC 17020).',
            'ASME B30.2'   => 'Grúas viajeras y grúas pórtico.',
            'ASME B30.3'   => 'Grúas torre.',
            'ASME B30.5'   => 'Grúas móviles y locomotoras.',
            'ASME B30.9'   => 'Eslingas.',
            'ASME B30.10'  => 'Ganchos.',
            'ASME B30.16'  => 'Polipastos aéreos.',
            'ASME B30.20'  => 'Dispositivos de izaje bajo el gancho.',
            'ASME B30.23'  => 'Sistemas de izaje de personal.',
            'ASME B30.26'  => 'Accesorios de aparejo (grilletes, cáncamos, tensores).',
            'ASME B56.1'   => 'Montacargas de baja y alta elevación.',
            'B56.1'        => 'Montacargas de baja y alta elevación.',
            'B56.6'        => 'Montacargas todo terreno.',
            'A92.20'       => 'Plataformas de elevación móviles de personal — Diseño y pruebas.',
            'A92.22'       => 'Plataformas de elevación móviles de personal — Uso seguro.',
            'ASTM E165'    => 'Inspección por líquidos penetrantes.',
            'ASTM E709'    => 'Inspección por partículas magnéticas.',
            'ISO 4309'     => 'Grúas — Cables de acero — Cuidado, mantenimiento, inspección y descarte.',
        ];
        foreach ($t as $pref => $tit) {
            if (str_starts_with($k, $pref) || str_contains($k, $pref)) return $tit;
        }
        return 'Norma aplicable al tipo de equipo.';
    }

    // ── Contenido por tipo de equipo ────────────────────────────────────────

    private static function perfil(string $id): ?array {
        $eppIzaje = ['Casco con barboquejo', 'Calzado de seguridad', 'Guantes de carnaza', 'Chaleco de alta visibilidad', 'Lentes de seguridad', 'Protección auditiva'];
        $eppAltura = ['Casco con barboquejo', 'Arnés de cuerpo completo', 'Línea de sujeción', 'Calzado de seguridad', 'Guantes', 'Chaleco de alta visibilidad'];

        $refsGrua = [
            ['ISO 4309', 'Grúas — Cables de acero — Cuidado, mantenimiento, inspección y descarte.'],
            ['ASME B30.10', 'Ganchos — inspección, criterios de retiro y uso.'],
            ['ISO 12480-1', 'Grúas — Uso seguro — Parte 1: Generalidades.'],
        ];
        $gloGrua = [
            ['Capacidad nominal', 'Carga máxima que el fabricante asigna al equipo para una configuración y radio determinados.'],
            ['Radio de operación', 'Distancia horizontal entre el eje de giro de la grúa y la vertical del gancho.'],
            ['Tabla de capacidades', 'Documento del fabricante que fija la carga permitida según radio, longitud de pluma y configuración.'],
            ['LMI / RCL', 'Limitador o indicador de momento de carga: avisa y bloquea los movimientos que llevarían a la sobrecarga.'],
            ['Anti-two-block', 'Dispositivo que impide que el motón choque con la punta de la pluma al izar.'],
            ['Zona de exclusión', 'Área bajo la carga y dentro del radio de giro donde no debe permanecer personal.'],
        ];

        $p = [];

        $p['grua_movil'] = [
            'descripcion' => 'Grúa móvil de pluma telescópica hidráulica, montada sobre camión o chasis autopropulsado, para el izaje y '
                . 'desplazamiento de cargas suspendidas dentro del rango de su tabla de capacidades. Su estabilidad depende de los '
                . 'estabilizadores, del terreno de apoyo y de la configuración de pluma y contrapeso; por eso cada maniobra debe '
                . 'planearse con el radio y el peso reales de la carga.',
            'refs' => array_merge([
                ['ASME B30.5', 'Grúas móviles y locomotoras — construcción, inspección, pruebas y operación.'],
                ['OSHA 29 CFR 1926 Subparte CC', 'Grúas y plumas en la construcción.'],
            ], $refsGrua),
            'criterios' => [
                ['Pluma telescópica', 'Secciones, soldaduras, patines de desgaste, pernos de articulación y cilindros de telescopeo.', 'Fisura, deformación permanente, soldadura no original o perno sin retención.'],
                ['Estabilizadores y chasis', 'Vigas, gatos, zapatas, válvulas de bloqueo y bastidor.', 'Viga deformada, gato con fuga o deriva, zapata faltante o dañada.'],
                ['Cable de acero', 'Hilos rotos, aplastamiento, jaula de pájaro, corrosión y diámetro.', 'Hilos rotos sobre el límite de ISO 4309 / ASME B30.5 o pérdida de diámetro mayor al 5%.'],
                ['Gancho y motón', 'Apertura de garganta, torsión, desgaste, poleas y pestillo de seguridad.', 'Apertura +5%, torsión mayor a 10°, fisura o pestillo inoperante.'],
                ['Sistema hidráulico', 'Fugas, mangueras, cilindros de elevación y válvulas de retención.', 'Fuga activa o descenso de pluma sin comando con carga.'],
                ['Dispositivos de seguridad', 'Limitador de momento (LMI), anti-two-block, indicador de ángulo y alarmas.', 'Dispositivo inoperante, puenteado o sin calibración.'],
                ['Cabina y controles', 'Retorno a neutro de palancas, frenos de giro y malacate, tabla de cargas a bordo.', 'Control que no regresa a neutro, freno deficiente o tabla ilegible.'],
            ],
            'riesgos' => [
                ['Volcadura por exceder radio o capacidad', 'Planear cada izaje con la tabla de capacidades; no superar el 85% de la capacidad nominal; LMI siempre activo.'],
                ['Hundimiento del terreno bajo los estabilizadores', 'Verificar la capacidad del suelo, usar placas de reparto y extender totalmente los estabilizadores.'],
                ['Contacto con líneas eléctricas', 'Mantener la distancia mínima de seguridad (nunca menos de 3 m) y asignar un señalero exclusivo.'],
                ['Caída de la carga', 'Usar accesorios inspeccionados y con capacidad marcada; pestillo cerrado; carga balanceada y amarrada.'],
                ['Golpe por carga suspendida o por el giro', 'Delimitar la zona de exclusión del radio de giro; nadie bajo la carga; usar cabos guía.'],
                ['Viento y condiciones climáticas', 'Suspender maniobras cuando el viento supere el límite del fabricante o haya tormenta eléctrica.'],
            ],
            'preuso' => ['Niveles de aceite, combustible y fugas visibles.', 'Estabilizadores extendidos, nivelados y sobre placas.', 'Cable, motón y gancho con pestillo.',
                'LMI, anti-two-block y alarmas funcionando.', 'Frenos y controles que regresan a neutro.', 'Tabla de capacidades y nivel de burbuja a bordo.',
                'Área de trabajo delimitada y libre de líneas eléctricas.', 'Registro de la revisión en la bitácora.'],
            'paro' => ['El limitador de momento falla o indica sobrecarga.', 'Hay fuga hidráulica activa o la pluma desciende sola.', 'El cable presenta hilos rotos, aplastamiento o deformación.',
                'Un estabilizador se hunde o pierde nivel.', 'Viento fuera de límite o tormenta eléctrica.'],
            'epp' => $eppIzaje,
            'glosario' => $gloGrua,
        ];

        $torre = [
            'descripcion' => 'Grúa torre de montaje fijo o sobre base, con torre vertical de celosía y pluma horizontal sobre la que corre '
                . 'el carro, empleada en obra para el izaje repetitivo de materiales. Su capacidad disminuye con el radio según la '
                . 'curva de carga del fabricante, y la altura bajo gancho la da la torre: por eso la configuración de montaje '
                . '(tramos, anclajes y lastre) es parte de lo que se inspecciona.',
            'refs' => array_merge([
                ['ASME B30.3', 'Grúas torre — construcción, montaje, inspección, pruebas y operación.'],
                ['EN 14439', 'Grúas — Grúas torre — Requisitos de seguridad.'],
                ['NOM-009-STPS-2011', 'Trabajos en altura: ascenso, montaje y mantenimiento en la torre.'],
            ], $refsGrua),
            'criterios' => [
                ['Torre, base y anclajes', 'Tramos de celosía, pernos de unión y su apriete, base, lastre y arriostramientos.', 'Fisura, perno flojo o faltante, corrosión con pérdida de sección.'],
                ['Pluma, contrapluma y tirantes', 'Cordones, diagonales, uniones, tirantes y contrapesos.', 'Cordón doblado o abollado, unión dañada, contrapeso suelto.'],
                ['Carro, malacate y cable', 'Rodillos del carro, tambor, enrollado y estado del cable.', 'Hilos rotos sobre el límite de ISO 4309, enrollado irregular o freno deficiente.'],
                ['Mecanismo de orientación', 'Corona de giro, piñón, motorreductores y freno de orientación.', 'Holgura excesiva, ruido anormal o freno que no retiene.'],
                ['Limitadores', 'Carga máxima, momento, carrera del carro, altura de gancho, giro y anemómetro.', 'Cualquier limitador inoperante, puenteado o mal ajustado.'],
                ['Instalación eléctrica', 'Tablero, protecciones, tierra física y cableado.', 'Tierra física ausente, conductores dañados o protecciones puenteadas.'],
                ['Cabina o control remoto', 'Paro de emergencia, sentido de mandos rotulado y comunicación.', 'Paro de emergencia inoperante o mandos sin identificar.'],
            ],
            'riesgos' => [
                ['Sobrecarga por el radio', 'Respetar la curva de carga en cada radio; limitadores de carga y momento siempre activos.'],
                ['Interferencia con otras grúas o estructuras', 'Plan anticolisión, alturas escalonadas y zonas restringidas de giro.'],
                ['Viento', 'Suspender el izaje al alcanzar el límite del fabricante; dejar la grúa en veleta (giro libre) al terminar la jornada.'],
                ['Caída de objetos', 'Zona de exclusión bajo la trayectoria de la carga; cargas sueltas en contenedor o red.'],
                ['Caída de altura en la torre', 'Ascenso con arnés y línea de vida conforme a NOM-009-STPS-2011.'],
                ['Riesgo eléctrico', 'Tierra física verificada, tablero cerrado y mantenimiento por personal calificado.'],
            ],
            'preuso' => ['Prueba de limitadores sin carga.', 'Frenos de elevación, carro y orientación.', 'Cable, motón y gancho con pestillo.', 'Anemómetro y alarma de viento.',
                'Paro de emergencia y mandos.', 'Zona bajo la carga despejada.', 'Comunicación por radio con el señalero.', 'Registro en la bitácora.'],
            'paro' => ['Un limitador falla o se activa sin causa.', 'El viento supera el límite del fabricante.', 'Ruido o vibración anormal en la orientación o el carro.',
                'Daño visible en el cable.', 'Tormenta eléctrica cercana.'],
            'epp' => $eppAltura,
            'glosario' => array_merge(array_slice($gloGrua, 0, 3), [
                ['Carro', 'Mecanismo que se desplaza sobre la pluma y fija el radio de la carga.'],
                ['Altura bajo gancho', 'Distancia vertical del piso al gancho en su posición más alta.'],
                ['Veleta', 'Posición fuera de servicio con el freno de giro liberado, para que la pluma se oriente con el viento.'],
            ]),
        ];
        $p['grua_torre'] = $torre;

        $ab = $torre;
        $ab['descripcion'] = 'Grúa torre de pluma abatible (luffing), en la que la pluma se inclina para fijar el radio de trabajo en lugar de '
            . 'desplazar un carro. Ocupa menos espacio de giro que una de pluma horizontal, por lo que es común en obras con '
            . 'edificios o grúas vecinas. Su capacidad depende del ángulo de pluma, que se lee del indicador de la propia grúa.';
        $ab['criterios'][2] = ['Malacates de elevación y abatimiento', 'Tambores, frenos, enrollado y estado de los cables.', 'Hilos rotos sobre el límite de ISO 4309 o freno de abatimiento deficiente.'];
        $ab['criterios'][4] = ['Limitadores', 'Carga, momento, ángulo mínimo y máximo de pluma, altura, giro y anemómetro.', 'Cualquier limitador inoperante, puenteado o mal ajustado.'];
        $ab['glosario'][3] = ['Ángulo de pluma', 'Inclinación de la pluma respecto a la horizontal; fija el radio de trabajo.'];
        $p['grua_torre_abatible'] = $ab;

        $p['montacargas'] = [
            'descripcion' => 'Montacargas de contrapeso con mástil y horquillas, para la elevación, transporte y estiba de cargas paletizadas. '
                . 'Su capacidad se indica a un centro de carga determinado: cuando la carga se aleja del respaldo o el mástil se '
                . 'eleva o inclina, la capacidad real disminuye, como lo muestra la placa del fabricante.',
            'refs' => [
                ['ANSI/ITSDF B56.1', 'Norma de seguridad para montacargas de baja y alta elevación.'],
                ['ISO 5057', 'Inspección y reparación de horquillas en servicio.'],
                ['OSHA 29 CFR 1910.178', 'Vehículos industriales motorizados.'],
                ['NOM-004-STPS-1999', 'Sistemas de protección y dispositivos de seguridad en maquinaria.'],
            ],
            'criterios' => [
                ['Horquillas', 'Espesor del talón, ángulo, diferencia de altura entre puntas, fisuras y seguros.', 'Desgaste del talón ≥10%, ángulo desviado más de 3° o fisura.'],
                ['Mástil y cadenas', 'Canales, rodillos, cadenas de elevación, anclajes y lubricación.', 'Cadena con elongación ≥3%, eslabón fisurado o anclaje dañado.'],
                ['Sistema hidráulico', 'Cilindros de elevación e inclinación, mangueras y válvulas.', 'Fuga activa o descenso del mástil sin comando.'],
                ['Frenos y dirección', 'Freno de servicio, freno de estacionamiento y juego de dirección.', 'Freno que no detiene o no retiene en pendiente.'],
                ['Protecciones', 'Protector superior, respaldo de carga y cinturón de seguridad.', 'Protector dañado o modificado, cinturón faltante.'],
                ['Señalización', 'Alarma de reversa, torreta, claxon, luces y placa de capacidad.', 'Placa ilegible o faltante, alarma inoperante.'],
            ],
            'riesgos' => [
                ['Volcadura lateral', 'Velocidad moderada en curvas, carga baja al desplazarse y uso obligatorio del cinturón.'],
                ['Volcadura frontal', 'No exceder la capacidad de la placa al centro de carga; no elevar la carga en movimiento.'],
                ['Atropellamiento', 'Rutas definidas, separación de peatones, alarma de reversa y torreta.'],
                ['Caída de la carga', 'Carga centrada y asegurada, mástil inclinado hacia atrás al transportar.'],
                ['Caída desde andén', 'Calzas en el transporte, andenes señalizados y placas niveladoras en buen estado.'],
                ['Gases o carga de baterías', 'Ventilación adecuada; carga de baterías en área designada con EPP.'],
            ],
            'preuso' => ['Horquillas y seguros.', 'Cadenas y mástil.', 'Fugas hidráulicas.', 'Frenos de servicio y estacionamiento.', 'Dirección y claxon.',
                'Alarma de reversa, torreta y luces.', 'Cinturón de seguridad.', 'Nivel de combustible, gas o carga de batería.'],
            'paro' => ['Frenos o dirección deficientes.', 'Fuga hidráulica activa.', 'Horquilla fisurada o doblada.', 'Cadena dañada o mástil que desciende solo.', 'Alarma de reversa inoperante.'],
            'epp' => ['Casco', 'Calzado de seguridad', 'Chaleco de alta visibilidad', 'Guantes', 'Lentes de seguridad', 'Cinturón del equipo abrochado'],
            'glosario' => [
                ['Centro de carga', 'Distancia del respaldo de las horquillas al centro de gravedad de la carga.'],
                ['Capacidad nominal', 'Carga máxima a un centro de carga y altura definidos por el fabricante.'],
                ['Mástil', 'Estructura vertical por la que se elevan las horquillas.'],
                ['Respaldo de carga', 'Rejilla que evita que la carga caiga hacia el operador.'],
                ['Protector superior', 'Estructura que protege al operador de objetos que caen.'],
                ['Triángulo de estabilidad', 'Zona entre los puntos de apoyo dentro de la cual debe mantenerse el centro de gravedad.'],
            ],
        ];

        $p['izaje'] = [
            'descripcion' => 'Elementos y accesorios de izaje —cadenas, eslabones, ganchos, grilletes y dispositivos bajo el gancho— que '
                . 'conectan la carga con el equipo de levante. Cada elemento debe portar su capacidad marcada y trabajar dentro de '
                . 'su Carga Máxima de Utilización (CML), considerando el ángulo de eslingado y el tipo de amarre.',
            'refs' => [
                ['ASME B30.9', 'Eslingas.'],
                ['ASME B30.10', 'Ganchos.'],
                ['ASME B30.20', 'Dispositivos de izaje bajo el gancho.'],
                ['ASME B30.26', 'Accesorios de aparejo: grilletes, cáncamos y tensores.'],
            ],
            'criterios' => [
                ['Marcado', 'Etiqueta o estampado con fabricante, grado y CML.', 'Marcado ilegible o faltante.'],
                ['Cuerpo y eslabones', 'Desgaste, elongación, muescas, torceduras y corrosión.', 'Desgaste o elongación sobre el límite del fabricante, eslabón torcido.'],
                ['Ganchos', 'Apertura de garganta, torsión, fisuras y pestillo.', 'Apertura +5%, torsión mayor a 10°, fisura o pestillo inoperante.'],
                ['Puntos de conexión', 'Anillos maestros, grilletes y pernos.', 'Perno no original, deformación o roscas dañadas.'],
                ['Tratamiento superficial', 'Daño por calor, salpicadura de soldadura o químicos.', 'Evidencia de calor o daño químico.'],
                ['Prueba de carga', 'Aplicación de 2 × CML y revisión posterior.', 'Deformación permanente o fisura tras la prueba.'],
            ],
            'riesgos' => [
                ['Sobrecarga por ángulo de eslingado', 'Calcular la tensión por ramal: a 30° de la horizontal la tensión en cada ramal es el doble de la carga que soporta.'],
                ['Carga de choque', 'Izar de forma gradual, sin tirones ni caídas libres.'],
                ['Corte por aristas vivas', 'Usar protectores en esquinas y bordes de la carga.'],
                ['Uso de elementos sin marcado', 'Retirar de servicio todo elemento sin capacidad identificable.'],
                ['Daño por calor o químicos', 'Almacenar en lugar seco y lejos de fuentes de calor; inspeccionar tras exposición.'],
            ],
            'preuso' => ['Marcado de capacidad legible.', 'Ganchos con pestillo funcional.', 'Sin eslabones torcidos ni estirados.', 'Grilletes con perno original y asegurado.',
                'Ángulo de eslingado dentro de lo planeado.', 'Protectores para aristas vivas.'],
            'paro' => ['Elemento sin marcado de capacidad.', 'Deformación, fisura o desgaste visible.', 'Pestillo de gancho inoperante.', 'Carga mayor a la CML considerando el ángulo.'],
            'epp' => $eppIzaje,
            'glosario' => [
                ['CML', 'Carga Máxima de Utilización asignada por el fabricante.'],
                ['Ángulo de eslingado', 'Ángulo de cada ramal respecto a la horizontal; a menor ángulo, mayor tensión.'],
                ['Anillo maestro', 'Eslabón principal que se coloca en el gancho de la grúa.'],
                ['Pestillo', 'Seguro que cierra la garganta del gancho.'],
                ['Factor de diseño', 'Relación entre la carga de ruptura y la CML.'],
                ['Carga de prueba', 'Carga aplicada para verificar el elemento; en este dictamen, 2 × CML.'],
            ],
        ];

        $p['plataforma'] = [
            'descripcion' => 'Plataforma de elevación móvil de personal (tijera o brazo articulado/telescópico) para trabajos temporales en altura. '
                . 'La plataforma está diseñada para elevar personas, herramientas y materiales dentro de su capacidad nominal; su '
                . 'estabilidad depende de la nivelación, del terreno y de los sensores de inclinación y sobrecarga.',
            'refs' => [
                ['ANSI/SAIA A92.20', 'Plataformas de elevación móviles de personal — Diseño, cálculo y pruebas.'],
                ['ANSI/SAIA A92.22', 'Plataformas de elevación móviles de personal — Uso seguro.'],
                ['ANSI/SAIA A92.24', 'Requisitos de capacitación para operadores y ocupantes.'],
                ['NOM-009-STPS-2011', 'Condiciones de seguridad para realizar trabajos en altura.'],
            ],
            'criterios' => [
                ['Estructura de elevación', 'Brazos, tijera, pernos, soldaduras y cilindros.', 'Fisura, deformación o perno sin retención.'],
                ['Plataforma y barandales', 'Barandales completos, rodapié, puerta con cierre y piso.', 'Barandal faltante o suelto, puerta sin cierre.'],
                ['Anclajes para arnés', 'Puntos de anclaje identificados y en buen estado.', 'Anclaje dañado o inexistente.'],
                ['Sistema hidráulico', 'Fugas, mangueras y bajada de emergencia.', 'Fuga activa o bajada de emergencia inoperante.'],
                ['Controles', 'Mandos en plataforma y en base, paro de emergencia y hombre muerto.', 'Paro de emergencia o mando inoperante.'],
                ['Dispositivos de seguridad', 'Sensor de inclinación, de sobrecarga, alarmas y frenos.', 'Sensor o alarma inoperante.'],
                ['Placa de capacidad', 'Capacidad, número de ocupantes y velocidad de viento permitida.', 'Placa ilegible o faltante.'],
            ],
            'riesgos' => [
                ['Volcadura', 'Operar en terreno firme y nivelado; respetar el sensor de inclinación y el límite de viento.'],
                ['Caída desde la plataforma', 'Puerta cerrada; en plataformas de brazo, arnés con línea de sujeción corta al anclaje designado.'],
                ['Aplastamiento contra estructuras', 'Movimientos lentos cerca de techos y vigas; vigilancia desde el piso.'],
                ['Contacto eléctrico', 'Mantener la distancia mínima a líneas energizadas (nunca menos de 3 m).'],
                ['Colisión con vehículos', 'Delimitar el área de trabajo con conos o barreras.'],
                ['Sobrecarga', 'No exceder la capacidad ni el número de ocupantes de la placa.'],
            ],
            'preuso' => ['Controles de base y plataforma.', 'Paro de emergencia y bajada de emergencia.', 'Barandales, puerta y anclajes.', 'Fugas hidráulicas.',
                'Llantas y estabilizadores.', 'Alarmas y sensor de inclinación.', 'Terreno y obstáculos en altura.', 'Manual del operador a bordo.'],
            'paro' => ['La alarma de inclinación o de sobrecarga se activa.', 'Fuga hidráulica activa.', 'Un mando o el paro de emergencia no responde.',
                'Viento fuera del límite de la placa.', 'Proximidad a líneas eléctricas.'],
            'epp' => $eppAltura,
            'glosario' => [
                ['Plataforma de trabajo', 'Canastilla o piso con barandal donde se ubican los ocupantes.'],
                ['Capacidad nominal', 'Peso máximo de personas, herramientas y materiales en la plataforma.'],
                ['Bajada de emergencia', 'Sistema para descender la plataforma si falla la energía.'],
                ['Sensor de inclinación', 'Dispositivo que impide elevar o desplazar fuera del ángulo permitido.'],
                ['Línea de sujeción', 'Cuerda o cinta que une el arnés con el anclaje.'],
                ['Hombre muerto', 'Pedal o interruptor que debe mantenerse presionado para operar.'],
            ],
        ];

        $p['telehandler'] = [
            'descripcion' => 'Manipulador telescópico (telehandler) con pluma extensible, para elevar y colocar cargas a distancia y en altura '
                . 'con horquillas u otros accesorios. Su estabilidad longitudinal disminuye al extender y elevar la pluma, por lo '
                . 'que cada accesorio tiene su propia tabla de capacidades.',
            'refs' => [
                ['ANSI/ITSDF B56.6', 'Montacargas todo terreno.'],
                ['ISO 10896-1', 'Montacargas todo terreno — Requisitos de seguridad — Parte 1: Manipuladores telescópicos.'],
                ['OSHA 29 CFR 1910.178', 'Vehículos industriales motorizados.'],
            ],
            'criterios' => [
                ['Pluma telescópica', 'Secciones, patines de desgaste, cadenas o cables de extensión y pernos.', 'Fisura, deformación o holgura excesiva.'],
                ['Horquillas y accesorios', 'Horquillas, placa de acoplamiento, seguros y placa de capacidad del accesorio.', 'Desgaste ≥10% del talón, seguro faltante o accesorio sin placa.'],
                ['Estabilizadores y nivelación', 'Gatos, zapatas y sistema de nivelación del chasis.', 'Fuga, deriva o nivelación inoperante.'],
                ['Frenos, transmisión y dirección', 'Freno de servicio y estacionamiento, modos de dirección.', 'Freno deficiente o dirección con juego excesivo.'],
                ['Limitador de momento', 'Indicador de estabilidad longitudinal y bloqueo de movimientos.', 'Indicador inoperante o puenteado.'],
                ['Cabina', 'Estructura ROPS/FOPS, cinturón, visibilidad y tablas de carga.', 'Cabina dañada, cinturón faltante o tablas ilegibles.'],
            ],
            'riesgos' => [
                ['Volcadura longitudinal', 'Usar la tabla del accesorio instalado y respetar el limitador de momento.'],
                ['Volcadura lateral', 'Nivelar el chasis antes de elevar; no desplazarse con la pluma elevada.'],
                ['Elevación de personas', 'Sólo con plataforma aprobada por el fabricante para ese modelo.'],
                ['Atropellamiento por poca visibilidad', 'Señalero, alarma de reversa y rutas despejadas.'],
                ['Contacto eléctrico', 'Mantener la distancia mínima a líneas energizadas (nunca menos de 3 m).'],
            ],
            'preuso' => ['Horquillas o accesorio bien acoplado.', 'Fugas hidráulicas.', 'Frenos y dirección.', 'Limitador de momento.', 'Estabilizadores y nivelación.',
                'Cinturón y espejos.', 'Alarma de reversa y luces.', 'Tabla de capacidades del accesorio a bordo.'],
            'paro' => ['El limitador de momento se activa o falla.', 'Fuga hidráulica activa.', 'Frenos o dirección deficientes.', 'Accesorio sin seguro o sin placa.', 'Terreno inestable o desnivelado.'],
            'epp' => $eppIzaje,
            'glosario' => [
                ['Alcance', 'Distancia horizontal desde las ruedas delanteras hasta el centro de la carga.'],
                ['Tabla de capacidades', 'Gráfica del fabricante con la carga permitida según ángulo y extensión.'],
                ['Limitador de momento', 'Sistema que vigila la estabilidad longitudinal y bloquea movimientos de riesgo.'],
                ['ROPS / FOPS', 'Protección contra volcadura y contra caída de objetos.'],
                ['Nivelación del chasis', 'Ajuste lateral del bastidor para trabajar en terreno con pendiente.'],
                ['Accesorio', 'Horquillas, plumín, cucharón o plataforma acoplada a la pluma.'],
            ],
        ];

        $p['grua_oruga'] = [
            'descripcion' => 'Grúa estructural sobre orugas con pluma de celosía, empleada en izajes de gran capacidad y montaje industrial. '
                . 'Las orugas reparten la carga sobre el terreno y permiten desplazarse en obra; puede trabajar con gancho principal '
                . 'y auxiliar (plumín), cada uno con su propia tabla de capacidades.',
            'refs' => array_merge([
                ['ASME B30.5', 'Grúas móviles y locomotoras (incluye grúas sobre orugas).'],
                ['OSHA 29 CFR 1926.1404', 'Montaje y desmontaje de grúas.'],
            ], $refsGrua),
            'criterios' => [
                ['Pluma y plumín de celosía', 'Cordones principales, diagonales, uniones y pernos de sección.', 'Cordón doblado o abollado, diagonal dañada, perno sin retención.'],
                ['Tirantes y mástil', 'Péndulos, tirantes y mástil de abatimiento.', 'Tirante dañado o conexión deformada.'],
                ['Tren de rodaje', 'Orugas, zapatas, rodillos, ruedas guía y tensado.', 'Zapata fisurada o tensado fuera de especificación.'],
                ['Giro y traslación', 'Corona, piñones, frenos de giro y traslación.', 'Holgura excesiva o freno que no retiene.'],
                ['Malacates y cables', 'Malacates principal y auxiliar, tambores y cables.', 'Hilos rotos sobre el límite de ISO 4309 o freno deficiente.'],
                ['Dispositivos de seguridad', 'LMI, anti-two-block en ambos ganchos, indicador de ángulo y limitadores.', 'Dispositivo inoperante o sin calibrar.'],
            ],
            'riesgos' => [
                ['Falla del terreno', 'Evaluar la presión sobre el suelo y usar esteras o tapetes de reparto.'],
                ['Desplazamiento con carga', 'Sólo si el fabricante lo permite, con la pluma alineada y la carga baja.'],
                ['Montaje y desmontaje de pluma', 'Nadie bajo la pluma al retirar pernos; seguir el procedimiento del fabricante.'],
                ['Uso de dos ganchos', 'No exceder la capacidad de cada gancho ni la combinada de la tabla.'],
                ['Viento', 'Suspender maniobras al alcanzar el límite del fabricante para la longitud de pluma.'],
                ['Contacto eléctrico', 'Mantener la distancia mínima a líneas energizadas (nunca menos de 3 m).'],
            ],
            'preuso' => ['Pernos y retenciones de la pluma.', 'Cables y enrollado en tambores.', 'Ganchos con pestillo.', 'LMI y anti-two-block.', 'Frenos de malacates y giro.',
                'Orugas y terreno.', 'Zona de exclusión delimitada.', 'Registro en la bitácora.'],
            'paro' => ['El LMI falla o indica sobrecarga.', 'Daño en un cordón de la pluma.', 'Hundimiento de una oruga.', 'Daño en el cable.', 'Viento fuera de límite.'],
            'epp' => $eppIzaje,
            'glosario' => array_merge(array_slice($gloGrua, 0, 4), [
                ['Plumín', 'Extensión de la pluma que lleva el gancho auxiliar.'],
                ['Pluma de celosía', 'Pluma formada por cordones y diagonales soldados.'],
            ]),
        ];

        $p['grua_portico'] = [
            'descripcion' => 'Grúa pórtico de una o dos vigas apoyadas sobre patas, con traslación sobre rieles o ruedas, y carro con polipasto '
                . 'o malacate para el izaje de cargas en patios, talleres y naves. Su capacidad la fija el polipasto y la viga; la '
                . 'flecha que toma la viga con la carga es un indicador directo de su estado.',
            'refs' => [
                ['ASME B30.2', 'Grúas viajeras y grúas pórtico.'],
                ['ASME B30.16', 'Polipastos aéreos.'],
                ['ASME B30.10', 'Ganchos.'],
                ['CMAA 70 / 74', 'Especificaciones para grúas viajeras de una y dos vigas.'],
            ],
            'criterios' => [
                ['Vigas y patas', 'Soldaduras, uniones atornilladas, flecha y alineación.', 'Fisura, deformación o flecha permanente tras la prueba.'],
                ['Rieles y ruedas', 'Alineación de rieles, pestañas y desgaste de ruedas.', 'Rueda con desgaste excesivo o riel desalineado.'],
                ['Polipasto o malacate', 'Frenos, tambor, guía de cable o cadena.', 'Freno que no retiene la carga.'],
                ['Cable o cadena y gancho', 'Hilos rotos, elongación, apertura y pestillo del gancho.', 'Hilos rotos sobre el límite, apertura +5% o pestillo inoperante.'],
                ['Limitadores y topes', 'Fin de carrera de elevación y traslación, topes y amortiguadores.', 'Fin de carrera inoperante o tope faltante.'],
                ['Botonera o radio', 'Paro de emergencia y sentido de movimientos rotulado.', 'Paro de emergencia inoperante o mandos sin identificar.'],
            ],
            'riesgos' => [
                ['Carga suspendida sobre personas', 'Zona de exclusión bajo la trayectoria; alarma de traslación.'],
                ['Colisión en la traslación', 'Topes, fines de carrera y área de rodamiento despejada.'],
                ['Tiro lateral o arrastre de cargas', 'Izar siempre en vertical; prohibido arrastrar cargas con la grúa.'],
                ['Viento en exteriores', 'Anclajes de tormenta y frenos aplicados fuera de servicio.'],
                ['Riesgo eléctrico', 'Cable de alimentación protegido y desconectador accesible.'],
            ],
            'preuso' => ['Paro de emergencia de la botonera.', 'Frenos de elevación y traslación.', 'Fines de carrera.', 'Cable o cadena y gancho.', 'Rieles y área de rodamiento libres.', 'Alarma de traslación.'],
            'paro' => ['El freno no retiene la carga.', 'Un fin de carrera no actúa.', 'Ruido o vibración anormal en la traslación.', 'Daño en cable o cadena.', 'Viento fuera de límite en exteriores.'],
            'epp' => $eppIzaje,
            'glosario' => [
                ['Claro', 'Distancia entre los apoyos de la viga.'],
                ['Flecha', 'Deformación vertical de la viga bajo carga.'],
                ['Carro', 'Mecanismo que se desplaza sobre la viga y lleva el polipasto.'],
                ['Fin de carrera', 'Interruptor que detiene el movimiento al llegar a su límite.'],
                ['Polipasto', 'Mecanismo de elevación con cable o cadena.'],
                ['Botonera', 'Mando colgante o inalámbrico de la grúa.'],
            ],
        ];

        $p['canastilla'] = [
            'descripcion' => 'Canastilla estructural suspendida del gancho de una grúa, diseñada exclusivamente para el izaje de personal cuando '
                . 'no existe un medio de acceso más seguro. Debe portar su peso propio, capacidad y número máximo de ocupantes, y '
                . 'trabajar con un aparejo de suspensión dedicado sólo a ella.',
            'refs' => [
                ['ASME B30.23', 'Sistemas de izaje de personal.'],
                ['OSHA 29 CFR 1926.1431', 'Izaje de personal con grúas.'],
                ['NOM-009-STPS-2011', 'Condiciones de seguridad para realizar trabajos en altura.'],
                ['AWS D1.1', 'Código de soldadura estructural — Acero.'],
            ],
            'criterios' => [
                ['Estructura y soldaduras', 'Perfiles, uniones soldadas y piso antiderrapante.', 'Fisura, deformación o piso dañado.'],
                ['Barandal y rodapié', 'Barandal superior entre 0.99 y 1.14 m, intermedio y rodapié.', 'Barandal incompleto o fuera de altura.'],
                ['Puerta', 'Abre hacia adentro y tiene cierre positivo.', 'Puerta que abre hacia afuera o sin cierre.'],
                ['Suspensión', 'Orejas, grilletes con perno asegurado y aparejo exclusivo.', 'Grillete sin seguro o aparejo compartido con cargas.'],
                ['Anclajes para arnés', 'Uno por ocupante, independientes del sistema de suspensión.', 'Anclaje faltante o dañado.'],
                ['Placa', 'Peso propio, capacidad y número máximo de ocupantes.', 'Placa ilegible o faltante.'],
                ['Prueba de carga', 'Carga del 125% de la capacidad y revisión posterior.', 'Deformación permanente tras la prueba.'],
            ],
            'riesgos' => [
                ['Caída de los ocupantes', 'Arnés de cuerpo completo conectado al anclaje designado durante todo el izaje.'],
                ['Falla del aparejo', 'Aparejo exclusivo para personal, inspeccionado antes de cada uso.'],
                ['Sobrecarga de la grúa', 'Peso total (canastilla, personas y herramientas) no mayor al 50% de la capacidad de la grúa en ese radio.'],
                ['Golpes contra la estructura', 'Cabos guía, movimientos lentos y comunicación permanente con el operador.'],
                ['Contacto eléctrico', 'Mantener la distancia mínima a líneas energizadas (nunca menos de 3 m).'],
                ['Viento', 'Suspender el izaje de personal con viento o tormenta.'],
            ],
            'preuso' => ['Izaje de prueba sin personal con carga equivalente.', 'Grilletes asegurados y pestillo del gancho cerrado.', 'Puerta cerrada y con seguro.', 'Arneses conectados.',
                'Peso total calculado contra la tabla de la grúa.', 'Comunicación con el operador.', 'Reunión previa con todos los participantes.'],
            'paro' => ['Pérdida de comunicación con el operador.', 'Viento o lluvia intensa.', 'Daño visible en el aparejo o la estructura.', 'Ocupante sin arnés conectado.', 'Proximidad a líneas eléctricas.'],
            'epp' => $eppAltura,
            'glosario' => [
                ['Peso propio (tara)', 'Peso de la canastilla vacía.'],
                ['Capacidad nominal', 'Peso máximo de personas, herramientas y materiales.'],
                ['Cierre positivo', 'Seguro que impide la apertura accidental de la puerta.'],
                ['Aparejo de suspensión', 'Eslingas y grilletes que unen la canastilla con el gancho.'],
                ['Izaje de prueba', 'Recorrido previo sin personal con una carga equivalente.'],
                ['Anclaje', 'Punto de conexión del arnés, independiente de la suspensión.'],
            ],
        ];
        $sp = $p['canastilla'];
        $sp['descripcion'] = 'Canastilla estructural de acceso abierto —sin puerta; el ingreso es por la parte superior—, suspendida del gancho de una '
            . 'grúa y destinada exclusivamente al izaje de personal. Al no tener puerta, el canto superior, el asidero y el apoyo de '
            . 'ingreso son parte de su seguridad, junto con un anclaje para arnés por cada ocupante.';
        $sp['criterios'][2] = ['Ingreso superior', 'Canto redondeado, asidero y apoyo para entrar y salir.', 'Canto vivo, asidero dañado o apoyo faltante.'];
        $p['canastilla_sp'] = $sp;

        $p['oreja'] = [
            'descripcion' => 'Oreja de carga (placa de izaje) soldada o atornillada a una estructura o equipo, que sirve como punto de conexión '
                . 'para grilletes. Está diseñada para trabajar en el plano de la placa: una carga fuera de ese plano reduce su '
                . 'capacidad, por lo que la orientación respecto a la dirección de la carga forma parte de la inspección.',
            'refs' => [
                ['ASME BTH-1', 'Diseño de dispositivos de izaje bajo el gancho.'],
                ['ASME B30.20', 'Dispositivos de izaje bajo el gancho.'],
                ['AWS D1.1', 'Código de soldadura estructural — Acero.'],
                ['ASTM E165 / E709', 'Líquidos penetrantes / partículas magnéticas.'],
            ],
            'criterios' => [
                ['Identificación', 'Marcado de capacidad y número de identificación.', 'Marcado ilegible o faltante.'],
                ['Placa', 'Fisuras, deformación y pérdida de espesor.', 'Fisura, deformación o pérdida de espesor fuera de diseño.'],
                ['Ojo de izaje', 'Elongación, ovalamiento, desgaste y holgura con el perno del grillete.', 'Ovalamiento o desgaste del barreno fuera de tolerancia.'],
                ['Unión a la estructura', 'Soldadura o tornillería; inspección por PND.', 'Indicación lineal en PND, socavado o tornillo flojo.'],
                ['Orientación', 'Alineación de la placa con la dirección de la carga.', 'Carga prevista fuera del plano de diseño.'],
                ['Prueba de carga', 'Aplicación del factor de prueba y revisión posterior.', 'Deformación permanente o indicación en PND tras la prueba.'],
            ],
            'riesgos' => [
                ['Carga fuera del plano', 'Orientar el izaje en el plano de la placa; usar grillete y aparejo alineados.'],
                ['Grillete incompatible', 'Perno del grillete acorde al barreno y capacidad igual o mayor a la de la oreja.'],
                ['Soldadura deteriorada', 'Inspección por PND periódica y después de cualquier evento de sobrecarga.'],
                ['Modificaciones', 'No cortar, soldar ni perforar la oreja o su base sin una nueva evaluación.'],
                ['Carga de choque', 'Izar de forma gradual, sin tirones.'],
            ],
            'preuso' => ['Marcado legible.', 'Sin fisuras ni deformación visibles.', 'Grillete del tamaño correcto y asegurado.', 'Dirección de la carga en el plano de la placa.', 'Base y soldaduras sin daños.'],
            'paro' => ['Fisura o deformación visible.', 'Grillete que no corresponde al barreno.', 'Carga prevista fuera del plano.', 'Corrosión con pérdida de material.'],
            'epp' => $eppIzaje,
            'glosario' => [
                ['Oreja de carga', 'Placa con barreno para conectar el aparejo de izaje.'],
                ['Plano de la placa', 'Dirección en la que la oreja está diseñada para recibir la carga.'],
                ['PND', 'Pruebas no destructivas: líquidos penetrantes, partículas magnéticas, ultrasonido.'],
                ['Factor de prueba', 'Múltiplo de la capacidad aplicado en la prueba de carga.'],
                ['Socavado', 'Ranura en el metal base junto a la soldadura que reduce su resistencia.'],
                ['Grillete', 'Accesorio en forma de U con perno que conecta la oreja con el aparejo.'],
            ],
        ];

        return $p[$id] ?? null;
    }

    public const CSS = <<<'CSS'
<style>
.ax-h { font-family:'avbamont','Montserrat',sans-serif; font-weight:900; font-size:8.6pt; color:#0B2545; text-transform:uppercase; letter-spacing:.6px; border-bottom:.5mm solid #C9A84C; padding:0 0 .9mm; margin:3.2mm 0 1.6mm; }
.ax-h span { font-family:'avbainter','Inter',sans-serif; font-weight:normal; font-size:7pt; color:#8a96a8; text-transform:none; letter-spacing:0; font-style:italic; }
.ax-caja { border:.3mm solid #dfe6ee; background:#ffffff; padding:2.4mm 3.4mm; }
.ax-p { font-size:8.4pt; line-height:1.55; color:#1f2d3d; }
.ax-ficha { width:100%; border-collapse:collapse; margin-top:2mm; }
.ax-ficha td { background:#f3f6fa; border:.3mm solid #ffffff; padding:1.4mm 2.4mm; vertical-align:top; }
.ax-k { font-size:6.2pt; font-weight:bold; color:#6b7a8f; text-transform:uppercase; letter-spacing:.5px; }
.ax-v { font-size:8.4pt; font-weight:bold; color:#0B2545; margin-top:.3mm; }
.ax-etapas { width:100%; border-collapse:collapse; }
.ax-etapas td { width:20%; vertical-align:top; background:#f3f6fa; border:.3mm solid #ffffff; padding:2.2mm 2.2mm 2.6mm; text-align:center; }
.ax-etapas td.ax-et-fin { background:#0B2545; }
.ax-et-n { font-family:'avbamont','Montserrat',sans-serif; font-weight:900; font-size:11pt; color:#C9A84C; }
.ax-et-t { font-family:'avbamont','Montserrat',sans-serif; font-weight:800; font-size:7.6pt; color:#0B2545; margin-top:.6mm; }
.ax-et-fin .ax-et-t { color:#ffffff; }
.ax-et-x { font-size:6.8pt; color:#4b5a6e; margin-top:.8mm; line-height:1.35; }
.ax-et-fin .ax-et-x { color:#c9d6e6; }
.ax-crit { width:100%; border-collapse:collapse; margin-top:2mm; }
.ax-crit td { vertical-align:top; font-size:7.2pt; line-height:1.4; padding:1.8mm 2.6mm; color:#1f2d3d; }
.ax-crit b { font-family:'avbamont','Montserrat',sans-serif; font-size:7.8pt; }
.ax-c-ok { background:#eef8f1; border-left:1.2mm solid #1b7a3d; width:33%; } .ax-c-ok b { color:#1b7a3d; }
.ax-c-nc { background:#fdeeee; border-left:1.2mm solid #b42318; width:33%; } .ax-c-nc b { color:#b42318; }
.ax-c-na { background:#f3f4f6; border-left:1.2mm solid #6b7280; width:33%; } .ax-c-na b { color:#4b5563; }
.ax-c-sep { width:1.6mm; padding:0!important; }
.ax-tab { width:100%; border-collapse:collapse; }
.ax-tab th { background:#134074; color:#ffffff; font-size:7pt; font-weight:bold; text-align:left; padding:1.6mm 2.4mm; letter-spacing:.3px; }
.ax-tab td { border-bottom:.3mm solid #e3e9f0; font-size:7.7pt; line-height:1.4; padding:1.5mm 2.4mm; vertical-align:top; color:#1f2d3d; }
.ax-tab tbody tr:nth-child(even) td { background:#f8fafc; }
.ax-cl { font-weight:bold; color:#0B2545!important; }
.ax-rech { color:#8a1c1c!important; }
.ax-ries { font-weight:bold; color:#8a1c1c!important; }
.ax-tag { font-size:6.6pt; font-weight:bold; padding:.5mm 1.6mm; }
.ax-tag-a { background:#0B2545; color:#ffffff; }
.ax-tag-r { background:#e8eef6; color:#134074; }
.ax-tag-c { background:#fbf3df; color:#8a6a14; }
.ax-nota { margin-top:2mm; background:#fbf6e8; border-left:1.2mm solid #C9A84C; padding:2mm 3mm; font-size:7.4pt; line-height:1.45; color:#4b5a6e; }
.ax-res { width:100%; border-collapse:collapse; margin-bottom:2mm; }
.ax-res td { width:25%; text-align:center; padding:2mm; border:.3mm solid #ffffff; }
.ax-r-n { font-family:'avbamont','Montserrat',sans-serif; font-weight:900; font-size:15pt; }
.ax-r-l { font-size:6.6pt; font-weight:bold; text-transform:uppercase; letter-spacing:.5px; }
.ax-r-t { background:#0B2545; color:#ffffff; } .ax-r-t .ax-r-l { color:#c9d6e6; }
.ax-r-c { background:#eef8f1; color:#1b7a3d; }
.ax-r-x { background:#fdeeee; color:#b42318; }
.ax-r-a { background:#f3f4f6; color:#4b5563; }
.ax-sec { background:#e8eef6!important; color:#0B2545!important; font-family:'avbamont','Montserrat',sans-serif; font-weight:800; font-size:7.4pt!important; text-transform:uppercase; letter-spacing:.4px; }
.ax-num { color:#8a96a8!important; text-align:center; }
.ax-v-c { color:#1b7a3d!important; font-weight:bold; }
.ax-v-nc { color:#b42318!important; font-weight:bold; }
.ax-v-na { color:#6b7280!important; }
.ax-cl-tab td { padding:1.05mm 2.4mm; font-size:7.5pt; }
.ax-dos { width:100%; border-collapse:collapse; margin-top:2mm; }
.ax-dos td { vertical-align:top; padding:0; }
.ax-dos-i, .ax-dos-d { width:49%; }
.ax-dos-s { width:2%; }
.ax-dos td.ax-lista { border:.3mm solid #dfe6ee; padding:1.8mm 2.6mm; background:#f8fafc; }
.ax-dos td.ax-paro { background:#fdeeee; border-left:1.2mm solid #b42318; padding:1.8mm 2.6mm; }
.ax-paro-t { font-family:'avbamont','Montserrat',sans-serif; font-weight:800; font-size:7.6pt; color:#b42318; margin-bottom:.8mm; }
.ax-ul { width:100%; border-collapse:collapse; }
.ax-ul td { font-size:7.7pt; line-height:1.45; padding:.5mm 0; vertical-align:top; color:#1f2d3d; }
.ax-ul td.ax-chk { width:5mm; color:#134074; font-size:9pt; line-height:1.1; }
.ax-ul td.ax-x { width:5mm; color:#b42318; font-size:7.4pt; }
.ax-ul td.ax-pt { width:4.5mm; color:#C9A84C; font-size:6pt; padding-top:1.1mm; }
.ax-epp { width:100%; border-collapse:collapse; }
.ax-epp td { background:#0B2545; color:#ffffff; border:.4mm solid #ffffff; text-align:center; font-size:7.2pt; font-weight:bold; padding:2.2mm 1.4mm; border-top:1mm solid #C9A84C; }
.ax-glo td.ax-cl { width:28%; }
.ax-prog { width:100%; border-collapse:collapse; }
.ax-prog td { width:25%; vertical-align:top; background:#f3f6fa; border:.4mm solid #ffffff; border-top:1mm solid #134074; padding:2mm 2.4mm; font-size:7.2pt; line-height:1.4; color:#1f2d3d; }
.ax-prog td.ax-pg-avba { background:#fbf6e8; border-top-color:#C9A84C; }
.ax-pg-h { font-family:'avbamont','Montserrat',sans-serif; font-weight:900; font-size:9pt; color:#0B2545; }
.ax-pg-q { font-size:6.6pt; font-weight:bold; color:#a8852c; margin:.4mm 0 1mm; text-transform:uppercase; letter-spacing:.3px; }
.ax-val { width:100%; border-collapse:collapse; margin-top:3.4mm; background:#0B2545; border-left:1.6mm solid #C9A84C; }
.ax-val td { vertical-align:middle; padding:3mm 4mm; color:#dbe5f1; font-size:7.8pt; line-height:1.5; }
.ax-val-qr { width:31mm; background:#ffffff; text-align:center; }
.ax-val-h { font-family:'avbamont','Montserrat',sans-serif; font-weight:900; font-size:11pt; color:#ffffff; margin-bottom:1mm; }
.ax-val b { color:#ffffff; } .ax-val b.ax-or { color:#e3c97a; }
.ax-val-s { font-size:7pt; color:#9fb3cc; margin-top:1.2mm; font-style:italic; }
</style>
CSS;
}
