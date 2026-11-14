<?php
require_once __DIR__ . '/../config/calculo_cobro.php';

function esperarMonto(float $total, float $cobrado, float $descuentoEsperado): void
{
    $resultado = calcularCobroMovil($total, $cobrado);
    if (abs($resultado['total_neto'] - $cobrado) > 0.0001) {
        throw new RuntimeException('El importe registrado difiere del cobrado.');
    }
    if (abs($resultado['descuento'] - $descuentoEsperado) > 0.0001) {
        throw new RuntimeException('El descuento calculado no es correcto.');
    }
}

function esperarRechazo(float $total, float $cobrado): void
{
    try {
        calcularCobroMovil($total, $cobrado);
    } catch (InvalidArgumentException $e) {
        return;
    }
    throw new RuntimeException('Se aceptó un importe inválido.');
}

esperarMonto(100.00, 70.00, 30.00);
esperarMonto(100.00, 100.00, 0.00);
esperarMonto(100.05, 70.02, 30.03);
esperarMonto(0.03, 0.01, 0.02);
esperarRechazo(100.00, 100.01);
esperarRechazo(100.00, 0.00);
esperarRechazo(100.00, -1.00);
esperarRechazo(0.00, 0.00);
esperarRechazo(INF, 1.00);
esperarRechazo(100.00, INF);

echo "10 pruebas de cálculo de cobro superadas.\n";
