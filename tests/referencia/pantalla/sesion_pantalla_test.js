// Pantallas de la plataforma (copia local, base de prueba): instalación, inicio de sesión,
// inicio por perfil, cuentas, cerrar sesión y que no se toque la sesión del Sistema de Gestión.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const B = 'http://localhost:8794/';
const D = __dirname + '/';
let fallas = 0;
const ok = (c, m) => { if (!c) fallas++; console.log((c ? 'OK   ' : 'FALLA') + ' ' + m); };
const sql = q => execSync(`mariadb --default-character-set=utf8mb4 -N -B -uprueba -pprueba -h127.0.0.1 ca_prueba -e "${q}"`).toString().trim();
// La sesión del Sistema de Gestión (mismo dominio): esta plataforma no debe tocarla
const SESION_PRINCIPAL = JSON.stringify({ token: 'token-del-sistema-de-gestion', rol: 'INSPECTOR', usuario: 'otro' });

async function navegador(b, movil = true) {
  const ctx = await b.newContext(movil
    ? { timezoneId: 'America/Mexico_City', viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true }
    : { timezoneId: 'America/Mexico_City', viewport: { width: 1280, height: 860 } });
  await ctx.addInitScript((s) => { if (!localStorage.getItem('avba_session')) localStorage.setItem('avba_session', s); }, SESION_PRINCIPAL);
  const p = await ctx.newPage();
  p.errores = [];
  p.on('pageerror', e => p.errores.push(e.message));
  p.on('dialog', d => d.accept());
  return { ctx, p };
}
const sinScrollLateral = p => p.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1);
async function entrar(p, usuario, password) {
  await p.goto(B + 'login.html'); await p.waitForSelector('#f-login:not([hidden])');
  await p.fill('#usuario', usuario); await p.fill('#password', password);
  await p.click('#b-login'); await p.waitForTimeout(1500);
}

(async () => {
  const b = await chromium.launch();

  // ── 1) Base vacía: el inicio de sesión ofrece crear la primera cuenta ──
  sql('DELETE FROM sesiones_usuario; DELETE FROM usuarios; DELETE FROM login_intentos');
  let { ctx, p } = await navegador(b);
  await p.goto(B + 'login.html'); await p.waitForTimeout(1200);
  ok(await p.isVisible('#f-instalar') && await p.isHidden('#f-login'), 'base vacía: formulario «Crear la primera cuenta de Administración»');
  await p.screenshot({ path: D + 'ses_instalar.png' });
  await p.fill('#i-clave', 'equivocada'); await p.fill('#i-nombre', 'Lucía Prueba'); await p.fill('#i-usuario', 'jefa.avba');
  await p.fill('#i-pass', 'contrasena-larga'); await p.fill('#i-pass2', 'otra-cosa');
  await p.click('#b-instalar'); await p.waitForTimeout(400);
  ok(/no coinciden/.test(await p.textContent('#err-instalar')), 'contraseñas distintas: aviso');
  await p.fill('#i-pass2', 'contrasena-larga');
  await p.click('#b-instalar'); await p.waitForTimeout(1000);
  ok(/no coincide con la de config/.test(await p.textContent('#err-instalar')), 'clave de instalación equivocada: aviso');
  await p.fill('#i-clave', 'clave-local-prueba');
  await p.click('#b-instalar'); await p.waitForTimeout(1800);
  ok(p.url().endsWith('/index.html'), 'cuenta creada: entra al inicio (' + p.url().replace(B, '') + ')');
  const guardada = await p.evaluate(() => JSON.parse(localStorage.getItem('ca_sesion') || '{}'));
  ok(guardada.rol === 'ADMIN' && guardada.usuario === 'jefa.avba' && /^[0-9a-f]{64}$/.test(guardada.token), 'sesión guardada en ca_sesion');
  ok((await p.evaluate(() => localStorage.getItem('avba_session'))) === SESION_PRINCIPAL, 'la sesión del Sistema de Gestión (avba_session) quedó intacta');
  ok(/LUCIA PRUEBA · Administración AVBA/.test(await p.textContent('#saludo')), 'saludo: ' + await p.textContent('#saludo'));
  ok(await p.isVisible('#m-cuentas'), 'ADMIN ve el mosaico «Cuentas»');
  ok(await sinScrollLateral(p), 'inicio sin desplazamiento lateral en teléfono');
  await p.screenshot({ path: D + 'ses_inicio_admin.png' });

  // ── 2) Cuentas: dar de alta al administrador de la planta y a un guardia ──
  await p.click('#m-cuentas'); await p.waitForTimeout(1200);
  ok(p.url().endsWith('/cuentas.html') && (await p.$$('#lista .cuenta')).length === 1, 'cuentas: sólo la mía');
  const alta = async (nombre, usuario, rol, pass) => {
    await p.click('#b-nueva'); await p.fill('#c-nombre', nombre); await p.fill('#c-usuario', usuario);
    await p.selectOption('#c-rol', rol); await p.fill('#c-pass', pass); await p.click('#b-guardar'); await p.waitForTimeout(1000);
  };
  await alta('Administrador Planta Norte', 'planta.norte', 'PLANTA', 'planta-norte-1');
  await alta('Caseta punto 1', 'punto1', 'ACCESOS', 'caseta-punto-1');
  await alta('Repetida', 'punto1', 'ACCESOS', 'caseta-punto-1');
  ok(/Ya existe/.test(await p.textContent('#f-err')), 'usuario repetido: «' + await p.textContent('#f-err') + '»');
  await p.click('#b-cancelar');
  let filas = await p.$$eval('#lista .cuenta', l => l.map(x => x.innerText.replace(/\s+/g, ' ')));
  ok(filas.length === 3 && filas.some(t => /CASETA PUNTO 1 punto1 Guardia de caseta Sin punto de control/.test(t)) && filas.some(t => /ADMINISTRADOR PLANTA NORTE planta.norte Administrador de la planta/.test(t)),
     'lista con las 3 cuentas y sus perfiles');
  ok(await sinScrollLateral(p), 'cuentas sin desplazamiento lateral en teléfono');
  await p.screenshot({ path: D + 'ses_cuentas.png', fullPage: true });
  // Editar: contraseña nueva al guardia
  await p.click('#lista .cuenta:has-text("punto1") [data-editar]');
  ok((await p.inputValue('#c-usuario')) === 'punto1' && await p.$eval('#c-usuario', e => e.disabled), 'editar: el usuario no se cambia');
  await p.fill('#c-pass', 'caseta-punto-1b'); await p.click('#b-guardar'); await p.waitForTimeout(900);
  ok(/actualizada/.test(await p.textContent('#toast')), 'contraseña nueva guardada');
  ok(await p.$$eval('#lista .cuenta:has-text("tú") [data-activo]', l => l.length) === 0, 'mi propia cuenta no tiene «Desactivar»');
  ok(!p.errores.length, 'sin errores de JavaScript (Administración)' + (p.errores.length ? ': ' + p.errores.join(' | ') : ''));

  // ── 3) Cerrar sesión ──
  const tokAdmin = guardada.token;
  await p.goto(B + 'index.html'); await p.waitForTimeout(600);
  await p.click('#b-salir'); await p.waitForTimeout(1200);
  ok(p.url().includes('login.html') && !(await p.evaluate(() => localStorage.getItem('ca_sesion'))), 'cerrar sesión: vuelve al inicio de sesión y borra ca_sesion');
  ok(sql(`SELECT COUNT(*) FROM sesiones_usuario WHERE token='${tokAdmin}'`) === '0', 'y la sesión ya no existe en el servidor');
  ok((await p.evaluate(() => localStorage.getItem('avba_session'))) === SESION_PRINCIPAL, 'la sesión del Sistema de Gestión sigue intacta');
  await ctx.close();

  // ── 4) Contraseña equivocada varias veces: cuenta regresiva ──
  ({ ctx, p } = await navegador(b));
  await p.goto(B + 'login.html'); await p.waitForSelector('#f-login:not([hidden])');
  await p.fill('#usuario', 'planta.norte');
  for (let i = 0; i < 5; i++) { await p.fill('#password', 'mala' + i); await p.click('#b-login'); await p.waitForTimeout(500); }
  ok(/Demasiados intentos/.test(await p.textContent('#err-login')) && /Espera \d+ s/.test(await p.textContent('#b-login')) && await p.$eval('#b-login', e => e.disabled),
     'tras 5 fallos: «' + await p.textContent('#err-login') + '» y el botón cuenta hacia atrás');
  await p.screenshot({ path: D + 'ses_espera.png' });
  sql("DELETE FROM login_intentos");
  await ctx.close();

  // ── 5) Administrador de la planta: inicio sin «Cuentas»; en Control de accesos ve los puntos sin poder asignarlos ──
  ({ ctx, p } = await navegador(b, false));
  await entrar(p, 'planta.norte', 'planta-norte-1');
  ok(p.url().endsWith('/index.html') && await p.isHidden('#m-cuentas'), 'planta entra al inicio, sin el mosaico «Cuentas»');
  await p.goto(B + 'cuentas.html'); await p.waitForTimeout(600);
  ok(await p.isVisible('#sin-permiso') && await p.isHidden('#todo'), 'planta en cuentas.html: «Sólo Administración AVBA…»');
  await p.goto(B + 'accesos.html'); await p.waitForTimeout(1800);
  ok(await p.isVisible('#tarjeta-puntos') && (await p.$$('#puntos-lista select')).length === 4 && await p.$$eval('#puntos-lista select', l => l.every(s => s.disabled)),
     'planta ve los 4 puntos, con la cuenta de cada uno sin poder cambiarla');
  ok(await p.isVisible('#f-planta'), 'planta tiene el filtro «En planta»');
  ok((await p.getAttribute('#atras', 'href')) === 'index.html', 'el botón de regresar lleva al inicio');
  await p.screenshot({ path: D + 'ses_planta_accesos.png' });
  ok(!p.errores.length, 'sin errores de JavaScript (planta)' + (p.errores.length ? ': ' + p.errores.join(' | ') : ''));
  await ctx.close();

  // ── 6) Guardia: entra directo a accesos.html; «Salir» cierra la sesión ──
  ({ ctx, p } = await navegador(b));
  await entrar(p, 'punto1', 'caseta-punto-1');
  ok(!/accesos\.html/.test(p.url()), 'con la contraseña vieja ya no entra');
  await p.fill('#password', 'caseta-punto-1b'); await p.click('#b-login'); await p.waitForTimeout(2000);
  ok(p.url().endsWith('/accesos.html'), 'el guardia entra directo a registrar');
  ok(/no tiene punto de control asignado/.test(await p.textContent('#banda-punto')), 'sin punto asignado: «' + (await p.textContent('#banda-punto')).slice(0, 70) + '…»');
  await p.goto(B + 'index.html'); await p.waitForTimeout(800);
  ok(p.url().endsWith('/accesos.html'), 'el guardia en el inicio: lo regresa a accesos.html');
  const tokGuardia = await p.evaluate(() => JSON.parse(localStorage.getItem('ca_sesion')).token);
  await p.click('#atras'); await p.waitForTimeout(1200);
  ok(p.url().includes('login.html') && sql(`SELECT COUNT(*) FROM sesiones_usuario WHERE token='${tokGuardia}'`) === '0', '«Salir» del guardia cierra la sesión también en el servidor');
  // El almacenamiento de esta plataforma lleva el prefijo ca_
  const claves = await p.evaluate(() => Object.keys(localStorage));
  ok(claves.every(k => k.startsWith('ca_') || k === 'avba_session'), 'todas las claves propias llevan ca_: ' + claves.join(', '));
  const bases = await p.evaluate(() => indexedDB.databases ? indexedDB.databases().then(l => l.map(x => x.name)) : []);
  ok(!bases.includes('avba_accesos'), 'la cola usa su propia base del navegador: ' + (bases.join(', ') || '(ninguna)'));
  ok(!p.errores.length, 'sin errores de JavaScript (guardia)' + (p.errores.length ? ': ' + p.errores.join(' | ') : ''));
  await ctx.close();

  // ── 7) Sesión vencida en el inicio: se ofrece entrar ──
  ({ ctx, p } = await navegador(b));
  await p.goto(B + 'index.html');
  await p.evaluate(() => localStorage.setItem('ca_sesion', JSON.stringify({ token: 'vencido', rol: 'ADMIN', usuario: 'x', nombre: 'X' })));
  await p.reload(); await p.waitForTimeout(1200);
  ok(await p.isVisible('#fuera') && await p.isHidden('#dentro') && !(await p.evaluate(() => localStorage.getItem('ca_sesion'))), 'sesión vencida: el inicio vuelve a «Iniciar sesión»');
  await p.screenshot({ path: D + 'ses_inicio_fuera.png' });
  await ctx.close();

  await b.close();
  console.log(fallas ? fallas + ' FALLAS' : 'TODO BIEN');
  process.exit(fallas ? 1 : 0);
})().catch(e => { console.error('FALLO', e); process.exit(1); });
