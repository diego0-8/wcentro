<?php
/**
 * Renderiza el detalle de un registro de auditoría.
 * Requiere: $detalle (string|null), opcional $accion (string|null)
 */
require_once __DIR__ . '/../../helpers/auditoria.php';

$detalle = $detalle ?? null;
$accion = $accion ?? null;
$lineas = auditoriaDetalleLineas($detalle, $accion);
?>
<?php if (empty($lineas)): ?>
    <span class="audit-detail-empty">—</span>
<?php else: ?>
    <ul class="audit-detail-list">
        <?php foreach ($lineas as $linea): ?>
            <li><?php echo htmlspecialchars($linea); ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
