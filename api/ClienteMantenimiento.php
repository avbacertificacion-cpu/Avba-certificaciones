<?php
/**
 * AVBA Certificaciones — Solicitudes de Material y Reportes de Mantenimiento
 *
 * Flujo:
 *   1. El mecánico (sub-usuario con permiso_mantenimiento) crea una SOLICITUD
 *      de material para un equipo (de "Mis Equipos" o certificado por AVBA),
 *      con uno o más ítems (del catálogo o libres).
 *   2. La cuenta principal asigna cada ítem a un proveedor y genera los ENVÍOS:
 *      un PDF por proveedor (sin logos AVBA ni precios) enviado por correo
 *      desde el correo configurado por el cliente.
 *   3. El mecánico crea el REPORTE DE MANTENIMIENTO (trabajo + material usado +
 *      fotos antes/después + liga opcional a 1..N solicitudes).
 *
 * Equipo polimórfico: origen 'cliente' (cliente_equipos) o 'avba' (equipos).
 */
class ClienteMantenimiento {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->migrate();
    }

    private function migrate(): void {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS cliente_sol_material (
                  id            INT AUTO_INCREMENT PRIMARY KEY,
                  id_cliente    VARCHAR(20) NOT NULL,
                  folio         VARCHAR(40) NOT NULL,
                  equipo_origen VARCHAR(10) NOT NULL DEFAULT 'cliente',
                  equipo_ref_id INT NOT NULL DEFAULT 0,
                  equipo_nombre VARCHAR(250) NULL,
                  mecanico_id   INT NULL,
                  mecanico_nombre VARCHAR(200) NULL,
                  fecha_requerida DATE NULL,
                  estado        VARCHAR(20) NOT NULL DEFAULT 'abierta',
                  notas         TEXT NULL,
                  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  KEY idx_cli (id_cliente)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS cliente_sol_material_item (
                  id           INT AUTO_INCREMENT PRIMARY KEY,
                  sol_id       INT NOT NULL,
                  material_id  INT NULL,
                  descripcion  VARCHAR(300) NOT NULL,
                  cantidad     DECIMAL(10,2) NOT NULL DEFAULT 1,
                  unidad       VARCHAR(40) NULL,
                  num_parte    VARCHAR(80) NULL,
                  proveedor_id INT NULL,
                  envio_id     INT NULL,
                  KEY idx_sol (sol_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS cliente_sol_envio (
                  id           INT AUTO_INCREMENT PRIMARY KEY,
                  id_cliente   VARCHAR(20) NOT NULL,
                  sol_id       INT NOT NULL,
                  proveedor_id INT NOT NULL,
                  folio        VARCHAR(40) NOT NULL,
                  pdf_url      VARCHAR(500) NULL,
                  email_to     VARCHAR(200) NULL,
                  email_enviado_at TIMESTAMP NULL,
                  estado       VARCHAR(20) NOT NULL DEFAULT 'generado',
                  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  KEY idx_sol (sol_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS cliente_mantenimiento (
                  id            INT AUTO_INCREMENT PRIMARY KEY,
                  id_cliente    VARCHAR(20) NOT NULL,
                  folio         VARCHAR(40) NOT NULL,
                  equipo_origen VARCHAR(10) NOT NULL DEFAULT 'cliente',
                  equipo_ref_id INT NOT NULL DEFAULT 0,
                  equipo_nombre VARCHAR(250) NULL,
                  mecanico_id   INT NULL,
                  mecanico_nombre VARCHAR(200) NULL,
                  fecha         DATE NULL,
                  tipo_mant     VARCHAR(40) NULL,
                  trabajo       TEXT NULL,
                  material_usado TEXT NULL,
                  observaciones TEXT NULL,
                  pdf_url       VARCHAR(500) NULL,
                  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  KEY idx_cli (id_cliente)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS cliente_mantenimiento_foto (
                  id         INT AUTO_INCREMENT PRIMARY KEY,
                  mant_id    INT NOT NULL,
                  etapa      VARCHAR(10) NOT NULL DEFAULT 'antes',
                  archivo_url VARCHAR(500) NOT NULL,
                  comentario VARCHAR(250) NULL,
                  KEY idx_m (mant_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS cliente_mantenimiento_sol (
                  mant_id INT NOT NULL,
                  sol_id  INT NOT NULL,
                  PRIMARY KEY (mant_id, sol_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\PDOException $e) {
            error_log('[ClienteMantenimiento] migrate: ' . $e->getMessage());
        }
    }

    private function norm(string $id): string {
        $id = trim($id);
        return (ctype_digit($id)) ? str_pad($id, 5, '0', STR_PAD_LEFT) : $id;
    }

    /** Genera el siguiente folio secuencial por cliente y tipo. */
    private function siguienteFolio(string $idCliente, string $prefijo, string $tabla): string {
        try {
            $s = $this->pdo->prepare("SELECT COUNT(*) FROM $tabla WHERE id_cliente = ?");
            $s->execute([$idCliente]);
            $n = (int)$s->fetchColumn() + 1;
        } catch (\PDOException $e) { $n = 1; }
        return sprintf('%s-%s-%04d', $prefijo, $idCliente, $n);
    }

    /** Resuelve los datos de un equipo según origen ('cliente' | 'avba'). */
    private function resolverEquipo(string $origen, int $refId, string $idCliente): ?array {
        if ($origen === 'avba') {
            $s = $this->pdo->prepare("SELECT id, maquinaria AS nombre, marca, modelo, serie, capacidad, control
                                      FROM equipos WHERE id = ? AND control LIKE ? LIMIT 1");
            $s->execute([$refId, $idCliente . '-%']);
        } else {
            $s = $this->pdo->prepare("SELECT id, nombre, marca, modelo, serie, capacidad
                                      FROM cliente_equipos WHERE id = ? AND id_cliente = ? LIMIT 1");
            $s->execute([$refId, $idCliente]);
        }
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function clienteCfg(string $idCliente): array {
        try {
            $s = $this->pdo->prepare("SELECT * FROM cliente_config WHERE id_cliente = ?");
            $s->execute([$idCliente]);
            return $s->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException $e) { return []; }
    }

    private function nombreCliente(string $idCliente): string {
        $cfg = $this->clienteCfg($idCliente);
        if (!empty($cfg['razon_social'])) return $cfg['razon_social'];
        try {
            $s = $this->pdo->prepare("SELECT nombre_cliente FROM clientes WHERE primera_parte = ? LIMIT 1");
            $s->execute([$idCliente]);
            $n = $s->fetchColumn();
            if ($n) return $n;
        } catch (\PDOException $e) {}
        return 'Cliente ' . $idCliente;
    }

    // ════════════════════════════════════════════════════════
    //  SOLICITUDES DE MATERIAL
    // ════════════════════════════════════════════════════════

    public function listarSolicitudes(string $idCliente, ?int $mecanicoId = null): array {
        $idCliente = $this->norm($idCliente);
        $sql = "SELECT s.id, s.folio, s.equipo_origen, s.equipo_ref_id, s.equipo_nombre,
                       s.mecanico_nombre, DATE_FORMAT(s.fecha_requerida,'%d/%m/%Y') AS fecha_requerida,
                       s.estado, s.notas, DATE_FORMAT(s.created_at,'%d/%m/%Y') AS fecha,
                       (SELECT COUNT(*) FROM cliente_sol_material_item i WHERE i.sol_id=s.id) AS n_items,
                       (SELECT COUNT(*) FROM cliente_sol_envio e WHERE e.sol_id=s.id) AS n_envios
                FROM cliente_sol_material s WHERE s.id_cliente = ?";
        $params = [$idCliente];
        if ($mecanicoId !== null) { $sql .= " AND s.mecanico_id = ?"; $params[] = $mecanicoId; }
        $sql .= " ORDER BY s.created_at DESC";
        $s = $this->pdo->prepare($sql);
        $s->execute($params);
        return ['status' => 'success', 'solicitudes' => $s->fetchAll(PDO::FETCH_ASSOC)];
    }

    public function crearSolicitud(array $data, string $idCliente, array $usr): array {
        $idCliente = $this->norm($idCliente);
        if (!$idCliente) return ['status' => 'error', 'message' => 'id_cliente requerido.'];

        $origen = ($data['equipo_origen'] ?? 'cliente') === 'avba' ? 'avba' : 'cliente';
        $refId  = (int)($data['equipo_ref_id'] ?? 0);
        $eq = $this->resolverEquipo($origen, $refId, $idCliente);
        if (!$eq) return ['status' => 'error', 'message' => 'Selecciona un equipo válido.'];

        $items = $data['items'] ?? [];
        if (is_string($items)) { $items = json_decode($items, true) ?: []; }
        $items = array_values(array_filter($items, fn($it) => trim($it['descripcion'] ?? '') !== ''));
        if (!$items) return ['status' => 'error', 'message' => 'Agrega al menos un ítem.'];

        $fechaReq = trim($data['fecha_requerida'] ?? '');
        $fechaReqDb = $fechaReq ? date('Y-m-d', strtotime(str_replace('/', '-', $fechaReq))) : null;
        $notas = trim($data['notas'] ?? '');
        $eqNombre = trim(($eq['nombre'] ?? '') . ' ' . (!empty($eq['serie']) ? '· S/N ' . $eq['serie'] : ''));
        $folio = $this->siguienteFolio($idCliente, 'SM', 'cliente_sol_material');

        $this->pdo->prepare(
            "INSERT INTO cliente_sol_material (id_cliente,folio,equipo_origen,equipo_ref_id,equipo_nombre,mecanico_id,mecanico_nombre,fecha_requerida,estado,notas)
             VALUES (?,?,?,?,?,?,?,?, 'abierta', ?)"
        )->execute([$idCliente, $folio, $origen, $refId, $eqNombre, (int)($usr['id'] ?? 0), $usr['nombre'] ?? '', $fechaReqDb, $notas]);
        $solId = (int)$this->pdo->lastInsertId();

        $ins = $this->pdo->prepare(
            "INSERT INTO cliente_sol_material_item (sol_id,material_id,descripcion,cantidad,unidad,num_parte)
             VALUES (?,?,?,?,?,?)"
        );
        foreach ($items as $it) {
            $ins->execute([
                $solId,
                !empty($it['material_id']) ? (int)$it['material_id'] : null,
                trim($it['descripcion']),
                (float)($it['cantidad'] ?? 1) ?: 1,
                trim($it['unidad'] ?? ''),
                trim($it['num_parte'] ?? ''),
            ]);
        }
        return ['status' => 'success', 'id' => $solId, 'folio' => $folio];
    }

    public function detalleSolicitud(int $solId, string $idCliente, ?int $mecanicoId = null): array {
        $idCliente = $this->norm($idCliente);
        $sql = "SELECT * FROM cliente_sol_material WHERE id=? AND id_cliente=?";
        $params = [$solId, $idCliente];
        if ($mecanicoId !== null) { $sql .= " AND mecanico_id=?"; $params[] = $mecanicoId; }
        $s = $this->pdo->prepare($sql);
        $s->execute($params);
        $sol = $s->fetch(PDO::FETCH_ASSOC);
        if (!$sol) return ['status' => 'error', 'message' => 'Solicitud no encontrada.'];

        $it = $this->pdo->prepare("SELECT i.*, p.nombre AS proveedor_nombre
                                   FROM cliente_sol_material_item i
                                   LEFT JOIN cliente_proveedores p ON p.id = i.proveedor_id AND p.id_cliente = ?
                                   WHERE i.sol_id = ?");
        $it->execute([$idCliente, $solId]);
        $sol['items'] = $it->fetchAll(PDO::FETCH_ASSOC);

        $en = $this->pdo->prepare("SELECT e.*, p.nombre AS proveedor_nombre
                                   FROM cliente_sol_envio e
                                   LEFT JOIN cliente_proveedores p ON p.id = e.proveedor_id AND p.id_cliente = ?
                                   WHERE e.sol_id = ? ORDER BY e.id");
        $en->execute([$idCliente, $solId]);
        $envios = $en->fetchAll(PDO::FETCH_ASSOC);
        foreach ($envios as &$ev) {
            $ev['pdf_url'] = $ev['pdf_url'] ? (rtrim(SITE_URL, '/') . '/' . ltrim($ev['pdf_url'], '/')) : '';
        }
        $sol['envios'] = $envios;
        return ['status' => 'success', 'solicitud' => $sol];
    }

    /** Asigna proveedor a cada ítem. $asignaciones = [ item_id => proveedor_id, ... ] */
    public function asignarProveedores(int $solId, array $asignaciones, string $idCliente): array {
        $idCliente = $this->norm($idCliente);
        $chk = $this->pdo->prepare("SELECT id FROM cliente_sol_material WHERE id=? AND id_cliente=?");
        $chk->execute([$solId, $idCliente]);
        if (!$chk->fetch()) return ['status' => 'error', 'message' => 'Solicitud no encontrada.'];

        $chkProv = $this->pdo->prepare("SELECT id FROM cliente_proveedores WHERE id=? AND id_cliente=?");
        $upd = $this->pdo->prepare("UPDATE cliente_sol_material_item SET proveedor_id=? WHERE id=? AND sol_id=? AND envio_id IS NULL");
        foreach ($asignaciones as $itemId => $provId) {
            $provId = $provId ? (int)$provId : null;
            if ($provId !== null) {
                $chkProv->execute([$provId, $idCliente]);
                if (!$chkProv->fetch()) continue; // proveedor no pertenece a este cliente — se ignora
            }
            $upd->execute([$provId, (int)$itemId, $solId]);
        }
        return ['status' => 'success'];
    }

    /**
     * Genera un PDF por proveedor (con los ítems asignados aún sin enviar) y
     * envía el correo desde el correo configurado del cliente. Adjunta el PDF.
     */
    public function generarEnvios(int $solId, string $idCliente): array {
        $idCliente = $this->norm($idCliente);
        $s = $this->pdo->prepare("SELECT * FROM cliente_sol_material WHERE id=? AND id_cliente=?");
        $s->execute([$solId, $idCliente]);
        $sol = $s->fetch(PDO::FETCH_ASSOC);
        if (!$sol) return ['status' => 'error', 'message' => 'Solicitud no encontrada.'];

        // Ítems asignados y aún no enviados, agrupados por proveedor
        $it = $this->pdo->prepare("SELECT * FROM cliente_sol_material_item WHERE sol_id=? AND proveedor_id IS NOT NULL AND envio_id IS NULL");
        $it->execute([$solId]);
        $items = $it->fetchAll(PDO::FETCH_ASSOC);
        if (!$items) return ['status' => 'error', 'message' => 'Asigna un proveedor a los ítems antes de generar los envíos.'];

        $porProv = [];
        foreach ($items as $i) { $porProv[(int)$i['proveedor_id']][] = $i; }

        $resultados = [];
        foreach ($porProv as $provId => $provItems) {
            $prov = $this->pdo->prepare("SELECT * FROM cliente_proveedores WHERE id=? AND id_cliente=?");
            $prov->execute([$provId, $idCliente]);
            $proveedor = $prov->fetch(PDO::FETCH_ASSOC);
            if (!$proveedor) continue;

            $folioEnvio = $this->siguienteFolio($idCliente, 'SE', 'cliente_sol_envio');
            $this->pdo->prepare(
                "INSERT INTO cliente_sol_envio (id_cliente,sol_id,proveedor_id,folio,email_to,estado)
                 VALUES (?,?,?,?,?, 'generado')"
            )->execute([$idCliente, $solId, $provId, $folioEnvio, $proveedor['correo'] ?? '']);
            $envioId = (int)$this->pdo->lastInsertId();

            // PDF
            $pdfUrl = $this->pdfSolicitudProveedor($sol, $proveedor, $provItems, $folioEnvio, $idCliente);
            $this->pdo->prepare("UPDATE cliente_sol_envio SET pdf_url=? WHERE id=?")->execute([$pdfUrl, $envioId]);

            // Marcar ítems como enviados
            $ids = array_map(fn($x) => (int)$x['id'], $provItems);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $this->pdo->prepare("UPDATE cliente_sol_material_item SET envio_id=? WHERE id IN ($in)")
                      ->execute(array_merge([$envioId], $ids));

            // Correo
            $envRes = $this->enviarEnvio($envioId, $idCliente, $sol, $proveedor, $pdfUrl, $folioEnvio);
            $resultados[] = ['proveedor' => $proveedor['nombre'], 'folio' => $folioEnvio, 'correo' => $envRes];
        }

        // Estado de la solicitud
        $pend = $this->pdo->prepare("SELECT COUNT(*) FROM cliente_sol_material_item WHERE sol_id=? AND envio_id IS NULL");
        $pend->execute([$solId]);
        $estado = ((int)$pend->fetchColumn() === 0) ? 'enviada' : 'parcial';
        $this->pdo->prepare("UPDATE cliente_sol_material SET estado=? WHERE id=?")->execute([$estado, $solId]);

        return ['status' => 'success', 'envios' => $resultados, 'estado' => $estado];
    }

    private function enviarEnvio(int $envioId, string $idCliente, array $sol, array $proveedor, string $pdfUrl, string $folio): string {
        $to = trim($proveedor['correo'] ?? '');
        if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) return 'sin-correo';
        if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) return 'sin-mailer';

        $cc = $this->clienteCfg($idCliente);
        $nombreCli = $this->nombreCliente($idCliente);
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            configurarMailerCliente($mail, $this->pdo, $cc);
            $mail->addAddress($to, $proveedor['nombre'] ?? '');
            $mail->isHTML(true);
            $mail->Subject = "Solicitud de material {$folio} — {$nombreCli}";
            $mail->Body = "<p>Estimado proveedor <strong>" . htmlspecialchars($proveedor['nombre'] ?? '') . "</strong>,</p>"
                . "<p>Adjuntamos la solicitud de material <strong>{$folio}</strong> de <strong>" . htmlspecialchars($nombreCli) . "</strong>. "
                . "Agradeceremos su cotización y disponibilidad.</p>"
                . "<p style='font-size:12px;color:#888'>Documento emitido a través del sistema de AVBA Inspección · www.avba.com.mx</p>";
            $abs = rtrim(UPLOAD_DIR, '/') . '/' . ltrim(str_replace(UPLOAD_URL, '', $pdfUrl), '/');
            if (is_file($abs)) $mail->addAttachment($abs, "Solicitud_{$folio}.pdf");
            $mail->send();
            $this->pdo->prepare("UPDATE cliente_sol_envio SET estado='enviado', email_enviado_at=NOW() WHERE id=?")->execute([$envioId]);
            return 'enviado';
        } catch (\Throwable $e) {
            error_log('[Mantenimiento] enviarEnvio: ' . $e->getMessage());
            return 'error: ' . $e->getMessage();
        }
    }

    public function eliminarSolicitud(int $solId, string $idCliente): array {
        $idCliente = $this->norm($idCliente);
        $chk = $this->pdo->prepare("SELECT id FROM cliente_sol_material WHERE id=? AND id_cliente=?");
        $chk->execute([$solId, $idCliente]);
        if (!$chk->fetch()) return ['status' => 'error', 'message' => 'Solicitud no encontrada.'];
        $this->pdo->prepare("DELETE FROM cliente_sol_material_item WHERE sol_id=?")->execute([$solId]);
        $this->pdo->prepare("DELETE FROM cliente_sol_envio WHERE sol_id=?")->execute([$solId]);
        $this->pdo->prepare("DELETE FROM cliente_sol_material WHERE id=?")->execute([$solId]);
        return ['status' => 'success'];
    }

    // ════════════════════════════════════════════════════════
    //  REPORTES DE MANTENIMIENTO
    // ════════════════════════════════════════════════════════

    public function listarMantenimientos(string $idCliente, ?int $mecanicoId = null): array {
        $idCliente = $this->norm($idCliente);
        $sql = "SELECT m.id, m.folio, m.equipo_nombre, m.mecanico_nombre, m.tipo_mant,
                       DATE_FORMAT(m.fecha,'%d/%m/%Y') AS fecha, m.pdf_url,
                       (SELECT COUNT(*) FROM cliente_mantenimiento_foto f WHERE f.mant_id=m.id) AS n_fotos
                FROM cliente_mantenimiento m WHERE m.id_cliente=?";
        $params = [$idCliente];
        if ($mecanicoId !== null) { $sql .= " AND m.mecanico_id=?"; $params[] = $mecanicoId; }
        $sql .= " ORDER BY m.created_at DESC";
        $s = $this->pdo->prepare($sql);
        $s->execute($params);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) { $r['pdf_url'] = $r['pdf_url'] ? (rtrim(SITE_URL,'/').'/'.ltrim($r['pdf_url'],'/')) : ''; }
        return ['status' => 'success', 'mantenimientos' => $rows];
    }

    public function crearMantenimiento(array $data, array $files, string $idCliente, array $usr): array {
        $idCliente = $this->norm($idCliente);
        if (!$idCliente) return ['status' => 'error', 'message' => 'id_cliente requerido.'];

        $origen = ($data['equipo_origen'] ?? 'cliente') === 'avba' ? 'avba' : 'cliente';
        $refId  = (int)($data['equipo_ref_id'] ?? 0);
        $eq = $this->resolverEquipo($origen, $refId, $idCliente);
        if (!$eq) return ['status' => 'error', 'message' => 'Selecciona un equipo válido.'];

        $trabajo = trim($data['trabajo'] ?? '');
        if (!$trabajo) return ['status' => 'error', 'message' => 'Describe el trabajo realizado.'];

        $eqNombre = trim(($eq['nombre'] ?? '') . ' ' . (!empty($eq['serie']) ? '· S/N ' . $eq['serie'] : ''));
        $fecha = trim($data['fecha'] ?? '');
        $fechaDb = $fecha ? date('Y-m-d', strtotime(str_replace('/', '-', $fecha))) : date('Y-m-d');
        $folio = $this->siguienteFolio($idCliente, 'OM', 'cliente_mantenimiento');

        $this->pdo->prepare(
            "INSERT INTO cliente_mantenimiento (id_cliente,folio,equipo_origen,equipo_ref_id,equipo_nombre,mecanico_id,mecanico_nombre,fecha,tipo_mant,trabajo,material_usado,observaciones)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
        )->execute([
            $idCliente, $folio, $origen, $refId, $eqNombre, (int)($usr['id'] ?? 0), $usr['nombre'] ?? '',
            $fechaDb, trim($data['tipo_mant'] ?? ''), $trabajo,
            trim($data['material_usado'] ?? ''), trim($data['observaciones'] ?? ''),
        ]);
        $mantId = (int)$this->pdo->lastInsertId();

        // Ligar solicitudes
        $sols = $data['solicitudes'] ?? [];
        if (is_string($sols)) $sols = json_decode($sols, true) ?: [];
        $lnk = $this->pdo->prepare("INSERT IGNORE INTO cliente_mantenimiento_sol (mant_id,sol_id) VALUES (?,?)");
        foreach ((array)$sols as $sid) { if ((int)$sid) $lnk->execute([$mantId, (int)$sid]); }

        // Fotos (antes/después), comprimidas
        $this->guardarFotos($files, $mantId, $idCliente);

        // Generar PDF
        $pdfUrl = $this->pdfMantenimiento($mantId, $idCliente);
        if ($pdfUrl) $this->pdo->prepare("UPDATE cliente_mantenimiento SET pdf_url=? WHERE id=?")->execute([$pdfUrl, $mantId]);

        return ['status' => 'success', 'id' => $mantId, 'folio' => $folio,
                'pdf_url' => $pdfUrl ? (rtrim(SITE_URL,'/').'/'.ltrim($pdfUrl,'/')) : ''];
    }

    private function guardarFotos(array $files, int $mantId, string $idCliente): void {
        if (empty($files['fotos']) || empty($files['fotos']['tmp_name'])) return;
        $dir = rtrim(UPLOAD_DIR, '/') . "/clientes/$idCliente/mantenimiento/$mantId/";
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $etapas = $_POST['fotos_etapa'] ?? [];
        $comentarios = $_POST['fotos_comentario'] ?? [];
        $names = (array)$files['fotos']['name'];
        $ins = $this->pdo->prepare("INSERT INTO cliente_mantenimiento_foto (mant_id,etapa,archivo_url,comentario) VALUES (?,?,?,?)");
        $count = 0;
        foreach ($names as $i => $orig) {
            if ($count >= 9) break; // máximo 9 fotos
            $tmp = $files['fotos']['tmp_name'][$i] ?? '';
            if (!$tmp || ($files['fotos']['error'][$i] ?? 1) !== 0) continue;
            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION)) ?: 'jpg';
            if (!in_array($ext, ['jpg','jpeg','png','webp'])) continue;
            $fn = 'foto_' . ($i + 1) . '_' . date('His') . '.jpg';
            $dest = $dir . $fn;
            if (!comprimirImagen($tmp, $dest, 1280, 1280, 72)) {
                $dest = $dir . 'foto_' . ($i + 1) . '_' . date('His') . ".$ext";
                if (!move_uploaded_file($tmp, $dest)) continue;
                $fn = basename($dest);
            }
            $etapa = (($etapas[$i] ?? 'antes') === 'despues') ? 'despues' : 'antes';
            $ins->execute([$mantId, $etapa, "uploads/clientes/$idCliente/mantenimiento/$mantId/$fn", trim($comentarios[$i] ?? '')]);
            $count++;
        }
    }

    public function detalleMantenimiento(int $mantId, string $idCliente, ?int $mecanicoId = null): array {
        $idCliente = $this->norm($idCliente);
        $sql = "SELECT * FROM cliente_mantenimiento WHERE id=? AND id_cliente=?";
        $params = [$mantId, $idCliente];
        if ($mecanicoId !== null) { $sql .= " AND mecanico_id=?"; $params[] = $mecanicoId; }
        $s = $this->pdo->prepare($sql);
        $s->execute($params);
        $m = $s->fetch(PDO::FETCH_ASSOC);
        if (!$m) return ['status' => 'error', 'message' => 'Reporte no encontrado.'];
        $f = $this->pdo->prepare("SELECT id, etapa, archivo_url, comentario FROM cliente_mantenimiento_foto WHERE mant_id=?");
        $f->execute([$mantId]);
        $fotos = $f->fetchAll(PDO::FETCH_ASSOC);
        foreach ($fotos as &$ft) { $ft['archivo_url'] = rtrim(SITE_URL,'/').'/'.ltrim($ft['archivo_url'],'/'); }
        $m['fotos'] = $fotos;
        $m['pdf_url'] = $m['pdf_url'] ? (rtrim(SITE_URL,'/').'/'.ltrim($m['pdf_url'],'/')) : '';
        return ['status' => 'success', 'mantenimiento' => $m];
    }

    public function eliminarMantenimiento(int $mantId, string $idCliente): array {
        $idCliente = $this->norm($idCliente);
        $chk = $this->pdo->prepare("SELECT id FROM cliente_mantenimiento WHERE id=? AND id_cliente=?");
        $chk->execute([$mantId, $idCliente]);
        if (!$chk->fetch()) return ['status' => 'error', 'message' => 'Reporte no encontrado.'];
        $dir = rtrim(UPLOAD_DIR, '/') . "/clientes/$idCliente/mantenimiento/$mantId/";
        if (is_dir($dir)) { foreach (glob($dir . '*') as $f) @unlink($f); @rmdir($dir); }
        $this->pdo->prepare("DELETE FROM cliente_mantenimiento_foto WHERE mant_id=?")->execute([$mantId]);
        $this->pdo->prepare("DELETE FROM cliente_mantenimiento_sol WHERE mant_id=?")->execute([$mantId]);
        $this->pdo->prepare("DELETE FROM cliente_mantenimiento WHERE id=?")->execute([$mantId]);
        return ['status' => 'success'];
    }

    // ════════════════════════════════════════════════════════
    //  PDFs
    // ════════════════════════════════════════════════════════

    private function logoB64(array $cfg): string {
        $rel = $cfg['logo_url'] ?? '';
        if (!$rel) return '';
        $abs = rtrim(UPLOAD_DIR, '/') . '/' . ltrim(str_replace(UPLOAD_URL, '', $rel), '/');
        if (!is_file($abs)) return '';
        $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        $mime = in_array($ext, ['jpg','jpeg']) ? 'image/jpeg' : ($ext === 'webp' ? 'image/webp' : 'image/png');
        return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($abs));
    }

    private function renderPdf(string $html, string $subdir, string $nombre): string {
        if (!class_exists('\\Mpdf\\Mpdf')) {
            $autoload = __DIR__ . '/../vendor/autoload.php';
            if (file_exists($autoload)) require_once $autoload;
        }
        if (!class_exists('\\Mpdf\\Mpdf')) throw new \RuntimeException('mPDF no disponible.');
        $dir = rtrim(UPLOAD_DIR, '/') . '/' . trim($subdir, '/') . '/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8', 'format' => 'Letter',
            'margin_left' => 12, 'margin_right' => 12, 'margin_top' => 12, 'margin_bottom' => 14,
            'tempDir' => sys_get_temp_dir() . '/mpdf',
        ]);
        $css = ''; $body = $html;
        if (preg_match('/<style[^>]*>(.*?)<\/style>/is', $html, $m)) $css = $m[1];
        if (preg_match('/<body[^>]*>(.*)<\/body>/is', $html, $mb)) $body = $mb[1];
        if ($css !== '') $mpdf->WriteHTML($css, \Mpdf\HTMLParserMode::HEADER_CSS);
        $mpdf->WriteHTML($body, \Mpdf\HTMLParserMode::HTML_BODY);
        $mpdf->Output($dir . $nombre, 'F');
        return rtrim(UPLOAD_URL, '/') . '/' . trim($subdir, '/') . '/' . $nombre;
    }

    /** Encabezado con membrete del cliente (logo + razón social + RFC). */
    private function membrete(array $cfg, string $idCliente): string {
        $esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $logo = $this->logoB64($cfg);
        $nombre = $esc($this->nombreCliente($idCliente));
        $rfc = !empty($cfg['rfc']) ? '<div style="font-size:9pt;color:#555">RFC: ' . $esc($cfg['rfc']) . '</div>' : '';
        $logoHtml = $logo ? '<img src="' . $logo . '" style="max-height:54px;max-width:170px">' : '';
        return '<table style="width:100%;border-collapse:collapse;border-bottom:2px solid #0d2244;margin-bottom:12px">
            <tr>
              <td style="vertical-align:middle">' . $logoHtml . '</td>
              <td style="text-align:right;vertical-align:middle">
                <div style="font-size:13pt;font-weight:bold;color:#0d2244">' . $nombre . '</div>' . $rfc . '
              </td>
            </tr></table>';
    }

    private function pieAvba(): string {
        return '<div style="position:fixed;bottom:4mm;left:0;right:0;text-align:center;font-size:7.5pt;color:#999">
            Documento emitido a través del sistema de AVBA Inspección &nbsp;·&nbsp; www.avba.com.mx</div>';
    }

    /** PDF de solicitud al proveedor: sin logos AVBA, sin precios. */
    private function pdfSolicitudProveedor(array $sol, array $prov, array $items, string $folio, string $idCliente): string {
        $esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $cfg = $this->clienteCfg($idCliente);
        $fechaReq = $sol['fecha_requerida'] ? date('d/m/Y', strtotime($sol['fecha_requerida'])) : '—';

        $filas = '';
        $n = 1;
        foreach ($items as $i) {
            $filas .= '<tr>
                <td style="text-align:center">' . $n++ . '</td>
                <td>' . $esc($i['descripcion']) . '</td>
                <td style="text-align:center">' . $esc($i['num_parte'] ?: '—') . '</td>
                <td style="text-align:center">' . rtrim(rtrim(number_format((float)$i['cantidad'], 2), '0'), '.') . '</td>
                <td style="text-align:center">' . $esc($i['unidad'] ?: '—') . '</td>
              </tr>';
        }

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
            body{font-family:DejaVu Sans,Arial,sans-serif;font-size:10pt;color:#1a2233}
            h1{font-size:15pt;color:#0d2244;margin:0 0 2px}
            .meta{font-size:9.5pt;color:#444;margin-bottom:10px}
            .box{border:1px solid #cdd5e6;border-radius:6px;padding:8px 12px;margin-bottom:12px;background:#f8fafc}
            table.items{width:100%;border-collapse:collapse;margin-top:6px;font-size:9.5pt}
            table.items th{background:#0d2244;color:#fff;padding:6px 8px;text-align:left}
            table.items td{border-bottom:1px solid #e3e8f0;padding:6px 8px}
            </style></head><body>'
            . $this->membrete($cfg, $idCliente)
            . '<h1>Solicitud de Material</h1>'
            . '<div class="meta">Folio: <strong>' . $esc($folio) . '</strong> &nbsp;·&nbsp; Fecha: ' . date('d/m/Y')
            . ' &nbsp;·&nbsp; Requerido para: <strong>' . $fechaReq . '</strong></div>'
            . '<div class="box"><strong>Proveedor:</strong> ' . $esc($prov['nombre'])
            . (!empty($prov['contacto']) ? ' &nbsp;·&nbsp; Att: ' . $esc($prov['contacto']) : '')
            . (!empty($prov['correo']) ? '<br>Correo: ' . $esc($prov['correo']) : '')
            . '</div>'
            . '<div style="font-size:9.5pt;margin-bottom:4px"><strong>Equipo:</strong> ' . $esc($sol['equipo_nombre']) . '</div>'
            . '<table class="items"><thead><tr><th style="width:6%">#</th><th>Descripción</th><th style="width:18%">Núm. parte</th><th style="width:12%">Cant.</th><th style="width:14%">Unidad</th></tr></thead><tbody>'
            . $filas . '</tbody></table>'
            . ($sol['notas'] ? '<div class="box" style="margin-top:12px"><strong>Notas:</strong> ' . $esc($sol['notas']) . '</div>' : '')
            . '<p style="margin-top:18px;font-size:9.5pt">Agradeceremos su cotización y disponibilidad. Quedamos atentos.</p>'
            . $this->pieAvba()
            . '</body></html>';

        $nombre = "Solicitud_{$folio}.pdf";
        return $this->renderPdf($html, "clientes/$idCliente/solicitudes", $nombre);
    }

    /** PDF de reporte de mantenimiento: membrete del cliente + fotos antes/después. */
    private function pdfMantenimiento(int $mantId, string $idCliente): ?string {
        try {
            $s = $this->pdo->prepare("SELECT * FROM cliente_mantenimiento WHERE id=? AND id_cliente=?");
            $s->execute([$mantId, $idCliente]);
            $m = $s->fetch(PDO::FETCH_ASSOC);
            if (!$m) return null;
            $f = $this->pdo->prepare("SELECT etapa, archivo_url, comentario FROM cliente_mantenimiento_foto WHERE mant_id=?");
            $f->execute([$mantId]);
            $fotos = $f->fetchAll(PDO::FETCH_ASSOC);

            $esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
            $cfg = $this->clienteCfg($idCliente);

            $imgFoto = function($etapa) use ($fotos, $esc) {
                $out = '';
                foreach ($fotos as $ft) {
                    if ($ft['etapa'] !== $etapa) continue;
                    $abs = rtrim(UPLOAD_DIR, '/') . '/' . ltrim(str_replace(UPLOAD_URL, '', $ft['archivo_url']), '/');
                    if (!is_file($abs)) continue;
                    $b64 = 'data:image/jpeg;base64,' . base64_encode(file_get_contents($abs));
                    $out .= '<td style="width:33%;padding:4px;text-align:center;vertical-align:top">
                        <img src="' . $b64 . '" style="width:100%;max-height:120px;object-fit:cover;border:1px solid #ccc;border-radius:4px">
                        ' . ($ft['comentario'] ? '<div style="font-size:7.5pt;color:#666;margin-top:2px">' . $esc($ft['comentario']) . '</div>' : '') . '</td>';
                }
                if ($out === '') return '<td style="font-size:9pt;color:#999;padding:8px">Sin fotos.</td>';
                // En filas de 3
                return $out;
            };

            $bloqueFotos = function($titulo, $etapa) use ($imgFoto) {
                $cells = $imgFoto($etapa);
                return '<div style="font-weight:bold;color:#0d2244;font-size:10pt;margin:10px 0 4px">' . $titulo . '</div>'
                    . '<table style="width:100%;border-collapse:collapse"><tr>' . $cells . '</tr></table>';
            };

            $fecha = $m['fecha'] ? date('d/m/Y', strtotime($m['fecha'])) : '—';
            $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
                body{font-family:DejaVu Sans,Arial,sans-serif;font-size:10pt;color:#1a2233}
                h1{font-size:15pt;color:#0d2244;margin:0 0 2px}
                .meta{font-size:9.5pt;color:#444;margin-bottom:10px}
                .sec{font-weight:bold;color:#0d2244;font-size:10.5pt;border-bottom:1px solid #cdd5e6;padding-bottom:3px;margin:12px 0 6px}
                .box{border:1px solid #e3e8f0;border-radius:6px;padding:8px 12px;background:#f8fafc;font-size:9.5pt;white-space:pre-wrap}
                table.kv td{padding:2px 6px;font-size:9.5pt}
                </style></head><body>'
                . $this->membrete($cfg, $idCliente)
                . '<h1>Reporte de Mantenimiento</h1>'
                . '<div class="meta">Folio: <strong>' . $esc($m['folio']) . '</strong> &nbsp;·&nbsp; Fecha: ' . $fecha
                . ' &nbsp;·&nbsp; Mecánico: ' . $esc($m['mecanico_nombre']) . '</div>'
                . '<table class="kv"><tr><td><strong>Equipo:</strong> ' . $esc($m['equipo_nombre']) . '</td>'
                . '<td><strong>Tipo:</strong> ' . $esc($m['tipo_mant'] ?: '—') . '</td></tr></table>'
                . '<div class="sec">Trabajo realizado</div><div class="box">' . $esc($m['trabajo']) . '</div>'
                . ($m['material_usado'] ? '<div class="sec">Material utilizado</div><div class="box">' . $esc($m['material_usado']) . '</div>' : '')
                . ($m['observaciones'] ? '<div class="sec">Observaciones</div><div class="box">' . $esc($m['observaciones']) . '</div>' : '')
                . $bloqueFotos('Evidencia — Antes', 'antes')
                . $bloqueFotos('Evidencia — Después', 'despues')
                . $this->pieAvba()
                . '</body></html>';

            return $this->renderPdf($html, "clientes/$idCliente/mantenimiento/$mantId", "Reporte_{$m['folio']}.pdf");
        } catch (\Throwable $e) {
            error_log('[Mantenimiento] pdfMantenimiento: ' . $e->getMessage());
            return null;
        }
    }
}
