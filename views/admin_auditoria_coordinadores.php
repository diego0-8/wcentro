<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers/auditoria.php';

$page_title = $page_title ?? 'Historial de coordinadores';
$registros = $registros ?? [];
$coordinadores = $coordinadores ?? [];
$campanas = $campanas ?? [];
$filtros = $filtros ?? [];
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
                <h1><i class="fas fa-clock-rotate-left page-title-icon"></i>Historial de coordinadores</h1>
                <p>Registro de acciones realizadas por coordinadores. Ordenado de la más reciente a la más antigua.</p>
            </div>

            <div class="campanas-card">
                <div class="campanas-card-header campanas-card-header--audit"><i class="fas fa-filter"></i> Filtros</div>
                <div class="campanas-card-body">
                    <form method="get" action="index.php" class="assign-toolbar" style="margin-top:0;padding-top:0;border-top:none;">
                        <input type="hidden" name="action" value="admin_auditoria_coordinadores">
                        <div class="campanas-form-group" style="margin-bottom:0;flex:1;min-width:160px;">
                            <label for="fecha_desde">Desde</label>
                            <input type="date" id="fecha_desde" name="fecha_desde" value="<?php echo htmlspecialchars($filtros['fecha_desde'] ?? ''); ?>">
                        </div>
                        <div class="campanas-form-group" style="margin-bottom:0;flex:1;min-width:160px;">
                            <label for="fecha_hasta">Hasta</label>
                            <input type="date" id="fecha_hasta" name="fecha_hasta" value="<?php echo htmlspecialchars($filtros['fecha_hasta'] ?? ''); ?>">
                        </div>
                        <div class="campanas-form-group" style="margin-bottom:0;flex:1;min-width:200px;">
                            <label for="coordinador">Coordinador</label>
                            <select id="coordinador" name="coordinador">
                                <option value="">Todos</option>
                                <?php foreach ($coordinadores as $c): ?>
                                    <option value="<?php echo htmlspecialchars($c['cedula']); ?>" <?php echo ($filtros['coordinador'] ?? '') === $c['cedula'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($c['nombre'] ?? $c['nombre_completo'] ?? $c['cedula']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="campanas-form-group" style="margin-bottom:0;flex:1;min-width:200px;">
                            <label for="campana_id">Campaña</label>
                            <select id="campana_id" name="campana_id">
                                <option value="">Todas</option>
                                <?php foreach ($campanas as $camp): ?>
                                    <option value="<?php echo (int) $camp['id']; ?>" <?php echo (int)($filtros['campana_id'] ?? 0) === (int)$camp['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($camp['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="card-actions" style="align-self:flex-end;margin:0;">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Filtrar</button>
                            <a href="index.php?action=admin_auditoria_coordinadores" class="btn btn-secondary">Limpiar</a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="campanas-card">
                <div class="campanas-card-header campanas-card-header--audit">
                    <i class="fas fa-list-check"></i> Registros (<?php echo count($registros); ?>)
                </div>
                <div class="campanas-card-body">
                    <?php if (empty($registros)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon"><i class="fas fa-clipboard-list"></i></div>
                            <strong>Sin registros</strong>
                            <p>No hay acciones que coincidan con los filtros seleccionados.</p>
                        </div>
                    <?php else: ?>
                        <div class="campanas-table-wrap">
                            <table class="asignaciones-table">
                                <thead>
                                    <tr>
                                        <th>Fecha</th>
                                        <th>Coordinador</th>
                                        <th>Campaña</th>
                                        <th>Acción</th>
                                        <th>Entidad</th>
                                        <th>Detalle</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($registros as $r):
                                    $accionKey = $r['accion'] ?? '';
                                    $accionLabel = auditoriaEtiquetaAccion($accionKey);
                                ?>
                                    <tr>
                                        <td><span class="audit-fecha"><?php echo htmlspecialchars(auditoriaFormatearFecha($r['fecha'] ?? null)); ?></span></td>
                                        <td><?php echo htmlspecialchars($r['coordinador_nombre'] ?? $r['coordinador_cedula'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($r['campana_nombre'] ?? '—'); ?></td>
                                        <td><span class="audit-accion"><?php echo htmlspecialchars($accionLabel); ?></span></td>
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
                <a href="index.php?action=list_campanas"><i class="fas fa-arrow-left"></i> Volver a campañas</a>
                <span>·</span>
                <a href="index.php?action=dashboard"><i class="fas fa-th-large"></i> Panel admin</a>
            </div>
        </section>
    </div>
</body>
</html>
