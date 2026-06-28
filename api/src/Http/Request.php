<?php

declare(strict_types=1);

namespace Medisa\Api\Http;

class Request
{
    /** @var string */
    private $method;

    /** @var string */
    private $path;

    /** @var array<string, string> */
    private $headers;

    /** @var array<string, mixed>|null */
    private $jsonBody;

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->path = self::normalizePath($_SERVER['REQUEST_URI'] ?? '/');
        $this->headers = self::readHeaders();
        $this->jsonBody = null;
    }

    public function getMethod()
    {
        return $this->method;
    }

    public function getPath()
    {
        return $this->path;
    }

    /** @return array<string, string> */
    public function getHeaders()
    {
        return $this->headers;
    }

    public function getHeader($name, $default = null)
    {
        $key = strtolower($name);
        return array_key_exists($key, $this->headers) ? $this->headers[$key] : $default;
    }

    public function getQuery($name, $default = null)
    {
        if (!isset($_GET[$name])) {
            return $default;
        }

        return $_GET[$name];
    }

    /** @return array<string, mixed> */
    public function getJsonBody()
    {
        if ($this->jsonBody !== null) {
            return $this->jsonBody;
        }

        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            $this->jsonBody = [];
            return $this->jsonBody;
        }

        $decoded = json_decode($raw, true);
        $this->jsonBody = is_array($decoded) ? $decoded : [];

        return $this->jsonBody;
    }

    private static function normalizePath($uri)
    {
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = '/';
        }

        $prefixes = ['/personelmedisa/api', '/api'];
        foreach ($prefixes as $prefix) {
            if (strpos($path, $prefix) === 0) {
                $path = substr($path, strlen($prefix));
                break;
            }
        }

        if ($path === '' || $path === false) {
            $path = '/';
        }

        if ($path[0] !== '/') {
            $path = '/' . $path;
        }

        $path = rtrim($path, '/');
        return $path === '' ? '/' : $path;
    }

    /** @return array<string, string> */
    private static function readHeaders()
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') !== 0 || !is_string($value)) {
                continue;
            }
            $name = strtolower(str_replace('_', '-', substr($key, 5)));
            $headers[$name] = $value;
        }

        if (isset($_SERVER['CONTENT_TYPE']) && is_string($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }

        if (isset($_SERVER['CONTENT_LENGTH']) && is_string($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = $_SERVER['CONTENT_LENGTH'];
        }

        return $headers;
    }
}
