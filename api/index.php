<?php
require_once __DIR__ . '/../vendor/autoload.php';

// Initialize Environment and Database
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

\Config\Database::connect();

// Create Router instance
$router = new \Bramus\Router\Router();

// Define API Routes
$router->mount('/api', function() use ($router) {

    // Healthcheck
    $router->get('/health', function() {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok', 'app' => $_ENV['APP_NAME']]);
    });
    
    // Auth Routes
    $router->mount('/auth', function() use ($router) {
        $router->post('/login', '\App\Controllers\AuthController@login');
    });

    // Sub-routing for Imports
    $router->mount('/imports', function() use ($router) {
        $router->post('/upload', '\App\Controllers\ImportsController@upload');
    });

});

// Run the routing magic
$router->run();
