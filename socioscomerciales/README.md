# Socios Comerciales AVBA

Portal tipo LinkedIn / bolsa de trabajo industrial (candidatos ↔ empresas) con
marca AVBA, abierto a empresas de todos los giros de la industria.
Sistema **independiente** del de certificaciones que vive en la raíz de este
repositorio: base de datos propia, sesiones propias y despliegue propio.

- **URL:** https://gestion.avba.com.mx/socioscomerciales
- **Rama:** `claude/socios-comerciales-portal-wco7s3`
- **Despliegue:** `.github/workflows/deploy-socioscomerciales.yml` (SCP, solo esta carpeta)

## Estructura

```
socioscomerciales/
├── index.html              Landing pública + muro del portal
├── login.html              Acceso
├── registro.html           Alta de cuenta (candidato / empresa)
├── verificar.html          Resultado del enlace de verificación de correo
├── terminos.html           Términos y Condiciones (público)
├── aviso-privacidad.html   Aviso de Privacidad (público)
├── admin.html              Panel de administración (solo SC_ADMINS)
├── recuperar.html          Solicitar y aplicar el restablecimiento de contraseña
├── inicio.html             Panel principal (distinto por tipo de cuenta)
│
│   — Candidato —
├── perfil-persona.html     Mi perfil: datos, CV, foto, experiencia, educación, habilidades
├── vacantes.html           Bolsa de trabajo con filtros
├── vacante.html            Detalle de vacante + postulación
├── mis-postulaciones.html  Seguimiento de postulaciones
├── empresas.html           Directorio de empresas
├── ver-empresa.html        Perfil público de una empresa
│
│   — Empresa —
├── perfil-empresa.html     Mi empresa: datos y logo
├── mis-vacantes.html       Publicar/editar vacantes y gestionar postulaciones
├── candidatos.html         Búsqueda de candidatos con filtros
├── ver-perfil.html         Perfil público de un candidato
│
├── assets/
│   ├── avba.css            Sistema de diseño compartido
│   └── avba.js             Sesión, API, navbar, toasts, iconos
├── api/
│   ├── index.php           Router (switch de acciones, respuestas JSON)
│   ├── Auth.php            Registro, login, verificación y contraseñas
│   ├── Personas.php        Perfil de candidato y búsqueda
│   ├── Empresas.php        Perfil de empresa y directorio
│   ├── Vacantes.php        Vacantes, postulaciones y avisos por correo
│   ├── Admin.php           Panel: listar, ver, bloquear y eliminar cuentas
│   ├── Feed.php            Muro: publicaciones, fotos y comentarios
│   ├── helpers.php         Sesiones, límites, subidas, envío de correo
│   └── diagnostico.php     Estado del servidor y del esquema
├── config/
│   ├── config.php          Credenciales reales (NO versionado)
│   ├── config.sample.php   Plantilla
│   └── database.php        scDB() + esquema y migraciones sc_*
├── uploads/                cv/ · fotos/ · logos/ · feed/ (protegido por .htaccess)
├── .htaccess               DirectoryIndex, Authorization, cabeceras de seguridad
└── .user.ini               Ajustes de PHP (el host corre PHP-FPM)
```

## Configuración en el servidor

Copiar `config/config.sample.php` a `config/config.php` y llenar:

| Constante     | Obligatoria | Descripción                     |
|---------------|-------------|---------------------------------|
| `SC_DB_HOST`  | Sí          | Host MySQL de la BD de socios   |
| `SC_DB_NAME`  | Sí          | Nombre de la BD                 |
| `SC_DB_USER`  | Sí          | Usuario                         |
| `SC_DB_PASS`  | Sí          | Contraseña                      |
| `SC_MAIL_FROM`| No          | Remitente de los correos        |
| `SC_URL_BASE` | No          | URL base si la detección falla  |
| `SC_DIAG_CLAVE`| Para el diagnóstico | Sin ella `?action=DIAGNOSTICO` responde 403 |
| `SC_ADMINS`   | Para el panel | Array de correos con acceso a `admin.html` |

`config.php` está en `.gitignore`, así que el despliegue nunca lo sobrescribe.

## Base de datos

Tablas con prefijo `sc_`: `sc_meta`, `sc_usuarios`, `sc_sesiones`, `sc_intentos`,
`sc_admin_log`, `sc_cv_accesos`, `sc_publicaciones`, `sc_comentarios`, `sc_personas`, `sc_experiencia`, `sc_educacion`, `sc_habilidades`,
`sc_empresas`, `sc_vacantes`, `sc_postulaciones`.

El esquema se crea y se migra solo. `sc_meta.schema_version` guarda la versión
aplicada; si es menor que `SC_SCHEMA_VERSION` (en `config/database.php`), se
ejecutan las migraciones pendientes en el siguiente request. Para añadir una
migración: subir la constante y agregar un método `migrarAN()`.

## Seguridad

Correcciones aplicadas tras una auditoría del API:

- **`scEsc()` escapa también las comillas.** Antes solo neutralizaba `< > &`,
  pero el texto se inserta además dentro de atributos (`alt=`, `href=`), así que
  un nombre con `"` cerraba el atributo e inyectaba `onload=` → **XSS almacenado**
  con robo del token de sesión. Nunca quites ese escape.
- **`scUrlBase()` no confía en la cabecera `Host`.** Se valida contra
  `SC_HOSTS_PERMITIDOS`; si no coincide se usa una URL fija. Sin esto, un
  `Host: evil.tld` en `SOLICITAR_RESET` mandaba a la víctima un correo legítimo
  cuyo enlace, con token válido, apuntaba al servidor del atacante.
- **Límite de intentos** (`sc_intentos`): 8 inicios de sesión por correo y 150
  por IP cada 15 min; 3 solicitudes de recuperación por correo; 5 registros por
  IP y 4 reenvíos de verificación por hora. Login compara contra un hash de
  relleno si el usuario no existe, para no delatarlo por tiempo.
- **Cambiar contraseña cierra las demás sesiones** y emite un token nuevo.
- **`GET_PERSONA_PUBLICA` es solo para empresas**; un candidato únicamente puede
  abrir su propio perfil público.
- **Sin el correo verificado no se escribe nada ni se ven perfiles ajenos**
  (ver más abajo). Antes bastaba con registrarse con una dirección inventada
  para publicar vacantes y recorrer el directorio de candidatos.
- `LISTAR_EMPRESAS` y `GET_EMPRESA_PUBLICA` ya **exigen sesión**: eran las dos
  únicas acciones que devolvían perfiles a cualquiera sin token.
- Textos truncados al largo real de su columna y fechas validadas: un valor
  demasiado largo provocaba error 1406 de MariaDB y el usuario veía un 500.
- El token **no se acepta por query string** (acabaría en logs y en `Referer`).
- Los errores de esquema y de conexión ya no exponen usuario ni nombre de la BD.
- `sitio_web` se valida como URL http(s) real, en servidor y en cliente.

### Segunda ronda de correcciones

- **El diagnóstico exige clave.** `SC_DIAG_CLAVE` era opcional y no venía en
  `config.sample.php`, así que en la práctica nadie la ponía: cualquiera podía
  leer versión de PHP, extensiones, versión de MariaDB y qué tablas existen.
  Ahora, sin clave definida, el endpoint responde 403.
- **El relleno de tiempos del login no era un hash bcrypt válido.**
  `password_verify` lo rechazaba de inmediato (66 ms frente a 263 ms de un
  hash real), así que el tiempo de respuesta delataba qué correos están
  registrados — justo lo que decía evitar. Ahora `SC_HASH_RELLENO` es un hash
  real y el coste se fija en `SC_BCRYPT_COSTE` para que no dependa de la
  versión de PHP del servidor. Al entrar se rehacen los hashes antiguos.
- **El registro tiene límite** (5 por IP y 3 por correo cada hora). Cada alta
  manda un correo: sin freno servía para inundar buzones ajenos y quemar la
  reputación del dominio. El reenvío de verificación también está limitado.
  El mensaje "ya existe una cuenta con ese correo" se mantiene porque sin él
  el registro es inusable; el límite es lo que hace inviable usarlo para
  averiguar quién está dado de alta.
- **Cabeceras de seguridad** en `.htaccess`: `X-Frame-Options`,
  `Content-Security-Policy`, `Referrer-Policy` y `Permissions-Policy`. El API
  añade `Cache-Control: private, no-store` — sus respuestas llevan correos y
  perfiles y no deben quedar en ninguna caché.
- **CORS acotado** a `SC_HOSTS_PERMITIDOS` en vez de `*`.
- **Contraseña mínima de 8** (`SC_PASSWORD_MIN`), en servidor y en cliente.
- Los comodines de `LIKE` se escapan (`scEscaparLike`): buscar `_` devolvía el
  padrón entero y obligaba a recorrer la tabla completa.
- `entregarCV` compara la ruta con la barra final incluida, para que un futuro
  `uploads/cv_publico/` no pase la comprobación por empezar igual.
- Las imágenes se **redibujan con GD** al subirlas: se va el EXIF (las fotos de
  teléfono llevan la geolocalización exacta) y se limitan a 1600 px.

### Sesiones por dispositivo

Antes la sesión era una sola columna en `sc_usuarios`, así que entrar desde el
teléfono cerraba la de la computadora sin avisar. Ahora viven en `sc_sesiones`,
una fila por dispositivo, y ambos perfiles muestran la lista con un botón para
cerrar las demás. La migración v5 copia las sesiones vivas, así que el
despliegue no echa a nadie fuera.

El token dura 7 días en vez de 30, pero se renueva solo mientras se use
(`SC_TOKEN_RENUEVA`): quien entra a diario no vuelve a escribir la contraseña y
uno robado caduca en una semana.

### Términos y Condiciones

El registro **exige aceptar** los Términos y el Aviso de Privacidad. La casilla
es obligatoria en el formulario, pero la comprobación de verdad está en
`ScAuth::registrar()`: quien llame al API directamente crearía cuentas sin
aceptar nada y la constancia dejaría de valer.

De cada alta se guarda **qué versión** se aceptó y **cuándo**
(`sc_usuarios.terminos_version` y `terminos_aceptados`, esquema v6). No basta
un "sí" suelto: si el texto cambia hay que poder saber quién aceptó cuál y a
quién toca volver a preguntarle. La versión vive en tres sitios y hay que
subirla en los tres a la vez:

| Archivo | Constante |
|---------|-----------|
| `api/helpers.php` | `SC_TERMINOS_VERSION` |
| `terminos.html` | `VERSION_TERMINOS` |
| `aviso-privacidad.html` | `VERSION_AVISO` |

Las cuentas creadas antes de la v6 quedan con esas columnas en `NULL`, que es
lo que ocurrió: nunca se les pidió. **No hay flujo para pedírselo a los que ya
existían**; si hace falta, se resuelve mostrando un aviso bloqueante en
`inicio.html` cuando `terminos_version` no coincide con la vigente.

> Los dos documentos son un **borrador de trabajo redactado por Claude**, no
> una asesoría legal. Describen con exactitud lo que el portal hace de verdad
> (qué datos toca, quién ve el CV, cómo se borra la cuenta), pero antes de
> publicarlos deben pasar por un abogado, sobre todo el domicilio social, el
> fuero y las direcciones de contacto, que hoy son `contacto@avba.com.mx` y
> `privacidad@avba.com.mx` y **tienen que existir**.

## Muro del portal (feed)

En **`index.html`**, debajo del hero. Publicaciones con texto y/o fotografía,
con comentarios. El componente entero vive en `assets/avba.js`
(`scMontarFeed('feed')`), así que se puede montar igual en otra página con una
línea.

### Público para leer, con cuenta para publicar, y todo moderado

`GET_FEED` y `GET_COMENTARIOS` **no piden sesión**: el muro lo lee cualquiera.
Lo que lo hace seguro es que **nada aparece hasta que administración lo
aprueba**. Sin esa moderación, un muro abierto publicaría en la portada de AVBA
el nombre, la foto y lo que escribiera cualquiera que se registrara.

| Quién llega | Qué ve | Qué puede hacer |
|---|---|---|
| Sin cuenta | El muro aprobado | Invitación a registrarse |
| Con cuenta sin confirmar | El muro aprobado | Aviso para verificar el correo |
| Cuenta verificada | El muro + lo suyo pendiente | Publicar y comentar |
| Administración | Todo | Aprobar, rechazar y borrar |

Quien publica **sigue viendo lo suyo aunque esté pendiente o rechazado**, con
el estado y el motivo. Si desapareciera sin más, lo volvería a intentar
pensando que falló el envío.

### El circuito de moderación

`sc_publicaciones.estado` es `pendiente` → `aprobada` | `rechazada`.

- En **`admin.html`**, el botón *"Moderar muro (N)"* lleva la cuenta de lo que
  espera. El número en el botón es lo que hace que alguien entre a revisar;
  enterrado en una métrica más, la cola se queda sin atender.
- **Rechazar no borra**: el autor sigue viendo su publicación con el motivo,
  así sabe por qué no salió. Para que desaparezca hay que borrarla.
- Una publicación aprobada se puede **retirar del muro** después (pasa a
  rechazada) sin perder el contenido.
- Cada decisión queda en `sc_admin_log` con el correo de quien la tomó.
- **Solo se comenta lo aprobado**: comentar algo pendiente dejaría respuestas
  colgando de una publicación que quizá nunca llegue a verse. `GET_COMENTARIOS`
  comprueba la visibilidad de la publicación antes de devolver nada, para que
  no se puedan leer los comentarios de algo sin aprobar pidiéndolo por su id.

La migración v9 da por **aprobado lo que ya estaba publicado**: se escribió
cuando el muro solo lo veían cuentas registradas, y dejarlo en pendiente sería
hacerlo desaparecer sin avisar a nadie.

### Quién puede borrar qué

- Su publicación o su comentario: **su autor**.
- Cualquier comentario en una publicación propia: **el dueño de la
  publicación** — si alguien le deja algo ofensivo en su muro no debería tener
  que esperar a que lo moderen.
- Todo: **administración** (`SC_ADMINS`).

Borrar NO exige el correo confirmado: quien quiera retirar algo suyo debe
poder hacerlo siempre.

### Límites y detalles

- **10 publicaciones y 40 comentarios por hora** y cuenta. La moderación para
  lo que llega al muro, pero sin límite la cola de revisión se inunda igual.
- Las fotos van a `uploads/feed/`, pasan por `scGuardarArchivo`, así que se
  **redibujan con GD**: se va el EXIF con la geolocalización y se limitan a
  1600 px. Máximo 6 MB.
- Borrar una publicación borra su archivo del disco; los comentarios se van
  por `ON DELETE CASCADE`. Borrar una cuenta se lleva todo lo que publicó.
- `PUBLICAR` viaja como **multipart** porque puede traer imagen
  (`scEnviarFormulario` en `avba.js`); el resto es JSON normal.
- Las publicaciones de cuentas bloqueadas desaparecen del muro: todas las
  consultas filtran por `u.activo = 1`.

## Aviso masivo a los postulantes

En **`mis-vacantes.html`**, al desplegar los candidatos de una vacante, cada
uno lleva una casilla. Seleccionando varios aparece una barra con **"Avisar que
están en revisión"**, que manda a todos un correo confirmando que su
postulación se recibió y que **sus documentos están siendo revisados por
`SC_REVISOR`** (en `api/helpers.php`, hoy "AVBA Inspections, Certifications and
Maintenance"). Se puede añadir un mensaje propio y, de paso, marcar sus
postulaciones como "En revisión".

Detalles que importan:

- El selector **"seleccionar los que se ven"** respeta el filtro de estatus: si
  estás en "Sin revisar", marca solo esos.
- El correo se manda **antes** de cambiar el estatus, y el cambio solo alcanza
  a los que seguían en "enviada". Al revés, alguien podría quedar marcado como
  "en revisión" sin que le hubiera llegado nada.
- La consulta filtra por `empresa_id`, así que un id de la postulación de otra
  empresa simplemente no devuelve fila y se cuenta como omitido.
- **Tope de 50 por envío** (`SC_MAX_AVISOS`) y 6 envíos por hora y empresa. Es
  la única acción del portal que manda muchos correos de golpe; sin freno, el
  dominio acaba marcado como spam. Si se seleccionan más de 50 se rechaza el
  lote entero con un mensaje, en vez de recortar en silencio y dejar a unos
  cuantos sin correo.
- El mensaje adicional lo escribe la empresa y va en un correo que firma AVBA,
  así que se escapa con `htmlspecialchars` + `nl2br`: no puede meter HTML.
- Los ids viajan como **texto separado por comas**, no como array: el router
  descarta del payload todo lo que no sea escalar (`scListaIds` los reconstruye).

## Administración

`admin.html` lista todas las cuentas del portal (candidatos y empresas), con
filtros por tipo y estado, búsqueda por nombre o correo y una ficha completa de
cada una: perfil, experiencia, educación, habilidades, postulaciones o
vacantes, sesiones abiertas y quién ha consultado su CV.

### Quién es administrador

Lo decide **`SC_ADMINS` en `config/config.php`**, un array de correos. No hay
columna en la base de datos, y es a propósito: ese archivo solo existe en el
servidor y no se versiona, así que ni un `INSERT` malicioso ni una fuga de la
base convierten a nadie en administrador.

El administrador **se registra primero como una cuenta normal** desde el
portal (candidato o empresa, lo que prefiera) y después se añade su correo a
la lista. Al entrar le aparece "Administración" en el menú. Para quitarle el
permiso basta con borrarlo de la lista.

```php
define('SC_ADMINS', ['tu-correo@avba.com.mx']);
```

### Bloquear y eliminar

- **Bloquear** pone `activo = 0` y **cierra todas sus sesiones al instante**
  — si no, quien ya estuviera dentro seguiría hasta que caducara su token. La
  cuenta desaparece de los listados porque todas las consultas del portal
  filtran por `u.activo = 1`. No borra nada y se deshace con un clic.
- **Eliminar** destruye la cuenta, sus archivos y sus publicaciones. Es
  irreversible, así que pide tres cosas: escribir el correo de la cuenta a
  mano, un motivo, y **la contraseña del propio administrador** — un token
  robado no debe bastar para vaciar el portal.

Ambas acciones quedan en `sc_admin_log` con el correo de quien las hizo, el de
la cuenta afectada, el motivo y la IP. Esa tabla **no tiene claves foráneas a
propósito**: cuando se borra una cuenta, el registro del borrado tiene que
sobrevivir, así que guarda una copia del correo en texto.

### Dos cosas que el panel no deja hacer

Un administrador **no puede bloquearse ni eliminarse a sí mismo**, ni tocar a
otro administrador. Las dos prohibiciones existen para lo mismo: si pudiera,
quedaría fuera de su propio panel y habría que arreglarlo a mano en la base de
datos. Para quitarle el permiso a alguien se edita `SC_ADMINS`.

### Datos personales (ARCO)

El portal guarda CV, teléfono, CURP e historial laboral, así que el titular
tiene que poder llevárselos y borrarlos. En ambos perfiles hay:

- **Descargar mis datos** (`EXPORTAR_DATOS`) — JSON con la cuenta, el perfil,
  experiencia, educación, habilidades, postulaciones y **qué empresas
  consultaron su CV**. El archivo se arma en el navegador para que ese JSON
  nunca viaje en una URL que acabe en un log.
- **Eliminar mi cuenta** (`ELIMINAR_CUENTA`) — pide la contraseña, porque un
  token robado no debe bastar para destruir una cuenta. Las filas hijas se van
  por `ON DELETE CASCADE` y los archivos se borran del disco.

`sc_cv_accesos` guarda quién abrió el CV de quién; nunca impide la descarga.

### Rendimiento y paginación

- Todos los listados traían un `LIMIT 60` fijo **sin desplazamiento**: a partir
  del resultado 61 el resto era invisible. Ahora aceptan `offset`, devuelven
  `total` y `hay_mas`, y las páginas tienen botón **"Ver más resultados"**.
- `MIS_POSTULACIONES`, `LISTAR_MIS_VACANTES` y `POSTULACIONES_VACANTE` no
  tenían límite ninguno; ahora traen 300 como tope.
- La búsqueda de candidatos lanzaba una consulta de habilidades **por cada
  candidato** (hasta 61 por pantalla); ahora es una sola con `IN`.
- El límite de login por IP subió a 150/15 min: una oficina entera puede salir
  por una sola IP pública y 30 dejaba fuera a todos. Entrar bien **borra** los
  intentos, porque antes ocho ingresos legítimos bloqueaban la cuenta.

### Acceso a los CV

Los PDF **ya no se sirven como archivos estáticos**: `uploads/cv/.htaccess`
deniega el acceso directo y los entrega `api/index.php?action=DESCARGAR_CV`,
que valida la sesión antes de escribir el archivo. Un candidato solo puede
abrir el suyo; una empresa con sesión puede abrir el de cualquier candidato,
que es la función de la bolsa de trabajo.

Como un `href` no puede llevar la cabecera `Authorization`, el frontend usa
`scVerCV(personaId)`: descarga el PDF como blob y lo muestra desde memoria, de
modo que el token nunca viaja en la URL.

Para restringirlo aún más a los candidatos que se postularon a las vacantes de
esa empresa, basta descomentar la comprobación marcada en
`ScPersonas::entregarCV()`.

Las fotos y logos siguen siendo públicos: se muestran con `<img>` en los
listados y no contienen datos personales sensibles.

## Contraseñas

- **Olvidé mi contraseña**: `recuperar.html` pide el correo y envía un enlace con
  token de 2 h. La respuesta es siempre la misma exista o no la cuenta, para no
  revelar qué correos están registrados.
- Al restablecerla se **cierran todas las sesiones abiertas** de esa cuenta.
- **Cambiar contraseña** con la sesión iniciada, desde ambos perfiles, exigiendo
  la contraseña actual.

## Verificación de correo

Al registrarse se genera un token (48 h de vigencia) y se envía un correo con
un enlace a `api/index.php?action=VERIFICAR_CORREO&t=…`, que marca la cuenta y
redirige a `verificar.html`.

Las cuentas verificadas llevan una insignia y aparecen primero en las búsquedas.

### Qué puede hacer una cuenta sin verificar

Mientras `correo_verificado = 0` la cuenta queda en **solo lectura de lo
propio**. El API lo aplica con `scSesionVerificada()` en `api/index.php`, que
responde `403` con `codigo: CORREO_NO_VERIFICADO`.

| Permitido sin verificar | Bloqueado hasta verificar |
|---|---|
| `LOGIN`, `LOGOUT`, `REGISTRO_*` | Editar perfil de persona o de empresa |
| `REENVIAR_VERIFICACION` | Subir CV, foto o logo |
| `SOLICITAR_RESET`, `RESTABLECER_PASSWORD`, `CAMBIAR_PASSWORD` | Experiencia, educación y habilidades |
| `GET_PERFIL_PERSONA` / `GET_PERFIL_EMPRESA` (el propio) | Crear, editar o borrar vacantes |
| `GET_INICIO` (sin la lista de postulantes) | Postularse y mover el estatus de una postulación |
| `BUSCAR_VACANTES`, `GET_VACANTE` (la bolsa es pública) | `LISTAR_EMPRESAS`, `GET_EMPRESA_PUBLICA`, `GET_PERSONA_PUBLICA`, `BUSCAR_CANDIDATOS`, `POSTULACIONES_VACANTE` |
| `LISTAR_MIS_VACANTES`, `MIS_POSTULACIONES` (propias) | `DESCARGAR_CV` de otra persona (el propio sí) |

Las excepciones son deliberadas: **entrar, pedir otro enlace y cambiar la
contraseña nunca se bloquean**, porque si el correo no llega la cuenta quedaría
inservible. Por lo mismo `GET_INICIO` sigue abierto — es lo que consulta el
botón "Ya lo confirmé" para saber si el enlace ya se abrió desde otro navegador.

La bolsa de trabajo sí se puede mirar: una vacante es un anuncio de la empresa,
no la ficha de una persona. Lo que no se puede es postularse.

En el frontend hay tres piezas en `assets/avba.js`, todas **cortesía visual**
(la regla vive en el API): `scVerificado()` lee el dato de la sesión local,
`scBloquearSinVerificar()` sustituye una pantalla entera por el aviso, y
`scSoloLecturaSinVerificar()` desactiva campos y botones salvo los marcados con
`data-sin-bloqueo` (aviso de verificación, tarjeta de contraseña, filtros de lo
propio).

## Logo

`assets/avba-logo.png` es un recorte del arte de mayor resolución disponible
(1346 px), quedándose con el isotipo y el wordmark **sin la palabra del giro**
(INSPECTIONS / CERTIFICATIONS), para que sirva a toda la industria y no solo a
un servicio. `assets/favicon.png` usa solo el isotipo, que es lo único legible
a tamaño de pestaña.

No recolorear el logo ni ponerlo sobre fondo oscuro: el arte tiene bordes
suavizados, así que reteñirlo deja halos y encajarlo en una placa blanca sobre
fondo oscuro parece calcomanía. Por eso las barras superiores son **blancas** y
el pie usa la marca en texto.

## Notas del host (Hostinger, PHP-FPM, MariaDB 11.8)

- **`php_value` en `.htaccess` provoca error 500** en toda la carpeta porque
  Apache no reconoce la directiva bajo PHP-FPM.
- **`.user.ini` tampoco se aplica en este host**: el diagnóstico devuelve los
  límites globales (`upload_max_filesize` de 1536M), no los del archivo. Por eso
  los ajustes que el portal necesita se fijan por código con `ini_set()` en
  `api/index.php`. El archivo se conserva por si el host habilita `user_ini`.
- `SHOW COLUMNS ... LIKE ?` con sentencias preparadas **nativas** falla en este
  MariaDB; por eso las migraciones listan columnas sin marcadores.
- `uploads/.htaccess` usa lista de bloqueo + `RewriteRule` como lista blanca,
  el mismo patrón que el sistema principal ya usa en producción.
- El correo sale por la función `mail()` de PHP.

## Aislamiento respecto a certificaciones

- Base de datos distinta (prefijo `sc_`, sin tocar tablas del sistema raíz).
- Cookie de sesión limitada a `/socioscomerciales`, fijada con `ini_set()` en
  `api/index.php` porque el host ignora `.user.ini`.
- El workflow filtra por `paths: socioscomerciales/**`, así que nunca sube ni
  borra archivos del sistema principal.
- `gestDB()` está escrita pero **desactivada** en `config/database.php`.

## Despliegue

El workflow sube por SCP con `overwrite: true`, que **sobrescribe pero nunca
borra**: un archivo que se quite del repositorio sigue vivo y accesible en el
servidor. No se le pone `--delete` a propósito, porque arrasaría con
`config/config.php` y con `uploads/`, que solo existen allí. Si se elimina un
archivo del portal, hay que borrarlo a mano por FTP o SSH.

## Pendiente

Activar `gestDB()` (solo lectura) para mostrar en los perfiles las
certificaciones AVBA verificadas del sistema principal.
