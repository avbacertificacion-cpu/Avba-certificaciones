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
| `index.html` | Entrada: sin sesión, «Iniciar sesión»; con sesión, el menú del perfil (Tablero, Control de accesos, Cuentas, Servicio médico, Días omitidos, Mi contraseña, Maqueta; Cuentas, Servicio médico, Días omitidos y la Maqueta sólo para ADMIN; el cliente sólo Tablero y Mi contraseña; el servicio médico va directo a `medico.html`). |
| `login.html` | Inicio de sesión propio. Con la base vacía ofrece crear la primera cuenta ADMIN con la `CLAVE_INSTALACION` de `config.php`. |
| `cuentas.html` | Cuentas (sólo ADMIN): alta, nombre, perfil, contraseña, desactivar. |
| `descargar.php` | Fotos de credenciales, PDF y reportes del tablero, sólo con sesión (el guardia, sólo sus fotos; el cliente, sólo los reportes del tablero). |
| `api/index.php` | Enrutador propio: sesión, cuentas y las acciones de `accesos.html`. |
| `api/Auth.php`, `api/helpers.php` | Cuentas, sesiones por equipo, límite de intentos; conexión a la base propia y utilidades. |
| `js/destinos.js` | Pantalla de inicio de cada perfil y la clave de la sesión (`ca_sesion`). |
| `accesos.html` | Módulo real que ya funcionaba en el Sistema de Gestión: pantalla del guardia (lee la credencial con la app, ENTRADA/SALIDA, cola sin señal, foto de evidencia) y del administrador (registros por turno, **En planta**, movimientos por ID, dar salida, incidencias, PDF). |
| `api/ControlAccesos.php` | Lógica del servidor del módulo (registros, turnos, puntos y equipos, en planta, PDF con mPDF). |
| `tablero.html` | **Tablero** (perfil CLIENTE; también PLANTA y ADMIN), con dos pestañas: **Accesos** y **Servicio médico** (`ca_tablero_vista`; ver `Medico.php`). Accesos: periodo (hoy, 7 y 30 días, mes, otras fechas; hasta 93 días), cifras, gráficas con Chart.js (`js/chart/`, colores validados: entradas azul `#257de0`, salidas naranja `#f47230`, tiempo dentro en escala de azules), «Ver datos» de cada gráfica, quién está dentro e incidencias. Descarga Excel y PDF (el PDF lleva las gráficas que dibuja la pantalla). **Turno de hoy (desde 06:00)** y horas en «Otras fechas» (`hora_desde`/`hora_hasta`; antes de las 06:00 el turno de hoy empezó ayer): el **corte** es el fin o ahora. **Cuadre** (`Tablero::cuadre`, con la regla de En planta): dentro al inicio + entradas − salidas − sin salida por más de 48 h = dentro al corte; aparte, entradas repetidas (ya estaba dentro), salidas sin entrada y lecturas sin ID. Las cifras de las otras pantallas cuentan distinto: la lista por turno usa el turno de cada guardia y sus márgenes de 2 h, y «Hoy» empieza a las 00:00. |
| `api/Tablero.php` | Cálculos del tablero (acciones `TABLERO`, `TABLERO_EXCEL`, `TABLERO_PDF`): entradas, salidas, personas por ID, visitas (entrada con su salida; más de 24 h no cuenta en horas), personas dentro hora por hora o máximo por día, tiempo dentro, más horas, incidencias. Archivos en `uploads/accesos/reportes/` (se borran al día). |
| `api/lib/xlsx.php` | Excel (.xlsx) propio, sin librerías ni extensión zip: hojas con títulos fijos, filtros, fechas y el ID como texto. |
| `personal.html` | **Personal y permisos** (ADMIN y PLANTA): la base de IDs y nombres (buscar, agregar, cambiar —cambiar el ID se lleva sus permisos—, quitar), **cargar Excel** (revisa primero: columnas reconocidas o elegidas a mano, nuevos, con cambios, renglones con problema —sin ID, sin nombre, un ID sin número (un nombre en la columna del ID) o el mismo ID con dos personas distintas: no se cargan—; el mismo ID con la misma persona queda el último renglón; no quita a nadie), descargar Excel, IDs leídos en la caseta que no están en la base, y **permisos** por ID con checklist (herramienta, celular, computadora, otro), foto del pase y/o datos del equipo y vigencia. `personal.html?id=287` abre a esa persona. |
| `api/Personal.php` | La base del personal (`personal`, llave = ID sin ceros) y sus permisos (`personal_permisos`; quitar = `activo` 0, queda historial). Acciones `PADRON_*` y `PERMISO_AGREGAR`/`PERMISO_QUITAR`. `PADRON_CAJA`: la copia para el teléfono de la caseta, con versión (`ca_ajustes.padron_version`). `registrar` e incidencias guardan el **nombre de la base** si el ID está (`Personal::nombreDe`). |
| `medico.html` | **Servicio médico** (perfil MEDICO; también ADMIN): toma de **tensión arterial**. 1) ¿Quién es?: escanear la credencial con la app (`LectorTarjeta.leer`, sin foto, con los `ids` de la base), escribir el ID o, sin credencial, el nombre; nombre y compañía salen de la copia de la base (`ca_padron`). 2) Sistólica/diastólica (con 3 dígitos pasa sola a la diastólica), frecuencia cardiaca, observaciones; **APTO / NO APTO automático** con los límites de `api/medico.json` (si marcan otro, pide la razón); la hora y quién tomó se guardan solos. «Volver a tomarle» (2.ª toma). Sin señal: cola propia `ca_medico_cola` (uid, se manda sola) y las tomas de hoy en `ca_medico_hoy`. La app abre `accesos.html`, que manda aquí al perfil MEDICO. |
| `api/Medico.php` | Tabla `medico_tomas` (uid, capturado, ID, nombre/compañía/cargo de la base, `en_base`, sistólica, diastólica, frecuencia, `categoria` NOM-030, `calculado` y `resultado` A/N, `toma` del día, observaciones, quién tomó). Acciones `MEDICO_REGLAS`, `MEDICO_REGISTRAR`, `MEDICO_EXISTEN`, `MEDICO_LISTAR` (hoy, todo el servicio médico), `MEDICO_PERSONA`; para el tablero `MEDICO_TABLERO`, `MEDICO_EXCEL` y `MEDICO_PDF` (ADMIN, PLANTA, CLIENTE; archivos `MEDICO_*` en reportes). Cada persona cuenta **una vez por día con su última toma**. El PDF es la bitácora en hoja horizontal (N.º, fecha, hora, ID, nombre, compañía, T. A., F. C., apto/no apto con X, observaciones, tomó) con cifras, no aptos y las gráficas de la pantalla. Los días omitidos tampoco cuentan. |
| `api/medico.json` | Reglas del servicio médico (sin tocar código): límites **139/89 a 100/60 mmHg** (NOM-030), frecuencia que sólo se avisa (60–100 lpm), título y subtítulo de la bitácora. |
| `omitidos.html` | **Días omitidos** (sólo ADMIN): los días con registros de los últimos 60 (cuántos, de qué hora a qué hora, con qué cuentas) para reconocer los de **prueba**; omitir un día completo o unas horas (antes dice cuánto se deja de contar; **hoy sólo hasta ahora**), volver a contar e historial. |
| `api/Omitidos.php` | Tabla `accesos_omitidos` (desde, hasta, motivo, quién; volver a contar = `activo` 0). **No borra nada:** `Omitidos::fuera($pdo, 'col')` es la condición SQL que deja fuera lo leído (`capturado`) en un periodo omitido y va en **todas** las consultas que cuentan: turnos y su PDF, `listar` (totales y numeración), En planta, movimientos por ID, incidencias, tablero y sus descargas, IDs fuera de la base, entrada abierta. **No** se filtra `ACCESOS_EXISTEN` ni la búsqueda por uid (la cola del teléfono). Acciones `OMITIDOS_LISTAR`, `OMITIR` (sin `aplicar` sólo revisa), `OMITIDO_QUITAR`. |
| `api/lib/xlsx_lector.php` | Lee .xlsx (abre el ZIP con zlib, sin extensión zip) y .csv (también los de Excel en Windows); regresa los renglones con su número de Excel. |
| `api/accesos-tarjeta.json` | Reglas del lector de la credencial (se ajustan sin reinstalar la app). `camara`: con cuál empieza el lector, **frontal** (la que se usa) o trasera. `digitosId` 5 y `minDigitos` 5: el ID impreso trae 5 dígitos (corrige las rayas de la impresora y nunca toma un pedazo del número). `subirFotos` (false: la foto de la credencial se queda en el teléfono) y `diasFotosLocales` (7). `appMinima`: versión de la app desde la que el lector trae lo último (la caseta y el servicio médico piden instalar la nueva si `disponible().version` es menor). |
| `api/lib/qrcode.php` | Generador de códigos QR (lo usa la portada de la maqueta). |
| `demo/` | **La maqueta** que hay que volver real: `control-accesos.html` (panel), `caseta.html` (guardia), `demo-datos.js` (datos ficticios y reglas), `index.html` (portada), `sala.php` (sincroniza la demo entre equipos). **Sólo Administración:** `solo-admin.js` regresa a su inicio a quien entre con otro perfil (sin sesión sí abre, para el teléfono que entra con el QR de la portada). |
| `mobile/caseta/` | App Android «AVBA Control de Accesos» (Capacitor): abre `accesos.html` y agrega el lector nativo de la credencial (CameraX + ML Kit sin internet, `AnalizadorTarjeta.java` con sus pruebas). Cámara frontal o trasera según la regla `camara`; el botón «Cambiar cámara» pasa a la otra y el teléfono la recuerda mientras la regla no cambie. `esperaNombre` (0 con la base del personal): tras confirmar el ID no espera el nombre. **Lectura continua** (`continuo`, eventos `lectura`/`cerrado`, `mostrar`, `detener`; `disponible()` responde `continuo: true`): la cámara no se cierra, cada credencial se avisa a la página, la misma credencial frente a la cámara no se reenvía (6 s), muestra en grande lo que dice la página (o «Leída» si no contesta en 2.5 s), con tono; salir = mantener 3 s. |
| `config/config.example.php` | Plantilla de la configuración (base propia). `config.php` vive sólo en el servidor. |
| `sql/esquema-inicial.sql` | Tablas de la base propia (cuentas, sesiones, registros, puntos). |
| `tests/referencia/` | Pruebas (PHP, API y pantallas con Playwright) contra la API propia. Todas: `bash tests/referencia/correr.sh` (una: `… correr.sh pantalla/sesion`). |
| `.github/workflows/` | `deploy-control-accesos.yml` (publica en `/controlaccesos/` en cada push a esta rama) y `app-caseta.yml` (compila y publica la app cuando cambia `mobile/caseta/`). **Encendidos** desde la fase 1. |

**Perfiles** (`Auth::ROLES`): **ADMIN** (Administración AVBA: todo, incluidas las cuentas),
**PLANTA** (administrador de accesos de la planta: registros, En planta, PDF, dar salida)
**ACCESOS** (guardia: sólo registra, consulta la copia de la base y agrega permisos con foto; el enrutador le rechaza todo lo demás; puede ser **equipo fijo**: `usuarios.modo` E sólo entradas | S sólo salidas, sin turno) **CLIENTE** (el cliente: sólo el tablero —también la pestaña Servicio médico—
y sus descargas; el enrutador le rechaza todo lo demás y `descargar.php` no le da fotos) y **MEDICO** (servicio médico: una cuenta por
médico o enfermera; sólo registra y consulta tomas de tensión arterial, baja la copia de la base y las reglas del lector; el enrutador le
rechaza todo lo demás y `descargar.php` no le da nada). Cada guardia tiene su **turno**
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
   del ID y parten etiquetas: «N OMBRE», «N SS»). Las rayas están siempre en el mismo lugar de la
   credencial, pero los números no (letra proporcional: el «1» es más angosto), así que en cada ID
   la raya cae en otro lado: entre dos números, pegada a uno o atravesándolo. Con la regla
   `digitosId` (5) el lector junta los pedazos del número («0 0287», «0028 7») y quita lo que
   sobra: **1) por su forma**: la app manda la caja de cada carácter (`Text.Symbol` →
   `AnalizadorTarjeta.Simbolo`, en `Linea.simbolos`); la raya es mucho más delgada que cualquier
   número (menos de la mitad de ancho; un «1» impreso mide cerca de ¾), sirve igual para cualquier
   ID y decide sin base (`rayasPorForma`); una raya delgada que completa 5 caracteres ocupa el
   lugar de un número que no se leyó: no es el ID. **2) sin la forma**, por lo que se leyó: «|»
   casi siempre es la raya, «I»/«l» casi, un «1» pegado a otro «1» o al final más que uno suelto
   (costos en `codigoDelId`). **3)** si quedan dos números posibles (`DUDA`), decide la base
   (`ids`) sólo si **una sola** opción está en ella y es de las más probables (si la más probable
   no está, puede ser alguien que aún no está en la base: no se le confunde con otra persona);
   sin base, no adivina. **Nunca toma un pedazo** (sería otra persona). El número con la raya
   encima se lee como la letra o el signo al que se parece («0» → «C», «Ø», «¢»; «4» → «A»;
   «5» → «$»; «7» → «T»), y la etiqueta también con la raya pegada («I|D:», «IID:», «ID|:»).
   `RayasSimulacionTest` lo prueba con miles de lecturas de IDs inventados, con las rayas como las de
   la impresora real y en cualquier lugar: con la forma, todas bien y nunca otro ID; sin la forma,
   sin base nunca otro ID (no adivina) y con la base a quien está en ella nunca se le confunde (sólo a
   alguien que aún no está, muy rara vez). Se confirma con 3 lecturas iguales entre las últimas 10 imágenes (tolera las
   borrosas) y, si no se distingue, la cámara dice qué lee en el recuadro («En el recuadro leo
   «…»»: `Lectura.enRecuadro`). Se probó también quitar las rayas de la imagen antes de leer: a la
   escala de la cámara frontal dañaba el «3» y no se usa. «N SS» partida no se toma como «N.»
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
7. **Foto de la credencial: sólo en el teléfono.** Cada lectura toma la foto de la credencial
   (de la misma imagen en la que se leyó el ID), pero **no se sube**: al servidor sólo va la
   información (ID, nombre de la base, hora, tipo). La foto se queda en el equipo (IndexedDB
   `ca_accesos`, almacén `fotos`, `diasFotosLocales` días, luego se borra sola) y el guardia la
   ve en su lista (`img[data-local]`, «foto guardada sólo en este equipo»). Lo mismo en las
   incidencias. **Sí se suben:** la foto del pase de un **permiso** (referencia) y la de un
   registro **sin ID ni nombre** (cámara del navegador: la foto es todo el registro).
   `subeFoto(it)` en `accesos.html`; la regla `subirFotos: true` (en `api/accesos-tarjeta.json`)
   vuelve a subirlas todas. Las fotos subidas viven en `uploads/accesos/` (protegida) y se
   sirven sólo con sesión; los registros de antes conservan la suya.
8. **Base del personal y permisos en la caseta:** la base es **sólo la del cliente** (su Excel,
   cargado en Personal y permisos, o lo que se agregue ahí a mano); lo que se escanea en la caseta
   son entradas y salidas y **nunca** entra a la base. El teléfono guarda una copia de la base
   (`ca_padron`) y funciona sin señal. Se actualiza al entrar, al volver la señal y **cada minuto**
   con la consulta de los avisos de 16 h (`ACCESOS_ALERTAS_16H` trae `padron_version`
   «base.permisos»): baja sólo si cambió, y si sólo cambiaron los permisos baja sólo los permisos
   (`PADRON_CAJA` con `solo_permisos`); así un permiso que agrega otro guardia llega a los demás
   teléfonos en menos de un minuto. **Datos móviles:** las respuestas de más de 1 KB van con gzip
   (`respuesta()` en `helpers.php`) y la lista del turno (`LISTAR_ACCESOS`) y las tomas del
   servicio médico (`MEDICO_LISTAR`) llevan `firma`: si no cambió, el servidor sólo responde
   `sin_cambios` (unos 100 bytes). Las fotos de credenciales no se suben (ver 7).
   `api_datos_test` mide lo que viaja (1,200 personas: la base 7 KB comprimida). Al leer: nombre de la base, sus permisos en la confirmación (dura 3.5 s si hay
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
   cámara a propósito (evento `cerrado`) se olvida y se vuelve a elegir. En la cámara, «Escribir
   ID» registra una credencial que no se lee (`manual`) sin cerrarla. Sus botones
   ENTRADA/SALIDA/INCIDENCIA siguen. Opcional: una cuenta de modo **E**/**S** (Cuentas,
   «Siempre ENTRADA/SALIDA») no elige, abre sola su tipo, no tiene turno (va por el **reloj**)
   y el servidor rechaza el otro tipo (`MODO_FIJO`). Cada `lectura` se registra (misma cola
   sin señal) salvo si esa persona ya se registró **del mismo tipo** en ese equipo hace menos
   de `repetirSeg` (120 s, en `api/accesos-tarjeta.json`): «YA REGISTRADA» en amarillo. A la
   app le manda `mostrar` (verde entrada, azul salida, amarillo con permiso vencido o ID
   fuera de la base). La app vieja (sin `continuo`) pide instalar la nueva y deja los botones.
10. **Aviso de las 16 h en planta** (`ControlAccesos::HORAS_LIMITE` 16, `HORAS_AVISO` 2,
   `alerta16`): quien cumple 16 h dentro en menos de 2 h sale con `alerta` «aviso» y quien ya
   las cumplió con «limite» (con `limite`, la hora en que las cumple). Franja `#banda-16` en
   `accesos.html` para la caseta y Administración (acción `ACCESOS_ALERTAS_16H`, permitida a la
   caseta: sólo ID, nombre y horas; se revisa cada minuto); En planta los pone primero y
   marcados; el tablero, su Excel y su PDF también.

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
