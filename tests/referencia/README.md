# Pruebas de referencia del módulo de accesos

Se escribieron mientras el módulo vivía en el Sistema de Gestión: corren contra **su**
API (`api/index.php` del sistema principal) en una copia local con una base de prueba.
Aquí quedan como **especificación de lo que la plataforma tiene que seguir haciendo**;
en la fase 1 de `docs/PLAN.md` se adaptan a la API propia y se dejan corriendo.

Todos los datos son inventados (nombres, IDs de credencial, NSS).

## Qué cubre cada una

| Archivo | Qué comprueba |
|---|---|
| `php/prueba.php` | Migración de la tabla, registro con tarjeta sin foto, reenvío sin duplicar, lista, emparejar entrada/salida por ID, PDF de datos y PDF con fotos. |
| `php/limitada.php` | Base sin permiso de ALTER: el módulo sigue funcionando con fotos. |
| `php/en_planta.php` | ID como llave (00316 = 316), «En planta» (quien ya salió por cualquier punto no aparece; más de 13 h se marca), movimientos de un ID, dar salida (sólo ADMIN), reglas del lector desde la API. |
| `php/nombre_pdf.php` | El nombre del PDF: una lectura parcial cuenta a favor del nombre completo. |
| `pantalla/api_puntos_test.mjs` | API de puntos de control: cuenta sin punto, un solo equipo por punto, liberar, mover la cuenta de punto. |
| `pantalla/puntos_pantalla_test.js` | Pantalla con API real: la caseta entra a su punto y ve su avance; otro equipo queda bloqueado; Administración libera y asigna. |
| `pantalla/pendientes_test.js` | Lo pendiente sin señal va con su cuenta; si el punto queda en otro equipo no se pierde; el bloqueo se quita solo. |
| `pantalla/planta_pantalla_test.js` | Vista «En planta» de Administración: buscar por ID o nombre, movimientos, dar salida, por punto, Excel. |
| `pantalla/evidencia_test.js` | Foto de la credencial como evidencia: con foto, ID a mano, app anterior sin foto, sin señal, verla en Administración y en el PDF. |
| `pantalla/accesos_tarjeta_test.js` | Pantalla con API simulada: lectura de la credencial, confirmación, PDF, navegador sin app. |

## Cómo se corrían (y cómo correrlas al adaptarlas)

1. MariaDB local en `127.0.0.1` y una base de prueba con `sql/esquema-inicial.sql` y
   `tests/referencia/sembrar-cuentas.sql` (cuentas `admin`, `planta`, `punto1…4` con
   tokens fijos `tok-admin`, `tok-punto1`…).
2. Una copia del sitio con un `config/config.php` de prueba servida sólo en localhost:
   `php -S localhost:8794 -t <copia>` (las pruebas de pantalla usan
   `http://localhost:8794/`; `accesos_tarjeta_test.js` usa `http://localhost:8793/`
   sirviendo el repositorio).
3. `php tests/referencia/php/prueba.php` y `node tests/referencia/pantalla/<prueba>.js`
   (Playwright y Chromium ya vienen instalados en el entorno de Claude Code).

Rutas que hay que ajustar al adaptarlas: los `require` de los PHP apuntan a
`api/helpers.php` (fase 1) y algunas pruebas de pantalla leen las fotos y PDF de la
copia local (`./api_prueba/uploads/accesos/`).
