# AVBA Caseta — app del guardia (Android)

App aparte de **AVBA Certificaciones** para el guardia de la caseta. Sólo
**recolecta datos**: lee la tarjeta de identificación y registra la **entrada** o
la **salida** (nombre, código y hora) en el **control de accesos del sistema**.
El análisis se hace en el perfil de administrador.

- **App ID:** `mx.com.avba.caseta` · **Nombre:** AVBA Caseta
- **Descarga (APK):** `https://gestion.avba.com.mx/demo/app/avba-caseta.apk`
- **Compilación:** `.github/workflows/app-caseta.yml` (cada cambio en `mobile/caseta/`)

## Cómo funciona

```
App AVBA Caseta ──abre──▶ gestion.avba.com.mx/accesos.html (cuenta de caseta)
                             │ ENTRADA / SALIDA
                             ▼
                 LectorTarjeta (nativo, en el teléfono)
                   cámara (CameraX) + reconocimiento de texto (ML Kit, modelo
                   dentro de la app, sin internet) + AnalizadorTarjeta
                             │ { nombre, código }
                             ▼
            registro: tipo, hora, punto de control, nombre, código (sin foto)
            → cola del teléfono (sin señal se envía después) → servidor
```

- La app **no lleva las pantallas dentro**: abre el control de accesos del sitio
  (`capacitor.config.json` → `server.url` y `server.appStartPath`). Cualquier
  cambio en `accesos.html` llega a la app con el despliegue normal del sitio.
  Para abrirla se necesita internet; ya abierta, lo registrado sin señal se
  guarda en el teléfono y se envía solo. Sin conexión al abrir, muestra
  `sin-conexion.html` (con «Reintentar»).
- El guardia inicia sesión con la **cuenta de caseta** (rol ACCESOS): sólo
  registra y ve sus propios registros del turno.
- La imagen de la tarjeta **no sale del teléfono**: al servidor sólo llegan el
  nombre y el código (`api/ControlAccesos.php`, columnas `nombre`, `codigo` y
  `origen` = tarjeta | manual).
- **PDF de entradas y salidas** (administración, en Control de accesos → «PDF del
  turno»): junta la entrada y la salida de cada persona por su código; nombre,
  código, hora de entrada, hora de salida y tiempo dentro.
- En el navegador (sin la app) `accesos.html` sigue igual: foto de la
  identificación.

## El lector

- Descarta otros números de la tarjeta (teléfono, NSS, CURP, código postal,
  fechas, año de vigencia) y corrige confusiones típicas (O→0, I→1, S→5, B→8).
- El nombre puede venir en uno o en dos renglones; descarta la empresa, el
  puesto y los letreros («QUANTUM», «ENERGY», «SUPERVISOR», «VIGENCIA»…).
- El código se da por bueno cuando sale igual en 3 de las últimas 6 imágenes;
  si el nombre tarda, espera hasta 1.5 s por él.
- Recuadro del tamaño de la tarjeta, lo que va leyendo en vivo, tocar para
  enfocar, linterna y **Escribir código** si no se lee.

### Ajustar la lectura sin reinstalar la app

`accesos.html` pide `demo/sala.php?tarjeta=1`, que entrega
`demo/tarjeta-reglas.json`: número de dígitos del código (`longitud`), un patrón
propio (`patron`, expresión regular de Java; el código es su primer grupo),
palabras que van junto al código (`claves`), palabras que no son parte de un
nombre (`noNombre`), renglones cuyo número no es el código (`noCodigo`) y cuántas
lecturas iguales se piden (`confirmar`).

Pruebas del analizador:
`android/app/src/test/java/mx/com/avba/caseta/AnalizadorTarjetaTest.java` (corren
en cada compilación).

## Compilar en una computadora

```bash
cd mobile/caseta
npm install
npm run sync          # páginas locales (sin conexión) y sincroniza Android
npm run open          # abre Android Studio
```

## Firma

La APK de prueba se firma siempre con la misma llave de prueba, que crea el flujo
de GitHub la primera vez y guarda en su caché (nunca en el repositorio). Así cada
versión se instala encima de la anterior. Para publicarla en Google Play se
necesita una llave propia en los secretos del repositorio (`AVBA_KEYSTORE_*`, ver
`app/build.gradle`).
