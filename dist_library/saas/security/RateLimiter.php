<?php
declare(strict_types=1);

final class RateLimiter
{
    private PDO $db;
    private array $cfg;

    public function __construct(PDO $db, array $security)
    {
        $this->db = $db;
        $this->cfg = $security['rate_limit'];
    }

    public function check(string $identifier): array
    {
        try {
            $row = $this->find($identifier);
            if (!$row) {
                return ['allowed' => true, 'retry_after' => 0];
            }

            $now = new DateTimeImmutable('now');
            $lockUntil = $this->parseTime($row['lock_until'] ?? null);
            if ($lockUntil && $lockUntil > $now) {
                return [
                    'allowed' => false,
                    'retry_after' => max(1, $lockUntil->getTimestamp() - $now->getTimestamp()),
                ];
            }

            $firstFail = $this->parseTime($row['first_fail_at'] ?? null);
            if (!$firstFail) {
                return ['allowed' => true, 'retry_after' => 0];
            }

            $windowSeconds = (int)$this->cfg['window_seconds'];
            $windowEnd = $firstFail->modify('+' . $windowSeconds . ' seconds');
            if ($windowEnd < $now) {
                $this->reset($identifier);
                return ['allowed' => true, 'retry_after' => 0];
            }

            $failCount = (int)($row['fail_count'] ?? 0);
            if ($failCount >= (int)$this->cfg['max_attempts']) {
                $lockSeconds = (int)$this->cfg['lock_seconds'];
                $lockUntil = $now->modify('+' . $lockSeconds . ' seconds');
                $this->setLock($identifier, $lockUntil);

                return ['allowed' => false, 'retry_after' => $lockSeconds];
            }

            return ['allowed' => true, 'retry_after' => 0];
        } catch (Throwable) {
            // Degrade mode: if rate-limit table is not ready, do not fail login endpoint with 500.
            return ['allowed' => true, 'retry_after' => 0];
        }
    }

    public function fail(string $identifier): void
    {
        try {
            // MERGE + HOLDLOCK for atomic upsert under concurrent login attempts.
            $sql = sprintf(
                'MERGE %s WITH (HOLDLOCK) AS target
                 USING (SELECT :identifier AS identifier) AS source
                 ON target.identifier = source.identifier
                 WHEN MATCHED THEN
                    UPDATE SET
                        fail_count = CASE
                            WHEN target.first_fail_at IS NULL THEN 1
                            ELSE target.fail_count + 1
                        END,
                        first_fail_at = COALESCE(target.first_fail_at, GETDATE()),
                        last_fail_at = GETDATE(),
                        updated_at = GETDATE()
                 WHEN NOT MATCHED THEN
                    INSERT (identifier, fail_count, first_fail_at, last_fail_at, lock_until, updated_at)
                    VALUES (source.identifier, 1, GETDATE(), GETDATE(), NULL, GETDATE());',
                $this->cfg['table']
            );
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['identifier' => $identifier]);
        } catch (Throwable) {
            // Degrade mode no-op.
        }
    }

    public function success(string $identifier): void
    {
        try {
            $this->reset($identifier);
        } catch (Throwable) {
            // Degrade mode no-op.
        }
    }

    private function reset(string $identifier): void
    {
        $sql = sprintf(
            'UPDATE %s
             SET fail_count = 0,
                 first_fail_at = NULL,
                 last_fail_at = NULL,
                 lock_until = NULL,
                 updated_at = GETDATE()
             WHERE identifier = :identifier',
            $this->cfg['table']
        );
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['identifier' => $identifier]);
    }

    private function setLock(string $identifier, DateTimeImmutable $lockUntil): void
    {
        $sql = sprintf(
            'UPDATE %s
             SET lock_until = :lock_until,
                 updated_at = GETDATE()
             WHERE identifier = :identifier',
            $this->cfg['table']
        );
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'identifier' => $identifier,
            'lock_until' => $lockUntil->format('Y-m-d H:i:s'),
        ]);
    }

    private function find(string $identifier): ?array
    {
        $sql = sprintf('SELECT TOP 1 * FROM %s WHERE identifier = :identifier', $this->cfg['table']);
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['identifier' => $identifier]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function parseTime(mixed $value): ?DateTimeImmutable
    {
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
