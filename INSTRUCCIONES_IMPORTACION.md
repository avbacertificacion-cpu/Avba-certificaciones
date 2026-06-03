# 📥 Importación Masiva de Extintores

## 📋 Descripción General

El sistema permite importar múltiples extintores de una sola vez mediante un archivo CSV. El proceso incluye:

1. **Descarga de plantilla** - Archivo CSV modelo
2. **Carga del archivo** - Arrastra y suelta o selecciona manualmente
3. **Validación y previsualización** - Ve los datos antes de guardar
4. **Confirmación** - Aprueba los cambios
5. **Importación** - Los datos se guardan en la BD

---

## 🚀 Paso a Paso

### 1. Acceder al módulo de importación

- Inicia sesión como **Administrador**
- Ve a **Panel Admin** → **Importar Extintores**
- O dirección directa: `/admin-importar-extintores.php`

### 2. Descargar la plantilla

Haz clic en el botón **📥 Descargar plantilla**

Se descargará un archivo `plantilla-extintores.csv` con estas columnas:

| Columna | Requerido | Ejemplo | Notas |
|---------|-----------|---------|-------|
| `codigo_manual` | ✅ SÍ | EXT-001 | Único por empresa |
| `ubicacion` | ✅ SÍ | Almacén General | Ubicación específica |
| `tipo` | ✅ SÍ | PQS | PQS, CO2, agua, espuma, halotron, otro |
| `capacidad` | ❌ No | 6 kg | Formato libre (ej: "6 kg", "2.5 kg") |
| `seccion` | ❌ No | EDIFICIO A | Agrupa en reportes |
| `empresa_id` | ✅ SÍ | 1 | ID de la empresa en el sistema |
| `fecha_recarga` | ❌ No | 2026-05-20 | Formato: YYYY-MM-DD |
| `fecha_ph` | ❌ No | 2025-10-15 | Formato: YYYY-MM-DD |
| `observaciones` | ❌ No | Extintor principal | Notas adicionales |

### 3. Completar la plantilla en Excel

1. **Abre el archivo CSV en Excel**
   - Click derecho → Abrir con → Excel
   - O arrastra a Excel

2. **Llena los datos requeridos:**
   - **codigo_manual**: Códigos únicos (EXT-001, EXT-002, etc.)
   - **ubicacion**: Donde está ubicado el extintor
   - **tipo**: Selecciona uno válido
   - **empresa_id**: El ID numérico de la empresa

3. **Agrega datos opcionales** según sea necesario

4. **Guarda como CSV:**
   - Archivo → Guardar como
   - Formato: CSV (Delimitado por comas) (*.csv)
   - Codificación: UTF-8

### 4. Subir el archivo

En el módulo de importación:

**Opción A - Arrastra y suelta:**
- Arrastra el archivo `.csv` directamente a la zona de carga

**Opción B - Click para seleccionar:**
- Haz clic en la zona
- Selecciona el archivo

### 5. Validación y previsualización

El sistema automáticamente:

✅ **Valida los datos:**
- Código no vacío
- Ubicación no vacía
- Tipo válido
- Empresa ID válido

📊 **Muestra estadísticas:**
- Total de filas
- Filas válidas (listas para importar)
- Advertencias
- Errores

🎨 **Color-códifica el resultado:**
- ✅ Verde = OK (se importará)
- ❌ Rojo = Error (se ignorará)
- ⚠️ Amarillo = Advertencia (se importará pero verifica)

### 6. Confirmar importación

Revisa la previsualización:

- Si todo se ve bien, haz clic en **✅ Confirmar e importar**
- Se abrirá un modal pidiendo confirmación final
- Haz clic en **Sí, importar** para guardar

### 7. Resultado final

✅ Si la importación es exitosa:
- Verás un mensaje: "Se importaron X extintores correctamente"
- Los datos se guardan en la BD automáticamente
- Se genera un registro de auditoría

❌ Si hay errores:
- Se mostrará el error
- Puedes volver a intentar con otro archivo

---

## 💡 Ejemplos y casos comunes

### Ejemplo 1: Importación simple

**Archivo CSV:**
```
codigo_manual,ubicacion,tipo,capacidad,seccion,empresa_id,fecha_recarga,fecha_ph,observaciones
EXT-001,Almacén,PQS,6 kg,PLANTA A,1,2026-05-20,2025-10-15,Principal
EXT-002,Oficina,CO2,2.5 kg,PLANTA A,1,2026-03-10,2025-09-20,Con manguera
EXT-003,Pasillo,agua,9 kg,PLANTA B,1,2026-04-05,2025-11-30,
```

✅ Se importarán 3 extintores

### Ejemplo 2: Múltiples empresas

Si tienes varias empresas, usa el `empresa_id` correcto:

```
codigo_manual,ubicacion,tipo,empresa_id
EXT-001,Almacén,PQS,1
EXT-100,Oficina,CO2,2
EXT-200,Bodega,agua,3
```

### Ejemplo 3: Datos mínimos

Solo lo obligatorio:

```
codigo_manual,ubicacion,tipo,empresa_id
EXT-001,Almacén General,PQS,1
EXT-002,Oficina Gerencia,CO2,1
EXT-003,Pasillo Principal,agua,1
```

---

## ⚠️ Errores comunes y soluciones

| Problema | Causa | Solución |
|----------|-------|----------|
| "Falta columna requerida" | El CSV no tiene todas las columnas | Usa la plantilla descargada |
| "Tipo no válido" | Escribiste "polvo" en lugar de "PQS" | Usa: PQS, CO2, agua, espuma, halotron, otro |
| "Empresa ID inválido" | El ID no existe o está vacío | Verifica en Admin → Empresas |
| "Código vacío" | Fila con código en blanco | Completa todos los códigos |
| "Solo se aceptan CSV" | Intentaste subir Excel directo | Guarda como CSV primero |

---

## 🔒 Seguridad

- ✅ Solo administradores pueden importar
- ✅ Validación de datos antes de guardar
- ✅ Transacciones: todo o nada (no guardos parciales)
- ✅ Auditoría de importaciones
- ✅ IDs de QR únicos generados automáticamente

---

## 📝 Notas importantes

1. **Código único por empresa:** No puedes tener dos EXT-001 en la misma empresa, pero sí en empresas diferentes.

2. **Fechas:** Formato obligatorio `YYYY-MM-DD` (ej: 2026-05-20)

3. **Sin edición directa del CSV:** El sistema valida todo, así que no intentes "trucar" los datos

4. **Rollback:** Si algo falla, NO se guardará nada. Puedes reintentar sin problemas.

5. **Después de importar:** Los extintores aparecerán inmediatamente en el sistema, listos para inspeccionar

---

## 🆘 ¿Necesitas ayuda?

- Verifica que el CSV tenga exactamente las columnas requeridas
- Asegúrate de que los tipos sean válidos
- Confirma que los IDs de empresa existan
- Usa UTF-8 al guardar el CSV
