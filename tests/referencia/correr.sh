#!/usr/bin/env bash
# ════════════════════════════════════════════════════════════════════
#  Corre todas las pruebas de Control de Accesos en LOCAL:
#    · MariaDB en 127.0.0.1 (base ca_prueba, usuario «prueba»; nunca la del servidor)
#    · servidor de PHP sólo en localhost:8794 sirviendo esta rama
#    · Playwright con el Chromium que ya trae el entorno
#
#  Uso (desde la raíz del repositorio):   bash tests/referencia/correr.sh [filtro]
#  Necesita poder entrar a MariaDB como root por el socket (sudo mysql) para
#  crear las bases de prueba. Si no existe config/config.php, crea uno de
#  prueba (no se sube: está en .gitignore).
# ════════════════════════════════════════════════════════════════════
set -u
cd "$(dirname "$0")/../.."
RAIZ=$(pwd)
T=tests/referencia
FILTRO=${1:-}
MYSQL_ROOT=${MYSQL_ROOT:-"mysql -uroot"}
PUERTO=8794

# ── Bases de prueba ──────────────────────────────────────────────────
$MYSQL_ROOT <<'SQL' || { echo "No se pudo entrar a MariaDB como root"; exit 1; }
CREATE USER IF NOT EXISTS 'prueba'@'127.0.0.1' IDENTIFIED BY 'prueba';
CREATE USER IF NOT EXISTS 'prueba'@'localhost' IDENTIFIED BY 'prueba';
CREATE USER IF NOT EXISTS 'limitado'@'127.0.0.1' IDENTIFIED BY 'limitado';
CREATE DATABASE IF NOT EXISTS ca_limitada CHARACTER SET utf8mb4;
GRANT ALL ON ca_prueba.* TO 'prueba'@'127.0.0.1';
GRANT ALL ON ca_prueba.* TO 'prueba'@'localhost';
GRANT ALL ON ca_limitada.* TO 'prueba'@'127.0.0.1';
-- Base «limitada»: el hosting no deja hacer ALTER (el módulo debe seguir con fotos)
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE ON ca_limitada.* TO 'limitado'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

base_nueva() {
  $MYSQL_ROOT -e "DROP DATABASE IF EXISTS ca_prueba; CREATE DATABASE ca_prueba CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  mysql -uprueba -pprueba -h127.0.0.1 ca_prueba < sql/esquema-inicial.sql
  mysql -uprueba -pprueba -h127.0.0.1 ca_prueba < $T/sembrar-cuentas.sql
}

# ── config/config.php de prueba ──────────────────────────────────────
if [ ! -f config/config.php ]; then
  cat > config/config.php <<'PHP'
<?php
// Configuración LOCAL de prueba (creada por tests/referencia/correr.sh; no se sube)
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'ca_prueba');
define('DB_USER', 'prueba');
define('DB_PASS', 'prueba');
define('CLAVE_INSTALACION', 'clave-local-prueba');
define('TOKEN_TTL', 43200);
define('SITE_URL', 'http://localhost:8794');
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
date_default_timezone_set('America/Mexico_City');
PHP
  echo "config/config.php de prueba creado"
fi
grep -q "'ca_prueba'" config/config.php || { echo "config/config.php no apunta a la base de prueba ca_prueba: no sigo."; exit 1; }

# ── mPDF (para los PDF) ──────────────────────────────────────────────
[ -f vendor/autoload.php ] || composer install --no-interaction --no-progress -q

# ── Servidor de PHP en localhost ─────────────────────────────────────
if ! curl -s -o /dev/null "http://localhost:$PUERTO/index.html"; then
  php -S localhost:$PUERTO -t "$RAIZ" > /tmp/ca-php-$PUERTO.log 2>&1 &
  SERVIDOR=$!
  trap 'kill $SERVIDOR 2>/dev/null' EXIT
  sleep 1
fi

FALLAS=0
correr() {   # base (nueva | sigue), nombre, comando
  [ -n "$FILTRO" ] && [[ "$2" != *"$FILTRO"* ]] && return
  [ "$1" = nueva ] && base_nueva
  echo; echo "━━━ $2"
  if eval "$3"; then echo "→ $2: bien"; else echo "→ $2: FALLÓ"; FALLAS=$((FALLAS + 1)); fi
}

correr nueva php/prueba          "php $T/php/prueba.php"
correr nueva php/en_planta       "php $T/php/en_planta.php"
correr sigue php/nombre_pdf      "php $T/php/nombre_pdf.php"
correr sigue php/limitada        "php $T/php/limitada.php"
correr nueva api/plataforma      "node $T/pantalla/api_plataforma_test.mjs"
correr nueva api/puntos          "node $T/pantalla/api_puntos_test.mjs"
correr nueva pantalla/sesion     "node $T/pantalla/sesion_pantalla_test.js"
correr nueva pantalla/puntos     "node $T/pantalla/puntos_pantalla_test.js"
correr nueva pantalla/pendientes "node $T/pantalla/pendientes_test.js"
correr nueva pantalla/planta     "php $T/php/sembrar_planta.php && node $T/pantalla/planta_pantalla_test.js"
correr nueva pantalla/evidencia  "node $T/pantalla/evidencia_test.js"
correr sigue pantalla/tarjeta    "node $T/pantalla/accesos_tarjeta_test.js"

echo
[ $FALLAS -eq 0 ] && echo "══ TODAS LAS PRUEBAS PASARON ══" || echo "══ $FALLAS PRUEBA(S) FALLARON ══"
exit $FALLAS
