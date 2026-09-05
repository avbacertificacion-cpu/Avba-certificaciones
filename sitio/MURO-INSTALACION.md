# Muro de operadores · Puesta en marcha

El muro ("Orgullo AVBA") permite que los visitantes envíen una fotografía de
su izaje y un comentario. **Nada aparece en el sitio hasta que alguien de
AVBA lo aprueba** desde el panel de moderación.

Mientras no se completen estos pasos, la sección se muestra vacía y el
formulario responde "el muro todavía no está habilitado". El resto del sitio
funciona con normalidad.

---

## 1. Crear la tabla en la base de datos

En el phpMyAdmin de Hostinger, selecciona la base de datos y ejecuta el
contenido de `sql/muro.sql`.

Puede ser la misma base que usa el sistema de certificaciones o una nueva; la
tabla se llama `muro_publicaciones` y no toca ninguna otra.

## 2. Crear el archivo de configuración

En el servidor, copia `config/config.example.php` como `config/config.php` y
rellena los valores. Ese archivo **no** está en el repositorio: es el único
lugar donde viven las credenciales, y está en `.gitignore` para que nunca
lleguen a GitHub.

Necesitas tres cosas:

- **Datos de la base**: host, nombre, usuario y contraseña.
- **Contraseña del panel**, guardada cifrada. No hace falta terminal: abre
  **https://www.avba.com.mx/generar-clave.php**, escribe la contraseña que
  quieras y esa página te da la línea exacta para pegar en `config.php`.
  La contraseña en claro no queda escrita en ningún archivo.

  Cuando ya puedas entrar al panel, borra `generar-clave.php` del servidor.

- **`IP_SALT`**: la genera la misma página `generar-clave.php`, junto con la
  contraseña. No hay que recordarla.

  Para qué sirve: el muro limita a 5 publicaciones por persona al día, y para
  contarlas hay que distinguir de algún modo a cada visitante. En lugar de
  guardar su dirección IP —un dato personal— se guarda una huella
  irreversible de ella. El problema es que las direcciones IPv4 son unos
  4 mil millones: cualquiera podría calcular la huella de todas y recuperar
  las originales. `IP_SALT` es una cadena secreta que se mezcla con la IP
  antes de calcular esa huella, y sin conocerla ese ataque deja de ser
  viable.

  Si algún día la cambias, lo único que ocurre es que los contadores del
  límite diario se reinician.

## 3. Permisos del directorio de fotografías

Las imágenes se guardan en `uploads/muro/`. El directorio se crea solo en el
primer envío; si el servidor no lo permite, créalo a mano con permisos `755`.

Verifica que `uploads/muro/.htaccess` haya subido: es el que impide que se
ejecute cualquier script dentro de esa carpeta. Si tu plan de Hostinger
ignora `php_flag`, avísame y lo resolvemos por otra vía.

---

## Herramientas de apoyo

| Página | Para qué sirve |
|---|---|
| `generar-clave.php` | Crea la contraseña del panel. Bórrala cuando termines. |
| `diagnostico.php` | Revisa que todo esté bien configurado y explica qué falta. |
| `moderacion.php` | El panel donde se aprueban las publicaciones. |

## Moderar publicaciones

Panel: **https://www.avba.com.mx/moderacion.php**

Entra con la contraseña del paso 2. Verás tres pestañas — Pendientes,
Aprobadas y Rechazadas — con el número de publicaciones en cada una.

En cada publicación puedes:

- **Aprobar**: aparece de inmediato en el sitio público.
- **Rechazar**: no se publica, pero el registro se conserva.
- **Eliminar**: borra el registro y la fotografía del servidor. No se puede
  deshacer.

El panel lleva `noindex`, así que no aparecerá en buscadores.

---

## Qué hace el sistema para protegerte

Estas medidas ya están implementadas y probadas:

| Riesgo | Cómo se controla |
|---|---|
| Se publica algo inapropiado | Todo nace como *pendiente*. Nada se publica sin aprobación. |
| Suben un script disfrazado de imagen | El tipo se determina por el contenido real, no por el nombre. La imagen se reconstruye desde cero, así que cualquier código incrustado se descarta. |
| Ejecución de código en `uploads/` | `.htaccess` que bloquea PHP y desactiva el motor en ese directorio. |
| Se filtra la ubicación de la planta de un cliente | Al reconstruir la imagen se eliminan los metadatos EXIF, que suelen incluir coordenadas GPS. |
| Bots y spam | Campo trampa invisible y máximo de 5 publicaciones por IP cada 24 horas. |
| Reclamo por derecho de imagen | Casilla de autorización obligatoria; el consentimiento se guarda junto a la publicación. |
| Robo de sesión del panel | Cookies `HttpOnly` y `SameSite=Strict`, token CSRF en cada acción y espera entre intentos de acceso. |
| Imágenes enormes que ralentizan el sitio | Se redimensionan a 1600 px por el lado mayor y se recomprimen. |

---

## Pendiente de definir

- **Aviso de privacidad.** La casilla de autorización cubre lo esencial, pero
  conviene enlazarla a un aviso de privacidad formal. Si me pasas el texto,
  lo publico y lo enlazo desde el formulario.
- **Aviso de publicación nueva.** Hoy hay que entrar al panel a revisar. Se
  puede añadir un correo automático cuando llegue algo pendiente.

---

# Logotipo de acreditación (ema)

La franja de acreditación ya está en el sitio, arriba de los servicios.
Mientras no exista el archivo del logotipo, el recuadro se oculta solo y el
texto se muestra igual: no queda ninguna imagen rota.

## Cómo colocar el logotipo

1. Consigue el archivo oficial que te entrega la ema (PNG con fondo
   transparente, o SVG).
2. Súbelo por el Administrador de archivos de Hostinger a la carpeta
   `assets/` del sitio, con este nombre exacto:

   ```
   assets/acreditacion-ema.png
   ```

3. Recarga la página. El logotipo aparece automáticamente; no hay que tocar
   código.

Si tu archivo es SVG o tiene otro nombre, mándamelo y ajusto la referencia.

## Antes de publicarlo, dos datos que faltan

- **El número de acreditación.** La ema pide normalmente que su símbolo se
  acompañe del número asignado y del alcance acreditado. Ya hay un espacio
  preparado en el diseño (`.acred-folio`) para colocarlo; solo hace falta el
  dato.
- **El alcance acreditado.** Conviene indicar para qué está acreditada la
  unidad, para que quede claro qué cubre la acreditación.

Conviene revisar las condiciones de uso del símbolo que entrega la propia
ema, porque suelen fijar reglas de tamaño, proporción y contexto.

---

# Videos en el muro

El muro acepta fotografías y videos (MP4, WebM y MOV, el formato del iPhone).

## Si ya tenías la tabla creada

Ejecuta `sql/muro-video.sql` en phpMyAdmin: agrega las tres columnas que el
video necesita. Si creas la tabla ahora desde cero con `muro.sql`, ya viene
todo incluido y no hace falta.

## Importante: el límite de subida del servidor

Este es el punto que decide si los videos funcionan o no.

Por omisión PHP acepta **2 MB por archivo**, y un video de celular pesa entre
20 y 100 MB. Con las fotografías esto se resolvió comprimiéndolas en el
navegador antes de enviarlas, pero **eso no es posible con video**: no hay
forma razonable de recomprimirlo desde el navegador.

Para que se puedan publicar videos hay que subir ese límite:

1. hPanel → **Avanzado** → **Configuración de PHP** → pestaña **Opciones**
2. Sube `upload_max_filesize` y `post_max_size` a **64M**
3. Guarda

El sitio se adapta solo: consulta el límite real del servidor y se lo muestra
al visitante en el formulario ("hasta 64 MB"). Si alguien elige un video más
pesado, se lo advierte **antes** de subirlo, en vez de dejarlo esperando para
fallar al final.

Mientras no subas el límite, los videos grandes seguirán rechazándose y solo
pasarán los muy cortos.

## Cómo se manejan los videos

- **Miniatura automática.** El navegador toma un fotograma y lo envía como
  imagen aparte. La galería muestra esa miniatura, así que la página no
  descarga los videos hasta que alguien decide reproducir uno.
- **Verificación del contenido.** Una fotografía se reconstruye desde cero, lo
  que elimina cualquier cosa incrustada; con un video no se puede hacer eso,
  así que se comprueba la firma binaria del archivo. Un script renombrado a
  `.mp4` se rechaza, y el `.htaccess` de `uploads/` impide que se ejecute
  nada dentro de esa carpeta.
- **Límite propio de 60 MB**, además del que imponga el servidor.
- En el panel de moderación puedes **ver el video completo antes de
  aprobarlo**.
