/* AVBA Control de Accesos — pantalla de inicio de cada perfil.
   La usan el inicio de sesión (login.html) y el botón de regresar de
   accesos.html. Un perfil nuevo se agrega sólo aquí (y en api/Auth.php). */
window.CA_DESTINOS = {
  ADMIN:   'index.html',     // Administración AVBA: inicio con Control de accesos y Cuentas
  PLANTA:  'index.html',     // Administrador de accesos de la planta
  ACCESOS: 'accesos.html',   // Guardia de caseta: directo a registrar entradas y salidas
  CLIENTE: 'tablero.html',   // Cliente: directo al tablero (gráficas, Excel y PDF)
};
/* Clave de la sesión en este navegador. Con prefijo ca_: la plataforma
   comparte dominio con el Sistema de Gestión (que usa avba_session). */
window.CA_SESION = 'ca_sesion';
