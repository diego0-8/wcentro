<?php
/**
 * Modelo Obligacion - Obligaciones por operación (carga CSV)
 * IMPORTANTE: La operación NO es única globalmente.
 * En este sistema la misma `operacion` puede existir en distintas bases (`base_id`).
 */

if (!function_exists('getDBConnection')) {
    require_once __DIR__ . '/../config.php';
}

class Obligacion {

    /** @var PDO */
    private $db;

    /** Valores permitidos para estado_proceso_juridico (ENUM en BD: JUDICIALIZADO, NO JUDICIALIZADO u otros) */
    private static $estadosJuridicoPermitidos = [
        'judicializado', 'no judicializado', 'pendiente', 'en_proceso', 'terminado',
        'activo', 'inactivo', 'prejuridico', 'juridico', 'prejurídico', 'jurídico'
    ];

    public function __construct() {
        $this->db = getDBConnection();
    }

    public function tablaExiste() {
        $stmt = $this->db->query("SHOW TABLES LIKE 'obligaciones'");
        return $stmt->rowCount() > 0;
    }

    /**
     * Normaliza estado_proceso_juridico para el ENUM de la BD
     */
    public static function normalizarEstadoJuridico($valor) {
        if ($valor === null || trim((string)$valor) === '') {
            return null;
        }
        $v = strtoupper(trim(str_replace([' ', '_'], [' ', ' '], $valor)));
        if ($v === 'JUDICIALIZADO' || $v === 'NO JUDICIALIZADO') {
            return $v;
        }
        $vLower = strtolower(str_replace([' ', 'í'], ['_', 'i'], trim($valor)));
        if ($vLower === 'judicializado' || strpos($vLower, 'judicializado') !== false) {
            return 'JUDICIALIZADO';
        }
        if ($vLower === 'no judicializado' || $vLower === 'no_judicializado') {
            return 'NO JUDICIALIZADO';
        }
        return null;
    }

    /**
     * Obtiene una obligación por número de operación (búsqueda global).
     * Evitar usar esto para cargas por base: use obtenerPorOperacionYBase().
     */
    public function obtenerPorOperacion($operacion) {
        if (!$this->tablaExiste()) {
            return null;
        }
        $stmt = $this->db->prepare("SELECT * FROM obligaciones WHERE operacion = ? LIMIT 1");
        $stmt->execute([$operacion]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Obtiene una obligación por número de operación y base_id (scope correcto para cargas).
     */
    public function obtenerPorOperacionYBase($operacion, $baseId) {
        if (!$this->tablaExiste()) {
            return null;
        }
        $stmt = $this->db->prepare("SELECT * FROM obligaciones WHERE operacion = ? AND base_id = ? LIMIT 1");
        $stmt->execute([(string) $operacion, (int) $baseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Valores por defecto para columnas NOT NULL de obligaciones (cuenta_cliente, oficina, año_castigo, total, total_a_pagar)
     */
    private static function valorObligatorio($valor, $esDecimal = false) {
        if ($valor !== null && $valor !== '' && trim((string)$valor) !== '') {
            return $esDecimal ? (float) str_replace(',', '.', $valor) : trim((string)$valor);
        }
        return $esDecimal ? 0.0 : '';
    }

    /** Normaliza días de mora a entero >= 0 */
    public static function normalizarDiasMora($valor) {
        if ($valor === null || trim((string) $valor) === '') {
            return 0;
        }
        $texto = preg_replace('/[^\d\-]/', '', (string) $valor);
        $n = (int) $texto;
        return $n < 0 ? 0 : $n;
    }

    /**
     * Crea una obligación. BD: cuenta_cliente, oficina, año_castigo, total, total_a_pagar son NOT NULL.
     */
    public function crear(array $datos) {
        if (!$this->tablaExiste()) {
            return false;
        }
        $estadoJur = self::normalizarEstadoJuridico($datos['estado_proceso_juridico'] ?? null);
        if ($estadoJur === null) {
            $estadoJur = 'NO JUDICIALIZADO';
        }
        $stmt = $this->db->prepare("
            INSERT INTO obligaciones (
                operacion, base_id, cliente_id, cuenta_cliente, oficina, año_castigo,
                concepto_mes_actual, estado_proceso_juridico, total, total_a_pagar,
                dueno_cartera, compra, tipo_producto, bucket_saldo_capital, dias_mora_actual
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        return $stmt->execute([
            (string) $datos['operacion'],
            (int) $datos['base_id'],
            (int) $datos['cliente_id'],
            self::valorObligatorio($datos['cuenta_cliente'] ?? $datos['cuenta'] ?? ''),
            self::valorObligatorio($datos['oficina'] ?? ''),
            self::valorObligatorio($datos['año_castigo'] ?? $datos['años_castigo'] ?? $datos['anos_castigo'] ?? ''),
            $datos['concepto_mes_actual'] ?? null,
            $estadoJur,
            self::valorObligatorio($datos['total'] ?? 0, true),
            self::valorObligatorio($datos['total_a_pagar'] ?? 0, true),
            self::valorObligatorio($datos['dueno_cartera'] ?? ''),
            self::valorObligatorio($datos['compra'] ?? ''),
            self::valorObligatorio($datos['tipo_producto'] ?? ''),
            self::valorObligatorio($datos['bucket_saldo_capital'] ?? ''),
            self::normalizarDiasMora($datos['dias_mora_actual'] ?? 0),
        ]);
    }

    /**
     * Actualiza una obligación (todos los campos excepto operacion y cliente_id). Columnas NOT NULL con valor por defecto si vienen vacías.
     */
    public function actualizar($operacion, array $datos) {
        if (!$this->tablaExiste()) {
            return false;
        }
        $estadoJur = isset($datos['estado_proceso_juridico']) ? self::normalizarEstadoJuridico($datos['estado_proceso_juridico']) : null;
        $stmt = $this->db->prepare("
            UPDATE obligaciones SET
                cuenta_cliente = ?, oficina = ?, año_castigo = ?, concepto_mes_actual = COALESCE(?, concepto_mes_actual),
                estado_proceso_juridico = COALESCE(?, estado_proceso_juridico), total = ?, total_a_pagar = ?,
                dueno_cartera = ?, compra = ?, tipo_producto = ?, bucket_saldo_capital = ?, dias_mora_actual = ?
            WHERE operacion = ?
        ");
        return $stmt->execute([
            self::valorObligatorio($datos['cuenta_cliente'] ?? $datos['cuenta'] ?? ''),
            self::valorObligatorio($datos['oficina'] ?? ''),
            self::valorObligatorio($datos['año_castigo'] ?? $datos['años_castigo'] ?? $datos['anos_castigo'] ?? ''),
            $datos['concepto_mes_actual'] ?? null,
            $estadoJur,
            self::valorObligatorio($datos['total'] ?? 0, true),
            self::valorObligatorio($datos['total_a_pagar'] ?? 0, true),
            self::valorObligatorio($datos['dueno_cartera'] ?? ''),
            self::valorObligatorio($datos['compra'] ?? ''),
            self::valorObligatorio($datos['tipo_producto'] ?? ''),
            self::valorObligatorio($datos['bucket_saldo_capital'] ?? ''),
            self::normalizarDiasMora($datos['dias_mora_actual'] ?? 0),
            $operacion,
        ]);
    }

    /**
     * Actualiza una obligación por operación + base_id (evita pisar obligaciones de otra base).
     */
    public function actualizarPorOperacionYBase($operacion, $baseId, array $datos) {
        if (!$this->tablaExiste()) {
            return false;
        }
        $estadoJur = isset($datos['estado_proceso_juridico']) ? self::normalizarEstadoJuridico($datos['estado_proceso_juridico']) : null;
        $stmt = $this->db->prepare("
            UPDATE obligaciones SET
                cuenta_cliente = ?, oficina = ?, año_castigo = ?, concepto_mes_actual = COALESCE(?, concepto_mes_actual),
                estado_proceso_juridico = COALESCE(?, estado_proceso_juridico), total = ?, total_a_pagar = ?, cliente_id = ?,
                dueno_cartera = ?, compra = ?, tipo_producto = ?, bucket_saldo_capital = ?, dias_mora_actual = ?
            WHERE operacion = ? AND base_id = ?
        ");
        return $stmt->execute([
            self::valorObligatorio($datos['cuenta_cliente'] ?? $datos['cuenta'] ?? ''),
            self::valorObligatorio($datos['oficina'] ?? ''),
            self::valorObligatorio($datos['año_castigo'] ?? $datos['años_castigo'] ?? $datos['anos_castigo'] ?? ''),
            $datos['concepto_mes_actual'] ?? null,
            $estadoJur,
            self::valorObligatorio($datos['total'] ?? 0, true),
            self::valorObligatorio($datos['total_a_pagar'] ?? 0, true),
            (int) ($datos['cliente_id'] ?? 0),
            self::valorObligatorio($datos['dueno_cartera'] ?? ''),
            self::valorObligatorio($datos['compra'] ?? ''),
            self::valorObligatorio($datos['tipo_producto'] ?? ''),
            self::valorObligatorio($datos['bucket_saldo_capital'] ?? ''),
            self::normalizarDiasMora($datos['dias_mora_actual'] ?? 0),
            (string) $operacion,
            (int) $baseId,
        ]);
    }

    /**
     * Obtiene obligaciones por base_id
     */
    public function obtenerPorBase($baseId) {
        if (!$this->tablaExiste()) {
            return [];
        }
        $stmt = $this->db->prepare("SELECT * FROM obligaciones WHERE base_id = ? ORDER BY operacion");
        $stmt->execute([$baseId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Obtiene obligaciones por cliente_id
     */
    public function obtenerPorCliente($clienteId) {
        if (!$this->tablaExiste()) {
            return [];
        }
        $stmt = $this->db->prepare("SELECT 
            id_obligacion,
            base_id,
            cliente_id,
            operacion,
            cuenta_cliente,
            oficina,
            año_castigo,
            concepto_mes_actual,
            estado_proceso_juridico,
            total,
            total_a_pagar,
            dueno_cartera,
            compra,
            tipo_producto,
            bucket_saldo_capital,
            dias_mora_actual
        FROM obligaciones WHERE cliente_id = ? ORDER BY operacion");
        $stmt->execute([$clienteId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
