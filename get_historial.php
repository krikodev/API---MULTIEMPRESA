<?php
ini_set('display_errors', 0); 

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Company-Domain");

try {
    require_once __DIR__ . '/config/conexion.php'; 

    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    
    $filtro = isset($data['filtro']) ? trim($data['filtro']) : '';
    // Si Flutter no manda fecha, usamos la fecha de HOY del servidor
    $fecha = isset($data['fecha']) ? trim($data['fecha']) : date('Y-m-d');

    if (!isset($pdo)) {
        throw new Exception("Error de conexión a la base de datos.");
    }

    // EXTRAEMOS 'fotos_evidencia' QUE ANTES NO ESTABA
    $sql = "SELECT 
                e.codigo AS traking, 
                e.estado, 
                e.fecha_salida,
                e.fecha_entrega,
                e.fotos_evidencia, 
                CONCAT(u_des.nombres, ' ', u_des.apellidos) AS destinatario,
                u_des.num_docu AS documentoDes,
                CONCAT(u_rem.nombres, ' ', u_rem.apellidos) AS remitente,
                pe.descripcion AS producto,
                t_o.nombre AS origen,
                t_d.nombre AS destino
            FROM encomienda e
            INNER JOIN usuario u_des ON e.id_destinatario = u_des.id_usuario
            INNER JOIN usuario u_rem ON e.id_remitente = u_rem.id_usuario
            LEFT JOIN producto_encomienda pe ON e.id_encomienda = pe.id_encomienda
            LEFT JOIN terminal t_o ON t_o.id_terminal = e.id_terminal_origen
            LEFT JOIN terminal t_d ON t_d.id_terminal = e.id_terminal_destino
            WHERE e.estado = 'ENTREGADO' 
            AND DATE(e.fecha_entrega) = ?"; // Filtro obligatorio por día

    $parametros = [$fecha];

    if (!empty($filtro)) {
        $sql .= " AND (e.codigo LIKE ? OR u_des.num_docu LIKE ?)";
        $parametros[] = "%$filtro%"; 
        $parametros[] = "%$filtro%";
    }

    $sql .= " ORDER BY e.fecha_entrega DESC LIMIT 50";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($parametros);
    
    $historial = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(["success" => true, "data" => $historial]);

} catch (Throwable $e) {
    echo json_encode(["success" => false, "message" => "Error en el servidor", "error" => $e->getMessage()]);
}
?>