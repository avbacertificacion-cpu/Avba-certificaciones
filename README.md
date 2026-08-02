# 🔥 Sistema de Gestión de Extintores

Sistema web para la gestión integral de inspecciones mensuales de extintores con roles diferenciados (Administrador, Inspector y Cliente).

**Rama de despliegue:** `claude/extintores-deploy` - Estructura optimizada para producción con QR tracking completo

<!-- deploy-check: 2026-08-02 -->
_Última verificación de despliegue: 2026-08-02_

## 📋 Características

- **Autenticación segura** con sistema de roles
- **Plantillas reutilizables** para inspecciones mensuales
- **Campos dinámicos** personalizables por área
- **Flujo de aprobación** para reportes
- **Panel de control** por rol
- **Auditoría** de todas las acciones
- **Generación de PDF** con etiquetas QR automáticas

## 🚀 Requisitos

- PHP 7.4 o superior
- MySQL 5.7 o superior
- Servidor web (Apache, Nginx)
- Acceso a directorios de escritura para uploads

## 📦 Instalación

### 1. Clonar el repositorio

```bash
git clone <tu-repo>
cd extinguisher-system
```

### 2. Configurar base de datos

1. Crear una base de datos MySQL nueva:
```sql
CREATE DATABASE extinguisher_management CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

2. Ejecutar el script SQL:
```bash
mysql -u root -p extinguisher_management < sql/database.sql
```

### 3. Configurar credenciales

Editar `config/config.php` con tus credenciales:
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'tu_usuario');
define('DB_PASS', 'tu_contraseña');
define('DB_NAME', 'extinguisher_management');
```

### 4. Crear directorios necesarios

```bash
mkdir -p uploads/pdf
mkdir -p uploads/temp
chmod 755 uploads uploads/pdf uploads/temp
```

### 5. Acceder a la aplicación

Abrir en el navegador:
```
http://localhost/extinguisher-system/public/login.html
```

## 👥 Roles y Permisos

### Administrador
- ✅ Crear plantillas de inspección
- ✅ Agregar áreas y campos
- ✅ Crear usuarios
- ✅ Revisar reportes completados
- ✅ Editar y aprobar reportes
- ✅ Generar PDFs
- ✅ Ver auditoría completa

### Inspector
- ✅ Llenar reportes usando plantillas
- ✅ Guardar borradores
- ✅ Enviar reportes para aprobación
- ✅ Ver mis reportes
- ✅ Descargar PDFs aprobados

### Cliente
- ✅ Ver sus reportes aprobados
- ✅ Descargar PDFs

## 📝 Flujo de Trabajo

### 1. Crear Plantilla (Admin)
1. Ir a "Crear Plantillas"
2. Llenar datos de la plantilla
3. Agregar áreas (ubicaciones a inspeccionar)
4. Para cada área, agregar campos (SER, MG, PO, PH, etc.)
5. Guardar

### 2. Inspector Llena Reporte
1. Ir a "Nuevo Reporte"
2. Seleccionar plantilla
3. Llenar información por área
4. Agregar observaciones si es necesario
5. Enviar para aprobación

### 3. Admin Revisa y Aprueba
1. Ir a "Revisar Reportes"
2. Ver información completada
3. Editar si es necesario
4. Aprobar o rechazar
5. El PDF se genera automáticamente

### 4. Cliente Descarga Reporte
1. Acceder con su cuenta
2. Ver reportes aprobados
3. Descargar PDF

## 🗂️ Estructura de Carpetas

```
extinguisher-system/
├── config/              # Archivos de configuración
│   └── config.php
├── api/                 # APIs REST
│   ├── auth.php
│   ├── plantillas.php
│   └── reportes.php
├── public/              # Archivos públicos (sin sesión)
│   └── login.html
├── private/             # Archivos privados (requieren sesión)
│   ├── admin-dashboard.php
│   ├── admin-plantillas.php
│   ├── admin-reportes.php
│   ├── inspector-dashboard.php
│   ├── inspector-nuevo-reporte.php
│   ├── inspector-llenar-reporte.php
│   └── cliente-dashboard.php
├── sql/                 # Scripts de base de datos
│   └── database.sql
├── uploads/             # Archivos cargados (PDFs, imágenes)
│   ├── pdf/
│   └── temp/
├── assets/              # CSS, JS, imágenes
│   ├── css/
│   ├── js/
│   └── img/
└── README.md           # Este archivo
```

## 🔒 Seguridad

- Contraseñas hasheadas con `password_hash()` (BCRYPT)
- Prepared statements para prevenir SQL injection
- Verificación de sesión y roles en cada página
- CSRF tokens (implementar)
- Auditoría completa de acciones

## 🌐 Despliegue en Hostinguer

### Requisitos para Hostinguer
- PHP 7.4+
- MySQL
- FTP/SSH access
- .htaccess habilitado

### Pasos

1. **Subir archivos vía FTP**
   - Conectar a servidor FTP
   - Subir carpeta completa

2. **Crear base de datos**
   - Panel de control de Hostinguer
   - Crear base de datos MySQL
   - Obtener credenciales

3. **Ejecutar script SQL**
   - Usar phpMyAdmin o cliente MySQL
   - Ejecutar `sql/database.sql`

4. **Configurar config.php**
   - Actualizar credenciales de BD
   - Asegurar URLs correctas

5. **Crear carpetas necesarias**
   ```
   /uploads
   /uploads/pdf
   /uploads/temp
   ```

6. **Permisos de carpetas**
   ```
   chmod 755 uploads
   chmod 755 uploads/pdf
   chmod 755 uploads/temp
   ```

## 🛠️ Problemas Comunes

### Error de conexión a BD
- Verificar credenciales en `config/config.php`
- Verificar que la BD existe
- Verificar acceso del usuario a la BD

### Archivos no se guardan en uploads
- Verificar permisos de carpeta: `chmod 755 uploads`
- Verificar que carpeta existe

### Páginas dan error 403
- Verificar archivo `.htaccess`
- Verificar permisos de archivos: `chmod 644 *.php`

## 📚 Tecnologías

- **Backend**: PHP 7.4+
- **Base de datos**: MySQL 5.7+
- **Frontend**: HTML5, CSS3, JavaScript vanilla
- **Seguridad**: Password hashing BCRYPT, Prepared statements
- **Generación PDF**: TCPDF (por implementar)

## 📝 Licencia

Todos los derechos reservados © 2026

## 👨‍💻 Soporte

Para reportar bugs o sugerencias, contactar al equipo de desarrollo.

## 🔄 Próximas mejoras

- [ ] Generación automática de PDFs
- [ ] Subida de fotos en inspecciones
- [ ] Notificaciones por email
- [ ] API REST completa
- [ ] Aplicación móvil
- [ ] Gráficos y reportes estadísticos
- [ ] Integración con sistemas de facturación
- [ ] Multi-idioma
