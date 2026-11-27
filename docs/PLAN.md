# Plan: de la maqueta a la plataforma real de Control de Accesos

**Objetivo.** Volver real la maqueta (`demo/`) como una plataforma independiente en
https://gestion.avba.com.mx/controlaccesos/, con base de datos y cuentas propias, a
partir del módulo que ya funcionaba en el Sistema de Gestión (`accesos.html`,
`api/ControlAccesos.php` y la app que lee la credencial).

Cada fase termina **probada** (base local y pantallas con Playwright) y **publicada**,
y con un reporte al usuario en español de qué quedó, qué se probó y qué falta.

---

## Fase 0 — Mudanza (hecha)

- Rama `claude/control-accesos` con sólo lo de control de accesos (con su historial).
- Despliegue propio a `public_html/controlaccesos/` y app propia
  («AVBA Control de Accesos», `mx.com.avba.controlaccesos`). Los dos flujos de GitHub
  están **apagados** (sin `push`) hasta terminar la fase 1: no se ha publicado nada.
- `config/config.example.php`, `sql/esquema-inicial.sql`, `.htaccess`, `composer.json`
  (sólo mPDF; lo instala el despliegue), pruebas de referencia.
- El Sistema de Gestión **no se tocó**: su `accesos.html`, la app «AVBA Caseta» y los
  datos de su base siguen funcionando hasta el cambio (fase 6).

## Fase 1 — Que la plataforma funcione sola

**Estado:** construida y probada en local (12 pruebas, `tests/referencia/correr.sh`);
despliegues encendidos. Falta lo del usuario en hPanel y la prueba con la app en un
teléfono.

Lo que hace el usuario (una vez, en hPanel):
1. Crear la base MySQL (p. ej. `u218429682_controlaccesos`) y su usuario.
2. Crear en el servidor `public_html/controlaccesos/config/config.php` a partir de
   `config/config.example.php` con esos datos y una `CLAVE_INSTALACION` propia.
3. Abrir `…/controlaccesos/login.html` y crear la primera cuenta ADMIN con esa clave.

Lo que se construye:
- `api/index.php`: enrutador propio, **sólo** sesión y control de accesos (las acciones
  que ya usa `accesos.html`: `LISTAR_ACCESOS`, `ACCESO_REGISTRAR`, `ACCESOS_EXISTEN`,
  `ACCESO_ELIMINAR`, `ACCESOS_MI_PUNTO`, `ACCESOS_PUNTOS`, `ACCESOS_PUNTO_ASIGNAR`,
  `ACCESOS_EQUIPO_LIBERAR`, `ACCESOS_PDF`, `ACCESOS_PDF_DATOS`, `ACCESOS_EN_PLANTA`,
  `ACCESOS_PERSONA`, `ACCESOS_DAR_SALIDA`, `ACCESOS_TARJETA_REGLAS`,
  `LISTAR_ACCESOS_LUGARES`). Respuestas JSON `{status, message, …}` como hoy.
- `api/helpers.php` (respuesta, conexión PDO, `textoEstandar`, `comprimirImagen`,
  `validarToken`) y `api/Auth.php`: contraseña con `password_hash`, sesión por equipo
  en `sesiones_usuario`, límite de intentos. Tomar del sistema principal lo que sirva,
  sin traer lo demás:
  `git fetch origin claude/continue-avba-project-bU5LX` y
  `git show origin/claude/continue-avba-project-bU5LX:api/Auth.php` (también
  `api/helpers.php`, `login.html`, `descargar.php`, `config/database.php`).
- `login.html` propio y entrada por perfil: **ADMIN** (Administración AVBA), **PLANTA**
  (administrador de accesos de la planta) y **ACCESOS** (guardia de caseta). Ajustar
  `ControlAccesos::ROLES`/`permitido()` y la pantalla a esos tres perfiles.
- `descargar.php`: fotos de credenciales y PDF, sólo con sesión.
- Cuentas: pantalla para que ADMIN cree cuentas (una por punto de control para los
  guardias, administradores de planta), cambie contraseñas y las desactive.
- Primera cuenta ADMIN: instalación que sólo funciona si no hay ninguna cuenta y con la
  `CLAVE_INSTALACION` de `config.php` (la de la plantilla no vale).
- `accesos.html`: claves de almacenamiento con prefijo **`ca_`** (ver CLAUDE.md,
  «mismo dominio»), rutas relativas a `/controlaccesos/`.
- Adaptar `tests/referencia/` a la API propia y dejarlas corriendo.
- Activar el `push` de `deploy-control-accesos.yml` y de `app-caseta.yml`, publicar y
  probar de punta a punta con la app nueva en un teléfono.

**Después de la fase 1 (a pedido del usuario):** botón INCIDENCIA en la caseta; turnos por
cuenta (cada guardia es de día o de noche y lo que registra va a su turno, a cualquier hora);
un solo acceso con varios teléfonos y cuentas registrando a la vez (sin puntos de control ni
teléfono ligado).

## Fase 2 — Padrón: empresas y personas

**Adelantado (a pedido del usuario):** la base del personal (`personal.html`, `api/Personal.php`):
IDs y nombres desde un Excel, agregar / cambiar / quitar IDs, y **permisos por ID**
(resguardo de herramienta, celular, computadora u otro) con foto del pase, datos del equipo y
vigencia; el guardia los ve al leer la credencial y agrega permisos con la foto del pase
(también sin señal). Al leer, se guarda el nombre de la base. Falta: empresas con REPSE,
documentos y cursos, y que el lector de la app sólo lea el ID (no espere el nombre).

Lo que muestra la maqueta (panel → Personas, perfil de la persona, Contratistas):
- **Empresas contratistas** con su REPSE (vigente / por vencer / vencido) y su personal.
- **Personas**: el ID de la credencial como llave, nombre, empresa, puesto, foto,
  **documentos y cursos con vigencia** (inducción, examen médico, DC-3, IMSS; cursos
  NOM-009 altura, NOM-027 soldadura, NOM-029 eléctrica, NOM-006 izaje, NOM-033
  espacios confinados, NOM-004 bloqueo de energías).
- Alta automática al leer una credencial nueva en la caseta (con lo que se leyó: ID y
  nombre) y alta/edición desde el panel.
- Perfil de la persona: datos, documentos, autorizaciones, horas por día (14 días),
  vales, incidencias y bitácora de accesos (con sus fotos de evidencia).

## Fase 3 — Reglas en la puerta y vales

Lo que el guardia ve al leer la credencial (como en `demo/caseta.html`): **autorizado**,
**con aviso** o **no puede pasar**, con el motivo.
- Revisión en la puerta (configurable, como en la maqueta → Reglas y roles):
  bloquear con documentos vencidos; celular sólo con autorización (si no, se
  resguarda); registrar herramienta a la entrada y revisarla a la salida; prueba de
  alcoholímetro aleatoria (% de las entradas).
- Autorizaciones con vigencia y **vetos** (con motivo, quién y hasta cuándo).
- Solicitudes de autorización de la caseta al administrador.
- **Vales**: ingreso de herramienta (se cierra al salir con ella), préstamo de equipo
  de planta (radio, detector de gases, arnés) y salida de material (requiere
  autorización); alerta cuando un vale abierto pasa de N días.

## Fase 4 — Jornadas e incidencias

**Adelantado:** el botón INCIDENCIA de la caseta ya existe (tabla `accesos_incidencias`:
alcoholímetro positivo, mala conducta, problema con la persona u otro; con la credencial
leída y su foto; aviso a Administración e historial por ID). Falta la gravedad, los
reportes por empresa y mes, y ligarlas a los vetos.

- Topes de la Ley Federal del Trabajo por año (la maqueta trae la tabla 2026–2030:
  48 h ordinarias + 9 dobles en 2026 … 40 + 12 en 2030), tope de permanencia continua
  (política de la planta, p. ej. 12 h) y días seguidos antes de exigir descanso.
- Alertas de jornada: permanencia, semana y descanso; las 15 personas con más horas.
- **Incidencias** con tipo y gravedad, por empresa y por mes; registrar incidencia o
  vetar desde el perfil de la persona.

## Fase 5 — Tablero y reportes

**Adelantado (a pedido del usuario):** perfil **CLIENTE** con su tablero (`tablero.html`,
`api/Tablero.php`): periodo de hasta 93 días, cifras, personas dentro, entradas y salidas por
día y por hora, tiempo dentro por visita, personas con más horas, incidencias, quién está
dentro; descarga en **Excel** (todo el detalle) y **PDF** (con las gráficas). Falta lo que
depende del padrón (fase 2): «por empresa» y documentación.

- Tablero de la maqueta: personas dentro (24 h), alertas activas, entradas y salidas
  por hora, dentro ahora por empresa, horas en 7 días, incidencias, documentación por
  empresa, movimientos recientes y lo que se registra en vivo en la caseta.
- «En planta» (ya existe), PDF de entradas y salidas y PDF con fotos (ya existen),
  descargas para Excel.

## Fase 6 — Cambio desde el Sistema de Gestión

- Migrar a la base nueva lo registrado en la base principal (`accesos_registros`,
  `accesos_puntos`, fotos de `uploads/accesos/`) con un script revisado y probado.
- Instalar «AVBA Control de Accesos» en los teléfonos de las casetas y, cuando su cola
  esté vacía, desinstalar «AVBA Caseta».
- Agregar el mosaico «Control de Accesos» al portal del sitio (lista `PLATAFORMAS` del
  `index.html` de la raíz, en el sistema principal).
- Con permiso del usuario: retirar del Sistema de Gestión `accesos.html`,
  `api/ControlAccesos.php`, la app vieja y la demo.

## Decisiones que son del usuario

- Nombre de la base y quién administra las cuentas.
- Qué reglas de la puerta se activan desde el inicio (fase 3).
- Cuándo se hace el cambio de la fase 6.
