<?php
/**
 * Los 14 centros de trabajo que se usan como datos de demostración.
 *
 * Viven aquí, y no dentro de una pantalla, porque dos herramientas necesitan
 * exactamente la misma lista: la que los siembra en la instalación de demo y
 * la que los retira si alguna vez entraron al sistema real. Si la lista
 * estuviera duplicada, una de las dos acabaría desfasada y la limpieza
 * dejaría plantas sueltas.
 */

/** Usuario gerente que se crea junto con las plantas de demostración. */
const GERENTE_USERNAME = 'gerente.corporativo';

/**
 * El vocabulario con el que se inventan los extintores de ejemplo.
 *
 * Vive aquí por la misma razón que la lista de centros: el que siembra lo usa
 * para inventar las filas y el que limpia lo usa para reconocerlas. Un extintor
 * sembrado siempre queda con la ubicación formada como
 * «SECCIÓN — DETALLE», con la sección y el detalle sacados de estas listas;
 * esa forma es la huella que permite distinguirlo de uno capturado a mano.
 */
const SECCIONES = [
    'corporativo' => ['Recepción', 'Piso 3', 'Piso 6', 'Cuarto de servidores', 'Cocineta', 'Sala de juntas'],
    'industrial'  => ['Área de Proceso', 'Subestación Eléctrica', 'Almacén General', 'Comedor', 'Taller de Mantenimiento', 'Sala de Control', 'Patio de Tanques', 'Oficinas Administrativas'],
    'eolico'      => ['Subestación', 'Casa de Control', 'Almacén de Refacciones', 'Oficinas', 'Base de Aerogenerador'],
];

const DETALLES_UBICACION = [
    'Junto a la puerta principal', 'Pasillo central', 'Cerca del tablero eléctrico',
    'Junto a la salida de emergencia', 'Área de trabajo', 'Pasillo de acceso',
    'Junto a la escalera', 'Cerca del extintor de respaldo', 'A la entrada del área',
];

const CAPACIDADES = [
    'corporativo' => [4.5, 6, 9],
    'industrial'  => [4.5, 9, 12, 25, 50],
    'eolico'      => [4.5, 9, 12, 25],
];

/** El centro de la lista que se llama así, o null si no es de la demostración. */
function centroPorNombre(string $nombre): ?array {
    foreach (centros() as $c) {
        if (mb_strtolower($c['nombre']) === mb_strtolower($nombre)) return $c;
    }
    return null;
}

function centros(): array {
    return [
        ['nombre' => 'CCC Altamira III y VI',        'domicilio' => 'Boulevard de los Ríos Km 10.3, Puerto Industrial de Altamira, Col. Lomas del Real, C.P. 89600, Altamira, Tamaulipas', 'sabor' => 'industrial', 'grande' => true],
        ['nombre' => 'CCC Altamira V',                'domicilio' => 'Boulevard de los Ríos Km 11.17, Col. Lomas del Real, Altamira, Tamaulipas, CP 89600', 'sabor' => 'industrial', 'grande' => false],
        ['nombre' => 'CCC Escobedo',                  'domicilio' => 'Carretera Mty-Monclova Km 11.5, El Carmen, Nuevo León, CP 66560', 'sabor' => 'industrial', 'grande' => false],
        ['nombre' => 'CCC Tamazunchale I',            'domicilio' => 'Predio El Clérigo entre Tepetate y Cuixcuatitla, C.P. 79960, Tamazunchale, S.L.P.', 'sabor' => 'industrial', 'grande' => false],
        ['nombre' => 'CCC El Clérigo',                'domicilio' => 'Predio El Clérigo entre Tepetate y Cuixcuatitla, C.P. 79960, Tamazunchale, S.L.P.', 'sabor' => 'industrial', 'grande' => false],
        ['nombre' => 'CCC La Laguna',                 'domicilio' => 'Circuito Industrial Durango 4300, Ex Ejido Cuba, CP 35140, Gómez Palacio, Durango', 'sabor' => 'industrial', 'grande' => false],
        ['nombre' => 'CCC Dulces Nombres',            'domicilio' => 'Carretera a Dulces Nombres Km 12.5, Pesquería, Nuevo León, CP 66650', 'sabor' => 'industrial', 'grande' => false],
        ['nombre' => 'CCC Baja California III',       'domicilio' => 'Carretera Escénica Tijuana-Ensenada Km 81.2, Predio La Jovita, Col. El Sauzal, Ensenada, B.C., CP 22760', 'sabor' => 'industrial', 'grande' => false],
        ['nombre' => 'CCC Enertek',                   'domicilio' => 'Carretera Tampico-Mante Km 17.5, C.P. 89600, Altamira, Tamaulipas', 'sabor' => 'industrial', 'grande' => false],
        ['nombre' => 'Parque Eólico La Venta III',    'domicilio' => 'Carretera La Ventosa-Arriaga, tramo La Ventosa, Tapanatepec Km 98+770, CP 70120, Municipio de Santo Domingo Ingenio, Oaxaca', 'sabor' => 'eolico', 'grande' => false],
        ['nombre' => 'Corporativo Cd. de México (1)', 'domicilio' => 'Cofre de Perote 130, piso 3, Lomas de Chapultepec, Miguel Hidalgo, CP 11000, CDMX', 'sabor' => 'corporativo', 'grande' => false],
        ['nombre' => 'Corporativo Cd. de México (2)', 'domicilio' => 'Sierra Gorda 42, piso 6, Col. Lomas de Chapultepec, Miguel Hidalgo, CP 11000, CDMX', 'sabor' => 'corporativo', 'grande' => false],
        ['nombre' => 'CC Topolobampo II',             'domicilio' => 'Ejido Choacahui, aprox. 5 km al noreste de San Miguel Zapotitlán, Km 19+350 de la carretera federal No. 15 Navojoa-Los Mochis, Ahome, Sinaloa, CP 81304', 'sabor' => 'industrial', 'grande' => false],
        ['nombre' => 'CC Topolobampo III',            'domicilio' => 'Ejido Choacahui, aprox. 5 km al noreste de San Miguel Zapotitlán, Km 19+350 de la carretera federal No. 15 Navojoa-Los Mochis, Ahome, Sinaloa, CP 81304', 'sabor' => 'industrial', 'grande' => false],
    ];
}
