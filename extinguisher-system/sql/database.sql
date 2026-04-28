-- Sistema de Gestión de Extintores
CREATE DATABASE IF NOT EXISTS extinguisher_management CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE extinguisher_management;

-- ─── USUARIOS ────────────────────────────────────────────────────────────────
CREATE TABLE usuarios (
    id          INT PRIMARY KEY AUTO_INCREMENT,
    nombre      VARCHAR(100) NOT NULL,
    email       VARCHAR(100) UNIQUE NOT NULL,
    password    VARCHAR(255) NOT NULL,
    rol         ENUM('administrador','inspector','cliente') NOT NULL,
    empresa_id  INT,
    estado      ENUM('activo','inactivo') DEFAULT 'activo',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ─── EMPRESAS ────────────────────────────────────────────────────────────────
CREATE TABLE empresas (
    id          INT PRIMARY KEY AUTO_INCREMENT,
    nombre      VARCHAR(150) NOT NULL,
    rfc         VARCHAR(13),
    domicilio   TEXT,
    telefono    VARCHAR(20),
    email       VARCHAR(100),
    contacto    VARCHAR(100),
    estado      ENUM('activo','inactivo') DEFAULT 'activo',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ─── EXTINTORES ──────────────────────────────────────────────────────────────
CREATE TABLE extintores (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    codigo_qr       VARCHAR(64) UNIQUE NOT NULL,   -- hash único para QR
    codigo_manual   VARCHAR(30) UNIQUE NOT NULL,   -- ej: EXT-001
    empresa_id      INT NOT NULL,
    ubicacion       VARCHAR(255) NOT NULL,
    tipo            ENUM('PQS','CO2','agua','espuma','halotron','otro') NOT NULL,
    capacidad       VARCHAR(30),                   -- ej: 6 kg, 2.5 kg
    seccion         VARCHAR(150),                  -- ej: EDIFICIO DE VIGILANCIA
    fecha_recarga   DATE,
    fecha_ph        DATE,                          -- prueba hidrostática
    estado          ENUM('activo','inactivo','en_prestamo') DEFAULT 'activo',
    observaciones   TEXT,
    creado_por      INT NOT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (empresa_id) REFERENCES empresas(id),
    FOREIGN KEY (creado_por) REFERENCES usuarios(id)
);

-- ─── INSPECCIONES (checklist por extintor) ───────────────────────────────────
CREATE TABLE inspecciones (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    extintor_id     INT NOT NULL,
    inspector_id    INT NOT NULL,
    fecha           DATE NOT NULL,
    hora            TIME NOT NULL,

    -- Checklist (OK = cumple NOM-002, NC = no cumple, NA = no aplica, PO = en préstamo)
    ser  ENUM('OK','NC','NA','PO') DEFAULT NULL,   -- Señalamiento y soporte
    mg   ENUM('OK','NC','NA','PO') DEFAULT NULL,   -- Manguera
    po   ENUM('OK','NC','NA','PO') DEFAULT NULL,   -- Extintor en préstamo
    ph   ENUM('OK','NC','NA','PO') DEFAULT NULL,   -- Prueba hidrostática
    sg   ENUM('OK','NC','NA','PO') DEFAULT NULL,   -- Seguro (pasador y cincho)
    ps   ENUM('OK','NC','NA','PO') DEFAULT NULL,   -- Presión
    ob   ENUM('OK','NC','NA','PO') DEFAULT NULL,   -- Obstruido
    dan  ENUM('OK','NC','NA','PO') DEFAULT NULL,   -- Daño
    pin  ENUM('OK','NC','NA','PO') DEFAULT NULL,   -- Pintura y etiqueta

    tipo_inspeccion  VARCHAR(50),                  -- tipo, capacidad, PH, recarga (de plantilla)
    capacidad_insp   VARCHAR(30),
    ph_vigente       TINYINT(1) DEFAULT 0,
    recarga_vigente  TINYINT(1) DEFAULT 0,

    observaciones   TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (extintor_id) REFERENCES extintores(id),
    FOREIGN KEY (inspector_id) REFERENCES usuarios(id)
);

-- ─── PLANTILLAS (para generar reportes del cliente) ──────────────────────────
CREATE TABLE plantillas (
    id          INT PRIMARY KEY AUTO_INCREMENT,
    nombre      VARCHAR(150) NOT NULL,
    descripcion TEXT,
    empresa_id  INT NOT NULL,
    creado_por  INT NOT NULL,
    estado      ENUM('activo','inactivo') DEFAULT 'activo',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (empresa_id) REFERENCES empresas(id),
    FOREIGN KEY (creado_por) REFERENCES usuarios(id)
);

-- ─── REPORTES MENSUALES (generados por admin para cliente) ───────────────────
CREATE TABLE reportes_mensuales (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    plantilla_id    INT NOT NULL,
    empresa_id      INT NOT NULL,
    generado_por    INT NOT NULL,
    mes             TINYINT NOT NULL,              -- 1-12
    anio            SMALLINT NOT NULL,
    numero_reporte  VARCHAR(20),                   -- REV-001
    ruta_pdf        VARCHAR(255),
    -- 'publicado' = visible para el cliente; 'borrador'/'generado' solo admin lo ve
    estado          ENUM('borrador','generado','publicado') DEFAULT 'borrador',
    observaciones   TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (plantilla_id) REFERENCES plantillas(id),
    FOREIGN KEY (empresa_id) REFERENCES empresas(id),
    FOREIGN KEY (generado_por) REFERENCES usuarios(id)
);

-- ─── AUDITORÍA ───────────────────────────────────────────────────────────────
CREATE TABLE auditoria (
    id          INT PRIMARY KEY AUTO_INCREMENT,
    usuario_id  INT,
    accion      VARCHAR(255),
    tabla       VARCHAR(50),
    registro_id INT,
    detalle     TEXT,
    ip          VARCHAR(45),
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
);

-- ─── ÍNDICES ─────────────────────────────────────────────────────────────────
CREATE INDEX idx_extintor_empresa    ON extintores(empresa_id);
CREATE INDEX idx_extintor_codigo_qr  ON extintores(codigo_qr);
CREATE INDEX idx_extintor_codigo_man ON extintores(codigo_manual);
CREATE INDEX idx_inspeccion_extintor ON inspecciones(extintor_id);
CREATE INDEX idx_inspeccion_fecha    ON inspecciones(fecha);
CREATE INDEX idx_reporte_empresa     ON reportes_mensuales(empresa_id);
CREATE INDEX idx_reporte_mes         ON reportes_mensuales(mes, anio);
CREATE INDEX idx_usuario_rol         ON usuarios(rol);
CREATE INDEX idx_usuario_empresa     ON usuarios(empresa_id);

-- ─── DATOS INICIALES ─────────────────────────────────────────────────────────
-- Admin por defecto (password: Admin1234!)
INSERT INTO empresas (nombre, rfc, email, contacto) VALUES
    ('Empresa Demo', 'EMP123456789', 'demo@empresa.com', 'Contacto Demo');

INSERT INTO usuarios (nombre, email, password, rol, empresa_id) VALUES
    ('Administrador', 'admin@sistema.com',
     '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
     'administrador', NULL),
    ('Inspector Demo', 'inspector@sistema.com',
     '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
     'inspector', NULL),
    ('Cliente Demo', 'cliente@sistema.com',
     '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
     'cliente', 1);
