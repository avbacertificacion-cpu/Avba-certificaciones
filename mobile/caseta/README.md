# AVBA Caseta — app del guardia (Android)

App aparte de **AVBA Certificaciones** para el guardia de la caseta. Lleva dentro
la pantalla del guardia (`demo/caseta.html`) y le agrega un **lector de la tarjeta
de identificación** que trabaja **en el propio teléfono, sin internet**.

- **App ID:** `mx.com.avba.caseta` · **Nombre:** AVBA Caseta
- **Descarga (APK):** `https://gestion.avba.com.mx/demo/app/avba-caseta.apk`
  (QR en la portada de la demo, sección «App del guardia para Android»)
- **Compilación:** `.github/workflows/app-caseta.yml` (cada cambio en
  `mobile/caseta/`, `demo/caseta.html` o `demo/demo-datos.js`)

## Cómo lee la tarjeta

```
ENTRADA / SALIDA ──▶ LectorTarjetaActivity (cámara, CameraX)
                        │  cada imagen: ML Kit reconoce el texto (modelo dentro de la app)
                        │  AnalizadorTarjeta: ¿cuál renglón es el nombre y cuál número el código?
                        │  se da por buena cuando el mismo código sale en 3 de las últimas 6 imágenes
                        ▼
                     { nombre, código } ──▶ pantalla del guardia: lo busca en el padrón
```

- La imagen **no sale del teléfono**: sólo pasan el nombre y el código.
- Descarta otros números de la tarjeta (teléfono, NSS, CURP, código postal,
  fechas, año de vigencia) y corrige confusiones típicas (O→0, I→1, S→5, B→8).
- El nombre puede venir en uno o en dos renglones; descarta la empresa, el
  puesto y los letreros («QUANTUM», «ENERGY», «SUPERVISOR», «VIGENCIA»…).
- En la pantalla del lector: recuadro del tamaño de la tarjeta, lo que va
  leyendo en vivo, tocar para enfocar, linterna, **Escribir código** (si no se
  lee) y **Simular lectura (demo)** (para los casos del guion).

### Ajustar la lectura sin reinstalar la app

Al abrir, la app pide `demo/sala.php?tarjeta=1`, que entrega
`demo/tarjeta-reglas.json`. Ahí se puede fijar el número de dígitos del código
(`longitud`), un patrón propio (`patron`, expresión regular de Java; el código es
su primer grupo), palabras que van junto al código (`claves`), palabras que no son
parte de un nombre (`noNombre`), renglones cuyo número no es el código
(`noCodigo`) y cuántas lecturas iguales se piden (`confirmar`).

Las pruebas del analizador están en
`android/app/src/test/java/mx/com/avba/caseta/AnalizadorTarjetaTest.java` y corren
en cada compilación.

## En la demo

- La app se conecta al panel del administrador con el **código de sala** de la
  portada (botón «Conectar» o menú ⋮). Sin sala, funciona sola en el teléfono.
- Si la tarjeta **no está en el padrón**, muestra lo leído para corregirlo y
  «Dar de alta» la registra como personal de Quantum Energy (operación `alta`
  de la sala); después sigue la revisión normal de entrada o salida.
- Los casos del guion (vetado, documento vencido, exceso de horas…) siguen
  saliendo con «Simular lectura (demo)».

## Compilar en una computadora

```bash
cd mobile/caseta
npm install
npm run sync          # arma www/ con la pantalla del guardia y sincroniza Android
npm run open          # abre Android Studio
```

## Firma

La APK de prueba se firma siempre con la misma llave de prueba, que crea el flujo
de GitHub la primera vez y guarda en su caché (nunca en el repositorio). Así cada
versión se instala encima de la anterior. Para publicarla en Google Play se
necesita una llave propia en los secretos del repositorio (`AVBA_KEYSTORE_*`, ver
`app/build.gradle`).
