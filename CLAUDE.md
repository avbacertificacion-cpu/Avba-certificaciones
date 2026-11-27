# AVBA · Control de Accesos — instrucciones del proyecto

Plataforma **independiente** de AVBA para el control de accesos de planta: entradas y
salidas del personal (contratistas y planta) con la **credencial de acceso**, leída por
los teléfonos de los guardias en el **único acceso** de la planta, el panel del administrador y el
**tablero del cliente** (gráficas, Excel y PDF).

Está separada del **Sistema de Gestión** (certificaciones, raíz de
gestion.avba.com.mx) igual que **Extintores** (`/extintores/`) y **Socios Comerciales**
(`/socioscomerciales/`): **rama propia, carpeta propia en el hosting, base de datos
propia y cuentas propias**. La aplicación es esta rama; el sistema principal no se toca.

- **Dirección:** https://gestion.avba.com.mx/controlaccesos/
- **Rama:** `claude/control-accesos` (aquí se trabaja y de aquí se publica).
- **Plan de trabajo:** `docs/PLAN.md` (de la maqueta a lo real, por fases).

## Reglas de trabajo

- **Todo en español**: lo que se le dice al usuario, los textos de las pantallas y los
  mensajes de commit. Al usuario, explicaciones claras y concretas, sin jerga.
- **Sólo esta rama.** No hacer push a ninguna otra. En especial:
  `claude/continue-avba-project-bU5LX` publica el Sistema de Gestión en producción y
  `main` es la rama por omisión. Leer de ellas sí se puede
  (`git fetch origin <rama>` y `git show origin/<rama>:ruta`).
- No crear pull requests salvo que el usuario lo pida.
- No poner nombres de modelos de IA en commits ni en archivos del repositorio.

## Privacidad (el repositorio es PÚBLICO)

- **Nunca** subir datos reales de personas: nombres, IDs de credencial, NSS, fotos,
  firmas, teléfonos, correos. En pruebas y ejemplos, siempre datos inventados
  (p. ej. «RAMIRO ESTEBAN VILLARREAL DUARTE», ID «00287», NSS «12345678901»).
- **Nunca** subir contraseñas, `config/config.php`, llaves de firma (`*.jks`) ni tokens.
- Si el usuario comparte una foto o un dato real para ajustar algo, usarlo sólo para
  entender el formato. Antes de cada commit, revisar el diff buscando datos reales.

## Cómo está armado

| Ruta | Qué es |
|---|---|
| `index.html` | Entrada: sin sesión, «Iniciar sesión»; con sesión, el menú del perfil (Tablero, Control de accesos, Cuentas, Mi contraseña, Maqueta; la Maqueta sólo para ADMIN; el cliente sólo Tablero y Mi contraseña). |
| `login.html` | Inicio de sesión propio. Con la base vacía ofrece crear la primera cuenta ADMIN con la `CLAVE_INSTALACION` de `config.php`. |
| `cuentas.html` | Cuentas (sólo ADMIN): alta, nombre, perfil, contraseña, desactivar. |
| `descargar.php` | Fotos de credenciales, PDF y reportes del tablero, sólo con sesión (el guardia, sólo sus fotos; el cliente, sólo los reportes del tablero). |
| `api/index.php` | Enrutador propio: sesión, cuentas y las acciones de `accesos.html`. |
| `api/Auth.php`, `api/helpers.php` | Cuentas, sesiones por equipo, límite de intentos; conexión a la base propia y utilidades. |
| `js/destinos.js` | Pantalla de inicio de cada perfil y la clave de la sesión (`ca_sesion`). |
| `accesos.html` | Módulo real que ya funcionaba en el Sistema de Gestión: pantalla del guardia (lee la credencial con la app, ENTRADA/SALIDA, cola sin señal, foto de evidencia) y del administrador (registros por turno, **En planta**, movimientos por ID, dar salida, incidencias, PDF). |
| `api/ControlAccesos.php` | Lógica del servidor del módulo (registros, turnos, puntos y equipos, en planta, PDF con mPDF). |
| `tablero.html` | **Tablero** (perfil CLIENTE; también PLANTA y ADMIN): periodo (hoy, 7 y 30 días, mes, otras fechas; hasta 93 días), cifras, gráficas con Chart.js (`js/chart/`, colores validados: entradas azul `#257de0`, salidas naranja `#f47230`, tiempo dentro en escala de azules), «Ver datos» de cada gráfica, quién está dentro e incidencias. Descarga Excel y PDF (el PDF lleva las gráficas que dibuja la pantalla). |
| `api/Tablero.php` | Cálculos del tablero (acciones `TABLERO`, `TABLERO_EXCEL`, `TABLERO_PDF`): entradas, salidas, personas por ID, visitas (entrada con su salida; más de 24 h no cuenta en horas), personas dentro hora por hora o máximo por día, tiempo dentro, más horas, incidencias. Archivos en `uploads/accesos/reportes/` (se borran al día). |
| `api/lib/xlsx.php` | Excel (.xlsx) propio, sin librerías ni extensión zip: hojas con títulos fijos, filtros, fechas y el ID como texto. |
| `personal.html` | **Personal y permisos** (ADMIN y PLANTA): la base de IDs y nombres (buscar, agregar, cambiar —cambiar el ID se lleva sus permisos—, quitar), **cargar Excel** (revisa primero: columnas reconocidas o elegidas a mano, nuevos, con cambios, renglones con problema; no quita a nadie), descargar Excel, IDs leídos en la caseta que no están en la base, y **permisos** por ID con checklist (herramienta, celular, computadora, otro), foto del pase y/o datos del equipo y vigencia. `personal.html?id=287` abre a esa persona. |
| `api/Personal.php` | La base del personal (`personal`, llave = ID sin ceros) y sus permisos (`personal_permisos`; quitar = `activo` 0, queda historial). Acciones `PADRON_*` y `PERMISO_AGREGAR`/`PERMISO_QUITAR`. `PADRON_CAJA`: la copia para el teléfono de la caseta, con versión (`ca_ajustes.padron_version`). `registrar` e incidencias guardan el **nombre de la base** si el ID está (`Personal::nombreDe`). |
| `api/lib/xlsx_lector.php` | Lee .xlsx (abre el ZIP con zlib, sin extensión zip) y .csv (también los de Excel en Windows); regresa los renglones con su número de Excel. |
| `api/accesos-tarjeta.json` | Reglas del lector de la credencial (se ajustan sin reinstalar la app). `camara`: con cuál empieza el lector, **frontal** (la que se usa) o trasera. `digitosId` 5 y `minDigitos` 5: el ID impreso trae 5 dígitos (corrige las rayas de la impresora y nunca toma un pedazo del número). |
| `api/lib/qrcode.php` | Generador de códigos QR (lo usa la portada de la maqueta). |
| `demo/` | **La maqueta** que hay que volver real: `control-accesos.html` (panel), `caseta.html` (guardia), `demo-datos.js` (datos ficticios y reglas), `index.html` (portada), `sala.php` (sincroniza la demo entre equipos). **Sólo Administración:** `solo-admin.js` regresa a su inicio a quien entre con otro perfil (sin sesión sí abre, para el teléfono que entra con el QR de la portada). |
| `mobile/caseta/` | App Android «AVBA Control de Accesos» (Capacitor): abre `accesos.html` y agrega el lector nativo de la credencial (CameraX + ML Kit sin internet, `AnalizadorTarjeta.java` con sus pruebas). Cámara frontal o trasera según la regla `camara`; el botón «Cambiar cámara» pasa a la otra y el teléfono la recuerda mientras la regla no cambie. `esperaNombre` (0 con la base del personal): tras confirmar el ID no espera el nombre. **Lectura continua** (`continuo`, eventos `lectura`/`cerrado`, `mostrar`, `detener`; `disponible()` responde `continuo: true`): la cámara no se cierra, cada credencial se avisa a la página, la misma credencial frente a la cámara no se reenvía (6 s), muestra en grande lo que dice la página (o «Leída» si no contesta en 2.5 s), con tono; salir = mantener 3 s. |
| `config/config.example.php` | Plantilla de la configuración (base propia). `config.php` vive sólo en el servidor. |
| `sql/esquema-inicial.sql` | Tablas de la base propia (cuentas, sesiones, registros, puntos). |
| `tests/referencia/` | Pruebas (PHP, API y pantallas con Playwright) contra la API propia. Todas: `bash tests/referencia/correr.sh` (una: `… correr.sh pantalla/sesion`). |
| `.github/workflows/` | `deploy-control-accesos.yml` (publica en `/controlaccesos/` en cada push a esta rama) y `app-caseta.yml` (compila y publica la app cuando cambia `mobile/caseta/`). **Encendidos** desde la fase 1. |

**Perfiles** (`Auth::ROLES`): **ADMIN** (Administración AVBA: todo, incluidas las cuentas),
**PLANTA** (administrador de accesos de la planta: registros, En planta, PDF, dar salida)
**ACCESOS** (guardia: sólo registra, consulta la copia de la base y agrega permisos con foto; el enrutador le rechaza todo lo demás; puede ser **equipo fijo**: `usuarios.modo` E sólo entradas | S sólo salidas, sin turno) y **CLIENTE** (el cliente: sólo el tablero
y sus descargas; el enrutador le rechaza todo lo demás y `descargar.php` no le da fotos). Cada guardia tiene su **turno**
(`usuarios.turno`: D día | N noche), que se elige en Cuentas.

## Cuidados importantes

1. **Mismo dominio que el Sistema de Gestión.** El navegador comparte `localStorage`,
   IndexedDB y cookies entre `/` y `/controlaccesos/`. Todas las claves de esta
   plataforma llevan el prefijo **`ca_`** (p. ej. `ca_sesion`). Ya renombradas en la fase 1:
   `ca_sesion`, `ca_accesos_*`, `ca_tarjeta_reglas` y la base IndexedDB `ca_accesos`
   (las `avba_*` son del sistema principal: no tocarlas). El service worker del
   sistema principal (alcance `/`) deja pasar lo que va a `/api/` y pide lo demás a la
   red; si esta plataforma necesita uno propio, registrarlo con alcance `/controlaccesos/`.
2. **Dos apps distintas.** «AVBA Caseta» (`mx.com.avba.caseta`) abre el control de
   accesos del sistema principal; «AVBA Control de Accesos»
   (`mx.com.avba.controlaccesos`) abre esta plataforma. Firmas distintas (la llave de
   prueba vive en la caché de GitHub de cada rama), así que se instalan una junto a la
   otra durante el cambio. El paquete Java sigue siendo `mx.com.avba.caseta`.
3. **La credencial de acceso real** es vertical: **ID** en un recuadro azul (5 dígitos con
   ceros a la izquierda, **único por persona: es la llave** de entradas, salidas y en
   planta; se compara sin ceros, «00287» = «287»), **NOMBRE** en uno o dos renglones,
   EMPRESA, CARGO, **NSS de 11 dígitos (nunca es el ID)**, TIPO DE SANGRE, ALERGIAS y
   abajo «CREDENCIAL DE ACCESO». El lector ya está ajustado a ese formato.
   **Credenciales con rayas de la impresora** (rayas blancas verticales que cruzan el recuadro
   del ID y parten etiquetas: «N OMBRE», «N SS»): con la regla `digitosId` (5) el lector junta
   los pedazos del número («0 0287», «0028 7»), quita la raya leída como «|», «I» o un «1» de más,
   **nunca toma un pedazo** (sería otra persona) y, si queda en duda, decide con los IDs de la
   base que le manda la página (`ids`); sin base no adivina. «N SS» partida no se toma como «N.»
   de número. La página además corrige con la base una lectura de la app anterior con un dígito
   de más (`corregirConBase`). La app nueva responde `rayas: true` en `disponible()`.
4. **Turnos por cuenta:** lo que registra un guardia va a **su** turno (el de su cuenta), a
   cualquier hora (`turnoDeGuardia`; en la pantalla `turnoGuardia`): el de día con la fecha
   del día; el de noche con la fecha en que empezó su noche (antes del mediodía es la noche
   del día anterior). El guardia sólo ve su turno en curso. El reloj (día 06:00–18:00, noche
   18:00–06:00) queda de respaldo para Administración y para guardias sin turno: una entrada
   cuenta para el turno que empieza (desde 2 h antes), una salida para el que termina (hasta
   2 h después), y la salida de quien tiene su entrada abierta (últimas 16 h) va al turno de
   esa entrada (`turnoDeEntradaAbierta`). Administración ve los turnos en curso por reloj.
5. **Un solo acceso, varios teléfonos a la vez:** todo se registra en `ACCESO`
   (`ControlAccesos::ACCESO`, se muestra «Acceso»). No hay puntos que asignar ni teléfono
   ligado: cualquier cuenta de guardia registra desde cualquier teléfono, varias al mismo
   tiempo, y cada registro guarda qué cuenta lo capturó (cada guardia ve sólo lo suyo). Los
   registros de antes conservan su «PUNTO DE CONTROL n»; la tabla `accesos_puntos` de una
   base vieja queda sin uso. Con un solo punto, la pantalla esconde el selector de punto.
6. **Incidencias (botón INCIDENCIA de la caseta):** el guardia lee la credencial como en
   una entrada y dice qué pasó: Alcoholímetro positivo, Mala conducta, Problema con la
   persona u Otro (con descripción). Tabla propia `accesos_incidencias` (no cuentan como
   entrada ni salida, ni en «En planta» ni en los PDF), misma cola sin señal, foto como
   evidencia, turno del reloj (sin el margen de 2 h). Sin credencial se reporta con el
   nombre. Administración ve una franja naranja con las nuevas y el historial por ID.
7. **Foto de evidencia:** cada lectura guarda la foto de la credencial (sale de la misma
   imagen en la que se leyó el ID). Las fotos viven en `uploads/accesos/` (protegida) y
   se sirven sólo con sesión.
8. **Base del personal y permisos en la caseta:** el teléfono guarda una copia de la base
   (`ca_padron`, se actualiza al entrar, cada 5 min y al volver la señal) y funciona sin
   señal. Al leer: nombre de la base, sus permisos en la confirmación (dura 3.5 s si hay
   algo que decir) y en «Última credencial leída», o «no está en la base». El guardia agrega
   un permiso con **la foto del pase (obligatoria)** desde esa tarjeta; va por la **misma cola**
   que los registros (`clase: 'permiso'`, acción `PERMISO_AGREGAR`, confirmado por
   `ACCESOS_EXISTEN`); en las listas sólo cuentan los registros (`esRegistro`). La base es
   de datos reales: **nunca** al repositorio; las pruebas usan personas inventadas.
9. **Equipos fijos (lectura continua):** el acceso tiene **4 equipos fijos** y **el guardia
   elige en cada equipo** si está en la ENTRADA o en la SALIDA (tarjeta «Lectura continua»
   de `accesos.html`, para cualquier guardia con la app nueva): la cámara queda abierta y
   cada credencial se registra sola **con su cuenta y su turno**. El equipo recuerda la
   elección (`ca_accesos_carril`): si la app se reinicia vuelve a leer solo; al salir de la
   cámara a propósito (evento `cerrado`) se olvida y se vuelve a elegir. Sus botones
   ENTRADA/SALIDA/INCIDENCIA siguen. Opcional: una cuenta de modo **E**/**S** (Cuentas,
   «Siempre ENTRADA/SALIDA») no elige, abre sola su tipo, no tiene turno (va por el **reloj**)
   y el servidor rechaza el otro tipo (`MODO_FIJO`). Cada `lectura` se registra (misma cola
   sin señal) salvo si esa persona ya se registró **del mismo tipo** en ese equipo hace menos
   de `repetirSeg` (120 s, en `api/accesos-tarjeta.json`): «YA REGISTRADA» en amarillo. A la
   app le manda `mostrar` (verde entrada, azul salida, amarillo con permiso vencido o ID
   fuera de la base). La app vieja (sin `continuo`) pide instalar la nueva y deja los botones.

## Cómo probar

- Todo junto: `bash tests/referencia/correr.sh` (crea la base `ca_prueba` en el MariaDB
  local, un `config/config.php` de prueba si no hay, levanta `php -S localhost:8794` y
  corre las pruebas con la base limpia antes de cada una).
- Base local: MariaDB en `127.0.0.1` con una base de prueba creada desde
  `sql/esquema-inicial.sql` (nunca la del servidor).
- Servidor local de PHP sólo en `localhost` (`php -S localhost:8794`) con un
  `config/config.php` de prueba que no se sube.
- Pantallas con Playwright (Chromium ya viene instalado); la app se simula con
  `window.Capacitor.Plugins.LectorTarjeta` (ver `tests/referencia/pantalla/`).
- Las pruebas del lector (`AnalizadorTarjetaTest.java`) corren en cada compilación de la
  app en GitHub.
- Probar antes de publicar y decirle al usuario qué se probó y qué no (p. ej. la cámara
  real sólo se puede probar en el teléfono).
