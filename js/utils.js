/**
 * AVBA — Utilidades compartidas
 */

/* ══════════════════════════════════════════
   VALIDACIÓN MATEMÁTICA DE CURP
   Algoritmo oficial RENAPO (dígito verificador)
══════════════════════════════════════════ */

const CURP_TABLA = '0123456789ABCDEFGHIJKLMNÑOPQRSTUVWXYZ';

/**
 * Valida la CURP: formato + dígito verificador matemático.
 * @param {string} curp
 * @returns {{ valida: boolean, error?: string }}
 */
function validarCURPCompleta(curp) {
  curp = (curp || '').toUpperCase().trim();

  if (curp.length !== 18) {
    return { valida: false, error: 'La CURP debe tener exactamente 18 caracteres.' };
  }

  if (!/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/.test(curp)) {
    return { valida: false, error: 'El formato de la CURP no es válido.' };
  }

  // Calcular dígito verificador (posiciones 0–16)
  let suma = 0;
  for (let i = 0; i < 17; i++) {
    const val = CURP_TABLA.indexOf(curp[i]);
    if (val < 0) return { valida: false, error: 'Carácter no reconocido en la CURP.' };
    suma += val * (18 - i);
  }
  const digitoEsperado = (10 - (suma % 10)) % 10;
  const digitoReal = parseInt(curp[17], 10);

  if (digitoEsperado !== digitoReal) {
    return {
      valida: false,
      error: `CURP inválida: el dígito verificador no corresponde (esperado ${digitoEsperado}).`
    };
  }

  return { valida: true };
}

/* ══════════════════════════════════════════
   BÚSQUEDA DE EMPRESA (autocomplete)
   Uso: empresaSearch(inputEl, hiddenEl, options)
══════════════════════════════════════════ */

/**
 * Inicializa el autocomplete de empresa en un campo de texto.
 * @param {HTMLInputElement} inputEl   - Input de texto visible
 * @param {HTMLInputElement} hiddenEl  - Input oculto que guarda primera_parte (ID 5 dígitos)
 * @param {object}           opts
 *   opts.token        {string}   - Token de sesión para la API
 *   opts.apiUrl       {string}   - URL base de la API (default 'api/index.php')
 *   opts.onSelect     {Function} - Callback(empresa) cuando el usuario selecciona
 *   opts.dropdownId   {string}   - ID del div dropdown a crear/usar
 */
function initEmpresaSearch(inputEl, hiddenEl, opts = {}) {
  const apiUrl    = opts.apiUrl || 'api/index.php';
  const token     = () => opts.token || (JSON.parse(localStorage.getItem('avba_session') || '{}').token || '');
  let timer       = null;
  let dropdown    = null;

  // Crear dropdown si no existe
  const dropId = opts.dropdownId || ('emp-drop-' + Math.random().toString(36).slice(2, 7));
  dropdown = document.getElementById(dropId);
  if (!dropdown) {
    dropdown = document.createElement('div');
    dropdown.id = dropId;
    dropdown.style.cssText = [
      'position:absolute','z-index:9999','background:#fff',
      'border:1.5px solid #dfe5ef','border-radius:10px',
      'box-shadow:0 4px 16px rgba(0,0,0,0.12)',
      'max-height:220px','overflow-y:auto','display:none',
      'width:100%','margin-top:2px'
    ].join(';');
    // Wrap input in relative container if needed
    const wrap = inputEl.parentElement;
    const wrapStyle = getComputedStyle(wrap).position;
    if (wrapStyle === 'static') wrap.style.position = 'relative';
    wrap.appendChild(dropdown);
  }

  function cerrarDropdown() {
    dropdown.style.display = 'none';
    dropdown.innerHTML = '';
  }

  function seleccionar(empresa) {
    inputEl.value  = empresa.nombre_cliente;
    hiddenEl.value = empresa.primera_parte || '';
    cerrarDropdown();
    if (opts.onSelect) opts.onSelect(empresa);
  }

  function renderDropdown(resultados) {
    dropdown.innerHTML = '';
    if (!resultados.length) {
      dropdown.innerHTML = '<div style="padding:10px 14px;font-size:13px;color:#5a6072">Sin coincidencias — se creará empresa nueva</div>';
      dropdown.style.display = 'block';
      return;
    }
    resultados.forEach(emp => {
      const item = document.createElement('div');
      item.style.cssText = 'padding:10px 14px;cursor:pointer;font-size:13px;border-bottom:1px solid #f0f4f9';
      item.innerHTML = `<strong style="color:#1a1a2e">${escHtmlUtil(emp.nombre_cliente)}</strong>
        <span style="font-size:11px;color:#5a6072;margin-left:8px">ID: ${emp.primera_parte}</span>`;
      item.addEventListener('mousedown', e => { e.preventDefault(); seleccionar(emp); });
      item.addEventListener('mouseover', () => item.style.background = '#E6F1FB');
      item.addEventListener('mouseout',  () => item.style.background = '');
      dropdown.appendChild(item);
    });
    dropdown.style.display = 'block';
  }

  async function buscar(q) {
    if (q.length < 2) { cerrarDropdown(); hiddenEl.value = ''; return; }
    try {
      const res = await fetch(`${apiUrl}?action=BUSCAR_CLIENTE&q=${encodeURIComponent(q)}`, {
        headers: { 'X-Token': token() }
      });
      const data = await res.json();
      if (data.status === 'success') renderDropdown(data.clientes || []);
    } catch(e) { cerrarDropdown(); }
  }

  inputEl.addEventListener('input', () => {
    hiddenEl.value = ''; // reset ID cuando el usuario escribe
    clearTimeout(timer);
    timer = setTimeout(() => buscar(inputEl.value.trim()), 280);
  });

  inputEl.addEventListener('blur', () => {
    setTimeout(cerrarDropdown, 180);
  });

  inputEl.addEventListener('focus', () => {
    if (inputEl.value.trim().length >= 2) buscar(inputEl.value.trim());
  });
}

function escHtmlUtil(s) {
  return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
