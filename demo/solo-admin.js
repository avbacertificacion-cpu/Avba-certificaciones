/* AVBA Control de Accesos — la maqueta es sólo para Administración AVBA.
   Con la sesión de otro perfil (administrador de la planta, cliente o guardia) se
   regresa a su pantalla de inicio. Sin sesión se deja abrir: así el teléfono que
   entra a la demo con el código QR de la portada sigue funcionando. */
(function () {
  var ses = null;
  try { ses = JSON.parse(localStorage.getItem('ca_sesion') || 'null'); } catch (e) {}
  if (!ses || !ses.token || ses.rol === 'ADMIN') return;
  var inicio = { PLANTA: 'index.html', CLIENTE: 'tablero.html', ACCESOS: 'accesos.html' }[ses.rol] || 'index.html';
  location.replace('../' + inicio);
})();
