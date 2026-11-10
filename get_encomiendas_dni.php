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
    $termino = trim((string) ($data['dni'] ?? ''));

    if ($termino === '') {
        echo json_encode([
            'success' => false,
            'message' => 'Ingresa un DNI o tracking.'
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
            WHERE
                (
                    u_des.num_docu LIKE ?
                    OR e.codigo LIKE ?
                )
                AND e.estado = 'EN DESTINO'
                AND e.pago IN (
                    'PAGADO',
                    'PAGO EN BLOQUE',
                    'PAGO EN DESTINO',
                    'CREDITO'
                )
            ORDER BY
                (DATE(e.fecha_registro) = CURDATE()) DESC,
                e.fecha_registro DESC
            LIMIT 20";

    $busqueda = $termino . '%';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$busqueda, $busqueda]);
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
