# Instalación en el servidor nuevo de Hostinger

Guía paso a paso para poner en marcha el sistema de gestión documental de
**CH Arquitectura y Construcción, S.A. de C.V.** en su propio servidor.
Está pensada para seguirse en orden, sin experiencia previa.

> **Regla de oro:** este sistema va en un servidor (o dominio) **propio de CH**
> y con una base de datos **nueva y vacía**. Nunca uses la base de datos ni la
> carpeta `public_html` del sistema de AVBA. El instalador y el flujo de
> despliegue tienen candados para evitarlo, pero la regla es tuya.

---

## 0. Qué hay en esta carpeta

```
sistema/
├── index.html, css/, js/, img/   La aplicación que se ve en el navegador
├── api/index.php                 La API: todas las operaciones pasan por aquí
├── instalar.php                  Instalador (crea tablas y el primer administrador)
├── src/                          Código PHP (protegido: no se abre desde el navegador)
├── sql/esquema.sql               Estructura de la base de datos
├── config/config.ejemplo.php     Plantilla de configuración
├── config/config.php             (lo creas tú en el servidor; NO está en GitHub)
├── cron/diario.php               Tareas diarias (avisos, limpieza)
├── cron/cambiar_clave.php        Contraseña nueva para un usuario (por SSH)
├── storage/                      Datos del servidor (archivos si el almacenamiento es local)
└── docs/MIGRACION.md             Esta guía
```

Las carpetas `src/`, `config/`, `sql/`, `storage/`, `cron/` y `docs/` tienen
un `.htaccess` que impide abrirlas desde el navegador.

---

## 1. Lo que necesitas

| Qué | Para qué |
|---|---|
| Plan de Hostinger con **PHP 8.1 o superior**, **MySQL** y **acceso SSH** (Premium, Business o Cloud) | Donde corre el sistema |
| Un **dominio** o subdominio para CH (ej. `documentos.chconstrucciones.mx`) | La dirección del sistema |
| Cuenta de **Backblaze B2** | Donde se guardan los documentos de trabajo |
| Sólo si se contrata el **archivo muerto en frío** (Propuesta B): cuenta de **Amazon Web Services** | Almacenamiento de largo plazo (S3 Glacier Deep Archive) |

> Puedes empezar con el almacenamiento **local** (en el mismo servidor) y pasar
> a la nube después: ver el paso 12.

---

## 2. Preparar el servidor en Hostinger (hPanel)

1. **Dominio:** agrégalo a tu plan (hPanel → *Sitios web* → *Agregar sitio web*).
2. **Versión de PHP:** hPanel → *Avanzado* → *Configuración de PHP* → elige
   **PHP 8.2 o 8.3**. En la pestaña *Extensiones de PHP* verifica que estén
   activas: `pdo_mysql`, `curl`, `mbstring`, `openssl`, `simplexml`.
3. **SSL (candado https):** hPanel → *Seguridad* → *SSL* → instala el
   certificado gratuito para el dominio. Espera a que diga *Activo*.
4. **SSH:** hPanel → *Avanzado* → *Acceso SSH* → actívalo. Anota **IP**,
   **puerto**, **usuario** y la **contraseña** (sirven para GitHub, paso 10).

---

## 3. Crear la base de datos

hPanel → *Bases de datos* → *Bases de datos MySQL*:

1. Escribe un nombre (ej. `chc`) y un usuario (ej. `chc`). Hostinger les
   agrega un prefijo, quedan como `u123456789_chc`.
2. Pon una contraseña **larga** (usa el generador de Hostinger) y guárdala en
   un lugar seguro.
3. Pulsa *Crear*. Anota: **nombre de la base**, **usuario**, **contraseña**.
   El host casi siempre es `localhost`.

---

## 4. Subir los archivos del sistema

Elige **una** de las dos formas.

### Opción A — Desde GitHub (recomendada)

Usa el flujo **"Deploy CH Construcciones (servidor nuevo)"** (paso 10). Sube
el contenido de `chconstrucciones/sistema/` directo a la carpeta del sitio.

### Opción B — A mano con el Administrador de archivos

1. En GitHub, descarga el repositorio (rama `claude/ch-construcciones`) como ZIP.
2. Comprime **el contenido** de `chconstrucciones/sistema/` (no la carpeta en sí).
3. hPanel → *Archivos* → *Administrador de archivos* → entra a
   `domains/TU-DOMINIO/public_html/` → sube el ZIP → clic derecho → *Extraer*.
4. Verifica que `index.html`, `api/`, `instalar.php` y el archivo `.htaccess`
   queden directamente dentro de `public_html/`.

---

## 5. Crear `config/config.php`

1. En el Administrador de archivos entra a `public_html/config/`.
2. Copia `config.ejemplo.php` y nombra la copia **`config.php`**.
3. Edita `config.php` y cambia, por ahora, sólo la sección `db`:

```php
'db' => [
    'host'    => 'localhost',
    'nombre'  => 'u123456789_chc',     // el nombre de tu base
    'usuario' => 'u123456789_chc',     // el usuario de tu base
    'clave'   => 'LA-CONTRASEÑA-DE-LA-BASE',
],
```

Deja `almacen.driver` en `'local'` para la primera prueba. Guarda.

> `config.php` tiene contraseñas: **nunca** lo subas a GitHub ni lo mandes por
> chat o correo. Guarda una copia en un lugar seguro (gestor de contraseñas).

---

## 6. Ejecutar el instalador

1. Abre `https://TU-DOMINIO/instalar.php`.
2. Todos los requisitos deben salir con ✓. Si alguno sale ✗, la página dice cómo
   arreglarlo (casi siempre es activar una extensión en el paso 2).
3. Escribe la **contraseña de la base de datos** (comprueba que tú administras
   el servidor), tu nombre, tu correo y la contraseña del administrador
   (mínimo 8 caracteres).
4. Pulsa **Instalar**. Al terminar, el instalador queda bloqueado para siempre.
5. Entra a `https://TU-DOMINIO/` con tu correo y contraseña.

Si dice *"La base de datos ya tiene tablas de otro sistema"*: estás apuntando a
una base equivocada. Crea una nueva y vacía (paso 3) y cámbiala en `config.php`.

---

## 7. Almacenamiento en la nube: Backblaze B2

### 7.1 Crear el bucket

1. Entra a Backblaze → *B2 Cloud Storage* → *Buckets* → **Create a Bucket**.
2. Nombre único, sin puntos (ej. `chc-documentos-2026`), **Files in Bucket:
   Private**. Encriptación: *Enable* (recomendado). Object Lock: *Disable*.
3. En la lista de buckets anota el **Endpoint**, por ejemplo
   `s3.us-west-004.backblazeb2.com`. La **región** es la parte de en medio:
   `us-west-004`.
4. **Lifecycle Settings** del bucket → elige **"Keep only the last version of
   the file"**. Así, cuando el sistema borra un archivo, B2 lo elimina de verdad
   y no se sigue cobrando.
5. **No configures CORS desde la página de Backblaze.** Lo hace el propio
   sistema en el paso 7.3. (Si el bucket ya tiene reglas CORS puestas desde la
   página, quítalas: ponlas en *"Do not share"*.)

### 7.2 Crear la llave de acceso

1. *Application Keys* → **Add a New Application Key**.
2. Nombre: `sistema-chc`. *Allow access to Bucket(s)*: **sólo** el bucket del
   paso anterior. *Type of Access*: **Read and Write**.
3. Copia el **keyID** y el **applicationKey**. El segundo se muestra **una sola
   vez**: guárdalo en tu gestor de contraseñas.

### 7.3 Conectar el sistema

En `config/config.php`:

```php
'almacen' => [
    'driver' => 's3',
    'local_ruta' => __DIR__ . '/../storage/archivos',
    's3' => [
        'endpoint'   => 'https://s3.us-west-004.backblazeb2.com',   // tu Endpoint, con https://
        'region'     => 'us-west-004',
        'bucket'     => 'chc-documentos-2026',
        'key'        => 'EL-keyID',
        'secret'     => 'EL-applicationKey',
        'path_style' => true,
    ],
],
```

Luego en el sistema: **Usuarios → Diagnóstico**.

1. *Almacenamiento* debe decir **Funciona** en ambos renglones.
2. En *Subidas desde el navegador* pulsa **Configurar subidas** (crea la regla
   CORS que permite que el navegador suba directo a B2) y después
   **Probar subida**. Debe decir **Funciona**.

Si *Configurar subidas* responde **AccessDenied**, la llave limitada a un bucket
no tiene permiso para cambiar la configuración del bucket. Solución: pon
**temporalmente** en `config.php` la *Master Application Key* (keyID y key de la
cuenta), pulsa *Configurar subidas* otra vez y **regresa de inmediato** a la
llave limitada `sistema-chc`.

> Si algún día cambias de dominio, vuelve a pulsar *Configurar subidas*.

---

## 8. Archivo muerto

En `config.php`, sección `archivo_muerto`. Hay dos formas, según la propuesta
contratada.

### 8.1 Acceso inmediato (Propuesta A) — mismo bucket de B2

```php
'archivo_muerto' => [
    'modo'    => 'inmediato',
    'prefijo' => 'archivo-muerto/',
    'driver'  => 's3',
    'local_ruta' => __DIR__ . '/../storage/archivo-muerto',
    's3' => [ /* los mismos datos que en 'almacen' */ ],
    'clase' => '',
    // (el resto igual)
],
```

### 8.2 En frío (Propuesta B) — Amazon S3 Glacier Deep Archive

1. En AWS crea un bucket en **us-east-1**, nombre sin puntos (ej.
   `chc-archivo-muerto`), con *Block all public access* **activado**.
2. En **IAM** crea un usuario `sistema-chc` sin acceso a la consola, con esta
   política (cambia `chc-archivo-muerto` por tu bucket):

```json
{
  "Version": "2012-10-17",
  "Statement": [
    { "Effect": "Allow",
      "Action": ["s3:ListBucket", "s3:PutBucketCORS", "s3:GetBucketCORS"],
      "Resource": "arn:aws:s3:::chc-archivo-muerto" },
    { "Effect": "Allow",
      "Action": ["s3:PutObject", "s3:GetObject", "s3:DeleteObject", "s3:RestoreObject"],
      "Resource": "arn:aws:s3:::chc-archivo-muerto/*" }
  ]
}
```

3. Crea una **Access key** para ese usuario (tipo *Application running outside
   AWS*) y guarda el *Access key ID* y el *Secret access key*.
4. En `config.php`:

```php
'archivo_muerto' => [
    'modo'    => 'frio',
    'prefijo' => 'archivo-muerto/',
    'driver'  => 's3',
    'local_ruta' => __DIR__ . '/../storage/archivo-muerto',
    's3' => [
        'endpoint'   => 'https://s3.us-east-1.amazonaws.com',
        'region'     => 'us-east-1',
        'bucket'     => 'chc-archivo-muerto',
        'key'        => 'ACCESS-KEY-ID',
        'secret'     => 'SECRET-ACCESS-KEY',
        'path_style' => false,
    ],
    'clase' => 'DEEP_ARCHIVE',
    'dias_disponible' => 7,
    'gb_incluidos'    => 100,
],
```

5. En **Diagnóstico** pulsa *Configurar subidas* (aplica la regla CORS también
   a este bucket) y revisa que todo diga *Funciona*.

> En modo frío, una requisición **estándar** tarda unas 12 horas y una
> **económica** hasta 48. El sistema lo revisa solo y avisa por correo.

### 8.3 Cargar muchos archivos de una vez

Para cargas pequeñas usa **Archivo muerto → Cargar archivos**. Para el archivo
completo (varios GB o TB) usa **rclone** (programa gratuito, rclone.org) desde
una computadora con buena conexión:

1. Ordena las carpetas así: `AÑO/CLAVE - Nombre de la obra/Carpeta/archivo`.
   Ejemplo: `2019/CH-2019-014 - Bodega Celaya/Planos/Planta general.pdf`.
2. Configura el destino con `rclone config`:
   - Inmediato (B2): tipo **b2**, con el keyID y applicationKey.
   - Frío (AWS): tipo **s3**, proveedor **AWS**, región `us-east-1`,
     *storage_class* **DEEP_ARCHIVE**.
3. Copia (ejemplo con un destino llamado `frio`):

```
rclone copy "D:\Archivo muerto" frio:chc-archivo-muerto/archivo-muerto --progress
```

4. En el sistema: **Archivo muerto → Sincronizar catálogo**. Recorre todo lo
   subido y lo agrega al catálogo (se puede repetir sin duplicar).

---

## 9. Correos y tareas diarias

### 9.1 Correo de avisos

1. hPanel → *Correos* → crea la cuenta `avisos@TU-DOMINIO`.
2. En `config.php`:

```php
'correo' => [
    'activo'      => true,
    'remitente'   => 'avisos@TU-DOMINIO',
    'url_sistema' => 'https://TU-DOMINIO/',
],
```

El sistema avisa: documentos por vencer, documentos compartidos, requisiciones
por aprobar, rechazadas y listas para descargar.

### 9.2 Tarea diaria (cron)

hPanel → *Avanzado* → *Cron Jobs* → tipo **PHP** (o *Personalizado*):

- **Comando:** `/usr/bin/php /home/u123456789/domains/TU-DOMINIO/public_html/cron/diario.php`
  (usa la ruta que te muestra Hostinger para tu cuenta).
- **Horario:** todos los días a las 7:00 → `0 7 * * *`.

Qué hace cada día: avisa de vencimientos (30, 7 y 1 día antes, el día y el día
siguiente), revisa las restauraciones del archivo muerto, borra subidas que se
cancelaron y limpia registros viejos. Desde el navegador no hace nada (404).

---

## 10. Despliegue desde GitHub

En GitHub → el repositorio → *Settings* → *Secrets and variables* → *Actions* →
*New repository secret*. Crea estos **cinco** secretos con los datos del
**servidor nuevo** (paso 2.4):

| Secreto | Ejemplo |
|---|---|
| `CHC_SSH_HOST` | `153.92.xx.xx` |
| `CHC_SSH_PORT` | `65002` |
| `CHC_SSH_USERNAME` | `u123456789` |
| `CHC_SSH_PASSWORD` | (la contraseña SSH) |
| `CHC_RUTA_DESTINO` | `/home/u123456789/domains/TU-DOMINIO/public_html` |

Para desplegar: *Actions* → **Deploy CH Construcciones (servidor nuevo)** →
*Run workflow* → rama `claude/ch-construcciones` → escribe **DESPLEGAR** →
*Run workflow*.

- Sólo corre a mano; un `git push` no lo dispara.
- Se detiene si los datos de conexión son los del servidor de AVBA o si la ruta
  apunta a `gestion.avba.com.mx`.
- No borra nada en el servidor: `config/config.php` y `storage/` se conservan.

> El flujo **"Deploy CH Construcciones"** (el del demo) sigue subiendo la carpeta
> `chconstrucciones/` a `gestion.avba.com.mx/chconstrucciones/` con cada push.
> Ahí el sistema real aparece como *"Falta instalar"* y no hace nada mientras no
> exista un `config.php`. No lo instales ahí con la base de datos de AVBA.

---

## 11. Cerrar la instalación

1. **HTTPS obligatorio:** en el `.htaccess` de la raíz quita el `#` de las tres
   líneas de *Forzar HTTPS*.
2. En `config.php` deja `'debug' => false`.
3. Da de alta a los usuarios en **Usuarios** (hasta 100, con 7 roles) y asígnalos
   a sus obras.
4. **Respaldos:** hPanel → *Archivos* → *Respaldos* (diarios o semanales según el
   plan; incluyen la base de datos). Además, una vez al mes exporta la base desde
   *phpMyAdmin* → *Exportar* y guárdala fuera del servidor.

### Lista de verificación

- [ ] `instalar.php` dice *"El sistema ya está instalado"*.
- [ ] Diagnóstico: los dos almacenamientos **Funciona**.
- [ ] Diagnóstico: *Probar subida* **Funciona**.
- [ ] Subí un documento, una nueva versión y lo descargué.
- [ ] Compartí un documento con alguien fuera de la obra y le llegó el correo.
- [ ] Publiqué un reporte fotográfico y se ve en el celular.
- [ ] Archivo muerto: cargué/sincronicé y (en frío) una requisición llegó a *Disponible*.
- [ ] El cron aparece en hPanel y su última ejecución terminó bien.
- [ ] `https://TU-DOMINIO/config/config.php` y `https://TU-DOMINIO/src/` dan **403**.

---

## 12. Pasar del almacenamiento local a la nube

Si empezaste con `driver => 'local'` y ya hay documentos:

1. Crea el bucket y la llave (paso 7).
2. Copia los archivos **conservando las rutas**, desde el servidor por SSH o
   descargando la carpeta:

```
rclone copy public_html/storage/archivos b2:chc-documentos-2026 --progress
rclone copy public_html/storage/archivo-muerto b2:chc-documentos-2026 --progress
```

(Si el archivo muerto va en frío, la segunda línea va al bucket de AWS del paso 8.2.)

3. Cambia `driver` a `'s3'` (paso 7.3), revisa **Diagnóstico** y abre algunos
   documentos viejos para confirmar.
4. Cuando todo funcione, borra el contenido de `storage/archivos` (no la carpeta).

---

## 13. Si algo falla

| Síntoma | Causa probable | Qué hacer |
|---|---|---|
| *"Falta instalar el sistema"* | No existe `config/config.php` o no se ejecutó el instalador | Pasos 5 y 6 |
| Error de conexión a la base | Datos de `db` incorrectos | Revisa nombre, usuario y contraseña con prefijo `u123…_` |
| Al subir: *"Revisa la configuración CORS"* | Falta la regla CORS o cambió el dominio | Diagnóstico → *Configurar subidas* |
| *SignatureDoesNotMatch* | Llave o secreto mal copiados, o región incorrecta | Copia de nuevo la llave; la región debe coincidir con el endpoint |
| *AccessDenied* en Diagnóstico | La llave no tiene permiso sobre ese bucket | En B2: llave *Read and Write* para ese bucket; en AWS: revisa la política |
| *InvalidObjectState* al descargar | Archivo en frío sin restaurar | Haz una requisición (es lo esperado en modo frío) |
| Error 500 sin detalle | Error de PHP | Pon `'debug' => true` un momento, repite y lee el mensaje; luego regrésalo a `false` |
| No llegan correos | Correo desactivado o remitente de otro dominio | Paso 9.1; el remitente debe ser del mismo dominio |
| Olvidé la contraseña del administrador | — | Otro administrador le asigna una nueva en *Usuarios*. Si es el único: por SSH, en la carpeta del sitio, `php cron/cambiar_clave.php tu@correo.com NuevaContraseña` |
