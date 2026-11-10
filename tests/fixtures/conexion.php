<?php
// Solo para pruebas de integración. Sin credenciales ni datos de producción.
header('Content-Type: application/json; charset=UTF-8');
$dominio = strtolower(trim($_SERVER['HTTP_X_COMPANY_DOMAIN'] ?? ''));
if (!in_array($dominio, ['tid.net.pe', 'laranaexpress.com.pe'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Empresa no autorizada']);
    exit;
}
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction('CURDATE', static fn() => date('Y-m-d'));
$pdo->sqliteCreateFunction('CONCAT', static fn(...$x) => implode('', $x), -1);
$pdo->exec('CREATE TABLE usuario (id_usuario INTEGER PRIMARY KEY, num_docu TEXT, nombres TEXT, apellidos TEXT)');
$pdo->exec('CREATE TABLE encomienda (id_encomienda INTEGER PRIMARY KEY, codigo TEXT, estado TEXT, pago TEXT, id_destinatario INTEGER, id_remitente INTEGER, fecha_registro TEXT)');
$pdo->exec('CREATE TABLE venta (id_venta INTEGER PRIMARY KEY, id_cliente INTEGER)');
$pdo->exec('CREATE TABLE dt_venta (id_venta INTEGER, id_encomienda INTEGER)');
$pdo->exec("INSERT INTO usuario VALUES
 (1,'12345678','Cliente','Destinatario'),
 (2,'87654321','Origen','Remitente'),
 (3,'20123456789','Empresa','Veinte'),
 (4,'10123456789','Empresa','Diez'),
 (5,'55555555','Otro','Destinatario')");
$pdo->exec("INSERT INTO encomienda VALUES
 (101,'TID-001','EN DESTINO','CREDITO',1,2,datetime('now')),
 (102,'TID-002','EN DESTINO','CREDITO',5,2,datetime('now')),
 (103,'TID-003','ENTREGADO','CREDITO',5,2,datetime('now')),
 (104,'TID-004','EN DESTINO','PAGO EN DESTINO',1,2,datetime('now')),
 (105,'TID-005','EN DESTINO','PAGO EN BLOQUE',5,2,datetime('now'))");
$pdo->exec('INSERT INTO venta VALUES (201,3),(202,4),(203,3)');
$pdo->exec('INSERT INTO dt_venta VALUES (201,101),(202,102),(203,103)');
