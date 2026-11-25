#!/usr/bin/env node
/**
 * Arma mobile/caseta/www para la app «AVBA Caseta».
 *
 * La app abre el control de accesos del sistema (gestion.avba.com.mx/
 * accesos.html, ver capacitor.config.json → server.url): el guardia entra con
 * su cuenta de caseta y cada entrada o salida queda en el sistema. La app sólo
 * agrega el lector de tarjetas (LectorTarjeta, nativo). Aquí van únicamente las
 * páginas locales: la de «sin conexión» (server.errorPath) y un index de
 * respaldo.
 *
 * Se corre antes de cada `cap sync` (npm run build). No toca el sitio web.
 */
import { cpSync, mkdirSync, readFileSync, writeFileSync, rmSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const APP  = join(dirname(fileURLToPath(import.meta.url)), '..');
const RAIZ = join(APP, '..', '..');
const WWW  = join(APP, 'www');
const config = JSON.parse(readFileSync(join(APP, 'capacitor.config.json'), 'utf8'));
const SERVIDOR = String(config.server && config.server.url || 'https://gestion.avba.com.mx').replace(/\/+$/, '');
const INICIO = SERVIDOR + ((config.server && config.server.appStartPath) || '/accesos.html');

rmSync(WWW, { recursive: true, force: true });
mkdirSync(join(WWW, 'assets', 'logos'), { recursive: true });
cpSync(join(RAIZ, 'assets', 'logos', 'avba.png'), join(WWW, 'assets', 'logos', 'avba.png'));

const pagina = (titulo, cuerpo) => `<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>${titulo}</title>
<style>
  html,body{height:100%;margin:0}
  body{background:linear-gradient(160deg,#0B2545,#134074);color:#fff;font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;
    display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:24px;box-sizing:border-box}
  img{width:120px;background:#fff;border-radius:16px;padding:10px 14px}
  h1{font-size:20px;margin:22px 0 8px}
  p{font-size:15px;opacity:.88;max-width:320px;line-height:1.45;margin:0}
  a{display:inline-block;margin-top:24px;background:#fff;color:#0B2545;font-weight:800;text-decoration:none;border-radius:12px;padding:14px 26px;font-size:16px}
</style></head>
<body>${cuerpo}</body></html>
`;

// server.errorPath: se muestra si el control de accesos no carga (sin internet al abrir la app)
writeFileSync(join(WWW, 'sin-conexion.html'), pagina('Sin conexión · AVBA Caseta',
  `<img src="assets/logos/avba.png" alt="AVBA"><h1>Sin conexión</h1>
<p>Para abrir el control de accesos se necesita internet. Ya abierto, las entradas y salidas se guardan en el teléfono aunque se vaya la señal.</p>
<a href="${INICIO}">Reintentar</a>`));

// Respaldo: la app abre directo en el servidor (server.url)
writeFileSync(join(WWW, 'index.html'), pagina('AVBA Caseta',
  `<img src="assets/logos/avba.png" alt="AVBA"><h1>AVBA Caseta</h1><p>Abriendo el control de accesos…</p>
<script>location.replace(${JSON.stringify(INICIO)});</script>`));

console.log('www listo · AVBA Caseta abre ' + INICIO);
