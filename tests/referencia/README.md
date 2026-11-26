# Pruebas de Control de Accesos

Corren contra la **API propia** de la plataforma (`api/index.php`) en una copia local con
una base de prueba. Todos los datos son inventados (nombres, IDs de credencial, NSS).

## Cómo correrlas

```bash
bash tests/referencia/correr.sh                 # todas
bash tests/referencia/correr.sh pantalla/sesion # sólo las que contienen ese texto
```

El guion:
1. Crea en el MariaDB local (`127.0.0.1`, entra como root por el socket) la base
   `ca_prueba` con `sql/esquema-inicial.sql` y `sembrar-cuentas.sql` (cuentas `admin`,
   `planta`, `punto1…4`, contraseña «prueba-local», tokens fijos `tok-admin`,
   `tok-planta`, `tok-punto1`…). La vuelve a crear limpia antes de cada prueba.
2. Si no hay `config/config.php`, crea uno de prueba (no se sube; está en `.gitignore`)
   y se niega a seguir si el que hay no apunta a `ca_prueba`.
3. Instala mPDF con Composer si falta y levanta `php -S localhost:8794`.
4. Corre las pruebas. Playwright y Chromium ya vienen en el entorno de Claude Code.

Las capturas y PDF que dejan las pruebas (`*.png`, `*.pdf`) están en `.gitignore`.

## Qué cubre cada una

| Archivo | Qué comprueba |
|---|---|
| `php/prueba.php` | Migración de la tabla, registro con tarjeta sin foto, reenvío sin duplicar, lista, emparejar entrada/salida por ID, PDF de datos y PDF con fotos. |
| `php/limitada.php` | Base sin permiso de ALTER: el módulo sigue funcionando con fotos. |
| `php/en_planta.php` | ID como llave (00316 = 316), «En planta» (quien ya salió por cualquier punto no aparece; más de 13 h se marca), movimientos de un ID, dar salida, reglas del lector. |
| `php/turnos.php` | El turno de cada lectura: la salida va al turno de la entrada abierta de esa persona (17:57 → 17:58, día que sale tarde, llega muy temprano, sin ceros), y sin entrada decide el reloj. |
| `pantalla/turnos_pantalla_test.js` | El teléfono del Punto 1 con el guardia de día y luego el de noche (reloj simulado): cada uno ve sólo su turno; lo del de día a las 17:57 o 19:10 va al día; lo del de noche, a su noche, también sin señal y en la madrugada siguiente. |
| `php/nombre_pdf.php` | El nombre del PDF: una lectura parcial cuenta a favor del nombre completo. |
| `pantalla/api_plataforma_test.mjs` | Instalación (sólo con base vacía y con la clave), inicio de sesión, espera tras 5 fallos, cerrar sesión, cuentas (alta, validaciones, perfil, contraseña, desactivar), los tres perfiles (ADMIN, PLANTA, ACCESOS) y `descargar.php` sin sesión. |
| `pantalla/api_incidencias_test.mjs` | Incidencias por la API: validaciones, con foto, sin credencial, reenvío sin duplicar, la cola las confirma, listas por perfil y por punto, no mueven contadores ni «En planta», historial por ID, fotos con permiso, quién puede borrar. |
| `pantalla/incidencias_pantalla_test.js` | Botón INCIDENCIA: lector → ventana «¿Qué pasó?» con ID, nombre y foto; sin señal queda en cola y sale al volver; sin credencial; franja de aviso a Administración; historial de la persona; en el navegador sin la app. |
| `pantalla/api_turnos_cuenta_test.mjs` | Turnos por cuenta: guardia de día y de noche en el mismo punto y el mismo teléfono; cada registro e incidencia va al turno del guardia a cualquier hora (17:57, 20:40, madrugada); cada guardia ve su turno; turno y punto desde Cuentas; cambiar de turno, quitar, sin turno. |
| `pantalla/api_puntos_test.mjs` | API de puntos de control: cuenta sin punto, un solo equipo por punto, liberar, mover la cuenta de punto. |
| `pantalla/sesion_pantalla_test.js` | Pantallas: crear la primera cuenta, inicio por perfil, cuentas, cuenta regresiva, planta sin «Cuentas», el guardia directo a registrar, «Salir», claves `ca_` y que la sesión del Sistema de Gestión (`avba_session`) no se toca. |
| `pantalla/puntos_pantalla_test.js` | La caseta entra a su punto y ve su avance; otro equipo queda bloqueado; Administración libera y asigna. |
| `pantalla/pendientes_test.js` | Lo pendiente sin señal va con su cuenta; si el punto queda en otro equipo no se pierde; el bloqueo se quita solo. |
| `pantalla/planta_pantalla_test.js` | Vista «En planta»: buscar por ID o nombre, movimientos, dar salida, por punto, Excel. |
| `pantalla/evidencia_test.js` | Foto de la credencial como evidencia: con foto, ID a mano, sin señal, verla en la administración y en el PDF. |
| `pantalla/accesos_tarjeta_test.js` | Pantalla con API simulada: lectura de la credencial, confirmación, PDF, navegador sin app. |

Lo que no se puede probar aquí: la cámara real y el lector nativo (sólo en el teléfono;
`AnalizadorTarjetaTest.java` corre en cada compilación de la app en GitHub).
