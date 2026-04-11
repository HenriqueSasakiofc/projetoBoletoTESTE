<?php
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($uri === '/cadastro' || $uri === '/cadastro.php') {
    require __DIR__ . '/public/cadastro_desativado.php';
    exit;
}

// API routes
if (strpos($uri, '/api') === 0) {
    require __DIR__ . '/api/index.php';
    exit;
}

// Static files in public
$file = __DIR__ . '/public' . $uri;
if (is_file($file)) {
    // Manually handle content types for the built-in server when routing
    $ext = pathinfo($file, PATHINFO_EXTENSION);
    $mimes = [
        'css' => 'text/css',
        'js'  => 'application/javascript',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
    ];
    if (isset($mimes[$ext])) {
        header('Content-Type: ' . $mimes[$ext]);
    }
    readfile($file);
    exit;
}

// Frontend pages mapping
$pages = [
    '/' => '/index.php',
    '/clientes' => '/clientes.php',
    '/importacao' => '/importacao.php',
    '/pendencias' => '/pendencias.php',
    '/outbox' => '/outbox.php',
    '/cliente' => '/cliente.php',
];

if (isset($pages[$uri])) {
    require __DIR__ . '/public' . $pages[$uri];
    exit;
}

// Fallback to index
require __DIR__ . '/public/index.php';
