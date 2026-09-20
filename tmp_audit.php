<?php
require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Now we have a booted app, get routes
$routes = [];
foreach (app('router')->getRoutes() as $route) {
    $action = $route->getAction();
    $controller = $action['controller'] ?? '';
    $method = '';
    if ($controller && strpos($controller, '@') !== false) {
        [$controller, $method] = explode('@', $controller);
    }
    $routes[] = [
        'method' => implode('|', $route->methods()),
        'uri' => $route->uri(),
        'name' => $route->getName() ?: '',
        'controller' => $controller,
        'method_name' => $method,
    ];
}

echo json_encode($routes);
