<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['imagen']) && isset($_POST['codigo_tracking'])) {
        $carpetaDestino = __DIR__ . "/img/";
        $codigoTracking = $_POST['codigo_tracking'];

        // Nombre único: tracking_timestamp_original.ext
        $extension = pathinfo($_FILES["imagen"]["name"], PATHINFO_EXTENSION);
        $nombreArchivo = $codigoTracking . "_" . time() . "." . $extension;
        $rutaCompleta = $carpetaDestino . $nombreArchivo;

        if (move_uploaded_file($_FILES["imagen"]["tmp_name"], $rutaCompleta)) {
            $urlImagen = "http://10.0.2.2/apis/img/" . $nombreArchivo;

            echo json_encode([
                "success" => true, 
                "mensaje" => "Imagen subida correctamente", 
                "url" => $urlImagen,
                "nombre_archivo" => $nombreArchivo
            ]);
        } else {
            echo json_encode([
                "success" => false, 
                "error" => "No se pudo guardar la imagen en el servidor"
            ]);
        }
    } else {
        echo json_encode([
            "success" => false, 
            "error" => "No se envió imagen o código de tracking"
        ]);
    }
} else {
    echo json_encode([
        "success" => false, 
        "error" => "Método no permitido"
    ]);
}
?>