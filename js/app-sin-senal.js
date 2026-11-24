/* ══════════════════════════════════════════════════════════════
   AVBA — Qué hace la app SIN SEÑAL, módulo por módulo

   Es el único lugar que hay que tocar para que un módulo nuevo trabaje sin
   señal en la app. Lo lee js/app-shell.js (la capa de la app); en el sitio
   web no se usa. Llega a los teléfonos con la actualización automática, igual
   que las pantallas: no hace falta publicar otra versión en las tiendas.

   Al agregar un módulo:

   1. CONSULTAS. Las acciones que empiezan con GET_, LISTAR_, DETALLE_,
      BUSCAR_, OBTENER_ o SUGERENCIAS_ se guardan solas en el teléfono y, sin
      señal, se contestan con la última copia. Si el módulo consulta con otro
      nombre, agrégalo en lecturas.acciones.

   2. CAPTURA SIN SEÑAL. Lo que se guarda o se envía NO se hace sin señal a
      menos que se declare aquí; sin declararlo, la pantalla avisa que no hay
      conexión (lo correcto para aprobar, emitir, timbrar, mandar correos…).
      Para que una captura se guarde en el teléfono y se envíe sola después:
        · bandeja.crea: si el servidor responde con el número del registro
          nuevo, pon la acción y el campo donde viene ese número. Lo que se
          capture después sobre ese registro se encadena a él aunque todavía
          no tenga número real.
        · bandeja.sinNumero: si no crea nada (completar, enviar, cerrar,
          eliminar).

   3. PRECARGA. Si el inspector necesita algo en campo sin haberlo abierto
      antes, agrega la consulta en precarga.consultas (se baja al entrar y
      cada 4 horas, para los roles de precarga.roles).
   ══════════════════════════════════════════════════════════════ */
window.AVBA_SIN_SENAL = {
  lecturas: {
    prefijos: ['GET_', 'LISTAR_', 'DETALLE_', 'BUSCAR_', 'OBTENER_', 'SUGERENCIAS_'],
    acciones: [
      // Portal del cliente
      'SOLCERT_MIS', 'RH_SALUD', 'RH_POLITICAS', 'RH_INCIDENCIAS',
      // Inspección de maquinaria
      'MI_INSPECCION_PREPARADA', 'getMaquinaria', 'getChecklist',
    ],
  },

  bandeja: {
    crea: {
      // Accesorios de izaje
      CREAR_SESION_ACCESORIOS: 'sesion_id',
      GUARDAR_ACCESORIO:       'id',
      // Arneses y líneas de vida
      CREAR_SESION_ARNESES:    'sesion_id',
      GUARDAR_ITEM_ARNES:      'item_id',
      // Cursos (participantes de una sesión ya abierta)
      GUARDAR_PARTICIPANTE:    'id',
      SESION_AGREGAR_EMPRESA:  'id',
      // Recomendaciones
      CREAR_RECOMENDACION:     'id',
      // Control de accesos (entradas y salidas con foto de la identificación)
      ACCESO_REGISTRAR:        'id',
    },
    sinNumero: [
      'COMPLETAR_SESION_ACC', 'ENVIAR_SESION_ACC',   // accesorios
      'CERRAR_SESION_ACCESO',                        // cursos
      'ELIMINAR_RECOMENDACION',                      // recomendaciones
    ],
  },

  precarga: {
    roles: ['INSPECTOR', 'ADMIN', 'CALIDAD'],
    consultas: [
      'CLIENTES_OFFLINE',                                   // buscar clientes sin señal
      'LISTAR_CURSOS', 'LISTAR_OCUPACIONES', 'GET_MIS_CURSOS',
      'GET_MIS_INSPECCIONES', 'GET_MIS_ACCESORIOS', 'MI_INSPECCION_PREPARADA',
      // Los tipos de equipo con sus listas de revisión, los tipos de accesorio
      // y de arnés con las suyas, se bajan aparte (dependen unos de otros).
    ],
  },
};
