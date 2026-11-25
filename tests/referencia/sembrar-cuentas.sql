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

INSERT IGNORE INTO sesiones_usuario (token, usuario_id, origen, creado, expira)
  SELECT CONCAT('tok-', usuario), id, 'prueba', NOW(), NOW() + INTERVAL 30 DAY FROM usuarios;
