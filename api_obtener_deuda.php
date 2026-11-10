<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-Company-Domain");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/config/conexion.php';

$data = json_decode(file_get_contents("php://input"), true);

// MODIFICADO: la entrega móvil se procesa una encomienda por vez.
$trackings = $data['trackings'] ?? [];

if (!is_array($trackings) || count($trackings) !== 1) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Debes consultar una sola encomienda por vez.'
    ]);
    exit;
}

$codigo = trim((string) $trackings[0]);

if ($codigo === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'El código de seguimiento es obligatorio.'
    ]);
    exit;
}

try {
    // NUEVO: localizar la encomienda y sus ventas vinculadas.
    $sql = "
        SELECT
            e.id_encomienda,
            e.codigo,
            e.estado AS estado_encomienda,
            e.pago,
            v.id_venta,
            v.total,
            v.descuento,
            v.estado AS estado_venta,
            v.id_pago,
            v.id_caja_chica,
            v.id_cobrador
        FROM encomienda e
        LEFT JOIN dt_venta dv
            ON dv.id_encomienda = e.id_encomienda
        LEFT JOIN venta v
            ON v.id_venta = dv.id_venta
        WHERE e.codigo = ?
        ORDER BY v.id_venta DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$codigo]);
    $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$filas) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'No se encontró la encomienda.'
        ]);
        exit;
    }

    $encomienda = $filas[0];

    // NUEVO: no se permite cobrar modalidades ajenas al flujo móvil.
    if ($encomienda['pago'] !== 'PAGO EN DESTINO') {
        echo json_encode([
            'success' => true,
            'deuda_total' => 0.00,
            'requiere_cobro' => false,
            'estado_pago' => $encomienda['pago'],
            'estado_encomienda' => $encomienda['estado_encomienda'],
            'detalles' => []
        ]);
        exit;
    }

    // NUEVO: localizar una venta pendiente válida.
    // No elegir una venta pagada ni una nota saldada mediante id_pago.
    $ventaPendiente = null;

    foreach ($filas as $fila) {
        if (
            $fila['id_venta'] !== null
            && $fila['estado_venta'] === 'PENDIENTE'
            && $fila['id_pago'] === null
        ) {
            if ($ventaPendiente !== null) {
                throw new Exception(
                    'La encomienda tiene varias ventas pendientes. Se requiere revisión.'
                );
            }

            $ventaPendiente = $fila;
        }
    }

    if ($ventaPendiente === null) {
        throw new Exception(
            'No se encontró una venta pendiente válida para esta encomienda.'
        );
    }

    $totalBruto = round((float) $ventaPendiente['total'], 2);

    if ($totalBruto <= 0) {
        throw new Exception('El importe pendiente no es válido.');
    }

    echo json_encode([
        'success' => true,
        'deuda_total' => $totalBruto,
        'requiere_cobro' => true,
        'estado_pago' => $encomienda['pago'],
        'estado_encomienda' => $encomienda['estado_encomienda'],
        'id_venta' => (int) $ventaPendiente['id_venta'],
        'descuento' => (float) ($ventaPendiente['descuento'] ?? 0),
        'detalles' => [
            [
                'codigo' => $encomienda['codigo'],
                'id_venta' => (int) $ventaPendiente['id_venta'],
                'total_bruto' => $totalBruto,
                'estado_venta' => $ventaPendiente['estado_venta']
            ]
        ]
    ]);
} catch (Throwable $e) {
    error_log('api_obtener_deuda.php: ' . $e->getMessage());

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e instanceof Exception
            ? $e->getMessage()
            : 'Error interno al consultar la deuda.'
    ]);
}
