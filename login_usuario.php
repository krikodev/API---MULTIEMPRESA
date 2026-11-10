<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Methods: POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Company-Domain");
    exit(0);
}

require_once("config/conexion.php");
require_once("config/Security.php");

$email = isset($_POST['email']) ? trim($_POST['email']) : '';
$password = isset($_POST['pass']) ? trim($_POST['pass']) : '';

if (empty($email) || empty($password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Datos incompletos']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT 
            id_usuario,
            id_terminal,
            id_tp_docu,
            num_docu,
            nombres,
            apellidos,
            fecha_nacimiento,
            estado_civil,
            genero,
            celular,
            email,
            contrasena,
            direccion,
            ubigeo,
            id_tp_usuario,
            estado_sunat,
            condicion_sunat,
            nacionalidad,
            estado,
            fecha_registro,
            id_sesionpersonal
        FROM usuario 
        WHERE email = :email AND estado = 1 
        LIMIT 1
    ");
    $stmt->bindParam(':email', $email, PDO::PARAM_STR);
    $stmt->execute();
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Credenciales inválidas']);
        exit;
    }

    try {
        $contrasenaDB = Security::decryption($usuario['contrasena']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error interno al procesar contraseña']);
        exit;
    }

    if ($password === $contrasenaDB) {

        // La caja se asigna solamente a usuarios repartidores.
        // REEMPLAZAR 0 por el id_tp_usuario REAL del repartidor.
        $ID_TP_USUARIO_REPARTIDOR = 16;

        $cajaChica = null;

        if (
            $ID_TP_USUARIO_REPARTIDOR > 0 &&
            (int) $usuario['id_tp_usuario'] === $ID_TP_USUARIO_REPARTIDOR
        ) {
            try {
                $pdo->beginTransaction();

                /*
             * Bloquea temporalmente el registro del usuario.
             * Evita que dos inicios de sesión simultáneos
             * creen dos cajas para el mismo repartidor.
             */
                $stmtLock = $pdo->prepare("
                SELECT id_usuario
                FROM usuario
                WHERE id_usuario = :id_usuario
                FOR UPDATE
            ");

                $stmtLock->execute([
                    ':id_usuario' => (int) $usuario['id_usuario']
                ]);

                if (!$stmtLock->fetch(PDO::FETCH_ASSOC)) {
                    throw new RuntimeException(
                        'No se pudo validar el usuario.'
                    );
                }

                // Buscar una caja abierta del usuario.
                $stmtCaja = $pdo->prepare("
                SELECT id_caja_chica
                FROM caja_chica
                WHERE id_usuario = :id_usuario
                  AND estado = 1
                ORDER BY id_caja_chica DESC
                LIMIT 1
            ");

                $stmtCaja->execute([
                    ':id_usuario' => (int) $usuario['id_usuario']
                ]);

                $cajaChica = $stmtCaja->fetchColumn();

                if ($cajaChica === false) {

                    // No existe una caja abierta: crearla.
                    $referencia = 'APERTURA AUTOMATICA APP';
                    $saldoInicial = 0.00;
                    $fechaInicio = date('Y-m-d H:i:s');
                    $estadoCaja = 1;

                    $stmtCrear = $pdo->prepare("
                    INSERT INTO caja_chica (
                        id_usuario,
                        referencia,
                        saldo_inicial,
                        fecha_inicio,
                        estado
                    ) VALUES (
                        :id_usuario,
                        :referencia,
                        :saldo_inicial,
                        :fecha_inicio,
                        :estado
                    )
                ");

                    $stmtCrear->execute([
                        ':id_usuario' => (int) $usuario['id_usuario'],
                        ':referencia' => $referencia,
                        ':saldo_inicial' => $saldoInicial,
                        ':fecha_inicio' => $fechaInicio,
                        ':estado' => $estadoCaja
                    ]);

                    $cajaChica = $pdo->lastInsertId();
                }

                $pdo->commit();

                $cajaChica = (int) $cajaChica;
            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log(
                    'Error al consultar o abrir Caja Chica: '
                        . $e->getMessage()
                );

                http_response_code(500);

                echo json_encode([
                    'success' => false,
                    'message' => 'No se pudo verificar o abrir la Caja Chica.'
                ]);

                exit;
            }
        }

        unset($usuario['contrasena']);

        // El ID estará disponible también en el objeto user.
        $usuario['id_caja_chica'] = $cajaChica;

        http_response_code(200);

        echo json_encode([
            'success' => true,
            'message' => 'Login correcto',
            'user' => $usuario,
            'caja_chica' => [
                'id_caja_chica' => $cajaChica,
                'abierta' => $cajaChica !== null
            ]
        ]);

        exit;
    } else {
        http_response_code(401);

        echo json_encode([
            'success' => false,
            'message' => 'Credenciales inválidas'
        ]);

        exit;
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error interno: ' . $e->getMessage()]);
    exit;
}
