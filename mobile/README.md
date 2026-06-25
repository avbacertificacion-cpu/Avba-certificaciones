# AVBA Certificaciones — App móvil (Android / iOS)

App nativa para **Google Play** y **App Store** construida con **Capacitor**.
La app envuelve el portal web de producción (`https://gestion.avba.com.mx`)
dentro de un contenedor nativo y le agrega funciones del dispositivo
(notificaciones locales, cámara, splash, ícono, barra de estado).

> **Ventaja de este enfoque:** cuando actualizas el portal web (PHP/HTML),
> la app se actualiza sola — no necesitas volver a publicar en las tiendas,
> salvo que cambies algo nativo (ícono, permisos, plugins o versión).

- **App ID:** `mx.com.avba.certificaciones`
- **Nombre:** AVBA Certificaciones
- **Entrada:** `https://gestion.avba.com.mx/login.html` (enruta a cliente o inspector según rol)

---

## 0. Requisitos en tu computadora

| Para… | Necesitas |
|-------|-----------|
| Android | [Android Studio](https://developer.android.com/studio) + JDK 17 (lo instala Android Studio) |
| iOS | Una **Mac** con [Xcode](https://developer.apple.com/xcode/) (no se puede compilar iOS en Windows/Linux) |
| Ambos | [Node.js 18+](https://nodejs.org) (ya lo tienes: `node -v`) |

---

## 1. Instalar dependencias (una sola vez)

```bash
cd mobile
npm install
```

## 2. Generar los proyectos nativos

```bash
# Android
npm run add:android

# iOS (solo en Mac)
npm run add:ios
```

Esto crea las carpetas `android/` e `ios/` (ignoradas por git; se regeneran).

## 3. Generar íconos y splash automáticamente

Coloca un PNG de **1024×1024** en `assets/icon.png` (ya hay uno de 512;
reemplázalo por uno de 1024 para mejor calidad) y ejecuta:

```bash
npm run icons
```

Genera todos los tamaños de ícono y splash para Android e iOS a partir de
`assets/icon.png`, usando el azul corporativo `#0b3d91` como fondo.

## 4. Sincronizar y abrir

```bash
npm run sync          # copia config + plugins a los proyectos nativos
npm run open:android  # abre Android Studio
npm run open:ios      # abre Xcode (solo Mac)
```

---

## 5. Probar en un dispositivo / emulador

- **Android Studio:** botón ▶ (Run) con un emulador o tu teléfono en modo
  desarrollador (USB debugging).
- **Xcode:** selecciona un simulador o tu iPhone y pulsa ▶.

La app abrirá una pantalla de carga azul y luego el login del portal.

---

## 6. Compilar para publicar

### Android (.aab para Google Play)

1. En Android Studio: **Build → Generate Signed Bundle / APK → Android App Bundle**.
2. Crea un **keystore** (guárdalo MUY bien; sin él no puedes actualizar la app):
   ```bash
   keytool -genkey -v -keystore avba-release.keystore -alias avba -keyalg RSA -keysize 2048 -validity 10000
   ```
3. Genera el `.aab` firmado → ese archivo subes a Google Play.

### iOS (.ipa para App Store)

1. En Xcode: **Product → Archive**.
2. Con el archive listo: **Distribute App → App Store Connect → Upload**.
3. Requiere estar inscrito en el Apple Developer Program.

---

## 7. Crear las cuentas de desarrollador

### Google Play Console — pago único de **$25 USD**
1. Entra a <https://play.google.com/console> con una cuenta Google.
2. Paga el registro ($25, una sola vez de por vida).
3. Crea una **app** → completa ficha (nombre, descripción, capturas, ícono 512,
   política de privacidad — obligatoria).
4. Sube el `.aab`, define país/precio (gratis) y envía a revisión (1–3 días).

### Apple Developer Program — **$99 USD/año**
1. Necesitas un **Apple ID** y, idealmente, la app **Apple Developer**.
2. Inscríbete en <https://developer.apple.com/programs/> ($99/año).
   - Como empresa (recomendado) necesitas un **número D-U-N-S** (gratis, tarda
     unos días). Como persona física es inmediato.
3. En <https://appstoreconnect.apple.com> crea la app, sube el build desde Xcode,
   completa ficha + capturas + política de privacidad y envía a revisión (1–3 días).

> **Política de privacidad:** ya generada en `privacidad.html`.
> URL pública: `https://gestion.avba.com.mx/privacidad.html` — usa esa URL en ambas tiendas.

---

## 8. Requisitos para que NO te rechacen

Apple es estricto con apps que "sólo muestran un sitio web" (guía 4.2). Esta app
ya incluye valor nativo para pasar revisión:

- ✅ Notificaciones locales de vencimientos (`@capacitor/local-notifications`)
- ✅ Cámara nativa para fotos del inspector (`@capacitor/camera`)
- ✅ Splash screen y barra de estado nativas
- ✅ Manejo del botón atrás de Android
- ✅ Ícono y nombre propios

Para reforzar, en la ficha describe la app como **herramienta de gestión de
flotas y certificaciones con notificaciones y captura en campo**, no como "un
navegador de nuestra web".

---

## 9. Conectar las funciones nativas al portal

El archivo `../js/native-bridge.js` (en la raíz del proyecto PHP) expone
`window.AVBANative`. Para activar las notificaciones de vencimientos en la app,
incluye el script en `portal-cliente.html` e `inspector.html` y llama:

```html
<script src="js/native-bridge.js"></script>
<script>
  // Ejemplo: tras cargar las alertas de vencimiento
  AVBANative.notificarVencimientos([
    { titulo: 'Licencia por vencer', cuerpo: 'Juan Pérez — vence en 15 días', dias: 15 }
  ]);
</script>
```

Funciona sin cambios en el navegador (degrada a la Notification API del navegador)
y se vuelve nativo dentro de la app. Avísame y lo cableo automáticamente al
sistema de alertas que ya tienes en el portal.

---

## 10. Actualizaciones

- **Cambios sólo web** (PHP/HTML/JS del portal): se reflejan al instante, sin
  re-publicar.
- **Cambios nativos** (ícono, plugins, permisos, versión): incrementa la versión
  en `android/app/build.gradle` (`versionCode`/`versionName`) y en Xcode, vuelve a
  compilar y sube el nuevo build a cada tienda.
