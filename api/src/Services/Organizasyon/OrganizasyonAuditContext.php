<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Organizasyon;

use Medisa\Api\Http\Request;

/**
 * Who performed an organisation write, and which request it came from.
 *
 * Passing this explicitly instead of reaching for a global is what makes the
 * audit obligation visible in every signature: a caller that cannot name an
 * actor cannot reach an audited write path.
 */
final class OrganizasyonAuditContext
{
    /** @var int */
    private $actorUserId;

    /** @var string */
    private $requestHash;

    /** @var string|null */
    private $idempotencyKey;

    public function __construct(int $actorUserId, string $requestHash, ?string $idempotencyKey = null)
    {
        if ($actorUserId <= 0) {
            throw OrganizasyonException::validation('Denetim kaydı için geçerli bir kullanıcı gereklidir.');
        }
        if (preg_match('/^[0-9a-f]{64}$/', $requestHash) !== 1) {
            throw OrganizasyonException::validation('Denetim kaydı için geçerli bir istek özeti gereklidir.');
        }

        $this->actorUserId = $actorUserId;
        $this->requestHash = $requestHash;
        $this->idempotencyKey = $idempotencyKey;
    }

    /**
     * The request fingerprint deliberately covers method, path, actor and raw
     * body: two different actors replaying the same body are two different
     * events, and the same actor retrying is one.
     *
     * @param array<string, mixed> $user
     */
    public static function fromRequest(Request $request, array $user, ?string $idempotencyKey = null): self
    {
        $actorUserId = (int) ($user['id'] ?? 0);
        $hash = hash(
            'sha256',
            $request->getMethod() . '|' . $request->getPath() . '|' . $actorUserId . '|' . (string) $request->getRawBody()
        );

        return new self($actorUserId, $hash, $idempotencyKey);
    }

    public function actorUserId(): int
    {
        return $this->actorUserId;
    }

    public function requestHash(): string
    {
        return $this->requestHash;
    }

    public function idempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }
}
