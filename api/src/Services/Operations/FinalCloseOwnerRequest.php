<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Operations;

use Medisa\Api\Auth\Jwt;
use Medisa\Api\Http\Request;
use RuntimeException;

/** Process-local credential: never exported, persisted, or sent over HTTP. */
final class FinalCloseOwnerRequest extends Request
{
    private $body;
    private $operation;
    private $credential;

    public function __construct(string $operation, int $actorId, array $body)
    {
        if (PHP_SAPI !== 'cli' || !in_array($actorId, [10, 11, 110], true)) {
            throw new RuntimeException('FINAL_CLOSE_ACTOR_FORBIDDEN');
        }
        parent::__construct();
        $this->operation = $operation;
        $this->body = $body;
        $this->credential = Jwt::encode(['sub' => $actorId, 'iat' => time(), 'exp' => time() + 60]);
    }

    public function getJsonBody() { return $this->body; }
    public function getRawBody() { return json_encode($this->body, JSON_THROW_ON_ERROR); }
    public function hasInvalidJsonBody(): bool { return false; }
    public function getMethod() { return 'POST'; }
    public function getPath() { return '/internal-final-close/' . $this->operation; }
    public function getHeader($name, $default = null)
    {
        return strtolower($name) === 'authorization' ? 'Bearer ' . $this->credential : $default;
    }
}
