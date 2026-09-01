<?php
/**
 * Panel de documentos adjuntos, reutilizable.
 *
 * Se incluye dentro de cualquier pantalla de administración y se abre con:
 *     abrirDocs('cotizacion', 12, 'COT-2026-001')
 *
 * Depende únicamente de api/documentos.php, así que no impone estilos ni
 * variables a la página que lo incluye.
 */
?>
<style>
    .doc-ov{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:200;align-items:flex-start;justify-content:center;padding:20px;overflow-y:auto}
    .doc-ov.open{display:flex}
    .doc-modal{background:#fff;border-radius:14px;width:100%;max-width:720px;padding:24px;margin:auto;
               font-family:'Segoe UI',system-ui,sans-serif;color:#1a2138}
    .doc-modal h3{font-size:19px;margin-bottom:4px;color:#1e293b}
    .doc-modal .doc-sub{font-size:13px;color:#64748b;margin-bottom:16px}
    .doc-form{background:#f8faff;border:2px dashed #c7d2fe;border-radius:12px;padding:16px;margin-bottom:18px}
    .doc-form .fila{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px}
    .doc-form label{display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:5px}
    .doc-form input,.doc-form select{width:100%;padding:9px;border:2px solid #e0e0ff;border-radius:8px;font-size:14px;font-family:inherit;background:#fff}
    .doc-form input:focus,.doc-form select:focus{outline:none;border-color:#667eea}
    .doc-hint{font-size:11px;color:#94a3b8;margin-top:6px}
    .doc-btn{padding:9px 16px;border:none;border-radius:8px;font-weight:700;cursor:pointer;font-size:14px}
    .doc-btn-primary{background:#667eea;color:#fff}.doc-btn-primary:hover{background:#5568d3}
    .doc-btn-primary:disabled{background:#a5b4fc;cursor:progress}
    .doc-btn-ghost{background:#eef2fb;color:#475569}
    .doc-lista{display:flex;flex-direction:column;gap:8px}
    .doc-item{display:flex;align-items:center;gap:12px;padding:11px 13px;border:1px solid #e8eeff;border-radius:10px}
    .doc-item:hover{background:#f8faff}
    .doc-ic{font-size:24px;line-height:1}
    .doc-info{flex:1;min-width:0}
    .doc-nombre{font-weight:700;font-size:14px;word-break:break-word}
    .doc-meta{font-size:11px;color:#64748b;margin-top:2px}
    .doc-tag{display:inline-block;padding:2px 8px;border-radius:12px;font-size:10px;font-weight:700;
             background:#e0e7ff;color:#4338ca;margin-right:6px;text-transform:uppercase}
    .doc-tag.factura{background:#d1fae5;color:#047857}
    .doc-tag.evidencia{background:#fef3c7;color:#92400e}
    .doc-acc{display:flex;gap:6px;flex-shrink:0}
    .doc-acc a,.doc-acc button{padding:6px 10px;border:none;border-radius:7px;font-size:12px;font-weight:700;
                               cursor:pointer;text-decoration:none;display:inline-block}
    .doc-acc .ver{background:#eef2fb;color:#475569}
    .doc-acc .baj{background:#dbeafe;color:#1d4ed8}
    .doc-acc .qui{background:#fee2e2;color:#b91c1c}
    .doc-vacio{text-align:center;padding:26px;color:#94a3b8;font-size:13px}
    .doc-msg{font-size:13px;font-weight:600;margin-bottom:10px;min-height:18px}
    @media(max-width:640px){ .doc-form .fila{grid-template-columns:1fr} .doc-item{flex-wrap:wrap} }
</style>

<div class="doc-ov" id="docOv">
  <div class="doc-modal">
    <h3>📎 Documentos</h3>
    <div class="doc-sub" id="docSub"></div>
    <div class="doc-msg" id="docMsg"></div>

    <form class="doc-form" id="docForm" onsubmit="return subirDoc(event)">
        <div class="fila">
            <div>
                <label>Tipo de documento</label>
                <select id="docTipo">
                    <option value="factura">Factura</option>
                    <option value="evidencia">Evidencia (fotos, actas)</option>
                    <option value="orden_compra">Orden de compra del cliente</option>
                    <option value="remision">Remisión / entrega</option>
                    <option value="cotizacion_proveedor">Cotización del proveedor</option>
                    <option value="otro">Otro</option>
                </select>
            </div>
            <div>
                <label>Descripción (opcional)</label>
                <input type="text" id="docDesc" placeholder="Ej: Factura A-4412 del proveedor">
            </div>
        </div>
        <div>
            <label>Archivo</label>
            <input type="file" id="docArchivo" accept=".pdf,.jpg,.jpeg,.png,.webp,.gif,.xml,.txt,.csv,.doc,.docx,.xls,.xlsx">
            <div class="doc-hint">PDF, imágenes, XML (CFDI), Word, Excel, TXT o CSV. Máximo 15 MB por archivo.</div>
        </div>
        <div style="text-align:right;margin-top:12px">
            <button type="submit" class="doc-btn doc-btn-primary" id="docSubir">⬆️ Subir documento</button>
        </div>
    </form>

    <div class="doc-lista" id="docLista"></div>

    <div style="text-align:right;margin-top:18px">
        <button type="button" class="doc-btn doc-btn-ghost" onclick="cerrarDocs()">Cerrar</button>
    </div>
  </div>
</div>

<script>
(function () {
    const DOC_API = '../api/documentos.php';
    let docModulo = '', docRegistro = 0;

    const dEsc = s => { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };

    const ETIQUETA_TIPO = {
        factura: 'Factura', evidencia: 'Evidencia', orden_compra: 'Orden de compra',
        remision: 'Remisión', cotizacion_proveedor: 'Cot. proveedor', otro: 'Otro'
    };

    function iconoDoc(mime, nombre) {
        if (!mime) mime = '';
        if (mime.startsWith('image/')) return '🖼️';
        if (mime === 'application/pdf') return '📄';
        if (mime.indexOf('spreadsheet') > -1 || mime.indexOf('excel') > -1) return '📊';
        if (mime.indexOf('word') > -1) return '📝';
        if (mime.indexOf('xml') > -1) return '🧾';
        return '📎';
    }

    function pesoLegible(b) {
        b = Number(b) || 0;
        if (b < 1024) return b + ' B';
        if (b < 1048576) return (b / 1024).toFixed(0) + ' KB';
        return (b / 1048576).toFixed(1) + ' MB';
    }

    function fechaHora(f) {
        if (!f) return '';
        const s = String(f);
        const p = s.substring(0, 10).split('-');
        return p.length === 3 ? `${p[2]}/${p[1]}/${p[0]} ${s.substring(11, 16)}` : s;
    }

    function docAviso(t, ok) {
        const m = document.getElementById('docMsg');
        m.textContent = t || '';
        m.style.color = ok ? '#27ae60' : '#c0392b';
        if (t) setTimeout(() => { if (m.textContent === t) m.textContent = ''; }, 5000);
    }

    window.abrirDocs = function (modulo, registroId, titulo) {
        if (!registroId) {
            alert('Guarda primero el registro y después podrás adjuntarle documentos.');
            return;
        }
        docModulo = modulo; docRegistro = registroId;
        document.getElementById('docSub').textContent = titulo || '';
        document.getElementById('docDesc').value = '';
        document.getElementById('docArchivo').value = '';
        docAviso('');
        document.getElementById('docOv').classList.add('open');
        cargarDocs();
    };

    window.cerrarDocs = function () {
        document.getElementById('docOv').classList.remove('open');
        // Si la página lleva contadores de adjuntos, se refrescan al cerrar
        if (typeof window.alCerrarDocs === 'function') window.alCerrarDocs();
    };

    async function cargarDocs() {
        const cont = document.getElementById('docLista');
        cont.innerHTML = '<div class="doc-vacio">Cargando…</div>';
        try {
            const r = await fetch(`${DOC_API}?action=listar&modulo=${encodeURIComponent(docModulo)}&registro_id=${docRegistro}`);
            const d = await r.json();
            if (!r.ok || !d.success) { cont.innerHTML = '<div class="doc-vacio">' + dEsc(d.error || 'No se pudo cargar la lista.') + '</div>'; return; }
            pintarDocs(d.data);
        } catch (e) {
            cont.innerHTML = '<div class="doc-vacio">No se pudo cargar la lista.</div>';
        }
    }

    function pintarDocs(items) {
        const cont = document.getElementById('docLista');
        if (!items.length) {
            cont.innerHTML = '<div class="doc-vacio">Todavía no hay documentos adjuntos.<br>Sube la factura, la evidencia o lo que necesites conservar aquí.</div>';
            return;
        }
        cont.innerHTML = items.map(d => `
            <div class="doc-item">
                <div class="doc-ic">${iconoDoc(d.mime, d.nombre_original)}</div>
                <div class="doc-info">
                    <div class="doc-nombre">${dEsc(d.nombre_original)}</div>
                    <div class="doc-meta">
                        <span class="doc-tag ${dEsc(d.tipo)}">${dEsc(ETIQUETA_TIPO[d.tipo] || d.tipo)}</span>
                        ${d.descripcion ? dEsc(d.descripcion) + ' · ' : ''}${pesoLegible(d.tamano)} ·
                        ${fechaHora(d.created_at)}${d.subido_por_nombre ? ' · ' + dEsc(d.subido_por_nombre) : ''}
                    </div>
                </div>
                <div class="doc-acc">
                    <a class="ver" href="${DOC_API}?action=descargar&id=${d.id}&inline=1" target="_blank" rel="noopener">👁️ Ver</a>
                    <a class="baj" href="${DOC_API}?action=descargar&id=${d.id}">⬇️</a>
                    <button type="button" class="qui" onclick="borrarDoc(${d.id})">🗑️</button>
                </div>
            </div>`).join('');
    }

    window.subirDoc = function (ev) {
        ev.preventDefault();
        const input = document.getElementById('docArchivo');
        if (!input.files.length) { docAviso('Elige un archivo antes de subir.', false); return false; }

        const fd = new FormData();
        fd.append('archivo', input.files[0]);
        fd.append('tipo', document.getElementById('docTipo').value);
        fd.append('descripcion', document.getElementById('docDesc').value.trim());

        const btn = document.getElementById('docSubir');
        btn.disabled = true; btn.textContent = 'Subiendo…';

        fetch(`${DOC_API}?action=subir&modulo=${encodeURIComponent(docModulo)}&registro_id=${docRegistro}`,
              { method: 'POST', body: fd })
            .then(r => r.json().then(d => ({ ok: r.ok, d })))
            .then(({ ok, d }) => {
                if (ok && d.success) {
                    docAviso('✓ Documento subido', true);
                    input.value = '';
                    document.getElementById('docDesc').value = '';
                    cargarDocs();
                } else {
                    docAviso(d.error || 'No se pudo subir el archivo.', false);
                }
            })
            .catch(() => docAviso('No se pudo subir el archivo.', false))
            .finally(() => { btn.disabled = false; btn.textContent = '⬆️ Subir documento'; });
        return false;
    };

    window.borrarDoc = async function (id) {
        if (!confirm('¿Eliminar este documento? El archivo se borra del servidor.')) return;
        const r = await fetch(`${DOC_API}?action=eliminar&id=${id}`);
        const d = await r.json().catch(() => ({}));
        if (r.ok && d.success) { docAviso('✓ Documento eliminado', true); cargarDocs(); }
        else docAviso(d.error || 'No se pudo eliminar.', false);
    };

    /** Contadores de adjuntos por registro, para pintar «📎 3» en los listados. */
    window.contarDocs = async function (modulo) {
        try {
            const r = await fetch(`${DOC_API}?action=conteos&modulo=${encodeURIComponent(modulo)}`);
            const d = await r.json();
            return (r.ok && d.success) ? d.data : {};
        } catch (e) { return {}; }
    };

    document.getElementById('docOv').addEventListener('click', e => {
        if (e.target.id === 'docOv') cerrarDocs();
    });
})();
</script>
