#!/usr/bin/env node
/**
 * Paquete de actualización de la app, para publicarlo junto con el sitio.
 *
 *   node mobile/scripts/paquete-servidor.mjs [carpeta-salida]   (por omisión: app-paquete/)
 *
 * Arma las pantallas de la app (build-www.mjs) y deja en la carpeta:
 *   · paquete.zip  — las pantallas, tal como van dentro de la app
 *   · ultima.json  — versión, fecha, huella SHA-256 del zip y la versión
 *                    mínima de la app instalada que lo puede usar
 *   · index.html   — vacío, para que la carpeta no se liste en el navegador
 *
 * Lo corre el despliegue a Hostinger (.github/workflows/deploy-proyecto1.yml).
 * La app consulta la ficha con la acción APP_VERSION y descarga el zip sola.
 * Sólo usa Node y el comando zip: nada que instalar.
 */
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdirSync, readFileSync, writeFileSync, rmSync, renameSync, statSync } from 'node:fs';
import { join, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const MOVIL  = join(dirname(fileURLToPath(import.meta.url)), '..');
const RAIZ   = join(MOVIL, '..');
const WWW    = join(MOVIL, 'www');
const SALIDA = resolve(process.argv[2] || join(RAIZ, 'app-paquete'));

execFileSync(process.execPath, [join(MOVIL, 'scripts', 'build-www.mjs')], { stdio: 'inherit' });
const ficha = JSON.parse(readFileSync(join(WWW, 'version.json'), 'utf8'));

mkdirSync(SALIDA, { recursive: true });
const temporal = join(SALIDA, 'paquete.tmp.zip');
rmSync(temporal, { force: true });
// index.html en la raíz del zip: el plugin de actualización lo exige así
execFileSync('zip', ['-r', '-X', '-q', '-9', temporal, '.'], { cwd: WWW });
const sha256 = createHash('sha256').update(readFileSync(temporal)).digest('hex');
const bytes = statSync(temporal).size;
renameSync(temporal, join(SALIDA, 'paquete.zip'));

writeFileSync(join(SALIDA, 'ultima.json'), JSON.stringify({
  version: ficha.version,
  armado: ficha.armado,
  commit: ficha.commit || '',
  nativoMinimo: ficha.nativoMinimo,
  archivo: 'paquete.zip',
  sha256,
  bytes,
}, null, 2) + '\n');
writeFileSync(join(SALIDA, 'index.html'), '');

console.log(`Paquete de la app: ${SALIDA}/paquete.zip · ${(bytes / 1048576).toFixed(1)} MB · versión ${ficha.version}`);
