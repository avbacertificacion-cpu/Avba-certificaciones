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
- **Contraseña del panel**, guardada como hash. Genérala una sola vez con:

  ```
  php -r "echo password_hash('LA_CONTRASENA_QUE_QUIERAS', PASSWORD_DEFAULT), PHP_EOL;"
  ```

  Pega el resultado en `MURO_ADMIN_HASH`. La contraseña en claro no se
  guarda en ningún archivo.

- **`IP_SALT`**: cualquier cadena larga y aleatoria. Sirve para limitar el
  abuso sin almacenar las direcciones IP de los visitantes.

## 3. Permisos del directorio de fotografías

Las imágenes se guardan en `uploads/muro/`. El directorio se crea solo en el
primer envío; si el servidor no lo permite, créalo a mano con permisos `755`.

Verifica que `uploads/muro/.htaccess` haya subido: es el que impide que se
ejecute cualquier script dentro de esa carpeta. Si tu plan de Hostinger
ignora `php_flag`, avísame y lo resolvemos por otra vía.

---

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
