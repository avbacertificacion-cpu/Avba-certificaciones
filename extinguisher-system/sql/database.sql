-- Crear base de datos
CREATE DATABASE IF NOT EXISTS extinguisher_management;
USE extinguisher_management;

-- Tabla de usuarios
CREATE TABLE usuarios (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nombre VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    rol ENUM('administrador', 'inspector', 'cliente') NOT NULL,
    empresa_id INT,
    estado ENUM('activo', 'inactivo') DEFAULT 'activo',
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fecha_modificacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Tabla de empresas/clientes
CREATE TABLE empresas (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nombre VARCHAR(150) NOT NULL,
    rfc VARCHAR(13),
    domicilio TEXT,
    telefono VARCHAR(20),
    email VARCHAR(100),
    contacto VARCHAR(100),
    estado ENUM('activo', 'inactivo') DEFAULT 'activo',
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabla de plantillas de inspección
CREATE TABLE plantillas_inspeccion (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nombre VARCHAR(150) NOT NULL,
    descripcion TEXT,
    numero_reporte VARCHAR(20),
    empresa_id INT NOT NULL,
    creado_por INT NOT NULL,
    estado ENUM('activo', 'inactivo') DEFAULT 'activo',
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (empresa_id) REFERENCES empresas(id),
    FOREIGN KEY (creado_por) REFERENCES usuarios(id)
);

-- Tabla de áreas en las plantillas
CREATE TABLE areas_plantilla (
    id INT PRIMARY KEY AUTO_INCREMENT,
    plantilla_id INT NOT NULL,
    nombre VARCHAR(100) NOT NULL,
    descripcion TEXT,
    orden INT,
    FOREIGN KEY (plantilla_id) REFERENCES plantillas_inspeccion(id) ON DELETE CASCADE
);

-- Tabla de campos dinámicos en áreas
CREATE TABLE campos_area (
    id INT PRIMARY KEY AUTO_INCREMENT,
    area_id INT NOT NULL,
    nombre_campo VARCHAR(100) NOT NULL,
    tipo VARCHAR(50), -- SER, MG, PO, PH, SG, PS, OB, DAR, PIN, etc
    orden INT,
    FOREIGN KEY (area_id) REFERENCES areas_plantilla(id) ON DELETE CASCADE
);

-- Tabla de reportes de inspección
CREATE TABLE reportes_inspeccion (
    id INT PRIMARY KEY AUTO_INCREMENT,
    plantilla_id INT NOT NULL,
    numero_reporte VARCHAR(20),
    empresa_id INT NOT NULL,
    inspeccionado_por INT NOT NULL,
    revisado_por INT,
    estado ENUM('borrador', 'pendiente_aprobacion', 'aprobado', 'rechazado') DEFAULT 'borrador',
    fecha_inspeccion DATE,
    ruta_pdf VARCHAR(255),
    comentarios TEXT,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fecha_aprobacion TIMESTAMP,
    FOREIGN KEY (plantilla_id) REFERENCES plantillas_inspeccion(id),
    FOREIGN KEY (empresa_id) REFERENCES empresas(id),
    FOREIGN KEY (inspeccionado_por) REFERENCES usuarios(id),
    FOREIGN KEY (revisado_por) REFERENCES usuarios(id)
);

-- Tabla de datos de inspección por área
CREATE TABLE datos_inspeccion (
    id INT PRIMARY KEY AUTO_INCREMENT,
    reporte_id INT NOT NULL,
    area_id INT NOT NULL,
    campo_id INT NOT NULL,
    valor TEXT,
    observaciones TEXT,
    FOREIGN KEY (reporte_id) REFERENCES reportes_inspeccion(id) ON DELETE CASCADE,
    FOREIGN KEY (area_id) REFERENCES areas_plantilla(id),
    FOREIGN KEY (campo_id) REFERENCES campos_area(id)
);

-- Tabla de auditoría
CREATE TABLE auditoria (
    id INT PRIMARY KEY AUTO_INCREMENT,
    usuario_id INT,
    accion VARCHAR(255),
    tabla VARCHAR(50),
    registro_id INT,
    cambios TEXT,
    fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
);

-- Índices para optimización
CREATE INDEX idx_usuario_rol ON usuarios(rol);
CREATE INDEX idx_usuario_empresa ON usuarios(empresa_id);
CREATE INDEX idx_plantilla_empresa ON plantillas_inspeccion(empresa_id);
CREATE INDEX idx_reporte_estado ON reportes_inspeccion(estado);
CREATE INDEX idx_reporte_inspector ON reportes_inspeccion(inspeccionado_por);
CREATE INDEX idx_reporte_empresa ON reportes_inspeccion(empresa_id);
