<?php require_once __DIR__ . '/../config.php';

// Inyectadas por index.php al cargar esta vista (AdminDashboardController).
$coordinadores = (isset($coordinadores) && is_array($coordinadores)) ? $coordinadores : [];
$estadisticas = (isset($estadisticas) && is_array($estadisticas)) ? $estadisticas : [];
$asignacionesLista = (isset($asignaciones) && is_array($asignaciones)) ? $asignaciones : [];
$asesoresDisponibles = $estadisticas['asesores_sin_coordinador'] ?? [];
$totalAsignaciones = count($asignacionesLista);
$asignacionesActivas = array_filter($asignacionesLista, function ($a) {
    return strtolower($a['estado'] ?? '') === 'activa';
});
$asesoresAsignadosCount = count($asignacionesActivas);
$totalAsesoresActivos = (int) ($estadisticas['total_asesores'] ?? 0);
$asesoresSinAsignarCount = count($asesoresDisponibles);
$tasaAsignacion = $totalAsesoresActivos > 0
    ? (int) round(($asesoresAsignadosCount / $totalAsesoresActivos) * 100)
    : 0;

$historialAsignaciones = $asignacionesLista;
usort($historialAsignaciones, function ($a, $b) {
    $fechaA = strtotime($a['fecha_asignacion'] ?? $a['fecha_creacion'] ?? '1970-01-01');
    $fechaB = strtotime($b['fecha_asignacion'] ?? $b['fecha_creacion'] ?? '1970-01-01');
    return $fechaB <=> $fechaA;
});
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include __DIR__ . '/partials/favicon.php'; ?>
    <title>Asignar Personal - <?php echo APP_NAME; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="assets/css/common.css">
    <link rel="stylesheet" href="assets/css/admin-dashboard.css">
</head>
<body>

    <div class="sidebar">
        <div class="sidebar-logo"><?php echo APP_NAME; ?></div>
        <nav class="sidebar-nav">
            <ul>
                <li onclick="window.location.href='index.php?action=dashboard'"><i class="fas fa-th-large"></i> Dashboard</li>
                <li onclick="window.location.href='index.php?action=admin_usuarios'"><i class="fas fa-users"></i> Usuarios</li>
                <li class="active" onclick="window.location.href='index.php?action=admin_asignaciones'"><i class="fas fa-user-friends"></i> Asignaciones</li>
            </ul>
        </nav>
        
        <!-- Botón de Cerrar Sesión en la parte inferior -->
        <div class="sidebar-footer">
            <a href="index.php?action=logout" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i>
                <span>Cerrar Sesión</span>
            </a>
        </div>
    </div>

    <div class="main-container">
        <!-- Encabezado Superior -->
        <header class="top-header">
            <div class="header-left">
                <i class="fas fa-user-friends"></i>
                <span>Asignar Personal</span>
                <span><?php echo $_SESSION['usuario_nombre'] ?? 'Usuario'; ?></span>
            </div>
            <div class="header-right">
                <span><i class="fas fa-circle-info"></i></span>
                <span><i class="fas fa-bell"></i></span>
                <img src="https://placehold.co/30x30/FFFFFF/000000?text=<?php echo substr($_SESSION['usuario_nombre'] ?? 'A', 0, 1); ?>" class="user-avatar-img" alt="">
                <span><?php echo $_SESSION['usuario_nombre'] ?? 'Admin'; ?> <i class="fas fa-caret-down"></i></span>
            </div>
        </header>

        <!-- Sección Principal -->
        <section class="current-call-section">
            <div class="call-details">
                <h3>GESTIÓN DE ASIGNACIONES</h3>
                <p class="call-info">Sistema <?php echo APP_NAME; ?></p>
                <p class="call-info">Asignación de Personal</p>
                <small>Administre las asignaciones de coordinadores y asesores</small>
                <div class="media-controls">
                    <button class="media-button" onclick="window.location.href='index.php?action=dashboard'">
                        <i class="fas fa-arrow-left"></i> Volver al Dashboard
                    </button>
                    <button class="media-button" onclick="cambiarTab('asignar')">
                        <i class="fas fa-user-plus"></i> Nueva Asignación
                    </button>
                </div>
            </div>
            
            <div class="call-main-view">
                <div class="client-info">
                    <i class="fas fa-user-friends"></i>
                    <div>
                        <span class="client-name">Panel de Asignaciones</span>
                        <span class="client-company"><?php echo APP_NAME; ?> - Administración</span>
                    </div>
                </div>

                <div class="main-tabs">
                    <span class="active" onclick="cambiarTab('asignar')">ASIGNAR</span>
                    <span onclick="cambiarTab('gestionar')">GESTIONAR</span>
                    <span onclick="cambiarTab('estadisticas')">ESTADÍSTICAS</span>
                    <span onclick="cambiarTab('historial')">HISTORIAL</span>
                </div>
                
                <div class="content-sections">
                    <!-- PESTAÑA 1: ASIGNAR -->
                    <div class="tab-content active" id="tab-asignar">
                        <div class="left-content">
                            <h4 class="section-heading">Nueva Asignación de Personal</h4>
                            <form id="form-asignar-personal" onsubmit="asignarPersonal(event)">
                                <div class="form-section">
                                    <div class="input-group">
                                        <label for="asesor_id">Asesor *</label>
                                        <select id="asesor_id" name="asesor_id" required>
                                            <option value="">Seleccionar asesor</option>
                                            <?php if (!empty($asesoresDisponibles)): ?>
                                                <?php foreach ($asesoresDisponibles as $asesor): ?>
                                                <option value="<?php echo htmlspecialchars($asesor['cedula']); ?>"><?php echo htmlspecialchars($asesor['nombre_completo']); ?> (<?php echo htmlspecialchars($asesor['usuario']); ?>)</option>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <option value="" disabled>No hay asesores disponibles sin coordinador</option>
                                            <?php endif; ?>
                                        </select>
                                        <small>Seleccione el asesor a asignar</small>
                                    </div>
                                    <div class="input-group">
                                        <label for="coordinador_id">Coordinador *</label>
                                        <select id="coordinador_id" name="coordinador_id" required>
                                            <option value="">Seleccionar coordinador</option>
                                            <?php foreach ($coordinadores as $coord): ?>
                                                <option value="<?php echo htmlspecialchars($coord['cedula']); ?>"><?php echo htmlspecialchars($coord['nombre_completo']); ?> (<?php echo htmlspecialchars($coord['usuario']); ?>)</option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small>Seleccione el coordinador responsable</small>
                                    </div>
                                </div>
                                
                                <div class="form-section">
                                    <div class="input-group">
                                        <label for="fecha_asignacion">Fecha de Asignación</label>
                                        <input type="date" id="fecha_asignacion" name="fecha_asignacion" value="<?php echo date('Y-m-d'); ?>">
                                        <small>Fecha en que se efectúa la asignación</small>
                                    </div>
                                    <div class="input-group">
                                        <label for="notas_asignacion">Notas de Asignación</label>
                                        <textarea id="notas_asignacion" name="notas_asignacion" rows="3" placeholder="Información adicional sobre la asignación..."></textarea>
                                        <small>Comentarios sobre la asignación (opcional)</small>
                                    </div>
                                </div>
                                
                                <div class="form-actions">
                                    <button type="button" class="btn btn-secondary" onclick="limpiarFormulario()">
                                        <i class="fas fa-eraser"></i> Limpiar
                                    </button>
                                    <button type="submit" class="btn btn-primary" id="btn-asignar">
                                        <i class="fas fa-user-friends"></i> Asignar Personal
                                    </button>
                                </div>
                            </form>
                        </div>
                        
                        <aside class="right-sidebar">
                            <h4>Información de Asignación</h4>
                            <div class="info-card">
                                <i class="fas fa-info-circle"></i>
                                <div>
                                    <h5>Proceso de Asignación</h5>
                                    <p>Los asesores deben ser asignados a un coordinador para poder trabajar en el sistema.</p>
                                </div>
                            </div>
                            
                            <div class="info-card">
                                <i class="fas fa-users"></i>
                                <div>
                                    <h5>Disponibilidad</h5>
                                    <p><strong>Asesores sin coordinador:</strong> <?php echo $asesoresSinAsignarCount; ?> disponibles</p>
                                    <p><strong>Coordinadores activos:</strong> <?php echo count($coordinadores); ?></p>
                                </div>
                            </div>
                            
                            <div class="info-card">
                                <i class="fas fa-shield-alt"></i>
                                <div>
                                    <h5>Permisos</h5>
                                    <p>Los coordinadores pueden gestionar y supervisar a sus asesores asignados.</p>
                                </div>
                            </div>
                        </aside>
                    </div>

                    <!-- PESTAÑA 2: GESTIONAR -->
                    <div class="tab-content" id="tab-gestionar">
                        <div class="left-content">
                            <h4 class="section-heading">Gestionar Asignaciones Existentes</h4>
                            
                            <!-- Filtros -->
                            <div class="filters-section">
                                <div class="filter-group">
                                    <label for="filtro_coordinador">Filtrar por Coordinador</label>
                                    <select id="filtro_coordinador" onchange="filtrarAsignaciones()">
                                        <option value="">Todos los coordinadores</option>
                                        <?php foreach ($coordinadores as $coord): ?>
                                            <option value="<?php echo $coord['cedula']; ?>"><?php echo htmlspecialchars($coord['nombre_completo']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="filter-group">
                                    <label for="filtro_estado">Filtrar por Estado</label>
                                    <select id="filtro_estado" onchange="filtrarAsignaciones()">
                                        <option value="">Todos los estados</option>
                                        <option value="activa">Activa</option>
                                        <option value="inactiva">Inactiva</option>
                                    </select>
                                </div>
                            </div>
                            
                            <!-- Lista de asignaciones -->
                            <div class="assignments-list" id="assignments-list">
                                <?php if (!empty($asignacionesLista)): ?>
                                    <?php foreach ($asignacionesLista as $asignacion): ?>
                                        <?php
                                        $estadoAsignacion = strtolower($asignacion['estado'] ?? 'activa');
                                        $fechaAsignacion = $asignacion['fecha_asignacion'] ?? $asignacion['fecha_creacion'] ?? null;
                                        ?>
                                        <div class="assignment-item"
                                             data-asignacion-id="<?php echo (int) ($asignacion['id'] ?? 0); ?>"
                                             data-coordinador-cedula="<?php echo htmlspecialchars($asignacion['coordinador_cedula'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                             data-estado="<?php echo htmlspecialchars($estadoAsignacion, ENT_QUOTES, 'UTF-8'); ?>">
                                            <div class="assignment-header">
                                                <h5>
                                                    <?php echo htmlspecialchars($asignacion['asesor_nombre'] ?? 'Asesor'); ?>
                                                    →
                                                    <?php echo htmlspecialchars($asignacion['coordinador_nombre'] ?? 'Coordinador'); ?>
                                                </h5>
                                                <div class="assignment-actions">
                                                    <?php if ($estadoAsignacion === 'activa'): ?>
                                                        <button type="button" class="btn btn-sm btn-warning" onclick="liberarAsignacion(<?php echo (int) ($asignacion['id'] ?? 0); ?>)" title="Liberar asesor">
                                                            <i class="fas fa-unlink"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <button type="button" class="btn btn-sm btn-danger" onclick="eliminarAsignacion(<?php echo (int) ($asignacion['id'] ?? 0); ?>)" title="Eliminar asignación">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </div>
                                            <div class="assignment-details">
                                                <div class="detail-item">
                                                    <span class="detail-label">Asesor (cédula):</span>
                                                    <span class="detail-value"><?php echo htmlspecialchars($asignacion['asesor_cedula'] ?? ''); ?></span>
                                                </div>
                                                <div class="detail-item">
                                                    <span class="detail-label">Estado:</span>
                                                    <span class="detail-value status-<?php echo $estadoAsignacion === 'activa' ? 'active' : 'inactive'; ?>">
                                                        <?php echo ucfirst($estadoAsignacion); ?>
                                                    </span>
                                                </div>
                                                <div class="detail-item">
                                                    <span class="detail-label">Fecha:</span>
                                                    <span class="detail-value">
                                                        <?php echo $fechaAsignacion ? date('d/m/Y H:i', strtotime($fechaAsignacion)) : '—'; ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="assignment-item empty" id="assignments-empty-state">
                                        <div class="empty-state">
                                            <i class="fas fa-user-friends"></i>
                                            <h5>No hay asignaciones</h5>
                                            <p>Las asignaciones aparecerán aquí una vez que se creen.</p>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <aside class="right-sidebar">
                            <h4>Acciones Rápidas</h4>
                            <div class="quick-actions">
                                <button class="action-btn" onclick="cambiarTab('asignar')">
                                    <i class="fas fa-user-plus"></i>
                                    Nueva Asignación
                                </button>
                                <button class="action-btn" onclick="exportarAsignaciones()">
                                    <i class="fas fa-download"></i>
                                    Exportar Lista
                                </button>
                                <button class="action-btn" onclick="actualizarAsignaciones()">
                                    <i class="fas fa-sync"></i>
                                    Actualizar
                                </button>
                            </div>
                            
                            <div class="info-card">
                                <i class="fas fa-chart-pie"></i>
                                <div>
                                    <h5>Resumen</h5>
                                    <p><strong>Total Asignaciones:</strong> <?php echo $totalAsignaciones; ?></p>
                                    <p><strong>Asesores Asignados:</strong> <?php echo $asesoresAsignadosCount; ?></p>
                                    <p><strong>Coordinadores Activos:</strong> <?php echo count($coordinadores); ?></p>
                                </div>
                            </div>
                        </aside>
                    </div>

                    <!-- PESTAÑA 3: ESTADÍSTICAS -->
                    <div class="tab-content" id="tab-estadisticas">
                        <div class="left-content">
                            <h4 class="section-heading">Estadísticas de Asignaciones</h4>
                            
                            <div class="stats-grid">
                                <div class="stat-card">
                                    <div class="stat-icon">
                                        <i class="fas fa-users"></i>
                                    </div>
                                    <div class="stat-content">
                                        <h5>Total Asesores</h5>
                                        <div class="stat-value"><?php echo $totalAsesoresActivos; ?></div>
                                        <div class="stat-subtitle">Activos en el sistema</div>
                                    </div>
                                </div>
                                
                                <div class="stat-card">
                                    <div class="stat-icon">
                                        <i class="fas fa-user-check"></i>
                                    </div>
                                    <div class="stat-content">
                                        <h5>Asesores Asignados</h5>
                                        <div class="stat-value"><?php echo $asesoresAsignadosCount; ?></div>
                                        <div class="stat-subtitle">Con coordinador activo</div>
                                    </div>
                                </div>
                                
                                <div class="stat-card">
                                    <div class="stat-icon">
                                        <i class="fas fa-user-times"></i>
                                    </div>
                                    <div class="stat-content">
                                        <h5>Sin Asignar</h5>
                                        <div class="stat-value"><?php echo $asesoresSinAsignarCount; ?></div>
                                        <div class="stat-subtitle">Pendientes</div>
                                    </div>
                                </div>
                                

                                <div class="stat-card">
                                    <div class="stat-icon">
                                        <i class="fas fa-user-shield"></i>
                                    </div>
                                    <div class="stat-content">
                                        <h5>Coordinadores</h5>
                                        <div class="stat-value"><?php echo count($coordinadores); ?></div>
                                        <div class="stat-subtitle">Activos</div>
                                    </div>
                                </div>
                            </div>

                            <div class="chart-section">
                                <h5>Distribución por Coordinador</h5>
                                <?php if ($asesoresAsignadosCount > 0): ?>
                                    <div class="chart-placeholder" style="text-align:left;padding:1rem;">
                                        <?php
                                        $porCoordinador = [];
                                        foreach ($asignacionesActivas as $asignacion) {
                                            $nombreCoord = $asignacion['coordinador_nombre'] ?? 'Sin nombre';
                                            $porCoordinador[$nombreCoord] = ($porCoordinador[$nombreCoord] ?? 0) + 1;
                                        }
                                        arsort($porCoordinador);
                                        foreach ($porCoordinador as $nombreCoord => $cantidad):
                                        ?>
                                            <p style="margin:0.35rem 0;">
                                                <strong><?php echo htmlspecialchars($nombreCoord); ?>:</strong>
                                                <?php echo (int) $cantidad; ?> asesor(es)
                                            </p>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                <div class="chart-placeholder">
                                    <i class="fas fa-chart-pie"></i>
                                    <p>Sin asignaciones activas</p>
                                    <small>Cree asignaciones para ver la distribución</small>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <aside class="right-sidebar">
                            <h4>Métricas</h4>
                            <div class="metric-item">
                                <span class="metric-label">Tasa de Asignación</span>
                                <div class="metric-bar">
                                    <div class="metric-fill" style="width: <?php echo $tasaAsignacion; ?>%"></div>
                                </div>
                                <span class="metric-value"><?php echo $tasaAsignacion; ?>%</span>
                            </div>

                            <div class="metric-item">
                                <span class="metric-label">Asignaciones activas</span>
                                <div class="metric-bar">
                                    <div class="metric-fill" style="width: <?php echo $totalAsignaciones > 0 ? (int) round(($asesoresAsignadosCount / $totalAsignaciones) * 100) : 0; ?>%"></div>
                                </div>
                                <span class="metric-value"><?php echo $asesoresAsignadosCount; ?> / <?php echo $totalAsignaciones; ?></span>
                            </div>
                        </aside>
                    </div>

                    <!-- PESTAÑA 4: HISTORIAL -->
                    <div class="tab-content" id="tab-historial">
                        <div class="left-content">
                            <h4 class="section-heading">Historial de Asignaciones</h4>

                            <div class="history-filters">
                                <div class="filter-group">
                                    <label for="fecha_desde">Desde</label>
                                    <input type="date" id="fecha_desde" onchange="filtrarHistorial()">
                                </div>
                                <div class="filter-group">
                                    <label for="fecha_hasta">Hasta</label>
                                    <input type="date" id="fecha_hasta" onchange="filtrarHistorial()">
                                </div>
                                <div class="filter-group">
                                    <label for="filtro_accion">Acción</label>
                                    <select id="filtro_accion" onchange="filtrarHistorial()">
                                        <option value="">Todas las acciones</option>
                                        <option value="crear">Crear</option>
                                        <option value="editar">Editar</option>
                                        <option value="eliminar">Eliminar</option>
                                    </select>
                                </div>
                            </div>

                            <div class="history-list" id="history-list">
                                <?php if (!empty($historialAsignaciones)): ?>
                                    <?php foreach ($historialAsignaciones as $asignacion): ?>
                                        <?php
                                        $fechaHistorial = $asignacion['fecha_asignacion'] ?? $asignacion['fecha_creacion'] ?? null;
                                        $fechaIso = $fechaHistorial ? date('Y-m-d', strtotime($fechaHistorial)) : '';
                                        $estadoHistorial = strtolower($asignacion['estado'] ?? 'activa');
                                        ?>
                                        <div class="history-item"
                                             data-fecha="<?php echo htmlspecialchars($fechaIso, ENT_QUOTES, 'UTF-8'); ?>"
                                             data-accion="crear">
                                            <div class="history-icon">
                                                <i class="fas fa-user-plus"></i>
                                            </div>
                                            <div class="history-content">
                                                <h5>Asignación registrada</h5>
                                                <p>
                                                    Asesor: <?php echo htmlspecialchars($asignacion['asesor_nombre'] ?? ''); ?>
                                                    → Coordinador: <?php echo htmlspecialchars($asignacion['coordinador_nombre'] ?? ''); ?>
                                                    (<?php echo ucfirst($estadoHistorial); ?>)
                                                </p>
                                                <small>
                                                    <?php echo $fechaHistorial ? date('d/m/Y H:i', strtotime($fechaHistorial)) : '—'; ?>
                                                    - <?php echo htmlspecialchars($asignacion['creador_nombre'] ?? 'Sistema'); ?>
                                                </small>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="history-item empty" id="history-empty-state">
                                        <div class="empty-state">
                                            <i class="fas fa-history"></i>
                                            <h5>No hay historial</h5>
                                            <p>Las asignaciones aparecerán aquí conforme se registren.</p>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <aside class="right-sidebar">
                            <h4>Resumen de Actividad</h4>
                            <div class="activity-summary">
                                <div class="activity-item">
                                    <i class="fas fa-plus-circle"></i>
                                    <div>
                                        <span class="activity-count"><?php echo $totalAsignaciones; ?></span>
                                        <span class="activity-label">Registradas</span>
                                    </div>
                                </div>
                                <div class="activity-item">
                                    <i class="fas fa-check-circle"></i>
                                    <div>
                                        <span class="activity-count"><?php echo $asesoresAsignadosCount; ?></span>
                                        <span class="activity-label">Activas</span>
                                    </div>
                                </div>
                                <div class="activity-item">
                                    <i class="fas fa-pause-circle"></i>
                                    <div>
                                        <span class="activity-count"><?php echo max(0, $totalAsignaciones - $asesoresAsignadosCount); ?></span>
                                        <span class="activity-label">Inactivas</span>
                                    </div>
                                </div>
                            </div>
                        </aside>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <!-- Alertas -->
    <div id="alert-container"></div>

    <script>
        // Función para cambiar entre pestañas
        function cambiarTab(tabName) {
            // Ocultar todas las pestañas
            const tabContents = document.querySelectorAll('.tab-content');
            tabContents.forEach(tab => {
                tab.style.display = 'none';
            });
            
            // Remover clase active de todas las pestañas
            const tabSpans = document.querySelectorAll('.main-tabs span');
            tabSpans.forEach(span => {
                span.classList.remove('active');
            });
            
            // Mostrar la pestaña seleccionada
            const selectedTab = document.getElementById('tab-' + tabName);
            if (selectedTab) {
                selectedTab.style.display = 'block';
            }
            
            // Marcar la pestaña como activa
            const selectedSpan = document.querySelector(`[onclick="cambiarTab('${tabName}')"]`);
            if (selectedSpan) {
                selectedSpan.classList.add('active');
            }
        }
        
        // Función para asignar personal
        function asignarPersonal(event) {
            event.preventDefault();
            
            const form = document.getElementById('form-asignar-personal');
            const btnAsignar = document.getElementById('btn-asignar');
            
            // Validar formulario
            if (!validateForm()) {
                return;
            }
            
            // Deshabilitar botón y mostrar loading
            btnAsignar.disabled = true;
            btnAsignar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Asignando...';
            
            // Limpiar alertas anteriores
            const alertContainer = document.getElementById('alert-container');
            alertContainer.innerHTML = '';
            
            // Recopilar datos del formulario
            const formData = new FormData(form);
            formData.append('ajax', '1');
            
            // Enviar solicitud AJAX
            fetch('index.php?action=crear_asignacion', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    return response.text().then(text => {
                        throw new Error('Respuesta no es JSON: ' + text.substring(0, 100));
                    });
                }
                return response.json();
            })
            .then(result => {
                if (result.success) {
                    mostrarAlerta(result.message, 'success');
                    form.reset();
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    mostrarAlerta(result.message, 'error');
                }
            })
            .catch(error => {
                mostrarAlerta('Error de conexión: ' + error.message, 'error');
            })
            .finally(() => {
                // Restaurar botón
                btnAsignar.disabled = false;
                btnAsignar.innerHTML = '<i class="fas fa-user-friends"></i> Asignar Personal';
            });
        }
        
        // Función para validar formulario
        function validateForm() {
            const requiredFields = ['asesor_id', 'coordinador_id'];
            let isValid = true;
            
            requiredFields.forEach(fieldId => {
                const field = document.getElementById(fieldId);
                if (!field.value.trim()) {
                    field.classList.add('error');
                    isValid = false;
                } else {
                    field.classList.remove('error');
                }
            });
            
            return isValid;
        }
        
        // Función para limpiar formulario
        function limpiarFormulario() {
            document.getElementById('form-asignar-personal').reset();
            const inputs = document.querySelectorAll('#form-asignar-personal input, #form-asignar-personal select, #form-asignar-personal textarea');
            inputs.forEach(input => input.classList.remove('error'));
        }
        
        // Función para mostrar alertas
        function mostrarAlerta(mensaje, tipo) {
            const alertContainer = document.getElementById('alert-container');
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${tipo}`;
            alertDiv.innerHTML = `
                <i class="fas fa-${tipo === 'success' ? 'check-circle' : 'exclamation-triangle'}"></i>
                ${mensaje}
            `;
            
            alertContainer.appendChild(alertDiv);
            
            // Auto-ocultar después de 5 segundos
            setTimeout(() => {
                alertDiv.remove();
            }, 5000);
        }
        
        // Funciones de filtrado
        function filtrarAsignaciones() {
            const coordinador = document.getElementById('filtro_coordinador').value;
            const estado = document.getElementById('filtro_estado').value;
            const items = document.querySelectorAll('#assignments-list .assignment-item:not(.empty)');
            let visibles = 0;

            items.forEach(item => {
                const matchCoord = !coordinador || item.dataset.coordinadorCedula === coordinador;
                const matchEstado = !estado || item.dataset.estado === estado;
                const visible = matchCoord && matchEstado;
                item.style.display = visible ? '' : 'none';
                if (visible) visibles++;
            });
        }

        function filtrarHistorial() {
            const desde = document.getElementById('fecha_desde').value;
            const hasta = document.getElementById('fecha_hasta').value;
            const accion = document.getElementById('filtro_accion').value;
            const items = document.querySelectorAll('#history-list .history-item:not(.empty)');

            items.forEach(item => {
                const fecha = item.dataset.fecha || '';
                const matchDesde = !desde || (fecha && fecha >= desde);
                const matchHasta = !hasta || (fecha && fecha <= hasta);
                const matchAccion = !accion || item.dataset.accion === accion;
                item.style.display = (matchDesde && matchHasta && matchAccion) ? '' : 'none';
            });
        }

        function liberarAsignacion(id) {
            if (!confirm('¿Está seguro de que desea liberar este asesor? Quedará disponible para otra asignación.')) {
                return;
            }

            fetch('index.php?action=liberar_asignacion', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'id=' + encodeURIComponent(id)
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    mostrarAlerta(result.message, 'success');
                    location.reload();
                } else {
                    mostrarAlerta(result.message, 'error');
                }
            })
            .catch(error => {
                mostrarAlerta('Error de conexión: ' + error.message, 'error');
            });
        }
        
        // Funciones de gestión
        function editarAsignacion(id) {
            liberarAsignacion(id);
        }
        
        function eliminarAsignacion(id) {
            if (!confirm('¿Está seguro de que desea eliminar esta asignación?')) {
                return;
            }

            const formData = new FormData();
            formData.append('id', id);

            fetch('index.php?action=eliminar_asignacion', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    mostrarAlerta(result.message, 'success');
                    location.reload();
                } else {
                    mostrarAlerta(result.message, 'error');
                }
            })
            .catch(error => {
                mostrarAlerta('Error de conexión: ' + error.message, 'error');
            });
        }
        
        function exportarAsignaciones() {
            const rows = <?php echo json_encode(array_map(function ($a) {
                return [
                    'id' => $a['id'] ?? '',
                    'asesor' => $a['asesor_nombre'] ?? '',
                    'asesor_cedula' => $a['asesor_cedula'] ?? '',
                    'coordinador' => $a['coordinador_nombre'] ?? '',
                    'coordinador_cedula' => $a['coordinador_cedula'] ?? '',
                    'estado' => $a['estado'] ?? '',
                    'fecha' => $a['fecha_asignacion'] ?? $a['fecha_creacion'] ?? '',
                ];
            }, $asignacionesLista), JSON_UNESCAPED_UNICODE); ?>;

            if (!rows.length) {
                mostrarAlerta('No hay asignaciones para exportar', 'error');
                return;
            }

            const header = ['ID', 'Asesor', 'Cédula Asesor', 'Coordinador', 'Cédula Coordinador', 'Estado', 'Fecha'];
            const csv = [header.join(',')].concat(rows.map(r => [
                r.id,
                '"' + String(r.asesor).replace(/"/g, '""') + '"',
                r.asesor_cedula,
                '"' + String(r.coordinador).replace(/"/g, '""') + '"',
                r.coordinador_cedula,
                r.estado,
                r.fecha
            ].join(','))).join('\n');

            const blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'asignaciones_' + new Date().toISOString().slice(0, 10) + '.csv';
            link.click();
            URL.revokeObjectURL(link.href);
        }
        
        function actualizarAsignaciones() {
            location.reload();
        }
        
        // Validación en tiempo real
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('form-asignar-personal');
            const inputs = form.querySelectorAll('input, select, textarea');
            
            inputs.forEach(input => {
                input.addEventListener('blur', function() {
                    if (this.hasAttribute('required') && !this.value.trim()) {
                        this.classList.add('error');
                    } else {
                        this.classList.remove('error');
                    }
                });
            });
        });
    </script>
</body> 
</html>
