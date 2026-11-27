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
   `planta`, `cliente`, `punto1…4`, `noche1…2`, contraseña «prueba-local», tokens fijos
   `tok-admin`, `tok-planta`, `tok-cliente`, `tok-punto1`…). La vuelve a crear limpia antes
   de cada prueba.
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
| `pantalla/turnos_pantalla_test.js` | Un teléfono con el guardia de día y luego el de noche (reloj simulado): cada uno ve sólo su turno; lo del de día a las 17:57 o 19:10 va al día; lo del de noche, a su noche, también sin señal y en la madrugada siguiente. |
| `php/nombre_pdf.php` | El nombre del PDF: una lectura parcial cuenta a favor del nombre completo. |
| `pantalla/api_plataforma_test.mjs` | Instalación (sólo con base vacía y con la clave), inicio de sesión, espera tras 5 fallos, cerrar sesión, cuentas (alta, validaciones, perfil, contraseña, desactivar), los tres perfiles (ADMIN, PLANTA, ACCESOS) y `descargar.php` sin sesión. |
| `pantalla/api_incidencias_test.mjs` | Incidencias por la API: validaciones, con foto, sin credencial, reenvío sin duplicar, la cola las confirma, listas por perfil y por punto, no mueven contadores ni «En planta», historial por ID, fotos con permiso, quién puede borrar. |
| `pantalla/incidencias_pantalla_test.js` | Botón INCIDENCIA: lector → ventana «¿Qué pasó?» con ID, nombre y foto; sin señal queda en cola y sale al volver; sin credencial; franja de aviso a Administración; historial de la persona; en el navegador sin la app. |
| `pantalla/api_simultaneos_test.mjs` | Un solo acceso, varios guardias a la vez: 50 envíos simultáneos desde 5 teléfonos y 4 cuentas (sin bloqueos ni duplicados, cada uno con su cuenta); el turno de cada registro e incidencia es el del guardia a cualquier hora; cada guardia ve su turno; el turno en Cuentas. |
| `pantalla/sesion_pantalla_test.js` | Pantallas: crear la primera cuenta, inicio por perfil, cuentas, cuenta regresiva, planta sin «Cuentas», el guardia directo a registrar, «Salir», claves `ca_` y que la sesión del Sistema de Gestión (`avba_session`) no se toca. |
| `pantalla/simultaneos_pantalla_test.js` | Dos teléfonos con guardias distintos leen credenciales al mismo tiempo (y la misma cuenta en un tercero): sin bloqueos, cada uno ve lo suyo; Administración ve todo con quién lo capturó y «En planta» por ID. |
| `pantalla/pendientes_test.js` | Lo pendiente sin señal va con su cuenta (no se manda a nombre de otra que entra en el mismo teléfono); dos teléfonos sin señal a la vez mandan cada uno lo suyo al volver. |
| `pantalla/planta_pantalla_test.js` | Vista «En planta»: buscar por ID o nombre, movimientos, dar salida, por punto, Excel. |
| `pantalla/evidencia_test.js` | Foto de la credencial como evidencia: con foto, ID a mano, sin señal, verla en la administración y en el PDF. |
| `pantalla/api_tablero_test.mjs` | Tablero por la API con datos conocidos: quién lo ve (cliente, planta, Administración; el guardia no) y todo lo que se le niega al cliente; entradas, salidas, personas (412 = 00412), visitas que cruzan la medianoche, salida sin registrar, sólo foto, quién está dentro, por día, por hora, tiempo dentro, más horas, personas dentro hora por hora; periodos; el Excel (hojas, ID como texto, fechas) y el PDF (gráficas válidas, las demás se ignoran); descargas por perfil; cuenta de cliente nueva. |
| `pantalla/tablero_pantalla_test.js` | Pantalla del tablero con un mes de datos inventados (`php/sembrar_tablero.php`): el cliente entra directo, cifras, las seis gráficas, periodos (hoy, 30 días, otras fechas, sin registros, más de 3 meses), recuerda el periodo, «Ver datos», buscar quién está dentro, descargar Excel y PDF (con las 6 gráficas), menú del cliente, Administración y el teléfono. |
| `pantalla/api_personal_test.mjs` | Base del personal por la API: quién puede qué (Administración y planta la manejan; el guardia sólo la copia y agregar permisos con foto; el cliente nada), agregar / cambiar / quitar (cambiar el ID se lleva los permisos), permisos (desde Administración con datos o foto; desde la caseta con foto, sin duplicar en la cola, deshacer el propio), copia con versión, nombre de la base al registrar e incidencias, IDs que faltan, cargar Excel (títulos en otro renglón, nombre y apellidos separados, NSS no es el ID, repetidos, renglones con problema, CSV de Windows con columnas a mano, .xls), fotos y Excel por perfil. |
| `pantalla/personal_pantalla_test.js` | Pantallas: Administración carga un Excel (revisar y cargar), busca, agrega un permiso con foto y datos, cambia y quita personas; el guardia (app simulada) lee y ve el nombre de la base y los permisos, el aviso de ID fuera de la base, agrega un resguardo con la foto del pase **sin señal** (sale al volver) y en la siguiente lectura aparece; Administración ve el resguardo y los IDs que faltan; menú por perfil. |
| `pantalla/api_fijo_test.mjs` | Equipos fijos por la API: cuentas de sólo entradas / sólo salidas (sin turno, aunque se mande uno), lo que devuelven LOGIN, SESION y ACCESOS_MI_PUNTO, que no registran el otro tipo, el turno por el reloj (05:20 día, 17:10 noche, salida con su entrada abierta), cambiar el modo y una base vieja sin la columna. |
| `pantalla/fijo_pantalla_test.js` | Equipo fijo con la app simulada (lectura continua): empieza sola, cada lectura se registra con el nombre de la base y la app muestra permisos; repetida en 2 min no se duplica; permiso vencido e ID fuera de la base en amarillo; varias seguidas; sin señal; reanudar; carril de salida; app vieja; el guardia con botones no cambia; crear un equipo fijo en Cuentas. |
| `pantalla/accesos_tarjeta_test.js` | Pantalla con API simulada: lectura de la credencial, confirmación, PDF, navegador sin app; la cámara frontal (el lector la recibe de la regla del servidor y en el navegador «Cambiar cámara» pasa a la trasera y se recuerda). |

Lo que no se puede probar aquí: la cámara real y el lector nativo (sólo en el teléfono;
`AnalizadorTarjetaTest.java` corre en cada compilación de la app en GitHub).
