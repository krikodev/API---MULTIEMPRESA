# Validación previa a despliegue: búsqueda DNI, RUC y crédito

Este cambio no altera las operaciones de entrega, venta, pago ni caja.
Solo afecta la lectura de encomiendas disponibles en `get_encomiendas_dni.php`.

## Contrato compatible

- POST JSON con `{"dni":"12345678"}` conserva el modo `auto` del cliente anterior.
- POST JSON con `{"dni":"12345678","tipo":"documento"}` filtra DNI de 8 cifras.
- POST JSON con `{"dni":"10123456789","tipo":"documento"}` filtra RUC de 11 cifras.
- POST JSON con `{"dni":"20123456789","tipo":"documento"}` filtra RUC de 11 cifras.
- POST JSON con `{"dni":"TID-001","tipo":"tracking"}` filtra códigos de tracking.
- El encabezado `X-Company-Domain` debe seguir siendo obligatorio para resolver la empresa.
- La respuesta conserva `success`, `data`, `traking`, `estado`, `estado_pago`, `destinatario` y `remitente`.

## Casos de integración con datos ficticios en una base de pruebas

1. DNI de destinatario con encomienda `EN DESTINO` y `PAGADO`: aparece.
2. RUC 10 del destinatario con encomienda `EN DESTINO`: aparece.
3. RUC 20 del cliente del comprobante, diferente del destinatario: aparece.
4. RUC de remitente distinto del destinatario: aparece.
5. Venta a crédito con `encomienda.pago = CREDITO`: aparece, con estado de crédito.
6. Venta a crédito con `venta.estado = PENDIENTE` y `venta.id_forma_pago = 2`: confirmar cuál es el valor real de `encomienda.pago` y que aparece.
7. Comprobante `PAGO EN BLOQUE`: aparece.
8. Encomienda `ENTREGADO`: no vuelve a aparecer como pendiente de entrega.
9. DNI de 9 o 10 dígitos en modo documento: HTTP 400; la app no debe enviarlo.
10. Búsqueda por tracking: sigue encontrando encomiendas.
11. El mismo documento en dos empresas: cada encabezado devuelve solo registros de su empresa.
12. Con la app antigua y sin parámetro `tipo`: la búsqueda anterior sigue funcionando.
13. Verificar `EXPLAIN` de la consulta con volumen real y revisar índices de `usuario.num_docu`, `dt_venta.id_encomienda`, `venta.id_cliente` y `encomienda.codigo`.

## Pruebas operativas obligatorias

- Verificar que las encomiendas al crédito se entregan **sin** cobro en la app.
- No ejecutar pruebas de entregas ni caja sobre producción.
- Comparar recuentos con el sistema web TID y revisar la relación entre `venta.id_cliente`, `encomienda.id_destinatario` y `encomienda.pago`.
- Ejecutar `php -l get_encomiendas_dni.php` antes de desplegar.
- Validar acceso, tiempos de respuesta y logs sin exponer documentos reales.
- Crear respaldo del archivo de producción antes de reemplazarlo; restaurar la versión anterior si falla la validación.

## Seguridad fuera del alcance de esta corrección

- Tratar como expuestos los secretos de base de datos/cifrado almacenados en el repositorio público y planificar su rotación controlada.
- Verificar autenticación y autorización en endpoints que cambian pagos, entregas y caja.
- No sustituir el flujo de cifrado de contraseñas sin un plan de migración.
