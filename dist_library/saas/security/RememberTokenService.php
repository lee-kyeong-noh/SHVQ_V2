<?php
declare(strict_types=1);

final class RememberTokenService
{
    private PDO $db;
    private array $security;

    public function __construct(PDO $db, array $security)
    {
        $this->db = $db;
        $this->security = $security;
    }

    public function issue(int $userPk, string $serviceCode, int $tenantId): void
    {
        $selector = bin2hex(random_bytes(9));
        $validator = bin2hex(random_bytes(32));
        $validatorHash = hash('sha256', $validator);
        $expiresAt = $this->newExpiry();

        $sql = sprintf(
            'INSERT INTO %s (selector, validator_hash, user_pk, service_code, tenant_id, expires_at, created_at, updated_at)
             VALUES (:selector, :validator_hash, :user_pk, :service_code, :tenant_id, :expires_at, GETDATE(), GETDATE())',
            $this->security['remember']['table']
        );

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'selector' => $selector,
            'validator_hash' => $validatorHash,
            'user_pk' => $userPk,
            'service_code' => $serviceCode,
            'tenant_id' => $tenantId,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
        ]);

        $this->setCookieToken($selector . ':' . $validator, $expiresAt);
    }

    public function validateAndRotate(?string $cookieValue): ?array
    {
        $parsed = $this->parseCookieValue($cookieValue);
        if ($parsed === null) {
            return null;
        }

        $sql = sprintf(
            'SELECT TOP 1 idx, selector, validator_hash, user_pk, service_code, tenant_id, expires_at
             FROM %s
             WHERE selector = :selector',
            $this->security['remember']['table']
        );

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['selector' => $parsed['selector']]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        $expiresAt = $this->toDateTimeImmutable($row['expires_at'] ?? null);
        if ($expiresAt === null || $expiresAt <= new DateTimeImmutable('now')) {
            $this->revokeSelector((string)$parsed['selector']);
            $this->clearCookie();
            return null;
        }

        $expectedHash = (string)($row['validator_hash'] ?? '');
        $actualHash = hash('sha256', $parsed['validator']);
        if ($expectedHash === '' || !hash_equals($expectedHash, $actualHash)) {
            $this->revokeSelector((string)$parsed['selector']);
            $this->clearCookie();
            return null;
        }

        $newSelector = bin2hex(random_bytes(9));
        $newValidator = bin2hex(random_bytes(32));
        $newValidatorHash = hash('sha256', $newValidator);
        $newExpiry = $this->newExpiry();

        $updateSql = sprintf(
            'UPDATE %s
             SET selector = :new_selector,
                 validator_hash = :new_validator_hash,
                 expires_at = :new_expires_at,
                 updated_at = GETDATE()
             WHERE idx = :idx',
            $this->security['remember']['table']
        );

        $updateStmt = $this->db->prepare($updateSql);
        $updateStmt->execute([
            'new_selector' => $newSelector,
            'new_validator_hash' => $newValidatorHash,
            'new_expires_at' => $newExpiry->format('Y-m-d H:i:s'),
            'idx' => (int)$row['idx'],
        ]);

        $this->setCookieToken($newSelector . ':' . $newValidator, $newExpiry);

        return [
            'user_pk' => (int)($row['user_pk'] ?? 0),
            'service_code' => (string)($row['service_code'] ?? 'shvq'),
            'tenant_id' => (int)($row['tenant_id'] ?? 0),
        ];
    }

    public function revokeFromCookie(?string $cookieValue): void
    {
        $parsed = $this->parseCookieValue($cookieValue);
        if ($parsed !== null) {
            $this->revokeSelector($parsed['selector']);
        }
    }

    public function revokeSelector(string $selector): void
    {
        if ($selector === '') {
            return;
        }

        $sql = sprintf('DELETE FROM %s WHERE selector = :selector', $this->security['remember']['table']);
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['selector' => $selector]);
    }

    public function clearCookie(): void
    {
        if (headers_sent()) {
            return;
        }

        setcookie($this->security['remember']['cookie_name'], '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => (bool)$this->security['session']['secure_cookie'],
            'httponly' => (bool)$this->security['session']['http_only'],
            'samesite' => (string)$this->security['session']['same_site'],
        ]);
    }

    private function parseCookieValue(?string $cookieValue): ?array
    {
        if (!is_string($cookieValue) || trim($cookieValue) === '') {
            return null;
        }

        $parts = explode(':', $cookieValue, 2);
        if (count($parts) !== 2) {
            return null;
        }

        $selector = trim((string)$parts[0]);
        $validator = trim((string)$parts[1]);
        if ($selector === '' || $validator === '') {
            return null;
        }

        return ['selector' => $selector, 'validator' => $validator];
    }

    private function setCookieToken(string $cookieValue, DateTimeImmutable $expiresAt): void
    {
        if (headers_sent()) {
            return;
        }

        setcookie($this->security['remember']['cookie_name'], $cookieValue, [
            'expires' => $expiresAt->getTimestamp(),
            'path' => '/',
            'secure' => (bool)$this->security['session']['secure_cookie'],
            'httponly' => (bool)$this->security['session']['http_only'],
            'samesite' => (string)$this->security['session']['same_site'],
        ]);
    }

    private function newExpiry(): DateTimeImmutable
    {
        $days = (int)$this->security['session']['remember_days'];
        return (new DateTimeImmutable('now'))->modify('+' . max(1, $days) . ' days');
    }

    private function toDateTimeImmutable(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }
}
