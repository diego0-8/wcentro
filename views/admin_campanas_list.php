<?php
require_once __DIR__ . '/../config.php';

$page_title = $page_title ?? 'Campañas';
$campanas = $campanas ?? [];
$success = $success ?? '';
$error = $error ?? '';

$totalCampanas = count($campanas);
$totalActivas = count(array_filter($campanas, fn($c) => ($c['estado'] ?? '') === 'activa'));
$totalCoords = array_sum(array_column($campanas, 'total_coordinadores'));
$totalAsesores = array_sum(array_column($campanas, 'total_asesores'));
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
                        <h1>Gestionar Campañas</h1>
                        <p>Organiza coordinadores, asesores y bases por campaña de cobranza.</p>
                    </div>
                    <a href="index.php?action=crear_campana" class="btn btn-success"><i class="fas fa-plus"></i> Nueva campaña</a>
                    <a href="index.php?action=admin_auditoria_coordinadores" class="btn btn-secondary"><i class="fas fa-clock-rotate-left"></i> Historial coordinadores</a>
                </div>
            </div>

            <?php if ($success): ?>
                <?php
                $listMessages = [
                    'campana_inhabilitada' => 'Campaña inhabilitada. La información se conserva y puede rehabilitarse cuando lo necesite.',
                    'campana_habilitada' => 'Campaña habilitada nuevamente.',
                ];
                $successText = $listMessages[$success] ?? 'Operación realizada correctamente.';
                ?>
                <div class="alert-campana alert-campana-success"><?php echo htmlspecialchars($successText); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert-campana alert-campana-error">Error: <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="campanas-stats">
                <div class="campanas-stat-card">
                    <span class="stat-icon"><i class="fas fa-bullhorn"></i></span>
                    <div class="value"><?php echo $totalCampanas; ?></div>
                    <div class="label">Campañas</div>
                </div>
                <div class="campanas-stat-card campanas-stat-card--activas">
                    <span class="stat-icon"><i class="fas fa-circle-check"></i></span>
                    <div class="value"><?php echo $totalActivas; ?></div>
                    <div class="label">Activas</div>
                </div>
                <div class="campanas-stat-card campanas-stat-card--coords">
                    <span class="stat-icon"><i class="fas fa-user-tie"></i></span>
                    <div class="value"><?php echo $totalCoords; ?></div>
                    <div class="label">Coordinadores</div>
                </div>
                <div class="campanas-stat-card campanas-stat-card--asesores">
                    <span class="stat-icon"><i class="fas fa-headset"></i></span>
                    <div class="value"><?php echo $totalAsesores; ?></div>
                    <div class="label">Asesores</div>
                </div>
            </div>

            <div class="campanas-card">
                <div class="campanas-card-header"><i class="fas fa-list"></i> Listado de campañas</div>
                <div class="campanas-card-body">
                    <?php if (empty($campanas)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon"><i class="fas fa-bullhorn"></i></div>
                            <strong>No hay campañas registradas</strong>
                            <p>Crea la primera campaña para asignar coordinadores y asesores.</p>
                        </div>
                        <div class="card-actions">
                            <a href="index.php?action=crear_campana" class="btn btn-success"><i class="fas fa-plus"></i> Crear campaña</a>
                        </div>
                    <?php else: ?>
                        <div class="campanas-table-wrap">
                            <table class="asignaciones-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Nombre</th>
                                        <th>Estado</th>
                                        <th>Coordinadores</th>
                                        <th>Asesores</th>
                                        <th>Bases</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($campanas as $c): ?>
                                    <?php $esActiva = ($c['estado'] ?? '') === 'activa'; ?>
                                    <tr class="<?php echo $esActiva ? '' : 'campana-row-inactiva'; ?>">
                                        <td class="campana-id-cell">#<?php echo (int) $c['id']; ?></td>
                                        <td class="campana-nombre-cell"><strong><?php echo htmlspecialchars($c['nombre']); ?></strong></td>
                                        <td>
                                            <span class="status-badge status-badge-<?php echo $c['estado'] === 'activa' ? 'activa' : 'inactiva'; ?>">
                                                <?php echo htmlspecialchars($c['estado']); ?>
                                            </span>
                                        </td>
                                        <td><span class="campana-metric"><?php echo (int) $c['total_coordinadores']; ?></span></td>
                                        <td><span class="campana-metric"><?php echo (int) $c['total_asesores']; ?></span></td>
                                        <td><span class="campana-metric campana-metric--bases"><?php echo (int) $c['total_bases']; ?></span></td>
                                        <td>
                                            <div class="table-actions">
                                                <a class="btn btn-sm btn-primary" href="index.php?action=gestionar_campana&id=<?php echo (int) $c['id']; ?>"><i class="fas fa-sliders"></i> Gestionar</a>
                                                <a class="btn btn-sm btn-secondary" href="index.php?action=editar_campana&id=<?php echo (int) $c['id']; ?>"><i class="fas fa-pen"></i> Editar</a>
                                                <a class="btn btn-sm btn-outline-secondary" href="index.php?action=ver_auditoria_campana&id=<?php echo (int) $c['id']; ?>"><i class="fas fa-clock-rotate-left"></i> Auditoría</a>
                                                <?php if ($esActiva): ?>
                                                    <a class="btn btn-sm btn-warning"
                                                       href="index.php?action=inhabilitar_campana&id=<?php echo (int) $c['id']; ?>"
                                                       onclick="return confirm('¿Inhabilitar esta campaña? Los datos se conservarán pero dejará de estar operativa.');"><i class="fas fa-ban"></i> Inhabilitar</a>
                                                <?php else: ?>
                                                    <a class="btn btn-sm btn-success"
                                                       href="index.php?action=habilitar_campana&id=<?php echo (int) $c['id']; ?>"
                                                       onclick="return confirm('¿Habilitar esta campaña nuevamente?');"><i class="fas fa-check"></i> Habilitar</a>
                                                <?php endif; ?>
                                            </div>
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
                <a href="index.php?action=dashboard"><i class="fas fa-arrow-left"></i> Volver al panel</a>
            </div>
        </section>
    </div>
</body>
</html>
