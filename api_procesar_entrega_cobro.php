<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-Company-Domain");

// NUEVO: responder correctamente a las solicitudes OPTIONS.
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


/**
 * Aplica proporcionalmente el descuento a los productos de una encomienda.
 *
 * Actualiza precio, op_gravada, op_igv, op_exonerada,
 * op_inafecta y op_total de cada producto.
 *
 * La última fila absorbe el residuo de redondeo.
 * Devuelve las sumas tributarias y si los productos cubren
 * el total bruto de la venta.
 */
function aplicarDescuentoProductosApp(
    PDO $conn,
    int $idEncomienda,
    float $totalBrutoNota,
    float $totalNetoNota
) {
    $query = $conn->prepare("
        SELECT
            id_producto_encomienda,
            cantidad,
            op_gravada,
            op_exonerada,
            op_inafecta,
            op_total
        FROM producto_encomienda
        WHERE id_encomienda = :id_encomienda
        ORDER BY id_producto_encomienda
    ");

    $query->bindValue(
        ':id_encomienda',
        $idEncomienda,
        PDO::PARAM_INT
    );
    $query->execute();

    $filas = $query->fetchAll(PDO::FETCH_ASSOC);

    if (empty($filas)) {
        return null;
    }

    // Total bruto de los productos antes del descuento.
    $brutoProductos = 0.00;

    foreach ($filas as $fila) {
        $brutoProductos += (float) $fila['op_total'];
    }

    $brutoProductos = round($brutoProductos, 2);

    if ($brutoProductos <= 0 || $totalBrutoNota <= 0) {
        return null;
    }

    // Comprobar si los productos representan toda la venta.
    $cubreTotal = abs(
        $brutoProductos - $totalBrutoNota
    ) < 0.01;

    // Si cubren toda la venta, deben sumar exactamente el neto.
    // Si no, se aplica la misma proporción de descuento.
    $netoProductos = $cubreTotal
        ? $totalNetoNota
        : round(
            $brutoProductos * ($totalNetoNota / $totalBrutoNota),
            2
        );

    $factor = $netoProductos / $brutoProductos;
    $acumulado = 0.00;
    $ultimo = count($filas) - 1;

    $sumas = [
        'op_gravada'   => 0.00,
        'op_igv'       => 0.00,
        'op_exonerada' => 0.00,
        'op_inafecta'  => 0.00
    ];

    $update = $conn->prepare("
        UPDATE producto_encomienda
        SET
            precio       = :precio,
            op_gravada   = :op_gravada,
            op_igv       = :op_igv,
            op_exonerada = :op_exonerada,
            op_inafecta  = :op_inafecta,
            op_total     = :op_total
        WHERE id_producto_encomienda = :id
    ");

    foreach ($filas as $i => $fila) {
        // La última fila absorbe los céntimos residuales.
        $opTotal = ($i === $ultimo)
            ? round($netoProductos - $acumulado, 2)
            : round((float) $fila['op_total'] * $factor, 2);

        $acumulado += $opTotal;

        $cantidad = (float) $fila['cantidad'] > 0
            ? (float) $fila['cantidad']
            : 1;

        $precio = round($opTotal / $cantidad, 2);

        $opGravada = 0.00;
        $opIgv = 0.00;
        $opExonerada = 0.00;
        $opInafecta = 0.00;

        // Conservar el tipo de afectación original del producto.
        if ((float) $fila['op_gravada'] > 0) {
            $opGravada = round($opTotal / 1.18, 2);
            $opIgv = round($opTotal - $opGravada, 2);
        } elseif ((float) $fila['op_exonerada'] > 0) {
            $opExonerada = $opTotal;
        } elseif ((float) $fila['op_inafecta'] > 0) {
            $opInafecta = $opTotal;
        }

        $update->execute([
            ':precio'       => $precio,
            ':op_gravada'   => $opGravada,
            ':op_igv'       => $opIgv,
            ':op_exonerada' => $opExonerada,
            ':op_inafecta'  => $opInafecta,
            ':op_total'     => $opTotal,
            ':id'           => (int) $fila['id_producto_encomienda']
        ]);

        $sumas['op_gravada']   += $opGravada;
        $sumas['op_igv']       += $opIgv;
        $sumas['op_exonerada'] += $opExonerada;
        $sumas['op_inafecta']  += $opInafecta;
    }

    foreach ($sumas as $clave => $valor) {
        $sumas[$clave] = round($valor, 2);
    }

    $sumas['cubre_total'] = $cubreTotal;

    return $sumas;
}


// La conexión se resuelve centralmente según X-Company-Domain.
require_once __DIR__ . '/config/conexion.php';
require_once __DIR__ . '/config/calculo_cobro.php';

// Leer y validar los datos recibidos.
$idUsuario = filter_var(
    $_POST['id_usuario'] ?? null,
    FILTER_VALIDATE_INT
);

$trackings = json_decode($_POST['trackings'] ?? '', true);
$urlsFotos = json_decode($_POST['urls_fotos'] ?? '', true);
$urlFirma = trim($_POST['url_firma'] ?? '');

$descuentoRaw = str_replace(
    ',',
    '.',
    (string) ($_POST['descuento'] ?? '0')
);

$montoRaw = str_replace(
    ',',
    '.',
    (string) ($_POST['monto_cobrado'] ?? '0')
);

$idMedioPagoRaw = $_POST['id_medio_pago'] ?? '';
$idMedioPago = $idMedioPagoRaw === ''
    ? null
    : filter_var($idMedioPagoRaw, FILTER_VALIDATE_INT);

if (
    !$idUsuario ||
    !is_array($trackings) ||
    count($trackings) !== 1 ||
    !is_array($urlsFotos)
) {
    responder(
        false,
        'Datos inválidos. Procesa una encomienda por operación.',
        400
    );
}

// MODIFICADO: el flujo acordado procesa una encomienda por vez.
$codigo = trim((string) $trackings[0]);

if ($codigo === '') {
    responder(false, 'El código de seguimiento está vacío.', 400);
}

foreach ($urlsFotos as $foto) {
    if (!is_string($foto) || !filter_var($foto, FILTER_VALIDATE_URL)) {
        responder(false, 'Una URL de evidencia no es válida.', 400);
    }
}

// La app nueva usa una foto; se aceptan temporalmente dos para que
// los dispositivos con la versión anterior sigan pudiendo entregar.
if (count($urlsFotos) < 1 || count($urlsFotos) > 2) {
    responder(false, 'Se requiere una foto de evidencia (máximo dos para versiones anteriores).', 400);
}

// Toda entrega debe incluir firma. Se recibe como una URL independiente.
if ($urlFirma === '' || !filter_var($urlFirma, FILTER_VALIDATE_URL)) {
    responder(false, 'Debes adjuntar una firma válida para la entrega.', 400);
}

// Se acepta el descuento enviado por clientes antiguos solo para detectar
// intentos de cobro en encomiendas que no lo requieren. Nunca se usa para
// modificar el importe de la venta: lo calcula el servidor.
if (
    !preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/D', $descuentoRaw) ||
    !preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/D', $montoRaw)
) {
    responder(false, 'El descuento o el importe deben expresarse con máximo dos decimales.', 400);
}

$descuentoEnviado = round((float) $descuentoRaw, 2);
$montoCobrado = round((float) $montoRaw, 2);
$descuento = 0.0;

if (!is_finite($descuentoEnviado) || !is_finite($montoCobrado)) {
    responder(false, 'Los importes no son válidos.', 400);
}

try {
    $pdo->beginTransaction();

    // NUEVO: bloquear la encomienda para evitar dos entregas simultáneas.
    $stmtEnc = $pdo->prepare(
        "SELECT id_encomienda, codigo, estado, pago
         FROM encomienda
         WHERE codigo = ?
         FOR UPDATE"
    );
    $stmtEnc->execute([$codigo]);
    $encomienda = $stmtEnc->fetch(PDO::FETCH_ASSOC);

    if (!$encomienda) {
        throw new Exception('No se encontró la encomienda.');
    }

    if ($encomienda['estado'] === 'ENTREGADO') {
        throw new Exception(
            'La encomienda ya figura como entregada. No se puede procesar nuevamente.'
        );
    }

    $idEncomienda = (int) $encomienda['id_encomienda'];
    $modalidadPago = strtoupper(trim($encomienda['pago']));
    $estadoEncomienda = strtoupper(trim($encomienda['estado']));

    // NUEVO: solo se intenta cobrar en EN DESTINO + PAGO EN DESTINO.
    $intentaCobrar = $montoCobrado > 0 || $descuentoEnviado > 0;

    $debeCobrar = (
        $estadoEncomienda === 'EN DESTINO' &&
        $modalidadPago === 'PAGO EN DESTINO'
    );

    $cobroRealizado = false;

    if ($debeCobrar) {
        // MODIFICADO: buscar la venta pendiente asociada a esta encomienda.
        $stmtVentas = $pdo->prepare(
            "SELECT v.*
             FROM venta v
             WHERE v.id_venta IN (
                 SELECT DISTINCT dv.id_venta
                 FROM dt_venta dv
                 WHERE dv.id_encomienda = ?
             )
             AND v.estado = 'PENDIENTE'
             AND v.id_pago IS NULL
             FOR UPDATE"
        );
        $stmtVentas->execute([$idEncomienda]);
        $ventas = $stmtVentas->fetchAll(PDO::FETCH_ASSOC);

        if (count($ventas) !== 1) {
            throw new Exception(
                'No se encontró una única venta pendiente para cobrar. Verifica la venta asociada.'
            );
        }

        $venta = $ventas[0];
        $idVenta = (int) $venta['id_venta'];

        $totalBruto = round((float) $venta['total'], 2);

        // El servidor determina el descuento a partir de lo efectivamente cobrado.
        // Ejemplo: total S/100, recibido S/70 => descuento S/30.
        $calculo = calcularCobroMovil($totalBruto, $montoCobrado);
        $descuento = $calculo['descuento'];
        $totalNeto = $calculo['total_neto'];

        // NUEVO: validar el medio de pago.
        if (!in_array($idMedioPago, [1, 2, 3], true)) {
            throw new Exception('Selecciona EFECTIVO, YAPE o PLIN.');
        }

        $stmtMedio = $pdo->prepare(
            "SELECT id_medio_pago
             FROM medio_pago
             WHERE id_medio_pago = ?
               AND estado = 1"
        );
        $stmtMedio->execute([$idMedioPago]);

        if (!$stmtMedio->fetchColumn()) {
            throw new Exception('El medio de pago no está activo.');
        }

        // El descuento queda determinado únicamente por el cálculo anterior.
        // NUEVO: buscar la caja abierta del repartidor.
        $stmtCaja = $pdo->prepare(
            "SELECT id_caja_chica
             FROM caja_chica
             WHERE id_usuario = ?
               AND estado = 1
             ORDER BY id_caja_chica DESC
             LIMIT 1
             FOR UPDATE"
        );
        $stmtCaja->execute([$idUsuario]);
        $idCaja = $stmtCaja->fetchColumn();

        if (!$idCaja) {
            throw new Exception(
                'Debes abrir una Caja Chica antes de cobrar.'
            );
        }

        // NUEVO: aplicar el descuento proporcional a los importes tributarios.
        $opGravadaOriginal = (float) $venta['op_gravada'];
        $opExoneradaOriginal = (float) $venta['op_exonerada'];
        $opInafectaOriginal = (float) $venta['op_inafecta'];
        $opIgvOriginal = (float) $venta['op_igv'];

        if ($descuento > 0) {
            $factor = $totalNeto / $totalBruto;

            $opGravada = round($opGravadaOriginal * $factor, 2);
            $opExonerada = round($opExoneradaOriginal * $factor, 2);
            $opInafecta = round($opInafectaOriginal * $factor, 2);

            if ($opGravadaOriginal > 0) {
                $opIgv = round(
                    $totalNeto - $opGravada - $opExonerada - $opInafecta,
                    2
                );
            } else {
                $opIgv = 0.00;

                // Ajustar el residuo de redondeo.
                $residuo = round(
                    $totalNeto - $opGravada - $opExonerada - $opInafecta,
                    2
                );

                if ($opExoneradaOriginal > 0) {
                    $opExonerada = round($opExonerada + $residuo, 2);
                } elseif ($opInafectaOriginal > 0) {
                    $opInafecta = round($opInafecta + $residuo, 2);
                }
            }
        } else {
            $opGravada = $opGravadaOriginal;
            $opExonerada = $opExoneradaOriginal;
            $opInafecta = $opInafectaOriginal;
            $opIgv = $opIgvOriginal;
        }


        /*
        * Aplicar el descuento al detalle de los productos
        * dentro de la misma transacción de la venta.
        */
        if ($descuento > 0) {
            $sumasProductos = aplicarDescuentoProductosApp(
                $pdo,
                $idEncomienda,
                $totalBruto,
                $totalNeto
            );

            /*
     * Si los productos cubren toda la venta,
     * la cabecera debe coincidir con sus importes.
     */
            if (
                $sumasProductos !== null
                && $sumasProductos['cubre_total']
            ) {
                $opGravada   = $sumasProductos['op_gravada'];
                $opIgv       = $sumasProductos['op_igv'];
                $opExonerada = $sumasProductos['op_exonerada'];
                $opInafecta  = $sumasProductos['op_inafecta'];
            }
        }


        // MODIFICADO: actualizar la venta existente, no insertar otra.
        $stmtUpdateVenta = $pdo->prepare(
            "UPDATE venta
             SET estado = 'PAGADO',
                 id_forma_pago = 1,
                 id_medio_pago = ?,
                 id_caja_chica = ?,
                 id_cobrador = ?,
                 descuento = ?,
                 op_gravada = ?,
                 op_igv = ?,
                 op_exonerada = ?,
                 op_inafecta = ?,
                 total = ?
             WHERE id_venta = ?
               AND estado = 'PENDIENTE'
               AND id_pago IS NULL"
        );

        $stmtUpdateVenta->execute([
            $idMedioPago,
            $idCaja,
            $idUsuario,
            $descuento,
            $opGravada,
            $opIgv,
            $opExonerada,
            $opInafecta,
            $totalNeto,
            $idVenta
        ]);

        if ($stmtUpdateVenta->rowCount() !== 1) {
            throw new Exception(
                'La venta cambió durante el proceso. Actualiza la información e inténtalo nuevamente.'
            );
        }

        // MODIFICADO: no actualizar caja_chica.saldo_final manualmente.
        $cobroRealizado = true;
    } else {
        // NUEVO: fuera de EN DESTINO no se permite ningún cobro.
        if ($intentaCobrar) {
            throw new Exception(
                'Solo se puede cobrar una encomienda PAGO EN DESTINO cuando está EN DESTINO.'
            );
        }

        // NUEVO: crédito, pago en bloque y ventas ya pagadas no se cobran aquí.
        if (
            $modalidadPago === 'PAGO EN DESTINO' &&
            $estadoEncomienda === 'EN DESTINO'
        ) {
            throw new Exception(
                'La encomienda requiere cobro, pero no se pudo validar una venta pendiente.'
            );
        }
    }

    // MODIFICADO: guardar fotos y firma en el campo existente.
    // Se conserva el formato de URLs separadas por comas.
    $evidencias = $urlsFotos;

    if ($urlFirma !== '') {
        $evidencias[] = $urlFirma;
    }

    $urlEvidencias = implode(',', $evidencias);

    // MODIFICADO: solo cambiar pago a PAGADO si se confirmó el cobro
    // o la encomienda ya estaba pagada.
    if ($cobroRealizado) {
        $stmtEntrega = $pdo->prepare(
            "UPDATE encomienda
             SET estado = 'ENTREGADO',
                 pago = 'PAGADO',
                 fotos_evidencia = ?,
                 fecha_entrega = NOW()
             WHERE id_encomienda = ?"
        );
    } else {
        $stmtEntrega = $pdo->prepare(
            "UPDATE encomienda
             SET estado = 'ENTREGADO',
                 fotos_evidencia = ?,
                 fecha_entrega = NOW()
             WHERE id_encomienda = ?"
        );
    }

    $stmtEntrega->execute([$urlEvidencias, $idEncomienda]);

    // NUEVO: registrar el movimiento dentro de la misma transacción.
    $observacion = $cobroRealizado
        ? 'Entrega y cobro registrados desde la App Móvil.'
        : 'Entrega registrada desde la App Móvil sin cobro.';

    $stmtMov = $pdo->prepare(
        "INSERT INTO encomienda_movimiento
         (id_encomienda, tipo_movimiento, id_terminal_evento,
          observacion, id_usuario, fecha_registro)
         VALUES (?, 'ENTREGA', 0, ?, ?, NOW())"
    );

    $stmtMov->execute([
        $idEncomienda,
        $observacion,
        $idUsuario
    ]);

    $pdo->commit();

    responder(true, $cobroRealizado
        ? 'Entrega y cobro registrados correctamente.'
        : 'Entrega registrada correctamente sin cobro.', 200, [
        'cobro_realizado' => $cobroRealizado,
        'monto_cobrado' => $cobroRealizado ? $montoCobrado : 0,
        'descuento_aplicado' => $cobroRealizado ? $descuento : 0
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // NUEVO: registrar el detalle técnico solo en los logs del servidor.
    error_log('Error procesando entrega móvil: ' . $e->getMessage());

    responder(false, $e->getMessage(), 400);
}
