<?php
/**
 * Controlador de Campañas - Administrador
 */

require_once __DIR__ . '/../models/Campana.php';

class AdminCampanaController {

    private function adminCedula(): string {
        return (string) ($_SESSION['usuario_id'] ?? $_SESSION['usuario_cedula'] ?? '');
    }

    private function exigirCampanaActiva(int $campanaId, Campana $campanaModel): void {
        if (!$campanaModel->estaActiva($campanaId)) {
            header('Location: index.php?action=gestionar_campana&id=' . $campanaId . '&error=campana_inactiva');
            exit;
        }
    }

    public function asignarPersonalRedirect(): void {
        header('Location: index.php?action=list_campanas');
        exit;
    }

    public function listCampanas(): void {
        $campanaModel = new Campana();
        $campanas = $campanaModel->obtenerTodas();
        $success = $_GET['success'] ?? '';
        $error = $_GET['error'] ?? '';
        $page_title = 'Gestionar Campañas';
        require __DIR__ . '/../views/admin_campanas_list.php';
    }

    public function crearCampana(): void {
        $page_title = 'Crear Campaña';
        $error = '';
        $campana = null;
        $campanaModel = new Campana();
        $migracionPendiente = !$campanaModel->tablaExiste();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $nombre = trim($_POST['nombre'] ?? '');
            $descripcion = trim($_POST['descripcion'] ?? '');
            if ($nombre === '') {
                $error = 'El nombre de la campaña es obligatorio.';
            } else {
                $id = $campanaModel->crear($nombre, $descripcion, $this->adminCedula());
                if ($id) {
                    header('Location: index.php?action=gestionar_campana&id=' . $id . '&success=campana_creada');
                    exit;
                }
                $detalle = $campanaModel->getLastError();
                if (str_contains($detalle, "doesn't exist") || str_contains($detalle, 'no existe')) {
                    $error = 'Falta el esquema de campañas en la base de datos. Ejecute: php scripts/ejecutar_migracion_campanas.php';
                } elseif ($detalle !== '') {
                    $error = 'No se pudo crear la campaña: ' . $detalle;
                } else {
                    $error = 'No se pudo crear la campaña. Ejecute php scripts/ejecutar_migracion_campanas.php';
                }
            }
        }

        require __DIR__ . '/../views/admin_campana_form.php';
    }

    public function editarCampana(): void {
        $id = (int) ($_GET['id'] ?? 0);
        $campanaModel = new Campana();
        $campana = $campanaModel->obtenerPorId($id);
        if (!$campana) {
            header('Location: index.php?action=list_campanas&error=campana_no_encontrada');
            exit;
        }

        $page_title = 'Editar Campaña';
        $error = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $nombre = trim($_POST['nombre'] ?? '');
            $descripcion = trim($_POST['descripcion'] ?? '');
            $estado = $_POST['estado'] ?? 'activa';
            if ($nombre === '') {
                $error = 'El nombre es obligatorio.';
            } elseif ($campanaModel->actualizar($id, $nombre, $descripcion, $estado)) {
                header('Location: index.php?action=gestionar_campana&id=' . $id . '&success=campana_actualizada');
                exit;
            } else {
                $error = 'No se pudo actualizar la campaña.';
            }
            $campana = $campanaModel->obtenerPorId($id);
        }

        require __DIR__ . '/../views/admin_campana_form.php';
    }

    public function gestionarCampana(): void {
        $id = (int) ($_GET['id'] ?? 0);
        $campanaModel = new Campana();
        $campana = $campanaModel->obtenerPorId($id);
        if (!$campana) {
            header('Location: index.php?action=list_campanas&error=campana_no_encontrada');
            exit;
        }

        $page_title = 'Gestionar Campaña: ' . $campana['nombre'];
        $coordinadores = $campanaModel->getCoordinadoresByCampana($id);
        $coordinadoresDisponibles = $campanaModel->getCoordinadoresDisponibles($id);
        $asesores = $campanaModel->getAsesoresByCampana($id);
        $asesoresDisponibles = $campanaModel->getAsesoresDisponibles($id);
        $success = $_GET['success'] ?? '';
        $error = $_GET['error'] ?? '';

        require __DIR__ . '/../views/admin_gestionar_campana.php';
    }

    public function asignarCoordinadorCampana(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: index.php?action=list_campanas');
            exit;
        }
        $campanaId = (int) ($_POST['campana_id'] ?? 0);
        $coordId = trim($_POST['coordinador_id'] ?? '');
        if ($campanaId <= 0 || $coordId === '') {
            header('Location: index.php?action=gestionar_campana&id=' . $campanaId . '&error=datos_incompletos');
            exit;
        }
        $campanaModel = new Campana();
        $this->exigirCampanaActiva($campanaId, $campanaModel);
        $ok = $campanaModel->asignarCoordinador($campanaId, $coordId, $this->adminCedula());
        header('Location: index.php?action=gestionar_campana&id=' . $campanaId . '&' . ($ok ? 'success=coord_asignado' : 'error=error_asignacion'));
        exit;
    }

    public function liberarCoordinadorCampana(): void {
        $campanaId = (int) ($_GET['campana_id'] ?? 0);
        $coordId = trim($_GET['coordinador_id'] ?? '');
        if ($campanaId <= 0 || $coordId === '') {
            header('Location: index.php?action=list_campanas&error=datos_incompletos');
            exit;
        }
        $campanaModel = new Campana();
        $ok = $campanaModel->liberarCoordinador($campanaId, $coordId);
        header('Location: index.php?action=gestionar_campana&id=' . $campanaId . '&' . ($ok ? 'success=coord_liberado' : 'error=error_liberacion'));
        exit;
    }

    public function asignarAsesorCampana(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: index.php?action=list_campanas');
            exit;
        }
        $campanaId = (int) ($_POST['campana_id'] ?? 0);
        $asesorId = trim($_POST['asesor_id'] ?? '');
        if ($campanaId <= 0 || $asesorId === '') {
            header('Location: index.php?action=gestionar_campana&id=' . $campanaId . '&error=datos_incompletos');
            exit;
        }
        $campanaModel = new Campana();
        $this->exigirCampanaActiva($campanaId, $campanaModel);
        $ok = $campanaModel->asignarAsesor($campanaId, $asesorId, $this->adminCedula());
        header('Location: index.php?action=gestionar_campana&id=' . $campanaId . '&' . ($ok ? 'success=asesor_asignado' : 'error=error_asignacion'));
        exit;
    }

    public function liberarAsesorCampana(): void {
        $campanaId = (int) ($_GET['campana_id'] ?? 0);
        $asesorId = trim($_GET['asesor_id'] ?? '');
        if ($campanaId <= 0 || $asesorId === '') {
            header('Location: index.php?action=list_campanas&error=datos_incompletos');
            exit;
        }
        $campanaModel = new Campana();
        $ok = $campanaModel->liberarAsesor($campanaId, $asesorId);
        header('Location: index.php?action=gestionar_campana&id=' . $campanaId . '&' . ($ok ? 'success=asesor_liberado' : 'error=error_liberacion'));
        exit;
    }

    public function verAuditoriaCampana(): void {
        $id = (int) ($_GET['id'] ?? 0);
        $campanaModel = new Campana();
        $campana = $campanaModel->obtenerPorId($id);
        if (!$campana) {
            header('Location: index.php?action=list_campanas&error=campana_no_encontrada');
            exit;
        }
        $page_title = 'Auditoría: ' . $campana['nombre'];
        $registros = $campanaModel->getAuditoriaByCampana($id);
        require __DIR__ . '/../views/admin_auditoria_campana.php';
    }

    public function inhabilitarCampana(): void {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            header('Location: index.php?action=list_campanas&error=datos_incompletos');
            exit;
        }
        $campanaModel = new Campana();
        $ok = $campanaModel->cambiarEstado($id, 'inactiva');
        $return = $_GET['return'] ?? 'list';
        if ($return === 'gestionar') {
            header('Location: index.php?action=gestionar_campana&id=' . $id . '&' . ($ok ? 'success=campana_inhabilitada' : 'error=error_estado'));
        } else {
            header('Location: index.php?action=list_campanas&' . ($ok ? 'success=campana_inhabilitada' : 'error=error_estado'));
        }
        exit;
    }

    public function habilitarCampana(): void {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            header('Location: index.php?action=list_campanas&error=datos_incompletos');
            exit;
        }
        $campanaModel = new Campana();
        $ok = $campanaModel->cambiarEstado($id, 'activa');
        $return = $_GET['return'] ?? 'list';
        if ($return === 'gestionar') {
            header('Location: index.php?action=gestionar_campana&id=' . $id . '&' . ($ok ? 'success=campana_habilitada' : 'error=error_estado'));
        } else {
            header('Location: index.php?action=list_campanas&' . ($ok ? 'success=campana_habilitada' : 'error=error_estado'));
        }
        exit;
    }

    public function auditoriaCoordinadoresGlobal(): void {
        require_once __DIR__ . '/../models/Usuario.php';
        $campanaModel = new Campana();
        $usuarioModel = new Usuario();

        $fechaDesde = trim($_GET['fecha_desde'] ?? '');
        $fechaHasta = trim($_GET['fecha_hasta'] ?? '');
        $coordinadorCedula = trim($_GET['coordinador'] ?? '');
        $campanaId = (int) ($_GET['campana_id'] ?? 0);

        $registros = $campanaModel->getAuditoriaAdmin(
            $fechaDesde !== '' ? $fechaDesde : null,
            $fechaHasta !== '' ? $fechaHasta : null,
            $coordinadorCedula !== '' ? $coordinadorCedula : null,
            $campanaId > 0 ? $campanaId : null
        );

        $coordinadores = array_values(array_filter($usuarioModel->obtenerTodos(), function ($u) {
            return strtolower($u['rol'] ?? '') === 'coordinador';
        }));
        $campanas = $campanaModel->obtenerTodas();

        $page_title = 'Historial de coordinadores';
        $filtros = [
            'fecha_desde' => $fechaDesde,
            'fecha_hasta' => $fechaHasta,
            'coordinador' => $coordinadorCedula,
            'campana_id' => $campanaId,
        ];

        require __DIR__ . '/../views/admin_auditoria_coordinadores.php';
    }
}
