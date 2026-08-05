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

- **`IP_SALT`**: cualquier cadena larga y aleatoria. Sirve para limitar el
  abuso sin almacenar las direcciones IP de los visitantes.

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
