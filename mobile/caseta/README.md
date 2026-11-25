# AVBA Control de Accesos — app del guardia (Android)

App de la plataforma **Control de Accesos** (gestion.avba.com.mx/controlaccesos/)
para el guardia de la caseta. **Recolecta datos**: lee la credencial de acceso y
registra la **entrada** o la **salida** (nombre, ID, foto de la credencial y hora).
El análisis se hace en el perfil de administrador.

- **App ID:** `mx.com.avba.controlaccesos` · **Nombre:** AVBA Control de Accesos.
  Es OTRA app que «AVBA Caseta» (la que abre el control de accesos del sistema
  principal): se instala junto a ella mientras dura el cambio, con su propia
  llave de firma. El paquete Java sigue siendo `mx.com.avba.caseta`.
- **Descarga (APK):** `https://gestion.avba.com.mx/controlaccesos/descargas/avba-control-accesos.apk`
- **Compilación:** `.github/workflows/app-caseta.yml` (apagada hasta la fase 1; ver docs/PLAN.md)

## Cómo funciona

```
App ──abre──▶ gestion.avba.com.mx/controlaccesos/accesos.html (cuenta de caseta)
                             │ ENTRADA / SALIDA
                             ▼
                 LectorTarjeta (nativo, en el teléfono)
                   cámara (CameraX) + reconocimiento de texto (ML Kit, modelo
                   dentro de la app, sin internet) + AnalizadorTarjeta
                             │ { nombre, código }
                             ▼
            registro: tipo, hora, punto de control, nombre, ID y foto de la credencial
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
- **Foto de la credencial como evidencia:** cada lectura guarda también la foto de
  la credencial. Sale de la misma imagen de la cámara en la que se leyó el ID (de
  preferencia una en la que también se leyó el nombre), así que no hace falta otra
  foto ni más tiempo; si el ID se escribe a mano, va la imagen más reciente. Se
  envía con el registro (sin señal queda en el teléfono con él) y en el servidor
  se ve en la lista del turno, en las entradas y salidas de cada persona y en el
  PDF con fotos (con el ID y el nombre). `foto: false` en las reglas la apaga.
- **El ID es la llave de cada persona:** el número del recuadro azul de la
  credencial es único por persona. Se compara sin los ceros de la izquierda
  («00287» leído = «287» escrito a mano).
- **En planta** (administración, en Control de accesos → filtro «En planta»): una
  persona por ID cuya última lectura de las últimas 48 h fue ENTRADA, en cualquier
  punto; en cuanto registra su SALIDA (por cualquier punto) ya no aparece. Buscar
  por ID o nombre, descargar para Excel, y marca a quien lleva más de 13 h dentro.
- **Movimientos de un ID:** tocar a la persona (o su ID en la lista) muestra sus
  entradas y salidas de los últimos 30 días. Administración puede **dar salida** a
  quien salió sin pasar su credencial (queda marcada como registrada por
  Administración).
- **PDF de entradas y salidas** (administración, en Control de accesos → «PDF del
  turno»): junta la entrada y la salida de cada persona por su código; nombre,
  código, hora de entrada, hora de salida y tiempo dentro.
- En el navegador (sin la app) `accesos.html` sigue igual: foto de la
  identificación.

## El lector

Está ajustado a la **credencial de acceso** (de pie): `ID:` en el recuadro azul,
`NOMBRE:` (en uno o dos renglones), `EMPRESA:`, `CARGO:`, `NSS:`, `TIPO DE SANGRE:`,
`ALERGIAS:` y abajo «CREDENCIAL DE ACCESO».

- El código es el número del `ID:`. Descarta los demás números (NSS de 11 dígitos,
  teléfono, CURP, código postal, fechas, año de vigencia) aunque el reconocimiento
  lea la etiqueta y el dato por separado, y corrige confusiones típicas
  (O→0, I→1, S→5, B→8; «ID:0O287» → 00287).
- El nombre es lo que sigue a `NOMBRE:`; si continúa en el renglón de abajo
  (alineado y sin otra etiqueta) se junta. Lo que va junto a `EMPRESA:`, `CARGO:`,
  etc. nunca se toma como nombre; tampoco los letreros, oficios ni la marca de la
  cámara impresa en la foto. Un número dentro de una palabra del nombre se lee
  como letra («C0RONA» → CORONA).
- Entre varias imágenes gana el nombre más leído; una lectura parcial (sólo el
  primer renglón) cuenta a favor del nombre completo.
- El código se da por bueno cuando sale igual en 3 de las últimas 6 imágenes;
  si el nombre tarda, espera hasta 1.5 s por él.
- Recuadro del tamaño de la credencial (de pie; `orientacion: "horizontal"` en
  las reglas lo acuesta), lo que va leyendo en vivo, tocar para enfocar, linterna
  y **Escribir código** si no se lee.

### Ajustar la lectura sin reinstalar la app

`accesos.html` pide `api/index.php?action=ACCESOS_TARJETA_REGLAS`, que entrega
`api/accesos-tarjeta.json` (no depende de la demo; la demo lee el mismo archivo): número de dígitos del código (`longitud`), un patrón
propio (`patron`, expresión regular de Java; el código es su primer grupo),
palabras que van junto al código (`claves`), palabras que no son parte de un
nombre (`noNombre`), renglones cuyo número no es el código (`noCodigo`), cuántas
lecturas iguales se piden (`confirmar`) y la forma del recuadro (`orientacion`).

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
