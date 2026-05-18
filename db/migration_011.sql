-- migration_011: Plantilla HTML de dictamen por tipo de equipo + prueba de carga en inspecciones

-- Plantilla dictamen HTML seleccionada para cada tipo de equipo (un archivo de los 5 disponibles)
ALTER TABLE maquinaria_tipos
  ADD COLUMN IF NOT EXISTS plantilla_dict_html VARCHAR(100) NULL
      COMMENT 'Nombre del archivo HTML del dictamen (ej: dictamen_montacargas.html)'
      AFTER plantilla_dict_pdf;

-- Datos de prueba de carga registrados por el inspector
ALTER TABLE equipos
  ADD COLUMN IF NOT EXISTS prueba_carga JSON NULL
      COMMENT 'Datos de prueba de carga según plantilla del tipo de equipo'
      AFTER inspector;
