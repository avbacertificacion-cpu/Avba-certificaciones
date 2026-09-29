/* ==========================================================================
   Detección de posibles enfermedades.

   Son reglas sencillas sobre el historial del trabajador (consultas,
   presiones de alto riesgo y antecedentes). No es un diagnóstico: cada
   alerta es un aviso para que el médico lo valore y explica por qué salió.
   ========================================================================== */

const NIVELES = {
  alto:  { nombre: 'Alto',  badge: 'badge-rojo',  peso: 3 },
  medio: { nombre: 'Medio', badge: 'badge-ambar', peso: 1 },
};

const DIA_MS = 86400000;

// Todas las tomas de presión del trabajador (consultas + alto riesgo)
function presionesDe(datos, trabajadorId) {
  const lista = [];
  datos.consultas.forEach(c => {
    if (c.trabajadorId === trabajadorId && c.signos.sistolica)
      lista.push({ fecha: c.fecha, sis: c.signos.sistolica, dia: c.signos.diastolica, origen: 'Consulta' });
  });
  datos.evaluaciones.forEach(e => {
    if (e.trabajadorId === trabajadorId)
      lista.push({ fecha: e.fecha, sis: e.sistolica, dia: e.diastolica, origen: 'Alto riesgo' });
  });
  return lista.sort((a, b) => a.fecha.localeCompare(b.fecha));
}

function evaluarSalud(datos, trabajador, ahora = new Date()) {
  const alertas = [];
  const hace = dias => new Date(ahora.getTime() - dias * DIA_MS).toISOString();
  const lim = (datos.config && datos.config.limites) || LIMITES_PRESION;

  // 1. Presión arterial: 3 o más tomas elevadas en 30 días
  const presiones = presionesDe(datos, trabajador.id).filter(p => p.fecha >= hace(30));
  const elevadas = presiones.filter(p => p.sis >= 140 || p.dia >= 90);
  const muyAltas = presiones.filter(p => p.sis >= 160 || p.dia >= 100);
  if (elevadas.length >= 3) {
    const ya = trabajador.antecedentes.hipertension;
    alertas.push({
      clave: 'hipertension',
      nivel: muyAltas.length >= 2 || (ya && elevadas.length >= 5) ? 'alto' : 'medio',
      titulo: ya ? 'Hipertensión no controlada' : 'Posible hipertensión',
      porque: `${elevadas.length} de ${presiones.length} tomas ≥140/90 en los últimos 30 días` +
        (muyAltas.length ? ` (${muyAltas.length} ≥160/100)` : '') + (ya ? '. Tiene antecedente de hipertensión.' : '.'),
    });
  }

  // 2. Glucosa en 90 días
  const glucosas = datos.consultas.filter(c => c.trabajadorId === trabajador.id && c.signos.glucosa && c.fecha >= hace(90));
  const ayunoAltas = glucosas.filter(c => c.signos.ayuno && c.signos.glucosa >= 126);
  const casualMuyAlta = glucosas.filter(c => !c.signos.ayuno && c.signos.glucosa >= 200);
  const prediabetes = glucosas.filter(c => c.signos.ayuno && c.signos.glucosa >= 100 && c.signos.glucosa < 126);
  if (ayunoAltas.length >= 2 || casualMuyAlta.length >= 1) {
    const ya = trabajador.antecedentes.diabetes;
    alertas.push({
      clave: 'diabetes', nivel: 'alto',
      titulo: ya ? 'Diabetes descontrolada' : 'Posible diabetes',
      porque: ayunoAltas.length >= 2
        ? `${ayunoAltas.length} glucosas en ayuno ≥126 mg/dL en 90 días (máx. ${Math.max(...ayunoAltas.map(c => c.signos.glucosa))}).`
        : `Glucosa casual ≥200 mg/dL (${casualMuyAlta[0].signos.glucosa}).`,
    });
  } else if (prediabetes.length >= 2) {
    alertas.push({ clave: 'prediabetes', nivel: 'medio', titulo: 'Posible prediabetes',
      porque: `${prediabetes.length} glucosas en ayuno entre 100 y 125 mg/dL en 90 días.` });
  }

  // 3. Obesidad por IMC
  const imc = calcularIMC(trabajador.peso, trabajador.talla);
  if (imc >= 30) {
    alertas.push({ clave: 'obesidad', nivel: imc >= 35 ? 'alto' : 'medio', titulo: imc >= 35 ? 'Obesidad grado II o mayor' : 'Obesidad',
      porque: `IMC de ${imc} (peso ${trabajador.peso} kg, talla ${trabajador.talla} cm).` });
  }

  // 4. Riesgo cardiometabólico: obesidad o tabaquismo sumados a presión o glucosa alteradas
  const tienePresion = alertas.some(a => a.clave === 'hipertension') || trabajador.antecedentes.hipertension;
  const tieneGlucosa = alertas.some(a => ['diabetes', 'prediabetes'].includes(a.clave)) || trabajador.antecedentes.diabetes;
  const factores = [];
  if (imc >= 30) factores.push('obesidad');
  if (trabajador.antecedentes.tabaquismo) factores.push('tabaquismo');
  if (tienePresion) factores.push('presión elevada');
  if (tieneGlucosa) factores.push('glucosa alterada');
  if (factores.length >= 3 || (imc >= 30 && (tienePresion || tieneGlucosa))) {
    alertas.push({ clave: 'cardiometabolico', nivel: 'alto', titulo: 'Riesgo cardiometabólico',
      porque: `Suma de factores: ${factores.join(', ')}.` });
  }

  // 5. Padecimiento recurrente: mismo motivo 4+ veces en 60 días
  const porMotivo = {};
  datos.consultas.forEach(c => {
    if (c.trabajadorId === trabajador.id && c.fecha >= hace(60) && c.motivo !== 'Otro' &&
        !['Control de presión arterial', 'Control de glucosa'].includes(c.motivo))
      porMotivo[c.motivo] = (porMotivo[c.motivo] || 0) + 1;
  });
  Object.entries(porMotivo).filter(([, n]) => n >= 4).forEach(([motivo, n]) => {
    alertas.push({ clave: 'recurrente', nivel: 'medio', titulo: `Recurrente: ${motivo}`,
      porque: `${n} consultas por "${motivo}" en los últimos 60 días.` });
  });

  // 6. Alto riesgo: 2 o más "No apto" en 30 días
  const noAptos = datos.evaluaciones.filter(e => e.trabajadorId === trabajador.id && e.fecha >= hace(30) &&
    dictaminar(e.sistolica, e.diastolica, lim) === 'no_apto');
  if (noAptos.length >= 2) {
    alertas.push({ clave: 'no_apto', nivel: 'alto', titulo: 'No apto recurrente para alto riesgo',
      porque: `${noAptos.length} dictámenes "No apto" en 30 días. Conviene valoración antes de volver a asignarlo.` });
  }

  const puntos = alertas.reduce((s, a) => s + NIVELES[a.nivel].peso, 0);
  const riesgo = puntos >= 3 ? 'alto' : puntos >= 1 ? 'medio' : 'bajo';
  return { alertas, puntos, riesgo, imc };
}

// Evalúa a todos los trabajadores activos (ordenados del más al menos propenso)
function evaluarTodos(datos) {
  return datos.trabajadores.filter(t => t.activo)
    .map(t => ({ trabajador: t, ...evaluarSalud(datos, t) }))
    .sort((a, b) => b.puntos - a.puntos);
}

const RIESGOS = {
  alto:  { nombre: 'Riesgo alto',  badge: 'badge-rojo' },
  medio: { nombre: 'Riesgo medio', badge: 'badge-ambar' },
  bajo:  { nombre: 'Sin alertas',  badge: 'badge-verde' },
};
