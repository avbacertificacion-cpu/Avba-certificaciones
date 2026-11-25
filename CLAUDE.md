# AVBA · Control de Accesos — instrucciones del proyecto

Plataforma **independiente** de AVBA para el control de accesos de planta: entradas y
salidas del personal (contratistas y planta) con la **credencial de acceso**, leída por
el teléfono del guardia en la caseta, y el panel del administrador.

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
| `index.html` | Entrada: sin sesión, «Iniciar sesión»; con sesión, el menú del perfil (Control de accesos, Cuentas, Mi contraseña, Maqueta). |
| `login.html` | Inicio de sesión propio. Con la base vacía ofrece crear la primera cuenta ADMIN con la `CLAVE_INSTALACION` de `config.php`. |
| `cuentas.html` | Cuentas (sólo ADMIN): alta, nombre, perfil, contraseña, desactivar. |
| `descargar.php` | Fotos de credenciales y PDF, sólo con sesión (el guardia, sólo sus fotos). |
| `api/index.php` | Enrutador propio: sesión, cuentas y las acciones de `accesos.html`. |
| `api/Auth.php`, `api/helpers.php` | Cuentas, sesiones por equipo, límite de intentos; conexión a la base propia y utilidades. |
| `js/destinos.js` | Pantalla de inicio de cada perfil y la clave de la sesión (`ca_sesion`). |
| `accesos.html` | Módulo real que ya funcionaba en el Sistema de Gestión: pantalla del guardia (lee la credencial con la app, ENTRADA/SALIDA, cola sin señal, foto de evidencia) y del administrador (registros por turno, **En planta**, movimientos por ID, dar salida, PDF, puntos de control). |
| `api/ControlAccesos.php` | Lógica del servidor del módulo (registros, turnos, puntos y equipos, en planta, PDF con mPDF). |
| `api/accesos-tarjeta.json` | Reglas del lector de la credencial (se ajustan sin reinstalar la app). |
| `api/lib/qrcode.php` | Generador de códigos QR (lo usa la portada de la maqueta). |
| `demo/` | **La maqueta** que hay que volver real: `control-accesos.html` (panel), `caseta.html` (guardia), `demo-datos.js` (datos ficticios y reglas), `index.html` (portada), `sala.php` (sincroniza la demo entre equipos). |
| `mobile/caseta/` | App Android «AVBA Control de Accesos» (Capacitor): abre `accesos.html` y agrega el lector nativo de la credencial (CameraX + ML Kit sin internet, `AnalizadorTarjeta.java` con sus pruebas). |
| `config/config.example.php` | Plantilla de la configuración (base propia). `config.php` vive sólo en el servidor. |
| `sql/esquema-inicial.sql` | Tablas de la base propia (cuentas, sesiones, registros, puntos). |
| `tests/referencia/` | Pruebas (PHP, API y pantallas con Playwright) contra la API propia. Todas: `bash tests/referencia/correr.sh` (una: `… correr.sh pantalla/sesion`). |
| `.github/workflows/` | `deploy-control-accesos.yml` (publica en `/controlaccesos/` en cada push a esta rama) y `app-caseta.yml` (compila y publica la app cuando cambia `mobile/caseta/`). **Encendidos** desde la fase 1. |

**Perfiles** (`Auth::ROLES`): **ADMIN** (Administración AVBA: todo, cuentas y asignar
puntos), **PLANTA** (administrador de accesos de la planta: registros, En planta, PDF,
dar salida, liberar el equipo de un punto) y **ACCESOS** (guardia: sólo registra en su
punto; el enrutador le rechaza todo lo demás).

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
4. **Turnos:** día 06:30–18:30 y noche 18:30–06:30; una entrada cuenta para el turno
   que empieza (desde 2 h antes) y una salida para el que termina (hasta 2 h después).
5. **Puntos de control:** 4 fijos («PUNTO DE CONTROL 1…4»); cada uno con su cuenta de
   caseta y **un solo equipo** (el primero que entra queda ligado; Administración lo
   libera).
6. **Foto de evidencia:** cada lectura guarda la foto de la credencial (sale de la misma
   imagen en la que se leyó el ID). Las fotos viven en `uploads/accesos/` (protegida) y
   se sirven sólo con sesión.

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
