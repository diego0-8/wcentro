<?php
require_once __DIR__ . '/../config.php';

$page_title = $page_title ?? 'Campaña';
$campana = $campana ?? null;
$isEdit = !empty($campana);
$error = $error ?? '';
$migracionPendiente = $migracionPendiente ?? false;
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
                <h1><?php if ($isEdit): ?><i class="fas fa-pen-to-square page-title-icon"></i><?php else: ?><i class="fas fa-plus-circle page-title-icon"></i><?php endif; ?><?php echo $isEdit ? 'Editar campaña' : 'Crear campaña'; ?></h1>
                <p><?php echo $isEdit ? 'Actualiza la información de la campaña.' : 'Define una nueva campaña de cobranza.'; ?></p>
            </div>

            <?php if ($migracionPendiente): ?>
                <div class="alert-campana alert-campana-error">
                    Falta el esquema de campañas en la base de datos.
                    Ejecute en la consola desde la carpeta del proyecto:<br>
                    <code>php scripts/ejecutar_migracion_campanas.php</code>
                </div>
            <?php endif; ?>

            <div class="campanas-card campanas-form-layout">
                <div class="campanas-card-header"><i class="fas fa-bullhorn"></i> <?php echo $isEdit ? 'Datos de la campaña' : 'Nueva campaña'; ?></div>
                <div class="campanas-card-body">
                    <?php if ($error): ?>
                        <div class="alert-campana alert-campana-error"><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>

                    <form method="post">
                        <div class="campanas-form-group">
                            <label for="nombre">Nombre</label>
                            <input type="text" id="nombre" name="nombre" required
                                   value="<?php echo htmlspecialchars($campana['nombre'] ?? ''); ?>"
                                   placeholder="Ej: Banco W Cobranza Q1">
                            <span class="form-hint">Nombre visible para administradores y coordinadores.</span>
                        </div>

                        <div class="campanas-form-group">
                            <label for="descripcion">Descripción</label>
                            <textarea id="descripcion" name="descripcion" rows="4"
                                      placeholder="Descripción opcional"><?php echo htmlspecialchars($campana['descripcion'] ?? ''); ?></textarea>
                        </div>

                        <?php if ($isEdit): ?>
                            <div class="campanas-form-group">
                                <label for="estado">Estado</label>
                                <select id="estado" name="estado">
                                    <option value="activa" <?php echo ($campana['estado'] ?? '') === 'activa' ? 'selected' : ''; ?>>Activa</option>
                                    <option value="inactiva" <?php echo ($campana['estado'] ?? '') === 'inactiva' ? 'selected' : ''; ?>>Inactiva</option>
                                </select>
                            </div>
                        <?php endif; ?>

                        <div class="card-actions">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar</button>
                            <a href="index.php?action=list_campanas" class="btn btn-secondary"><i class="fas fa-times"></i> Cancelar</a>
                            <?php if ($isEdit): ?>
                                <a href="index.php?action=gestionar_campana&id=<?php echo (int) ($campana['id'] ?? 0); ?>" class="btn btn-outline-primary"><i class="fas fa-sliders"></i> Gestionar campaña</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <div class="breadcrumb-links">
                <a href="index.php?action=list_campanas"><i class="fas fa-arrow-left"></i> Volver al listado</a>
            </div>
        </section>
    </div>
</body>
</html>
