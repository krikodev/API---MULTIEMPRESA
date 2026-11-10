<?php
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Company-Domain');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    require_once __DIR__ . '/config/conexion.php';

    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    // "dni" se mantiene por compatibilidad con versiones ya instaladas.
    $termino = trim((string) ($data['dni'] ?? $data['documento'] ?? ''));
    $tipo = strtolower(trim((string) ($data['tipo'] ?? 'auto')));

    if (!in_array($tipo, ['auto', 'documento', 'tracking'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Tipo de búsqueda no válido.']);
        exit;
    }

    if ($tipo === 'documento' && !preg_match('/^(?:[0-9]{8}|[0-9]{11})$/D', $termino)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Ingresa un DNI de 8 dígitos o un RUC de 11 dígitos.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($termino === '') {
        echo json_encode([
            'success' => false,
            'message' => 'Ingresa un DNI, RUC o tracking.'
        ]);
        exit;
    }

    if (strlen($termino) < 3) {
        echo json_encode([
            'success' => true,
            'data' => []
        ]);
        exit;
    }

    if (!isset($pdo)) {
        throw new Exception('No se detectó la conexión a la base de datos.');
    }

    // Para documentos buscamos destinatario, remitente y cliente del comprobante.
    // Es importante para facturas a crédito emitidas a un RUC diferente del destinatario.
    $filtros = [];
    $parametros = [];
    $busqueda = $termino . '%';

    if ($tipo !== 'tracking') {
        $documento = $tipo === 'documento' ? $termino : $busqueda;
        $filtros[] = 'u_des.num_docu LIKE ?';
        $filtros[] = 'u_rem.num_docu LIKE ?';
        $filtros[] = "EXISTS (
            SELECT 1
            FROM dt_venta dv_busqueda
            INNER JOIN venta v_busqueda ON v_busqueda.id_venta = dv_busqueda.id_venta
            INNER JOIN usuario u_cliente ON u_cliente.id_usuario = v_busqueda.id_cliente
            WHERE dv_busqueda.id_encomienda = e.id_encomienda
              AND u_cliente.num_docu LIKE ?
        )";
        array_push($parametros, $documento, $documento, $documento);
    }

    if ($tipo !== 'documento') {
        $filtros[] = 'e.codigo LIKE ?';
        $parametros[] = $busqueda;
    }

    $sql = "SELECT
                e.codigo AS traking,
                e.estado,
                e.pago AS estado_pago,
                CONCAT(u_des.nombres, ' ', u_des.apellidos) AS destinatario,
                CONCAT(u_rem.nombres, ' ', u_rem.apellidos) AS remitente,
                e.fecha_registro
            FROM encomienda e
            INNER JOIN usuario u_des
                ON e.id_destinatario = u_des.id_usuario
            INNER JOIN usuario u_rem
                ON e.id_remitente = u_rem.id_usuario
            WHERE (" . implode(' OR ', $filtros) . ")
                AND e.estado = 'EN DESTINO'
                AND e.pago IN (
                    'PAGADO',
                    'PAGO EN BLOQUE',
                    'PAGO EN DESTINO',
                    'CREDITO',
                    'CRÉDITO',
                    'PAGO A CREDITO',
                    'PAGO AL CREDITO'
                )
            ORDER BY
                (DATE(e.fecha_registro) = CURDATE()) DESC,
                e.fecha_registro DESC
            LIMIT 50";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($parametros);
    $encomiendas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(
        ['success' => true, 'data' => $encomiendas],
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
} catch (Throwable $e) {
    error_log('[get_encomiendas_dni] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error al buscar encomiendas.'
    ]);
    exit;
}
