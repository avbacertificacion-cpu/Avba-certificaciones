-- ════════════════════════════════════════════════════════════════════
--  Cuentas y sesiones de PRUEBA para la base LOCAL (nunca en el servidor).
--  Contraseña de todas: «prueba-local». Los tokens fijos (tok-admin,
--  tok-punto1…) son los que usan las pruebas de tests/referencia/pantalla.
--  Se carga después de sql/esquema-inicial.sql.
-- ════════════════════════════════════════════════════════════════════
INSERT IGNORE INTO usuarios (usuario, password_hash, rol, nombre) VALUES
  ('admin',  '$2y$10$2ubHb4pnvaQ5SBPhqcp3QueSIXFPTcuECuANuPGZAFXyEwZY8UfE6', 'ADMIN',   'ADMINISTRACION'),
  ('planta', '$2y$10$2ubHb4pnvaQ5SBPhqcp3QueSIXFPTcuECuANuPGZAFXyEwZY8UfE6', 'PLANTA',  'ADMINISTRADOR DE PLANTA'),
  ('punto1', '$2y$10$2ubHb4pnvaQ5SBPhqcp3QueSIXFPTcuECuANuPGZAFXyEwZY8UfE6', 'ACCESOS', 'CASETA PUNTO 1'),
  ('punto2', '$2y$10$2ubHb4pnvaQ5SBPhqcp3QueSIXFPTcuECuANuPGZAFXyEwZY8UfE6', 'ACCESOS', 'CASETA PUNTO 2'),
  ('punto3', '$2y$10$2ubHb4pnvaQ5SBPhqcp3QueSIXFPTcuECuANuPGZAFXyEwZY8UfE6', 'ACCESOS', 'CASETA PUNTO 3'),
  ('punto4', '$2y$10$2ubHb4pnvaQ5SBPhqcp3QueSIXFPTcuECuANuPGZAFXyEwZY8UfE6', 'ACCESOS', 'CASETA PUNTO 4');

-- Los guardias punto1…4 son del turno de día; noche1 y noche2, del de noche.
INSERT IGNORE INTO usuarios (usuario, password_hash, rol, nombre) VALUES
  ('noche1', '$2y$10$2ubHb4pnvaQ5SBPhqcp3QueSIXFPTcuECuANuPGZAFXyEwZY8UfE6', 'ACCESOS', 'CASETA PUNTO 1 NOCHE'),
  ('noche2', '$2y$10$2ubHb4pnvaQ5SBPhqcp3QueSIXFPTcuECuANuPGZAFXyEwZY8UfE6', 'ACCESOS', 'CASETA PUNTO 2 NOCHE');
-- El cliente: sólo el tablero (gráficas, Excel y PDF).
INSERT IGNORE INTO usuarios (usuario, password_hash, rol, nombre) VALUES
  ('cliente', '$2y$10$2ubHb4pnvaQ5SBPhqcp3QueSIXFPTcuECuANuPGZAFXyEwZY8UfE6', 'CLIENTE', 'CLIENTE DE PRUEBA');
-- Equipos fijos (lectura continua): uno de entrada y uno de salida, sin turno (van por el reloj).
INSERT IGNORE INTO usuarios (usuario, password_hash, rol, nombre) VALUES
  ('fijo.entrada', '$2y$10$2ubHb4pnvaQ5SBPhqcp3QueSIXFPTcuECuANuPGZAFXyEwZY8UfE6', 'ACCESOS', 'EQUIPO FIJO ENTRADA'),
  ('fijo.salida',  '$2y$10$2ubHb4pnvaQ5SBPhqcp3QueSIXFPTcuECuANuPGZAFXyEwZY8UfE6', 'ACCESOS', 'EQUIPO FIJO SALIDA');
-- Servicio médico: dos cuentas (cada toma guarda quién la tomó).
INSERT IGNORE INTO usuarios (usuario, password_hash, rol, nombre) VALUES
  ('medico',  '$2y$10$2ubHb4pnvaQ5SBPhqcp3QueSIXFPTcuECuANuPGZAFXyEwZY8UfE6', 'MEDICO', 'ENF. LAURA MEDINA'),
  ('medico2', '$2y$10$2ubHb4pnvaQ5SBPhqcp3QueSIXFPTcuECuANuPGZAFXyEwZY8UfE6', 'MEDICO', 'DR. HUGO SALAS');
UPDATE usuarios SET modo = 'E' WHERE usuario = 'fijo.entrada';
UPDATE usuarios SET modo = 'S' WHERE usuario = 'fijo.salida';
UPDATE usuarios SET turno = 'D' WHERE usuario IN ('punto1', 'punto2', 'punto3', 'punto4');
UPDATE usuarios SET turno = 'N' WHERE usuario IN ('noche1', 'noche2');

INSERT IGNORE INTO sesiones_usuario (token, usuario_id, origen, creado, expira)
  SELECT CONCAT('tok-', usuario), id, 'prueba', NOW(), NOW() + INTERVAL 30 DAY FROM usuarios;
