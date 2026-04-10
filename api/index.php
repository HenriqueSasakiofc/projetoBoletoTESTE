<?php
require_once __DIR__ . '/../vendor/autoload.php';

// Initialize Environment and Database
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

\Config\Database::connect();

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// Helper to handle routes
function handleRoute($pattern, $controllerMethod) {
    global $uri, $method;
    
    // Convert pattern to regex
    $regex = str_replace('/', '\/', $pattern);
    $regex = preg_replace('/\{\w+\}/', '(\d+)', $regex);
    $regex = "/^" . $regex . "$/";

    if (preg_match($regex, $uri, $matches)) {
        array_shift($matches); // Remove full match
        list($controllerClass, $action) = explode('@', $controllerMethod);
        $fullClass = "\\App\\Controllers\\" . $controllerClass;
        $controller = new $fullClass();
        call_user_func_array([$controller, $action], $matches);
        exit;
    }
}

// Routes
if ($method === 'GET') {
    if ($uri === '/api/health') {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok', 'app' => $_ENV['APP_NAME']]);
        exit;
    }
    handleRoute('/api/clients', 'ClientsApiController@index');
    handleRoute('/api/clients/{id}', 'ClientsApiController@show');
    handleRoute('/api/message-template', 'MessagesApiController@getTemplate');
    handleRoute('/api/upload-batches/{id}/pendings', 'UploadBatchesController@pendings');
}

if ($method === 'POST') {
    handleRoute('/api/auth/login', 'AuthController@login');
    handleRoute('/api/imports/upload', 'ImportsApiController@upload');
    handleRoute('/api/customers/{id}/send-manual-message', 'MessagesApiController@sendManual');
    handleRoute('/api/receivables/{id}/queue-standard-message', 'ReceivablesController@queueStandardMessage');
}

if ($method === 'PUT') {
    handleRoute('/api/message-template', 'MessagesApiController@updateTemplate');
}

if ($method === 'POST' && $uri === '/api/auth/register-company') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Cadastro publico de empresa desativado. Solicite a criacao manual do acesso.']);
    exit;
}

// 404 Fallback
http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['error' => 'API Endpoint not found', 'uri' => $uri, 'method' => $method]);
