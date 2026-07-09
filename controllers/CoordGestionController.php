<?php
/**
 * Controlador de Gestión del Coordinador
 * Endpoints AJAX para bases, asesores y tareas (Coord_gestion.php)
 */

require_once __DIR__ . '/../models/Usuario.php';
require_once __DIR__ . '/../models/Asignacion.php';
require_once __DIR__ . '/../models/Campana.php';
require_once __DIR__ . '/../models/Cliente.php';
require_once __DIR__ . '/../models/BaseCliente.php';
require_once __DIR__ . '/../models/Obligacion.php';
require_once __DIR__ . '/../models/Tarea.php';
require_once __DIR__ . '/../models/Acuerdo.php';

class CoordGestionController {

    private function coordinadorCedula() {
        return $_SESSION['usuario_id'] ?? $_SESSION['usuario_cedula'] ?? null;
    }

    private function campanaModel(): Campana {
        static $model = null;
        if ($model === null) {
            $model = new Campana();
        }
        return $model;
    }

    private function cedulasAsesoresCoordinador(?string $cedula): array {
        if (!$cedula) {
            return [];
        }
        return $this->campanaModel()->getAsesoresCedulasDelCoordinador($cedula);
    }

    private function asesoresListaCoordinador(?string $cedula): array {
        if (!$cedula) {
            return [];
        }
        return $this->campanaModel()->getAsesoresDelCoordinador($cedula);
    }

    private function coordinadorPuedeAccederBase(?string $cedula, int $baseId): bool {
        if (!$cedula || $baseId <= 0) {
            return false;
        }
        return $this->campanaModel()->coordinadorAccedeABase($cedula, $baseId);
    }

    private function auditarCoordinador(string $accion, string $entidad, ?int $entidadId = null, ?array $detalle = null): void {
        try {
            $cedula = $this->coordinadorCedula();
            if (!$cedula) {
                return;
            }
            $campanaId = $this->campanaModel()->obtenerPrimeraCampanaIdDelCoordinador((string) $cedula);
            $this->campanaModel()->registrarAuditoria((string) $cedula, $campanaId, $accion, $entidad, $entidadId, $detalle);
        } catch (Throwable $e) {
            error_log('CoordGestionController::auditarCoordinador - ' . $e->getMessage());
        }
    }

    /**
     * Lista bases de clientes (desde distinct base_id en cliente, o tabla base_clientes si existe)
     * @return array{success: bool, data?: array, bases?: array}
     */
    public function obtenerBases() {
        try {
            $db = getDBConnection();
            $bases = [];
            $cedula = $this->coordinadorCedula();
            $filtrarPorCampana = $this->campanaModel()->tablaExiste() && $cedula;
            $tablasBases = ['base_clientes', 'bases'];
            $encontrada = false;
            foreach ($tablasBases as $tabla) {
                try {
                    $stmt = $db->query("SHOW TABLES LIKE '$tabla'");
                    if ($stmt->rowCount() > 0) {
                        $filtroCampana = '';
                        if ($filtrarPorCampana) {
                            $filtroCampana = " AND (
                                bc.creado_por = " . $db->quote((string) $cedula) . "
                                OR bc.campana_id IN (
                                    SELECT cc.campana_id FROM campana_coordinadores cc
                                    INNER JOIN campanas c ON c.id_campana = cc.campana_id AND c.estado = 'activa'
                                    WHERE cc.coordinador_cedula = " . $db->quote((string) $cedula) . "
                                      AND cc.estado = 'activo'
                                )
                            )";
                        }
                        $sql = "
                            SELECT
                                bc.id_base AS id,
                                bc.nombre,
                                bc.estado,
                                bc.fecha_creacion,
                                bc.campana_id,
                                (SELECT COUNT(*) FROM cliente c WHERE c.base_id = bc.id_base) AS total_clientes,
                                (SELECT COUNT(*) FROM obligaciones o WHERE o.base_id = bc.id_base) AS total_obligaciones
                            FROM `{$tabla}` bc
                            WHERE LOWER(TRIM(bc.estado)) = 'activo'{$filtroCampana}
                            ORDER BY bc.nombre
                        ";
                        $stmt = $db->query($sql);
                        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                            $estadoBase = strtolower(trim((string)($row['estado'] ?? 'activo')));
                            if ($estadoBase !== 'activo') {
                                continue;
                            }
                            $idBase = (int) ($row['id'] ?? 0);
                            $totalClientes = (int) ($row['total_clientes'] ?? 0);
                            $totalObligaciones = (int) ($row['total_obligaciones'] ?? 0);
                            // Sincronizar contadores cacheados en base_clientes
                            if ($idBase > 0 && $tabla === 'base_clientes') {
                                try {
                                    $db->prepare("UPDATE base_clientes SET total_clientes = ?, TOTAL_OBLIGACIONES = ? WHERE id_base = ?")
                                        ->execute([$totalClientes, $totalObligaciones, $idBase]);
                                } catch (Exception $e) {
                                    // no bloquear listado
                                }
                            }
                            $bases[] = [
                                'id' => $idBase,
                                'nombre' => $row['nombre'] ?? 'Base ' . $idBase,
                                'estado' => $row['estado'] ?? 'activo',
                                'fecha_creacion' => $row['fecha_creacion'] ?? null,
                                'total_clientes' => $totalClientes,
                                'total_obligaciones' => $totalObligaciones,
                                'from_base_clientes' => true,
                            ];
                        }
                        $encontrada = true;
                        break;
                    }
                } catch (Exception $e) {
                    continue;
                }
            }
            if (!$encontrada) {
                // Derivar bases desde tabla cliente con GROUP BY (evita cargar millones de filas en memoria)
                try {
                    $db = getDBConnection();
                    $stmt = $db->query("SELECT base_id AS id, COUNT(*) AS total_clientes FROM cliente GROUP BY base_id");
                    if ($stmt) {
                        $obligacionModel = new Obligacion();
                        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                            $bid = (int) ($row['id'] ?? 0);
                            $obligacionesCount = 0;
                            if ($obligacionModel->tablaExiste()) {
                                try {
                                    $st = $db->prepare("SELECT COUNT(*) AS n FROM obligaciones WHERE base_id = ?");
                                    $st->execute([$bid]);
                                    $obligacionesCount = (int) ($st->fetchColumn());
                                } catch (\Exception $e) {
                                    // Ignorar error
                                }
                            }
                            $bases[] = [
                                'id' => $bid,
                                'nombre' => 'Base ' . ($bid ?: 'General'),
                                'estado' => 'activo',
                                'fecha_creacion' => null,
                                'total_clientes' => (int) ($row['total_clientes'] ?? 0),
                                'total_obligaciones' => $obligacionesCount,
                                'from_base_clientes' => false,
                            ];
                        }
                    }
                } catch (\Exception $e) {
                    error_log("CoordGestionController::obtenerBases (desde cliente): " . $e->getMessage());
                }
            }
            return ['success' => true, 'data' => $bases, 'bases' => $bases];
        } catch (Exception $e) {
            error_log("CoordGestionController::obtenerBases - " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'data' => [], 'bases' => []];
        }
    }

    /**
     * Obtiene clientes de una base (para el modal "Ver Clientes") con límite y búsqueda.
     * GET base_id, [limit], [offset], [busqueda]
     * Con bases de +1M registros solo se cargan hasta COORD_MODAL_CLIENTES_LIMIT; usar búsqueda para encontrar.
     * @return array{success: bool, clientes?: array, total?: int, message?: string}
     */
    public function obtenerClientesBase() {
        $baseId = isset($_GET['base_id']) ? (int) $_GET['base_id'] : 0;
        if ($baseId <= 0) {
            return ['success' => false, 'message' => 'base_id inválido', 'clientes' => [], 'total' => 0];
        }
        try {
            $clienteModel = new Cliente();
            $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : null;
            $offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;
            $busqueda = isset($_GET['busqueda']) ? trim((string) $_GET['busqueda']) : '';
            $result = $clienteModel->obtenerPorBasePaginado($baseId, $limit, $offset, $busqueda);
            return [
                'success' => true,
                'clientes' => $result['list'],
                'total' => $result['total'],
            ];
        } catch (\Exception $e) {
            error_log("CoordGestionController::obtenerClientesBase - " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'clientes' => [], 'total' => 0];
        }
    }

    /**
     * Obtiene el detalle de un cliente con sus obligaciones
     * GET cliente_id
     * @return array{success: bool, cliente?: array, obligaciones?: array, message?: string}
     */
    public function detalleClienteCoordinador() {
        $clienteId = isset($_GET['cliente_id']) ? (int) $_GET['cliente_id'] : 0;
        if ($clienteId <= 0) {
            return ['success' => false, 'message' => 'cliente_id inválido'];
        }
        try {
            $clienteModel = new Cliente();
            $obligacionModel = new Obligacion();
            
            $cliente = $clienteModel->obtenerPorId($clienteId);
            if (!$cliente) {
                return ['success' => false, 'message' => 'Cliente no encontrado'];
            }
            
            $obligaciones = $obligacionModel->obtenerPorCliente($clienteId);
            
            return [
                'success' => true,
                'cliente' => $cliente,
                'obligaciones' => $obligaciones,
            ];
        } catch (Exception $e) {
            error_log("CoordGestionController::detalleClienteCoordinador - " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Asesores asignados al coordinador logueado
     * @return array{success: bool, asesores?: array}
     */
    public function obtenerAsesores() {
        $cedula = $this->coordinadorCedula();
        if (!$cedula) {
            return ['success' => false, 'message' => 'No autorizado', 'asesores' => []];
        }
        try {
            $asesores = $this->asesoresListaCoordinador($cedula);
            return ['success' => true, 'asesores' => $asesores, 'data' => $asesores];
        } catch (Exception $e) {
            error_log("CoordGestionController::obtenerAsesores - " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'asesores' => []];
        }
    }

    /**
     * Verifica que existan las tablas base_clientes, cliente y obligaciones (para la pestaña Coord. gestión)
     * @return array{success: bool, total_comercios?: int, total_facturas?: int, error?: string, instrucciones?: string}
     */
    public function verificarTablas() {
        try {
            $baseCliente = new BaseCliente();
            $cliente = new Cliente();
            $obligacion = new Obligacion();
            $basesOk = $baseCliente->tablaExiste();
            $clienteOk = $this->tablaClienteExiste();
            $obligacionesOk = $obligacion->tablaExiste();
            if (!$basesOk || !$clienteOk || !$obligacionesOk) {
                $faltan = array_filter([
                    !$basesOk ? 'base_clientes' : null,
                    !$clienteOk ? 'cliente' : null,
                    !$obligacionesOk ? 'obligaciones' : null,
                ]);
                return [
                    'success' => false,
                    'error' => 'Faltan tablas: ' . implode(', ', $faltan),
                    'instrucciones' => 'Verifique que la base de datos esté importada correctamente y que la tabla cliente tenga la columna base_id.',
                ];
            }
            $totalBases = 0;
            if ($basesOk) {
                $res = $this->obtenerBases();
                $totalBases = count($res['bases'] ?? $res['data'] ?? []);
            }
            $totalClientes = 0;
            if ($clienteOk) {
                $db = getDBConnection();
                $stmt = $db->query("SELECT COUNT(*) AS n FROM cliente");
                if ($stmt) {
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    $totalClientes = (int) ($row['n'] ?? 0);
                }
            }
            return [
                'success' => true,
                'total_comercios' => $totalBases,
                'total_facturas' => $totalClientes,
            ];
        } catch (Exception $e) {
            error_log("CoordGestionController::verificarTablas - " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'instrucciones' => 'Revise la configuración de la base de datos (config.php) y verifique que la BD wcentro esté creada e importada.',
            ];
        }
    }

    private function tablaClienteExiste() {
        $db = getDBConnection();
        $stmt = $db->query("SHOW TABLES LIKE 'cliente'");
        if ($stmt->rowCount() === 0) {
            return false;
        }
        $stmt = $db->query("SHOW COLUMNS FROM cliente LIKE 'base_id'");
        return $stmt->rowCount() > 0;
    }

    /**
     * Estadísticas de bases para el panel
     * - Total Bases: Cuenta todos los nombres únicos en base_clientes
     * - Clientes Totales: Cuenta todas las cédulas únicas en cliente
     * - Obligaciones Totales: Cuenta todos los números de operación en obligaciones
     * - Bases Inactivas: Cuenta solo las bases con estado 'inactivo'
     */
    public function obtenerEstadisticasBases() {
        try {
            $db = getDBConnection();
            
            // 1. Total Bases (ACTIVAS): Contar bases activas en base_clientes
            $totalBases = 0;
            try {
                $stmt = $db->query("SELECT COUNT(*) AS total FROM base_clientes WHERE estado = 'activo'");
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $totalBases = (int)($row['total'] ?? 0);
            } catch (Exception $e) {
                error_log("Error contando total bases: " . $e->getMessage());
            }
            
            // 2. Clientes Totales (ACTIVAS): Contar cédulas únicas solo en bases activas
            $clientesTotales = 0;
            try {
                $stmt = $db->query("
                    SELECT COUNT(DISTINCT c.cedula) AS total
                    FROM cliente c
                    INNER JOIN base_clientes bc ON bc.id_base = c.base_id
                    WHERE bc.estado = 'activo'
                ");
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $clientesTotales = (int)($row['total'] ?? 0);
            } catch (Exception $e) {
                error_log("Error contando clientes totales: " . $e->getMessage());
            }
            
            // 3. Obligaciones Totales (ACTIVAS): Contar obligaciones (filas) en bases activas
            $obligacionesTotales = 0;
            try {
                $stmt = $db->query("
                    SELECT COUNT(*) AS total
                    FROM obligaciones o
                    INNER JOIN base_clientes bc ON bc.id_base = o.base_id
                    WHERE bc.estado = 'activo'
                ");
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $obligacionesTotales = (int)($row['total'] ?? 0);
            } catch (Exception $e) {
                error_log("Error contando obligaciones totales: " . $e->getMessage());
            }
            
            // 4. Bases Inactivas: Contar solo las bases con estado 'inactivo'
            $basesInactivas = 0;
            try {
                $stmt = $db->prepare("SELECT COUNT(*) AS total FROM base_clientes WHERE estado = 'inactivo'");
                $stmt->execute();
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $basesInactivas = (int)($row['total'] ?? 0);
            } catch (Exception $e) {
                error_log("Error contando bases inactivas: " . $e->getMessage());
            }
            
            return [
                'success' => true,
                'total_bases' => $totalBases,
                'clientes_totales' => $clientesTotales,
                'obligaciones_totales' => $obligacionesTotales,
                'bases_inactivas' => $basesInactivas,
            ];
        } catch (Exception $e) {
            error_log("CoordGestionController::obtenerEstadisticasBases - " . $e->getMessage());
            return [
                'success' => false, 
                'total_bases' => 0, 
                'clientes_totales' => 0,
                'obligaciones_totales' => 0,
                'bases_inactivas' => 0,
            ];
        }
    }

    /**
     * Carga archivo CSV a base_clientes, cliente y obligaciones.
     * Columnas CSV: operación, cuenta, oficina, cedula, nombre, años castigo, concepto mes actual,
     * estado proceso juridico, total, total a pagar, correo, dueño cartera, compra, tipo producto,
     * bucket saldo capital, departamento, dias mora actual, tel1..tel10
     * Obligatorios: cedula (numérica) y operación (texto, admite letras; columna obligaciones.operacion es VARCHAR).
     * tipo_carga: nueva (crea base) | existente (usa base_datos_id)
     */
    public function cargarCsv() {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');
        ignore_user_abort(true);
        @file_put_contents(dirname(__DIR__) . '/log_carga_diagnostico.txt', date('c') . " CoordGestionController::cargarCsv entrada\n", FILE_APPEND);

        $tipoCarga = $_POST['tipo_carga'] ?? '';
        $nombreArchivo = trim($_POST['nombre_archivo'] ?? '');
        $baseIdExistente = isset($_POST['base_datos_id']) ? (int)$_POST['base_datos_id'] : 0;

        if (empty($_FILES['csv_file']['tmp_name']) || !is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
            return ['success' => false, 'mensaje' => 'No se recibió ningún archivo CSV.'];
        }
        if ($tipoCarga === 'nueva' && $nombreArchivo === '') {
            return ['success' => false, 'mensaje' => 'Para carga nueva debe indicar el nombre del archivo/base.'];
        }
        if ($tipoCarga === 'existente' && $baseIdExistente <= 0) {
            return ['success' => false, 'mensaje' => 'Para carga existente debe seleccionar una base de datos.'];
        }

        $baseClienteModel = new BaseCliente();
        $obligacionModel = new Obligacion();

        if (!$baseClienteModel->tablaExiste()) {
            return ['success' => false, 'mensaje' => 'La tabla base_clientes no existe. Ejecute el script SQL de creación.'];
        }
        if (!$obligacionModel->tablaExiste()) {
            return ['success' => false, 'mensaje' => 'La tabla obligaciones no existe. Ejecute el script SQL de creación.'];
        }

        $baseId = 0;
        $creadoPor = $_SESSION['usuario_cedula'] ?? $_SESSION['usuario_id'] ?? null;
        if ($tipoCarga === 'nueva') {
            $campanaId = $this->campanaModel()->obtenerPrimeraCampanaIdDelCoordinador((string) $creadoPor);
            $baseId = $baseClienteModel->crear($nombreArchivo, 'activo', $creadoPor, $campanaId);
            if (!$baseId) {
                return ['success' => false, 'mensaje' => 'No se pudo crear la base de clientes. Verifique que el usuario esté logueado (creado_por).'];
            }
        } else {
            $base = $baseClienteModel->obtenerPorId($baseIdExistente);
            if (!$base) {
                return ['success' => false, 'mensaje' => 'La base de datos seleccionada no existe.'];
            }
            $estadoSel = strtolower(trim((string)($base['estado'] ?? '')));
            if ($estadoSel !== 'activo') {
                return ['success' => false, 'mensaje' => 'La base seleccionada no está habilitada. Habilítela en la pestaña HABILITAR antes de cargar un CSV.'];
            }
            $baseId = (int)$base['id'];
            if (!$this->coordinadorPuedeAccederBase((string) $creadoPor, $baseId)) {
                return ['success' => false, 'mensaje' => 'No tiene permiso para cargar en esta base.'];
            }
        }

        $separator = $_POST['separator'] ?? ',';
        if ($separator === '\\t') {
            $separator = "\t";
        }

        try {
            $resultado = $this->procesarCsvEnArchivo($_FILES['csv_file']['tmp_name'], $baseId, $tipoCarga, [
                'separator' => $separator,
                'has_header' => !empty($_POST['has_header']),
                'skip_empty' => !empty($_POST['skip_empty']),
                'encoding' => $_POST['encoding'] ?? 'utf-8',
            ]);
            if (!empty($resultado['success'])) {
                $this->auditarCoordinador('cargar_csv', 'base_clientes', (int) $baseId, [
                    'tipo_carga' => $tipoCarga,
                    'filas' => (int) ($resultado['filas_procesadas'] ?? 0),
                ]);
            }
            @file_put_contents(dirname(__DIR__) . '/log_carga_diagnostico.txt', date('c') . " CoordGestionController::cargarCsv retorno OK filas=" . ($resultado['filas_procesadas'] ?? 0) . "\n", FILE_APPEND);
            return $this->respuestaCargaCsvParaWeb($resultado);
        } catch (Throwable $e) {
            @file_put_contents(dirname(__DIR__) . '/log_carga_diagnostico.txt', date('c') . " CoordGestionController::cargarCsv EXCEPCION " . $e->getMessage() . "\n", FILE_APPEND);
            error_log('CoordGestionController::cargarCsv - ' . $e->getMessage());
            return ['success' => false, 'mensaje' => 'Error al procesar CSV: ' . $e->getMessage()];
        }
    }

    /**
     * Importa un CSV desde ruta local (scripts CLI). Misma lógica que cargarCsv en la vista.
     *
     * @param string $csvPath Ruta absoluta al archivo
     * @param string $tipoCarga nueva|existente
     * @param string|null $nombreBase Nombre de base (carga nueva)
     * @param int|null $baseIdExistente ID base (carga existente)
     * @param array $opciones separator, encoding, has_header, skip_empty
     */
    public function importarCsvDesdeRuta(
        string $csvPath,
        string $tipoCarga = 'nueva',
        ?string $nombreBase = null,
        ?int $baseIdExistente = null,
        array $opciones = []
    ): array {
        if (!is_file($csvPath)) {
            return ['success' => false, 'mensaje' => 'No existe el archivo CSV: ' . $csvPath];
        }
        if ($tipoCarga === 'nueva' && trim((string) $nombreBase) === '') {
            return ['success' => false, 'mensaje' => 'Para carga nueva debe indicar el nombre de la base.'];
        }
        if ($tipoCarga === 'existente' && (int) $baseIdExistente <= 0) {
            return ['success' => false, 'mensaje' => 'Para carga existente debe indicar base_datos_id.'];
        }

        $baseClienteModel = new BaseCliente();
        $obligacionModel = new Obligacion();

        if (!$baseClienteModel->tablaExiste()) {
            return ['success' => false, 'mensaje' => 'La tabla base_clientes no existe.'];
        }
        if (!$obligacionModel->tablaExiste()) {
            return ['success' => false, 'mensaje' => 'La tabla obligaciones no existe.'];
        }

        $baseId = 0;
        $creadoPor = $_SESSION['usuario_cedula'] ?? $_SESSION['usuario_id'] ?? 'script_cli';
        if ($tipoCarga === 'nueva') {
            $baseId = $baseClienteModel->crear(trim($nombreBase), 'activo', $creadoPor);
            if (!$baseId) {
                return ['success' => false, 'mensaje' => 'No se pudo crear la base de clientes.'];
            }
        } else {
            $base = $baseClienteModel->obtenerPorId((int) $baseIdExistente);
            if (!$base) {
                return ['success' => false, 'mensaje' => 'La base seleccionada no existe.'];
            }
            $baseId = (int) $base['id'];
        }

        $separator = $opciones['separator'] ?? ',';
        if ($separator === '\\t') {
            $separator = "\t";
        }

        try {
            $resultado = $this->procesarCsvEnArchivo($csvPath, $baseId, $tipoCarga, [
                'separator' => $separator,
                'has_header' => array_key_exists('has_header', $opciones) ? (bool) $opciones['has_header'] : true,
                'skip_empty' => array_key_exists('skip_empty', $opciones) ? (bool) $opciones['skip_empty'] : true,
                'encoding' => $opciones['encoding'] ?? 'utf-8',
            ]);
            $resultado['base_id'] = $baseId;
            $resultado['nombre_base'] = $nombreBase;
            return $resultado;
        } catch (Throwable $e) {
            error_log('CoordGestionController::importarCsvDesdeRuta - ' . $e->getMessage());
            return ['success' => false, 'mensaje' => 'Error al procesar CSV: ' . $e->getMessage()];
        }
    }

    /** Encabezados por defecto cuando el CSV no trae fila de títulos (27 columnas). */
    private function headersCsvPorDefecto(): array {
        return [
            'operacion', 'cuenta', 'oficina', 'cedula', 'nombre', 'años_castigo',
            'concepto_mes_actual', 'estado_proceso_juridico', 'total', 'total_a_pagar', 'email',
            'dueno_cartera', 'compra', 'tipo_producto', 'bucket_saldo_capital', 'departamento',
            'dias_mora_actual', 'tel1', 'tel2', 'tel3', 'tel4', 'tel5', 'tel6', 'tel7', 'tel8', 'tel9', 'tel10',
        ];
    }

    /** Convierte celdas de una fila CSV al encoding UTF-8 si hace falta. */
    private function convertirFilaCsvEncoding(array $row, string $encoding): array {
        foreach ($row as $i => $cell) {
            if ($cell === null || $cell === '') {
                continue;
            }
            $texto = (string) $cell;
            if (strtolower($encoding) !== 'utf-8') {
                $texto = @mb_convert_encoding($texto, 'UTF-8', $encoding) ?: $texto;
            }
            $row[$i] = function_exists('limpiarUtf8') ? limpiarUtf8($texto) : $texto;
        }
        return $row;
    }

    /**
     * Procesa un CSV fila a fila (streaming) con caché en memoria y commits por lote.
     * Optimizado para bases de decenas de miles de filas.
     */
    private function procesarCsvEnArchivo(string $path, int $baseId, string $tipoCarga, array $opciones): array {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');
        ignore_user_abort(true);

        $clienteModel = new Cliente();
        $obligacionModel = new Obligacion();
        $baseClienteModel = new BaseCliente();

        $separator = $opciones['separator'] ?? ',';
        if ($separator === '\\t') {
            $separator = "\t";
        }
        $hasHeader = array_key_exists('has_header', $opciones) ? (bool) $opciones['has_header'] : true;
        $skipEmpty = array_key_exists('skip_empty', $opciones) ? (bool) $opciones['skip_empty'] : true;
        $encoding = $opciones['encoding'] ?? 'utf-8';
        $tamLote = 500;
        $maxErrores = 100;

        $fh = @fopen($path, 'rb');
        if (!$fh) {
            return ['success' => false, 'mensaje' => 'No se pudo abrir el archivo CSV.'];
        }

        $headers = $hasHeader ? fgetcsv($fh, 0, $separator) : null;
        if ($hasHeader && ($headers === false || $headers === [null])) {
            fclose($fh);
            return ['success' => false, 'mensaje' => 'El archivo está vacío o no tiene encabezados válidos.'];
        }
        if (!$hasHeader) {
            rewind($fh);
            $headers = $this->headersCsvPorDefecto();
        } else {
            $headers = $this->convertirFilaCsvEncoding($headers, $encoding);
            $headers = array_map('trim', $headers);
            if (isset($headers[0])) {
                $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
            }
        }

        $map = $this->mapCsvHeaders($headers);
        $cacheClientes = [];
        $cacheObligaciones = [];

        $clientesCreados = 0;
        $clientesActualizados = 0;
        $obligacionesCreadas = 0;
        $obligacionesActualizadas = 0;
        $errores = [];
        $totalErrores = 0;
        $procesadas = 0;
        $numRow = $hasHeader ? 1 : 0;
        $filasLeidas = 0;

        $db = getDBConnection();
        $db->beginTransaction();
        $enTransaccion = true;
        $filasEnLote = 0;

        try {
            while (($row = fgetcsv($fh, 0, $separator)) !== false) {
                $numRow++;
                $filasLeidas++;
                if ($row === [null] || (count($row) === 1 && trim((string) ($row[0] ?? '')) === '')) {
                    if ($skipEmpty) {
                        continue;
                    }
                }
                $row = $this->convertirFilaCsvEncoding($row, $encoding);
                if ($skipEmpty && $this->filaCsvVacia($row)) {
                    continue;
                }
                if (count($row) < 2) {
                    continue;
                }

                $data = [];
                foreach ($map as $col => $index) {
                    $raw = isset($row[$index]) ? $row[$index] : '';
                    $data[$col] = trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $raw));
                }

                $cedula = trim(trim(preg_replace('/\s+/', '', $data['cedula'] ?? ''), "\"' \t\n\r"));
                $operacion = trim(trim(preg_replace('/\s+/', '', $data['operacion'] ?? ''), "\"' \t\n\r"));
                if ($cedula === '' || $operacion === '') {
                    if ($totalErrores < $maxErrores) {
                        $errores[] = "Fila $numRow: cedula y operación son obligatorios.";
                    }
                    $totalErrores++;
                    continue;
                }
                $cedulaNum = preg_replace('/[,]/', '', $cedula);
                if (!is_numeric($cedulaNum)) {
                    if ($totalErrores < $maxErrores) {
                        $errores[] = "Fila $numRow: la cédula debe ser numérica.";
                    }
                    $totalErrores++;
                    continue;
                }
                if (strlen($operacion) > 50) {
                    if ($totalErrores < $maxErrores) {
                        $errores[] = "Fila $numRow: la operación excede 50 caracteres.";
                    }
                    $totalErrores++;
                    continue;
                }
                $procesadas++;

                $nombreCliente = trim($data['nombre'] ?? '');
                $emailCliente = trim($data['email'] ?? '');
                if ($emailCliente === '-' || strtolower($emailCliente) === 'n/a') {
                    $emailCliente = '';
                }
                $clienteData = [
                    'base_id' => $baseId,
                    'cedula' => $cedula,
                    'nombre' => $nombreCliente !== '' ? $nombreCliente : '',
                    'email' => $emailCliente,
                    'ciudad' => '',
                    'departamento' => trim($data['departamento'] ?? ''),
                    'tel1' => trim($data['tel1'] ?? ''),
                    'tel2' => trim($data['tel2'] ?? ''),
                    'tel3' => trim($data['tel3'] ?? ''),
                    'tel4' => trim($data['tel4'] ?? ''),
                    'tel5' => trim($data['tel5'] ?? ''),
                    'tel6' => trim($data['tel6'] ?? ''),
                    'tel7' => trim($data['tel7'] ?? ''),
                    'tel8' => trim($data['tel8'] ?? ''),
                    'tel9' => trim($data['tel9'] ?? ''),
                    'tel10' => trim($data['tel10'] ?? ''),
                ];

                $existeCliente = $cacheClientes[$cedula] ?? null;
                if ($existeCliente === null) {
                    $existeCliente = $clienteModel->obtenerPorCedulaYBase($cedula, $baseId);
                    if ($existeCliente) {
                        $cacheClientes[$cedula] = $existeCliente;
                    }
                }

                if ($existeCliente && $tipoCarga === 'existente') {
                    foreach ($this->fusionarTelefonosCargaExistente($existeCliente, $data) as $tk => $tv) {
                        $clienteData[$tk] = $tv;
                    }
                }

                $idCliente = null;
                if ($existeCliente) {
                    $clienteModel->actualizar($existeCliente['id'], $clienteData);
                    $idCliente = (int) $existeCliente['id'];
                    $clientesActualizados++;
                    $cacheClientes[$cedula] = array_merge($existeCliente, $clienteData, ['id' => $idCliente]);
                } else {
                    $idCliente = (int) $clienteModel->crear($clienteData);
                    if ($idCliente > 0) {
                        $clientesCreados++;
                        $cacheClientes[$cedula] = array_merge($clienteData, ['id' => $idCliente]);
                    }
                }
                if ($idCliente <= 0) {
                    if ($totalErrores < $maxErrores) {
                        $errores[] = "Fila $numRow: no se pudo obtener id del cliente (cedula $cedula).";
                    }
                    $totalErrores++;
                    continue;
                }

                $obligData = [
                    'operacion' => $operacion,
                    'base_id' => $baseId,
                    'cliente_id' => $idCliente,
                    'cuenta_cliente' => trim($data['cuenta'] ?? $data['cuenta_cliente'] ?? ''),
                    'oficina' => trim($data['oficina'] ?? ''),
                    'año_castigo' => trim($data['años_castigo'] ?? $data['año_castigo'] ?? $data['ano_castigo'] ?? ''),
                    'concepto_mes_actual' => trim($data['concepto_mes_actual'] ?? '') ?: null,
                    'estado_proceso_juridico' => trim($data['estado_proceso_juridico'] ?? '') ?: null,
                    'total' => trim($data['total'] ?? '') !== '' ? $data['total'] : '0',
                    'total_a_pagar' => trim($data['total_a_pagar'] ?? '') !== '' ? $data['total_a_pagar'] : '0',
                    'dueno_cartera' => trim($data['dueno_cartera'] ?? ''),
                    'compra' => trim($data['compra'] ?? ''),
                    'tipo_producto' => trim($data['tipo_producto'] ?? ''),
                    'bucket_saldo_capital' => trim($data['bucket_saldo_capital'] ?? ''),
                    'dias_mora_actual' => Obligacion::normalizarDiasMora($data['dias_mora_actual'] ?? 0),
                ];

                $existeOblig = array_key_exists($operacion, $cacheObligaciones)
                    ? $cacheObligaciones[$operacion]
                    : null;
                if ($existeOblig === null) {
                    $existeOblig = $obligacionModel->obtenerPorOperacionYBase($operacion, $baseId);
                    $cacheObligaciones[$operacion] = $existeOblig ?: false;
                }

                if ($existeOblig) {
                    if ($obligacionModel->actualizarPorOperacionYBase($operacion, $baseId, $obligData)) {
                        $obligacionesActualizadas++;
                    }
                } else {
                    if ($obligacionModel->crear($obligData)) {
                        $obligacionesCreadas++;
                        $cacheObligaciones[$operacion] = true;
                    }
                }

                $filasEnLote++;
                if ($filasEnLote >= $tamLote) {
                    $db->commit();
                    $db->beginTransaction();
                    $filasEnLote = 0;
                }
            }

            if ($enTransaccion) {
                $db->commit();
                $enTransaccion = false;
            }
        } catch (Throwable $e) {
            if ($enTransaccion) {
                $db->rollBack();
            }
            fclose($fh);
            throw $e;
        }

        fclose($fh);

        $totalClientesEnBase = 0;
        $totalObligacionesEnBase = 0;
        if ($baseId > 0) {
            $baseClienteModel->actualizarContadores($baseId);
            $stmtCnt = $db->prepare("
                SELECT
                    (SELECT COUNT(*) FROM cliente WHERE base_id = ?) AS total_clientes,
                    (SELECT COUNT(*) FROM obligaciones WHERE base_id = ?) AS total_obligaciones
            ");
            $stmtCnt->execute([$baseId, $baseId]);
            $cnt = $stmtCnt->fetch(PDO::FETCH_ASSOC) ?: [];
            $totalClientesEnBase = (int) ($cnt['total_clientes'] ?? 0);
            $totalObligacionesEnBase = (int) ($cnt['total_obligaciones'] ?? 0);
        }

        if ($procesadas === 0) {
            $diag = $this->diagnosticarFalloCargaCsv($map, $headers, $filasLeidas, $totalErrores, $errores, $encoding);
            return $this->respuestaCargaCsvParaWeb(array_merge([
                'success' => false,
                'mensaje' => $diag['mensaje'],
                'message' => $diag['mensaje'],
                'codigo_error' => $diag['codigo'],
                'sugerencias' => $diag['sugerencias'],
                'base_id' => $baseId,
                'map_columnas' => $map,
                'encabezados' => $headers,
                'filas_leidas' => $filasLeidas,
                'filas_procesadas' => 0,
                'clientes_creados' => 0,
                'clientes_actualizados' => 0,
                'obligaciones_creadas' => 0,
                'obligaciones_actualizadas' => 0,
                'total_clientes_en_base' => $totalClientesEnBase,
                'total_obligaciones_en_base' => $totalObligacionesEnBase,
                'errores' => array_slice($errores, 0, 20),
                'total_errores' => $totalErrores,
            ], $diag['extra'] ?? []));
        }

        $mensaje = "Carga finalizada. Filas procesadas: $procesadas. Clientes nuevos: $clientesCreados, actualizados: $clientesActualizados. Obligaciones nuevas: $obligacionesCreadas, actualizadas: $obligacionesActualizadas.";
        if ($totalErrores > 0) {
            $mensaje .= ' Errores: ' . $totalErrores;
        }

        return $this->respuestaCargaCsvParaWeb([
            'success' => true,
            'mensaje' => $mensaje,
            'message' => $mensaje,
            'base_id' => $baseId,
            'filas_leidas' => $filasLeidas,
            'filas_procesadas' => $procesadas,
            'clientes_creados' => $clientesCreados,
            'clientes_actualizados' => $clientesActualizados,
            'obligaciones_creadas' => $obligacionesCreadas,
            'obligaciones_actualizadas' => $obligacionesActualizadas,
            'total_clientes_en_base' => $totalClientesEnBase,
            'total_obligaciones_en_base' => $totalObligacionesEnBase,
            'errores' => array_slice($errores, 0, 20),
            'total_errores' => $totalErrores,
        ]);
    }

    /** Prepara la respuesta de carga CSV para el navegador (UTF-8 seguro, payload reducido). */
    private function respuestaCargaCsvParaWeb(array $resultado): array {
        if (!empty($resultado['success'])) {
            unset($resultado['map_columnas'], $resultado['encabezados']);
        }
        return function_exists('sanitizarParaJson')
            ? sanitizarParaJson($resultado)
            : $resultado;
    }

    /** @param array<int, string|null> $row */
    private function filaCsvVacia(array $row): bool {
        foreach ($row as $cell) {
            if (trim((string) ($cell ?? '')) !== '') {
                return false;
            }
        }
        return true;
    }

    /**
     * Mensaje detallado cuando no se procesó ninguna fila válida.
     */
    private function diagnosticarFalloCargaCsv(
        array $map,
        array $headers,
        int $filasLeidas,
        int $totalErrores,
        array $errores,
        string $encoding
    ): array {
        $sugerencias = [];
        $extra = [];

        if ($filasLeidas === 0) {
            return [
                'codigo' => 'ARCHIVO_VACIO',
                'mensaje' => 'El archivo no tiene filas de datos después del encabezado. Verifique que el CSV no esté vacío.',
                'sugerencias' => [
                    'Confirme que el archivo tiene al menos una fila además de los títulos.',
                    'Si exportó desde Excel, guarde como CSV UTF-8 o Windows-1252 según la codificación seleccionada.',
                ],
            ];
        }

        if (!isset($map['cedula']) || !isset($map['operacion'])) {
            $enc = implode(' | ', array_slice($headers, 0, 8));
            $sugerencias[] = 'Use la plantilla de 27 columnas o incluya columnas IDENTIFICACION y OPERACIÓN.';
            $sugerencias[] = 'Encabezados detectados: ' . $enc . (count($headers) > 8 ? '…' : '');
            return [
                'codigo' => 'COLUMNAS_NO_RECONOCIDAS',
                'mensaje' => 'No se reconocieron las columnas obligatorias IDENTIFICACION (cédula) y/o OPERACIÓN en el encabezado del CSV.',
                'sugerencias' => $sugerencias,
                'extra' => ['columnas_mapeadas' => $map],
            ];
        }

        if ($totalErrores > 0) {
            $ejemplo = $errores[0] ?? '';
            $sugerencias[] = 'Revise cédula (numérica) y operación (obligatoria, admite letras y números).';
            $sugerencias[] = 'Codificación actual: ' . $encoding . '. Pruebe Windows-1252 si los títulos tienen caracteres raros.';
            return [
                'codigo' => 'FILAS_CON_ERRORES',
                'mensaje' => "Se leyeron {$filasLeidas} filas pero ninguna fue válida. Errores encontrados: {$totalErrores}. Ejemplo: {$ejemplo}",
                'sugerencias' => $sugerencias,
            ];
        }

        return [
            'codigo' => 'SIN_DATOS_VALIDOS',
            'mensaje' => "Se leyeron {$filasLeidas} filas pero ninguna cumplió los requisitos (cédula numérica, operación presente, filas no vacías).",
            'sugerencias' => [
                'Verifique el separador (coma, punto y coma) en Configuración de Carga.',
                'Marque «El archivo tiene encabezados» si la primera fila son títulos.',
                'Codificación: pruebe UTF-8 o Windows-1252 según cómo exportó el archivo.',
            ],
        ];
    }

    /**
     * Carga existente: fusiona tel1–tel10 del cliente en BD con los del CSV.
     * - Conserva todos los números ya guardados (orden tel1…tel10).
     * - Añade números del CSV que sean distintos (comparación por dígitos); no duplica.
     * - Celdas vacías en el CSV no borran teléfonos existentes.
     *
     * @param array $filaCliente fila de cliente (tel1…tel10)
     * @param array $dataCsv fila mapeada del CSV (mismas claves tel1…tel10)
     * @return array<string,string> tel1…tel10
     */
    private function fusionarTelefonosCargaExistente(array $filaCliente, array $dataCsv): array {
        $normalizarParaClave = static function (string $num): string {
            $digits = preg_replace('/\D+/', '', $num);
            return $digits !== '' ? $digits : trim($num);
        };

        $merged = [];
        $seen = [];

        for ($i = 1; $i <= 10; $i++) {
            $k = 'tel' . $i;
            $raw = trim((string)($filaCliente[$k] ?? ''));
            if ($raw === '') {
                continue;
            }
            $nk = $normalizarParaClave($raw);
            if ($nk === '') {
                continue;
            }
            if (isset($seen[$nk])) {
                continue;
            }
            $seen[$nk] = true;
            $merged[] = $raw;
        }

        for ($i = 1; $i <= 10; $i++) {
            $k = 'tel' . $i;
            $raw = trim((string)($dataCsv[$k] ?? ''));
            if ($raw === '') {
                continue;
            }
            $nk = $normalizarParaClave($raw);
            if ($nk === '') {
                continue;
            }
            if (isset($seen[$nk])) {
                continue;
            }
            $seen[$nk] = true;
            $merged[] = $raw;
        }

        if (count($merged) > 10) {
            $merged = array_slice($merged, 0, 10);
        }
        while (count($merged) < 10) {
            $merged[] = '';
        }

        $out = [];
        for ($i = 0; $i < 10; $i++) {
            $out['tel' . ($i + 1)] = $merged[$i] ?? '';
        }
        return $out;
    }

    /**
     * Mapea nombres de columnas del CSV (con acentos/espacios) a índices y nombres estándar
     */
    private function mapCsvHeaders(array $headers) {
        $aliases = [
            'operacion' => ['operacion', 'operación', 'operacion'],
            'cuenta' => ['cuenta', 'cuenta cleinte', 'cuenta cliente', 'cuenta_cliente'],
            'oficina' => ['oficina'],
            'cedula' => ['cedula', 'cédula', 'identificacion', 'IDENTIFICACION'],
            'nombre' => ['nombre', 'nombre cliente', 'nombre_cliente'],
            'años_castigo' => ['años castigo', 'año de castigo', 'años_castigo', 'anos castigo', 'año_castigo', 'ano castigo'],
            'concepto_mes_actual' => ['concepto mes actual', 'concepto_mes_actual'],
            'estado_proceso_juridico' => ['estado proceso juridico', 'estado_proceso_juridico', 'estado proceso jurídico'],
            'total' => ['total', 'total obligacion', 'total obligación', 'total_obligacion'],
            'total_a_pagar' => ['total a pagar', 'total_a_pagar', 'total a pagar', 'saldo capital', 'saldo_capital'],
            // Nueva columna de correo/e-mail para clientes
            'email' => ['email', 'correo', 'correo electronico', 'correo electrónico'],
            'dueno_cartera' => ['dueno cartera', 'dueño cartera', 'dueno_cartera', 'dueño_cartera', 'owner cartera'],
            'compra' => ['compra'],
            'tipo_producto' => ['tipo producto', 'tipo_producto', 'tipo de producto'],
            'bucket_saldo_capital' => ['bucket saldo capital', 'bucket_saldo_capital', 'bucket capital'],
            'departamento' => ['departamento', 'depto'],
            'dias_mora_actual' => ['dias mora actual', 'días mora actual', 'dias_mora_actual', 'dias de mora', 'dias mora'],
            'tel1' => ['tel1'], 'tel2' => ['tel2'], 'tel3' => ['tel3'], 'tel4' => ['tel4'], 'tel5' => ['tel5'],
            'tel6' => ['tel6'], 'tel7' => ['tel7'], 'tel8' => ['tel8'], 'tel9' => ['tel9'], 'tel10' => ['tel10'],
        ];
        $map = [];
        
        // Normalizar headers: quitar espacios extra, convertir a minúsculas, normalizar caracteres especiales
        foreach ($headers as $i => $raw) {
            $h = strtolower(trim($raw));
            // Normalizar: quitar espacios múltiples, reemplazar caracteres especiales
            $hNorm = preg_replace('/\s+/', ' ', $h);
            $hNorm = str_replace(['á', 'é', 'í', 'ó', 'ú', 'ñ', 'Á', 'É', 'Í', 'Ó', 'Ú', 'Ñ'], 
                                 ['a', 'e', 'i', 'o', 'u', 'n', 'a', 'e', 'i', 'o', 'u', 'n'], $hNorm);
            $hNormSinEspacios = str_replace(' ', '_', $hNorm);
            
            // Buscar coincidencias en aliases (solo si aún no está mapeado)
            foreach ($aliases as $key => $vars) {
                if (isset($map[$key])) {
                    continue; // Ya mapeado, no sobrescribir
                }
                foreach ($vars as $v) {
                    $vLower = strtolower($v);
                    $vNorm = preg_replace('/\s+/', ' ', $vLower);
                    $vNorm = str_replace(['á', 'é', 'í', 'ó', 'ú', 'ñ'], ['a', 'e', 'i', 'o', 'u', 'n'], $vNorm);
                    $vNormSinEspacios = str_replace(' ', '_', $vNorm);
                    
                    // Coincidencia exacta
                    if ($h === $vLower || $hNormSinEspacios === $vNormSinEspacios) {
                        $map[$key] = $i;
                        break 2;
                    }
                    
                    // Coincidencia parcial específica
                    if ($key === 'operacion' && (strpos($hNorm, 'operacion') !== false || strpos($hNorm, 'oper') !== false)) {
                        $map[$key] = $i;
                        break 2;
                    }
                    if ($key === 'cuenta' && (strpos($hNorm, 'cuenta') !== false || strpos($hNorm, 'cleinte') !== false)) {
                        $map[$key] = $i;
                        break 2;
                    }
                    if ($key === 'nombre' && strpos($hNorm, 'nombre') !== false && strpos($hNorm, 'cliente') !== false) {
                        $map[$key] = $i;
                        break 2;
                    }
                    if ($key === 'años_castigo' && (strpos($hNorm, 'castigo') !== false || strpos($hNorm, 'ano') !== false || strpos($hNorm, 'año') !== false)) {
                        $map[$key] = $i;
                        break 2;
                    }
                    if ($key === 'total_a_pagar' && (
                        (strpos($hNorm, 'total') !== false && strpos($hNorm, 'pagar') !== false)
                        || (strpos($hNorm, 'saldo') !== false && strpos($hNorm, 'capital') !== false && strpos($hNorm, 'bucket') === false)
                    )) {
                        $map[$key] = $i;
                        break 2;
                    }
                    if ($key === 'total' && (
                        (strpos($hNorm, 'total') !== false && strpos($hNorm, 'pagar') === false && strpos($hNorm, 'bucket') === false && strpos($hNorm, 'oblig') === false)
                        || (strpos($hNorm, 'total') !== false && strpos($hNorm, 'oblig') !== false)
                    )) {
                        $map[$key] = $i;
                        break 2;
                    }
                    if ($key === 'dueno_cartera' && strpos($hNorm, 'cartera') !== false && (
                        strpos($hNorm, 'dueno') !== false || strpos($hNorm, 'dueño') !== false || strpos($hNorm, 'due') !== false
                    )) {
                        $map[$key] = $i;
                        break 2;
                    }
                    if ($key === 'tipo_producto' && strpos($hNorm, 'tipo') !== false && strpos($hNorm, 'producto') !== false) {
                        $map[$key] = $i;
                        break 2;
                    }
                    if ($key === 'bucket_saldo_capital' && strpos($hNorm, 'bucket') !== false) {
                        $map[$key] = $i;
                        break 2;
                    }
                    if ($key === 'dias_mora_actual' && strpos($hNorm, 'mora') !== false) {
                        $map[$key] = $i;
                        break 2;
                    }
                    if ($key === 'departamento' && strpos($hNorm, 'departamento') !== false) {
                        $map[$key] = $i;
                        break 2;
                    }
                }
            }
        }
        
        // Fallbacks por posición solo en plantilla estándar (21 o 27 columnas)
        $esPlantillaEstandar = count($headers) >= 21;
        if ($esPlantillaEstandar) {
            if (!isset($map['operacion']) && isset($headers[0])) {
                $map['operacion'] = 0;
            }
            if (!isset($map['cuenta']) && isset($headers[1])) {
                $map['cuenta'] = 1;
            }
            if (!isset($map['oficina']) && isset($headers[2])) {
                $map['oficina'] = 2;
            }
            if (!isset($map['cedula']) && isset($headers[3])) {
                $map['cedula'] = 3;
            }
            if (!isset($map['nombre']) && isset($headers[4])) {
                $map['nombre'] = 4;
            }
            if (!isset($map['años_castigo']) && isset($headers[5])) {
                $map['años_castigo'] = 5;
            }
            if (!isset($map['concepto_mes_actual']) && isset($headers[6])) {
                $map['concepto_mes_actual'] = 6;
            }
            if (!isset($map['estado_proceso_juridico']) && isset($headers[7])) {
                $map['estado_proceso_juridico'] = 7;
            }
            if (!isset($map['total']) && isset($headers[8])) {
                $map['total'] = 8;
            }
            if (!isset($map['total_a_pagar']) && isset($headers[9])) {
                $map['total_a_pagar'] = 9;
            }
            if (!isset($map['email']) && isset($headers[10])) {
                $map['email'] = 10;
            }
            if (!isset($map['dueno_cartera']) && isset($headers[11])) {
                $map['dueno_cartera'] = 11;
            }
            if (!isset($map['compra']) && isset($headers[12])) {
                $map['compra'] = 12;
            }
            if (!isset($map['tipo_producto']) && isset($headers[13])) {
                $map['tipo_producto'] = 13;
            }
            if (!isset($map['bucket_saldo_capital']) && isset($headers[14])) {
                $map['bucket_saldo_capital'] = 14;
            }
            if (!isset($map['departamento']) && isset($headers[15])) {
                $map['departamento'] = 15;
            }
            if (!isset($map['dias_mora_actual']) && isset($headers[16])) {
                $map['dias_mora_actual'] = 16;
            }
            for ($t = 1; $t <= 10; $t++) {
                $idx = 16 + $t;
                if (!isset($map["tel$t"]) && isset($headers[$idx])) {
                    $map["tel$t"] = $idx;
                }
            }
        }
        
        return $map;
    }

    /**
     * Obtiene asesores SIN acceso a una base específica
     * @return array{success: bool, asesores?: array, message?: string}
     */
    public function obtenerAsesoresSinAcceso() {
        try {
            $baseId = $_GET['base_id'] ?? null;
            if (!$baseId) {
                return ['success' => false, 'message' => 'Base ID requerido', 'asesores' => []];
            }

            $db = getDBConnection();
            $usuarioModel = new Usuario();
            
            // Obtener todos los asesores asignados al coordinador
            $cedula = $this->coordinadorCedula();
            if (!$cedula) {
                return ['success' => false, 'message' => 'No autorizado', 'asesores' => []];
            }

            $todosAsesores = [];
            foreach ($this->asesoresListaCoordinador($cedula) as $u) {
                $todosAsesores[$u['cedula']] = $u;
            }

            // Obtener asesores que YA tienen acceso a esta base
            $stmt = $db->prepare("SELECT asesor_cedula FROM asignacion_base_asesores WHERE base_id = ? AND estado = 'activa'");
            $stmt->execute([$baseId]);
            $asesoresConAcceso = $stmt->fetchAll(PDO::FETCH_COLUMN);

            // Filtrar: solo los que NO tienen acceso
            $asesoresSinAcceso = [];
            foreach ($todosAsesores as $cedula => $asesor) {
                if (!in_array($cedula, $asesoresConAcceso)) {
                    $asesoresSinAcceso[] = $asesor;
                }
            }

            return ['success' => true, 'asesores' => $asesoresSinAcceso];
        } catch (Exception $e) {
            error_log("CoordGestionController::obtenerAsesoresSinAcceso - " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'asesores' => []];
        }
    }

    /**
     * Obtiene asesores CON acceso a una base específica (solo asesores asignados al coordinador)
     * @return array{success: bool, asesores?: array, message?: string}
     */
    public function obtenerAsesoresAccesoBase() {
        try {
            $baseId = $_GET['base_id'] ?? null;
            if (!$baseId) {
                return ['success' => false, 'message' => 'Base ID requerido', 'asesores' => []];
            }

            $coordinadorCedula = $this->coordinadorCedula();
            if (!$coordinadorCedula) {
                return ['success' => false, 'message' => 'No autorizado', 'asesores' => []];
            }

            $db = getDBConnection();
            $usuarioModel = new Usuario();
            $asesoresCoordinador = $this->cedulasAsesoresCoordinador($coordinadorCedula);
            
            if (empty($asesoresCoordinador)) {
                return ['success' => true, 'asesores' => []];
            }
            
            // Obtener asesores con acceso a esta base que estén asignados al coordinador
            $placeholders = implode(',', array_fill(0, count($asesoresCoordinador), '?'));
            $stmt = $db->prepare("
                SELECT aba.asesor_cedula, aba.fecha_asignacion, aba.estado
                FROM asignacion_base_asesores aba
                WHERE aba.base_id = ? 
                AND aba.estado = 'activa'
                AND aba.asesor_cedula IN ($placeholders)
                ORDER BY aba.fecha_asignacion DESC
            ");
            $params = array_merge([$baseId], $asesoresCoordinador);
            $stmt->execute($params);
            $accesos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $asesores = [];
            foreach ($accesos as $acceso) {
                $asesor = $usuarioModel->obtenerPorCedula($acceso['asesor_cedula']);
                if ($asesor && strtolower($asesor['rol'] ?? '') === 'asesor') {
                    $asesor['nombre_completo'] = $asesor['nombre_completo'] ?? $asesor['nombre'] ?? '';
                    // Consistencia con el frontend (coord-comercio-factura.js espera asesor_cedula)
                    $asesor['asesor_cedula'] = $acceso['asesor_cedula'];
                    // Mantener estado del usuario y exponer estado del acceso por separado
                    $asesor['fecha_asignacion'] = $acceso['fecha_asignacion'];
                    $asesor['asignacion_estado'] = $acceso['estado'];
                    $asesores[] = $asesor;
                }
            }

            return ['success' => true, 'asesores' => $asesores];
        } catch (Exception $e) {
            error_log("CoordGestionController::obtenerAsesoresAccesoBase - " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'asesores' => []];
        }
    }

    /**
     * Guarda acceso de asesores a una base de clientes en la tabla asignacion_base_asesores
     * @return array{success: bool, message: string, insertados?: int, actualizados?: int}
     */
    public function guardarAccesoBase() {
        try {
            $baseId = $_POST['base_id'] ?? null;
            $asesoresJson = $_POST['asesores'] ?? '[]';
            
            if (!$baseId) {
                return ['success' => false, 'message' => 'Base ID requerido'];
            }
            
            $asesoresIds = json_decode($asesoresJson, true);
            if (!is_array($asesoresIds) || empty($asesoresIds)) {
                return ['success' => false, 'message' => 'Debe seleccionar al menos un asesor'];
            }
            
            $coordinadorCedula = $this->coordinadorCedula();
            if (!$coordinadorCedula) {
                return ['success' => false, 'message' => 'No autorizado'];
            }
            
            $db = getDBConnection();
            $usuarioModel = new Usuario();
            $asesoresCoordinador = $this->cedulasAsesoresCoordinador($coordinadorCedula);
            
            $asesoresValidos = [];
            foreach ($asesoresIds as $asesorCedula) {
                if (!in_array($asesorCedula, $asesoresCoordinador)) {
                    continue; // Saltar asesores no asignados al coordinador
                }
                
                // Verificar que el asesor existe y es de tipo asesor
                $asesor = $usuarioModel->obtenerPorCedula($asesorCedula);
                if ($asesor && strtolower($asesor['rol'] ?? '') === 'asesor') {
                    $asesoresValidos[] = $asesorCedula;
                }
            }
            
            if (empty($asesoresValidos)) {
                return ['success' => false, 'message' => 'No hay asesores válidos para asignar. Asegúrese de que los asesores estén asignados al coordinador.'];
            }
            
            // Insertar o actualizar acceso en asignacion_base_asesores
            $insertados = 0;
            $actualizados = 0;
            
            foreach ($asesoresValidos as $asesorCedula) {
                // Verificar si ya existe acceso
                $stmt = $db->prepare("SELECT id_base_asesor, estado FROM asignacion_base_asesores WHERE base_id = ? AND asesor_cedula = ?");
                $stmt->execute([$baseId, $asesorCedula]);
                $existente = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existente) {
                    // Si existe pero está inactiva, activarla
                    if ($existente['estado'] === 'inactiva') {
                        $stmt = $db->prepare("UPDATE asignacion_base_asesores SET estado = 'activa', fecha_actualizacion = NOW() WHERE id_base_asesor = ?");
                        $stmt->execute([$existente['id_base_asesor']]);
                        $actualizados++;
                    }
                    // Si ya está activa, no hacer nada
                } else {
                    // Insertar nuevo acceso
                    $stmt = $db->prepare("INSERT INTO asignacion_base_asesores (base_id, asesor_cedula, estado, fecha_asignacion) VALUES (?, ?, 'activa', NOW())");
                    $stmt->execute([$baseId, $asesorCedula]);
                    $insertados++;
                }
            }
            
            $mensaje = "Acceso otorgado exitosamente. ";
            if ($insertados > 0) {
                $mensaje .= "$insertados asesor(es) nuevo(s) con acceso. ";
            }
            if ($actualizados > 0) {
                $mensaje .= "$actualizados acceso(s) reactivado(s).";
            }

            $this->auditarCoordinador('asignar_acceso_base', 'base_clientes', (int) $baseId, [
                'asesores' => $asesoresValidos,
                'insertados' => $insertados,
                'actualizados' => $actualizados,
            ]);
            
            return [
                'success' => true,
                'message' => trim($mensaje),
                'insertados' => $insertados,
                'actualizados' => $actualizados,
            ];
        } catch (Exception $e) {
            error_log("CoordGestionController::guardarAccesoBase - " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Obtiene clientes disponibles para asignar (que no tengan tareas pendientes)
     * @return array{success: bool, clientes_disponibles?: int, message?: string}
     */
    public function obtenerClientesDisponibles() {
        try {
            $baseId = $_GET['base_id'] ?? null;
            if (!$baseId) {
                return ['success' => false, 'message' => 'Base ID requerido', 'clientes_disponibles' => 0];
            }

            $db = getDBConnection();
            $tareaModel = new Tarea();
            
            // Obtener total de clientes en la base
            $stmt = $db->prepare("SELECT COUNT(*) AS total FROM cliente WHERE base_id = ?");
            $stmt->execute([$baseId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $totalClientes = (int)($row['total'] ?? 0);
            
            // Obtener clientes ya asignados en tareas pendientes o en progreso
            $clientesAsignados = $tareaModel->obtenerClientesAsignados($baseId);
            $totalAsignados = count($clientesAsignados);
            
            // Clientes disponibles = total - asignados
            $clientesDisponibles = max(0, $totalClientes - $totalAsignados);
            
            return [
                'success' => true,
                'clientes_disponibles' => $clientesDisponibles,
                'total_clientes' => $totalClientes,
                'clientes_asignados' => $totalAsignados,
            ];
        } catch (Exception $e) {
            error_log("CoordGestionController::obtenerClientesDisponibles - " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'clientes_disponibles' => 0];
        }
    }

    /**
     * Crea una asignación de clientes a un asesor (crea una tarea)
     * @return array{success: bool, message: string, tarea_id?: int}
     */
    public function crearAsignacionClientes() {
        try {
            $baseId = $_POST['base_id'] ?? null;
            $asesorCedula = $_POST['asesor_cedula'] ?? null;
            $cantidadClientes = isset($_POST['cantidad_clientes']) ? (int)$_POST['cantidad_clientes'] : 0;
            $coordinadorCedula = $this->coordinadorCedula();
            
            if (!$baseId || !$asesorCedula || !$coordinadorCedula) {
                return ['success' => false, 'message' => 'Faltan datos requeridos'];
            }
            
            if ($cantidadClientes <= 0) {
                return ['success' => false, 'message' => 'La cantidad de clientes debe ser mayor a 0'];
            }
            
            // Verificar que el asesor tenga acceso a la base
            $db = getDBConnection();
            $stmt = $db->prepare("SELECT COUNT(*) AS n FROM asignacion_base_asesores WHERE base_id = ? AND asesor_cedula = ? AND estado = 'activa'");
            $stmt->execute([$baseId, $asesorCedula]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ((int)($row['n'] ?? 0) === 0) {
                return ['success' => false, 'message' => 'El asesor no tiene acceso a esta base de clientes'];
            }
            
            // Obtener clientes disponibles
            $tareaModel = new Tarea();
            $clientesAsignados = $tareaModel->obtenerClientesAsignados($baseId);
            
            // Obtener clientes de la base que no estén asignados (LIMIT debe ser entero en MariaDB/MySQL)
            $clienteModel = new Cliente();
            $limit = (int)($cantidadClientes + count($clientesAsignados));
            $limit = max(1, min($limit, 100000));
            $stmt = $db->prepare("SELECT id_cliente FROM cliente WHERE base_id = ? ORDER BY id_cliente LIMIT " . $limit);
            $stmt->execute([$baseId]);
            $todosClientes = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Filtrar clientes no asignados
            $clientesDisponibles = array_diff($todosClientes, $clientesAsignados);
            $clientesParaAsignar = array_slice($clientesDisponibles, 0, $cantidadClientes);
            
            if (count($clientesParaAsignar) < $cantidadClientes) {
                return ['success' => false, 'message' => 'No hay suficientes clientes disponibles. Disponibles: ' . count($clientesDisponibles)];
            }
            
            // Obtener obligaciones relacionadas a estos clientes
            $obligacionesAsignadas = [];
            if (!empty($clientesParaAsignar)) {
                $placeholders = implode(',', array_fill(0, count($clientesParaAsignar), '?'));
                $stmt = $db->prepare("SELECT operacion FROM obligaciones WHERE cliente_id IN ($placeholders)");
                $stmt->execute($clientesParaAsignar);
                $obligacionesAsignadas = $stmt->fetchAll(PDO::FETCH_COLUMN);
            }
            
            // Nombre de la tarea: opcional desde POST; si no viene, el modelo genera uno por defecto
            $nombreTarea = isset($_POST['nombre_tarea']) ? trim((string)$_POST['nombre_tarea']) : null;
            
            // Crear la tarea
            $tareaId = $tareaModel->crear([
                'nombre_tarea' => $nombreTarea,
                'coordinador_cedula' => $coordinadorCedula,
                'asesor_cedula' => $asesorCedula,
                'base_id' => $baseId,
                'clientes_asignados' => $clientesParaAsignar,
                'obligaciones_asignadas' => $obligacionesAsignadas,
            ]);
            
            if ($tareaId) {
                $tareaModel->insertarDetalleTareas($tareaId, $clientesParaAsignar);
                $this->auditarCoordinador('crear_tarea', 'tareas', (int) $tareaId, [
                    'asesor_cedula' => $asesorCedula,
                    'base_id' => (int) $baseId,
                    'clientes' => count($clientesParaAsignar),
                ]);
                return [
                    'success' => true,
                    'message' => "Se asignaron {$cantidadClientes} clientes exitosamente",
                    'tarea_id' => $tareaId,
                    'clientes_asignados' => count($clientesParaAsignar),
                    'obligaciones_asignadas' => count($obligacionesAsignadas),
                ];
            }
            
            return ['success' => false, 'message' => 'Error al crear la asignación'];
        } catch (Exception $e) {
            error_log("CoordGestionController::crearAsignacionClientes - " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Obtiene todas las tareas del coordinador logueado
     * @return array{success: bool, asignaciones?: array, message?: string}
     */
    public function obtenerTareasCoordinador() {
        try {
            $coordinadorCedula = $this->coordinadorCedula();
            if (!$coordinadorCedula) {
                return ['success' => false, 'message' => 'No autorizado', 'asignaciones' => []];
            }
            
            $tareaModel = new Tarea();
            $tareas = $tareaModel->obtenerPorCoordinador($coordinadorCedula);
            
            return ['success' => true, 'asignaciones' => $tareas, 'data' => $tareas];
        } catch (Exception $e) {
            error_log("CoordGestionController::obtenerTareasCoordinador - " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'asignaciones' => []];
        }
    }

    /**
     * Completa una tarea cambiando su estado a 'completa'
     * @return array{success: bool, message: string}
     */
    public function completarTarea() {
        try {
            $tareaId = $_POST['tarea_id'] ?? null;
            if (!$tareaId) {
                return ['success' => false, 'message' => 'ID de tarea requerido'];
            }

            $coordinadorCedula = $this->coordinadorCedula();
            if (!$coordinadorCedula) {
                return ['success' => false, 'message' => 'No autorizado'];
            }

            // Verificar que la tarea pertenezca al coordinador
            $tareaModel = new Tarea();
            $tareas = $tareaModel->obtenerPorCoordinador($coordinadorCedula);
            $tareaExiste = false;
            foreach ($tareas as $tarea) {
                if ($tarea['id_tarea'] == $tareaId) {
                    $tareaExiste = true;
                    break;
                }
            }

            if (!$tareaExiste) {
                return ['success' => false, 'message' => 'Tarea no encontrada o no autorizado'];
            }

            // Actualizar estado a 'completa'
            if ($tareaModel->actualizarEstado($tareaId, 'completa')) {
                return ['success' => true, 'message' => 'Tarea completada exitosamente'];
            }

            return ['success' => false, 'message' => 'Error al actualizar la tarea'];
        } catch (Exception $e) {
            error_log("CoordGestionController::completarTarea - " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Obtiene valores únicos para los filtros de obligaciones
     * @return array{success: bool, oficinas?: array, anos_castigo?: array, conceptos?: array}
     */
    public function obtenerValoresFiltros() {
        try {
            $baseId = $_GET['base_id'] ?? null;
            if (!$baseId) {
                return ['success' => false, 'message' => 'Base ID requerido'];
            }

            $db = getDBConnection();
            
            // Obtener oficinas únicas
            $stmt = $db->prepare("SELECT DISTINCT oficina FROM obligaciones WHERE base_id = ? AND oficina != '' ORDER BY oficina");
            $stmt->execute([$baseId]);
            $oficinas = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Obtener años de castigo únicos
            $stmt = $db->prepare("SELECT DISTINCT año_castigo FROM obligaciones WHERE base_id = ? AND año_castigo != '' ORDER BY año_castigo DESC");
            $stmt->execute([$baseId]);
            $anosCastigo = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Obtener conceptos únicos
            $stmt = $db->prepare("SELECT DISTINCT concepto_mes_actual FROM obligaciones WHERE base_id = ? AND concepto_mes_actual IS NOT NULL AND concepto_mes_actual != '' ORDER BY concepto_mes_actual");
            $stmt->execute([$baseId]);
            $conceptos = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Valores únicos de tipificación (historial_gestion) para clientes de esta base
            $canales = [];
            $niveles1 = [];
            $niveles2 = [];
            $stmt = $db->prepare("
                SELECT DISTINCT hg.canal_contacto, hg.nivel1_tipo, hg.nivel2_tipo
                FROM historial_gestion hg
                INNER JOIN cliente c ON hg.cliente_id = c.id_cliente
                WHERE c.base_id = ? AND TRIM(COALESCE(hg.canal_contacto,'')) != ''
                ORDER BY hg.canal_contacto, hg.nivel1_tipo, hg.nivel2_tipo
            ");
            $stmt->execute([$baseId]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if ($row['canal_contacto'] !== null && $row['canal_contacto'] !== '') {
                    $canales[$row['canal_contacto']] = true;
                }
                if (!empty(trim((string)($row['nivel1_tipo'] ?? '')))) {
                    $niveles1[$row['nivel1_tipo']] = true;
                }
                if (!empty(trim((string)($row['nivel2_tipo'] ?? '')))) {
                    $niveles2[$row['nivel2_tipo']] = true;
                }
            }
            $canales = array_keys($canales);
            $niveles1 = array_keys($niveles1);
            $niveles2 = array_keys($niveles2);
            sort($canales);
            sort($niveles1);
            sort($niveles2);
            
            return [
                'success' => true,
                'oficinas' => $oficinas,
                'anos_castigo' => $anosCastigo,
                'conceptos' => $conceptos,
                'canales_contacto' => $canales,
                'nivel1_tipos' => $niveles1,
                'nivel2_tipos' => $niveles2,
            ];
        } catch (Exception $e) {
            error_log("CoordGestionController::obtenerValoresFiltros - " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Aplica filtros a las obligaciones y devuelve los clientes que cumplen los criterios
     * @return array{success: bool, clientes?: array, total_clientes?: int, total_obligaciones?: int}
     */
    public function aplicarFiltrosObligaciones() {
        try {
            $baseId = $_POST['base_id'] ?? null;
            $asesorCedula = $_POST['asesor_cedula'] ?? null;
            $filtrosJson = $_POST['filtros'] ?? '{}';
            
            if (!$baseId || !$asesorCedula) {
                return ['success' => false, 'message' => 'Base ID y Asesor requeridos'];
            }
            
            // Verificar que el asesor tenga acceso a la base
            $db = getDBConnection();
            $stmt = $db->prepare("SELECT COUNT(*) AS n FROM asignacion_base_asesores WHERE base_id = ? AND asesor_cedula = ? AND estado = 'activa'");
            $stmt->execute([$baseId, $asesorCedula]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ((int)($row['n'] ?? 0) === 0) {
                return ['success' => false, 'message' => 'El asesor no tiene acceso a esta base'];
            }
            
            $filtros = json_decode($filtrosJson, true);
            if (!is_array($filtros)) {
                return ['success' => false, 'message' => 'Filtros inválidos'];
            }
            
            // Construir consulta SQL con filtros
            $sql = "SELECT DISTINCT o.cliente_id, c.cedula, c.nombre 
                    FROM obligaciones o
                    INNER JOIN cliente c ON o.cliente_id = c.id_cliente
                    WHERE o.base_id = ?";
            
            $params = [$baseId];
            
            // Aplicar filtros
            if (isset($filtros['oficina'])) {
                $sql .= " AND o.oficina = ?";
                $params[] = $filtros['oficina'];
            }
            
            if (isset($filtros['ano_castigo'])) {
                $sql .= " AND o.año_castigo = ?";
                $params[] = $filtros['ano_castigo'];
            }
            
            if (isset($filtros['concepto_mes_actual'])) {
                $sql .= " AND o.concepto_mes_actual = ?";
                $params[] = $filtros['concepto_mes_actual'];
            }
            
            if (isset($filtros['estado_proceso_juridico'])) {
                $sql .= " AND o.estado_proceso_juridico = ?";
                $params[] = $filtros['estado_proceso_juridico'];
            }
            
            if (isset($filtros['total']) && is_array($filtros['total'])) {
                $operador = $filtros['total']['operador'] ?? '=';
                $valor = $filtros['total']['valor'] ?? 0;
                $sql .= " AND o.total " . $operador . " ?";
                $params[] = $valor;
            }
            
            if (isset($filtros['total_a_pagar']) && is_array($filtros['total_a_pagar'])) {
                $operador = $filtros['total_a_pagar']['operador'] ?? '=';
                $valor = $filtros['total_a_pagar']['valor'] ?? 0;
                $sql .= " AND o.total_a_pagar " . $operador . " ?";
                $params[] = $valor;
            }
            
            // Filtro por árbol de tipificación (historial_gestion): canal, nivel1, nivel2
            $existeTipificacion = false;
            $sqlTipif = '';
            $paramsTipif = [];
            if (!empty($filtros['canal_contacto'])) {
                $existeTipificacion = true;
                $sqlTipif .= " AND hg.canal_contacto = ?";
                $paramsTipif[] = $filtros['canal_contacto'];
            }
            if (!empty($filtros['nivel1_tipo'])) {
                $existeTipificacion = true;
                $sqlTipif .= " AND hg.nivel1_tipo = ?";
                $paramsTipif[] = $filtros['nivel1_tipo'];
            }
            if (!empty($filtros['nivel2_tipo'])) {
                $existeTipificacion = true;
                $sqlTipif .= " AND hg.nivel2_tipo = ?";
                $paramsTipif[] = $filtros['nivel2_tipo'];
            }
            if ($existeTipificacion) {
                $sql .= " AND EXISTS (
                    SELECT 1 FROM historial_gestion hg
                    WHERE hg.cliente_id = o.cliente_id " . $sqlTipif . "
                )";
                $params = array_merge($params, $paramsTipif);
            }
            
            // Excluir clientes ya asignados en tareas pendientes (salvo que se pida incluirlos)
            $incluirEnTareasPendientes = !empty($_POST['incluir_en_tareas_pendientes']);
            if (!$incluirEnTareasPendientes) {
                $tareaModel = new Tarea();
                $clientesAsignados = $tareaModel->obtenerClientesAsignados($baseId);
                if (!empty($clientesAsignados)) {
                    $placeholders = implode(',', array_fill(0, count($clientesAsignados), '?'));
                    $sql .= " AND o.cliente_id NOT IN ($placeholders)";
                    $params = array_merge($params, $clientesAsignados);
                }
            }
            
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Obtener IDs de clientes
            $clientesIds = array_column($clientes, 'cliente_id');
            
            // Contar obligaciones de estos clientes
            $totalObligaciones = 0;
            if (!empty($clientesIds)) {
                $placeholders = implode(',', array_fill(0, count($clientesIds), '?'));
                $sqlObligaciones = "SELECT COUNT(*) FROM obligaciones WHERE cliente_id IN ($placeholders) AND base_id = ?";
                $stmtOblig = $db->prepare($sqlObligaciones);
                $paramsOblig = array_merge($clientesIds, [$baseId]);
                $stmtOblig->execute($paramsOblig);
                $totalObligaciones = (int)$stmtOblig->fetchColumn();
            }
            
            return [
                'success' => true,
                'clientes' => $clientesIds,
                'total_clientes' => count($clientesIds),
                'total_obligaciones' => $totalObligaciones,
            ];
        } catch (Exception $e) {
            error_log("CoordGestionController::aplicarFiltrosObligaciones - " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Crea una asignación con clientes filtrados
     * @return array{success: bool, message: string, tarea_id?: int}
     */
    public function crearAsignacionClientesFiltrados() {
        try {
            $baseId = $_POST['base_id'] ?? null;
            $asesorCedula = $_POST['asesor_cedula'] ?? null;
            $clientesIdsJson = $_POST['clientes_ids'] ?? '[]';
            $coordinadorCedula = $this->coordinadorCedula();
            
            if (!$baseId || !$asesorCedula || !$coordinadorCedula) {
                return ['success' => false, 'message' => 'Faltan datos requeridos'];
            }
            
            $clientesIds = json_decode($clientesIdsJson, true);
            if (!is_array($clientesIds) || empty($clientesIds)) {
                return ['success' => false, 'message' => 'No hay clientes para asignar'];
            }
            // Respeta cantidad_asignar cuando el coordinador eligió "Asignar N clientes" (parcial)
            $cantidadAsignar = isset($_POST['cantidad_asignar']) ? (int) $_POST['cantidad_asignar'] : 0;
            if ($cantidadAsignar > 0 && $cantidadAsignar < count($clientesIds)) {
                $clientesIds = array_slice($clientesIds, 0, $cantidadAsignar);
            }
            
            // Verificar que el asesor tenga acceso a la base
            $db = getDBConnection();
            $stmt = $db->prepare("SELECT COUNT(*) AS n FROM asignacion_base_asesores WHERE base_id = ? AND asesor_cedula = ? AND estado = 'activa'");
            $stmt->execute([$baseId, $asesorCedula]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ((int)($row['n'] ?? 0) === 0) {
                return ['success' => false, 'message' => 'El asesor no tiene acceso a esta base de clientes'];
            }
            
            // Obtener obligaciones relacionadas a estos clientes
            $obligacionesAsignadas = [];
            if (!empty($clientesIds)) {
                $placeholders = implode(',', array_fill(0, count($clientesIds), '?'));
                $stmt = $db->prepare("SELECT operacion FROM obligaciones WHERE cliente_id IN ($placeholders) AND base_id = ?");
                $params = array_merge($clientesIds, [$baseId]);
                $stmt->execute($params);
                $obligacionesAsignadas = $stmt->fetchAll(PDO::FETCH_COLUMN);
            }
            
            // Nombre de la tarea: opcional desde POST
            $nombreTarea = isset($_POST['nombre_tarea']) ? trim((string)$_POST['nombre_tarea']) : null;
            
            // Crear la tarea
            $tareaModel = new Tarea();
            $tareaId = $tareaModel->crear([
                'nombre_tarea' => $nombreTarea,
                'coordinador_cedula' => $coordinadorCedula,
                'asesor_cedula' => $asesorCedula,
                'base_id' => $baseId,
                'clientes_asignados' => $clientesIds,
                'obligaciones_asignadas' => $obligacionesAsignadas,
            ]);
            
            if ($tareaId) {
                $tareaModel->insertarDetalleTareas($tareaId, $clientesIds);
                return [
                    'success' => true,
                    'message' => "Se asignaron " . count($clientesIds) . " clientes exitosamente",
                    'tarea_id' => $tareaId,
                    'clientes_asignados' => count($clientesIds),
                    'obligaciones_asignadas' => count($obligacionesAsignadas),
                ];
            }
            
            return ['success' => false, 'message' => 'Error al crear la asignación'];
        } catch (Exception $e) {
            error_log("CoordGestionController::crearAsignacionClientesFiltrados - " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Crea una asignación a partir de un CSV de cédulas.
     * Solo se asignan clientes que existan en la base seleccionada.
     * @return array{success: bool, message: string, tarea_id?: int, clientes_asignados?: int, cedulas_csv?: int, cedulas_encontradas?: int, cedulas_no_encontradas?: int}
     */
    public function crearAsignacionClientesCsv() {
        try {
            $baseId = $_POST['base_id'] ?? null;
            $asesorCedula = $_POST['asesor_cedula'] ?? null;
            $coordinadorCedula = $this->coordinadorCedula();

            if (!$baseId || !$asesorCedula || !$coordinadorCedula) {
                return ['success' => false, 'message' => 'Faltan datos requeridos (base, asesor o sesión).'];
            }

            // Archivo CSV
            $file = $_FILES['archivo_csv'] ?? null;
            if (!$file || ($file['error'] !== UPLOAD_ERR_OK) || empty($file['tmp_name'])) {
                return ['success' => false, 'message' => 'Debe subir un archivo CSV con cédulas.'];
            }

            $cedulas = $this->extraerCedulasDesdeCsv($file['tmp_name']);
            if (empty($cedulas)) {
                return ['success' => false, 'message' => 'El CSV no contiene cédulas válidas. Use una columna "cedula" o una cédula por línea.'];
            }

            $db = getDBConnection();

            // Verificar que el asesor tenga acceso a la base
            $stmt = $db->prepare("SELECT COUNT(*) AS n FROM asignacion_base_asesores WHERE base_id = ? AND asesor_cedula = ? AND estado = 'activa'");
            $stmt->execute([$baseId, $asesorCedula]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ((int)($row['n'] ?? 0) === 0) {
                return ['success' => false, 'message' => 'El asesor no tiene acceso a esta base de clientes.'];
            }

            // Resolver cédulas a id_cliente solo para clientes de esta base
            $placeholders = implode(',', array_fill(0, count($cedulas), '?'));
            $stmt = $db->prepare("SELECT id_cliente, cedula FROM cliente WHERE base_id = ? AND cedula IN ($placeholders)");
            $params = array_merge([$baseId], $cedulas);
            $stmt->execute($params);
            $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $clientesIds = array_column($filas, 'id_cliente');
            $cedulasEncontradas = count($clientesIds);
            $cedulasNoEncontradas = count($cedulas) - $cedulasEncontradas;

            if (empty($clientesIds)) {
                return [
                    'success' => false,
                    'message' => 'Ninguna de las cédulas del CSV pertenece a la base seleccionada.',
                    'cedulas_csv' => count($cedulas),
                    'cedulas_encontradas' => 0,
                    'cedulas_no_encontradas' => count($cedulas),
                ];
            }

            // Obligaciones de esos clientes
            $obligacionesAsignadas = [];
            $placeholdersIds = implode(',', array_fill(0, count($clientesIds), '?'));
            $stmt = $db->prepare("SELECT operacion FROM obligaciones WHERE cliente_id IN ($placeholdersIds) AND base_id = ?");
            $paramsObl = array_merge($clientesIds, [$baseId]);
            $stmt->execute($paramsObl);
            $obligacionesAsignadas = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $nombreTarea = isset($_POST['nombre_tarea']) ? trim((string)$_POST['nombre_tarea']) : null;

            $tareaModel = new Tarea();
            $tareaId = $tareaModel->crear([
                'nombre_tarea' => $nombreTarea,
                'coordinador_cedula' => $coordinadorCedula,
                'asesor_cedula' => $asesorCedula,
                'base_id' => $baseId,
                'clientes_asignados' => $clientesIds,
                'obligaciones_asignadas' => $obligacionesAsignadas,
            ]);

            if ($tareaId) {
                $tareaModel->insertarDetalleTareas($tareaId, $clientesIds);
                // Registrar trazabilidad en carga_csv_tareas (tabla en banco12.sql)
                $nombreArchivo = isset($file['name']) ? trim((string)$file['name']) : null;
                $this->registrarCargaCsvTarea($db, [
                    'base_id' => $baseId,
                    'asesor_cedula' => $asesorCedula,
                    'coordinador_cedula' => $coordinadorCedula,
                    'nombre_archivo' => $nombreArchivo,
                    'cedulas_subidas' => count($cedulas),
                    'cedulas_encontradas' => $cedulasEncontradas,
                    'cedulas_no_encontradas' => $cedulasNoEncontradas,
                    'id_tarea' => $tareaId,
                ]);
                return [
                    'success' => true,
                    'message' => 'Se asignaron ' . count($clientesIds) . ' clientes desde el CSV.' . ($cedulasNoEncontradas > 0 ? " ($cedulasNoEncontradas cédulas del CSV no están en esta base.)" : ''),
                    'tarea_id' => $tareaId,
                    'clientes_asignados' => count($clientesIds),
                    'obligaciones_asignadas' => count($obligacionesAsignadas),
                    'cedulas_csv' => count($cedulas),
                    'cedulas_encontradas' => $cedulasEncontradas,
                    'cedulas_no_encontradas' => $cedulasNoEncontradas,
                ];
            }

            return ['success' => false, 'message' => 'Error al crear la asignación.'];
        } catch (Exception $e) {
            error_log("CoordGestionController::crearAsignacionClientesCsv - " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Extrae lista de cédulas desde un archivo CSV (una columna o una por línea).
     * @param string $rutaArchivo
     * @return array<int, string>
     */
    /**
     * Sanitiza un valor para CSV: reemplaza saltos de línea por espacio para evitar filas vacías.
     * @param string $valor
     * @return string
     */
    private function sanitizarTextoCsv($valor) {
        $s = (string) $valor;
        $s = str_replace(["\r\n", "\n", "\r"], ' ', $s);
        return trim($s);
    }

    /**
     * Limpia observaciones para exportarlas en una sola celda/una sola línea:
     * elimina saltos de línea y colapsa espacios repetidos.
     * @param string $valor
     * @return string
     */
    private function sanitizarObservacionesCsv($valor) {
        $s = (string) $valor;
        $s = str_replace(["\r\n", "\n", "\r", "\t"], ' ', $s);
        $s = preg_replace('/\s+/', ' ', $s);
        return trim((string) $s);
    }

    /**
     * Normaliza campos tipo tipificación/canales para CSV:
     * - reemplaza '_' por espacio
     * - convierte a mayúsculas (UTF-8)
     * - elimina saltos de línea (vía sanitizarTextoCsv)
     */
    private function normalizarTipificacionCsv($valor) {
        $s = $this->sanitizarTextoCsv($valor ?? '');
        $s = str_replace('_', ' ', $s);
        // Mantener compatibilidad por si mbstring no está habilitado.
        if (function_exists('mb_strtoupper')) {
            return mb_strtoupper($s, 'UTF-8');
        }
        return strtoupper($s);
    }

    /**
     * Formatea fechas para CSV en orden día/mes/año.
     */
    private function formatearFechaCsv($valor, $conHora = true) {
        $s = trim((string) $valor);
        if ($s === '') {
            return '';
        }
        try {
            $dt = new DateTime($s);
            return $dt->format($conHora ? 'd/m/Y H:i:s' : 'd/m/Y');
        } catch (Exception $e) {
            return $s;
        }
    }

    private function extraerCedulasDesdeCsv($rutaArchivo) {
        $cedulas = [];
        $handle = fopen($rutaArchivo, 'r');
        if (!$handle) {
            return [];
        }
        $primeraFila = true;
        $indiceCedula = null;
        while (($fila = fgetcsv($handle, 0, ',', '"')) !== false) {
            if ($primeraFila && !empty($fila)) {
                $primeraFila = false;
                $cabeceras = array_map('trim', array_map('strtolower', $fila));
                if (in_array('cedula', $cabeceras, true)) {
                    $indiceCedula = array_search('cedula', $cabeceras, true);
                } else {
                    $indiceCedula = 0;
                }
            }
            $valor = isset($indiceCedula, $fila[$indiceCedula]) ? trim((string)$fila[$indiceCedula]) : '';
            if ($valor !== '' && strtolower($valor) !== 'cedula') {
                $cedulas[] = $valor;
            }
        }
        fclose($handle);
        return array_values(array_unique($cedulas));
    }

    /**
     * Registra en carga_csv_tareas la trazabilidad de una carga CSV (tabla en banco12.sql).
     * Si la tabla no existe, se ignora sin fallar.
     * @param PDO $db
     * @param array{base_id: mixed, asesor_cedula: string, coordinador_cedula: string, nombre_archivo: ?string, cedulas_subidas: int, cedulas_encontradas: int, cedulas_no_encontradas: int, id_tarea: int} $datos
     */
    private function registrarCargaCsvTarea(PDO $db, array $datos) {
        try {
            $stmt = $db->prepare("
                INSERT INTO carga_csv_tareas (base_id, asesor_cedula, coordinador_cedula, nombre_archivo, cedulas_subidas, cedulas_encontradas, cedulas_no_encontradas, id_tarea)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $datos['base_id'],
                $datos['asesor_cedula'],
                $datos['coordinador_cedula'],
                $datos['nombre_archivo'] ?? null,
                (int) $datos['cedulas_subidas'],
                (int) $datos['cedulas_encontradas'],
                (int) $datos['cedulas_no_encontradas'],
                (int) $datos['id_tarea'],
            ]);
        } catch (Exception $e) {
            error_log("CoordGestionController::registrarCargaCsvTarea - " . $e->getMessage());
            // No fallar la asignación si la tabla no existe (ej. esquema antiguo sin carga_csv_tareas)
        }
    }

    /**
     * Obtiene clientes no asignados de una base
     * @return array{success: bool, clientes?: array, message?: string}
     */
    public function obtenerClientesNoAsignados() {
        try {
            $baseId = $_GET['base_id'] ?? null;
            if (!$baseId) {
                return ['success' => false, 'message' => 'Base ID requerido', 'clientes' => []];
            }

            $db = getDBConnection();
            $tareaModel = new Tarea();
            
            // Obtener clientes ya asignados en tareas pendientes o en progreso
            $clientesAsignados = $tareaModel->obtenerClientesAsignados($baseId);
            
            // Obtener todos los clientes de la base
            $clienteModel = new Cliente();
            $todosClientes = $clienteModel->obtenerPorBase($baseId);
            
            // Filtrar clientes no asignados
            $clientesNoAsignados = [];
            foreach ($todosClientes as $cliente) {
                if (!in_array($cliente['id_cliente'], $clientesAsignados)) {
                    $clientesNoAsignados[] = $cliente;
                }
            }
            
            return [
                'success' => true,
                'clientes' => $clientesNoAsignados,
                'total' => count($clientesNoAsignados)
            ];
        } catch (Exception $e) {
            error_log("CoordGestionController::obtenerClientesNoAsignados - " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'clientes' => []];
        }
    }

    /**
     * Obtiene bases deshabilitadas (estado = 'inactivo')
     * @return array{success: bool, bases?: array, message?: string}
     */
    public function obtenerBasesDeshabilitadas() {
        try {
            $db = getDBConnection();
            $stmt = $db->query("
                SELECT
                    bc.id_base AS id,
                    bc.nombre,
                    bc.estado,
                    bc.fecha_creacion,
                    (SELECT COUNT(*) FROM cliente c WHERE c.base_id = bc.id_base) AS total_clientes,
                    (SELECT COUNT(*) FROM obligaciones o WHERE o.base_id = bc.id_base) AS total_obligaciones
                FROM base_clientes bc
                WHERE bc.estado = 'inactivo'
                ORDER BY bc.nombre
            ");
            $bases = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $bases[] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'nombre' => $row['nombre'] ?? '',
                    'estado' => $row['estado'] ?? 'inactivo',
                    'fecha_creacion' => $row['fecha_creacion'] ?? null,
                    'total_clientes' => (int) ($row['total_clientes'] ?? 0),
                    'total_obligaciones' => (int) ($row['total_obligaciones'] ?? 0),
                    'TOTAL_OBLIGACIONES' => (int) ($row['total_obligaciones'] ?? 0),
                ];
            }
            
            return [
                'success' => true,
                'bases' => $bases
            ];
        } catch (Exception $e) {
            error_log("CoordGestionController::obtenerBasesDeshabilitadas - " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'bases' => []];
        }
    }

    /**
     * Guarda asignaciones de asesores a bases (alias de guardarAccesoBase)
     * @return array{success: bool, message: string}
     */
    public function guardarAsignacionesBase() {
        return $this->guardarAccesoBase();
    }

    /**
     * Libera acceso de un asesor a una base (cambia estado a 'inactiva')
     * @return array{success: bool, message: string}
     */
    public function liberarAccesoBase() {
        try {
            $baseId = $_POST['base_id'] ?? $_GET['base_id'] ?? null;
            $asesorCedula = $_POST['asesor_cedula'] ?? $_GET['asesor_cedula'] ?? null;
            
            if (!$baseId || !$asesorCedula) {
                return ['success' => false, 'message' => 'Base ID y Asesor requeridos'];
            }
            
            $coordinadorCedula = $this->coordinadorCedula();
            if (!$coordinadorCedula) {
                return ['success' => false, 'message' => 'No autorizado'];
            }
            
            // Verificar que el asesor esté asignado al coordinador
            $asesoresCoordinador = $this->cedulasAsesoresCoordinador($coordinadorCedula);
            
            if (!in_array($asesorCedula, $asesoresCoordinador)) {
                return ['success' => false, 'message' => 'El asesor no está asignado a este coordinador'];
            }
            
            $db = getDBConnection();
            $stmt = $db->prepare("UPDATE asignacion_base_asesores SET estado = 'inactiva', fecha_actualizacion = NOW() WHERE base_id = ? AND asesor_cedula = ?");
            
            if ($stmt->execute([$baseId, $asesorCedula])) {
                $this->auditarCoordinador('liberar_acceso_base', 'base_clientes', (int) $baseId, [
                    'asesor_cedula' => $asesorCedula,
                ]);
                return ['success' => true, 'message' => 'Acceso liberado exitosamente'];
            }
            
            return ['success' => false, 'message' => 'Error al liberar el acceso'];
        } catch (Exception $e) {
            error_log("CoordGestionController::liberarAccesoBase - " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Habilita una base (cambia estado a 'activo')
     * @return array{success: bool, message: string}
     */
    public function habilitarBase() {
        try {
            $baseId = $_POST['base_id'] ?? $_GET['base_id'] ?? null;
            if (!$baseId) {
                return ['success' => false, 'message' => 'Base ID requerido'];
            }
            
            $baseClienteModel = new BaseCliente();
            if (!$baseClienteModel->tablaExiste()) {
                return ['success' => false, 'message' => 'La tabla de bases no existe'];
            }
            $base = $baseClienteModel->obtenerPorId($baseId);
            if (!$base) {
                return ['success' => false, 'message' => 'La base no existe en base_clientes'];
            }
            if (($base['estado'] ?? '') === 'activo') {
                return ['success' => true, 'message' => 'La base ya estaba habilitada'];
            }
            if ($baseClienteModel->actualizar($baseId, ['estado' => 'activo'])) {
                $this->auditarCoordinador('habilitar_base', 'base_clientes', (int) $baseId, [
                    'nombre' => $base['nombre'] ?? '',
                ]);
                return ['success' => true, 'message' => 'Base habilitada exitosamente'];
            }
            return ['success' => false, 'message' => 'No se pudo habilitar la base'];
        } catch (Exception $e) {
            error_log("CoordGestionController::habilitarBase - " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Deshabilita una base (cambia estado a 'inactivo')
     * @return array{success: bool, message: string}
     */
    public function deshabilitarBase() {
        try {
            $baseId = $_POST['base_id'] ?? $_GET['base_id'] ?? null;
            if (!$baseId) {
                return ['success' => false, 'message' => 'Base ID requerido'];
            }
            
            $baseClienteModel = new BaseCliente();
            if (!$baseClienteModel->tablaExiste()) {
                return ['success' => false, 'message' => 'La tabla de bases no existe'];
            }
            $base = $baseClienteModel->obtenerPorId($baseId);
            if (!$base) {
                return ['success' => false, 'message' => 'La base no existe en base_clientes'];
            }
            if (($base['estado'] ?? '') === 'inactivo') {
                // Ya está deshabilitada (MySQL puede devolver 0 filas afectadas si se intenta setear el mismo valor)
                return ['success' => true, 'message' => 'La base ya estaba deshabilitada'];
            }
            if ($baseClienteModel->actualizar($baseId, ['estado' => 'inactivo'])) {
                // Al deshabilitar, inactivar accesos (no se muestran bases ni tareas al asesor)
                try {
                    $db = getDBConnection();
                    $stmt = $db->prepare("UPDATE asignacion_base_asesores SET estado = 'inactiva', fecha_actualizacion = NOW() WHERE base_id = ?");
                    $stmt->execute([(int)$baseId]);
                } catch (Throwable $e) {
                    // No bloquear deshabilitado por esto, pero dejar rastro
                    error_log("CoordGestionController::deshabilitarBase - no se pudo inactivar accesos: " . $e->getMessage());
                }
                $this->auditarCoordinador('deshabilitar_base', 'base_clientes', (int) $baseId, [
                    'nombre' => $base['nombre'] ?? '',
                ]);
                return ['success' => true, 'message' => 'Base deshabilitada exitosamente'];
            }
            return ['success' => false, 'message' => 'No se pudo deshabilitar la base'];
        } catch (Exception $e) {
            error_log("CoordGestionController::deshabilitarBase - " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Elimina una base (elimina registros relacionados y luego la base)
     * @return array{success: bool, message: string}
     */
    public function eliminarBase() {
        try {
            $baseId = $_POST['base_id'] ?? $_GET['base_id'] ?? null;
            if (!$baseId) {
                return ['success' => false, 'message' => 'Base ID requerido'];
            }
            
            $db = getDBConnection();
            
            // Verificar que no haya tareas pendientes o en progreso para esta base
            $tareaModel = new Tarea();
            $tareas = $tareaModel->obtenerPorCoordinador($this->coordinadorCedula());
            foreach ($tareas as $tarea) {
                if ($tarea['base_id'] == $baseId && in_array($tarea['estado'], ['pendiente', 'en progreso'])) {
                    return ['success' => false, 'message' => 'No se puede eliminar la base porque tiene tareas pendientes o en progreso'];
                }
            }
            
            // Eliminar asignaciones de asesores a esta base
            $stmt = $db->prepare("DELETE FROM asignacion_base_asesores WHERE base_id = ?");
            $stmt->execute([$baseId]);
            
            // Eliminar la base
            $stmt = $db->prepare("DELETE FROM base_clientes WHERE id_base = ?");
            if ($stmt->execute([$baseId])) {
                $this->auditarCoordinador('eliminar_base', 'base_clientes', (int) $baseId);
                return ['success' => true, 'message' => 'Base eliminada exitosamente'];
            }
            
            return ['success' => false, 'message' => 'Error al eliminar la base'];
        } catch (Exception $e) {
            error_log("CoordGestionController::eliminarBase - " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Obtiene el historial de gestiones del coordinador (de todos sus asesores)
     * @return array{success: bool, historial?: array, message?: string}
     */
    public function obtenerHistorial() {
        try {
            $coordinadorCedula = $this->coordinadorCedula();
            if (!$coordinadorCedula) {
                return ['success' => false, 'message' => 'No autorizado', 'historial' => []];
            }
            
            $db = getDBConnection();
            
            // Obtener asesores asignados al coordinador
            $asesoresCedulas = $this->cedulasAsesoresCoordinador($coordinadorCedula);
            
            if (empty($asesoresCedulas)) {
                return ['success' => true, 'historial' => []];
            }
            
            // Obtener historial de gestiones de los asesores
            $placeholders = implode(',', array_fill(0, count($asesoresCedulas), '?'));
            $sql = "
                SELECT 
                    hg.id_gestion,
                    hg.asesor_cedula,
                    hg.cliente_id,
                    hg.obligacion_id,
                    hg.canal_contacto,
                    hg.nivel1_tipo,
                    hg.nivel2_tipo,
                    hg.nivel3_tipo,
                    hg.fecha_creacion,
                    c.nombre as cliente_nombre,
                    c.cedula as cliente_cedula,
                    u.nombre as asesor_nombre
                FROM historial_gestion hg
                INNER JOIN cliente c ON hg.cliente_id = c.id_cliente
                INNER JOIN usuarios u ON hg.asesor_cedula = u.cedula
                WHERE hg.asesor_cedula IN ($placeholders)
                ORDER BY hg.fecha_creacion DESC
                LIMIT 1000
            ";
            
            $stmt = $db->prepare($sql);
            $stmt->execute($asesoresCedulas);
            $historial = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'success' => true,
                'historial' => $historial
            ];
        } catch (Exception $e) {
            error_log("CoordGestionController::obtenerHistorial - " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'historial' => []];
        }
    }

    /**
     * Elimina una tarea
     * @return array{success: bool, message: string}
     */
    public function eliminarTarea() {
        try {
            $tareaId = $_POST['tarea_id'] ?? $_GET['tarea_id'] ?? null;
            if (!$tareaId) {
                return ['success' => false, 'message' => 'ID de tarea requerido'];
            }

            $coordinadorCedula = $this->coordinadorCedula();
            if (!$coordinadorCedula) {
                return ['success' => false, 'message' => 'No autorizado'];
            }

            // Verificar que la tarea pertenezca al coordinador
            $tareaModel = new Tarea();
            $tareas = $tareaModel->obtenerPorCoordinador($coordinadorCedula);
            $tareaExiste = false;
            foreach ($tareas as $tarea) {
                if ($tarea['id_tarea'] == $tareaId) {
                    $tareaExiste = true;
                    break;
                }
            }

            if (!$tareaExiste) {
                return ['success' => false, 'message' => 'Tarea no encontrada o no autorizado'];
            }

            // Eliminar la tarea
            $db = getDBConnection();
            $stmt = $db->prepare("DELETE FROM tareas WHERE id_tarea = ?");
            
            if ($stmt->execute([$tareaId])) {
                $this->auditarCoordinador('eliminar_tarea', 'tareas', (int) $tareaId);
                return ['success' => true, 'message' => 'Tarea eliminada exitosamente'];
            }

            return ['success' => false, 'message' => 'Error al eliminar la tarea'];
        } catch (Exception $e) {
            error_log("CoordGestionController::eliminarTarea - " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Obtiene asignaciones pendientes del coordinador
     * @return array{success: bool, asignaciones?: array, message?: string}
     */
    public function obtenerAsignacionesPendientes() {
        try {
            $coordinadorCedula = $this->coordinadorCedula();
            if (!$coordinadorCedula) {
                return ['success' => false, 'message' => 'No autorizado', 'asignaciones' => []];
            }
            
            $tareaModel = new Tarea();
            $tareas = $tareaModel->obtenerPorCoordinador($coordinadorCedula);
            
            // Filtrar solo las pendientes
            $pendientes = [];
            foreach ($tareas as $tarea) {
                if ($tarea['estado'] === 'pendiente') {
                    $pendientes[] = $tarea;
                }
            }
            
            return ['success' => true, 'asignaciones' => $pendientes];
        } catch (Exception $e) {
            error_log("CoordGestionController::obtenerAsignacionesPendientes - " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'asignaciones' => []];
        }
    }

    /**
     * Descarga la plantilla CSV real para carga de datos (mismo formato que la imagen de referencia)
     * Columnas: OPERACIÓN, CUENTA CLIENTE, OFICINA, IDENTIFICACION, NOMBRE CLIENTE, AÑO DE CASTIGO,
     * CONCEPTO MES ACTUAL, ESTADO PROCESO JURIDICO, Total, TOTAL A PAGAR, CORREO,
     * DUEÑO CARTERA, COMPRA, TIPO PRODUCTO, BUCKET SALDO CAPITAL, DEPARTAMENTO, DIAS MORA ACTUAL, tel1..tel10
     * @return void
     */
    public function descargarPlantilla() {
        try {
            $plantillaEstatica = dirname(__DIR__) . '/assets/plantillas/plantilla_carga_clientes.csv';
            if (is_file($plantillaEstatica)) {
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="plantilla_carga_clientes.csv"');
                header('Content-Length: ' . filesize($plantillaEstatica));
                readfile($plantillaEstatica);
                exit;
            }

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="plantilla_carga_clientes.csv"');

            $columnas = [
                'OPERACIÓN',
                'CUENTA CLIENTE',
                'OFICINA',
                'IDENTIFICACION',
                'NOMBRE CLIENTE',
                'AÑO DE CASTIGO',
                'CONCEPTO MES ACTUAL',
                'ESTADO PROCESO JURIDICO',
                'Total',
                'TOTAL A PAGAR',
                'CORREO',
                'DUEÑO CARTERA',
                'COMPRA',
                'TIPO PRODUCTO',
                'BUCKET SALDO CAPITAL',
                'DEPARTAMENTO',
                'DIAS MORA ACTUAL',
                'tel1',
                'tel2',
                'tel3',
                'tel4',
                'tel5',
                'tel6',
                'tel7',
                'tel8',
                'tel9',
                'tel10',
            ];

            $output = fopen('php://output', 'w');

            // BOM UTF-8 para Excel
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

            fputcsv($output, $columnas);

            // Filas de ejemplo según formato real (como en la imagen)
            // Nota: se incluye ahora la columna CORREO entre TOTAL A PAGAR y tel1
            $ejemplos = [
                ['361238', '424545', 'FUNZA', '174157', 'Everardo Ortega Pabon', '2024', 'ALIVIOS CASTIGADOS', 'NO JUDICIALIZADO', '184077', '36815', 'everardo.ortega@example.com', 'Cartera A', 'COMPRA1', 'Consumo', 'B1', 'Cundinamarca', '45', '3014289243', '3227158862', '0', '0', '', '', '', '', '', ''],
                ['127972', '465473', 'SOACHA', '195413', 'Acuna Romero Julio', '2024', 'ALIVIOS CASTIGADOS', 'NO JUDICIALIZADO', '71863', '14373', 'julio.acuna@example.com', 'Cartera B', 'COMPRA2', 'Microcredito', 'B2', 'Cundinamarca', '30', '3138054888', '3228625660', '0', '0', '', '', '', '', '', ''],
            ];
            foreach ($ejemplos as $fila) {
                fputcsv($output, $fila);
            }

            fclose($output);
            exit;
        } catch (Exception $e) {
            error_log("CoordGestionController::descargarPlantilla - " . $e->getMessage());
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    }

    /**
     * Exporta bases de clientes a CSV
     * @return void
     */
    public function exportarBases() {
        try {
            $baseId = $_GET['base_id'] ?? null;
            
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="export_bases_' . date('Y-m-d') . '.csv"');
            
            $db = getDBConnection();
            $output = fopen('php://output', 'w');
            
            // BOM UTF-8 para Excel
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
            
            if ($baseId) {
                // Exportar una base específica
                $stmt = $db->prepare("
                    SELECT 
                        c.cedula,
                        c.nombre,
                        c.email,
                        c.departamento,
                        c.tel1, c.tel2, c.tel3, c.tel4, c.tel5,
                        c.tel6, c.tel7, c.tel8, c.tel9, c.tel10,
                        o.operacion,
                        o.cuenta_cliente,
                        o.oficina,
                        o.año_castigo,
                        o.concepto_mes_actual,
                        o.estado_proceso_juridico,
                        o.total,
                        o.total_a_pagar,
                        o.dueno_cartera,
                        o.compra,
                        o.tipo_producto,
                        o.bucket_saldo_capital,
                        o.dias_mora_actual
                    FROM cliente c
                    LEFT JOIN obligaciones o ON o.cliente_id = c.id_cliente
                    WHERE c.base_id = ?
                    ORDER BY c.nombre
                ");
                $stmt->execute([$baseId]);
                
                // Encabezados
                fputcsv($output, [
                    'cedula', 'nombre', 'email', 'departamento', 'tel1', 'tel2', 'tel3', 'tel4', 'tel5',
                    'tel6', 'tel7', 'tel8', 'tel9', 'tel10',
                    'operacion', 'cuenta_cliente', 'oficina', 'año_castigo',
                    'concepto_mes_actual', 'estado_proceso_juridico', 'total', 'total_a_pagar',
                    'dueno_cartera', 'compra', 'tipo_producto', 'bucket_saldo_capital', 'dias_mora_actual'
                ]);
                
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    fputcsv($output, $row);
                }
            } else {
                // Exportar todas las bases
                $stmt = $db->query("
                    SELECT 
                        id_base,
                        nombre,
                        estado,
                        fecha_creacion,
                        total_clientes,
                        TOTAL_OBLIGACIONES
                    FROM base_clientes
                    ORDER BY nombre
                ");
                
                fputcsv($output, ['id_base', 'nombre', 'estado', 'fecha_creacion', 'total_clientes', 'total_obligaciones']);
                
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    fputcsv($output, $row);
                }
            }
            
            fclose($output);
            exit;
        } catch (Exception $e) {
            error_log("CoordGestionController::exportarBases - " . $e->getMessage());
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    }

    /**
     * Genera y descarga CSV de reporte de gestiones (coordinador).
     * Parámetros GET: fecha_inicio, fecha_fin (Y-m-d).
     * Columnas: fecha de gestion, asesor, operacion, cedula del cliente, cliente,
     * telefono de contacto, base, canal de contacto, nivel1, nivel2, fecha de pago,
     * cuota, cuota actual, descuento aplicado, valor de pago, duracion, observaciones.
     */
    public function generarReporteGestiones() {
        try {
            $coordinadorCedula = $this->coordinadorCedula();
            if (!$coordinadorCedula) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'No autorizado']);
                exit;
            }
            $fechaInicio = isset($_GET['fecha_inicio']) ? trim($_GET['fecha_inicio']) : '';
            $fechaFin = isset($_GET['fecha_fin']) ? trim($_GET['fecha_fin']) : '';
            if (!$fechaInicio || !$fechaFin) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'fecha_inicio y fecha_fin son requeridos (Y-m-d).']);
                exit;
            }
            $db = getDBConnection();
            $asesoresCedulas = $this->cedulasAsesoresCoordinador($coordinadorCedula);
            if (empty($asesoresCedulas)) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'No tiene asesores asignados.']);
                exit;
            }
            
            // Detectar si el esquema de `acuerdos` tiene columnas ancho `pago_1..pago_N`.
            // Si no existen (esquema normalizado), se dejarán las columnas de pago vacías en el CSV.
            $acuerdosTienePagoColumnas = false;
            $pagoSelect = '';
            try {
                $stmtCols = $db->prepare("
                    SELECT 1
                    FROM information_schema.columns
                    WHERE table_schema = DATABASE()
                      AND table_name = 'acuerdos'
                      AND column_name = 'pago_1'
                    LIMIT 1
                ");
                $stmtCols->execute();
                $acuerdosTienePagoColumnas = $stmtCols->fetchColumn() !== false;
            } catch (Exception $e) {
                $acuerdosTienePagoColumnas = false;
            }
            
            if ($acuerdosTienePagoColumnas) {
                $maxPagoCols = Acuerdo::maxIndiceColumnasPagoAcuerdos();
                for ($i = 1; $i <= $maxPagoCols; $i++) {
                    $pagoSelect .= ", a.pago_{$i} AS pago_{$i}, a.fecha_pago_{$i} AS fecha_{$i}";
                }
            }
            $placeholders = implode(',', array_fill(0, count($asesoresCedulas), '?'));
            $sql = "
                SELECT
                    h.id_gestion,
                    h.fecha_creacion AS fecha_gestion,
                    h.asesor_cedula,
                    u.nombre AS asesor_nombre,
                    c.cedula AS cliente_cedula,
                    c.nombre AS cliente_nombre,
                    h.id_tarea,
                    t.nombre_tarea,
                    t.base_id,
                    h.canal_contacto,
                    h.nivel1_tipo,
                    h.nivel2_tipo,
                    h.nivel3_tipo,
                    h.nivel4_tipo,
                    COALESCE(o.operacion, 'Ninguna') AS operacion,
                    h.fecha_pago,
                    h.valor_pago,
                    h.cuota,
                    h.cuota_actual,
                    a.descuento_aplicado,
                    -- Campos del acuerdo (coinciden con esquema ancho `pago_1..pago_N` o normalizado)
                    a.tipo_acuerdo,
                    a.numero_cuotas,
                    a.valor_original,
                    a.valor_final_pago_total,
                    CASE
                        WHEN h.nivel2_tipo = 'acuerdo_aprobado' THEN COALESCE(a.estado_aprobacion, '')
                        ELSE ''
                    END AS estado_aprobacion_reporte,
                    CASE
                        WHEN a.tipo_acuerdo = 'total' THEN COALESCE(a.valor_final_pago_total, '')
                        WHEN a.tipo_acuerdo = 'cuotas' THEN COALESCE(a.valor_original, '')
                        WHEN a.tipo_acuerdo = 'comite' THEN COALESCE(a.valor_original, '')
                        ELSE COALESCE(h.valor_pago, '')
                    END AS valor_pago_reporte,
                    COALESCE(CAST(a.numero_cuotas AS CHAR), '') AS cuota_reporte
                    {$pagoSelect},
                    h.llamada_telefonica,
                    h.email,
                    h.sms,
                    h.correo_fisico,
                    h.whatsapp,
                    h.duracion_segundos,
                    h.observaciones,
                    h.numero_contacto,
                    b.nombre AS nombre_base
                FROM historial_gestion h
                INNER JOIN cliente c ON c.id_cliente = h.cliente_id
                LEFT JOIN base_clientes b ON b.id_base = c.base_id
                LEFT JOIN obligaciones o ON o.id_obligacion = h.obligacion_id
                LEFT JOIN acuerdos a ON a.id_gestion = h.id_gestion
                LEFT JOIN usuarios u ON u.cedula = h.asesor_cedula
                LEFT JOIN tareas t ON t.id_tarea = h.id_tarea
                WHERE h.asesor_cedula IN ($placeholders)
                  AND DATE(h.fecha_creacion) >= ?
                  AND DATE(h.fecha_creacion) <= ?
                ORDER BY h.fecha_creacion DESC
            ";
            $params = array_merge($asesoresCedulas, [$fechaInicio, $fechaFin]);
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="reporte_gestiones_' . $fechaInicio . '_' . $fechaFin . '.csv"');
            $output = fopen('php://output', 'w');
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
            $columnas = [
                'fecha de gestion',
                'asesor',
                'operacion',
                'cedula del cliente',
                'cliente',
                'telefono de contacto',
                'base',
                'canal de contacto',
                'nivel1',
                'nivel2',
                'fecha de pago',
                'descuento aplicado',
                'valor de pago',
                'estado de aprovacion',
                'cuota',
            ];
            for ($i = 1; $i <= Acuerdo::MAX_PAGO_COLUMNAS_ANCHO; $i++) {
                $columnas[] = 'pago ' . $i;
                $columnas[] = 'fecha ' . $i;
            }
            $columnas[] = 'duracion';
            $columnas[] = 'observaciones';
            fputcsv($output, $columnas);
            // Streaming: escribir fila a fila sin cargar todo en memoria (soporta millones de registros)
            while (($r = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                $seg = (int) ($r['duracion_segundos'] ?? 0);
                $h = floor($seg / 3600);
                $m = floor(($seg % 3600) / 60);
                $s = $seg % 60;
                $duracion = sprintf('%d:%02d:%02d', $h, $m, $s);
                $fila = [
                    $this->formatearFechaCsv($r['fecha_gestion'] ?? '', true),
                    $this->sanitizarTextoCsv($r['asesor_nombre'] ?? ''),
                    $this->sanitizarTextoCsv($r['operacion'] ?? 'Ninguna'),
                    $this->sanitizarTextoCsv($r['cliente_cedula'] ?? ''),
                    $this->sanitizarTextoCsv($r['cliente_nombre'] ?? ''),
                    $this->sanitizarTextoCsv($r['numero_contacto'] ?? ''),
                    $this->sanitizarTextoCsv($r['nombre_base'] ?? ''),
                    $this->normalizarTipificacionCsv($r['canal_contacto'] ?? ''),
                    $this->normalizarTipificacionCsv($r['nivel1_tipo'] ?? ''),
                    $this->normalizarTipificacionCsv($r['nivel2_tipo'] ?? ''),
                    $this->formatearFechaCsv($r['fecha_pago'] ?? '', false),
                    $this->sanitizarTextoCsv($r['descuento_aplicado'] ?? ''),
                    $this->sanitizarTextoCsv($r['valor_pago_reporte'] ?? ''),
                    $this->sanitizarTextoCsv($r['estado_aprobacion_reporte'] ?? ''),
                    $this->sanitizarTextoCsv($r['cuota_reporte'] ?? ''),
                ];
                for ($i = 1; $i <= Acuerdo::MAX_PAGO_COLUMNAS_ANCHO; $i++) {
                    $fila[] = $this->sanitizarTextoCsv($r["pago_{$i}"] ?? '');
                    $fila[] = $this->formatearFechaCsv($r["fecha_{$i}"] ?? '', false);
                }
                $fila[] = $duracion;
                $fila[] = $this->sanitizarObservacionesCsv($r['observaciones'] ?? '');
                fputcsv($output, $fila);
            }
            fclose($output);
            exit;
        } catch (Exception $e) {
            error_log("CoordGestionController::generarReporteGestiones - " . $e->getMessage());
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    }

    /**
     * Genera y descarga CSV de reporte TMO (tiempos de asesores) desde la tabla tiempos.
     * Parámetros: fecha_inicio, fecha_fin (Y-m-d) por GET o POST (JSON).
     * Solo incluye asesores asignados al coordinador.
     */
    public function generarReporteTmo() {
        try {
            $coordinadorCedula = $this->coordinadorCedula();
            if (!$coordinadorCedula) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'No autorizado']);
                exit;
            }
            $fechaInicio = null;
            $fechaFin = null;
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $input = file_get_contents('php://input');
                $datos = $input ? json_decode($input, true) : null;
                $fechaInicio = isset($datos['fecha_inicio']) ? trim((string)$datos['fecha_inicio']) : null;
                $fechaFin = isset($datos['fecha_fin']) ? trim((string)$datos['fecha_fin']) : null;
            }
            if (!$fechaInicio || !$fechaFin) {
                $fechaInicio = isset($_GET['fecha_inicio']) ? trim($_GET['fecha_inicio']) : null;
                $fechaFin = isset($_GET['fecha_fin']) ? trim($_GET['fecha_fin']) : null;
            }
            if (!$fechaInicio || !$fechaFin) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'fecha_inicio y fecha_fin son requeridos (Y-m-d).']);
                exit;
            }
            $db = getDBConnection();
            $asesoresCedulas = $this->cedulasAsesoresCoordinador($coordinadorCedula);
            if (empty($asesoresCedulas)) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'No tiene asesores asignados.']);
                exit;
            }
            $placeholders = implode(',', array_fill(0, count($asesoresCedulas), '?'));
            $sql = "
                SELECT t.id_tiempo, t.asesor_cedula, t.fecha, t.tipo_registro, t.hora_inicio, t.hora_fin, t.estado,
                       u.nombre AS asesor_nombre
                FROM tiempos t
                INNER JOIN usuarios u ON u.cedula = t.asesor_cedula
                WHERE t.asesor_cedula IN ($placeholders)
                  AND t.fecha >= ?
                  AND t.fecha <= ?
                ORDER BY t.fecha DESC, t.hora_inicio DESC
            ";
            $params = array_merge($asesoresCedulas, [$fechaInicio, $fechaFin]);
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="reporte_tmo_' . $fechaInicio . '_' . $fechaFin . '.csv"');
            $output = fopen('php://output', 'w');
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
            $columnas = ['fecha', 'asesor_cedula', 'asesor_nombre', 'tipo_registro', 'hora_inicio', 'hora_fin', 'duracion_segundos', 'duracion', 'estado'];
            fputcsv($output, $columnas);
            while (($r = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                $hIni = $r['hora_inicio'] ?? null;
                $hFin = $r['hora_fin'] ?? null;
                $duracionSeg = 0;
                if ($hIni && $hFin) {
                    $duracionSeg = max(0, strtotime($hFin) - strtotime($hIni));
                }
                $h = floor($duracionSeg / 3600);
                $m = floor(($duracionSeg % 3600) / 60);
                $s = $duracionSeg % 60;
                $duracion = sprintf('%d:%02d:%02d', $h, $m, $s);
                $fila = [
                    $r['fecha'] ?? '',
                    $r['asesor_cedula'] ?? '',
                    $r['asesor_nombre'] ?? '',
                    $r['tipo_registro'] ?? '',
                    $r['hora_inicio'] ?? '',
                    $r['hora_fin'] ?? '',
                    $duracionSeg,
                    $duracion,
                    $r['estado'] ?? '',
                ];
                fputcsv($output, $fila);
            }
            fclose($output);
            exit;
        } catch (Exception $e) {
            error_log("CoordGestionController::generarReporteTmo - " . $e->getMessage());
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    }

    /**
     * Limpia el historial de gestiones (solo del coordinador y sus asesores)
     * @return array{success: bool, message: string}
     */
    public function limpiarHistorial() {
        try {
            $coordinadorCedula = $this->coordinadorCedula();
            if (!$coordinadorCedula) {
                return ['success' => false, 'message' => 'No autorizado'];
            }
            
            $db = getDBConnection();
            
            // Obtener asesores asignados al coordinador
            $asesoresCedulas = $this->cedulasAsesoresCoordinador($coordinadorCedula);
            
            if (empty($asesoresCedulas)) {
                return ['success' => true, 'message' => 'No hay historial para limpiar'];
            }
            
            // Eliminar historial de gestiones de los asesores
            $placeholders = implode(',', array_fill(0, count($asesoresCedulas), '?'));
            $stmt = $db->prepare("DELETE FROM historial_gestion WHERE asesor_cedula IN ($placeholders)");
            
            if ($stmt->execute($asesoresCedulas)) {
                $eliminados = $stmt->rowCount();
                return ['success' => true, 'message' => "Se eliminaron $eliminados registros del historial"];
            }
            
            return ['success' => false, 'message' => 'Error al limpiar el historial'];
        } catch (Exception $e) {
            error_log("CoordGestionController::limpiarHistorial - " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Obtiene los detalles completos de un asesor para el modal del coordinador
     * Incluye información del asesor, estadísticas y gestiones recientes
     * @return array{success: bool, asesor?: array, gestiones?: array, message?: string}
     */
    public function obtenerDetallesAsesorCoord() {
        try {
            $asesorCedula = $_GET['asesor_cedula'] ?? null;
            $coordinadorCedula = $this->coordinadorCedula();
            
            if (!$asesorCedula) {
                return ['success' => false, 'message' => 'Cédula del asesor requerida'];
            }
            
            if (!$coordinadorCedula) {
                return ['success' => false, 'message' => 'No autorizado'];
            }
            
            // Verificar que el asesor esté asignado al coordinador
            $asesoresCoordinador = $this->cedulasAsesoresCoordinador($coordinadorCedula);
            
            if (!in_array($asesorCedula, $asesoresCoordinador)) {
                return ['success' => false, 'message' => 'El asesor no está asignado a este coordinador'];
            }
            
            $db = getDBConnection();
            $usuarioModel = new Usuario();
            $tareaModel = new Tarea();
            
            // Obtener datos del asesor
            $asesor = $usuarioModel->obtenerPorCedula($asesorCedula);
            if (!$asesor || strtolower($asesor['rol'] ?? '') !== 'asesor') {
                return ['success' => false, 'message' => 'Asesor no encontrado'];
            }
            
            $asesor['nombre_completo'] = $asesor['nombre_completo'] ?? $asesor['nombre'] ?? '';
            
            // Calcular clientes asignados desde tareas
            $tareasAsesor = $tareaModel->obtenerPorAsesor($asesorCedula);
            $clientesAsignadosIds = [];
            foreach ($tareasAsesor as $tarea) {
                $clientesTarea = is_array($tarea['clientes_asignados']) ? $tarea['clientes_asignados'] : [];
                $clientesAsignadosIds = array_merge($clientesAsignadosIds, $clientesTarea);
            }
            $asesor['clientes_asignados'] = count(array_unique($clientesAsignadosIds));
            
            // Calcular clientes gestionados desde historial_gestion
            $stmt = $db->prepare("
                SELECT COUNT(DISTINCT cliente_id) as total 
                FROM historial_gestion 
                WHERE asesor_cedula = ?
            ");
            $stmt->execute([$asesorCedula]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $asesor['clientes_gestionados'] = (int)($result['total'] ?? 0);
            
            // Obtener última actividad
            $stmt = $db->prepare("
                SELECT MAX(fecha_creacion) as ultima_actividad 
                FROM historial_gestion 
                WHERE asesor_cedula = ?
            ");
            $stmt->execute([$asesorCedula]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $asesor['ultima_actividad'] = $result['ultima_actividad'] ?? null;
            
            // Formatear última actividad si existe
            if ($asesor['ultima_actividad']) {
                try {
                    $fecha = new DateTime($asesor['ultima_actividad']);
                    $asesor['ultima_actividad'] = $fecha->format('d/m/Y H:i');
                } catch (Exception $e) {
                    // Mantener formato original si hay error
                }
            }
            
            // Normalizar estado a minúsculas para el frontend
            $asesor['estado'] = strtolower($asesor['estado'] ?? 'activo');
            
            // Obtener gestiones recientes (últimas 50)
            $stmt = $db->prepare("
                SELECT 
                    hg.id_gestion,
                    hg.cliente_id,
                    hg.obligacion_id,
                    hg.canal_contacto,
                    hg.nivel1_tipo,
                    hg.nivel2_tipo,
                    hg.nivel3_tipo,
                    hg.nivel4_tipo,
                    hg.observaciones,
                    hg.llamada_telefonica,
                    hg.email,
                    hg.sms,
                    hg.correo_fisico,
                    hg.whatsapp,
                    hg.fecha_creacion,
                    hg.fecha_pago,
                    hg.valor_pago,
                    hg.cuota,
                    hg.cuota_actual,
                    hg.numero_contacto,
                    hg.duracion_segundos,
                    c.nombre as cliente_nombre,
                    c.cedula as cliente_cedula
                FROM historial_gestion hg
                INNER JOIN cliente c ON hg.cliente_id = c.id_cliente
                WHERE hg.asesor_cedula = ?
                ORDER BY hg.fecha_creacion DESC
                LIMIT 50
            ");
            $stmt->execute([$asesorCedula]);
            $gestionesRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Formatear gestiones con información del cliente
            $gestiones = [];
            foreach ($gestionesRaw as $gestion) {
                $gestiones[] = [
                    'id_gestion' => $gestion['id_gestion'],
                    'cliente_id' => $gestion['cliente_id'],
                    'obligacion_id' => $gestion['obligacion_id'],
                    'canal_contacto' => $gestion['canal_contacto'],
                    'nivel1_tipo' => $gestion['nivel1_tipo'],
                    'nivel2_tipo' => $gestion['nivel2_tipo'],
                    'nivel2_clasificacion' => $gestion['nivel2_tipo'], // Compatibilidad con frontend
                    'nivel3_tipo' => $gestion['nivel3_tipo'],
                    'nivel3_detalle' => $gestion['nivel3_tipo'], // Compatibilidad con frontend
                    'nivel4_tipo' => $gestion['nivel4_tipo'],
                    'observaciones' => $gestion['observaciones'],
                    'llamada_telefonica' => $gestion['llamada_telefonica'],
                    'email' => $gestion['email'],
                    'sms' => $gestion['sms'],
                    'correo_fisico' => $gestion['correo_fisico'],
                    'whatsapp' => $gestion['whatsapp'],
                    'correo_electronico' => $gestion['email'], // Compatibilidad con frontend
                    'mensajeria_aplicacion' => 'no', // Campo no existe en BD, por compatibilidad
                    'fecha_creacion' => $gestion['fecha_creacion'],
                    'fecha_pago' => $gestion['fecha_pago'],
                    'valor_pago' => $gestion['valor_pago'],
                    'cuota' => $gestion['cuota'],
                    'cuota_actual' => $gestion['cuota_actual'],
                    'numero_contacto' => $gestion['numero_contacto'],
                    'duracion_segundos' => $gestion['duracion_segundos'],
                    'cliente_info' => [
                        'nombre' => $gestion['cliente_nombre'],
                        'identificacion' => $gestion['cliente_cedula'],
                        'cedula' => $gestion['cliente_cedula']
                    ]
                ];
            }
            
            return [
                'success' => true,
                'asesor' => $asesor,
                'gestiones' => $gestiones
            ];
        } catch (Exception $e) {
            error_log("CoordGestionController::obtenerDetallesAsesorCoord - " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Busca gestiones de un asesor por término (cédula, teléfono, nombre o operación)
     * @return array{success: bool, gestiones?: array, message?: string}
     */
    public function buscarGestionesAsesorCoord() {
        try {
            $asesorCedula = $_GET['asesor_cedula'] ?? null;
            $termino = trim($_GET['termino'] ?? '');
            $coordinadorCedula = $this->coordinadorCedula();
            
            if (!$asesorCedula) {
                return ['success' => false, 'message' => 'Cédula del asesor requerida'];
            }
            
            if (!$termino) {
                return ['success' => false, 'message' => 'Término de búsqueda requerido', 'gestiones' => []];
            }
            
            if (!$coordinadorCedula) {
                return ['success' => false, 'message' => 'No autorizado'];
            }
            
            // Verificar que el asesor esté asignado al coordinador
            $asesoresCoordinador = $this->cedulasAsesoresCoordinador($coordinadorCedula);
            
            if (!in_array($asesorCedula, $asesoresCoordinador)) {
                return ['success' => false, 'message' => 'El asesor no está asignado a este coordinador'];
            }
            
            $db = getDBConnection();
            $terminoBusqueda = '%' . $termino . '%';
            
            // Buscar gestiones por cédula, nombre, teléfono o operación
            $sql = "
                SELECT DISTINCT
                    hg.id_gestion,
                    hg.cliente_id,
                    hg.obligacion_id,
                    hg.canal_contacto,
                    hg.nivel1_tipo,
                    hg.nivel2_tipo,
                    hg.nivel3_tipo,
                    hg.nivel4_tipo,
                    hg.observaciones,
                    hg.llamada_telefonica,
                    hg.email,
                    hg.sms,
                    hg.correo_fisico,
                    hg.whatsapp,
                    hg.fecha_creacion,
                    hg.fecha_pago,
                    hg.valor_pago,
                    hg.cuota,
                    hg.cuota_actual,
                    hg.numero_contacto,
                    hg.duracion_segundos,
                    c.nombre as cliente_nombre,
                    c.cedula as cliente_cedula,
                    o.operacion as operacion_numero
                FROM historial_gestion hg
                INNER JOIN cliente c ON hg.cliente_id = c.id_cliente
                LEFT JOIN obligaciones o ON hg.obligacion_id = o.id_obligacion
                WHERE hg.asesor_cedula = ?
                AND (
                    c.cedula LIKE ? OR
                    c.nombre LIKE ? OR
                    c.tel1 LIKE ? OR c.tel2 LIKE ? OR c.tel3 LIKE ? OR c.tel4 LIKE ? OR c.tel5 LIKE ? OR
                    c.tel6 LIKE ? OR c.tel7 LIKE ? OR c.tel8 LIKE ? OR c.tel9 LIKE ? OR c.tel10 LIKE ? OR
                    o.operacion LIKE ?
                )
                ORDER BY hg.fecha_creacion DESC
                LIMIT 100
            ";
            
            $stmt = $db->prepare($sql);
            $params = array_merge(
                [$asesorCedula],
                array_fill(0, 13, $terminoBusqueda) // 1 cédula + 1 nombre + 10 teléfonos + 1 operación
            );
            $stmt->execute($params);
            $gestionesRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Formatear gestiones con información del cliente
            $gestiones = [];
            foreach ($gestionesRaw as $gestion) {
                $gestiones[] = [
                    'id_gestion' => $gestion['id_gestion'],
                    'cliente_id' => $gestion['cliente_id'],
                    'obligacion_id' => $gestion['obligacion_id'],
                    'canal_contacto' => $gestion['canal_contacto'],
                    'nivel1_tipo' => $gestion['nivel1_tipo'],
                    'nivel2_tipo' => $gestion['nivel2_tipo'],
                    'nivel2_clasificacion' => $gestion['nivel2_tipo'],
                    'nivel3_tipo' => $gestion['nivel3_tipo'],
                    'nivel3_detalle' => $gestion['nivel3_tipo'],
                    'nivel4_tipo' => $gestion['nivel4_tipo'],
                    'observaciones' => $gestion['observaciones'],
                    'llamada_telefonica' => $gestion['llamada_telefonica'],
                    'email' => $gestion['email'],
                    'sms' => $gestion['sms'],
                    'correo_fisico' => $gestion['correo_fisico'],
                    'whatsapp' => $gestion['whatsapp'],
                    'correo_electronico' => $gestion['email'],
                    'mensajeria_aplicacion' => 'no',
                    'fecha_creacion' => $gestion['fecha_creacion'],
                    'fecha_pago' => $gestion['fecha_pago'],
                    'valor_pago' => $gestion['valor_pago'],
                    'cuota' => $gestion['cuota'],
                    'cuota_actual' => $gestion['cuota_actual'],
                    'numero_contacto' => $gestion['numero_contacto'],
                    'duracion_segundos' => $gestion['duracion_segundos'],
                    'cliente_info' => [
                        'nombre' => $gestion['cliente_nombre'],
                        'identificacion' => $gestion['cliente_cedula'],
                        'cedula' => $gestion['cliente_cedula']
                    ],
                    'operacion' => $gestion['operacion_numero']
                ];
            }
            
            return [
                'success' => true,
                'gestiones' => $gestiones,
                'total' => count($gestiones)
            ];
        } catch (Exception $e) {
            error_log("CoordGestionController::buscarGestionesAsesorCoord - " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'gestiones' => []];
        }
    }
}
