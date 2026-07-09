<?php
/**
 * Modelo Campana - Banco W CRM
 * Campañas con múltiples coordinadores y asesores
 */

if (!function_exists('getDBConnection')) {
    require_once __DIR__ . '/../config.php';
}

class Campana {

    /** @var PDO */
    private $db;

    public function __construct() {
        $this->db = getDBConnection();
    }

    public function tablaExiste(): bool {
        $stmt = $this->db->query("SHOW TABLES LIKE 'campanas'");
        return $stmt && $stmt->rowCount() > 0;
    }

    private function mapCampanaRow(?array $row): ?array {
        if (!$row) {
            return null;
        }
        return [
            'id' => (int) ($row['id_campana'] ?? 0),
            'id_campana' => (int) ($row['id_campana'] ?? 0),
            'nombre' => $row['nombre'] ?? '',
            'descripcion' => $row['descripcion'] ?? '',
            'estado' => $row['estado'] ?? 'activa',
            'creado_por' => $row['creado_por'] ?? null,
            'fecha_creacion' => $row['fecha_creacion'] ?? null,
            'fecha_actualizacion' => $row['fecha_actualizacion'] ?? null,
            'creador_nombre' => $row['creador_nombre'] ?? null,
            'total_coordinadores' => (int) ($row['total_coordinadores'] ?? 0),
            'total_asesores' => (int) ($row['total_asesores'] ?? 0),
            'total_bases' => (int) ($row['total_bases'] ?? 0),
        ];
    }

    public function crear(string $nombre, string $descripcion, string $creadoPor) {
        if (!$this->tablaExiste()) {
            $this->lastError = 'La tabla campanas no existe. Ejecute: php scripts/ejecutar_migracion_campanas.php';
            return false;
        }

        $creadoPor = trim($creadoPor);
        if ($creadoPor === '') {
            $creadoPor = $this->resolverCedulaAdmin();
        }
        if ($creadoPor === '') {
            $this->lastError = 'No hay administrador activo para registrar creado_por. Inicie sesión de nuevo.';
            return false;
        }

        try {
            $stmt = $this->db->prepare("
                INSERT INTO campanas (nombre, descripcion, estado, creado_por)
                VALUES (?, ?, 'activa', ?)
            ");
            if (!$stmt->execute([trim($nombre), trim($descripcion), $creadoPor])) {
                $this->lastError = 'Error al insertar la campaña.';
                return false;
            }
            return (int) $this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log('Campana::crear - ' . $e->getMessage());
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    /** @var string */
    private $lastError = '';

    public function getLastError(): string {
        return $this->lastError;
    }

    private function resolverCedulaAdmin(): string {
        $stmt = $this->db->query("
            SELECT cedula FROM usuarios
            WHERE rol = 'administrador' AND estado = 'Activo'
            ORDER BY fecha_creacion ASC
            LIMIT 1
        ");
        $cedula = $stmt ? $stmt->fetchColumn() : false;
        return $cedula ? (string) $cedula : '';
    }

    public function actualizar(int $id, string $nombre, string $descripcion, string $estado): bool {
        $stmt = $this->db->prepare("
            UPDATE campanas SET nombre = ?, descripcion = ?, estado = ? WHERE id_campana = ?
        ");
        return $stmt->execute([trim($nombre), trim($descripcion), $estado, $id]);
    }

    /**
     * Inhabilita o habilita una campaña sin borrar datos (soft toggle).
     */
    public function cambiarEstado(int $id, string $estado): bool {
        if (!in_array($estado, ['activa', 'inactiva'], true)) {
            $this->lastError = 'Estado no válido.';
            return false;
        }
        try {
            $stmt = $this->db->prepare("UPDATE campanas SET estado = ? WHERE id_campana = ?");
            return $stmt->execute([$estado, $id]);
        } catch (PDOException $e) {
            error_log('Campana::cambiarEstado - ' . $e->getMessage());
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    public function estaActiva(int $id): bool {
        $campana = $this->obtenerPorId($id);
        return $campana && ($campana['estado'] ?? '') === 'activa';
    }

    public function obtenerTodas(bool $soloActivas = false): array {
        if (!$this->tablaExiste()) {
            return [];
        }
        $where = $soloActivas ? " WHERE c.estado = 'activa'" : '';
        $stmt = $this->db->query("
            SELECT c.*,
                   u.nombre AS creador_nombre,
                   (SELECT COUNT(*) FROM campana_coordinadores cc
                    WHERE cc.campana_id = c.id_campana AND cc.estado = 'activo') AS total_coordinadores,
                   (SELECT COUNT(*) FROM campana_asesores ca
                    WHERE ca.campana_id = c.id_campana AND ca.estado = 'activo') AS total_asesores,
                   (SELECT COUNT(*) FROM base_clientes b
                    WHERE b.campana_id = c.id_campana AND b.estado = 'activo') AS total_bases
            FROM campanas c
            LEFT JOIN usuarios u ON u.cedula = c.creado_por
            {$where}
            ORDER BY c.fecha_creacion DESC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_values(array_filter(array_map([$this, 'mapCampanaRow'], $rows)));
    }

    public function obtenerActivas(): array {
        return $this->obtenerTodas(true);
    }

    public function obtenerPorId(int $id): ?array {
        if (!$this->tablaExiste()) {
            return null;
        }
        $stmt = $this->db->prepare("
            SELECT c.*,
                   u.nombre AS creador_nombre,
                   (SELECT COUNT(*) FROM campana_coordinadores cc
                    WHERE cc.campana_id = c.id_campana AND cc.estado = 'activo') AS total_coordinadores,
                   (SELECT COUNT(*) FROM campana_asesores ca
                    WHERE ca.campana_id = c.id_campana AND ca.estado = 'activo') AS total_asesores,
                   (SELECT COUNT(*) FROM base_clientes b
                    WHERE b.campana_id = c.id_campana AND b.estado = 'activo') AS total_bases
            FROM campanas c
            LEFT JOIN usuarios u ON u.cedula = c.creado_por
            WHERE c.id_campana = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        return $this->mapCampanaRow($stmt->fetch(PDO::FETCH_ASSOC) ?: null);
    }

    public function obtenerCampanasDelCoordinador(string $coordinadorCedula): array {
        if (!$this->tablaExiste()) {
            return [];
        }
        $stmt = $this->db->prepare("
            SELECT c.*, u.nombre AS creador_nombre
            FROM campanas c
            INNER JOIN campana_coordinadores cc ON cc.campana_id = c.id_campana
            LEFT JOIN usuarios u ON u.cedula = c.creado_por
            WHERE cc.coordinador_cedula = ?
              AND cc.estado = 'activo'
              AND c.estado = 'activa'
            ORDER BY c.nombre ASC
        ");
        $stmt->execute([(string) $coordinadorCedula]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_values(array_filter(array_map([$this, 'mapCampanaRow'], $rows)));
    }

    public function coordinadorPerteneceACampana(string $coordinadorCedula, int $campanaId): bool {
        $stmt = $this->db->prepare("
            SELECT 1 FROM campana_coordinadores
            WHERE coordinador_cedula = ? AND campana_id = ? AND estado = 'activo'
            LIMIT 1
        ");
        $stmt->execute([(string) $coordinadorCedula, $campanaId]);
        return (bool) $stmt->fetchColumn();
    }

    public function coordinadorAccedeABase(string $coordinadorCedula, int $baseId): bool {
        if (!$this->tablaExiste()) {
            return true;
        }
        $stmt = $this->db->prepare("
            SELECT 1
            FROM base_clientes b
            WHERE b.id_base = ?
              AND (
                b.creado_por = ?
                OR (
                    b.campana_id IS NOT NULL
                    AND EXISTS (
                        SELECT 1 FROM campanas c
                        WHERE c.id_campana = b.campana_id AND c.estado = 'activa'
                    )
                    AND EXISTS (
                        SELECT 1 FROM campana_coordinadores cc
                        WHERE cc.campana_id = b.campana_id
                          AND cc.coordinador_cedula = ?
                          AND cc.estado = 'activo'
                    )
                )
              )
            LIMIT 1
        ");
        $stmt->execute([(int) $baseId, (string) $coordinadorCedula, (string) $coordinadorCedula]);
        return (bool) $stmt->fetchColumn();
    }

    public function obtenerCampanaIdPorBase(int $baseId): ?int {
        $stmt = $this->db->prepare("SELECT campana_id FROM base_clientes WHERE id_base = ? LIMIT 1");
        $stmt->execute([(int) $baseId]);
        $val = $stmt->fetchColumn();
        return $val !== false && $val !== null ? (int) $val : null;
    }

    public function obtenerPrimeraCampanaIdDelCoordinador(string $coordinadorCedula): ?int {
        $campanas = $this->obtenerCampanasDelCoordinador($coordinadorCedula);
        if (empty($campanas)) {
            return null;
        }
        return (int) ($campanas[0]['id'] ?? 0) ?: null;
    }

    public function getIdsCampanasDelCoordinador(string $coordinadorCedula): array {
        $campanas = $this->obtenerCampanasDelCoordinador($coordinadorCedula);
        return array_map(fn($c) => (int) $c['id'], $campanas);
    }

    /**
     * Cédulas de asesores visibles para el coordinador (todas sus campañas activas).
     * @return array<int, string>
     */
    public function getAsesoresCedulasDelCoordinador(string $coordinadorCedula): array {
        if (!$this->tablaExiste()) {
            return $this->fallbackAsesoresCedulasDesdeAsignaciones($coordinadorCedula);
        }
        $asesores = $this->getAsesoresDelCoordinador($coordinadorCedula);
        return array_values(array_unique(array_column($asesores, 'cedula')));
    }

    /**
     * Asesores completos visibles para el coordinador.
     * @return array<int, array>
     */
    public function getAsesoresDelCoordinador(string $coordinadorCedula): array {
        if (!$this->tablaExiste()) {
            return $this->fallbackAsesoresDesdeAsignaciones($coordinadorCedula);
        }
        $stmt = $this->db->prepare("
            SELECT DISTINCT u.cedula, u.nombre, u.usuario, u.estado, u.extension,
                   u.nombre AS nombre_completo,
                   ca.fecha_asignacion, ca.estado AS estado_asignacion
            FROM campana_asesores ca
            INNER JOIN campana_coordinadores cc ON cc.campana_id = ca.campana_id
            INNER JOIN usuarios u ON u.cedula = ca.asesor_cedula
            WHERE cc.coordinador_cedula = ?
              AND cc.estado = 'activo'
              AND ca.estado = 'activo'
              AND u.estado = 'Activo'
              AND u.rol = 'asesor'
            ORDER BY u.nombre
        ");
        $stmt->execute([(string) $coordinadorCedula]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function fallbackAsesoresCedulasDesdeAsignaciones(string $coordinadorCedula): array {
        require_once __DIR__ . '/Asignacion.php';
        $asignacionModel = new Asignacion();
        $asignaciones = $asignacionModel->obtenerPorCoordinador($coordinadorCedula);
        return array_values(array_unique(array_column($asignaciones, 'asesor_cedula')));
    }

    private function fallbackAsesoresDesdeAsignaciones(string $coordinadorCedula): array {
        require_once __DIR__ . '/Usuario.php';
        require_once __DIR__ . '/Asignacion.php';
        $asignacionModel = new Asignacion();
        $usuarioModel = new Usuario();
        $asignaciones = $asignacionModel->obtenerPorCoordinador($coordinadorCedula);
        $asesores = [];
        foreach ($asignaciones as $a) {
            $u = $usuarioModel->obtenerPorCedula($a['asesor_cedula']);
            if ($u && strtolower($u['rol'] ?? '') === 'asesor') {
                $u['nombre_completo'] = $u['nombre_completo'] ?? $u['nombre'] ?? '';
                $asesores[] = $u;
            }
        }
        return $asesores;
    }

    public function getCoordinadoresByCampana(int $campanaId): array {
        $stmt = $this->db->prepare("
            SELECT u.cedula, u.cedula AS id, u.nombre AS nombre_completo, u.usuario,
                   cc.fecha_asignacion, cc.estado AS estado_asignacion
            FROM campana_coordinadores cc
            INNER JOIN usuarios u ON u.cedula = cc.coordinador_cedula
            WHERE cc.campana_id = ? AND cc.estado = 'activo'
            ORDER BY u.nombre
        ");
        $stmt->execute([$campanaId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getCoordinadoresDisponibles(int $campanaId): array {
        $stmt = $this->db->prepare("
            SELECT u.cedula, u.cedula AS id, u.nombre AS nombre_completo, u.usuario
            FROM usuarios u
            WHERE u.rol = 'coordinador' AND u.estado = 'Activo'
              AND u.cedula NOT IN (
                SELECT cc.coordinador_cedula FROM campana_coordinadores cc
                WHERE cc.campana_id = ? AND cc.estado = 'activo'
              )
            ORDER BY u.nombre
        ");
        $stmt->execute([$campanaId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function asignarCoordinador(int $campanaId, string $coordinadorCedula, string $asignadoPor): bool {
        $stmt = $this->db->prepare("
            SELECT id_campana_coordinador FROM campana_coordinadores
            WHERE campana_id = ? AND coordinador_cedula = ? LIMIT 1
        ");
        $stmt->execute([$campanaId, (string) $coordinadorCedula]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $stmt = $this->db->prepare("
                UPDATE campana_coordinadores
                SET estado = 'activo', asignado_por = ?, fecha_asignacion = CURRENT_TIMESTAMP
                WHERE id_campana_coordinador = ?
            ");
            return $stmt->execute([(string) $asignadoPor, (int) $existing['id_campana_coordinador']]);
        }

        $stmt = $this->db->prepare("
            INSERT INTO campana_coordinadores (campana_id, coordinador_cedula, estado, asignado_por)
            VALUES (?, ?, 'activo', ?)
        ");
        return $stmt->execute([$campanaId, (string) $coordinadorCedula, (string) $asignadoPor]);
    }

    public function liberarCoordinador(int $campanaId, string $coordinadorCedula): bool {
        $stmt = $this->db->prepare("
            UPDATE campana_coordinadores SET estado = 'inactivo'
            WHERE campana_id = ? AND coordinador_cedula = ? AND estado = 'activo'
        ");
        return $stmt->execute([$campanaId, (string) $coordinadorCedula]);
    }

    public function getAsesoresByCampana(int $campanaId): array {
        $stmt = $this->db->prepare("
            SELECT u.cedula, u.cedula AS id, u.nombre AS nombre_completo, u.usuario,
                   ca.fecha_asignacion, ca.estado AS estado_asignacion
            FROM campana_asesores ca
            INNER JOIN usuarios u ON u.cedula = ca.asesor_cedula
            WHERE ca.campana_id = ? AND ca.estado = 'activo' AND u.estado = 'Activo'
            ORDER BY u.nombre
        ");
        $stmt->execute([$campanaId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAsesoresDisponibles(int $campanaId): array {
        $stmt = $this->db->prepare("
            SELECT u.cedula, u.cedula AS id, u.nombre AS nombre_completo, u.usuario
            FROM usuarios u
            WHERE u.rol = 'asesor' AND u.estado = 'Activo'
              AND u.cedula NOT IN (
                SELECT ca.asesor_cedula FROM campana_asesores ca
                WHERE ca.campana_id = ? AND ca.estado = 'activo'
              )
              AND u.cedula NOT IN (
                SELECT ca2.asesor_cedula FROM campana_asesores ca2
                WHERE ca2.estado = 'activo'
              )
            ORDER BY u.nombre
        ");
        $stmt->execute([$campanaId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function asignarAsesor(int $campanaId, string $asesorCedula, string $asignadoPor): bool {
        $stmt = $this->db->prepare("UPDATE campana_asesores SET estado = 'inactivo' WHERE asesor_cedula = ? AND estado = 'activo'");
        $stmt->execute([(string) $asesorCedula]);

        $stmt = $this->db->prepare("
            SELECT id_campana_asesor FROM campana_asesores
            WHERE campana_id = ? AND asesor_cedula = ? LIMIT 1
        ");
        $stmt->execute([$campanaId, (string) $asesorCedula]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $stmt = $this->db->prepare("
                UPDATE campana_asesores
                SET estado = 'activo', asignado_por = ?, fecha_asignacion = CURRENT_TIMESTAMP
                WHERE id_campana_asesor = ?
            ");
            return $stmt->execute([(string) $asignadoPor, (int) $existing['id_campana_asesor']]);
        }

        $stmt = $this->db->prepare("
            INSERT INTO campana_asesores (campana_id, asesor_cedula, estado, asignado_por)
            VALUES (?, ?, 'activo', ?)
        ");
        return $stmt->execute([$campanaId, (string) $asesorCedula, (string) $asignadoPor]);
    }

    public function liberarAsesor(int $campanaId, string $asesorCedula): bool {
        $stmt = $this->db->prepare("
            UPDATE campana_asesores SET estado = 'inactivo'
            WHERE campana_id = ? AND asesor_cedula = ? AND estado = 'activo'
        ");
        return $stmt->execute([$campanaId, (string) $asesorCedula]);
    }

    public function registrarAuditoria(
        string $coordinadorCedula,
        ?int $campanaId,
        string $accion,
        string $entidad,
        ?int $entidadId = null,
        ?array $detalle = null
    ): bool {
        if (!$this->tablaExiste()) {
            return false;
        }
        $stmt = $this->db->prepare("
            INSERT INTO auditoria_coordinadores
                (coordinador_cedula, campana_id, accion, entidad, entidad_id, detalle)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $json = $detalle !== null ? json_encode($detalle, JSON_UNESCAPED_UNICODE) : null;
        return $stmt->execute([
            (string) $coordinadorCedula,
            $campanaId,
            $accion,
            $entidad,
            $entidadId,
            $json,
        ]);
    }

    public function getAuditoriaByCampana(int $campanaId, int $limit = 200): array {
        $limit = max(1, min(500, (int) $limit));
        $stmt = $this->db->prepare("
            SELECT a.*, u.nombre AS coordinador_nombre
            FROM auditoria_coordinadores a
            LEFT JOIN usuarios u ON u.cedula = a.coordinador_cedula
            WHERE a.campana_id = ?
            ORDER BY a.fecha DESC
            LIMIT $limit
        ");
        $stmt->execute([$campanaId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Historial del coordinador: últimos N días, más reciente primero.
     */
    public function getAuditoriaRecienteCoordinador(string $coordinadorCedula, int $dias = 5, int $limit = 100): array {
        if (!$this->tablaExiste()) {
            return [];
        }
        $dias = max(1, min(90, $dias));
        $limit = max(1, min(500, (int) $limit));
        $stmt = $this->db->prepare("
            SELECT a.*, c.nombre AS campana_nombre
            FROM auditoria_coordinadores a
            LEFT JOIN campanas c ON c.id_campana = a.campana_id
            WHERE a.coordinador_cedula = ?
              AND a.fecha >= DATE_SUB(NOW(), INTERVAL ? DAY)
            ORDER BY a.fecha DESC
            LIMIT $limit
        ");
        $stmt->execute([(string) $coordinadorCedula, $dias]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Historial global para administrador con filtros opcionales.
     */
    public function getAuditoriaAdmin(
        ?string $fechaDesde = null,
        ?string $fechaHasta = null,
        ?string $coordinadorCedula = null,
        ?int $campanaId = null,
        int $limit = 500
    ): array {
        if (!$this->tablaExiste()) {
            return [];
        }
        $limit = max(1, min(2000, (int) $limit));
        $where = ['1=1'];
        $params = [];

        if ($fechaDesde !== null && $fechaDesde !== '') {
            $where[] = 'DATE(a.fecha) >= ?';
            $params[] = $fechaDesde;
        }
        if ($fechaHasta !== null && $fechaHasta !== '') {
            $where[] = 'DATE(a.fecha) <= ?';
            $params[] = $fechaHasta;
        }
        if ($coordinadorCedula !== null && $coordinadorCedula !== '') {
            $where[] = 'a.coordinador_cedula = ?';
            $params[] = $coordinadorCedula;
        }
        if ($campanaId !== null && $campanaId > 0) {
            $where[] = 'a.campana_id = ?';
            $params[] = $campanaId;
        }

        $sql = "
            SELECT a.*,
                   u.nombre AS coordinador_nombre,
                   c.nombre AS campana_nombre
            FROM auditoria_coordinadores a
            LEFT JOIN usuarios u ON u.cedula = a.coordinador_cedula
            LEFT JOIN campanas c ON c.id_campana = a.campana_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY a.fecha DESC
            LIMIT $limit
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
