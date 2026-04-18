<?php
declare(strict_types=1);

final class NotificationService
{
    private PDO $db;
    private array $tableExistsCache = [];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function summary(string $serviceCode, int $tenantId): array
    {
        $serviceCode = $this->normalizeServiceCode($serviceCode);
        $tenantId = max(0, $tenantId);

        $rule = $this->ruleSummary($serviceCode, $tenantId);
        $event = $this->eventSummary($serviceCode, $tenantId);
        $queue = $this->queueSummary($serviceCode, $tenantId);
        $delivery = $this->deliverySummary($serviceCode, $tenantId);

        return [
            'scope' => [
                'service_code' => $serviceCode,
                'tenant_id' => $tenantId,
            ],
            'rules_total' => $rule['total'],
            'rules_active_total' => $rule['active_total'],
            'events_24h_total' => $event['total_24h'],
            'latest_event_at' => $event['latest_event_at'],
            'queue_total' => $queue['total'],
            'queue_pending_total' => $queue['pending_total'],
            'queue_retrying_total' => $queue['retrying_total'],
            'queue_failed_total' => $queue['failed_total'],
            'queue_sent_total' => $queue['sent_total'],
            'queue_latest_open_at' => $queue['latest_open_at'],
            'delivery_24h_total' => $delivery['total_24h'],
            'delivery_fail_24h_total' => $delivery['fail_24h_total'],
            'delivery_avg_latency_ms_24h' => $delivery['avg_latency_ms_24h'],
            'latest_delivery_at' => $delivery['latest_delivery_at'],
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    }

    public function queueList(
        string $serviceCode,
        int $tenantId,
        string $status = '',
        string $channel = '',
        int $page = 1,
        int $limit = 20
    ): array {
        if (!$this->tableExists('Tb_NotifyQueue')) {
            return $this->emptyListResponse($page, $limit);
        }

        $serviceCode = $this->normalizeServiceCode($serviceCode);
        $tenantId = max(0, $tenantId);
        $status = strtoupper(trim($status));
        $channel = strtolower(trim($channel));
        $status = preg_match('/^[A-Z_]+$/', $status) === 1 ? $status : '';
        $channel = preg_match('/^[a-z0-9_.-]+$/', $channel) === 1 ? $channel : '';

        [$page, $limit, $offset] = $this->normalizePagination($page, $limit);
        $table = $this->normalizeTableName('Tb_NotifyQueue');
        [$whereSql, $params] = $this->buildScopeWhere($serviceCode, $tenantId);

        if ($status !== '') {
            $whereSql .= ' AND status = :status';
            $params['status'] = $status;
        }
        if ($channel !== '') {
            $whereSql .= ' AND LOWER(channel) = :channel';
            $params['channel'] = $channel;
        }

        $countSql = "SELECT COUNT(1) AS cnt FROM {$table} WHERE {$whereSql}";
        $total = $this->fetchCount($countSql, $params);

        $listSql = "SELECT
                        idx,
                        service_code,
                        tenant_id,
                        event_stream_idx,
                        notify_rule_idx,
                        recipient_key,
                        channel,
                        payload_json,
                        status,
                        retry_count,
                        next_retry_at,
                        sent_at,
                        created_at,
                        updated_at
                    FROM {$table}
                    WHERE {$whereSql}
                    ORDER BY idx DESC
                    OFFSET {$offset} ROWS FETCH NEXT {$limit} ROWS ONLY";
        $rows = $this->fetchAll($listSql, $params);

        $items = [];
        foreach ($rows as $row) {
            $payload = [];
            if (is_string($row['payload_json'] ?? null) && trim((string)$row['payload_json']) !== '') {
                $decoded = json_decode((string)$row['payload_json'], true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            }
            $row['payload'] = $payload;
            unset($row['payload_json']);
            $items[] = $row;
        }

        return [
            'items' => $items,
            'pagination' => $this->paginationPayload($page, $limit, $total),
        ];
    }

    public function deliveryList(
        string $serviceCode,
        int $tenantId,
        string $channel = '',
        string $resultCode = '',
        int $page = 1,
        int $limit = 20
    ): array {
        if (!$this->tableExists('Tb_NotifyDeliveryLog')) {
            return $this->emptyListResponse($page, $limit);
        }

        $serviceCode = $this->normalizeServiceCode($serviceCode);
        $tenantId = max(0, $tenantId);
        $channel = strtolower(trim($channel));
        $resultCode = strtoupper(trim($resultCode));
        $channel = preg_match('/^[a-z0-9_.-]+$/', $channel) === 1 ? $channel : '';
        $resultCode = preg_match('/^[A-Z0-9_.-]+$/', $resultCode) === 1 ? $resultCode : '';

        [$page, $limit, $offset] = $this->normalizePagination($page, $limit);
        $table = $this->normalizeTableName('Tb_NotifyDeliveryLog');
        [$whereSql, $params] = $this->buildScopeWhere($serviceCode, $tenantId);

        if ($channel !== '') {
            $whereSql .= ' AND LOWER(channel) = :channel';
            $params['channel'] = $channel;
        }
        if ($resultCode !== '') {
            $whereSql .= ' AND UPPER(result_code) = :result_code';
            $params['result_code'] = $resultCode;
        }

        $countSql = "SELECT COUNT(1) AS cnt FROM {$table} WHERE {$whereSql}";
        $total = $this->fetchCount($countSql, $params);

        $listSql = "SELECT
                        idx,
                        service_code,
                        tenant_id,
                        notify_queue_idx,
                        channel,
                        recipient_key,
                        result_code,
                        result_message,
                        latency_ms,
                        created_at
                    FROM {$table}
                    WHERE {$whereSql}
                    ORDER BY idx DESC
                    OFFSET {$offset} ROWS FETCH NEXT {$limit} ROWS ONLY";
        $items = $this->fetchAll($listSql, $params);

        return [
            'items' => $items,
            'pagination' => $this->paginationPayload($page, $limit, $total),
        ];
    }

    private function ruleSummary(string $serviceCode, int $tenantId): array
    {
        if (!$this->tableExists('Tb_NotifyRule')) {
            return ['total' => 0, 'active_total' => 0];
        }

        $table = $this->normalizeTableName('Tb_NotifyRule');
        [$whereSql, $params] = $this->buildScopeWhere($serviceCode, $tenantId);
        $sql = "SELECT
                    COUNT(1) AS total_count,
                    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_count
                FROM {$table}
                WHERE {$whereSql}";
        $row = $this->fetchRow($sql, $params);

        return [
            'total' => (int)($row['total_count'] ?? 0),
            'active_total' => (int)($row['active_count'] ?? 0),
        ];
    }

    private function eventSummary(string $serviceCode, int $tenantId): array
    {
        if (!$this->tableExists('Tb_EventStream')) {
            return [
                'total_24h' => 0,
                'latest_event_at' => null,
            ];
        }

        $table = $this->normalizeTableName('Tb_EventStream');
        [$whereSql, $params] = $this->buildScopeWhere($serviceCode, $tenantId);
        $sql = "SELECT
                    SUM(CASE WHEN created_at >= DATEADD(HOUR, -24, GETDATE()) THEN 1 ELSE 0 END) AS total_24h,
                    MAX(created_at) AS latest_event_at
                FROM {$table}
                WHERE {$whereSql}";
        $row = $this->fetchRow($sql, $params);

        return [
            'total_24h' => (int)($row['total_24h'] ?? 0),
            'latest_event_at' => is_string($row['latest_event_at'] ?? null) ? (string)$row['latest_event_at'] : null,
        ];
    }

    private function queueSummary(string $serviceCode, int $tenantId): array
    {
        if (!$this->tableExists('Tb_NotifyQueue')) {
            return [
                'total' => 0,
                'pending_total' => 0,
                'retrying_total' => 0,
                'failed_total' => 0,
                'sent_total' => 0,
                'latest_open_at' => null,
            ];
        }

        $table = $this->normalizeTableName('Tb_NotifyQueue');
        [$whereSql, $params] = $this->buildScopeWhere($serviceCode, $tenantId);
        $sql = "SELECT
                    COUNT(1) AS total_count,
                    SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) AS pending_count,
                    SUM(CASE WHEN status = 'RETRYING' THEN 1 ELSE 0 END) AS retrying_count,
                    SUM(CASE WHEN status = 'FAILED' THEN 1 ELSE 0 END) AS failed_count,
                    SUM(CASE WHEN status IN ('SENT', 'DELIVERED') THEN 1 ELSE 0 END) AS sent_count,
                    MAX(CASE WHEN status IN ('PENDING', 'RETRYING', 'FAILED') THEN created_at END) AS latest_open_at
                FROM {$table}
                WHERE {$whereSql}";
        $row = $this->fetchRow($sql, $params);

        return [
            'total' => (int)($row['total_count'] ?? 0),
            'pending_total' => (int)($row['pending_count'] ?? 0),
            'retrying_total' => (int)($row['retrying_count'] ?? 0),
            'failed_total' => (int)($row['failed_count'] ?? 0),
            'sent_total' => (int)($row['sent_count'] ?? 0),
            'latest_open_at' => is_string($row['latest_open_at'] ?? null) ? (string)$row['latest_open_at'] : null,
        ];
    }

    private function deliverySummary(string $serviceCode, int $tenantId): array
    {
        if (!$this->tableExists('Tb_NotifyDeliveryLog')) {
            return [
                'total_24h' => 0,
                'fail_24h_total' => 0,
                'avg_latency_ms_24h' => 0,
                'latest_delivery_at' => null,
            ];
        }

        $table = $this->normalizeTableName('Tb_NotifyDeliveryLog');
        [$whereSql, $params] = $this->buildScopeWhere($serviceCode, $tenantId);
        $sql = "SELECT
                    SUM(CASE WHEN created_at >= DATEADD(HOUR, -24, GETDATE()) THEN 1 ELSE 0 END) AS total_24h,
                    SUM(CASE
                            WHEN created_at >= DATEADD(HOUR, -24, GETDATE())
                                 AND UPPER(result_code) NOT IN ('OK', 'SUCCESS', 'SENT')
                            THEN 1 ELSE 0 END
                    ) AS fail_24h_count,
                    AVG(CASE WHEN created_at >= DATEADD(HOUR, -24, GETDATE()) THEN CONVERT(FLOAT, latency_ms) END) AS avg_latency_24h,
                    MAX(created_at) AS latest_delivery_at
                FROM {$table}
                WHERE {$whereSql}";
        $row = $this->fetchRow($sql, $params);
        $avgLatency = (float)($row['avg_latency_24h'] ?? 0);

        return [
            'total_24h' => (int)($row['total_24h'] ?? 0),
            'fail_24h_total' => (int)($row['fail_24h_count'] ?? 0),
            'avg_latency_ms_24h' => (int)round($avgLatency),
            'latest_delivery_at' => is_string($row['latest_delivery_at'] ?? null) ? (string)$row['latest_delivery_at'] : null,
        ];
    }

    private function normalizeServiceCode(string $serviceCode): string
    {
        $serviceCode = strtolower(trim($serviceCode));
        return $serviceCode !== '' ? $serviceCode : 'shvq';
    }

    private function normalizePagination(int $page, int $limit): array
    {
        $page = max(1, $page);
        $limit = max(1, min($limit, 200));
        $offset = ($page - 1) * $limit;
        return [$page, $limit, $offset];
    }

    private function buildScopeWhere(string $serviceCode, int $tenantId): array
    {
        $where = ['service_code = :service_code'];
        $params = ['service_code' => $serviceCode];
        /* tenantId=0은 허용하지 않는다 — 전체 테넌트 데이터 노출 방지 */
        if ($tenantId <= 0) {
            throw new \InvalidArgumentException('tenant_id must be greater than 0');
        }
        $where[] = 'tenant_id = :tenant_id';
        $params['tenant_id'] = $tenantId;
        return [implode(' AND ', $where), $params];
    }

    private function paginationPayload(int $page, int $limit, int $total): array
    {
        return [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => $limit > 0 ? (int)ceil($total / $limit) : 0,
        ];
    }

    private function emptyListResponse(int $page, int $limit): array
    {
        [$page, $limit] = $this->normalizePagination($page, $limit);
        return [
            'items' => [],
            'pagination' => $this->paginationPayload($page, $limit, 0),
        ];
    }

    private function fetchCount(string $sql, array $params = []): int
    {
        $row = $this->fetchRow($sql, $params);
        return (int)($row['cnt'] ?? 0);
    }

    private function fetchRow(string $sql, array $params = []): array
    {
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : [];
        } catch (Throwable) {
            return [];
        }
    }

    private function fetchAll(string $sql, array $params = []): array
    {
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return is_array($rows) ? $rows : [];
        } catch (Throwable) {
            return [];
        }
    }

    private function tableExists(string $tableName): bool
    {
        if (array_key_exists($tableName, $this->tableExistsCache)) {
            return $this->tableExistsCache[$tableName];
        }

        try {
            $stmt = $this->db->prepare("SELECT CASE WHEN OBJECT_ID(:table_name, 'U') IS NULL THEN 0 ELSE 1 END AS exists_yn");
            $stmt->execute(['table_name' => 'dbo.' . $tableName]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $exists = (int)($row['exists_yn'] ?? 0) === 1;
        } catch (Throwable) {
            $exists = false;
        }

        $this->tableExistsCache[$tableName] = $exists;
        return $exists;
    }

    private function normalizeTableName(string $table): string
    {
        $table = trim($table);
        if ($table === '' || preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            throw new InvalidArgumentException('invalid table name');
        }
        return '[dbo].[' . $table . ']';
    }
}
