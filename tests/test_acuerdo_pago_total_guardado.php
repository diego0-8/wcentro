<?php
/**
 * Validación: acuerdo de pago total en vista asesor (solo total a pagar) y persistencia.
 * Ejecutar: php tests/test_acuerdo_pago_total_guardado.php
 */
$baseDir = dirname(__DIR__);
$errores = [];
$ok = [];

$vista = $baseDir . '/views/asesor_gestionar.php';
$js = $baseDir . '/assets/js/asesor-gestionar.js';
$controller = $baseDir . '/controllers/AsesorGestionController.php';
$modelo = $baseDir . '/models/Acuerdo.php';

foreach ([$vista, $js, $controller, $modelo] as $f) {
    if (!is_file($f)) {
        $errores[] = 'Falta archivo: ' . $f;
    }
}

if (!$errores) {
    $v = file_get_contents($vista);
    $j = file_get_contents($js);
    $c = file_get_contents($controller);
    $m = file_get_contents($modelo);

    $checks = [
        [$v, 'id="campos-acuerdo-pago-total"', 'bloque campos acuerdo pago total en vista'],
        [$v, 'id="total-a-pagar-acuerdo"', 'campo total a pagar en vista'],
        [$v, 'Total a pagar:', 'etiqueta total a pagar en vista'],
        [$v, 'Datos del acuerdo de pago total', 'etiqueta sección acuerdo pago total'],
        [$j, "nivel2 === 'acuerdo_pago_total'", 'manejo nivel2 acuerdo_pago_total en JS'],
        [$j, 'diligencie total a pagar', 'validación solo total (tarjeta multi)'],
        [$j, 'Para ACUERDO PAGO TOTAL debe diligenciar total a pagar', 'validación formulario único'],
        [$j, 'buildHtmlTarjetaPagoTotal', 'tarjeta multi obligación pago total'],
        [$j, 'total_a_pagar_acuerdo', 'payload total_a_pagar_acuerdo'],
        [$c, "if (\$nivel2 === 'acuerdo_pago_total')", 'guardar acuerdo pago total en controlador'],
        [$c, "'valor_final_pago_total' => \$total", 'persiste valor_final_pago_total'],
        [$c, 'total a pagar es obligatorio', 'validación backend total a pagar'],
        [$m, 'valor_final_pago_total', 'columna valor_final_pago_total en modelo Acuerdo'],
    ];

    foreach ($checks as [$content, $needle, $label]) {
        if (strpos($content, $needle) === false) {
            $errores[] = $label;
        } else {
            $ok[] = $label;
        }
    }

    $removidosVista = [
        'id="saldo-a-pagar"' => 'saldo-a-pagar no debe estar en vista',
        'id="descuento-monto"' => 'descuento-monto no debe estar en vista',
        'id="fecha-limite-acuerdo"' => 'fecha-limite-acuerdo no debe estar en vista',
        'Saldo a pagar:' => 'etiqueta saldo a pagar no debe estar en vista',
    ];
    foreach ($removidosVista as $needle => $label) {
        if (strpos($v, $needle) !== false) {
            $errores[] = $label;
        } else {
            $ok[] = $label;
        }
    }

    if (preg_match('/function buildHtmlTarjetaPagoTotal[\s\S]*?js-saldo-a-pagar/', $j)) {
        $errores[] = 'buildHtmlTarjetaPagoTotal no debe incluir saldo a pagar';
    } else {
        $ok[] = 'tarjeta multi sin saldo a pagar';
    }
}

echo "\n=== Test: Acuerdo pago total (asesor) ===\n\n";
foreach ($ok as $m) {
    echo "  [OK] $m\n";
}
foreach ($errores as $m) {
    echo "  [FAIL] $m\n";
}
echo "\nTotal: " . count($ok) . " OK, " . count($errores) . " FAIL.\n";
exit(count($errores) > 0 ? 1 : 0);
