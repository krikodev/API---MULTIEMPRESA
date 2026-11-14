<?php
/**
 * Calcula el descuento que corresponde al importe realmente cobrado.
 * Trabaja en céntimos para no acumular diferencias de punto flotante.
 *
 * Nunca usa el descuento recibido del móvil como fuente de verdad.
 */
function calcularCobroMovil(float $totalBruto, float $montoRecibido): array
{
    if (!is_finite($totalBruto) || !is_finite($montoRecibido)) {
        throw new InvalidArgumentException('El importe no es válido.');
    }

    $totalCentimos = (int) round($totalBruto * 100);
    $recibidoCentimos = (int) round($montoRecibido * 100);

    if ($totalCentimos <= 0) {
        throw new InvalidArgumentException('La venta no tiene un importe pendiente válido.');
    }

    if ($recibidoCentimos <= 0) {
        throw new InvalidArgumentException('El importe cobrado debe ser mayor que cero.');
    }

    if ($recibidoCentimos > $totalCentimos) {
        throw new InvalidArgumentException(
            'El importe cobrado no puede superar el total pendiente.'
        );
    }

    return [
        'total_neto' => $recibidoCentimos / 100,
        'descuento' => ($totalCentimos - $recibidoCentimos) / 100,
    ];
}
