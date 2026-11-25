#!/usr/bin/env node
/**
 * Arma mobile/caseta/www: la pantalla del guardia (demo/caseta.html) dentro de
 * la app «AVBA Caseta». Copia del repositorio sólo lo que esa pantalla usa y le
 * agrega app-config.js, que le dice que está dentro de la app y a qué servidor
 * enviar los registros (la sala que comparte con el panel del administrador).
 *
 * Se corre antes de cada `cap sync` (npm run build). No toca el sitio web.
 */
import { cpSync, mkdirSync, readFileSync, writeFileSync, rmSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';

const APP  = join(dirname(fileURLToPath(import.meta.url)), '..');
const RAIZ = join(APP, '..', '..');
const WWW  = join(APP, 'www');
const SERVIDOR = (process.env.AVBA_SERVIDOR || 'https://gestion.avba.com.mx').replace(/\/+$/, '');

const pkg = JSON.parse(readFileSync(join(APP, 'package.json'), 'utf8'));
let commit = '';
try { commit = execFileSync('git', ['rev-parse', '--short', 'HEAD'], { cwd: RAIZ }).toString().trim(); } catch (e) {}

rmSync(WWW, { recursive: true, force: true });
mkdirSync(join(WWW, 'demo'), { recursive: true });
mkdirSync(join(WWW, 'assets', 'logos'), { recursive: true });

cpSync(join(RAIZ, 'demo', 'demo-datos.js'), join(WWW, 'demo', 'demo-datos.js'));
cpSync(join(RAIZ, 'assets', 'logos', 'avba.png'), join(WWW, 'assets', 'logos', 'avba.png'));

// La pantalla del guardia, con la configuración de la app antes de los datos
const ANTES = '<script src="demo-datos.js"></script>';
let html = readFileSync(join(RAIZ, 'demo', 'caseta.html'), 'utf8');
if (!html.includes(ANTES)) throw new Error('caseta.html ya no carga demo-datos.js como se esperaba');
html = html.replace(ANTES, '<script src="app-config.js"></script>\n' + ANTES);
writeFileSync(join(WWW, 'demo', 'caseta.html'), html);

const config = {
  servidor: SERVIDOR,
  urlSala: SERVIDOR + '/demo/sala.php',
  version: pkg.version,
  armado: new Date().toISOString(),
  commit
};
writeFileSync(join(WWW, 'demo', 'app-config.js'),
  '/* Lo genera mobile/caseta/scripts/build-www.mjs: la pantalla está dentro de la app AVBA Caseta */\n' +
  'window.AVBA_APP = ' + JSON.stringify(config, null, 2) + ';\n');

// La app abre en la pantalla del guardia
writeFileSync(join(WWW, 'index.html'), `<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AVBA Caseta</title><style>html,body{background:#0B2545;margin:0}</style></head>
<body><script>location.replace('demo/caseta.html' + location.search);</script></body></html>
`);

console.log('www listo · AVBA Caseta ' + pkg.version + (commit ? ' (' + commit + ')' : '') + ' · servidor ' + SERVIDOR);
