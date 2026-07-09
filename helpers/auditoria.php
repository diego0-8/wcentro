<?php
/**
 * Formateo legible de registros de auditoria_coordinadores.
 */

function auditoriaEtiquetaAccion(?string $accion): string {
    $map = [
        'migracion_inicial' => 'Migración inicial',
        'asignar_acceso_base' => 'Asignó acceso a base',
        'liberar_acceso_base' => 'Liberó acceso a base',
        'habilitar_base' => 'Habilitó base',
        'deshabilitar_base' => 'Deshabilitó base',
        'eliminar_base' => 'Eliminó base',
        'crear_tarea' => 'Creó tarea',
        'eliminar_tarea' => 'Eliminó tarea',
        'cargar_csv' => 'Cargó CSV',
    ];
    return $map[$accion ?? ''] ?? ($accion ?: '—');
}

function auditoriaEtiquetaEntidad(?string $entidad, $entidadId = null): string {
    $labels = [
        'campana' => 'Campaña',
        'base_clientes' => 'Base de clientes',
        'tareas' => 'Tarea',
        'asesor' => 'Asesor',
    ];
    $nombre = $labels[strtolower(trim((string) $entidad))] ?? ucfirst((string) $entidad);
    if ($entidadId !== null && $entidadId !== '' && (int) $entidadId > 0) {
        return $nombre . ' #' . (int) $entidadId;
    }
    return $nombre;
}

/**
 * @return array<int, string>
 */
function auditoriaDetalleLineas(?string $detalle, ?string $accion = null): array {
    if ($detalle === null || trim($detalle) === '') {
        return [];
    }

    $data = json_decode($detalle, true);
    if (!is_array($data)) {
        return [trim($detalle)];
    }

    switch ($accion) {
        case 'migracion_inicial':
            $lineas = [];
            if (!empty($data['mensaje'])) {
                $lineas[] = (string) $data['mensaje'];
            }
            if (!empty($data['campana'])) {
                $lineas[] = 'Campaña: ' . $data['campana'];
            }
            $resumen = [];
            if (isset($data['coordinadores'])) {
                $resumen[] = (int) $data['coordinadores'] . ' coordinador(es)';
            }
            if (isset($data['asesores'])) {
                $resumen[] = (int) $data['asesores'] . ' asesor(es)';
            }
            if (isset($data['bases_vinculadas'])) {
                $resumen[] = (int) $data['bases_vinculadas'] . ' base(s) vinculada(s)';
            }
            if ($resumen !== []) {
                $lineas[] = implode(' · ', $resumen);
            }
            if (isset($data['asignaciones_legacy_inactivadas'])) {
                $lineas[] = (int) $data['asignaciones_legacy_inactivadas'] . ' asignación(es) legacy inactivada(s)';
            }
            return $lineas;

        case 'asignar_acceso_base':
            $lineas = [];
            if (!empty($data['asesores']) && is_array($data['asesores'])) {
                $lineas[] = count($data['asesores']) . ' asesor(es): ' . implode(', ', $data['asesores']);
            }
            $partes = [];
            if (isset($data['insertados'])) {
                $partes[] = (int) $data['insertados'] . ' nuevo(s)';
            }
            if (isset($data['actualizados'])) {
                $partes[] = (int) $data['actualizados'] . ' reactivado(s)';
            }
            if ($partes !== []) {
                $lineas[] = 'Accesos: ' . implode(', ', $partes);
            }
            return $lineas ?: auditoriaDetalleGenerico($data);

        case 'liberar_acceso_base':
            if (!empty($data['asesor_cedula'])) {
                return ['Asesor liberado: ' . $data['asesor_cedula']];
            }
            return auditoriaDetalleGenerico($data);

        case 'habilitar_base':
        case 'deshabilitar_base':
            if (!empty($data['nombre'])) {
                return ['Base: ' . $data['nombre']];
            }
            return auditoriaDetalleGenerico($data);

        case 'crear_tarea':
            $lineas = [];
            if (!empty($data['asesor_cedula'])) {
                $lineas[] = 'Asesor: ' . $data['asesor_cedula'];
            }
            if (!empty($data['base_id'])) {
                $lineas[] = 'Base #' . (int) $data['base_id'];
            }
            if (isset($data['clientes'])) {
                $lineas[] = (int) $data['clientes'] . ' cliente(s) asignado(s)';
            }
            return $lineas ?: auditoriaDetalleGenerico($data);

        case 'cargar_csv':
            $lineas = [];
            if (!empty($data['tipo_carga'])) {
                $lineas[] = 'Tipo: ' . ($data['tipo_carga'] === 'nueva' ? 'base nueva' : 'base existente');
            }
            if (isset($data['filas'])) {
                $lineas[] = (int) $data['filas'] . ' fila(s) procesada(s)';
            }
            return $lineas ?: auditoriaDetalleGenerico($data);

        default:
            return auditoriaDetalleGenerico($data);
    }
}

/**
 * @param array<string, mixed> $data
 * @return array<int, string>
 */
function auditoriaDetalleGenerico(array $data): array {
    $labels = [
        'mensaje' => 'Mensaje',
        'campana' => 'Campaña',
        'admin' => 'Administrador',
        'coordinadores' => 'Coordinadores',
        'asesores' => 'Asesores',
        'bases_vinculadas' => 'Bases vinculadas',
        'asignaciones_legacy_inactivadas' => 'Asignaciones inactivadas',
        'asesor_cedula' => 'Asesor',
        'base_id' => 'Base',
        'nombre' => 'Nombre',
        'insertados' => 'Insertados',
        'actualizados' => 'Actualizados',
        'clientes' => 'Clientes',
        'filas' => 'Filas',
        'tipo_carga' => 'Tipo de carga',
    ];

    $lineas = [];
    foreach ($data as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $label = $labels[$key] ?? ucfirst(str_replace('_', ' ', (string) $key));
        if (is_array($value)) {
            $lineas[] = $label . ': ' . implode(', ', array_map('strval', $value));
        } else {
            $lineas[] = $label . ': ' . $value;
        }
    }
    return $lineas;
}

function auditoriaFormatearFecha(?string $fecha): string {
    if ($fecha === null || trim($fecha) === '') {
        return '—';
    }
    $ts = strtotime($fecha);
    if ($ts === false) {
        return $fecha;
    }
    return date('d/m/Y H:i', $ts);
}
