<?php
/*
 * CONFIGURACIÓN DEL SISTEMA — plantilla
 *
 * 1. Copia este archivo con el nombre  config.php  (en esta misma carpeta).
 * 2. Llena los datos de la base de datos que creaste en el panel de Hostinger.
 * 3. Elige el almacenamiento: 'local' para empezar, 's3' cuando tengas la
 *    cuenta de Backblaze B2 (o Amazon S3).
 *
 * config.php NO se sube a GitHub (tiene contraseñas). Sólo vive en el servidor.
 * Guía completa: docs/MIGRACION.md
 */
return [

    'app' => [
        'nombre'  => 'CH Arquitectura y Construcción',
        // Zona horaria de la empresa.
        'zona'    => 'America/Mexico_City',
        // true sólo mientras se prueba: muestra el detalle de los errores.
        'debug'   => false,
        // Tamaño máximo por archivo, en MB.
        'max_mb'  => 2048,
        // Minutos sin actividad antes de cerrar la sesión.
        'sesion_minutos' => 480,
    ],

    // Base de datos MySQL (Hostinger → Bases de datos → MySQL).
    'db' => [
        'host'    => 'localhost',
        'nombre'  => 'u000000000_chc',
        'usuario' => 'u000000000_chc',
        'clave'   => 'CAMBIA-ESTA-CLAVE',
    ],

    // Almacenamiento de los documentos de trabajo.
    'almacen' => [
        // 'local' = en el mismo servidor (carpeta storage/). Bueno para empezar.
        // 's3'    = en la nube: Backblaze B2, Amazon S3 o cualquier compatible.
        'driver' => 'local',

        // Sólo si driver = 'local'. Mejor fuera de public_html si se puede.
        'local_ruta' => __DIR__ . '/../storage/archivos',

        // Sólo si driver = 's3'. Ejemplo para Backblaze B2:
        //   endpoint: https://s3.us-west-004.backblazeb2.com  (lo muestra B2 en el bucket)
        //   region:   us-west-004
        's3' => [
            'endpoint'   => 'https://s3.us-west-004.backblazeb2.com',
            'region'     => 'us-west-004',
            'bucket'     => 'nombre-del-bucket',
            'key'        => 'ID-DE-LA-LLAVE',
            'secret'     => 'LLAVE-SECRETA',
            // true para B2 y la mayoría de compatibles; false para Amazon S3.
            'path_style' => true,
        ],
    ],

    // Archivo muerto (documentos de obras terminadas).
    'archivo_muerto' => [
        // 'inmediato' = se descargan al instante (Propuesta A).
        // 'frio'      = almacenamiento de largo plazo, con requisición y espera (Propuesta B).
        'modo' => 'inmediato',

        // Prefijo (carpeta) donde vive el archivo muerto dentro del almacenamiento.
        'prefijo' => 'archivo-muerto/',

        // Dónde se guarda. Usa la misma forma que 'almacen'.
        //   - Modo inmediato: normalmente el mismo bucket de trabajo.
        //   - Modo frío: un bucket de Amazon S3 y 'clase' => 'DEEP_ARCHIVE'.
        'driver'     => 'local',
        'local_ruta' => __DIR__ . '/../storage/archivo-muerto',
        's3' => [
            'endpoint'   => 'https://s3.us-east-1.amazonaws.com',
            'region'     => 'us-east-1',
            'bucket'     => 'nombre-del-bucket-archivo',
            'key'        => 'ID-DE-LA-LLAVE',
            'secret'     => 'LLAVE-SECRETA',
            'path_style' => false,
        ],
        // Clase de almacenamiento al subir (sólo Amazon S3). Vacío = normal.
        'clase' => '',

        // Días que un archivo recuperado queda disponible para descargar.
        'dias_disponible' => 7,
        // Recuperación incluida al mes, en GB (sólo informativo en pantalla).
        'gb_incluidos' => 100,
        // Sólo para pruebas con almacenamiento local en modo frío:
        // minutos que se simula que tarda la recuperación (estándar, económica).
        'simular_minutos' => [1, 2],
    ],

    // Avisos por correo (vencimientos, requisiciones, documentos compartidos).
    'correo' => [
        'activo'    => false,
        // Usa un correo del dominio creado en Hostinger → Correos.
        'remitente' => 'avisos@tudominio.com',
        // Dirección del sistema, para poner enlaces en los correos.
        'url_sistema' => 'https://tudominio.com/',
    ],
];
