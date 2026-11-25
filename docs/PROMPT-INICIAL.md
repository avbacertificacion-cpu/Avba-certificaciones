# Mensaje inicial para el proyecto nuevo de Claude Code

Crear el proyecto (sesión) sobre el repositorio `avbacertificacion-cpu/Avba-certificaciones`,
rama **`claude/control-accesos`**, y pegar esto como primer mensaje:

---

Vamos a construir la plataforma **AVBA Control de Accesos**, independiente del Sistema de
Gestión, igual que Extintores: rama propia, carpeta propia en el hosting
(gestion.avba.com.mx/controlaccesos/), base de datos propia y cuentas propias.

Trabaja **sólo** en la rama `claude/control-accesos` del repositorio
avbacertificacion-cpu/Avba-certificaciones; no hagas push a ninguna otra rama. Antes de
empezar lee completos `CLAUDE.md` y `docs/PLAN.md`: ahí están el contexto, las reglas
(todo en español; el repositorio es público, así que nada de datos reales de personas
ni contraseñas) y el plan por fases.

La meta es volver real la maqueta de `demo/`, partiendo del módulo que ya funciona:
`accesos.html`, `api/ControlAccesos.php` y la app de `mobile/caseta`, que lee la
credencial de acceso con el teléfono (el ID del recuadro azul es la llave de cada
persona) y guarda la foto de la credencial como evidencia.

Empieza por la **fase 1** (que la plataforma funcione sola):
1. Revisa el inicio de sesión y el enrutador del sistema principal
   (`git fetch origin claude/continue-avba-project-bU5LX` y
   `git show origin/claude/continue-avba-project-bU5LX:api/Auth.php`, `api/helpers.php`,
   `login.html`, `descargar.php`) y toma sólo lo necesario.
2. Arma `api/index.php`, `api/helpers.php`, el inicio de sesión propio, `descargar.php`
   y la pantalla de cuentas, con los tres perfiles de la maqueta: ADMIN (Administración
   AVBA), PLANTA (administrador de accesos de la planta) y ACCESOS (guardia de caseta).
3. Cambia las claves de almacenamiento de `accesos.html` al prefijo `ca_` (comparte
   dominio con el Sistema de Gestión).
4. Pruébalo todo en local (MariaDB y `php -S localhost`), adaptando `tests/referencia/`.
5. Dime qué tengo que hacer yo en hPanel (crear la base y el `config.php` del servidor)
   y, cuando funcione, activa los despliegues automáticos, publica y probamos con la app
   «AVBA Control de Accesos» en un teléfono.

Al terminar cada paso, cuéntame en español qué quedó, qué probaste y qué falta.
