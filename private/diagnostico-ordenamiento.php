<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Diagnóstico de Ordenamiento - Reportes</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
        .container { max-width: 1000px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; }
        h1 { color: #333; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #667eea; color: white; }
        tr:hover { background: #f9f9f9; }
        .red { color: red; font-weight: bold; }
        .green { color: green; font-weight: bold; }
        .info { background: #d1ecf1; border: 1px solid #bee5eb; padding: 15px; border-radius: 5px; margin: 15px 0; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Diagnóstico de Ordenamiento de Extintores</h1>

        <div class="info">
            <strong>Instrucciones:</strong>
            <ol>
                <li>Selecciona una empresa</li>
                <li>El script mostrará cómo se ordenan los extintores actualmente</li>
                <li>Verifica que EXT-001, EXT-002, etc. estén en orden ascendente</li>
            </ol>
        </div>

        <form id="form-diagnostico">
            <label for="empresa_id">Selecciona Empresa:</label>
            <select id="empresa_id" required>
                <option value="">-- Cargando empresas --</option>
            </select>
            <button type="submit">Diagnosticar</button>
        </form>

        <div id="resultado"></div>
    </div>

    <script>
        // Cargar empresas
        fetch('../api/extintores.php?action=listar')
            .then(r => r.json())
            .then(d => {
                if (d.success && d.data) {
                    const empresas = {};
                    d.data.forEach(ext => {
                        if (!empresas[ext.empresa_id]) {
                            empresas[ext.empresa_id] = ext.empresa_nombre;
                        }
                    });

                    const select = document.getElementById('empresa_id');
                    select.innerHTML = '<option value="">-- Selecciona --</option>';
                    Object.entries(empresas).forEach(([id, nombre]) => {
                        const option = document.createElement('option');
                        option.value = id;
                        option.textContent = `${nombre} (ID: ${id})`;
                        select.appendChild(option);
                    });
                }
            });

        document.getElementById('form-diagnostico').addEventListener('submit', async (e) => {
            e.preventDefault();
            const empresa_id = document.getElementById('empresa_id').value;

            const resp = await fetch(`../api/extintores.php?action=listar&empresa_id=${empresa_id}`);
            const data = await resp.json();

            if (!data.success) {
                document.getElementById('resultado').innerHTML = '<p class="red">Error al cargar datos</p>';
                return;
            }

            let html = '<h2>Extintores de la Empresa</h2>';
            html += '<table>';
            html += '<tr><th>ID</th><th>Código</th><th>Sección</th><th>Estado</th><th>Tipo</th></tr>';

            const extintores = data.data.filter(e => e.estado === 'activo');

            // Verificar si están ordenados correctamente
            let ordenCorrecto = true;
            let ultimoNumero = 0;

            extintores.forEach(ext => {
                const codigo = ext.codigo_manual;
                // Extraer número del código
                const match = codigo.match(/\d+/);
                const numero = match ? parseInt(match[0]) : 0;

                if (numero < ultimoNumero) {
                    ordenCorrecto = false;
                }
                ultimoNumero = numero;

                const colorClass = numero < ultimoNumero ? 'red' : '';
                html += `<tr>
                    <td>${ext.id}</td>
                    <td><strong>${codigo}</strong></td>
                    <td>${ext.seccion || 'Sin sección'}</td>
                    <td>${ext.estado}</td>
                    <td>${ext.tipo_nombre}</td>
                </tr>`;
            });

            html += '</table>';

            if (ordenCorrecto || extintores.length <= 1) {
                html = '<p class="green">✅ Los extintores están ordenados correctamente</p>' + html;
            } else {
                html = '<p class="red">❌ Los extintores NO están en orden correcto</p>' + html;
            }

            document.getElementById('resultado').innerHTML = html;
        });
    </script>
</body>
</html>
