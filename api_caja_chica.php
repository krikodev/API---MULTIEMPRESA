<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-Company-Domain");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function responder($success, $mensaje, $status = 200, $extra = [])
{
    http_response_code($status);
    echo json_encode(
        array_merge([
            'success' => $success,
            $success ? 'message' : 'error' => $mensaje
        ], $extra),
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

require_once __DIR__ . '/config/conexion.php';

$idUsuario = filter_var(
    $_POST['id_usuario'] ?? null,
    FILTER_VALIDATE_INT
);

$accion = trim($_POST['accion'] ?? 'consultar');

if (!$idUsuario) {
    responder(false, 'No se recibió un usuario válido.', 400);
}

if (!in_array($accion, ['consultar', 'cerrar'], true)) {
    responder(false, 'Acción no válida.', 400);
}

try {
    if ($accion === 'consultar') {
        $stmt = $pdo->prepare("
            SELECT
                id_caja_chica,
                id_usuario,
                referencia,
                saldo_inicial,
                saldo_final,
                saldo_real,
                fecha_inicio,
                fecha_fin,
                estado,
                dinero_entregar
            FROM caja_chica
            WHERE id_usuario = ?
              AND estado = 1
            ORDER BY id_caja_chica DESC
            LIMIT 1
        ");
        $stmt->execute([$idUsuario]);

        $caja = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$caja) {
            responder(true, 'No tienes una caja chica abierta.', 200, [
                'caja_abierta' => false
            ]);
        }

        responder(true, 'Caja chica encontrada.', 200, [
            'caja_abierta' => true,
            'caja' => $caja
        ]);
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT
            id_caja_chica,
            id_usuario,
            referencia,
            saldo_inicial,
            saldo_final,
            saldo_real,
            fecha_inicio,
            fecha_fin,
            estado,
            dinero_entregar
        FROM caja_chica
        WHERE id_usuario = ?
          AND estado = 1
        ORDER BY id_caja_chica DESC
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$idUsuario]);

    $caja = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$caja) {
        $pdo->rollBack();
        responder(false, 'No tienes una caja chica abierta.', 400);
    }

    $update = $pdo->prepare("
        UPDATE caja_chica
        SET estado = 0,
            fecha_fin = NOW()
        WHERE id_caja_chica = ?
          AND id_usuario = ?
          AND estado = 1
    ");

    $update->execute([
        $caja['id_caja_chica'],
        $idUsuario
    ]);

    if ($update->rowCount() !== 1) {
        throw new Exception('No se pudo cerrar la caja chica.');
    }

    $stmt = $pdo->prepare("
        SELECT
            id_caja_chica,
            id_usuario,
            referencia,
            saldo_inicial,
            saldo_final,
            saldo_real,
            fecha_inicio,
            fecha_fin,
            estado,
            dinero_entregar
        FROM caja_chica
        WHERE id_caja_chica = ?
        LIMIT 1
    ");
    $stmt->execute([$caja['id_caja_chica']]);

    $cajaCerrada = $stmt->fetch(PDO::FETCH_ASSOC);

    $pdo->commit();

    responder(true, 'Caja chica cerrada correctamente.', 200, [
        'caja_abierta' => false,
        'caja' => $cajaCerrada
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Error al gestionar caja chica: ' . $e->getMessage());
    responder(false, 'No se pudo completar la operación de caja chica.', 500);
}
?>
