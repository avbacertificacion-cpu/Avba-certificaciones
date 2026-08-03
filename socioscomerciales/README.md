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
├── index.html              Landing pública
├── login.html              Acceso
├── registro.html           Alta de cuenta (candidato / empresa)
├── verificar.html          Resultado del enlace de verificación de correo
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
│   ├── Auth.php            Registro, login, verificación de correo
│   ├── Personas.php        Perfil de candidato y búsqueda
│   ├── Empresas.php        Perfil de empresa y directorio
│   ├── Vacantes.php        Vacantes, postulaciones y avisos por correo
│   └── helpers.php         Tokens, subidas, envío de correo
├── config/
│   ├── config.php          Credenciales reales (NO versionado)
│   ├── config.sample.php   Plantilla
│   └── database.php        scDB() + esquema y migraciones sc_*
├── uploads/                cv/ · fotos/ · logos/ (protegido por .htaccess)
├── .htaccess               DirectoryIndex, Authorization, no-cache de HTML
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

`config.php` está en `.gitignore`, así que el despliegue nunca lo sobrescribe.

## Base de datos

Tablas con prefijo `sc_`: `sc_meta`, `sc_usuarios`, `sc_personas`,
`sc_experiencia`, `sc_educacion`, `sc_habilidades`, `sc_empresas`,
`sc_vacantes`, `sc_postulaciones`.

El esquema se crea y se migra solo. `sc_meta.schema_version` guarda la versión
aplicada; si es menor que `SC_SCHEMA_VERSION` (en `config/database.php`), se
ejecutan las migraciones pendientes en el siguiente request. Para añadir una
migración: subir la constante y agregar un método `migrarAN()`.

## Verificación de correo

Al registrarse se genera un token (48 h de vigencia) y se envía un correo con
un enlace a `api/index.php?action=VERIFICAR_CORREO&t=…`, que marca la cuenta y
redirige a `verificar.html`.

La verificación **no bloquea el uso del portal**: si el envío falla, la cuenta
sigue funcionando y se muestra un aviso con un botón para reenviar el enlace.
Las cuentas verificadas llevan una insignia y aparecen primero en las búsquedas.

## Logo

`assets/avba-logo.png` es un recorte del arte de mayor resolución disponible
(1346 px), quedándose con el isotipo y el wordmark **sin la palabra del giro**
(INSPECTIONS / CERTIFICATIONS), para que sirva a toda la industria y no solo a
un servicio. `assets/favicon.png` usa solo el isotipo, que es lo único legible
a tamaño de pestaña.

No recolorear el logo: el arte tiene bordes suavizados y reteñirlo deja halos.
Sobre fondos oscuros va dentro de una placa blanca (`.logo-chip`).

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

## Pendiente

Activar `gestDB()` (solo lectura) para mostrar en los perfiles las
certificaciones AVBA verificadas del sistema principal.
