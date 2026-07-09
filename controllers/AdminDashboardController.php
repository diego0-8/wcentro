<?php
/**
 * Controlador del Dashboard de Administrador
 * Obtiene datos para las pestañas: usuarios, asignaciones, estadísticas, actividad
 */

require_once __DIR__ . '/../models/Usuario.php';
require_once __DIR__ . '/../models/Asignacion.php';
require_once __DIR__ . '/../models/Campana.php';
require_once __DIR__ . '/../models/Cliente.php';

class AdminDashboardController {

    /**
     * Obtiene todos los datos necesarios para el dashboard del administrador
     * @return array{usuarios: array, asignaciones: array, estadisticas: array, coordinadores: array, campanas: array}
     */
    public function obtenerDatosDashboard() {
        try {
            $usuarioModel = new Usuario();
            $usuarios = $usuarioModel->obtenerTodos();
            if (!is_array($usuarios)) {
                $usuarios = [];
            }
            
            // Campañas (reemplaza asignaciones directas como fuente principal)
            $campanas = [];
            try {
                $campanaModel = new Campana();
                $campanas = $campanaModel->obtenerActivas();
            } catch (Exception $e) {
                error_log("AdminDashboardController: Error al obtener campañas - " . $e->getMessage());
            }

            // Asignaciones legacy (solo historial / compatibilidad UI)
            $asignaciones = [];
            try {
                $asignacionModel = new Asignacion();
                $asignaciones = $asignacionModel->obtenerTodos();
                if (!is_array($asignaciones)) {
                    $asignaciones = [];
                }
            } catch (Exception $e) {
                error_log("AdminDashboardController: Error al obtener asignaciones - " . $e->getMessage());
            }
            
            // Obtener clientes
            $clientes = [];
            try {
                $clienteModel = new Cliente();
                $clientes = $clienteModel->obtenerTodos();
                if (!is_array($clientes)) {
                    $clientes = [];
                }
            } catch (Exception $e) {
                error_log("AdminDashboardController: Error al obtener clientes - " . $e->getMessage());
            }
            
            // Obtener coordinadores (filtrar de usuarios)
            $coordinadores = array_filter($usuarios, function($u) {
                return strtolower($u['rol'] ?? '') === 'coordinador';
            });
            $coordinadores = array_values($coordinadores); // Reindexar
            
            // Calcular estadísticas
            $estadisticas = $this->calcularEstadisticas($usuarios, $asignaciones, $clientes, $campanas);
            
            return [
                'usuarios' => $usuarios,
                'asignaciones' => $asignaciones,
                'campanas' => $campanas,
                'estadisticas' => $estadisticas,
                'coordinadores' => $coordinadores,
            ];
        } catch (Exception $e) {
            error_log("AdminDashboardController: Error general - " . $e->getMessage());
            return [
                'usuarios' => [],
                'asignaciones' => [],
                'campanas' => [],
                'estadisticas' => $this->calcularEstadisticas([], [], [], []),
                'coordinadores' => [],
            ];
        }
    }

    /**
     * Calcula estadísticas a partir de los usuarios, asignaciones y clientes
     * @param array $usuarios
     * @param array $asignaciones
     * @param array $clientes
     * @return array
     */
    private function calcularEstadisticas($usuarios, $asignaciones = [], $clientes = [], $campanas = []) {
        $total = count($usuarios);
        $activos = 0;
        $coordinadores = 0;
        $coordinadoresDisponibles = 0;
        $asesores = 0;

        $campanaModel = new Campana();
        $usaCampanas = $campanaModel->tablaExiste() && !empty($campanas);
        $asesoresAsignados = $usaCampanas
            ? (int) array_sum(array_column($campanas, 'total_asesores'))
            : count(array_filter($asignaciones, function ($a) {
                return strtolower($a['estado'] ?? 'activa') === 'activa';
            }));
        
        foreach ($usuarios as $usuario) {
            $estado = strtolower($usuario['estado'] ?? '');
            if ($estado === 'activo') {
                $activos++;
            }
            
            $rol = strtolower($usuario['rol'] ?? '');
            if ($rol === 'coordinador') {
                $coordinadores++;
                if ($estado === 'activo') {
                    $coordinadoresDisponibles++;
                }
            } elseif ($rol === 'asesor') {
                $asesores++;
            }
        }
        
        // Asesores sin campaña activa
        $cedulasAsignadas = [];
        if ($usaCampanas) {
            try {
                $stmt = getDBConnection()->query("SELECT asesor_cedula FROM campana_asesores WHERE estado = 'activo'");
                if ($stmt) {
                    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $ced) {
                        $cedulasAsignadas[$ced] = true;
                    }
                }
            } catch (Exception $e) {
                error_log('AdminDashboardController::calcularEstadisticas campanas - ' . $e->getMessage());
            }
        } else {
            foreach ($asignaciones as $a) {
                if (strtolower($a['estado'] ?? 'activa') === 'activa' && !empty($a['asesor_cedula'])) {
                    $cedulasAsignadas[$a['asesor_cedula']] = true;
                }
            }
        }
        $asesoresSinCoordinador = [];
        foreach ($usuarios as $u) {
            $rol = strtolower($u['rol'] ?? '');
            $estado = strtolower($u['estado'] ?? '');
            if ($rol === 'asesor' && $estado === 'activo' && !isset($cedulasAsignadas[$u['cedula']])) {
                $asesoresSinCoordinador[] = $u;
            }
        }
        
        // Actividad reciente (por ahora vacía, se implementará cuando haya tabla de logs)
        $actividadReciente = [];
        
        return [
            'total_usuarios' => $total,
            'usuarios_activos' => $activos,
            'total_coordinadores' => $coordinadores,
            'coordinadores_disponibles' => $coordinadoresDisponibles,
            'total_asesores' => $asesores,
            'asesores_asignados' => $asesoresAsignados,
            'asesores_sin_coordinador' => $asesoresSinCoordinador,
            'total_clientes' => count($clientes),
            'clientes_nuevos' => count(array_filter($clientes, function($c) {
                $fechaCreacion = $c['fecha_creacion'] ?? null;
                if (!$fechaCreacion) return false;
                $fecha = strtotime($fechaCreacion);
                $hace30Dias = strtotime('-30 days');
                return $fecha >= $hace30Dias;
            })),
            'total_contratos' => 0, // Se implementará cuando haya tabla contratos
            'total_cartera' => 0,
            'clientes_gestionados' => 0,
            'clientes_pendientes' => 0,
            'total_campanas' => count($campanas),
            'campanas_activas' => count(array_filter($campanas, fn($c) => ($c['estado'] ?? '') === 'activa')),
            'actividad_reciente' => $actividadReciente,
        ];
    }

    /**
     * Carga de clientes desde el modal legacy del dashboard.
     * Redirige al flujo correcto según el rol administrador.
     * @return array{success: bool, message: string, redirect?: string}
     */
    public function cargarClientes() {
        return [
            'success' => false,
            'message' => 'La carga masiva de clientes se realiza desde Gestión del coordinador. Para historial de gestiones use Reportes.',
            'redirect' => 'index.php?action=admin_reportes',
        ];
    }

    /**
     * Generación de reportes desde el modal legacy del dashboard.
     * @return array{success: bool, message: string, redirect?: string}
     */
    public function generarReporte() {
        return [
            'success' => false,
            'message' => 'Use la sección Reportes para cargar historial CSV. Los reportes de gestión están disponibles en Exportación del coordinador.',
            'redirect' => 'index.php?action=admin_reportes',
        ];
    }
}
