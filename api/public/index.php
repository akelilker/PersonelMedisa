<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Medisa\Api\Router;

$router = new Router();
$router->dispatch();
