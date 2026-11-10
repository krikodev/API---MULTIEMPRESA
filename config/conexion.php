<?php
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Company-Domain');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function get_company_domain(): string
{
    $headers = function_exists('getallheaders') ? getallheaders() : [];

    foreach ($headers as $name => $value) {
        if (strtolower((string) $name) === 'x-company-domain') {
            return strtolower(trim((string) $value));
        }
    }

    return strtolower(trim((string) ($_SERVER['HTTP_X_COMPANY_DOMAIN'] ?? '')));
}

$dominio = get_company_domain();

// Ambas empresas comparten el servidor de API, pero usan bases de datos distintas.
$empresas = [
    'tid.net.pe' => [
        'host' => 'localhost',
        'db'   => 'GestorTID_desarrolloTID',
        'user' => 'GestorTID_TIDHuancayo',
        'pass' => 'q7@sUFam:#|4)k@6',
    ],
    'laranaexpress.com.pe' => [
        'host' => 'localhost',
        'db'   => 'GestorTID_LaRANA',
        'user' => 'GestorTID_LaranaExpress',
        'pass' => 'iG)5^&ZU}@L*M=3c',
    ],
];

if ($dominio === '' || !isset($empresas[$dominio])) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Dominio de empresa no autorizado.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$credenciales = $empresas[$dominio];

try {
    $pdo = new PDO(
        'mysql:host=' . $credenciales['host']
            . ';dbname=' . $credenciales['db']
            . ';charset=utf8mb4',
        $credenciales['user'],
        $credenciales['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    error_log('Error de conexión para ' . $dominio . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'No se pudo conectar con la base de datos de la empresa.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
