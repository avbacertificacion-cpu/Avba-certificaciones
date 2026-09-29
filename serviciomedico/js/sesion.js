/* ==========================================================================
   Inicio y cierre de sesión (de demostración: no hay servidor que valide).
   ========================================================================== */

const CLAVE_SESION = 'avba_serviciomedico_sesion';

const ROLES = {
  admin:      'Administrador médico',
  paramedico: 'Paramédico',
  planta:     'Planta',
};

function iniciarSesion(usuario, password) {
  const datos = DB.leer();
  const u = datos.usuarios.find(x =>
    x.activo && x.usuario.toLowerCase() === usuario.trim().toLowerCase() && x.password === password);
  if (!u) return null;

  // Los paramédicos quedan registrados en el turno que detecta el sistema.
  if (u.rol === 'paramedico') registrarGuardia(datos, u);

  try { localStorage.setItem(CLAVE_SESION, JSON.stringify({ usuarioId: u.id, inicio: new Date().toISOString() })); }
  catch (e) { return null; }
  return u;
}

// Una guardia = un paramédico presente en un turno concreto. Si vuelve a
// entrar durante el mismo turno no se duplica.
function registrarGuardia(datos, u) {
  const t = detectarTurno(new Date(), u.esquema);
  const existe = datos.guardias.some(g =>
    g.usuarioId === u.id && g.fechaTurno === t.fechaTurno && g.turno === t.clave);
  if (existe) return;
  datos.guardias.push({
    id: DB.nuevoId('g'),
    usuarioId: u.id,
    ambulanciaId: u.ambulanciaId || null,
    turno: t.clave,
    esquema: t.esquema,
    fechaTurno: t.fechaTurno,
    entrada: new Date().toISOString(),
  });
  DB.guardar(datos);
}

function usuarioActual() {
  try {
    const s = JSON.parse(localStorage.getItem(CLAVE_SESION));
    if (!s) return null;
    return DB.leer().usuarios.find(u => u.id === s.usuarioId && u.activo) || null;
  } catch (e) { return null; }
}

function cerrarSesion() {
  try { localStorage.removeItem(CLAVE_SESION); } catch (e) {}
  location.href = 'index.html';
}

// Evita que se inyecte HTML al mostrar datos capturados por el usuario.
function esc(texto) {
  return String(texto ?? '').replace(/[&<>"']/g, c =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
