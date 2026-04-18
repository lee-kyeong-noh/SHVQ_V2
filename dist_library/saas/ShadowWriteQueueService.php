<?php
declare(strict_types=1);

final class ShadowWriteQueueService
{
    private PDO $db;
    private array $cfg;
    private string $table;
    private string $provider;
    private string $jobType;
    private int $maxRetry;
    private int $retryBackoffBaseMinutes;
    private int $retryBackoffMaxMinutes;

    public function __construct(PDO $db, array $security)
    {
        $this->db = $db;
        $this->cfg = $security['shadow_write'] ?? [];
        $this->table = $this->normalizeTableName((string)($this->cfg['queue_table'] ?? 'Tb_IntErrorQueue'));
        $this->provider = (string)($this->cfg['provider_key'] ?? 'shadow');
        $this->jobType = (string)($this->cfg['job_type'] ?? 'shadow_write');
        $this->maxRetry = max(1, (int)($this->cfg['max_retry'] ?? 10));
        $this->retryBackoffBaseMinutes = max(1, (int)($this->cfg['retry_backoff_base_minutes'] ?? 2));
        $this->retryBackoffMaxMinutes = max(1, (int)($this->cfg['retry_backoff_max_minutes'] ?? 60));
    }

    public function enqueueFailure(array $job): int
    {
        $job['error_message'] = (string)($job['error_message'] ?? 'shadow write failed');
        return $this->enqueueJob($job, 'PENDING', 0);
    }

    public function enqueueJob(array $job, string $status = 'PENDING', int $retryCount = 0): int
    {
        $serviceCode = trim((string)($job['service_code'] ?? 'shvq'));
        if ($serviceCode === '') {
            $serviceCode = 'shvq';
        }
        $tenantId = max(0, (int)($job['tenant_id'] ?? 0));
        $errorMessage = $this->clip((string)($job['error_message'] ?? ''), 1000);
        $payloadJson = $this->encodeJson($job);
        $status = strtoupper(trim($status));
        if (!in_array($status, ['PENDING', 'RETRYING', 'FAILED', 'RESOLVED'], true)) {
            $status = 'PENDING';
        }
        $retryCount = max(0, $retryCount);

        $sql = sprintf(
            "INSERT INTO %s
            (service_code, tenant_id, provider, job_type, payload_json, error_message, retry_count, next_retry_at, status, created_at, updated_at)
             OUTPUT INSERTED.idx
             VALUES (:service_code, :tenant_id, :provider, :job_type, :payload_json, :error_message, :retry_count, GETDATE(), :status, GETDATE(), GETDATE())",
            $this->table
        );
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'service_code' => $serviceCode,
            'tenant_id' => $tenantId,
            'provider' => $this->provider,
            'job_type' => $this->jobType,
            'payload_json' => $payloadJson,
            'error_message' => $errorMessage,
            'retry_count' => $retryCount,
            'status' => $status,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($row['idx'] ?? 0);
    }

    public function stats(array $filters = []): array
    {
        [$whereSql, $params] = $this->buildWhere($filters);
        $sql = sprintf(
            "SELECT
                COUNT(1) AS total_count,
                SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) AS pending_count,
                SUM(CASE WHEN status = 'RETRYING' THEN 1 ELSE 0 END) AS retrying_count,
                SUM(CASE WHEN status = 'FAILED' THEN 1 ELSE 0 END) AS failed_count,
                SUM(CASE WHEN status = 'RESOLVED' THEN 1 ELSE 0 END) AS resolved_count,
                MIN(CASE WHEN status IN ('PENDING', 'RETRYING') THEN created_at END) AS oldest_open_at
             FROM %s
             WHERE %s",
            $this->table,
            $whereSql
        );
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'total_count' => (int)($row['total_count'] ?? 0),
            'pending_count' => (int)($row['pending_count'] ?? 0),
            'retrying_count' => (int)($row['retrying_count'] ?? 0),
            'failed_count' => (int)($row['failed_count'] ?? 0),
            'resolved_count' => (int)($row['resolved_count'] ?? 0),
            'oldest_open_at' => is_string($row['oldest_open_at'] ?? null) ? (string)$row['oldest_open_at'] : null,
        ];
    }

    public function list(array $filters = []): array
    {
        $page = max(1, (int)($filters['page'] ?? 1));
        $limit = max(1, (int)($filters['limit'] ?? 20));
        $limit = min($limit, 200);
        $offset = ($page - 1) * $limit;

        [$whereSql, $params] = $this->buildWhere($filters);

        $countSql = sprintf('SELECT COUNT(1) AS cnt FROM %s WHERE %s', $this->table, $whereSql);
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($params);
        $countRow = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $total = (int)($countRow['cnt'] ?? 0);

        $listSql = sprintf(
            "SELECT idx, service_code, tenant_id, status, retry_count, next_retry_at, error_message, payload_json, created_at, updated_at
             FROM %s
             WHERE %s
             ORDER BY idx DESC
             OFFSET %d ROWS FETCH NEXT %d ROWS ONLY",
            $this->table,
            $whereSql,
            $offset,
            $limit
        );

        $listStmt = $this->db->prepare($listSql);
        $listStmt->execute($params);
        $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);
        $items = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $payload = [];
            if (is_string($row['payload_json'] ?? null) && trim((string)$row['payload_json']) !== '') {
                $decoded = json_decode((string)$row['payload_json'], true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            }

            $items[] = [
                'idx' => (int)($row['idx'] ?? 0),
                'service_code' => (string)($row['service_code'] ?? ''),
                'tenant_id' => (int)($row['tenant_id'] ?? 0),
                'status' => (string)($row['status'] ?? ''),
                'retry_count' => (int)($row['retry_count'] ?? 0),
                'next_retry_at' => (string)($row['next_retry_at'] ?? ''),
                'error_message' => (string)($row['error_message'] ?? ''),
                'payload' => $payload,
                'created_at' => (string)($row['created_at'] ?? ''),
                'updated_at' => (string)($row['updated_at'] ?? ''),
            ];
        }

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => $limit > 0 ? (int)ceil($total / $limit) : 0,
            ],
        ];
    }

    public function requeueByOperator(int $idx, string $note = ''): bool
    {
        if ($idx <= 0) {
            return false;
        }

        $message = $this->clip(trim($note), 1000);
        $sql = sprintf(
            "UPDATE %s
             SET status = 'PENDING',
                 next_retry_at = GETDATE(),
                 updated_at = GETDATE(),
                 error_message = CASE WHEN :msg <> '' THEN :msg ELSE error_message END
             WHERE idx = :idx
               AND provider = :provider
               AND job_type = :job_type",
            $this->table
        );
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'idx' => $idx,
            'provider' => $this->provider,
            'job_type' => $this->jobType,
            'msg' => $message,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function resolveByOperator(int $idx, string $note = ''): bool
    {
        if ($idx <= 0) {
            return false;
        }

        $message = $this->clip(trim($note), 1000);
        $sql = sprintf(
            "UPDATE %s
             SET status = 'RESOLVED',
                 updated_at = GETDATE(),
                 error_message = CASE WHEN :msg <> '' THEN :msg ELSE error_message END
             WHERE idx = :idx
               AND provider = :provider
               AND job_type = :job_type",
            $this->table
        );
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'idx' => $idx,
            'provider' => $this->provider,
            'job_type' => $this->jobType,
            'msg' => $message,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function recoverStaleRetrying(int $staleMinutes = 20): int
    {
        $staleMinutes = max(1, $staleMinutes);
        $sql = sprintf(
            "UPDATE %s
             SET status = 'PENDING',
                 next_retry_at = GETDATE(),
                 updated_at = GETDATE(),
                 error_message = LEFT(
                     CASE
                         WHEN error_message = '' THEN 'auto_recovered_from_stale_retrying'
                         ELSE error_message + ' | auto_recovered_from_stale_retrying'
                     END, 1000
                 )
             WHERE provider = :provider
               AND job_type = :job_type
               AND status = 'RETRYING'
               AND updated_at < DATEADD(MINUTE, (0 - :stale_minutes), GETDATE())",
            $this->table
        );

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'provider' => $this->provider,
            'job_type' => $this->jobType,
            'stale_minutes' => $staleMinutes,
        ]);

        return (int)$stmt->rowCount();
    }

    public function openCountOlderThan(int $olderThanMinutes = 0): int
    {
        $olderThanMinutes = max(0, $olderThanMinutes);
        $sql = sprintf(
            "SELECT COUNT(1) AS cnt
             FROM %s
             WHERE provider = :provider
               AND job_type = :job_type
               AND status IN ('PENDING', 'RETRYING')
               AND created_at <= DATEADD(MINUTE, (0 - :older_than_minutes), GETDATE())",
            $this->table
        );
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'provider' => $this->provider,
            'job_type' => $this->jobType,
            'older_than_minutes' => $olderThanMinutes,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return (int)($row['cnt'] ?? 0);
    }

    public function markRetryFailure(int $idx, string $errorMessage): array
    {
        $sql = sprintf(
            "SELECT TOP 1 idx, retry_count
             FROM %s
             WHERE idx = :idx
               AND provider = :provider
               AND job_type = :job_type",
            $this->table
        );
        $selectStmt = $this->db->prepare($sql);
        $selectStmt->execute([
            'idx' => $idx,
            'provider' => $this->provider,
            'job_type' => $this->jobType,
        ]);
        $row = $selectStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['ok' => false, 'error' => 'QUEUE_ITEM_NOT_FOUND'];
        }

        $nextRetryCount = ((int)($row['retry_count'] ?? 0)) + 1;
        $status = $nextRetryCount >= $this->maxRetry ? 'FAILED' : 'PENDING';
        $backoffMinutes = $status === 'FAILED' ? 0 : $this->calculateBackoffMinutes($nextRetryCount);

        $updateSql = sprintf(
            "UPDATE %s
             SET retry_count = :retry_count,
                 status = :status,
                 next_retry_at = DATEADD(MINUTE, :backoff_minutes, GETDATE()),
                 updated_at = GETDATE(),
                 error_message = :error_message
             WHERE idx = :idx",
            $this->table
        );
        $updateStmt = $this->db->prepare($updateSql);
        $updateStmt->execute([
            'retry_count' => $nextRetryCount,
            'status' => $status,
            'backoff_minutes' => $backoffMinutes,
            'error_message' => $this->clip($errorMessage, 1000),
            'idx' => $idx,
        ]);

        return [
            'ok' => true,
            'status' => $status,
            'retry_count' => $nextRetryCount,
            'backoff_minutes' => $backoffMinutes,
        ];
    }

    private function buildWhere(array $filters): array
    {
        $where = [
            'provider = :provider',
            'job_type = :job_type',
        ];
        $params = [
            'provider' => $this->provider,
            'job_type' => $this->jobType,
        ];

        $serviceCode = trim((string)($filters['service_code'] ?? ''));
        if ($serviceCode !== '') {
            $where[] = 'service_code = :service_code';
            $params['service_code'] = $serviceCode;
        }

        $tenantId = (int)($filters['tenant_id'] ?? 0);
        if ($tenantId > 0) {
            $where[] = 'tenant_id = :tenant_id';
            $params['tenant_id'] = $tenantId;
        }

        $status = strtoupper(trim((string)($filters['status'] ?? '')));
        if ($status !== '') {
            $allowed = ['PENDING', 'RETRYING', 'FAILED', 'RESOLVED'];
            if (in_array($status, $allowed, true)) {
                $where[] = 'status = :status';
                $params['status'] = $status;
            }
        }

        return [implode(' AND ', $where), $params];
    }

    private function calculateBackoffMinutes(int $retryCount): int
    {
        $retryCount = max(1, $retryCount);
        $backoff = (int)($this->retryBackoffBaseMinutes * (2 ** ($retryCount - 1)));
        return min($backoff, $this->retryBackoffMaxMinutes);
    }

    private function encodeJson(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : '{}';
    }

    private function clip(string $value, int $maxLen): string
    {
        $maxLen = max(1, $maxLen);
        return mb_substr($value, 0, $maxLen);
    }

    private function normalizeTableName(string $table): string
    {
        $trimmed = trim($table);
        $default = '[dbo].[Tb_IntErrorQueue]';

        if ($trimmed === '') {
            return $default;
        }

        /* dbo. 접두어가 붙은 경우 (ex. dbo.Tb_IntErrorQueue) → 파싱 후 재인용 */
        $name = preg_replace('/^dbo\./i', '', $trimmed);

        if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
            return $default;
        }

        return '[dbo].[' . $name . ']';
    }
}
