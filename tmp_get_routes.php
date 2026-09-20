
<?php
require __DIR__ . '/vendor/autoload.php';

$routes = app(\Illuminate\Routing\Router::class)->getRoutes();

$output = [];
foreach ($routes as $route) {
    $action = $route->getAction();
    $controller = $action['controller'] ?? '';
    $method = '';
    
    if ($controller) {
        $parts = explode('@', $controller);
        $controller = $parts[0] ?? $controller;
        $method = $parts[1] ?? 'index';
    }
    
    $output[] = [
        'method' => $route->methods()[0] ?? 'ANY',
        'uri' => $route->uri(),
        'name' => $route->getName() ?? '',
        'controller' => $controller,
        'method_name' => $method,
    ];
}

echo json_encode($output, JSON_PRETTY_PRINT);
