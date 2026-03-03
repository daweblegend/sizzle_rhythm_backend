<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include global configuration first (handles CORS)
require_once __DIR__ . '/Config/global.php';

require_once __DIR__ . '/vendor/autoload.php';

use FastRoute\RouteCollector;

// Load routes from external file
$routes = require __DIR__ . '/Routes.php';

// Create dispatcher with routes from array
$dispatcher = FastRoute\simpleDispatcher(function(RouteCollector $r) use ($routes) {
    foreach ($routes as $route) {
        $r->addRoute($route['method'], $route['url'], $route['handler']);
    }
});

$httpMethod = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];

// Remove query string
if (false !== $pos = strpos($uri, '?')) {
    $uri = substr($uri, 0, $pos);
}

// Remove the base path (project folder path)
$basePath = '/Github/sizzle_rhythm_backend'; // Adjust this to your project folder
if (strpos($uri, $basePath) === 0) {
    $uri = substr($uri, strlen($basePath));
}

// Ensure URI starts with /
if ($uri === '' || $uri[0] !== '/') {
    $uri = '/' . $uri;
}

$uri = rawurldecode($uri);

// Debug: uncomment the next line to see what URI is being processed
// echo "Debug: URI = '$uri', Method = '$httpMethod'"; exit;

$routeInfo = $dispatcher->dispatch($httpMethod, $uri);
switch ($routeInfo[0]) {
    case FastRoute\Dispatcher::NOT_FOUND:
        http_response_code(404);
        echo json_encode(['error' => '404 Not Found', 'message' => 'The requested endpoint was not found']);
        break;
    case FastRoute\Dispatcher::METHOD_NOT_ALLOWED:
        $allowedMethods = $routeInfo[1];
        http_response_code(405);
        echo json_encode([
            'error' => '405 Method Not Allowed', 
            'message' => 'Method not allowed',
            'allowed_methods' => $allowedMethods
        ]);
        break;
    case FastRoute\Dispatcher::FOUND:
        $handler = $routeInfo[1];
        $vars = $routeInfo[2];
        
        // Merge URL parameters into $_GET and $_REQUEST
        $_GET = array_merge($_GET, $vars);
        $_REQUEST = array_merge($_REQUEST, $vars);
        
        // Find the route by handler and include the corresponding file
        foreach ($routes as $route) {
            if ($route['handler'] === $handler) {
                // Set action if specified in route
                if (isset($route['action'])) {
                    $_REQUEST['action'] = $route['action'];
                }
                require __DIR__ . $route['path'];
                break;
            }
        }
        break;
}