/* ==========================================================================
   Ayudas para las gráficas (Chart.js, incluida en js/vendor).
   Los colores de serie siguen un orden fijo y validado para daltonismo;
   los de estado (apto / revalorar / no apto) se reservan sólo para eso.
   ========================================================================== */

const COLORES = {
  serie: ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#4a3aa7', '#e34948'],
  estado: { apto: '#4a8a1c', revalorar: '#e0a106', no_apto: '#c93b3b' },
  riesgo: { alto: '#c93b3b', medio: '#e0a106', bajo: '#4a8a1c' },
  texto: '#5a6072',
  tenue: '#9299a8',
  rejilla: '#eef1f6',
  superficie: '#ffffff',
};

if (window.Chart) {
  Chart.defaults.font.family = "'Segoe UI', system-ui, sans-serif";
  Chart.defaults.font.size = 11.5;
  Chart.defaults.color = COLORES.texto;
  Chart.defaults.plugins.legend.display = false; // usamos leyendas en HTML
  Chart.defaults.plugins.tooltip.backgroundColor = '#1a1a2e';
  Chart.defaults.plugins.tooltip.padding = 10;
  Chart.defaults.plugins.tooltip.cornerRadius = 8;
  Chart.defaults.plugins.tooltip.boxPadding = 4;
  Chart.defaults.maintainAspectRatio = false;
  Chart.defaults.animation.duration = 400;
}

function grafica(id, config) {
  const lienzo = document.getElementById(id);
  if (!lienzo || !window.Chart) return null;
  const g = new Chart(lienzo, config);
  graficas.push(g);
  return g;
}

// Ejes discretos: rejilla fina, sin bordes, números limpios
function ejes({ apilada = false, horizontal = false, max, min } = {}) {
  const valor = { beginAtZero: min == null, min, max, stacked: apilada, grid: { color: COLORES.rejilla }, border: { display: false },
    ticks: { precision: 0, color: COLORES.tenue } };
  const categoria = { stacked: apilada, grid: { display: false }, border: { color: COLORES.rejilla }, ticks: { color: COLORES.tenue, autoSkipPadding: 8, autoSkip: !horizontal } };
  return horizontal ? { x: valor, y: categoria } : { x: categoria, y: valor };
}

// Barras delgadas con esquina redondeada en el extremo del dato
function barras(etiqueta, datos, color, extra = {}) {
  return { label: etiqueta, data: datos, backgroundColor: color, hoverBackgroundColor: color,
    borderRadius: 4, borderSkipped: 'start', maxBarThickness: 24, borderColor: COLORES.superficie, borderWidth: 0, ...extra };
}

function linea(etiqueta, datos, color, extra = {}) {
  return { label: etiqueta, data: datos, borderColor: color, backgroundColor: color, borderWidth: 2,
    pointRadius: 3, pointHoverRadius: 6, pointBorderColor: COLORES.superficie, pointBorderWidth: 2,
    tension: 0.25, fill: false, ...extra };
}

function leyenda(items) {
  return `<div class="leyenda">${items.map(([texto, color]) => `<span><i style="background:${color}"></i>${esc(texto)}</span>`).join('')}</div>`;
}

// Últimos n días como fechas AAAA-MM-DD (del más viejo al de hoy)
function ultimosDias(n) {
  const lista = [];
  for (let i = n - 1; i >= 0; i--) { const d = new Date(); d.setDate(d.getDate() - i); lista.push(fechaISO(d)); }
  return lista;
}
function etiquetaDia(iso) {
  const [a, m, d] = iso.split('-').map(Number);
  return new Date(a, m - 1, d).toLocaleDateString('es-MX', { day: 'numeric', month: 'short' });
}
