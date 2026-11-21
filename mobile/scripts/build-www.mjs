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
 * Se corre antes de cada `cap sync` (npm run build). No toca el sitio web.
 */
import { cpSync, mkdirSync, readFileSync, writeFileSync, rmSync, existsSync, readdirSync, statSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const MOVIL = join(dirname(fileURLToPath(import.meta.url)), '..');
const RAIZ  = join(MOVIL, '..');
const WWW   = join(MOVIL, 'www');

/* Pantallas de la app (las de trabajo; no las plantillas de documentos) */
const PANTALLAS = [
  'login.html', 'inspector.html', 'portal-cliente.html', 'calidad.html', 'certificaciones.html',
  'admin.html', 'administrativo.html', 'personal.html', 'presupuestos.html', 'examen.html',
  'examenes.html', 'participante.html', 'pnd.html', 'validar.html', 'privacidad.html',
];
/* Carpetas y archivos de apoyo */
const COPIAR = ['js', 'css', 'assets/logos', 'assets/sellos', 'icon-192.png', 'icon-512.png', 'manifest.json'];

rmSync(WWW, { recursive: true, force: true });
mkdirSync(WWW, { recursive: true });

for (const c of COPIAR) {
  const de = join(RAIZ, c);
  if (!existsSync(de)) { console.warn('  · no existe, se omite:', c); continue; }
  cpSync(de, join(WWW, c), { recursive: true });
}

const ETIQUETA = '<script src="js/app-shell.js"></script>';
let n = 0;
for (const p of PANTALLAS) {
  const de = join(RAIZ, p);
  if (!existsSync(de)) { console.warn('  · no existe, se omite:', p); continue; }
  let html = readFileSync(de, 'utf8');
  if (!html.includes(ETIQUETA)) {
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

/* Versión del paquete, para mostrarla y depurar */
const pkg = JSON.parse(readFileSync(join(MOVIL, 'package.json'), 'utf8'));
writeFileSync(join(WWW, 'version.json'), JSON.stringify({ version: pkg.version, armado: new Date().toISOString() }, null, 2));

let bytes = 0;
(function medir(d) { for (const f of readdirSync(d)) { const r = join(d, f); const s = statSync(r); s.isDirectory() ? medir(r) : bytes += s.size; } })(WWW);
console.log(`mobile/www listo: ${n} pantallas, ${(bytes / 1048576).toFixed(1)} MB`);
