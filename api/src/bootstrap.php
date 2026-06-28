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

require __DIR__ . '/Config/config.php';
