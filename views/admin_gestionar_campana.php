<?php
require_once __DIR__ . '/../config.php';

$page_title = $page_title ?? 'Gestionar Campaña';
$campana = $campana ?? [];
$coordinadores = $coordinadores ?? [];
$coordinadoresDisponibles = $coordinadoresDisponibles ?? [];
$asesores = $asesores ?? [];
$asesoresDisponibles = $asesoresDisponibles ?? [];
$success = $success ?? '';
$error = $error ?? '';
$campanaId = (int) ($campana['id'] ?? 0);
$campanaActiva = ($campana['estado'] ?? '') === 'activa';

$messages = [
    'campana_creada' => 'Campaña creada correctamente.',
    'campana_actualizada' => 'Campaña actualizada.',
    'coord_asignado' => 'Coordinador asignado.',
    'coord_liberado' => 'Coordinador liberado.',
    'asesor_asignado' => 'Asesor asignado a la campaña.',
    'asesor_liberado' => 'Asesor liberado de la campaña.',
    'campana_inhabilitada' => 'Campaña inhabilitada. La información se conserva.',
    'campana_habilitada' => 'Campaña habilitada nuevamente.',
];

$errorMessages = [
    'campana_inactiva' => 'No se pueden asignar personas mientras la campaña esté inhabilitada.',
    'error_estado' => 'No se pudo cambiar el estado de la campaña.',
    'datos_incompletos' => 'Datos incompletos para la operación.',
    'error_asignacion' => 'No se pudo completar la asignación.',
    'error_liberacion' => 'No se pudo completar la liberación.',
];
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
                <div class="page-intro-actions">
                    <div>
                        <h1><?php echo htmlspecialchars($campana['nombre'] ?? ''); ?></h1>
                        <p><?php echo htmlspecialchars($campana['descripcion'] ?? 'Sin descripción'); ?></p>
                    </div>
                    <?php if ($campanaActiva): ?>
                        <a class="btn btn-warning"
                           href="index.php?action=inhabilitar_campana&id=<?php echo $campanaId; ?>&return=gestionar"
                           onclick="return confirm('¿Inhabilitar esta campaña? Los datos se conservarán pero dejará de estar operativa.');">
                            <i class="fas fa-ban"></i> Inhabilitar campaña
                        </a>
                    <?php else: ?>
                        <a class="btn btn-success"
                           href="index.php?action=habilitar_campana&id=<?php echo $campanaId; ?>&return=gestionar"
                           onclick="return confirm('¿Habilitar esta campaña nuevamente?');">
                            <i class="fas fa-check"></i> Habilitar campaña
                        </a>
                    <?php endif; ?>
                </div>
                <div class="campana-meta">
                    <span class="campana-meta-item">
                        <i class="fas fa-signal"></i> Estado:
                        <span class="status-badge status-badge-<?php echo ($campana['estado'] ?? '') === 'activa' ? 'activa' : 'inactiva'; ?>">
                            <?php echo htmlspecialchars($campana['estado'] ?? ''); ?>
                        </span>
                    </span>
                    <span class="campana-meta-item"><i class="fas fa-user-tie"></i> <?php echo count($coordinadores); ?> coordinador(es)</span>
                    <span class="campana-meta-item"><i class="fas fa-headset"></i> <?php echo count($asesores); ?> asesor(es)</span>
                    <span class="campana-meta-item"><i class="fas fa-database"></i> <?php echo (int) ($campana['total_bases'] ?? 0); ?> base(s)</span>
                </div>
            </div>

            <?php if ($success && isset($messages[$success])): ?>
                <div class="alert-campana alert-campana-success"><?php echo $messages[$success]; ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <?php $errorText = $errorMessages[$error] ?? $error; ?>
                <div class="alert-campana alert-campana-error">Error: <?php echo htmlspecialchars($errorText); ?></div>
            <?php endif; ?>

            <?php if (!$campanaActiva): ?>
                <div class="alert-campana alert-campana-warning">
                    Esta campaña está <strong>inhabilitada</strong>. Coordinadores y asesores no podrán operarla, pero toda la información (bases, asignaciones y auditoría) se mantiene intacta.
                </div>
            <?php endif; ?>

            <div class="campanas-grid">
                <div class="campanas-card">
                    <div class="campanas-card-header campanas-card-header--coords"><i class="fas fa-user-tie"></i> Coordinadores</div>
                    <div class="campanas-card-body">
                        <?php if (empty($coordinadores)): ?>
                            <div class="empty-state">
                                <div class="empty-state-icon"><i class="fas fa-user-tie"></i></div>
                                <strong>Sin coordinadores</strong>
                                <p>Asigna al menos un coordinador para operar esta campaña.</p>
                            </div>
                        <?php else: ?>
                            <div class="campanas-table-wrap">
                            <table class="asignaciones-table">
                                <thead>
                                    <tr>
                                        <th>Nombre</th>
                                        <th>Usuario</th>
                                        <th>Acción</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($coordinadores as $c):
                                    $iniciales = strtoupper(substr($c['nombre_completo'] ?? 'C', 0, 1));
                                ?>
                                    <tr>
                                        <td>
                                            <div class="persona-cell">
                                                <span class="persona-avatar persona-avatar--coord"><?php echo htmlspecialchars($iniciales); ?></span>
                                                <span class="persona-nombre"><?php echo htmlspecialchars($c['nombre_completo']); ?></span>
                                            </div>
                                        </td>
                                        <td><span class="persona-usuario"><?php echo htmlspecialchars($c['usuario']); ?></span></td>
                                        <td>
                                            <a class="btn btn-sm btn-danger"
                                               href="index.php?action=liberar_coordinador_campana&campana_id=<?php echo $campanaId; ?>&coordinador_id=<?php echo urlencode($c['cedula']); ?>"
                                               onclick="return confirm('¿Liberar coordinador de esta campaña?');"><i class="fas fa-user-minus"></i> Liberar</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            </div>
                        <?php endif; ?>

                        <?php if ($campanaActiva && !empty($coordinadoresDisponibles)): ?>
                            <form method="post" action="index.php?action=asignar_coordinador_campana" class="assign-toolbar">
                                <input type="hidden" name="campana_id" value="<?php echo $campanaId; ?>">
                                <select name="coordinador_id" required>
                                    <option value="">Seleccionar coordinador...</option>
                                    <?php foreach ($coordinadoresDisponibles as $c): ?>
                                        <option value="<?php echo htmlspecialchars($c['cedula']); ?>"><?php echo htmlspecialchars($c['nombre_completo']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-primary"><i class="fas fa-user-plus"></i> Asignar coordinador</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="campanas-card">
                    <div class="campanas-card-header campanas-card-header--asesores"><i class="fas fa-headset"></i> Asesores</div>
                    <div class="campanas-card-body">
                        <?php if (empty($asesores)): ?>
                            <div class="empty-state">
                                <div class="empty-state-icon"><i class="fas fa-headset"></i></div>
                                <strong>Sin asesores</strong>
                                <p>Los asesores de la campaña podrán recibir acceso a bases desde el coordinador.</p>
                            </div>
                        <?php else: ?>
                            <div class="campanas-table-wrap">
                            <table class="asignaciones-table">
                                <thead>
                                    <tr>
                                        <th>Nombre</th>
                                        <th>Usuario</th>
                                        <th>Acción</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($asesores as $a):
                                    $iniciales = strtoupper(substr($a['nombre_completo'] ?? 'A', 0, 1));
                                ?>
                                    <tr>
                                        <td>
                                            <div class="persona-cell">
                                                <span class="persona-avatar persona-avatar--asesor"><?php echo htmlspecialchars($iniciales); ?></span>
                                                <span class="persona-nombre"><?php echo htmlspecialchars($a['nombre_completo']); ?></span>
                                            </div>
                                        </td>
                                        <td><span class="persona-usuario"><?php echo htmlspecialchars($a['usuario']); ?></span></td>
                                        <td>
                                            <a class="btn btn-sm btn-danger"
                                               href="index.php?action=liberar_asesor_campana&campana_id=<?php echo $campanaId; ?>&asesor_id=<?php echo urlencode($a['cedula']); ?>"
                                               onclick="return confirm('¿Liberar asesor de esta campaña?');"><i class="fas fa-user-minus"></i> Liberar</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            </div>
                        <?php endif; ?>

                        <?php if ($campanaActiva && !empty($asesoresDisponibles)): ?>
                            <form method="post" action="index.php?action=asignar_asesor_campana" class="assign-toolbar">
                                <input type="hidden" name="campana_id" value="<?php echo $campanaId; ?>">
                                <select name="asesor_id" required>
                                    <option value="">Seleccionar asesor...</option>
                                    <?php foreach ($asesoresDisponibles as $a): ?>
                                        <option value="<?php echo htmlspecialchars($a['cedula']); ?>"><?php echo htmlspecialchars($a['nombre_completo']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-primary"><i class="fas fa-user-plus"></i> Asignar asesor</button>
                            </form>
                        <?php elseif ($campanaActiva): ?>
                            <p class="alert-campana alert-campana-info assign-toolbar-info">No hay asesores disponibles sin campaña activa.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="breadcrumb-links">
                <a href="index.php?action=editar_campana&id=<?php echo $campanaId; ?>"><i class="fas fa-pen"></i> Editar campaña</a>
                <span>·</span>
                <a href="index.php?action=ver_auditoria_campana&id=<?php echo $campanaId; ?>"><i class="fas fa-clock-rotate-left"></i> Ver auditoría</a>
                <span>·</span>
                <a href="index.php?action=list_campanas"><i class="fas fa-arrow-left"></i> Volver al listado</a>
            </div>
        </section>
    </div>
</body>
</html>
