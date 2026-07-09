<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers/auditoria.php';

$page_title = $page_title ?? 'Auditoría de Campaña';
$campana = $campana ?? [];
$registros = $registros ?? [];
$campanaId = (int) ($campana['id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include __DIR__ . '/partials/favicon.php'; ?>
    <title><?php echo htmlspecialchars($page_title); ?> - <?php echo APP_NAME; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="assets/css/common.css">
    <link rel="stylesheet" href="assets/css/admin-dashboard.css">
    <link rel="stylesheet" href="assets/css/admin_campanas.css">
</head>
<body>
    <?php include __DIR__ . '/Navbar.php'; ?>

    <div class="main-container">
        <?php include __DIR__ . '/Header.php'; ?>

        <section class="current-call-section campanas-module">
            <div class="page-intro">
                <h1><i class="fas fa-clock-rotate-left page-title-icon"></i>Auditoría: <?php echo htmlspecialchars($campana['nombre'] ?? ''); ?></h1>
                <p>Historial de acciones realizadas por coordinadores en esta campaña.</p>
            </div>

            <div class="campanas-card">
                <div class="campanas-card-header campanas-card-header--audit"><i class="fas fa-list-check"></i> Registro de actividades</div>
                <div class="campanas-card-body">
                    <?php if (empty($registros)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon"><i class="fas fa-clipboard-list"></i></div>
                            <strong>Sin registros</strong>
                            <p>Aún no hay acciones de coordinadores registradas en esta campaña.</p>
                        </div>
                    <?php else: ?>
                        <div class="campanas-table-wrap">
                        <table class="asignaciones-table">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Coordinador</th>
                                    <th>Acción</th>
                                    <th>Entidad</th>
                                    <th>Detalle</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($registros as $r):
                                $accionKey = $r['accion'] ?? '';
                            ?>
                                <tr>
                                    <td><span class="audit-fecha"><?php echo htmlspecialchars(auditoriaFormatearFecha($r['fecha'] ?? null)); ?></span></td>
                                    <td><?php echo htmlspecialchars($r['coordinador_nombre'] ?? $r['coordinador_cedula'] ?? ''); ?></td>
                                    <td><span class="audit-accion"><?php echo htmlspecialchars(auditoriaEtiquetaAccion($accionKey)); ?></span></td>
                                    <td><?php echo htmlspecialchars(auditoriaEtiquetaEntidad($r['entidad'] ?? '', $r['entidad_id'] ?? null)); ?></td>
                                    <td class="audit-detail-cell">
                                        <?php
                                        $detalle = $r['detalle'] ?? null;
                                        $accion = $accionKey;
                                        include __DIR__ . '/partials/auditoria_detalle.php';
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="breadcrumb-links">
                <a href="index.php?action=gestionar_campana&id=<?php echo $campanaId; ?>"><i class="fas fa-arrow-left"></i> Volver a la campaña</a>
                <span>·</span>
                <a href="index.php?action=list_campanas"><i class="fas fa-bullhorn"></i> Listado de campañas</a>
            </div>
        </section>
    </div>
</body>
</html>
