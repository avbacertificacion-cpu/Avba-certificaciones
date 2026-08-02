# Socios Comerciales AVBA

Portal de bolsa de trabajo (candidatos ↔ empresas) con marca AVBA.
Sistema **independiente** del de certificaciones que vive en la raíz de este
repositorio: base de datos propia, sesiones propias y despliegue propio.

- **URL:** https://gestion.avba.com.mx/socioscomerciales
- **Rama de trabajo:** `claude/socios-comerciales-portal-wco7s3`
- **Despliegue:** `.github/workflows/deploy-socioscomerciales.yml` (SCP, solo esta carpeta)

## Estructura

```
socioscomerciales/
├── index.html            Landing pública
├── login.html            Acceso (persona / empresa)
├── registro.html         Alta de cuenta (persona / empresa)
├── perfil-persona.html   Perfil de candidato
├── perfil-empresa.html   Perfil de empresa
├── api/
│   ├── index.php         Router (switch de acciones, respuestas JSON)
│   ├── Auth.php          Registro, login, logout (token propio)
│   ├── Personas.php      Perfil, CV, foto, experiencia, educación, habilidades
│   ├── Empresas.php      Perfil y logo
│   └── helpers.php       Token, respuestas JSON, subida de archivos
├── config/
│   ├── config.php        Credenciales reales (NO versionado)
│   ├── config.sample.php Plantilla
│   └── database.php      scDB() + autoinstalación de tablas sc_*
└── uploads/              cv/ · fotos/ · logos/ (protegido por .htaccess)
```

## Configuración en el servidor

Copiar `config/config.sample.php` a `config/config.php` y llenar:

| Constante     | Descripción                          |
|---------------|--------------------------------------|
| `SC_DB_HOST`  | Host MySQL de la BD de socios        |
| `SC_DB_NAME`  | Nombre de la BD                      |
| `SC_DB_USER`  | Usuario                              |
| `SC_DB_PASS`  | Contraseña                           |

Las tablas `sc_*` se crean solas en el primer request; no hay que ejecutar SQL a mano.

`config.php` está en `.gitignore`, así que el despliegue nunca lo sobrescribe.

## Base de datos

Tablas con prefijo `sc_`: `sc_usuarios`, `sc_personas`, `sc_experiencia`,
`sc_educacion`, `sc_habilidades`, `sc_empresas`, `sc_vacantes`, `sc_postulaciones`.

La conexión a la BD de gestión (`gestDB()`) está **escrita pero desactivada** en
`config/database.php`. Se activará en la Fase 2 para leer certificaciones
verificadas del sistema principal.

## Aislamiento respecto al sistema de certificaciones

- Base de datos distinta (prefijo `sc_`, sin tocar tablas del sistema raíz).
- Cookie de sesión limitada a `/socioscomerciales` (`.htaccess`).
- El workflow de despliegue filtra por `paths: socioscomerciales/**`, así que
  nunca sube ni borra archivos del sistema principal.

## Fase 2 (pendiente)

Vacantes, postulaciones, búsqueda de candidatos y lectura de certificaciones
verificadas desde la BD de gestión.
