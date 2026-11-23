# AVBA Certificaciones — App móvil (Android / iOS)

App nativa para **Google Play** y **App Store**, hecha con **Capacitor 8**.

- **App ID / Bundle ID:** `mx.com.avba.certificaciones`
- **Nombre en la tienda:** AVBA Certificaciones · **Nombre bajo el ícono:** AVBA
- **Versión:** 2.0.0 (en `package.json`, `android/app/build.gradle` y el proyecto de Xcode)
- **Servidor:** `https://gestion.avba.com.mx`
- **Aviso de privacidad (para las tiendas):** `https://gestion.avba.com.mx/privacidad.html`

---

## 1. Cómo funciona

La app **lleva dentro todas las pantallas del portal** (login, inspector,
portal del cliente, calidad, certificaciones, administración, exámenes, etc.).
Por eso abre y se usa aunque el teléfono no tenga señal. El servidor sólo se
usa para traer y guardar datos.

```
 Teléfono                                            Servidor
 ┌───────────────────────────────────────────┐       ┌─────────────────────┐
 │ Pantallas (www/, dentro de la app)        │       │ gestion.avba.com.mx │
 │   │                                       │       │                     │
 │ js/app-shell.js  ── hay señal ───────────────────▶│ api/ , uploads/ ... │
 │   │  · guarda copia de lo que consulta    │       └─────────────────────┘
 │   │  · sin señal: responde con la copia   │
 │   │  · sin señal: guarda lo capturado en  │
 │   │    la «bandeja de salida» y lo envía  │
 │   │    solo, en orden, al volver la señal │
 │ Cámara · GPS · Red · Notificaciones       │
 └───────────────────────────────────────────┘
```

`js/app-shell.js` es la capa nativa. Sólo se activa dentro de la app (en el
navegador no hace nada), y:

1. **Manda las llamadas al servidor.** Las pantallas piden `api/...`,
   `uploads/...`, `descargar.php`, etc.; dentro de la app esas rutas se
   dirigen a `https://gestion.avba.com.mx`.
2. **Guarda una copia de todo lo que se consulta** (catálogos, checklists,
   clientes, listas, detalles) en el teléfono. Sin señal, la pantalla recibe
   la última copia guardada.
3. **Precarga lo del inspector** al entrar y cada 4 horas: catálogos,
   checklists de todos los tipos de equipo, clientes, inspecciones asignadas.
4. **Bandeja de salida.** Si se captura algo sin señal, se guarda en el
   teléfono con un número provisional. Lo que se capture después sobre ese
   registro (por ejemplo, los accesorios de una sesión creada sin señal) se
   encadena a él. Al volver la señal se envía todo en el mismo orden, y los
   números provisionales se cambian por los reales.
5. Muestra un **aviso de «Sin señal»** y un contador de **«N registros por
   enviar»**. Al tocarlo se puede reintentar o descartar un envío que el
   servidor rechazó.

La inspección de maquinaria conserva su propia cola (`js/offline-sync.js`),
que ahora también envía con la sesión vigente.

### Entrar con huella, rostro o Windows Hello

Funciona igual en la app y en el navegador de cualquier dispositivo
(`js/acceso-dispositivo.js` + `api/Dispositivos.php`):

| Dónde | Qué usa |
|---|---|
| App Android | Huella o rostro del teléfono (Keystore protegido por biometría) |
| App iPhone / iPad | Face ID o Touch ID (Keychain protegido por biometría) |
| Navegador en Windows | Windows Hello (rostro, huella o PIN) |
| Navegador en Mac | Touch ID |
| Navegador en iPhone / iPad / Android | Face ID, Touch ID, huella o rostro |

- La primera vez se entra con contraseña; enseguida se ofrece activarlo en
  ese dispositivo («Ahora no» lo vuelve a ofrecer en 30 días).
- En el navegador es una llave de acceso (passkey): la llave privada no sale
  del dispositivo y el servidor sólo guarda la pública. En la app, una
  credencial propia del teléfono guardada en el llavero, que sólo se lee con
  huella o rostro.
- En la app, al abrirla con sesión guardada se pide el sensor; sin señal, el
  sensor recupera la última sesión del teléfono y se sigue trabajando.
- La contraseña sigue funcionando siempre. En **Administración → Usuarios**,
  el botón 🔐 de cada cuenta muestra sus dispositivos y permite quitarlos (por
  ejemplo, si perdió el teléfono).
- Cada dispositivo tiene su propia sesión: entrar en la computadora ya no
  cierra la del teléfono, y cambiar de módulo en uno no cambia el otro.

### Qué funciona sin señal

| Funciona sin señal | Necesita señal |
|---|---|
| Abrir la app y entrar con una sesión ya iniciada, o con huella / rostro | **El primer inicio de sesión** en el teléfono |
| Inspección de maquinaria completa (checklist, fotos, firma, GPS) | Crear sesiones de curso (los participantes se registran en línea con el QR) |
| Inspección de accesorios y de arneses: crear sesión, capturar, fotos, enviar | Aprobar, emitir y enviar en Calidad y Certificaciones |
| Buscar clientes, ver catálogos y checklists | Ver o descargar los PDF (se abren en el navegador de la app) |
| Consultar listas y detalles ya vistos (se muestra la última copia) | Mapas (el GPS sí guarda la ubicación sin señal) |
| Registrar participantes en una sesión ya abierta | Lo que el teléfono nunca ha consultado |

---

## 2. Estructura

```
mobile/
├── src/index.html              Entrada: aplica la actualización pendiente y envía a la pantalla del rol
├── scripts/build-www.mjs       Arma www/ con las pantallas del portal + la capa de la app
├── scripts/paquete-servidor.mjs Arma el paquete de actualización (lo corre cada despliegue)
├── www/                        (generada, no se versiona)
├── assets/                     Ícono y splash fuente (1024 y 2732 px)
├── android/                    Proyecto de Android Studio (sí se versiona)
├── ios/                        Proyecto de Xcode, con Swift Package Manager (sí se versiona)
└── capacitor.config.json
```

Fuera de `mobile/`, en el sitio:

| Archivo | Para qué |
|---|---|
| `js/app-shell.js` | La capa de la app: servidor, copias sin señal, bandeja de salida, actualización |
| `js/app-sin-senal.js` | **Qué hace cada módulo sin señal** (el único que se toca al agregar módulos) |
| `js/destinos.js` | Pantalla de cada rol al entrar (la comparten el login y la app) |
| `js/acceso-dispositivo.js` | Entrar con huella, rostro o Windows Hello |

---

## 2.1 Actualización automática

Cada despliegue del sitio arma también el paquete de pantallas de la app y lo
publica en `https://gestion.avba.com.mx/app-paquete/` (`paquete.zip` y su ficha
`ultima.json`, con la huella SHA-256). Los teléfonos:

1. Revisan si hay versión nueva: al abrir, al volver al frente y al recuperar
   la señal; como mucho cada 30 minutos en wifi y cada 6 horas con datos.
2. La descargan solos (unos 2 MB) y verifican su huella.
3. La aplican **la siguiente vez que se abre la app desde cerrada, o en el
   inicio de sesión**. Nunca a media captura: en Android abrir la cámara manda
   la app a segundo plano, así que aplicarla «al pasar a segundo plano» podría
   recargar la pantalla a media inspección.
4. Si la versión nueva no arranca, el teléfono regresa solo a la anterior y esa
   versión no se vuelve a intentar.

Lo capturado sin señal no se pierde al actualizar: vive en el teléfono, no en
el paquete.

Se usa el plugin `@capgo/capacitor-updater` (código abierto) **con el servidor
propio**: sin suscripción y sin enviar estadísticas a terceros (`statsUrl: ""`).

**Cuándo SÍ hay que publicar en las tiendas:** sólo si cambia algo del
teléfono, es decir, un plugin nuevo (otro sensor, otra función nativa) o un
permiso nuevo. En ese caso:

1. Sube `avba.nativoMinimo` en `package.json` a la versión nueva de la app.
2. Publica esa versión en las tiendas (sección 8).

Los teléfonos con la app anterior no reciben pantallas que necesiten lo nuevo:
ven el aviso «Hay una versión nueva de AVBA en la tienda». Así nunca les llega
algo que su app no puede correr.

El servidor se actualiza al momento y los teléfonos minutos u horas después,
así que la API debe seguir aceptando lo que mandan las pantallas anteriores
(sobre todo lo que quedó en la bandeja de salida de alguien sin señal).

---

## 2.2 Cómo agregar un módulo nuevo

| Paso | ¿Hay que tocar la app? |
|---|---|
| Pantalla nueva (`algo.html` en la raíz) | No: entra sola al paquete. Las plantillas `dictamen_*`, `certificado_*` y `*_preview` se excluyen porque las arma el servidor |
| Rol nuevo | Sólo `js/destinos.js` (lo usan el sitio y la app) |
| PHP nuevo, imágenes en otras carpetas | No: todo `.php` y lo que no venga en el paquete se pide al servidor |
| Que sus consultas sirvan sin señal | No, si se llaman `GET_…`, `LISTAR_…`, `DETALLE_…`, `BUSCAR_…`, `OBTENER_…` o `SUGERENCIAS_…`. Si no, anótalas en `js/app-sin-senal.js` |
| Que se pueda **capturar** sin señal | Anota sus acciones en `js/app-sin-senal.js` (instrucciones dentro) |
| Que el inspector lo tenga en campo sin abrirlo antes | Anota la consulta en `precarga` de `js/app-sin-senal.js` |
| Usa una función nueva del teléfono | Sí: plugin, `nativoMinimo` y versión nueva en las tiendas (2.1) |

Con eso, al publicar el módulo en el sitio como siempre, llega a los teléfonos
solo.

---

## 3. Compilar sin computadora propia (GitHub Actions)

Hay dos flujos en `.github/workflows/`. Se corren desde GitHub → **Actions**:

| Flujo | Cuándo corre | Qué entrega |
|---|---|---|
| **App Android** (`app-android.yml`) | A mano, con cambios en `mobile/`, o con una etiqueta `app-v*` | Siempre un **APK de prueba**. Con firma, el **AAB** para Google Play; opcionalmente lo sube a pruebas internas |
| **App iOS** (`app-ios.yml`) | A mano o con una etiqueta `app-v*` | Sin secretos, comprueba que compila. Con secretos, **firma y sube a App Store Connect / TestFlight** |

El APK de prueba se descarga en la corrida (sección *Artifacts*) y se instala
directo en un Android (hay que permitir «instalar apps desconocidas»).

Para publicar una versión de las dos a la vez:

```bash
git tag app-v2.0.0 && git push origin app-v2.0.0
```

Ninguno de estos flujos toca el servidor. El despliegue a Hostinger es otro
flujo y excluye la carpeta `mobile/`.

### Secretos (GitHub → Settings → Secrets and variables → Actions)

| Secreto | Para | De dónde sale |
|---|---|---|
| `ANDROID_KEYSTORE_BASE64` | Android | Tu `.jks` en base64: `base64 -w0 avba-firma.jks` (Mac: `base64 -i avba-firma.jks`) |
| `ANDROID_KEYSTORE_PASSWORD` | Android | Contraseña del almacén |
| `ANDROID_KEY_ALIAS` | Android | Alias de la llave (p. ej. `avba`) |
| `ANDROID_KEY_PASSWORD` | Android | Contraseña de la llave |
| `PLAY_SERVICE_ACCOUNT_JSON` | Android, opcional | Cuenta de servicio con acceso a la app en Play Console |
| `ASC_KEY_ID` | iOS | App Store Connect → Users and Access → Integrations → App Store Connect API |
| `ASC_ISSUER_ID` | iOS | En esa misma pantalla, arriba |
| `ASC_KEY_P8` | iOS | El archivo `AuthKey_XXXX.p8` que se descarga al crear la llave (pega el contenido tal cual) |
| `IOS_CERT_PRIVATE_KEY` | iOS | Llave RSA (PEM) con la que se crea el certificado de distribución. Se genera una vez: `ssh-keygen -t rsa -b 2048 -m PEM -f ios_cert -q -N ""` (funciona en Windows) |

La firma de iOS la resuelven las herramientas de Codemagic
(`codemagic-cli-tools`, código abierto) dentro de GitHub Actions: con la llave
de API crean o reutilizan el certificado de distribución y el perfil. **No
hace falta una Mac.** La llave de API necesita el rol **App Manager** (si al
crear el certificado marca falta de permisos, usa **Admin**).

---

Los textos, capturas e imágenes para las dos tiendas están en
[`tienda/`](tienda/ficha-tiendas.md).

## 4. Publicar en Google Play

1. **Cuenta de desarrollador:** https://play.google.com/console — pago único
   de **25 USD**. Regístrala como **organización** (pide el número
   **D-U-N-S** de la empresa). Las cuentas personales nuevas deben hacer una
   prueba cerrada con 12 testers durante 14 días antes de publicar; las de
   organización no.
2. **Crea la llave de firma una sola vez y guárdala muy bien.** Si se pierde,
   no se puede volver a actualizar la app (salvo que actives *Play App Signing*,
   que Play ofrece al subir la primera versión: actívalo).
   ```bash
   keytool -genkeypair -v -keystore avba-firma.jks -alias avba \
     -keyalg RSA -keysize 2048 -validity 10000
   ```
3. Carga los 4 secretos de Android en GitHub y corre **App Android**. Descarga
   el artefacto `avba-android-play-aab`.
4. En Play Console: **Crear app** → nombre «AVBA Certificaciones», idioma
   español (México), App, Gratis.
5. **La primera versión se sube a mano:** Pruebas → Pruebas internas → Crear
   versión → sube el `.aab`. De ahí en adelante el flujo puede subirla solo
   (secreto `PLAY_SERVICE_ACCOUNT_JSON` y casilla «Subir a Google Play»), y la
   deja como borrador para que tú la publiques.
6. Llena la ficha y los formularios:
   - **Política de privacidad:** `https://gestion.avba.com.mx/privacidad.html`
   - **Acceso a la app:** «Toda la funcionalidad está restringida» → da un
     usuario y contraseña de prueba (ver sección 6).
   - **Seguridad de los datos:** se recopilan *fotos*, *ubicación precisa*,
     *nombre*, *correo* e *ID de usuario*, para la *funcionalidad de la app*;
     no se comparten con terceros; viajan cifrados (HTTPS); el usuario puede
     pedir que se borren (derechos ARCO del aviso de privacidad).
   - **Clasificación de contenido:** cuestionario → app de negocios, sin contenido sensible.
   - **Público objetivo:** mayores de 18.
   - Capturas de pantalla de teléfono (mínimo 2), ícono de 512 px
     (`assets/icon-only.png` reducido) y gráfico destacado de 1024×500.
7. Pasa la versión de pruebas internas a **Producción** y envíala a revisión
   (suele tardar de 1 a 7 días la primera vez).

## 5. Publicar en el App Store

1. **Cuenta de Apple Developer:** https://developer.apple.com/programs/enroll —
   **99 USD al año**. Inscríbela como **organización**; Apple pide el
   **D-U-N-S** de la empresa y un sitio web con el mismo nombre. La
   verificación tarda de unos días a 2 semanas.
2. En **App Store Connect → Apps → +**: nueva app iOS, nombre «AVBA
   Certificaciones», idioma español (México), Bundle ID
   `mx.com.avba.certificaciones` (si no aparece en la lista, regístralo en
   developer.apple.com → Identifiers), SKU `avba-certificaciones`.
3. Crea la **llave de API** (rol App Manager), carga los 4 secretos de iOS en GitHub
   y corre **App iOS**. En unos 15 a 30 minutos la compilación aparece en
   **TestFlight**. Instálala en tu iPhone con la app TestFlight para probarla.
4. Llena la ficha:
   - **Política de privacidad:** `https://gestion.avba.com.mx/privacidad.html`
   - **Privacidad de la app:** las mismas respuestas que en Google Play. La
     app ya trae su manifiesto (`ios/App/App/PrivacyInfo.xcprivacy`): sin
     rastreo; fotos, ubicación precisa, nombre, correo e ID de usuario para la
     funcionalidad de la app.
   - **Cifrado:** ya está declarado que sólo usa el cifrado estándar (HTTPS),
     así que no pide documentación de exportación.
   - **Información para la revisión:** usuario y contraseña de prueba (sección 6)
     y una nota como: *«App de uso profesional para inspectores y clientes de
     AVBA. Las cuentas las da de alta AVBA; no hay registro público. La app
     trabaja sin conexión: captura inspecciones con cámara y GPS y las envía
     al recuperar señal.»*
   - Capturas de iPhone de 6.9" (1320×2868) — mínimo 3.
5. Envía a revisión (normalmente 1 a 3 días).

> Si Apple considera que la app es sólo para tu empresa y tus clientes, puede
> sugerir publicarla como **app no listada** (*Unlisted*): se instala con un
> enlace directo y no aparece en las búsquedas. Funciona igual.

## 6. Cuenta de prueba para los revisores

Las dos tiendas exigen entrar a la app. Antes de enviar a revisión, crea desde
Administración una **cuenta de cliente de prueba** de una empresa ficticia
(«EMPRESA DEMO») con uno o dos certificados de ejemplo. No uses una cuenta
real, y tampoco una de inspector: un inspector ve la lista completa de
clientes. Las herramientas de izaje se revisan sin cuenta. Puedes desactivarla
después de la aprobación y volver a activarla para cada nueva revisión.

---

## 7. Compilar en tu computadora (opcional)

| Para… | Necesitas |
|---|---|
| Android | Android Studio (incluye el JDK 21) |
| iOS | Una Mac con Xcode 26 o más nuevo |
| Ambos | Node.js 22 o más nuevo |

```bash
cd mobile
npm install
npm run sync            # arma www/ y la copia a android/ e ios/
npm run open:android    # abre Android Studio  → Run ▶ o Build → Generate Signed Bundle
npm run open:ios        # abre Xcode (sólo en Mac) → Product → Archive
```

Para firmar en tu computadora, crea `android/keystore.properties` (no se sube
a git):

```properties
storeFile=/ruta/a/avba-firma.jks
storePassword=...
keyAlias=avba
keyPassword=...
```

Para probar en el navegador de la computadora sin teléfono:
`npm run build` y sirve `www/` con cualquier servidor local. La capa de la
app se activa con `window.__AVBA_APP_PRUEBA__ = true` antes de cargar
`app-shell.js`; para apuntar a otro servidor, usa `window.__AVBA_SERVIDOR__`.

## 8. Publicar una versión nueva en las tiendas

Sólo hace falta cuando cambia algo nativo (2.1); las pantallas se actualizan
solas.

1. Sube el número de versión en los tres lugares:
   - `package.json` → `"version"`
   - `android/app/build.gradle` → `versionName`
   - Xcode → `MARKETING_VERSION` (en `ios/App/App.xcodeproj/project.pbxproj`)

   Los números internos (`versionCode` de Android y número de compilación de
   iOS) los sube solo GitHub Actions en cada corrida.
   Si la versión nueva trae un plugin o permiso nuevo, sube también
   `avba.nativoMinimo` en `package.json` (ver 2.1).
2. Etiqueta y sube: `git tag app-v2.1.0 && git push origin app-v2.1.0`.
3. Revisa la versión en pruebas internas y TestFlight, y pásala a producción.

## 9. Íconos y splash

Las fuentes están en `assets/`: `icon-only.png` (1024, fondo blanco),
`icon-foreground.png` y `icon-background.png` (ícono adaptable de Android),
`splash.png` y `splash-dark.png` (2732, azul `#0B2545`). Si cambias alguno:

```bash
npm run icons && npx cap sync
```

## 10. Permisos que pide la app

| Permiso | Para qué | Cuándo se pide |
|---|---|---|
| Cámara | Fotos de evidencia de la inspección | La primera vez que se toma una foto |
| Fotos | Adjuntar fotos ya tomadas | Al elegir una foto de la galería |
| Ubicación (mientras se usa) | Registrar dónde se hizo la inspección | Al iniciar una inspección |
| Notificaciones | Recordatorios de vencimientos en el portal del cliente | La primera vez que hay un vencimiento próximo |
| Face ID / huella | Entrar sin escribir la contraseña, también sin señal | Al activar la entrada con huella o rostro |

Los textos que ve el usuario están en español en `ios/App/App/Info.plist`.
