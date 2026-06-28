<?php

declare(strict_types=1);

$example = require __DIR__ . '/config.example.php';
$config = $example;

$localCandidates = [
    dirname(__DIR__, 2) . '/config.local.php',
    __DIR__ . '/config.local.php',
];

foreach ($localCandidates as $localPath) {
    if (is_file($localPath)) {
        /** @var array<string, mixed> $local */
        $local = require $localPath;
        $config = array_merge($config, $local);
        break;
    }
}

if (!function_exists('medisa_config')) {
    function medisa_config($key = null, $default = null)
    {
        global $config;

        if ($key === null) {
            return $config;
        }

        return array_key_exists($key, $config) ? $config[$key] : $default;
    }
}

if (!function_exists('medisa_config_ready')) {
    function medisa_config_ready()
    {
        $required = ['db_host', 'db_name', 'db_user', 'db_password', 'jwt_secret'];
        foreach ($required as $key) {
            $value = medisa_config($key);
            if (!is_string($value) || trim($value) === '' || strpos($value, 'CHANGE_ME') === 0) {
                return false;
            }
        }

        return strlen((string) medisa_config('jwt_secret')) >= 32;
    }
}
