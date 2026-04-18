<?php
declare(strict_types=1);

final class CadTokenService
{
    private PDO $db;
    private array $security;

    public function __construct(PDO $db, array $security)
    {
        $this->db = $db;
        $this->security = $security;
    }

    public function issue(int $userPk, string $serviceCode, int $tenantId): array
    {
        $token = 'cad_' . bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);

        $ttl = max(60, (int)($this->security['cad']['ttl_seconds'] ?? 300));
        $expiresAt = (new DateTimeImmutable('now'))->modify('+' . $ttl . ' seconds');

        $sql = sprintf(
            'INSERT INTO %s (token_hash, user_pk, service_code, tenant_id, expires_at, is_used, created_at, updated_at)
             VALUES (:token_hash, :user_pk, :service_code, :tenant_id, :expires_at, 0, GETDATE(), GETDATE())',
            $this->security['cad']['table']
        );

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'token_hash' => $tokenHash,
            'user_pk' => $userPk,
            'service_code' => $serviceCode,
            'tenant_id' => $tenantId,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
        ]);

        return [
            'token' => $token,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            'ttl_seconds' => $ttl,
        ];
    }

    public function verify(string $token): ?array
    {
        $trimmed = trim($token);
        if ($trimmed === '' || mb_strlen($trimmed) > 255) {
            return null;
        }

        $tokenHash = hash('sha256', $trimmed);
        $sql = sprintf(
            'SELECT TOP 1 idx, user_pk, service_code, tenant_id, expires_at, is_used
             FROM %s
             WHERE token_hash = :token_hash',
            $this->security['cad']['table']
        );

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['token_hash' => $tokenHash]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        if ((int)($row['is_used'] ?? 0) === 1) {
            return null;
        }

        $expiresAt = $this->toDateTimeImmutable($row['expires_at'] ?? null);
        if ($expiresAt === null || $expiresAt <= new DateTimeImmutable('now')) {
            return null;
        }

        $consumeSql = sprintf(
            'UPDATE %s
             SET is_used = 1,
                 used_at = GETDATE(),
                 updated_at = GETDATE()
             WHERE idx = :idx
               AND is_used = 0',
            $this->security['cad']['table']
        );

        $consumeStmt = $this->db->prepare($consumeSql);
        $consumeStmt->execute(['idx' => (int)$row['idx']]);

        if ($consumeStmt->rowCount() < 1) {
            return null;
        }

        return [
            'user_pk' => (int)($row['user_pk'] ?? 0),
            'service_code' => (string)($row['service_code'] ?? 'shvq'),
            'tenant_id' => (int)($row['tenant_id'] ?? 0),
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
        ];
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
