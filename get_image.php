<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

$data = json_decode(file_get_contents("php://input"), true);
$codigoTracking = $data["codigo_tracking"] ?? "";

if (empty($codigoTracking)) {
    echo json_encode([
        "success" => false,
        "message" => "Código tracking requerido"
    ]);
    exit;
}

$carpetaImagenes = __DIR__ . "/img/";
$imagenes = [];

// Buscar archivos que empiecen con el código de tracking
if (is_dir($carpetaImagenes)) {
    $archivos = scandir($carpetaImagenes);
    
    foreach ($archivos as $archivo) {
        // Verificar si el archivo empieza con el código de tracking
        if (strpos($archivo, $codigoTracking . "_") === 0) {
            $imagenes[] = [
                "nombre" => $archivo,
                "url" => "http://10.0.2.2/apis/img/" . $archivo
            ];
        }
    }
}

if (!empty($imagenes)) {
    echo json_encode([
        "success" => true,
        "imagenes" => $imagenes
    ]);
} else {
    echo json_encode([
        "success" => true,
        "imagenes" => [],
        "message" => "No se encontraron imágenes para esta encomienda"
    ]);
}
?>