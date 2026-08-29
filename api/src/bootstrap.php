<?php

declare(strict_types=1);

spl_autoload_register(function ($class) {
    $prefix = 'Medisa\\Api\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

// Installed before config/routing so a failure in either is still reported as a
// contract-shaped 500 with a correlation id instead of an empty response body.
Medisa\Api\Http\ErrorBoundary::install();

require __DIR__ . '/Config/config.php';
