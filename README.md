# AVBA · Control de Accesos

Plataforma independiente de AVBA para el control de accesos de planta, en
**https://gestion.avba.com.mx/controlaccesos/**. Separada del Sistema de Gestión como
Extintores: rama propia (`claude/control-accesos`), carpeta propia en el hosting, base
de datos propia y cuentas propias.

- **Instrucciones del proyecto:** [`CLAUDE.md`](CLAUDE.md)
- **Plan por fases (de la maqueta a lo real):** [`docs/PLAN.md`](docs/PLAN.md)
- **Maqueta:** `demo/` · **App Android:** `mobile/caseta/` · **Base:** `sql/esquema-inicial.sql`

## Publicar

- Sitio: `.github/workflows/deploy-control-accesos.yml` publica en cada push a esta rama
  **cuando se encienda** (fase 1; hoy está apagado). `config/config.php` vive sólo en el
  servidor (plantilla: `config/config.example.php`).
- App: `.github/workflows/app-caseta.yml`, igual (apagado hasta la fase 1). Se descargará
  de `gestion.avba.com.mx/controlaccesos/descargas/avba-control-accesos.apk`.
