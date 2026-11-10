<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Company-Domain");
header("Content-Type: application/json; charset=UTF-8");
require_once("config/conexion.php");

$data = json_decode(file_get_contents("php://input"), true);

$serie = $data["serie"] ?? "";
$correlativo = $data["correlativo"] ?? "";
$fecha = $data["fecha"] ?? "";

if (!empty($serie) && !empty($correlativo) && !empty($fecha)) {
    $sql = "SELECT
        CONCAT(u_remitente.nombres, ' ', u_remitente.apellidos) AS remitente,
        u_remitente.num_docu AS documentoRem,
        CONCAT(u_destinatario.nombres, ' ', u_destinatario.apellidos) AS destinatario,
        u_destinatario.num_docu AS documentoDes,
        tv.nombre AS terminal,
        t_o.nombre AS origen,
        t_d.nombre AS destino,
        v.fecha_registro,
        e.fecha_salida,
        e.fecha_entrega,
        e.estado,
        pe.descripcion AS producto,
        pe.obs AS observacion,
        e.codigo AS traking
    FROM venta v
    INNER JOIN dt_venta dv ON v.id_venta = dv.id_venta
    INNER JOIN encomienda e ON dv.id_encomienda = e.id_encomienda
    INNER JOIN usuario u_remitente ON e.id_remitente = u_remitente.id_usuario
    INNER JOIN usuario u_destinatario ON e.id_destinatario = u_destinatario.id_usuario
    INNER JOIN terminal tv ON v.id_terminal = tv.id_terminal
    LEFT JOIN terminal t_o ON t_o.id_terminal = e.id_terminal_origen
    LEFT JOIN terminal t_d ON t_d.id_terminal = e.id_terminal_destino
    INNER JOIN producto_encomienda pe ON e.id_encomienda = pe.id_encomienda
    WHERE v.serie = :serie AND v.correlativo = :correlativo AND DATE(v.fecha_emision) = :fecha";

    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(":serie", $serie);
    $stmt->bindParam(":correlativo", $correlativo);
    $stmt->bindParam(":fecha", $fecha);
    $stmt->execute();

    $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($resultados)) {
        echo json_encode([
            "success" => true,
            "data" => $resultados
        ]);
    } else {
        echo json_encode([
            "success" => false,
            "message" => "No se encontró encomienda con esos datos"
        ]);
    }
} else {
    echo json_encode([
        "success" => false,
        "message" => "Serie, correlativo y fecha son requeridos"
    ]);
}
?>