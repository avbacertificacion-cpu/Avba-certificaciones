<?php
/**
 * Registro de plantillas de certificado disponibles.
 * Cada clave es el identificador de tipo usado en todo el sistema.
 */
return [

    'equipos' => [
        'titulo'       => 'Certificado de Equipos',
        'descripcion'  => 'Montacargas, grúas y maquinaria pesada',
        'detalle'      => 'Incluye datos de equipo: tipo, capacidad, marca, modelo, serie e identificación. Genera vigencia automática.',
        'archivo'      => 'certificado_preview.html',
        'modulos'      => ['certificaciones', 'inspecciones'],
        'campos'       => ['cliente','domicilio','tipo_maquinaria','capacidad','marca','modelo','no_serie','no_identificacion','fecha_inspeccion'],
        'color'        => '#0B2545',
        'icono'        => 'equipo',
        'muestra' => [
            '{folio}'             => 'EQ-2024-0001',
            '{cliente}'           => 'INDUSTRIAS MODELO SA DE CV',
            '{domicilio}'         => 'BLVD. TOLUCA 450, COL. INDUSTRIAL, CDMX CP 02300',
            '{tipo_maquinaria}'   => 'MONTACARGAS ELÉCTRICO',
            '{capacidad}'         => '3,000 KG',
            '{marca}'             => 'TOYOTA',
            '{modelo}'            => '8FGU30',
            '{no_serie}'          => '8FGU30-12345',
            '{no_identificacion}' => 'MF-001',
            '{fecha_inspeccion}'  => '',   // filled at runtime
            '{vigencia}'          => '',   // filled at runtime
            '{no_acreditacion}'   => '0147-I-0022',
            '{qr_imagen}'         => '',   // filled at runtime
        ],
    ],

    'accesorios' => [
        'titulo'       => 'Certificado de Accesorios',
        'descripcion'  => 'Eslingas, grilletes y accesorios de izaje',
        'detalle'      => 'Incluye resumen de ítems inspeccionados. Ideal para lotes de accesorios de un mismo cliente.',
        'archivo'      => 'certificado_accesorios_preview.html',
        'modulos'      => ['certificaciones', 'accesorios'],
        'campos'       => ['cliente','domicilio','resumen_items','fecha_inspeccion'],
        'color'        => '#1a5580',
        'icono'        => 'accesorio',
        'muestra' => [
            '{folio}'           => 'ACC-2024-0001',
            '{cliente}'         => 'INDUSTRIAS MODELO SA DE CV',
            '{domicilio}'       => 'BLVD. TOLUCA 450, COL. INDUSTRIAL, CDMX CP 02300',
            '{resumen_items}'   => "3 eslingas cadena G80 1/2\" cap. 3.5 t\n2 grilletes forjados 3/4\"\n1 gancho de seguridad c/pasador",
            '{fecha_inspeccion}'=> '',
            '{vigencia}'        => '',
            '{no_acreditacion}' => '0147-I-0022',
            '{qr_imagen}'       => '',
        ],
    ],

    'personal' => [
        'titulo'       => 'Certificado de Personal',
        'descripcion'  => 'Cursos y programas de capacitación',
        'detalle'      => 'Para participantes que acreditan un programa. Sin vigencia de inspección.',
        'archivo'      => 'certificado_personal_preview.html',
        'modulos'      => ['personal', 'capacitacion'],
        'campos'       => ['participante','puesto','curp','fecha_emision','programa','empresa'],
        'color'        => '#2d6a4f',
        'icono'        => 'personal',
        'muestra' => [
            '{folio}'          => 'PER-2024-0001',
            '{participante}'   => 'JUAN CARLOS PÉREZ GARCÍA',
            '{puesto}'         => 'OPERADOR DE MONTACARGAS',
            '{curp}'           => 'PEGJ850101HDFRRN09',
            '{fecha_emision}'  => '',
            '{programa}'       => 'OPERACIÓN SEGURA DE MONTACARGAS',
            '{empresa}'        => 'INDUSTRIAS MODELO SA DE CV',
            '{no_acreditacion}'=> '0147-I-0022',
            '{qr_imagen}'      => '',
        ],
    ],

];
