<?php
declare(strict_types=1);

final class IntegrationService
{
    private PDO $db;
    private array $tableExistsCache = [];
    private array $columnExistsCache = [];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function summary(string $serviceCode, int $tenantId, array $providers = []): array
    {
        $serviceCode = $this->normalizeServiceCode($serviceCode);
        $tenantId = max(0, $tenantId);
        $providers = $this->normalizeProviders($providers);

        $checkpoint = $this->checkpointSummary($serviceCode, $tenantId, $providers);
        $queue = $this->errorQueueSummary($serviceCode, $tenantId, $providers);

        return [
            'scope' => [
                'service_code' => $serviceCode,
                'tenant_id' => $tenantId,
                'providers' => $providers,
            ],
            'accounts_total' => $this->countProviderAccounts($serviceCode, $tenantId, $providers, null),
            'accounts_active_total' => $this->countProviderAccounts($serviceCode, $tenantId, $providers, 'ACTIVE'),
            'devices_total' => $this->countDevices($serviceCode, $tenantId, $providers, null),
            'devices_active_total' => $this->countDevices($serviceCode, $tenantId, $providers, true),
            'checkpoints_total' => $checkpoint['total'],
            'checkpoints_error_total' => $checkpoint['error_total'],
            'last_sync_success_at' => $checkpoint['last_success_at'],
            'error_queue_total' => $queue['total'],
            'error_queue_pending_total' => $queue['pending_total'],
            'error_queue_retrying_total' => $queue['retrying_total'],
            'error_queue_failed_total' => $queue['failed_total'],
            'error_queue_resolved_total' => $queue['resolved_total'],
            'last_error_at' => $queue['last_error_at'],
            'provider_breakdown' => $this->providerBreakdown($serviceCode, $tenantId, $providers),
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    }

    public function accountList(
        string $serviceCode,
        int $tenantId,
        array $providers = [],
        string $status = '',
        int $page = 1,
        int $limit = 20,
        int $actorUserPk = 0,
        int $actorRoleLevel = 0
    ): array {
        if (!$this->tableExists('Tb_IntProviderAccount')) {
            return $this->emptyListResponse($page, $limit);
        }

        $serviceCode = $this->normalizeServiceCode($serviceCode);
        $tenantId = max(0, $tenantId);
        $providers = $this->normalizeProviders($providers);
        $status = strtoupper(trim($status));
        $status = preg_match('/^[A-Z_]+$/', $status) === 1 ? $status : '';
        $actorUserPk = max(0, $actorUserPk);
        $actorRoleLevel = max(0, $actorRoleLevel);
        $hasUserPkColumn = $this->columnExists('Tb_IntProviderAccount', 'user_pk');

        [$page, $limit, $offset] = $this->normalizePagination($page, $limit);
        $table = $this->normalizeTableName('Tb_IntProviderAccount');
        $where = ['service_code = :service_code'];
        $params = ['service_code' => $serviceCode];
        if ($tenantId > 0) {
            $where[] = 'tenant_id = :tenant_id';
            $params['tenant_id'] = $tenantId;
        }
        if ($status !== '') {
            $where[] = 'status = :status';
            $params['status'] = $status;
        }
        if ($actorRoleLevel === 0 || $actorRoleLevel > 4) {
            if ($actorUserPk <= 0 || !$hasUserPkColumn) {
                return $this->emptyListResponse($page, $limit);
            }
            $where[] = 'user_pk = :user_pk';
            $params['user_pk'] = $actorUserPk;
        }
        $this->appendProviderFilter($where, $params, 'provider', $providers, 'provider_');
        $whereSql = implode(' AND ', $where);

        $countSql = "SELECT COUNT(1) AS cnt FROM {$table} WHERE {$whereSql}";
        $total = $this->fetchCount($countSql, $params);

        $listColumns = [
            'idx',
            'service_code',
            'tenant_id',
            'provider',
            'account_key',
            'display_name',
            'is_primary',
            'status',
            'created_at',
            'updated_at',
        ];
        if ($hasUserPkColumn) {
            $listColumns[] = 'user_pk';
        }

        $listSql = "SELECT
                        " . implode(",\n                        ", $listColumns) . "
                    FROM {$table}
                    WHERE {$whereSql}
                    ORDER BY is_primary DESC, idx DESC
                    OFFSET {$offset} ROWS FETCH NEXT {$limit} ROWS ONLY";
        $items = $this->fetchAll($listSql, $params);

        return [
            'items' => $items,
            'pagination' => $this->paginationPayload($page, $limit, $total),
        ];
    }

    public function checkpointList(
        string $serviceCode,
        int $tenantId,
        array $providers = [],
        string $status = '',
        int $page = 1,
        int $limit = 20
    ): array {
        if (!$this->tableExists('Tb_IntSyncCheckpoint') || !$this->tableExists('Tb_IntProviderAccount')) {
            return $this->emptyListResponse($page, $limit);
        }

        $serviceCode = $this->normalizeServiceCode($serviceCode);
        $tenantId = max(0, $tenantId);
        $providers = $this->normalizeProviders($providers);
        $status = strtoupper(trim($status));
        $status = preg_match('/^[A-Z_]+$/', $status) === 1 ? $status : '';

        [$page, $limit, $offset] = $this->normalizePagination($page, $limit);
        $checkpointTable = $this->normalizeTableName('Tb_IntSyncCheckpoint');
        $accountTable = $this->normalizeTableName('Tb_IntProviderAccount');

        $where = ['a.service_code = :service_code'];
        $params = ['service_code' => $serviceCode];
        if ($tenantId > 0) {
            $where[] = 'a.tenant_id = :tenant_id';
            $params['tenant_id'] = $tenantId;
        }
        if ($status !== '') {
            $where[] = 'c.status = :status';
            $params['status'] = $status;
        }
        $this->appendProviderFilter($where, $params, 'a.provider', $providers, 'provider_');
        $whereSql = implode(' AND ', $where);

        $countSql = "SELECT COUNT(1) AS cnt
                     FROM {$checkpointTable} c
                     INNER JOIN {$accountTable} a ON a.idx = c.provider_account_idx
                     WHERE {$whereSql}";
        $total = $this->fetchCount($countSql, $params);

        $listSql = "SELECT
                        c.idx,
                        c.provider_account_idx,
                        a.provider,
                        a.account_key,
                        a.display_name,
                        c.sync_scope,
                        c.cursor_value,
                        c.status,
                        c.last_success_at,
                        c.last_error,
                        c.updated_at
                    FROM {$checkpointTable} c
                    INNER JOIN {$accountTable} a ON a.idx = c.provider_account_idx
                    WHERE {$whereSql}
                    ORDER BY c.updated_at DESC, c.idx DESC
                    OFFSET {$offset} ROWS FETCH NEXT {$limit} ROWS ONLY";
        $items = $this->fetchAll($listSql, $params);

        return [
            'items' => $items,
            'pagination' => $this->paginationPayload($page, $limit, $total),
        ];
    }

    public function errorQueueList(
        string $serviceCode,
        int $tenantId,
        array $providers = [],
        string $status = '',
        string $jobType = '',
        int $page = 1,
        int $limit = 20
    ): array {
        if (!$this->tableExists('Tb_IntErrorQueue')) {
            return $this->emptyListResponse($page, $limit);
        }

        $serviceCode = $this->normalizeServiceCode($serviceCode);
        $tenantId = max(0, $tenantId);
        $providers = $this->normalizeProviders($providers);
        $status = strtoupper(trim($status));
        $status = preg_match('/^[A-Z_]+$/', $status) === 1 ? $status : '';
        $jobType = trim($jobType);
        if ($jobType !== '' && preg_match('/^[A-Za-z0-9_.-]+$/', $jobType) !== 1) {
            $jobType = '';
        }

        [$page, $limit, $offset] = $this->normalizePagination($page, $limit);
        $table = $this->normalizeTableName('Tb_IntErrorQueue');
        $where = ['service_code = :service_code'];
        $params = ['service_code' => $serviceCode];
        if ($tenantId > 0) {
            $where[] = 'tenant_id = :tenant_id';
            $params['tenant_id'] = $tenantId;
        }
        if ($status !== '') {
            $where[] = 'status = :status';
            $params['status'] = $status;
        }
        if ($jobType !== '') {
            $where[] = 'job_type = :job_type';
            $params['job_type'] = $jobType;
        }
        $this->appendProviderFilter($where, $params, 'provider', $providers, 'provider_');
        $whereSql = implode(' AND ', $where);

        $countSql = "SELECT COUNT(1) AS cnt FROM {$table} WHERE {$whereSql}";
        $total = $this->fetchCount($countSql, $params);

        $listSql = "SELECT
                        idx,
                        service_code,
                        tenant_id,
                        provider,
                        job_type,
                        payload_json,
                        error_message,
                        retry_count,
                        next_retry_at,
                        status,
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

    private function countProviderAccounts(string $serviceCode, int $tenantId, array $providers, ?string $status): int
    {
        if (!$this->tableExists('Tb_IntProviderAccount')) {
            return 0;
        }

        $table = $this->normalizeTableName('Tb_IntProviderAccount');
        $where = ['service_code = :service_code'];
        $params = ['service_code' => $serviceCode];
        if ($tenantId > 0) {
            $where[] = 'tenant_id = :tenant_id';
            $params['tenant_id'] = $tenantId;
        }
        if ($status !== null) {
            $where[] = 'status = :status';
            $params['status'] = strtoupper(trim($status));
        }
        $this->appendProviderFilter($where, $params, 'provider', $providers, 'provider_');

        $sql = "SELECT COUNT(1) AS cnt FROM {$table} WHERE " . implode(' AND ', $where);
        return $this->fetchCount($sql, $params);
    }

    private function countDevices(string $serviceCode, int $tenantId, array $providers, ?bool $activeOnly): int
    {
        if (!$this->tableExists('Tb_IntDevice')) {
            return 0;
        }

        $table = $this->normalizeTableName('Tb_IntDevice');
        $where = ['service_code = :service_code'];
        $params = ['service_code' => $serviceCode];
        if ($tenantId > 0) {
            $where[] = 'tenant_id = :tenant_id';
            $params['tenant_id'] = $tenantId;
        }
        if ($activeOnly === true) {
            $where[] = 'is_active = 1';
        }
        $this->appendProviderFilter($where, $params, 'provider', $providers, 'provider_');

        $sql = "SELECT COUNT(1) AS cnt FROM {$table} WHERE " . implode(' AND ', $where);
        return $this->fetchCount($sql, $params);
    }

    private function checkpointSummary(string $serviceCode, int $tenantId, array $providers): array
    {
        if (!$this->tableExists('Tb_IntSyncCheckpoint') || !$this->tableExists('Tb_IntProviderAccount')) {
            return [
                'total' => 0,
                'error_total' => 0,
                'last_success_at' => null,
            ];
        }

        $checkpointTable = $this->normalizeTableName('Tb_IntSyncCheckpoint');
        $accountTable = $this->normalizeTableName('Tb_IntProviderAccount');
        $where = ['a.service_code = :service_code'];
        $params = ['service_code' => $serviceCode];
        if ($tenantId > 0) {
            $where[] = 'a.tenant_id = :tenant_id';
            $params['tenant_id'] = $tenantId;
        }
        $this->appendProviderFilter($where, $params, 'a.provider', $providers, 'provider_');
        $whereSql = implode(' AND ', $where);

        $sql = "SELECT
                    COUNT(1) AS total_count,
                    SUM(CASE WHEN c.status IN ('ERROR', 'FAIL', 'FAILED') THEN 1 ELSE 0 END) AS error_count,
                    MAX(c.last_success_at) AS last_success_at
                FROM {$checkpointTable} c
                INNER JOIN {$accountTable} a ON a.idx = c.provider_account_idx
                WHERE {$whereSql}";
        $row = $this->fetchRow($sql, $params);

        return [
            'total' => (int)($row['total_count'] ?? 0),
            'error_total' => (int)($row['error_count'] ?? 0),
            'last_success_at' => is_string($row['last_success_at'] ?? null) ? (string)$row['last_success_at'] : null,
        ];
    }

    private function errorQueueSummary(string $serviceCode, int $tenantId, array $providers): array
    {
        if (!$this->tableExists('Tb_IntErrorQueue')) {
            return [
                'total' => 0,
                'pending_total' => 0,
                'retrying_total' => 0,
                'failed_total' => 0,
                'resolved_total' => 0,
                'last_error_at' => null,
            ];
        }

        $table = $this->normalizeTableName('Tb_IntErrorQueue');
        $where = ['service_code = :service_code'];
        $params = ['service_code' => $serviceCode];
        if ($tenantId > 0) {
            $where[] = 'tenant_id = :tenant_id';
            $params['tenant_id'] = $tenantId;
        }
        $this->appendProviderFilter($where, $params, 'provider', $providers, 'provider_');
        $whereSql = implode(' AND ', $where);

        $sql = "SELECT
                    COUNT(1) AS total_count,
                    SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) AS pending_count,
                    SUM(CASE WHEN status = 'RETRYING' THEN 1 ELSE 0 END) AS retrying_count,
                    SUM(CASE WHEN status = 'FAILED' THEN 1 ELSE 0 END) AS failed_count,
                    SUM(CASE WHEN status = 'RESOLVED' THEN 1 ELSE 0 END) AS resolved_count,
                    MAX(CASE WHEN status IN ('PENDING', 'RETRYING', 'FAILED') THEN created_at END) AS last_error_at
                FROM {$table}
                WHERE {$whereSql}";
        $row = $this->fetchRow($sql, $params);

        return [
            'total' => (int)($row['total_count'] ?? 0),
            'pending_total' => (int)($row['pending_count'] ?? 0),
            'retrying_total' => (int)($row['retrying_count'] ?? 0),
            'failed_total' => (int)($row['failed_count'] ?? 0),
            'resolved_total' => (int)($row['resolved_count'] ?? 0),
            'last_error_at' => is_string($row['last_error_at'] ?? null) ? (string)$row['last_error_at'] : null,
        ];
    }

    private function providerBreakdown(string $serviceCode, int $tenantId, array $providers): array
    {
        if (!$this->tableExists('Tb_IntProviderAccount')) {
            return [];
        }

        $table = $this->normalizeTableName('Tb_IntProviderAccount');
        $where = ['service_code = :service_code'];
        $params = ['service_code' => $serviceCode];
        if ($tenantId > 0) {
            $where[] = 'tenant_id = :tenant_id';
            $params['tenant_id'] = $tenantId;
        }
        $this->appendProviderFilter($where, $params, 'provider', $providers, 'provider_');
        $whereSql = implode(' AND ', $where);

        $sql = "SELECT
                    LOWER(provider) AS provider,
                    COUNT(1) AS total_count,
                    SUM(CASE WHEN status = 'ACTIVE' THEN 1 ELSE 0 END) AS active_count
                FROM {$table}
                WHERE {$whereSql}
                GROUP BY LOWER(provider)
                ORDER BY LOWER(provider) ASC";

        return $this->fetchAll($sql, $params);
    }

    private function normalizeServiceCode(string $serviceCode): string
    {
        $serviceCode = trim($serviceCode);
        if ($serviceCode === '') {
            return 'shvq';
        }
        return strtolower($serviceCode);
    }

    private function normalizeProviders(array $providers): array
    {
        $normalized = [];
        foreach ($providers as $provider) {
            $value = strtolower(trim((string)$provider));
            if ($value === '' || $value === 'all') {
                continue;
            }
            if (preg_match('/^[a-z0-9_.-]+$/', $value) !== 1) {
                continue;
            }
            $normalized[$value] = true;
        }

        return array_keys($normalized);
    }

    private function normalizePagination(int $page, int $limit): array
    {
        $page = max(1, $page);
        $limit = max(1, min($limit, 200));
        $offset = ($page - 1) * $limit;
        return [$page, $limit, $offset];
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

    private function appendProviderFilter(array &$where, array &$params, string $column, array $providers, string $paramPrefix): void
    {
        if ($providers === []) {
            return;
        }

        $placeholders = [];
        foreach (array_values($providers) as $idx => $provider) {
            $key = $paramPrefix . $idx;
            $placeholders[] = ':' . $key;
            $params[$key] = $provider;
        }
        $where[] = 'LOWER(' . $column . ') IN (' . implode(', ', $placeholders) . ')';
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

    private function columnExists(string $tableName, string $columnName): bool
    {
        $cacheKey = $tableName . '.' . $columnName;
        if (array_key_exists($cacheKey, $this->columnExistsCache)) {
            return $this->columnExistsCache[$cacheKey];
        }

        if (!$this->tableExists($tableName)) {
            $this->columnExistsCache[$cacheKey] = false;
            return false;
        }

        try {
            $stmt = $this->db->prepare('SELECT CASE WHEN COL_LENGTH(:obj, :col) IS NULL THEN 0 ELSE 1 END AS exists_yn');
            $stmt->execute([
                'obj' => 'dbo.' . $tableName,
                'col' => $columnName,
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $exists = (int)($row['exists_yn'] ?? 0) === 1;
        } catch (Throwable) {
            $exists = false;
        }

        $this->columnExistsCache[$cacheKey] = $exists;
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
