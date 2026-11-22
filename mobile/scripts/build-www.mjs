#!/usr/bin/env node
/**
 * Arma mobile/www: las pantallas del portal, dentro de la app.
 *
 * La app ya no carga el sitio desde internet: lleva sus pantallas, scripts,
 * estilos e imágenes, así abre y trabaja sin señal. Este script copia del
 * repositorio lo que la app necesita y le agrega a cada pantalla, como primer
 * script, js/app-shell.js (la capa que la conecta al servidor y la hace
 * funcionar sin conexión).
 *
 * Se corre antes de cada `cap sync` (npm run build) y en cada despliegue del
 * sitio, para publicar el paquete de actualización (scripts/paquete-servidor.mjs).
 * No toca el sitio web.
 */
import { cpSync, mkdirSync, readFileSync, writeFileSync, rmSync, existsSync, readdirSync, statSync } from 'node:fs';
import { join, dirname, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';

const MOVIL = join(dirname(fileURLToPath(import.meta.url)), '..');
const RAIZ  = join(MOVIL, '..');
const WWW   = join(MOVIL, 'www');

/* Pantallas de la app: TODAS las .html de la raíz del sitio, menos las
   plantillas de documentos que arma el servidor (dictamen_*, certificado_*,
   *_preview) y la portada web (index.html; la app trae la suya). Un módulo
   nuevo entra solo: no hay que anotarlo en ningún lado. */
const NO_SON_PANTALLAS = /^(dictamen_|certificado_)|_preview\.html$|^index\.html$/i;
const PANTALLAS = readdirSync(RAIZ)
  .filter(f => f.toLowerCase().endsWith('.html') && !NO_SON_PANTALLAS.test(f) && statSync(join(RAIZ, f)).isFile())
  .sort();

/* Carpetas y archivos de apoyo. Lo que no venga aquí (por ejemplo, imágenes de
   otras carpetas de assets/) la app lo pide al servidor: ver js/app-shell.js. */
const COPIAR = ['js', 'css', 'assets/logos', 'assets/sellos', 'icon-192.png', 'icon-512.png', 'manifest.json'];

const pkg = JSON.parse(readFileSync(join(MOVIL, 'package.json'), 'utf8'));
const armado = new Date().toISOString();
let commit = '';
try { commit = execFileSync('git', ['rev-parse', '--short', 'HEAD'], { cwd: RAIZ }).toString().trim(); } catch (e) {}
/* Versión del paquete: la de la app + fecha y hora + commit. La app compara
   «armado» para saber si una versión publicada es más nueva que la suya. */
const VERSION = process.env.AVBA_PAQUETE_VERSION
  || `${pkg.version}-${armado.replace(/\D/g, '').slice(0, 12)}${commit ? '-' + commit : ''}`;
const NATIVO_MINIMO = (pkg.avba && pkg.avba.nativoMinimo) || pkg.version;

rmSync(WWW, { recursive: true, force: true });
mkdirSync(WWW, { recursive: true });

for (const c of COPIAR) {
  const de = join(RAIZ, c);
  if (!existsSync(de)) { console.warn('  · no existe, se omite:', c); continue; }
  cpSync(de, join(WWW, c), { recursive: true });
}

/* Antes que cualquier otro script de la pantalla: qué trae el paquete, las
   reglas sin señal de cada módulo y la capa de la app. */
const ETIQUETA = '<script src="js/app-paquete.js"></script>\n  <script src="js/app-sin-senal.js"></script>\n  <script src="js/app-shell.js"></script>';
let n = 0;
for (const p of PANTALLAS) {
  const de = join(RAIZ, p);
  if (!existsSync(de)) { console.warn('  · no existe, se omite:', p); continue; }
  let html = readFileSync(de, 'utf8');
  if (!html.includes('js/app-shell.js')) {
    // Primero que todo: antes de que cualquier otro script haga una petición
    html = /<head[^>]*>/i.test(html)
      ? html.replace(/<head[^>]*>/i, m => m + '\n  ' + ETIQUETA)
      : ETIQUETA + '\n' + html;
  }
  // El service worker es del sitio web; en la app las pantallas ya están dentro
  html = html.replace(/navigator\.serviceWorker\.register\(([^)]*)\)/g, 'Promise.resolve(/* app: sin service worker */)');
  writeFileSync(join(WWW, p), html);
  n++;
}

/* Entrada de la app: decide a qué pantalla ir (con o sin señal) */
cpSync(join(MOVIL, 'src', 'index.html'), join(WWW, 'index.html'));

/* Ficha del paquete: versión y lista de archivos (lo que no esté en la lista
   se pide al servidor). La lee js/app-shell.js antes que nada. */
writeFileSync(join(WWW, 'version.json'), JSON.stringify({ version: VERSION, armado, commit, nativoMinimo: NATIVO_MINIMO }, null, 2));
const archivos = [];
let bytes = 0;
(function listar(d) {
  for (const f of readdirSync(d)) {
    const r = join(d, f); const s = statSync(r);
    if (s.isDirectory()) listar(r); else { archivos.push(relative(WWW, r).split(sep).join('/')); bytes += s.size; }
  }
})(WWW);
archivos.push('js/app-paquete.js');
archivos.sort();
writeFileSync(join(WWW, 'js', 'app-paquete.js'),
  '/* Generado por mobile/scripts/build-www.mjs: no editar. */\n' +
  'window.__AVBA_PAQUETE__ = ' + JSON.stringify({ version: VERSION, armado, nativoMinimo: NATIVO_MINIMO, archivos }) + ';\n');

console.log(`mobile/www listo: ${n} pantallas, ${archivos.length} archivos, ${(bytes / 1048576).toFixed(1)} MB · versión ${VERSION}`);
