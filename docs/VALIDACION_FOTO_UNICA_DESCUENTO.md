# Validación de producción — Foto única y descuento automático

## Orden de publicación
1. Respaldar `api_procesar_entrega_cobro.php`, `config/` y la base de datos antes de cambiar el servidor.
2. Actualizar primero la **API**. La API acepta una foto nueva y, temporalmente, dos fotos de aplicaciones antiguas, siempre con firma.
3. Probar con una **base de datos de pruebas equivalente a producción**, no con ventas reales.
4. Después instalar una compilación del **Flutter 1.0.2+3** en un dispositivo de pruebas.
5. Verificar ambos dominios de empresa, tipos de comprobante factura y boleta, y solo entonces considerar la distribución.

## Matriz de aceptación
| Caso | Comportamiento esperado |
|---|---|
| Venta pendiente S/100, se cobra S/70 | `venta.descuento=30`, `venta.total=70`, `venta.estado=PAGADO` y medio/caja correctos |
| Venta pendiente S/100, se cobra S/100 | Descuento 0, venta pagada por 100 |
| Venta pendiente S/100, se intenta cobrar S/100.01 | Error; transacción revertida sin marcar entregado |
| Importe 0 o negativo para pago en destino | Error; venta y entrega no cambian |
| Descuento declarado por cliente distinto del real | El servidor usa **total - monto_cobrado**, nunca el descuento que envió el cliente |
| Encomienda a crédito (factura o boleta) | Una foto y firma; se entrega sin cobro; `venta.estado` y `encomienda.pago` no cambian |
| Encomienda pagada o pago en bloque | Una foto y firma, se entrega sin cobrar otra vez |
| Foto única sin firma | Error de validación |
| Versión anterior con dos fotos y firma | Aceptada temporalmente para despliegue gradual |
| Encomienda ya entregada | No duplicar entrega ni venta |
| Sin caja abierta o medio de pago inactivo | Error y rollback |
| DNI/RUC, tracking, documentos de venta | Sin cambios de búsqueda |

## Comprobación fiscal y conciliación
La API existente prorratea el descuento en productos e importes tributarios. Confirmar con el área contable que el cálculo de gravada, IGV, inafecta, exonerada y la actualización de `venta.total` son correctos para casos reales de factura y boleta. Un descuento en una venta ya emitida podría requerir un tratamiento tributario específico; no hacer pruebas con comprobantes reales antes de aclararlo.

## Pruebas automatizadas
- `php tests/test_calculo_cobro.php`: 10 casos del importe y redondeo.
- `bash tests/run_search_integration.sh`: 10 casos de búsqueda con SQLite ficticia.
- `php -l api_procesar_entrega_cobro.php`: comprobación de sintaxis.
- GitHub Actions valida los tres pasos en los PR.

## Recuperación
Si falla el despliegue de la API, restaurar el archivo PHP respaldado y detener la instalación del APK nuevo, ya que la API anterior exigía dos fotos. Registrar la versión desplegada, hora y resultado por empresa.
