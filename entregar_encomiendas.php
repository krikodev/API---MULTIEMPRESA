<?php
ini_set('display_errors', 0); 
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Company-Domain");

try {
    require_once __DIR__ . '/config/conexion.php'; 

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(["success" => false, "error" => "Método no permitido"]);
        exit;
    }

    $trackings = json_decode($_POST['trackings'] ?? '[]', true);
    $urls_fotos = json_decode($_POST['urls_fotos'] ?? '[]', true);

    if (empty($trackings)) {
        echo json_encode(["success" => false, "error" => "No se seleccionaron encomiendas"]);
        exit;
    }

    if (empty($urls_fotos)) {
        echo json_encode(["success" => false, "error" => "Faltan las evidencias fotográficas"]);
        exit;
    }

    if (!isset($pdo)) {
        throw new Exception("Error de configuración: No se encontró la conexión \$pdo");
    }

    // Divide las fotos recibidas de 2 en 2 por paquete
    $fotos_por_paquete = array_chunk($urls_fotos, 2);

    $pdo->beginTransaction();

    $sql = "UPDATE encomienda 
            SET estado = 'ENTREGADO', 
                fecha_entrega = NOW(), 
                fotos_evidencia = ? 
            WHERE codigo = ?";
            
    $stmt = $pdo->prepare($sql);

    foreach ($trackings as $index => $tracking) {
        $fotos_de_este_paquete = isset($fotos_por_paquete[$index]) ? $fotos_por_paquete[$index] : [];
        $fotos_string = implode(',', $fotos_de_este_paquete);
        
        $stmt->execute([$fotos_string, $tracking]);
    }

    $pdo->commit();

    echo json_encode([
        "success" => true, 
        "message" => "Despacho exitoso. " . count($trackings) . " encomiendas entregadas."
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    echo json_encode([
        "success" => false, 
        "error" => "Error crítico en el servidor: " . $e->getMessage()
    ]);
}
?>