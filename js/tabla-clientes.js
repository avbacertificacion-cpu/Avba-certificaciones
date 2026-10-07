/**
 * AVBA — Tabla agrupada por cliente, con ordenamiento por columna.
 *
 * Las pantallas de Calidad y Certificaciones listaban un renglón por
 * certificación. Con cientos de registros, encontrar los de un cliente era
 * ir filtrando a mano. Aquí se agrupan: se ven los clientes, y al abrir uno
 * salen sus certificaciones.
 *
 * Lo demás no cambia. El filtrado, el detalle y las acciones siguen siendo
 * de cada página; esto sólo decide cómo se acomoda lo que ya llega.
 *
 * ORDENAMIENTO. Al pulsar un encabezado se ordena por esa columna; al volver
 * a pulsarlo, al revés. La regla cuando está agrupado es una sola: se ordenan
 * los renglones de cada cliente, y los clientes se ordenan por su mejor
 * renglón bajo ese mismo criterio. Así, ordenando por fecha, arriba queda el
 * cliente con la certificación más reciente, y dentro de él esa misma
 * certificación primero.
 */
(function (global) {
  'use strict';

  /** dd/mm/aaaa → número comparable. Lo que no se entienda va al final. */
  function aFecha(v) {
    if (!v) return -Infinity;
    const m = String(v).match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
    if (m) return new Date(+m[3], +m[2] - 1, +m[1]).getTime();
    const t = Date.parse(v);
    return isNaN(t) ? -Infinity : t;
  }

  function comparar(a, b, tipo) {
    if (tipo === 'fecha') { a = aFecha(a); b = aFecha(b); }
    else if (tipo === 'num') { a = a === null || a === undefined ? -Infinity : +a;
                               b = b === null || b === undefined ? -Infinity : +b; }
    else {
      a = String(a ?? '').toLowerCase();
      b = String(b ?? '').toLowerCase();
      // Con acentos y ñ, el orden del navegador es el correcto en español.
      return a.localeCompare(b, 'es');
    }
    return a < b ? -1 : a > b ? 1 : 0;
  }

  function esc(s) {
    return String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
  }

  let _n = 0;

  /**
   * @param op.tbody    id del <tbody>
   * @param op.thead    id del <tr> de encabezados, que este módulo rellena
   * @param op.cols     [{key, label, tipo, valor(fila), ancho}]
   * @param op.fila     (registro, agrupado) => string con los <td>. Recibe si
   *                    está agrupado para no repetir el nombre del cliente
   *                    dentro del grupo que ya lo lleva en su encabezado.
   * @param op.grupo    campo por el que se agrupa (por omisión 'cliente')
   * @param op.resumen  (registros) => string a la derecha del nombre del cliente
   * @param op.orden    {campo, dir} inicial
   * @param op.contador id del elemento donde va "N registros"
   */
  function crear(op) {
    const id = 'tc' + (++_n);
    const est = {
      campo: (op.orden && op.orden.campo) || 'fecha',
      dir:   (op.orden && op.orden.dir)   || 'desc',
      // Agrupado de entrada: es lo que se pidió. El interruptor permite ver
      // la lista plana de siempre cuando hace falta comparar entre clientes.
      agrupado: op.agrupado !== false,
      abiertos: new Set(),
      datos: [],
    };
    const cols = op.cols;
    const campoGrupo = op.grupo || 'cliente';

    function valorDe(r, col) {
      return col.valor ? col.valor(r) : r[col.key];
    }

    function ordenar(lista) {
      const col = cols.find(c => c.key === est.campo) || cols[0];
      const signo = est.dir === 'asc' ? 1 : -1;
      return [...lista].sort((a, b) => signo * comparar(valorDe(a, col), valorDe(b, col), col.tipo));
    }

    function pintarEncabezado() {
      const tr = document.getElementById(op.thead);
      if (!tr) return;
      tr.innerHTML = cols.map(c => {
        const act = c.key === est.campo;
        const flecha = act ? (est.dir === 'asc' ? ' ▲' : ' ▼') : '';
        return `<th${c.ancho ? ` style="width:${c.ancho}"` : ''}
            class="tc-th${act ? ' tc-act' : ''}" data-k="${esc(c.key)}"
            title="Ordenar por ${esc(c.label)}">${esc(c.label)}<span class="tc-flecha">${flecha}</span></th>`;
      }).join('') + '<th></th>';
      tr.querySelectorAll('.tc-th').forEach(th => {
        th.onclick = () => {
          const k = th.dataset.k;
          // Misma columna: se invierte. Columna nueva: arranca ascendente,
          // salvo las fechas, donde lo útil es ver primero lo más reciente.
          if (est.campo === k) est.dir = est.dir === 'asc' ? 'desc' : 'asc';
          else { est.campo = k; est.dir = (cols.find(c => c.key === k) || {}).tipo === 'fecha' ? 'desc' : 'asc'; }
          pintar();
        };
      });
    }

    function pintar() {
      const tbody = document.getElementById(op.tbody);
      if (!tbody) return;
      pintarEncabezado();

      if (op.contador) {
        const el = document.getElementById(op.contador);
        if (el) el.textContent = est.datos.length + ' registro' + (est.datos.length !== 1 ? 's' : '');
      }

      if (!est.datos.length) {
        tbody.innerHTML = `<tr><td colspan="${cols.length + 1}"
          style="text-align:center;padding:36px;color:var(--texto-hint)">No hay registros que coincidan.</td></tr>`;
        return;
      }

      if (!est.agrupado) {
        tbody.innerHTML = ordenar(est.datos).map(r => `<tr>${op.fila(r, false)}</tr>`).join('');
        return;
      }

      // Agrupar conservando el orden en que aparece cada cliente.
      const grupos = new Map();
      est.datos.forEach(r => {
        const k = (r[campoGrupo] || '—').trim() || '—';
        if (!grupos.has(k)) grupos.set(k, []);
        grupos.get(k).push(r);
      });

      // Cada cliente se ordena por dentro, y los clientes entre sí por su
      // primer renglón: así el criterio elegido manda en los dos niveles.
      const col = cols.find(c => c.key === est.campo) || cols[0];
      const signo = est.dir === 'asc' ? 1 : -1;
      const lista = [...grupos.entries()].map(([nombre, filas]) => ({ nombre, filas: ordenar(filas) }));
      lista.sort((a, b) => est.campo === campoGrupo
        ? signo * comparar(a.nombre, b.nombre, 'texto')
        : signo * comparar(valorDe(a.filas[0], col), valorDe(b.filas[0], col), col.tipo));

      tbody.innerHTML = lista.map(g => {
        const abierto = est.abiertos.has(g.nombre);
        const resumen = op.resumen ? op.resumen(g.filas) : '';
        return `<tr class="tc-grupo${abierto ? ' tc-abierto' : ''}" data-g="${esc(g.nombre)}">
            <td colspan="${cols.length + 1}">
              <div class="tc-grupo-in">
                <span class="tc-chev">${abierto ? '▾' : '▸'}</span>
                <span class="tc-nombre">${esc(g.nombre)}</span>
                <span class="tc-cuenta">${g.filas.length}</span>
                <span class="tc-resumen">${resumen}</span>
              </div>
            </td>
          </tr>`
          + (abierto ? g.filas.map(r => `<tr class="tc-fila">${op.fila(r, true)}</tr>`).join('') : '');
      }).join('');

      tbody.querySelectorAll('.tc-grupo').forEach(tr => {
        tr.onclick = () => {
          const g = tr.dataset.g;
          if (est.abiertos.has(g)) est.abiertos.delete(g); else est.abiertos.add(g);
          pintar();
        };
      });
    }

    return {
      id,
      render(datos) { est.datos = datos || []; pintar(); },
      agrupar(v) { est.agrupado = !!v; pintar(); },
      estaAgrupado() { return est.agrupado; },
      abrirTodos() { est.datos.forEach(r => est.abiertos.add((r[campoGrupo] || '—').trim() || '—')); pintar(); },
      cerrarTodos() { est.abiertos.clear(); pintar(); },
    };
  }

  global.TablaClientes = { crear, aFecha, comparar };
})(window);
